<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Shared base for form integrations. Empty for now: with only one
 * concrete integration (WpLogin) there's nothing real to share yet.
 */
abstract class AbstractIntegration implements IntegrationInterface {
	abstract public function is_available(): bool;
	abstract public function register(): void;
}
