<?php
/**
 * Fires on plugin deletion. Drops the events table and removes all options.
 *
 * @package Bitflw\NexCaptcha
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || die;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nxc_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

delete_option( 'nxc_settings' );
delete_option( 'nxc_secret' );
delete_option( 'nxc_db_version' );

wp_clear_scheduled_hook( 'nxc_prune_events' );
