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
 * Protects wp-login.php's login form.
 *
 * Fail-closed by default (per docs/PLAN.md section 3.4): any real
 * wp-login.php submission without a valid token is rejected, regardless
 * of whether the credentials themselves would have been correct.
 */
class WpLogin extends AbstractIntegration {

	private const FORM_ID = 'login';

	public function is_available(): bool {
		return (bool) ( Settings::get( 'forms' )[ self::FORM_ID ] ?? true );
	}

	public function register(): void {
		add_action( 'login_form', array( $this, 'render' ) );
		add_filter( 'authenticate', array( $this, 'validate' ), 30, 3 );
	}

	public function render(): void {
		Assets::enqueue_widget();
		echo Template::widget( self::FORM_ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template::widget() already escapes everything it outputs.
	}

	/**
	 * @param mixed $user The user (or WP_Error) an earlier authenticate
	 *                     callback has already produced.
	 */
	public function validate( $user, string $username = '', string $password = '' ) {
		if ( '' === $username && '' === $password ) {
			return $user;
		}

		if ( ! $this->is_login_form_submission() ) {
			return $user;
		}

		if ( $this->ip_allowlisted() ) {
			return $user;
		}

		$result = Verifier::verify( self::FORM_ID, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this REST-backed token check is the nonce equivalent here; the login form has no WP nonce of its own.

		if ( $result->ok ) {
			return $user;
		}

		$message = apply_filters(
			'nxc_error_message',
			__( 'Verification failed. Please try again.', 'nex-captcha' ),
			$result->reason,
			self::FORM_ID
		);

		return new WP_Error( 'nxc_failed', $message );
	}

	/**
	 * True only for a real wp-login.php form POST. Without this check,
	 * WooCommerce's My Account login (which also fires 'authenticate'
	 * but never renders our widget) would get rejected the moment this
	 * integration exists -- that form isn't ours to protect until the
	 * WooCommerce integration (Phase 4) exists.
	 */
	private function is_login_form_submission(): bool {
		return ! defined( 'XMLRPC_REQUEST' )
			&& ! defined( 'REST_REQUEST' )
			&& isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow']
			&& isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
	}
}
