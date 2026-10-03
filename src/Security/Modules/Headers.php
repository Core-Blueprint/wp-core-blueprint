<?php
declare(strict_types=1);
/**
 * Headers
 *
 * Emits HTTP response headers that instruct browsers to apply stricter
 * same-origin, content-type, referrer, and permission policies. None of
 * these headers restrict the WordPress admin - they affect how browsers
 * render and interact with the site.
 *
 * All headers are toggled individually. HSTS and CSP are flagged as higher
 * risk because a misconfiguration can be disruptive:
 *   - HSTS with max-age pins browsers to HTTPS even after the header stops
 *     being sent. Enable only on sites fully committed to HTTPS.
 *   - CSP in enforce mode can break themes and plugins that rely on inline
 *     scripts or styles. This module only ships CSP in Report-Only mode -
 *     violations are visible in browser dev tools but nothing is blocked.
 *
 * Headers are emitted on three hooks so they cover frontend, wp-admin, and
 * wp-login.php:
 *   - send_headers : frontend page loads
 *   - admin_init   : wp-admin
 *   - login_init   : wp-login.php
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Security\Modules;

use CoreBlueprint\Core\Settings;
use CoreBlueprint\Core\Security\AbstractModule;

defined( 'ABSPATH' ) || exit;

final class Headers extends AbstractModule {

	/**
	 * Default Content-Security-Policy-Report-Only directive set. Permissive
	 * enough to run alongside most themes without breaking them, strict enough
	 * that real XSS payloads generate violation events in the browser console.
	 *
	 * Users who want to tighten this further can filter 'cb_core_csp_report_only'.
	 */
	const CSP_REPORT_ONLY_DEFAULT = "default-src 'self' data: https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: blob: https:; font-src 'self' data: https:; object-src 'none'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";

	public function slug(): string {
		return 'headers';
	}

	public function label(): string {
		return __( 'Security headers', 'core-blueprint' );
	}

	public function description(): string {
		return __( 'Adds HTTP response headers that enable modern browser security protections against clickjacking, MIME sniffing, cross-origin attacks, and XSS.', 'core-blueprint' );
	}

	/**
	 * Plain-language module description.
	 */
	public function description_plain(): string {
		return __( 'Sends modern security instructions with every page so visitor browsers know what is and isn\'t allowed. These instructions protect against clickjacking (an attacker placing your site invisibly on another page), MIME-sniffing (malicious files pretending to be images), and accidental data leaks. Visitors don\'t notice anything; their browser simply handles things more strictly behind the scenes.', 'core-blueprint' );
	}

	public function features(): array {
		return [
			[
				'id'          => 'x_frame_options',
				'label'       => __( 'X-Frame-Options: SAMEORIGIN', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Sends X-Frame-Options: SAMEORIGIN to prevent the site from being rendered inside an <iframe> on third-party domains. Mitigates clickjacking.', 'core-blueprint' ),
					'plain'     => __( 'Prevents other websites from hiding your site invisibly in a frame on their page. Without this setting an attacker can overlay your login page onto a fake site and trick visitors into clicking invisible buttons (clickjacking).', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'low',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP', 'ref' => 'Secure Headers', 'url' => 'https://owasp.org/www-project-secure-headers/', 'note' => 'Clickjacking protection' ],
					[ 'type' => 'cwe',      'ref'  => 'CWE-1021', 'note' => 'Improper Restriction of Rendered UI Layers or Frames' ],
				],
			],
			[
				'id'          => 'x_content_type_options',
				'label'       => __( 'X-Content-Type-Options: nosniff', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Sends X-Content-Type-Options: nosniff to disable browser MIME-type sniffing. Prevents execution of content that was uploaded with a different Content-Type than the browser would infer.', 'core-blueprint' ),
					'plain'     => __( 'Makes browsers handle files exactly as the server delivers them and not try to "guess". Without this setting an attacker can upload a script disguised as an image and have the browser execute it as a program.', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'none',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP', 'ref' => 'Secure Headers', 'url' => 'https://owasp.org/www-project-secure-headers/', 'note' => 'MIME-sniffing protection' ],
					[ 'type' => 'cwe',      'ref'  => 'CWE-430', 'note' => 'Deployment of Wrong Handler' ],
				],
			],
			[
				'id'          => 'referrer_policy',
				'label'       => __( 'Referrer-Policy: strict-origin-when-cross-origin', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Sends Referrer-Policy: strict-origin-when-cross-origin so full URLs do not leak in Referer headers to external sites. Same-origin navigation still sends the full URL.', 'core-blueprint' ),
					'plain'     => __( 'Ensures that when clicking an external link the full page path is not sent along - only the main domain. This prevents leaking details about forms, searches or personal pages to third parties.', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'none',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP', 'ref' => 'Secure Headers', 'url' => 'https://owasp.org/www-project-secure-headers/', 'note' => 'Prevents referrer leakage' ],
					[ 'type' => 'cwe',      'ref'  => 'CWE-200', 'note' => 'Exposure of sensitive information to an unauthorized actor' ],
				],
			],
			[
				'id'          => 'permissions_policy',
				'label'       => __( 'Permissions-Policy: disable high-risk browser features', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Sends a Permissions-Policy header that explicitly denies camera, microphone, geolocation, payment, USB, and other sensor APIs. Embedded iframes cannot request these without the site explicitly allowlisting them.', 'core-blueprint' ),
					'plain'     => __( 'Tells browsers the site doesn\'t need access to camera, microphone, location, payment APIs or USB devices. Without this setting a malicious script or placed advertisement on the site could request such access. GDPR-relevant for municipal and healthcare sites.', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'low',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP', 'ref' => 'Secure Headers', 'url' => 'https://owasp.org/www-project-secure-headers/', 'note' => 'Restricts browser feature access' ],
				],
			],
			[
				'id'          => 'strict_transport_security',
				'label'       => __( 'Strict-Transport-Security (HSTS)', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Sends Strict-Transport-Security with a 1-year max-age, forcing HTTPS enforcement by the browser. Only emitted on HTTPS requests (is_ssl()). WARNING: once received, browsers remember this even if the header is later removed, for the full max-age duration.', 'core-blueprint' ),
					'plain'     => __( 'Forces the browser to always load the site over a secure (HTTPS) connection, even if someone accidentally types the insecure address. Important: only enable on sites that fully and permanently run over HTTPS - the browser remembers this instruction for a year, even if you turn it off later.', 'core-blueprint' ),
				],
				'default'     => false,
				'restrictive' => false,
				'risk'        => 'high',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP', 'ref' => 'Secure Headers', 'url' => 'https://owasp.org/www-project-secure-headers/', 'note' => 'HTTPS enforcement' ],
					[ 'type' => 'cwe',      'ref'  => 'CWE-319', 'note' => 'Cleartext Transmission of Sensitive Information' ],
				],
			],
			[
				'id'          => 'x_xss_protection',
				'label'       => __( 'X-XSS-Protection: 0', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Sends X-XSS-Protection: 0 to explicitly disable the deprecated browser XSS auditor. The auditor is known to introduce its own side-channel vulnerabilities; modern browsers ignore it, but setting it defensively suppresses stale defaults in older clients.', 'core-blueprint' ),
					'plain'     => __( 'Explicitly disables an old, now-unsafe browser feature. This feature was meant to stop XSS attacks but turned out to introduce new leaks itself. Modern browsers already ignore it; this setting covers outdated browsers defensively.', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'none',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP', 'ref' => 'Secure Headers', 'url' => 'https://owasp.org/www-project-secure-headers/', 'note' => 'Legacy XSS auditor disabled' ],
				],
			],
			[
				'id'          => 'cross_origin_opener_policy',
				'label'       => __( 'Cross-Origin-Opener-Policy: same-origin', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Sends Cross-Origin-Opener-Policy: same-origin to isolate the browsing context from cross-origin windows opened via window.open or target=_blank links. Mitigates speculative-execution side-channel attacks.', 'core-blueprint' ),
					'plain'     => __( 'Ensures that pages opened via links or pop-ups stay isolated from your site. Prevents external pages from gaining insight into your page memory via advanced browser tricks (a well-known class of attacks from 2018 known as Spectre).', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'low',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP', 'ref' => 'Secure Headers', 'url' => 'https://owasp.org/www-project-secure-headers/', 'note' => 'Browsing-context isolation' ],
				],
			],
			[
				'id'          => 'cross_origin_resource_policy',
				'label'       => __( 'Cross-Origin-Resource-Policy: same-site', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Sends Cross-Origin-Resource-Policy: same-site to prevent other origins from embedding this site\'s resources. Subdomains are permitted; switch to same-origin in code for stricter isolation.', 'core-blueprint' ),
					'plain'     => __( 'Prevents other websites from displaying images, scripts and other files from your site on their own pages. Subdomains of your own site are still allowed. This prevents bandwidth theft and certain attack patterns.', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'low',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP', 'ref' => 'Secure Headers', 'url' => 'https://owasp.org/www-project-secure-headers/', 'note' => 'Resource-embedding isolation' ],
				],
			],
			[
				'id'          => 'csp_report_only',
				'label'       => __( 'Content-Security-Policy-Report-Only (monitoring mode)', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Sends Content-Security-Policy-Report-Only with a permissive default policy. Violations are visible in the browser DevTools console; nothing is blocked. Use this to validate a CSP before switching to enforce mode.', 'core-blueprint' ),
					'plain'     => __( 'Enables a "watch but don\'t block" mode for a strict security rule that determines which scripts may load. Without actually blocking you can see in browser developer tools what the rule would stop, so you can test without breaking anything. The strict "actually block" version only comes after this monitoring period has stabilised.', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'medium',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP', 'ref' => 'Secure Headers', 'url' => 'https://owasp.org/www-project-secure-headers/', 'note' => 'Content Security Policy' ],
					[ 'type' => 'cwe',      'ref'  => 'CWE-79', 'note' => 'Cross-site Scripting (XSS)' ],
				],
			],
		];
	}

	/**
	 * Module-level badges.
	 */
	public function badges(): array {
		return [
			[ 'type' => 'tech',     'label' => 'PHP 8.4+' ],
			[ 'type' => 'tech',     'label' => 'WP 6.0+' ],
			[ 'type' => 'standard', 'body'  => 'OWASP Top 10', 'ref' => 'A05:2021', 'url' => 'https://owasp.org/Top10/A05_2021-Security_Misconfiguration/', 'note' => 'Security Misconfiguration' ],
		];
	}

	// ─── Boot ────────────────────────────────────────────────────────────────

	public function boot(): void {
		// Keep the module registered for UI/status discovery, but a disabled Core
		// Shield master switch owns no runtime hooks or side effects.
		if ( ! Settings::shield_enabled() ) {
			return;
		}

		// Frontend + wp-cron REST etc. - send_headers fires for standard WP requests.
		add_action( 'send_headers', [ $this, 'emit_headers' ] );

		// wp-admin - admin_init fires before the admin HTML output starts.
		add_action( 'admin_init', [ $this, 'emit_headers' ], 1 );

		// wp-login.php - login_init fires on the login screen.
		add_action( 'login_init', [ $this, 'emit_headers' ] );
	}

	// ─── Header emission ─────────────────────────────────────────────────────

	public function emit_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		if ( $this->feature( 'x_frame_options' ) ) {
			header( 'X-Frame-Options: SAMEORIGIN' );
		}

		if ( $this->feature( 'x_content_type_options' ) ) {
			header( 'X-Content-Type-Options: nosniff' );
		}

		if ( $this->feature( 'referrer_policy' ) ) {
			header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		}

		if ( $this->feature( 'permissions_policy' ) ) {
			header( 'Permissions-Policy: ' . $this->permissions_policy_value() );
		}

		if ( $this->feature( 'strict_transport_security' ) ) {
			// Only send HSTS on HTTPS requests. Sending it over plain HTTP is ignored
			// by browsers but signals misconfiguration; skip it defensively.
			if ( is_ssl() ) {
				$max_age = (int) apply_filters( 'cb_core_hsts_max_age', 31536000 ); // 1 year
				header( 'Strict-Transport-Security: max-age=' . $max_age );
			}
		}

		if ( $this->feature( 'x_xss_protection' ) ) {
			header( 'X-XSS-Protection: 0' );
		}

		if ( $this->feature( 'cross_origin_opener_policy' ) ) {
			header( 'Cross-Origin-Opener-Policy: same-origin' );
		}

		if ( $this->feature( 'cross_origin_resource_policy' ) ) {
			header( 'Cross-Origin-Resource-Policy: same-site' );
		}

		if ( $this->feature( 'csp_report_only' ) ) {
			$policy = (string) apply_filters( 'cb_core_csp_report_only', self::CSP_REPORT_ONLY_DEFAULT );
			if ( '' !== $policy ) {
				header( 'Content-Security-Policy-Report-Only: ' . $policy );
			}
		}
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Build the Permissions-Policy directive list. Each feature is explicitly
	 * denied with an empty allowlist; third-party embedded iframes cannot
	 * request them without a separate allowlist being set by the site owner.
	 *
	 * Filter 'cb_core_permissions_policy' lets advanced users extend or override.
	 */
	private function permissions_policy_value(): string {
		$default = [
			'accelerometer'                   => '()',
			'ambient-light-sensor'            => '()',
			'autoplay'                        => '(self)',
			'battery'                         => '()',
			'camera'                          => '()',
			'display-capture'                 => '()',
			'document-domain'                 => '()',
			'encrypted-media'                 => '()',
			'execution-while-not-rendered'    => '()',
			'execution-while-out-of-viewport' => '()',
			'fullscreen'                      => '(self)',
			'geolocation'                     => '()',
			'gyroscope'                       => '()',
			'magnetometer'                    => '()',
			'microphone'                      => '()',
			'midi'                            => '()',
			'navigation-override'             => '()',
			'payment'                         => '()',
			'picture-in-picture'              => '(self)',
			'publickey-credentials-get'       => '(self)',
			'screen-wake-lock'                => '()',
			'sync-xhr'                        => '(self)',
			'usb'                             => '()',
			'web-share'                       => '(self)',
			'xr-spatial-tracking'             => '()',
		];

		$policy = apply_filters( 'cb_core_permissions_policy', $default );

		$parts = [];
		foreach ( $policy as $directive => $allowlist ) {
			$parts[] = $directive . '=' . $allowlist;
		}

		return implode( ', ', $parts );
	}
}
