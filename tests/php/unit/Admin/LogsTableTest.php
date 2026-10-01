<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Admin;

use Bitflw\NexCaptcha\Admin\LogsTable;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Scoped to current_filters(): the rest of LogsTable extends
 * WP_List_Table, whose own lifecycle (prepare_items() called from a
 * real admin screen) needs WP machinery the unit suite doesn't have --
 * covered live instead (see this phase's plan).
 */
final class LogsTableTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_key' )->alias(
			static function ( string $key ): string {
				return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) ) ?? '';
			}
		);
		Functions\when( 'wp_unslash' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		unset( $_GET['nxc_form'], $_GET['nxc_result'] );
		parent::tearDown();
	}

	public function test_defaults_to_no_filters(): void {
		$this->assertSame( array( 'form' => '', 'result' => '' ), LogsTable::current_filters() );
	}

	public function test_reads_a_valid_form_filter(): void {
		$_GET['nxc_form'] = 'login';

		$this->assertSame( 'login', LogsTable::current_filters()['form'] );
	}

	public function test_accepts_any_sanitized_form_value(): void {
		// Forms aren't a fixed enum (a shortcode can use any id), unlike result.
		$_GET['nxc_form'] = 'cf7:123';

		$this->assertSame( 'cf7123', LogsTable::current_filters()['form'] );
	}

	public function test_reads_a_valid_result_filter(): void {
		$_GET['nxc_result'] = 'rate_limited';

		$this->assertSame( 'rate_limited', LogsTable::current_filters()['result'] );
	}

	public function test_rejects_a_result_value_outside_the_known_set(): void {
		$_GET['nxc_result'] = 'not-a-real-result';

		$this->assertSame( '', LogsTable::current_filters()['result'] );
	}
}
