<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Editor\PlatformTransport;
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

defined( 'ABSPATH' ) || exit;

/**
 * Reusable Mail-owned snapshots, distinct from registered runtime Mail
 * resources and immutable provider defaults. No cross-product storage.
 */
final class SavedTemplateSource implements
	TemplateApplySourceInterface,
	TemplateManagementSourceInterface,
	TemplateDesignerAuthorityInterface,
	TemplateCategorySourceInterface {

	public const SOURCE_ID = 'core-blueprint-mail-saved';

	public function source_id(): string { return self::SOURCE_ID; }
	public function extension_id(): string { return 'core-blueprint'; }
	public function designer_type(): string { return 'mail'; }
	public function target_profile_id(): string { return \CoreBlueprint\Core\Design\Profile\Mail\PlatformAdapter::PROFILE; }

	public function can_browse( int $user_id ): bool {
		return $user_id > 0 && $user_id === get_current_user_id()
			&& user_can( $user_id, 'manage_options' ) && DesignerState::is_enabled();
	}

	public function can_view( string $template_id, int $user_id ): bool {
		return $this->can_browse( $user_id ) && SavedTemplateStore::valid_id( $template_id )
			&& 'found' === SavedTemplateStore::find( $template_id, $user_id )['status'];
	}

	public function can_manage( string $template_id, int $user_id ): bool {
		return $this->can_view( $template_id, $user_id );
	}

	public function can_access_designer_resource(
		string $resource_type, string $resource_key, int $user_id
	): bool {
		// Saved asset IDs MUST NOT become an operational Mail resource key.
		return $this->can_browse( $user_id ) && Repository::MAIL === $resource_type
			&& null !== TemplateRegistry::get( $resource_key )
			&& PlatformTransport::authorized_resource(
				$this->target_profile_id(), $resource_type, $resource_key
			);
	}

	public function can_launch_designer(
		string $resource_type, string $resource_key, int $user_id
	): bool {
		if ( ! RequestContext::is_admin_screen()
			|| ! $this->can_access_designer_resource( $resource_type, $resource_key, $user_id ) ) return false;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen instanceof \WP_Screen
			&& $screen->id === PageRegistry::hook_suffix( Page::SLUG );
	}

	public function can_apply_to(
		string $template_id, string $target_type, string $target_key, int $actor_id
	): bool {
		return $this->can_view( $template_id, $actor_id )
			&& $this->can_access_designer_resource( $target_type, $target_key, $actor_id );
	}

	public function instantiate_for_apply(
		string $template_id, string $target_type, string $target_key, int $actor_id
	): ?array {
		if ( ! $this->can_apply_to( $template_id, $target_type, $target_key, $actor_id ) ) return null;
		$snapshot = SavedTemplateStore::find( $template_id, $actor_id );
		return 'found' === $snapshot['status'] ? $snapshot['document'] : null;
	}

	public function categories( int $user_id ): array {
		return $this->can_browse( $user_id )
			? [ 'saved' => __( 'Saved Mail templates', 'core-blueprint' ) ] : [];
	}

	public function query( TemplateQuery $query, ?string $cursor ): TemplatePage {
		$actor = get_current_user_id();
		if ( ! $this->can_browse( $actor )
			|| ( '' !== $query->source && self::SOURCE_ID !== $query->source )
			|| ( '' !== $query->extension && 'core-blueprint' !== $query->extension )
			|| ( '' !== $query->designer && 'mail' !== $query->designer )
			|| ( '' !== $query->category && 'saved' !== $query->category )
			|| ( '' !== $query->locale && 'en' !== $query->locale )
			|| ( '' !== $query->origin && ! in_array( $query->origin, [ 'personal', 'site' ], true ) ) ) {
			return new TemplatePage( [] );
		}
		$page = SavedTemplateStore::page( $query, $cursor, $actor );
		if ( 'ready' !== $page['status'] ) return new TemplatePage( [] );
		$items = [];
		foreach ( $page['items'] as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( ! SavedTemplateStore::valid_id( $id ) || ! $this->can_view( $id, $actor ) ) continue;
			try {
				$items[] = new TemplateMetadata(
					self::SOURCE_ID, 'core-blueprint', 'mail', $id,
					(string) $row['title'], '', (string) $row['visibility'],
					'saved', 'en', (string) $row['revision']
				);
			} catch ( \InvalidArgumentException ) {
				continue;
			}
		}
		return new TemplatePage( $items, $page['next'] );
	}

	public function navigation( TemplateMetadata $item, int $user_id ): TemplateNavigation {
		if ( $item->source !== self::SOURCE_ID || $item->designer !== 'mail'
			|| $item->extension !== 'core-blueprint' || ! $this->can_view( $item->id, $user_id ) ) {
			return new TemplateNavigation();
		}
		// Never launch a saved asset as though it were a provider Mail resource.
		$url = add_query_arg(
			[ 'page' => Page::SLUG, 'tab' => 'templates', 'manage_saved' => '1' ],
			admin_url( 'admin.php' )
		);
		return new TemplateNavigation( null, [
			'url' => $url, 'label' => __( 'Manage Mail templates', 'core-blueprint' ),
		] );
	}
}
