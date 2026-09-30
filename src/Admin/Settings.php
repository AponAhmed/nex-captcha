<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Admin;

use Bitflw\NexCaptcha\Support\Ip;

defined( 'ABSPATH' ) || exit;

/**
 * Default settings, sanitization and schema migration.
 *
 * The full shape matches docs/PLAN.md section 4.1, including fields
 * with no consumer yet (mode, escalation, time_trap_seconds, logging,
 * fail_mode, trusted_proxies) -- stored now so later phases don't need
 * to touch the schema again, but with no admin UI control until
 * something actually reads them.
 */
class Settings {

	private const OPTION         = 'nxc_settings';
	private const SCHEMA_VERSION = 1;

	private const DIFFICULTIES = array( 'low', 'balanced', 'high', 'custom' );
	private const FORM_IDS     = array( 'login', 'register', 'lostpassword', 'comments' );
	private const THEMES       = array( 'light', 'dark', 'auto' );
	private const MODES        = array( 'invisible', 'checkbox' );
	private const FAIL_MODES   = array( 'open', 'closed' );

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'schema_version'    => self::SCHEMA_VERSION,
			'mode'              => 'invisible',
			'difficulty'        => 'balanced',
			'custom_maxnumber'  => 100000,
			'escalation'        => true,
			'forms'             => array(
				'login'        => true,
				'register'     => true,
				'lostpassword' => true,
				'comments'     => true,
			),
			'theme'             => 'auto',
			'time_trap_seconds' => 2,
			'rate_limit'        => array(
				'max'    => 10,
				'window' => 600,
			),
			'allowlist'         => array(),
			'bypass_roles'      => array( 'administrator' ),
			'trusted_proxies'   => array(),
			'fail_mode'         => array(
				'login'    => 'closed',
				'comments' => 'open',
			),
			'logging'           => array(
				'enabled'        => true,
				'retention_days' => 30,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return array_replace_recursive( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();

		return $all[ $key ] ?? $fallback;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public static function update( array $input ): void {
		update_option( self::OPTION, self::sanitize( $input ) );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$current = self::all();

		return array(
			'schema_version'    => self::SCHEMA_VERSION,
			'mode'              => self::sanitize_enum( $input['mode'] ?? null, self::MODES, $current['mode'] ),
			'difficulty'        => self::sanitize_enum( $input['difficulty'] ?? null, self::DIFFICULTIES, $current['difficulty'] ),
			'custom_maxnumber'  => self::sanitize_positive_int( $input['custom_maxnumber'] ?? null, $current['custom_maxnumber'] ),
			'escalation'        => ! empty( $input['escalation'] ),
			'forms'             => self::sanitize_forms( $input['forms'] ?? array() ),
			'theme'             => self::sanitize_enum( $input['theme'] ?? null, self::THEMES, $current['theme'] ),
			'time_trap_seconds' => self::sanitize_positive_int( $input['time_trap_seconds'] ?? null, $current['time_trap_seconds'] ),
			'rate_limit'        => array(
				'max'    => self::sanitize_positive_int( $input['rate_limit']['max'] ?? null, $current['rate_limit']['max'] ),
				'window' => self::sanitize_positive_int( $input['rate_limit']['window'] ?? null, $current['rate_limit']['window'] ),
			),
			'allowlist'         => self::sanitize_ip_list( $input['allowlist'] ?? array() ),
			'bypass_roles'      => self::sanitize_roles( $input['bypass_roles'] ?? array() ),
			'trusted_proxies'   => self::sanitize_ip_list( $input['trusted_proxies'] ?? array() ),
			'fail_mode'         => array(
				'login'    => self::sanitize_enum( $input['fail_mode']['login'] ?? null, self::FAIL_MODES, $current['fail_mode']['login'] ),
				'comments' => self::sanitize_enum( $input['fail_mode']['comments'] ?? null, self::FAIL_MODES, $current['fail_mode']['comments'] ),
			),
			'logging'           => array(
				'enabled'        => ! empty( $input['logging']['enabled'] ),
				'retention_days' => self::sanitize_positive_int( $input['logging']['retention_days'] ?? null, $current['logging']['retention_days'] ),
			),
		);
	}

	/**
	 * @param mixed $value
	 * @param string[] $allowed
	 */
	private static function sanitize_enum( $value, array $allowed, string $fallback ): string {
		return ( is_string( $value ) && in_array( $value, $allowed, true ) ) ? $value : $fallback;
	}

	/**
	 * @param mixed $value
	 */
	private static function sanitize_positive_int( $value, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		$int = (int) $value;

		return $int > 0 ? $int : $fallback;
	}

	/**
	 * @param mixed $forms
	 * @return array<string, bool>
	 */
	private static function sanitize_forms( $forms ): array {
		$forms  = is_array( $forms ) ? $forms : array();
		$result = array();

		foreach ( self::FORM_IDS as $form_id ) {
			$result[ $form_id ] = ! empty( $forms[ $form_id ] );
		}

		return $result;
	}

	/**
	 * Accepts either an array of entries or a newline/comma separated
	 * string (a plain <textarea>, one entry per line, is the admin UI).
	 *
	 * @param mixed $raw
	 * @return string[]
	 */
	private static function sanitize_ip_list( $raw ): array {
		if ( is_array( $raw ) ) {
			$entries = $raw;
		} else {
			$entries = (array) preg_split( '/[\r\n,]+/', (string) $raw );
		}

		$entries = array_map( 'trim', $entries );

		return array_values( array_filter( $entries, array( Ip::class, 'is_valid_allowlist_entry' ) ) );
	}

	/**
	 * @param mixed $roles
	 * @return string[]
	 */
	private static function sanitize_roles( $roles ): array {
		$roles = is_array( $roles ) ? $roles : array();
		$roles = array_map( 'sanitize_key', $roles );

		return array_values( array_unique( array_filter( $roles ) ) );
	}
}
