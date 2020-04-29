<?php
/**
 * Helper functions
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

use Konekt\WooCommerce\Merit_Aktiva\Plugin;


/**
 * @since 1.0.0
 *
 * @return \Konekt\WooCommerce\Merit_Aktiva\Plugin
 */
function wc_konekt_woocommerce_merit_aktiva() {

	return Plugin::instance();
}