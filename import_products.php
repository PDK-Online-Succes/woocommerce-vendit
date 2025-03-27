<pre>
<?php
require_once  __DIR__ . '/init.php';

if (file_exists(__DIR__ . '/import/Products.xml')) {
	$xml = simplexml_load_file(__DIR__ . '/import/Products.xml');
	global $create;
	$create=0;
	$update=0;
	$delete=0;
	$total=0;
	$i=0;
	foreach ($xml->Products->Product as $item ){
		$total++;
		$EcommerceProductGuid = $item->EcommerceProductGuid->__toString();
		$product = get_product_by_guid($woocommerce, $EcommerceProductGuid);
		if(!$product){
			$create++;
			//Create product
			create_product($woocommerce, $item);
		} else {
			if($item->IsDeleted->__toString() === "False"){
				$update++;
				$product = $woocommerce->get("products/{$product[0]->id}");
				//Update product with $product[0]->id
				update_product($woocommerce, $item, $product->id);
			} else {
				$delete++;
				//Delete product with $product[0]->id
				$woocommerce->delete("products/{$product[0]->id}", ['force' => true]);
			}
		}
		$i++; 
		//if($i == 10) break;
	}
	error_log("[COMPLETE] Total: {$total} - Created: {$create} - Updated: {$update} - Deleted: {$delete}<br>");
}

function create_product($woocommerce, $xml){
	global $create;
	 $data = [
		'name'        				=> !empty($xml->Description) 				? sanitize_text($xml->Description->__toString()) 				: '',
		'description' 				=> !empty($xml->ProductDescription) 		? sanitize_html($xml->BigInfo->__toString()) 					: '',
		'short_description' 		=> !empty($xml->ProductShortDescription) 	? sanitize_html($xml->SmallInfo->__toString()) 					: '',
		'featured'					=> !empty($xml->FrontPage) 					? $xml->FrontPage->__toString()									: '',	
		'EcommerceProductGuid' 				=> !empty($xml->EcommerceProductGuid) 		? sanitize_text($xml->EcommerceProductGuid->__toString()) 		: '',
		'ProductNumber' 				=> !empty($xml->ProductNumber) 				? sanitize_text($xml->ProductNumber->__toString()) 				: '',
		'rank_math_title'  			=> !empty($xml->PageTitle) 			? sanitize_text($xml->PageTitle->__toString()) 			: '',
        'rank_math_focus_keyword'  	=> !empty($xml->MetaKeywords) 			? sanitize_text($xml->MetaKeywords->__toString()) 			: '',
        'rank_math_description'  	=> !empty($xml->MetaDescription) 		? sanitize_text($xml->MetaDescription->__toString())		: '',
	];
	if(!$xml->ProductVariations) { 
		$create--;
		return error_log( "[ERROR] No Variations Found: " . print_r( $xml, true ) );
	}

	$count = $xml->ProductVariations->ProductVariation->count();
	//echo "Variation Count: {$count}<br>";

	if($xml->Brand){
		$brand = get_brand($woocommerce, $xml->Brand->__toString());
		if(!$brand){
			$brand = create_brand($woocommerce, $xml->Brand->__toString());
		}	
		$data['brands'][] = [
			'id' => is_array($brand) ? $brand[0]->id : $brand->id
		];
	}

	foreach($xml->Groups->ProductGroup as $group){
		$category = get_category_by_guid($woocommerce, $group->__toString());
		$data['categories'][] = [
			'id' => $category[0]->id
		];
		if((string)$group['Default'] === "True"){
			//$default = $categorie[0]->id;
			//echo "Default ProductGroup ID: " . (string)$group . "\n";
			$data['meta_data'][] = [
				'key' => '_primary_term_product_cat',
				'value' => $category[0]->id
			];
			if ($category && isset($category[0])) {
                $primary_category_name = $category[0]->name;
            }
		}
	}

	$combined_images = get_combined_images_from_xml($xml);
	$current_images = $product->images ?? [];

	if (images_changed($current_images, $combined_images)) {
		$data['images'] = $combined_images;
	}
	if (empty($combined_images) && !empty($current_images)) {
		$data['images'] = [];
	}

	$data['attributes'] = build_combined_attributes_from_xml($xml, $woocommerce);

	// Deduplicate the images by `src` values
	if (isset($data['images']) && is_array($data['images'])) {
		$data['images'] = array_unique($data['images'], SORT_REGULAR);
	}

	$link_ids = get_linked_product_ids_from_xml($xml, $woocommerce);

	if (!empty($link_ids['upsell_ids'])) {
		$data['upsell_ids'] = [$link_ids['upsell_ids']];
		//print_r($data['upsell_ids']);
	}

	if (!empty($link_ids['cross_sell_ids'])) {
		$data['cross_sell_ids'] = [$link_ids['cross_sell_ids']];
		//print_r($data['cross_sell_ids']);
	}
	
	
	//print_r($data['images']);

	//check if ProductVariation is only one or multiple
	if($count > 1){
		$data['type'] = 'variable';
		$data['sku'] = !empty($xml->ProductNumber) ? sanitize_text($xml->ProductNumber->__toString()) : '';

		$data = array_filter($data, function($value) {
			return $value !== '' && $value !== null;
		});
		//Create Product
		try {
			$response = $woocommerce->post('products', $data);
			evalBool($_ENV['DEBUG']) && error_log("[DEBUG][POST] Product Create: " . json_encode($response, JSON_PRETTY_PRINT));

			foreach ($xml->ProductVariations->ProductVariation as $variation){
				$guid = $variation->EcommerceProductVariationGuid->__toString();

				$variation_check = get_product_variation_by_guid($woocommerce, $response->id, $guid);
				if(!$variation_check){
					//Create ProductVariation
					create_product_variation($woocommerce, $variation, $response->id, $xml->ProductNumber->__toString(), $primary_category_name, $xml->StockProduct->__toString());
				}
				//create_product_variation($woocommerce, $variation, "123", $xml->ProductNumber->__toString());
			}
			//print_r($data);
		} catch (Exception $e) {
			//error_log("[ERROR][POST] API Request Failed: " . $e->getMessage());
		}
	} else {
		$data['type'] = 'simple';
		$data['sku'] = ( !empty($xml->ProductNumber) && !empty($xml->ProductVariations->ProductVariation->ProductId) ) ? sanitize_text($xml->ProductNumber->__toString().'_'.$xml->ProductVariations->ProductVariation->ProductId->__toString()) : '';
		$data['regular_price'] = !empty($xml->ProductVariations->ProductVariation->SalesPriceInc) ? number_format(sanitize_text($xml->ProductVariations->ProductVariation->SalesPriceInc->__toString()),4,'.','') : '';
		$data['ProductId'] = !empty($xml->ProductVariations->ProductVariation->ProductId) ? sanitize_text($xml->ProductVariations->ProductVariation->ProductId->__toString()) : '';
		$data['manage_stock'] =  !empty($xml->StockProduct) ? strtolower(sanitize_text($xml->StockProduct->__toString())) : '';

		if($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice){
			$data['sale_price'] = !empty($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionPriceInc) ? number_format(sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionPriceInc->__toString()),4,'.','') : '';
			$data['date_on_sale_from'] = !empty($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionStart) ? sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionStart->__toString()) : '';
			$data['date_on_sale_to'] = !empty($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionEnd) ? sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionEnd->__toString()) : '';
		}

		$data = array_filter($data, function($value) {
			return $value !== '' && $value !== null;
		});
		//Create Product

		try {

			$response = $woocommerce->post('products', $data);
			evalBool($_ENV['DEBUG']) && error_log("[DEBUG][POST] Product Create: " . json_encode($response, JSON_PRETTY_PRINT));
			
		} catch (Exception $e) {
			error_log("[ERROR][POST] API Request Failed: " . $e->getMessage());
		}
		
	}
	//print_r($data);
	
}
function create_product_variation($woocommerce, $xml, $product_id, $sku, $primary_category_name, $stock=''){

		$data['sku'] = !empty($xml->ProductId) ? sanitize_text($sku.'_'.$xml->ProductId->__toString()) : '';
		$data['regular_price'] = !empty($xml->SalesPriceInc) ? number_format(sanitize_text($xml->SalesPriceInc->__toString()),4,'.','') : '';
		$data['EcommerceProductVariationGuid'] = !empty($xml->EcommerceProductVariationGuid) ? sanitize_text($xml->EcommerceProductVariationGuid->__toString()) : '';
		$data['ProductId'] = !empty($xml->ProductId) ? sanitize_text($xml->ProductId->__toString()) : '';
		$data['manage_stock'] =  !empty($stock) ? strtolower(sanitize_text($stock)) : '';
		
		foreach($xml->Attributes->Attribute as $attr){
			if( !empty($attr->SortOrder) ){
				$data['menu_order'] = (int)$attr->SortOrder;
				break;
			}
		}
		
		if($xml->ActionPrices->ActionPrice){
			$data['sale_price'] = !empty($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionPriceInc) ? number_format(sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionPriceInc->__toString()),4,'.','') : '';
			$data['date_on_sale_from'] = !empty($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionStart) ? sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionStart->__toString()) : '';
			$data['date_on_sale_to'] = !empty($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionEnd) ? sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionEnd->__toString()) : '';
		}
	
		if($xml->Images->Image){
			foreach($xml->Images->Image as $img){
				if((int)$img['ImageOrder'] === 0) $data['image'] = get_images($img);
			}
		}

		$variation_attributes = build_variation_attributes_from_xml($xml, $primary_category_name, $woocommerce);

		if (!empty($variation_attributes)) {
			$data['attributes'] = $variation_attributes;
		}

		$data = array_filter($data, function($value) {
			return $value !== '' && $value !== null;
		});

		//return $woocommerce->post("products/{$product_id}/variations", $data);
		try {
			$response = $woocommerce->post("products/{$product_id}/variations", $data);
			evalBool($_ENV['DEBUG']) && error_log("[DEBUG][POST] Variation Create: " . json_encode($response, JSON_PRETTY_PRINT));
		} catch (Exception $e) {
			error_log("[ERROR][POST] API Request Failed: " . $e->getMessage());
		}

	//print_r($data);

}
function update_product($woocommerce, $xml, $product_id){

	$product = $woocommerce->get("products/{$product_id}");

	$data['name']        				= ( !empty($xml->Description) && $product->name != sanitize_text($xml->Description->__toString()))										? sanitize_text($xml->Description->__toString()) 				: '';
	$data['description']				= ( !empty($xml->BigInfo) && $product->description != sanitize_html($xml->BigInfo->__toString())) 										? sanitize_html($xml->BigInfo->__toString()) 					: '';
	$data['short_description'] 			= ( !empty($xml->SmallInfo) && $product->short_description != sanitize_html($xml->SmallInfo->__toString())) 							? sanitize_html($xml->SmallInfo->__toString()) 					: '';
	$data['featured']					= ( !empty($xml->FrontPage) && (!empty($product->featured) && $product->featured != strtolower($xml->FrontPage->__toString())) ) 										? strtolower($xml->FrontPage->__toString())						: '';
	$data['EcommerceProductGuid'] 		= ( !empty($xml->EcommerceProductGuid) && $product->EcommerceProductGuid != sanitize_text($xml->EcommerceProductGuid->__toString()))  	? sanitize_text($xml->EcommerceProductGuid->__toString()) 		: '';
	$data['ProductNumber'] 				= ( !empty($xml->ProductNumber) && $product->ProductNumber != sanitize_text($xml->ProductNumber->__toString()))							? sanitize_text($xml->ProductNumber->__toString()) 				: '';
	$data['rank_math_title']  			= ( !empty($xml->PageTitle) && $product->rank_math_title != sanitize_text($xml->PageTitle->__toString()))								? sanitize_text($xml->PageTitle->__toString()) 					: '';
    $data['rank_math_focus_keyword'] 	= ( !empty($xml->MetaKeywords) && $product->rank_math_focus_keyword != sanitize_text($xml->MetaKeywords->__toString())) 				? sanitize_text($xml->MetaKeywords->__toString()) 				: '';
    $data['rank_math_description']  	= ( !empty($xml->MetaDescription) && $product->rank_math_description != sanitize_text($xml->MetaDescription->__toString()))				? sanitize_text($xml->MetaDescription->__toString())			: '';

	if(!$xml->ProductVariations) return "Error No ProductVariations";
	$count = $xml->ProductVariations->ProductVariation->count();
	//echo "Variation Count: {$count}<br>";

	if($xml->Brand){
		$brand = get_brand($woocommerce, $xml->Brand->__toString());
		if(!$brand){
			$brand = create_brand($woocommerce, $xml->Brand->__toString());
		}
		$new_brand_id = is_array($brand) ? $brand[0]->id : $brand->id;
    	$current_brand_id = isset($product->brands[0]->id) ? $product->brands[0]->id : null;

		if ($current_brand_id != $new_brand_id) {
			$data['brands'][] = [
				'id' => $new_brand_id
			];
		}
	}

	// Get current categories from the product
	$current_category_ids = array_map(function($cat) {
		return $cat->id;
	}, $product->categories);

	// Build new category list from XML
	$new_category_ids = [];
	$meta_data = [];

	foreach ($xml->Groups->ProductGroup as $group) {
		$category = get_category_by_guid($woocommerce, $group->__toString());

		if (!$category || !isset($category[0])) {
			continue; // Skip if not found
		}

		$cat_id = $category[0]->id;
		$new_category_ids[] = $cat_id;

		if ((string)$group['Default'] === "True") {
			$meta_data[] = [
				'key' => '_primary_term_product_cat',
				'value' => $cat_id
			];
            if ($category && isset($category[0])) {
                $primary_category_name = $category[0]->name;
            }
		}
	}

	// Compare current vs new
	$categories_changed = array_diff($new_category_ids, $current_category_ids) || array_diff($current_category_ids, $new_category_ids);

	if ($categories_changed) {
		$data = [
			'categories' => array_map(function($id) {
				return ['id' => $id];
			}, $new_category_ids),
		];

		if (!empty($meta_data)) {
			$data['meta_data'] = $meta_data;
		}
	}
	$combined_images = get_combined_images_from_xml($xml);
	$current_images = $product->images ?? [];

	if (images_changed($current_images, $combined_images)) {
		$data['images'] = $combined_images;
	}
	if (empty($combined_images) && !empty($current_images)) {
		$data['images'] = [];
	}

	$combined_attributes = build_combined_attributes_from_xml($xml, $woocommerce);	
	if (attributes_changed($product->attributes, $combined_attributes)) {
		$data['attributes'] = $combined_attributes;
		//print_r($data['attributes']);
	}

	$link_ids = get_linked_product_ids_from_xml($xml, $woocommerce);

	if (!empty($link_ids['upsell_ids'])) {
		$data['upsell_ids'] = $link_ids['upsell_ids'];
		//print_r($data['upsell_ids']);
	}

	if (!empty($link_ids['cross_sell_ids'])) {
		$data['cross_sell_ids'] = $link_ids['cross_sell_ids'];
		//print_r($data['cross_sell_ids']);
	}

	//print_r($combined_attributes);

	//check if ProductVariation is only one or multiple
	if($count > 1){
		//$data['type'] = $product->type != 'variable' ? 'variable' : '';
		$data['sku'] = ( !empty($xml->ProductNumber) && $product->sku != sanitize_text($xml->ProductNumber->__toString()) ) ? sanitize_text($xml->ProductNumber->__toString()) : '';

		$data = array_filter($data, function($value) {
			return $value !== '' && $value !== null;
		});

		//Update Product
		try {
			$response = $woocommerce->put("products/{$product->id}", $data);
			evalBool($_ENV['DEBUG']) && error_log("[DEBUG][PUT] Product Update: " . json_encode($response, JSON_PRETTY_PRINT));

			foreach ($xml->ProductVariations->ProductVariation as $variation){
				$guid = $variation->EcommerceProductVariationGuid->__toString();

				$variation_check = get_product_variation_by_guid($woocommerce, $response->id, $guid);
				if(!$variation_check && $variation->IsDeleted->__toString() === "False"){
					//Create ProductVariation
					create_product_variation($woocommerce, $variation, $response->id, $response->sku, $primary_category_name, $xml->StockProduct->__toString());
				} elseif ($variation_check) {
					if($variation->IsDeleted->__toString() === "True"){
						$woocommerce->delete("products/{$response->id}/variations/{$variation_check[0]->id}", ['force' => true]);
					} else {
						//Update ProductVariation
						update_product_variation($woocommerce, $variation, $response->id, $variation_check[0]->id, $response->sku, $primary_category_name);
					}
				}

				//create_product_variation($woocommerce, $variation, "123", $xml->ProductNumber->__toString());
			}
			//print_r($data);
		} catch (Exception $e) {
			//error_log("[ERROR][POST] API Request Failed: " . $e->getMessage());
		}
	} else {
		//$data['type'] = $product->type != 'simple' ? 'simple' : '';
		$data['sku'] = ( !empty($xml->ProductNumber) && !empty($xml->ProductVariations->ProductVariation->ProductId) && $product->sku != sanitize_text($xml->ProductNumber->__toString().'_'.$xml->ProductVariations->ProductVariation->ProductId->__toString()) ) ? sanitize_text($xml->ProductNumber->__toString().'_'.$xml->ProductVariations->ProductVariation->ProductId->__toString()) : '';
		$data['regular_price'] = ( !empty($xml->ProductVariations->ProductVariation->SalesPriceInc) && $product->regular_price != number_format(sanitize_text($xml->ProductVariations->ProductVariation->SalesPriceInc->__toString()),4,'.','') ) ? number_format(sanitize_text($xml->ProductVariations->ProductVariation->SalesPriceInc->__toString()),4,'.','') : '';
		//echo "Product Regular Price: {$product->regular_price}  - " . number_format(sanitize_text($xml->ProductVariations->ProductVariation->SalesPriceInc->__toString()),4,'.','') . "<br>";
		$data['ProductId'] = ( !empty($xml->ProductVariations->ProductVariation->ProductId) && $product->ProductId != sanitize_text($xml->ProductVariations->ProductVariation->ProductId->__toString())) ? sanitize_text($xml->ProductVariations->ProductVariation->ProductId->__toString()) : '';
		$data['manage_stock'] = ( !empty($xml->StockProduct) && ( !empty($product->manage_stock) && $product->manage_stock != strtolower(sanitize_text($xml->StockProduct->__toString())) )) ? strtolower(sanitize_text($xml->StockProduct->__toString())) : '';

		if($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice){
			$data['sale_price'] = ( !empty($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionPriceInc) && $product->sale_price != number_format(sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionPriceInc->__toString()),4,'.','') ) ? number_format(sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionPriceInc->__toString()),4,'.','') : '';
			$data['date_on_sale_from'] = ( !empty($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionStart) && $product->date_on_sale_from != sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionStart->__toString()) ) ? sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionStart->__toString()) : '';
			$data['date_on_sale_to'] = ( !empty($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionEnd) && $product->date_on_sale_to != sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionEnd->__toString()) ) ? sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionEnd->__toString()) : '';
		}

		$data = array_filter($data, function($value) {
			return $value !== '' && $value !== null;
		});
		//Create Product

		try {

			$response = $woocommerce->put("products/{$product->id}", $data);
			evalBool($_ENV['DEBUG']) && error_log("[DEBUG][PUT] Product Update: " . json_encode($response, JSON_PRETTY_PRINT));
			
		} catch (Exception $e) {
			error_log("[ERROR][POST] API Request Failed: " . $e->getMessage());
		}
		
	}
	//print_r($data);
}
//$woocommerce, $xml, $product_id, $sku
function update_product_variation($woocommerce, $xml, $product_id, $variation_id, $sku, $primary_category_name, $stock=''){
	$variation = $woocommerce->get("products/{$product_id}/variations/{$variation_id}");

	$data['sku'] = ( !empty($xml->ProductId) && $variation->sku != sanitize_text($sku.'_'.$xml->ProductId->__toString()) ) ? sanitize_text($sku.'_'.$xml->ProductId->__toString()) : '';
	$data['regular_price'] = ( !empty($xml->SalesPriceInc) && $variation->regular_price != number_format(sanitize_text($xml->SalesPriceInc->__toString()),4,'.','')  ) ? number_format(sanitize_text($xml->SalesPriceInc->__toString()),4,'.','')  : '';
	$data['EcommerceProductVariationGuid'] = ( !empty($xml->EcommerceProductVariationGuid) && $variation->EcommerceProductVariationGuid != sanitize_text($xml->EcommerceProductVariationGuid->__toString())) ? sanitize_text($xml->EcommerceProductVariationGuid->__toString()) : '';
	$data['ProductId'] = !empty($xml->ProductId) ? sanitize_text($xml->ProductId->__toString()) : '';
	$data['manage_stock'] = ( !empty($stock) && (!empty($variation->manage_stock) && $variation->manage_stock != strtolower(sanitize_text($stock)) ) ) ? strtolower(sanitize_text($stock)) : '';
	
	foreach($xml->Attributes->Attribute as $attr){
		if( !empty($attr->SortOrder) ){
			$data['menu_order'] = $variation->menu_order != (int)$attr->SortOrder ? (int)$attr->SortOrder : '';
			break;
		}
	}
	
	//$data['menu_order'] =( !empty($xml->Attributes->Attribute->SortOrder) && $variation->menu_order != (int)$xml->Attributes->Attribute->SortOrder ) ? (int)$xml->Attributes->Attribute->SortOrder : 0;

	if($xml->ActionPrices->ActionPrice){
		$data['sale_price'] = ( !empty($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionPriceInc) && $variation->sale_price != number_format(sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionPriceInc->__toString()),4,'.','') ) ? number_format(sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionPriceInc->__toString()),4,'.','') : '';
			$data['date_on_sale_from'] = ( !empty($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionStart) && $variation->date_on_sale_from != sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionStart->__toString()) ) ? sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionStart->__toString()) : '';
			$data['date_on_sale_to'] = ( !empty($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionEnd) && $variation->date_on_sale_to != sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionEnd->__toString()) ) ? sanitize_text($xml->ProductVariations->ProductVariation->ActionPrices->ActionPrice->ActionEnd->__toString()) : '';
	}

	if ($xml->Images->Image) {
		foreach ($xml->Images->Image as $img) {
			if ((int)$img['ImageOrder'] === 0) {
				$new_image = get_images($img);
				$current_image = $variation->image ?? null;
	
				if (variation_image_changed($current_image, $new_image)) {
					$data['image'] = $new_image;
				}
				break; // only one image needed
			}
		}
	} else {
		// No image in XML, but current variation has one — clear it
		if (!empty($variation->image)) {
			$data['image'] = null;
		}
	}
	$variation_attributes = build_variation_attributes_from_xml($xml, $primary_category_name, $woocommerce);

	// Fetch current attributes for comparison
	$variation = $woocommerce->get("products/{$product_id}/variations/{$variation_id}");
	$current_attributes = $variation->attributes ?? [];

	if (variation_attributes_changed($current_attributes, $variation_attributes)) {
		$data['attributes'] = $variation_attributes;
	}

	$data = array_filter($data, function($value) {
		return $value !== '' && $value !== null;
	});

	//return $woocommerce->post("products/{$product_id}/variations", $data);
	try {
		$response = $woocommerce->put("products/{$product_id}/variations/{$variation->id}", $data);
		evalBool($_ENV['DEBUG']) && error_log("[DEBUG][PUT] Variation Update: " . json_encode($response, JSON_PRETTY_PRINT));
	} catch (Exception $e) {
		error_log("[ERROR][POST] API Request Failed: " . $e->getMessage());
	}
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
function map_attribute_name($original_name, $primary_category_name) {
    $name = strtolower(trim($original_name));
    $category = strtolower(trim($primary_category_name));

    // Define attribute renaming rules
    $attribute_map = [
        'color' => 'Kleur',
        'size' => [
            'broeken' => 'Kledingmaat',
            'truien' => 'Kledingmaat',
            't-shirts' => 'Kledingmaat',
            'schoenen' => 'Schoenmaat',
            'laarzen' => 'Schoenmaat',
            // You can add more here
        ]
    ];

    // Global attribute mapping (e.g. color)
    if (isset($attribute_map[$name]) && is_string($attribute_map[$name])) {
        return $attribute_map[$name];
    }

    // Category-based mapping (e.g. size)
    if (isset($attribute_map[$name]) && is_array($attribute_map[$name])) {
        if (isset($attribute_map[$name][$category])) {
            return $attribute_map[$name][$category];
        }

        // ❗ Default fallback for "size" if category not mapped
        if ($name === 'size') {
            return 'Maat';
        }
    }

    // No match? Keep the original name
    return $original_name;
}
/*function build_variation_attributes_from_xml($variation_xml, $primary_category_name, $woocommerce) {
    $variation_attributes = [];

    if ($variation_xml->Attributes) {
        foreach ($variation_xml->Attributes->Attribute as $attr) {
            $original_name = (string)$attr->Name;
            $mapped_name = map_attribute_name($original_name, $primary_category_name);

            $attribute = get_attribute_by_name($woocommerce, $mapped_name);
            if (!$attribute) {
                $attribute = create_attribute($woocommerce, $mapped_name);
            }

            $attribute_id = is_array($attribute) ? $attribute[0]->id : $attribute->id;

            $term = get_attribute_term_by_name($woocommerce, $attribute_id, (string)$attr->Value);
            if (!$term) {
                $term = create_attribute_term($woocommerce, $attribute_id, (string)$attr->Value);
            }

            $term_name = is_array($term) ? $term[0]->name : $term->name;

            $variation_attributes[] = [
                'id' => $attribute_id,
                'option' => $term_name
            ];
        }
    }

    return $variation_attributes;
}*/
function build_variation_attributes_from_xml($variation_xml, $primary_category_name, $woocommerce) {
    $variation_attributes = [];

    if (!$variation_xml->Attributes) {
        return $variation_attributes;
    }

    $attributes_raw = [];

    // STEP 1: Gather and map attributes with SortOrder
    foreach ($variation_xml->Attributes->Attribute as $attr) {
        $original_name = (string)$attr->Name;
        $mapped_name = map_attribute_name($original_name, $primary_category_name);
        $value = (string)$attr->Value;
        $sort_order = isset($attr['SortOrder']) ? (int)$attr['SortOrder'] : 999;

        $attributes_raw[] = [
            'original_name' => $original_name,
            'mapped_name' => $mapped_name,
            'value' => $value,
            'sort_order' => $sort_order
        ];
    }

    // STEP 2: Sort by SortOrder
    usort($attributes_raw, function ($a, $b) {
        return $a['sort_order'] <=> $b['sort_order'];
    });

    // STEP 3: Build variation_attributes
    foreach ($attributes_raw as $attr) {
        $attribute = get_attribute_by_name($woocommerce, $attr['mapped_name']);
        if (!$attribute) {
            $attribute = create_attribute($woocommerce, $attr['mapped_name']);
        }

        $attribute_id = is_array($attribute) ? $attribute[0]->id : $attribute->id;

        $term = get_attribute_term_by_name($woocommerce, $attribute_id, $attr['value']);
        if (!$term) {
            $term = create_attribute_term($woocommerce, $attribute_id, $attr['value']);
        }

        $term_name = is_array($term) ? $term[0]->name : $term->name;

        $variation_attributes[] = [
            'id' => $attribute_id,
            'option' => $term_name
        ];
    }

    return $variation_attributes;
}


function build_combined_attributes_from_xml($xml, $woocommerce) {
    $attributes = [];
    $variation_attribute_map = [];
    $variation_attribute_names_mapped = [];

    // STEP 0: Determine primary category name
    $primary_category_name = null;
    foreach ($xml->Groups->ProductGroup as $group) {
        if ((string) $group['Default'] === 'True') {
            $category = get_category_by_guid($woocommerce, (string) $group);
            if ($category && isset($category[0])) {
                $primary_category_name = $category[0]->name;
            }
            break;
        }
    }

    // STEP 1: Collect variation attribute values
    if ($xml->ProductVariations && $xml->ProductVariations->ProductVariation) {
        foreach ($xml->ProductVariations->ProductVariation as $variation) {
            if ($variation->Attributes) {
                foreach ($variation->Attributes->Attribute as $attr) {
                    $original_name = (string) $attr->Name;
                    $mapped_name = map_attribute_name($original_name, $primary_category_name);

                    $variation_attribute_map[$mapped_name][] = (string) $attr->Value;
                    $variation_attribute_names_mapped[$mapped_name] = true;
                }
            }
        }
    }

    // STEP 2: Process specs, skipping mapped names used by variations
    if ($xml->Specs) {
        foreach ($xml->Specs->Spec as $spec) {
            $original_name = (string) $spec->Name;
            $mapped_name = map_attribute_name($original_name, $primary_category_name);

            if (isset($variation_attribute_names_mapped[$mapped_name])) {
                continue; // Variation version takes priority
            }

            $value = (string) $spec->Value;

            $attribute = get_attribute_by_name($woocommerce, $mapped_name);
            if (!$attribute) {
                $attribute = create_attribute($woocommerce, $mapped_name);
            }

            $attribute_id = is_array($attribute) ? $attribute[0]->id : $attribute->id;
            $attribute_name = is_array($attribute) ? $attribute[0]->name : $attribute->name;

            $term = get_attribute_term_by_name($woocommerce, $attribute_id, $value);
            if (!$term) {
                $term = create_attribute_term($woocommerce, $attribute_id, $value);
            }

            $term_name = is_array($term) ? $term[0]->name : $term->name;

            $attributes[] = [
                'id' => $attribute_id,
                'name' => $attribute_name,
                'visible' => true,
                'variation' => false,
                'options' => [$term_name]
            ];
        }
    }

    // STEP 3: Add variation attributes to final array
    foreach ($variation_attribute_map as $mapped_name => $values) {
        $attribute = get_attribute_by_name($woocommerce, $mapped_name);
        if (!$attribute) {
            $attribute = create_attribute($woocommerce, $mapped_name);
        }

        $attribute_id = is_array($attribute) ? $attribute[0]->id : $attribute->id;
        $attribute_name = is_array($attribute) ? $attribute[0]->name : $attribute->name;

        $term_names = [];
        foreach (array_unique($values) as $value) {
            $term = get_attribute_term_by_name($woocommerce, $attribute_id, $value);
            if (!$term) {
                $term = create_attribute_term($woocommerce, $attribute_id, $value);
            }
            $term_names[] = is_array($term) ? $term[0]->name : $term->name;
        }

        $attributes[] = [
            'id' => $attribute_id,
            'name' => $attribute_name,
            'visible' => true,
            'variation' => $attribute_name != "Kleur",
            'options' => $term_names
        ];
    }
	evalBool($_ENV['DEBUG']) && error_log("[DEBUG][POST] Attributes: " . json_encode($attributes, JSON_PRETTY_PRINT));
    return $attributes;
}

function attributes_changed($current_attributes, $new_attributes) {
    if (count($current_attributes) !== count($new_attributes)) {
        return true;
    }

    foreach ($new_attributes as $new_attr) {
        $matched = false;

        foreach ($current_attributes as $curr_attr) {
            // Match by attribute id or name
            if (
                (isset($new_attr['id']) && isset($curr_attr->id) && $new_attr['id'] == $curr_attr->id) ||
                (isset($new_attr['name']) && isset($curr_attr->name) && strtolower($new_attr['name']) == strtolower($curr_attr->name))
            ) {
                $curr_options = array_map('strval', $curr_attr->options ?? []);
                $new_options = array_map('strval', $new_attr['options']);

                sort($curr_options);
                sort($new_options);

                if ($curr_options !== $new_options) {
                    return true;
                }

                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return true;
        }
    }

    return false;
}
function variation_attributes_changed($current, $new) {
    if (count($current) !== count($new)) return true;

    foreach ($new as $index => $new_attr) {
        $curr_attr = $current[$index] ?? null;
        if (!$curr_attr) return true;

        $same_id = isset($new_attr['id'], $curr_attr->id) && $new_attr['id'] == $curr_attr->id;
        $same_name = isset($new_attr['name'], $curr_attr->name) && strtolower($new_attr['name']) == strtolower($curr_attr->name);

        $same_option = isset($new_attr['option'], $curr_attr->option) && $new_attr['option'] == $curr_attr->option;

        if ((!$same_id && !$same_name) || !$same_option) {
            return true;
        }
    }

    return false;
}
function get_images($xml){
	$params = [
		'search' => $xml->__toString(),  // Search by filename
		'per_page' => 1  // Limit the number of results
	];
	//var_dump($params);
	$media = fetch_wordpress_data('media', $params);
	//var_dump($media);
	
	if(!empty($media)){
		$data = [
			'id' => $media[0]['id'],
			'position' => (int)$xml['ImageOrder']
		];
	} else {
		$data = [
			'src' => url_origin( $_SERVER ).'/import/images/'.$xml->__toString(),
			'position' => (int)$xml['ImageOrder']
		];
	}
	//var_dump($data);
	return $data;
}
function get_combined_images_from_xml($xml) {
    $images = [];

    if (!isset($xml->ProductVariations->ProductVariation)) {
        return $images;
    }

    foreach ($xml->ProductVariations->ProductVariation as $variation) {
        if (isset($variation->Images->Image)) {
            foreach ($variation->Images->Image as $img) {
                $image_data = get_images($img); // your existing function
                $images[] = $image_data;
            }
        }
    }

    // Deduplicate by src or id
    $seen = [];
    $unique_images = [];

    foreach ($images as $image) {
        $key = isset($image['id']) ? 'id:' . $image['id'] : 'src:' . $image['src'];

        if (!in_array($key, $seen)) {
            $seen[] = $key;
            $unique_images[] = $image;
        }
    }

    // Add position (optional but recommended)
    foreach ($unique_images as $index => &$image) {
        $image['position'] = $index;
    }

    return $unique_images;
}
function images_changed($current_images, $new_images) {
    if (count($current_images) !== count($new_images)) {
        return true;
    }

    foreach ($new_images as $index => $new_image) {
        $current = $current_images[$index] ?? null;
        if (!$current) return true;

        $current_src = $current->src ?? null;
        $current_id  = $current->id ?? null;
        $new_src     = $new_image['src'] ?? null;
        $new_id      = $new_image['id'] ?? null;

        if (
            ($new_id && $current_id && $new_id != $current_id) ||
            ($new_src && $current_src && $new_src != $current_src)
        ) {
            return true;
        }
    }

    return false;
}
function variation_image_changed($current_image, $new_image) {
    if (!$current_image && !$new_image) return false;
    if (!$current_image || !$new_image) return true;

    $current_id  = $current_image->id ?? null;
    $current_src = $current_image->src ?? null;

    $new_id  = $new_image['id'] ?? null;
    $new_src = $new_image['src'] ?? null;

    return (
        ($new_id && $current_id && $new_id !== $current_id) ||
        ($new_src && $current_src && $new_src !== $current_src)
    );
}

function get_linked_product_ids_from_xml($xml, $woocommerce) {
    $upsells = [];
    $cross_sells = [];

    // Upsells from <SimilarProducts>
    if (isset($xml->SimilarProducts->SimilarProduct)) {
        foreach ($xml->SimilarProducts->SimilarProduct as $similar) {
            $product = get_product_by_guid($woocommerce, $similar->__toString());
			evalBool($_ENV['DEBUG']) && error_log("[DEBUG][GET] UpSell: " . json_encode($product, JSON_PRETTY_PRINT));
            if ($product) $upsells[] = $product[0]->id;
        }
    }

    // Cross-sells from <Parts>
    if (isset($xml->Parts->PartProduct)) {
        foreach ($xml->Parts->PartProduct as $part) {
            $product = get_product_by_guid($woocommerce, $part->__toString());
			evalBool($_ENV['DEBUG']) && error_log("[DEBUG][GET] CrossSell: " . json_encode($product, JSON_PRETTY_PRINT));
            if ($product) $cross_sells[] = $product[0]->id;
        }
    }

    // Cross-sells from <Accessories>
    if (isset($xml->Accessories->AccessoryProduct)) {
        foreach ($xml->Accessories->AccessoryProduct as $accessory) {
            $product = get_product_by_guid($woocommerce, $accessory->__toString());
			evalBool($_ENV['DEBUG']) && error_log("[DEBUG][GET] CrossSell2: " . json_encode($product, JSON_PRETTY_PRINT));
            if ($product) $cross_sells[] = $product[0]->id;
        }
    }

    // Deduplicate
    $upsells = array_values(array_unique($upsells));
    $cross_sells = array_values(array_unique($cross_sells));

    return [
        'upsell_ids' => implode(',', $upsells),
        'cross_sell_ids' => implode(',', $cross_sells)
    ];
}
?>
</pre>