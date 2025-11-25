<?php
if (file_exists(__DIR__ . '/import/Products.xml')) {
	copy(__DIR__ . '/import/Products.xml', __DIR__ . '/tmp/products/Products_' . date('Y-m-d_H-i-s') . '.xml');
	unlink(__DIR__ . '/import/Products.xml');

	$script = __DIR__ . '/import_products.php';
	$running = trim(shell_exec("pgrep -f " . escapeshellarg($script)));

	if ($running) {
		error_log("Proces voor {$script} draait nog. Trigger overgeslagen.");
	} else {
		$cmd = 'php ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
		exec($cmd);
	}
}
