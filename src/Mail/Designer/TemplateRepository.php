<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Design\Document\Authority;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Profile\Mail\Validator;

defined( 'ABSPATH' ) || exit;

/** Typed integrity refusal; never silently substitute defaults for stored failures. */
final class DocumentUnavailable extends \RuntimeException {
	public function __construct( public readonly string $reason ) {
		parent::__construct( 'Native Mail Designer document is unavailable.' );
	}
}

/**
 * Read-only presentation gateway. All native writes and revision receipts belong
 * exclusively to Designer Platform v1 Save Authority, not this Mail subsystem.
 */
final class TemplateRepository {
	/** Never read or write this historical option in the native route. */
	public const OPTION = 'cb_core_mail_template_overrides';

	public static function get( string $template_id ): ?array {
		$definition = TemplateRegistry::get( $template_id );
		if ( ! is_array( $definition ) ) return null;
		if ( 'active' !== ( Authority::inspect()['status'] ?? 'blocked' ) ) {
			throw new DocumentUnavailable( 'blocked' );
		}
		$read = Repository::get( Repository::MAIL, $template_id );
		$status = (string) ( $read['status'] ?? 'unavailable' );
		if ( 'found' === $status ) {
			$document = $read['document'] ?? null;
			$revision = $read['revision'] ?? null;
		} elseif ( 'missing' === $status ) {
			// The same per-resource native default must be used by the context
			// loader for a missing row. Never create or overwrite a row here.
			$document = WordPressTemplates::default_document( $template_id );
			$revision = '0';
		} else {
			throw new DocumentUnavailable( in_array( $status, [ 'corrupt', 'unsupported', 'unavailable', 'invalid', 'error' ], true )
				? ( 'unsupported' === $status ? 'invalid' : $status ) : 'blocked' );
		}
		$project = is_array( $document ) ? ( $document['project'] ?? null ) : null;
		$fields = is_array( $document ) ? ( $document['fields'] ?? null ) : null;
		if ( ! is_array( $project ) || ! is_array( $fields )
			|| count( $fields ) !== 2 || ! array_key_exists( 'subject', $fields )
			|| ! array_key_exists( 'preheader', $fields )
			|| ! is_string( $fields['subject'] ) || '' === trim( $fields['subject'] )
			|| ! is_string( $fields['preheader'] )
			|| ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $fields['subject'], 'UTF-8' )
				: preg_match_all( '/./us', $fields['subject'] ) ) ?: ( '' === $fields['subject'] ? 0 : 256 ) ) > 255
			|| ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $fields['preheader'], 'UTF-8' )
				: preg_match_all( '/./us', $fields['preheader'] ) ) ?: ( '' === $fields['preheader'] ? 0 : 501 ) ) > 500
			|| ( new Validator() )->validate( $project )->has_errors() ) {
			throw new DocumentUnavailable( 'invalid' );
		}
		return array_merge( $definition, [
			'project' => $project, 'fields' => $fields,
			'subject' => $fields['subject'], 'preheader' => $fields['preheader'],
			'revision' => $revision, 'authority' => 'native',
		] );
	}

	/** Legacy Admin mutations are permanently non-authoritative. */
	public static function mutate( string $template_id, string $expected, string $subject, array $project ): array {
		return [ 'status' => 'blocked' ];
	}
	public static function reset_revisioned( string $template_id, string $expected ): array {
		return [ 'status' => 'blocked' ];
	}
	public static function save( string $template_id, string $subject, array $project ): bool { return false; }
	public static function reset( string $template_id ): bool { return false; }
	public static function overrides(): array { return []; }

	private function __construct() {}
}
