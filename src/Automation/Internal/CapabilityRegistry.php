<?php
declare(strict_types=1);
/**
 * Internal storage and collection lifecycle for Automation Foundation.
 *
 * Public consumers use TriggerRegistry, ActionRegistry and StateRegistry. This
 * class keeps one collection pass so a provider can register all automation
 * capabilities from a single callback without duplicate lifecycle dispatch.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Automation\Internal;

use CoreBlueprint\Core\Automation\Schema;
use CoreBlueprint\Core\ExtensionRegistry;

defined( 'ABSPATH' ) || exit;

final class CapabilityRegistry {

	public const BASE_PROVIDER = 'core-blueprint';
	private const ID_PATTERN = '/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)+$/D';
	private const SCHEMA_VERSION_PATTERN = '/^[1-9][0-9]*$/D';

	/** @var array<string,array<string,mixed>> */
	private static array $triggers = [];
	/** @var array<string,array<string,mixed>> */
	private static array $actions = [];
	/** @var array<string,array<string,mixed>> */
	private static array $states = [];
	private static bool $collected = false;

	/**
	 * Whether the canonical Base init lifecycle has completed.
	 *
	 * ExtensionRegistry is collected on `init` priority 5. Automation Foundation
	 * must not pull that collection forward during plugin loading or an earlier
	 * init callback. The Foundation therefore becomes discoverable only after
	 * `init` has finished, preserving the existing extension-registration contract.
	 */
	public static function is_ready(): bool {
		return self::$collected || ( did_action( 'init' ) > 0 && ! doing_action( 'init' ) );
	}

	/** Collect registered capabilities once Base's canonical init lifecycle completed. */
	public static function collect(): bool {
		if ( self::$collected ) {
			return true;
		}
		if ( ! self::is_ready() ) {
			return false;
		}

		// Freeze before dispatch so recursive discovery cannot fire the public
		// registration lifecycle twice in one request.
		self::$collected = true;

		// ExtensionRegistry has already completed its canonical init collection.
		// Calling collect() again is idempotent and protects tests/manual contexts
		// without changing normal runtime timing.
		ExtensionRegistry::collect();
		do_action( 'cb_core_register_automation_capabilities' );
		return true;
	}

	/** @param array<string,mixed> $definition */
	public static function register_trigger( array $definition, bool $base_owned ): bool {
		if ( ! $base_owned && ! doing_action( 'cb_core_register_automation_capabilities' ) ) {
			self::diagnostic( 'Trigger registration refused outside cb_core_register_automation_capabilities.' );
			return false;
		}

		$normalized = self::normalize_trigger( $definition, $base_owned );
		if ( null === $normalized ) {
			return false;
		}

		$key = self::key( $normalized['provider'], $normalized['id'] );
		if ( isset( self::$triggers[ $key ] ) ) {
			self::diagnostic( sprintf( 'Duplicate automation trigger refused: %s.', $key ) );
			return false;
		}

		self::$triggers[ $key ] = $normalized;
		return true;
	}

	/** @param array<string,mixed> $definition */
	public static function register_action( array $definition, bool $base_owned ): bool {
		if ( ! $base_owned && ! doing_action( 'cb_core_register_automation_capabilities' ) ) {
			self::diagnostic( 'Action registration refused outside cb_core_register_automation_capabilities.' );
			return false;
		}

		$normalized = self::normalize_action( $definition, $base_owned );
		if ( null === $normalized ) {
			return false;
		}

		$key = self::key( $normalized['provider'], $normalized['id'] );
		if ( isset( self::$actions[ $key ] ) ) {
			self::diagnostic( sprintf( 'Duplicate automation action refused: %s.', $key ) );
			return false;
		}

		self::$actions[ $key ] = $normalized;
		return true;
	}

	/** @param array<string,mixed> $definition */
	public static function register_state( array $definition, bool $base_owned ): bool {
		if ( ! $base_owned && ! doing_action( 'cb_core_register_automation_capabilities' ) ) {
			self::diagnostic( 'State registration refused outside cb_core_register_automation_capabilities.' );
			return false;
		}

		$normalized = self::normalize_state( $definition, $base_owned );
		if ( null === $normalized ) {
			return false;
		}

		$key = self::key( $normalized['provider'], $normalized['id'] );
		if ( isset( self::$states[ $key ] ) ) {
			self::diagnostic( sprintf( 'Duplicate automation state capability refused: %s.', $key ) );
			return false;
		}

		self::$states[ $key ] = $normalized;
		return true;
	}

	/** @return array<string,array<string,mixed>> */
	public static function triggers(): array {
		self::collect();
		return self::$triggers;
	}

	/** @return array<string,array<string,mixed>> */
	public static function actions(): array {
		self::collect();
		return self::without_callback( self::$actions, 'executor' );
	}

	/** @return array<string,array<string,mixed>> */
	public static function states(): array {
		self::collect();
		return self::without_callback( self::$states, 'resolver' );
	}

	/** @return array<string,mixed>|null */
	public static function trigger( string $provider, string $id ): ?array {
		self::collect();
		$key = self::key_if_valid( $provider, $id );
		return null !== $key ? ( self::$triggers[ $key ] ?? null ) : null;
	}

	/** @return array<string,mixed>|null */
	public static function action( string $provider, string $id ): ?array {
		self::collect();
		$key = self::key_if_valid( $provider, $id );
		return self::public_definition( self::$actions, $key, 'executor' );
	}

	/** @return array<string,mixed>|null */
	public static function state( string $provider, string $id ): ?array {
		self::collect();
		$key = self::key_if_valid( $provider, $id );
		return self::public_definition( self::$states, $key, 'resolver' );
	}

	/** @internal Future orchestration runtime boundary; not public API in AF1. */
	public static function executor( string $provider, string $id ): ?callable {
		self::collect();
		return self::callback( self::$actions, self::key_if_valid( $provider, $id ), 'executor' );
	}

	/** @internal Future orchestration runtime boundary; not public API in AF2. */
	public static function state_resolver( string $provider, string $id ): ?callable {
		self::collect();
		return self::callback( self::$states, self::key_if_valid( $provider, $id ), 'resolver' );
	}

	/** @internal */
	public static function reset_for_tests(): void {
		self::$triggers = [];
		self::$actions = [];
		self::$states = [];
		self::$collected = false;
	}

	/** @param array<string,mixed> $definition @return array<string,mixed>|null */
	private static function normalize_trigger( array $definition, bool $base_owned ): ?array {
		$allowed = [ 'provider', 'id', 'label', 'description', 'schema_version', 'payload_schema' ];
		if ( [] !== array_diff( array_keys( $definition ), $allowed ) ) {
			return null;
		}

		$common = self::normalize_common( $definition, $base_owned );
		$schema = isset( $definition['payload_schema'] ) && is_array( $definition['payload_schema'] )
			? Schema::normalize( $definition['payload_schema'] )
			: null;
		if ( null === $common || null === $schema ) {
			return null;
		}

		return $common + [ 'payload_schema' => $schema ];
	}

	/** @param array<string,mixed> $definition @return array<string,mixed>|null */
	private static function normalize_action( array $definition, bool $base_owned ): ?array {
		$allowed = [ 'provider', 'id', 'label', 'description', 'schema_version', 'input_schema', 'output_schema', 'required_capability', 'executor' ];
		if ( [] !== array_diff( array_keys( $definition ), $allowed ) ) {
			return null;
		}

		$common = self::normalize_common( $definition, $base_owned );
		$input = isset( $definition['input_schema'] ) && is_array( $definition['input_schema'] )
			? Schema::normalize( $definition['input_schema'] )
			: null;
		$output_raw = $definition['output_schema'] ?? [];
		$output = is_array( $output_raw ) ? Schema::normalize( $output_raw ) : null;
		$capability = self::normalize_required_capability( $definition );
		$executor = $definition['executor'] ?? null;

		if (
			null === $common
			|| null === $input
			|| null === $output
			|| null === $capability
			|| ! is_callable( $executor )
		) {
			return null;
		}

		return $common + [
			'input_schema'        => $input,
			'output_schema'       => $output,
			'required_capability' => $capability,
			'executor'            => $executor,
		];
	}

	/** @param array<string,mixed> $definition @return array<string,mixed>|null */
	private static function normalize_state( array $definition, bool $base_owned ): ?array {
		$allowed = [ 'provider', 'id', 'label', 'description', 'schema_version', 'input_schema', 'output_schema', 'required_capability', 'resolver' ];
		if ( [] !== array_diff( array_keys( $definition ), $allowed ) ) {
			return null;
		}

		$common = self::normalize_common( $definition, $base_owned );
		$input_raw = $definition['input_schema'] ?? [];
		$output_raw = $definition['output_schema'] ?? null;
		$input = is_array( $input_raw ) ? Schema::normalize( $input_raw ) : null;
		$output = is_array( $output_raw ) ? Schema::normalize( $output_raw ) : null;
		$capability = self::normalize_required_capability( $definition );
		$resolver = $definition['resolver'] ?? null;

		if (
			null === $common
			|| null === $input
			|| null === $output
			|| [] === $output
			|| null === $capability
			|| ! is_callable( $resolver )
		) {
			return null;
		}

		return $common + [
			'input_schema'        => $input,
			'output_schema'       => $output,
			'required_capability' => $capability,
			'resolver'            => $resolver,
		];
	}

	/** @param array<string,mixed> $definition @return array<string,mixed>|null */
	private static function normalize_common( array $definition, bool $base_owned ): ?array {
		$provider = $base_owned
			? self::BASE_PROVIDER
			: ( isset( $definition['provider'] ) && is_string( $definition['provider'] ) ? trim( $definition['provider'] ) : '' );
		$id = isset( $definition['id'] ) && is_string( $definition['id'] ) ? trim( $definition['id'] ) : '';
		$label = isset( $definition['label'] ) && is_string( $definition['label'] )
			? trim( wp_strip_all_tags( $definition['label'] ) )
			: '';
		$description = isset( $definition['description'] ) && is_string( $definition['description'] )
			? trim( wp_strip_all_tags( $definition['description'] ) )
			: '';
		$schema_version = isset( $definition['schema_version'] ) && is_string( $definition['schema_version'] )
			? trim( $definition['schema_version'] )
			: '';

		if ( ! $base_owned ) {
			if (
				self::BASE_PROVIDER === $provider
				|| ! ExtensionRegistry::is_valid_id( $provider )
				|| null === ExtensionRegistry::definition( $provider )
			) {
				self::diagnostic( sprintf( 'Unknown automation capability provider refused: %s.', $provider ) );
				return null;
			}
		}

		if (
			1 !== preg_match( self::ID_PATTERN, $id )
			|| '' === $label
			|| strlen( $label ) > 120
			|| strlen( $description ) > 500
			|| 1 !== preg_match( self::SCHEMA_VERSION_PATTERN, $schema_version )
		) {
			return null;
		}

		return [
			'provider'       => $provider,
			'id'             => $id,
			'label'          => $label,
			'description'    => $description,
			'schema_version' => $schema_version,
		];
	}

	/** @param array<string,mixed> $definition */
	private static function normalize_required_capability( array $definition ): ?string {
		$capability = isset( $definition['required_capability'] ) && is_string( $definition['required_capability'] )
			? trim( $definition['required_capability'] )
			: '';
		return '' !== $capability && sanitize_key( $capability ) === $capability ? $capability : null;
	}

	private static function key( string $provider, string $id ): string {
		return $provider . '::' . $id;
	}

	private static function key_if_valid( string $provider, string $id ): ?string {
		$provider = trim( $provider );
		$id = trim( $id );
		if (
			( self::BASE_PROVIDER !== $provider && ! ExtensionRegistry::is_valid_id( $provider ) )
			|| 1 !== preg_match( self::ID_PATTERN, $id )
		) {
			return null;
		}
		return self::key( $provider, $id );
	}

	/**
	 * @param array<string,array<string,mixed>> $definitions
	 * @return array<string,array<string,mixed>>
	 */
	private static function without_callback( array $definitions, string $callback_key ): array {
		$out = [];
		foreach ( $definitions as $key => $definition ) {
			$public = $definition;
			unset( $public[ $callback_key ] );
			$out[ $key ] = $public;
		}
		return $out;
	}

	/**
	 * @param array<string,array<string,mixed>> $definitions
	 * @return array<string,mixed>|null
	 */
	private static function public_definition( array $definitions, ?string $key, string $callback_key ): ?array {
		if ( null === $key || ! isset( $definitions[ $key ] ) ) {
			return null;
		}
		$public = $definitions[ $key ];
		unset( $public[ $callback_key ] );
		return $public;
	}

	/** @param array<string,array<string,mixed>> $definitions */
	private static function callback( array $definitions, ?string $key, string $callback_key ): ?callable {
		if ( null === $key || ! isset( $definitions[ $key ][ $callback_key ] ) ) {
			return null;
		}
		$callback = $definitions[ $key ][ $callback_key ];
		return is_callable( $callback ) ? $callback : null;
	}

	private static function diagnostic( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Core Blueprint Automation Foundation] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
