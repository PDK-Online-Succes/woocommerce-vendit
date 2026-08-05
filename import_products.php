<?php
require_once __DIR__ . '/init.php';

// CLI opties
$options = getopt("a:o", ["action:", "options:"]);

$only_variations = false;
$only_products = false;

// Bepaal map
if ((isset($options['a']) && $options['a'] === 'manual') || (isset($options['action']) && $options['action'] === 'manual')) {
	$dir = 'manual/products';
} else {
	$dir = 'tmp/products';
}

// Bestanden ophalen
$files = recursive_scan_dir($dir);
if(!$files) exit();

// Opties instellen
if ((isset($options['o']) && $options['o'] === 'only_variations') || (isset($options['options']) && $options['options'] === 'only_variations')) {
	$only_variations = true;
}

if ((isset($options['o']) && $options['o'] === 'only_products') || (isset($options['options']) && $options['options'] === 'only_products')) {
	$only_products = true;
}

// --- Nieuwe functie: XML-bestanden splitsen indien nodig ---
split_large_xml_files($files, $dir, 500);

// Na splitsen bestanden opnieuw ophalen
$files = recursive_scan_dir($dir);

log_message('INFO', json_encode($files, JSON_PRETTY_PRINT));

$total = $create = $update = $delete = 0;

$total_products = 0;
$total_created = 0;
$total_updated = 0;
$total_deleted = 0;

$total_variations_created = 0;
$total_variations_updated = 0;
$total_variations_deleted = 0;

/**
 * PRE-SCAN FASE
 * ========================
 * 1. Bestaande caches ophalen
 * 2. XML scannen om nieuwe brands / attributes / terms te detecteren
 * 3. Batches aanmaken
 * 4. Caches opnieuw opbouwen
 */

log_message('INFO', 'Starting prescan for brands/attributes/terms');

// 1) Caches ophalen
$brand_cache = build_brand_cache($woocommerce);
$attribute_cache = build_attribute_cache($woocommerce);
$term_cache = build_term_cache($woocommerce, $attribute_cache);

// 2) Pre-scan XML voor de queues (alleen brands/attributes/terms verzamelen)
foreach ($files as $file) {
	if (!file_exists(__DIR__ . DIRECTORY_SEPARATOR . $file))
		continue;

	$xml = simplexml_load_file(__DIR__ . DIRECTORY_SEPARATOR . $file);

	foreach ($xml->Products->Product as $product_xml) {

		// Merk detecteren
		get_brand_cached((string) $product_xml->Brand, $brand_cache, $GLOBALS['batch_create_brands']);

		// Specs
		if (isset($product_xml->Specs->Spec)) {
			foreach ($product_xml->Specs->Spec as $spec) {
				$raw_name = (string) $spec->Name;
				$value = (string) $spec->Value;

				if (trim($raw_name) === '' || trim($value) === '')
					continue;

				// Mapping + attribute ophalen
				$mapped_name = map_attribute_name($raw_name, '');
				$attr_obj = get_or_create_attribute_cached($mapped_name, $attribute_cache, $GLOBALS['batch_create_attributes']);

				if ($attr_obj) {
					foreach (explode(',', $value) as $val) {
						$val = trim($val);
						if ($val === '')
							continue;

						get_or_create_term_cached($attr_obj->id, $val, $term_cache, $GLOBALS['batch_create_terms']);
					}
				}
			}
		}

		// Variatie-attributen
		if (isset($product_xml->ProductVariations->ProductVariation)) {
			foreach ($product_xml->ProductVariations->ProductVariation as $variation) {
				if (!isset($variation->Attributes->Attribute))
					continue;

				foreach ($variation->Attributes->Attribute as $attr) {
					$raw_name = (string) $attr->Name;
					$value = (string) $attr->Value;

					if (trim($raw_name) === '' || trim($value) === '')
						continue;

					$mapped_name = map_attribute_name($raw_name, '');
					$attr_obj = get_or_create_attribute_cached($mapped_name, $attribute_cache, $GLOBALS['batch_create_attributes']);

					if ($attr_obj) {
						get_or_create_term_cached($attr_obj->id, trim($value), $term_cache, $GLOBALS['batch_create_terms']);
					}
				}
			}
		}
	}
}

// 3) Batches uitvoeren om nieuwe items in WooCommerce aan te maken
//log_message('DEBUG', 'Brand: '. json_encode($GLOBALS['batch_create_brands'],JSON_PRETTY_PRINT));
flush_create_brands($woocommerce, $GLOBALS['batch_create_brands']);
$GLOBALS['batch_create_brands'] = [];

//log_message('DEBUG', 'Attributes: '. json_encode($GLOBALS['batch_create_attributes'],JSON_PRETTY_PRINT));
flush_create_attributes($woocommerce, $GLOBALS['batch_create_attributes']);
$GLOBALS['batch_create_attributes'] = [];

//log_message('DEBUG', 'Terms: '. json_encode($GLOBALS['batch_create_terms'],JSON_PRETTY_PRINT));
flush_create_terms($woocommerce, $GLOBALS['batch_create_terms']);
$GLOBALS['batch_create_terms'] = [];
$brand_cache = [];
$attribute_cache = [];
$term_cache = [];

gc_collect_cycles();

// 4) Caches opnieuw opbouwen met net aangemaakte items
$brand_cache = build_brand_cache($woocommerce);
$attribute_cache = build_attribute_cache($woocommerce);
$term_cache = build_term_cache($woocommerce, $attribute_cache);
$category_cache = build_category_cache($woocommerce);

log_message('INFO', 'Prescan complete, starting product import…');

//log_message('DEBUG', 'Attribute Cache: '. json_encode($attribute_cache, JSON_PRETTY_PRINT));
//log_message('DEBUG', 'Term Cache: '. json_encode($term_cache, JSON_PRETTY_PRINT));

global $product_map;

foreach ($files as $file_index => $file) {
	$total_products = $total_created = $total_updated = $total_deleted = $total_variations_created = $total_variations_updated = $total_variations_deleted = 0;

	log_message('INFO', '=== START PRODUCTIMPORT ===');
	log_message('INFO', "Verwerken van bestand {$file_index} → $file");
	if (!file_exists(__DIR__ . DIRECTORY_SEPARATOR . $file))
		continue;

	$xml = simplexml_load_file(__DIR__ . DIRECTORY_SEPARATOR . $file);

	// Verzamel alle GUIDs per bestand.
	$product_guids = [];
	foreach ($xml->Products->Product as $item) {
		$product_guids[] = (string) $item->EcommerceProductGuid;

		 /** SimilarProducts */
		if (isset($item->SimilarProducts->SimilarProduct)) {
			foreach ($item->SimilarProducts->SimilarProduct as $sim) {
				$product_guids[] = (string)$sim;
			}
		}

		/** Accessories */
		if (isset($item->Accessories->AccessoryProduct)) {
			foreach ($item->Accessories->AccessoryProduct as $acc) {
				$product_guids[] = (string)$acc;
			}
		}

	}
	$product_map = get_products_by_guids_batch($woocommerce, array_unique($product_guids));

	if (!$only_variations) {
		// Process alle producten
		process_products_from_xml($woocommerce, $xml, $product_map, $attribute_cache, $term_cache, $brand_cache, $category_cache);
	}

	if (!$only_products) {
		// Process alle variaties
		process_variations_from_xml_files($woocommerce, $xml, $product_map, $attribute_cache, $term_cache, $category_cache);
	}

	log_message('INFO', '=== SAMENVATTING PRODUCTIMPORT ===');
	log_message('INFO', "Totaal producten gevonden:      $total_products");
	if (!$only_variations) {
		log_message('INFO', "Producten aangemaakt:           $total_created");
		log_message('INFO', "Producten bijgewerkt:           $total_updated");
		log_message('INFO', "Producten verwijderd:           $total_deleted");
	} else {
		log_message('INFO', "Verwerk alleen de variaties.");
	}
	if (!$only_products) {
		log_message('INFO', "Variaties aangemaakt:           $total_variations_created");
		log_message('INFO', "Variaties bijgewerkt:           $total_variations_updated");
		log_message('INFO', "Variaties verwijderd:           $total_variations_deleted");
	} else {
		log_message('INFO', "Verwerk alleen de producten.");
	}

	log_message('INFO', '=== EINDE PRODUCTIMPORT ===');

	unlink(__DIR__ . DIRECTORY_SEPARATOR . $file);

	unset($xml);
	gc_collect_cycles();
}

function process_products_from_xml($woocommerce, $xml, $product_map, &$attribute_cache, &$term_cache, &$brand_cache, &$category_cache)
{
	$create = [];
	$update = [];
	$delete = [];

	foreach ($xml->Products->Product as $product_xml) {
		$guid = (string) $product_xml->EcommerceProductGuid;
		clean_deleted_variations($product_xml);

		// Verwijderen
		if (strtolower((string) $product_xml->IsDeleted) === 'true') {
			if (isset($product_map[$guid])) {
				$delete[] = ['id' => $product_map[$guid]->id];
			}
			continue;
		}

		// Type bepalen (simple, variable, bundled)
		$variations = $product_xml->ProductVariations->ProductVariation ?? [];
		$bundle = $product_xml->MandatoryProducts->MandatoryProduct ?? [];

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
			$create[] = build_product_payload($product_xml, $type, $product_map, $attribute_cache, $term_cache, $brand_cache, $category_cache, null);
		} else {
			//log_message('UPDATE', "GUID:  {$guid}" );
			// ✏️ Bestaand product: update indien nodig
			$existing = $product_map[$guid];
			$new_data = build_product_payload($product_xml, $type, $product_map, $attribute_cache, $term_cache, $brand_cache, $category_cache, $existing);
			if ($new_data !== null) {

				$update[] = $new_data;
			}
		}
	}

	// Laatste batch
	flush_product_batches($woocommerce, $product_map, $create, $update, $delete);

	unset($create, $update, $delete, $product_map);
	gc_collect_cycles();

	return true;
}

function build_product_payload($xml, $type, &$product_map, &$attribute_cache, &$term_cache, &$brand_cache, &$category_cache, $existing = null)
{
	$guid = (string) $xml->EcommerceProductGuid;
	$product_number = (string) $xml->ProductNumber;
	$name = (string) $xml->Description;
	$brand_name = (string) $xml->Brand;
	$short_description = (string) $xml->SmallInfo;
	$description = (string) $xml->BigInfo;

	$featured = (string) $xml->FrontPage;
	$rankmath_title = (string) $xml->PageTitle;
	$rankmath_focus_keyword = (string) $xml->MetaKeywords;
	$rankmath_description = (string) $xml->MetaDescription;

	$images = get_combined_images_from_xml($xml);
	//log_message('DEBUG', json_encode($images, JSON_PRETTY_PRINT));

	$categories = [];
	$primary_cat_id = null;
	$primary_cat_name = '';

	if (isset($xml->Groups->ProductGroup)) {
		foreach ($xml->Groups->ProductGroup as $group) {
			$group_guid = (string) $group;
			$is_primary = strtolower((string) $group['Default']) === 'true';

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
	$brand_id = $brand?->id;

	$attr_data = extract_attributes_from_spec_and_variation(
		$xml,
		$type,
		$primary_cat_name,
		$attribute_cache,
		$term_cache
	);
	//log_message('DEBUG', "Product_attr: ".json_encode($attr_data, JSON_PRETTY_PRINT));

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
		'status' => (strtolower((string) $xml->Visible) === 'true') ? 'publish' : 'draft',
		'categories' => $categories,
		'images' => $images ?? [],
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
		$product_data['brands'] = [['id' => $brand_id]];
	}

	if (!empty($all_attributes)) {
		$product_data['attributes'] = $all_attributes;
	}

	/** ============================================================
	 *  SimilarProducts  →  upsell_ids
	 * ============================================================ */
	$upsell_ids = [];

	if (isset($xml->SimilarProducts->SimilarProduct)) {
		foreach ($xml->SimilarProducts->SimilarProduct as $similar_xml) {
			//log_message('DEBUG', json_encode($similar_xml,JSON_PRETTY_PRINT));
			$similar_guid = (string)$similar_xml;

			$upsell_product = $product_map[$similar_guid] ?? null;
			if ($upsell_product && isset($upsell_product->id)) {
				$upsell_ids[] = (int)$upsell_product->id;
			}
		}
	}
	//log_message('DEBUG', json_encode($upsell_ids,JSON_PRETTY_PRINT));
	if (!empty($upsell_ids)) {
		$product_data['upsell_ids'] = $upsell_ids;
	}
	//log_message('DEBUG', json_encode($product_data['upsell_ids'],JSON_PRETTY_PRINT));
	/** ============================================================
	 *  Accessories  →  cross_sell_ids (met ItemOrder sortering)
	 * ============================================================ */
	$cross_sell_items = [];

	if (isset($xml->Accessories->AccessoryProduct)) {
		foreach ($xml->Accessories->AccessoryProduct as $accessory_xml) {
			//log_message('DEBUG', json_encode($accessory_xml,JSON_PRETTY_PRINT));
			$accessory_guid = (string)$accessory_xml;

			// ItemOrder uit attribuut, fallback = 9999
			$order = isset($accessory_xml['ItemOrder'])
				? (int)$accessory_xml['ItemOrder']
				: 9999;

			$cross_sell_product = $product_map[$accessory_guid] ?? null;

			if ($cross_sell_product && isset($cross_sell_product->id)) {
				$cross_sell_items[] = [
					'order' => $order,
					'id'    => (int)$cross_sell_product->id
				];
			}
		}
	}
	//log_message('DEBUG', json_encode($cross_sell_items,JSON_PRETTY_PRINT));

	if (!empty($cross_sell_items)) {
		usort($cross_sell_items, function($a, $b) {
			return $a['order'] <=> $b['order'];
		});

		$product_data['cross_sell_ids'] = array_column($cross_sell_items, 'id');
	}
	//log_message('DEBUG', json_encode($product_data['cross_sell_ids'],JSON_PRETTY_PRINT));

	// Simple product
	if ($type === 'simple' && isset($xml->ProductVariations->ProductVariation)) {
		$variation = $xml->ProductVariations->ProductVariation;
		$product_id = (string) $variation->ProductId;
		$sku = $product_number . '_' . $product_id;

		$product_data['regular_price'] = number_format((float) $variation->SalesPriceInc, 2, '.', '');
		$action_data = get_active_action_price_data_from_xml($variation->ActionPrices);

		if ($action_data) {
			$product_data['sale_price'] = $action_data['price'];
			$product_data['date_on_sale_from'] = $action_data['from'];
			$product_data['date_on_sale_to'] = $action_data['to'];
		}
		// Als er géén actie is, maar existing had wél een sale, dan sale velden leeg maken
		if (!$action_data && $existing) {
			$hadSale = !empty($existing->sale_price)
				|| !empty($existing->date_on_sale_from)
				|| !empty($existing->date_on_sale_to);

			if ($hadSale) {
				$product_data['sale_price'] = '';
				$product_data['date_on_sale_from'] = null;
				$product_data['date_on_sale_to'] = null;
			}
		}

		$product_data['sku'] = $sku;
		$product_data['ProductId'] = $product_id;
	}

	// Bundle product
	if ($type === 'bundle' && isset($xml->ProductVariations->ProductVariation)) {
		$variation = $xml->ProductVariations->ProductVariation;
		$product_id = (string) $variation->ProductId;
		$sku = $product_number . '_' . $product_id;

		$product_data['regular_price'] = number_format((float) $variation->SalesPriceInc, 2, '.', '');
		$action_data = get_active_action_price_data_from_xml($variation->ActionPrices);

		if ($action_data) {
			$product_data['sale_price'] = $action_data['price'];
			$product_data['date_on_sale_from'] = $action_data['from'];
			$product_data['date_on_sale_to'] = $action_data['to'];
		}
		// Als er géén actie is, maar existing had wél een sale, dan sale velden leeg maken
		if (!$action_data && $existing) {
			$hadSale = !empty($existing->sale_price)
				|| !empty($existing->date_on_sale_from)
				|| !empty($existing->date_on_sale_to);

			if ($hadSale) {
				$product_data['sale_price'] = '';
				$product_data['date_on_sale_from'] = null;
				$product_data['date_on_sale_to'] = null;
			}
		}

		$product_data['sku'] = $sku;
		$product_data['ProductId'] = $product_id;

		$bundled_items = [];
		if (isset($xml->MandatoryProducts->MandatoryProduct)) {
			$position = 0;
			foreach ($xml->MandatoryProducts->MandatoryProduct as $mandatory) {
				$child_guid = (string) $mandatory;
				$child_product_id = (string) $mandatory['ProductId'];

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
			$changed_bundle = count($bundled_items) != count($existing_bundle);

			if (!$changed_bundle) {
				foreach ($bundled_items as $i => $new_item) {
					$old = $existing_bundle[$i] ?? null;
					if (!$old || (int)$old->product_id !== (int)$new_item['product_id']) {
						$changed_bundle = true;
						break;
					}
				}
			}

			if ($changed_bundle) {
				// Nieuwe items klaarzetten
				$product_data['bundled_items_add'] = array_map(function ($item) {
					return array_merge($item, ['delete' => false]);
				}, $bundled_items);

				// Oude items apart markeren
				$product_data['bundled_items_delete'] = [];
				foreach ($existing_bundle as $old) {
					if (!empty($old->bundled_item_id)) {
						$product_data['bundled_items_delete'][] = [
							'bundled_item_id' => $old->bundled_item_id,
							'delete' => true
						];
					}
				}
			}
		}
	}

	// ❗ Check op update
	if ($existing) {
		$changed = false;

		if (isset($changed_bundle) && $changed_bundle)
			$changed = true;

		// Vergelijking op een aantal hoofdvelden
		if ($existing->name !== $product_data['name'])
			$changed = true;
		if ($existing->description !== $product_data['description'])
			$changed = true;
		if ($existing->short_description !== $product_data['short_description'])
			$changed = true;
		if ($existing->featured !== $product_data['featured'])
			$changed = true;
		if ($existing->status !== $product_data['status'])
			$changed = true;
		if ($existing->type !== $product_data['type'])
			$changed = true;
		if ($existing->sku !== $product_data['sku'])
			$changed = true;
		if ($existing->rank_math_title !== $product_data['rank_math_title'])
			$changed = true;
		if ($existing->rank_math_focus_keyword !== $product_data['rank_math_focus_keyword'])
			$changed = true;
		if ($existing->rank_math_description !== $product_data['rank_math_description'])
			$changed = true;

		if ($images === null) {
			// null → geen <Image>-elementen in de XML: Vendit heeft de afbeeldingen verwijderd.
			// Stuur images: [] zodat WooCommerce de bestaande afbeeldingen ook verwijdert.
			$product_data['images'] = [];
			if (!empty($existing->images)) {
				foreach ($existing->images as $img) {
					if (isset($img->id)) {
						delete_media_item($img->id);
					}
				}
				$changed = true;
			}
		} elseif (!empty($images)) {
			// Opgeloste afbeeldingen beschikbaar → vergelijk en werk bij indien gewijzigd.
			if (images_changed($existing->images ?? [], $images)) {
				foreach ($existing->images ?? [] as $img) {
					if (isset($img->id)) {
						delete_media_item($img->id);
					}
				}
				$changed = true;
			}
		} else {
			// [] → <Image>-elementen aanwezig in XML maar bestanden niet gevonden.
			// Tijdelijk niet beschikbaar: bestaande afbeeldingen ongemoeid laten.
			unset($product_data['images']);
			log_message('INFO', "Product {$guid}: afbeeldingen overgeslagen (bestanden niet beschikbaar), bestaande afbeeldingen behouden.");
		}

		// ➕ Prijsvelden meenemen in vergelijking (belangrijk voor simple/bundle)
		if (isset($product_data['regular_price'])) {
			if ((string) ($existing->regular_price ?? '') !== (string) $product_data['regular_price']) {
				$changed = true;
			}
		}

		$new_sale = $product_data['sale_price'] ?? '';
		$old_sale = $existing->sale_price ?? '';
		$new_sale_from = $product_data['date_on_sale_from'] ?? '';
		$old_sale_from = $existing->date_on_sale_from ?? '';
		$new_sale_to = $product_data['date_on_sale_to'] ?? '';
		$old_sale_to = $existing->date_on_sale_to ?? '';

		if ((string) $old_sale !== (string) $new_sale)
			$changed = true;
		if ((string) $old_sale_from !== (string) $new_sale_from)
			$changed = true;
		if ((string) $old_sale_to !== (string) $new_sale_to)
			$changed = true;

		if (!$changed)
			return null;

		$product_data['id'] = $existing->id;
	} else {
		$product_data['manage_stock'] = true;
		$product_data['stock_quantity'] = 0;
		$product_data['stock_status'] = 'outofstock';
		$product_data['backorders'] = 'no';
	}
	//log_message('DEBUG', 'Product Data: '.json_encode($product_data, JSON_PRETTY_PRINT));
	return $product_data;
}

function process_variations_from_xml_files($woocommerce, $xml, &$product_map, &$attribute_cache, &$term_cache, &$category_cache)
{
	foreach ($xml->Products->Product as $i => $product_xml) {
		$guid = (string) $product_xml->EcommerceProductGuid;
		//clean_deleted_variations($product_xml);
		$variations = $product_xml->ProductVariations->ProductVariation ?? [];

		// Alleen variabele producten verwerken
		if (count($variations) <= 1)
			continue;

		log_message('INFO', "[{$i}] Verwerken product {$guid}");
		if (!isset($product_map[$guid]) || !isset($product_map[$guid]->id))
			continue;

		$product = $product_map[$guid];

		// Primaire categorienaam ophalen
		$primary_cat_name = '';
		foreach ($product_xml->Groups->ProductGroup as $group) {
			if (strtolower((string) $group['Default']) === 'true') {
				$group_guid = (string) $group;
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

function build_variation_batch_payload($product_id, $product_xml, $existing_variations, $primary_cat_name, &$attribute_cache, &$term_cache)
{
	$create = [];
	$update = [];
	$delete = [];
	$used_guids = [];

	foreach ($product_xml->ProductVariations->ProductVariation as $var_xml) {
		$guid = (string) $var_xml->EcommerceProductVariationGuid;

		// Verwijderde variatie?
		if (strtolower((string) $var_xml->IsDeleted) === 'true') {
			if (isset($existing_variations[$guid])) {
				$delete[] = ['id' => $existing_variations[$guid]->id];
			}
			continue;
		}

		$used_guids[] = $guid;
		$product_number = (string) $product_xml->ProductNumber;
		$product_id_vms = (string) $var_xml->ProductId;
		$sku = $product_number . '_' . $product_id_vms;

		$variation_data = [
			'sku' => $sku,
			'regular_price' => number_format((float) $var_xml->SalesPriceInc, 2, '.', ''),
			'description' => (string) $var_xml->ProductDescription,
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
				$image = get_images($img);
				if ($image !== null) {
					$variation_data['image'] = $image;
				}
				break;
			}
		}

		if (!isset($existing_variations[$guid])) {
			$variation_data['manage_stock'] = true;
			$variation_data['stock_quantity'] = 0;
			$variation_data['stock_status'] = 'outofstock';
			$variation_data['backorders'] = 'no';

			$create[] = $variation_data;
		} else {
			$existing = $existing_variations[$guid];
			$changed = false;

			if ($existing->sku !== $variation_data['sku'])
				$changed = true;
			if ((string) $existing->regular_price !== $variation_data['regular_price'])
				$changed = true;
			if ($existing->description !== $variation_data['description'])
				$changed = true;

			if (!$action_data) {
				// Als er geen actie is, maar de bestaande variatie had wel een sale, leeg dan de sale velden
				$hadSale = !empty($existing->sale_price)
					|| !empty($existing->date_on_sale_from)
					|| !empty($existing->date_on_sale_to);

				if ($hadSale) {
					$variation_data['sale_price'] = '';
					$variation_data['date_on_sale_from'] = null;
					$variation_data['date_on_sale_to'] = null;
				}
			}

			if (variation_image_changed($existing->image ?? null, $variation_data['image'] ?? null))
				$changed = true;

			if (variation_attributes_changed($existing->attributes ?? [], $variation_data['attributes']))
				$changed = true;

			// ➕ Neem sale-velden mee in vergelijking
			$new_sale = $variation_data['sale_price'] ?? '';
			$old_sale = $existing->sale_price ?? '';
			$new_sale_from = $variation_data['date_on_sale_from'] ?? '';
			$old_sale_from = $existing->date_on_sale_from ?? '';
			$new_sale_to = $variation_data['date_on_sale_to'] ?? '';
			$old_sale_to = $existing->date_on_sale_to ?? '';

			if ((string) $old_sale !== (string) $new_sale)
				$changed = true;
			if ((string) $old_sale_from !== (string) $new_sale_from)
				$changed = true;
			if ((string) $old_sale_to !== (string) $new_sale_to)
				$changed = true;

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
	$batch = [
		'product_id' => $product_id,
		'create' => $create,
		'update' => $update,
		'delete' => $delete
	];

	log_message('DEBUG', 'Variation Batch: '.json_encode($batch, JSON_PRETTY_PRINT));

	return $batch;
}