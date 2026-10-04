<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Challenge;

use Bitflw\NexCaptcha\Challenge\SliderChallenge;
use Bitflw\NexCaptcha\Support\Crypto;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;
use ReflectionClass;
use ReflectionProperty;

final class SliderChallengeTest extends TestCase {

	private const TTL  = 600;
	private const FORM = 'login';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->justReturn( base64_encode( str_repeat( 's', 32 ) ) );
		Functions\when( 'update_option' )->justReturn( true );
		$this->reset_secret_cache();
	}

	protected function tearDown(): void {
		$this->reset_secret_cache();
		parent::tearDown();
	}

	private function reset_secret_cache(): void {
		$property = new ReflectionProperty( Crypto::class, 'secret' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * The gap position is deliberately never part of the payload (see
	 * the class docblock); recovering it here the same way verify()
	 * itself does -- searching for the HMAC match -- is the only way a
	 * test can know what the "correct" submission even is.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function gap_xy( array $payload ): array {
		$ref    = new ReflectionClass( SliderChallenge::class );
		$method = $ref->getMethod( 'find_gap_xy' );
		$method->setAccessible( true );

		$gap = $method->invoke( null, $payload['salt'], $payload['signature'] );

		if ( null === $gap ) {
			$this->fail( 'Could not recover the gap position from the test challenge.' );
		}

		return $gap;
	}

	/**
	 * A plausible human-like drag: several points, strictly increasing
	 * time, meaningful movement on at least one axis -- satisfies
	 * check_trace().
	 */
	private function plausible_trace( int $end_x, int $end_y ): array {
		return array(
			array(
				'x' => 0,
				'y' => 0,
				't' => 0,
			),
			array(
				'x' => (int) ( $end_x / 2 ),
				'y' => (int) ( $end_y / 2 ),
				't' => 80,
			),
			array(
				'x' => $end_x,
				'y' => $end_y,
				't' => 200,
			),
		);
	}

	public function test_create_returns_the_documented_shape(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );

		$this->assertSame( 'slider', $payload['mode'] );
		$this->assertArrayHasKey( 'image', $payload );
		$this->assertArrayHasKey( 'piece', $payload );
		$this->assertArrayHasKey( 'salt', $payload );
		$this->assertArrayHasKey( 'signature', $payload );
		$this->assertStringStartsWith( 'data:image/png;base64,', $payload['image'] );
		$this->assertStringStartsWith( 'data:image/png;base64,', $payload['piece'] );
		$this->assertArrayNotHasKey( 'pieceY', $payload, 'The gap Y position must never be sent to the client either.' );
		$this->assertArrayNotHasKey( 'gapX', $payload, 'The gap X position must never be sent to the client.' );
		$this->assertArrayNotHasKey( 'gapY', $payload, 'The gap Y position must never be sent to the client.' );
	}

	public function test_a_correct_position_and_trace_verifies(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );
		list( $gap_x, $gap_y ) = $this->gap_xy( $payload );

		$submitted          = $payload;
		$submitted['x']     = $gap_x;
		$submitted['y']     = $gap_y;
		$submitted['trace'] = $this->plausible_trace( $gap_x, $gap_y );

		$this->assertNull( SliderChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_position_within_tolerance_on_both_axes_verifies(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );
		list( $gap_x, $gap_y ) = $this->gap_xy( $payload );

		$submitted          = $payload;
		$submitted['x']     = $gap_x + 3;
		$submitted['y']     = $gap_y - 3;
		$submitted['trace'] = $this->plausible_trace( $gap_x + 3, $gap_y - 3 );

		$this->assertNull( SliderChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_correct_x_but_y_outside_tolerance_fails_as_bad_position(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );
		list( $gap_x, $gap_y ) = $this->gap_xy( $payload );

		$submitted          = $payload;
		$submitted['x']     = $gap_x;
		$submitted['y']     = $gap_y + 20;
		$submitted['trace'] = $this->plausible_trace( $gap_x, $gap_y + 20 );

		$this->assertSame( 'bad_position', SliderChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_correct_y_but_x_outside_tolerance_fails_as_bad_position(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );
		list( $gap_x, $gap_y ) = $this->gap_xy( $payload );

		$submitted          = $payload;
		$submitted['x']     = $gap_x + 20;
		$submitted['y']     = $gap_y;
		$submitted['trace'] = $this->plausible_trace( $gap_x + 20, $gap_y );

		$this->assertSame( 'bad_position', SliderChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_tampered_salt_fails_as_bad_signature(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );
		list( $gap_x, $gap_y ) = $this->gap_xy( $payload );

		$submitted          = $payload;
		$submitted['x']     = $gap_x;
		$submitted['y']     = $gap_y;
		$submitted['trace'] = $this->plausible_trace( $gap_x, $gap_y );
		$submitted['salt']  = 'x' . substr( $submitted['salt'], 1 );

		$this->assertSame( 'bad_signature', SliderChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_tampered_signature_fails_as_bad_signature(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );
		list( $gap_x, $gap_y ) = $this->gap_xy( $payload );

		$submitted              = $payload;
		$submitted['x']         = $gap_x;
		$submitted['y']         = $gap_y;
		$submitted['trace']     = $this->plausible_trace( $gap_x, $gap_y );
		$submitted['signature'] = str_repeat( '0', strlen( $submitted['signature'] ) );

		$this->assertSame( 'bad_signature', SliderChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_missing_trace_fails_as_bad_trace(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );
		list( $gap_x, $gap_y ) = $this->gap_xy( $payload );

		$submitted      = $payload;
		$submitted['x'] = $gap_x;
		$submitted['y'] = $gap_y;

		$this->assertSame( 'bad_trace', SliderChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_single_point_trace_fails_as_bad_trace(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );
		list( $gap_x, $gap_y ) = $this->gap_xy( $payload );

		$submitted          = $payload;
		$submitted['x']     = $gap_x;
		$submitted['y']     = $gap_y;
		$submitted['trace'] = array(
			array(
				'x' => $gap_x,
				'y' => $gap_y,
				't' => 0,
			),
		);

		$this->assertSame( 'bad_trace', SliderChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_an_instant_trace_fails_as_bad_trace(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );
		list( $gap_x, $gap_y ) = $this->gap_xy( $payload );

		$submitted          = $payload;
		$submitted['x']     = $gap_x;
		$submitted['y']     = $gap_y;
		$submitted['trace'] = array(
			array(
				'x' => 0,
				'y' => 0,
				't' => 0,
			),
			array(
				'x' => (int) ( $gap_x / 2 ),
				'y' => (int) ( $gap_y / 2 ),
				't' => 1,
			),
			array(
				'x' => $gap_x,
				'y' => $gap_y,
				't' => 2,
			),
		);

		$this->assertSame( 'bad_trace', SliderChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_trace_with_no_movement_on_either_axis_fails_as_bad_trace(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );
		list( $gap_x, $gap_y ) = $this->gap_xy( $payload );

		$submitted          = $payload;
		$submitted['x']     = $gap_x;
		$submitted['y']     = $gap_y;
		$submitted['trace'] = array(
			array(
				'x' => $gap_x,
				'y' => $gap_y,
				't' => 0,
			),
			array(
				'x' => $gap_x,
				'y' => $gap_y,
				't' => 80,
			),
			array(
				'x' => $gap_x,
				'y' => $gap_y,
				't' => 200,
			),
		);

		$this->assertSame( 'bad_trace', SliderChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_trace_with_only_vertical_movement_still_verifies(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );
		list( $gap_x, $gap_y ) = $this->gap_xy( $payload );

		$submitted          = $payload;
		$submitted['x']     = $gap_x;
		$submitted['y']     = $gap_y;
		$submitted['trace'] = array(
			array(
				'x' => $gap_x,
				'y' => 0,
				't' => 0,
			),
			array(
				'x' => $gap_x,
				'y' => (int) ( $gap_y / 2 ),
				't' => 80,
			),
			array(
				'x' => $gap_x,
				'y' => $gap_y,
				't' => 200,
			),
		);

		$this->assertNull( SliderChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_an_expired_challenge_fails(): void {
		$payload = SliderChallenge::create( self::FORM, 0, -1 );
		list( $gap_x, $gap_y ) = $this->gap_xy( $payload );

		$submitted          = $payload;
		$submitted['x']     = $gap_x;
		$submitted['y']     = $gap_y;
		$submitted['trace'] = $this->plausible_trace( $gap_x, $gap_y );

		$this->assertSame( 'expired', SliderChallenge::verify( $submitted, self::FORM ) );
	}

	public function test_a_token_for_a_different_form_fails(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );
		list( $gap_x, $gap_y ) = $this->gap_xy( $payload );

		$submitted          = $payload;
		$submitted['x']     = $gap_x;
		$submitted['y']     = $gap_y;
		$submitted['trace'] = $this->plausible_trace( $gap_x, $gap_y );

		$this->assertSame( 'wrong_form', SliderChallenge::verify( $submitted, 'comments' ) );
	}

	public function test_expiry_matches_the_salts_expiry(): void {
		$payload = SliderChallenge::create( self::FORM, 0, self::TTL );

		$this->assertGreaterThan( time(), SliderChallenge::expiry( $payload ) );
		$this->assertLessThanOrEqual( time() + self::TTL, SliderChallenge::expiry( $payload ) );
	}
}
