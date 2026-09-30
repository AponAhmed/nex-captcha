<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Rest;

use Bitflw\NexCaptcha\Challenge\PowChallenge;
use Bitflw\NexCaptcha\Support\Ip;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles GET /wp-json/nexcaptcha/v1/challenge.
 *
 * Difficulty is hardcoded to Balanced and rate limiting is a fixed stub
 * until Admin\Settings and Signals\RateLimiter exist (Phase 2/3).
 */
class ChallengeController {

	private const NAMESPACE = 'nexcaptcha/v1';
	private const ROUTE     = '/challenge';

	private const DEFAULT_MAXNUMBER = 100000;
	private const DEFAULT_TTL       = 600;

	private const RATE_LIMIT_MAX    = 20;
	private const RATE_LIMIT_WINDOW = 60;

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

		$payload = PowChallenge::create( $form, self::DEFAULT_MAXNUMBER, self::DEFAULT_TTL );

		return self::respond( $payload, 200 );
	}

	private static function respond( array $data, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Fixed stub: N requests per window per IP-hash. Replaced by
	 * Signals\RateLimiter (object-cache aware, configurable) in Phase 3.
	 */
	private static function is_rate_limited(): bool {
		$key   = 'nxc_rl_challenge_' . Ip::hash();
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return true;
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );

		return false;
	}
}
