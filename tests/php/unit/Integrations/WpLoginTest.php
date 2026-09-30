<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Integrations;

use Bitflw\NexCaptcha\Challenge\PowChallenge;
use Bitflw\NexCaptcha\Integrations\WpLogin;
use Bitflw\NexCaptcha\Support\Crypto;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionProperty;
use WP_Error;

final class WpLoginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->justReturn( base64_encode( str_repeat( 's', 32 ) ) );
		Functions\when( 'update_option' )->justReturn( true );
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
		$payload = PowChallenge::create( 'login', 2000, 600 );

		for ( $number = 0; $number <= $payload['maxnumber']; $number++ ) {
			if ( hash( 'sha256', $payload['salt'] . $number ) === $payload['challenge'] ) {
				$payload['number'] = $number;
				return base64_encode( (string) json_encode( $payload ) );
			}
		}

		$this->fail( 'Could not solve the test challenge.' );
	}

	public function test_register_wires_the_documented_hooks(): void {
		Functions\expect( 'add_action' )->once()->with( 'login_form', Mockery::type( 'array' ) );
		Functions\expect( 'add_filter' )->once()->with( 'authenticate', Mockery::type( 'array' ), 30, 3 );

		( new WpLogin() )->register();

		// Mockery verifies the expectations above in tearDown(), but that
		// doesn't count as a PHPUnit assertion -- without this the test
		// is flagged risky under failOnRisky="true".
		$this->addToAssertionCount( 2 );
	}

	public function test_empty_credentials_pass_through_untouched(): void {
		$user = 'unchanged-placeholder';

		$result = ( new WpLogin() )->validate( $user, '', '' );

		$this->assertSame( $user, $result );
	}

	public function test_a_non_login_page_submission_passes_through_untouched(): void {
		// admin-ajax.php, WooCommerce's My Account form, etc. all fire
		// 'authenticate' too but never render our widget -- protecting
		// them is a later integration's job (WooCommerce is Phase 4).
		$GLOBALS['pagenow'] = 'admin-ajax.php';

		$user = 'unchanged-placeholder';

		$result = ( new WpLogin() )->validate( $user, 'someone', 'password' );

		$this->assertSame( $user, $result );
	}

	public function test_a_get_request_passes_through_untouched(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$user = 'unchanged-placeholder';

		$result = ( new WpLogin() )->validate( $user, 'someone', 'password' );

		$this->assertSame( $user, $result );
	}

	public function test_a_valid_token_passes_through_the_original_user_unchanged(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$_POST['nxc_token'] = $this->solved_token();

		$user = 'whatever-wp-core-already-decided';

		$result = ( new WpLogin() )->validate( $user, 'someone', 'password' );

		$this->assertSame( $user, $result );
	}

	public function test_a_missing_token_returns_a_wp_error_regardless_of_the_prior_user(): void {
		$result = ( new WpLogin() )->validate( 'a-perfectly-valid-user', 'someone', 'password' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nxc_failed', $result->get_error_code() );
	}
}
