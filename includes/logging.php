<?php
/**
 * Logging functions for Bulk Plugin Installer
 */

/**
 * Create logs table
 */
function bpi_create_logs_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bpi_logs';
    $charset_collate = $wpdb->get_charset_collate();

    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            log_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            item_name varchar(255) NOT NULL,
            item_type varchar(50) NOT NULL,
            action varchar(50) NOT NULL,
            source varchar(100) NOT NULL,
            status varchar(50) NOT NULL,
            message text NOT NULL,
            user_id bigint(20) NOT NULL,
            PRIMARY KEY  (id),
            KEY log_time (log_time),
            KEY item_type (item_type),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

/**
 * Add log entry
 *
 * @param string $item_name Item name
 * @param string $item_type Item type (plugin or theme)
 * @param string $action Action (install, update, delete)
 * @param string $source Source (repository, wenpai, url, upload)
 * @param string $status Status (success, error, skipped)
 * @param string $message Message
 * @return int|false The log ID or false on failure
 */
function bpi_add_log_entry($item_name, $item_type, $action, $source, $status, $message) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bpi_logs';
    
    return $wpdb->insert(
        $table_name,
        [
            'log_time' => current_time('mysql'),
            'item_name' => $item_name,
            'item_type' => $item_type,
            'action' => $action,
            'source' => $source,
            'status' => $status,
            'message' => $message,
            'user_id' => get_current_user_id()
        ],
        [
            '%s', // log_time
            '%s', // item_name
            '%s', // item_type
            '%s', // action
            '%s', // source
            '%s', // status
            '%s', // message
            '%d'  // user_id
        ]
    );
}

/**
 * Get logs
 *
 * @param array $filters Filters
 * @param int $per_page Items per page
 * @param int $page Current page
 * @return array Logs data
 */
function bpi_get_logs($filters = [], $per_page = 20, $page = 1) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bpi_logs';
    
    $where = [];
    $prepare = [];
    
    if (!empty($filters['item_type'])) {
        $where[] = 'item_type = %s';
        $prepare[] = $filters['item_type'];
    }
    
    if (!empty($filters['status'])) {
        $where[] = 'status = %s';
        $prepare[] = $filters['status'];
    }
    
    if (!empty($filters['date_from'])) {
        $where[] = 'log_time >= %s';
        $prepare[] = $filters['date_from'] . ' 00:00:00';
    }
    
    if (!empty($filters['date_to'])) {
        $where[] = 'log_time <= %s';
        $prepare[] = $filters['date_to'] . ' 23:59:59';
    }
    
    if (!empty($filters['search'])) {
        $where[] = '(item_name LIKE %s OR message LIKE %s)';
        $search_term = '%' . $wpdb->esc_like($filters['search']) . '%';
        $prepare[] = $search_term;
        $prepare[] = $search_term;
    }
    
    $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $offset = ($page - 1) * $per_page;
    
    $query = $wpdb->prepare(
        "SELECT * FROM $table_name $where_clause ORDER BY log_time DESC LIMIT %d OFFSET %d",
        array_merge($prepare, [$per_page, $offset])
    );
    
    $logs = $wpdb->get_results($query);
    
    // Get total count for pagination
    $count_query = $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name $where_clause",
        $prepare
    );
    
    $total = $wpdb->get_var($count_query);
    
    return [
        'logs' => $logs,
        'total' => $total,
        'pages' => ceil($total / $per_page)
    ];
}

/**
 * Clear logs
 */
function bpi_clear_logs() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bpi_logs';
    
    $wpdb->query("TRUNCATE TABLE $table_name");
}

/**
 * Handle clear logs request
 */
function bpi_handle_clear_logs() {
    if (isset($_POST['action']) && $_POST['action'] === 'bpi_clear_logs' && isset($_POST['bpi_logs_nonce']) && wp_verify_nonce($_POST['bpi_logs_nonce'], 'bpi_clear_logs')) {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'bulk-plugin-installer'));
        }
        
        bpi_clear_logs();
        
        wp_redirect(add_query_arg(['page' => 'bulk-plugin-installer', 'tab' => 'logs', 'cleared' => '1'], admin_url('plugins.php')));
        exit;
    }
}
add_action('admin_init', 'bpi_handle_clear_logs');

/**
 * Handle export logs request
 */
function bpi_handle_export_logs() {
    if (isset($_GET['action']) && $_GET['action'] === 'bpi_export_logs' && isset($_GET['bpi_logs_nonce']) && wp_verify_nonce($_GET['bpi_logs_nonce'], 'bpi_export_logs')) {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'bulk-plugin-installer'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'bpi_logs';
        
        $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY log_time DESC");
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=bpi-logs-' . date('Y-m-d') . '.csv');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Add headers
        fputcsv($output, [
            __('ID', 'bulk-plugin-installer'),
            __('Time', 'bulk-plugin-installer'),
            __('Item', 'bulk-plugin-installer'),
            __('Type', 'bulk-plugin-installer'),
            __('Action', 'bulk-plugin-installer'),
            __('Source', 'bulk-plugin-installer'),
            __('Status', 'bulk-plugin-installer'),
            __('Message', 'bulk-plugin-installer'),
            __('User ID', 'bulk-plugin-installer'),
            __('Username', 'bulk-plugin-installer'),
        ]);
        
        // Add data rows
        foreach ($logs as $log) {
            $user = get_userdata($log->user_id);
            $username = $user ? $user->user_login : __('Unknown', 'bulk-plugin-installer');
            
            fputcsv($output, [
                $log->id,
                $log->log_time,
                $log->item_name,
                $log->item_type,
                $log->action,
                $log->source,
                $log->status,
                $log->message,
                $log->user_id,
                $username
            ]);
        }
        
        fclose($output);
        exit;
    }
}
add_action('admin_post_bpi_export_logs', 'bpi_handle_export_logs');

/**
 * Log WordPress core plugin/theme installations
 */
function bpi_log_wp_installations() {
    add_action('upgrader_process_complete', function($upgrader, $hook_extra) {
        if (isset($hook_extra['type']) && isset($hook_extra['action'])) {
            // For plugins
            if ($hook_extra['type'] === 'plugin' && $hook_extra['action'] === 'install') {
                $plugin_info = $upgrader->plugin_info();
                if ($plugin_info) {
                    $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_info);
                    $plugin_name = $plugin_data['Name'] ?? basename($plugin_info, '.php');
                    bpi_add_log_entry(
                        $plugin_name,
                        'plugin',
                        'install',
                        'wordpress_core',
                        'success',
                        __('Plugin installed via WordPress core installer', 'bulk-plugin-installer')
                    );
                }
            }
            
            // For themes
            if ($hook_extra['type'] === 'theme' && $hook_extra['action'] === 'install') {
                $theme_info = $upgrader->theme_info();
                if ($theme_info) {
                    $theme_name = $theme_info->get('Name');
                    bpi_add_log_entry(
                        $theme_name,
                        'theme',
                        'install',
                        'wordpress_core',
                        'success',
                        __('Theme installed via WordPress core installer', 'bulk-plugin-installer')
                    );
                }
            }
        }
    }, 10, 2);
}
add_action('init', 'bpi_log_wp_installations');