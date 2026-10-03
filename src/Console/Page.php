<?php
declare(strict_types=1);
/**
 * Console Page - `Core Blueprint › Console`.
 *
 * Three-panel runner UI: command picker (left), argument form (right),
 * output stream (bottom). Lives between Preferences and Hub in the
 * sidebar (position 95) so operator-frequent surfaces cluster but Hub
 * stays at the bottom.
 *
 * Capability: cb_use_cli - operator-only. There is no admin-toggle for
 * this page; CB Console is for technicians, not end-customers.
 *
 * Server-side this page is a thin shell - the real work happens client-
 * side in the @cb-core/console ES module which fetches commands via the
 * REST endpoint at core-blueprint/v1/console/.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\Console;

use CoreBlueprint\Core\Admin\Admin;
use CoreBlueprint\Core\Admin\PageBase;

defined( 'ABSPATH' ) || exit;

final class Page extends PageBase {

	public function slug(): string {
		return Admin::CONSOLE_SLUG;
	}

	public function title(): string {
		return __( 'CB Console', 'core-blueprint' );
	}

	public function menu_title(): string {
		return __( 'Console', 'core-blueprint' );
	}

	public function position(): ?int {
		return 95;
	}

	public function capability(): string {
		return 'cb_use_cli';
	}

	public function render(): void {
		$this->guard();

		$nonce        = wp_create_nonce( 'wp_rest' );
		$rest_root    = esc_url_raw( rest_url( 'core-blueprint/v1/console/' ) );
		$current_user = wp_get_current_user();
		?>
		<div class="wrap cb-core-wrap cb-console" data-cb-console-root>
			<h1 class="cb-core-title"><?php esc_html_e( 'CB Console', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro">
				<?php esc_html_e( 'Run Core Blueprint CLI commands from the browser. Pick a command on the left, fill in arguments on the right, then run it. State-changing and destructive commands require explicit confirmation.', 'core-blueprint' ); ?>
			</p>

			<div class="cb-console__app" data-cb-console-app
				data-rest-root="<?php echo esc_attr( $rest_root ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
				data-actor-id="<?php echo esc_attr( (string) $current_user->ID ); ?>"
				data-actor-login="<?php echo esc_attr( (string) $current_user->user_login ); ?>"
			>
				<aside class="cb-console__picker" aria-label="<?php esc_attr_e( 'Commands', 'core-blueprint' ); ?>">
					<div class="cb-console__picker-search">
						<label class="screen-reader-text" for="cb-console-search"><?php esc_html_e( 'Filter commands', 'core-blueprint' ); ?></label>
						<input
							type="search"
							id="cb-console-search"
							class="cb-console__search-input"
							data-cb-console-search
							placeholder="<?php esc_attr_e( 'Filter commands…', 'core-blueprint' ); ?>"
							autocomplete="off"
							spellcheck="false"
						/>
					</div>
					<div class="cb-console__picker-list" data-cb-console-picker role="listbox" aria-label="<?php esc_attr_e( 'Available commands', 'core-blueprint' ); ?>">
						<div class="cb-console__picker-loading">
							<?php esc_html_e( 'Loading commands…', 'core-blueprint' ); ?>
						</div>
					</div>
				</aside>

				<section class="cb-console__form" aria-label="<?php esc_attr_e( 'Command arguments', 'core-blueprint' ); ?>">
					<header class="cb-console__form-header">
						<h2 class="cb-console__form-title" data-cb-console-form-title>
							<?php esc_html_e( 'Select a command', 'core-blueprint' ); ?>
						</h2>
						<p class="cb-console__form-desc" data-cb-console-form-desc>
							<?php esc_html_e( 'Pick a command from the list to view its arguments.', 'core-blueprint' ); ?>
						</p>
					</header>

					<div class="cb-console__form-body" data-cb-console-form-body>
						<div class="cb-console__form-empty">
							<?php esc_html_e( 'No command selected.', 'core-blueprint' ); ?>
						</div>
					</div>

					<footer class="cb-console__form-footer" data-cb-console-form-footer hidden>
						<div class="cb-console__form-side-effects" data-cb-console-side-effects></div>
						<button
							type="button"
							class="button button-primary cb-console__run-btn"
							data-cb-console-run
							disabled
						>
							<?php esc_html_e( 'Run command', 'core-blueprint' ); ?>
						</button>
					</footer>
				</section>

				<section class="cb-console__output" aria-label="<?php esc_attr_e( 'Command output', 'core-blueprint' ); ?>">
					<header class="cb-console__output-header">
						<h2 class="cb-console__output-title"><?php esc_html_e( 'Output', 'core-blueprint' ); ?></h2>
						<div class="cb-console__output-meta" data-cb-console-output-meta></div>
						<button
							type="button"
							class="button button-link cb-console__output-clear"
							data-cb-console-clear
							hidden
						>
							<?php esc_html_e( 'Clear', 'core-blueprint' ); ?>
						</button>
					</header>

					<div class="cb-console__output-body" data-cb-console-output-body>
						<div class="cb-console__output-empty">
							<?php esc_html_e( 'Output will appear here after you run a command.', 'core-blueprint' ); ?>
						</div>
					</div>
				</section>
			</div>
		</div>
		<?php
	}
}
