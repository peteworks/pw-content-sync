<?php
/**
 * Settings page for source site URL and Application Password credentials.
 *
 * @package SF_Content_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SF_Sync_Settings {

	private const OPTION_GROUP = 'sf_content_sync_settings';
	private const OPTION_NAME  = 'sf_content_sync_options';
	private const PAGE_SLUG    = 'sf-content-sync';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ], 10, 1 );
		add_action( 'wp_ajax_sf_sync_test_connection', [ self::class, 'ajax_test_connection' ] );
	}

	public static function add_menu(): void {
		add_options_page(
			__( 'Content Sync', 'sf-content-sync' ),
			__( 'Content Sync', 'sf-content-sync' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	public static function register_settings(): void {
		register_setting( self::OPTION_GROUP, self::OPTION_NAME, [
			'type'              => 'array',
			'sanitize_callback' => [ self::class, 'sanitize_options' ],
		] );

		add_settings_section(
			'sf_sync_source',
			__( 'Source site', 'sf-content-sync' ),
			[ self::class, 'render_source_section' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			'source_url',
			__( 'Source site URL', 'sf-content-sync' ),
			[ self::class, 'field_source_url' ],
			self::PAGE_SLUG,
			'sf_sync_source',
			[ 'label_for' => 'sf_sync_source_url' ]
		);

		add_settings_field(
			'source_username',
			__( 'WordPress username', 'sf-content-sync' ),
			[ self::class, 'field_source_username' ],
			self::PAGE_SLUG,
			'sf_sync_source',
			[ 'label_for' => 'sf_sync_source_username' ]
		);

		add_settings_field(
			'source_app_password',
			__( 'Application password', 'sf-content-sync' ),
			[ self::class, 'field_source_app_password' ],
			self::PAGE_SLUG,
			'sf_sync_source',
			[ 'label_for' => 'sf_sync_source_app_password' ]
		);

		add_settings_field(
			'test_connection',
			__( 'Test connection', 'sf-content-sync' ),
			[ self::class, 'field_test_connection' ],
			self::PAGE_SLUG,
			'sf_sync_source'
		);
	}

	public static function sanitize_options( $input ): array {
		if ( ! is_array( $input ) ) {
			return self::get_options();
		}
		$out = [
			'source_url'          => isset( $input['source_url'] ) ? esc_url_raw( trim( $input['source_url'] ), [ 'https' ] ) : '',
			'source_username'     => isset( $input['source_username'] ) ? sanitize_text_field( $input['source_username'] ) : '',
			'source_app_password' => isset( $input['source_app_password'] ) && $input['source_app_password'] !== ''
				? str_replace( ' ', '', sanitize_text_field( $input['source_app_password'] ) )
				: '',
		];
		$current = self::get_options();
		if ( empty( $out['source_app_password'] ) && ! empty( $current['source_app_password'] ) ) {
			$out['source_app_password'] = $current['source_app_password'];
		}
		return $out;
	}

	public static function get_options(): array {
		$opts = get_option( self::OPTION_NAME, [] );
		return wp_parse_args( $opts, [
			'source_url'          => '',
			'source_username'     => '',
			'source_app_password' => '',
		] );
	}

	public static function render_source_section(): void {
		echo '<p>' . esc_html__( 'Configure the WordPress site to pull content from. Use an Application Password for the source site.', 'sf-content-sync' ) . '</p>';
		?>
		<details class="sf-sync-app-password-details">
			<summary class="sf-sync-app-password-summary"><?php esc_html_e( 'View details', 'sf-content-sync' ); ?></summary>
			<p><?php esc_html_e( 'How to add an Application Password on the source WordPress site:', 'sf-content-sync' ); ?></p>
			<ol class="sf-sync-app-password-steps">
				<li><?php esc_html_e( 'Log in to the source site (the site you are pulling content from).', 'sf-content-sync' ); ?></li>
				<li><?php esc_html_e( 'Go to Users → Profile (or Users → All Users, then click your username).', 'sf-content-sync' ); ?></li>
				<li><?php esc_html_e( 'Scroll to the "Application Passwords" section.', 'sf-content-sync' ); ?></li>
				<li><?php esc_html_e( 'Enter a name for the new application password (e.g. "Content Sync") in the New Application Password Name field.', 'sf-content-sync' ); ?></li>
				<li><?php esc_html_e( 'Click "Add New Application Password".', 'sf-content-sync' ); ?></li>
				<li><?php esc_html_e( 'Copy the generated password and paste it into the Application password field below. You will not be able to see it again on the source site.', 'sf-content-sync' ); ?></li>
			</ol>
			<p><?php esc_html_e( 'If you do not see the Application Passwords section, your host or site may have disabled it. WordPress 5.6+ is required.', 'sf-content-sync' ); ?></p>
		</details>
		<?php
	}

	public static function field_source_url(): void {
		$opts = self::get_options();
		$val  = $opts['source_url'] ?? '';
		echo '<input id="sf_sync_source_url" name="' . esc_attr( self::OPTION_NAME . '[source_url]' ) . '" type="url" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="https://source.example.com" />';
		echo '<p class="description">' . esc_html__( 'Base URL of the source site (no trailing slash). HTTPS recommended.', 'sf-content-sync' ) . '</p>';
	}

	public static function field_source_username(): void {
		$opts = self::get_options();
		$val  = $opts['source_username'] ?? '';
		echo '<input id="sf_sync_source_username" name="' . esc_attr( self::OPTION_NAME . '[source_username]' ) . '" type="text" value="' . esc_attr( $val ) . '" class="regular-text" autocomplete="off" />';
	}

	public static function field_source_app_password(): void {
		$opts = self::get_options();
		$val  = $opts['source_app_password'] ?? '';
		echo '<input id="sf_sync_source_app_password" name="' . esc_attr( self::OPTION_NAME . '[source_app_password]' ) . '" type="password" value="" class="regular-text" autocomplete="new-password" />';
		echo '<p class="description">' . esc_html__( 'Leave blank to keep existing password. Create under Users → Profile → Application Passwords on the source site.', 'sf-content-sync' ) . '</p>';
		if ( ! empty( $val ) ) {
			echo '<p class="description">' . esc_html__( 'A password is currently stored.', 'sf-content-sync' ) . '</p>';
		}
	}

	public static function field_test_connection(): void {
		echo '<button type="button" id="sf-sync-test-connection" class="button">' . esc_html__( 'Test connection', 'sf-content-sync' ) . '</button>';
		echo ' <span id="sf-sync-test-result" aria-live="polite"></span>';
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save settings', 'sf-content-sync' ) );
				?>
			</form>
		</div>
		<?php
	}

	public static function enqueue_assets( string $hook ): void {
		if ( $hook !== 'settings_page_' . self::PAGE_SLUG ) {
			return;
		}
		wp_enqueue_style(
			'sf-content-sync-admin',
			SF_CONTENT_SYNC_PLUGIN_URL . 'assets/css/admin.css',
			[],
			SF_CONTENT_SYNC_VERSION
		);
		wp_enqueue_script(
			'sf-content-sync-settings',
			SF_CONTENT_SYNC_PLUGIN_URL . 'assets/js/settings.js',
			[ 'jquery' ],
			SF_CONTENT_SYNC_VERSION,
			true
		);
		wp_localize_script( 'sf-content-sync-settings', 'sfContentSyncSettings', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sf_sync_test_connection' ),
		] );
	}

	public static function ajax_test_connection(): void {
		check_ajax_referer( 'sf_sync_test_connection', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sf-content-sync' ) ] );
		}
		$opts = self::get_options();
		// Use POST params when provided (test with current form values); otherwise use saved options.
		$source_url  = isset( $_POST['source_url'] ) && is_string( $_POST['source_url'] )
			? esc_url_raw( trim( wp_unslash( $_POST['source_url'] ) ), [ 'https', 'http' ] )
			: ( $opts['source_url'] ?? '' );
		$source_user = isset( $_POST['source_username'] ) && is_string( $_POST['source_username'] )
			? sanitize_text_field( wp_unslash( $_POST['source_username'] ) )
			: ( $opts['source_username'] ?? '' );
		$source_pass = isset( $_POST['source_app_password'] ) && $_POST['source_app_password'] !== ''
			? str_replace( ' ', '', sanitize_text_field( wp_unslash( $_POST['source_app_password'] ) ) )
			: ( $opts['source_app_password'] ?? '' );
		$source_pass = str_replace( ' ', '', $source_pass );
		if ( $source_url === '' || $source_user === '' || $source_pass === '' ) {
			wp_send_json_error( [ 'message' => __( 'Source URL, username, and application password are required. Save settings or enter the application password to test.', 'sf-content-sync' ) ] );
		}
		$test_url = rtrim( $source_url, '/' ) . '/wp-json/' . SF_CONTENT_SYNC_REST_NAMESPACE . '/ping';
		$auth_basic = 'Basic ' . base64_encode( $source_user . ':' . $source_pass );
		$response = wp_remote_get( $test_url, [
			'timeout' => 15,
			'headers' => [
				'Authorization'             => $auth_basic,
				'X-SF-Sync-Authorization'    => $auth_basic,
			],
			'sslverify' => true,
		] );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [
				'message'   => $response->get_error_message(),
				'tried_url' => $test_url,
			] );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code === 401 ) {
			// Retry with credentials in query string (source must have define('SF_SYNC_ALLOW_QUERY_AUTH', true) in wp-config).
			$retry_url = add_query_arg( [
				'sf_sync_user' => $source_user,
				'sf_sync_pass' => $source_pass,
			], $test_url );
			$retry = wp_remote_get( $retry_url, [ 'timeout' => 15, 'sslverify' => true ] );
			if ( ! is_wp_error( $retry ) && wp_remote_retrieve_response_code( $retry ) >= 200 && wp_remote_retrieve_response_code( $retry ) < 300 ) {
				$response = $retry;
				$code = wp_remote_retrieve_response_code( $response );
			}
		}
		if ( $code === 401 ) {
			wp_send_json_error( [
				'message'   => __( 'Invalid username or application password. Create the Application Password on the source site (Users → Profile → Application Passwords). If the host strips auth headers, add define(\'SF_SYNC_ALLOW_QUERY_AUTH\', true); to wp-config.php on the source.', 'sf-content-sync' ),
				'tried_url' => $test_url,
			] );
		}
		if ( $code === 404 ) {
			wp_send_json_success( [ 'message' => __( 'Connection OK. (Page 1 not found on source; API is reachable.)', 'sf-content-sync' ) ] );
		}
		if ( $code >= 200 && $code < 300 ) {
			wp_send_json_success( [ 'message' => __( 'Connection successful.', 'sf-content-sync' ) ] );
		}
		wp_send_json_error( [
			'message'   => sprintf( __( 'Unexpected response: %d', 'sf-content-sync' ), $code ),
			'tried_url' => $test_url,
		] );
	}
}
