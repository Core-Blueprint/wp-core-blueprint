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

namespace CB\Core\Setup;

defined( 'ABSPATH' ) || exit;

final class SectionRegistry {

	/** @return array<string,array{label:string,order:int}> */
	public static function all(): array {
		return [
			'environment-availability' => [ 'label' => 'Environment & availability', 'order' => 10 ],
			'administrator-recovery'   => [ 'label' => 'Administrator & recovery',   'order' => 20 ],
			'safeguards'               => [ 'label' => 'Safeguards',                 'order' => 30 ],
			'operations'               => [ 'label' => 'Operations',                 'order' => 40 ],
			'mail'                     => [ 'label' => 'Mail',                       'order' => 50 ],
			'privacy-governance'       => [ 'label' => 'Privacy & governance',       'order' => 60 ],
			'cms-tools'                => [ 'label' => 'CMS tools',                  'order' => 70 ],
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
