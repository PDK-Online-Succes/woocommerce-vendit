<?php
if (file_exists(__DIR__ . '/import/Groups.xml')) {
	copy(__DIR__ . '/import/Groups.xml', __DIR__ . '/tmp/groups/Groups_' . date('Y-m-d_H-i-s') . '.xml');
	unlink(__DIR__ . '/import/Groups.xml');

	$script = __DIR__ . '/import_groups.php';
	$running = trim(shell_exec("pgrep -f " . escapeshellarg($script)));

	if ($running) {
		error_log("Proces voor {$script} draait al. Trigger overgeslagen.");
	} else {
		$cmd = 'php ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
		exec($cmd);
	}
}
