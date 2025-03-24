<?php
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

?>