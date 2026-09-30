<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Signals;

use Bitflw\NexCaptcha\Signals\RateLimiter;
use Bitflw\NexCaptcha\Support\Crypto;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;
use ReflectionProperty;

final class RateLimiterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Default rate_limit (max 10, window 600) applies.
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				return 'nxc_secret' === $key ? base64_encode( str_repeat( 's', 32 ) ) : array();
			}
		);
		$this->reset_secret_cache();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->reset_secret_cache();
		parent::tearDown();
	}

	private function reset_secret_cache(): void {
		$property = new ReflectionProperty( Crypto::class, 'secret' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	public function test_is_limited_is_false_under_the_max(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( 9 );

		$this->assertFalse( RateLimiter::is_limited( 'test' ) );
	}

	public function test_is_limited_is_true_at_the_max(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( 10 );

		$this->assertTrue( RateLimiter::is_limited( 'test' ) );
	}

	public function test_hit_increments_the_stored_count(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( 3 );
		Functions\expect( 'set_transient' )->once()->with( 'nxc_rl_test', 4, 600 )->andReturn( true );

		RateLimiter::hit( 'test' );

		$this->addToAssertionCount( 1 );
	}

	public function test_attempts_reads_without_incrementing(): void {
		Functions\expect( 'get_transient' )->once()->with( 'nxc_rl_test' )->andReturn( 5 );
		Functions\expect( 'set_transient' )->never();

		$this->assertSame( 5, RateLimiter::attempts( 'test' ) );
	}

	public function test_challenge_bucket_is_keyed_by_ip_hash(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		$bucket_a               = RateLimiter::challenge_bucket();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.6';
		$bucket_b               = RateLimiter::challenge_bucket();

		$this->assertNotSame( $bucket_a, $bucket_b );
		$this->assertStringStartsWith( 'challenge_', $bucket_a );
	}
}
