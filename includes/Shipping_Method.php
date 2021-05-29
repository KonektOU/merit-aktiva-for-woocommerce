<?php
/**
 * Shipping method
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva;

defined( 'ABSPATH' ) or exit;

class Shipping_Method extends \WC_Shipping_Method {


	public function __construct( $instance_id = 0 ) {
		$this->id                 = Plugin::SHIPPING_METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Local pickup by Merit Aktiva', 'konekt-merit-aktiva' );
		$this->method_description = __( 'Allow local pickup by warehouse. For example, customer can pick up the order', 'konekt-merit-aktiva' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->init();
	}


	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->title     = $this->get_option( 'title' );
		$this->warehouse = $this->get_option( 'warehouse' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}


	/**
	 * Init form fields.
	 */
	public function init_form_fields() {
		$this->instance_form_fields = array(
			'title' => [
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'Local pickup', 'woocommerce' ),
				'desc_tip'    => true,
			],
			'warehouse' => [
				'title'   => __( 'Warehouse', 'konekt-merit-aktiva' ),
				'type'    => 'select',
				'options' => wp_list_pluck( $this->get_integration()->get_warehouses(), 'title', 'id' ),
			],
		);
	}


	/**
	 * calculate_shipping function.
	 *
	 * @access public
	 * @param mixed $package
	 * @return void
	 */
	public function calculate_shipping( $package = [] ) {
		$needed_products = count( $package['contents'] );
		$actual_products = 0;

		foreach ( $package['contents'] as $package_product ) {
			$product_id = $package_product['variation_id'] && $package_product['variation_id'] > 0 ? $package_product['variation_id'] : $package_product['product_id'];
			$product_id = $this->get_integration()->get_wpml_original_post_id( $product_id );
			$product    = wc_get_product( $product_id );
			$warehouses = $this->get_plugin()->attach_product_quantities_by_warehouse( [], $product );

			if ( ! empty( $warehouses ) ) {
				foreach ( $warehouses as $warehouse ) {
					if ( $this->warehouse === $warehouse['location'] ) {
						if ( $warehouse['quantity'] > 0 && $package_product['quantity'] <= $warehouse['quantity'] ) {
							$actual_products++;
						}
					}
				}
			}
		}

		// Only add the rate if all of the products are available there.
		if ( $actual_products === $needed_products ) {
			$this->add_rate( [
				'label'     => $this->title,
				'cost'      => 0,
				'meta_data' => [
					'warehouse_location_id' => $this->warehouse,
				],
			] );
		}
	}


	/**
	 * Get integration
	 *
	 * @return \Konekt\WooCommerce\Merit_Aktiva\Integration
	 */
	protected function get_integration() {
		return $this->get_plugin()->get_integration();
	}


	/**
	 * Get plugin
	 *
	 * @return \Konekt\WooCommerce\Merit_Aktiva\Plugin
	 */
	protected function get_plugin() {
		return wc_konekt_woocommerce_merit_aktiva();
	}


}