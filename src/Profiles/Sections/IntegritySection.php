<?php
declare(strict_types=1);

namespace CB\Core\Profiles\Sections;

use CB\Core\Integrity\Scheduler\Cron;
use CB\Core\Integrity\State;
use CB\Core\Integrity\Storage\ResultRepository;
use CB\Core\Profiles\ExactSection;
use CB\Core\Profiles\SchemaGuard;

defined( 'ABSPATH' ) || exit;

final class IntegritySection extends ExactSection {
	public function id(): string { return 'integrity'; }
	public function label(): string { return __( 'Core Scanner policy', 'core-blueprint' ); }
	public function description(): string { return __( 'Scanner schedule, coverage and alert policy. Scan results and approved baselines are not included.', 'core-blueprint' ); }
	public function schema_version(): int { return 1; }

	public function export(): array {
		$settings = ResultRepository::settings();
		return [
			'schedule'             => (string) ( $settings['schedule'] ?? 'disabled' ),
			'plugin_checksums'     => ! empty( $settings['plugin_checksums'] ),
			'theme_checksums'      => ! empty( $settings['theme_checksums'] ),
			'uploads_scan'         => ! empty( $settings['uploads_scan'] ),
			'max_visible_findings' => (int) ( $settings['max_visible_findings'] ?? 50 ),
			'email_alerts'         => [
				'critical_anomaly' => ! empty( $settings['email_alerts']['critical_anomaly'] ),
				'warning_anomaly'  => ! empty( $settings['email_alerts']['warning_anomaly'] ),
				'resolved'         => ! empty( $settings['email_alerts']['resolved'] ),
			],
		];
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'schedule', 'plugin_checksums', 'theme_checksums', 'uploads_scan', 'max_visible_findings', 'email_alerts' ], 'Core Scanner' );
		$schedule = SchemaGuard::string( $incoming['schedule'] ?? null, 'Core Scanner schedule' );
		if ( ! in_array( $schedule, [ 'disabled', 'daily', 'weekly' ], true ) ) {
			throw new \InvalidArgumentException( __( 'The profile contains an invalid Core Scanner schedule.', 'core-blueprint' ) );
		}
		$alerts = SchemaGuard::object( $incoming['email_alerts'] ?? null, 'Core Scanner email alerts' );
		SchemaGuard::exact_keys( $alerts, [ 'critical_anomaly', 'warning_anomaly', 'resolved' ], 'Core Scanner email alerts' );
		$max_visible = SchemaGuard::int( $incoming['max_visible_findings'] ?? null, 'Core Scanner visible findings limit' );
		if ( $max_visible < 10 || $max_visible > 200 ) {
			SchemaGuard::invalid_value( 'Core Scanner visible findings limit' );
		}
		return [
			'schedule'             => $schedule,
			'plugin_checksums'     => SchemaGuard::bool( $incoming['plugin_checksums'] ?? null, 'Core Scanner plugin checksums' ),
			'theme_checksums'      => SchemaGuard::bool( $incoming['theme_checksums'] ?? null, 'Core Scanner theme checksums' ),
			'uploads_scan'         => SchemaGuard::bool( $incoming['uploads_scan'] ?? null, 'Core Scanner uploads scan' ),
			'max_visible_findings' => $max_visible,
			'email_alerts'         => [
				'critical_anomaly' => SchemaGuard::bool( $alerts['critical_anomaly'] ?? null, 'Core Scanner critical anomaly alerts' ),
				'warning_anomaly'  => SchemaGuard::bool( $alerts['warning_anomaly'] ?? null, 'Core Scanner warning anomaly alerts' ),
				'resolved'         => SchemaGuard::bool( $alerts['resolved'] ?? null, 'Core Scanner resolved alerts' ),
			],
		];
	}

	public function apply( array $incoming, string $actor ): void {
		$incoming = $this->normalize( $incoming );
		ResultRepository::saveSettings( $incoming );
		if ( State::is_enabled() ) {
			Cron::sync_schedule();
		} else {
			Cron::clear_schedule();
		}
	}
}
