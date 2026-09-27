<?php
declare(strict_types=1);

namespace CB\Core\Profiles\Sections;

use CB\Core\Profiles\ExactSection;
use CB\Core\Profiles\SchemaGuard;
use CB\Core\Profiles\StateGuard;
use CB\Core\Security\LoginShield;
use CB\Core\Security\ModuleRegistry;
use CB\Core\Security\TwoFactor\Policy;
use CB\Core\Security\TwoFactor\PolicyMutation;
use CB\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class SecuritySection extends ExactSection {
	private const CORE_MODULES = [ 'fingerprint', 'headers' ];

	public function id(): string { return 'security'; }
	public function label(): string { return __( 'Security baseline', 'core-blueprint' ); }
	public function description(): string { return __( 'Core Shield hardening features and portable Login Shield configuration. Master activation is applied separately at the end.', 'core-blueprint' ); }
	public function schema_version(): int { return 2; }

	public function export(): array {
		$settings = Settings::get();
		$login = LoginShield::config();
		$modules = [];
		foreach ( self::CORE_MODULES as $slug ) {
			$module = ModuleRegistry::get( $slug );
			if ( null === $module ) {
				continue;
			}
			$stored = is_array( $settings['modules'][ $slug ] ?? null ) ? $settings['modules'][ $slug ] : [];
			$features = [];
			foreach ( $module->features() as $feature ) {
				$id = sanitize_key( (string) ( $feature['id'] ?? '' ) );
				if ( '' !== $id ) {
					$features[ $id ] = ! empty( $stored['features'][ $id ] );
				}
			}
			$modules[ $slug ] = [
				'enabled'  => ! empty( $stored['enabled'] ),
				'features' => $features,
			];
		}
		return [
			'login_shield' => [
				'slug'                => (string) ( $login['slug'] ?? '' ),
				'mode'                => (string) ( $login['mode'] ?? LoginShield::MODE_STANDARD ),
				'block_response_code' => (int) ( $login['block_response_code'] ?? LoginShield::RESPONSE_CODE_404 ),
			],
			'core_modules' => $modules,
			'two_factor'   => Policy::config(),
		];
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'login_shield', 'core_modules', 'two_factor' ], 'security' );
		$raw_login = SchemaGuard::object( $incoming['login_shield'] ?? null, 'Login Shield' );
		SchemaGuard::exact_keys( $raw_login, [ 'slug', 'mode', 'block_response_code' ], 'Login Shield' );
		$slug_raw = SchemaGuard::string( $raw_login['slug'] ?? null, 'Login Shield slug' );
		$slug = LoginShield::sanitize_slug( $slug_raw );
		if ( $slug !== $slug_raw ) {
			throw new \InvalidArgumentException( __( 'The profile contains invalid Login Shield settings.', 'core-blueprint' ) );
		}
		$mode = SchemaGuard::string( $raw_login['mode'] ?? null, 'Login Shield mode' );
		$code = SchemaGuard::int( $raw_login['block_response_code'] ?? null, 'Login Shield response code' );
		if ( ! in_array( $mode, LoginShield::MODES, true ) || ! in_array( $code, LoginShield::RESPONSE_CODES, true ) ) {
			throw new \InvalidArgumentException( __( 'The profile contains invalid Login Shield settings.', 'core-blueprint' ) );
		}

		$raw_modules = SchemaGuard::object( $incoming['core_modules'] ?? null, 'Core Shield modules' );
		$expected_modules = array_values( array_filter( self::CORE_MODULES, static fn( string $id ): bool => null !== ModuleRegistry::get( $id ) ) );
		SchemaGuard::exact_keys( $raw_modules, $expected_modules, 'Core Shield modules' );
		$modules = [];
		foreach ( $raw_modules as $slug_id => $raw ) {
			$slug_id = sanitize_key( (string) $slug_id );
			if ( ! in_array( $slug_id, self::CORE_MODULES, true ) || null === ModuleRegistry::get( $slug_id ) ) {
				throw new \InvalidArgumentException( __( 'The profile contains an unsupported Core Shield module.', 'core-blueprint' ) );
			}
			$raw = SchemaGuard::object( $raw, 'Core Shield module' );
			SchemaGuard::exact_keys( $raw, [ 'enabled', 'features' ], 'Core Shield module' );
			$module = ModuleRegistry::get( $slug_id );
			$known_features = [];
			foreach ( $module->features() as $feature ) {
				$id = sanitize_key( (string) ( $feature['id'] ?? '' ) );
				if ( '' !== $id ) { $known_features[ $id ] = true; }
			}
			$raw_features = SchemaGuard::object( $raw['features'] ?? null, 'Core Shield features' );
			SchemaGuard::exact_keys( $raw_features, array_keys( $known_features ), 'Core Shield features' );
			$features = [];
			foreach ( $raw_features as $feature_id => $enabled ) {
				$feature_id = sanitize_key( (string) $feature_id );
				if ( ! isset( $known_features[ $feature_id ] ) ) {
					throw new \InvalidArgumentException( __( 'The profile contains an unsupported Core Shield feature.', 'core-blueprint' ) );
				}
				$features[ $feature_id ] = SchemaGuard::bool( $enabled, 'Core Shield feature state' );
			}
			ksort( $features, SORT_STRING );
			$modules[ $slug_id ] = [
				'enabled'  => SchemaGuard::bool( $raw['enabled'] ?? null, 'Core Shield module state' ),
				'features' => $features,
			];
		}
		ksort( $modules, SORT_STRING );

		$raw_two_factor = SchemaGuard::object( $incoming['two_factor'] ?? null, 'two-factor policy' );
		SchemaGuard::exact_keys( $raw_two_factor, [ 'mode', 'scope' ], 'two-factor policy' );
		$two_factor_mode = SchemaGuard::string( $raw_two_factor['mode'] ?? null, 'two-factor policy mode' );
		$two_factor_scope = SchemaGuard::string( $raw_two_factor['scope'] ?? null, 'two-factor policy scope' );
		if ( ! Policy::is_valid_mode( $two_factor_mode ) || Policy::SCOPE_PRIVILEGED !== $two_factor_scope ) {
			SchemaGuard::invalid_value( 'two-factor policy' );
		}

		return [
			'login_shield' => [
				'slug'                => $slug,
				'mode'                => $mode,
				'block_response_code' => $code,
			],
			'core_modules' => $modules,
			'two_factor'   => [
				'mode'  => $two_factor_mode,
				'scope' => $two_factor_scope,
			],
		];
	}

	public function preflight( array $incoming, array $current ): void {
		$incoming = $this->normalize( $incoming );
		$current = $this->normalize( $current );
		$incoming_mode = (string) $incoming['two_factor']['mode'];
		$current_mode  = (string) $current['two_factor']['mode'];
		if ( $incoming_mode === $current_mode ) {
			return;
		}

		$actor = wp_get_current_user();
		if ( ! ( $actor instanceof \WP_User ) || $actor->ID <= 0 ) {
			throw new \RuntimeException( __( 'Two-factor policy changes require a trusted CB Operator.', 'core-blueprint' ) );
		}

		PolicyMutation::assert_can_set_mode( $incoming_mode, $actor );
		PolicyMutation::assert_can_restore_mode( $current_mode, $actor );
	}

	public function warnings( array $incoming ): array {
		$incoming = $this->normalize( $incoming );
		$warnings = [];
		if ( ! empty( $incoming['core_modules']['headers']['features']['strict_transport_security'] ) ) {
			$warnings[] = __( 'This Profile enables HSTS. Browsers can remember that policy after it is later disabled.', 'core-blueprint' );
		}
		$current = LoginShield::config();
		if ( ! empty( $current['enabled'] ) && (string) $incoming['login_shield']['slug'] !== (string) ( $current['slug'] ?? '' ) ) {
			$warnings[] = __( 'Login Shield is currently active and this Profile changes its custom login slug. Confirm the new route before signing out.', 'core-blueprint' );
		}
		return $warnings;
	}

	public function apply( array $incoming, string $actor ): void {
		$incoming = $this->normalize( $incoming );
		$current_login = LoginShield::config();
		$current_login['slug'] = $incoming['login_shield']['slug'];
		$current_login['mode'] = $incoming['login_shield']['mode'];
		$current_login['block_response_code'] = $incoming['login_shield']['block_response_code'];
		LoginShield::save( $current_login, $actor );

		foreach ( $incoming['core_modules'] as $slug => $config ) {
			if ( ! Settings::set_module_enabled( $slug, (bool) $config['enabled'], $actor ) ) {
				$stored = Settings::get()['modules'][ $slug ]['enabled'] ?? null;
				if ( (bool) $stored !== (bool) $config['enabled'] ) {
					throw new \RuntimeException( __( 'Could not apply a Core Shield module state.', 'core-blueprint' ) );
				}
			}
			foreach ( $config['features'] as $feature => $enabled ) {
				if ( ! Settings::set_feature_enabled( $slug, $feature, (bool) $enabled, $actor ) ) {
					$stored = Settings::get()['modules'][ $slug ]['features'][ $feature ] ?? null;
					if ( (bool) $stored !== (bool) $enabled ) {
						throw new \RuntimeException( __( 'Could not apply a Core Shield feature state.', 'core-blueprint' ) );
					}
				}
			}
		}

		if ( Policy::mode() !== (string) $incoming['two_factor']['mode'] ) {
			$wp_actor = wp_get_current_user();
			if ( ! ( $wp_actor instanceof \WP_User ) || $wp_actor->ID <= 0 ) {
				throw new \RuntimeException( __( 'Two-factor policy changes require a trusted CB Operator.', 'core-blueprint' ) );
			}
			PolicyMutation::set_mode( (string) $incoming['two_factor']['mode'], $wp_actor, 'profile' );
		}
	}

	public function restore( array $snapshot, array $incoming, string $actor ): void {
		$current = $this->snapshot();
		if ( ! StateGuard::is_between( $current, $snapshot, $incoming ) ) {
			throw new \RuntimeException( __( 'Configuration changed during Profile rollback and was not overwritten.', 'core-blueprint' ) );
		}

		$snapshot = $this->normalize( $snapshot );
		$incoming = $this->normalize( $incoming );
		$current_mode = Policy::mode();
		$snapshot_mode = (string) $snapshot['two_factor']['mode'];

		if ( $current_mode !== $snapshot_mode ) {
			$wp_actor = wp_get_current_user();
			if ( ! ( $wp_actor instanceof \WP_User ) || $wp_actor->ID <= 0 ) {
				throw new \RuntimeException( __( 'Two-factor policy changes require a trusted CB Operator.', 'core-blueprint' ) );
			}
			PolicyMutation::restore_mode(
				$snapshot_mode,
				(string) $incoming['two_factor']['mode'],
				$wp_actor,
				'profile_rollback'
			);
		}

		$restore_without_policy = $snapshot;
		$restore_without_policy['two_factor']['mode'] = Policy::mode();
		$this->apply( $restore_without_policy, $actor );
	}
}
