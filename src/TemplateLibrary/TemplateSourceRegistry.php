<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

use CoreBlueprint\Core\ExtensionRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Public local-only registry. Sources own storage, permission policy, stable
 * server-side search and domain operations. This class never makes HTTP calls.
 */
final class TemplateSourceRegistry {
	/** @var array<string,TemplateSourceInterface> */
	private static array $sources = [];
	private static bool $collected = false;

	public static function init(): void {
		// ExtensionRegistry collects at init:5. Templates may refer only to known extensions.
		add_action( 'init', [ self::class, 'collect' ], 6 );
	}

	public static function register( TemplateSourceInterface $source ): bool {
		if ( ! doing_action( 'core_blueprint_register_template_sources' ) ) {
			return false;
		}
		$id = $source->source_id();
		$extension = $source->extension_id();
		$designer = $source->designer_type();
		if ( ! TemplateQuery::valid_id( $id )
			|| ! TemplateQuery::valid_id( $extension )
			|| ! TemplateQuery::valid_id( $designer )
			|| isset( self::$sources[ $id ] )
			|| ( 'core-blueprint' !== $extension && null === ExtensionRegistry::definition( $extension ) ) ) {
			return false;
		}
		self::$sources[ $id ] = $source;
		return true;
	}

	/** @return array<string,array{extension:string,designer:string}> */
	public static function sources(): array {
		self::collect_if_ready();
		$found = [];
		foreach ( self::$sources as $id => $source ) {
			if ( self::allowed( $source ) ) {
				$found[ $id ] = [ 'extension' => $source->extension_id(), 'designer' => $source->designer_type() ];
			}
		}
		ksort( $found, SORT_STRING );
		return $found;
	}

	/** All authorized Base and extension templates. For trusted server-side Admin callers. */
	public static function browse_global( TemplateQuery $query, ?string $cursor = null ): TemplatePage {
		return self::browse( $query, null, null, $cursor );
	}

	/**
	 * Fixed Designer context, not a user-changeable browser filter. T2 transport
	 * MUST bind this scope to its server-verified current Designer session.
	 */
	public static function browse_designer(
		string $extension, string $designer, TemplateQuery $query, ?string $cursor = null
	): TemplatePage {
		if ( ! TemplateQuery::valid_id( $extension ) || ! TemplateQuery::valid_id( $designer )
			|| ( '' !== $query->extension && $query->extension !== $extension )
			|| ( '' !== $query->designer && $query->designer !== $designer ) ) {
			return new TemplatePage( [] );
		}
		return self::browse( $query, $extension, $designer, $cursor );
	}

	public static function collect(): void {
		if ( self::$collected ) {
			return;
		}
		self::$collected = true;
		do_action( 'core_blueprint_register_template_sources' );
	}

	/** @internal WordPress integration fixtures only. */
	public static function _reset_for_testing(): void {
		self::$sources = [];
		self::$collected = false;
	}

	private static function collect_if_ready(): void {
		if ( ! self::$collected && did_action( 'init' ) > 0 ) {
			self::collect();
		}
	}

	private static function allowed( TemplateSourceInterface $source ): bool {
		$user = get_current_user_id();
		if ( $user < 1 ) {
			return false;
		}
		try {
			return $source->can_browse( $user );
		} catch ( \Throwable ) {
			return false;
		}
	}

	private static function browse(
		TemplateQuery $query, ?string $extension, ?string $designer, ?string $cursor
	): TemplatePage {
		self::collect_if_ready();
		$user = get_current_user_id();
		if ( $user < 1 ) {
			return new TemplatePage( [] );
		}
		$sources = [];
		foreach ( self::$sources as $id => $source ) {
			if ( ( null !== $extension && $source->extension_id() !== $extension )
				|| ( null !== $designer && $source->designer_type() !== $designer )
				|| ( '' !== $query->source && $query->source !== $id )
				|| ( '' !== $query->extension && $source->extension_id() !== $query->extension )
				|| ( '' !== $query->designer && $source->designer_type() !== $query->designer )
				|| ! self::allowed( $source ) ) {
				continue;
			}
			$sources[ $id ] = $source;
		}
		ksort( $sources, SORT_STRING );
		$keys = array_keys( $sources );
		$scope = ( $extension ?? '*' ) . ':' . ( $designer ?? '*' );
		$fingerprint = hash( 'sha256', $query->fingerprint() . '|' . $scope . '|' . implode( '|', $keys ) );
		$position = self::read_cursor( $cursor, $fingerprint, $user );
		if ( null === $position ) {
			return new TemplatePage( [] );
		}
		$index = $position['index'];
		$inner = $position['inner'];
		$items = [];

		while ( $index < count( $keys ) && count( $items ) < $query->limit ) {
			$source = $sources[ $keys[ $index ] ];
			$remaining = $query->limit - count( $items );
			try {
				$page = $source->query( $query->with_limit( $remaining ), $inner );
				if ( count( $page->items ) > $remaining ) {
					throw new \UnexpectedValueException( 'Source returned oversized page.' );
				}
				foreach ( $page->items as $item ) {
					if ( $item->source !== $keys[ $index ] || $item->extension !== $source->extension_id()
						|| $item->designer !== $source->designer_type() || ! self::matches( $item, $query )
						|| ! $source->can_view( $item->id, $user ) ) {
						continue;
					}
					$items[] = $item;
				}
				$next = $page->next_cursor;
				if ( null !== $next && strlen( $next ) > 256 ) {
					throw new \UnexpectedValueException( 'Source cursor exceeds the provider budget.' );
				}
				if ( null !== $next && $next !== $inner ) {
					return new TemplatePage( $items, self::write_cursor( $index, $next, $fingerprint, $user ) );
				}
			} catch ( \Throwable ) {
				// A failing/untrusted provider cannot expose data or break other providers.
			}
			++$index;
			$inner = null;
		}
		return new TemplatePage( $items, $index < count( $keys )
			? self::write_cursor( $index, null, $fingerprint, $user ) : null );
	}

	private static function matches( TemplateMetadata $item, TemplateQuery $query ): bool {
		return ( '' === $query->origin || $item->origin === $query->origin )
			&& ( '' === $query->category || $item->category === $query->category )
			&& ( '' === $query->locale || $item->locale === $query->locale )
			&& ( '' === $query->search || false !== stripos( $item->title . ' ' . $item->description, $query->search ) );
	}

	/** @return array{index:int,inner:?string}|null */
	private static function read_cursor( ?string $cursor, string $fingerprint, int $user ): ?array {
		if ( null === $cursor ) {
			return [ 'index' => 0, 'inner' => null ];
		}
		if ( strlen( $cursor ) > 2048 || ! preg_match( '/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/D', $cursor ) ) {
			return null;
		}
		[ $encoded, $mac ] = explode( '.', $cursor, 2 );
		if ( ! hash_equals( hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) ), $mac ) ) {
			return null;
		}
		try {
			$decoded = base64_decode( strtr( $encoded, '-_', '+/' ), true );
			$state = json_decode( (string) $decoded, true, 8, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return null;
		}
		if ( ! is_array( $state ) || 1 !== ( $state['v'] ?? null )
			|| $user !== ( $state['user'] ?? null ) || $fingerprint !== ( $state['fingerprint'] ?? null )
			|| ! is_int( $state['index'] ?? null ) || $state['index'] < 0
			|| ! is_int( $state['expires'] ?? null ) || $state['expires'] < time()
			|| ( null !== ( $state['inner'] ?? null ) && ( ! is_string( $state['inner'] ) || strlen( $state['inner'] ) > 256 ) ) ) {
			return null;
		}
		return [ 'index' => $state['index'], 'inner' => $state['inner'] ?? null ];
	}

	private static function write_cursor( int $index, ?string $inner, string $fingerprint, int $user ): string {
		$json = json_encode( [
			'v' => 1, 'user' => $user, 'fingerprint' => $fingerprint,
			'index' => $index, 'inner' => $inner, 'expires' => time() + 900,
		], JSON_THROW_ON_ERROR );
		$encoded = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
	}
}
