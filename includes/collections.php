<?php
/**
 * Functions for handling collections
 */

/**
 * Get preset plugin collections
 *
 * @param bool $force_refresh Force refreshing cache
 * @return array Collections data
 */
function bpi_get_preset_collections($force_refresh = false) {
    $collections = get_transient('bpi_preset_collections');

    if ($force_refresh || false === $collections) {
        $collections = [
            'version' => '1.0',
            'last_updated' => current_time('Y-m-d'),
            'collections' => []
        ];

        // First try to load from remote sources
        $sources = get_option('bpi_collection_sources', []);
        $loaded_remote = false;

        foreach ($sources as $source) {
            if (empty($source['enabled']) || empty($source['url'])) {
                continue;
            }

            $response = wp_remote_get($source['url'], [
                'timeout' => 15,
                'sslverify' => true,
                'headers' => [
                    'User-Agent' => 'WordPress/Bulk-Plugin-Installer-' . BPI_VERSION
                ]
            ]);

            if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
                $remote_data = json_decode(wp_remote_retrieve_body($response), true);

                if (is_array($remote_data) && !empty($remote_data['collections'])) {
                    if (isset($remote_data['version']) && version_compare($remote_data['version'], $collections['version'], '>')) {
                        $collections['version'] = $remote_data['version'];
                    }

                    if (isset($remote_data['last_updated'])) {
                        $collections['last_updated'] = $remote_data['last_updated'];
                    }

                    $collections['collections'] = array_merge($collections['collections'], $remote_data['collections']);
                    $loaded_remote = true;
                }
            }
        }

        // If no remote collections were loaded, try local file
        if (!$loaded_remote) {
            $local_file = BPI_PATH . 'data/collections.json';
            if (file_exists($local_file)) {
                $local_data = json_decode(file_get_contents($local_file), true);
                if (is_array($local_data) && !empty($local_data['collections'])) {
                    $collections = $local_data;
                }
            }
        }

        // Cache for 24 hours
        set_transient('bpi_preset_collections', $collections, DAY_IN_SECONDS);
    }

    return apply_filters('bpi_preset_collections', $collections);
}

/**
 * Count items in a collection
 *
 * @param array $items Collection items
 * @return int Total count
 */
function bpi_count_collection_items($items) {
    $count = 0;

    if (!is_array($items)) {
        return 0;
    }

    foreach ($items as $source => $source_items) {
        if (is_array($source_items)) {
            $count += count($source_items);
        }
    }

    return $count;
}

/**
 * Get AJAX handler for collection details
 */
function bpi_ajax_get_collection_details() {
    check_ajax_referer('bpi_installer', 'nonce');

    if (!bpi_user_can_install()) {
        wp_send_json_error([
            'message' => __('Insufficient permissions', 'bulk-plugin-installer')
        ]);
    }

    $collection_id = sanitize_text_field($_POST['collection_id'] ?? '');
    $collections = bpi_get_preset_collections();

    if (!isset($collections['collections'][$collection_id])) {
        wp_send_json_error([
            'message' => __('Collection not found', 'bulk-plugin-installer')
        ]);
    }

    $collection = $collections['collections'][$collection_id];

    // Generate HTML output
    ob_start();
    ?>
    <h2><?php echo esc_html($collection['name']); ?></h2>
    <p class="bpi-collection-description"><?php echo esc_html($collection['description']); ?></p>

    <?php if (!empty($collection['screenshot'])) : ?>
    <div class="bpi-collection-screenshot">
        <img src="<?php echo esc_url($collection['screenshot']); ?>" alt="<?php echo esc_attr($collection['name']); ?>">
    </div>
    <?php endif; ?>

    <div class="bpi-install-collection-wrapper">
        <p><?php printf(__('This collection includes %d plugins and %d themes. Click the button to install them all.', 'bulk-plugin-installer'),
            bpi_count_collection_items($collection['plugins'] ?? []),
            bpi_count_collection_items($collection['themes'] ?? [])
        ); ?></p>
        <button type="button" class="button button-primary bpi-install-collection" data-collection="<?php echo esc_attr($collection_id); ?>">
            <?php _e('Install Collection', 'bulk-plugin-installer'); ?>
        </button>
    </div>

    <?php if (!empty($collection['plugins'])) : ?>
    <div class="bpi-collection-plugins">
        <h3><?php _e('Plugins', 'bulk-plugin-installer'); ?></h3>
        <div class="bpi-collection-item-list">
            <?php foreach ($collection['plugins'] as $source => $plugins) : ?>
                <?php if (!is_array($plugins)) continue; ?>
                <?php foreach ($plugins as $plugin) :
                    $slug = is_array($plugin) ? $plugin['slug'] : $plugin;
                    $name = is_array($plugin) ? $plugin['name'] : $slug;
                    $description = is_array($plugin) ? ($plugin['description'] ?? '') : '';
                    $required = is_array($plugin) && isset($plugin['required']) ? $plugin['required'] : false;
                ?>
                <div class="bpi-collection-item">
                    <div class="bpi-collection-item-icon">
                        <span class="dashicons dashicons-admin-plugins"></span>
                    </div>
                    <div class="bpi-collection-item-name">
                        <?php echo esc_html($name); ?>
                        <?php if ($required) : ?>
                            <span class="bpi-required"><?php _e('(Required)', 'bulk-plugin-installer'); ?></span>
                        <?php endif; ?>
                        <?php if ($description) : ?>
                            <div class="bpi-collection-item-description"><?php echo esc_html($description); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="bpi-collection-item-source">
                        <?php
                        switch ($source) {
                            case 'repository':
                                _e('Repository', 'bulk-plugin-installer');
                                break;
                            case 'wenpai':
                                _e('WenPai', 'bulk-plugin-installer');
                                break;
                            case 'url':
                                _e('URL', 'bulk-plugin-installer');
                                break;
                        }
                        ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($collection['themes'])) : ?>
    <div class="bpi-collection-themes">
        <h3><?php _e('Themes', 'bulk-plugin-installer'); ?></h3>
        <div class="bpi-collection-item-list">
            <?php foreach ($collection['themes'] as $source => $themes) : ?>
                <?php if (!is_array($themes)) continue; ?>
                <?php foreach ($themes as $theme) :
                    $slug = is_array($theme) ? $theme['slug'] : $theme;
                    $name = is_array($theme) ? $theme['name'] : $slug;
                    $description = is_array($theme) ? ($theme['description'] ?? '') : '';
                    $required = is_array($theme) && isset($theme['required']) ? $theme['required'] : false;
                ?>
                <div class="bpi-collection-item">
                    <div class="bpi-collection-item-icon">
                        <span class="dashicons dashicons-admin-appearance"></span>
                    </div>
                    <div class="bpi-collection-item-name">
                        <?php echo esc_html($name); ?>
                        <?php if ($required) : ?>
                            <span class="bpi-required"><?php _e('(Required)', 'bulk-plugin-installer'); ?></span>
                        <?php endif; ?>
                        <?php if ($description) : ?>
                            <div class="bpi-collection-item-description"><?php echo esc_html($description); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="bpi-collection-item-source">
                        <?php
                        switch ($source) {
                            case 'repository':
                                _e('Repository', 'bulk-plugin-installer');
                                break;
                            case 'wenpai':
                                _e('WenPai', 'bulk-plugin-installer');
                                break;
                            case 'url':
                                _e('URL', 'bulk-plugin-installer');
                                break;
                        }
                        ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php
    $html = ob_get_clean();

    wp_send_json_success([
        'html' => $html,
        'name' => $collection['name']
    ]);
}

/**
 * Install collection AJAX handler
 */
function bpi_ajax_install_collection() {
    check_ajax_referer('bpi_installer', 'nonce');

    if (!bpi_user_can_install()) {
        wp_send_json_error([
            'message' => __('Insufficient permissions', 'bulk-plugin-installer')
        ]);
    }

    $collection_id = sanitize_text_field($_POST['collection_id'] ?? '');
    $collections = bpi_get_preset_collections();

    if (!isset($collections['collections'][$collection_id])) {
        wp_send_json_error([
            'message' => __('Collection not found', 'bulk-plugin-installer')
        ]);
    }

    $collection = $collections['collections'][$collection_id];
    $installer = new BPI_Installer();
    $results = [
        'plugins' => [],
        'themes' => []
    ];

    // Install plugins
    if (!empty($collection['plugins']) && is_array($collection['plugins'])) {
        foreach ($collection['plugins'] as $source => $plugins) {
            if (!is_array($plugins) || empty($plugins)) {
                continue;
            }

            $items = [];
            foreach ($plugins as $plugin) {
                $items[] = is_array($plugin) ? $plugin['slug'] : $plugin;
            }

            if (!empty($items)) {
                $source_results = $installer->bpi_install_plugins($items, $source);
                $results['plugins'] = array_merge($results['plugins'], $source_results);
            }
        }
    }

    // Install themes
    if (!empty($collection['themes']) && is_array($collection['themes'])) {
        foreach ($collection['themes'] as $source => $themes) {
            if (!is_array($themes) || empty($themes)) {
                continue;
            }

            $items = [];
            foreach ($themes as $theme) {
                $items[] = is_array($theme) ? $theme['slug'] : $theme;
            }

            if (!empty($items)) {
                $source_results = $installer->bpi_install_themes($items, $source);
                $results['themes'] = array_merge($results['themes'], $source_results);
            }
        }
    }

    // Update statistics
    bpi_update_statistics(array_merge($results['plugins'], $results['themes']));

    // Generate HTML output
    ob_start();
    ?>
    <div class="notice notice-success">
        <p><?php printf(__('Installation of collection "%s" completed!', 'bulk-plugin-installer'), esc_html($collection['name'])); ?></p>
    </div>

    <h3><?php _e('Plugins Results', 'bulk-plugin-installer'); ?></h3>
    <?php if (empty($results['plugins'])) : ?>
        <p><?php _e('No plugins were installed.', 'bulk-plugin-installer'); ?></p>
    <?php else : ?>
    <ul class="installation-list">
        <?php foreach ($results['plugins'] as $item => $result) : ?>
        <li class="<?php echo $result['success'] ? 'success' : 'error'; ?>">
            <span class="item-name"><?php echo esc_html($item); ?></span>
            <span class="status <?php echo $result['success'] ? 'success' : 'error'; ?>">
                <?php if ($result['success']) : ?>
                    <?php echo isset($result['skipped']) && $result['skipped'] ? 'ⓘ ' : '✓ '; ?>
                    <?php echo esc_html($result['message']); ?>
                <?php else : ?>
                    ✗ <?php echo esc_html($result['message']); ?>
                    <?php if (isset($result['retry']) && $result['retry']) : ?>
                    <button class="retry-btn" data-item="<?php echo esc_attr($item); ?>" data-type="repository">
                        <?php _e('Retry', 'bulk-plugin-installer'); ?>
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h3><?php _e('Themes Results', 'bulk-plugin-installer'); ?></h3>
    <?php if (empty($results['themes'])) : ?>
        <p><?php _e('No themes were installed.', 'bulk-plugin-installer'); ?></p>
    <?php else : ?>
    <ul class="installation-list">
        <?php foreach ($results['themes'] as $item => $result) : ?>
        <li class="<?php echo $result['success'] ? 'success' : 'error'; ?>">
            <span class="item-name"><?php echo esc_html($item); ?></span>
            <span class="status <?php echo $result['success'] ? 'success' : 'error'; ?>">
                <?php if ($result['success']) : ?>
                    <?php echo isset($result['skipped']) && $result['skipped'] ? 'ⓘ ' : '✓ '; ?>
                    <?php echo esc_html($result['message']); ?>
                <?php else : ?>
                    ✗ <?php echo esc_html($result['message']); ?>
                    <?php if (isset($result['retry']) && $result['retry']) : ?>
                    <button class="retry-btn" data-item="<?php echo esc_attr($item); ?>" data-type="repository">
                        <?php _e('Retry', 'bulk-plugin-installer'); ?>
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if (isset($collection['configuration'])) : ?>
    <div class="notice notice-info">
        <p><?php _e('Collection installed successfully. Additional configuration is available.', 'bulk-plugin-installer'); ?></p>
        <p><button class="button button-secondary bpi-configure-collection" data-collection="<?php echo esc_attr($collection_id); ?>">
            <?php _e('Configure Site', 'bulk-plugin-installer'); ?>
        </button></p>
    </div>
    <?php endif; ?>
    <?php
    $html = ob_get_clean();

    wp_send_json_success([
        'html' => $html,
        'results' => $results
    ]);
}

/**
 * Save remote collection source
 */
function bpi_ajax_save_remote_source() {
    check_ajax_referer('bpi_installer', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error([
            'message' => __('Insufficient permissions', 'bulk-plugin-installer')
        ]);
    }

    $name = sanitize_text_field($_POST['name'] ?? '');
    $url = esc_url_raw($_POST['url'] ?? '');
    $id = sanitize_text_field($_POST['id'] ?? '');

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        wp_send_json_error([
            'message' => __('Please enter a valid URL', 'bulk-plugin-installer')
        ]);
    }

    $sources = get_option('bpi_collection_sources', []);

    // Check if URL is valid and accessible
    $response = wp_remote_get($url, [
        'timeout' => 15,
        'sslverify' => true,
        'headers' => [
            'User-Agent' => 'WordPress/Bulk-Plugin-Installer-' . BPI_VERSION
        ]
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error([
            'message' => __('Unable to access URL. Error: ', 'bulk-plugin-installer') . $response->get_error_message()
        ]);
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        wp_send_json_error([
            'message' => sprintf(__('Invalid response from URL. Status code: %d', 'bulk-plugin-installer'), $response_code)
        ]);
    }

    $json = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($json) || !isset($json['collections']) || !is_array($json['collections'])) {
        wp_send_json_error([
            'message' => __('The URL does not contain valid collections data', 'bulk-plugin-installer')
        ]);
    }

    if (empty($id)) {
        // Add new source
        $sources[] = [
            'name' => !empty($name) ? $name : sprintf(__('Collection Source %d', 'bulk-plugin-installer'), count($sources) + 1),
            'url' => $url,
            'enabled' => true
        ];
    } else {
        // Update existing source
        foreach ($sources as $index => $source) {
            if ($index == $id) {
                $sources[$index]['name'] = !empty($name) ? $name : $source['name'];
                $sources[$index]['url'] = $url;
                break;
            }
        }
    }

    update_option('bpi_collection_sources', $sources);
    delete_transient('bpi_preset_collections');

    wp_send_json_success([
        'message' => __('Collection source saved successfully', 'bulk-plugin-installer'),
        'sources' => $sources
    ]);
}

/**
 * Refresh collections
 */
function bpi_ajax_refresh_collections() {
    check_ajax_referer('bpi_installer', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error([
            'message' => __('Insufficient permissions', 'bulk-plugin-installer')
        ]);
    }

    delete_transient('bpi_preset_collections');
    $collections = bpi_get_preset_collections(true);

    wp_send_json_success([
        'message' => __('Collections refreshed successfully', 'bulk-plugin-installer'),
        'count' => count($collections['collections']),
        'last_updated' => $collections['last_updated']
    ]);
}
