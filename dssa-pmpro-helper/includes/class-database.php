<?php
/**
 * Database management for DSSA PMPro Helper
 */

if (!defined('ABSPATH')) {
    exit;
}

class DSSA_PMPro_Helper_Database {
    
    /**
     * Current database version
     */
    const DB_VERSION = '1.0.0';
    
    /**
     * Option name for storing database version
     */
    const DB_VERSION_OPTION = 'dssa_pmpro_helper_db_version';
    
    /**
     * Initialize the database class
     */
    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'check_for_updates']);
    }
    
    /**
     * Create or update database tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Legacy membership numbers table
        $table_legacy_numbers = $wpdb->prefix . 'dssa_legacy_numbers';
        $sql_legacy_numbers = "CREATE TABLE IF NOT EXISTS {$table_legacy_numbers} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            membership_number VARCHAR(50) NOT NULL,
            status ENUM('unclaimed', 'claimed') DEFAULT 'unclaimed',
            claimed_by_user_id BIGINT(20) UNSIGNED NULL,
            claimed_date DATETIME NULL,
            date_created DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_modified DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY membership_number (membership_number),
            KEY status (status),
            KEY claimed_by_user_id (claimed_by_user_id)
        ) {$charset_collate};";
        
        // Audit log table
        $table_audit_log = $wpdb->prefix . 'dssa_audit_log';
        $sql_audit_log = "CREATE TABLE IF NOT EXISTS {$table_audit_log} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY action (action),
            KEY timestamp (timestamp),
            KEY ip_address (ip_address)
        ) {$charset_collate};";
        
        // Branches table
        $table_branches = $wpdb->prefix . 'dssa_branches';
        $sql_branches = "CREATE TABLE IF NOT EXISTS {$table_branches} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_name VARCHAR(100) NOT NULL,
            branch_code VARCHAR(20) NOT NULL,
            description TEXT,
            contact_email VARCHAR(100),
            is_active BOOLEAN DEFAULT TRUE,
            sort_order INT(11) DEFAULT 0,
            date_created DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_modified DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY branch_code (branch_code),
            UNIQUE KEY branch_name (branch_name),
            KEY is_active (is_active)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_legacy_numbers);
        dbDelta($sql_audit_log);
        dbDelta($sql_branches);
        
        // Store the current database version
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        
        // Insert default branches if table is empty
        self::maybe_insert_default_branches();
    }
    
    /**
     * Check for database updates
     */
    public static function check_for_updates() {
        $current_version = get_option(self::DB_VERSION_OPTION, '0');
        
        if (version_compare($current_version, self::DB_VERSION, '<')) {
            self::create_tables();
        }
    }
    
    /**
     * Insert default branches if branches table is empty
     */
    private static function maybe_insert_default_branches() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dssa_branches';
        
        // Check if table has any rows
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        
        if ($count == 0) {
            $default_branches = [
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
                'Zululand',
            ];
            
            foreach ($default_branches as $branch_name) {
                $branch_code = sanitize_title($branch_name);
                $wpdb->insert($table_name, [
                    'branch_name' => $branch_name,
                    'branch_code' => $branch_code,
                ]);
            }
        }
    }
    
	/**
	 * Check if a legacy number exists and is available
	 * 
	 * @param string $membership_number
	 * @return array Returns array with 'valid' and 'message' keys
	 */
	public static function check_legacy_number($membership_number) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'dssa_legacy_numbers';
		
		$result = $wpdb->get_row($wpdb->prepare(
			"SELECT id, membership_number, status, claimed_by_user_id, claimed_date 
			 FROM {$table_name} 
			 WHERE membership_number = %s",
			$membership_number
		), ARRAY_A);
		
		if ($result) {
			// Number found in database
			if ($result['status'] === 'unclaimed') {
				return [
					'valid' => true,
					'message' => __('Valid membership number.', 'dssa-pmpro-helper')
				];
			} else {
				// Already claimed
				return [
					'valid' => false,
					'message' => sprintf(
						__('Membership number %s has already been claimed.', 'dssa-pmpro-helper'),
						$membership_number
					)
				];
			}
		} else {
			// Number not found
			return [
				'valid' => false,
				'message' => sprintf(
					__('Membership number %s not found in legacy database.', 'dssa-pmpro-helper'),
					$membership_number
				)
			];
		}
	}
    
    /**
     * Claim a legacy number for a user
     * 
     * @param string $membership_number
     * @param int $user_id
     * @return bool|WP_Error
     */
    public static function claim_legacy_number($membership_number, $user_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dssa_legacy_numbers';
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Check if number exists and is unclaimed
            $current_status = self::check_legacy_number($membership_number);
            
            if (!$current_status) {
                throw new Exception(
                    sprintf(
                        __('Membership number %s not found in legacy database.', 'dssa-pmpro-helper'),
                        $membership_number
                    )
                );
            }
            
            if ($current_status['status'] === 'claimed') {
                throw new Exception(
                    sprintf(
                        __('Membership number %s has already been claimed.', 'dssa-pmpro-helper'),
                        $membership_number
                    )
                );
            }
            
            // Update the record
            $updated = $wpdb->update(
                $table_name,
                [
                    'status' => 'claimed',
                    'claimed_by_user_id' => $user_id,
                    'claimed_date' => current_time('mysql'),
                ],
                [
                    'membership_number' => $membership_number,
                    'status' => 'unclaimed',
                ],
                ['%s', '%d', '%s'],
                ['%s', '%s']
            );
            
            if ($updated === false) {
                throw new Exception(__('Failed to update legacy number record.', 'dssa-pmpro-helper'));
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            return true;
            
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            return new WP_Error('claim_failed', $e->getMessage());
        }
    }
    
    /**
     * Import legacy numbers from CSV data
     * 
     * @param array $numbers Array of membership numbers
     * @return array Results with counts and errors
     */
    public static function import_legacy_numbers($numbers) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dssa_legacy_numbers';
        $results = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
            'duplicates' => 0,
        ];
        
        // Start transaction for bulk import
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($numbers as $index => $number) {
                // Clean and validate the number
                $number = trim(sanitize_text_field($number));
                
                if (empty($number)) {
                    $results['errors'][] = sprintf(__('Row %d: Empty membership number', 'dssa-pmpro-helper'), $index + 1);
                    $results['skipped']++;
                    continue;
                }
                
                // Check if number already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE membership_number = %s",
                    $number
                ));
                
                if ($exists) {
                    $results['duplicates']++;
                    $results['skipped']++;
                    continue;
                }
                
                // Insert the number
                $inserted = $wpdb->insert(
                    $table_name,
                    [
                        'membership_number' => $number,
                        'status' => 'unclaimed',
                    ],
                    ['%s', '%s']
                );
                
                if ($inserted) {
                    $results['imported']++;
                } else {
                    $results['errors'][] = sprintf(__('Row %d: Failed to insert membership number %s', 'dssa-pmpro-helper'), $index + 1, $number);
                    $results['skipped']++;
                }
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            $results['errors'][] = __('Database error during import: ', 'dssa-pmpro-helper') . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Import branches from CSV data
     * 
     * @param array $branches Array of branch names
     * @return array Results with counts and errors
     */
    public static function import_branches($branches) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dssa_branches';
        $results = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
            'duplicates' => 0,
        ];
        
        // Start transaction for bulk import
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($branches as $index => $branch_name) {
                // Clean and validate the branch name
                $branch_name = trim(sanitize_text_field($branch_name));
                
                if (empty($branch_name)) {
                    $results['errors'][] = sprintf(__('Row %d: Empty branch name', 'dssa-pmpro-helper'), $index + 1);
                    $results['skipped']++;
                    continue;
                }
                
                // Create branch code from name
                $branch_code = sanitize_title($branch_name);
                
                // Check if branch already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE branch_code = %s OR branch_name = %s",
                    $branch_code,
                    $branch_name
                ));
                
                if ($exists) {
                    $results['duplicates']++;
                    $results['skipped']++;
                    continue;
                }
                
                // Insert the branch
                $inserted = $wpdb->insert(
                    $table_name,
                    [
                        'branch_name' => $branch_name,
                        'branch_code' => $branch_code,
                    ],
                    ['%s', '%s']
                );
                
                if ($inserted) {
                    $results['imported']++;
                } else {
                    $results['errors'][] = sprintf(__('Row %d: Failed to insert branch %s', 'dssa-pmpro-helper'), $index + 1, $branch_name);
                    $results['skipped']++;
                }
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            $results['errors'][] = __('Database error during import: ', 'dssa-pmpro-helper') . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Get all legacy numbers with filters
     * 
     * @param array $args Query arguments
     * @return array
     */
    public static function get_legacy_numbers($args = []) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dssa_legacy_numbers';
        
        $defaults = [
            'status' => null,
            'search' => '',
            'per_page' => 20,
            'page' => 1,
            'orderby' => 'date_created',
            'order' => 'DESC',
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Build WHERE clause
        $where = ['1=1'];
        $prepare_values = [];
        
        if ($args['status']) {
            $where[] = 'status = %s';
            $prepare_values[] = $args['status'];
        }
        
        if ($args['search']) {
            $where[] = 'membership_number LIKE %s';
            $prepare_values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Build ORDER BY clause
        $allowed_orderby = ['id', 'membership_number', 'status', 'date_created', 'claimed_date'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'date_created';
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
        
        return [
            'items' => $results,
            'total' => $total,
            'pages' => ceil($total / $args['per_page']),
            'page' => $args['page'],
            'per_page' => $args['per_page'],
        ];
    }
    
    /**
     * Get all branches
     * 
     * @param bool $active_only Whether to return only active branches
     * @return array
     */
    public static function get_branches($active_only = true) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dssa_branches';
        
        $where = $active_only ? "WHERE is_active = 1" : "";
        $query = "SELECT * FROM {$table_name} {$where} ORDER BY sort_order ASC, branch_name ASC";
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Clean up old audit log entries based on retention policy
     */
    public static function cleanup_audit_log() {
        global $wpdb;
        
        $retention_years = dssa_pmpro_helper_get_setting('data_retention_years', 7);
        
        if ($retention_years <= 0) {
            return 0;
        }
        
        $table_name = $wpdb->prefix . 'dssa_audit_log';
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_years} years"));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE timestamp < %s",
            $cutoff_date
        ));
        
        return $deleted;
    }
    
    /**
     * Get database table sizes for reports
     */
    public static function get_table_sizes() {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'dssa_legacy_numbers',
            $wpdb->prefix . 'dssa_audit_log',
            $wpdb->prefix . 'dssa_branches',
        ];
        
        $sizes = [];
        
        foreach ($tables as $table) {
            $size = $wpdb->get_var("SELECT ROUND((data_length + index_length) / 1024 / 1024, 2) 
                                   FROM information_schema.TABLES 
                                   WHERE table_schema = DATABASE() 
                                   AND table_name = '{$table}'");
            
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            
            $sizes[$table] = [
                'size_mb' => $size ?: 0,
                'row_count' => $count ?: 0,
            ];
        }
        
        return $sizes;
    }
}