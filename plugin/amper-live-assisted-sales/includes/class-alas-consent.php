<?php
/**
 * GDPR/ePrivacy consent support: server-side consent-region detection (EU opt-in vs
 * opt-out markets) and the consent-banner copy in the store's display language.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALAS_Consent {

	const CONSENT_COOKIE = 'las_consent';

	// Country codes where prior consent is required before behavioural profiling
	// (EU-27 + EEA + UK + CH). Mirrors the b2c reference integration.
	const CONSENT_REQUIRED_COUNTRIES = array(
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
		'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
		'IS', 'LI', 'NO',
		'GB', 'CH',
	);

	// ISO country headers injected by common CDNs / load balancers.
	const GEO_HEADERS = array(
		'HTTP_CF_IPCOUNTRY',
		'HTTP_CLOUDFRONT_VIEWER_COUNTRY',
		'HTTP_X_VERCEL_IP_COUNTRY',
		'HTTP_X_APPENGINE_COUNTRY',
		'HTTP_X_GEO_COUNTRY',
		'HTTP_X_COUNTRY_CODE',
	);

	/** Best-effort ISO-3166 alpha-2 country for the request; "" when unknown. */
	public static function country_code() {
		foreach ( self::GEO_HEADERS as $key ) {
			$code = strtoupper( trim( (string) ( $_SERVER[ $key ] ?? '' ) ) );
			if ( strlen( $code ) === 2 && ctype_alpha( $code ) && ! in_array( $code, array( 'XX', 'T1' ), true ) ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * Consent regime for this visitor: "eu" (prior consent required), "noneu" (opt-out
	 * allowed) or "" (unknown - the browser tracker falls back to a timezone heuristic).
	 */
	public static function region() {
		$code = self::country_code();
		if ( ! $code ) {
			return '';
		}
		return in_array( $code, self::CONSENT_REQUIRED_COUNTRIES, true ) ? 'eu' : 'noneu';
	}

	/**
	 * The shopper's consent choice from the tracker's banner cookie:
	 * true / false / null (not answered yet).
	 */
	public static function consent_value() {
		$raw = (string) ( $_COOKIE[ self::CONSENT_COOKIE ] ?? '' );
		if ( 'true' === $raw ) {
			return true;
		}
		if ( 'false' === $raw ) {
			return false;
		}
		return null;
	}

	/**
	 * Whether raw PII (email, IP) may be forwarded to LAS for this request.
	 * EU/EEA/UK: only once the shopper explicitly consented (opt-in).
	 * Elsewhere: allowed unless they explicitly opted out.
	 */
	public static function pii_forwarding_allowed() {
		$consent = self::consent_value();
		if ( false === $consent ) {
			return false;
		}
		if ( 'eu' === self::region() ) {
			return true === $consent;
		}
		return true;
	}

	/**
	 * Consent-banner copy in the store's display language (English source, Polish
	 * translation for pl_* locales), consumed by the browser tracker.
	 */
	public static function texts() {
		$pl = str_starts_with( (string) get_locale(), 'pl' );
		if ( $pl ) {
			return array(
				'aria_label'      => 'Zgoda na pliki cookies i przetwarzanie danych',
				'title'           => 'Zależy nam na Twojej prywatności',
				'body'            => 'Ten sklep używa plików cookies oraz danych o Twoich działaniach, aby działać poprawnie, pomagać Ci na żywo i trafniej dobierać produkty.',
				'accept_all'      => 'Zaakceptuj wszystkie',
				'only_necessary'  => 'Tylko niezbędne',
				'preferences'     => 'Preferencje',
				'prefs_title'     => 'Preferencje prywatności',
				'prefs_intro'     => 'Wybierz, na co się zgadzasz. Możesz to zmienić w dowolnym momencie.',
				'close'           => 'Zamknij',
				'necessary_title' => 'Niezbędne',
				'necessary_state' => 'Zawsze aktywne',
				'necessary_desc'  => 'Konieczne, aby sklep działał i był bezpieczny. Nie budują profilu ani Cię nie identyfikują.',
				'analytics_title' => 'Analiza i personalizacja',
				'analytics_desc'  => 'Pozwalają zrozumieć Twoją wizytę, aby sklep mógł pomagać Ci na żywo i trafniej dobierać produkty.',
				'save'            => 'Zapisz wybór',
				'cancel'          => 'Anuluj',
			);
		}
		return array(
			'aria_label'      => 'Cookie and data-processing consent',
			'title'           => 'We value your privacy',
			'body'            => 'This store uses cookies and data about your activity to work properly, help you in real time, and recommend products more accurately.',
			'accept_all'      => 'Accept all',
			'only_necessary'  => 'Only necessary',
			'preferences'     => 'Preferences',
			'prefs_title'     => 'Privacy preferences',
			'prefs_intro'     => "Choose what you're comfortable with. You can change this at any time.",
			'close'           => 'Close',
			'necessary_title' => 'Strictly necessary',
			'necessary_state' => 'Always on',
			'necessary_desc'  => "Required for the store to work and stay secure. Doesn't build a profile or identify you.",
			'analytics_title' => 'Analytics & personalization',
			'analytics_desc'  => 'Lets the store understand your visit so it can help you in real time and recommend products more accurately.',
			'save'            => 'Save choices',
			'cancel'          => 'Cancel',
		);
	}
}
