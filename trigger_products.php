<?php
if (file_exists(__DIR__ . '/import/Products.xml')) {
	copy(__DIR__ . '/import/Products.xml', __DIR__ . '/tmp/products/Products_' . date('Y-m-d_H-i-s') . '.xml');
	unlink(__DIR__ . '/import/Products.xml');

	// Execute update_stock.php in the background
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/import_products.php') . ' > /dev/null 2>&1 &';
    exec($cmd);
}