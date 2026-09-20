<?php
declare(strict_types=1);
/**
 * Content Models subsystem bootstrap.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\ContentModels;

use CB\Core\Admin\PageRegistry;
use CB\Core\ContentModels\Admin\Actions;
use CB\Core\ContentModels\Admin\MetaBoxes;
use CB\Core\ContentModels\Admin\OptionPages;
use CB\Core\ContentModels\Admin\Page;
use CB\Core\ContentModels\Admin\TermMeta;
use CB\Core\ContentModels\Admin\Transfer;
use CB\Core\ContentModels\Admin\UserMeta;
use CB\Core\ContentModels\Adapters\Bricks\Bootstrap as BricksAdapter;
use CB\Core\ContentModels\Importers\NativeWordPress\Bootstrap as NativeImporter;
use CB\Core\RequestContext;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	public static function boot(): void {
		// Rewrite maintenance always boots so disabling the module can clear
		// previously registered public routes on the following request.
		Rewrite::boot();
		Api::boot();
		add_action( 'init', static function (): void { do_action( 'cb_core_content_models_register', Api::class ); }, 1 );
		Runtime::boot();
		BricksAdapter::boot();

		add_action( 'cb_core_register_pages', static function (): void {
			if ( ! State::is_enabled() ) {
				return;
			}
			PageRegistry::register_base( new Page() );
		} );
		add_action( 'init', [ __CLASS__, 'register_i18n_filters' ], 1 );
		add_action( 'cb_hud_register_items', [ __CLASS__, 'register_hud_item' ] );

		if ( RequestContext::is_admin_screen() ) {
			MetaBoxes::boot();
			OptionPages::boot();
			TermMeta::boot();
			UserMeta::boot();
		}

		if ( RequestContext::is_admin_post() ) {
			Actions::boot();
			OptionPages::boot();
			Transfer::boot();
			NativeImporter::boot();
		}

		if ( RequestContext::is_ajax() ) {
			Actions::boot();
		}
	}

	public static function register_i18n_filters(): void {
		\CB\Core\Governance\EventRegistry::register_core_many( self::register_event_labels( [] ) );
		add_filter( 'cb_core_capability_catalog', [ __CLASS__, 'register_capability' ] );
	}

	/** @param array<string,string> $labels */
	public static function register_event_labels( array $labels ): array {
		$labels['content.models.subsystem.enabled']  = __( 'Content Models: subsystem enabled', 'core-blueprint' );
		$labels['content.models.subsystem.disabled'] = __( 'Content Models: subsystem disabled', 'core-blueprint' );
		$labels['content.models.post.type.created']  = __( 'Content Models: post type created', 'core-blueprint' );
		$labels['content.models.post.type.duplicated'] = __( 'Content Models: post type duplicated', 'core-blueprint' );
		$labels['content.models.post.type.updated']  = __( 'Content Models: post type updated', 'core-blueprint' );
		$labels['content.models.post.type.deleted']  = __( 'Content Models: post type definition deleted', 'core-blueprint' );
		$labels['content.models.taxonomy.created']   = __( 'Content Models: taxonomy created', 'core-blueprint' );
		$labels['content.models.taxonomy.duplicated']  = __( 'Content Models: taxonomy duplicated', 'core-blueprint' );
		$labels['content.models.taxonomy.updated']   = __( 'Content Models: taxonomy updated', 'core-blueprint' );
		$labels['content.models.taxonomy.deleted']   = __( 'Content Models: taxonomy definition deleted', 'core-blueprint' );
		$labels['content.models.option.page.created']    = __( 'Content Models: Option Page created', 'core-blueprint' );
		$labels['content.models.option.page.duplicated'] = __( 'Content Models: Option Page duplicated', 'core-blueprint' );
		$labels['content.models.option.page.updated']    = __( 'Content Models: Option Page updated', 'core-blueprint' );
		$labels['content.models.option.page.deleted']    = __( 'Content Models: Option Page definition deleted', 'core-blueprint' );
		$labels['content.models.option.values.updated']  = __( 'Content Models: Option Page values updated', 'core-blueprint' );
		$labels['content.models.schema.exported'] = __( 'Content Models: schema exported', 'core-blueprint' );
		$labels['content.models.schema.imported'] = __( 'Content Models: schema imported', 'core-blueprint' );
		$labels['content.models.schema.import.acknowledged'] = __( 'Content Models: schema import backup and recovery responsibility acknowledged', 'core-blueprint' );
		$labels['content.models.native.imported'] = __( 'Content Models: native WordPress schema adopted', 'core-blueprint' );
		$labels['content.models.native.import.acknowledged'] = __( 'Content Models: native import backup and recovery responsibility acknowledged', 'core-blueprint' );
		$labels['content.models.field.group.created']  = __( 'Content Models: field group created', 'core-blueprint' );
		$labels['content.models.field.group.duplicated'] = __( 'Content Models: field group duplicated', 'core-blueprint' );
		$labels['content.models.field.group.updated']  = __( 'Content Models: field group updated', 'core-blueprint' );
		$labels['content.models.field.group.deleted']  = __( 'Content Models: field group definition deleted', 'core-blueprint' );
		$labels['content.models.field.created']        = __( 'Content Models: field created', 'core-blueprint' );
		$labels['content.models.field.duplicated']       = __( 'Content Models: field duplicated', 'core-blueprint' );
		$labels['content.models.field.updated']        = __( 'Content Models: field updated', 'core-blueprint' );
		$labels['content.models.field.deleted']        = __( 'Content Models: field definition deleted', 'core-blueprint' );
		return $labels;
	}

	/** @param array<string,array<string,mixed>> $catalog */
	public static function register_capability( array $catalog ): array {
		$catalog['cb_manage_content_models'] = [
			'label'       => __( 'Manage content models', 'core-blueprint' ),
			'group'       => __( 'Core Blueprint', 'core-blueprint' ),
			'source'      => 'Core Blueprint',
			'description' => __( 'Create and manage Core Blueprint custom post types, taxonomies, Option Pages, field groups and field schema definitions.', 'core-blueprint' ),
		];
		return $catalog;
	}

	public static function register_hud_item( string $registry ): void {
		if ( ! current_user_can( 'cb_manage_content_models' ) || ! class_exists( $registry ) ) {
			return;
		}
		$registry::add_item( [
			'id'         => 'cb-hud-content-models',
			'label'      => __( 'Content Models', 'core-blueprint' ),
			'section'    => 'cb-core',
			'url'        => admin_url( 'admin.php?page=' . Page::SLUG ),
			'order'      => 23,
			'capability' => 'cb_manage_content_models',
			'icon'       => 'database',
			'module'     => 'content-models',
		] );
	}
}
