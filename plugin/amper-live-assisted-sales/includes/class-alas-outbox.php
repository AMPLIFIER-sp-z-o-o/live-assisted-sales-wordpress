<?php
/**
 * Transactional outbox for money/truth events (add_to_cart, remove_from_cart,
 * begin_checkout, purchase): rows survive LAS downtime and a WP-Cron relay retries
 * anything the fast path could not confirm, up to OUTBOX_MAX_ATTEMPTS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALAS_Outbox {

	const CRON_HOOK           = 'amper_las_relay_outbox';
	const CRON_INTERVAL       = 'amper_las_minutely';
	const OUTBOX_MAX_ATTEMPTS = 8;
	const RELAY_BATCH         = 200;

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'amper_las_outbox';
	}

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_interval' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'relay_pending' ) );
		// Self-heal: activation may predate an update that added the cron; make sure it exists.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule_cron();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_type VARCHAR(64) NOT NULL DEFAULT '',
				payload LONGTEXT NOT NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'pending',
				attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				last_error TEXT NULL,
				created_at DATETIME NOT NULL,
				sent_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY status_created (status, created_at)
			) {$charset};"
		);
	}

	public static function register_cron_interval( $schedules ) {
		$schedules[ self::CRON_INTERVAL ] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (AMPER LAS outbox relay)', 'amper-las' ),
		);
		return $schedules;
	}

	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public static function pending_count() {
		global $wpdb;
		$table = self::table_name();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
	}

	/**
	 * Persists one durable event and returns the row id (0 on failure - the caller then
	 * degrades to fire-and-forget rather than dropping the event silently).
	 */
	public static function enqueue( array $payload ) {
		global $wpdb;
		$ok = $wpdb->insert(
			self::table_name(),
			array(
				'event_type' => substr( (string) ( $payload['event_type'] ?? '' ), 0, 64 ),
				'payload'    => wp_json_encode( $payload ),
				'status'     => 'pending',
				'attempts'   => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Attempts confirmed delivery of one pending row and updates its state. */
	public static function deliver_row( $row_id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'pending'", $row_id ) );
		if ( ! $row ) {
			return false;
		}
		if ( ! ALAS_Settings::is_configured() ) {
			// Unconfigured target keeps its rows pending without burning attempts: re-adding
			// the key later still delivers the backlog instead of dead-lettering it.
			return false;
		}
		$payload = json_decode( (string) $row->payload, true );
		$sent    = is_array( $payload ) ? ALAS_Client::send_event( $payload, true ) : false;

		$attempts = (int) $row->attempts + 1;
		$update   = array( 'attempts' => $attempts );
		if ( $sent ) {
			$update['status']     = 'sent';
			$update['sent_at']    = gmdate( 'Y-m-d H:i:s' );
			$update['last_error'] = '';
		} else {
			$update['last_error'] = 'delivery failed';
			if ( $attempts >= self::OUTBOX_MAX_ATTEMPTS ) {
				$update['status'] = 'failed';
			}
		}
		$wpdb->update( $table, $update, array( 'id' => (int) $row->id ) );
		return $sent;
	}

	/** Cron entry point: delivers pending rows oldest-first. */
	public static function relay_pending() {
		global $wpdb;
		$table = self::table_name();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d", self::RELAY_BATCH )
		);
		$delivered = 0;
		foreach ( $ids as $id ) {
			if ( self::deliver_row( (int) $id ) ) {
				$delivered++;
			}
		}
		return $delivered;
	}
}
