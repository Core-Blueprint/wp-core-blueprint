<?php
declare(strict_types=1);
/**
 * Compliance Resource Registry.
 *
 * Base and registered Core Blueprint extensions declare stable compliance
 * resource roles here. Assignments are site-owned configuration and live in
 * Repository; software-defined roles themselves are immutable to the user.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Compliance;

use CoreBlueprint\Core\ExtensionRegistry;

defined( 'ABSPATH' ) || exit;

final class ResourceRegistry {

	public const BASE_OWNER = 'core-blueprint';

	/** @var array<string,array{key:string,owner:string,id:string,label:string,description:string,custom:bool}> */
	private static array $definitions = [];

	private static bool $collected = false;

	/** Register the collection lifecycle after extension identity is available. */
	public static function init(): void {
		add_action( 'init', [ self::class, 'collect' ], 15 );
	}

	/**
	 * Register one extension-owned compliance resource role.
	 *
	 * Registration is accepted only during cb_core_register_compliance_resources
	 * and only for a currently registered Core Blueprint extension identity.
	 *
	 * @param string              $owner      ExtensionRegistry id.
	 * @param string              $id         Owner-local lower-case kebab-case id.
	 * @param array<string,mixed> $definition Resource metadata.
	 */
	public static function register( string $owner, string $id, array $definition ): bool {
		if ( ! doing_action( 'cb_core_register_compliance_resources' ) ) {
			self::diagnostic( 'Compliance resource registration refused outside cb_core_register_compliance_resources.' );
			return false;
		}
		if ( self::BASE_OWNER === $owner || null === ExtensionRegistry::definition( $owner ) ) {
			self::diagnostic( sprintf( 'Unknown compliance resource owner refused: %s.', $owner ) );
			return false;
		}
		return self::register_definition( $owner, $id, $definition, false );
	}

	/** Collect Base roles and extension contributions exactly once per request. */
	public static function collect(): void {
		if ( self::$collected ) {
			return;
		}

		// Extension identity is authoritative for third-party ownership.
		ExtensionRegistry::collect();
		self::$collected = true;

		self::register_definition(
			self::BASE_OWNER,
			'privacy-policy',
			[
				'label'       => __( 'Privacy Policy', 'core-blueprint' ),
				'description' => __( 'Privacy and personal-data information published by your organisation.', 'core-blueprint' ),
			],
			true
		);
		self::register_definition(
			self::BASE_OWNER,
			'disclaimer',
			[
				'label'       => __( 'Disclaimer', 'core-blueprint' ),
				'description' => __( 'General limitations, notices or responsibility statements for the site.', 'core-blueprint' ),
			],
			true
		);
		self::register_definition(
			self::BASE_OWNER,
			'terms-and-conditions',
			[
				'label'       => __( 'Terms & Conditions', 'core-blueprint' ),
				'description' => __( 'General terms or conditions that apply to use of the site or organisation services.', 'core-blueprint' ),
			],
			true
		);

		/**
		 * Register compliance resource roles contributed by active extensions.
		 *
		 * Extensions call ResourceRegistry::register() inside this action.
		 */
		do_action( 'cb_core_register_compliance_resources' );
	}

	/** @return array<string,array{key:string,owner:string,id:string,label:string,description:string,custom:bool}> */
	public static function software_definitions(): array {
		self::ensure_collected();
		return self::$definitions;
	}

	/**
	 * Return software-defined and site-defined resource roles.
	 *
	 * @return array<string,array{key:string,owner:string,id:string,label:string,description:string,custom:bool}>
	 */
	public static function all(): array {
		self::ensure_collected();
		$all = self::$definitions;
		foreach ( Repository::custom_definitions() as $key => $definition ) {
			if ( isset( $all[ $key ] ) ) {
				self::diagnostic( sprintf( 'Custom compliance resource collision refused: %s.', $key ) );
				continue;
			}
			$all[ $key ] = $definition;
		}
		uasort(
			$all,
			static function ( array $a, array $b ): int {
				if ( $a['owner'] === $b['owner'] ) {
					return strcasecmp( $a['label'], $b['label'] );
				}
				if ( self::BASE_OWNER === $a['owner'] ) {
					return -1;
				}
				if ( self::BASE_OWNER === $b['owner'] ) {
					return 1;
				}
				return strcasecmp( self::owner_label( $a['owner'] ), self::owner_label( $b['owner'] ) );
			}
		);
		return $all;
	}

	/** @return array{key:string,owner:string,id:string,label:string,description:string,custom:bool}|null */
	public static function get( string $key ): ?array {
		$key = trim( $key );
		if ( '' === $key ) {
			return null;
		}
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Build the stable owner-qualified resource key used by storage and shortcodes. */
	public static function key( string $owner, string $id ): string {
		return $owner . ':' . $id;
	}

	/** Whether an owner may receive new user-defined resource roles. */
	public static function owner_exists( string $owner ): bool {
		if ( self::BASE_OWNER === $owner ) {
			return true;
		}
		return null !== ExtensionRegistry::definition( $owner );
	}

	/** Human-readable owner name for grouping in the central Compliance screen. */
	public static function owner_label( string $owner ): string {
		if ( self::BASE_OWNER === $owner ) {
			return __( 'Core Blueprint', 'core-blueprint' );
		}

		$definition = ExtensionRegistry::definition( $owner );
		if ( null !== $definition ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins = get_plugins();
			$file    = (string) $definition['plugin_file'];
			if ( isset( $plugins[ $file ]['Name'] ) ) {
				$name = trim( wp_strip_all_tags( (string) $plugins[ $file ]['Name'] ) );
				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		return ucwords( str_replace( '-', ' ', $owner ) );
	}

	/** Reset request-local state for tests. */
	public static function _reset_for_testing(): void {
		self::$definitions = [];
		self::$collected   = false;
	}

	/** @param array<string,mixed> $definition */
	private static function register_definition( string $owner, string $id, array $definition, bool $base_owned ): bool {
		if ( $base_owned !== ( self::BASE_OWNER === $owner ) ) {
			self::diagnostic( 'Compliance resource ownership boundary mismatch.' );
			return false;
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $id ) ) {
			self::diagnostic( sprintf( 'Invalid compliance resource id refused: %s.', $id ) );
			return false;
		}
		if ( str_starts_with( $id, 'custom-' ) ) {
			self::diagnostic( sprintf( 'Reserved compliance resource id refused: %s.', $id ) );
			return false;
		}

		$unknown = array_diff( array_keys( $definition ), [ 'label', 'description' ] );
		if ( [] !== $unknown ) {
			self::diagnostic( sprintf( 'Compliance resource %s contains unknown metadata.', $id ) );
			return false;
		}

		$label = isset( $definition['label'] ) && is_string( $definition['label'] )
			? trim( wp_strip_all_tags( $definition['label'] ) )
			: '';
		if ( '' === $label ) {
			self::diagnostic( sprintf( 'Compliance resource %s requires a label.', $id ) );
			return false;
		}
		$description = isset( $definition['description'] ) && is_string( $definition['description'] )
			? trim( wp_strip_all_tags( $definition['description'] ) )
			: '';

		$key = self::key( $owner, $id );
		if ( isset( self::$definitions[ $key ] ) ) {
			self::diagnostic( sprintf( 'Duplicate compliance resource refused: %s.', $key ) );
			return false;
		}

		self::$definitions[ $key ] = [
			'key'         => $key,
			'owner'       => $owner,
			'id'          => $id,
			'label'       => $label,
			'description' => $description,
			'custom'      => false,
		];
		return true;
	}

	private static function ensure_collected(): void {
		if ( self::$collected ) {
			return;
		}
		if ( did_action( 'init' ) > 0 || doing_action( 'init' ) ) {
			self::collect();
		}
	}

	private static function diagnostic( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Core Blueprint Compliance] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- developer diagnostic only.
		}
	}
}
