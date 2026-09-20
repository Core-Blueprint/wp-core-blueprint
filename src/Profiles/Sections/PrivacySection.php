<?php
declare(strict_types=1);

namespace CB\Core\Profiles\Sections;

use CB\Core\Governance\RetentionPolicy;
use CB\Core\Log\Verbosity;
use CB\Core\Privacy\Anonymizer;
use CB\Core\Profiles\ExactSection;
use CB\Core\Profiles\SchemaGuard;

defined( 'ABSPATH' ) || exit;

final class PrivacySection extends ExactSection {
	public function id(): string { return 'privacy'; }
	public function label(): string { return __( 'Privacy & logging', 'core-blueprint' ); }
	public function description(): string { return __( 'IP handling, audit verbosity and audit-log retention policy.', 'core-blueprint' ); }
	public function schema_version(): int { return 1; }

	public function export(): array {
		$verbosity = [];
		foreach ( array_keys( Verbosity::DEFAULTS ) as $category ) {
			$verbosity[ $category ] = Verbosity::level_for_category( $category );
		}
		return [
			'ip_mode'   => Anonymizer::ip_mode(),
			'verbosity' => $verbosity,
			'retention' => RetentionPolicy::all(),
		];
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'ip_mode', 'verbosity', 'retention' ], 'privacy' );
		$mode = SchemaGuard::string( $incoming['ip_mode'] ?? null, 'privacy IP policy' );
		if ( ! in_array( $mode, Anonymizer::MODES, true ) ) {
			throw new \InvalidArgumentException( __( 'The profile contains an invalid privacy IP policy.', 'core-blueprint' ) );
		}
		$verbosity = [];
		$raw_verbosity = SchemaGuard::object( $incoming['verbosity'] ?? null, 'audit verbosity' );
		SchemaGuard::exact_keys( $raw_verbosity, array_keys( Verbosity::DEFAULTS ), 'audit verbosity' );
		foreach ( array_keys( Verbosity::DEFAULTS ) as $category ) {
			$level = SchemaGuard::string( $raw_verbosity[ $category ] ?? null, 'audit verbosity ' . $category );
			if ( ! in_array( $level, Verbosity::LEVELS, true ) ) {
				throw new \InvalidArgumentException( __( 'The profile contains an invalid audit verbosity policy.', 'core-blueprint' ) );
			}
			$verbosity[ $category ] = $level;
		}
		$retention = [];
		$raw_retention = SchemaGuard::object( $incoming['retention'] ?? null, 'retention' );
		SchemaGuard::exact_keys( $raw_retention, RetentionPolicy::CATEGORIES, 'retention' );
		foreach ( RetentionPolicy::CATEGORIES as $category ) {
			if ( ! array_key_exists( $category, $raw_retention ) ) {
				throw new \InvalidArgumentException( __( 'The profile contains an incomplete retention policy.', 'core-blueprint' ) );
			}
			$days = SchemaGuard::int( $raw_retention[ $category ], 'retention ' . $category );
			if ( $days < 0 || $days > 3650 ) {
				SchemaGuard::invalid_value( 'retention ' . $category );
			}
			$retention[ $category ] = $days;
		}
		return [ 'ip_mode' => $mode, 'verbosity' => $verbosity, 'retention' => $retention ];
	}

	public function warnings( array $incoming ): array {
		$incoming = $this->normalize( $incoming );
		return Anonymizer::MODE_FULL === $incoming['ip_mode']
			? [ __( 'This Profile enables full IP-address storage in audit logs. Confirm that the site has an appropriate privacy and retention basis.', 'core-blueprint' ) ]
			: [];
	}

	public function apply( array $incoming, string $actor ): void {
		$incoming = $this->normalize( $incoming );
		if ( ! Anonymizer::set_ip_mode( $incoming['ip_mode'], $actor ) && Anonymizer::ip_mode() !== $incoming['ip_mode'] ) {
			throw new \RuntimeException( __( 'Could not apply the profile privacy policy.', 'core-blueprint' ) );
		}
		foreach ( $incoming['verbosity'] as $category => $level ) {
			if ( ! Verbosity::set_level( $category, $level ) && Verbosity::level_for_category( $category ) !== $level ) {
				throw new \RuntimeException( __( 'Could not apply the profile audit verbosity policy.', 'core-blueprint' ) );
			}
		}
		RetentionPolicy::update( $incoming['retention'] );
		if ( RetentionPolicy::all() !== $incoming['retention'] ) {
			throw new \RuntimeException( __( 'Could not apply the profile retention policy.', 'core-blueprint' ) );
		}
	}
}
