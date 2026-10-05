<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and enqueues the frontend widget and admin scripts/styles.
 */
class Assets {

	/**
	 * Enqueues the widget script and its stylesheet. Called by an
	 * integration's render method, so assets load only on pages that
	 * actually render a protected form.
	 */
	public static function enqueue_widget(): void {
		wp_enqueue_script(
			'nxc-widget',
			NXC_URL . 'assets/build/widget.js',
			array(),
			NXC_VERSION,
			true
		);

		wp_enqueue_style(
			'nxc-widget',
			NXC_URL . 'assets/src/widget.css',
			array(),
			NXC_VERSION
		);
	}

	/**
	 * Enqueues the Settings -> NexCaptcha screen's own script and
	 * stylesheet. Admin\SettingsPage calls this only on its own screen
	 * (checked via the admin_enqueue_scripts hook suffix), so neither
	 * ever loads elsewhere in wp-admin.
	 */
	public static function enqueue_admin(): void {
		wp_enqueue_script(
			'nxc-admin',
			NXC_URL . 'assets/build/admin.js',
			array(),
			NXC_VERSION,
			true
		);

		wp_enqueue_style(
			'nxc-admin',
			NXC_URL . 'assets/src/admin.css',
			array(),
			NXC_VERSION
		);
	}
}
