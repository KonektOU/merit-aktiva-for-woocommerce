<?php
/**
 * Orders
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva;

defined( 'ABSPATH' ) or exit;

class Orders {


	public $integration;


	public function __construct( $integration ) {
		$this->integration = $integration;

		if ( $this->integration->have_api_credentials() ) {
			// Add "Submit again to Merit Aktiva".
			add_filter( 'woocommerce_order_actions', array( $this, 'add_order_view_action' ), 90, 1 );
			add_action( 'woocommerce_order_action_wc_' . $this->get_plugin()->get_id() . '_submit_order_action', array( $this, 'process_order_submit_action' ), 90, 1 );

			// Maybe create invoice when order status is okay
			add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_create_invoice' ), 20, 4 );

			// Refund orders when needed
			add_action( 'woocommerce_order_refunded', array( $this, 'refund_order' ), 20, 2 );

			// Show order item warehouse location
			add_action( 'woocommerce_after_order_itemmeta', array( $this, 'show_warehouse_name' ), 10, 2 );
		}
	}


	/**
	 * Undocumented function
	 *
	 * @param integer $item_id
	 * @param \WC_Order_Item_Product $item
	 *
	 * @return void
	 */
	public function show_warehouse_name( $item_id, $item ) {
		if ( ! is_callable( array( $item, 'get_product' ) ) ) {
			return;
		}

		$order_invoice_id = $this->get_plugin()->get_order_meta( $item->get_order(), 'invoice_id' );

		if ( $order_invoice_id ) {
			if ( false === ( $api_order = $this->get_plugin()->get_cache( 'order_' . $item->get_order_id() ) ) ) {
				$api_order = $this->get_api()->get_order( $item->get_order_id() );

				if ( ! empty( $api_order->Lines ) ) {
					$this->get_plugin()->set_cache( 'order_' . $item->get_order_id(), $api_order, HOUR_IN_SECONDS );
				} else {
					$api_order = null;
				}
			}

			if ( $api_order ) {
				foreach ( $api_order->Lines as $order_line ) {
					if ( ! empty( $order_line->ArticleCode ) ) {
						if ( $order_line->ArticleCode == $item->get_product()->get_sku() ) {
							printf( '<div class="wc-order-item-sku"><strong>%s:</strong> %s</div>', __( 'Warehouse', 'konekt-merit-aktiva' ), $this->get_plugin()->attach_warehouse_title( '', $order_line->LocationCode ) );

							break;
						}
					}
				}
			}
		}
	}


	public function add_order_view_action( $actions ) {
		// Add custom action
		$actions['wc_' . $this->get_plugin()->get_id() . '_submit_order_action'] = __( 'Submit order to Merit Aktiva', 'konekt-merit-aktiva' );

		return $actions;
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

		if ( 'yes' !== $this->integration->get_option( 'invoice_sync_allowed', 'no' ) ) {
			return;
		}

		if ( $order_new_status === $this->integration->get_option( 'invoice_sync_status', 'processing' ) || ( 'on-hold' === $order_new_status && 'yes' === $this->integration->get_option( 'invoice_sync_onhold', 'no' ) ) ) {
			$this->get_api()->create_invoice( $order );

		} elseif ( 'refunded' === $order_new_status ) {
			//
		}
	}


	public function refund_order( $order_id, $refund_id ) {
		$order  = wc_get_order( $order_id );
		$refund = new \WC_Order_Refund( $refund_id );

		$this->get_api()->create_invoice( $order, false, $refund );
	}


	public function process_order_submit_action( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		// Submit manually
		$this->maybe_create_invoice( $order->get_id(), $this->integration->get_option( 'invoice_sync_status', 'processing' ), $this->integration->get_option( 'invoice_sync_status', 'processing' ), $order );
	}


	/**
	 * Get plugin
	 *
	 * @return Konekt\WooCommerce\Merit_Aktiva\Plugin
	 */
	protected function get_plugin() {
		return wc_konekt_woocommerce_merit_aktiva();
	}


	/**
	 * Get API connector
	 *
	 * @return Konekt\WooCommerce\Merit_Aktiva\API
	 */
	protected function get_api() {
		return $this->integration->get_api();
	}


}