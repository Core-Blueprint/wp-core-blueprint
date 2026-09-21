<?php
declare(strict_types=1);
/**
 * Connects registered Mail templates and binding providers to the Design
 * Foundation Mail renderer.
 *
 * Runtime callers receive null on any invalid/unrenderable custom design so
 * their canonical WordPress/domain email can continue unchanged.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Mail\Designer;

use CB\Core\Mail\ProjectRenderer;

defined( 'ABSPATH' ) || exit;

final class Renderer {
	/**
	 * @param array<string,mixed> $context
	 * @return array{subject:string,html:string}|null
	 */
	public static function render( string $template_id, array $context ): ?array {
		$template = TemplateRepository::get( $template_id );
		if ( null === $template || ! is_array( $template['project'] ?? null ) ) {
			return null;
		}

		try {
			$bindings = BindingRegistry::resolve( $context );
			return ( new ProjectRenderer() )->render(
				$template['project'],
				(string) $template['subject'],
				$bindings
			);
		} catch ( \Throwable $exception ) {
			error_log( sprintf( 'CB Mail Designer [%s]: %s', sanitize_key( str_replace( '.', '-', $template_id ) ), $exception->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- render failure diagnostic, no message body/context.
			return null;
		}
	}

	/** @return array{subject:string,html:string}|null */
	public static function preview( string $template_id, ?array $project = null, ?string $subject = null ): ?array {
		$template = TemplateRepository::get( $template_id );
		if ( null === $template ) {
			return null;
		}
		$project = is_array( $project ) ? $project : (array) $template['project'];
		$subject = null !== $subject ? sanitize_text_field( $subject ) : (string) $template['subject'];
		try {
			$bindings = BindingRegistry::preview_values();
			return ( new ProjectRenderer() )->render(
				$project,
				$subject,
				$bindings,
				[ 'editor_markers' => true ]
			);
		} catch ( \Throwable $exception ) {
			return null;
		}
	}

	/** Ensure HTML content type without discarding provider/domain headers. */
	public static function html_headers( string|array $headers ): array {
		$lines = is_array( $headers ) ? $headers : ( '' === trim( $headers ) ? [] : ( preg_split( '/\r?\n/', trim( $headers ) ) ?: [] ) );
		$out = [];
		foreach ( $lines as $key => $line ) {
			if ( is_string( $key ) && is_string( $line ) && ! str_contains( $line, ':' ) ) {
				$line = $key . ': ' . $line;
			}
			if ( ! is_string( $line ) || 1 === preg_match( '/^\s*content-type\s*:/i', $line ) ) {
				continue;
			}
			$out[] = $line;
		}
		$out[] = 'Content-Type: text/html; charset=UTF-8';
		return $out;
	}

	private function __construct() {}
}
