(function () {
    'use strict';

    function activateTab(form, tabName) {
        var buttons = form.querySelectorAll('[data-lealez-tab]');
        var panels = form.querySelectorAll('[data-lealez-panel]');

        buttons.forEach(function (button) {
            var active = button.getAttribute('data-lealez-tab') === tabName;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        panels.forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-lealez-panel') === tabName);
        });
    }

    function initTabs() {
        document.querySelectorAll('.lealez-tab-form').forEach(function (form, formIndex) {
            var buttons = form.querySelectorAll('[data-lealez-tab]');
            if (!buttons.length) {
                return;
            }

            buttons.forEach(function (button, buttonIndex) {
                button.setAttribute('role', 'tab');
                button.setAttribute('aria-selected', button.classList.contains('is-active') ? 'true' : 'false');
                button.id = button.id || 'lealez-tab-' + formIndex + '-' + buttonIndex;

                button.addEventListener('click', function () {
                    var tabName = button.getAttribute('data-lealez-tab');
                    activateTab(form, tabName);
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState(null, '', window.location.pathname + window.location.search + '#' + tabName);
                    }
                });
            });

            var hash = window.location.hash ? window.location.hash.substring(1) : '';
            if (hash && form.querySelector('[data-lealez-tab="' + hash + '"]')) {
                activateTab(form, hash);
            }
        });
    }

    function initConfirmations() {
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-lealez-confirm]');
            if (!trigger) {
                return;
            }

            var message = trigger.getAttribute('data-lealez-confirm');
            if (message && !window.confirm(message)) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
    }

    function reindexPeriods(day) {
        var dayKey = day.getAttribute('data-hours-day');
        day.querySelectorAll('.lealez-hours-period').forEach(function (period, index) {
            var open = period.querySelector('input[type="time"]:first-of-type');
            var close = period.querySelector('input[type="time"]:last-of-type');
            if (open) {
                open.name = 'location_hours[' + dayKey + '][periods][' + index + '][open]';
            }
            if (close) {
                close.name = 'location_hours[' + dayKey + '][periods][' + index + '][close]';
            }
        });
    }

    function buildPeriod(day) {
        var dayKey = day.getAttribute('data-hours-day');
        var wrapper = document.createElement('div');
        wrapper.className = 'lealez-hours-period';
        wrapper.innerHTML =
            '<input type="time" name="location_hours[' + dayKey + '][periods][0][open]" value="09:00">' +
            '<span aria-hidden="true">—</span>' +
            '<input type="time" name="location_hours[' + dayKey + '][periods][0][close]" value="18:00">' +
            '<button type="button" class="lealez-hours-remove" aria-label="Eliminar intervalo">×</button>';
        return wrapper;
    }

    function refreshDayState(day) {
        var closed = day.querySelector('.lealez-hours-closed');
        var allDay = day.querySelector('.lealez-hours-all-day');
        var disabled = Boolean((closed && closed.checked) || (allDay && allDay.checked));

        day.classList.toggle('is-disabled', disabled);
        day.querySelectorAll('.lealez-hours-period input').forEach(function (input) {
            input.readOnly = disabled;
        });
        day.querySelectorAll('.lealez-hours-remove, .lealez-hours-add').forEach(function (button) {
            button.disabled = disabled;
        });
    }

    function initHours() {
        document.querySelectorAll('.lealez-hours-day').forEach(function (day) {
            refreshDayState(day);
            reindexPeriods(day);
        });

        document.addEventListener('change', function (event) {
            var checkbox = event.target.closest('.lealez-hours-closed, .lealez-hours-all-day');
            if (!checkbox) {
                return;
            }

            var day = checkbox.closest('.lealez-hours-day');
            if (!day) {
                return;
            }

            if (checkbox.checked) {
                if (checkbox.classList.contains('lealez-hours-closed')) {
                    var allDay = day.querySelector('.lealez-hours-all-day');
                    if (allDay) {
                        allDay.checked = false;
                    }
                } else {
                    var closed = day.querySelector('.lealez-hours-closed');
                    if (closed) {
                        closed.checked = false;
                    }
                }
            }

            refreshDayState(day);
        });

        document.addEventListener('click', function (event) {
            var add = event.target.closest('.lealez-hours-add');
            if (add) {
                var addDay = add.closest('.lealez-hours-day');
                var periods = addDay ? addDay.querySelector('.lealez-hours-periods') : null;
                if (addDay && periods) {
                    periods.appendChild(buildPeriod(addDay));
                    reindexPeriods(addDay);
                }
                return;
            }

            var remove = event.target.closest('.lealez-hours-remove');
            if (!remove) {
                return;
            }

            var removeDay = remove.closest('.lealez-hours-day');
            var removePeriods = removeDay ? removeDay.querySelector('.lealez-hours-periods') : null;
            var row = remove.closest('.lealez-hours-period');
            if (!removeDay || !removePeriods || !row) {
                return;
            }

            row.remove();
            if (!removePeriods.querySelector('.lealez-hours-period')) {
                removePeriods.appendChild(buildPeriod(removeDay));
            }
            reindexPeriods(removeDay);
        });

        document.querySelectorAll('.lealez-tab-form').forEach(function (form) {
            form.addEventListener('submit', function () {
                form.querySelectorAll('.lealez-hours-day').forEach(function (day) {
                    reindexPeriods(day);
                });
            });
        });
    }

    function init() {
        initTabs();
        initConfirmations();
        initHours();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
