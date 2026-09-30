<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Signals;

use Bitflw\NexCaptcha\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Combines signals into a 0-100 risk score, currently used to scale PoW
 * difficulty. Mode escalation (switching to the slider) needs
 * Challenge\SliderChallenge, which doesn't exist yet -- that half of
 * this class's documented job lands in a later phase.
 *
 * The only signal genuinely available before a challenge is even
 * issued is proximity to the rate limit; richer historical signals
 * need Logging\EventLogger, also not built yet.
 */
class RiskScorer {

	private const ESCALATION_THRESHOLD = 50;
	private const MAX_MULTIPLIER       = 3.0;
	private const CEILING              = 250000; // docs/PLAN.md section 3.4's own High-difficulty ceiling.

	public static function score(): int {
		$max = (int) ( Settings::get( 'rate_limit', array() )['max'] ?? 10 );

		if ( $max <= 0 ) {
			return 0;
		}

		$attempts = RateLimiter::attempts( RateLimiter::challenge_bucket() );

		return (int) min( 100, round( ( $attempts / $max ) * 100 ) );
	}

	public static function adjust_maxnumber( int $maxnumber, string $form_id ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches nxc_pow_maxnumber's documented filter signature (docs/PLAN.md section 4.6); not yet used for per-form scoring.
		$risk = self::score();

		if ( $risk < self::ESCALATION_THRESHOLD ) {
			return $maxnumber;
		}

		$multiplier = 1 + ( $risk / 100 ) * ( self::MAX_MULTIPLIER - 1 );

		return (int) min( self::CEILING, round( $maxnumber * $multiplier ) );
	}
}
