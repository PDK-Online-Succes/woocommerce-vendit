<?php 
require_once  __DIR__ . '/init.php';
if (file_exists(__DIR__ . '/import/Groups.xml')) {
	$xml = simplexml_load_file(__DIR__ . '/import/Groups.xml');
   //print_r($xml->Groups->Group);
   
	foreach ($xml->Groups->Group as $group ){
		$group_guid = $group->GroupGuid->__toString();
		$category = get_category_by_guid($woocommerce, $group_guid);
		if(!$category){
			//Create categorie
			create_categorie($woocommerce, $group, $parent = 0);
		} else {
			//Update categorie with $category[0]->id
			update_categorie($woocommerce, $group, $category[0]->id);
		}
		//Delve Deeper for sub Categories
		recursive_subgroup($woocommerce, $group);
	}
}

function recursive_subgroup($woocommerce, $xml){
	//Check if we have reached the bottom
	if( !$xml->SubGroups->Group ) return error_log("We have reached the bottom.");
	foreach($xml->SubGroups->Group as $group){
		$group_guid = $group->GroupGuid->__toString();
		$category = get_category_by_guid($woocommerce, $group_guid);

		if(!$category){
			//Get the parent id with parent_guid
			$parent_guid = $group->Parent_Guid->__toString();
			$category = get_category_by_guid($woocommerce, $parent_guid);
			//Create subcategorie
			create_categorie($woocommerce, $group, $category[0]->id);
		} else {
			//Update subcategorie with $category[0]->id
			update_categorie($woocommerce, $group, $category[0]->id);
		}
		//print_r($group);
		recursive_subgroup($woocommerce, $group);
	}
	return $group->GroupName->__toString();
}

function create_categorie($woocommerce, $xml, $parent = 0){
	$data = array_filter([
		'name'        				=> !empty($xml->GroupName) 				? sanitize_text($xml->GroupName->__toString()) 				: '',
		'parent' 					=> !empty($parent) 						? intval($parent) 											: '',
        'description' 				=> !empty($xml->GroupDescription) 		? sanitize_text($xml->GroupDescription->__toString()) 		: '',
        'menu_order'  				=> !empty($xml->ItemOrder) 				? (int) $xml->ItemOrder->__toString() 						: '',
        'rank_math_title'  			=> !empty($xml->GroupMetaTitle) 		? sanitize_text($xml->GroupMetaTitle->__toString()) 		: '',
        'rank_math_focus_keyword'  	=> !empty($xml->GroupMetaKeywords) 		? sanitize_text($xml->GroupMetaKeywords->__toString()) 		: '',
        'rank_math_description'  	=> !empty($xml->GroupMetaDescription) 	? sanitize_text($xml->GroupMetaDescription->__toString())	: '',
		'group_guid' 				=> !empty($xml->GroupGuid) 				? sanitize_text($xml->GroupGuid->__toString()) 				: '',
	], function($value) {
		return $value !== '' && $value !== null;
	});
	//Send create request
	try {
        $response = $woocommerce->post('products/categories', $data);
        evalBool($_ENV['DEBUG']) && error_log("[DEBUG][POST] WooCommerce API Response: " . json_encode($response, JSON_PRETTY_PRINT));
    } catch (Exception $e) {
        error_log("[ERROR][POST] API Request Failed: " . $e->getMessage());
    }
}

function update_categorie($woocommerce, $xml, $id) {
	$taxonomy = $woocommerce->get("products/categories/{$id}");
    // Ensure data is properly sanitized
	// 'slug'        				=> !empty($xml->GroupName) 				? sanitize_text($xml->GroupName->__toString()) 				: '',	
	$data = array_filter([
		'name'        				=> (!empty($xml->GroupName) && $taxonomy->name != sanitize_text($xml->GroupName->__toString())) ? sanitize_text($xml->GroupName->__toString()) : '',
		'description' 				=> (!empty($xml->GroupDescription) && $taxonomy->description != sanitize_text($xml->GroupDescription->__toString())) ? sanitize_text($xml->GroupDescription->__toString()) : '',
		'menu_order'  				=> (!empty($xml->ItemOrder) && $taxonomy->menu_order != (int) $xml->ItemOrder->__toString()) ? (int) $xml->ItemOrder->__toString() : '',
		'rank_math_title'			=> (!empty($xml->GroupMetaTitle) && $taxonomy->rank_math_title != sanitize_text($xml->GroupMetaTitle->__toString())) ? sanitize_text($xml->GroupMetaTitle->__toString()) : '',
		'rank_math_focus_keyword'	=> (!empty($xml->GroupMetaKeywords) && $taxonomy->rank_math_focus_keyword != sanitize_text($xml->GroupMetaKeywords->__toString())) ? sanitize_text($xml->GroupMetaKeywords->__toString()) : '',
		'rank_math_description'		=> (!empty($xml->GroupMetaDescription) && $taxonomy->rank_math_description != sanitize_text($xml->GroupMetaDescription->__toString())) 	? sanitize_text($xml->GroupMetaDescription->__toString()) : '',
	], function($value) {
		return $value !== '' && $value !== null;
	});
	//'group_guid' 	=> isset($xml->GroupGuid) ? sanitize_text($xml->GroupGuid->__toString()) : '',
	if (empty($data)) return;
    //Send update request
    try {
        $response = $woocommerce->put("products/categories/{$id}", $data);
        evalBool($_ENV['DEBUG']) && error_log("[DEBUG][PUT] WooCommerce API Response: " . json_encode($response, JSON_PRETTY_PRINT));
		return $response;
    } catch (Exception $e) {
		evalBool($_ENV['DEBUG']) && error_log("[DEBUG][PUT] Input: " . json_encode($data, JSON_PRETTY_PRINT));
        error_log("[ERROR][PUT] API Request Failed: " . $e->getMessage());
		$response = json_decode($e->getResponse()->getBody(), true);
		error_log("[ERROR][PUT] Response Body: " . json_encode($response, JSON_PRETTY_PRINT));
        throw $e; // Re-throw the exception or handle it as needed
    }
}

?>