<?php
declare(strict_types=1);

use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\TemplateLibrary\BrowserController;
use CoreBlueprint\Core\TemplateLibrary\DesignerScope;
use CoreBlueprint\Core\TemplateLibrary\TemplateDesignerAuthorityInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateMetadata;
use CoreBlueprint\Core\TemplateLibrary\TemplatePage;
use CoreBlueprint\Core\TemplateLibrary\TemplatePreview;
use CoreBlueprint\Core\TemplateLibrary\TemplatePreviewSourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

final class CB_Template_Library_Preview_Transport_Test extends WP_UnitTestCase {
	private int $actor_id;
	private bool $had_cookie;
	private mixed $original_cookie;
	private TemplatePreviewSourceInterface $fixture;

	public function set_up(): void {
		parent::set_up();
		$this->actor_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $this->actor_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'template_preview_fixture' ) );
		$this->had_cookie = array_key_exists( LOGGED_IN_COOKIE, $_COOKIE );
		$this->original_cookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;
		$this->set_session( $this->actor_id );
		set_current_screen( 'core-blueprint-test-mail' );
		$this->fixture = new class implements TemplatePreviewSourceInterface, TemplateDesignerAuthorityInterface {
			public bool $allow = true;
			public bool $fail = false;
			public bool $revoke_session_during_render = false;
			public int $renders = 0;
			public string $markup = '<p>Safe sample</p>';
			public string $current_version = '1';
			public function source_id(): string { return 'core-blueprint-preview-fixture'; }
			public function extension_id(): string { return 'core-blueprint'; }
			public function designer_type(): string { return 'mail'; }
			public function can_browse( int $user_id ): bool {
				return $user_id > 0 && $user_id === get_current_user_id() && user_can( $user_id, 'manage_options' );
			}
			public function can_view( string $template_id, int $user_id ): bool {
				return 'example' === $template_id && $this->can_browse( $user_id );
			}
			public function can_preview( string $template_id, int $actor_id ): bool {
				return $this->allow && $this->can_view( $template_id, $actor_id );
			}
			public function render_preview( string $template_id, string $expected_version, int $actor_id ): ?TemplatePreview {
				++$this->renders;
				if ( $this->fail ) throw new \RuntimeException( 'Provider secret detail' );
				if ( $this->revoke_session_during_render ) {
					WP_Session_Tokens::get_instance( $actor_id )->destroy( wp_get_session_token() );
				}
				if ( ! $this->can_preview( $template_id, $actor_id ) || $this->current_version !== $expected_version ) {
					return null;
				}
				return new TemplatePreview( $this->current_version, $this->markup );
			}
			public function can_access_designer_resource( string $resource_type, string $resource_key, int $user_id ): bool {
				return 'mail' === $resource_type && 'fixture-mail' === $resource_key && $this->can_browse( $user_id );
			}
			public function can_launch_designer( string $resource_type, string $resource_key, int $user_id ): bool {
				$screen = get_current_screen();
				return $screen instanceof WP_Screen && $screen->id === 'core-blueprint-test-mail'
					&& $this->can_access_designer_resource( $resource_type, $resource_key, $user_id );
			}
			public function query( TemplateQuery $query, ?string $cursor ): TemplatePage {
				return new TemplatePage( [ new TemplateMetadata(
					$this->source_id(), 'core-blueprint', 'mail', 'example',
					'Example', '', 'built-in', 'example', 'en', $this->current_version
				) ] );
			}
		};
		TemplateSourceRegistry::_reset_for_testing();
		add_action( 'core_blueprint_register_template_sources', [ $this, 'register_fixture' ] );
	}

	public function register_fixture(): void {
		TemplateSourceRegistry::register( $this->fixture );
		// Metadata-only provider proves the optional interface is not required.
		TemplateSourceRegistry::register( new class implements \CoreBlueprint\Core\TemplateLibrary\TemplateSourceInterface {
			public function source_id(): string { return 'core-blueprint-preview-unavailable'; }
			public function extension_id(): string { return 'core-blueprint'; }
			public function designer_type(): string { return 'mail'; }
			public function can_browse( int $user_id ): bool { return $user_id > 0 && user_can( $user_id, 'manage_options' ); }
			public function can_view( string $template_id, int $user_id ): bool {
				return 'example' === $template_id && $this->can_browse( $user_id );
			}
			public function query( TemplateQuery $query, ?string $cursor ): TemplatePage {
				return new TemplatePage( [ new TemplateMetadata(
					$this->source_id(), 'core-blueprint', 'mail', 'example',
					'Plain', '', 'built-in', 'example', 'en', '1'
				) ] );
			}
		} );
		// Distinct, independently authenticated Designer authority, but not a
		// preview provider. Its token must NOT unlock the Mail preview source.
		TemplateSourceRegistry::register( new class implements \CoreBlueprint\Core\TemplateLibrary\TemplateSourceInterface, TemplateDesignerAuthorityInterface {
			public function source_id(): string { return 'core-blueprint-preview-scope-reports'; }
			public function extension_id(): string { return 'core-blueprint'; }
			public function designer_type(): string { return 'reports'; }
			public function can_browse( int $user_id ): bool { return $user_id > 0 && user_can( $user_id, 'manage_options' ); }
			public function can_view( string $template_id, int $user_id ): bool { return false; }
			public function query( TemplateQuery $query, ?string $cursor ): TemplatePage { return new TemplatePage( [] ); }
			public function can_access_designer_resource( string $resource_type, string $resource_key, int $actor_id ): bool {
				return 'reports' === $resource_type && 'fixture-reports' === $resource_key
					&& $this->can_browse( $actor_id );
			}
			public function can_launch_designer( string $resource_type, string $resource_key, int $actor_id ): bool {
				$screen = get_current_screen();
				return $screen instanceof WP_Screen && $screen->id === 'core-blueprint-test-reports'
					&& $this->can_access_designer_resource( $resource_type, $resource_key, $actor_id );
			}
		} );
	}

	private function set_session( int $user_id ): void {
		wp_set_current_user( $user_id );
		$expires = time() + HOUR_IN_SECONDS;
		$token = WP_Session_Tokens::get_instance( $user_id )->create( $expires );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $expires, 'logged_in', $token );
	}

	private function preview_request( array $override = [], bool $nonce = true, string $scope = '' ): WP_REST_Request {
		$payload = array_merge( [
			'source' => 'core-blueprint-preview-fixture', 'templateId' => 'example',
			'version' => '1', 'contextEpoch' => 0,
		], $override );
		$request = new WP_REST_Request( 'POST', '/core-blueprint/v1/template-library/preview' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $nonce ) $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		if ( '' !== $scope ) $request->set_header( 'X-CB-Designer-Scope', $scope );
		$request->set_body( wp_json_encode( $payload ) );
		return $request;
	}

	public function tear_down(): void {
		remove_action( 'core_blueprint_register_template_sources', [ $this, 'register_fixture' ] );
		TemplateSourceRegistry::_reset_for_testing();
		set_current_screen( 'front' );
		if ( $this->had_cookie ) $_COOKIE[ LOGGED_IN_COOKIE ] = $this->original_cookie;
		else unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_registered_preview_transport_is_explicit_nonce_guarded_and_metadata_only(): void {
		$route = '/core-blueprint/v1/template-library/preview';
		self::assertArrayHasKey( $route, rest_get_server()->get_routes() );
		$listing = rest_do_request( new WP_REST_Request( 'GET', '/core-blueprint/v1/template-library/templates' ) )->get_data();
		$found = array_values( array_filter( $listing['items'], static fn ( array $row ): bool => 'core-blueprint-preview-fixture' === $row['source'] ) );
		self::assertCount( 1, $found );
		self::assertArrayNotHasKey( 'html', $found[0] );
		self::assertSame( 0, $this->fixture->renders );
		$source = rest_do_request( new WP_REST_Request( 'GET', '/core-blueprint/v1/template-library/sources' ) )->get_data()['sources'];
		$capability = array_values( array_filter( $source, static fn ( array $row ): bool => 'core-blueprint-preview-fixture' === $row['id'] ) );
		self::assertSame( [ 1 ], $capability[0]['previews'] );

		self::assertSame( 403, rest_do_request( $this->preview_request( [], false ) )->get_status() );
		self::assertSame( 0, $this->fixture->renders );
		$result = rest_do_request( $this->preview_request() );
		self::assertSame( 200, $result->get_status() );
		self::assertSame( '<p>Safe sample</p>', $result->get_data()['html'] );
		self::assertSame( 1, $result->get_data()['apiVersion'] );
		self::assertSame( 1, $this->fixture->renders );
		self::assertStringContainsString( 'no-store', $result->get_headers()['Cache-Control'] );
	}

	public function test_per_item_acl_version_and_provider_errors_refuse_without_leaks(): void {
		foreach ( [
			[ 'templateId' => 'missing' ], [ 'source' => 'unknown-source' ],
			[ 'version' => '9' ],
		] as $payload ) {
			self::assertSame( 403, rest_do_request( $this->preview_request( $payload ) )->get_status() );
		}
		$this->fixture->allow = false;
		self::assertSame( 403, rest_do_request( $this->preview_request() )->get_status() );
		$this->fixture->allow = true;
		$this->fixture->fail = true;
		$failed = rest_do_request( $this->preview_request() );
		self::assertSame( 403, $failed->get_status() );
		self::assertStringNotContainsString( 'Provider secret', wp_json_encode( $failed->get_data() ) );
	}

	public function test_previewless_provider_remains_visible_without_preview_transport(): void {
		$sources = rest_do_request( new WP_REST_Request(
			'GET', '/core-blueprint/v1/template-library/sources'
		) )->get_data()['sources'];
		$none = array_values( array_filter( $sources, static fn ( array $row ): bool =>
			'core-blueprint-preview-unavailable' === $row['id'] ) );
		self::assertCount( 1, $none );
		self::assertSame( [], $none[0]['previews'] );
		self::assertSame( 403, rest_do_request( $this->preview_request( [
			'source' => 'core-blueprint-preview-unavailable',
		] ) )->get_status() );
	}

	public function test_cross_designer_cross_actor_and_expired_scopes_fail_closed(): void {
		$mail_scope = DesignerScope::issue( 'core-blueprint-preview-fixture', 'mail', 'fixture-mail' );
		self::assertIsString( $mail_scope );
		set_current_screen( 'core-blueprint-test-reports' );
		$reports_scope = DesignerScope::issue(
			'core-blueprint-preview-scope-reports', 'reports', 'fixture-reports'
		);
		set_current_screen( 'core-blueprint-test-mail' );
		self::assertIsString( $reports_scope );
		self::assertSame( 403, rest_do_request( $this->preview_request( [], true, $reports_scope ) )->get_status() );
		self::assertNull( TemplateSourceRegistry::preview(
			'core-blueprint-preview-fixture', 'example', '1',
			[ 'extension' => 'core-blueprint', 'designer' => 'reports' ]
		) );
		[ $encoded ] = explode( '.', $mail_scope, 2 );
		$data = json_decode( base64_decode( strtr( $encoded, '-_', '+/' ), true ), true );
		self::assertIsArray( $data );
		$data['expires'] = time() - 1;
		$expired_payload = rtrim( strtr( base64_encode( wp_json_encode( $data ) ), '+/', '-_' ), '=' );
		$expired_scope = $expired_payload . '.' . hash_hmac( 'sha256', $expired_payload, wp_salt( 'auth' ) );
		self::assertSame( 403, rest_do_request( $this->preview_request( [], true, $expired_scope ) )->get_status() );
		$other_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other = get_userdata( $other_id );
		self::assertInstanceOf( WP_User::class, $other );
		self::assertTrue( PrivilegedAccessRegistry::approve( $other, 0, 'template_preview_fixture' ) );
		$this->set_session( $other_id );
		self::assertSame( 403, rest_do_request( $this->preview_request( [], true, $mail_scope ) )->get_status() );
		$this->set_session( $this->actor_id );
	}

	public function test_active_markup_remote_media_and_large_fragments_are_refused(): void {
		foreach ( [
			'<script>alert(1)</script>', '<img src="https://example.com/a.png">',
			'<p onclick="alert(1)">X</p>', '<a href="/wp-admin/">X</a>',
			'<div style="background-image:url(https://example.com/x)">X</div>',
			'<div style="background-image:u&#114;l(https://example.com/x)">X</div>',
			'<div style="background-image:u\\72l(https://example.com/x)">X</div>',
			'<div style="@import https://example.com/x">X</div>',
			str_repeat( 'x', TemplatePreview::MAX_HTML_BYTES + 1 ),
		] as $html ) {
			$this->fixture->markup = $html;
			self::assertSame( 403, rest_do_request( $this->preview_request() )->get_status() );
		}
	}

	public function test_session_revoked_during_provider_render_never_returns_preview_html(): void {
		$scope = DesignerScope::issue( 'core-blueprint-preview-fixture', 'mail', 'fixture-mail' );
		self::assertIsString( $scope );
		$this->fixture->revoke_session_during_render = true;
		$result = rest_do_request( $this->preview_request( [], true, $scope ) );
		self::assertSame( 403, $result->get_status() );
		self::assertSame( 1, $this->fixture->renders );
		self::assertArrayNotHasKey( 'html', $result->get_data() );
	}

	public function test_scope_mismatch_revoked_session_and_invalid_request_are_refused(): void {
		$scope = DesignerScope::issue( 'core-blueprint-preview-fixture', 'mail', 'fixture-mail' );
		self::assertIsString( $scope );
		self::assertSame( 200, rest_do_request( $this->preview_request( [ 'contextEpoch' => 17 ], true, $scope ) )->get_status() );
		self::assertSame( 403, rest_do_request( $this->preview_request( [], true, $scope . 'x' ) )->get_status() );
		self::assertSame( 400, rest_do_request( $this->preview_request( [ 'contextEpoch' => '17' ] ) )->get_status() );
		self::assertSame( 400, rest_do_request( $this->preview_request( [ 'html' => '<p>injected</p>' ] ) )->get_status() );
		WP_Session_Tokens::get_instance( $this->actor_id )->destroy( wp_get_session_token() );
		self::assertSame( 403, rest_do_request( $this->preview_request( [], true, $scope ) )->get_status() );
	}
}
