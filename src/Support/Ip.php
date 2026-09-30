<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Client IP detection and hashing.
 */
class Ip {

	/**
	 * The visitor's IP address. REMOTE_ADDR only for now; trusted-proxy
	 * header support is a Phase 3 Security-tab setting.
	 */
	public static function address(): string {
		return is_string( $_SERVER['REMOTE_ADDR'] ?? null ) ? $_SERVER['REMOTE_ADDR'] : '';
	}

	/**
	 * HMAC of the visitor's IP, safe to store and log.
	 */
	public static function hash(): string {
		return Crypto::hmac( self::address() );
	}
}
