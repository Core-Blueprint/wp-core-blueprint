<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail;

use CoreBlueprint\Core\Design\Profile\Mail\BindingInterpolator;
use CoreBlueprint\Core\Design\Profile\Mail\HtmlRenderer;

defined( 'ABSPATH' ) || exit;

/** Pure output boundary for a complete native v1 Mail {project,fields}. */
final class ProjectRenderer {
	private readonly HtmlRenderer $renderer;

	public function __construct( ?HtmlRenderer $renderer = null ) {
		$this->renderer = $renderer ?? new HtmlRenderer();
	}

	/**
	 * @param array<string,mixed> $project
	 * @param array{subject:string,preheader:string} $fields
	 * @param array<string,scalar|null> $bindings
	 * @return array{subject:string,preheader:string,html:string}
	 */
	public function render( array $project, array $fields, array $bindings = [], array $options = [] ): array {
		if ( ! isset( $fields['subject'], $fields['preheader'] ) || ! is_string( $fields['subject'] )
			|| ! is_string( $fields['preheader'] ) || count( $fields ) !== 2
			|| mb_strlen( $fields['subject'], 'UTF-8' ) > 255
			|| mb_strlen( $fields['preheader'], 'UTF-8' ) > 500 ) {
			throw new \InvalidArgumentException( 'Mail fields are invalid.' );
		}
		$subject = BindingInterpolator::interpolate( $fields['subject'], $bindings );
		$subject = sanitize_text_field( $subject );
		$subject = trim( str_replace( [ "\r", "\n", "\0" ], ' ', $subject ) );
		if ( '' === $subject || mb_strlen( $subject, 'UTF-8' ) > 255 ) {
			throw new \InvalidArgumentException( 'Mail subject is invalid.' );
		}
		$preheader = BindingInterpolator::interpolate( $fields['preheader'], $bindings );
		$preheader = trim( preg_replace( '/[\x00-\x1f\x7f]/u', ' ', $preheader ) ?? '' );
		if ( mb_strlen( $preheader, 'UTF-8' ) > 500 ) {
			throw new \InvalidArgumentException( 'Mail preheader is invalid.' );
		}
		$html = $this->renderer->render( $project, $bindings,
			array_merge( $options, [ 'preheader' => $preheader ] ) );
		return [ 'subject' => $subject, 'preheader' => $preheader, 'html' => $html ];
	}
}
