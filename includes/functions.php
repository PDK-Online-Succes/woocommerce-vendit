<?php
function recursive_scan_dir($path) {
    $files = [];
    $entries = scandir($path);
    foreach ($entries as $entry) {
		$dotDB = explode (".", $entry);
		$dotDB = end($dotDB);
		//Remove ., .., .DS_Store, ._.DS_Store, ._, db, url
    if ($entry != "." && $entry != "..") { 
            if (is_dir($path . DIRECTORY_SEPARATOR . $entry)) {
				chmod($path . DIRECTORY_SEPARATOR . $entry, 0775);
                $files[$entry] = recursive_scan_dir($path . DIRECTORY_SEPARATOR . $entry);
            } else {
				if ($entry == ".DS_Store" || $entry == "._.DS_Store" || $entry == "Thumbs.db" || substr($entry, 0,2)=="._" || $dotDB == "db" || $dotDB == "url" ) {
					unlink($path . DIRECTORY_SEPARATOR . $entry); 
					//echo $entry." Removed\r\n";
				} else {
					$files[] = (string)$path.DIRECTORY_SEPARATOR.$entry;
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
 */function get_products_by_guids_batch($woocommerce, array $guids) {
    if (empty($guids)) return [];

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
			log_message(['error','get'], "Batch get_product_by_guid failed: " . $e->getMessage());
        }
    }
	//error_log("Batch fetched products: " . json_encode($results, JSON_PRETTY_PRINT), 3, IMPORT_ERROR_LOG);
    return $results;
}

function get_variations_by_product_guid_batch($woocommerce, $product_id) {
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

function get_categories_by_group_guids($woocommerce, $guids = []) {
    if (empty($guids)) return [];

    $params = [
        'group_guid' => $guids,
        'per_page' => 100,
    ];

    try {
        $response = $woocommerce->get('products/categories', $params);
		evalBool($_ENV['DEBUG']) && log_message(['info','get'], "Batch Category Lookup: " . json_encode($response, JSON_PRETTY_PRINT));
        // Filter naar unieke group_guid
        $filtered = [];
        foreach ($response as $cat) {
            $guid = $cat->GroupGuid ?? $cat->group_guid ?? null;
            if (!$guid) continue;

            // Bewaar de eerste die we tegenkomen, of kies op basis van voorwaarden
            if (!isset($filtered[$guid]) || $cat->parent > 0) {
                $filtered[$guid] = $cat;
            }
        }

        return array_values($filtered);
    } catch (Exception $e) {
		log_message(['error','get'], "Batch category fetch failed: " . $e->getMessage());

    }

    return [];
}

// Multi-functional fetch function
function fetch_wordpress_data($endpoint, $params = []) {
    // Base URL for the WordPress REST API
    $api_url = rtrim($_ENV['SiteURL'], '/') . '/wp-json/wp/v2/' . $endpoint;
    // Initialize cURL
    $ch = curl_init();
    // Set up the full URL with query parameters
    $url = $api_url . '?' . http_build_query($params);
    curl_setopt($ch, CURLOPT_URL, $url);
    // Use Basic Authentication with consumer key and secret
    curl_setopt($ch, CURLOPT_USERPWD, $_ENV['wp_user'] . ':' . $_ENV['wp_secret']);
    // Return response as a string
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Execute the request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Get the HTTP response code
    curl_close($ch);

    // Check if the response is valid and the status code is 2xx (successful)
    if ($http_code >= 200 && $http_code < 300) {
        // Decode the JSON response
        $data = json_decode($response, true);
        
        // Return the data if available
        return $data;
    } else {
        // Handle errors (non-2xx response code)
        return [
            'error' => true,
            'message' => 'Request failed with HTTP code ' . $http_code,
            'response' => $response
        ];
    }
}

function url_origin($s = [], $use_forwarded_host = false) {
    // Fallback base URL for CLI
    $cli_base_url = $_ENV['ImportURL'];

    // If run from CLI or $_SERVER not properly set
    if (php_sapi_name() === 'cli' || empty($s['HTTP_HOST'])) {
        return $cli_base_url;
    }

    $ssl      = ( ! empty( $s['HTTPS'] ) && $s['HTTPS'] == 'on' );
    $sp       = strtolower( $s['SERVER_PROTOCOL'] );
    $protocol = substr( $sp, 0, strpos( $sp, '/' ) ) . ( $ssl ? 's' : '' );
    $port     = $s['SERVER_PORT'];
    $port     = ( ( ! $ssl && $port == '80' ) || ( $ssl && $port == '443' ) ) ? '' : ':' . $port;
    $host     = $use_forwarded_host && isset( $s['HTTP_X_FORWARDED_HOST'] )
        ? $s['HTTP_X_FORWARDED_HOST']
        : ( $s['HTTP_HOST'] ?? $s['SERVER_NAME'] ?? null );

    $host = $host ?? 'localhost';
    return $protocol . '://' . $host . $port;
}


// ✅ Cachinglaag met volledige paginatie en batch-wachtrijen voor importscript, inclusief batch splitsing bij >100

// Batch queues
$batch_create_brands = [];
$batch_create_attributes = [];
$batch_create_terms = [];

function build_product_cache_by_guid($woocommerce, array $guids) {
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
function build_brand_cache($woocommerce) {
    $cache = [];
    $page = 1;
    do {
        try {
            $response = $woocommerce->get('products/brands', [
                'per_page' => 100,
                'page' => $page
            ]);
            foreach ($response as $brand) {
                $cache[strtolower($brand->name)] = $brand;
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

function build_attribute_cache($woocommerce) {
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

function build_term_cache($woocommerce, $attribute_cache) {
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

function build_category_cache($woocommerce) {
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
function queue_create_brand($name, &$queue) {
    $slug = sanitize_slug($name);
    $queue[$slug] = ['name' => $name, 'slug' => $slug];
}

function queue_create_attribute($name, &$queue) {
    $slug = 'pa_' . sanitize_slug($name);
    $queue[$slug] = [
        'name' => $name,
        'slug' => $slug,
        'type' => 'select',
        'order_by' => 'menu_order',
        'has_archives' => true
    ];
}

function queue_create_term($attr_id, $term_name, &$queue) {
    $slug = sanitize_slug($term_name);
    $queue[$attr_id][$slug] = ['name' => $term_name, 'slug' => $slug];
}

// Batch flushers (in sets van 100)
function flush_create_brands($woocommerce, &$queue) {
    if (empty($queue)) return;
    $chunks = array_chunk(array_values($queue), 100);
    foreach ($chunks as $chunk) {
        try {
            $woocommerce->post('products/brands/batch', ['create' => $chunk]);
			log_message('info', "Created batch of " . count($chunk) . " brands");
        } catch (Exception $e) {
			log_message('error', "Brand batch failed: " . $e->getMessage() );
        }
    }
    $queue = [];
}

function flush_create_attributes($woocommerce, &$queue) {
    if (empty($queue)) return;
    $chunks = array_chunk(array_values($queue), 100);
    foreach ($chunks as $chunk) {
        try {
            $woocommerce->post('products/attributes/batch', ['create' => $chunk]);
            log_message('info', "Created batch of " . count($chunk) . " attributes");
        } catch (Exception $e) {
			log_message('error',"Attribute batch failed: " . $e->getMessage());
        }
    }
    $queue = [];
}

function flush_create_terms($woocommerce, &$queue) {
    foreach ($queue as $attr_id => $terms) {
        if (empty($terms)) continue;
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

// Herschreven helpers
function get_brand_cached($brand_name, &$brand_cache, &$batch_create_brands) {
    $key = strtolower($brand_name);
    if (isset($brand_cache[$key])) return $brand_cache[$key];
    queue_create_brand($brand_name, $batch_create_brands);
    return null;
}

function get_or_create_attribute_cached($name, &$attribute_cache, &$batch_create_attributes) {
    $key = strtolower($name);
    if (isset($attribute_cache[$key])) return $attribute_cache[$key];
    queue_create_attribute($name, $batch_create_attributes);
    return null;
}

function get_or_create_term_cached($attr_id, $term_name, &$term_cache, &$batch_create_terms) {
    $key = strtolower($term_name);
    if (isset($term_cache[$attr_id][$key])) return $term_cache[$attr_id][$key];
    queue_create_term($attr_id, $term_name, $batch_create_terms);
    return null;
}
function map_attribute_name($original_name, $primary_category_name) {
    $name = strtolower(trim($original_name));
	//log_message('CAT', json_encode($primary_category_name, JSON_PRETTY_PRINT));
    $category = strtolower(trim($primary_category_name));

    // Define attribute renaming rules
    $attribute_map = [
        //'color' => 'Kleur',
		'color' => [
			'luchtbuks' => 'Joule',
			'luchtbuks / geweer' => 'Joule',
			'luchtdrukpistool' => 'Joule',
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
        return $attribute_map[$name];
    }

    // Category-based mapping (e.g. size)
    if (isset($attribute_map[$name]) && is_array($attribute_map[$name])) {
        if (isset($attribute_map[$name][$category])) {
            return $attribute_map[$name][$category];
        }

        // ❗ Default fallback for "size" if category not mapped
        if ($name === 'size')  return 'Maat';
		if ($name === 'color') return 'Kleur';
    }

    // No match? Keep the original name
    return $original_name;
}

function get_images($xml) {
    $filename = $xml->__toString();
    $local_path = __DIR__ . '/import/images/' . $filename;

    // Probeer eerst media match
    $params = [
        'search' => $filename,
        'per_page' => 1
    ];
    $media = fetch_wordpress_data('media', $params);

    if (!empty($media)) {
        return [
            'id' => $media[0]['id'],
            'position' => (int)$xml['ImageOrder']
        ];
    }

    // Bestaat lokaal?
    if (file_exists($local_path)) {
        return [
            'src' => url_origin($_SERVER) . '/import/images/' . $filename,
            'position' => (int)$xml['ImageOrder']
        ];
    }

    // Geen geldige afbeelding
    return null;
}
function get_combined_images_from_xml($xml) {
    $images = [];

    if (!isset($xml->ProductVariations->ProductVariation)) {
        return $images;
    }

    foreach ($xml->ProductVariations->ProductVariation as $variation) {
        if (isset($variation->Images->Image)) {
            foreach ($variation->Images->Image as $img) {
                $image_data = get_images($img);
                if ($image_data !== null) {
                    $images[] = $image_data;
                }
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

    // Positie opnieuw zetten
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

function build_variation_attributes_from_xml($variation_xml, $primary_category_name, &$attribute_cache, &$term_cache, &$batch_create_attributes, &$batch_create_terms) {
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
    usort($attributes_raw, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

    // STEP 3: Build variation_attributes
    foreach ($attributes_raw as $attr) {
        $attribute = get_or_create_attribute_cached($attr['mapped_name'], $attribute_cache, $batch_create_attributes);
        if (!$attribute) continue;

        $attribute_id = $attribute->id;

        $split_values = array_map('trim', explode(',', $attr['value']));
        foreach ($split_values as $val) {
            if ($val === '') continue;

            $term = get_or_create_term_cached($attribute_id, $val, $term_cache, $batch_create_terms);
            if (!$term) continue;

            $variation_attributes[] = [
                'id' => $attribute_id,
                'option' => $term->name
            ];
        }
    }

    return $variation_attributes;
}

function attributes_changed($current_attributes, $new_attributes) {
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
function variation_attributes_changed($current, $new) {
    if (count($current) !== count($new)) return true;

    foreach ($new as $index => $new_attr) {
        $curr_attr = $current[$index] ?? null;
        if (!$curr_attr) return true;

        $same_id = ($new_attr['id'] ?? null) === ($curr_attr->id ?? null);
        $same_name = strcasecmp($new_attr['name'] ?? '', $curr_attr->name ?? '') === 0;
        $same_option = ($new_attr['option'] ?? null) === ($curr_attr->option ?? null);

        if ((!$same_id && !$same_name) || !$same_option) {
            return true;
        }
    }

    return false;
}

function extract_attributes_from_spec_and_variation($xml, $type, $primary_cat_name, &$attribute_cache, &$term_cache) {
    $attributes = [];
    $variation_attributes_map = [];

    // 📌 1. Specs -> Spec
    if (isset($xml->Specs->Spec)) {
        foreach ($xml->Specs->Spec as $spec) {
            $raw_name = (string)$spec->Name;
            $value = (string)$spec->Value;
            if (trim($raw_name) === '' || trim($value) === '') continue;

            $mapped_name = map_attribute_name($raw_name, $primary_cat_name);
            $attribute_obj = get_or_create_attribute_cached($mapped_name, $attribute_cache, $GLOBALS['batch_create_attributes']);
            if (!$attribute_obj) continue;

            $attribute_id = $attribute_obj->id;
            $values = array_map('trim', explode(',', $value));
            $options = [];

            foreach ($values as $val) {
                if ($val === '') continue;

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

    // 📌 2. Attributes uit 1e variatie (simple/bundle)
    if ($type === 'simple' || $type === 'bundle') {
        $variation = $xml->ProductVariations->ProductVariation ?? null;
        if ($variation && isset($variation->Attributes->Attribute)) {
            foreach ($variation->Attributes->Attribute as $attr) {
                $raw_name = (string)$attr->Name;
                $value = (string)$attr->Value;
                if (trim($raw_name) === '' || trim($value) === '') continue;

                $mapped_name = map_attribute_name($raw_name, $primary_cat_name);
                $attribute_obj = get_or_create_attribute_cached($mapped_name, $attribute_cache, $GLOBALS['batch_create_attributes']);
                if (!$attribute_obj) continue;

                $attribute_id = $attribute_obj->id;
                $values = array_map('trim', explode(',', $value));
                $options = [];

                foreach ($values as $val) {
                    if ($val === '') continue;

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

    // 📌 3. Variation-attributen verzamelen voor variable hoofdproduct
    if ($type === 'variable') {
        foreach ($xml->ProductVariations->ProductVariation as $variation) {
            if ((string)$variation->IsDeleted === 'true') continue;

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
        'variation_attributes' => array_values($variation_attributes_map)
    ];
}

/**
 * Verwijdert alle ProductVariation nodes met IsDeleted == True uit een SimpleXMLElement ProductVariations node.
 *
 * @param SimpleXMLElement $xml
 * @return void
 */
function clean_deleted_variations(SimpleXMLElement &$xml): void {
    if (!isset($xml->ProductVariations) || !isset($xml->ProductVariations->ProductVariation)) {
        return;
    }

    $validVariations = [];

    foreach ($xml->ProductVariations->ProductVariation as $variation) {
        // Als IsDeleted ontbreekt of 'false' is -> behouden
        if (!isset($variation->IsDeleted) || strtolower((string)$variation->IsDeleted) === 'false') {
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

function flush_product_batches($woocommerce, &$product_map, &$create, &$update, &$delete) {
    global $total_created, $total_updated, $total_deleted;

    $total_created += count($create);
    $total_updated += count($update);
    $total_deleted += count($delete);

    $batch_types = ['create' => $create, 'update' => $update, 'delete' => $delete];

    foreach ($batch_types as $type => $items) {
        if (empty($items)) continue;

        $chunks = array_chunk($items, 100);
        $total = count($chunks);
        log_message('info', ucfirst($type) . " Payload Total: {$total}" );

        $i = 1;
        foreach ($chunks as $chunk) {
            $payload = [$type => $chunk]; // Zorg dat je per batch alleen het juiste type meestuurt

            try {
                $response = $woocommerce->post('products/batch', $payload);

				//Update Product_map
				if (isset($response->create)) {
					foreach ($response->create as $created) {
						$guid = $created->EcommerceProductGuid;

						if ($guid) {
							$GLOBALS['product_map'][$guid] = $created;
						}
					}
				}

                log_message('info', "Batch {$i} ({$type}) executed: " . json_encode(array_column($chunk, 'sku')));
                //log_message('info', "Payload: " . json_encode($payload));
                //log_message('info', "Response: " . json_encode($response));
            } catch (Exception $e) {
                log_message('error', "Batch {$i} ({$type}) failed: " . $e->getMessage());
            }

            $i++;
        }
    }
}
// function flush_variation_batch($woocommerce, $product_id, $create, $update, $delete) {
// 	global $total_variations_created, $total_variations_updated, $total_variations_deleted;

// 	$total_variations_created += count($create);
// 	$total_variations_updated += count($update);
// 	$total_variations_deleted += count($delete);

// 	$batch_types = ['create' => $create, 'update' => $update, 'delete' => $delete];

// 	foreach ($batch_types as $type => $items) {
// 		if (empty($items)) continue;

// 		$chunks = array_chunk($items, 100);
// 		$i = 1;

// 		foreach ($chunks as $chunk) {
// 			$payload = [$type => $chunk];

// 			try {
// 				$response = $woocommerce->post("products/{$product_id}/variations/batch", $payload);
// 				log_message('info', "Variations batch {$i} ({$type}) for product {$product_id} executed: " . json_encode(array_column($chunk, 'sku')));
// 				//log_message('info', "Variations Payload: " . json_encode($payload));
//                 //log_message('info', "Variations Response: " . json_encode($response));
// 			} catch (Exception $e) {
// 				log_message('error', "Variations batch {$i} ({$type}) failed for product {$product_id}: " . $e->getMessage());
// 			}

// 			$i++;
// 		}
// 	}
// }
function flush_variation_batch($woocommerce, $product_id, $create, $update, $delete) {
	global $total_variations_created, $total_variations_updated, $total_variations_deleted;

	$total_variations_created += count($create);
	$total_variations_updated += count($update);
	$total_variations_deleted += count($delete);
	
    $payload = [];

    if (!empty($create)) $payload['create'] = $create;
    if (!empty($update)) $payload['update'] = $update;
    if (!empty($delete)) $payload['delete'] = $delete;

    if (empty($payload)) return;

    try {
        $woocommerce->post("products/{$product_id}/variations/batch", $payload);
        log_message(['INFO', 'POST'], "Variations batch for product {$product_id}: create=" . count($create) . ", update=" . count($update) . ", delete=" . count($delete));
		//log_message('info', "Variations Payload: " . json_encode($payload));
		//log_message('info', "Variations Response: " . json_encode($response));
    } catch (Exception $e) {
        log_message(['ERROR', 'POST'], "Variations batch failed for product {$product_id}: " . $e->getMessage());
    }
}

function get_active_action_price_data_from_xml($actionPricesXml) {
    $now = new DateTime();
    $best = null;

    if (!$actionPricesXml || !isset($actionPricesXml->ActionPrice)) {
        return null;
    }

    foreach ($actionPricesXml->ActionPrice as $action) {
        $start = new DateTime((string)$action->ActionStart);
        $end   = new DateTime((string)$action->ActionEnd);

        if ($now >= $start && $now <= $end) {
            $price = (float)$action->ActionPriceInc;

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

function log_message($levels, $msg) {
    if (!is_array($levels)) {
        $levels = [strtoupper($levels)];
    }

    $level_tags = implode('] [', $levels );
    $message = date('Y-m-d H:i:s') . " [{$level_tags}] $msg\r\n";

    echo $message;
    error_log($message, 3, IMPORT_ERROR_LOG);
}