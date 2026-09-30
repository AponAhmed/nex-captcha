<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Signals;

use Bitflw\NexCaptcha\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Flags submissions made faster than a human plausibly could.
 *
 * The nxc_ts field is deliberately unsigned: it's a heuristic against
 * naive bots, not a security boundary like the PoW token. A bot
 * sophisticated enough to forge a stale timestamp is already past what
 * a signed field would stop -- the PoW and replay guard are what
 * actually have to hold.
 */
class TimeTrap implements SignalInterface {

	private const FIELD = 'nxc_ts';

	public function check( array $data ): ?string {
		if ( ! isset( $data[ self::FIELD ] ) || ! is_numeric( $data[ self::FIELD ] ) ) {
			return 'too_fast';
		}

		$elapsed  = time() - (int) $data[ self::FIELD ];
		$required = (int) Settings::get( 'time_trap_seconds', 2 );

		return $elapsed < $required ? 'too_fast' : null;
	}
}
