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

	/**
	 * Whether the visitor's IP matches any of the given plain IPs or
	 * CIDR ranges (IPv4 or IPv6).
	 *
	 * @param string[] $entries
	 */
	public static function in_allowlist( array $entries ): bool {
		$address = self::address();

		if ( '' === $address ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( self::matches( $address, (string) $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether $entry is a plain IP or a valid CIDR range, for settings
	 * sanitization.
	 */
	public static function is_valid_allowlist_entry( string $entry ): bool {
		$entry = trim( $entry );

		if ( '' === $entry ) {
			return false;
		}

		if ( false === strpos( $entry, '/' ) ) {
			return false !== filter_var( $entry, FILTER_VALIDATE_IP );
		}

		list( $ip, $prefix ) = explode( '/', $entry, 2 );
		$binary              = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;

		return false !== $binary && ctype_digit( $prefix ) && (int) $prefix <= strlen( $binary ) * 8;
	}

	/**
	 * Byte-wise prefix match, so the same logic works for both IPv4 and
	 * IPv6 without the sign-extension pitfalls of ip2long()-based masking.
	 */
	private static function matches( string $address, string $entry ): bool {
		$entry = trim( $entry );

		if ( false === strpos( $entry, '/' ) ) {
			return $entry === $address;
		}

		list( $subnet, $prefix ) = explode( '/', $entry, 2 );

		if ( ! ctype_digit( $prefix ) ) {
			return false;
		}

		$address_bin = inet_pton( $address );
		$subnet_bin  = inet_pton( $subnet );

		if ( false === $address_bin || false === $subnet_bin || strlen( $address_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$prefix = (int) $prefix;
		$bytes  = intdiv( $prefix, 8 );
		$bits   = $prefix % 8;

		if ( $prefix > strlen( $address_bin ) * 8 ) {
			return false;
		}

		if ( $bytes > 0 && substr( $address_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $bits ) {
			return true;
		}

		$mask = chr( ( 0xFF << ( 8 - $bits ) ) & 0xFF );

		return ( $address_bin[ $bytes ] & $mask ) === ( $subnet_bin[ $bytes ] & $mask );
	}
}
