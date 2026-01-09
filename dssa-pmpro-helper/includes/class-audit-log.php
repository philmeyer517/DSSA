<?php
/**
 * Audit Log management for DSSA PMPro Helper
 */

if (!defined('ABSPATH')) {
    exit;
}

class DSSA_PMPro_Helper_Audit_Log {
    
    /**
     * Initialize the audit log class
     */
    public static function init() {
        // Add cleanup cron hook
        add_action('dssa_pmpro_helper_daily_audit_cleanup', [__CLASS__, 'cleanup_old_entries']);
        
        // Hook into user actions for automatic logging
        add_action('user_register', [__CLASS__, 'log_user_registration'], 10, 1);
        add_action('profile_update', [__CLASS__, 'log_profile_update'], 10, 2);
        add_action('delete_user', [__CLASS__, 'log_user_deletion'], 10, 2);
        
        // Hook into PMPro actions
        add_action('pmpro_after_checkout', [__CLASS__, 'log_pmpro_checkout'], 10, 2);
        add_action('pmpro_before_change_membership_level', [__CLASS__, 'log_membership_change'], 10, 4);
        
        // Hook into our plugin actions
        add_action('dssa_pmpro_helper_legacy_number_claimed', [__CLASS__, 'log_legacy_number_claimed'], 10, 2);
        add_action('dssa_pmpro_helper_legacy_number_imported', [__CLASS__, 'log_legacy_number_imported'], 10, 1);
    }
    
    /**
     * Add an entry to the audit log
     * 
     * @param int|null $user_id User ID (null for system actions)
     * @param string $action Action performed
     * @param mixed $details Additional details (will be serialized)
     * @return int|false The log entry ID or false on failure
     */
    public static function add_entry($user_id, $action, $details = null) {
        global $wpdb;
        
        // Check if audit logging is enabled
        if (!dssa_pmpro_helper_get_setting('enable_audit_log', true)) {
            return false;
        }
        
        $table_name = $wpdb->prefix . 'dssa_audit_log';
        
        $data = [
            'user_id' => $user_id,
            'action' => sanitize_text_field($action),
            'ip_address' => self::get_user_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
        ];
        
        // Serialize details if provided
        if ($details !== null) {
            $data['details'] = maybe_serialize($details);
        }
        
        $format = [
            '%d', // user_id
            '%s', // action
            '%s', // ip_address
            '%s', // user_agent
            '%s', // details (optional)
        ];
        
        if ($details !== null) {
            $data['details'] = maybe_serialize($details);
            $format[] = '%s';
        }
        
        $result = $wpdb->insert($table_name, $data, $format);
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        // Log error but don't break execution
        dssa_pmpro_helper_log(
            'Failed to add audit log entry',
            [
                'error' => $wpdb->last_error,
                'data' => $data,
            ],
            'error'
        );
        
        return false;
    }
    
    /**
     * Get user IP address
     */
    private static function get_user_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        // Handle multiple IPs in X_FORWARDED_FOR
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }
    
    /**
     * Get audit log entries with filters
     * 
     * @param array $args Query arguments
     * @return array
     */
    public static function get_entries($args = []) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dssa_audit_log';
        
        $defaults = [
            'user_id' => null,
            'action' => null,
            'start_date' => null,
            'end_date' => null,
            'search' => '',
            'per_page' => 50,
            'page' => 1,
            'orderby' => 'timestamp',
            'order' => 'DESC',
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Build WHERE clause
        $where = ['1=1'];
        $prepare_values = [];
        
        if ($args['user_id']) {
            $where[] = 'user_id = %d';
            $prepare_values[] = $args['user_id'];
        }
        
        if ($args['action']) {
            $where[] = 'action = %s';
            $prepare_values[] = $args['action'];
        }
        
        if ($args['start_date']) {
            $where[] = 'timestamp >= %s';
            $prepare_values[] = $args['start_date'];
        }
        
        if ($args['end_date']) {
            $where[] = 'timestamp <= %s';
            $prepare_values[] = $args['end_date'];
        }
        
        if ($args['search']) {
            $where[] = '(action LIKE %s OR details LIKE %s OR ip_address LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $prepare_values[] = $search_term;
            $prepare_values[] = $search_term;
            $prepare_values[] = $search_term;
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Build ORDER BY clause
        $allowed_orderby = ['id', 'user_id', 'action', 'timestamp', 'ip_address'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'timestamp';
        $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';
        
        // Calculate offset
        $offset = ($args['page'] - 1) * $args['per_page'];
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
        if (!empty($prepare_values)) {
            $count_query = $wpdb->prepare($count_query, $prepare_values);
        }
        $total = $wpdb->get_var($count_query);
        
        // Get results
        $query = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $prepare_values[] = $args['per_page'];
        $prepare_values[] = $offset;
        
        $results = $wpdb->get_results($wpdb->prepare($query, $prepare_values), ARRAY_A);
        
        // Unserialize details
        foreach ($results as &$row) {
            if (!empty($row['details'])) {
                $row['details'] = maybe_unserialize($row['details']);
            }
            
            // Add user display name if user_id exists
            if ($row['user_id']) {
                $user = get_user_by('id', $row['user_id']);
                $row['user_display_name'] = $user ? $user->display_name : __('User not found', 'dssa-pmpro-helper');
                $row['user_email'] = $user ? $user->user_email : '';
            }
        }
        
        return [
            'items' => $results,
            'total' => $total,
            'pages' => ceil($total / $args['per_page']),
            'page' => $args['page'],
            'per_page' => $args['per_page'],
        ];
    }
    
    /**
     * Clean up old audit log entries
     */
    public static function cleanup_old_entries() {
        return DSSA_PMPro_Helper_Database::cleanup_audit_log();
    }
    
    /**
     * Get statistics about audit log
     * 
     * @return array
     */
    public static function get_statistics() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dssa_audit_log';
        
        $stats = [
            'total_entries' => 0,
            'entries_today' => 0,
            'entries_this_week' => 0,
            'entries_this_month' => 0,
            'top_actions' => [],
            'top_users' => [],
        ];
        
        // Total entries
        $stats['total_entries'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        
        // Today's entries
        $today = date('Y-m-d');
        $stats['entries_today'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE DATE(timestamp) = %s",
            $today
        ));
        
        // This week's entries
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $stats['entries_this_week'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE DATE(timestamp) >= %s",
            $week_start
        ));
        
        // This month's entries
        $month_start = date('Y-m-01');
        $stats['entries_this_month'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE DATE(timestamp) >= %s",
            $month_start
        ));
        
        // Top 10 actions
        $top_actions = $wpdb->get_results(
            "SELECT action, COUNT(*) as count 
             FROM {$table_name} 
             GROUP BY action 
             ORDER BY count DESC 
             LIMIT 10",
            ARRAY_A
        );
        
        foreach ($top_actions as $action) {
            $stats['top_actions'][$action['action']] = $action['count'];
        }
        
        // Top 10 users
        $top_users = $wpdb->get_results(
            "SELECT user_id, COUNT(*) as count 
             FROM {$table_name} 
             WHERE user_id IS NOT NULL 
             GROUP BY user_id 
             ORDER BY count DESC 
             LIMIT 10",
            ARRAY_A
        );
        
        foreach ($top_users as $user) {
            $user_data = get_user_by('id', $user['user_id']);
            if ($user_data) {
                $stats['top_users'][$user['user_id']] = [
                    'count' => $user['count'],
                    'display_name' => $user_data->display_name,
                    'email' => $user_data->user_email,
                ];
            }
        }
        
        return $stats;
    }
    
    /**
     * Export audit log to CSV
     * 
     * @param array $args Query arguments (same as get_entries)
     * @return string CSV content
     */
    public static function export_to_csv($args = []) {
        $entries = self::get_entries(array_merge($args, ['per_page' => -1]));
        
        $csv_data = [];
        $headers = [
            'ID',
            'Timestamp',
            'User ID',
            'User Name',
            'User Email',
            'Action',
            'IP Address',
            'User Agent',
            'Details',
        ];
        
        $csv_data[] = $headers;
        
        foreach ($entries['items'] as $entry) {
            $details = '';
            if (!empty($entry['details'])) {
                if (is_array($entry['details']) || is_object($entry['details'])) {
                    $details = json_encode($entry['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $details = $entry['details'];
                }
            }
            
            $csv_data[] = [
                $entry['id'],
                $entry['timestamp'],
                $entry['user_id'] ?: '',
                $entry['user_display_name'] ?? '',
                $entry['user_email'] ?? '',
                $entry['action'],
                $entry['ip_address'],
                $entry['user_agent'],
                $details,
            ];
        }
        
        // Generate CSV
        $output = fopen('php://temp', 'r+');
        foreach ($csv_data as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
    
    /**
     * Log user registration
     */
    public static function log_user_registration($user_id) {
        $user = get_user_by('id', $user_id);
        
        if ($user) {
            self::add_entry($user_id, 'user_registered', [
                'username' => $user->user_login,
                'email' => $user->user_email,
                'roles' => $user->roles,
            ]);
        }
    }
    
    /**
     * Log user profile update
     */
    public static function log_profile_update($user_id, $old_user_data) {
        $user = get_user_by('id', $user_id);
        
        if ($user) {
            $changes = [];
            $old_data = (array) $old_user_data;
            
            // Check for changed fields
            $fields_to_check = ['user_email', 'first_name', 'last_name', 'display_name'];
            foreach ($fields_to_check as $field) {
                if (isset($old_data[$field]) && $old_data[$field] != $user->$field) {
                    $changes[$field] = [
                        'old' => $old_data[$field],
                        'new' => $user->$field,
                    ];
                }
            }
            
            if (!empty($changes)) {
                self::add_entry($user_id, 'profile_updated', [
                    'changes' => $changes,
                ]);
            }
        }
    }
    
    /**
     * Log user deletion
     */
    public static function log_user_deletion($user_id, $reassign) {
        $user = get_user_by('id', $user_id);
        
        if ($user) {
            self::add_entry(get_current_user_id(), 'user_deleted', [
                'deleted_user_id' => $user_id,
                'deleted_username' => $user->user_login,
                'deleted_email' => $user->user_email,
                'reassigned_to' => $reassign,
            ]);
        }
    }
    
    /**
     * Log PMPro checkout
     */
    public static function log_pmpro_checkout($user_id, $level) {
        self::add_entry($user_id, 'pmpro_checkout', [
            'level_id' => $level->id,
            'level_name' => $level->name,
            'initial_payment' => $level->initial_payment,
            'billing_amount' => $level->billing_amount,
        ]);
    }
    
    /**
     * Log PMPro membership change
     */
    public static function log_membership_change($level_id, $user_id, $old_levels, $cancel_level) {
        $action = $level_id > 0 ? 'membership_level_added' : 'membership_level_removed';
        
        self::add_entry(get_current_user_id(), $action, [
            'user_id' => $user_id,
            'level_id' => abs($level_id),
            'old_levels' => $old_levels,
            'cancel_level' => $cancel_level,
        ]);
    }
    
    /**
     * Log legacy number claim
     */
    public static function log_legacy_number_claimed($user_id, $membership_number) {
        self::add_entry($user_id, 'legacy_number_claimed', [
            'membership_number' => $membership_number,
        ]);
    }
    
    /**
     * Log legacy number import
     */
    public static function log_legacy_number_imported($results) {
        self::add_entry(get_current_user_id(), 'legacy_numbers_imported', $results);
    }
    
    /**
     * Log payment event
     */
    public static function log_payment($user_id, $amount, $transaction_id, $gateway, $status) {
        self::add_entry($user_id, 'payment_' . $status, [
            'amount' => $amount,
            'transaction_id' => $transaction_id,
            'gateway' => $gateway,
            'status' => $status,
        ]);
    }
    
    /**
     * Log admin action
     */
    public static function log_admin_action($action, $details = []) {
        self::add_entry(get_current_user_id(), 'admin_' . $action, $details);
    }
}