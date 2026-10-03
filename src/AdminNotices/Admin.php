<?php
declare(strict_types=1);
/**
 * Preferences management boundary for Admin Notices Governance.
 *
 * The editor manages only source-level audience policy and bounded source
 * metadata. Notice markup, actions, nonces and authorization remain owned by
 * WordPress and the notice producer.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\AdminNotices;

use CoreBlueprint\Core\Ajax\Request;
use CoreBlueprint\Core\UI\RoleCapabilityPicker;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const FORM_ACTION = 'cb_core_admin_notices_save';
	public const NONCE_ACTION = 'cb_core_admin_notices_preferences';
	public const NONCE_NAME = '_cb_admin_notices_nonce';
	public const PICKER_NONCE_ACTION = 'cb_core_admin_notices_picker';
	public const ROLE_SEARCH_ACTION = 'cb_core_admin_notices_search_roles';
	public const CAPABILITY_SEARCH_ACTION = 'cb_core_admin_notices_search_capabilities';

	public static function boot(): void {
		add_action( 'admin_post_' . self::FORM_ACTION, [ self::class, 'handle_save' ] );
		add_action( 'wp_ajax_' . self::ROLE_SEARCH_ACTION, [ self::class, 'ajax_search_roles' ] );
		add_action( 'wp_ajax_' . self::CAPABILITY_SEARCH_ACTION, [ self::class, 'ajax_search_capabilities' ] );
	}

	public static function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Canonical editor state.
	 *
	 * @return array{
	 *   policy:array<string,mixed>,
	 *   sources:list<array<string,mixed>>,
	 *   summary:array{sources:int,restricted:int,protected:int,unknown:int}
	 * }
	 */
	public static function editor_state(): array {
		$policy = Policy::get();
		$rules = self::rules_by_source( $policy['rules'] );
		$sources = SourceLedger::all();

		foreach ( $policy['rules'] as $rule ) {
			$source_id = (string) ( $rule['source'] ?? '' );
			if ( '' === $source_id || isset( $sources[ $source_id ] ) ) {
				continue;
			}
			$sources[ $source_id ] = self::policy_only_source( $source_id );
		}

		$rows = [];
		$summary = [
			'sources'    => 0,
			'restricted' => 0,
			'protected'  => 0,
			'unknown'    => 0,
		];

		foreach ( $sources as $source_id => $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			$rule = $rules[ $source_id ] ?? Policy::rule_for( (string) $source_id );
			$row = $source;
			$row['rule'] = $rule;
			$rows[] = $row;

			$summary['sources']++;
			if ( Policy::EVERYONE !== (string) ( $rule['visibility'] ?? Policy::EVERYONE ) ) {
				$summary['restricted']++;
			}
			if ( ! empty( $source['protected'] ) ) {
				$summary['protected']++;
			}
			if ( SourceResolver::KIND_UNKNOWN === (string) ( $source['kind'] ?? '' ) ) {
				$summary['unknown']++;
			}
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$protected = (int) ! empty( $b['protected'] ) <=> (int) ! empty( $a['protected'] );
				if ( 0 !== $protected ) {
					return $protected;
				}
				return strnatcasecmp( (string) ( $a['label'] ?? '' ), (string) ( $b['label'] ?? '' ) );
			}
		);

		return [
			'policy'  => $policy,
			'sources' => $rows,
			'summary' => $summary,
		];
	}

	public static function save_editor_payload( array $payload, string $actor = 'preferences' ): bool {
		return Policy::replace( $payload, $actor );
	}

	public static function reset_editor_policy( string $actor = 'preferences' ): bool {
		return Policy::reset( $actor );
	}

	public static function handle_save(): void {
		if ( ! self::can_manage() ) {
			wp_die(
				esc_html__( 'You do not have permission to manage Admin Notices.', 'core-blueprint' ),
				'',
				[ 'response' => 403 ]
			);
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$redirect = admin_url( 'admin.php?page=core-blueprint-preferences&tab=admin-notices' );
		$is_reset = isset( $_POST['cb_admin_notices_reset'] )
			&& '1' === sanitize_text_field( wp_unslash( $_POST['cb_admin_notices_reset'] ) );

		if ( $is_reset ) {
			$ok = self::reset_editor_policy();
			wp_safe_redirect( add_query_arg( 'admin_notices_notice', $ok ? 'reset' : 'invalid', $redirect ) );
			exit;
		}

		$raw = isset( $_POST['cb_admin_notices_payload'] )
			? wp_unslash( $_POST['cb_admin_notices_payload'] )
			: ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- strict JSON policy normalization below.

		try {
			$decoded = is_string( $raw ) ? json_decode( $raw, true, 64, JSON_THROW_ON_ERROR ) : null;
			if ( ! is_array( $decoded ) ) {
				throw new \InvalidArgumentException( 'Invalid Admin Notices payload.' );
			}
			$ok = self::save_editor_payload( $decoded );
		} catch ( \Throwable ) {
			$ok = false;
		}

		wp_safe_redirect( add_query_arg( 'admin_notices_notice', $ok ? 'saved' : 'invalid', $redirect ) );
		exit;
	}

	/** @param list<string> $roles @return list<array{id:string,label:string,meta:string}> */
	public static function role_picker_items( array $roles ): array {
		return RoleCapabilityPicker::role_items( $roles );
	}

	/** @param list<string> $capabilities @return list<array{id:string,label:string,meta:string}> */
	public static function capability_picker_items( array $capabilities ): array {
		return RoleCapabilityPicker::capability_items( $capabilities );
	}

	/** @return list<array{id:string,label:string,meta:string}> */
	public static function search_roles( string $search ): array {
		return RoleCapabilityPicker::search_roles( $search );
	}

	/** @return list<array{id:string,label:string,meta:string}> */
	public static function search_capabilities( string $search ): array {
		return RoleCapabilityPicker::search_capabilities( $search );
	}

	public static function ajax_search_roles(): void {
		Request::nonce( self::PICKER_NONCE_ACTION, '_ajax_nonce' );
		Request::cap( Capabilities::MANAGE );
		wp_send_json_success( [ 'items' => self::search_roles( Request::text( 'search' ) ) ] );
	}

	public static function ajax_search_capabilities(): void {
		Request::nonce( self::PICKER_NONCE_ACTION, '_ajax_nonce' );
		Request::cap( Capabilities::MANAGE );
		wp_send_json_success( [ 'items' => self::search_capabilities( Request::text( 'search' ) ) ] );
	}

	/** @param list<array<string,mixed>> $rules @return array<string,array<string,mixed>> */
	private static function rules_by_source( array $rules ): array {
		$map = [];
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && isset( $rule['source'] ) && is_string( $rule['source'] ) ) {
				$map[ $rule['source'] ] = $rule;
			}
		}
		return $map;
	}

	/** @return array<string,mixed> */
	private static function policy_only_source( string $source_id ): array {
		$kind = match ( true ) {
			'wordpress:core' === $source_id => SourceResolver::KIND_WORDPRESS,
			str_starts_with( $source_id, 'plugin:' ) => SourceResolver::KIND_PLUGIN,
			str_starts_with( $source_id, 'mu-plugin:' ) => SourceResolver::KIND_MU_PLUGIN,
			str_starts_with( $source_id, 'theme:' ) => SourceResolver::KIND_THEME,
			default => SourceResolver::KIND_UNKNOWN,
		};

		return [
			'id'             => $source_id,
			'label'          => self::fallback_label( $source_id ),
			'kind'           => $kind,
			'manageable'     => SourceResolver::is_manageable_id( $source_id ),
			'protected'      => SourceResolver::is_protected_id( $source_id ),
			'hooks'          => [],
			'callback_count' => 0,
			'first_seen'     => 0,
			'last_seen'      => 0,
		];
	}

	private static function fallback_label( string $source_id ): string {
		if ( 'wordpress:core' === $source_id ) {
			return 'WordPress';
		}
		$parts = explode( ':', $source_id, 2 );
		$slug = $parts[1] ?? $source_id;
		$slug = str_replace( [ '-', '_' ], ' ', $slug );
		$slug = trim( $slug );
		return '' !== $slug ? ucwords( $slug ) : $source_id;
	}

	private function __construct() {}
}
