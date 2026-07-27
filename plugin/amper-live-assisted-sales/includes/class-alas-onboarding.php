<?php
/**
 * Getting a freshly installed store from "activated" to "connected".
 *
 * The plugin does nothing until it holds an API key, and a merchant who activates it is left on the
 * plugins list with no idea that anything else is expected of them. Everything here exists to close
 * that gap: a link where they already are, a notice telling them what is missing, and - in
 * ALAS_Connect - a button that does the rest without them copying anything between two tabs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALAS_Onboarding {

	/** Set on activation so the notice greets a new install rather than every existing one. */
	const OPTION_JUST_ACTIVATED = 'amper_las_just_activated';

	public static function init() {
		add_filter( 'plugin_action_links_' . plugin_basename( AMPER_LAS_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_after_activation' ) );
	}

	/**
	 * Take the merchant to the setup screen once, right after they activate the plugin.
	 *
	 * The settings page lives under the WooCommerce menu, which is correct - it is a WooCommerce
	 * extension - but nothing about activating a plugin tells anyone to go looking there. This
	 * removes the guess entirely.
	 *
	 * Guarded on three things. `activate-multi` means the merchant activated a batch of plugins and
	 * is not thinking about ours - hijacking that would be obnoxious and would strand the other
	 * plugins' own notices. The option is deleted before redirecting, so this can only ever fire
	 * once. And a store that is already connected has nothing to set up.
	 */
	public static function redirect_after_activation() {
		if ( ! get_option( self::OPTION_JUST_ACTIVATED ) ) {
			return;
		}
		delete_option( self::OPTION_JUST_ACTIVATED );
		if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ALAS_Settings::site_public_key() ) {
			return;
		}
		wp_safe_redirect( self::settings_url() );
		exit;
	}

	/** Called from the activation hook. */
	public static function on_activate() {
		add_option( self::OPTION_JUST_ACTIVATED, '1' );
	}

	public static function settings_url() {
		return admin_url( 'admin.php?page=amper-las' );
	}

	/**
	 * "Settings" next to Deactivate on the plugins list.
	 *
	 * This is where the merchant physically is at the moment of activation, and without it the only
	 * route to the settings page is guessing that it lives under the WooCommerce menu.
	 */
	public static function action_links( $links ) {
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::settings_url() ),
			esc_html__( 'Settings', 'amper-live-assisted-sales' )
		);
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * "You are one step away" - shown until the store is actually connected.
	 *
	 * Deliberately not dismissible: an unconnected plugin sends nothing, shows no widget and does
	 * nothing at all, so a dismissed notice would leave a merchant with a plugin they believe is
	 * working. It disappears the moment a connection test succeeds, which is the only thing that
	 * makes it true.
	 */
	public static function setup_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ALAS_Settings::site_public_key() ) {
			delete_option( self::OPTION_JUST_ACTIVATED );
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// Not on our own settings page - the page itself says all of this, in context.
		if ( $screen && 'woocommerce_page_amper-las' === $screen->id ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'AMPER Live Assisted Sales is not connected yet.', 'amper-live-assisted-sales' ); ?></strong>
				<?php esc_html_e( 'Connect your store to start seeing live visitor activity and to show the chat widget.', 'amper-live-assisted-sales' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( self::settings_url() ); ?>" class="button button-primary">
					<?php esc_html_e( 'Finish setup', 'amper-live-assisted-sales' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
