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

class WC_Order {

	public $id;
	public $total          = '0';
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

	public function __construct( int $id ) {
		$this->id = $id;
	}

	public function get_id() {
		return $this->id;
	}
	public function get_total() {
		return $this->total;
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

	public function get_checkout_order_received_url() {
		return 'https://store.test/checkout/order-received/' . $this->id . '/?key=' . $this->order_key;
	}
	public function get_checkout_payment_url() {
		return 'https://store.test/checkout/order-pay/' . $this->id . '/?pay_for_order=true&key=' . $this->order_key;
	}
}
