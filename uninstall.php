<?php
/**
 * RJV AGI Bridge – Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 *
 * @package RJV_AGI_Bridge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove all plugin options.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rjv\_agi\_%'"
);

// Drop the audit log table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rjv_agi_audit_log" );

// Clean up transients.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rjv\_rl\_%' OR option_name LIKE '_transient_timeout_rjv\_rl\_%'"
);

// Flush rewrite rules.
flush_rewrite_rules();
