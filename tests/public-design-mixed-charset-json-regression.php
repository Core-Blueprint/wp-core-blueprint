<?php
declare(strict_types=1);

/**
 * DND/Designer P0: WordPress wpdb mixed charset preflight.
 *
 * Both design tables have ascii_bin identity columns and WordPress-charset
 * document/receipt columns. wpdb may classify the mixed table as ASCII and
 * reject non-ASCII SQL literals before mysqli executes them. All SQL-bound
 * JSON must therefore be ASCII-safe while preserving decoded Unicode and
 * the existing cryptographic canonical_json() representation.
 *
 * Runs without WordPress, MySQL, a background runner, or mutations.
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/src/Design/Persistence/SaveEnvelope.php';
require_once dirname( __DIR__ ) . '/src/Design/Document/Repository.php';

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Persistence\SaveEnvelope;

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags );
	}
}

$check = static function ( bool $ok, string $label ): void {
	if ( ! $ok ) {
		fwrite( STDERR, "Mixed charset JSON regression FAIL: {$label}\n" );
		exit( 1 );
	}
};

$unicode = "Récapitulatif — wijzigingen ✅ 🔒 中文 العربية";
$document = [
	'project' => [
		'schema_version' => 1,
		'root' => [
			'type' => 'mail.section',
			'children' => [
				[ 'type' => 'mail.text', 'content' => $unicode ],
			],
		],
	],
	'fields' => [
		'subject' => $unicode,
		'preheader' => 'Line' . "\u{2028}" . 'separator',
	],
];

// Exercise the actual production Repository::encode() implementation, not
// a copied serialization algorithm.
$method = new ReflectionMethod( Repository::class, 'encode' );
$stored_document_json = $method->invoke( null, $document );
$check( is_string( $stored_document_json ), 'document serialization succeeded' );
$check( 1 !== preg_match( '/[^\\x00-\\x7F]/', $stored_document_json ),
	'SQL-bound document JSON contains no non-ASCII bytes' );
$check( json_decode( $stored_document_json, true, 128, JSON_THROW_ON_ERROR ) === $document,
	'document decode restores all original UTF-8 values' );

$receipt = (object) [
	'contractVersion' => 1,
	'status' => 'committed',
	'acceptedProject' => (object) [
		'schema_version' => 1,
		'heading' => $unicode,
	],
	'acceptedFields' => (object) [
		'subject' => $unicode,
	],
];
$canonical = SaveEnvelope::canonical_json( $receipt );
$check( str_contains( $canonical, 'Récapitulatif' ),
	'existing canonical JSON still uses original UTF-8 for digests' );
$canonical_digest = hash( 'sha256', $canonical );

$stored_receipt_json = SaveEnvelope::storage_json( $receipt );
$check( 1 !== preg_match( '/[^\\x00-\\x7F]/', $stored_receipt_json ),
	'SQL-bound receipt JSON contains no non-ASCII bytes' );
$decoded = json_decode( $stored_receipt_json, false, 128, JSON_THROW_ON_ERROR );
$check( SaveEnvelope::canonical_json( $decoded ) === $canonical,
	'canonical receipt and digest remain identical after ASCII transport' );
$check( hash( 'sha256', SaveEnvelope::canonical_json( $decoded ) ) === $canonical_digest,
	'receipt digest remains unchanged' );
$check( $decoded->acceptedProject->heading === $unicode
	&& $decoded->acceptedFields->subject === $unicode,
	'receipt decode restores Unicode without normalization or data loss' );

echo "Base Designer mixed-charset SQL JSON storage regression: PASS\n";
