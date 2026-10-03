<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Fixed;

defined( 'ABSPATH' ) || exit;

final class Contract {
	public const LAYOUT_MODE = 'fixed';
	public const UNITS = 'mm';
	public const ROOT_LAYOUT_KEY = 'layout';
	public const NODE_FRAME_KEY = 'frame';

	public const LAYOUT_KEYS = [ 'mode', 'units', 'page' ];
	public const PAGE_KEYS = [ 'width', 'height' ];
	public const FRAME_KEYS = [ 'x', 'y', 'width', 'height' ];

	private function __construct() {}
}
