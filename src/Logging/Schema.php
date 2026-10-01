<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the events table via dbDelta().
 */
class Schema {

	private const DB_VERSION = 1;

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'nxc_events';
	}

	/**
	 * dbDelta() is picky about formatting: two spaces before PRIMARY
	 * KEY/KEY, each column on its own line.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			form VARCHAR(64) NOT NULL,
			mode VARCHAR(16) NOT NULL,
			result VARCHAR(16) NOT NULL,
			reason VARCHAR(32) NULL,
			ip_hash CHAR(64) NOT NULL,
			solve_ms INT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY ip_hash (ip_hash)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'nxc_db_version', self::DB_VERSION );
	}
}
