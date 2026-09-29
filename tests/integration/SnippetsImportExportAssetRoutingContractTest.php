<?php
declare(strict_types=1);

use CB\Core\Admin\ScreenAssetRegistry;

final class SnippetsImportExportAssetRoutingContractTest extends WP_UnitTestCase {

	/** @return string[] */
	private function requirements( string $tab, string $view = '' ): array {
		$method = new ReflectionMethod( ScreenAssetRegistry::class, 'snippets_requirements' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $tab, $view );

		self::assertIsArray( $result );
		return $result;
	}

	public function test_import_export_uses_dedicated_provider_without_list_editor_or_modal_assets(): void {
		$requirements = $this->requirements( 'import-export' );

		self::assertContains( 'provider.snippets-import-export', $requirements );
		self::assertNotContains( 'provider.snippets-list', $requirements );
		self::assertNotContains( 'provider.snippets-editor', $requirements );
		self::assertNotContains( 'foundation.modal', $requirements );
	}

	public function test_settings_tab_does_not_load_snippets_javascript_provider(): void {
		$requirements = $this->requirements( 'settings' );

		self::assertNotContains( 'provider.snippets-list', $requirements );
		self::assertNotContains( 'provider.snippets-editor', $requirements );
	}

	public function test_snippets_list_and_editor_routing_remain_unchanged(): void {
		$list = $this->requirements( 'snippets', 'list' );
		self::assertContains( 'foundation.modal', $list );
		self::assertContains( 'provider.snippets-list', $list );
		self::assertNotContains( 'provider.snippets-editor', $list );

		$editor = $this->requirements( 'snippets', 'edit' );
		self::assertContains( 'foundation.modal', $editor );
		self::assertContains( 'provider.snippets-editor', $editor );
		self::assertNotContains( 'provider.snippets-list', $editor );
	}
}
