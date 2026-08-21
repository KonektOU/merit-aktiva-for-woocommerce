<?php
/**
 * Lifecycle class
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva;

use SkyVerge\WooCommerce\PluginFramework\v6_2_4 as Framework;

defined( 'ABSPATH' ) or exit;

class Lifecycle extends Framework\Plugin\Lifecycle {

	protected $upgrade_versions = [
		'1.0.34',
		'1.0.36',
		'1.1',
		'1.1.1',
	];

	public function upgrade_to_1_0_34( $installed_version ) {
		$orders = wc_get_orders( array(
			'return'                    => 'objects',
			'merit_aktiva_invoice_id'   => 'any',
			'merit_aktiva_warehouse_id' => '',
		) );

		foreach ( $orders as $order ) {
			$this->get_plugin()->remove_order_meta( $order, 'warehouse_id' );

			$api_order = $this->get_plugin()->get_integration()->get_orders()->get_api_order( $order );

			if ( $api_order ) {
				$warehouses = [];

				foreach ( $api_order->Lines as $order_line ) {
					if ( ! empty( $order_line->LocationCode ) ) {
						$warehouses[] = $order_line->LocationCode;
					}
				}

				$this->get_plugin()->add_order_meta( $order, 'warehouse_id', $warehouses );
			}
		}
	}

	/**
	 * A fresh install needs the lookup table too.
	 *
	 * It was created only by the upgrade to 1.0.36, so a site installing the
	 * plugin for the first time never got one: every stock lookup then failed
	 * against a table that was not there, and each product fell back to asking
	 * the Merit API one at a time.
	 *
	 * @return void
	 */
	protected function install() {

		$this->create_lookup_table();
	}


	public function upgrade_to_1_0_36( $installed_version ) {

		$this->create_lookup_table();
	}


	/**
	 * Every existing site needs the lookup table built as well.
	 *
	 * The statement that was supposed to create it in 1.0.36 was rejected by
	 * MySQL - a primary key over nullable columns - so no site has ever had
	 * one, upgraded or not. This runs the corrected statement everywhere.
	 *
	 * @param string $installed_version Version being upgraded from.
	 *
	 * @return void
	 */
	public function upgrade_to_1_1_1( $installed_version ) {

		$this->create_lookup_table();
	}


	/**
	 * The table stock and item data is kept in, per warehouse.
	 *
	 * dbDelta is safe to run against an existing table - it compares and only
	 * applies what differs - so install and upgrade can share this.
	 *
	 * Both key columns are NOT NULL because they are the primary key, and MySQL
	 * refuses a primary key over a nullable column: as written the CREATE
	 * failed outright, which is the other half of why sites had no table.
	 *
	 * @return void
	 */
	protected function create_lookup_table() {
		global $wpdb;

		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$create_table = "
			CREATE TABLE {$wpdb->prefix}wc_merit_aktiva_product_lookup (
				`sku` varchar(100) NOT NULL default '',
				`item_id` varchar(100) NULL default NULL,
				`stock_quantity` double NULL default NULL,
				`product_type` varchar(100) NULL default NULL,
				`location_code` bigint(20) NOT NULL default 0,
				`uom_name` varchar(100) NULL default NULL,
				PRIMARY KEY  (`sku`, `location_code`),
				KEY `location_code` (`location_code`),
				KEY `item_id` (`item_id`)
			) $collate;
		";

		dbDelta( $create_table );
	}
}