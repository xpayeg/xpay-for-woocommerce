<?php
/**
 * A WC_Order stand-in exposing exactly the surface the XPay state
 * machines touch, with recorders (notes, status history, saves) for
 * assertions. One deliberate divergence from production: wc_get_order()
 * returns the same instance every time, so XPay_Order_Sync::reload()
 * degrades to "same object" — acceptable because these tests pin
 * decision logic, not cache-coherency mechanics.
 *
 * @package XPay_For_WooCommerce
 */

/**
 * A fee line, as WooCommerce models one.
 *
 * Enough of it for the pass-through fee path: XPay's fee is charged to the
 * shopper on top of the basket, so the order has to carry a line saying so
 * or its total is a lie in every email and report.
 */
class WC_Order_Item_Fee {

	public $name  = '';
	public $total = '0';
	public $tax_status = 'taxable';

	public function set_name( $name ) {
		$this->name = (string) $name;
	}

	public function set_amount( $amount ) {
		$this->total = (string) $amount;
	}

	public function set_total( $total ) {
		$this->total = (string) $total;
	}

	public function set_tax_status( $status ) {
		$this->tax_status = (string) $status;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_total() {
		return $this->total;
	}
}

class WC_Order {

	public $id;
	public $total          = '0';
	/** What WooCommerce has already recorded as refunded on this order. */
	public $total_refunded = '0';
	public $currency       = 'EGP';
	public $status         = 'pending';
	public $paid           = false;
	public $user_id        = 0;
	public $payment_method = 'xpay';
	public $order_key      = 'wc_order_testkey';
	public $transaction_id = '';
	public $meta           = array();
	public $notes          = array();
	public $status_history = array();
	public $saves          = 0;

	public $billing_first_name = '';
	public $billing_last_name  = '';
	public $billing_email      = '';
	public $billing_phone      = '';

	public $billing_address_1 = '';
	public $billing_address_2 = '';
	public $billing_city      = '';
	public $billing_state     = '';
	public $billing_postcode  = '';
	public $billing_country   = '';

	public $shipping_first_name = '';
	public $shipping_last_name  = '';
	public $shipping_phone      = '';
	public $shipping_address_1  = '';
	public $shipping_address_2  = '';
	public $shipping_city       = '';
	public $shipping_state      = '';
	public $shipping_postcode   = '';
	public $shipping_country    = '';

	public function __construct( int $id ) {
		$this->id = $id;
	}

	public function get_id() {
		return $this->id;
	}
	public function get_total() {
		return $this->total;
	}
	public function get_total_refunded() {
		return $this->total_refunded;
	}
	public function get_currency() {
		return $this->currency;
	}
	public function get_user_id() {
		return $this->user_id;
	}
	public function get_payment_method() {
		return $this->payment_method;
	}
	public function get_order_key() {
		return $this->order_key;
	}
	public function get_order_number() {
		return (string) $this->id;
	}

	public function get_meta( $key = '' ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}
	/** Fee lines added to this order, in order. */
	public $fee_items = array();

	public function add_item( $item ) {
		$this->fee_items[] = $item;
	}

	public function get_items( $type = 'line_item' ) {
		return 'fee' === $type ? $this->fee_items : array();
	}

	public function set_total( $total ) {
		$this->total = (string) $total;
	}

	public function save() {
		++$this->saves;
		return $this->id;
	}

	public function is_paid() {
		return $this->paid;
	}
	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
	}
	public function update_status( $status, $note = '' ) {
		$this->status           = $status;
		$this->status_history[] = $status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
		++$this->saves;
		return true;
	}
	public function get_transaction_id() {
		return $this->transaction_id;
	}
	public function set_transaction_id( $transaction_id ) {
		$this->transaction_id = (string) $transaction_id;
	}
	public function payment_complete( $transaction_id = '' ) {
		$this->paid             = true;
		$this->status           = 'processing';
		$this->status_history[] = 'processing';
		$this->transaction_id   = $transaction_id;
		++$this->saves;
		return true;
	}
	public function add_order_note( $note ) {
		$this->notes[] = $note;
		return count( $this->notes );
	}

	public function get_billing_first_name() {
		return $this->billing_first_name;
	}
	public function get_billing_last_name() {
		return $this->billing_last_name;
	}
	public function get_billing_email() {
		return $this->billing_email;
	}
	public function get_billing_phone() {
		return $this->billing_phone;
	}
	public function get_billing_address_1() {
		return $this->billing_address_1;
	}
	public function get_billing_address_2() {
		return $this->billing_address_2;
	}
	public function get_billing_city() {
		return $this->billing_city;
	}
	public function get_billing_state() {
		return $this->billing_state;
	}
	public function get_billing_postcode() {
		return $this->billing_postcode;
	}
	public function get_billing_country() {
		return $this->billing_country;
	}

	public function get_shipping_first_name() {
		return $this->shipping_first_name;
	}
	public function get_shipping_last_name() {
		return $this->shipping_last_name;
	}
	public function get_shipping_phone() {
		return $this->shipping_phone;
	}
	public function get_shipping_address_1() {
		return $this->shipping_address_1;
	}
	public function get_shipping_address_2() {
		return $this->shipping_address_2;
	}
	public function get_shipping_city() {
		return $this->shipping_city;
	}
	public function get_shipping_state() {
		return $this->shipping_state;
	}
	public function get_shipping_postcode() {
		return $this->shipping_postcode;
	}
	public function get_shipping_country() {
		return $this->shipping_country;
	}
	/**
	 * Match WC_Order::has_shipping_address(): either address line is enough.
	 */
	public function has_shipping_address() {
		return '' !== $this->shipping_address_1 || '' !== $this->shipping_address_2;
	}

	public function get_checkout_order_received_url() {
		return 'https://store.test/checkout/order-received/' . $this->id . '/?key=' . $this->order_key;
	}
	public function get_checkout_payment_url() {
		return 'https://store.test/checkout/order-pay/' . $this->id . '/?pay_for_order=true&key=' . $this->order_key;
	}
}
