<?php
declare(strict_types=1);

use CoreBlueprint\Core\TemplateLibrary\TemplateMetadata;
use CoreBlueprint\Core\TemplateLibrary\TemplatePage;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

final class CB_Template_Library_Registry_Contract_Test extends WP_UnitTestCase {
	private int $operator = 0;

	public function set_up(): void {
		parent::set_up();
		$this->operator = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->operator );
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
		TemplateSourceRegistry::register( $this->source( 'core-blueprint-fixture-workflow', 'workflow', 1001 ) );
		TemplateSourceRegistry::register( $this->source( 'core-blueprint-fixture-mail', 'mail', 2 ) );
	}

	private function source( string $id, string $designer, int $count, string $extension = 'core-blueprint' ): TemplateSourceInterface {
		return new class( $id, $designer, $count, $extension ) implements TemplateSourceInterface {
			public function __construct(
				private string $id,
				private string $designer,
				private int $count,
				private string $extension
			) {}
			public function source_id(): string { return $this->id; }
			public function extension_id(): string { return $this->extension; }
			public function designer_type(): string { return $this->designer; }
			public function can_browse( int $user_id ): bool {
				return $user_id > 0 && user_can( $user_id, 'manage_options' );
			}
			public function can_view( string $template_id, int $user_id ): bool {
				return $this->can_browse( $user_id ) && 'example-0001' !== $template_id;
			}
			public function query( TemplateQuery $query, ?string $cursor ): TemplatePage {
				$offset = null === $cursor ? 0 : (int) $cursor;
				$end = min( $this->count, $offset + $query->limit );
				$items = [];
				for ( $i = $offset; $i < $end; ++$i ) {
					$items[] = new TemplateMetadata(
						$this->id, $this->extension, $this->designer,
						sprintf( 'example-%04d', $i + 1 ),
						'Example ' . ( $i + 1 ), 'A safe metadata-only template',
						'built-in', 'example', 'en', '1'
					);
				}
				return new TemplatePage( $items, $end < $this->count ? (string) $end : null );
			}
		};
	}

	public function test_registration_requires_exact_lifecycle_and_known_extension(): void {
		self::assertFalse( TemplateSourceRegistry::register( $this->source( 'out-of-lifecycle', 'workflow', 1 ) ) );
		$accepted = null;
		$unknown = null;
		$duplicate = function () use ( &$accepted, &$unknown ): void {
			$unknown = TemplateSourceRegistry::register( $this->source( 'unknown-owner', 'workflow', 1, 'missing-extension' ) );
			$accepted = TemplateSourceRegistry::register( $this->source( 'core-blueprint-fixture-workflow', 'workflow', 1 ) );
			self::assertFalse( TemplateSourceRegistry::register( $this->source( 'core-blueprint-fixture-mail', 'mail', 1 ) ) );
		};
		add_action( 'core_blueprint_register_template_sources', $duplicate, 20 );
		TemplateSourceRegistry::collect();
		remove_action( 'core_blueprint_register_template_sources', $duplicate, 20 );
		self::assertFalse( $unknown, 'Unregistered extension cannot own a source.' );
		self::assertFalse( $accepted, 'Duplicate source must fail closed.' );
		self::assertCount( 2, TemplateSourceRegistry::sources() );
	}

	public function test_global_and_locked_designer_scope_are_distinct(): void {
		$global = TemplateSourceRegistry::browse_global( new TemplateQuery( limit: 24 ) );
		self::assertNotEmpty( $global->items );
		self::assertSame( 'mail', $global->items[0]->designer );
		self::assertCount( 1, TemplateSourceRegistry::browse_designer( 'core-blueprint', 'mail', new TemplateQuery() )->items );
		self::assertSame( [], TemplateSourceRegistry::browse_designer( 'core-blueprint', 'unknown', new TemplateQuery() )->items );
		$workflow = TemplateSourceRegistry::browse_designer( 'core-blueprint', 'workflow', new TemplateQuery() );
		self::assertNotEmpty( $workflow->items );
		foreach ( $workflow->items as $item ) {
			self::assertSame( 'workflow', $item->designer );
		}
		self::assertSame( [], TemplateSourceRegistry::browse_designer(
			'core-blueprint', 'workflow', new TemplateQuery( designer: 'mail' )
		)->items );
		self::assertSame( [], TemplateSourceRegistry::browse_designer(
			'core-blueprint', 'workflow', new TemplateQuery( extension: 'other-extension' )
		)->items );
	}

	public function test_server_side_user_item_and_cursor_isolation(): void {
		$query = new TemplateQuery( limit: 24 );
		$page = TemplateSourceRegistry::browse_designer( 'core-blueprint', 'workflow', $query );
		self::assertCount( 23, $page->items, 'Per-item access hides denied items.' );
		self::assertNotNull( $page->next_cursor );
		self::assertSame( [], TemplateSourceRegistry::browse_designer(
			'core-blueprint', 'mail', $query, $page->next_cursor
		)->items );
		self::assertSame( [], TemplateSourceRegistry::browse_designer(
			'core-blueprint', 'workflow', new TemplateQuery( limit: 12 ), $page->next_cursor
		)->items );
		$tampered = 'X' . substr( $page->next_cursor, 1 );
		self::assertSame( [], TemplateSourceRegistry::browse_designer(
			'core-blueprint', 'workflow', $query, $tampered
		)->items );
		$other_user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $other_user );
		self::assertSame( [], TemplateSourceRegistry::browse_designer(
			'core-blueprint', 'workflow', $query, $page->next_cursor
		)->items );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( [], TemplateSourceRegistry::browse_global( $query )->items );
		self::assertSame( [], TemplateSourceRegistry::sources() );
	}

	public function test_one_thousand_plus_templates_are_paginated_not_loaded_in_one_response(): void {
		$query = new TemplateQuery( limit: 48 );
		$cursor = null;
		$total = 0;
		$iterations = 0;
		do {
			$page = TemplateSourceRegistry::browse_designer( 'core-blueprint', 'workflow', $query, $cursor );
			self::assertLessThanOrEqual( 48, count( $page->items ) );
			$total += count( $page->items );
			$cursor = $page->next_cursor;
			++$iterations;
			self::assertLessThan( 30, $iterations, 'Pagination must terminate.' );
		} while ( null !== $cursor );
		self::assertSame( 1000, $total );
	}

	public function test_invalid_query_and_payload_boundaries_fail_closed(): void {
		$this->expectException( \InvalidArgumentException::class );
		new TemplateQuery( limit: 49 );
	}
}
