<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Integrations;

use Bitflw\NexCaptcha\Challenge\PowChallenge;
use Bitflw\NexCaptcha\Integrations\WpLostPassword;
use Bitflw\NexCaptcha\Support\Crypto;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionProperty;
use WP_Error;

final class WpLostPasswordTest extends TestCase {

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
		$this->reset_secret_cache();

		$GLOBALS['pagenow']        = 'wp-login.php';
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['pagenow'], $_SERVER['REQUEST_METHOD'], $_POST['nxc_token'] );
		$this->reset_secret_cache();
		parent::tearDown();
	}

	private function reset_secret_cache(): void {
		$property = new ReflectionProperty( Crypto::class, 'secret' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	private function solved_token(): string {
		$payload = PowChallenge::create( 'lostpassword', 2000, 600 );

		for ( $number = 0; $number <= $payload['maxnumber']; $number++ ) {
			if ( hash( 'sha256', $payload['salt'] . $number ) === $payload['challenge'] ) {
				$payload['number'] = $number;
				return base64_encode( (string) json_encode( $payload ) );
			}
		}

		$this->fail( 'Could not solve the test challenge.' );
	}

	public function test_is_available_reads_the_forms_setting(): void {
		$this->settings_override = array( 'forms' => array( 'lostpassword' => false ) );

		$this->assertFalse( ( new WpLostPassword() )->is_available() );
	}

	public function test_is_available_defaults_to_enabled(): void {
		$this->assertTrue( ( new WpLostPassword() )->is_available() );
	}

	public function test_register_wires_the_documented_hooks(): void {
		Functions\expect( 'add_action' )->once()->with( 'lostpassword_form', Mockery::type( 'array' ) );
		Functions\expect( 'add_action' )->once()->with( 'lostpassword_post', Mockery::type( 'array' ) );

		( new WpLostPassword() )->register();

		$this->addToAssertionCount( 2 );
	}

	public function test_a_non_lostpassword_submission_adds_no_error(): void {
		$GLOBALS['pagenow'] = 'admin-ajax.php';

		$errors = new WP_Error();
		( new WpLostPassword() )->validate( $errors );

		$this->assertSame( '', $errors->get_error_code() );
	}

	public function test_a_valid_token_adds_no_error(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$_POST['nxc_token'] = $this->solved_token();

		$errors = new WP_Error();
		( new WpLostPassword() )->validate( $errors );

		$this->assertSame( '', $errors->get_error_code() );
	}

	public function test_a_missing_token_adds_an_nxc_failed_error(): void {
		$errors = new WP_Error();
		( new WpLostPassword() )->validate( $errors );

		$this->assertSame( 'nxc_failed', $errors->get_error_code() );
	}
}
