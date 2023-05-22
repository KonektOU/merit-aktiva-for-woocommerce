<?php
/**
 * Plugin base class
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva;

defined( 'ABSPATH' ) or exit;

use SkyVerge\WooCommerce\PluginFramework\v5_6_1 as Framework;

/**
 * @since 1.0.0
 */
class Plugin extends Framework\SV_WC_Plugin {


	/** @var Plugin */
	protected static $instance;

	/** plugin version number */
	const VERSION = '1.0.36';

	/** plugin id */
	const PLUGIN_ID = 'wc-merit-aktiva';

	/** @var string shipping method id */
	const SHIPPING_METHOD_ID = 'konekt-merit-aktiva-shipping';

	/** @var string the integration class name */
	const INTEGRATION_CLASS = '\\Konekt\\WooCommerce\\Merit_Aktiva\\Integration';

	/** @var string the orders class name */
	const ORDERS_CLASS = '\\Konekt\\WooCommerce\\Merit_Aktiva\\Orders';

	/** @var string the data store class name */
	const DATASTORE_CLASS = '\\Konekt\\WooCommerce\\Merit_Aktiva\\Product_Data_Store';

	/** @var string the shipping method class name */
	const SHIPPING_CLASS = '\\Konekt\\WooCommerce\\Merit_Aktiva\\Shipping_Method';

	/** @var \Konekt\WooCommerce\Merit_Aktiva\Integration the integration class instance */
	private $integration;

	/** @var \Konekt\WooCommerce\Merit_Aktiva\Orders the orders class instance */
	private $orders;

	/** @var string cache transient prefix */
	private $cache_prefix;


	/**
	 * Constructs the plugin.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		$this->cache_prefix = 'wc_' . self::PLUGIN_ID . '_cache_';

		parent::__construct(
			self::PLUGIN_ID,
			self::VERSION,
			array(
				'text_domain' => 'konekt-merit-aktiva',
			)
		);
	}


	/**
	 * Initializes the plugin.
	 *
	 * @since 1.0.0
	 */
	public function init_plugin() {

		$this->load_integration();

		// Add integration
		add_filter( 'woocommerce_integrations', array( $this, 'load_integration' ) );

		// Add custom data store
		add_filter( 'woocommerce_data_stores', array( $this, 'load_product_data_store' ) );

		// Custom hook
		add_filter( self::PLUGIN_ID . '_product_quantities_by_warehouse', array( $this, 'attach_product_quantities_by_warehouse' ), 10, 2 );
		add_filter( self::PLUGIN_ID . '_warehouse_title', array( $this, 'attach_warehouse_title' ), 10, 2 );
	}


	/**
	 * Loads integration
	 *
	 * @param array $integrations
	 *
	 * @return array
	 */
	public function load_integration( $integrations = [] ) {

		if ( ! class_exists( self::INTEGRATION_CLASS ) ) {
			require_once( $this->get_plugin_path() . '/includes/Integration.php' );
		}

		if ( ! in_array( self::INTEGRATION_CLASS, $integrations, true ) ) {
			$integrations[ self::PLUGIN_ID ] = self::INTEGRATION_CLASS;
		}

		return $integrations;
	}


	public function load_product_data_store( $stores = [] ) {

		if ( ! class_exists( self::DATASTORE_CLASS ) ) {
			require_once( $this->get_plugin_path() . '/includes/Product_Data_Store.php' );
			require_once( $this->get_plugin_path() . '/includes/Data_Stores/Product.php' );
			require_once( $this->get_plugin_path() . '/includes/Data_Stores/Product_Variable.php' );
			require_once( $this->get_plugin_path() . '/includes/Data_Stores/Product_Variation.php' );
		}

		$base_store = self::DATASTORE_CLASS;

		$stores['product']           = new Data_Stores\Product( new $base_store ) ;
		$stores['product-variable']  = new Data_Stores\Product_Variable( new $base_store ) ;
		$stores['product-variation'] = new Data_Stores\Product_Variation( new $base_store ) ;

		return $stores;
	}


	/**
	 * Returns the integration class instance.
	 *
	 * @since 1.0.0
	 *
	 * @return \Konekt\WooCommerce\Merit_Aktiva\Integration the integration class instance
	 */
	public function get_integration() {

		if ( null === $this->integration ) {

			$integrations = null === WC()->integrations ? [] : WC()->integrations->get_integrations();
			$integration  = self::INTEGRATION_CLASS;

			if ( isset( $integrations[ self::PLUGIN_ID ] ) && $integrations[ self::PLUGIN_ID ] instanceof $integration ) {

				$this->integration = $integrations[ self::PLUGIN_ID ];

			} else {

				$this->load_integration();

				$this->integration = new $integration();
			}
		}

		return $this->integration;
	}


	/**
	 * Get prefixed meta key for order meta
	 *
	 * @param string $meta_key
	 *
	 * @return string
	 */
	public function get_meta_key( $meta_key ) {
		return $this->get_id() . '_' . $meta_key;
	}


	/**
	 * Add product meta
	 *
	 * @param \WC_Product $product
	 * @param string|array $meta
	 * @param string $value
	 *
	 * @return void
	 */
	public function add_product_meta( \WC_Product $product, $meta, $value = null ) {

		if ( is_array( $meta ) ) {
			foreach ( $meta as $key => $value ) {
				$product->update_meta_data( $this->get_meta_key( $key ), $value );
			}

		} else {
			$product->update_meta_data( $this->get_meta_key( $meta ), $value );
		}

		$product->save_meta_data();
	}


	public function get_product_meta( \WC_Product $product, $meta_key ) {

		return $product ? $product->get_meta( $this->get_meta_key( $meta_key ), true ) : null;
	}


	public function remove_product_meta( \WC_Product $product, $meta_keys ) {

		foreach ( $meta_keys as $meta_key ) {
			$product->delete_meta_data( $this->get_meta_key( $meta_key ) );
		}

		$product->save_meta_data();
	}


	public function attach_product_quantities_by_warehouse( $quantities, $product ) {
		if ( ! $product ) {
			return [];
		}

		$product_in_warehouses = $this->get_integration()->get_product_from_lookup_table( $product->get_sku() );
		$quantities            = [];

		if ( empty( $product_in_warehouses ) ) {
			$product_in_warehouses = [];
		}

		foreach ( $this->get_integration()->get_warehouses() as $warehouse ) {
			$warehouse_quantity = 0;

			foreach ( $product_in_warehouses as $product ) {
				if ( $product['location_code'] == $warehouse['id'] ) {
					$warehouse_quantity = $product['stock_quantity'];

					break;
				}
			}

			$quantities[] = [
				'location_title' => $this->attach_warehouse_title( '', $warehouse['id'] ),
				'location'       => $warehouse['id'],
				'quantity'       => $warehouse_quantity,
			];
		}

		return $quantities;
	}


	public function attach_warehouse_title( $title, $warehouse_id ) {
		$warehouses = $this->get_integration()->get_warehouses();

		foreach ( $warehouses as $warehouse ) {
			if ( $warehouse['id'] == $warehouse_id ) {
				$title = $warehouse['title'];
			}
		}

		return $title;
	}


	public function get_option( $name ) {
		return get_option( 'wc_' . $this->get_id() . '_' . $name );
	}


	public function update_option( $name, $value ) {
		update_option( 'wc_' . $this->get_id() . '_' . $name, $value, 'no' );
	}


	public function get_order_warehouse_id( $order ) {
		$location_code = null;

		// Look for location code from shipping method
		if ( $order->has_shipping_method( self::SHIPPING_METHOD_ID ) ) {
			foreach ( $order->get_shipping_methods() as $shipping_method ) {
				if ( self::SHIPPING_METHOD_ID === $shipping_method->get_method_id() ) {
					$location_code = $shipping_method->get_meta( 'warehouse_location_id', true );

					break;
				}
			}
		}

		return $location_code;
	}


	/**
	 * Add order meta
	 *
	 * @param \WC_Order $order
	 * @param string|array $meta
	 * @param string $value
	 *
	 * @return void
	 */
	public function add_order_meta( \WC_Order $order, $meta, $value = null ) {

		if ( is_array( $meta ) ) {
			foreach ( $meta as $key => $value ) {
				$order->update_meta_data( $this->get_meta_key( $key ), $value );
			}

		} elseif ( is_array( $value ) ) {
			foreach ( $value as $meta_value ) {
				$order->add_meta_data( $this->get_meta_key( $meta ), $meta_value, false );
			}
		} else {
			$order->update_meta_data( $this->get_meta_key( $meta ), $value );
		}

		$order->save_meta_data();
	}


	/**
	 * Get order meta
	 *
	 * @param \WC_Order $order
	 * @param string|array $meta
	 * @param string $value
	 *
	 * @return void
	 */
	public function get_order_meta( \WC_Order $order, $meta_key ) {

		return $order->get_meta( $this->get_meta_key( $meta_key ), true );
	}


	/**
	 * Remove order meta
	 *
	 * @param WC_Order $order
	 * @param array $meta_keys
	 *
	 * @return void
	 */
	public function remove_order_meta( \WC_Order $order, $meta_keys ) {

		foreach ( $meta_keys as $meta_key ) {
			$order->delete_meta_data( $this->get_meta_key( $meta_key ) );
		}

		$order->save_meta_data();
	}


	/**
	 * Add note to order
	 *
	 * @param \WC_Order $order
	 * @param string $message
	 *
	 * @return void
	 */
	public function add_order_note( \WC_Order $order, $message ) {

		$order->add_order_note( sprintf( '%s: %s', $this->get_integration()->get_method_title(), $message ) );
	}


	/**
	 * Get cached item
	 *
	 * @param string $cache_key
	 *
	 * @return mixed
	 */
	public function get_cache( $cache_key ) {
		return get_transient( $this->cache_prefix . $cache_key );
	}


	/**
	 * Add item to cache
	 *
	 * @param string $cache_key
	 * @param mixed $data
	 * @param int $expiration
	 *
	 * @return bool
	 */
	public function set_cache( $cache_key, $data, $expiration ) {
		return set_transient( $this->cache_prefix . $cache_key, $data, $expiration );
	}


	/**
	 * Delete item from cache
	 *
	 * @param string $cache_key
	 *
	 * @return bool
	 */
	public function delete_cache( $cache_key ) {
		return delete_transient( $this->cache_prefix . $cache_key );
	}


	public function add_notice( $name, $message ) {
		if ( \WC_Admin_Notices::has_notice( $name ) ) {
			\WC_Admin_Notices::remove_notice( $name );
		}

		\WC_Admin_Notices::add_custom_notice( $name, sprintf( '%s: %s', $this->get_plugin_name(), $message ) );
	}


	public function schedule_action( $action, $data = [], $recurring = null, $next_run = null ) {
		if ( ! as_next_scheduled_action( $this->get_id() . '_' . $action, $data, $this->get_id() ) ) {
			if ( null !== $recurring || null !== $recurring ) {
				as_schedule_recurring_action( $next_run ?? time(), $recurring, $this->get_id()  . '_' . $action, $data, $this->get_id() );
			}
			else {
				as_enqueue_async_action( $this->get_id() . '_' . $action, $data, $this->get_id() );
			}
		}
	}


	public function has_scheduled_action( $action, $data = [] ) {
		return as_has_scheduled_action( $this->get_id() . '_' . $action, $data, $this->get_id() );
	}


	public function hook_action( $action, $hook, $priority = 10, $accepted_args = 1 ) {
		add_action( $this->get_id() . '_' . $action, $hook, $priority, $accepted_args );
	}


	public function unschedule_all_actions( $action, $data = null ) {
		as_unschedule_all_actions( $this->get_id() . '_' . $action, $data, $this->get_id() );
	}


	public function log_action( $message, $action = null ) {
		if ( $action ) {
			$this->log( $message, $this->get_id() . '_' . $action );
		} else {
			$this->log( $message );
		}
	}


	/**
	 * Gets the URL to the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @see Framework\SV_WC_Plugin::is_plugin_settings()
	 * @param string $_ unused
	 * @return string URL to the settings page
	 */
	public function get_settings_url( $_ = '' ) {

		return admin_url( 'admin.php?page=wc-settings&tab=integration&section=merit_aktiva' );
	}


	/**
	 * Get documentation URL
	 *
	 * @return void
	 */
	public function get_documentation_url() {

		return 'https://konekt.ee/woocommerce/merit-aktiva';
	}


	/**
	 * Gets the plugin full name including "WooCommerce"
	 *
	 * @since 1.0.0
	 *
	 * @return string plugin name
	 */
	public function get_plugin_name() {

		return __( 'Merit Aktiva for WooCommerce', 'konekt-merit-aktiva' );
	}


	/**
	 * Gets the full path and filename of the plugin file.
	 *
	 * @since 1.0.0
	 *
	 * @return string the full path and filename of the plugin file
	 */
	protected function get_file() {

		return __DIR__;
	}


	/**
	 * Initializes the lifecycle handler.
	 *
	 * @since 1.0.0
	 */
	protected function init_lifecycle_handler() {

		require_once( $this->get_plugin_path() . '/includes/Lifecycle.php' );

		$this->lifecycle_handler = new \Konekt\WooCommerce\Merit_Aktiva\Lifecycle( $this );
	}


	/**
	 * Gets the main instance of Framework Plugin instance.
	 *
	 * Ensures only one instance is/can be loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return Plugin
	 */
	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}


}