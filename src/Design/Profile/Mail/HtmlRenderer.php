<?php
declare(strict_types=1);
/**
 * Email-safe HTML renderer for Design Foundation Mail projects.
 *
 * Rendering is deterministic and transport-independent. Values are escaped at
 * their output context; no arbitrary raw HTML component is provided by Base.
 * Extensions may render their own registered node types through the documented
 * `cb_core_design_mail_render_node` filter.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Design\Profile\Mail;

defined( 'ABSPATH' ) || exit;

final class HtmlRenderer {
	public function __construct( private readonly Validator $validator = new Validator() ) {}

	/**
	 * @param array<string,mixed> $project
	 * @param array<string,scalar|null> $bindings
	 * @param array{editor_markers?:bool} $options
	 */
	public function render( array $project, array $bindings = [], array $options = [] ): string {
		$diagnostics = $this->validator->validate( $project );
		if ( $diagnostics->has_errors() ) {
			throw new \InvalidArgumentException( 'Mail design project is invalid.' );
		}

		$root = (array) $project['root'];
		$properties = is_array( $root['properties'] ?? null ) ? $root['properties'] : [];
		$layout = is_array( $properties['layout'] ?? null ) ? $properties['layout'] : [];
		$width = $this->int_range( $layout['width'] ?? Contract::WIDTH_DEFAULT, Contract::WIDTH_MIN, Contract::WIDTH_MAX, Contract::WIDTH_DEFAULT );
		$background = $this->color( $layout['background'] ?? '#f3f4f6', '#f3f4f6' );
		$content_background = $this->color( $layout['contentBackground'] ?? '#ffffff', '#ffffff' );
		$text_color = $this->color( $layout['textColor'] ?? '#1f2937', '#1f2937' );
		$accent = $this->color( $layout['accentColor'] ?? '#2563eb', '#2563eb' );
		$font = $this->font_family( $layout['fontFamily'] ?? Contract::FONT_FAMILIES[0] );
		$preheader = BindingInterpolator::interpolate( (string) ( $properties['preheader'] ?? '' ), $bindings );
		$editor_markers = true === ( $options['editor_markers'] ?? false );
		$theme = [
			'text' => $text_color,
			'accent' => $accent,
			'font' => $font,
		];

		$body = '';
		foreach ( (array) ( $root['children'] ?? [] ) as $index => $node ) {
			if ( is_array( $node ) ) {
				$body .= $this->render_node( $node, $bindings, $theme, [ (int) $index ], $editor_markers );
			}
		}

		$preheader_html = '' === trim( $preheader ) ? '' : sprintf(
			'<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">%s</div>',
			esc_html( $preheader )
		);
		$editor_css = $editor_markers
			? '[data-cb-mail-editor-node][data-cb-mail-selected="true"]>tr>td{outline:2px solid #2271b1;outline-offset:-2px}[data-cb-mail-editor-node][data-cb-mail-hovered="true"]>tr>td{outline:2px dashed #2271b1;outline-offset:-2px}'
			: '';

		return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="x-apple-disable-message-reformatting"><style>@media only screen and (max-width:640px){.cb-mail-container{width:100%!important}.cb-mail-pad{padding-left:20px!important;padding-right:20px!important}.cb-mail-button{display:block!important;width:100%!important;box-sizing:border-box!important}}' . $editor_css . '</style></head>'
			. '<body style="margin:0;padding:0;background:' . esc_attr( $background ) . ';font-family:' . esc_attr( $font ) . ';color:' . esc_attr( $text_color ) . ';">'
			. $preheader_html
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:' . esc_attr( $background ) . ';border-collapse:collapse;"><tr><td align="center" style="padding:32px 12px;">'
			. '<table role="presentation" class="cb-mail-container" width="' . esc_attr( (string) $width ) . '" cellspacing="0" cellpadding="0" border="0" style="width:' . esc_attr( (string) $width ) . 'px;max-width:100%;background:' . esc_attr( $content_background ) . ';border-collapse:collapse;">'
			. $body
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * @param array<string,mixed> $node
	 * @param array<string,scalar|null> $bindings
	 * @param array{text:string,accent:string,font:string} $theme
	 * @param list<int> $path
	 */
	private function render_node( array $node, array $bindings, array $theme, array $path, bool $editor_markers ): string {
		$type = (string) ( $node['type'] ?? '' );
		$provider = (string) ( $node['provider'] ?? '' );
		$properties = is_array( $node['properties'] ?? null ) ? $node['properties'] : [];

		if ( Contract::CORE_PROVIDER !== $provider ) {
			/**
			 * Render a registered extension-owned Mail node.
			 *
			 * Extensions must return complete email-safe table-row HTML. Base passes
			 * only already-validated declarative node data and resolved binding values.
			 * Preview callers additionally receive non-persistent editor context.
			 *
			 * @param string $html
			 * @param array<string,mixed> $node
			 * @param array<string,scalar|null> $bindings
			 * @param array<string,string> $theme
			 * @param array{editor_preview:bool,path:list<int>} $render_context
			 */
			$html = apply_filters(
				'cb_core_design_mail_render_node',
				'',
				$node,
				$bindings,
				$theme,
				[ 'editor_preview' => $editor_markers, 'path' => $path ]
			);
			$html = is_string( $html ) ? $html : '';
			return $editor_markers ? $this->wrap_editor_node( $html, $path, $type, $provider ) : $html;
		}

		$html = match ( $type ) {
			'mail.section' => $this->render_section( $node, $bindings, $theme, $path, $editor_markers ),
			'mail.heading' => $this->render_heading( $properties, $bindings, $theme ),
			'mail.text' => $this->render_text( $properties, $bindings, $theme ),
			'mail.button' => $this->render_button( $properties, $bindings, $theme ),
			'mail.image' => $this->render_image( $properties, $bindings ),
			'mail.divider' => $this->render_divider( $properties ),
			'mail.spacer' => $this->render_spacer( $properties ),
			default => '',
		};
		return $editor_markers ? $this->wrap_editor_node( $html, $path, $type, $provider ) : $html;
	}

	/**
	 * @param array<string,mixed> $node
	 * @param array<string,scalar|null> $bindings
	 * @param array{text:string,accent:string,font:string} $theme
	 * @param list<int> $path
	 */
	private function render_section( array $node, array $bindings, array $theme, array $path, bool $editor_markers ): string {
		$properties = is_array( $node['properties'] ?? null ) ? $node['properties'] : [];
		$background = $this->color( $properties['background'] ?? '#ffffff', '#ffffff' );
		$padding = $this->int_range( $properties['padding'] ?? 28, 0, 80, 28 );
		$content = '';
		foreach ( (array) ( $node['children'] ?? [] ) as $index => $child ) {
			if ( is_array( $child ) ) {
				$content .= $this->render_node( $child, $bindings, $theme, [ ...$path, (int) $index ], $editor_markers );
			}
		}
		return '<tr><td style="padding:0;background:' . esc_attr( $background ) . ';"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">'
			. '<tr><td class="cb-mail-pad" style="padding:' . esc_attr( (string) $padding ) . 'px;">'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">' . $content . '</table>'
			. '</td></tr></table></td></tr>';
	}

	/** @param list<int> $path */
	private function wrap_editor_node( string $html, array $path, string $type, string $provider ): string {
		if ( '' === $html ) {
			return '';
		}
		$path_json = wp_json_encode( array_values( $path ) );
		$path_json = is_string( $path_json ) ? $path_json : '[]';
		return '<tbody data-cb-mail-editor-node="1" data-cb-mail-path="' . esc_attr( $path_json ) . '" data-cb-mail-type="' . esc_attr( $type ) . '" data-cb-mail-provider="' . esc_attr( $provider ) . '">'
			. $html
			. '</tbody>';
	}

	/** @param array<string,mixed> $properties @param array<string,scalar|null> $bindings @param array{text:string,accent:string,font:string} $theme */
	private function render_heading( array $properties, array $bindings, array $theme ): string {
		$text = BindingInterpolator::interpolate( (string) ( $properties['text'] ?? '' ), $bindings );
		$size = $this->int_range( $properties['fontSize'] ?? 28, 16, 48, 28 );
		$align = $this->align( $properties['align'] ?? 'left' );
		$color = $this->color( $properties['color'] ?? $theme['text'], $theme['text'] );
		$spacing = $this->int_range( $properties['spacing'] ?? 16, 0, 48, 16 );
		return '<tr><td style="padding:0 0 ' . esc_attr( (string) $spacing ) . 'px;text-align:' . esc_attr( $align ) . ';">'
			. '<div style="margin:0;font-family:' . esc_attr( $theme['font'] ) . ';font-size:' . esc_attr( (string) $size ) . 'px;line-height:1.25;font-weight:700;color:' . esc_attr( $color ) . ';">'
			. esc_html( $text ) . '</div></td></tr>';
	}

	/** @param array<string,mixed> $properties @param array<string,scalar|null> $bindings @param array{text:string,accent:string,font:string} $theme */
	private function render_text( array $properties, array $bindings, array $theme ): string {
		$text = BindingInterpolator::interpolate( (string) ( $properties['text'] ?? '' ), $bindings );
		$size = $this->int_range( $properties['fontSize'] ?? 16, 12, 28, 16 );
		$align = $this->align( $properties['align'] ?? 'left' );
		$color = $this->color( $properties['color'] ?? $theme['text'], $theme['text'] );
		$spacing = $this->int_range( $properties['spacing'] ?? 16, 0, 48, 16 );
		return '<tr><td style="padding:0 0 ' . esc_attr( (string) $spacing ) . 'px;text-align:' . esc_attr( $align ) . ';font-family:' . esc_attr( $theme['font'] ) . ';font-size:' . esc_attr( (string) $size ) . 'px;line-height:1.6;color:' . esc_attr( $color ) . ';">'
			. nl2br( esc_html( $text ) ) . '</td></tr>';
	}

	/** @param array<string,mixed> $properties @param array<string,scalar|null> $bindings @param array{text:string,accent:string,font:string} $theme */
	private function render_button( array $properties, array $bindings, array $theme ): string {
		$label = BindingInterpolator::interpolate( (string) ( $properties['label'] ?? __( 'Continue', 'core-blueprint' ) ), $bindings );
		$url = esc_url( BindingInterpolator::interpolate( (string) ( $properties['url'] ?? '' ), $bindings ), [ 'http', 'https' ] );
		$align = $this->align( $properties['align'] ?? 'left' );
		$background = $this->color( $properties['background'] ?? $theme['accent'], $theme['accent'] );
		$text_color = $this->color( $properties['color'] ?? '#ffffff', '#ffffff' );
		$radius = $this->int_range( $properties['radius'] ?? 6, 0, 32, 6 );
		$spacing = $this->int_range( $properties['spacing'] ?? 20, 0, 48, 20 );
		if ( '' === $url ) {
			return '';
		}
		return '<tr><td align="' . esc_attr( $align ) . '" style="padding:0 0 ' . esc_attr( (string) $spacing ) . 'px;">'
			. '<a class="cb-mail-button" href="' . $url . '" style="display:inline-block;padding:12px 20px;background:' . esc_attr( $background ) . ';border-radius:' . esc_attr( (string) $radius ) . 'px;color:' . esc_attr( $text_color ) . ';font-family:' . esc_attr( $theme['font'] ) . ';font-size:16px;line-height:1.2;font-weight:700;text-decoration:none;">'
			. esc_html( $label ) . '</a></td></tr>';
	}

	/** @param array<string,mixed> $properties @param array<string,scalar|null> $bindings */
	private function render_image( array $properties, array $bindings ): string {
		$url = esc_url( BindingInterpolator::interpolate( (string) ( $properties['url'] ?? '' ), $bindings ), [ 'http', 'https' ] );
		if ( '' === $url ) {
			return '';
		}
		$alt = BindingInterpolator::interpolate( (string) ( $properties['alt'] ?? '' ), $bindings );
		$width = $this->int_range( $properties['width'] ?? Contract::WIDTH_DEFAULT, 1, Contract::WIDTH_MAX, Contract::WIDTH_DEFAULT );
		$align = $this->align( $properties['align'] ?? 'center' );
		$spacing = $this->int_range( $properties['spacing'] ?? 20, 0, 48, 20 );
		return '<tr><td align="' . esc_attr( $align ) . '" style="padding:0 0 ' . esc_attr( (string) $spacing ) . 'px;">'
			. '<img src="' . $url . '" alt="' . esc_attr( $alt ) . '" width="' . esc_attr( (string) $width ) . '" style="display:block;width:' . esc_attr( (string) $width ) . 'px;max-width:100%;height:auto;border:0;" />'
			. '</td></tr>';
	}

	/** @param array<string,mixed> $properties */
	private function render_divider( array $properties ): string {
		$color = $this->color( $properties['color'] ?? '#e5e7eb', '#e5e7eb' );
		$spacing = $this->int_range( $properties['spacing'] ?? 20, 0, 48, 20 );
		return '<tr><td style="padding:' . esc_attr( (string) $spacing ) . 'px 0;"><div style="height:1px;line-height:1px;background:' . esc_attr( $color ) . ';font-size:1px;">&nbsp;</div></td></tr>';
	}

	/** @param array<string,mixed> $properties */
	private function render_spacer( array $properties ): string {
		$height = $this->int_range( $properties['height'] ?? 24, 0, 120, 24 );
		return '<tr><td height="' . esc_attr( (string) $height ) . '" style="height:' . esc_attr( (string) $height ) . 'px;line-height:' . esc_attr( (string) $height ) . 'px;font-size:1px;">&nbsp;</td></tr>';
	}

	private function color( mixed $value, string $fallback ): string {
		$color = sanitize_hex_color( (string) $value );
		return is_string( $color ) && '' !== $color ? $color : $fallback;
	}

	private function align( mixed $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, [ 'left', 'center', 'right' ], true ) ? $value : 'left';
	}

	private function font_family( mixed $value ): string {
		$value = trim( (string) $value );
		return in_array( $value, Contract::FONT_FAMILIES, true ) ? $value : Contract::FONT_FAMILIES[0];
	}

	private function int_range( mixed $value, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $value ) );
	}
}
