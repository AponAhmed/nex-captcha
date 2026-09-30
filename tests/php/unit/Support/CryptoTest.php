<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Support;

use Bitflw\NexCaptcha\Support\Crypto;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;
use ReflectionProperty;

final class CryptoTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
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

	public function test_get_secret_generates_and_persists_when_missing(): void {
		Functions\expect( 'get_option' )->once()->with( 'nxc_secret' )->andReturn( false );
		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				static function ( string $key, string $value, bool $autoload ): bool {
					return 'nxc_secret' === $key && false === $autoload && false !== base64_decode( $value, true );
				}
			)
			->andReturn( true );

		$secret = Crypto::get_secret();

		$this->assertSame( 32, strlen( $secret ) );
	}

	public function test_get_secret_reuses_a_stored_option(): void {
		$stored = base64_encode( str_repeat( 'a', 32 ) );
		Functions\expect( 'get_option' )->once()->with( 'nxc_secret' )->andReturn( $stored );
		Functions\expect( 'update_option' )->never();

		$secret = Crypto::get_secret();

		$this->assertSame( str_repeat( 'a', 32 ), $secret );
	}

	public function test_get_secret_is_cached_across_calls(): void {
		Functions\expect( 'get_option' )->once()->andReturn( base64_encode( 'x' ) );

		$first  = Crypto::get_secret();
		$second = Crypto::get_secret();

		$this->assertSame( $first, $second );
	}

	public function test_hmac_is_deterministic_for_the_same_secret(): void {
		$a = Crypto::hmac( 'payload', 'secret' );
		$b = Crypto::hmac( 'payload', 'secret' );

		$this->assertSame( $a, $b );
		$this->assertSame( 64, strlen( $a ) );
	}

	public function test_hmac_differs_for_different_secrets(): void {
		$this->assertNotSame(
			Crypto::hmac( 'payload', 'secret-a' ),
			Crypto::hmac( 'payload', 'secret-b' )
		);
	}

	public function test_random_hex_length(): void {
		$this->assertSame( 24, strlen( Crypto::random_hex( 12 ) ) );
	}

	public function test_equals_wraps_hash_equals(): void {
		$this->assertTrue( Crypto::equals( 'abc', 'abc' ) );
		$this->assertFalse( Crypto::equals( 'abc', 'abd' ) );
	}
}
