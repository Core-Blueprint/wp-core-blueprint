<?php
declare(strict_types=1);

/**
 * MAIL-EXT-01A: provider-owned native defaults without WordPress/SQL/network.
 * The same register/default API must serve built-in WordPress and extensions.
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Editor\PlatformTransport;
use CoreBlueprint\Core\Design\Profile\Mail\Contract;
use CoreBlueprint\Core\Design\Profile\Mail\PlatformAdapter;
use CoreBlueprint\Core\Mail\Designer\TemplateRegistry;
use CoreBlueprint\Core\Mail\Designer\WordPressTemplates;

function __( string $text, string $domain = '' ): string { return $text; }
function sanitize_text_field( string $text ): string { return trim( strip_tags( $text ) ); }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function do_action( string $name ): void {
	if ( 'core_blueprint_register_mail_templates' === $name ) {
		$GLOBALS['cb_mail_ext_registrations']++;
		( $GLOBALS['cb_mail_ext_register'] )();
	}
}
function cb_mail_ext_assert( bool $condition, string $reason ): void {
	if ( ! $condition ) throw new RuntimeException( 'MAIL-EXT-01A: ' . $reason );
}

$root = dirname( __DIR__ );
foreach ( [
	'src/Design/Kernel/Diagnostic.php',
	'src/Design/Kernel/Diagnostics.php',
	'src/Design/Profile/Mail/Contract.php',
	'src/Design/Profile/Mail/Validator.php',
	'src/Design/Document/Repository.php',
	'src/Design/Editor/PlatformTransport.php',
	'src/Mail/Designer/TemplateRegistry.php',
	'src/Mail/Designer/WordPressTemplates.php',
	'src/Design/Profile/Mail/PlatformAdapter.php',
] as $file ) require_once $root . '/' . $file;

$default = [
	'project' => [
		'schema_version' => 1,
		'design_type' => Contract::DESIGN_TYPE,
		'root' => Contract::node( Contract::ROOT_TYPE, [
			Contract::node( 'mail.section', [
				Contract::node( 'mail.heading', [], [ 'text' => 'Hello {{site.name}}' ] ),
				Contract::node( 'mail.text', [], [ 'text' => 'Booking {{booking.date}}' ] ),
			] ),
		] ),
	],
	'fields' => [
		'subject' => 'Booking for {{booking.customer_name}}',
		'preheader' => 'Appointment details',
	],
];
$provider = [
	'id' => 'bookings.customer-confirmed',
	'provider' => 'bookings',
	'group' => 'bookings',
	'label' => 'Booking confirmed',
	'description' => 'Provider-owned message',
	'document' => $default,
];
$GLOBALS['cb_mail_ext_registrations'] = 0;
$GLOBALS['cb_mail_ext_register'] = static function () use ( $provider ): void {
	cb_mail_ext_assert( TemplateRegistry::register( $provider ), 'registered external native v1 document' );
};
TemplateRegistry::boot();
cb_mail_ext_assert( 1 === $GLOBALS['cb_mail_ext_registrations'], 'extension registration action once' );
cb_mail_ext_assert( 7 === count( TemplateRegistry::all() ), 'six WordPress plus one extension template' );
cb_mail_ext_assert( TemplateRegistry::default_document( $provider['id'] ) === $default,
	'external read-only canonical default' );
cb_mail_ext_assert( PlatformAdapter::default_for_resource( $provider['id'] ) === $default,
	'editor context uses provider default' );
cb_mail_ext_assert( Repository::valid_document( Repository::MAIL, $default ),
	'provider document accepted by native repository admission' );

$copy = TemplateRegistry::default_document( $provider['id'] );
$copy['fields']['subject'] = 'changed';
cb_mail_ext_assert( TemplateRegistry::default_document( $provider['id'] ) === $default,
	'read does not mutate provider default' );

foreach ( [
	'wordpress.password-reset', 'wordpress.new-user', 'wordpress.new-user-admin',
	'wordpress.password-changed', 'wordpress.email-changed', 'wordpress.password-changed-admin',
] as $id ) {
	$wp_default = WordPressTemplates::default_document( $id );
	cb_mail_ext_assert( is_array( $wp_default ), 'built-in WordPress document: ' . $id );
	cb_mail_ext_assert( TemplateRegistry::default_document( $id ) === $wp_default,
	'built-in source parity: ' . $id );
	cb_mail_ext_assert( PlatformAdapter::default_for_resource( $id ) === $wp_default,
	'built-in editor context parity: ' . $id );
	cb_mail_ext_assert( Repository::valid_document( Repository::MAIL, $wp_default ),
	'built-in native document validation: ' . $id );
}

cb_mail_ext_assert( ! TemplateRegistry::register( $provider ), 'duplicate ID rejected' );
$invalid = $provider;
$invalid['id'] = 'bookings.invalid-project';
$invalid['document']['project']['schema_version'] = 0;
cb_mail_ext_assert( ! TemplateRegistry::register( $invalid ), 'legacy project rejected' );
$invalid = $provider;
$invalid['id'] = 'bookings.invalid-fields';
$invalid['document']['fields']['unexpected'] = 'x';
cb_mail_ext_assert( ! TemplateRegistry::register( $invalid ), 'extra persisted field rejected' );
$invalid = $provider;
$invalid['id'] = 'bookings.empty-subject';
$invalid['document']['fields']['subject'] = '';
cb_mail_ext_assert( ! TemplateRegistry::register( $invalid ), 'empty subject rejected' );
$invalid = $provider;
$invalid['id'] = 'bookings.overlong-subject';
$invalid['document']['fields']['subject'] = str_repeat( 'x', 256 );
cb_mail_ext_assert( ! TemplateRegistry::register( $invalid ), 'subject length enforced' );
$invalid = $provider;
$invalid['id'] = 'wordpress.impostor';
cb_mail_ext_assert( ! TemplateRegistry::register( $invalid ), 'reserved WordPress namespace rejected' );
$invalid = $provider;
$invalid['id'] = 'other.example';
cb_mail_ext_assert( ! TemplateRegistry::register( $invalid ), 'provider namespace mismatch rejected' );
$invalid = $provider;
unset( $invalid['document'] );
$invalid['project'] = $default['project'];
$invalid['subject'] = $default['fields']['subject'];
cb_mail_ext_assert( ! TemplateRegistry::register( $invalid ), 'legacy project-only registration rejected' );
cb_mail_ext_assert( null === TemplateRegistry::default_document( 'bookings.unknown' ),
	'unknown resource has no generic default' );
cb_mail_ext_assert( null === PlatformAdapter::default_for_resource( 'bookings.unknown' ),
	'editor unknown resource has no generic default' );
cb_mail_ext_assert( 7 === count( TemplateRegistry::all() ), 'invalid registrations changed nothing' );

echo "Base MAIL-EXT-01A native template provider contract regression: PASS\n";
