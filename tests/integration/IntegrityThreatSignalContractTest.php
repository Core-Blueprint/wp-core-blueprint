<?php
declare(strict_types=1);

use CoreBlueprint\Core\Integrity\Scanner\FileSignatureInspector;
use CoreBlueprint\Core\Integrity\Scanner\ThreatCorrelator;
use CoreBlueprint\Core\Integrity\Scanner\MuPluginScanner;
use CoreBlueprint\Core\Integrity\Scanner\LoaderPatternInspector;
use CoreBlueprint\Core\Integrity\Support\Finding;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository;

final class IntegrityThreatSignalContractTest extends WP_UnitTestCase {
	/** @var list<string> */
	private array $temporary_files = [];

	protected function tearDown(): void {
		foreach ( $this->temporary_files as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		parent::tearDown();
	}

	private function fixture( string $bytes ): string {
		$path = tempnam( sys_get_temp_dir(), 'cb-threat-' );
		$this->assertIsString( $path );
		file_put_contents( $path, $bytes );
		$this->temporary_files[] = $path;
		return $path;
	}

	public function test_executable_disguised_as_png_is_detected_without_execution(): void {
		$header = str_repeat( "\0", 256 );
		$header = substr_replace( $header, 'MZ', 0, 2 );
		$header = substr_replace( $header, pack( 'V', 128 ), 0x3c, 4 );
		$header = substr_replace( $header, "PE\0\0", 128, 4 );
		$result = FileSignatureInspector::inspect( $this->fixture( $header ), 'png' );
		$this->assertTrue( $result['checked'] );
		$this->assertSame( 'windows_pe', $result['detected'] );
		$this->assertSame( 'disguised_executable', $result['anomaly'] );
	}

	public function test_valid_png_and_unverified_mz_are_not_false_positives(): void {
		$png = FileSignatureInspector::inspect( $this->fixture( "\x89PNG\r\n\x1a\n" . str_repeat( 'a', 32 ) ), 'png' );
		$this->assertSame( 'png', $png['detected'] );
		$this->assertSame( '', $png['anomaly'] );
		$unknown = FileSignatureInspector::inspect( $this->fixture( 'MZ' . str_repeat( 'a', 128 ) ), 'png' );
		$this->assertSame( 'unknown', $unknown['detected'] );
		$this->assertSame( '', $unknown['anomaly'] );
	}

	public function test_exact_media_reference_is_required_to_correlate(): void {
		$loader = Finding::make( [
			'type' => 'mu_plugin', 'status' => 'suspicious_loader_chain', 'severity' => 'warning',
			'target' => [ 'slug' => 'mu-plugins', 'path' => 'wp-content/mu-plugins/', 'file' => 'loader.php' ],
			'meta' => [ 'referenced_media' => [ 'hero-background.png' ] ],
		] );
		$payload = Finding::make( [
			'type' => 'uploads', 'status' => 'disguised_executable', 'severity' => 'critical',
			'target' => [ 'slug' => 'uploads', 'path' => 'wp-content/uploads/', 'file' => '2026/03/hero-background.png' ],
			'meta' => [ 'actual_sha256' => str_repeat( 'a', 64 ), 'signature_stable' => true ],
		] );
		$linked = ThreatCorrelator::correlate( [ $loader, $payload ] );
		$this->assertCount( 3, $linked );
		$this->assertSame( 'linked_execution_chain', $linked[2]['status'] );
		$loader['meta']['referenced_media'] = [ 'not-a-match.png' ];
		$this->assertCount( 2, ThreatCorrelator::correlate( [ $loader, $payload ] ) );
	}

	public function test_loader_patterns_ignore_comments_and_quoted_examples(): void {
		$comment = "<?php\n/* rename( 'fake.png', 'fake.exe' ); exec( 'ignored' ); */\n";
		$this->assertFalse( LoaderPatternInspector::inspect( $comment )['suspected'] );
		$source = "<?php\nrename( 'hero-background.png', 'payload.exe' );\nexec( 'TEST_NOT_EXECUTED' );\n";
		$this->assertTrue( LoaderPatternInspector::inspect( $source )['suspected'] );
	}

	public function test_mu_plugin_baseline_is_explicit_and_no_untrusted_code_is_executed(): void {
		$root = (string) ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' );
		if ( is_link( $root ) || ( is_dir( $root ) && ! is_writable( $root ) ) ) {
			$this->markTestSkipped( 'MU-plugin test directory not writable.' );
		}
		$created = ! is_dir( $root );
		if ( $created && ! mkdir( $root, 0755, true ) ) {
			$this->markTestSkipped( 'Cannot create MU-plugin directory.' );
		}
		$file = $root . '/cb-threat-fixture-' . wp_generate_password( 12, false, false ) . '.php';
		file_put_contents( $file, "<?php\nrename( 'hero-background.png', 'payload.exe' );\nexec( 'echo TEST_ONLY' );\n" );
		$this->temporary_files[] = $file;
		try {
			$scanner = new MuPluginScanner();
			$state = [];
			$findings = [];
			$done = false;
			for ( $i = 0; $i < 20 && ! $done; $i++ ) {
				$result = $scanner->scan_batch( $state, 100, 2.0 );
				$state = $result['state'];
				$findings = array_merge( $findings, $result['checks'] );
				$done = $result['done'];
			}
			$this->assertTrue( $done, 'Resumable scan should finish without executing the synthetic loader.' );
			$statuses = array_column( $findings, 'status' );
			$this->assertContains( 'suspicious_loader_chain', $statuses );
			$this->assertContains( 'baseline_required', $statuses );
			$candidates = array_filter( $findings, [ ResultRepository::class, 'isBaselineCandidateCheck' ] );
			$this->assertCount( 1, $candidates );
			$this->assertSame( 'incomplete', $result['coverage']['state'] );
			$approved = ResultRepository::saveBaselineComponent( [ 'checks' => $findings, 'summary' => [] ], 'mu_plugin', 'mu-plugins' );
			$this->assertTrue( (bool) ( $approved['_component_saved'] ?? false ) );
			$state = [];
			$verified_findings = [];
			$done = false;
			for ( $i = 0; $i < 20 && ! $done; $i++ ) {
				$verified = $scanner->scan_batch( $state, 100, 2.0 );
				$state = $verified['state'];
				$verified_findings = array_merge( $verified_findings, $verified['checks'] );
				$done = $verified['done'];
			}
			$this->assertTrue( $done );
			$this->assertContains( 'ok', array_column( $verified_findings, 'status' ) );
			$this->assertNotContains( 'missing', array_column( $verified_findings, 'status' ) );
			$this->assertSame( 'complete', $verified['coverage']['state'] );
		} finally {
			unlink( $file );
			$this->temporary_files = array_values( array_diff( $this->temporary_files, [ $file ] ) );
			if ( $created ) {
				@rmdir( $root );
			}
		}
	}
}
