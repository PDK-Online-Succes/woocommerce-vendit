<pre>
<?php
require_once  __DIR__ . '/init.php';
$files = recursive_scan_dir('tmp/stock');
$delete = true;

foreach($files as $file){
	if (file_exists(__DIR__.DIRECTORY_SEPARATOR.$file)) {
		error_log("[DEBUG] StockFile: ".__DIR__.DIRECTORY_SEPARATOR.$file, 3 , IMPORT_ERROR_LOG);
		$xml = simplexml_load_file(__DIR__.DIRECTORY_SEPARATOR.$file);
		//print_r($xml->Stocks->Stock);
		if($xml->Products->Product){
			foreach ($xml->Products->Product as $stock ){
				$product_guid = $stock->EcommerceProductGuid->__toString();
				$product = get_product_by_guid($woocommerce, $product_guid);
				if($product){
					$product = $woocommerce->get("products/{$product[0]->id}");
					update_product($woocommerce, $stock, $product);
				} else {
					$delete = false;
					error_log("[DEBUG] Product not found: {$product_guid}", 3 , IMPORT_ERROR_LOG);
				}
			}
		}
		$delete === true ? unlink(__DIR__.DIRECTORY_SEPARATOR.$file) : '';
	}
	//error_log("[COMPLETE] Total: {$total} - Created: {$create} - Updated: {$update} - Deleted: {$delete}<br>");
}

function update_product($woocommerce, $xml, $product){
	

	if($product->type == 'variable'){
		$variations = get_product_variation_by_xml_product_id($woocommerce, $product->id, $xml->ProductId->__toString());
		
		if($variations){
			//print_r($variations[0]->id);
			//die();
			$variation = $woocommerce->get("products/{$product->id}/variations/{$variations[0]->id}");

			$data['manage_stock'] = $variation->manage_stock != true ? true : '';
			$data['stock_quantity'] = $variation->stock_quantity != $xml->Quantity->__toString() ? $xml->Quantity->__toString() : '';
			$data = array_filter($data, function($value) {
				return $value !== '' && $value !== null;
			});
			if(empty($data)) return error_log("[DEBUG] No data to update for product: {$variation->id}", 3 , IMPORT_ERROR_LOG);

			$woocommerce->put("products/{$product->id}/variations/{$variation->id}", $data);
		}
		
	} else {
		$data['manage_stock'] = $product->manage_stock != true ? true : '';
		$data['stock_quantity'] = $product->stock_quantity != $xml->Quantity->__toString() ? $xml->Quantity->__toString() : '';
		$data = array_filter($data, function($value) {
			return $value !== '' && $value !== null;
		});
		if(empty($data)) return error_log("[DEBUG] No data to update for product: {$product->id}", 3 , IMPORT_ERROR_LOG);

		$woocommerce->put("products/{$product->id}", $data);
	}
}

?>
</pre>