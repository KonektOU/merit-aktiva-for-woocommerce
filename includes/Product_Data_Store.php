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

			if ( ( 'relative' === $sync_method ) || ( is_product() && 'on-demand' === $sync_method ) || ( 'cron' === $sync_method && did_action( 'konekt_merit_aktiva_cron_job' ) ) || did_action( 'woocommerce_new_product' ) || did_action( 'woocommerce_update_product' ) ) {
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


	public function get_product_from_warehouse( $product_sku, $warehouse_id ) {
		$all_products = $this->get_integration()->get_warehouse_products( $warehouse_id );
		$product      = null;

		if ( $all_products ) {
			if ( array_key_exists( $product_sku, $all_products ) ) {
				$product = $all_products[ $product_sku ];
			}
		}

		return $product;
	}


	/**
	 * Fetch product stock from API and update it
	 *
	 * @param \WC_Product $product
	 *
	 * @return void
	 */
	public function refetch_product_stock( &$product ) {
		$warehouses     = $this->get_integration()->get_warehouses();
		$quantities     = [];
		$total_quantity = 0;

		foreach ( $warehouses as $warehouse ) {

			$stock_cache_key = $this->get_stock_cache_key( $product->get_sku(), $warehouse['id'] );

			if ( did_action( 'konekt_merit_aktiva_cron_job' ) ) {
				$stock_cache = false;
			} else {
				$stock_cache = $this->get_plugin()->get_cache( $stock_cache_key );
			}

			if ( false !== $stock_cache ) {
				$quantities = null;

				break;
			} else {
				$item_stock = $this->get_product_from_warehouse( $product->get_sku(), $warehouse['id'] );

				$this->get_plugin()->set_cache( $stock_cache_key, $item_stock, MINUTE_IN_SECONDS * intval( $this->get_integration()->get_option( 'stock_refresh_rate', 15 ) ) );

				if ( empty( $item_stock ) ) {
					$this->get_plugin()->remove_product_meta( $product, [ 'item_id', 'uom_name' ] );

					continue;
				}

				if ( 'Laokaup' !== $item_stock->Type ) {
					$quantities = null;

					$product->set_manage_stock( false );

					break;
				}

				// Manage stock
				$product->set_manage_stock( true );

				// Save item ID
				$this->get_plugin()->add_product_meta( $product, [
					'item_id'  => $item_stock->ItemId,
					'uom_name' => $item_stock->UnitofMeasureName,
				] );

				if ( $item_stock->InventoryQty ) {
					$total_quantity += (int) $item_stock->InventoryQty;

					$quantities[] = [
						'location' => $warehouse['id'],
						'quantity' => wc_stock_amount( $item_stock->InventoryQty ),
					];
				}
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
		return $this->get_integration()->get_api();
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