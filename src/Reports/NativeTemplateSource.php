<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Reports;

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\Admin\Pages\Preferences;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Editor\Assets as DesignEditorAssets;
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

defined( 'ABSPATH' ) || exit;

/**
 * Read-only Library projection of the single built-in Maintenance Reports
 * design. No generated report, saved document or branding value is read here.
 *
 * Reports retains its one authoritative Designer/TransactionalSave and storage.
 * This source does not create a new user-template repository or write endpoint.
 */
final class NativeTemplateSource implements
	TemplateApplySourceInterface,
	TemplateManagementSourceInterface,
	TemplateDesignerAuthorityInterface,
	TemplateCategorySourceInterface,
	TemplateSortedSourceInterface {

	public const SOURCE_ID = 'core-blueprint-reports';
	public const TEMPLATE_ID = 'maintenance';
	public const CATEGORY = 'maintenance';

	public function source_id(): string {
		return self::SOURCE_ID;
	}

	public function extension_id(): string {
		return 'core-blueprint';
	}

	public function designer_type(): string {
		return 'reports';
	}

	public function can_browse( int $user_id ): bool {
		return $user_id > 0
			&& $user_id === get_current_user_id()
			&& user_can( $user_id, 'cb_manage_branding' )
			&& State::is_enabled();
	}

	public function can_view( string $template_id, int $user_id ): bool {
		return self::TEMPLATE_ID === $template_id && $this->can_browse( $user_id );
	}

	public function target_profile_id(): string {
		return \CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter::PROFILE;
	}

	public function can_apply_to( string $template_id, string $target_type, string $target_key, int $actor_id ): bool {
		return $this->can_view( $template_id, $actor_id )
			&& $this->can_access_designer_resource( $target_type, $target_key, $actor_id );
	}

	public function instantiate_for_apply(
		string $template_id, string $target_type, string $target_key, int $actor_id
	): ?array {
		if ( ! $this->can_apply_to( $template_id, $target_type, $target_key, $actor_id ) ) return null;
		return [
			'project' => \CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter::default_project(),
			'fields' => \CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter::default_fields(),
		];
	}

	public function can_manage( string $template_id, int $user_id ): bool {
		// The canonical built-in design is immutable as a Library template.
		// All real Reports document writes go through PlatformTransport.
		return false;
	}

	public function can_access_designer_resource(
		string $resource_type, string $resource_key, int $user_id
	): bool {
		return Repository::REPORTS === $resource_type
			&& DesignDocumentAdapter::KEY === $resource_key
			&& $this->can_view( $resource_key, $user_id );
	}

	public function can_launch_designer(
		string $resource_type, string $resource_key, int $user_id
	): bool {
		if ( ! RequestContext::is_admin_screen()
			|| ! $this->can_access_designer_resource( $resource_type, $resource_key, $user_id )
			|| ! DesignEditorAssets::platform_fullscreen_requested() ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen
			|| $screen->id !== PageRegistry::hook_suffix( Preferences::SLUG ) ) {
			return false;
		}
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] )
			? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] )
			? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return Preferences::SLUG === $page && 'reports' === $tab;
	}

	public function categories( int $user_id ): array {
		return $this->can_browse( $user_id )
			? [ self::CATEGORY => __( 'Maintenance Report', 'core-blueprint' ) ]
			: [];
	}

	public function query( TemplateQuery $query, ?string $cursor ): TemplatePage {
		if ( ! $this->can_browse( get_current_user_id() )
			|| null !== $cursor
			|| ( '' !== $query->source && self::SOURCE_ID !== $query->source )
			|| ( '' !== $query->extension && 'core-blueprint' !== $query->extension )
			|| ( '' !== $query->designer && 'reports' !== $query->designer )
			|| ( '' !== $query->origin && 'built-in' !== $query->origin )
			|| ( '' !== $query->category && self::CATEGORY !== $query->category )
			|| ( '' !== $query->locale && 'en' !== $query->locale ) ) {
			return new TemplatePage( [] );
		}

		$title = __( 'Maintenance Report', 'core-blueprint' );
		$description = __( 'Edit the maintenance report composition and branding.', 'core-blueprint' );
		if ( '' !== $query->search
			&& false === stripos( $title . ' ' . $description, $query->search ) ) {
			return new TemplatePage( [] );
		}
		return new TemplatePage( [
			new TemplateMetadata(
				self::SOURCE_ID, 'core-blueprint', 'reports', self::TEMPLATE_ID,
				$title, $description, 'built-in', self::CATEGORY, 'en', '1'
			),
		] );
	}

	/** One built-in Reports resource; no documents or production reports are loaded. */
	public function query_sorted( TemplateQuery $query, ?array $after ): TemplatePage {
		if ( TemplateSort::TITLE_ASC !== $query->sort ) return new TemplatePage( [] );
		$page = $this->query( $query, null );
		return new TemplatePage( array_values( array_filter( $page->items,
			static fn ( TemplateMetadata $item ): bool =>
				null === $after || TemplateSort::after( $item, $after )
		) ) );
	}

	public function navigation( TemplateMetadata $item, int $user_id ): TemplateNavigation {
		if ( self::SOURCE_ID !== $item->source
			|| 'core-blueprint' !== $item->extension
			|| 'reports' !== $item->designer
			|| 'built-in' !== $item->origin
			|| self::CATEGORY !== $item->category
			|| ! $this->can_view( $item->id, $user_id ) ) {
			return new TemplateNavigation();
		}
		$url = add_query_arg(
			[ 'page' => Preferences::SLUG, 'tab' => 'reports', 'cb_designer' => '1' ],
			admin_url( 'admin.php' )
		);
		return new TemplateNavigation( [
			'url' => $url,
			'label' => __( 'Reports Designer', 'core-blueprint' ),
		] );
	}
}
