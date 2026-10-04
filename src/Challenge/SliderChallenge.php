<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Challenge;

use Bitflw\NexCaptcha\Support\Crypto;
use GdImage;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered slider puzzle challenge.
 *
 * Same HMAC-signed-token approach as PowChallenge/MathChallenge (one
 * of the two options docs/PLAN.md section 3.4 offers, chosen over
 * sodium_crypto_secretbox for consistency -- no new crypto primitive
 * for a third feature when HMAC already does the job). The gap
 * position (both X and Y -- a real 2D jigsaw puzzle, not a 1D
 * horizontal slider) is never transmitted or stored anywhere:
 * signature = HMAC(salt . gapX . '_' . gapY, secret), and verify()
 * recovers both by searching the full valid coordinate grid for the
 * one pair whose HMAC matches -- cheap (tens of thousands of HMACs,
 * still sub-second) for the server, which holds the secret, and
 * meaningless as an attack surface for anyone who doesn't.
 */
class SliderChallenge implements ChallengeInterface {

	private const WIDTH      = 300;
	private const HEIGHT     = 150;
	private const PIECE_SIZE = 40;
	private const MARGIN     = 10;
	private const TOLERANCE  = 5;

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $maxnumber is part of ChallengeInterface's contract; no PoW difficulty concept applies to a slider puzzle.
	public static function create( string $form, int $maxnumber, int $ttl ): array {
		$gap_x = random_int( self::MARGIN, self::WIDTH - self::PIECE_SIZE - self::MARGIN );
		$gap_y = random_int( self::MARGIN, self::HEIGHT - self::PIECE_SIZE - self::MARGIN );

		$background = self::draw_background();
		$piece      = self::extract_piece( $background, $gap_x, $gap_y );
		self::draw_gap( $background, $gap_x, $gap_y );

		$image_uri = self::to_data_uri( $background );
		$piece_uri = self::to_data_uri( $piece );

		imagedestroy( $background );
		imagedestroy( $piece );

		$expires = time() + $ttl;
		$salt    = Crypto::random_hex( 12 ) . '?expires=' . $expires . '&form=' . rawurlencode( $form ) . '&v=1';

		return array(
			'mode'      => 'slider',
			'image'     => $image_uri,
			'piece'     => $piece_uri,
			'salt'      => $salt,
			'signature' => Crypto::hmac( $salt . $gap_x . '_' . $gap_y ),
		);
	}

	public static function verify( array $payload, string $form ): ?string {
		$trace_reason = self::check_trace( $payload['trace'] ?? null );

		if ( null !== $trace_reason ) {
			return $trace_reason;
		}

		$salt      = (string) ( $payload['salt'] ?? '' );
		$signature = (string) ( $payload['signature'] ?? '' );
		$x         = (int) ( $payload['x'] ?? -1 );
		$y         = (int) ( $payload['y'] ?? -1 );

		$gap = self::find_gap_xy( $salt, $signature );

		if ( null === $gap ) {
			return 'bad_signature';
		}

		if ( abs( $x - $gap[0] ) > self::TOLERANCE || abs( $y - $gap[1] ) > self::TOLERANCE ) {
			return 'bad_position';
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
	 * Same shape as PowChallenge::expiry()/MathChallenge::expiry(), for
	 * the replay guard's TTL.
	 */
	public static function expiry( array $payload ): int {
		$query = self::parse_salt( (string) ( $payload['salt'] ?? '' ) );

		return null === $query ? 0 : (int) $query['expires'];
	}

	/**
	 * Recovers (gapX, gapY) by searching every valid coordinate pair for
	 * the one whose HMAC matches the signature -- the only pair that
	 * could have produced it, short of breaking HMAC. Bounded the same
	 * way create() bounds its own random_int() calls, so this covers
	 * every value create() could have picked.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private static function find_gap_xy( string $salt, string $signature ): ?array {
		$max_x = self::WIDTH - self::PIECE_SIZE - self::MARGIN;
		$max_y = self::HEIGHT - self::PIECE_SIZE - self::MARGIN;

		for ( $x = self::MARGIN; $x <= $max_x; $x++ ) {
			for ( $y = self::MARGIN; $y <= $max_y; $y++ ) {
				if ( Crypto::equals( Crypto::hmac( $salt . $x . '_' . $y ), $signature ) ) {
					return array( $x, $y );
				}
			}
		}

		return null;
	}

	/**
	 * Deliberately simple heuristics ("at least a few points, spread
	 * over some non-zero time, with some movement on at least one
	 * axis"), not a sophisticated bot classifier -- consistent with
	 * this being a self-hosted single-purpose plugin rather than an
	 * enterprise bot-detection product.
	 *
	 * @param mixed $trace
	 */
	private static function check_trace( $trace ): ?string {
		if ( ! is_array( $trace ) || count( $trace ) < 3 ) {
			return 'bad_trace';
		}

		$first_t = null;
		$prev_t  = null;
		$xs      = array();
		$ys      = array();

		foreach ( $trace as $point ) {
			if ( ! is_array( $point ) || ! isset( $point['x'], $point['y'], $point['t'] ) || ! is_numeric( $point['x'] ) || ! is_numeric( $point['y'] ) || ! is_numeric( $point['t'] ) ) {
				return 'bad_trace';
			}

			$t = (float) $point['t'];

			if ( null === $first_t ) {
				$first_t = $t;
			}

			if ( null !== $prev_t && $t <= $prev_t ) {
				return 'bad_trace';
			}

			$prev_t = $t;
			$xs[]   = (float) $point['x'];
			$ys[]   = (float) $point['y'];
		}

		if ( ( $prev_t - $first_t ) < 50.0 ) {
			return 'bad_trace';
		}

		if ( ( max( $xs ) - min( $xs ) ) < 2.0 && ( max( $ys ) - min( $ys ) ) < 2.0 ) {
			return 'bad_trace';
		}

		return null;
	}

	private static function draw_background(): GdImage {
		$image = imagecreatetruecolor( self::WIDTH, self::HEIGHT );

		$base = imagecolorallocate( $image, random_int( 150, 220 ), random_int( 150, 220 ), random_int( 150, 220 ) );
		imagefilledrectangle( $image, 0, 0, self::WIDTH, self::HEIGHT, $base );

		for ( $i = 0; $i < 12; $i++ ) {
			$color = imagecolorallocate( $image, random_int( 0, 255 ), random_int( 0, 255 ), random_int( 0, 255 ) );
			$x     = random_int( 0, self::WIDTH );
			$y     = random_int( 0, self::HEIGHT );
			$size  = random_int( 10, 40 );

			if ( 0 === $i % 2 ) {
				imagefilledellipse( $image, $x, $y, $size, $size, $color );
			} else {
				imagefilledrectangle( $image, $x, $y, $x + $size, $y + $size, $color );
			}
		}

		return $image;
	}

	/**
	 * Copies the background's undisturbed pixels at the gap position,
	 * before the gap indicator is drawn over them -- a real jigsaw
	 * piece shows the texture it was cut from.
	 */
	private static function extract_piece( GdImage $background, int $gap_x, int $piece_y ): GdImage {
		$piece = imagecreatetruecolor( self::PIECE_SIZE, self::PIECE_SIZE );
		imagecopy( $piece, $background, 0, 0, $gap_x, $piece_y, self::PIECE_SIZE, self::PIECE_SIZE );

		return $piece;
	}

	private static function draw_gap( GdImage $background, int $gap_x, int $piece_y ): void {
		$overlay = imagecolorallocatealpha( $background, 0, 0, 0, 90 );
		imagefilledrectangle( $background, $gap_x, $piece_y, $gap_x + self::PIECE_SIZE, $piece_y + self::PIECE_SIZE, $overlay );

		$border = imagecolorallocate( $background, 255, 255, 255 );
		imagerectangle( $background, $gap_x, $piece_y, $gap_x + self::PIECE_SIZE - 1, $piece_y + self::PIECE_SIZE - 1, $border );
	}

	private static function to_data_uri( GdImage $image ): string {
		ob_start();
		imagepng( $image );
		$binary = (string) ob_get_clean();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data: URI encoding, not obfuscation.
		return 'data:image/png;base64,' . base64_encode( $binary );
	}

	/**
	 * Same salt format and parsing as PowChallenge/MathChallenge --
	 * duplicated deliberately again rather than extracting a shared
	 * helper; still only a few lines per call site.
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
