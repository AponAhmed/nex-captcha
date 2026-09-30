<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Challenge;

defined( 'ABSPATH' ) || exit;

/**
 * Contract implemented by every challenge mode (PoW, slider, math).
 */
interface ChallengeInterface {

	/**
	 * Builds a challenge payload for the REST response.
	 */
	public static function create( string $form, int $maxnumber, int $ttl ): array;

	/**
	 * Cryptographically verifies a decoded submitted token against $form.
	 * Returns null when valid, or a reason code when not.
	 */
	public static function verify( array $payload, string $form ): ?string;
}
