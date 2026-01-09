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

			update_user_meta( $user_id, $field, $value );

			wp_send_json_success();
		}
		
		add_action( 'wp_ajax_dssa_assign_member_field', [ $this, 'ajax_assign_member_field' ] );
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
    
    /**
    * Add settings link to plugins page
    */
    public function add_plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=dssa-settings') . '">' .
            __('Settings', 'dssa-pmpro-helper') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
    * Render dashboard page
    */
    public function render_dashboard_page() {
        ?>
        <div class="wrap dssa-admin-wrap">
            <h1><?php _e('DSSA Admin Dashboard', 'dssa-pmpro-helper'); ?></h1>
            <div class="notice notice-info">
                <p><?php _e('Phase 1 functionality is active. Legacy Members and Settings are available.', 'dssa-pmpro-helper'); ?></p>
            </div>
            <div class="dssa-dashboard-widgets">
                <div class="card">
                    <h2><?php _e('Quick Links', 'dssa-pmpro-helper'); ?></h2>
                    <ul>
                        <li><a href="<?php echo admin_url('admin.php?page=dssa-legacy-members'); ?>"><?php _e('Manage Legacy Members', 'dssa-pmpro-helper'); ?></a></li>
                        <li><a href="<?php echo admin_url('admin.php?page=dssa-settings'); ?>"><?php _e('Plugin Settings', 'dssa-pmpro-helper'); ?></a></li>
                        <li><a href="<?php echo admin_url('admin.php?page=pmpro-memberslist'); ?>"><?php _e('PMPro Members List', 'dssa-pmpro-helper'); ?></a></li>
                    </ul>
                </div>
                <div class="card">
                    <h2><?php _e('Phase 1 Features', 'dssa-pmpro-helper'); ?></h2>
                    <ul>
                        <li>✓ <?php _e('Custom checkout fields with conditional logic', 'dssa-pmpro-helper'); ?></li>
                        <li>✓ <?php _e('Legacy member registration flow', 'dssa-pmpro-helper'); ?></li>
                        <li>✓ <?php _e('CSV upload for legacy numbers', 'dssa-pmpro-helper'); ?></li>
                        <li>✓ <?php _e('Audit logging system', 'dssa-pmpro-helper'); ?></li>
                        <li>✓ <?php _e('Settings framework', 'dssa-pmpro-helper'); ?></li>
                    </ul>
                </div>
            </div>
            <style>
                .dssa-dashboard-widgets {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                    gap: 20px;
                    margin-top: 20px;
                }
                .dssa-dashboard-widgets .card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                    padding: 20px;
                }
                .dssa-dashboard-widgets .card h2 {
                    margin-top: 0;
                    border-bottom: 1px solid #eee;
                    padding-bottom: 10px;
                }
                .dssa-dashboard-widgets .card ul {
                    margin-left: 20px;
                }
                .dssa-dashboard-widgets .card li {
                    margin-bottom: 8px;
                }
            </style>
        </div>
        <?php
    }
    
    /**
    * Render legacy members page
    */
    public function render_legacy_members_page() {
        // This should be handled by the DSSA_PMPro_Helper_Legacy_Members class
        if (class_exists('DSSA_PMPro_Helper_Legacy_Members')) {
            DSSA_PMPro_Helper_Legacy_Members::render_legacy_members_page();
        } else {
            echo '<div class="wrap"><h1>' . __('Legacy Members', 'dssa-pmpro-helper') . '</h1>';
            echo '<div class="notice notice-error"><p>' . __('Legacy Members module not available.', 'dssa-pmpro-helper') . '</p></div></div>';
        }
    }
    
    /**
    * Render branch management page
    */
    public function render_branch_management_page() {
        // Get existing branch field options from PMPro
        global $wpdb;
        
        // Try to get branch field options from PMPro user fields
        $branch_options = [];
        
        // Method 1: Check PMPro user fields
        if (function_exists('pmpro_get_user_fields')) {
            $user_fields = pmpro_get_user_fields();
            foreach ($user_fields as $field) {
                if ($field->name === 'branch' && !empty($field->options)) {
                    $branch_options = $field->options;
                    break;
                }
            }
        }
        
        // Method 2: Check directly in options (fallback)
        if (empty($branch_options)) {
            $branch_field = get_option('pmpro_user_field_branch');
            if (!empty($branch_field) && is_array($branch_field) && !empty($branch_field['options'])) {
                $branch_options = $branch_field['options'];
            }
        }
        
        // Method 3: Hardcoded fallback for demo
        if (empty($branch_options)) {
            $branch_options = [
                'Gqeberha Atalaya' => 'Gqeberha Atalaya',
                'Bauhinia' => 'Bauhinia',
                'Boekenhout' => 'Boekenhout',
                'Celtis' => 'Celtis',
                'Elongatus' => 'Elongatus',
                'Encephalartos' => 'Encephalartos',
                'Erythrina' => 'Erythrina',
                'Kameeldoring' => 'Kameeldoring',
                'Kanniedood' => 'Kanniedood',
                'Karee' => 'Karee',
                'Kierieklapper' => 'Kierieklapper',
                'Kremetart' => 'Kremetart',
                'Langberg' => 'Langberg',
                'Magaliesberg' => 'Magaliesberg',
                'Manketti' => 'Manketti',
                'Matumi' => 'Matumi',
                'Musasa' => 'Musasa',
                'Outeniqua' => 'Outeniqua',
                'Pilanesberg' => 'Pilanesberg',
                'Soutpansberg' => 'Soutpansberg',
                'Springbokvlakte' => 'Springbokvlakte',
                'Taaibos' => 'Taaibos',
                'Umdoni' => 'Umdoni',
                'Waterberg' => 'Waterberg',
                'Welwitschia' => 'Welwitschia',
                'Western Province' => 'Western Province',
                'Wolkberg' => 'Wolkberg',
                'Zululand' => 'Zululand',
            ];
        }
        ?>
        <div class="wrap dssa-admin-wrap">
            <h1><?php _e('Branch Management', 'dssa-pmpro-helper'); ?></h1>
            <div class="notice notice-info">
                <p><?php _e('Branches are managed via PMPro User Fields. This interface allows you to view branch assignments and statistics.', 'dssa-pmpro-helper'); ?></p>
            </div>
            <div class="dssa-branch-management">
                <!-- Branch Statistics -->
                <div class="dssa-card">
                    <h2><?php _e('Branch Statistics', 'dssa-pmpro-helper'); ?></h2>
                    <div class="dssa-stats-grid">
                        <?php
                        // Get member count per branch
                        $members_per_branch = [];
                        $total_members = 0;
                        foreach ($branch_options as $value => $label) {
                            $args = [
                                'meta_key' => 'branch',
                                'meta_value' => $value,
                                'count_total' => true,
                            ];
                            $user_query = new WP_User_Query($args);
                            $count = $user_query->get_total();
                            $members_per_branch[$label] = $count;
                            $total_members += $count;
                        }
                        
                        // Add "Unassigned" count
                        $args = [
                            'meta_key' => 'branch',
                            'meta_compare' => 'NOT EXISTS',
                            'count_total' => true,
                        ];
                        $user_query = new WP_User_Query($args);
                        $unassigned_count = $user_query->get_total();
                        $total_members += $unassigned_count;
                        
                        // Display statistics
                        foreach ($members_per_branch as $branch_name => $count) {
                            $percentage = $total_members > 0 ? round(($count / $total_members) * 100, 1) : 0;
                            ?>
                            <div class="stat-box">
                                <h3><?php echo esc_html($branch_name); ?></h3>
                                <p class="stat-number"><?php echo esc_html($count); ?></p>
                                <p class="stat-percentage"><?php echo esc_html($percentage); ?>%</p>
                            </div>
                            <?php
                        }
                        
                        if ($unassigned_count > 0) {
                            $percentage = $total_members > 0 ? round(($unassigned_count / $total_members) * 100, 1) : 0;
                            ?>
                            <div class="stat-box stat-unassigned">
                                <h3><?php _e('Unassigned', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($unassigned_count); ?></p>
                                <p class="stat-percentage"><?php echo esc_html($percentage); ?>%</p>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                    <div class="dssa-total-stat">
                        <h3><?php _e('Total Members with Branch Data:', 'dssa-pmpro-helper'); ?></h3>
                        <p class="stat-number"><?php echo esc_html($total_members); ?></p>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="dssa-card">
                    <h2><?php _e('Quick Actions', 'dssa-pmpro-helper'); ?></h2>
                    <div class="dssa-quick-actions">
                        <p><?php _e('Branch assignments are managed in two ways:', 'dssa-pmpro-helper'); ?></p>
                        <ol>
                            <li>
                                <strong><?php _e('For Legacy Members:', 'dssa-pmpro-helper'); ?></strong>
                                <?php _e('Members select their branch during registration.', 'dssa-pmpro-helper'); ?>
                            </li>
                            <li>
                                <strong><?php _e('For New Members:', 'dssa-pmpro-helper'); ?></strong>
                                <?php _e('Membership Manager assigns branches after registration approval.', 'dssa-pmpro-helper'); ?>
                            </li>
                        </ol>
                        <div class="dssa-action-buttons">
                            <a href="<?php echo admin_url('admin.php?page=dssa-member-management'); ?>" class="button button-primary">
                                <?php _e('Go to Member Management', 'dssa-pmpro-helper'); ?>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=pmpro-memberslist'); ?>" class="button">
                                <?php _e('View PMPro Members', 'dssa-pmpro-helper'); ?>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Branch List -->
                <div class="dssa-card">
                    <h2><?php _e('Available Branches', 'dssa-pmpro-helper'); ?></h2>
                    <div class="dssa-branch-list">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Branch Value', 'dssa-pmpro-helper'); ?></th>
                                    <th><?php _e('Branch Name', 'dssa-pmpro-helper'); ?></th>
                                    <th><?php _e('Member Count', 'dssa-pmpro-helper'); ?></th>
                                    <th><?php _e('Actions', 'dssa-pmpro-helper'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (empty($branch_options)) {
                                    ?>
                                    <tr>
                                        <td colspan="4" class="no-branches">
                                            <?php _e('No branches found. Please configure branches in PMPro User Fields.', 'dssa-pmpro-helper'); ?>
                                        </td>
                                    </tr>
                                    <?php
                                } else {
                                    foreach ($branch_options as $value => $label) {
                                        $args = [
                                            'meta_key' => 'branch',
                                            'meta_value' => $value,
                                            'count_total' => true,
                                        ];
                                        $user_query = new WP_User_Query($args);
                                        $count = $user_query->get_total();
                                        ?>
                                        <tr>
                                            <td><code><?php echo esc_html($value); ?></code></td>
                                            <td><?php echo esc_html($label); ?></td>
                                            <td><?php echo esc_html($count); ?></td>
                                            <td>
                                                <a href="<?php echo admin_url('admin.php?page=dssa-member-management&filter_branch=' . urlencode($value)); ?>" class="button button-small">
                                                    <?php _e('View Members', 'dssa-pmpro-helper'); ?>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                    
                                    // Unassigned row
                                    if ($unassigned_count > 0) {
                                        ?>
                                        <tr class="unassigned-row">
                                            <td colspan="2"><strong><?php _e('Unassigned Members', 'dssa-pmpro-helper'); ?></strong></td>
                                            <td><?php echo esc_html($unassigned_count); ?></td>
                                            <td>
                                                <a href="<?php echo admin_url('admin.php?page=dssa-member-management&filter_unassigned=1'); ?>" class="button button-small">
                                                    <?php _e('View Unassigned', 'dssa-pmpro-helper'); ?>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <style>
                .dssa-branch-management {
                    display: grid;
                    gap: 20px;
                }
                .dssa-stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                    gap: 15px;
                    margin: 20px 0;
                }
                .dssa-stats-grid .stat-box {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 4px;
                    text-align: center;
                    border-left: 4px solid #0073aa;
                }
                .dssa-stats-grid .stat-unassigned {
                    border-left-color: #dc3232;
                }
                .stat-box h3 {
                    margin: 0 0 10px 0;
                    color: #666;
                    font-size: 14px;
                }
                .stat-box .stat-number {
                    font-size: 28px;
                    font-weight: bold;
                    color: #0073aa;
                    margin: 0;
                }
                .stat-box .stat-percentage {
                    font-size: 14px;
                    color: #666;
                    margin: 5px 0 0 0;
                }
                .dssa-total-stat {
                    margin-top: 20px;
                    padding-top: 20px;
                    border-top: 1px solid #eee;
                }
                .dssa-total-stat h3 {
                    margin: 0 0 10px 0;
                    color: #666;
                }
                .dssa-total-stat .stat-number {
                    font-size: 32px;
                    font-weight: bold;
                    color: #0073aa;
                    margin: 0;
                }
                .dssa-quick-actions {
                    margin: 20px 0;
                }
                .dssa-quick-actions ol {
                    margin-left: 20px;
                    margin-bottom: 20px;
                }
                .dssa-quick-actions li {
                    margin-bottom: 10px;
                }
                .dssa-action-buttons {
                    display: flex;
                    gap: 10px;
                    flex-wrap: wrap;
                }
                .dssa-branch-list table {
                    margin-top: 15px;
                }
                .dssa-branch-list .no-branches {
                    text-align: center;
                    padding: 30px;
                    color: #666;
                    font-style: italic;
                }
                .unassigned-row {
                    background-color: #fff8e5 !important;
                }
                @media (max-width: 768px) {
                    .dssa-stats-grid {
                        grid-template-columns: 1fr;
                    }
                    .dssa-action-buttons {
                        flex-direction: column;
                    }
                    .dssa-action-buttons .button {
                        width: 100%;
                        text-align: center;
                    }
                }
            </style>
        </div>
        <?php
    }
    
    /**
    * Render settings page
    */
    public function render_settings_page() {
        // This should be handled by the DSSA_PMPro_Helper_Settings class
        if (class_exists('DSSA_PMPro_Helper_Settings')) {
            DSSA_PMPro_Helper_Settings::display_settings_page();
        } else {
            echo '<div class="wrap"><h1>' . __('Settings', 'dssa-pmpro-helper') . '</h1>';
            echo '<div class="notice notice-error"><p>' . __('Settings module not available.', 'dssa-pmpro-helper') . '</p></div></div>';
        }
    }
    
    /**
    * Render member management page
    */
    public function render_member_management_page() {
        // Check if we're filtering by branch
        $filter_branch = isset($_GET['filter_branch']) ? sanitize_text_field($_GET['filter_branch']) : '';
        $filter_unassigned = isset($_GET['filter_unassigned']) && $_GET['filter_unassigned'] == '1';
        ?>
        <div class="wrap dssa-admin-wrap">
            <h1><?php _e('Member Management', 'dssa-pmpro-helper'); ?></h1>
            <?php if ($filter_branch): ?>
                <div class="notice notice-info">
                    <p>
                        <?php echo sprintf(
                            __('Filtering members by branch: <strong>%s</strong>', 'dssa-pmpro-helper'),
                            esc_html($filter_branch)
                        ); ?>
                        <a href="<?php echo remove_query_arg(['filter_branch', 'filter_unassigned']); ?>" class="button button-small">
                            <?php _e('Clear Filter', 'dssa-pmpro-helper'); ?>
                        </a>
                    </p>
                </div>
            <?php elseif ($filter_unassigned): ?>
                <div class="notice notice-info">
                    <p>
                        <?php _e('Showing unassigned members (no branch selected)', 'dssa-pmpro-helper'); ?>
                        <a href="<?php echo remove_query_arg(['filter_branch', 'filter_unassigned']); ?>" class="button button-small">
                            <?php _e('Clear Filter', 'dssa-pmpro-helper'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>
            
            <div class="dssa-member-management">
                <!-- Member Statistics -->
                <div class="dssa-card">
                    <h2><?php _e('Member Overview', 'dssa-pmpro-helper'); ?></h2>
                    <div class="dssa-member-stats">
                        <?php
                        // Get member counts
                        $args = [
                            'count_total' => true,
                        ];
                        
                        // Apply branch filter if set
                        if ($filter_branch) {
                            $args['meta_key'] = 'branch';
                            $args['meta_value'] = $filter_branch;
                        } elseif ($filter_unassigned) {
                            $args['meta_key'] = 'branch';
                            $args['meta_compare'] = 'NOT EXISTS';
                        }
                        
                        $user_query = new WP_User_Query($args);
                        $total_members = $user_query->get_total();
                        
                        // Get legacy vs new member counts
                        $legacy_args = $args;
                        $legacy_args['meta_key'] = 'dssa_membership_number';
                        $legacy_args['meta_compare'] = 'EXISTS';
                        $legacy_query = new WP_User_Query($legacy_args);
                        $legacy_count = $legacy_query->get_total();
                        $new_member_count = $total_members - $legacy_count;
                        
                        // Get active vs pending counts (simplified)
                        $active_args = array_merge($args, [
                            'meta_query' => [[
                                'key' => 'pmpro_approval_status',
                                'value' => 'approved',
                            ]]
                        ]);
                        $active_query = new WP_User_Query($active_args);
                        $active_count = $active_query->get_total();
                        $pending_count = $total_members - $active_count;
                        ?>
                        <div class="dssa-stats-grid">
                            <div class="stat-box">
                                <h3><?php _e('Total Members', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($total_members); ?></p>
                            </div>
                            <div class="stat-box">
                                <h3><?php _e('Legacy Members', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($legacy_count); ?></p>
                            </div>
                            <div class="stat-box">
                                <h3><?php _e('New Members', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($new_member_count); ?></p>
                            </div>
                            <div class="stat-box">
                                <h3><?php _e('Active', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($active_count); ?></p>
                            </div>
                            <div class="stat-box">
                                <h3><?php _e('Pending', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($pending_count); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Member List -->
                <div class="dssa-card">
                    <h2><?php _e('Member List', 'dssa-pmpro-helper'); ?></h2>
                    <div class="dssa-member-filters">
                        <form method="get" action="<?php echo admin_url('admin.php'); ?>">
                            <input type="hidden" name="page" value="dssa-member-management">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label for="member-search"><?php _e('Search:', 'dssa-pmpro-helper'); ?></label>
                                    <input type="text"
                                        id="member-search"
                                        name="s"
                                        value="<?php echo isset($_GET['s']) ? esc_attr($_GET['s']) : ''; ?>"
                                        placeholder="<?php esc_attr_e('Name, email, or membership number', 'dssa-pmpro-helper'); ?>">
                                </div>
                                <div class="filter-group">
                                    <label for="member-type"><?php _e('Member Type:', 'dssa-pmpro-helper'); ?></label>
                                    <select id="member-type" name="member_type">
                                        <option value=""><?php _e('All', 'dssa-pmpro-helper'); ?></option>
                                        <option value="legacy" <?php selected(isset($_GET['member_type']) && $_GET['member_type'] == 'legacy'); ?>>
                                            <?php _e('Legacy', 'dssa-pmpro-helper'); ?>
                                        </option>
                                        <option value="new" <?php selected(isset($_GET['member_type']) && $_GET['member_type'] == 'new'); ?>>
                                            <?php _e('New', 'dssa-pmpro-helper'); ?>
                                        </option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label for="member-status"><?php _e('Status:', 'dssa-pmpro-helper'); ?></label>
                                    <select id="member-status" name="member_status">
                                        <option value=""><?php _e('All', 'dssa-pmpro-helper'); ?></option>
                                        <option value="active" <?php selected(isset($_GET['member_status']) && $_GET['member_status'] == 'active'); ?>>
                                            <?php _e('Active', 'dssa-pmpro-helper'); ?>
                                        </option>
                                        <option value="pending" <?php selected(isset($_GET['member_status']) && $_GET['member_status'] == 'pending'); ?>>
                                            <?php _e('Pending', 'dssa-pmpro-helper'); ?>
                                        </option>
                                    </select>
                                </div>
                                <div class="filter-actions">
                                    <button type="submit" class="button button-primary">
                                        <?php _e('Apply Filters', 'dssa-pmpro-helper'); ?>
                                    </button>
                                    <a href="<?php echo admin_url('admin.php?page=dssa-member-management'); ?>" class="button">
                                        <?php _e('Reset', 'dssa-pmpro-helper'); ?>
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <div class="dssa-member-table-container">
                        <?php
                        // Prepare query args
                        $query_args = [
                            'number' => 50,
                            'paged' => isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1,
                        ];
                        
                        // Apply search
                        if (!empty($_GET['s'])) {
                            $search = sanitize_text_field($_GET['s']);
                            $query_args['search'] = '*' . $search . '*';
                            $query_args['search_columns'] = ['user_login', 'user_email', 'display_name', 'user_nicename'];
                        }
                        
                        // Apply member type filter
                        if (!empty($_GET['member_type'])) {
                            if ($_GET['member_type'] == 'legacy') {
                                $query_args['meta_key'] = 'dssa_membership_number';
                                $query_args['meta_compare'] = 'EXISTS';
                            } elseif ($_GET['member_type'] == 'new') {
                                $query_args['meta_key'] = 'dssa_membership_number';
                                $query_args['meta_compare'] = 'NOT EXISTS';
                            }
                        }
                        
                        // Apply status filter (simplified)
                        if (!empty($_GET['member_status'])) {
                            if ($_GET['member_status'] == 'active') {
                                $query_args['meta_query'][] = [
                                    'key' => 'pmpro_approval_status',
                                    'value' => 'approved',
                                ];
                            } elseif ($_GET['member_status'] == 'pending') {
                                $query_args['meta_query'][] = [
                                    'key' => 'pmpro_approval_status',
                                    'value' => 'pending',
                                ];
                            }
                        }
                        
                        // Apply branch filter if set via GET
                        if (!empty($_GET['filter_branch'])) {
                            $query_args['meta_query'][] = [
                                'key' => 'branch',
                                'value' => sanitize_text_field($_GET['filter_branch']),
                            ];
                        } elseif ($filter_unassigned) {
                            $query_args['meta_query'][] = [
                                'key' => 'branch',
                                'compare' => 'NOT EXISTS',
                            ];
                        }
                        
                        // Get users
                        $user_query = new WP_User_Query($query_args);
                        $users = $user_query->get_results();
                        $total_users = $user_query->get_total();
                        $total_pages = ceil($total_users / $query_args['number']);
                        ?>
                        <table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<td class="check-column">
										<input type="checkbox" id="dssa-select-all">
									</td>
									<th><?php _e('User', 'dssa-pmpro-helper'); ?></th>
									<th><?php _e('Email', 'dssa-pmpro-helper'); ?></th>
									<th><?php _e('Membership Number', 'dssa-pmpro-helper'); ?></th>
									<th><?php _e('Branch', 'dssa-pmpro-helper'); ?></th>
									<th><?php _e('Type', 'dssa-pmpro-helper'); ?></th>
									<th><?php _e('Status', 'dssa-pmpro-helper'); ?></th>
									<th><?php _e('Actions', 'dssa-pmpro-helper'); ?></th>
								</tr>
							</thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="8" class="no-members">
                                            <?php _e('No members found.', 'dssa-pmpro-helper'); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): 
                                        $membership_number = get_user_meta($user->ID, 'dssa_membership_number', true);
                                        $branch = get_user_meta($user->ID, 'branch', true);
                                        $approval_status = get_user_meta($user->ID, 'pmpro_approval_status', true);
                                        
                                        // Determine member type
                                        $member_type = !empty($membership_number) ? 'Legacy' : 'New';
                                        
                                        // Determine status
                                        $status = !empty($approval_status) ? ucfirst($approval_status) : 'Active';
                                        
                                        // Get user's membership level
                                        $level = pmpro_getMembershipLevelForUser($user->ID);
                                        $level_name = $level ? $level->name : 'None';
                                        ?>
                                        <tr>
											<td class="check-column">
												<input type="checkbox"
													   class="dssa-member-checkbox"
													   name="user_ids[]"
													   value="<?php echo esc_attr( $user->ID ); ?>">
											</td>
                                            <td>
                                                <strong>
                                                    <a href="<?php echo admin_url('user-edit.php?user_id=' . $user->ID); ?>">
                                                        <?php echo esc_html($user->display_name); ?>
                                                    </a>
                                                </strong>
                                                <br>
                                                <small><?php echo esc_html($user->user_login); ?></small>
                                            </td>
                                            <td>
                                                <a href="mailto:<?php echo esc_attr($user->user_email); ?>">
                                                    <?php echo esc_html($user->user_email); ?>
                                                </a>
                                            </td>
                                            <td>
												<span
													class="dssa-inline-edit"
													data-user-id="<?php echo esc_attr( $user->ID ); ?>"
													data-field="membership_number"
												>
													<?php echo $membership_number ? esc_html( $membership_number ) : '— Click to assign —'; ?>
												</span>
                                            </td>
                                            <td>
												<span
													class="dssa-inline-edit"
													data-user-id="<?php echo esc_attr( $user->ID ); ?>"
													data-field="branch"
												>
													<?php echo $branch ? esc_html( $branch ) : '— Click to assign —'; ?>
												</span>
                                            </td>
                                            <td>
                                                <span class="member-type-<?php echo strtolower($member_type); ?>">
                                                    <?php echo esc_html($member_type); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-<?php echo strtolower($status); ?>">
                                                    <?php echo esc_html($status); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="row-actions">
                                                    <a href="<?php echo esc_url(
														admin_url( 'admin.php?page=pmpro-member&user_id=' . $user->ID )
													); ?>" class="button button-small">
														<?php _e( 'Edit', 'dssa-pmpro-helper' ); ?>
													</a>
                                                    <?php if (empty($membership_number)): ?>
                                                        <button type="button"
                                                            class="button button-small assign-number-btn"
                                                            data-user-id="<?php echo esc_attr($user->ID); ?>"
                                                            data-user-name="<?php echo esc_attr($user->display_name); ?>">
                                                            <?php _e('Assign Number', 'dssa-pmpro-helper'); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (empty($branch)): ?>
                                                        <button type="button"
                                                            class="button button-small assign-branch-btn"
                                                            data-user-id="<?php echo esc_attr($user->ID); ?>"
                                                            data-user-name="<?php echo esc_attr($user->display_name); ?>">
                                                            <?php _e('Assign Branch', 'dssa-pmpro-helper'); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="tablenav bottom">
                                <div class="tablenav-pages">
                                    <?php
                                    $current_page = $query_args['paged'];
                                    $page_links = paginate_links([
                                        'base' => add_query_arg('paged', '%#%'),
                                        'format' => '',
                                        'prev_text' => __('&laquo;', 'dssa-pmpro-helper'),
                                        'next_text' => __('&raquo;', 'dssa-pmpro-helper'),
                                        'total' => $total_pages,
                                        'current' => $current_page,
                                        'type' => 'array',
                                    ]);
                                    
                                    if ($page_links) {
                                        echo '<span class="displaying-num">' . sprintf(
                                            __('Displaying %1$s&#8211;%2$s of %3$s', 'dssa-pmpro-helper'),
                                            ($current_page - 1) * $query_args['number'] + 1,
                                            min($current_page * $query_args['number'], $total_users),
                                            $total_users
                                        ) . '</span>';
                                        echo '<span class="pagination-links">' . join("\n", $page_links) . '</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Bulk Actions -->
                    <div class="dssa-bulk-actions">
                        <select id="dssa-member-bulk-action">
                            <option value=""><?php _e('Bulk Actions', 'dssa-pmpro-helper'); ?></option>
                            <option value="export"><?php _e('Export Selected', 'dssa-pmpro-helper'); ?></option>
                            <option value="send_welcome"><?php _e('Send Welcome Email', 'dssa-pmpro-helper'); ?></option>
                            <option value="reset_password"><?php _e('Reset Password', 'dssa-pmpro-helper'); ?></option>
                        </select>
                        <button type="button" class="button" id="dssa-apply-member-bulk-action">
                            <?php _e('Apply', 'dssa-pmpro-helper'); ?>
                        </button>
                        <a href="<?php echo esc_url(
							admin_url( 'admin-post.php?action=dssa_export_members' )
						); ?>" class="button button-secondary">
							Export All
						</a>
                    </div>
                </div>
            </div>
            <style>
                .dssa-member-management {
                    display: grid;
                    gap: 20px;
                }
                .dssa-member-stats .dssa-stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                    gap: 15px;
                    margin: 20px 0;
                }
                .dssa-member-filters {
                    margin: 20px 0;
                    padding: 15px;
                    background: #f9f9f9;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                }
                .filter-row {
                    display: flex;
                    gap: 15px;
                    align-items: flex-end;
                    flex-wrap: wrap;
                }
                .filter-group {
                    display: flex;
                    flex-direction: column;
                    gap: 5px;
                    flex: 1;
                    min-width: 200px;
                }
                .filter-group label {
                    font-weight: 600;
                    font-size: 13px;
                }
                .filter-actions {
                    display: flex;
                    gap: 10px;
                    align-items: flex-end;
                }
                .dssa-member-table-container {
                    margin: 20px 0;
                    overflow-x: auto;
                }
                .no-members {
                    text-align: center;
                    padding: 40px !important;
                    color: #666;
                    font-style: italic;
                }
                .branch-tag {
                    display: inline-block;
                    padding: 2px 8px;
                    background: #e0f2fe;
                    color: #0369a1;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: 500;
                }
                .member-type-legacy {
                    color: #059669;
                    font-weight: 500;
                }
                .member-type-new {
                    color: #7c3aed;
                    font-weight: 500;
                }
                .status-active {
                    color: #059669;
                    font-weight: 500;
                }
                .status-pending {
                    color: #d97706;
                    font-weight: 500;
                }
                .row-actions {
                    display: flex;
                    gap: 5px;
                    flex-wrap: wrap;
                }
                .dssa-bulk-actions {
                    display: flex;
                    gap: 10px;
                    margin-top: 20px;
                    align-items: center;
                }
                @media (max-width: 1024px) {
                    .filter-row {
                        flex-direction: column;
                        align-items: stretch;
                    }
                    .filter-group {
                        min-width: auto;
                    }
                    .dssa-member-stats .dssa-stats-grid {
                        grid-template-columns: repeat(2, 1fr);
                    }
                }
                @media (max-width: 768px) {
                    .dssa-member-stats .dssa-stats-grid {
                        grid-template-columns: 1fr;
                    }
                    .row-actions {
                        flex-direction: column;
                    }
                    .row-actions .button {
                        width: 100%;
                        text-align: center;
                    }
                }
            </style>
            
        </div>
        <?php
    }
	
	public function render_member_management_modals() {

		?>

		<div id="dssa-admin-modals-root">
		    
            <!-- Assign Number Modal -->
			<div id="dssa-assign-number-modal" class="dssa-modal">
				<div class="dssa-modal-overlay"></div>
				<div class="dssa-modal-content">
					<span class="dssa-modal-close">&times;</span>
					<h3><?php _e( 'Assign Membership Number', 'dssa-pmpro-helper' ); ?></h3>

					<p><strong id="dssa-assign-number-user-name"></strong></p>

					<input type="hidden" id="dssa-assign-number-user-id">
					<input type="text" id="dssa-membership-number">

					<button class="button button-primary" id="dssa-save-membership-number">
						<?php _e( 'Assign Number', 'dssa-pmpro-helper' ); ?>
					</button>
				</div>
			</div>
			
            <!-- Assign Branch Modal -->
			<div id="dssa-assign-branch-modal" class="dssa-modal">
				<div class="dssa-modal-overlay"></div>
				<div class="dssa-modal-content">
					<span class="dssa-modal-close">&times;</span>
					<h3><?php _e( 'Assign Branch', 'dssa-pmpro-helper' ); ?></h3>

					<p><strong id="dssa-assign-branch-user-name"></strong></p>

					<input type="hidden" id="dssa-assign-branch-user-id">

					<select id="branch-select">
						<option value=""><?php _e( 'Select branch', 'dssa-pmpro-helper' ); ?></option>
						<?php foreach ( dssa_get_branches() as $branch ) : ?>
							<option value="<?php echo esc_attr( $branch ); ?>">
								<?php echo esc_html( $branch ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<button class="button button-primary" id="dssa-save-branch">
						<?php _e( 'Assign Branch', 'dssa-pmpro-helper' ); ?>
					</button>
				</div>
			</div>

		</div>

		<?php
	}
    
    /**
    * Render reports page
    */
    public function render_reports_page() {
        // Get date range for reports
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-01');
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-t');
        ?>
        <div class="wrap dssa-admin-wrap">
            <h1><?php _e('Reports', 'dssa-pmpro-helper'); ?></h1>
            <div class="dssa-reports">
                <!-- Date Range Filter -->
                <div class="dssa-card">
                    <h2><?php _e('Report Filters', 'dssa-pmpro-helper'); ?></h2>
                    <form method="get" action="<?php echo admin_url('admin.php'); ?>" class="dssa-report-filters">
                        <input type="hidden" name="page" value="dssa-reports">
                        <div class="filter-row">
                            <div class="filter-group">
                                <label for="start-date"><?php _e('Start Date:', 'dssa-pmpro-helper'); ?></label>
                                <input type="date"
                                    id="start-date"
                                    name="start_date"
                                    value="<?php echo esc_attr($start_date); ?>"
                                    max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="filter-group">
                                <label for="end-date"><?php _e('End Date:', 'dssa-pmpro-helper'); ?></label>
                                <input type="date"
                                    id="end-date"
                                    name="end_date"
                                    value="<?php echo esc_attr($end_date); ?>"
                                    max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="filter-group">
                                <label for="report-type"><?php _e('Report Type:', 'dssa-pmpro-helper'); ?></label>
                                <select id="report-type" name="report_type">
                                    <option value="membership"><?php _e('Membership Statistics', 'dssa-pmpro-helper'); ?></option>
                                    <option value="financial"><?php _e('Financial Reports', 'dssa-pmpro-helper'); ?></option>
                                    <option value="renewals"><?php _e('Renewal Projections', 'dssa-pmpro-helper'); ?></option>
                                </select>
                            </div>
                            <div class="filter-actions">
                                <button type="submit" class="button button-primary">
                                    <?php _e('Generate Report', 'dssa-pmpro-helper'); ?>
                                </button>
                                <button type="button" class="button" id="dssa-export-report">
                                    <?php _e('Export to CSV', 'dssa-pmpro-helper'); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Membership Statistics Report -->
                <div class="dssa-card">
                    <h2><?php _e('Membership Statistics', 'dssa-pmpro-helper'); ?></h2>
                    <?php
                    // Get membership statistics
                    $stats = self::get_membership_statistics($start_date, $end_date);
                    ?>
                    <div class="dssa-stats-overview">
                        <div class="dssa-stats-grid">
                            <div class="stat-box">
                                <h3><?php _e('Total Members', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($stats['total_members']); ?></p>
                            </div>
                            <div class="stat-box">
                                <h3><?php _e('New Registrations', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($stats['new_registrations']); ?></p>
                            </div>
                            <div class="stat-box">
                                <h3><?php _e('Legacy Members', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($stats['legacy_members']); ?></p>
                            </div>
                            <div class="stat-box">
                                <h3><?php _e('Active Members', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($stats['active_members']); ?></p>
                            </div>
                            <div class="stat-box">
                                <h3><?php _e('Pending Approval', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($stats['pending_members']); ?></p>
                            </div>
                            <div class="stat-box">
                                <h3><?php _e('Growth Rate', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($stats['growth_rate']); ?>%</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Membership Levels Breakdown -->
                    <div class="dssa-chart-section">
                        <h3><?php _e('Membership Levels Breakdown', 'dssa-pmpro-helper'); ?></h3>
                        <div class="dssa-chart-container">
                            <canvas id="membershipLevelsChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Renewal Projections -->
                <div class="dssa-card">
                    <h2><?php _e('Renewal Projections', 'dssa-pmpro-helper'); ?></h2>
                    <?php
                    // Get renewal projections
                    $projections = self::get_renewal_projections();
                    ?>
                    <div class="dssa-renewal-projections">
                        <div class="dssa-stats-grid">
                            <div class="stat-box">
                                <h3><?php _e('Next 30 Days', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($projections['30_days']['count']); ?></p>
                                <p class="stat-subtitle">R <?php echo esc_html(number_format($projections['30_days']['value'], 2)); ?></p>
                            </div>
                            <div class="stat-box">
                                <h3><?php _e('Next 60 Days', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($projections['60_days']['count']); ?></p>
                                <p class="stat-subtitle">R <?php echo esc_html(number_format($projections['60_days']['value'], 2)); ?></p>
                            </div>
                            <div class="stat-box">
                                <h3><?php _e('Next 90 Days', 'dssa-pmpro-helper'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($projections['90_days']['count']); ?></p>
                                <p class="stat-subtitle">R <?php echo esc_html(number_format($projections['90_days']['value'], 2)); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Export Options -->
                <div class="dssa-card">
                    <h2><?php _e('Export Data', 'dssa-pmpro-helper'); ?></h2>
                    <div class="dssa-export-options">
                        <p><?php _e('Export member data for reporting or backup purposes:', 'dssa-pmpro-helper'); ?></p>
                        <div class="dssa-export-buttons">
                            <a href="<?php echo wp_nonce_url(
                                admin_url('admin-ajax.php?action=dssa_export_members&type=all'),
                                'dssa_export_members'
                            ); ?>" class="button button-primary">
                                <?php _e('Export All Members', 'dssa-pmpro-helper'); ?>
                            </a>
                        </div>
                        <p class="description">
                            <?php _e('Exports include: Name, Email, Membership Number, Branch, Membership Level, Status, Join Date.', 'dssa-pmpro-helper'); ?>
                        </p>
                    </div>
                </div>
            </div>
            <style>
                .dssa-reports {
                    display: grid;
                    gap: 20px;
                }
                .dssa-report-filters {
                    margin: 20px 0;
                }
                .dssa-report-filters .filter-row {
                    display: flex;
                    gap: 15px;
                    align-items: flex-end;
                    flex-wrap: wrap;
                }
                .dssa-report-filters .filter-group {
                    display: flex;
                    flex-direction: column;
                    gap: 5px;
                    flex: 1;
                    min-width: 150px;
                }
                .dssa-report-filters .filter-group label {
                    font-weight: 600;
                    font-size: 13px;
                }
                .dssa-report-filters .filter-actions {
                    display: flex;
                    gap: 10px;
                    align-items: flex-end;
                }
                .dssa-stats-overview .dssa-stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                    gap: 15px;
                    margin: 20px 0;
                }
                .dssa-chart-section {
                    margin: 30px 0;
                    padding-top: 30px;
                    border-top: 1px solid #eee;
                }
                .dssa-chart-section h3 {
                    margin-top: 0;
                    color: #444;
                }
                .dssa-chart-container {
                    margin: 20px 0;
                    max-width: 800px;
                    height: 300px;
                }
                .dssa-renewal-projections .dssa-stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
                    gap: 15px;
                    margin: 20px 0;
                }
                .dssa-renewal-projections .stat-subtitle {
                    font-size: 14px;
                    color: #666;
                    margin: 5px 0 0 0;
                }
                .dssa-export-options {
                    margin: 20px 0;
                }
                .dssa-export-buttons {
                    display: flex;
                    gap: 10px;
                    margin: 15px 0;
                    flex-wrap: wrap;
                }
                @media (max-width: 1024px) {
                    .dssa-report-filters .filter-row {
                        flex-direction: column;
                        align-items: stretch;
                    }
                    .dssa-report-filters .filter-group {
                        min-width: auto;
                    }
                    .dssa-stats-overview .dssa-stats-grid,
                    .dssa-renewal-projections .dssa-stats-grid {
                        grid-template-columns: repeat(2, 1fr);
                    }
                    .dssa-export-buttons {
                        flex-direction: column;
                    }
                    .dssa-export-buttons .button {
                        width: 100%;
                        text-align: center;
                    }
                }
                @media (max-width: 768px) {
                    .dssa-stats-overview .dssa-stats-grid,
                    .dssa-renewal-projections .dssa-stats-grid {
                        grid-template-columns: 1fr;
                    }
                    .dssa-chart-container {
                        height: 250px;
                    }
                }
            </style>
            
            <!-- Chart.js for reports -->
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <script>
            jQuery(document).ready(function($) {
                // Initialize charts
                <?php
                // Prepare data for membership levels chart
                $level_labels = [];
                $level_counts = [];
                $level_colors = [
                    '#3498db', // Blue
                    '#2ecc71', // Green
                    '#e74c3c', // Red
                    '#f39c12', // Orange
                    '#9b59b6', // Purple
                ];
                $i = 0;
                $pmpro_levels = pmpro_getAllLevels(true, true);
                foreach ($pmpro_levels as $level) {
                    $level_labels[] = $level->name;
                    $level_counts[] = $level->members;
                    $i++;
                }
                ?>
                // Membership Levels Chart
                var membershipLevelsCtx = document.getElementById('membershipLevelsChart').getContext('2d');
                var membershipLevelsChart = new Chart(membershipLevelsCtx, {
                    type: 'pie',
                    data: {
                        labels: <?php echo json_encode($level_labels); ?>,
                        datasets: [{
                            data: <?php echo json_encode($level_counts); ?>,
                            backgroundColor: <?php echo json_encode(array_slice($level_colors, 0, count($level_labels))); ?>,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                            },
                            title: {
                                display: true,
                                text: 'Membership Level Distribution'
                            }
                        }
                    }
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
    * Helper: Get membership statistics
    */
    private static function get_membership_statistics($start_date, $end_date) {
        global $wpdb;
        
        // Convert dates to timestamps
        $start_timestamp = strtotime($start_date . ' 00:00:00');
        $end_timestamp = strtotime($end_date . ' 23:59:59');
        
        // Total members
        $total_members = count_users();
        $total_members = $total_members['total_users'];
        
        // New registrations in date range
        $new_registrations = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users}
            WHERE user_registered >= %s AND user_registered <= %s",
            date('Y-m-d H:i:s', $start_timestamp),
            date('Y-m-d H:i:s', $end_timestamp)
        ));
        
        // Legacy members
        $legacy_members = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'dssa_membership_number'"
        );
        
        // Active members (simplified - has PMPro membership)
        $active_members = $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->pmpro_memberships_users}
            WHERE status = 'active'"
        );
        
        // Pending members
        $pending_members = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta}
            WHERE meta_key = 'pmpro_approval_status' AND meta_value = 'pending'"
        );
        
        // Growth rate (vs previous period)
        $prev_start = date('Y-m-d', strtotime($start_date . ' -1 month'));
        $prev_end = date('Y-m-d', strtotime($end_date . ' -1 month'));
        $prev_registrations = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users}
            WHERE user_registered >= %s AND user_registered <= %s",
            $prev_start . ' 00:00:00',
            $prev_end . ' 23:59:59'
        ));
        $growth_rate = $prev_registrations > 0 ? 
            round((($new_registrations - $prev_registrations) / $prev_registrations) * 100, 1) :
            ($new_registrations > 0 ? 100 : 0);
        
        // Membership levels breakdown
        $levels = [];
        $pmpro_levels = pmpro_getAllLevels(true, true);
        foreach ($pmpro_levels as $level) {
            $level_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->pmpro_memberships_users}
                WHERE membership_id = %d AND status = 'active'",
                $level->id
            ));
            
            // Determine annual value based on level ID
            $annual_prices = [
                1 => 300, // Level 1
                2 => 100, // Level 2
                3 => 90,  // Level 3
                4 => 500, // Level 4
            ];
            $annual_value = isset($annual_prices[$level->id]) ? $annual_prices[$level->id] * $level_count : 0;
            
            $levels[$level->name] = [
                'count' => (int)$level_count,
                'annual_value' => $annual_value,
            ];
        }
        
        return [
            'total_members' => (int)$total_members,
            'new_registrations' => (int)$new_registrations,
            'legacy_members' => (int)$legacy_members,
            'active_members' => (int)$active_members,
            'pending_members' => (int)$pending_members,
            'growth_rate' => $growth_rate,
            'levels' => $levels,
        ];
    }
    
    /**
    * Helper: Get renewal projections
    */
    private static function get_renewal_projections() {
        global $wpdb;
        
        // Get renewal date from settings
        $renewal_month = get_option('dssa_pmpro_helper_annual_renewal_month', 3);
        $renewal_day = get_option('dssa_pmpro_helper_annual_renewal_day', 1);
        $current_year = date('Y');
        $next_year = date('Y', strtotime('+1 year'));
        
        // Calculate next renewal date
        $next_renewal = strtotime("$current_year-$renewal_month-$renewal_day");
        if ($next_renewal < current_time('timestamp')) {
            $next_renewal = strtotime("$next_year-$renewal_month-$renewal_day");
        }
        
        // Get active members with end dates
        $active_members = $wpdb->get_results(
            "SELECT user_id, enddate FROM {$wpdb->pmpro_memberships_users}
            WHERE status = 'active' AND enddate IS NOT NULL
            ORDER BY enddate"
        );
        
        $projections = [
            '30_days' => ['count' => 0, 'value' => 0],
            '60_days' => ['count' => 0, 'value' => 0],
            '90_days' => ['count' => 0, 'value' => 0],
            '180_days' => ['count' => 0, 'value' => 0],
            'upcoming_renewals' => [],
        ];
        
        $now = current_time('timestamp');
        $thirty_days = strtotime('+30 days', $now);
        $sixty_days = strtotime('+60 days', $now);
        $ninety_days = strtotime('+90 days', $now);
        $one_eighty_days = strtotime('+180 days', $now);
        
        foreach ($active_members as $member) {
            $end_timestamp = strtotime($member->enddate);
            $days_until_renewal = ceil(($end_timestamp - $now) / DAY_IN_SECONDS);
            
            // Get user info
            $user = get_user_by('id', $member->user_id);
            if (!$user) continue;
            
            // Get membership level
            $level = pmpro_getMembershipLevelForUser($member->user_id);
            if (!$level) continue;
            
            // Determine amount due based on level ID
            $annual_prices = [
                1 => 300, // Level 1
                2 => 100, // Level 2
                3 => 90,  // Level 3
                4 => 500, // Level 4
            ];
            $amount_due = isset($annual_prices[$level->id]) ? $annual_prices[$level->id] : 0;
            
            // Categorize by timeframe
            if ($end_timestamp <= $thirty_days && $end_timestamp > $now) {
                $projections['30_days']['count']++;
                $projections['30_days']['value'] += $amount_due;
                
                // Add to upcoming renewals list
                $projections['upcoming_renewals'][] = [
                    'user_id' => $member->user_id,
                    'member_name' => $user->display_name,
                    'email' => $user->user_email,
                    'level_name' => $level->name,
                    'renewal_date' => date('Y-m-d', $end_timestamp),
                    'amount_due' => $amount_due,
                    'status' => 'upcoming',
                ];
            }
            if ($end_timestamp <= $sixty_days && $end_timestamp > $now) {
                $projections['60_days']['count']++;
                $projections['60_days']['value'] += $amount_due;
            }
            if ($end_timestamp <= $ninety_days && $end_timestamp > $now) {
                $projections['90_days']['count']++;
                $projections['90_days']['value'] += $amount_due;
            }
            if ($end_timestamp <= $one_eighty_days && $end_timestamp > $now) {
                $projections['180_days']['count']++;
                $projections['180_days']['value'] += $amount_due;
            }
        }
        
        return $projections;
    }
    
    /**
    * AJAX: Assign membership number
    */
	public function ajax_assign_membership_number() {
		check_ajax_referer( 'dssa_assign_number', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$user_id = absint( $_POST['user_id'] ?? 0 );
		$number  = sanitize_text_field( $_POST['membership_number'] ?? '' );

		if ( ! $user_id || $number === '' ) {
			wp_send_json_error( 'Invalid data provided' );
		}

		update_user_meta( $user_id, 'dssa_membership_number', $number );

		wp_send_json_success();
	}
    
    /**
    * AJAX: Assign branch
    */
	public function ajax_assign_branch() {
		check_ajax_referer( 'dssa_assign_branch', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$user_id = absint( $_POST['user_id'] ?? 0 );
		$branch  = sanitize_text_field( $_POST['branch'] ?? '' );

		if ( ! $user_id || $branch === '' ) {
			wp_send_json_error( 'Invalid data provided' );
		}

		update_user_meta( $user_id, 'dssa_branch', $branch );

		wp_send_json_success();
	}
	
	/**
	 * Export members
	 */
	public function export_members_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}

		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename=dssa-members.csv' );

		$output = fopen( 'php://output', 'w' );

		fputcsv( $output, [
			'User ID',
			'Name',
			'Email',
			'Membership Number',
			'Branch',
		] );

		foreach ( get_users() as $user ) {
			fputcsv( $output, [
				$user->ID,
				$user->display_name,
				$user->user_email,
				get_user_meta( $user->ID, 'dssa_membership_number', true ),
				get_user_meta( $user->ID, 'dssa_branch', true ),
			] );
		}

		fclose( $output );
		exit;
	}
}

add_action( 'admin_footer', function () {
	?>
	<div id="dssa-assign-number-modal" style="
		display:none;
		position:fixed;
		inset:0;
		z-index:999999;
	">
		<div style="
			position:absolute;
			inset:0;
			background:rgba(0,0,0,0.6);
		"></div>

		<div style="
			position:absolute;
			top:50%;
			left:50%;
			transform:translate(-50%,-50%);
			background:#fff;
			padding:20px;
			width:400px;
			box-shadow:0 10px 40px rgba(0,0,0,0.4);
		">
			<h3>Assign Membership Number</h3>
			<input type="text" id="dssa-membership-number">
			<button class="button button-primary">Assign</button>
		</div>
	</div>
	<?php
});