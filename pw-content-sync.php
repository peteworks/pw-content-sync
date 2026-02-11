<?php
/**
 * Plugin Name: Content Sync
 * Description: Sync page content and ACF fields from a source WordPress site to the current site. Pull content page-by-page via REST API.
 * Version: 1.0.2
 * Author: Pete
 * License: GPL v2 or later
 * Text Domain: sf-content-sync
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SF_CONTENT_SYNC_VERSION', '1.0.2' );
define( 'SF_CONTENT_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SF_CONTENT_SYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SF_CONTENT_SYNC_REST_NAMESPACE', 'sf-sync/v1' );

/**
 * GitHub-based updates. Define in wp-config.php:
 *   PW_CONTENT_SYNC_GITHUB_REPO - e.g. 'your-username/pw-content-sync' (required)
 *   PW_CONTENT_SYNC_GITHUB_TOKEN - GitHub Personal Access Token (required for private repos)
 * Updates are delivered from GitHub Releases (zip built by CI on push to production).
 */
if ( is_admin() && ! defined( 'WP_CLI' ) && defined( 'PW_CONTENT_SYNC_GITHUB_REPO' ) && PW_CONTENT_SYNC_GITHUB_REPO !== '' ) {
	$puc_autoload = SF_CONTENT_SYNC_PLUGIN_DIR . 'vendor/autoload.php';
	if ( file_exists( $puc_autoload ) ) {
		require_once $puc_autoload;
		$pw_content_sync_repo_url = 'https://github.com/' . trim( PW_CONTENT_SYNC_GITHUB_REPO, '/' );
		$pw_content_sync_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			$pw_content_sync_repo_url,
			__FILE__,
			'pw-content-sync'
		);
		$pw_content_sync_update_checker->getVcsApi()->enableReleaseAssets();
		if ( defined( 'PW_CONTENT_SYNC_GITHUB_TOKEN' ) && PW_CONTENT_SYNC_GITHUB_TOKEN !== '' ) {
			$pw_content_sync_update_checker->setAuthentication( PW_CONTENT_SYNC_GITHUB_TOKEN );
		}
	}
}

require_once SF_CONTENT_SYNC_PLUGIN_DIR . 'includes/class-sf-sync-rest-source.php';
require_once SF_CONTENT_SYNC_PLUGIN_DIR . 'includes/class-sf-sync-settings.php';
require_once SF_CONTENT_SYNC_PLUGIN_DIR . 'includes/class-sf-sync-media.php';
require_once SF_CONTENT_SYNC_PLUGIN_DIR . 'includes/class-sf-sync-mapper.php';
require_once SF_CONTENT_SYNC_PLUGIN_DIR . 'includes/class-sf-sync-field-walker.php';
require_once SF_CONTENT_SYNC_PLUGIN_DIR . 'includes/class-sf-sync-pull.php';

/**
 * Check for ACF and bootstrap the plugin.
 */
function sf_content_sync_init(): void {
	if ( ! function_exists( 'get_fields' ) || ! function_exists( 'acf_get_field_groups' ) ) {
		add_action( 'admin_notices', static function (): void {
			echo '<div class="notice notice-warning"><p>';
			esc_html_e( 'SF Content Sync requires Advanced Custom Fields (ACF) to be active.', 'sf-content-sync' );
			echo '</p></div>';
		} );
		return;
	}

	SF_Sync_Rest_Source::register();
	SF_Sync_Settings::init();
	SF_Sync_Pull::init();
}

add_action( 'plugins_loaded', 'sf_content_sync_init' );

/**
 * Add "View details" link on the Plugins list (next to "By Pete") linking to settings and instructions.
 */
add_filter( 'plugin_row_meta', function ( array $plugin_meta, string $plugin_file, array $plugin_data, string $status ): array {
	if ( $plugin_file !== 'pw-content-sync/pw-content-sync.php' ) {
		return $plugin_meta;
	}
	$settings_url = admin_url( 'options-general.php?page=sf-content-sync' );
	if ( current_user_can( 'manage_options' ) ) {
		array_unshift( $plugin_meta, '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'View details', 'sf-content-sync' ) . '</a>' );
	}
	return $plugin_meta;
}, 10, 4 );

/**
 * Add "Check for updates" link to the plugin action row on wp-admin/plugins.php.
 * Uses the same URL that Plugin Update Checker expects when GitHub updates are enabled.
 */
add_filter( 'plugin_action_links_pw-content-sync/pw-content-sync.php', function ( array $actions ): array {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return $actions;
	}
	if ( defined( 'PW_CONTENT_SYNC_GITHUB_REPO' ) && PW_CONTENT_SYNC_GITHUB_REPO !== '' ) {
		$check_url = wp_nonce_url(
			add_query_arg(
				[
					'puc_check_for_updates' => 1,
					'puc_slug'              => 'pw-content-sync',
				],
				admin_url( 'plugins.php' )
			),
			'puc_check_for_updates'
		);
		$actions['pw_content_sync_check'] = '<a href="' . esc_url( $check_url ) . '">' . esc_html__( 'Check for updates', 'sf-content-sync' ) . '</a>';
	}
	return $actions;
} );
