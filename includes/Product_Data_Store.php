<?php
/**
 * Base Data Store
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva;

defined( 'ABSPATH' ) or exit;

class Product_Data_Store {


	public function read( $product ) {

		if ( $product->get_sku() ) {
			$sync_method = $this->get_integration()->get_option( 'sync_method' );

			if ( ( 'relative' === $sync_method ) || ( is_product() && 'on-demand' === $sync_method ) || ( 'cron' === $sync_method && did_action( 'konekt_merit_aktiva_cron_job' ) ) ) {
				$this->get_integration()->update_warehouse_products();

				if ( 'yes' === $this->get_integration()->get_option( 'stock_sync_allowed', 'no' ) ) {
					$this->refetch_product_stock( $product );
				}

				if ( 'yes' === $this->get_integration()->get_option( 'product_sync_allowed', 'no' ) ) {
					$this->refetch_product_data( $product );
				}
			}
		}
	}


	/**
	 * Fetch product stock from API and update it
	 *
	 * @param \WC_Product $product
	 *
	 * @return void
	 */
	public function refetch_product_stock( &$product ) {
		$this->get_integration()->update_product_stock_data( $product );
	}


	/**
	 * Fetch product data from API and update it
	 *
	 * @param \WC_Product $product
	 *
	 * @return void
	 */
	private function refetch_product_data( &$product ) {

		if ( did_action( 'konekt_merit_aktiva_create_products' ) ) {
			return;
		}

		$item_cache_key = $this->get_item_cache_key( $product->get_sku() );

		if ( false === ( $cached = $this->get_plugin()->get_cache( $item_cache_key ) ) ) {
			$item = $this->get_api()->get_item( $product->get_sku() );

			if ( $item ) {
				// Update data
			} else {
				if ( 'yes' === $this->get_integration()->get_option( 'product_create_allowed', 'no' ) ) {
					$this->get_api()->create_products( [ $product ] );

					// Force refetching data
					$item = -1;
				}
			}

			$this->get_plugin()->set_cache( $item_cache_key, $item, DAY_IN_SECONDS * intval( $this->get_integration()->get_option( 'product_refresh_rate', 30 ) ) );
		}
	}


	public function get_item_cache_key( $product_sku ) {
		return 'item_' . $product_sku;
	}


	/**
	 * Get plugin
	 *
	 * @return \Konekt\WooCommerce\Merit_Aktiva\Plugin
	 */
	protected function get_plugin() {
		return wc_konekt_woocommerce_merit_aktiva();
	}


	/**
	 * Get API connector
	 *
	 * @return \Konekt\WooCommerce\Merit_Aktiva\API
	 */
	protected function get_api() {
		return $this->get_integration()->get_api();
	}


	/**
	 * Get integration
	 *
	 * @return \Konekt\WooCommerce\Merit_Aktiva\Integration
	 */
	protected function get_integration() {
		return $this->get_plugin()->get_integration();
	}


}