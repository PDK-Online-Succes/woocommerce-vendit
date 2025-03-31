<?php
if (file_exists(__DIR__ . '/import/Stock.xml')) {
	copy(__DIR__ . '/import/Stock.xml', __DIR__ . '/tmp/stock/Stock_' . date('Y-m-d_H-i-s') . '.xml');
	unlink(__DIR__ . '/import/Stock.xml');

	// Execute update_stock.php in the background
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/update_stock.php') . ' > /dev/null 2>&1 &';
    exec($cmd);
}