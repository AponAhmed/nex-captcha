<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Support;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC signing, random byte generation and constant-time comparison.
 */
class Crypto {

	private static ?string $secret = null;

	/**
	 * Loads the plugin's HMAC secret, generating and persisting one on
	 * first use. A NXC_SECRET constant (e.g. in wp-config.php) overrides
	 * the stored option.
	 */
	public static function get_secret(): string {
		if ( null !== self::$secret ) {
			return self::$secret;
		}

		if ( defined( 'NXC_SECRET' ) && '' !== NXC_SECRET ) {
			self::$secret = (string) NXC_SECRET;
			return self::$secret;
		}

		$stored = get_option( 'nxc_secret' );

		if ( is_string( $stored ) && '' !== $stored ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage encoding, not obfuscation.
			$decoded = base64_decode( $stored, true );
			if ( false !== $decoded ) {
				self::$secret = $decoded;
				return self::$secret;
			}
		}

		$generated = self::generate_secret();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding, not obfuscation.
		update_option( 'nxc_secret', base64_encode( $generated ), false );
		self::$secret = $generated;

		return self::$secret;
	}

	/**
	 * Generates a fresh 32-byte secret. Does not persist it.
	 */
	public static function generate_secret(): string {
		return random_bytes( 32 );
	}

	/**
	 * HMAC-SHA256 of $data, hex encoded, using the plugin secret unless
	 * a specific secret is given.
	 */
	public static function hmac( string $data, ?string $secret = null ): string {
		return hash_hmac( 'sha256', $data, $secret ?? self::get_secret() );
	}

	/**
	 * Random hex string of $bytes random bytes (so 2x as many hex chars).
	 */
	public static function random_hex( int $bytes = 32 ): string {
		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * Constant-time string comparison.
	 */
	public static function equals( string $known, string $user ): bool {
		return hash_equals( $known, $user );
	}
}
