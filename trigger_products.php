<?php
$file = __DIR__ . '/import/Products.xml';

if (file_exists($file)) {
	copy($file, __DIR__ . '/tmp/products/Products_' . date('Y-m-d_H-i-s') . '.xml');
	unlink($file);

	$checkCmd = "pgrep -f " . escapeshellarg('import_products.php') .
		" | xargs -r ps -o cmd= -p | grep -v manual";

	$runningAuto = trim(shell_exec($checkCmd));

	if ($runningAuto) {
		error_log("Automatisch import proces voor products draait al. Trigger overgeslagen.");
	} else {
		$cmd = 'php ' . escapeshellarg(__DIR__ . '/import_products.php') . ' > /dev/null 2>&1 &';
		exec($cmd);
	}
}
