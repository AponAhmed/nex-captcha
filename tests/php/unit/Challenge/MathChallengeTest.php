<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Challenge;

use Bitflw\NexCaptcha\Challenge\MathChallenge;
use Bitflw\NexCaptcha\Support\Crypto;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;
use ReflectionProperty;

final class MathChallengeTest extends TestCase {

	private const TTL  = 600;
	private const FORM = 'comments';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->justReturn( base64_encode( str_repeat( 's', 32 ) ) );
		Functions\when( 'update_option' )->justReturn( true );
		$this->reset_secret_cache();
	}

	protected function tearDown(): void {
		$this->reset_secret_cache();
		parent::tearDown();
	}

	private function reset_secret_cache(): void {
		$property = new ReflectionProperty( Crypto::class, 'secret' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	private function answer( array $payload ): int {
		list( $a, $b ) = array_map( 'intval', explode( '+', (string) $payload['question'] ) );

		return $a + $b;
	}

	public function test_create_asks_a_single_digit_sum(): void {
		$payload = MathChallenge::create( self::FORM, 0, self::TTL );

		$this->assertMatchesRegularExpression( '/^\d \+ \d$/', $payload['question'] );
		$this->assertArrayNotHasKey( 'answer', $payload, 'The answer must never be sent to the client.' );
	}

	/**
	 * questionImage is cosmetic (a classic picture-captcha look), not a
	 * new security boundary -- the plain question field above stays
	 * alongside it on purpose, for accessibility. Only asserting shape
	 * here, not pixel content.
	 */
	public function test_create_also_renders_a_distorted_question_image(): void {
		$payload = MathChallenge::create( self::FORM, 0, self::TTL );

		$this->assertArrayHasKey( 'questionImage', $payload );
		$this->assertStringStartsWith( 'data:image/png;base64,', $payload['questionImage'] );

		$binary = base64_decode( substr( $payload['questionImage'], strlen( 'data:image/png;base64,' ) ), true );
		$this->assertNotFalse( $binary );
		$this->assertSame( "\x89PNG\r\n\x1a\n", substr( (string) $binary, 0, 8 ) );
	}

	public function test_a_correct_answer_verifies(): void {
		$payload = MathChallenge::create( self::FORM, 0, self::TTL );

		$submitted           = $payload;
		$submitted['answer'] = (string) $this->answer( $payload );

		$this->assertNull( MathChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_whitespace_around_the_answer_is_tolerated(): void {
		$payload = MathChallenge::create( self::FORM, 0, self::TTL );

		$submitted           = $payload;
		$submitted['answer'] = '  ' . $this->answer( $payload ) . '  ';

		$this->assertNull( MathChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_wrong_answer_fails_as_bad_signature(): void {
		$payload = MathChallenge::create( self::FORM, 0, self::TTL );

		$submitted           = $payload;
		$submitted['answer'] = (string) ( $this->answer( $payload ) + 1 );

		$this->assertSame( 'bad_signature', MathChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_tampered_salt_fails_as_bad_signature(): void {
		$payload = MathChallenge::create( self::FORM, 0, self::TTL );

		$submitted           = $payload;
		$submitted['answer'] = (string) $this->answer( $payload );
		$submitted['salt']   = 'x' . substr( $submitted['salt'], 1 );

		$this->assertSame( 'bad_signature', MathChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_tampered_signature_fails_as_bad_signature(): void {
		$payload = MathChallenge::create( self::FORM, 0, self::TTL );

		$submitted              = $payload;
		$submitted['answer']    = (string) $this->answer( $payload );
		$submitted['signature'] = str_repeat( '0', strlen( $submitted['signature'] ) );

		$this->assertSame( 'bad_signature', MathChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_an_expired_challenge_fails(): void {
		$payload = MathChallenge::create( self::FORM, 0, -1 );

		$submitted           = $payload;
		$submitted['answer'] = (string) $this->answer( $payload );

		$this->assertSame( 'expired', MathChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_token_for_a_different_form_fails(): void {
		$payload = MathChallenge::create( self::FORM, 0, self::TTL );

		$submitted           = $payload;
		$submitted['answer'] = (string) $this->answer( $payload );

		$this->assertSame( 'wrong_form', MathChallenge::verify( $submitted, 'login' ) );
	}
}
