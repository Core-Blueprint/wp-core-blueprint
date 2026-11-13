<?php
declare(strict_types=1);

use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\TemplateLibrary\BrowserController;
use CoreBlueprint\Core\TemplateLibrary\TemplateMetadata;
use CoreBlueprint\Core\TemplateLibrary\TemplatePage;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;
use CoreBlueprint\Core\TemplateLibrary\TemplateSort;
use CoreBlueprint\Core\TemplateLibrary\TemplateSortedSourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

/** TL-1B1 global sort: real WP users, signed cursors and independent providers. */
final class CB_Template_Library_Global_Sort_Test extends WP_UnitTestCase {
	private int $admin = 0;
	private bool $many = false;
	private bool $unsupported = false;
	private string $failure = '';

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $this->admin );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'template_sort_test' ) );
		wp_set_current_user( $this->admin );
		TemplateSourceRegistry::_reset_for_testing();
		add_action( 'core_blueprint_register_template_sources', [ $this, 'register_sources' ] );
	}

	public function tear_down(): void {
		remove_action( 'core_blueprint_register_template_sources', [ $this, 'register_sources' ] );
		TemplateSourceRegistry::_reset_for_testing();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function register_sources(): void {
		TemplateSourceRegistry::register( $this->source(
			'core-blueprint-sort-a', [ 'Alpha', 'Charlie', 'Echo', 'Zulu' ], 0
		) );
		TemplateSourceRegistry::register( $this->source(
			'core-blueprint-sort-b', [ 'Bravo', 'Charlie', 'Delta', 'Zulu' ], 1
		) );
		if ( $this->unsupported ) {
			TemplateSourceRegistry::register( new class implements TemplateSourceInterface {
				public function source_id(): string { return 'core-blueprint-sort-legacy'; }
				public function extension_id(): string { return 'core-blueprint'; }
				public function designer_type(): string { return 'mail'; }
				public function can_browse( int $user_id ): bool { return user_can( $user_id, 'manage_options' ); }
				public function can_view( string $template_id, int $user_id ): bool { return $this->can_browse( $user_id ); }
				public function query( TemplateQuery $query, ?string $cursor ): TemplatePage {
					return new TemplatePage( [] );
				}
			} );
		}
	}

	private function source( string $id, array $titles, int $parity ): TemplateSortedSourceInterface {
		return new class( $id, $titles, $parity, $this->many, $this->failure )
			implements TemplateSortedSourceInterface {
			public function __construct(
				private string $name, private array $titles, private int $parity,
				private bool $many, private string $failure
			) {}
			public function source_id(): string { return $this->name; }
			public function extension_id(): string { return 'core-blueprint'; }
			public function designer_type(): string { return 'mail'; }
			public function can_browse( int $user_id ): bool {
				return $user_id > 0 && user_can( $user_id, 'manage_options' );
			}
			public function can_view( string $template_id, int $user_id ): bool {
				return $this->can_browse( $user_id ) && 's-9999' !== $template_id;
			}
			private function all(): array {
				$items = [];
				$count = $this->many ? 1001 : count( $this->titles );
				for ( $i = 0; $i < $count; ++$i ) {
					$title = $this->many
						? sprintf( 'Item %04d', 2 * $i + $this->parity )
						: $this->titles[ $i ];
					$items[] = new TemplateMetadata(
						$this->name, 'core-blueprint', 'mail',
						sprintf( 's-%04d', $i ),
						$title, 'Public metadata only', 'built-in', 'example', 'en', '1'
					);
				}
				return $items;
			}
			public function query( TemplateQuery $query, ?string $cursor ): TemplatePage {
				$offset = null === $cursor ? 0 : (int) $cursor;
				$all = $this->all();
				$page = array_slice( $all, $offset, $query->limit );
				$next = $offset + count( $page );
				return new TemplatePage( $page, $next < count( $all ) ? (string) $next : null );
			}
			public function query_sorted( TemplateQuery $query, ?array $after ): TemplatePage {
				if ( 'throw' === $this->failure ) throw new \RuntimeException( 'Provider failed.' );
				$all = array_values( array_filter( $this->all(),
					fn ( TemplateMetadata $item ): bool =>
						$this->can_view( $item->id, get_current_user_id() )
						&& ( null === $after || TemplateSort::after( $item, $after ) )
				) );
				usort( $all, [ TemplateSort::class, 'compare' ] );
				$page = array_slice( $all, 0, $query->limit );
				if ( 'unordered' === $this->failure && count( $page ) > 1 ) {
					$page = array_reverse( $page );
				}
				return new TemplatePage( $page,
					'private' === $this->failure ? 'unsupported-private-cursor' : null );
			}
		};
	}

	/** @return list<string> */
	private function collect( TemplateQuery $query ): array {
		$cursor = null;
		$all = [];
		$rounds = 0;
		do {
			$page = TemplateSourceRegistry::browse_global( $query, $cursor );
			self::assertLessThanOrEqual( $query->limit, count( $page->items ) );
			foreach ( $page->items as $item ) {
				$all[] = $item->title . '|' . $item->source . '|' . $item->id;
			}
			$cursor = $page->next_cursor;
			self::assertLessThan( 50, ++$rounds, 'Bounded continuation must terminate.' );
		} while ( null !== $cursor );
		return $all;
	}

	public function test_two_interleaved_providers_are_globally_sorted_across_page_boundaries(): void {
		$actual = $this->collect( new TemplateQuery( limit: 2, sort: TemplateSort::TITLE_ASC ) );
		self::assertSame( [
			'Alpha|core-blueprint-sort-a|s-0000',
			'Bravo|core-blueprint-sort-b|s-0000',
			'Charlie|core-blueprint-sort-a|s-0001',
			'Charlie|core-blueprint-sort-b|s-0001',
			'Delta|core-blueprint-sort-b|s-0002',
			'Echo|core-blueprint-sort-a|s-0002',
			'Zulu|core-blueprint-sort-a|s-0003',
			'Zulu|core-blueprint-sort-b|s-0003',
		], $actual );
	}

	public function test_1000_plus_per_provider_stays_bounded_and_never_repeats_a_tuple(): void {
		$this->many = true;
		$actual = $this->collect( new TemplateQuery( limit: 48, sort: TemplateSort::TITLE_ASC ) );
		self::assertCount( 2002, $actual );
		self::assertCount( 2002, array_unique( $actual ) );
		self::assertSame( 'Item 0000|core-blueprint-sort-a|s-0000', $actual[0] );
		self::assertSame( 'Item 0001|core-blueprint-sort-b|s-0000', $actual[1] );
		self::assertSame( 'Item 2001|core-blueprint-sort-b|s-1000', $actual[2001] );
	}

	private function assert_sorted_refused( callable $run, string $expected ): void {
		try {
			$run();
			self::fail( 'Invalid sorted page/cursor must fail closed.' );
		} catch ( \DomainException $error ) {
			self::assertSame( $expected, $error->getMessage() );
		}
	}

	public function test_cursor_integrity_actor_scope_and_query_identity(): void {
		$query = new TemplateQuery( limit: 2, sort: TemplateSort::TITLE_ASC );
		$page = TemplateSourceRegistry::browse_global( $query );
		self::assertNotNull( $page->next_cursor );
		$cursor = $page->next_cursor;
		$this->assert_sorted_refused( static fn () => TemplateSourceRegistry::browse_global(
			$query, 'X' . substr( $cursor, 1 )
		), 'invalid_cursor' );
		$this->assert_sorted_refused( static fn () => TemplateSourceRegistry::browse_global(
			new TemplateQuery( limit: 3, sort: TemplateSort::TITLE_ASC ), $cursor
		), 'invalid_cursor' );
		$this->assert_sorted_refused( static fn () => TemplateSourceRegistry::browse_global(
			new TemplateQuery( origin: 'personal', limit: 2, sort: TemplateSort::TITLE_ASC ), $cursor
		), 'invalid_cursor' );
		$this->assert_sorted_refused( static fn () => TemplateSourceRegistry::browse_designer(
			'core-blueprint', 'reports', $query, $cursor
		), 'invalid_cursor' );
		$other = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$actor = get_userdata( $other );
		self::assertInstanceOf( WP_User::class, $actor );
		self::assertTrue( PrivilegedAccessRegistry::approve( $actor, 0, 'template_sort_other_actor' ) );
		wp_set_current_user( $other );
		$this->assert_sorted_refused( static fn () => TemplateSourceRegistry::browse_global(
			$query, $cursor
		), 'invalid_cursor' );
	}

	public function test_unsupported_or_failing_source_never_returns_false_global_sort(): void {
		$query = new TemplateQuery( limit: 2, sort: TemplateSort::TITLE_ASC );
		$this->unsupported = true;
		$this->assert_sorted_refused( static fn () => TemplateSourceRegistry::browse_global(
			$query
		), 'sort_unavailable' );
		TemplateSourceRegistry::_reset_for_testing();
		$this->unsupported = false;
		$this->failure = 'unordered';
		$this->assert_sorted_refused( static fn () => TemplateSourceRegistry::browse_global(
			$query
		), 'sort_unavailable' );
		TemplateSourceRegistry::_reset_for_testing();
		$this->failure = 'private';
		$this->assert_sorted_refused( static fn () => TemplateSourceRegistry::browse_global(
			$query
		), 'sort_unavailable' );
		TemplateSourceRegistry::_reset_for_testing();
		$this->failure = 'throw';
		$this->assert_sorted_refused( static fn () => TemplateSourceRegistry::browse_global(
			$query
		), 'sort_unavailable' );
	}

	public function test_invalid_sort_and_rest_error_are_explicit(): void {
		try {
			new TemplateQuery( sort: 'title-natural-asc' );
			self::fail( 'Unsupported sort must be rejected.' );
		} catch ( \InvalidArgumentException ) {
			self::assertTrue( true );
		}
		$this->unsupported = true;
		$request = new WP_REST_Request( 'GET', '/core-blueprint/v1/template-library/templates' );
		$request->set_param( 'sort', TemplateSort::TITLE_ASC );
		$response = BrowserController::templates( $request );
		self::assertSame( 422, $response->get_status() );
		self::assertSame( 'sort_unavailable', $response->get_data()['code'] );
		$request->set_param( 'sort', 'unsupported' );
		$response = BrowserController::templates( $request );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'invalid_query', $response->get_data()['code'] );
	}

	public function test_unauthorized_user_cannot_obtain_sorted_provider_metadata(): void {
		$query = new TemplateQuery( limit: 2, sort: TemplateSort::TITLE_ASC );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( [], TemplateSourceRegistry::browse_global( $query )->items );
	}
}
