<?php
/**
 * wpdb stand-in for XPay_Order_Lock: scripts GET_LOCK answers per call
 * ('1' granted, '0' busy, null errored) and records every prepared
 * statement so tests can assert lock names and RELEASE_LOCK pairing.
 *
 * @package XPay_For_WooCommerce
 */

class XPay_Fake_Wpdb {

	/** @var array Queue of GET_LOCK answers; '1' when empty. */
	public $lock_results = array();

	/** @var string[] Every statement passed through prepare(). */
	public $statements = array();

	public $last_error = '';

	public function prepare( $query, ...$args ) {
		$query = str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query );
		return vsprintf( $query, $args );
	}

	public function get_var( $query ) {
		$this->statements[] = $query;
		if ( 0 === strpos( $query, 'SELECT GET_LOCK' ) ) {
			return array() === $this->lock_results ? '1' : array_shift( $this->lock_results );
		}
		return null;
	}

	public function query( $query ) {
		$this->statements[] = $query;
		return 1;
	}
}
