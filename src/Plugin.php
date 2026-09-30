<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha;

use Bitflw\NexCaptcha\Admin\SettingsPage;
use Bitflw\NexCaptcha\Integrations\IntegrationInterface;
use Bitflw\NexCaptcha\Integrations\WpComments;
use Bitflw\NexCaptcha\Integrations\WpLogin;
use Bitflw\NexCaptcha\Integrations\WpLostPassword;
use Bitflw\NexCaptcha\Integrations\WpRegister;
use Bitflw\NexCaptcha\Rest\ChallengeController;
use Bitflw\NexCaptcha\Signals\RiskScorer;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin: loads settings, registers the REST route and hooks,
 * and lets integrations register themselves.
 */
final class Plugin {

	public static function boot(): void {
		ChallengeController::register();

		add_filter( 'nxc_pow_maxnumber', array( RiskScorer::class, 'adjust_maxnumber' ), 10, 2 );

		if ( is_admin() ) {
			SettingsPage::register();
		}

		add_shortcode( 'nexcaptcha', array( self::class, 'render_shortcode' ) );

		foreach ( self::integrations() as $integration ) {
			if ( $integration->is_available() ) {
				$integration->register();
			}
		}

		do_action( 'nxc_loaded' );
	}

	/**
	 * @param array<string, mixed>|string $atts
	 */
	public static function render_shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'form' => 'custom' ), $atts );

		return nxc_render( (string) $atts['form'] );
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
