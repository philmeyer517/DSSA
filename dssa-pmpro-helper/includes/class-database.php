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

            // Run meta migration once after tables are created / on update
            self::migrate_user_meta_keys();
        }
    }

    /**
     * Migrate older user meta keys to canonical keys
     *
     * Copies legacy keys (non-destructive) into:
     *  - dssa_membership_number
     *  - dssa_branch
     *
     * Only writes the canonical meta if it is empty for the user.
     */
    public static function migrate_user_meta_keys() {
        // Avoid running migration more than once per DB version
        $migration_flag = 'dssa_pmpro_helper_meta_migrated_' . self::DB_VERSION;
        if (get_option($migration_flag)) {
            return;
        }

        if (!function_exists('get_users')) {
            return;
        }

        $batch = 200;
        $offset = 0;

        // Log start (if debug enabled)
        dssa_pmpro_helper_log('Starting user meta migration to canonical keys');

        do {
            $user_query_args = [
                'number' => $batch,
                'offset' => $offset,
                'fields' => ['ID'],
            ];
            $users = get_users($user_query_args);

            if (empty($users)) {
                break;
            }

            foreach ($users as $u) {
                $user_id = $u->ID;

                // Membership number migration
                $canonical = get_user_meta($user_id, 'dssa_membership_number', true);
                if (empty($canonical)) {
                    $candidates = [
                        'dssa_member_number',
                        'dssa_member_number', // possible duplicate keys across code
                        'dssa_member_number',
                        'dssa_member_number',
                        'dssa_member_number',
                        'dssa_member_number',
                        'member_number',
                        'membership_number',
                        'dssa_member_number', // keep checking if plugin used odd variants
                    ];
                    foreach ($candidates as $cand) {
                        $val = get_user_meta($user_id, $cand, true);
                        if ($val !== '') {
                            update_user_meta($user_id, 'dssa_membership_number', $val);
                            dssa_pmpro_helper_log("Migrated membership number for user {$user_id} from meta '{$cand}'");
                            break;
                        }
                    }
                }

                // Branch migration
                $canonical_branch = get_user_meta($user_id, 'dssa_branch', true);
                if ($canonical_branch === '') {
                    $branch_candidates = [
                        'branch',
                        'dssa_branch',
                    ];
                    foreach ($branch_candidates as $cand) {
                        $val = get_user_meta($user_id, $cand, true);
                        if ($val !== '') {
                            update_user_meta($user_id, 'dssa_branch', $val);
                            dssa_pmpro_helper_log("Migrated branch for user {$user_id} from meta '{$cand}'");
                            break;
                        }
                    }
                }
            }

            $offset += $batch;
        } while (count($users) === $batch);

        // Mark migration completed
        update_option($migration_flag, 1);
        dssa_pmpro_helper_log('Completed user meta migration to canonical keys');
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

            foreach ($default_branches as $i => $branch_name) {
                $wpdb->insert($table_name, [
                    'branch_name' => $branch_name,
                    'branch_code' => sanitize_title_with_dashes($branch_name),
                    'is_active' => 1,
                    'sort_order' => $i,
                ], [
                    '%s', '%s', '%d', '%d'
                ]);
            }
        }
    }
}