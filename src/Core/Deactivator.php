<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation: clears scheduled cron events.
 *
 * No cron is scheduled yet (Logging's prune job is Phase 3) --
 * wp_clear_scheduled_hook() is a safe no-op if nothing was ever
 * scheduled, so this is forward-compatible rather than dead code.
 */
class Deactivator {

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'nxc_prune_events' );
	}
}
