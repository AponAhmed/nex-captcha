<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the widget and challenge templates.
 */
class Template {

	/**
	 * The .nxc-widget container: a hidden nxc_token input plus an ARIA
	 * live status region. widget.ts finds this by class, reads the form
	 * id and REST URL off its data attributes, and fills the input once
	 * it has solved a challenge.
	 *
	 * Honeypot markup and the <noscript> math fallback are added in
	 * Phase 3, alongside Signals\Honeypot and MathChallenge.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $form_id is used by the included template below, invisible to static analysis.
	public static function widget( string $form_id ): string {
		ob_start();
		include NXC_PATH . 'templates/widget.php';
		return (string) ob_get_clean();
	}
}
