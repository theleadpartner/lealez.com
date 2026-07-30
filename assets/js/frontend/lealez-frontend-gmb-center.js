(function ($) {
    'use strict';

    window.ajaxurl = window.ajaxurl || (window.lealezGmbFrontend ? window.lealezGmbFrontend.ajaxUrl : '');

    $(document).on('click', '[data-lealez-gmb-confirm]', function (event) {
        var message = $(this).data('lealez-gmb-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });
})(jQuery);
