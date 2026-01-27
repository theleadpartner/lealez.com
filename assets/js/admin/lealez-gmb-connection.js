/**
 * Lealez GMB Connection Handler
 * 
 * Handles Google My Business connection UI interactions
 *
 * @package Lealez
 * @since 1.0.0
 */

(function($) {
    'use strict';

    var LealezGMB = {
        
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
            $(document).on('click', '.lealez-connect-gmb', this.connectGMB);
            $(document).on('click', '.lealez-disconnect-gmb', this.disconnectGMB);
            $(document).on('click', '.lealez-refresh-gmb-locations', this.refreshLocations);
            $(document).on('click', '.lealez-test-gmb-connection', this.testConnection);
        },

        /**
         * Get business ID
         */
        getBusinessId: function() {
            return $('#post_ID').val() || lealezGMBData.businessId || 0;
        },

        /**
         * Show loading
         */
        showLoading: function(button) {
            button.prop('disabled', true);
            button.data('original-text', button.text());
            button.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> ' + lealezGMBData.i18n.processing);
        },

        /**
         * Hide loading
         */
        hideLoading: function(button) {
            button.prop('disabled', false);
            button.text(button.data('original-text') || button.text());
        },

        /**
         * Show notice
         */
        showNotice: function(message, type) {
            type = type || 'success';
            var noticeClass = 'notice-' + type;
            var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
            
            $('.wrap h1').after(notice);
            
            setTimeout(function() {
                notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Connect to GMB
         */
        connectGMB: function(e) {
            e.preventDefault();
            var button = $(this);
            var businessId = LealezGMB.getBusinessId();

            if (!businessId) {
                alert(lealezGMBData.i18n.saveFirst);
                return;
            }

            LealezGMB.showLoading(button);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'lealez_gmb_get_auth_url',
                    business_id: businessId,
                    nonce: lealezGMBData.nonce
                },
                success: function(response) {
                    LealezGMB.hideLoading(button);
                    
                    if (response.success && response.data.auth_url) {
                        // Open OAuth window
                        var width = 600;
                        var height = 700;
                        var left = (screen.width - width) / 2;
                        var top = (screen.height - height) / 2;
                        
                        var authWindow = window.open(
                            response.data.auth_url,
                            'gmb_auth',
                            'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top
                        );

                        // Check if window was closed
                        var checkWindow = setInterval(function() {
                            if (authWindow.closed) {
                                clearInterval(checkWindow);
                                // Reload page to show connection status
                                location.reload();
                            }
                        }, 1000);
                    } else {
                        LealezGMB.showNotice(response.data.message || lealezGMBData.i18n.error, 'error');
                    }
                },
                error: function() {
                    LealezGMB.hideLoading(button);
                    LealezGMB.showNotice(lealezGMBData.i18n.error, 'error');
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

            var button = $(this);
            var businessId = LealezGMB.getBusinessId();

            LealezGMB.showLoading(button);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'lealez_gmb_disconnect',
                    business_id: businessId,
                    nonce: lealezGMBData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        LealezGMB.showNotice(response.data.message, 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        LealezGMB.hideLoading(button);
                        LealezGMB.showNotice(response.data.message || lealezGMBData.i18n.error, 'error');
                    }
                },
                error: function() {
                    LealezGMB.hideLoading(button);
                    LealezGMB.showNotice(lealezGMBData.i18n.error, 'error');
                }
            });
        },

        /**
         * Refresh locations
         */
        refreshLocations: function(e) {
            e.preventDefault();
            var button = $(this);
            var businessId = LealezGMB.getBusinessId();

            LealezGMB.showLoading(button);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'lealez_gmb_refresh_locations',
                    business_id: businessId,
                    nonce: lealezGMBData.nonce
                },
                success: function(response) {
                    LealezGMB.hideLoading(button);
                    
                    if (response.success) {
                        LealezGMB.showNotice(response.data.message, 'success');
                        
                        // Optionally reload to show updated locations
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        LealezGMB.showNotice(response.data.message || lealezGMBData.i18n.error, 'error');
                    }
                },
                error: function() {
                    LealezGMB.hideLoading(button);
                    LealezGMB.showNotice(lealezGMBData.i18n.error, 'error');
                }
            });
        },

        /**
         * Test connection
         */
        testConnection: function(e) {
            e.preventDefault();
            var button = $(this);
            var businessId = LealezGMB.getBusinessId();

            LealezGMB.showLoading(button);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'lealez_gmb_test_connection',
                    business_id: businessId,
                    nonce: lealezGMBData.nonce
                },
                success: function(response) {
                    LealezGMB.hideLoading(button);
                    
                    if (response.success) {
                        LealezGMB.showNotice(response.data.message, 'success');
                    } else {
                        LealezGMB.showNotice(response.data.message || lealezGMBData.i18n.error, 'error');
                    }
                },
                error: function() {
                    LealezGMB.hideLoading(button);
                    LealezGMB.showNotice(lealezGMBData.i18n.error, 'error');
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        LealezGMB.init();
    });

})(jQuery);