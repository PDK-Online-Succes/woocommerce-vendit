<?php
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

//use Symfony\Component\Dotenv\Dotenv;
use Dotenv\Dotenv;
use Automattic\WooCommerce\Client;

//var_dump(dirname(__DIR__));

//$dotenv = new Dotenv();
//$dotenv->load(dirname(__DIR__).'/.env');

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

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
        return $data ?: false;
    } else {
        // Handle errors (non-2xx response code)
        return [
            'error' => true,
            'message' => 'Request failed with HTTP code ' . $http_code,
            'response' => $response
        ];
    }
}

if(!function_exists('convertToASCII')){
	function sanitize_slug($string) {
		// Convert to ASCII
		$string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);
		// Remove any remaining non-ASCII characters
		$string = preg_replace('/[^\x20-\x7E]/', '', $string);
		// Optionally replace special characters with underscores
		$string = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $string);
		//convert to UTF-8
		$string = iconv('ASCII', 'UTF-8//TRANSLIT//IGNORE', $string);
		// Replace multiple underscores with a single underscore
		$string = preg_replace('/_+/', '_', $string);
		return $string;
	}
}

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


$params = [
    'search' => 'woocommerce-placeholder.png',  // Search by filename
    'per_page' => 10  // Limit the number of results
];

$media = fetch_wordpress_data('media', $params);

if ($media) {
    echo "Media found:\n";
	echo "<pre>";
    print_r($media);
	echo "</pre>";	
} else {
    echo "No media found.";
}


?>