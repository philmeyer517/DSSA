<?php
/**
 * Checkout Fields class for DSSA PMPro Helper
 * PMPro-safe bilingual labels, legacy member validation, and checkout assets
 */

if (!defined('ABSPATH')) {
	exit;
}

class DSSA_PMPro_Helper_Checkout_Fields {

	private static $instance = null;

	public static function get_instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function init() {
		$instance = self::get_instance();

		dssa_pmpro_helper_log('Checkout Fields INIT reached');

		// ALWAYS register hooks — never conditionally
		add_action('wp_enqueue_scripts', [$instance, 'enqueue_checkout_assets'], 99);
		
		add_action('pmpro_checkout_after_account_information_heading', [$instance, 'inject_account_info_intro']);

		add_filter('gettext', [$instance, 'override_pmpro_core_labels'], 20, 3);

		add_filter('pmpro_registration_checks', [$instance, 'validate_checkout_fields']);

		add_action('wp_ajax_dssa_validate_legacy_number', [$instance, 'ajax_validate_legacy_number']);
		add_action('wp_ajax_nopriv_dssa_validate_legacy_number', [$instance, 'ajax_validate_legacy_number']);

		add_action('pmpro_after_checkout', [$instance, 'save_custom_checkout_fields']);
		add_action('user_register', [$instance, 'save_custom_checkout_fields']);

		return $instance;
	}
	
	// ... other methods unchanged (override_pmpro_core_labels, enqueue_checkout_assets, ajax_validate_legacy_number, validate_checkout_fields) ...

	public function save_custom_checkout_fields($user_id) {

		$is_legacy_member = false;

		if (isset($_POST['exist_member'])) {
			update_user_meta($user_id, 'dssa_exist_member', (int) $_POST['exist_member']);

			if ((int) $_POST['exist_member'] === 1 && !empty($_POST['member_number'])) {
				$is_legacy_member = true;
			}
		}

		if (isset($_POST['member_number'])) {
			$number = sanitize_text_field($_POST['member_number']);
			// Standardized canonical meta key
			update_user_meta($user_id, 'dssa_membership_number', $number);

			if ($is_legacy_member && class_exists('DSSA_PMPro_Helper_Database')) {
				DSSA_PMPro_Helper_Database::claim_legacy_number($number, $user_id);
			}
		}

		if (isset($_POST['branch'])) {
			// Canonical branch meta key
			update_user_meta($user_id, 'dssa_branch', sanitize_text_field($_POST['branch']));
		}

		if (isset($_POST['card_payments'])) {
			update_user_meta($user_id, 'dssa_card_payments', (int) $_POST['card_payments']);
		}

		update_user_meta($user_id, 'dssa_is_legacy_member', $is_legacy_member ? 1 : 0);

		if (class_exists('DSSA_PMPro_Helper_Audit_Log')) {
			DSSA_PMPro_Helper_Audit_Log::add_entry(
				$user_id,
				'member_registered',
				[
					'exist_member'   => $_POST['exist_member'] ?? 0,
					'member_number'  => $_POST['member_number'] ?? '',
					'branch'         => $_POST['branch'] ?? '',
					'card_payments'  => $_POST['card_payments'] ?? 0,
					'is_legacy'      => $is_legacy_member ? 1 : 0,
				]
			);
		}
	}
}