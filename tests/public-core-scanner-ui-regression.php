<?php
declare(strict_types=1);

/**
 * Core Scanner field-acceptance UI contract, read-only and WordPress-free.
 *
 * Safeguards has several tabs. Scanner's shared disclosure CSS must be
 * selected by the tab asset registry rather than copied into page CSS.
 * The remaining layout rules are deliberately scoped to Scanner.
 */

function scanner_ui_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "Core Scanner UI regression FAILED: {$message}\n" );
		exit( 1 );
	}
}

function scanner_ui_source( string $path ): string {
	$source = file_get_contents( dirname( __DIR__ ) . '/' . $path );
	scanner_ui_assert( is_string( $source ), 'cannot read ' . $path );
	return $source;
}

$registry = scanner_ui_source( 'src/Admin/ScreenAssetRegistry.php' );
$scanner_start = strpos( $registry, "case 'core-scanner':" );
$scanner_end = false !== $scanner_start ? strpos( $registry, "case 'failsafe':", $scanner_start ) : false;
scanner_ui_assert( false !== $scanner_start && false !== $scanner_end, 'scanner asset scope exists' );
$scanner_requirements = substr( $registry, $scanner_start, $scanner_end - $scanner_start );
scanner_ui_assert( str_contains( $scanner_requirements, "'component.interactive-surfaces'" ), 'Scanner loads shared interactive surfaces' );

$shared = scanner_ui_source( 'assets/css/components/interactive-surfaces.css' );
scanner_ui_assert( str_contains( $shared, '.cb-core-disclosure > .cb-core-disclosure__summary' ), 'shared disclosure row styles exist' );
scanner_ui_assert( str_contains( $shared, '.cb-core-interactive-row > .cb-core-interactive-row__summary' ), 'shared Findings row styles exist' );
scanner_ui_assert( str_contains( $shared, '.cb-core-interactive-row > .cb-core-interactive-row__summary::-webkit-details-marker' ), 'native browser finding marker is suppressed' );
scanner_ui_assert( str_contains( $shared, '.cb-core-disclosure > .cb-core-disclosure__summary::marker' ), 'native browser disclosure marker is suppressed' );

$findings = scanner_ui_source( 'src/Integrity/Admin/ScannerGroupedFindingsView.php' );
scanner_ui_assert( str_contains( $findings, 'cb-core-interactive-row__summary' ), 'Findings consumes shared row component' );
scanner_ui_assert( str_contains( $findings, 'cb-core-disclosure__summary' ), 'technical details consume shared disclosure' );
$overview = scanner_ui_source( 'src/Integrity/Admin/ScannerOverviewView.php' );
scanner_ui_assert( str_contains( $overview, 'cb-core-integrity-coverage-head' ), 'coverage has a title/status header row' );
scanner_ui_assert( str_contains( $overview, 'cb-core-integrity-about-scan cb-core-disclosure' ), 'About scan consumes shared disclosure' );

$css = scanner_ui_source( 'assets/css/pages/safeguards-core-scanner.css' );
scanner_ui_assert(
	1 === preg_match( '/[.]cb-core-integrity-local-nav[[:space:]]*[{][^}]*overflow-y:[[:space:]]*hidden[[:space:]]*;/s', $css ),
	'tab bar hides unwanted vertical overflow'
);
scanner_ui_assert(
	1 === preg_match( '/[.]cb-core-integrity-coverage-panel[[:space:]]+[.]cb-core-integrity-component[[:space:]]*[{][^}]*flex-direction:[[:space:]]*column[[:space:]]*;/s', $css ),
	'coverage cards stack their content vertically'
);
scanner_ui_assert( str_contains( $css, '.cb-core-integrity-coverage-head {' ), 'coverage status and title share one row' );

echo "Core Scanner field UI regression: PASS\n";
