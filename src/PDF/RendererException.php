<?php
declare(strict_types=1);
/**
 * RendererException
 *
 * Thrown by CoreBlueprint\Core\PDF\Renderer when the underlying engine cannot produce a
 * PDF. Wraps any Throwable raised by Dompdf so callers only have to catch a
 * single exception type from the CB namespace.
 *
 * Common causes:
 *   - Dompdf vendor library missing (see Renderer::is_available())
 *   - Malformed HTML that the layout engine cannot parse
 *   - Memory exhaustion on very large documents
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\PDF;

defined( 'ABSPATH' ) || exit;

final class RendererException extends \RuntimeException {}
