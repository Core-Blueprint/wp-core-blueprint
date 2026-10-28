<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Profiles\Sections;

use CoreBlueprint\Core\Profiles\ExactSection;
use CoreBlueprint\Core\Profiles\SchemaGuard;
use CoreBlueprint\Core\Profiles\VersionGuardedSectionInterface;
use CoreBlueprint\Core\Profiles\ReportsProfileTransaction;
use CoreBlueprint\Core\Settings\OptionCas;
use CoreBlueprint\Core\Reports\Composer\NativeComposition;
use CoreBlueprint\Core\Settings;
use CoreBlueprint\Core\Design\Document\Authority;
use CoreBlueprint\Core\Reports\DesignDocumentAdapter;

defined( 'ABSPATH' ) || exit;

final class ReportsSection extends ExactSection implements VersionGuardedSectionInterface {
	public function id(): string { return 'reports'; }
	public function label(): string { return __( 'Reports policy', 'core-blueprint' ); }
	public function description(): string { return __( 'Report retention, branding and complete native Reports project. Report snapshots and recipient addresses are not included.', 'core-blueprint' ); }
	public function schema_version(): int { return 2; }

	public function export(): array {
		$reports = Settings::get()['reports'] ?? [];
		$live = DesignDocumentAdapter::current();
		// Native Reports persists one strict {project,fields} document.
		// Never read the removed pre-native `branding` document envelope.
		$branding = $live['fields'];
		$alerts = is_array( $reports['email_alerts'] ?? null ) ? $reports['email_alerts'] : [];
		return [
			'retention_days' => max( 7, min( 3650, (int) ( $reports['retention_days'] ?? 365 ) ) ),
			'email_alerts' => [ 'generation_failed' => ! empty( $alerts['generation_failed'] ) ],
			'branding' => [
				'provider_name'    => sanitize_text_field( (string) ( $branding['provider_name'] ?? '' ) ),
				'provider_contact' => sanitize_text_field( (string) ( $branding['provider_contact'] ?? '' ) ),
				'accent_color'     => $this->color( (string) ( $branding['accent_color'] ?? '#0064c8' ) ),
			],
			'project' => $live['project'],
		];
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'retention_days', 'email_alerts', 'branding', 'project' ], 'Reports' );
		$branding = SchemaGuard::object( $incoming['branding'] ?? null, 'Reports branding' );
		SchemaGuard::exact_keys( $branding, [ 'provider_name', 'provider_contact', 'accent_color' ], 'Reports branding' );
		$alerts = SchemaGuard::object( $incoming['email_alerts'] ?? null, 'Reports email alerts' );
		SchemaGuard::exact_keys( $alerts, [ 'generation_failed' ], 'Reports email alerts' );
		$project = SchemaGuard::object( $incoming['project'] ?? null, 'Reports native project' );
		try {
			NativeComposition::assert_project( $project );
		} catch ( \Throwable $error ) {
			SchemaGuard::invalid_value( 'Reports native project' );
		}
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
		return [
			'retention_days' => $retention,
			'email_alerts' => [ 'generation_failed' => SchemaGuard::bool( $alerts['generation_failed'] ?? null, 'Reports generation-failed alerts' ) ],
			'branding' => [
				'provider_name'    => $provider_name,
				'provider_contact' => $provider_contact,
				'accent_color'     => strtolower( $accent_color ),
			],
			'project' => $project,
		];
	}

	/** Preview is read-only for a healthy active authority. */
	public function preflight( array $incoming, array $current ): void {
		unset( $incoming, $current );
		$this->version_witness();
	}

	/** @return array<string,string> */
	public function version_witness(): array {
		$authority = (string) ( Authority::inspect( Repository::REPORTS )['status'] ?? 'blocked' );
		if ( 'active' !== $authority ) {
			throw new \RuntimeException( 'Reports Profile recovery_required: authority' );
		}
		$row = OptionCas::read();
		if ( 'ok' !== ( $row['status'] ?? '' ) ) {
			throw new \RuntimeException( 'Reports Profile recovery_required: settings_unavailable' );
		}
		$document = DesignDocumentAdapter::read();
		if ( 'found' !== ( $document['status'] ?? '' )
			|| ! is_string( $row['raw'] ?? null )
			|| ! is_array( $document['document'] ?? null ) ) {
			throw new \RuntimeException( 'Reports Profile recovery_required: document_unavailable' );
		}
		return [
			'authority'         => $authority,
			'settings_sha256'   => hash( 'sha256', $row['raw'] ),
			'document_revision' => (string) $document['revision'],
			'document_sha256'   => ReportsProfileTransaction::document_hash( $document['document'] ),
		];
	}

	/** Canonical active authority needs an intact read-only transaction witness. */
	public function assert_apply_allowed(): void {
		$authority = (string) ( Authority::inspect( Repository::REPORTS )['status'] ?? 'blocked' );
		if ( 'active' === $authority ) {
			$full = $this->version_witness();
			$transaction = ReportsProfileTransaction::witness();
			if ( 'ok' !== ( $transaction['status'] ?? '' )
				|| ( $transaction['witness']['settings_sha256'] ?? null ) !== $full['settings_sha256']
				|| ( $transaction['witness']['document_revision'] ?? null ) !== $full['document_revision']
				|| ( $transaction['witness']['document_sha256'] ?? null ) !== $full['document_sha256'] ) {
				throw new \RuntimeException( 'Reports Profile recovery_required: canonical_authority' );
			}
			return;
		}
		// Schema v2 has no legacy or prepared authority fallback.
		throw new \RuntimeException( 'REPORTS_PROFILE_ACTIVE_AUTHORITY_REQUIRED' );
	}

	/** Engine-only synchronous transaction result; generic apply remains void. */
	public function apply_profile_guarded( array $incoming, string $actor, array $approved ): array {
		unset( $actor );
		if ( 'active' !== ( $approved['authority'] ?? null )
			|| 'active' !== ( Authority::inspect( Repository::REPORTS )['status'] ?? null )
			|| ! isset( $approved['settings_sha256'], $approved['document_revision'], $approved['document_sha256'] )
			|| count( $approved ) !== 4 ) {
			return [ 'status' => 'blocked', 'reason' => 'missing_engine_witness' ];
		}
		$this->assert_apply_allowed();
		$expected = $approved;
		unset( $expected['authority'] );
		return ReportsProfileTransaction::apply_profile_guarded( $this->normalize( $incoming ), $expected );
	}

	/** No generic Section API may bypass the native, Engine-scoped transaction. */
	public function apply( array $incoming, string $actor ): void {
		unset( $incoming, $actor );
		throw new \RuntimeException( 'REPORTS_PROFILE_GUARDED_APPLY_REQUIRED' );
	}

	public function restore( array $snapshot, array $incoming, string $actor ): void {
		unset( $snapshot, $incoming, $actor );
		throw new \RuntimeException( 'REPORTS_PROFILE_GUARDED_RESTORE_REQUIRED' );
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
		if ( 'active' === ( $before_witness['authority'] ?? null ) ) {
			if ( null === $after_witness ) {
				if ( $now === $before_witness ) {
					return;
				}
				throw new \RuntimeException( 'Reports Profile recovery_required: missing_post_witness' );
			}
			$committed = [
				'authority'         => 'active',
				'settings_sha256'   => $after_witness['settings_sha256'] ?? null,
				'document_revision' => $after_witness['document_revision'] ?? null,
				'document_sha256'   => $after_witness['document_sha256'] ?? null,
			];
			if ( count( $after_witness ) !== 3 || $now !== $committed ) {
				throw new \RuntimeException( 'Reports Profile recovery_required: concurrent_revision' );
			}
			$result = ReportsProfileTransaction::reverse_profile_guarded(
				$this->normalize( $snapshot ), $after_witness
			);
			$restored = is_array( $result['witness'] ?? null ) ? [
				'authority'         => 'active',
				'settings_sha256'   => $result['witness']['settings_sha256'] ?? null,
				'document_revision' => $result['witness']['document_revision'] ?? null,
				'document_sha256'   => $result['witness']['document_sha256'] ?? null,
			] : null;
			// Compensation advances the document revision. The represented
			// snapshot must be restored, but its OLD revision cannot reappear.
			if ( ! in_array( $result['status'] ?? '', [ 'saved', 'unchanged' ], true )
				|| null === $restored
				|| ! $this->verify( $snapshot )
				|| $this->version_witness() !== $restored ) {
				throw new \RuntimeException( 'Reports Profile recovery_required: compensation_unverified' );
			}
			return;
		}
		throw new \RuntimeException( 'REPORTS_PROFILE_ACTIVE_AUTHORITY_REQUIRED' );
	}

	private function color( string $value ): string {
		$color = sanitize_hex_color( $value );
		return is_string( $color ) && '' !== $color ? strtolower( $color ) : '#0064c8';
	}
}
