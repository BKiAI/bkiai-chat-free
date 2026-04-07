document.addEventListener('DOMContentLoaded', function () {
    var activeTabInput = document.getElementById('bkiai_active_tab');

    function activateAdminTab(target) {
        if (!target) {
            target = 'general';
        }

        var targetExists = false;
        document.querySelectorAll('.bkiai-admin-tab-button').forEach(function (button) {
            if (button.getAttribute('data-tab-target') === target) {
                targetExists = true;
            }
        });

        if (!targetExists) {
            target = 'general';
        }

        document.querySelectorAll('.bkiai-admin-tab-button').forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-tab-target') === target);
        });

        document.querySelectorAll('.bkiai-admin-tab-panel').forEach(function (panel) {
            var isActive = panel.getAttribute('data-tab-panel') === target;
            panel.classList.toggle('is-active', isActive);
            panel.style.display = isActive ? 'block' : 'none';
        });

        if (activeTabInput) {
            activeTabInput.value = target;
        }

        try {
            window.localStorage.setItem('bkiai_admin_active_tab', target);
        } catch (e) {}
    }

    var urlTab = '';
    try {
        urlTab = new URLSearchParams(window.location.search).get('active_tab') || '';
    } catch (e) {}

    var savedTab = '';
    try {
        savedTab = window.localStorage.getItem('bkiai_admin_active_tab') || '';
    } catch (e) {}

    activateAdminTab(urlTab || (activeTabInput ? activeTabInput.value : '') || savedTab || 'general');

    document.querySelectorAll('.bkiai-admin-tab-button').forEach(function (button) {
        button.addEventListener('click', function () {
            activateAdminTab(button.getAttribute('data-tab-target'));
        });
    });

    document.querySelectorAll('.bkiai-color-palette').forEach(function (picker) {
        var targetId = picker.getAttribute('data-target');
        var textInput = document.getElementById(targetId);
        if (!textInput) {
            return;
        }
        picker.addEventListener('input', function () {
            textInput.value = picker.value;
            textInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
        textInput.addEventListener('input', function () {
            var value = (textInput.value || '').trim();
            if (/^#[0-9a-fA-F]{6}$/.test(value)) {
                picker.value = value;
            }
        });
    });

    function normalizeHex(hex) {
        var value = (hex || '').trim();
        if (/^#[0-9a-fA-F]{3}$/.test(value)) {
            return '#' + value.charAt(1) + value.charAt(1) + value.charAt(2) + value.charAt(2) + value.charAt(3) + value.charAt(3);
        }
        return /^#[0-9a-fA-F]{6}$/.test(value) ? value.toLowerCase() : '#ffffff';
    }

    function adjustColor(hex, amount) {
        hex = normalizeHex(hex).replace('#', '');
        var num = parseInt(hex, 16);
        var r = Math.max(0, Math.min(255, (num >> 16) + amount));
        var g = Math.max(0, Math.min(255, ((num >> 8) & 0xff) + amount));
        var b = Math.max(0, Math.min(255, (num & 0xff) + amount));
        return '#' + [r, g, b].map(function (part) { return part.toString(16).padStart(2, '0'); }).join('');
    }

    function rgba(hex, alpha) {
        hex = normalizeHex(hex).replace('#', '');
        var num = parseInt(hex, 16);
        var r = num >> 16;
        var g = (num >> 8) & 0xff;
        var b = num & 0xff;
        return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
    }

    function buildFillPreview(color, type, preset, angle) {
        color = normalizeHex(color);
        angle = parseInt(angle || '135', 10);
        var light = adjustColor(color, 52);
        var mid = adjustColor(color, 22);
        var dark = adjustColor(color, -24);
        var deeper = adjustColor(color, -44);

        if (type === 'solid') {
            return color;
        }
        if (type === 'gradient') {
            switch (preset) {
                case 'shine':
                    return 'linear-gradient(' + angle + 'deg, ' + light + ' 0%, ' + mid + ' 38%, ' + dark + ' 100%)';
                case 'deep':
                    return 'linear-gradient(' + angle + 'deg, ' + mid + ' 0%, ' + deeper + ' 100%)';
                case 'split':
                    return 'linear-gradient(' + angle + 'deg, ' + light + ' 0%, ' + color + ' 52%, ' + dark + ' 100%)';
                default:
                    return 'linear-gradient(' + angle + 'deg, ' + light + ' 0%, ' + color + ' 100%)';
            }
        }
        if (preset === 'dots') {
            return 'radial-gradient(circle, ' + rgba('#ffffff', 0.30) + ' 0 2px, transparent 2.4px) 0 0 / 18px 18px repeat, linear-gradient(' + angle + 'deg, ' + light + ' 0%, ' + color + ' 100%)';
        }
        if (preset === 'grid') {
            return 'linear-gradient(' + rgba('#ffffff', 0.18) + ' 1px, transparent 1px) 0 0 / 18px 18px repeat, linear-gradient(90deg, ' + rgba('#ffffff', 0.18) + ' 1px, transparent 1px) 0 0 / 18px 18px repeat, linear-gradient(' + angle + 'deg, ' + light + ' 0%, ' + color + ' 100%)';
        }
        if (preset === 'mesh') {
            return 'repeating-linear-gradient(' + angle + 'deg, ' + rgba('#ffffff', 0.24) + ' 0 2px, transparent 2px 14px), repeating-linear-gradient(' + ((angle + 90) % 360) + 'deg, ' + rgba('#ffffff', 0.10) + ' 0 2px, transparent 2px 14px), linear-gradient(' + angle + 'deg, ' + mid + ' 0%, ' + dark + ' 100%)';
        }
        return 'repeating-linear-gradient(' + angle + 'deg, ' + rgba('#ffffff', 0.24) + ' 0 10px, ' + rgba('#ffffff', 0.10) + ' 10px 20px), linear-gradient(' + angle + 'deg, ' + light + ' 0%, ' + color + ' 100%)';
    }

    function updateAllFillPreviews() {
        document.querySelectorAll('.bkiai-fill-preview').forEach(function (preview) {
            var scope = preview.getAttribute('data-scope');
            var colorInput = document.querySelector('input[name="design[' + scope + '_color]"]');
            var typeSelect = document.querySelector('select[name="design[' + scope + '_fill_type]"]');
            var presetSelect = document.querySelector('select[name="design[' + scope + '_fill_preset]"]');
            var angleSelect = document.querySelector('select[name="design[' + scope + '_fill_angle]"]');
            preview.style.background = buildFillPreview(colorInput ? colorInput.value : '#ffffff', typeSelect ? typeSelect.value : 'solid', presetSelect ? presetSelect.value : 'soft', angleSelect ? angleSelect.value : '135');
        });
    }

    document.querySelectorAll('.bkiai-fill-preview').forEach(function (preview) {
        var scope = preview.getAttribute('data-scope');
        ['color', 'fill_type', 'fill_preset', 'fill_angle'].forEach(function (suffix) {
            var field = document.querySelector('[name="design[' + scope + '_' + suffix + ']"]');
            if (field) {
                field.addEventListener('input', updateAllFillPreviews);
                field.addEventListener('change', updateAllFillPreviews);
            }
        });
    });
    updateAllFillPreviews();

    var designPresets = (window.bkiaiAdminConfig && window.bkiaiAdminConfig.designPresets) || {};
    var designPresetSelect = document.getElementById('bkiai_design_preset');
    var applyDesignPresetButton = document.getElementById('bkiai_apply_design_preset');
    if (applyDesignPresetButton && designPresetSelect) {
        applyDesignPresetButton.addEventListener('click', function () {
            var selected = designPresetSelect.value;
            if (!selected || !designPresets[selected]) {
                return;
            }
            var values = designPresets[selected].values || {};
            Object.keys(values).forEach(function (key) {
                var field = document.querySelector('[name="design[' + key + ']"]');
                if (!field) {
                    return;
                }
                if (field.type === 'checkbox') {
                    field.checked = values[key] === '1';
                } else {
                    field.value = values[key];
                }
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
                if (key.indexOf('_color') !== -1 && field.id) {
                    var picker = document.querySelector('.bkiai-color-palette[data-target="' + field.id + '"]');
                    if (picker) {
                        picker.value = values[key];
                    }
                }
            });
            updateAllFillPreviews();
        });
    }

    function attachImageFieldPreview(config) {
        if (!config) {
            return;
        }
        var selectButton = document.getElementById(config.selectButtonId);
        var removeButton = document.getElementById(config.removeButtonId);
        var fileInput = document.getElementById(config.fileInputId);
        var urlInput = document.getElementById(config.urlInputId);
        var removeInput = document.getElementById(config.removeInputId);
        var preview = document.getElementById(config.previewId);
        if (!fileInput || !urlInput || !preview) {
            return;
        }

        function setPreview(src) {
            if (src) {
                preview.src = src;
                preview.classList.remove('is-hidden');
            } else {
                preview.src = '';
                preview.classList.add('is-hidden');
            }
        }

        if (selectButton) {
            selectButton.addEventListener('click', function (event) {
                event.preventDefault();
                fileInput.click();
            });
        }

        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files[0]) {
                if (removeInput) {
                    removeInput.value = '0';
                }
                urlInput.value = fileInput.files[0].name;
                var reader = new FileReader();
                reader.onload = function (readerEvent) {
                    setPreview(readerEvent.target.result);
                };
                reader.readAsDataURL(fileInput.files[0]);
            }
        });

        if (removeButton) {
            removeButton.addEventListener('click', function (event) {
                event.preventDefault();
                if (removeInput) {
                    removeInput.value = '1';
                }
                fileInput.value = '';
                urlInput.value = '';
                setPreview('');
            });
        }

        urlInput.addEventListener('input', function () {
            var value = (urlInput.value || '').trim();
            if (!value) {
                setPreview('');
                return;
            }
            if (removeInput) {
                removeInput.value = '0';
            }
            if (/^https?:\/\//i.test(value) || value.indexOf('/') === 0) {
                setPreview(value);
            }
        });
    }

    attachImageFieldPreview({
        selectButtonId: 'bkiai_logo_select_button',
        removeButtonId: 'bkiai_logo_remove_button',
        fileInputId: 'bkiai_logo_file',
        urlInputId: 'bkiai_design_logo_url',
        removeInputId: 'bkiai_logo_remove',
        previewId: 'bkiai_logo_preview'
    });

    attachImageFieldPreview({
        selectButtonId: 'bkiai_chat_history_background_select_button',
        removeButtonId: 'bkiai_chat_history_background_remove_button',
        fileInputId: 'bkiai_chat_history_background_file',
        urlInputId: 'bkiai_design_chat_history_background_image_url',
        removeInputId: 'bkiai_chat_history_background_remove',
        previewId: 'bkiai_chat_history_background_preview'
    });

    document.querySelectorAll('.bkiai-admin-file-trigger').forEach(function (button) {
        var targetId = button.getAttribute('data-target');
        var fileInput = document.getElementById(targetId);
        var status = button.parentNode ? button.parentNode.querySelector('.bkiai-admin-file-status') : null;
        if (!fileInput || !status) {
            return;
        }
        function syncStatus() {
            var files = fileInput.files ? Array.prototype.slice.call(fileInput.files) : [];
            status.textContent = files.length ? files.map(function (file) { return file.name; }).join(', ') : (status.getAttribute('data-empty-label') || 'No file chosen');
        }
        button.addEventListener('click', function (event) {
            event.preventDefault();
            fileInput.click();
        });
        fileInput.addEventListener('change', syncStatus);
        syncStatus();
    });

    document.querySelectorAll('.bkiai-systemprompt-field').forEach(function (textarea) {
        var counterId = textarea.getAttribute('data-counter-target');
        var counter = counterId ? document.getElementById(counterId) : null;
        if (!counter) {
            return;
        }
        function updateCounter() {
            var length = (textarea.value || '').length;
            counter.textContent = length + ' characters';
            counter.classList.remove('is-warning', 'is-danger');
            if (length > 2500) {
                counter.classList.add('is-warning');
            }
            if (length > 5000) {
                counter.classList.add('is-danger');
            }
        }
        textarea.addEventListener('input', updateCounter);
        updateCounter();
    });
});
