<?php
/**
 * Registration workflow class for DSSA PMPro Helper
 * Handles form processing, membership assignment, and payment flow logic
 */

if (!defined('ABSPATH')) {
    exit;
}

class DSSA_PMPro_Helper_Registration {
    
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
        
        // Hook into PMPro checkout completion
        add_action('pmpro_after_checkout', [$instance, 'process_registration_workflow'], 10, 2);
        
        // Modify checkout confirmation for new members
        add_filter('pmpro_confirmation_message', [$instance, 'customize_confirmation_message'], 10, 2);
        
        // Add email notifications
        add_action('dssa_member_registered', [$instance, 'send_registration_notifications'], 10, 2);
        
        return $instance;
    }
    
    /**
     * Process registration workflow after checkout
     */
    public function process_registration_workflow($user_id, $order) {
        // Get user data
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }
        
        // Determine member type
        $is_legacy_member = get_user_meta($user_id, 'dssa_is_legacy_member', true);
        $card_payments = get_user_meta($user_id, 'dssa_card_payments', true);
        
        // Set initial membership status
        if ($is_legacy_member) {
            // Legacy members get immediate access
            $this->activate_legacy_member($user_id);
        } else {
            // New members go to pending approval
            $this->set_pending_approval($user_id, $card_payments);
        }
        
        // Trigger notification event
        do_action('dssa_member_registered', $user_id, [
            'is_legacy' => $is_legacy_member,
            'card_payments' => $card_payments,
            'order_id' => $order->id ?? 0,
        ]);
        
        // Log to audit
        if (class_exists('DSSA_PMPro_Helper_Audit_Log')) {
            DSSA_PMPro_Helper_Audit_Log::add_entry(
                $user_id,
                'registration_processed',
                [
                    'member_type' => $is_legacy_member ? 'legacy' : 'new',
                    'card_payments' => $card_payments,
                    'status' => $is_legacy_member ? 'active' : 'pending',
                ]
            );
        }
    }
    
    /**
     * Activate legacy member (immediate access)
     */
    private function activate_legacy_member($user_id) {
        // Set membership as active
        update_user_meta($user_id, 'dssa_membership_status', 'active');
        update_user_meta($user_id, 'dssa_approved_by', 'system');
        update_user_meta($user_id, 'dssa_approved_date', current_time('mysql'));
        
        // No payment needed for legacy members
        update_user_meta($user_id, 'dssa_payment_status', 'not_required');
        
        // Get membership number
        $member_number = get_user_meta($user_id, 'dssa_member_number', true);
        
        // Legacy members don't need number assignment
        update_user_meta($user_id, 'dssa_number_assigned', 'legacy');
        update_user_meta($user_id, 'dssa_number_assigned_by', 'system');
        update_user_meta($user_id, 'dssa_number_assigned_date', current_time('mysql'));
        
        // Set renewal date based on annual renewal configuration
        $this->set_membership_renewal_date($user_id);
    }
    
    /**
     * Set new member to pending approval
     */
    private function set_pending_approval($user_id, $card_payments) {
        // Set status to pending
        update_user_meta($user_id, 'dssa_membership_status', 'pending');
        
        // Set payment status based on card payments selection
        if ($card_payments) {
            update_user_meta($user_id, 'dssa_payment_status', 'card_selected');
        } else {
            update_user_meta($user_id, 'dssa_payment_status', 'eft_required');
            
            // Save calculated amount for EFT display
            if (class_exists('DSSA_PMPro_Helper_Membership_Levels')) {
                DSSA_PMPro_Helper_Membership_Levels::save_calculated_amount_to_user($user_id);
            }
        }
        
        // Number not assigned yet
        update_user_meta($user_id, 'dssa_number_assigned', 'pending');
        
        // Branch not assigned yet
        $current_branch = get_user_meta($user_id, 'dssa_branch', true);
        if (empty($current_branch)) {
            update_user_meta($user_id, 'dssa_branch', 'unassigned');
        }
        
        // Set renewal date
        $this->set_membership_renewal_date($user_id);
    }
    
    /**
     * Set membership renewal date based on annual renewal configuration
     */
    private function set_membership_renewal_date($user_id) {
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
        
        update_user_meta($user_id, 'dssa_renewal_date', $renewal_date->format('Y-m-d'));
    }
    
    /**
     * Customize confirmation message based on member type
     */
    public function customize_confirmation_message($message, $user) {
        if (!$user || !isset($user->ID)) {
            return $message;
        }
        
        $user_id = $user->ID;
        $is_legacy = get_user_meta($user_id, 'dssa_is_legacy_member', true);
        $card_payments = get_user_meta($user_id, 'dssa_card_payments', true);
        $membership_status = get_user_meta($user_id, 'dssa_membership_status', true);
        
        if ($is_legacy) {
            // Legacy member confirmation
            $custom_message = '<div class="dssa-confirmation dssa-legacy-confirmation">';
            $custom_message .= '<h3>' . __('Welcome to the DSSA Website!', 'dssa-pmpro-helper') . '</h3>';
            $custom_message .= '<p>' . __('Thank you for registering your existing DSSA membership. Your access to member benefits has been activated.', 'dssa-pmpro-helper') . '</p>';
            
            // Add link to member downloads
            $custom_message .= '<p>' . sprintf(
                __('You can now access the %sMember Downloads%s page to download the latest Dendron magazine.', 'dssa-pmpro-helper'),
                '<a href="https://dendro.co.za/member-downloads/" target="_blank">',
                '</a>'
            ) . '</p>';
            
            $custom_message .= '<p><strong>' . __('Next Steps:', 'dssa-pmpro-helper') . '</strong></p>';
            $custom_message .= '<ul>';
            $custom_message .= '<li>' . __('Bookmark the members login page for future access', 'dssa-pmpro-helper') . '</li>';
            $custom_message .= '<li>' . __('Explore the member resources available to you', 'dssa-pmpro-helper') . '</li>';
            $custom_message .= '</ul>';
            $custom_message .= '</div>';
            
            return $custom_message;
            
        } elseif ($membership_status === 'pending') {
            // New member pending approval
            $custom_message = '<div class="dssa-confirmation dssa-pending-confirmation">';
            $custom_message .= '<h3>' . __('Thank You for Your Membership Application!', 'dssa-pmpro-helper') . '</h3>';
            
            if ($card_payments) {
                // Card payment selected
                $custom_message .= '<p>' . __('Your application has been received and is pending verification by DSSA administration.', 'dssa-pmpro-helper') . '</p>';
                
                // Get order details for card payment amount
                $order = new MemberOrder();
                $order->getLastMemberOrder($user_id);
                
                if (!empty($order->id) && $order->total > 0) {
                    $custom_message .= '<p>' . sprintf(
                        __('A payment of %s has been processed for your membership.', 'dssa-pmpro-helper'),
                        '<strong>R ' . number_format($order->total, 2) . '</strong>'
                    ) . '</p>';
                }
                
            } else {
                // EFT payment required
                $custom_message .= '<p>' . __('Your membership application has been received and is pending verification by DSSA administration.', 'dssa-pmpro-helper') . '</p>';
                
                // Get the calculated pro-rata amount
                $pro_rata_amount = get_user_meta($user_id, 'dssa_calculated_amount', true);
                $within_threshold = get_user_meta($user_id, 'dssa_within_prorata_threshold', true);
                
                if (!$within_threshold && $pro_rata_amount > 0) {
                    // Show banking details for EFT payment
                    $custom_message .= '<div class="dssa-banking-details">';
                    $custom_message .= '<h4>' . __('Please make payment via EFT:', 'dssa-pmpro-helper') . '</h4>';
                    $custom_message .= '<p>' . sprintf(__('Amount: %s', 'dssa-pmpro-helper'), '<strong>R ' . number_format($pro_rata_amount, 2) . '</strong>') . '</p>';
                    $custom_message .= '<table class="dssa-banking-table">';
                    $custom_message .= '<tr><th>' . __('Banking Details:', 'dssa-pmpro-helper') . '</th></tr>';
                    $custom_message .= '<tr><td><strong>' . __('Name:', 'dssa-pmpro-helper') . '</strong> Dendrologiese Vereniging</td></tr>';
                    $custom_message .= '<tr><td><strong>' . __('Bank:', 'dssa-pmpro-helper') . '</strong> First National Bank</td></tr>';
                    $custom_message .= '<tr><td><strong>' . __('Bank Code:', 'dssa-pmpro-helper') . '</strong> 250655</td></tr>';
                    $custom_message .= '<tr><td><strong>' . __('Account Number:', 'dssa-pmpro-helper') . '</strong> 62060700723</td></tr>';
                    $custom_message .= '<tr><td><strong>' . __('Reference:', 'dssa-pmpro-helper') . '</strong> ' . esc_html($user->first_name) . ' ' . esc_html($user->last_name) . '</td></tr>';
                    $custom_message .= '</table>';
                    $custom_message .= '</div>';
                } else {
                    // Within threshold, no payment needed now
                    $custom_message .= '<p>' . __('As your application is close to the renewal period, you will receive an invoice from the Dendrological Society of South Africa for the next membership year.', 'dssa-pmpro-helper') . '</p>';
                }
            }
            
            $custom_message .= '<div class="dssa-pending-notice">';
            $custom_message .= '<p><strong>' . __('Important:', 'dssa-pmpro-helper') . '</strong> ' . __('Your membership benefits will be activated once the Membership Manager assigns you a membership number and verifies your payment (if applicable).', 'dssa-pmpro-helper') . '</p>';
            $custom_message .= '<p>' . __('You will receive an email notification once your membership is active.', 'dssa-pmpro-helper') . '</p>';
            $custom_message .= '</div>';
            $custom_message .= '</div>';
            
            return $custom_message;
        }
        
        // Default PMPro message for other cases
        return $message;
    }
    
    /**
     * Send registration notifications
     */
    public function send_registration_notifications($user_id, $data) {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }
        
        $is_legacy = $data['is_legacy'] ?? false;
        $card_payments = $data['card_payments'] ?? false;
        
        // 1. Send welcome email to member
        $this->send_member_welcome_email($user, $is_legacy, $card_payments);
        
        // 2. Send notification to membership manager
        $this->send_manager_notification_email($user, $is_legacy, $card_payments);
    }
    
    /**
     * Send welcome email to member
     */
    private function send_member_welcome_email($user, $is_legacy, $card_payments) {
        $to = $user->user_email;
        $subject = $is_legacy 
            ? __('Welcome to the DSSA Website', 'dssa-pmpro-helper')
            : __('Thank You for Your DSSA Membership Application', 'dssa-pmpro-helper');
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . dssa_pmpro_helper_get_setting('notification_from_name', get_bloginfo('name')) . 
                 ' <' . dssa_pmpro_helper_get_setting('notification_from_email', get_bloginfo('admin_email')) . '>',
        ];
        
        if ($is_legacy) {
            $message = $this->get_legacy_welcome_email_content($user);
        } else {
            $message = $this->get_new_member_welcome_email_content($user, $card_payments);
        }
        
        wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Get legacy member welcome email content
     */
    private function get_legacy_welcome_email_content($user) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php _e('Welcome to the DSSA Website', 'dssa-pmpro-helper'); ?></title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #2c5e8e; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
                .button { display: inline-block; padding: 10px 20px; background-color: #2c5e8e; color: white; text-decoration: none; border-radius: 4px; }
                .highlight { background-color: #e8f4fd; padding: 15px; border-left: 4px solid #2c5e8e; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1><?php _e('Welcome to the Dendrological Society of South Africa', 'dssa-pmpro-helper'); ?></h1>
                </div>
                
                <div class="content">
                    <p><?php echo sprintf(__('Dear %s,', 'dssa-pmpro-helper'), esc_html($user->first_name . ' ' . $user->last_name)); ?></p>
                    
                    <p><?php _e('Welcome to the DSSA member website! We\'re delighted to have you join our online community.', 'dssa-pmpro-helper'); ?></p>
                    
                    <div class="highlight">
                        <h3><?php _e('Your Member Benefits Are Now Active', 'dssa-pmpro-helper'); ?></h3>
                        <p><?php _e('As an existing DSSA member, you now have access to:', 'dssa-pmpro-helper'); ?></p>
                        <ul>
                            <li><?php _e('The latest Dendron magazine downloads', 'dssa-pmpro-helper'); ?></li>
                            <li><?php _e('Exclusive member resources', 'dssa-pmpro-helper'); ?></li>
                            <li><?php _e('Branch event information', 'dssa-pmpro-helper'); ?></li>
                            <li><?php _e('Society updates and announcements', 'dssa-pmpro-helper'); ?></li>
                        </ul>
                    </div>
                    
                    <p>
                        <strong><?php _e('Get Started:', 'dssa-pmpro-helper'); ?></strong><br>
                        <?php echo sprintf(
                            __('Visit our %sMember Downloads%s page to access the latest Dendron magazine and other resources.', 'dssa-pmpro-helper'),
                            '<a href="https://dendro.co.za/member-downloads/">',
                            '</a>'
                        ); ?>
                    </p>
                    
                    <p style="text-align: center; margin: 30px 0;">
                        <a href="https://dendro.co.za/member-downloads/" class="button">
                            <?php _e('Access Member Downloads', 'dssa-pmpro-helper'); ?>
                        </a>
                    </p>
                    
                    <p>
                        <strong><?php _e('Login Details:', 'dssa-pmpro-helper'); ?></strong><br>
                        <?php _e('You can log in to the website using your username or membership number:', 'dssa-pmpro-helper'); ?><br>
                        <strong><?php _e('Username:', 'dssa-pmpro-helper'); ?></strong> <?php echo esc_html($user->user_login); ?><br>
                        <?php 
                        $member_number = get_user_meta($user->ID, 'dssa_member_number', true);
                        if (!empty($member_number)) {
                            echo '<strong>' . __('Membership Number:', 'dssa-pmpro-helper') . '</strong> ' . esc_html($member_number) . '<br>';
                        }
                        ?>
                    </p>
                    
                    <p>
                        <?php _e('If you have any questions or need assistance, please contact your branch representative or the DSSA administration.', 'dssa-pmpro-helper'); ?>
                    </p>
                    
                    <p><?php _e('Best regards,', 'dssa-pmpro-helper'); ?><br>
                    <?php _e('The DSSA Team', 'dssa-pmpro-helper'); ?></p>
                </div>
                
                <div class="footer">
                    <p><?php _e('Dendrological Society of South Africa', 'dssa-pmpro-helper'); ?><br>
                    <?php echo date('Y'); ?> © <?php _e('All rights reserved', 'dssa-pmpro-helper'); ?></p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get new member welcome email content
     */
    private function get_new_member_welcome_email_content($user, $card_payments) {
        // Get calculated amount for EFT payments
        $pro_rata_amount = get_user_meta($user->ID, 'dssa_calculated_amount', true);
        $within_threshold = get_user_meta($user->ID, 'dssa_within_prorata_threshold', true);
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php _e('Thank You for Your DSSA Membership Application', 'dssa-pmpro-helper'); ?></title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #2c5e8e; color: white; padding: 15px; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
                .pending-notice { background-color: #fff8e1; border: 1px solid #ffd54f; padding: 15px; margin: 20px 0; border-radius: 4px; }
                .banking-details { background-color: #e8f5e9; border: 1px solid #4caf50; padding: 15px; margin: 20px 0; border-radius: 4px; }
                .info-box { background-color: #e3f2fd; padding: 15px; margin: 20px 0; border-radius: 4px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2><?php _e('Dendrological Society of South Africa', 'dssa-pmpro-helper'); ?></h2>
                </div>
                
                <div class="content">
                    <p><?php echo sprintf(__('Dear %s,', 'dssa-pmpro-helper'), esc_html($user->first_name . ' ' . $user->last_name)); ?></p>
                    
                    <p><?php _e('Thank you for applying for membership with the Dendrological Society of South Africa. We\'re excited to welcome you to our community of tree enthusiasts.', 'dssa-pmpro-helper'); ?></p>
                    
                    <div class="pending-notice">
                        <h3><?php _e('Application Status: Pending Verification', 'dssa-pmpro-helper'); ?></h3>
                        <p><?php _e('Your application has been received and is currently pending verification by our Membership Manager. You will be notified once your membership has been approved and activated.', 'dssa-pmpro-helper'); ?></p>
                    </div>
                    
                    <?php if (!$card_payments && !$within_threshold && $pro_rata_amount > 0): ?>
                    <div class="banking-details">
                        <h3><?php _e('Payment Required', 'dssa-pmpro-helper'); ?></h3>
                        <p><?php echo sprintf(__('Please make an EFT payment of %s to complete your membership application.', 'dssa-pmpro-helper'), '<strong>R ' . number_format($pro_rata_amount, 2) . '</strong>'); ?></p>
                        
                        <p><strong><?php _e('Banking Details:', 'dssa-pmpro-helper'); ?></strong></p>
                        <table style="width: 100%;">
                            <tr><td><strong><?php _e('Account Holder:', 'dssa-pmpro-helper'); ?></strong></td><td>Dendrologiese Vereniging</td></tr>
                            <tr><td><strong><?php _e('Bank:', 'dssa-pmpro-helper'); ?></strong></td><td>First National Bank</td></tr>
                            <tr><td><strong><?php _e('Branch Code:', 'dssa-pmpro-helper'); ?></strong></td><td>250655</td></tr>
                            <tr><td><strong><?php _e('Account Number:', 'dssa-pmpro-helper'); ?></strong></td><td>62060700723</td></tr>
                            <tr><td><strong><?php _e('Reference:', 'dssa-pmpro-helper'); ?></strong></td><td><?php echo esc_html($user->first_name . ' ' . $user->last_name); ?></td></tr>
                        </table>
                    </div>
                    <?php elseif (!$card_payments && $within_threshold): ?>
                    <div class="info-box">
                        <h3><?php _e('Payment Information', 'dssa-pmpro-helper'); ?></h3>
                        <p><?php _e('As your application is close to our annual renewal period, you will receive an invoice from the Dendrological Society of South Africa for the next membership year.', 'dssa-pmpro-helper'); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-box">
                        <h3><?php _e('What Happens Next?', 'dssa-pmpro-helper'); ?></h3>
                        <ol>
                            <li><?php _e('Our Membership Manager will review your application', 'dssa-pmpro-helper'); ?></li>
                            <li><?php _e('You will be assigned a membership number and branch', 'dssa-pmpro-helper'); ?></li>
                            <li><?php _e('Your payment will be verified (if applicable)', 'dssa-pmpro-helper'); ?></li>
                            <li><?php _e('You will receive an email notification once your membership is active', 'dssa-pmpro-helper'); ?></li>
                            <li><?php _e('You\'ll gain access to all member benefits and resources', 'dssa-pmpro-helper'); ?></li>
                        </ol>
                    </div>
                    
                    <p>
                        <?php _e('This process typically takes 2-3 business days. If you have any questions about your application, please contact the Membership Manager.', 'dssa-pmpro-helper'); ?>
                    </p>
                    
                    <p><?php _e('Best regards,', 'dssa-pmpro-helper'); ?><br>
                    <?php _e('The DSSA Team', 'dssa-pmpro-helper'); ?></p>
                </div>
                
                <div class="footer">
                    <p><?php _e('Dendrological Society of South Africa', 'dssa-pmpro-helper'); ?><br>
                    <?php echo date('Y'); ?> © <?php _e('All rights reserved', 'dssa-pmpro-helper'); ?></p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Send notification email to membership manager
     */
    private function send_manager_notification_email($user, $is_legacy, $card_payments) {
        $manager_emails = dssa_pmpro_helper_get_setting('membership_manager_emails', get_bloginfo('admin_email'));
        $emails = array_map('trim', explode(',', $manager_emails));
        
        if (empty($emails)) {
            return;
        }
        
        $subject = $is_legacy
            ? sprintf(__('[DSSA] Legacy Member Registration: %s', 'dssa-pmpro-helper'), $user->user_login)
            : sprintf(__('[DSSA] New Member Application: %s', 'dssa-pmpro-helper'), $user->user_login);
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . dssa_pmpro_helper_get_setting('notification_from_name', get_bloginfo('name')) . 
                 ' <' . dssa_pmpro_helper_get_setting('notification_from_email', get_bloginfo('admin_email')) . '>',
        ];
        
        $message = $this->get_manager_notification_email_content($user, $is_legacy, $card_payments);
        
        foreach ($emails as $email) {
            if (is_email($email)) {
                wp_mail($email, $subject, $message, $headers);
            }
        }
    }
    
    /**
     * Get manager notification email content
     */
    private function get_manager_notification_email_content($user, $is_legacy, $card_payments) {
        $member_number = get_user_meta($user->ID, 'dssa_member_number', true);
        $branch = get_user_meta($user->ID, 'dssa_branch', true);
        $admin_url = admin_url('user-edit.php?user_id=' . $user->ID);
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php _e('DSSA Member Notification', 'dssa-pmpro-helper'); ?></title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 700px; margin: 0 auto; padding: 20px; }
                .header { background-color: #2c5e8e; color: white; padding: 15px; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                .info-table th, .info-table td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
                .info-table th { background-color: #f2f2f2; }
                .action-box { background-color: #e8f5e9; padding: 15px; margin: 20px 0; border: 1px solid #4caf50; }
                .legacy-box { background-color: #e3f2fd; padding: 15px; margin: 20px 0; border: 1px solid #2196f3; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2><?php echo $is_legacy ? __('Legacy Member Registration', 'dssa-pmpro-helper') : __('New Member Application', 'dssa-pmpro-helper'); ?></h2>
                </div>
                
                <div class="content">
                    <?php if ($is_legacy): ?>
                    <div class="legacy-box">
                        <h3><?php _e('Legacy Member Registered', 'dssa-pmpro-helper'); ?></h3>
                        <p><?php _e('An existing DSSA member has registered on the website and their membership has been automatically activated.', 'dssa-pmpro-helper'); ?></p>
                    </div>
                    <?php else: ?>
                    <div class="action-box">
                        <h3><?php _e('Action Required', 'dssa-pmpro-helper'); ?></h3>
                        <p><?php _e('A new member has applied and requires your attention to assign a membership number and activate their membership.', 'dssa-pmpro-helper'); ?></p>
                        <p><a href="<?php echo esc_url($admin_url); ?>"><?php _e('Review and approve this member', 'dssa-pmpro-helper'); ?></a></p>
                    </div>
                    <?php endif; ?>
                    
                    <h3><?php _e('Member Details', 'dssa-pmpro-helper'); ?></h3>
                    <table class="info-table">
                        <tr>
                            <th><?php _e('Name:', 'dssa-pmpro-helper'); ?></th>
                            <td><?php echo esc_html($user->first_name . ' ' . $user->last_name); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Username:', 'dssa-pmpro-helper'); ?></th>
                            <td><?php echo esc_html($user->user_login); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Email:', 'dssa-pmpro-helper'); ?></th>
                            <td><?php echo esc_html($user->user_email); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Registration Date:', 'dssa-pmpro-helper'); ?></th>
                            <td><?php echo date_i18n(get_option('date_format'), strtotime($user->user_registered)); ?></td>
                        </tr>
                        <?php if (!empty($member_number)): ?>
                        <tr>
                            <th><?php _e('Membership Number:', 'dssa-pmpro-helper'); ?></th>
                            <td><?php echo esc_html($member_number); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($branch)): ?>
                        <tr>
                            <th><?php _e('Branch:', 'dssa-pmpro-helper'); ?></th>
                            <td><?php echo esc_html($branch); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th><?php _e('Member Type:', 'dssa-pmpro-helper'); ?></th>
                            <td><?php echo $is_legacy ? __('Legacy Member', 'dssa-pmpro-helper') : __('New Member', 'dssa-pmpro-helper'); ?></td>
                        </tr>
                        <?php if (!$is_legacy): ?>
                        <tr>
                            <th><?php _e('Payment Method:', 'dssa-pmpro-helper'); ?></th>
                            <td><?php echo $card_payments ? __('Card Payments', 'dssa-pmpro-helper') : __('EFT Required', 'dssa-pmpro-helper'); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    
                    <p>
                        <a href="<?php echo esc_url($admin_url); ?>">
                            <?php _e('View Full Member Profile in Admin', 'dssa-pmpro-helper'); ?>
                        </a>
                    </p>
                    
                    <?php if (!$is_legacy): ?>
                    <h3><?php _e('Required Actions:', 'dssa-pmpro-helper'); ?></h3>
                    <ol>
                        <li><?php _e('Verify member information', 'dssa-pmpro-helper'); ?></li>
                        <li><?php _e('Assign a membership number', 'dssa-pmpro-helper'); ?></li>
                        <li><?php _e('Assign/confirm branch allocation', 'dssa-pmpro-helper'); ?></li>
                        <li><?php _e('Verify payment (if EFT selected)', 'dssa-pmpro-helper'); ?></li>
                        <li><?php _e('Activate membership benefits', 'dssa-pmpro-helper'); ?></li>
                    </ol>
                    <?php endif; ?>
                    
                    <p><?php _e('This is an automated notification from the DSSA membership system.', 'dssa-pmpro-helper'); ?></p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}

// Initialize the class
DSSA_PMPro_Helper_Registration::init();

