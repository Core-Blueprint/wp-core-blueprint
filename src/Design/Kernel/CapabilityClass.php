<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

enum CapabilityClass: string {
	case Presentation = 'presentation';
	case Data = 'data';
	case Requirement = 'requirement';
}
