<?php
declare(strict_types=1);

final class CB_Base_Clipboard_Consumer_Boundary_Contract_Test extends WP_UnitTestCase {

	public function test_clipboard_foundation_is_the_only_runtime_clipboard_implementation(): void {
		$clipboard = $this->source( 'assets/js/core/clipboard-runtime.js' );
		$dom       = $this->source( 'assets/js/core/dom.js' );
		$failsafe  = $this->source( 'assets/js/features/failsafe.js' );
		$console   = $this->source( 'assets/js/features/console.js' );
		$scanner   = $this->source( 'assets/js/features/core-scanner.js' );
		$two_factor = $this->source( 'assets/js/features/two-factor-enrollment.js' );

		self::assertStringContainsString( 'navigator.clipboard.writeText', $clipboard );
		self::assertStringContainsString( "execCommand?.( 'copy' )", $clipboard );

		self::assertStringNotContainsString( 'copyToClipboard', $dom );
		self::assertStringNotContainsString( 'navigator.clipboard', $dom );
		self::assertStringNotContainsString( 'execCommand', $dom );

		foreach ( [
			'failsafe' => $failsafe,
			'console'  => $console,
			'scanner'     => $scanner,
			'two-factor'  => $two_factor,
		] as $consumer => $source ) {
			self::assertStringContainsString(
				"../core/clipboard-runtime.js",
				$source,
				$consumer . ' must consume the shared Clipboard Foundation.'
			);
			self::assertStringNotContainsString(
				'navigator.clipboard.writeText',
				$source,
				$consumer . ' must not own direct Clipboard API behavior.'
			);
			self::assertStringNotContainsString(
				'execCommand',
				$source,
				$consumer . ' must not own fallback clipboard behavior.'
			);
		}
	}

	private function source( string $relative_path ): string {
		$source = file_get_contents( CB_CORE_DIR . $relative_path );
		self::assertIsString( $source, 'Could not read source contract: ' . $relative_path );
		return $source;
	}
}
