<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/** Immutable bounded metadata query. Consumers implement filtering before paging. */
final class TemplateQuery {
	public readonly string $search;
	public readonly string $source;
	public readonly string $extension;
	public readonly string $designer;
	public readonly string $origin;
	public readonly string $category;
	public readonly string $locale;
	public readonly int $limit;

	public function __construct(
		string $search = '',
		string $source = '',
		string $extension = '',
		string $designer = '',
		string $origin = '',
		string $category = '',
		string $locale = '',
		int $limit = 24
	) {
		$search = trim( $search );
		if ( strlen( $search ) > 120 || preg_match( '/[\x00-\x1f\x7f]/', $search ) ) {
			throw new \InvalidArgumentException( 'Invalid template search.' );
		}
		foreach ( [ $source, $extension, $designer, $category ] as $identifier ) {
			if ( '' !== $identifier && ! self::valid_id( $identifier ) ) {
				throw new \InvalidArgumentException( 'Invalid template query identifier.' );
			}
		}
		if ( '' !== $origin && ! in_array( $origin, [ 'built-in', 'personal', 'site', 'installed-pack' ], true ) ) {
			throw new \InvalidArgumentException( 'Invalid template origin.' );
		}
		if ( '' !== $locale && ! preg_match( '/^[a-z]{2}(?:_[A-Z]{2})?$/D', $locale ) ) {
			throw new \InvalidArgumentException( 'Invalid template locale.' );
		}
		if ( $limit < 1 || $limit > 48 ) {
			throw new \InvalidArgumentException( 'Template query exceeds the page limit.' );
		}
		$this->search = $search;
		$this->source = $source;
		$this->extension = $extension;
		$this->designer = $designer;
		$this->origin = $origin;
		$this->category = $category;
		$this->locale = $locale;
		$this->limit = $limit;
	}

	public static function valid_id( string $id ): bool {
		return strlen( $id ) <= 96 && 1 === preg_match( '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $id );
	}

	public function with_limit( int $limit ): self {
		return new self( $this->search, $this->source, $this->extension, $this->designer, $this->origin, $this->category, $this->locale, $limit );
	}

	public function fingerprint(): string {
		return hash( 'sha256', json_encode( [ $this->search, $this->source, $this->extension, $this->designer, $this->origin, $this->category, $this->locale, $this->limit ], JSON_THROW_ON_ERROR ) );
	}
}
