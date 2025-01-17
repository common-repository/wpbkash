<?php
/**
 * @package WPbKash
 */
namespace Themepaw\bKash\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WCBkashGateway - Woocommerce gateway register
 *
 * @author themepaw
 */
class WCBkashGateway extends \WC_Payment_Gateway {

	/**
	 * Initialize the gateway
	 */
	public function __construct() {

		$this->id                 = 'wpbkash';
		$this->icon               = false;
		$this->has_fields         = true;
		$this->method_title       = __( 'bKash', 'wpbkash' );
		$this->method_description = __( 'Pay via bKash payment', 'wpbkash' );
		$this->icon               = apply_filters( 'wpbkash_logo', WPBKASH_URL . 'assets/images/bkash-logo.png' );

		$title                = $this->get_option( 'title' );
		$this->title          = empty( $title ) ? __( 'bKash', 'wpbkash' ) : $title;
		$this->description    = $this->get_option( 'description' );
		$this->payment_notice = $this->get_option( 'payment_notice' );
		$this->instructions   = $this->get_option( 'instructions', $this->description );

		$option           = get_option( 'wpbkash_general_fields' );
		$this->testmode   = isset( $option['testmode'] ) ? sanitize_key( $option['testmode'] ) : '';
		$this->app_key    = isset( $option['app_key'] ) ? sanitize_key( $option['app_key'] ) : '';
		$this->app_secret = isset( $option['app_secret'] ) ? sanitize_key( $option['app_secret'] ) : '';
		$this->username   = isset( $option['username'] ) ? sanitize_key( $option['username'] ) : '';
		$this->password   = isset( $option['password'] ) ? sanitize_key( $option['password'] ) : '';

		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_email_before_order_table', [ $this, 'email_instructions' ], 10, 3 );
		add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'payment_availability' ] );

        if ( ! $this->is_valid_for_use() ) {
			$this->enabled = 'no';
		} else {
            add_action( 'wp_enqueue_scripts', [ $this, 'payment_scripts' ] );
        }

	}

    /**
	 * Check if this currency is bdt or not.
	 *
	 * @return bool
	 */
	public function is_valid_for_use() {
		if ( 'BDT' === get_woocommerce_currency() ) {
            return true;
        }
	}

	/**
	 * Disable bKash method if currency BDT not set
	 */
	public function payment_availability( $available_gateways ) {
		if ( is_admin() ) {
			return $available_gateways;
		}
		if ( ! is_checkout() ) {
			return $available_gateways;
		}
		if ( 'BDT' !== get_woocommerce_currency() || ! ( ! empty( $this->app_key ) && ! empty( $this->app_secret ) && ! empty( $this->username ) && ! empty( $this->password ) ) ) {
			unset( $available_gateways['wpbkash'] );
		}
		return $available_gateways;
	}

	/**
	 * Checkout page scripts and styles
	 */
	public function payment_scripts() {

		// we need JavaScript to process a token only on cart/checkout pages, right?
		if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
			return;
		}

		// if our payment gateway is disabled, we do not have to enqueue JS too
		if ( 'no' === $this->enabled ) {
			return;
		}

		// no reason to enqueue JavaScript if API keys are not set
		if ( empty( $this->app_key ) || empty( $this->app_secret ) || empty( $this->username ) || empty( $this->password ) ) {
			return;
		}

		// and this is our custom JS  and styles
		wp_register_style( 'wpbkash_wc', WPBKASH_URL . 'assets/css/wpbkash_wc.css' );
		wp_register_style( 'wpbkash_alertify', WPBKASH_URL . 'assets/css/alertify.min.css' );
		wp_register_style( 'alertify_bootstrap', WPBKASH_URL . 'assets/css/bootstrap.min.css' );
		wp_register_script( 'wpbkash_alertify', WPBKASH_URL . 'assets/js/alertify.min.js', [ 'jquery' ], '0.1', true );
		wp_register_script( 'wpbkash_wc', WPBKASH_URL . 'assets/js/wpbkash_wc.js', [ 'jquery', 'wpbkash_alertify' ], '0.1', true );

		$mode          = ( isset( $this->testmode ) && ! empty( $this->testmode ) ) ? 'sandbox' : 'pay';
		$bkash_version = WPBKASH()->bkash_api_version;
		$filename      = ( 'sandbox' === $mode ) ? 'bKash-checkout-sandbox' : 'bKash-checkout';

		wp_localize_script(
			'wpbkash_wc',
			'wpbkash_params',
			[
				'home_url'  => esc_url( home_url() ),
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
                'assets'    => WPBKASH_URL . 'assets/images/',
                'btn_title'    => esc_html__( 'Ok', 'wpbkash' ),
                'modal_title'    => [
                    'error' => esc_html__( 'Payment Failed!', 'wpbkash' ),
                    'success' => esc_html__( 'Payment Successful!', 'wpbkash' ),
                ],
                'common_error' => esc_html__('Payment failed due to technical reasons', 'wpbkash'),
                'success_msg' => esc_html__('Successfully payment completed. Wait your order is being processing..', 'wpbkash'),
                'process_msg' => esc_html__('bKash Payment processing...', 'wpbkash'),
				'nonce'     => wp_create_nonce( 'wpbkash_nonce' ),
				'bkash_error'     => esc_html__( 'WooCommerce bKash credentials are incorrect or missing any required field.', 'wpbkash' ),
				'scriptUrl' => "https://scripts.{$mode}.bka.sh/versions/{$bkash_version}/checkout/{$filename}.js"
			]
		);

		wp_enqueue_style( 'wpbkash_alertify' );
		wp_enqueue_style( 'alertify_bootstrap' );
		wp_enqueue_style( 'wpbkash_wc' );
		wp_enqueue_script( 'wpbkash_alertify' );
		wp_enqueue_script( 'wpbkash_wc' );

	}

    /**
	 * Admin Panel Options.
	 * - Options for bits like 'title' and availability on a country-by-country basis.
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {
		if ( $this->is_valid_for_use() ) {
			parent::admin_options();
		} else {
			?>
			<div class="inline error">
				<p>
					<strong><?php esc_html_e( 'Gateway disabled', 'woocommerce' ); ?></strong>: <?php esc_html_e( 'bKash Payment Gateway not support your store currency.', 'wpbkash' ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Admin configuration parameters
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'enabled'        => [
				'title' => esc_html__( 'Enable/Disable', 'wpbkash' ),
				'type'  => 'checkbox',
				'label' => esc_html__( 'Enable bKash Payment', 'wpbkash' ),
			],
			'title'          => [
				'title'   => esc_html__( 'Title', 'wpbkash' ),
				'type'    => 'text',
				'default' => esc_html__( 'bKash Payment', 'wpbkash' ),
			],
			'description'    => [
				'title'       => esc_html__( 'Description', 'wpbkash' ),
				'type'        => 'textarea',
				'description' => esc_html__( 'Payment method description that the customer will see on your checkout.', 'wpbkash' ),
				'default'     => esc_html__( 'Pay via bKash: you can pay with your personal bKash account. ', 'wpbkash' ),
				'desc_tip'    => true,
			],
			'instructions'   => [
				'title'       => esc_html__( 'Instructions', 'wpbkash' ),
				'type'        => 'textarea',
				'description' => esc_html__( 'Instruction that will be added to the order email sent to customer if the payment was not completed.', 'wpbkash' ),
				'desc_tip'    => true,
			],
			'payment_notice' => [
				'title'       => esc_html__( 'Payment status notice', 'wpbkash' ),
				'type'        => 'textarea',
				'description' => esc_html__( 'Thankyou page order payment notice if customer not yet paid.', 'wpbkash' ),
				'default'     => esc_html__( 'Payment was not completed. You can still complete your order by paying from here: ', 'wpbkash' ),
				'desc_tip'    => true,
			],
			'settings'       => [
				'title' => sprintf(
                    esc_html__( '%1$s %2$s', 'wpbkash' ),
                    esc_html__( 'Setup your bKash merchant credentials', 'wpbkash' ),
                    sprintf(
                        '<a href="%s">%s</a>',
                        esc_url( admin_url( 'admin.php?page=wpbkash_settings' ) ),
                        esc_html__( 'here', 'wpbkash' )
                    )
                ),
				'type'  => 'title',
			]
		];
	}

	/**
	 * Output for the order received page.
	 *
	 * @return void
	 */
	public function thankyou_page( $order_id ) {

		$order = wc_get_order( $order_id );

		$display_btn = apply_filters( 'wpbkash_display_payment_button_order_thankyou', true );

		if ( 'wpbkash' === $order->get_payment_method() && $display_btn ) :

			$entry_id = wpbkash_get_id( $order_id );
			$entry    = wpbkash_get_entry( (int) $entry_id );

			if ( ! isset( $entry ) || empty( $entry ) || ( isset( $entry ) && !empty( $entry ) && 'completed' !== $entry->status ) ) {
				if ( $this->payment_notice ) {
					echo wpautop( wptexturize( wp_kses_post( $this->payment_notice ) ) );
				}
				Process::bkash_trigger( $order_id );
			}
		endif;
	}

	/**
	 * Add content to the WC emails.
	 *
	 * @access public
	 * @param WC_Order $order
	 * @param bool     $sent_to_admin
	 * @param bool     $plain_text
	 *
	 * @return void
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {

		if ( ! $sent_to_admin && 'wpbkash' === $order->get_payment_method() && $order->has_status( 'pending' ) ) {
			if ( $this->instructions ) {
				echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
			}
		}

	}

	/**
	 * If There are no payment fields show the description if set.
	 */
	public function payment_fields() {
		$description = $this->get_description();
		if ( $description ) {
			echo wpautop( wptexturize( $description ) ); // @codingStandardsIgnoreLine.
		}
		?>
		<?php wp_nonce_field('wpbkash_security_nonce', 'wpbkash_nonce'); ?>
		<input type="hidden" name="bkash_checkout_valid" id="bkash_checkout_valid" value="1">
		<span id="bKash_button" class="wpbkash--hidden-btn"><?php esc_html_e( 'Pay With bKash', 'wpbkash' ); ?></span>
		<?php
	}


	/**
	 * Process the gateway integration
	 *
	 * @param  int $order_id
	 *
	 * @return void
	 */
	public function process_payment( $order_id ) {

		global $woocommerce;

		$order = wc_get_order( $order_id );

		$entry_id = WC()->session->get( 'wpbkash_entry_id' );

		if( $order && !empty( $entry_id ) ) {

			WC()->session->set( 'wpbkash_entry_id', null );

			$entry = wpbkash_get_entry( $entry_id );

			// we received the payment
            $order->payment_complete();
            
			// Reduce stock levels
            wc_reduce_stock_levels( $order->get_id() );

			$invoice = ( property_exists($entry, 'invoice') ) ? $entry->invoice : '';

			update_post_meta( $order_id, '_bkash_trxid', $entry->trx_id );
			update_post_meta( $order_id, '_bkash_invoice', $invoice );

			$fields = array(
				'ref_id'     => sanitize_key( $order_id ),
				'status'     => 'completed',
				'updated_at' => current_time( 'mysql' )
			);
	
			$escapes = array(
				'%s',
				'%s',
				'%s',
			);
	
			wpbkash_entry_update( $entry_id, $fields, $escapes );

			// some notes to customer (replace true with false to make it private)
			$order->add_order_note( esc_html__('Hey, your order is paid via bKash!'), true );

			$order->add_order_note( sprintf( esc_html__( 'bKash payment completed with TrxID#%1$s, amount: %2$s, merchant invoiceID:%2$s', 'wpbkash' ), $entry->trx_id, $order->get_total(), $invoice ) );

			// Empty cart
			$woocommerce->cart->empty_cart();

			// Redirect to the thank you page
			return array(
				'result' => 'success',
				'redirect' => $this->get_return_url( $order )
			);

		} else {
			wc_add_notice( esc_html__('Something wen\'t wrong, Please try again.', 'wpbkash'), 'error' );
			return;
		}

	}

}
