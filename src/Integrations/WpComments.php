<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Integrations;

use Bitflw\NexCaptcha\Core\Assets;
use Bitflw\NexCaptcha\Support\Template;
use Bitflw\NexCaptcha\Verification\Verifier;

defined( 'ABSPATH' ) || exit;

/**
 * Protects the core comment form.
 *
 * Bypasses administrators, matching docs/PLAN.md section 4.1's default
 * settings shape ("bypass_roles": ["administrator"]) -- this is the
 * documented *default*, not a speculative feature, just not yet
 * configurable since Admin\Settings doesn't exist.
 */
class WpComments extends AbstractIntegration {

	private const FORM_ID = 'comments';

	public function is_available(): bool {
		return true;
	}

	public function register(): void {
		add_action( 'comment_form_after_fields', array( $this, 'render' ) );
		add_action( 'comment_form_logged_in_after', array( $this, 'render' ) );
		add_filter( 'preprocess_comment', array( $this, 'validate' ) );
	}

	public function render(): void {
		if ( $this->bypassed() ) {
			return;
		}

		Assets::enqueue_widget();
		echo Template::widget( self::FORM_ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template::widget() already escapes everything it outputs.
	}

	/**
	 * @param array<string, mixed> $commentdata
	 * @return array<string, mixed>
	 */
	public function validate( array $commentdata ): array {
		if ( $this->bypassed() || ! $this->is_relevant_submission() ) {
			return $commentdata;
		}

		$result = Verifier::verify( self::FORM_ID, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the token check is the nonce equivalent here.

		if ( ! $result->ok ) {
			$message = apply_filters(
				'nxc_error_message',
				__( 'Verification failed. Please try again.', 'nex-captcha' ),
				$result->reason,
				self::FORM_ID
			);

			wp_die(
				esc_html( $message ),
				esc_html__( 'Comment Submission Failure', 'nex-captcha' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		return $commentdata;
	}

	/**
	 * preprocess_comment also fires for REST API / XML-RPC comment
	 * creation, which never rendered our widget -- excluded so this
	 * integration doesn't break headless clients it doesn't claim to
	 * protect.
	 */
	private function is_relevant_submission(): bool {
		return ! defined( 'REST_REQUEST' ) && ! defined( 'XMLRPC_REQUEST' );
	}

	private function bypassed(): bool {
		// manage_options, not the 'administrator' role name: capabilities
		// are the robust check WPCS wants (roles can be renamed), and
		// manage_options is WP's own standard "is this an admin" test.
		return current_user_can( 'manage_options' );
	}
}
