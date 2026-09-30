<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Rest;

use Bitflw\NexCaptcha\Admin\Settings;
use Bitflw\NexCaptcha\Challenge\PowChallenge;
use Bitflw\NexCaptcha\Signals\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles GET /wp-json/nexcaptcha/v1/challenge.
 *
 * Difficulty comes from Admin\Settings; rate limiting delegates to
 * Signals\RateLimiter.
 */
class ChallengeController {

	private const NAMESPACE = 'nexcaptcha/v1';
	private const ROUTE     = '/challenge';

	private const DEFAULT_TTL = 600;

	private const DIFFICULTY_MAXNUMBERS = array(
		'low'      => 20000,
		'balanced' => 100000,
		'high'     => 250000,
	);

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'form' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$form = (string) $request->get_param( 'form' );

		if ( 1 !== preg_match( '/^[a-z0-9:_-]+$/', $form ) ) {
			return self::respond( array( 'code' => 'invalid_form' ), 400 );
		}

		if ( self::is_rate_limited() ) {
			return self::respond( array( 'code' => 'rate_limited' ), 429 );
		}

		$maxnumber = (int) apply_filters( 'nxc_pow_maxnumber', self::maxnumber(), $form );
		$ttl       = (int) apply_filters( 'nxc_challenge_ttl', self::DEFAULT_TTL, $form );

		$payload = PowChallenge::create( $form, $maxnumber, $ttl );

		return self::respond( $payload, 200 );
	}

	private static function maxnumber(): int {
		$difficulty = (string) Settings::get( 'difficulty', 'balanced' );

		if ( 'custom' === $difficulty ) {
			return (int) Settings::get( 'custom_maxnumber', 100000 );
		}

		return self::DIFFICULTY_MAXNUMBERS[ $difficulty ] ?? self::DIFFICULTY_MAXNUMBERS['balanced'];
	}

	private static function respond( array $data, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	private static function is_rate_limited(): bool {
		$bucket = RateLimiter::challenge_bucket();

		if ( RateLimiter::is_limited( $bucket ) ) {
			return true;
		}

		RateLimiter::hit( $bucket );

		return false;
	}
}
