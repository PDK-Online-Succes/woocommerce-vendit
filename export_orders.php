<pre><?php
require_once __DIR__ . '/init.php';

$modified_after = (new DateTime('-30 minutes'))->format(DateTime::ATOM);
$modified_after = (new DateTime('-3 hours'))->format(DateTime::ATOM);

$locale = 'nl_NL'; // taal waarin je de naam wilt terugkrijgen

$per_page = 10;
$page = 1;



echo $formatted;

do {
	$params = [
		'after' => $modified_after,
		'per_page' => $per_page,
		'page' => $page
	];

	try {
		$response = $woocommerce->get('orders', $params);
		$orders = (object) $response;
		$countable_orders = (array) $response;

		if ($orders) {
			//print_r($orders);
			//print_r(__DIR__);
			foreach ($orders as $order) {
				// Stel de juiste tijdzone in
				date_default_timezone_set('Europe/Amsterdam');
				// Maak een DateTime-object met microseconden
				$dt = new DateTime();
				// Haal de offset op in het gewenste formaat (met dubbele punt)
				$offset = $dt->format('P'); // Geeft bijv. +01:00
				// Haal microseconden op
				$microseconds = $dt->format('u'); // Bijv. 171128
				// Vul aan tot 7 cijfers, zoals in je voorbeeld (micro + extra nul)
				$microseconds = str_pad($microseconds, 7, "0");
				// Bouw de volledige datumstring
				$formatted = $dt->format("Y-m-d\TH:i:s") . $offset;

				$order_number = $order->number;

				// Create root node
				$xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><OrderImport xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema"/>');
				$importInfo = $xml->addChild('ImportInfo');
				$importInfo->addChild('ExportDateTime', $formatted);

				$ordersNode = $xml->addChild('Orders');
				$orderNode = $ordersNode->addChild('Order');

				// Required fields
				$orderNode->addChild('OrderNumber', $order->number);
				//$orderNode->addChild('StoreNumber', '1'); // <-- Replace this with your logic
				//$orderNode->addChild('OrderType', 'Order'); // or 'Reservering'?
				$orderNode->addChild('OrderDate', date('Y-m-d H:i:s', strtotime($order->date_created)));
				$orderNode->addChild('TotalOrderAmount', number_format($order->total, 4, '.', ''));
				$orderNode->addChild('PaymentMethod', $order->payment_method_title ?? '');
				$orderNode->addChild('PaymentCosts', number_format($order->fee_total ?? 0, 4, '.', ''));
				$orderNode->addChild('Paid', number_format($order->total, 4, '.', ''));
				$orderNode->addChild('ShippingMethod', $order->shipping_lines[0]->method_title ?? '');
				$orderNode->addChild('ShippingCosts', number_format($order->shipping_total ?? 0, 4, '.', ''));
				$orderNode->addChild('InvoiceDiscountAmount', number_format($order->discount_total ?? 0, 4, '.', ''));
				//$orderNode->addChild('OrderStatusId', $order->status); // optional mapping needed?

				// Billing (Invoice)
				$billing = $order->billing;
				$orderNode->addChild('FirstName', $billing->first_name);
				$orderNode->addChild('LastName', $billing->last_name);
				$orderNode->addChild('EmailAddress', $billing->email);
				$orderNode->addChild('Phone', $billing->phone ?? '');
				$orderNode->addChild('InvoiceAddress', $billing->address_1);
				$orderNode->addChild('InvoiceZipcode', $billing->postcode);
				$orderNode->addChild('InvoiceCity', $billing->city);
				$orderNode->addChild('InvoiceCountry', \Locale::getDisplayRegion('-' . $billing->country, $locale));
				$orderNode->addChild('InvoiceCountryCode', $billing->country);

				// Shipping (Delivery)
				$shipping = $order->shipping;
				$orderNode->addChild('DeliveryFirstName', $shipping->first_name ?? '');
				$orderNode->addChild('DeliveryLastName', $shipping->last_name ?? '');
				$orderNode->addChild('DeliveryAddress', $shipping->address_1 ?? '');
				$orderNode->addChild('DeliveryZipcode', $shipping->postcode ?? '');
				$orderNode->addChild('DeliveryCity', $shipping->city ?? '');
				$orderNode->addChild('DeliveryCountry', \Locale::getDisplayRegion('-' . $shipping->country, $locale) ?? '');
				$orderNode->addChild('DeliveryCountryCode', $shipping->country ?? '');

				// Products
				$productsNode = $orderNode->addChild('Products');

				foreach ($order->line_items as $item) {
					$productNode = $productsNode->addChild('Product');

					// Fetch product or variation data
					try {
						$product = $woocommerce->get("products/{$item->product_id}");
						$ecommerce_guid = $product->EcommerceProductGuid ?? '';

						if (!empty($item->variation_id)) {
							// Variable product
							$variation = $woocommerce->get("products/{$item->product_id}/variations/{$item->variation_id}");
							$product_id = $variation->ProductId ?? '';
						} else {
							// Simple product
							$product_id = $product->ProductId ?? '';
						}

						// Add fields
						if ($ecommerce_guid) {
							$productNode->addChild('EcommerceProductGuid', $ecommerce_guid);
						}

						if ($product_id) {
							$productNode->addChild('ProductId', $product_id);
						}

					} catch (Exception $e) {
						error_log(date('Y-m-d H:i:s') . "[ERROR][PRODUCT FETCH] Failed for product ID {$item->product_id}: " . $e->getMessage() . "\r\n", 3, IMPORT_ERROR_LOG);
					}

					// Continue with other fields
					$productNode->addChild('ProductSalesPriceEx', number_format($item->total / $item->quantity, 4, '.', ''));
					$productNode->addChild('ProductSalesPriceInc', number_format(($item->total + $item->total_tax) / $item->quantity, 4, '.', ''));
					//$productNode->addChild('PrivateCopyLevy', number_format(0,4,'.','') );
					$productNode->addChild('Quantity', (int) $item->quantity);
					//$productNode->addChild('Remarks', '');
					$productNode->addChild('OfficeId', 1);
					$productNode->addChild('Description', htmlspecialchars($item->name));
				}

				// Convert XML to string
				$xml_string = $xml->asXML();
				$xml_string = str_replace('encoding="UTF-8"', 'encoding="UTF-16"', $xml_string);
				$utf16le_string = mb_convert_encoding($xml_string, 'UTF-16LE', 'UTF-8');
				$bom = "\xFF\xFE";

				//file_put_contents(__DIR__ . "/debug-xml/order-{$order_number}_before.xml", $xml->asXML());
				//file_put_contents(__DIR__ . "/debug-xml/order-{$order_number}_after.xml", $bom . $utf16le_string);
				file_put_contents(__DIR__ . "/debug-xml/order-{$order_number}.xml", $bom . $utf16le_string);

				$file_path = __DIR__ . "/export/orders/order-{$order_number}.xml";
				file_put_contents($file_path, $bom . $utf16le_string);

				echo "Saved order #$order_number to $file_path" . PHP_EOL;
			}
		}

	} catch (Exception $e) {
		error_log(date('Y-m-d H:i:s') . "[ERROR][GET] API Request Failed on page $page: " . $e->getMessage() . "\r\n", 3, IMPORT_ERROR_LOG);
		break;
	}

	$page++;
} while (count($countable_orders) === $per_page);


?>
</pre>