<?php
/**
 * Lifecycle class
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva;

use SkyVerge\WooCommerce\PluginFramework\v5_6_1 as Framework;

defined( 'ABSPATH' ) or exit;

class Lifecycle extends Framework\Plugin\Lifecycle {

	protected $upgrade_versions = [
		'1.0.34',
		'1.0.36',
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

	public function upgrade_to_1_0_36( $installed_version ) {
		global $wpdb;

		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$create_table = "
			CREATE TABLE {$wpdb->prefix}wc_merit_aktiva_product_lookup (
				`sku` varchar(100),
				`item_id` varchar(100) NULL default NULL,
				`stock_quantity` double NULL default NULL,
				`product_type` varchar(100) NULL default NULL,
				`location_code` bigint(20) NULL default 0,
				`uom_name` varchar(100) NULL default NULL,
				PRIMARY KEY  (`sku`, `location_code`),
				KEY `location_code` (`location_code`),
				KEY `item_id` (`item_id`)
			) $collate;
		";

		dbDelta( $create_table );
	}
}