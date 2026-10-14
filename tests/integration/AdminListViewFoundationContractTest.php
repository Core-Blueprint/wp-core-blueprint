<?php
declare(strict_types=1);

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\UI\ListView;

final class CB_Admin_List_View_Foundation_Contract_Test extends WP_UnitTestCase {
	/** @return array<string,mixed> */
	private static function fixture( int $count = 2 ): array {
		$rows = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$rows[] = [
				'cells' => [
					'name' => [
						'text' => 'List ' . $i,
						'url' => admin_url( 'admin.php?page=fixture&list_id=' . $i ),
						'strong' => true,
					],
					'type' => [ 'text' => 'Static', 'tone' => 'info' ],
					'actions' => [ 'text' => '', 'actions' => [
						[ 'url' => admin_url( 'admin.php?page=fixture&list_id=' . $i ), 'label' => 'Edit' ],
					] ],
				],
			];
		}
		return [
			'title' => 'Lists',
			'description' => 'Manage your newsletter audiences',
			'primary_action' => [ 'url' => admin_url( 'admin.php?page=fixture&view=add' ), 'label' => 'Add list' ],
			'filters' => [
				'action' => admin_url( 'admin.php' ), 'hidden' => [ 'page' => 'fixture' ],
				'search' => [ 'name' => 's', 'label' => 'Search lists', 'value' => 'starters' ],
				'selects' => [
					[ 'name' => 'type', 'label' => 'Type', 'value' => 'static',
						'options' => [ '' => 'All types', 'static' => 'Static', 'dynamic' => 'Dynamic' ] ],
				],
				'submit_label' => 'Filter',
				'reset' => [ 'url' => admin_url( 'admin.php?page=fixture' ), 'label' => 'Reset' ],
			],
			'columns' => [
				[ 'id' => 'name', 'label' => 'Name',
					'sort' => [ 'url' => admin_url( 'admin.php?page=fixture&type=static&sort=title' ), 'direction' => 'ascending' ] ],
				[ 'id' => 'type', 'label' => 'Type' ],
				[ 'id' => 'actions', 'label' => 'Actions' ],
			],
			'rows' => $rows,
			'labels' => [ 'scroll' => 'Lists table, scroll horizontally if needed',
				'pagination' => 'Lists pages', 'previous' => 'Previous', 'next' => 'Next' ],
			'pagination' => [
				'page' => 2, 'pages' => 4, 'total' => 100,
				'summary' => '26–50 of 100 lists',
				'previous_url' => admin_url( 'admin.php?page=fixture&paged=1&type=static' ),
				'next_url' => admin_url( 'admin.php?page=fixture&paged=3&type=static' ),
			],
		];
	}

	public function test_semantic_registration_and_independent_asset_helper(): void {
		self::assertSame(
			[ 'foundations' => [ 'list-view' ], 'components' => [] ],
			PageRegistry::normalize_semantic_requirements(
				[ 'foundations' => [ 'list-view' ] ], 'list-view-fixture'
			)
		);
		ListView::enqueue();
		self::assertTrue( wp_style_is( 'cb-core-css-list-view', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'cb-core-css-filter-bar', 'enqueued' ) );
		$style = wp_styles()->registered['cb-core-css-list-view'] ?? null;
		self::assertInstanceOf( _WP_Dependency::class, $style );
		self::assertContains( 'cb-core-css-filter-bar', $style->deps );
	}

	public function test_render_composes_one_get_form_accessible_table_actions_and_paging(): void {
		$html = ListView::render( self::fixture() );
		self::assertSame( 1, substr_count( $html, '<form ' ) );
		self::assertStringContainsString( 'method="get"', $html );
		self::assertStringContainsString( 'role="search"', $html );
		self::assertStringContainsString( 'class="cb-core-toolbar cb-core-list-view__filters"', $html );
		self::assertStringContainsString( 'name="page" value="fixture"', $html );
		self::assertStringContainsString( 'type="search" name="s"', $html );
		self::assertStringContainsString( '<option value="static" selected=', $html );
		self::assertStringContainsString( '<caption class="screen-reader-text">Lists</caption>', $html );
		self::assertStringContainsString( 'scope="col" aria-sort="ascending"', $html );
		self::assertStringContainsString( '<th scope="row"><strong>', $html );
		self::assertStringContainsString( 'aria-label="Lists table, scroll horizontally if needed"', $html );
		self::assertStringContainsString( '26–50 of 100 lists', $html );
		self::assertStringContainsString( 'paged=3&amp;type=static', $html );
		self::assertStringNotContainsString( '<form', substr( $html, strpos( $html, '<table' ) ) );
	}

	public function test_externally_supplied_text_and_links_cannot_inject_markup(): void {
		$args = self::fixture( 1 );
		$args['rows'][0]['cells']['name']['text'] = '<script>alert("secret")</script>';
		$args['rows'][0]['cells']['name']['url'] = 'javascript:alert(1)';
		$args['rows'][0]['cells']['actions']['actions'][0]['label'] = '<img src=x onerror=alert(1)>';
		$args['description'] = '<svg onload=alert(1)>';
		$html = ListView::render( $args );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringNotContainsString( '<svg', $html );
		self::assertStringNotContainsString( 'href="javascript:', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
		self::assertStringContainsString( '&lt;svg onload=', $html );
	}

	public function test_empty_and_error_states_are_distinct_without_fabricated_loading(): void {
		$args = self::fixture( 0 );
		$args['empty_message'] = 'No matching lists.';
		$html = ListView::render( $args );
		self::assertStringContainsString( 'No matching lists.', $html );
		self::assertStringNotContainsString( '<table', $html );
		$args['error'] = true;
		$args['error_message'] = 'Lists could not be loaded.';
		$html = ListView::render( $args );
		self::assertStringContainsString( 'cb-core-list-view__state--error', $html );
		self::assertStringContainsString( 'Lists could not be loaded.', $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assertStringNotContainsString( 'Loading', $html );
	}

	public function test_bounded_two_fifty_and_two_hundred_rows(): void {
		foreach ( [ 2, 50, 200 ] as $count ) {
			$html = ListView::render( self::fixture( $count ) );
			self::assertSame( $count + 1, substr_count( $html, '<tr>' ) );
			self::assertSame( $count, substr_count( $html, '<th scope="row">' ) );
		}
	}

	public function test_untrusted_descriptors_fail_closed_instead_of_accepting_raw_html_or_unbounded_data(): void {
		$cases = [];
		$args = self::fixture();
		$args['columns'][] = [ 'id' => 'name', 'label' => 'Duplicate' ];
		$cases[] = $args;
		$args = self::fixture();
		$args['filters']['hidden']['bad[]'] = 'forged';
		$cases[] = $args;
		$args = self::fixture();
		$args['rows'][0]['cells']['type']['tone'] = 'evil" onclick="run';
		$cases[] = $args;
		$args = self::fixture();
		$args['rows'][0]['cells']['name'] = [ 'text' => 'Value', 'actions' => 'raw html' ];
		$cases[] = $args;
		$args = self::fixture();
		$args['pagination']['page'] = 999;
		$cases[] = $args;
		$args = self::fixture( 501 );
		$cases[] = $args;

		foreach ( $cases as $case ) {
			try {
				ListView::render( $case );
				self::fail( 'Invalid List View descriptor was accepted.' );
			} catch ( \InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
	}

	public function test_opt_in_styles_are_scoped_and_public_api_remains_passive(): void {
		$css = (string) file_get_contents( CB_CORE_DIR . 'assets/css/components/list-view.css' );
		$source = (string) file_get_contents( CB_CORE_DIR . 'src/UI/ListView.php' );
		self::assertStringContainsString( '.cb-core-list-view__table', $css );
		self::assertStringContainsString( '.cb-core-list-view__scroll:focus-visible', $css );
		self::assertStringContainsString( 'prefers-reduced-motion', $css );
		self::assertStringContainsString( 'max-width: 480px', $css );
		foreach ( [ 'update_option(', 'wp_insert_post(', 'wp_remote_', 'register_rest_route(', '$wpdb->', 'eval(', 'innerHTML' ] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $source );
		}
	}
}
