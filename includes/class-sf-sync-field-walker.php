<?php
/**
 * Recursively process ACF payload from source and update destination post fields.
 *
 * @package SF_Content_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SF_Sync_Field_Walker {

	/** @var array<string, int> URL to attachment ID cache for media (shared with pull handler). */
	private array $url_to_attachment_id = [];

	/** @var int Destination post ID. */
	private int $post_id;

	/** @var list<string> Field names that were updated (for diagnostics). */
	private array $updated = [];

	/** @var list<string> Field names skipped because no matching field on destination (for diagnostics). */
	private array $skipped = [];

	public function __construct( int $post_id, array $url_to_attachment_id = [] ) {
		$this->post_id = $post_id;
		$this->url_to_attachment_id = $url_to_attachment_id;
	}

	/**
	 * Update ACF field on the destination post from source payload value.
	 * Handles attachment payloads (download and get new ID), post payloads (resolve to local ID), repeaters, flexible content.
	 *
	 * @param string $field_name ACF field name.
	 * @param mixed  $value      Source payload value (may contain attachment/post payloads).
	 * @return bool Success.
	 */
	public function update_field_from_payload( string $field_name, mixed $value ): bool {
		$field = get_field_object( $field_name, $this->post_id );
		if ( ! is_array( $field ) ) {
			$this->skipped[] = $field_name;
			return false;
		}
		$processed = $this->process_value( $value, $field );
		update_field( $field_name, $processed, $this->post_id );
		$this->updated[] = $field_name;
		return true;
	}

	/**
	 * Process a single value: resolve attachments and post_object, recurse for repeater/flexible.
	 */
	private function process_value( mixed $value, array $field ): mixed {
		$type = $field['type'] ?? '';

		switch ( $type ) {
			case 'image':
			case 'file':
				return $this->process_attachment_value( $value );
			case 'post_object':
			case 'relationship':
				return $this->process_post_object_value( $value, $field );
			case 'repeater':
				return $this->process_repeater_value( $value, $field );
			case 'flexible_content':
				return $this->process_flexible_value( $value, $field );
			case 'gallery':
				return $this->process_gallery_value( $value );
			default:
				return $value;
		}
	}

	private function process_attachment_value( mixed $value ): int {
		if ( is_array( $value ) && isset( $value['type'] ) && $value['type'] === 'attachment' && ! empty( $value['url'] ) ) {
			return SF_Sync_Media::payload_to_attachment_id( $value, $this->post_id, $this->url_to_attachment_id );
		}
		return 0;
	}

	private function process_post_object_value( mixed $value, array $field ): int|array {
		$post_type = 'page';
		if ( ! empty( $field['post_type'] ) ) {
			$post_type = is_array( $field['post_type'] ) ? ( $field['post_type'][0] ?? 'page' ) : $field['post_type'];
		}
		$multiple = ! empty( $field['multiple'] );
		if ( $multiple && is_array( $value ) ) {
			$ids = [];
			foreach ( $value as $item ) {
				$resolved = SF_Sync_Mapper::resolve_post_object( $item, $post_type );
				if ( $resolved > 0 ) {
					$ids[] = $resolved;
				}
			}
			return $ids;
		}
		return SF_Sync_Mapper::resolve_post_object( $value, $post_type );
	}

	private function process_repeater_value( mixed $value, array $field ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$sub_fields = $field['sub_fields'] ?? [];
		$out       = [];
		foreach ( $value as $row_index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out_row = [];
			foreach ( $sub_fields as $sub ) {
				$name = $sub['name'] ?? '';
				if ( $name === '' || ! array_key_exists( $name, $row ) ) {
					continue;
				}
				$out_row[ $name ] = $this->process_value( $row[ $name ], $sub );
			}
			$out[] = $out_row;
		}
		return $out;
	}

	private function process_flexible_value( mixed $value, array $field ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$layouts = $field['layouts'] ?? [];
		$out    = [];
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$layout_name = $row['acf_fc_layout'] ?? '';
			if ( $layout_name === '' ) {
				continue;
			}
			$layout = null;
			foreach ( $layouts as $l ) {
				if ( ( $l['name'] ?? '' ) === $layout_name ) {
					$layout = $l;
					break;
				}
			}
			$out_row = [ 'acf_fc_layout' => $layout_name ];
			if ( $layout && ! empty( $layout['sub_fields'] ) ) {
				foreach ( $layout['sub_fields'] as $sub ) {
					$name = $sub['name'] ?? '';
					if ( $name === '' || ! array_key_exists( $name, $row ) ) {
						continue;
					}
					$out_row[ $name ] = $this->process_value( $row[ $name ], $sub );
				}
			}
			$out[] = $out_row;
		}
		return $out;
	}

	private function process_gallery_value( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$ids = [];
		foreach ( $value as $item ) {
			$id = $this->process_attachment_value( $item );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Update all ACF fields on the post from the source 'acf' payload.
	 *
	 * @param array<string, mixed> $acf_payload Top-level ACF key => value from source.
	 */
	public function update_all_acf_from_payload( array $acf_payload ): void {
		foreach ( $acf_payload as $field_name => $value ) {
			$this->update_field_from_payload( $field_name, $value );
		}
	}

	/**
	 * Get the URL-to-attachment-ID cache (for reuse with featured image or logging).
	 *
	 * @return array<string, int>
	 */
	public function get_url_to_attachment_id_cache(): array {
		return $this->url_to_attachment_id;
	}

	/** @return list<string> */
	public function get_updated_fields(): array {
		return $this->updated;
	}

	/** @return list<string> */
	public function get_skipped_fields(): array {
		return $this->skipped;
	}
}
