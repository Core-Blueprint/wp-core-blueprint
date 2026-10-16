<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

use CoreBlueprint\Core\Admin\Page;

defined( 'ABSPATH' ) || exit;

/** Metadata-only discovery. All consumer-owned actions stay in their Designer. */
final class AdminPage implements Page {
	public const SLUG = 'core-blueprint-template-library';

	public function slug(): string { return self::SLUG; }
	public function title(): string { return __( 'Template Library', 'core-blueprint' ); }
	public function menu_title(): string { return __( 'Template Library', 'core-blueprint' ); }
	public function position(): ?int { return 27; }
	public function capability(): string { return 'manage_options'; }

	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html( $this->title() ), '', [ 'response' => 403 ] );
		}
		echo '<div class="wrap cb-template-library-page cb-core-form-scope">';
		echo '<h1>' . esc_html( $this->title() ) . '</h1>';
		echo '<p>' . esc_html__( 'Browse templates', 'core-blueprint' ) . '</p>';
		echo '<div data-cb-template-library-global></div>';
		echo '</div>';
	}
}
