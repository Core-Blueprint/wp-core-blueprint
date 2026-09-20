<?php
declare(strict_types=1);

namespace CB\Core\Profiles;

use CB\Core\Profiles\Sections\AIGovernanceSection;
use CB\Core\Profiles\Sections\ContentModelsSection;
use CB\Core\Profiles\Sections\IntegritySection;
use CB\Core\Profiles\Sections\MediaFormatsSection;
use CB\Core\Profiles\Sections\ModuleStatesSection;
use CB\Core\Profiles\Sections\NotesSection;
use CB\Core\Profiles\Sections\PermissionsSection;
use CB\Core\Profiles\Sections\PrivacySection;
use CB\Core\Profiles\Sections\ReportsSection;
use CB\Core\Profiles\Sections\SecuritySection;

defined( 'ABSPATH' ) || exit;

final class SectionRegistry {
	/** @var SectionInterface[]|null Test-only section injection. */
	private static ?array $testing_sections = null;

	/** @return array<string,SectionInterface> */
	public static function all(): array {
		$sections = [];
		foreach ( [
			new SecuritySection(),
			new PrivacySection(),
			new IntegritySection(),
			new ContentModelsSection(),
			new MediaFormatsSection(),
			new NotesSection(),
			new ReportsSection(),
			new PermissionsSection(),
			new AIGovernanceSection(),
		] as $section ) {
			$sections[ $section->id() ] = $section;
		}

		// Public v1 deliberately has no extension registration hook. Base can
		// therefore guarantee the portability/secrets boundary for every exported
		// section. A future public contract can be added after that trust boundary
		// has its own reviewed extension policy.
		if ( null !== self::$testing_sections ) {
			foreach ( self::$testing_sections as $section ) {
				if ( ! $section instanceof SectionInterface ) {
					continue;
				}
				$id = $section->id();
				if ( isset( $sections[ $id ] ) || 'module-states' === $id || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id ) ) {
					continue;
				}
				$sections[ $id ] = $section;
			}
		}

		// Base module activation is deliberately last so configuration is present
		// before any optional runtime is enabled.
		$activation = new ModuleStatesSection();
		$sections[ $activation->id() ] = $activation;
		return $sections;
	}

	public static function get( string $id ): ?SectionInterface {
		$sections = self::all();
		return $sections[ $id ] ?? null;
	}

	/** @internal Integration tests only. */
	public static function _set_for_testing( ?array $sections ): void {
		self::$testing_sections = $sections;
	}
}
