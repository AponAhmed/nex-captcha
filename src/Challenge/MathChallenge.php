<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Challenge;

use Bitflw\NexCaptcha\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Math/text challenge used for escalation and as the accessible fallback.
 *
 * The client never receives the answer, only signature = HMAC(salt .
 * answer, secret) -- a keyed hash, not reversible to the answer even
 * for a low-entropy space like a single-digit sum, since that needs
 * the secret. This is what section 2.1 means by "answer is hashed into
 * the signed token" and section 3.4 means by "never sent to the client".
 */
class MathChallenge implements ChallengeInterface {

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $maxnumber is part of ChallengeInterface's contract; no PoW difficulty concept applies to a math question.
	public static function create( string $form, int $maxnumber, int $ttl ): array {
		$a      = random_int( 1, 9 );
		$b      = random_int( 1, 9 );
		$answer = $a + $b;

		$expires = time() + $ttl;
		$salt    = Crypto::random_hex( 12 ) . '?expires=' . $expires . '&form=' . rawurlencode( $form ) . '&v=1';

		return array(
			'mode'      => 'math',
			'question'  => "{$a} + {$b}",
			'salt'      => $salt,
			'signature' => Crypto::hmac( $salt . $answer ),
		);
	}

	public static function verify( array $payload, string $form ): ?string {
		$salt      = (string) $payload['salt'];
		$answer    = trim( (string) $payload['answer'] );
		$signature = (string) $payload['signature'];

		if ( ! Crypto::equals( Crypto::hmac( $salt . $answer ), $signature ) ) {
			return 'bad_signature';
		}

		$query = self::parse_salt( $salt );

		if ( null === $query ) {
			return 'bad_signature';
		}

		if ( time() > (int) $query['expires'] ) {
			return 'expired';
		}

		if ( ! hash_equals( $form, $query['form'] ) ) {
			return 'wrong_form';
		}

		return null;
	}

	/**
	 * The unix timestamp a decoded payload's challenge expires at, or 0
	 * if the salt can't be parsed. Used to size the replay guard's TTL,
	 * same as PowChallenge::expiry().
	 */
	public static function expiry( array $payload ): int {
		$query = self::parse_salt( (string) $payload['salt'] );

		return null === $query ? 0 : (int) $query['expires'];
	}

	/**
	 * Same salt format and parsing as PowChallenge -- duplicated
	 * deliberately rather than shared for just two call sites.
	 *
	 * @return array{expires: string, form: string}|null
	 */
	private static function parse_salt( string $salt ): ?array {
		$parts = explode( '?', $salt, 2 );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		parse_str( $parts[1], $query );

		if ( ! isset( $query['expires'], $query['form'] ) || ! is_string( $query['expires'] ) || ! is_string( $query['form'] ) ) {
			return null;
		}

		return array(
			'expires' => $query['expires'],
			'form'    => $query['form'],
		);
	}
}
