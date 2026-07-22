<?php
/**
 * HTTP client for the LAS ingest API and the HMAC signer for the chat widget's
 * customer identity (window.LAS_CUSTOMER).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALAS_Client {

	// Off the shopper's critical path most of the time, so a generous timeout beats dropped events.
	const EVENT_TIMEOUT           = 5;
	const CONNECTION_TEST_TIMEOUT = 8;

	// How long a signed widget identity stays valid. Pages refresh the signature on every
	// navigation, so this only needs to outlive a long-idle open tab.
	const CUSTOMER_SIGNATURE_TTL = 7200;

	private static function request( $path, $method = 'GET', $payload = null, $args = array() ) {
		$base = ALAS_Settings::base_url();
		if ( ! $base ) {
			return new WP_Error( 'amper_las_unconfigured', 'LAS base URL is not configured.' );
		}
		$defaults = array(
			'method'  => $method,
			'timeout' => self::EVENT_TIMEOUT,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . ALAS_Settings::api_key(),
			),
		);
		if ( null !== $payload ) {
			$defaults['headers']['Content-Type'] = 'application/json';
			$defaults['body']                    = wp_json_encode( $payload );
		}
		return wp_remote_request( $base . '/' . ltrim( $path, '/' ), array_merge( $defaults, $args ) );
	}

	public static function test_connection() {
		return self::request( '/api/ingest/store-events/test/', 'GET', null, array( 'timeout' => self::CONNECTION_TEST_TIMEOUT ) );
	}

	public static function disconnect() {
		return self::request( '/api/ingest/store-events/disconnect/', 'POST', array() );
	}

	/**
	 * Delivers one event to LAS.
	 *
	 * @param array $payload  The full event envelope.
	 * @param bool  $blocking Blocking (confirmed) delivery for the durable path; fire-and-forget
	 *                        for high-frequency telemetry.
	 * @return bool True on a CONFIRMED 2xx delivery; non-blocking sends optimistically return true
	 *              once the request was handed to the transport.
	 */
	public static function send_event( $payload, $blocking = false ) {
		$args = $blocking
			? array()
			: array( 'blocking' => false, 'timeout' => 2 );
		$response = self::request( '/api/ingest/store-events/', 'POST', $payload, $args );
		if ( is_wp_error( $response ) ) {
			return false;
		}
		if ( ! $blocking ) {
			return true;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * HMAC proof for window.LAS_CUSTOMER, verified by las-backend with the same store key.
	 * Canonical message (must match las-backend's identity_signature_message):
	 * "external_id|lowercased email|unix exp". The key itself never reaches the browser.
	 *
	 * @return array{exp:int, sig:string}
	 */
	public static function sign_customer_identity( $external_id, $email ) {
		$exp     = time() + self::CUSTOMER_SIGNATURE_TTL;
		$message = $external_id . '|' . strtolower( trim( (string) $email ) ) . '|' . $exp;
		$sig     = hash_hmac( 'sha256', $message, ALAS_Settings::api_key() );
		return array( 'exp' => $exp, 'sig' => $sig );
	}
}
