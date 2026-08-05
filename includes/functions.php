<?php
function recursive_scan_dir($path)
{
	$files = [];
	$entries = scandir($path);
	foreach ($entries as $entry) {
		$dotDB = explode(".", $entry);
		$dotDB = end($dotDB);
		//Remove ., .., .DS_Store, ._.DS_Store, ._, db, url
		if ($entry != "." && $entry != "..") {
			if (is_dir($path . DIRECTORY_SEPARATOR . $entry)) {
				chmod($path . DIRECTORY_SEPARATOR . $entry, 0775);
				$files[$entry] = recursive_scan_dir($path . DIRECTORY_SEPARATOR . $entry);
			} else {
				if ($entry == ".DS_Store" || $entry == "._.DS_Store" || $entry == "Thumbs.db" || substr($entry, 0, 2) == "._" || $dotDB == "db" || $dotDB == "url") {
					unlink($path . DIRECTORY_SEPARATOR . $entry);
					//echo $entry." Removed\r\n";
				} else {
					$files[] = (string) $path . DIRECTORY_SEPARATOR . $entry;
				}
			}
		}
	}
	return $files;
}

/**
 * Summary of get_product_by_guid
 * @param mixed $woocommerce
 * @param mixed $guid
 */
function get_products_by_guids_batch($woocommerce, array $guids)
{
	if (empty($guids))
		return [];

	$results = [];
	$chunks = array_chunk($guids, 100); // WooCommerce REST API default max is 100 per page

	foreach ($chunks as $chunk) {
		$params = [
			'EcommerceProductGuid' => $chunk,
			'per_page' => 100,
		];

		try {
			$response = $woocommerce->get('products', $params);
			//print_r($response);
			//log_message(['error','get'], "Batch get_product_by_guid failed: " . json_encode($response, JSON_PRETTY_PRINT));

			foreach ($response as $product) {
				$guid = $product->EcommerceProductGuid ?? null;
				if ($guid) {
					$results[$guid] = $product;
				}
			}
		} catch (Exception $e) {
			log_message(['error', 'get'], "Batch get_product_by_guid failed: " . $e->getMessage());
		}
	}
	//error_log("Batch fetched products: " . json_encode($results, JSON_PRETTY_PRINT), 3, IMPORT_ERROR_LOG);
	return $results;
}

function get_variations_by_product_guid_batch($woocommerce, $product_id)
{
	$variations = [];
	$page = 1;

	do {
		try {
			$response = $woocommerce->get("products/{$product_id}/variations", [
				'per_page' => 100,
				'page' => $page,
			]);

			foreach ($response as $variation) {
				$guid = $variation->EcommerceProductVariationGuid ?? null;
				if ($guid) {
					$variations[$guid] = $variation;
				}
			}

			$count = count($response);
			$page++;
		} catch (Exception $e) {
			log_message('error', "Failed to fetch variations for product {$product_id}: " . $e->getMessage());
			break;
		}
	} while ($count === 100);

	return $variations;
}

function get_categories_by_group_guids($woocommerce, $guids = [])
{
	if (empty($guids))
		return [];

	$params = [
		'group_guid' => $guids,
		'per_page' => 100,
	];

	try {
		$response = $woocommerce->get('products/categories', $params);
		evalBool($_ENV['DEBUG']) && log_message(['info', 'get'], "Batch Category Lookup: " . json_encode($response, JSON_PRETTY_PRINT));
		// Filter naar unieke group_guid
		$filtered = [];
		foreach ($response as $cat) {
			$guid = $cat->GroupGuid ?? $cat->group_guid ?? null;
			if (!$guid)
				continue;

			// Bewaar de eerste die we tegenkomen, of kies op basis van voorwaarden
			if (!isset($filtered[$guid]) || $cat->parent > 0) {
				$filtered[$guid] = $cat;
			}
		}

		return array_values($filtered);
	} catch (Exception $e) {
		log_message(['error', 'get'], "Batch category fetch failed: " . $e->getMessage());

	}

	return [];
}

// Multi-functional fetch function
function wp_rest_request($method, $endpoint, $params = [], $body = null)
{
	$base_url = rtrim($_ENV['SiteURL'], '/') . '/wp-json/' . ltrim($endpoint, '/');

	if (in_array(strtoupper($method), ['GET', 'DELETE']) && !empty($params)) {
		$base_url .= '?' . http_build_query($params);
	}

	$ch = curl_init($base_url);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
	curl_setopt($ch, CURLOPT_USERPWD, $_ENV['wp_user'] . ':' . $_ENV['wp_secret']);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

	if (in_array(strtoupper($method), ['POST', 'PUT']) && !empty($body)) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json'
		]);
	}

	$response = curl_exec($ch);
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	$data = json_decode($response, true);

	if ($http_code >= 200 && $http_code < 300) {
		return $data;
	} else {
		log_message(['ERROR', strtoupper($method)], "Request to {$base_url} failed (HTTP {$http_code}): " . json_encode($data));
		return [
			'error' => true,
			'http_code' => $http_code,
			'message' => $data['message'] ?? 'Unknown error',
		];
	}
}

function fetch_wordpress_data($endpoint, $params = [])
{
	return wp_rest_request('GET', "wp/v2/{$endpoint}", $params);
}
function delete_media_item($media_id)
{
	$result = wp_rest_request('DELETE', "wp/v2/media/{$media_id}", ['force' => true]);

	if (isset($result['error']) && $result['error']) {
		log_message(['ERROR', 'DELETE'], "Failed to delete media item {$media_id}: " . $result['message']);
		return false;
	}

	log_message(['INFO', 'DELETE'], "Media item {$media_id} deleted.");
	return true;
}

function url_origin($s = [], $use_forwarded_host = false)
{
	// Fallback base URL for CLI
	$cli_base_url = $_ENV['ImportURL'];

	// If run from CLI or $_SERVER not properly set
	if (php_sapi_name() === 'cli' || empty($s['HTTP_HOST'])) {
		return $cli_base_url;
	}

	$ssl = (!empty($s['HTTPS']) && $s['HTTPS'] == 'on');
	$sp = strtolower($s['SERVER_PROTOCOL']);
	$protocol = substr($sp, 0, strpos($sp, '/')) . ($ssl ? 's' : '');
	$port = $s['SERVER_PORT'];
	$port = ((!$ssl && $port == '80') || ($ssl && $port == '443')) ? '' : ':' . $port;
	$host = $use_forwarded_host && isset($s['HTTP_X_FORWARDED_HOST'])
		? $s['HTTP_X_FORWARDED_HOST']
		: ($s['HTTP_HOST'] ?? $s['SERVER_NAME'] ?? null);

	$host = $host ?? 'localhost';
	return $protocol . '://' . $host . $port;
}


// ✅ Cachinglaag met volledige paginatie en batch-wachtrijen voor importscript, inclusief batch splitsing bij >100

// Batch queues
$batch_create_brands = [];
$batch_create_attributes = [];
$batch_create_terms = [];

function build_product_cache_by_guid($woocommerce, array $guids)
{
	$cache = [];
	$chunks = array_chunk($guids, 100);

	foreach ($chunks as $chunk) {
		$page = 1;
		do {
			$params = [
				'EcommerceProductGuid' => $chunk,
				'per_page' => 100,
				'page' => $page
			];

			try {
				$response = $woocommerce->get('products', $params);
				foreach ($response as $product) {
					$guid = $product->EcommerceProductGuid ?? null;
					if ($guid) {
						$cache[$guid] = $product;
					}
				}
				$count = count($response);
				$page++;
			} catch (Exception $e) {
				log_message('error', "Batch fetch failed (page $page): " . $e->getMessage());
				break;
			}
		} while ($count === 100);
	}

	return $cache;
}

// Brand cache met paginatie
function build_brand_cache($woocommerce)
{
	$cache = [];
	$page = 1;
	do {
		try {
			$response = $woocommerce->get('products/brands', [
				'per_page' => 100,
				'page' => $page
			]);
			foreach ($response as $brand) {
				$cache[trim(strtolower($brand->name))] = $brand;
			}
			$count = count($response);
			$page++;
		} catch (Exception $e) {
			log_message('error', "Failed to fetch brands (page $page): " . $e->getMessage());
			break;
		}
	} while ($count === 100);
	return $cache;
}

function build_attribute_cache($woocommerce)
{
	$cache = [];
	$page = 1;
	do {
		try {
			$response = $woocommerce->get('products/attributes', [
				'per_page' => 100,
				'page' => $page
			]);
			foreach ($response as $attr) {
				$cache[strtolower($attr->name)] = $attr;
			}
			$count = count($response);
			$page++;
		} catch (Exception $e) {
			log_message('error', "Failed to fetch attributes (page $page): " . $e->getMessage());
			break;
		}
	} while ($count === 100);
	return $cache;
}

function build_term_cache($woocommerce, $attribute_cache)
{
	$cache = [];
	foreach ($attribute_cache as $attr) {
		$attr_id = $attr->id;
		$page = 1;
		do {
			try {
				$terms = $woocommerce->get("products/attributes/{$attr_id}/terms", [
					'per_page' => 100,
					'page' => $page
				]);
				foreach ($terms as $term) {
					$cache[$attr_id][strtolower($term->name)] = $term;
				}
				$count = count($terms);
				$page++;
			} catch (Exception $e) {
				log_message('error', "Failed to fetch terms for attr {$attr_id} (page {$page}): " . $e->getMessage());
				break;
			}
		} while ($count === 100);
	}
	return $cache;
}

function build_category_cache($woocommerce)
{
	$cache = [];
	$page = 1;
	do {
		try {
			$response = $woocommerce->get('products/categories', [
				'per_page' => 100,
				'page' => $page
			]);
			foreach ($response as $cat) {
				$guid = $cat->GroupGuid ?? $cat->group_guid ?? null;
				if ($guid) {
					$cache[$guid] = $cat;
				}
			}
			$count = count($response);
			$page++;
		} catch (Exception $e) {
			log_message('error', "Failed to fetch categories (page $page): " . $e->getMessage());
			break;
		}
	} while ($count === 100);
	return $cache;
}

// Queue helpers
function queue_create_brand($name, &$queue)
{
	$slug = sanitize_slug(trim($name));
	$queue[$slug] = ['name' => trim($name), 'slug' => $slug];
}

function queue_create_attribute($name, &$queue)
{
	$slug = 'pa_' . sanitize_slug(trim($name));
	$queue[$slug] = [
		'name' => trim($name),
		'slug' => $slug,
		'type' => 'select',
		'order_by' => 'menu_order',
		'has_archives' => true
	];
}

function queue_create_term($attr_id, $term_name, &$queue)
{
	$slug = sanitize_slug(trim($term_name));
	$queue[$attr_id][$slug] = ['name' => trim($term_name), 'slug' => $slug];
}

// Batch flushers (in sets van 100)
function flush_create_brands($woocommerce, &$queue)
{
	if (empty($queue)){
		return;
	}
	$chunks = array_chunk(array_values($queue), 100);
	foreach ($chunks as $chunk) {
		try {
			$woocommerce->post('products/brands/batch', ['create' => $chunk]);
			log_message('info', "Created batch of " . count($chunk) . " brands");
		} catch (Exception $e) {
			log_message('error', "Brand batch failed: " . $e->getMessage());
		}
	}
	$queue = [];
}

function flush_create_attributes($woocommerce, &$queue)
{
	if (empty($queue)){
		return;
	}
	$chunks = array_chunk(array_values($queue), 100);
	foreach ($chunks as $chunk) {
		try {
			$woocommerce->post('products/attributes/batch', ['create' => $chunk]);
			log_message('info', "Created batch of " . count($chunk) . " attributes");
		} catch (Exception $e) {
			log_message('error', "Attribute batch failed: " . $e->getMessage());
		}
	}
	$queue = [];
}

function flush_create_terms($woocommerce, &$queue)
{
	foreach ($queue as $attr_id => $terms) {
		if (empty($terms)){
			continue;
		}
		$chunks = array_chunk(array_values($terms), 100);
		foreach ($chunks as $chunk) {
			try {
				$woocommerce->post("products/attributes/{$attr_id}/terms/batch", ['create' => $chunk]);
				log_message('info', "Created batch of " . count($chunk) . " terms for attr {$attr_id}");
			} catch (Exception $e) {
				log_message('error', "Term batch failed for attr {$attr_id}: " . $e->getMessage());
			}
		}
	}
	$queue = [];
}

function get_brand_cached($brand_name, &$brand_cache, &$batch_create_brands)
{
	$key = strtolower(htmlspecialchars(trim($brand_name)));
	if (isset($brand_cache[$key])){
		return $brand_cache[$key];
	}
	queue_create_brand(trim($brand_name), $batch_create_brands);
	return null;
}

function get_or_create_attribute_cached($name, &$attribute_cache, &$batch_create_attributes)
{
	$key = strtolower($name);
	if (isset($attribute_cache[$key])){
		return $attribute_cache[$key];
	}
	queue_create_attribute($name, $batch_create_attributes);
	return null;
}

function get_or_create_term_cached($attr_id, $term_name, &$term_cache, &$batch_create_terms)
{
	$key = strtolower($term_name);
	if (isset($term_cache[$attr_id][$key])){
		return $term_cache[$attr_id][$key];
	}
	queue_create_term($attr_id, $term_name, $batch_create_terms);
	return null;
}
function map_attribute_name($original_name, $primary_category_name)
{
	$name = strtolower(trim($original_name));
	$category = strtolower(trim($primary_category_name));

	// Define attribute renaming rules
	$attribute_map = [
		//'color' => 'Kleur',
		'color' => [
			'luchtbuks' => 'Joule',
			'luchtbuks / geweer' => 'Joule',
			'luchtdrukpistool' => 'Joule',
			'luchtdrukpistool-kopen' => 'Joule',
			'luchtdrukmunitie' => 'Joule',
			'pcp buks' => 'Joule',
			'veer buks' => 'Joule',
			'buks to 7.5j' => 'Joule',
			'buks van 7.5 - 100j' => 'Joule',
			'buks van 100 - 500j' => 'Kaliber',
			'buks 500j+' => 'Joule',
			'veer pistool' => 'Joule',
			'pistool tot 7.5j' => 'Joule',
		],
		'size' => [
			//Kleding
			'broeken' => 'Broekmaat',
			'schoenen' => 'Schoenmaat',
			'laarzen' => 'Schoenmaat',
			//Wapens
			'luchtbuks' => 'Kaliber',
			'luchtbuks / geweer' => 'Kaliber',
			'luchtdrukpistool' => 'Kaliber',
			'luchtdrukpistool-kopen' => 'Kaliber',
			'luchtdrukmunitie' => 'Kaliber',
			'pcp buks' => 'Kaliber',
			'veer buks' => 'Kaliber',
			'buks to 7.5j' => 'Kaliber',
			'buks van 7.5 - 100j' => 'Kaliber',
			'buks van 100 - 500j' => 'Kaliber',
			'buks 500j+' => 'Kaliber',
			'veer pistool' => 'Kaliber',
			'pistool tot 7.5j' => 'Kaliber',
			'pellets' => 'Kaliber',
			'jacht' => 'Kaliber',
			'wapen' => 'Kaliber',
			'groot kaliber geweer' => 'Kaliber',
			'klein kaliber geweer' => 'Kaliber',
			'schietsport' => 'Kaliber',
			'klein kaliber pistool' => 'Kaliber',
			'groot kaliber pistool' => 'Kaliber',
			//Kijkers
			'kijkers' => 'Vergroting',
			'verrekijkers' => 'Vergroting',
			'monokijkers' => 'Vergroting',
			'spotting scope' => 'Vergroting',
			'afstand meter' => 'Vergroting',
			// You can add more here
		]
	];

	// Global attribute mapping (e.g. color)
	if (isset($attribute_map[$name]) && is_string($attribute_map[$name])) {
		//log_message('DEBUG', 'Attribute map: '.json_encode($attribute_map[$name], JSON_PRETTY_PRINT));
		return $attribute_map[$name];
	}

	// Category-based mapping (e.g. size)
	if (isset($attribute_map[$name]) && is_array($attribute_map[$name])) {
		if (isset($attribute_map[$name][$category])) {
			//log_message('DEBUG', 'Attribute map: '.json_encode($attribute_map[$name][$category], JSON_PRETTY_PRINT));
			return $attribute_map[$name][$category];
		}

		// ❗ Default fallback for "size" if category not mapped
		if ($name === 'size')
			return 'Maat';
		if ($name === 'color')
			return 'Kleur';
	}

	// No match? Keep the original name
	return $original_name;
}

function get_images($xml)
{
	if (!$xml->__toString())
		return null;

	$filename = $xml->__toString();

	$local_path = dirname(__DIR__) . '/import/images/' . $filename;
	// Probeer eerst media match
	$params = [
		'search' => $filename,
		'per_page' => 1
	];
	$media = fetch_wordpress_data('media', $params);

	if (!empty($media)) {
		return [
			'id' => $media[0]['id'],
			'position' => (int) $xml['ImageOrder']
		];
	}

	// Bestaat lokaal?
	if (!file_exists($local_path)) {
		log_message('DEBUG', "File NOT FOUND: [$local_path] (raw filename: '" . $filename . "')");
	} else {
		log_message('DEBUG', "File FOUND: [$local_path]");
		log_message('DEBUG', "Public URL: " . url_origin($_SERVER) . '/import/images/' . $filename);
		return [
			'src' => url_origin($_SERVER) . '/import/images/' . $filename,
			'position' => (int) $xml['ImageOrder']
		];
	}

	// Geen geldige afbeelding
	return null;
}
/**
 * Verzamelt afbeeldingen uit alle ProductVariation/Images/Image-elementen.
 *
 * Retourneert:
 *   null  → geen <Image>-elementen in de XML (Vendit heeft de afbeelding(en) verwijderd)
 *   []    → <Image>-elementen aanwezig maar geen enkel bestand gevonden (tijdelijk niet beschikbaar)
 *   [...] → opgeloste afbeeldingen klaar voor de WooCommerce REST API-payload
 */
function get_combined_images_from_xml($xml)
{
	if (!isset($xml->ProductVariations->ProductVariation)) {
		return null;
	}

	$xmlImageCount = 0;
	$images = [];
	$skipped = 0;

	foreach ($xml->ProductVariations->ProductVariation as $variation) {
		if (isset($variation->Images->Image)) {
			foreach ($variation->Images->Image as $img) {
				// Lege <Image/>-elementen niet meetellenvooral bij 'no image' exports
				if ((string) $img === '') {
					continue;
				}
				$xmlImageCount++;
				$image_data = get_images($img);
				if ($image_data !== null) {
					$images[] = $image_data;
				} else {
					$skipped++;
				}
			}
		}
	}

	// Geen <Image>-elementen gevonden in de XML → Vendit heeft de afbeeldingen verwijderd
	if ($xmlImageCount === 0) {
		return null;
	}

	// <Image>-elementen aanwezig maar geen enkel bestand oplosbaar → tijdelijk niet beschikbaar
	if (empty($images)) {
		log_message('WARNING', "get_combined_images_from_xml: {$skipped} afbeelding(en) overgeslagen — bestanden niet gevonden in mediabibliotheek of lokaal bestandssysteem.");
		return [];
	}

	if ($skipped > 0) {
		log_message('WARNING', "get_combined_images_from_xml: {$skipped} van de " . ($xmlImageCount) . " afbeelding(en) niet oplosbaar.");
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

	// Positie opnieuw zetten
	foreach ($unique_images as $index => &$image) {
		$image['position'] = $index;
	}

	return $unique_images;
}
function images_changed($current_images, $new_images)
{
	if (count($current_images) !== count($new_images)) {
		return true;
	}

	foreach ($new_images as $index => $new_image) {
		$current = $current_images[$index] ?? null;
		if (!$current)
			return true;

		$current_src = $current->src ?? null;
		$current_id = $current->id ?? null;
		$new_src = $new_image['src'] ?? null;
		$new_id = $new_image['id'] ?? null;

		if (
			($new_id && $current_id && $new_id != $current_id) ||
			($new_src && $current_src && $new_src != $current_src)
		) {
			return true;
		}
	}

	return false;
}
function variation_image_changed($current_image, $new_image)
{
	if (!$current_image && !$new_image)
		return false;
	if (!$current_image || !$new_image)
		return true;

	$current_id = $current_image->id ?? null;
	$current_src = $current_image->src ?? null;

	$new_id = $new_image['id'] ?? null;
	$new_src = $new_image['src'] ?? null;

	return (
		($new_id && $current_id && $new_id !== $current_id) ||
		($new_src && $current_src && $new_src !== $current_src)
	);
}

function build_variation_attributes_from_xml($variation_xml, $primary_category_name, &$attribute_cache, &$term_cache, &$batch_create_attributes, &$batch_create_terms)
{
	$variation_attributes = [];

	if (!isset($variation_xml->Attributes->Attribute)) {
		return $variation_attributes;
	}

	$attributes_raw = [];

	// STEP 1: Gather and map attributes with SortOrder
	foreach ($variation_xml->Attributes->Attribute as $attr) {
		$original_name = (string) $attr->Name;
		$mapped_name = map_attribute_name($original_name, $primary_category_name);
		$value = (string) $attr->Value;
		$sort_order = isset($attr['SortOrder']) ? (int) $attr['SortOrder'] : 999;

		$attributes_raw[] = [
			'original_name' => $original_name,
			'mapped_name' => $mapped_name,
			'value' => $value,
			'sort_order' => $sort_order
		];
	}

	// STEP 2: Sort by SortOrder
	//usort($attributes_raw, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
	usort($attributes_raw, function ($a, $b) {
		return $a['sort_order'] <=> $b['sort_order'];
	});

	// STEP 3: Build variation_attributes
	foreach ($attributes_raw as $attr) {
		$attribute = get_or_create_attribute_cached($attr['mapped_name'], $attribute_cache, $batch_create_attributes);
		if (!$attribute)
			continue;

		$attribute_id = $attribute->id;

		$split_values = array_map('trim', explode(',', $attr['value']));
		foreach ($split_values as $val) {
			if ($val === '')
				continue;

			$term = get_or_create_term_cached($attribute_id, $val, $term_cache, $batch_create_terms);
			if (!$term)
				continue;

			$variation_attributes[] = [
				'id' => $attribute_id,
				'option' => $term->name
			];
		}
	}

	return $variation_attributes;
}

function attributes_changed($current_attributes, $new_attributes)
{
	if (count($current_attributes) !== count($new_attributes)) {
		return true;
	}

	foreach ($new_attributes as $new_attr) {
		$matched = false;

		foreach ($current_attributes as $curr_attr) {
			$new_name = strtolower($new_attr['name'] ?? '');
			$curr_name = strtolower($curr_attr->name ?? '');

			if ($new_name === $curr_name || ($new_attr['id'] ?? null) === ($curr_attr->id ?? null)) {
				$curr_options = array_map('strval', $curr_attr->options ?? []);
				$new_options = array_map('strval', $new_attr['options'] ?? []);

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
function variation_attributes_changed($current, $new)
{
	if (count($current) !== count($new))
		return true;

	foreach ($new as $index => $new_attr) {
		$curr_attr = $current[$index] ?? null;
		if (!$curr_attr)
			return true;

		$same_id = ($new_attr['id'] ?? null) === ($curr_attr->id ?? null);
		$same_name = strcasecmp($new_attr['name'] ?? '', $curr_attr->name ?? '') === 0;
		$same_option = ($new_attr['option'] ?? null) === ($curr_attr->option ?? null);

		if ((!$same_id && !$same_name) || !$same_option) {
			return true;
		}
	}

	return false;
}

function extract_attributes_from_spec_and_variation($xml, $type, $primary_cat_name, &$attribute_cache, &$term_cache)
{
	$attributes = [];
	$variation_attributes_map = [];

	// 1. Specs -> Spec
	if (isset($xml->Specs->Spec)) {
		foreach ($xml->Specs->Spec as $spec) {
			$raw_name = (string) $spec->Name;
			$value = (string) $spec->Value;
			if (trim($raw_name) === '' || trim($value) === '')
				continue;

			$mapped_name = map_attribute_name($raw_name, $primary_cat_name);
			$attribute_obj = get_or_create_attribute_cached($mapped_name, $attribute_cache, $GLOBALS['batch_create_attributes']);
			if (!$attribute_obj)
				continue;

			$attribute_id = $attribute_obj->id;
			$values = array_map('trim', explode(',', $value));
			$options = [];

			foreach ($values as $val) {
				if ($val === '')
					continue;

				$term = get_or_create_term_cached($attribute_id, $val, $term_cache, $GLOBALS['batch_create_terms']);
				if ($term) {
					$options[] = $term->name;
				}
			}

			if (!empty($options)) {
				$attributes[] = [
					'id' => $attribute_id,
					'variation' => false,
					'visible' => true,
					'options' => $options
				];
			}
		}
	}

	// 2. Attributes uit 1e variatie (simple/bundle)
	if ($type === 'simple' || $type === 'bundle') {
		$variation = $xml->ProductVariations->ProductVariation ?? null;
		if ($variation && isset($variation->Attributes->Attribute)) {
			foreach ($variation->Attributes->Attribute as $attr) {
				$raw_name = (string) $attr->Name;
				$value = (string) $attr->Value;
				if (trim($raw_name) === '' || trim($value) === '')
					continue;

				$mapped_name = map_attribute_name($raw_name, $primary_cat_name);
				$attribute_obj = get_or_create_attribute_cached($mapped_name, $attribute_cache, $GLOBALS['batch_create_attributes']);
				if (!$attribute_obj)
					continue;

				$attribute_id = $attribute_obj->id;
				$values = array_map('trim', explode(',', $value));
				$options = [];

				foreach ($values as $val) {
					if ($val === '')
						continue;

					$term = get_or_create_term_cached($attribute_id, $val, $term_cache, $GLOBALS['batch_create_terms']);
					if ($term) {
						$options[] = $term->name;
					}
				}

				if (!empty($options)) {
					$attributes[] = [
						'id' => $attribute_id,
						'variation' => false,
						'visible' => true,
						'options' => $options
					];
				}
			}
		}
	}

	// 2b. Attributes uit ProductVariation->Attributes
	if (isset($xml->ProductVariations->ProductVariation)) {

		$parent_attribute_values = [];

		foreach ($xml->ProductVariations->ProductVariation as $variation) {

			if (!isset($variation->Attributes->Attribute)) {
				continue;
			}

			foreach ($variation->Attributes->Attribute as $attr) {

				$raw_name = trim((string) $attr->Name);
				$option   = trim((string) $attr->Value);

				if ($raw_name === '' || $option === '') {
					continue;
				}

				$mapped_name = map_attribute_name($raw_name, $primary_cat_name);

				$attribute_obj = get_or_create_attribute_cached(
					$mapped_name,
					$attribute_cache,
					$GLOBALS['batch_create_attributes']
				);

				if (!$attribute_obj || empty($attribute_obj->id)) {
					continue;
				}

				$attr_id = $attribute_obj->id;

				/** 1️⃣ Variatie-attribuut (identiek aan variable) */
				if (!isset($variation_attributes_map[$attr_id])) {
					$variation_attributes_map[$attr_id] = [
						'id'        => $attr_id,
						'variation' => true,
						'visible'   => true,
						'options'   => []
					];
				}

				if (!in_array($option, $variation_attributes_map[$attr_id]['options'], true)) {
					$variation_attributes_map[$attr_id]['options'][] = $option;
				}

				/** 2️⃣ Parent-attribuut */
				$parent_attribute_values[$attr_id][] = $option;
			}
		}

		/** 3️⃣ Parent attributes vullen (zelfde patroon als specs) */
		foreach ($parent_attribute_values as $attribute_id => $values) {

			$options = [];

			foreach (array_unique($values) as $val) {
				if ($val === '') continue;

				$term = get_or_create_term_cached(
					$attribute_id,
					$val,
					$term_cache,
					$GLOBALS['batch_create_terms']
				);

				if ($term) {
					$options[] = $term->name;
				}
			}

			if (!empty($options)) {
				$attributes[] = [
					'id'        => $attribute_id,
					'variation' => false,
					'visible'   => true,
					'options'   => $options
				];
			}
		}
	}

	// 3. Variation-attributen verzamelen voor variable hoofdproduct
	if ($type === 'variable') {
		foreach ($xml->ProductVariations->ProductVariation as $variation) {
			if ((string) $variation->IsDeleted === 'true')
				continue;

			$attr_set = build_variation_attributes_from_xml(
				$variation,
				$primary_cat_name,
				$attribute_cache,
				$term_cache,
				$GLOBALS['batch_create_attributes'],
				$GLOBALS['batch_create_terms']
			);

			foreach ($attr_set as $attr) {
				$attr_id = $attr['id'];
				$option = $attr['option'];

				if (!isset($variation_attributes_map[$attr_id])) {
					$variation_attributes_map[$attr_id] = [
						'id' => $attr_id,
						'variation' => true,
						'visible' => true,
						'options' => []
					];
				}

				if (!in_array($option, $variation_attributes_map[$attr_id]['options'], true)) {
					$variation_attributes_map[$attr_id]['options'][] = $option;
				}
			}
		}
	}

	return [
		'attributes' => $attributes,
		'variation_attributes' => array_values(array_filter(
			$variation_attributes_map,
			fn($attr) => count($attr['options']) > 1
		))
	];
}

/**
 * Verwijdert alle ProductVariation nodes met IsDeleted == True uit een SimpleXMLElement ProductVariations node.
 *
 * @param SimpleXMLElement $xml
 * @return void
 */
function clean_deleted_variations(SimpleXMLElement &$xml): void
{
	if (!isset($xml->ProductVariations) || !isset($xml->ProductVariations->ProductVariation)) {
		return;
	}

	$validVariations = [];

	foreach ($xml->ProductVariations->ProductVariation as $variation) {
		// Als IsDeleted ontbreekt of 'false' is -> behouden
		if (!isset($variation->IsDeleted) || strtolower((string) $variation->IsDeleted) === 'false') {
			$validVariations[] = $variation->asXML();
		}
	}

	// Verwijder alle bestaande variaties
	unset($xml->ProductVariations->ProductVariation);

	// Voeg geldige variaties opnieuw toe
	foreach ($validVariations as $variationXml) {
		$variation = new SimpleXMLElement($variationXml);
		$domProductVariations = dom_import_simplexml($xml->ProductVariations);
		$domVariation = dom_import_simplexml($variation);
		$domProductVariations->appendChild($domProductVariations->ownerDocument->importNode($domVariation, true));
	}
}

function flush_product_batches($woocommerce, &$product_map, &$create, &$update, &$delete)
{
	global $total_created, $total_updated, $total_deleted;

	$total_created += count($create);
	$total_updated += count($update);
	$total_deleted += count($delete);

	$batch_types = ['create' => $create, 'update' => $update, 'delete' => $delete];

	foreach ($batch_types as $type => $items) {
		if (empty($items))
			continue;

		// 🔴 Stap 1: afbeeldingen verwijderen vóór batch delete
		if ($type === 'delete') {
			foreach ($items as $item) {
				$product_id = $item['id'];
				try {
					$product = $woocommerce->get("products/{$product_id}");

					if (!empty($product->images)) {
						foreach ($product->images as $img) {
							if (isset($img->id)) {
								delete_media_item($img->id);
							}
						}
					}
				} catch (Exception $e) {
					log_message(['ERROR', 'GET'], "Failed to fetch product {$product_id} for image cleanup: " . $e->getMessage());
				}
			}
		}

		$chunks = array_chunk($items, 50);
		$total = count($chunks);
		log_message('info', ucfirst($type) . " Payload Total: {$total}");

		$i = 1;
		foreach ($chunks as $chunk) {
			if ($type === 'update') {
				// Bundelvelden eruit strippen
				$clean_chunk = [];
				foreach ($chunk as $item) {
					$copy = $item;
					unset($copy['bundled_items_delete'], $copy['bundled_items_add']);
					$clean_chunk[] = $copy;
				}
				$payload = [$type => $clean_chunk];
			} else {
				$payload = [$type => $chunk];
			}

			//log_message('DEBUG', json_encode($payload));
			try {
				$response = $woocommerce->post('products/batch', $payload);

				// Update product_map (zelfde als bij jou nu)
				if (isset($response->create)) {
					foreach ($response->create as $created) {
						$guid = $created->EcommerceProductGuid;
						if ($guid) {
							$GLOBALS['product_map'][$guid] = $created;
						}
					}
				}

				/**
				 * ✅ EXTRA: Post-create image update
				 * Omdat images bij create soms niet goed worden geïmporteerd, doen we direct daarna een update met alleen images.
				 */
				if ($type === 'create' && isset($response->create) && !empty($response->create)) {

					$imageUpdates = [];

					// Match op volgorde: WooCommerce retourneert create-resultaten in dezelfde volgorde als de request.
					// Gebruik index-matching zodat we niet afhankelijk zijn van EcommerceProductGuid in de response.
					foreach ($response->create as $idx => $created) {
						$newId = $created->id ?? null;
						$origItem = $chunk[$idx] ?? null;

						if (!$newId || !$origItem) {
							log_message('info', "Post-create image: geen match op index {$idx}, overgeslagen.");
							continue;
						}

						$origImages = $origItem['images'] ?? [];
						if (empty($origImages)) {
							log_message('info', "Post-create image: product ID {$newId} heeft geen afbeeldingen in payload, overgeslagen.");
							continue;
						}

						// IDs uit de originele payload — dit zijn pre-existing mediabestanden die NIET verwijderd mogen worden
						$origImageIds = array_filter(array_column($origImages, 'id'));

						// Verwijder alleen media die WooCommerce NIEUW aanmaakte tijdens de batch-create (niet de pre-existing).
						// Zo voorkomen we duplicaten zonder bestaande media te verwijderen.
						try {
							$fresh = $woocommerce->get("products/{$newId}");
							if (!empty($fresh->images)) {
								foreach ($fresh->images as $img) {
									if (!empty($img->id) && !in_array($img->id, $origImageIds)) {
										delete_media_item($img->id);
									}
								}
							}
						} catch (Exception $e) {
							log_message(['ERROR', 'GET'], "Post-create image cleanup failed for product {$newId}: " . $e->getMessage());
						}

						$imageUpdates[] = [
							'id'     => (int) $newId,
							'images' => $origImages,
						];
					}

					if (!empty($imageUpdates)) {
						$imageChunks = array_chunk($imageUpdates, 50);
						foreach ($imageChunks as $batchIdx => $imgChunk) {
							try {
								$imgResp = $woocommerce->post('products/batch', ['update' => $imgChunk]);

								if (isset($imgResp->update)) {
									foreach ($imgResp->update as $updated) {
										$ug = $updated->EcommerceProductGuid ?? null;
										if ($ug) {
											$GLOBALS['product_map'][$ug] = $updated;
										}

										// Log of afbeeldingen daadwerkelijk zijn gekoppeld
										$attachedCount = count($updated->images ?? []);
										log_message('info', "Post-create image update product {$updated->id}: {$attachedCount} afbeelding(en) gekoppeld.");
									}
								}

								log_message('info', "Post-create image update batch uitgevoerd (" . ($batchIdx + 1) . "): " . count($imgChunk) . " producten");
							} catch (Exception $e) {
								log_message('error', "Post-create image update batch mislukt: " . $e->getMessage());
							}
						}
					} else {
						log_message('info', "Post-create image update: geen producten met afbeeldingen om bij te werken.");
					}
				}

				log_message('info', "Batch {$i} ({$type}) executed: " . json_encode(array_column($chunk, 'sku')));
			} catch (Exception $e) {
				log_message('error', "Batch {$i} ({$type}) failed: " . $e->getMessage());
			}
			// 👉 Extra stap: bundelitems afhandelen NA batch update
			if ($type === 'update') {
				foreach ($chunk as $item) {
					if (empty($item['id']))
						continue;

					$has_delete = !empty($item['bundled_items_delete']) && is_array($item['bundled_items_delete']);
					$has_add = !empty($item['bundled_items_add']) && is_array($item['bundled_items_add']);

					// Sla over als er niets te wijzigen is aan de bundel
					if (!$has_delete && !$has_add)
						continue;

					try {
						if ($has_delete) {
							$woocommerce->put("products/{$item['id']}", [
								'bundled_items' => $item['bundled_items_delete']
							]);
							log_message('info', "Product {$item['id']} bundelitems verwijderd");
						}

						if ($has_add) {
							$woocommerce->put("products/{$item['id']}", [
								'bundled_items' => $item['bundled_items_add']
							]);
							log_message('info', "Product {$item['id']} bundelitems toegevoegd");
						}
					} catch (Exception $e) {
						log_message('error', "Fout bij bundelupdate voor {$item['id']}: " . $e->getMessage());
					}
				}
			}
			$i++;
		}
	}
}

function flush_variation_batch($woocommerce, $product_id, $create, $update, $delete)
{
	global $total_variations_created, $total_variations_updated, $total_variations_deleted;

	$total_variations_created += count($create);
	$total_variations_updated += count($update);
	$total_variations_deleted += count($delete);

	$payload = [];

	if (!empty($create))
		$payload['create'] = $create;
	if (!empty($update))
		$payload['update'] = $update;
	if (!empty($delete))
		$payload['delete'] = $delete;

	if (empty($payload))
		return;

	try {
		$woocommerce->post("products/{$product_id}/variations/batch", $payload);
		log_message(['INFO', 'POST'], "Variations batch for product {$product_id}: create=" . count($create) . ", update=" . count($update) . ", delete=" . count($delete));
		//log_message('info', "Variations Payload: " . json_encode($payload));
		//log_message('info', "Variations Response: " . json_encode($response));
	} catch (Exception $e) {
		log_message(['ERROR', 'POST'], "Variations batch failed for product {$product_id}: " . $e->getMessage());
	}
}

function get_active_action_price_data_from_xml($actionPricesXml)
{
	$now = new DateTime();
	$best = null;

	if (!$actionPricesXml || !isset($actionPricesXml->ActionPrice)) {
		return null;
	}

	foreach ($actionPricesXml->ActionPrice as $action) {
		$start = new DateTime((string) $action->ActionStart);
		$end = new DateTime((string) $action->ActionEnd);

		if ($now >= $start && $now <= $end) {
			$price = (float) $action->ActionPriceInc;

			if ($best === null || $price < $best['price']) {
				$best = [
					'price' => number_format($price, 2, '.', ''),
					'from' => $start->getTimestamp(),
					'to' => $end->getTimestamp()
				];
			}
		}
	}

	return $best;
}

function save_missing_stock_products($products, $file_path)
{
	if (empty($products))
		return;

	// Bestaat het bestand al?
	if (file_exists($file_path)) {
		$xml = simplexml_load_file($file_path);
	} else {
		// Nieuw XML bestand aanmaken
		$xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><StockExport xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema"><ExportInfo><ExportDateTime></ExportDateTime><Type>Periodic</Type><ExportStarted>Manual</ExportStarted></ExportInfo><Products></Products></StockExport>');
		$xml->ExportInfo->ExportDateTime = date('c'); // ISO 8601
	}

	$productsNode = $xml->Products;

	// Verzamel bestaande GUIDs om duplicaten te voorkomen
	$existing_guids = [];
	foreach ($productsNode->Product as $existing_product) {
		$existing_guids[] = (string) $existing_product->EcommerceProductGuid;
	}

	foreach ($products as $product) {
		$guid = (string) $product->EcommerceProductGuid;
		if (in_array($guid, $existing_guids)) {
			continue; // sla duplicaat over
		}

		$existing_guids[] = $guid; // voeg toe aan array om latere duplicaten te blokkeren
		$productNode = $productsNode->addChild('Product');

		foreach ($product->children() as $child) {
			$productNode->addChild($child->getName(), (string) $child);
		}
	}

	$xml->asXML($file_path);

	log_message("INFO", "Saved " . count($products) . " missing products to {$file_path}");
}

// --------------------------
// Functie: splits XML-bestanden > maxProductsPerFile
// --------------------------
function split_large_xml_files(array $files, string $dir, int $maxProductsPerFile = 500): void
{
	foreach ($files as $file) {
		if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'xml') {
			continue;
		}

		// Kleine sanity-check: bestaat en leesbaar?
		if (!is_readable($file)) {
			echo "⚠️ Niet leesbaar: {$file}\n";
			continue;
		}

		// 1) Haal root + ExportInfo met SimpleXML (klein, veilig in memory)
		$rootName = 'ProductExport';
		$nsAttrs = 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema"';
		$exportInfoXml = '';
		try {
			$sx = @simplexml_load_file($file);
			if ($sx instanceof SimpleXMLElement) {
				$exportInfoXml = $sx->ExportInfo ? $sx->ExportInfo->asXML() : '';
				// Bewaar originele namespaces als aanwezig
				$dom = dom_import_simplexml($sx)->ownerDocument;
				$rootEl = $dom->documentElement;
				$rootName = $rootEl->tagName;
				// verzamel xmlns-* attrs van het root element
				$ns = [];
				if ($rootEl->attributes) {
					foreach ($rootEl->attributes as $attr) {
						if (strpos($attr->nodeName, 'xmlns') === 0) {
							$ns[] = $attr->nodeName . '="' . $attr->nodeValue . '"';
						}
					}
				}
				if (!empty($ns)) {
					$nsAttrs = implode(' ', $ns);
				}
			}
		} catch (Throwable $e) {
			// Laat defaults staan
		}

		// 2) Stream met XMLReader en split per 500 <Product>-nodes
		$reader = new XMLReader();
		if (!$reader->open($file)) {
			echo "⚠️ Kan niet openen met XMLReader: {$file}\n";
			continue;
		}

		$partIndex = 0;
		$inProducts = false;
		$buffer = [];
		$totalCount = 0;

		// helper: schrijf een part-bestand
		$write_part = function (array $productsXml) use ($file, $dir, $rootName, $nsAttrs, $exportInfoXml, &$partIndex, $maxProductsPerFile) {
			if (empty($productsXml))
				return null;
			$partIndex++;
			$basename = pathinfo($file, PATHINFO_FILENAME);
			$out = "{$dir}/{$basename}_part_{$partIndex}.xml";

			$xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
			$xml .= "<{$rootName} {$nsAttrs}>\n";
			if (!empty($exportInfoXml)) {
				$xml .= "  " . trim($exportInfoXml) . "\n";
			}
			$xml .= "  <Products>\n";
			foreach ($productsXml as $px) {
				// Zorg dat er exact één inspringing is (optioneel)
				$xml .= "    " . trim($px) . "\n";
			}
			$xml .= "  </Products>\n";
			$xml .= "</{$rootName}>\n";

			file_put_contents($out, $xml);
			echo "  → Aangemaakt: {$out} (" . count($productsXml) . " producten)\n";
			return $out;
		};

		echo "Analyseren & splitsen: {$file}\n";

		// Lees door het document
		while ($reader->read()) {
			// Detect <Products> sectie om iets sneller te zijn (optioneel)
			if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'Products') {
				$inProducts = true;
			}

			// Elk <Product> ophalen
			if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'Product') {
				$productXml = $reader->readOuterXML();
				if ($productXml !== '') {
					$buffer[] = $productXml;
					$totalCount++;

					// Schrijf chunk wanneer vol
					if (count($buffer) >= $maxProductsPerFile) {
						$write_part($buffer);
						$buffer = [];
					}
				}
				// Skip de node die we net volledig lazen
				continue;
			}

			// Sluiting </Products> (alle resterende buffer flushen)
			if ($inProducts && $reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'Products') {
				// einde products-sectie
				$inProducts = false;
			}
		}

		// Laatste rest
		$lastCreated = null;
		if (!empty($buffer)) {
			$lastCreated = $write_part($buffer);
			$buffer = [];
		}

		$reader->close();

		// 3) Beslis: wel of niet gesplitst?
		if ($totalCount === 0) {
			echo "⚠️ Geen <Product>-nodes gevonden in: {$file}\n";
			continue;
		}

		if ($totalCount <= $maxProductsPerFile) {
			// we hebben hoogstens 1 part-bestand gemaakt -> dat is zinloos; verwijder dat part en houd origineel
			if ($partIndex === 1) {
				$basename = pathinfo($file, PATHINFO_FILENAME);
				$single = "{$dir}/{$basename}_part_1.xml";
				if (is_file($single)) {
					@unlink($single);
					echo "Niet gesplitst (slechts {$totalCount} producten) — part-bestand verwijderd, origineel behouden.\n";
				} else {
					echo "Niet gesplitst (slechts {$totalCount} producten) — origineel behouden.\n";
				}
			} else {
				echo "Niet gesplitst (slechts {$totalCount} producten) — origineel behouden.\n";
			}
			continue;
		}

		// 4) Alleen als er daadwerkelijk 2+ parts zijn: origineel verwijderen
		if ($partIndex >= 2) {
			if (@unlink($file)) {
				echo "❌ Origineel verwijderd: {$file} (totaal producten: {$totalCount}, parts: {$partIndex})\n";
			} else {
				echo "⚠️ Kon origineel niet verwijderen: {$file}\n";
			}
		} else {
			// Veiligheidsnet: als we om wat voor reden dan ook maar 1 part hebben terwijl totalCount > max,
			// verwijder niets en laat een waarschuwing zien.
			echo "⚠️ Onverwacht: slechts 1 part gemaakt bij {$totalCount} producten. Origineel NIET verwijderd.\n";
		}
	}
}


function log_message($levels, $msg)
{
	if (!is_array($levels)) {
		$levels = [strtoupper($levels)];
	}

	$level_tags = implode('] [', $levels);
	$message = date('Y-m-d H:i:s') . " [{$level_tags}] $msg\r\n";

	echo $message;
	error_log($message, 3, IMPORT_ERROR_LOG);
}