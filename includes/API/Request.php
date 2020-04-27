<?php
/**
 * Merit Aktiva for WooCommerce
 *
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva\API;

use SkyVerge\WooCommerce\PluginFramework\v5_6_1 as Framework;

defined( 'ABSPATH' ) or exit;


/**
 * Base API request object.
 *
 * @since 1.0.0
 */
class Request extends Framework\SV_WC_API_JSON_Request {


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

		$params['ApiId']     = $this->api_id;
		$params['timestamp'] = date( 'YmdHis' );
		$params['signature'] = $this->create_signature( $this->get_data() );

		return $params;
	}


	protected function create_signature( $data ) {

		$signable      = $this->api_id . date( 'YmdHis' ) . wp_json_encode( $data );
		$raw_signature = hash_hmac( 'sha256', $signable, $this->api_key, true );
		$signature     = base64_encode( $raw_signature );

		return $signature;
	}


}