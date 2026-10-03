<?php
declare(strict_types=1);
/**
 * Governance event metadata registry.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CoreBlueprint\Core\Governance;

defined( 'ABSPATH' ) || exit;

final class EventRegistry {
	private const ID_PATTERN = '/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)+$/';
	private const STORAGE_MAX_LENGTH = 50;
	private const RESERVED_NAMESPACES = [
		'ai', 'audit', 'console', 'content', 'diagnostic', 'failsafe', 'integrity', 'login',
		'mail', 'media', 'module', 'note', 'notes', 'package', 'permissions', 'plugin',
		'reports', 'security', 'settings', 'snippet', 'snippets', 'system', 'ui', 'user', 'core',
	];

	/** @var array<string,array{id:string,label:string,storage_key:string,retention_category:?string}> */
	private static array $definitions = [];
	/** @var array<string,string> */
	private static array $storage_owners = [];
	private static bool $core_defaults_registered = false;

	/** @param array{id:string,label:string,retention_category?:string} $definition */
	public static function register( array $definition ): bool {
		if ( ! did_action( 'init' ) && ! doing_action( 'init' ) ) {
			return false;
		}
		return self::register_definition( $definition, false );
	}

	/** @internal @param array{id:string,label:string,retention_category?:string} $definition */
	public static function register_core( array $definition ): bool {
		return self::register_definition( $definition, true );
	}

	/** @internal @param array<string,string> $definitions */
	public static function register_core_many( array $definitions ): void {
		foreach ( $definitions as $id => $label ) {
			self::register_core( [ 'id' => (string) $id, 'label' => (string) $label ] );
		}
	}

	public static function is_valid_id( string $id ): bool {
		return 1 === preg_match( self::ID_PATTERN, $id ) && false !== self::storage_key( $id );
	}

	/** Public consumers may not emit events inside Base-owned namespaces. */
	public static function is_public_id( string $id ): bool {
		if ( ! self::is_valid_id( $id ) ) {
			return false;
		}
		$namespace = strstr( $id, '.', true );
		return is_string( $namespace ) && ! in_array( $namespace, self::RESERVED_NAMESPACES, true );
	}

	public static function storage_key( string $id ): string|false {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			return false;
		}
		$key = str_replace( '.', '_', $id );
		return '' !== $key && strlen( $key ) <= self::STORAGE_MAX_LENGTH ? $key : false;
	}

	/** @internal Claim the exact storage identity, including events without a registered label. */
	public static function claim( string $id ): bool {
		$key = self::storage_key( $id );
		if ( false === $key ) {
			return false;
		}
		$owner = self::$storage_owners[ $key ] ?? null;
		if ( null !== $owner && $owner !== $id ) {
			return false;
		}
		self::$storage_owners[ $key ] = $id;
		return true;
	}

	public static function label( string $event_type ): ?string {
		self::ensure_core_defaults();
		if ( isset( self::$definitions[ $event_type ] ) ) {
			return self::$definitions[ $event_type ]['label'];
		}
		$storage = self::normalize_existing_storage_key( $event_type );
		$owner   = self::$storage_owners[ $storage ] ?? null;
		return null !== $owner && isset( self::$definitions[ $owner ] )
			? self::$definitions[ $owner ]['label']
			: null;
	}

	public static function retention_category( string $event_type ): ?string {
		self::ensure_core_defaults();
		$definition = self::$definitions[ $event_type ] ?? null;
		if ( null === $definition ) {
			$storage = self::normalize_existing_storage_key( $event_type );
			$owner = self::$storage_owners[ $storage ] ?? null;
			$definition = null !== $owner ? ( self::$definitions[ $owner ] ?? null ) : null;
		}
		if ( ! is_array( $definition ) ) {
			return null;
		}
		$category = $definition['retention_category'] ?? null;
		return is_string( $category ) && RetentionPolicy::is_category( $category ) ? $category : null;
	}

	private static function register_definition( array $definition, bool $core_owned ): bool {
		if ( ! isset( $definition['id'], $definition['label'] ) || ! is_string( $definition['id'] ) || ! is_string( $definition['label'] ) ) {
			return false;
		}
		$id    = $definition['id'];
		$label = trim( $definition['label'] );
		$key   = self::storage_key( $id );
		if ( false === $key || '' === $label || isset( self::$definitions[ $id ] ) ) {
			return false;
		}
		$namespace = strstr( $id, '.', true );
		if ( ! $core_owned && in_array( $namespace, self::RESERVED_NAMESPACES, true ) ) {
			return false;
		}
		$retention_category = null;
		if ( array_key_exists( 'retention_category', $definition ) ) {
			$retention_category = is_string( $definition['retention_category'] ) ? $definition['retention_category'] : '';
			if ( ! RetentionPolicy::is_category( $retention_category ) ) {
				return false;
			}
		}
		$owner = self::$storage_owners[ $key ] ?? null;
		if ( null !== $owner && $owner !== $id ) {
			return false;
		}
		self::$storage_owners[ $key ] = $id;
		self::$definitions[ $id ] = [
			'id'                 => $id,
			'label'              => $label,
			'storage_key'        => $key,
			'retention_category' => $retention_category,
		];
		return true;
	}

	private static function ensure_core_defaults(): void {
		if ( self::$core_defaults_registered ) {
			return;
		}
		if ( ! did_action( 'init' ) && ! doing_action( 'init' ) ) {
			return;
		}
		self::$core_defaults_registered = true;
		self::register_core_many( [
			'plugin.activated' => __( 'Plugin activated', 'core-blueprint' ),
			'plugin.deactivated' => __( 'Plugin deactivated', 'core-blueprint' ),
			'settings.changed' => __( 'Settings changed', 'core-blueprint' ),
			'settings.module.toggled' => __( 'Module toggled', 'core-blueprint' ),
			'settings.feature.toggled' => __( 'Feature toggled', 'core-blueprint' ),
			'settings.defaults.applied' => __( 'Recommended defaults applied', 'core-blueprint' ),
			'settings.migrated' => __( 'Settings schema migrated', 'core-blueprint' ),
			'settings.migration.failed' => __( 'Settings schema migration failed', 'core-blueprint' ),
			'failsafe.emergency.activated' => __( 'Emergency bypass activated', 'core-blueprint' ),
			'failsafe.emergency.deactivated' => __( 'Emergency bypass deactivated', 'core-blueprint' ),
			'failsafe.window.closed' => __( 'Bypass window closed', 'core-blueprint' ),
			'failsafe.bypass.url.used' => __( 'Secret bypass URL used', 'core-blueprint' ),
			'failsafe.bypass.url.rejected' => __( 'Secret bypass URL rejected', 'core-blueprint' ),
			'failsafe.token.rotated' => __( 'Bypass token rotated', 'core-blueprint' ),
			'login.success' => __( 'Successful login', 'core-blueprint' ),
			'login.failed' => __( 'Failed login', 'core-blueprint' ),
			'diagnostic.header.test' => __( 'Header test run', 'core-blueprint' ),
			'audit.exported' => __( 'Audit log exported', 'core-blueprint' ),
			'audit.pruned' => __( 'Audit log pruned', 'core-blueprint' ),
			'audit.prune.failed' => __( 'Audit log prune failed', 'core-blueprint' ),
			'module.boot.failed' => __( 'Module boot failed', 'core-blueprint' ),
			// Historical Base-owned Snippets event identities remain registered so
			// audit rows written before the standalone extension extraction retain
			// their human-readable labels.
			'snippets.subsystem.enabled' => __( 'Snippets: subsystem enabled', 'core-blueprint' ),
			'snippets.subsystem.disabled' => __( 'Snippets: subsystem disabled', 'core-blueprint' ),
			'snippet.created' => __( 'Snippets: snippet created', 'core-blueprint' ),
			'snippet.updated' => __( 'Snippets: snippet updated', 'core-blueprint' ),
			'snippet.enabled' => __( 'Snippets: snippet enabled', 'core-blueprint' ),
			'snippet.disabled' => __( 'Snippets: snippet disabled', 'core-blueprint' ),
			'snippet.duplicated' => __( 'Snippets: snippet duplicated', 'core-blueprint' ),
			'snippet.deleted' => __( 'Snippets: snippet deleted', 'core-blueprint' ),
			'snippet.auto.disabled' => __( 'Snippets: snippet auto-disabled after runtime error', 'core-blueprint' ),
			'snippets.exported' => __( 'Snippets: snippets exported', 'core-blueprint' ),
			'snippets.imported' => __( 'Snippets: snippets imported', 'core-blueprint' ),
			'snippets.restore.acknowledged' => __( 'Snippets: restore backup and recovery responsibility acknowledged', 'core-blueprint' ),
			'security.password.reconfirm.failed' => __( 'Password re-confirm failed', 'core-blueprint' ),
			'ui.site.mode.changed' => __( 'Description mode (site default) changed', 'core-blueprint' ),
		] );
	}

	private static function normalize_existing_storage_key( string $raw ): string {
		return substr( sanitize_key( str_replace( '.', '_', $raw ) ), 0, self::STORAGE_MAX_LENGTH );
	}
}
