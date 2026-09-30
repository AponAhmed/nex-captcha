<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin: loads settings, registers the REST route and hooks,
 * and lets integrations register themselves.
 */
final class Plugin {

	public static function boot(): void {
		do_action( 'nxc_loaded' );
	}
}
