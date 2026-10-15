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

	/**
	 * Bounded, capability-gated category facets. Does not enumerate template
	 * documents or require pagination to discover categories.
	 *
	 * @return array<string,list<array{id:string,label:string}>>
	 */
	public static function source_categories(): array {
		self::collect_if_ready();
		$user = get_current_user_id();
		if ( $user < 1 ) {
			return [];
		}
		$catalog = [];
		foreach ( self::$sources as $source_id => $source ) {
			if ( ! $source instanceof TemplateCategorySourceInterface || ! self::allowed( $source ) ) {
				continue;
			}
			try {
				$raw = $source->categories( $user );
				$items = [];
				foreach ( $raw as $id => $label ) {
					if ( count( $items ) >= 48 ) {
						break;
					}
					if ( ! is_string( $id ) || ! TemplateQuery::valid_id( $id ) || ! is_string( $label ) ) {
						continue;
					}
					$label = trim( wp_strip_all_tags( $label ) );
					if ( '' === $label || strlen( $label ) > 96 || preg_match( '/[\x00-\x1f\x7f]/', $label ) ) {
						continue;
					}
					$items[$id] = [ 'id' => $id, 'label' => $label ];
				}
				ksort( $items, SORT_STRING );
				$catalog[$source_id] = array_values( $items );
			} catch ( \\Throwable ) {
				// A failing provider cannot expose facets or affect other sources.
			}
		}
		return $catalog;
	}

	/**
	 * T3 optional local navigation. Rechecks the CURRENT actor and item even
	 * after a prior browse result; a client-provided metadata object is never
	 * authorization. No domain writes, redirects or outbound requests.
	 */
	public static function navigation( TemplateMetadata $item, bool $include_manage = false ): TemplateNavigation {
		self::collect_if_ready();
		$user = get_current_user_id();
		$source = self::$sources[ $item->source ] ?? null;
		if ( $user < 1 || ! $source instanceof TemplateManagementSourceInterface
			|| $source->extension_id() !== $item->extension
			|| $source->designer_type() !== $item->designer
			|| ! self::allowed( $source ) ) {
			return new TemplateNavigation();
		}
		try {
			if ( ! $source->can_view( $item->id, $user ) ) {
				return new TemplateNavigation();
			}
			$links = $source->navigation( $item, $user );
			$can_manage = $include_manage
				&& in_array( $item->origin, [ 'personal', 'site' ], true )
				&& $source->can_manage( $item->id, $user );
			return new TemplateNavigation( $links->preview, $can_manage ? $links->manage : null );
		} catch ( \Throwable ) {
			// A bad provider must not leak a URL, expose private items or
			// break discovery from other installed extensions.
			return new TemplateNavigation();
		}
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
