<?php
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Automattic\WooCommerce\Client;

include_once __DIR__.'/includes/functions.php';
include_once __DIR__.'/includes/sanitization.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();


const IMPORT_ERROR_LOG = dirname(__DIR__) . '/vendit.log';
// Custom Dotenv Boolean Support
if (! function_exists('evalBool')) {
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

// Multi-functional fetch function
function fetch_wordpress_data($endpoint, $params = []) {
    // Base URL for the WordPress REST API
    $api_url = rtrim($_ENV['SiteURL'], '/') . '/wp-json/wp/v2/' . $endpoint;
    // Initialize cURL
    $ch = curl_init();
    // Set up the full URL with query parameters
    $url = $api_url . '?' . http_build_query($params);
    curl_setopt($ch, CURLOPT_URL, $url);
    // Use Basic Authentication with consumer key and secret
    curl_setopt($ch, CURLOPT_USERPWD, $_ENV['wp_user'] . ':' . $_ENV['wp_secret']);
    // Return response as a string
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Execute the request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Get the HTTP response code
    curl_close($ch);

    // Check if the response is valid and the status code is 2xx (successful)
    if ($http_code >= 200 && $http_code < 300) {
        // Decode the JSON response
        $data = json_decode($response, true);
        
        // Return the data if available
        return $data;
    } else {
        // Handle errors (non-2xx response code)
        return [
            'error' => true,
            'message' => 'Request failed with HTTP code ' . $http_code,
            'response' => $response
        ];
    }
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

function get_brand($woocommerce, $brand){
	$params = [
		'search' => $brand
	];

	//Send get Request
	try {
		$response = $woocommerce->get('products/brands', $params);
		//evalBool($_ENV['DEBUG']) && error_log("[DEBUG][GET] WooCommerce API Response: " . json_encode($response, JSON_PRETTY_PRINT));
		return $response;
	} catch (Exception $e) {
		error_log("[ERROR][GET] API Request Failed: " . $e->getMessage());
	}
	return false;
}

function create_brand($woocommerce, $brand){
	//print_r($xml);
	$data = array_filter([
		'name' => !empty($brand) ? sanitize_text($brand) : ''
	], function($value) {
		return $value !== '' && $value !== null;
	});

	//Send create request
	try {
        $response = $woocommerce->post('products/brands', $data);
        evalBool($_ENV['DEBUG']) && error_log("[DEBUG][POST] WooCommerce API Response: " . json_encode($response, JSON_PRETTY_PRINT));
		return $response;
    } catch (Exception $e) {
        error_log("[ERROR][POST] API Request Failed: " . $e->getMessage());
    }
	return false;
}

function url_origin($s = [], $use_forwarded_host = false) {
    // Fallback base URL for CLI
    $cli_base_url = $_ENV['ImportURL'];

    // If run from CLI or $_SERVER not properly set
    if (php_sapi_name() === 'cli' || empty($s['HTTP_HOST'])) {
        return $cli_base_url;
    }

    $ssl      = ( ! empty( $s['HTTPS'] ) && $s['HTTPS'] == 'on' );
    $sp       = strtolower( $s['SERVER_PROTOCOL'] );
    $protocol = substr( $sp, 0, strpos( $sp, '/' ) ) . ( $ssl ? 's' : '' );
    $port     = $s['SERVER_PORT'];
    $port     = ( ( ! $ssl && $port == '80' ) || ( $ssl && $port == '443' ) ) ? '' : ':' . $port;
    $host     = $use_forwarded_host && isset( $s['HTTP_X_FORWARDED_HOST'] )
        ? $s['HTTP_X_FORWARDED_HOST']
        : ( $s['HTTP_HOST'] ?? $s['SERVER_NAME'] ?? null );

    $host = $host ?? 'localhost';
    return $protocol . '://' . $host . $port;
}

?>