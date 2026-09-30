<?php
/**
 * Plugin Name:       NexCaptcha
 * Plugin URI:        https://nexcaptcha.bitflw.com
 * Description:       Self-hosted, privacy-first CAPTCHA. Invisible proof-of-work by default, with slider and math fallbacks. No external calls, no cookies.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Bitflw
 * Author URI:        https://bitflw.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nex-captcha
 * Domain Path:       /languages
 *
 * @package Bitflw\NexCaptcha
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || die;

define( 'NXC_VERSION', '0.1.0' );
define( 'NXC_FILE', __FILE__ );
define( 'NXC_PATH', plugin_dir_path( __FILE__ ) );
define( 'NXC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bail with an admin notice instead of a fatal error when requirements
 * or the Composer autoloader aren't met.
 */
function nxc_requirements_notice( string $message ): void {
	add_action(
		'admin_notices',
		static function () use ( $message ): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	);
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	nxc_requirements_notice(
		sprintf(
			/* translators: %s: current PHP version */
			__( 'NexCaptcha requires PHP 8.0 or higher. You are running %s.', 'nex-captcha' ),
			PHP_VERSION
		)
	);
	return;
}

if ( version_compare( $GLOBALS['wp_version'], '6.4', '<' ) ) {
	nxc_requirements_notice(
		__( 'NexCaptcha requires WordPress 6.4 or higher.', 'nex-captcha' )
	);
	return;
}

if ( ! file_exists( NXC_PATH . 'vendor/autoload.php' ) ) {
	nxc_requirements_notice(
		__( 'NexCaptcha is missing its Composer dependencies. Run "composer install" in the plugin directory.', 'nex-captcha' )
	);
	return;
}

require_once NXC_PATH . 'vendor/autoload.php';

// Not autoloaded via Composer's "files" mechanism on purpose: that runs
// immediately whenever anything requires vendor/autoload.php --
// including PHPUnit's own CLI bootstrap, before ABSPATH is ever
// defined, which silently kills the whole process at this file's own
// guard clause. A plain require here only ever runs in a real WP request.
require_once NXC_PATH . 'src/functions.php';

// Must be called from the main plugin file directly, not from a
// plugins_loaded callback -- WP only recognizes these hooks when
// they're registered while this file is first included.
register_activation_hook( __FILE__, array( \Bitflw\NexCaptcha\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Bitflw\NexCaptcha\Core\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\Bitflw\NexCaptcha\Plugin::boot();
	}
);
