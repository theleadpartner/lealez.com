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
            
            const businessId = lealezGMBData.businessId;
            
            if (!businessId || businessId === '0') {
                alert(lealezGMBData.i18n.saveFirst);
                return;
            }

            const $button = $(e.currentTarget);
            $button.prop('disabled', true).text(lealezGMBData.i18n.processing);

            $.ajax({
                url: lealezGMBData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lealez_gmb_get_auth_url',
                    nonce: lealezGMBData.nonce,
                    business_id: businessId
                },
                success: function(response) {
                    if (response.success && response.data.auth_url) {
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
                            alert('Por favor, permite las ventanas emergentes para conectar con Google.');
                            $button.prop('disabled', false).text('Conectar con Google My Business');
                            return;
                        }

                        // Monitor popup
                        const checkPopup = setInterval(function() {
                            if (popup.closed) {
                                clearInterval(checkPopup);
                                // Reload page to show updated connection status
                                location.reload();
                            }
                        }, 1000);

                    } else {
                        alert(response.data.message || lealezGMBData.i18n.error);
                        $button.prop('disabled', false).text('Conectar con Google My Business');
                    }
                },
                error: function() {
                    alert(lealezGMBData.i18n.error);
                    $button.prop('disabled', false).text('Conectar con Google My Business');
                }
            });
        },

        /**
         * Disconnect from GMB
         */
        disconnectGMB: function(e) {
            e.preventDefault();
            
            if (!confirm(lealezGMBData.i18n.confirmDisconnect)) {
                return;
            }

            const $button = $(e.currentTarget);
            $button.prop('disabled', true).text(lealezGMBData.i18n.processing);

            $.ajax({
                url: lealezGMBData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lealez_gmb_disconnect',
                    nonce: lealezGMBData.nonce,
                    business_id: lealezGMBData.businessId
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || lealezGMBData.i18n.error);
                        $button.prop('disabled', false).text('Desconectar Cuenta');
                    }
                },
                error: function() {
                    alert(lealezGMBData.i18n.error);
                    $button.prop('disabled', false).text('Desconectar Cuenta');
                }
            });
        },

        /**
         * Refresh GMB locations
         */
        refreshLocations: function(e) {
            e.preventDefault();
            
            const $button = $(e.currentTarget);
            $button.prop('disabled', true).text(lealezGMBData.i18n.processing);

            $.ajax({
                url: lealezGMBData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lealez_gmb_refresh_locations',
                    nonce: lealezGMBData.nonce,
                    business_id: lealezGMBData.businessId
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || lealezGMBData.i18n.error);
                        $button.prop('disabled', false).text('Actualizar Ubicaciones');
                    }
                },
                error: function() {
                    alert(lealezGMBData.i18n.error);
                    $button.prop('disabled', false).text('Actualizar Ubicaciones');
                }
            });
        },

        /**
         * Test GMB connection
         */
        testConnection: function(e) {
            e.preventDefault();
            
            const $button = $(e.currentTarget);
            $button.prop('disabled', true).text(lealezGMBData.i18n.processing);

            $.ajax({
                url: lealezGMBData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lealez_gmb_test_connection',
                    nonce: lealezGMBData.nonce,
                    business_id: lealezGMBData.businessId
                },
                success: function(response) {
                    if (response.success) {
                        alert('✓ ' + response.data.message);
                    } else {
                        alert('✗ ' + (response.data.message || lealezGMBData.i18n.error));
                    }
                    $button.prop('disabled', false).text('Probar Conexión');
                },
                error: function() {
                    alert(lealezGMBData.i18n.error);
                    $button.prop('disabled', false).text('Probar Conexión');
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        LealezGMB.init();
    });

})(jQuery);
