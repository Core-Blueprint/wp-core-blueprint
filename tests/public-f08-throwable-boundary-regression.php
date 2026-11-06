<?php
declare(strict_types=1);

/**
 * FRG-04G F08: cross-boundary exception messages must not reach operator
 * responses, persisted notices or security audit contexts unclassified.
 * Standalone Level 1 contract: no WordPress installation/database required.
 */

$root = dirname( __DIR__ );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . '/' );
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $value ): string {
		return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( $value ) ) ?? '';
	}
}

require_once $root . '/src/Support/ThrowableBoundary.php';

use CoreBlueprint\Core\Support\ThrowableBoundary;

$check = static function ( bool $valid, string $name ): void {
	if ( ! $valid ) {
		fwrite( STDERR, "F08 Throwable boundary FAIL: {$name}\n" );
		exit( 1 );
	}
};

$secret = 'api_key=F08_NEVER_ECHO_this_secret';
$unexpected = new RuntimeException( '/private/uploads/' . $secret );
$known = [ 'Known, safe domain validation failure.' ];
$fallback = 'An error occurred.';

$check( $fallback === ThrowableBoundary::known_message( $unexpected, $known, $fallback ), 'secret-bearing exception gets fixed fallback' );
$check( $known[0] === ThrowableBoundary::known_message( new RuntimeException( $known[0] ), $known, $fallback ), 'known domain validation error retained' );
$check( $fallback === ThrowableBoundary::known_message( new RuntimeException( 'Something else' ), [], $fallback ), 'unknown exception remains generic' );
$context = ThrowableBoundary::context( $unexpected, 'provider_failed' );
$check( [ 'reason' => 'provider_failed', 'exception_class' => RuntimeException::class ] === $context, 'audit context only exposes bounded metadata' );
$check( ! str_contains( json_encode( $context ), $secret ), 'audit context excludes secret' );
$check( ThrowableBoundary::diagnostic( $unexpected ) === RuntimeException::class, 'debug diagnostic is class-only' );

$cases = [
	'src/Ajax/Handlers/Reports.php' => [
		'present' => [ 'ThrowableBoundary::context( $e,', "'code'   => 'reports_generation_failed'" ],
		'absent'  => [ "'detail'  => \$e->getMessage()", "'reason'    => \$e->getMessage()" ],
	],
	'src/Ajax/Handlers/Modules.php' => [
		'present' => [ "'message' => __( 'An error occurred.', 'core-blueprint' )" ],
		'absent'  => [ 'sanitize_text_field( $e->getMessage() )' ],
	],
	'src/Ajax/Handlers/TwoFactorPolicy.php' => [
		'present' => [ 'ThrowableBoundary::known_message( $error,' ],
		'absent'  => [ "'message' => \$error->getMessage()" ],
	],
	'src/AdminColumns/Admin/Ajax.php' => [
		'present' => [ "'message' => __( 'An error occurred.', 'core-blueprint' )" ],
		'absent'  => [ "wp_send_json_error( [ 'message' => \$error->getMessage() ], 500 )" ],
	],
	'src/Security/TwoFactor/ProfileController.php' => [
		'present' => [ 'ThrowableBoundary::known_message( $error,', 'self::safe_failure_message( $error )' ],
		'absent'  => [ "self::set_notice( (int) \$user->ID, 'error', \$error->getMessage() )" ],
	],
	'src/Profiles/Admin/Actions.php' => [
		'present' => [ "self::redirect_with_notice( 'error', __( 'An error occurred.', 'core-blueprint' ) )" ],
		'absent'  => [ "self::redirect_with_notice( 'error', \$error->getMessage() )" ],
	],
	'src/MediaReplace/AdminIntegration.php' => [
		'present' => [ 'ThrowableBoundary::context( $previous,', 'ThrowableBoundary::context( $e,' ],
		'absent'  => [ "'exception_message'", "'error'         => \$e->getMessage()" ],
	],
	'src/PackageDownload/AdminIntegration.php' => [
		'present' => [ 'ThrowableBoundary::context( $e,', 'ThrowableBoundary::diagnostic( $e )' ],
		'absent'  => [ "esc_html( \$e->getMessage() )", "'message'   => \$e->getMessage()" ],
	],
	'src/Integrity/Api/IntegrityApi.php' => [
		'present' => [ 'ThrowableBoundary::context( $throwable,' ],
		'absent'  => [ "'message' => \$throwable->getMessage()" ],
	],
	'src/Permissions/Rest/RolesController.php' => [
		'present' => [ 'ThrowableBoundary::known_message( $e,' ],
		'absent'  => [ 'return self::error( $e->getMessage(), 403 )' ],
	],
];

foreach ( $cases as $file => $rules ) {
	$source = file_get_contents( $root . '/' . $file );
	$check( is_string( $source ), $file . ' must exist and be readable' );
	foreach ( $rules['present'] as $needle ) {
		$check( str_contains( $source, $needle ), $file . ' must implement a safe output boundary: ' . $needle );
	}
	foreach ( $rules['absent'] as $needle ) {
		$check( ! str_contains( $source, $needle ), $file . ' must not project an unchecked exception: ' . $needle );
	}
}

echo "F08 cross-boundary source and safe projection contracts: PASS\n";
