<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\DataExchange\EntityInterface;
use CoreBlueprint\Core\DataExchange\Foundation;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Editor\PlatformTransport;
use CoreBlueprint\Core\Mail\DesignerState;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Mail-owned, asset-free portability adapter for reusable snapshots.
 * Operational Mail resources and Designer Save are never imported or mutated.
 */
final class SavedTemplatePortability implements EntityInterface {
	public const ENTITY = 'template.mail';
	private const SCHEMA = 1;

	public function is_available(): bool {
		return DesignerState::is_enabled() && SavedTemplateSchema::healthy();
	}

	public function schema_version(): int { return self::SCHEMA; }
	public function supports_schema_version( int $schema_version ): bool {
		return self::SCHEMA === $schema_version;
	}

	private static function actor(): int {
		$actor = get_current_user_id();
		return $actor > 0 && is_user_logged_in() && current_user_can( 'manage_options' )
			&& DesignerState::is_enabled() ? $actor : 0;
	}

	private static function destination( array $context ): ?string {
		$id = $context['destination_id'] ?? null;
		return is_string( $id ) && SavedTemplateStore::valid_id( $id ) ? $id : null;
	}

	public function can_export( array $context = [] ): bool {
		return self::actor() > 0
			&& isset( $context['source_id'], $context['revision'] )
			&& is_string( $context['source_id'] ) && SavedTemplateStore::valid_id( $context['source_id'] )
			&& is_int( $context['revision'] ) && $context['revision'] > 0;
	}

	public function can_import( array $context = [] ): bool {
		return self::actor() > 0 && self::destination( $context ) !== null
			&& ( $context['intent'] ?? null ) === 'create_personal_copy';
	}

	/** JSON T4 carries NO media package. Reject any embedded asset URL. */
	private static function asset_free( array $document ): bool {
		$root = $document['project']['root'] ?? null;
		if ( ! is_array( $root ) ) return false;
		$stack = [ $root ];
		$count = 0;
		while ( $stack ) {
			$node = array_pop( $stack );
			if ( ! is_array( $node ) || ++$count > 10000 ) return false;
			$type = $node['type'] ?? '';
			$props = $node['properties'] ?? [];
			if ( 'mail.image' === $type && '' !== trim( (string) ( $props['url'] ?? '' ) ) ) return false;
			if ( 'mail.rich-text' === $type ) {
				$markup = (string) ( $props['markup'] ?? '' );
				if ( preg_match( '/<(?:img|video|audio|source|iframe|object|embed)\b|\bsrc(?:set)?\s*=|url\s*\(/i', $markup ) ) return false;
			}
			foreach ( $node['children'] ?? [] as $child ) $stack[] = $child;
		}
		return true;
	}

	private static function portable_id( string $title, array $document ): ?string {
		$json = wp_json_encode( [ 'title' => $title, 'document' => $document ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? 'mail.' . substr( hash( 'sha256', $json ), 0, 48 ) : null;
	}

	public function export_records( array $context = [] ): iterable|WP_Error {
		if ( ! $this->can_export( $context ) ) {
			return new WP_Error( 'cb_mail_portability_refused', 'Mail template export is not authorized.' );
		}
		$item = SavedTemplateStore::find( $context['source_id'], self::actor() );
		if ( 'found' !== ( $item['status'] ?? '' ) || $item['revision'] !== $context['revision'] ) {
			return new WP_Error( 'cb_mail_portability_stale', 'The selected Mail template is unavailable or has changed.' );
		}
		if ( ! self::asset_free( $item['document'] ) ) {
			return new WP_Error( 'cb_mail_portability_media_unsupported', 'This Mail template requires media packaging, which JSON export does not provide.' );
		}
		$id = self::portable_id( $item['title'], $item['document'] );
		if ( null === $id ) return new WP_Error( 'cb_mail_portability_invalid', 'Cannot encode Mail template.' );
		return [ [
			'portable_id' => $id,
			'designer_type' => 'mail',
			'document_schema_version' => 1,
			'template_version' => '1',
			'title' => $item['title'],
			'description' => '',
			'locale' => 'en',
			'category' => 'saved',
			'resources' => [],
			'document' => $item['document'],
		] ];
	}

	private static function exact( array $value, array $keys ): bool {
		if ( array_is_list( $value ) ) return false;
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		return $actual === $keys;
	}

	private static function valid_record( array $record ): bool {
		if ( ! self::exact( $record, [
			'portable_id', 'designer_type', 'document_schema_version',
			'template_version', 'title', 'description', 'locale', 'category',
			'resources', 'document',
		] ) || ! is_string( $record['portable_id'] )
			|| $record['designer_type'] !== 'mail'
			|| $record['document_schema_version'] !== 1
			|| $record['template_version'] !== '1'
			|| ! is_string( $record['title'] ) || ! SavedTemplateStore::valid_title( $record['title'] )
			|| $record['description'] !== '' || $record['locale'] !== 'en'
			|| $record['category'] !== 'saved' || $record['resources'] !== []
			|| ! is_array( $record['document'] )
			|| ! PlatformTransport::valid_native_document( Repository::MAIL, $record['document'] )
			|| ! self::asset_free( $record['document'] )
		) return false;
		// Source-local row IDs, site IDs, privileges and media references never
		// become a portable identity or privileged target on the receiving site.
		return self::portable_id( $record['title'], $record['document'] ) === $record['portable_id'];
	}

	public function plan_import( array $record, string $mode, int $source_schema_version, array $context = [] ): array|WP_Error {
		if ( Foundation::MODE_CREATE_ONLY !== $mode || ! $this->supports_schema_version( $source_schema_version )
			|| ! $this->can_import( $context ) || ! self::valid_record( $record ) ) {
			return new WP_Error( 'cb_mail_portability_invalid', 'The Mail template import is not valid or authorized.' );
		}
		$destination = self::destination( $context );
		$found = SavedTemplateStore::find( $destination, self::actor() );
		if ( 'missing' !== $found['status'] ) {
			return new WP_Error( 'cb_mail_portability_collision', 'The destination Mail template is unavailable or already exists.' );
		}
		return [
			'operation' => Foundation::OP_CREATE,
			'reference' => $destination,
			'payload' => [
				'id' => $destination,
				'title' => $record['title'],
				'document' => $record['document'],
				'actor' => self::actor(),
				'visibility' => 'personal',
				'portable_id' => $record['portable_id'],
			],
			'warnings' => [],
		];
	}

	public function apply_import( array $plan, array $context = [] ): array|WP_Error {
		$payload = $plan['payload'] ?? null;
		$id = self::destination( $context );
		$actor = self::actor();
		if ( 0 === $actor || null === $id || ! $this->can_import( $context )
			|| ( $plan['operation'] ?? null ) !== Foundation::OP_CREATE
			|| ( $plan['reference'] ?? null ) !== $id
			|| ! is_array( $payload ) || ! self::exact( $payload, [
				'id', 'title', 'document', 'actor', 'visibility', 'portable_id',
			] )
			|| $payload['id'] !== $id || $payload['actor'] !== $actor
			|| $payload['visibility'] !== 'personal'
			|| ! is_string( $payload['title'] ) || ! SavedTemplateStore::valid_title( $payload['title'] )
			|| ! is_array( $payload['document'] )
			|| ! PlatformTransport::valid_native_document( Repository::MAIL, $payload['document'] )
			|| ! self::asset_free( $payload['document'] )
			|| ! is_string( $payload['portable_id'] )
			|| self::portable_id( $payload['title'], $payload['document'] ) !== $payload['portable_id']
		) return new WP_Error( 'cb_mail_portability_refused', 'Mail template import authority changed.' );

		// Domain-owned canonical personal asset writer, unique PK, never upsert.
		$result = SavedTemplateStore::create_imported_personal( $id, $payload['title'], $actor, $payload['document'] );
		if ( 'created' !== $result['status'] ) {
			return new WP_Error( 'cb_mail_portability_write_failed', 'Mail template import could not be confirmed.' );
		}
		return [ 'reference' => $id ];
	}
}
