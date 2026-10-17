<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Profiles\Sections;

use CoreBlueprint\Core\Profiles\CanonicalJson;
use CoreBlueprint\Core\Profiles\ExactSection;
use CoreBlueprint\Core\Profiles\SchemaGuard;
use CoreBlueprint\Core\Profiles\VersionGuardedSectionInterface;
use CoreBlueprint\Core\Settings\OptionCas;
use CoreBlueprint\Core\Reports\Composer\MaintenanceTemplate;
use CoreBlueprint\Core\Settings;
use CoreBlueprint\Core\Design\Document\Authority;
use CoreBlueprint\Core\Reports\DesignDocumentAdapter;

defined( 'ABSPATH' ) || exit;

final class ReportsSection extends ExactSection implements VersionGuardedSectionInterface {
	public function id(): string { return 'reports'; }
	public function label(): string { return __( 'Reports policy', 'core-blueprint' ); }
	public function description(): string { return __( 'Report retention, branding and composer policy. Report snapshots, recipient addresses and attachment IDs are not included.', 'core-blueprint' ); }
	public function schema_version(): int { return 1; }

	public function export(): array {
		$reports = Settings::get()['reports'] ?? [];
		$live = DesignDocumentAdapter::current();
		$branding = $live['branding'];
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

	/** Preview is read-only for a healthy active authority. */
	public function preflight( array $incoming, array $current ): void {
		unset( $incoming, $current );
		$this->version_witness();
	}

	/** @return array<string,string> */
	public function version_witness(): array {
		$authority = (string) ( Authority::inspect()['status'] ?? 'blocked' );
		if ( ! in_array( $authority, [ 'legacy', 'prepared', 'active' ], true ) ) {
			throw new \RuntimeException( 'Reports Profile recovery_required: authority' );
		}
		$row = OptionCas::read();
		if ( 'ok' !== ( $row['status'] ?? '' ) ) {
			throw new \RuntimeException( 'Reports Profile recovery_required: settings_unavailable' );
		}
		$revision = 'legacy';
		if ( 'active' === $authority ) {
			$document = DesignDocumentAdapter::read();
			if ( 'found' !== ( $document['status'] ?? '' ) ) {
				throw new \RuntimeException( 'Reports Profile recovery_required: document_unavailable' );
			}
			$revision = (string) $document['revision'];
		}
		return [
			'authority'         => $authority,
			'settings_sha256'   => hash( 'sha256', $row['raw'] ),
			'document_revision' => $revision,
		];
	}

	/** Active Profiles Apply remains explicitly blocked. */
	public function assert_apply_allowed(): void {
		self::require_legacy_profile_authority();
	}

	/** Repeat policy at direct apply and rollback boundaries. */
	public function restore( array $snapshot, array $incoming, string $actor ): void {
		self::require_legacy_profile_authority();
		parent::restore( $snapshot, $incoming, $actor );
	}

	private static function require_legacy_profile_authority(): void {
		$state = Authority::inspect()['status'];
		if ( ! in_array( $state, [ 'legacy', 'prepared' ], true ) ) {
			throw new \RuntimeException(
				__( 'Reports Profile import is temporarily unavailable while canonical Reports storage is active or requires recovery. No Profile sections were changed. Review the Reports storage policy.', 'core-blueprint' )
			);
		}
	}

	public function apply( array $incoming, string $actor ): void {
		$this->assert_apply_allowed();
		$changes = self::represented_paths( $this->normalize( $incoming ) );
		if ( ! Settings::set_paths( $changes, $actor )
			&& ( 'unchanged' !== Settings::last_write_status() || ! Settings::matches_paths( $changes ) ) ) {
			throw new \RuntimeException( 'Reports Profile apply failed: settings_conflict' );
		}
	}

	/** @return array<string,mixed> */
	private static function represented_paths( array $data ): array {
		return [
			'reports.retention_days'                 => $data['retention_days'],
			'reports.email_alerts.generation_failed' => $data['email_alerts']['generation_failed'],
			'reports.branding.provider_name'         => $data['branding']['provider_name'],
			'reports.branding.provider_contact'      => $data['branding']['provider_contact'],
			'reports.branding.accent_color'          => $data['branding']['accent_color'],
			'reports.composer.maintenance'           => $data['composer']['maintenance'],
		];
	}

	/** Compensate only from exact post-apply Settings bytes. */
	public function restore_guarded(
		array $snapshot,
		array $incoming,
		string $actor,
		array $before_witness,
		?array $after_witness
	): void {
		unset( $incoming, $actor );
		$now = $this->version_witness();
		if ( null === $after_witness ) {
			if ( $now === $before_witness ) {
				return;
			}
			throw new \RuntimeException( 'Reports Profile recovery_required: missing_post_witness' );
		}
		if ( $now !== $after_witness ) {
			throw new \RuntimeException( 'Reports Profile recovery_required: concurrent_revision' );
		}
		$this->assert_apply_allowed();
		$row = OptionCas::read();
		if ( 'ok' !== ( $row['status'] ?? '' )
			|| hash( 'sha256', $row['raw'] ) !== $after_witness['settings_sha256'] ) {
			throw new \RuntimeException( 'Reports Profile recovery_required: settings_revision' );
		}
		$changes = self::represented_paths( $this->normalize( $snapshot ) );
		$desired = $row['value'];
		foreach ( $changes as $path => $value ) {
			$node = &$desired;
			foreach ( explode( '.', $path ) as $part ) {
				if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
					throw new \RuntimeException( 'Reports Profile recovery_required: invalid_settings' );
				}
				$node = &$node[ $part ];
			}
			$node = $value;
			unset( $node );
		}
		$result = OptionCas::write( $row['value'], $desired, $row['raw'], $changes );
		if ( 'saved' !== ( $result['status'] ?? '' )
			&& 'unchanged' !== ( $result['status'] ?? '' ) ) {
			throw new \RuntimeException( 'Reports Profile recovery_required: compensation_conflict' );
		}
		if ( ! $this->verify( $snapshot ) ) {
			throw new \RuntimeException( 'Reports Profile recovery_required: compensation_unverified' );
		}
	}

	private function color( string $value ): string {
		$color = sanitize_hex_color( $value );
		return is_string( $color ) && '' !== $color ? strtolower( $color ) : '#0064c8';
	}
}
