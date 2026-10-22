<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Mail;

defined( 'ABSPATH' ) || exit;

/** Native Platform v1 Mail domain. No experimental/legacy document vocabulary. */
final class Contract {
	public const DESIGN_TYPE = 'core.blueprint.mail';
	public const ROOT_TYPE = self::DESIGN_TYPE;
	public const CORE_PROVIDER = 'core.blueprint';
	public const WIDTH_MIN = 320;
	public const WIDTH_MAX = 800;
	public const WIDTH_DEFAULT = 600;
	public const FONT_FAMILIES = [
		'Arial, Helvetica, sans-serif', 'Helvetica, Arial, sans-serif',
		'Georgia, Times New Roman, serif', 'Tahoma, Verdana, sans-serif',
		'Verdana, Geneva, sans-serif',
	];

	private static function s( int $max, int $min = 0 ): array {
		return [ 'type' => 'string', 'minLength' => $min, 'maxLength' => $max ];
	}
	private static function n( int $min, int $max ): array {
		return [ 'type' => 'integer', 'minimum' => $min, 'maximum' => $max ];
	}
	private static function e( array $choices ): array {
		return [ 'type' => 'string', 'enum' => $choices, 'maxLength' => 128 ];
	}

	/**
	 * Single PHP-owned domain declaration. Each default has an exact schema.
	 * @return array<string,array{parents:array,defaults:array,properties:array}>
	 */
	public static function definitions(): array {
		$color = self::s( 32 );
		$align = self::e( [ 'left', 'center', 'right' ] );
		$font = self::e( self::FONT_FAMILIES );
		$content_parents = [ 'mail.section', 'mail.column' ];
		return [
			self::ROOT_TYPE => [
				'parents' => [],
				'defaults' => [
					'background' => '#f3f4f6', 'contentBackground' => '#ffffff',
					'textColor' => '#1f2937', 'accentColor' => '#2563eb',
					'fontFamily' => self::FONT_FAMILIES[0], 'width' => 600, 'padding' => 32,
				],
				'properties' => [
					'background' => $color, 'contentBackground' => $color,
					'textColor' => $color, 'accentColor' => $color,
					'fontFamily' => $font, 'width' => self::n( 320, 800 ),
					'padding' => self::n( 0, 64 ),
				],
			],
			'mail.section' => [
				'parents' => [ self::ROOT_TYPE ],
				'defaults' => [ 'background' => '#ffffff', 'padding' => 32, 'spacing' => 0 ],
				'properties' => [ 'background' => $color, 'padding' => self::n( 0, 64 ), 'spacing' => self::n( 0, 64 ) ],
			],
			'mail.heading' => [
				'parents' => $content_parents,
				'defaults' => [ 'text' => 'Your heading', 'fontSize' => 28, 'align' => 'left', 'color' => '#1f2937', 'spacing' => 16 ],
				'properties' => [ 'text' => self::s( 10000 ), 'fontSize' => self::n( 16, 48 ), 'align' => $align, 'color' => $color, 'spacing' => self::n( 0, 48 ) ],
			],
			'mail.text' => [
				'parents' => $content_parents,
				'defaults' => [ 'text' => 'Write your message here.', 'fontSize' => 16, 'align' => 'left', 'color' => '#1f2937', 'spacing' => 16 ],
				'properties' => [ 'text' => self::s( 100000 ), 'fontSize' => self::n( 12, 28 ), 'align' => $align, 'color' => $color, 'spacing' => self::n( 0, 48 ) ],
			],
			'mail.rich-text' => [
				'parents' => $content_parents,
				'defaults' => [ 'markup' => '<p>Write your message here.</p>', 'fontSize' => 16, 'align' => 'left', 'color' => '#1f2937', 'spacing' => 16 ],
				'properties' => [ 'markup' => self::s( 100000 ), 'fontSize' => self::n( 12, 28 ), 'align' => $align, 'color' => $color, 'spacing' => self::n( 0, 48 ) ],
			],
			'mail.button' => [
				'parents' => $content_parents,
				'defaults' => [ 'label' => 'Continue', 'url' => '', 'align' => 'left', 'background' => '#2563eb', 'color' => '#ffffff', 'radius' => 6, 'spacing' => 20 ],
				'properties' => [ 'label' => self::s( 160 ), 'url' => self::s( 2048 ), 'align' => $align, 'background' => $color, 'color' => $color, 'radius' => self::n( 0, 32 ), 'spacing' => self::n( 0, 48 ) ],
			],
			'mail.image' => [
				'parents' => $content_parents,
				'defaults' => [ 'url' => '', 'alt' => '', 'width' => 560, 'align' => 'center', 'spacing' => 20 ],
				'properties' => [ 'url' => self::s( 2048 ), 'alt' => self::s( 255 ), 'width' => self::n( 1, 800 ), 'align' => $align, 'spacing' => self::n( 0, 48 ) ],
			],
			'mail.divider' => [
				'parents' => $content_parents,
				'defaults' => [ 'color' => '#e5e7eb', 'spacing' => 20 ],
				'properties' => [ 'color' => $color, 'spacing' => self::n( 0, 48 ) ],
			],
			'mail.spacer' => [
				'parents' => $content_parents,
				'defaults' => [ 'height' => 24 ],
				'properties' => [ 'height' => self::n( 0, 120 ) ],
			],
			'mail.columns' => [
				'parents' => [ 'mail.section' ],
				'defaults' => [ 'ratio' => 'equal', 'gap' => 16, 'spacing' => 20 ],
				'properties' => [ 'ratio' => self::e( [ 'equal', 'left-wide', 'right-wide', 'left-third', 'right-third' ] ),
					'gap' => self::n( 0, 40 ), 'spacing' => self::n( 0, 48 ) ],
			],
			'mail.column' => [
				'parents' => [ 'mail.columns' ],
				'defaults' => [ 'background' => '#ffffff', 'padding' => 0 ],
				'properties' => [ 'background' => $color, 'padding' => self::n( 0, 40 ) ],
			],
		];
	}

	public static function node( string $type, array $children = [], array $overrides = [] ): array {
		$definition = self::definitions()[ $type ] ?? null;
		if ( null === $definition ) {
			throw new \InvalidArgumentException( 'Unknown native Mail node type.' );
		}
		return [ 'type' => $type, 'provider' => self::CORE_PROVIDER,
			'properties' => array_replace( $definition['defaults'], $overrides ),
			'children' => $children ];
	}

	public static function default_document(): array {
		return [ 'project' => [
			'schema_version' => 1, 'design_type' => self::DESIGN_TYPE,
			'root' => self::node( self::ROOT_TYPE, [
				self::node( 'mail.section', [ self::node( 'mail.text', [], [ 'text' => '' ] ) ] ),
			] ),
		], 'fields' => [ 'subject' => 'Untitled email', 'preheader' => '' ] ];
	}

	private function __construct() {}
}
