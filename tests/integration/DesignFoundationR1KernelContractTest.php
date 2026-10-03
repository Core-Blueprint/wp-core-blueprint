<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Bootstrap;
use CoreBlueprint\Core\Design\Kernel\AuthorizationRegistry;
use CoreBlueprint\Core\Design\Kernel\CapabilityClass;
use CoreBlueprint\Core\Design\Kernel\CapabilityRegistry;
use CoreBlueprint\Core\Design\Kernel\DesignTypeRegistry;
use CoreBlueprint\Core\Design\Kernel\ProviderRegistry;
use CoreBlueprint\Core\Design\Kernel\SchemaValidator;
use CoreBlueprint\Core\Design\Kernel\Serializer;
use CoreBlueprint\Core\Design\Kernel\ValidationException;

final class CB_Design_Foundation_R1_Kernel_Contract_Test extends WP_UnitTestCase {
	/** @return array{0:ProviderRegistry,1:DesignTypeRegistry,2:CapabilityRegistry,3:AuthorizationRegistry,4:Serializer} */
	private function kernel(): array {
		$providers = new ProviderRegistry();
		self::assertTrue( $providers->register( 'coreblueprint.maintenance' ) );
		self::assertTrue( $providers->register( 'acme.chart' ) );
		self::assertTrue( $providers->register_alias( 'legacy.chart', 'acme.chart' ) );

		$design_types = new DesignTypeRegistry( $providers );
		self::assertTrue( $design_types->register( 'coreblueprint.maintenance.report', 'coreblueprint.maintenance', true ) );

		$capabilities = new CapabilityRegistry( $providers );
		self::assertTrue( $capabilities->register( 'acme.chart', 'chart.render', CapabilityClass::Presentation ) );
		self::assertTrue( $capabilities->register( 'coreblueprint.maintenance', 'report.data', CapabilityClass::Data ) );
		self::assertTrue( $capabilities->register( 'coreblueprint.maintenance', 'report.required_content', CapabilityClass::Requirement ) );

		$authorization = new AuthorizationRegistry( $providers, $design_types, $capabilities );
		$serializer = new Serializer( new SchemaValidator( $providers, $design_types ) );

		return [ $providers, $design_types, $capabilities, $authorization, $serializer ];
	}

	public function test_registration_does_not_imply_authorization(): void {
		[ , , , $authorization ] = $this->kernel();
		self::assertFalse( $authorization->is_authorized( 'coreblueprint.maintenance.report', 'acme.chart', 'chart.render' ) );
		self::assertFalse( $authorization->is_authorized( 'coreblueprint.maintenance.report', 'coreblueprint.maintenance', 'report.data' ) );
	}

	public function test_site_authorization_is_limited_to_presentational_capabilities(): void {
		[ , , , $authorization ] = $this->kernel();
		self::assertTrue( $authorization->grant_site( 'coreblueprint.maintenance.report', 'acme.chart', 'chart.render' ) );
		self::assertFalse( $authorization->grant_site( 'coreblueprint.maintenance.report', 'coreblueprint.maintenance', 'report.data' ) );
		self::assertFalse( $authorization->grant_site( 'coreblueprint.maintenance.report', 'coreblueprint.maintenance', 'report.required_content' ) );
		self::assertTrue( $authorization->is_authorized( 'coreblueprint.maintenance.report', 'legacy.chart', 'chart.render' ) );
	}

	public function test_consumer_authorization_can_grant_data_and_requirement_capabilities(): void {
		[ , , , $authorization ] = $this->kernel();
		self::assertTrue( $authorization->grant_consumer( 'coreblueprint.maintenance.report', 'coreblueprint.maintenance', 'report.data' ) );
		self::assertTrue( $authorization->grant_consumer( 'coreblueprint.maintenance.report', 'coreblueprint.maintenance', 'report.required_content' ) );
		self::assertTrue( $authorization->is_authorized( 'coreblueprint.maintenance.report', 'coreblueprint.maintenance', 'report.data' ) );
		self::assertTrue( $authorization->is_authorized( 'coreblueprint.maintenance.report', 'coreblueprint.maintenance', 'report.required_content' ) );
	}

	public function test_schema_zero_requires_explicit_experimental_decode(): void {
		[ , , , , $serializer ] = $this->kernel();
		$json = '{"schema_version":0,"design_type":"coreblueprint.maintenance.report","root":{}}';

		try {
			$serializer->decode( $json );
			self::fail( 'Stable decode must reject experimental schema version 0.' );
		} catch ( ValidationException $exception ) {
			$codes = array_column( $exception->diagnostics()->to_array(), 'code' );
			self::assertContains( 'schema.experimental_not_stable', $codes );
		}

		$project = $serializer->decode( $json, true );
		self::assertSame( 0, $project->schema_version() );
		self::assertSame( 'coreblueprint.maintenance.report', $project->design_type() );
		self::assertSame( [], $project->root() );
	}

	public function test_unknown_stable_schema_generation_fails_closed(): void {
		[ , , , , $serializer ] = $this->kernel();
		$json = '{"schema_version":1,"design_type":"coreblueprint.maintenance.report","root":{}}';
		$this->expectException( ValidationException::class );
		$serializer->decode( $json, true );
	}

	public function test_provider_alias_resolution_never_rewrites_stored_design_identity(): void {
		[ , , , , $serializer ] = $this->kernel();
		$json = '{"schema_version":0,"design_type":"coreblueprint.maintenance.report","root":{"type":"container","provider":"legacy.chart","properties":{},"children":[]}}';
		$project = $serializer->decode( $json, true );

		self::assertSame( 'legacy.chart', $project->root()['provider'] );
		self::assertStringContainsString( '"provider":"legacy.chart"', $serializer->encode( $project ) );
	}

	public function test_unknown_provider_and_editor_state_fail_closed(): void {
		[ , , , , $serializer ] = $this->kernel();
		$unknown_provider = '{"schema_version":0,"design_type":"coreblueprint.maintenance.report","root":{"type":"container","provider":"unknown.vendor","properties":{},"children":[]}}';
		try {
			$serializer->decode( $unknown_provider, true );
			self::fail( 'Unknown provider must fail closed.' );
		} catch ( ValidationException $exception ) {
			self::assertContains( 'node.unknown_provider', array_column( $exception->diagnostics()->to_array(), 'code' ) );
		}

		$editor_state = '{"schema_version":0,"design_type":"coreblueprint.maintenance.report","root":{},"editor_state":{"selection":[]}}';
		try {
			$serializer->decode( $editor_state, true );
			self::fail( 'Editor state must not enter DesignProject persistence.' );
		} catch ( ValidationException $exception ) {
			self::assertContains( 'schema.unknown_key', array_column( $exception->diagnostics()->to_array(), 'code' ) );
		}
	}

	public function test_document_bootstrap_is_available_without_registering_speculative_contracts(): void {
		Bootstrap::boot();
		self::assertTrue( class_exists( \CoreBlueprint\Core\Design\Profile\Document\Bootstrap::class ) );
		self::assertFalse( class_exists( \CoreBlueprint\Core\Design\Profile\ProfileRegistry::class ) );
		self::assertFalse( interface_exists( \CoreBlueprint\Core\Design\Profile\ProfileInterface::class ) );
	}
}
