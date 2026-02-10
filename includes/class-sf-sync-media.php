<?php
/**
 * Download remote file and sideload into media library.
 *
 * @package SF_Content_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SF_Sync_Media {

	/**
	 * Download file from URL and create attachment. Returns new attachment ID or 0 on failure.
	 *
	 * @param string   $url       Full URL to the file.
	 * @param int      $post_id   Optional post to attach to.
	 * @param string   $alt       Optional alt text for images.
	 * @param array<string, int> $url_to_id Cache of already-imported URLs to attachment IDs (passed by reference).
	 * @return int Attachment ID or 0.
	 */
	public static function sideload_from_url( string $url, int $post_id = 0, string $alt = '', array &$url_to_id = [] ): int {
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return 0;
		}
		if ( isset( $url_to_id[ $url ] ) ) {
			return $url_to_id[ $url ];
		}

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$file_array = [
			'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ) ?: 'download',
			'tmp_name' => $tmp,
		];

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_handle_sideload( $file_array, $post_id, null, [ 'post_author' => get_current_user_id() ?: 1 ] );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return 0;
		}
		if ( $alt && wp_attachment_is_image( $id ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		$url_to_id[ $url ] = $id;
		return $id;
	}

	/**
	 * Handle payload from source: either attachment payload (type, url, alt, filename) or already numeric ID.
	 * Returns destination attachment ID; uses cache to avoid re-downloading same URL.
	 *
	 * @param mixed    $payload   Source value: array with type=>attachment, url, alt, or int (ignored; use url).
	 * @param int      $post_id   Post to attach to.
	 * @param array<string, int> $url_to_id Cache (by reference).
	 * @return int Attachment ID or 0.
	 */
	public static function payload_to_attachment_id( $payload, int $post_id, array &$url_to_id = [] ): int {
		if ( is_array( $payload ) && isset( $payload['type'] ) && $payload['type'] === 'attachment' && ! empty( $payload['url'] ) ) {
			$alt = isset( $payload['alt'] ) && is_string( $payload['alt'] ) ? $payload['alt'] : '';
			return self::sideload_from_url( $payload['url'], $post_id, $alt, $url_to_id );
		}
		return 0;
	}
}
