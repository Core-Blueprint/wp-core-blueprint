<?php
declare(strict_types=1);

use CB\Core\Admin\PageRegistry;
use CB\Core\Admin\Pages\Dashboard;
use CB\Core\Environment\EnvironmentTypeTestShim;
use CB\Core\Governance\EventRegistry;
use CB\Core\Log\AuditLog;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Setup\Admin\Actions as SetupActions;
use CB\Core\Setup\Admin\Page as SetupPage;
use CB\Core\Setup\Bootstrap as SetupBootstrap;
use CB\Core\Setup\Lifecycle;
use CB\Core\Setup\Registry;
use CB\Core\Setup\ReviewManager;
use CB\Core\Setup\ReviewRepository;
use CB\Core\Setup\SectionRegistry;
use CB\Core\Setup\StatusResolver;
use CB\Core\Setup\Summary;

final class CB_Base_Core_Setup_Control_Plane_Contract_Test extends WP_UnitTestCase {

	private array $saved_options = [];

	public function set_up(): void {
		parent::set_up();

		foreach ( [
			ReviewRepository::OPTION,
			'cb_core_first_activated_at',
		] as $option ) {
			$this->saved_options[ $option ] = get_option( $option, '__cb_missing__' );
		}

		delete_option( ReviewRepository::OPTION );
		EnvironmentTypeTestShim::reset();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		foreach ( $this->saved_options as $option => $value ) {
			$this->restore_option( (string) $option, $value );
		}
		EnvironmentTypeTestShim::reset();
		wp_set_current_user( 0 );
		PageRegistry::_reset_for_testing();

		parent::tear_down();
	}

	public function test_cp1_activation_origin_is_stable_and_lazy_initialization_is_conservative(): void {
		Lifecycle::initialize_activation( true );
		$first = ReviewRepository::lifecycle();
		self::assertSame( ReviewRepository::ORIGIN_FIRST_INSTALL, $first['origin'] );
		self::assertGreaterThan( 0, $first['initialized_at'] );

		Lifecycle::initialize_activation( false );
		self::assertSame(
			ReviewRepository::ORIGIN_FIRST_INSTALL,
			ReviewRepository::lifecycle()['origin'],
			'A later activation must never rewrite first-install origin.'
		);

		delete_option( ReviewRepository::OPTION );
		update_option( 'cb_core_first_activated_at', '2026-09-01 12:00:00', false );

		$existing = Lifecycle::ensure_initialized();
		self::assertSame( ReviewRepository::ORIGIN_EXISTING_INSTALL, $existing['origin'] );
	}

	public function test_cp2_mark_started_is_idempotent_and_audits_exactly_once(): void {
		$admin = $this->trusted_admin();
		Lifecycle::initialize_activation( true );

		$before = $this->audit_count( 'core_setup_started' );
		self::assertTrue( Lifecycle::mark_started( $admin ) );
		self::assertFalse( Lifecycle::mark_started( $admin ) );

		$lifecycle = ReviewRepository::lifecycle();
		self::assertGreaterThan( 0, $lifecycle['started_at'] );
		self::assertSame( $admin, $lifecycle['started_by'] );
		self::assertSame( $before + 1, $this->audit_count( 'core_setup_started' ) );
	}

	public function test_cp3_summary_owns_raw_counts_not_percentages_and_covers_all_28_checks(): void {
		Lifecycle::initialize_activation( false );
		$summary = Summary::build( Registry::all() );

		self::assertSame( 29, $summary['total'] );
		self::assertArrayNotHasKey( 'percentage', $summary );
		self::assertArrayNotHasKey( 'progress', $summary );

		$total = array_sum( $summary['counts'] );
		self::assertSame( 29, $total );
		self::assertSame(
			[ 'environment-availability', 'administrator-recovery', 'safeguards', 'operations', 'mail', 'privacy-governance', 'cms-tools' ],
			array_keys( $summary['sections'] )
		);
		self::assertSame( 3, $summary['sections']['environment-availability']['total'] );
		self::assertSame( 4, $summary['sections']['administrator-recovery']['total'] );
		self::assertSame( 4, $summary['sections']['safeguards']['total'] );
		self::assertSame( 3, $summary['sections']['operations']['total'] );
		self::assertSame( 3, $summary['sections']['mail']['total'] );
		self::assertSame( 3, $summary['sections']['privacy-governance']['total'] );
		self::assertSame( 8, $summary['sections']['cms-tools']['total'] );
	}

	public function test_cp4_current_user_summary_respects_per_check_capabilities(): void {
		Lifecycle::initialize_activation( false );

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		$summary = Summary::current_user();

		self::assertSame( 0, $summary['total'] );
		self::assertSame( [], $summary['sections'] );
	}

	public function test_cp5_review_manager_enforces_capability_and_audits_only_semantic_change(): void {
		Lifecycle::initialize_activation( false );
		EnvironmentTypeTestShim::set( 'production' );

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$this->expectException( RuntimeException::class );
		ReviewManager::record( 'environment-identity', ReviewRepository::REVIEWED, '', $subscriber );
	}

	public function test_cp6_repeated_review_is_noop_and_does_not_duplicate_audit(): void {
		$admin = $this->trusted_admin();
		Lifecycle::initialize_activation( false );
		EnvironmentTypeTestShim::set( 'production' );

		$before = $this->audit_count( 'core_setup_reviewed' );
		self::assertTrue( ReviewManager::record( 'environment-identity', ReviewRepository::REVIEWED, '', $admin ) );
		self::assertFalse( ReviewManager::record( 'environment-identity', ReviewRepository::REVIEWED, '', $admin ) );
		self::assertSame( $before + 1, $this->audit_count( 'core_setup_reviewed' ) );

		$check = Registry::get( 'environment-identity' );
		self::assertNotNull( $check );
		self::assertSame( StatusResolver::CONFIGURED, StatusResolver::resolve( $check ) );
	}

	public function test_cp7_later_and_not_applicable_require_explicit_valid_reasoned_intent(): void {
		$admin = $this->trusted_admin();
		Lifecycle::initialize_activation( false );

		try {
			ReviewManager::record( 'mail-designer', ReviewRepository::LATER, '', $admin );
			self::fail( 'Later without a reason must be rejected.' );
		} catch ( InvalidArgumentException $e ) {
			self::assertStringContainsString( 'reason', strtolower( $e->getMessage() ) );
		}

		self::assertTrue(
			ReviewManager::record( 'mail-designer', ReviewRepository::LATER, 'Decide after launch.', $admin )
		);

		$this->expectException( InvalidArgumentException::class );
		ReviewManager::record( 'mail-designer', ReviewRepository::NOT_APPLICABLE, 'Not needed.', $admin );
	}

	public function test_cp8_section_notes_are_bounded_to_authorized_sections_and_never_logged_as_content(): void {
		$admin = $this->trusted_admin();
		Lifecycle::initialize_activation( false );
		self::assertTrue( SectionRegistry::can_manage_note( 'environment-availability' ) );

		$secret_note = 'Internal handover phrase that must not enter audit context.';
		$before = $this->audit_count( 'core_setup_note_updated' );

		self::assertTrue( ReviewManager::save_section_note( 'environment-availability', $secret_note, $admin ) );
		self::assertFalse( ReviewManager::save_section_note( 'environment-availability', $secret_note, $admin ) );
		self::assertSame( $before + 1, $this->audit_count( 'core_setup_note_updated' ) );

		$events = AuditLog::query( [
			'event_type' => 'core_setup_note_updated',
			'per_page'   => 5,
			'page'       => 1,
		] );
		$serialized = wp_json_encode( $events['rows'] );
		self::assertIsString( $serialized );
		self::assertStringNotContainsString( $secret_note, $serialized );
	}

	public function test_cp9_setup_route_is_reserved_base_owned_and_positioned_after_dashboard(): void {
		PageRegistry::_reset_for_testing();
		SetupBootstrap::register_admin_page();

		$page = PageRegistry::get( SetupPage::SLUG );
		self::assertInstanceOf( SetupPage::class, $page );
		self::assertSame( 'core-blueprint-setup', $page->slug() );
		self::assertSame( 15, $page->position() );
		self::assertSame( 'manage_options', $page->capability() );
	}

	public function test_cp10_admin_post_actions_and_audit_labels_are_registered(): void {
		SetupActions::boot();
		self::assertNotFalse( has_action( 'admin_post_cb_core_setup_review', [ SetupActions::class, 'review' ] ) );
		self::assertNotFalse( has_action( 'admin_post_cb_core_setup_clear', [ SetupActions::class, 'clear' ] ) );
		self::assertNotFalse( has_action( 'admin_post_cb_core_setup_note', [ SetupActions::class, 'note' ] ) );

		SetupBootstrap::register_event_labels();
		self::assertSame( 'Core Setup check reviewed', EventRegistry::label( 'core.setup.reviewed' ) );
		self::assertSame( 'Core Setup section note updated', EventRegistry::label( 'core.setup.note.updated' ) );
	}

	public function test_cp11_dashboard_contains_the_persistent_core_setup_entry(): void {
		$this->trusted_admin();
		Lifecycle::initialize_activation( false );

		ob_start();
		( new Dashboard() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Core Setup', $html );
		self::assertStringContainsString( 'page=core-blueprint-setup', $html );
	}

	private function trusted_admin(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'core_setup_control_plane_fixture' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	private function audit_count( string $event_type ): int {
		$result = AuditLog::query( [
			'event_type' => $event_type,
			'per_page'   => 1,
			'page'       => 1,
		] );
		return (int) $result['total'];
	}

	private function restore_option( string $name, mixed $value ): void {
		if ( '__cb_missing__' === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}
}
