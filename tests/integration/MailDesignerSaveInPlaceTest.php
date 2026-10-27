<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Document\Schema;
use CoreBlueprint\Core\Design\Persistence\OperationLedger;
use CoreBlueprint\Core\Design\Persistence\OperationSchema;
use CoreBlueprint\Core\Mail\Settings as MailSettings;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Mail\Designer\Renderer as MailRenderer;
use CoreBlueprint\Core\Design\Editor\PlatformTransport;
use CoreBlueprint\Core\Design\Profile\Mail\Contract;
use CoreBlueprint\Core\Mail\Admin\TemplateActions;
use CoreBlueprint\Core\Mail\Designer\TemplateRepository;
use CoreBlueprint\Core\Mail\Designer\WordPressTemplates;

final class CB_Mail_Designer_Save_In_Place_Test extends \PHPUnit\Framework\TestCase {
	public function test_historical_admin_post_and_ajax_save_have_no_write_authority(): void {
		TemplateActions::boot();
		self::assertFalse( has_action( 'admin_post_cb_core_mail_template_save', [ TemplateActions::class, 'save' ] ) );
		self::assertFalse( has_action( 'wp_ajax_cb_core_mail_template_save', [ TemplateActions::class, 'save_ajax' ] ) );
		self::assertFalse( has_action( 'admin_post_cb_core_mail_template_reset', [ TemplateActions::class, 'reset' ] ) );
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Mail/Admin/TemplateActions.php' );
		self::assertStringContainsString( "'response' => 410", $source );
	}

	public function test_obsolete_mail_repository_mutations_refuse_and_leave_document_unchanged(): void {
		$template = WordPressTemplates::default_document( 'wordpress.password-reset' );
		self::assertIsArray( $template );
		$before = Repository::get( Repository::MAIL, 'wordpress.password-reset' );
		self::assertSame( 'blocked', TemplateRepository::mutate(
			'wordpress.password-reset', '0', 'Injected subject', $template['project']
		)['status'] );
		self::assertSame( 'blocked', TemplateRepository::reset_revisioned( 'wordpress.password-reset', '0' )['status'] );
		self::assertFalse( TemplateRepository::save(
			'wordpress.password-reset', 'Injected subject', $template['project']
		) );
		self::assertFalse( TemplateRepository::reset( 'wordpress.password-reset' ) );
		self::assertSame( [], TemplateRepository::overrides() );
		self::assertSame( $before, Repository::get( Repository::MAIL, 'wordpress.password-reset' ) );
	}

	public function test_native_platform_validates_revisioned_project_and_fields_without_legacy_conversion(): void {
		$valid = WordPressTemplates::default_document( 'wordpress.password-reset' );
		self::assertIsArray( $valid );
		self::assertSame( Contract::DESIGN_TYPE, $valid['project']['design_type'] );
		self::assertTrue( PlatformTransport::valid_native_document( Repository::MAIL, $valid ) );
		$invalid = $valid;
		$invalid['fields']['extra'] = 'legacy';
		self::assertFalse( PlatformTransport::valid_native_document( Repository::MAIL, $invalid ) );
		$invalid = $valid;
		$invalid['project']['schema_version'] = 0;
		self::assertFalse( PlatformTransport::valid_native_document( Repository::MAIL, $invalid ) );
		self::assertTrue( Repository::valid_revision( '0' ) );
		self::assertTrue( Repository::valid_revision( '1' ) );
		self::assertFalse( Repository::valid_revision( '-1' ) );
		self::assertFalse( Repository::valid_revision( '01' ) );
	}

	public function test_native_transport_enforces_rest_nonce_and_single_transactional_save_authority(): void {
		$request = new WP_REST_Request( 'POST', '/core-blueprint/v1/designer-platform/save' );
		self::assertFalse( PlatformTransport::permission( $request ) );
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Design/Editor/PlatformTransport.php' );
		self::assertStringContainsString( 'new TransactionalSave(', $source );
		self::assertStringContainsString( 'expectedStoreRevision', $source );
		self::assertStringContainsString( 'registryFingerprint', $source );
		self::assertStringContainsString( "'status' => 'unknown'", $source );
		self::assertStringNotContainsString( 'TemplateRepository::save(', $source );
	}


	/**
	 * Real native REST Save/Reopen/Render/CAS acceptance. Unlike the read-only
	 * contracts above, the positive path MUST own an independent DB transaction.
	 * WordPress PHPUnit cases may leave the parent process DB connection inside
	 * an ambient test transaction; sharing it correctly makes TransactionalSave
	 * refuse the Save. Isolate only this positive test in a fresh PHP process,
	 * bootstrap WordPress afresh, and never inherit parent PHPUnit globals.
	 * Keep the explicit @@in_transaction == 0 guard below: no implicit COMMIT,
	 * ROLLBACK, reconnect or bypass of the production fail-closed behavior.
	 * Execute only inside a separately authorized dedicated integration database.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_native_platform_save_reopen_render_and_stale_cas_in_real_wordpress(): void {
		global $wpdb;
		self::assertSame( '0', (string) $wpdb->get_var( 'SELECT @@in_transaction' ),
			'Positive Platform Save must own its transaction; no nested PHPUnit transaction.' );
		self::assertTrue( Schema::healthy() );
		self::assertTrue( OperationSchema::healthy() );

		$key = 'wordpress.new-user-admin';
		self::assertSame( 'missing', Repository::get( Repository::MAIL, $key )['status'],
			'A pre-existing Mail document must never be overwritten by this fixture.' );
		$old_settings = get_option( MailSettings::OPTION, null );
		$old_user = get_current_user_id();
		$actor_id = 0;
		$operations = [];
		try {
			$unique = bin2hex( random_bytes( 6 ) );
			$created = wp_insert_user( [
				'user_login' => 'cb_l2r1_' . $unique,
				'user_pass' => wp_generate_password( 32, true, true ),
				'user_email' => 'cb_l2r1_' . $unique . '@example.test',
				'role' => 'administrator',
			] );
			self::assertFalse( is_wp_error( $created ) );
			$actor_id = (int) $created;
			self::assertGreaterThan( 0, $actor_id );
			wp_set_current_user( $actor_id );
			$actor = get_userdata( $actor_id );
			self::assertInstanceOf( \WP_User::class, $actor );
			// Production denies newly created privileged identities until approved.
			// Model an operator-approved synthetic fixture through the signed registry.
			self::assertFalse( PrivilegedAccessRegistry::is_approved( $actor ) );
			self::assertFalse( current_user_can( 'manage_options' ) );
			self::assertTrue( PrivilegedAccessRegistry::approve( $actor, 0, 'test_fixture' ) );
			self::assertTrue( PrivilegedAccessRegistry::is_approved( $actor ) );
			self::assertTrue( current_user_can( 'manage_options' ) );

			$settings = MailSettings::all();
			$settings['designer_enabled'] = true;
			MailSettings::save( $settings );
			self::assertTrue( MailSettings::designer_enabled() );

			$read_context = static function () use ( $key ): array {
				$request = new WP_REST_Request( 'GET', '/core-blueprint/v1/designer-platform/context' );
				$request->set_param( 'action', 'context' );
				$request->set_param( 'profileId', 'base/mail' );
				$request->set_param( 'resourceKey', $key );
				$request->set_param( 'contextEpoch', 0 );
				$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
				self::assertTrue( PlatformTransport::permission( $request ) );
				$response = PlatformTransport::dispatch( $request );
				self::assertInstanceOf( WP_REST_Response::class, $response );
				$data = $response->get_data();
				self::assertSame( 'loaded', $data['status'] ?? null );
				return $data;
			};
			$submit = static function ( array $context, array $project, array $fields,
				string $revision, string $operation_id ): array {
				$request = new WP_REST_Request( 'POST', '/core-blueprint/v1/designer-platform/save' );
				$request->set_param( 'action', 'save' );
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
				$request->set_body( (string) wp_json_encode( [
					'contractVersion' => 1,
					'profileId' => $context['profileId'],
					'resourceType' => $context['resourceType'],
					'resourceKey' => $context['resourceKey'],
					'projectSchema' => $context['projectSchema'],
					'registryFingerprint' => $context['registryFingerprint'],
					'operationId' => $operation_id,
					'expectedStoreRevision' => $revision,
					'project' => $project,
					'fields' => $fields,
				] ) );
				self::assertTrue( PlatformTransport::permission( $request ) );
				$response = PlatformTransport::dispatch( $request );
				self::assertInstanceOf( WP_REST_Response::class, $response );
				return $response->get_data();
			};

			$context = $read_context();
			self::assertSame( '0', $context['storeRevision'] );
			$project = $context['project'];
			$fields = $context['fields'];
			$project['root']['children'][0]['children'][1]['properties']['text'] = 'L2-R1 native render proof';
			$fields['subject'] = 'L2-R1 native subject';
			$fields['preheader'] = 'L2-R1 native preheader';
			self::assertTrue( PlatformTransport::valid_native_document( Repository::MAIL, [
				'project' => $project, 'fields' => $fields,
			] ) );

			$operations[] = 'L2R1_' . bin2hex( random_bytes( 14 ) );
			$first = $submit( $context, $project, $fields, '0', $operations[0] );
			self::assertSame( 'committed', $first['status'] ?? null );
			self::assertSame( '1', $first['receipt']['acceptedStoreRevision'] ?? null );

			$reopened = $read_context();
			self::assertSame( '1', $reopened['storeRevision'] );
			self::assertEquals( $project, $reopened['project'] );
			self::assertEquals( $fields, $reopened['fields'] );
			$stored = Repository::get( Repository::MAIL, $key );
			self::assertSame( 'found', $stored['status'] );
			self::assertSame( '1', $stored['revision'] );
			self::assertEquals( [ 'project' => $project, 'fields' => $fields ], $stored['document'] );
			$template = TemplateRepository::get( $key );
			self::assertSame( $fields['subject'], $template['subject'] );
			self::assertSame( $fields['preheader'], $template['preheader'] );
			$rendered = MailRenderer::render( $key, [] );
			self::assertIsArray( $rendered );
			self::assertSame( $fields['subject'], $rendered['subject'] );
			self::assertSame( $fields['preheader'], $rendered['preheader'] );
			self::assertStringContainsString( 'L2-R1 native render proof', $rendered['html'] );

			$stale_fields = $fields;
			$stale_fields['subject'] = 'STALE WRITE MUST NOT LAND';
			$operations[] = 'L2R1_' . bin2hex( random_bytes( 14 ) );
			$stale = $submit( $context, $project, $stale_fields, '0', $operations[1] );
			self::assertSame( 'conflict', $stale['status'] ?? null );
			$after = Repository::get( Repository::MAIL, $key );
			self::assertSame( $stored, $after );
			self::assertSame( '1', $read_context()['storeRevision'] );
			self::assertStringNotContainsString( 'STALE WRITE MUST NOT LAND',
				(string) ( MailRenderer::render( $key, [] )['subject'] ?? '' ) );

			$fields['subject'] = 'L2-R1 revision two';
			$operations[] = 'L2R1_' . bin2hex( random_bytes( 14 ) );
			$second = $submit( $reopened, $project, $fields, '1', $operations[2] );
			self::assertSame( 'committed', $second['status'] ?? null );
			self::assertSame( '2', $second['receipt']['acceptedStoreRevision'] ?? null );
			self::assertSame( '2', $read_context()['storeRevision'] );
			self::assertSame( 'L2-R1 revision two', TemplateRepository::get( $key )['subject'] );
		} finally {
			// Test-owned synthetic data only. Never touch any pre-existing row,
			// site installation, database or the separate H1 evidence workspace.
			foreach ( $operations as $operation ) {
				$wpdb->delete( OperationSchema::table_name(), [
					'blog_id' => (int) get_current_blog_id(),
					'actor_id' => $actor_id,
					'operation_id_hash' => OperationLedger::operation_hash( $operation ),
				], [ '%d', '%d', '%s' ] );
			}
			if ( $actor_id > 0 ) {
				$wpdb->delete( Schema::table_name(), [
					'identity_hash' => hash( 'sha256', Repository::MAIL . "\0" . $key ),
				], [ '%s' ] );
			}
			if ( null === $old_settings ) {
				delete_option( MailSettings::OPTION );
			} else {
				update_option( MailSettings::OPTION, $old_settings, false );
			}
			wp_set_current_user( $old_user );
			if ( $actor_id > 0 ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $actor_id );
			}
		}
	}

	public function test_active_editor_does_not_enqueue_the_deprecated_mail_save_adapter(): void {
		$assets = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Mail/Admin/DesignerAssets.php' );
		self::assertStringContainsString( 'DesignEditorAssets::enqueue_platform()', $assets );
		self::assertStringContainsString( 'platform_fullscreen_requested()', $assets );
		self::assertStringNotContainsString( 'wp_enqueue_script_module( self::SAVE_MODULE_ID', $assets );
		$transport = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Design/Editor/PlatformTransport.php' );
		self::assertStringContainsString( "'committed'", $transport );
		self::assertStringContainsString( "'refused'", $transport );
		self::assertStringContainsString( "'unknown'", $transport );
	}
}
