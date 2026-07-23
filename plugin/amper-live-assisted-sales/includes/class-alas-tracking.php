<?php
/**
 * Server-side WooCommerce hooks -> LAS events (GA4 taxonomy). Business/money events
 * are emitted server-side (source of truth); session_start and telemetry come from
 * the browser tracker only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALAS_Tracking {

	const META_VISITOR       = '_amper_las_visitor_id';
	const META_SESSION       = '_amper_las_session_id';
	const META_PURCHASE_SENT = '_amper_las_purchase_sent';
	const SESSION_SHIPPING   = 'amper_las_shipping_info_sent';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'on_page_view' ), 20 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 20, 6 );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'on_cart_item_removed' ), 20, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( __CLASS__, 'on_cart_quantity_update' ), 20, 4 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'on_update_order_review' ), 20, 1 );
		// Classic (shortcode) checkout and the block/Store API checkout both funnel into the
		// same order handler, so either surface produces the same event stream.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_checkout_order_processed' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_store_api_order_processed' ), 20, 1 );
		// Optional wishlist integration (no hard dependency): YITH WooCommerce Wishlist.
		add_action( 'yith_wcwl_added_to_wishlist', array( __CLASS__, 'on_added_to_wishlist' ), 20, 3 );
	}

	// ---- Page-view funnel events -----------------------------------------

	public static function on_page_view() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ! ALAS_Settings::is_configured() ) {
			return;
		}
		if ( 'HEAD' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			return;
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product ) {
				ALAS_Dispatch::dispatch( 'view_item', array(
					'product' => ALAS_Payloads::product_payload( $product ),
					'page'    => array( 'title' => $product->get_name() ),
				) );
			}
			return;
		}

		if ( is_search() ) {
			global $wp_query;
			$query = trim( get_search_query() );
			if ( $query !== '' ) {
				ALAS_Dispatch::dispatch( 'search', array(
					'search' => array(
						'query' => $query,
						// LAS classifies zero-result searches strictly by results_count.
						'results_count' => (int) $wp_query->found_posts,
					),
					'page' => array( 'title' => 'Search: ' . $query ),
				) );
			}
			return;
		}

		if ( function_exists( 'is_product_category' ) && ( is_product_category() || is_product_tag() ) ) {
			$term = get_queried_object();
			ALAS_Dispatch::dispatch( 'view_item_list', array(
				'category' => ALAS_Payloads::category_payload( $term ),
				'page'     => array( 'title' => $term instanceof WP_Term ? $term->name : '' ),
			) );
			return;
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			ALAS_Dispatch::dispatch( 'view_item_list', array(
				'page' => array( 'title' => get_the_title( wc_get_page_id( 'shop' ) ) ?: 'Products' ),
			) );
			return;
		}

		if ( function_exists( 'is_cart' ) && is_cart() && WC()->cart && ! WC()->cart->is_empty() ) {
			ALAS_Dispatch::dispatch( 'view_cart', array( 'page' => array( 'title' => 'Cart' ) ) );
			return;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && WC()->cart && ! WC()->cart->is_empty() ) {
			// GA4 begin_checkout - fired when the checkout page opens (not at final submit).
			ALAS_Dispatch::dispatch( 'begin_checkout', array( 'page' => array( 'title' => 'Checkout' ) ) );
		}
	}

	// ---- Cart events ------------------------------------------------------

	public static function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		if ( ! ALAS_Settings::is_configured() ) {
			return;
		}
		$product = wc_get_product( $variation_id ? (int) $variation_id : (int) $product_id );
		if ( ! $product ) {
			return;
		}
		self::fresh_cart_totals();
		ALAS_Dispatch::dispatch( 'add_to_cart', array(
			'product' => ALAS_Payloads::product_payload( $product ),
			'cart'    => ALAS_Payloads::cart_payload(),
			// Raised from WooCommerce's background add-to-cart request, whose URL is the endpoint,
			// not a page the shopper visited - report where they actually are.
			'url'     => ALAS_Payloads::referring_page_url(),
		) );
	}

	public static function on_cart_item_removed( $cart_item_key, $cart ) {
		if ( ! ALAS_Settings::is_configured() || ! $cart instanceof WC_Cart ) {
			return;
		}
		$removed = $cart->removed_cart_contents[ $cart_item_key ] ?? array();
		$product = wc_get_product( (int) ( $removed['variation_id'] ?? 0 ) ?: (int) ( $removed['product_id'] ?? 0 ) );
		self::fresh_cart_totals();
		ALAS_Dispatch::dispatch( 'remove_from_cart', array(
			'product' => $product ? ALAS_Payloads::product_payload( $product ) : array(),
			'cart'    => ALAS_Payloads::cart_payload( $cart ),
			'url'     => ALAS_Payloads::referring_page_url(),
		) );
	}

	/**
	 * GA4 semantics for a cart-page quantity change: an increase is an add_to_cart,
	 * a decrease a remove_from_cart (a b2c quantity increment flows through the same
	 * add path). Quantity-to-zero is already covered by woocommerce_cart_item_removed.
	 */
	public static function on_cart_quantity_update( $cart_item_key, $quantity, $old_quantity, $cart = null ) {
		if ( ! ALAS_Settings::is_configured() ) {
			return;
		}
		$delta = (int) $quantity - (int) $old_quantity;
		if ( 0 === $delta ) {
			return;
		}
		$cart      = $cart instanceof WC_Cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		$cart_item = $cart ? ( $cart->get_cart()[ $cart_item_key ] ?? array() ) : array();
		$product   = wc_get_product( (int) ( $cart_item['variation_id'] ?? 0 ) ?: (int) ( $cart_item['product_id'] ?? 0 ) );
		if ( ! $product ) {
			return;
		}
		self::fresh_cart_totals();
		ALAS_Dispatch::dispatch( $delta > 0 ? 'add_to_cart' : 'remove_from_cart', array(
			'product' => ALAS_Payloads::product_payload( $product ),
			'cart'    => ALAS_Payloads::cart_payload( $cart ),
			'url'     => ALAS_Payloads::referring_page_url(),
		) );
	}

	/** The hooks above can fire before the caller recalculates totals; refresh so snapshots are current. */
	private static function fresh_cart_totals() {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->calculate_totals();
		}
	}

	// ---- Checkout events --------------------------------------------------

	/**
	 * GA4 add_shipping_info - the shopper confirmed a delivery method on the classic
	 * checkout (fires on the update_order_review AJAX whenever the method changes).
	 * De-duplicated per chosen method via the WooCommerce session.
	 */
	public static function on_update_order_review( $post_data ) {
		if ( ! ALAS_Settings::is_configured() || ! WC()->session ) {
			return;
		}
		parse_str( (string) $post_data, $posted );
		$methods = isset( $posted['shipping_method'] ) && is_array( $posted['shipping_method'] ) ? array_values( $posted['shipping_method'] ) : array();
		$chosen  = (string) ( $methods[0] ?? '' );
		if ( '' === $chosen ) {
			return;
		}
		if ( WC()->session->get( self::SESSION_SHIPPING ) === $chosen ) {
			return;
		}
		WC()->session->set( self::SESSION_SHIPPING, $chosen );
		// This hook fires BEFORE WooCommerce applies the newly chosen method to the cart,
		// so read label AND cost from the chosen rate itself - the cart's shipping total
		// still reflects the previous method here. Recalculate FIRST: early in the AJAX
		// request the shipping packages may not exist yet and the rate lookup would fail.
		self::fresh_cart_totals();
		$rate = self::shipping_rate( $chosen );
		$cost = $rate ? (float) $rate->get_cost() + array_sum( array_map( 'floatval', (array) $rate->get_taxes() ) ) : 0.0;
		ALAS_Dispatch::dispatch( 'add_shipping_info', array(
			'metadata' => array(
				'shipping' => array(
					'tier' => $rate ? (string) $rate->get_label() : $chosen,
					'cost' => ALAS_Payloads::amount( $cost ),
				),
			),
			'page' => array( 'title' => 'Checkout' ),
		) );
	}

	private static function shipping_rate( $rate_id ) {
		if ( WC()->shipping ) {
			foreach ( WC()->shipping->get_packages() as $package ) {
				if ( isset( $package['rates'][ $rate_id ] ) ) {
					return $package['rates'][ $rate_id ];
				}
			}
		}
		return null;
	}

	public static function on_checkout_order_processed( $order_id, $posted_data, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order ) {
			self::emit_order_events( $order );
		}
	}

	public static function on_store_api_order_processed( $order ) {
		if ( $order instanceof WC_Order ) {
			self::emit_order_events( $order );
		}
	}

	/**
	 * add_payment_info + purchase for a freshly placed order. The LAS visitor/session
	 * ids are captured into order meta and passed explicitly so the conversion
	 * attributes to the right session even off the original request path.
	 */
	private static function emit_order_events( WC_Order $order ) {
		if ( ! ALAS_Settings::is_configured() ) {
			return;
		}
		if ( $order->get_meta( self::META_PURCHASE_SENT ) ) {
			return;
		}
		$visitor_id = ALAS_Payloads::visitor_id();
		$session_id = ALAS_Payloads::session_id();
		$order->update_meta_data( self::META_VISITOR, $visitor_id );
		$order->update_meta_data( self::META_SESSION, $session_id );
		$order->update_meta_data( self::META_PURCHASE_SENT, gmdate( 'c' ) );
		$order->save();

		// Block-checkout path never passed through update_order_review, so make sure the
		// funnel still carries add_shipping_info before payment/purchase.
		$shipping_pending = ! WC()->session || WC()->session->get( self::SESSION_SHIPPING ) === null;
		if ( $shipping_pending && $order->get_shipping_method() ) {
			ALAS_Dispatch::dispatch( 'add_shipping_info', array(
				'visitor_id' => $visitor_id,
				'session_id' => $session_id,
				'metadata'   => array(
					'shipping' => array(
						'tier' => (string) $order->get_shipping_method(),
						'cost' => ALAS_Payloads::amount( $order->get_shipping_total() ),
					),
				),
				'page' => array( 'title' => 'Checkout' ),
			) );
		}
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_SHIPPING, null );
		}

		ALAS_Dispatch::dispatch( 'add_payment_info', array(
			'visitor_id' => $visitor_id,
			'session_id' => $session_id,
			'metadata'   => array( 'payment' => array( 'type' => (string) $order->get_payment_method_title() ) ),
			'page'       => array( 'title' => 'Checkout' ),
		) );

		ALAS_Dispatch::dispatch( 'purchase', array(
			'visitor_id' => $visitor_id,
			'session_id' => $session_id,
			'cart'       => ALAS_Payloads::order_cart_payload( $order ),
			'metadata'   => ALAS_Payloads::order_metadata( $order ),
			'page'       => array( 'title' => 'Order ' . $order->get_id() ),
		) );
	}

	// ---- Wishlist (optional) ---------------------------------------------

	public static function on_added_to_wishlist( $product_id, $wishlist_id = 0, $user_id = 0 ) {
		if ( ! ALAS_Settings::is_configured() ) {
			return;
		}
		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return;
		}
		ALAS_Dispatch::dispatch( 'add_to_wishlist', array(
			'product' => ALAS_Payloads::product_payload( $product ),
			'page'    => array( 'title' => $product->get_name() ),
		) );
	}
}
