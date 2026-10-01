<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Logging;

use Bitflw\NexCaptcha\Logging\Schema;
use Bitflw\NexCaptcha\Tests\TestCase;

/**
 * install() itself needs dbDelta() and a real database -- out of scope
 * for the unit suite, covered live instead (see this phase's plan).
 */
final class SchemaTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_table_name_uses_the_wpdb_prefix(): void {
		$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );

		$this->assertSame( 'wp_nxc_events', Schema::table_name() );
	}

	public function test_table_name_respects_a_custom_prefix(): void {
		$GLOBALS['wpdb'] = (object) array( 'prefix' => 'custom_' );

		$this->assertSame( 'custom_nxc_events', Schema::table_name() );
	}
}
