<?php
declare(strict_types=1);
/**
 * Canonical bounded Admin Notices audience policy.
 *
 * WordPress remains the notice registry and authorization source of truth.
 * This option stores only source-level presentation rules.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\AdminNotices;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Policy {

	public const OPTION  = 'cb_core_admin_notices_policy';
	public const VERSION = 1;

	public const EVERYONE       = 'everyone';
	public const OPERATORS_ONLY = 'operators_only';
	public const SELECTED       = 'selected';
	public const VISIBILITIES   = [ self::EVERYONE, self::OPERATORS_ONLY, self::SELECTED ];

	private const MAX_RULES = 100;

	/** @return array{version:int,rules:list<array{source:string,visibility:string,audience:array{roles:list<string>,capabilities:list<string>}}>} */
	public static function defaults(): array {
		return [
			'version' => self::VERSION,
			'rules'   => [],
		];
	}

	public static function get(): array {
		$stored = get_option( self::OPTION, null );
		if ( null === $stored || [] === $stored || ! is_array( $stored ) ) {
			return self::defaults();
		}
		try {
			return self::normalize( $stored );
		} catch ( \InvalidArgumentException ) {
			return self::defaults();
		}
	}

	public static function replace( array $candidate, string $actor = 'runtime' ): bool {
		$normalized = self::normalize( $candidate );
		$before = self::get();
		$stored = get_option( self::OPTION, null );

		if ( $before === $normalized ) {
			if ( self::defaults() === $normalized && null !== $stored ) {
				return delete_option( self::OPTION );
			}
			return true;
		}

		$changed = self::defaults() === $normalized
			? ( null === $stored || delete_option( self::OPTION ) )
			: update_option( self::OPTION, $normalized, false );

		if ( ! $changed ) {
			return false;
		}

		self::audit_mutation( 'ui.admin.notices.policy.changed', $actor, $before, $normalized );
		return true;
	}

	public static function reset( string $actor = 'runtime' ): bool {
		return self::replace( self::defaults(), $actor );
	}

	public static function has_restrictions(): bool {
		foreach ( self::get()['rules'] as $rule ) {
			if ( self::EVERYONE !== $rule['visibility'] ) {
				return true;
			}
		}
		return false;
	}

	/** @return array{source:string,visibility:string,audience:array{roles:list<string>,capabilities:list<string>}} */
	public static function rule_for( string $source_id ): array {
		foreach ( self::get()['rules'] as $rule ) {
			if ( $source_id === $rule['source'] ) {
				return $rule;
			}
		}
		return [
			'source'     => $source_id,
			'visibility' => self::EVERYONE,
			'audience'   => [ 'roles' => [], 'capabilities' => [] ],
		];
	}

	public static function normalize( array $candidate ): array {
		self::assert_exact_keys( $candidate, [ 'version', 'rules' ], 'policy' );
		if ( ! isset( $candidate['version'] ) || ! is_int( $candidate['version'] ) || self::VERSION !== $candidate['version'] ) {
			throw new \InvalidArgumentException( 'Admin Notices policy version is not supported.' );
		}

		$rules = $candidate['rules'] ?? null;
		if ( ! is_array( $rules ) || ! array_is_list( $rules ) || count( $rules ) > self::MAX_RULES ) {
			throw new \InvalidArgumentException( 'Admin Notices rule list is invalid.' );
		}

		$normalized = [];
		$seen = [];
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || array_is_list( $rule ) ) {
				throw new \InvalidArgumentException( 'Admin Notices rule is invalid.' );
			}
			self::assert_exact_keys( $rule, [ 'source', 'visibility', 'audience' ], 'rule' );

			$source = (string) ( $rule['source'] ?? '' );
			if ( ! SourceResolver::is_manageable_id( $source ) || isset( $seen[ $source ] ) ) {
				throw new \InvalidArgumentException( 'Admin Notices source identity is invalid or duplicated.' );
			}
			$seen[ $source ] = true;

			$visibility = (string) ( $rule['visibility'] ?? '' );
			if ( ! in_array( $visibility, self::VISIBILITIES, true ) ) {
				throw new \InvalidArgumentException( 'Admin Notices visibility is invalid.' );
			}

			if ( SourceResolver::is_protected_id( $source ) && self::EVERYONE !== $visibility ) {
				throw new \InvalidArgumentException( 'Protected Admin Notices sources must remain visible to everyone.' );
			}

			$audience_raw = $rule['audience'] ?? null;
			if ( ! is_array( $audience_raw ) || array_is_list( $audience_raw ) ) {
				throw new \InvalidArgumentException( 'Admin Notices audience is invalid.' );
			}
			$audience = Audience::normalize( $audience_raw );
			if ( self::SELECTED !== $visibility && ( [] !== $audience['roles'] || [] !== $audience['capabilities'] ) ) {
				throw new \InvalidArgumentException( 'Admin Notices audience is only valid for selected visibility.' );
			}
			if ( self::SELECTED === $visibility && [] === $audience['roles'] && [] === $audience['capabilities'] ) {
				throw new \InvalidArgumentException( 'Selected Admin Notices visibility requires at least one role or capability.' );
			}

			$normalized[] = [
				'source'     => $source,
				'visibility' => $visibility,
				'audience'   => self::SELECTED === $visibility
					? $audience
					: [ 'roles' => [], 'capabilities' => [] ],
			];
		}

		usort( $normalized, static fn( array $a, array $b ): int => strcmp( $a['source'], $b['source'] ) );

		return [
			'version' => self::VERSION,
			'rules'   => $normalized,
		];
	}

	private static function assert_exact_keys( array $value, array $allowed, string $name ): void {
		$actual = array_map( 'strval', array_keys( $value ) );
		$expected = array_values( array_map( 'strval', $allowed ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( sprintf( 'Admin Notices %s has an invalid schema.', $name ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	private static function audit_mutation( string $event, string $actor, array $before, array $after ): void {
		$actor = substr( sanitize_text_field( $actor ), 0, 120 );
		AuditLog::log( $event, 'notice', [
			'actor'  => $actor,
			'before' => self::audit_counts( $before ),
			'after'  => self::audit_counts( $after ),
		] );
	}

	/** @return array{rules:int,everyone:int,operators_only:int,selected:int} */
	private static function audit_counts( array $policy ): array {
		$out = [
			'rules'          => 0,
			'everyone'       => 0,
			'operators_only' => 0,
			'selected'       => 0,
		];
		foreach ( (array) ( $policy['rules'] ?? [] ) as $rule ) {
			$visibility = (string) ( $rule['visibility'] ?? '' );
			$out['rules']++;
			if ( isset( $out[ $visibility ] ) ) {
				$out[ $visibility ]++;
			}
		}
		return $out;
	}

	private function __construct() {}
}
