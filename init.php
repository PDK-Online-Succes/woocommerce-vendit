<?php
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Automattic\WooCommerce\Client;

include_once __DIR__ . '/includes/functions.php';
include_once __DIR__ . '/includes/sanitization.php';

//$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$error_path = dirname(__DIR__);
define('IMPORT_ERROR_LOG', (string) $error_path . '/dev.vendit.log');

// Custom Dotenv Boolean Support
if (!function_exists('evalBool')) {
	function evalBool($value)
	{
		return (strcasecmp($value, 'true') ? false : true);
	}
}

$woocommerce = new Client(
	$_ENV['SiteURL'], // Your store URL
	$_ENV['Consumer_Key'], // Your consumer key
	$_ENV['Consumer_Secret'], // Your consumer secret
	[
		'wp_api' => true, // Enable the WP REST API integration
		'version' => 'wc/v3', // WooCommerce WP REST API version
		'query_string_auth' => true // Force Basic Authentication as query string true and using under HTTPS
	]
);

?>