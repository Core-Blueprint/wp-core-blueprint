<?php
declare(strict_types=1);

namespace CB\Core\Profiles\Sections;

use CB\Core\Integrity\Scanner\ScanJobRepository;
use CB\Core\Integrity\Scanner\ScannerLock;
use CB\Core\Modules\ActivationRegistry;
use CB\Core\Profiles\ExactSection;
use CB\Core\Profiles\SchemaGuard;
use CB\Core\Security\LoginShield;

defined( 'ABSPATH' ) || exit;

final class ModuleStatesSection extends ExactSection {
	/** Mail and Snippets are intentionally excluded from Profiles v1. */
	private const PORTABLE = [
		'login-shield',
		'core-shield',
		'content-models',
		'notes',
		'reports',
		'media-replace',
		'media-formats',
		'package-downloads',
		'user-roles',
		'core-scanner',
	];

	public function id(): string { return 'module-states'; }
	public function label(): string { return __( 'Module activation', 'core-blueprint' ); }
	public function description(): string { return __( 'Activation state for portable Base modules. Mail and Snippets are deliberately excluded.', 'core-blueprint' ); }
	public function schema_version(): int { return 1; }

	public function export(): array {
		$states = [];
		foreach ( self::PORTABLE as $id ) {
			if ( null !== ActivationRegistry::definition( $id ) ) {
				$states[ $id ] = ActivationRegistry::is_enabled( $id );
			}
		}
		return [ 'states' => $states ];
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'states' ], 'module activation' );
		$raw = SchemaGuard::object( $incoming['states'] ?? null, 'module activation states' );
		$expected = array_values( array_filter( self::PORTABLE, static fn( string $id ): bool => null !== ActivationRegistry::definition( $id ) ) );
		SchemaGuard::exact_keys( $raw, $expected, 'module activation states' );
		$states = [];
		foreach ( self::PORTABLE as $id ) {
			if ( null === ActivationRegistry::definition( $id ) ) {
				continue;
			}
			$states[ $id ] = SchemaGuard::bool( $raw[ $id ] ?? null, 'module activation state ' . $id );
		}
		return [ 'states' => $states ];
	}

	public function preflight( array $incoming, array $current ): void {
		$incoming = $this->normalize( $incoming );
		foreach ( array_keys( $incoming['states'] ) as $id ) {
			if ( null === ActivationRegistry::definition( $id ) ) {
				throw new \RuntimeException( __( 'A profile module is no longer available.', 'core-blueprint' ) );
			}
		}

		if ( ! empty( $incoming['states']['login-shield'] ) && '' === LoginShield::sanitize_slug( (string) ( LoginShield::config()['slug'] ?? '' ) ) ) {
			throw new \RuntimeException( __( 'Login Shield cannot be enabled by this profile until a valid custom login slug is configured.', 'core-blueprint' ) );
		}

		if (
			isset( $incoming['states']['core-scanner'] )
			&& ! $incoming['states']['core-scanner']
			&& ! empty( $current['states']['core-scanner'] )
		) {
			$job = ScanJobRepository::get();
			if ( is_array( $job ) && 'running' === (string) ( $job['status'] ?? '' ) ) {
				throw new \RuntimeException( __( 'Core Scanner cannot be disabled by a profile while a scan is running.', 'core-blueprint' ) );
			}
		}
	}

	public function warnings( array $incoming ): array {
		$incoming = $this->normalize( $incoming );
		$warnings = [];
		if ( ! empty( $incoming['states']['login-shield'] ) ) {
			$warnings[] = __( 'This Profile enables Login Shield. Confirm the configured custom login route before signing out.', 'core-blueprint' );
		}
		if ( isset( $incoming['states']['core-shield'] ) && ! $incoming['states']['core-shield'] ) {
			$warnings[] = __( 'This Profile disables the Core Shield master switch.', 'core-blueprint' );
		}
		if ( isset( $incoming['states']['core-scanner'] ) && ! $incoming['states']['core-scanner'] ) {
			$warnings[] = __( 'This Profile disables Core Scanner after all other Profile changes have been applied.', 'core-blueprint' );
		}
		return $warnings;
	}

	public function apply( array $incoming, string $actor ): void {
		$incoming = $this->normalize( $incoming );
		foreach ( $incoming['states'] as $id => $enabled ) {
			$definition = ActivationRegistry::definition( $id );
			if ( null === $definition ) {
				throw new \RuntimeException( __( 'A profile module is no longer available.', 'core-blueprint' ) );
			}
			$state = $definition['state'];
			if ( (bool) $state::is_enabled() === $enabled ) {
				continue;
			}

			$scanner_lock = '';
			if ( 'core-scanner' === $id ) {
				$scanner_lock = ScannerLock::acquire( 'profile_module_state' );
			}
			try {
				$state::set_enabled( $enabled, $actor );
				if ( (bool) $state::is_enabled() !== $enabled ) {
					throw new \RuntimeException( __( 'A profile module state did not persist.', 'core-blueprint' ) );
				}
			} finally {
				if ( '' !== $scanner_lock ) {
					ScannerLock::release( $scanner_lock );
				}
			}
		}
	}
}
