<?php
/**
 * Admin page rendering functions
 */

/**
 * Render the admin page
 */
function bpi_render_admin_page() {
    if (!bpi_user_can_install()) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'bulk-plugin-installer'));
    }

    wp_enqueue_style('bpi-admin-style', BPI_URL . 'assets/css/admin.css', [], BPI_VERSION);
    wp_enqueue_script('bpi-admin', BPI_URL . 'assets/js/admin.js', ['jquery'], BPI_VERSION, true);

    $i18n_strings = [
        'go_to_settings' => __('Go to Settings to add domain', 'bulk-plugin-installer'),
        'switch_to_themes' => __('Switch to Themes tab', 'bulk-plugin-installer'),
        'switch_to_plugins' => __('Switch to Plugins tab', 'bulk-plugin-installer'),
        'retry' => __('Retry', 'bulk-plugin-installer'),
        'installation_in_progress' => __('Installation in progress... (Large ZIP files may take some time)', 'bulk-plugin-installer'),
        'completed' => __('completed', 'bulk-plugin-installer'),
        'remaining' => __('remaining', 'bulk-plugin-installer'),
        'installation_complete' => __('Installation completed! Check the results below. Failed items can be retried using the "Retry" buttons if applicable.', 'bulk-plugin-installer'),
        'no_files' => __('Please select at least one ZIP file.', 'bulk-plugin-installer'),
        'no_slugs' => __('Please enter at least one slug.', 'bulk-plugin-installer'),
        'no_urls' => __('Please enter at least one URL.', 'bulk-plugin-installer'),
        'confirm_install_collection' => __('Are you sure you want to install all items in this collection?', 'bulk-plugin-installer'),
        'installing' => __('Installing...', 'bulk-plugin-installer'),
        'install_collection' => __('Install Collection', 'bulk-plugin-installer'),
        'confirm_delete_source' => __('Are you sure you want to delete this collection source?', 'bulk-plugin-installer'),
        'reinstall' => __('Reinstall', 'bulk-plugin-installer'),
        'manual_reinstall' => __('Manual reinstall required', 'bulk-plugin-installer')
    ];

    wp_localize_script('bpi-admin', 'bpiAjax', [
        'nonce' => wp_create_nonce('bpi_installer'),
        'ajaxurl' => admin_url('admin-ajax.php'),
        'i18n' => $i18n_strings
    ]);
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Bulk Plugin & Theme Installer', 'bulk-plugin-installer'); ?>
            <span style="font-size: 13px; padding-left: 10px;"><?php printf(esc_html__('Version: %s', 'bulk-plugin-installer'), esc_html(BPI_VERSION)); ?></span>
            <a href="https://wpmultisite.com/document/" target="_blank" class="button button-secondary" style="margin-left: 10px;"><?php esc_html_e('Document', 'bulk-plugin-installer'); ?></a>
            <a href="https://wpmultisite.com/forums/" target="_blank" class="button button-secondary"><?php esc_html_e('Support', 'bulk-plugin-installer'); ?></a>
        </h1>

        <div class="bpi-container">
            <div class="bpi-card">
                <div id="bpi-tabs">
                    <div class="bpi-tabs-nav">
                        <button type="button" class="bpi-tab active" data-tab="plugins"><?php _e('Plugins', 'bulk-plugin-installer'); ?></button>
                        <button type="button" class="bpi-tab" data-tab="themes"><?php _e('Themes', 'bulk-plugin-installer'); ?></button>
                        <button type="button" class="bpi-tab" data-tab="reinstall"><?php _e('Reinstall', 'bulk-plugin-installer'); ?></button>
                        <button type="button" class="bpi-tab" data-tab="collections"><?php _e('Collections', 'bulk-plugin-installer'); ?></button>
                        <button type="button" class="bpi-tab" data-tab="collection-sources"><?php _e('Collection Sources', 'bulk-plugin-installer'); ?></button>
                        <button type="button" class="bpi-tab" data-tab="logs"><?php _e('Logs', 'bulk-plugin-installer'); ?></button>
                        <button type="button" class="bpi-tab" data-tab="settings"><?php _e('Settings', 'bulk-plugin-installer'); ?></button>
                    </div>

                    <div id="plugins" class="bpi-tab-content active">
                        <h2><?php _e('Install Plugins', 'bulk-plugin-installer'); ?></h2>
                        <p><?php _e('Install multiple plugins from various sources.', 'bulk-plugin-installer'); ?></p>
                        <form id="bulk-plugin-form" class="bpi-form" enctype="multipart/form-data">
                            <div class="bpi-form-row">
                                <label for="plugin-install-type"><?php _e('Installation Source:', 'bulk-plugin-installer'); ?></label>
                                <select id="plugin-install-type" name="install_type" class="bpi-select">
                                    <option value="repository"><?php _e('WordPress.org Repository', 'bulk-plugin-installer'); ?></option>
                                    <option value="wenpai"><?php _e('WenPai.org Repository (China Mirror)', 'bulk-plugin-installer'); ?></option>
                                    <option value="url"><?php _e('Remote URL', 'bulk-plugin-installer'); ?></option>
                                    <option value="upload"><?php _e('Upload ZIP', 'bulk-plugin-installer'); ?></option>
                                </select>
                            </div>

                            <div class="bpi-form-row source-input repository-source active">
                                <label for="plugin-slugs"><?php _e('Plugin Slugs:', 'bulk-plugin-installer'); ?></label>
                                <textarea id="plugin-slugs" name="items" rows="8"
                                    placeholder="<?php esc_attr_e('Enter plugin slugs, one per line (e.g., akismet)', 'bulk-plugin-installer'); ?>"
                                ></textarea>
                            </div>

                            <div class="bpi-form-row source-input wenpai-source">
                                <label for="plugin-wenpai-slugs"><?php _e('Plugin Slugs (WenPai.org):', 'bulk-plugin-installer'); ?></label>
                                <textarea id="plugin-wenpai-slugs" name="items" rows="8"
                                    placeholder="<?php esc_attr_e('Enter plugin slugs, one per line (e.g., akismet)', 'bulk-plugin-installer'); ?>"
                                ></textarea>
                            </div>

                            <div class="bpi-form-row source-input url-source">
                                <label for="plugin-urls"><?php _e('Download URLs:', 'bulk-plugin-installer'); ?></label>
                                <textarea id="plugin-urls" name="items" rows="8"
                                    placeholder="<?php esc_attr_e('Enter download URLs, one per line', 'bulk-plugin-installer'); ?>"
                                ></textarea>
                            </div>

                            <div class="bpi-form-row source-input upload-source">
                                <label for="plugin-files"><?php _e('ZIP Files:', 'bulk-plugin-installer'); ?></label>
                                <div class="file-upload-container">
                                    <input type="file" id="plugin-files" name="plugin_files[]" multiple accept=".zip" />
                                    <div class="upload-instructions">
                                        <?php _e('Drag and drop plugin ZIP files here or click to select files', 'bulk-plugin-installer'); ?>
                                    </div>
                                    <div id="selected-files" class="selected-files"></div>
                                </div>
                            </div>

                            <div class="bpi-form-row">
                                <?php wp_nonce_field('bpi_installer', 'bpi_nonce'); ?>
                                <button type="submit" class="button button-primary button-large">
                                    <?php _e('Install Plugins', 'bulk-plugin-installer'); ?>
                                </button>
                            </div>
                        </form>
                    </div>

                    <div id="themes" class="bpi-tab-content">
                        <h2><?php _e('Install Themes', 'bulk-plugin-installer'); ?></h2>
                        <p><?php _e('Install multiple themes from various sources.', 'bulk-plugin-installer'); ?></p>
                        <form id="bulk-theme-form" class="bpi-form" enctype="multipart/form-data">
                            <div class="bpi-form-row">
                                <label for="theme-install-type"><?php _e('Installation Source:', 'bulk-plugin-installer'); ?></label>
                                <select id="theme-install-type" name="install_type" class="bpi-select">
                                    <option value="repository"><?php _e('WordPress.org Repository', 'bulk-plugin-installer'); ?></option>
                                    <option value="wenpai"><?php _e('WenPai.org Repository (China Mirror)', 'bulk-plugin-installer'); ?></option>
                                    <option value="url"><?php _e('Remote URL', 'bulk-plugin-installer'); ?></option>
                                    <option value="upload"><?php _e('Upload ZIP', 'bulk-plugin-installer'); ?></option>
                                </select>
                            </div>

                            <div class="bpi-form-row source-input repository-source active">
                                <label for="theme-slugs"><?php _e('Theme Slugs:', 'bulk-plugin-installer'); ?></label>
                                <textarea id="theme-slugs" name="items" rows="8"
                                    placeholder="<?php esc_attr_e('Enter theme slugs, one per line (e.g., twentytwenty)', 'bulk-plugin-installer'); ?>"
                                ></textarea>
                            </div>

                            <div class="bpi-form-row source-input wenpai-source">
                                <label for="theme-wenpai-slugs"><?php _e('Theme Slugs (WenPai.org):', 'bulk-plugin-installer'); ?></label>
                                <textarea id="theme-wenpai-slugs" name="items" rows="8"
                                    placeholder="<?php esc_attr_e('Enter theme slugs, one per line (e.g., twentytwenty)', 'bulk-plugin-installer'); ?>"
                                ></textarea>
                            </div>

                            <div class="bpi-form-row source-input url-source">
                                <label for="theme-urls"><?php _e('Download URLs:', 'bulk-plugin-installer'); ?></label>
                                <textarea id="theme-urls" name="items" rows="8"
                                    placeholder="<?php esc_attr_e('Enter download URLs, one per line', 'bulk-plugin-installer'); ?>"
                                ></textarea>
                            </div>

                            <div class="bpi-form-row source-input upload-source">
                                <label for="theme-files"><?php _e('ZIP Files:', 'bulk-plugin-installer'); ?></label>
                                <div class="file-upload-container">
                                    <input type="file" id="theme-files" name="theme_files[]" multiple accept=".zip" />
                                    <div class="upload-instructions">
                                        <?php _e('Drag and drop theme ZIP files here or click to select files', 'bulk-plugin-installer'); ?>
                                    </div>
                                    <div id="selected-files" class="selected-files"></div>
                                </div>
                            </div>

                            <div class="bpi-form-row">
                                <?php wp_nonce_field('bpi_installer', 'bpi_nonce'); ?>
                                <button type="submit" class="button button-primary button-large">
                                    <?php _e('Install Themes', 'bulk-plugin-installer'); ?>
                                </button>
                            </div>
                        </form>
                    </div>

                    <div id="reinstall" class="bpi-tab-content">
                        <h2><?php _e('Reinstall Plugins & Themes', 'bulk-plugin-installer'); ?></h2>
                        <p><?php _e('Use this tool to reinstall plugins and themes after a security incident or to fix corrupted files. Note that custom or premium plugins/themes from unknown sources must be manually reinstalled.', 'bulk-plugin-installer'); ?></p>

                        <div class="bpi-reinstall-container">
                            <div class="bpi-reinstall-tabs">
                                <button type="button" class="bpi-reinstall-tab active" data-content="plugins"><?php _e('Plugins', 'bulk-plugin-installer'); ?></button>
                                <button type="button" class="bpi-reinstall-tab" data-content="themes"><?php _e('Themes', 'bulk-plugin-installer'); ?></button>
                            </div>

                            <div class="bpi-reinstall-content active" id="bpi-reinstall-plugins">
                                <div class="bpi-reinstall-actions">
                                    <div class="bpi-search-box">
                                        <input type="text" id="plugin-search" placeholder="<?php esc_attr_e('Search plugins...', 'bulk-plugin-installer'); ?>">
                                    </div>
                                    <div class="bpi-filter-actions">
                                        <label>
                                            <input type="checkbox" id="filter-active-plugins" checked>
                                            <?php _e('Active', 'bulk-plugin-installer'); ?>
                                        </label>
                                        <label>
                                            <input type="checkbox" id="filter-inactive-plugins" checked>
                                            <?php _e('Inactive', 'bulk-plugin-installer'); ?>
                                        </label>
                                        <label>
                                            <input type="checkbox" id="filter-wp-plugins" checked>
                                            <?php _e('WordPress.org', 'bulk-plugin-installer'); ?>
                                        </label>
                                        <label>
                                            <input type="checkbox" id="filter-unknown-plugins" checked>
                                            <?php _e('Unknown Source', 'bulk-plugin-installer'); ?>
                                        </label>
                                    </div>
                                    <div class="bpi-selection-actions">
                                        <button type="button" class="button" id="select-all-plugins"><?php _e('Select All', 'bulk-plugin-installer'); ?></button>
                                        <button type="button" class="button" id="select-none-plugins"><?php _e('Select None', 'bulk-plugin-installer'); ?></button>
                                        <button type="button" class="button" id="select-wp-plugins"><?php _e('Select WordPress.org', 'bulk-plugin-installer'); ?></button>
                                    </div>
                                </div>

                                <table class="wp-list-table widefat fixed striped bpi-plugins-table">
                                    <thead>
                                        <tr>
                                            <th class="check-column"><input type="checkbox" id="plugins-select-all"></th>
                                            <th><?php _e('Plugin', 'bulk-plugin-installer'); ?></th>
                                            <th><?php _e('Version', 'bulk-plugin-installer'); ?></th>
                                            <th><?php _e('Status', 'bulk-plugin-installer'); ?></th>
                                            <th><?php _e('Source', 'bulk-plugin-installer'); ?></th>
                                            <th><?php _e('Actions', 'bulk-plugin-installer'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="plugins-list">
                                        <tr>
                                            <td colspan="6"><?php _e('Loading plugins...', 'bulk-plugin-installer'); ?></td>
                                        </tr>
                                    </tbody>
                                </table>

                                <div class="bpi-bulk-actions">
                                    <button type="button" class="button button-primary" id="reinstall-selected-plugins" disabled>
                                        <?php _e('Reinstall Selected Plugins', 'bulk-plugin-installer'); ?>
                                    </button>
                                    <p class="bpi-warning">
                                        <span class="dashicons dashicons-warning"></span>
                                        <?php _e('Warning: Reinstalling will temporarily deactivate the plugins. Premium plugins or those from unknown sources cannot be automatically reinstalled.', 'bulk-plugin-installer'); ?>
                                    </p>
                                </div>
                            </div>

                            <div class="bpi-reinstall-content" id="bpi-reinstall-themes">
                                <div class="bpi-reinstall-actions">
                                    <div class="bpi-search-box">
                                        <input type="text" id="theme-search" placeholder="<?php esc_attr_e('Search themes...', 'bulk-plugin-installer'); ?>">
                                    </div>
                                    <div class="bpi-filter-actions">
                                        <label>
                                            <input type="checkbox" id="filter-active-themes" checked>
                                            <?php _e('Active', 'bulk-plugin-installer'); ?>
                                        </label>
                                        <label>
                                            <input type="checkbox" id="filter-inactive-themes" checked>
                                            <?php _e('Inactive', 'bulk-plugin-installer'); ?>
                                        </label>
                                        <label>
                                            <input type="checkbox" id="filter-wp-themes" checked>
                                            <?php _e('WordPress.org', 'bulk-plugin-installer'); ?>
                                        </label>
                                        <label>
                                            <input type="checkbox" id="filter-unknown-themes" checked>
                                            <?php _e('Unknown Source', 'bulk-plugin-installer'); ?>
                                        </label>
                                    </div>
                                    <div class="bpi-selection-actions">
                                        <button type="button" class="button" id="select-all-themes"><?php _e('Select All', 'bulk-plugin-installer'); ?></button>
                                        <button type="button" class="button" id="select-none-themes"><?php _e('Select None', 'bulk-plugin-installer'); ?></button>
                                        <button type="button" class="button" id="select-wp-themes"><?php _e('Select WordPress.org', 'bulk-plugin-installer'); ?></button>
                                    </div>
                                </div>

                                <table class="wp-list-table widefat fixed striped bpi-themes-table">
                                    <thead>
                                        <tr>
                                            <th class="check-column"><input type="checkbox" id="themes-select-all"></th>
                                            <th><?php _e('Theme', 'bulk-plugin-installer'); ?></th>
                                            <th><?php _e('Version', 'bulk-plugin-installer'); ?></th>
                                            <th><?php _e('Status', 'bulk-plugin-installer'); ?></th>
                                            <th><?php _e('Source', 'bulk-plugin-installer'); ?></th>
                                            <th><?php _e('Actions', 'bulk-plugin-installer'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="themes-list">
                                        <tr>
                                            <td colspan="6"><?php _e('Loading themes...', 'bulk-plugin-installer'); ?></td>
                                        </tr>
                                    </tbody>
                                </table>

                                <div class="bpi-bulk-actions">
                                    <button type="button" class="button button-primary" id="reinstall-selected-themes" disabled>
                                        <?php _e('Reinstall Selected Themes', 'bulk-plugin-installer'); ?>
                                    </button>
                                    <p class="bpi-warning">
                                        <span class="dashicons dashicons-warning"></span>
                                        <?php _e('Warning: Reinstalling the active theme may briefly affect your site appearance. Premium themes or those from unknown sources cannot be automatically reinstalled.', 'bulk-plugin-installer'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div id="reinstall-results" class="bpi-results"></div>
                    </div>

                    <div id="collections" class="bpi-tab-content">
                        <h2><?php _e('Preset Collections', 'bulk-plugin-installer'); ?></h2>
                        <p><?php _e('Quickly install curated collections of plugins and themes for specific website types.', 'bulk-plugin-installer'); ?></p>

                        <div class="bpi-collections-wrapper">
                            <div class="bpi-collections-controls">
                                <button type="button" class="button bpi-refresh-collections">
                                    <span class="dashicons dashicons-update"></span> <?php _e('Refresh Collections', 'bulk-plugin-installer'); ?>
                                </button>
                                <div class="bpi-collections-filters">
                                    <select id="collection-category-filter">
                                        <option value=""><?php _e('All Categories', 'bulk-plugin-installer'); ?></option>
                                        <option value="business"><?php _e('Business', 'bulk-plugin-installer'); ?></option>
                                        <option value="ecommerce"><?php _e('E-Commerce', 'bulk-plugin-installer'); ?></option>
                                        <option value="community"><?php _e('Community', 'bulk-plugin-installer'); ?></option>
                                        <option value="content"><?php _e('Content', 'bulk-plugin-installer'); ?></option>
                                    </select>
                                    <select id="collection-level-filter">
                                        <option value=""><?php _e('All Levels', 'bulk-plugin-installer'); ?></option>
                                        <option value="beginner"><?php _e('Beginner', 'bulk-plugin-installer'); ?></option>
                                        <option value="intermediate"><?php _e('Intermediate', 'bulk-plugin-installer'); ?></option>
                                        <option value="advanced"><?php _e('Advanced', 'bulk-plugin-installer'); ?></option>
                                    </select>
                                </div>
                            </div>

                            <div class="bpi-collections-grid">
                                <?php
                                $collections = bpi_get_preset_collections();
                                if (empty($collections['collections'])) {
                                    echo '<div class="bpi-empty-collections">';
                                    echo '<p>' . __('No collections found. Please add collection sources in the settings tab or refresh.', 'bulk-plugin-installer') . '</p>';
                                    echo '</div>';
                                } else {
                                    foreach ($collections['collections'] as $key => $collection) :
                                        $icon = isset($collection['icon']) ? $collection['icon'] : 'dashicons-admin-plugins';
                                        $category = isset($collection['category']) ? $collection['category'] : '';
                                        $level = isset($collection['level']) ? $collection['level'] : '';
                                    ?>
                                        <div class="bpi-collection-card" data-collection="<?php echo esc_attr($key); ?>" data-category="<?php echo esc_attr($category); ?>" data-level="<?php echo esc_attr($level); ?>">
                                            <div class="bpi-collection-icon">
                                                <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                                            </div>
                                            <h3><?php echo esc_html($collection['name']); ?></h3>
                                            <p><?php echo esc_html($collection['description']); ?></p>
                                            <div class="bpi-collection-meta">
                                                <span><?php printf(_n('%s plugin', '%s plugins', bpi_count_collection_items($collection['plugins'] ?? []), 'bulk-plugin-installer'), bpi_count_collection_items($collection['plugins'] ?? [])); ?></span>
                                                <?php if (!empty($collection['themes'])) : ?>
                                                <span><?php printf(_n('%s theme', '%s themes', bpi_count_collection_items($collection['themes'] ?? []), 'bulk-plugin-installer'), bpi_count_collection_items($collection['themes'] ?? [])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="button button-primary bpi-load-collection" data-collection="<?php echo esc_attr($key); ?>">
                                                <?php _e('View & Install', 'bulk-plugin-installer'); ?>
                                            </button>
                                        </div>
                                    <?php endforeach;
                                }
                                ?>
                            </div>

                            <div class="bpi-collection-details" style="display: none;">
                                <button type="button" class="bpi-back-to-collections">
                                    <span class="dashicons dashicons-arrow-left-alt"></span>
                                    <?php _e('Back to Collections', 'bulk-plugin-installer'); ?>
                                </button>

                                <div id="bpi-collection-content"></div>
                            </div>
                        </div>
                    </div>

                    <div id="collection-sources" class="bpi-tab-content">
                        <h2><?php _e('Collection Sources', 'bulk-plugin-installer'); ?></h2>
                        <p><?php _e('Add or manage sources for plugin and theme collections.', 'bulk-plugin-installer'); ?></p>

                        <?php if (!current_user_can('manage_options')): ?>
                            <p><?php _e('You need administrator privileges to modify collection sources.', 'bulk-plugin-installer'); ?></p>
                        <?php else: ?>
                            <table class="wp-list-table widefat fixed striped bpi-sources-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('Name', 'bulk-plugin-installer'); ?></th>
                                        <th><?php _e('URL', 'bulk-plugin-installer'); ?></th>
                                        <th><?php _e('Status', 'bulk-plugin-installer'); ?></th>
                                        <th><?php _e('Actions', 'bulk-plugin-installer'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $sources = get_option('bpi_collection_sources', []);
                                    if (empty($sources)) {
                                        echo '<tr><td colspan="4">' . __('No sources added yet. Add a source below.', 'bulk-plugin-installer') . '</td></tr>';
                                    } else {
                                        foreach ($sources as $index => $source) {
                                            ?>
                                            <tr>
                                                <td><?php echo esc_html($source['name']); ?></td>
                                                <td><a href="<?php echo esc_url($source['url']); ?>" target="_blank"><?php echo esc_url($source['url']); ?></a></td>
                                                <td>
                                                    <label class="bpi-toggle">
                                                        <input type="checkbox" class="bpi-source-toggle" data-id="<?php echo esc_attr($index); ?>" <?php checked(!empty($source['enabled'])); ?>>
                                                        <span class="bpi-toggle-slider"></span>
                                                    </label>
                                                </td>
                                                <td>
                                                    <button type="button" class="button button-small bpi-edit-source" data-id="<?php echo esc_attr($index); ?>" data-name="<?php echo esc_attr($source['name']); ?>" data-url="<?php echo esc_url($source['url']); ?>">
                                                        <?php _e('Edit', 'bulk-plugin-installer'); ?>
                                                    </button>
                                                    <button type="button" class="button button-small bpi-delete-source" data-id="<?php echo esc_attr($index); ?>">
                                                        <?php _e('Delete', 'bulk-plugin-installer'); ?>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    }
                                    ?>
                                </tbody>
                            </table>

                            <div class="bpi-form-row" style="margin-top: 20px;">
                                <h4><?php _e('Add New Source', 'bulk-plugin-installer'); ?></h4>
                                <form id="bpi-add-source-form" class="bpi-form">
                                    <input type="hidden" id="source-id" value="">
                                    <div class="bpi-form-row">
                                        <label for="source-name"><?php _e('Name', 'bulk-plugin-installer'); ?></label>
                                        <input type="text" id="source-name" name="source_name" class="regular-text" placeholder="<?php esc_attr_e('e.g., My Collection Source', 'bulk-plugin-installer'); ?>">
                                    </div>
                                    <div class="bpi-form-row">
                                        <label for="source-url"><?php _e('URL', 'bulk-plugin-installer'); ?></label>
                                        <input type="url" id="source-url" name="source_url" class="regular-text" placeholder="<?php esc_attr_e('https://example.com/collections.json', 'bulk-plugin-installer'); ?>" required>
                                        <p class="description">
                                            <?php _e('Enter the URL to a JSON file with collections data.', 'bulk-plugin-installer'); ?>
                                        </p>
                                    </div>
                                    <div class="bpi-form-row">
                                        <button type="submit" class="button button-primary" id="source-submit-btn">
                                            <?php _e('Add Source', 'bulk-plugin-installer'); ?>
                                        </button>
                                        <button type="button" class="button" id="source-cancel-btn" style="display:none;">
                                            <?php _e('Cancel', 'bulk-plugin-installer'); ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div id="logs" class="bpi-tab-content">
                        <h2><?php _e('Installation Logs', 'bulk-plugin-installer'); ?></h2>
                        <p><?php _e('View logs of plugin and theme installations.', 'bulk-plugin-installer'); ?></p>

                        <?php if (isset($_GET['cleared']) && $_GET['cleared'] == '1'): ?>
                        <div class="notice notice-success">
                            <p><?php _e('Logs have been cleared successfully.', 'bulk-plugin-installer'); ?></p>
                        </div>
                        <?php endif; ?>

                        <div class="bpi-log-filters">
                            <form id="bpi-log-filter-form" method="get">
                                <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>">
                                <input type="hidden" name="tab" value="logs">

                                <div class="bpi-filter-row">
                                    <select name="filter_type">
                                        <option value=""><?php _e('All Types', 'bulk-plugin-installer'); ?></option>
                                        <option value="plugin" <?php selected(isset($_GET['filter_type']) && $_GET['filter_type'] === 'plugin'); ?>><?php _e('Plugins', 'bulk-plugin-installer'); ?></option>
                                        <option value="theme" <?php selected(isset($_GET['filter_type']) && $_GET['filter_type'] === 'theme'); ?>><?php _e('Themes', 'bulk-plugin-installer'); ?></option>
                                    </select>

                                    <select name="filter_status">
                                        <option value=""><?php _e('All Statuses', 'bulk-plugin-installer'); ?></option>
                                        <option value="success" <?php selected(isset($_GET['filter_status']) && $_GET['filter_status'] === 'success'); ?>><?php _e('Success', 'bulk-plugin-installer'); ?></option>
                                        <option value="error" <?php selected(isset($_GET['filter_status']) && $_GET['filter_status'] === 'error'); ?>><?php _e('Error', 'bulk-plugin-installer'); ?></option>
                                        <option value="skipped" <?php selected(isset($_GET['filter_status']) && $_GET['filter_status'] === 'skipped'); ?>><?php _e('Skipped', 'bulk-plugin-installer'); ?></option>
                                    </select>

                                    <input type="date" name="filter_date_from" value="<?php echo isset($_GET['filter_date_from']) ? esc_attr($_GET['filter_date_from']) : ''; ?>" placeholder="<?php esc_attr_e('From Date', 'bulk-plugin-installer'); ?>">

                                    <input type="date" name="filter_date_to" value="<?php echo isset($_GET['filter_date_to']) ? esc_attr($_GET['filter_date_to']) : ''; ?>" placeholder="<?php esc_attr_e('To Date', 'bulk-plugin-installer'); ?>">

                                    <input type="text" name="filter_search" value="<?php echo isset($_GET['filter_search']) ? esc_attr($_GET['filter_search']) : ''; ?>" placeholder="<?php esc_attr_e('Search logs...', 'bulk-plugin-installer'); ?>">

                                    <button type="submit" class="button"><?php _e('Filter', 'bulk-plugin-installer'); ?></button>
                                    <a href="<?php echo esc_url(add_query_arg(['page' => $_GET['page'], 'tab' => 'logs'], admin_url('plugins.php'))); ?>" class="button"><?php _e('Reset', 'bulk-plugin-installer'); ?></a>
                                </div>
                            </form>
                        </div>

                        <?php
                        // Process filters
                        $filters = [];
                        if (isset($_GET['filter_type']) && !empty($_GET['filter_type'])) {
                            $filters['item_type'] = sanitize_text_field($_GET['filter_type']);
                        }

                        if (isset($_GET['filter_status']) && !empty($_GET['filter_status'])) {
                            $filters['status'] = sanitize_text_field($_GET['filter_status']);
                        }

                        if (isset($_GET['filter_date_from']) && !empty($_GET['filter_date_from'])) {
                            $filters['date_from'] = sanitize_text_field($_GET['filter_date_from']);
                        }

                        if (isset($_GET['filter_date_to']) && !empty($_GET['filter_date_to'])) {
                            $filters['date_to'] = sanitize_text_field($_GET['filter_date_to']);
                        }

                        if (isset($_GET['filter_search']) && !empty($_GET['filter_search'])) {
                            $filters['search'] = sanitize_text_field($_GET['filter_search']);
                        }

                        // Get current page
                        $current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;

                        // Get logs
                        $logs_data = bpi_get_logs($filters, 20, $current_page);
                        $logs = $logs_data['logs'];
                        $total_pages = $logs_data['pages'];
                        ?>

                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Time', 'bulk-plugin-installer'); ?></th>
                                    <th><?php _e('Item', 'bulk-plugin-installer'); ?></th>
                                    <th><?php _e('Type', 'bulk-plugin-installer'); ?></th>
                                    <th><?php _e('Action', 'bulk-plugin-installer'); ?></th>
                                    <th><?php _e('Source', 'bulk-plugin-installer'); ?></th>
                                    <th><?php _e('Status', 'bulk-plugin-installer'); ?></th>
                                    <th><?php _e('Message', 'bulk-plugin-installer'); ?></th>
                                    <th><?php _e('User', 'bulk-plugin-installer'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="8"><?php _e('No logs found.', 'bulk-plugin-installer'); ?></td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->log_time))); ?></td>
                                        <td><?php echo esc_html($log->item_name); ?></td>
                                        <td><?php echo esc_html(ucfirst($log->item_type)); ?></td>
                                        <td><?php echo esc_html(ucfirst($log->action)); ?></td>
                                        <td><?php echo esc_html(ucfirst($log->source)); ?></td>
                                        <td>
                                            <span class="bpi-status bpi-status-<?php echo esc_attr($log->status); ?>">
                                                <?php echo esc_html(ucfirst($log->status)); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($log->message); ?></td>
                                        <td>
                                            <?php
                                            $user = get_userdata($log->user_id);
                                            echo $user ? esc_html($user->display_name) : __('Unknown', 'bulk-plugin-installer');
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <?php if ($total_pages > 1): ?>
                        <div class="tablenav bottom">
                            <div class="tablenav-pages">
                                <span class="displaying-num">
                                    <?php printf(_n('%s item', '%s items', $logs_data['total'], 'bulk-plugin-installer'), number_format_i18n($logs_data['total'])); ?>
                                </span>
                                <span class="pagination-links">
                                    <?php
                                    echo paginate_links([
                                        'base' => add_query_arg('paged', '%#%'),
                                        'format' => '',
                                        'prev_text' => '&laquo;',
                                        'next_text' => '&raquo;',
                                        'total' => $total_pages,
                                        'current' => $current_page
                                    ]);
                                    ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="bpi-log-actions">
                            <form id="bpi-log-clear-form" method="post">
                                <?php wp_nonce_field('bpi_clear_logs', 'bpi_logs_nonce'); ?>
                                <input type="hidden" name="action" value="bpi_clear_logs">
                                <button type="submit" class="button button-secondary" onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all logs? This action cannot be undone.', 'bulk-plugin-installer'); ?>');">
                                    <?php _e('Clear All Logs', 'bulk-plugin-installer'); ?>
                                </button>
                            </form>

                            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'bpi_export_logs'], admin_url('admin-post.php')), 'bpi_export_logs', 'bpi_logs_nonce')); ?>" class="button button-secondary">
                                <?php _e('Export Logs (CSV)', 'bulk-plugin-installer'); ?>
                            </a>
                        </div>
                    </div>

                    <div id="settings" class="bpi-tab-content">
                        <h2><?php _e('Settings', 'bulk-plugin-installer'); ?></h2>
                        <p><?php _e('Configure plugin installation settings.', 'bulk-plugin-installer'); ?></p>
                        <span id="settings-status" class="notice" style="display:none;"></span>
                        <?php if (!current_user_can('manage_options')): ?>
                            <p><?php _e('You need administrator privileges to modify these settings.', 'bulk-plugin-installer'); ?></p>
                        <?php else: ?>
                            <form id="bpi-settings-form" class="bpi-form">
                                <?php wp_nonce_field('bpi_installer', 'bpi_nonce'); ?>
                                <div class="bpi-form-row">
                                    <label><?php _e('Allowed Roles', 'bulk-plugin-installer'); ?></label>
                                    <?php
                                    $allowed_roles = get_option('bpi_allowed_roles', BPI_ALLOWED_ROLES);
                                    $roles = wp_roles()->get_names();
                                    foreach ($roles as $role => $label) {
                                        printf(
                                            '<label><input type="checkbox" name="bpi_allowed_roles[]" value="%s" %s> %s</label><br>',
                                            esc_attr($role),
                                            in_array($role, $allowed_roles) ? 'checked' : '',
                                            esc_html($label)
                                        );
                                    }
                                    ?>
                                </div>

                                <div class="bpi-form-row">
                                    <h3><?php _e('Installation Options', 'bulk-plugin-installer'); ?></h3>
                                    <?php
                                    $install_options = get_option('bpi_install_options', [
                                        'duplicate_handling' => 'skip',
                                        'auto_activate' => false,
                                        'keep_backups' => false,
                                    ]);
                                    ?>
                                    <label><?php _e('Duplicate Handling', 'bulk-plugin-installer'); ?></label>
                                    <div class="bpi-radio-group">
                                        <label>
                                            <input type="radio" name="bpi_install_options[duplicate_handling]" value="skip" <?php checked($install_options['duplicate_handling'], 'skip'); ?>>
                                            <?php _e('Skip duplicates (recommended)', 'bulk-plugin-installer'); ?>
                                        </label><br>
                                        <label>
                                            <input type="radio" name="bpi_install_options[duplicate_handling]" value="reinstall" <?php checked($install_options['duplicate_handling'], 'reinstall'); ?>>
                                            <?php _e('Force reinstall', 'bulk-plugin-installer'); ?>
                                        </label><br>
                                        <label>
                                            <input type="radio" name="bpi_install_options[duplicate_handling]" value="error" <?php checked($install_options['duplicate_handling'], 'error'); ?>>
                                            <?php _e('Show error', 'bulk-plugin-installer'); ?>
                                        </label>
                                    </div>
                                </div>
                                <div class="bpi-form-row">
                                    <label>
                                        <input type="checkbox" name="bpi_install_options[auto_activate]" value="1" <?php checked(!empty($install_options['auto_activate'])); ?>>
                                        <?php _e('Automatically activate plugins after installation', 'bulk-plugin-installer'); ?>
                                    </label>
                                </div>
                                <div class="bpi-form-row">
                                    <label>
                                        <input type="checkbox" name="bpi_install_options[keep_backups]" value="1" <?php checked(!empty($install_options['keep_backups'])); ?>>
                                        <?php _e('Keep backups of existing plugins when reinstalling', 'bulk-plugin-installer'); ?>
                                    </label>
                                </div>

                                <div class="bpi-form-row">
                                    <label for="bpi_custom_domains"><?php _e('Additional Trusted Domains', 'bulk-plugin-installer'); ?></label>
                                    <textarea name="bpi_custom_domains" id="bpi_custom_domains" rows="5" class="large-text code"
                                        placeholder="<?php esc_attr_e('Enter one root domain per line (e.g., example.com)', 'bulk-plugin-installer'); ?>"
                                    ><?php echo esc_textarea(get_option('bpi_custom_domains', '')); ?></textarea>
                                    <p class="description">
                                        <?php _e('Enter root domains (one per line) for remote installation. Subdomains are automatically included.', 'bulk-plugin-installer'); ?>
                                    </p>
                                </div>
                                <div class="bpi-form-row">
                                    <button type="submit" class="button button-primary"><?php _e('Save Settings', 'bulk-plugin-installer'); ?></button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="installation-results" class="bpi-results"></div>
            </div>

            <div class="bpi-card">
                <h2><?php _e('Statistics', 'bulk-plugin-installer'); ?></h2>
                <p><?php _e('View statistics of plugin and theme installations.', 'bulk-plugin-installer'); ?></p>
                <?php
                $stats = get_option('bpi_statistics', [
                    'total_installs' => 0,
                    'successful_installs' => 0,
                    'failed_installs' => 0,
                    'last_install_time' => ''
                ]);
                ?>
                <table class="wp-list-table widefat fixed">
                    <thead>
                        <tr>
                            <th><?php _e('Metric', 'bulk-plugin-installer'); ?></th>
                            <th><?php _e('Value', 'bulk-plugin-installer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th><?php _e('Total Installs', 'bulk-plugin-installer'); ?></th>
                            <td><?php echo esc_html($stats['total_installs']); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Successful Installs', 'bulk-plugin-installer'); ?></th>
                            <td><?php echo esc_html($stats['successful_installs']); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Failed Installs', 'bulk-plugin-installer'); ?></th>
                            <td><?php echo esc_html($stats['failed_installs']); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Last Install Time', 'bulk-plugin-installer'); ?></th>
                            <td><?php echo esc_html($stats['last_install_time'] ?: __('Never Installed', 'bulk-plugin-installer')); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}
