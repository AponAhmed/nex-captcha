<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Signals;

use Bitflw\NexCaptcha\Signals\TimeTrap;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;

final class TimeTrapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Default time_trap_seconds (2) applies.
		Functions\when( 'get_option' )->justReturn( array() );
	}

	public function test_an_old_enough_timestamp_is_clean(): void {
		$result = ( new TimeTrap() )->check( array( 'nxc_ts' => time() - 10 ) );

		$this->assertNull( $result );
	}

	public function test_a_too_recent_timestamp_fires(): void {
		$result = ( new TimeTrap() )->check( array( 'nxc_ts' => time() ) );

		$this->assertSame( 'too_fast', $result );
	}

	public function test_a_missing_timestamp_fires(): void {
		$this->assertSame( 'too_fast', ( new TimeTrap() )->check( array() ) );
	}

	public function test_a_non_numeric_timestamp_fires(): void {
		$this->assertSame( 'too_fast', ( new TimeTrap() )->check( array( 'nxc_ts' => 'not-a-number' ) ) );
	}

	public function test_a_future_timestamp_fires(): void {
		// Clock skew or tampering -- never treated as "plenty of time".
		$this->assertSame( 'too_fast', ( new TimeTrap() )->check( array( 'nxc_ts' => time() + 1000 ) ) );
	}
}
