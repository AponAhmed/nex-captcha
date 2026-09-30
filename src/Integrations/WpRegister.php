<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Integrations;

use Bitflw\NexCaptcha\Admin\Settings;
use Bitflw\NexCaptcha\Core\Assets;
use Bitflw\NexCaptcha\Support\Template;
use Bitflw\NexCaptcha\Verification\Verifier;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Protects the WordPress registration form.
 */
class WpRegister extends AbstractIntegration {

	private const FORM_ID = 'register';

	public function is_available(): bool {
		return (bool) ( Settings::get( 'forms' )[ self::FORM_ID ] ?? true );
	}

	public function register(): void {
		add_action( 'register_form', array( $this, 'render' ) );
		add_filter( 'registration_errors', array( $this, 'validate' ), 30, 3 );
	}

	public function render(): void {
		Assets::enqueue_widget();
		echo Template::widget( self::FORM_ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template::widget() already escapes everything it outputs.
	}

	/**
	 * registration_errors passes an existing WP_Error to add to (possibly
	 * already holding errors like "username taken"), not one to replace --
	 * unlike authenticate's contract.
	 */
	public function validate( WP_Error $errors, string $sanitized_user_login = '', string $user_email = '' ): WP_Error {
		if ( ! $this->is_registration_submission() ) {
			return $errors;
		}

		if ( $this->ip_allowlisted() ) {
			return $errors;
		}

		$result = Verifier::verify( self::FORM_ID, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the token check is the nonce equivalent here.

		if ( ! $result->ok ) {
			$message = apply_filters(
				'nxc_error_message',
				__( 'Verification failed. Please try again.', 'nex-captcha' ),
				$result->reason,
				self::FORM_ID
			);
			$errors->add( 'nxc_failed', $message );
		}

		return $errors;
	}

	/**
	 * Same reasoning as WpLogin::is_login_form_submission(): scope to a
	 * real wp-login.php?action=register POST so a third-party plugin's
	 * own registration flow (which never rendered our widget) isn't
	 * broken the moment this integration exists.
	 */
	private function is_registration_submission(): bool {
		return ! defined( 'XMLRPC_REQUEST' )
			&& ! defined( 'REST_REQUEST' )
			&& isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow']
			&& isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
	}
}
