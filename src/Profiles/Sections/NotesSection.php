<?php
declare(strict_types=1);

namespace CB\Core\Profiles\Sections;

use CB\Core\Notes\Settings\Defaults;
use CB\Core\Notes\Settings\SettingsRepository;
use CB\Core\Profiles\ExactSection;
use CB\Core\Profiles\SchemaGuard;

defined( 'ABSPATH' ) || exit;

final class NotesSection extends ExactSection {
	public function id(): string { return 'notes'; }
	public function label(): string { return __( 'Notes defaults', 'core-blueprint' ); }
	public function description(): string { return __( 'Default Notes type, status and presentation. Notes, users and assignments are not included.', 'core-blueprint' ); }
	public function schema_version(): int { return 1; }

	public function export(): array {
		$current = SettingsRepository::all();
		return [
			'default_type'          => (string) $current['default_type'],
			'default_status'        => (string) $current['default_status'],
			'details_initial_state' => (string) $current['details_initial_state'],
			'default_layout'        => (string) $current['default_layout'],
		];
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'default_type', 'default_status', 'details_initial_state', 'default_layout' ], 'Notes defaults' );
		$raw = [
			'default_type'          => SchemaGuard::string( $incoming['default_type'] ?? null, 'Notes default type' ),
			'default_status'        => SchemaGuard::string( $incoming['default_status'] ?? null, 'Notes default status' ),
			'details_initial_state' => SchemaGuard::string( $incoming['details_initial_state'] ?? null, 'Notes details state' ),
			'default_layout'        => SchemaGuard::string( $incoming['default_layout'] ?? null, 'Notes default layout' ),
		];
		$sanitized = Defaults::sanitize( array_merge( Defaults::values(), $raw, [ 'enabled' => true, 'default_assigned_to' => 0 ] ) );
		$normalized = [
			'default_type'          => (string) $sanitized['default_type'],
			'default_status'        => (string) $sanitized['default_status'],
			'details_initial_state' => (string) $sanitized['details_initial_state'],
			'default_layout'        => (string) $sanitized['default_layout'],
		];
		if ( $normalized !== $raw ) {
			SchemaGuard::invalid_value( 'Notes defaults' );
		}
		return $normalized;
	}

	public function apply( array $incoming, string $actor ): void {
		unset( $actor );
		SettingsRepository::update( $this->normalize( $incoming ) );
	}
}
