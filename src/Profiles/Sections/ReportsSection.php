<?php
declare(strict_types=1);

namespace CB\Core\Profiles\Sections;

use CB\Core\Profiles\CanonicalJson;
use CB\Core\Profiles\ExactSection;
use CB\Core\Profiles\SchemaGuard;
use CB\Core\Reports\Composer\MaintenanceTemplate;
use CB\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class ReportsSection extends ExactSection {
	public function id(): string { return 'reports'; }
	public function label(): string { return __( 'Reports policy', 'core-blueprint' ); }
	public function description(): string { return __( 'Report retention, branding and composer policy. Report snapshots, recipient addresses and attachment IDs are not included.', 'core-blueprint' ); }
	public function schema_version(): int { return 1; }

	public function export(): array {
		$reports = Settings::get()['reports'] ?? [];
		$branding = is_array( $reports['branding'] ?? null ) ? $reports['branding'] : [];
		$alerts = is_array( $reports['email_alerts'] ?? null ) ? $reports['email_alerts'] : [];
		return [
			'retention_days' => max( 7, min( 3650, (int) ( $reports['retention_days'] ?? 365 ) ) ),
			'email_alerts' => [ 'generation_failed' => ! empty( $alerts['generation_failed'] ) ],
			'branding' => [
				'provider_name'    => sanitize_text_field( (string) ( $branding['provider_name'] ?? '' ) ),
				'provider_contact' => sanitize_text_field( (string) ( $branding['provider_contact'] ?? '' ) ),
				'accent_color'     => $this->color( (string) ( $branding['accent_color'] ?? '#0064c8' ) ),
			],
			'composer' => [ 'maintenance' => MaintenanceTemplate::current() ],
		];
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'retention_days', 'email_alerts', 'branding', 'composer' ], 'Reports' );
		$branding = SchemaGuard::object( $incoming['branding'] ?? null, 'Reports branding' );
		SchemaGuard::exact_keys( $branding, [ 'provider_name', 'provider_contact', 'accent_color' ], 'Reports branding' );
		$alerts = SchemaGuard::object( $incoming['email_alerts'] ?? null, 'Reports email alerts' );
		SchemaGuard::exact_keys( $alerts, [ 'generation_failed' ], 'Reports email alerts' );
		$composer_root = SchemaGuard::object( $incoming['composer'] ?? null, 'Reports composer' );
		SchemaGuard::exact_keys( $composer_root, [ 'maintenance' ], 'Reports composer' );
		$composer = SchemaGuard::object( $composer_root['maintenance'] ?? null, 'Reports maintenance composer' );
		$retention = SchemaGuard::int( $incoming['retention_days'] ?? null, 'Reports retention days' );
		if ( $retention < 7 || $retention > 3650 ) {
			SchemaGuard::invalid_value( 'Reports retention days' );
		}
		$provider_name = SchemaGuard::string( $branding['provider_name'] ?? null, 'Reports provider name' );
		$provider_contact = SchemaGuard::string( $branding['provider_contact'] ?? null, 'Reports provider contact' );
		$accent_input = SchemaGuard::string( $branding['accent_color'] ?? null, 'Reports accent color' );
		$accent_color = sanitize_hex_color( $accent_input );
		if ( ! is_string( $accent_color ) || strtolower( $accent_color ) !== strtolower( $accent_input ) ) {
			SchemaGuard::invalid_value( 'Reports accent color' );
		}
		if ( sanitize_text_field( $provider_name ) !== $provider_name || sanitize_text_field( $provider_contact ) !== $provider_contact ) {
			SchemaGuard::invalid_value( 'Reports branding' );
		}
		$normalized_composer = MaintenanceTemplate::normalize( $composer );
		if ( CanonicalJson::encode( $normalized_composer ) !== CanonicalJson::encode( $composer ) ) {
			SchemaGuard::invalid_value( 'Reports maintenance composer' );
		}
		return [
			'retention_days' => $retention,
			'email_alerts' => [ 'generation_failed' => SchemaGuard::bool( $alerts['generation_failed'] ?? null, 'Reports generation-failed alerts' ) ],
			'branding' => [
				'provider_name'    => $provider_name,
				'provider_contact' => $provider_contact,
				'accent_color'     => strtolower( $accent_color ),
			],
			'composer' => [ 'maintenance' => $normalized_composer ],
		];
	}

	public function apply( array $incoming, string $actor ): void {
		$incoming = $this->normalize( $incoming );
		$settings = Settings::get();
		$reports = is_array( $settings['reports'] ?? null ) ? $settings['reports'] : [];
		$reports['retention_days'] = $incoming['retention_days'];
		$reports['email_alerts']['generation_failed'] = $incoming['email_alerts']['generation_failed'];
		$reports['branding']['provider_name'] = $incoming['branding']['provider_name'];
		$reports['branding']['provider_contact'] = $incoming['branding']['provider_contact'];
		$reports['branding']['accent_color'] = $incoming['branding']['accent_color'];
		$reports['composer']['maintenance'] = $incoming['composer']['maintenance'];
		if ( ! Settings::set_key( 'reports', $reports, $actor ) && $this->export() !== $incoming ) {
			throw new \RuntimeException( __( 'Could not apply the Reports profile policy.', 'core-blueprint' ) );
		}
	}

	private function color( string $value ): string {
		$color = sanitize_hex_color( $value );
		return is_string( $color ) && '' !== $color ? strtolower( $color ) : '#0064c8';
	}
}
