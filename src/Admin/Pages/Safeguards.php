<?php
declare(strict_types=1);
/**
 * Safeguards - the hardening + compliance configuration hub.
 *
 * Bundles the "set-and-forget" configuration that keeps a site structurally
 * weerbaar: a read-only status overview, Core Shield (master switch +
 * security modules + header test + audit retention), Access Mode,
 * Failsafe lockout-prevention, and Login Shield. Every tab corresponds to
 * a configurable safeguard layer (except Overview, which is a snapshot).
 *
 * Tabs:
 *   overview      - read-only status strip + bypass banner + quick actions
 *   two-factor    - privileged-account second-factor policy
 *   core-shield   - master switch, modules, header test + audit retention
 *   access-mode   - public / coming-soon / maintenance / admin-only state
 *   failsafe      - lockout bypass mechanisms + emergency controls
 *   login-shield  - custom login URL + /wp-admin guest-handling policy
 *
 * Permissions (meta-config: who may configure CB) lives under Preferences,
 * not here - Safeguards is hardening-config, governance is a separate
 * concern that fits the personal/site-wide preferences page.
 *
 * Tab logic lives in render_*_tab() methods on this class. Keeps the tab
 * catalog readable without a Page class per tab (shared page scope + caps).
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Admin\Pages;

use CB\Core\Admin\Admin;
use CB\Core\Admin\Overview;
use CB\Core\Admin\PageBase;
use CB\Core\Admin\Tabbed;
use CB\Core\Detector;
use CB\Core\Security\AccessMode as SecurityAccessMode;
use CB\Core\Security\Failsafe;
use CB\Core\Security\LoginShield;
use CB\Core\Security\ModuleRegistry;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\Policy as TwoFactorPolicy;
use CB\Core\Security\TwoFactor\ProviderDetector;
use CB\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Safeguards extends PageBase {
	use Tabbed;

	const SLUG = 'core-blueprint-safeguards';

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'Safeguards', 'core-blueprint' );
	}

	public function menu_title(): string {
		return __( 'Safeguards', 'core-blueprint' );
	}

	public function position(): ?int {
		return 30;
	}

	public function render(): void {
		$this->guard();

		if ( ! class_exists( Settings::class ) ) {
			echo '<div class="wrap"><p>';
			esc_html_e( 'Safeguards subsystem not loaded.', 'core-blueprint' );
			echo '</p></div>';
			return;
		}

		// Tab order: core security first, optional features second, emergency last.
		//   1. Overview      - read-only status
		//   2. Access Mode   - site-level gate (is the site even reachable?)
		//   3. Login Shield  - login-endpoint hardening (narrow, specific)
		//   4. Two-factor    - privileged-account authentication policy
		//   5. Core Shield   - baseline hardening (master switch + modules + headers)
		//   6. Core Scanner  - file integrity verification (read-only checks)
		//   7. Failsafe      - emergency escape hatch (last resort)
		//
		// Threat-model flow: access → login endpoint → authentication → configuration → files →
		// recovery. Login Shield sits before Core Shield
		// because protecting the login endpoint is the more specific,
		// outer-perimeter concern; Core Shield is the broader baseline
		// hardening once an authenticated user is past the login.
		//
		// Permissions (meta - who may configure CB) lives under Preferences,
		// not here. Safeguards is hardening-config; Permissions is governance.
		$available_tabs = [ 'overview', 'access-mode', 'login-shield', 'two-factor', 'core-shield', 'core-scanner', 'failsafe' ];
		$tab_labels     = [
			'overview'     => __( 'Overview',      'core-blueprint' ),
			'access-mode'  => __( 'Access Mode',   'core-blueprint' ),
			'login-shield' => __( 'Login Shield',  'core-blueprint' ),
			'two-factor'   => __( 'Two-factor',    'core-blueprint' ),
			'core-shield'  => __( 'Core Shield',   'core-blueprint' ),
			'core-scanner' => __( 'Core Scanner',  'core-blueprint' ),
			'failsafe'     => __( 'Failsafe',      'core-blueprint' ),
		];

		$tab = $this->active_tab( $available_tabs, 'overview' );

		switch ( $tab ) {
			case 'access-mode':  $this->render_access_mode_tab( $tab, $tab_labels );  return;
			case 'core-shield':  $this->render_core_shield_tab( $tab, $tab_labels );  return;
			case 'core-scanner': $this->render_core_scanner_tab( $tab, $tab_labels ); return;
			case 'login-shield': $this->render_login_shield_tab( $tab, $tab_labels ); return;
			case 'two-factor':   $this->render_two_factor_tab( $tab, $tab_labels );   return;
			case 'failsafe':     $this->render_failsafe_tab( $tab, $tab_labels );     return;
			default:             $this->render_overview_tab( $tab, $tab_labels );     return;
		}
	}

	// ─── Tab renderers ─────────────────────────────────────────────────────

	private function render_overview_tab( string $tab, array $tab_labels ): void {
		$settings  = Settings::get();
		$site_mode = Settings::site_mode();
		$bypassed  = class_exists( Failsafe::class ) ? Failsafe::is_bypassed()   : false;
		$layers    = class_exists( Failsafe::class ) ? Failsafe::active_layers() : [];
		$modules   = ModuleRegistry::all();
		$self_test = class_exists( Failsafe::class ) ? Failsafe::self_test()     : [];

		// Failsafe status: aggregate self-test result.
		$self_test_ok = true;
		foreach ( $self_test as $check ) {
			if ( empty( $check['ok'] ) ) {
				$self_test_ok = false;
				break;
			}
		}

		$shield_on    = Settings::shield_enabled();
		$module_count = count( $modules );
		$audit_total  = (int) \CB\Core\Log\AuditLog::query( [ 'per_page' => 1 ] )['total'];

		// Optional bypass banner - supplied as raw HTML to the Overview
		// helper because it contains dynamic composed layer lists that
		// the helper's generic card rendering can't represent.
		$banner = '';
		if ( $bypassed ) {
			$active_layer_labels = [];
			if ( ! empty( $layers['constant'] ) )  { $active_layer_labels[] = __( 'wp-config.php constant', 'core-blueprint' ); }
			if ( ! empty( $layers['option'] ) )    { $active_layer_labels[] = __( 'emergency option', 'core-blueprint' ); }
			if ( ! empty( $layers['transient'] ) ) { $active_layer_labels[] = __( '60-minute bypass window', 'core-blueprint' ); }

			ob_start();
			?>
			<div class="cb-core-bypass-banner">
				⚠ <?php esc_html_e( 'Emergency bypass is ACTIVE. Restrictive security features are currently disabled.', 'core-blueprint' ); ?>
				<span class="layers">
					<?php
					/* translators: %s: comma-separated list of active bypass layers */
					echo esc_html( sprintf( __( 'Active via: %s', 'core-blueprint' ), implode( ', ', $active_layer_labels ) ) );
					?>
				</span>
			</div>
			<?php
			$banner = ob_get_clean();
		}

		$core_shield_url   = admin_url( 'admin.php?page=' . self::SLUG . '&tab=core-shield' );
		$access_mode_url   = admin_url( 'admin.php?page=' . self::SLUG . '&tab=access-mode' );
		$failsafe_url      = admin_url( 'admin.php?page=' . self::SLUG . '&tab=failsafe' );
		$login_shield_url  = admin_url( 'admin.php?page=' . self::SLUG . '&tab=login-shield' );
		$two_factor_url    = admin_url( 'admin.php?page=' . self::SLUG . '&tab=two-factor' );
		$core_scanner_url  = admin_url( 'admin.php?page=' . self::SLUG . '&tab=core-scanner' );

		ob_start();
		Overview::render( [
			'title' => __( 'Overview', 'core-blueprint' ),
			'intro' => __( 'Status summary for every Safeguards layer on this site. Use the tab cards below to jump straight to what you need, or the Quick actions at the bottom for common operations.', 'core-blueprint' ),

			'banner' => $banner,

			'status_cards' => [
				[
					'label'  => __( 'Failsafe', 'core-blueprint' ),
					'value'  => $self_test_ok ? __( 'Operational', 'core-blueprint' ) : __( 'Check required', 'core-blueprint' ),
					'state'  => $self_test_ok ? 'ok' : 'critical',
					'detail' => sprintf(
						'<a href="%s">%s</a>',
						esc_url( $failsafe_url ),
						esc_html__( 'View details →', 'core-blueprint' )
					),
				],
				[
					'label'  => _x( 'Core Shield', 'status card label', 'core-blueprint' ),
					'value'  => $shield_on ? __( 'On', 'core-blueprint' ) : __( 'Off', 'core-blueprint' ),
					'state'  => $shield_on ? 'ok' : 'warning',
					'detail' => $shield_on
						? __( 'All Core Blueprint security is active', 'core-blueprint' )
						: __( 'All features disabled', 'core-blueprint' ),
				],
				[
					'label'  => __( 'Modules registered', 'core-blueprint' ),
					'value'  => (string) $module_count,
					'detail' => sprintf(
						'<a href="%s">%s</a>',
						esc_url( $core_shield_url ),
						esc_html__( 'Manage modules →', 'core-blueprint' )
					),
				],
				[
					'label'  => __( 'Audit events logged', 'core-blueprint' ),
					'value'  => number_format_i18n( $audit_total ),
					'detail' => sprintf(
						'<a href="%s">%s</a>',
						esc_url( admin_url( 'admin.php?page=' . Admin::LOGS_SLUG . '&tab=audit' ) ),
						esc_html__( 'Open audit log →', 'core-blueprint' )
					),
				],
			],

			'tab_cards' => [
				[
					'slug'  => 'access-mode',
					'url'   => $access_mode_url,
					'label' => __( 'Access Mode', 'core-blueprint' ),
					'desc'  => __( 'Choose Public, Coming Soon, Maintenance, or Admin-Only and control how visitors and crawlers see the site.', 'core-blueprint' ),
					'icon'  => 'lock',
				],
				[
					'slug'  => 'login-shield',
					'url'   => $login_shield_url,
					'label' => __( 'Login Shield', 'core-blueprint' ),
					'desc'  => __( 'Hide /wp-login.php behind a custom URL so blind brute-force scans get a 404 instead of reaching your login form.', 'core-blueprint' ),
					'icon'  => 'admin-network',
				],
				[
					'slug'  => 'two-factor',
					'url'   => $two_factor_url,
					'label' => __( 'Two-factor', 'core-blueprint' ),
					'desc'  => __( 'Require a second factor for privileged WordPress accounts, with Base TOTP, recovery codes and supported external-provider coexistence.', 'core-blueprint' ),
					'icon'  => 'lock',
				],
				[
					'slug'  => 'core-shield',
					'url'   => $core_shield_url,
					'label' => __( 'Core Shield', 'core-blueprint' ),
					'desc'  => __( 'Master switch for all Core Blueprint security. Configure hardening modules, security headers, and the complementary-plugin detector.', 'core-blueprint' ),
					'icon'  => 'shield',
				],
				[
					'slug'  => 'core-scanner',
					'url'   => $core_scanner_url,
					'label' => __( 'Core Scanner', 'core-blueprint' ),
					'desc'  => __( 'File integrity verification for WordPress core, supported plugins and themes, and the uploads directory. Detects unexpected changes; never modifies files.', 'core-blueprint' ),
					'icon'  => 'search',
				],
				[
					'slug'  => 'failsafe',
					'url'   => $failsafe_url,
					'label' => __( 'Failsafe', 'core-blueprint' ),
					'desc'  => __( 'Lockout-prevention: four independent recovery layers that guarantee you can always get back into your own site.', 'core-blueprint' ),
					'icon'  => 'sos',
				],
			],

			'quick_actions' => [
				[
					'url'     => $core_shield_url,
					'label'   => __( 'Configure Core Shield', 'core-blueprint' ),
					'primary' => false,
				],
				[
					'url'     => $failsafe_url,
					'label'   => __( 'Failsafe & emergency controls', 'core-blueprint' ),
					'primary' => false,
				],
				[
					'url'     => admin_url( 'admin.php?page=' . Admin::LOGS_SLUG . '&tab=audit' ),
					'label'   => __( 'View audit log', 'core-blueprint' ),
					'primary' => false,
				],
			],
		] );
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function render_core_shield_tab( string $tab, array $tab_labels ): void {
		$settings   = Settings::get();
		$site_mode  = Settings::site_mode();
		$detector   = class_exists( Detector::class ) ? Detector::summary() : [];
		$modules    = ModuleRegistry::all();

		// Privileged Access Protection is an always-on detection/review boundary.
		// The selected policy decides whether unapproved native WordPress access
		// is restricted. Detailed identity data and trust-authority controls stay
		// available only to a signed, approved Core Blueprint Operator.
		$current_user             = wp_get_current_user();
		$can_manage_privileged    = current_user_can( 'cb_manage_permissions' )
			&& $current_user instanceof \WP_User
			&& \CB\Core\Permissions\PrivilegedAccessGuard::is_trusted_operator( $current_user );
		$privileged_access_mode  = \CB\Core\Permissions\PrivilegedAccessPolicy::enforcement_mode();
		$privileged_review_users = [];
		$approved_privileged_count = 0;
		if ( $can_manage_privileged && class_exists( '\CB\Core\Permissions\PrivilegedAccessRegistry' ) ) {
			$privileged_review_users     = \CB\Core\Permissions\PrivilegedAccessRegistry::review_snapshot();
			$approved_privileged_count = \CB\Core\Permissions\PrivilegedAccessRegistry::approved_count();
		}

		ob_start();
		include CB_CORE_DIR . 'templates/core-shield.php';
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function render_access_mode_tab( string $tab, array $tab_labels ): void {
		if ( ! class_exists( SecurityAccessMode::class ) ) {
			$this->render_subsystem_missing( __( 'Access Mode subsystem not loaded.', 'core-blueprint' ) );
			return;
		}

		$current = SecurityAccessMode::current();
		$config  = SecurityAccessMode::config();

		ob_start();
		include CB_CORE_DIR . 'templates/access-mode.php';
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function render_failsafe_tab( string $tab, array $tab_labels ): void {
		if ( ! class_exists( Failsafe::class ) ) {
			$this->render_subsystem_missing( __( 'Failsafe subsystem not loaded.', 'core-blueprint' ) );
			return;
		}
		$self_test = Failsafe::self_test();
		$layers    = Failsafe::active_layers();
		$bypassed  = Failsafe::is_bypassed();

		ob_start();
		include CB_CORE_DIR . 'templates/failsafe.php';
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function render_login_shield_tab( string $tab, array $tab_labels ): void {
		if ( ! class_exists( LoginShield::class ) ) {
			$this->render_subsystem_missing( __( 'Login Shield subsystem not loaded.', 'core-blueprint' ) );
			return;
		}

		$config         = LoginShield::config();
		$custom_url     = LoginShield::custom_login_url();
		$shield_on      = class_exists( Settings::class ) ? Settings::shield_enabled() : true;
		$bypassed       = class_exists( Failsafe::class ) ? Failsafe::is_bypassed() : false;
		$enforcing      = LoginShield::is_enforcing();
		$safeguards_url = admin_url( 'admin.php?page=' . self::SLUG . '&tab=failsafe' );

		ob_start();
		include CB_CORE_DIR . 'templates/login-shield.php';
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Render the Core Scanner tab. Delegates panel rendering to the
	 * Integrity Page class, which owns the rich UI (status, metrics,
	 * findings, history, baseline diff). Page-level chrome (h1, intro,
	 * tabnav) is handled here so the renderer stays focused on the
	 * scanner panel itself.
	 */
	private function render_two_factor_tab( string $tab, array $tab_labels ): void {
		$current_user = wp_get_current_user();
		$can_manage = current_user_can( 'cb_manage_permissions' )
			&& $current_user instanceof \WP_User
			&& \CB\Core\Permissions\PrivilegedAccessGuard::is_trusted_operator( $current_user );
		$base_enrolled = $current_user instanceof \WP_User
			&& $current_user->ID > 0
			&& CredentialStore::is_enrolled( (int) $current_user->ID );
		$providers = $current_user instanceof \WP_User
			? ProviderDetector::providers_for_user( $current_user )
			: [];
		$policy = TwoFactorPolicy::config();
		$bypassed = class_exists( Failsafe::class ) && Failsafe::is_bypassed();
		$profile_url = admin_url( 'profile.php#cb-core-two-factor' );

		ob_start();
		include CB_CORE_DIR . 'templates/two-factor-policy.php';
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function render_core_scanner_tab( string $tab, array $tab_labels ): void {
		if ( ! class_exists( '\\CB\\Core\\Integrity\\Admin\\Page' ) ) {
			$this->render_subsystem_missing( __( 'Core Scanner subsystem not loaded.', 'core-blueprint' ) );
			return;
		}

		ob_start();
		?>
		<div class="wrap cb-core-wrap">
			<h1 class="cb-core-title"><?php esc_html_e( 'Core Scanner', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro">
				<?php esc_html_e( 'File integrity verification for WordPress core, supported plugins and themes, and the uploads directory. Detects unexpected changes; never modifies files. Run on demand or schedule daily/weekly.', 'core-blueprint' ); ?>
			</p>
			<?php \CB\Core\Integrity\Admin\Page::render_panel(); ?>
		</div>
		<?php
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
