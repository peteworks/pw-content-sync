<?php
/**
 * REST endpoint on the source site: exposes page post data + ACF with media URLs.
 *
 * @package SF_Content_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SF_Sync_Rest_Source {

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
		add_filter( 'determine_current_user', [ self::class, 'auth_from_custom_header' ], 25 );
		// Run before permission_callback: copy auth query params from REST request into $_GET so try_auth_from_query can read them.
		add_filter( 'rest_pre_dispatch', [ self::class, 'inject_query_params_into_get' ], 10, 3 );
	}

	/**
	 * REST API parses query string into the request object; $_GET may not be set. Copy our auth params so try_auth_from_query can read them.
	 */
	public static function inject_query_params_into_get( $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
		if ( strpos( $request->get_route(), 'sf-sync' ) === false ) {
			return $result;
		}
		$params = $request->get_query_params();
		if ( isset( $params['sf_sync_user'], $params['sf_sync_pass'] ) ) {
			$_GET['sf_sync_user'] = $params['sf_sync_user'];
			$_GET['sf_sync_pass'] = $params['sf_sync_pass'];
		}
		return $result;
	}

	/**
	 * If not already logged in, try: (1) X-SF-Sync-Authorization header, (2) query params when SF_SYNC_ALLOW_QUERY_AUTH is set.
	 * Use when the server strips the standard Authorization header before PHP.
	 */
	public static function auth_from_custom_header( $user_id ): int|false {
		if ( $user_id ) {
			return $user_id;
		}
		if ( ! wp_is_application_passwords_available() ) {
			return $user_id;
		}
		$user_id = self::try_auth_from_header();
		if ( $user_id ) {
			return $user_id;
		}
		return self::try_auth_from_query();
	}

	private static function try_auth_from_header(): int|false {
		$header = isset( $_SERVER['HTTP_X_SF_SYNC_AUTHORIZATION'] ) ? $_SERVER['HTTP_X_SF_SYNC_AUTHORIZATION'] : '';
		if ( strpos( $header, 'Basic ' ) !== 0 ) {
			return false;
		}
		$encoded = substr( $header, 6 );
		$decoded = base64_decode( $encoded, true );
		if ( $decoded === false ) {
			return false;
		}
		$parts = explode( ':', $decoded, 2 );
		if ( count( $parts ) !== 2 ) {
			return false;
		}
		$authenticated = wp_authenticate_application_password( null, $parts[0], $parts[1] );
		return $authenticated instanceof WP_User ? $authenticated->ID : false;
	}

	/**
	 * Fallback: auth from query params. Only when source wp-config has define('SF_SYNC_ALLOW_QUERY_AUTH', true);
	 * Insecure (URLs may be logged) — use only on dev/staging.
	 */
	private static function try_auth_from_query(): int|false {
		if ( ! defined( 'SF_SYNC_ALLOW_QUERY_AUTH' ) || ! SF_SYNC_ALLOW_QUERY_AUTH ) {
			return false;
		}
		$user = isset( $_GET['sf_sync_user'] ) ? sanitize_text_field( wp_unslash( $_GET['sf_sync_user'] ) ) : '';
		$pass = isset( $_GET['sf_sync_pass'] ) ? sanitize_text_field( wp_unslash( $_GET['sf_sync_pass'] ) ) : '';
		if ( $user === '' || $pass === '' ) {
			return false;
		}
		$authenticated = wp_authenticate_application_password( null, $user, $pass );
		return $authenticated instanceof WP_User ? $authenticated->ID : false;
	}

	public static function register_routes(): void {
		register_rest_route( SF_CONTENT_SYNC_REST_NAMESPACE, '/ping', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'ping' ],
			'permission_callback' => [ self::class, 'ping_permission_check' ],
		] );

		register_rest_route( SF_CONTENT_SYNC_REST_NAMESPACE, '/page/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'get_page_by_id' ],
			'permission_callback' => [ self::class, 'permission_check' ],
			'args'                => [
				'id' => [
					'required'          => true,
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && (int) $param > 0;
					},
					'sanitize_callback' => 'absint',
				],
			],
		] );

		register_rest_route( SF_CONTENT_SYNC_REST_NAMESPACE, '/page-by-slug/(?P<slug>[a-z0-9\-]+)', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'get_page_by_slug' ],
			'permission_callback' => [ self::class, 'permission_check' ],
			'args'                => [
				'slug' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_title',
				],
			],
		] );

		// Any post type: by ID or by slug.
		register_rest_route( SF_CONTENT_SYNC_REST_NAMESPACE, '/post-type/(?P<post_type>[a-z0-9_\-]+)/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'get_post_by_id' ],
			'permission_callback' => [ self::class, 'ping_permission_check' ],
			'args'                => [
				'post_type' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
				'id'        => [
					'required'          => true,
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && (int) $param > 0;
					},
					'sanitize_callback' => 'absint',
				],
			],
		] );

		register_rest_route( SF_CONTENT_SYNC_REST_NAMESPACE, '/post-type/(?P<post_type>[a-z0-9_\-]+)/by-slug/(?P<slug>[a-z0-9\-]+)', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'get_post_by_slug' ],
			'permission_callback' => [ self::class, 'ping_permission_check' ],
			'args'                => [
				'post_type' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
				'slug'      => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_title',
				],
			],
		] );
	}

	/**
	 * Ping: only require authenticated user (no read_post). Used for test connection.
	 */
	public static function ping_permission_check( WP_REST_Request $request ): bool {
		return is_user_logged_in();
	}

	public static function ping( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( [ 'message' => 'OK', 'authenticated' => true ], 200 );
	}

	/**
	 * Require Application Password (Basic auth) or cookie auth and ability to read the post.
	 * User is set by WP via determine_current_user (wp_validate_application_password) when Authorization: Basic is sent.
	 */
	public static function permission_check( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$post_id = (int) $request->get_param( 'id' );
		if ( $post_id > 0 && ! current_user_can( 'read_post', $post_id ) ) {
			return false;
		}
		// Slug route has no 'id'; we check read_post in get_page_by_slug after resolving.
		return true;
	}

	public static function get_page_by_id( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request->get_param( 'id' );
		return self::build_page_response( $id );
	}

	public static function get_page_by_slug( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug = $request->get_param( 'slug' );
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $page instanceof WP_Post ) {
			return new WP_Error( 'not_found', __( 'Page not found.', 'sf-content-sync' ), [ 'status' => 404 ] );
		}
		return self::build_page_response( (int) $page->ID );
	}

	public static function get_post_by_id( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_type = $request->get_param( 'post_type' );
		$id        = (int) $request->get_param( 'id' );
		return self::build_post_response( $id, $post_type );
	}

	public static function get_post_by_slug( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_type = $request->get_param( 'post_type' );
		$slug      = $request->get_param( 'slug' );
		$posts     = get_posts( [
			'post_type'      => $post_type,
			'name'           => $slug,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		if ( empty( $posts ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'sf-content-sync' ), [ 'status' => 404 ] );
		}
		return self::build_post_response( (int) $posts[0], $post_type );
	}

	private static function build_page_response( int $post_id ): WP_REST_Response|WP_Error {
		return self::build_post_response( $post_id, 'page' );
	}

	private static function build_post_response( int $post_id, string $post_type ): WP_REST_Response|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $post_type ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'sf-content-sync' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'read_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot read this post.', 'sf-content-sync' ), [ 'status' => 403 ] );
		}

		$featured_id     = (int) get_post_thumbnail_id( $post_id );
		$featured_payload = $featured_id > 0 ? self::attachment_to_payload( $featured_id ) : null;

		$acf_raw = function_exists( 'get_fields' ) ? get_fields( $post_id ) : null;
		$acf     = is_array( $acf_raw ) ? self::replace_attachments_in_value( $acf_raw ) : [];

		$data = [
			'id'             => $post_id,
			'title'          => $post->post_title,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'featured_media' => $featured_payload,
			'acf'            => $acf,
		];

		if ( $post_type === 'page' ) {
			$page_template = get_post_meta( $post_id, '_wp_page_template', true );
			$data['page_template'] = is_string( $page_template ) && $page_template !== '' ? $page_template : 'default';
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Convert attachment ID to payload for destination to download.
	 */
	private static function attachment_to_payload( int $attachment_id ): array {
		$url = wp_get_attachment_url( $attachment_id );
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$file = get_attached_file( $attachment_id );
		$filename = $file ? basename( $file ) : '';

		return [
			'type'     => 'attachment',
			'id'       => $attachment_id,
			'url'      => $url ?: '',
			'alt'      => is_string( $alt ) ? $alt : '',
			'filename' => $filename,
		];
	}

	/**
	 * Recursively replace attachment IDs in ACF value with payload objects.
	 */
	private static function replace_attachments_in_value( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			// ACF image field: array with 'ID', 'url', etc. or just ID key.
			if ( isset( $value['ID'] ) && is_numeric( $value['ID'] ) ) {
				return self::attachment_to_payload( (int) $value['ID'] );
			}
			// Repeater / flexible: list of rows.
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::replace_attachments_in_value( $v );
			}
			return $out;
		}

		if ( is_numeric( $value ) && (int) $value > 0 ) {
			$post = get_post( (int) $value );
			if ( $post instanceof WP_Post ) {
				if ( $post->post_type === 'attachment' ) {
					return self::attachment_to_payload( (int) $value );
				}
				return [
					'type'  => 'post',
					'id'    => $post->ID,
					'slug'  => $post->post_name,
					'title' => $post->post_title,
				];
			}
		}

		return $value;
	}
}
