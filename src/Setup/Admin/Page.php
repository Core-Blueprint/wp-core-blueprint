<?php
declare(strict_types=1);
/**
 * Core Setup admin route.
 *
 * Phase 4 intentionally renders only the structural progress shell. The
 * detailed guided-review presentation is layered on this control plane later.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Admin;

use CB\Core\Admin\PageBase;
use CB\Core\Setup\Lifecycle;
use CB\Core\Setup\SectionRegistry;
use CB\Core\Setup\StatusResolver;
use CB\Core\Setup\Summary;

defined( 'ABSPATH' ) || exit;

final class Page extends PageBase {

	public const SLUG = 'core-blueprint-setup';

	public function slug(): string { return self::SLUG; }
	public function title(): string { return 'Core Setup'; }
	public function menu_title(): string { return 'Core Setup'; }
	public function position(): ?int { return 15; }
	public function capability(): string { return 'manage_options'; }

	public function render(): void {
		$this->guard();
		Lifecycle::mark_started( get_current_user_id() );
		$summary = Summary::current_user();
		$result = Actions::pull_result();

		?>
		<div class="wrap cb-core-wrap cb-core-setup-wrap">
			<h1 class="cb-core-title">Core Setup</h1>
			<p class="cb-core-intro">Review the current Core Blueprint configuration without creating a second settings layer. Every check reads its owning subsystem live.</p>

			<?php if ( is_array( $result ) && ! empty( $result['message'] ) ) : ?>
				<div class="notice <?php echo 'error' === ( $result['type'] ?? '' ) ? 'notice-error' : 'notice-success'; ?> inline">
					<p><?php echo esc_html( (string) $result['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<section class="cb-core-panel">
				<h2>Review status</h2>
				<p>
					<strong><?php echo esc_html( self::overall_label( (string) $summary['overall'] ) ); ?></strong>
					· <?php echo esc_html( (string) $summary['total'] ); ?> checks
					· <?php echo esc_html( (string) $summary['counts'][ StatusResolver::CONFIGURED ] ); ?> configured
					· <?php echo esc_html( (string) $summary['counts'][ StatusResolver::NEEDS_REVIEW ] ); ?> need review
					· <?php echo esc_html( (string) $summary['counts'][ StatusResolver::ATTENTION ] ); ?> attention
					· <?php echo esc_html( (string) $summary['counts'][ StatusResolver::LATER ] ); ?> later
					· <?php echo esc_html( (string) $summary['counts'][ StatusResolver::NOT_APPLICABLE ] ); ?> not applicable
				</p>
			</section>

			<?php foreach ( $summary['sections'] as $section_id => $section ) : ?>
				<section class="cb-core-panel">
					<h2><?php echo esc_html( SectionRegistry::get( (string) $section_id )['label'] ?? (string) $section_id ); ?></h2>
					<p>
						<?php echo esc_html( (string) $section['total'] ); ?> checks
						· <?php echo esc_html( (string) $section['counts'][ StatusResolver::CONFIGURED ] ); ?> configured
						· <?php echo esc_html( (string) $section['counts'][ StatusResolver::NEEDS_REVIEW ] ); ?> need review
						· <?php echo esc_html( (string) $section['counts'][ StatusResolver::ATTENTION ] ); ?> attention
						· <?php echo esc_html( (string) $section['counts'][ StatusResolver::LATER ] ); ?> later
						· <?php echo esc_html( (string) $section['counts'][ StatusResolver::NOT_APPLICABLE ] ); ?> not applicable
					</p>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function overall_label( string $state ): string {
		return match ( $state ) {
			Summary::NEEDS_ATTENTION   => 'Needs attention',
			Summary::REVIEW_INCOMPLETE => 'Review incomplete',
			default                    => 'Reviewed',
		};
	}
}
