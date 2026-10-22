<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Reports;

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Reports\Composer\NativeComposition;

defined( 'ABSPATH' ) || exit;

/**
 * The one authoritative native Reports design read, shared by PDF and preview.
 * The Foundation owns ALL saves and the document transaction. This adapter
 * never falls back to Settings/Composer or silently repairs an invalid project.
 */
final class DesignDocumentAdapter {
	public const KEY = 'maintenance';

	/** @return array{revision:string,project:array<string,mixed>,fields:array<string,mixed>} */
	public static function current(): array {
		$read = self::read();
		if ( 'found' !== ( $read['status'] ?? null ) ) {
			throw new \RuntimeException( 'REPORTS_DESIGN_UNAVAILABLE' );
		}
		$document = $read['document'];
		return [
			'revision' => $read['revision'],
			'project' => $document['project'],
			'fields' => $document['fields'],
		];
	}

	/** @return array<string,mixed> */
	public static function read(): array {
		$result = Repository::get( Repository::REPORTS, self::KEY );
		if ( 'found' !== ( $result['status'] ?? null ) ) {
			return [ 'status' => $result['status'] ?? 'blocked' ];
		}
		return NativeComposition::valid_document( $result['document'] ?? null )
			? $result : [ 'status' => 'corrupt' ];
	}

	/**
	 * Historical Composer transport has no write authority for native designs.
	 * All modifications must traverse the Foundation's TransactionalSave.
	 */
	public static function save( string $expected, array $branding, array $composer ): array {
		return [ 'status' => 'blocked' ];
	}

	public static function reset( string $expected ): array {
		return [ 'status' => 'blocked' ];
	}

	public static function valid_document( mixed $document ): bool {
		return NativeComposition::valid_document( $document );
	}

	private function __construct() {}
}
