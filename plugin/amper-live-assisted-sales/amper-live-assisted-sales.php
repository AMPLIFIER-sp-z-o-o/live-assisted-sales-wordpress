<?php
/**
 * Plugin Name: AMPER Live Assisted Sales
 * Plugin URI: https://live-assisted-sales.com/
 * Description: Connects your WooCommerce store to the AMPER Live Assisted Sales platform: real-time visitor tracking (GA4 event taxonomy), GDPR consent banner, live chat widget with buy-from-chat and AI assistance.
 * Version: 1.0.1
 * Author: AMPER
 * Author URI: https://live-assisted-sales.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: amper-las
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AMPER_LAS_VERSION', '1.0.1' );
define( 'AMPER_LAS_PLUGIN_FILE', __FILE__ );
define( 'AMPER_LAS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AMPER_LAS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AMPER_LAS_PLUGIN_DIR . 'includes/class-alas-settings.php';
require_once AMPER_LAS_PLUGIN_DIR . 'includes/class-alas-client.php';
require_once AMPER_LAS_PLUGIN_DIR . 'includes/class-alas-consent.php';
require_once AMPER_LAS_PLUGIN_DIR . 'includes/class-alas-payloads.php';
require_once AMPER_LAS_PLUGIN_DIR . 'includes/class-alas-outbox.php';
require_once AMPER_LAS_PLUGIN_DIR . 'includes/class-alas-dispatch.php';
require_once AMPER_LAS_PLUGIN_DIR . 'includes/class-alas-tracking.php';
require_once AMPER_LAS_PLUGIN_DIR . 'includes/class-alas-rest.php';
require_once AMPER_LAS_PLUGIN_DIR . 'includes/class-alas-frontend.php';

// Declare compatibility with WooCommerce High-Performance Order Storage (custom order tables).
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'amper-las', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'AMPER Live Assisted Sales requires WooCommerce to be installed and active.', 'amper-las' )
				. '</p></div>';
		} );
		return;
	}

	ALAS_Settings::init();
	ALAS_Outbox::init();
	ALAS_Tracking::init();
	ALAS_Rest::init();
	ALAS_Frontend::init();
} );

register_activation_hook( __FILE__, function () {
	ALAS_Outbox::install();
	ALAS_Outbox::schedule_cron();
} );

register_deactivation_hook( __FILE__, function () {
	ALAS_Outbox::unschedule_cron();
} );
