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
	protected $api = null;


	protected $orders = null;


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

			'sync_all_products' => [
				'title' => __( 'Sync products', 'konekt-merit-aktiva' ),
				'type'  => 'manual-product'
			]
		];

		if ( $this->have_api_credentials() ) {

			$this->form_fields['sync_all_products'] = [
				'title'       => __( 'Sync products', 'konekt-merit-aktiva' ),
				'type'        => 'manual_product_sync',
				'description' => __( 'Manual sync for product stocks.', 'konekt-merit-aktiva' ),
			];

			// Taxes
			$this->form_fields['taxes_section_title'] = [
				'title' => __( 'Taxes configuration', 'konekt-merit-aktiva' ),
				'type'  => 'title',
			];

			$this->form_fields['taxes'] = [
				'title' => __( 'Taxes', 'konekt-merit-aktiva' ),
				'type'  => 'tax_mapping_table',
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
		if ( ! empty( $_GET['action'] ) && 'sync-products' === $_GET['action'] ) {
			if ( ! empty( $_GET['nonce'] ) && wp_verify_nonce( $_GET['nonce'], 'sync-products' ) ) {
				if ( did_action( $this->get_plugin()->get_id() . '_sync-products' ) ) {
					return;
				}

				do_action( $this->get_plugin()->get_id() . '_sync-products' );

				$this->get_plugin()->log( 'Starting manual sync' );

				wp_schedule_single_event( time(), 'konekt_merit_aktiva_cron_job' );
			}
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
			if ( ! wp_next_scheduled( 'konekt_merit_aktiva_cron_job' ) ) {
				wp_schedule_event( time(), 'twicedaily', 'konekt_merit_aktiva_cron_job' );
			}

			add_action( 'konekt_merit_aktiva_cron_job', array( $this, 'cron_hook' ) );
		} else {
			wp_clear_scheduled_hook( 'konekt_merit_aktiva_cron_job' );
		}

	}


	public function cron_hook() {

		$this->get_plugin()->log( 'Starting cron' );

		$time_start  = microtime( true );
		$product_ids = $this->update_warehouse_products( true );

		if ( ! empty( $product_ids ) ) {
			$this->get_plugin()->log( 'Fetching products for an update.' );

			wc_get_products( [
				'include' => $product_ids,
				'type'    => array_merge( [ 'variation' ], wc_get_product_types() ),
				'return'  => 'objects',
				'limit'   => -1,
			] );
		}

		$time_end = microtime( true );

		$this->get_plugin()->log( sprintf( 'Ending cron: %s sec duration', ( $time_end - $time_start ) ) );

	}


	public function get_product_id_by_sku( $product_sku ) {
		$product  = null;
		$products = wc_get_products( [
			'sku'    => $product_sku,
			'limit'  => 1,
			'type'   => array_merge( [ 'variation' ], wc_get_product_types() ),   // also search for variations
			'return' => 'ids',
		] );

		if ( $products && 1 === count( $products ) ) {
			$product = reset( $products );
		}

		return $product;
	}


	public function get_warehouse_products( $warehouse_id ) {
		return $this->get_plugin()->get_cache( 'warehouse_' . $warehouse_id );
	}


	public function update_warehouse_products( $clear_cache = false ) {
		$warehouses  = $this->get_warehouses();
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

						$this->get_plugin()->delete_cache( 'item_stock_' . $product_sku . '@' . $warehouse['id'] );

						$product_id_by_sku = $this->get_product_id_by_sku( $product_sku );
						$product_data      = [
							'Type'         => $product->Type,
							'InventoryQty' => $product->InventoryQty,
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
		);

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // WPCS: XSS ok. ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<a href="<?php echo esc_url( add_query_arg( [ 'nonce' => wp_create_nonce( 'sync-products' ), 'action' => 'sync-products' ] ) ) ?>" class="button"><?php echo esc_html_e( 'Sync products', 'konekt-merit-aktiva' ); ?></a>
					<?php echo $this->get_description_html( $data ); // WPCS: XSS ok. ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
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
							<th>#</th>
							<th><?php esc_html_e( 'Tax ID', 'konekt-merit-aktiva' ); ?></th>
							<th><?php esc_html_e( 'Comment', 'konekt-merit-aktiva' ); ?></th>
							<th><?php esc_html_e( 'Matching tax', 'konekt-merit-aktiva' ); ?></th>
						</tr>
					</thead>
					<tbody>

						<?php foreach ( $external_taxes as $external_tax_id => $tax_comment ) : ?>

							<?php
							$row_counter++;

							$value = $values[$external_tax_id] ?? false;
							?>

							<tr>
								<td><?php echo $row_counter; ?>.</td>
								<td><?php echo esc_html( $external_tax_id ); ?></td>
								<td><?php echo esc_html( $tax_comment ); ?></td>
								<td>
									<select name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $external_tax_id ); ?>]" class="select">

										<option value="0"></option>

										<?php foreach ( $wc_taxes as $wc_tax_id => $wc_tax ) : ?>

											<option value="<?php echo esc_attr( $wc_tax_id ); ?>" <?php selected( $wc_tax_id, $value, true ); ?>><?php echo esc_html( $wc_tax['label'] ); ?></option>

										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>

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

		foreach ( $this->get_all_tax_rates() as $rate_id => $rate ) {

			if ( $wc_tax_class == $rate['slug'] ) {
				$tax_rate_id = $rate_id;

				break;
			}
		}

		if ( $tax_rate_id ) {
			$current_taxes = (array) $this->get_option( 'taxes', [] );

			foreach ( $current_taxes as $tax_code => $wc_tax_id ) {
				if ( $tax_rate_id == $wc_tax_id ) {
					$tax = $tax_code;

					break;
				}
			}
		}

		return $tax;
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
