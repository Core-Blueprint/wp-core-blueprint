<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Flow;

defined( 'ABSPATH' ) || exit;

final class Contract {
	public const LAYOUT_MODE = 'flow';
	public const UNITS = 'mm';
	public const ROOT_LAYOUT_KEY = 'layout';
	public const NODE_FRAME_KEY = 'frame';
	public const NODE_FLOW_KEY = 'flow';

	public const LAYOUT_KEYS = [ 'mode', 'units', 'page', 'margins' ];
	public const PAGE_KEYS = [ 'width', 'height' ];
	public const MARGIN_KEYS = [ 'top', 'right', 'bottom', 'left' ];
	public const FLOW_KEYS = [ 'space_before', 'space_after', 'break_before', 'break_after', 'keep_together' ];

	private function __construct() {}
}
