<?php
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Automattic\WooCommerce\Client;

//var_dump(dirname(__DIR__));

$dotenv = new Dotenv();
$dotenv->load(dirname(__DIR__).'/.env');

// Custom Dotenv Boolean Support
if (! function_exists('evalBool')) {
	function evalBool($value)
	{
		return (strcasecmp($value, 'true') ? false : true);
	}
}

// $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
// $dotenv->load();
//$dotenv->required('DEBUG')->isBoolean();
//$dotenv->ifPresent('DEBUG')->isBoolean();

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
//var_dump(evalBool($_ENV['DEBUG']));


function sanitize_text($text) {
    return trim(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')); // Safe alternative
}

function get_category_by_guid($woocommerce, $guid){
	$params = [
		'group_guid' => "{$guid}"
	];

	//Send get Request
	try {
        $response = $woocommerce->get('products/categories', $params);
        //evalBool($_ENV['DEBUG']) && error_log("[DEBUG][GET] WooCommerce API Response: " . json_encode($response, JSON_PRETTY_PRINT));
		return $response;
    } catch (Exception $e) {
        error_log("[ERROR][GET] API Request Failed: " . $e->getMessage());
    }
	return false;
}

?>