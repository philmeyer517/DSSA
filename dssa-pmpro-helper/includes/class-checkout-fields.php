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

	/* ======================================================
	 * Get singleton instance
	/* ====================================================== */

	public static function get_instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ======================================================
	 * Initialise hooks
	/* ====================================================== */
	public static function init() {

		$instance = self::get_instance();

		dssa_pmpro_helper_log('Checkout Fields INIT reached');

		// ALWAYS register hooks — never conditionally
		add_action('wp_enqueue_scripts', [$instance, 'enqueue_checkout_assets'], 99);
		
		add_action('pmpro_checkout_after_account_information_heading', [$instance, 'inject_account_info_intro']);

		/**
		 * IMPORTANT:
		 * PMPro core account field labels (username, password, email)
		 * are rendered directly in templates and DO NOT reliably pass
		 * through pmpro_checkout_fields.
		 *
		 * This scoped gettext filter is intentional and safe.
		 * Do NOT remove unless PMPro changes its rendering layer.
		 */		
		add_filter('gettext', [$instance, 'override_pmpro_core_labels'], 20, 3);

		add_filter('pmpro_registration_checks', [$instance, 'validate_checkout_fields']);

		add_action('wp_ajax_dssa_validate_legacy_number', [$instance, 'ajax_validate_legacy_number']);
		add_action('wp_ajax_nopriv_dssa_validate_legacy_number', [$instance, 'ajax_validate_legacy_number']);

		add_action('pmpro_after_checkout', [$instance, 'save_custom_checkout_fields']);
		add_action('user_register', [$instance, 'save_custom_checkout_fields']);

		return $instance;
	}
	
	/* ======================================================
	 * Inject explanatory text below Account Information heading
	 * ====================================================== */

	public function inject_account_info_intro() {

		if ( ! function_exists( 'pmpro_is_checkout' ) || ! pmpro_is_checkout() ) {
			return;
		}

		echo '<div class="dssa-account-info-intro">';
		echo wp_kses_post(
			__(
				'<p><strong>Please note:</strong> These details will be used to create your DSSA online account.</p>
				 <p>Ensure your email address is correct, as all membership communication will be sent here.</p>',
				'dssa-pmpro-helper'
			)
		);
		echo '</div>';
	}

	/* ======================================================
	 * Bilingual labels for PMPro native account fields
	 * ====================================================== */

	public function override_pmpro_core_labels( $translated, $text, $domain ) {

		if ( $domain !== 'paid-memberships-pro' ) {
			return $translated;
		}

		$map = [
			'Username'               => 'Username / Gebruikersnaam',
			'Password'               => 'Password / Wagwoord',
			'Confirm Password'       => 'Confirm Password / Bevestig Wagwoord',
			'Email Address'          => 'Email / E-posadres',
			'Confirm Email Address'  => 'Confirm Email / Bevestig E-posadres',
		];

		return $map[ $text ] ?? $translated;
	}

	/* ======================================================
	 * ASSETS
	 * ====================================================== */

	public function enqueue_checkout_assets() {

		if (is_admin()) {
			return;
		}

		// 🚫 Only load on PMPro checkout
		if ( ! function_exists( 'pmpro_is_checkout' ) || ! pmpro_is_checkout() ) {
			return;
		}

		dssa_pmpro_helper_log('enqueue_checkout_assets() fired');

		wp_enqueue_script(
			'dssa-checkout',
			DSSA_PMPRO_HELPER_URL . 'assets/js/checkout.js',
			['jquery'],
			DSSA_PMPRO_HELPER_VERSION,
			true
		);
	}


	/* ======================================================
	 * AJAX: Legacy membership validation
	 * ====================================================== */

	public function ajax_validate_legacy_number() {

		if (!check_ajax_referer('dssa_ajax_nonce', 'nonce', false)) {
			wp_send_json_success([
				'valid'   => false,
				'message' => __('Security check failed.', 'dssa-pmpro-helper'),
			]);
			wp_die();
		}

		$number = isset($_POST['member_number'])
			? sanitize_text_field($_POST['member_number'])
			: '';

		if (empty($number)) {
			wp_send_json_success([
				'valid'   => false,
				'message' => __('Please enter a membership number.', 'dssa-pmpro-helper'),
			]);
			wp_die();
		}

		if (!class_exists('DSSA_PMPro_Helper_Database')) {
			wp_send_json_success([
				'valid'   => false,
				'message' => __('System error. Please try again.', 'dssa-pmpro-helper'),
			]);
			wp_die();
		}

		$result = DSSA_PMPro_Helper_Database::check_legacy_number($number);

		wp_send_json_success([
			'valid'   => !empty($result['valid']),
			'message' => $result['message'] ?? __('Validation completed.', 'dssa-pmpro-helper'),
		]);

		wp_die();
	}

	/* ======================================================
	 * SERVER-SIDE VALIDATION
	 * ====================================================== */

	public function validate_checkout_fields($okay) {

		if (empty($_POST['exist_member']) || intval($_POST['exist_member']) !== 1) {
			return $okay;
		}

		if (empty($_POST['member_number'])) {
			pmpro_setMessage(__('Please enter your membership number.', 'dssa-pmpro-helper'), 'pmpro_error');
			return false;
		}

		if (empty($_POST['branch'])) {
			pmpro_setMessage(__('Please select your branch.', 'dssa-pmpro-helper'), 'pmpro_error');
			return false;
		}

		if (class_exists('DSSA_PMPro_Helper_Database')) {
			$result = DSSA_PMPro_Helper_Database::check_legacy_number(
				sanitize_text_field($_POST['member_number'])
			);

			if (empty($result['valid'])) {
				pmpro_setMessage($result['message'], 'pmpro_error');
				return false;
			}
		}

		return $okay;
	}

	/* ======================================================
	 * SAVE CHECKOUT DATA
	 * ====================================================== */

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
			update_user_meta($user_id, 'dssa_member_number', $number);

			if ($is_legacy_member && class_exists('DSSA_PMPro_Helper_Database')) {
				DSSA_PMPro_Helper_Database::claim_legacy_number($number, $user_id);
			}
		}

		if (isset($_POST['branch'])) {
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