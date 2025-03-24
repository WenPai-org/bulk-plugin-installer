<?php
function bpi_render_admin_page() {
    if (!bpi_user_can_install()) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'bulk-plugin-installer'));
    }

    wp_enqueue_style('bpi-admin-style', BPI_URL . 'assets/css/admin.css', [], BPI_VERSION);
    wp_enqueue_script('bpi-admin', BPI_URL . 'assets/js/admin.js', ['jquery'], BPI_VERSION, true);
    wp_localize_script('bpi-admin', 'bpiAjax', [
        'nonce' => wp_create_nonce('bpi_installer'),
        'ajaxurl' => admin_url('admin-ajax.php')
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
