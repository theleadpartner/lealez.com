/**
 * GMB Performance Dashboard — OyPerf
 *
 * Archivo: assets/js/oy-perf-dashboard.js
 * Depende de: jquery, chartjs-v4, oyPerfConfig (wp_localize_script)
 *
 * FIX CANVAS (v1.1):
 *  - Reemplaza el <canvas> con uno nuevo en cada render → elimina el bug
 *    de stale-canvas de Chart.js 4 tras destroy().
 *  - Envuelve new Chart() en doble requestAnimationFrame → garantiza que
 *    el browser haya repintado y el canvas tenga dimensiones reales antes
 *    de que Chart.js mida el contenedor.
 *
 * @package Lealez
 * @since   1.1.0
 */
(function($){
    'use strict';

    // -------------------------------------------------------------------------
    // Guard: waitForChart
    // Definido como before-inline en chartjs-v4 desde PHP, pero lo declaramos
    // aquí también como fallback por si este archivo cargara antes.
    // -------------------------------------------------------------------------
    if (!window.waitForChart) {
        window._chartJSQueue   = window._chartJSQueue || [];
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

    // -------------------------------------------------------------------------
    // OyPerf object
    // -------------------------------------------------------------------------
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
        currentView   : 'all',   // 'all' | 'cards' | 'chart' | 'table'

        monthNames: ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'],

        // ------------------------------------------------------------------
        // Init
        // ------------------------------------------------------------------

        init: function(){
            var self = this;

            self.populateMonthSelects();
            self.fetchMetrics(false);

            // Period change → show/hide month range picker
            $('#oy-perf-period').on('change', function(){
                $('#oy-perf-month-range').toggle('month_range' === $(this).val());
            });

            // Metric pill toggle (visual only)
            $(document).on('change', '.oy-perf-metric-chk', function(){
                $(this).closest('.oy-perf-pill').toggleClass('oy-perf-pill--active', $(this).is(':checked'));
            });

            // Quick-select buttons
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

            // Toolbar buttons
            $('#oy-perf-btn-apply').on('click',   function(){ self.fetchMetrics(false); });
            $('#oy-perf-btn-refresh').on('click',  function(){ self.fetchMetrics(true); });
            $('#oy-perf-btn-sync').on('click',     function(){ self.syncMetrics(); });
            $('#oy-perf-btn-diag').on('click',     function(){ self.diagMetrics(); });

            // Table sort
            $('#oy-perf-sort-date-asc').on('click', function(){
                self.sortAsc = true;
                if (self.lastData) { self.buildTable(self.lastData); }
            });
            $('#oy-perf-sort-date-desc').on('click', function(){
                self.sortAsc = false;
                if (self.lastData) { self.buildTable(self.lastData); }
            });

            // CSV export
            $('#oy-perf-export-csv').on('click', function(){
                if (self.lastData) { self.exportCSV(self.lastData); }
            });

            // View mode toggle buttons
            $(document).on('click', '.oy-perf-view-btn', function(){
                self.setViewMode($(this).data('view'));
            });

            // Chart type change: re-render
            $('#oy-perf-chart-type').on('change', function(){
                if (self.lastData) {
                    self.buildChart(self.lastData, $(this).val());
                }
            });

            // Single-metric dropdown: re-render chart
            $('#oy-perf-single-metric').on('change', function(){
                if (self.lastData) {
                    self.buildChart(self.lastData, $('#oy-perf-chart-type').val() || 'bar');
                }
            });
        },

        // ------------------------------------------------------------------
        // Month selects helpers
        // ------------------------------------------------------------------

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

        // ------------------------------------------------------------------
        // View Mode
        // ------------------------------------------------------------------

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

            if (showCards && hasSeries) {
                $('#oy-perf-kpis').show();
            } else {
                $('#oy-perf-kpis').hide();
            }

            if (showChart && hasSeries) {
                $('#oy-perf-chart-wrap').show();
            } else {
                $('#oy-perf-chart-wrap').hide();
            }

            if (showTable && hasDates) {
                $('#oy-perf-table-wrap').show();
            } else {
                $('#oy-perf-table-wrap').hide();
            }
        },

        // ------------------------------------------------------------------
        // Core fetch
        // ------------------------------------------------------------------

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

                // Surface debug info si no hay datos de series
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

                // Build all views
                self.buildKPIs(pd);

                var chartType = $('#oy-perf-chart-type').val() || 'bar';
                self.buildChart(pd, chartType);

                self.buildTable(pd);

                // Show view toggle and apply current mode
                $('#oy-perf-view-toggle').show();
                self.applyViewMode();

                // Period badge
                $('#oy-perf-chart-period').text(pd.period.label || '');

                // Footer
                $('#oy-perf-last-sync').text('Última consulta: ' + pd.cached_at);
                var totalDays = pd.data.dates ? pd.data.dates.length : 0;
                $('#oy-perf-cache-info').text(' | ' + totalDays + ' días de datos');
                $('#oy-perf-footer').show();

            }).fail(function(xhr){
                self.showStatus('error', 'Error de conexión: ' + (xhr.statusText || 'unknown'));
            });
        },

        // ------------------------------------------------------------------
        // Build KPIs
        // ------------------------------------------------------------------

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

        // ------------------------------------------------------------------
        // Chart metric selector (helper)
        // ------------------------------------------------------------------

        updateChartMetricSelector: function(pd, chartType){
            var self   = this;
            var series = pd.data.series || {};
            var $lbl   = $('#oy-perf-chart-metric-label');
            var $sel   = $('#oy-perf-single-metric');
            var $pieBdg = $('#oy-perf-chart-pie-badge');
            var isPie   = (chartType === 'pie');

            if (isPie) {
                $lbl.hide();
                $sel.hide();
                $pieBdg.show();
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
                $lbl.show();
                $sel.show();
            }
        },

        // ------------------------------------------------------------------
        // Build Chart  ← FIX PRINCIPAL
        //
        // Correcciones aplicadas (v1.1):
        //  1. Actualizar selector de métrica ANTES de determinar la clave
        //     seleccionada, para que $sel.val() refleje el valor correcto.
        //  2. Destruir instancia anterior y luego REEMPLAZAR el <canvas>
        //     con un elemento nuevo → elimina el bug de stale-canvas de
        //     Chart.js 4.
        //  3. Mostrar $wrap ANTES de crear el canvas nuevo.
        //  4. Envolver new Chart() en doble requestAnimationFrame → garantiza
        //     que el browser haya hecho el reflow y el canvas tenga dimensiones
        //     reales cuando Chart.js lo mide.
        // ------------------------------------------------------------------

        buildChart: function(pd, chartType){
            var self       = this;
            var allDates   = pd.data.dates  || [];
            var allSeries  = pd.data.series || {};
            var $wrap      = $('#oy-perf-chart-wrap');
            var $title     = $('#oy-perf-chart-title');
            var $nodata    = $('#oy-perf-chart-nodata');
            var $container = $('#oy-perf-chart-container');

            $nodata.hide();
            $container.show();

            if (!Object.keys(allSeries).length) {
                if (self.chartInstance) { self.chartInstance.destroy(); self.chartInstance = null; }
                return;
            }

            // Guard: Chart.js no cargado aún
            if (typeof Chart === 'undefined') {
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
                    if (self.chartInstance) { self.chartInstance.destroy(); self.chartInstance = null; }
                    $nodata.show();
                    $container.hide();
                    return;
                }
            } else {
                // Barras: UN solo indicador — usar el valor del selector YA ACTUALIZADO
                var selectedKey = $('#oy-perf-single-metric').val();
                if (!selectedKey || !allSeries[selectedKey]) {
                    selectedKey = Object.keys(allSeries)[0] || '';
                }
                if (!selectedKey) { return; }
                series[selectedKey] = allSeries[selectedKey];
            }

            var sk      = Object.keys(series)[0] || '';
            var skLabel = (sk && series[sk]) ? series[sk].label : '';

            // ----------------------------------------------------------------
            // Construir chartConfig según tipo
            // ----------------------------------------------------------------
            var chartConfig = null;

            // --- PIE: distribución móvil vs escritorio ---
            if (isPie) {
                var pieLabels = [];
                var pieValues = [];
                var pieColors = [];
                $.each(series, function(k, s){
                    pieLabels.push(s.label);
                    pieValues.push(s.total);
                    pieColors.push(s.color);
                });

                $title.text('Distribución Móvil vs Escritorio');
                $container.css('height', '300px');

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
                            legend: {
                                position: 'right',
                                labels  : { boxWidth: 14, padding: 10, font: { size: 11 } }
                            },
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

            // --- BARRAS POR MES ---
            else if (chartType === 'bar_month') {
                if (!dates.length) { return; }

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
                $container.css('height', '280px');
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
                        responsive         : true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend : { display: false },
                            tooltip: { callbacks: { label: function(ctx){ return ' ' + self.formatNum(ctx.raw); } } }
                        },
                        scales: {
                            x: { ticks: { maxRotation: 45 } },
                            y: { beginAtZero: true, ticks: { callback: function(v){ return self.formatNum(v); } } }
                        }
                    }
                };
            }

            // --- BARRAS POR DÍA ---
            else {
                if (!dates.length) { return; }

                var values = dates.map(function(d){
                    return series[sk] && series[sk].data[d] !== undefined ? series[sk].data[d] : null;
                });

                $title.text(skLabel + ' — por día');
                $container.css('height', '280px');
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
                        responsive         : true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend : { display: false },
                            tooltip: { callbacks: { label: function(ctx){ return ' ' + (ctx.raw !== null ? self.formatNum(ctx.raw) : '—'); } } }
                        },
                        scales: {
                            x: { ticks: { maxTicksLimit: 20, maxRotation: 45 } },
                            y: { beginAtZero: true, ticks: { callback: function(v){ return self.formatNum(v); } } }
                        }
                    }
                };
            }

            if (!chartConfig) { return; }

            // ----------------------------------------------------------------
            // FIX 1: Destruir instancia anterior
            // ----------------------------------------------------------------
            if (self.chartInstance) {
                self.chartInstance.destroy();
                self.chartInstance = null;
            }

            // ----------------------------------------------------------------
            // FIX 2: Mostrar el wrap ANTES de insertar el canvas nuevo.
            //        Si el wrap está oculto, el canvas tendrá 0×0px.
            // ----------------------------------------------------------------
            $wrap.show();

            // ----------------------------------------------------------------
            // FIX 3: Reemplazar el <canvas> con uno limpio.
            //        Chart.js 4 deja el canvas anterior en estado interno
            //        corrupto tras destroy(); un canvas nuevo lo evita.
            // ----------------------------------------------------------------
            $container.html('<canvas id="oy-perf-chart"></canvas>');

            // ----------------------------------------------------------------
            // FIX 4: Doble requestAnimationFrame.
            //        El primer rAF ocurre en el mismo frame de paint; el
            //        segundo garantiza que el browser ha completado el reflow
            //        y el canvas tiene dimensiones reales (height:280px del CSS).
            //        Sin esto, Chart.js mide 0×0px y no pinta nada.
            // ----------------------------------------------------------------
            var capturedConfig = chartConfig;
            requestAnimationFrame(function(){
                requestAnimationFrame(function(){
                    var canvas = document.getElementById('oy-perf-chart');
                    if (!canvas) {
                        console.error('[OyPerf] Canvas #oy-perf-chart no encontrado tras reemplazo.');
                        return;
                    }
                    try {
                        self.chartInstance = new Chart(canvas, capturedConfig);
                    } catch(e) {
                        console.error('[OyPerf] Error al crear Chart:', e);
                    }
                });
            });
        },

        // ------------------------------------------------------------------
        // Build Table
        // ------------------------------------------------------------------

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

            var $thead  = $('#oy-perf-table-head');
            var $tfoot  = $('#oy-perf-table-foot');
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
            $.each(keys, function(i, k){
                footHtml += '<th>'+self.formatNum(series[k].total)+'</th>';
            });
            footHtml += '</tr>';
            $tfoot.html(footHtml);

            $('#oy-perf-table-wrap').show();
        },

        // ------------------------------------------------------------------
        // CSV Export
        // ------------------------------------------------------------------

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

        // ------------------------------------------------------------------
        // Sync metrics
        // ------------------------------------------------------------------

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
                    $status.removeClass('oy-perf-status--loading')
                           .addClass('oy-perf-status--error')
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
                $status.removeClass('oy-perf-status--loading')
                       .addClass('oy-perf-status--success')
                       .html(msg);
            }).fail(function(xhr){
                $btn.prop('disabled', false);
                $status.removeClass('oy-perf-status--loading')
                       .addClass('oy-perf-status--error')
                       .html('❌ Error de conexión: ' + (xhr.statusText || 'unknown'));
            });
        },

        // ------------------------------------------------------------------
        // Diagnostic
        // ------------------------------------------------------------------

        diagMetrics: function(){
            var self    = this;
            var $btn    = $('#oy-perf-btn-diag');
            var $status = $('#oy-perf-diag-status');

            $btn.prop('disabled', true);
            $status.removeClass('oy-perf-status--loading oy-perf-status--error oy-perf-status--info oy-perf-status--success')
                   .addClass('oy-perf-status--loading')
                   .html('⏳ Ejecutando diagnóstico...')
                   .show();

            $.post(self.ajaxUrl, {
                action  : 'oy_gmb_perf_diagnostic',
                nonce   : self.nonce,
                post_id : self.postId,
            }, function(resp){
                $btn.prop('disabled', false);
                if (!resp.success) {
                    $status.removeClass('oy-perf-status--loading')
                           .addClass('oy-perf-status--error')
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

                $status.removeClass('oy-perf-status--loading')
                       .addClass('oy-perf-status--info')
                       .html(rows.join('<br>'));

            }).fail(function(xhr){
                $btn.prop('disabled', false);
                $status.removeClass('oy-perf-status--loading')
                       .addClass('oy-perf-status--error')
                       .html('❌ Error de conexión: ' + (xhr.statusText || 'unknown'));
            });
        },

        // ------------------------------------------------------------------
        // Status helpers
        // ------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------
    $(document).ready(function(){
        if ($('#oy-perf-dashboard').length) {
            OyPerf.init();
        }
    });

})(jQuery);
