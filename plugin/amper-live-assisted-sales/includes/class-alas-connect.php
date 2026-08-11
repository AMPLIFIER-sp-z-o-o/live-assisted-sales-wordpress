<?php
/**
 * One-click connection to AMPER Live Assisted Sales.
 *
 * Replaces the worst part of setting this plugin up: sign up on another site, find the store, find
 * the key, copy it, come back, paste it. Here the merchant presses one button, confirms on
 * live-assisted-sales.com that this store may connect, and lands back on a working configuration.
 *
 * The handshake is shaped like OAuth's PKCE, for one reason that matters: the API key is a
 * long-lived credential that can write events into the merchant's account, and it must never travel
 * through a browser. So the browser only ever carries a single-use `code`; the plugin then trades
 * that code for the key over a direct server-to-server request, proving it started the exchange with
 * a `verifier` that never left this server.
 *
 *   1. START  plugin -> LAS   state + challenge = sha256(verifier), return_url, site_url
 *   2. LAS: merchant signs in, confirms, LAS creates the store
 *   3. BACK   LAS -> browser -> plugin   state + one-time code
 *   4. SWAP   plugin -> LAS (server to server)   code + verifier  ->  write_key
 *
 * `state` protects step 3 from being forged, and the verifier makes a stolen code worthless.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALAS_Connect {

	const ACTION_START     = 'amper_las_connect_start';
	const ACTION_RETURN    = 'amper_las_connect_return';
	const HANDSHAKE_KEY    = 'amper_las_connect_handshake';
	/** Long enough for a merchant to sign up on the way, short enough that a leaked code is stale. */
	const HANDSHAKE_TTL    = 15 * MINUTE_IN_SECONDS;
	const NOTICE_KEY       = 'amper_las_connect_notice';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION_START, array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_' . self::ACTION_RETURN, array( __CLASS__, 'handle_return' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/** URL of the button that starts the whole thing. */
	public static function start_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_START ),
			self::ACTION_START
		);
	}

	private static function return_url() {
		return admin_url( 'admin-post.php?action=' . self::ACTION_RETURN );
	}

	private static function settings_url() {
		return admin_url( 'admin.php?page=amper-las' );
	}

	// ---- Step 1: send the merchant to LAS ---------------------------------

	public static function handle_start() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this store.', 'amper-live-assisted-sales' ), 403 );
		}
		check_admin_referer( self::ACTION_START );

		$base = ALAS_Settings::base_url();
		if ( ! $base ) {
			self::fail( __( 'Set the LAS platform address first.', 'amper-live-assisted-sales' ) );
		}

		$preflight = self::preflight_error( $base );
		if ( null !== $preflight ) {
			self::fail( $preflight );
		}

		$verifier = wp_generate_password( 64, false, false );
		$state    = wp_generate_password( 32, false, false );
		set_site_transient(
			self::HANDSHAKE_KEY,
			array( 'state' => $state, 'verifier' => $verifier, 'user' => get_current_user_id() ),
			self::HANDSHAKE_TTL
		);

		wp_redirect( self::connect_redirect_url( $state, $verifier ) );
		exit;
	}

	/**
	 * Why the connect screen on the platform cannot be reached, or null when it can.
	 *
	 * A blind redirect to a dead or misconfigured platform would strand the merchant on a foreign
	 * error page with no way back to wp-admin. One cheap probe first keeps every failure on this
	 * screen, where the error notice and the manual key method both live. The probe uses the SERVER
	 * address on purpose: the public one may be unreachable from this server (Docker), and the
	 * code-for-key exchange will need the server address to work anyway.
	 */
	public static function preflight_error( $base ) {
		$probe = wp_remote_head(
			trailingslashit( $base ) . 'integrations/wordpress/connect/',
			array( 'timeout' => 10 )
		);
		if ( is_wp_error( $probe ) ) {
			return sprintf(
				/* translators: %s: network error message. */
				__( 'Could not reach AMPER LAS (%s). Nothing was changed - try again in a moment, or paste the API key manually below.', 'amper-live-assisted-sales' ),
				$probe->get_error_message()
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $probe );
		if ( $status >= 400 ) {
			return sprintf(
				/* translators: %d: HTTP status code returned by the LAS platform. */
				__( 'AMPER LAS did not accept the connection address (HTTP %d). Nothing was changed - check the LAS platform address, or paste the API key manually below.', 'amper-live-assisted-sales' ),
				$status
			);
		}
		return null;
	}

	/**
	 * The URL the merchant's BROWSER travels to, so it must use the public address; the server-side
	 * address may be unreachable from a browser (Docker, internal networks). The code-for-key
	 * exchange in handle_return stays on the server address.
	 */
	public static function connect_redirect_url( $state, $verifier ) {
		return add_query_arg(
			array(
				'state'      => $state,
				// Only the hash goes out. Whoever intercepts it cannot reverse it into the verifier.
				'challenge'  => self::challenge( $verifier ),
				'return_url' => rawurlencode( self::return_url() ),
				'site_url'   => rawurlencode( home_url() ),
				'site_name'  => rawurlencode( get_bloginfo( 'name' ) ),
				'platform'   => 'woocommerce',
				// The store's own language, so a Polish shop greets shoppers in Polish from the
				// first minute instead of inheriting the LAS account's UI language.
				'language'   => strtolower( substr( get_locale(), 0, 2 ) ),
			),
			trailingslashit( ALAS_Settings::public_base_url() ) . 'integrations/wordpress/connect/'
		);
	}

	private static function challenge( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	// ---- Step 3 and 4: take the code, trade it for the key ----------------

	public static function handle_return() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this store.', 'amper-live-assisted-sales' ), 403 );
		}

		$handshake = get_site_transient( self::HANDSHAKE_KEY );
		delete_site_transient( self::HANDSHAKE_KEY );
		if ( ! is_array( $handshake ) || empty( $handshake['state'] ) ) {
			self::fail( __( 'The connection attempt expired. Please try again.', 'amper-live-assisted-sales' ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// hash_equals, not ==: this comparison decides whether a redirect really came from the
		// handshake we started, and a timing-safe check costs nothing here.
		if ( ! $state || ! hash_equals( (string) $handshake['state'], $state ) ) {
			self::fail( __( 'The connection response did not match this store. Nothing was changed.', 'amper-live-assisted-sales' ) );
		}
		if ( ! $code ) {
			$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
			self::fail(
				'access_denied' === $error
					? __( 'Connection cancelled - nothing was changed.', 'amper-live-assisted-sales' )
					: __( 'AMPER LAS did not return a connection code. Nothing was changed.', 'amper-live-assisted-sales' )
			);
		}

		$response = wp_remote_post(
			trailingslashit( ALAS_Settings::base_url() ) . 'api/integrations/wordpress/exchange/',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'code'     => $code,
						'verifier' => $handshake['verifier'],
						'site_url' => home_url(),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			self::fail( sprintf(
				/* translators: %s: network error message. */
				__( 'Could not reach AMPER LAS: %s', 'amper-live-assisted-sales' ),
				$response->get_error_message()
			) );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code_http = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code_http || empty( $body['write_key'] ) ) {
			$detail = isset( $body['detail'] ) ? (string) $body['detail'] : sprintf( 'HTTP %d', $code_http );
			self::fail( sprintf(
				/* translators: %s: error returned by the LAS platform. */
				__( 'AMPER LAS refused the connection: %s', 'amper-live-assisted-sales' ),
				$detail
			) );
		}

		update_option( ALAS_Settings::OPTION_API_KEY, (string) $body['write_key'] );
		update_option( ALAS_Settings::OPTION_ENABLED, 'yes' );

		// Reuse the existing test: it is what fetches the public widget key, and without that key the
		// widget stays hidden. Connecting without it would look successful and change nothing.
		$test = ALAS_Settings::run_connection_test();
		if ( empty( $test['ok'] ) ) {
			self::fail( sprintf(
				/* translators: %s: reason the connection test failed. */
				__( 'The key was saved but the connection test failed: %s', 'amper-live-assisted-sales' ),
				isset( $test['message'] ) ? (string) $test['message'] : ''
			) );
		}

		$store = ! empty( $body['store_name'] ) ? (string) $body['store_name'] : '';
		self::succeed(
			$store
				? sprintf(
					/* translators: %s: store name in the LAS console. */
					__( 'Connected to AMPER LAS as "%s". The chat widget is live on your storefront.', 'amper-live-assisted-sales' ),
					$store
				)
				: __( 'Connected to AMPER LAS. The chat widget is live on your storefront.', 'amper-live-assisted-sales' )
		);
	}

	// ---- Outcome ----------------------------------------------------------

	private static function fail( $message ) {
		set_site_transient( self::NOTICE_KEY, array( 'type' => 'error', 'message' => $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::settings_url() );
		exit;
	}

	private static function succeed( $message ) {
		set_site_transient( self::NOTICE_KEY, array( 'type' => 'success', 'message' => $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::settings_url() );
		exit;
	}

	public static function render_notice() {
		$notice = get_site_transient( self::NOTICE_KEY );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_site_transient( self::NOTICE_KEY );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'success' === $notice['type'] ? 'success' : 'error',
			esc_html( $notice['message'] )
		);
	}
}
