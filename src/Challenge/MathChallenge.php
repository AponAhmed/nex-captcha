<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Challenge;

use Bitflw\NexCaptcha\Support\Crypto;
use GdImage;

defined( 'ABSPATH' ) || exit;

/**
 * Math/text challenge used for escalation and as the accessible fallback.
 *
 * The client never receives the answer, only signature = HMAC(salt .
 * answer, secret) -- a keyed hash, not reversible to the answer even
 * for a low-entropy space like a single-digit sum, since that needs
 * the secret. This is what section 2.1 means by "answer is hashed into
 * the signed token" and section 3.4 means by "never sent to the client".
 *
 * The question itself is NOT a security boundary the way the answer
 * is: `questionImage`'s per-character rotation is a cosmetic, classic-
 * picture-captcha style, not a new anti-bot measure -- the plain-text
 * `question` field ships alongside it on purpose (as `alt` text on the
 * client), since this mode is this project's own accessible fallback
 * (reached via the slider's "Accessible alternative" link); hiding the
 * question from screen readers here would defeat that purpose. A bot
 * could always just read `question` directly -- the arithmetic was
 * never hard to compute once visible, image or not.
 */
class MathChallenge implements ChallengeInterface {

	// GD's built-in bitmap font (no external TTF file to bundle/license) -- font 5 is its largest, 9x15px per glyph.
	private const FONT           = 5;
	private const CHAR_ADVANCE   = 28;
	private const CHAR_CANVAS    = 24;
	private const IMAGE_HEIGHT   = 60;
	private const ROTATE_DEGREES = 12;
	private const Y_JITTER       = 3;

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $maxnumber is part of ChallengeInterface's contract; no PoW difficulty concept applies to a math question.
	public static function create( string $form, int $maxnumber, int $ttl ): array {
		$a        = random_int( 1, 9 );
		$b        = random_int( 1, 9 );
		$answer   = $a + $b;
		$question = "{$a} + {$b}";

		$expires = time() + $ttl;
		$salt    = Crypto::random_hex( 12 ) . '?expires=' . $expires . '&form=' . rawurlencode( $form ) . '&v=1';

		return array(
			'mode'          => 'math',
			'question'      => $question,
			'questionImage' => self::draw_question_image( $question ),
			'salt'          => $salt,
			'signature'     => Crypto::hmac( $salt . $answer ),
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
	 * Renders $question as a light-background image with each character
	 * drawn separately and rotated a small random angle -- a classic
	 * picture-captcha look using only GD's built-in bitmap font, so
	 * there's no TTF file to bundle (and its licensing/portability
	 * concerns across arbitrary WordPress hosts to think about).
	 */
	private static function draw_question_image( string $question ): string {
		$chars  = str_split( $question );
		$width  = count( $chars ) * self::CHAR_ADVANCE + (int) ( self::CHAR_ADVANCE / 2 );
		$canvas = imagecreatetruecolor( $width, self::IMAGE_HEIGHT );

		$background = imagecolorallocate( $canvas, 235, 235, 240 );
		imagefilledrectangle( $canvas, 0, 0, $width, self::IMAGE_HEIGHT, $background );

		for ( $i = 0; $i < 4; $i++ ) {
			$noise = imagecolorallocate( $canvas, random_int( 180, 210 ), random_int( 180, 210 ), random_int( 180, 210 ) );
			imageline( $canvas, random_int( 0, $width ), random_int( 0, self::IMAGE_HEIGHT ), random_int( 0, $width ), random_int( 0, self::IMAGE_HEIGHT ), $noise );
		}

		$x = (int) ( self::CHAR_ADVANCE / 4 );

		foreach ( $chars as $char ) {
			self::draw_rotated_char( $canvas, $char, $background, $x );
			$x += self::CHAR_ADVANCE;
		}

		return self::to_data_uri( $canvas );
	}

	private static function draw_rotated_char( GdImage $canvas, string $char, int $background, int $x ): void {
		$glyph = imagecreatetruecolor( self::CHAR_CANVAS, self::CHAR_CANVAS + 6 );
		imagefilledrectangle( $glyph, 0, 0, self::CHAR_CANVAS, self::CHAR_CANVAS + 6, $background );

		$text_color = imagecolorallocate( $glyph, 30, 30, 40 );
		imagestring( $glyph, self::FONT, 7, 7, $char, $text_color );

		$angle   = (float) random_int( -self::ROTATE_DEGREES, self::ROTATE_DEGREES );
		$rotated = imagerotate( $glyph, $angle, $background );

		$y_jitter = random_int( -self::Y_JITTER, self::Y_JITTER );
		$dest_y   = (int) ( ( self::IMAGE_HEIGHT - imagesy( $rotated ) ) / 2 ) + $y_jitter;

		imagecopy( $canvas, $rotated, $x, $dest_y, 0, 0, imagesx( $rotated ), imagesy( $rotated ) );

		imagedestroy( $glyph );
		imagedestroy( $rotated );
	}

	private static function to_data_uri( GdImage $image ): string {
		ob_start();
		imagepng( $image );
		$binary = (string) ob_get_clean();

		imagedestroy( $image );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data: URI encoding, not obfuscation.
		return 'data:image/png;base64,' . base64_encode( $binary );
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
