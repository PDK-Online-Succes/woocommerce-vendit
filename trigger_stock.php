<?php
if (file_exists(__DIR__ . '/import/Stock.xml')) {
	copy(__DIR__ . '/import/Stock.xml', __DIR__ . '/tmp/stock/Stock_' . date('Y-m-d_H-i-s') . '.xml');
	unlink(__DIR__ . '/import/Stock.xml');

	$script = __DIR__ . '/update_stock.php';
	$running = trim(shell_exec("pgrep -f " . escapeshellarg($script)));

	if ($running) {
		error_log("Proces voor {$script} draait al. Trigger overgeslagen.");
	} else {
		$cmd = 'php ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
		exec($cmd);
	}
}
