/**
 * OY Location Admin JavaScript
 * 
 * Este archivo maneja funcionalidades adicionales del admin de Location CPT.
 * El código principal de GMB está inline en el metabox por ahora.
 * 
 * @package Lealez
 * @since 1.0.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Placeholder para funcionalidad futura
        // El código principal de GMB import está inline en el metabox
        
        // Debug helper
        if (typeof console !== 'undefined' && window.location.search.indexOf('debug=1') !== -1) {
            console.log('OY Location Admin JS loaded');
            if (typeof oyLocationGmb !== 'undefined') {
                console.log('oyLocationGmb config:', oyLocationGmb);
            }
        }
    });

})(jQuery);
