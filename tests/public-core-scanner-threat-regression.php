<?php
declare(strict_types=1);

/**
 * Self-contained, non-executing Core Scanner threat-signal regression.
 *
 * Level 1 source gate: no WordPress bootstrap, network, DB or executable
 * payload is required or permitted.
 */

require_once dirname( __DIR__ ) . '/src/Integrity/Scanner/FileSignatureInspector.php';
require_once dirname( __DIR__ ) . '/src/Integrity/Scanner/LoaderPatternInspector.php';

use CoreBlueprint\Core\Integrity\Scanner\FileSignatureInspector;
use CoreBlueprint\Core\Integrity\Scanner\LoaderPatternInspector;

function scanner_threat_assert( bool $condition, string $description ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "Core Scanner threat-signal regression FAILED: {$description}\n" );
		exit( 1 );
	}
}

$file = tempnam( sys_get_temp_dir(), 'cb-threat-sig-' );
scanner_threat_assert( is_string( $file ) && '' !== $file, 'isolated temporary fixture available' );

try {
	// Header markers only; this is NOT a real executable payload.
	$header = str_repeat( "\0", 256 );
	$header = substr_replace( $header, 'MZ', 0, 2 );
	$header = substr_replace( $header, pack( 'V', 128 ), 0x3c, 4 );
	$header = substr_replace( $header, "PE\0\0", 128, 4 );
	file_put_contents( $file, $header );
	$pe = FileSignatureInspector::inspect( $file, 'png' );
	scanner_threat_assert( $pe['checked'] && 'windows_pe' === $pe['detected'] && 'disguised_executable' === $pe['anomaly'], 'PE header masquerading as PNG' );

	file_put_contents( $file, "\x89PNG\r\n\x1a\n" . str_repeat( "\0", 24 ) );
	$png = FileSignatureInspector::inspect( $file, 'png' );
	scanner_threat_assert( 'png' === $png['detected'] && '' === $png['anomaly'], 'normal PNG is not flagged' );

	file_put_contents( $file, 'MZ' . str_repeat( 'a', 126 ) );
	$mz = FileSignatureInspector::inspect( $file, 'png' );
	scanner_threat_assert( 'unknown' === $mz['detected'] && '' === $mz['anomaly'], 'MZ alone is not a PE verdict' );

	file_put_contents( $file, "<?php echo 'test';" );
	$php = FileSignatureInspector::inspect( $file, 'jpg' );
	scanner_threat_assert( 'php' === $php['detected'] && 'disguised_executable' === $php['anomaly'], 'PHP source behind image extension' );
} finally {
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}

$comment = <<<'PHP'
<?php
/* rename('media.png', 'payload.exe'); exec('example'); */
$example = "rename('media.png', 'payload.exe'); exec('example');";
PHP;
scanner_threat_assert( ! LoaderPatternInspector::inspect( $comment )['suspected'], 'comments and quoted examples are ignored' );

$methods = <<<'PHP'
<?php
$runner->rename('media.png', 'payload.exe');
$runner->exec('example');
function exec($arg) { return $arg; }
PHP;
scanner_threat_assert( ! LoaderPatternInspector::inspect( $methods )['suspected'], 'object methods and declarations are ignored' );

$loader = <<<'PHP'
<?php
if (false) {
    rename('media.png', 'payload.exe');
    exec('NEVER_EXECUTED');
}
PHP;
$signals = LoaderPatternInspector::inspect( $loader );
scanner_threat_assert( $signals['suspected'], 'passive loader indicators are detected' );
scanner_threat_assert( in_array( 'media.png', $signals['referenced_media'], true ), 'literal media filename evidence is preserved' );

echo "Core Scanner threat-signal Level 1 regression: PASS\n";
