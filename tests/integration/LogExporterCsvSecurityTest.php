<?php
declare(strict_types=1);

use CB\Core\Log\LogExporter;

final class CB_Base_Log_Exporter_Csv_Security_Test extends WP_UnitTestCase {

	public function test_csv_neutralizes_spreadsheet_formula_prefixes_without_changing_json(): void {
		$columns = [ 'value' => 'Value' ];
		$rows = [
			[ 'value' => '=1+1' ],
			[ 'value' => '+SUM(A1:A2)' ],
			[ 'value' => '-1+2' ],
			[ 'value' => '@SUM(A1:A2)' ],
			[ 'value' => " \t=1+1" ],
			[ 'value' => "'literal" ],
			[ 'value' => 'safe' ],
		];

		$stream = fopen( 'php://temp', 'w+b' );
		self::assertIsResource( $stream );
		self::assertSame( count( $rows ), LogExporter::to_csv( $stream, $rows, $columns ) );
		rewind( $stream );

		self::assertSame( [ 'Value' ], fgetcsv( $stream, 0, ',', '"', '\\' ) );
		$csv_values = [];
		while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '\\' ) ) ) {
			$csv_values[] = (string) ( $row[0] ?? '' );
		}
		fclose( $stream );

		self::assertSame(
			[
				"'=1+1",
				"'+SUM(A1:A2)",
				"'-1+2",
				"'@SUM(A1:A2)",
				"' \t=1+1",
				"''literal",
				'safe',
			],
			$csv_values
		);

		$json_stream = fopen( 'php://temp', 'w+b' );
		self::assertIsResource( $json_stream );
		self::assertSame( 1, LogExporter::to_json( $json_stream, [ [ 'value' => '=1+1' ] ], $columns, [] ) );
		rewind( $json_stream );
		$json = stream_get_contents( $json_stream );
		fclose( $json_stream );
		self::assertIsString( $json );
		$decoded = json_decode( $json, true );
		self::assertSame( '=1+1', $decoded['events'][0]['value'] ?? null );
	}
}
