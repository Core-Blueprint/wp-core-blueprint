<?php
declare(strict_types=1);

namespace CB\Core\Profiles;

use CB\Core\ExtensionRegistry;
use CB\Core\Profiles\Sections\AIGovernanceSection;
use CB\Core\Profiles\Sections\AuditNotificationsSection;
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
	/** @var array<string,SectionInterface> */
	private static array $extension_sections = [];

	private static bool $collected = false;
	private static bool $collecting = false;
	private static bool $initialized = false;

	/** @var SectionInterface[]|null Test-only section injection. */
	private static ?array $testing_sections = null;

	/**
	 * Register the controlled first-party Profile-section collection lifecycle.
	 *
	 * Extension inventory is collected at init priority 5. Profile sections
	 * follow at priority 6 so first-party ownership can be verified before a
	 * section is accepted.
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'init', [ self::class, 'collect' ], 6 );
	}

	/**
	 * Register one Profile section owned by an official first-party extension.
	 *
	 * Registration is accepted only during cb_core_register_profile_sections.
	 * The section ID must be namespaced below the canonical extension ID so Base
	 * can reserve its own section IDs and reject ambiguous ownership.
	 */
	public static function register( string $extension_id, SectionInterface $section ): bool {
		if ( ! self::$collecting || ! doing_action( 'cb_core_register_profile_sections' ) ) {
			self::diagnostic( 'Profile section registration refused outside cb_core_register_profile_sections.' );
			return false;
		}

		$extension_id = trim( $extension_id );
		if ( ! ExtensionRegistry::is_valid_id( $extension_id ) || ! ExtensionRegistry::is_first_party( $extension_id ) ) {
			self::diagnostic( sprintf( 'Profile section registration refused for untrusted extension: %s.', $extension_id ) );
			return false;
		}

		$id = $section->id();
		if (
			! self::is_valid_id( $id )
			|| ! str_starts_with( $id, $extension_id . '-' )
			|| 'module-states' === $id
		) {
			self::diagnostic( sprintf( 'Invalid or unowned Profile section id refused: %s.', $id ) );
			return false;
		}

		$built_ins = self::built_in_sections();
		if ( isset( $built_ins[ $id ] ) || isset( self::$extension_sections[ $id ] ) ) {
			self::diagnostic( sprintf( 'Duplicate Profile section registration refused: %s.', $id ) );
			return false;
		}

		// Leave one slot for the Base-owned module activation section.
		$maximum_extensions = Document::MAX_SECTIONS - count( $built_ins ) - 1;
		if ( count( self::$extension_sections ) >= $maximum_extensions ) {
			self::diagnostic( 'Profile section registration refused because the document section limit is exhausted.' );
			return false;
		}

		self::$extension_sections[ $id ] = $section;
		return true;
	}

	/**
	 * Collect extension sections exactly once for the current request.
	 *
	 * ExtensionRegistry owns first-party identity. This registry owns only the
	 * portable configuration contract and never stores extension settings.
	 */
	public static function collect(): void {
		if ( self::$collected || self::$collecting ) {
			return;
		}

		// Resolve the canonical extension inventory before opening the Profile
		// registration window. ExtensionRegistry itself is frozen after collect.
		ExtensionRegistry::definitions();

		self::$collecting = true;
		try {
			do_action( 'cb_core_register_profile_sections' );
			ksort( self::$extension_sections, SORT_STRING );
			self::$collected = true;
		} finally {
			self::$collecting = false;
		}
	}

	/** @return array<string,SectionInterface> */
	public static function all(): array {
		if ( ! self::$collected && ! self::$collecting && ( did_action( 'init' ) > 0 || doing_action( 'init' ) ) ) {
			self::collect();
		}

		$sections = self::built_in_sections();

		foreach ( self::$extension_sections as $id => $section ) {
			$sections[ $id ] = $section;
		}

		if ( null !== self::$testing_sections ) {
			foreach ( self::$testing_sections as $section ) {
				if ( ! $section instanceof SectionInterface ) {
					continue;
				}
				$id = $section->id();
				if ( isset( $sections[ $id ] ) || 'module-states' === $id || ! self::is_valid_id( $id ) ) {
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

	public static function is_valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id );
	}

	/** @return array<string,SectionInterface> */
	private static function built_in_sections(): array {
		$sections = [];
		foreach ( [
			new SecuritySection(),
			new PrivacySection(),
			new AuditNotificationsSection(),
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
		return $sections;
	}

	/** @internal Integration tests only. */
	public static function _set_for_testing( ?array $sections ): void {
		self::$testing_sections = $sections;
	}

	/** @internal Integration tests only. */
	public static function _reset_for_testing(): void {
		self::$extension_sections = [];
		self::$testing_sections = null;
		self::$collected = false;
		self::$collecting = false;
	}

	private static function diagnostic( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Core Blueprint Profiles] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only registry diagnostic.
		}
	}
}
