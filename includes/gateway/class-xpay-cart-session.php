<?php
/**
 * XPay_Cart_Session
 *
 * The checkout page's session, and the rule that keeps its amount honest.
 *
 * The pay page has an order, so its total is already final and its session
 * is created once and left alone. The checkout page has neither: the shopper
 * is still choosing shipping and typing coupons while the payment fields are
 * already mounted on the page, bound to a client secret. So the session has
 * to exist before the order does, and its amount has to follow the cart.
 *
 * Replacing the session on every total change is not an option here. A new
 * session means a new client secret, and re-mounting on a new secret throws
 * away whatever the shopper has typed into the card fields. So the session
 * stays and the amount moves, which is a deliberate exception to this
 * plugin's create-and-expire rule, taken because the alternative is worse
 * for the shopper.
 *
 * WHAT THE PLATFORM WILL NOT DO FOR US
 *
 * It refuses updates to a session that is not open, and refuses to change
 * mode, uiMode, submitType, currency or expiresAfterMinutes at all. It does
 * NOT refuse an update while a payment is running, because a session with a
 * payment in flight is still open. Moving the total under a shopper who has
 * already pressed pay is therefore possible, and it is exactly the failure
 * this class exists to prevent.
 *
 * THE GUARD
 *
 * The browser announces a payment before it starts one and again when it
 * ends. Between those two points this class refuses every amount change and
 * says so, rather than applying it quietly or throwing. The lock is held in
 * the WooCommerce session, so it is per shopper, and it carries a timestamp
 * so a browser that never reports back cannot freeze the cart forever.
 *
 * Fail closed: when anything about the lock is unreadable, the update is
 * refused. A refused update leaves a correct session with a stale amount,
 * which the confirm-time check catches. An allowed update at the wrong
 * moment charges the wrong number.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Cart_Session {

	/** WooCommerce session key holding the cart's session id. */
	const SESSION_ID_KEY = 'xpay_cart_session_id';

	/** WooCommerce session key holding its client secret. */
	const CLIENT_SECRET_KEY = 'xpay_cart_client_secret';

	/** WooCommerce session key holding the minor-unit amount last sent. */
	const AMOUNT_KEY = 'xpay_cart_amount';

	/** WooCommerce session key holding the currency last sent. */
	const CURRENCY_KEY = 'xpay_cart_currency';

	/** WooCommerce session key holding the payment lock's timestamp. */
	const PAYMENT_LOCK_KEY = 'xpay_cart_payment_started_at';

	/**
	 * How long a payment lock is honored without word from the browser.
	 *
	 * Long enough for a 3-D Secure challenge on a slow phone, short enough
	 * that a shopper who closed the tab mid-payment is not stuck with a
	 * frozen cart. A browser that finishes normally clears the lock itself
	 * and never reaches this.
	 */
	const PAYMENT_LOCK_TTL = 900;

	/** @var XPay_Api_Client */
	private $client;

	public function __construct( XPay_Api_Client $client ) {
		$this->client = $client;
	}

	/* ── The payment lock ────────────────────────────────────────────── */

	/**
	 * Announce that a payment is starting. Amount changes are refused from
	 * here until payment_finished() or the TTL expires.
	 */
	public function payment_started(): void {
		$this->session_set( self::PAYMENT_LOCK_KEY, time() );
	}

	/** Announce that the payment ended, however it ended. */
	public function payment_finished(): void {
		$this->session_set( self::PAYMENT_LOCK_KEY, null );
	}

	/**
	 * Whether a payment is running right now.
	 *
	 * Unreadable state counts as locked. The cost of a false lock is a
	 * refused update; the cost of a false unlock is a shopper charged an
	 * amount they never saw.
	 */
	public function payment_in_flight(): bool {
		$started = $this->session_get( self::PAYMENT_LOCK_KEY );
		if ( null === $started || '' === $started ) {
			return false;
		}
		if ( ! is_numeric( $started ) ) {
			return true;
		}
		return ( time() - (int) $started ) < self::PAYMENT_LOCK_TTL;
	}

	/* ── Amount sync ─────────────────────────────────────────────────── */

	/**
	 * Bring the session's amount in line with the cart.
	 *
	 * Returns one of 'unchanged', 'updated', 'locked' or 'none' so the
	 * caller can tell the difference between nothing to do, work done, and
	 * work deliberately refused. A refusal is not an error and must not read
	 * like one: the shopper is mid-payment and the old amount is the amount
	 * they agreed to.
	 *
	 * @param int    $amount_minor Cart total in minor units.
	 * @param string $currency     Cart currency, ISO 4217.
	 */
	public function sync_amount( int $amount_minor, string $currency ): string {
		$session_id = $this->session_id();
		if ( '' === $session_id ) {
			return 'none';
		}

		// Currency is immutable on the platform, so a currency change is not
		// an update at all: the session is wrong and must be rebuilt. Say so
		// rather than sending a PATCH that is guaranteed to be rejected.
		$known_currency = (string) $this->session_get( self::CURRENCY_KEY );
		if ( '' !== $known_currency && strtoupper( $known_currency ) !== strtoupper( $currency ) ) {
			return 'currency-changed';
		}

		$known_amount = $this->session_get( self::AMOUNT_KEY );
		if ( null !== $known_amount && (int) $known_amount === $amount_minor ) {
			return 'unchanged';
		}

		if ( $this->payment_in_flight() ) {
			XPay_Logger::event(
				'cart_session.update_refused',
				array(
					'session_id' => $session_id,
					'reason'     => 'payment_in_flight',
					'from'       => null === $known_amount ? null : (int) $known_amount,
					'to'         => $amount_minor,
				)
			);
			return 'locked';
		}

		$this->client->update_checkout_session(
			$session_id,
			array(
				'lineItems' => array(
					array(
						'name'       => $this->line_item_name(),
						'unitAmount' => $amount_minor,
						'quantity'   => 1,
					),
				),
			),
			$this->update_key( $session_id, $amount_minor )
		);

		$this->session_set( self::AMOUNT_KEY, $amount_minor );
		$this->session_set( self::CURRENCY_KEY, strtoupper( $currency ) );

		XPay_Logger::event(
			'cart_session.amount_updated',
			array(
				'session_id' => $session_id,
				'from'       => null === $known_amount ? null : (int) $known_amount,
				'to'         => $amount_minor,
			)
		);

		return 'updated';
	}

	/**
	 * The amount this session was last told about, or null when it has not
	 * been told anything. Confirm-time checks compare the cart against this
	 * rather than trusting the browser's copy.
	 */
	public function known_amount(): ?int {
		$known = $this->session_get( self::AMOUNT_KEY );
		return null === $known || '' === $known ? null : (int) $known;
	}

	/* ── Creation ────────────────────────────────────────────────────── */

	/**
	 * The cart's session, creating one if there is not a usable one yet.
	 *
	 * Returns { id, clientSecret } or null when a session cannot be had.
	 *
	 * uiMode is "custom", which is what makes the fields render on the
	 * store's page rather than in a window. That choice brings three
	 * platform rules with it, all enforced at creation: cancelUrl is
	 * banned, afterCompletion must be a redirect and is required, and the
	 * five customer-collection flags are rejected outright. So none of them
	 * are sent, and the shopper details ride at confirm time instead.
	 *
	 * @param int    $amount_minor Cart total in minor units.
	 * @param string $currency     Cart currency.
	 * @param string $return_url   Where the shopper lands after paying.
	 * @return array|null { id, clientSecret }
	 * @throws XPay_Api_Exception When creation fails.
	 */
	public function ensure( int $amount_minor, string $currency, string $return_url ): ?array {
		$existing = $this->session_id();
		if ( '' !== $existing && '' !== $this->client_secret() ) {
			$known = (string) $this->session_get( self::CURRENCY_KEY );
			if ( '' === $known || strtoupper( $known ) === strtoupper( $currency ) ) {
				return array(
					'id'           => $existing,
					'clientSecret' => $this->client_secret(),
				);
			}
			// Currency is immutable, so a changed store currency means this
			// session can never be right again. Start over rather than
			// PATCH something the platform will refuse.
			$this->forget();
		}

		$created = $this->client->create_checkout_session(
			array(
				'mode'            => 'payment',
				'uiMode'          => 'custom',
				'currency'        => strtoupper( $currency ),
				'lineItems'       => array(
					array(
						'name'       => $this->line_item_name(),
						'unitAmount' => $amount_minor,
						'quantity'   => 1,
					),
				),
				'afterCompletion' => array(
					'type'     => 'redirect',
					'redirect' => array( 'url' => $return_url ),
				),
				'metadata'        => array( 'integration' => 'woocommerce' ),
			),
			$this->create_key( $amount_minor, $currency )
		);

		$id     = (string) ( $created['id'] ?? '' );
		$secret = (string) ( $created['clientSecret'] ?? '' );
		if ( '' === $id || '' === $secret ) {
			return null;
		}

		$this->remember( $id, $secret, $amount_minor, $currency );

		XPay_Logger::event(
			'cart_session.created',
			array(
				'session_id' => $id,
				'amount'     => $amount_minor,
				'currency'   => strtoupper( $currency ),
			)
		);

		return array(
			'id'           => $id,
			'clientSecret' => $secret,
		);
	}

	/**
	 * Idempotency key for creating this cart's session.
	 *
	 * Scoped to the WooCommerce customer and the amount, so a double-submit
	 * from one shopper collapses while two shoppers never collide.
	 *
	 * @param int    $amount_minor Amount at creation.
	 * @param string $currency     Currency at creation.
	 */
	private function create_key( int $amount_minor, string $currency ): string {
		$who = '';
		if ( function_exists( 'WC' ) && WC()->session ) {
			$who = (string) WC()->session->get_customer_id();
		}
		return 'cartnew_' . md5( $who . '|' . $amount_minor . '|' . strtoupper( $currency ) );
	}

	/* ── Session identity ────────────────────────────────────────────── */

	/** The cart's session id, or an empty string when there is none yet. */
	public function session_id(): string {
		return (string) $this->session_get( self::SESSION_ID_KEY );
	}

	/** The cart's client secret, or an empty string when there is none. */
	public function client_secret(): string {
		return (string) $this->session_get( self::CLIENT_SECRET_KEY );
	}

	/**
	 * Remember a freshly created session.
	 *
	 * @param string $session_id    cs_… id.
	 * @param string $client_secret Secret the browser mounts against.
	 * @param int    $amount_minor  Amount it was created with.
	 * @param string $currency      Currency it was created with.
	 */
	public function remember( string $session_id, string $client_secret, int $amount_minor, string $currency ): void {
		$this->session_set( self::SESSION_ID_KEY, $session_id );
		$this->session_set( self::CLIENT_SECRET_KEY, $client_secret );
		$this->session_set( self::AMOUNT_KEY, $amount_minor );
		$this->session_set( self::CURRENCY_KEY, strtoupper( $currency ) );
		$this->session_set( self::PAYMENT_LOCK_KEY, null );
	}

	/**
	 * Forget the cart's session entirely.
	 *
	 * Used when the currency changed under it, and after the order is placed
	 * so the next cart starts clean rather than inheriting a paid session.
	 */
	public function forget(): void {
		foreach ( array( self::SESSION_ID_KEY, self::CLIENT_SECRET_KEY, self::AMOUNT_KEY, self::CURRENCY_KEY, self::PAYMENT_LOCK_KEY ) as $key ) {
			$this->session_set( $key, null );
		}
	}

	/* ── Internals ───────────────────────────────────────────────────── */

	/**
	 * Idempotency key for one amount on one session. Two identical updates
	 * collapse; a different amount is a different key and goes through.
	 *
	 * @param string $session_id   cs_… id.
	 * @param int    $amount_minor Amount being set.
	 */
	private function update_key( string $session_id, int $amount_minor ): string {
		return 'cartupd_' . $session_id . '_' . $amount_minor;
	}

	/**
	 * The single line item a cart session carries.
	 *
	 * Deliberately one line rather than a mirror of the cart. The shopper
	 * reads their basket on the store's own page, the payment form shows a
	 * total, and mirroring every line would put two lists that can disagree
	 * in front of the same person. The order carries the real breakdown.
	 */
	private function line_item_name(): string {
		return __( 'Order total', 'xpay-for-woocommerce' );
	}

	/**
	 * Read one value from the WooCommerce session.
	 *
	 * @param string $key Session key.
	 * @return mixed Null when WooCommerce has no session yet.
	 */
	private function session_get( string $key ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		return WC()->session->get( $key );
	}

	/**
	 * Write one value to the WooCommerce session. Null removes it.
	 *
	 * @param string $key   Session key.
	 * @param mixed  $value Value, or null to unset.
	 */
	private function session_set( string $key, $value ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		WC()->session->set( $key, $value );
	}
}
