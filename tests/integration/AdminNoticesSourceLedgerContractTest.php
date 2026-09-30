<?php
declare(strict_types=1);

use CB\Core\AdminNotices\Discovery;
use CB\Core\AdminNotices\SourceLedger;
use CB\Core\AdminNotices\SourceResolver;

final class CB_Base_Admin_Notices_Source_Ledger_Contract_Test extends WP_UnitTestCase {

	private mixed $saved_ledger;

	public function set_up(): void {
		parent::set_up();
		$this->saved_ledger = get_option( SourceLedger::OPTION, '__cb_notices_missing__' );
		delete_option( SourceLedger::OPTION );
	}

	public function tear_down(): void {
		if ( '__cb_notices_missing__' === $this->saved_ledger ) {
			delete_option( SourceLedger::OPTION );
		} else {
			update_option( SourceLedger::OPTION, $this->saved_ledger, false );
		}
		parent::tear_down();
	}

	public function test_anl1_observation_persists_metadata_only(): void {
		$entries = [
			[
				'priority' => 10,
				'callback' => static function (): void {},
				'source'   => [
					'id'         => 'plugin:example-plugin',
					'label'      => 'Example Plugin',
					'kind'       => SourceResolver::KIND_PLUGIN,
					'manageable' => true,
					'protected'  => false,
					'callback'   => 'Example\\Notice::render',
				],
			],
			[
				'priority' => 20,
				'callback' => static function (): void {},
				'source'   => [
					'id'         => 'plugin:example-plugin',
					'label'      => 'Example Plugin',
					'kind'       => SourceResolver::KIND_PLUGIN,
					'manageable' => true,
					'protected'  => false,
					'callback'   => 'Example\\Notice::render_secondary',
				],
			],
		];

		self::assertTrue( SourceLedger::observe_hook( 'admin_notices', $entries, 1_700_000_000 ) );

		$row = SourceLedger::source( 'plugin:example-plugin' );
		self::assertIsArray( $row );
		self::assertSame(
			[ 'id', 'label', 'kind', 'manageable', 'protected', 'hooks', 'callback_count', 'first_seen', 'last_seen' ],
			array_keys( $row )
		);
		self::assertSame( [ 'admin_notices' ], $row['hooks'] );
		self::assertSame( 2, $row['callback_count'] );
		self::assertSame( 1_700_000_000, $row['first_seen'] );
		self::assertSame( 1_700_000_000, $row['last_seen'] );

		$serialized = wp_json_encode( SourceLedger::get() );
		self::assertIsString( $serialized );
		foreach ( [ '"callback":', 'render_secondary', '<div', 'nonce', 'action_url', 'message' ] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $serialized );
		}
	}

	public function test_anl2_repeat_observation_is_write_throttled_until_metadata_or_seen_window_changes(): void {
		$entries = [
			[
				'priority' => 10,
				'callback' => static function (): void {},
				'source'   => [
					'id'         => 'theme:example-theme',
					'label'      => 'Example Theme',
					'kind'       => SourceResolver::KIND_THEME,
					'manageable' => true,
					'protected'  => false,
				],
			],
		];

		self::assertTrue( SourceLedger::observe_hook( 'all_admin_notices', $entries, 1_700_000_000 ) );
		self::assertFalse( SourceLedger::observe_hook( 'all_admin_notices', $entries, 1_700_000_100 ) );
		self::assertTrue( SourceLedger::observe_hook( 'all_admin_notices', $entries, 1_700_086_500 ) );

		$row = SourceLedger::source( 'theme:example-theme' );
		self::assertIsArray( $row );
		self::assertSame( 1_700_000_000, $row['first_seen'] );
		self::assertSame( 1_700_086_500, $row['last_seen'] );
	}

	public function test_anl3_unknown_sources_are_observable_but_never_manageable(): void {
		$entries = [
			[
				'priority' => 10,
				'callback' => static function (): void {},
				'source'   => [
					'id'         => 'unknown:0123456789abcdef',
					'label'      => 'Unknown source',
					'kind'       => SourceResolver::KIND_UNKNOWN,
					'manageable' => false,
					'protected'  => true,
				],
			],
		];

		self::assertTrue( SourceLedger::observe_hook( 'admin_notices', $entries, 1_700_000_000 ) );
		$row = SourceLedger::source( 'unknown:0123456789abcdef' );
		self::assertIsArray( $row );
		self::assertFalse( $row['manageable'] );
		self::assertTrue( $row['protected'] );
	}

	public function test_anl4_only_public_notice_hooks_can_be_observed(): void {
		self::assertContains( 'admin_notices', Discovery::HOOKS );
		self::assertFalse( SourceLedger::observe_hook( 'shutdown', [], 1_700_000_000 ) );
		self::assertSame( SourceLedger::defaults(), SourceLedger::get() );
	}
}
