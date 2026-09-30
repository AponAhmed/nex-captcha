<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Signals;

use Bitflw\NexCaptcha\Admin\Settings;
use Bitflw\NexCaptcha\Support\Ip;

defined( 'ABSPATH' ) || exit;

/**
 * Per-bucket attempt limiting. Backed by the Transients API, which
 * already defers to a persistent object cache when one is available
 * rather than the options table.
 */
class RateLimiter {

	private const PREFIX = 'nxc_rl_';

	public static function is_limited( string $bucket ): bool {
		$max = (int) ( Settings::get( 'rate_limit', array() )['max'] ?? 10 );

		return self::attempts( $bucket ) >= $max;
	}

	public static function hit( string $bucket ): void {
		$window = (int) ( Settings::get( 'rate_limit', array() )['window'] ?? 600 );
		$key    = self::key( $bucket );
		$count  = self::attempts( $bucket );

		set_transient( $key, $count + 1, $window );
	}

	/**
	 * Read-only: how many attempts this bucket has used, without
	 * consuming one. Used by RiskScorer to gauge proximity to the limit.
	 */
	public static function attempts( string $bucket ): int {
		return (int) get_transient( self::key( $bucket ) );
	}

	private static function key( string $bucket ): string {
		return self::PREFIX . $bucket;
	}

	/**
	 * The bucket ChallengeController limits challenge issuance by, and
	 * RiskScorer reads to gauge risk -- centralized here so both agree
	 * on the same name.
	 */
	public static function challenge_bucket(): string {
		return 'challenge_' . Ip::hash();
	}
}
