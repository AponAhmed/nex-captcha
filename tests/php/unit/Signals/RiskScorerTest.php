<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Signals;

use Bitflw\NexCaptcha\Signals\RiskScorer;
use Bitflw\NexCaptcha\Support\Crypto;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;
use ReflectionProperty;

final class RiskScorerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				return 'nxc_secret' === $key
					? base64_encode( str_repeat( 's', 32 ) )
					: array( 'rate_limit' => array( 'max' => 10 ) );
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

	public function test_score_is_zero_with_no_attempts(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );

		$this->assertSame( 0, RiskScorer::score() );
	}

	public function test_score_scales_with_attempts_toward_the_max(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( 5 );

		$this->assertSame( 50, RiskScorer::score() );
	}

	public function test_score_caps_at_100(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( 25 );

		$this->assertSame( 100, RiskScorer::score() );
	}

	public function test_adjust_maxnumber_is_unchanged_below_the_escalation_threshold(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( 2 ); // score 20

		$this->assertSame( 100000, RiskScorer::adjust_maxnumber( 100000, 'login' ) );
	}

	public function test_adjust_maxnumber_scales_up_at_high_risk(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( 10 ); // score 100, 3x multiplier

		$this->assertSame( 60000, RiskScorer::adjust_maxnumber( 20000, 'login' ) );
	}

	public function test_adjust_maxnumber_never_exceeds_the_high_difficulty_ceiling(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( 10 ); // score 100, would be 300000 uncapped

		$this->assertSame( 250000, RiskScorer::adjust_maxnumber( 100000, 'login' ) );
	}
}
