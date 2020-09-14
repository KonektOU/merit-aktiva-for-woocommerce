<?php
/**
 * Integration
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva;

defined( 'ABSPATH' ) or exit;

class Product_Data_Store extends \WC_Product_Data_Store_CPT implements \WC_Object_Data_Store_Interface, \WC_Product_Data_Store_Interface {


	public function read( &$product ) {
		parent::read( $product );

		if ( ! empty( $product->get_sku() ) ) {

			if ( 'yes' === $this->get_integration()->get_option( 'stock_sync_allowed', 'no' ) ) {
				$this->refetch_product_stock( $product );
			}

			if ( 'yes' === $this->get_integration()->get_option( 'product_sync_allowed', 'no' ) ) {
				$this->refetch_product_data( $product );
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
	private function refetch_product_stock( &$product ) {
		$stock_cache_key = $this->get_stock_cache_key( $product->get_sku() );

		if ( false === ( $cached = $this->get_plugin()->get_cache( $stock_cache_key ) ) ) {
			$item_stock = $this->get_api()->get_item_stock( $product->get_sku() );

			if ( $item_stock ) {
				$new_stock_count = wc_stock_amount( $item_stock->Instock );

				if ( $new_stock_count != $product->get_stock_quantity() ) {
					$this->update_product_stock( $product->get_id(), $new_stock_count, 'set' );
				}

				if ( ! $product->managing_stock() ) {
					$product->set_manage_stock( true );
					$product->set_stock_status( $new_stock_count > 0 ? 'instock' : 'outofstock' );
				}

				if ( $new_stock_count > 0 && 'instock' !== $product->get_stock_status() ) {
					$product->set_stock_status( 'instock' );
				}

				if ( ! empty( $product->get_changes() ) ) {
					$product->save();
				}

			}

			$this->get_plugin()->set_cache( $stock_cache_key, $item_stock, MINUTE_IN_SECONDS * intval( $this->get_integration()->get_option( 'stock_refresh_rate', 15 ) ) );
		}
	}


	/**
	 * Fetch product data from API and update it
	 *
	 * @param \WC_Product $product
	 *
	 * @return void
	 */
	private function refetch_product_data( &$product ) {

		$item_cache_key = $this->get_item_cache_key( $product->get_sku() );

		if ( false === ( $cached = $this->get_plugin()->get_cache( $item_cache_key ) ) ) {
			$item = $this->get_api()->get_item( $product->get_sku() );

			if ( $item ) {
				if ( $item->VATCode ) {
					$available_taxes = $this->get_integration()->get_option( 'taxes', [] );

					if ( array_key_exists( $item->VATCode, $available_taxes ) ) {
						$woocommerce_tax_id    = (int) $available_taxes[ $item->VATCode ];
						$woocommerce_tax_class = '';

						foreach ( $this->get_integration()->get_all_tax_rates() as $tax_id => $tax ) {
							if ( (int) $tax_id === $woocommerce_tax_id ) {
								$woocommerce_tax_class = $tax['slug'];

								break;
							}
						}

						if ( ! empty( $woocommerce_tax_class ) ) {
							if ( $woocommerce_tax_class !== $product->get_tax_class() ) {
								$product->set_tax_class( $woocommerce_tax_class );
								$product->save();
							}
						}
					}
				}
			}

			//$this->get_plugin()->set_cache( $item_cache_key, $item, DAY_IN_SECONDS * intval( $this->get_integration()->get_option( 'product_refresh_rate', 30 ) ) );
		}
	}


	public function get_stock_cache_key( $product_sku ) {
		return 'item_stock_' . $product_sku;
	}


	public function get_item_cache_key( $product_sku ) {
		return 'item_' . $product_sku;
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
		return $this->get_plugin()->get_integration()->get_api();
	}


	/**
	 * Get integration
	 *
	 * @return Konekt\WooCommerce\Merit_Aktiva\Integration
	 */
	protected function get_integration() {
		return $this->get_plugin()->get_integration();
	}


}