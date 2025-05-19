<?php
/**
 * Plugin Name: Bulk Plugin Installer
 * Plugin URI: https://wpmultisite.com/plugins/bulk-plugin-installer/
 * Description: Bulk install WordPress plugins and themes from repository, URL, or ZIP uploads with preset collections support.
 * Version: 1.3.0
 * Author: WPMultisite.com
 * Author URI: https://wpmultisite.com
 * Network: true
 * Requires at least: 5.8
 * License: GPL v2 or later
 * Text Domain: bulk-plugin-installer
 * Requires PHP: 7.4
 * Domain Path: /languages
 */

if (!defined('WPINC')) {
    die;
}

define('BPI_VERSION', '1.2.0');
define('BPI_PATH', plugin_dir_path(__FILE__));
define('BPI_URL', plugin_dir_url(__FILE__));
define('BPI_ALLOWED_ROLES', ['administrator', 'super_admin']);
define('BPI_TRUSTED_DOMAINS', [
    'wordpress.org',
    'wenpai.cn',
    'wenpai.net',
    'wenpai.org',
    'weixiaoduo.com',
    'feibisi.com',
    'feicode.com',
    'github.com',
    'raw.githubusercontent.com'
]);

// Include required files
require_once BPI_PATH . 'includes/class-installer.php';
require_once BPI_PATH . 'includes/admin-page.php';
require_once BPI_PATH . 'includes/collections.php';
require_once BPI_PATH . 'includes/logging.php';
require_once BPI_PATH . 'includes/reinstaller.php';

/**
 * Initialize the plugin
 */
function bpi_init() {
    load_plugin_textdomain('bulk-plugin-installer', false, dirname(plugin_basename(__FILE__)) . '/languages/');

    if (is_multisite()) {
        if (is_network_admin()) {
            add_action('network_admin_menu', 'bpi_add_network_submenu_page');
        }
    } else {
        add_action('admin_menu', 'bpi_add_menu_page');
    }

    // Register Ajax handlers
    add_action('wp_ajax_bpi_install_plugins', 'bpi_handle_install_plugins');
    add_action('wp_ajax_bpi_install_themes', 'bpi_handle_install_themes');
    add_action('wp_ajax_bpi_save_settings', 'bpi_handle_save_settings');
    add_action('wp_ajax_bpi_get_collection_details', 'bpi_ajax_get_collection_details');
    add_action('wp_ajax_bpi_install_collection', 'bpi_ajax_install_collection');
    add_action('wp_ajax_bpi_save_remote_source', 'bpi_ajax_save_remote_source');
    add_action('wp_ajax_bpi_refresh_collections', 'bpi_ajax_refresh_collections');
}
add_action('plugins_loaded', 'bpi_init');

/**
 * Add menu page for single site
 */
function bpi_add_menu_page() {
    add_plugins_page(
        __('Plugin Installer', 'bulk-plugin-installer'),
        __('Plugin Installer', 'bulk-plugin-installer'),
        'install_plugins',
        'bulk-plugin-installer',
        'bpi_render_admin_page',
        10
    );
}

/**
 * Add menu page for multisite
 */
function bpi_add_network_submenu_page() {
    add_submenu_page(
        'plugins.php',
        __('Plugin Installer', 'bulk-plugin-installer'),
        __('Plugin Installer', 'bulk-plugin-installer'),
        'manage_network_plugins',
        'bulk-plugin-installer',
        'bpi_render_admin_page'
    );
}

/**
 * Register plugin settings
 */
function bpi_register_settings() {
    register_setting('bpi_settings', 'bpi_allowed_roles', ['sanitize_callback' => 'bpi_sanitize_roles']);
    register_setting('bpi_settings', 'bpi_custom_domains', ['sanitize_callback' => 'sanitize_textarea_field']);
    register_setting('bpi_settings', 'bpi_statistics', ['sanitize_callback' => 'bpi_sanitize_statistics']);
    register_setting('bpi_settings', 'bpi_collection_sources', ['sanitize_callback' => 'bpi_sanitize_collection_sources']);
    register_setting('bpi_settings', 'bpi_install_options', ['sanitize_callback' => 'bpi_sanitize_install_options']);
}
add_action('admin_init', 'bpi_register_settings');
if (is_multisite()) {
    add_action('network_admin_init', 'bpi_register_settings');
}

/**
 * Sanitize the roles array
 *
 * @param array $roles User roles
 * @return array Sanitized roles
 */
function bpi_sanitize_roles($roles) {
    if (!is_array($roles)) {
        return BPI_ALLOWED_ROLES;
    }
    $valid_roles = array_keys(wp_roles()->get_names());
    return array_intersect($roles, $valid_roles);
}

/**
 * Sanitize statistics array
 *
 * @param array $stats Statistics data
 * @return array Sanitized statistics
 */
function bpi_sanitize_statistics($stats) {
    return [
        'total_installs' => absint($stats['total_installs'] ?? 0),
        'successful_installs' => absint($stats['successful_installs'] ?? 0),
        'failed_installs' => absint($stats['failed_installs'] ?? 0),
        'last_install_time' => sanitize_text_field($stats['last_install_time'] ?? '')
    ];
}

/**
 * Sanitize collection sources
 *
 * @param array $sources Collection source URLs
 * @return array Sanitized source URLs
 */
function bpi_sanitize_collection_sources($sources) {
    if (!is_array($sources)) {
        return [];
    }

    $sanitized = [];
    foreach ($sources as $source) {
        if (isset($source['url']) && !empty($source['url'])) {
            $sanitized[] = [
                'name' => sanitize_text_field($source['name'] ?? __('Unnamed Source', 'bulk-plugin-installer')),
                'url' => esc_url_raw($source['url']),
                'enabled' => !empty($source['enabled'])
            ];
        }
    }

    return $sanitized;
}

/**
 * Sanitize install options
 *
 * @param array $options Installation options
 * @return array Sanitized options
 */
function bpi_sanitize_install_options($options) {
    if (!is_array($options)) {
        return [
            'duplicate_handling' => 'skip',
            'auto_activate' => false,
            'keep_backups' => false,
        ];
    }

    return [
        'duplicate_handling' => isset($options['duplicate_handling']) && in_array($options['duplicate_handling'], ['skip', 'reinstall', 'error'])
            ? $options['duplicate_handling']
            : 'skip',
        'auto_activate' => !empty($options['auto_activate']),
        'keep_backups' => !empty($options['keep_backups']),
    ];
}

/**
 * Check if current user can install plugins
 *
 * @return bool True if user can install, false otherwise
 */
function bpi_user_can_install() {
    if (!is_user_logged_in()) {
        return false;
    }
    if (is_multisite() && is_network_admin()) {
        return current_user_can('manage_network_plugins');
    }
    $allowed_roles = get_option('bpi_allowed_roles', BPI_ALLOWED_ROLES);
    $user = wp_get_current_user();
    return !empty(array_intersect($allowed_roles, $user->roles)) || current_user_can('manage_options');
}

/**
 * Check if a domain is in the allowed list
 *
 * @param string $url URL to check
 * @return bool True if domain is allowed, false otherwise
 */
function bpi_is_domain_allowed($url) {
    if (empty($url)) {
        return false;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return false;
    }
    $host = strtolower($host);
    $trusted_domains = array_merge(
        BPI_TRUSTED_DOMAINS,
        array_filter(array_map('trim', explode("\n", get_option('bpi_custom_domains', ''))))
    );
    $trusted_domains = array_map('strtolower', $trusted_domains);

    foreach ($trusted_domains as $domain) {
        if ($host === $domain || preg_match('/\.' . preg_quote($domain, '/') . '$/', $host)) {
            return true;
        }
    }
    return false;
}

/**
 * Handle plugin installation AJAX request
 */
function bpi_handle_install_plugins() {
    check_ajax_referer('bpi_installer', 'nonce');

    if (!current_user_can('install_plugins') && !(is_multisite() && current_user_can('manage_network_plugins'))) {
        wp_send_json_error([
            'message' => __('Insufficient permissions', 'bulk-plugin-installer'),
            'error_code' => 403
        ]);
    }

    $installer = new BPI_Installer();
    $type = sanitize_text_field($_POST['install_type'] ?? '');
    $results = [];

    try {
        if ($type === 'upload') {
            if (!isset($_FILES['plugin_files']) || empty($_FILES['plugin_files']['name'])) {
                wp_send_json_error([
                    'message' => __('No files uploaded', 'bulk-plugin-installer'),
                    'error_code' => 400
                ]);
            }
            $files = bpi_process_uploaded_files('plugin_files');
            if (empty($files)) {
                wp_send_json_error([
                    'message' => __('No valid files uploaded', 'bulk-plugin-installer'),
                    'error_code' => 400
                ]);
            }
            $results = $installer->bpi_install_plugins($files, $type);
        } else {
            $items = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : [];
            if (!is_array($items) || empty($items)) {
                wp_send_json_error([
                    'message' => __('No items provided', 'bulk-plugin-installer'),
                    'error_code' => 400
                ]);
            }
            $results = $installer->bpi_install_plugins($items, $type);
        }
        bpi_update_statistics($results);
        wp_send_json_success($results);
    } catch (Exception $e) {
        error_log('BPI Plugin Install Error: ' . $e->getMessage());
        wp_send_json_error([
            'message' => $installer->get_error_message($e->getCode(), $e->getMessage()),
            'error_code' => $e->getCode()
        ]);
    }
}

/**
 * Process uploaded files
 *
 * @param string $field_name Form field name
 * @return array Processed files
 */
function bpi_process_uploaded_files($field_name) {
    $files = [];
    if (is_array($_FILES[$field_name]['name'])) {
        $file_count = count($_FILES[$field_name]['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES[$field_name]['error'][$i] === UPLOAD_ERR_OK) {
                $files[] = [
                    'name' => sanitize_file_name($_FILES[$field_name]['name'][$i]),
                    'type' => $_FILES[$field_name]['type'][$i],
                    'tmp_name' => $_FILES[$field_name]['tmp_name'][$i],
                    'error' => $_FILES[$field_name]['error'][$i],
                    'size' => $_FILES[$field_name]['size'][$i]
                ];
            }
        }
    } else {
        if ($_FILES[$field_name]['error'] === UPLOAD_ERR_OK) {
            $files[] = [
                'name' => sanitize_file_name($_FILES[$field_name]['name']),
                'type' => $_FILES[$field_name]['type'],
                'tmp_name' => $_FILES[$field_name]['tmp_name'],
                'error' => $_FILES[$field_name]['error'],
                'size' => $_FILES[$field_name]['size']
            ];
        }
    }
    return $files;
}

/**
 * Handle theme installation AJAX request
 */
function bpi_handle_install_themes() {
    check_ajax_referer('bpi_installer', 'nonce');

    if (!current_user_can('install_themes') && !(is_multisite() && current_user_can('manage_network_plugins'))) {
        wp_send_json_error([
            'message' => __('Insufficient permissions', 'bulk-plugin-installer'),
            'error_code' => 403
        ]);
    }

    $installer = new BPI_Installer();
    $type = sanitize_text_field($_POST['install_type'] ?? '');
    $results = [];

    try {
        if ($type === 'upload') {
            if (!isset($_FILES['theme_files']) || empty($_FILES['theme_files']['name'])) {
                wp_send_json_error([
                    'message' => __('No files uploaded', 'bulk-plugin-installer'),
                    'error_code' => 400
                ]);
            }
            $files = bpi_process_uploaded_files('theme_files');
            if (empty($files)) {
                wp_send_json_error([
                    'message' => __('No valid files uploaded', 'bulk-plugin-installer'),
                    'error_code' => 400
                ]);
            }
            $results = $installer->bpi_install_themes($files, $type);
        } else {
            $items = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : [];
            if (!is_array($items) || empty($items)) {
                wp_send_json_error([
                    'message' => __('No items provided', 'bulk-plugin-installer'),
                    'error_code' => 400
                ]);
            }
            $results = $installer->bpi_install_themes($items, $type);
        }
        bpi_update_statistics($results);
        wp_send_json_success($results);
    } catch (Exception $e) {
        error_log('BPI Theme Install Error: ' . $e->getMessage());
        wp_send_json_error([
            'message' => $installer->get_error_message($e->getCode(), $e->getMessage()),
            'error_code' => $e->getCode()
        ]);
    }
}

/**
 * Handle save settings AJAX request
 */
function bpi_handle_save_settings() {
    check_ajax_referer('bpi_installer', 'nonce');

    if (!current_user_can('manage_options') && !(is_multisite() && current_user_can('manage_network_options'))) {
        wp_send_json_error([
            'message' => __('Insufficient permissions', 'bulk-plugin-installer'),
            'error_code' => 403
        ]);
    }

    $roles = isset($_POST['bpi_allowed_roles']) ? (array)$_POST['bpi_allowed_roles'] : [];
    $domains = isset($_POST['bpi_custom_domains']) ? sanitize_textarea_field($_POST['bpi_custom_domains']) : '';

    // Save installation options
    $install_options = isset($_POST['bpi_install_options']) ? $_POST['bpi_install_options'] : [];
    $sanitized_options = [
        'duplicate_handling' => isset($install_options['duplicate_handling']) ? sanitize_text_field($install_options['duplicate_handling']) : 'skip',
        'auto_activate' => !empty($install_options['auto_activate']),
        'keep_backups' => !empty($install_options['keep_backups']),
    ];
    update_option('bpi_install_options', $sanitized_options);

    update_option('bpi_allowed_roles', bpi_sanitize_roles($roles));
    update_option('bpi_custom_domains', $domains);

    wp_send_json_success(__('Settings saved successfully!', 'bulk-plugin-installer'));
}

/**
 * Update installation statistics
 *
 * @param array $results Installation results
 */
function bpi_update_statistics($results) {
    $stats = get_option('bpi_statistics', [
        'total_installs' => 0,
        'successful_installs' => 0,
        'failed_installs' => 0,
        'last_install_time' => ''
    ]);

    $stats['total_installs'] += count($results);
    foreach ($results as $result) {
        if ($result['success']) {
            $stats['successful_installs']++;
        } else {
            $stats['failed_installs']++;
        }
    }
    $stats['last_install_time'] = current_time('mysql');

    update_option('bpi_statistics', $stats);
}

/**
 * Plugin activation hook
 */
register_activation_hook(__FILE__, 'bpi_activate');
function bpi_activate() {
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('This plugin requires PHP 7.4 or higher.', 'bulk-plugin-installer'));
    }

    // Check server configuration
    $suggested_configs = [
        'upload_max_filesize' => '64M',
        'post_max_size' => '64M',
        'max_file_uploads' => '20',
        'memory_limit' => '256M',
        'max_execution_time' => '300'
    ];
    foreach ($suggested_configs as $key => $value) {
        if (ini_get($key) < $value) {
            error_log("BPI Warning: $key is set to " . ini_get($key) . ", recommended: $value");
        }
    }

    // Initialize statistics
    if (!get_option('bpi_statistics')) {
        update_option('bpi_statistics', [
            'total_installs' => 0,
            'successful_installs' => 0,
            'failed_installs' => 0,
            'last_install_time' => ''
        ]);
    }

    // Create default collection sources
    if (!get_option('bpi_collection_sources')) {
        update_option('bpi_collection_sources', [
            [
                'name' => __('Official Collections', 'bulk-plugin-installer'),
                'url' => 'https://wpmultisite.com/api/collections.json',
                'enabled' => true
            ]
        ]);
    }

    // Create required directories
    $collections_dir = BPI_PATH . 'data';
    if (!file_exists($collections_dir)) {
        wp_mkdir_p($collections_dir);
    }

    // Create backup directory
    $backup_dir = WP_CONTENT_DIR . '/bpi-backups';
    if (!file_exists($backup_dir)) {
        wp_mkdir_p($backup_dir);
        wp_mkdir_p($backup_dir . '/plugins');
        wp_mkdir_p($backup_dir . '/themes');
    }

    // Create logs table
    bpi_create_logs_table();

    // Create default collections file if it doesn't exist
    $collections_file = $collections_dir . '/collections.json';
    if (!file_exists($collections_file)) {
        $default_collections = [
            'version' => '1.0',
            'last_updated' => current_time('Y-m-d'),
            'collections' => [
                'business' => [
                    'name' => __('Business Website', 'bulk-plugin-installer'),
                    'slug' => 'business',
                    'description' => __('Essential plugins for a professional business website.', 'bulk-plugin-installer'),
                    'icon' => 'dashicons-building',
                    'category' => 'business',
                    'level' => 'beginner',
                    'plugins' => [
                        'repository' => [
                            [
                                'slug' => 'wordpress-seo',
                                'name' => 'Yoast SEO',
                                'description' => __('The leading SEO plugin for WordPress', 'bulk-plugin-installer'),
                                'required' => true
                            ],
                            [
                                'slug' => 'contact-form-7',
                                'name' => 'Contact Form 7',
                                'description' => __('Simple but flexible contact form plugin', 'bulk-plugin-installer'),
                                'required' => true
                            ]
                        ],
                        'wenpai' => [],
                        'url' => []
                    ],
                    'themes' => [
                        'repository' => [
                            [
                                'slug' => 'astra',
                                'name' => 'Astra',
                                'description' => __('Fast, lightweight theme for business websites', 'bulk-plugin-installer'),
                                'required' => false
                            ]
                        ],
                        'wenpai' => [],
                        'url' => []
                    ]
                ]
            ]
        ];
        file_put_contents($collections_file, wp_json_encode($default_collections, JSON_PRETTY_PRINT));
    }
}

/**
 * Add "Upload from URL" button to plugin install page
 */
function bpi_add_plugin_url_upload_button() {
    if (bpi_user_can_install()) {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.wp-header-end').before(
                    '<a href="<?php echo admin_url("plugins.php?page=bulk-plugin-installer&tab=plugins&install_type=url"); ?>" class="page-title-action"><?php _e("Upload from URL", "bulk-plugin-installer"); ?></a>'
                );
            });
        </script>
        <?php
    }
}
add_action('admin_footer-plugin-install.php', 'bpi_add_plugin_url_upload_button');

/**
 * Add "Upload from URL" button to theme install page
 */
function bpi_add_theme_url_upload_button() {
    if (bpi_user_can_install()) {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.wp-header-end').before(
                    '<a href="<?php echo admin_url("plugins.php?page=bulk-plugin-installer&tab=themes&install_type=url"); ?>" class="page-title-action"><?php _e("Upload from URL", "bulk-plugin-installer"); ?></a>'
                );
            });
        </script>
        <?php
    }
}
add_action('admin_footer-theme-install.php', 'bpi_add_theme_url_upload_button');

/**
 * Add "Upload from URL" button to installed plugins list page
 */
function bpi_add_installed_plugin_url_upload_button() {
    if (bpi_user_can_install()) {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.wp-header-end').before(
                    '<a href="<?php echo admin_url("plugins.php?page=bulk-plugin-installer&tab=plugins&install_type=url"); ?>" class="page-title-action"><?php _e("Upload from URL", "bulk-plugin-installer"); ?></a>'
                );
            });
        </script>
        <?php
    }
}
add_action('admin_footer-plugins.php', 'bpi_add_installed_plugin_url_upload_button');

/**
 * Add "Upload from URL" button to installed themes list page
 */
function bpi_add_installed_theme_url_upload_button() {
    if (bpi_user_can_install()) {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.wp-header-end').before(
                    '<a href="<?php echo admin_url("plugins.php?page=bulk-plugin-installer&tab=themes&install_type=url"); ?>" class="page-title-action"><?php _e("Upload from URL", "bulk-plugin-installer"); ?></a>'
                );
            });
        </script>
        <?php
    }
}
add_action('admin_footer-themes.php', 'bpi_add_installed_theme_url_upload_button');

// Integrate UpdatePulse Server for updates using PUC v5.3
require_once plugin_dir_path(__FILE__) . 'lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5p3\PucFactory;

$BulkPluginInstallerUpdateChecker = PucFactory::buildUpdateChecker(
    'https://updates.weixiaoduo.com/bulk-plugin-installer.json',
    __FILE__,
    'bulk-plugin-installer'
);
