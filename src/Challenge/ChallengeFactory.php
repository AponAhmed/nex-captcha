<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Challenge;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the right challenge instance for a requested or risk-adjusted mode.
 *
 * MathChallenge is deliberately not reachable here: it's only ever used by
 * the <noscript> fallback rendered server-side in templates/widget.php, never
 * requested through the REST challenge endpoint this factory serves.
 */
class ChallengeFactory {

	public const DEFAULT_MODE = 'invisible';

	private const MODES = array(
		'invisible' => PowChallenge::class,
		'slider'    => SliderChallenge::class,
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
