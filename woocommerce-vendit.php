<?php
/**
 * Plugin Name:       Woocommerce Vendit 
 * Plugin URI:        https://github.com/pdk-online-succes/woocommerce-vendit
 * Description:       Woocommerce Vendit Koppeling
 * Version:           1.0.0
 * Author:            Luuk de Bresser
 * License:           MIT
  * Domain Path:       /languages
 * Text Domain:       woocommerce-vendit
 * GitHub Plugin URI: https://github.com/pdk-online-succes/woocommerce-vendit
 * GitHub Languages:  https://github.com/pdk-online-succes/woocommerce-vendit-translations
 */

/**
 * API Verbinding maken Vendit
 */

//var_dump(getToken());
function getToken(){
	$url = "https://oauth.vendit.online/api/GetToken?apiKey=hjp8bl1Sd4pZFKEZhrUDW0&username=JNxF6CIqA-BZXD4qUKN8p1&password=OCD887cVk4Lbd5Zh_pchq0";
	$curl = curl_init();
	curl_setopt( $curl, CURLOPT_URL, $url );
	curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $curl, CURLOPT_POST, true );
	curl_setopt( $curl, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' ) );
	
	$response = curl_exec( $curl );
	
	// Check HTTP status code
	if (!curl_errno($curl)) {
		switch ($http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) {
		case 200:  # OK
			break;
		default:
			echo 'Unexpected HTTP code: '. $http_code ."\n";
			echo $response ."\n";
			exit();
		}
	}

	curl_close( $curl );
	$decode = json_decode( $response, true );
	return $decode;
}



?>
