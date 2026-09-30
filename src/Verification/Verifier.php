<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Verification;

use Bitflw\NexCaptcha\Challenge\PowChallenge;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies a submitted token against its challenge and returns a Result.
 *
 * Only the invisible/checkbox PoW mode exists in Phase 1; slider and math
 * dispatch will be added here once ChallengeFactory grows those modes.
 */
class Verifier {

	public static function verify( string $form_id, array $data ): Result {
		$mode    = 'invisible';
		$payload = Token::decode( $data['nxc_token'] ?? null );

		if ( null === $payload ) {
			return self::fail( 'malformed_token', $mode, $form_id );
		}

		$reason = PowChallenge::verify( $payload, $form_id );

		if ( null !== $reason ) {
			return self::fail( $reason, $mode, $form_id );
		}

		$challenge = (string) $payload['challenge'];

		if ( ReplayGuard::is_used( $challenge ) ) {
			return self::fail( 'replayed', $mode, $form_id );
		}

		$ttl = max( 0, PowChallenge::expiry( $payload ) - time() );
		ReplayGuard::mark_used( $challenge, $ttl );

		do_action( 'nxc_verified', $mode, $form_id );

		return Result::pass( $mode );
	}

	private static function fail( string $reason, string $mode, string $form_id ): Result {
		do_action( 'nxc_failed', $reason, $mode, $form_id );

		return Result::fail( $reason, $mode );
	}
}
