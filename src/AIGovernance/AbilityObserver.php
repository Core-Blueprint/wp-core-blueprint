<?php
declare(strict_types=1);
/**
 * WordPress Abilities observability adapter.
 *
 * WP 7.0 provides authorized-start and successful-completion actions. WP 7.1
 * adds invocation and intermediate result filters that permit materially richer
 * evidence. Callbacks intentionally use only the common argument subset for the
 * 6.9/7.0 before/after actions so the same code is signature-safe on 7.1.
 *
 * Observation is always best-effort: no logging/storage failure is allowed to
 * change an Ability's input, permissions, callback result or execution flow.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CB\Core\AIGovernance;

defined( 'ABSPATH' ) || exit;

final class AbilityObserver {
	/** @var array<string,array<int,array{id:string,start_ns:int,capture_state:string,evidence:array<string,mixed>,error_code:?string}>> */
	private static array $stacks = [];

	public static function boot(): void {
		add_action( 'wp_before_execute_ability', [ __CLASS__, 'on_before_execute' ], 10, 2 );
		add_action( 'wp_after_execute_ability', [ __CLASS__, 'on_after_execute' ], 10, 3 );
		add_action( 'shutdown', [ __CLASS__, 'flush_open' ], PHP_INT_MAX );

		global $wp_version;
		if ( ! is_string( $wp_version ) || version_compare( $wp_version, '7.1', '<' ) ) {
			return;
		}

		add_action( 'wp_ability_invoked', [ __CLASS__, 'on_invoked' ], 10, 3 );
		add_filter( 'wp_pre_execute_ability', [ __CLASS__, 'on_pre_execute' ], PHP_INT_MAX, 4 );
		add_filter( 'wp_ability_normalize_input', [ __CLASS__, 'on_normalize_input' ], PHP_INT_MAX, 3 );
		add_filter( 'wp_ability_validate_input', [ __CLASS__, 'on_validate_input' ], PHP_INT_MAX, 3 );
		add_filter( 'wp_ability_permission_result', [ __CLASS__, 'on_permission_result' ], PHP_INT_MAX, 4 );
		add_filter( 'wp_ability_execute_result', [ __CLASS__, 'on_execute_result' ], PHP_INT_MAX, 4 );
		add_filter( 'wp_ability_validate_output', [ __CLASS__, 'on_validate_output' ], PHP_INT_MAX, 3 );
	}

	public static function on_invoked( string $ability_name, mixed $input, mixed $ability = null ): void {
		unset( $ability );
		self::observe( static function () use ( $ability_name, $input ): void {
			self::open( $ability_name, 'invoked', [ 'arguments_shape' => Privacy::summarize( $input ) ] );
		} );
	}

	public static function on_before_execute( string $ability_name, mixed $input ): void {
		self::observe( static function () use ( $ability_name, $input ): void {
			if ( ! self::has_current( $ability_name ) ) {
				self::open( $ability_name, 'authorized', [ 'arguments_shape' => Privacy::summarize( $input ) ] );
				return;
			}
			self::touch( $ability_name, 'authorized', [ 'normalized_arguments_shape' => Privacy::summarize( $input ) ] );
		} );
	}

	public static function on_after_execute( string $ability_name, mixed $input, mixed $result ): void {
		unset( $input );
		self::observe( static function () use ( $ability_name, $result ): void {
			self::finish( $ability_name, 'succeeded', 'completed', [ 'result_shape' => Privacy::summarize( $result ) ] );
		} );
	}

	public static function on_pre_execute( mixed $pre, string $ability_name, mixed $input, mixed $ability = null ): mixed {
		unset( $input, $ability );
		self::observe( static function () use ( $pre, $ability_name ): void {
			$is_sentinel = is_object( $pre ) && 'WP_Filter_Sentinel' === get_class( $pre );
			if ( ! $is_sentinel ) {
				self::finish( $ability_name, 'short-circuited', 'short-circuited', [ 'result_shape' => Privacy::summarize( $pre ) ] );
			}
		} );
		return $pre;
	}

	public static function on_normalize_input( mixed $input, string $ability_name, mixed $ability = null ): mixed {
		unset( $ability );
		self::observe( static function () use ( $input, $ability_name ): void {
			if ( is_wp_error( $input ) ) {
				self::finish( $ability_name, 'invalid', 'normalized', [ 'normalization_result' => Privacy::summarize( $input ) ], self::first_error_code( $input ) );
				return;
			}
			self::touch( $ability_name, 'normalized', [ 'normalized_arguments_shape' => Privacy::summarize( $input ) ] );
		} );
		return $input;
	}

	public static function on_validate_input( mixed $valid, mixed $input, string $ability_name ): mixed {
		unset( $input );
		self::observe( static function () use ( $valid, $ability_name ): void {
			if ( false === $valid || is_wp_error( $valid ) ) {
				$error = is_wp_error( $valid ) ? self::first_error_code( $valid ) : 'ability_invalid_input';
				self::finish( $ability_name, 'invalid', 'input-validation', [ 'argument_validation' => Privacy::summarize( $valid ) ], $error );
				return;
			}
			self::touch( $ability_name, 'input-validated', [ 'argument_validation' => [ 'type' => 'passed' ] ] );
		} );
		return $valid;
	}

	public static function on_permission_result( mixed $permission, string $ability_name, mixed $input, mixed $ability = null ): mixed {
		unset( $input, $ability );
		self::observe( static function () use ( $permission, $ability_name ): void {
			if ( true !== $permission ) {
				$error = is_wp_error( $permission ) ? self::first_error_code( $permission ) : 'ability_permission_denied';
				self::finish( $ability_name, 'denied', 'permission-checked', [ 'permission_result' => Privacy::summarize( $permission ) ], $error );
				return;
			}
			self::touch( $ability_name, 'authorized', [ 'permission_result' => [ 'type' => 'granted' ] ] );
		} );
		return $permission;
	}

	public static function on_execute_result( mixed $result, string $ability_name, mixed $input, mixed $ability = null ): mixed {
		unset( $input, $ability );
		self::observe( static function () use ( $result, $ability_name ): void {
			if ( is_wp_error( $result ) ) {
				self::finish( $ability_name, 'failed', 'callback-result', [ 'result_shape' => Privacy::summarize( $result ) ], self::first_error_code( $result ) );
				return;
			}
			self::touch( $ability_name, 'callback-result', [ 'result_shape' => Privacy::summarize( $result ) ] );
		} );
		return $result;
	}

	public static function on_validate_output( mixed $valid, mixed $output, string $ability_name ): mixed {
		unset( $output );
		self::observe( static function () use ( $valid, $ability_name ): void {
			if ( false === $valid || is_wp_error( $valid ) ) {
				$error = is_wp_error( $valid ) ? self::first_error_code( $valid ) : 'ability_invalid_output';
				self::finish( $ability_name, 'invalid', 'output-validation', [ 'result_validation' => Privacy::summarize( $valid ) ], $error );
				return;
			}
			self::touch( $ability_name, 'output-validated', [ 'result_validation' => [ 'type' => 'passed' ] ] );
		} );
		return $valid;
	}

	public static function flush_open(): void {
		self::observe( static function (): void {
			foreach ( self::$stacks as $ability_name => $frames ) {
				foreach ( $frames as $frame ) {
					Repository::update( $frame['id'], [
						'capture_state' => $frame['capture_state'],
						'evidence'      => $frame['evidence'],
						'error_code'    => $frame['error_code'],
					] );
					TraceContext::leave( $frame['id'] );
				}
				unset( self::$stacks[ $ability_name ] );
			}
		} );
	}

	/** @param array<string,mixed> $evidence */
	private static function open( string $ability_name, string $capture_state, array $evidence ): void {
		$ability_name = substr( sanitize_text_field( $ability_name ), 0, 190 );
		if ( '' === $ability_name ) {
			return;
		}
		$source = SourceContext::detect();
		$link = TraceContext::link();
		$activity_id = wp_generate_uuid4();
		global $wp_version;
		$evidence = array_replace_recursive(
			[
				'capture' => [
					'platform'   => 'wordpress-abilities',
					'wp_version' => is_string( $wp_version ) ? $wp_version : 'unknown',
				],
			],
			$source['evidence'],
			$evidence
		);
		$id = Repository::insert( [
			'activity_id'       => $activity_id,
			'correlation_id'    => $link['correlation_id'],
			'parent_activity_id' => $link['parent_activity_id'],
			'operation_type'    => 'ability',
			'operation'      => $ability_name,
			'transport'      => $source['transport'],
			'source_id'      => $source['source_id'],
			'source_label'   => $source['source_label'],
			'outcome'        => 'unknown',
			'capture_state'  => $capture_state,
			'evidence'       => $evidence,
		] );
		if ( false === $id ) {
			return;
		}
		TraceContext::enter( $id, $link['correlation_id'] );
		self::$stacks[ $ability_name ] ??= [];
		self::$stacks[ $ability_name ][] = [
			'id'            => $id,
			'start_ns'      => hrtime( true ),
			'capture_state' => $capture_state,
			'evidence'      => $evidence,
			'error_code'    => null,
		];
	}

	/** @param array<string,mixed> $evidence */
	private static function touch( string $ability_name, string $capture_state, array $evidence ): void {
		$index = self::current_index( $ability_name );
		if ( null === $index ) {
			return;
		}
		self::$stacks[ $ability_name ][ $index ]['capture_state'] = $capture_state;
		self::$stacks[ $ability_name ][ $index ]['evidence'] = array_replace_recursive(
			self::$stacks[ $ability_name ][ $index ]['evidence'],
			$evidence
		);
	}

	/** @param array<string,mixed> $evidence */
	private static function finish( string $ability_name, string $outcome, string $capture_state, array $evidence, ?string $error_code = null ): void {
		$index = self::current_index( $ability_name );
		if ( null === $index ) {
			return;
		}
		$frame = self::$stacks[ $ability_name ][ $index ];
		array_pop( self::$stacks[ $ability_name ] );
		if ( [] === self::$stacks[ $ability_name ] ) {
			unset( self::$stacks[ $ability_name ] );
		}
		$duration_ms = max( 0, (int) round( ( hrtime( true ) - $frame['start_ns'] ) / 1_000_000 ) );
		Repository::update( $frame['id'], [
			'outcome'       => $outcome,
			'capture_state' => $capture_state,
			'duration_ms'   => $duration_ms,
			'error_code'    => $error_code,
			'evidence'      => array_replace_recursive( $frame['evidence'], $evidence ),
			'completed_at'  => gmdate( 'Y-m-d H:i:s' ),
		] );
		TraceContext::leave( $frame['id'] );
	}

	private static function has_current( string $ability_name ): bool {
		return null !== self::current_index( $ability_name );
	}

	private static function current_index( string $ability_name ): ?int {
		if ( empty( self::$stacks[ $ability_name ] ) ) {
			return null;
		}
		return count( self::$stacks[ $ability_name ] ) - 1;
	}

	private static function first_error_code( \WP_Error $error ): ?string {
		$codes = $error->get_error_codes();
		return isset( $codes[0] ) ? substr( sanitize_key( (string) $codes[0] ), 0, 100 ) : null;
	}

	private static function observe( callable $callback ): void {
		try {
			$callback();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/** @internal */
	public static function reset_for_tests(): void {
		self::$stacks = [];
		TraceContext::reset_for_tests();
	}
}