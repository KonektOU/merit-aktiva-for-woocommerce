<?php
/**
 * Integration
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva;

use WC_Admin_Notices;

defined( 'ABSPATH' ) or exit;

class Integration extends \WC_Integration {


	/** @var Konekt\WooCommerce\Merit_Aktiva\API API handler instance */
	protected $api = null;

	protected $orders = null;


	/**
	 * Default VAT rate ID
	 */
	const DEFAULT_ESTONIAN_TAX_ID = 'b9b25735-6a15-4d4e-8720-25b254ae3d21';


	/**
	 * Default zero tax ID
	 */
	const DEFAULT_ZERO_TAX_ID = '973a4395-665f-47a6-a5b6-5384dd24f8d0';


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
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		if ( $this->have_api_credentials() ) {
			if ( 'yes' === $this->get_option( 'stock_product_tab', 'no' ) ) {
				add_filter( 'woocommerce_product_tabs', array( $this, 'add_product_stock_tab' ) );
			}
		}

		// Custom WC query
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( $this, 'add_custom_product_query_var' ), 10, 2 );

		// Add custom coupon data
		add_action( 'woocommerce_coupon_options', array( $this, 'add_coupon_giftcard_option' ) );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_coupon_giftcard_option' ), 10, 2 );

		// Remove item_id from being duplicated
		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( $this, 'remove_product_duplication_meta' ), 10, 1 );
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

			// Invoices
			'invoices_section_title' => [
				'title' => __( 'Invoices configuration', 'konekt-merit-aktiva' ),
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

			'invoice_sync_onhold' => [
				'title'   => __( 'Sync on-hold', 'konekt-merit-aktiva' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Sync orders with on-hold status.', 'konekt-merit-aktiva' ),
			],

			'invoice_delete_cancelled' => [
				'title'   => __( 'Delete cancelled', 'konekt-merit-aktiva' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Delete invoices if order is marked as cancelled.', 'konekt-merit-aktiva' ),
			],

			'invoice_shipping_sku' => [
				'title'       => __( 'Shipping SKU', 'konekt-merit-aktiva' ),
				'type'        => 'text',
				'default'     => '',
			],

			'invoice_giftcard_sku' => [
				'title'       => __( 'Giftcard SKU', 'konekt-merit-aktiva' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'If order contains coupon with e-giftcard, then the coupon row will use this SKU.', 'konekt-merit-aktiva' ),
			],

			// Stock
			'stock_section_title' => [
				'title' => __( 'Stock management configuration', 'konekt-merit-aktiva' ),
				'type'  => 'title',
			],

			'stock_sync_allowed' => [
				'title'   => __( 'Stock management', 'konekt-merit-aktiva' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Allow syncing product stock with Merit Aktiva', 'konekt-merit-aktiva' ),
			],

			'primary_warehouse_id' => [
				'title'       => __( 'Primary warehouse ID', 'konekt-merit-aktiva' ),
				'type'        => 'text',
				'default'     => '',
			],

			'refund_warehouse_id' => [
				'title'       => __( 'Refund warehouse ID', 'konekt-merit-aktiva' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'All refunded products will be redirected there.', 'konekt-merit-aktiva' ),
			],

			'warehouses' => [
				'title'       => __( 'Warehouses', 'konekt-merit-aktiva' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'Warehouses IDs that will be used, each warehouse ID on new line. ID separated by colon, second half is title, for example 1:Main warehouse', 'konekt-merit-aktiva' ),
			],

			'stock_product_tab' => [
				'title'   => __( 'Stock location tab', 'konekt-merit-aktiva' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Adds custom product tab to show product availability by warehouse. Only with multiple warehouses.', 'konekt-merit-aktiva' ),
			],

			'validate_checkout_stock' => [
				'title'   => __( 'Check stock status on checkout', 'konekt-merit-aktiva' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Makes additional requests via API to check for latest stock status information.', 'konekt-merit-aktiva' ),
			],

			// Product
			'product_section_title' => [
				'title' => __( 'Product configuration', 'konekt-merit-aktiva' ),
				'type'  => 'title',
			],

			'product_sync_allowed' => [
				'title'   => __( 'Products', 'konekt-merit-aktiva' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Allow syncing product data with Merit Aktiva', 'konekt-merit-aktiva' ),
			],

			'product_create_allowed' => [
				'title'   => __( 'Create products', 'konekt-merit-aktiva' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Allow creating products to Merit Aktiva.', 'konekt-merit-aktiva' ),
			],

			// Advanced
			'advanced_section_title' => [
				'title' => __( 'Advanced configuration', 'konekt-merit-aktiva' ),
				'type'  => 'title',
			],

			'stock_refresh_rate' => [
				'title'       => __( 'Stock refresh rate', 'konekt-merit-aktiva' ),
				'type'        => 'number',
				'default'     => '15',
				'description' => __( 'How often (in minutes) product stock is fetched from API?', 'konekt-merit-aktiva' )
			],

			'product_refresh_rate' => [
				'title'       => __( 'Product refresh rate', 'konekt-merit-aktiva' ),
				'type'        => 'number',
				'default'     => '30',
				'description' => __( 'How often (in days) product data is fetched from API?', 'konekt-merit-aktiva' )
			],

			'sync_method' => [
				'title'   => __( 'Sync method', 'konekt-merit-aktiva' ),
				'type'    => 'select',
				'default' => 'cron',
				'options' => [
					'cron'       => __( 'Cron twice daily', 'konekt-merit-aktiva' ),
					'continuous' => __( 'Continuous', 'konekt-merit-aktiva' ),
					'relative'   => __( 'Relative', 'konekt-merit-aktiva' ),
					'on-demand'  => __( 'On demand', 'konekt-merit-aktiva' ),
				]
			],

			'save_api_messages_to_notes' => [
				'title'   => __( 'Messages', 'konekt-merit-aktiva' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Save messages from API to order notes (privately).', 'konekt-merit-aktiva' ),
			],
		];

		if ( $this->have_api_credentials() ) {

			$this->form_fields['sync_product_stock'] = [
				'title'        => __( 'Sync stock', 'konekt-merit-aktiva' ),
				'type'         => 'manual_product_sync',
				'description'  => __( 'Manual sync for product stocks', 'konekt-merit-aktiva' ),
				'nonce'        => 'sync-product-stock',
				'button_title' => __( 'Sync now', 'konekt-merit-aktiva' ),
				'callback'     => [ $this, 'manual_product_stock_sync' ],
			];

			$this->form_fields['create_products'] = [
				'title'        => __( 'Create products', 'konekt-merit-aktiva' ),
				'type'         => 'manual_product_sync',
				'description'  => __( 'Create products to Merit Aktiva that are missing', 'konekt-merit-aktiva' ),
				'nonce'        => 'create-products',
				'button_title' => __( 'Create now', 'konekt-merit-aktiva' ),
				'callback'     => [ $this, 'manual_product_sync' ],
			];

			// Taxes
			$this->form_fields['taxes_section_title'] = [
				'title' => __( 'Taxes configuration', 'konekt-merit-aktiva' ),
				'type'  => 'title',
			];

			$this->form_fields['taxes'] = [
				'title'       => __( 'Taxes', 'konekt-merit-aktiva' ),
				'type'        => 'tax_mapping_table',
				'description' => __( 'Match all WooCommerce taxes with Merit Aktiva taxes. You can find TaxId from Merit Aktiva settings.', 'konekt-merit-aktiva' ),
			];

			$this->form_fields['zero_tax_account_code'] = [
				'title'       => __( 'Zero tax account code', 'konekt-merit-aktiva' ),
				'type'        => 'text',
				'description' => __( 'Invoice rows with zero tax rate will have this account code applied. If not set, then it is not used.', 'konekt-merit-aktiva' ),
			];

			$this->form_fields['payment_methods'] = [
				'title'       => __( 'Payment methods', 'konekt-merit-aktiva' ),
				'type'        => 'payment_methods_mapping_table',
				'description' => __( 'Map WooCommerce payment methods to Merit Aktiva banks.', 'konekt-merit-aktiva' ),
			];
		}
	}


	public function init() {
		$this->schedule_cron();

		$this->orders = $this->get_orders();

		// Add shipping method
		add_filter( 'woocommerce_shipping_methods', array( $this, 'load_shipping_method' ) );
	}


	public function admin_init() {
		if ( isset( $_GET['update_source'] ) && $this->id === $_GET['update_source'] ) {
			do_action( 'konekt_merit_aktiva_update_product' );

			$product_id = sanitize_text_field( wp_unslash( $_GET['post'] ) );
			$update     = $this->manually_update_product_stock_data( $product_id );

			if ( true === $update ) {
				$this->get_plugin()->add_notice( 'product_update', __( 'Product updated.', 'konekt-merit-aktiva' ) );
			} else {
				$this->get_plugin()->add_notice( 'product_update', __( 'Product update failed.', 'konekt-merit-aktiva' ) );
			}
		}

		// Manual update handling
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'add_product_update_data_field' ) );
	}


	public function manually_update_product_stock_data( $product_id ) {
		global $wpdb;

		$_product = wc_get_product( $product_id );
		$products = [ $_product ];

		if ( $_product->is_type( 'variable' ) ) {
			foreach ( $_product->get_children() as $child ) {
				$variation = wc_get_product( $child );

				if ( $variation->get_sku() ) {
					$products[] = $variation;
				}
			}
		}

		if ( ! empty( $products ) ) {
			foreach ( $products as $product ) {
				foreach ( $this->get_warehouses() as $warehouse ) {
					$item_stock           = $this->get_api()->get_item_stock( $product->get_sku(), $warehouse['id'] );
					$product_in_warehouse = $this->get_product_from_lookup_table( $product->get_sku(), $warehouse['id'] );

					if ( ! is_object( $item_stock ) || ! property_exists( $item_stock, 'InventoryQty' ) ) {
						$this->remove_product_from_lookup_table( $product->get_sku(), $warehouse['id'] );

						continue;
					}

					$product_data = [
						'product_type'   => $item_stock->Type,
						'stock_quantity' => $item_stock->InventoryQty,
						'item_id'        => $item_stock->ItemId,
						'uom_name'       => $item_stock->UnitofMeasureName,
						'sku'            => $product->get_sku(),
						'location_code'  => $warehouse['id'],
					];

					if ( $product_in_warehouse ) {
						$this->update_product_in_lookup_table( $product->get_sku(), $warehouse['id'], $product_data );

					} else {
						$this->add_product_to_lookup_table( $product_data );
					}

					$this->update_product_stock_data( $product );
				}
			}

			return true;
		}

		return false;
	}


	/**
	 * Fetch product from lookup table
	 *
	 * @param string $product_sku
	 * @param integer $warehouse_id
	 *
	 * @return array|null
	 */
	public function get_product_from_lookup_table( $product_sku, $warehouse_id = null ) {
		global $wpdb;

		if ( empty( $product_sku ) ) {
			return null;
		}

		if ( $warehouse_id ) {
			$product = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wc_merit_aktiva_product_lookup WHERE location_code = %d AND sku = %s", $warehouse_id, $product_sku ),
				ARRAY_A
			);
		} else {
			$product = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wc_merit_aktiva_product_lookup WHERE sku = %s", $product_sku ),
				ARRAY_A
			);
		}

		return $product;
	}


	public function get_product_uom_from_lookup_table( $product_sku ) {
		global $wpdb;

		if ( empty( $product_sku ) ) {
			return null;
		}

		$product_uom = $wpdb->get_var(
			$wpdb->prepare( "SELECT uom_name FROM {$wpdb->prefix}wc_merit_aktiva_product_lookup WHERE sku = %s LIMIT 1", $product_sku ),
			ARRAY_A
		);

		return $product_uom;
	}


	/**
	 * Update product in lookup table
	 *
	 * @param string $product_sku
	 * @param integer $warehouse_id
	 * @param array $data
	 *
	 * @return void
	 */
	public function update_product_in_lookup_table( $product_sku, $warehouse_id, $data ) {
		global $wpdb;

		$wpdb->update(
			"{$wpdb->prefix}wc_merit_aktiva_product_lookup",
			$data,
			[
				'location_code' => $warehouse_id,
				'sku'           => $product_sku,
			]
		);
	}


	/**
	 * Add product to lookup table
	 *
	 * @param string $product_sku
	 * @param array $data
	 *
	 * @return void
	 */
	public function add_product_to_lookup_table( $data ) {
		global $wpdb;

		$wpdb->insert(
			"{$wpdb->prefix}wc_merit_aktiva_product_lookup",
			$data,
		);
	}


	/**
	 * Removes product from lookup table
	 *
	 * @param string $product_sku
	 * @param integer $warehouse_id
	 *
	 * @return void
	 */
	public function remove_product_from_lookup_table( $product_sku, $warehouse_id ) {
		global $wpdb;

		$wpdb->delete(
			"{$wpdb->prefix}wc_merit_aktiva_product_lookup",
			[
				'location_code' => $warehouse_id,
				'sku'           => $product_sku,
			]
		);
	}


	public function update_product_stock_data( $product ) {
		$warehouses     = $this->get_warehouses();
		$total_quantity = 0;

		foreach ( $warehouses as $warehouse ) {

			$item_stock = $this->get_product_from_lookup_table( $product->get_sku(), $warehouse['id'] );

			if ( empty( $item_stock ) ) {
				$api_stock = $this->get_api()->get_item_stock( $product->get_sku(), $warehouse['id'] );

				if ( ! $api_stock ) {
					$this->get_plugin()->remove_product_meta( $product, [ 'item_id' ] );

					if ( $product->is_type( 'variable' ) ) {
						if ( ! $product->managing_stock() && $product->get_stock_status() == 'outofstock' ) {
							$product->set_stock_status( 'instock' );
						}
					}

					continue;
				} else {
					$item_stock = [
						'product_type'   => $api_stock->Type,
						'stock_quantity' => $api_stock->InventoryQty,
						'item_id'        => $api_stock->ItemId,
						'uom_name'       => $api_stock->UnitofMeasureName,
						'sku'            => $product->get_sku(),
						'location_code'  => $warehouse['id'],
					];

					$this->add_product_to_lookup_table( $item_stock );
				}
			}

			if ( $item_stock ) {
				if ( 'Laokaup' != $item_stock['product_type'] ) {
					$product->set_manage_stock( false );
					$product->set_stock_status( 'instock' );

					break;
				}

				// Manage stock
				if ( $product->is_type( 'variable' ) ) {
					$product->set_manage_stock( false );
					$product->set_stock_status( 'instock' );
				} else {
					$product->set_manage_stock( true );
				}

				// Save item ID
				$this->get_plugin()->add_product_meta( $product, 'item_id', $item_stock['item_id'] );

				if ( $item_stock['stock_quantity'] ) {
					$total_quantity += (int) $item_stock['stock_quantity'];
				}
			}
		}

		if ( $product->managing_stock() ) {

			if ( $total_quantity != $product->get_stock_quantity() ) {
				$product->set_stock_quantity( $total_quantity );
			}

			if ( $total_quantity > 0 && 'instock' !== $product->get_stock_status() ) {
				$product->set_stock_status( 'instock' );
			}

		}

		if ( ! empty( $product->get_changes() ) ) {
			$product->save();
		}
	}


	/**
	 * Load shipping method
	 *
	 * @param array $methods
	 *
	 * @since 1.0.2
	 *
	 * @return array
	 */
	public function load_shipping_method( $methods = [] ) {

		if ( empty( $this->get_warehouses() ) ) {
			return $methods;
		}

		if ( ! class_exists( $this->get_plugin()::SHIPPING_CLASS ) ) {
			require_once( $this->get_plugin()->get_plugin_path() . '/includes/Shipping_Method.php' );
		}

		$methods[ $this->get_plugin()::SHIPPING_METHOD_ID ] = $this->get_plugin()::SHIPPING_CLASS;

		return $methods;
	}

	public function is_continuous_sync_method() {

		return 'continuous' === $this->get_option( 'sync_method', 'on-demand' );
	}


	public function schedule_cron() {

		if ( 'cron' === $this->get_option( 'sync_method', 'on-demand' ) ) {
			foreach ( $this->get_warehouses() as $key => $warehouse ) {
				$this->get_plugin()->schedule_action( 'cron_job', [ $warehouse, false ], DAY_IN_SECONDS / 2, time() + ( DAY_IN_SECONDS / 2 ) + ( ( HOUR_IN_SECONDS * $key ) + 1 ) );

				if ( wp_next_scheduled( 'konekt_merit_aktiva_cron_job', [ $warehouse ] ) ) {
					wp_clear_scheduled_hook( 'konekt_merit_aktiva_cron_job', [ $warehouse ] );
				}
			}
		}
		elseif ( $this->is_continuous_sync_method() ) {
			foreach ( $this->get_warehouses() as $key => $warehouse ) {
				$this->get_plugin()->schedule_action( 'warehouse_update_job', [ $warehouse, false ], HOUR_IN_SECONDS, time() );
			}

			if ( ! $this->get_plugin()->has_scheduled_action( 'continuous_job', [] ) ) {
				$this->get_plugin()->schedule_action( 'continuous_job' , [], 1 * MINUTE_IN_SECONDS );
			}
		}

		// Action Scheduler
		$this->get_plugin()->hook_action( 'warehouse_update_job', array( $this, 'update_warehouses_job_hook' ) );
		$this->get_plugin()->hook_action( 'continuous_job', array( $this, 'continuous_job_hook' ) );
		$this->get_plugin()->hook_action( 'cron_job', array( $this, 'cron_hook' ) );
		$this->get_plugin()->hook_action( 'manual_product_update', array( $this, 'cron_hook' ) );
		$this->get_plugin()->hook_action( 'product_update', array( $this, 'cron_products_hook' ) );
		$this->get_plugin()->hook_action( 'create_chunk_of_products', array( $this, 'as_action_create_chunk_of_products' ), 10, 3 );
		$this->get_plugin()->hook_action( 'end_of_product_creation_message', array( $this, 'as_action_end_of_product_creation_message' ) );
	}


	public function cron_hook( $warehouse, $manual_update = false, $update_products = true ) {

		$this->get_plugin()->log( 'Starting cron' );

		if ( true === $manual_update ) {
			$this->get_plugin()->unschedule_all_actions( 'product_update' );
			$this->get_plugin()->add_notice( 'manual_stock_sync', sprintf( __( 'Updating warehouse "%s" data.', 'konekt-merit-aktiva' ), $warehouse['title'] ) );
		}

		$time_start       = microtime( true );
		$update_warehouse = $this->update_warehouse_products( true, [ $warehouse ] );

		if ( $update_warehouse && $update_products ) {
			$this->get_plugin()->unschedule_all_actions( 'product_update' );
			$this->get_plugin()->schedule_action( 'product_update', [ 1, $manual_update ] );
		}

		$time_end = microtime( true );

		$this->get_plugin()->log( sprintf( 'Ending cron: %s sec duration', ( $time_end - $time_start ) ) );

	}


	public function update_warehouses_job_hook( $warehouse ) {
		$this->update_warehouse_products( true, [ $warehouse ] );
	}


	public function continuous_job_hook() {
		$warehouses        = $this->get_warehouses();
		$current_warehouse = (int) $this->get_plugin()->get_option( 'continuous_warehouse' );
		$page              = (int) $this->get_plugin()->get_option( 'continuous_page_num' ) ?: 1;

		if ( ( empty( $current_warehouse ) && -1 != $current_warehouse ) && ! empty( $warehouses ) ) {
			$current_warehouse = reset( $warehouses )['id'];
		}

		if ( -1 === $current_warehouse ) {
			// Update products
			$update = $this->cron_products_hook( $page, false );

			if ( true === $update ) {
				// Finished updates.
				$this->get_plugin()->update_option( 'continuous_warehouse', reset( $warehouses ) );
				$this->get_plugin()->update_option( 'continuous_page_num', 1 );
			} elseif ( false === $update ) {
				// Not finished yet.
				$this->get_plugin()->update_option( 'continuous_warehouse', -1 );
				$this->get_plugin()->update_option( 'continuous_page_num', $page + 1 );
			} else {
				// We are stuck somehow
			}
		} elseif ( $current_warehouse ) {
			$next_warehouse = false;
			$found_current  = false;

			foreach ( $warehouses as $warehouse ) {
				if ( $warehouse['id'] == $current_warehouse ) {
					$found_current = true;

					$this->get_plugin()->log_action( sprintf( __( 'Updating %s warehouse products.', 'konekt-merit-aktiva' ), $warehouse['title'] ), 'update-products' );

					$this->update_warehouse_products( true, [ $warehouse ] );
				} elseif ( true === $found_current && false === $next_warehouse ) {
					$next_warehouse = $warehouse;

					break;
				}
			}

			if ( false === $next_warehouse ) {
				$this->get_plugin()->update_option( 'continuous_warehouse', -1 );
			} else {
				$this->get_plugin()->update_option( 'continuous_warehouse', $next_warehouse['id'] );
			}
		}
	}


	public function cron_products_hook( $page = 1, $manual_update = false ) {
		$this->get_plugin()->log_action( sprintf( 'Fetching products for an update, page %d.', $page ), 'update-products' );

		// Remove existing product updates
		$this->get_plugin()->unschedule_all_actions( 'product_update' );

		$args = [
			'type'     => array_merge( [ 'variation' ], array_keys( wc_get_product_types() ) ),
			'return'   => 'ids',
			'limit'    => 100,
			'order'    => 'DESC',
			'orderby'  => 'post_type',
			'status'   => 'publish',
			'paginate' => true,
			'page'     => $page,
		];

		if ( function_exists( 'pll_default_language' ) ) {
			$args['lang'] = pll_default_language();
		}

		$results = wc_get_products( $args );

		foreach ( $results->products as $product_id ) {
			$product_id = $this->get_wpml_original_post_id( $product_id );
			$product    = wc_get_product( $product_id );

			if ( $product ) {
				if ( ! $product->is_type( 'external' ) ) {
					$this->update_product_stock_data( $product );
				}
			}
		}

		// Show informational notice
		if ( true === $manual_update ) {
			$this->get_plugin()->add_notice( 'manual_stock_sync', sprintf( 'Updated %d products, page %d of %d. Total products %d.', count( $results->products ), $page, $results->max_num_pages, $results->total ) );
		}

		$this->get_plugin()->log_action( sprintf( __( 'Updated %d products, page %d of %d. Total products %d.', 'konekt-merit-aktiva' ), count( $results->products ), $page, $results->max_num_pages, $results->total ), 'update-products' );

		if ( $results->max_num_pages > $page ) {

			if ( ! $this->is_continuous_sync_method() ) {
				$this->get_plugin()->schedule_action( 'product_update', [ $page + 1 ] );
			}

			return false;
		}
		elseif ( $results->max_num_pages == $page ) {
			$this->get_plugin()->log_action( sprintf( 'End of product updates. Updated total of %d.', $results->total ), 'update-products' );

			if ( true === $manual_update ) {
				$this->get_plugin()->add_notice( 'manual_stock_sync', __( 'Finished product stock syncing.', 'konekt-merit-aktiva' ) );
			}

			return true;
		}

		return false;
	}


	public function get_product_id_by_sku( $product_sku ) {
		$product  = null;
		$products = wc_get_products( [
			'sku'     => $product_sku,
			'limit'   => 1,
			'type'    => array_merge( [ 'variation' ], array_keys( wc_get_product_types() ) ),   // also search for variations
			'return'  => 'ids',
		] );

		if ( $products && 1 === count( $products ) ) {
			$product = reset( $products );
			$product = $this->get_wpml_original_post_id( $product );
		}

		return $product;
	}


	public function get_wpml_original_post_id( $post_id, $type = 'post_product' ) {
		global $sitepress;

		if ( $sitepress && defined( 'ICL_LANGUAGE_CODE' ) ) {
			$trid         = $sitepress->get_element_trid( $post_id, $type );
			$translations = $sitepress->get_element_translations( $trid, $type );

			if ( ! empty( $translations ) ) {
				foreach ( $translations as $translation ) {
					if ( $translation->original ) {
						$post_id = $translation->element_id;

						break;
					}
				}
			}
		} elseif ( function_exists( 'pll_get_post' ) ) {
			return pll_get_post( $post_id, pll_default_language() );
		}

		return $post_id;
	}


	public function get_warehouse_products( $warehouse_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wc_merit_aktiva_product_lookup WHERE location_code = %d", $warehouse_id ),
			ARRAY_A
		);
	}


	public function update_warehouse_products( $clear_cache = false, $warehouses = [] ) {
		global $wpdb;

		if ( empty( $warehouses ) ) {
			$warehouses = $this->get_warehouses();
		}

		$updated = false;

		foreach ( $warehouses as $warehouse ) {

			if ( true === $clear_cache ) {
				$products_in_warehouse = false;
			} else {
				$products_in_warehouse = $this->get_warehouse_products( $warehouse['id'] );
			}

			if ( empty( $products_in_warehouse ) ) {

				$this->get_plugin()->log( sprintf( 'Updating products source data in warehouse %s (%s)', $warehouse['title'], $warehouse['id'] ) );

				$wpdb->query( 'START TRANSACTION' );

				// Clear data
				$wpdb->query(
					$wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_merit_aktiva_product_lookup WHERE location_code = %d", $warehouse['id'] )
				);

				$api_products = $this->get_api()->get_products_in_warehouse( $warehouse['id'] );
				$cleaned_data = [];

				$this->get_plugin()->log( sprintf( 'Cleaning up products data in %s (%s)', $warehouse['title'], $warehouse['id'] ) );

				if ( $api_products ) {
					foreach ( $api_products as $product ) {
						if ( empty( $product->Code ) ) {
							continue;
						}

						$product_sku = trim( $product->Code );

						if ( ! $product_sku ) {
							continue;
						}

						$cleaned_data[ $product_sku ] = [
							'sku'            => $product_sku,
							'location_code'  => $warehouse['id'],
							'product_type'   => $product->Type,
							'stock_quantity' => $product->InventoryQty,
							'item_id'        => $product->ItemId,
							'uom_name'       => $product->UnitofMeasureName,
						];
					}
				}

				if ( ! empty( $cleaned_data ) ) {
					$updated = true;

					foreach ( $cleaned_data as $product_sku => $data ) {
						$this->add_product_to_lookup_table( $data );
					}
				}

				$wpdb->query( 'COMMIT' );

				$this->get_plugin()->log( sprintf( 'Settings products cache for warehouse %s (%s). Total of products %d.', $warehouse['title'], $warehouse['id'], count( $cleaned_data ) ) );
			}
		}

		return $updated;
	}


	/**
	 * Get taxes
	 *
	 * @return array
	 */
	public function get_taxes() {
		if ( false === ( $taxes = $this->get_plugin()->get_cache( 'taxes' ) ) ) {
			$taxes = $this->get_api()->get_taxes();

			if ( ! empty( $taxes ) ) {
				$taxes = (array) $taxes;
			} else {
				$taxes = [];
			}

			$this->get_plugin()->set_cache( 'taxes', $taxes, MONTH_IN_SECONDS );

		}

		return $taxes ?? [];
	}


	public function get_warehouses() {

		$warehouses = explode( "\n", $this->get_option( 'warehouses', [] ) );

		if ( ! empty( $warehouses ) ) {
			$warehouses = array_map( 'trim', $warehouses );
			$formatted  = [];

			foreach ( $warehouses as $warehouse_raw ) {
				$warehouse = explode( ':', $warehouse_raw );

				if ( count( $warehouse ) < 2 ) {
					continue;
				}

				$formatted[] = [
					'id'    => $warehouse[0],
					'title' => $this->translate_warehouse_title( $warehouse[1] ),
				];
			}

			return $formatted;
		}

		return [
			'id'    => 0,
			'title' => '',
		];
	}

	public function translate_warehouse_title( $title ) {
		if ( function_exists( 'pll_register_string' ) ) {
			pll_register_string( 'Warehouse title', $title, $this->get_plugin()->get_plugin_name() );

			return pll__( $title );
		}

		do_action( 'wpml_register_single_string', $this->get_plugin()->get_plugin_name(), 'Warehouse title', $title );

		return apply_filters( 'wpml_translate_single_string', $title, 'konekt-merit-aktiva', 'Warehouse title' );
	}


	/**
	 * Gets the API handler instance.
	 *
	 * @since 1.0.0
	 *
	 * @return \Konekt\WooCommerce\Merit_Aktiva\API
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
	public function have_api_credentials() {

		return $this->get_option( 'api_id' ) && $this->get_option( 'api_key' );
	}


	public function add_product_stock_tab( $tabs ) {
		global $product;

		$tabs['quantities_by_warehouse'] = [
			'title'    => __( 'Stock status', 'konekt-merit-aktiva' ),
			'priority' => 60,
			'callback' => function () use ( $product ) {
				if ( $product->is_type( 'variable' ) ) {
					$quantities = [];

					foreach ( $product->get_available_variations() as $variation ) {
						$variation_product    = wc_get_product( $variation['variation_id'] );
						$variation_quantities = $this->get_plugin()->attach_product_quantities_by_warehouse( [], $variation_product );

						if ( ! empty( $variation_quantities ) ) {
							$variation_name = [];

							foreach ($variation['attributes'] as $attribute) {
								$variation_name[] = $attribute;
							}

							foreach ( $variation_quantities as $variation_quantity ) {
								if ( ! array_key_exists( $variation_quantity['location'], $quantities ) ) {
									$quantities[$variation_quantity['location']] = [
										'location_title' => $variation_quantity['location_title'],
										'products'     => [],
									];
								}

								$quantities[$variation_quantity['location']]['products'][] = [
									'sku'       => $variation_product->get_sku(),
									'variation' => implode( ' ', $variation_name ),
									'product'   => $variation_product,
									'quantity'  => $variation_quantity['quantity'],
								];
							}
						}
					}

					wc_get_template( 'single-product/tabs/stock-status-variable.php', compact( 'quantities' ), '', $this->get_plugin()->get_plugin_path() . '/templates/' );
				} else {
					$quantities = $this->get_plugin()->attach_product_quantities_by_warehouse( [], $product );

					wc_get_template( 'single-product/tabs/stock-status.php', compact( 'quantities' ), '', $this->get_plugin()->get_plugin_path() . '/templates/' );
				}
			}
		];

		return $tabs;
	}


	public function generate_manual_product_sync_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
			'nonce'             => '',
			'button_title'      => '',
		);

		$data = wp_parse_args( $data, $defaults );

		if ( ! empty( $_GET['action'] ) && $data['nonce'] === $_GET['action'] ) {
			if ( ! empty( $_GET['nonce'] ) && wp_verify_nonce( $_GET['nonce'], $data['nonce'] ) ) {
				call_user_func( $data['callback'] );
			}
		}

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // WPCS: XSS ok. ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<a href="<?php echo esc_url( add_query_arg( [ 'nonce' => wp_create_nonce( $data['nonce'] ), 'action' => $data['nonce'] ] ) ) ?>" class="button"><?php echo esc_html( $data['button_title'] ); ?></a>
					<?php echo $this->get_description_html( $data ); // WPCS: XSS ok. ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}


	public function manual_product_stock_sync() {
		if ( did_action( $this->get_plugin()->get_id() . '_sync-product-stock' ) ) {
			return;
		}

		do_action( $this->get_plugin()->get_id() . '_sync-product-stock' );

		$this->get_plugin()->log( 'Starting manual sync' );
		$this->get_plugin()->add_notice( 'manual_stock_sync', __( 'Running product stock sync.', 'konekt-merit-aktiva' ) );

		foreach ( $this->get_warehouses() as $key => $warehouse ) {
			$this->get_plugin()->schedule_action( 'manual_product_update', [ $warehouse, true ] );
		}
	}


	public function manual_product_sync() {
		if ( did_action( $this->get_plugin()->get_id() . '_create-products' ) ) {
			return;
		}

		do_action( $this->get_plugin()->get_id() . '_create-products' );

		$this->get_plugin()->log_action( 'Starting manual product creation', 'create-products' );

		$products_per_page = 10;
		$products_ids      = wc_get_products( [
			'limit'                => -1,
			'paginate'             => false,
			'type'                 => [ 'simple', 'variation' ],
			'return'               => 'ids',
			'status'               => 'publish',
			'merit_aktiva_item_id' => '',
		] );

		$this->get_plugin()->delete_cache( 'create_products' );

		if ( WC_Admin_Notices::has_notice( $this->get_plugin()->get_id() . '_create-products' ) ) {
			WC_Admin_Notices::remove_notice( $this->get_plugin()->get_id() . '_create-products' );
		}

		if ( ! empty( $products_ids ) ) {
			if ( count( $products_ids ) > $products_per_page ) {
				$products_chunk = array_chunk( $products_ids, $products_per_page );
			} else {
				$products_chunk = [ $products_ids ];
			}

			$notice_text = sprintf( __( 'Found total of %d products, total of %d chunks', 'konekt-merit-aktiva' ), count( $products_ids ), count( $products_chunk ) );

			$this->get_plugin()->add_notice( 'create-products', $notice_text );

			$this->get_plugin()->log_action( $notice_text, 'create-products' );

			foreach ( $products_chunk as $chunk_key => $chunk ) {
				$this->get_plugin()->schedule_action( 'create_chunk_of_products', [ 'ids' => $chunk, 'chunk' => $chunk_key + 1, 'total_chunks' => count( $products_chunk ) ] );
			}

			$this->get_plugin()->schedule_action( 'end_of_product_creation_message', [ 'total_products' => count( $products_ids ) ] );
		} else {
			$this->get_plugin()->log_action( __( 'Did not find any products.', 'konekt-merit-aktiva' ), 'create-products' );
			$this->get_plugin()->add_notice( 'create-products', __( 'Did not find any products.', 'konekt-merit-aktiva' ) );
		}
	}


	public function as_action_create_chunk_of_products( $ids = [], $chunk = 0, $total_chunks = 0 ) {
		if ( ! empty( $ids ) ) {
			$products = array_map( 'wc_get_product', $ids );

			// Create products
			$create_products = $this->get_api()->create_products( $products );

			$this->get_plugin()->add_notice(
				'create-products',
				sprintf(
					__( 'Creating %d products, chunk %d of total %d.', 'konekt-merit-aktiva' ),
					count( $ids ),
					$chunk,
					$total_chunks
				)
			);

			if ( true === $create_products ) {
				do_action( 'konekt_merit_aktiva_created_products', $products );

				// Sync stock
				foreach ( $products as $product ) {

					if ( $product->is_type( 'external' ) ) {
						continue;
					}

					$external_item = $this->get_api()->get_item( $product->get_sku() );

					if ( $external_item ) {
						$this->get_plugin()->add_product_meta( $product, 'item_id', $external_item->ItemId );
					} else {
						$this->get_plugin()->remove_product_meta( $product, [ 'item_id' ] );
					}
				}

				$this->get_plugin()->log_action( __( 'End of this chunk.', 'konekt-merit-aktiva' ), 'create-products' );
			} else {
				$this->get_plugin()->log_action( sprintf( 'Could not create products: %s', print_r( $create_products->Message, true ) ), 'create-products' );

				$creation_errors  = $this->get_plugin()->get_cache( 'create_products' );
				$creation_errors .= print_r( $create_products->Message ?? $create_products, true );

				$this->get_plugin()->set_cache( 'create_products', $creation_errors, 0 );
			}
		}
	}


	public function as_action_end_of_product_creation_message( $total_products = 0 ) {

		$this->get_plugin()->log_action( 'Finished manual product sync', 'create-products' );

		$creation_errors = $this->get_plugin()->get_cache( 'create_products' );

		if ( ! empty( $creation_errors ) ) {
			$this->get_plugin()->log_action( sprintf( 'Could not create products: %s', print_r( $creation_errors, true ) ), 'create-products' );
			$this->get_plugin()->add_notice( 'create-products', $creation_errors );
		} else {
			$this->get_plugin()->add_notice( 'create-products', sprintf( __( 'Created total of %d products.', 'konekt-merit-aktiva' ), $total_products ) );
		}
	}

	public function generate_payment_methods_mapping_table_html( $key, $data ) {
		$field_key      = $this->get_field_key( $key );
		$default_args   = [
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => [],
		];
		$data          = wp_parse_args( $data, $default_args );
		$row_counter   = 0;
		$values        = (array) $this->get_option( $key, array() );
		$payment_types = $this->get_api()->get_payment_types();

		ob_start();
		?>

		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // WPCS: XSS ok. ?></label>
			</th>
			<td class="forminp">

				<?php echo $this->get_description_html( $data ); // WPCS: XSS ok. ?>

				<table class="form-table">

					<thead>
						<tr>
							<td style="width: 35px;"><strong>#</strong></td>
							<td><strong><?php esc_html_e( 'Payment Method', 'konekt-merit-aktiva' ); ?></strong></td>
							<td><strong><?php esc_html_e( 'Bank', 'konekt-merit-aktiva' ); ?></strong></td>
						</tr>
					</thead>
					<tbody>

						<?php foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) : ?>

							<?php
							$row_counter++;

							$value = $values[ $gateway_id ] ?? false;
							?>

							<tr>
								<td><?php echo $row_counter; ?>.</td>
								<td><?php echo esc_html( $gateway->get_title() ); ?></td>
								<td>
									<select name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $gateway_id ); ?>]">
										<option value="">- <?php esc_html_e( 'Do not overwrite', 'konekt-merit-aktiva' ) ?> -</option>

										<?php foreach ( $payment_types as $bank ) : ?>
											<option value="<?php echo esc_attr( $bank->Name ); ?>" <?php selected( $value, $bank->Name, true ) ?>><?php echo esc_html( $bank->Name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>

						<?php endforeach; ?>


						<?php
						$row_counter++;

						$value = $values['none'] ?? false;
						?>

						<tr>
							<td><?php echo $row_counter; ?>.</td>
							<td><?php echo esc_html( __( 'No payment' ) ); ?></td>
							<td>
								<select name="<?php echo esc_attr( $field_key ); ?>[none]">
									<option value="">- <?php esc_html_e( 'Do not overwrite', 'konekt-merit-aktiva' ) ?> -</option>

									<?php foreach ( $payment_types as $bank ) : ?>
										<option value="<?php echo esc_attr( $bank->Name ); ?>" <?php selected( $value, $bank->Name, true ) ?>><?php echo esc_html( $bank->Name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>

					</tbody>

				</table>
			</td>
		</tr>

		<?php
		return ob_get_clean();
	}


	/**
	 * Validate payment_methods mapping table field.
	 *
	 * @param  string $key Field key.
	 * @param  string $value Posted Value.
	 * @return string|array
	 */
	public function validate_payment_methods_mapping_table_field( $key, $value ) {

		return $this->validate_multiselect_field( $key, $value );
	}


	public function generate_tax_mapping_table_html( $key, $data ) {
		$field_key      = $this->get_field_key( $key );
		$default_args   = [
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => [],
		];
		$data        = wp_parse_args( $data, $default_args );
		$row_counter = 0;
		$values      = (array) $this->get_option( $key, array() );

		ob_start();
		?>

		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // WPCS: XSS ok. ?></label>
			</th>
			<td class="forminp">

				<?php echo $this->get_description_html( $data ); // WPCS: XSS ok. ?>

				<table class="form-table" style="table-layout: auto;">

					<thead>
						<tr>
							<td style="width: 35px"><strong>#</strong></td>
							<td><strong><?php esc_html_e( 'WooCommerce Tax', 'konekt-merit-aktiva' ); ?></strong></td>
							<td><strong><?php esc_html_e( 'Tax rate slug', 'konekt-merit-aktiva' ); ?></strong></td>
							<td><strong><?php esc_html_e( 'Rate', 'konekt-merit-aktiva' ); ?></strong></td>
							<td><strong><?php esc_html_e( 'Tax ID', 'konekt-merit-aktiva' ); ?></strong></td>
						</tr>
					</thead>
					<tbody>

						<?php foreach ( $this->get_all_tax_rates() as $wc_tax_id => $wc_tax ) : ?>

							<?php
							$row_counter++;

							$value = $values[$wc_tax_id] ?? false;
							?>

							<tr>
								<td><?php echo $row_counter; ?>.</td>
								<td>
									<?php echo esc_html( $wc_tax['label'] ); ?>
									<?php if ( ! empty( $wc_tax['country'] ) ) : ?>
										&nbsp;(<?php echo esc_html( $wc_tax['country'] ); ?>)
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $wc_tax['slug'] ); ?></td>
								<td><?php echo esc_html( $wc_tax['rate'] ); ?>%</td>
								<td>
									<select name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $wc_tax_id ); ?>]">
										<?php foreach ( $this->get_taxes() as $tax_rate ) : ?>
											<option value="<?php echo esc_attr( $tax_rate->Id ); ?>" <?php selected( $value, $tax_rate->Id ); ?>><?php echo esc_html( $tax_rate->Name ); ?> (<?php echo esc_html( $tax_rate->Code ); ?>, <?php echo esc_html( $tax_rate->TaxPct ); ?>%, <?php echo esc_html( $tax_rate->Id ); ?>)</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>

						<?php endforeach; ?>

						<tr>
							<td><?php echo $row_counter + 1; ?>.</td>
							<td><?php echo esc_html_e( '0% tax', 'konekt-merit-aktiva' ); ?></td>
							<td></td>
							<td>0%</td>
							<td>
								<select name="<?php echo esc_attr( $field_key ); ?>[none]">
									<?php foreach ( $this->get_taxes() as $tax_rate ) : ?>
										<option value="<?php echo esc_attr( $tax_rate->Id ); ?>" <?php selected( $values['none'] ?? self::DEFAULT_ZERO_TAX_ID, $tax_rate->Id ); ?>><?php echo esc_html( $tax_rate->Name ); ?> (<?php echo esc_html( $tax_rate->Code ); ?>, <?php echo esc_html( $tax_rate->TaxPct ); ?>%, <?php echo esc_html( $tax_rate->Id ); ?>)</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>

					</tbody>

				</table>
			</td>
		</tr>

		<?php
		return ob_get_clean();
	}


	/**
	 * Validate tax mapping table field.
	 *
	 * @param  string $key Field key.
	 * @param  string $value Posted Value.
	 * @return string|array
	 */
	public function validate_tax_mapping_table_field( $key, $value ) {

		return $this->validate_multiselect_field( $key, $value );
	}


	public function get_all_tax_rates() {
		global $wpdb;

		$rates = \WC_Tax::get_rates();

		foreach ( $rates as $rate_key => $rate ) {
			if ( 'yes' === $rate['shipping'] && ! isset( $rate['slug'] ) ) {
				$rates[ $rate_key ]['slug'] = get_option( 'woocommerce_shipping_tax_class' );
			}
		}

		foreach ( $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}woocommerce_tax_rates` ORDER BY tax_rate_order" ) as $rate ) {
			$rates[ $rate->tax_rate_id ] = [
				'label'   => $rate->tax_rate_name,
				'rate'    => $rate->tax_rate,
				'slug'    => $rate->tax_rate_class,
				'country' => $rate->tax_rate_country,
			];
		}

		return $rates;
	}


	public function get_matching_bank_account( $payment_method ) {
		$payment_methods = (array) $this->get_option( 'payment_methods', [] );

		if ( array_key_exists( $payment_method, $payment_methods ) ) {
			return $payment_methods[ $payment_method ];
		}
		else {
			return null;
		}
	}


	public function get_matching_tax_code( $wc_tax_class = null, $tax_rate_id = null ) {
		if ( null === $tax_rate_id ) {
			$tax_rate_id = '';

			if ( 0 === $wc_tax_class ) {
				$tax_rate_id = 'none';
			}
			else {
				foreach ( $this->get_all_tax_rates() as $rate_id => $rate ) {
					if ( $wc_tax_class == $rate['slug'] ) {
						$tax_rate_id = $rate_id;

						break;
					}
				}
			}
		}

		if ( $tax_rate_id ) {
			$current_taxes = (array) $this->get_option( 'taxes', [] );

			if ( array_key_exists( $tax_rate_id, $current_taxes ) ) {
				return $current_taxes[ $tax_rate_id ];
			}
			elseif ( 'none' === $tax_rate_id ) {
				return self::DEFAULT_ZERO_TAX_ID;
			}
		}
		else {
			return self::DEFAULT_ESTONIAN_TAX_ID;
		}

		return false;
	}


	public function add_custom_product_query_var( $query, $query_vars ) {
		if ( isset( $query_vars['merit_aktiva_item_id'] ) ) {
			if ( '' === $query_vars['merit_aktiva_item_id'] ) {
				$query['meta_query'][] = [
					'relation' => 'AND',
					[
						'key'     => '_sku',
						'value'   => '',
						'compare' => '!=',
					],
					[
						'key'     => $this->get_plugin()->get_meta_key( 'item_id' ),
						'compare' => 'NOT EXISTS',
					],
				];
			}
			else {
				$query['meta_query'][] = [
					'key'     => $this->get_plugin()->get_meta_key( 'item_id' ),
					'value'   => $query_vars['merit_aktiva_item_id'],
					'compare' => '=',
				];
			}
		}

		return $query;
	}


	/**
	 * Do not duplicate item ID meta when duplicating product
	 *
	 * @param array $excluded_meta
	 *
	 * @return array
	 */
	function remove_product_duplication_meta( $excluded_meta ) {
		$excluded_meta[] = $this->get_plugin()->get_meta_key( 'item_id' );
		$excluded_meta[] = $this->get_plugin()->get_meta_key( 'quantities_by_warehouse' );
		$excluded_meta[] = $this->get_plugin()->get_meta_key( 'created' );
		$excluded_meta[] = $this->get_plugin()->get_meta_key( 'uom_name' );

		return $excluded_meta;
	}


	/**
	 * Add custom button for manual product data update
	 *
	 * @return void
	 */
	public function add_product_update_data_field() {
		?>
		<div class="options_group options-group__<?php echo esc_attr( $this->id ) ?>">
			<p class="form-field">
				<label><?php echo esc_html( $this->get_method_title() ); ?></label>
				<a href="<?php echo esc_url( add_query_arg( 'update_source', $this->id ) ); ?>" class="button"><?php esc_html_e( 'Update data', 'konekt-merit-aktiva' ); ?></a>
			</p>
		</div>
		<?php
	}


	/**
	 * Add giftcard checkbox to coupons
	 *
	 * @return void
	 */
	public function add_coupon_giftcard_option() {
		woocommerce_wp_checkbox( array(
			'id'          => 'is_coupon_giftcard',
			'cbvalue'     => 'yes',
			'label'       => __( 'Giftcard', 'konekt-merit-aktiva' ),
			'description' => __( 'Giftcards change the way discounts are calculated on the invoice.', 'konekt-merit-aktiva' ),
		) );
	}


	/**
	 * Save giftcard checkbox data
	 *
	 * @param integer $post_id
	 * @param \WC_Coupon $coupon
	 *
	 * @return void
	 */
	public function save_coupon_giftcard_option( $post_id, $coupon ) {
		if ( isset( $_POST['is_coupon_giftcard'] ) ) {
			$coupon->update_meta_data( 'is_coupon_giftcard', 'yes' );
		} else {
			$coupon->update_meta_data( 'is_coupon_giftcard', 'no' );
		}

		$coupon->save();
	}


	public function get_orders() {

		if ( null === $this->orders ) {
			$this->orders = new Orders( $this );
		}

		return $this->orders;
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
