<?php
declare(strict_types=1);
/**
 * Controlled public registry for Core Blueprint extensions.
 *
 * Extension identity and WordPress inventory location are deliberately separate:
 * `id` is the Core Blueprint platform identity, while `plugin_file` is the
 * canonical WordPress plugin basename used to resolve installed/active state.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core;

use CB\Core\Modules\ActivationRegistry;

defined( 'ABSPATH' ) || exit;

final class ExtensionRegistry {

	/** @var array<string,array{id:string,plugin_file:string,requires_api:string,requires_base:string,menu_url:string,status_id:string}> */
	private static array $definitions = [];

	/** @var array<string,string> plugin_file => extension id */
	private static array $plugin_owners = [];

	private static bool $collected = false;

	/** Register the canonical collection lifecycle. */
	public static function init(): void {
		add_action( 'init', [ self::class, 'collect' ], 5 );
	}

	/**
	 * Register one extension during `cb_core_register_extensions`.
	 *
	 * @param array<string,mixed> $definition
	 */
	public static function register( array $definition ): bool {
		if ( ! doing_action( 'cb_core_register_extensions' ) ) {
			self::diagnostic( 'Extension registration refused outside cb_core_register_extensions.' );
			return false;
		}

		$normalized = self::normalize( $definition );
		if ( null === $normalized ) {
			return false;
		}

		$id          = $normalized['id'];
		$plugin_file = $normalized['plugin_file'];
		if ( isset( self::$definitions[ $id ] ) || isset( self::$plugin_owners[ $plugin_file ] ) ) {
			self::diagnostic( sprintf( 'Duplicate extension registration refused: %s (%s).', $id, $plugin_file ) );
			return false;
		}

		self::$definitions[ $id ]            = $normalized;
		self::$plugin_owners[ $plugin_file ] = $id;
		return true;
	}

	/** @return array<string,array{id:string,plugin_file:string,requires_api:string,requires_base:string,menu_url:string,status_id:string}> */
	public static function definitions(): array {
		if ( ! self::$collected && ( did_action( 'init' ) > 0 || doing_action( 'init' ) ) ) {
			self::collect();
		}
		return self::$definitions;
	}

	/**
	 * Return the current Base-owned extension inventory projection.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function snapshot(): array {
		return Extensions::detected();
	}

	/** @return array<string,mixed>|null */
	public static function get( string $id ): ?array {
		if ( ! self::is_valid_id( $id ) ) {
			return null;
		}
		$snapshot = self::snapshot();
		return isset( $snapshot[ $id ] ) && is_array( $snapshot[ $id ] ) ? $snapshot[ $id ] : null;
	}

	/** @return array{id:string,plugin_file:string,requires_api:string,requires_base:string,menu_url:string,status_id:string}|null */
	public static function definition( string $id ): ?array {
		if ( ! self::is_valid_id( $id ) ) {
			return null;
		}
		$definitions = self::definitions();
		return $definitions[ $id ] ?? null;
	}

	/**
	 * Return display-safe developer and provenance metadata for one registered extension.
	 *
	 * `first_party` is derived from the same reserved identity invariant enforced
	 * during registration. Callers cannot promote themselves to first-party by
	 * supplying settings metadata.
	 *
	 * @return array{id:string,plugin_file:string,developer_name:string,developer_url:string,first_party:bool}|null
	 */
	public static function identity( string $id ): ?array {
		$definition = self::definition( $id );
		if ( null === $definition ) {
			return null;
		}

		$plugin_data = self::plugin_data( $definition['plugin_file'] );
		if ( null === $plugin_data ) {
			return null;
		}

		$developer_name = trim( wp_strip_all_tags( (string) ( $plugin_data['Author'] ?? '' ) ) );
		if ( '' === $developer_name ) {
			return null;
		}

		$developer_url = isset( $plugin_data['AuthorURI'] ) && is_string( $plugin_data['AuthorURI'] )
			? esc_url_raw( $plugin_data['AuthorURI'] )
			: '';

		return [
			'id'             => $id,
			'plugin_file'    => $definition['plugin_file'],
			'developer_name' => $developer_name,
			'developer_url'  => $developer_url,
			'first_party'    => self::is_first_party_definition( $id, $definition['plugin_file'], $plugin_data ),
		];
	}

	/** Whether Base recognizes this registered extension as official first-party software. */
	public static function is_first_party( string $id ): bool {
		$identity = self::identity( $id );
		return null !== $identity && true === $identity['first_party'];
	}

	public static function collect(): void {
		if ( self::$collected ) {
			return;
		}
		self::$collected = true;
		do_action( 'cb_core_register_extensions' );
	}

	public static function is_valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/', $id );
	}

	/** Reset request-local state for tests and explicit inventory invalidation. */
	public static function reset(): void {
		self::$definitions   = [];
		self::$plugin_owners = [];
		self::$collected     = false;
	}

	/** @param array<string,mixed> $definition
	 *  @return array{id:string,plugin_file:string,requires_api:string,requires_base:string,menu_url:string,status_id:string}|null
	 */
	private static function normalize( array $definition ): ?array {
		$id = isset( $definition['id'] ) && is_string( $definition['id'] ) ? trim( $definition['id'] ) : '';
		if ( ! self::is_valid_id( $id ) ) {
			self::diagnostic( 'Malformed extension id refused.' );
			return null;
		}

		$plugin_file = isset( $definition['plugin_file'] ) && is_string( $definition['plugin_file'] )
			? trim( wp_normalize_path( $definition['plugin_file'] ) )
			: '';
		if ( '' === $plugin_file || $plugin_file !== plugin_basename( $plugin_file ) || str_contains( $plugin_file, '\\' ) ) {
			self::diagnostic( sprintf( 'Invalid plugin_file for extension %s.', $id ) );
			return null;
		}

		$plugin_data = self::plugin_data( $plugin_file );
		if ( null === $plugin_data ) {
			self::diagnostic( sprintf( 'Unknown plugin_file for extension %s.', $id ) );
			return null;
		}

		if ( str_starts_with( $id, 'core-blueprint-' ) && ! self::is_first_party_definition( $id, $plugin_file, $plugin_data ) ) {
			self::diagnostic( sprintf( 'Reserved Core Blueprint extension id refused: %s.', $id ) );
			return null;
		}

		$requires_api = isset( $definition['requires_api'] ) && is_string( $definition['requires_api'] )
			? trim( $definition['requires_api'] )
			: '';
		if ( 1 !== preg_match( '/^\d+\.\d+$/', $requires_api ) ) {
			self::diagnostic( sprintf( 'Invalid requires_api for extension %s.', $id ) );
			return null;
		}

		$requires_base = isset( $definition['requires_base'] ) && is_string( $definition['requires_base'] )
			? trim( $definition['requires_base'] )
			: '';
		if ( '' !== $requires_base && 1 !== preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $requires_base ) ) {
			self::diagnostic( sprintf( 'Invalid requires_base for extension %s.', $id ) );
			return null;
		}

		$menu_url = isset( $definition['menu_url'] ) && is_string( $definition['menu_url'] )
			? esc_url_raw( $definition['menu_url'] )
			: '';
		if ( '' !== $menu_url ) {
			$menu_url = wp_validate_redirect( $menu_url, '' );
		}

		$status_id = isset( $definition['status_id'] ) && is_string( $definition['status_id'] )
			? trim( $definition['status_id'] )
			: '';
		if ( '' !== $status_id && ! ActivationRegistry::is_valid_id( $status_id ) ) {
			self::diagnostic( sprintf( 'Invalid status_id for extension %s.', $id ) );
			return null;
		}

		return [
			'id'            => $id,
			'plugin_file'   => $plugin_file,
			'requires_api'  => $requires_api,
			'requires_base' => $requires_base,
			'menu_url'      => $menu_url,
			'status_id'     => $status_id,
		];
	}

	/** @return array<string,mixed>|null */
	private static function plugin_data( string $plugin_file ): ?array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		return isset( $plugins[ $plugin_file ] ) && is_array( $plugins[ $plugin_file ] )
			? $plugins[ $plugin_file ]
			: null;
	}

	/** @param array<string,mixed> $plugin_data */
	private static function is_first_party_definition( string $id, string $plugin_file, array $plugin_data ): bool {
		if ( ! str_starts_with( $id, 'core-blueprint-' ) ) {
			return false;
		}
		$folder = dirname( $plugin_file );
		$author = trim( wp_strip_all_tags( (string) ( $plugin_data['Author'] ?? '' ) ) );
		return $folder === $id && 'Core Blueprint' === $author;
	}

	private static function diagnostic( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Core Blueprint ExtensionRegistry] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-only registry diagnostic.
		}
	}
}
