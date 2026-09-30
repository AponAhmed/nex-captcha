<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Verification;

defined( 'ABSPATH' ) || exit;

/**
 * Value object describing the outcome of a verification attempt.
 */
final class Result {

	private function __construct(
		public readonly bool $ok,
		public readonly ?string $reason,
		public readonly string $mode,
	) {}

	public static function pass( string $mode ): self {
		return new self( true, null, $mode );
	}

	public static function fail( string $reason, string $mode = 'invisible' ): self {
		return new self( false, $reason, $mode );
	}
}
