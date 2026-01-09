<?php
/**
 * Membership Levels class for DSSA PMPro Helper
 * Handles level assignment, price calculations, and payment logic
 */

if (!defined('ABSPATH')) {
    exit;
}

class DSSA_PMPro_Helper_Membership_Levels {
    
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
        
        // Modify checkout level for pro-rata calculations
        add_filter('pmpro_checkout_level', [$instance, 'apply_pro_rata_pricing']);
        
        // Apply Paystack fee passthrough
        add_filter('pmpro_checkout_level', [$instance, 'apply_paystack_fees']);
        
        // Hide payment section for legacy members
        add_action('pmpro_checkout_boxes', [$instance, 'control_payment_section_display'], 20);
        
        // Store calculated amounts for display in confirmation
        add_action('pmpro_before_checkout', [$instance, 'store_calculated_amounts']);
        
        // Add pro-rata calculation display to checkout
        add_action('pmpro_checkout_before_submit_button', [$instance, 'display_prorata_calculations']);
        
        // Add payment info for 14-day threshold members
        add_action('pmpro_checkout_before_submit_button', [$instance, 'display_threshold_message']);
        
        // Enqueue additional JS for real-time calculations
        add_action('wp_enqueue_scripts', [$instance, 'enqueue_prorata_scripts']);
        
        return $instance;
    }
    
    /**
     * Enqueue pro-rata calculation scripts
     */
    public function enqueue_prorata_scripts() {
        if (!function_exists('pmpro_is_checkout') || !pmpro_is_checkout()) {
            return;
        }
        
        wp_enqueue_script(
            'dssa-pmpro-prorata',
            DSSA_PMPRO_HELPER_URL . 'assets/js/prorata-calculations.js',
            ['jquery', 'dssa-pmpro-checkout'],
            DSSA_PMPRO_HELPER_VERSION,
            true
        );
        
        // Get pro-rata settings for JavaScript
        $renewal_month = intval(dssa_pmpro_helper_get_setting('annual_renewal_month', 3));
        $renewal_day = intval(dssa_pmpro_helper_get_setting('annual_renewal_day', 1));
        $threshold_days = intval(dssa_pmpro_helper_get_setting('prorata_threshold_days', 14));
        $paystack_percentage = floatval(dssa_pmpro_helper_get_setting('paystack_percentage', 2.9));
        $paystack_fixed = floatval(dssa_pmpro_helper_get_setting('paystack_fixed', 1.00));
        
        wp_localize_script('dssa-pmpro-prorata', 'dssa_prorata_settings', [
            'annual_renewal_month' => $renewal_month,
            'annual_renewal_day' => $renewal_day,
            'threshold_days' => $threshold_days,
            'paystack_percentage' => $paystack_percentage,
            'paystack_fixed' => $paystack_fixed,
            'membership_levels' => $this->get_membership_levels_data(),
            'next_renewal_date' => $this->get_next_renewal_date_formatted(),
            'currency_symbol' => 'R'
        ]);
    }
    
    /**
     * Get membership levels data for JavaScript
     */
    private function get_membership_levels_data() {
        $levels = pmpro_getAllLevels(true, true);
        $level_data = [];
        
        foreach ($levels as $level) {
            $level_data[$level->id] = [
                'name' => $level->name,
                'annual_fee' => floatval($level->initial_payment),
                'billing_amount' => floatval($level->billing_amount),
                'cycle_number' => intval($level->cycle_number),
                'cycle_period' => $level->cycle_period
            ];
        }
        
        return $level_data;
    }
    
    /**
     * Get next renewal date formatted
     */
    private function get_next_renewal_date_formatted() {
        $renewal_month = intval(dssa_pmpro_helper_get_setting('annual_renewal_month', 3));
        $renewal_day = intval(dssa_pmpro_helper_get_setting('annual_renewal_day', 1));
        
        $current_date = new DateTime();
        $current_year = intval($current_date->format('Y'));
        
        $renewal_date = new DateTime();
        $renewal_date->setDate($current_year, $renewal_month, $renewal_day);
        
        if ($current_date > $renewal_date) {
            $renewal_date->modify('+1 year');
        }
        
        return $renewal_date->format('j F Y');
    }
    
    /**
     * Store calculated amounts before checkout for confirmation display
     */
    public function store_calculated_amounts() {
        // Only store for new members
        if (isset($_POST['exist_member']) && $_POST['exist_member'] == 1) {
            return;
        }
        
        // Get selected level
        $level_id = isset($_POST['level']) ? intval($_POST['level']) : 0;
        if (!$level_id) {
            return;
        }
        
        $level = pmpro_getLevel($level_id);
        if (!$level) {
            return;
        }
        
        // Calculate pro-rata amount
        $pro_rata_amount = $this->calculate_pro_rata_amount($level->initial_payment);
        
        // Store in session for confirmation page
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['dssa_calculated_amount'] = $pro_rata_amount;
        $_SESSION['dssa_within_threshold'] = $this->is_within_prorata_threshold();
        
        // Also store in POST for immediate use
        $_POST['dssa_calculated_amount'] = $pro_rata_amount;
        $_POST['dssa_within_threshold'] = $_SESSION['dssa_within_threshold'];
    }
    
    /**
     * Display pro-rata calculations on checkout page
     */
    public function display_prorata_calculations() {
        // Only show for new members (not legacy)
        if (isset($_POST['exist_member']) && $_POST['exist_member'] == 1) {
            return;
        }
        
        // Get selected level
        $level_id = isset($_POST['level']) ? intval($_POST['level']) : 0;
        if (!$level_id) {
            // Try to get from GET parameter
            $level_id = isset($_GET['level']) ? intval($_GET['level']) : 0;
        }
        
        if (!$level_id) {
            return;
        }
        
        $level = pmpro_getLevel($level_id);
        if (!$level) {
            return;
        }
        
        // Calculate values
        $annual_fee = floatval($level->initial_payment);
        $pro_rata_amount = $this->calculate_pro_rata_amount($annual_fee);
        $within_threshold = $this->is_within_prorata_threshold();
        $months_remaining = $this->get_months_remaining();
        $next_renewal_date = $this->get_next_renewal_date_formatted();
        
        // Only display if we have calculations to show
        if ($pro_rata_amount == 0 && !$within_threshold) {
            return;
        }
        
        ?>
        <div id="dssa-prorata-calculations" class="dssa-prorata-calculations pmpro_checkout">
            <h3><?php _e('Membership Fee Calculation', 'dssa-pmpro-helper'); ?></h3>
            
            <table class="dssa-prorata-table">
                <tr>
                    <td><?php _e('Annual Membership Fee:', 'dssa-pmpro-helper'); ?></td>
                    <td class="dssa-amount">R <?php echo number_format($annual_fee, 2); ?></td>
                </tr>
                
                <?php if ($months_remaining > 0): ?>
                <tr>
                    <td>
                        <?php printf(
                            __('Pro-rata for %d months until %s:', 'dssa-pmpro-helper'),
                            ceil($months_remaining),
                            $next_renewal_date
                        ); ?>
                    </td>
                    <td class="dssa-amount">R <?php echo number_format($pro_rata_amount, 2); ?></td>
                </tr>
                <?php endif; ?>
                
                <?php if (isset($_POST['card_payments']) && $_POST['card_payments'] == 1 && $pro_rata_amount > 0): 
                    $amount_with_fees = $this->calculate_amount_with_paystack_fees($pro_rata_amount);
                    $fee_amount = $amount_with_fees - $pro_rata_amount;
                ?>
                <tr>
                    <td><?php _e('Paystack transaction fees (2.9% + R1):', 'dssa-pmpro-helper'); ?></td>
                    <td class="dssa-amount">R <?php echo number_format($fee_amount, 2); ?></td>
                </tr>
                <tr class="dssa-total-row">
                    <td><strong><?php _e('Total Amount Due:', 'dssa-pmpro-helper'); ?></strong></td>
                    <td class="dssa-amount"><strong>R <?php echo number_format($amount_with_fees, 2); ?></strong></td>
                </tr>
                <?php elseif ($pro_rata_amount > 0): ?>
                <tr class="dssa-total-row">
                    <td><strong><?php _e('Total Amount Due:', 'dssa-pmpro-helper'); ?></strong></td>
                    <td class="dssa-amount"><strong>R <?php echo number_format($pro_rata_amount, 2); ?></strong></td>
                </tr>
                <?php endif; ?>
            </table>
            
            <?php if ($within_threshold && $pro_rata_amount == 0): ?>
            <div class="dssa-threshold-notice dssa-info">
                <p>
                    <strong><?php _e('No Payment Required Now', 'dssa-pmpro-helper'); ?></strong><br>
                    <?php printf(
                        __('Your membership starts soon! No payment is required at this time. Your first payment of R%s will be due on %s.', 'dssa-pmpro-helper'),
                        number_format($annual_fee, 2),
                        $next_renewal_date
                    ); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Display 14-day threshold message
     */
    public function display_threshold_message() {
        // Only show for new members
        if (isset($_POST['exist_member']) && $_POST['exist_member'] == 1) {
            return;
        }
        
        $within_threshold = $this->is_within_prorata_threshold();
        $pro_rata_amount = 0;
        
        // Get level to calculate pro-rata
        $level_id = isset($_POST['level']) ? intval($_POST['level']) : 0;
        if ($level_id) {
            $level = pmpro_getLevel($level_id);
            if ($level) {
                $pro_rata_amount = $this->calculate_pro_rata_amount($level->initial_payment);
            }
        }
        
        if ($within_threshold && $pro_rata_amount == 0) {
            $next_renewal_date = $this->get_next_renewal_date_formatted();
            ?>
            <div class="dssa-payment-threshold-message dssa-notice">
                <h4><?php _e('Payment Information', 'dssa-pmpro-helper'); ?></h4>
                <p>
                    <?php printf(
                        __('You\'re joining close to our renewal period. No payment is required now. If you select "Card Payments", your card details will be saved for future renewals starting %s.', 'dssa-pmpro-helper'),
                        $next_renewal_date
                    ); ?>
                </p>
                <p>
                    <small><?php _e('Note: Card payments selected now will be used for all future automatic renewals.', 'dssa-pmpro-helper'); ?></small>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Apply pro-rata pricing to checkout level
     */
    public function apply_pro_rata_pricing($level) {
        // Skip if not a new member or if legacy member
        if (isset($_POST['exist_member']) && $_POST['exist_member'] == 1) {
            // Legacy member - no payment
            $level->initial_payment = 0;
            $level->billing_amount = 0;
            $level->cycle_number = 0; // No recurring
            return $level;
        }
        
        // Check if within pro-rata threshold (≤14 days)
        $within_threshold = $this->is_within_prorata_threshold();
        
        if ($within_threshold) {
            // Within threshold - no charge now
            $level->initial_payment = 0;
            return $level;
        }
        
        // Calculate pro-rata amount
        $pro_rata_amount = $this->calculate_pro_rata_amount($level->initial_payment);
        
        if ($pro_rata_amount > 0) {
            $level->initial_payment = $pro_rata_amount;
        }
        
        return $level;
    }
    
    /**
     * Apply Paystack fees to checkout level
     */
    public function apply_paystack_fees($level) {
        // Skip if no payment
        if ($level->initial_payment <= 0) {
            return $level;
        }
        
        // Only apply if card payments selected
        if (!isset($_POST['card_payments']) || $_POST['card_payments'] != 1) {
            return $level;
        }
        
        // Check if fee passthrough is enabled
        $enable_fee_passthrough = dssa_pmpro_helper_get_setting('enable_fee_passthrough', true);
        
        if (!$enable_fee_passthrough) {
            return $level;
        }
        
        // Calculate amount with Paystack fees
        $amount_with_fees = $this->calculate_amount_with_paystack_fees($level->initial_payment);
        
        if ($amount_with_fees > $level->initial_payment) {
            $level->initial_payment = $amount_with_fees;
        }
        
        return $level;
    }
    
    /**
     * Control payment section display
     */
    public function control_payment_section_display($boxes) {
        // Check if on checkout page and user is logged in (profile) or POST data exists (checkout)
        $is_legacy_member = false;
        
        if (is_user_logged_in()) {
            // Profile page
            $user_id = get_current_user_id();
            $is_legacy_member = get_user_meta($user_id, 'dssa_is_legacy_member', true);
        } elseif (isset($_POST['exist_member']) && $_POST['exist_member'] == 1) {
            // Checkout form submission for legacy member
            $is_legacy_member = true;
        }
        
        if ($is_legacy_member) {
            // Remove payment information box for legacy members
            foreach ($boxes as $key => $box) {
                if (isset($box['id']) && $box['id'] == 'payment_information') {
                    unset($boxes[$key]);
                    break;
                }
            }
            
            // Also remove billing address if present
            foreach ($boxes as $key => $box) {
                if (isset($box['id']) && $box['id'] == 'billing_address') {
                    unset($boxes[$key]);
                    break;
                }
            }
            
            // Add a message explaining no payment needed
            $boxes[] = array(
                'id' => 'dssa_no_payment',
                'title' => __('Payment Information', 'dssa-pmpro-helper'),
                'content' => '<div class="dssa-legacy-payment-notice dssa-success">
                    <p>' . __('As an existing DSSA member, no online payment is required. Your membership fees are managed offline through your branch.', 'dssa-pmpro-helper') . '</p>
                </div>',
                'order' => 25
            );
        }
        
        return $boxes;
    }
    
    /**
     * Calculate pro-rata amount
     */
    private function calculate_pro_rata_amount($annual_fee) {
        // Check if within threshold - if so, no charge
        if ($this->is_within_prorata_threshold()) {
            return 0;
        }
        
        // Get remaining months in membership year
        $months_remaining = $this->get_months_remaining();
        
        if ($months_remaining <= 0) {
            return 0;
        }
        
        // Calculate pro-rata: (annual_fee / 12) × months_remaining
        $pro_rata = ($annual_fee / 12) * $months_remaining;
        
        // Round to 2 decimal places
        return round($pro_rata, 2);
    }
    
    /**
     * Get months remaining until next renewal date
     */
    private function get_months_remaining() {
        $renewal_month = intval(dssa_pmpro_helper_get_setting('annual_renewal_month', 3));
        $renewal_day = intval(dssa_pmpro_helper_get_setting('annual_renewal_day', 1));
        
        $current_date = new DateTime();
        $current_year = intval($current_date->format('Y'));
        
        // Create renewal date for this year
        $renewal_date_this_year = new DateTime();
        $renewal_date_this_year->setDate($current_year, $renewal_month, $renewal_day);
        
        // If renewal date has already passed this year, use next year
        if ($current_date > $renewal_date_this_year) {
            $renewal_date_this_year->modify('+1 year');
        }
        
        // Calculate months remaining
        $interval = $current_date->diff($renewal_date_this_year);
        $months_remaining = ($interval->y * 12) + $interval->m;
        
        // Add partial month if more than 0 days
        if ($interval->d > 0) {
            $months_remaining += ($interval->d / 30); // Approximate
        }
        
        return max(0, $months_remaining);
    }
    
    /**
     * Check if current date is within pro-rata threshold
     */
    private function is_within_prorata_threshold() {
        $threshold_days = intval(dssa_pmpro_helper_get_setting('prorata_threshold_days', 14));
        $renewal_month = intval(dssa_pmpro_helper_get_setting('annual_renewal_month', 3));
        $renewal_day = intval(dssa_pmpro_helper_get_setting('annual_renewal_day', 1));
        
        $current_date = new DateTime();
        $current_year = intval($current_date->format('Y'));
        
        // Create renewal date for this year
        $renewal_date = new DateTime();
        $renewal_date->setDate($current_year, $renewal_month, $renewal_day);
        
        // If renewal date has already passed this year, use next year
        if ($current_date > $renewal_date) {
            $renewal_date->modify('+1 year');
        }
        
        // Calculate days until renewal
        $interval = $current_date->diff($renewal_date);
        $days_remaining = $interval->days;
        
        return $days_remaining <= $threshold_days;
    }
    
    /**
     * Calculate amount with Paystack fees
     */
    private function calculate_amount_with_paystack_fees($amount) {
        $percentage = floatval(dssa_pmpro_helper_get_setting('paystack_percentage', 2.9));
        $fixed_fee = floatval(dssa_pmpro_helper_get_setting('paystack_fixed', 1.00));
        
        // Convert percentage to decimal
        $p = $percentage / 100;
        
        // Calculate total to charge: T = (M + f) / (1 - p)
        $total = ($amount + $fixed_fee) / (1 - $p);
        
        // Round up to nearest cent (0.01)
        $total = ceil($total * 100) / 100;
        
        return $total;
    }
    
    /**
     * Save calculated amount to user meta after checkout
     * This should be called from the registration class
     */
    public static function save_calculated_amount_to_user($user_id) {
        // Get calculated amount from session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['dssa_calculated_amount'])) {
            update_user_meta($user_id, 'dssa_calculated_amount', $_SESSION['dssa_calculated_amount']);
        }
        
        if (isset($_SESSION['dssa_within_threshold'])) {
            update_user_meta($user_id, 'dssa_within_prorata_threshold', $_SESSION['dssa_within_threshold']);
        }
        
        // Clear session data
        unset($_SESSION['dssa_calculated_amount']);
        unset($_SESSION['dssa_within_threshold']);
    }
}

// Initialize the class
DSSA_PMPro_Helper_Membership_Levels::init();