<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets\Conditions;

defined( 'ABSPATH' ) || exit;

final class Engine {
	public static function matches( array $conditions ): bool {
		$rules = isset( $conditions['rules'] ) && is_array( $conditions['rules'] ) ? $conditions['rules'] : [];
		if ( empty( $rules ) ) {
			return true;
		}

		$relation = strtolower( (string) ( $conditions['relation'] ?? 'and' ) );
		$relation = 'or' === $relation ? 'or' : 'and';
		$results = [];

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$results[] = self::match_rule( $rule );
		}

		if ( empty( $results ) ) {
			return true;
		}

		$result = 'or' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
		return (bool) apply_filters( 'core_blueprint_snippets_conditions_match', $result, $conditions );
	}

	private static function match_rule( array $rule ): bool {
		$field    = sanitize_key( (string) ( $rule['field'] ?? '' ) );
		$operator = sanitize_key( (string) ( $rule['operator'] ?? 'is' ) );
		$value    = $rule['value'] ?? null;
		$actual   = null;

		switch ( $field ) {
			case 'scope':
				$actual = is_admin() ? 'admin' : 'frontend';
				break;
			case 'logged_in':
				$actual = is_user_logged_in();
				$value  = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				break;
			case 'user_role':
				$user   = wp_get_current_user();
				$actual = is_array( $user->roles ?? null ) ? $user->roles : [];
				break;
			case 'post_type':
				$actual = function_exists( 'get_post_type' ) ? (string) get_post_type() : '';
				break;
			case 'request_path':
				$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
				$actual = (string) wp_parse_url( $uri, PHP_URL_PATH );
				break;
			default:
				return (bool) apply_filters( 'core_blueprint_snippets_condition_rule', true, $rule, null );
		}

		if ( is_array( $actual ) ) {
			$match = in_array( (string) $value, array_map( 'strval', $actual ), true );
		} elseif ( 'contains' === $operator ) {
			$match = '' !== (string) $value && str_contains( (string) $actual, (string) $value );
		} else {
			$match = $actual === $value || (string) $actual === (string) $value;
		}

		if ( in_array( $operator, [ 'is_not', 'not_contains' ], true ) ) {
			$match = ! $match;
		}

		return (bool) apply_filters( 'core_blueprint_snippets_condition_rule', $match, $rule, $actual );
	}
}
