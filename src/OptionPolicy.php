<?php
declare(strict_types=1);
/** Runtime hot/cold option policy for Core Blueprint Base. */

namespace CB\Core;

defined( 'ABSPATH' ) || exit;

final class OptionPolicy {
	private const VERSION = 1;
	private const VERSION_OPTION = 'cb_core_option_policy_version';

	/** Small values read on normal requests and safe for WordPress alloptions. */
	private const HOT_OPTIONS = [
		'cb_core_settings',
		'cb_core_access_mode',
		'cb_core_db_version',
		'cb_core_mail_log_db_version',
		'cb_core_notes_db_version',
		'cb_core_reports_db_version',
		'cb_core_db_health_checked_at',
	];

	public static function maybe_sync(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::VERSION ) {
			return;
		}
		self::sync_active();
	}

	public static function sync_active(): void {
		$values = [];
		foreach ( self::HOT_OPTIONS as $option ) {
			$values[ $option ] = true;
		}
		wp_set_option_autoload_values( $values );
		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	public static function mark_inactive(): void {
		$values = [];
		foreach ( self::HOT_OPTIONS as $option ) {
			$values[ $option ] = false;
		}
		wp_set_option_autoload_values( $values );
		delete_option( self::VERSION_OPTION );
	}
}
