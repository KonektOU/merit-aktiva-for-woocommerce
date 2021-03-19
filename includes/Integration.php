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
				'title'   => __( 'On-hold sync', 'konekt-merit-aktiva' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Sync orders with on-hold status.', 'konekt-merit-aktiva' ),
			],

			'invoice_shipping_sku' => [
				'title'       => __( 'Shipping SKU', 'konekt-merit-aktiva' ),
				'type'        => 'text',
				'default'     => '',
			],

			'invoice_payment_method_name' => [
				'title'       => __( 'Payment method name', 'konekt-merit-aktiva' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'This will override the payment method title from WooCommerce. Leave empty for WooCommerce title.', 'konekt-merit-aktiva' ),
			],

			'cod_payment_method_name' => [
				'title'       => __( 'Cash of Delivery method name', 'konekt-merit-aktiva' ),
				'type'        => 'text',
				'default'     => '',
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
				'default' => 'on-demand',
				'options' => [
					'on-demand' => __( 'On demand', 'konekt-merit-aktiva' ),
					'relative'  => __( 'Relative', 'konekt-merit-aktiva' ),
					'cron'      => __( 'Cron twice daily', 'konekt-merit-aktiva' ),
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
				$this->get_plugin()->get_admin_notice_handler()->add_admin_notice( __( 'Product updated.', 'konekt-merit-aktiva' ), 'product_update' );
			} else {
				$this->get_plugin()->get_admin_notice_handler()->add_admin_notice( __( 'Product update failed.', 'konekt-merit-aktiva' ), 'product_update' );
			}
		}

		// Manual update handling
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'add_product_update_data_field' ) );
	}


	public function manually_update_product_stock_data( $product_id ) {
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
					$warehouse_products = $this->get_warehouse_products( $warehouse['id'] );
					$item_stock         = $this->get_api()->get_item_stock( $product->get_sku(), $warehouse['id'] );

					if ( ! is_object( $item_stock ) || ! property_exists( $item_stock, 'InventoryQty' ) ) {
						continue;
					}

					$warehouse_products[ $product->get_sku() ] = (object) [
						'Type'              => $item_stock->Type,
						'InventoryQty'      => $item_stock->InventoryQty,
						'ItemId'            => $item_stock->ItemId,
						'UnitofMeasureName' => $item_stock->UnitofMeasureName,
						'ProductId'         => $product_id,
					];

					$this->get_plugin()->set_cache( 'warehouse_' . $warehouse['id'], $warehouse_products, HOUR_IN_SECONDS * intval( $this->get_option( 'product_refresh_rate', 15 ) ) );

					$this->update_product_stock_data( $product );
				}
			}

			if ( $_product->is_type( 'variable' ) ) {
				$_product->sync_stock_status( $_product );
			}

			return true;
		}

		return false;
	}


	public function get_product_from_warehouse( $product_sku, $warehouse_id ) {
		$all_products = $this->get_warehouse_products( $warehouse_id );
		$product      = null;

		if ( $all_products ) {
			if ( array_key_exists( $product_sku, $all_products ) ) {
				$product = $all_products[ $product_sku ];
			}
		}

		return $product;
	}


	public function update_product_stock_data( $product ) {
		$warehouses     = $this->get_warehouses();
		$quantities     = [];
		$total_quantity = 0;

		foreach ( $warehouses as $warehouse ) {

			$item_stock = $this->get_product_from_warehouse( $product->get_sku(), $warehouse['id'] );

			if ( empty( $item_stock ) ) {
				$this->get_plugin()->remove_product_meta( $product, [ 'item_id', 'uom_name' ] );

				if ( $product->is_type( 'variable' ) ) {
					if ( ! $product->managing_stock() && $product->get_stock_status() == 'outofstock' ) {
						$product->set_stock_status( 'instock' );
					}
				}

				continue;
			}

			$item_stock = (array) $item_stock;

			if ( 'Laokaup' != $item_stock['Type'] ) {
				$quantities = null;

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
			$this->get_plugin()->add_product_meta( $product, [
				'item_id'  => $item_stock['ItemId'],
				'uom_name' => $item_stock['UnitofMeasureName'],
			] );

			if ( $item_stock['InventoryQty'] ) {
				$total_quantity += (int) $item_stock['InventoryQty'];

				$quantities[] = [
					'location' => $warehouse['id'],
					'quantity' => wc_stock_amount( $item_stock['InventoryQty'] ),
				];
			}
		}

		if ( null !== $quantities && $product->managing_stock() ) {
			// Save quantities to meta
			$this->get_plugin()->add_product_meta(
				$product,
				[
					'quantities_by_warehouse' => $quantities
				]
			);

			if ( $total_quantity != $product->get_stock_quantity() ) {
				$product->set_stock_quantity( $total_quantity );
			}

			if ( $total_quantity > 0 && 'instock' !== $product->get_stock_status() ) {
				$product->set_stock_status( 'instock' );
			}

		} else {
			$this->get_plugin()->remove_product_meta( $product, [ 'quantities_by_warehouse' ] );
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


	public function schedule_cron() {

		if ( 'cron' === $this->get_option( 'sync_method', 'on-demand' ) ) {
			foreach ( $this->get_warehouses() as $key => $warehouse ) {
				if ( ! wp_next_scheduled( 'konekt_merit_aktiva_cron_job', [ $warehouse ] ) ) {
					wp_schedule_event( time() + (  MINUTE_IN_SECONDS + ( ( $key * 2 ) * MINUTE_IN_SECONDS ) ), 'twicedaily', 'konekt_merit_aktiva_cron_job', [ $warehouse ] );
				}
			}
		} else {
			if ( ! did_action( $this->get_plugin()->get_id() . '_sync-product-stock' ) ) {
				wp_clear_scheduled_hook( 'konekt_merit_aktiva_cron_job' );
			}
		}

		add_action( 'konekt_merit_aktiva_cron_job', array( $this, 'cron_hook' ), 10 );
		add_action( 'konekt_merit_aktiva_manual_cron_job', array( $this, 'cron_hook' ), 10 );
		add_action( 'konekt_merit_aktiva_products_cron_job', array( $this, 'cron_products_hook' ), 10 );
	}


	public function cron_hook( $warehouse ) {

		$this->get_plugin()->log( 'Starting cron' );

		if ( wp_next_scheduled( 'konekt_merit_aktiva_products_cron_job' ) ) {
			wp_clear_scheduled_hook( 'konekt_merit_aktiva_products_cron_job' );
		}

		$time_start  = microtime( true );
		$product_ids = $this->update_warehouse_products( true, [ $warehouse ] );

		if ( ! empty( $product_ids ) ) {
			wp_schedule_single_event( time() + ( 3 * MINUTE_IN_SECONDS ), 'konekt_merit_aktiva_products_cron_job' );
		}

		$time_end = microtime( true );

		$this->get_plugin()->log( sprintf( 'Ending cron: %s sec duration', ( $time_end - $time_start ) ) );

	}


	public function cron_products_hook( $page = 1 ) {
		$this->get_plugin()->log( sprintf( 'Fetching products for an update, page %d.', $page ), $this->get_plugin()->get_id() . '_update-products' );

		$results = wc_get_products( [
			'type'     => array_merge( [ 'variation' ], array_keys( wc_get_product_types() ) ),
			'return'   => 'ids',
			'limit'    => 250,
			'order'    => 'DESC',
			'orderby'  => 'post_type',
			'status'   => 'publish',
			'paginate' => true,
			'page'     => $page,
		] );

		foreach ( $results->products as $product_id ) {
			$product_id = $this->get_wpml_original_post_id( $product_id );
			$product    = wc_get_product( $product_id );

			if ( $product ) {
				if ( ! $product->is_type( 'external' ) ) {
					$this->update_product_stock_data( $product );
				}

				if ( $product->is_type( 'variable' ) ) {
					$product->sync_stock_status( $product );

					if ( ! empty( $product->get_changes() ) ) {
						$product->save();
					}
				}
			}
		}

		$this->get_plugin()->log( sprintf( 'Updated %d products, page %d of %d. Total products %d.', count( $results->products ), $page, $results->max_num_pages, $results->total ), $this->get_plugin()->get_id() . '_update-products' );

		if ( $results->max_num_pages > $page ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'konekt_merit_aktiva_products_cron_job', [ $page + 1 ] );
		}
		elseif ( $results->max_num_pages == $page ) {
			$this->get_plugin()->log( sprintf( 'End of product updates. Updated total of %d.', $results->total ), $this->get_plugin()->get_id() . '_update-products' );
		}
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

		if ( ! $sitepress || ! defined( 'ICL_LANGUAGE_CODE' ) ) {
			return $post_id;
		}

		$trid         = $sitepress->get_element_trid( $post_id, $type );
		$translations = $sitepress->get_element_translations( $trid, $type );

		if ( ! empty( $translations ) ) {
			foreach ( $translations as $translation ) {
				if ( $translation->original ) {
					return $translation->element_id;
				}
			}
		}

		return $post_id;
	}


	public function get_warehouse_products( $warehouse_id ) {
		return $this->get_plugin()->get_cache( 'warehouse_' . $warehouse_id );
	}


	public function update_warehouse_products( $clear_cache = false, $warehouses = [] ) {
		if ( empty( $warehouses ) ) {
			$warehouses = $this->get_warehouses();
		}

		$product_ids = [];

		foreach ( $warehouses as $warehouse ) {

			if ( true === $clear_cache ) {
				$products_in_warehouse = false;
			} else {
				$products_in_warehouse = $this->get_warehouse_products( $warehouse['id'] );
			}

			if ( false === $products_in_warehouse ) {
				$this->get_plugin()->log( sprintf( 'Updating products source data in warehouse %s (%s)', $warehouse['title'], $warehouse['id'] ) );

				$api_products = $this->get_api()->get_products_in_warehouse( $warehouse['id'] );
				$cleaned_data = [];

				if ( $api_products ) {
					foreach ( $api_products as $product ) {
						if ( empty( $product->Code ) ) {
							continue;
						}

						$product_sku = trim( $product->Code );

						if ( array_key_exists( $product_sku, $cleaned_data ) ) {
							continue;
						}

						$product_id_by_sku = $this->get_product_id_by_sku( $product_sku );
						$product_data      = [
							'Type'              => $product->Type,
							'InventoryQty'      => $product->InventoryQty,
							'ItemId'            => $product->ItemId,
							'UnitofMeasureName' => $product->UnitofMeasureName,
						];

						if ( $product_id_by_sku ) {
							// Just init product so it will be updated
							$product_data['ProductID'] = $product_id_by_sku;
							$product_ids []            = $product_id_by_sku;
						}

						$cleaned_data[ $product_sku ] = (object) $product_data;
					}
				}

				$this->get_plugin()->set_cache( 'warehouse_' . $warehouse['id'], $cleaned_data, HOUR_IN_SECONDS * intval( $this->get_option( 'product_refresh_rate', 15 ) ) );
			}
		}

		return $product_ids;
	}


	/**
	 * Get taxes
	 *
	 * @return void
	 */
	public function get_taxes() {
		if ( false === ( $taxes = $this->get_plugin()->get_cache( 'taxes' ) ) ) {
			$taxes = $this->get_api()->get_taxes();

			if ( ! empty( $taxes ) ) {
				$taxes = array_column( (array) $taxes, 'Code', 'Id' );
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
					'title' => $warehouse[1],
				];
			}

			return $formatted;
		}

		return [
			'id'    => 0,
			'title' => '',
		];
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

		foreach ( $this->get_warehouses() as $key => $warehouse ) {
			if ( ! wp_next_scheduled( 'konekt_merit_aktiva_manual_cron_job', [ $warehouse ] ) ) {
				wp_schedule_single_event( time() + ( $key * MINUTE_IN_SECONDS ), 'konekt_merit_aktiva_manual_cron_job', [ $warehouse ] );
			}
		}
	}


	public function manual_product_sync() {
		if ( did_action( $this->get_plugin()->get_id() . '_create-products' ) ) {
			return;
		}

		do_action( $this->get_plugin()->get_id() . '_create-products' );

		$current_page  = absint( wc_get_var( $_GET['current_page'], 1 ) );
		$last_creation = date_i18n( 'Y-m-d H:i:s' );

		$this->get_plugin()->log( sprintf( 'Starting manual product creation (page %d)', $current_page ), $this->get_plugin()->get_id() . '_create-products' );

		$query_products = wc_get_products( [
			'limit'                => 50,
			'paginate'             => true,
			'page'                 => $current_page,
			'type'                 => [ 'simple', 'variation' ],
			'return'               => 'ids',
			'status'               => 'publish',
			'merit_aktiva_item_id' => '',
			'merit_aktiva_created' => $last_creation,
		] );

		if ( 1 === $current_page ) {
			$this->get_plugin()->log( sprintf( 'Found total of %d products, total of %d pages', $query_products->total, $query_products->max_num_pages ), $this->get_plugin()->get_id() . '_create-products' );
			$this->get_plugin()->delete_cache( 'create_products' );
		}

		if ( ! empty( $query_products->products ) ) {
			$products = array_map( 'wc_get_product', $query_products->products );

			// Create products
			$create_products = $this->get_api()->create_products( $products );

			if ( true === $create_products ) {
				do_action( 'konekt_merit_aktiva_created_products' );

				// Sync stock
				foreach ( $products as $product ) {

					if ( $product->is_type( 'external' ) ) {
						continue;
					}

					$external_item = $this->get_api()->get_item( $product->get_sku() );

					if ( $external_item ) {
						$this->get_plugin()->add_product_meta( $product, [
							'item_id'  => $external_item->ItemId,
							'uom_name' => $external_item->UnitofMeasureName,
							'created'  => $last_creation,
						] );
					} else {
						$this->get_plugin()->remove_product_meta( $product, [ 'item_id', 'uom_name' ] );
					}

				}
			} else {
				$this->get_plugin()->log( sprintf( 'Could not create products: %s', print_r( $create_products, true ) ), $this->get_plugin()->get_id() . '_create-products' );

				$creation_errors  = $this->get_plugin()->get_cache( 'create_products' );
				$creation_errors .= print_r( $create_products, true );

				$this->get_plugin()->set_cache( 'create_products', $creation_errors, 0 );
			}

			if ( $query_products->max_num_pages > 1 && $current_page < $query_products->max_num_pages ) {
				wp_safe_redirect( add_query_arg( [
					'action'       => 'create-products',
					'nonce'        => wp_create_nonce( 'create-products' ),
					'current_page' => $current_page + 1,
				], $this->get_plugin()->get_settings_url() ) );
			} else {
				$this->get_plugin()->log( sprintf( 'Finished manual product sync after %d pages', $current_page ), $this->get_plugin()->get_id() . '_create-products' );

				if ( ! $creation_errors ) {
					$creation_errors = $this->get_plugin()->get_cache( 'create_products' );
				}

				if ( $creation_errors ) {
					if ( WC_Admin_Notices::has_notice( $this->get_plugin()->get_id() . '_create-products' ) ) {
						WC_Admin_Notices::remove_notice( $this->get_plugin()->get_id() . '_create-products' );
					}

					WC_Admin_Notices::add_custom_notice( $this->get_plugin()->get_id() . '_create-products', $creation_errors );
				}

				wp_safe_redirect( add_query_arg( 'done', '1', $this->get_plugin()->get_settings_url() ) );
			}
		} else {
			$this->get_plugin()->log( 'Did not find any products', $this->get_plugin()->get_id() . '_create-products' );
		}
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
		$data           = wp_parse_args( $data, $default_args );
		$external_taxes = (array) $this->get_taxes();
		$row_counter    = 0;
		$wc_taxes       = $this->get_all_tax_rates();
		$values         = (array) $this->get_option( $key, array() );

		ob_start();
		?>

		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // WPCS: XSS ok. ?></label>
			</th>
			<td class="forminp">

				<?php echo $this->get_description_html( $data ); // WPCS: XSS ok. ?>

				<table class="">

					<thead>
						<tr>
							<th width="100">#</th>
							<th><?php esc_html_e( 'WooCommerce Tax', 'konekt-merit-aktiva' ); ?></th>
							<th><?php esc_html_e( 'Tax ID', 'konekt-merit-aktiva' ); ?></th>
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
								<td><?php echo esc_html( $wc_tax['label'] ); ?></td>
								<td><input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $wc_tax_id ); ?>]" value="<?php echo esc_attr( $value ); ?>"></td>
							</tr>

						<?php endforeach; ?>

						<tr>
							<td><?php echo $row_counter + 1; ?>.</td>
							<td><?php echo esc_html_e( '0% tax', 'konekt-merit-aktiva' ); ?></td>
							<td><input type="text" name="<?php echo esc_attr( $field_key ); ?>[none]" value="<?php echo esc_attr( $values['none'] ?? self::DEFAULT_ZERO_TAX_ID ); ?>"></td>
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

		$rates = \WC_Tax::get_rates();

		foreach ( $rates as $rate_key => $rate ) {
			if ( 'yes' === $rate['shipping'] && ! isset( $rate['slug'] ) ) {
				$rates[ $rate_key ]['slug'] = get_option( 'woocommerce_shipping_tax_class' );
			}
		}

		foreach ( \WC_Tax::get_tax_class_slugs() as $tax_class ) {

			foreach ( \WC_Tax::get_rates_for_tax_class( $tax_class ) as $rate ) {

				$rates[ $rate->tax_rate_id ] = [
					'label' => $rate->tax_rate_name,
					'rate'  => $rate->tax_rate,
					'slug'  => $tax_class,
				];
			}
		}

		return $rates;
	}


	public function get_matching_tax_code( $wc_tax_class ) {
		$tax         = '';
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

		return $tax;
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
						'relation' => 'OR',
						[
							'key'     => $this->get_plugin()->get_meta_key( 'item_id' ),
							'compare' => 'NOT EXISTS',
						],
						[
							'key'     => $this->get_plugin()->get_meta_key( 'item_id' ),
							'value'   => '',
							'compare' => '=',
						]
					]
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

		if ( isset( $query_vars['merit_aktiva_created'] ) ) {
			$query['meta_query'][] = [
				'relation' => 'OR',
				[
					'key'     => $this->get_plugin()->get_meta_key( 'created' ),
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => $this->get_plugin()->get_meta_key( 'created' ),
					'value'   => '',
					'compare' => '=',
				],
				[
					'key'     => $this->get_plugin()->get_meta_key( 'created' ),
					'value'   => $query_vars['merit_aktiva_created'],
					'compare' => '!=',
				]
			];
		}

		return $query;
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


	public function get_orders() {

		if ( null === $this->orders ) {
			$this->orders = new Orders( $this );
		}

		return $this->orders;
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
