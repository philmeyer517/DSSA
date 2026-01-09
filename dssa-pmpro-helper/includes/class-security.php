<?php
/**
 * Security class for DSSA PMPro Helper
 */

if (!defined('ABSPATH')) {
    exit;
}

class DSSA_PMPro_Helper_Security {
    
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
        // Phase 1: Basic security setup
        // Phase 2 will add login/password reset functionality
        return $instance;
    }
    
    /**
     * Check if current user can manage DSSA
     */
    public static function can_manage_dssa() {
        return current_user_can('manage_dssa') || current_user_can('administrator');
    }
    
    /**
     * Verify nonce for DSSA actions
     */
    public static function verify_nonce($action = 'dssa_action', $nonce_field = 'dssa_nonce') {
        if (!isset($_REQUEST[$nonce_field]) || !wp_verify_nonce($_REQUEST[$nonce_field], $action)) {
            wp_die(__('Security check failed. Please try again.', 'dssa-pmpro-helper'));
        }
        return true;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Private to prevent direct instantiation
    }
}