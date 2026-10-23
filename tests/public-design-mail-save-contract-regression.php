<?php
declare(strict_types=1);

/**
 * Mail Designer P0: the shipped WordPress template plus palette additions must
 * pass the same PHP-only native document admission used by Repository::write.
 * No WordPress runtime, database writes, fixture migration or public release.
 */

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Editor\PlatformTransport;
use CoreBlueprint\Core\Design\Persistence\SaveEnvelope;
use CoreBlueprint\Core\Design\Profile\Mail\Contract;
use CoreBlueprint\Core\Design\Profile\Mail\Validator;
use CoreBlueprint\Core\Mail\Designer\WordPressTemplates;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );
if ( ! function_exists( '__' ) ) {
	function __( string $value, string $domain = '' ): string { return $value; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags );
	}
}

$root = dirname( __DIR__ );
foreach ( [
	'src/Design/Kernel/Diagnostic.php',
	'src/Design/Kernel/Diagnostics.php',
	'src/Design/Profile/Mail/Contract.php',
	'src/Design/Profile/Mail/Validator.php',
	'src/Design/Document/Repository.php',
	'src/Design/Editor/PlatformTransport.php',
	'src/Design/Persistence/SaveEnvelope.php',
	'src/Mail/Designer/WordPressTemplates.php',
] as $file ) {
	require_once $root . '/' . $file;
}

$check = static function ( bool $valid, string $name ): void {
	if ( ! $valid ) {
		fwrite( STDERR, "Mail Save source contract FAIL: {$name}\n" );
		exit( 1 );
	}
};

$document = WordPressTemplates::default_document( 'wordpress.new-user-admin' );
$check( is_array( $document ), 'native WordPress resource default exists' );
$check( Repository::valid_document( Repository::MAIL, $document ),
	'native default admitted by Repository' );

// Same user-visible addition as the P0 field scenario: another section,
// containing a Heading and Text. No alternate legacy document contract.
$document['project']['root']['children'][] = Contract::node( 'mail.section', [
	Contract::node( 'mail.heading' ),
	Contract::node( 'mail.text' ),
] );
$check( ! ( new Validator() )->validate( $document['project'] )->has_errors(),
	'Mail Validator accepts newly added Section Heading and Text' );
$check( PlatformTransport::valid_native_document( Repository::MAIL, $document ),
	'Mail domain admission accepts the edited document' );
$check( Repository::valid_document( Repository::MAIL, $document ),
	'Repository writer admission accepts the edited document' );

// The transport first decodes incoming JSON to nested stdClass objects.
$project_object = json_decode( wp_json_encode( $document['project'] ), false, 128, JSON_THROW_ON_ERROR );
$fields_object = json_decode( wp_json_encode( $document['fields'] ), false, 128, JSON_THROW_ON_ERROR );
$project = json_decode( SaveEnvelope::canonical_json( $project_object ), true, 128, JSON_THROW_ON_ERROR );
$fields = json_decode( SaveEnvelope::canonical_json( $fields_object ), true, 128, JSON_THROW_ON_ERROR );
$check( Repository::valid_document( Repository::MAIL, [ 'project' => $project, 'fields' => $fields ] ),
	'canonical server SaveEnvelope roundtrip remains admissible' );

$invalid = $document;
$invalid['project']['root']['children'][] = Contract::node( 'mail.section', [
	Contract::node( 'mail.section' ),
] );
$check( ! Repository::valid_document( Repository::MAIL, $invalid ),
	'invalid nested Section remains rejected' );

echo "Base Designer native Mail document Save admission regression: PASS\n";
