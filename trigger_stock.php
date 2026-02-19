<?php
$file = __DIR__ . '/import/Stock.xml';

if (file_exists($file)) {
	copy($file, __DIR__ . '/tmp/stock/Stock_' . date('Y-m-d_H-i-s') . '.xml');
	unlink($file);

	$checkCmd = "pgrep -f " . escapeshellarg('update_stock.php') .
		" | xargs -r ps -o cmd= -p | grep -v manual";

	$runningAuto = trim(shell_exec($checkCmd) ?? '');

	if ($runningAuto) {
		error_log("Automatisch stock proces draait al. Trigger overgeslagen.");
	} else {
		$cmd = 'php ' . escapeshellarg(__DIR__ . '/update_stock.php') . ' > /dev/null 2>&1 &';
		exec($cmd);
	}
}
