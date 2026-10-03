<?php
declare(strict_types=1);
/**
 * Modules\Status - canonical module health/status registry.
 *
 * Activation state and health are deliberately separate concepts. Status providers are
 * registered declaratively under canonical kebab-case IDs and return the small shared
 * ok|warn|err|off result vocabulary consumed by Dashboard and HUD surfaces.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Modules;

use CoreBlueprint\Core\Log\Status as LogStatus;
use CoreBlueprint\Core\Notes\Status as NotesStatus;
use CoreBlueprint\Core\Reports\Status as ReportsStatus;
use CoreBlueprint\Core\Safeguards\Contributors as SafeguardContributors;
use CoreBlueprint\Core\Snippets\Status as SnippetsStatus;

defined( 'ABSPATH' ) || exit;

final class Status {

	/** Allowed state values. */
	public const STATES = [ 'ok', 'warn', 'err', 'off' ];

	/** @return array<string,array{provider:callable,label:string,url:string}> */
	private static function built_in_definitions(): array {
		$safeguards = admin_url( 'admin.php?page=core-blueprint-safeguards' );

		return [
			'access-mode' => [
				'provider' => [ SafeguardContributors::class, 'access_mode' ],
				'label'    => 'Access Mode',
				'url'      => $safeguards . '&tab=access-mode',
			],
			'login-shield' => [
				'provider' => [ SafeguardContributors::class, 'login_shield' ],
				'label'    => 'Login Shield',
				'url'      => $safeguards . '&tab=login-shield',
			],
			'core-shield' => [
				'provider' => [ SafeguardContributors::class, 'core_shield' ],
				'label'    => 'Core Shield',
				'url'      => $safeguards . '&tab=core-shield',
			],
			'core-scanner' => [
				'provider' => [ SafeguardContributors::class, 'core_scanner' ],
				'label'    => 'Core Scanner',
				'url'      => $safeguards . '&tab=core-scanner',
			],
			'failsafe' => [
				'provider' => [ SafeguardContributors::class, 'failsafe' ],
				'label'    => 'Failsafe',
				'url'      => $safeguards . '&tab=failsafe',
			],
			'logs' => [
				'provider' => [ LogStatus::class, 'contribute' ],
				'label'    => 'Logs',
				'url'      => admin_url( 'admin.php?page=core-blueprint-logs' ),
			],
			'notes' => [
				'provider' => [ NotesStatus::class, 'contribute' ],
				'label'    => 'Notes',
				'url'      => admin_url( 'admin.php?page=core-blueprint-notes' ),
			],
			'reports' => [
				'provider' => [ ReportsStatus::class, 'contribute' ],
				'label'    => 'Reports',
				'url'      => admin_url( 'admin.php?page=core-blueprint-reports' ),
			],
			'snippets' => [
				'provider' => [ SnippetsStatus::class, 'contribute' ],
				'label'    => 'Snippets',
				'url'      => admin_url( 'admin.php?page=core-blueprint-snippets' ),
			],
		];
	}

	/**
	 * Return the validated status-provider map.
	 *
	 * Extensions may append their own definitions through
	 * `cb_core_module_status_definitions`. Base-owned IDs are reserved.
	 *
	 * @return array<string,array{provider:callable,label:string,url:string}>
	 */
	public static function definitions(): array {
		$built_ins = self::built_in_definitions();

		/**
		 * Filter the canonical module status definitions.
		 *
		 * @param array<string,array{provider:callable,label:string,url?:string}> $definitions
		 */
		$filtered = apply_filters( 'cb_core_module_status_definitions', $built_ins );
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
			if ( isset( $built_ins[ $id ] ) || ! ActivationRegistry::is_valid_id( $id ) || ! is_array( $definition ) ) {
				continue;
			}

			$normalized = self::normalize_definition( $definition );
			if ( null !== $normalized ) {
				$out[ $id ] = $normalized;
			}
		}

		return $out;
	}

	/** @return array{provider:callable,label:string,url:string}|null */
	public static function definition( string $id ): ?array {
		if ( ! ActivationRegistry::is_valid_id( $id ) ) {
			return null;
		}

		$definition = self::definitions()[ $id ] ?? null;
		return is_array( $definition ) ? self::normalize_definition( $definition ) : null;
	}

	/**
	 * Fetch a status by canonical ID. Unknown IDs have no status and return null.
	 * Provider failures degrade to a warning shape and never break presentation.
	 *
	 * @return array{state:string,detail:string,url:string,label:string}|null
	 */
	public static function get( string $id ): ?array {
		$definition = self::definition( $id );
		if ( null === $definition ) {
			return null;
		}

		try {
			$raw = call_user_func( $definition['provider'] );
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'CB Modules\\Status [%s]: %s', $id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- last-resort provider failure diagnostic; status fallback must not depend on AuditLog.
			return self::fallback( 'warn', self::unavailable_label(), $id, $definition );
		}

		if ( ! is_array( $raw ) ) {
			return self::fallback( 'warn', self::unavailable_label(), $id, $definition );
		}

		$state = isset( $raw['state'] ) && is_string( $raw['state'] ) ? $raw['state'] : '';
		if ( ! in_array( $state, self::STATES, true ) ) {
			return self::fallback( 'warn', self::unavailable_label(), $id, $definition );
		}

		$detail = isset( $raw['detail'] ) && is_string( $raw['detail'] )
			? wp_strip_all_tags( $raw['detail'] )
			: '';
		$url = isset( $raw['url'] ) && is_string( $raw['url'] ) ? esc_url_raw( $raw['url'] ) : '';
		if ( '' === $url ) {
			$url = $definition['url'];
		}

		return [
			'state'  => $state,
			'detail' => $detail,
			'url'    => $url,
			'label'  => self::label( $id, $definition['label'] ),
		];
	}

	/** @param string[] $ids @return array<string,array{state:string,detail:string,url:string,label:string}> */
	public static function many( array $ids ): array {
		$out = [];
		foreach ( $ids as $id ) {
			$id = (string) $id;
			$status = self::get( $id );
			if ( null !== $status ) {
				$out[ $id ] = $status;
			}
		}
		return $out;
	}

	/** Translate the shared status vocabulary to the existing UI dot classes. */
	public static function dot_class( string $state ): string {
		switch ( $state ) {
			case 'ok':   return 'active';
			case 'warn': return 'warning';
			case 'err':  return 'error';
			case 'off':
			default:     return 'idle';
		}
	}

	/** Human-readable label for a registered status ID. */
	public static function label( string $id, string $fallback = '' ): string {
		if ( ! self::i18n_ready() ) {
			return '' !== $fallback ? $fallback : $id;
		}

		switch ( $id ) {
			case 'access-mode':  return __( 'Access Mode', 'core-blueprint' );
			case 'login-shield': return __( 'Login Shield', 'core-blueprint' );
			case 'core-shield':  return __( 'Core Shield', 'core-blueprint' );
			case 'core-scanner': return __( 'Core Scanner', 'core-blueprint' );
			case 'failsafe':     return __( 'Failsafe', 'core-blueprint' );
			case 'logs':         return __( 'Logs', 'core-blueprint' );
			case 'notes':        return __( 'Notes', 'core-blueprint' );
			case 'reports':      return __( 'Reports', 'core-blueprint' );
			case 'snippets':     return __( 'Snippets', 'core-blueprint' );
			default:             return '' !== $fallback ? $fallback : $id;
		}
	}

	/** @return array{provider:callable,label:string,url:string}|null */
	private static function normalize_definition( array $definition ): ?array {
		$provider = $definition['provider'] ?? null;
		$label    = isset( $definition['label'] ) && is_string( $definition['label'] ) ? trim( $definition['label'] ) : '';
		$url      = isset( $definition['url'] ) && is_string( $definition['url'] ) ? esc_url_raw( $definition['url'] ) : '';

		if ( ! is_callable( $provider ) || '' === $label ) {
			return null;
		}

		return [
			'provider' => $provider,
			'label'    => $label,
			'url'      => $url,
		];
	}

	/** @param array{provider:callable,label:string,url:string} $definition */
	private static function fallback( string $state, string $detail, string $id, array $definition ): array {
		return [
			'state'  => in_array( $state, self::STATES, true ) ? $state : 'off',
			'detail' => $detail,
			'url'    => $definition['url'],
			'label'  => self::label( $id, $definition['label'] ),
		];
	}

	private static function unavailable_label(): string {
		return self::i18n_ready() ? __( 'Status unavailable', 'core-blueprint' ) : 'Status unavailable';
	}

	private static function i18n_ready(): bool {
		return did_action( 'init' ) > 0 || doing_action( 'init' );
	}
}
