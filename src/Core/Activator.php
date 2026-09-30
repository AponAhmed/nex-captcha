<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Core;

use Bitflw\NexCaptcha\Admin\Settings;
use Bitflw\NexCaptcha\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation: generates the HMAC secret and default options.
 *
 * Crypto::get_secret() and Settings::all() both already self-heal on
 * first read (Phase 1/2), so nothing here is strictly load-bearing --
 * this just makes activation eagerly create them, matching
 * docs/PLAN.md's Phase 2 checklist, rather than leaving it to whichever
 * request happens to need them first.
 */
class Activator {

	public static function activate(): void {
		Crypto::get_secret();

		if ( false === get_option( 'nxc_settings' ) ) {
			update_option( 'nxc_settings', Settings::defaults() );
		}

		update_option( 'nxc_db_version', 1 );
	}
}
