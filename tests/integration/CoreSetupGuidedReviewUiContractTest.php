<?php
declare(strict_types=1);

use CB\Core\Admin\Pages\Dashboard;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Setup\Admin\Page as SetupPage;
use CB\Core\Setup\Lifecycle;
use CB\Core\Setup\Registry;
use CB\Core\Setup\ReviewRepository;

final class CB_Base_Core_Setup_Guided_Review_UI_Contract_Test extends WP_UnitTestCase {

	private mixed $saved_setup_state;
	private array $saved_get = [];

	public function set_up(): void {
		parent::set_up();

		$this->saved_setup_state = get_option( ReviewRepository::OPTION, '__cb_missing__' );
		$this->saved_get = $_GET;
		delete_option( ReviewRepository::OPTION );
		$_GET = [];
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		if ( '__cb_missing__' === $this->saved_setup_state ) {
			delete_option( ReviewRepository::OPTION );
		} else {
			update_option( ReviewRepository::OPTION, $this->saved_setup_state, false );
		}
		$_GET = $this->saved_get;
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_ui1_first_install_opens_the_first_section_and_uses_start_wording(): void {
		$this->trusted_admin();
		Lifecycle::initialize_activation( true );

		$html = $this->render_setup();

		self::assertStringContainsString( 'Start Core Setup', $html );
		self::assertStringNotContainsString( 'Complete Core Setup', $html );
		self::assertStringContainsString( 'Environment &amp; availability (3)', $html );
		self::assertStringContainsString( 'Review (28)', $html );
		self::assertSame(
			8,
			preg_match_all( '/<a[^>]+class="nav-tab(?: nav-tab-active)?"/', $html )
		);
		self::assertMatchesRegularExpression(
			'/tab=environment-availability[^"]*" class="nav-tab nav-tab-active"/',
			$html
		);
		self::assertStringContainsString( 'name="action" value="cb_core_setup_review"', $html );
		self::assertStringContainsString( 'name="_wpnonce"', $html );
		self::assertStringNotContainsString( 'name="fingerprint"', $html );
		self::assertGreaterThan( 0, ReviewRepository::lifecycle()['started_at'] );
	}

	public function test_ui2_existing_site_opens_review_by_default_and_remains_reopenable(): void {
		$this->trusted_admin();
		Lifecycle::initialize_activation( false );

		$html = $this->render_setup();

		self::assertStringNotContainsString( 'Start Core Setup</h2>', $html );
		self::assertMatchesRegularExpression(
			'/tab=review[^"]*" class="nav-tab nav-tab-active"/',
			$html
		);
		self::assertStringContainsString( 'Review section', $html );
		self::assertStringContainsString( '28 checks', $html );

		$_GET['tab'] = 'mail';
		$reopened = $this->render_setup();
		self::assertMatchesRegularExpression(
			'/tab=mail[^"]*" class="nav-tab nav-tab-active"/',
			$reopened
		);
		self::assertStringContainsString( 'Mail (3)', $reopened );
	}

	public function test_ui3_dashboard_uses_start_then_review_core_setup_wording(): void {
		$user_id = $this->trusted_admin();
		Lifecycle::initialize_activation( true );

		ob_start();
		( new Dashboard() )->render();
		$before = (string) ob_get_clean();

		self::assertStringContainsString( 'Start Core Setup', $before );
		self::assertStringNotContainsString( 'Complete Core Setup', $before );

		Lifecycle::mark_started( $user_id );

		ob_start();
		( new Dashboard() )->render();
		$after = (string) ob_get_clean();

		self::assertStringContainsString( 'Review Core Setup', $after );
		self::assertStringNotContainsString( 'Start Core Setup', $after );
		self::assertStringContainsString( 'page=core-blueprint-setup', $after );
	}

	public function test_ui4_section_review_controls_keep_reasoned_choices_and_owner_deeplinks_visible(): void {
		$this->trusted_admin();
		Lifecycle::initialize_activation( false );
		$_GET['tab'] = 'cms-tools';

		$html = $this->render_setup();

		self::assertStringContainsString( 'CMS tools (8)', $html );
		self::assertStringContainsString( 'Open settings', $html );
		self::assertStringContainsString( 'Mark reviewed', $html );
		self::assertStringContainsString( 'Review later', $html );
		self::assertStringContainsString( 'Mark not applicable', $html );
		self::assertStringContainsString( 'Why will this be reviewed later?', $html );
		self::assertStringContainsString( 'Section note', $html );
		self::assertStringContainsString( 'cb_core_setup_note', $html );
	}

	public function test_ui5_limited_manage_options_user_sees_only_authorized_checks(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'core_setup_limited_ui_fixture' ) );
		wp_set_current_user( $user_id );
		Lifecycle::initialize_activation( false );

		$html = $this->render_setup();

		self::assertStringContainsString( 'Review (23)', $html );
		self::assertStringContainsString( 'CMS tools (5)', $html );
		self::assertStringNotContainsString( 'Review (28)', $html );
	}

	private function render_setup(): string {
		ob_start();
		( new SetupPage() )->render();
		return (string) ob_get_clean();
	}

	private function trusted_admin(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		foreach ( Registry::all() as $check ) {
			$user->add_cap( $check->capability() );
		}

		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'core_setup_guided_ui_fixture' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}
}
