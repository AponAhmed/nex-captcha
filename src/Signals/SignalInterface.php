<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Signals;

defined( 'ABSPATH' ) || exit;

/**
 * Contract implemented by every passive anti-bot signal.
 */
interface SignalInterface {

	/**
	 * @param array<string, mixed> $data Submitted form data ($_POST).
	 * @return string|null A reason code if this signal fires, null if clean.
	 */
	public function check( array $data ): ?string;
}
