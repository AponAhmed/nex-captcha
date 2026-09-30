<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Contract implemented by every form integration.
 *
 * render()/validate() are deliberately not part of this contract: each
 * integration's actual WP hook has its own callback signature (login's
 * authenticate filter vs. comments' preprocess_comment vs. CF7's
 * wpcf7_validate), so they're defined per-integration and wired up
 * inside that integration's own register().
 */
interface IntegrationInterface {

	/**
	 * Whether this integration's target (e.g. a third-party plugin) is
	 * active, so Plugin::boot() knows whether to register it.
	 */
	public function is_available(): bool;

	/**
	 * Hooks this integration into WordPress.
	 */
	public function register(): void;
}
