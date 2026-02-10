<?php
/**
 * Map source post ID (or slug/title) to destination post ID.
 *
 * @package SF_Content_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SF_Sync_Mapper {

	/**
	 * Resolve source post identifier to destination post ID.
	 * Tries by slug first (same post type), then by title. Returns 0 if not found.
	 *
	 * @param int|string $source_id_or_slug Source post ID (for reference), or slug/title to look up.
	 * @param string     $post_type         Post type to search (e.g. 'page', 'post').
	 * @param string     $source_slug       Optional slug from source (if we have it in payload).
	 * @param string     $source_title      Optional title from source.
	 * @return int Destination post ID or 0.
	 */
	public static function source_to_destination_post_id( $source_id_or_slug, string $post_type = 'page', string $source_slug = '', string $source_title = '' ): int {
		if ( $source_slug !== '' ) {
			$page = get_page_by_path( $source_slug, OBJECT, $post_type );
			if ( $page instanceof WP_Post ) {
				return (int) $page->ID;
			}
		}
		if ( is_string( $source_id_or_slug ) && $source_id_or_slug !== '' ) {
			$by_slug = get_page_by_path( $source_id_or_slug, OBJECT, $post_type );
			if ( $by_slug instanceof WP_Post ) {
				return (int) $by_slug->ID;
			}
		}
		if ( $source_title !== '' ) {
			$posts = get_posts( [
				'post_type'      => $post_type,
				'title'         => $source_title,
				'post_status'   => 'any',
				'numberposts'   => 1,
				'fields'        => 'ids',
			] );
			if ( ! empty( $posts ) ) {
				return (int) $posts[0];
			}
		}
		return 0;
	}

	/**
	 * Given source payload for a post_object field (may be ID or array with id, slug, title), return destination post ID.
	 *
	 * @param mixed  $value     Source value: int (ID), or array with id, slug, title.
	 * @param string $post_type Post type to resolve to.
	 * @return int Destination post ID or 0.
	 */
	/**
	 * Resolve source value (ID or array with type, id, slug, title) to destination post ID.
	 */
	public static function resolve_post_object( $value, string $post_type = 'page' ): int {
		$slug  = '';
		$title = '';
		$id    = 0;
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			$id = (int) $value;
		} elseif ( is_array( $value ) ) {
			$id    = isset( $value['id'] ) ? (int) $value['id'] : 0;
			$slug  = isset( $value['slug'] ) && is_string( $value['slug'] ) ? $value['slug'] : '';
			$title = isset( $value['title'] ) && is_string( $value['title'] ) ? $value['title'] : '';
		}
		return self::source_to_destination_post_id( $id, $post_type, $slug, $title );
	}
}
