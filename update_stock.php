<pre>
<?php
require_once  __DIR__ . '/init.php';

if (file_exists(__DIR__ . '/import/Stocks.xml')) {
	$xml = XmlReader::open(__DIR__ . '/import/Stocks.xml');
	$xml = simplexml_load_file(__DIR__ . '/import/Stocks.xml');
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
		$variation = get_product_variation_by_xml_product_id($woocommerce, $product->id, $xml->ProductId->__toString());
		if($variation){
			$variation = $woocommerce->put("products/{$product->id}/variations/{$variation[0]->id}", $data);
			update_variation($woocommerce, $xml, $variation);
		}
		
	} else {
		$woocommerce->put("products/{$product->id}", $data);
	}
}

?>
</pre>