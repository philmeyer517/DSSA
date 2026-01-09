<?php
/**
 * DSSA PMPro Helper - Branch Management
 * Simple version that just handles branch assignments
 */

if (!defined('ABSPATH')) {
    exit;
}

class DSSA_PMPro_Helper_Branch_Management {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public static function init() {
        $instance = self::get_instance();
        
        // We'll add the AJAX handlers directly in the admin interface
        // This class is mostly for organization
        
        return $instance;
    }
    
    /**
     * Get branches from PMPro User Fields
     */
    public static function get_branches_from_pmpro() {
        $branches = array();
        
        // Method 1: Check PMPro options directly
        $pmpro_fields = get_option('pmpro_user_fields', array());
        
        if (!empty($pmpro_fields) && is_array($pmpro_fields)) {
            foreach ($pmpro_fields as $field) {
                if (isset($field['name']) && $field['name'] === 'branch' && !empty($field['options'])) {
                    if (is_array($field['options'])) {
                        $branches = $field['options'];
                    } elseif (is_string($field['options'])) {
                        $branches = array_map('trim', explode(',', $field['options']));
                    }
                    break;
                }
            }
        }
        
        // Method 2: Check if branch field has its own option
        if (empty($branches)) {
            $branch_field = get_option('pmpro_user_field_branch');
            if (!empty($branch_field) && isset($branch_field['options'])) {
                if (is_array($branch_field['options'])) {
                    $branches = $branch_field['options'];
                }
            }
        }
        
        // Method 3: Try to get from database directly
        if (empty($branches)) {
            global $wpdb;
            $branch_data = $wpdb->get_var(
                "SELECT option_value FROM {$wpdb->options} 
                 WHERE option_name = 'pmpro_user_fields' 
                 AND option_value LIKE '%branch%'"
            );
            
            if ($branch_data) {
                $fields = maybe_unserialize($branch_data);
                if (is_array($fields)) {
                    foreach ($fields as $field) {
                        if (isset($field['name']) && $field['name'] === 'branch' && !empty($field['options'])) {
                            if (is_array($field['options'])) {
                                $branches = $field['options'];
                            }
                            break;
                        }
                    }
                }
            }
        }
        
        // If still no branches, use defaults
        if (empty($branches)) {
            $branches = array(
				'Select / Kies...',
				'Gqeberha Atalaya',
				'Bauhinia',
				'Boekenhout',
				'Celtis',
				'Elongatus',
				'Encephalartos',
				'Erythrina',
				'Kameeldoring',
				'Kanniedood',
				'Karee',
				'Kierieklapper',
				'Kremetart',
				'Langberg',
				'Magaliesberg',
				'Manketti',
				'Matumi',
				'Musasa',
				'Outeniqua',
				'Pilanesberg',
				'Soutpansberg',
				'Springbokvlakte',
				'Taaibos',
				'Umdoni',
				'Waterberg',
				'Welwitschia',
				'Western Province',
				'Wolkberg',
				'Zululand'
            );
        }
        
        return $branches;
    }
}