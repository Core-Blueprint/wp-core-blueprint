<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use function array_unique;
use function array_values;
use function defined;
use function function_exists;
use function get_available_languages;
use function get_locale;
use function get_site_option;
use function is_array;
use function is_file;
use function is_string;
use function md5_file;
use function strtolower;
use function wp_normalize_path;

use const ABSPATH;

defined( 'ABSPATH' ) || exit;

/**
 * Detects which official WordPress distribution-locale is installed on disk.
 *
 * The architectural problem this solves
 * -------------------------------------
 *
 * `get_locale()` returns the UI-rendering locale of the WordPress
 * installation. That is NOT the same as "which official WordPress
 * distribution was downloaded onto this disk". The two diverge when:
 *
 *   - A site is installed as en_US and later switched to nl_NL via
 *     Settings → General → Site Language. WordPress does NOT re-download
 *     core files on a UI-locale switch - it only changes the active
 *     language pack. Result: core files are still the en_US distribution
 *     while `get_locale()` returns 'nl_NL'.
 *
 *   - A multilingual plugin (WPML, Polylang) manipulates `get_locale()`
 *     per-request. The scanner runs in admin context where the locale
 *     might be different than in front-end context.
 *
 *   - WordPress locale resolution (including WPLANG when explicitly
 *     defined) points at a UI locale whose installed core distribution
 *     differs from the files present on disk.
 *
 * For checksum verification, we need the DISTRIBUTION-locale, not the
 * UI-locale. The discriminator file is `wp-includes/version.php`: its
 * checksum differs per official distribution (e.g. en_US vs nl_NL),
 * because non-en_US distributions historically include a
 * `$wp_local_package` line that en_US does not.
 *
 * Other core PHP files (`wp-includes/load.php`, `wp-settings.php`) are
 * locale-agnostic - their checksums are identical across all official
 * distributions for a given WordPress version. We use those for an
 * integrity cross-check during detection: if `version.php` matches one
 * locale but `load.php` or `wp-settings.php` mismatches every locale,
 * something is wrong on a deeper level than locale-drift, and the
 * detection result should be marked as inconclusive so the caller
 * surfaces a tampering finding rather than a drift finding.
 *
 * This class is intentionally stateless. It performs no option reads
 * or writes; the caller decides whether to persist the result. This
 * keeps detection testable in isolation and lets the same logic be
 * re-used during a manual "Re-detect" action and during a lazy
 * mismatch-triggered detection on scan time.
 *
 * @since   1.0.0
 */
final class LocaleDetector {
	/**
	 * Path of the locale-discriminator file, relative to ABSPATH.
	 *
	 * `wp-includes/version.php` is the only core PHP file whose content
	 * differs across official locale distributions (because of the
	 * `$wp_local_package` line in non-en_US builds).
	 */
	public const DISCRIMINATOR_FILE = 'wp-includes/version.php';

	/**
	 * Locale-agnostic core files used for integrity cross-check.
	 *
	 * If these mismatch every candidate locale, detection is
	 * inconclusive - there is something wrong beyond locale drift,
	 * and the caller should surface that as tampering rather than
	 * silently picking a "best match" locale for the discriminator.
	 *
	 * @var array<int,string>
	 */
	public const CROSS_CHECK_FILES = [
		'wp-includes/load.php',
		'wp-settings.php',
	];

	/**
	 * Run detection and return the result.
	 *
	 * @param string $wp_version The current WordPress version string.
	 *
	 * @return array{
	 *   detected: ?string,
	 *   tried: array<int,string>,
	 *   matched_file: string,
	 *   cross_check: 'ok'|'failed'|'skipped'|'unavailable',
	 *   reason: 'first_match'|'no_match'|'api_unavailable'|'discriminator_missing',
	 * }
	 */
	public function detect( string $wp_version ): array {
		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$discriminator_path = wp_normalize_path( ABSPATH . self::DISCRIMINATOR_FILE );

		if ( ! is_file( $discriminator_path ) ) {
			return [
				'detected'     => null,
				'tried'        => [],
				'matched_file' => self::DISCRIMINATOR_FILE,
				'cross_check'  => 'skipped',
				'reason'       => 'discriminator_missing',
			];
		}

		$disk_hash = md5_file( $discriminator_path );
		if ( ! is_string( $disk_hash ) ) {
			return [
				'detected'     => null,
				'tried'        => [],
				'matched_file' => self::DISCRIMINATOR_FILE,
				'cross_check'  => 'skipped',
				'reason'       => 'discriminator_missing',
			];
		}

		$disk_hash = strtolower( $disk_hash );
		$candidates = $this->candidate_locales();
		$tried      = [];

		foreach ( $candidates as $locale ) {
			$tried[]   = $locale;
			$checksums = \get_core_checksums( $wp_version, $locale );

			if ( ! is_array( $checksums ) ) {
				continue;
			}

			$expected = $checksums[ self::DISCRIMINATOR_FILE ] ?? null;
			if ( ! is_string( $expected ) ) {
				continue;
			}

			if ( strtolower( $expected ) !== $disk_hash ) {
				continue;
			}

			// Discriminator matched. Now run the cross-check: do the
			// locale-agnostic core files also match this checksum
			// payload? If they don't, this is not locale-drift but
			// something deeper - refuse to confirm a detection so the
			// caller surfaces tampering rather than a misleading drift.
			$cross = $this->cross_check( $checksums );

			return [
				'detected'     => $locale,
				'tried'        => $tried,
				'matched_file' => self::DISCRIMINATOR_FILE,
				'cross_check'  => $cross,
				// 'first_match' wins regardless of cross_check outcome;
				// the caller interprets cross_check === 'failed' as a
				// signal to NOT pin the detection result.
				'reason'       => 'first_match',
			];
		}

		// No candidate matched the discriminator's disk hash.
		return [
			'detected'     => null,
			'tried'        => $tried,
			'matched_file' => self::DISCRIMINATOR_FILE,
			'cross_check'  => 'skipped',
			'reason'       => [] === $tried ? 'api_unavailable' : 'no_match',
		];
	}

	/**
	 * Candidate locales tried in order of likelihood.
	 *
	 * Order rationale:
	 *   1. get_locale() - current WordPress site/UI locale resolution
	 *   2. en_US - fallback for sites whose installed distribution remains
	 *      English while the configured UI locale changed later
	 *   3. WPLANG site_option - multisite/network default candidate
	 *   4. get_available_languages() - installed language packs that
	 *      may correspond to a previously-downloaded distribution
	 *
	 * Duplicates are removed while preserving order so an early
	 * candidate that also appears later doesn't get re-tried.
	 *
	 * @return array<int,string>
	 */
	private function candidate_locales(): array {
		$candidates = [];

		$ui_locale = (string) get_locale();
		if ( '' !== $ui_locale ) {
			$candidates[] = $ui_locale;
		}

		$candidates[] = 'en_US';


		if ( function_exists( 'get_site_option' ) ) {
			$site_wplang = (string) get_site_option( 'WPLANG', '' );
			if ( '' !== $site_wplang ) {
				$candidates[] = $site_wplang;
			}
		}

		if ( function_exists( 'get_available_languages' ) ) {
			$installed = get_available_languages();
			if ( is_array( $installed ) ) {
				foreach ( $installed as $locale ) {
					if ( is_string( $locale ) && '' !== $locale ) {
						$candidates[] = $locale;
					}
				}
			}
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Run the integrity cross-check.
	 *
	 * For every locale-agnostic file in CROSS_CHECK_FILES, verify that
	 * the on-disk md5 matches the expected hash from the supplied
	 * checksum payload. The payload is the one returned by
	 * `get_core_checksums()` for the locale that just matched the
	 * discriminator.
	 *
	 * Cross-check semantics:
	 *   - 'ok'       - all cross-check files matched
	 *   - 'failed'   - at least one mismatched (signals tampering)
	 *   - 'skipped'  - file not present on disk (don't fail detection,
	 *                  but the caller may want to surface this)
	 *
	 * @param array<string,string> $checksums Payload from get_core_checksums().
	 */
	private function cross_check( array $checksums ): string {
		$any_checked = false;

		foreach ( self::CROSS_CHECK_FILES as $relative ) {
			$expected = $checksums[ $relative ] ?? null;
			if ( ! is_string( $expected ) ) {
				continue;
			}

			$absolute = wp_normalize_path( ABSPATH . $relative );
			if ( ! is_file( $absolute ) ) {
				continue;
			}

			$actual = md5_file( $absolute );
			if ( ! is_string( $actual ) ) {
				continue;
			}

			$any_checked = true;

			if ( strtolower( $actual ) !== strtolower( $expected ) ) {
				return 'failed';
			}
		}

		return $any_checked ? 'ok' : 'skipped';
	}
}
