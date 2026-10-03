<?php
declare(strict_types=1);
/**
 * Base-owned Core Setup section metadata.
 *
 * Checks remain authoritative for membership. This registry owns only the
 * stable section identities, order and internal fallback labels.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup;

defined( 'ABSPATH' ) || exit;

final class SectionRegistry {

	/** @return array<string,array{label:string,order:int}> */
	public static function all(): array {
		return [
			'environment-availability' => [ 'label' => __( 'Environment & availability', 'core-blueprint' ), 'order' => 10 ],
			'administrator-recovery'   => [ 'label' => __( 'Administrator & recovery', 'core-blueprint' ),   'order' => 20 ],
			'safeguards'               => [ 'label' => __( 'Safeguards', 'core-blueprint' ),                 'order' => 30 ],
			'operations'               => [ 'label' => __( 'Operations', 'core-blueprint' ),                 'order' => 40 ],
			'mail'                     => [ 'label' => __( 'Mail', 'core-blueprint' ),                       'order' => 50 ],
			'privacy-governance'       => [ 'label' => __( 'Privacy & governance', 'core-blueprint' ),       'order' => 60 ],
			'cms-tools'                => [ 'label' => __( 'CMS tools', 'core-blueprint' ),                  'order' => 70 ],
		];
	}

	/** @return array{label:string,order:int}|null */
	public static function get( string $section_id ): ?array {
		$definition = self::all()[ $section_id ] ?? null;
		return is_array( $definition ) ? $definition : null;
	}

	public static function is_known( string $section_id ): bool {
		return null !== self::get( $section_id );
	}

	/**
	 * Section annotations can contain operational context spanning every check
	 * in that section, so editing/reading them requires access to all members.
	 */
	public static function can_manage_note( string $section_id ): bool {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_known( $section_id ) ) {
			return false;
		}

		$sections = Registry::sections();
		$checks = $sections[ $section_id ] ?? [];
		if ( [] === $checks ) {
			return false;
		}

		foreach ( $checks as $check ) {
			if ( ! $check instanceof CheckInterface || ! current_user_can( $check->capability() ) ) {
				return false;
			}
		}

		return true;
	}

	private function __construct() {}
}
