<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Core;

use Bitflw\NexCaptcha\Admin\Settings;
use Bitflw\NexCaptcha\Logging\Schema;
use Bitflw\NexCaptcha\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation: generates the HMAC secret, default
 * options, the events table and the daily prune cron.
 *
 * Crypto::get_secret() and Settings::all() both already self-heal on
 * first read (Phase 1/2), so neither is strictly load-bearing here --
 * this just makes activation eagerly create them, matching
 * docs/PLAN.md's Phase 2 checklist, rather than leaving it to whichever
 * request happens to need them first. Schema::install() is load-bearing
 * though: dbDelta() only runs when something calls it.
 */
class Activator {

	public static function activate(): void {
		Crypto::get_secret();

		if ( false === get_option( 'nxc_settings' ) ) {
			update_option( 'nxc_settings', Settings::defaults() );
		}

		Schema::install();

		if ( ! wp_next_scheduled( 'nxc_prune_events' ) ) {
			wp_schedule_event( time(), 'daily', 'nxc_prune_events' );
		}
	}
}
