<?php
/**
 * Same-origin browser-events proxy: the tracker posts batched telemetry here and the
 * server enriches (IP, UA, user identity, cart, consent gate) and forwards it to LAS
 * server-to-server - the browser never talks to LAS directly nor sees the API key.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALAS_Rest {

	const MAX_PAYLOAD_BYTES = 32768;
	const MAX_BATCH         = 25;

	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'amper-las/v1', '/events', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Anonymous shoppers post telemetry; protection is the same-origin check
				// (+ server-side enrichment only from trusted request state).
				'permission_callback' => '__return_true',
			) );
		} );
	}

	/**
	 * Cart snapshot for browser events, read STRAIGHT from the persisted WooCommerce
	 * session (cookie -> stored row) without initializing WC()->cart. The session
	 * handler's init() hooks a save at shutdown; skipping it guarantees this request
	 * can never overwrite the shopper's cart/coupons with a partial state. The stored
	 * line rows carry line_total/line_tax from the last real calculation, so totals
	 * reflect applied discounts like the b2c reference does.
	 *
	 * @return array Cart payload, or empty array when there is no readable session.
	 */
	private static function readonly_session_cart() {
		if ( function_exists( 'WC' ) && WC()->cart instanceof WC_Cart && ! WC()->cart->is_empty() ) {
			return ALAS_Payloads::cart_payload();
		}
		if ( ! class_exists( 'WC_Session_Handler' ) ) {
			return array();
		}
		try {
			$handler = new WC_Session_Handler();
			$cookie  = $handler->get_session_cookie();
			if ( ! $cookie || empty( $cookie[0] ) ) {
				return array();
			}
			$session = $handler->get_session( $cookie[0] );
			if ( ! is_array( $session ) ) {
				return array();
			}
			// Never let a serialized payload instantiate objects: the cart row is written by
			// WooCommerce, but this is the one place the plugin unserializes stored data, so it
			// stays object-free by construction rather than by trusting the writer.
			$raw  = (string) ( $session['cart'] ?? '' );
			$rows = is_serialized( $raw ) ? unserialize( $raw, array( 'allowed_classes' => false ) ) : maybe_unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			if ( ! is_array( $rows ) || ! $rows ) {
				return array();
			}
			$currency = get_woocommerce_currency();
			$items    = array();
			$count    = 0;
			$total    = 0.0;
			foreach ( array_slice( $rows, 0, 50, true ) as $row ) {
				$product = wc_get_product( (int) ( $row['variation_id'] ?? 0 ) ?: (int) ( $row['product_id'] ?? 0 ) );
				if ( ! $product ) {
					continue;
				}
				$quantity = (int) ( $row['quantity'] ?? 1 );
				$line     = (float) ( $row['line_total'] ?? 0 ) + (float) ( $row['line_tax'] ?? 0 );
				$count   += $quantity;
				$total   += $line;
				$items[]  = array(
					'product_id' => (string) $product->get_id(),
					'name'       => (string) $product->get_name(),
					'sku'        => (string) $product->get_sku(),
					'url'        => (string) $product->get_permalink(),
					'quantity'   => $quantity,
					'price'      => ALAS_Payloads::amount( $quantity > 0 ? $line / $quantity : $line ),
					'line_total' => ALAS_Payloads::amount( $line ),
					'currency'   => $currency,
				);
			}
			return array(
				'items_count'   => $count,
				'total'         => ALAS_Payloads::amount( $total ),
				'total_display' => ALAS_Payloads::amount_display( $total ),
				'currency'      => $currency,
				'items'         => $items,
			);
		} catch ( Throwable $e ) {
			return array();
		}
	}

	private static function is_same_origin( WP_REST_Request $request ) {
		$origin = $request->get_header( 'origin' );
		if ( ! $origin ) {
			$origin = $request->get_header( 'referer' );
		}
		if ( ! $origin ) {
			return true;
		}
		$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
		$origin_port = wp_parse_url( $origin, PHP_URL_PORT );
		$home_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$home_port   = wp_parse_url( home_url(), PHP_URL_PORT );
		return strtolower( (string) $origin_host ) === strtolower( (string) $home_host )
			&& (string) $origin_port === (string) $home_port;
	}

	/**
	 * Restore the logged-in shopper for this request.
	 *
	 * WordPress drops cookie authentication on REST requests that carry no X-WP-Nonce
	 * (rest_cookie_check_errors sets the current user to 0), and the tracker deliberately sends
	 * none: it fires from cached pages and via sendBeacon, where a 12-24h nonce would go stale.
	 * The consequence was that every browser-side event (select_item, session_start, scroll_depth,
	 * page_ping, session_end) reported metadata.user as anonymous even while the shopper was
	 * signed in - the SERVER-side events on the very same visit carried their e-mail, so one
	 * customer showed up as two people in the console. Validate the logged-in cookie ourselves;
	 * it grants no extra privileges (this endpoint writes nothing to WordPress) and only affects
	 * the identity attached to outgoing events.
	 */
	private static function restore_cookie_user() {
		if ( is_user_logged_in() ) {
			return;
		}
		$user_id = wp_validate_auth_cookie( '', 'logged_in' );
		if ( $user_id ) {
			wp_set_current_user( (int) $user_id );
		}
	}

	public static function handle( WP_REST_Request $request ) {
		if ( ! self::is_same_origin( $request ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'detail' => 'Cross-origin event requests are not allowed.' ), 403 );
		}
		self::restore_cookie_user();

		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_PAYLOAD_BYTES ) {
			return new WP_REST_Response( array( 'ok' => false, 'detail' => 'Payload too large.' ), 413 );
		}

		if ( ! ALAS_Settings::is_configured() ) {
			return new WP_REST_Response( array( 'ok' => true, 'sent' => 0 ), 200 );
		}

		$data = json_decode( $body, true );
		if ( null === $data && 'null' !== trim( $body ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'detail' => 'Invalid JSON.' ), 400 );
		}

		if ( is_array( $data ) && array_is_list( $data ) ) {
			$events = $data;
		} elseif ( is_array( $data ) && isset( $data['events'] ) && is_array( $data['events'] ) ) {
			$events = $data['events'];
		} elseif ( is_array( $data ) ) {
			$events = array( $data );
		} else {
			return new WP_REST_Response( array( 'ok' => false, 'detail' => 'Invalid events payload.' ), 400 );
		}

		// WooCommerce does not load the session/cart on REST requests. Never call
		// wc_load_cart() here: initializing the cart in REST hooks a session save at
		// shutdown that persists a HALF-LOADED state - it silently wiped applied
		// coupons from the shopper's session. Peek at the stored session read-only.
		$session_cart = self::readonly_session_cart();

		$sent   = 0;
		$errors = array();
		foreach ( array_slice( $events, 0, self::MAX_BATCH ) as $item ) {
			if ( ! is_array( $item ) ) {
				$errors[] = 'Invalid event object.';
				continue;
			}
			$event_type = trim( (string) ( $item['event_type'] ?? '' ) );
			if ( ! in_array( $event_type, ALAS_Payloads::SUPPORTED_EVENT_TYPES, true ) ) {
				$errors[] = 'Unsupported event type.';
				continue;
			}
			$overrides = array(
				'event_id'   => (string) ( $item['event_id'] ?? '' ) ?: wp_generate_uuid4(),
				'page'       => is_array( $item['page'] ?? null ) ? $item['page'] : array(),
				'product'    => is_array( $item['product'] ?? null ) ? $item['product'] : array(),
				'category'   => is_array( $item['category'] ?? null ) ? $item['category'] : array(),
				'search'     => is_array( $item['search'] ?? null ) ? $item['search'] : array(),
				'cursor'     => is_array( $item['cursor'] ?? null ) ? $item['cursor'] : array(),
				'metadata'   => is_array( $item['metadata'] ?? null ) ? $item['metadata'] : array(),
			);
			if ( ! empty( $item['visitor_id'] ) ) {
				$overrides['visitor_id'] = (string) $item['visitor_id'];
			}
			if ( ! empty( $item['session_id'] ) ) {
				$overrides['session_id'] = (string) $item['session_id'];
			}
			if ( ! empty( $item['occurred_at'] ) ) {
				$overrides['occurred_at'] = (string) $item['occurred_at'];
			}
			if ( ! empty( $item['url'] ) ) {
				$overrides['url'] = (string) $item['url'];
			}
			if ( array_key_exists( 'cart', $item ) && is_array( $item['cart'] ) ) {
				$overrides['cart'] = $item['cart'];
			} elseif ( $session_cart && ! in_array( $event_type, ALAS_Payloads::CART_CONTEXT_EXCLUDED_EVENT_TYPES, true ) ) {
				$overrides['cart'] = $session_cart;
			}
			if ( ALAS_Dispatch::dispatch( $event_type, $overrides ) ) {
				$sent++;
			} else {
				$errors[] = 'Event could not be queued for Live Assisted Sales.';
			}
		}

		$status = $errors ? 400 : 200;
		return new WP_REST_Response( array( 'ok' => ! $errors, 'sent' => $sent, 'errors' => $errors ), $status );
	}
}
