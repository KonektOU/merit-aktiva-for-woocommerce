<?php
/**
 * API functionality
 *
 * @package Merit Aktiva for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Merit_Aktiva;

use SkyVerge\WooCommerce\PluginFramework\v5_6_1 as Framework;

defined( 'ABSPATH' ) or exit;

class API extends Framework\SV_WC_API_Base {

	const ITEM_TYPE_STOCK_ITEM = 1;

	const ITEM_TYPE_SERVICE = 2;

	const ITEM_TYPE_ITEM = 3;


	/**
	 * API URL based on language
	 *
	 * @var array
	 */
	private $api_urls = [
		'estonian' => 'https://aktiva.merit.ee/api/v1/',
		'finnish'  => 'https://aktiva.meritaktiva.fi/api/v1/',
		'polish'   => 'https://program.360ksiegowosc.pl/api/v1/',
	];

	/** @var \Konekt\WooCommerce\Merit_Aktiva\Integration the integration class instance */
	private $integration;


	/**
	 * API constructor
	 *
	 * @param \Konekt\WooCommerce\Merit_Aktiva\Integration $integration
	 */
	public function __construct( $integration ) {

		$this->integration = $integration;

		$this->api_id      = $this->integration->get_option( 'api_id' );
		$this->api_key     = $this->integration->get_option( 'api_key' );
		$this->request_uri = $this->api_urls[ $this->integration->get_option( 'api_localization' ) ];

		$this->set_request_content_type_header( 'application/json' );
		$this->set_request_accept_header( 'application/json' );

		$this->response_handler = API\Response::class;

		add_action( 'requests-requests.before_request', array( $this, 'maybe_set_data_format_to_body' ), 999, 5 );
	}


	/**
	 * Create invoice
	 *
	 * @param \WC_Order $order
	 * @param bool $set_default_location
	 * @param null|\WC_Order_Refund $refund
	 *
	 * @return bool
	 */
	public function create_invoice( $order, $set_default_location = false, $refund = null ) {

		// Support for "Estonian Banklinks for WooCommerce"
		$reference_number = $order->get_meta( '_wc_estonian_banklinks_reference_number', true );

		if ( ! $reference_number ) {
			$reference_number = $this->generate_reference_number( $order->get_id() );
		}

		$order_items      = [];
		$tax_items        = [];
		$tax_amount       = [];
		$is_full_refund   = false;
		$order_line_items = [];

		// Look for location code from shipping method
		$location_code = $this->get_plugin()->get_order_warehouse_id( $order );

		// Get order items
		if ( $refund ) {
			$order_line_items = $refund->get_items( [ 'line_item', 'shipping' ] );
		}

		if ( empty( $order_line_items ) && $refund && abs( $refund->get_total( 'edit' ) ) == $order->get_total( 'edit' ) ) {
			$order_line_items = $order->get_items( [ 'line_item', 'shipping' ] );
			$is_full_refund   = true;
		}
		elseif ( empty( $order_line_items ) ) {
			$order_line_items = $order->get_items( [ 'line_item', 'shipping' ] );
		}

		// Add order items
		/** @var \WC_Order_Item_Product $order_item */
		foreach ( $order_line_items as $order_item ) {

			$order_row = [
				'Item' => [
					'Code'        => '',
					'Description' => $order_item->get_name(),
					'Type'        => self::ITEM_TYPE_ITEM,
				],
				'Quantity' => $this->format_number( $order_item->get_quantity() ),
				'Price'    => $this->format_number( ( $order_item->get_total( 'edit' ) ) / $order_item->get_quantity() ),
				'TaxId'    => $this->integration->get_matching_tax_code( $order_item->get_tax_class() ),
			];

			if ( empty( $order_row['TaxId'] ) && 'inherit' === $order_item->get_tax_class() ) {
				$order_row['TaxId'] = $this->integration->get_matching_tax_code( '' );
			}

			if ( is_callable( array( $order_item, 'get_product' ) ) ) {

				$product = $order_item->get_product();

				if ( ! $product ) {
					continue;
				}

				$order_row['Item']['Code'] = $product->get_sku();
				$order_row['Item']['Type'] = $product->managing_stock() ? self::ITEM_TYPE_STOCK_ITEM : self::ITEM_TYPE_ITEM;

				// Attach warehouse
				if ( null !== $location_code ) {
					$order_row['LocationCode'] = $location_code;
				} else {
					$product_quantities = $this->get_plugin()->attach_product_quantities_by_warehouse( [], $product );
					$warehouse_key      = null;

					foreach ( $this->integration->get_warehouses() as $warehouse ) {
						if ( ! $warehouse['id'] ) {
							continue;
						}

						$warehouse_key = array_search( $warehouse['id'], array_column( $product_quantities, 'location' ) );

						if ( false !== $warehouse_key ) {
							if ( $product_quantities[ $warehouse_key ]['quantity'] >= $order_item->get_quantity() ) {
								$order_row['LocationCode'] = $warehouse['id'];

								break;
							}
						}
					}
				}

				if ( true === $set_default_location ) {
					$order_row['Item']['DefLocationCode'] = $this->integration->get_option( 'primary_warehouse_id', '1' );
				}

				// Check for discounts
				if ( $order_item->get_subtotal( 'edit' ) != $order_item->get_total( 'edit' ) ) {
					$discount_amount = $order_item->get_subtotal( 'edit' ) - $order_item->get_total( 'edit' );

					$order_row['DiscountPct']    = $this->format_number( $discount_amount / $order_item->get_subtotal( 'edit' ) * 100 );
					$order_row['DiscountAmount'] = $this->format_number( $discount_amount );
					$order_row['Price']          = $this->format_number( $order_item->get_subtotal( 'edit' ) );
				}

			} else {

				$order_row['Item']['Code'] = $this->integration->get_option( 'invoice_shipping_sku' );
				$order_row['Item']['Type'] = self::ITEM_TYPE_SERVICE;
			}

			if ( $refund ) {
				$order_row['ItemCostAmount'] = $order_row['Price'];

				if ( (float) $order_row['Quantity'] > 0 ) {
					$order_row['Quantity'] = $this->format_number( 0 - (float) $order_row['Quantity'] );
				}
			}

			$order_items[] = $order_row;
			$tax_items[]   = [
				'TaxId'  => $this->integration->get_matching_tax_code( $order_item->get_tax_class() ),
				'Amount' => $this->format_number( abs( $order_item->get_total_tax( 'edit' ) ) ),
			];
		}

		$customer_address = $order->get_address( 'billing' );

		// Remove name and company before generate the Google Maps URL.
		unset( $customer_address['first_name'], $customer_address['last_name'], $customer_address['company'] );

		if ( ! empty( $tax_items ) ) {
			foreach ( $tax_items as $tax_item ) {
				if ( ! array_key_exists( $tax_item['TaxId'], $tax_amount ) ) {
					$tax_amount[ $tax_item['TaxId'] ] = $tax_item['Amount'];
				} else {
					$tax_amount[ $tax_item['TaxId'] ] += $tax_item['Amount'];
				}
			}
		}

		$tax_items = [];

		foreach ( $tax_amount as $tax_id => $tax_sum ) {
			$tax_items[] = [
				'TaxId'  => $tax_id,
				'Amount' => $this->format_number( $tax_sum ),
			];
		}

		if ( $refund ) {
			if ( $is_full_refund ) {
				$total_amount = $this->format_number( 0 - $order->get_total( 'edit' ) + $order->get_total_tax( 'edit' ) );
			} else {
				$total_amount = $this->format_number( $refund->get_total( 'edit' ) - $refund->get_total_tax( 'edit' ) );
			}
		} else {
			$total_amount = $this->format_number( $order->get_total( 'edit' ) - $order->get_total_tax( 'edit' ) );
		}

		// Prepare invoice data
		$invoice = [
			// Customer data
			'Customer' => [
				'Name'          => $order->get_formatted_billing_full_name(),
				'NotTDCustomer' => 'true',
				'Address'       => WC()->countries->get_formatted_address( $customer_address, ', ' ),
				'CountryCode'   => $order->get_billing_country(),
				'PhoneNo'       => $order->get_billing_phone(),
				'Email'         => $order->get_billing_email(),
			],

			// Invoice data
			'DocDate'         => $order->get_date_created()->format( 'YmdHis' ),
			'RefNo'           => apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_invoice_reference_number', $reference_number ),
			'InvoiceNo'       => ( $refund ? 'C' : '' ) . $order->get_order_number(),
			'CurrencyCode'    => $order->get_currency(),

			// Invoice rows
			'InvoiceRow'      => $order_items,
			'TotalAmount'     => $total_amount,
			'TaxAmount'       => $tax_items,

			// Additional information
			'DepartmentCode' => '',
			'ProjectCode'    => '',
			'HComment'       => '',
			'FComment'       => '',
		];

		// Payment data
		if ( $order->is_paid() ) {
			$invoice['DueDate']         = $order->get_date_paid()->format( 'YmdHis' );
			$invoice['TransactionDate'] = $order->get_date_paid()->format( 'YmdHis' );
			$invoice['Payment']         = [
				'PaymentMethod' => $order->get_payment_method_title(),
				'PaidAmount'    => $this->format_number( $order->get_total( 'edit' ) ),
				'PaymDate'      => $order->get_date_paid()->format( 'YmdHis' ),
			];

			if ( ! empty( $this->integration->get_option( 'invoice_payment_method_name', '' ) ) ) {
				$invoice['Payment']['PaymentMethod'] = $this->integration->get_option( 'invoice_payment_method_name', '' );
			}
		}

		$response = $this->perform_request(
			$this->get_new_request( [
				'method' => 'POST',
				'path'   => 'sendinvoice',
				'data'   => apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_invoice_data', $invoice ),
			] )
		);

		if ( 200 === $this->get_response_code() ) {
			// Save order and customer IDs from response
			$this->get_plugin()->add_order_meta( $order, [
				'invoice_id'  => $response->InvoiceId,
				'customer_id' => $response->CustomerId,
			] );

			if ( $refund ) {
				$message = __( 'Created refund invoice with ID %s. Customer ID is %s.', 'konekt-merit-aktiva' );
			} else {
				$message = __( 'Created invoice with ID %s. Customer ID is %s.', 'konekt-merit-aktiva' );
			}

			// Add order note
			$this->get_plugin()->add_order_note(
				$order,
				sprintf(
					$message,
					$response->InvoiceId,
					$response->CustomerId
				)
			);

			// Save customer ID to user
			if ( false !== ( $customer_id = $order->get_customer_id() ) ) {
				update_user_meta( $customer_id, '_wc_' . $this->get_plugin()->get_id() . '_customer_id', $response->CustomerId );
			}
		} else {
			// Request failed
			$this->get_plugin()->add_order_note(
				$order,
				__( 'Invoice generation failed.', 'konekt-merit-aktiva' )
			);

			if ( ! empty( $response->Message ) ) {
				$this->get_plugin()->add_order_note( $order, $response->Message );
			}
		}

		return 200 === $this->get_response_code();
	}


	/**
	 * Get available taxes
	 *
	 * @return void
	 */
	public function get_taxes() {
		$request = $this->perform_request(
			$this->get_new_request( [
				'path' => 'gettaxes',
			] )
		);

		return $request->response_data;
	}


	public function get_item( $product_sku, $warehouse_id = null ) {
		$request_data = [
			'Code' => $product_sku,
		];

		if ( null !== $warehouse_id ) {
			$request_data['LocationCode'] = $warehouse_id;
		}

		$request = $this->perform_request(
			$this->get_new_request( [
				'path' => 'getitems',
				'data' => $request_data,
			] )
		);

		$response = empty( $request ) ? null : reset( $request->response_data );

		if ( $response && $response->Code !== $product_sku ) {
			$this->get_plugin()->log( sprintf( 'Wrong item stock fetched. Tried %s, got %s.', $product_sku, $response->Code ) );

			return false;
		}

		return $response;
	}


	public function get_order( $order_id ) {
		$order        = wc_get_order( $order_id );
		$request_data = [
			'Id' => $this->get_plugin()->get_order_meta( $order, 'invoice_id' ),
		];

		$request = $this->perform_request(
			$this->get_new_request( [
				'path' => 'getinvoice',
				'data' => $request_data,
			] )
		);

		return empty( $request ) ? null : $request->response_data;
	}


	public function delete_invoice( $order_id ) {
		$order        = wc_get_order( $order_id );
		$request_data = [
			'Id' => $this->get_plugin()->get_order_meta( $order, 'invoice_id' ),
		];

		$request = $this->perform_request(
			$this->get_new_request( [
				'method' => 'POST',
				'path'   => 'deleteinvoice',
				'data'   => $request_data,
			] )
		);

		return empty( $request ) ? null : $request->response_data;
	}


	public function get_item_stock( $product_sku, $warehouse_id ) {
		return $this->get_item( $product_sku, $warehouse_id );
	}


	public function get_products_in_warehouse( $warehouse_id ) {

		$request = $this->perform_request(
			$this->get_new_request( [
				'path' => 'getitems',
				'data' => [
					'LocationCode' => $warehouse_id,
				],
			] )
		);

		return empty( $request ) ? null : $request->response_data;
	}


	/**
	 * Format number
	 *
	 * @param float $number
	 *
	 * @return string
	 */
	private function format_number( $number ) {
		return Framework\SV_WC_Helper::number_format( $number );
	}


	/**
	 * Generate reference number
	 *
	 * @param string $stamp
	 *
	 * @return string
	 */
	private function generate_reference_number( $stamp ) {
		$chcs = array( 7, 3, 1 );
		$sum  = 0;
		$pos  = 0;

		for ( $i = 0; $i < strlen( $stamp ); $i++ ) {
			$x   = (int) ( substr( $stamp, strlen( $stamp ) - 1 - $i, 1 ) );
			$sum = $sum + ( $x * $chcs[ $pos ] );

			if ( $pos == 2 ) {
				$pos = 0;

			} else {
				$pos = $pos + 1;
			}
		}

		$x   = 10 - ( $sum % 10 );
		$sum = ( $x != 10 ) ? $x : 0;

		return $stamp . $sum;
	}


	/**
	 * Construct new API request
	 *
	 * @param array $args
	 *
	 * @return \Konekt\WooCommerce\Merit_Aktiva\API\Request
	 */
	protected function get_new_request( $args = [] ) {
		$args = wp_parse_args( $args, [
			'path'   => '',
			'params' => [],
			'method' => 'GET',
			'data'   => [],
		] );

		return new API\Request( $this->api_id, $this->api_key, $args['method'], $args['path'], $args['data'], $args['params'] );
	}


	/**
	 * Gets the request URL query.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function get_request_query() {

		$query  = '';
		$params = $this->get_request()->get_params();

		if ( ! empty( $params ) ) {
			$query = http_build_query( $params, '', '&' );
		}

		return $query;
	}


	public function maybe_set_data_format_to_body( &$url, &$headers, &$data, &$type, &$options ) {
		if ( stristr( $url, $this->get_request_uri() ) ) {
			$options['data_format'] = 'body';
		}
	}


	/**
	 * Gets the request body.
	 *
	 * @since 4.5.0
	 * @return string
	 */
	protected function get_request_body() {

		if ( in_array( strtoupper( $this->get_request_method() ), array( 'GET', 'HEAD' ) ) ) {
			return wp_json_encode( $this->get_request()->get_data() );
		}

		return ( $this->get_request() && $this->get_request()->to_string() ) ? $this->get_request()->to_string() : '';
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