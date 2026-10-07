<?php
declare(strict_types=1);
/**
 * Fingerprint
 *
 * Reduces information disclosure by removing identifiers that reveal the
 * WordPress version and expose obsolete discovery endpoints. Non-restrictive:
 * these hooks never block a visitor, they only strip or suppress output.
 *
 * Features:
 *   - remove_wp_version_meta     : <meta name="generator"> tag
 *   - remove_asset_version_query : ?ver=x.x.x query on CSS/JS (core version only)
 *   - remove_feed_generator      : WordPress version in RSS/Atom feeds
 *   - block_readme_html          : /readme.html returns 404 (if request routes through WP)
 *   - remove_rsd_link            : Really Simple Discovery <link> in <head>
 *   - remove_wlwmanifest_link    : Windows Live Writer manifest link (obsolete since 2012)
 *   - remove_powered_by_header   : X-Powered-By / X-Pingback response headers
 *
 * None of these features affect site visitors in any visible way. They are
 * safe to enable in all site modes.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Security\Modules;

use CoreBlueprint\Core\RequestContext;
use CoreBlueprint\Core\Settings;
use CoreBlueprint\Core\Security\AbstractModule;

defined( 'ABSPATH' ) || exit;

final class Fingerprint extends AbstractModule {

	public function slug(): string {
		return 'fingerprint';
	}

	public function label(): string {
		return __( 'Fingerprint reduction', 'core-blueprint' );
	}

	public function description(): string {
		return __( 'Removes identifiers that disclose the WordPress version and suppresses obsolete discovery endpoints.', 'core-blueprint' );
	}

	/**
	 * Plain-language description of the module's purpose - used when the UI
	 * renders in Plain mode. Follows the Core Blueprint Peter Principle:
	 * explain what it does AND why we do it, no jargon.
	 */
	public function description_plain(): string {
		return __( 'Prevents visitors and attackers from seeing which WordPress version and older components the site uses. Attackers use that information to look up known vulnerabilities - removing it reduces the attack surface without visitors noticing anything.', 'core-blueprint' );
	}

	public function features(): array {
		return [
			[
				'id'          => 'remove_wp_version_meta',
				'label'       => __( 'Remove WordPress version meta tag', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Strips the <meta name="generator" content="WordPress x.x"> element from the page <head>.', 'core-blueprint' ),
					'plain'     => __( 'Hides which WordPress version your site runs from visitors. Attackers use that information to look up known vulnerabilities for that specific version - removing it makes their job a lot harder.', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'none',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP ASVS', 'ref' => 'V14.3.3', 'url' => 'https://owasp.org/www-project-application-security-verification-standard/', 'note' => 'Information disclosure in HTTP responses' ],
					[ 'type' => 'cwe',      'ref'  => 'CWE-200',    'note' => 'Exposure of sensitive information to an unauthorized actor' ],
				],
			],
			[
				'id'          => 'remove_asset_version_query',
				'label'       => __( 'Remove ?ver= query from WordPress core assets', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Strips the version query parameter from CSS/JS URLs when it matches the installed WordPress version. Plugin and theme cache-busting values are preserved.', 'core-blueprint' ),
					'plain'     => __( 'Strips the version numbers appended to WordPress files (e.g. ?ver=6.4.2). This also reveals which version the site runs. Plugin and theme version numbers are kept so updates still reach visitors correctly.', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'low',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP ASVS', 'ref' => 'V14.3.3', 'url' => 'https://owasp.org/www-project-application-security-verification-standard/', 'note' => 'Information disclosure in HTTP responses' ],
					[ 'type' => 'cwe',      'ref'  => 'CWE-200',    'note' => 'Exposure of sensitive information to an unauthorized actor' ],
				],
			],
			[
				'id'          => 'remove_feed_generator',
				'label'       => __( 'Remove WordPress version from RSS/Atom feeds', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Blanks the <generator> element in RSS, Atom, and RDF feed output that advertises the exact WordPress version.', 'core-blueprint' ),
					'plain'     => __( 'Removes the WordPress version from the site\'s RSS and Atom feeds. Without this the version is literally printed in the feed output that anyone can request.', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'none',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP ASVS', 'ref' => 'V14.3.3', 'url' => 'https://owasp.org/www-project-application-security-verification-standard/', 'note' => 'Information disclosure in HTTP responses' ],
					[ 'type' => 'cwe',      'ref'  => 'CWE-200',    'note' => 'Exposure of sensitive information to an unauthorized actor' ],
				],
			],
			[
				'id'          => 'block_readme_html',
				'label'       => __( 'Block access to /readme.html', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Returns a 404 for the /readme.html file, which lists the exact installed WordPress version. Static hosts may still serve the file directly - see documentation.', 'core-blueprint' ),
					'plain'     => __( 'Blocks the readme file that ships with WordPress by default. That file literally contains the version and is otherwise readable by anyone who types the URL. On some servers the web server delivers the file directly - in that case an extra server rule is needed.', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'none',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP ASVS', 'ref' => 'V14.3.3', 'url' => 'https://owasp.org/www-project-application-security-verification-standard/', 'note' => 'Information disclosure in HTTP responses' ],
					[ 'type' => 'cwe',      'ref'  => 'CWE-200',    'note' => 'Exposure of sensitive information to an unauthorized actor' ],
				],
			],
			[
				'id'          => 'remove_rsd_link',
				'label'       => __( 'Remove RSD (Really Simple Discovery) link', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Suppresses the <link rel="EditURI"> tag used by legacy XML-RPC clients for endpoint auto-discovery.', 'core-blueprint' ),
					'plain'     => __( 'Removes an old discovery link from the page that is only used by outdated blog software. Modern visitors and CMS integrations don\'t need this link; removing it closes an unnecessary attack surface.', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'low',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP ASVS', 'ref' => 'V14.3.3', 'url' => 'https://owasp.org/www-project-application-security-verification-standard/', 'note' => 'Unnecessary endpoint advertisement' ],
				],
			],
			[
				'id'          => 'remove_wlwmanifest_link',
				'label'       => __( 'Remove Windows Live Writer manifest link', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Suppresses the <link rel="wlwmanifest"> tag. Windows Live Writer was discontinued by Microsoft in 2012.', 'core-blueprint' ),
					'plain'     => __( 'Removes a link from the page that was only meant for a Microsoft program that has not existed since 2012. Purely redundant information in the page header.', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'none',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP ASVS', 'ref' => 'V14.3.3', 'url' => 'https://owasp.org/www-project-application-security-verification-standard/', 'note' => 'Unnecessary endpoint advertisement' ],
				],
			],
			[
				'id'          => 'remove_powered_by_header',
				'label'       => __( 'Remove X-Powered-By and X-Pingback headers', 'core-blueprint' ),
				'description' => [
					'technical' => __( 'Strips WordPress- and PHP-identifying response headers where the PHP runtime permits it. Some hosts emit X-Powered-By at the webserver level and cannot be overridden from PHP.', 'core-blueprint' ),
					'plain'     => __( 'Removes response headers that reveal the site runs on WordPress and PHP. On some hosts this is configured at server level; in that case an adjustment at the host is needed to fully remove them.', 'core-blueprint' ),
				],
				'default'     => true,
				'restrictive' => false,
				'risk'        => 'none',
				'conflict'    => null,
				'badges'      => [
					[ 'type' => 'standard', 'body' => 'OWASP ASVS', 'ref' => 'V14.3.3', 'url' => 'https://owasp.org/www-project-application-security-verification-standard/', 'note' => 'Server/framework identification disclosure' ],
					[ 'type' => 'cwe',      'ref'  => 'CWE-200',    'note' => 'Exposure of sensitive information to an unauthorized actor' ],
				],
			],
		];
	}

	/**
	 * Module-level badges (tech spec and overall standards).
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

		// Feature: remove_wp_version_meta
		add_action( 'init', [ $this, 'hook_remove_wp_version_meta' ] );

		// Feature: remove_asset_version_query
		add_filter( 'style_loader_src',  [ $this, 'filter_strip_wp_version_query' ], 999 );
		add_filter( 'script_loader_src', [ $this, 'filter_strip_wp_version_query' ], 999 );

		// Feature: remove_feed_generator
		add_filter( 'the_generator', [ $this, 'filter_feed_generator' ], 10, 2 );

		// Feature: block_readme_html - hooked very early so it can short-circuit.
		add_action( 'init', [ $this, 'hook_block_readme_html' ], 0 );

		// Feature: remove_rsd_link
		add_action( 'init', [ $this, 'hook_remove_rsd_link' ] );

		// Feature: remove_wlwmanifest_link
		add_action( 'init', [ $this, 'hook_remove_wlwmanifest_link' ] );

		// Feature: remove_powered_by_header
		add_filter( 'wp_headers', [ $this, 'filter_strip_powered_by_headers' ], 999 );
		add_action( 'send_headers', [ $this, 'hook_remove_powered_by_runtime' ], 999 );
	}

	// ─── Feature: remove_wp_version_meta ─────────────────────────────────────

	public function hook_remove_wp_version_meta(): void {
		if ( ! $this->feature( 'remove_wp_version_meta' ) ) {
			return;
		}
		remove_action( 'wp_head', 'wp_generator' );

		// Also cover cases where themes echo get_the_generator() manually.
		add_filter( 'the_generator', [ $this, 'filter_blank_html_generator' ], 10, 2 );
	}

	/**
	 * Blank out the HTML/XHTML generator types. Feed-specific types are handled
	 * separately by filter_feed_generator() so that behaviour can be toggled
	 * independently.
	 */
	public function filter_blank_html_generator( string $gen, string $type ): string {
		if ( in_array( $type, [ 'html', 'xhtml', 'export' ], true ) ) {
			return '';
		}
		return $gen;
	}

	// ─── Feature: remove_asset_version_query ─────────────────────────────────

	public function filter_strip_wp_version_query( $src ) {
		if ( ! $this->feature( 'remove_asset_version_query' ) ) {
			return $src;
		}

		if ( ! is_string( $src ) || '' === $src ) {
			return $src;
		}

		global $wp_version;

		if ( empty( $wp_version ) ) {
			return $src;
		}

		$parsed = wp_parse_url( $src );
		if ( empty( $parsed['query'] ) ) {
			return $src;
		}

		parse_str( $parsed['query'], $query_parts );

		// Only strip ver when it exactly matches the installed WordPress version.
		// Plugin- or theme-specific ver values are preserved for cache-busting.
		if ( ! isset( $query_parts['ver'] ) || (string) $query_parts['ver'] !== (string) $wp_version ) {
			return $src;
		}

		return remove_query_arg( 'ver', $src );
	}

	// ─── Feature: remove_feed_generator ──────────────────────────────────────

	public function filter_feed_generator( string $gen, string $type ): string {
		if ( ! $this->feature( 'remove_feed_generator' ) ) {
			return $gen;
		}
		if ( in_array( $type, [ 'rss2', 'rss', 'atom', 'rdf', 'comment' ], true ) ) {
			return '';
		}
		return $gen;
	}

	// ─── Feature: block_readme_html ──────────────────────────────────────────

	public function hook_block_readme_html(): void {
		if ( ! $this->feature( 'block_readme_html' ) ) {
			return;
		}

		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		$uri = RequestContext::request_uri();
		if ( '' === $uri ) {
			return;
		}

		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return;
		}

		// Case-insensitive match for /readme.html at path end.
		if ( preg_match( '#/readme\.html$#i', $path ) ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo "404 Not Found\n";
			exit;
		}
	}

	// ─── Feature: remove_rsd_link ────────────────────────────────────────────

	public function hook_remove_rsd_link(): void {
		if ( ! $this->feature( 'remove_rsd_link' ) ) {
			return;
		}
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'xmlrpc_rsd_apis', 'rest_output_rsd' );
	}

	// ─── Feature: remove_wlwmanifest_link ────────────────────────────────────

	public function hook_remove_wlwmanifest_link(): void {
		if ( ! $this->feature( 'remove_wlwmanifest_link' ) ) {
			return;
		}
		remove_action( 'wp_head', 'wlwmanifest_link' );
	}

	// ─── Feature: remove_powered_by_header ───────────────────────────────────

	public function filter_strip_powered_by_headers( $headers ) {
		if ( ! $this->feature( 'remove_powered_by_header' ) ) {
			return $headers;
		}

		if ( ! is_array( $headers ) ) {
			return $headers;
		}

		// WordPress sets X-Pingback for sites that have pingback enabled.
		unset( $headers['X-Pingback'] );

		return $headers;
	}

	/**
	 * Some servers (and PHP itself with expose_php=On) emit X-Powered-By at
	 * runtime. header_remove() will strip it if we haven't yet sent headers.
	 * We also call this on send_headers with very high priority so WP's own
	 * header-emitting code has had a chance to register its set.
	 */
	public function hook_remove_powered_by_runtime(): void {
		if ( ! $this->feature( 'remove_powered_by_header' ) ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}
		// Case-insensitive removal - header_remove matches case-insensitively.
		header_remove( 'X-Powered-By' );
		header_remove( 'X-Pingback' );
	}
}