<?php
declare(strict_types=1);

use CB\Core\AIGovernance\Settings as AISettings;
use CB\Core\ContentModels\Repository as ContentModelsRepository;
use CB\Core\Profiles\ApplyLock;
use CB\Core\Profiles\Diff;
use CB\Core\Profiles\Engine;
use CB\Core\Profiles\SectionInterface;
use CB\Core\Profiles\SectionRegistry;

final class CB_Profiles_Test_Mutable_Section implements SectionInterface {
	public function id(): string { return 'zz-test-mutable'; }
	public function label(): string { return 'Mutable fixture'; }
	public function description(): string { return 'Mutable transaction fixture.'; }
	public function schema_version(): int { return 1; }
	public function supports_schema_version( int $schema_version ): bool { return 1 === $schema_version; }
	public function migrate( array $incoming, int $source_schema_version ): array { if ( 1 !== $source_schema_version ) { throw new InvalidArgumentException(); } return $this->normalize( $incoming ); }
	public function export(): array { return [ 'value' => (string) get_option( 'cb_profiles_test_mutable', '' ) ]; }
	public function normalize( array $incoming ): array { return [ 'value' => sanitize_text_field( (string) ( $incoming['value'] ?? '' ) ) ]; }
	public function preflight( array $incoming, array $current ): void { unset( $incoming, $current ); }
	public function snapshot(): array { return $this->export(); }
	public function warnings( array $incoming ): array { unset( $incoming ); return []; }
	public function preview( array $current, array $incoming ): array { return Diff::between( $current, $incoming ); }
	public function apply( array $incoming, string $actor ): void { unset( $actor ); update_option( 'cb_profiles_test_mutable', $incoming['value'], false ); }
	public function restore( array $snapshot, array $incoming, string $actor ): void { unset( $incoming ); $this->apply( $snapshot, $actor ); }
	public function verify( array $incoming ): bool { return $this->export() === $incoming; }
}

final class CB_Profiles_Test_Failing_Section implements SectionInterface {
	public function id(): string { return 'zz-test-failing'; }
	public function label(): string { return 'Failing fixture'; }
	public function description(): string { return 'Failing transaction fixture.'; }
	public function schema_version(): int { return 1; }
	public function supports_schema_version( int $schema_version ): bool { return 1 === $schema_version; }
	public function migrate( array $incoming, int $source_schema_version ): array { if ( 1 !== $source_schema_version ) { throw new InvalidArgumentException(); } return $this->normalize( $incoming ); }
	public function export(): array { return [ 'value' => (string) get_option( 'cb_profiles_test_failing', 'stable' ) ]; }
	public function normalize( array $incoming ): array { return [ 'value' => sanitize_text_field( (string) ( $incoming['value'] ?? '' ) ) ]; }
	public function preflight( array $incoming, array $current ): void { unset( $incoming, $current ); }
	public function snapshot(): array { return $this->export(); }
	public function warnings( array $incoming ): array { unset( $incoming ); return []; }
	public function preview( array $current, array $incoming ): array { return Diff::between( $current, $incoming ); }
	public function apply( array $incoming, string $actor ): void { unset( $incoming, $actor ); update_option( 'cb_profiles_test_failing', 'partial', false ); throw new RuntimeException( 'Injected profile apply failure.' ); }
	public function restore( array $snapshot, array $incoming, string $actor ): void { unset( $incoming, $actor ); update_option( 'cb_profiles_test_failing', (string) $snapshot['value'], false ); }
	public function verify( array $incoming ): bool { unset( $incoming ); return false; }
}

final class CB_Profiles_Test_Large_Section implements SectionInterface {
	public function __construct( private string $section_id, private string $option ) {}
	public function id(): string { return $this->section_id; }
	public function label(): string { return 'Large fixture'; }
	public function description(): string { return 'Large diff fixture.'; }
	public function schema_version(): int { return 1; }
	public function supports_schema_version( int $schema_version ): bool { return 1 === $schema_version; }
	public function migrate( array $incoming, int $source_schema_version ): array { if ( 1 !== $source_schema_version ) { throw new InvalidArgumentException(); } return $this->normalize( $incoming ); }
	public function export(): array { $stored = get_option( $this->option, [] ); return is_array( $stored ) ? $stored : []; }
	public function normalize( array $incoming ): array { return $incoming; }
	public function preflight( array $incoming, array $current ): void { unset( $incoming, $current ); }
	public function snapshot(): array { return $this->export(); }
	public function warnings( array $incoming ): array { unset( $incoming ); return []; }
	public function preview( array $current, array $incoming ): array { return Diff::between( $current, $incoming ); }
	public function apply( array $incoming, string $actor ): void { unset( $actor ); update_option( $this->option, $incoming, false ); }
	public function restore( array $snapshot, array $incoming, string $actor ): void { unset( $incoming, $actor ); update_option( $this->option, $snapshot, false ); }
	public function verify( array $incoming ): bool { return $this->export() === $incoming; }
}

final class CB_Base_Profiles_Transaction_Contract_Test extends WP_UnitTestCase {
	private mixed $saved_ai_retention = '__cb_profiles_missing__';
	private mixed $saved_content_models = '__cb_profiles_missing__';
	private mixed $saved_rewrite_dirty = '__cb_profiles_missing__';
	public function set_up(): void {
		parent::set_up();
		$this->saved_ai_retention = get_option( AISettings::RETENTION_OPTION, '__cb_profiles_missing__' );
		$this->saved_content_models = get_option( 'cb_core_content_models_schema', '__cb_profiles_missing__' );
		$this->saved_rewrite_dirty = get_option( 'cb_core_content_models_rewrite_dirty', '__cb_profiles_missing__' );
		delete_option( 'cb_profiles_test_mutable' );
		delete_option( 'cb_profiles_test_failing' );
		delete_option( 'cb_profiles_test_large_a' );
		delete_option( 'cb_profiles_test_large_b' );
		ApplyLock::clear();
	}

	public function tear_down(): void {
		SectionRegistry::_set_for_testing( null );
		delete_option( 'cb_profiles_test_mutable' );
		delete_option( 'cb_profiles_test_failing' );
		delete_option( 'cb_profiles_test_large_a' );
		delete_option( 'cb_profiles_test_large_b' );
		ApplyLock::clear();
		if ( '__cb_profiles_missing__' === $this->saved_ai_retention ) {
			delete_option( AISettings::RETENTION_OPTION );
		} else {
			update_option( AISettings::RETENTION_OPTION, $this->saved_ai_retention, false );
		}
		if ( '__cb_profiles_missing__' === $this->saved_content_models ) {
			delete_option( 'cb_core_content_models_schema' );
		} else {
			update_option( 'cb_core_content_models_schema', $this->saved_content_models, false );
		}
		if ( '__cb_profiles_missing__' === $this->saved_rewrite_dirty ) {
			delete_option( 'cb_core_content_models_rewrite_dirty' );
		} else {
			update_option( 'cb_core_content_models_rewrite_dirty', $this->saved_rewrite_dirty, false );
		}
		parent::tear_down();
	}

	public function test_pt1_stale_preview_is_rejected_before_mutation(): void {
		$before = AISettings::retention_days();
		$document = Engine::export_document( 'AI policy', '', [ 'ai-governance' ] );
		$document['sections']['ai-governance']['data']['retention_days'] = min( AISettings::MAX_RETENTION_DAYS, $before + 7 );
		$preview = Engine::preview( $document );

		$intervening = min( AISettings::MAX_RETENTION_DAYS, $before + 3 );
		AISettings::update_retention_days( $intervening );
		try {
			Engine::apply( $document, $preview['fingerprint'], 'test' );
			self::fail( 'Stale Profile preview was applied.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'changed', strtolower( $error->getMessage() ) );
		}
		self::assertSame( $intervening, AISettings::retention_days() );
	}

	public function test_pt2_later_section_failure_rolls_back_every_applied_section(): void {
		SectionRegistry::_set_for_testing( [ new CB_Profiles_Test_Mutable_Section(), new CB_Profiles_Test_Failing_Section() ] );
		update_option( 'cb_profiles_test_mutable', 'before', false );
		update_option( 'cb_profiles_test_failing', 'stable', false );
		$document = [
			'format' => 'core-blueprint-profile',
			'format_version' => 1,
			'profile' => [ 'name' => 'Rollback fixture', 'description' => '' ],
			'source' => [ 'base_version' => CB_CORE_VERSION ],
			'exported_at' => gmdate( 'c' ),
			'sections' => [
				'zz-test-mutable' => [ 'schema_version' => 1, 'data' => [ 'value' => 'after' ] ],
				'zz-test-failing' => [ 'schema_version' => 1, 'data' => [ 'value' => 'explode' ] ],
			],
		];
		$preview = Engine::preview( $document );
		try {
			Engine::apply( $document, $preview['fingerprint'], 'test' );
			self::fail( 'Injected failure did not abort the Profile transaction.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Injected profile apply failure', $error->getMessage() );
		}
		self::assertSame( 'before', get_option( 'cb_profiles_test_mutable' ) );
		self::assertSame( 'stable', get_option( 'cb_profiles_test_failing' ), 'The failing section itself was not rolled back.' );
	}

	public function test_pt3_apply_lock_rejects_concurrent_owner_and_never_releases_foreign_lock(): void {
		$first = ApplyLock::acquire( 'first' );
		try {
			ApplyLock::acquire( 'second' );
			self::fail( 'Concurrent Profile apply lock was acquired.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'already running', $error->getMessage() );
		}
		ApplyLock::release( 'not-the-owner' );
		try {
			ApplyLock::acquire( 'third' );
			self::fail( 'Foreign release cleared the Profile apply lock.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'already running', $error->getMessage() );
		}
		ApplyLock::release( $first );
		$next = ApplyLock::acquire( 'next' );
		self::assertNotSame( '', $next );
		ApplyLock::release( $next );
	}

	public function test_pt4_total_diff_limit_fails_closed_across_sections(): void {
		$a = new CB_Profiles_Test_Large_Section( 'zz-test-large-a', 'cb_profiles_test_large_a' );
		$b = new CB_Profiles_Test_Large_Section( 'zz-test-large-b', 'cb_profiles_test_large_b' );
		SectionRegistry::_set_for_testing( [ $a, $b ] );
		$before = [];
		$after  = [];
		for ( $i = 0; $i < 600; $i++ ) {
			$before[ 'k' . $i ] = 'before';
			$after[ 'k' . $i ]  = 'after';
		}
		update_option( 'cb_profiles_test_large_a', $before, false );
		update_option( 'cb_profiles_test_large_b', $before, false );
		$document = [
			'format' => 'core-blueprint-profile',
			'format_version' => 1,
			'profile' => [ 'name' => 'Large diff', 'description' => '' ],
			'source' => [ 'base_version' => CB_CORE_VERSION ],
			'exported_at' => gmdate( 'c' ),
			'sections' => [
				'zz-test-large-a' => [ 'schema_version' => 1, 'data' => $after ],
				'zz-test-large-b' => [ 'schema_version' => 1, 'data' => $after ],
			],
		];
		$this->expectException( RuntimeException::class );
		Engine::preview( $document );
	}

	public function test_pt5_content_models_rollback_is_targeted_and_refuses_concurrent_touched_changes(): void {
		$before = $this->post_type_definition( 'cb_prof_fixture', 'Before fixture' );
		ContentModelsRepository::save_post_type( $before );
		$snapshot = ContentModelsRepository::all();

		$after = $this->post_type_definition( 'cb_prof_fixture', 'After fixture' );
		$created = $this->post_type_definition( 'cb_prof_new', 'Created by profile' );
		$target = [
			'content_models_schema_version' => ContentModelsRepository::SCHEMA_VERSION,
			'post_types' => [ 'cb_prof_fixture' => $after, 'cb_prof_new' => $created ],
			'taxonomies' => [],
			'option_pages' => [],
			'field_groups' => [],
		];
		ContentModelsRepository::merge_imported_schema( $target, true );

		$unrelated = $this->post_type_definition( 'cb_prof_other', 'Unrelated local change' );
		ContentModelsRepository::save_post_type( $unrelated );
		ContentModelsRepository::restore_profile_schema( $snapshot, $target );
		self::assertSame( $before, ContentModelsRepository::post_type( 'cb_prof_fixture' ) );
		self::assertNull( ContentModelsRepository::post_type( 'cb_prof_new' ) );
		self::assertSame( $unrelated, ContentModelsRepository::post_type( 'cb_prof_other' ) );

		ContentModelsRepository::merge_imported_schema( $target, true );
		$third = $this->post_type_definition( 'cb_prof_fixture', 'Concurrent third state' );
		ContentModelsRepository::save_post_type( $third );
		try {
			ContentModelsRepository::restore_profile_schema( $snapshot, $target );
			self::fail( 'Content Models rollback overwrote a concurrent touched-definition change.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'changed during Profile rollback', $error->getMessage() );
		}
		self::assertSame( $third, ContentModelsRepository::post_type( 'cb_prof_fixture' ) );
	}

	private function post_type_definition( string $key, string $label ): array {
		return ContentModelsRepository::normalize_post_type( [
			'key'            => $key,
			'singular_label' => $label,
			'plural_label'   => $label . 's',
			'description'    => '',
			'public'         => false,
			'show_in_rest'   => false,
			'has_archive'    => false,
			'hierarchical'   => false,
			'rewrite_slug'   => $key,
			'icon'           => 'dashicons-admin-post',
			'supports'       => [ 'title' ],
		] );
	}

}
