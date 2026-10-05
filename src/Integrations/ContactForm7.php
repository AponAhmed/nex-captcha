<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Integrations;

use Bitflw\NexCaptcha\Core\Assets;
use Bitflw\NexCaptcha\Support\Template;
use Bitflw\NexCaptcha\Verification\Verifier;
use WPCF7_ContactForm;
use WPCF7_FormTag;
use WPCF7_Submission;
use WPCF7_TagGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Contact Form 7 integration via the [nexcaptcha] form tag.
 *
 * Unlike CF7's own text/acceptance field types, an invisible/background
 * anti-bot widget has no named value to validate inline -- there's
 * nothing to point a per-field error at. CF7's own reCAPTCHA and
 * Cloudflare Turnstile modules (modules/recaptcha, modules/turnstile)
 * both hook the single wpcf7_spam filter instead of wpcf7_validate_*,
 * so this does too: returning true marks the whole submission spam,
 * which CF7 surfaces with its own generic rejection message.
 *
 * No Settings::FORM_IDS entry and no Forms-panel toggle -- CF7's own
 * opt-in mechanism already *is* the [nexcaptcha] tag itself, placed
 * per-form by whoever edits that form's template, same reasoning as
 * the shortcode/nxc_verify() "Generic" integration having no settings
 * row of its own.
 */
class ContactForm7 extends AbstractIntegration {

	private const FORM_PREFIX = 'cf7:';

	public function is_available(): bool {
		return class_exists( 'WPCF7_ContactForm' );
	}

	public function register(): void {
		add_action( 'wpcf7_init', array( $this, 'add_form_tag' ) );
		add_filter( 'wpcf7_spam', array( $this, 'verify' ), 9, 2 );
		add_action( 'wpcf7_admin_init', array( $this, 'add_tag_generator' ), 35 );
	}

	public function add_form_tag(): void {
		wpcf7_add_form_tag(
			'nexcaptcha',
			array( $this, 'render_tag' ),
			array( 'display-block' => true )
		);
	}

	/**
	 * @param WPCF7_FormTag $tag Unused: the widget takes no name or options.
	 */
	public function render_tag( $tag ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- part of the form-tag handler signature CF7 itself calls (wpcf7_add_form_tag()).
		$contact_form = WPCF7_ContactForm::get_current();

		if ( ! $contact_form || $this->role_bypassed() ) {
			return '';
		}

		Assets::enqueue_widget();

		return Template::widget( self::form_id( $contact_form->id() ) );
	}

	/**
	 * @param bool $spam
	 * @param WPCF7_Submission $submission
	 */
	public function verify( $spam, $submission ): bool {
		if ( $spam || $this->role_bypassed() || $this->ip_allowlisted() ) {
			return (bool) $spam;
		}

		$form_id = self::form_id( $submission->get_contact_form()->id() );
		$result  = Verifier::verify( $form_id, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the token check is the nonce equivalent here, same as every other integration.

		if ( ! $result->ok ) {
			$submission->add_spam_log(
				array(
					'agent'  => 'nexcaptcha',
					'reason' => (string) $result->reason,
				)
			);
		}

		return ! $result->ok;
	}

	public function add_tag_generator(): void {
		if ( ! class_exists( 'WPCF7_TagGenerator' ) ) {
			return;
		}

		WPCF7_TagGenerator::get_instance()->add(
			'nexcaptcha',
			__( 'NexCaptcha', 'nex-captcha' ),
			array( $this, 'render_tag_generator' ),
			array( 'version' => '2' )
		);
	}

	/**
	 * No configurable fields -- the tag takes no name or options, so
	 * there's nothing for a visitor to fill in. The hidden basetype
	 * input is still required even so: admin/includes/js/index.js's
	 * tag-generator (v2) panel init always calls its own internal
	 * h()/g() once on open to compute the "tag" preview field and
	 * enable the Insert Tag button, and h() bails out (leaving the
	 * preview empty and the button permanently disabled) unless it
	 * finds a [data-tag-part="basetype"] element -- confirmed by
	 * reading that file directly, since CF7's own PHP-side docs don't
	 * spell this contract out anywhere.
	 */
	public function render_tag_generator(): void {
		?>
		<div class="description-box">
			<p>
				<?php esc_html_e( 'Generates a form-tag for a NexCaptcha widget. It takes no options -- place it anywhere in the form template.', 'nex-captcha' ); ?>
			</p>
		</div>
		<div class="control-box">
			<input type="hidden" data-tag-part="basetype" value="nexcaptcha" />
		</div>
		<div class="insert-box">
			<input type="text" class="code selectable" readonly="readonly" data-tag-part="tag" aria-label="<?php esc_attr_e( 'The form-tag to be inserted into the form template', 'nex-captcha' ); ?>" />
			<button type="button" class="button button-primary" data-taggen="insert-tag" disabled="disabled">
				<?php esc_html_e( 'Insert Tag', 'nex-captcha' ); ?>
			</button>
		</div>
		<?php
	}

	private static function form_id( int $cf7_id ): string {
		return self::FORM_PREFIX . $cf7_id;
	}
}
