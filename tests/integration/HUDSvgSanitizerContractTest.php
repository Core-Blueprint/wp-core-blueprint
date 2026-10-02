<?php
declare(strict_types=1);

use CB\Core\Brand\CoreBlueprintMark;
use CB\Core\HUD\Brand\CoreBlueprint;
use CB\Core\HUD\HUD;

final class CB_Base_HUD_Svg_Sanitizer_Contract_Test extends WP_UnitTestCase {

	public function test_canonical_mark_survives_structural_sanitization(): void {
		$clean = HUD::sanitize_logo_svg( CoreBlueprintMark::svg() );

		self::assertStringContainsString( '<svg', $clean );
		self::assertStringContainsString( '<linearGradient', $clean );
		self::assertStringContainsString( '<mask', $clean );
		self::assertStringContainsString( 'mask-type="alpha"', $clean );
		self::assertStringContainsString( 'url(#cb-brand-cb-grad)', $clean );
		self::assertStringNotContainsString( '<script', strtolower( $clean ) );
	}

	public function test_builtin_animated_brand_style_remains_brand_scoped(): void {
		$brand = new CoreBlueprint();
		$clean = HUD::sanitize_logo_svg( $brand->logo_animated_svg() );

		self::assertStringContainsString( '<style>', $clean );
		self::assertStringContainsString( '.cb-brand-cb-roundel', $clean );
		self::assertStringContainsString( '@keyframes cb-brand-cb-shimmer', $clean );
		self::assertStringContainsString( 'filter: brightness(1)', $clean );
	}

	public function test_untrusted_brand_svg_drops_executable_external_and_global_style_content(): void {
		$dirty = <<<'SVG'
<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" onload="alert(1)">
	<script>alert(1)</script>
	<foreignObject><div>unsafe</div></foreignObject>
	<path class="cb-brand-safe" d="M0 0h24v24H0z" fill="url(https://evil.example/fill.svg#x)" />
	<style>body { display: none; }</style>
</svg>
SVG;

		$clean = HUD::sanitize_logo_svg( $dirty );

		self::assertStringContainsString( '<svg', $clean );
		self::assertStringContainsString( '<path', $clean );
		self::assertStringNotContainsString( '<script', strtolower( $clean ) );
		self::assertStringNotContainsString( 'onload=', strtolower( $clean ) );
		self::assertStringNotContainsString( 'foreignobject', strtolower( $clean ) );
		self::assertStringNotContainsString( 'evil.example', strtolower( $clean ) );
		self::assertStringNotContainsString( '<style>', strtolower( $clean ) );
		self::assertStringContainsString( 'fill="none"', $clean );
	}
}
