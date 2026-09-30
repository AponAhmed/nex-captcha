<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Challenge;

use Bitflw\NexCaptcha\Challenge\PowChallenge;
use Bitflw\NexCaptcha\Support\Crypto;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;
use ReflectionProperty;

final class PowChallengeTest extends TestCase {

	// Small on purpose: brute force must stay fast in the test suite.
	private const MAXNUMBER = 2000;
	private const TTL       = 600;
	private const FORM      = 'login';

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

	/**
	 * Brute-forces the number, the same way a real browser's Web Worker
	 * would, so the "done when" bar from docs/PLAN.md section 5 (Phase 1)
	 * is actually exercised: create a challenge, solve it, verify it.
	 */
	private function solve( array $payload ): int {
		for ( $number = 0; $number <= $payload['maxnumber']; $number++ ) {
			if ( hash( 'sha256', $payload['salt'] . $number ) === $payload['challenge'] ) {
				return $number;
			}
		}

		$this->fail( 'Could not solve the test challenge.' );
	}

	public function test_a_correctly_solved_challenge_verifies(): void {
		$payload = PowChallenge::create( self::FORM, self::MAXNUMBER, self::TTL );

		$submitted           = $payload;
		$submitted['number'] = $this->solve( $payload );

		$this->assertNull( PowChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_tampered_salt_fails_as_bad_signature(): void {
		$payload = PowChallenge::create( self::FORM, self::MAXNUMBER, self::TTL );

		$submitted           = $payload;
		$submitted['number'] = $this->solve( $payload );
		$submitted['salt']   = 'x' . substr( $submitted['salt'], 1 );

		$this->assertSame( 'bad_signature', PowChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_an_unsolved_number_fails_as_bad_signature(): void {
		$payload = PowChallenge::create( self::FORM, self::MAXNUMBER, self::TTL );

		$submitted           = $payload;
		$submitted['number'] = 0;

		if ( hash( 'sha256', $submitted['salt'] . '0' ) === $submitted['challenge'] ) {
			$this->markTestSkipped( 'Got lucky: 0 happened to be the real solution.' );
		}

		$this->assertSame( 'bad_signature', PowChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_tampered_signature_fails_as_bad_signature(): void {
		$payload = PowChallenge::create( self::FORM, self::MAXNUMBER, self::TTL );

		$submitted              = $payload;
		$submitted['number']    = $this->solve( $payload );
		$submitted['signature'] = str_repeat( '0', strlen( $submitted['signature'] ) );

		$this->assertSame( 'bad_signature', PowChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_an_expired_challenge_fails(): void {
		$payload = PowChallenge::create( self::FORM, self::MAXNUMBER, -1 );

		$submitted           = $payload;
		$submitted['number'] = $this->solve( $payload );

		$this->assertSame( 'expired', PowChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_token_for_a_different_form_fails(): void {
		$payload = PowChallenge::create( self::FORM, self::MAXNUMBER, self::TTL );

		$submitted           = $payload;
		$submitted['number'] = $this->solve( $payload );

		$this->assertSame( 'wrong_form', PowChallenge::verify( $submitted, 'comments' ) );
	}
}
