<?php
declare(strict_types=1);
/**
 * Storage- and transport-independent renderer for complete Mail projects.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Mail;

use CB\Core\Design\Profile\Mail\BindingInterpolator;
use CB\Core\Design\Profile\Mail\HtmlRenderer;

defined( 'ABSPATH' ) || exit;

final class ProjectRenderer {
	private readonly HtmlRenderer $html_renderer;

	public function __construct( ?HtmlRenderer $html_renderer = null ) {
		$this->html_renderer = $html_renderer ?? new HtmlRenderer();
	}

	/**
	 * Render a caller-owned Mail project and subject against one binding set.
	 *
	 * This contract owns no template storage, sender identity, transport,
	 * delivery logging or recipient state. Invalid projects continue to throw
	 * the canonical HtmlRenderer validation exception so consumers can fail
	 * closed at their own workflow boundary.
	 *
	 * @param array<string,mixed> $project
	 * @param array<string,scalar|null> $bindings
	 * @param array{editor_markers?:bool} $options
	 * @return array{subject:string,html:string}
	 */
	public function render( array $project, string $subject, array $bindings = [], array $options = [] ): array {
		return [
			'subject' => BindingInterpolator::interpolate( $subject, $bindings ),
			'html'    => $this->html_renderer->render( $project, $bindings, $options ),
		];
	}
}
