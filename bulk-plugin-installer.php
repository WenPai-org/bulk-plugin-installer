<?php
/**
 * Plugin Name: Bulk Plugin Installer
 * Plugin URI: https://wpmultisite.com/plugins/bulk-plugin-installer/
 * Description: Bulk install WordPress plugins and themes from repository, URL, or ZIP uploads.
 * Version: 1.1.8
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

define('BPI_VERSION', '1.1.8');
define('BPI_PATH', plugin_dir_path(__FILE__));
define('BPI_URL', plugin_dir_url(__FILE__));

require_once BPI_PATH . 'includes/class-installer.php';
require_once BPI_PATH . 'includes/admin-page.php';

function bpi_init() {
    load_plugin_textdomain('bulk-plugin-installer', false, dirname(plugin_basename(__FILE__)) . '/languages/');

    if (is_multisite()) {
        if (is_network_admin()) {
            add_action('network_admin_menu', 'bpi_add_network_submenu_page');
        }
    } else {
        add_action('admin_menu', 'bpi_add_menu_page');
    }

    add_action('wp_ajax_bpi_install_plugins', 'bpi_handle_install_plugins');
    add_action('wp_ajax_bpi_install_themes', 'bpi_handle_install_themes');
    add_action('wp_ajax_bpi_save_settings', 'bpi_handle_save_settings');
}
add_action('plugins_loaded', 'bpi_init');

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

function bpi_register_settings() {
    register_setting('bpi_settings', 'bpi_allowed_roles', ['sanitize_callback' => 'bpi_sanitize_roles']);
    register_setting('bpi_settings', 'bpi_custom_domains', ['sanitize_callback' => 'sanitize_textarea_field']);
    register_setting('bpi_settings', 'bpi_statistics', ['sanitize_callback' => 'bpi_sanitize_statistics']);
}
add_action('admin_init', 'bpi_register_settings');
if (is_multisite()) {
    add_action('network_admin_init', 'bpi_register_settings');
}

function bpi_sanitize_roles($roles) {
    if (!is_array($roles)) {
        return BPI_ALLOWED_ROLES;
    }
    $valid_roles = array_keys(wp_roles()->get_names());
    return array_intersect($roles, $valid_roles);
}

function bpi_sanitize_statistics($stats) {
    return [
        'total_installs' => absint($stats['total_installs'] ?? 0),
        'successful_installs' => absint($stats['successful_installs'] ?? 0),
        'failed_installs' => absint($stats['failed_installs'] ?? 0),
        'last_install_time' => sanitize_text_field($stats['last_install_time'] ?? '')
    ];
}

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
            $files = [];
            if (is_array($_FILES['plugin_files']['name'])) {
                $file_count = count($_FILES['plugin_files']['name']);
                for ($i = 0; $i < $file_count; $i++) {
                    if ($_FILES['plugin_files']['error'][$i] === UPLOAD_ERR_OK) {
                        $files[] = [
                            'name' => sanitize_file_name($_FILES['plugin_files']['name'][$i]),
                            'type' => $_FILES['plugin_files']['type'][$i],
                            'tmp_name' => $_FILES['plugin_files']['tmp_name'][$i],
                            'error' => $_FILES['plugin_files']['error'][$i],
                            'size' => $_FILES['plugin_files']['size'][$i]
                        ];
                    }
                }
            } else {
                if ($_FILES['plugin_files']['error'] === UPLOAD_ERR_OK) {
                    $files[] = [
                        'name' => sanitize_file_name($_FILES['plugin_files']['name']),
                        'type' => $_FILES['plugin_files']['type'],
                        'tmp_name' => $_FILES['plugin_files']['tmp_name'],
                        'error' => $_FILES['plugin_files']['error'],
                        'size' => $_FILES['plugin_files']['size']
                    ];
                }
            }
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
            $files = [];
            if (is_array($_FILES['theme_files']['name'])) {
                $file_count = count($_FILES['theme_files']['name']);
                for ($i = 0; $i < $file_count; $i++) {
                    if ($_FILES['theme_files']['error'][$i] === UPLOAD_ERR_OK) {
                        $files[] = [
                            'name' => sanitize_file_name($_FILES['theme_files']['name'][$i]),
                            'type' => $_FILES['theme_files']['type'][$i],
                            'tmp_name' => $_FILES['theme_files']['tmp_name'][$i],
                            'error' => $_FILES['theme_files']['error'][$i],
                            'size' => $_FILES['theme_files']['size'][$i]
                        ];
                    }
                }
            } else {
                if ($_FILES['theme_files']['error'] === UPLOAD_ERR_OK) {
                    $files[] = [
                        'name' => sanitize_file_name($_FILES['theme_files']['name']),
                        'type' => $_FILES['theme_files']['type'],
                        'tmp_name' => $_FILES['theme_files']['tmp_name'],
                        'error' => $_FILES['theme_files']['error'],
                        'size' => $_FILES['theme_files']['size']
                    ];
                }
            }
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

    update_option('bpi_allowed_roles', bpi_sanitize_roles($roles));
    update_option('bpi_custom_domains', $domains);

    wp_send_json_success(__('Settings saved successfully!', 'bulk-plugin-installer'));
}

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

register_activation_hook(__FILE__, 'bpi_activate');
function bpi_activate() {
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('This plugin requires PHP 7.4 or higher.', 'bulk-plugin-installer'));
    }
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
    if (!get_option('bpi_statistics')) {
        update_option('bpi_statistics', [
            'total_installs' => 0,
            'successful_installs' => 0,
            'failed_installs' => 0,
            'last_install_time' => ''
        ]);
    }
}

// Add "Upload from URL" button to plugin install page
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

// Add "Upload from URL" button to theme install page
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


// Integrate UpdatePulse Server for updates using PUC v5.3
require_once plugin_dir_path(__FILE__) . 'lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5p3\PucFactory;

$BulkPluginInstallerUpdateChecker = PucFactory::buildUpdateChecker(
    'https://updates.weixiaoduo.com/bulk-plugin-installer.json',
    __FILE__,
    'bulk-plugin-installer'
);
