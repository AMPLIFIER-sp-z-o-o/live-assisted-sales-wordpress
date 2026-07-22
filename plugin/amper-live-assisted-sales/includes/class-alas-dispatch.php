<?php
/**
 * Central event dispatch. Money/truth events whose loss would corrupt analytics or
 * ML labels go through the durable outbox (with an immediate delivery attempt at
 * request shutdown, after the response has been flushed to the shopper); everything
 * else keeps the cheap fire-and-forget fast path.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALAS_Dispatch {

	const DURABLE_EVENT_TYPES = array( 'add_to_cart', 'remove_from_cart', 'begin_checkout', 'purchase' );

	/** Outbox row ids created during this request, delivered on shutdown. */
	private static $pending_fast_path = array();
	private static $shutdown_hooked   = false;

	/**
	 * Builds the envelope for $event_type and queues it for delivery.
	 *
	 * @return bool Whether the event was accepted for delivery.
	 */
	public static function dispatch( $event_type, array $data = array() ) {
		if ( ! in_array( $event_type, ALAS_Payloads::SUPPORTED_EVENT_TYPES, true ) ) {
			return false;
		}
		if ( ! ALAS_Settings::is_configured() ) {
			return false;
		}
		$payload = ALAS_Payloads::build_envelope( $event_type, $data );
		return self::deliver( $payload );
	}

	/** Queues an already-built envelope (used by the browser-events proxy). */
	public static function deliver( array $payload ) {
		$event_type = (string) ( $payload['event_type'] ?? '' );
		if ( in_array( $event_type, self::DURABLE_EVENT_TYPES, true ) ) {
			$row_id = ALAS_Outbox::enqueue( $payload );
			if ( ! $row_id ) {
				// Outbox write failed - degrade to fire-and-forget rather than dropping silently.
				return ALAS_Client::send_event( $payload, false );
			}
			self::$pending_fast_path[] = $row_id;
			self::hook_shutdown();
			return true;
		}
		return ALAS_Client::send_event( $payload, false );
	}

	private static function hook_shutdown() {
		if ( self::$shutdown_hooked ) {
			return;
		}
		self::$shutdown_hooked = true;
		add_action( 'shutdown', array( __CLASS__, 'flush_fast_path' ), 100 );
	}

	/**
	 * Immediate confirmed delivery of this request's outbox rows, run at shutdown so
	 * the (blocking) HTTP call happens after the page/AJAX response was generated.
	 * Anything unconfirmed here is retried by the cron relay.
	 */
	public static function flush_fast_path() {
		$row_ids                 = self::$pending_fast_path;
		self::$pending_fast_path = array();
		if ( ! $row_ids ) {
			return;
		}
		// Push the response out to the client before the blocking sends, when possible.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		foreach ( $row_ids as $row_id ) {
			ALAS_Outbox::deliver_row( $row_id );
		}
	}
}
