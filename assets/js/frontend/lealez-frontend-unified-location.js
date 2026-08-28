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
            '#location_short_description', '#opening_date', '#google_primary_category',
            '#service_area_only', '#show_address_to_customers', '#location_address_line1', '#location_address_line2',
            '#location_neighborhood', '#location_city', '#location_state', '#location_country', '#location_postal_code',
            '#location_latitude', '#location_longitude', '#location_maps_url', '#location_service_areas_text',
            '#location_phone', '#location_additional_phones', '#location_website', '#location_menu_url',
            '#location_chat_enabled', '#location_chat_type', '#location_chat_url', '#location_booking_urls_text',
            '#location_order_urls_text', '[name^="location_hours"]'
        ];

        var localSelectors = [
            '#parent_business_id', '#location_title', '#location_code', '#location_status',
            '#price_range', '#social_facebook_local', '#social_instagram_local',
            '#social_tiktok_local', '#social_x_local', '#accepts_loyalty', '#loyalty_earning_enabled',
            '#loyalty_redemption_enabled', '#loyalty_multiplier', '#loyalty_terminal_id', '#location_manager',
            '#location_manager_email', '#location_manager_phone', '#manager_notes', '#internal_notes'
        ];

        annotateField($form.find(googleSelectors.join(',')), config.googleLabel || 'Publica en Google', 'is-google-write');
        annotateField($form.find(localSelectors.join(',')), config.localLabel || 'Solo Lealez', 'is-internal');
    }

    function replaceTextNode($element, replacements) {
        $element.contents().filter(function () {
            return this.nodeType === 3 && $.trim(this.nodeValue).length;
        }).each(function () {
            var text = this.nodeValue;
            replacements.forEach(function (item) {
                text = text.replace(item[0], item[1]);
            });
            this.nodeValue = text;
        });
    }

    function applyFriendlyClientCopy($profile) {
        var replacements = [
            [/Google My Business/g, 'Google Business Profile'],
            [/Sincronizar Todo Ahora/g, 'Sincronizar perfil'],
            [/Sincronizar Horario desde GMB/g, 'Actualizar horarios desde Google'],
            [/Sincronizar desde GMB/g, 'Actualizar desde Google'],
            [/Sincronizar desde Google My Business/g, 'Actualizar desde Google'],
            [/Enviar a GMB/g, 'Publicar en Google'],
            [/Enviar a Google ↑/g, 'Publicar en Google'],
            [/Guardar cambios del metabox/g, 'Guardar en Lealez'],
            [/Guardar metabox/g, 'Guardar en Lealez'],
            [/cambios locales del metabox/g, 'cambios en Lealez'],
            [/metabox/g, 'sección'],
            [/Metabox/g, 'Sección'],
            [/Actualizar metadatos/g, 'Actualizar opciones disponibles'],
            [/metadatos de atributos/g, 'opciones de características'],
            [/Metadatos de atributos/g, 'Opciones de características'],
            [/Agregar los atributos/g, 'Cargar características'],
            [/Editar atributos/g, 'Editar características'],
            [/Atributos/g, 'Características'],
            [/atributos/g, 'características'],
            [/Última sync:/g, 'Última actualización:'],
            [/Última sincronización \(botón Horarios\):/g, 'Última actualización de horarios:'],
            [/Log de Sincronización/g, 'Historial de sincronización'],
            [/Log de Horarios de Atención/g, 'Historial de horarios']
        ];

        $profile.find('button, a, strong, h2, h3, h4, label, p, span, th, dt').each(function () {
            replaceTextNode($(this), replacements);
        });
    }

    function hideTechnicalClientDetails($profile) {
        var technicalLabelPatterns = [
            /^\s*(GMB\s*)?(Account|Location)\s*ID\s*:?\s*$/i,
            /^\s*Resource\s*Name\s*:?\s*$/i,
            /^\s*Google\s*\(RAW.*$/i,
            /^\s*RAW\s*:?\s*$/i,
            /^\s*updateMask\s*:?\s*$/i,
            /^\s*fieldMask\s*:?\s*$/i,
            /^\s*endpoint\s*:?\s*$/i,
            /^\s*payload\s*:?\s*$/i,
            /^\s*request\s*id\s*:?\s*$/i,
            /^\s*storeCode\s*:?\s*$/i,
            /^\s*placeId\s*:?\s*$/i
        ];

        $profile.find('th, dt, label, strong, p, span').each(function () {
            var $node = $(this);
            var text = $.trim($node.clone().children().remove().end().text());

            if (!text || text.length > 120) {
                return;
            }

            var isTechnical = technicalLabelPatterns.some(function (pattern) {
                return pattern.test(text);
            });

            if (!isTechnical) {
                return;
            }

            var $container = $node.closest('tr, .oy-raw-json, .oy-technical-detail, details, li, p');
            if (!$container.length) {
                $container = $node.parent();
            }
            $container.addClass('lealez-client-technical-hidden').attr('aria-hidden', 'true');
        });

        $profile.find('code').each(function () {
            var $code = $(this);
            var text = $.trim($code.text());
            if (!text) {
                return;
            }

            if (/^(accounts\/|locations\/|categories\/|gcid:)/i.test(text) ||
                /(^|\b)(updateMask|fieldMask|placeId|primaryCategory|primaryPhone)(\b|\.)/i.test(text) ||
                /^[a-zA-Z][a-zA-Z0-9_]*\.[a-zA-Z][a-zA-Z0-9_.]*$/.test(text)) {
                $code.addClass('lealez-client-technical-hidden').attr('aria-hidden', 'true');
            }
        });
    }

    function prepareClientProfile() {
        var $profile = $('.lealez-unified-location-profile[data-lealez-client-profile="1"]');
        if (!$profile.length) {
            return;
        }

        applyFriendlyClientCopy($profile);
        hideTechnicalClientDetails($profile);

        // Some metaboxes repaint their status panels after AJAX. Re-apply only
        // presentation rules; no handler, nonce or payload is modified.
        var observer = new MutationObserver(function (mutations) {
            var shouldRefresh = mutations.some(function (mutation) {
                return mutation.addedNodes && mutation.addedNodes.length;
            });
            if (shouldRefresh) {
                window.requestAnimationFrame(function () {
                    applyFriendlyClientCopy($profile);
                    hideTechnicalClientDetails($profile);
                });
            }
        });

        observer.observe($profile.get(0), { childList: true, subtree: true });
    }

    $(function () {
        annotateCreationForm();
        prepareClientProfile();
    });
})(jQuery);
