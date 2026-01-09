<?php
/**
 * Plugin Name: DSSA PMPro Helper
 * Plugin URI: https://dendro.co.za
 * Description: Custom membership management system for Dendrological Society of South Africa
 * Version: 3.0.0
 * Author: Phil Meyer / RMM New Generation Marketing
 * Author URI: https://rmmm.co.za
 * License: GPL v2 or later
 * Text Domain: dssa-pmpro-helper
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

/* ============================================================
 * CONSTANTS
 * ============================================================ */

define('DSSA_PMPRO_HELPER_VERSION', '3.0.0');
define('DSSA_PMPRO_HELPER_PATH', plugin_dir_path(__FILE__));
define('DSSA_PMPRO_HELPER_URL', plugin_dir_url(__FILE__));
define('DSSA_PMPRO_HELPER_FILE', __FILE__);

/* ============================================================
 * GLOBAL HELPERS
 * ============================================================ */

function dssa_pmpro_helper_get_setting($key, $default = '') {
	$value = get_option("dssa_pmpro_helper_{$key}", $default);
	return apply_filters("dssa_pmpro_helper_setting_{$key}", $value);
}

function dssa_pmpro_helper_log($message, $data = null) {
	if (!dssa_pmpro_helper_get_setting('enable_debug_logging')) {
		return;
	}

	$log = '[DSSA PMPro Helper] ' . $message;
	if ($data !== null) {
		$log .= ' | ' . print_r($data, true);
	}

	error_log($log);
}

/* ============================================================
 * REQUIREMENTS CHECK
 * ============================================================ */

function dssa_pmpro_helper_check_requirements() {

	if (!class_exists('PMPro_Membership_Level')) {
		add_action('admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__('Paid Memberships Pro is required.', 'dssa-pmpro-helper');
			echo '</p></div>';
		});
		return false;
	}

	return true;
}

/* ============================================================
 * MAIN BOOTSTRAP (FRONTEND + ADMIN)
 * ============================================================ */

function dssa_pmpro_helper_init() {

	if (!dssa_pmpro_helper_check_requirements()) {
		return;
	}

	load_plugin_textdomain(
		'dssa-pmpro-helper',
		false,
		dirname(plugin_basename(__FILE__)) . '/languages'
	);

	$files = [
		'includes/class-database.php',
		'includes/class-settings.php',
		'includes/class-checkout-fields.php',
		'includes/class-audit-log.php',
		'includes/class-security.php',
		'includes/class-legacy-members.php',
		'includes/class-membership-levels.php',
		'includes/class-registration.php',
		'includes/class-branch-management.php',
		'includes/class-login-system.php',
	];

	foreach ($files as $file) {
		require_once DSSA_PMPRO_HELPER_PATH . $file;
	}

	$classes = [
		'DSSA_PMPro_Helper_Database',
		'DSSA_PMPro_Helper_Settings',
		'DSSA_PMPro_Helper_Checkout_Fields',
		'DSSA_PMPro_Helper_Legacy_Members',
		'DSSA_PMPro_Helper_Security',
		'DSSA_PMPro_Helper_Audit_Log',
		'DSSA_PMPro_Helper_Registration',
		'DSSA_PMPro_Helper_Membership_Levels',
		'DSSA_PMPro_Helper_Login_System',
		'DSSA_PMPro_Helper_Branch_Management',
	];

	foreach ($classes as $class) {
		if (class_exists($class) && method_exists($class, 'init')) {
			$class::init();
		}
	}
}

/**
 * IMPORTANT:
 * Must run on frontend BEFORE PMPro renders checkout
 */
add_action('plugins_loaded', 'dssa_pmpro_helper_init', 5);

/* ============================================================
 * ADMIN-ONLY INTERFACE
 * ============================================================ */

add_action('plugins_loaded', function () {

	if (!is_admin()) {
		return;
	}

	$admin_file = DSSA_PMPRO_HELPER_PATH . 'includes/class-admin-interface.php';

	if (file_exists($admin_file)) {
		require_once $admin_file;

		if (class_exists('DSSA_PMPro_Helper_Admin_Interface')) {
			DSSA_PMPro_Helper_Admin_Interface::init();
		}
	}
});

/* ============================================================
 * ACTIVATION / DEACTIVATION
 * ============================================================ */

register_activation_hook(__FILE__, function () {

	if (!class_exists('PMPro_Membership_Level')) {
		deactivate_plugins(plugin_basename(__FILE__));
		wp_die(__('Paid Memberships Pro must be active.', 'dssa-pmpro-helper'));
	}

	require_once DSSA_PMPRO_HELPER_PATH . 'includes/class-database.php';
	DSSA_PMPro_Helper_Database::create_tables();
});

register_deactivation_hook(__FILE__, function () {
	wp_clear_scheduled_hook('dssa_pmpro_helper_daily_renewal_check');
	wp_clear_scheduled_hook('dssa_pmpro_helper_daily_audit_cleanup');
});
