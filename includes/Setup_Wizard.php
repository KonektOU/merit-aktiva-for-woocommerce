<?php
/**
 * Setup wizard
 *
 * What a shop has to get right before this plugin does anything at all: the
 * API keys, and what it is meant to exchange with Merit. Both are on the
 * settings screen too, among five other sections - which is fine for changing
 * one thing later and no way to start.
 *
 * The rest of the settings are deliberately not here. Warehouses, tax codes and
 * payment methods are chosen from lists Merit itself provides, and a wizard is
 * the worst place to meet an empty list.
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva;

use SkyVerge\WooCommerce\PluginFramework\v6_2_4 as Framework;

defined( 'ABSPATH' ) or exit;

class Setup_Wizard extends Framework\Admin\Setup_Wizard {


	/**
	 * Register the steps, in the order they are walked.
	 *
	 * @return void
	 */
	protected function register_steps() {

		$this->register_step(
			'connect',
			__( 'Connect', 'konekt-merit-aktiva' ),
			array( $this, 'render_connect_step' ),
			array( $this, 'save_connect_step' )
		);

		$this->register_step(
			'sync',
			__( 'What to exchange', 'konekt-merit-aktiva' ),
			array( $this, 'render_sync_step' ),
			array( $this, 'save_sync_step' )
		);
	}


	/**
	 * The API credentials.
	 *
	 * @return void
	 */
	public function render_connect_step() {
		$integration = $this->get_integration();

		?>
		<p>
			<?php
			printf(
				/* translators: %1$s: opening link tag, %2$s: closing link tag. */
				esc_html__( 'The keys come from Merit Aktiva itself, under Settings → General settings → API. %1$sMerit\'s own instructions%2$s explain where to find them.', 'konekt-merit-aktiva' ),
				'<a href="https://www.merit.ee/juhendid/aktiva-api/" target="_blank" rel="noopener noreferrer">',
				'</a>'
			);
			?>
		</p>
		<?php

		$this->render_form_field(
			'api_localization',
			array(
				'label'    => __( 'Merit Aktiva version', 'konekt-merit-aktiva' ),
				'type'     => 'select',
				'options'  => array(
					'estonian' => __( 'Estonian', 'konekt-merit-aktiva' ),
					'finnish'  => __( 'Finnish', 'konekt-merit-aktiva' ),
					'polish'   => __( 'Polish', 'konekt-merit-aktiva' ),
				),
				'required' => true,
			),
			$integration->get_option( 'api_localization', 'estonian' )
		);

		$this->render_form_field(
			'api_id',
			array(
				'label'    => __( 'API ID', 'konekt-merit-aktiva' ),
				'type'     => 'text',
				'required' => true,
			),
			$integration->get_option( 'api_id', '' )
		);

		$this->render_form_field(
			'api_key',
			array(
				'label'    => __( 'API key', 'konekt-merit-aktiva' ),
				// A credential, so not left sitting legibly on a screen that
				// somebody may well be sharing while they set this up.
				'type'     => 'password',
				'required' => true,
			),
			$integration->get_option( 'api_key', '' )
		);

		?>
		<p class="description">
			<?php esc_html_e( 'The keys are checked against Merit Aktiva before this step is accepted, so you will know here rather than later if something is wrong with them.', 'konekt-merit-aktiva' ); ?>
		</p>
		<?php
	}


	/**
	 * Try the credentials, and keep them if they work.
	 *
	 * Merit is asked one question with the keys just entered. A wizard that
	 * accepted them and left the shop to find out weeks later that nothing had
	 * ever synced would be worse than no wizard.
	 *
	 * Tried before they are saved, and saved only if Merit answers: a set of
	 * keys that has just been refused is not an improvement on the set that was
	 * there before, and overwriting the old ones with them loses the only
	 * working thing on the screen.
	 *
	 * @throws Framework\SV_WC_Plugin_Exception When Merit will not answer.
	 *
	 * @return void
	 */
	public function save_connect_step() {
		$integration  = $this->get_integration();
		$localization = sanitize_key( Framework\SV_WC_Helper::get_posted_value( 'api_localization' ) );

		$settings = array(
			'api_localization' => in_array( $localization, array( 'estonian', 'finnish', 'polish' ), true ) ? $localization : 'estonian',
			'api_id'           => sanitize_text_field( Framework\SV_WC_Helper::get_posted_value( 'api_id' ) ),
			'api_key'          => sanitize_text_field( Framework\SV_WC_Helper::get_posted_value( 'api_key' ) ),
		);

		if ( '' === $settings['api_id'] || '' === $settings['api_key'] ) {
			throw new Framework\SV_WC_Plugin_Exception( __( 'Both the API ID and the API key are needed.', 'konekt-merit-aktiva' ) );
		}

		$saved = $integration->settings;

		// In memory only: the API reads the keys from the integration when it
		// is built, so this is enough to try them with.
		$integration->settings = array_merge( (array) $saved, $settings );

		$integration->reload_api();
		$integration->clear_api_failure();

		$integration->get_api()->get_banks();

		$failure = $integration->get_api_failure();

		if ( $failure ) {
			$integration->settings = $saved;

			$integration->reload_api();

			throw new Framework\SV_WC_Plugin_Exception( wp_strip_all_tags( $integration->api_failure_message( $failure ) ) );
		}

		$this->update_integration_settings( $settings );

		$integration->reload_api();
	}


	/**
	 * What the shop wants exchanged.
	 *
	 * @return void
	 */
	public function render_sync_step() {
		$integration = $this->get_integration();

		?>
		<p><?php esc_html_e( 'Each of these can be changed later, and each can be left off.', 'konekt-merit-aktiva' ); ?></p>
		<?php

		$this->render_form_field(
			'invoice_sync_allowed',
			array(
				'label'       => __( 'Invoices', 'konekt-merit-aktiva' ),
				'type'        => 'toggle',
				'description' => __( 'Send invoices to Merit Aktiva. One is created when an order reaches the status chosen below.', 'konekt-merit-aktiva' ),
			),
			'yes' === $integration->get_option( 'invoice_sync_allowed', 'no' )
		);

		$statuses = array();

		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$statuses[ str_replace( 'wc-', '', $slug ) ] = $label;
		}

		$this->render_form_field(
			'invoice_sync_status',
			array(
				'label'    => __( 'Invoice on order status', 'konekt-merit-aktiva' ),
				'type'     => 'select',
				'options'  => $statuses,
				// Required, or woocommerce_form_field() writes "(optional)"
				// after the label - and it is not optional: an order status is
				// always chosen, this one merely has a sensible default.
				'required' => true,
			),
			$integration->get_option( 'invoice_sync_status', 'processing' )
		);

		$this->render_form_field(
			'stock_sync_allowed',
			array(
				'label'       => __( 'Stock levels', 'konekt-merit-aktiva' ),
				'type'        => 'toggle',
				'description' => __( 'Take stock levels from Merit Aktiva, matching products by SKU. Which warehouses to count is chosen on the settings screen.', 'konekt-merit-aktiva' ),
			),
			'yes' === $integration->get_option( 'stock_sync_allowed', 'no' )
		);

		$this->render_form_field(
			'product_sync_allowed',
			array(
				'label'       => __( 'Product data', 'konekt-merit-aktiva' ),
				'type'        => 'toggle',
				'description' => __( 'Take product data from Merit Aktiva: names and prices follow Merit rather than WooCommerce.', 'konekt-merit-aktiva' ),
			),
			'yes' === $integration->get_option( 'product_sync_allowed', 'no' )
		);
	}


	/**
	 * @return void
	 */
	public function save_sync_step() {
		$status = sanitize_key( Framework\SV_WC_Helper::get_posted_value( 'invoice_sync_status' ) );

		$this->update_integration_settings(
			array(
				'invoice_sync_allowed' => Framework\SV_WC_Helper::get_posted_value( 'invoice_sync_allowed' ) ? 'yes' : 'no',
				'invoice_sync_status'  => array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ? $status : 'processing',
				'stock_sync_allowed'   => Framework\SV_WC_Helper::get_posted_value( 'stock_sync_allowed' ) ? 'yes' : 'no',
				'product_sync_allowed' => Framework\SV_WC_Helper::get_posted_value( 'product_sync_allowed' ) ? 'yes' : 'no',
			)
		);
	}


	/**
	 * @return void
	 */
	protected function render_before_next_steps() {

		?>
		<p>
			<?php esc_html_e( 'Warehouses, tax codes and payment methods are matched up on the settings screen: those lists come from Merit Aktiva, and they are worth going through before the first invoice is sent.', 'konekt-merit-aktiva' ); ?>
		</p>
		<?php
	}


	/**
	 * Where to go from the finished screen.
	 *
	 * @return array
	 */
	protected function get_next_steps() {
		$steps = parent::get_next_steps();

		$steps['settings'] = array(
			'name'         => __( 'Finish the setup', 'konekt-merit-aktiva' ),
			'label'        => __( 'Match warehouses, taxes and payment methods', 'konekt-merit-aktiva' ),
			'url'          => $this->get_plugin()->get_settings_url(),
			'button_class' => 'button button-primary',
		);

		return $steps;
	}


	/**
	 * The buttons under the next steps.
	 *
	 * The framework offers "Review Your Settings" here, which is where the
	 * step above already sends people - two buttons, one destination, one of
	 * them explaining itself and the other not. Only the explained one stays.
	 *
	 * @return array
	 */
	protected function get_additional_actions() {
		$actions  = parent::get_additional_actions();
		$settings = $this->get_plugin()->get_settings_url();

		foreach ( $actions as $label => $url ) {
			if ( $url === $settings ) {
				unset( $actions[ $label ] );
			}
		}

		return $actions;
	}


	/**
	 * Write settings without disturbing the rest of them.
	 *
	 * @param array $settings Settings to change.
	 *
	 * @return void
	 */
	private function update_integration_settings( $settings ) {
		$integration = $this->get_integration();

		update_option(
			$integration->get_option_key(),
			array_merge( (array) get_option( $integration->get_option_key(), array() ), $settings )
		);

		$integration->init_settings();
	}


	/**
	 * @return \Konekt\WooCommerce\Merit_Aktiva\Integration
	 */
	private function get_integration() {

		return $this->get_plugin()->get_integration();
	}
}
