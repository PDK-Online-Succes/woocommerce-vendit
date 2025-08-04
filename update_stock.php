<?php
require_once  __DIR__ . '/init.php';

$files = recursive_scan_dir('tmp/stock');
$options = getopt("a", ["action:"]);
if (isset($options['a']) && $options['a'] == 'manual' || isset($options['action']) && $options['action'] == 'manual') {
	$files = recursive_scan_dir('manual/stock');
}
$delete = true;
$product_guid = [];
$stock_data = [];
$i = 0;

foreach($files as $file){
	if (file_exists(__DIR__.DIRECTORY_SEPARATOR.$file)) {
		evalBool($_ENV['DEBUG']) && error_log(date('Y-m-d H:i:s')."[DEBUG] StockFile: ".__DIR__.DIRECTORY_SEPARATOR.$file."\r\n", 3 , IMPORT_ERROR_LOG);
		$unsortedXml = simplexml_load_file(__DIR__.DIRECTORY_SEPARATOR.$file);

		// Convert <Product> nodes to an array
		$products = [];
		foreach ($unsortedXml->Products->Product as $product) {
			$products[] = $product;
		}

		// Sort by EcommerceProductGuid
		usort($products, function ($a, $b) {
			return strcmp((string)$a->EcommerceProductGuid, (string)$b->EcommerceProductGuid);
		});

		// Create a new root <Products> element
		$xml = new SimpleXMLElement('<Products/>');

		// Dynamically copy all children of each <Product>
		foreach ($products as $product) {
			$productNode = $xml->addChild('Product');
			foreach ($product->children() as $child) {
				$productNode->addChild($child->getName(), (string)$child);
			}
		}
		
		if($xml->Product){
			foreach ($xml->Product as $stock ){
				if($i < 10){
					if( !in_array( $stock->EcommerceProductGuid->__toString(), $product_guid ) ){
						$product_guid[] = $stock->EcommerceProductGuid->__toString();
					}
					$stock_data[] = $stock;
					$i++;
				} else {
					$products = get_product_by_guid($woocommerce, $product_guid);
					//Bulk Update Product Stock
					update_product($woocommerce, $stock_data, $products);

					$i = 0;
					$product_guid = [];
					$stock_data = [];
					if( !in_array( $stock->EcommerceProductGuid->__toString(), $product_guid ) ){
						$product_guid[] = $stock->EcommerceProductGuid->__toString();
					}
					$stock_data[] = $stock;
					$i++;
				}
			}
			//Bulk Update the Remaining Products
			update_product($woocommerce, $stock_data, $products);

		}
		$delete === true ? unlink(__DIR__.DIRECTORY_SEPARATOR.$file) : '';
	}
}

function update_product($woocommerce, $xml, $products){

	// Bouw een lookup-array van stock_data (ProductId => stock object)
	$stock_lookup = [];
	foreach ($xml as $stock_item) {
		$pid = (string)$stock_item->ProductId;
		$stock_lookup[$pid] = $stock_item;
	}

	$simple_updates = [];

	foreach($products as $product){
		if($product->type == 'variable'){
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
					
					// Bouw update array
					$data = [
						'id' => $variation->id,
						'manage_stock' => true,
						'stock_quantity' => (int)$stock->Quantity,
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
					evalBool($_ENV['DEBUG']) && error_log(date('Y-m-d H:i:s') . "[DEBUG] Variations updated for product {$product->id}: " . json_encode($variation_updates) . "\r\n", 3, IMPORT_ERROR_LOG);
					evalBool($_ENV['DEBUG']) && error_log(date('Y-m-d H:i:s') . "[DEBUG] Response: " . json_encode($response) . "\r\n", 3, IMPORT_ERROR_LOG);
				} catch (Exception $e) {
					error_log(date('Y-m-d H:i:s') . "[ERROR] Failed to update variations for product {$product->id}: " . $e->getMessage() . "\r\n", 3, IMPORT_ERROR_LOG);
				}
			}

		} else {
			// Simpel product, zoek matching stock
			foreach ($xml as $stock_item) {
				if ((string)$stock_item->EcommerceProductGuid === $product->EcommerceProductGuid) {
					$simple_updates[] = [
						'id' => $product->id,
						'manage_stock' => true,
						'stock_quantity' => (int)$stock_item->Quantity,
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
			evalBool($_ENV['DEBUG']) && error_log(date('Y-m-d H:i:s') . "[DEBUG] Simple products updated: " . json_encode($simple_updates) . "\r\n", 3, IMPORT_ERROR_LOG);
			evalBool($_ENV['DEBUG']) && error_log(date('Y-m-d H:i:s') . "[DEBUG] Response: " . json_encode($response) . "\r\n", 3, IMPORT_ERROR_LOG);
		} catch (Exception $e) {
			error_log(date('Y-m-d H:i:s') . "[ERROR] Failed to update simple products: " . $e->getMessage() . "\r\n", 3, IMPORT_ERROR_LOG);
		}
	}
}