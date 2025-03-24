<?php
class BPI_Installer {
    private $wp_filesystem;

    public function __construct() {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            if (!WP_Filesystem()) {
                throw new Exception(__('Unable to initialize filesystem. Please check server permissions.', 'bulk-plugin-installer'));
            }
        }
        $this->wp_filesystem = $wp_filesystem;
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
                        throw new Exception(__('Invalid plugin slug', 'bulk-plugin-installer'));
                    }
                    $plugin_key = $item . '/' . $item . '.php';
                } elseif ($type === 'upload') {
                    $file_name = sanitize_file_name($item['name']);
                    $plugin_key = pathinfo($file_name, PATHINFO_FILENAME) . '/' . pathinfo($file_name, PATHINFO_FILENAME) . '.php';
                }

                if ($plugin_key && array_key_exists($plugin_key, $installed_plugins)) {
                    $results[$item] = [
                        'success' => true,
                        'message' => __('Plugin already installed, skipped', 'bulk-plugin-installer')
                    ];
                    continue;
                }

                switch ($type) {
                    case 'repository':
                        $api = plugins_api('plugin_information', [
                            'slug' => $item,
                            'fields' => ['sections' => false]
                        ]);
                        if (is_wp_error($api)) {
                            throw new Exception($api->get_error_message());
                        }
                        $result = $upgrader->install($api->download_link);
                        break;

                    case 'wenpai':
                        $response = wp_remote_get("https://api.wenpai.net/wp-json/wp/v2/plugins/{$item}");
                        if (is_wp_error($response)) {
                            throw new Exception($response->get_error_message());
                        }
                        $plugin_data = json_decode(wp_remote_retrieve_body($response), true);
                        $download_link = $plugin_data && !empty($plugin_data['download_link']) 
                            ? $plugin_data['download_link'] 
                            : "https://downloads.wenpai.net/plugin/{$item}.latest-stable.zip";
                        $result = $upgrader->install($download_link);
                        break;

                    case 'url':
                        $item = sanitize_text_field($item);
                        if (!filter_var($item, FILTER_VALIDATE_URL) || !bpi_is_domain_allowed($item)) {
                            throw new Exception(__('Invalid or untrusted URL', 'bulk-plugin-installer'));
                        }
                        $result = $upgrader->install($item);
                        break;

                    case 'upload':
                        $file = $item;
                        if ($file['error'] !== UPLOAD_ERR_OK) {
                            throw new Exception(__('File upload error: ', 'bulk-plugin-installer') . $file['error']);
                        }
                        $file_name = sanitize_file_name($file['name']);
                        if (pathinfo($file_name, PATHINFO_EXTENSION) !== 'zip') {
                            throw new Exception(__('Only ZIP files are allowed', 'bulk-plugin-installer'));
                        }
                        $temp_file = $file['tmp_name'];
                        $upload_dir = wp_upload_dir();
                        $dest_path = $upload_dir['path'] . '/' . $file_name;

                        if (!move_uploaded_file($temp_file, $dest_path)) {
                            throw new Exception(__('Failed to move uploaded file. Check server permissions.', 'bulk-plugin-installer'));
                        }

                        // 检查 ZIP 文件类型
                        if (!$this->is_plugin_zip($dest_path)) {
                            if ($this->is_theme_zip($dest_path)) {
                                unlink($dest_path);
                                throw new Exception(__('This appears to be a theme ZIP. Please use the Themes tab to install.', 'bulk-plugin-installer'));
                            }
                            unlink($dest_path);
                            throw new Exception(__('Invalid plugin ZIP file', 'bulk-plugin-installer'));
                        }

                        $result = $upgrader->install($dest_path);
                        if (file_exists($dest_path)) {
                            unlink($dest_path);
                        }
                        $item = $file_name;
                        break;
                }

                if (is_wp_error($result)) {
                    throw new Exception($result->get_error_message());
                }

                $results[$item] = [
                    'success' => $result === true,
                    'message' => $result === true ? __('Successfully installed', 'bulk-plugin-installer') : __('Installation failed', 'bulk-plugin-installer')
                ];
            } catch (Exception $e) {
                $results[$item] = [
                    'success' => false,
                    'message' => $e->getMessage()
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
                if ($type === 'repository' || $type === 'wenpai') {
                    $item = sanitize_text_field($item);
                    if (!preg_match('/^[a-z0-9-]+$/', $item)) {
                        throw new Exception(__('Invalid theme slug', 'bulk-plugin-installer'));
                    }
                    $theme_key = $item;
                } elseif ($type === 'upload') {
                    $file_name = sanitize_file_name($item['name']);
                    $theme_key = pathinfo($file_name, PATHINFO_FILENAME);
                }

                if ($theme_key && array_key_exists($theme_key, $installed_themes)) {
                    $results[$item] = [
                        'success' => true,
                        'message' => __('Theme already installed, skipped', 'bulk-plugin-installer')
                    ];
                    continue;
                }

                switch ($type) {
                    case 'repository':
                        $api = themes_api('theme_information', [
                            'slug' => $item,
                            'fields' => ['sections' => false]
                        ]);
                        if (is_wp_error($api)) {
                            throw new Exception($api->get_error_message());
                        }
                        $result = $upgrader->install($api->download_link);
                        break;

                    case 'wenpai':
                        $response = wp_remote_get("https://api.wenpai.net/wp-json/wp/v2/themes/{$item}");
                        if (is_wp_error($response)) {
                            throw new Exception($response->get_error_message());
                        }
                        $theme_data = json_decode(wp_remote_retrieve_body($response), true);
                        $download_link = $theme_data && !empty($theme_data['download_link']) 
                            ? $theme_data['download_link'] 
                            : "https://downloads.wenpai.net/theme/{$item}.latest-stable.zip";
                        $result = $upgrader->install($download_link);
                        break;

                    case 'url':
                        $item = sanitize_text_field($item);
                        if (!filter_var($item, FILTER_VALIDATE_URL) || !bpi_is_domain_allowed($item)) {
                            throw new Exception(__('Invalid or untrusted URL', 'bulk-plugin-installer'));
                        }
                        $result = $upgrader->install($item);
                        break;

                    case 'upload':
                        $file = $item;
                        if ($file['error'] !== UPLOAD_ERR_OK) {
                            throw new Exception(__('File upload error: ', 'bulk-plugin-installer') . $file['error']);
                        }
                        $file_name = sanitize_file_name($file['name']);
                        if (pathinfo($file_name, PATHINFO_EXTENSION) !== 'zip') {
                            throw new Exception(__('Only ZIP files are allowed', 'bulk-plugin-installer'));
                        }
                        $temp_file = $file['tmp_name'];
                        $upload_dir = wp_upload_dir();
                        $dest_path = $upload_dir['path'] . '/' . $file_name;

                        if (!move_uploaded_file($temp_file, $dest_path)) {
                            throw new Exception(__('Failed to move uploaded file. Check server permissions.', 'bulk-plugin-installer'));
                        }

                        // 检查 ZIP 文件类型
                        if (!$this->is_theme_zip($dest_path)) {
                            if ($this->is_plugin_zip($dest_path)) {
                                unlink($dest_path);
                                throw new Exception(__('This appears to be a plugin ZIP. Please use the Plugins tab to install.', 'bulk-plugin-installer'));
                            }
                            unlink($dest_path);
                            throw new Exception(__('Invalid theme ZIP file', 'bulk-plugin-installer'));
                        }

                        $result = $upgrader->install($dest_path);
                        if (file_exists($dest_path)) {
                            unlink($dest_path);
                        }
                        $item = $file_name;
                        break;
                }

                if (is_wp_error($result)) {
                    throw new Exception($result->get_error_message());
                }

                $results[$item] = [
                    'success' => $result === true,
                    'message' => $result === true ? __('Successfully installed', 'bulk-plugin-installer') : __('Installation failed', 'bulk-plugin-installer')
                ];
            } catch (Exception $e) {
                $results[$item] = [
                    'success' => false,
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }
}