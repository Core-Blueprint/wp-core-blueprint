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
	 * An informational capability hint for an already-authorized source.
	 * The sorted browse endpoint still checks every provider, filter, actor
	 * and the bounded source-set size; this is never an authorization grant.
	 */
	public static function supports_title_sort( string $source_id ): bool {
		self::collect_if_ready();
		$source = self::$sources[ $source_id ] ?? null;
		return $source instanceof TemplateSortedSourceInterface
			&& function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' )
			&& self::allowed( $source );
	}

	/**
	 * Source-level preview capability hint, NOT an authorization grant.
	 * Individual items are checked separately for each POST preview request.
	 */
	public static function supports_preview( string $source_id ): bool {
		self::collect_if_ready();
		$source = self::$sources[ $source_id ] ?? null;
		return $source instanceof TemplatePreviewSourceInterface && self::allowed( $source );
	}

	/**
	 * Per-item UI capability hint, not a right to download.
	 * Never advertises ZIP for a provider with only JSON support.
	 */
	public static function supports_resource_export( TemplateMetadata $item ): bool {
		self::collect_if_ready();
		$actor = get_current_user_id();
		$source = self::$sources[ $item->source ] ?? null;
		if ( $actor < 1 || ! current_user_can( 'manage_options' )
			|| ! $source instanceof TemplateResourceExportSourceInterface
			|| $source->extension_id() !== $item->extension
			|| $source->designer_type() !== $item->designer
			|| ! self::allowed( $source ) ) {
			return false;
		}
		try {
			return TemplateQuery::valid_id( $source->resource_export_entity() )
				&& $source->can_view( $item->id, $actor )
				&& $source->can_export_resource_package( $item->id, $item->version, $actor );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Download transport admission for a resource-bearing template.
	 * No browser-supplied provider/entity, export context or resource bytes.
	 * Every domain operation remains on the registered source and the existing
	 * Data Exchange Engine. A ZIP is never a permission to import or publish.
	 *
	 * @return array{path:string,package_sha256:string,document_sha256:string,resources:array}|\WP_Error
	 */
	public static function export_resource_package(
		string $source_id, string $template_id, string $version, string $private_root
	): array|\WP_Error {
		self::collect_if_ready();
		$actor = get_current_user_id();
		$source = self::$sources[ $source_id ] ?? null;
		if ( $actor < 1 || ! current_user_can( 'manage_options' )
			|| ! TemplateQuery::valid_id( $source_id )
			|| ! TemplateQuery::valid_id( $template_id )
			|| strlen( $version ) > 48
			|| 1 !== preg_match( '/^[1-9][0-9]*(?:\\.[0-9]+){0,2}$/D', $version )
			|| ! $source instanceof TemplateResourceExportSourceInterface
			|| ! self::allowed( $source ) ) {
			return new \WP_Error( 'cb_core_resource_export_refused', 'Resource export is unavailable.' );
		}
		$package = null;
		$refuse = static fn(): \WP_Error => new \WP_Error(
			'cb_core_resource_export_refused', 'Resource export is unavailable.'
		);
		try {
			$entity = $source->resource_export_entity();
			if ( ! TemplateQuery::valid_id( $entity )
				|| ! $source->can_view( $template_id, $actor )
				|| ! $source->can_export_resource_package( $template_id, $version, $actor ) ) {
				return $refuse();
			}
			$context = $source->resource_export_context( $template_id, $version, $actor );
			if ( is_wp_error( $context ) || ! is_array( $context )
				|| ( $context['templateId'] ?? null ) !== $template_id
				|| ( $context['version'] ?? null ) !== $version
				|| ( $context['actorId'] ?? null ) !== $actor ) {
				return $refuse();
			}
			$read = static function ( array $resource ) use (
				$source, $actor, $template_id, $version
			): string|\WP_Error {
				if ( get_current_user_id() !== $actor
					|| ! current_user_can( 'manage_options' )
					|| ! $source->can_view( $template_id, $actor )
					|| ! $source->can_export_resource_package( $template_id, $version, $actor ) ) {
					return new \WP_Error( 'cb_core_resource_export_revoked', 'Resource export is unavailable.' );
				}
				return $source->read_export_resource( $template_id, $version, $resource, $actor );
			};
			$package = \CoreBlueprint\Core\DataExchange\ResourcePackageExport::create(
				$source->extension_id(), $entity, $context, $read, $private_root
			);
			if ( is_wp_error( $package ) ) {
				return $refuse();
			}
			if ( get_current_user_id() !== $actor || ! current_user_can( 'manage_options' )
				|| ! self::allowed( $source )
				|| ! $source->can_view( $template_id, $actor )
				|| ! $source->can_export_resource_package( $template_id, $version, $actor ) ) {
				\CoreBlueprint\Core\DataExchange\ResourcePackageExport::discard( $package['path'], $private_root );
				return $refuse();
			}
			return $package;
		} catch ( \Throwable ) {
			if ( is_array( $package ) && is_string( $package['path'] ?? null ) ) {
				\CoreBlueprint\Core\DataExchange\ResourcePackageExport::discard( $package['path'], $private_root );
			}
			return $refuse();
		}
	}

	/**
	 * Read-only provider-owned preview boundary, independent of Apply/Save.
	 * No client-supplied HTML/document, repository access, network or caching.
	 *
	 * @param array{extension:string,designer:string}|null $scope Verified Designer scope; null = global admin.
	 */
	public static function preview(
		string $source_id, string $template_id, string $expected_version, ?array $scope
	): ?TemplatePreview {
		self::collect_if_ready();
		$actor = get_current_user_id();
		$source = self::$sources[ $source_id ] ?? null;
		if ( $actor < 1 || ( null === $scope && ! current_user_can( 'manage_options' ) )
			|| ! TemplateQuery::valid_id( $source_id )
			|| ! TemplateQuery::valid_id( $template_id )
			|| ! $source instanceof TemplatePreviewSourceInterface
			|| ! self::allowed( $source )
			|| ( null !== $scope && (
				$source->extension_id() !== ( $scope['extension'] ?? null )
				|| $source->designer_type() !== ( $scope['designer'] ?? null )
			) ) ) {
			return null;
		}
		try {
			if ( ! $source->can_view( $template_id, $actor )
				|| ! $source->can_preview( $template_id, $actor ) ) {
				return null;
			}
			$result = $source->render_preview( $template_id, $expected_version, $actor );
			if ( ! $result instanceof TemplatePreview
				|| ! hash_equals( $expected_version, $result->version )
				|| ! self::allowed( $source )
				|| ! $source->can_view( $template_id, $actor )
				|| ! $source->can_preview( $template_id, $actor ) ) {
				return null;
			}
			return $result;
		} catch ( \Throwable ) {
			// Provider exceptions, unknown revisions and unsafe HTML fail closed.
			return null;
		}
	}

	/**
	 * Resolve a registered source and the consumer's current Designer authority.
	 * Source registration and browse permission are not launch authority.
	 *
	 * @return array{extension:string,designer:string}|null
	 */
	public static function authorized_designer_scope(
		string $source_id, string $resource_type, string $resource_key, bool $launch
	): ?array {
		self::collect_if_ready();
		$source = self::$sources[ $source_id ] ?? null;
		$user = get_current_user_id();
		if ( $user < 1 || ! $source instanceof TemplateDesignerAuthorityInterface
			|| ! self::allowed( $source ) ) {
			return null;
		}
		try {
			if ( ! $source->can_access_designer_resource( $resource_type, $resource_key, $user )
				|| ( $launch && ! $source->can_launch_designer( $resource_type, $resource_key, $user ) ) ) {
				return null;
			}
			return [ 'extension' => $source->extension_id(), 'designer' => $source->designer_type() ];
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Bounded, capability-gated category facets. Does not enumerate template
	 * documents or require pagination to discover categories.
	 *
	 * @return array<string,list<array{id:string,label:string}>>
	 */
	public static function source_categories( ?array $source_ids = null ): array {
		self::collect_if_ready();
		$user = get_current_user_id();
		if ( $user < 1 ) {
			return [];
		}
		$catalog = [];
		foreach ( self::$sources as $source_id => $source ) {
			// A scoped Designer must not even invoke metadata providers from other
			// Designer contexts, independently of the response projection.
			if ( null !== $source_ids && ! in_array( $source_id, $source_ids, true ) ) {
				continue;
			}
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
			} catch ( \Throwable ) {
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

	/**
	 * TL-M1: optional action capability discovery. Re-evaluate the current
	 * actor; returned IDs are UI hints, never an authorization token.
	 *
	 * @return list<string>
	 */
	public static function actions( TemplateMetadata $item ): array {
		self::collect_if_ready();
		$actor = get_current_user_id();
		$source = self::$sources[ $item->source ] ?? null;
		if ( $actor < 1 || ! $source instanceof TemplateActionSourceInterface
			|| ! in_array( $item->origin, [ 'personal', 'site' ], true )
			|| ! TemplateQuery::valid_id( $item->id )
			|| ! self::allowed( $source ) ) {
			return [];
		}
		try {
			if ( $source->extension_id() !== $item->extension
				|| $source->designer_type() !== $item->designer
				|| ! $source->can_view( $item->id, $actor )
				|| ! $source->can_manage( $item->id, $actor ) ) {
				return [];
			}
			$supported = $source->supported_actions( $item->id, $actor );
			if ( ! is_array( $supported ) ) {
				return [];
			}
			$allowed = [ 'rename', 'duplicate', 'export-json', 'delete' ];
			return array_values( array_filter( $allowed, static fn ( string $action ): bool =>
				in_array( $action, $supported, true ) ) );
		} catch ( \Throwable ) {
			return [];
		}
	}

	/**
	 * Shared server-side action boundary: no metadata or document is accepted
	 * from the browser. Providers keep the sole write/export authority.
	 *
	 * @param array<string,mixed> $input
	 * @param array{extension:string,designer:string}|null $scope Server-verified scope.
	 * @return array<string,mixed>
	 */
	public static function perform_action(
		string $source_id, string $template_id, string $action,
		int $expected_revision, array $input, ?array $scope
	): array {
		self::collect_if_ready();
		$actor = get_current_user_id();
		$source = self::$sources[ $source_id ] ?? null;
		if ( $actor < 1 || ! TemplateQuery::valid_id( $source_id )
			|| ! TemplateQuery::valid_id( $template_id )
			|| $expected_revision < 1
			|| ! in_array( $action, [ 'rename', 'duplicate', 'export-json', 'delete' ], true )
			|| ! $source instanceof TemplateActionSourceInterface
			|| ! self::allowed( $source ) ) {
			return [ 'status' => 'refused' ];
		}
		$needs_title = in_array( $action, [ 'rename', 'duplicate' ], true );
		if ( $needs_title ) {
			if ( array_keys( $input ) !== [ 'title' ] || ! is_string( $input['title'] )
				|| strlen( $input['title'] ) > 191 ) {
				return [ 'status' => 'invalid' ];
			}
		} elseif ( [] !== $input ) {
			return [ 'status' => 'invalid' ];
		}
		try {
			if ( ( null !== $scope && (
				$source->extension_id() !== ( $scope['extension'] ?? null )
				|| $source->designer_type() !== ( $scope['designer'] ?? null )
			) ) || ! $source->can_view( $template_id, $actor )
				|| ! $source->can_manage( $template_id, $actor )
				|| ! in_array( $action, $source->supported_actions( $template_id, $actor ), true ) ) {
				return [ 'status' => 'refused' ];
			}
			$result = $source->perform_action(
				$template_id, $action, $expected_revision, $input, $actor
			);
			$status = $result['status'] ?? null;
			if ( ! is_string( $status ) ) {
				return [ 'status' => 'unknown' ];
			}
			if ( in_array( $status, [ 'conflict', 'missing', 'refused', 'invalid', 'unsupported', 'unavailable', 'unknown' ], true ) ) {
				return [ 'status' => $status ];
			}
			if ( 'rename' === $action && 'renamed' === $status
				&& ( $result['revision'] ?? null ) === $expected_revision + 1 ) {
				return [ 'status' => 'renamed', 'revision' => $result['revision'] ];
			}
			if ( 'duplicate' === $action && 'created' === $status
				&& is_string( $result['id'] ?? null )
				&& TemplateQuery::valid_id( $result['id'] )
				&& is_int( $result['revision'] ?? null ) && $result['revision'] > 0 ) {
				return [ 'status' => 'created', 'id' => $result['id'], 'revision' => $result['revision'] ];
			}
			if ( 'delete' === $action && 'deleted' === $status ) {
				return [ 'status' => 'deleted' ];
			}
			if ( 'export-json' === $action && 'ready' === $status
				&& is_string( $result['data'] ?? null )
				&& '' !== $result['data'] && strlen( $result['data'] ) <= 2500000 ) {
				return [ 'status' => 'ready', 'data' => $result['data'] ];
			}
		} catch ( \Throwable ) {
			// Fail closed on provider exceptions; a write may have occurred.
		}
		return [ 'status' => 'unknown' ];
	}

	/**
	 * Obtain an authorized independent native document. Never trust browser
	 * metadata, cache domain payloads, or perform writes in this registry.
	 *
	 * @param array{extension:string,designer:string} $scope Verified target scope.
	 * @return array{project:array<string,mixed>,fields:array<string,mixed>}|null
	 */
	public static function document_for_apply(
		string $source_id, string $template_id, string $profile_id,
		string $target_type, string $target_key, array $scope
	): ?array {
		self::collect_if_ready();
		$actor = get_current_user_id();
		$source = self::$sources[ $source_id ] ?? null;
		if ( $actor < 1 || ! TemplateQuery::valid_id( $template_id )
			|| ! $source instanceof TemplateApplySourceInterface
			|| $source->extension_id() !== ( $scope['extension'] ?? null )
			|| $source->designer_type() !== ( $scope['designer'] ?? null )
			|| ! self::allowed( $source ) ) {
			return null;
		}
		try {
			if ( $profile_id !== $source->target_profile_id()
				|| ! \CoreBlueprint\Core\Design\Editor\PlatformTransport::authorized_resource(
					$profile_id, $target_type, $target_key
				)
				|| ! $source->can_view( $template_id, $actor )
				|| ! $source->can_apply_to( $template_id, $target_type, $target_key, $actor ) ) {
				return null;
			}
			$document = $source->instantiate_for_apply( $template_id, $target_type, $target_key, $actor );
			if ( ! is_array( $document )
				|| ! \CoreBlueprint\Core\Design\Editor\PlatformTransport::valid_native_document( $target_type, $document ) ) {
				return null;
			}
			return $document;
		} catch ( \Throwable ) {
			return null;
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
		if ( TemplateSort::TITLE_ASC === $query->sort ) {
			return self::browse_sorted( $query, $sources, $fingerprint, $cursor, $user );
		}
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

	/**
	 * TL-1B1: exact global ordered metadata across bounded opt-in sources.
	 * No template document is loaded here; providers return only authorized
	 * keyset pages and Base validates every item before completing a page.
	 *
	 * @param array<string,TemplateSourceInterface> $sources
	 */
	private static function browse_sorted(
		TemplateQuery $query, array $sources, string $fingerprint, ?string $cursor, int $user
	): TemplatePage {
		if ( count( $sources ) > TemplateSort::MAX_SOURCES ) {
			throw new \DomainException( 'sort_unavailable' );
		}
		foreach ( $sources as $source ) {
			if ( ! $source instanceof TemplateSortedSourceInterface ) {
				throw new \DomainException( 'sort_unavailable' );
			}
		}
		$after = self::read_sorted_cursor( $cursor, $fingerprint, $user );
		$rows = self::sorted_candidates( $sources, $query, $after, $user );
		usort( $rows, [ TemplateSort::class, 'compare' ] );
		$items = array_slice( $rows, 0, $query->limit );
		if ( ! $items ) {
			return new TemplatePage( [] );
		}
		$last = TemplateSort::position( $items[ count( $items ) - 1 ] );
		// An item beyond this page is already known, or perform one bounded
		// peek per eligible source to avoid a spurious final "Load more".
		$has_more = count( $rows ) > $query->limit;
		if ( ! $has_more && count( $items ) === $query->limit ) {
			$has_more = [] !== self::sorted_candidates(
				$sources, $query->with_limit( 1 ), $last, $user
			);
		}
		return new TemplatePage( $items, $has_more
			? self::write_sorted_cursor( $last, $fingerprint, $user ) : null );
	}

	/**
	 * At most 12 providers x 48 metadata items in memory, with an optional
	 * 12-provider peek for the continuation. Never enumerate a full catalog.
	 *
	 * @param array<string,TemplateSourceInterface> $sources
	 * @param array{title:string,source:string,id:string}|null $after
	 * @return list<TemplateMetadata>
	 */
	private static function sorted_candidates(
		array $sources, TemplateQuery $query, ?array $after, int $user
	): array {
		$found = [];
		foreach ( $sources as $id => $source ) {
			try {
				if ( ! $source instanceof TemplateSortedSourceInterface
					|| ! self::allowed( $source ) ) {
					throw new \UnexpectedValueException( 'Sorted provider access unavailable.' );
				}
				$page = $source->query_sorted( $query, $after );
				if ( null !== $page->next_cursor || count( $page->items ) > $query->limit ) {
					throw new \UnexpectedValueException( 'Invalid sorted provider page.' );
				}
				$previous = null;
				foreach ( $page->items as $item ) {
					if ( $item->source !== $id
						|| $item->extension !== $source->extension_id()
						|| $item->designer !== $source->designer_type()
						|| ! self::matches( $item, $query )
						|| ! TemplateSort::valid_position( TemplateSort::position( $item ) )
						|| ! $source->can_view( $item->id, $user )
						|| ( null !== $after && ! TemplateSort::after( $item, $after ) )
						|| ( null !== $previous && TemplateSort::compare( $previous, $item ) >= 0 ) ) {
						throw new \UnexpectedValueException( 'Invalid ordered or unauthorized metadata.' );
					}
					$found[] = $item;
					$previous = $item;
				}
			} catch ( \Throwable ) {
				// Unlike unsorted discovery, silently skipping one provider would
				// falsely advertise a complete globally ordered result.
				throw new \DomainException( 'sort_unavailable' );
			}
		}
		return $found;
	}

	/**
	 * One encrypted/authenticated global seek tuple, NOT private provider
	 * cursors. Plaintext titles must never leak into REST URLs or access logs.
	 * A missing AEAD primitive makes optional sort unavailable, not insecure.
	 *
	 * @return array{title:string,source:string,id:string}|null
	 */
	private static function read_sorted_cursor( ?string $cursor, string $fingerprint, int $user ): ?array {
		if ( null === $cursor ) return null;
		if ( ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'openssl_encrypt' ) ) {
			throw new \DomainException( 'sort_unavailable' );
		}
		if ( strlen( $cursor ) > 2048 || strlen( $cursor ) < 40
			|| ! preg_match( '/^[A-Za-z0-9_-]+$/D', $cursor ) ) {
			throw new \DomainException( 'invalid_cursor' );
		}
		$bytes = base64_decode( strtr( $cursor, '-_', '+/' ), true );
		if ( ! is_string( $bytes ) || strlen( $bytes ) < 29 ) {
			throw new \DomainException( 'invalid_cursor' );
		}
		$iv = substr( $bytes, 0, 12 );
		$tag = substr( $bytes, 12, 16 );
		$encrypted = substr( $bytes, 28 );
		$plain = openssl_decrypt(
			$encrypted, 'aes-256-gcm', self::sorted_cursor_key(),
			OPENSSL_RAW_DATA, $iv, $tag
		);
		if ( ! is_string( $plain ) ) {
			throw new \DomainException( 'invalid_cursor' );
		}
		try {
			$state = json_decode( $plain, true, 8, JSON_THROW_ON_ERROR );
		} catch ( \Throwable ) {
			throw new \DomainException( 'invalid_cursor' );
		}
		if ( ! is_array( $state )
			|| array_keys( $state ) !== [ 'v', 'user', 'blog', 'fingerprint', 'after', 'expires' ]
			|| 2 !== $state['v'] || $user !== $state['user']
			|| get_current_blog_id() !== $state['blog']
			|| $fingerprint !== $state['fingerprint']
			|| ! TemplateSort::valid_position( $state['after'] )
			|| ! is_int( $state['expires'] ) || $state['expires'] < time() ) {
			throw new \DomainException( 'invalid_cursor' );
		}
		return $state['after'];
	}

	/** @param array{title:string,source:string,id:string} $after */
	private static function write_sorted_cursor( array $after, string $fingerprint, int $user ): string {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			throw new \DomainException( 'sort_unavailable' );
		}
		if ( ! TemplateSort::valid_position( $after ) ) {
			throw new \DomainException( 'sort_unavailable' );
		}
		try {
			$body = json_encode( [
				'v' => 2, 'user' => $user, 'blog' => get_current_blog_id(),
				'fingerprint' => $fingerprint, 'after' => $after,
				'expires' => time() + 900,
			], JSON_THROW_ON_ERROR );
			$iv = random_bytes( 12 );
			$tag = '';
			$encrypted = openssl_encrypt(
				$body, 'aes-256-gcm', self::sorted_cursor_key(),
				OPENSSL_RAW_DATA, $iv, $tag, '', 16
			);
		} catch ( \Throwable ) {
			throw new \DomainException( 'sort_unavailable' );
		}
		if ( ! is_string( $encrypted ) || strlen( $tag ) !== 16 ) {
			throw new \DomainException( 'sort_unavailable' );
		}
		return rtrim( strtr( base64_encode( $iv . $tag . $encrypted ), '+/', '-_' ), '=' );
	}

	private static function sorted_cursor_key(): string {
		return hash( 'sha256', 'cb-template-sort-cursor-v2|' . wp_salt( 'auth' ), true );
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
