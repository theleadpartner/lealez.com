/**
 * OY Location - GMB "Más" Attributes Metabox JavaScript
 *
 * Flujo implementado:
 * - Modo lectura por defecto.
 * - Modo edición local independiente.
 * - Guardar metabox por AJAX sin publicar todo el post.
 * - Enviar a Google únicamente los cambios locales guardados.
 * - Verificar estado leyendo de nuevo los atributos desde GMB.
 * - Log visual detallado en localStorage.
 */
(function ($) {
    'use strict';

    var config = (typeof oyGmbMoreConfig !== 'undefined') ? oyGmbMoreConfig : {};
    var ajaxUrl = config.ajaxUrl || '';
    var nonce   = config.nonce   || '';
    var postId  = parseInt(config.postId || 0, 10);
    var i18n    = config.i18n    || {};

    var editorState = {
        enabled: false,
        dirty: false,
        saving: false,
        baseline: null
    };

    $(document).ready(function () {
        if (!postId) return;

        initEditor();
        initRefreshButton();
        initRenderButton();
        initSaveButtons();
        initPushButton();
        initCheckStatusButton();
        initLogPanel();
        renderLog();
    });

    function wrapper() {
        return $('#oy-gmb-more-metabox-' + postId);
    }

    function t(key, fallback) {
        return (i18n && i18n[key]) ? i18n[key] : fallback;
    }

    function initEditor() {
        var $wrap = wrapper();
        if (!$wrap.length) return;

        editorState.baseline = captureState();
        editorState.enabled = false;
        editorState.dirty = false;
        editorState.saving = false;

        setFieldsEnabled(false);
        updateEditorUi();

        $wrap.on('change input', '.oy-gmb-more-field-input', function () {
            if (!editorState.enabled) return;
            editorState.dirty = !statesEqual(editorState.baseline, captureState());
            $('#oy_gmb_more_has_changes_' + postId).val(editorState.dirty ? '1' : '0');
            updateEditorUi();
        });
    }

    function initSaveButtons() {
        $(document).on('click', '.oy-gmb-more-btn-edit', function (e) {
            e.preventDefault();
            beginEditMode();
        });

        $(document).on('click', '.oy-gmb-more-btn-cancel', function (e) {
            e.preventDefault();
            cancelEditMode();
        });

        $(document).on('click', '.oy-gmb-more-btn-save', function (e) {
            e.preventDefault();
            saveMetabox();
        });

        // Evitar publicar el post si hay cambios internos del metabox sin guardar.
        document.addEventListener('click', function (event) {
            var saveButton = event.target.closest('#publish, #save-post');
            if (!saveButton) return;

            if (editorState.enabled && editorState.dirty) {
                event.preventDefault();
                event.stopPropagation();
                showNotice('error', t('mustSaveFirst', 'Primero guarda los cambios locales del metabox antes de actualizar el post.'));
            }
        }, true);
    }

    function beginEditMode() {
        var $wrap = wrapper();
        if (!$wrap.length || !$wrap.find('.oy-gmb-more-group').length) {
            showNotice('error', t('renderNeedMeta', 'No hay atributos renderizados. Primero actualiza metadatos y agrega los atributos.'));
            return;
        }

        editorState.enabled = true;
        editorState.saving = false;
        editorState.baseline = captureState();
        editorState.dirty = false;
        $('#oy_gmb_more_has_changes_' + postId).val('0');
        $('#oy_gmb_more_editor_active_' + postId).val('1');
        setFieldsEnabled(true);
        updateEditorUi();
        showNotice('info', t('editMode', 'Modo edición activo.'));
    }

    function cancelEditMode() {
        if (editorState.dirty) {
            var ok = confirm('Hay cambios locales sin guardar. ¿Cancelar la edición y descartar los cambios?');
            if (!ok) return;
        }

        restoreState(editorState.baseline || {});
        editorState.enabled = false;
        editorState.dirty = false;
        editorState.saving = false;
        $('#oy_gmb_more_has_changes_' + postId).val('0');
        $('#oy_gmb_more_editor_active_' + postId).val('0');
        setFieldsEnabled(false);
        updateEditorUi();
        showNotice('info', t('readMode', 'Modo lectura.'));
    }

    function setFieldsEnabled(enabled) {
        wrapper().find('.oy-gmb-more-field-input').prop('disabled', !enabled);
    }

    function updateEditorUi() {
        var $wrap = wrapper();
        var hasGroups = $wrap.find('.oy-gmb-more-group').length > 0;

        $wrap.toggleClass('is-editing', !!editorState.enabled);
        $('.oy-gmb-more-btn-edit').toggle(!editorState.enabled).prop('disabled', editorState.saving || !hasGroups);
        $('.oy-gmb-more-btn-save').toggle(editorState.enabled).prop('disabled', !editorState.enabled || !editorState.dirty || editorState.saving);
        $('.oy-gmb-more-btn-cancel').toggle(editorState.enabled).prop('disabled', editorState.saving);

        $('.oy-gmb-more-btn-push, .oy-gmb-more-btn-check-status').each(function () {
            var $btn = $(this);
            var baseDisabled = String($btn.data('base-disabled') || '0') === '1';
            var loading = $btn.hasClass('is-loading');
            $btn.prop('disabled', baseDisabled || loading || (editorState.enabled && editorState.dirty));
        });

        var text = t('readMode', 'Modo lectura.');
        if (editorState.saving) text = t('saving', 'Guardando metabox...');
        else if (editorState.enabled && editorState.dirty) text = t('dirtyState', 'Tienes cambios locales sin guardar.');
        else if (editorState.enabled) text = t('editMode', 'Modo edición activo.');

        $('#oy-gmb-more-editor-state-' + postId).text(text);
    }

    function captureState() {
        var state = {};

        wrapper().find('.oy-gmb-more-field').each(function () {
            var $field = $(this);
            var attrId = String($field.data('attr-id') || '');
            if (!attrId) return;

            var valueType = String($field.data('value-type') || '');
            var label = $.trim($field.find('.oy-gmb-more-field-label').first().text()) || attrId;
            var value;
            var display;

            if (valueType === 'REPEATED_ENUM') {
                value = [];
                var labels = [];
                $field.find('input[type="checkbox"].oy-gmb-more-field-input:checked').each(function () {
                    value.push(String($(this).val() || ''));
                    labels.push($.trim($(this).closest('label').text()));
                });
                value.sort();
                labels.sort();
                display = labels.length ? labels.join(', ') : 'No especificado';
            } else if (valueType === 'BOOL') {
                var $checked = $field.find('input[type="radio"].oy-gmb-more-field-input:checked').first();
                value = $checked.length ? String($checked.val()) : '';
                display = value === 'true' ? 'Sí' : (value === 'false' ? 'No' : 'No especificado');
            } else {
                var $input = $field.find('.oy-gmb-more-field-input').first();
                value = $input.length ? String($input.val() || '') : '';
                if ($input.is('select')) {
                    display = $.trim($input.find('option:selected').text()) || 'No especificado';
                } else {
                    display = value || 'No especificado';
                }
            }

            state[attrId] = {
                label: label,
                value: value,
                display: display,
                type: valueType
            };
        });

        return normalizeState(state);
    }

    function restoreState(state) {
        state = state || {};

        wrapper().find('.oy-gmb-more-field').each(function () {
            var $field = $(this);
            var attrId = String($field.data('attr-id') || '');
            if (!attrId || !state[attrId]) return;

            var valueType = String($field.data('value-type') || '');
            var item = state[attrId];
            var value = item.value;

            if (valueType === 'REPEATED_ENUM') {
                var selected = Array.isArray(value) ? value.map(String) : [];
                $field.find('input[type="checkbox"].oy-gmb-more-field-input').each(function () {
                    $(this).prop('checked', selected.indexOf(String($(this).val())) !== -1);
                });
            } else if (valueType === 'BOOL') {
                $field.find('input[type="radio"].oy-gmb-more-field-input').prop('checked', false);
                $field.find('input[type="radio"].oy-gmb-more-field-input[value="' + cssEscape(String(value || '')) + '"]').prop('checked', true);
                if (String(value || '') === '') {
                    $field.find('input[type="radio"].oy-gmb-more-field-input[value=""]').prop('checked', true);
                }
            } else {
                $field.find('.oy-gmb-more-field-input').first().val(value || '');
            }
        });
    }

    function normalizeState(state) {
        var normalized = {};
        Object.keys(state || {}).sort().forEach(function (key) {
            var item = state[key] || {};
            var value = item.value;
            if (Array.isArray(value)) value = value.map(String).sort();
            else value = String(value == null ? '' : value);

            normalized[key] = {
                label: String(item.label || key),
                value: value,
                display: String(item.display || ''),
                type: String(item.type || '')
            };
        });
        return normalized;
    }

    function statesEqual(a, b) {
        return JSON.stringify(normalizeState(a || {})) === JSON.stringify(normalizeState(b || {}));
    }

    function saveMetabox() {
        if (editorState.saving || !editorState.enabled) return;

        if (!editorState.dirty) {
            showNotice('info', 'No hay cambios locales para guardar.');
            return;
        }

        var beforeState = editorState.baseline ? normalizeState(editorState.baseline) : captureState();
        var data = wrapper()
            .find('.oy-gmb-more-field-input, .oy-gmb-more-present-input, .oy-gmb-more-type-input, #oy_gmb_more_editor_active_' + postId)
            .serializeArray();

        data.push({ name: 'action', value: 'oy_gmb_more_save_metabox' });
        data.push({ name: 'nonce', value: nonce });
        data.push({ name: 'post_id', value: postId });

        editorState.saving = true;
        updateEditorUi();
        showNotice('info', t('saving', 'Guardando metabox...'));
        setButtonLoading($('.oy-gmb-more-btn-save'), true);

        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: data,
            success: function (response) {
                setButtonLoading($('.oy-gmb-more-btn-save'), false);
                editorState.saving = false;

                if (!response || !response.success) {
                    var msg = response && response.data && response.data.message ? response.data.message : t('saveError', 'No se pudieron guardar los cambios locales.');
                    showNotice('error', msg);
                    addLogEntry({ action: 'manual_gmb_more_save_error', error_message: msg, response: response && response.data ? response.data : response }, buildDiff(beforeState, captureState()), 'push_error');
                    updateEditorUi();
                    return;
                }

                if (response.data && response.data.panel_html) {
                    $('#oy-gmb-more-push-panel').replaceWith(response.data.panel_html);
                }

                var afterState = captureState();
                editorState.baseline = afterState;
                editorState.enabled = false;
                editorState.dirty = false;
                $('#oy_gmb_more_has_changes_' + postId).val('0');
                $('#oy_gmb_more_editor_active_' + postId).val('0');
                setFieldsEnabled(false);
                updateEditorUi();

                showNotice('success', response.data.message || t('saveDone', 'Cambios locales guardados.'));

                if (response.data && response.data.log_context) {
                    addLogEntry(response.data.log_context.raw || { action: 'manual_gmb_more_metabox_save' }, buildDiff(response.data.log_context.before || beforeState, response.data.log_context.after || afterState), 'manual_save');
                } else {
                    addLogEntry({ action: 'manual_gmb_more_metabox_save' }, buildDiff(beforeState, afterState), 'manual_save');
                }
            },
            error: function (xhr, status, error) {
                setButtonLoading($('.oy-gmb-more-btn-save'), false);
                editorState.saving = false;
                var msg = t('saveError', 'No se pudieron guardar los cambios locales.') + ' (' + error + ')';
                showNotice('error', msg);
                addLogEntry({ action: 'manual_gmb_more_save_network_error', error_message: msg }, buildDiff(beforeState, captureState()), 'push_error');
                updateEditorUi();
                if (window.console) console.error('[OY GMB More] AJAX error (save):', status, error, xhr);
            }
        });
    }

    function initRefreshButton() {
        $(document).on('click', '.oy-gmb-more-btn-refresh', function (e) {
            e.preventDefault();

            if (editorState.enabled && editorState.dirty) {
                showNotice('error', t('mustSaveFirst', 'Primero guarda los cambios locales del metabox antes de continuar.'));
                return;
            }

            var btn = $(this);
            if (btn.hasClass('is-loading')) return;

            var confirmed = confirm(
                (i18n.refreshing || 'Actualizando metadatos desde Google...') + '\n\n' +
                'La página se recargará para mostrar los atributos actualizados.'
            );
            if (!confirmed) return;

            showNotice('info', i18n.refreshing || 'Actualizando metadatos...');
            setButtonLoading(btn, true);

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'oy_gmb_more_refresh_metadata',
                    nonce: nonce,
                    post_id: postId
                },
                success: function (response) {
                    setButtonLoading(btn, false);

                    if (response.success) {
                        showNotice('success', response.data.message || (i18n.refreshDone || 'Metadatos actualizados.'));
                        addLogEntry({ action: 'refresh_gmb_more_metadata', response: response.data || {} }, [], 'manual_check');
                        if (response.data.reload) {
                            setTimeout(function () {
                                window.location.reload();
                            }, 1200);
                        }
                    } else {
                        var msg = (response.data && response.data.message)
                            ? response.data.message
                            : (i18n.refreshError || 'Error al actualizar metadatos.');
                        showNotice('error', msg);
                        addLogEntry({ action: 'refresh_gmb_more_metadata_error', error_message: msg, response: response.data || {} }, [], 'push_error');
                    }
                },
                error: function (xhr, status, error) {
                    setButtonLoading(btn, false);
                    showNotice('error', (i18n.refreshError || 'Error al actualizar metadatos.') + ' (' + error + ')');
                    if (window.console) console.error('[OY GMB More] AJAX error (refresh):', status, error);
                }
            });
        });
    }

    function initRenderButton() {
        $(document).on('click', '.oy-gmb-more-btn-render', function (e) {
            e.preventDefault();

            if (editorState.enabled && editorState.dirty) {
                showNotice('error', t('mustSaveFirst', 'Primero guarda los cambios locales del metabox antes de continuar.'));
                return;
            }

            var btn = $(this);
            if (btn.hasClass('is-loading') || btn.prop('disabled')) {
                if (btn.prop('disabled')) {
                    showNotice('error', i18n.renderNeedMeta || 'No hay metadatos cargados. Primero haz clic en "Actualizar metadatos".');
                }
                return;
            }

            var wrapperEl = btn.closest('.oy-gmb-more-wrapper');
            var contentEl = wrapperEl.find('.oy-gmb-more-content').first();

            if (!wrapperEl.length || !contentEl.length) {
                showNotice('error', 'No se encontró el contenedor del metabox para insertar los atributos (DOM no encontrado).');
                if (window.console) console.error('[OY GMB More] Render: wrapper/content not found.', { wrapper: wrapperEl.length, content: contentEl.length });
                return;
            }

            showNotice('info', i18n.rendering || 'Agregando atributos en la UI...');
            setButtonLoading(btn, true);

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'oy_gmb_more_render_attributes',
                    nonce: nonce,
                    post_id: postId
                },
                success: function (response) {
                    setButtonLoading(btn, false);

                    if (!response || !response.success) {
                        var msgErr = (response && response.data && response.data.message)
                            ? response.data.message
                            : (i18n.renderError || 'No se pudieron renderizar los atributos.');
                        showNotice('error', msgErr);
                        if (window.console) console.error('[OY GMB More] Render failed:', response);
                        return;
                    }

                    var html = (response.data && response.data.html) ? String(response.data.html) : '';
                    var htmlLen = html.trim().length;

                    if (htmlLen < 50) {
                        showNotice('error', 'El servidor respondió éxito pero NO devolvió HTML válido para insertar.');
                        if (window.console) console.error('[OY GMB More] Render success but empty HTML:', response.data);
                        return;
                    }

                    contentEl.html(html);

                    var hasGroups = contentEl.find('.oy-gmb-more-group').length;
                    if (hasGroups < 1) {
                        showNotice('error', 'Se insertó HTML pero no contiene grupos de atributos. Revisa metadata/groupDisplayName.');
                        if (window.console) console.error('[OY GMB More] Inserted HTML but no groups found.', { hasGroups: hasGroups, responseData: response.data });
                        return;
                    }

                    editorState.enabled = false;
                    editorState.dirty = false;
                    editorState.saving = false;
                    editorState.baseline = captureState();
                    setFieldsEnabled(false);
                    updateEditorUi();

                    var msgOk = response.data.message || (i18n.renderDone || 'Atributos agregados en la UI.');
                    msgOk += ' (Grupos: ' + hasGroups + ')';
                    showNotice('success', msgOk);
                    addLogEntry({ action: 'render_gmb_more_attributes', meta_count: response.data.meta_count, html_len: response.data.html_len, groups: hasGroups }, [], 'manual_check');
                },
                error: function (xhr, status, error) {
                    setButtonLoading(btn, false);
                    showNotice('error', (i18n.renderError || 'No se pudieron renderizar los atributos.') + ' (' + error + ')');
                    if (window.console) console.error('[OY GMB More] AJAX error (render):', status, error, xhr);
                }
            });
        });
    }

    function initPushButton() {
        $(document).on('click', '.oy-gmb-more-btn-push', function (e) {
            e.preventDefault();

            if (editorState.enabled && editorState.dirty) {
                showNotice('error', t('mustSaveFirst', 'Primero guarda los cambios locales del metabox antes de enviar a Google.'));
                return;
            }

            var btn = $(this);
            if (btn.hasClass('is-loading') || btn.prop('disabled')) return;

            var confirmed = confirm(i18n.confirmPush || '¿Enviar los cambios guardados localmente a Google Business Profile?');
            if (!confirmed) return;

            showNotice('info', i18n.pushing || 'Enviando a Google...');
            setButtonLoading(btn, true);

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'oy_gmb_more_push_to_gmb',
                    nonce: nonce,
                    post_id: postId
                },
                success: function (response) {
                    setButtonLoading(btn, false);

                    if (response && response.data && response.data.panel_html) {
                        $('#oy-gmb-more-push-panel').replaceWith(response.data.panel_html);
                    }

                    if (response && response.success) {
                        showNotice('success', response.data.message || (i18n.pushDone || 'Cambios enviados a Google.'));
                        $('#oy_gmb_more_has_changes_' + postId).val('0');
                        $('#oy_gmb_more_editor_active_' + postId).val('0');
                        editorState.baseline = captureState();
                        editorState.dirty = false;
                        updateEditorUi();
                        if (response.data && response.data.log_context) {
                            addLogEntry(response.data.log_context.raw || { action: 'push_gmb_more_to_gmb', response: response.data }, buildDiff(response.data.log_context.before || {}, response.data.log_context.after || {}), 'push_to_gmb');
                        }
                    } else {
                        var msg = (response && response.data && response.data.message)
                            ? response.data.message
                            : (i18n.pushError || 'Error al enviar a Google.');
                        showNotice('error', msg);
                        if (response && response.data && response.data.log_context) {
                            addLogEntry(response.data.log_context.raw || { action: 'push_gmb_more_error', error_message: msg, response: response.data }, buildDiff(response.data.log_context.before || {}, response.data.log_context.after || {}), 'push_error');
                        }
                    }
                },
                error: function (xhr, status, error) {
                    setButtonLoading(btn, false);
                    var msg = (i18n.pushError || 'Error al enviar a Google.') + ' (' + error + ')';
                    showNotice('error', msg);
                    addLogEntry({ action: 'push_gmb_more_network_error', error_message: msg }, [], 'push_error');
                    if (window.console) console.error('[OY GMB More] AJAX error (push):', status, error, xhr);
                }
            });
        });
    }

    function initCheckStatusButton() {
        $(document).on('click', '.oy-gmb-more-btn-check-status', function (e) {
            e.preventDefault();

            if (editorState.enabled && editorState.dirty) {
                showNotice('error', t('mustSaveFirst', 'Primero guarda los cambios locales del metabox antes de verificar.'));
                return;
            }

            var btn = $(this);
            if (btn.hasClass('is-loading') || btn.prop('disabled')) return;

            showNotice('info', t('checking', 'Verificando estado en Google...'));
            setButtonLoading(btn, true);

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'oy_gmb_more_check_push_status',
                    nonce: nonce,
                    post_id: postId
                },
                success: function (response) {
                    setButtonLoading(btn, false);

                    if (response && response.data && response.data.panel_html) {
                        $('#oy-gmb-more-push-panel').replaceWith(response.data.panel_html);
                    }

                    if (response && response.success) {
                        showNotice('success', response.data.message || 'Estado verificado.');
                        if (response.data && response.data.log_context) {
                            addLogEntry(response.data.log_context.raw || { action: 'check_gmb_more_status', response: response.data }, buildDiff(response.data.log_context.before || {}, response.data.log_context.after || {}), 'manual_check');
                        }
                    } else {
                        var msg = response && response.data && response.data.message ? response.data.message : t('checkError', 'No se pudo verificar el estado en Google.');
                        showNotice('error', msg);
                        if (response && response.data && response.data.log_context) {
                            addLogEntry(response.data.log_context.raw || { action: 'check_gmb_more_status_error', error_message: msg }, buildDiff(response.data.log_context.before || {}, response.data.log_context.after || {}), 'push_error');
                        }
                    }
                },
                error: function (xhr, status, error) {
                    setButtonLoading(btn, false);
                    var msg = t('checkError', 'No se pudo verificar el estado en Google.') + ' (' + error + ')';
                    showNotice('error', msg);
                    addLogEntry({ action: 'check_gmb_more_status_network_error', error_message: msg }, [], 'push_error');
                    if (window.console) console.error('[OY GMB More] AJAX error (check):', status, error, xhr);
                }
            });
        });
    }

    function showNotice(type, message) {
        var noticeEl = $('#oy-gmb-more-notice-' + postId);
        if (!noticeEl.length) return;

        noticeEl
            .removeClass('success error info')
            .addClass(type)
            .html('<strong>' + escapeHtml(message) + '</strong>')
            .fadeIn(200);

        if (type !== 'error') {
            clearTimeout(noticeEl.data('hide-timer'));
            noticeEl.data('hide-timer', setTimeout(function () {
                noticeEl.fadeOut(400);
            }, 6000));
        }
    }

    function setButtonLoading(btn, loading) {
        if (!btn || !btn.length) return;
        if (loading) {
            btn.addClass('is-loading').prop('disabled', true).css('opacity', '0.7');
        } else {
            btn.removeClass('is-loading').css('opacity', '1');
            updateEditorUi();
        }
    }

    function initLogPanel() {
        $(document).on('click', '#oy-gmb-more-log-header', function () {
            var $body = $('#oy-gmb-more-log-body');
            var $icon = $('#oy-gmb-more-log-toggle-icon');
            $body.slideToggle(160, function () {
                var visible = $body.is(':visible');
                $icon.css('transform', visible ? 'rotate(90deg)' : 'rotate(0deg)');
                $('#oy-gmb-more-log-header').css('borderBottomColor', visible ? '#dadce0' : 'transparent');
            });
        });

        $(document).on('click', '#oy-gmb-more-log-clear', function (e) {
            e.preventDefault();
            if (!confirm('¿Limpiar el historial visual de este metabox en este navegador?')) return;
            localStorage.removeItem(logKey());
            renderLog();
        });
    }

    function logKey() {
        return 'oy_gmb_more_sync_log_' + postId;
    }

    function getLog() {
        try {
            var raw = localStorage.getItem(logKey());
            var log = raw ? JSON.parse(raw) : [];
            return Array.isArray(log) ? log : [];
        } catch (e) {
            return [];
        }
    }

    function setLog(log) {
        try {
            localStorage.setItem(logKey(), JSON.stringify((log || []).slice(0, 20)));
        } catch (e) {}
    }

    function addLogEntry(raw, diff, type) {
        var log = getLog();
        log.unshift({
            at: new Date().toISOString(),
            type: type || 'manual_check',
            raw: raw || {},
            diff: Array.isArray(diff) ? diff : []
        });
        setLog(log);
        renderLog();
    }

    function renderLog() {
        var $container = $('#oy-gmb-more-log-entries');
        if (!$container.length) return;

        var log = getLog();
        if (!log.length) {
            $container.html('<div style="padding:10px 0; color:#777; font-style:italic;">No hay eventos registrados en este navegador para este metabox.</div>');
            return;
        }

        var typeStyles = {
            manual_save:  { bg: '#f6fff9', border: '#46b450', icon: '💾', label: 'Guardado local' },
            push_to_gmb:  { bg: '#eef6ff', border: '#2271b1', icon: '🚀', label: 'Envío a GMB' },
            manual_check: { bg: '#fffdf3', border: '#dba617', icon: '🔎', label: 'Verificación' },
            push_error:   { bg: '#fff5f5', border: '#dc3232', icon: '⚠️', label: 'Error' }
        };

        var html = '';
        log.forEach(function (entry) {
            var style = typeStyles[entry.type] || typeStyles.manual_check;
            var action = entry.raw && entry.raw.action ? entry.raw.action : style.label;
            var date = entry.at ? new Date(entry.at) : new Date();
            var dateLabel = isNaN(date.getTime()) ? entry.at : date.toLocaleString();

            html += '<div style="margin-bottom:10px; padding:10px 12px; background:' + style.bg + '; border-left:4px solid ' + style.border + '; border-radius:3px;">';
            html += '<div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:6px;">';
            html += '<strong>' + style.icon + ' ' + escapeHtml(style.label) + ' <code>' + escapeHtml(action) + '</code></strong>';
            html += '<span style="font-size:11px; color:#666;">' + escapeHtml(dateLabel) + '</span>';
            html += '</div>';

            if (entry.raw && entry.raw.error_message) {
                html += '<div style="margin:6px 0; color:#dc3232;"><strong>Error:</strong> ' + escapeHtml(entry.raw.error_message) + '</div>';
            }

            if (entry.diff && entry.diff.length) {
                html += '<table style="width:100%; border-collapse:collapse; font-size:12px; margin-top:8px; background:#fff;">';
                html += '<thead><tr><th style="text-align:left; border:1px solid #eee; padding:6px;">Atributo</th><th style="text-align:left; border:1px solid #eee; padding:6px;">Antes</th><th style="text-align:left; border:1px solid #eee; padding:6px;">Después</th><th style="text-align:left; border:1px solid #eee; padding:6px;">Estado</th></tr></thead><tbody>';
                entry.diff.forEach(function (row) {
                    html += '<tr>';
                    html += '<td style="border:1px solid #eee; padding:6px;">' + escapeHtml(row.label || row.key || '') + '</td>';
                    html += '<td style="border:1px solid #eee; padding:6px;">' + escapeHtml(row.before_display || row.before || 'No especificado') + '</td>';
                    html += '<td style="border:1px solid #eee; padding:6px;">' + escapeHtml(row.after_display || row.after || 'No especificado') + '</td>';
                    html += '<td style="border:1px solid #eee; padding:6px;">' + escapeHtml(row.status || '') + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
            }

            html += '<details style="margin-top:8px;"><summary style="cursor:pointer; color:#2271b1;">Ver datos técnicos</summary><pre style="white-space:pre-wrap; max-height:180px; overflow:auto; background:#f6f7f7; padding:8px; border:1px solid #e5e5e5;">' + escapeHtml(JSON.stringify(entry.raw || {}, null, 2)) + '</pre></details>';
            html += '</div>';
        });

        $container.html(html);
    }

    function buildDiff(before, after) {
        before = normalizeSnapshot(before || {});
        after = normalizeSnapshot(after || {});

        var keys = {};
        Object.keys(before).forEach(function (key) { keys[key] = true; });
        Object.keys(after).forEach(function (key) { keys[key] = true; });

        var rows = [];
        Object.keys(keys).sort().forEach(function (key) {
            var b = before[key] || {};
            var a = after[key] || {};
            var beforeValue = JSON.stringify(b.value == null ? '' : b.value);
            var afterValue = JSON.stringify(a.value == null ? '' : a.value);

            if (beforeValue === afterValue) {
                return;
            }

            rows.push({
                key: key,
                label: a.label || b.label || key,
                before: beforeValue,
                after: afterValue,
                before_display: b.display || 'No especificado',
                after_display: a.display || 'No especificado',
                status: beforeValue === '""' ? 'new' : (afterValue === '""' || afterValue === '[]' ? 'cleared' : 'changed')
            });
        });

        return rows;
    }

    function normalizeSnapshot(snapshot) {
        var normalized = {};
        Object.keys(snapshot || {}).forEach(function (key) {
            var item = snapshot[key] || {};
            var value = item.value;
            if (Array.isArray(value)) value = value.map(String).sort();
            else value = String(value == null ? '' : value);
            normalized[key] = {
                label: String(item.label || key),
                value: value,
                display: String(item.display || value || 'No especificado')
            };
        });
        return normalized;
    }

    function cssEscape(str) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(str);
        }
        return String(str).replace(/([ #;?%&,.+*~\':"!^$[\]()=>|\/ @])/g, '\\$1');
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

})(jQuery);
