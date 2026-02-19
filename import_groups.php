<?php
require_once __DIR__ . '/init.php';

$cached_guids = [];
$category_cache = build_category_cache($woocommerce);
$attribute_cache = build_attribute_cache($woocommerce);

$files = recursive_scan_dir('tmp/groups');
$options = getopt("a", ["action:"]);
if (isset($options['a']) && $options['a'] == 'manual' || isset($options['action']) && $options['action'] == 'manual') {
	$files = recursive_scan_dir('manual/groups');
}

$total_created = 0;
$total_updated = 0;
$total_skipped = 0;

foreach ($files as $file) {
	if (!file_exists(__DIR__ . DIRECTORY_SEPARATOR . $file))
		continue;

	$xml = simplexml_load_file(__DIR__ . DIRECTORY_SEPARATOR . $file);

	$groupedByDepth = [];
	foreach ($xml->Groups->Group as $group) {
		collect_groups_recursively($groupedByDepth, $group, 0);
	}

	ksort($groupedByDepth); // Parents first
	foreach ($groupedByDepth as $depth => $groups) {
		process_category_batches($woocommerce, $groups, $cached_guids, $attribute_cache, $total_created, $total_updated, $total_skipped);
	}

	unlink(__DIR__ . DIRECTORY_SEPARATOR . $file);
}
log_message('SUMMARY', "Created: {$total_created} | Updated: $total_updated | Skipped (missing parent): $total_skipped");

function collect_groups_recursively(&$grouped, $group, $depth = 0)
{
	$grouped[$depth][] = $group;
	if ($group->SubGroups && $group->SubGroups->Group) {
		foreach ($group->SubGroups->Group as $subgroup) {
			collect_groups_recursively($grouped, $subgroup, $depth + 1);
		}
	}
}

function process_category_batches($woocommerce, $groups, &$cached_guids, $attribute_cache, &$created, &$updated, &$skipped)
{
	$chunks = array_chunk($groups, 100);

	foreach ($chunks as $batch) {
		$payload = [];
		$updates = [];

		$guids = array_unique(array_map(fn($g) => (string) $g->GroupGuid, $batch));
		$uncachedGuids = array_diff($guids, array_keys($cached_guids));

		if (!empty($uncachedGuids)) {
			cache_categories_by_group_guids($woocommerce, $uncachedGuids, $cached_guids);
		}

		$existingMap = array_intersect_key($cached_guids, array_flip($guids));

		$parentGuids = array_unique(array_filter(array_map(fn($g) => (string) $g->Parent_Guid, $batch)));
		$uncachedParents = array_diff($parentGuids, array_keys($cached_guids));

		if (!empty($uncachedParents)) {
			cache_categories_by_group_guids($woocommerce, $uncachedParents, $cached_guids);
		}

		$parentMap = [];
		foreach ($parentGuids as $guid) {
			if (isset($cached_guids[$guid])) {
				$parentMap[$guid] = $cached_guids[$guid]->id;
			}
		}

		foreach ($batch as $group) {
			$guid = (string) $group->GroupGuid;
			$parentGuid = (string) $group->Parent_Guid;
			$parentId = 0;

			if (!empty($parentGuid) && $parentGuid !== '00000000-0000-0000-0000-000000000000') {
				if (!isset($parentMap[$parentGuid])) {
					log_message('SKIP', "Parent with GUID {$parentGuid} not found for group {$guid}. Skipping import until parent exists.");

					$skipped++;
					continue;
				}
				$parentId = $parentMap[$parentGuid];
			}

			$existing = $existingMap[$guid] ?? null;

			$data = build_category_data($group, $existing, $parentId, $attribute_cache);

			if ($existing) {
				$current = $existing;
				if (json_encode($data) !== json_encode((array) $current)) {
					$updates[] = ['id' => $current->id, 'data' => $data];
				}
			} else {
				$payload[] = $data;
			}
		}

		if (!empty($payload)) {
			try {
				$woocommerce->post('products/categories/batch', ['create' => $payload]);
				$created += count($payload);
			} catch (Exception $e) {
				error_log(date('Y-m-d H:i:s') . "[ERROR][POST-BATCH] " . $e->getMessage() . "\r\n", 3, IMPORT_ERROR_LOG);
				log_message('ERROR, POST-BATCH', $e->getMessage());
			}
		}

		foreach ($updates as $item) {
			try {
				//error_log(date('Y-m-d H:i:s') . "[DEBUG][PUT] filter_kenmerk payload: " . print_r($data['filter_kenmerk'], true)."\r\n", 3, IMPORT_ERROR_LOG);
				//log_message('DEBUG,PUT',"Categorie Payload:". json_encode($item,JSON_PRETTY_PRINT));
				$woocommerce->put("products/categories/{$item['id']}", $item['data']);
				$updated++;
			} catch (Exception $e) {
				log_message('ERROR,PUT', "[ERROR][PUT] Update failed for ID {$item['id']}: " . $e->getMessage() );
			}
		}
	}
}

function cache_categories_by_group_guids($woocommerce, $guids, &$cache)
{
	$params = [
		'group_guid' => $guids,
		'per_page' => 100,
	];

	try {
		evalBool($_ENV['DEBUG']) && error_log(date('Y-m-d H:i:s') . "[DEBUG] Requesting GroupGuids: " . implode(', ', $guids) . "\r\n", 3, IMPORT_ERROR_LOG);
		$response = $woocommerce->get('products/categories', $params);

		foreach ($response as $cat) {
			$guid = $cat->GroupGuid ?? $cat->group_guid ?? null;
			if (!$guid)
				continue;

			if (!isset($cache[$guid])) {
				$cache[$guid] = $cat;
			} else {
				log_message('WARNING',"Duplicate GroupGuid in cache set: $guid (existing ID: {$cache[$guid]->id}, new ID: {$cat->id})");
			}
		}
	} catch (Exception $e) {
		log_message( 'ERROR,GET', "Batch category fetch failed: " . $e->getMessage() );
	}
}

function build_category_data($xml, $existingMap, $parent_id = 0, $attribute_cache = [])
{
	$ignoreSEO = !empty($existing?->IgnoreVenditGroupSEO);
    $ignoreURL = !empty($existing?->IgnoreVenditGroupURL);
	$data = array_filter([
        'name' => ($ignoreSEO ? '' : sanitize_text($xml->GroupName->__toString() ?? '')),
        'slug' => ($ignoreURL ? '' : sanitize_text($xml->GroupUrlName->__toString() ?? '')),
        'parent' => $parent_id ?: 0,
        'description' => ($ignoreSEO ? '' : sanitize_html($xml->GroupDescription->__toString() ?? '')),
        'menu_order' => (int) ($xml->ItemOrder->__toString() ?? 0),
        'rank_math_title' => ($ignoreSEO ? '' : sanitize_text($xml->GroupMetaTitle->__toString() ?? '')),
        'rank_math_focus_keyword' => ($ignoreSEO ? '' : sanitize_text($xml->GroupMetaKeywords->__toString() ?? '')),
        'rank_math_description' => ($ignoreSEO ? '' : sanitize_text($xml->GroupMetaDescription->__toString() ?? '')),
        'group_guid' => sanitize_text($xml->GroupGuid->__toString() ?? '')
    ]);

	if ($xml->Specs) {
		foreach ($xml->Specs->Spec as $spec) {
			$name = strtolower($spec->Name->__toString());
			if (isset($attribute_cache[$name])) {
				$data['filter_kenmerk'][] = $attribute_cache[$name]->slug;
			}
		}
	}

	return $data;
}
