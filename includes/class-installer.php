<?php
class BPI_Installer {
    private $wp_filesystem;
    private $error_messages = []; // Initialize as empty array

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


    public function get_error_message($code, $default_message) {
        return __($this->error_messages[$code] ?? $default_message, 'bulk-plugin-installer');
    }

    private function is_plugin_zip($zip_path) {
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

    private function is_theme_zip($zip_path) {
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
                    $results[$item] = [
                        'success' => true,
                        'message' => __('Plugin already installed, skipped', 'bulk-plugin-installer'),
                        'skipped' => true
                    ];
                    continue;
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

                $results[$item] = [
                    'success' => true,
                    'message' => __('Successfully installed', 'bulk-plugin-installer')
                ];
            } catch (Exception $e) {
                $no_retry_codes = [1001, 1004, 1005, 1007, 1009, 1010];
                $results[$item] = [
                    'success' => false,
                    'message' => $this->get_error_message($e->getCode(), $e->getMessage()),
                    'error_code' => $e->getCode(),
                    'retry' => !in_array($e->getCode(), $no_retry_codes)
                ];
            }
        }

        return $results;
    }

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
                        if (file_exists($dest_path)) {
                            unlink($dest_path);
                        }
                        $results[$file_name] = [
                            'success' => true,
                            'message' => __('Theme already installed, skipped', 'bulk-plugin-installer'),
                            'skipped' => true
                        ];
                        continue;
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

                    $download_link = $dest_path;
                    $item = $file_name;
                } else {
                    $theme_key = $item;
                    if ($theme_key && array_key_exists($theme_key, $installed_themes)) {
                        $results[$item] = [
                            'success' => true,
                            'message' => __('Theme already installed, skipped', 'bulk-plugin-installer'),
                            'skipped' => true
                        ];
                        continue;
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
            }
        }

        return $results;
    }
}