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
			return ( new ProjectRenderer() )->render(
				$template['project'], $template['fields'], BindingRegistry::resolve( $context )
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
