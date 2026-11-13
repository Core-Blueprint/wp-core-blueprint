<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Mail\Admin\Page;
use CoreBlueprint\Core\Mail\DesignerState;
use CoreBlueprint\Core\RequestContext;
use CoreBlueprint\Core\TemplateLibrary\TemplateApplySourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateCategorySourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateDesignerAuthorityInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateManagementSourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateMetadata;
use CoreBlueprint\Core\TemplateLibrary\TemplateNavigation;
use CoreBlueprint\Core\TemplateLibrary\TemplatePage;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;
use CoreBlueprint\Core\TemplateLibrary\TemplateSort;
use CoreBlueprint\Core\TemplateLibrary\TemplateSortedSourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplatePreview;
use CoreBlueprint\Core\TemplateLibrary\TemplatePreviewSourceInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only bridge from the authoritative native Mail template catalog to the
 * public Template Library. This never creates a second Save or storage path.
 *
 * The Mail registry owns provider defaults and access to the actual editor.
 * The Template Library owns only metadata discovery and safe navigation.
 */
final class NativeTemplateSource implements
	TemplateApplySourceInterface,
	TemplateManagementSourceInterface,
	TemplateDesignerAuthorityInterface,
	TemplateCategorySourceInterface,
	TemplateSortedSourceInterface,
	TemplatePreviewSourceInterface {

	public const SOURCE_ID = 'core-blueprint-mail';

	public function source_id(): string {
		return self::SOURCE_ID;
	}

	public function extension_id(): string {
		return 'core-blueprint';
	}

	public function designer_type(): string {
		return 'mail';
	}

	public function can_browse( int $user_id ): bool {
		return $user_id > 0
			&& $user_id === get_current_user_id()
			&& user_can( $user_id, 'manage_options' )
			&& DesignerState::is_enabled();
	}

	public function can_view( string $template_id, int $user_id ): bool {
		return $this->can_browse( $user_id )
			&& TemplateQuery::valid_id( $template_id )
			&& null !== TemplateRegistry::get( $template_id );
	}

	/**
	 * Only Base-owned, immutable WordPress Mail templates are previewable in v1.
	 * Other registered Mail providers retain metadata and navigation, but must
	 * opt into their own reviewed synthetic preview boundary.
	 */
	public function can_preview( string $template_id, int $actor_id ): bool {
		return $this->can_view( $template_id, $actor_id )
			&& null !== WordPressTemplates::default_document( $template_id );
	}

	public function render_preview( string $template_id, string $expected_version, int $actor_id ): ?TemplatePreview {
		if ( '1' !== $expected_version || ! $this->can_preview( $template_id, $actor_id ) ) {
			return null;
		}
		$document = WordPressTemplates::default_document( $template_id );
		if ( null === $document ) return null;
		return LibraryPreview::render( $document, '1' );
	}

	public function target_profile_id(): string {
		return \CoreBlueprint\Core\Design\Profile\Mail\PlatformAdapter::PROFILE;
	}

	public function can_apply_to( string $template_id, string $target_type, string $target_key, int $actor_id ): bool {
		return Repository::MAIL === $target_type
			&& $this->can_view( $template_id, $actor_id )
			&& $this->can_access_designer_resource( $target_type, $target_key, $actor_id );
	}

	public function instantiate_for_apply(
		string $template_id, string $target_type, string $target_key, int $actor_id
	): ?array {
		return $this->can_apply_to( $template_id, $target_type, $target_key, $actor_id )
			? TemplateRegistry::default_document( $template_id ) : null;
	}

	public function can_manage( string $template_id, int $user_id ): bool {
		// Provider defaults are not personal/site instances and cannot be
		// mutated through Library discovery. Native Mail writes remain owned
		// by the authenticated PlatformTransport/TransactionalSave boundary.
		return false;
	}

	public function can_access_designer_resource(
		string $resource_type,
		string $resource_key,
		int $user_id
	): bool {
		return Repository::MAIL === $resource_type
			&& $this->can_view( $resource_key, $user_id );
	}

	public function can_launch_designer(
		string $resource_type,
		string $resource_key,
		int $user_id
	): bool {
		if ( ! RequestContext::is_admin_screen()
			|| ! $this->can_access_designer_resource( $resource_type, $resource_key, $user_id ) ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen instanceof \WP_Screen
			&& $screen->id === PageRegistry::hook_suffix( Page::SLUG );
	}

	public function categories( int $user_id ): array {
		if ( ! $this->can_browse( $user_id ) ) {
			return [];
		}
		$categories = [];
		foreach ( TemplateRegistry::all() as $definition ) {
			$category = $definition['group'] ?? null;
			if ( ! is_string( $category ) || ! TemplateQuery::valid_id( $category ) ) {
				continue;
			}
			$categories[ $category ] = ucfirst( str_replace( [ '-', '_' ], ' ', $category ) );
			if ( count( $categories ) >= 48 ) {
				break;
			}
		}
		ksort( $categories, SORT_STRING );
		return $categories;
	}

	public function query( TemplateQuery $query, ?string $cursor ): TemplatePage {
		if ( ! $this->can_browse( get_current_user_id() )
			|| ( '' !== $query->source && self::SOURCE_ID !== $query->source )
			|| ( '' !== $query->extension && 'core-blueprint' !== $query->extension )
			|| ( '' !== $query->designer && 'mail' !== $query->designer )
			|| ( '' !== $query->origin && 'built-in' !== $query->origin )
			|| ( '' !== $query->locale && 'en' !== $query->locale ) ) {
			return new TemplatePage( [] );
		}

		if ( null !== $cursor && (
			! ctype_digit( $cursor )
			|| strlen( $cursor ) > 7
			|| ( strlen( $cursor ) > 1 && '0' === $cursor[0] )
		) ) {
			return new TemplatePage( [] );
		}
		$offset = null === $cursor ? 0 : (int) $cursor;
		$filtered = [];
		$definitions = TemplateRegistry::all();
		ksort( $definitions, SORT_STRING ); // Stable across localized labels.
		foreach ( $definitions as $id => $definition ) {
			if ( ! $this->can_view( (string) $id, get_current_user_id() ) ) {
				continue;
			}
			$category = (string) ( $definition['group'] ?? '' );
			if ( ! TemplateQuery::valid_id( $category )
				|| ( '' !== $query->category && $category !== $query->category ) ) {
				continue;
			}
			$title = (string) ( $definition['label'] ?? '' );
			$description = (string) ( $definition['description'] ?? '' );
			if ( '' !== $query->search
				&& false === stripos( $title . ' ' . $description, $query->search ) ) {
				continue;
			}
			try {
				$filtered[] = new TemplateMetadata(
					self::SOURCE_ID, 'core-blueprint', 'mail', (string) $id,
					$title, $description, 'built-in', $category, 'en', '1'
				);
			} catch ( \InvalidArgumentException ) {
				// Invalid provider metadata must not leak or break discovery.
				continue;
			}
		}
		$total = count( $filtered );
		if ( $offset > $total ) {
			return new TemplatePage( [] );
		}
		$items = array_slice( $filtered, $offset, $query->limit );
		$next = $offset + count( $items );
		return new TemplatePage( $items, $next < $total ? (string) $next : null );
	}

	/** Exact bytewise UTF-8 title, source ID and item ID ordering. */
	public function query_sorted( TemplateQuery $query, ?array $after ): TemplatePage {
		if ( TemplateSort::TITLE_ASC !== $query->sort
			|| ! $this->can_browse( get_current_user_id() )
			|| ( '' !== $query->source && self::SOURCE_ID !== $query->source )
			|| ( '' !== $query->extension && 'core-blueprint' !== $query->extension )
			|| ( '' !== $query->designer && 'mail' !== $query->designer )
			|| ( '' !== $query->origin && 'built-in' !== $query->origin )
			|| ( '' !== $query->locale && 'en' !== $query->locale ) ) {
			return new TemplatePage( [] );
		}
		$items = [];
		foreach ( TemplateRegistry::all() as $id => $definition ) {
			if ( ! $this->can_view( (string) $id, get_current_user_id() ) ) continue;
			$category = (string) ( $definition['group'] ?? '' );
			if ( ! TemplateQuery::valid_id( $category )
				|| ( '' !== $query->category && $category !== $query->category ) ) continue;
			$title = (string) ( $definition['label'] ?? '' );
			$description = (string) ( $definition['description'] ?? '' );
			if ( '' !== $query->search
				&& false === stripos( $title . ' ' . $description, $query->search ) ) continue;
			try {
				$item = new TemplateMetadata(
					self::SOURCE_ID, 'core-blueprint', 'mail', (string) $id,
					$title, $description, 'built-in', $category, 'en', '1'
				);
				if ( null === $after || TemplateSort::after( $item, $after ) ) {
					// Keep only the lowest 48 (or fewer) items in this in-memory
					// provider scan; never accumulate the entire catalog for sorting.
					$items[] = $item;
					usort( $items, [ TemplateSort::class, 'compare' ] );
					if ( count( $items ) > $query->limit ) array_pop( $items );
				}
			} catch ( \InvalidArgumentException ) {
				// Broken local definition is not browseable.
			}
		}
		return new TemplatePage( $items );
	}

	public function navigation( TemplateMetadata $item, int $user_id ): TemplateNavigation {
		if ( $item->source !== self::SOURCE_ID
			|| $item->extension !== 'core-blueprint'
			|| $item->designer !== 'mail'
			|| ! $this->can_view( $item->id, $user_id ) ) {
			return new TemplateNavigation();
		}

		$url = add_query_arg(
			[ 'page' => Page::SLUG, 'tab' => 'templates', 'template' => $item->id ],
			admin_url( 'admin.php' )
		);
		return new TemplateNavigation( [
			'url' => $url,
			'label' => __( 'Mail Designer', 'core-blueprint' ),
		] );
	}
}
