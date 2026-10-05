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

		$sanitized = Settings::sanitize( array( 'difficulty' => 'not-a-real-difficulty' ) );

		$this->assertSame( 'high', $sanitized['difficulty'] );
	}

	public function test_sanitize_accepts_a_valid_difficulty(): void {
		Functions\expect( 'get_option' )->once()->andReturn( array() );

		$sanitized = Settings::sanitize( array( 'difficulty' => 'low' ) );

		$this->assertSame( 'low', $sanitized['difficulty'] );
	}

	public function test_sanitize_coerces_form_toggles_to_booleans(): void {
		Functions\expect( 'get_option' )->once()->andReturn( array() );

		$sanitized = Settings::sanitize(
			array(
				'forms' => array(
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
	 * The settings screen now renders General/Forms/Security as stacked
	 * panels inside ONE shared <form> (not one per tab, which used to
	 * silently wipe every OTHER section's fields on save -- see
	 * Settings::sanitize()'s own docblock for that history). This is
	 * the regression test for the assumption that fix now relies on:
	 * every real submit carries every section's fields together, and
	 * sanitize() must handle all of them correctly in one pass.
	 */
	public function test_sanitize_handles_every_sections_fields_together_in_one_submit(): void {
		Functions\expect( 'get_option' )->once()->andReturn( array() );

		$sanitized = Settings::sanitize(
			array(
				'difficulty'        => 'high',
				'custom_maxnumber'  => 50000,
				'escalation'        => '1',
				'forms'             => array(
					'login'    => '1',
					'register' => '1',
				),
				'allowlist'         => "203.0.113.5\n203.0.113.0/24",
				'bypass_roles'      => array( 'Administrator', 'Editor' ),
				'rate_limit'        => array(
					'max'    => 20,
					'window' => 300,
				),
				'time_trap_seconds' => 3,
			)
		);

		$this->assertSame( 'high', $sanitized['difficulty'] );
		$this->assertSame( 50000, $sanitized['custom_maxnumber'] );
		$this->assertTrue( $sanitized['escalation'] );
		$this->assertTrue( $sanitized['forms']['login'] );
		$this->assertTrue( $sanitized['forms']['register'] );
		$this->assertFalse( $sanitized['forms']['lostpassword'] );
		$this->assertSame( array( '203.0.113.5', '203.0.113.0/24' ), $sanitized['allowlist'] );
		$this->assertSame( array( 'administrator', 'editor' ), $sanitized['bypass_roles'] );
		$this->assertSame( 20, $sanitized['rate_limit']['max'] );
		$this->assertSame( 300, $sanitized['rate_limit']['window'] );
		$this->assertSame( 3, $sanitized['time_trap_seconds'] );
	}

	public function test_sanitize_filters_out_invalid_allowlist_entries(): void {
		Functions\expect( 'get_option' )->once()->andReturn( array() );

		$sanitized = Settings::sanitize(
			array( 'allowlist' => "203.0.113.5\nnot-an-ip\n203.0.113.0/24" )
		);

		$this->assertSame( array( '203.0.113.5', '203.0.113.0/24' ), $sanitized['allowlist'] );
	}

	public function test_sanitize_deduplicates_and_lowercases_bypass_roles(): void {
		Functions\expect( 'get_option' )->once()->andReturn( array() );

		$sanitized = Settings::sanitize(
			array( 'bypass_roles' => array( 'Administrator', 'administrator', 'Editor' ) )
		);

		$this->assertSame( array( 'administrator', 'editor' ), $sanitized['bypass_roles'] );
	}

	public function test_sanitize_falls_back_to_the_current_value_for_a_non_positive_rate_limit(): void {
		Functions\expect( 'get_option' )->once()->andReturn( array( 'rate_limit' => array( 'max' => 10, 'window' => 600 ) ) );

		$sanitized = Settings::sanitize(
			array(
				'rate_limit' => array(
					'max'    => -5,
					'window' => 'nope',
				),
			)
		);

		$this->assertSame( 10, $sanitized['rate_limit']['max'] );
		$this->assertSame( 600, $sanitized['rate_limit']['window'] );
	}

	/**
	 * Fields with no admin UI control yet (mode, theme, trusted_proxies,
	 * fail_mode, logging) must never be touched by a settings save, even
	 * though every save now processes the full $input unconditionally --
	 * see Settings::sanitize()'s own docblock.
	 */
	public function test_sanitize_never_touches_fields_with_no_admin_ui(): void {
		Functions\expect( 'get_option' )->once()->andReturn(
			array(
				'logging' => array(
					'enabled'        => true,
					'retention_days' => 30,
				),
			)
		);

		$sanitized = Settings::sanitize( array( 'difficulty' => 'low' ) );

		$this->assertTrue( $sanitized['logging']['enabled'] );
		$this->assertSame( 30, $sanitized['logging']['retention_days'] );
	}
}
