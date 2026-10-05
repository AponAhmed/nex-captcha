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
	 * SettingsPage used to render General/Forms/Security as three
	 * SEPARATE <form>s (one per tab), which meant this sanitize_callback
	 * couldn't tell "a field this submit doesn't own is absent" apart
	 * from "the user unchecked/cleared it" -- every save silently wiped
	 * every OTHER tab's fields back to empty/false. That's fixed at the
	 * source now instead of gated here: SettingsPage renders General,
	 * Forms and Security as stacked panels inside ONE shared <form>, so
	 * every submit -- regardless of which panel's own Save button was
	 * clicked -- always carries every field together. Fields with no
	 * admin UI control at all yet (mode, theme, trusted_proxies,
	 * fail_mode, logging) are simply never touched here and stay
	 * whatever $current already had, same as always.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$current = self::all();

		$result                      = $current;
		$result['schema_version']    = self::SCHEMA_VERSION;
		$result['difficulty']        = self::sanitize_enum( $input['difficulty'] ?? null, self::DIFFICULTIES, $current['difficulty'] );
		$result['custom_maxnumber']  = self::sanitize_positive_int( $input['custom_maxnumber'] ?? null, $current['custom_maxnumber'] );
		$result['escalation']        = ! empty( $input['escalation'] );
		$result['forms']             = self::sanitize_forms( $input['forms'] ?? array() );
		$result['allowlist']         = self::sanitize_ip_list( $input['allowlist'] ?? array() );
		$result['bypass_roles']      = self::sanitize_roles( $input['bypass_roles'] ?? array() );
		$result['rate_limit']        = array(
			'max'    => self::sanitize_positive_int( $input['rate_limit']['max'] ?? null, $current['rate_limit']['max'] ),
			'window' => self::sanitize_positive_int( $input['rate_limit']['window'] ?? null, $current['rate_limit']['window'] ),
		);
		$result['time_trap_seconds'] = self::sanitize_positive_int( $input['time_trap_seconds'] ?? null, $current['time_trap_seconds'] );

		return $result;
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
