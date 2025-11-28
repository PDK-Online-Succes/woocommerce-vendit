<?php
require_once __DIR__ . '/init.php';

$stock_timestamp = time();
$stock_retry_file = __DIR__ . "/tmp/stock/stock_{$stock_timestamp}.xml";

$delete = true;

$files = recursive_scan_dir('tmp/stock');
$options = getopt("a", ["action:"]);
if (isset($options['a']) && $options['a'] == 'manual' || isset($options['action']) && $options['action'] == 'manual') {
	$files = recursive_scan_dir('manual/stock');
	evalBool($_ENV['DEBUG']) && $delete = false;
}

$product_guid = [];
$stock_data = [];
$i = 0;

foreach ($files as $file) {
	if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . $file)) {
		log_message('DEBUG', "StockFile: " . __DIR__ . DIRECTORY_SEPARATOR . $file);
		$unsortedXml = simplexml_load_file(__DIR__ . DIRECTORY_SEPARATOR . $file);

		// Maak array van Product XML-strings
		$products = [];
		foreach ($unsortedXml->Products->Product as $product) {
			$products[] = $product->asXML(); // behoudt volledige structuur
		}

		// Sorteer op EcommerceProductGuid binnen de XML-strings
		usort($products, function ($a, $b) {
			$aXml = new SimpleXMLElement($a);
			$bXml = new SimpleXMLElement($b);
			return strcmp((string) $aXml->EcommerceProductGuid, (string) $bXml->EcommerceProductGuid);
		});

		// Bouw nieuwe XML, behoudt alles binnen <Product> intact
		$xmlString = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<Products>\n";
		foreach ($products as $productXml) {
			$xmlString .= $productXml . "\n";
		}
		$xmlString .= "</Products>";

		// Laad nieuwe XML in SimpleXMLElement
		$xml = new SimpleXMLElement($xmlString);
		//log_message("DEBUG", json_encode($xml,JSON_PRETTY_PRINT));

		if ($xml->Product) {
			foreach ($xml->Product as $stock) {
				if ($i < 100) {
					if (!in_array($stock->EcommerceProductGuid->__toString(), $product_guid)) {
						$product_guid[] = $stock->EcommerceProductGuid->__toString();
					}
					$stock_data[] = $stock;
					$i++;
				} else {
					// 🔁 batch verwerken zodra 100 bereikt
					process_stock_batch($woocommerce, $product_guid, $stock_data, $stock_retry_file);

					// reset voor volgende batch
					$i = 0;
					$product_guid = [$stock->EcommerceProductGuid->__toString()];
					$stock_data = [$stock];
					$i++;
				}
			}

			// ✅ Verwerk resterende (kleine batch)
			if (!empty($stock_data)) {
				process_stock_batch($woocommerce, $product_guid, $stock_data, $stock_retry_file);
			}
		}

		if ($delete)
			unlink(__DIR__ . DIRECTORY_SEPARATOR . $file);
	}
}

function update_product($woocommerce, $xml, $products)
{

	// Bouw een lookup-array van stock_data (ProductId => stock object)
	$stock_lookup = [];
	foreach ($xml as $stock_item) {
		$pid = (string) $stock_item->ProductId;
		$stock_lookup[$pid] = $stock_item;
	}

	$simple_updates = [];

	foreach ($products as $product) {
		if ($product->type == 'variable') {
			$variations = $woocommerce->get("products/{$product->id}/variations");

			$variation_updates = [];

			foreach ($variations as $variation) {
				// Haal ProductId op uit meta_data
				$variation_product_id = null;
				foreach ($variation->meta_data as $meta) {
					if ($meta->key === 'ProductId') {
						$variation_product_id = $meta->value;
						break;
					}
				}

				// Match met stock data
				if ($variation_product_id && isset($stock_lookup[$variation_product_id])) {
					$stock = $stock_lookup[$variation_product_id];
					$total_qty = get_total_quantity_with_suppliers($stock);
					// Bouw update array
					$data = [
						'id' => $variation->id,
						'manage_stock' => true,
						'stock_quantity' => intval($total_qty),
						'stock_status' => ($total_qty > 0) ? 'instock' : 'outofstock',
						'backorders' => (string) $stock->AvailabilityStatus == 'Leverbaar' ? 'notify' : 'no'
					];

					$variation_updates[] = $data;
				}
			}

			// Batch update variaties
			if (!empty($variation_updates)) {
				try {
					$response = $woocommerce->post("products/{$product->id}/variations/batch", [
						'update' => $variation_updates
					]);
					evalBool($_ENV['DEBUG']) && log_message('DEBUG', "Variations updated for product {$product->id}: " . json_encode($variation_updates));
					evalBool($_ENV['DEBUG']) && log_message('DEBUG', "Response: " . json_encode($response));
					// evalBool($_ENV['DEBUG']) && error_log(date('Y-m-d H:i:s') . "[DEBUG] Variations updated for product {$product->id}: " . json_encode($variation_updates) . "\r\n", 3, IMPORT_ERROR_LOG);
					// evalBool($_ENV['DEBUG']) && error_log(date('Y-m-d H:i:s') . "[DEBUG] Response: " . json_encode($response) . "\r\n", 3, IMPORT_ERROR_LOG);
				} catch (Exception $e) {
					log_message('ERROR', "Failed to update variations for product {$product->id}: " . $e->getMessage());
					//error_log(date('Y-m-d H:i:s') . "[ERROR] Failed to update variations for product {$product->id}: " . $e->getMessage() . "\r\n", 3, IMPORT_ERROR_LOG);
				}
			}
			// Change manage_stock to false for parent product.
			$simple_updates[] = [
				'id' => $product->id,
				'manage_stock' => false,
			];

		} else {
			// Simpel product, zoek matching stock
			foreach ($xml as $stock_item) {
				if ((string) $stock_item->EcommerceProductGuid === $product->EcommerceProductGuid) {
					$total_qty = get_total_quantity_with_suppliers($stock_item);
					$simple_updates[] = [
						'id' => $product->id,
						'manage_stock' => true,
						'stock_quantity' => intval($total_qty),
						'stock_status' => ($total_qty > 0) ? 'instock' : 'outofstock',
						'backorders' => (string) $stock_item->AvailabilityStatus == 'Leverbaar' ? 'notify' : 'no'
					];
				}
			}
		}
	}
	// Batch update simpele producten
	if (!empty($simple_updates)) {
		try {
			$response = $woocommerce->post("products/batch", [
				'update' => $simple_updates
			]);
			evalBool($_ENV['DEBUG']) && log_message('DEBUG', "Simple products updated: " . json_encode($simple_updates));
			evalBool($_ENV['DEBUG']) && log_message('DEBUG', "Response: " . json_encode($response));
			//evalBool($_ENV['DEBUG']) && error_log(date('Y-m-d H:i:s') . "[DEBUG] Simple products updated: " . json_encode($simple_updates) . "\r\n", 3, IMPORT_ERROR_LOG);
			//evalBool($_ENV['DEBUG']) && error_log(date('Y-m-d H:i:s') . "[DEBUG] Response: " . json_encode($response) . "\r\n", 3, IMPORT_ERROR_LOG);
		} catch (Exception $e) {
			log_message('ERROR', "Failed to update simple products: " . $e->getMessage());
			// error_log(date('Y-m-d H:i:s') . "[ERROR] Failed to update simple products: " . $e->getMessage() . "\r\n", 3, IMPORT_ERROR_LOG);
		}
	}
}

/**
 * Helperfunctie om een batch producten te verwerken
 */
function process_stock_batch($woocommerce, $product_guid, $stock_data, $stock_retry_file)
{
	$products = get_products_by_guids_batch($woocommerce, $product_guid);
	//log_message('DEBUG', "wcproducts: " . json_encode($products));

	$found_guids = array_map(fn($p) => $p->EcommerceProductGuid, $products);
	$existing = [];
	$missing = [];

	foreach ($stock_data as $entry) {
		if (in_array((string) $entry->EcommerceProductGuid, $found_guids)) {
			$existing[] = $entry;
		} else {
			$missing[] = $entry;
		}
	}

	if (!empty($existing)) {
		update_product($woocommerce, $existing, $products);
	}

	if (!empty($missing)) {
		save_missing_stock_products($missing, $stock_retry_file);
	}
}

function get_total_quantity_with_suppliers($stock_item)
{
	$quantity = intval($stock_item->Quantity);
	$supplier_total = 0;

	if (isset($stock_item->Suppliers) && isset($stock_item->Suppliers->Supplier)) {
		foreach ($stock_item->Suppliers->Supplier as $supplier) {
			$supplier_total += intval($supplier->Stock);
		}
	}
	//log_message("DEBUG", json_encode($stock_item,JSON_PRETTY_PRINT));
	//log_message("DEBUG", "GUID: ".$stock_item->EcommerceProductGuid." Stock: ".$quantity." Supplier: ".$supplier_total );
	return $quantity + $supplier_total;
}
