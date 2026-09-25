<?php
declare(strict_types=1);

namespace CB\Core\Profiles\Sections;

use CB\Core\Log\AuditLog;
use CB\Core\Profiles\ExactSection;
use CB\Core\Profiles\SchemaGuard;
use CB\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class AuditNotificationsSection extends ExactSection {
	public function id(): string { return 'audit-notifications'; }
	public function label(): string { return __( 'Audit notifications', 'core-blueprint' ); }
	public function description(): string { return __( 'Severity-based audit notification policy. Recipient addresses remain site-local and are not included.', 'core-blueprint' ); }
	public function schema_version(): int { return 1; }

	public function export(): array {
		$settings = Settings::get();
		$audit = is_array( $settings['audit'] ?? null ) ? $settings['audit'] : [];
		$alerts = is_array( $audit['email_alerts'] ?? null ) ? $audit['email_alerts'] : [];
		$normalized = [];

		foreach ( AuditLog::SEVERITIES as $severity ) {
			$normalized[ $severity ] = ! empty( $alerts[ $severity ] );
		}

		return [ 'email_alerts' => $normalized ];
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'email_alerts' ], 'audit notifications' );
		$raw = SchemaGuard::object( $incoming['email_alerts'] ?? null, 'audit notification policy' );
		SchemaGuard::exact_keys( $raw, AuditLog::SEVERITIES, 'audit notification policy' );

		$alerts = [];
		foreach ( AuditLog::SEVERITIES as $severity ) {
			$alerts[ $severity ] = SchemaGuard::bool( $raw[ $severity ] ?? null, 'audit notification ' . $severity );
		}

		return [ 'email_alerts' => $alerts ];
	}

	public function apply( array $incoming, string $actor ): void {
		$incoming = $this->normalize( $incoming );
		$settings = Settings::get();
		$audit = is_array( $settings['audit'] ?? null ) ? $settings['audit'] : [];
		$audit['email_alerts'] = $incoming['email_alerts'];

		if ( ! Settings::set_key( 'audit', $audit, $actor ) && $this->export() !== $incoming ) {
			throw new \RuntimeException( __( 'Could not apply the audit notification policy.', 'core-blueprint' ) );
		}
	}
}
