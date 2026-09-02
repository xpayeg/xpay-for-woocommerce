<?php
/**
 * XPay_Webhook_Configurator
 *
 * Creates and retires this store's webhook endpoints at XPay, so the
 * webhook sets itself up when keys arrive: right URL, all subscribed
 * events, signing secret stored automatically. The copy-the-secret step
 * merchants get wrong is gone entirely — no manual secret field exists;
 * the Reconfigure webhook button is the recovery for an endpoint
 * deleted at XPay's side.
 *
 * The lifecycle is Stripe's (WC_Stripe_Account's webhook management),
 * adopted deliberately, per plane and independently:
 *
 *   - CREATE at key save. The response's secret is stored in the settings
 *     the receiver already reads, and webhook_data records the endpoint id,
 *     its URL, and THE KEY THAT CREATED IT — the creating key verbatim,
 *     not a fingerprint, because decommissioning after a key change must
 *     authenticate as the account that owns the old endpoint. It lives in
 *     the same settings option as the keys themselves, so it adds no new
 *     exposure.
 *   - DEDUPE after create: every other endpoint aimed at this store's URL
 *     in this plane is deleted (a reinstall must not leave the platform
 *     delivering to one store twice).
 *   - DECOMMISSION when a plane's key changes or is removed, using the
 *     OLD key. Failure logs and never blocks a save: an orphaned endpoint
 *     at XPay is recoverable, a save that cannot complete is not.
 *   - RECONFIGURE silently when a plugin update changes the subscribed
 *     event list, per plane, only when the platform's copy differs.
 *
 * Test and live never touch each other: each plane's endpoint is created
 * by, recorded with, and deleted through that plane's own key.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class XPay_Webhook_Configurator {

	/** The permission the merchant's key needs for any of this to work. */
	const REQUIRED_PERMISSION = 'WEBHOOK_ENDPOINTS_WRITE';

	/**
	 * Create this store's endpoint for one plane and store its secret.
	 *
	 * Order of operations is Stripe's: create FIRST, then delete the
	 * leftovers (excluding the new id), then persist, then reset the
	 * health record so it describes the new endpoint from second one.
	 *
	 * @param string $api_key The plane's secret/restricted key.
	 * @return array The endpoint object the API returned.
	 * @throws XPay_Api_Exception When the endpoint cannot be created.
	 */
	public static function configure( string $api_key ): array {
		$client = new XPay_Api_Client( $api_key );
		$live   = $client->is_live_mode();
		$url    = self::webhook_url();

		$endpoint = $client->create_webhook_endpoint(
			array(
				'url'           => $url,
				'enabledEvents' => XPay_Event_Names::SUBSCRIBED,
				'description'   => 'WooCommerce (' . home_url() . ')',
			),
			// Time-keyed on purpose: every save that reaches here MEANS a
			// fresh endpoint (the old one is deleted below), while a
			// transport retry of THIS save replays the same key and body.
			'wcwh_' . time()
		);

		if ( ! isset( $endpoint['id'], $endpoint['secret'] ) || ! is_string( $endpoint['id'] ) || ! is_string( $endpoint['secret'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constant message; render sites escape on output.
			throw XPay_Api_Exception::from_api_response( array( 'message' => 'Webhook endpoint response missing id or secret' ), 502 );
		}

		self::delete_other_endpoints_for_this_store( $client, (string) $endpoint['id'] );

		self::merge_settings(
			array(
				self::secret_setting( $live ) => (string) $endpoint['secret'],
				self::data_setting( $live )   => array(
					'id'     => (string) $endpoint['id'],
					'url'    => isset( $endpoint['url'] ) ? (string) $endpoint['url'] : $url,
					'secret' => $api_key,
				),
			)
		);

		XPay_Webhook_State::clear_state( $live );

		XPay_Logger::event(
			'webhook.configured',
			array(
				'endpoint_id' => $endpoint['id'],
				'live_mode'   => $live,
			)
		);

		return $endpoint;
	}

	/**
	 * Delete every other endpoint in this plane aimed at this store's URL.
	 * A reinstall, or a save that raced, must not leave XPay delivering
	 * the same events here twice — the receiver's dedupe would absorb the
	 * duplicates, but the retired endpoint's failures would poison the
	 * platform-side delivery stats forever.
	 *
	 * @param XPay_Api_Client $client     Client for the plane.
	 * @param string          $exclude_id The endpoint that must survive.
	 */
	private static function delete_other_endpoints_for_this_store( XPay_Api_Client $client, string $exclude_id ): void {
		try {
			$listing = $client->list_webhook_endpoints();
		} catch ( XPay_Api_Exception $e ) {
			XPay_Logger::event( 'webhook.dedupe_list_failed', array( 'code' => $e->get_error_code() ) );
			return;
		}

		$endpoints = isset( $listing['data'] ) && is_array( $listing['data'] ) ? $listing['data'] : array();
		foreach ( $endpoints as $endpoint ) {
			if ( ! is_array( $endpoint ) || ! isset( $endpoint['id'], $endpoint['url'] ) ) {
				continue;
			}
			if ( $endpoint['id'] === $exclude_id || ! self::is_this_stores_url( (string) $endpoint['url'] ) ) {
				continue;
			}
			try {
				$client->delete_webhook_endpoint( (string) $endpoint['id'] );
				XPay_Logger::event( 'webhook.duplicate_deleted', array( 'endpoint_id' => $endpoint['id'] ) );
			} catch ( XPay_Api_Exception $e ) {
				XPay_Logger::event(
					'webhook.duplicate_delete_failed',
					array(
						'endpoint_id' => $endpoint['id'],
						'code'        => $e->get_error_code(),
					)
				);
			}
		}
	}

	/**
	 * Retire a previously configured endpoint when the key that created it
	 * is being removed or replaced, authenticating with THAT key — the new
	 * key may belong to a different account entirely.
	 *
	 * Failure logs and answers false, never throws: a save must complete
	 * even when the old key is already revoked at XPay.
	 *
	 * @param mixed  $webhook_data The stored webhook_data for the plane.
	 * @param string $new_api_key  The key being saved ('' on removal).
	 * @return bool True when the endpoint was deleted.
	 */
	public static function maybe_decommission( $webhook_data, string $new_api_key ): bool {
		if ( ! is_array( $webhook_data ) || empty( $webhook_data['id'] ) || empty( $webhook_data['secret'] ) ) {
			return false;
		}
		if ( '' !== $new_api_key && $new_api_key === $webhook_data['secret'] ) {
			return false;
		}

		try {
			$client = new XPay_Api_Client( (string) $webhook_data['secret'] );
			$client->delete_webhook_endpoint( (string) $webhook_data['id'] );
		} catch ( XPay_Api_Exception $e ) {
			XPay_Logger::event(
				'webhook.decommission_failed',
				array(
					'endpoint_id' => (string) $webhook_data['id'],
					'code'        => $e->get_error_code(),
				)
			);
			return false;
		}

		XPay_Logger::event( 'webhook.decommissioned', array( 'endpoint_id' => (string) $webhook_data['id'] ) );
		return true;
	}

	/**
	 * Run the decommission pass for BOTH planes after a settings save,
	 * comparing each plane's newly saved key against the key that created
	 * its recorded endpoint. The plane whose key did not change is never
	 * touched. When an endpoint is retired, its secret, its record and its
	 * health state go with it.
	 *
	 * @param array $settings The settings as just saved.
	 * @return array The settings with retired planes' webhook fields cleared.
	 */
	public static function decommission_after_key_update( array $settings ): array {
		foreach ( array( false, true ) as $live ) {
			$mode_key = (string) ( $settings[ ( $live ? 'live' : 'test' ) . '_api_key' ] ?? '' );
			$data     = $settings[ self::data_setting( $live ) ] ?? null;

			if ( self::maybe_decommission( $data, $mode_key ) ) {
				$settings[ self::secret_setting( $live ) ] = '';
				$settings[ self::data_setting( $live ) ]   = array();
				XPay_Webhook_State::clear_state( $live );
			}
		}
		return $settings;
	}

	/**
	 * After a plugin update: bring each configured plane's event list in
	 * step with this version's, silently, and only when the platform's
	 * copy actually differs. Stripe reconfigures on update the same way.
	 */
	public static function maybe_reconfigure_on_update(): void {
		$settings = self::settings();

		foreach ( array( false, true ) as $live ) {
			$api_key = (string) ( $settings[ ( $live ? 'live' : 'test' ) . '_api_key' ] ?? '' );
			$data    = $settings[ self::data_setting( $live ) ] ?? null;
			if ( '' === $api_key || ! is_array( $data ) || empty( $data['id'] ) ) {
				continue; // Never configured, or no key to do it with.
			}

			try {
				$client   = new XPay_Api_Client( $api_key );
				$listing  = $client->list_webhook_endpoints();
				$existing = null;
				foreach ( ( isset( $listing['data'] ) && is_array( $listing['data'] ) ? $listing['data'] : array() ) as $endpoint ) {
					if ( is_array( $endpoint ) && isset( $endpoint['id'] ) && $endpoint['id'] === $data['id'] ) {
						$existing = $endpoint;
						break;
					}
				}
				if ( null === $existing ) {
					continue; // Deleted at XPay; the health row will say so.
				}

				$desired = XPay_Event_Names::SUBSCRIBED;
				$current = isset( $existing['enabledEvents'] ) && is_array( $existing['enabledEvents'] ) ? $existing['enabledEvents'] : array();
				sort( $desired );
				sort( $current );
				if ( $desired === $current ) {
					continue;
				}

				self::configure( $api_key );
				XPay_Logger::event( 'webhook.reconfigured_on_update', array( 'live_mode' => $live ) );
			} catch ( XPay_Api_Exception $e ) {
				XPay_Logger::event(
					'webhook.reconfigure_failed',
					array(
						'live_mode' => $live,
						'code'      => $e->get_error_code(),
					)
				);
			}
		}
	}

	/** This store's receiver URL, the one every endpoint points at. */
	public static function webhook_url(): string {
		return home_url( '/?wc-api=' . XPay_Constants::WEBHOOK_ENDPOINT );
	}

	/**
	 * Does an endpoint URL point at THIS store's receiver? Scheme-blind
	 * (an http->https migration must still recognize its own endpoint),
	 * everything else exact.
	 *
	 * @param string $url Endpoint URL as the API returned it.
	 */
	public static function is_this_stores_url( string $url ): bool {
		$strip = static function ( string $value ): string {
			return strtolower( untrailingslashit( (string) preg_replace( '#^https?://#i', '', trim( $value ) ) ) );
		};
		return $strip( $url ) === $strip( self::webhook_url() );
	}

	/** @param bool $live Which plane. */
	public static function secret_setting( bool $live ): string {
		return ( $live ? 'live' : 'test' ) . '_webhook_secret';
	}

	/** @param bool $live Which plane. */
	public static function data_setting( bool $live ): string {
		return ( $live ? 'live' : 'test' ) . '_webhook_data';
	}

	/** The stored webhook_data for one plane, or null. */
	public static function endpoint_data( bool $live ): ?array {
		$settings = self::settings();
		$data     = $settings[ self::data_setting( $live ) ] ?? null;
		return is_array( $data ) && ! empty( $data['id'] ) ? $data : null;
	}

	/** The gateway settings option, as stored. */
	private static function settings(): array {
		$settings = get_option( 'woocommerce_' . XPay_Constants::GATEWAY_ID . '_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Merge values into the gateway settings option. The option is the one
	 * WooCommerce's own save writes; undeclared keys survive its saves
	 * because init_settings loads the whole stored array first.
	 *
	 * @param array $values Keys to write.
	 */
	public static function merge_settings( array $values ): void {
		update_option( 'woocommerce_' . XPay_Constants::GATEWAY_ID . '_settings', array_merge( self::settings(), $values ) );
	}
}
