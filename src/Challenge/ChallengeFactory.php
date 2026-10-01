<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Challenge;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the right challenge instance for a requested or risk-adjusted mode.
 *
 * MathChallenge is also reachable here (not just the <noscript> fallback
 * in templates/widget.php) so the slider's "Accessible alternative" link
 * can fetch a real one over REST -- see ChallengeController::resolve_mode()
 * for why math is never risk-escalated-to, only ever requested outright.
 */
class ChallengeFactory {

	public const DEFAULT_MODE = 'invisible';

	private const MODES = array(
		'invisible' => PowChallenge::class,
		'slider'    => SliderChallenge::class,
		'math'      => MathChallenge::class,
	);

	public static function is_known_mode( string $mode ): bool {
		return isset( self::MODES[ $mode ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function create( string $mode, string $form, int $maxnumber, int $ttl ): array {
		$class = self::MODES[ $mode ] ?? self::MODES[ self::DEFAULT_MODE ];

		return $class::create( $form, $maxnumber, $ttl );
	}
}
