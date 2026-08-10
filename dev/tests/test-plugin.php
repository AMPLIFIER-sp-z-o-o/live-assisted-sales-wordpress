<?php
/**
 * Backend test suite for the AMPER LAS plugin (run via `wp eval-file`).
 * Covers payload builders, consent/PII gating, HMAC identity, envelope shape,
 * outbox durability state machine and purchase de-duplication.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['alas_pass'] = 0;
$GLOBALS['alas_fail'] = 0;

function t_ok( $cond, $label, $detail = '' ) {
	if ( $cond ) {
		$GLOBALS['alas_pass']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['alas_fail']++;
		echo "FAIL  $label" . ( $detail !== '' ? "  [$detail]" : '' ) . "\n";
	}
}

function t_eq( $a, $b, $label ) {
	t_ok( $a === $b, $label, var_export( $a, true ) . ' !== ' . var_export( $b, true ) );
}

echo "== A. Money formatting ==\n";
t_eq( ALAS_Payloads::amount( 45 ), '45.00', 'amount(45) -> 45.00' );
t_eq( ALAS_Payloads::amount( '1234.5' ), '1234.50', 'amount(1234.5) -> 1234.50' );
t_eq( ALAS_Payloads::amount( 0 ), '0.00', 'amount(0) -> 0.00' );
$disp = ALAS_Payloads::amount_display( 1234.56 );
t_ok( strpos( $disp, '1 234,56' ) !== false && strpos( $disp, 'zł' ) !== false, 'amount_display uses store format', $disp );
t_ok( strpos( $disp, "\xc2\xa0" ) === false, 'amount_display has no NBSP', bin2hex( $disp ) );

echo "== B. Product payloads ==\n";
$simple = wc_get_product( 14 ); // Hoodie with Logo
$pp = ALAS_Payloads::product_payload( $simple );
$expected_keys = array( 'id', 'name', 'sku', 'url', 'image', 'price', 'price_display', 'currency' );
t_eq( array_keys( $pp ), $expected_keys, 'product payload keys match b2c' );
t_eq( $pp['id'], '14', 'product id string' );
t_eq( $pp['sku'], 'woo-hoodie-with-logo', 'product sku' );
t_eq( $pp['price'], '45.00', 'product price' );
t_eq( $pp['currency'], 'PLN', 'product currency' );
t_ok( strpos( $pp['url'], '/product/hoodie-with-logo/' ) !== false, 'product url', $pp['url'] );
t_ok( $pp['image'] !== '' && strpos( $pp['image'], 'http' ) === 0, 'product image absolute', $pp['image'] );
t_ok( strpos( $pp['price_display'], '45,00' ) !== false, 'price_display formatted', $pp['price_display'] );

$variable = null;
foreach ( wc_get_products( array( 'type' => 'variable', 'limit' => 1 ) ) as $p ) { $variable = $p; }
if ( $variable ) {
	$vp = ALAS_Payloads::product_payload( $variable );
	t_ok( $vp['price'] !== '' , 'variable product has a price', $variable->get_name() );
	$children = $variable->get_children();
	if ( $children ) {
		$var_payload = ALAS_Payloads::product_payload( wc_get_product( $children[0] ) );
		t_eq( $var_payload['id'], (string) $children[0], 'variation payload uses variation id' );
	}
} else {
	t_ok( false, 'variable product exists in catalog' );
}

$bare_id = wp_insert_post( array( 'post_title' => 'ALAS bare product', 'post_type' => 'product', 'post_status' => 'publish' ) );
wp_set_object_terms( $bare_id, 'simple', 'product_type' );
$bare = wc_get_product( $bare_id );
$bp = ALAS_Payloads::product_payload( $bare );
t_eq( $bp['image'], '', 'product without image -> empty string' );
t_eq( $bp['sku'], '', 'product without sku -> empty string' );
t_eq( $bp['price'], '', 'product without price -> empty string' );
t_eq( ALAS_Payloads::product_payload( null ), array(), 'null product -> empty payload' );
t_eq( ALAS_Payloads::product_payload( 999999 ), array(), 'missing product id -> empty payload' );

echo "== C. Cart payloads ==\n";
if ( null === WC()->cart ) { wc_load_cart(); }
WC()->cart->empty_cart();
$empty = ALAS_Payloads::cart_payload();
t_eq( $empty['items_count'], 0, 'empty cart items_count 0' );
t_eq( $empty['total'], '0.00', 'empty cart total 0.00' );
t_eq( $empty['items'], array(), 'empty cart items []' );
t_eq( $empty['currency'], 'PLN', 'empty cart currency' );

WC()->cart->add_to_cart( 14, 2 );
$beanie = null;
foreach ( wc_get_products( array( 'sku' => 'woo-beanie', 'limit' => 1 ) ) as $p ) { $beanie = $p; }
if ( $beanie ) { WC()->cart->add_to_cart( $beanie->get_id(), 1 ); }
WC()->cart->calculate_totals();
$cp = ALAS_Payloads::cart_payload();
t_eq( $cp['items_count'], $beanie ? 3 : 2, 'cart items_count sums quantities' );
$expected_total = $beanie ? '108.00' : '90.00'; // 2*45 + 18 (beanie sale price)
t_eq( $cp['total'], $expected_total, 'cart total = items only (no shipping)' );
$invariant_ok = true;
foreach ( $cp['items'] as $item ) {
	$keys_ok = array_keys( $item ) === array( 'product_id', 'name', 'sku', 'url', 'quantity', 'price', 'line_total', 'currency' );
	if ( ! $keys_ok ) { $invariant_ok = false; break; }
	if ( abs( (float) $item['price'] * $item['quantity'] - (float) $item['line_total'] ) > 0.005 ) { $invariant_ok = false; break; }
}
t_ok( $invariant_ok, 'cart item keys + invariant line_total == price*qty' );
WC()->cart->empty_cart();

echo "== D. Order payloads (order #60 from E2E) ==\n";
$order = wc_get_order( 60 );
if ( $order ) {
	$ocp = ALAS_Payloads::order_cart_payload( $order );
	t_eq( $ocp['items_count'], 1, 'order items_count' );
	t_eq( $ocp['total'], '60.00', 'order total includes shipping' );
	t_eq( $ocp['items'][0]['line_total'], '45.00', 'order line_total' );
	t_eq( $ocp['items'][0]['price'], '45.00', 'order unit price preserves invariant' );
	$om = ALAS_Payloads::order_metadata( $order );
	$expected_order_keys = array( 'order_id', 'tracking_token', 'status', 'subtotal', 'discount_total', 'delivery_cost', 'total', 'currency', 'coupon_code' );
	t_eq( array_keys( $om['order'] ), $expected_order_keys, 'metadata.order keys match b2c' );
	t_eq( $om['order']['delivery_cost'], '15.00', 'metadata.order delivery_cost' );
	t_eq( $om['order']['subtotal'], '45.00', 'metadata.order subtotal' );
} else {
	t_ok( false, 'order #60 exists' );
}

echo "== E. Consent + PII gate ==\n";
unset( $_COOKIE['las_consent'] );
unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
t_eq( ALAS_Consent::consent_value(), null, 'no cookie -> consent null' );
$_COOKIE['las_consent'] = 'true';
t_eq( ALAS_Consent::consent_value(), true, 'cookie true' );
$_COOKIE['las_consent'] = 'false';
t_eq( ALAS_Consent::consent_value(), false, 'cookie false' );
t_eq( ALAS_Consent::pii_forwarding_allowed(), false, 'consent false -> PII blocked everywhere' );

$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
t_eq( ALAS_Consent::region(), 'eu', 'CF header DE -> eu' );
unset( $_COOKIE['las_consent'] );
t_eq( ALAS_Consent::pii_forwarding_allowed(), false, 'EU + no consent -> PII blocked (opt-in)' );
$_COOKIE['las_consent'] = 'true';
t_eq( ALAS_Consent::pii_forwarding_allowed(), true, 'EU + consent true -> PII allowed' );

$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
t_eq( ALAS_Consent::region(), 'noneu', 'CF header US -> noneu' );
unset( $_COOKIE['las_consent'] );
t_eq( ALAS_Consent::pii_forwarding_allowed(), true, 'US + no consent -> PII allowed (opt-out)' );
$_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
t_eq( ALAS_Consent::region(), '', 'XX header ignored -> unknown region' );
unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );

echo "== F. User metadata ==\n";
wp_set_current_user( 0 );
t_eq( ALAS_Payloads::user_metadata( true ), array( 'status' => 'anonymous', 'authenticated' => false ), 'anonymous user metadata' );
$customer = get_user_by( 'email', 'klient@example.com' );
wp_set_current_user( $customer->ID );
$um = ALAS_Payloads::user_metadata( true );
t_eq( $um['email'], 'klient@example.com', 'authenticated metadata email' );
t_eq( $um['id'], (string) $customer->ID, 'authenticated metadata id' );
$um_nopii = ALAS_Payloads::user_metadata( false );
t_ok( ! isset( $um_nopii['email'] ) && ! isset( $um_nopii['display'] ), 'include_pii=false drops email+display' );
t_eq( $um_nopii['id'], (string) $customer->ID, 'include_pii=false keeps pseudonymous id' );
wp_set_current_user( 0 );

echo "== G. HMAC identity signature ==\n";
$signed = ALAS_Client::sign_customer_identity( '3', 'Klient@Example.com ' );
t_ok( $signed['exp'] > time() + 7000 && $signed['exp'] <= time() + 7200, 'signature TTL ~2h' );
$expected = hash_hmac( 'sha256', '3|klient@example.com|' . $signed['exp'], ALAS_Settings::api_key() );
t_eq( $signed['sig'], $expected, 'canonical message: id|lower(trim(email))|exp' );

echo "== H. Envelope ==\n";
$_COOKIE['las_visitor_id'] = 'test-visitor-123';
$_COOKIE['las_session_id'] = 'test-session-456';
$_COOKIE['las_consent'] = 'true';
$env = ALAS_Payloads::build_envelope( 'view_item', array( 'product' => $pp, 'page' => array( 'title' => 'X' ) ) );
$expected_env_keys = array( 'event_id', 'event_type', 'visitor_id', 'session_id', 'occurred_at', 'url', 'page', 'product', 'category', 'search', 'cart', 'cursor', 'metadata' );
t_eq( array_keys( $env ), $expected_env_keys, 'envelope keys match b2c contract' );
t_eq( $env['visitor_id'], 'test-visitor-123', 'visitor_id from cookie' );
t_eq( $env['session_id'], 'test-session-456', 'session_id from cookie' );
t_eq( $env['metadata']['consent'], true, 'consent forwarded in metadata' );
t_ok( isset( $env['metadata']['user'] ), 'metadata.user present' );
t_ok( is_array( $env['cart'] ) && isset( $env['cart']['items_count'] ), 'cart auto-attached for view_item' );
t_ok( (bool) preg_match( '/^\d{4}-\d{2}-\d{2}T/', $env['occurred_at'] ), 'occurred_at ISO8601' );
t_ok( wp_is_uuid( $env['event_id'] ), 'event_id is uuid' );

$ping = ALAS_Payloads::build_envelope( 'page_ping', array() );
t_eq( $ping['cart'], array(), 'page_ping carries no cart snapshot' );
$sel = ALAS_Payloads::build_envelope( 'select_item', array() );
t_eq( $sel['cart'], array(), 'select_item carries no cart snapshot' );

$start = ALAS_Payloads::build_envelope( 'session_start', array() );
$logo = ALAS_Settings::widget_logo_url();
t_eq( isset( $start['metadata']['widget']['logo_url'] ), $logo !== '', 'session_start logo only when configured' );

unset( $_COOKIE['las_visitor_id'], $_COOKIE['las_session_id'] );
$env2 = ALAS_Payloads::build_envelope( 'view_item', array() );
t_ok( strpos( $env2['visitor_id'], 'visitor-' ) === 0, 'visitor fallback uuid prefixed' );

echo "== I. Dispatch guards ==\n";
t_eq( ALAS_Dispatch::dispatch( 'nonsense_event', array() ), false, 'unsupported event type rejected' );
$saved_key    = ALAS_Settings::api_key();
$saved_public = ALAS_Settings::site_public_key();
update_option( 'amper_las_api_key', '' );
t_eq( ALAS_Dispatch::dispatch( 'view_item', array() ), false, 'unconfigured -> dispatch refused' );
update_option( 'amper_las_api_key', $saved_key );

echo "== I2. Stale connection state ==\n";
// The fetched public key belongs to one API key; changing or clearing the key must forget it,
// or the settings page keeps claiming "Connected" and hides the Connect button.
update_option( 'amper_las_site_public_key', 'site_pk_stale_test' );
update_option( 'amper_las_api_key', 'different-key-for-stale-test' );
t_eq( ALAS_Settings::site_public_key(), '', 'changing the API key forgets the public key' );
update_option( 'amper_las_site_public_key', 'site_pk_stale_test' );
update_option( 'amper_las_api_key', '' );
t_eq( ALAS_Settings::site_public_key(), '', 'clearing the API key forgets the public key' );
update_option( 'amper_las_site_public_key', 'site_pk_stale_test' );
update_option( 'amper_las_api_key', 'same-key-twice' );
update_option( 'amper_las_site_public_key', 'site_pk_stale_test' );
update_option( 'amper_las_api_key', 'same-key-twice' );
t_eq( ALAS_Settings::site_public_key(), 'site_pk_stale_test', 're-saving the same key keeps the public key' );
update_option( 'amper_las_api_key', $saved_key );

echo "== J. Outbox state machine ==\n";
global $wpdb;
$table = ALAS_Outbox::table_name();
$row_id = ALAS_Outbox::enqueue( array( 'event_type' => 'add_to_cart', 'event_id' => wp_generate_uuid4(), 'test' => 'outbox-sm' ) );
t_ok( $row_id > 0, 'enqueue returns row id' );
t_eq( $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id=%d", $row_id ) ), 'pending', 'row starts pending' );

// Unconfigured target: row must stay pending WITHOUT burning attempts.
update_option( 'amper_las_api_key', '' );
t_eq( ALAS_Outbox::deliver_row( $row_id ), false, 'unconfigured deliver_row -> false' );
t_eq( (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id=%d", $row_id ) ), 0, 'unconfigured keeps attempts at 0' );
update_option( 'amper_las_api_key', $saved_key );
// The key round-trips above wiped the fetched public key (by design) - put the real one back.
update_option( 'amper_las_site_public_key', $saved_public );

// Dead target burns attempts, hits FAILED at 8.
$saved_url = get_option( 'amper_las_base_url' );
update_option( 'amper_las_base_url', 'http://host.docker.internal:59999' );
for ( $i = 0; $i < 8; $i++ ) { ALAS_Outbox::deliver_row( $row_id ); }
$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, attempts FROM {$table} WHERE id=%d", $row_id ) );
t_eq( $row->status, 'failed', 'row failed after max attempts' );
t_eq( (int) $row->attempts, 8, 'attempts capped at 8' );
t_eq( ALAS_Outbox::deliver_row( $row_id ), false, 'failed row is not retried' );
update_option( 'amper_las_base_url', $saved_url );

// Healthy target: pending -> sent, confirmed.
$row_id2 = ALAS_Outbox::enqueue( ALAS_Payloads::build_envelope( 'add_to_cart', array( 'product' => $pp ) ) );
t_eq( ALAS_Outbox::deliver_row( $row_id2 ), true, 'healthy deliver_row confirms' );
t_eq( $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id=%d", $row_id2 ) ), 'sent', 'row marked sent' );
t_eq( ALAS_Outbox::deliver_row( $row_id2 ), false, 'sent row is not re-sent' );

echo "== K. Purchase de-duplication ==\n";
$test_order = wc_create_order();
$test_order->add_product( wc_get_product( 14 ), 1 );
$test_order->set_payment_method_title( 'Test payment' );
$test_order->calculate_totals();
$test_order->save();
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE event_type='purchase'" );
ALAS_Tracking::on_store_api_order_processed( $test_order );
ALAS_Tracking::on_store_api_order_processed( wc_get_order( $test_order->get_id() ) ); // second fire must no-op
$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE event_type='purchase'" );
t_eq( $after - $before, 1, 'double order-processed emits exactly one purchase' );
t_ok( (bool) wc_get_order( $test_order->get_id() )->get_meta( '_amper_las_purchase_sent' ), 'purchase-sent meta recorded' );
t_ok( (bool) wc_get_order( $test_order->get_id() )->get_meta( '_amper_las_visitor_id' ), 'visitor id pinned in order meta' );
$test_order->delete( true );
wp_delete_post( $bare_id, true );

echo "== L. Chat-only kill switch ==\n";
$saved_chat = get_option( ALAS_Settings::OPTION_CHAT_ENABLED, 'yes' );
update_option( ALAS_Settings::OPTION_CHAT_ENABLED, 'yes' );
t_eq( ALAS_Settings::is_chat_enabled(), true, 'chat enabled by default value' );
$widget_with_chat = ALAS_Settings::is_widget_configured();
update_option( ALAS_Settings::OPTION_CHAT_ENABLED, '' );
t_eq( ALAS_Settings::is_chat_enabled(), false, 'unticked checkbox disables chat' );
t_eq( ALAS_Settings::is_widget_configured(), false, 'chat off hides the widget' );
t_eq( ALAS_Settings::is_configured(), true, 'chat off keeps tracking configured' );
update_option( ALAS_Settings::OPTION_CHAT_ENABLED, 'yes' );
t_eq( ALAS_Settings::is_widget_configured(), $widget_with_chat, 'chat back on restores the widget' );
update_option( ALAS_Settings::OPTION_CHAT_ENABLED, $saved_chat );

echo "== M. Proxy/CDN trust checkbox ==\n";
$saved_proxy  = get_option( ALAS_Settings::OPTION_TRUST_PROXY, '' );
$saved_remote = $_SERVER['REMOTE_ADDR'] ?? null;
$saved_xff    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$_SERVER['REMOTE_ADDR']          = '203.0.113.7';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 10.0.0.1';
update_option( ALAS_Settings::OPTION_TRUST_PROXY, '' );
t_eq( ALAS_Payloads::client_ip(), '203.0.113.7', 'checkbox off ignores X-Forwarded-For' );
update_option( ALAS_Settings::OPTION_TRUST_PROXY, 'yes' );
t_eq( ALAS_Payloads::client_ip(), '198.51.100.1', 'checkbox on uses rightmost public hop' );
update_option( ALAS_Settings::OPTION_TRUST_PROXY, '' );
add_filter( 'amper_las_trust_forwarded_for', '__return_true' );
t_eq( ALAS_Payloads::client_ip(), '198.51.100.1', 'legacy filter still wins over checkbox' );
remove_filter( 'amper_las_trust_forwarded_for', '__return_true' );
update_option( ALAS_Settings::OPTION_TRUST_PROXY, $saved_proxy );
if ( null === $saved_remote ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $saved_remote; }
if ( null === $saved_xff ) { unset( $_SERVER['HTTP_X_FORWARDED_FOR'] ); } else { $_SERVER['HTTP_X_FORWARDED_FOR'] = $saved_xff; }

echo "\nRESULT: {$GLOBALS['alas_pass']} passed, {$GLOBALS['alas_fail']} failed\n";
if ( $GLOBALS['alas_fail'] > 0 ) { exit( 1 ); }
