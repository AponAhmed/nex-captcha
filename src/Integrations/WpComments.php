<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Integrations;

use Bitflw\NexCaptcha\Admin\Settings;
use Bitflw\NexCaptcha\Core\Assets;
use Bitflw\NexCaptcha\Support\Template;
use Bitflw\NexCaptcha\Verification\Verifier;

defined( 'ABSPATH' ) || exit;

/**
 * Protects the core comment form.
 *
 * Bypasses roles from the configured bypass_roles setting (default
 * ["administrator"], per docs/PLAN.md section 4.1) via the shared
 * AbstractIntegration::role_bypassed() -- meaningful here since a
 * logged-in commenter is completely normal, unlike login/register/
 * lostpassword where nothing is authenticated yet at submission time.
 */
class WpComments extends AbstractIntegration {

	private const FORM_ID = 'comments';

	public function is_available(): bool {
		return (bool) ( Settings::get( 'forms' )[ self::FORM_ID ] ?? true );
	}

	public function register(): void {
		add_action( 'comment_form_after_fields', array( $this, 'render' ) );
		add_action( 'comment_form_logged_in_after', array( $this, 'render' ) );
		add_filter( 'preprocess_comment', array( $this, 'validate' ) );
	}

	public function render(): void {
		if ( $this->role_bypassed() ) {
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
		if ( $this->role_bypassed() || $this->ip_allowlisted() || ! $this->is_relevant_submission() ) {
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
}
