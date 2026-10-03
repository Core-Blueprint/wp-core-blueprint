<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use FilesystemIterator;
use Throwable;

use function array_pop;
use function is_callable;
use function is_dir;
use function is_link;
use function is_readable;
use function ltrim;
use function rtrim;
use function strlen;
use function substr;
use function wp_normalize_path;

/**
 * Streaming filesystem traversal used by Scanner components.
 *
 * It deliberately does not follow symlinks and never imposes a file-count
 * ceiling. The caller receives every regular file as it is encountered, so a
 * large tree does not need to be materialised in memory first.
 */
final class FilesystemWalker {

	/**
	 * @param callable(string,array):void $on_file Called with relative path + metadata.
	 * @param callable(string,string):bool|null $should_descend Optional directory filter.
	 */
	public static function walk( string $root, callable $on_file, ?callable $should_descend = null ): array {
		$root = rtrim( wp_normalize_path( $root ), '/' );
		$stats = [
			'root'              => $root,
			'complete'          => true,
			'files_encountered' => 0,
			'files_processed'   => 0,
			'unreadable_count'  => 0,
			'symlink_count'     => 0,
			'outside_count'     => 0,
			'error_count'       => 0,
			'unreadable_paths'  => [],
			'symlink_paths'     => [],
			'outside_paths'     => [],
			'errors'            => [],
		];

		if ( '' === $root || ! is_dir( $root ) || ! is_readable( $root ) ) {
			$stats['complete']    = false;
			$stats['error_count'] = 1;
			$stats['errors'][]    = __( 'Scan root is missing or unreadable.', 'core-blueprint' );
			return $stats;
		}

		$stack = [ $root ];

		while ( [] !== $stack ) {
			$directory = (string) array_pop( $stack );

			if ( ! is_readable( $directory ) ) {
				$stats['complete'] = false;
				$stats['unreadable_count']++;
				self::record( $stats['unreadable_paths'], self::relative( $root, $directory ) );
				continue;
			}

			try {
				$iterator = new FilesystemIterator( $directory, FilesystemIterator::SKIP_DOTS );
			} catch ( Throwable $throwable ) {
				$stats['complete'] = false;
				$stats['error_count']++;
				self::record( $stats['errors'], $throwable->getMessage() );
				continue;
			}

			foreach ( $iterator as $entry ) {
				$path     = wp_normalize_path( $entry->getPathname() );
				$relative = self::relative( $root, $path );

				if ( is_link( $path ) || $entry->isLink() ) {
					$stats['complete'] = false;
					$stats['symlink_count']++;
					self::record( $stats['symlink_paths'], $relative );
					continue;
				}

				if ( $entry->isDir() ) {
					if ( null === $should_descend || ! is_callable( $should_descend ) || $should_descend( $relative, $path ) ) {
						$stack[] = $path;
					}
					continue;
				}

				if ( ! $entry->isFile() ) {
					continue;
				}

				$stats['files_encountered']++;

				if ( ! PathGuard::existing_path_is_inside( $path, $root ) ) {
					$stats['complete'] = false;
					$stats['outside_count']++;
					self::record( $stats['outside_paths'], $relative );
					continue;
				}

				$readable = $entry->isReadable() && is_readable( $path );
				if ( ! $readable ) {
					$stats['complete'] = false;
					$stats['unreadable_count']++;
					self::record( $stats['unreadable_paths'], $relative );
				}

				$on_file(
					$relative,
					[
						'absolute_path' => $path,
						'readable'      => $readable,
						'size'          => (int) $entry->getSize(),
						'mtime'         => (int) $entry->getMTime(),
					]
				);
				$stats['files_processed']++;
			}
		}

		return $stats;
	}

	private static function relative( string $root, string $path ): string {
		$root = rtrim( wp_normalize_path( $root ), '/' );
		$path = wp_normalize_path( $path );

		if ( $path === $root ) {
			return '';
		}

		return ltrim( substr( $path, strlen( $root ) ), '/' );
	}

	private static function record( array &$target, string $value ): void {
		$target[] = $value;
	}
}
