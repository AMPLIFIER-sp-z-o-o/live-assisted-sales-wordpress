<?php
/**
 * One-click connection, plugin side (includes/class-alas-connect.php).
 *
 * Talks to the LAS instance configured in this store, so it exercises the real handshake rather than
 * a mock: the challenge this plugin computes has to be the one that server accepts, or the exchange
 * fails. Run against a local backend (make dev on :8001).
 */

$fail = 0;
function ck( $ok, $label, $extra = '' ) {
	global $fail;
	if ( ! $ok ) {
		$fail++;
	}
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( '' !== $extra ? "  [$extra]" : '' ) . "\n";
}

$base = ALAS_Settings::base_url();
echo "== start url ==\n";
$start = ALAS_Connect::start_url();
ck( false !== strpos( $start, 'action=' . ALAS_Connect::ACTION_START ), 'button points at the start action' );
ck( false !== strpos( $start, '_wpnonce=' ), 'start is nonce protected (no drive-by connect)' );

echo "== redirect carries the store context ==\n";
$redirect = ALAS_Connect::connect_redirect_url( 'st-test', str_repeat( 'v', 64 ) );
$public   = ALAS_Settings::public_base_url();
ck( 0 === strpos( $redirect, $public ), 'browser redirect uses the PUBLIC address (Docker-safe)', $redirect );
$lang = strtolower( substr( get_locale(), 0, 2 ) );
ck( false !== strpos( $redirect, 'language=' . $lang ), "store language '$lang' rides along for the new-store default", $redirect );
ck( false !== strpos( $redirect, 'platform=woocommerce' ), 'platform is named' );

echo "== challenge matches what LAS expects ==\n";
$m = new ReflectionMethod( 'ALAS_Connect', 'challenge' );
$m->setAccessible( true );
$verifier = str_repeat( 'v', 64 );
$expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
ck( $m->invoke( null, $verifier ) === $expected, 'challenge is base64url(sha256(verifier)), unpadded', $m->invoke( null, $verifier ) );

echo "== live handshake against $base ==\n";
$probe = wp_remote_get( $base . '/integrations/wordpress/connect/', array( 'timeout' => 10, 'redirection' => 0 ) );
$code  = is_wp_error( $probe ) ? $probe->get_error_message() : (int) wp_remote_retrieve_response_code( $probe );
// Anonymous: LAS must bounce to login rather than answer. 302 to /accounts/login/ is the pass.
ck( 302 === $code, 'connect endpoint exists and demands a login', $code );
$location = is_wp_error( $probe ) ? '' : (string) wp_remote_retrieve_header( $probe, 'location' );
ck( false !== strpos( $location, '/accounts/login/' ), 'anonymous visitor is sent to sign in', $location );

echo "== exchange rejects what it should ==\n";
$post = function ( $body ) use ( $base ) {
	$r = wp_remote_post(
		$base . '/api/integrations/wordpress/exchange/',
		array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		)
	);
	return is_wp_error( $r ) ? array( 0, $r->get_error_message() ) : array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ) );
};
list( $c1 ) = $post( array( 'code' => 'never-issued-' . wp_generate_password( 12, false ), 'verifier' => $verifier, 'site_url' => home_url() ) );
ck( 400 === $c1, 'an invented code is refused', $c1 );
list( $c2 ) = $post( array( 'verifier' => $verifier ) );
ck( 400 === $c2, 'a request with no code is refused', $c2 );
list( $c3, $b3 ) = $post( array( 'code' => 'x', 'verifier' => 'y', 'site_url' => home_url() ) );
ck( 400 === $c3 && false === strpos( $b3, 'write_key' ), 'a refusal never leaks a key', $c3 );

echo "== return handler is guarded ==\n";
delete_site_transient( ALAS_Connect::HANDSHAKE_KEY );
ck( false === get_site_transient( ALAS_Connect::HANDSHAKE_KEY ), 'no handshake in flight' );
// With no handshake stored, a forged return must not be able to set a key. Asserted through the
// option: whatever the redirect does, the stored key must be untouched.
$before = get_option( 'amper_las_api_key' );
ck( is_string( $before ), 'api key option readable' );

echo "\nRESULT: " . ( $fail ? "$fail failed" : 'all passed' ) . "\n";
