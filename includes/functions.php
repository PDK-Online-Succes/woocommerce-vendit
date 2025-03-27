<?php
/**
 * Summary of get_product_by_guid
 * @param mixed $woocommerce
 * @param mixed $guid
 */
function get_product_by_guid($woocommerce, $guid){
	$params = [
		'EcommerceProductGuid' => "{$guid}"
	];

	evalBool($_ENV['DEBUG']) && error_log("[DEBUG][GET] Input GUID: " . json_encode($params, JSON_PRETTY_PRINT));
	//Send get Request
	try {
		$response = $woocommerce->get('products', $params);
		evalBool($_ENV['DEBUG']) && error_log("[DEBUG][GET] Get Product By GUID: " . json_encode($response, JSON_PRETTY_PRINT));
		return $response;
	} catch (Exception $e) {
		error_log("[ERROR][GET] API Request Failed: " . $e->getMessage());
	}
	
	return false;
}
/**
 * Summary of get_product_variation_by_guid
 * @param mixed $woocommerce
 * @param mixed $product_id
 * @param mixed $guid
 */
function get_product_variation_by_guid($woocommerce, $product_id, $guid){
	$params = [
		'EcommerceProductVariationGuid' => "{$guid}"
	];
	evalBool($_ENV['DEBUG']) && error_log("[DEBUG][GET] Input GUID: " . json_encode($params, JSON_PRETTY_PRINT));
	//Send get Request
	try {
		$response = $woocommerce->get("products/{$product_id}/variations", $params);
		evalBool($_ENV['DEBUG']) && error_log("[DEBUG][GET] Get Variation By GUID: " . json_encode($response, JSON_PRETTY_PRINT));
		return $response;
	} catch (Exception $e) {
		error_log("[ERROR][GET] API Request Failed: " . $e->getMessage());
	}
	return false;
}
/**
 * Summary of get_product_variation_by_xml_product_id
 * @param mixed $woocommerce
 * @param mixed $product_id
 * @param mixed $xml_product_id
 */
function get_product_variation_by_xml_product_id($woocommerce, $product_id, $xml_product_id){
	$params = [
		'ProductId' => "{$xml_product_id}"
	];
	evalBool($_ENV['DEBUG']) && error_log("[DEBUG][GET] Input GUID: " . json_encode($params, JSON_PRETTY_PRINT));
	//Send get Request
	try {
		$response = $woocommerce->get("products/{$product_id}/variations", $params);
		evalBool($_ENV['DEBUG']) && error_log("[DEBUG][GET] Get Variation By XML ProductId: " . json_encode($response, JSON_PRETTY_PRINT));
		return $response;
	} catch (Exception $e) {
		error_log("[ERROR][GET] API Request Failed: " . $e->getMessage());
	}
	return false;
}
/**
 * Summary of get_attribute_by_name
 * @param mixed $woocommerce
 * @param mixed $name
 * @return array
 */
function get_attribute_by_name($woocommerce, $name){
	$attributes = $woocommerce->get('products/attributes');

	$attribute = array_filter($attributes, function($attr) use ($name) {
		return strtolower($attr->name) === strtolower($name);
	});
	//Return attribute else empty array
	return array_values($attribute);
}
/**
 * Summary of get_attribute_term_by_name
 * @param mixed $woocommerce
 * @param mixed $attribute_id
 * @param mixed $term
 * @return array
 */
function get_attribute_term_by_name($woocommerce, $attribute_id, $term){
	$params = [
		'search' => $term,
		'per_page' => 100
		
	];
	$terms = $woocommerce->get("products/attributes/{$attribute_id}/terms", $params);

	$filtered_terms = array_filter($terms, function($t) use ($term) {
		return strtolower($t->name) === strtolower($term);
	});
	//Return term else empty array
	// print_r(array_values($filtered_terms));
	// die();
	return array_values($filtered_terms);
}

?>