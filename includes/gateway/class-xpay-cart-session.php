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

	/** WooCommerce session key counting amount updates on this session. */
	const REVISION_KEY = 'xpay_cart_amount_revision';

	/** WooCommerce session key counting sessions this cart has been given. */
	const GENERATION_KEY = 'xpay_cart_session_generation';

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

		// The line carries a currency of its own even though the session's is
		// immutable and cannot be sent on an update: an inline price is
		// required to state one, and the platform rejects a line whose
		// currency differs from the session's. The currency guard above is
		// what makes the two agree.
		$this->client->update_checkout_session(
			$session_id,
			array( 'lineItems' => $this->line_items( $amount_minor, $currency ) ),
			$this->update_key( $session_id, $this->next_revision(), $amount_minor )
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
				// Both currencies are sent on purpose. The top-level one is
				// the session's, and omitting it does not inherit the line's:
				// it falls back to the platform default of EGP, which would
				// then disagree with the line and be refused. They must be
				// stated and they must match.
				'currency'        => strtoupper( $currency ),
				'lineItems'       => $this->line_items( $amount_minor, $currency ),
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
	 * Replace a session the shopper can no longer pay.
	 *
	 * Nothing else in this class notices that a session has died. ensure()
	 * hands back whatever the cart holds as long as the currency still
	 * agrees, and it is right to: checking with the platform on every mount
	 * would put an extra round trip in front of a shopper who almost never
	 * needs one. But a session lives a day and a WooCommerce session lives
	 * two, so a shopper who leaves a tab open overnight comes back to a
	 * secret that can never be paid again, and without this they would be
	 * handed it on every remount and every refresh for as long as their cart
	 * survives. Refreshing the page, which is what they are told to do, runs
	 * exactly the same code and returns exactly the same dead session.
	 *
	 * The page is what notices, because the platform serves an expired
	 * session with a 200 like any other and only the status inside it tells
	 * them apart. Its report is checked rather than believed: a browser that
	 * could retire a session by saying so could churn one per keystroke.
	 *
	 * A session that has been paid is deliberately left exactly where it is.
	 * It means money moved on this cart and the order that should have
	 * followed did not, and minting a payable replacement there charges the
	 * shopper a second time for what they have already bought.
	 *
	 * Returns 'restarted' when a fresh session now backs this cart, 'open'
	 * when the platform says the old one is fine and nothing was touched,
	 * 'paid' when it has already been paid for, 'none' when there was no
	 * session to begin with, or 'failed' when a replacement could not be
	 * made.
	 *
	 * @param int    $amount_minor Cart total in minor units.
	 * @param string $currency     Cart currency, ISO 4217.
	 * @param string $return_url   Where the shopper lands after paying.
	 * @return string One of the outcomes above.
	 * @throws XPay_Api_Exception When the platform cannot be reached.
	 */
	public function restart( int $amount_minor, string $currency, string $return_url ): string {
		$session_id = $this->session_id();
		if ( '' === $session_id ) {
			return 'none';
		}

		$session = $this->fetch_or_missing( $session_id );
		$status  = (string) ( $session['status'] ?? '' );

		// COMPLETE alone is not enough to refuse. A completed session that
		// took no money is spent but harmless, and treating it as paid would
		// strand a shopper who never was charged. Only money already taken
		// blocks a replacement.
		if ( XPay_Session_Status::COMPLETE === $status && XPay_Payment_Status::PAID === (string) ( $session['paymentStatus'] ?? '' ) ) {
			XPay_Logger::event(
				'cart_session.paid_without_order',
				array(
					'session_id' => $session_id,
					'amount'     => isset( $session['amountTotal'] ) ? (int) $session['amountTotal'] : null,
				)
			);
			return 'paid';
		}

		if ( XPay_Session_Status::OPEN === $status && empty( $session['isExpired'] ) ) {
			return 'open';
		}

		XPay_Logger::event(
			'cart_session.restarted',
			array(
				'session_id' => $session_id,
				'status'     => $status,
			)
		);

		$this->forget();
		return null === $this->ensure( $amount_minor, $currency, $return_url ) ? 'failed' : 'restarted';
	}

	/**
	 * Read a session, counting one the platform has never heard of as spent.
	 *
	 * A session that is not there cannot be paid any more than an expired
	 * one can, and leaving it in place points this cart at nothing for as
	 * long as the shopper keeps it. Every other failure is rethrown rather
	 * than guessed at: a transport error or an auth error says nothing about
	 * the session, which may still be open and payable, and replacing one of
	 * those is how a shopper ends up with two live sessions and pays the
	 * wrong one.
	 *
	 * @param string $session_id cs_… id.
	 * @return array The session, or a stand-in that reads as expired.
	 * @throws XPay_Api_Exception When the failure is not about the session.
	 */
	private function fetch_or_missing( string $session_id ): array {
		try {
			return $this->client->get_checkout_session( $session_id );
		} catch ( XPay_Api_Exception $e ) {
			if ( XPay_Error_Codes::API_RESOURCE_MISSING !== $e->get_error_code() && 404 !== $e->get_http_status() ) {
				throw $e;
			}
			return array( 'status' => XPay_Session_Status::EXPIRED );
		}
	}

	/**
	 * Idempotency key for creating this cart's session.
	 *
	 * Scoped to the WooCommerce customer and the amount, so a double-submit
	 * from one shopper collapses while two shoppers never collide. Collapsing
	 * those is the whole point: the page asks for a session from more than
	 * one place, and two asks that race must not leave two sessions behind.
	 *
	 * The generation is what keeps that from reaching further than intended.
	 * A completed answer stays replayable for a day, and a shopper who buys
	 * the same basket twice in that day, or comes back to a cart whose
	 * session has expired under it, would otherwise be handed the previous
	 * session again: same customer, same amount, same currency, same key.
	 * The one thing that has changed is that the earlier session was thrown
	 * away, so that is what the key is made to notice.
	 *
	 * @param int    $amount_minor Amount at creation.
	 * @param string $currency     Currency at creation.
	 */
	private function create_key( int $amount_minor, string $currency ): string {
		$who = '';
		if ( function_exists( 'WC' ) && WC()->session ) {
			$who = (string) WC()->session->get_customer_id();
		}
		return 'cartnew_' . md5( $who . '|' . $this->generation() . '|' . $amount_minor . '|' . strtoupper( $currency ) );
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
	 * Used when the currency changed under it, when it expired under the
	 * shopper, and after the order is placed so the next cart starts clean
	 * rather than inheriting a paid session.
	 */
	public function forget(): void {
		foreach ( array( self::SESSION_ID_KEY, self::CLIENT_SECRET_KEY, self::AMOUNT_KEY, self::CURRENCY_KEY, self::PAYMENT_LOCK_KEY, self::REVISION_KEY ) as $key ) {
			$this->session_set( $key, null );
		}

		// Advanced rather than cleared. Whatever this cart is given next is a
		// different session from the one just dropped, and its creation must
		// not be answered out of the cache of the one that is gone.
		$this->session_set( self::GENERATION_KEY, $this->generation() + 1 );
	}

	/* ── Internals ───────────────────────────────────────────────────── */

	/**
	 * Idempotency key for one amount update on one session.
	 *
	 * The counter is the part that matters, and it is not bookkeeping. A key
	 * built from the amount alone is a key the platform has already seen the
	 * moment a cart returns to a total it held before — apply a coupon,
	 * remove it, apply it again — and a repeated key whose body matches is
	 * replayed rather than run: the first call's answer comes back, the
	 * session keeps the total the shopper has just moved away from, and this
	 * plugin records the one they moved to. Nothing looks wrong afterwards.
	 * The confirm-time check compares the cart against what we believe we
	 * sent, both agree, and the shopper is charged the other number.
	 *
	 * So every attempt gets its own key, and two updates are only ever "the
	 * same request" when they genuinely are one.
	 *
	 * The amount rides along too. A counter is only as durable as the
	 * WooCommerce session it lives in, and if a request dies before that is
	 * written back the next update reuses the number. With the amount in the
	 * key that collides into a replay of the identical update, which is
	 * harmless; without it, it would be a reused key carrying a different
	 * body, which the platform refuses outright.
	 *
	 * @param string $session_id   cs_… id.
	 * @param int    $revision     Which update this is on that session.
	 * @param int    $amount_minor Amount being set.
	 */
	private function update_key( string $session_id, int $revision, int $amount_minor ): string {
		return 'cartupd_' . $session_id . '_' . $revision . '_' . $amount_minor;
	}

	/**
	 * The number of the update about to be sent, counting from one.
	 *
	 * Advanced before the call rather than after it: a PATCH whose response
	 * was lost still reached the platform, and the retry that follows must
	 * not be answered out of its cache.
	 */
	private function next_revision(): int {
		$current = $this->session_get( self::REVISION_KEY );
		$next    = ( is_numeric( $current ) ? (int) $current : 0 ) + 1;
		$this->session_set( self::REVISION_KEY, $next );
		return $next;
	}

	/**
	 * How many sessions this cart has been through.
	 *
	 * Only ever advanced by forget(), which is to say only when a session is
	 * deliberately discarded.
	 */
	private function generation(): int {
		$current = $this->session_get( self::GENERATION_KEY );
		return is_numeric( $current ) ? (int) $current : 0;
	}

	/**
	 * The single line item a cart session carries.
	 *
	 * Deliberately one line rather than a mirror of the cart. The shopper
	 * reads their basket on the store's own page, the payment form shows a
	 * total, and mirroring every line would put two lists that can disagree
	 * in front of the same person. The order carries the real breakdown.
	 *
	 * The amount rides inside an inline price because that is the only way
	 * to state one the store has not pre-registered as a price object, and
	 * the name the shopper reads lives under it. Anything outside that shape
	 * is rejected rather than ignored, so create and update build the line
	 * here instead of writing it out twice and drifting.
	 *
	 * @param int    $amount_minor Line amount in minor units.
	 * @param string $currency     Line currency, ISO 4217.
	 * @return array One-element lineItems payload.
	 */
	private function line_items( int $amount_minor, string $currency ): array {
		return array(
			array(
				'priceData' => array(
					'currency'    => strtoupper( $currency ),
					'unitAmount'  => $amount_minor,
					'productData' => array(
						'name' => __( 'Order total', 'xpay-for-woocommerce' ),
					),
				),
				'quantity'  => 1,
			),
		);
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
