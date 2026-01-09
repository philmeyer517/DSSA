<?php
/**
 * Updated Login System for DSSA PMPro Helper
 * Login only with membership number, styled like PMPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class DSSA_PMPro_Helper_Login_System {
    
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
        
        // Add shortcodes
        add_shortcode('custom_pmpro_login_form', [$instance, 'shortcode_login_form']);
        add_shortcode('custom_pmpro_reset_password', [$instance, 'shortcode_reset_password']);
        
        // Hook into authentication - EARLIER priority
        add_filter('authenticate', [$instance, 'authenticate_membership_number'], 20, 3);
        
        // Handle custom login errors
        add_action('wp_login_failed', [$instance, 'login_failed'], 10, 2);
        
        // Add login redirect
        add_filter('login_redirect', [$instance, 'login_redirect'], 10, 3);
        
        // Add logout redirect
        add_action('wp_logout', [$instance, 'logout_redirect']);
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', [$instance, 'enqueue_scripts']);
        
        // Add AJAX handler for password reset
        add_action('wp_ajax_nopriv_dssa_reset_password', [$instance, 'ajax_reset_password']);
        add_action('wp_ajax_dssa_reset_password', [$instance, 'ajax_reset_password']);
        
        // Intercept login form submission
        add_action('login_form_login', [$instance, 'intercept_login_submission']);
        
        return $instance;
    }
    
    /**
     * Intercept login form submission
     */
    public function intercept_login_submission() {
        if (isset($_POST['dssa_login_nonce']) && wp_verify_nonce($_POST['dssa_login_nonce'], 'dssa-login-nonce')) {
            // This is our custom login form
            $redirect_to = isset($_POST['redirect_to']) ? $_POST['redirect_to'] : home_url('/members-area/');
            $user = wp_signon(array(
                'user_login'    => isset($_POST['dssa_membership_number']) ? $_POST['dssa_membership_number'] : '',
                'user_password' => isset($_POST['pwd']) ? $_POST['pwd'] : '',
                'remember'      => isset($_POST['rememberme'])
            ), is_ssl());
            
            if (is_wp_error($user)) {
                // Redirect back with error
                $error_code = $user->get_error_code();
                $error_message = $user->get_error_message();
                $login_url = home_url('/members-login/');
                $login_url = add_query_arg('login_error', urlencode($error_message), $login_url);
                wp_redirect($login_url);
                exit;
            } else {
                // Successful login
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID, isset($_POST['rememberme']));
                do_action('wp_login', $user->user_login, $user);
                wp_redirect($redirect_to);
                exit;
            }
        }
    }
    
    /**
     * Handle login failed
     */
    public function login_failed($username, $error) {
        // Check if this was our custom login
        if (isset($_POST['dssa_login_nonce']) && wp_verify_nonce($_POST['dssa_login_nonce'], 'dssa-login-nonce')) {
            $login_url = home_url('/members-login/');
            $login_url = add_query_arg('login_error', urlencode($error->get_error_message()), $login_url);
            wp_redirect($login_url);
            exit;
        }
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        global $post;
        
        if (!is_a($post, 'WP_Post')) {
            return;
        }
        
        $is_login_page = has_shortcode($post->post_content, 'custom_pmpro_login_form');
        $is_reset_page = has_shortcode($post->post_content, 'custom_pmpro_reset_password');
        
        if ($is_login_page || $is_reset_page) {
            // Enqueue CSS
            wp_enqueue_style(
                'dssa-login-css',
                plugin_dir_url(__FILE__) . '../assets/css/login.css',
                array(),
                '1.0.0'
            );
            
            // Enqueue JavaScript
            wp_enqueue_script(
                'dssa-login-js',
                plugin_dir_url(__FILE__) . '../assets/js/login.js',
                array('jquery'),
                '1.0.0',
                true
            );
            
            // Localize script for AJAX
            wp_localize_script('dssa-login-js', 'dssa_login', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('dssa-reset-nonce'),
                'strings' => array(
                    'show' => __('Show password', 'dssa-pmpro-helper'),
                    'hide' => __('Hide password', 'dssa-pmpro-helper'),
                    'loading' => __('Processing...', 'dssa-pmpro-helper'),
                    'error' => __('An error occurred.', 'dssa-pmpro-helper'),
                )
            ));
        }
    }
    
    /**
     * Login form shortcode
     */
    public function shortcode_login_form($atts, $content = null) {
        // Don't show if already logged in
        if (is_user_logged_in()) {
            $redirect = isset($atts['redirect']) ? esc_url($atts['redirect']) : home_url('/members-area/');
            return '<div class="dssa-already-logged-in">' . 
                   __('You are already logged in.', 'dssa-pmpro-helper') . 
                   ' <a href="' . wp_logout_url(home_url()) . '">' . 
                   __('Log out?', 'dssa-pmpro-helper') . '</a></div>';
        }
        
        ob_start();
        ?>
        <div class="dssa-pmpro-login-wrapper pmpro">
            <div class="pmpro_login_wrap">
                <h2><?php _e('Member Login', 'dssa-pmpro-helper'); ?> / Lid Aantekening</h2>
                
                <?php
                // Show errors if any
                if (isset($_GET['login_error'])) {
                    echo '<div class="pmpro_message pmpro_error">';
                    echo esc_html(urldecode($_GET['login_error']));
                    echo '</div>';
                }
                
                // Show logout message
                if (isset($_GET['loggedout']) && $_GET['loggedout'] == 'true') {
                    echo '<div class="pmpro_message pmpro_success">';
                    _e('You have been logged out.', 'dssa-pmpro-helper');
                    echo '</div>';
                }
                ?>
                
                <form class="pmpro_form" id="dssa-login-form" method="post">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url(home_url('/members-area/')); ?>">
                    
                    <div class="pmpro_login-fields">
                        <!-- Membership Number Field -->
                        <div class="pmpro_checkout-field pmpro_checkout-field-username">
                            <label for="dssa_membership_number">
                                <?php _e('Membership Number', 'dssa-pmpro-helper'); ?> / Lidnommer
                                <span class="pmpro_asterisk">*</span>
                            </label>
                            <input id="dssa_membership_number" 
                                   name="dssa_membership_number" 
                                   type="text" 
                                   class="input pmpro_required" 
                                   value="<?php echo esc_attr(isset($_POST['dssa_membership_number']) ? $_POST['dssa_membership_number'] : ''); ?>" 
                                   required
                                   placeholder="<?php esc_attr_e('Enter your membership number', 'dssa-pmpro-helper'); ?>">
                            <div class="pmpro_field_description">
                                <?php _e('Enter your DSSA membership number', 'dssa-pmpro-helper'); ?>
                            </div>
                        </div>
                        
                        <!-- Password Field -->
                        <div class="pmpro_checkout-field pmpro_checkout-field-password">
                            <label for="dssa_password">
                                <?php _e('Password', 'dssa-pmpro-helper'); ?> / Wagwoord
                                <span class="pmpro_asterisk">*</span>
                            </label>
                            <div class="pmpro_password_wrapper">
                                <input id="dssa_password" 
                                       name="pwd" 
                                       type="password" 
                                       class="input pmpro_required" 
                                       required
                                       placeholder="<?php esc_attr_e('Enter your password', 'dssa-pmpro-helper'); ?>">
                                <button type="button" class="pmpro_btn pmpro_btn-link pmpro_show_password" aria-label="<?php esc_attr_e('Show password', 'dssa-pmpro-helper'); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Remember Me -->
                        <div class="pmpro_checkout-field pmpro_checkout-field-rememberme">
                            <label class="pmpro_label-inline">
                                <input name="rememberme" type="checkbox" id="rememberme" value="forever">
                                <?php _e('Remember Me', 'dssa-pmpro-helper'); ?> / Onthou my
                            </label>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="pmpro_submit">
                            <button type="submit" class="pmpro_btn pmpro_btn-submit-checkout" id="dssa-login-submit">
                                <?php _e('Log In', 'dssa-pmpro-helper'); ?> / Teken Aan
                            </button>
                            <?php wp_nonce_field('dssa-login-nonce', 'dssa_login_nonce'); ?>
                        </div>
                    </div>
                </form>
                
                <!-- Links -->
                <div class="pmpro_login-links">
                    <p>
                        <a href="<?php echo home_url('/reset-password/'); ?>" class="pmpro_btn pmpro_btn-link">
                            <?php _e('Forgot your password?', 'dssa-pmpro-helper'); ?> / Wagwoord vergeet?
                        </a>
                    </p>
                    <p>
                        <?php _e('Not a member yet?', 'dssa-pmpro-helper'); ?>
                        <a href="<?php echo pmpro_url('checkout'); ?>" class="pmpro_btn pmpro_btn-link">
                            <?php _e('Register here', 'dssa-pmpro-helper'); ?> / Registreer hier
                        </a>
                    </p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Authenticate with membership number
     */
    public function authenticate_membership_number($user, $username, $password) {
        // Check if this is our custom login
        if (empty($_POST['dssa_login_nonce']) || !wp_verify_nonce($_POST['dssa_login_nonce'], 'dssa-login-nonce')) {
            return $user;
        }
        
        // Get membership number
        $membership_number = isset($_POST['dssa_membership_number']) ? trim($_POST['dssa_membership_number']) : '';
        
        if (empty($membership_number)) {
            return new WP_Error('empty_membership_number', __('Membership number is required.', 'dssa-pmpro-helper'));
        }
        
        if (empty($password)) {
            return new WP_Error('empty_password', __('Password is required.', 'dssa-pmpro-helper'));
        }
        
        // Find user by membership number
        $user_id = $this->get_user_by_membership_number($membership_number);
        
        if (!$user_id) {
            return new WP_Error('invalid_membership_number', __('Invalid membership number.', 'dssa-pmpro-helper'));
        }
        
        // Get user
        $user = get_user_by('id', $user_id);
        
        if (!$user) {
            return new WP_Error('invalid_user', __('User not found.', 'dssa-pmpro-helper'));
        }
        
        // Verify password
        if (!wp_check_password($password, $user->user_pass, $user->ID)) {
            return new WP_Error('incorrect_password', __('Incorrect password.', 'dssa-pmpro-helper'));
        }
        
        return $user;
    }
    
    /**
     * Get user ID by membership number
     */
    private function get_user_by_membership_number($membership_number) {
        global $wpdb;
        
        $membership_number = sanitize_text_field($membership_number);
        
        // First check our custom meta
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} 
             WHERE meta_key = 'dssa_membership_number' 
             AND meta_value = %s",
            $membership_number
        ));
        
        if ($user_id) {
            return $user_id;
        }
        
        // Also check the PMPro field if it exists
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} 
             WHERE meta_key = 'member_number' 
             AND meta_value = %s",
            $membership_number
        ));
        
        return $user_id;
    }
    
    /**
     * Login redirect
     */
    public function login_redirect($redirect_to, $requested_redirect_to, $user) {
        // Check if this was our custom login
        if (isset($_POST['dssa_login_nonce']) && wp_verify_nonce($_POST['dssa_login_nonce'], 'dssa-login-nonce')) {
            return home_url('/members-area/');
        }
        
        return $redirect_to;
    }
    
    /**
     * Logout redirect
     */
    public function logout_redirect() {
        wp_safe_redirect(home_url());
        exit;
    }
    
    /**
     * Password reset shortcode
     */
    public function shortcode_reset_password($atts, $content = null) {
        // Don't show if already logged in
        if (is_user_logged_in()) {
            return '<div class="dssa-already-logged-in">' . 
                   __('You are already logged in.', 'dssa-pmpro-helper') . 
                   ' <a href="' . wp_logout_url(home_url()) . '">' . 
                   __('Log out?', 'dssa-pmpro-helper') . '</a></div>';
        }
        
        ob_start();
        ?>
        <div class="dssa-pmpro-reset-wrapper pmpro">
            <div class="pmpro_reset_wrap">
                <h2><?php _e('Reset Password', 'dssa-pmpro-helper'); ?> / Herstel Wagwoord</h2>
                
                <?php
                // Show messages if any
                if (isset($_GET['reset'])) {
                    if ($_GET['reset'] == 'success') {
                        echo '<div class="pmpro_message pmpro_success">';
                        _e('Check your email for the password reset link.', 'dssa-pmpro-helper');
                        echo '</div>';
                    } elseif ($_GET['reset'] == 'error') {
                        echo '<div class="pmpro_message pmpro_error">';
                        echo isset($_GET['message']) ? esc_html(urldecode($_GET['message'])) : __('An error occurred.', 'dssa-pmpro-helper');
                        echo '</div>';
                    }
                }
                ?>
                
                <form class="pmpro_form" id="dssa-reset-form" method="post">
                    <div class="pmpro_checkout-field pmpro_checkout-field-membership_number">
                        <label for="dssa_reset_membership_number">
                            <?php _e('Membership Number', 'dssa-pmpro-helper'); ?> / Lidnommer
                            <span class="pmpro_asterisk">*</span>
                        </label>
                        <input id="dssa_reset_membership_number" 
                               name="dssa_reset_membership_number" 
                               type="text" 
                               class="input pmpro_required" 
                               required
                               placeholder="<?php esc_attr_e('Enter your membership number', 'dssa-pmpro-helper'); ?>">
                        <div class="pmpro_field_description">
                            <?php _e('Enter your DSSA membership number to reset your password', 'dssa-pmpro-helper'); ?>
                        </div>
                    </div>
                    
                    <div class="pmpro_submit">
                        <button type="submit" class="pmpro_btn pmpro_btn-submit-checkout" id="dssa-reset-submit">
                            <?php _e('Reset Password', 'dssa-pmpro-helper'); ?> / Herstel Wagwoord
                        </button>
                        <?php wp_nonce_field('dssa-reset-nonce', 'dssa_reset_nonce'); ?>
                    </div>
                    
                    <div class="pmpro_login-links">
                        <p>
                            <a href="<?php echo home_url('/members-login/'); ?>" class="pmpro_btn pmpro_btn-link">
                                <?php _e('Back to Login', 'dssa-pmpro-helper'); ?> / Terug na Aantekening
                            </a>
                        </p>
                    </div>
                </form>
                
                <div id="dssa-reset-message" class="pmpro_message" style="display: none;"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX password reset
     */
    public function ajax_reset_password() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dssa-reset-nonce')) {
            wp_send_json_error(__('Security check failed.', 'dssa-pmpro-helper'));
        }
        
        // Check rate limiting
        if (!$this->check_rate_limit()) {
            wp_send_json_error(__('Too many reset attempts. Please try again in an hour.', 'dssa-pmpro-helper'));
        }
        
        $membership_number = isset($_POST['membership_number']) ? sanitize_text_field($_POST['membership_number']) : '';
        
        if (empty($membership_number)) {
            wp_send_json_error(__('Please enter your membership number.', 'dssa-pmpro-helper'));
        }
        
        // Find user by membership number
        $user_id = $this->get_user_by_membership_number($membership_number);
        
        if (!$user_id) {
            wp_send_json_error(__('No account found with that membership number.', 'dssa-pmpro-helper'));
        }
        
        $user = get_user_by('id', $user_id);
        
        if (!$user) {
            wp_send_json_error(__('User not found.', 'dssa-pmpro-helper'));
        }
        
        // Generate reset key
        $key = get_password_reset_key($user);
        
        if (is_wp_error($key)) {
            wp_send_json_error(__('Failed to generate reset key.', 'dssa-pmpro-helper'));
        }
        
        // Send reset email
        $sent = $this->send_password_reset_email($user, $key);
        
        if ($sent) {
            // Log the reset attempt
            if (class_exists('DSSA_PMPro_Helper_Audit_Log')) {
                DSSA_PMPro_Helper_Audit_Log::add_entry(
                    $user_id,
                    'password_reset_requested',
                    [
                        'membership_number' => $membership_number,
                        'ip_address' => $_SERVER['REMOTE_ADDR'],
                    ]
                );
            }
            
            wp_send_json_success(__('Password reset email sent. Check your inbox.', 'dssa-pmpro-helper'));
        } else {
            wp_send_json_error(__('Failed to send reset email.', 'dssa-pmpro-helper'));
        }
    }
    
    /**
     * Check rate limiting
     */
    private function check_rate_limit() {
        $ip = $_SERVER['REMOTE_ADDR'];
        $transient_key = 'dssa_reset_limit_' . $ip;
        $attempts = get_transient($transient_key);
        
        if ($attempts === false) {
            $attempts = 0;
            set_transient($transient_key, 1, HOUR_IN_SECONDS);
            return true;
        }
        
        if ($attempts >= 3) {
            return false;
        }
        
        set_transient($transient_key, $attempts + 1, HOUR_IN_SECONDS);
        return true;
    }
    
    /**
     * Send password reset email
     */
    private function send_password_reset_email($user, $key) {
        $to = $user->user_email;
        $subject = __('Reset Your DSSA Membership Password', 'dssa-pmpro-helper');
        
        $reset_link = network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user->user_login), 'login');
        
        $message = $this->get_password_reset_email_template([
            'member_name' => $user->display_name,
            'reset_link' => $reset_link,
            'site_name' => get_bloginfo('name'),
        ]);
        
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $from_email = get_option('admin_email');
        $from_name = get_bloginfo('name');
        
        if ($from_email && $from_name) {
            $headers[] = "From: $from_name <$from_email>";
        }
        
        return wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Password reset email template
     */
    private function get_password_reset_email_template($data) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Reset Your Password</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #2c5aa0; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; border: 1px solid #ddd; }
                .button { display: inline-block; padding: 10px 20px; background-color: #2c5aa0; color: white; text-decoration: none; border-radius: 4px; }
                .note { color: #666; font-size: 14px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Dendrological Society of South Africa</h1>
            </div>
            <div class="content">
                <h2>Password Reset Request</h2>
                <p>Dear <?php echo esc_html($data['member_name']); ?>,</p>
                <p>You have requested to reset your password for your DSSA membership account.</p>
                <p>To reset your password, click the button below:</p>
                <p style="text-align: center; margin: 30px 0;">
                    <a href="<?php echo esc_url($data['reset_link']); ?>" class="button">Reset Password</a>
                </p>
                <p>If you didn't request this password reset, you can safely ignore this email.</p>
                <p>This link will expire in 24 hours.</p>
                <div class="note">
                    <p>If the button doesn't work, copy and paste this link into your browser:</p>
                    <p><small><?php echo esc_url($data['reset_link']); ?></small></p>
                </div>
                <p>Best regards,<br>The DSSA Team</p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}