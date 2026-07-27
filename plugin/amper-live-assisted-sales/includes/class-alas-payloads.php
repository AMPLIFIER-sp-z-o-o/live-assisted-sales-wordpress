<?php
/**
 * Event payload builders: the LAS event envelope plus product / category / cart /
 * order snapshots built from WooCommerce objects. Field-for-field compatible with
 * the AMPER b2c reference integration (GA4 event taxonomy).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALAS_Payloads {

	const VISITOR_COOKIE = 'las_visitor_id';
	const SESSION_COOKIE = 'las_session_id';

	// Keep in sync with las-backend StorefrontEvent.EventType.
	const SUPPORTED_EVENT_TYPES = array(
		'view_item_list',
		'select_item',
		'view_item',
		'add_to_wishlist',
		'add_to_cart',
		'remove_from_cart',
		'view_cart',
		'begin_checkout',
		'add_shipping_info',
		'add_payment_info',
		'purchase',
		// Behavioural signals (not GA4 funnel): session lifecycle + engagement.
		'session_start',
		'search',
		'scroll_depth',
		'page_ping',
		'session_end',
	);

	// High-frequency telemetry never needs a server-side cart snapshot (a page_ping every
	// ~15s must not run a cart rebuild each time); select_item is a client-side tile click.
	const CART_CONTEXT_EXCLUDED_EVENT_TYPES = array( 'scroll_depth', 'page_ping', 'select_item' );

	// ---- Identity ---------------------------------------------------------

	public static function visitor_id() {
		$value = sanitize_text_field( (string) ( $_COOKIE[ self::VISITOR_COOKIE ] ?? '' ) );
		return $value !== '' ? substr( $value, 0, 128 ) : 'visitor-' . wp_generate_uuid4();
	}

	public static function session_id() {
		$value = sanitize_text_field( (string) ( $_COOKIE[ self::SESSION_COOKIE ] ?? '' ) );
		return $value !== '' ? substr( $value, 0, 128 ) : 'session-' . wp_generate_uuid4();
	}

	/**
	 * Real shopper IP. Events are forwarded server-to-server, so LAS only sees this
	 * server's IP - the visitor's address must ride in the payload.
	 *
	 * X-Forwarded-For is a CLIENT-SUPPLIED header: taken at face value, any shopper can hand the
	 * merchant a made-up address that the console then shows as fact, country flag and all. There is
	 * no way to detect from inside PHP whether something in front actually rewrites the header -
	 * a request arriving from a private address only means "something is in front", not "that
	 * something appends X-Forwarded-For" (a plain Docker or LAN setup satisfies the first and not
	 * the second, and the spoof sails through). So the chain is ignored unless the site says
	 * otherwise, and REMOTE_ADDR - which cannot be forged - is used instead:
	 *
	 *     add_filter( 'amper_las_trust_forwarded_for', '__return_true' );
	 *
	 * Behind Cloudflare or an nginx that sets `$proxy_add_x_forwarded_for`, turn it on: the header
	 * then carries the real address and we read the RIGHTMOST public entry, the hop that proxy
	 * appended, because everything to its left is whatever the client typed.
	 */
	public static function client_ip() {
		$remote    = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$forwarded = (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' );
		if ( ! $forwarded ) {
			return $remote;
		}
		/**
		 * Whether a proxy/CDN in front of this site rewrites X-Forwarded-For and it may be trusted.
		 * Off by default: a forged customer IP shown to the merchant as fact is worse than showing
		 * the proxy's own address.
		 *
		 * @param bool   $trusted Whether the forwarded chain may be trusted.
		 * @param string $remote  REMOTE_ADDR for this request.
		 */
		$trusted = (bool) apply_filters( 'amper_las_trust_forwarded_for', false, $remote );
		if ( ! $trusted ) {
			return $remote;
		}
		$hops = array_reverse( array_map( 'trim', explode( ',', $forwarded ) ) );
		foreach ( $hops as $hop ) {
			if ( $hop && filter_var( $hop, FILTER_VALIDATE_IP ) && ! self::is_private_ip( $hop ) ) {
				return $hop;
			}
		}
		return $remote;
	}

	/** Loopback / RFC1918 / link-local / unique-local address - i.e. our own infrastructure. */
	private static function is_private_ip( $ip ) {
		if ( ! $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Identity block for the event. When PII forwarding is not allowed (no consent yet /
	 * opted out) the pseudonymous account id stays for operational linkage but the raw
	 * email / display name are DROPPED.
	 */
	public static function user_metadata( $include_pii = true ) {
		$user = wp_get_current_user();
		if ( $user && $user->exists() ) {
			if ( ! $include_pii ) {
				return array(
					'status'        => 'authenticated',
					'authenticated' => true,
					'id'            => (string) $user->ID,
				);
			}
			$email   = (string) $user->user_email;
			$display = trim( (string) $user->display_name );
			if ( ! $display ) {
				$display = $email ?: (string) $user->user_login;
			}
			return array(
				'status'        => 'authenticated',
				'authenticated' => true,
				'id'            => (string) $user->ID,
				'email'         => $email,
				'display'       => $display,
			);
		}
		return array( 'status' => 'anonymous', 'authenticated' => false );
	}

	// ---- Envelope ---------------------------------------------------------

	/**
	 * Builds the full LAS event envelope, enriching with consent, user identity,
	 * client IP, user agent, the store logo (session_start) and the current cart.
	 *
	 * @param string $event_type One of SUPPORTED_EVENT_TYPES.
	 * @param array  $data       Optional overrides: event_id, visitor_id, session_id,
	 *                           occurred_at, url, page, product, category, search, cart,
	 *                           cursor, metadata.
	 */
	public static function build_envelope( $event_type, array $data = array() ) {
		$metadata = isset( $data['metadata'] ) && is_array( $data['metadata'] ) ? $data['metadata'] : array();

		// Resolve the shopper's consent choice FIRST (set by the tracker's banner cookie)
		// so it can gate PII below. LAS applies its own per-store regime on receipt.
		if ( ! array_key_exists( 'consent', $metadata ) ) {
			$consent = ALAS_Consent::consent_value();
			if ( null !== $consent ) {
				$metadata['consent'] = $consent;
			}
		}

		$pii_allowed      = ALAS_Consent::pii_forwarding_allowed();
		$metadata['user'] = self::user_metadata( $pii_allowed );
		if ( $pii_allowed ) {
			$client_ip = self::client_ip();
			if ( $client_ip ) {
				$metadata['client_ip'] = $client_ip;
			}
		}
		if ( empty( $metadata['user_agent'] ) ) {
			$user_agent = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
			if ( $user_agent ) {
				$metadata['user_agent'] = substr( $user_agent, 0, 512 );
			}
		}
		// Propagate the store's brand logo once per session so the LAS agent console can
		// show it as the support-team avatar, matching the customer-facing widget.
		if ( 'session_start' === $event_type ) {
			$logo_url = ALAS_Settings::widget_logo_url();
			if ( $logo_url ) {
				$widget             = isset( $metadata['widget'] ) && is_array( $metadata['widget'] ) ? $metadata['widget'] : array();
				$widget['logo_url'] = $logo_url;
				$metadata['widget'] = $widget;
			}
		}

		$cart = array_key_exists( 'cart', $data ) ? $data['cart'] : null;
		if ( ! $cart && ! in_array( $event_type, self::CART_CONTEXT_EXCLUDED_EVENT_TYPES, true ) ) {
			$cart = self::cart_payload();
		}

		$occurred_at = $data['occurred_at'] ?? gmdate( 'c' );

		return array(
			'event_id'    => (string) ( $data['event_id'] ?? wp_generate_uuid4() ),
			'event_type'  => $event_type,
			'visitor_id'  => (string) ( $data['visitor_id'] ?? '' ) !== '' ? (string) $data['visitor_id'] : self::visitor_id(),
			'session_id'  => (string) ( $data['session_id'] ?? '' ) !== '' ? (string) $data['session_id'] : self::session_id(),
			'occurred_at' => $occurred_at,
			'url'         => (string) ( $data['url'] ?? self::current_url() ),
			'page'        => isset( $data['page'] ) && is_array( $data['page'] ) ? $data['page'] : array(),
			'product'     => isset( $data['product'] ) && is_array( $data['product'] ) ? $data['product'] : array(),
			'category'    => isset( $data['category'] ) && is_array( $data['category'] ) ? $data['category'] : array(),
			'search'      => isset( $data['search'] ) && is_array( $data['search'] ) ? $data['search'] : array(),
			'cart'        => is_array( $cart ) ? $cart : array(),
			'cursor'      => isset( $data['cursor'] ) && is_array( $data['cursor'] ) ? $data['cursor'] : array(),
			'metadata'    => $metadata,
		);
	}

	/**
	 * The absolute URL of the page being tracked.
	 *
	 * Built from home_url(), never from the Host header: HTTP_HOST is client-supplied, so a
	 * spoofed Host used to put an attacker-chosen absolute URL into the merchant's event history,
	 * where it reads like a link to their own shop. Only the path/query comes from the request.
	 */
	public static function current_url() {
		$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$path = '/' . ltrim( wp_parse_url( $uri, PHP_URL_PATH ) ?? '/', '/' );
		$query = (string) ( wp_parse_url( $uri, PHP_URL_QUERY ) ?? '' );
		return home_url( $path . ( $query ? '?' . $query : '' ) );
	}

	/**
	 * The page the shopper is actually looking at.
	 *
	 * WooCommerce handles "add to cart" in the background (?wc-ajax=add_to_cart, admin-ajax.php,
	 * or a POST back to the cart), so current_url() there returns the endpoint - an address the
	 * shopper never visited, which the LAS console would otherwise show the agent as "Now on".
	 * The referer is the real page; it is trusted only when it points back at this shop, so a
	 * forged header cannot put an arbitrary link in front of the agent.
	 *
	 * @return string
	 */
	public static function referring_page_url() {
		$referer = (string) wp_get_referer();
		if ( $referer ) {
			$referer_host = (string) wp_parse_url( $referer, PHP_URL_HOST );
			$home_host    = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			if ( $referer_host && strcasecmp( $referer_host, $home_host ) === 0 ) {
				return $referer;
			}
		}
		return self::current_url();
	}

	// ---- Money formatting -------------------------------------------------

	/** Plain-decimal amount string ("129.00") from any WooCommerce amount. */
	public static function amount( $value ) {
		return (string) wc_format_decimal( $value, 2 );
	}

	/**
	 * Human display amount ("129,00 zł") using the store's own price formatting,
	 * flattened to plain text (LAS renders it verbatim in the agent console).
	 */
	public static function amount_display( $value ) {
		$html = wc_price( (float) $value );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		// Normalize non-breaking / narrow spaces to regular spaces.
		return trim( str_replace( array( "\xc2\xa0", "\xe2\x80\xaf" ), ' ', $text ) );
	}

	// ---- Product / category ----------------------------------------------

	public static function product_payload( $product ) {
		$product = self::resolve_product( $product );
		if ( ! $product ) {
			return array();
		}
		$image_id  = $product->get_image_id();
		$image_url = $image_id ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_single' ) : '';
		// A product with no price set sends "" (b2c parity), not a phantom 0.00.
		$raw_price = $product->get_price( 'edit' );
		$price     = ( '' === $raw_price || null === $raw_price ) ? '' : wc_get_price_to_display( $product );
		return array(
			'id'            => (string) $product->get_id(),
			'name'          => (string) $product->get_name(),
			'sku'           => (string) $product->get_sku(),
			'url'           => (string) $product->get_permalink(),
			// Image + price let LAS render a clickable product card (not just text) in the chat.
			'image'         => $image_url,
			'price'         => '' !== $price && null !== $price ? self::amount( $price ) : '',
			'price_display' => '' !== $price && null !== $price ? self::amount_display( $price ) : '',
			'currency'      => get_woocommerce_currency(),
		);
	}

	private static function resolve_product( $product ) {
		if ( $product instanceof WC_Product ) {
			return $product;
		}
		if ( is_numeric( $product ) ) {
			$obj = wc_get_product( (int) $product );
			return $obj ? $obj : null;
		}
		return null;
	}

	public static function category_payload( $term ) {
		if ( ! $term instanceof WP_Term ) {
			return array();
		}
		$url = get_term_link( $term );
		return array(
			'id'   => (string) $term->term_id,
			'name' => (string) $term->name,
			'slug' => (string) $term->slug,
			'url'  => is_wp_error( $url ) ? '' : (string) $url,
		);
	}

	// ---- Cart -------------------------------------------------------------

	/** Snapshot of the current WooCommerce cart in the LAS cart shape. */
	public static function cart_payload( $cart = null ) {
		if ( null === $cart ) {
			$cart = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		}
		$currency = get_woocommerce_currency();
		if ( ! $cart instanceof WC_Cart || $cart->is_empty() ) {
			return array(
				'items_count'   => $cart instanceof WC_Cart ? (int) $cart->get_cart_contents_count() : 0,
				'total'         => '0.00',
				'total_display' => self::amount_display( 0 ),
				'currency'      => $currency,
				'items'         => array(),
			);
		}

		$items = array();
		foreach ( array_slice( $cart->get_cart(), 0, 50, true ) as $cart_item ) {
			$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ? $cart_item['data'] : null;
			if ( ! $product ) {
				continue;
			}
			$quantity   = (int) ( $cart_item['quantity'] ?? 1 );
			$unit_price = (float) wc_get_price_to_display( $product );
			$items[]    = array(
				'product_id' => (string) $product->get_id(),
				'name'       => (string) $product->get_name(),
				'sku'        => (string) $product->get_sku(),
				'url'        => (string) $product->get_permalink(),
				'quantity'   => $quantity,
				'price'      => self::amount( $unit_price ),
				// Keep the funnel invariant line_total == unit_price * quantity.
				'line_total' => self::amount( $unit_price * $quantity ),
				'currency'   => $currency,
			);
		}

		// Items value (after discounts, incl. tax) WITHOUT shipping - the agent console
		// shows this as "cart value"; a shipping-inflated number would read as a phantom item.
		$total = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax();
		return array(
			'items_count'   => (int) $cart->get_cart_contents_count(),
			'total'         => self::amount( $total ),
			'total_display' => self::amount_display( $total ),
			'currency'      => $currency,
			'items'         => $items,
		);
	}

	// ---- Order (purchase) -------------------------------------------------

	/**
	 * A cart-shaped snapshot of a placed order so the LAS agent console renders order
	 * line items with the same helpers it uses for live carts. line_total ==
	 * unit_price * quantity is preserved (funnel value invariant).
	 */
	public static function order_cart_payload( WC_Order $order ) {
		$currency = $order->get_currency() ?: get_woocommerce_currency();
		$items    = array();
		$count    = 0;
		foreach ( array_slice( $order->get_items(), 0, 50, true ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$quantity = (int) $item->get_quantity();
			$count   += $quantity;
			$product  = $item->get_product();
			$line     = (float) $order->get_line_total( $item, true );
			$unit     = $quantity > 0 ? $line / $quantity : $line;
			$items[]  = array(
				'product_id' => (string) ( $item->get_variation_id() ?: $item->get_product_id() ),
				'name'       => (string) $item->get_name(),
				'sku'        => $product ? (string) $product->get_sku() : '',
				'url'        => $product ? (string) $product->get_permalink() : '',
				'quantity'   => $quantity,
				'price'      => self::amount( $unit ),
				'line_total' => self::amount( $line ),
				'currency'   => $currency,
			);
		}
		$total = $order->get_total();
		return array(
			'items_count'   => $count,
			'total'         => self::amount( $total ),
			'total_display' => self::amount_display( $total ),
			'currency'      => $currency,
			'items'         => $items,
		);
	}

	/**
	 * Order-specific fields carried in metadata.order - the supervised labels the LAS
	 * intent engine reads.
	 */
	public static function order_metadata( WC_Order $order ) {
		$currency = $order->get_currency() ?: get_woocommerce_currency();
		return array(
			'order' => array(
				'order_id'       => (string) $order->get_id(),
				'tracking_token' => (string) $order->get_order_key(),
				'status'         => (string) $order->get_status(),
				'subtotal'       => self::amount( $order->get_subtotal() ),
				'discount_total' => self::amount( $order->get_discount_total() ),
				'delivery_cost'  => self::amount( $order->get_shipping_total() ),
				'total'          => self::amount( $order->get_total() ),
				'currency'       => $currency,
				'coupon_code'    => implode( ',', $order->get_coupon_codes() ),
			),
		);
	}
}
