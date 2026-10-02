<?php
declare(strict_types=1);
/**
 * Diag\I18n - `wp cb diag i18n`.
 *
 * Read-only translation-loading diagnostic.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CB\Core\CLI\Commands\Diag;

use CB\Core\Console\CommandInterface;
use CB\Core\Console\Result;

defined( 'ABSPATH' ) || exit;

final class I18n implements CommandInterface {

	public function execute( array $args ): Result {
		$locale          = determine_locale();
		$wp_locale       = get_locale();
		$mo_plugin       = WP_PLUGIN_DIR . '/core-blueprint/languages/core-blueprint-' . $locale . '.mo';
		$mo_wp_languages = WP_LANG_DIR . '/plugins/core-blueprint-' . $locale . '.mo';

		$plugin_exists = file_exists( $mo_plugin );
		$wp_exists     = file_exists( $mo_wp_languages );
		$loaded        = is_textdomain_loaded( 'core-blueprint' );

		$lines   = [];
		$lines[] = '';
		$lines[] = 'Core Blueprint - i18n diagnostic';
		$lines[] = str_repeat( '─', 60 );
		$lines[] = 'WP get_locale():        ' . $wp_locale;
		$lines[] = 'determine_locale():     ' . $locale;
		$lines[] = '';
		$lines[] = 'MO file (plugin dir):';
		$lines[] = '  path:   ' . $mo_plugin;
		$lines[] = '  exists: ' . ( $plugin_exists ? 'YES' : 'no' );
		if ( $plugin_exists ) {
			$lines[] = '  size:   ' . number_format( filesize( $mo_plugin ) ) . ' bytes';
			$lines[] = '  mtime:  ' . date( 'Y-m-d H:i:s', filemtime( $mo_plugin ) );
		}
		$lines[] = '';
		$lines[] = 'MO file (WP_LANG_DIR/plugins/):';
		$lines[] = '  path:   ' . $mo_wp_languages;
		$lines[] = '  exists: ' . ( $wp_exists ? 'YES - this overrides the plugin-bundled MO' : 'no' );
		if ( $wp_exists ) {
			$lines[] = '  size:   ' . number_format( filesize( $mo_wp_languages ) ) . ' bytes';
			$lines[] = '  mtime:  ' . date( 'Y-m-d H:i:s', filemtime( $mo_wp_languages ) );
		}
		$lines[] = '';
		$lines[] = "is_textdomain_loaded( 'core-blueprint' ): " . ( $loaded ? 'YES' : 'NO' );

		// Round-trip probe.
		$probe_in  = 'Reports';
		$probe_out = __( 'Reports', 'core-blueprint' );
		$lines[]   = '';
		$lines[]   = 'Round-trip probe:';
		$lines[]   = "  __( '" . $probe_in . "' )  →  '" . $probe_out . "'";
		$lines[]   = '';

		$translation_works = $probe_in !== $probe_out;

		$data = [
			'locale'                    => $locale,
			'wp_locale'                 => $wp_locale,
			'mo_plugin_path'            => $mo_plugin,
			'mo_plugin_exists'          => $plugin_exists,
			'mo_wp_languages_path'      => $mo_wp_languages,
			'mo_wp_languages_exists'    => $wp_exists,
			'textdomain_loaded'         => $loaded,
			'round_trip_input'          => $probe_in,
			'round_trip_output'         => $probe_out,
			'translation_appears_to_work' => $translation_works,
		];

		if ( $wp_exists ) {
			return Result::warning(
				__( 'A copy in WP_LANG_DIR shadows the plugin-bundled MO. Delete it to fall back to the plugin version.', 'core-blueprint' ),
				$lines,
				$data
			);
		}
		if ( ! $translation_works && 'en_US' !== $locale ) {
			return Result::warning(
				__( 'Translation appears not to be applied - string returned unchanged.', 'core-blueprint' ),
				$lines,
				$data
			);
		}

		return Result::success(
			__( 'Translation system OK.', 'core-blueprint' ),
			$lines,
			$data
		);
	}

	public function args_schema(): array {
		return [];
	}

	public function side_effects(): string {
		return 'none';
	}

	/**
	 * Diagnose translation loading.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb diag i18n
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = $this->execute( $assoc_args );
		foreach ( $result->lines as $line ) {
			\WP_CLI::line( $line );
		}
		if ( 'warning' === $result->status ) {
			\WP_CLI::warning( $result->message );
		}
	}
}
