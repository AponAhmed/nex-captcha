<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Verification;

use Bitflw\NexCaptcha\Challenge\PowChallenge;
use Bitflw\NexCaptcha\Support\Crypto;
use Bitflw\NexCaptcha\Tests\TestCase;
use Bitflw\NexCaptcha\Verification\Verifier;
use Brain\Monkey\Functions;
use ReflectionProperty;

final class VerifierTest extends TestCase {

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

	private function solved_token(): string {
		$payload = PowChallenge::create( self::FORM, 2000, 600 );

		for ( $number = 0; $number <= $payload['maxnumber']; $number++ ) {
			if ( hash( 'sha256', $payload['salt'] . $number ) === $payload['challenge'] ) {
				$payload['number'] = $number;
				$payload['took']   = 10;
				return base64_encode( (string) json_encode( $payload ) );
			}
		}

		$this->fail( 'Could not solve the test challenge.' );
	}

	public function test_a_malformed_token_fails(): void {
		Functions\expect( 'do_action' )->zeroOrMoreTimes();

		$result = Verifier::verify( self::FORM, [ 'nxc_token' => 'not-base64-json' ] );

		$this->assertFalse( $result->ok );
		$this->assertSame( 'malformed_token', $result->reason );
	}

	public function test_a_valid_solved_token_passes_and_marks_it_used(): void {
		$token = $this->solved_token();

		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->once()->andReturn( true );
		Functions\expect( 'do_action' )->zeroOrMoreTimes();

		$result = Verifier::verify( self::FORM, [ 'nxc_token' => $token ] );

		$this->assertTrue( $result->ok );
		$this->assertSame( 'invisible', $result->mode );
	}

	public function test_a_replayed_token_fails(): void {
		$token = $this->solved_token();

		Functions\expect( 'get_transient' )->once()->andReturn( 1 );
		Functions\expect( 'do_action' )->zeroOrMoreTimes();

		$result = Verifier::verify( self::FORM, [ 'nxc_token' => $token ] );

		$this->assertFalse( $result->ok );
		$this->assertSame( 'replayed', $result->reason );
	}

	public function test_success_fires_nxc_verified(): void {
		$token = $this->solved_token();

		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->once()->andReturn( true );
		Functions\expect( 'do_action' )->once()->with( 'nxc_verified', 'invisible', self::FORM );

		Verifier::verify( self::FORM, [ 'nxc_token' => $token ] );

		// Mockery verifies the expectation above in tearDown(); that alone
		// doesn't count as a PHPUnit assertion under failOnRisky="true".
		$this->addToAssertionCount( 1 );
	}

	public function test_failure_fires_nxc_failed_with_the_reason(): void {
		Functions\expect( 'do_action' )->once()->with( 'nxc_failed', 'malformed_token', 'invisible', self::FORM );

		Verifier::verify( self::FORM, [ 'nxc_token' => 'not-base64-json' ] );

		$this->addToAssertionCount( 1 );
	}
}
