<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Signals;

use Bitflw\NexCaptcha\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Combines signals into a 0-100 risk score, used both to scale PoW
 * difficulty and to decide mode escalation (invisible -> slider).
 *
 * The only signal genuinely available before a challenge is even
 * issued is proximity to the rate limit; richer historical signals
 * need Logging\EventLogger, which is currently write-only (no
 * read-back query exists yet) -- a later enhancement, not required
 * for this signal to be useful today.
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

	/**
	 * Shares ESCALATION_THRESHOLD with adjust_maxnumber() rather than a
	 * separate configurable setting -- docs/PLAN.md section 2.4 only
	 * calls for an escalation on/off switch, not a tunable threshold.
	 */
	public static function mode(): string {
		if ( ! Settings::get( 'escalation', true ) ) {
			return 'invisible';
		}

		return self::score() >= self::ESCALATION_THRESHOLD ? 'slider' : 'invisible';
	}
}
