<?php
declare(strict_types=1);
/**
 * Daily retention scheduler.
 *
 * AuditLog retention policy is owned by Governance\RetentionPolicy. Dedicated
 * retention stores register through Governance\RetentionStoreRegistry.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CoreBlueprint\Core\Log;

use CoreBlueprint\Core\Governance\RetentionPolicy;
use CoreBlueprint\Core\Governance\RetentionStoreRegistry;
use CoreBlueprint\Core\Support\ThrowableBoundary;

defined( 'ABSPATH' ) || exit;

final class Retention {
	public const CRON_HOOK = 'cb_core_daily_prune';

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function run(): void {
		$audit = self::prune_audit();
		$stores = self::run_retention_stores();
		$total = $audit['total'] + $stores['total'];
		if ( $total > 0 ) {
			AuditLog::log( 'audit.pruned', 'info', [
				'total'        => $total,
				'per_category' => $audit['breakdown'],
				'stores'       => $stores['breakdown'],
			] );
		}
	}

	/**
	 * Apply the configured AuditLog policy, optionally to one category only.
	 *
	 * @return array{total:int,breakdown:array<string,int>}
	 */
	public static function prune_audit( ?string $only_category = null ): array {
		if ( null !== $only_category && ! RetentionPolicy::is_category( $only_category ) ) {
			return [ 'total' => 0, 'breakdown' => [] ];
		}

		$policy = RetentionPolicy::all();
		$categories = null === $only_category ? RetentionPolicy::CATEGORIES : [ $only_category ];
		$breakdown = [];
		$total = 0;

		foreach ( $categories as $category ) {
			$days = (int) ( $policy[ $category ] ?? 0 );
			if ( $days < 1 ) {
				continue;
			}
			try {
				$deleted = AuditLog::prune_by_category( $category, $days );
			} catch ( \Throwable $e ) {
				AuditLog::log( 'audit.prune_failed', 'warning', [
					'category' => $category,
					'days'     => $days,
					...ThrowableBoundary::context( $e, 'audit_prune_failed' ),
				] );
				continue;
			}
			$breakdown[ $category ] = $deleted;
			$total += $deleted;
		}

		return [ 'total' => $total, 'breakdown' => $breakdown ];
	}

	/** @return array{total:int,breakdown:array<string,int>} */
	private static function run_retention_stores(): array {
		$breakdown = [];
		$total = 0;
		foreach ( RetentionStoreRegistry::all() as $id => $store ) {
			$days = (int) $store['days'];
			if ( $days < 1 ) {
				continue;
			}
			try {
				$deleted = (int) call_user_func( $store['prune'], $days );
			} catch ( \Throwable $e ) {
				AuditLog::log( 'audit.prune_failed', 'warning', [
					'store'   => $id,
					'days'    => $days,
					...ThrowableBoundary::context( $e, 'retention_store_prune_failed' ),
				] );
				continue;
			}
			$breakdown[ $id ] = $deleted;
			$total += max( 0, $deleted );
		}
		return [ 'total' => $total, 'breakdown' => $breakdown ];
	}

	public static function next_run(): ?int {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		return $ts ? (int) $ts : null;
	}
}
