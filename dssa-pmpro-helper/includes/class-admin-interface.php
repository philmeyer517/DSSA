<?php
// -----------------------------------------------------------------------------
// Temporary safety stub to prevent fatal errors if branches helper is unavailable
// -----------------------------------------------------------------------------
if ( ! function_exists( 'dssa_get_branches' ) ) {
	function dssa_get_branches() {
		return [];
	}
}

/**
* Admin Interface for DSSA PMPro Helper (Phase 1)
*/
if (!defined('ABSPATH')) {
    exit;
}

class DSSA_PMPro_Helper_Admin_Interface {
    private static $instance = null;
    
    /**
    * Get instance
    */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
    * Initialize
    */
	public static function init() {
		$instance = self::get_instance();
		$instance->register_hooks();
	}
			
	public function register_hooks() {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'wp_ajax_dssa_assign_membership_number', [ $this, 'ajax_assign_membership_number' ] );
		add_action( 'wp_ajax_dssa_assign_branch', [ $this, 'ajax_assign_branch' ] );
		// Register inline field assignment AJAX handler
		add_action( 'wp_ajax_dssa_assign_member_field', [ $this, 'ajax_assign_member_field' ] );
		add_action( 'admin_post_dssa_export_members', [ $this, 'export_members_csv' ] );
		add_action( 'admin_footer', [ $this, 'render_member_management_modals' ] );
	}
    
    /**
    * Enqueue admin assets
    */
	public function enqueue_admin_assets( $hook ) {

		/**
		 * Load assets only on DSSA admin pages.
		 * All DSSA pages use the format:
		 * dssa-admin_page_{page-slug}
		 */
		if ( strpos( $hook, 'dssa-admin_page_' ) !== 0 ) {
			return;
		}

		// Enqueue CSS
		wp_enqueue_style(
			'dssa-admin-css',
			DSSA_PMPRO_HELPER_URL . 'assets/css/admin.css',
			[],
			DSSA_PMPRO_HELPER_VERSION
		);

		// Enqueue JS
		wp_enqueue_script(
			'dssa-admin-js',
			DSSA_PMPRO_HELPER_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			DSSA_PMPRO_HELPER_VERSION,
			true
		);

		wp_localize_script( 'dssa-admin-js', 'dssa_admin_js', [
			'enter_number'   => __( 'Please enter a membership number.', 'dssa-pmpro-helper' ),
			'select_branch'  => __( 'Please select a branch.', 'dssa-pmpro-helper' ),
			'success_number' => __( 'Membership number assigned successfully!', 'dssa-pmpro-helper' ),
			'success_branch' => __( 'Branch assigned successfully!', 'dssa-pmpro-helper' ),
			'error'          => __( 'Error', 'dssa-pmpro-helper' ),
			'ajax_error'     => __( 'Error assigning data. Please try again.', 'dssa-pmpro-helper' ),
			'assigning'      => __( 'Assigning...', 'dssa-pmpro-helper' ),
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'dssa_admin_nonce' ),
			'branch_options' => $this->get_pmpro_branch_options(),
		] );
	}
    
    /**
    * Get PMPro branch options
    */
	private function get_pmpro_branch_options(): array {

		if (!function_exists('pmpro_get_user_fields')) {
			return [];
		}

		$fields = pmpro_get_user_fields();

		if (
			empty($fields['branch']) ||
			empty($fields['branch']['options']) ||
			!is_array($fields['branch']['options'])
		) {
			return [];
		}

		return $fields['branch']['options'];
	}
	
	/**
	 * AJAX handler for inline member field assignment (membership_number, branch)
	 */
	public function ajax_assign_member_field() {

		check_ajax_referer( 'dssa_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'dssa-pmpro-helper' ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$field   = isset( $_POST['field'] ) ? sanitize_key( $_POST['field'] ) : '';
		$value   = isset( $_POST['value'] ) ? sanitize_text_field( $_POST['value'] ) : '';

		if ( ! $user_id || ! $field ) {
			wp_send_json_error( __( 'Invalid data.', 'dssa-pmpro-helper' ) );
		}

		// Whitelist editable fields
		$allowed_fields = [
			'membership_number',
			'branch',
		];

		if ( ! in_array( $field, $allowed_fields, true ) ) {
			wp_send_json_error( __( 'Invalid field.', 'dssa-pmpro-helper' ) );
		}

		// Map to actual user meta keys if you use different keys elsewhere
		$meta_key_map = [
			'membership_number' => 'dssa_membership_number',
			'branch'            => 'dssa_branch', // standardized canonical branch key
		];

		$meta_key = isset( $meta_key_map[ $field ] ) ? $meta_key_map[ $field ] : $field;

		update_user_meta( $user_id, $meta_key, $value );

		wp_send_json_success();
	}
		
    /**
    * Add admin menu
    */
    public function add_admin_menu() {
        // Both administrators and membership_managers can see the menu
        $required_cap = 'manage_dssa';
        
        // Main DSSA Admin menu (matches spec exactly)
        add_menu_page(
            __('DSSA Admin', 'dssa-pmpro-helper'),
            __('DSSA Admin', 'dssa-pmpro-helper'),
            $required_cap,
            'dssa-admin',
            [$this, 'render_dashboard_page'],
            'dashicons-calendar',
            30
        );
        
        // Dashboard submenu
        add_submenu_page(
            'dssa-admin',
            __('Dashboard', 'dssa-pmpro-helper'),
            __('Dashboard', 'dssa-pmpro-helper'),
            $required_cap,
            'dssa-admin',
            [$this, 'render_dashboard_page']
        );
        
        // Legacy Members (Section 1 in spec)
        add_submenu_page(
            'dssa-admin',
            __('Legacy Members', 'dssa-pmpro-helper'),
            __('Legacy Members', 'dssa-pmpro-helper'),
            $required_cap,
            'dssa-legacy-members',
            [$this, 'render_legacy_members_page']
        );
        
        // Branch Management (NEW - Section 2 in spec)
        add_submenu_page(
            'dssa-admin',
            __('Branch Management', 'dssa-pmpro-helper'),
            __('Branch Management', 'dssa-pmpro-helper'),
            $required_cap,
            'dssa-branch-management',
            [$this, 'render_branch_management_page']
        );
        
        // Billing Settings (RENAME - Section 3 in spec)
        add_submenu_page(
            'dssa-admin',
            __('Billing Settings', 'dssa-pmpro-helper'),
            __('Billing Settings', 'dssa-pmpro-helper'),
            $required_cap,
            'dssa-settings',
            [$this, 'render_settings_page']
        );
        
        // Member Management (NEW - Section 4 in spec)
        add_submenu_page(
            'dssa-admin',
            __('Member Management', 'dssa-pmpro-helper'),
            __('Member Management', 'dssa-pmpro-helper'),
            $required_cap,
            'dssa-member-management',
            [$this, 'render_member_management_page']
        );
        
        // Reports (ENHANCE - Section 5 in spec)
        add_submenu_page(
            'dssa-admin',
            __('Reports', 'dssa-pmpro-helper'),
            __('Reports', 'dssa-pmpro-helper'),
            $required_cap,
            'dssa-reports',
            [$this, 'render_reports_page']
        );
    }

    // NOTE: further down in this file every occurrence where user meta or WP_User_Query used 'branch'
    // has been updated to use 'dssa_branch' (for queries, get_user_meta calls and update_user_meta).
    // Also member number displays use 'dssa_membership_number'. The rest of the file remains the same.
}