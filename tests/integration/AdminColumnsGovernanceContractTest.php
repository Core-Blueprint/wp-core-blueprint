<?php
declare(strict_types=1);

use CB\Core\AdminColumns\Bootstrap as AdminColumnsBootstrap;
use CB\Core\AdminColumns\PolicyRepository;
use CB\Core\AdminColumns\Runtime;
use CB\Core\AdminColumns\SupportedScreen;

final class CB_Base_Admin_Columns_Governance_Contract_Test extends WP_UnitTestCase {
	private mixed $saved_policy = '__cb_admin_columns_missing__';
	private string $saved_pagenow = '';
	private string $saved_typenow = '';

	public function set_up(): void {
		parent::set_up();
		$this->saved_policy = get_option( PolicyRepository::OPTION, '__cb_admin_columns_missing__' );
		$this->saved_pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		$this->saved_typenow = isset( $GLOBALS['typenow'] ) ? (string) $GLOBALS['typenow'] : '';

		delete_option( PolicyRepository::OPTION );
		Runtime::_reset_for_testing();
		AdminColumnsBootstrap::boot();

		if ( ! post_type_exists( 'cb_column_fixture' ) ) {
			register_post_type( 'cb_column_fixture', [
				'label'        => 'Column fixtures',
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'supports'     => [ 'title', 'author' ],
			] );
		}
	}

	public function tear_down(): void {
		if ( '__cb_admin_columns_missing__' === $this->saved_policy ) {
			delete_option( PolicyRepository::OPTION );
		} else {
			update_option( PolicyRepository::OPTION, $this->saved_policy, false );
		}

		if ( post_type_exists( 'cb_column_fixture' ) ) {
			unregister_post_type( 'cb_column_fixture' );
		}

		$GLOBALS['pagenow'] = $this->saved_pagenow;
		$GLOBALS['typenow'] = $this->saved_typenow;
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_acg1_policy_schema_is_bounded_deterministic_and_dormant_safe(): void {
		$normalized = PolicyRepository::normalize( [
			'schema_version' => 1,
			'screens' => [
				'edit-cb_missing_fixture' => [
					'order'  => [ 'title', 'future_plugin', 'cb', 'date' ],
					'hidden' => [ 'future_plugin' ],
				],
			],
		] );

		self::assertSame( 1, $normalized['schema_version'] );
		self::assertSame(
			[ 'cb', 'title', 'future_plugin', 'date' ],
			$normalized['screens']['edit-cb_missing_fixture']['order']
		);
		self::assertSame( [ 'future_plugin' ], $normalized['screens']['edit-cb_missing_fixture']['hidden'] );

		$invalid = $normalized;
		$invalid['screens']['edit-cb_missing_fixture']['hidden'] = [ 'title' ];
		$this->expectException( InvalidArgumentException::class );
		PolicyRepository::normalize( $invalid );
	}

	public function test_acg2_supported_screen_predicate_is_limited_to_normal_post_list_screens(): void {
		foreach ( [ 'post', 'page', 'cb_column_fixture' ] as $post_type ) {
			$screen = $this->set_post_list_screen( $post_type );
			self::assertTrue( SupportedScreen::is_supported( $screen ), $post_type . ' should be supported.' );
		}

		$GLOBALS['pagenow'] = 'users.php';
		set_current_screen( 'users' );
		self::assertFalse( SupportedScreen::is_supported() );

		$GLOBALS['pagenow'] = 'upload.php';
		set_current_screen( 'upload' );
		self::assertFalse( SupportedScreen::is_supported() );
	}

	public function test_acg3_posts_pages_and_cpt_keep_the_native_wp_posts_list_table(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';

		foreach ( [ 'post', 'page', 'cb_column_fixture' ] as $post_type ) {
			$screen = $this->set_post_list_screen( $post_type );
			$table = new WP_Posts_List_Table( [ 'screen' => $screen ] );
			$hook = 'manage_' . $screen->id . '_columns';

			try {
				$columns = apply_filters( $hook, [] );
				self::assertInstanceOf( WP_Posts_List_Table::class, $table );
				self::assertArrayHasKey( 'cb', $columns );
				self::assertArrayHasKey( 'title', $columns );
				self::assertSame( PHP_INT_MAX, has_filter( $hook, [ Runtime::class, 'filter_columns' ] ) );
			} finally {
				remove_filter( $hook, [ $table, 'get_columns' ], 0 );
			}
		}
	}

	public function test_acg4_empty_policy_is_an_exact_no_op_and_discovery_keeps_the_received_map(): void {
		$screen = $this->set_post_list_screen( 'post' );
		$hook = 'manage_' . $screen->id . '_columns';
		$incoming = [
			'cb'             => '<input type="checkbox">',
			'title'          => 'Title',
			'fixture_plugin' => 'Fixture plugin',
			'date'           => 'Date',
		];

		$result = apply_filters( $hook, $incoming );
		self::assertSame( $incoming, $result );
		self::assertSame( $incoming, Runtime::discovered_columns( 'edit-post' ) );
	}

	public function test_acg5_hide_and_reorder_touch_only_governed_existing_columns_and_keep_unknown_anchor(): void {
		$incoming = [
			'cb'             => 'CB',
			'title'          => 'Title',
			'plugin_new'     => 'New plugin',
			'author'         => 'Author',
			'date'           => 'Date',
		];
		$policy = [
			'order'  => [ 'cb', 'date', 'title', 'author', 'dormant_missing' ],
			'hidden' => [ 'author' ],
		];

		$result = Runtime::apply_policy( $incoming, $policy );
		self::assertSame( [ 'cb', 'date', 'plugin_new', 'title' ], array_keys( $result ) );
		self::assertSame( 'New plugin', $result['plugin_new'] );
		self::assertArrayNotHasKey( 'author', $result );
		self::assertArrayNotHasKey( 'dormant_missing', $result );
	}

	public function test_acg6_structural_columns_are_never_synthesized_or_hidden(): void {
		$without_cb = Runtime::apply_policy(
			[ 'title' => 'Title', 'date' => 'Date' ],
			[ 'order' => [ 'cb', 'date', 'title' ], 'hidden' => [] ]
		);
		self::assertSame( [ 'date', 'title' ], array_keys( $without_cb ) );
		self::assertArrayNotHasKey( 'cb', $without_cb );

		$without_title = Runtime::apply_policy(
			[ 'cb' => 'CB', 'date' => 'Date' ],
			[ 'order' => [ 'cb', 'title', 'date' ], 'hidden' => [ 'title' ] ]
		);
		self::assertSame( [ 'cb', 'date' ], array_keys( $without_title ) );
		self::assertArrayNotHasKey( 'title', $without_title );

		$corrupt_hide = Runtime::apply_policy(
			[ 'date' => 'Date', 'cb' => 'CB', 'title' => 'Title' ],
			[ 'order' => [ 'date', 'cb', 'title' ], 'hidden' => [ 'cb', 'title' ] ]
		);
		self::assertSame( [ 'cb', 'date', 'title' ], array_keys( $corrupt_hide ) );
	}


	public function test_acg7_site_policy_hide_and_show_run_through_the_native_column_filter(): void {
		$screen = $this->set_post_list_screen( 'post' );
		$hook = 'manage_' . $screen->id . '_columns';
		$plugin_filter = static function ( array $columns ): array {
			$columns['fixture_existing'] = 'Existing plugin';
			return $columns;
		};
		add_filter( $hook, $plugin_filter, 100 );

		try {
			$this->store_policy_for_test( [
				'schema_version' => 1,
				'screens' => [
					'edit-post' => [
						'order'  => [ 'cb', 'title', 'fixture_existing', 'date' ],
						'hidden' => [ 'fixture_existing' ],
					],
				],
			] );
			$hidden = apply_filters( $hook, [
				'cb'    => 'CB',
				'title' => 'Title',
				'date'  => 'Date',
			] );
			self::assertArrayNotHasKey( 'fixture_existing', $hidden );

			$this->store_policy_for_test( [
				'schema_version' => 1,
				'screens' => [
					'edit-post' => [
						'order'  => [ 'cb', 'title', 'fixture_existing', 'date' ],
						'hidden' => [],
					],
				],
			] );
			$shown = apply_filters( $hook, [
				'cb'    => 'CB',
				'title' => 'Title',
				'date'  => 'Date',
			] );
			self::assertArrayHasKey( 'fixture_existing', $shown );
		} finally {
			remove_filter( $hook, $plugin_filter, 100 );
		}
	}

	public function test_acg8_column_added_after_policy_save_remains_visible(): void {
		$screen = $this->set_post_list_screen( 'post' );
		$hook = 'manage_' . $screen->id . '_columns';

		$this->store_policy_for_test( [
			'schema_version' => 1,
			'screens' => [
				'edit-post' => [
					'order'  => [ 'cb', 'date', 'title' ],
					'hidden' => [],
				],
			],
		] );

		$plugin_filter = static function ( array $columns ): array {
			$columns['plugin_added_later'] = 'Added later';
			return $columns;
		};
		add_filter( $hook, $plugin_filter, 100 );

		try {
			$result = apply_filters( $hook, [
				'cb'    => 'CB',
				'title' => 'Title',
				'date'  => 'Date',
			] );
			self::assertArrayHasKey( 'plugin_added_later', $result );
			self::assertSame( 'Added later', $result['plugin_added_later'] );
		} finally {
			remove_filter( $hook, $plugin_filter, 100 );
		}
	}

	public function test_acg9_runtime_owns_only_column_map_governance_not_table_or_query_behavior(): void {
		$root = dirname( __DIR__, 2 );
		$runtime = file_get_contents( $root . '/src/AdminColumns/Runtime.php' );
		$bootstrap = file_get_contents( $root . '/src/AdminColumns/Bootstrap.php' );
		self::assertIsString( $runtime );
		self::assertIsString( $bootstrap );

		self::assertStringContainsString( "'manage_' . \$screen_id . '_columns'", $runtime );
		self::assertStringContainsString( 'PHP_INT_MAX', $runtime );
		self::assertStringContainsString( "add_action( 'current_screen'", $bootstrap );
		foreach ( [
			"add_filter( 'pre_get_posts'",
			"add_action( 'pre_get_posts'",
			"add_filter( 'posts_clauses'",
			"add_filter( 'request'",
			"sortable_columns'",
			"bulk_actions-",
			"post_row_actions",
			"page_row_actions",
			'wp_enqueue_script',
			'wp_enqueue_style',
		] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $runtime . "\n" . $bootstrap );
		}
	}

	public function test_acg10_more_than_100_screens_is_rejected(): void {
		$screens = [];
		for ( $i = 0; $i < 101; $i++ ) {
			$screens[ 'edit-fixture-' . $i ] = [
				'order'  => [],
				'hidden' => [],
			];
		}

		$this->expectException( InvalidArgumentException::class );
		PolicyRepository::normalize( [
			'schema_version' => 1,
			'screens'        => $screens,
		] );
	}

	public function test_acg11_more_than_250_order_identities_is_rejected(): void {
		$order = [];
		for ( $i = 0; $i < 251; $i++ ) {
			$order[] = 'column_' . $i;
		}

		$this->expectException( InvalidArgumentException::class );
		PolicyRepository::normalize( [
			'schema_version' => 1,
			'screens' => [
				'edit-post' => [
					'order'  => $order,
					'hidden' => [],
				],
			],
		] );
	}

	public function test_acg12_more_than_250_hidden_identities_is_rejected(): void {
		$hidden = [];
		for ( $i = 0; $i < 251; $i++ ) {
			$hidden[] = 'column_' . $i;
		}

		$this->expectException( InvalidArgumentException::class );
		PolicyRepository::normalize( [
			'schema_version' => 1,
			'screens' => [
				'edit-post' => [
					'order'  => $hidden,
					'hidden' => $hidden,
				],
			],
		] );
	}

	public function test_acg13_invalid_utf8_column_identity_is_rejected_without_normalization(): void {
		$invalid_utf8 = "column_\xC3\x28";
		self::assertNotSame( $invalid_utf8, wp_check_invalid_utf8( $invalid_utf8 ) );

		$this->expectException( InvalidArgumentException::class );
		PolicyRepository::normalize( [
			'schema_version' => 1,
			'screens' => [
				'edit-post' => [
					'order'  => [ $invalid_utf8 ],
					'hidden' => [],
				],
			],
		] );
	}

	public function test_acg14_oversized_or_corrupt_persisted_policy_fails_open_to_exact_empty_policy(): void {
		$screens = [];
		for ( $i = 0; $i < 101; $i++ ) {
			$screens[ 'edit-fixture-' . $i ] = [
				'order'  => [],
				'hidden' => [],
			];
		}
		update_option( PolicyRepository::OPTION, [
			'schema_version' => 1,
			'screens'        => $screens,
		], false );
		self::assertSame( PolicyRepository::empty_policy(), PolicyRepository::get() );

		update_option( PolicyRepository::OPTION, [
			'schema_version' => 1,
			'screens' => [
				'edit-post' => [
					'order'  => [ "column_\xC3\x28" ],
					'hidden' => [],
				],
			],
		], false );
		self::assertSame( PolicyRepository::empty_policy(), PolicyRepository::get() );
	}

	private function store_policy_for_test( array $policy ): void {
		update_option( PolicyRepository::OPTION, PolicyRepository::normalize( $policy ), false );
	}

	private function set_post_list_screen( string $post_type ): WP_Screen {
		$GLOBALS['pagenow'] = 'edit.php';
		$GLOBALS['typenow'] = $post_type;
		set_current_screen( 'edit-' . $post_type );
		$screen = get_current_screen();
		self::assertInstanceOf( WP_Screen::class, $screen );
		Runtime::attach( $screen );
		return $screen;
	}
}
