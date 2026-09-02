<?php
/**
 * Connect with XPay: the pure decision tables.
 *
 * Three questions with security consequences, answered by pure functions
 * so every row of each table is pinned without WordPress: when a Connect
 * click must (re)register the OAuth client, why a returning flow must be
 * refused, and when a token response cannot be trusted. Plus the PKCE
 * challenge, pinned to RFC 7636's own appendix B vector — a homegrown
 * assertion recomputed with the implementation's formula would prove
 * nothing.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class ConnectDecisionsTest extends TestCase {

	private const CALLBACK = 'https://store.example/?wc-api=xpay_connect';
	private const NOW      = 1700000000;

	/* ── PKCE ────────────────────────────────────────────────────────── */

	public function test_challenge_matches_the_rfc_7636_vector(): void {
		// RFC 7636 appendix B: the one verifier/challenge pair published
		// by the spec's authors.
		$this->assertSame(
			'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			XPay_Connect::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' )
		);
	}

	/* ── Client registration decisions ───────────────────────────────── */

	/**
	 * @dataProvider registration_cases
	 * @param array|null $stored   Stored client record.
	 * @param bool       $expected Must register.
	 */
	public function test_client_registration_decision( ?array $stored, bool $expected ): void {
		$this->assertSame( $expected, XPay_Connect::client_needs_registration( $stored, self::CALLBACK, self::NOW ) );
	}

	public function registration_cases(): array {
		$fresh = array(
			'client_id'    => 'cid_1',
			'redirect_uri' => self::CALLBACK,
			'created_at'   => self::NOW - 60,
			'completed_at' => 0,
		);
		$aged  = self::NOW - XPay_Connect::CLIENT_STALE_SECONDS - 1;

		return array(
			'nothing stored registers'                => array( null, true ),
			'missing client_id registers'             => array( array( 'redirect_uri' => self::CALLBACK ), true ),
			'empty client_id registers'               => array(
				array_merge( $fresh, array( 'client_id' => '' ) ),
				true,
			),
			'fresh matching client is reused'         => array( $fresh, false ),
			'moved host registers'                    => array(
				array_merge( $fresh, array( 'redirect_uri' => 'https://old-host.example/?wc-api=xpay_connect' ) ),
				true,
			),
			'stale never-completed registers'         => array(
				array_merge( $fresh, array( 'created_at' => $aged ) ),
				true,
			),
			'stale but completed once is kept'        => array(
				array_merge(
					$fresh,
					array(
						'created_at'   => $aged,
						'completed_at' => $aged + 100,
					)
				),
				false,
			),
			'exactly at the stale boundary is kept'   => array(
				array_merge( $fresh, array( 'created_at' => self::NOW - XPay_Connect::CLIENT_STALE_SECONDS ) ),
				false,
			),
		);
	}

	/* ── Flow verification decisions ─────────────────────────────────── */

	/**
	 * @dataProvider flow_cases
	 * @param array|null  $flow     Stored flow record.
	 * @param string      $state    State the redirect carried.
	 * @param int         $user_id  User at the callback.
	 * @param int         $now      UTC seconds.
	 * @param string|null $expected Refusal reason, null = valid.
	 */
	public function test_flow_verification_decision( ?array $flow, string $state, int $user_id, int $now, ?string $expected ): void {
		$this->assertSame( $expected, XPay_Connect::flow_error( $flow, $state, $user_id, $now ) );
	}

	public function flow_cases(): array {
		$flow = array(
			'state'      => 'state-abc',
			'verifier'   => 'verifier-abc',
			'live'       => false,
			'user_id'    => 7,
			'created_at' => self::NOW - 60,
		);

		return array(
			'valid flow passes'              => array( $flow, 'state-abc', 7, self::NOW, null ),
			'no flow stored'                 => array( null, 'state-abc', 7, self::NOW, 'missing' ),
			'half a record is missing'       => array( array( 'state' => 'state-abc' ), 'state-abc', 7, self::NOW, 'missing' ),
			'non-string state is missing'    => array( array_merge( $flow, array( 'state' => 5 ) ), 'state-abc', 7, self::NOW, 'missing' ),
			'wrong state refused'            => array( $flow, 'state-FORGED', 7, self::NOW, 'state_mismatch' ),
			'empty state refused'            => array( $flow, '', 7, self::NOW, 'state_mismatch' ),
			'another user refused'           => array( $flow, 'state-abc', 8, self::NOW, 'user_mismatch' ),
			'expired flow refused'           => array( $flow, 'state-abc', 7, self::NOW - 60 + XPay_Connect::FLOW_TTL_SECONDS + 1, 'expired' ),
			'at the TTL boundary passes'     => array( $flow, 'state-abc', 7, self::NOW - 60 + XPay_Connect::FLOW_TTL_SECONDS, null ),
		);
	}

	/* ── Token response decisions ────────────────────────────────────── */

	/**
	 * @dataProvider token_response_cases
	 * @param mixed $body     Decoded token response.
	 * @param bool  $live     Mode the flow requested.
	 * @param bool  $accepted Whether keys come back.
	 */
	public function test_token_response_decision( $body, bool $live, bool $accepted ): void {
		$keys = XPay_Connect::keys_from_token_response( $body, $live );
		if ( ! $accepted ) {
			$this->assertNull( $keys );
			return;
		}
		$this->assertSame( $body['xpay_restricted_key'], $keys['restricted'] );
		$this->assertSame( $body['xpay_publishable_key'], $keys['publishable'] );
	}

	public function token_response_cases(): array {
		$test_body = array(
			'access_token'         => 'xpo_ignored',
			'xpay_mode'            => 'test',
			'xpay_restricted_key'  => 'rk_test_abc',
			'xpay_publishable_key' => 'pk_test_abc',
		);

		return array(
			'good test response accepted'         => array( $test_body, false, true ),
			'good live response accepted'         => array(
				array(
					'xpay_mode'            => 'live',
					'xpay_restricted_key'  => 'rk_live_abc',
					'xpay_publishable_key' => 'pk_live_abc',
				),
				true,
				true,
			),
			'not an array refused'                => array( 'nope', false, false ),
			'mode missing refused'                => array(
				array(
					'xpay_restricted_key'  => 'rk_test_abc',
					'xpay_publishable_key' => 'pk_test_abc',
				),
				false,
				false,
			),
			'live answer to a test flow refused'  => array(
				array(
					'xpay_mode'            => 'live',
					'xpay_restricted_key'  => 'rk_live_abc',
					'xpay_publishable_key' => 'pk_live_abc',
				),
				false,
				false,
			),
			'test answer to a live flow refused'  => array( $test_body, true, false ),
			'wrong-plane restricted key refused'  => array(
				array_merge( $test_body, array( 'xpay_restricted_key' => 'rk_live_abc' ) ),
				false,
				false,
			),
			'wrong-plane publishable key refused' => array(
				array_merge( $test_body, array( 'xpay_publishable_key' => 'pk_live_abc' ) ),
				false,
				false,
			),
			'secret key in the rk slot refused'   => array(
				array_merge( $test_body, array( 'xpay_restricted_key' => 'sk_test_abc' ) ),
				false,
				false,
			),
			'non-string key refused'              => array(
				array_merge( $test_body, array( 'xpay_restricted_key' => array( 'rk_test_abc' ) ) ),
				false,
				false,
			),
		);
	}
}
