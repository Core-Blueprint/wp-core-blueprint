<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Profiles\Sections;

use CoreBlueprint\Core\Permissions\PrivilegedAccessPolicy;
use CoreBlueprint\Core\Profiles\ExactSection;
use CoreBlueprint\Core\Profiles\SchemaGuard;
use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class PermissionsSection extends ExactSection {
	public function id(): string { return 'permissions'; }
	public function label(): string { return __( 'Permissions policy', 'core-blueprint' ); }
	public function description(): string { return __( 'Portable permission governance policy. Operators, approvals, user assignments and trust fingerprints are not included.', 'core-blueprint' ); }
	public function schema_version(): int { return 1; }

	public function export(): array {
		$settings = Settings::get();
		$permissions = is_array( $settings['permissions'] ?? null ) ? $settings['permissions'] : [];
		$alerts = is_array( $permissions['email_alerts'] ?? null ) ? $permissions['email_alerts'] : [];
		$reports = is_array( $settings['reports'] ?? null ) ? $settings['reports'] : [];
		$integrity = is_array( $settings['integrity'] ?? null ) ? $settings['integrity'] : [];
		return [
			'hide_from_admins'       => ! empty( $permissions['hide_from_admins'] ),
			'privileged_access_mode' => PrivilegedAccessPolicy::enforcement_mode(),
			'admin_capabilities'     => [
				'reports_generate_maintenance' => ! empty( $reports['admin_can_generate']['maintenance'] ),
				'integrity_run'                => ! empty( $integrity['admin_can_run'] ),
			],
			'email_alerts'           => [
				'role_change'              => SchemaGuard::bool( $alerts['role_change'] ?? null, 'Permissions role-change alerts' ),
				'operator_guard_triggered' => SchemaGuard::bool( $alerts['operator_guard_triggered'] ?? null, 'Permissions operator-guard alerts' ),
				'privileged_review'        => SchemaGuard::bool( $alerts['privileged_review'] ?? null, 'Permissions privileged-review alerts' ),
			],
		];
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'hide_from_admins', 'privileged_access_mode', 'admin_capabilities', 'email_alerts' ], 'Permissions' );
		$mode = SchemaGuard::string( $incoming['privileged_access_mode'] ?? null, 'Privileged Access Protection mode' );
		if ( ! PrivilegedAccessPolicy::is_valid_mode( $mode ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The profile contains an invalid Privileged Access Protection mode.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$admin_caps = SchemaGuard::object( $incoming['admin_capabilities'] ?? null, 'Permissions administrator capabilities' );
		SchemaGuard::exact_keys( $admin_caps, [ 'reports_generate_maintenance', 'integrity_run' ], 'Permissions administrator capabilities' );
		$alerts = SchemaGuard::object( $incoming['email_alerts'] ?? null, 'Permissions email alerts' );
		SchemaGuard::exact_keys( $alerts, [ 'role_change', 'operator_guard_triggered', 'privileged_review' ], 'Permissions email alerts' );
		return [
			'hide_from_admins'       => SchemaGuard::bool( $incoming['hide_from_admins'] ?? null, 'Permissions visibility' ),
			'privileged_access_mode' => $mode,
			'admin_capabilities' => [
				'reports_generate_maintenance' => SchemaGuard::bool( $admin_caps['reports_generate_maintenance'] ?? null, 'Reports administrator generation access' ),
				'integrity_run' => SchemaGuard::bool( $admin_caps['integrity_run'] ?? null, 'Core Scanner administrator run access' ),
			],
			'email_alerts'           => [
				'role_change'              => SchemaGuard::bool( $alerts['role_change'] ?? null, 'Permissions role-change alerts' ),
				'operator_guard_triggered' => SchemaGuard::bool( $alerts['operator_guard_triggered'] ?? null, 'Permissions operator-guard alerts' ),
				'privileged_review'        => SchemaGuard::bool( $alerts['privileged_review'] ?? null, 'Permissions privileged-review alerts' ),
			],
		];
	}

	public function warnings( array $incoming ): array {
		$incoming = $this->normalize( $incoming );
		$warnings = [];
		if ( PrivilegedAccessPolicy::MODE_MONITOR === $incoming['privileged_access_mode'] ) {
			$warnings[] = __( 'This Profile changes Privileged Access Protection to Monitor mode. Detection and review remain active, but unapproved privileged identities are not restricted by that policy.', 'core-blueprint' );
		}
		if ( $incoming['hide_from_admins'] ) {
			$warnings[] = __( 'This Profile hides Permissions from normal administrators. Approved Core Blueprint Operators retain access.', 'core-blueprint' );
		}
		return $warnings;
	}

	public function apply( array $incoming, string $actor ): void {
		$incoming = $this->normalize( $incoming );
		if ( $this->export() === $incoming ) {
			if ( ! Settings::matches_paths( [
				'permissions.hide_from_admins' => $incoming['hide_from_admins'],
				'permissions.privileged_access_mode' => $incoming['privileged_access_mode'],
				'reports.admin_can_generate.maintenance' => $incoming['admin_capabilities']['reports_generate_maintenance'],
				'integrity.admin_can_run' => $incoming['admin_capabilities']['integrity_run'],
				'permissions.email_alerts.role_change' => $incoming['email_alerts']['role_change'],
				'permissions.email_alerts.operator_guard_triggered' => $incoming['email_alerts']['operator_guard_triggered'],
				'permissions.email_alerts.privileged_review' => $incoming['email_alerts']['privileged_review'],
			] ) ) {
				throw new \RuntimeException( __( 'Could not apply the Permissions profile policy.', 'core-blueprint' ) );
			}
			return;
		}
		// One option CAS across all three policy groups. The unrepresented
		// sibling fields remain outside this Profile's write intent.
		$changes = [
			'permissions.hide_from_admins' => $incoming['hide_from_admins'],
			'permissions.privileged_access_mode' => $incoming['privileged_access_mode'],
			'reports.admin_can_generate.maintenance' => $incoming['admin_capabilities']['reports_generate_maintenance'],
			'integrity.admin_can_run' => $incoming['admin_capabilities']['integrity_run'],
		];
		foreach ( $incoming['email_alerts'] as $key => $enabled ) {
			$changes[ 'permissions.email_alerts.' . $key ] = $enabled;
		}
		if ( ! Settings::set_paths( $changes, $actor ) || $this->export() !== $incoming ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output.
			throw new \RuntimeException( __( 'Could not apply the Permissions profile policy.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

}
