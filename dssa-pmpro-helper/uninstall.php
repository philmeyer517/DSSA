<?php
/**
 * Uninstall script for DSSA PMPro Helper
 * 
 * This file is called when the plugin is deleted from WordPress admin.
 * It cleans up all plugin data if the cleanup setting is enabled.
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Load WordPress functions
require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

/**
 * Clean up plugin data
 */
function dssa_pmpro_helper_uninstall_cleanup() {
    global $wpdb;
    
    // Get cleanup setting
    $cleanup_on_delete = get_option('dssa_pmpro_helper_cleanup_on_delete', false);
    
    // Only proceed if cleanup is enabled
    if (!$cleanup_on_delete) {
        return;
    }
    
    // Remove all plugin options
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dssa_pmpro_helper_%'");
    
    // Remove database tables
    $tables = [
        "{$wpdb->prefix}dssa_legacy_numbers",
        "{$wpdb->prefix}dssa_audit_log",
        "{$wpdb->prefix}dssa_branches",
    ];
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
    
    // Remove user meta
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'dssa_%'");
    
    // Remove scheduled cron jobs
    wp_clear_scheduled_hook('dssa_pmpro_helper_daily_renewal_check');
    wp_clear_scheduled_hook('dssa_pmpro_helper_daily_audit_cleanup');
    
    // Remove custom role (if no users have it)
    $users_with_role = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value LIKE '%membership_manager%'");
    
    if ($users_with_role == 0) {
        remove_role('membership_manager');
    }
    
    // Clear any transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dssa_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_dssa_%'");
}

// Run cleanup
dssa_pmpro_helper_uninstall_cleanup();

// Log the uninstall (if debug is enabled)
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('[DSSA PMPro Helper] Plugin uninstalled and data cleaned up.');
}