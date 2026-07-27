<?php
/**
 * Plugin settings: option accessors, WooCommerce-submenu settings page and the
 * AJAX connection test that fetches the store's public widget key from LAS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALAS_Settings {

	const OPTION_ENABLED         = 'amper_las_enabled';
	const OPTION_BASE_URL        = 'amper_las_base_url';
	const OPTION_PUBLIC_BASE_URL = 'amper_las_public_base_url';
	const OPTION_API_KEY         = 'amper_las_api_key';
	const OPTION_SITE_PUBLIC_KEY = 'amper_las_site_public_key';
	const OPTION_WIDGET_ACCENT   = 'amper_las_widget_accent';
	const OPTION_WIDGET_LOGO     = 'amper_las_widget_logo';
	const OPTION_TEST_STATUS     = 'amper_las_last_test_status';
	const OPTION_TEST_MESSAGE    = 'amper_las_last_test_message';
	const OPTION_TEST_AT         = 'amper_las_last_test_at';
	const OPTION_FAST_UPDATES    = 'amper_las_fast_updates';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_amper_las_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
	}

	// ---- Accessors --------------------------------------------------------

	public static function is_enabled() {
		return get_option( self::OPTION_ENABLED, 'yes' ) === 'yes';
	}

	/** Base URL the SERVER uses to reach LAS (may be an internal address, e.g. inside Docker). */
	public static function base_url() {
		return untrailingslashit( trim( (string) get_option( self::OPTION_BASE_URL, '' ) ) );
	}

	/** Base URL the BROWSER uses (widget script/iframe). Falls back to the server base URL. */
	public static function public_base_url() {
		$url = untrailingslashit( trim( (string) get_option( self::OPTION_PUBLIC_BASE_URL, '' ) ) );
		return $url ? $url : self::base_url();
	}

	public static function api_key() {
		return trim( (string) get_option( self::OPTION_API_KEY, '' ) );
	}

	public static function site_public_key() {
		return trim( (string) get_option( self::OPTION_SITE_PUBLIC_KEY, '' ) );
	}

	public static function widget_accent() {
		return trim( (string) get_option( self::OPTION_WIDGET_ACCENT, '' ) );
	}

	/** Widget logo URL: explicit option, else the theme's custom logo, else "". */
	public static function widget_logo_url() {
		$explicit = trim( (string) get_option( self::OPTION_WIDGET_LOGO, '' ) );
		if ( $explicit ) {
			return $explicit;
		}
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$url = wp_get_attachment_image_url( $logo_id, 'medium' );
			if ( $url ) {
				return $url;
			}
		}
		return '';
	}

	/** Configured = events can be sent (server URL + API key present and integration enabled). */
	public static function is_configured() {
		return self::is_enabled() && self::base_url() !== '' && self::api_key() !== '';
	}

	/** Widget configured = the chat widget can be embedded (public key already fetched). */
	public static function is_widget_configured() {
		return self::is_configured() && self::site_public_key() !== '';
	}

	public static function record_test_result( $status, $message ) {
		update_option( self::OPTION_TEST_STATUS, $status, false );
		update_option( self::OPTION_TEST_MESSAGE, $message, false );
		update_option( self::OPTION_TEST_AT, time(), false );
	}

	// ---- Admin page -------------------------------------------------------

	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Live Assisted Sales', 'amper-las' ),
			__( 'Live Assisted Sales', 'amper-las' ),
			'manage_woocommerce',
			'amper-las',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		$text = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' );
		register_setting( 'amper_las', self::OPTION_ENABLED, $text );
		register_setting( 'amper_las', self::OPTION_BASE_URL, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( 'amper_las', self::OPTION_PUBLIC_BASE_URL, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( 'amper_las', self::OPTION_API_KEY, $text );
		register_setting( 'amper_las', self::OPTION_WIDGET_ACCENT, $text );
		register_setting( 'amper_las', self::OPTION_WIDGET_LOGO, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( 'amper_las', self::OPTION_FAST_UPDATES, $text );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$status     = get_option( self::OPTION_TEST_STATUS, '' );
		$message    = get_option( self::OPTION_TEST_MESSAGE, '' );
		$tested_at  = (int) get_option( self::OPTION_TEST_AT, 0 );
		$public_key = self::site_public_key();
		$pending    = ALAS_Outbox::pending_count();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AMPER Live Assisted Sales', 'amper-las' ); ?></h1>
			<p><?php esc_html_e( 'Connect your store to the AMPER Live Assisted Sales console: live visitor activity, purchase-intent scoring and a chat widget with AI assistance.', 'amper-las' ); ?></p>

			<?php if ( $message ) : ?>
				<div class="notice <?php echo $status === 'success' ? 'notice-success' : 'notice-error'; ?> inline">
					<p>
						<?php echo esc_html( $message ); ?>
						<?php if ( $tested_at ) : ?>
							<em>(<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $tested_at ) ); ?>)</em>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'amper_las' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Integration enabled', 'amper-las' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_ENABLED ); ?>" value="yes" <?php checked( self::is_enabled() ); ?> />
								<?php esc_html_e( 'Send events to Live Assisted Sales and show the chat widget', 'amper-las' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="amper_las_base_url"><?php esc_html_e( 'LAS platform address', 'amper-las' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="amper_las_base_url" name="<?php echo esc_attr( self::OPTION_BASE_URL ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_BASE_URL, '' ) ); ?>" placeholder="https://live-assisted-sales.com" />
							<p class="description"><?php esc_html_e( 'The AMPER Live Assisted Sales platform address, e.g. https://live-assisted-sales.com.', 'amper-las' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="amper_las_public_base_url"><?php esc_html_e( 'Public widget address (advanced)', 'amper-las' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="amper_las_public_base_url" name="<?php echo esc_attr( self::OPTION_PUBLIC_BASE_URL ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_PUBLIC_BASE_URL, '' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Only needed when your server reaches LAS through a different address than your visitors\' browsers (e.g. Docker or an internal network). Leave empty to use the platform address above.', 'amper-las' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="amper_las_api_key"><?php esc_html_e( 'Store API key', 'amper-las' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="amper_las_api_key" name="<?php echo esc_attr( self::OPTION_API_KEY ); ?>" value="<?php echo esc_attr( self::api_key() ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Paste the key from your store\'s page in the AMPER LAS console.', 'amper-las' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="amper_las_widget_accent"><?php esc_html_e( 'Chat widget accent color', 'amper-las' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="amper_las_widget_accent" name="<?php echo esc_attr( self::OPTION_WIDGET_ACCENT ); ?>" value="<?php echo esc_attr( self::widget_accent() ); ?>" placeholder="#2563eb" />
							<p class="description"><?php esc_html_e( 'Optional. A CSS color (e.g. #2563eb) for the chat bubble; leave empty for the default.', 'amper-las' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="amper_las_widget_logo"><?php esc_html_e( 'Chat widget logo URL', 'amper-las' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="amper_las_widget_logo" name="<?php echo esc_attr( self::OPTION_WIDGET_LOGO ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_WIDGET_LOGO, '' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional. Leave empty to use your theme\'s logo.', 'amper-las' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Updates', 'amper-las' ); ?></th>
						<td>
							<p class="description" style="margin-bottom:6px;">
								<?php
								/* translators: %s: currently installed plugin version. */
								echo esc_html( sprintf( __( 'Version %s. The plugin keeps itself up to date - there is nothing to click.', 'amper-las' ), AMPER_LAS_VERSION ) );
								?>
							</p>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_FAST_UPDATES ); ?>" value="yes" <?php checked( ALAS_Updater::fast_checks_enabled() ); ?> />
								<?php esc_html_e( 'Check for updates every few minutes (test stores only)', 'amper-las' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default: updates are picked up within about half a day, the same rhythm WordPress uses for every other plugin. Turn this on only on a staging or demo store, where you want a change to arrive right after it is published.', 'amper-las' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connection status', 'amper-las' ); ?></th>
						<td>
							<?php if ( $public_key ) : ?>
								<p><span style="color:#00a32a;font-weight:600;">&#9679;</span> <?php esc_html_e( 'Connected - the chat widget is active on your store.', 'amper-las' ); ?></p>
							<?php else : ?>
								<p><span style="color:#d63638;font-weight:600;">&#9679;</span> <?php esc_html_e( 'Not connected yet - save your settings, then run the connection test.', 'amper-las' ); ?></p>
							<?php endif; ?>
							<button type="button" class="button" id="amper-las-test-btn"><?php esc_html_e( 'Test connection', 'amper-las' ); ?></button>
							<span id="amper-las-test-result" style="margin-left:8px;"></span>
							<?php if ( $pending > 0 ) : ?>
								<p class="description">
									<?php
									/* translators: %d: number of queued events. */
									echo esc_html( sprintf( _n( '%d event is waiting to be delivered to LAS.', '%d events are waiting to be delivered to LAS.', $pending, 'amper-las' ), $pending ) );
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<script>
		(function () {
			var btn = document.getElementById("amper-las-test-btn");
			if (!btn) return;
			btn.addEventListener("click", function () {
				var out = document.getElementById("amper-las-test-result");
				out.textContent = <?php echo wp_json_encode( __( 'Testing…', 'amper-las' ) ); ?>;
				var body = new URLSearchParams();
				body.set("action", "amper_las_test_connection");
				body.set("_wpnonce", <?php echo wp_json_encode( wp_create_nonce( 'amper_las_test' ) ); ?>);
				fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: "POST", credentials: "same-origin", body: body })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						out.textContent = (data && data.data && data.data.message) || "";
						out.style.color = data && data.success ? "#00a32a" : "#d63638";
						if (data && data.success) { window.setTimeout(function () { window.location.reload(); }, 900); }
					})
					.catch(function () { out.textContent = <?php echo wp_json_encode( __( 'Request failed.', 'amper-las' ) ); ?>; out.style.color = "#d63638"; });
			});
		})();
		</script>
		<?php
	}

	// ---- Connection test --------------------------------------------------

	public static function ajax_test_connection() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'amper_las_test', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'amper-las' ) ), 403 );
		}
		$result = self::run_connection_test();
		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/**
	 * Runs the LAS connection test and stores its outcome. Mirrors the b2c reference behaviour:
	 * the previously fetched public key is forgotten ONLY when LAS explicitly rejects the
	 * credentials (401/403) - a transient network failure must not hide a working chat widget.
	 */
	public static function run_connection_test() {
		if ( ! self::base_url() || ! self::api_key() ) {
			update_option( self::OPTION_SITE_PUBLIC_KEY, '', false );
			$message = ! self::base_url()
				? __( 'The AMPER LAS platform address is not set.', 'amper-las' )
				: __( 'A store API key is required. Paste the key from your store\'s page in the AMPER LAS console.', 'amper-las' );
			self::record_test_result( 'failed', $message );
			return array( 'ok' => false, 'message' => $message );
		}

		$response = ALAS_Client::test_connection();

		if ( is_wp_error( $response ) ) {
			$reason = strtolower( $response->get_error_message() );
			if ( strpos( $reason, 'could not resolve' ) !== false || strpos( $reason, 'name or service' ) !== false || strpos( $reason, 'getaddrinfo' ) !== false ) {
				$message = __( 'We couldn\'t find that server. Check the AMPER LAS platform address - it is the AMPER platform address, not your store\'s own website.', 'amper-las' );
			} elseif ( strpos( $reason, 'refused' ) !== false || strpos( $reason, 'connection reset' ) !== false ) {
				$message = __( 'The AMPER LAS server address was reached but refused the connection. Confirm the address and port are correct and that the LAS platform is running.', 'amper-las' );
			} else {
				/* translators: %s: error detail. */
				$message = sprintf( __( 'LAS connection failed: %s', 'amper-las' ), $response->get_error_message() );
			}
			self::record_test_result( 'failed', $message );
			return array( 'ok' => false, 'message' => $message );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$detail = is_array( $data ) ? (string) ( $data['detail'] ?? '' ) : '';
			if ( $detail === 'Invalid store API key.' ) {
				$detail = __( 'Invalid store API key.', 'amper-las' );
			}
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'LAS rejected the API key (HTTP %d).', 'amper-las' ), $code );
			if ( $detail ) {
				$message .= ' ' . $detail;
			}
			if ( in_array( $code, array( 401, 403 ), true ) ) {
				update_option( self::OPTION_SITE_PUBLIC_KEY, '', false );
			}
			self::record_test_result( 'failed', $message );
			return array( 'ok' => false, 'message' => $message );
		}

		$store      = is_array( $data ) && isset( $data['store'] ) && is_array( $data['store'] ) ? $data['store'] : array();
		$store_name = (string) ( $store['display_name'] ?? '' );
		$public_key = trim( (string) ( $store['public_key'] ?? '' ) );
		update_option( self::OPTION_SITE_PUBLIC_KEY, $public_key, false );
		/* translators: %s: store name. */
		$message = sprintf( __( 'Connection to %s works correctly.', 'amper-las' ), $store_name ? $store_name : __( 'your store', 'amper-las' ) );
		self::record_test_result( 'success', $message );
		return array( 'ok' => true, 'message' => $message );
	}
}
