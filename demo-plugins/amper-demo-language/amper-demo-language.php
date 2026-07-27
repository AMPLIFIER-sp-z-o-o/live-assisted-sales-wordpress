<?php
/**
 * Plugin Name: AMPER Demo Language Switcher
 * Description: Serves the demo store in Polish or English - the visitor's browser decides by default, a PL/EN switcher in the Storefront header lets them override it. No content duplication: WordPress, WooCommerce and the theme already ship both translations.
 * Version: 1.0.1
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: AMPLIFIER
 * License: GPL-2.0-or-later
 * Text Domain: amper-demo-language
 *
 * Why a custom plugin instead of Polylang/TranslatePress: the demo store needs a bilingual
 * INTERFACE, not translated content (the catalog is WooCommerce's own English sample data).
 * Switching the locale per visitor gets that from the .mo files WordPress/WooCommerce/Storefront
 * already ship, with no duplicated products and no paid add-on.
 */

defined( 'ABSPATH' ) || exit;

class Amper_Demo_Language {

	const COOKIE      = 'amper_demo_lang';
	const QUERY_VAR   = 'amper_lang';
	const COOKIE_DAYS = 365;

	/** Supported locales, keyed by the short code used in the cookie and the switcher links. */
	const LOCALES = array(
		'pl' => 'pl_PL',
		'en' => 'en_US',
	);

	public static function boot() {
		// Handled before anything renders: the switcher link sets the cookie and redirects to the
		// clean URL, so ?amper_lang= never reaches analytics or the LAS event payloads.
		add_action( 'init', array( __CLASS__, 'maybe_handle_switch' ), 0 );

		// get_locale() runs the `locale` filter on every call, so filtering it here (before any
		// text domain is loaded) is enough to move the whole front end - theme, WooCommerce,
		// checkout, e-mails - onto the visitor's language.
		add_filter( 'locale', array( __CLASS__, 'filter_locale' ) );

		add_action( 'storefront_header', array( __CLASS__, 'render_switcher' ), 31 );
		add_action( 'wp_head', array( __CLASS__, 'print_styles' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'print_fragment_refresh' ), 20 );
	}

	/** Short code ("pl"/"en") for this visitor: explicit choice first, then the browser. */
	public static function current_code() {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( isset( self::LOCALES[ $cookie ] ) ) {
			return $cookie;
		}
		return self::browser_code();
	}

	/**
	 * Highest-priority language the browser asked for, mapped onto what the store speaks.
	 * Anything that is not Polish gets English - the same rule the LAS chat widget applies.
	 */
	public static function browser_code() {
		$header = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? (string) wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) : '';
		if ( '' === $header ) {
			return 'pl';
		}
		$best  = 'en';
		$score = -1.0;
		foreach ( explode( ',', $header ) as $chunk ) {
			$parts = explode( ';', trim( $chunk ) );
			$tag   = strtolower( trim( $parts[0] ) );
			$q     = 1.0;
			if ( isset( $parts[1] ) && preg_match( '/q\s*=\s*([0-9.]+)/', $parts[1], $m ) ) {
				$q = (float) $m[1];
			}
			if ( '' === $tag || $q <= $score ) {
				continue;
			}
			$score = $q;
			$best  = ( 0 === strpos( $tag, 'pl' ) ) ? 'pl' : 'en';
		}
		return $best;
	}

	public static function filter_locale( $locale ) {
		// wp-admin stays in the site language; wc-ajax/REST calls from the storefront (cart
		// fragments, checkout updates) must follow the visitor, so they are NOT excluded.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $locale;
		}
		$code = self::current_code();
		return isset( self::LOCALES[ $code ] ) ? self::LOCALES[ $code ] : $locale;
	}

	public static function maybe_handle_switch() {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		$code = sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		if ( isset( self::LOCALES[ $code ] ) ) {
			setcookie(
				self::COOKIE,
				$code,
				array(
					'expires'  => time() + ( self::COOKIE_DAYS * DAY_IN_SECONDS ),
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
			$_COOKIE[ self::COOKIE ] = $code;
		}
		$target = remove_query_arg( self::QUERY_VAR );
		if ( ! $target ) {
			$target = home_url( '/' );
		}
		wp_safe_redirect( $target, 302 );
		exit;
	}

	public static function switch_url( $code ) {
		$current = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '' )
			. ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' );
		return esc_url( add_query_arg( self::QUERY_VAR, $code, $current ) );
	}

	public static function render_switcher() {
		$active = self::current_code();
		$labels = array(
			'pl' => array( 'label' => 'PL', 'title' => 'Polski' ),
			'en' => array( 'label' => 'EN', 'title' => 'English' ),
		);
		echo '<nav class="amper-lang-switch" aria-label="' . esc_attr__( 'Language', 'amper-demo-language' ) . '">';
		foreach ( $labels as $code => $meta ) {
			$is_active = ( $code === $active );
			printf(
				'<a class="amper-lang-switch__link%1$s" href="%2$s" hreflang="%3$s" lang="%3$s" title="%4$s"%5$s>%6$s</a>',
				$is_active ? ' is-active' : '',
				self::switch_url( $code ),
				esc_attr( $code ),
				esc_attr( $meta['title'] ),
				$is_active ? ' aria-current="true"' : '',
				esc_html( $meta['label'] )
			);
		}
		echo '</nav>';
	}

	/**
	 * WooCommerce caches the mini-cart markup in sessionStorage and only refreshes it when the cart
	 * changes, so right after a language switch the header cart would keep the previous language.
	 * Drop the cache and ask WooCommerce for fresh fragments whenever the language differs from the
	 * one the cached markup was rendered in.
	 */
	public static function print_fragment_refresh() {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return;
		}
		$code = esc_js( self::current_code() );
		echo '<script id="amper-lang-switch-fragments">(function(){
try{
  var KEY="amper_demo_lang_rendered", lang="' . $code . '";
  if(window.sessionStorage && sessionStorage.getItem(KEY)===lang){return;}
  if(window.sessionStorage){
    Object.keys(sessionStorage).forEach(function(k){ if(k.indexOf("wc_fragments")===0){ sessionStorage.removeItem(k); } });
    sessionStorage.setItem(KEY, lang);
  }
  if(window.jQuery){ jQuery(function($){ $(document.body).trigger("wc_fragment_refresh"); }); }
}catch(e){}
})();</script>';
	}

	public static function print_styles() {
		// Colours come from Storefront's own header palette; 44px hit targets and a visible focus
		// ring keep the control usable on touch and by keyboard.
		echo '<style id="amper-lang-switch-css">
.amper-lang-switch{display:flex;gap:.25em;align-items:center;float:right;clear:none;margin:0 0 1em 1em;font-size:.875em}
.amper-lang-switch__link{display:inline-flex;align-items:center;justify-content:center;min-width:2.75em;min-height:2.75em;padding:.25em .6em;border:1px solid rgba(0,0,0,.15);border-radius:4px;text-decoration:none;font-weight:600;line-height:1}
.amper-lang-switch__link:hover{border-color:rgba(0,0,0,.35)}
.amper-lang-switch__link:focus-visible{outline:2px solid currentColor;outline-offset:2px}
.amper-lang-switch__link.is-active{background:rgba(0,0,0,.08);border-color:rgba(0,0,0,.3);cursor:default}
@media (max-width:768px){.amper-lang-switch{float:none;margin:.5em 0 1em}}
</style>';
	}
}

Amper_Demo_Language::boot();
