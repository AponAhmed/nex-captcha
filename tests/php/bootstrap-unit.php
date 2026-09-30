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
		/** @var array<string, string[]> */
		private array $errors = array();

		public function __construct( string $code = '', string $message = '' ) {
			if ( '' !== $code ) {
				$this->add( $code, $message );
			}
		}

		public function add( string $code, string $message ): void {
			$this->errors[ $code ][] = $message;
		}

		public function has_errors(): bool {
			return array() !== $this->errors;
		}

		public function get_error_code(): string {
			$codes = array_keys( $this->errors );

			return $codes[0] ?? '';
		}

		public function get_error_message(): string {
			$code = $this->get_error_code();

			return '' === $code ? '' : ( $this->errors[ $code ][0] ?? '' );
		}
	}
}
