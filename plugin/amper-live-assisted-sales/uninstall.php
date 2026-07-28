<?php
/**
 * Uninstall cleanup: removes plugin options and the outbox table.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = array(
	'amper_las_enabled',
	'amper_las_base_url',
	'amper_las_public_base_url',
	'amper_las_api_key',
	'amper_las_site_public_key',
	'amper_las_widget_accent',
	'amper_las_widget_logo',
	'amper_las_last_test_status',
	'amper_las_last_test_message',
	'amper_las_last_test_at',
	'amper_las_fast_updates',
	'amper_las_just_activated',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

delete_site_transient( 'amper_las_remote_version' );
delete_site_transient( 'amper_las_connect_handshake' );

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}amper_las_outbox" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

wp_clear_scheduled_hook( 'amper_las_relay_outbox' );
wp_clear_scheduled_hook( 'amper_las_check_update' );
