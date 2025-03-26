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

    $('.bpi-tab').on('click', function() {
        $('.bpi-tab').removeClass('active');
        $(this).addClass('active');
        
        var tab = $(this).data('tab');
        $('.bpi-tab-content').removeClass('active').hide();
        $('#' + tab).addClass('active').show();
    });

    $('.bpi-select').on('change', function() {
        const $form = $(this).closest('.bpi-form');
        const selectedType = $(this).val();
        
        $form.find('.source-input').removeClass('active').hide();
        $form.find('textarea[name="items"]').val('');
        $form.find('input[type="file"]').val('');
        $form.find('.selected-files').empty();
        
        $form.find('.' + selectedType + '-source').addClass('active').show();
    });

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
        $results.html(`<div class="notice notice-info"><p>${bpiAjax.i18n.installation_in_progress}</p><div class="progress-count">0/${items.length} ${bpiAjax.i18n.completed} (0% done, ${items.length} ${bpiAjax.i18n.remaining})</div><ul class="installation-list"></ul></div>`);

        const $list = $results.find('.installation-list');
        const $progress = $results.find('.progress-count');
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
                    console.log('Upload response:', response);
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
                    console.log('Upload error:', xhr, status, error);
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
                    console.log('Item response:', response);
                    handleResponse(response, item, index);
                    processNextItem(index + 1);
                },
                error: function(xhr, status, error) {
                    console.log('Item error:', xhr, status, error);
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

            completed++;
            const percentage = Math.round((completed / items.length) * 100);
            const remaining = items.length - completed;
            $progress.text(`${completed}/${items.length} ${bpiAjax.i18n.completed} (${percentage}% done, ${remaining} ${bpiAjax.i18n.remaining})`);
        }

        function handleError(xhr, status, error, item, index) {
            const $item = $(`#item-${index}`) || $list.find('li:last');
            $item.find('.spinner').removeClass('is-active')
                .addClass('error')
                .find('.status')
                .html(`✗ ${escapeHtml(xhr.responseText || 'Installation failed: ' + error)} <button class="retry-btn" data-item="${escapeHtml(item)}" data-type="${type}">${bpiAjax.i18n.retry}</button>`);
            completed++;
            const percentage = Math.round((completed / items.length) * 100);
            const remaining = items.length - completed;
            $progress.text(`${completed}/${items.length} ${bpiAjax.i18n.completed} (${percentage}% done, ${remaining} ${bpiAjax.i18n.remaining})`);
        }

        function installationComplete() {
            $submitButton.prop('disabled', false).text(`Install ${action === 'bpi_install_plugins' ? 'Plugins' : 'Themes'}`);
            const $notice = $results.find('.notice').removeClass('notice-info').addClass('notice-success');
            $notice.find('p').html(bpiAjax.i18n.installation_complete);
        }
    });

    $results.on('click', '.retry-btn', function() {
        const $button = $(this);
        const item = $button.data('item');
        const type = $button.data('type');
        const action = $('#bulk-plugin-form').is(':visible') ? 'bpi_install_plugins' : 'bpi_install_themes';
        const $li = $button.closest('li');
        $li.find('.spinner').addClass('is-active');
        $li.find('.status').html('');

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
                console.log('Retry response:', response);
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
                console.log('Retry error:', xhr, status, error);
                $li.find('.spinner').removeClass('is-active')
                    .addClass('error')
                    .find('.status')
                    .html(`✗ ${escapeHtml(xhr.responseText || 'Retry failed: ' + error)} <button class="retry-btn" data-item="${escapeHtml(item)}" data-type="${type}">${bpiAjax.i18n.retry}</button>`);
            }
        });
    });

    $('#bpi-settings-form').on('submit', function(e) {
        e.preventDefault();
        const $form = $(this);
        const $submitButton = $form.find('button[type="submit"]');
        const $status = $('#settings-status');

        $submitButton.prop('disabled', true).text('Saving...');
        $status.removeClass('notice-success notice-error').addClass('notice-info').text('Saving...').show();

        $.ajax({
            url: bpiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'bpi_save_settings',
                nonce: bpiAjax.nonce,
                bpi_allowed_roles: $form.find('input[name="bpi_allowed_roles[]"]:checked').map(function() { return this.value; }).get(),
                bpi_custom_domains: $form.find('textarea[name="bpi_custom_domains"]').val()
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

    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
});