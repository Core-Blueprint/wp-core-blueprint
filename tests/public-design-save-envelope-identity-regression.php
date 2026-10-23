<?php
declare(strict_types=1);

/**
 * SOURCE regression for the canonical native Designer SaveEnvelope identity ABI.
 * Executable without WordPress/MySQL; no Save transaction or release authority.
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require_once dirname( __DIR__ ) . '/src/Design/Persistence/SaveEnvelope.php';

use CoreBlueprint\Core\Design\Persistence\SaveEnvelope;

$assert = static function ( bool $ok, string $label ): void {
	if ( ! $ok ) {
		fwrite( STDERR, "SaveEnvelope identity regression FAIL: {$label}\n" );
		exit( 1 );
	}
};

$make = static function (
	string $resource_type,
	string $resource_key,
	string $profile_id,
	string $project_schema
): SaveEnvelope {
	return new SaveEnvelope(
		1, 1, $resource_type, $resource_key, $profile_id, $project_schema,
		'sha256:' . str_repeat( 'a', 64 ), str_repeat( 'b', 32 ), '0',
		(object) [ 'schema_version' => 1, 'design_type' => 'example.type',
			'root' => (object) [ 'type' => 'example.node', 'children' => [] ] ],
		(object) [ 'subject' => 'Example subject', 'preheader' => '' ]
	);
};

foreach ( [
	[ 'base.mail-template', 'wordpress.new-user-admin', 'base/mail', 'base/mail/1' ],
	[ 'base.maintenance-report-design', 'maintenance', 'base/reports', 'base/reports/1' ],
	[ 'vendor.document', 'vendor-resource', 'vendor/editor', 'vendor/editor/2' ],
] as [ $resource_type, $resource_key, $profile_id, $project_schema ] ) {
	$envelope = $make( $resource_type, $resource_key, $profile_id, $project_schema );
	$assert( $envelope->profile_id === $profile_id, 'canonical profile accepted' );
	$assert( $envelope->project_schema === $project_schema, 'canonical schema accepted' );
	$assert( hash_equals( $envelope->request_digest, $envelope->digest( $envelope->project, $envelope->fields ) ),
		'canonical digest stable' );
}

foreach ( [
	[ 'base/mail/other', 'base/mail/1' ],
	[ 'base//mail', 'base/mail/1' ],
	[ 'BASE/mail', 'base/mail/1' ],
	[ 'base/../mail', 'base/mail/1' ],
	[ 'base/mail', 'base/mail/0' ],
	[ 'base/mail', 'base/mail' ],
	[ 'base/mail', 'base/mail/01' ],
	[ 'base/mail', 'base/reports/1/extra' ],
] as [ $profile_id, $schema ] ) {
	try {
		$make( 'base.mail-template', 'wordpress.new-user-admin', $profile_id, $schema );
		fwrite( STDERR, "SaveEnvelope identity regression FAIL: invalid profile/schema admitted\n" );
		exit( 1 );
	} catch ( \InvalidArgumentException $expected ) {
		// The validation boundary must reject before a Save transaction starts.
	}
}

try {
	$make( 'base/mail-template', 'wordpress.new-user-admin', 'base/mail', 'base/mail/1' );
	fwrite( STDERR, "SaveEnvelope identity regression FAIL: unsafe resource type admitted\n" );
	exit( 1 );
} catch ( \InvalidArgumentException $expected ) {
	// Slash is legitimate in profile/schema, not in a document resource type.
}

echo "Base Designer SaveEnvelope canonical identity regression: PASS\n";
