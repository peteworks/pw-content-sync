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
			// Conditionally visible or never-saved fields: get_field_object() can return false when
			// looking up by name. Resolve field config from post's field groups by name.
			$field = $this->get_field_config_by_name( $field_name );
		}
		if ( ! is_array( $field ) ) {
			$this->skipped[] = $field_name;
			return false;
		}
		$processed = $this->process_value( $value, $field );
		// Use field key so ACF accepts the update even when field is conditionally hidden.
		$selector = $field['key'] ?? $field_name;
		update_field( $selector, $processed, $this->post_id );
		$this->updated[] = $field_name;
		return true;
	}

	/**
	 * Get ACF field config by name from field groups that apply to this post.
	 * Use when get_field_object( $name, $post_id ) returns false (e.g. conditional field not yet saved).
	 *
	 * @return array|null Field config or null if not found.
	 */
	private function get_field_config_by_name( string $field_name ): ?array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return null;
		}
		$groups = acf_get_field_groups( [ 'post_id' => $this->post_id ] );
		if ( ! is_array( $groups ) ) {
			return null;
		}
		foreach ( $groups as $group ) {
			$key = $group['key'] ?? '';
			if ( $key === '' ) {
				continue;
			}
			$fields = acf_get_fields( $key );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			$found = $this->find_field_by_name( $fields, $field_name );
			if ( $found !== null ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * Find field config by name in a flat or nested list (e.g. group sub_fields).
	 *
	 * @param array $fields   Array of field configs.
	 * @param string $name   Field name.
	 * @return array|null    Field config or null.
	 */
	private function find_field_by_name( array $fields, string $name ): ?array {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( ( $field['name'] ?? '' ) === $name ) {
				return $field;
			}
			$sub = $field['sub_fields'] ?? [];
			if ( is_array( $sub ) && ! empty( $sub ) ) {
				$found = $this->find_field_by_name( $sub, $name );
				if ( $found !== null ) {
					return $found;
				}
			}
			$layouts = $field['layouts'] ?? [];
			if ( is_array( $layouts ) && ! empty( $layouts ) ) {
				foreach ( $layouts as $layout ) {
					$layout_sub = $layout['sub_fields'] ?? [];
					if ( is_array( $layout_sub ) && ! empty( $layout_sub ) ) {
						$found = $this->find_field_by_name( $layout_sub, $name );
						if ( $found !== null ) {
							return $found;
						}
					}
				}
			}
		}
		return null;
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
			case 'page_link':
				return $this->process_page_link_value( $value, $field );
			case 'repeater':
				return $this->process_repeater_value( $value, $field );
			case 'flexible_content':
				return $this->process_flexible_value( $value, $field );
			case 'group':
				return $this->process_group_value( $value, $field );
			case 'component_field':
				return $this->process_component_value( $value, $field );
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
		// Relationship is always multi-value; post_object can be multiple.
		$multiple = ! empty( $field['multiple'] ) || ( $field['type'] ?? '' ) === 'relationship';
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

	/**
	 * Resolve page_link when value is a post payload (from source). URL strings pass through.
	 */
	private function process_page_link_value( mixed $value, array $field ): mixed {
		if ( ! is_array( $value ) || ( $value['type'] ?? '' ) !== 'post' ) {
			return $value;
		}
		$post_type = 'page';
		if ( ! empty( $field['post_type'] ) && is_array( $field['post_type'] ) ) {
			$post_type = $field['post_type'][0] ?? 'page';
		} elseif ( ! empty( $field['post_type'] ) && is_string( $field['post_type'] ) ) {
			$post_type = $field['post_type'];
		}
		$resolved = SF_Sync_Mapper::resolve_post_object( $value, $post_type );
		return $resolved > 0 ? $resolved : $value;
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

	/**
	 * Process a group field: value is associative array keyed by sub_field name.
	 * Recursively process each sub_field so nested flexible_content, image, etc. are resolved.
	 */
	private function process_group_value( mixed $value, array $field ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$sub_fields = $field['sub_fields'] ?? [];
		$out       = [];
		foreach ( $sub_fields as $sub ) {
			$name = $sub['name'] ?? '';
			if ( $name === '' || ! array_key_exists( $name, $value ) ) {
				continue;
			}
			$out[ $name ] = $this->process_value( $value[ $name ], $sub );
		}
		return $out;
	}

	/**
	 * Process ACF component field (embedded field group). Resolves nested attachments/post_object.
	 */
	private function process_component_value( mixed $value, array $field ): mixed {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$group_key = $field['field_group_key'] ?? '';
		if ( $group_key === '' || ! function_exists( 'acf_get_fields' ) ) {
			return $value;
		}
		$sub_fields = acf_get_fields( $group_key );
		if ( ! is_array( $sub_fields ) ) {
			return $value;
		}
		$out = [];
		foreach ( $sub_fields as $sub ) {
			$name = $sub['name'] ?? '';
			if ( $name === '' || ! array_key_exists( $name, $value ) ) {
				continue;
			}
			$out[ $name ] = $this->process_value( $value[ $name ], $sub );
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
	 * Runs retry passes for skipped fields so conditionally visible fields
	 * (e.g. dependent on a true_false, or chained A→B→C) get applied after their controlling field is set.
	 *
	 * @param array<string, mixed> $acf_payload Top-level ACF key => value from source.
	 */
	public function update_all_acf_from_payload( array $acf_payload ): void {
		foreach ( $acf_payload as $field_name => $value ) {
			$this->update_field_from_payload( $field_name, $value );
		}
		$max_passes = 5;
		for ( $pass = 0; $pass < $max_passes; $pass++ ) {
			$to_retry = $this->skipped;
			if ( empty( $to_retry ) ) {
				break;
			}
			$resolved_any = false;
			foreach ( $to_retry as $field_name ) {
				if ( ! array_key_exists( $field_name, $acf_payload ) ) {
					continue;
				}
				$prev_skipped = $this->skipped;
				$this->skipped = [];
				$ok = $this->update_field_from_payload( $field_name, $acf_payload[ $field_name ] );
				if ( $ok ) {
					$this->skipped = array_values( array_filter( $prev_skipped, fn( string $n ): bool => $n !== $field_name ) );
					$resolved_any = true;
				} else {
					$this->skipped = $prev_skipped;
				}
			}
			if ( ! $resolved_any ) {
				break;
			}
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
