<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Verification;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks redeemed challenge IDs so each token can be used only once.
 */
class ReplayGuard {
}
