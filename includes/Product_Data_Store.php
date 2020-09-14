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
		$warehouses = $this->get_integration()->get_warehouses();
		$quantities = [];

		foreach ( $warehouses as $warehouse_id ) {

			$stock_cache_key = $this->get_stock_cache_key( $product->get_sku(), $warehouse_id );

			if ( false === ( $cached = $this->get_plugin()->get_cache( $stock_cache_key ) ) ) {
				$item_stock = $this->get_api()->get_item_stock( $product->get_sku(), $warehouse_id );

				if ( empty( $item_stock ) ) {
					continue;
				}

				if ( 'Laokaup' !== $item_stock->Type ) {
					$quantities = null;

					$product->set_manage_stock( false );

					break;
				}

				$product->set_manage_stock( true );

				if ( $item_stock->InventoryQty ) {
					$quantities[] = [
						'location' => $warehouse_id,
						'quantity' => wc_stock_amount( $item_stock->InventoryQty ),
					];
				}

				$this->get_plugin()->set_cache( $stock_cache_key, $item_stock, MINUTE_IN_SECONDS * intval( $this->get_integration()->get_option( 'stock_refresh_rate', 15 ) ) );
			}
		}

		if ( null !== $quantities && $product->managing_stock() ) {
			$total_quantity = 0;

			foreach ( $quantities as $quantity ) {
				$total_quantity += $quantity['quantity'];
			}

			// Save quantities to meta
			$this->get_plugin()->add_product_meta(
				$product,
				[
					'quantities_by_warehouse' => $quantities
				]
			);

			$new_stock_count = wc_stock_amount( $total_quantity );

			if ( $new_stock_count != $product->get_stock_quantity() ) {
				$this->update_product_stock( $product->get_id(), $new_stock_count, 'set' );
			}

			if ( $new_stock_count > 0 && 'instock' !== $product->get_stock_status() ) {
				$product->set_stock_status( 'instock' );
			}

		}

		if ( ! empty( $product->get_changes() ) ) {
			$product->save();
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
				// Update data
			}

			$this->get_plugin()->set_cache( $item_cache_key, $item, DAY_IN_SECONDS * intval( $this->get_integration()->get_option( 'product_refresh_rate', 30 ) ) );
		}
	}


	public function get_stock_cache_key( $product_sku, $warehouse_id ) {
		return 'item_stock_' . $product_sku . '@' . $warehouse_id;
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