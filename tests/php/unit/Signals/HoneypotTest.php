<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Signals;

use Bitflw\NexCaptcha\Signals\Honeypot;
use Bitflw\NexCaptcha\Tests\TestCase;

final class HoneypotTest extends TestCase {

	public function test_an_empty_field_is_clean(): void {
		$this->assertNull( ( new Honeypot() )->check( array( 'nxc_hp' => '' ) ) );
	}

	public function test_a_missing_field_is_clean(): void {
		$this->assertNull( ( new Honeypot() )->check( array() ) );
	}

	public function test_a_filled_field_fires(): void {
		$this->assertSame( 'honeypot', ( new Honeypot() )->check( array( 'nxc_hp' => 'i am a bot' ) ) );
	}
}
