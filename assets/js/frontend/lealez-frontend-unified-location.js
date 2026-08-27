(function ($) {
    'use strict';

    var config = window.lealezUnifiedLocation || {};

    function chip(label, cssClass) {
        return $('<span>', {
            'class': 'lealez-sync-chip lealez-field-label-scope ' + cssClass,
            text: label
        });
    }

    function annotateField(selector, label, cssClass) {
        $(selector).each(function () {
            var $field = $(this);
            var id = $field.attr('id');
            var $label = id ? $('label[for="' + id.replace(/"/g, '\\"') + '"]').first() : $field.closest('.lealez-field').find('label').first();

            if (!$label.length || $label.find('.lealez-field-label-scope').length) {
                return;
            }

            $label.append(chip(label, cssClass));
        });
    }

    function annotateCreationForm() {
        var $form = $('.lealez-portal form.lealez-form').filter(function () {
            return $(this).find('input[name="lealez_frontend_action"][value="save_location"]').length > 0;
        });

        if (!$form.length) {
            return;
        }

        var googleSelectors = [
            '#location_short_description', '#opening_date',
            '#service_area_only', '#show_address_to_customers', '#location_address_line1', '#location_address_line2',
            '#location_neighborhood', '#location_city', '#location_state', '#location_country', '#location_postal_code',
            '#location_latitude', '#location_longitude', '#location_maps_url', '#location_service_areas_text',
            '#location_phone', '#location_additional_phones', '#location_website', '#location_menu_url',
            '#location_chat_enabled', '#location_chat_type', '#location_chat_url', '#location_booking_urls_text',
            '#location_order_urls_text', '[name^="location_hours"]'
        ];

        var localSelectors = [
            '#parent_business_id', '#location_title', '#location_code', '#location_status',
            '#google_primary_category', '#price_range', '#social_facebook_local', '#social_instagram_local',
            '#social_tiktok_local', '#social_x_local', '#accepts_loyalty', '#loyalty_earning_enabled',
            '#loyalty_redemption_enabled', '#loyalty_multiplier', '#loyalty_terminal_id', '#location_manager',
            '#location_manager_email', '#location_manager_phone', '#manager_notes', '#internal_notes'
        ];

        annotateField($form.find(googleSelectors.join(',')), config.googleLabel || 'Se puede publicar', 'is-google-write');
        annotateField($form.find(localSelectors.join(',')), config.localLabel || 'Solo en Lealez', 'is-internal');
    }

    /**
     * The original admin metaboxes are intentionally reused in the customer
     * portal so their save/push logic stays intact. Some of those metaboxes were
     * written for technical administrators and can include Google resource
     * names in explanatory text. For customer users we only clean visible text
     * nodes; form values, hidden fields and AJAX payloads are never touched.
     */
    function sanitizeCustomerTechnicalCopy() {
        if (parseInt(config.technicalAdmin, 10) === 1 || $('.lealez-unified-location-profile .lealez-gmb-nav-item[href*="section=connection"]').length) {
            return;
        }

        var root = document.querySelector('.lealez-unified-location-profile .lealez-gmb-main');
        if (!root || !document.createTreeWalker) {
            return;
        }

        var skipTags = {
            SCRIPT: true,
            STYLE: true,
            INPUT: true,
            TEXTAREA: true,
            SELECT: true,
            OPTION: true,
            CODE: true,
            PRE: true
        };

        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                var parent = node.parentElement;
                if (!parent || skipTags[parent.tagName]) {
                    return NodeFilter.FILTER_REJECT;
                }
                return NodeFilter.FILTER_ACCEPT;
            }
        });

        var nodes = [];
        var node;
        while ((node = walker.nextNode())) {
            nodes.push(node);
        }

        nodes.forEach(function (textNode) {
            var text = textNode.nodeValue || '';
            var cleaned = text
                .replace(/categories\.primaryCategory/gi, 'Categoría principal')
                .replace(/categories\.additionalCategories/gi, 'Categorías adicionales')
                .replace(/phoneNumbers\.primaryPhone/gi, 'Teléfono principal')
                .replace(/phoneNumbers\.additionalPhones/gi, 'Teléfonos adicionales')
                .replace(/metadata\.placeId\s*:?\s*[A-Za-z0-9_-]*/gi, '')
                .replace(/(?:categories\/)?gcid:[A-Za-z0-9_:-]+/gi, '')
                .replace(/accounts\/[A-Za-z0-9_-]+\/locations\/[A-Za-z0-9_-]+/gi, '')
                .replace(/\blocations\/[A-Za-z0-9_-]+\b/gi, '')
                .replace(/\s{2,}/g, ' ')
                .replace(/\s+([,.;:)])/g, '$1');

            if (cleaned !== text) {
                textNode.nodeValue = cleaned;
            }
        });
    }

    $(function () {
        annotateCreationForm();
        sanitizeCustomerTechnicalCopy();
    });
})(jQuery);
