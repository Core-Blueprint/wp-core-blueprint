<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use CoreBlueprint\Core\Integrity\Support\ResultFormatter;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository;

defined( 'ABSPATH' ) || exit;

final class Summary {
	public static function latest(): array {
		$result  = ResultRepository::getLatest();
		$summary = ResultFormatter::summary( $result );

		return [
			'status'     => $summary['status'],
			'last_scan'  => $summary['last_scan'],
			'scan_type'  => $summary['source'],
			'findings'   => $summary['summary'],
			'components' => $summary['components'],
			'completion' => $summary['completion'],
			'coverage'   => $summary['coverage'],
		];
	}
}
