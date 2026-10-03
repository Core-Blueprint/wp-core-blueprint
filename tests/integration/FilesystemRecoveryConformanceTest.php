<?php
declare(strict_types=1);

use CoreBlueprint\Core\Integrity\Quarantine\Repository as QuarantineRepository;
use CoreBlueprint\Core\Integrity\Quarantine\Service as QuarantineService;
use CoreBlueprint\Core\Integrity\Quarantine\Vault;
use CoreBlueprint\Core\Integrity\Storage\ChunkedOptionStore;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\Integrity\Storage\StorageSchema;
use CoreBlueprint\Core\Integrity\Support\DirectoryHasher;
use CoreBlueprint\Core\Integrity\Support\Finding;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\PackageDownload\AdminIntegration as PackageDownloads;
use CoreBlueprint\Core\PackageDownload\ArchiveService;
use CoreBlueprint\Core\PackageDownload\State as PackageDownloadState;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Permissions\Roles;

final class CB_C2A_Test_Termination extends RuntimeException {
    /** @var mixed */
    public $wp_args;

    public function __construct( $args ) {
        parent::__construct( '__CB_C2A_TEST_TERMINATION__' );
        $this->wp_args = $args;
    }
}

final class CB_Base_Filesystem_Recovery_Conformance_Test extends WP_UnitTestCase {
    /** @var string[] */
    private array $cleanup_paths = [];

    /** @var callable|null */
    private $vault_filter = null;

    private string $vault_parent = '';
    private string $uploads_root = '';
    private string $upload_fixture_root = '';
    private string $upload_fixture_relative = '';
    private bool $package_download_was_enabled = true;

    public function set_up(): void {
        parent::set_up();

        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        Roles::ensure_operator_role();

        $this->package_download_was_enabled = PackageDownloadState::is_enabled();
        if ( ! $this->package_download_was_enabled ) {
            PackageDownloadState::set_enabled( true, 'c2a-test-setup' );
        }

        ResultRepository::clear();
        ResultRepository::clearBaseline();
        ChunkedOptionStore::delete( 'cb_core_quarantine_workspace' );

        $uploads = wp_get_upload_dir();
        $basedir = wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) );
        self::assertNotSame( '', $basedir, 'C2-A requires a WordPress uploads directory.' );
        if ( ! is_dir( $basedir ) ) {
            self::assertTrue( wp_mkdir_p( $basedir ), 'Could not create the WordPress uploads directory for C2-A fixtures.' );
        }
        $resolved_uploads = realpath( $basedir );
        self::assertIsString( $resolved_uploads, 'Could not resolve the WordPress uploads directory for C2-A fixtures.' );
        $this->uploads_root = rtrim( wp_normalize_path( (string) $resolved_uploads ), '/' );

        $this->upload_fixture_relative = 'cb-c2a-' . bin2hex( random_bytes( 5 ) );
        $this->upload_fixture_root = $this->uploads_root . '/' . $this->upload_fixture_relative;
        self::assertTrue( mkdir( $this->upload_fixture_root, 0700, true ), 'Could not create C2-A uploads fixture root.' );
        $this->cleanup_paths[] = $this->upload_fixture_root;

        $this->vault_parent = $this->make_temp_dir( 'vault-parent' );
        $this->vault_filter = function ( $parent, $site_root, $document_root ) {
            unset( $parent, $site_root, $document_root );
            return $this->vault_parent;
        };
        add_filter( 'core_blueprint_quarantine_vault_parent', $this->vault_filter, PHP_INT_MAX, 3 );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        if ( null !== $this->vault_filter ) {
            remove_filter( 'core_blueprint_quarantine_vault_parent', $this->vault_filter, PHP_INT_MAX );
        }

        ChunkedOptionStore::delete( 'cb_core_quarantine_workspace' );
        ResultRepository::clear();
        ResultRepository::clearBaseline();

        if ( PackageDownloadState::is_enabled() !== $this->package_download_was_enabled ) {
            PackageDownloadState::set_enabled( $this->package_download_was_enabled, 'c2a-test-cleanup' );
        }

        foreach ( array_reverse( $this->cleanup_paths ) as $path ) {
            $this->remove_tree( $path );
        }
        $this->cleanup_paths = [];

        parent::tear_down();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_c2a_package_handler_rejects_manipulated_and_stale_inventory_without_success_audit(): void {
        self::assertFalse( headers_sent(), 'Package handler contract requires a headers-clean isolated process.' );

        $admin = $this->approved_administrator();
        wp_set_current_user( (int) $admin->ID );
        self::assertTrue( current_user_can( 'install_plugins' ), 'Approved Administrator fixture did not retain install_plugins.' );

        $cases = [
            '../c2a-traversal.php'          => 'c2a-traversal',
            'c2a-missing/c2a-missing.php' => 'c2a-missing',
        ];

        foreach ( $cases as $package => $temp_needle ) {
            self::assertSame( [], $this->temp_entries_containing( $temp_needle ), 'Unexpected pre-existing C2-A package temp fixture.' );
            $success_audit = $this->audit_count( 'package.plugin_downloaded' );

            $_GET = [
                'type'     => 'plugin',
                'package'  => $package,
                '_wpnonce' => wp_create_nonce( 'cb_core_download_package:plugin:' . $package ),
            ];
            $_REQUEST = $_GET;

            $termination = $this->capture_wp_die( static fn() => PackageDownloads::handle_download() );
            $args = is_array( $termination->wp_args ) ? $termination->wp_args : [];
            self::assertSame( 500, (int) ( $args['response'] ?? 0 ), 'Unknown/manipulated inventory did not fail closed.' );
            self::assertSame( $success_audit, $this->audit_count( 'package.plugin_downloaded' ), 'Denied package request emitted a success audit.' );
            self::assertSame( [], $this->temp_entries_containing( $temp_needle ), 'Denied inventory request allocated an unexpected package archive.' );
        }
    }

    public function test_c2a_archive_service_rejects_outside_root_symlink_escape_and_invalid_segments(): void {
        $service = new ArchiveService();
        $allowed = $this->make_temp_dir( 'package-allowed' );
        $outside = $this->make_temp_dir( 'package-outside' );
        $outside_file = $outside . '/outside.php';
        self::assertNotFalse( file_put_contents( $outside_file, "<?php echo 'outside';\n" ) );

        $this->assert_runtime_exception(
            static fn() => $service->create_from_file( $outside_file, $allowed, 'outside' ),
            'ArchiveService accepted a source outside its allowed root.'
        );
        self::assertFileExists( $outside_file );

        $escape = $allowed . '/escape';
        $this->assert_symlink_created( $outside, $escape );
        $this->assert_runtime_exception(
            static fn() => $service->create_from_directory( $escape, $allowed, 'escape' ),
            'ArchiveService followed a package-root symlink outside its allowed root.'
        );
        self::assertTrue( is_link( $escape ) );
        self::assertFileExists( $outside_file );

        $valid_file = $allowed . '/valid.php';
        self::assertNotFalse( file_put_contents( $valid_file, "<?php echo 'valid';\n" ) );
        foreach ( [ '..', 'bad/slug', 'bad\\slug' ] as $segment ) {
            $this->assert_runtime_exception(
                static fn() => $service->create_from_file( $valid_file, $allowed, $segment ),
                'ArchiveService accepted an unsafe archive segment: ' . $segment
            );
        }
    }

    public function test_c2a_archive_service_skips_nested_symlinks_and_builds_single_file_archive_in_wp_temp_storage(): void {
        $service = new ArchiveService();
        $allowed = $this->make_temp_dir( 'archive-root' );
        $package = $allowed . '/c2a-package';
        self::assertTrue( mkdir( $package, 0700, true ) );
        self::assertNotFalse( file_put_contents( $package . '/normal.txt', 'normal' ) );

        $outside = $this->make_temp_dir( 'archive-secret' );
        $secret = $outside . '/secret.txt';
        self::assertNotFalse( file_put_contents( $secret, 'do-not-archive' ) );
        $this->assert_symlink_created( $secret, $package . '/leak.txt' );

        $directory_archive = $service->create_from_directory( $package, $allowed, 'c2a-package' );
        $this->cleanup_paths[] = $directory_archive;
        self::assertFileExists( $directory_archive );
        self::assertTrue( $this->path_is_within( (string) realpath( $directory_archive ), (string) realpath( get_temp_dir() ) ), 'Directory archive was created outside WordPress temp storage.' );

        $entries = $this->archive_entries( $directory_archive );
        self::assertContains( 'c2a-package/normal.txt', $entries );
        self::assertNotContains( 'c2a-package/leak.txt', $entries, 'Nested symlink was included in the archive.' );

        $single_file = $allowed . '/single-plugin.php';
        self::assertNotFalse( file_put_contents( $single_file, "<?php\n/* Plugin Name: C2-A Single */\n" ) );
        $single_archive = $service->create_from_file( $single_file, $allowed, 'c2a-single-plugin' );
        $this->cleanup_paths[] = $single_archive;
        self::assertFileExists( $single_archive );
        self::assertTrue( $this->path_is_within( (string) realpath( $single_archive ), (string) realpath( get_temp_dir() ) ), 'Single-file plugin archive was created outside WordPress temp storage.' );
        self::assertContains( 'c2a-single-plugin/single-plugin.php', $this->archive_entries( $single_archive ) );
    }

    public function test_c2a_file_quarantine_moves_current_evidence_and_restores_only_persisted_destination(): void {
        $relative = $this->upload_fixture_relative . '/file-happy.txt';
        $source = $this->make_upload_file( $relative, 'trusted-evidence' );
        $finding = $this->seed_upload_finding( $source, $relative );
        $quarantine_audit = $this->audit_count( 'integrity_quarantine_item_quarantined' );

        $item = QuarantineService::quarantine( (string) $finding['id'], 'file' );
        $id = (string) $item['id'];
        self::assertNotSame( '', $id );
        self::assertFileDoesNotExist( $source );
        self::assertNotNull( QuarantineRepository::get( $id ) );
        self::assertFileExists( Vault::payload_path( $id, 'file' ) );
        self::assertSame( $quarantine_audit + 1, $this->audit_count( 'integrity_quarantine_item_quarantined' ) );

        $caller_destination_root = $this->make_temp_dir( 'caller-destination' );
        $caller_destination = $caller_destination_root . '/caller-selected.txt';
        $_REQUEST['destination'] = $caller_destination;
        $restore_audit = $this->audit_count( 'integrity_quarantine_item_restored' );

        $restored = QuarantineService::restore( $id );
        self::assertSame( 'restored', (string) $restored['status'] );
        self::assertFileExists( $source );
        self::assertSame( 'trusted-evidence', (string) file_get_contents( $source ) );
        self::assertFileDoesNotExist( $caller_destination, 'Caller-controlled destination data influenced restore.' );
        self::assertSame( $restore_audit + 1, $this->audit_count( 'integrity_quarantine_item_restored' ) );
    }

    public function test_c2a_quarantine_denies_changed_sha_and_source_symlink_before_mutation(): void {
        $changed_relative = $this->upload_fixture_relative . '/changed.txt';
        $changed_source = $this->make_upload_file( $changed_relative, 'scanned-content' );
        $changed_finding = $this->seed_upload_finding( $changed_source, $changed_relative );
        self::assertNotFalse( file_put_contents( $changed_source, 'changed-after-scan' ) );
        $success_audit = $this->audit_count( 'integrity_quarantine_item_quarantined' );

        $this->assert_runtime_exception(
            static fn() => QuarantineService::quarantine( (string) $changed_finding['id'], 'file' ),
            'Quarantine accepted a file whose SHA changed after the scan.'
        );
        self::assertFileExists( $changed_source );
        self::assertSame( 'changed-after-scan', (string) file_get_contents( $changed_source ) );
        self::assertSame( [], QuarantineRepository::items() );
        self::assertSame( $success_audit, $this->audit_count( 'integrity_quarantine_item_quarantined' ) );

        $target_relative = $this->upload_fixture_relative . '/symlink-target.txt';
        $target = $this->make_upload_file( $target_relative, 'symlink-target' );
        $link_relative = $this->upload_fixture_relative . '/symlink-source.txt';
        $link = $this->uploads_root . '/' . $link_relative;
        $this->assert_symlink_created( $target, $link );
        $hash = hash_file( 'sha256', $target );
        self::assertIsString( $hash );
        $symlink_finding = $this->seed_upload_finding( $link, $link_relative, (string) $hash );

        $this->assert_runtime_exception(
            static fn() => QuarantineService::quarantine( (string) $symlink_finding['id'], 'file' ),
            'Quarantine accepted a symlink source.'
        );
        self::assertTrue( is_link( $link ) );
        self::assertFileExists( $target );
        self::assertSame( 'symlink-target', (string) file_get_contents( $target ) );
        self::assertSame( [], QuarantineRepository::items() );
    }

    public function test_c2a_directory_quarantine_supports_happy_path_and_denies_nested_symlink_without_partial_move(): void {
        $directory = $this->make_top_level_upload_dir( 'c2a-dir-happy' );
        $directory_name = wp_basename( $directory );
        $evidence = $directory . '/evidence.txt';
        $sibling = $directory . '/sibling.txt';
        self::assertNotFalse( file_put_contents( $evidence, 'directory-evidence' ) );
        self::assertNotFalse( file_put_contents( $sibling, 'directory-sibling' ) );
        $finding = $this->seed_upload_finding( $evidence, $directory_name . '/evidence.txt' );

        $item = QuarantineService::quarantine( (string) $finding['id'], 'directory' );
        $id = (string) $item['id'];
        self::assertSame( 'directory', (string) $item['kind'] );
        self::assertDirectoryDoesNotExist( $directory );
        self::assertDirectoryExists( Vault::payload_path( $id, 'directory' ) );

        QuarantineService::restore( $id );
        self::assertDirectoryExists( $directory );
        self::assertSame( 'directory-evidence', (string) file_get_contents( $evidence ) );
        self::assertSame( 'directory-sibling', (string) file_get_contents( $sibling ) );

        ChunkedOptionStore::delete( 'cb_core_quarantine_workspace' );
        ResultRepository::clear();

        $unsafe = $this->make_top_level_upload_dir( 'c2a-dir-symlink' );
        $unsafe_name = wp_basename( $unsafe );
        $unsafe_evidence = $unsafe . '/evidence.txt';
        self::assertNotFalse( file_put_contents( $unsafe_evidence, 'unsafe-evidence' ) );
        $outside = $this->make_temp_dir( 'directory-symlink-target' );
        $outside_file = $outside . '/outside.txt';
        self::assertNotFalse( file_put_contents( $outside_file, 'outside' ) );
        $this->assert_symlink_created( $outside_file, $unsafe . '/nested-link.txt' );
        $unsafe_finding = $this->seed_upload_finding( $unsafe_evidence, $unsafe_name . '/evidence.txt' );
        $success_audit = $this->audit_count( 'integrity_quarantine_item_quarantined' );

        $this->assert_runtime_exception(
            static fn() => QuarantineService::quarantine( (string) $unsafe_finding['id'], 'directory' ),
            'Directory quarantine accepted nested symlink content.'
        );
        self::assertDirectoryExists( $unsafe );
        self::assertFileExists( $unsafe_evidence );
        self::assertTrue( is_link( $unsafe . '/nested-link.txt' ) );
        self::assertFileExists( $outside_file );
        self::assertSame( [], QuarantineRepository::items() );
        self::assertSame( $success_audit, $this->audit_count( 'integrity_quarantine_item_quarantined' ) );
    }

    public function test_c2a_restore_denies_collision_and_ancestor_symlink_without_overwrite_or_escape(): void {
        $collision_relative = $this->upload_fixture_relative . '/collision.txt';
        $collision_source = $this->make_upload_file( $collision_relative, 'original-collision' );
        $collision_finding = $this->seed_upload_finding( $collision_source, $collision_relative );
        $collision_item = QuarantineService::quarantine( (string) $collision_finding['id'], 'file' );
        $collision_id = (string) $collision_item['id'];
        $collision_payload = Vault::payload_path( $collision_id, 'file' );
        self::assertNotFalse( file_put_contents( $collision_source, 'new-owner-content' ) );
        $restore_audit = $this->audit_count( 'integrity_quarantine_item_restored' );

        $this->assert_runtime_exception(
            static fn() => QuarantineService::restore( $collision_id ),
            'Restore overwrote an occupied original destination.'
        );
        self::assertSame( 'new-owner-content', (string) file_get_contents( $collision_source ) );
        self::assertFileExists( $collision_payload );
        self::assertSame( $restore_audit, $this->audit_count( 'integrity_quarantine_item_restored' ) );

        $ancestor_relative = $this->upload_fixture_relative . '/ancestor/file.txt';
        $ancestor_source = $this->make_upload_file( $ancestor_relative, 'ancestor-original' );
        $ancestor_finding = $this->seed_upload_finding( $ancestor_source, $ancestor_relative );
        $ancestor_item = QuarantineService::quarantine( (string) $ancestor_finding['id'], 'file' );
        $ancestor_id = (string) $ancestor_item['id'];
        $ancestor_payload = Vault::payload_path( $ancestor_id, 'file' );
        $ancestor_parent = dirname( $ancestor_source );
        self::assertTrue( rmdir( $ancestor_parent ), 'Could not remove empty restore parent for ancestor-symlink fixture.' );
        $outside = $this->make_temp_dir( 'restore-escape' );
        $this->assert_symlink_created( $outside, $ancestor_parent );

        $this->assert_runtime_exception(
            static fn() => QuarantineService::restore( $ancestor_id ),
            'Restore followed an ancestor symlink outside uploads.'
        );
        self::assertTrue( is_link( $ancestor_parent ) );
        self::assertFileDoesNotExist( $outside . '/file.txt' );
        self::assertFileExists( $ancestor_payload );
    }

    public function test_c2a_restore_denies_corrupt_and_missing_payloads_before_filesystem_restore(): void {
        $corrupt_relative = $this->upload_fixture_relative . '/corrupt.txt';
        $corrupt_source = $this->make_upload_file( $corrupt_relative, 'corrupt-original' );
        $corrupt_finding = $this->seed_upload_finding( $corrupt_source, $corrupt_relative );
        $corrupt_item = QuarantineService::quarantine( (string) $corrupt_finding['id'], 'file' );
        $corrupt_id = (string) $corrupt_item['id'];
        $corrupt_payload = Vault::payload_path( $corrupt_id, 'file' );
        self::assertNotFalse( file_put_contents( $corrupt_payload, 'tampered-payload' ) );
        $restore_audit = $this->audit_count( 'integrity_quarantine_item_restored' );

        $this->assert_runtime_exception(
            static fn() => QuarantineService::restore( $corrupt_id ),
            'Restore accepted a payload whose manifest no longer matched.'
        );
        self::assertFileDoesNotExist( $corrupt_source );
        self::assertFileExists( $corrupt_payload );
        self::assertSame( 'tampered-payload', (string) file_get_contents( $corrupt_payload ) );
        self::assertSame( $restore_audit, $this->audit_count( 'integrity_quarantine_item_restored' ) );

        $missing_relative = $this->upload_fixture_relative . '/missing-payload.txt';
        $missing_source = $this->make_upload_file( $missing_relative, 'missing-original' );
        $missing_finding = $this->seed_upload_finding( $missing_source, $missing_relative );
        $missing_item = QuarantineService::quarantine( (string) $missing_finding['id'], 'file' );
        $missing_id = (string) $missing_item['id'];
        $missing_payload = Vault::payload_path( $missing_id, 'file' );
        self::assertTrue( unlink( $missing_payload ) );

        $this->assert_runtime_exception(
            static fn() => QuarantineService::restore( $missing_id ),
            'Restore accepted a missing quarantine payload.'
        );
        self::assertFileDoesNotExist( $missing_source );
        self::assertFileDoesNotExist( $missing_payload );
    }

    public function test_c2a_permanent_delete_is_explicit_and_rejects_already_restored_items(): void {
        $relative = $this->upload_fixture_relative . '/delete.txt';
        $source = $this->make_upload_file( $relative, 'delete-me' );
        $finding = $this->seed_upload_finding( $source, $relative );
        $item = QuarantineService::quarantine( (string) $finding['id'], 'file' );
        $id = (string) $item['id'];
        $payload = Vault::payload_path( $id, 'file' );
        $delete_audit = $this->audit_count( 'integrity_quarantine_item_deleted' );

        $deleted = QuarantineService::delete_permanently( $id );
        self::assertSame( 'deleted', (string) $deleted['status'] );
        self::assertFileDoesNotExist( $payload );
        self::assertSame( $delete_audit + 1, $this->audit_count( 'integrity_quarantine_item_deleted' ) );
        self::assertSame( 'deleted', (string) ( QuarantineRepository::get( $id )['status'] ?? '' ) );

        $restored_relative = $this->upload_fixture_relative . '/restored-not-deletable.txt';
        $restored_source = $this->make_upload_file( $restored_relative, 'restore-first' );
        $restored_finding = $this->seed_upload_finding( $restored_source, $restored_relative );
        $restored_item = QuarantineService::quarantine( (string) $restored_finding['id'], 'file' );
        $restored_id = (string) $restored_item['id'];
        QuarantineService::restore( $restored_id );

        $this->assert_runtime_exception(
            static fn() => QuarantineService::delete_permanently( $restored_id ),
            'Permanent delete accepted an already-restored item.'
        );
        self::assertFileExists( $restored_source );
        self::assertSame( 'restore-first', (string) file_get_contents( $restored_source ) );
        self::assertSame( 'restored', (string) ( QuarantineRepository::get( $restored_id )['status'] ?? '' ) );
    }

    public function test_c2a_baseline_bulk_update_is_all_or_nothing_and_component_removal_remains_explicit(): void {
        $existing_slug = 'c2a-existing-' . bin2hex( random_bytes( 3 ) );
        $valid_slug = 'c2a-valid-' . bin2hex( random_bytes( 3 ) );
        $rejected_slug = 'c2a-rejected-' . bin2hex( random_bytes( 3 ) );

        $existing = $this->baseline_candidate( $existing_slug, 'existing', true );
        $seed = ResultRepository::saveBaseline( [ 'checks' => [ $existing ] ] );
        self::assertTrue( ! empty( $seed['_baseline_saved'] ), 'Could not seed existing baseline fixture.' );
        $before = ResultRepository::getBaseline();
        self::assertIsArray( $before );
        self::assertTrue( ResultRepository::hasBaselineComponent( 'plugin', $existing_slug ) );

        $valid = $this->baseline_candidate( $valid_slug, 'valid', true );
        $rejected = $this->baseline_candidate( $rejected_slug, 'rejected', false );
        $mixed = ResultRepository::saveBaseline( [ 'checks' => [ $valid, $rejected ] ] );
        self::assertFalse( (bool) ( $mixed['_baseline_saved'] ?? true ), 'Mixed valid/invalid baseline set was partially accepted.' );
        self::assertSame( serialize( $before ), serialize( ResultRepository::getBaseline() ), 'Rejected bulk baseline attempt changed persisted baseline state.' );
        self::assertFalse( ResultRepository::hasBaselineComponent( 'plugin', $valid_slug ), 'Valid candidate from rejected set was partially persisted.' );
        self::assertFalse( ResultRepository::hasBaselineComponent( 'plugin', $rejected_slug ), 'Rejected candidate was persisted.' );

        $positive = ResultRepository::saveBaseline( [ 'checks' => [ $existing, $valid ] ] );
        self::assertTrue( ! empty( $positive['_baseline_saved'] ), 'Fully valid baseline set did not persist.' );
        $stored = ResultRepository::getBaseline();
        self::assertIsArray( $stored );
        self::assertSame( 2, (int) ( $stored['entry_count'] ?? 0 ) );
        self::assertTrue( ResultRepository::hasBaselineComponent( 'plugin', $existing_slug ) );
        self::assertTrue( ResultRepository::hasBaselineComponent( 'plugin', $valid_slug ) );

        $removed = ResultRepository::removeBaselineComponent( 'plugin', $valid_slug );
        self::assertIsArray( $removed );
        self::assertFalse( ResultRepository::hasBaselineComponent( 'plugin', $valid_slug ), 'Explicit component removal did not remove its selected component.' );
        self::assertTrue( ResultRepository::hasBaselineComponent( 'plugin', $existing_slug ), 'Explicit component removal affected an unrelated baseline component.' );
        self::assertSame( 1, (int) ( ResultRepository::getBaseline()['entry_count'] ?? 0 ) );
    }

    private function approved_administrator(): WP_User {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $user = get_userdata( $user_id );
        self::assertInstanceOf( WP_User::class, $user );
        self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'c2a-package-fixture' ), 'Could not approve C2-A Administrator fixture.' );
        $user = get_userdata( $user_id );
        self::assertInstanceOf( WP_User::class, $user );
        return $user;
    }

    private function capture_wp_die( callable $callback ): CB_C2A_Test_Termination {
        $die_handler = static function ( $message = '', $title = '', $args = [] ): void {
            unset( $message, $title );
            throw new CB_C2A_Test_Termination( $args );
        };
        $handler_filter = static fn() => $die_handler;
        add_filter( 'wp_die_handler', $handler_filter, PHP_INT_MAX );
        try {
            $callback();
            self::fail( 'Expected WordPress package handler to terminate the request.' );
        } catch ( CB_C2A_Test_Termination $termination ) {
            return $termination;
        } finally {
            remove_filter( 'wp_die_handler', $handler_filter, PHP_INT_MAX );
        }
    }

    private function assert_runtime_exception( callable $callback, string $message ): RuntimeException {
        try {
            $callback();
            self::fail( $message );
        } catch ( RuntimeException $exception ) {
            return $exception;
        }
    }

    private function make_temp_dir( string $label ): string {
        $root = rtrim( wp_normalize_path( get_temp_dir() ), '/' );
        $path = $root . '/cb-c2a-' . sanitize_key( $label ) . '-' . bin2hex( random_bytes( 5 ) );
        self::assertTrue( mkdir( $path, 0700, true ), 'Could not create temporary C2-A directory: ' . $label );
        $this->cleanup_paths[] = $path;
        return $path;
    }

    private function make_top_level_upload_dir( string $prefix ): string {
        $name = sanitize_key( $prefix ) . '-' . bin2hex( random_bytes( 4 ) );
        $path = $this->uploads_root . '/' . $name;
        self::assertTrue( mkdir( $path, 0700, true ), 'Could not create top-level uploads fixture directory.' );
        $this->cleanup_paths[] = $path;
        return $path;
    }

    private function make_upload_file( string $relative, string $content ): string {
        $path = wp_normalize_path( $this->uploads_root . '/' . ltrim( $relative, '/' ) );
        $parent = dirname( $path );
        if ( ! is_dir( $parent ) ) {
            self::assertTrue( wp_mkdir_p( $parent ), 'Could not create uploads fixture parent.' );
        }
        self::assertNotFalse( file_put_contents( $path, $content ), 'Could not write uploads fixture file.' );
        return $path;
    }

    /** @return array<string,mixed> */
    private function seed_upload_finding( string $path, string $relative, ?string $hash = null ): array {
        $hash = $hash ?? hash_file( 'sha256', $path );
        self::assertIsString( $hash, 'Could not hash C2-A uploads fixture.' );
        $finding = Finding::make( [
            'type'     => 'uploads',
            'status'   => 'unexpected',
            'severity' => 'critical',
            'category' => 'tampering',
            'message'  => 'C2-A uploads finding',
            'target'   => [
                'slug'  => 'uploads',
                'label' => 'Uploads',
                'path'  => 'wp-content/uploads/',
                'file'  => $relative,
            ],
            'meta'     => [
                'actual_sha256'  => strtolower( (string) $hash ),
                'filesystem_path' => wp_normalize_path( $path ),
            ],
        ] );

        ResultRepository::saveLatest( [
            'storage_schema' => StorageSchema::VERSION,
            'plugin_version' => defined( 'CB_CORE_VERSION' ) ? CB_CORE_VERSION : '1.0.0',
            'status'         => 'done',
            'source'         => 'c2a-test',
            'completed_at'   => current_time( 'mysql' ),
            'summary'        => [ 'total' => 1, 'ok' => 0, 'warning' => 0, 'critical' => 1 ],
            'components'     => [],
            'completion'     => 'complete',
            'coverage'       => [ 'state' => 'complete' ],
            'checks'         => [ $finding ],
        ] );

        return $finding;
    }

    /** @return array<string,mixed> */
    private function baseline_candidate( string $slug, string $content, bool $complete ): array {
        if ( ! is_dir( WP_PLUGIN_DIR ) ) {
            self::assertTrue( wp_mkdir_p( WP_PLUGIN_DIR ), 'Could not create WordPress plugin directory for baseline fixture.' );
        }
        $root = wp_normalize_path( WP_PLUGIN_DIR . '/' . $slug );
        self::assertTrue( mkdir( $root, 0700, true ), 'Could not create baseline component fixture.' );
        $this->cleanup_paths[] = $root;
        self::assertNotFalse( file_put_contents( $root . '/plugin.php', "<?php\n/* Plugin Name: C2-A Baseline */\n" . $content . "\n" ) );

        $snapshot = DirectoryHasher::snapshot( $root );
        self::assertTrue( ! empty( $snapshot['complete'] ), 'Baseline fixture snapshot was incomplete.' );
        self::assertNotSame( [], (array) ( $snapshot['manifest'] ?? [] ), 'Baseline fixture manifest was empty.' );

        return Finding::make( [
            'type'     => 'plugin',
            'status'   => 'baseline_required',
            'severity' => 'warning',
            'message'  => 'C2-A baseline candidate',
            'target'   => [
                'slug'  => $slug,
                'label' => $slug,
                'path'  => 'wp-content/plugins/' . $slug . '/',
                'file'  => '',
            ],
            'verification' => [
                'method'     => 'local_baseline',
                'source'     => 'approved_local_baseline',
                'confidence' => 'medium',
                'label'      => 'Local approved baseline',
                'scope'      => 'component',
            ],
            'meta' => [
                'baseline_entry_id'  => 'baseline-' . $slug,
                'filesystem_root'    => $root,
                'baseline_manifest'  => (array) $snapshot['manifest'],
                'fingerprint_complete' => $complete,
                'fingerprint_hash'   => (string) $snapshot['hash'],
            ],
        ] );
    }

    private function assert_symlink_created( string $target, string $link ): void {
        self::assertTrue( symlink( $target, $link ), 'Linux C2-A harness could not create the required symlink fixture.' );
        self::assertTrue( is_link( $link ), 'C2-A symlink fixture was not a real symlink.' );
    }

    /** @return string[] */
    private function archive_entries( string $archive ): array {
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            self::assertTrue( true === $zip->open( $archive ), 'Could not inspect C2-A ZIP archive.' );
            $entries = [];
            for ( $index = 0; $index < $zip->numFiles; $index++ ) {
                $name = $zip->getNameIndex( $index );
                if ( is_string( $name ) ) {
                    $entries[] = $name;
                }
            }
            $zip->close();
            return $entries;
        }

        if ( ! class_exists( 'PclZip' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }
        $zip = new PclZip( $archive );
        $list = $zip->listContent();
        self::assertIsArray( $list, 'Could not inspect C2-A PclZip archive.' );
        $entries = [];
        foreach ( $list as $entry ) {
            if ( is_array( $entry ) && isset( $entry['filename'] ) ) {
                $entries[] = (string) $entry['filename'];
            }
        }
        return $entries;
    }

    /** @return string[] */
    private function temp_entries_containing( string $needle ): array {
        $entries = scandir( get_temp_dir() );
        if ( false === $entries ) {
            return [];
        }
        return array_values( array_filter( $entries, static fn( string $entry ): bool => str_contains( $entry, $needle ) ) );
    }

    private function audit_count( string $event_type ): int {
        $result = AuditLog::query( [
            'event_type' => AuditLog::normalize_event_type( $event_type ),
            'per_page'   => 1,
        ] );
        return (int) $result['total'];
    }

    private function path_is_within( string $path, string $root ): bool {
        $path = untrailingslashit( wp_normalize_path( $path ) );
        $root = untrailingslashit( wp_normalize_path( $root ) );
        return '' !== $path && '' !== $root && ( $path === $root || str_starts_with( $path . '/', $root . '/' ) );
    }

    private function remove_tree( string $path ): void {
        if ( '' === $path ) {
            return;
        }
        if ( is_link( $path ) || is_file( $path ) ) {
            @unlink( $path );
            return;
        }
        if ( ! is_dir( $path ) ) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $entry ) {
            $entry_path = $entry->getPathname();
            if ( $entry->isLink() || $entry->isFile() ) {
                @unlink( $entry_path );
            } else {
                @rmdir( $entry_path );
            }
        }
        @rmdir( $path );
    }
}
