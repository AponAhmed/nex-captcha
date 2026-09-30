<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Signals;

defined( 'ABSPATH' ) || exit;

/**
 * Hidden input field that only bots fill in. The field itself is
 * rendered by templates/widget.php and hidden off-screen (not
 * display:none) in widget.css, so it stays hidden even for a bot that
 * never executes JS.
 */
class Honeypot implements SignalInterface {

	private const FIELD = 'nxc_hp';

	public function check( array $data ): ?string {
		return ! empty( $data[ self::FIELD ] ) ? 'honeypot' : null;
	}
}
