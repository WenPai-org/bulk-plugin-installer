jQuery(document).ready(function($) {
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
        const $results = $('#installation-results');

        let items = [];
        let errorMessage = '';

        if (type === 'upload') {
            const $fileInput = $form.find('input[type="file"]');
            const files = $fileInput[0].files;
            if (!files || files.length === 0) {
                errorMessage = 'Please select at least one ZIP file.';
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
                    'Please enter at least one slug.' : 
                    'Please enter at least one URL.';
            }
        }

        if (errorMessage) {
            alert(errorMessage);
            return;
        }

        $submitButton.prop('disabled', true).text('Installing...');
        $results.html(`<div class="notice notice-info"><p>Installation in progress... (Large ZIP files may take some time)</p><div class="progress-count">0/${items.length} completed (0% done, ${items.length} remaining)</div><ul class="installation-list"></ul></div>`);

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
                    if (response.success) {
                        Object.keys(response.data).forEach((item, index) => {
                            handleResponse(response, item, index);
                        });
                    } else {
                        $list.append(`<li><span class="item-name">Upload Error</span><span class="status error">✗ ${response.data}</span></li>`);
                    }
                    installationComplete();
                },
                error: function(xhr, status, error) {
                    $list.append(`<li><span class="item-name">Upload Error</span><span class="status error">✗ ${xhr.responseText || error}</span></li>`);
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

            if (response.success) {
                const result = response.data[item];
                $item.addClass(result.success ? 'success' : 'error')
                    .find('.status').text(result.success ? '✓ ' + result.message : '✗ ' + result.message);
            } else {
                $item.addClass('error').find('.status').text('✗ ' + (response.data || 'Unknown error'));
            }

            completed++;
            const percentage = Math.round((completed / items.length) * 100);
            const remaining = items.length - completed;
            $progress.text(`${completed}/${items.length} completed (${percentage}% done, ${remaining} remaining)`);
        }

        function handleError(xhr, status, error, item, index) {
            const $item = $(`#item-${index}`) || $list.find('li:last');
            $item.find('.spinner').removeClass('is-active')
                .addClass('error')
                .find('.status').text(`✗ Installation failed: ${xhr.responseText || error}`);
            completed++;
            const percentage = Math.round((completed / items.length) * 100);
            const remaining = items.length - completed;
            $progress.text(`${completed}/${items.length} completed (${percentage}% done, ${remaining} remaining)`);
        }

        function installationComplete() {
            $submitButton.prop('disabled', false).text(`Install ${action === 'bpi_install_plugins' ? 'Plugins' : 'Themes'}`);
            const $notice = $results.find('.notice').removeClass('notice-info').addClass('notice-success');
            $notice.find('p').text('Installation completed!');
        }
    });

    $('#bpi-settings-form').on('submit', function(e) {
        e.preventDefault();
        const $form = $(this);
        const $submitButton = $form.find('button[type="submit"]');
        const $status = $('#settings-status');

        $submitButton.prop('disabled', true).text('Saving...');
        $status.removeClass('notice-success notice-error').addClass('notice-info').text('Saving...').show();

        const formData = $form.serialize(); // 使用 serialize() 而不是 serializeArray()
        
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