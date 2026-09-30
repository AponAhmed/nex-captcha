<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Challenge;

use Bitflw\NexCaptcha\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Invisible and checkbox proof-of-work challenge (ALTCHA-style).
 *
 * The server picks a secret number, publishes SHA-256(salt + number) and
 * a signature over it, and the browser brute-forces the number.
 */
class PowChallenge implements ChallengeInterface {

	public static function create( string $form, int $maxnumber, int $ttl ): array {
		$expires   = time() + $ttl;
		$salt      = Crypto::random_hex( 12 ) . '?expires=' . $expires . '&form=' . rawurlencode( $form ) . '&v=1';
		$number    = random_int( 0, $maxnumber );
		$challenge = hash( 'sha256', $salt . $number );

		return array(
			'mode'      => 'invisible',
			'algorithm' => 'SHA-256',
			'challenge' => $challenge,
			'maxnumber' => $maxnumber,
			'salt'      => $salt,
			'signature' => Crypto::hmac( $challenge ),
		);
	}

	public static function verify( array $payload, string $form ): ?string {
		$salt      = (string) $payload['salt'];
		$number    = (string) $payload['number'];
		$challenge = (string) $payload['challenge'];
		$signature = (string) $payload['signature'];

		if ( ! Crypto::equals( hash( 'sha256', $salt . $number ), $challenge ) ) {
			return 'bad_signature';
		}

		if ( ! Crypto::equals( Crypto::hmac( $challenge ), $signature ) ) {
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
	 * The unix timestamp a decoded payload's challenge expires at, or 0 if
	 * the salt can't be parsed. Used by the caller to size the replay
	 * guard's TTL so it never outlives the challenge itself.
	 */
	public static function expiry( array $payload ): int {
		$query = self::parse_salt( (string) $payload['salt'] );

		return null === $query ? 0 : (int) $query['expires'];
	}

	/**
	 * Extracts { expires, form } from the salt's "?expires=...&form=...&v=1"
	 * suffix. Returns null if the salt doesn't have a parseable suffix.
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
