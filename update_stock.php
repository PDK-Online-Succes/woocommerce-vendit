<pre>
<?php
require_once  __DIR__ . '/init.php';

if (file_exists(__DIR__ . '/import/Stock.xml')) {
	$xml = simplexml_load_file(__DIR__ . '/import/Stock.xml');
   //print_r($xml->Stocks->Stock);
   
	foreach ($xml->Products->Product as $stock ){
		$product_guid = $stock->EcommerceProductGuid->__toString();
		$product = get_product_by_guid($woocommerce, $product_guid);
		if($product){
			$product = $woocommerce->get("products/{$product[0]->id}");
			update_product($woocommerce, $stock, $product);
		}
	}
}

function update_product($woocommerce, $xml, $product){
	$data['manage_stock'] = true;
	$data['stock_quantity'] = $xml->Quantity->__toString();

	if($product->type == 'variable'){
		$variations = get_product_variation_by_xml_product_id($woocommerce, $product->id, $xml->ProductId->__toString());
		
		if($variations){
			//print_r($variations[0]->id);
			//die();
			$variation = $woocommerce->get("products/{$product->id}/variations/{$variations[0]->id}");

			$woocommerce->put("products/{$product->id}/variations/{$variation->id}", $data);
		}
		
	} else {
		$woocommerce->put("products/{$product->id}", $data);
	}
}

?>
</pre>