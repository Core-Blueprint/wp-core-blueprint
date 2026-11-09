<?php
declare(strict_types=1);

/**
 * TL-6B Level 1: execute the real native Reports TemplateSource without WP/DB.
 * WordPress-only state, auth and screen APIs are minimal independent fixtures.
 */
namespace CoreBlueprint\Core\Admin {
	final class PageRegistry {
		public static function hook_suffix( string $slug ): string {
			return 'toplevel_page_' . $slug;
		}
	}
}
namespace CoreBlueprint\Core\Admin\Pages {
	final class Preferences {
		public const SLUG = 'core-blueprint-preferences';
	}
}
namespace CoreBlueprint\Core\Design\Document {
	final class Repository {
		public const REPORTS = 'base.maintenance-report-design';
		public const MAIL = 'base.mail-template';
	}
}
namespace CoreBlueprint\Core\Design\Editor {
	final class Assets {
		public static bool $fullscreen = false;
		public static function platform_fullscreen_requested(): bool {
			return self::$fullscreen;
		}
	}
}
namespace CoreBlueprint\Core {
	final class RequestContext {
		public static bool $admin_screen = true;
		public static function is_admin_screen(): bool {
			return self::$admin_screen;
		}
	}
}
namespace CoreBlueprint\Core\Reports {
	final class State {
		public static bool $enabled = true;
		public static function is_enabled(): bool {
			return self::$enabled;
		}
	}
	final class DesignDocumentAdapter {
		public const KEY = 'maintenance';
	}
}
namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );

	use CoreBlueprint\Core\Admin\Pages\Preferences;
	use CoreBlueprint\Core\Design\Document\Repository;
	use CoreBlueprint\Core\Design\Editor\Assets;
	use CoreBlueprint\Core\Reports\NativeTemplateSource;
	use CoreBlueprint\Core\Reports\State;
	use CoreBlueprint\Core\RequestContext;
	use CoreBlueprint\Core\TemplateLibrary\TemplateMetadata;
	use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;

	class WP_Screen {
		public function __construct( public string $id ) {}
	}

	$GLOBALS['tl_reports_actor'] = 17;
	$GLOBALS['tl_reports_can_brand'] = true;
	$GLOBALS['tl_reports_screen'] = new WP_Screen( 'front' );

	function __( string $text, string $domain = '' ): string { return $text; }
	function wp_strip_all_tags( string $text ): string { return strip_tags( $text ); }
	function get_current_user_id(): int { return $GLOBALS['tl_reports_actor']; }
	function user_can( int $id, string $capability ): bool {
		return 'cb_manage_branding' === $capability
			&& $id === $GLOBALS['tl_reports_actor']
			&& $GLOBALS['tl_reports_can_brand'];
	}
	function get_current_screen(): WP_Screen { return $GLOBALS['tl_reports_screen']; }
	function wp_parse_url( string $url ): array|false { return parse_url( $url ); }
	function admin_url( string $path = '' ): string { return 'https://example.test/wp-admin/' . $path; }
	function add_query_arg( array $args, string $url ): string {
		return $url . '?' . http_build_query( $args );
	}
	function sanitize_key( string $value ): string {
		return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) );
	}
	function wp_unslash( string $value ): string { return stripslashes( $value ); }
	function tl_reports_assert( bool $condition, string $message ): void {
		if ( ! $condition ) throw new \RuntimeException( 'TL-6B: ' . $message );
	}

	$root = dirname( __DIR__ );
	foreach ( [
		'src/TemplateLibrary/TemplateQuery.php',
		'src/TemplateLibrary/TemplateSourceInterface.php',
		'src/TemplateLibrary/TemplateManagementSourceInterface.php',
		'src/TemplateLibrary/TemplateDesignerAuthorityInterface.php',
		'src/TemplateLibrary/TemplateCategorySourceInterface.php',
		'src/TemplateLibrary/TemplateMetadata.php',
		'src/TemplateLibrary/TemplatePage.php',
		'src/TemplateLibrary/TemplateNavigation.php',
		'src/Reports/NativeTemplateSource.php',
	] as $file ) {
		require_once $root . '/' . $file;
	}

	$source = new NativeTemplateSource();
	tl_reports_assert( 'core-blueprint-reports' === $source->source_id(), 'canonical source ID' );
	tl_reports_assert( 'core-blueprint' === $source->extension_id(), 'canonical Base identity' );
	tl_reports_assert( 'reports' === $source->designer_type(), 'correct Designer domain' );
	tl_reports_assert( $source->can_browse( 17 ), 'authorized operator can browse' );
	tl_reports_assert( ! $source->can_browse( 18 ), 'no actor impersonation' );
	tl_reports_assert( $source->can_view( 'maintenance', 17 ), 'one immutable native template' );
	tl_reports_assert( ! $source->can_view( 'other', 17 ), 'unknown ID denied' );
	tl_reports_assert( ! $source->can_manage( 'maintenance', 17 ), 'built-in never manageable' );
	tl_reports_assert(
		[ 'maintenance' => 'Maintenance Report' ] === $source->categories( 17 ),
		'category facet stays public and bounded'
	);

	$page = $source->query( new TemplateQuery(), null );
	tl_reports_assert( 1 === count( $page->items ), 'one built-in template' );
	tl_reports_assert( null === $page->next_cursor, 'no second page' );
	$item = $page->items[0];
	tl_reports_assert( $item instanceof TemplateMetadata, 'typed public metadata only' );
	tl_reports_assert( 'built-in' === $item->origin && 'reports' === $item->designer, 'correct origin/scope' );
	tl_reports_assert( ! property_exists( $item, 'document' ), 'no report design document leaked' );
	tl_reports_assert( ! property_exists( $item, 'branding' ), 'no provider branding leaked' );

	foreach ( [
		new TemplateQuery( source: 'core-blueprint-mail' ),
		new TemplateQuery( designer: 'mail' ),
		new TemplateQuery( extension: 'other-extension' ),
		new TemplateQuery( origin: 'personal' ),
		new TemplateQuery( category: 'other' ),
		new TemplateQuery( locale: 'nl' ),
		new TemplateQuery( search: 'nothing-matches' ),
	] as $filter ) {
		tl_reports_assert( [] === $source->query( $filter, null )->items, 'rejected cross-scope filter' );
	}
	tl_reports_assert( 1 === count( $source->query( new TemplateQuery( search: 'branding' ), null )->items ),
		'search matches canonical description' );
	tl_reports_assert( [] === $source->query( new TemplateQuery(), '0' )->items,
		'untrusted cursor cannot create a second page' );

	$navigation = $source->navigation( $item, 17 );
	tl_reports_assert( null === $navigation->manage, 'read-only origin has no manage endpoint' );
	tl_reports_assert( is_array( $navigation->preview ), 'safe preview navigation exists' );
	tl_reports_assert(
		str_contains( $navigation->preview['url'], 'page=core-blueprint-preferences' )
		&& str_contains( $navigation->preview['url'], 'tab=reports' )
		&& str_contains( $navigation->preview['url'], 'cb_designer=1' ),
		'preview opens existing native Reports Designer route'
	);
	$forged = new TemplateMetadata(
		'core-blueprint-reports', 'core-blueprint', 'reports', 'maintenance',
		'Forged metadata', '', 'personal', 'maintenance', 'en', '1'
	);
	tl_reports_assert( [] === $source->navigation( $forged, 17 )->public_links( true ),
		'forged personal origin denied' );
	tl_reports_assert(
		$source->can_access_designer_resource( Repository::REPORTS, 'maintenance', 17 ),
		'canonical resource admitted'
	);
	tl_reports_assert(
		! $source->can_access_designer_resource( Repository::MAIL, 'maintenance', 17 )
		&& ! $source->can_access_designer_resource( Repository::REPORTS, 'other', 17 ),
		'other resource types/keys denied'
	);

	$_GET = [ 'page' => Preferences::SLUG, 'tab' => 'reports', 'cb_designer' => '1' ];
	Assets::$fullscreen = true;
	$GLOBALS['tl_reports_screen'] = new WP_Screen( 'toplevel_page_core-blueprint-preferences' );
	tl_reports_assert( $source->can_launch_designer( Repository::REPORTS, 'maintenance', 17 ),
		'scope may be minted only on exact canonical Reports Designer route' );
	$_GET['tab'] = 'notes';
	tl_reports_assert( ! $source->can_launch_designer( Repository::REPORTS, 'maintenance', 17 ),
		'unrelated Preferences tab denied' );
	$_GET['tab'] = 'reports';
	RequestContext::$admin_screen = false;
	tl_reports_assert( ! $source->can_launch_designer( Repository::REPORTS, 'maintenance', 17 ),
		'AJAX/non-screen launch denied' );
	RequestContext::$admin_screen = true;

	State::$enabled = false;
	tl_reports_assert( ! $source->can_browse( 17 ), 'disabled Reports hidden' );
	tl_reports_assert( [] === $source->categories( 17 ), 'disabled facet hidden' );
	tl_reports_assert( [] === $source->query( new TemplateQuery(), null )->items, 'disabled browse empty' );
	tl_reports_assert( [] === $source->navigation( $item, 17 )->public_links( true ),
		'disabled navigation hidden' );
	State::$enabled = true;
	$GLOBALS['tl_reports_can_brand'] = false;
	tl_reports_assert( ! $source->can_browse( 17 ), 'branding capability required' );
	tl_reports_assert( ! $source->can_access_designer_resource(
		Repository::REPORTS, 'maintenance', 17
	), 'capability revocation prevents resource access' );
	$GLOBALS['tl_reports_can_brand'] = true;
	$GLOBALS['tl_reports_actor'] = 18;
	tl_reports_assert( ! $source->can_browse( 17 ), 'different actor cannot re-use authority' );

	$bootstrap = (string) file_get_contents( $root . '/src/Reports/Bootstrap.php' );
	tl_reports_assert(
		str_contains( $bootstrap, 'core_blueprint_register_template_sources' )
		&& str_contains( $bootstrap, 'new NativeTemplateSource()' ),
		'exact native source registered via public hook'
	);
	echo "Base TL-6B native Reports Template Library source regression: PASS\n";
}
