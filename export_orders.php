<?php
require_once __DIR__ . '/init.php';

$modified_after = (new DateTime('-30 minutes'))->format(DateTime::ATOM);
//$modified_after = (new DateTime('-3 hours'))->format(DateTime::ATOM);

$locale = 'nl_NL';

$per_page = 10;
$page = 1;
$order_id = (!empty($_GET['order_id'])) ? $_GET['order_id'] : '';

do {
    $params = [
        'after' => $modified_after,
        'per_page' => $per_page,
        'page' => $page,
		'status' => 'processing,completed'
    ];

    try {
		if($order_id){
			$response = [$woocommerce->get('orders/'.$order_id)];
		} else {
        	$response = $woocommerce->get('orders', $params);
		}
        $orders = (object) $response;
        $countable_orders = (array) $response;

        if ($orders) {
            foreach ($orders as $order) {
				if (empty($order->date_paid)) {
					log_message('INFO', "Skipping order #{$order->number} — not yet paid");
					continue;
				}

                date_default_timezone_set('Europe/Amsterdam');
                $dt = new DateTime();
                $offset = $dt->format('P'); 
                $microseconds = $dt->format('u');
                $microseconds = str_pad($microseconds, 7, "0");
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
                $orderNode->addChild('OrderDate', date('Y-m-d H:i:s', strtotime($order->date_created)));
                $orderNode->addChild('TotalOrderAmount', number_format($order->total, 4, '.', ''));
                $orderNode->addChild('PaymentMethod', $order->payment_method_title ?? '');
                $orderNode->addChild('PaymentCosts', number_format($order->fee_total ?? 0, 4, '.', ''));
                $orderNode->addChild('Paid', number_format($order->total, 4, '.', ''));
                $orderNode->addChild('ShippingMethod', $order->shipping_lines[0]->method_title ?? '');
                $orderNode->addChild('ShippingCosts', number_format(($order->shipping_total + $order->shipping_tax) ?? 0, 4, '.', ''));
                $orderNode->addChild('InvoiceDiscountAmount', number_format($order->discount_total ?? 0, 4, '.', ''));

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

                        if ($ecommerce_guid) {
                            $productNode->addChild('EcommerceProductGuid', $ecommerce_guid);
                        }
                        if ($product_id) {
                            $productNode->addChild('ProductId', $product_id);
                        }

                    } catch (Exception $e) {
                        log_message(['ERROR', 'PRODUCT FETCH'], "Failed for product ID {$item->product_id}: " . $e->getMessage());
                    }

                    // Continue with other fields
                    $productNode->addChild('ProductSalesPriceEx', number_format($item->total / $item->quantity, 4, '.', ''));
                    $productNode->addChild('ProductSalesPriceInc', number_format(($item->total + $item->total_tax) / $item->quantity, 4, '.', ''));
                    $productNode->addChild('Quantity', (int)$item->quantity);
                    $productNode->addChild('OfficeId', 1);
                    $productNode->addChild('Description', htmlspecialchars($item->name));
                }

                // Convert XML to UTF-16 with BOM
                $xml_string = $xml->asXML();
                $xml_string = str_replace('encoding="UTF-8"', 'encoding="UTF-16"', $xml_string);
                $utf16le_string = mb_convert_encoding($xml_string, 'UTF-16LE', 'UTF-8');
                $bom = "\xFF\xFE";

                //file_put_contents(__DIR__ . "/debug-xml/order-{$order_number}.xml", $bom . $utf16le_string);

                $file_path = __DIR__ . "/export/orders/order-{$order_number}.xml";
                file_put_contents($file_path, $bom . $utf16le_string);

                log_message('INFO', "Saved order #{$order_number} to {$file_path}");
            }
        }

    } catch (Exception $e) {
        log_message(['ERROR', 'GET'] , "API Request Failed on page {$page}: " . $e->getMessage());
        break;
    }

    $page++;

} while (count($countable_orders) === $per_page);
