<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Verification;

use Bitflw\NexCaptcha\Challenge\MathChallenge;
use Bitflw\NexCaptcha\Challenge\PowChallenge;
use Bitflw\NexCaptcha\Signals\Honeypot;
use Bitflw\NexCaptcha\Signals\InteractionCheck;
use Bitflw\NexCaptcha\Signals\TimeTrap;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies a submitted token against its challenge and returns a Result.
 *
 * Dispatches on which fields are present: nxc_token is the normal
 * JS-driven PoW path; nxc_math_answer with no nxc_token is the
 * <noscript> fallback (templates/widget.php renders both only when
 * MathChallenge's HMAC-answer check also failed, so a weird submission
 * naming neither field is not a real client). Slider dispatch will be
 * added here once ChallengeFactory grows that mode.
 */
class Verifier {

	public static function verify( string $form_id, array $data ): Result {
		$has_token = isset( $data['nxc_token'] ) && '' !== $data['nxc_token'];
		$has_math  = isset( $data['nxc_math_answer'] );
		$mode      = $has_math && ! $has_token ? 'math' : 'invisible';

		// Honeypot/TimeTrap apply regardless of which path a submission
		// is attempting (or neither) -- cheapest, most certain signals
		// first, and the ones that work with no JS at all, so a bot that
		// doesn't even bother with a token/answer still gets logged
		// under the right reason rather than a generic malformed_token.
		foreach ( array( new Honeypot(), new TimeTrap() ) as $signal ) {
			$reason = $signal->check( $data );

			if ( null !== $reason ) {
				return self::fail( $reason, $mode, $form_id );
			}
		}

		if ( $has_token ) {
			return self::verify_pow( $form_id, $data );
		}

		if ( $has_math ) {
			return self::verify_math( $form_id, $data );
		}

		return self::fail( 'malformed_token', $mode, $form_id );
	}

	private static function verify_pow( string $form_id, array $data ): Result {
		$mode = 'invisible';

		// Set only by widget.ts's event listeners -- meaningless to check
		// before we know a token exists at all (handled by the shared
		// Honeypot/TimeTrap pass in verify()), and the math fallback path
		// never runs it (see verify_math()'s own docblock).
		$reason = ( new InteractionCheck() )->check( $data );

		if ( null !== $reason ) {
			return self::fail( $reason, $mode, $form_id );
		}

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

		$solve_ms = isset( $payload['took'] ) && is_numeric( $payload['took'] ) ? (int) $payload['took'] : null;

		do_action( 'nxc_verified', $mode, $form_id, $solve_ms );

		return Result::pass( $mode );
	}

	/**
	 * InteractionCheck is deliberately not run here: it's set only by
	 * widget.ts's event listeners, and a genuine no-JS visitor using
	 * this fallback could never satisfy it -- that would make the
	 * accessible fallback unusable for exactly the people it exists for.
	 */
	private static function verify_math( string $form_id, array $data ): Result {
		$mode = 'math';

		$payload = array(
			'salt'      => $data['nxc_math_salt'] ?? '',
			'signature' => $data['nxc_math_signature'] ?? '',
			'answer'    => $data['nxc_math_answer'] ?? '',
		);

		$reason = MathChallenge::verify( $payload, $form_id );

		if ( null !== $reason ) {
			return self::fail( $reason, $mode, $form_id );
		}

		// Math has no separate "challenge" hash the way PoW does; the
		// salt is equally unique per issuance, so it serves the same
		// role as the replay guard's key.
		$salt = (string) $payload['salt'];

		if ( ReplayGuard::is_used( $salt ) ) {
			return self::fail( 'replayed', $mode, $form_id );
		}

		$ttl = max( 0, MathChallenge::expiry( $payload ) - time() );
		ReplayGuard::mark_used( $salt, $ttl );

		do_action( 'nxc_verified', $mode, $form_id, null );

		return Result::pass( $mode );
	}

	private static function fail( string $reason, string $mode, string $form_id ): Result {
		do_action( 'nxc_failed', $reason, $mode, $form_id );

		return Result::fail( $reason, $mode );
	}
}
