<?php
/**
 * Bootstrap for the unit test suite (Brain Monkey — no WordPress required).
 *
 * @package Bitflw\NexCaptcha\Tests
 */

// src/ files guard against direct access with defined('ABSPATH') || exit.
// There's no real WordPress here, so give them a value to find.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Minimal WP_Error stand-in: Brain Monkey mocks WP *functions*, not core
// classes, and real WordPress isn't loaded for unit tests.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}
