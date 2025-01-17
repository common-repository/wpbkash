<?php
/**
 * @package WPbKash
 */
namespace Themepaw\bKash\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Themepaw\bKash\Api\Query;
use Themepaw\bKash\Invoice;

/**
 * Ajax class file.
 *
 * @package WpbKash
 */
final class Ajax {

	/**
	 * Store api class
	 */
	public $api;

	/**
	 * Initialize
	 */
	function __construct() {
		add_action( 'wp_ajax_wpbkash_createpayment', [ $this, 'wpbkash_createpayment' ] );
		add_action( 'wp_ajax_nopriv_wpbkash_createpayment', [ $this, 'wpbkash_createpayment' ] );
		add_action( 'wp_ajax_wpbkash_executepayment', [ $this, 'wpbkash_executepayment' ] );
		add_action( 'wp_ajax_nopriv_wpbkash_executepayment', [ $this, 'wpbkash_executepayment' ] );
		add_action( 'wp_ajax_wpbkash_get_orderdata', [ $this, 'wpbkash_get_orderdata' ] );
		add_action( 'wp_ajax_nopriv_wpbkash_get_orderdata', [ $this, 'wpbkash_get_orderdata' ] );
	}

	/**
	 * bkash createpayment ajax request
	 */
	function wpbkash_createpayment() {

		check_ajax_referer( 'wpbkash_security_nonce', 'nonce' );

		$order_id = ( isset( $_POST['order_id'] ) && ! empty( $_POST['order_id'] ) ) ? absint( $_POST['order_id'] ) : '';

		$order = wc_get_order( $order_id );

		if ( is_object( $order ) ) {
			$total = $order->get_total();
		} else {
			$total = WC()->cart->total;
		}

		$paymentData = Query::instance()->createPayment( $total );

		echo $paymentData;

		wp_die();
	}
	
	/**
	 * get WooCommerce order data
	 */
	function wpbkash_get_orderdata() {

		check_ajax_referer( 'wpbkash_security_nonce', 'nonce' );

		$order_id = ( isset( $_POST['order_id'] ) && ! empty( $_POST['order_id'] ) ) ? absint( $_POST['order_id'] ) : '';

		$order = wc_get_order( $order_id );

		if ( is_object( $order ) ) {
			$total = $order->get_total();
		} else {
			$total = WC()->cart->total;
		}

		$invoice = Invoice::instance()->get_invoice();

		$data = [
			'amount' => $total,
			'invoice' => $invoice
		];

		wp_send_json_success( $data );

		wp_die();
	}

	/**
	 * bkash executepayment ajax request
	 */
	function wpbkash_executepayment() {
		check_ajax_referer( 'wpbkash_security_nonce', 'nonce' );

		$paymentid = ( isset( $_POST['paymentid'] ) && ! empty( $_POST['paymentid'] ) ) ? sanitize_text_field( $_POST['paymentid'] ) : '';
		$order_id = ( isset( $_POST['order_id'] ) && ! empty( $_POST['order_id'] ) ) ? absint( $_POST['order_id'] ) : '';

		if ( empty( $paymentid ) ) {
			wp_send_json_error(
				[
					'message'  => esc_html__( 'Invalid token or expired', 'wpbkash' )
				]
			);
			wp_die();
		}

		$data = Query::instance()->executePayment( $paymentid );
        $data = json_decode( $data );

		if ( ! isset( $data ) || empty( $data ) || ! isset( $data->trxID ) || ! isset( $data->paymentID ) || ! isset( $data->transactionStatus ) || "Completed" != $data->transactionStatus ) {
			$msg = isset( $data->errorMessage ) ? $data->errorMessage : esc_html__('Looks like the server is taking to long to respond, this can be caused by either poor connectivity or an error with our servers. Please try again in a while', 'wpbkash');
			wp_send_json_error(
				[
					'message'   => apply_filters('wpbkash_execute_err_msg', $msg)
				]
			);
			wp_die();
		}

		if ( !empty( $order_id ) ) {
			$order = wc_get_order( $order_id );
			if( is_object( $order ) ) {
				$customer_id    = $order->get_user_id();
				$data->user_id  = ( ! empty( $customer_id ) ) ? $customer_id : $order->get_billing_email();
			} else {
				wp_send_json_error( esc_html__( 'Wrong or invalid order ID', 'wpbkash' ) );
				wp_die();
			}
		} else {
			$customer_data = WC()->session->get('customer');

			if( empty( $customer_data ) ) {
				wp_send_json_error(
					[
						'message'  => esc_html__( 'Something wen\'t wrong, please try again', 'wpbkash' )
					]
				);
				wp_die();
			}
			$data->user_id  = ( ! empty( $customer_data['id'] ) ) ? $customer_data['id'] : $customer_data['email'];
		}
        
		do_action( 'wpbkash_process_payment', $paymentid, $data );

        $data->payment_id = $paymentid;
		$entry_id = $this->insert_transaction( $data );
		WC()->session->set('wpbkash_entry_id', $entry_id );

		if( $entry_id ) {
			wp_send_json_success(
				[
					'transactionStatus' => 'completed',
					'entry_id' => $entry_id
				]
			);
			wp_die();
		}
		
		wp_send_json_error(
			[
				'message'  => esc_html__( 'Something wen\'t wrong, please try again', 'wpbkash' )
			]
		);
		wp_die();

	}

    function process_event() {
        
    }

	/**
	 * Insert entry transaction
	 *
	 * @param object $response
	 */
	function insert_transaction( $response ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wpbkash',
			[
				'trx_id'     => sanitize_key( $response->trxID ),
				'payment_id' => sanitize_key( $response->payment_id ),
				'trx_status' => sanitize_key( $response->transactionStatus ),
				'sender'     => sanitize_key( $response->user_id ),
				'ref'        => 'wc_order',
				'invoice'    => sanitize_text_field( $response->merchantInvoiceNumber ),
				'amount'     => absint( $response->amount ),
				'created_at' => current_time( 'mysql' ),
				'status'     => 'pending',
				'data'       => maybe_serialize( $response )
			],
			[
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s'
			]
		);

		return $wpdb->insert_id;
	}
	
}

