<?php
declare(strict_types=1);

use CB\Core\Design\Editor\Assets;

final class CB_Design_Foundation_Flow_Preview_Host_Test extends WP_UnitTestCase {

	private function source(): string {
		$source = file_get_contents( CB_CORE_DIR . 'assets/js/design/document/flow/preview-host.js' );
		self::assertIsString( $source );
		return $source;
	}

	public function test_designer_mode_enqueues_public_flow_preview_host(): void {
		Assets::enqueue_designer_mode( 'Flow preview host test' );

		self::assertTrue( wp_script_is( Assets::FLOW_PREVIEW_SCRIPT, 'enqueued' ) );

		$registered = wp_scripts()->registered[ Assets::FLOW_PREVIEW_SCRIPT ] ?? null;
		self::assertInstanceOf( _WP_Dependency::class, $registered );
		self::assertStringEndsWith( '/assets/js/design/document/flow/preview-host.js', (string) $registered->src );
		self::assertContains( Assets::DESIGNER_MODE_SCRIPT, $registered->deps );
	}

	public function test_host_uses_opaque_sandbox_and_cross_document_height_protocol(): void {
		$source = $this->source();

		self::assertStringContainsString( "setAttribute('sandbox', 'allow-scripts')", $source );
		self::assertStringNotContainsString( 'allow-same-origin', $source );
		self::assertStringNotContainsString( 'contentDocument', $source );
		self::assertStringContainsString( "PROTOCOL = 'cb-flow-preview-v1'", $source );
		self::assertStringContainsString( 'event.source !== frame.contentWindow', $source );
		self::assertStringContainsString( 'token === null', $source );
		self::assertStringContainsString( 'ResizeObserver', $source );
		self::assertStringContainsString( 'generation', $source );
		self::assertStringContainsString( 'token', $source );
		self::assertStringContainsString( 'MAX_HEIGHT = 100000', $source );
		self::assertStringContainsString( 'frame.hidden = true', $source );
	}

	public function test_host_fails_closed_without_expected_csp_and_allows_only_hashed_bridge_script(): void {
		$source = $this->source();

		self::assertStringContainsString( 'Flow preview document must provide a Content-Security-Policy.', $source );
		self::assertStringContainsString( 'Flow preview document already defines script-src.', $source );
		self::assertStringContainsString( "script-src '\" + BRIDGE_HASH + \"'", $source );

		self::assertSame( 1, preg_match( "/var BRIDGE_HASH = '(sha256-[A-Za-z0-9+\\/=]+)';/", $source, $hash_match ) );
		self::assertSame( 1, preg_match( "/var BRIDGE_SCRIPT = \\[(.*?)\\n\\t\\]\\.join\\('\\\\n'\\);/s", $source, $script_match ) );
		$line_count = preg_match_all( '/^\\t\\t("(?:[^"\\\\]|\\\\.)*")[,]?$/m', $script_match[1], $line_matches );
		self::assertGreaterThan( 0, (int) $line_count );

		$lines = [];
		foreach ( $line_matches[1] as $encoded_line ) {
			$decoded_line = json_decode( $encoded_line, true );
			self::assertIsString( $decoded_line );
			$lines[] = $decoded_line;
		}

		$bridge = implode( "\n", $lines );
		$actual_hash = 'sha256-' . base64_encode( hash( 'sha256', $bridge, true ) );
		self::assertSame( $hash_match[1], $actual_hash );
	}
}
