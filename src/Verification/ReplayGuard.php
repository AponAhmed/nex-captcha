<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Verification;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks redeemed challenge IDs so each token can be used only once.
 *
 * Backed by the Transients API, which already uses a persistent object
 * cache instead of the options table when one is available.
 */
class ReplayGuard {

	public static function is_used( string $challenge ): bool {
		return false !== get_transient( self::key( $challenge ) );
	}

	public static function mark_used( string $challenge, int $ttl ): void {
		set_transient( self::key( $challenge ), 1, max( 0, $ttl ) );
	}

	private static function key( string $challenge ): string {
		return 'nxc_used_' . hash( 'sha256', $challenge );
	}
}
