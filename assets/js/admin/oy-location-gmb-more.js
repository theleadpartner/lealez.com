/**
 * OY Location - GMB "Más" Attributes Metabox JavaScript
 *
 * Maneja las interacciones del metabox de atributos dinámicos:
 * - Detectar cambios en campos y marcar el estado "modificado"
 * - Botón "Actualizar metadatos" → AJAX refresh y recarga de página
 * - Botón "Enviar a Google ↑" → AJAX push a GMB API
 *
 * @package Lealez
 * @since 1.0.0
 */

(function ($) {
    'use strict';

    // Configuración inyectada por wp_localize_script
    var config = (typeof oyGmbMoreConfig !== 'undefined') ? oyGmbMoreConfig : {};
    var ajaxUrl = config.ajaxUrl || '';
    var nonce   = config.nonce   || '';
    var postId  = config.postId  || 0;
    var i18n    = config.i18n    || {};

    // =========================================================================
    // INIT
    // =========================================================================

    $(document).ready(function () {
        if (!postId) return;

        initChangeTracking();
        initRefreshButton();
        initPushButton();
    });

    // =========================================================================
    // CHANGE TRACKING
    // =========================================================================

    /**
     * Detecta cambios en cualquier campo del metabox y marca el estado.
     */
    function initChangeTracking() {
        var wrapper = $('#oy-gmb-more-metabox-' + postId);
        if (!wrapper.length) return;

        // Escuchar cambios en inputs, selects y textareas del metabox
        wrapper.on('change', '.oy-gmb-more-field-input', function () {
            markHasChanges(true);
        });
    }

    /**
     * Marca o desmarca el campo oculto de "tiene cambios pendientes".
     *
     * @param {boolean} hasChanges
     */
    function markHasChanges(hasChanges) {
        $('#oy_gmb_more_has_changes_' + postId).val(hasChanges ? '1' : '0');

        var pushBtn = $('.oy-gmb-more-btn-push');
        if (hasChanges) {
            pushBtn.addClass('button-primary').removeClass('button-secondary');
        }
    }

    // =========================================================================
    // REFRESH METADATA BUTTON
    // =========================================================================

    /**
     * Inicializa el botón "Actualizar metadatos".
     */
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

                        // Si el servidor pide reload, recargar la página tras 1.5 s
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

    // =========================================================================
    // PUSH TO GMB BUTTON
    // =========================================================================

    /**
     * Inicializa el botón "Enviar a Google ↑".
     */
    function initPushButton() {
        $(document).on('click', '.oy-gmb-more-btn-push', function (e) {
            e.preventDefault();

            var btn = $(this);
            if (btn.hasClass('is-loading') || btn.prop('disabled')) return;

            var confirmed = confirm(i18n.confirmPush || '¿Enviar los cambios directamente a Google Business Profile?');
            if (!confirmed) return;

            // Primero guardar el formulario para que los overrides estén en la BD
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

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Muestra un mensaje de notificación en el metabox.
     *
     * @param {string} type    'success' | 'error' | 'info'
     * @param {string} message Texto del mensaje.
     */
    function showNotice(type, message) {
        var noticeEl = $('#oy-gmb-more-notice-' + postId);
        if (!noticeEl.length) return;

        noticeEl
            .removeClass('success error info')
            .addClass(type)
            .html('<strong>' + escapeHtml(message) + '</strong>')
            .fadeIn(200);

        // Auto-ocultar después de 6 s para success/info
        if (type !== 'error') {
            clearTimeout(noticeEl.data('hide-timer'));
            noticeEl.data('hide-timer', setTimeout(function () {
                noticeEl.fadeOut(400);
            }, 6000));
        }
    }

    /**
     * Activa o desactiva el estado de carga de un botón.
     *
     * @param {jQuery}  btn       El botón.
     * @param {boolean} loading   true = cargando.
     */
    function setButtonLoading(btn, loading) {
        if (loading) {
            btn.addClass('is-loading').prop('disabled', true).css('opacity', '0.7');
        } else {
            btn.removeClass('is-loading').prop('disabled', false).css('opacity', '1');
        }
    }

    /**
     * Guarda el formulario de manera silenciosa haciendo click en el botón
     * "Actualizar" de WordPress antes de un push.
     *
     * @returns {Promise}
     */
    function saveFormFirst() {
        return new Promise(function (resolve) {
            // Intentar trigger del save de WordPress
            var publishBtn = $('#publish, #save-post');
            if (publishBtn.length) {
                // Solo si el formulario tiene cambios reales
                if ($('#oy_gmb_more_has_changes_' + postId).val() === '1') {
                    // Disparar click en Actualizar
                    publishBtn.first().trigger('click');
                    // Esperar un momento para que WordPress procese
                    setTimeout(resolve, 800);
                } else {
                    resolve();
                }
            } else {
                resolve();
            }
        });
    }

    /**
     * Escapa HTML básico para mostrar en notificaciones.
     *
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})(jQuery);
