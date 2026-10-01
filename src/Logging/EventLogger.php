<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Logging;

use Bitflw\NexCaptcha\Admin\Settings;
use Bitflw\NexCaptcha\Support\Ip;

defined( 'ABSPATH' ) || exit;

/**
 * Writes verification events to the events table, hooked onto the
 * nxc_verified/nxc_failed/nxc_rate_limited actions that already fire
 * without this class existing. Section 2.5's fourth result value,
 * "blocked", has no concrete trigger anywhere yet -- not invented here.
 */
class EventLogger {

	public static function register(): void {
		add_action( 'nxc_verified', array( self::class, 'log_verified' ), 10, 3 );
		add_action( 'nxc_failed', array( self::class, 'log_failed' ), 10, 3 );
		add_action( 'nxc_rate_limited', array( self::class, 'log_rate_limited' ), 10, 1 );
		add_action( 'nxc_prune_events', array( self::class, 'prune' ) );
	}

	public static function log_verified( string $mode, string $form_id, ?int $solve_ms = null ): void {
		self::insert( $form_id, $mode, 'passed', null, $solve_ms );
	}

	public static function log_failed( string $reason, string $mode, string $form_id ): void {
		self::insert( $form_id, $mode, 'failed', $reason );
	}

	public static function log_rate_limited( string $form_id ): void {
		self::insert( $form_id, 'invisible', 'rate_limited' );
	}

	public static function prune(): void {
		global $wpdb;

		$days   = (int) ( Settings::get( 'logging', array() )['retention_days'] ?? 30 );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table_name() . ' WHERE created_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name isn't user input, can't be parameterized.
	}

	private static function insert( string $form, string $mode, string $result, ?string $reason = null, ?int $solve_ms = null ): void {
		if ( empty( Settings::get( 'logging', array() )['enabled'] ) ) {
			return;
		}

		global $wpdb;

		$wpdb->insert(
			Schema::table_name(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'form'       => $form,
				'mode'       => $mode,
				'result'     => $result,
				'reason'     => $reason,
				'ip_hash'    => Ip::hash(),
				'solve_ms'   => $solve_ms,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}
}
