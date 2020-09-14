<?php
/**
 * API Response
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva\API;

use SkyVerge\WooCommerce\PluginFramework\v5_6_1 as Framework;

defined( 'ABSPATH' ) or exit;


/**
 * Base API Response object.
 *
 * @since 1.0.0
 */
class Response extends Framework\SV_WC_API_JSON_Response {


	/**
	 * Build the data object from the raw JSON.
	 *
	 * @since 4.3.0
	 * @param string $raw_response_json The raw JSON
	 */
	public function __construct( $raw_response_json ) {

		$this->raw_response_json = $raw_response_json;

		$this->response_data = json_decode( $raw_response_json );

		if ( ! is_object( $this->response_data ) ) {
			$this->response_data = json_decode( $this->response_data );
		}
	}


}