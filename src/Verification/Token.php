<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Verification;

defined( 'ABSPATH' ) || exit;

/**
 * Decodes and validates the shape of a submitted challenge token.
 */
class Token {

	private const POW_KEYS = array( 'algorithm', 'challenge', 'number', 'salt', 'signature' );

	/**
	 * Decodes the base64-encoded nxc_token field into its payload array.
	 * Returns null if it's missing, malformed, or doesn't have the shape
	 * of a known challenge type.
	 */
	public static function decode( mixed $raw ): ?array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the submitted nxc_token field, not obfuscation.
		$json = base64_decode( $raw, true );

		if ( false === $json ) {
			return null;
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		return self::has_pow_shape( $data ) ? $data : null;
	}

	private static function has_pow_shape( array $data ): bool {
		foreach ( self::POW_KEYS as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				return false;
			}
		}

		return is_string( $data['algorithm'] )
			&& is_string( $data['challenge'] )
			&& ( is_int( $data['number'] ) || is_string( $data['number'] ) )
			&& is_string( $data['salt'] )
			&& is_string( $data['signature'] );
	}
}
