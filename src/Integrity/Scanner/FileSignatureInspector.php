<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

/**
 * Passive, bounded signature inspection. Never executes or includes inspected content.
 * A positive executable classification requires a structural signature, not an
 * extension, MIME label, or isolated "MZ" prefix.
 */
final class FileSignatureInspector {
	private const HEADER_BYTES = 4096;
	private const SCRIPT_EXTENSIONS = [ 'php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'cgi', 'pl', 'py', 'sh', 'exe', 'dll', 'bat', 'cmd', 'ps1' ];

	/** @return array{checked:bool,detected:string,anomaly:string,reason:string} */
	public static function inspect( string $path, string $extension ): array {
		$result = [ 'checked' => false, 'detected' => 'unknown', 'anomaly' => '', 'reason' => '' ];
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			$result['reason'] = 'header_unreadable';
			return $result;
		}
		try {
			$header = @fread( $handle, self::HEADER_BYTES );
		} finally {
			fclose( $handle );
		}
		if ( ! is_string( $header ) ) {
			$result['reason'] = 'header_read_failed';
			return $result;
		}
		$result['checked'] = true;
		$result['detected'] = self::format( $header );
		$extension = strtolower( $extension );

		if ( in_array( $result['detected'], [ 'windows_pe', 'elf', 'php' ], true )
			&& ! in_array( $extension, self::SCRIPT_EXTENSIONS, true ) ) {
			$result['anomaly'] = 'disguised_executable';
			$result['reason'] = 'executable_signature_in_non_executable_file';
		}
		return $result;
	}

	/** Return only formats that can be established from bounded header evidence. */
	private static function format( string $bytes ): string {
		if ( strlen( $bytes ) >= 64 && str_starts_with( $bytes, 'MZ' ) ) {
			$offset = unpack( 'Voffset', substr( $bytes, 0x3c, 4 ) );
			$pe_at = (int) ( $offset['offset'] ?? 0 );
			if ( $pe_at >= 64 && $pe_at + 4 <= strlen( $bytes )
				&& "PE\0\0" === substr( $bytes, $pe_at, 4 ) ) {
				return 'windows_pe';
			}
		}
		if ( str_starts_with( $bytes, "\x7fELF" ) ) {
			return 'elf';
		}
		if ( preg_match( '/^\\s*<\\?(?:php\\b|=)/i', $bytes ) === 1 ) {
			return 'php';
		}
		if ( str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) ) {
			return 'png';
		}
		if ( str_starts_with( $bytes, "\xff\xd8\xff" ) ) {
			return 'jpeg';
		}
		if ( str_starts_with( $bytes, 'GIF87a' ) || str_starts_with( $bytes, 'GIF89a' ) ) {
			return 'gif';
		}
		if ( str_starts_with( $bytes, 'RIFF' ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'webp';
		}
		if ( str_starts_with( $bytes, '%PDF-' ) ) {
			return 'pdf';
		}
		return 'unknown';
	}
}
