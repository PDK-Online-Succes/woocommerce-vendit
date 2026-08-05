# woocommerce-vendit

Woocommerce Vendit Koppeling — plain PHP CLI scripts (no framework, no plugin) that sync a **Vendit ERP/POS** system with a WooCommerce store over the WP REST API (`wc/v3`). Comments and logs are in Dutch.

## Flow

```
Vendit drops XML → import/          →  trigger_*.php (cron/webhook)
                                        ├─ move to tmp/<type>/<timestamped>.xml
                                        ├─ skip if an auto run is already going (pgrep)
                                        └─ fork the worker in background
                                                ↓
   Groups.xml   → import_groups.php    → product categories
   Products.xml → import_products.php  → products + variations + attributes/terms/brands/images
   Stock.xml    → update_stock.php     → stock levels & prices
                                                ↓
WooCommerce orders → export_orders.php → export/orders/order-*.xml (UTF-16LE + BOM, back to Vendit)
```

## Files

| File | Role |
|---|---|
| `includes/functions.php` | The engine: WC API caches, batch queues, change detection, XML splitting, `log_message` |
| `import_products.php` | Two-phase import — pre-scan XML for new brands/attributes/terms, flush those, rebuild caches, then batch products + variations |
| `update_stock.php` | Stock in batches of 100, with a retry file for failures |
| `import_groups.php` | Categories depth-first ordered so parents exist before children |
| `export_orders.php` | Paid orders (last 30 min) → Vendit order XML |
| `includes/sanitization.php` | Slug/text/HTML cleaning |
| `init.php` | `.env` (dotenv) → WooCommerce `Client`, log path |
| `trigger_*.php` | Cron entry points: stage the XML, guard against double runs, fork the worker |

## Design decisions

- **Everything is batched.** GUID→product lookups, brand/attribute/term creation, and product writes all go through `flush_*` helpers instead of per-item API calls. Big XMLs get split at 500 products (`split_large_xml_files`).
- **Change detection before writing** — `attributes_changed()`, `images_changed()`, `variation_image_changed()` avoid pointless updates.
- **Vendit `EcommerceProductGuid` / `GroupGuid` is the join key**, stored as WC meta.
- **Caches are rebuilt mid-run** after the pre-scan flush, so newly created terms resolve.
- **Global mutable state** (`$GLOBALS['batch_create_*']`, `global $product_map`) threads through the import — the main thing that makes `import_products.php` hard to follow.

## Running manually

```bash
php import_products.php --action=manual              # reads manual/products/ instead of tmp/
php import_products.php --action=manual --options=only_variations
php import_products.php --action=manual --options=only_products
php import_groups.php  --action=manual
php update_stock.php   --action=manual               # keeps source files when DEBUG=true
```

The triggers' `grep -v manual` lets a manual run coexist with the cron one.

## Config

`.env` in the parent directory of the project root:

```
SiteURL=https://example.com
Consumer_Key=ck_...
Consumer_Secret=cs_...
DEBUG=false
```

Logs go to `../vendit.log` (`IMPORT_ERROR_LOG`).

## Install

```bash
composer install
```

Dependencies: `automattic/woocommerce`, `vlucas/phpdotenv`, `symfony/dom-crawler`, `symfony/css-selector`.
