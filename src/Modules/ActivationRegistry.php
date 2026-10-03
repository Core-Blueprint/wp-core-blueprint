<?php
declare(strict_types=1);
/**
 * ActivationRegistry - canonical activation authority for optional Core Blueprint modules.
 *
 * A registered module supplies a State class implementing ModuleStateInterface plus the
 * capability required to mutate the master state. Public module identifiers are canonical
 * kebab-case. Unknown or invalid identifiers always fail closed.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Modules;

use CoreBlueprint\Core\ContentModels\State as ContentModelsState;
use CoreBlueprint\Core\Integrity\State as CoreScannerState;
use CoreBlueprint\Core\Mail\State as MailState;
use CoreBlueprint\Core\MediaFormats\State as MediaFormatsState;
use CoreBlueprint\Core\MediaReplace\Capabilities as MediaReplaceCapabilities;
use CoreBlueprint\Core\MediaReplace\State as MediaReplaceState;
use CoreBlueprint\Core\Notes\State as NotesState;
use CoreBlueprint\Core\PackageDownload\State as PackageDownloadState;
use CoreBlueprint\Core\Permissions\UserRolesState;
use CoreBlueprint\Core\Reports\State as ReportsState;
use CoreBlueprint\Core\Security\CoreShieldState;
use CoreBlueprint\Core\Security\LoginShieldState;
use CoreBlueprint\Core\Snippets\State as SnippetsState;

defined( 'ABSPATH' ) || exit;

final class ActivationRegistry {

	/** @return array<string,array{state:class-string<ModuleStateInterface>,capability:string}> */
	private static function built_in_definitions(): array {
		return [
			'login-shield' => [
				'state'      => LoginShieldState::class,
				'capability' => 'manage_options',
			],
			'core-shield' => [
				'state'      => CoreShieldState::class,
				'capability' => 'manage_options',
			],
			'core-scanner' => [
				'state'      => CoreScannerState::class,
				'capability' => 'cb_manage_integrity_policy',
			],
			'content-models' => [
				'state'      => ContentModelsState::class,
				'capability' => 'cb_manage_content_models',
			],
			'notes' => [
				'state'      => NotesState::class,
				'capability' => 'cb_manage_notes',
			],
			'reports' => [
				'state'      => ReportsState::class,
				'capability' => 'cb_manage_reports',
			],
			'mail' => [
				'state'      => MailState::class,
				'capability' => 'manage_options',
			],
			'media-replace' => [
				'state'      => MediaReplaceState::class,
				'capability' => MediaReplaceCapabilities::MANAGE_MEDIA_REPLACE,
			],
			'media-formats' => [
				'state'      => MediaFormatsState::class,
				'capability' => 'manage_options',
			],
			'package-downloads' => [
				'state'      => PackageDownloadState::class,
				'capability' => 'manage_options',
			],
			'user-roles' => [
				'state'      => UserRolesState::class,
				'capability' => 'cb_manage_roles',
			],
			'snippets' => [
				'state'      => SnippetsState::class,
				'capability' => 'cb_manage_snippets',
			],
		];
	}

	/**
	 * Return the validated activation map.
	 *
	 * Extensions may add canonical kebab-case IDs through the filter. Built-in IDs are
	 * reserved by Base and cannot be replaced by a filtered definition.
	 *
	 * @return array<string,array{state:class-string<ModuleStateInterface>,capability:string}>
	 */
	public static function definitions(): array {
		$built_ins = self::built_in_definitions();

		/**
		 * Filter the canonical module activation map.
		 *
		 * State classes must implement ModuleStateInterface. Base-owned IDs are reserved;
		 * extensions may append their own canonical kebab-case module IDs.
		 *
		 * @param array<string,array{state:class-string,capability:string}> $definitions
		 */
		$filtered = apply_filters( 'cb_core_module_activation_definitions', $built_ins );
		if ( ! is_array( $filtered ) ) {
			$filtered = $built_ins;
		}

		$out = [];
		foreach ( $built_ins as $id => $definition ) {
			$normalized = self::normalize_definition( $definition );
			if ( null !== $normalized ) {
				$out[ $id ] = $normalized;
			}
		}

		foreach ( $filtered as $id => $definition ) {
			$id = (string) $id;
			if ( isset( $built_ins[ $id ] ) || ! self::is_valid_id( $id ) || ! is_array( $definition ) ) {
				continue;
			}

			$normalized = self::normalize_definition( $definition );
			if ( null !== $normalized ) {
				$out[ $id ] = $normalized;
			}
		}

		return $out;
	}

	/** @return array{state:class-string<ModuleStateInterface>,capability:string}|null */
	public static function definition( string $id ): ?array {
		if ( ! self::is_valid_id( $id ) ) {
			return null;
		}

		$definition = self::definitions()[ $id ] ?? null;
		return is_array( $definition ) ? self::normalize_definition( $definition ) : null;
	}

	/** Whether a registered module is currently enabled. Unknown/error states fail closed. */
	public static function is_enabled( string $id ): bool {
		$definition = self::definition( $id );
		if ( null === $definition ) {
			return false;
		}

		$state = $definition['state'];
		try {
			return (bool) $state::is_enabled();
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'CB Modules\\ActivationRegistry [%s]: %s', $id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- last-resort state-provider diagnostic; activation fail-closed path must stay independent of AuditLog.
			return false;
		}
	}

	/** @return string[] */
	public static function slugs(): array {
		return array_keys( self::definitions() );
	}

	/** Public identifier validator: lower-case kebab-case only. */
	public static function is_valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id );
	}

	/** @return array{state:class-string<ModuleStateInterface>,capability:string}|null */
	private static function normalize_definition( array $definition ): ?array {
		$state      = isset( $definition['state'] ) && is_string( $definition['state'] ) ? $definition['state'] : '';
		$capability = isset( $definition['capability'] ) && is_string( $definition['capability'] )
			? sanitize_key( $definition['capability'] )
			: '';

		if ( '' === $state || '' === $capability || ! class_exists( $state ) ) {
			return null;
		}
		if ( ! is_subclass_of( $state, ModuleStateInterface::class ) ) {
			return null;
		}

		return [
			'state'      => $state,
			'capability' => $capability,
		];
	}
}
