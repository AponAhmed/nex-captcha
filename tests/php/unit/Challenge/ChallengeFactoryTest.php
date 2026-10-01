<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Challenge;

use Bitflw\NexCaptcha\Challenge\ChallengeFactory;
use Bitflw\NexCaptcha\Support\Crypto;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;
use ReflectionProperty;

final class ChallengeFactoryTest extends TestCase {

	private const TTL  = 600;
	private const FORM = 'login';

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

	public function test_invisible_is_a_known_mode(): void {
		$this->assertTrue( ChallengeFactory::is_known_mode( 'invisible' ) );
	}

	public function test_slider_is_a_known_mode(): void {
		$this->assertTrue( ChallengeFactory::is_known_mode( 'slider' ) );
	}

	public function test_math_is_not_a_known_mode(): void {
		// MathChallenge is noscript-only, never reachable through the
		// REST endpoint this factory serves.
		$this->assertFalse( ChallengeFactory::is_known_mode( 'math' ) );
	}

	public function test_an_unknown_mode_is_not_known(): void {
		$this->assertFalse( ChallengeFactory::is_known_mode( 'bogus' ) );
	}

	public function test_invisible_mode_creates_a_pow_payload(): void {
		$payload = ChallengeFactory::create( 'invisible', self::FORM, 2000, self::TTL );

		$this->assertArrayHasKey( 'maxnumber', $payload );
		$this->assertArrayHasKey( 'challenge', $payload );
	}

	public function test_slider_mode_creates_a_slider_payload(): void {
		$payload = ChallengeFactory::create( 'slider', self::FORM, 0, self::TTL );

		$this->assertSame( 'slider', $payload['mode'] );
		$this->assertArrayHasKey( 'image', $payload );
		$this->assertArrayHasKey( 'piece', $payload );
	}

	public function test_an_unknown_mode_falls_back_to_the_default(): void {
		$payload = ChallengeFactory::create( 'bogus', self::FORM, 2000, self::TTL );

		$this->assertArrayHasKey( 'maxnumber', $payload );
		$this->assertArrayHasKey( 'challenge', $payload );
	}
}
