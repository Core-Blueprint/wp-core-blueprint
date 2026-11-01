<?php
declare(strict_types=1);
/**
 * Plugin Name: Core Blueprint UI P53 Field Fixture
 * Description: Temporary staging-only visual acceptance page for Base UI P53.
 * Version: 0.0.1-test
 *
 * TEST-ONLY: never deploy on production. Remove after FIELD acceptance.
 */
defined( 'ABSPATH' ) || exit;

use CoreBlueprint\Core\UI\Assets;

add_action( 'admin_menu', static function (): void {
	if ( ! class_exists( Assets::class ) ) {
		return;
	}

	add_management_page(
		'Base UI P53 Field Fixture',
		'Base UI P53 Fixture',
		'manage_options',
		'cb-base-ui-p53-field',
		static function (): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'core-blueprint' ) );
			}

			$presentation = isset( $_GET['cbp53'] ) && 'core' === $_GET['cbp53'] ? 'core' : 'wp-native'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only visual mode.
			$native_url = add_query_arg( [ 'page' => 'cb-base-ui-p53-field', 'cbp53' => 'wp-native' ], admin_url( 'tools.php' ) );
			$core_url = add_query_arg( [ 'page' => 'cb-base-ui-p53-field', 'cbp53' => 'core' ], admin_url( 'tools.php' ) );
			?>
			<div class="wrap">
				<h1>Base UI P53 Field Fixture (staging only)</h1>
				<p>Use Tab and Shift+Tab for focus, inspect hover and disabled states, and toggle details by mouse and keyboard. This page never saves data.</p>
				<p>Presentation: <strong><?php echo esc_html( $presentation ); ?></strong>.
					<a href="<?php echo esc_url( $native_url ); ?>">WP-native</a> |
					<a href="<?php echo esc_url( $core_url ); ?>">Core</a>
				</p>
				<h2>Buttons</h2>
				<p><button type="button" class="button">Plain WordPress button (must remain native)</button></p>
				<p>
					<button type="button" class="button cb-core-button cb-core-button--primary">Primary</button>
					<button type="button" class="button cb-core-button cb-core-button--secondary">Secondary</button>
					<button type="button" class="button cb-core-button cb-core-button--remediation">Remediation</button>
					<button type="button" class="button cb-core-button cb-core-button--danger">Delete exception</button>
				</p>
				<p>
					<button type="button" class="button cb-core-button cb-core-button--danger cb-core-button--compact">Compact danger</button>
					<button type="button" class="button cb-core-button cb-core-button--danger" disabled>Disabled danger</button>
					<button type="button" class="button cb-core-button cb-core-button--secondary" aria-busy="true">Busy state</button>
					<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-button--icon-only" aria-label="Open calendar">
						<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
					</button>
				</p>
				<h2>Icon Controls</h2>
				<p>
					<button type="button" class="button-link cb-core-icon-control" aria-label="Default icon control">
						<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
					</button>
					<button type="button" class="button-link cb-core-icon-control cb-core-icon-control--outlined" aria-label="Outlined calendar control">
						<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
					</button>
					<button type="button" class="button-link cb-core-icon-control cb-core-icon-control--outlined" aria-label="Disabled calendar control" disabled>
						<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
					</button>
				</p>
				<h2>Native Details</h2>
				<details class="cb-core-details">
					<summary><span class="cb-core-details__chevron" aria-hidden="true"></span>Closed by default</summary>
					<p>Expanded disclosure content.</p>
				</details>
				<details class="cb-core-details" open>
					<summary><span class="cb-core-details__chevron" aria-hidden="true"></span>Open by default</summary>
					<p>Expanded disclosure content.</p>
				</details>
			</div>
			<?php
		}
	);
} );

add_action( 'admin_enqueue_scripts', static function ( string $hook_suffix ): void {
	if ( 'tools_page_cb-base-ui-p53-field' !== $hook_suffix || ! class_exists( Assets::class ) ) {
		return;
	}

	$presentation = isset( $_GET['cbp53'] ) && 'core' === $_GET['cbp53'] ? 'core' : 'wp-native'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only visual mode.
	Assets::enqueue_buttons( $presentation );
	Assets::enqueue_icon_controls( $presentation );
} );
