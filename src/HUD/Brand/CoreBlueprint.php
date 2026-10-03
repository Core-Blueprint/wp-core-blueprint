<?php
declare(strict_types=1);
/**
 * CoreBlueprint - built-in default brand for HUD.
 *
 * The baseline. Always registered, always available, never unregistered.
 * Acts as the final fallback in {@see BrandRegistry::current()}'s
 * resolution chain so HUD always has *some* brand to render.
 *
 * Logo: the canonical Core Blueprint gradient roundel supplied by the
 * shared Base brand boundary. HUD remains a consumer of that source so
 * product chrome never carries a private copy of the brand glyph.
 *
 * Palette: empty array - CoreBlueprint is the default look, no token
 * overrides needed. CB Base's tokens.css is the source of truth.
 * Brand-driven theme variants (dark/light/cyberpunk) layer on top via
 * the existing theme system, not via brand palette.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\HUD\Brand;

use CoreBlueprint\Core\Brand\CoreBlueprintMark;

defined( 'ABSPATH' ) || exit;

final class CoreBlueprint extends AbstractBrand {

	public function id(): string {
		return 'core-blueprint';
	}

	public function label(): string {
		return __( 'Core Blueprint', 'core-blueprint' );
	}

	public function status(): string {
		return 'available';
	}

	/**
	 * Static logo: the actual Core Blueprint icon - circular roundel
	 * with a teal-to-blue gradient (00FFDD → 0037FF) and the
	 * stylised C-mark in the brand-dark colour. This is the canonical
	 * brand glyph used across all Core Blueprint touchpoints.
	 *
	 * Note: this SVG ships its own colours via fill="url(#paint0...)"
	 * and fill="#131648" - it does NOT use currentColor like generic
	 * single-colour glyphs. That's intentional: the gradient + mark
	 * combination IS the brand. White-label brands implementing
	 * BrandInterface provide their own SVG with their own colour
	 * choices (or use currentColor if they want palette-driven
	 * colouring).
	 */
	public function logo_svg(): string {
		return CoreBlueprintMark::svg();
	}

	/**
	 * Animated variant: same canonical Core Blueprint mark with a
	 * subtle gradient-shift on the roundel (gives a "live" feel to
	 * the launcher without being attention-grabbing). The C-letter
	 * stays still - animating it would distort the brand glyph.
	 */
	public function logo_animated_svg(): string {
		return <<<'SVG'
<svg viewBox="0 0 800 800" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
	<style>
		.cb-brand-cb-roundel { animation: cb-brand-cb-shimmer 3.2s ease-in-out infinite; transform-origin: center; }
		@keyframes cb-brand-cb-shimmer {
			0%, 100% { filter: brightness(1) saturate(1); }
			50%      { filter: brightness(1.12) saturate(1.18); }
		}
	</style>
	<path class="cb-brand-cb-roundel" d="M0 400.001C0 179.087 179.086 0 400 0C620.914 0 800 179.087 800 400.001C800 620.915 620.914 800.002 400 800.002C179.086 800.002 0 620.915 0 400.001Z" fill="url(#cb-brand-cb-grad-anim)"/>
	<path d="M400 9.50586C615.664 9.50586 790.494 184.337 790.494 400.001C790.494 615.665 615.664 790.497 400 790.497C184.336 790.497 9.50586 615.665 9.50586 400.001C9.50587 184.337 184.336 9.50586 400 9.50586Z" stroke="white" stroke-opacity="0.16" stroke-width="19.0107"/>
	<mask id="cb-brand-cb-mask-anim" style="mask-type:alpha" maskUnits="userSpaceOnUse" x="0" y="0" width="800" height="801">
		<path d="M0 400.001C0 179.087 179.086 0 400 0C620.914 0 800 179.087 800 400.001C800 620.915 620.914 800.002 400 800.002C179.086 800.002 0 620.915 0 400.001Z" fill="#121547"/>
	</mask>
	<g mask="url(#cb-brand-cb-mask-anim)">
		<path d="M208.616 -4.50781V389.193H282.027V422.742H175.067V-4.50781H208.616Z" fill="#131648"/>
		<path d="M78.5698 365.968V15.4922H112.119V365.968C112.119 378.792 122.513 389.182 135.337 389.186H282.027V422.735H135.337C103.985 422.731 78.5698 397.321 78.5698 365.968Z" fill="#131648"/>
		<path d="M397.471 595.255C371.871 595.255 348.404 589.069 327.071 576.695C306.164 563.895 289.524 547.042 277.151 526.135C264.777 504.802 258.591 481.335 258.591 455.735L258.591 355.895C258.591 329.868 264.777 306.401 277.151 285.494C289.524 264.588 306.164 247.948 327.071 235.574C348.404 222.774 371.871 216.374 397.471 216.374C416.244 216.374 433.737 219.788 449.951 226.614C466.591 233.441 480.884 242.828 492.831 254.774C495.391 257.334 496.671 260.321 496.671 263.734C496.671 266.721 495.391 269.921 492.831 273.334C490.697 275.894 488.351 278.454 485.791 281.014C483.657 283.148 481.097 285.494 478.111 288.054C475.124 290.614 471.924 291.894 468.511 291.894C464.671 291.894 461.257 290.401 458.271 287.414C450.164 280.161 440.991 274.401 430.751 270.134C420.511 265.868 409.417 263.734 397.471 263.734C380.831 263.734 365.684 267.788 352.031 275.894C338.804 283.574 328.137 294.241 320.031 307.895C312.351 321.121 308.511 336.055 308.511 352.695L308.511 458.935C308.511 475.148 312.351 490.082 320.031 503.735C328.137 517.388 338.804 528.268 352.031 536.375C365.684 544.055 380.831 547.895 397.471 547.895C409.417 547.895 420.511 545.762 430.751 541.495C440.991 537.228 450.164 531.468 458.271 524.215C461.257 521.228 464.671 519.735 468.511 519.735C471.924 519.735 475.124 521.015 478.111 523.575C480.671 525.708 483.017 528.055 485.151 530.615C487.711 532.748 490.271 535.308 492.831 538.295C495.391 540.855 496.671 544.055 496.671 547.895C496.671 551.308 495.391 554.295 492.831 556.855C480.884 568.802 466.591 578.188 449.951 585.015C433.737 591.842 416.244 595.255 397.471 595.255Z" fill="#131648"/>
		<path d="M400.465 445.814C389.22 445.814 379.581 441.959 371.549 434.248C363.838 426.216 359.983 416.738 359.983 405.814C359.983 394.569 363.838 385.091 371.549 377.38C379.581 369.669 389.22 365.814 400.465 365.814C411.388 365.814 420.706 369.669 428.416 377.38C436.127 385.091 439.983 394.569 439.983 405.814C439.983 416.738 436.127 426.216 428.416 434.248C420.706 441.959 411.388 445.814 400.465 445.814Z" fill="#131648"/>
	</g>
	<defs>
		<linearGradient id="cb-brand-cb-grad-anim" x1="800" y1="0" x2="-0.00201416" y2="800" gradientUnits="userSpaceOnUse">
			<stop stop-color="#00FFDD"/>
			<stop offset="1" stop-color="#0037FF"/>
		</linearGradient>
	</defs>
</svg>
SVG;
	}

	/**
	 * No palette override - CoreBlueprint IS the default look, so we
	 * defer entirely to CB Base's tokens.css. Other brands fill this
	 * with their own accent / surface / text overrides.
	 */
	public function palette(): array {
		return [];
	}

	public function description(): array {
		return [
			'plain'     => __( 'The default Core Blueprint look - neutral, focused, suite-wide consistent.', 'core-blueprint' ),
			'technical' => __( 'Built-in baseline brand. Uses CB Base default tokens with no palette overrides; cb-* CSS variables resolve from tokens.css.', 'core-blueprint' ),
		];
	}
}
