<?php
declare(strict_types=1);
/**
 * Chart - shared SVG line-chart renderer for activity timelines.
 *
 * Used by all four audit views (Audit Log, System Log, Connection Log,
 * Maintenance Report) to render a consistent daily-activity chart.
 * Emits SVG that reuses the .cb-core-mr-chart-* classes so styling lives
 * in one place in pages/reports.css.
 *
 * Callers pass an array of { date: 'Y-m-d', count: int } rows, oldest
 * first, and receive ready-to-echo SVG markup.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Log;

defined( 'ABSPATH' ) || exit;

class Chart {

	/**
	 * Render a line chart for the given daily counts.
	 *
	 * @param array  $daily An ordered array of ['date' => 'Y-m-d', 'count' => int].
	 * @param string $title The chart heading text, already translated.
	 * @return string SVG + wrapper HTML ready to echo.
	 */
	public static function render_activity( array $daily, string $title = '' ): string {
		if ( '' === $title ) {
			$title = __( 'Activity', 'core-blueprint' );
		}

		$max   = max( array_column( $daily, 'count' ) ?: [ 0 ] );
		$y_max = max( 1, $max );

		// ViewBox dimensions - scales to parent width via CSS.
		$chart_w = 1000;
		$chart_h = 220;
		$pad_l   = 48;
		$pad_r   = 24;
		$pad_t   = 20;
		$pad_b   = 36;
		$plot_w  = $chart_w - $pad_l - $pad_r;
		$plot_h  = $chart_h - $pad_t - $pad_b;

		$n      = count( $daily );
		$points = [];
		foreach ( $daily as $i => $d ) {
			$x = $pad_l + ( $n > 1 ? ( $i / ( $n - 1 ) ) * $plot_w : $plot_w / 2 );
			$y = $pad_t + $plot_h - ( ( (int) $d['count'] / $y_max ) * $plot_h );
			$points[] = [
				'x'     => $x,
				'y'     => $y,
				'count' => (int) $d['count'],
				'date'  => (string) $d['date'],
			];
		}

		$polyline_coords = implode( ' ', array_map(
			static fn( $p ) => round( $p['x'], 1 ) . ',' . round( $p['y'], 1 ),
			$points
		) );

		// Area path - line + closed to baseline.
		$area_path = '';
		if ( ! empty( $points ) ) {
			$first = $points[0];
			$last  = $points[ count( $points ) - 1 ];
			$area_path = 'M ' . round( $first['x'], 1 ) . ' ' . round( $pad_t + $plot_h, 1 );
			foreach ( $points as $p ) {
				$area_path .= ' L ' . round( $p['x'], 1 ) . ' ' . round( $p['y'], 1 );
			}
			$area_path .= ' L ' . round( $last['x'], 1 ) . ' ' . round( $pad_t + $plot_h, 1 );
			$area_path .= ' Z';
		}

		// Y-axis gridlines - 4 steps including zero and max.
		$y_ticks = [];
		for ( $t = 0; $t <= 4; $t++ ) {
			$val = (int) round( ( $t / 4 ) * $y_max );
			$y   = $pad_t + $plot_h - ( ( $val / $y_max ) * $plot_h );
			$y_ticks[] = [ 'y' => $y, 'label' => $val ];
		}

		// X-axis labels - show first / mid / last date.
		$x_labels = [];
		if ( $n > 0 ) {
			$indexes = array_unique( [ 0, (int) floor( ( $n - 1 ) / 2 ), $n - 1 ] );
			foreach ( $indexes as $idx ) {
				$p = $points[ $idx ];
				$ts = strtotime( (string) $daily[ $idx ]['date'] );
				$x_labels[] = [
					'x'     => $p['x'],
					'label' => $ts ? date_i18n( 'j M', $ts ) : (string) $daily[ $idx ]['date'],
				];
			}
		}

		// Unique gradient id per render so multiple charts on one page
		// don't collide. Still stable enough for server-side render.
		static $counter = 0;
		$counter++;
		$grad_id = 'cb-core-mr-chart-fill-' . $counter;

		ob_start();
		?>
		<div class="cb-core-mr-chart-wrap">
			<h3 class="cb-core-mr-chart-title"><?php echo esc_html( $title ); ?></h3>
			<svg class="cb-core-mr-chart" viewBox="0 0 <?php echo (int) $chart_w; ?> <?php echo (int) $chart_h; ?>" preserveAspectRatio="none" role="img" aria-label="<?php echo esc_attr( $title ); ?>">

				<defs>
					<linearGradient id="<?php echo esc_attr( $grad_id ); ?>" x1="0" y1="0" x2="0" y2="1">
						<stop offset="0%"   class="cb-core-mr-chart-stop-top" />
						<stop offset="100%" class="cb-core-mr-chart-stop-bot" />
					</linearGradient>
				</defs>

				<?php foreach ( $y_ticks as $tick ) : ?>
					<line class="cb-core-mr-chart-grid" x1="<?php echo (int) $pad_l; ?>" y1="<?php echo esc_attr( (string) round( $tick['y'], 1 ) ); ?>" x2="<?php echo (int) ( $chart_w - $pad_r ); ?>" y2="<?php echo esc_attr( (string) round( $tick['y'], 1 ) ); ?>" />
					<text class="cb-core-mr-chart-axis" x="<?php echo (int) ( $pad_l - 8 ); ?>" y="<?php echo esc_attr( (string) round( $tick['y'] + 4, 1 ) ); ?>" text-anchor="end"><?php echo esc_html( (string) $tick['label'] ); ?></text>
				<?php endforeach; ?>

				<?php if ( $area_path ) : ?>
					<path class="cb-core-mr-chart-area" fill="url(#<?php echo esc_attr( $grad_id ); ?>)" d="<?php echo esc_attr( $area_path ); ?>" />
				<?php endif; ?>

				<?php if ( count( $points ) > 1 ) : ?>
					<polyline class="cb-core-mr-chart-line" points="<?php echo esc_attr( $polyline_coords ); ?>" fill="none" />
				<?php endif; ?>

				<?php foreach ( $points as $p ) : ?>
					<circle class="cb-core-mr-chart-dot<?php echo $p['count'] > 0 ? ' cb-core-mr-chart-dot--active' : ''; ?>" cx="<?php echo esc_attr( (string) round( $p['x'], 1 ) ); ?>" cy="<?php echo esc_attr( (string) round( $p['y'], 1 ) ); ?>" r="<?php echo $p['count'] > 0 ? '2.25' : '1.5'; ?>">
						<?php
						$ts = strtotime( $p['date'] );
						$dl = $ts ? date_i18n( 'j M Y', $ts ) : $p['date'];
						$lbl = sprintf( '%s · %d %s', $dl, $p['count'], _n( 'event', 'events', $p['count'], 'core-blueprint' ) );
						?>
						<title><?php echo esc_html( $lbl ); ?></title>
					</circle>
				<?php endforeach; ?>

				<?php foreach ( $x_labels as $xl ) : ?>
					<text class="cb-core-mr-chart-axis" x="<?php echo esc_attr( (string) round( $xl['x'], 1 ) ); ?>" y="<?php echo (int) ( $chart_h - 10 ); ?>" text-anchor="middle"><?php echo esc_html( $xl['label'] ); ?></text>
				<?php endforeach; ?>

			</svg>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build a 30-day daily-count skeleton (oldest → newest) with all
	 * counts zeroed. Callers fill in counts from their own data source.
	 *
	 * @return array<int, array{date: string, count: int}>
	 */
	public static function empty_daily_scaffold( int $days = 30 ): array {
		$now   = time();
		$daily = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day_ts = $now - ( $i * DAY_IN_SECONDS );
			$key    = gmdate( 'Y-m-d', $day_ts );
			$daily[ $key ] = [ 'date' => $key, 'count' => 0 ];
		}
		return $daily;
	}

	/**
	 * Given a list of rows with a 'created_at' UTC timestamp field,
	 * compute daily counts over the last N days. Returns oldest-first.
	 *
	 * @param iterable $rows     Each row must have a 'created_at' or ->created_at.
	 * @param int      $days     Window size.
	 * @param string   $date_key Field name (default 'created_at').
	 */
	public static function daily_counts_from_rows( iterable $rows, int $days = 30, string $date_key = 'created_at' ): array {
		$daily = self::empty_daily_scaffold( $days );
		foreach ( $rows as $row ) {
			$ts_str = '';
			if ( is_array( $row ) ) {
				$ts_str = (string) ( $row[ $date_key ] ?? '' );
			} elseif ( is_object( $row ) ) {
				$ts_str = (string) ( $row->{$date_key} ?? '' );
			}
			if ( '' === $ts_str ) {
				continue;
			}
			$ts = strtotime( $ts_str . ' UTC' );
			if ( $ts <= 0 ) {
				continue;
			}
			$key = gmdate( 'Y-m-d', $ts );
			if ( isset( $daily[ $key ] ) ) {
				$daily[ $key ]['count']++;
			}
		}
		return array_values( $daily );
	}
}
