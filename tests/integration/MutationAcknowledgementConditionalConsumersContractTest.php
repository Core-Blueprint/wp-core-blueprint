<?php
declare(strict_types=1);

use CoreBlueprint\Core\Governance\EventRegistry;

final class MutationAcknowledgementConditionalConsumersContractTest extends WP_UnitTestCase {

	private function source( string $path ): string {
		$source = file_get_contents( CB_CORE_DIR . $path );
		self::assertIsString( $source, $path );
		return $source;
	}

	private function section( string $source, string $start, string $end ): string {
		$start_pos = strpos( $source, $start );
		$end_pos = strpos( $source, $end, false === $start_pos ? 0 : $start_pos + strlen( $start ) );
		self::assertNotFalse( $start_pos, $start );
		self::assertNotFalse( $end_pos, $end );
		return substr( $source, (int) $start_pos, (int) $end_pos - (int) $start_pos );
	}

	public function test_notes_acknowledgement_is_required_for_actual_existing_overwrites_before_any_import_mutation(): void {
		$page = $this->source( 'src/Notes/Admin/Page.php' );
		$controller = $this->source( 'src/Notes/Rest/NotesController.php' );
		$repository = $this->source( 'src/Notes/Repository.php' );
		$script = $this->source( 'assets/js/features/notes.js' );

		self::assertStringContainsString( 'cb-notes-import-overwrite-acknowledgement-template', $page );
		self::assertStringContainsString( 'Repository::import_preview( $notes )', $controller );
		self::assertStringContainsString( "(int) ( \$row['existing_id'] ?? 0 ) < 1", $controller );
		self::assertStringContainsString( 'MutationAcknowledgement::require_confirmed(', $controller );
		self::assertStringContainsString( "Audit::log( 'notes.import.overwrite.acknowledged'", $controller );
		self::assertStringContainsString( 'Repository::import_commit( $notes, $decisions, $overwrite_acknowledged )', $controller );

		self::assertStringContainsString( 'bool $overwrite_acknowledged = false', $repository );
		self::assertStringContainsString( "if ( 'overwrite' === \$decision && \$existing && ! \$overwrite_acknowledged )", $repository );
		self::assertStringContainsString( '$prepared[] = [', $repository );
		self::assertStringContainsString( 'foreach ( $prepared as $item )', $repository );

		$guard = strpos( $repository, "if ( 'overwrite' === \$decision && \$existing && ! \$overwrite_acknowledged )" );
		$mutation_loop = strpos( $repository, 'foreach ( $prepared as $item )' );
		self::assertNotFalse( $guard );
		self::assertNotFalse( $mutation_loop );
		self::assertLessThan( $mutation_loop, $guard );

		self::assertStringContainsString( "Object.values(decisions).includes('overwrite')", $script );
		self::assertStringContainsString( "notes_import_overwrite_acknowledgement: hasOverwrite ? '1' : ''", $script );
	}

	public function test_scanner_acknowledgement_is_restore_only_and_precedes_filesystem_restore(): void {
		$view = $this->source( 'src/Integrity/Admin/ScannerQuarantineView.php' );
		$controller = $this->source( 'src/Integrity/Rest/ScanController.php' );
		$script = $this->source( 'assets/js/features/core-scanner.js' );

		self::assertStringContainsString( 'cb-integrity-quarantine-restore-acknowledgement-template', $view );
		self::assertStringContainsString( 'MutationAcknowledgement::require_confirmed(', $controller );
		self::assertStringContainsString( "'integrity.quarantine.restore.acknowledged'", $controller );
		self::assertStringContainsString( "quarantine_restore_acknowledgement: '1'", $script );

		$restore = $this->section( $controller, 'public function restore_quarantine', 'public function delete_quarantine' );
		$delete = $this->section( $controller, 'public function delete_quarantine', 'public function add_quarantine_note' );
		self::assertStringContainsString( 'MutationAcknowledgement::require_confirmed(', $restore );
		self::assertStringNotContainsString( 'MutationAcknowledgement', $delete );

		$audit = strpos( $restore, "'integrity.quarantine.restore.acknowledged'" );
		$mutation = strpos( $restore, 'QuarantineService::restore( $id )' );
		self::assertNotFalse( $audit );
		self::assertNotFalse( $mutation );
		self::assertLessThan( $mutation, $audit );
	}

	public function test_conditional_acknowledgement_events_are_registered_and_plain_described(): void {
		$events = [
			'notes.import.overwrite.acknowledged',
			'integrity.quarantine.restore.acknowledged',
		];

		foreach ( $events as $event ) {
			self::assertTrue( EventRegistry::is_valid_id( $event ), $event );
		}

		$notes = $this->source( 'src/Notes/Bootstrap.php' );
		self::assertStringContainsString( "'notes.import.overwrite.acknowledged'", $notes );

		$integrity = $this->source( 'src/Integrity/Bootstrap.php' );
		self::assertStringContainsString( "'integrity.quarantine.restore.acknowledged'", $integrity );

		$language = $this->source( 'src/Log/Language.php' );
		foreach ( $events as $event ) {
			self::assertStringContainsString( "'{$event}'", $language );
		}
	}
}
