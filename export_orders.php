<pre><?php
require_once  __DIR__ . '/init.php';

$modified_after = (new DateTime('-30 minutes'))->format(DateTime::ATOM);
$per_page = 10;
$page = 1;

do {
    $params = [
        'modified_after' => $modified_after,
        'per_page' => $per_page,
        'page' => $page
    ];

    try {
        $response = $woocommerce->get('orders', $params);
        $orders = (object)$response;

        if ($orders) {
            foreach ($orders as $order) {
                $order_number = $order->number;
                $file_path = __DIR__ . "/export/orders/order-{$order_number}.xml";

                // Create root node
                $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><OrderImport xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema"/>');
                $importInfo = $xml->addChild('ImportInfo');
                $importInfo->addChild('ExportDateTime', date('Y-m-d H:i:s'));

                $ordersNode = $xml->addChild('Orders');
                $orderNode = $ordersNode->addChild('Order');

                // Required fields
                $orderNode->addChild('Ordernummer', $order->number);
                $orderNode->addChild('StoreNumber', 'DEALER123'); // <-- Replace this with your logic
                $orderNode->addChild('OrderType', 'Order'); // or 'Reservering'?
                $orderNode->addChild('OrderDate', date('Y-m-d H:i:s', strtotime($order->date_created)));
                $orderNode->addChild('TotalOrderAmount', (float)$order->total);
                $orderNode->addChild('PaymentMethod', $order->payment_method_title ?? '');
                $orderNode->addChild('PaymentCosts', (float)$order->fee_total ?? 0);
                $orderNode->addChild('Paid', (float)$order->total);
                $orderNode->addChild('ShippingMethod', $order->shipping_lines[0]->method_title ?? '');
                $orderNode->addChild('ShippingCosts', (float)($order->shipping_total ?? 0));
                $orderNode->addChild('InvoiceDiscountAmount', (float)$order->discount_total ?? 0);
                $orderNode->addChild('OrderStatusId', $order->status); // optional mapping needed?

                // Billing (Invoice)
                $billing = $order->billing;
                $orderNode->addChild('InvoiceFirstName', $billing->first_name);
                $orderNode->addChild('InvoiceLastName', $billing->last_name);
                $orderNode->addChild('InvoiceEmailAddress', $billing->email);
                $orderNode->addChild('InvoicePhone', $billing->phone ?? '');
                $orderNode->addChild('InvoiceAddress', $billing->address_1);
                $orderNode->addChild('InvoiceZipcode', $billing->postcode);
                $orderNode->addChild('InvoiceCity', $billing->city);
                $orderNode->addChild('InvoiceCountry', $billing->country);
                $orderNode->addChild('InvoiceCountryCode', $billing->country);

                // Shipping (Delivery)
                $shipping = $order->shipping;
                $orderNode->addChild('DeliveryFirstName', $shipping->first_name ?? '');
                $orderNode->addChild('DeliveryLastName', $shipping->last_name ?? '');
                $orderNode->addChild('DeliveryAddress', $shipping->address_1 ?? '');
                $orderNode->addChild('DeliveryZipcode', $shipping->postcode ?? '');
                $orderNode->addChild('DeliveryCity', $shipping->city ?? '');
                $orderNode->addChild('DeliveryCountry', $shipping->country ?? '');
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
						error_log("[ERROR][PRODUCT FETCH] Failed for product ID {$item->product_id}: " . $e->getMessage());
					}

					// Continue with other fields
					$productNode->addChild('ProductSalesPriceEx', (float)$item->total);
					$productNode->addChild('ProductSalesPriceInc', (float)$item->total + $item->total_tax);
					$productNode->addChild('Quantity', $item->quantity);
					$productNode->addChild('Description', htmlspecialchars($item->name));
				}

                // Convert XML to string
                $xml_string = $xml->asXML();
                $xml_string = str_replace('encoding="UTF-8"', 'encoding="UTF-16"', $xml_string);
                $utf16le_string = mb_convert_encoding($xml_string, 'UTF-16LE', 'UTF-8');
                $bom = "\xFF\xFE";
                file_put_contents($file_path, $bom . $utf16le_string);

                echo "Saved order #$order_number to $file_path" . PHP_EOL;
            }
        }

    } catch (Exception $e) {
        error_log("[ERROR][GET] API Request Failed on page $page: " . $e->getMessage());
        break;
    }

    $page++;
} while (count($orders) === $per_page);


?>
</pre>