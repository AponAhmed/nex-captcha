<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Integrations;

use Bitflw\NexCaptcha\Challenge\PowChallenge;
use Bitflw\NexCaptcha\Integrations\ContactForm7;
use Bitflw\NexCaptcha\Support\Crypto;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionProperty;
use WPCF7_ContactForm;
use WPCF7_Submission;

final class ContactForm7Test extends TestCase {

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
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		$this->reset_secret_cache();
	}

	protected function tearDown(): void {
		unset( $_POST['nxc_token'], $_POST['nxc_ts'], $_POST['nxc_interacted'] );
		WPCF7_ContactForm::set_current( null );
		$this->reset_secret_cache();
		parent::tearDown();
	}

	private function reset_secret_cache(): void {
		$property = new ReflectionProperty( Crypto::class, 'secret' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	private function solved_token( string $form_id ): string {
		$payload = PowChallenge::create( $form_id, 2000, 600 );

		for ( $number = 0; $number <= $payload['maxnumber']; $number++ ) {
			if ( hash( 'sha256', $payload['salt'] . $number ) === $payload['challenge'] ) {
				$payload['number'] = $number;
				return base64_encode( (string) json_encode( $payload ) );
			}
		}

		$this->fail( 'Could not solve the test challenge.' );
	}

	private function submission_for( int $cf7_id ): WPCF7_Submission {
		return new WPCF7_Submission( new WPCF7_ContactForm( $cf7_id ) );
	}

	public function test_is_available_true_when_cf7_is_installed(): void {
		$this->assertTrue( ( new ContactForm7() )->is_available() );
	}

	public function test_register_wires_the_documented_hooks(): void {
		Functions\expect( 'add_action' )->once()->with( 'wpcf7_init', Mockery::type( 'array' ) );
		Functions\expect( 'add_filter' )->once()->with( 'wpcf7_spam', Mockery::type( 'array' ), 9, 2 );
		Functions\expect( 'add_action' )->once()->with( 'wpcf7_admin_init', Mockery::type( 'array' ), 35 );

		( new ContactForm7() )->register();

		$this->addToAssertionCount( 3 );
	}

	public function test_verify_returns_existing_spam_unchanged_without_rechecking(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$submission = $this->submission_for( 1 );
		$result     = ( new ContactForm7() )->verify( true, $submission );

		$this->assertTrue( $result );
		$this->assertSame( array(), $submission->spam_log );
	}

	public function test_a_bypassed_role_is_never_marked_spam(): void {
		Functions\expect( 'is_user_logged_in' )->once()->andReturn( true );
		Functions\expect( 'wp_get_current_user' )->once()->andReturn( (object) array( 'roles' => array( 'administrator' ) ) );

		$submission = $this->submission_for( 1 );
		$result     = ( new ContactForm7() )->verify( false, $submission );

		$this->assertFalse( $result );
	}

	public function test_a_valid_token_is_not_marked_spam(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$_POST['nxc_token']      = $this->solved_token( 'cf7:42' );
		$_POST['nxc_ts']         = time() - 10;
		$_POST['nxc_interacted'] = '1';

		$submission = $this->submission_for( 42 );
		$result     = ( new ContactForm7() )->verify( false, $submission );

		$this->assertFalse( $result );
		$this->assertSame( array(), $submission->spam_log );
	}

	public function test_a_missing_token_is_marked_spam_and_logged(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$submission = $this->submission_for( 1 );
		$result     = ( new ContactForm7() )->verify( false, $submission );

		$this->assertTrue( $result );
		$this->assertCount( 1, $submission->spam_log );
		$this->assertSame( 'nexcaptcha', $submission->spam_log[0]['agent'] );
	}

	public function test_a_token_solved_for_a_different_cf7_form_is_rejected(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		// Solved for form 1, submitted against form 2 -- the per-form
		// identifier (cf7:<id>) is the whole point of form_id(), so a
		// token from one CF7 form must not verify against another.
		$_POST['nxc_token']      = $this->solved_token( 'cf7:1' );
		$_POST['nxc_ts']         = time() - 10;
		$_POST['nxc_interacted'] = '1';

		$submission = $this->submission_for( 2 );
		$result     = ( new ContactForm7() )->verify( false, $submission );

		$this->assertTrue( $result );
	}

	public function test_render_tag_returns_empty_string_with_no_current_contact_form(): void {
		WPCF7_ContactForm::set_current( null );

		$this->assertSame( '', ( new ContactForm7() )->render_tag( null ) );
	}
}
