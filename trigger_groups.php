<?php
if (file_exists(__DIR__ . '/import/Groups.xml')) {
	copy(__DIR__ . '/import/Groups.xml', __DIR__ . '/tmp/groups/Groups_' . date('Y-m-d_H-i-s') . '.xml');
	unlink(__DIR__ . '/import/Groups.xml');
	
	// Execute import_groups.php in the background
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/import_groups.php') . ' > /dev/null 2>&1 &';
    exec($cmd);
}