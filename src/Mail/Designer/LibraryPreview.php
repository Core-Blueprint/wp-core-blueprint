<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Mail\ProjectRenderer;
use CoreBlueprint\Core\TemplateLibrary\PreviewHtmlProjection;
use CoreBlueprint\Core\TemplateLibrary\TemplatePreview;

defined( 'ABSPATH' ) || exit;

/**
 * Mail-owned TL-1C preview: canonical ProjectRenderer with only synthetic
 * bindings; shared Base only validates the final already-rendered HTML output.
 */
final class LibraryPreview {
	/** Never read actor/site data, option values, users, emails or live tokens. */
	private const SAMPLE_BINDINGS = [
		'site.name'          => 'Example Site',
		'site.url'           => 'https://example.invalid',
		'site.admin_email'   => 'admin@example.invalid',
		'user.display_name'  => 'Example User',
		'user.login'         => 'example-user',
		'user.email'         => 'user@example.invalid',
		'contact.first_name' => 'Example',
		'action.url'         => 'https://example.invalid/action',
	];

	/** @param array{project:array<string,mixed>,fields:array<string,mixed>} $document */
	public static function render( array $document, string $version ): TemplatePreview {
		if ( ! isset( $document['project'], $document['fields'] )
			|| ! is_array( $document['project'] ) || ! is_array( $document['fields'] ) ) {
			throw new \InvalidArgumentException( 'Invalid Mail preview document.' );
		}
		$rendered = ( new ProjectRenderer() )->render(
			$document['project'], $document['fields'], self::SAMPLE_BINDINGS
		);
		return PreviewHtmlProjection::from_rendered_html( $version, $rendered['html'] );
	}

	private function __construct() {}
}
