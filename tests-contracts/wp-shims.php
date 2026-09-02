<?php
/**
 * WordPress/WooCommerce shims for the contract suite.
 *
 * Just enough of the WP surface for the state-machine classes to run:
 * in-memory options and user meta, an order registry behind
 * wc_get_order(), pass-through i18n/escaping, and an action recorder so
 * tests can assert which XPay_Logger stages fired. Deliberately dumb —
 * any behavior a test depends on must be a behavior WordPress actually
 * has (add_query_arg really does not urlencode values, for instance).
 *
 * @package XPay_For_WooCommerce
 */

function xpay_tests_reset_world(): void {
	$GLOBALS['xpay_test_options']      = array();
	$GLOBALS['xpay_test_user_meta']    = array();
	$GLOBALS['xpay_test_orders']       = array();
	$GLOBALS['xpay_test_actions']      = array();
	$GLOBALS['xpay_test_http']           = array();
	$GLOBALS['xpay_test_http_responses'] = array();
	$GLOBALS['xpay_test_locale']       = 'en_US';
	$GLOBALS['xpay_test_wc_refunds']   = array();
	$GLOBALS['xpay_test_refund_error'] = null;
	$GLOBALS['xpay_test_wc_session']   = array();
	$GLOBALS['xpay_test_scheduled']    = array();
	$GLOBALS['wpdb']                   = new XPay_Fake_Wpdb();
}

/* ── WooCommerce session ─────────────────────────────────────────────── */

/**
 * Minimal stand-in for WooCommerce's session handler.
 *
 * Only get(), set() and the customer id are modelled, and set( key, null )
 * removes the key, which is how WooCommerce itself behaves.
 */
class XPay_Test_WC_Session {
	public function get_customer_id() {
		return 'cust_test_contract';
	}
	public function get( $key, $default_value = null ) {
		return array_key_exists( $key, $GLOBALS['xpay_test_wc_session'] )
			? $GLOBALS['xpay_test_wc_session'][ $key ]
			: $default_value;
	}
	public function set( $key, $value ) {
		if ( null === $value ) {
			unset( $GLOBALS['xpay_test_wc_session'][ $key ] );
			return;
		}
		$GLOBALS['xpay_test_wc_session'][ $key ] = $value;
	}
}

class XPay_Test_WC {
	/** @var XPay_Test_WC_Session|null Null models a request with no session. */
	public $session;
	public function __construct() {
		$this->session = new XPay_Test_WC_Session();
	}
}

function WC() {
	if ( ! isset( $GLOBALS['xpay_test_wc'] ) ) {
		$GLOBALS['xpay_test_wc'] = new XPay_Test_WC();
	}
	return $GLOBALS['xpay_test_wc'];
}

/* ── Options ─────────────────────────────────────────────────────────── */

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['xpay_test_options'] ) ? $GLOBALS['xpay_test_options'][ $name ] : $default_value;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['xpay_test_options'][ $name ] = $value;
	return true;
}
/**
 * Faithful to core in the one way the plugin depends on: it does NOT
 * overwrite. Code writing a first-time-only value reads back the original
 * on a second call, and a shim that behaved like update_option would hide
 * exactly the bug that distinction exists to prevent.
 */
function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['xpay_test_options'] ) ) {
		return false;
	}
	$GLOBALS['xpay_test_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['xpay_test_options'][ $name ] );
	return true;
}

/* ── User meta ───────────────────────────────────────────────────────── */

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['xpay_test_user_meta'][ $user_id ][ $key ] = $value;
	return true;
}
function get_user_meta( $user_id, $key, $single = false ) {
	return isset( $GLOBALS['xpay_test_user_meta'][ $user_id ][ $key ] ) ? $GLOBALS['xpay_test_user_meta'][ $user_id ][ $key ] : '';
}
function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['xpay_test_user_meta'][ $user_id ][ $key ] );
	return true;
}

/* ── Orders ──────────────────────────────────────────────────────────── */

function wc_get_order( $order_id ) {
	return isset( $GLOBALS['xpay_test_orders'][ (int) $order_id ] ) ? $GLOBALS['xpay_test_orders'][ (int) $order_id ] : false;
}
function clean_post_cache( $order_id ) {}

/**
 * Meta-query subset only — exactly the lookup the webhook controller's
 * order_by_payment_intent() issues.
 */
function wc_get_orders( $args ) {
	$matches = array();
	$clauses = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
	$limit   = isset( $args['limit'] ) ? (int) $args['limit'] : -1;
	foreach ( $GLOBALS['xpay_test_orders'] as $order ) {
		$ok = true;
		foreach ( $clauses as $clause ) {
			if ( ! is_array( $clause ) || $order->get_meta( $clause['key'] ) !== $clause['value'] ) {
				$ok = false;
				break;
			}
		}
		if ( $ok ) {
			$matches[] = $order;
			if ( $limit > 0 && count( $matches ) >= $limit ) {
				break;
			}
		}
	}
	if ( isset( $args['return'] ) && 'ids' === $args['return'] ) {
		return array_map(
			static function ( $order ) {
				return $order->get_id();
			},
			$matches
		);
	}
	return $matches;
}

function wc_price( $amount, $args = array() ) {
	$currency = isset( $args['currency'] ) ? $args['currency'] : '';
	return trim( $currency . ' ' . $amount );
}

/**
 * Records every mirror call; scripted to fail via
 * $GLOBALS['xpay_test_refund_error'] (a WP_Error to return).
 */
function wc_create_refund( $args ) {
	if ( isset( $GLOBALS['xpay_test_refund_error'] ) && null !== $GLOBALS['xpay_test_refund_error'] ) {
		return $GLOBALS['xpay_test_refund_error'];
	}
	$GLOBALS['xpay_test_wc_refunds'][] = $args;
	return new stdClass();
}

class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/* ── Hooks: recorded, never dispatched ───────────────────────────────── */

function do_action( $hook, ...$args ) {
	$GLOBALS['xpay_test_actions'][] = array_merge( array( $hook ), $args );
}
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
function apply_filters( $hook, $value ) {
	return $value;
}

/* ── Action Scheduler: queued, never run ─────────────────────────────── */

/*
 * WooCommerce bundles Action Scheduler
 * (woocommerce/packages/action-scheduler/functions.php), so on every store
 * this plugin runs on these two functions exist. While they were absent
 * here, function_exists() was false and the webhook controller's no-order
 * path took its no-scheduler branch, the one that logs order_not_found and
 * gives up, instead of the branch a real store takes, which re-queues.
 *
 * Only the two the plugin calls are defined. Adding the rest of the API
 * would be shimming behaviour nothing exercises.
 */

/**
 * Record a single scheduled action.
 *
 * $unique and $priority are accepted to match the real signature
 * (functions.php:69) and ignored: nothing here schedules with either.
 *
 * @param int    $timestamp When the action would run.
 * @param string $hook      Hook to fire.
 * @param array  $args      Args bound to the hook, positionally.
 * @param string $group     Group the action belongs to.
 * @param bool   $unique    Unused.
 * @param int    $priority  Unused.
 * @return int The action id. The real one returns zero only when Action
 *             Scheduler has not initialised, which cannot happen here.
 */
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
	$GLOBALS['xpay_test_scheduled'][] = array(
		'timestamp' => (int) $timestamp,
		'hook'      => $hook,
		'args'      => $args,
		'group'     => $group,
	);
	return count( $GLOBALS['xpay_test_scheduled'] );
}

/**
 * Whether a matching action is queued.
 *
 * Matching mirrors the real query: an empty group is not a filter
 * (ActionScheduler_DBStore.php:483), and args are compared through their
 * JSON form, which is the column the store actually compares (:260, :493).
 * That makes key ORDER significant, exactly as it is in production, which
 * is the property the requeue de-dupe depends on.
 *
 * The real function counts only pending and running actions. Nothing here
 * ever runs the queue, so every recorded action is pending.
 *
 * @param string     $hook  Hook to look for.
 * @param array|null $args  Args to match, or null to match any.
 * @param string     $group Group to match, or '' for any.
 * @return bool
 */
function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['xpay_test_scheduled'] as $action ) {
		if ( $action['hook'] !== $hook ) {
			continue;
		}
		if ( '' !== $group && $action['group'] !== $group ) {
			continue;
		}
		if ( null !== $args && wp_json_encode( $action['args'] ) !== wp_json_encode( $args ) ) {
			continue;
		}
		return true;
	}
	return false;
}

/* ── i18n / escaping: identity, formatting preserved ─────────────────── */

function __( $text, $domain = null ) {
	return $text;
}
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function esc_attr__( $text, $domain = null ) {
	return $text;
}
function esc_html( $text ) {
	return $text;
}
function esc_attr( $text ) {
	return $text;
}
function esc_url( $url ) {
	return $url;
}

/* ── URLs & misc ─────────────────────────────────────────────────────── */

function home_url( $path = '' ) {
	return 'https://store.test' . $path;
}

/**
 * Mirrors the real behavior that matters here: values are appended
 * WITHOUT urlencoding (WordPress's documented quirk), which is what
 * lets the {CHECKOUT_SESSION_ID} placeholder survive into the URL.
 */
function add_query_arg( $key, $value = null, $url = null ) {
	$args = is_array( $key ) ? $key : array( $key => $value );
	$url  = is_array( $key ) ? $value : $url;
	foreach ( $args as $k => $v ) {
		$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . $v;
	}
	return $url;
}

function get_locale() {
	return $GLOBALS['xpay_test_locale'];
}
function absint( $value ) {
	return abs( (int) $value );
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}
function wc_string_to_bool( $value ) {
	return is_bool( $value ) ? $value : ( 'yes' === $value || 1 === $value || 'true' === $value || '1' === $value );
}


/*
 * HTTP. The transport is scripted rather than stubbed at the client's public
 * methods, so tests can assert on what actually goes over the wire — the
 * Idempotency-Key in particular, which is built inside request() and is
 * invisible to every fake that overrides the methods above it.
 */
function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['xpay_test_http'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	$next = array_shift( $GLOBALS['xpay_test_http_responses'] );
	return null === $next ? array(
		'response' => array( 'code' => 200 ),
		'body'     => '{}',
	) : $next;
}
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}
