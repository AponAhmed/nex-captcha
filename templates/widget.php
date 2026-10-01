<?php
/**
 * Widget container markup: .nxc-widget div, hidden nxc_token input, an
 * ARIA live status region, a honeypot field (Signals\Honeypot), a
 * render-time timestamp (Signals\TimeTrap), an interaction flag
 * (Signals\InteractionCheck, set by widget.ts) and a <noscript>
 * Challenge\MathChallenge fallback for visitors with JS disabled --
 * rendered server-side here since there's no JS to fetch one over REST.
 *
 * @var string $form_id Form identifier the widget protects.
 *
 * @package Bitflw\NexCaptcha
 */

use Bitflw\NexCaptcha\Challenge\MathChallenge;

defined( 'ABSPATH' ) || exit;

$nxc_field_id = 'nxc_token_' . preg_replace( '/[^a-z0-9_-]/', '_', $form_id );
$nxc_math     = MathChallenge::create( $form_id, 0, (int) apply_filters( 'nxc_challenge_ttl', 600, $form_id ) );
?>
<div class="nxc-widget" data-form="<?php echo esc_attr( $form_id ); ?>" data-rest-url="<?php echo esc_url( rest_url( 'nexcaptcha/v1/' ) ); ?>">
	<input type="hidden" name="nxc_token" id="<?php echo esc_attr( $nxc_field_id ); ?>" value="" />
	<input type="hidden" name="nxc_ts" value="<?php echo esc_attr( (string) time() ); ?>" />
	<input type="hidden" name="nxc_interacted" value="0" />
	<div class="nxc-widget__hp" aria-hidden="true">
		<label for="<?php echo esc_attr( $nxc_field_id ); ?>_hp"><?php esc_html_e( 'Leave this field empty', 'nex-captcha' ); ?></label>
		<input type="text" name="nxc_hp" id="<?php echo esc_attr( $nxc_field_id ); ?>_hp" tabindex="-1" autocomplete="off" value="" />
	</div>
	<div class="nxc-widget__status" role="status" aria-live="polite">
		<?php esc_html_e( 'Verifying you are human…', 'nex-captcha' ); ?>
	</div>
	<noscript>
		<div class="nxc-widget__math">
			<input type="hidden" name="nxc_math_salt" value="<?php echo esc_attr( $nxc_math['salt'] ); ?>" />
			<input type="hidden" name="nxc_math_signature" value="<?php echo esc_attr( $nxc_math['signature'] ); ?>" />
			<label for="<?php echo esc_attr( $nxc_field_id ); ?>_math">
				<?php
				printf(
					/* translators: %s: a simple addition question, e.g. "7 + 5" */
					esc_html__( 'Verify you are human -- what is %s?', 'nex-captcha' ),
					esc_html( $nxc_math['question'] )
				);
				?>
			</label>
			<input type="text" inputmode="numeric" name="nxc_math_answer" id="<?php echo esc_attr( $nxc_field_id ); ?>_math" required="required" />
		</div>
	</noscript>
</div>
