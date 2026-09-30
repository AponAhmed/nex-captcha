<?php
/**
 * Widget container markup: .nxc-widget div, hidden nxc_token input, an
 * ARIA live status region, a honeypot field (Signals\Honeypot) and a
 * render-time timestamp (Signals\TimeTrap). The noscript math-challenge
 * fallback is added alongside Challenge\MathChallenge in a later phase.
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
	<input type="hidden" name="nxc_ts" value="<?php echo esc_attr( (string) time() ); ?>" />
	<div class="nxc-widget__hp" aria-hidden="true">
		<label for="<?php echo esc_attr( $nxc_field_id ); ?>_hp"><?php esc_html_e( 'Leave this field empty', 'nex-captcha' ); ?></label>
		<input type="text" name="nxc_hp" id="<?php echo esc_attr( $nxc_field_id ); ?>_hp" tabindex="-1" autocomplete="off" value="" />
	</div>
	<div class="nxc-widget__status" role="status" aria-live="polite">
		<?php esc_html_e( 'Verifying you are human…', 'nex-captcha' ); ?>
	</div>
</div>
