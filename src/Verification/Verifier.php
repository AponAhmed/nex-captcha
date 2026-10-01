<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Verification;

use Bitflw\NexCaptcha\Challenge\MathChallenge;
use Bitflw\NexCaptcha\Challenge\PowChallenge;
use Bitflw\NexCaptcha\Challenge\SliderChallenge;
use Bitflw\NexCaptcha\Signals\Honeypot;
use Bitflw\NexCaptcha\Signals\InteractionCheck;
use Bitflw\NexCaptcha\Signals\TimeTrap;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies a submitted token against its challenge and returns a Result.
 *
 * Dispatches on which fields are present: nxc_token is the normal
 * JS-driven PoW path; nxc_slider_salt with no nxc_token is the slider
 * drag path; nxc_math_answer with neither is the <noscript> fallback
 * (templates/widget.php renders it only when the JS-driven paths above
 * aren't in play, so a weird submission naming none of these fields is
 * not a real client).
 */
class Verifier {

	public static function verify( string $form_id, array $data ): Result {
		$has_token  = isset( $data['nxc_token'] ) && '' !== $data['nxc_token'];
		$has_slider = isset( $data['nxc_slider_salt'] ) && '' !== $data['nxc_slider_salt'];
		$has_math   = isset( $data['nxc_math_answer'] );
		$mode       = self::mode_for( $has_token, $has_slider, $has_math );

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

		if ( $has_slider ) {
			return self::verify_slider( $form_id, $data );
		}

		if ( $has_math ) {
			return self::verify_math( $form_id, $data );
		}

		return self::fail( 'malformed_token', $mode, $form_id );
	}

	private static function mode_for( bool $has_token, bool $has_slider, bool $has_math ): string {
		if ( $has_token ) {
			return 'invisible';
		}

		if ( $has_slider ) {
			return 'slider';
		}

		return $has_math ? 'math' : 'invisible';
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
	 * InteractionCheck is deliberately not run here either: a slider
	 * drag *is* the interaction, so checking a separate JS-set flag on
	 * top would be a redundant signal, not a bug to avoid (unlike
	 * math's case below, this isn't about accessibility).
	 */
	private static function verify_slider( string $form_id, array $data ): Result {
		$mode = 'slider';

		$payload = array(
			'salt'      => $data['nxc_slider_salt'] ?? '',
			'signature' => $data['nxc_slider_signature'] ?? '',
			'x'         => $data['nxc_slider_x'] ?? -1,
			'trace'     => self::decode_trace( $data['nxc_slider_trace'] ?? null ),
		);

		$reason = SliderChallenge::verify( $payload, $form_id );

		if ( null !== $reason ) {
			return self::fail( $reason, $mode, $form_id );
		}

		// No separate "challenge" hash the way PoW has; the salt is
		// equally unique per issuance, so it serves the same role as
		// the replay guard's key (same reasoning as verify_math()'s).
		$salt = (string) $payload['salt'];

		if ( ReplayGuard::is_used( $salt ) ) {
			return self::fail( 'replayed', $mode, $form_id );
		}

		$ttl = max( 0, SliderChallenge::expiry( $payload ) - time() );
		ReplayGuard::mark_used( $salt, $ttl );

		do_action( 'nxc_verified', $mode, $form_id, null );

		return Result::pass( $mode );
	}

	/**
	 * The trace travels over HTTP as a JSON string (nxc_slider_trace),
	 * the same way widget.ts will send it -- not as native PHP array
	 * fields the way $_POST can't represent nested structures anyway.
	 *
	 * wp_unslash() matters here specifically: WordPress's addslashes_deep()
	 * backslash-escapes every quote in $_POST, which corrupts JSON's own
	 * quotes before json_decode() ever sees them -- confirmed live, where
	 * a validly-solved slider submission came back bad_trace until this
	 * was added.
	 *
	 * @param mixed $trace
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function decode_trace( $trace ): array {
		if ( ! is_string( $trace ) || '' === $trace ) {
			return array();
		}

		$decoded = json_decode( wp_unslash( $trace ), true );

		return is_array( $decoded ) ? $decoded : array();
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
