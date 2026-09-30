<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Integrations;

use Bitflw\NexCaptcha\Challenge\PowChallenge;
use Bitflw\NexCaptcha\Integrations\WpComments;
use Bitflw\NexCaptcha\Support\Crypto;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionProperty;

final class WpCommentsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $settings_override = array();

	protected function setUp(): void {
		parent::setUp();
		$this->settings_override = array();

		Functions\when( 'get_option' )->alias(
			function ( string $key ) {
				if ( 'nxc_secret' === $key ) {
					return base64_encode( str_repeat( 's', 32 ) );
				}

				return 'nxc_settings' === $key ? $this->settings_override : false;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		$this->reset_secret_cache();
	}

	protected function tearDown(): void {
		unset( $_POST['nxc_token'], $_POST['nxc_ts'] );
		$this->reset_secret_cache();
		parent::tearDown();
	}

	private function reset_secret_cache(): void {
		$property = new ReflectionProperty( Crypto::class, 'secret' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	private function solved_token(): string {
		$payload = PowChallenge::create( 'comments', 2000, 600 );

		for ( $number = 0; $number <= $payload['maxnumber']; $number++ ) {
			if ( hash( 'sha256', $payload['salt'] . $number ) === $payload['challenge'] ) {
				$payload['number'] = $number;
				return base64_encode( (string) json_encode( $payload ) );
			}
		}

		$this->fail( 'Could not solve the test challenge.' );
	}

	public function test_is_available_reads_the_forms_setting(): void {
		$this->settings_override = array( 'forms' => array( 'comments' => false ) );

		$this->assertFalse( ( new WpComments() )->is_available() );
	}

	public function test_is_available_defaults_to_enabled(): void {
		$this->assertTrue( ( new WpComments() )->is_available() );
	}

	public function test_register_wires_the_documented_hooks(): void {
		Functions\expect( 'add_action' )->once()->with( 'comment_form_after_fields', Mockery::type( 'array' ) );
		Functions\expect( 'add_action' )->once()->with( 'comment_form_logged_in_after', Mockery::type( 'array' ) );
		Functions\expect( 'add_filter' )->once()->with( 'preprocess_comment', Mockery::type( 'array' ) );

		( new WpComments() )->register();

		$this->addToAssertionCount( 3 );
	}

	public function test_a_bypassed_role_skips_validation_untouched(): void {
		Functions\expect( 'is_user_logged_in' )->once()->andReturn( true );
		Functions\expect( 'wp_get_current_user' )->once()->andReturn( (object) array( 'roles' => array( 'administrator' ) ) );

		$commentdata = array( 'comment_content' => 'hello' );
		$result      = ( new WpComments() )->validate( $commentdata );

		$this->assertSame( $commentdata, $result );
	}

	public function test_a_valid_token_returns_the_commentdata_unchanged(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$_POST['nxc_token'] = $this->solved_token();
		$_POST['nxc_ts']    = time() - 10;

		$commentdata = array( 'comment_content' => 'hello' );
		$result      = ( new WpComments() )->validate( $commentdata );

		$this->assertSame( $commentdata, $result );
	}

	public function test_a_missing_token_calls_wp_die(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\expect( 'wp_die' )->once()->with(
			Mockery::type( 'string' ),
			Mockery::type( 'string' ),
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);

		( new WpComments() )->validate( array( 'comment_content' => 'hello' ) );

		$this->addToAssertionCount( 1 );
	}
}
