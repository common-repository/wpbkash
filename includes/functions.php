<?php
/**
 * Short description for file
 *
 * @package    WPbKash
 * @author     themepaw <themepaw@gmail.com>
 * @author     mlimon <mlimonbd@gmail.com>
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GPLv2 Or Later
 * @version    0.1
 */

/**
 * Get all entry
 *
 * @param array $args
 * @return object
 */
function wpbkash_get_all_entry( $args = array() ) {
	global $wpdb;

	$defaults = array(
		'number'  => 20,
		'offset'  => 0,
		'orderby' => 'id',
		'status'  => '',
		's'       => '',
		'order'   => 'DESC',
	);

	$table = $wpdb->prefix . 'wpbkash';

	$args      = wp_parse_args( $args, $defaults );
	$cache_key = 'entry-all';
	$items     = wp_cache_get( $cache_key, 'wpbkash' );

	if ( false === $items ) {

		$sql = "SELECT * FROM $table";

		if ( ! empty( $args['s'] ) ) {
			$search = esc_sql( $args['s'] );
			$sql   .= " WHERE trx_id LIKE '%{$search}%'";
			$sql   .= " OR sender = '{$search}'";
			$sql   .= " OR status = '{$search}'";
			$sql   .= " OR invoice = '{$search}'";
			$sql   .= " OR ref = '{$search}'";
		}

		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$status = esc_sql( $args['status'] );
			$sql   .= " WHERE status = '{$status}'";
		}

		if ( ! empty( $args['orderby'] ) ) {
			$sql .= ' ORDER BY ' . esc_sql( $args['orderby'] );
			$sql .= ! empty( $args['order'] ) ? ' ' . esc_sql( $args['order'] ) : ' ASC';
		}

		$sql .= ' LIMIT ' . esc_sql( $args['offset'] ) . '';
		$sql .= ', ' . esc_sql( $args['number'] ) . '';

		$items = $wpdb->get_results( $sql );

		wp_cache_set( $cache_key, $items, 'wpbkash' );
	}

	return $items;
}

/**
 * Get entry count
 *
 * @param string $status
 *
 * @return int
 */
function wpbkash_get_count( $status = '' ) {

	global $wpdb;

	$table = $wpdb->prefix . 'wpbkash';

	$sql = "SELECT count(id) FROM $table";

	if ( ! empty( $status ) && 'all' !== $status ) {
		$status = esc_sql( $status );
		$sql   .= " WHERE status = '{$status}'";
	}

	$count = $wpdb->get_var( $sql );

	return $count;
}

/**
 * Fetch all entry from database
 *
 * @return array
 */
function wpbkash_get_entry_count() {
	global $wpdb;

	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'wpbkash' );
}

/**
 * Fetch a single entry from database
 *
 * @param int $id
 *
 * @return array
 */
function wpbkash_get_entry( $id = 0 ) {
	global $wpdb;

	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'wpbkash WHERE id = %d', $id ) );
}


/**
 * Does this user exist?
 *
 * @param  int|string|WP_User $user_id User ID or object.
 * @return bool                        Whether the user exists.
 */
function wpbkash_user_exist( $user_id = '' ) {
	if ( $user_id instanceof WP_User ) {
		$user_id = $user_id->ID;
	}
	return (bool) get_user_by( 'id', $user_id );
}

/**
 * Delete Entry
 *
 * @param  int $entry_id
 * @return int|false The number of rows updated, or false on error.
 */
function wpbkash_delete_entry( $entry_id ) {
	global $wpdb;

	return $wpdb->delete( $wpdb->prefix . 'wpbkash', array( 'id' => absint( $entry_id ) ), array( '%d' ) );
}

/**
 * Get entry by order as referenace type
 *
 * @param int    $order_id
 * @param string $type reference type
 *
 * @return NULL|int
 */
function wpbkash_get_id( $order_id, $type = 'wc_order' ) {
	global $wpdb;

	$result = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wpbkash WHERE ref='%s' AND ref_id='%d'", sanitize_text_field( $type ), absint( $order_id ) ) );
	if ( isset( $result ) && isset( $result->id ) ) {
		return $result->id;
	}
	return $result;
}

/**
 * Generat uniq key
 */
function wpbkash_get_payout_key() {
	 $str   = rand();
	$hashed = md5( $str );
	return $hashed;
}

/**
 * Generate unique payour url
 *
 * @param string $email
 * @param string $key
 * @param int    $id
 *
 * @return string
 */
function wpbkash_get_payout_url( $email, $key, $id ) {
	$url = add_query_arg(
		array(
			'key'   => $key,
			'email' => $email,
		),
		home_url( "wpbkash-api/{$id}/" )
	);
	return $url;
}

/**
 * Entry Update
 *
 * @param int   $entry_id
 * @param array $fields
 * @param array $escapes
 *
 * @return int|false
 */
function wpbkash_entry_update( $entry_id, $fields, $escapes ) {
	global $wpdb;

	$table = $wpdb->prefix . 'wpbkash';

	$updated = $wpdb->update(
		$table,
		$fields,
		array( 'id' => absint( $entry_id ) ),
		$escapes
	);

	return $updated;
}


/**
 * Default email text
 */
function wpbkash_pay_default_template() {
	/* translators: Do not translate Shortcode like [wpbkash-sitename], [wpbkash-paymenturl], [wpbkash-siteurl]; those are placeholders. */
	$email_text = esc_html__(
		'Dear [your-name],

Please click on the following link to verify your email address and to proceed with the payment. Note that without the email verification and payment – your registration will not be completed for the event. You will need to verify your email address within 10 minutes.

Click on the link bellow to verify your email address and pay:

[wpbkash-paymenturl]
Amount: [wpbkash-amount]

This is an auto generated email and please do not reply to this email. If you have any question, please write to [wpbkass-admin]

Regards,
All at [wpbkash-sitename]
Tel: +880 0000-00000, 
[wpbkash-siteurl]',
		'wpbkash'
	);

	$email_text = apply_filters( 'wpbkash_pay_use_html', $email_text );
	return $email_text;
}


/**
 * Default email text
 */
function wpbkash_confirmation_default_template() {
	/* translators: Do not translate Shortcode like [wpbkash-sitename], [wpbkash-paymenturl], [wpbkash-siteurl]; those are placeholders. */
	$email_text = esc_html__(
		'Dear [your-name],

Congratulations!

You are now successfully registered for the Dhaka Half Marathon 2020. Please take a print of this email and keep it for collecting your t-shirt and running bib. Your registration details are as follows:

Registration Details:

Full Name: [your-name]
Email: [your-email]
Transaction ID: [wpbkash-amount]
Registration ID:
Blah blah .....

We wish you all the best for the event.

Regards,
All at [wpbkash-sitename]
Tel: +880 0000-00000, 
[wpbkash-siteurl]

This is an auto generated email and please do not reply to this email. If you have any question, please write to contact@example.com',
		'wpbkash'
	);

	$email_text = apply_filters( 'wpbkash_confirm_use_html', $email_text );
	return $email_text;
}

/**
 * bKash Fee handler
 */
function wpbkash_bkash_fees( $cart_object ) {

	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	$payment_method = WC()->session->get( 'chosen_payment_method' );

	$extra_fields = get_option( 'wpbkash_extra_fields' );
	if ( ! isset( $extra_fields['enable'] ) || '1' != $extra_fields['enable'] || $payment_method !== 'wpbkash' ) {
		return;
	}

	$type   = $extra_fields['type'];
	$amount = (float) $extra_fields['amount'];

	$cart_total = $cart_object->subtotal_ex_tax;
	if ( isset( $extra_fields['shipping'] ) && '1' == $extra_fields['shipping'] ) {
		$cart_total = $cart_total + $cart_object->shipping_total;
	}

	$minimum   = $extra_fields['minimum'] ? $extra_fields['minimum'] : '';
	$maximum   = $extra_fields['maximum'] ? $extra_fields['maximum'] : '';
	$label     = $extra_fields['label'] ? $extra_fields['label'] : __( 'bKash fee', 'wpbkash' );
	$taxable   = ( isset( $extra_fields['tax'] ) && '1' == $extra_fields['tax'] ) ? true : false;
	$tax_class = $extra_fields['tax_class'] ? $extra_fields['tax_class'] : 'standard';

	if ( ! empty( $minimum ) && $cart_total < $minimum ) {
		return;
	}

	if ( ! empty( $maximum ) && $cart_total > $maximum ) {
		return;
	}

	if ( $type == 'percentage' ) {
		$amount = number_format( ( $cart_total / 100 ) * $amount, 2 );
	} else {
		$amount = number_format( $amount, 2 );
	}

	$cart_object->add_fee( $label, $amount, $taxable, $tax_class );
}
add_action( 'woocommerce_cart_calculate_fees', 'wpbkash_bkash_fees' );


 /**
	 * Get the final amount after apply bkash fee/charge
	 *
	 * @since 1.3.0
	 *
	 * @param $amount
	 *
	 * @return float|int
	 */
	function wpbkash_get_amount( $cart_total ) {

        $extra_fields = get_option( 'wpbkash_extra_fields' );
        if ( ! isset( $extra_fields['enable'] ) || '1' != $extra_fields['enable'] ) {
            return $cart_total;
        }

        $type   = $extra_fields['type'];
        $fee_amount = (float) $extra_fields['amount'];

        $minimum   = $extra_fields['minimum'] ? $extra_fields['minimum'] : '';
        $maximum   = $extra_fields['maximum'] ? $extra_fields['maximum'] : '';

        if ( ! empty( $minimum ) && $cart_total < $minimum ) {
            return $cart_total;
        }

        if ( ! empty( $maximum ) && $cart_total > $maximum ) {
            return $cart_total;
        }

		if ( $type == 'percentage' ) {
            $cart_total = $cart_total + $cart_total * ( $fee_amount / 100 );
        } else {
            $cart_total = $cart_total + $fee_amount;
        }

		$cart_total = number_format($cart_total, 2, '.', '');

		return $cart_total;
	}