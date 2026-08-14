<?php
/**
 * Product search this shop answers for the LAS agent console.
 *
 * An agent helping a shopper in chat types a few letters and must see products from THIS shop's
 * real catalogue - not only the ones some shopper happened to view earlier. LAS therefore asks the
 * shop, and the shop answers using its own visibility and pricing rules, so a hidden or
 * out-of-catalogue product can never be sent into a conversation.
 *
 * The request is signed, never keyed: LAS sends a timestamp plus an HMAC of "<timestamp>.<body>"
 * computed with the store API key both sides already share. The secret never travels, and binding
 * the timestamp into the signed material stops a captured request from being replayed later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALAS_Search {

	const MAX_RESULTS             = 24;
	const MAX_QUERY_LENGTH        = 120;
	const SIGNATURE_MAX_AGE       = 300;
	const ROUTE_NAMESPACE         = 'amper-las/v1';
	const ROUTE                   = '/product-search';

	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( self::ROUTE_NAMESPACE, self::ROUTE, array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Authentication is the HMAC below, not a WordPress capability: the caller is LAS's
				// server, which holds no WordPress account.
				'permission_callback' => '__return_true',
			) );
		} );
	}

	/**
	 * Absolute URL of this endpoint, announced to LAS so it never has to guess per-platform paths.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( self::ROUTE_NAMESPACE . self::ROUTE );
	}

	/**
	 * Is this request really from LAS, and recent?
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $secret  Shared store API key.
	 * @return bool
	 */
	private static function signature_is_valid( $request, $secret ) {
		if ( '' === (string) $secret ) {
			// Nothing to verify against - an unconfigured shop must never expose its catalogue.
			return false;
		}
		$timestamp = (string) $request->get_header( 'x_amper_timestamp' );
		$signature = (string) $request->get_header( 'x_amper_signature' );
		if ( '' === $timestamp || '' === $signature ) {
			return false;
		}
		if ( ! is_numeric( $timestamp ) ) {
			return false;
		}
		if ( abs( time() - (int) $timestamp ) > self::SIGNATURE_MAX_AGE ) {
			return false;
		}
		$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $request->get_body(), $secret );

		// Constant-time compare: a byte-by-byte one leaks the right signature through timing.
		return hash_equals( $expected, $signature );
	}

	/**
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		if ( ! ALAS_Settings::is_enabled() ) {
			return new WP_REST_Response( array( 'detail' => 'Integration is disabled.' ), 403 );
		}
		if ( ! self::signature_is_valid( $request, ALAS_Settings::api_key() ) ) {
			return new WP_REST_Response( array( 'detail' => 'Invalid signature.' ), 403 );
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			// WooCommerce inactive: this WordPress install has no catalogue to search.
			return new WP_REST_Response( array( 'results' => array() ), 200 );
		}

		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( array( 'detail' => 'Invalid JSON payload.' ), 400 );
		}

		$query = self::clean_query( isset( $body['query'] ) ? $body['query'] : '' );
		$limit = isset( $body['limit'] ) ? (int) $body['limit'] : self::MAX_RESULTS;
		$limit = max( 1, min( $limit, self::MAX_RESULTS ) );
		if ( '' === $query ) {
			// A blank query must not dump the whole catalogue over the wire.
			return new WP_REST_Response( array( 'results' => array() ), 200 );
		}

		return new WP_REST_Response( array( 'results' => self::search( $query, $limit ) ), 200 );
	}

	/**
	 * Collapse whitespace and cap length - the query is remote input used to build a DB search.
	 *
	 * @param mixed $raw Raw query.
	 * @return string
	 */
	private static function clean_query( $raw ) {
		$query = is_scalar( $raw ) ? (string) $raw : '';
		$query = trim( preg_replace( '/\s+/u', ' ', $query ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $query, 0, self::MAX_QUERY_LENGTH ) : substr( $query, 0, self::MAX_QUERY_LENGTH );
	}

	/**
	 * Matching products, in the shared search contract.
	 *
	 * ``wc_get_products`` with ``status=publish`` and the catalogue visibility filter applies the
	 * shop's own rules, so hidden and draft products stay invisible to the agent. Prices come from
	 * ``wc_get_price_to_display``, the same helper the storefront uses, so tax display settings and
	 * any per-role pricing plugin are honoured rather than re-implemented here.
	 *
	 * @param string $query Search phrase.
	 * @param int    $limit Maximum rows.
	 * @return array
	 */
	private static function search( $query, $limit ) {
		$products = wc_get_products(
			array(
				'status'   => 'publish',
				'limit'    => $limit,
				'orderby'  => 'title',
				'order'    => 'ASC',
				's'        => $query,
				'visibility' => 'catalog',
			)
		);
		if ( ! is_array( $products ) ) {
			return array();
		}

		$results = array();
		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$results[] = array(
				'id'           => (string) $product->get_id(),
				'name'         => (string) $product->get_name(),
				'sku'          => (string) $product->get_sku(),
				'url'          => (string) $product->get_permalink(),
				'image'        => self::image_url( $product ),
				'price'        => self::price_display( $product ),
				'availability' => $product->is_in_stock() ? 'in_stock' : 'out_of_stock',
			);
		}

		return $results;
	}

	/**
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function image_url( $product ) {
		$image_id = $product->get_image_id();
		if ( ! $image_id ) {
			return '';
		}
		$src = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );

		return $src ? (string) $src : '';
	}

	/**
	 * Formatted price the agent (and then the shopper) reads verbatim - a bare number would be
	 * meaningless without the shop's currency and tax display settings.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function price_display( $product ) {
		// get_price_html() is the shop's OWN answer to "what price does a visitor see here?".
		// Wholesale/B2B setups replace it with "log in to see the price" through the
		// woocommerce_get_price_html filter, and catalogue-mode plugins blank it entirely. Building
		// the number ourselves from wc_get_price_to_display() would walk straight past that decision
		// and quote a price the shop deliberately hides.
		$html = (string) $product->get_price_html();
		if ( '' === $html ) {
			return '';
		}

		// wc_price() renders the currency symbol as an HTML entity ("&#122;&#322;" for "zł");
		// stripping tags alone would leave those entities for the shopper to read verbatim.
		$text = trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );

		// No digits means the shop answered with a message ("Price on request"), not a price. The
		// card shows no price rather than a sentence squeezed into a price chip.
		return preg_match( '/\d/u', $text ) ? $text : '';
	}
}
