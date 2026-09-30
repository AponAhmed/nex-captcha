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
		$payload = Token::decode( $data['nxc_token'] ?? null );

		if ( null === $payload ) {
			return Result::fail( 'malformed_token' );
		}

		$mode = 'invisible';

		$reason = PowChallenge::verify( $payload, $form_id );

		if ( null !== $reason ) {
			return Result::fail( $reason, $mode );
		}

		$challenge = (string) $payload['challenge'];

		if ( ReplayGuard::is_used( $challenge ) ) {
			return Result::fail( 'replayed', $mode );
		}

		$ttl = max( 0, PowChallenge::expiry( $payload ) - time() );
		ReplayGuard::mark_used( $challenge, $ttl );

		return Result::pass( $mode );
	}
}
