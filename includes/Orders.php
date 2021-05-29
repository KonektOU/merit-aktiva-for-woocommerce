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

			// Maybe create or delete invoice when order status is okay
			add_action( 'woocommerce_order_status_changed', array( $this, 'schedule_order_actions' ), 20, 3 );
			add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_delete_invoice' ), 21, 3 );
			add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_create_payment' ), 22, 3 );

			// Hook into scheduled order actions
			add_action( $this->get_plugin()->get_id() . '_scheduled_order_action', array( $this, 'maybe_create_invoice' ), 20, 3 );

			// Refund orders when needed
			add_action( 'woocommerce_order_refunded', array( $this, 'refund_order' ), 20, 2 );

			// Show order item warehouse location
			add_action( 'woocommerce_after_order_itemmeta', array( $this, 'show_warehouse_name' ), 10, 2 );

			// Add order ID column
			add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_listing_columns' ) );
			add_filter( 'manage_shop_order_posts_custom_column', array( $this, 'show_order_listing_column' ), 10, 1 );

			// Allow filtering orders with/without order ID
			add_action( 'restrict_manage_posts', array( $this, 'filter_orders_by_external_order_id') , 20 );
			add_filter( 'request', array( $this, 'filter_orders_by_external_order_id_query' ) );

			// Validate products in cart
			add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_cart_products' ), 10, 2 );
		}
	}


	public function schedule_order_actions( $order_id, $order_old_status, $order_new_status ) {
		as_enqueue_async_action( $this->get_plugin()->get_id() . '_scheduled_order_action', compact( 'order_id', 'order_old_status', 'order_new_status' ), $this->get_plugin()->get_id() );
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

		$api_order = $this->get_api_order( $item->get_order() );

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


	public function get_api_order( $order ) {
		$order_invoice_id = $this->get_plugin()->get_order_meta( $order, 'invoice_id' );

		if ( $order_invoice_id ) {
			if ( false === ( $api_order = $this->get_plugin()->get_cache( 'order_' . $order->get_id() ) ) ) {
				$api_order = $this->get_api()->get_order( $order->get_id() );

				if ( ! empty( $api_order->Lines ) ) {
					$this->get_plugin()->set_cache( 'order_' . $order->get_id(), $api_order, HOUR_IN_SECONDS );
				} else {
					$api_order = null;
				}
			}

			return $api_order;
		}

		return false;
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
	 *
	 * @return void
	 */
	public function maybe_create_invoice( $order_id, $order_old_status, $order_new_status ) {

		if ( 'yes' !== $this->integration->get_option( 'invoice_sync_allowed', 'no' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if (
			( $order_new_status === $this->integration->get_option( 'invoice_sync_status', 'processing' ) && 'yes' !== $this->integration->get_option( 'invoice_sync_onhold', 'no' ) )
			 || ( 'on-hold' !== $order_old_status && $order_new_status === $this->integration->get_option( 'invoice_sync_status', 'processing' ) && 'yes' === $this->integration->get_option( 'invoice_sync_onhold', 'no' ) )
			 || ( 'on-hold' === $order_new_status && 'yes' === $this->integration->get_option( 'invoice_sync_onhold', 'no' ) ) ) {
			$this->get_api()->create_invoice( $order );
			$this->resync_order_products_stock( $order );
		} elseif ( 'refunded' === $order_new_status ) {
			//
		}
	}


	/**
	 * Delete invoice (if order status is okay)
	 *
	 * @param integer $order_id
	 * @param string $order_old_status
	 * @param string $order_new_status
	 *
	 * @return void
	 */
	public function maybe_delete_invoice( $order_id, $order_old_status, $order_new_status ) {

		if ( 'yes' !== $this->integration->get_option( 'invoice_delete_cancelled', 'no' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( $order_new_status === 'cancelled' ) {
			$external_id = $this->get_plugin()->get_order_meta( $order, 'invoice_id' );

			if ( $external_id ) {
				$this->get_api()->delete_invoice( $order_id, $external_id );

				$this->get_plugin()->add_order_note(
					$order,
					sprintf(
						__( 'Order cancelled. Deleted invoice with ID %s.', 'konekt-merit-aktiva' ),
						$external_id
					)
				);

				$this->get_plugin()->remove_order_meta( $order, [ 'invoice_id', 'customer_id' ] );
				$this->resync_order_products_stock( $order );
			}
		}
	}


	/**
	 * Create payment for COD
	 *
	 * @param integer $order_id
	 * @param string $order_old_status
	 * @param string $order_new_status
	 *
	 * @return void
	 */
	public function maybe_create_payment( $order_id, $order_old_status, $order_new_status ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! in_array( $order->get_payment_method(), [ 'cod', 'bacs', 'cheque' ] ) ) {
			return;
		}

		if ( ( 'cod' === $order->get_payment_method() && $order_new_status === 'completed' ) ||
			( in_array( $order->get_payment_method(), [ 'bacs', 'cheque' ] ) && 'processing' === $order_new_status ) ) {

			$external_id = $this->get_plugin()->get_order_meta( $order, 'invoice_id' );

			if ( $external_id ) {
				$api_order = $this->get_api_order( $order );

				if ( $api_order && empty( $api_order->Payments ) ) {
					$bank_name = null;

					if ( 'bacs' === $order->get_payment_method() ) {
						$bank_name = $this->integration->get_option( 'invoice_payment_method_name', '' );
					}

					$payment = $this->get_api()->create_payment( $api_order->Header->InvoiceNo, $api_order->Header->ReferenceNo, $api_order->Header->TotalSum, $api_order->Header->CustomerName, $bank_name );

					if ( ! empty( $payment->InvoiceId ) ) {
						$this->get_plugin()->add_order_note(
							$order,
							__( 'Created payment.', 'konekt-merit-aktiva' )
						);
					}
				}
			}
		}
	}


	public function resync_order_products_stock( $order ) {
		foreach ( $order->get_items( [ 'line_item' ] ) as $order_item ) {
			if ( is_callable( array( $order_item, 'get_product' ) ) ) {

				$product = $order_item->get_product();

				if ( ! $product ) {
					continue;
				}

				$this->integration->manually_update_product_stock_data( $product->get_id() );
			}
		}
	}


	public function refund_order( $order_id, $refund_id ) {
		$order  = wc_get_order( $order_id );
		$refund = new \WC_Order_Refund( $refund_id );

		$this->get_api()->create_invoice( $order, false, $refund );
		$this->resync_order_products_stock( $order );
	}


	public function process_order_submit_action( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		// Submit manually
		$this->maybe_create_invoice( $order->get_id(), $this->integration->get_option( 'invoice_sync_status', 'processing' ), $this->integration->get_option( 'invoice_sync_status', 'processing' ), $order );
	}


	public function add_order_listing_columns( $columns ) {
		$new_columns = [];

		foreach ( $columns as $column_name => $column_info ) {

			if ( 'order_total' === $column_name ) {
				$new_columns[ $this->integration->id ]                = __( 'Merit Aktiva', 'konekt-merit-aktiva' );
				$new_columns[ $this->integration->id . '_warehouse' ] = __( 'Warehouse', 'konekt-merit-aktiva' );
			}

			$new_columns [ $column_name ] = $column_info;

		}

		return $new_columns;
	}


	public function show_order_listing_column( $column ) {
		global $post;

		if ( $this->integration->id == $column ) {
			$order = \wc_get_order( $post->ID );

			echo $this->get_plugin()->get_order_meta( $order, 'invoice_id' );
		}
		elseif ( $this->integration->id . '_warehouse' == $column ) {
			$wc_order  = \wc_get_order( $post->ID );
			$api_order = $this->get_api_order( $wc_order );

			if ( $api_order ) {
				$warehouses = [];

				foreach ( $api_order->Lines as $location ) {
					$warehouses[] = $this->get_plugin()->attach_warehouse_title( '', $location->LocationCode );
				}

				$warehouses = array_filter( $warehouses );
				$warehouses = array_unique( $warehouses );

				echo implode( ', ', $warehouses );
			}
		}
	}


	public function validate_cart_products( $data, $errors ) {
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$product_id = $this->integration->get_wpml_original_post_id( $cart_item['data']->get_id() );
			$product    = wc_get_product( $product_id );

			if ( $product ) {
				$api_product = $this->get_api()->get_item( $product->get_sku() );

				if ( $api_product && $api_product->Type == 'Laokaup' ) {
					$product_quantities = $this->get_plugin()->attach_product_quantities_by_warehouse( [], $product );

					if ( empty( $product_quantities ) ) {
						$this->integration->manually_update_product_stock_data( $product );
						$product_quantities = $this->get_plugin()->attach_product_quantities_by_warehouse( [], $product );
					}

					if ( empty( $product_quantities ) || array_sum( wp_list_pluck( $product_quantities, 'quantity' ) ) <= 0 ) {
						$errors->add( 'out-of-stock', sprintf( __( 'Sorry, "%s" is not in stock. Please edit your cart and try again. We apologize for any inconvenience caused.', 'woocommerce' ), $product->get_name() ) );
					}
				}
			}
		}
	}


	public function filter_orders_by_external_order_id() {
		global $typenow;

		if ( 'shop_order' === $typenow ) {
			$action_name = $this->integration->id . '_order_id_filter';
			?>
			<select name="<?php echo esc_attr( $action_name ); ?>" id="dropdown_<?php echo esc_attr( $action_name ); ?>">
				<option value=""><?php esc_html_e( 'Show all orders', 'konekt-merit-aktiva' ); ?></option>

				<option value="1" <?php echo esc_attr( isset( $_GET[$action_name] ) ? selected( '1', $_GET[$action_name], false ) : '' ); ?>><?php esc_html_e( 'Merit Aktiva: Submitted', 'konekt-merit-aktiva' ) ?></option>
				<option value="0" <?php echo esc_attr( isset( $_GET[$action_name] ) ? selected( '0', $_GET[$action_name], false ) : '' ); ?>><?php esc_html_e( 'Merit Aktiva: Not submitted', 'konekt-merit-aktiva' ) ?></option>
			</select>
			<?php
		}
	}


	public function filter_orders_by_external_order_id_query( $vars ) {

		global $typenow;

		$action_name = $this->integration->id . '_order_id_filter';

		if ( 'shop_order' == $typenow && isset( $_GET[$action_name] ) && is_numeric( $_GET[$action_name] ) ) {

			$vars['meta_key'] = $this->get_plugin()->get_meta_key( 'invoice_id' );

			if ( 1 === (int) $_GET[$action_name] ) {
				$vars['meta_compare'] = '!=';
				$vars['meta_value']   = '';
			} elseif ( 0 === (int) $_GET[$action_name] ) {
				$vars['meta_compare'] = 'NOT EXISTS';
			}
		}

		return $vars;
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