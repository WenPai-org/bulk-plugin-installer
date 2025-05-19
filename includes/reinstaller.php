<?php
/**
 * Functions for handling reinstallation operations
 */

/**
 * Check if a plugin exists in the WordPress.org repository
 *
 * @param string $slug Plugin slug
 * @return bool True if exists, false otherwise
 */
function bpi_plugin_exists_in_repository($slug) {
    if (empty($slug)) {
        return false;
    }

    $url = 'https://api.wordpress.org/plugins/info/1.0/' . $slug . '.json';
    $response = wp_remote_get($url);

    if (is_wp_error($response)) {
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    return !empty($data) && isset($data['slug']);
}

/**
 * Check if a theme exists in the WordPress.org repository
 *
 * @param string $slug Theme slug
 * @return bool True if exists, false otherwise
 */
function bpi_theme_exists_in_repository($slug) {
    if (empty($slug)) {
        return false;
    }

    $url = 'https://api.wordpress.org/themes/info/1.1/?action=theme_information&request[slug]=' . $slug;
    $response = wp_remote_get($url);

    if (is_wp_error($response)) {
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    return !empty($data) && isset($data['slug']);
}

/**
 * Get all installed plugins
 *
 * @return array Installed plugins information
 */
function bpi_get_installed_plugins() {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins = get_plugins();
    $installed_plugins = [];

    // Create a cache for repository existence checks
    $repository_check_cache = [];

    foreach ($all_plugins as $plugin_path => $plugin_data) {
        $plugin_slug = dirname($plugin_path);
        if ($plugin_slug === '.') {
            // Handle single-file plugins
            $plugin_slug = basename($plugin_path, '.php');
        }

        // Default source
        $source = 'unknown';

        // Get plugin status
        if (is_plugin_active($plugin_path)) {
            $status = 'active';
        } else {
            $status = 'inactive';
        }

        // Source determination logic
        // 1. Check common WordPress.org plugins first
        $common_wp_plugins = [
            'akismet', 'hello', 'contact-form-7', 'woocommerce', 'wordpress-seo',
            'jetpack', 'wordfence', 'elementor', 'all-in-one-seo-pack', 'wp-super-cache'
        ];

        if (in_array($plugin_slug, $common_wp_plugins)) {
            $source = 'repository';
        } else {
            // 2. Check plugin/author URI for wordpress.org references
            if (
                strpos($plugin_data['PluginURI'], 'wordpress.org') !== false ||
                strpos($plugin_data['AuthorURI'], 'wordpress.org') !== false
            ) {
                $source = 'repository';
            }
            // 3. Check for WenPai sources
            elseif (
                strpos($plugin_data['PluginURI'], 'wenpai.net') !== false ||
                strpos($plugin_data['PluginURI'], 'wenpai.org') !== false ||
                strpos($plugin_data['PluginURI'], 'wenpai.cn') !== false ||
                strpos($plugin_data['AuthorURI'], 'wenpai.net') !== false ||
                strpos($plugin_data['AuthorURI'], 'wenpai.org') !== false ||
                strpos($plugin_data['AuthorURI'], 'wenpai.cn') !== false
            ) {
                $source = 'wenpai';
            }
            // 4. Check if plugin exists in WordPress.org repository
            elseif (!isset($repository_check_cache[$plugin_slug])) {
                $repository_check_cache[$plugin_slug] = bpi_plugin_exists_in_repository($plugin_slug);
                if ($repository_check_cache[$plugin_slug]) {
                    $source = 'repository';
                }
            } elseif ($repository_check_cache[$plugin_slug]) {
                $source = 'repository';
            }
        }

        $installed_plugins[] = [
            'name' => $plugin_data['Name'],
            'slug' => $plugin_slug,
            'path' => $plugin_path,
            'version' => $plugin_data['Version'],
            'source' => $source,
            'status' => $status,
            'plugin_uri' => $plugin_data['PluginURI'],
            'author' => $plugin_data['Author'],
            'author_uri' => $plugin_data['AuthorURI'],
            'description' => $plugin_data['Description']
        ];
    }

    return $installed_plugins;
}

/**
 * Get all installed themes
 *
 * @return array Installed themes information
 */
function bpi_get_installed_themes() {
    $all_themes = wp_get_themes();
    $installed_themes = [];
    $active_theme = wp_get_theme();

    // Create a cache for repository existence checks
    $repository_check_cache = [];

    foreach ($all_themes as $theme_slug => $theme_obj) {
        // Default source
        $source = 'unknown';

        // Check if theme is active
        if ($active_theme->get_stylesheet() === $theme_slug) {
            $status = 'active';
        } else {
            $status = 'inactive';
        }

        // Get theme URIs
        $theme_uri = $theme_obj->get('ThemeURI');
        $author_uri = $theme_obj->get('AuthorURI');

        // Source determination logic
        // 1. Check common WordPress.org themes first
        $common_wp_themes = [
            'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone',
            'twentytwenty', 'twentynineteen', 'twentyeighteen', 'twentyseventeen'
        ];

        if (in_array($theme_slug, $common_wp_themes)) {
            $source = 'repository';
        } else {
            // 2. Check theme/author URI for wordpress.org references
            if (
                strpos($theme_uri, 'wordpress.org') !== false ||
                strpos($author_uri, 'wordpress.org') !== false
            ) {
                $source = 'repository';
            }
            // 3. Check for WenPai sources
            elseif (
                strpos($theme_uri, 'wenpai.net') !== false ||
                strpos($theme_uri, 'wenpai.org') !== false ||
                strpos($theme_uri, 'wenpai.cn') !== false ||
                strpos($author_uri, 'wenpai.net') !== false ||
                strpos($author_uri, 'wenpai.org') !== false ||
                strpos($author_uri, 'wenpai.cn') !== false
            ) {
                $source = 'wenpai';
            }
            // 4. Check if theme exists in WordPress.org repository
            elseif (!isset($repository_check_cache[$theme_slug])) {
                $repository_check_cache[$theme_slug] = bpi_theme_exists_in_repository($theme_slug);
                if ($repository_check_cache[$theme_slug]) {
                    $source = 'repository';
                }
            } elseif ($repository_check_cache[$theme_slug]) {
                $source = 'repository';
            }
        }

        $installed_themes[] = [
            'name' => $theme_obj->get('Name'),
            'slug' => $theme_slug,
            'version' => $theme_obj->get('Version'),
            'source' => $source,
            'status' => $status,
            'theme_uri' => $theme_uri,
            'author' => $theme_obj->get('Author'),
            'author_uri' => $author_uri,
            'description' => $theme_obj->get('Description')
        ];
    }

    return $installed_themes;
}

/**
 * AJAX handler for getting installed plugins
 */
function bpi_ajax_get_plugins_list() {
    check_ajax_referer('bpi_installer', 'nonce');

    if (!current_user_can('install_plugins') && !(is_multisite() && current_user_can('manage_network_plugins'))) {
        wp_send_json_error(__('Insufficient permissions', 'bulk-plugin-installer'));
    }

    $installed_plugins = bpi_get_installed_plugins();
    wp_send_json_success($installed_plugins);
}
add_action('wp_ajax_bpi_get_plugins_list', 'bpi_ajax_get_plugins_list');

/**
 * AJAX handler for getting installed themes
 */
function bpi_ajax_get_themes_list() {
    check_ajax_referer('bpi_installer', 'nonce');

    if (!current_user_can('install_themes') && !(is_multisite() && current_user_can('manage_network_plugins'))) {
        wp_send_json_error(__('Insufficient permissions', 'bulk-plugin-installer'));
    }

    $installed_themes = bpi_get_installed_themes();
    wp_send_json_success($installed_themes);
}
add_action('wp_ajax_bpi_get_themes_list', 'bpi_ajax_get_themes_list');

/**
 * AJAX handler for reinstalling plugins
 */
function bpi_handle_reinstall_plugins() {
    check_ajax_referer('bpi_installer', 'nonce');

    if (!current_user_can('install_plugins') && !(is_multisite() && current_user_can('manage_network_plugins'))) {
        wp_send_json_error([
            'message' => __('Insufficient permissions', 'bulk-plugin-installer'),
            'error_code' => 403
        ]);
    }

    $items = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : [];
    if (!is_array($items) || empty($items)) {
        wp_send_json_error([
            'message' => __('No items provided', 'bulk-plugin-installer'),
            'error_code' => 400
        ]);
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

    $results = [];
    $installer = new BPI_Installer();

    foreach ($items as $item) {
        $source = sanitize_text_field($item['source'] ?? 'repository');
        $slug = sanitize_text_field($item['slug'] ?? '');
        $path = sanitize_text_field($item['path'] ?? '');
        $was_active = is_plugin_active($path);

        if (empty($slug)) {
            $results[$path] = [
                'success' => false,
                'message' => __('Invalid plugin slug', 'bulk-plugin-installer'),
                'error_code' => 400
            ];
            continue;
        }

        // Deactivate plugin first
        if ($was_active) {
            deactivate_plugins($path);
        }

        // For repository or wenpai source, use reinstaller
        if ($source === 'repository' || $source === 'wenpai') {
            // Use the existing installer method
            $source_results = $installer->bpi_install_plugins([$slug], $source);

            // Get the result for this specific item
            if (isset($source_results[$slug])) {
                $results[$path] = $source_results[$slug];

                // If successful and the plugin was active before, try to reactivate
                if ($was_active && $source_results[$slug]['success']) {
                    $plugin_file = WP_PLUGIN_DIR . '/' . $path;
                    if (file_exists($plugin_file)) {
                        activate_plugin($path);
                        $results[$path]['message'] .= ' ' . __('and reactivated', 'bulk-plugin-installer');
                    }
                }
            } else {
                $results[$path] = [
                    'success' => false,
                    'message' => __('Failed to reinstall plugin', 'bulk-plugin-installer'),
                    'error_code' => 500
                ];
            }
        } else {
            // For unknown or custom sources
            $results[$path] = [
                'success' => false,
                'message' => __('Cannot reinstall plugin from unknown source. Please reinstall manually.', 'bulk-plugin-installer'),
                'error_code' => 400,
                'source' => $source
            ];
        }

        // Log the reinstallation
        bpi_add_log_entry(
            $slug,
            'plugin',
            'reinstall',
            $source,
            $results[$path]['success'] ? 'success' : 'error',
            $results[$path]['message']
        );
    }

    bpi_update_statistics($results);
    wp_send_json_success($results);
}
add_action('wp_ajax_bpi_reinstall_plugins', 'bpi_handle_reinstall_plugins');

/**
 * AJAX handler for reinstalling themes
 */
function bpi_handle_reinstall_themes() {
    check_ajax_referer('bpi_installer', 'nonce');

    if (!current_user_can('install_themes') && !(is_multisite() && current_user_can('manage_network_plugins'))) {
        wp_send_json_error([
            'message' => __('Insufficient permissions', 'bulk-plugin-installer'),
            'error_code' => 403
        ]);
    }

    $items = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : [];
    if (!is_array($items) || empty($items)) {
        wp_send_json_error([
            'message' => __('No items provided', 'bulk-plugin-installer'),
            'error_code' => 400
        ]);
    }

    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/theme-install.php';

    $results = [];
    $installer = new BPI_Installer();
    $active_theme = wp_get_theme();

    foreach ($items as $item) {
        $source = sanitize_text_field($item['source'] ?? 'repository');
        $slug = sanitize_text_field($item['slug'] ?? '');
        $was_active = ($active_theme->get_stylesheet() === $slug);

        if (empty($slug)) {
            $results[$slug] = [
                'success' => false,
                'message' => __('Invalid theme slug', 'bulk-plugin-installer'),
                'error_code' => 400
            ];
            continue;
        }

        // For repository or wenpai source, use reinstaller
        if ($source === 'repository' || $source === 'wenpai') {
            // Use the existing installer method
            $source_results = $installer->bpi_install_themes([$slug], $source);

            // Get the result for this specific item
            if (isset($source_results[$slug])) {
                $results[$slug] = $source_results[$slug];

                // If successful and the theme was active before, try to reactivate
                if ($was_active && $source_results[$slug]['success']) {
                    switch_theme($slug);
                    $results[$slug]['message'] .= ' ' . __('and reactivated', 'bulk-plugin-installer');
                }
            } else {
                $results[$slug] = [
                    'success' => false,
                    'message' => __('Failed to reinstall theme', 'bulk-plugin-installer'),
                    'error_code' => 500
                ];
            }
        } else {
            // For unknown or custom sources
            $results[$slug] = [
                'success' => false,
                'message' => __('Cannot reinstall theme from unknown source. Please reinstall manually.', 'bulk-plugin-installer'),
                'error_code' => 400,
                'source' => $source
            ];
        }

        // Log the reinstallation
        bpi_add_log_entry(
            $slug,
            'theme',
            'reinstall',
            $source,
            $results[$slug]['success'] ? 'success' : 'error',
            $results[$slug]['message']
        );
    }

    bpi_update_statistics($results);
    wp_send_json_success($results);
}
add_action('wp_ajax_bpi_reinstall_themes', 'bpi_handle_reinstall_themes');
