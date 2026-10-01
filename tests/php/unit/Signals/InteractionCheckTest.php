<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Signals;

use Bitflw\NexCaptcha\Signals\InteractionCheck;
use Bitflw\NexCaptcha\Tests\TestCase;

final class InteractionCheckTest extends TestCase {

	public function test_a_flagged_interaction_is_clean(): void {
		$this->assertNull( ( new InteractionCheck() )->check( array( 'nxc_interacted' => '1' ) ) );
	}

	public function test_an_unset_flag_fires(): void {
		$this->assertSame( 'no_interaction', ( new InteractionCheck() )->check( array( 'nxc_interacted' => '0' ) ) );
	}

	public function test_a_missing_field_fires(): void {
		$this->assertSame( 'no_interaction', ( new InteractionCheck() )->check( array() ) );
	}
}
