<?php
/**
 * Settings class for DSSA PMPro Helper
 * 
 * This class handles the plugin settings interface with a tab-based layout.
 * It has been restructured to work properly with WordPress Settings API
 * by using full page reloads instead of AJAX for tab switching.
 */
if (!defined('ABSPATH')) {
    exit;
}

class DSSA_PMPro_Helper_Settings {
    private static $instance = null;
    private static $current_tab = 'general';
    
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
        
        // Set current tab
        $possible_tabs = ['general', 'email', 'dates', 'paystack', 'validation', 'renewal'];
        if (isset($_GET['tab']) && in_array($_GET['tab'], $possible_tabs)) {
            self::$current_tab = sanitize_text_field($_GET['tab']);
        }
        
        // Register settings
        add_action('admin_init', [$instance, 'register_settings']);
        return $instance;
    }
    
    /**
     * Get available tabs
     */
    public static function get_tabs() {
        return [
            'general'   => __('General Settings', 'dssa-pmpro-helper'),
            'email'     => __('Email Settings', 'dssa-pmpro-helper'),
            'dates'     => __('Date & Renewal Settings', 'dssa-pmpro-helper'),
            'paystack'  => __('Paystack Fee Settings', 'dssa-pmpro-helper'),
            'validation' => __('Validation Messages', 'dssa-pmpro-helper'),
            'renewal'   => __('Renewal Processing', 'dssa-pmpro-helper'),
        ];
    }
    
    /**
     * Display settings page
     */
    public static function display_settings_page() {
        $instance = self::get_instance();
        $tabs = self::get_tabs();
        $current_tab = self::$current_tab;
        ?>
        <div class="wrap dssa-settings-wrap">
            <h1><?php _e('DSSA PMPro Helper Settings', 'dssa-pmpro-helper'); ?></h1>
            
            <!-- Tab navigation -->
            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_key => $tab_label): ?>
                    <a href="<?php echo admin_url('admin.php?page=dssa-settings&tab=' . esc_attr($tab_key)); ?>"
                       class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>"
                       data-tab="<?php echo esc_attr($tab_key); ?>">
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            
            <div class="dssa-settings-content" id="dssa-settings-content">
                <form method="post" action="options.php">
                    <?php
                    // Output security fields
                    settings_fields('dssa_pmpro_helper_' . $current_tab . '_group');
                    // Output settings section
                    do_settings_sections('dssa_pmpro_helper_' . $current_tab);
                    // Submit button
                    submit_button();
                    ?>
                </form>
                
                <?php if ($current_tab === 'paystack'): ?>
                    <div class="paystack-calculator" style="margin-top: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                        <h3 style="margin-top: 0;"><?php _e('Fee Calculator', 'dssa-pmpro-helper'); ?></h3>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                                <?php _e('Example Calculation:', 'dssa-pmpro-helper'); ?>
                            </label>
                            <p>
                                <?php
                                $example_amount = 300;
                                $percentage = get_option('dssa_pmpro_helper_paystack_percentage', 2.9) / 100;
                                $fixed = get_option('dssa_pmpro_helper_paystack_fixed', 1.00);
                                $total = ($example_amount + $fixed) / (1 - $percentage);
                                $fees = $total - $example_amount;
                                $fee_percentage = ($fees / $total) * 100;
                                echo sprintf(
                                    __('For a membership fee of <strong>R %s</strong>:', 'dssa-pmpro-helper'),
                                    number_format($example_amount, 2)
                                );
                                ?>
                            </p>
                            <ul style="margin-left: 20px;">
                                <li><?php echo sprintf(__('Paystack fees: <strong>R %s</strong> (%s%%)', 'dssa-pmpro-helper'), number_format($fees, 2), number_format($fee_percentage, 2)); ?></li>
                                <li><?php echo sprintf(__('Total to charge member: <strong>R %s</strong>', 'dssa-pmpro-helper'), number_format($total, 2)); ?></li>
                                <li><?php echo sprintf(__('Formula: (%s + %s) / (1 - %s) = %s', 'dssa-pmpro-helper'), number_format($example_amount, 2), number_format($fixed, 2), number_format($percentage, 3), number_format($total, 2)); ?></li>
                            </ul>
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                                <?php _e('Test Your Own Amount:', 'dssa-pmpro-helper'); ?>
                            </label>
                            <p>
                                <input type="number" id="test_amount" value="300" step="0.01" min="0" style="width: 120px; margin-right: 10px;">
                                <button type="button" id="calculate_fees" class="button"><?php _e('Calculate', 'dssa-pmpro-helper'); ?></button>
                            </p>
                            <div id="calculation_results" style="display: none; margin-top: 15px; padding: 15px; background: #fff; border: 1px solid #ccc; border-radius: 3px;">
                                <h4 style="margin-top: 0;"><?php _e('Results:', 'dssa-pmpro-helper'); ?></h4>
                                <div id="results_content"></div>
                            </div>
                        </div>
                    </div>
                    
                    <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        // Paystack fee calculator
                        $('#calculate_fees').on('click', function() {
                            var amount = parseFloat($('#test_amount').val());
                            var percentageElement = $('#dssa_pmpro_helper_paystack_percentage');
                            var fixedElement = $('#dssa_pmpro_helper_paystack_fixed');
                            
                            // Check if elements exist
                            if (percentageElement.length === 0 || fixedElement.length === 0) {
                                console.error('Paystack fields not found');
                                alert('<?php _e("Paystack settings fields not found. Please save settings first.", "dssa-pmpro-helper"); ?>');
                                return;
                            }
                            
                            var percentage = parseFloat(percentageElement.val()) / 100;
                            var fixed = parseFloat(fixedElement.val());
                            
                            if (isNaN(amount) || amount <= 0) {
                                alert('<?php _e("Please enter a valid amount.", "dssa-pmpro-helper"); ?>');
                                return;
                            }
                            
                            if (isNaN(percentage) || isNaN(fixed)) {
                                alert('<?php _e("Please save Paystack settings first.", "dssa-pmpro-helper"); ?>');
                                return;
                            }
                            
                            // Calculate total to charge customer so DSSA receives exact amount
                            // T = (M + f) / (1 - p)
                            var total = (amount + fixed) / (1 - percentage);
                            var fees = total - amount;
                            var fee_percentage = (fees / total) * 100;
                            
                            $('#results_content').html(
                                '<p><strong><?php _e("Amount DSSA receives:", "dssa-pmpro-helper"); ?></strong> R ' + amount.toFixed(2) + '</p>' +
                                '<p><strong><?php _e("Paystack fees:", "dssa-pmpro-helper"); ?></strong> R ' + fees.toFixed(2) + ' (' + fee_percentage.toFixed(2) + '%)</p>' +
                                '<p><strong><?php _e("Total to charge member:", "dssa-pmpro-helper"); ?></strong> R ' + total.toFixed(2) + '</p>' +
                                '<p><strong><?php _e("Breakdown:", "dssa-pmpro-helper"); ?></strong> (' + amount.toFixed(2) + ' + ' + fixed.toFixed(2) + ') / (1 - ' + percentage.toFixed(3) + ') = ' + total.toFixed(2) + '</p>'
                            );
                            $('#calculation_results').show();
                        });
                    });
                    </script>
                <?php endif; ?>
                
                <?php $instance->enqueue_scripts(); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Enqueue scripts
     */
    private function enqueue_scripts() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Tab switching with page reload (maintains WordPress settings API compatibility)
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                // Update URL and reload page
                window.location.href = '<?php echo admin_url('admin.php?page=dssa-settings&tab='); ?>' + tab;
            });
        });
        </script>
        <style>
        .dssa-settings-wrap {
            max-width: 1200px;
        }
        .nav-tab-wrapper {
            margin-bottom: 20px;
        }
        .dssa-settings-content {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            min-height: 300px;
        }
        .form-table th {
            width: 250px;
        }
        .paystack-calculator {
            max-width: 600px;
        }
        </style>
        <?php
    }
    
    /**
     * Register all settings
     */
    public function register_settings() {
        // ============================================
        // GENERAL SETTINGS
        // ============================================
        register_setting('dssa_pmpro_helper_general_group', 'dssa_pmpro_helper_enable_debug_logging');
        register_setting('dssa_pmpro_helper_general_group', 'dssa_pmpro_helper_enable_audit_log');
        register_setting('dssa_pmpro_helper_general_group', 'dssa_pmpro_helper_data_retention_years', [
            'sanitize_callback' => 'absint',
            'default' => 7
        ]);
        register_setting('dssa_pmpro_helper_general_group', 'dssa_pmpro_helper_cleanup_on_delete');
        
        add_settings_section(
            'dssa_general_section',
            __('General Settings', 'dssa-pmpro-helper'),
            [$this, 'general_section_callback'],
            'dssa_pmpro_helper_general'
        );
        
        add_settings_field(
            'enable_debug_logging',
            __('Enable Debug Logging', 'dssa-pmpro-helper'),
            [$this, 'checkbox_field_callback'],
            'dssa_pmpro_helper_general',
            'dssa_general_section',
            [
                'label_for' => 'dssa_pmpro_helper_enable_debug_logging',
                'description' => __('Log debug information to WordPress debug log. Only enable for troubleshooting.', 'dssa-pmpro-helper')
            ]
        );
        
        add_settings_field(
            'enable_audit_log',
            __('Enable Audit Log', 'dssa-pmpro-helper'),
            [$this, 'checkbox_field_callback'],
            'dssa_pmpro_helper_general',
            'dssa_general_section',
            [
                'label_for' => 'dssa_pmpro_helper_enable_audit_log',
                'description' => __('Track important actions for compliance and debugging.', 'dssa-pmpro-helper')
            ]
        );
        
        add_settings_field(
            'data_retention_years',
            __('Data Retention (Years)', 'dssa-pmpro-helper'),
            [$this, 'number_field_callback'],
            'dssa_pmpro_helper_general',
            'dssa_general_section',
            [
                'label_for' => 'dssa_pmpro_helper_data_retention_years',
                'description' => __('How many years to keep audit log entries before automatic cleanup.', 'dssa-pmpro-helper'),
                'min' => 1,
                'max' => 20,
                'step' => 1
            ]
        );
        
        add_settings_field(
            'cleanup_on_delete',
            __('Cleanup Data on Uninstall', 'dssa-pmpro-helper'),
            [$this, 'checkbox_field_callback'],
            'dssa_pmpro_helper_general',
            'dssa_general_section',
            [
                'label_for' => 'dssa_pmpro_helper_cleanup_on_delete',
                'description' => __('Remove all plugin data when plugin is deleted. WARNING: This cannot be undone!', 'dssa-pmpro-helper')
            ]
        );
        
        // ============================================
        // EMAIL SETTINGS
        // ============================================
        register_setting('dssa_pmpro_helper_email_group', 'dssa_pmpro_helper_notification_from_email', [
            'sanitize_callback' => 'sanitize_email'
        ]);
        register_setting('dssa_pmpro_helper_email_group', 'dssa_pmpro_helper_notification_from_name', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('dssa_pmpro_helper_email_group', 'dssa_pmpro_helper_membership_manager_emails', [
            'sanitize_callback' => [$this, 'sanitize_email_list']
        ]);
        register_setting('dssa_pmpro_helper_email_group', 'dssa_pmpro_helper_new_member_notification_emails', [
            'sanitize_callback' => [$this, 'sanitize_email_list']
        ]);
        register_setting('dssa_pmpro_helper_email_group', 'dssa_pmpro_helper_billing_notification_emails', [
            'sanitize_callback' => [$this, 'sanitize_email_list']
        ]);
        
        add_settings_section(
            'dssa_email_section',
            __('Email Settings', 'dssa-pmpro-helper'),
            [$this, 'email_section_callback'],
            'dssa_pmpro_helper_email'
        );
        
        add_settings_field(
            'notification_from_email',
            __('From Email', 'dssa-pmpro-helper'),
            [$this, 'email_field_callback'],
            'dssa_pmpro_helper_email',
            'dssa_email_section',
            [
                'label_for' => 'dssa_pmpro_helper_notification_from_email',
                'description' => __('Email address used for sending notifications.', 'dssa-pmpro-helper')
            ]
        );
        
        add_settings_field(
            'notification_from_name',
            __('From Name', 'dssa-pmpro-helper'),
            [$this, 'text_field_callback'],
            'dssa_pmpro_helper_email',
            'dssa_email_section',
            [
                'label_for' => 'dssa_pmpro_helper_notification_from_name',
                'description' => __('Name used for sending notifications.', 'dssa-pmpro-helper')
            ]
        );
        
        add_settings_field(
            'membership_manager_emails',
            __('Membership Manager Emails', 'dssa-pmpro-helper'),
            [$this, 'textarea_field_callback'],
            'dssa_pmpro_helper_email',
            'dssa_email_section',
            [
                'label_for' => 'dssa_pmpro_helper_membership_manager_emails',
                'description' => __('Email addresses for membership managers (one per line).', 'dssa-pmpro-helper'),
                'rows' => 3
            ]
        );
        
        add_settings_field(
            'new_member_notification_emails',
            __('New Member Notification Emails', 'dssa-pmpro-helper'),
            [$this, 'textarea_field_callback'],
            'dssa_pmpro_helper_email',
            'dssa_email_section',
            [
                'label_for' => 'dssa_pmpro_helper_new_member_notification_emails',
                'description' => __('Email addresses to notify when new members register (one per line).', 'dssa-pmpro-helper'),
                'rows' => 3
            ]
        );
        
        add_settings_field(
            'billing_notification_emails',
            __('Billing Notification Emails', 'dssa-pmpro-helper'),
            [$this, 'textarea_field_callback'],
            'dssa_pmpro_helper_email',
            'dssa_email_section',
            [
                'label_for' => 'dssa_pmpro_helper_billing_notification_emails',
                'description' => __('Email addresses to notify for billing issues (one per line).', 'dssa-pmpro-helper'),
                'rows' => 3
            ]
        );
        
        // ============================================
        // DATE & RENEWAL SETTINGS
        // ============================================
        register_setting('dssa_pmpro_helper_dates_group', 'dssa_pmpro_helper_annual_renewal_month', [
            'sanitize_callback' => [$this, 'sanitize_month'],
            'default' => 3
        ]);
        register_setting('dssa_pmpro_helper_dates_group', 'dssa_pmpro_helper_annual_renewal_day', [
            'sanitize_callback' => [$this, 'sanitize_day'],
            'default' => 1
        ]);
        register_setting('dssa_pmpro_helper_dates_group', 'dssa_pmpro_helper_prorata_threshold_days', [
            'sanitize_callback' => 'absint',
            'default' => 14
        ]);
        register_setting('dssa_pmpro_helper_dates_group', 'dssa_pmpro_helper_grace_period_days', [
            'sanitize_callback' => 'absint',
            'default' => 14
        ]);
        
        add_settings_section(
            'dssa_dates_section',
            __('Date & Renewal Settings', 'dssa-pmpro-helper'),
            [$this, 'dates_section_callback'],
            'dssa_pmpro_helper_dates'
        );
        
        add_settings_field(
            'annual_renewal_month',
            __('Annual Renewal Month', 'dssa-pmpro-helper'),
            [$this, 'select_month_callback'],
            'dssa_pmpro_helper_dates',
            'dssa_dates_section',
            [
                'label_for' => 'dssa_pmpro_helper_annual_renewal_month',
                'description' => __('Month when annual renewals occur.', 'dssa-pmpro-helper')
            ]
        );
        
        add_settings_field(
            'annual_renewal_day',
            __('Annual Renewal Day', 'dssa-pmpro-helper'),
            [$this, 'select_day_callback'],
            'dssa_pmpro_helper_dates',
            'dssa_dates_section',
            [
                'label_for' => 'dssa_pmpro_helper_annual_renewal_day',
                'description' => __('Day of month when annual renewals occur.', 'dssa-pmpro-helper')
            ]
        );
        
        add_settings_field(
            'prorata_threshold_days',
            __('Pro-rata Threshold (Days)', 'dssa-pmpro-helper'),
            [$this, 'number_field_callback'],
            'dssa_pmpro_helper_dates',
            'dssa_dates_section',
            [
                'label_for' => 'dssa_pmpro_helper_prorata_threshold_days',
                'description' => __('If ≤ this many days remain before renewal, no pro-rata payment is charged.', 'dssa-pmpro-helper'),
                'min' => 0,
                'max' => 31,
                'step' => 1
            ]
        );
        
        add_settings_field(
            'grace_period_days',
            __('Grace Period (Days)', 'dssa-pmpro-helper'),
            [$this, 'number_field_callback'],
            'dssa_pmpro_helper_dates',
            'dssa_dates_section',
            [
                'label_for' => 'dssa_pmpro_helper_grace_period_days',
                'description' => __('Number of days after failed payment before access is restricted.', 'dssa-pmpro-helper'),
                'min' => 0,
                'max' => 90,
                'step' => 1
            ]
        );
        
        // ============================================
        // PAYSTACK SETTINGS
        // ============================================
        register_setting('dssa_pmpro_helper_paystack_group', 'dssa_pmpro_helper_paystack_percentage', [
            'sanitize_callback' => [$this, 'sanitize_percentage'],
            'default' => 2.9
        ]);
        register_setting('dssa_pmpro_helper_paystack_group', 'dssa_pmpro_helper_paystack_fixed', [
            'sanitize_callback' => 'floatval',
            'default' => 1.00
        ]);
        register_setting('dssa_pmpro_helper_paystack_group', 'dssa_pmpro_helper_enable_fee_passthrough');
        
        add_settings_section(
            'dssa_paystack_section',
            __('Paystack Fee Settings', 'dssa-pmpro-helper'),
            [$this, 'paystack_section_callback'],
            'dssa_pmpro_helper_paystack'
        );
        
        add_settings_field(
            'paystack_percentage',
            __('Percentage Fee (%)', 'dssa-pmpro-helper'),
            [$this, 'number_field_callback'],
            'dssa_pmpro_helper_paystack',
            'dssa_paystack_section',
            [
                'label_for' => 'dssa_pmpro_helper_paystack_percentage',
                'description' => __('Paystack percentage fee (e.g., 2.9 for 2.9%).', 'dssa-pmpro-helper'),
                'min' => 0,
                'max' => 100,
                'step' => 0.1
            ]
        );
        
        add_settings_field(
            'paystack_fixed',
            __('Fixed Fee (R)', 'dssa-pmpro-helper'),
            [$this, 'number_field_callback'],
            'dssa_pmpro_helper_paystack',
            'dssa_paystack_section',
            [
                'label_for' => 'dssa_pmpro_helper_paystack_fixed',
                'description' => __('Paystack fixed fee in Rands.', 'dssa-pmpro-helper'),
                'min' => 0,
                'step' => 0.01
            ]
        );
        
        add_settings_field(
            'enable_fee_passthrough',
            __('Enable Fee Passthrough', 'dssa-pmpro-helper'),
            [$this, 'checkbox_field_callback'],
            'dssa_pmpro_helper_paystack',
            'dssa_paystack_section',
            [
                'label_for' => 'dssa_pmpro_helper_enable_fee_passthrough',
                'description' => __('Pass Paystack fees to members (required by DSSA constitution).', 'dssa-pmpro-helper')
            ]
        );
        
        // ============================================
        // VALIDATION MESSAGES
        // ============================================
        register_setting('dssa_pmpro_helper_validation_group', 'dssa_pmpro_helper_legacy_number_not_found', [
            'sanitize_callback' => 'sanitize_textarea_field'
        ]);
        register_setting('dssa_pmpro_helper_validation_group', 'dssa_pmpro_helper_legacy_number_claimed', [
            'sanitize_callback' => 'sanitize_textarea_field'
        ]);
        register_setting('dssa_pmpro_helper_validation_group', 'dssa_pmpro_helper_legacy_number_success', [
            'sanitize_callback' => 'sanitize_textarea_field'
        ]);
        
        add_settings_section(
            'dssa_validation_section',
            __('Validation Messages', 'dssa-pmpro-helper'),
            [$this, 'validation_section_callback'],
            'dssa_pmpro_helper_validation'
        );
        
        add_settings_field(
            'legacy_number_not_found',
            __('Legacy Number Not Found', 'dssa-pmpro-helper'),
            [$this, 'textarea_field_callback'],
            'dssa_pmpro_helper_validation',
            'dssa_validation_section',
            [
                'label_for' => 'dssa_pmpro_helper_legacy_number_not_found',
                'description' => __('Message shown when legacy membership number is not found.', 'dssa-pmpro-helper'),
                'rows' => 2
            ]
        );
        
        add_settings_field(
            'legacy_number_claimed',
            __('Legacy Number Already Claimed', 'dssa-pmpro-helper'),
            [$this, 'textarea_field_callback'],
            'dssa_pmpro_helper_validation',
            'dssa_validation_section',
            [
                'label_for' => 'dssa_pmpro_helper_legacy_number_claimed',
                'description' => __('Message shown when legacy membership number is already claimed.', 'dssa-pmpro-helper'),
                'rows' => 2
            ]
        );
        
        add_settings_field(
            'legacy_number_success',
            __('Legacy Number Success', 'dssa-pmpro-helper'),
            [$this, 'textarea_field_callback'],
            'dssa_pmpro_helper_validation',
            'dssa_validation_section',
            [
                'label_for' => 'dssa_pmpro_helper_legacy_number_success',
                'description' => __('Message shown when legacy membership number is successfully validated.', 'dssa-pmpro-helper'),
                'rows' => 2
            ]
        );
        
        // ============================================
        // RENEWAL PROCESSING
        // ============================================
        register_setting('dssa_pmpro_helper_renewal_group', 'dssa_pmpro_helper_renewal_reminder_days', [
            'sanitize_callback' => 'absint',
            'default' => 14
        ]);
        register_setting('dssa_pmpro_helper_renewal_group', 'dssa_pmpro_helper_renewal_processing_days_before', [
            'sanitize_callback' => 'absint',
            'default' => 3
        ]);
        register_setting('dssa_pmpro_helper_renewal_group', 'dssa_pmpro_helper_failed_payment_retry_attempts', [
            'sanitize_callback' => 'absint',
            'default' => 3
        ]);
        register_setting('dssa_pmpro_helper_renewal_group', 'dssa_pmpro_helper_failed_payment_retry_days', [
            'sanitize_callback' => 'absint',
            'default' => 7
        ]);
        
        add_settings_section(
            'dssa_renewal_section',
            __('Renewal Processing', 'dssa-pmpro-helper'),
            [$this, 'renewal_section_callback'],
            'dssa_pmpro_helper_renewal'
        );
        
        add_settings_field(
            'renewal_reminder_days',
            __('Renewal Reminder (Days Before)', 'dssa-pmpro-helper'),
            [$this, 'number_field_callback'],
            'dssa_pmpro_helper_renewal',
            'dssa_renewal_section',
            [
                'label_for' => 'dssa_pmpro_helper_renewal_reminder_days',
                'description' => __('Send renewal reminder this many days before renewal date.', 'dssa-pmpro-helper'),
                'min' => 1,
                'max' => 60,
                'step' => 1
            ]
        );
        
        add_settings_field(
            'renewal_processing_days_before',
            __('Process Renewals (Days Before)', 'dssa-pmpro-helper'),
            [$this, 'number_field_callback'],
            'dssa_pmpro_helper_renewal',
            'dssa_renewal_section',
            [
                'label_for' => 'dssa_pmpro_helper_renewal_processing_days_before',
                'description' => __('Process automatic renewals this many days before due date.', 'dssa-pmpro-helper'),
                'min' => 1,
                'max' => 30,
                'step' => 1
            ]
        );
        
        add_settings_field(
            'failed_payment_retry_attempts',
            __('Failed Payment Retry Attempts', 'dssa-pmpro-helper'),
            [$this, 'number_field_callback'],
            'dssa_pmpro_helper_renewal',
            'dssa_renewal_section',
            [
                'label_for' => 'dssa_pmpro_helper_failed_payment_retry_attempts',
                'description' => __('Number of retry attempts for failed payments.', 'dssa-pmpro-helper'),
                'min' => 0,
                'max' => 10,
                'step' => 1
            ]
        );
        
        add_settings_field(
            'failed_payment_retry_days',
            __('Failed Payment Retry Interval (Days)', 'dssa-pmpro-helper'),
            [$this, 'number_field_callback'],
            'dssa_pmpro_helper_renewal',
            'dssa_renewal_section',
            [
                'label_for' => 'dssa_pmpro_helper_failed_payment_retry_days',
                'description' => __('Days between retry attempts for failed payments.', 'dssa-pmpro-helper'),
                'min' => 1,
                'max' => 30,
                'step' => 1
            ]
        );
    }
    
    /**
     * Section callbacks
     */
    public function general_section_callback() {
        echo '<p>' . __('General plugin settings and configuration.', 'dssa-pmpro-helper') . '</p>';
    }
    
    public function email_section_callback() {
        echo '<p>' . __('Configure email addresses for notifications.', 'dssa-pmpro-helper') . '</p>';
    }
    
    public function dates_section_callback() {
        $next_renewal = $this->get_next_renewal_date();
        echo '<p>' . __('Configure membership renewal dates and grace periods.', 'dssa-pmpro-helper') . '</p>';
        if ($next_renewal) {
            echo '<p><strong>' . __('Next renewal date:', 'dssa-pmpro-helper') . ' ' . date_i18n(get_option('date_format'), $next_renewal) . '</strong></p>';
        }
    }
    
    public function paystack_section_callback() {
        echo '<p>' . __('Configure Paystack fee calculations. Fees will be passed to members as required by DSSA constitution.', 'dssa-pmpro-helper') . '</p>';
    }
    
    public function validation_section_callback() {
        echo '<p>' . __('Customize validation messages shown to users during registration.', 'dssa-pmpro-helper') . '</p>';
    }
    
    public function renewal_section_callback() {
        echo '<p>' . __('Configure renewal processing and failed payment handling.', 'dssa-pmpro-helper') . '</p>';
    }
    
    /**
     * Field callbacks
     */
    public function checkbox_field_callback($args) {
        $option = get_option($args['label_for'], false);
        $checked = checked(1, $option, false);
        ?>
        <input type="checkbox"
               id="<?php echo esc_attr($args['label_for']); ?>"
               name="<?php echo esc_attr($args['label_for']); ?>"
               value="1"
               <?php echo $checked; ?>>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }
    
    public function number_field_callback($args) {
        $option = get_option($args['label_for'], '');
        ?>
        <input type="number"
               id="<?php echo esc_attr($args['label_for']); ?>"
               name="<?php echo esc_attr($args['label_for']); ?>"
               value="<?php echo esc_attr($option); ?>"
               class="regular-text"
               <?php echo isset($args['min']) ? 'min="' . esc_attr($args['min']) . '"' : ''; ?>
               <?php echo isset($args['max']) ? 'max="' . esc_attr($args['max']) . '"' : ''; ?>
               <?php echo isset($args['step']) ? 'step="' . esc_attr($args['step']) . '"' : ''; ?>>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }
    
    public function email_field_callback($args) {
        $option = get_option($args['label_for'], '');
        ?>
        <input type="email"
               id="<?php echo esc_attr($args['label_for']); ?>"
               name="<?php echo esc_attr($args['label_for']); ?>"
               value="<?php echo esc_attr($option); ?>"
               class="regular-text">
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }
    
    public function text_field_callback($args) {
        $option = get_option($args['label_for'], '');
        ?>
        <input type="text"
               id="<?php echo esc_attr($args['label_for']); ?>"
               name="<?php echo esc_attr($args['label_for']); ?>"
               value="<?php echo esc_attr($option); ?>"
               class="regular-text">
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }
    
    public function textarea_field_callback($args) {
        $option = get_option($args['label_for'], '');
        ?>
        <textarea id="<?php echo esc_attr($args['label_for']); ?>"
                  name="<?php echo esc_attr($args['label_for']); ?>"
                  class="large-text"
                  rows="<?php echo isset($args['rows']) ? esc_attr($args['rows']) : 5; ?>"><?php echo esc_textarea($option); ?></textarea>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }
    
    public function select_month_callback($args) {
        $option = get_option($args['label_for'], 3);
        $months = [
            1 => __('January', 'dssa-pmpro-helper'),
            2 => __('February', 'dssa-pmpro-helper'),
            3 => __('March', 'dssa-pmpro-helper'),
            4 => __('April', 'dssa-pmpro-helper'),
            5 => __('May', 'dssa-pmpro-helper'),
            6 => __('June', 'dssa-pmpro-helper'),
            7 => __('July', 'dssa-pmpro-helper'),
            8 => __('August', 'dssa-pmpro-helper'),
            9 => __('September', 'dssa-pmpro-helper'),
            10 => __('October', 'dssa-pmpro-helper'),
            11 => __('November', 'dssa-pmpro-helper'),
            12 => __('December', 'dssa-pmpro-helper'),
        ];
        ?>
        <select id="<?php echo esc_attr($args['label_for']); ?>"
                name="<?php echo esc_attr($args['label_for']); ?>"
                class="regular-text">
            <?php foreach ($months as $value => $label): ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($option, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }
    
    public function select_day_callback($args) {
        $option = get_option($args['label_for'], 1);
        ?>
        <select id="<?php echo esc_attr($args['label_for']); ?>"
                name="<?php echo esc_attr($args['label_for']); ?>"
                class="regular-text">
            <?php for ($i = 1; $i <= 31; $i++): ?>
                <option value="<?php echo esc_attr($i); ?>" <?php selected($option, $i); ?>>
                    <?php echo esc_html($i); ?>
                </option>
            <?php endfor; ?>
        </select>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }
    
    /**
     * Sanitization functions
     */
    public function sanitize_email_list($input) {
        $emails = explode("\n", $input);
        $sanitized_emails = [];
        foreach ($emails as $email) {
            $email = trim($email);
            if (is_email($email)) {
                $sanitized_emails[] = sanitize_email($email);
            }
        }
        return implode("\n", $sanitized_emails);
    }
    
    public function sanitize_month($input) {
        $month = intval($input);
        return ($month >= 1 && $month <= 12) ? $month : 3;
    }
    
    public function sanitize_day($input) {
        $day = intval($input);
        return ($day >= 1 && $day <= 31) ? $day : 1;
    }
    
    public function sanitize_percentage($input) {
        $percentage = floatval($input);
        return ($percentage >= 0 && $percentage <= 100) ? $percentage : 2.9;
    }
    
    /**
     * Utility functions
     */
    public static function get_next_renewal_date() {
        $month = get_option('dssa_pmpro_helper_annual_renewal_month', 3);
        $day = get_option('dssa_pmpro_helper_annual_renewal_day', 1);
        $year = date('Y');
        
        // Create date for this year
        $this_year = strtotime("$year-$month-$day");
        
        // If date has already passed this year, use next year
        if ($this_year < current_time('timestamp')) {
            $year++;
            $this_year = strtotime("$year-$month-$day");
        }
        
        return $this_year;
    }
}