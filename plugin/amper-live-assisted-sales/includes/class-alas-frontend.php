<?php
/**
 * Storefront glue: enqueues the browser tracker with its config, embeds the LAS chat
 * widget and publishes the signed customer identity (window.LAS_CUSTOMER).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALAS_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_widget_embed' ), 50 );
	}

	public static function enqueue() {
		if ( is_admin() || ! ALAS_Settings::is_configured() ) {
			return;
		}

		wp_enqueue_script(
			'amper-las-tracker',
			AMPER_LAS_PLUGIN_URL . 'assets/js/tracker.js',
			array(),
			AMPER_LAS_VERSION,
			true
		);
		wp_localize_script( 'amper-las-tracker', 'amperLasConfig', self::tracker_config() );

		if ( ALAS_Settings::is_widget_configured() ) {
			wp_enqueue_script(
				'amper-las-cart-bridge',
				AMPER_LAS_PLUGIN_URL . 'assets/js/cart-bridge.js',
				array(),
				AMPER_LAS_VERSION,
				true
			);
			wp_localize_script( 'amper-las-cart-bridge', 'amperLasCartBridge', array(
				'ajaxUrl' => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'add_to_cart' ) : '',
				'addedMessage' => __( 'Added to cart:', 'amper-las' ),
			) );
		}
	}

	private static function tracker_config() {
		return array(
			'endpoint'      => rest_url( 'amper-las/v1/events' ),
			'consentRegion' => ALAS_Consent::region(),
			'consentTexts'  => ALAS_Consent::texts(),
			'initialCart'   => ALAS_Payloads::cart_payload(),
			'customer'      => self::customer_payload(),
			'listContext'   => self::list_context(),
		);
	}

	/**
	 * The identity the chat widget uses to skip the pre-chat form, with an HMAC proof
	 * las-backend verifies with the same store key. The key itself never reaches the
	 * browser - only the signature does.
	 */
	private static function customer_payload() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return new stdClass();
		}
		$email   = (string) $user->user_email;
		$display = trim( (string) $user->display_name ) ?: ( $email ?: (string) $user->user_login );
		$payload = array(
			'id'          => (string) $user->ID,
			'external_id' => (string) $user->ID,
			'email'       => $email,
			'name'        => $display,
			'display'     => $display,
		);
		if ( ALAS_Settings::api_key() ) {
			$signed         = ALAS_Client::sign_customer_identity( $payload['external_id'], $email );
			$payload['exp'] = $signed['exp'];
			$payload['sig'] = $signed['sig'];
		}
		return $payload;
	}

	/**
	 * Names the product list the current page shows so tile clicks (select_item) can say
	 * which list they came from - "Product selected" alone leaves the agent asking
	 * "from which list?".
	 */
	private static function list_context() {
		if ( function_exists( 'is_product_category' ) && ( is_product_category() || is_product_tag() ) ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				return array( 'id' => (string) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug );
			}
		}
		// is_search BEFORE is_shop: a product search is also a product archive, so
		// is_shop() is true there too and would mislabel the list as "Shop".
		if ( is_search() ) {
			return array( 'id' => '', 'name' => 'Search results', 'slug' => '' );
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return array( 'id' => '', 'name' => get_the_title( wc_get_page_id( 'shop' ) ) ?: 'Products', 'slug' => '' );
		}
		return new stdClass();
	}

	/** Prints the async chat-widget loader tag (same embed contract as the b2c storefront). */
	public static function print_widget_embed() {
		if ( is_admin() || ! ALAS_Settings::is_widget_configured() ) {
			return;
		}
		$src    = ALAS_Settings::public_base_url() . '/widget/v1/chat.js';
		$accent = ALAS_Settings::widget_accent();
		$logo   = ALAS_Settings::widget_logo_url();
		echo '<script async src="' . esc_url( $src ) . '" data-las-site="' . esc_attr( ALAS_Settings::site_public_key() ) . '"'
			. ( $accent ? ' data-las-accent="' . esc_attr( $accent ) . '"' : '' )
			. ( $logo ? ' data-las-logo="' . esc_url( $logo ) . '"' : '' )
			. '></script>' . "\n";
	}
}
