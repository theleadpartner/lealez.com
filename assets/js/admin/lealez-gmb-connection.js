/**
 * Lealez GMB Connection Handler
 * 
 * Handles Google My Business OAuth connection flow
 */
(function($) {
    'use strict';

    const LealezGMB = {

        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Safe i18n getter
         */
        t: function(key, fallback) {
            try {
                if (typeof lealezGMBData !== 'undefined' && lealezGMBData.i18n && lealezGMBData.i18n[key]) {
                    return lealezGMBData.i18n[key];
                }
            } catch (e) {}
            return fallback;
        },

        /**
         * Validate localized data exists
         */
        ensureData: function() {
            if (typeof lealezGMBData === 'undefined' || !lealezGMBData) {
                alert('Error: lealezGMBData no está disponible. Revisa wp_enqueue_script/wp_localize_script.');
                return false;
            }
            return true;
        },

        /**
         * Button loading helper
         */
        setButtonLoading: function($btn, loading, loadingText, fallbackRestoreText) {
            if (!$btn || !$btn.length) return;

            if (loading) {
                if (!$btn.data('lealez-original-text')) {
                    $btn.data('lealez-original-text', $btn.text());
                }
                $btn.prop('disabled', true).text(loadingText);
            } else {
                const original = $btn.data('lealez-original-text');
                $btn.prop('disabled', false).text(original || fallbackRestoreText || '');
                $btn.removeData('lealez-original-text');
            }
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            $(document).on('click', '.lealez-connect-gmb', this.connectGMB.bind(this));
            $(document).on('click', '.lealez-disconnect-gmb', this.disconnectGMB.bind(this));
            $(document).on('click', '.lealez-refresh-gmb-locations', this.refreshLocations.bind(this));
            $(document).on('click', '.lealez-test-gmb-connection', this.testConnection.bind(this));
        },

        /**
         * Connect to GMB
         */
        connectGMB: function(e) {
            e.preventDefault();

            if (!this.ensureData()) return;

            const businessId = lealezGMBData.businessId;

            if (!businessId || businessId === '0') {
                alert(this.t('saveFirst', 'Por favor guarda el post primero'));
                return;
            }

            const $button = $(e.currentTarget);
            this.setButtonLoading($button, true, this.t('processing', 'Procesando...'), this.t('connectBtn', 'Conectar con Google My Business'));

            $.ajax({
                url: lealezGMBData.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'lealez_gmb_get_auth_url',
                    nonce: lealezGMBData.nonce,
                    business_id: businessId
                },
                success: (response) => {
                    if (response && response.success && response.data && response.data.auth_url) {

                        // Open popup window
                        const width = 600;
                        const height = 700;
                        const left = (screen.width - width) / 2;
                        const top = (screen.height - height) / 2;

                        const popup = window.open(
                            response.data.auth_url,
                            'gmb_oauth',
                            `width=${width},height=${height},left=${left},top=${top},toolbar=0,menubar=0,location=0`
                        );

                        // Check if popup was blocked
                        if (!popup || popup.closed || typeof popup.closed === 'undefined') {
                            alert(this.t('popupBlocked', 'Por favor, permite las ventanas emergentes para conectar con Google.'));
                            this.setButtonLoading($button, false, '', this.t('connectBtn', 'Conectar con Google My Business'));
                            return;
                        }

                        // Monitor popup (cuando cierre, recargamos)
                        const checkPopup = setInterval(function() {
                            if (popup.closed) {
                                clearInterval(checkPopup);
                                location.reload();
                            }
                        }, 1000);

                    } else {
                        const msg = (response && response.data && response.data.message)
                            ? response.data.message
                            : this.t('error', 'Error');

                        alert(msg);
                        this.setButtonLoading($button, false, '', this.t('connectBtn', 'Conectar con Google My Business'));
                    }
                },
                error: (xhr) => {
                    let msg = this.t('error', 'Error');

                    // Intentar extraer mensaje si vino JSON
                    try {
                        const r = xhr.responseJSON;
                        if (r && r.data && r.data.message) msg = r.data.message;
                    } catch (e) {}

                    alert(msg);
                    this.setButtonLoading($button, false, '', this.t('connectBtn', 'Conectar con Google My Business'));
                }
            });
        },

        /**
         * Disconnect from GMB
         */
        disconnectGMB: function(e) {
            e.preventDefault();

            if (!this.ensureData()) return;

            if (!confirm(this.t('confirmDisconnect', '¿Estás seguro de que deseas desconectar la cuenta de Google My Business?'))) {
                return;
            }

            const $button = $(e.currentTarget);
            this.setButtonLoading($button, true, this.t('processing', 'Procesando...'), this.t('disconnectBtn', 'Desconectar Cuenta'));

            $.ajax({
                url: lealezGMBData.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'lealez_gmb_disconnect',
                    nonce: lealezGMBData.nonce,
                    business_id: lealezGMBData.businessId
                },
                success: (response) => {
                    if (response && response.success) {
                        location.reload();
                    } else {
                        const msg = (response && response.data && response.data.message)
                            ? response.data.message
                            : this.t('error', 'Error');

                        alert(msg);
                        this.setButtonLoading($button, false, '', this.t('disconnectBtn', 'Desconectar Cuenta'));
                    }
                },
                error: (xhr) => {
                    let msg = this.t('error', 'Error');
                    try {
                        const r = xhr.responseJSON;
                        if (r && r.data && r.data.message) msg = r.data.message;
                    } catch (e) {}

                    alert(msg);
                    this.setButtonLoading($button, false, '', this.t('disconnectBtn', 'Desconectar Cuenta'));
                }
            });
        },

        /**
         * Refresh GMB locations
         */
refreshLocations: function(e) {
    e.preventDefault();

    if (!this.ensureData()) return;

    const $button = $(e.currentTarget);
    this.setButtonLoading($button, true, this.t('processing', 'Procesando...'), this.t('refreshBtn', 'Actualizar Ubicaciones'));

    $.ajax({
        url: lealezGMBData.ajaxUrl,
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'lealez_gmb_refresh_locations',
            nonce: lealezGMBData.nonce,
            business_id: lealezGMBData.businessId
        },
        success: (response) => {
            if (response && response.success) {
                const msg = (response.data && response.data.message) ? response.data.message : 'OK';
                alert(msg);
                location.reload();
                return;
            }

            const msg = (response && response.data && response.data.message)
                ? response.data.message
                : this.t('error', 'Error');

            alert(msg);

            // ✅ Si el backend programó un cron, recargamos para que el metabox muestre "programado para..."
            if (response && response.data && response.data.scheduled_for) {
                location.reload();
                return;
            }

            this.setButtonLoading($button, false, '', this.t('refreshBtn', 'Actualizar Ubicaciones'));
        },
        error: (xhr) => {
            let msg = this.t('error', 'Error');
            let scheduled = false;

            try {
                const r = xhr.responseJSON;
                if (r && r.data && r.data.message) msg = r.data.message;
                if (r && r.data && r.data.scheduled_for) scheduled = true;
            } catch (e) {}

            alert(msg);

            // ✅ Si vino “scheduled_for”, recargamos igual
            if (scheduled) {
                location.reload();
                return;
            }

            this.setButtonLoading($button, false, '', this.t('refreshBtn', 'Actualizar Ubicaciones'));
        }
    });
},


        /**
         * Test GMB connection
         */
        testConnection: function(e) {
            e.preventDefault();

            if (!this.ensureData()) return;

            const $button = $(e.currentTarget);
            this.setButtonLoading($button, true, this.t('processing', 'Procesando...'), this.t('testBtn', 'Probar Conexión'));

            $.ajax({
                url: lealezGMBData.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'lealez_gmb_test_connection',
                    nonce: lealezGMBData.nonce,
                    business_id: lealezGMBData.businessId
                },
                success: (response) => {
                    if (response && response.success) {
                        const msg = (response.data && response.data.message) ? response.data.message : 'OK';
                        alert('✓ ' + msg);
                    } else {
                        const msg = (response && response.data && response.data.message)
                            ? response.data.message
                            : this.t('error', 'Error');

                        alert('✗ ' + msg);
                    }
                    this.setButtonLoading($button, false, '', this.t('testBtn', 'Probar Conexión'));
                },
                error: (xhr) => {
                    let msg = this.t('error', 'Error');
                    try {
                        const r = xhr.responseJSON;
                        if (r && r.data && r.data.message) msg = r.data.message;
                    } catch (e) {}

                    alert(msg);
                    this.setButtonLoading($button, false, '', this.t('testBtn', 'Probar Conexión'));
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        LealezGMB.init();
    });

})(jQuery);
