/**
 * OY Location - GMB "Más" Attributes Metabox JavaScript
 *
 * - Botón "Actualizar metadatos" → AJAX refresh + reload
 * - Botón "Agregar los atributos" → AJAX render UI (sin llamar a Google) + INSERT REAL
 * - Botón "Enviar a Google ↑" → AJAX push
 */

(function ($) {
    'use strict';

    var config = (typeof oyGmbMoreConfig !== 'undefined') ? oyGmbMoreConfig : {};
    var ajaxUrl = config.ajaxUrl || '';
    var nonce   = config.nonce   || '';
    var postId  = config.postId  || 0;
    var i18n    = config.i18n    || {};

    $(document).ready(function () {
        if (!postId) return;

        initChangeTracking();
        initRefreshButton();
        initRenderButton();
        initPushButton();
    });

    function initChangeTracking() {
        var wrapper = $('#oy-gmb-more-metabox-' + postId);
        if (!wrapper.length) return;

        wrapper.on('change', '.oy-gmb-more-field-input', function () {
            markHasChanges(true);
        });
    }

    function markHasChanges(hasChanges) {
        $('#oy_gmb_more_has_changes_' + postId).val(hasChanges ? '1' : '0');

        var pushBtn = $('.oy-gmb-more-btn-push');
        if (hasChanges) {
            pushBtn.addClass('button-primary').removeClass('button-secondary');
        }
    }

    function initRefreshButton() {
        $(document).on('click', '.oy-gmb-more-btn-refresh', function (e) {
            e.preventDefault();

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
                url     : ajaxUrl,
                method  : 'POST',
                dataType: 'json',
                data    : {
                    action  : 'oy_gmb_more_refresh_metadata',
                    nonce   : nonce,
                    post_id : postId
                },
                success: function (response) {
                    setButtonLoading(btn, false);

                    if (response.success) {
                        showNotice('success', response.data.message || (i18n.refreshDone || 'Metadatos actualizados.'));
                        if (response.data.reload) {
                            setTimeout(function () {
                                window.location.reload();
                            }, 1500);
                        }
                    } else {
                        var msg = (response.data && response.data.message)
                            ? response.data.message
                            : (i18n.refreshError || 'Error al actualizar metadatos.');
                        showNotice('error', msg);
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

    /**
     * ✅ FIX: Insertar HTML relativo al botón para evitar fallos por re-render del DOM.
     * Además, NO mostrar success si NO insertó realmente.
     */
    function initRenderButton() {
        $(document).on('click', '.oy-gmb-more-btn-render', function (e) {
            e.preventDefault();

            var btn = $(this);
            if (btn.hasClass('is-loading') || btn.prop('disabled')) {
                if (btn.prop('disabled')) {
                    showNotice('error', i18n.renderNeedMeta || 'No hay metadatos cargados. Primero haz clic en "Actualizar metadatos".');
                }
                return;
            }

            // Encontrar el wrapper y el contenedor REAL a reemplazar
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
                url     : ajaxUrl,
                method  : 'POST',
                dataType: 'json',
                data    : {
                    action  : 'oy_gmb_more_render_attributes',
                    nonce   : nonce,
                    post_id : postId
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

                    // ✅ GARANTÍA JS: si no hay html real, NO success
                    if (htmlLen < 50) {
                        showNotice('error', 'El servidor respondió éxito pero NO devolvió HTML válido para insertar.');
                        if (window.console) console.error('[OY GMB More] Render success but empty HTML:', response.data);
                        return;
                    }

                    // ✅ Insertar HTML
                    contentEl.html(html);

                    // ✅ Verificación post-insert (garantía)
                    var hasGroups = contentEl.find('.oy-gmb-more-group').length;
                    if (hasGroups < 1) {
                        showNotice('error', 'Se insertó HTML pero no contiene grupos de atributos. Revisa metadata/groupDisplayName.');
                        if (window.console) console.error('[OY GMB More] Inserted HTML but no groups found.', { hasGroups: hasGroups, responseData: response.data });
                        return;
                    }

                    // Success real
                    var msgOk = response.data.message || (i18n.renderDone || 'Atributos agregados en la UI.');
                    // Añadimos datos útiles (no molesta y te confirma que sí insertó)
                    msgOk += ' (Grupos: ' + hasGroups + ')';
                    showNotice('success', msgOk);
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

            var btn = $(this);
            if (btn.hasClass('is-loading') || btn.prop('disabled')) return;

            var confirmed = confirm(i18n.confirmPush || '¿Enviar los cambios directamente a Google Business Profile?');
            if (!confirmed) return;

            var saveFirst = saveFormFirst();
            saveFirst.then(function () {
                showNotice('info', i18n.pushing || 'Enviando a Google...');
                setButtonLoading(btn, true);

                $.ajax({
                    url     : ajaxUrl,
                    method  : 'POST',
                    dataType: 'json',
                    data    : {
                        action  : 'oy_gmb_more_push_to_gmb',
                        nonce   : nonce,
                        post_id : postId
                    },
                    success: function (response) {
                        setButtonLoading(btn, false);

                        if (response.success) {
                            showNotice('success', response.data.message || (i18n.pushDone || 'Cambios enviados a Google.'));
                            markHasChanges(false);
                            btn.removeClass('button-primary').addClass('button-secondary');
                        } else {
                            var msg = (response.data && response.data.message)
                                ? response.data.message
                                : (i18n.pushError || 'Error al enviar a Google.');
                            showNotice('error', msg);
                        }
                    },
                    error: function (xhr, status, error) {
                        setButtonLoading(btn, false);
                        showNotice('error', (i18n.pushError || 'Error al enviar a Google.') + ' (' + error + ')');
                        if (window.console) console.error('[OY GMB More] AJAX error (push):', status, error);
                    }
                });
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
        if (loading) {
            btn.addClass('is-loading').prop('disabled', true).css('opacity', '0.7');
        } else {
            btn.removeClass('is-loading').prop('disabled', false).css('opacity', '1');
        }
    }

    function saveFormFirst() {
        return new Promise(function (resolve) {
            var publishBtn = $('#publish, #save-post');
            if (publishBtn.length) {
                if ($('#oy_gmb_more_has_changes_' + postId).val() === '1') {
                    publishBtn.first().trigger('click');
                    setTimeout(resolve, 800);
                } else {
                    resolve();
                }
            } else {
                resolve();
            }
        });
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})(jQuery);
