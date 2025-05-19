<?php
/**
 * Installer class for handling plugin and theme installations
 */
class BPI_Installer {
    /**
     * WordPress filesystem object
     *
     * @var WP_Filesystem_Base
     */
    private $wp_filesystem;

    /**
     * Error messages with translation
     *
     * @var array
     */
    private $error_messages = [];

    /**
     * Constructor
     */
    public function __construct() {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            if (!WP_Filesystem()) {
                throw new Exception(__('Unable to initialize filesystem. Please check server permissions.', 'bulk-plugin-installer'));
            }
        }
        $this->wp_filesystem = $wp_filesystem;

        // Initialize error messages with translations in the constructor
        $this->error_messages = [
            // Plugin error codes
            1001 => __('Invalid plugin slug format. Please use only lowercase letters, numbers and hyphens.', 'bulk-plugin-installer'),
            1004 => __('Invalid URL format. Please provide a complete URL including http:// or https://', 'bulk-plugin-installer'),
            1005 => __('The domain is not in the trusted list. Please add it in settings or use another source.', 'bulk-plugin-installer'),
            1007 => __('Only ZIP files are allowed for plugin uploads.', 'bulk-plugin-installer'),
            1009 => __('This appears to be a theme ZIP file. Please use the Themes tab for installation.', 'bulk-plugin-installer'),
            1010 => __('The uploaded file is not a valid WordPress plugin ZIP.', 'bulk-plugin-installer'),
            // Theme error codes
            2001 => __('Invalid theme slug format. Please use only lowercase letters, numbers and hyphens.', 'bulk-plugin-installer'),
            2003 => __('Only ZIP files are allowed for theme uploads.', 'bulk-plugin-installer'),
            2005 => __('This appears to be a plugin ZIP file. Please use the Plugins tab for installation.', 'bulk-plugin-installer'),
            2006 => __('The uploaded file is not a valid WordPress theme ZIP.', 'bulk-plugin-installer'),
            2009 => __('Invalid URL format for theme. Please provide a complete URL including http:// or https://', 'bulk-plugin-installer'),
            2010 => __('The domain is not in the trusted list for themes. Please add it in settings or use another source.', 'bulk-plugin-installer')
        ];
    }

    /**
     * Get error message by code
     *
     * @param int $code Error code
     * @param string $default_message Default message if code not found
     * @return string Error message
     */
    public function get_error_message($code, $default_message) {
        return isset($this->error_messages[$code]) ? $this->error_messages[$code] : $default_message;
    }

    /**
     * Check if a ZIP file is a plugin
     *
     * @param string $zip_path Path to ZIP file
     * @return bool True if it's a plugin, false otherwise
     */
    private function is_plugin_zip($zip_path) {
        if (!extension_loaded('zip')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (preg_match('/\.php$/', $filename)) {
                    $content = $zip->getFromIndex($i);
                    if (preg_match('/Plugin Name:/i', $content)) {
                        $zip->close();
                        return true;
                    }
                }
            }
            $zip->close();
        }
        return false;
    }

    /**
     * Check if a ZIP file is a theme
     *
     * @param string $zip_path Path to ZIP file
     * @return bool True if it's a theme, false otherwise
     */
    private function is_theme_zip($zip_path) {
        if (!extension_loaded('zip')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (strpos($zip->getNameIndex($i), 'style.css') !== false) {
                    $zip->close();
                    return true;
                }
            }
            $zip->close();
        }
        return false;
    }

    /**
     * Install plugins
     *
     * @param array $items Items to install
     * @param string $type Installation type
     * @return array Installation results
     */
    public function bpi_install_plugins($items, $type) {
        $valid_types = ['repository', 'wenpai', 'url', 'upload'];
        if (!in_array($type, $valid_types)) {
            return ['error' => __('Invalid installation type', 'bulk-plugin-installer')];
        }

        if (empty($items) || !is_array($items)) {
            return ['error' => __('No items provided', 'bulk-plugin-installer')];
        }

        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        // Get installation options
        $install_options = get_option('bpi_install_options', [
            'duplicate_handling' => 'skip',
            'auto_activate' => false,
            'keep_backups' => false,
        ]);

        $results = [];
        $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
        $installed_plugins = get_plugins();

        foreach ($items as $item) {
            try {
                $plugin_key = null;
                if ($type === 'repository' || $type === 'wenpai') {
                    $item = sanitize_text_field($item);
                    if (!preg_match('/^[a-z0-9-]+$/', $item)) {
                        throw new Exception(
                            $this->get_error_message(1001, 'Invalid plugin slug'),
                            1001
                        );
                    }
                    $plugin_key = $item . '/' . $item . '.php';
                } elseif ($type === 'upload') {
                    $file_name = sanitize_file_name($item['name']);
                    $plugin_key = pathinfo($file_name, PATHINFO_FILENAME) . '/' . pathinfo($file_name, PATHINFO_FILENAME) . '.php';
                }

                if ($plugin_key && array_key_exists($plugin_key, $installed_plugins)) {
                    switch ($install_options['duplicate_handling']) {
                        case 'skip':
                            $results[$item] = [
                                'success' => true,
                                'message' => __('Plugin already installed, skipped', 'bulk-plugin-installer'),
                                'skipped' => true
                            ];
                            bpi_add_log_entry(
                                $item,
                                'plugin',
                                'install',
                                $type,
                                'skipped',
                                __('Plugin already installed, skipped', 'bulk-plugin-installer')
                            );
                            continue 2; // Skip to the next item in the foreach loop

                        case 'reinstall':
                            // Continue with reinstall (don't add any special code here, just don't skip)
                            if ($install_options['keep_backups']) {
                                // Backup the existing plugin
                                $backup_dir = WP_CONTENT_DIR . '/bpi-backups/plugins/';
                                if (!file_exists($backup_dir)) {
                                    wp_mkdir_p($backup_dir);
                                }

                                $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_key);
                                $backup_path = $backup_dir . basename($plugin_dir) . '-' . date('Y-m-d-H-i-s') . '.zip';

                                // Create backup
                                if (class_exists('ZipArchive')) {
                                    $zip = new ZipArchive();
                                    if ($zip->open($backup_path, ZipArchive::CREATE) === TRUE) {
                                        $files = new RecursiveIteratorIterator(
                                            new RecursiveDirectoryIterator($plugin_dir),
                                            RecursiveIteratorIterator::LEAVES_ONLY
                                        );

                                        foreach ($files as $file) {
                                            if (!$file->isDir()) {
                                                $filePath = $file->getRealPath();
                                                $relativePath = substr($filePath, strlen($plugin_dir) + 1);
                                                $zip->addFile($filePath, $relativePath);
                                            }
                                        }

                                        $zip->close();
                                    }
                                }
                            }
                            break;

                        case 'error':
                            $results[$item] = [
                                'success' => false,
                                'message' => __('Plugin already installed. Set "Skip duplicates" in settings to ignore this error.', 'bulk-plugin-installer'),
                                'error_code' => 1013
                            ];
                            bpi_add_log_entry(
                                $item,
                                'plugin',
                                'install',
                                $type,
                                'error',
                                __('Plugin already installed. Set "Skip duplicates" in settings to ignore this error.', 'bulk-plugin-installer')
                            );
                            continue 2; // Skip to the next item in the foreach loop
                    }
                }

                $download_link = '';
                switch ($type) {
                    case 'repository':
                        $api = plugins_api('plugin_information', [
                            'slug' => $item,
                            'fields' => ['sections' => false]
                        ]);
                        if (is_wp_error($api)) {
                            throw new Exception(
                                $this->get_error_message(1002, 'Failed to fetch plugin info: ' . $api->get_error_message()),
                                1002
                            );
                        }
                        $download_link = $api->download_link;
                        break;

                    case 'wenpai':
                        $response = wp_remote_get("https://api.wenpai.net/wp-json/wp/v2/plugins/{$item}");
                        if (is_wp_error($response)) {
                            $code = wp_remote_retrieve_response_code($response);
                            throw new Exception(
                                $this->get_error_message(1003, "Download failed with status code $code: " . $response->get_error_message()),
                                1003
                            );
                        }
                        $plugin_data = json_decode(wp_remote_retrieve_body($response), true);
                        $download_link = $plugin_data && !empty($plugin_data['download_link'])
                            ? $plugin_data['download_link']
                            : "https://downloads.wenpai.net/plugin/{$item}.latest-stable.zip";
                        break;

                    case 'url':
                        $item = sanitize_text_field($item);
                        if (!filter_var($item, FILTER_VALIDATE_URL)) {
                            throw new Exception(
                                $this->get_error_message(1004, 'Invalid URL format'),
                                1004
                            );
                        }
                        if (!bpi_is_domain_allowed($item)) {
                            throw new Exception(
                                $this->get_error_message(1005, 'Untrusted domain'),
                                1005
                            );
                        }
                        $download_link = $item;
                        break;

                    case 'upload':
                        $file = $item;
                        if ($file['error'] !== UPLOAD_ERR_OK) {
                            throw new Exception(
                                $this->get_error_message(1006, 'File upload error: ' . $file['error']),
                                1006
                            );
                        }
                        $file_name = sanitize_file_name($file['name']);
                        if (pathinfo($file_name, PATHINFO_EXTENSION) !== 'zip') {
                            throw new Exception(
                                $this->get_error_message(1007, 'Only ZIP files are allowed'),
                                1007
                            );
                        }
                        $temp_file = $file['tmp_name'];
                        $upload_dir = wp_upload_dir();
                        $dest_path = $upload_dir['path'] . '/' . $file_name;

                        if (!move_uploaded_file($temp_file, $dest_path)) {
                            throw new Exception(
                                $this->get_error_message(1008, 'Failed to move uploaded file. Check server permissions.'),
                                1008
                            );
                        }

                        if (!$this->is_plugin_zip($dest_path)) {
                            if ($this->is_theme_zip($dest_path)) {
                                unlink($dest_path);
                                throw new Exception(
                                    $this->get_error_message(1009, 'Theme ZIP detected'),
                                    1009
                                );
                            }
                            unlink($dest_path);
                            throw new Exception(
                                $this->get_error_message(1010, 'Invalid plugin ZIP'),
                                1010
                            );
                        }

                        $download_link = $dest_path;
                        $item = $file_name;
                        break;
                }

                $result = $upgrader->install($download_link);
                if ($type === 'upload' && file_exists($dest_path)) {
                    unlink($dest_path);
                }

                if (is_wp_error($result)) {
                    $error_message = $result->get_error_message();
                    throw new Exception(
                        $this->get_error_message(1011, $error_message ?: 'Unknown installation error'),
                        1011
                    );
                } elseif ($result !== true) {
                    throw new Exception(
                        $this->get_error_message(1012, 'Installation failed unexpectedly'),
                        1012
                    );
                }

                $success_message = __('Successfully installed', 'bulk-plugin-installer');

                // Add auto-activation logic
                if ($install_options['auto_activate'] && $result === true) {
                    $plugin_file = false;

                    // Find the installed plugin file
                    $plugin_folders = glob(WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR);
                    foreach ($plugin_folders as $plugin_folder) {
                        $potential_main_file = basename($plugin_folder) . '.php';
                        if (file_exists($plugin_folder . '/' . $potential_main_file)) {
                            $plugin_file = basename($plugin_folder) . '/' . $potential_main_file;
                            break;
                        }
                    }

                    if ($plugin_file) {
                        if (is_multisite() && is_network_admin()) {
                            $activate = activate_plugin($plugin_file, '', true);
                        } else {
                            $activate = activate_plugin($plugin_file);
                        }

                        if (!is_wp_error($activate)) {
                            $success_message .= ' ' . __('and activated', 'bulk-plugin-installer');
                        }
                    }
                }

                bpi_add_log_entry(
                    $item,
                    'plugin',
                    'install',
                    $type,
                    'success',
                    $success_message
                );

                $results[$item] = [
                    'success' => true,
                    'message' => $success_message
                ];
            } catch (Exception $e) {
                $no_retry_codes = [1001, 1004, 1005, 1007, 1009, 1010];
                $results[$item] = [
                    'success' => false,
                    'message' => $this->get_error_message($e->getCode(), $e->getMessage()),
                    'error_code' => $e->getCode(),
                    'retry' => !in_array($e->getCode(), $no_retry_codes)
                ];

                bpi_add_log_entry(
                    $item,
                    'plugin',
                    'install',
                    $type,
                    'error',
                    $this->get_error_message($e->getCode(), $e->getMessage())
                );
            }
        }

        return $results;
    }

    /**
     * Install themes
     *
     * @param array $items Items to install
     * @param string $type Installation type
     * @return array Installation results
     */
    public function bpi_install_themes($items, $type) {
        $valid_types = ['repository', 'wenpai', 'url', 'upload'];
        if (!in_array($type, $valid_types)) {
            return ['error' => __('Invalid installation type', 'bulk-plugin-installer')];
        }

        if (empty($items) || !is_array($items)) {
            return ['error' => __('No items provided', 'bulk-plugin-installer')];
        }

        require_once ABSPATH . 'wp-admin/includes/theme-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $install_options = get_option('bpi_install_options', [
            'duplicate_handling' => 'skip',
            'auto_activate' => false,
            'keep_backups' => false,
        ]);

        $results = [];
        $upgrader = new Theme_Upgrader(new WP_Ajax_Upgrader_Skin());
        $installed_themes = wp_get_themes();

        foreach ($items as $item) {
            try {
                $theme_key = null;
                $download_link = '';
                if ($type === 'repository' || $type === 'wenpai') {
                    $item = sanitize_text_field($item);
                    if (!preg_match('/^[a-z0-9-]+$/', $item)) {
                        throw new Exception(
                            $this->get_error_message(2001, 'Invalid theme slug'),
                            2001
                        );
                    }
                    $theme_key = $item;
                } elseif ($type === 'upload') {
                    $file = $item;
                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        throw new Exception(
                            $this->get_error_message(2002, 'File upload error: ' . $file['error']),
                            2002
                        );
                    }
                    $file_name = sanitize_file_name($file['name']);
                    if (pathinfo($file_name, PATHINFO_EXTENSION) !== 'zip') {
                        throw new Exception(
                            $this->get_error_message(2003, 'Only ZIP files are allowed'),
                            2003
                        );
                    }
                    $temp_file = $file['tmp_name'];
                    $upload_dir = wp_upload_dir();
                    $dest_path = $upload_dir['path'] . '/' . $file_name;

                    if (!move_uploaded_file($temp_file, $dest_path)) {
                        throw new Exception(
                            $this->get_error_message(2004, 'Failed to move uploaded file. Check server permissions.'),
                            2004
                        );
                    }

                    if (!$this->is_theme_zip($dest_path)) {
                        if ($this->is_plugin_zip($dest_path)) {
                            unlink($dest_path);
                            throw new Exception(
                                $this->get_error_message(2005, 'Plugin ZIP detected'),
                                2005
                            );
                        }
                        unlink($dest_path);
                        throw new Exception(
                            $this->get_error_message(2006, 'Invalid theme ZIP'),
                            2006
                        );
                    }

                    $zip = new ZipArchive();
                    if ($zip->open($dest_path) === true) {
                        $theme_key = null;
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $filename = $zip->getNameIndex($i);
                            if (strpos($filename, 'style.css') !== false) {
                                $theme_key = dirname($filename);
                                if ($theme_key === '.') {
                                    $theme_key = pathinfo($file_name, PATHINFO_FILENAME);
                                }
                                break;
                            }
                        }
                        $zip->close();
                    }

                    if ($theme_key && array_key_exists($theme_key, $installed_themes)) {
                        switch ($install_options['duplicate_handling']) {
                            case 'skip':
                                if (file_exists($dest_path)) {
                                    unlink($dest_path);
                                }
                                $results[$file_name] = [
                                    'success' => true,
                                    'message' => __('Theme already installed, skipped', 'bulk-plugin-installer'),
                                    'skipped' => true
                                ];
                                bpi_add_log_entry(
                                    $file_name,
                                    'theme',
                                    'install',
                                    $type,
                                    'skipped',
                                    __('Theme already installed, skipped', 'bulk-plugin-installer')
                                );
                                continue 2; // Skip to the next item in the foreach loop

                            case 'reinstall':
                                // Continue with reinstall
                                if ($install_options['keep_backups']) {
                                    // Backup code for themes
                                    $backup_dir = WP_CONTENT_DIR . '/bpi-backups/themes/';
                                    if (!file_exists($backup_dir)) {
                                        wp_mkdir_p($backup_dir);
                                    }

                                    $theme_dir = get_theme_root() . '/' . $theme_key;
                                    $backup_path = $backup_dir . $theme_key . '-' . date('Y-m-d-H-i-s') . '.zip';

                                    // Create backup
                                    if (class_exists('ZipArchive') && file_exists($theme_dir)) {
                                        $zip = new ZipArchive();
                                        if ($zip->open($backup_path, ZipArchive::CREATE) === TRUE) {
                                            $files = new RecursiveIteratorIterator(
                                                new RecursiveDirectoryIterator($theme_dir),
                                                RecursiveIteratorIterator::LEAVES_ONLY
                                            );

                                            foreach ($files as $file) {
                                                if (!$file->isDir()) {
                                                    $filePath = $file->getRealPath();
                                                    $relativePath = substr($filePath, strlen($theme_dir) + 1);
                                                    $zip->addFile($filePath, $relativePath);
                                                }
                                            }

                                            $zip->close();
                                        }
                                    }
                                }
                                break;

                            case 'error':
                                if (file_exists($dest_path)) {
                                    unlink($dest_path);
                                }
                                $results[$file_name] = [
                                    'success' => false,
                                    'message' => __('Theme already installed. Set "Skip duplicates" in settings to ignore this error.', 'bulk-plugin-installer'),
                                    'error_code' => 2013
                                ];
                                bpi_add_log_entry(
                                    $file_name,
                                    'theme',
                                    'install',
                                    $type,
                                    'error',
                                    __('Theme already installed. Set "Skip duplicates" in settings to ignore this error.', 'bulk-plugin-installer')
                                );
                                continue 2; // Skip to the next item in the foreach loop
                        }
                    }

                    $download_link = $dest_path;
                    $item = $file_name;
                } else {
                    $theme_key = $item;
                    if ($theme_key && array_key_exists($theme_key, $installed_themes)) {
                        switch ($install_options['duplicate_handling']) {
                            case 'skip':
                                $results[$item] = [
                                    'success' => true,
                                    'message' => __('Theme already installed, skipped', 'bulk-plugin-installer'),
                                    'skipped' => true
                                ];
                                bpi_add_log_entry(
                                    $item,
                                    'theme',
                                    'install',
                                    $type,
                                    'skipped',
                                    __('Theme already installed, skipped', 'bulk-plugin-installer')
                                );
                                continue 2; // Skip to the next item in the foreach loop

                            case 'reinstall':
                                // Continue with reinstall
                                if ($install_options['keep_backups']) {
                                    // Backup code for themes
                                    $backup_dir = WP_CONTENT_DIR . '/bpi-backups/themes/';
                                    if (!file_exists($backup_dir)) {
                                        wp_mkdir_p($backup_dir);
                                    }

                                    $theme_dir = get_theme_root() . '/' . $theme_key;
                                    $backup_path = $backup_dir . $theme_key . '-' . date('Y-m-d-H-i-s') . '.zip';

                                    // Create backup
                                    if (class_exists('ZipArchive') && file_exists($theme_dir)) {
                                        $zip = new ZipArchive();
                                        if ($zip->open($backup_path, ZipArchive::CREATE) === TRUE) {
                                            $files = new RecursiveIteratorIterator(
                                                new RecursiveDirectoryIterator($theme_dir),
                                                RecursiveIteratorIterator::LEAVES_ONLY
                                            );

                                            foreach ($files as $file) {
                                                if (!$file->isDir()) {
                                                    $filePath = $file->getRealPath();
                                                    $relativePath = substr($filePath, strlen($theme_dir) + 1);
                                                    $zip->addFile($filePath, $relativePath);
                                                }
                                            }

                                            $zip->close();
                                        }
                                    }
                                }
                                break;

                            case 'error':
                                $results[$item] = [
                                    'success' => false,
                                    'message' => __('Theme already installed. Set "Skip duplicates" in settings to ignore this error.', 'bulk-plugin-installer'),
                                    'error_code' => 2013
                                ];
                                bpi_add_log_entry(
                                    $item,
                                    'theme',
                                    'install',
                                    $type,
                                    'error',
                                    __('Theme already installed. Set "Skip duplicates" in settings to ignore this error.', 'bulk-plugin-installer')
                                );
                                continue 2; // Skip to the next item in the foreach loop
                        }
                    }
                }

                switch ($type) {
                    case 'repository':
                        $api = themes_api('theme_information', [
                            'slug' => $item,
                            'fields' => ['sections' => false]
                        ]);
                        if (is_wp_error($api)) {
                            throw new Exception(
                                $this->get_error_message(2007, 'Failed to fetch theme info: ' . $api->get_error_message()),
                                2007
                            );
                        }
                        $download_link = $api->download_link;
                        break;

                    case 'wenpai':
                        $response = wp_remote_get("https://api.wenpai.net/wp-json/wp/v2/themes/{$item}");
                        if (is_wp_error($response)) {
                            $code = wp_remote_retrieve_response_code($response);
                            throw new Exception(
                                $this->get_error_message(2008, "Download failed with status code $code: " . $response->get_error_message()),
                                2008
                            );
                        }
                        $theme_data = json_decode(wp_remote_retrieve_body($response), true);
                        $download_link = $theme_data && !empty($theme_data['download_link'])
                            ? $theme_data['download_link']
                            : "https://downloads.wenpai.net/theme/{$item}.latest-stable.zip";
                        break;

                    case 'url':
                        $item = sanitize_text_field($item);
                        if (!filter_var($item, FILTER_VALIDATE_URL)) {
                            throw new Exception(
                                $this->get_error_message(2009, 'Invalid URL format'),
                                2009
                            );
                        }
                        if (!bpi_is_domain_allowed($item)) {
                            throw new Exception(
                                $this->get_error_message(2010, 'Untrusted domain'),
                                2010
                            );
                        }
                        $download_link = $item;
                        break;

                    case 'upload':
                        // Already handled above
                        break;
                }

                $result = $upgrader->install($download_link);
                if ($type === 'upload' && file_exists($dest_path)) {
                    unlink($dest_path);
                }

                if (is_wp_error($result)) {
                    $error_message = $result->get_error_message();
                    throw new Exception(
                        $this->get_error_message(2011, $error_message ?: 'Unknown installation error'),
                        2011
                    );
                } elseif ($result !== true) {
                    throw new Exception(
                        $this->get_error_message(2012, 'Installation failed unexpectedly'),
                        2012
                    );
                }

                bpi_add_log_entry(
                    $item,
                    'theme',
                    'install',
                    $type,
                    'success',
                    __('Successfully installed', 'bulk-plugin-installer')
                );

                $results[$item] = [
                    'success' => true,
                    'message' => __('Successfully installed', 'bulk-plugin-installer')
                ];
            } catch (Exception $e) {
                $no_retry_codes = [2001, 2003, 2005, 2006, 2009, 2010];
                $results[$item] = [
                    'success' => false,
                    'message' => $this->get_error_message($e->getCode(), $e->getMessage()),
                    'error_code' => $e->getCode(),
                    'retry' => !in_array($e->getCode(), $no_retry_codes)
                ];

                bpi_add_log_entry(
                    $item,
                    'theme',
                    'install',
                    $type,
                    'error',
                    $this->get_error_message($e->getCode(), $e->getMessage())
                );
            }
        }

        return $results;
    }
}
