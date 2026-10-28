<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only primary-DB native-v1 admission for the one Designer authority.
 * No A2.2 conversions, legacy fallback, data mutation or cached option reads.
 * A missing native row is permissible only without historical/conflicting
 * source data and with a verified native document schema.
 *
 * @internal
 */
final class Authority {
	public const ACTIVE_OPTION = 'cb_core_design_docs_cutover';
	public const EVER_ACTIVE_OPTION = 'cb_core_design_docs_ever_active_v1';
	private const PREPARED_OPTION = 'cb_core_design_docs_prepared_v1';
	private const BACKUP_OPTION = 'cb_core_design_docs_migration_backup_v1';
	private const SETTINGS_OPTION = 'cb_core_settings';

	/** @return array{status:string} */
	public static function inspect( string $resource_type = Repository::MAIL ): array {
		global $wpdb;
		if ( ! in_array( $resource_type, [ Repository::MAIL, Repository::REPORTS ], true ) ) {
			return [ 'status' => 'blocked' ];
		}
		if ( ! is_object( $wpdb ) || ! is_string( $wpdb->options ) || '' === $wpdb->options
			|| (int) get_current_blog_id() <= 0 || ! Schema::healthy() ) {
			return [ 'status' => 'blocked' ];
		}

		// Site-bound physical reads, not get_option() or cached defaults.
		$names = [
			self::ACTIVE_OPTION,
			self::EVER_ACTIVE_OPTION,
			self::PREPARED_OPTION,
			self::BACKUP_OPTION,
			\CoreBlueprint\Core\Mail\Designer\TemplateRepository::OPTION,
			self::SETTINGS_OPTION,
		];
		$rows = [];
		foreach ( $names as $name ) {
			$wpdb->last_error = '';
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
					$wpdb->options,
					$name
				),
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error
				|| ( null !== $row && ! is_array( $row ) ) ) {
				return [ 'status' => 'blocked' ];
			}
			$rows[$name] = $row;
		}

		// Global migration markers block both domains. A historic Mail override
		// must never be mistaken for Reports data, but still blocks Mail.
		foreach ( array_slice( $names, 0, 5 ) as $name ) {
			if ( Repository::REPORTS === $resource_type
				&& \CoreBlueprint\Core\Mail\Designer\TemplateRepository::OPTION === $name ) {
				continue;
			}
			if ( null !== $rows[$name] ) {
				return [ 'status' => 'blocked' ];
			}
		}

		// Other Base settings (retention, permissions, etc.) are independent.
		// Old customized Reports design must not be masked by native defaults.
		if ( null !== $rows[self::SETTINGS_OPTION]
			&& ! self::reports_design_is_default( $rows[self::SETTINGS_OPTION] ) ) {
			return [ 'status' => 'blocked' ];
		}
		return [ 'status' => 'active' ];
	}

	/** @param array<string,mixed> $row */
	private static function reports_design_is_default( array $row ): bool {
		$raw = $row['option_value'] ?? null;
		if ( ! is_string( $raw ) || '' === $raw ) return false;
		try {
			// Reject malformed serialization without instantiating PHP objects.
			$settings = @unserialize( $raw, [ 'allowed_classes' => false ] );
			if ( ! is_array( $settings ) || serialize( $settings ) !== $raw ) return false;
			if ( ! array_key_exists( 'reports', $settings ) ) return true;
			$reports = $settings['reports'];
			$defaults = \CoreBlueprint\Core\Settings::defaults()['reports'] ?? null;
			if ( ! is_array( $reports ) || ! is_array( $defaults ) ) return false;
			foreach ( [ 'branding', 'composer' ] as $key ) {
				if ( array_key_exists( $key, $reports )
					&& ( ! array_key_exists( $key, $defaults )
						|| $reports[$key] !== $defaults[$key] ) ) return false;
			}
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	private function __construct() {}
}
