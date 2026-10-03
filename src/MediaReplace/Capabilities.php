<?php
declare(strict_types=1);
/**
 * Media Replace capability registration.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\MediaReplace;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	/** Site-wide authority to enable/disable and manage the Media Replace module. */
	public const MANAGE_MEDIA_REPLACE = 'cb_manage_media_replace';

	/** Attachment meta-capability resolved through WordPress' native edit_post mapping. */
	public const REPLACE_MEDIA = 'cb_replace_media';

	/** Register the attachment meta-capability mapper. */
	public static function init(): void {
		add_filter( 'map_meta_cap', [ __CLASS__, 'map_replace_media_cap' ], 10, 4 );
	}

	/**
	 * Resolve Media Replace use through WordPress' native attachment ownership rules.
	 *
	 * @param string[] $caps    Primitive capabilities WordPress currently requires.
	 * @param string   $cap     Requested capability.
	 * @param int      $user_id User being checked.
	 * @param mixed[]  $args    Meta-capability arguments; first item is attachment ID.
	 * @return string[]
	 */
	public static function map_replace_media_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( self::REPLACE_MEDIA !== $cap ) {
			return $caps;
		}

		$attachment_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		if ( $attachment_id <= 0 ) {
			return [ 'do_not_allow' ];
		}

		$attachment = get_post( $attachment_id );
		if ( ! ( $attachment instanceof \WP_Post ) || 'attachment' !== $attachment->post_type ) {
			return [ 'do_not_allow' ];
		}

		$required   = map_meta_cap( 'edit_post', $user_id, $attachment_id );
		$required[] = 'upload_files';

		return array_values( array_unique( array_map( 'strval', $required ) ) );
	}

	/**
	 * Register human-readable metadata in the existing User Roles catalog.
	 *
	 * @param array<string,array<string,mixed>> $catalog Existing catalog.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_catalog( array $catalog ): array {
		$catalog[ self::MANAGE_MEDIA_REPLACE ] = [
			'label'       => __( 'Manage Media Replace', 'core-blueprint' ),
			'group'       => __( 'Core Blueprint', 'core-blueprint' ),
			'source'      => 'Core Blueprint',
			'description' => __( 'Enable, disable and manage the site-wide Media Replace module.', 'core-blueprint' ),
		];

		// REPLACE_MEDIA is a meta-capability, not a role-assignable primitive.

		return $catalog;
	}
}
