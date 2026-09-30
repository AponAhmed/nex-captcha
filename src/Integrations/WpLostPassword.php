<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Integrations;

use Bitflw\NexCaptcha\Core\Assets;
use Bitflw\NexCaptcha\Support\Template;
use Bitflw\NexCaptcha\Verification\Verifier;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Protects the lost password form.
 */
class WpLostPassword extends AbstractIntegration {

	private const FORM_ID = 'lostpassword';

	public function is_available(): bool {
		return true;
	}

	public function register(): void {
		add_action( 'lostpassword_form', array( $this, 'render' ) );
		add_action( 'lostpassword_post', array( $this, 'validate' ) );
	}

	public function render(): void {
		Assets::enqueue_widget();
		echo Template::widget( self::FORM_ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template::widget() already escapes everything it outputs.
	}

	/**
	 * lostpassword_post is an action, not a filter: WP core passes its
	 * WP_Error by reference and checks $errors->has_errors() itself
	 * afterwards, so this adds to it rather than returning anything.
	 */
	public function validate( WP_Error &$errors ): void {
		if ( ! $this->is_lostpassword_submission() ) {
			return;
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
	}

	private function is_lostpassword_submission(): bool {
		return ! defined( 'XMLRPC_REQUEST' )
			&& ! defined( 'REST_REQUEST' )
			&& isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow']
			&& isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
	}
}
