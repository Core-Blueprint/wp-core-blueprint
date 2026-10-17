<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Reports;

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Reports\Composer\MaintenanceTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * @internal One canonical revision for complete Reports branding + Composer.
 * A missing active singleton is never a valid default and never self-heals.
 */
final class DesignDocumentAdapter {
	public const KEY = 'maintenance';

	/** @return array<string,mixed> */
	public static function read(): array {
		$result = Repository::get( Repository::REPORTS, self::KEY );
		if ( 'found' !== ( $result['status'] ?? '' ) ) {
			return [ 'status' => 'missing' === ( $result['status'] ?? '' ) ? 'blocked' : ( $result['status'] ?? 'blocked' ) ];
		}
		return self::valid_document( $result['document'] )
			? $result
			: [ 'status' => 'corrupt' ];
	}

	/** @return array<string,mixed> */
	public static function save( string $expected, array $branding, array $composer ): array {
		if ( ! Repository::valid_revision( $expected, false ) ) {
			return [ 'status' => 'invalid' ];
		}
		$current = self::read();
		if ( 'found' !== $current['status'] ) {
			return $current;
		}
		if ( $expected !== $current['revision'] ) {
			return [ 'status' => 'conflict' ];
		}
		$document = [ 'branding' => $branding, 'composer' => [ 'maintenance' => $composer ] ];
		if ( ! self::valid_document( $document ) ) {
			return [ 'status' => 'invalid' ];
		}
		return Repository::save( Repository::REPORTS, self::KEY, $expected, $document );
	}

	/** @return array<string,mixed> */
	public static function reset( string $expected ): array {
		if ( ! Repository::valid_revision( $expected, false ) ) {
			return [ 'status' => 'invalid' ];
		}
		$current = self::read();
		if ( 'found' !== $current['status'] ) {
			return $current;
		}
		if ( $expected !== $current['revision'] ) {
			return [ 'status' => 'conflict' ];
		}
		$document = [
			'branding' => ReportBranding::settings_defaults(),
			'composer' => [ 'maintenance' => MaintenanceTemplate::defaults() ],
		];
		return Repository::reset( Repository::REPORTS, self::KEY, $expected, $document );
	}

	/** Strict lossless state: never drop unknown settings/Composer content. */
	public static function valid_document( mixed $document ): bool {
		if ( ! is_array( $document )
			|| ! Repository::valid_document( Repository::REPORTS, $document ) ) {
			return false;
		}
		$branding = $document['branding'];
		$composer = $document['composer'];
		if ( ! is_array( $branding ) || ! is_array( $composer )
			|| ! array_key_exists( 'maintenance', $composer )
			|| 1 !== count( $composer )
			|| ! is_array( $composer['maintenance'] ) ) {
			return false;
		}
		try {
			if ( ReportBrandingInput::normalize( $branding ) !== $branding
				|| MaintenanceTemplate::normalize( $composer['maintenance'] ) !== $composer['maintenance'] ) {
				return false;
			}
		} catch ( \Throwable $error ) {
			return false;
		}
		return true;
	}

	private function __construct() {}
}
