<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/** Public metadata only: never workflow/document payloads or authority. */
final class TemplateMetadata {
	public readonly string $source;
	public readonly string $extension;
	public readonly string $designer;
	public readonly string $id;
	public readonly string $title;
	public readonly string $description;
	public readonly string $origin;
	public readonly string $category;
	public readonly string $locale;
	public readonly string $version;
	public readonly bool $compatible;

	public function __construct(
		string $source,
		string $extension,
		string $designer,
		string $id,
		string $title,
		string $description,
		string $origin,
		string $category,
		string $locale,
		string $version,
		bool $compatible = true
	) {
		foreach ( [ $source, $extension, $designer, $id, $category ] as $identifier ) {
			if ( ! TemplateQuery::valid_id( $identifier ) ) {
				throw new \InvalidArgumentException( 'Invalid template metadata identity.' );
			}
		}
		$title = trim( wp_strip_all_tags( $title ) );
		$description = trim( wp_strip_all_tags( $description ) );
		if ( '' === $title || strlen( $title ) > 191 || strlen( $description ) > 500 ) {
			throw new \InvalidArgumentException( 'Invalid template display metadata.' );
		}
		if ( ! in_array( $origin, [ 'built-in', 'personal', 'site', 'installed-pack' ], true )
			|| ! preg_match( '/^[a-z]{2}(?:_[A-Z]{2})?$/D', $locale )
			|| 1 !== preg_match( '/^[1-9][0-9]*(?:\.[0-9]+){0,2}$/D', $version ) ) {
			throw new \InvalidArgumentException( 'Invalid template metadata provenance.' );
		}
		$this->source = $source;
		$this->extension = $extension;
		$this->designer = $designer;
		$this->id = $id;
		$this->title = $title;
		$this->description = $description;
		$this->origin = $origin;
		$this->category = $category;
		$this->locale = $locale;
		$this->version = $version;
		$this->compatible = $compatible;
	}
}
