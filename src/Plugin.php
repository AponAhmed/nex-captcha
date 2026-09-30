<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha;

use Bitflw\NexCaptcha\Rest\ChallengeController;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin: loads settings, registers the REST route and hooks,
 * and lets integrations register themselves.
 */
final class Plugin {

	public static function boot(): void {
		ChallengeController::register();

		do_action( 'nxc_loaded' );
	}
}
