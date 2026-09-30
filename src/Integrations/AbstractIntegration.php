<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Integrations;

use Bitflw\NexCaptcha\Admin\Settings;
use Bitflw\NexCaptcha\Support\Ip;

defined( 'ABSPATH' ) || exit;

/**
 * Shared base for form integrations.
 */
abstract class AbstractIntegration implements IntegrationInterface {
	abstract public function is_available(): bool;
	abstract public function register(): void;

	/**
	 * Whether the visitor's IP is on the configured allowlist. Used by
	 * every integration; location-based exemption makes sense
	 * regardless of authentication state.
	 */
	protected function ip_allowlisted(): bool {
		return Ip::in_allowlist( Settings::get( 'allowlist', array() ) );
	}

	/**
	 * Whether the current user's role is exempt, per the configured
	 * bypass_roles list. Only meaningful where a "current user" exists
	 * before submission -- WpComments uses this; login/register/
	 * lostpassword don't, since nothing is authenticated yet at the
	 * point those forms are submitted.
	 */
	protected function role_bypassed(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$roles   = wp_get_current_user()->roles;
		$allowed = Settings::get( 'bypass_roles', array() );

		return array() !== array_intersect( $roles, $allowed );
	}
}
