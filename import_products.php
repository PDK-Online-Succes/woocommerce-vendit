<?php
require_once __DIR__ . '/init.php';
log_message('INFO', '=== START OF IMPORT_PRODUCTS.PHP ===');

$files = recursive_scan_dir('tmp/products');
$options = getopt("a", ["action:"]);
if ((isset($options['a']) && $options['a'] === 'manual') || (isset($options['action']) && $options['action'] === 'manual')) {
    $manual = true;
    $files = recursive_scan_dir('manual/products');
}

$total = $create = $update = $delete = 0;

$total_products = 0;
$total_created = 0;
$total_updated = 0;
$total_deleted = 0;

$total_variations_created = 0;
$total_variations_updated = 0;
$total_variations_deleted = 0;

$brand_cache = build_brand_cache($woocommerce);
$attribute_cache = build_attribute_cache($woocommerce);
$term_cache = build_term_cache($woocommerce, $attribute_cache);
$category_cache = build_category_cache($woocommerce);

// Verzamel alle GUIDs
$product_guids = [];
foreach ($files as $file) {
    if (!file_exists(__DIR__ . DIRECTORY_SEPARATOR . $file)) continue;
    $xml = simplexml_load_file(__DIR__ . DIRECTORY_SEPARATOR . $file);
    foreach ($xml->Products->Product as $item) {
        $product_guids[] = (string)$item->EcommerceProductGuid;
    }
}
//error_log("Unique GUIDs to fetch: " . json_encode(array_unique($product_guids))."\r\n", 3, IMPORT_ERROR_LOG);
//log_message('INFO', "Unique GUIDs to fetch: " . json_encode(array_unique($product_guids)));

global $product_map;
$product_map = get_products_by_guids_batch($woocommerce, array_unique($product_guids));

log_message('INFO', '=== START PRODUCTIMPORT ===');

process_products_from_xml($woocommerce, $files, $product_map, $attribute_cache, $term_cache, $brand_cache, $category_cache);

process_variations_from_xml_files($woocommerce, $files, $product_map, $attribute_cache, $term_cache, $category_cache);

log_message('INFO', '=== SAMENVATTING PRODUCTIMPORT ===');
log_message('INFO', "Totaal producten gevonden:      $total_products");
log_message('INFO', "Producten aangemaakt:           $total_created");
log_message('INFO', "Producten bijgewerkt:           $total_updated");
log_message('INFO', "Producten verwijderd:           $total_deleted");
log_message('INFO', "Variaties aangemaakt:           $total_variations_created");
log_message('INFO', "Variaties bijgewerkt:           $total_variations_updated");
log_message('INFO', "Variaties verwijderd:           $total_variations_deleted");
log_message('INFO', '=== EINDE PRODUCTIMPORT ===');

function process_products_from_xml($woocommerce, $xml_files, $product_map, &$attribute_cache, &$term_cache, &$brand_cache, &$category_cache) {
    $create = [];
    $update = [];
    $delete = [];

    foreach ($xml_files as $file) {
        if (!file_exists(__DIR__ . '/' . $file)) continue;

        $xml = simplexml_load_file(__DIR__ . '/' . $file);

        foreach ($xml->Products->Product as $product_xml) {
            $guid = (string)$product_xml->EcommerceProductGuid;
            clean_deleted_variations($product_xml);

            // Verwijderen
            if (strtolower((string)$product_xml->IsDeleted) === 'true') {
                if (isset($product_map[$guid])) {
                    $delete[] = ['id' => $product_map[$guid]->id];
                }
                continue;
            }

            // Type bepalen (simple, variable, bundled)
            $variations = $product_xml->ProductVariations->ProductVariation ?? [];
            $bundle     = $product_xml->MandatoryProducts->MandatoryProduct ?? [];

            if (count($variations) === 1 && count($bundle) === 0) {
                $type = 'simple';
            } elseif (count($variations) === 1 && count($bundle) > 0) {
                $type = 'bundle';
            } else {
                $type = 'variable';
            }

            // ➕ Nieuw product
            if (!isset($product_map[$guid])) {
				//log_message('CREATE', "GUID:  {$guid}" );
                $create[] = build_product_payload($product_xml, $type, null, $attribute_cache, $term_cache, $brand_cache, $category_cache, $product_map);
            } else {
				//log_message('UPDATE', "GUID:  {$guid}" );
                // ✏️ Bestaand product: update indien nodig
                $existing = $product_map[$guid];
                $new_data = build_product_payload($product_xml, $type, $existing, $attribute_cache, $term_cache, $brand_cache, $category_cache, $product_map);
                if ($new_data !== null) {

                    $update[] = $new_data;
                }
            }
        }
    }

    // Laatste batch
    flush_product_batches($woocommerce, $product_map, $create, $update, $delete);
}

function build_product_payload($xml, $type, $existing = null, &$attribute_cache, &$term_cache, &$brand_cache, &$category_cache, &$product_map) {
    $guid = (string)$xml->EcommerceProductGuid;
    $product_number = (string)$xml->ProductNumber;
    $name = (string)$xml->Description;
    $brand_name = (string)$xml->Brand;
    $short_description = (string)$xml->SmallInfo;
    $description = (string)$xml->BigInfo;

	$featured = (string)$xml->FrontPage;
	$rankmath_title = (string)$xml->PageTitle;
	$rankmath_focus_keyword = (string)$xml->MetaKeywords;
	$rankmath_description = (string)$xml->MetaDescription;

    $images = get_combined_images_from_xml($xml);

    $categories = [];
    $primary_cat_id = null;
    $primary_cat_name = '';

    if (isset($xml->Groups->ProductGroup)) {
        foreach ($xml->Groups->ProductGroup as $group) {
            $group_guid = (string)$group;
            $is_primary = strtolower((string)$group['Default']) === 'true';

            if (isset($category_cache[$group_guid])) {
                $cat = $category_cache[$group_guid];
                $cat_entry = ['id' => $cat->id];

                if ($is_primary || !$primary_cat_id) {
                    $primary_cat_id = $cat->id;
                    $primary_cat_name = $cat->name ?? '';
                    array_unshift($categories, $cat_entry);
                } else {
                    $categories[] = $cat_entry;
                }
            }
        }
    }

    $brand = get_brand_cached($brand_name, $brand_cache, $GLOBALS['batch_create_brands']);
    $brand_id = $brand->id ?? null;

    $attr_data = extract_attributes_from_spec_and_variation(
        $xml,
        $type,
        $primary_cat_name,
        $attribute_cache,
        $term_cache
    );

    $all_attributes = array_merge(
        $attr_data['attributes'] ?? [],
        $attr_data['variation_attributes'] ?? []
    );

    $product_data = [
        'name' => $name,
        'description' => $description,
        'short_description' => $short_description,
        'type' => $type,
		'featured' => $featured,
        'status' => (strtolower((string)$xml->Visible) === 'true') ? 'publish' : 'draft',
        'categories' => $categories,
        'images' => $images ?: [],
        'sku' => ($type === 'variable') ? $product_number : null,
        'EcommerceProductGuid' => $guid,
        'ProductNumber' => $product_number,
		'rank_math_title' => $rankmath_title,
        'rank_math_focus_keyword' => $rankmath_focus_keyword,
        'rank_math_description' => $rankmath_description,
    ];

    if ($primary_cat_id) {
        $product_data['meta_data'] = [
            ['key' => '_primary_term_product_cat', 'value' => $primary_cat_id],
            ['key' => 'rank_math_primary_product_cat', 'value' => $primary_cat_id],
        ];
    }

    if ($brand_id) {
        $product_data['brands'] = [ ['id' => $brand_id] ];
    }

    if (!empty($all_attributes)) {
        $product_data['attributes'] = $all_attributes;
    }

    // Simple product
    if ($type === 'simple' && isset($xml->ProductVariations->ProductVariation)) {
        $variation = $xml->ProductVariations->ProductVariation;
        $product_id = (string)$variation->ProductId;
        $sku = $product_number . '_' . $product_id;

        $product_data['regular_price'] = number_format((float)$variation->SalesPriceInc, 2, '.', '');
		$action_data = get_active_action_price_data_from_xml($variation->ActionPrices);

		if ($action_data) {
			$product_data['sale_price'] = $action_data['price'];
			$product_data['date_on_sale_from'] = $action_data['from'];
			$product_data['date_on_sale_to'] = $action_data['to'];
		}
        $product_data['sku'] = $sku;
        $product_data['ProductId'] = $product_id;
    }

	 // Bundle product
    if ($type === 'bundle' && isset($xml->ProductVariations->ProductVariation)) {
        $variation = $xml->ProductVariations->ProductVariation;
        $product_id = (string)$variation->ProductId;
        $sku = $product_number . '_' . $product_id;

        $product_data['regular_price'] = number_format((float)$variation->SalesPriceInc, 2, '.', '');
		$action_data = get_active_action_price_data_from_xml($variation->ActionPrices);

		if ($action_data) {
			$product_data['sale_price'] = $action_data['price'];
			$product_data['date_on_sale_from'] = $action_data['from'];
			$product_data['date_on_sale_to'] = $action_data['to'];
		}
        $product_data['sku'] = $sku;
        $product_data['ProductId'] = $product_id;

		$bundled_items = [];
        if (isset($xml->MandatoryProducts->MandatoryProduct)) {
            $position = 0;
            foreach ($xml->MandatoryProducts->MandatoryProduct as $mandatory) {
                $child_guid = (string)$mandatory;
                $child_product_id = (string)$mandatory['ProductId'];

                // Zoek product in $GLOBALS['product_map'] (zoals bij variaties)
               $linked = $product_map[$child_guid] ?? null;

                if ($linked && isset($linked->id)) {
                    $bundled_items[] = [
                        'product_id' => $linked->id,
                        'quantity_min' => 1,
                        'quantity_max' => 1,
                        'menu_order' => $position
                    ];
                    $position++;
                }
            }

            // Check of bestaande bundel afwijkt
            $existing_bundle = $existing->bundled_items ?? [];
            $changed_bundle = count($bundled_items) !== count($existing_bundle);

            if (!$changed_bundle) {
                foreach ($bundled_items as $i => $new_item) {
                    $old = $existing_bundle[$i] ?? null;
                    if (!$old || $old->product_id != $new_item['product_id']) {
                        $changed_bundle = true;
                        break;
                    }
                }
            }

            if ($changed_bundle) {
                // Markeer als volledige bundelvervanging
                $product_data['bundled_items'] = array_map(function ($item) {
                    return array_merge($item, ['delete' => false]);
                }, $bundled_items);

                // Voeg verwijdermarkering toe voor oude bundelitems (alle)
                foreach ($existing_bundle as $old) {
                    $product_data['bundled_items'][] = ['id' => $old->id, 'delete' => true];
                }
            }
        }
    }

    // ❗ Check op update
    if ($existing) {
        $changed = false;

        // Vergelijking op een aantal hoofdvelden
        if ($existing->name !== $product_data['name']) $changed = true;
        if ($existing->description !== $product_data['description']) $changed = true;
        if ($existing->short_description !== $product_data['short_description']) $changed = true;
        if ($existing->featured !== $product_data['featured']) $changed = true;
        if ($existing->status !== $product_data['status']) $changed = true;
        if ($existing->type !== $product_data['type']) $changed = true;
        if ($existing->sku !== $product_data['sku']) $changed = true;
        if ($existing->rank_math_title !== $product_data['rank_math_title']) $changed = true;
        if ($existing->rank_math_focus_keyword !== $product_data['rank_math_focus_keyword']) $changed = true;
        if ($existing->rank_math_description !== $product_data['rank_math_description']) $changed = true;

        if (images_changed($existing->images ?? [], $product_data['images'])) $changed = true;

        if (!$changed) return null;

        $product_data['id'] = $existing->id;
    }

    return $product_data;
}

function process_variations_from_xml_files($woocommerce, $xml_files, &$product_map, &$attribute_cache, &$term_cache, &$category_cache) {
    foreach ($xml_files as $file_index => $file) {
        log_message('INFO', "Verwerken van bestand {$file_index} → $file");
        if (!file_exists(__DIR__ . DIRECTORY_SEPARATOR . $file)) continue;

        $xml = simplexml_load_file(__DIR__ . DIRECTORY_SEPARATOR . $file);

        foreach ($xml->Products->Product as $i => $product_xml) {
            $guid = (string)$product_xml->EcommerceProductGuid;
            //clean_deleted_variations($product_xml);
            $variations = $product_xml->ProductVariations->ProductVariation ?? [];

            // Alleen variabele producten verwerken
            if (count($variations) <= 1) continue;

            log_message('INFO', "[{$i}] Verwerken product {$guid}");
            if (!isset($product_map[$guid]) || !isset($product_map[$guid]->id)) continue;

            $product = $product_map[$guid];

            // Primaire categorienaam ophalen
            $primary_cat_name = '';
            foreach ($product_xml->Groups->ProductGroup as $group) {
                if (strtolower((string)$group['Default']) === 'true') {
                    $group_guid = (string)$group;
                    if (isset($category_cache[$group_guid])) {
						$cat = $category_cache[$group_guid];
                		
                        $primary_cat_name = $cat->name ?? '';
                        break;
                    }
                }
            }

            $existing_variations = get_variations_by_product_guid_batch($woocommerce, $product->id);
            $batch = build_variation_batch_payload($product->id, $product_xml, $existing_variations, $primary_cat_name, $attribute_cache, $term_cache);

            flush_variation_batch($woocommerce, $product->id, $batch['create'], $batch['update'], $batch['delete']);
        }
    }
}

function build_variation_batch_payload($product_id, $product_xml, $existing_variations, $primary_cat_name, &$attribute_cache, &$term_cache) {
    $create = [];
    $update = [];
    $delete = [];
    $used_guids = [];

    foreach ($product_xml->ProductVariations->ProductVariation as $var_xml) {
        $guid = (string)$var_xml->EcommerceProductVariationGuid;

        // Verwijderde variatie?
        if (strtolower((string)$var_xml->IsDeleted) === 'true') {
            if (isset($existing_variations[$guid])) {
                $delete[] = ['id' => $existing_variations[$guid]->id];
            }
            continue;
        }

        $used_guids[] = $guid;
        $product_number = (string)$product_xml->ProductNumber;
        $product_id_vms = (string)$var_xml->ProductId;
        $sku = $product_number . '_' . $product_id_vms;

        $variation_data = [
            'sku' => $sku,
            'regular_price' => number_format((float)$var_xml->SalesPriceInc, 2, '.', ''),
            'description' => (string)$var_xml->ProductDescription,
            'EcommerceProductVariationGuid' => $guid,
            'ProductId' => $product_id_vms,
            'attributes' => build_variation_attributes_from_xml(
                $var_xml,
                $primary_cat_name,
                $attribute_cache,
                $term_cache,
                $GLOBALS['batch_create_attributes'],
                $GLOBALS['batch_create_terms']
            ),
        ];

        $action_data = get_active_action_price_data_from_xml($var_xml->ActionPrices);
        if ($action_data) {
            $variation_data['sale_price'] = $action_data['price'];
            $variation_data['date_on_sale_from'] = $action_data['from'];
            $variation_data['date_on_sale_to'] = $action_data['to'];
        }

        if (isset($var_xml->Images->Image)) {
            foreach ($var_xml->Images->Image as $img) {
                $variation_data['image'] = get_images($img);
                break;
            }
        }

        if (!isset($existing_variations[$guid])) {
            $create[] = $variation_data;
        } else {
            $existing = $existing_variations[$guid];
            $changed = false;

            if ($existing->sku !== $variation_data['sku']) $changed = true;
            if ((string)$existing->regular_price !== $variation_data['regular_price']) $changed = true;
            if ($existing->description !== $variation_data['description']) $changed = true;
            if (variation_image_changed($existing->image ?? null, $variation_data['image'] ?? null)) $changed = true;
            if (variation_attributes_changed($existing->attributes ?? [], $variation_data['attributes'])) $changed = true;

            if ($changed) {
                $variation_data['id'] = $existing->id;
                $update[] = $variation_data;
            }
        }
    }

    // Controle op variaties die niet meer in XML voorkomen
    foreach ($existing_variations as $guid => $variation) {
        if (!in_array($guid, $used_guids)) {
            $delete[] = ['id' => $variation->id];
        }
    }

    return [
        'product_id' => $product_id,
        'create' => $create,
        'update' => $update,
        'delete' => $delete
    ];
}


log_message('INFO', '=== END OF IMPORT_PRODUCTS.PHP ===');