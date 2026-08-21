<?php
/**
 * API Request
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva\API;

use SkyVerge\WooCommerce\PluginFramework\v6_2_4 as Framework;

defined( 'ABSPATH' ) or exit;


/**
 * Base API request object.
 *
 * @since 1.0.0
 */
class Request extends Framework\SV_WC_API_JSON_Request {


	/**
	 * Merit API id, as issued to the shop.
	 *
	 * Declared rather than assigned into existence: the framework's request
	 * base declares method, path, data and params, but knows nothing about
	 * Merit's credentials, and PHP 8.2 deprecated dynamic properties.
	 *
	 * @var string
	 */
	protected $api_id;

	/** @var string Merit API key, as issued to the shop. */
	protected $api_key;


	/**
	 * Construct API request
	 *
	 * @param string $api_id
	 * @param string $api_key
	 * @param string $method
	 * @param string $path
	 * @param array $data
	 * @param array $params
	 */
	public function __construct( $api_id, $api_key, $method, $path, $data = [], $params = [] ) {

		$this->api_id  = $api_id;
		$this->api_key = $api_key;
		$this->method  = $method;
		$this->path    = $path;
		$this->data    = $data;
		$this->params  = $params;
	}


	/**
	 * Get the request parameters.
	 *
	 * @since 1.0.0
	 * @see SV_WC_API_Request::get_params()
	 * @return array
	 */
	public function get_params() {
		$params = $this->params;

		// One timestamp, used both in the request and in what is signed. They
		// used to be two separate date() calls, so a request that happened to
		// straddle a second was signed for a moment other than the one it
		// claimed - and Merit answered api-invalidSignature, intermittently and
		// for no reason anybody could see.
		$timestamp = gmdate( 'YmdHis' );

		$params['ApiId']     = $this->api_id;
		$params['timestamp'] = $timestamp;
		$params['signature'] = $this->create_signature( $timestamp );

		return $params;
	}


	/**
	 * Sign the request, the way Merit asks for it.
	 *
	 *   dataToSign := utf8_to_bytes( string_concat( apiId, timestamp, httpBody ) )
	 *   signature  := base64( hmac_sha256( apiKey, dataToSign ) )
	 *
	 * with the timestamp as yyyyMMddHHmmss in UTC - hence gmdate() rather than
	 * date(), which answers in whatever timezone PHP has been left in.
	 *
	 * The body signed is the one this request will actually send, taken from
	 * the same method the framework sends it with. It used to be built here
	 * separately, and for a request with no payload the two disagreed: an empty
	 * payload is sent as an empty body but was signed as "[]", so every call
	 * without data - gettaxes and getbanks among them - was signed wrongly.
	 *
	 * @param string $timestamp Timestamp being sent with the request.
	 *
	 * @return string
	 */
	protected function create_signature( $timestamp ) {
		$signable = $this->api_id . $timestamp . $this->to_string();

		return base64_encode( hash_hmac( 'sha256', $signable, $this->api_key, true ) );
	}


}