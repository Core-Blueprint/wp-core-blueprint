<?php
declare(strict_types=1);
/**
 * Persistent Core Setup guided review page.
 *
 * Core Setup never owns canonical module configuration. It reads live evidence,
 * records human review intent, and deeplinks to the settings owner.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Admin;

use CB\Core\Admin\PageBase;
use CB\Core\Admin\Tabbed;
use CB\Core\Setup\Evidence;
use CB\Core\Setup\Lifecycle;
use CB\Core\Setup\Presentation;
use CB\Core\Setup\ReviewRepository;
use CB\Core\Setup\StatusResolver;
use CB\Core\Setup\Summary;
use CB\Core\UI\Icon;

defined( 'ABSPATH' ) || exit;

final class Page extends PageBase {
	use Tabbed;

	public const SLUG = 'core-blueprint-setup';

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'Core Setup', 'core-blueprint' );
	}

	public function menu_title(): string {
		return __( 'Core Setup', 'core-blueprint' );
	}

	public function position(): ?int {
		return 15;
	}

	public function capability(): string {
		return 'manage_options';
	}

	public function render(): void {
		$this->guard();

		$before = Lifecycle::ensure_initialized();
		$first_visit = ReviewRepository::ORIGIN_FIRST_INSTALL === $before['origin']
			&& 0 === (int) $before['started_at'];

		Lifecycle::mark_started( get_current_user_id() );

		$summary = Summary::current_user();
		$result  = Actions::pull_result();
		$tabs    = self::tab_labels( $summary );
		$default = 'overview';
		$tab     = $this->active_tab( array_keys( $tabs ), $default );

		ob_start();
		?>
		<div class="wrap cb-core-wrap cb-core-setup-wrap">
			<h1 class="cb-core-title"><?php esc_html_e( 'Core Setup', 'core-blueprint' ); ?></h1>

			<p class="cb-core-intro">
				<?php esc_html_e( 'Review the current Core Blueprint configuration and record the choices made for this site. Core Setup reads each subsystem live, so you can reopen it at any time to review what changed.', 'core-blueprint' ); ?>
			</p>

			<?php if ( $first_visit ) : ?>
				<div class="cb-core-notice cb-core-notice--info">
					<div class="cb-core-notice__content">
						<h2 class="cb-core-notice__title"><?php esc_html_e( 'Start Core Setup', 'core-blueprint' ); ?></h2>
						<p class="cb-core-notice__message">
							<?php esc_html_e( 'Work through the sections in your own order. Nothing is changed automatically, and you can return to this checklist later.', 'core-blueprint' ); ?>
						</p>
						<p class="cb-core-notice__message">
							<?php esc_html_e( 'When Core Blueprint is first activated by an authenticated WordPress user, that account is assigned the CB Operator role to establish the initial trusted operator.', 'core-blueprint' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=core-blueprint-preferences&tab=permissions' ) ); ?>">
								<?php esc_html_e( 'Review permissions', 'core-blueprint' ); ?>
							</a>
						</p>
					</div>
				</div>
			<?php endif; ?>

			<?php self::render_result_notice( $result ); ?>
			<?php self::render_status_strip( $summary ); ?>

			<?php
			if ( 'overview' === $tab ) {
				self::render_overview( $summary );
			} elseif ( isset( $summary['sections'][ $tab ] ) ) {
				self::render_section( $tab, $summary['sections'][ $tab ] );
			}
			?>
		</div>
		<?php
		$html = (string) ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tabs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** @param array<string,mixed> $summary @return array<string,string> */
	private static function tab_labels( array $summary ): array {
		$tabs = [
			'overview' => sprintf(
				/* translators: %d: total number of setup checks */
				__( 'Overview (%d)', 'core-blueprint' ),
				(int) ( $summary['total'] ?? 0 )
			),
		];

		foreach ( (array) $summary['sections'] as $section_id => $section ) {
			$tabs[ (string) $section_id ] = sprintf(
				/* translators: 1: section name, 2: number of setup checks */
				__( '%1$s (%2$d)', 'core-blueprint' ),
				(string) ( $section['label'] ?? $section_id ),
				(int) ( $section['total'] ?? 0 )
			);
		}

		return $tabs;
	}

	/** @param array<string,mixed> $summary */
	private static function render_status_strip( array $summary ): void {
		$cards = [
			[
				'label' => __( 'Sections', 'core-blueprint' ),
				'value' => count( (array) $summary['sections'] ),
				'state' => '',
			],
			[
				'label' => __( 'Checks', 'core-blueprint' ),
				'value' => (int) $summary['total'],
				'state' => '',
			],
			[
				'label' => __( 'Configured', 'core-blueprint' ),
				'value' => (int) $summary['counts'][ StatusResolver::CONFIGURED ],
				'state' => 'ok',
			],
			[
				'label' => __( 'Needs review', 'core-blueprint' ),
				'value' => (int) $summary['counts'][ StatusResolver::NEEDS_REVIEW ],
				'state' => (int) $summary['counts'][ StatusResolver::NEEDS_REVIEW ] > 0 ? 'warning' : 'ok',
			],
			[
				'label' => __( 'Attention', 'core-blueprint' ),
				'value' => (int) $summary['counts'][ StatusResolver::ATTENTION ],
				'state' => (int) $summary['counts'][ StatusResolver::ATTENTION ] > 0 ? 'critical' : 'ok',
			],
		];
		?>
		<div class="cb-core-status-strip" aria-label="<?php esc_attr_e( 'Core Setup review status', 'core-blueprint' ); ?>">
			<?php foreach ( $cards as $card ) : ?>
				<div class="cb-core-status-card<?php echo '' !== $card['state'] ? ' is-' . esc_attr( $card['state'] ) : ''; ?>">
					<span class="label"><?php echo esc_html( (string) $card['label'] ); ?></span>
					<span class="value"><?php echo esc_html( (string) $card['value'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/** @param array<string,mixed>|null $result */
	private static function render_result_notice( ?array $result ): void {
		if ( ! is_array( $result ) || empty( $result['message'] ) ) {
			return;
		}

		$type = 'error' === (string) ( $result['type'] ?? '' ) ? 'error' : 'success';
		?>
		<div class="cb-core-notice cb-core-notice--<?php echo esc_attr( $type ); ?>">
			<div class="cb-core-notice__content">
				<p class="cb-core-notice__message"><?php echo esc_html( (string) $result['message'] ); ?></p>
			</div>
		</div>
		<?php
	}

	/** @param array<string,mixed> $section */
	private static function render_section( string $section_id, array $section ): void {
		?>
		<section class="cb-core-panel">
			<h2><?php echo esc_html( (string) $section['label'] ); ?></h2>
			<p><?php echo esc_html( self::count_line( $section ) ); ?></p>
		</section>

		<?php foreach ( (array) $section['checks'] as $check ) : ?>
			<?php self::render_check( $section_id, (array) $check ); ?>
		<?php endforeach; ?>

		<?php if ( ! empty( $section['note_access'] ) ) : ?>
			<?php self::render_section_note( $section_id, $section ); ?>
		<?php endif; ?>
		<?php
	}

	/** @param array<string,mixed> $check */
	private static function render_check( string $section_id, array $check ): void {
		$status          = (string) ( $check['status'] ?? StatusResolver::NEEDS_REVIEW );
		$health          = (string) ( $check['evidence_health'] ?? Evidence::HEALTH_UNAVAILABLE );
		$review          = is_array( $check['review'] ?? null ) ? $check['review'] : null;
		$review_current  = ! empty( $check['review_current'] );
		$reason          = is_array( $review ) ? trim( (string) ( $review['reason'] ?? '' ) ) : '';
		$config_url      = esc_url( (string) ( $check['configuration_url'] ?? '' ) );
		$allows_later    = ! empty( $check['allows_later'] );
		$allows_na       = ! empty( $check['allows_not_applicable'] );
		$can_mark_reviewed = Evidence::HEALTH_OK === $health;
		?>
		<article class="cb-core-card">
			<div class="cb-core-card__header">
				<h3 class="cb-core-card__title"><?php echo esc_html( (string) $check['label'] ); ?></h3>
				<span class="cb-core-state-badge cb-core-state-badge--compact cb-core-state-badge--<?php echo esc_attr( Presentation::status_badge_variant( $status ) ); ?>">
					<?php echo esc_html( Presentation::status_label( $status ) ); ?>
				</span>
			</div>

			<div class="cb-core-card__body">
				<p class="cb-core-card__lead"><?php echo esc_html( Presentation::check_description( (string) $check['id'] ) ); ?></p>

				<?php if ( Evidence::HEALTH_UNAVAILABLE === $health ) : ?>
					<div class="cb-core-notice cb-core-notice--error">
						<div class="cb-core-notice__content">
							<p class="cb-core-notice__message">
								<?php esc_html_e( 'Current evidence is unavailable. This check cannot be marked reviewed until the owning subsystem can be read again.', 'core-blueprint' ); ?>
							</p>
						</div>
					</div>
				<?php elseif ( StatusResolver::ATTENTION === $status ) : ?>
					<div class="cb-core-notice cb-core-notice--warning">
						<div class="cb-core-notice__content">
							<p class="cb-core-notice__message">
								<?php esc_html_e( 'The current site state needs attention. Deferring this check does not hide or downgrade that warning.', 'core-blueprint' ); ?>
							</p>
						</div>
					</div>
				<?php elseif ( null !== $review && ! $review_current ) : ?>
					<div class="cb-core-notice cb-core-notice--warning">
						<div class="cb-core-notice__content">
							<p class="cb-core-notice__message">
								<?php esc_html_e( 'The configuration changed since this check was last reviewed. Review the current settings again.', 'core-blueprint' ); ?>
							</p>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $review_current && '' !== $reason ) : ?>
					<p>
						<strong><?php esc_html_e( 'Review reason:', 'core-blueprint' ); ?></strong>
						<?php echo esc_html( $reason ); ?>
					</p>
				<?php endif; ?>

				<div class="cb-core-field cb-core-field--separated">
					<div class="cb-core-actions">
						<?php if ( '' !== $config_url ) : ?>
							<a class="button" href="<?php echo $config_url; ?>">
								<?php esc_html_e( 'Open settings', 'core-blueprint' ); ?>
							</a>
						<?php endif; ?>

						<?php if ( $can_mark_reviewed && StatusResolver::CONFIGURED !== $status ) : ?>
							<?php self::render_review_form( $section_id, (string) $check['id'], ReviewRepository::REVIEWED, __( 'Mark reviewed', 'core-blueprint' ) ); ?>
						<?php endif; ?>

						<?php if ( null !== $review ) : ?>
							<form class="cb-core-form-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="cb_core_setup_clear">
								<input type="hidden" name="check_id" value="<?php echo esc_attr( (string) $check['id'] ); ?>">
								<input type="hidden" name="return_tab" value="<?php echo esc_attr( $section_id ); ?>">
								<?php wp_nonce_field( 'cb_core_setup_clear' ); ?>
								<button type="submit" class="button"><?php esc_html_e( 'Reset review', 'core-blueprint' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( $allows_later || $allows_na ) : ?>
					<div class="cb-core-field cb-core-field--separated">
						<span class="cb-core-field__label"><?php esc_html_e( 'Other review choices', 'core-blueprint' ); ?></span>
						<div class="cb-core-field__control">
							<?php if ( $allows_later ) : ?>
								<?php
								self::render_reasoned_review_form(
									$section_id,
									(string) $check['id'],
									ReviewRepository::LATER,
									__( 'Review later', 'core-blueprint' ),
									__( 'Why will this be reviewed later?', 'core-blueprint' )
								);
								?>
							<?php endif; ?>

							<?php if ( $allows_na ) : ?>
								<?php
								self::render_reasoned_review_form(
									$section_id,
									(string) $check['id'],
									ReviewRepository::NOT_APPLICABLE,
									__( 'Mark not applicable', 'core-blueprint' ),
									__( 'Why does this not apply to this site?', 'core-blueprint' )
								);
								?>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}

	private static function render_review_form( string $return_tab, string $check_id, string $disposition, string $label ): void {
		?>
		<form class="cb-core-form-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cb_core_setup_review">
			<input type="hidden" name="check_id" value="<?php echo esc_attr( $check_id ); ?>">
			<input type="hidden" name="disposition" value="<?php echo esc_attr( $disposition ); ?>">
			<input type="hidden" name="return_tab" value="<?php echo esc_attr( $return_tab ); ?>">
			<?php wp_nonce_field( 'cb_core_setup_review' ); ?>
			<button type="submit" class="button button-primary"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function render_reasoned_review_form(
		string $return_tab,
		string $check_id,
		string $disposition,
		string $label,
		string $placeholder
	): void {
		?>
		<form class="cb-core-field cb-core-field--inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cb_core_setup_review">
			<input type="hidden" name="check_id" value="<?php echo esc_attr( $check_id ); ?>">
			<input type="hidden" name="disposition" value="<?php echo esc_attr( $disposition ); ?>">
			<input type="hidden" name="return_tab" value="<?php echo esc_attr( $return_tab ); ?>">
			<?php wp_nonce_field( 'cb_core_setup_review' ); ?>
			<label>
				<span class="screen-reader-text"><?php echo esc_html( $placeholder ); ?></span>
				<input type="text" name="reason" required maxlength="1000" class="regular-text" placeholder="<?php echo esc_attr( $placeholder ); ?>">
			</label>
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/** @param array<string,mixed> $section */
	private static function render_section_note( string $section_id, array $section ): void {
		$note = is_array( $section['note'] ?? null ) ? (string) ( $section['note']['note'] ?? '' ) : '';
		?>
		<section class="cb-core-panel">
			<h2><?php esc_html_e( 'Section note', 'core-blueprint' ); ?></h2>
			<form class="cb-core-field" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cb_core_setup_note">
				<input type="hidden" name="section_id" value="<?php echo esc_attr( $section_id ); ?>">
				<input type="hidden" name="return_tab" value="<?php echo esc_attr( $section_id ); ?>">
				<?php wp_nonce_field( 'cb_core_setup_note' ); ?>
				<p class="description">
					<?php esc_html_e( 'Store setup-specific context for this section. This note does not change configuration and does not depend on the optional Notes module.', 'core-blueprint' ); ?>
				</p>
				<div class="cb-core-field__control">
					<textarea name="note" rows="5" class="large-text" maxlength="4000"><?php echo esc_textarea( $note ); ?></textarea>
				</div>
				<div class="cb-core-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save section note', 'core-blueprint' ); ?></button>
				</div>
			</form>
		</section>
		<?php
	}

	/** @param array<string,mixed> $summary */
	private static function render_overview( array $summary ): void {
		?>
		<section class="cb-core-panel">
			<h2><?php esc_html_e( 'Overview', 'core-blueprint' ); ?></h2>
			<p>
				<strong><?php echo esc_html( Presentation::overall_label( (string) $summary['overall'] ) ); ?></strong>
				<?php echo esc_html( ' · ' . self::count_line( $summary ) ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Reviewed means the current configuration has been consciously assessed. It is not a security guarantee and it does not prevent later changes from reopening a check.', 'core-blueprint' ); ?>
			</p>
		</section>

		<div class="cb-core-tab-cards">
			<?php foreach ( (array) $summary['sections'] as $section_id => $section ) : ?>
				<?php $section_status = self::section_status( (array) $section ); ?>
				<a class="cb-core-tab-card" href="<?php echo esc_url( add_query_arg( [ 'page' => self::SLUG, 'tab' => (string) $section_id ], admin_url( 'admin.php' ) ) ); ?>">
					<span class="cb-core-tab-card__body">
						<span class="cb-core-tab-card__label"><?php echo esc_html( (string) $section['label'] ); ?></span>
						<span class="cb-core-tab-card__desc"><?php echo esc_html( self::count_line( (array) $section ) ); ?></span>
						<span>
							<span class="cb-core-state-badge cb-core-state-badge--compact cb-core-state-badge--<?php echo esc_attr( Presentation::status_badge_variant( $section_status ) ); ?>">
								<?php echo esc_html( Presentation::status_label( $section_status ) ); ?>
							</span>
						</span>
					</span>
					<span class="cb-core-tab-card__arrow" aria-hidden="true">
						<?php echo Icon::render( 'chevron-right', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon::render() is escape-clean. ?>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/** @param array<string,mixed> $section */
	private static function section_status( array $section ): string {
		$counts = is_array( $section['counts'] ?? null ) ? $section['counts'] : [];

		if ( (int) ( $counts[ StatusResolver::ATTENTION ] ?? 0 ) > 0 ) {
			return StatusResolver::ATTENTION;
		}
		if ( (int) ( $counts[ StatusResolver::NEEDS_REVIEW ] ?? 0 ) > 0 ) {
			return StatusResolver::NEEDS_REVIEW;
		}
		if ( (int) ( $counts[ StatusResolver::LATER ] ?? 0 ) > 0 ) {
			return StatusResolver::LATER;
		}
		if ( (int) ( $counts[ StatusResolver::CONFIGURED ] ?? 0 ) > 0 ) {
			return StatusResolver::CONFIGURED;
		}

		return StatusResolver::NOT_APPLICABLE;
	}

	/** @param array<string,mixed> $scope */
	private static function count_line( array $scope ): string {
		$counts = is_array( $scope['counts'] ?? null ) ? $scope['counts'] : [];
		return sprintf(
			/* translators: 1: total checks, 2: configured, 3: needs review, 4: attention, 5: later, 6: not applicable */
			__( '%1$d checks · %2$d configured · %3$d need review · %4$d attention · %5$d later · %6$d not applicable', 'core-blueprint' ),
			(int) ( $scope['total'] ?? 0 ),
			(int) ( $counts[ StatusResolver::CONFIGURED ] ?? 0 ),
			(int) ( $counts[ StatusResolver::NEEDS_REVIEW ] ?? 0 ),
			(int) ( $counts[ StatusResolver::ATTENTION ] ?? 0 ),
			(int) ( $counts[ StatusResolver::LATER ] ?? 0 ),
			(int) ( $counts[ StatusResolver::NOT_APPLICABLE ] ?? 0 )
		);
	}
}
