<?php
declare(strict_types=1);
/**
 * Canonical v1 contract for responsive HTML email designs.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Design\Profile\Mail;

defined( 'ABSPATH' ) || exit;

final class Contract {
	public const DESIGN_TYPE = 'mail-template';
	public const ROOT_TYPE = 'mail.root';
	public const CORE_PROVIDER = 'core';
	public const WIDTH_MIN = 320;
	public const WIDTH_MAX = 800;
	public const WIDTH_DEFAULT = 600;

	public const CORE_NODE_TYPES = [
		'mail.root',
		'mail.section',
		'mail.heading',
		'mail.text',
		'mail.button',
		'mail.image',
		'mail.divider',
		'mail.spacer',
	];

	public const FONT_FAMILIES = [
		'Arial, Helvetica, sans-serif',
		'Helvetica, Arial, sans-serif',
		'Georgia, Times New Roman, serif',
		'Tahoma, Verdana, sans-serif',
		'Verdana, Geneva, sans-serif',
	];

	private function __construct() {}
}
