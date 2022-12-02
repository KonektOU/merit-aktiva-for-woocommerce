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

	const DEFAULT_PRODUCT_UOM = 'tk';


	/**
	 * API URL based on language
	 *
	 * @var array
	 */
	private $api_urls = [
		// Version 1 URLs
		'estonian' => 'https://aktiva.merit.ee/api/v1/',
		'finnish'  => 'https://aktiva.meritaktiva.fi/api/v1/',
		'polish'   => 'https://program.360ksiegowosc.pl/api/v1/',

		// Version 2 URLs
		'estonian_v2' => 'https://aktiva.merit.ee/api/v2/',
		'finnish_v2'  => 'https://aktiva.meritaktiva.fi/api/v2/',
		'polish_v2'   => 'https://program.360ksiegowosc.pl/api/v2/',
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
		$order_line_items = [];
		$total_amount     = 0;
		$total_tax_amount = 0;

		// Look for location code from shipping method
		$location_code = $this->get_plugin()->get_order_warehouse_id( $order );

		// Get order items
		if ( $refund ) {
			$order_line_items = $refund->get_items( [ 'line_item', 'shipping' ] );

			if ( ! empty( $refund_warehouse_id = $this->integration->get_option( 'refund_warehouse_id', false ) ) ) {
				$location_code = $refund_warehouse_id;
			}
		}

		if ( empty( $order_line_items ) && $refund && abs( $refund->get_total( 'edit' ) ) == $order->get_total( 'edit' ) ) {
			$order_line_items = $order->get_items( [ 'line_item', 'shipping', 'fee' ] );
		}
		elseif ( empty( $order_line_items ) ) {
			$order_line_items = $order->get_items( [ 'line_item', 'shipping', 'fee' ] );
		}

		// Find matching location
		if ( ! $location_code ) {
			$matching_location = $this->find_same_warehouse_for_items( $order_line_items );
		}
		else {
			$matching_location = null;
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
				'Price'    => round( ( $order_item->get_total( 'edit' ) ) / $order_item->get_quantity(), 7 ),
			];

			$item_taxes = $order_item->get_taxes();

			foreach ( $item_taxes['total'] as $tax_id => $amount ) {
				$tax_code = $this->integration->get_matching_tax_code( null, $tax_id );

				if ( $tax_code ) {
					$order_row['TaxId'] = $tax_code;

					break;
				}
			}

			if ( empty( $order_row['TaxId'] ) && 'inherit' === $order_item->get_tax_class() ) {
				$order_row['TaxId'] = $this->integration->get_matching_tax_code( '' );
			}

			if ( ( $order_item->get_total( 'edit' ) > 0 || ( $refund && abs( $refund->get_total( 'edit' ) ) > 0 ) ) && $order_item->get_total_tax() == 0 ) {
				$order_row['TaxId'] = $this->integration->get_matching_tax_code( 0 );

				if ( ! empty( $account_code = $this->integration->get_option( 'zero_tax_account_code', null ) ) ) {
					$order_row['GLAccountCode'] = $account_code;
				}
			}

			if ( empty( $order_row['TaxId'] ) && $order_item->get_total_tax() >= 0 ) {
				$order_row['TaxId'] = $this->integration::DEFAULT_ESTONIAN_TAX_ID;
			}

			if ( is_callable( array( $order_item, 'get_product' ) ) ) {

				$product = $order_item->get_product();

				if ( ! $product ) {
					$total_amount += $this->format_number( $order_row['Price'] * $order_row['Quantity'] );

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

					if ( empty( $product_quantities ) ) {
						$this->get_plugin()->log( sprintf( 'Not able to fetch product %s (%d) quantities.', $product->get_sku(), $product->get_id() ) );
					}
					else {
						if ( null !== $matching_location ) {
							$order_row['LocationCode'] = $matching_location;
						}
						else {
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
					}
				}

				if ( true === $set_default_location ) {
					$order_row['Item']['DefLocationCode'] = $this->integration->get_option( 'primary_warehouse_id', '1' );
				}

				// Check for discounts
				if ( $order_item->get_subtotal( 'edit' ) != $order_item->get_total( 'edit' ) ) {
					$discount_amount = $order_item->get_subtotal( 'edit' ) - $order_item->get_total( 'edit' );

					$order_row['DiscountPct']     = $this->format_number( $discount_amount / $order_item->get_subtotal( 'edit' ) * 100 );
					$order_row['Price']           = round( $order_item->get_subtotal( 'edit' ), 2 ); // no VAT, no discount
					$order_row['DiscountAmount']  = $this->format_number( $discount_amount );
					$order_row['DiscountedPrice'] = $this->format_number( $order_row['Price'] - $order_row['DiscountAmount'] );
				}

				$product_uom = $this->integration->get_product_uom_from_lookup_table( $product->get_sku() );

				if ( $product_uom ) {
					$order_row['Item']['UOMName'] = $product_uom;
				} else {
					$order_row['Item']['UOMName'] = self::DEFAULT_PRODUCT_UOM;
				}

			} else {

				if ( $order_item->is_type( 'shipping' ) ) {
					$order_row['Item']['Code'] = $this->integration->get_option( 'invoice_shipping_sku' );
				}

				$order_row['Item']['Type'] = self::ITEM_TYPE_SERVICE;
			}

			if ( $refund ) {
				$order_row['ItemCostAmount'] = $this->format_number( $order_row['Price'] );
				$order_row['Price'] = abs( $order_row['Price'] );

				if ( (float) $order_row['Quantity'] > 0 ) {
					$order_row['Quantity'] = $this->format_number( 0 - (float) $order_row['Quantity'] );
				}
			}

			if ( ! empty( $order_row['DiscountedPrice'] ) ) {
				$total_amount += $this->format_number( $order_row['DiscountedPrice'] * $order_row['Quantity'] );
			}
			else {
				$total_amount += $this->format_number( $order_row['Price'] * $order_row['Quantity'] );
			}

			$total_tax_amount += $this->format_number( abs( $order_item->get_total_tax( 'edit' ) ) );

			$order_items[] = $order_row;
			$tax_items[]   = [
				'TaxId'  => $order_row['TaxId'],
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

		// Prepare customer data
		$customer_data = [
			'Name'          => $order->get_formatted_billing_full_name(),
			'NotTDCustomer' => 'true',
			'Address'       => WC()->countries->get_formatted_address( $customer_address, ', ' ),
			'CountryCode'   => $order->get_billing_country(),
			'PhoneNo'       => $order->get_billing_phone(),
			'Email'         => $order->get_billing_email(),
		];

		if ( ! empty( $order->get_billing_company() ) ) {
			$customer_data['Name'] = $order->get_billing_company();
		}

		// Prepare invoice data
		$invoice = [
			// Customer data
			'Customer' => $customer_data,

			// Invoice data
			'DocDate'         => $refund ? $refund->get_date_created()->format( 'YmdHis' ) : $order->get_date_created()->format( 'YmdHis' ),
			'RefNo'           => apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_invoice_reference_number', $reference_number ),
			'InvoiceNo'       => ( $refund ? 'C' : '' ) . $order->get_order_number(),
			'CurrencyCode'    => $order->get_currency(),

			// Invoice rows
			'InvoiceRow'      => $order_items,
			'TotalAmount'     => $this->format_number( $total_amount ),
			'TaxAmount'       => $tax_items,

			// Additional information
			'DepartmentCode' => '',
			'ProjectCode'    => '',
			'HComment'       => '',
			'FComment'       => '',
		];

		if ( ! $refund ) {
			$wc_total = $this->format_number( $order->get_total( 'edit' ) - $order->get_total_tax( 'edit' ) );

			if ( $wc_total != $invoice['TotalAmount'] ) {
				$invoice['RoundingAmount'] = $this->format_number( $wc_total - $invoice['TotalAmount'] );
			}
		}

		// Payment data
		if ( $order->is_paid() && ! $refund ) {
			if ( $order->get_date_paid() ) {
				$invoice['DueDate']         = $order->get_date_paid()->format( 'YmdHis' );
				$invoice['TransactionDate'] = $order->get_date_paid()->format( 'YmdHis' );
				$invoice['Payment']         = [
					'PaymentMethod' => $order->get_payment_method_title(),
					'PaidAmount'    => $this->format_number( $total_amount + $total_tax_amount + ( $invoice['RoundingAmount'] ?? 0 ) ),
					'PaymDate'      => $order->get_date_paid()->format( 'YmdHis' ),
				];

				if ( ! empty( $payment_method = $this->integration->get_matching_bank_account( $order->get_payment_method() ) ) ) {
					$invoice['Payment']['PaymentMethod'] = $payment_method;
				}
			}
		}

		$response = $this->perform_request(
			$this->get_new_request( [
				'method' => 'POST',
				'path'   => 'sendinvoice',
				'data'   => apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_invoice_data', $invoice, $order, $refund ),
			] )
		);

		if ( 200 === $this->get_response_code() ) {
			// Save order and customer IDs from response
			$this->get_plugin()->add_order_meta( $order, [
				'invoice_id'  => $response->InvoiceId,
				'customer_id' => $response->CustomerId,
			] );

			if ( $refund ) {
				$message = __( 'Created refund invoice nr. %s with ID %s. Customer ID is %s.', 'konekt-merit-aktiva' );
			} else {
				$message = __( 'Created invoice no. %s with ID %s. Customer ID is %s.', 'konekt-merit-aktiva' );
			}

			// Add order note
			$this->get_plugin()->add_order_note(
				$order,
				sprintf(
					$message,
					$response->InvoiceNo,
					$response->InvoiceId,
					$response->CustomerId
				)
			);

			// Save customer ID to user
			if ( false !== ( $customer_id = $order->get_customer_id() ) ) {
				update_user_meta( $customer_id, '_wc_' . $this->get_plugin()->get_id() . '_customer_id', $response->CustomerId );
			}

			return [
				'invoice_id'  => $response->InvoiceId,
				'invoice_no'  => $response->InvoiceNo,
				'customer_id' => $response->CustomerId,
			];
		} else {
			// Request failed
			$this->get_plugin()->add_order_note(
				$order,
				__( 'Invoice generation failed.', 'konekt-merit-aktiva' )
			);

			if ( ! empty( $message = $response->Message ) ) {
				if ( 'yes' === $this->integration->get_option( 'save_api_messages_to_notes', 'no' ) ) {
					$this->get_plugin()->add_order_note( $order, $message );
				}

				return $response->Message;
			} else {
				return null;
			}
		}

		return false;
	}


	public function find_same_warehouse_for_items( $order_items ) {
		$location_code = null;

		foreach ( $this->integration->get_warehouses() as $warehouse ) {
			$is_enough_stock = true;

			foreach ( $order_items as $order_item ) {
				if ( is_callable( array( $order_item, 'get_product' ) ) ) {
					$product            = $order_item->get_product();
					$product_quantities = $this->get_plugin()->attach_product_quantities_by_warehouse( [], $product );

					if ( empty( $product_quantities ) ) {
						$manual_update = $this->integration->manually_update_product_stock_data( $product->get_id() );

						if ( true === $manual_update ) {
							$product_quantities = $this->get_plugin()->attach_product_quantities_by_warehouse( [], $product );
						}
					}

					$warehouse_key = array_search( $warehouse['id'], array_column( $product_quantities, 'location' ) );

					if ( false !== $warehouse_key ) {
						if ( ! $product_quantities[ $warehouse_key ]['quantity'] || $product_quantities[ $warehouse_key ]['quantity'] < $order_item->get_quantity() ) {
							$is_enough_stock = false;

							break;
						} else {

						}
					}
				}
			}

			if ( true === $is_enough_stock ) {
				$location_code = $warehouse['id'];

				break;
			}
		}

		return $location_code;
	}


	public function create_products( $products ) {
		do_action( 'konekt_merit_aktiva_create_products' );

		$items = [];

		foreach ( $products as $product ) {
			if ( $product->is_type( 'external' ) ) {
				continue;
			}

			$this->get_plugin()->log_action( sprintf( 'Creating %s (%s)', $product->get_name(), $product->get_sku() ), 'create-products' );

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation_product = wc_get_product( $variation_id );

					if ( ! $variation_product->get_sku() ) {
						continue;
					}

					if ( ! $variation_product->managing_stock() ) {
						$variation_product->set_manage_stock( true );
						$variation_product->save();
					}

					$product_uom = $this->integration->get_product_uom_from_lookup_table( $variation_product->get_sku() );

					$items[] = [
						'Type'            => self::ITEM_TYPE_STOCK_ITEM,
						'Usage'           => 3,                                                               // Sales and purchases
						'Code'            => $variation_product->get_sku(),
						'Description'     => $variation_product->get_name(),
						'UOMName'         => $product_uom ? $product_uom : self::DEFAULT_PRODUCT_UOM,
						'DefLocationCode' => $this->integration->get_option( 'primary_warehouse_id', '1' ),
						'TaxId'           => $this->integration->get_matching_tax_code( $variation_product->get_tax_class() ),
					];
				}
			} else {
				$product_uom = $this->integration->get_product_uom_from_lookup_table( $product->get_sku() );;

				$items[] = [
					'Type'            => self::ITEM_TYPE_STOCK_ITEM,
					'Usage'           => 3,                                                               // Sales and purchases
					'Code'            => $product->get_sku(),
					'Description'     => $product->get_name(),
					'UOMName'         => $product_uom ? $product_uom : self::DEFAULT_PRODUCT_UOM,
					'DefLocationCode' => $this->integration->get_option( 'primary_warehouse_id', '1' ),
					'TaxId'           => $this->integration->get_matching_tax_code( $product->get_tax_class() ),
				];
			}
		}

		// Prepare
		$request = [
			'Items' => $items,
		];

		// Set v2 URL
		$this->request_uri = $this->api_urls[ $this->integration->get_option( 'api_localization', 'estonian' ) . '_v2' ];

		$response = $this->perform_request(
			$this->get_new_request( [
				'method' => 'POST',
				'path'   => 'senditems',
				'data'   => apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_create_products_data', $request, $products ),
			] )
		);

		// Set v1 URL
		$this->request_uri = $this->api_urls[ $this->integration->get_option( 'api_localization', 'estonian' ) ];

		return 200 === $this->get_response_code() ? true : $response;
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
			$this->get_plugin()->log( sprintf( 'Wrong item data fetched. Tried %s, got %s.', $product_sku, $response->Code ) );

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


	public function delete_invoice( $order_id, $external_id = null ) {
		if ( ! $external_id ) {
			$order        = wc_get_order( $order_id );
			$request_data = [
				'Id' => $this->get_plugin()->get_order_meta( $order, 'invoice_id' ),
			];
		}
		else {
			$request_data = [
				'Id' => $external_id,
			];
		}

		$request = $this->perform_request(
			$this->get_new_request( [
				'method' => 'POST',
				'path'   => 'deleteinvoice',
				'data'   => $request_data,
			] )
		);

		return empty( $request ) ? null : $request->response_data;
	}


	/**
	 * Create payment
	 *
	 * @since 1.0.15
	 *
	 * @param string $invoice_no
	 * @param string $reference_number
	 * @param float $amount
	 * @param string $customer_name
	 * @param string $bank_name
	 *
	 * @return object
	 */
	public function create_payment( $invoice_no, $reference_number, $amount, $customer_name, $bank_name = null ) {
		$data = [
			'InvoiceNo'    => $invoice_no,
			'Amount'       => $amount,
			'RefNo'        => $reference_number,
			'CustomerName' => $customer_name,
		];

		if ( ! empty( $bank_name ) ) {
			$payment_types = $this->get_payment_types();

			if ( is_array( $payment_types ) ) {
				$payment_types_ids = wc_list_pluck( $payment_types, 'Id', 'Name' );

				if ( array_key_exists( $bank_name, $payment_types_ids ) ) {
					$data['BankId'] = $payment_types_ids[ $bank_name ];
				}
			}
		}

		$request = $this->perform_request(
			$this->get_new_request( [
				'method' => 'POST',
				'path'   => 'sendpayment',
				'data'   => $data,
			] )
		);

		return empty( $request ) ? null : $request->response_data;
	}


	/**
	 * Get banks
	 *
	 * @since 1.0.16
	 *
	 * @return object
	 */
	public function get_banks() {
		$request = $this->perform_request(
			$this->get_new_request( [
				'method' => 'POST',
				'path'   => 'getbanks',
				'data'   => [],
			] )
		);

		return empty( $request ) ? null : $request->response_data;
	}


	/**
	 * Get payment types
	 *
	 * @since 1.0.35
	 *
	 * @return object
	 */
	public function get_payment_types() {
		// Set v2 URL
		$this->request_uri = $this->api_urls[ $this->integration->get_option( 'api_localization', 'estonian' ) . '_v2' ];

		$request = $this->perform_request(
			$this->get_new_request( [
				'method' => 'POST',
				'path'   => 'getpaymenttypes',
				'data'   => [
					'Type' => 3 // Sales
				],
			] )
		);

		// Set v1 URL
		$this->request_uri = $this->api_urls[ $this->integration->get_option( 'api_localization', 'estonian' ) ];

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
	private function format_number( $number, $precision = 2 ) {
		return Framework\SV_WC_Helper::number_format( round( $number, $precision ) );
	}


	/**
	 * Generate reference number
	 *
	 * @param string $stamp
	 *
	 * @return string
	 */
	public function generate_reference_number( $stamp ) {
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
			'method' => 'POST',
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


	/**
	 * Get plugin
	 *
	 * @return \Konekt\WooCommerce\Merit_Aktiva\Plugin
	 */
	protected function get_plugin() {
		return wc_konekt_woocommerce_merit_aktiva();
	}


}