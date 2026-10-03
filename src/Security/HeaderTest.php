<?php
declare(strict_types=1);
/**
 * HeaderTest
 *
 * Diagnostic helper that fetches the site's own homepage over HTTP and
 * reports which security-relevant response headers are present. Used by
 * the dashboard "Run header test" button to give an at-a-glance grade
 * similar to securityheaders.com, but evaluated locally.
 *
 * The test uses wp_safe_remote_get() against home_url('/'), so the request is
 * subject to whatever reverse-proxy, CDN, or .htaccess rules are in front
 * of the WP install - the report reflects what real browsers would see.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Security;

defined( 'ABSPATH' ) || exit;

final class HeaderTest {

	/**
	 * Ordered list of headers the test inspects. Keys are canonical header
	 * names; values describe the header for the UI.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function expected_headers(): array {
		return [
			'X-Frame-Options' => [
				'label'       => __( 'X-Frame-Options', 'core-blueprint' ),
				'description' => __( 'Clickjacking protection', 'core-blueprint' ),
			],
			'X-Content-Type-Options' => [
				'label'       => __( 'X-Content-Type-Options', 'core-blueprint' ),
				'description' => __( 'MIME-sniffing protection', 'core-blueprint' ),
			],
			'Referrer-Policy' => [
				'label'       => __( 'Referrer-Policy', 'core-blueprint' ),
				'description' => __( 'Controls referrer leakage', 'core-blueprint' ),
			],
			'Permissions-Policy' => [
				'label'       => __( 'Permissions-Policy', 'core-blueprint' ),
				'description' => __( 'Restricts browser APIs', 'core-blueprint' ),
			],
			'Strict-Transport-Security' => [
				'label'       => __( 'Strict-Transport-Security', 'core-blueprint' ),
				'description' => __( 'HTTPS enforcement (HSTS) - HTTPS sites only', 'core-blueprint' ),
			],
			'X-XSS-Protection' => [
				'label'       => __( 'X-XSS-Protection', 'core-blueprint' ),
				'description' => __( 'Legacy XSS auditor setting', 'core-blueprint' ),
			],
			'Cross-Origin-Opener-Policy' => [
				'label'       => __( 'Cross-Origin-Opener-Policy', 'core-blueprint' ),
				'description' => __( 'Browsing-context isolation', 'core-blueprint' ),
			],
			'Cross-Origin-Resource-Policy' => [
				'label'       => __( 'Cross-Origin-Resource-Policy', 'core-blueprint' ),
				'description' => __( 'Resource-embedding isolation', 'core-blueprint' ),
			],
			'Content-Security-Policy-Report-Only' => [
				'label'       => __( 'Content-Security-Policy-Report-Only', 'core-blueprint' ),
				'description' => __( 'CSP in monitoring mode', 'core-blueprint' ),
			],
		];
	}

	/**
	 * Run the test. Fetches home_url('/') and returns a structured report.
	 *
	 * @return array{
	 *     url: string,
	 *     status: int,
	 *     results: array<string, array{present: bool, value: string, label: string, description: string}>,
	 *     score: int,
	 *     total: int,
	 *     grade: string,
	 *     error: ?string
	 * }
	 */
	public static function run(): array {
		$url = home_url( '/' );

		$response = wp_safe_remote_get( $url, [
			'timeout'     => 10,
			'redirection' => 5,
			'sslverify'   => apply_filters( 'cb_core_header_test_sslverify', true ),
			'user-agent'  => 'CoreBlueprint/' . CB_CORE_VERSION . ' (header-test)',
			'headers'     => [
				// Ensure we get a representative HTML response, not a conditional 304.
				'Cache-Control' => 'no-cache',
				'Pragma'        => 'no-cache',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'url'     => $url,
				'status'  => 0,
				'results' => [],
				'score'   => 0,
				'total'   => count( self::expected_headers() ),
				'grade'   => 'N/A',
				'error'   => $response->get_error_message(),
			];
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$headers = wp_remote_retrieve_headers( $response );

		// wp_remote_retrieve_headers() returns a Requests_Utility_CaseInsensitiveDictionary
		// on modern WP, which supports array-access via lowercase keys. Normalise to a
		// lowercase-keyed array so we can check each header the same way.
		$normalised = self::normalise_headers( $headers );

		$expected = self::expected_headers();
		$results  = [];
		$score    = 0;

		foreach ( $expected as $header => $info ) {
			$lc_key  = strtolower( $header );
			$present = isset( $normalised[ $lc_key ] );
			$value   = $present ? (string) $normalised[ $lc_key ] : '';

			if ( $present ) {
				$score++;
			}

			$results[ $header ] = [
				'present'     => $present,
				'value'       => $value,
				'label'       => $info['label'],
				'description' => $info['description'],
			];
		}

		return [
			'url'     => $url,
			'status'  => $status,
			'results' => $results,
			'score'   => $score,
			'total'   => count( $expected ),
			'grade'   => self::grade( $score, count( $expected ) ),
			'error'   => null,
		];
	}

	/**
	 * Convert any WP headers structure into a lowercase-keyed associative array.
	 */
	private static function normalise_headers( $headers ): array {
		$out = [];

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$all = $headers->getAll();
			if ( is_array( $all ) ) {
				foreach ( $all as $k => $v ) {
					$out[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
				}
				return $out;
			}
		}

		if ( is_array( $headers ) ) {
			foreach ( $headers as $k => $v ) {
				$out[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
			}
			return $out;
		}

		// Fallback: iterate if the object is Traversable.
		if ( is_object( $headers ) && $headers instanceof \Traversable ) {
			foreach ( $headers as $k => $v ) {
				$out[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
			}
		}

		return $out;
	}

	/**
	 * Translate a raw score to a letter grade.
	 *
	 * Scoring rationale: HSTS being absent on non-HTTPS sites is expected, so
	 * the grade does not penalise that scenario - reaching 8/9 on HTTP is
	 * effectively a "perfect" result for that environment.
	 */
	private static function grade( int $score, int $total ): string {
		if ( $total <= 0 ) {
			return 'N/A';
		}

		$pct = ( $score / $total ) * 100;

		if ( $pct >= 95 ) return 'A+';
		if ( $pct >= 85 ) return 'A';
		if ( $pct >= 70 ) return 'B';
		if ( $pct >= 55 ) return 'C';
		if ( $pct >= 40 ) return 'D';
		return 'F';
	}
}
