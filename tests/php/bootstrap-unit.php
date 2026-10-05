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

// WP core time constant, used by Logging\EventLogger::prune().
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

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

// Minimal WP_List_Table stand-in so Admin\LogsTable (extends it) can be
// autoloaded at all. Real WordPress isn't loaded, so its own fallback
// (requiring wp-admin/includes/class-wp-list-table.php relative to the
// fake ABSPATH above) would fail -- nothing here is actually exercised
// by the unit suite, which only tests LogsTable's static helpers.
if ( ! class_exists( 'WP_List_Table' ) ) {
	class WP_List_Table {
		/**
		 * @param array<string, mixed> $args
		 */
		public function __construct( array $args = array() ) {}
	}
}

// Minimal Contact Form 7 stand-ins -- CF7 itself isn't loaded for unit
// tests, so Integrations\ContactForm7Test needs just enough of its real
// shape (get_current()/id() on WPCF7_ContactForm, get_contact_form()/
// add_spam_log() on WPCF7_Submission) to exercise verify()/render_tag()
// without the real plugin installed.
if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
	class WPCF7_ContactForm {
		private static ?self $current = null;

		public function __construct( private int $id ) {}

		public static function get_current(): ?self {
			return self::$current;
		}

		public static function set_current( ?self $form ): void {
			self::$current = $form;
		}

		public function id(): int {
			return $this->id;
		}
	}
}

if ( ! class_exists( 'WPCF7_Submission' ) ) {
	class WPCF7_Submission {
		/** @var array<int, array<string, mixed>> */
		public array $spam_log = array();

		public function __construct( private WPCF7_ContactForm $contact_form ) {}

		public function get_contact_form(): WPCF7_ContactForm {
			return $this->contact_form;
		}

		/**
		 * @param array<string, mixed>|string $data
		 */
		public function add_spam_log( $data = '' ): void {
			$this->spam_log[] = (array) $data;
		}
	}
}
