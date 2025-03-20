<pre>
<?php
require_once  __DIR__ . '/init.php';

if (file_exists(__DIR__ . '/import/Products.xml')) {
	$xml = XmlReader::open(__DIR__ . '/import/Products.xml');
	$xml = simplexml_load_file(__DIR__ . '/import/Products.xml');

	foreach ($xml->Products->Product as $item ){
		$EcommerceProductGuid = $item->EcommerceProductGuid->__toString();
		$product = get_product_by_guid($woocommerce, $EcommerceProductGuid);
		if(!$product){
			//Create product
			create_product($woocommerce, $item);
		} else {
			//Update product with $product[0]->id
			//echo "Product SKU: {$product_sku} Product ID: {$product[0]->id}<br>";
			//print_r($product);
			//update_product($woocommerce, $item, $product[0]->id);
		}
	}

}

function get_product_by_guid($woocommerce, $guid){
	$params = [
		'EcommerceProductGuid' => "{$guid}"
	];

	//Send get Request
	try {
		$response = $woocommerce->get('products', $params);
		//evalBool($_ENV['DEBUG']) && error_log("[DEBUG][GET] WooCommerce API Response: " . json_encode($response, JSON_PRETTY_PRINT));
		return $response;
	} catch (Exception $e) {
		error_log("[ERROR][GET] API Request Failed: " . $e->getMessage());
	}
	return false;
}

function get_product_variation_by_guid($woocommerce, $product_id, $guid){
	$params = [
		'EcommerceProductVariationGuid' => "{$guid}"
	];

	//Send get Request
	try {
		$response = $woocommerce->get("products/{$product_id}/variations", $params);
		//evalBool($_ENV['DEBUG']) && error_log("[DEBUG][GET] WooCommerce API Response: " . json_encode($response, JSON_PRETTY_PRINT));
		return $response;
	} catch (Exception $e) {
		error_log("[ERROR][GET] API Request Failed: " . $e->getMessage());
	}
	return false;
}

function create_product($woocommerce, $xml){
	// $data = array_filter([
	// 	'name'        				=> !empty($xml->ProductName) 				? sanitize_text($xml->ProductName->__toString()) 				: '',
	// 	'type' 						=> !empty($xml->ProductType) 				? sanitize_text($xml->ProductType->__toString()) 				: '',
	// 	'sku' 						=> !empty($xml->ProductSKU) 				? sanitize_text($xml->ProductSKU->__toString()) 				: '',
	// 	'regular_price' 			=> !empty($xml->ProductPrice) 				? sanitize_text($xml->ProductPrice->__toString()) 			: '',
	// 	'description' 				=> !empty($xml->ProductDescription) 		? sanitize_text($xml->ProductDescription->__toString()) 		: '',
	// 	'short_description' 		=> !empty($xml->ProductShortDescription) 	? sanitize_text($xml->ProductShortDescription->__toString()) 	: '',
	// 	'categories' 				=> !empty($xml->ProductCategory) 			? sanitize_text($xml->ProductCategory->__toString()) 			: '',
	// 	'images' 					=> !empty($xml->ProductImages) 			? sanitize_text($xml->ProductImages->__toString()) 			: '',
	// 	'attributes' 				=> !empty($xml->ProductAttributes) 		? sanitize_text($xml->ProductAttributes->__toString()) 		: '',
	// 	'meta_data' 				=> !empty($xml->ProductMetaData) 			? sanitize_text($xml->ProductMetaData->__toString()) 			: '',
	// 	'stock_quantity' 			=> !empty($xml->ProductStockQuantity) 		? sanitize_text($xml->ProductStockQuantity->__toString()) 		: '',
	// 	'stock_status' 				=> !empty($xml->ProductStockStatus) 		? sanitize_text($xml->ProductStockStatus->__toString()) 		: '',
	// 	'weight' 					=> !empty($xml->ProductWeight) 			? sanitize_text($xml->ProductWeight->__toString()) 			: '',
	// 	'dimensions' 				=> !empty($xml->ProductDimensions) 		? sanitize_text($xml->ProductDimensions->__toString()) 		: '',
	// ]);
	if(!$xml->ProductVariations) return "Error No ProductVariations";
	$count = $xml->ProductVariations->ProductVariation->count();
	echo "Count: {$count}<br>";

	foreach($xml->Groups->ProductGroup as $group){
		$categorie = get_category_by_guid($woocommerce, $group->EcommerceProductGroupGuid->__toString());
		$data['categories'][] = [
			'id' => $categorie[0]->id
		];
		if((string)$group['Default'] === "True"){
			//$default = $categorie[0]->id;
			//echo "Default ProductGroup ID: " . (string)$group . "\n";
			$data['meta_data'][] = [
				'key' => '_primary_term_product_cat',
				'value' => $categorie[0]->id
			];
		}

	}
	print_r($data);

	//check if ProductVariation is only one or multiple
	if($count > 1){
		//Create Product
		//$response = json_decode($woocommerce->post('products', $data), true);
		//echo "Product ID: {$response['id']}<br>";

		foreach ($xml->ProductVariations->ProductVariation as $variation){
			//Create ProductVariation
			//create_product_variation($woocommerce, $variation, $response['id']);
			//print_r($variation);
		}
	} else {
		//Create Product
		//$woocommerce->post('products', $data);
	}

}
function create_product_variation($woocommerce, $xml, $product_id){

	foreach($xml->Images->Image as $img){
		$data['categories'][] = [
			'id' => $categorie[0]->id
		];

	}

}
function update_product($woocommerce, $xml, $product_id){

	$count = count($xml->ProductVariations->ProductVariation);
	echo "Count: {$count}<br>";
	//check if ProductVariation is only one or multiple
	if($count > 1){
		//Create Product
		//$response = json_decode($woocommerce->post('products', $data), true);
		//echo "Product ID: {$response['id']}<br>";
		//foreach ($item->ProductVariations->ProductVariation as $variation){
			//Create ProductVariation
			//create_product_variation($woocommerce, $variation, $response['id']);
		//}
	} else {
		//Create Product
		//$woocommerce->post('products', $data);
	}
}
function update_product_variation($woocommerce, $xml, $id){

}

function create_attribute($woocommerce, $name){
	$attribute = [
		'name' => $name,
		'slug' => 'pa_'.sanitize_slug($name),
		'type' => 'select',
		'order_by' => 'menu_order',
		'has_archives' => true
	];
	//Create attribute
	$response = $woocommerce->post('products/attributes', $attribute);
	return $response;
}
function create_attribute_term($woocommerce, $attribute_id, $term){
	$term = [
		'name' => $term,
		'slug' => sanitize_slug($term)
	];
	//Create attribute
	$response = $woocommerce->post("products/attributes/{$attribute_id}/terms", $term);
	return $response;
}
function get_attribute_by_name($woocommerce, $name){
	$attributes = $woocommerce->get('products/attributes');

	$attribute = array_filter($attributes, function($attr) use ($name) {
		return strtolower($attr->name) === strtolower($name);
	});
	//Return attribute else empty array
	return $attribute;
}
function get_attribute_term_by_name($woocommerce, $attribute_id, $term){
	$params = [
		'search' => $term
	];
	$terms = $woocommerce->get("products/attributes/{$attribute_id}/terms", $params);

	return $terms;
	// $attribute = array_filter($attributes, function($attr) use ($name) {
	// 	return strtolower($attr->name) === strtolower($name);
	// });
	// //Return attribute else empty array
	// return $attribute;
}

$attribute = get_attribute_by_name($woocommerce, 'Test');
print_r($attribute);
$term = get_attribute_term_by_name($woocommerce, $attribute[0]->id, 'Test123');
print_r($term);
?>
</pre>