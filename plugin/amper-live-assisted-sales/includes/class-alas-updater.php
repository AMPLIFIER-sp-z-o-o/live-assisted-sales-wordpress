<?php
/**
 * Self-updating from the plugin's public GitHub repository.
 *
 * The plugin is not on wordpress.org, so WordPress has nowhere to look for new versions on its own.
 * This class points it at our repo instead: the `Version:` header on `main` is the single source of
 * truth, and a store that runs an older one is offered - and by default installs - the new code the
 * same way any wordpress.org plugin would. No release, no tag, no build step and no CI: pushing to
 * main with a bumped version IS the release.
 *
 * Updates install unattended (see self::force_auto_update). A security fix that waits for every
 * shop owner to notice a badge and click it is a fix that never lands, and this is our code, which
 * merchants do not edit. A store can opt out with:
 *
 *     add_filter( 'amper_las_auto_update', '__return_false' );
 *
 * @see ALAS_Updater::rename_source_dir() for the one WordPress trap this has to work around.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALAS_Updater {

	const REPO             = 'AMPLIFIER-sp-z-o-o/live-assisted-sales-wordpress';
	const BRANCH           = 'main';
	/** Directory inside the repo that actually holds the plugin. */
	const PLUGIN_SUBDIR    = 'plugin/amper-live-assisted-sales';
	const VERSION_CACHE_KEY = 'amper_las_remote_version';
	/** How long a version answer is reused. Short on purpose: this is the delay between pushing a
	 *  fix and a shop having it. GitHub allows 60 unauthenticated calls an hour per IP and this
	 *  costs at most 12 of them. */
	const CACHE_TTL        = 5 * MINUTE_IN_SECONDS;
	const CRON_INTERVAL    = 'amper_las_five_minutes';

	const CRON_HOOK = 'amper_las_check_update';

	public static function init() {
		// WordPress looks for updates twice a day, so a fix pushed at noon could sit unseen on a shop
		// until the evening. That delay IS the product decision here, so it is cut to five minutes:
		// a version bump should reach stores while you are still looking at the push.
		//
		// WP-Cron has no daemon - it rides on page loads - so on a shop with visitors this lands
		// within minutes, and on a dead shop it lands with the first visitor. Nothing to install and
		// nothing for the merchant to do either way.
		add_filter( 'cron_schedules', array( __CLASS__, 'register_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::CRON_HOOK, array( __CLASS__, 'check_and_update_now' ) );
		self::ensure_scheduled();

		// Both hooks on purpose: `pre_set_…` fires only when WordPress refreshes the transient
		// (roughly twice a day), while `site_transient_…` also runs on every read - so the update
		// shows up on the Plugins screen right away instead of after the next refresh.
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'rename_source_dir' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'force_auto_update' ), 10, 2 );
		// A fresh install/update should not wait for the next tick to learn about the one after it.
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush_cache' ) );
	}

	public static function register_interval( $schedules ) {
		$schedules[ self::CRON_INTERVAL ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (AMPER LAS updates)', 'amper-las' ),
		);
		return $schedules;
	}

	/** Keeps the job on the current interval, including for stores upgrading from an older one. */
	private static function ensure_scheduled() {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next ) {
			$event = wp_get_scheduled_event( self::CRON_HOOK );
			if ( $event && self::CRON_INTERVAL === $event->schedule ) {
				return;
			}
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
	}

	public static function flush_cache() {
		delete_site_transient( self::VERSION_CACHE_KEY );
	}

	/**
	 * Every few minutes: look for a newer version and, if there is one, install it there and then.
	 *
	 * Deliberately narrower than core's own unattended pass, which updates everything on the site
	 * that has auto-updates on: this store's other plugins are not ours to hurry along. Only our
	 * own entry is handed to WP_Automatic_Updater, so the install still runs through core's
	 * machinery - its compatibility checks, its rollback when the new version fatals on activation,
	 * its notification mail - and nothing else on the shop is touched.
	 */
	public static function check_and_update_now() {
		self::flush_cache();
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-includes/update.php';
		}
		wp_update_plugins();

		$updates = get_site_transient( 'update_plugins' );
		$item    = is_object( $updates ) && isset( $updates->response[ self::basename() ] )
			? $updates->response[ self::basename() ]
			: null;
		if ( ! $item ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$updater = new WP_Automatic_Updater();
		// Honours the site's own kill switches too (WP_AUTO_UPDATE_CORE, DISALLOW_FILE_MODS,
		// automatic_updater_disabled): a store that forbids unattended updates keeps forbidding them.
		if ( $updater->should_update( 'plugin', $item, WP_PLUGIN_DIR ) ) {
			$updater->update( 'plugin', $item );
		}
	}

	private static function basename() {
		return plugin_basename( AMPER_LAS_PLUGIN_FILE );
	}

	private static function slug() {
		return dirname( self::basename() );
	}

	/** Raw URL of the plugin's main file on the tracked branch - the file carrying `Version:`. */
	private static function remote_header_url() {
		return sprintf(
			'https://raw.githubusercontent.com/%s/%s/%s/amper-live-assisted-sales.php',
			self::REPO,
			self::BRANCH,
			self::PLUGIN_SUBDIR
		);
	}

	private static function package_url() {
		return sprintf( 'https://github.com/%s/archive/refs/heads/%s.zip', self::REPO, self::BRANCH );
	}

	/**
	 * The plugin headers currently on the branch: version plus the compatibility requirements.
	 *
	 * Only the header block is fetched (a few hundred bytes), never the archive: this runs on
	 * ordinary admin page loads and must not cost a megabyte each time.
	 *
	 * `requires` / `requires_php` / `requires_plugins` are read too and handed to WordPress with the
	 * update. They are what stops core from pushing a build onto a store that cannot run it - an
	 * unattended update is exactly where "requires PHP 8.0" has to be enforced by the platform
	 * rather than noticed by a human.
	 *
	 * @return array{version:string, requires:string, requires_php:string, requires_plugins:string}
	 */
	public static function remote_headers( $force = false ) {
		$empty = array( 'version' => '', 'requires' => '', 'requires_php' => '', 'requires_plugins' => '' );
		if ( ! $force ) {
			$cached = get_site_transient( self::VERSION_CACHE_KEY );
			if ( is_array( $cached ) ) {
				return array_merge( $empty, $cached );
			}
		}
		$response = wp_remote_get( self::remote_header_url(), array( 'timeout' => 8 ) );
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			// Cache the miss briefly too, so an outage doesn't turn every admin page into a retry.
			set_site_transient( self::VERSION_CACHE_KEY, $empty, 15 * MINUTE_IN_SECONDS );
			return $empty;
		}
		$body   = (string) wp_remote_retrieve_body( $response );
		$header = static function ( $label ) use ( $body ) {
			return preg_match( '/^\s*\*\s*' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', $body, $m ) ? trim( $m[1] ) : '';
		};
		$headers = array(
			'version'          => $header( 'Version' ),
			'requires'         => $header( 'Requires at least' ),
			'requires_php'     => $header( 'Requires PHP' ),
			'requires_plugins' => $header( 'Requires Plugins' ),
		);
		set_site_transient( self::VERSION_CACHE_KEY, $headers, self::CACHE_TTL );
		return $headers;
	}

	public static function remote_version( $force = false ) {
		return self::remote_headers( $force )['version'];
	}

	/** Tells WordPress an update exists, using the same shape wordpress.org would return. */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		// "Check again" on the Updates screen must mean it: without this the half-day cache would
		// answer with yesterday's version and the button would look broken.
		$forced  = ! empty( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$headers = self::remote_headers( $forced );
		$remote  = $headers['version'];
		if ( ! $remote || ! version_compare( $remote, AMPER_LAS_VERSION, '>' ) ) {
			return $transient;
		}
		$update = (object) array(
			'id'               => 'github.com/' . self::REPO,
			'slug'             => self::slug(),
			'plugin'           => self::basename(),
			'new_version'      => $remote,
			'url'              => 'https://github.com/' . self::REPO,
			'package'          => self::package_url(),
			// Core refuses the update (and, importantly, the UNATTENDED one) when the store does
			// not meet these - that check is the reason they are carried through at all.
			'requires'         => $headers['requires'],
			'requires_php'     => $headers['requires_php'],
			'requires_plugins' => array_filter( array_map( 'trim', explode( ',', $headers['requires_plugins'] ) ) ),
			'icons'            => array(),
			'banners'          => array(),
		);
		$transient->response[ self::basename() ] = $update;
		unset( $transient->no_update[ self::basename() ] );
		return $transient;
	}

	/** "View details" in the plugins list - without this WordPress asks wordpress.org and 404s. */
	public static function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== self::slug() ) {
			return $result;
		}
		$remote = self::remote_version();
		return (object) array(
			'name'          => 'AMPER Live Assisted Sales',
			'slug'          => self::slug(),
			'version'       => $remote ?: AMPER_LAS_VERSION,
			'author'        => '<a href="https://live-assisted-sales.com/">AMPER</a>',
			'homepage'      => 'https://live-assisted-sales.com/',
			'download_link' => self::package_url(),
			'sections'      => array(
				'description' => __( 'Connects your WooCommerce store to the AMPER Live Assisted Sales platform. Updates are delivered straight from the plugin\'s repository.', 'amper-las' ),
			),
		);
	}

	/**
	 * Point the installer at the plugin folder INSIDE the downloaded archive.
	 *
	 * GitHub's branch archive unpacks to `live-assisted-sales-wordpress-main/`, and that name is what
	 * WordPress would install under - leaving the real plugin untouched and a second, half-broken copy
	 * of it beside the original. The plugin actually lives in a subdirectory of that archive, so the
	 * source is re-pointed there and renamed to the slug WordPress already knows.
	 */
	public static function rename_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== self::basename() ) {
			return $source;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $source;
		}
		$candidate = trailingslashit( $source ) . self::PLUGIN_SUBDIR;
		if ( ! $wp_filesystem->is_dir( $candidate ) ) {
			return $source;
		}
		$target = trailingslashit( $remote_source ) . self::slug();
		if ( $wp_filesystem->is_dir( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}
		if ( ! $wp_filesystem->move( $candidate, $target ) ) {
			return new WP_Error( 'amper_las_source_move_failed', __( 'Could not prepare the update package.', 'amper-las' ) );
		}
		return trailingslashit( $target );
	}

	/**
	 * Install our updates unattended, unless the store opts out.
	 *
	 * Everything else about this integration runs without the merchant doing anything; an update
	 * that sits behind a button they have to notice would be the one manual step in the chain, and
	 * the one that decides whether a security fix reaches their shop this week or never.
	 */
	public static function force_auto_update( $update, $item ) {
		if ( ! empty( $item->plugin ) && $item->plugin === self::basename() ) {
			/**
			 * Filter: set to false to go back to clicking "Update" by hand on this store.
			 *
			 * @param bool $enabled Whether AMPER LAS updates itself automatically.
			 */
			return (bool) apply_filters( 'amper_las_auto_update', true );
		}
		return $update;
	}
}
