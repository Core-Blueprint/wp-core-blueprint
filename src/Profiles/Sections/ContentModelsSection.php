<?php
declare(strict_types=1);

namespace CB\Core\Profiles\Sections;

use CB\Core\ContentModels\Repository;
use CB\Core\ContentModels\SchemaTransfer;
use CB\Core\Profiles\CanonicalJson;
use CB\Core\Profiles\Diff;
use CB\Core\Profiles\SectionInterface;
use CB\Core\Profiles\SchemaGuard;

defined( 'ABSPATH' ) || exit;

final class ContentModelsSection implements SectionInterface {
	private const MAPS = [ 'post_types', 'taxonomies', 'option_pages', 'field_groups' ];

	public function id(): string { return 'content-models'; }
	public function label(): string { return __( 'Content Models schema', 'core-blueprint' ); }
	public function description(): string { return __( 'Post types, taxonomies, Option Pages and field schemas. Content values and site content are not included.', 'core-blueprint' ); }
	public function schema_version(): int { return 1; }

	public function supports_schema_version( int $schema_version ): bool { return 1 === $schema_version; }
	public function migrate( array $incoming, int $source_schema_version ): array {
		if ( ! $this->supports_schema_version( $source_schema_version ) ) {
			throw new \InvalidArgumentException( __( 'This Content Models Profile section schema version is not supported.', 'core-blueprint' ) );
		}
		return $this->normalize( $incoming );
	}

	public function export(): array {
		$data = Repository::all();
		return [
			'content_models_schema_version' => Repository::SCHEMA_VERSION,
			'post_types'   => $data['post_types'],
			'taxonomies'   => $data['taxonomies'],
			'option_pages'  => $data['option_pages'],
			'field_groups' => $data['field_groups'],
		];
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'content_models_schema_version', 'post_types', 'taxonomies', 'option_pages', 'field_groups' ], 'Content Models' );
		$schema_version = SchemaGuard::int( $incoming['content_models_schema_version'] ?? null, 'Content Models schema version' );
		if ( Repository::SCHEMA_VERSION !== $schema_version ) {
			throw new \InvalidArgumentException( __( 'The Content Models schema version in this profile is not supported by this Base version.', 'core-blueprint' ) );
		}
		$portable = [
			'content_models_schema_version' => $schema_version,
			'post_types'   => SchemaGuard::object( $incoming['post_types'] ?? null, 'Content Models post types' ),
			'taxonomies'   => SchemaGuard::object( $incoming['taxonomies'] ?? null, 'Content Models taxonomies' ),
			'option_pages'  => SchemaGuard::object( $incoming['option_pages'] ?? null, 'Content Models Option Pages' ),
			'field_groups' => SchemaGuard::object( $incoming['field_groups'] ?? null, 'Content Models field groups' ),
		];
		$wrapped = [
			'format'         => 'core-blueprint-content-models',
			'format_version' => 1,
			'schema_version' => Repository::SCHEMA_VERSION,
			'post_types'     => $portable['post_types'],
			'taxonomies'     => $portable['taxonomies'],
			'option_pages'    => $portable['option_pages'],
			'field_groups'   => $portable['field_groups'],
		];
		$normalized = SchemaTransfer::decode( CanonicalJson::encode( $wrapped ) );
		$canonical = [
			'content_models_schema_version' => Repository::SCHEMA_VERSION,
			'post_types'   => $normalized['post_types'],
			'taxonomies'   => $normalized['taxonomies'],
			'option_pages'  => $normalized['option_pages'],
			'field_groups' => $normalized['field_groups'],
		];
		if ( CanonicalJson::encode( $canonical ) !== CanonicalJson::encode( $portable ) ) {
			SchemaGuard::invalid_value( 'Content Models schema' );
		}
		return $canonical;
	}

	public function preflight( array $incoming, array $current ): void {
		unset( $current );
		$incoming = $this->normalize( $incoming );
		$analysis = SchemaTransfer::analyze( [
			'post_types' => $incoming['post_types'],
			'taxonomies' => $incoming['taxonomies'],
			'option_pages' => $incoming['option_pages'],
			'field_groups' => $incoming['field_groups'],
		] );
		if ( ! empty( $analysis['locked'] ) ) {
			throw new \InvalidArgumentException( __( 'The profile contains Content Models definitions owned and locked by another plugin.', 'core-blueprint' ) );
		}
	}

	public function warnings( array $incoming ): array {
		$incoming = $this->normalize( $incoming );
		$analysis = SchemaTransfer::analyze( [
			'post_types'   => $incoming['post_types'],
			'taxonomies'   => $incoming['taxonomies'],
			'option_pages'  => $incoming['option_pages'],
			'field_groups' => $incoming['field_groups'],
		] );
		if ( empty( $analysis['conflicts'] ) ) {
			return [];
		}
		$count = count( $analysis['conflicts'] );
		return [ sprintf(
			_n( '%d existing Content Model definition will be updated.', '%d existing Content Model definitions will be updated.', $count, 'core-blueprint' ),
			$count
		) ];
	}

	public function snapshot(): array {
		return Repository::all();
	}

	public function preview( array $current, array $incoming ): array {
		$projected = [ 'content_models_schema_version' => Repository::SCHEMA_VERSION ];
		foreach ( self::MAPS as $map ) {
			$projected[ $map ] = [];
			foreach ( array_keys( $incoming[ $map ] ) as $key ) {
				$projected[ $map ][ $key ] = $current[ $map ][ $key ] ?? null;
			}
		}
		return Diff::between( $projected, $incoming );
	}

	public function apply( array $incoming, string $actor ): void {
		unset( $actor );
		$incoming = $this->normalize( $incoming );
		$document = [
			'schema_version' => Repository::SCHEMA_VERSION,
			'post_types'     => $incoming['post_types'],
			'taxonomies'     => $incoming['taxonomies'],
			'option_pages'    => $incoming['option_pages'],
			'field_groups'   => $incoming['field_groups'],
		];
		SchemaTransfer::import( $document, true );
	}

	public function restore( array $snapshot, array $incoming, string $actor ): void {
		unset( $actor );
		Repository::restore_profile_schema( $snapshot, $this->normalize( $incoming ) );
	}

	public function verify( array $incoming ): bool {
		$current = Repository::all();
		foreach ( self::MAPS as $map ) {
			foreach ( $incoming[ $map ] as $key => $definition ) {
				if ( ! isset( $current[ $map ][ $key ] ) || $current[ $map ][ $key ] !== $definition ) {
					return false;
				}
			}
		}
		return true;
	}
}
