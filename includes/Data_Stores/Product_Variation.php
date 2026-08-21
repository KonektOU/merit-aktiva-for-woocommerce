<?php
/**
 * Integration
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva\Data_Stores;

defined( 'ABSPATH' ) or exit;

class Product_Variation extends \WC_Product_Variation_Data_Store_CPT implements \WC_Object_Data_Store_Interface, \WC_Product_Data_Store_Interface {


	/**
	 * The store this one defers to for everything Merit knows.
	 *
	 * Declared rather than assigned into existence: PHP 8.2 deprecated dynamic
	 * properties, and 8.4 still warns on every product read.
	 *
	 * @var \Konekt\WooCommerce\Merit_Aktiva\Product_Data_Store
	 */
	protected $base;


	/**
	 * Construct
	 *
	 * @param \Konekt\WooCommerce\Merit_Aktiva\Product_Data_Store $base_store
	 */
	public function __construct( $base_store ) {
		$this->base = $base_store;
	}


	/**
	 * Read product data
	 *
	 * @param \WC_product $product
	 *
	 * @return void
	 */
	public function read( &$product ) {

		parent::read( $product );

		if ( ! empty( $product->get_sku() ) ) {

			$this->base->read( $product );
		}
	}


}