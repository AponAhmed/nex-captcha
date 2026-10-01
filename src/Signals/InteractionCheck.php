<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Signals;

defined( 'ABSPATH' ) || exit;

/**
 * Flags submissions with no real pointer, focus or keyboard input.
 * assets/src/widget.ts sets nxc_interacted on the first focusin,
 * pointerdown or input event anywhere in the protected form.
 */
class InteractionCheck implements SignalInterface {

	private const FIELD = 'nxc_interacted';

	public function check( array $data ): ?string {
		return empty( $data[ self::FIELD ] ) ? 'no_interaction' : null;
	}
}
