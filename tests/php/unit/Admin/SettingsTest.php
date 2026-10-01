<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Admin;

use Bitflw\NexCaptcha\Admin\Settings;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// A simplified but behaviourally equivalent stand-in for WP
		// core's sanitize_key(): lowercase, strip anything but a-z0-9_-.
		Functions\when( 'sanitize_key' )->alias(
			static function ( string $key ): string {
				return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) ) ?? '';
			}
		);
	}

	public function test_defaults_match_the_documented_shape(): void {
		$defaults = Settings::defaults();

		$this->assertSame(
			array(
				'schema_version',
				'mode',
				'difficulty',
				'custom_maxnumber',
				'escalation',
				'forms',
				'theme',
				'time_trap_seconds',
				'rate_limit',
				'allowlist',
				'bypass_roles',
				'trusted_proxies',
				'fail_mode',
				'logging',
			),
			array_keys( $defaults )
		);
		$this->assertSame( array( 'administrator' ), $defaults['bypass_roles'] );
		$this->assertSame( array(), $defaults['allowlist'] );
	}

	public function test_all_merges_stored_values_over_defaults(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'nxc_settings', array() )
			->andReturn( array( 'difficulty' => 'high' ) );

		$all = Settings::all();

		$this->assertSame( 'high', $all['difficulty'] );
		// Untouched keys keep their default.
		$this->assertSame( 'invisible', $all['mode'] );
	}

	public function test_get_reads_a_single_key(): void {
		Functions\expect( 'get_option' )->once()->andReturn( array( 'difficulty' => 'low' ) );

		$this->assertSame( 'low', Settings::get( 'difficulty' ) );
	}

	public function test_sanitize_falls_back_to_the_current_value_for_an_invalid_difficulty(): void {
		Functions\expect( 'get_option' )->once()->andReturn( array( 'difficulty' => 'high' ) );

		$sanitized = Settings::sanitize(
			array(
				'_section'   => 'general',
				'difficulty' => 'not-a-real-difficulty',
			)
		);

		$this->assertSame( 'high', $sanitized['difficulty'] );
	}

	public function test_sanitize_accepts_a_valid_difficulty(): void {
		Functions\expect( 'get_option' )->once()->andReturn( array() );

		$sanitized = Settings::sanitize(
			array(
				'_section'   => 'general',
				'difficulty' => 'low',
			)
		);

		$this->assertSame( 'low', $sanitized['difficulty'] );
	}

	public function test_sanitize_coerces_form_toggles_to_booleans(): void {
		Functions\expect( 'get_option' )->once()->andReturn( array() );

		$sanitized = Settings::sanitize(
			array(
				'_section' => 'forms',
				'forms'    => array(
					'login'    => '1',
					'comments' => '',
				),
			)
		);

		$this->assertTrue( $sanitized['forms']['login'] );
		$this->assertFalse( $sanitized['forms']['comments'] );
		// Forms not present in the input default to disabled, not silently dropped.
		$this->assertArrayHasKey( 'register', $sanitized['forms'] );
		$this->assertFalse( $sanitized['forms']['register'] );
	}

	/**
	 * The multi-tab admin UI means a settings save for ANY OTHER tab
	 * must leave the Forms tab's own values exactly as they were --
	 * this is the regression test for the bug that originally forced
	 * this whole _section-gated design: saving the Forms tab used to
	 * silently wipe General/Security's fields back to empty/false, and
	 * vice versa.
	 */
	public function test_sanitize_leaves_other_sections_untouched(): void {
		Functions\expect( 'get_option' )->once()->andReturn(
			array(
				'escalation'   => true,
				'forms'        => array(
					'login'        => true,
					'register'     => true,
					'lostpassword' => true,
					'comments'     => true,
				),
				'bypass_roles' => array( 'administrator' ),
			)
		);

		// Submitting the Forms tab alone -- no escalation/bypass_roles
		// fields present at all, same as that tab's real <form>.
		$sanitized = Settings::sanitize(
			array(
				'_section' => 'forms',
				'forms'    => array( 'login' => '1' ),
			)
		);

		$this->assertTrue( $sanitized['escalation'] );
		$this->assertSame( array( 'administrator' ), $sanitized['bypass_roles'] );
	}

	public function test_sanitize_with_an_unrecognized_section_changes_nothing(): void {
		Functions\expect( 'get_option' )->once()->andReturn(
			array(
				'difficulty' => 'high',
				'forms'      => array( 'login' => true ),
			)
		);

		$sanitized = Settings::sanitize( array( 'difficulty' => 'low' ) );

		$this->assertSame( 'high', $sanitized['difficulty'] );
		$this->assertTrue( $sanitized['forms']['login'] );
	}

	public function test_sanitize_filters_out_invalid_allowlist_entries(): void {
		Functions\expect( 'get_option' )->once()->andReturn( array() );

		$sanitized = Settings::sanitize(
			array(
				'_section'  => 'security',
				'allowlist' => "203.0.113.5\nnot-an-ip\n203.0.113.0/24",
			)
		);

		$this->assertSame( array( '203.0.113.5', '203.0.113.0/24' ), $sanitized['allowlist'] );
	}

	public function test_sanitize_deduplicates_and_lowercases_bypass_roles(): void {
		Functions\expect( 'get_option' )->once()->andReturn( array() );

		$sanitized = Settings::sanitize(
			array(
				'_section'     => 'security',
				'bypass_roles' => array( 'Administrator', 'administrator', 'Editor' ),
			)
		);

		$this->assertSame( array( 'administrator', 'editor' ), $sanitized['bypass_roles'] );
	}

	public function test_sanitize_falls_back_to_the_current_value_for_a_non_positive_rate_limit(): void {
		Functions\expect( 'get_option' )->once()->andReturn( array( 'rate_limit' => array( 'max' => 10, 'window' => 600 ) ) );

		$sanitized = Settings::sanitize(
			array(
				'_section'   => 'security',
				'rate_limit' => array(
					'max'    => -5,
					'window' => 'nope',
				),
			)
		);

		$this->assertSame( 10, $sanitized['rate_limit']['max'] );
		$this->assertSame( 600, $sanitized['rate_limit']['window'] );
	}
}
