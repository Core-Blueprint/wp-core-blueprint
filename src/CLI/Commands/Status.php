<?php
declare(strict_types=1);
/**
 * Status - `wp cb status` and Console "cb status".
 *
 * Operator-friendly snapshot of this Core Blueprint install.
 *
 * Architecture:
 *   - execute()       - pure data collection, returns Result
 *   - __invoke()      - WP-CLI dispatch wrapper, formats Result for STDOUT
 *   - args_schema()   - declares args for the Console UI
 *   - side_effects()  - 'none', read-only
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository as ScanResults;
use CoreBlueprint\Core\Integrity\State as IntegrityState;
use CoreBlueprint\Core\Notes\State as NotesState;
use CoreBlueprint\Core\Reports\State as ReportsState;
use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Status implements CommandInterface {

	public function execute( array $args ): Result {
		$snapshot = self::collect_snapshot();
		$lines    = self::format_lines( $snapshot );

		return Result::success(
			__( 'Core Blueprint status snapshot', 'core-blueprint' ),
			$lines,
			$snapshot
		);
	}

	public function args_schema(): array {
		return [
			'format' => [
				'type'    => 'select',
				'label'   => __( 'Output format', 'core-blueprint' ),
				'default' => 'text',
				'options' => [ 'text' => 'text', 'json' => 'json', 'yaml' => 'yaml' ],
				'help'    => __( 'CLI display format. Console always renders the structured view; CLI honours this flag.', 'core-blueprint' ),
			],
		];
	}

	public function side_effects(): string {
		return 'none';
	}

	/**
	 * Print an operator snapshot.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb status
	 *     wp cb status --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>     $args
	 * @param array<string, string>  $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result   = $this->execute( $assoc_args );
		$format   = (string) ( $assoc_args['format'] ?? 'text' );
		$snapshot = $result->data ?? [];

		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $snapshot, JSON_PRETTY_PRINT ) );
			return;
		}
		if ( 'yaml' === $format ) {
			\WP_CLI\Utils\format_items( 'yaml', [ $snapshot ], array_keys( $snapshot ) );
			return;
		}

		foreach ( $result->lines as $line ) {
			\WP_CLI::line( $line );
		}
	}

	/** @return array<string, mixed> */
	private static function collect_snapshot(): array {
		return [
			'version'    => CB_CORE_VERSION,
			'site_url'   => home_url(),
			'site_mode'  => Settings::site_mode(),
			'modules'    => self::module_summary(),
			'last_scan'  => self::scan_summary(),
			'updates'    => self::update_counts(),
		];
	}

	/**
	 * @param array<string, mixed> $snap
	 * @return array<int, string>
	 */
	private static function format_lines( array $snap ): array {
		$lines   = [];
		$lines[] = '';
		$lines[] = 'Core Blueprint - Status';
		$lines[] = str_repeat( '─', 60 );
		$lines[] = 'Version:           ' . (string) $snap['version'];
		$lines[] = 'Site URL:          ' . (string) $snap['site_url'];
		$lines[] = 'Site mode:         ' . (string) $snap['site_mode'];
		$lines[] = '';
		$lines[] = 'Modules:';
		foreach ( (array) $snap['modules'] as $module => $enabled ) {
			$lines[] = sprintf( '  %-12s %s', $module . ':', $enabled ? 'on' : 'off' );
		}
		$lines[] = '';
		$lines[] = 'Last scan:';
		$scan = (array) $snap['last_scan'];
		if ( empty( $scan['has_result'] ) ) {
			$lines[] = '  No scan results recorded yet.';
			$lines[] = '  Run `wp cb scan run --user=<user>` to start one.';
		} else {
			$lines[] = '  Status:         ' . (string) ( $scan['status']       ?? '-' );
			$lines[] = '  Completed at:   ' . (string) ( $scan['completed_at'] ?? '-' );
			$lines[] = '  Issues:         ' . (string) ( $scan['issue_count']  ?? 0 );
		}
		$lines[] = '';
		$lines[] = 'Pending updates:';
		$upd     = (array) $snap['updates'];
		$lines[] = '  Core update:    ' . ( ! empty( $upd['core_has_update'] ) ? 'YES' : 'no' );
		$lines[] = '  Plugins:        ' . (int) ( $upd['plugins']      ?? 0 );
		$lines[] = '  Themes:         ' . (int) ( $upd['themes']       ?? 0 );
		$lines[] = '  Translations:   ' . (int) ( $upd['translations'] ?? 0 );
		$lines[] = '';
		return $lines;
	}

	/** @return array<string, bool> */
	private static function module_summary(): array {
		return [
			'reports'   => class_exists( ReportsState::class )    && ReportsState::is_enabled(),
			'notes'     => class_exists( NotesState::class )      && NotesState::is_enabled(),
			'integrity' => class_exists( IntegrityState::class )  && IntegrityState::is_enabled(),
		];
	}

	/** @return array<string, mixed> */
	private static function scan_summary(): array {
		if ( ! class_exists( ScanResults::class ) ) {
			return [ 'has_result' => false ];
		}
		if ( ! ScanResults::hasResult() ) {
			return [ 'has_result' => false ];
		}
		$latest  = ScanResults::getLatest() ?? [];
		$summary = ScanResults::getSummary();
		return [
			'has_result'   => true,
			'status'       => (string) ( $latest['status']       ?? 'unknown' ),
			'completed_at' => (string) ( $latest['completed_at'] ?? '' ),
			'source'       => (string) ( $latest['source']       ?? '' ),
			'issue_count'  => (int)    ( $summary['totals']['issues'] ?? $summary['issues'] ?? 0 ),
		];
	}

	/** @return array<string, int|bool> */
	private static function update_counts(): array {
		if ( ! function_exists( 'wp_get_update_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$data            = function_exists( 'wp_get_update_data' ) ? wp_get_update_data() : [];
		$core_has_update = false;
		if ( function_exists( 'get_core_updates' ) ) {
			foreach ( (array) get_core_updates() as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response ) {
					$core_has_update = true;
					break;
				}
			}
		}
		return [
			'plugins'         => (int)  ( $data['counts']['plugins']      ?? 0 ),
			'themes'          => (int)  ( $data['counts']['themes']       ?? 0 ),
			'translations'    => (int)  ( $data['counts']['translations'] ?? 0 ),
			'core_has_update' => (bool) $core_has_update,
		];
	}

	private static function format_timestamp( $ts ): string {
		$ts = (int) $ts;
		if ( $ts <= 0 ) {
			return 'never';
		}
		return gmdate( 'Y-m-d H:i:s', $ts ) . ' UTC';
	}
}
