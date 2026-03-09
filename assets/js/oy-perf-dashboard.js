/**
 * GMB Performance Dashboard — OyPerf
 *
 * Archivo: assets/js/oy-perf-dashboard.js
 * Depende de: jquery, chartjs-v4, oyPerfConfig (wp_localize_script)
 *
 * v1.2 - DEBUG EXHAUSTIVO + FIX CANVAS DEFINITIVO
 *  - Panel de debug visual dentro del metabox (eliminar cuando ya funcione)
 *  - console.log en cada paso de buildChart()
 *  - _buildId: evita que rAFs obsoletos ejecuten new Chart()
 *  - setTimeout(100ms) en lugar de doble rAF (más fiable en WP admin)
 *  - CSS fix: canvas con width/height 100% explícitos
 *  - Fallback de dimensiones: si contenedor mide 0, fuerza 400×280
 *
 * @package Lealez
 * @since   1.2.0
 */
(function($){
    'use strict';

    // =========================================================================
    // Guard: waitForChart
    // =========================================================================
    if (!window.waitForChart) {
        window._chartJSQueue  = window._chartJSQueue || [];
        window.waitForChart = function(fn){
            if (typeof Chart !== 'undefined') { fn(); return; }
            window._chartJSQueue.push(fn);
            if (!window._chartJSWatcher) {
                window._chartJSWatcher = setInterval(function(){
                    if (typeof Chart !== 'undefined') {
                        clearInterval(window._chartJSWatcher);
                        window._chartJSWatcher = null;
                        var q = window._chartJSQueue.splice(0);
                        q.forEach(function(f){ try { f(); } catch(e) { console.warn('[Chart]', e); } });
                    }
                }, 50);
            }
        };
    }

    // =========================================================================
    // OyPerf
    // =========================================================================
    var OyPerf = {

        postId        : oyPerfConfig.postId,
        nonce         : oyPerfConfig.nonce,
        ajaxUrl       : oyPerfConfig.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php',
        chartInstance : null,
        metricsDef    : oyPerfConfig.metricsDef,
        impKeys       : oyPerfConfig.impKeys,
        actKeys       : oyPerfConfig.actKeys,
        lastData      : null,
        sortAsc       : true,
        currentView   : 'all',
        _buildId      : 0,   // ← contador para invalidar rAFs obsoletos

        monthNames: ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'],

        // ---------------------------------------------------------------------
        // Init
        // ---------------------------------------------------------------------
        init: function(){
            var self = this;

            console.log('[OyPerf] init() — postId:', self.postId, '| ajaxUrl:', self.ajaxUrl);
            console.log('[OyPerf] Chart.js disponible en init():', typeof Chart !== 'undefined');

            self.populateMonthSelects();
            self.fetchMetrics(false);

            $('#oy-perf-period').on('change', function(){
                $('#oy-perf-month-range').toggle('month_range' === $(this).val());
            });

            $(document).on('change', '.oy-perf-metric-chk', function(){
                $(this).closest('.oy-perf-pill').toggleClass('oy-perf-pill--active', $(this).is(':checked'));
            });

            $('#oy-perf-select-all').on('click', function(){
                $('.oy-perf-metric-chk').prop('checked', true).trigger('change');
            });
            $('#oy-perf-select-none').on('click', function(){
                $('.oy-perf-metric-chk').prop('checked', false).trigger('change');
            });
            $('#oy-perf-select-impressions').on('click', function(){
                $('.oy-perf-metric-chk').prop('checked', false).trigger('change');
                $.each(self.impKeys, function(i, k){
                    $('.oy-perf-metric-chk[value="'+k+'"]').prop('checked', true).trigger('change');
                });
            });
            $('#oy-perf-select-actions').on('click', function(){
                $('.oy-perf-metric-chk').prop('checked', false).trigger('change');
                $.each(self.actKeys, function(i, k){
                    $('.oy-perf-metric-chk[value="'+k+'"]').prop('checked', true).trigger('change');
                });
            });

            $('#oy-perf-btn-apply').on('click',  function(){ self.fetchMetrics(false); });
            $('#oy-perf-btn-refresh').on('click', function(){ self.fetchMetrics(true); });
            $('#oy-perf-btn-sync').on('click',    function(){ self.syncMetrics(); });
            $('#oy-perf-btn-diag').on('click',    function(){ self.diagMetrics(); });

            $('#oy-perf-sort-date-asc').on('click', function(){
                self.sortAsc = true;
                if (self.lastData) { self.buildTable(self.lastData); }
            });
            $('#oy-perf-sort-date-desc').on('click', function(){
                self.sortAsc = false;
                if (self.lastData) { self.buildTable(self.lastData); }
            });

            $('#oy-perf-export-csv').on('click', function(){
                if (self.lastData) { self.exportCSV(self.lastData); }
            });

            $(document).on('click', '.oy-perf-view-btn', function(){
                self.setViewMode($(this).data('view'));
            });

            $('#oy-perf-chart-type').on('change', function(){
                if (self.lastData) {
                    console.log('[OyPerf] Chart type changed to:', $(this).val());
                    self.buildChart(self.lastData, $(this).val());
                }
            });

            $('#oy-perf-single-metric').on('change', function(){
                if (self.lastData) {
                    self.buildChart(self.lastData, $('#oy-perf-chart-type').val() || 'bar');
                }
            });

            // ── Auto-test de Chart.js ──────────────────────────────────────
            self.selfTestChartJS();
        },

        // ---------------------------------------------------------------------
        // Auto-test: verifica si Chart.js funciona con un canvas temporal
        // ---------------------------------------------------------------------
        selfTestChartJS: function(){
            var self = this;

            var doTest = function(){
                console.log('[OyPerf] selfTest — Chart object:', typeof Chart);
                if (typeof Chart === 'undefined') {
                    console.error('[OyPerf] selfTest FAILED: Chart.js no disponible en window');
                    self.debugLog('❌ Chart.js no está disponible (typeof Chart === undefined)');
                    return;
                }
                // Crear canvas temporal fuera del DOM
                var tc  = document.createElement('canvas');
                tc.width  = 200;
                tc.height = 100;
                document.body.appendChild(tc);
                try {
                    var ti = new Chart(tc, {
                        type: 'bar',
                        data: { labels: ['A'], datasets: [{ data: [1], backgroundColor: '#4285f4' }] },
                        options: { animation: false, responsive: false }
                    });
                    ti.destroy();
                    document.body.removeChild(tc);
                    console.log('[OyPerf] selfTest ✅ Chart.js funciona correctamente');
                    self.debugLog('✅ Chart.js v' + Chart.version + ' OK (auto-test pasado)');
                } catch(e) {
                    document.body.removeChild(tc);
                    console.error('[OyPerf] selfTest ❌ Error al crear chart de prueba:', e);
                    self.debugLog('❌ Chart.js error en auto-test: ' + e.message);
                }
            };

            if (typeof Chart !== 'undefined') {
                doTest();
            } else {
                waitForChart(doTest);
            }
        },

        // ---------------------------------------------------------------------
        // Month selects helpers
        // ---------------------------------------------------------------------
        populateMonthSelects: function(){
            var self = this;
            var now  = new Date();
            var opts = '';
            for (var i = 0; i <= 23; i++) {
                var d  = new Date(now.getFullYear(), now.getMonth() - i, 1);
                var y  = d.getFullYear();
                var m  = d.getMonth() + 1;
                var v  = y + '-' + String(m).padStart(2,'0');
                var lb = self.monthNames[m-1] + ' ' + y;
                opts += '<option value="'+v+'">'+lb+'</option>';
            }
            var $from = $('#oy-perf-month-from');
            var $to   = $('#oy-perf-month-to');
            $from.html(opts);
            $to.html(opts);
            var fromDate = new Date(now.getFullYear(), now.getMonth() - 5, 1);
            $from.val(fromDate.getFullYear() + '-' + String(fromDate.getMonth()+1).padStart(2,'0'));
            $to.val(now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0'));
        },

        // ---------------------------------------------------------------------
        // View Mode
        // ---------------------------------------------------------------------
        setViewMode: function(mode){
            var self = this;
            self.currentView = mode || 'all';
            $('.oy-perf-view-btn').removeClass('oy-perf-view-btn--active');
            $('.oy-perf-view-btn[data-view="'+self.currentView+'"]').addClass('oy-perf-view-btn--active');
            self.applyViewMode();
        },

        applyViewMode: function(){
            var self      = this;
            var showCards = false;
            var showChart = false;
            var showTable = false;

            switch (self.currentView) {
                case 'cards': showCards = true; break;
                case 'chart': showChart = true; break;
                case 'table': showTable = true; break;
                case 'all':
                default:
                    showCards = showChart = showTable = true;
                    break;
            }

            var hasSeries = self.lastData && self.lastData.data && Object.keys(self.lastData.data.series||{}).length > 0;
            var hasDates  = self.lastData && self.lastData.data && (self.lastData.data.dates||[]).length > 0;

            if (showCards && hasSeries)  { $('#oy-perf-kpis').show(); }
            else                         { $('#oy-perf-kpis').hide(); }

            if (showChart && hasSeries)  { $('#oy-perf-chart-wrap').show(); }
            else                         { $('#oy-perf-chart-wrap').hide(); }

            if (showTable && hasDates)   { $('#oy-perf-table-wrap').show(); }
            else                         { $('#oy-perf-table-wrap').hide(); }
        },

        // ---------------------------------------------------------------------
        // Core fetch
        // ---------------------------------------------------------------------
        getSelectedMetrics: function(){
            var metrics = [];
            $('.oy-perf-metric-chk:checked').each(function(){
                metrics.push($(this).val());
            });
            return metrics;
        },

        fetchMetrics: function(forceRefresh){
            var self    = this;
            var period  = $('#oy-perf-period').val();
            var metrics = self.getSelectedMetrics();

            if (!metrics.length) {
                self.showStatus('error', 'Selecciona al menos una métrica.');
                return;
            }

            var data = {
                action        : 'oy_gmb_perf_fetch',
                nonce         : self.nonce,
                post_id       : self.postId,
                period        : period,
                metrics       : metrics,
                force_refresh : forceRefresh ? 1 : 0,
            };

            if ('month_range' === period) {
                data.date_from = $('#oy-perf-month-from').val();
                data.date_to   = $('#oy-perf-month-to').val();
            }

            self.showStatus('loading', 'Consultando datos en Google Business Profile...');
            self.hideResults();

            $.post(self.ajaxUrl, data, function(resp){
                if (!resp.success) {
                    self.showStatus('error', resp.data.message || 'Error desconocido');
                    return;
                }

                self.hideStatus();
                var pd = resp.data;
                self.lastData = pd;

                var hasSeries = pd.data && pd.data.series && Object.keys(pd.data.series).length > 0;
                if (!hasSeries && pd.debug) {
                    var dbg  = pd.debug;
                    var hint = '⚠️ La API respondió HTTP 200 pero no devolvió datos de series. ';
                    hint += 'Location ID: <code>' + self.escHtml(dbg.location_id || '?') + '</code> | ';
                    hint += 'Outer wrappers: <code>' + dbg.outer_count + '</code> | ';
                    hint += 'Series internas: <code>' + dbg.inner_series + '</code>. ';
                    hint += 'Usa <strong>"Diagnóstico API"</strong> para ver la respuesta raw completa.';
                    self.showStatus('info', hint);
                }

                self.buildKPIs(pd);

                var chartType = $('#oy-perf-chart-type').val() || 'bar';
                self.buildChart(pd, chartType);

                self.buildTable(pd);

                $('#oy-perf-view-toggle').show();
                self.applyViewMode();

                $('#oy-perf-chart-period').text(pd.period.label || '');
                $('#oy-perf-last-sync').text('Última consulta: ' + pd.cached_at);
                var totalDays = pd.data.dates ? pd.data.dates.length : 0;
                $('#oy-perf-cache-info').text(' | ' + totalDays + ' días de datos');
                $('#oy-perf-footer').show();

            }).fail(function(xhr){
                self.showStatus('error', 'Error de conexión: ' + (xhr.statusText || 'unknown'));
            });
        },

        // ---------------------------------------------------------------------
        // Build KPIs
        // ---------------------------------------------------------------------
        buildKPIs: function(pd){
            var self   = this;
            var series = pd.data.series || {};
            var $kpis  = $('#oy-perf-kpis');
            $kpis.empty();

            var keys = Object.keys(series);
            if (!keys.length) {
                $kpis.html('<p>No hay datos disponibles para el período.</p>');
            } else {
                $.each(keys, function(i, k){
                    var s        = series[k];
                    var c        = pd.comparison && pd.comparison[k] ? pd.comparison[k] : null;
                    var chg      = '';
                    var chgClass = '';

                    if (c && c.prev_total > 0) {
                        var pct  = ((s.total - c.prev_total) / c.prev_total * 100).toFixed(1);
                        var sign = pct > 0 ? '+' : '';
                        chgClass = pct > 0 ? 'oy-perf-kpi__change--up' : (pct < 0 ? 'oy-perf-kpi__change--down' : 'oy-perf-kpi__change--flat');
                        chg = '<span class="oy-perf-kpi__change '+chgClass+'">'+sign+pct+'%</span>';
                    } else if (c && c.prev_total === 0 && s.total > 0) {
                        chg = '<span class="oy-perf-kpi__change oy-perf-kpi__change--up">+∞</span>';
                    }

                    var prevInfo = '';
                    if (c && c.prev_period) {
                        prevInfo = '<div class="oy-perf-kpi__prev">Anterior: ' + self.formatNum(c.prev_total || 0) + '</div>';
                    }

                    $kpis.append(
                        '<div class="oy-perf-kpi" style="border-top:3px solid '+s.color+';">' +
                            '<div class="oy-perf-kpi__label">'+self.escHtml(s.label)+'</div>' +
                            '<div class="oy-perf-kpi__value">'+self.formatNum(s.total)+chg+'</div>' +
                            '<div class="oy-perf-kpi__meta">Prom: '+s.avg+' / día &nbsp;|&nbsp; Máx: '+self.formatNum(s.max)+'</div>' +
                            prevInfo +
                        '</div>'
                    );
                });
            }
        },

        // ---------------------------------------------------------------------
        // Chart metric selector
        // ---------------------------------------------------------------------
        updateChartMetricSelector: function(pd, chartType){
            var self    = this;
            var series  = pd.data.series || {};
            var $lbl    = $('#oy-perf-chart-metric-label');
            var $sel    = $('#oy-perf-single-metric');
            var $pieBdg = $('#oy-perf-chart-pie-badge');
            var isPie   = (chartType === 'pie');

            if (isPie) {
                $lbl.hide(); $sel.hide(); $pieBdg.show();
            } else {
                $pieBdg.hide();
                var keys       = Object.keys(series);
                var currentVal = $sel.val();
                var opts       = '';
                $.each(keys, function(i, k){
                    opts += '<option value="'+self.escHtml(k)+'">'+self.escHtml(series[k].label)+'</option>';
                });
                $sel.html(opts);
                if (currentVal && series[currentVal]) {
                    $sel.val(currentVal);
                } else if (keys.length) {
                    $sel.val(keys[0]);
                }
                $lbl.show(); $sel.show();
            }
        },

        // ---------------------------------------------------------------------
        // Build Chart  — v1.2 CON DEBUG EXHAUSTIVO
        //
        // Cambios v1.2:
        //  - _buildId counter: cancela rAFs obsoletos si buildChart() se llama
        //    varias veces antes de que el rAF anterior dispare.
        //  - setTimeout(100ms) en lugar de doble rAF: más fiable en WP admin
        //    (el doble rAF puede disparar en el mismo frame si el navegador
        //    optimiza, antes de que WordPress termine su propio layout).
        //  - canvas.style.width/height = '100%' EXPLÍCITO antes de new Chart()
        //  - Fallback de dimensiones: si el contenedor mide 0, forza 400×280
        //  - Panel de debug visual + console.log en cada paso
        // ---------------------------------------------------------------------
        buildChart: function(pd, chartType){
            var self       = this;
            var allDates   = pd.data.dates  || [];
            var allSeries  = pd.data.series || {};
            var $wrap      = $('#oy-perf-chart-wrap');
            var $title     = $('#oy-perf-chart-title');
            var $nodata    = $('#oy-perf-chart-nodata');
            var $container = $('#oy-perf-chart-container');

            console.log('[OyPerf] buildChart() llamado — chartType:', chartType, '| series keys:', Object.keys(allSeries).length, '| dates:', allDates.length);

            $nodata.hide();
            $container.show();

            if (!Object.keys(allSeries).length) {
                console.warn('[OyPerf] buildChart: sin series, abortando');
                if (self.chartInstance) { self.chartInstance.destroy(); self.chartInstance = null; }
                return;
            }

            // Guard Chart.js
            if (typeof Chart === 'undefined') {
                console.warn('[OyPerf] buildChart: Chart.js no disponible, esperando...');
                waitForChart(function(){ self.buildChart(pd, chartType); });
                return;
            }

            // Actualizar selector de métrica
            self.updateChartMetricSelector(pd, chartType);

            // Filtrar series según tipo
            var series = {};
            var dates  = allDates;
            var isPie  = (chartType === 'pie');

            if (isPie) {
                var impKeys = [
                    'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
                    'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
                    'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
                    'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'
                ];
                $.each(impKeys, function(i, k){
                    if (allSeries[k]) { series[k] = allSeries[k]; }
                });

                if (!Object.keys(series).length) {
                    console.warn('[OyPerf] buildChart (pie): sin métricas de impresión seleccionadas');
                    if (self.chartInstance) { self.chartInstance.destroy(); self.chartInstance = null; }
                    $nodata.show();
                    $container.hide();
                    return;
                }
            } else {
                var selectedKey = $('#oy-perf-single-metric').val();
                if (!selectedKey || !allSeries[selectedKey]) {
                    selectedKey = Object.keys(allSeries)[0] || '';
                }
                if (!selectedKey) {
                    console.warn('[OyPerf] buildChart: no selectedKey, abortando');
                    return;
                }
                series[selectedKey] = allSeries[selectedKey];
            }

            var sk      = Object.keys(series)[0] || '';
            var skLabel = (sk && series[sk]) ? series[sk].label : '';

            console.log('[OyPerf] buildChart — isPie:', isPie, '| sk:', sk, '| skLabel:', skLabel);

            // ── Construir chartConfig ───────────────────────────────────────
            var chartConfig = null;
            var targetHeight = 280;

            if (isPie) {
                targetHeight = 300;
                var pieLabels = [], pieValues = [], pieColors = [];
                $.each(series, function(k, s){
                    pieLabels.push(s.label);
                    pieValues.push(s.total);
                    pieColors.push(s.color);
                });
                $title.text('Distribución Móvil vs Escritorio');
                chartConfig = {
                    type : 'pie',
                    data : {
                        labels  : pieLabels,
                        datasets: [{
                            data           : pieValues,
                            backgroundColor: pieColors.map(function(c){ return c + 'CC'; }),
                            borderColor    : pieColors,
                            borderWidth    : 2,
                        }]
                    },
                    options: {
                        responsive         : true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'right', labels: { boxWidth: 14, padding: 10, font: { size: 11 } } },
                            tooltip: {
                                callbacks: {
                                    label: function(ctx){
                                        var total = ctx.dataset.data.reduce(function(a,b){ return a+b; }, 0);
                                        var pct   = total > 0 ? (ctx.raw / total * 100).toFixed(1) : 0;
                                        return ' ' + ctx.label + ': ' + self.formatNum(ctx.raw) + ' (' + pct + '%)';
                                    }
                                }
                            }
                        }
                    }
                };
            }

            else if (chartType === 'bar_month') {
                if (!dates.length) {
                    console.warn('[OyPerf] buildChart (bar_month): sin fechas');
                    return;
                }
                var monthMap  = {};
                var monthList = [];
                $.each(dates, function(i, d){
                    var ym = d.substring(0, 7);
                    if (!monthMap[ym]) { monthMap[ym] = 0; monthList.push(ym); }
                    monthMap[ym] += (series[sk] && series[sk].data[d] !== undefined ? series[sk].data[d] : 0);
                });
                var monthLabels = monthList.map(function(ym){
                    var parts = ym.split('-');
                    return self.monthNames[parseInt(parts[1],10)-1] + ' ' + parts[0];
                });
                $title.text(skLabel + ' — por mes');
                chartConfig = {
                    type : 'bar',
                    data : {
                        labels  : monthLabels,
                        datasets: [{
                            label          : skLabel,
                            data           : monthList.map(function(ym){ return monthMap[ym] || 0; }),
                            backgroundColor: (series[sk] ? series[sk].color : '#4285f4') + 'CC',
                            borderColor    : series[sk] ? series[sk].color : '#4285f4',
                            borderWidth    : 1.5,
                            borderRadius   : 4,
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: function(ctx){ return ' ' + self.formatNum(ctx.raw); } } }
                        },
                        scales: {
                            x: { ticks: { maxRotation: 45 } },
                            y: { beginAtZero: true, ticks: { callback: function(v){ return self.formatNum(v); } } }
                        }
                    }
                };
            }

            else {
                if (!dates.length) {
                    console.warn('[OyPerf] buildChart (bar): sin fechas');
                    return;
                }
                var values = dates.map(function(d){
                    return series[sk] && series[sk].data[d] !== undefined ? series[sk].data[d] : null;
                });
                $title.text(skLabel + ' — por día');
                chartConfig = {
                    type : 'bar',
                    data : {
                        labels  : dates,
                        datasets: [{
                            label          : skLabel,
                            data           : values,
                            backgroundColor: (series[sk] ? series[sk].color : '#4285f4') + 'BB',
                            borderColor    : series[sk] ? series[sk].color : '#4285f4',
                            borderWidth    : 1.5,
                            borderRadius   : 2,
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: function(ctx){ return ' ' + (ctx.raw !== null ? self.formatNum(ctx.raw) : '—'); } } }
                        },
                        scales: {
                            x: { ticks: { maxTicksLimit: 20, maxRotation: 45 } },
                            y: { beginAtZero: true, ticks: { callback: function(v){ return self.formatNum(v); } } }
                        }
                    }
                };
            }

            if (!chartConfig) {
                console.warn('[OyPerf] buildChart: chartConfig nulo, abortando');
                return;
            }

            // ── Destruir instancia anterior ─────────────────────────────────
            if (self.chartInstance) {
                console.log('[OyPerf] Destruyendo chart anterior');
                self.chartInstance.destroy();
                self.chartInstance = null;
            }

            // ── Mostrar wrap ANTES de medir el contenedor ───────────────────
            $wrap.show();
            $container.show();

            // ── Aplicar altura al contenedor ────────────────────────────────
            $container.css('height', targetHeight + 'px');

            // ── Reemplazar canvas con uno limpio ────────────────────────────
            $container.html('<canvas id="oy-perf-chart"></canvas>');

            // ── _buildId: cancela rAFs obsoletos ────────────────────────────
            self._buildId = (self._buildId || 0) + 1;
            var thisBuildId   = self._buildId;
            var capturedConfig = chartConfig;

            console.log('[OyPerf] buildChart — programando setTimeout, buildId:', thisBuildId);

            // ── setTimeout 100ms en lugar de doble rAF ──────────────────────
            // El doble rAF puede disparar antes de que WP termine su layout
            // interno; 100ms es suficiente para que el browser complete el
            // reflow y asigne dimensiones reales al contenedor.
            setTimeout(function(){

                // Verificar que este buildId sigue siendo el actual
                if (thisBuildId !== self._buildId) {
                    console.log('[OyPerf] Timeout obsoleto (buildId ' + thisBuildId + ' != ' + self._buildId + '), cancelando');
                    return;
                }

                var canvas     = document.getElementById('oy-perf-chart');
                var $cont      = $('#oy-perf-chart-container');
                var $wrapCheck = $('#oy-perf-chart-wrap');

                // ── Recolectar métricas de dimensiones ──────────────────────
                var contW  = $cont.length  ? $cont[0].clientWidth  : 0;
                var contH  = $cont.length  ? $cont[0].clientHeight : 0;
                var wrapV  = $wrapCheck.is(':visible');
                var contV  = $cont.is(':visible');

                console.log('[OyPerf] === setTimeout callback ===');
                console.log('[OyPerf] buildId:', thisBuildId);
                console.log('[OyPerf] canvas encontrado:', !!canvas);
                console.log('[OyPerf] container.clientWidth:', contW, '| clientHeight:', contH);
                console.log('[OyPerf] wrap visible:', wrapV, '| container visible:', contV);
                console.log('[OyPerf] Chart.js disponible:', typeof Chart !== 'undefined');
                if (canvas) {
                    console.log('[OyPerf] canvas.offsetWidth:', canvas.offsetWidth, '| offsetHeight:', canvas.offsetHeight);
                }

                // ── Actualizar panel de debug visual ────────────────────────
                self.updateDebugPanel({
                    'buildId'         : thisBuildId,
                    'canvas encontrado' : canvas ? 'SÍ' : 'NO ❌',
                    'container W×H'   : contW + ' × ' + contH + 'px',
                    'wrap visible'    : wrapV ? 'SÍ' : 'NO ❌',
                    'container visible': contV ? 'SÍ' : 'NO ❌',
                    'Chart.js'        : typeof Chart !== 'undefined' ? 'disponible ✅' : 'NO DISPONIBLE ❌',
                    'chartType'       : chartType,
                });

                if (!canvas) {
                    console.error('[OyPerf] Canvas #oy-perf-chart no encontrado en DOM');
                    self.updateDebugPanel({ 'error' : 'Canvas no encontrado en DOM ❌' });
                    return;
                }

                if (typeof Chart === 'undefined') {
                    console.error('[OyPerf] Chart.js no disponible en setTimeout callback');
                    self.updateDebugPanel({ 'error' : 'Chart.js no disponible ❌' });
                    return;
                }

                // ── Forzar dimensiones explícitas en el canvas ──────────────
                // Chart.js 4 lee las dimensiones del CONTENEDOR para escalar
                // el canvas; si el contenedor reporta 0 por algún bug de CSS
                // en WP admin, el fallback de 400×targetHeight garantiza render.
                canvas.style.display = 'block';
                if (contW > 0 && contH > 0) {
                    console.log('[OyPerf] Usando dimensiones del contenedor: ' + contW + '×' + contH);
                    // El canvas usará responsive: true → Chart.js lo ajusta solo
                } else {
                    // Fallback: forzar tamaño explícito cuando el contenedor mide 0
                    var fallbackW = Math.max($wrapCheck.width() - 30, 400);
                    var fallbackH = targetHeight;
                    console.warn('[OyPerf] Contenedor mide 0! Usando fallback: ' + fallbackW + '×' + fallbackH);
                    canvas.style.width  = fallbackW + 'px';
                    canvas.style.height = fallbackH + 'px';
                    canvas.width  = fallbackW;
                    canvas.height = fallbackH;
                    // Deshabilitar responsive para evitar que Chart.js borre el tamaño
                    capturedConfig.options.responsive = false;
                    self.updateDebugPanel({ 'fallback activado' : fallbackW + '×' + fallbackH + 'px (contenedor era 0)' });
                }

                // ── Crear el Chart ──────────────────────────────────────────
                try {
                    console.log('[OyPerf] Llamando new Chart()...');
                    self.chartInstance = new Chart(canvas, capturedConfig);
                    console.log('[OyPerf] ✅ Chart creado exitosamente. ID:', self.chartInstance.id);
                    self.updateDebugPanel({ 'new Chart()' : '✅ EXITOSO — ID: ' + self.chartInstance.id });
                } catch(e) {
                    console.error('[OyPerf] ❌ Error en new Chart():', e);
                    self.updateDebugPanel({ 'error new Chart()' : '❌ ' + e.message });
                }

            }, 100);
        },

        // ---------------------------------------------------------------------
        // updateDebugPanel — muestra info de debug en el panel #oy-perf-chart-debug
        // ---------------------------------------------------------------------
        updateDebugPanel: function(info){
            var $panel = $('#oy-perf-chart-debug');
            if (!$panel.length) { return; }

            // Obtener contenido actual y agregar nuevas líneas
            var existing = $panel.data('lines') || {};
            $.extend(existing, info);
            $panel.data('lines', existing);

            var html = '<strong>🔧 Debug Gráfico (eliminar en producción)</strong><br>';
            $.each(existing, function(k, v){
                html += '• <code>' + k + '</code>: ' + v + '<br>';
            });
            $panel.html(html).show();
        },

        // Shortcut: escribe una línea de debug sin clave
        debugLog: function(msg){
            var $panel = $('#oy-perf-chart-debug');
            if (!$panel.length) { return; }
            var current = $panel.html() || '';
            $panel.html(current + '• ' + msg + '<br>').show();
        },

        // ---------------------------------------------------------------------
        // Build Table
        // ---------------------------------------------------------------------
        buildTable: function(pd){
            var self   = this;
            var dates  = (pd.data.dates || []).slice();
            var series = pd.data.series || {};
            var keys   = Object.keys(series);

            if (!dates.length || !keys.length) {
                $('#oy-perf-table-wrap').hide();
                return;
            }

            if (!self.sortAsc) { dates.reverse(); }

            var $thead   = $('#oy-perf-table-head');
            var $tfoot   = $('#oy-perf-table-foot');
            var headHtml = '<tr><th>Fecha</th>';
            $.each(keys, function(i, k){
                headHtml += '<th style="color:'+series[k].color+';">'+self.escHtml(series[k].label)+'</th>';
            });
            headHtml += '</tr>';
            $thead.html(headHtml);

            var bodyHtml = '';
            $.each(dates, function(i, d){
                bodyHtml += '<tr><td><strong>'+d+'</strong></td>';
                $.each(keys, function(j, k){
                    var v = series[k].data[d];
                    bodyHtml += '<td>'+(v !== undefined ? self.formatNum(v) : '—')+'</td>';
                });
                bodyHtml += '</tr>';
            });
            $('#oy-perf-table-body').html(bodyHtml);

            var footHtml = '<tr><th>TOTAL</th>';
            $.each(keys, function(i, k){ footHtml += '<th>'+self.formatNum(series[k].total)+'</th>'; });
            footHtml += '</tr>';
            $tfoot.html(footHtml);

            $('#oy-perf-table-wrap').show();
        },

        // ---------------------------------------------------------------------
        // CSV Export
        // ---------------------------------------------------------------------
        exportCSV: function(pd){
            var dates  = (pd.data.dates || []).slice().sort();
            var series = pd.data.series || {};
            var keys   = Object.keys(series);
            if (!dates.length || !keys.length) { return; }

            var header = ['Fecha'].concat(keys.map(function(k){ return series[k].label; })).join(',');
            var rows   = [header];

            $.each(dates, function(i, d){
                var row = [d];
                $.each(keys, function(j, k){
                    var v = series[k].data[d];
                    row.push(v !== undefined ? v : 0);
                });
                rows.push(row.join(','));
            });

            var totals = ['TOTAL'];
            $.each(keys, function(i, k){ totals.push(series[k].total); });
            rows.push(totals.join(','));

            var blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8;' });
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href     = url;
            a.download = 'gmb-performance-' + (pd.period ? pd.period.label.replace(/[^a-z0-9]/gi,'_') : 'export') + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },

        // ---------------------------------------------------------------------
        // Sync metrics
        // ---------------------------------------------------------------------
        syncMetrics: function(){
            var self    = this;
            var $btn    = $('#oy-perf-btn-sync');
            var $status = $('#oy-perf-sync-status');

            $btn.prop('disabled', true);
            $status.removeClass('oy-perf-status--loading oy-perf-status--error oy-perf-status--info oy-perf-status--success')
                   .addClass('oy-perf-status--loading')
                   .html('⏳ Sincronizando métricas con Google Business Profile, por favor espera...')
                   .show();

            $.post(self.ajaxUrl, {
                action  : 'oy_gmb_perf_sync',
                nonce   : self.nonce,
                post_id : self.postId,
            }, function(resp){
                $btn.prop('disabled', false);
                if (!resp.success) {
                    $status.removeClass('oy-perf-status--loading').addClass('oy-perf-status--error')
                           .html('❌ ' + (resp.data.message || 'Error al sincronizar'));
                    return;
                }
                var saved   = resp.data.saved || {};
                var msg     = '✅ Métricas guardadas correctamente.';
                var details = [];
                if (saved.gmb_profile_views_30d  !== undefined) { details.push('Vistas 30d: '     + saved.gmb_profile_views_30d); }
                if (saved.gmb_calls_30d           !== undefined) { details.push('Llamadas 30d: '   + saved.gmb_calls_30d); }
                if (saved.gmb_website_clicks_30d  !== undefined) { details.push('Web clicks 30d: ' + saved.gmb_website_clicks_30d); }
                if (details.length) { msg += ' (' + details.join(', ') + ')'; }
                if (resp.data.synced_at) { msg += ' — ' + resp.data.synced_at; }
                $status.removeClass('oy-perf-status--loading').addClass('oy-perf-status--success').html(msg);
            }).fail(function(xhr){
                $btn.prop('disabled', false);
                $status.removeClass('oy-perf-status--loading').addClass('oy-perf-status--error')
                       .html('❌ Error de conexión: ' + (xhr.statusText || 'unknown'));
            });
        },

        // ---------------------------------------------------------------------
        // Diagnostic
        // ---------------------------------------------------------------------
        diagMetrics: function(){
            var self    = this;
            var $btn    = $('#oy-perf-btn-diag');
            var $status = $('#oy-perf-diag-status');

            $btn.prop('disabled', true);
            $status.removeClass('oy-perf-status--loading oy-perf-status--error oy-perf-status--info oy-perf-status--success')
                   .addClass('oy-perf-status--loading').html('⏳ Ejecutando diagnóstico...').show();

            $.post(self.ajaxUrl, {
                action  : 'oy_gmb_perf_diagnostic',
                nonce   : self.nonce,
                post_id : self.postId,
            }, function(resp){
                $btn.prop('disabled', false);
                if (!resp.success) {
                    $status.removeClass('oy-perf-status--loading').addClass('oy-perf-status--error')
                           .html('❌ Error: ' + self.escHtml(resp.data.message || 'Error desconocido'));
                    return;
                }

                var d    = resp.data.diagnostic || {};
                var rows = [];
                rows.push('<strong>🔍 Diagnóstico de API de Rendimiento</strong>');
                rows.push('<strong>Configuración:</strong>');
                rows.push('• gmb_location_id almacenado: <code>' + self.escHtml(d.gmb_location_id_stored || '—') + '</code>');
                rows.push('• parent_business_id: <code>' + (d.parent_business_id || '—') + '</code>');
                rows.push('<strong>OAuth:</strong>');
                rows.push('• _gmb_connected: <code>' + self.escHtml(d.gmb_connected_flag || '—') + '</code>');
                rows.push('• Refresh token: <code>' + self.escHtml(d.has_refresh_token || '—') + '</code>');
                rows.push('• Token expira: <code>' + self.escHtml(d.token_expires_at || '—') + '</code>');
                rows.push('• Token expirado: <code>' + self.escHtml(d.token_expired_now || '—') + '</code>');
                rows.push('<strong>Resultado API:</strong>');
                rows.push('• Endpoint: <code style="font-size:11px;">' + self.escHtml(d.test_endpoint || '—') + '</code>');
                rows.push('• Resultado: <code>' + self.escHtml(d.api_result || '—') + '</code>');

                if (d.error_code) {
                    rows.push('• Código de error: <code>' + self.escHtml(d.error_code) + '</code>');
                    rows.push('• Mensaje: ' + self.escHtml(d.error_message || '—'));
                    if (d.http_code) { rows.push('• HTTP Code: <code>' + d.http_code + '</code>'); }
                    if (d.raw_body)  { rows.push('• Raw body: <code style="font-size:10px;">' + self.escHtml(d.raw_body) + '</code>'); }
                } else if (d.has_outer_key) {
                    rows.push('• has_outer_key: <code>' + self.escHtml(d.has_outer_key) + '</code>');
                    rows.push('• outer_count: <code>' + (d.outer_count || 0) + '</code>');
                    rows.push('• inner_series_count: <code>' + (d.inner_series_count || 0) + '</code>');
                    if (d.first_metric) {
                        rows.push('• first_metric: <code>' + self.escHtml(d.first_metric) + '</code>');
                        rows.push('• datedValues_count: <code>' + (d.datedValues_count || 0) + '</code>');
                        rows.push('• value es string: <code>' + self.escHtml(d.value_field_is_string || '—') + '</code>');
                    }
                    if (d.diagnosis) {
                        rows.push('⚠️ <strong>Diagnóstico: ' + self.escHtml(d.diagnosis) + '</strong>');
                    }
                }

                $status.removeClass('oy-perf-status--loading').addClass('oy-perf-status--info').html(rows.join('<br>'));

            }).fail(function(xhr){
                $btn.prop('disabled', false);
                $status.removeClass('oy-perf-status--loading').addClass('oy-perf-status--error')
                       .html('❌ Error de conexión: ' + (xhr.statusText || 'unknown'));
            });
        },

        // ---------------------------------------------------------------------
        // Status helpers
        // ---------------------------------------------------------------------
        showStatus: function(type, msg){
            var icons = { loading: '⏳', error: '❌', info: 'ℹ️', success: '✅' };
            $('#oy-perf-status')
               .removeClass('oy-perf-status--loading oy-perf-status--error oy-perf-status--info oy-perf-status--success')
               .addClass('oy-perf-status--'+type)
               .html((icons[type]||'') + ' ' + msg)
               .show();
        },

        hideStatus: function(){
            $('#oy-perf-status').hide();
        },

        hideResults: function(){
            $('#oy-perf-view-toggle').hide();
            $('#oy-perf-kpis, #oy-perf-chart-wrap, #oy-perf-table-wrap, #oy-perf-footer').hide();
        },

        formatNum: function(n){
            if (n === null || n === undefined) { return '—'; }
            return Number(n).toLocaleString('es-CO');
        },

        escHtml: function(str){
            return String(str)
                .replace(/&/g,  '&amp;')
                .replace(/</g,  '&lt;')
                .replace(/>/g,  '&gt;')
                .replace(/"/g,  '&quot;');
        }
    };

    // =========================================================================
    // Bootstrap
    // =========================================================================
    $(document).ready(function(){
        if ($('#oy-perf-dashboard').length) {
            console.log('[OyPerf] document.ready — inicializando OyPerf');
            OyPerf.init();
        } else {
            console.log('[OyPerf] document.ready — #oy-perf-dashboard no encontrado, omitiendo init');
        }
    });

})(jQuery);
