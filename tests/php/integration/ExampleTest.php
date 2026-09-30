<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Integration;

use WP_UnitTestCase;

/**
 * Placeholder integration test, replaced once Phase 1 wires up real hooks.
 */
final class ExampleTest extends WP_UnitTestCase {

	public function test_plugin_loads_in_a_real_wordpress_install(): void {
		$this->assertTrue( did_action( 'nxc_loaded' ) > 0 );
	}
}
