<?php
/**
 * Widget container markup: .nxc-widget div, hidden nxc_token input and
 * an ARIA live status region. Honeypot field and the noscript
 * math-challenge fallback are added in Phase 3.
 *
 * @var string $form_id Form identifier the widget protects.
 *
 * @package Bitflw\NexCaptcha
 */

defined( 'ABSPATH' ) || exit;

$nxc_field_id = 'nxc_token_' . preg_replace( '/[^a-z0-9_-]/', '_', $form_id );
?>
<div class="nxc-widget" data-form="<?php echo esc_attr( $form_id ); ?>" data-rest-url="<?php echo esc_url( rest_url( 'nexcaptcha/v1/' ) ); ?>">
	<input type="hidden" name="nxc_token" id="<?php echo esc_attr( $nxc_field_id ); ?>" value="" />
	<div class="nxc-widget__status" role="status" aria-live="polite">
		<?php esc_html_e( 'Verifying you are human…', 'nex-captcha' ); ?>
	</div>
</div>
