<?php
declare(strict_types=1);
/**
 * PDF Renderer
 *
 * Thin, security-hardened wrapper around the vendored Dompdf library. Renders
 * HTML to an in-memory PDF binary; permanent file storage is intentionally not
 * part of this API.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\PDF;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	const VENDOR_AUTOLOAD = '/src/PDF/lib/dompdf/autoload.inc.php';

	private const EXPECTED_DOMPDF_VERSION = '3.1.6';
	private const REQUIRED_EXTENSIONS      = [ 'dom', 'mbstring' ];
	private const POINTS_PER_INCH          = 72.0;
	private const MILLIMETRES_PER_INCH     = 25.4;
	private const MAX_CUSTOM_PAPER_MM      = 2000.0;

	/*
	 * Third-party PDF dependencies are vendored verbatim. Do not apply Core
	 * Blueprint strict-types, formatting or hardening transforms inside vendor/.
	 * Security policy belongs in this wrapper and in validated report inputs.
	 */

	/** @var array<string,mixed> */
	private array $defaults;

	/**
	 * @param array $options {
	 *     @type string $paper_size        Named paper size, e.g. A4 or Letter.
	 *     @type array  $paper_size_mm     Optional final [width,height] in millimetres.
	 *     @type string $orientation       portrait|landscape for named paper sizes.
	 *     @type string $default_font      Default font family.
	 *     @type bool   $is_html5_parser   Use Dompdf's HTML5 parser.
	 * }
	 */
	public function __construct( array $options = [] ) {
		$this->defaults = array_merge(
			[
				'paper_size'      => 'A4',
				'orientation'     => 'portrait',
				'default_font'    => 'DejaVu Sans',
				'is_html5_parser' => true,
			],
			$options
		);
	}

	public static function is_available(): bool {
		if ( ! file_exists( CB_CORE_DIR . ltrim( self::VENDOR_AUTOLOAD, '/' ) ) ) {
			return false;
		}

		$version_file = CB_CORE_DIR . 'src/PDF/lib/dompdf/vendor/dompdf/dompdf/VERSION';
		$version      = is_readable( $version_file ) ? trim( (string) file_get_contents( $version_file ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( self::EXPECTED_DOMPDF_VERSION !== $version ) {
			return false;
		}

		foreach ( self::REQUIRED_EXTENSIONS as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				return false;
			}
		}

		if ( class_exists( '\Dompdf\Dompdf', false ) && ! self::is_own_dompdf_loaded() ) {
			return false;
		}

		return true;
	}

	/**
	 * Render a complete HTML document to a PDF binary.
	 *
	 * Remote resources, embedded PHP and embedded JavaScript are always disabled
	 * and cannot be re-enabled through caller options.
	 *
	 * @throws RendererException When rendering fails.
	 */
	public function render( string $html, array $options = [] ): string {
		$opts = array_merge( $this->defaults, $options );
		[ $paper_size, $orientation ] = $this->paper_contract( $opts );
		$this->require_engine();

		$font_cache = null;
		try {
			// Dompdf otherwise writes derived font metrics into its vendor tree.
			// Keep each render's cache private and outside the installed plugin.
			$cache_directory = rtrim( get_temp_dir(), "/\\" ) . '/cb-pdf-fonts-' . bin2hex( random_bytes( 16 ) );
			if ( ! mkdir( $cache_directory, 0700 ) ) {
				throw new RendererException( 'Could not create the PDF font cache.' );
			}
			$font_cache = $cache_directory;
			$dompdf_options = $this->build_dompdf_options( $opts );
			$dompdf_options->setFontCache( $font_cache );
			$dompdf = new \Dompdf\Dompdf( $dompdf_options );
			$dompdf->setPaper( $paper_size, $orientation );
			$dompdf->loadHtml( $html );
			$dompdf->render();
			$output = $dompdf->output();
		} catch ( \Throwable $e ) {
			throw new RendererException(
				'PDF rendering failed: ' . $e->getMessage(),
				(int) $e->getCode(),
				$e
			);
		} finally {
			if ( null !== $font_cache ) {
				// Dompdf's derived metric cache contains flat JSON files only.
				foreach ( glob( $font_cache . '/*' ) ?: [] as $cache_file ) {
					unlink( $cache_file );
				}
				rmdir( $font_cache );
			}
		}

		if ( ! is_string( $output ) || '' === $output || ! str_starts_with( $output, '%PDF-' ) ) {
			throw new RendererException( 'PDF rendering produced invalid output.' );
		}

		return $output;
	}

	private function require_engine(): void {
		$missing_extensions = array_values( array_filter(
			self::REQUIRED_EXTENSIONS,
			static fn ( string $extension ): bool => ! extension_loaded( $extension )
		) );
		if ( ! empty( $missing_extensions ) ) {
			throw new RendererException(
				'Dompdf requires the following PHP extensions: ' . implode( ', ', $missing_extensions )
			);
		}

		if ( class_exists( '\Dompdf\Dompdf', false ) ) {
			if ( ! self::is_own_dompdf_loaded() ) {
				throw new RendererException( 'A different Dompdf installation is already loaded in this request.' );
			}
			return;
		}

		if ( ! self::is_available() ) {
			throw new RendererException(
				'Dompdf vendor library is missing. Expected at: '
				. CB_CORE_DIR . ltrim( self::VENDOR_AUTOLOAD, '/' )
			);
		}

		require_once CB_CORE_DIR . ltrim( self::VENDOR_AUTOLOAD, '/' );

		if ( ! class_exists( '\Dompdf\Dompdf', true ) ) {
			throw new RendererException(
				'Dompdf autoloader was loaded but \Dompdf\Dompdf is unavailable.'
			);
		}

		if ( ! self::is_own_dompdf_loaded() ) {
			throw new RendererException( 'Dompdf loaded from an unexpected location.' );
		}
	}

	private static function is_own_dompdf_loaded(): bool {
		try {
			$reflection = new \ReflectionClass( '\Dompdf\Dompdf' );
			$file       = $reflection->getFileName();
			$own_root   = realpath( CB_CORE_DIR . 'src/PDF/lib/dompdf/vendor/dompdf/dompdf' );
			$loaded     = is_string( $file ) ? realpath( $file ) : false;
		} catch ( \ReflectionException $e ) {
			return false;
		}

		if ( false === $own_root || false === $loaded ) {
			return false;
		}

		$prefix = rtrim( $own_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return 0 === strncmp( $loaded, $prefix, strlen( $prefix ) );
	}

	/**
	 * @param array<string,mixed> $opts
	 * @return array{0:string|array{0:float,1:float,2:float,3:float},1:string}
	 */
	private function paper_contract( array $opts ): array {
		if ( ! array_key_exists( 'paper_size_mm', $opts ) ) {
			return [ (string) $opts['paper_size'], (string) $opts['orientation'] ];
		}

		$dimensions = $opts['paper_size_mm'];
		if ( ! is_array( $dimensions ) || ! array_is_list( $dimensions ) || 2 !== count( $dimensions ) ) {
			throw new RendererException( 'paper_size_mm must contain exactly [width, height].' );
		}

		$width_mm  = $this->paper_dimension_mm( $dimensions[0] );
		$height_mm = $this->paper_dimension_mm( $dimensions[1] );
		if ( null === $width_mm || null === $height_mm ) {
			throw new RendererException( 'paper_size_mm dimensions must be positive finite numbers up to 2000 mm.' );
		}

		$to_points = static fn ( float $mm ): float => $mm * self::POINTS_PER_INCH / self::MILLIMETRES_PER_INCH;
		return [ [ 0.0, 0.0, $to_points( $width_mm ), $to_points( $height_mm ) ], 'portrait' ];
	}

	private function paper_dimension_mm( mixed $value ): ?float {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}
		$number = (float) $value;
		if ( ! is_finite( $number ) || $number <= 0.0 || $number > self::MAX_CUSTOM_PAPER_MM ) {
			return null;
		}
		return $number;
	}

	private function build_dompdf_options( array $opts ): \Dompdf\Options {
		$dompdf_options = new \Dompdf\Options();
		$dompdf_options->setDefaultFont( (string) $opts['default_font'] );
		$dompdf_options->setIsHtml5ParserEnabled( (bool) $opts['is_html5_parser'] );

		// Hard security boundary: report HTML is self-contained. Callers cannot
		// opt back into network requests, embedded PHP or PDF JavaScript.
		$dompdf_options->setIsRemoteEnabled( false );
		$dompdf_options->setIsPhpEnabled( false );
		$dompdf_options->setIsJavascriptEnabled( false );

		// Dompdf 3.1.6 adds an estimated decoded-image memory limit. Keep this
		// conservative; report logos are separately bounded before embedding.
		if ( method_exists( $dompdf_options, 'setImageByteSizeLimit' ) ) {
			$dompdf_options->setImageByteSizeLimit( '32M' );
		}

		return $dompdf_options;
	}
}
