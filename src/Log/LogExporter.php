<?php
declare(strict_types=1);
/**
 * LogExporter - format-agnostic serialiser for log-type exports.
 *
 * Log classes (AuditLog, MaintenanceReport, and any future log sources)
 * expose three things to the exporter:
 *
 *   - rows_iterator( $args ): iterable   - yields one associative row at a time
 *   - columns(): array<string,string>    - map of field_key => human label
 *   - export_meta( $args ): array        - envelope metadata (filters, total, etc.)
 *
 * The exporter turns that into CSV or JSON on an open stream handle. A
 * `do_action( 'cb_core_export_{format}', ... )` hook lets sibling plugins
 * (e.g. a future CB Report plugin for PDF) register their own renderers
 * against unknown formats without touching this class.
 *
 * Format registry: `apply_filters( 'cb_core_export_formats', ['csv' => 'CSV', 'json' => 'JSON'] )`
 * is the canonical list the UI reads to build the dropdown. Extensions
 * add to this filter AND to the matching do_action hook.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Log;

defined( 'ABSPATH' ) || exit;

final class LogExporter {

	/** Supported built-in formats - extended via `cb_core_export_formats` filter. */
	const BUILTIN_FORMATS = [ 'csv', 'json' ];

	/**
	 * Return the registered export formats as format-slug => label.
	 * Extensions add entries via the `cb_core_export_formats` filter.
	 *
	 * @return array<string,string>
	 */
	public static function formats(): array {
		$formats = [
			'csv'  => __( 'CSV', 'core-blueprint' ),
			'json' => __( 'JSON', 'core-blueprint' ),
		];

		/**
		 * Filter: cb_core_export_formats
		 *
		 * Allows extension plugins (CB Report etc.) to register additional
		 * export formats. The key is the format slug (used in URLs and in
		 * the do_action hook `cb_core_export_{slug}`); the value is the
		 * human-readable label shown in the UI dropdown.
		 *
		 * @param array<string,string> $formats
		 */
		return apply_filters( 'cb_core_export_formats', $formats );
	}

	/**
	 * Normalise a format slug against the registered list. Unknown
	 * slugs fall back to 'csv'.
	 */
	public static function sanitize_format( string $format ): string {
		$format = sanitize_key( $format );
		return isset( self::formats()[ $format ] ) ? $format : 'csv';
	}

	/**
	 * MIME type for a format slug. Falls back to application/octet-stream
	 * for unknown formats - extensions that add custom formats should
	 * filter `cb_core_export_mime_types` to register theirs.
	 */
	public static function mime_type( string $format ): string {
		$types = apply_filters( 'cb_core_export_mime_types', [
			'csv'  => 'text/csv; charset=UTF-8',
			'json' => 'application/json; charset=UTF-8',
		] );
		return $types[ $format ] ?? 'application/octet-stream';
	}

	/**
	 * File extension for a format slug. Defaults to the slug itself.
	 */
	public static function extension( string $format ): string {
		$extensions = apply_filters( 'cb_core_export_extensions', [
			'csv'  => 'csv',
			'json' => 'json',
		] );
		return $extensions[ $format ] ?? $format;
	}

	/**
	 * Dispatch an export to the requested format. Built-in formats
	 * (csv, json) are handled inline; unknown formats fire
	 * `cb_core_export_{format}` for extension plugins to handle.
	 *
	 * @param string   $format   Format slug (already sanitised by caller).
	 * @param resource $handle   Open output stream.
	 * @param iterable $rows     Generator or iterable yielding associative rows.
	 * @param array    $columns  field_key => human label map.
	 * @param array    $meta     Envelope metadata for JSON / PDF cover.
	 * @return int Number of rows written (0 if extension didn't report back).
	 */
	public static function dispatch( string $format, $handle, iterable $rows, array $columns, array $meta ): int {
		switch ( $format ) {
			case 'csv':
				return self::to_csv( $handle, $rows, $columns );
			case 'json':
				return self::to_json( $handle, $rows, $columns, $meta );
			default:
				// Extension hook: CB Report and similar plugins listen here.
				// The action receives the handle + iterable + columns + meta
				// plus a by-reference counter for row accounting. Extensions
				// should increment $count as they write rows.
				$count = 0;

				/**
				 * Action: cb_core_export_{format}
				 *
				 * Fired when a request asks for an export format that CB Base
				 * doesn't handle natively. Extension plugins register a
				 * listener that writes the appropriate content to $handle.
				 *
				 * @param resource $handle
				 * @param iterable $rows
				 * @param array    $columns
				 * @param array    $meta
				 * @param int      $count   By-reference - handler increments as rows are written.
				 */
				do_action_ref_array( "cb_core_export_{$format}", [ $handle, $rows, $columns, $meta, &$count ] );

				return $count;
		}
	}

	/**
	 * Stream rows to a CSV file handle with a header row derived from
	 * $columns. Keeps memory flat by consuming $rows row-by-row.
	 *
	 * @param resource $handle   Open file handle (e.g. fopen('php://output', 'w')).
	 * @param iterable $rows     Iterable of associative row arrays.
	 * @param array    $columns  field_key => human label map. Order of keys
	 *                           defines column order in output.
	 * @return int Rows written (excluding header).
	 */
	public static function to_csv( $handle, iterable $rows, array $columns ): int {
		fputcsv( $handle, array_values( $columns ), ',', '"', '\\' );

		$written = 0;
		foreach ( $rows as $row ) {
			$line = [];
			foreach ( array_keys( $columns ) as $field ) {
				$value = $row[ $field ] ?? '';
				if ( is_array( $value ) || is_object( $value ) ) {
					$value = wp_json_encode( $value );
				}
				$line[] = self::protect_csv_cell( (string) $value );
			}
			fputcsv( $handle, $line, ',', '"', '\\' );
			$written++;
		}
		return $written;
	}

	/**
	 * Neutralize spreadsheet formula prefixes without changing the stored log
	 * record or JSON export. Prefixing an apostrophe is the same reversible
	 * transport convention used by the Data Exchange CSV foundation.
	 */
	private static function protect_csv_cell( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( "'" === $value[0] || 1 === preg_match( '/^[ \t\r\n]*[=+\-@]/D', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Write an envelope JSON document: metadata about the export plus
	 * the event rows themselves. Pretty-printed for readability -
	 * official reports are opened in editors, not streamed to bots, and
	 * the size overhead is minimal.
	 *
	 * Unlike to_csv() this buffers all rows in memory before encoding.
	 * That's intentional: JSON is a nested structure, streaming it means
	 * manual string concatenation and no pretty-printing. For CB's
	 * current scale (audit logs retention-capped at ~180 days) buffering
	 * is fine. If this becomes a bottleneck the fix is ndjson, not
	 * streaming JSON.
	 *
	 * @param resource $handle
	 * @param iterable $rows
	 * @param array    $columns   Used to project rows to the known fields
	 *                            (drops internal-only fields like decoded
	 *                            context arrays that the exporter would
	 *                            duplicate from the raw JSON context).
	 * @param array    $meta      Envelope metadata (type, generated_at, filters, ...).
	 * @return int Rows written.
	 */
	public static function to_json( $handle, iterable $rows, array $columns, array $meta ): int {
		$collected = [];
		foreach ( $rows as $row ) {
			$projected = [];
			foreach ( array_keys( $columns ) as $field ) {
				$projected[ $field ] = $row[ $field ] ?? null;
			}
			$collected[] = $projected;
		}

		$meta['total'] = count( $collected );

		$envelope = [
			'export' => $meta,
			'events' => $collected,
		];

		$json = wp_json_encode( $envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return 0;
		}

		fwrite( $handle, $json );
		return count( $collected );
	}

	/**
	 * Assemble a standard envelope metadata array for an export request.
	 * Called by the AJAX handlers. Extension formats (PDF) can read from
	 * the same structure and extend it as needed.
	 *
	 * @param string $type   Export type slug ('audit', 'system_log', 'maintenance_report').
	 * @param array  $filters Active filters (opaque; handler decides what to include).
	 * @return array
	 */
	public static function base_meta( string $type, array $filters = [] ): array {
		$user     = wp_get_current_user();
		$actor    = $user && $user->ID
			? sprintf( '%s:%s', $user->user_login, $user->user_email ?: '-' )
			: 'unknown';

		return [
			'type'           => $type,
			'generated_at'   => gmdate( 'c' ),
			'generated_by'   => $actor,
			'site_url'       => home_url(),
			'plugin_version' => defined( 'CB_CORE_VERSION' ) ? CB_CORE_VERSION : 'unknown',
			'filters'        => array_filter( $filters, static fn( $v ) => '' !== $v && null !== $v ),
		];
	}
}
