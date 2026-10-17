<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Design\Document\Authority;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Profile\Mail\Validator;

defined( 'ABSPATH' ) || exit;

/** @internal Site-bound, registered-template Mail document CAS adapter. */
final class DocumentAdapter {

	/** @return array<string,mixed> */
	public static function read( string $template_id, array $definition, array $authority ): array {
		if ( 'active' !== ( $authority['status'] ?? '' ) || ! Repository::valid_identity( Repository::MAIL, $template_id )
			|| $template_id !== ( $definition['id'] ?? null ) ) {
			return [ 'status' => 'blocked' ];
		}
		$existing = Repository::get( Repository::MAIL, $template_id );
		if ( 'found' === $existing['status'] ) {
			$document = $existing['document'];
			if ( ! self::valid_project( $document['project'] ?? null ) ) {
				return [ 'status' => 'corrupt' ];
			}
			return $existing;
		}
		if ( 'missing' !== $existing['status'] ) {
			return $existing;
		}
		if ( ! Authority::uncustomized_mail_id( $template_id, $authority ) ) {
			return [ 'status' => 'blocked' ];
		}
		$default = self::default_document( $definition );
		return null === $default
			? [ 'status' => 'invalid' ]
			: [ 'status' => 'default', 'revision' => '0', 'document' => $default ];
	}

	/** @return array<string,mixed> */
	public static function save(
		string $template_id,
		array $definition,
		array $authority,
		string $expected,
		string $subject,
		array $project
	): array {
		if ( ! Repository::valid_revision( $expected ) || '' === trim( $subject )
			|| $subject !== sanitize_text_field( $subject )
			|| ! self::valid_project( $project ) ) {
			return [ 'status' => 'invalid' ];
		}
		$current = self::read( $template_id, $definition, $authority );
		if ( ! in_array( $current['status'], [ 'found', 'default' ], true ) ) {
			return $current;
		}
		if ( $expected !== $current['revision'] ) {
			return [ 'status' => 'conflict' ];
		}
		$document = $current['document'];
		$document['subject'] = $subject;
		$document['project'] = $project;
		$document['base_fingerprint'] = self::provider_fingerprint( $definition );
		$document['modified_at'] = gmdate( 'Y-m-d H:i:s' );
		$document['customized'] = true;
		return 'default' === $current['status']
			? Repository::create( Repository::MAIL, $template_id, '0', $document )
			: Repository::save( Repository::MAIL, $template_id, $expected, $document );
	}

	/** Reset keeps a versioned row so stale saves cannot resurrect old edits. */
	public static function reset( string $template_id, array $definition, array $authority, string $expected ): array {
		if ( ! Repository::valid_revision( $expected ) ) {
			return [ 'status' => 'invalid' ];
		}
		$current = self::read( $template_id, $definition, $authority );
		if ( ! in_array( $current['status'], [ 'found', 'default' ], true ) ) {
			return $current;
		}
		if ( $expected !== $current['revision'] ) {
			return [ 'status' => 'conflict' ];
		}
		if ( 'default' === $current['status'] ) {
			return [ 'status' => 'saved', 'revision' => '0', 'document' => $current['document'] ];
		}
		$document = $current['document'];
		$document['subject'] = (string) $definition['subject'];
		$document['project'] = $definition['project'];
		$document['customized'] = false;
		$document['base_fingerprint'] = self::provider_fingerprint( $definition );
		$document['modified_at'] = gmdate( 'Y-m-d H:i:s' );
		return Repository::reset( Repository::MAIL, $template_id, $expected, $document );
	}

	private static function valid_project( mixed $project ): bool {
		return is_array( $project ) && ! array_is_list( $project )
			&& ! ( new Validator() )->validate( $project )->has_errors();
	}

	private static function default_document( array $definition ): ?array {
		if ( ! isset( $definition['subject'], $definition['project'] )
			|| ! is_string( $definition['subject'] )
			|| '' === trim( $definition['subject'] )
			|| ! self::valid_project( $definition['project'] ) ) {
			return null;
		}
		return [
			'subject' => $definition['subject'],
			'project' => $definition['project'],
			'base_fingerprint' => self::provider_fingerprint( $definition ),
			'customized' => false,
		];
	}

	private static function provider_fingerprint( array $definition ): string {
		$json = wp_json_encode( [
			'subject' => (string) ( $definition['subject'] ?? '' ),
			'project' => $definition['project'] ?? [],
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	private function __construct() {}
}
