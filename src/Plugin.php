<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha;

use Bitflw\NexCaptcha\Integrations\IntegrationInterface;
use Bitflw\NexCaptcha\Integrations\WpComments;
use Bitflw\NexCaptcha\Integrations\WpLogin;
use Bitflw\NexCaptcha\Integrations\WpLostPassword;
use Bitflw\NexCaptcha\Integrations\WpRegister;
use Bitflw\NexCaptcha\Rest\ChallengeController;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin: loads settings, registers the REST route and hooks,
 * and lets integrations register themselves.
 */
final class Plugin {

	public static function boot(): void {
		ChallengeController::register();

		foreach ( self::integrations() as $integration ) {
			if ( $integration->is_available() ) {
				$integration->register();
			}
		}

		do_action( 'nxc_loaded' );
	}

	/**
	 * @return IntegrationInterface[]
	 */
	private static function integrations(): array {
		return apply_filters(
			'nxc_register_integrations',
			array(
				new WpLogin(),
				new WpRegister(),
				new WpLostPassword(),
				new WpComments(),
			)
		);
	}
}
