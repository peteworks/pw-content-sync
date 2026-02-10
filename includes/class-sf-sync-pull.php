<?php
/**
 * Pull content from source: metabox and AJAX handler.
 *
 * @package SF_Content_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SF_Sync_Pull {

	public static function init(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add_metabox' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ], 10, 1 );
		add_action( 'wp_ajax_sf_sync_pull_page', [ self::class, 'ajax_pull_page' ] );
	}

	public static function add_metabox(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! $screen->post_type ) {
			return;
		}
		$post_type = $screen->post_type;
		$pt_obj   = get_post_type_object( $post_type );
		if ( ! $pt_obj || ! $pt_obj->public ) {
			return;
		}
		add_meta_box(
			'sf_sync_pull',
			__( 'Pull from source', 'sf-content-sync' ),
			[ self::class, 'render_metabox' ],
			$post_type,
			'side',
			'default'
		);
	}

	public static function render_metabox( WP_Post $post ): void {
		$opts = SF_Sync_Settings::get_options();
		if ( empty( $opts['source_url'] ) || empty( $opts['source_username'] ) || empty( $opts['source_app_password'] ) ) {
			echo '<p>' . esc_html__( 'Configure the source site under Settings → Content Sync first.', 'sf-content-sync' ) . '</p>';
			return;
		}
		wp_nonce_field( 'sf_sync_pull_page', 'sf_sync_pull_nonce' );
		$post_type = get_post_type( $post );
		$label     = $post_type === 'page' ? __( 'Source page (ID or slug)', 'sf-content-sync' ) : __( 'Source (ID or slug)', 'sf-content-sync' );
		?>
		<p>
			<label for="sf-sync-source-page"><?php echo esc_html( $label ); ?></label>
			<input type="text" id="sf-sync-source-page" class="widefat" placeholder="123 or my-slug" />
		</p>
		<p>
			<button type="button" id="sf-sync-pull-button" class="button button-primary"><?php esc_html_e( 'Pull content', 'sf-content-sync' ); ?></button>
		</p>
		<p id="sf-sync-pull-result" aria-live="polite" class="sf-sync-result"></p>
		<?php
	}

	public static function enqueue_assets( string $hook ): void {
		global $post;
		if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
			return;
		}
		if ( ! $post || ! $post->post_type ) {
			return;
		}
		$pt_obj = get_post_type_object( $post->post_type );
		if ( ! $pt_obj || ! $pt_obj->public ) {
			return;
		}
		wp_enqueue_script(
			'sf-content-sync-pull',
			SF_CONTENT_SYNC_PLUGIN_URL . 'assets/js/pull-admin.js',
			[ 'jquery' ],
			SF_CONTENT_SYNC_VERSION,
			true
		);
		wp_localize_script( 'sf-content-sync-pull', 'sfContentSyncPull', [
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'sf_sync_pull_page' ),
			'postId'    => $post->ID,
			'postType'  => $post->post_type,
			'i18n'      => [
				'success' => __( 'Content pulled successfully.', 'sf-content-sync' ),
				'error'   => __( 'Error:', 'sf-content-sync' ),
				'loading' => __( 'Pulling…', 'sf-content-sync' ),
			],
		] );
		wp_enqueue_style(
			'sf-content-sync-admin',
			SF_CONTENT_SYNC_PLUGIN_URL . 'assets/css/admin.css',
			[],
			SF_CONTENT_SYNC_VERSION
		);
	}

	public static function ajax_pull_page(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'sf_sync_pull_page' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'sf-content-sync' ) ] );
		}
		$dest_post_id = isset( $_POST['dest_post_id'] ) ? (int) $_POST['dest_post_id'] : 0;
		$source_page  = isset( $_POST['source_page'] ) ? sanitize_text_field( wp_unslash( $_POST['source_page'] ) ) : '';
		$post_type    = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		if ( $dest_post_id <= 0 || $source_page === '' ) {
			wp_send_json_error( [ 'message' => __( 'Invalid post or source identifier.', 'sf-content-sync' ) ] );
		}
		if ( ! current_user_can( 'edit_post', $dest_post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You cannot edit this post.', 'sf-content-sync' ) ] );
		}

		$dest_type = get_post_type( $dest_post_id );
		if ( $post_type === '' ) {
			$post_type = $dest_type ?: 'post';
		}
		if ( $post_type !== $dest_type ) {
			wp_send_json_error( [ 'message' => __( 'Source post type must match the current post type.', 'sf-content-sync' ) ] );
		}
		$pt_obj = get_post_type_object( $post_type );
		if ( ! $pt_obj || ! $pt_obj->public ) {
			wp_send_json_error( [ 'message' => __( 'Invalid post type.', 'sf-content-sync' ) ] );
		}

		$opts = SF_Sync_Settings::get_options();
		if ( empty( $opts['source_url'] ) || empty( $opts['source_username'] ) || empty( $opts['source_app_password'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Source not configured. Go to Settings → Content Sync.', 'sf-content-sync' ) ] );
		}

		$base    = rtrim( $opts['source_url'], '/' ) . '/wp-json/' . SF_CONTENT_SYNC_REST_NAMESPACE;
		$app_pass = str_replace( ' ', '', $opts['source_app_password'] ?? '' );
		$auth     = 'Basic ' . base64_encode( $opts['source_username'] . ':' . $app_pass );

		if ( is_numeric( $source_page ) && (int) $source_page > 0 ) {
			$url = $base . '/post-type/' . $post_type . '/' . (int) $source_page;
		} else {
			$slug = sanitize_title( $source_page );
			$url  = $base . '/post-type/' . $post_type . '/by-slug/' . $slug;
		}

		$response = wp_remote_get( $url, [
			'timeout' => 60,
			'headers' => [
				'Authorization'          => $auth,
				'X-SF-Sync-Authorization' => $auth,
			],
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ] );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code === 401 ) {
			$retry_url = add_query_arg( [
				'sf_sync_user' => $opts['source_username'],
				'sf_sync_pass' => $app_pass,
			], $url );
			$retry = wp_remote_get( $retry_url, [ 'timeout' => 60 ] );
			if ( ! is_wp_error( $retry ) && wp_remote_retrieve_response_code( $retry ) >= 200 && wp_remote_retrieve_response_code( $retry ) < 300 ) {
				$response = $retry;
				$code = wp_remote_retrieve_response_code( $response );
				$body = wp_remote_retrieve_body( $response );
			}
		}
		if ( $code === 401 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid source credentials.', 'sf-content-sync' ) ] );
		}
		if ( $code === 404 ) {
			$msg = __( 'Source item not found.', 'sf-content-sync' )
				. ' ' . __( 'Ensure the SF Content Sync plugin is installed and active on the source site, and the slug or ID is correct.', 'sf-content-sync' );
			wp_send_json_error( [ 'message' => $msg, 'tried_url' => $url ] );
		}
		if ( $code < 200 || $code >= 300 ) {
			wp_send_json_error( [ 'message' => sprintf( __( 'Source returned error: %d', 'sf-content-sync' ), $code ) ] );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid response from source.', 'sf-content-sync' ) ] );
		}

		$post_data = [
			'ID'           => $dest_post_id,
			'post_title'   => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'post_content' => isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '',
			'post_excerpt' => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : '',
			'post_name'    => isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '',
			'post_status'  => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft',
		];
		wp_update_post( $post_data );

		// Set page template first so ACF field groups tied to the template are available.
		if ( isset( $data['page_template'] ) && is_string( $data['page_template'] ) ) {
			$template = sanitize_text_field( $data['page_template'] );
			if ( $template === 'default' || preg_match( '/^[a-z0-9_\-\.\/]+\.php$/i', $template ) ) {
				update_post_meta( $dest_post_id, '_wp_page_template', $template );
			}
		}

		$url_to_id = [];
		$featured = $data['featured_media'] ?? null;
		if ( is_array( $featured ) && ! empty( $featured['url'] ) ) {
			$aid = SF_Sync_Media::payload_to_attachment_id( $featured, $dest_post_id, $url_to_id );
			if ( $aid > 0 ) {
				set_post_thumbnail( $dest_post_id, $aid );
			}
		}

		$acf = $data['acf'] ?? [];
		if ( is_array( $acf ) && ! empty( $acf ) ) {
			$walker = new SF_Sync_Field_Walker( $dest_post_id, $url_to_id );
			$walker->update_all_acf_from_payload( $acf );
		}

		$message = __( 'Content and ACF fields updated.', 'sf-content-sync' );
		wp_send_json_success( [ 'message' => $message ] );
	}
}
