<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Mail\ProjectRenderer;

defined( 'ABSPATH' ) || exit;

/** The one WordPress-facing native v1 Mail output consumer. */
final class Renderer {
	/** @param array<string,mixed> $context
	 * @return array{subject:string,preheader:string,html:string}|null */
	public static function render( string $template_id, array $context ): ?array {
		// DocumentUnavailable escapes to WordPressIntegration for diagnostics
		// and lossless fallback to the original WordPress notification.
		$template = TemplateRepository::get( $template_id );
		if ( null === $template ) return null;
		try {
			$bindings = BindingRegistry::resolve( $context );
			if ( in_array( $template_id, [ 'wordpress.password-reset', 'wordpress.new-user' ], true ) ) {
				$action_url = $bindings['action.url'] ?? null;
				if ( ! is_string( $action_url ) || ! self::valid_action_url( $action_url )
					|| ! self::has_action_link( $template['project']['root'] ) ) return null;
			}
			return ( new ProjectRenderer() )->render(
				$template['project'], $template['fields'], $bindings
			);
		} catch ( \Throwable $error ) {
			// Never log action keys, recipient details or untrusted content.
			return null;
		}
	}

	/**
	 * Read-only, unsaved preview using exactly the native runtime renderer.
	 * No persistence or independent preview document authority.
	 */
	public static function preview( string $template_id, ?array $project = null, ?string $subject = null ): ?array {
		$template = TemplateRepository::get( $template_id );
		if ( null === $template ) return null;
		$fields = $template['fields'];
		if ( null !== $subject ) $fields['subject'] = $subject;
		try {
			return ( new ProjectRenderer() )->render(
				is_array( $project ) ? $project : $template['project'],
				$fields, BindingRegistry::preview_values(), [ 'editor_markers' => true ]
			);
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/** A security notification must include a functional, context-owned link. */
	private static function valid_action_url( string $url ): bool {
		if ( '' === $url || preg_match( '/[\x00-\x20\x7f]/', $url )
			|| false === filter_var( $url, FILTER_VALIDATE_URL ) ) return false;
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true )
			&& ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
	}

	private static function has_action_link( array $node, int $depth = 0 ): bool {
		if ( $depth > 64 ) return false;
		$type = $node['type'] ?? null;
		$p = $node['properties'] ?? [];
		if ( 'mail.button' === $type && ( $p['url'] ?? null ) === '{{action.url}}' ) return true;
		if ( 'mail.rich-text' === $type && is_string( $p['markup'] ?? null )
			&& preg_match( '~<a\b[^>]*href\s*=\s*["\x27]\{\{action\.url\}\}["\x27]~i', $p['markup'] ) ) return true;
		foreach ( $node['children'] ?? [] as $child ) {
			if ( is_array( $child ) && self::has_action_link( $child, $depth + 1 ) ) return true;
		}
		return false;
	}

	/** Preserve upstream recipient/provider headers; replace Content-Type only. */
	public static function html_headers( string|array $headers ): array {
		$lines = is_array( $headers ) ? $headers
			: ( '' === trim( $headers ) ? [] : ( preg_split( '/\r?\n/', trim( $headers ) ) ?: [] ) );
		$out = [];
		foreach ( $lines as $key => $line ) {
			if ( is_string( $key ) && is_string( $line ) && ! str_contains( $line, ':' ) )
				$line = $key . ': ' . $line;
			if ( ! is_string( $line ) || 1 === preg_match( '/^\s*content-type\s*:/i', $line ) ) continue;
			$out[] = $line;
		}
		$out[] = 'Content-Type: text/html; charset=UTF-8';
		return $out;
	}

	private function __construct() {}
}
