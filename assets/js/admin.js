/**
 * Admin JavaScript functionality
 */
jQuery(document).ready(function($) {
    const $results = $('#installation-results');

    // Handle URL parameter for tab selection
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab');
    if (tab) {
        $('.bpi-tab').removeClass('active');
        $('.bpi-tab[data-tab="' + tab + '"]').addClass('active');
        $('.bpi-tab-content').removeClass('active').hide();
        $('#' + tab).addClass('active').show();
    }

    // Tab navigation
    $('.bpi-tab').on('click', function() {
        $('.bpi-tab').removeClass('active');
        $(this).addClass('active');

        var tab = $(this).data('tab');
        $('.bpi-tab-content').removeClass('active').hide();
        $('#' + tab).addClass('active').show();

        // Update URL without page reload
        const url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.pushState({}, '', url);
    });

    // Installation source selection
    $('.bpi-select').on('change', function() {
        const $form = $(this).closest('.bpi-form');
        const selectedType = $(this).val();

        $form.find('.source-input').removeClass('active').hide();
        $form.find('textarea[name="items"]').val('');
        $form.find('input[type="file"]').val('');
        $form.find('.selected-files').empty();

        $form.find('.' + selectedType + '-source').addClass('active').show();
    });

    // File upload handling
    $('.file-upload-container').each(function() {
        const $container = $(this);
        const $fileInput = $container.find('input[type="file"]');
        const $selectedFiles = $container.find('.selected-files');

        $container.on('click', function(e) {
            if (e.target === this || $(e.target).hasClass('upload-instructions')) {
                $fileInput.trigger('click');
            }
        });

        $container.on('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('dragover');
        });

        $container.on('dragleave drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');
        });

        $container.on('drop', function(e) {
            const files = e.originalEvent.dataTransfer.files;
            $fileInput[0].files = files;
            handleFiles(files);
        });

        $fileInput.on('change', function() {
            handleFiles(this.files);
        });

        function handleFiles(files) {
            $selectedFiles.empty();
            Array.from(files).forEach(file => {
                if (file.type === 'application/zip' || file.name.endsWith('.zip')) {
                    const $fileElement = $(`
                        <div class="selected-file">
                            <span class="filename">${escapeHtml(file.name)}</span>
                            <span class="remove-file dashicons dashicons-no-alt"></span>
                        </div>
                    `);
                    $selectedFiles.append($fileElement);
                }
            });
        }

        $selectedFiles.on('click', '.remove-file', function() {
            $(this).closest('.selected-file').remove();
            if ($selectedFiles.children().length === 0) {
                $fileInput.val('');
            }
        });
    });

    // Plugin/Theme installation form submission
    $('#bulk-plugin-form, #bulk-theme-form').on('submit', function(e) {
        e.preventDefault();
        const $form = $(this);
        const action = $form.attr('id') === 'bulk-plugin-form' ? 'bpi_install_plugins' : 'bpi_install_themes';
        const type = $form.find('.bpi-select').val();
        const $submitButton = $form.find('button[type="submit"]');

        let items = [];
        let errorMessage = '';

        if (type === 'upload') {
            const $fileInput = $form.find('input[type="file"]');
            const files = $fileInput[0].files;
            if (!files || files.length === 0) {
                errorMessage = bpiAjax.i18n.no_files;
            } else {
                items = Array.from(files).map(file => file.name);
            }
        } else {
            const $textarea = $form.find('.' + type + '-source textarea[name="items"]');
            items = $textarea.val().split('\n')
                .map(item => item.trim())
                .filter(item => item.length > 0);
            if (items.length === 0) {
                errorMessage = (type === 'repository' || type === 'wenpai') ?
                    bpiAjax.i18n.no_slugs :
                    bpiAjax.i18n.no_urls;
            }
        }

        if (errorMessage) {
            alert(errorMessage);
            return;
        }

        $submitButton.prop('disabled', true).text('Installing...');
        $results.html(`<div class="notice notice-info">
            <p>${bpiAjax.i18n.installation_in_progress}</p>
            <div class="progress-bar-container">
                <div class="progress-bar" style="width: 0%;">0%</div>
            </div>
            <div class="progress-count">0/${items.length} ${bpiAjax.i18n.completed} (0% done, ${items.length} ${bpiAjax.i18n.remaining})</div>
            <ul class="installation-list"></ul>
        </div>`);

        const $list = $results.find('.installation-list');
        const $progress = $results.find('.progress-count');
        const $progressBar = $results.find('.progress-bar');
        let completed = 0;

        if (type === 'upload') {
            const formData = new FormData($form[0]);
            formData.append('action', action);
            formData.append('nonce', bpiAjax.nonce);
            formData.append('install_type', type);

            $.ajax({
                url: bpiAjax.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        Object.keys(response.data).forEach((item, index) => {
                            handleResponse(response, item, index);
                        });
                    } else {
                        $list.append(`<li><span class="item-name">${escapeHtml('Upload Error')}</span><span class="status error">✗ ${escapeHtml(response.data || 'Unknown upload error')}</span></li>`);
                    }
                    installationComplete();
                },
                error: function(xhr, status, error) {
                    $list.append(`<li><span class="item-name">${escapeHtml('Upload Error')}</span><span class="status error">✗ ${escapeHtml(xhr.responseText || error)}</span></li>`);
                    installationComplete();
                }
            });
        } else {
            processNextItem(0);
        }

        function processNextItem(index) {
            if (index >= items.length) {
                installationComplete();
                return;
            }

            const item = items[index];
            $list.append(`<li id="item-${index}"><span class="spinner is-active"></span><span class="item-name">${escapeHtml(item)}</span><span class="status"></span></li>`);

            $.ajax({
                url: bpiAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: action,
                    nonce: bpiAjax.nonce,
                    items: JSON.stringify([item]),
                    install_type: type
                },
                success: function(response) {
                    handleResponse(response, item, index);
                    processNextItem(index + 1);
                },
                error: function(xhr, status, error) {
                    handleError(xhr, status, error, item, index);
                    processNextItem(index + 1);
                }
            });
        }

        function handleResponse(response, item, index) {
            const $item = $(`#item-${index}`) || $list.find('li:last');
            $item.find('.spinner').removeClass('is-active');

            if (response.success && response.data[item]) {
                const result = response.data[item];
                $item.addClass(result.success ? 'success' : 'error');
                let statusHtml = '';
                if (result.success) {
                    statusHtml = result.skipped ? 'ⓘ ' + escapeHtml(result.message) : '✓ ' + escapeHtml(result.message);
                } else {
                    statusHtml = '✗ ' + escapeHtml(result.message);
                    if (result.retry) {
                        statusHtml += ' <button class="retry-btn" data-item="' + escapeHtml(item) + '" data-type="' + type + '">' + bpiAjax.i18n.retry + '</button>';
                    }
                }
                $item.find('.status').html(statusHtml);
            } else {
                $item.addClass('error')
                    .find('.status')
                    .html('✗ ' + escapeHtml(response.data || 'Unknown error') + ' <button class="retry-btn" data-item="' + escapeHtml(item) + '" data-type="' + type + '">' + bpiAjax.i18n.retry + '</button>');
            }

            updateProgress();
        }

        function handleError(xhr, status, error, item, index) {
            const $item = $(`#item-${index}`) || $list.find('li:last');
            $item.find('.spinner').removeClass('is-active');
            $item.addClass('error')
                .find('.status')
                .html(`✗ ${escapeHtml(xhr.responseText || 'Installation failed: ' + error)} <button class="retry-btn" data-item="${escapeHtml(item)}" data-type="${type}">${bpiAjax.i18n.retry}</button>`);

            updateProgress();
        }

        function updateProgress() {
            completed++;
            const percentage = Math.round((completed / items.length) * 100);
            const remaining = items.length - completed;
            $progress.text(`${completed}/${items.length} ${bpiAjax.i18n.completed} (${percentage}% done, ${remaining} ${bpiAjax.i18n.remaining})`);
            $progressBar.css('width', percentage + '%').text(percentage + '%');
        }

        function installationComplete() {
            $submitButton.prop('disabled', false).text(`Install ${action === 'bpi_install_plugins' ? 'Plugins' : 'Themes'}`);
            const $notice = $results.find('.notice').removeClass('notice-info').addClass('notice-success');
            $notice.find('p').html(bpiAjax.i18n.installation_complete);
            $progressBar.css('width', '100%').text('100%');
        }
    });

    // Retry button handler
    $results.on('click', '.retry-btn', function() {
        const $button = $(this);
        const item = $button.data('item');
        const type = $button.data('type');
        const action = $('#bulk-plugin-form').is(':visible') ? 'bpi_install_plugins' : 'bpi_install_themes';
        const $li = $button.closest('li');

        $li.find('.status').html('<span class="spinner is-active"></span>');

        $.ajax({
            url: bpiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: action,
                nonce: bpiAjax.nonce,
                items: JSON.stringify([item]),
                install_type: type
            },
            success: function(response) {
                $li.find('.spinner').removeClass('is-active');
                if (response.success && response.data[item]) {
                    const result = response.data[item];
                    $li.removeClass('error success').addClass(result.success ? 'success' : 'error');
                    let statusHtml = '';
                    if (result.success) {
                        statusHtml = result.skipped ? 'ⓘ ' + escapeHtml(result.message) : '✓ ' + escapeHtml(result.message);
                    } else {
                        statusHtml = '✗ ' + escapeHtml(result.message);
                        if (result.retry) {
                            statusHtml += ' <button class="retry-btn" data-item="' + escapeHtml(item) + '" data-type="' + type + '">' + bpiAjax.i18n.retry + '</button>';
                        }
                    }
                    $li.find('.status').html(statusHtml);
                } else {
                    $li.addClass('error')
                        .find('.status')
                        .html('✗ ' + escapeHtml(response.data || 'Unknown error') + ' <button class="retry-btn" data-item="' + escapeHtml(item) + '" data-type="' + type + '">' + bpiAjax.i18n.retry + '</button>');
                }
            },
            error: function(xhr, status, error) {
                $li.find('.spinner').removeClass('is-active');
                $li.addClass('error')
                    .find('.status')
                    .html(`✗ ${escapeHtml(xhr.responseText || 'Retry failed: ' + error)} <button class="retry-btn" data-item="${escapeHtml(item)}" data-type="${type}">${bpiAjax.i18n.retry}</button>`);
            }
        });
    });

    // Settings form submission
    $('#bpi-settings-form').on('submit', function(e) {
        e.preventDefault();
        const $form = $(this);
        const $submitButton = $form.find('button[type="submit"]');
        const $status = $('#settings-status');

        $submitButton.prop('disabled', true).text('Saving...');
        $status.removeClass('notice-success notice-error').addClass('notice-info').text('Saving...').show();

        // Get installation options
        const installOptions = {
            duplicate_handling: $form.find('input[name="bpi_install_options[duplicate_handling]"]:checked').val(),
            auto_activate: $form.find('input[name="bpi_install_options[auto_activate]"]').is(':checked') ? 1 : 0,
            keep_backups: $form.find('input[name="bpi_install_options[keep_backups]"]').is(':checked') ? 1 : 0
        };

        $.ajax({
            url: bpiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'bpi_save_settings',
                nonce: bpiAjax.nonce,
                bpi_allowed_roles: $form.find('input[name="bpi_allowed_roles[]"]:checked').map(function() { return this.value; }).get(),
                bpi_custom_domains: $form.find('textarea[name="bpi_custom_domains"]').val(),
                bpi_install_options: installOptions
            },
            success: function(response) {
                if (response.success) {
                    $status.removeClass('notice-info').addClass('notice-success').text(response.data || 'Settings saved successfully!').show().delay(3000).fadeOut();
                } else {
                    $status.removeClass('notice-info').addClass('notice-error').text(response.data || 'Failed to save settings.').show();
                }
                $submitButton.prop('disabled', false).text('Save Settings');
            },
            error: function(xhr, status, error) {
                $status.removeClass('notice-info').addClass('notice-error').text('An error occurred while saving settings: ' + (xhr.responseText || error)).show();
                $submitButton.prop('disabled', false).text('Save Settings');
            }
        });
    });

    // Collection view handlers
    $(document).on('click', '.bpi-load-collection', function() {
        const collectionId = $(this).data('collection');

        // Show loading animation
        $('.bpi-collections-wrapper').addClass('loading');

        // AJAX get collection details
        $.ajax({
            url: bpiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'bpi_get_collection_details',
                nonce: bpiAjax.nonce,
                collection_id: collectionId
            },
            success: function(response) {
                if (response.success) {
                    // Fill collection details content
                    $('#bpi-collection-content').html(response.data.html);

                    // Show details view and hide list view
                    $('.bpi-collections-grid').hide();
                    $('.bpi-collections-controls').hide();
                    $('.bpi-collection-details').show();
                } else {
                    alert(response.data.message || 'Failed to load collection');
                }
                $('.bpi-collections-wrapper').removeClass('loading');
            },
            error: function() {
                alert('An error occurred while loading the collection');
                $('.bpi-collections-wrapper').removeClass('loading');
            }
        });
    });

    // Return to collection list
    $(document).on('click', '.bpi-back-to-collections', function() {
        $('.bpi-collection-details').hide();
        $('.bpi-collections-grid').show();
        $('.bpi-collections-controls').show();
    });

    // Install collection
    $(document).on('click', '.bpi-install-collection', function() {
        const collectionId = $(this).data('collection');

        if (!confirm(bpiAjax.i18n.confirm_install_collection)) {
            return;
        }

        const $button = $(this);
        $button.prop('disabled', true).text(bpiAjax.i18n.installing);

        $.ajax({
            url: bpiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'bpi_install_collection',
                nonce: bpiAjax.nonce,
                collection_id: collectionId
            },
            success: function(response) {
                if (response.success) {
                    $('#installation-results').html(response.data.html);
                    $('html, body').animate({
                        scrollTop: $('#installation-results').offset().top
                    }, 500);
                } else {
                    alert(response.data.message || 'Failed to install collection');
                }
                $button.prop('disabled', false).text(bpiAjax.i18n.install_collection);
            },
            error: function() {
                alert('An error occurred during installation');
                $button.prop('disabled', false).text(bpiAjax.i18n.install_collection);
            }
        });
    });

    // Collection filtering
    $('#collection-category-filter, #collection-level-filter').on('change', function() {
        filterCollections();
    });

    function filterCollections() {
        const category = $('#collection-category-filter').val();
        const level = $('#collection-level-filter').val();

        $('.bpi-collection-card').each(function() {
            const $card = $(this);
            const cardCategory = $card.data('category');
            const cardLevel = $card.data('level');

            let show = true;

            if (category && cardCategory !== category) {
                show = false;
            }

            if (level && cardLevel !== level) {
                show = false;
            }

            $card.toggle(show);
        });

        // Show message if no results
        if ($('.bpi-collection-card:visible').length === 0) {
            if ($('.bpi-empty-collections').length === 0) {
                $('.bpi-collections-grid').append('<div class="bpi-empty-collections"><p>' +
                    'No collections match your selected filters. Please try different filter options.' +
                    '</p></div>');
            }
        } else {
            $('.bpi-empty-collections').remove();
        }
    }

    // Refresh collections
    $('.bpi-refresh-collections').on('click', function() {
        const $button = $(this);
        $button.prop('disabled', true);
        $('.bpi-collections-wrapper').addClass('loading');

        $.ajax({
            url: bpiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'bpi_refresh_collections',
                nonce: bpiAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert(response.data.message || 'Failed to refresh collections');
                    $button.prop('disabled', false);
                    $('.bpi-collections-wrapper').removeClass('loading');
                }
            },
            error: function() {
                alert('An error occurred while refreshing collections');
                $button.prop('disabled', false);
                $('.bpi-collections-wrapper').removeClass('loading');
            }
        });
    });

    // Collection source management
    $('#bpi-add-source-form').on('submit', function(e) {
        e.preventDefault();
        const $form = $(this);
        const $submitBtn = $('#source-submit-btn');
        const $nameInput = $('#source-name');
        const $urlInput = $('#source-url');
        const sourceId = $('#source-id').val();

        $submitBtn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: bpiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'bpi_save_remote_source',
                nonce: bpiAjax.nonce,
                name: $nameInput.val(),
                url: $urlInput.val(),
                id: sourceId
            },
            success: function(response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert(response.data.message || 'Failed to save source');
                    $submitBtn.prop('disabled', false).text(sourceId ? 'Update Source' : 'Add Source');
                }
            },
            error: function() {
                alert('An error occurred while saving the source');
                $submitBtn.prop('disabled', false).text(sourceId ? 'Update Source' : 'Add Source');
            }
        });
    });

    // Edit source
    $('.bpi-edit-source').on('click', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        const url = $(this).data('url');

        $('#source-id').val(id);
        $('#source-name').val(name);
        $('#source-url').val(url);
        $('#source-submit-btn').text('Update Source');
        $('#source-cancel-btn').show();

        $('html, body').animate({
            scrollTop: $('#bpi-add-source-form').offset().top
        }, 500);
    });

    // Cancel edit
    $('#source-cancel-btn').on('click', function() {
        $('#source-id').val('');
        $('#source-name').val('');
        $('#source-url').val('');
        $('#source-submit-btn').text('Add Source');
        $(this).hide();
    });

    // Delete source
    $('.bpi-delete-source').on('click', function() {
        if (!confirm(bpiAjax.i18n.confirm_delete_source)) {
            return;
        }

        const id = $(this).data('id');
        const $row = $(this).closest('tr');

        $.ajax({
            url: bpiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'bpi_delete_remote_source',
                nonce: bpiAjax.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(400, function() {
                        $(this).remove();
                        if ($('.bpi-sources-table tbody tr').length === 0) {
                            $('.bpi-sources-table tbody').html('<tr><td colspan="4">' +
                                'No sources added yet. Add a source below.' +
                                '</td></tr>');
                        }
                    });
                } else {
                    alert(response.data.message || 'Failed to delete source');
                }
            },
            error: function() {
                alert('An error occurred while deleting the source');
            }
        });
    });

    // Toggle source enabled/disabled
    $('.bpi-source-toggle').on('change', function() {
        const id = $(this).data('id');
        const enabled = $(this).prop('checked');

        $.ajax({
            url: bpiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'bpi_toggle_remote_source',
                nonce: bpiAjax.nonce,
                id: id,
                enabled: enabled
            },
            success: function(response) {
                if (!response.success) {
                    alert(response.data.message || 'Failed to update source status');
                }
            },
            error: function() {
                alert('An error occurred while updating the source');
            }
        });
    });

    // Helper function to escape HTML
    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Reinstall tab functionality
    (function() {
        // Only run if the reinstall tab exists
        if ($('#reinstall').length === 0) return;

        // Tab switching within reinstall tab
        $('.bpi-reinstall-tab').on('click', function() {
            $('.bpi-reinstall-tab').removeClass('active');
            $(this).addClass('active');

            const contentId = $(this).data('content');
            $('.bpi-reinstall-content').removeClass('active');
            $(`#bpi-reinstall-${contentId}`).addClass('active');
        });

        // Load plugins and themes on tab activation
        $('.bpi-tab[data-tab="reinstall"]').on('click', function() {
            loadPlugins();
            loadThemes();
        });

        // Also load on page load if reinstall tab is active
        if ($('.bpi-tab[data-tab="reinstall"]').hasClass('active')) {
            loadPlugins();
            loadThemes();
        }

        // Function to load installed plugins
        function loadPlugins() {
            const $pluginsList = $('#plugins-list');
            $pluginsList.html('<tr><td colspan="6">' +
                '<span class="spinner is-active"></span> ' +
                'Loading plugins...</td></tr>');

            $.ajax({
                url: bpiAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bpi_get_plugins_list',
                    nonce: bpiAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        renderPlugins(response.data);
                    } else {
                        $pluginsList.html('<tr><td colspan="6">' +
                            'Error loading plugins: ' + (response.data || 'Unknown error') +
                            '</td></tr>');
                    }
                },
                error: function() {
                    $pluginsList.html('<tr><td colspan="6">' +
                        'Failed to load plugins. Please refresh the page and try again.' +
                        '</td></tr>');
                }
            });
        }

        // Function to load installed themes
        function loadThemes() {
            const $themesList = $('#themes-list');
            $themesList.html('<tr><td colspan="6">' +
                '<span class="spinner is-active"></span> ' +
                'Loading themes...</td></tr>');

            $.ajax({
                url: bpiAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bpi_get_themes_list',
                    nonce: bpiAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        renderThemes(response.data);
                    } else {
                        $themesList.html('<tr><td colspan="6">' +
                            'Error loading themes: ' + (response.data || 'Unknown error') +
                            '</td></tr>');
                    }
                },
                error: function() {
                    $themesList.html('<tr><td colspan="6">' +
                        'Failed to load themes. Please refresh the page and try again.' +
                        '</td></tr>');
                }
            });
        }

        // Render plugins list
        function renderPlugins(plugins) {
            if (!plugins || plugins.length === 0) {
                $('#plugins-list').html('<tr><td colspan="6">No plugins found.</td></tr>');
                return;
            }

            let html = '';
            plugins.forEach(function(plugin) {
                const sourceClass = plugin.source === 'repository' ? 'source-wp' :
                                  (plugin.source === 'wenpai' ? 'source-wenpai' : 'source-unknown');
                const statusClass = plugin.status === 'active' ? 'status-active' : 'status-inactive';
                const canReinstall = plugin.source === 'repository' || plugin.source === 'wenpai';

                html += `<tr data-slug="${escapeHtml(plugin.slug)}" data-path="${escapeHtml(plugin.path)}" ` +
                       `data-source="${escapeHtml(plugin.source)}" data-status="${escapeHtml(plugin.status)}">
                    <td><input type="checkbox" class="plugin-select" ${canReinstall ? '' : 'disabled'}></td>
                    <td>
                        <strong>${escapeHtml(plugin.name)}</strong>
                        <div class="plugin-description">${escapeHtml(plugin.description || '')}</div>
                    </td>
                    <td>${escapeHtml(plugin.version)}</td>
                    <td><span class="bpi-status ${statusClass}">${escapeHtml(plugin.status)}</span></td>
                    <td><span class="bpi-source ${sourceClass}">${escapeHtml(plugin.source)}</span></td>
                    <td>
                        ${canReinstall ?
                            `<button type="button" class="button button-small reinstall-single-plugin">
                                ${bpiAjax.i18n.reinstall || 'Reinstall'}
                            </button>` :
                            `<span class="bpi-manual-note">${bpiAjax.i18n.manual_reinstall || 'Manual reinstall required'}</span>`
                        }
                    </td>
                </tr>`;
            });

            $('#plugins-list').html(html);
            updatePluginSelectionCount();
        }

        // Render themes list
        function renderThemes(themes) {
            if (!themes || themes.length === 0) {
                $('#themes-list').html('<tr><td colspan="6">No themes found.</td></tr>');
                return;
            }

            let html = '';
            themes.forEach(function(theme) {
                const sourceClass = theme.source === 'repository' ? 'source-wp' :
                                  (theme.source === 'wenpai' ? 'source-wenpai' : 'source-unknown');
                const statusClass = theme.status === 'active' ? 'status-active' : 'status-inactive';
                const canReinstall = theme.source === 'repository' || theme.source === 'wenpai';

                html += `<tr data-slug="${escapeHtml(theme.slug)}" data-source="${escapeHtml(theme.source)}" data-status="${escapeHtml(theme.status)}">
                    <td><input type="checkbox" class="theme-select" ${canReinstall ? '' : 'disabled'}></td>
                    <td>
                        <strong>${escapeHtml(theme.name)}</strong>
                        <div class="theme-description">${escapeHtml(theme.description || '')}</div>
                    </td>
                    <td>${escapeHtml(theme.version)}</td>
                    <td><span class="bpi-status ${statusClass}">${escapeHtml(theme.status)}</span></td>
                    <td><span class="bpi-source ${sourceClass}">${escapeHtml(theme.source)}</span></td>
                    <td>
                        ${canReinstall ?
                            `<button type="button" class="button button-small reinstall-single-theme">
                                ${bpiAjax.i18n.reinstall || 'Reinstall'}
                            </button>` :
                            `<span class="bpi-manual-note">${bpiAjax.i18n.manual_reinstall || 'Manual reinstall required'}</span>`
                        }
                    </td>
                </tr>`;
            });

            $('#themes-list').html(html);
            updateThemeSelectionCount();
        }

        // Plugin selection handlers
        $('#plugins-list').on('change', '.plugin-select', function() {
            updatePluginSelectionCount();
        });

        $('#plugins-select-all').on('change', function() {
            const isChecked = $(this).is(':checked');
            $('#plugins-list .plugin-select:not(:disabled)').prop('checked', isChecked);
            updatePluginSelectionCount();
        });

        $('#select-all-plugins').on('click', function() {
            $('#plugins-list .plugin-select:not(:disabled)').prop('checked', true);
            updatePluginSelectionCount();
        });

        $('#select-none-plugins').on('click', function() {
            $('#plugins-list .plugin-select').prop('checked', false);
            updatePluginSelectionCount();
        });

        $('#select-wp-plugins').on('click', function() {
            $('#plugins-list tr').each(function() {
                const source = $(this).data('source');
                if (source === 'repository' || source === 'wenpai') {
                    $(this).find('.plugin-select').prop('checked', true);
                } else {
                    $(this).find('.plugin-select').prop('checked', false);
                }
            });
            updatePluginSelectionCount();
        });

        // Theme selection handlers
        $('#themes-list').on('change', '.theme-select', function() {
            updateThemeSelectionCount();
        });

        $('#themes-select-all').on('change', function() {
            const isChecked = $(this).is(':checked');
            $('#themes-list .theme-select:not(:disabled)').prop('checked', isChecked);
            updateThemeSelectionCount();
        });

        $('#select-all-themes').on('click', function() {
            $('#themes-list .theme-select:not(:disabled)').prop('checked', true);
            updateThemeSelectionCount();
        });

        $('#select-none-themes').on('click', function() {
            $('#themes-list .theme-select').prop('checked', false);
            updateThemeSelectionCount();
        });

        $('#select-wp-themes').on('click', function() {
            $('#themes-list tr').each(function() {
                const source = $(this).data('source');
                if (source === 'repository' || source === 'wenpai') {
                    $(this).find('.theme-select').prop('checked', true);
                } else {
                    $(this).find('.theme-select').prop('checked', false);
                }
            });
            updateThemeSelectionCount();
        });

        // Update plugin selection count and button state
        function updatePluginSelectionCount() {
            const selectedCount = $('#plugins-list .plugin-select:checked').length;
            $('#reinstall-selected-plugins').prop('disabled', selectedCount === 0);
            $('#reinstall-selected-plugins').text(
                selectedCount > 0 ?
                `Reinstall Selected Plugins (${selectedCount})` :
                'Reinstall Selected Plugins'
            );
        }

        // Update theme selection count and button state
        function updateThemeSelectionCount() {
            const selectedCount = $('#themes-list .theme-select:checked').length;
            $('#reinstall-selected-themes').prop('disabled', selectedCount === 0);
            $('#reinstall-selected-themes').text(
                selectedCount > 0 ?
                `Reinstall Selected Themes (${selectedCount})` :
                'Reinstall Selected Themes'
            );
        }

        // Plugin search functionality
        $('#plugin-search').on('keyup', function() {
            const searchTerm = $(this).val().toLowerCase();
            $('#plugins-list tr').each(function() {
                const pluginName = $(this).find('td:nth-child(2) strong').text().toLowerCase();
                const pluginDesc = $(this).find('.plugin-description').text().toLowerCase();

                if (pluginName.includes(searchTerm) || pluginDesc.includes(searchTerm)) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        // Theme search functionality
        $('#theme-search').on('keyup', function() {
            const searchTerm = $(this).val().toLowerCase();
            $('#themes-list tr').each(function() {
                const themeName = $(this).find('td:nth-child(2) strong').text().toLowerCase();
                const themeDesc = $(this).find('.theme-description').text().toLowerCase();

                if (themeName.includes(searchTerm) || themeDesc.includes(searchTerm)) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        // Plugin filters
        $('#filter-active-plugins, #filter-inactive-plugins, #filter-wp-plugins, #filter-unknown-plugins').on('change', function() {
            const showActive = $('#filter-active-plugins').is(':checked');
            const showInactive = $('#filter-inactive-plugins').is(':checked');
            const showWordPress = $('#filter-wp-plugins').is(':checked');
            const showUnknown = $('#filter-unknown-plugins').is(':checked');

            $('#plugins-list tr').each(function() {
                const status = $(this).data('status');
                const source = $(this).data('source');

                const showByStatus = (status === 'active' && showActive) ||
                                    (status === 'inactive' && showInactive);

                const showBySource = (source === 'repository' && showWordPress) ||
                                   (source === 'wenpai' && showWordPress) ||
                                   (source !== 'repository' && source !== 'wenpai' && showUnknown);

                if (showByStatus && showBySource) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        // Theme filters
        $('#filter-active-themes, #filter-inactive-themes, #filter-wp-themes, #filter-unknown-themes').on('change', function() {
            const showActive = $('#filter-active-themes').is(':checked');
            const showInactive = $('#filter-inactive-themes').is(':checked');
            const showWordPress = $('#filter-wp-themes').is(':checked');
            const showUnknown = $('#filter-unknown-themes').is(':checked');

            $('#themes-list tr').each(function() {
                const status = $(this).data('status');
                const source = $(this).data('source');

                const showByStatus = (status === 'active' && showActive) ||
                                    (status === 'inactive' && showInactive);

                const showBySource = (source === 'repository' && showWordPress) ||
                                   (source === 'wenpai' && showWordPress) ||
                                   (source !== 'repository' && source !== 'wenpai' && showUnknown);

                if (showByStatus && showBySource) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        // Reinstall single plugin button
        $('#plugins-list').on('click', '.reinstall-single-plugin', function() {
            const $row = $(this).closest('tr');
            const plugin = {
                slug: $row.data('slug'),
                path: $row.data('path'),
                source: $row.data('source')
            };

            if (!confirm(`Are you sure you want to reinstall the plugin "${$row.find('td:nth-child(2) strong').text()}"?`)) {
                return;
            }

            reinstallPlugins([plugin]);
        });

        // Reinstall selected plugins button
        $('#reinstall-selected-plugins').on('click', function() {
            const selectedPlugins = [];
            $('#plugins-list .plugin-select:checked').each(function() {
                const $row = $(this).closest('tr');
                selectedPlugins.push({
                    slug: $row.data('slug'),
                    path: $row.data('path'),
                    source: $row.data('source')
                });
            });

            if (selectedPlugins.length === 0) {
                return;
            }

            if (!confirm(`Are you sure you want to reinstall ${selectedPlugins.length} selected plugins?`)) {
                return;
            }

            reinstallPlugins(selectedPlugins);
        });

        // Reinstall single theme button
        $('#themes-list').on('click', '.reinstall-single-theme', function() {
            const $row = $(this).closest('tr');
            const theme = {
                slug: $row.data('slug'),
                source: $row.data('source')
            };

            if (!confirm(`Are you sure you want to reinstall the theme "${$row.find('td:nth-child(2) strong').text()}"?`)) {
                return;
            }

            reinstallThemes([theme]);
        });

        // Reinstall selected themes button
        $('#reinstall-selected-themes').on('click', function() {
            const selectedThemes = [];
            $('#themes-list .theme-select:checked').each(function() {
                const $row = $(this).closest('tr');
                selectedThemes.push({
                    slug: $row.data('slug'),
                    source: $row.data('source')
                });
            });

            if (selectedThemes.length === 0) {
                return;
            }

            if (!confirm(`Are you sure you want to reinstall ${selectedThemes.length} selected themes?`)) {
                return;
            }

            reinstallThemes(selectedThemes);
        });

        // Function to reinstall plugins
        function reinstallPlugins(plugins) {
            const $results = $('#reinstall-results');
            $results.html(`<div class="notice notice-info">
                <p>Reinstalling ${plugins.length} plugins...</p>
                <div class="progress-bar-container">
                    <div class="progress-bar" style="width: 0%;">0%</div>
                </div>
                <div class="progress-count">0/${plugins.length} ${bpiAjax.i18n.completed || 'completed'} (0% done, ${plugins.length} ${bpiAjax.i18n.remaining || 'remaining'})</div>
                <ul class="installation-list"></ul>
            </div>`);

            const $list = $results.find('.installation-list');
            const $progress = $results.find('.progress-count');
            const $progressBar = $results.find('.progress-bar');

            $.ajax({
                url: bpiAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bpi_reinstall_plugins',
                    nonce: bpiAjax.nonce,
                    items: JSON.stringify(plugins)
                },
                success: function(response) {
                    if (response.success) {
                        const results = response.data;
                        $list.empty();

                        Object.keys(results).forEach(function(key) {
                            const result = results[key];
                            let itemName = key;

                            // Try to find the plugin name from the DOM
                            $('#plugins-list tr').each(function() {
                                if ($(this).data('path') === key || $(this).data('slug') === key) {
                                    itemName = $(this).find('td:nth-child(2) strong').text();
                                }
                            });

                            $list.append(`<li class="${result.success ? 'success' : 'error'}">
                                <span class="item-name">${escapeHtml(itemName)}</span>
                                <span class="status ${result.success ? 'success' : 'error'}">
                                    ${result.success ? '✓ ' : '✗ '}${escapeHtml(result.message)}
                                </span>
                            </li>`);
                        });

                        $progressBar.css('width', '100%').text('100%');
                        $progress.text(`${plugins.length}/${plugins.length} ${bpiAjax.i18n.completed || 'completed'} (100% done, 0 ${bpiAjax.i18n.remaining || 'remaining'})`);
                        $results.find('.notice').removeClass('notice-info').addClass('notice-success')
                            .find('p').text('Reinstallation completed! Check the results below.');

                        // Refresh the plugins list
                        loadPlugins();
                    } else {
                        $results.html(`<div class="notice notice-error">
                            <p>Error: ${escapeHtml(response.data.message || 'Unknown error')}</p>
                        </div>`);
                    }
                },
                error: function(xhr, status, error) {
                    $results.html(`<div class="notice notice-error">
                        <p>Ajax Error: ${escapeHtml(error || 'Unknown error')}</p>
                    </div>`);
                }
            });
        }

        // Function to reinstall themes
        function reinstallThemes(themes) {
            const $results = $('#reinstall-results');
            $results.html(`<div class="notice notice-info">
                <p>Reinstalling ${themes.length} themes...</p>
                <div class="progress-bar-container">
                    <div class="progress-bar" style="width: 0%;">0%</div>
                </div>
                <div class="progress-count">0/${themes.length} ${bpiAjax.i18n.completed || 'completed'} (0% done, ${themes.length} ${bpiAjax.i18n.remaining || 'remaining'})</div>
                <ul class="installation-list"></ul>
            </div>`);

            const $list = $results.find('.installation-list');
            const $progress = $results.find('.progress-count');
            const $progressBar = $results.find('.progress-bar');

            $.ajax({
                url: bpiAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bpi_reinstall_themes',
                    nonce: bpiAjax.nonce,
                    items: JSON.stringify(themes)
                },
                success: function(response) {
                    if (response.success) {
                        const results = response.data;
                        $list.empty();

                        Object.keys(results).forEach(function(key) {
                            const result = results[key];
                            let itemName = key;

                            // Try to find the theme name from the DOM
                            $('#themes-list tr').each(function() {
                                if ($(this).data('slug') === key) {
                                    itemName = $(this).find('td:nth-child(2) strong').text();
                                }
                            });

                            $list.append(`<li class="${result.success ? 'success' : 'error'}">
                                <span class="item-name">${escapeHtml(itemName)}</span>
                                <span class="status ${result.success ? 'success' : 'error'}">
                                    ${result.success ? '✓ ' : '✗ '}${escapeHtml(result.message)}
                                </span>
                            </li>`);
                        });

                        $progressBar.css('width', '100%').text('100%');
                        $progress.text(`${themes.length}/${themes.length} ${bpiAjax.i18n.completed || 'completed'} (100% done, 0 ${bpiAjax.i18n.remaining || 'remaining'})`);
                        $results.find('.notice').removeClass('notice-info').addClass('notice-success')
                            .find('p').text('Reinstallation completed! Check the results below.');

                        // Refresh the themes list
                        loadThemes();
                    } else {
                        $results.html(`<div class="notice notice-error">
                            <p>Error: ${escapeHtml(response.data.message || 'Unknown error')}</p>
                        </div>`);
                    }
                },
                error: function(xhr, status, error) {
                    $results.html(`<div class="notice notice-error">
                        <p>Ajax Error: ${escapeHtml(error || 'Unknown error')}</p>
                    </div>`);
                }
            });
        }
    })();
});
