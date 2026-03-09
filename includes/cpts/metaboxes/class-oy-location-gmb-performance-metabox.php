<?php
/**
 * Metabox: Panel de Rendimiento GMB (Business Profile Performance API)
 *
 * Dashboard de métricas de rendimiento desde la Business Profile Performance API v1.
 * Vistas en: Tarjetas (con comparativa), Gráfico (barras/torta), Tabla.
 *
 * Archivo destino: includes/cpts/metaboxes/class-oy-location-gmb-performance-metabox.php
 *
 * @package Lealez
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class OY_Location_GMB_Performance_Metabox
 *
 * Registra, renderiza y gestiona el panel de rendimiento GMB vía AJAX.
 * Expone los handlers:
 *  - wp_ajax_oy_gmb_perf_fetch        → métricas diarias (fetchMultiDailyMetricsTimeSeries)
 *  - wp_ajax_oy_gmb_perf_sync         → sincroniza métricas a post meta
 *  - wp_ajax_oy_gmb_perf_diagnostic   → diagnóstico raw de API
 *
 * NOTA: Las palabras clave de búsqueda se gestionan en
 *       class-oy-location-gmb-keywords-metabox.php (OY_Location_GMB_Keywords_Metabox).
 */
class OY_Location_GMB_Performance_Metabox {

    // -----------------------------------------------------------------------
    // Constructor / Hooks
    // -----------------------------------------------------------------------

    public function __construct() {
        add_action( 'add_meta_boxes',        array( $this, 'register_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_oy_gmb_perf_fetch',      array( $this, 'ajax_fetch_metrics' ) );
        add_action( 'wp_ajax_oy_gmb_perf_sync',       array( $this, 'ajax_sync_metrics' ) );
        add_action( 'wp_ajax_oy_gmb_perf_diagnostic', array( $this, 'ajax_diagnostic' ) );
    }

    // -----------------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------------

    public function register_meta_box() {
        add_meta_box(
            'oy_location_gmb_performance',
            __( '📊 Panel de Rendimiento — Google Business Profile', 'lealez' ),
            array( $this, 'render_meta_box' ),
            'oy_location',
            'normal',
            'default'
        );
    }

    // -----------------------------------------------------------------------
    // Script / Style enqueue
    // -----------------------------------------------------------------------

public function enqueue_scripts( $hook ) {
    global $post;

    if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
        return;
    }

    if ( ! $post || 'oy_location' !== $post->post_type ) {
        return;
    }

    // ── Chart.js 4.4.3 — LOCAL (no CDN) ──────────────────────────────────
    // El CDN externo puede estar bloqueado por el firewall/CSP del servidor.
    // Usar el archivo bundleado dentro del plugin garantiza que siempre cargue.
    if ( ! wp_script_is( 'chartjs-v4', 'registered' ) ) {
        if ( defined( 'LEALEZ_ASSETS_URL' ) ) {
            $chartjs_url = LEALEZ_ASSETS_URL . 'js/vendor/chart.umd.min.js';
        } else {
            $plugin_root = dirname( dirname( dirname( dirname( __FILE__ ) ) ) );
            $chartjs_url = plugins_url( 'assets/js/vendor/chart.umd.min.js', $plugin_root . '/index.php' );
        }

        wp_register_script(
            'chartjs-v4',
            $chartjs_url,
            array(),
            '4.4.3',
            true
        );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // Verificar que el archivo existe en disco
            if ( defined( 'LEALEZ_PLUGIN_PATH' ) ) {
                $chartjs_disk = LEALEZ_PLUGIN_PATH . 'assets/js/vendor/chart.umd.min.js';
            } else {
                $plugin_root  = dirname( dirname( dirname( dirname( __FILE__ ) ) ) );
                $chartjs_disk = $plugin_root . '/assets/js/vendor/chart.umd.min.js';
            }
            error_log( '[OyPerf] Chart.js URL local: ' . $chartjs_url );
            error_log( '[OyPerf] Chart.js existe en disco: ' . ( file_exists( $chartjs_disk ) ? 'SÍ ✅' : 'NO ❌ — falta assets/js/vendor/chart.umd.min.js' ) );
        }
    }
    wp_enqueue_script( 'chartjs-v4' );

    // ── waitForChart guard (fallback por si otra lib redefine Chart) ──
    $guard_js = 'if(!window.waitForChart){' .
        'window._chartJSQueue=window._chartJSQueue||[];' .
        'window.waitForChart=function(fn){' .
            'if(typeof Chart!=="undefined"){fn();return;}' .
            'window._chartJSQueue.push(fn);' .
            'if(!window._chartJSWatcher){' .
                'window._chartJSWatcher=setInterval(function(){' .
                    'if(typeof Chart!=="undefined"){' .
                        'clearInterval(window._chartJSWatcher);' .
                        'window._chartJSWatcher=null;' .
                        'var q=window._chartJSQueue.splice(0);' .
                        'q.forEach(function(f){try{f();}catch(e){console.warn("[Chart]",e);}});' .
                    '}' .
                '},50);' .
            '}' .
        '};' .
    '}';
    wp_add_inline_script( 'chartjs-v4', $guard_js, 'before' );

    // ── JS del dashboard de rendimiento ──────────────────────────────────
    if ( defined( 'LEALEZ_ASSETS_URL' ) ) {
        $js_url = LEALEZ_ASSETS_URL . 'js/oy-perf-dashboard.js';
    } else {
        $plugin_root = dirname( dirname( dirname( dirname( __FILE__ ) ) ) );
        $js_url      = plugins_url( 'assets/js/oy-perf-dashboard.js', $plugin_root . '/index.php' );
    }

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[OyPerf] enqueue_scripts() — post #' . $post->ID );
        error_log( '[OyPerf] Dashboard JS URL: ' . $js_url );
    }

    wp_enqueue_script(
        'oy-perf-dashboard',
        $js_url,
        array( 'jquery', 'chartjs-v4' ),
        '1.2.0',
        true
    );

    $nonce = wp_create_nonce( 'oy_gmb_performance_nonce' );

    $impressions_keys = array(
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
    );
    $actions_keys = array(
        'BUSINESS_CONVERSATIONS',
        'BUSINESS_DIRECTION_REQUESTS',
        'CALL_CLICKS',
        'WEBSITE_CLICKS',
        'BUSINESS_BOOKINGS',
        'BUSINESS_FOOD_ORDERS',
        'BUSINESS_FOOD_MENU_CLICKS',
    );

    wp_localize_script( 'oy-perf-dashboard', 'oyPerfConfig', array(
        'postId'     => $post->ID,
        'nonce'      => $nonce,
        'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
        'metricsDef' => $this->get_all_metrics_definition(),
        'impKeys'    => $impressions_keys,
        'actKeys'    => $actions_keys,
    ) );

    wp_add_inline_style( 'wp-admin', $this->get_inline_css() );
}

    // -----------------------------------------------------------------------
    // Render Metabox HTML
    // -----------------------------------------------------------------------

    public function render_meta_box( $post ) {
        $location_id  = get_post_meta( $post->ID, 'gmb_location_id', true );
        $business_id  = get_post_meta( $post->ID, 'parent_business_id', true );
        $gmb_connected = false;
        if ( $business_id ) {
            $flag          = get_post_meta( $business_id, '_gmb_connected', true );
            $has_refresh   = (bool) get_post_meta( $business_id, 'gmb_refresh_token', true );
            $gmb_connected = $flag || $has_refresh;
        }

        // ---- Guard: GMB no conectado ----
        if ( ! $gmb_connected || ! $location_id ) {
            echo '<div class="oy-perf-notice oy-perf-notice--warn">';
            echo '<span class="dashicons dashicons-warning"></span> ';
            if ( ! $gmb_connected ) {
                esc_html_e( 'El negocio padre no tiene Google Business Profile conectado. Conecta GMB primero.', 'lealez' );
            } else {
                esc_html_e( 'Esta ubicación no tiene un Google Location ID asignado. Importa los datos desde GMB primero.', 'lealez' );
            }
            echo '</div>';
            return;
        }

        $all_metrics = $this->get_all_metrics_definition();
        ?>
        <div id="oy-perf-dashboard" class="oy-perf-dashboard" data-post-id="<?php echo esc_attr( $post->ID ); ?>">

            <!-- TOOLBAR -->
            <div class="oy-perf-toolbar">
                <div class="oy-perf-toolbar__left">

                    <!-- Period selector -->
                    <div class="oy-perf-field-group">
                        <label for="oy-perf-period"><?php esc_html_e( 'Período', 'lealez' ); ?></label>
                        <select id="oy-perf-period" class="oy-perf-select">
                            <option value="this_month"><?php esc_html_e( 'Este mes', 'lealez' ); ?></option>
                            <option value="3months" selected><?php esc_html_e( 'Últimos 3 meses', 'lealez' ); ?></option>
                            <option value="6months"><?php esc_html_e( 'Últimos 6 meses', 'lealez' ); ?></option>
                            <option value="12months"><?php esc_html_e( 'Últimos 12 meses', 'lealez' ); ?></option>
                            <option value="month_range"><?php esc_html_e( 'Rango por mes', 'lealez' ); ?></option>
                        </select>
                    </div>

                    <!-- Month range picker (hidden by default) -->
                    <div id="oy-perf-month-range" class="oy-perf-field-group" style="display:none;">
                        <label for="oy-perf-month-from"><?php esc_html_e( 'Desde', 'lealez' ); ?></label>
                        <select id="oy-perf-month-from" class="oy-perf-select-month"></select>
                        <label for="oy-perf-month-to"><?php esc_html_e( 'Hasta', 'lealez' ); ?></label>
                        <select id="oy-perf-month-to" class="oy-perf-select-month"></select>
                    </div>

                    <!-- Chart type (only relevant when Gráfico view is active) -->
                    <div class="oy-perf-field-group" id="oy-perf-chart-type-group">
                        <label for="oy-perf-chart-type"><?php esc_html_e( 'Gráfica', 'lealez' ); ?></label>
                        <select id="oy-perf-chart-type" class="oy-perf-select">
                            <option value="bar" selected><?php esc_html_e( 'Barras (por día)', 'lealez' ); ?></option>
                            <option value="bar_month"><?php esc_html_e( 'Barras (por mes)', 'lealez' ); ?></option>
                            <option value="pie"><?php esc_html_e( 'Torta (distribución)', 'lealez' ); ?></option>
                        </select>
                    </div>

                </div><!-- /.left -->

                <div class="oy-perf-toolbar__right">
                    <button type="button" id="oy-perf-btn-apply" class="button button-primary">
                        <span class="dashicons dashicons-search" style="vertical-align:middle;margin-top:-2px;"></span>
                        <?php esc_html_e( 'Consultar', 'lealez' ); ?>
                    </button>
                    <button type="button" id="oy-perf-btn-refresh" class="button" title="<?php esc_attr_e( 'Forzar recarga desde la API (ignora caché)', 'lealez' ); ?>">
                        <span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:-2px;"></span>
                        <?php esc_html_e( 'Actualizar', 'lealez' ); ?>
                    </button>
                    <button type="button" id="oy-perf-btn-sync" class="button button-secondary" title="<?php esc_attr_e( 'Sincroniza los totales y los guarda en los meta-campos de esta ubicación', 'lealez' ); ?>">
                        <span class="dashicons dashicons-cloud-saved" style="vertical-align:middle;margin-top:-2px;"></span>
                        <?php esc_html_e( 'Sincronizar métricas', 'lealez' ); ?>
                    </button>
                    <button type="button" id="oy-perf-btn-diag" class="button" title="<?php esc_attr_e( 'Ejecuta diagnóstico raw de la API para depurar problemas', 'lealez' ); ?>" style="color:#666;">
                        <span class="dashicons dashicons-info" style="vertical-align:middle;margin-top:-2px;"></span>
                        <?php esc_html_e( 'Diagnóstico API', 'lealez' ); ?>
                    </button>
                </div>
            </div><!-- /.toolbar -->

            <!-- METRIC SELECTOR -->
            <div class="oy-perf-metric-selector">
                <strong><?php esc_html_e( 'Métricas a mostrar:', 'lealez' ); ?></strong>
                <div class="oy-perf-metric-pills">
                    <?php foreach ( $all_metrics as $key => $def ) : ?>
                        <label class="oy-perf-pill <?php echo esc_attr( $def['default'] ? 'oy-perf-pill--active' : '' ); ?>" data-metric="<?php echo esc_attr( $key ); ?>">
                            <input type="checkbox" name="oy_perf_metric[]" value="<?php echo esc_attr( $key ); ?>"
                                <?php checked( $def['default'] ); ?> class="oy-perf-metric-chk" />
                            <span style="color:<?php echo esc_attr( $def['color'] ); ?>">●</span>
                            <?php echo esc_html( $def['label'] ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="oy-perf-pill-actions">
                    <button type="button" id="oy-perf-select-all" class="button button-small"><?php esc_html_e( 'Seleccionar todas', 'lealez' ); ?></button>
                    <button type="button" id="oy-perf-select-none" class="button button-small"><?php esc_html_e( 'Ninguna', 'lealez' ); ?></button>
                    <button type="button" id="oy-perf-select-impressions" class="button button-small"><?php esc_html_e( 'Solo Impresiones', 'lealez' ); ?></button>
                    <button type="button" id="oy-perf-select-actions" class="button button-small"><?php esc_html_e( 'Solo Acciones', 'lealez' ); ?></button>
                </div>
            </div><!-- /.metric-selector -->

            <!-- VIEW MODE TOGGLE -->
            <div id="oy-perf-view-toggle" class="oy-perf-view-toggle" style="display:none;">
                <span class="oy-perf-view-toggle__label"><?php esc_html_e( 'Vista:', 'lealez' ); ?></span>
                <button type="button" class="oy-perf-view-btn oy-perf-view-btn--active" data-view="all">
                    <span class="dashicons dashicons-grid-view" style="vertical-align:middle;margin-top:-2px;"></span>
                    <?php esc_html_e( 'Todo', 'lealez' ); ?>
                </button>
                <button type="button" class="oy-perf-view-btn" data-view="cards">
                    <span class="dashicons dashicons-id" style="vertical-align:middle;margin-top:-2px;"></span>
                    <?php esc_html_e( 'Tarjetas', 'lealez' ); ?>
                </button>
                <button type="button" class="oy-perf-view-btn" data-view="chart">
                    <span class="dashicons dashicons-chart-bar" style="vertical-align:middle;margin-top:-2px;"></span>
                    <?php esc_html_e( 'Gráfico', 'lealez' ); ?>
                </button>
                <button type="button" class="oy-perf-view-btn" data-view="table">
                    <span class="dashicons dashicons-list-view" style="vertical-align:middle;margin-top:-2px;"></span>
                    <?php esc_html_e( 'Tabla', 'lealez' ); ?>
                </button>
            </div>

            <!-- LOADING / ERROR -->
            <div id="oy-perf-status" class="oy-perf-status" style="display:none;"></div>
            <!-- SYNC STATUS -->
            <div id="oy-perf-sync-status" class="oy-perf-status" style="display:none;"></div>
            <!-- DIAGNOSTIC STATUS -->
            <div id="oy-perf-diag-status" class="oy-perf-status" style="display:none;"></div>

            <!-- KPI CARDS -->
            <div id="oy-perf-kpis" class="oy-perf-kpis" style="display:none;"></div>

<!-- CHART -->
            <div id="oy-perf-chart-wrap" class="oy-perf-chart-wrap" style="display:none;">
                <div class="oy-perf-chart-header">
                    <h4 id="oy-perf-chart-title"><?php esc_html_e( 'Gráfico', 'lealez' ); ?></h4>
                    <div class="oy-perf-chart-header-right">
                        <label id="oy-perf-chart-metric-label" for="oy-perf-single-metric" style="display:none;"><?php esc_html_e( 'Indicador:', 'lealez' ); ?></label>
                        <select id="oy-perf-single-metric" class="oy-perf-select oy-perf-single-metric-sel" style="display:none;" title="<?php esc_attr_e( 'Indicador a graficar', 'lealez' ); ?>"></select>
                        <span id="oy-perf-chart-pie-badge" class="oy-perf-badge oy-perf-badge--pie" style="display:none;"><?php esc_html_e( 'Móvil vs Escritorio', 'lealez' ); ?></span>
                        <span id="oy-perf-chart-period" class="oy-perf-badge"></span>
                    </div>
                </div>
                <!-- Aviso: torta sin impresiones seleccionadas -->
                <div id="oy-perf-chart-nodata" class="oy-perf-status oy-perf-status--info" style="display:none;">
                    <?php esc_html_e( 'ℹ️ La vista Torta solo aplica para métricas de impresiones (Vistas Maps/Búsqueda). Selecciona al menos una.', 'lealez' ); ?>
                </div>
                <div class="oy-perf-chart-container" id="oy-perf-chart-container">
                    <canvas id="oy-perf-chart"></canvas>
                </div>
                <!-- DEBUG PANEL — eliminar cuando el gráfico ya funcione -->
                <div id="oy-perf-chart-debug" class="oy-perf-chart-debug" style="display:none;"></div>
            </div>

            <!-- DATA TABLE -->
            <div id="oy-perf-table-wrap" class="oy-perf-table-wrap" style="display:none;">
                <div class="oy-perf-section-header">
                    <h4><?php esc_html_e( 'Datos diarios', 'lealez' ); ?></h4>
                    <div class="oy-perf-table-controls">
                        <button type="button" id="oy-perf-sort-date-asc"  class="button button-small"><?php esc_html_e( '↑ Más antiguo', 'lealez' ); ?></button>
                        <button type="button" id="oy-perf-sort-date-desc" class="button button-small"><?php esc_html_e( '↓ Más reciente', 'lealez' ); ?></button>
                        <button type="button" id="oy-perf-export-csv"     class="button button-small"><?php esc_html_e( '⬇ CSV', 'lealez' ); ?></button>
                    </div>
                </div>
                <div class="oy-perf-table-scroll">
                    <table id="oy-perf-table" class="wp-list-table widefat striped oy-perf-table">
                        <thead id="oy-perf-table-head"></thead>
                        <tbody id="oy-perf-table-body"></tbody>
                        <tfoot id="oy-perf-table-foot"></tfoot>
                    </table>
                </div>
            </div><!-- /.table-wrap -->

            <!-- LAST SYNC INFO -->
            <div id="oy-perf-footer" class="oy-perf-footer" style="display:none;">
                <span id="oy-perf-last-sync"></span>
                <span id="oy-perf-cache-info"></span>
            </div>

        </div><!-- /#oy-perf-dashboard -->
        <?php
    }

    // -----------------------------------------------------------------------
    // Metrics Definition
    // -----------------------------------------------------------------------

    /**
     * All available DailyMetrics from Business Profile Performance API v1.
     *
     * @return array
     */
    private function get_all_metrics_definition() {
        return array(
            'BUSINESS_IMPRESSIONS_DESKTOP_MAPS'   => array(
                'label'   => __( 'Vistas Maps (escritorio)', 'lealez' ),
                'group'   => 'impressions',
                'color'   => '#1565C0',
                'default' => false,
            ),
            'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' => array(
                'label'   => __( 'Vistas Búsqueda (escritorio)', 'lealez' ),
                'group'   => 'impressions',
                'color'   => '#1976D2',
                'default' => false,
            ),
            'BUSINESS_IMPRESSIONS_MOBILE_MAPS'    => array(
                'label'   => __( 'Vistas Maps (móvil)', 'lealez' ),
                'group'   => 'impressions',
                'color'   => '#42A5F5',
                'default' => true,
            ),
            'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'  => array(
                'label'   => __( 'Vistas Búsqueda (móvil)', 'lealez' ),
                'group'   => 'impressions',
                'color'   => '#64B5F6',
                'default' => true,
            ),
            'BUSINESS_CONVERSATIONS'              => array(
                'label'   => __( 'Clics en el Chat (Mensajes)', 'lealez' ),
                'group'   => 'actions',
                'color'   => '#6A1B9A',
                'default' => true,
            ),
            'BUSINESS_DIRECTION_REQUESTS'         => array(
                'label'   => __( 'Cómo llegar', 'lealez' ),
                'group'   => 'actions',
                'color'   => '#2E7D32',
                'default' => true,
            ),
            'CALL_CLICKS'                         => array(
                'label'   => __( 'Llamadas', 'lealez' ),
                'group'   => 'actions',
                'color'   => '#E65100',
                'default' => true,
            ),
            'WEBSITE_CLICKS'                      => array(
                'label'   => __( 'Clics sitio web', 'lealez' ),
                'group'   => 'actions',
                'color'   => '#00838F',
                'default' => true,
            ),
            'BUSINESS_BOOKINGS'                   => array(
                'label'   => __( 'Reservas', 'lealez' ),
                'group'   => 'actions',
                'color'   => '#AD1457',
                'default' => false,
            ),
            'BUSINESS_FOOD_ORDERS'                => array(
                'label'   => __( 'Pedidos de comida', 'lealez' ),
                'group'   => 'actions',
                'color'   => '#BF360C',
                'default' => false,
            ),
            'BUSINESS_FOOD_MENU_CLICKS'           => array(
                'label'   => __( 'Clics en menú', 'lealez' ),
                'group'   => 'actions',
                'color'   => '#4E342E',
                'default' => false,
            ),
        );
    }

    // -----------------------------------------------------------------------
    // AJAX: Fetch Performance Metrics
    // -----------------------------------------------------------------------

    /**
     * AJAX handler: métricas diarias
     * Action: oy_gmb_perf_fetch
     */
    public function ajax_fetch_metrics() {
        check_ajax_referer( 'oy_gmb_performance_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'lealez' ) ), 403 );
        }

        $post_id     = absint( $_POST['post_id'] ?? 0 );
        $period      = sanitize_text_field( $_POST['period'] ?? '30' );
        $date_from   = sanitize_text_field( $_POST['date_from'] ?? '' );
        $date_to     = sanitize_text_field( $_POST['date_to'] ?? '' );
        $force       = ! empty( $_POST['force_refresh'] );
        $metrics_raw = isset( $_POST['metrics'] ) ? (array) $_POST['metrics'] : array();

        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => __( 'ID de publicación inválido.', 'lealez' ) ) );
        }

        if ( 'oy_location' !== get_post_type( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Tipo de publicación inválido.', 'lealez' ) ) );
        }

        $location_name = get_post_meta( $post_id, 'gmb_location_id', true );
        $business_id   = get_post_meta( $post_id, 'parent_business_id', true );

        if ( ! $location_name || ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Faltan datos de configuración GMB (location_id o business_id).', 'lealez' ) ) );
        }

        $range = $this->build_date_range( $period, $date_from, $date_to );
        if ( is_wp_error( $range ) ) {
            wp_send_json_error( array( 'message' => $range->get_error_message() ) );
        }

        $allowed_metrics   = array_keys( $this->get_all_metrics_definition() );
        $requested_metrics = array();
        foreach ( $metrics_raw as $m ) {
            $m = sanitize_text_field( $m );
            if ( in_array( $m, $allowed_metrics, true ) ) {
                $requested_metrics[] = $m;
            }
        }

        if ( empty( $requested_metrics ) ) {
            $requested_metrics = array( 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH', 'CALL_CLICKS', 'WEBSITE_CLICKS', 'BUSINESS_DIRECTION_REQUESTS' );
        }

        $result = Lealez_GMB_API::get_location_performance_metrics(
            $business_id,
            $location_name,
            $requested_metrics,
            $range['start'],
            $range['end'],
            ! $force
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message'     => $result->get_error_message(),
                'code'        => $result->get_error_code(),
                'location_id' => $location_name,
                'range'       => $range,
            ) );
        }

        if ( ! is_array( $result ) ) {
            wp_send_json_error( array(
                'message'  => __( 'La API de rendimiento de Google devolvió una respuesta vacía o inválida. Verifica que el scope businessprofileperformance.readonly esté autorizado en tu cuenta Google.', 'lealez' ),
                'code'     => 'empty_api_response',
                'raw_type' => gettype( $result ),
            ) );
        }

        $raw_outer   = isset( $result['multiDailyMetricTimeSeries'] ) ? (array) $result['multiDailyMetricTimeSeries'] : array();
        $inner_total = 0;
        foreach ( $raw_outer as $o ) {
            if ( is_array( $o ) && isset( $o['dailyMetricTimeSeries'] ) ) {
                $inner_total += count( (array) $o['dailyMetricTimeSeries'] );
            }
        }
        $debug = array(
            'location_id'       => $location_name,
            'business_id'       => (int) $business_id,
            'requested_metrics' => $requested_metrics,
            'range'             => $range,
            'api_keys'          => array_keys( $result ),
            'outer_count'       => count( $raw_outer ),
            'inner_series'      => $inner_total,
            'raw_preview'       => substr( wp_json_encode( $result ), 0, 800 ),
        );

        $processed = $this->process_multi_metrics( $result, $requested_metrics );

        $comparison = array();
        if ( ! empty( $processed['series'] ) ) {
            $comparison = $this->fetch_comparison(
                $business_id,
                $location_name,
                $requested_metrics,
                $range
            );
        }

        wp_send_json_success( array(
            'period'     => $range,
            'data'       => $processed,
            'comparison' => $comparison,
            'cached_at'  => current_time( 'mysql' ),
            'debug'      => $debug,
        ) );
    }

    // -----------------------------------------------------------------------
    // Data Processing Helpers
    // -----------------------------------------------------------------------

    /**
     * Build start/end date range from period selector or custom dates.
     *
     * Supported period values:
     *  - 'this_month'  → 1st of current month to yesterday
     *  - '3months'     → 1st of 3 months ago to yesterday
     *  - '6months'     → 1st of 6 months ago to yesterday
     *  - '12months'    → 1st of 12 months ago to yesterday
     *  - 'month_range' → date_from/date_to as 'YYYY-MM' strings
     *  - 'custom'      → date_from/date_to as 'Y-m-d' strings (legacy)
     *  - numeric       → last N days (legacy fallback)
     *
     * @param string $period
     * @param string $date_from
     * @param string $date_to
     * @return array|WP_Error
     */
    private function build_date_range( $period, $date_from = '', $date_to = '' ) {
        $now       = current_time( 'timestamp' );
        $yesterday = strtotime( '-1 day', $now );

        switch ( $period ) {

            case 'this_month':
                $start_ts = mktime( 0, 0, 0, (int) gmdate( 'n', $now ), 1, (int) gmdate( 'Y', $now ) );
                $end_ts   = $yesterday;
                break;

            case '3months':
                $start_ts = mktime( 0, 0, 0, (int) gmdate( 'n', $now ) - 2, 1, (int) gmdate( 'Y', $now ) );
                $end_ts   = $yesterday;
                break;

            case '6months':
                $start_ts = mktime( 0, 0, 0, (int) gmdate( 'n', $now ) - 5, 1, (int) gmdate( 'Y', $now ) );
                $end_ts   = $yesterday;
                break;

            case '12months':
                $start_ts = mktime( 0, 0, 0, (int) gmdate( 'n', $now ) - 11, 1, (int) gmdate( 'Y', $now ) );
                $end_ts   = $yesterday;
                break;

            case 'month_range':
                if ( ! $date_from || ! $date_to ) {
                    return new WP_Error( 'invalid_range', __( 'Debes especificar mes de inicio y fin (YYYY-MM).', 'lealez' ) );
                }
                $parts_from = explode( '-', $date_from );
                $parts_to   = explode( '-', $date_to );
                if ( count( $parts_from ) !== 2 || count( $parts_to ) !== 2 ) {
                    return new WP_Error( 'invalid_range', __( 'Formato de mes inválido. Usa YYYY-MM.', 'lealez' ) );
                }
                $start_ts = mktime( 0, 0, 0, (int) $parts_from[1], 1, (int) $parts_from[0] );
                $end_ts   = mktime( 23, 59, 59, (int) $parts_to[1] + 1, 0, (int) $parts_to[0] );
                if ( $end_ts > $yesterday ) {
                    $end_ts = $yesterday;
                }
                if ( $start_ts >= $end_ts ) {
                    return new WP_Error( 'invalid_range', __( 'El mes de inicio debe ser anterior al mes de fin.', 'lealez' ) );
                }
                break;

            case 'custom':
                if ( ! $date_from || ! $date_to ) {
                    return new WP_Error( 'invalid_range', __( 'Debes especificar fecha de inicio y fin para el rango personalizado.', 'lealez' ) );
                }
                $start_ts = strtotime( $date_from );
                $end_ts   = strtotime( $date_to );
                if ( ! $start_ts || ! $end_ts || $start_ts > $end_ts ) {
                    return new WP_Error( 'invalid_range', __( 'Rango de fechas inválido.', 'lealez' ) );
                }
                break;

            default:
                $days     = absint( $period );
                $days     = $days ? $days : 30;
                $end_ts   = $yesterday;
                $start_ts = strtotime( '-' . $days . ' days', $end_ts );
                break;
        }

        $start = array(
            'year'  => (int) gmdate( 'Y', $start_ts ),
            'month' => (int) gmdate( 'n', $start_ts ),
            'day'   => (int) gmdate( 'j', $start_ts ),
        );
        $end = array(
            'year'  => (int) gmdate( 'Y', $end_ts ),
            'month' => (int) gmdate( 'n', $end_ts ),
            'day'   => (int) gmdate( 'j', $end_ts ),
        );

        return array(
            'start' => $start,
            'end'   => $end,
            'label' => sprintf(
                '%s — %s',
                gmdate( 'd/m/Y', $start_ts ),
                gmdate( 'd/m/Y', $end_ts )
            ),
            'days'  => max( 1, (int) round( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) ),
        );
    }

    /**
     * Process the raw API response from fetchMultiDailyMetricsTimeSeries.
     *
     * Google's actual response structure:
     *   {
     *     "multiDailyMetricTimeSeries": [
     *       {
     *         "dailyMetricTimeSeries": [
     *           {
     *             "dailyMetric": "CALL_CLICKS",
     *             "timeSeries": {
     *               "datedValues": [
     *                 { "date": {"year":2026,"month":2,"day":27}, "value": "2" },
     *                 { "date": {"year":2026,"month":2,"day":28} }   // absent value = 0
     *               ]
     *             }
     *           }
     *         ]
     *       }
     *     ]
     *   }
     *
     * @param array $raw_response
     * @param array $requested_metrics
     * @return array { 'dates' => string[], 'series' => array }
     */
    private function process_multi_metrics( $raw_response, $requested_metrics ) {
        $definitions = $this->get_all_metrics_definition();
        $series      = array();
        $all_dates   = array();

        if ( ! is_array( $raw_response ) ) {
            return array( 'dates' => array(), 'series' => array() );
        }

        $outer_list = isset( $raw_response['multiDailyMetricTimeSeries'] )
            ? (array) $raw_response['multiDailyMetricTimeSeries']
            : array();

        foreach ( $outer_list as $outer_item ) {
            if ( ! is_array( $outer_item ) ) {
                continue;
            }

            $inner_list = isset( $outer_item['dailyMetricTimeSeries'] )
                ? (array) $outer_item['dailyMetricTimeSeries']
                : array();

            foreach ( $inner_list as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }

                $metric_key = isset( $item['dailyMetric'] ) ? (string) $item['dailyMetric'] : '';
                if ( '' === $metric_key || ! isset( $definitions[ $metric_key ] ) ) {
                    continue;
                }

                $dated_values = isset( $item['timeSeries']['datedValues'] )
                    ? (array) $item['timeSeries']['datedValues']
                    : array();

                $data_map = array();

                foreach ( $dated_values as $dv ) {
                    if ( ! is_array( $dv ) || ! isset( $dv['date'] ) ) {
                        continue;
                    }
                    $d        = $dv['date'];
                    $date_str = sprintf(
                        '%04d-%02d-%02d',
                        isset( $d['year'] )  ? (int) $d['year']  : 0,
                        isset( $d['month'] ) ? (int) $d['month'] : 0,
                        isset( $d['day'] )   ? (int) $d['day']   : 0
                    );

                    $val = 0;
                    if ( array_key_exists( 'value', $dv ) && null !== $dv['value'] ) {
                        $val = (int) $dv['value'];
                    }

                    $data_map[ $date_str ] = $val;
                    $all_dates[]           = $date_str;
                }

                $series[ $metric_key ] = array(
                    'label' => $definitions[ $metric_key ]['label'],
                    'color' => $definitions[ $metric_key ]['color'],
                    'group' => $definitions[ $metric_key ]['group'],
                    'data'  => $data_map,
                    'total' => array_sum( $data_map ),
                    'max'   => empty( $data_map ) ? 0 : max( $data_map ),
                    'avg'   => empty( $data_map ) ? 0 : round( array_sum( $data_map ) / count( $data_map ), 1 ),
                );
            }
        }

        $all_dates = array_values( array_unique( $all_dates ) );
        sort( $all_dates );

        return array(
            'dates'  => $all_dates,
            'series' => $series,
        );
    }

    /**
     * Fetch comparison data for the previous equivalent period.
     *
     * @param int    $business_id
     * @param string $location_name
     * @param array  $metrics
     * @param array  $range Current range
     * @return array  metric_key => ['prev_total', 'prev_period']
     */
    private function fetch_comparison( $business_id, $location_name, $metrics, $range ) {
        $days = max( 1, (int) ( $range['days'] ?? 30 ) );

        $current_start_ts = mktime( 0, 0, 0, $range['start']['month'], $range['start']['day'], $range['start']['year'] );
        $prev_end_ts      = $current_start_ts - DAY_IN_SECONDS;
        $prev_start_ts    = $prev_end_ts - ( $days * DAY_IN_SECONDS );

        $prev_range = array(
            'start' => array(
                'year'  => (int) gmdate( 'Y', $prev_start_ts ),
                'month' => (int) gmdate( 'n', $prev_start_ts ),
                'day'   => (int) gmdate( 'j', $prev_start_ts ),
            ),
            'end' => array(
                'year'  => (int) gmdate( 'Y', $prev_end_ts ),
                'month' => (int) gmdate( 'n', $prev_end_ts ),
                'day'   => (int) gmdate( 'j', $prev_end_ts ),
            ),
        );

        $prev_result = Lealez_GMB_API::get_location_performance_metrics(
            $business_id,
            $location_name,
            $metrics,
            $prev_range['start'],
            $prev_range['end'],
            true
        );

        if ( is_wp_error( $prev_result ) ) {
            return array();
        }

        $prev_processed = $this->process_multi_metrics( $prev_result, $metrics );
        $comparison     = array();

        foreach ( $prev_processed['series'] as $metric_key => $prev_data ) {
            $comparison[ $metric_key ] = array(
                'prev_total'  => $prev_data['total'],
                'prev_period' => sprintf(
                    '%s — %s',
                    gmdate( 'd/m/Y', $prev_start_ts ),
                    gmdate( 'd/m/Y', $prev_end_ts )
                ),
            );
        }

        return $comparison;
    }

    // -----------------------------------------------------------------------
    // AJAX: Sync & Save Performance Metrics to Post Meta
    // -----------------------------------------------------------------------

    /**
     * AJAX handler: sincroniza las métricas de rendimiento y guarda los totales
     * en los meta-campos del post oy_location.
     *
     * Guarda (30 días): gmb_profile_views_30d, gmb_calls_30d, gmb_website_clicks_30d,
     *                   gmb_direction_requests_30d, gmb_bookings_30d, gmb_messages_sent,
     *                   gmb_food_orders, gmb_menu_clicks
     * Guarda (7 días):  gmb_profile_views_7d, gmb_calls_7d, gmb_website_clicks_7d,
     *                   gmb_direction_requests_7d
     * Guarda timestamp: gmb_metrics_last_sync
     *
     * Action: oy_gmb_perf_sync
     */
    public function ajax_sync_metrics() {
        check_ajax_referer( 'oy_gmb_performance_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'lealez' ) ), 403 );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );

        if ( ! $post_id || 'oy_location' !== get_post_type( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Post ID inválido.', 'lealez' ) ) );
        }

        $location_name = get_post_meta( $post_id, 'gmb_location_id', true );
        $business_id   = get_post_meta( $post_id, 'parent_business_id', true );

        if ( ! $location_name || ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Faltan datos de configuración GMB (location_id o business_id).', 'lealez' ) ) );
        }

        $all_metrics = array(
            'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
            'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
            'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
            'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
            'BUSINESS_CONVERSATIONS',
            'BUSINESS_DIRECTION_REQUESTS',
            'CALL_CLICKS',
            'WEBSITE_CLICKS',
            'BUSINESS_BOOKINGS',
            'BUSINESS_FOOD_ORDERS',
            'BUSINESS_FOOD_MENU_CLICKS',
        );

        $saved  = array();
        $errors = array();

        // ── Sync 30-day period ────────────────────────────────────────────
        $range_30 = $this->build_date_range( '30' );
        if ( ! is_wp_error( $range_30 ) ) {
            $result_30 = Lealez_GMB_API::get_location_performance_metrics(
                $business_id,
                $location_name,
                $all_metrics,
                $range_30['start'],
                $range_30['end'],
                false
            );

            if ( ! is_wp_error( $result_30 ) ) {
                $processed_30 = $this->process_multi_metrics( $result_30, $all_metrics );
                $series_30    = $processed_30['series'] ?? array();

                $views_30 = 0;
                foreach ( array( 'BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH' ) as $imp_key ) {
                    $views_30 += isset( $series_30[ $imp_key ] ) ? (int) $series_30[ $imp_key ]['total'] : 0;
                }
                update_post_meta( $post_id, 'gmb_profile_views_30d', $views_30 );
                $saved['gmb_profile_views_30d'] = $views_30;

                $calls_30 = isset( $series_30['CALL_CLICKS'] ) ? (int) $series_30['CALL_CLICKS']['total'] : 0;
                update_post_meta( $post_id, 'gmb_calls_30d', $calls_30 );
                $saved['gmb_calls_30d'] = $calls_30;

                $web_30 = isset( $series_30['WEBSITE_CLICKS'] ) ? (int) $series_30['WEBSITE_CLICKS']['total'] : 0;
                update_post_meta( $post_id, 'gmb_website_clicks_30d', $web_30 );
                $saved['gmb_website_clicks_30d'] = $web_30;

                $dir_30 = isset( $series_30['BUSINESS_DIRECTION_REQUESTS'] ) ? (int) $series_30['BUSINESS_DIRECTION_REQUESTS']['total'] : 0;
                update_post_meta( $post_id, 'gmb_direction_requests_30d', $dir_30 );
                $saved['gmb_direction_requests_30d'] = $dir_30;

                $book_30 = isset( $series_30['BUSINESS_BOOKINGS'] ) ? (int) $series_30['BUSINESS_BOOKINGS']['total'] : 0;
                update_post_meta( $post_id, 'gmb_bookings_30d', $book_30 );
                $saved['gmb_bookings_30d'] = $book_30;

                $conv_30 = isset( $series_30['BUSINESS_CONVERSATIONS'] ) ? (int) $series_30['BUSINESS_CONVERSATIONS']['total'] : 0;
                update_post_meta( $post_id, 'gmb_messages_sent', $conv_30 );
                $saved['gmb_messages_sent'] = $conv_30;

                $food_30 = isset( $series_30['BUSINESS_FOOD_ORDERS'] ) ? (int) $series_30['BUSINESS_FOOD_ORDERS']['total'] : 0;
                update_post_meta( $post_id, 'gmb_food_orders', $food_30 );
                $saved['gmb_food_orders'] = $food_30;

                $menu_30 = isset( $series_30['BUSINESS_FOOD_MENU_CLICKS'] ) ? (int) $series_30['BUSINESS_FOOD_MENU_CLICKS']['total'] : 0;
                update_post_meta( $post_id, 'gmb_menu_clicks', $menu_30 );
                $saved['gmb_menu_clicks'] = $menu_30;
            } else {
                $errors[] = '30d: ' . $result_30->get_error_message();
            }
        }

        // ── Sync 7-day period ─────────────────────────────────────────────
        $range_7 = $this->build_date_range( '7' );
        if ( ! is_wp_error( $range_7 ) ) {
            $metrics_7 = array(
                'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
                'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
                'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
                'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
                'BUSINESS_DIRECTION_REQUESTS',
                'CALL_CLICKS',
                'WEBSITE_CLICKS',
            );

            $result_7 = Lealez_GMB_API::get_location_performance_metrics(
                $business_id,
                $location_name,
                $metrics_7,
                $range_7['start'],
                $range_7['end'],
                false
            );

            if ( ! is_wp_error( $result_7 ) ) {
                $processed_7 = $this->process_multi_metrics( $result_7, $metrics_7 );
                $series_7    = $processed_7['series'] ?? array();

                $views_7 = 0;
                foreach ( array( 'BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH' ) as $imp_key ) {
                    $views_7 += isset( $series_7[ $imp_key ] ) ? (int) $series_7[ $imp_key ]['total'] : 0;
                }
                update_post_meta( $post_id, 'gmb_profile_views_7d', $views_7 );
                $saved['gmb_profile_views_7d'] = $views_7;

                $calls_7 = isset( $series_7['CALL_CLICKS'] ) ? (int) $series_7['CALL_CLICKS']['total'] : 0;
                update_post_meta( $post_id, 'gmb_calls_7d', $calls_7 );
                $saved['gmb_calls_7d'] = $calls_7;

                $web_7 = isset( $series_7['WEBSITE_CLICKS'] ) ? (int) $series_7['WEBSITE_CLICKS']['total'] : 0;
                update_post_meta( $post_id, 'gmb_website_clicks_7d', $web_7 );
                $saved['gmb_website_clicks_7d'] = $web_7;

                $dir_7 = isset( $series_7['BUSINESS_DIRECTION_REQUESTS'] ) ? (int) $series_7['BUSINESS_DIRECTION_REQUESTS']['total'] : 0;
                update_post_meta( $post_id, 'gmb_direction_requests_7d', $dir_7 );
                $saved['gmb_direction_requests_7d'] = $dir_7;
            } else {
                $errors[] = '7d: ' . $result_7->get_error_message();
            }
        }

        // ── Save sync timestamp ───────────────────────────────────────────
        $sync_time = current_time( 'mysql' );
        update_post_meta( $post_id, 'gmb_metrics_last_sync', $sync_time );
        $saved['gmb_metrics_last_sync'] = $sync_time;

        if ( ! empty( $errors ) && empty( $saved ) ) {
            wp_send_json_error( array(
                'message' => __( 'Error al sincronizar métricas: ', 'lealez' ) . implode( ' | ', $errors ),
            ) );
        }

        wp_send_json_success( array(
            'message'   => __( 'Métricas sincronizadas y guardadas correctamente.', 'lealez' ),
            'saved'     => $saved,
            'errors'    => $errors,
            'synced_at' => $sync_time,
        ) );
    }

    // -----------------------------------------------------------------------
    // AJAX: Diagnostic — Raw API Inspector
    // -----------------------------------------------------------------------

    /**
     * AJAX handler: Diagnóstico raw de la Performance API.
     *
     * Realiza una llamada fresca con CALL_CLICKS (últimos 7 días) y devuelve
     * información detallada del response.
     *
     * Action: oy_gmb_perf_diagnostic
     */
    public function ajax_diagnostic() {
        check_ajax_referer( 'oy_gmb_performance_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'lealez' ) ), 403 );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );

        if ( ! $post_id || 'oy_location' !== get_post_type( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Post ID inválido.', 'lealez' ) ) );
        }

        $location_name = get_post_meta( $post_id, 'gmb_location_id', true );
        $business_id   = get_post_meta( $post_id, 'parent_business_id', true );

        if ( ! $location_name || ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Faltan gmb_location_id o parent_business_id.', 'lealez' ) ) );
        }

        $flag        = get_post_meta( $business_id, '_gmb_connected', true );
        $has_refresh = (bool) get_post_meta( $business_id, 'gmb_refresh_token', true );
        $token_exp   = get_post_meta( $business_id, 'gmb_token_expires_at', true );

        $range = $this->build_date_range( '7' );
        if ( is_wp_error( $range ) ) {
            wp_send_json_error( array( 'message' => $range->get_error_message() ) );
        }

        $result = Lealez_GMB_API::get_location_performance_metrics(
            $business_id,
            $location_name,
            array( 'CALL_CLICKS' ),
            $range['start'],
            $range['end'],
            false
        );

        $info = array(
            'gmb_location_id_stored'   => $location_name,
            'parent_business_id'       => (int) $business_id,
            'gmb_connected_flag'       => $flag ? 'yes (_gmb_connected)' : 'no',
            'has_refresh_token'        => $has_refresh ? 'yes' : 'no',
            'token_expires_at'         => $token_exp ? gmdate( 'Y-m-d H:i:s', (int) $token_exp ) : 'not set',
            'token_expired_now'        => ( $token_exp && (int) $token_exp < time() ) ? 'YES - EXPIRADO' : 'no',
            'test_endpoint'            => 'https://businessprofileperformance.googleapis.com/v1/locations/' . $location_name . ':fetchMultiDailyMetricsTimeSeries',
            'date_range'               => $range['label'],
        );

        if ( is_wp_error( $result ) ) {
            $info['api_result']    = 'WP_Error';
            $info['error_code']    = $result->get_error_code();
            $info['error_message'] = $result->get_error_message();
            $err_data              = $result->get_error_data();
            if ( is_array( $err_data ) ) {
                $info['http_code'] = $err_data['code'] ?? '';
                $info['raw_body']  = isset( $err_data['raw_body'] ) ? substr( (string) $err_data['raw_body'], 0, 600 ) : '';
            }
            wp_send_json_success( array( 'diagnostic' => $info ) );
        }

        if ( ! is_array( $result ) ) {
            $info['api_result'] = 'Non-array: ' . gettype( $result );
            wp_send_json_success( array( 'diagnostic' => $info ) );
        }

        $outer = isset( $result['multiDailyMetricTimeSeries'] ) ? (array) $result['multiDailyMetricTimeSeries'] : null;
        $info['api_result']    = 'HTTP 200 array';
        $info['response_keys'] = array_keys( $result );
        $info['has_outer_key'] = ( null !== $outer ) ? 'yes' : 'NO — clave faltante';
        $info['outer_count']   = is_array( $outer ) ? count( $outer ) : 0;

        $inner_items = array();
        if ( is_array( $outer ) ) {
            foreach ( $outer as $o ) {
                if ( is_array( $o ) && isset( $o['dailyMetricTimeSeries'] ) ) {
                    foreach ( (array) $o['dailyMetricTimeSeries'] as $item ) {
                        $inner_items[] = $item;
                    }
                }
            }
        }

        $info['inner_series_count'] = count( $inner_items );

        if ( ! empty( $inner_items ) ) {
            $first  = $inner_items[0];
            $dated  = isset( $first['timeSeries']['datedValues'] ) ? (array) $first['timeSeries']['datedValues'] : array();
            $info['first_metric']         = isset( $first['dailyMetric'] ) ? $first['dailyMetric'] : 'unknown';
            $info['datedValues_count']    = count( $dated );
            $info['datedValues_sample']   = ! empty( $dated ) ? array_slice( $dated, 0, 3 ) : 'empty';
            $info['value_field_is_string'] = ( ! empty( $dated ) && isset( $dated[0]['value'] ) ) ? ( is_string( $dated[0]['value'] ) ? 'yes (string)' : 'no (int)' ) : 'n/a (absent)';
        } else {
            $info['diagnosis'] = 'No inner series encontradas. Causa probable: scope OAuth businessprofileperformance.readonly faltante, o no hay datos para el período.';
        }

        $info['raw_response_preview'] = substr( wp_json_encode( $result ), 0, 1500 );

        wp_send_json_success( array( 'diagnostic' => $info ) );
    }


    // -----------------------------------------------------------------------
    // Inline CSS
    // -----------------------------------------------------------------------

    private function get_inline_css() {
        return '
/* ========================================================
   OY Location — GMB Performance Dashboard
   ======================================================== */
#oy-perf-dashboard { font-size:13px; color:#1e1e1e; }

/* Toolbar */
.oy-perf-toolbar {
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:8px;
    background:#f6f7f7; border:1px solid #ddd; border-radius:6px;
    padding:10px 14px; margin-bottom:14px;
}
.oy-perf-toolbar__left  { display:flex; align-items:center; flex-wrap:wrap; gap:12px; }
.oy-perf-toolbar__right { display:flex; gap:8px; flex-wrap:wrap; }
.oy-perf-field-group { display:flex; align-items:center; gap:6px; }
.oy-perf-field-group label { font-weight:600; white-space:nowrap; }
.oy-perf-select { min-width:150px; }

/* Metric Pills */
.oy-perf-metric-selector {
    background:#fff; border:1px solid #ddd; border-radius:6px;
    padding:10px 14px; margin-bottom:14px;
}
.oy-perf-metric-selector strong { display:block; margin-bottom:8px; }
.oy-perf-metric-pills { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px; }
.oy-perf-pill {
    display:inline-flex; align-items:center; gap:4px;
    padding:3px 10px; border-radius:20px; cursor:pointer;
    background:#f0f0f0; border:1.5px solid #ccc;
    font-size:12px; user-select:none; transition:all .15s;
}
.oy-perf-pill--active { background:#e8f0fe; border-color:#4285f4; color:#1a73e8; font-weight:600; }
.oy-perf-pill input[type="checkbox"] { display:none; }
.oy-perf-pill-actions { display:flex; gap:6px; flex-wrap:wrap; }

/* Status messages */
.oy-perf-status {
    padding:10px 14px; border-radius:4px; margin-bottom:12px;
    border:1px solid transparent;
}
.oy-perf-status--loading { background:#e8f4fb; border-color:#bee5eb; color:#0c5460; }
.oy-perf-status--error   { background:#fff3cd; border-color:#ffc107; color:#856404; }
.oy-perf-status--info    { background:#d1ecf1; border-color:#bee5eb; color:#0c5460; }
.oy-perf-status--success { background:#d4edda; border-color:#c3e6cb; color:#155724; }

/* Notices */
.oy-perf-notice {
    padding:10px 14px; border-radius:4px; margin:8px 0;
    border:1px solid transparent;
}
.oy-perf-notice--warn { background:#fff3cd; border-color:#ffc107; color:#856404; }

/* KPI Cards */
.oy-perf-kpis {
    display:grid;
    grid-template-columns: repeat(auto-fill, minmax(180px,1fr));
    gap:10px; margin-bottom:16px;
}
.oy-perf-kpi {
    background:#fff; border:1px solid #ddd; border-radius:6px;
    padding:12px; box-shadow:0 1px 3px rgba(0,0,0,.06);
}
.oy-perf-kpi__label { font-size:11px; color:#666; margin-bottom:4px; line-height:1.3; }
.oy-perf-kpi__value { font-size:24px; font-weight:700; color:#1e1e1e; line-height:1.2; }
.oy-perf-kpi__meta  { font-size:11px; color:#888; margin-top:4px; }
.oy-perf-kpi__prev  { font-size:11px; color:#999; margin-top:2px; }
.oy-perf-kpi__change {
    font-size:13px; font-weight:600; margin-left:6px;
    vertical-align:middle;
}
.oy-perf-kpi__change--up   { color:#2e7d32; }
.oy-perf-kpi__change--down { color:#c62828; }
.oy-perf-kpi__change--flat { color:#666; }

/* Chart */
.oy-perf-chart-wrap {
    background:#fff; border:1px solid #ddd; border-radius:6px;
    padding:14px; margin-bottom:16px;
}
.oy-perf-chart-header {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:10px;
}
.oy-perf-chart-header h4 { margin:0; font-size:14px; font-weight:600; }
.oy-perf-badge {
    background:#e8f0fe; color:#1a73e8; padding:2px 10px;
    border-radius:20px; font-size:12px; font-weight:600;
}
.oy-perf-chart-container { position:relative; height:280px; width:100%; display:block; }
.oy-perf-chart-container canvas { display:block !important; width:100% !important; height:100% !important; max-height:none !important; box-sizing:border-box; }
/* Panel de debug visual */
.oy-perf-chart-debug { font-size:11px; font-family:monospace; background:#fffbe6; border:1px solid #f0c040; border-radius:4px; padding:8px 12px; margin-top:8px; line-height:1.8; color:#444; }

/* View Mode Toggle */
.oy-perf-view-toggle {
    display:flex; align-items:center; gap:6px; flex-wrap:wrap;
    margin-bottom:14px; padding:8px 12px;
    background:#f0f4ff; border:1px solid #c8d8ff; border-radius:6px;
}
.oy-perf-view-toggle__label {
    font-weight:600; font-size:12px; color:#555; white-space:nowrap; margin-right:4px;
}
.oy-perf-view-btn {
    display:inline-flex; align-items:center; gap:4px;
    padding:4px 12px; border-radius:20px; cursor:pointer; font-size:12px;
    background:#fff; border:1.5px solid #ccc; color:#444;
    transition:all .15s;
}
.oy-perf-view-btn:hover { border-color:#4285f4; color:#1a73e8; background:#e8f0fe; }
.oy-perf-view-btn--active {
    background:#4285f4; border-color:#4285f4; color:#fff !important; font-weight:600;
}
.oy-perf-view-btn--active:hover { background:#3367d6; border-color:#3367d6; }

/* Table */
.oy-perf-table-wrap {
    background:#fff; border:1px solid #ddd; border-radius:6px;
    padding:14px; margin-bottom:16px;
}
.oy-perf-section-header {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:10px;
}
.oy-perf-section-header h4 { margin:0; font-size:14px; font-weight:600; }
.oy-perf-table-controls { display:flex; gap:6px; }
.oy-perf-table-scroll { overflow-x:auto; max-height:400px; overflow-y:auto; }
.oy-perf-table th, .oy-perf-table td { padding:6px 10px; white-space:nowrap; }
.oy-perf-table tfoot th { background:#f6f7f7; font-weight:700; }

/* Footer */
.oy-perf-footer {
    font-size:11px; color:#888; padding:6px 0;
    border-top:1px solid #eee; margin-top:4px;
}

/* Chart header right area */
.oy-perf-chart-header-right {
    display:flex; align-items:center; gap:8px; flex-wrap:wrap;
}
.oy-perf-chart-header-right label {
    font-size:12px; font-weight:600; color:#555; white-space:nowrap;
}

/* Single-metric dropdown in chart header */
.oy-perf-single-metric-sel {
    min-width:200px; font-size:12px !important;
    padding:3px 6px !important; height:auto !important;
}

/* Pie badge variant */
.oy-perf-badge--pie {
    background:#fce8b2; color:#b06000;
}

/* Month-range selector */
.oy-perf-select-month { min-width:120px; }
';
    }
}
