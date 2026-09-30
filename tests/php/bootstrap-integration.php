<?php
/**
 * Bootstrap for the integration test suite (real WordPress, via wp-phpunit).
 *
 * @package Bitflw\NexCaptcha\Tests
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$wp_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';

require_once $wp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/nex-captcha.php';
	}
);

require $wp_tests_dir . '/includes/bootstrap.php';
