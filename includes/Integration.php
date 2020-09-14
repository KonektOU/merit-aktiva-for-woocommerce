<?php
/**
 * Integration
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva;

defined( 'ABSPATH' ) or exit;

class Integration extends \WC_Integration {


	/** @var Konekt\WooCommerce\Merit_Aktiva\API API handler instance */
	protected $api;


	/**
	 * Integration constructor
	 */
	public function __construct() {

		$this->id                 = 'merit_aktiva';
		$this->method_title       = __( 'Merit Aktiva', 'konekt-merit-aktiva' );
		$this->method_description = __( 'Supercharge your WooCommerce with Merit Aktiva integration for seamless orders data exchange.', 'konekt-merit-aktiva' );

		$this->init_form_fields();
		$this->init_settings();

		// Bind to the save action for the settings.
		add_action( 'woocommerce_update_options_integration_' . $this->id, [ $this, 'process_admin_options' ] );

		if ( $this->have_api_credentials() ) {
			if ( 'yes' === $this->get_option( 'invoice_sync_allowed', 'no' ) ) {
				add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_create_invoice' ), 20, 4 );
			}

			// Add "Submit again to Merit Aktiva".
			add_filter( 'woocommerce_order_actions', array( $this, 'add_order_view_action' ), 90, 1 );
			add_action( 'woocommerce_order_action_wc_' . $this->get_plugin()->get_id() . '_submit_order_action', array( $this, 'process_order_submit_action' ), 90, 1 );
		}
	}


	/**
	 * Set integration settings fields
	 *
	 * @return void
	 */
	public function init_form_fields() {

		$this->form_fields = [

			// API configuration
			'api_section_title' => [
				'title' => __( 'API configuration', 'konekt-merit-aktiva' ),
				'type'  => 'title',
			],

			'api_localization' => [
				'title'   => __( 'Localization', 'konekt-merit-aktiva' ),
				'type'    => 'select',
				'options' => [
					'estonian' => __( 'Estonian', 'konekt-merit-aktiva' ),
					'finnish'  => __( 'Finnish', 'konekt-merit-aktiva' ),
					'polish'   => __( 'Polish', 'konekt-merit-aktiva' ),
				],
			],

			'api_id' => [
				'title'   => __( 'API ID', 'konekt-merit-aktiva' ),
				'type'    => 'text',
				'default' => '',
			],

			'api_key' => [
				'title'   => __( 'API key', 'konekt-merit-aktiva' ),
				'type'    => 'password',
				'default' => '',
			],

			// Features
			'features_section_title' => [
				'title' => __( 'Features', 'konekt-merit-aktiva' ),
				'type'  => 'title',
			],

			'invoice_sync_allowed' => [
				'title'   => __( 'Invoices', 'konekt-merit-aktiva' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Allow sending invoices to Merit Aktiva', 'konekt-merit-aktiva' ),
			],

			'invoice_sync_status' => [
				'title'       => __( 'Order status', 'konekt-merit-aktiva' ),
				'type'        => 'select',
				'default'     => 'processing',
				'options'     => [
					'processing' => __( 'Processing', 'woocommerce' ),
					'completed'  => __( 'Completed', 'woocommerce' ),
				],
				'description' => __( 'This determines which order status is needed to be sent to Merit Aktiva', 'konekt-merit-aktiva' ),
			],

			'invoice_item_type' => [
				'title'       => __( 'Invoice item type', 'konekt-merit-aktiva' ),
				'type'        => 'select',
				'default'     => '1',
				'options'     => [
					'1' => __( 'Stock item', 'woocommerce' ),
					'2' => __( 'Service', 'woocommerce' ),
					'3' => __( 'Item', 'woocommerce' ),
				],
			],

			'invoice_shipping_sku' => [
				'title'       => __( 'Shipping SKU', 'konekt-merit-aktiva' ),
				'type'        => 'text',
				'default'     => '',
			],

			'primary_warehouse_id' => [
				'title'       => __( 'Primary warehouse ID', 'konekt-merit-aktiva' ),
				'type'        => 'text',
				'default'     => '',
			],

			'warehouses' => [
				'title'       => __( 'Warehouses', 'konekt-merit-aktiva' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'Warehouses IDs that will be used, each warehouse ID on new line.', 'konekt-merit-aktiva' ),
			],
		];

		if ( $this->have_api_credentials() ) {
			$taxes = $this->get_taxes();

			$this->form_fields['invoice_tax_id'] = [
				'title'       => __( 'Tax ID' ),
				'type'        => 'select',
				'default'     => '',
				'options'     => $taxes,
			];
		}
	}


	/**
	 * Get taxes
	 *
	 * @return void
	 */
	public function get_taxes() {
		if ( false === ( $taxes = get_transient( 'wc_' . $this->get_plugin()->get_id() . '_taxes' ) ) ) {
			$taxes = $this->get_api()->get_taxes();

			if ( ! empty( $taxes ) ) {
				$taxes = array_column( (array) $taxes, 'Code', 'Id' );
			}

			set_transient( 'wc_' . $this->get_plugin()->get_id() . '_taxes', $taxes );
		}

		return $taxes;
	}


	/**
	 * Create invoice (if order status is okay)
	 *
	 * @param itneger $order_id
	 * @param string $order_old_status
	 * @param string $order_new_status
	 * @param \WC_Order $order
	 *
	 * @return void
	 */
	public function maybe_create_invoice( $order_id, $order_old_status, $order_new_status, $order ) {

		if ( $order_new_status !== $this->get_option( 'invoice_sync_status', 'processing' ) ) {
			return;
		}

		$this->get_api()->create_invoice( $order );
	}


	/**
	 * Gets the API handler instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Konekt\WooCommerce\Merit_Aktiva\API
	 */
	public function get_api() {

		if ( null === $this->api ) {
			$this->api = new API( $this );
		}

		return $this->api;
	}


	/**
	 * Checks if API ID and key have been set
	 *
	 * @return bool
	 */
	private function have_api_credentials() {

		return $this->get_option( 'api_id' ) && $this->get_option( 'api_key' );
	}


	public function add_order_view_action( $actions ) {
		// Add custom action
		$actions['wc_' . $this->get_plugin()->get_id() . '_submit_order_action'] = __( 'Submit order to Merit Aktiva', 'konekt-merit-aktiva' );

		return $actions;
	}


	public function process_order_submit_action( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		$this->get_plugin()->log( 'submit action' );

		// Submit manually
		$this->maybe_create_invoice( $order->get_id(), $this->get_option( 'invoice_sync_status', 'processing' ), $this->get_option( 'invoice_sync_status', 'processing' ), $order );
	}


	/**
	 * Get plugin
	 *
	 * @return Konekt\WooCommerce\Merit_Aktiva\Plugin
	 */
	protected function get_plugin() {
		return wc_konekt_woocommerce_merit_aktiva();
	}


}
