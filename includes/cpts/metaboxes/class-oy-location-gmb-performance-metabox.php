<?php
/**
 * Metabox: Panel de Rendimiento GMB (Business Profile Performance API)
 *
 * Dashboard completo de métricas de rendimiento desde la Business Profile Performance API v1.
 * Soporta múltiples métricas diarias, keywords de búsqueda mensual, comparativas y gráficas.
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
 *  - wp_ajax_oy_gmb_perf_keywords     → keywords de búsqueda mensual
 */
class OY_Location_GMB_Performance_Metabox {

    // -----------------------------------------------------------------------
    // Constructor / Hooks
    // -----------------------------------------------------------------------

    public function __construct() {
        add_action( 'add_meta_boxes',        array( $this, 'register_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_oy_gmb_perf_fetch',      array( $this, 'ajax_fetch_metrics' ) );
        add_action( 'wp_ajax_oy_gmb_perf_keywords',   array( $this, 'ajax_fetch_keywords' ) );
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

        // Chart.js 4 from cdnjs (no API key required, only vanilla canvas)
        wp_register_script(
            'chartjs-v4',
            'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.3/chart.umd.min.js',
            array(),
            '4.4.3',
            true
        );
        wp_enqueue_script( 'chartjs-v4' );

        $nonce = wp_create_nonce( 'oy_gmb_performance_nonce' );

        // Inject config via wp_localize_script to avoid PHP variable interpolation issues in JS nowdoc
        $impressions_keys = array( 'BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH' );
        $actions_keys     = array( 'BUSINESS_CONVERSATIONS', 'BUSINESS_DIRECTION_REQUESTS', 'CALL_CLICKS', 'WEBSITE_CLICKS', 'BUSINESS_BOOKINGS', 'BUSINESS_FOOD_ORDERS', 'BUSINESS_FOOD_MENU_CLICKS' );

        wp_localize_script( 'chartjs-v4', 'oyPerfConfig', array(
            'postId'     => $post->ID,
            'nonce'      => $nonce,
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'metricsDef' => $this->get_all_metrics_definition(),
            'impKeys'    => $impressions_keys,
            'actKeys'    => $actions_keys,
        ) );

        wp_add_inline_script( 'chartjs-v4', $this->get_inline_js(), 'after' );
        wp_add_inline_style( 'wp-admin', $this->get_inline_css() );
    }

    // -----------------------------------------------------------------------
    // Render Metabox HTML
    // -----------------------------------------------------------------------

    public function render_meta_box( $post ) {
        $location_id  = get_post_meta( $post->ID, 'gmb_location_id', true );
        $business_id  = get_post_meta( $post->ID, 'parent_business_id', true );
        // Use _gmb_connected (private meta, set by Lealez_GMB_OAuth) as the authoritative connection flag.
        // Fallback: also accept if a refresh token is present (covers legacy data).
        $gmb_connected = false;
        if ( $business_id ) {
            $flag          = get_post_meta( $business_id, '_gmb_connected', true );
            $has_refresh   = (bool) get_post_meta( $business_id, 'gmb_refresh_token', true );
            $gmb_connected = $flag || $has_refresh;
        }
        $location_name = get_the_title( $post->ID );

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

        // ---- Defaults ----
        $default_period = '30';

        // ---- Available Metrics ----
        $all_metrics = $this->get_all_metrics_definition();

        ?>
        <div id="oy-perf-dashboard" class="oy-perf-dashboard" data-post-id="<?php echo esc_attr( $post->ID ); ?>">

            <!-- TOOLBAR -->
            <div class="oy-perf-toolbar">
                <div class="oy-perf-toolbar__left">

                    <!-- Period selector - Month/Year based -->
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

                    <!-- Chart type -->
                    <div class="oy-perf-field-group">
                        <label for="oy-perf-chart-type"><?php esc_html_e( 'Gráfica', 'lealez' ); ?></label>
                        <select id="oy-perf-chart-type" class="oy-perf-select">
                            <option value="line"><?php esc_html_e( 'Líneas', 'lealez' ); ?></option>
                            <option value="bar"><?php esc_html_e( 'Barras (por día)', 'lealez' ); ?></option>
                            <option value="bar_month"><?php esc_html_e( 'Barras (por mes)', 'lealez' ); ?></option>
                            <option value="bar_year"><?php esc_html_e( 'Barras (por año)', 'lealez' ); ?></option>
                            <option value="pie"><?php esc_html_e( 'Torta (distribución)', 'lealez' ); ?></option>
                            <option value="doughnut"><?php esc_html_e( 'Dona (distribución)', 'lealez' ); ?></option>
                        </select>
                    </div>

                    <!-- Compare toggle -->
                    <div class="oy-perf-field-group">
                        <label class="oy-perf-toggle-label">
                            <input type="checkbox" id="oy-perf-compare-toggle" checked />
                            <?php esc_html_e( 'Comparar período anterior', 'lealez' ); ?>
                        </label>
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
                    <button type="button" id="oy-perf-btn-sync" class="button button-secondary" title="<?php esc_attr_e( 'Sincroniza los totales de los últimos 30 y 7 días y los guarda en los meta-campos de esta ubicación', 'lealez' ); ?>">
                        <span class="dashicons dashicons-cloud-saved" style="vertical-align:middle;margin-top:-2px;"></span>
                        <?php esc_html_e( 'Sincronizar métricas', 'lealez' ); ?>
                    </button>
                    <button type="button" id="oy-perf-btn-diag" class="button" title="<?php esc_attr_e( 'Ejecuta diagnóstico raw de la API de rendimiento para depurar problemas de conexión o datos vacíos', 'lealez' ); ?>" style="color:#666;">
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
                    <span class="dashicons dashicons-chart-line" style="vertical-align:middle;margin-top:-2px;"></span>
                    <?php esc_html_e( 'Gráfico', 'lealez' ); ?>
                </button>
                <button type="button" class="oy-perf-view-btn" data-view="table">
                    <span class="dashicons dashicons-list-view" style="vertical-align:middle;margin-top:-2px;"></span>
                    <?php esc_html_e( 'Tabla', 'lealez' ); ?>
                </button>
                <button type="button" class="oy-perf-view-btn" data-view="comparison">
                    <span class="dashicons dashicons-arrow-left-alt" style="vertical-align:middle;margin-top:-2px;"></span>
                    <?php esc_html_e( 'Comparativa', 'lealez' ); ?>
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
                    <h4 id="oy-perf-chart-title"><?php esc_html_e( 'Evolución de métricas', 'lealez' ); ?></h4>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span id="oy-perf-chart-subtitle" class="oy-perf-chart-subtitle" style="display:none;"></span>
                        <span id="oy-perf-chart-period" class="oy-perf-badge"></span>
                    </div>
                </div>
                <div class="oy-perf-chart-container" id="oy-perf-chart-container">
                    <canvas id="oy-perf-chart"></canvas>
                </div>
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

            <!-- COMPARISON CARD -->
            <div id="oy-perf-comparison-wrap" class="oy-perf-comparison-wrap" style="display:none;">
                <h4><?php esc_html_e( 'Comparativa — período actual vs período anterior', 'lealez' ); ?></h4>
                <div id="oy-perf-comparison-grid" class="oy-perf-comparison-grid"></div>
            </div>

            <!-- KEYWORDS SECTION -->
            <div id="oy-perf-keywords-wrap" class="oy-perf-keywords-wrap" style="display:none;">
                <div class="oy-perf-section-header">
                    <h4><?php esc_html_e( '🔍 Palabras clave de búsqueda', 'lealez' ); ?></h4>
                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                        <select id="oy-kw-month-filter" class="oy-perf-select" style="min-width:140px;font-size:12px;">
                            <option value=""><?php esc_html_e( 'Todos los meses', 'lealez' ); ?></option>
                        </select>
                        <button type="button" id="oy-perf-btn-keywords" class="button button-small">
                            <span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:-2px;"></span>
                            <?php esc_html_e( 'Actualizar', 'lealez' ); ?>
                        </button>
                    </div>
                </div>
                <div id="oy-perf-keywords-status" class="oy-perf-status" style="display:none;"></div>
                <!-- KPI top 3 keywords -->
                <div id="oy-kw-kpis" class="oy-kw-kpis" style="display:none;"></div>
                <!-- Keywords chart -->
                <div id="oy-kw-chart-wrap" class="oy-kw-chart-wrap" style="display:none;">
                    <div class="oy-perf-chart-container" style="height:220px;">
                        <canvas id="oy-kw-chart"></canvas>
                    </div>
                </div>
                <!-- Keywords table -->
                <div id="oy-perf-keywords-content" style="display:none;">
                    <div class="oy-perf-table-scroll">
                        <table class="wp-list-table widefat striped oy-perf-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th><?php esc_html_e( 'Palabra clave', 'lealez' ); ?></th>
                                    <th><?php esc_html_e( 'Total impresiones', 'lealez' ); ?></th>
                                    <th><?php esc_html_e( 'Mejor mes', 'lealez' ); ?></th>
                                    <th><?php esc_html_e( 'Meses con datos', 'lealez' ); ?></th>
                                    <th><?php esc_html_e( 'Tendencia', 'lealez' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="oy-perf-keywords-body"></tbody>
                        </table>
                    </div>
                </div>
            </div><!-- /.keywords-wrap -->

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

        // Validate post type
        if ( 'oy_location' !== get_post_type( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Tipo de publicación inválido.', 'lealez' ) ) );
        }

        // Get location meta
        $location_name = get_post_meta( $post_id, 'gmb_location_id', true );
        $business_id   = get_post_meta( $post_id, 'parent_business_id', true );

        if ( ! $location_name || ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Faltan datos de configuración GMB (location_id o business_id).', 'lealez' ) ) );
        }

        // Build date range
        $range = $this->build_date_range( $period, $date_from, $date_to );
        if ( is_wp_error( $range ) ) {
            wp_send_json_error( array( 'message' => $range->get_error_message() ) );
        }

        // Sanitize metrics
        $allowed_metrics = array_keys( $this->get_all_metrics_definition() );
        $requested_metrics = array();
        foreach ( $metrics_raw as $m ) {
            $m = sanitize_text_field( $m );
            if ( in_array( $m, $allowed_metrics, true ) ) {
                $requested_metrics[] = $m;
            }
        }

        if ( empty( $requested_metrics ) ) {
            // Default: first 5 metrics
            $requested_metrics = array( 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH', 'CALL_CLICKS', 'WEBSITE_CLICKS', 'BUSINESS_DIRECTION_REQUESTS' );
        }

        // Call API (use_cache = !force)
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

        // Guard: API returned non-WP_Error but non-array
        if ( ! is_array( $result ) ) {
            wp_send_json_error( array(
                'message'  => __( 'La API de rendimiento de Google devolvió una respuesta vacía o inválida. Verifica que el scope businessprofileperformance.readonly esté autorizado en tu cuenta Google.', 'lealez' ),
                'code'     => 'empty_api_response',
                'raw_type' => gettype( $result ),
            ) );
        }

        // Build debug payload (always returned, useful when series is empty)
        $raw_outer  = isset( $result['multiDailyMetricTimeSeries'] ) ? (array) $result['multiDailyMetricTimeSeries'] : array();
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

        // Process: build chart-ready structure
        $processed = $this->process_multi_metrics( $result, $requested_metrics );

        // Comparison: skip when no series data (saves an extra API call)
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

    /**
     * AJAX handler: keywords de búsqueda mensual
     * Action: oy_gmb_perf_keywords
     *
     * POST params (optional):
     *   kw_month_from  YYYY-MM  start month (defaults to 6 months ago)
     *   kw_month_to    YYYY-MM  end month   (defaults to current month)
     */
    public function ajax_fetch_keywords() {
        check_ajax_referer( 'oy_gmb_performance_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'lealez' ) ), 403 );
        }

        $post_id   = absint( $_POST['post_id'] ?? 0 );
        $force     = ! empty( $_POST['force_refresh'] );
        $kw_from   = sanitize_text_field( $_POST['kw_month_from'] ?? '' );
        $kw_to     = sanitize_text_field( $_POST['kw_month_to'] ?? '' );

        if ( ! $post_id || 'oy_location' !== get_post_type( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Post ID inválido.', 'lealez' ) ) );
        }

        $location_name = get_post_meta( $post_id, 'gmb_location_id', true );
        $business_id   = get_post_meta( $post_id, 'parent_business_id', true );

        if ( ! $location_name || ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Faltan datos de configuración GMB.', 'lealez' ) ) );
        }

        // Build month range from POST params or fall back to last 6 months
        if ( $kw_from && $kw_to ) {
            $parts_from  = explode( '-', $kw_from );
            $parts_to    = explode( '-', $kw_to );
            if ( count( $parts_from ) === 2 && count( $parts_to ) === 2 ) {
                $start_month = array( 'year' => (int) $parts_from[0], 'month' => (int) $parts_from[1] );
                $end_month   = array( 'year' => (int) $parts_to[0],   'month' => (int) $parts_to[1] );
            } else {
                wp_send_json_error( array( 'message' => __( 'Formato de mes inválido. Usa YYYY-MM.', 'lealez' ) ) );
                return;
            }
        } else {
            // Default: last 6 months
            $end_month   = array( 'year' => (int) gmdate( 'Y' ), 'month' => (int) gmdate( 'n' ) );
            $start_ts    = mktime( 0, 0, 0, $end_month['month'] - 5, 1, $end_month['year'] );
            $start_month = array( 'year' => (int) gmdate( 'Y', $start_ts ), 'month' => (int) gmdate( 'n', $start_ts ) );
        }

        $result = Lealez_GMB_API::get_location_search_keywords(
            $business_id,
            $location_name,
            $start_month,
            $end_month,
            ! $force
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Build aggregated keyword data (total impressions per keyword + monthly breakdown)
        $aggregated = $this->aggregate_keywords( $result );

        wp_send_json_success( array(
            'keywords'   => $result,
            'aggregated' => $aggregated,
            'period'     => array( 'start' => $start_month, 'end' => $end_month ),
            'cached_at'  => current_time( 'mysql' ),
        ) );
    }

    /**
     * Aggregate raw keyword API response into totals and monthly breakdowns.
     *
     * @param array $keywords  Raw keyword response from Lealez_GMB_API::get_location_search_keywords
     * @return array  [ ['keyword'=>string, 'total'=>int, 'monthly'=>['YYYY-MM'=>int, ...]], ... ]
     *                Sorted by total descending.
     */
    private function aggregate_keywords( $keywords ) {
        if ( ! is_array( $keywords ) ) {
            return array();
        }

        $agg = array();

        foreach ( $keywords as $kw ) {
            // Extract keyword text (handle both API response shapes)
            $text = '';
            if ( ! empty( $kw['searchKeyword']['insightsValue']['value'] ) ) {
                $text = (string) $kw['searchKeyword']['insightsValue']['value'];
            } elseif ( ! empty( $kw['insightsValue']['value'] ) ) {
                $text = (string) $kw['insightsValue']['value'];
            }

            if ( '' === $text ) {
                continue;
            }

            if ( ! isset( $agg[ $text ] ) ) {
                $agg[ $text ] = array( 'total' => 0, 'monthly' => array() );
            }

            // Monthly breakdown
            if ( ! empty( $kw['monthlyMetrics'] ) && is_array( $kw['monthlyMetrics'] ) ) {
                foreach ( $kw['monthlyMetrics'] as $mm ) {
                    $val = isset( $mm['monthlyMetrics'] ) ? (int) $mm['monthlyMetrics'] : 0;
                    $agg[ $text ]['total'] += $val;
                    if ( isset( $mm['month']['year'], $mm['month']['month'] ) ) {
                        $key = sprintf( '%04d-%02d', (int) $mm['month']['year'], (int) $mm['month']['month'] );
                        $agg[ $text ]['monthly'][ $key ] = ( $agg[ $text ]['monthly'][ $key ] ?? 0 ) + $val;
                    }
                }
            } elseif ( isset( $kw['impressionsValue']['value'] ) ) {
                // Single-value fallback (no monthly breakdown)
                $agg[ $text ]['total'] += (int) $kw['impressionsValue']['value'];
            }
        }

        // Sort by total descending
        uasort( $agg, function( $a, $b ) {
            return $b['total'] - $a['total'];
        } );

        // Convert to indexed array
        $result = array();
        foreach ( $agg as $text => $data ) {
            $result[] = array(
                'keyword' => $text,
                'total'   => $data['total'],
                'monthly' => $data['monthly'],
            );
        }

        return $result;
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
     * @param string $date_from  'YYYY-MM' for month_range, 'Y-m-d' for custom
     * @param string $date_to    'YYYY-MM' for month_range, 'Y-m-d' for custom
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
                // Last day of the to-month: day 0 of the next month = last day of to-month
                $end_ts   = mktime( 23, 59, 59, (int) $parts_to[1] + 1, 0, (int) $parts_to[0] );
                // Cap at yesterday (Performance API has ~2-day lag)
                if ( $end_ts > $yesterday ) {
                    $end_ts = $yesterday;
                }
                if ( $start_ts >= $end_ts ) {
                    return new WP_Error( 'invalid_range', __( 'El mes de inicio debe ser anterior al mes de fin.', 'lealez' ) );
                }
                break;

            case 'custom':
                // Legacy: full Y-m-d dates
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
                // Legacy: numeric days (7, 30, 90, 180…)
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
     * Process the raw API response from fetchMultiDailyMetricsTimeSeries
     * into a chart-ready structure.
     *
     * @param array $raw_response API response array
     * @param array $requested_metrics
     * @return array
     */
    /**
     * Process the raw API response from fetchMultiDailyMetricsTimeSeries.
     *
     * Google's actual response structure (verified against raw diagnostic output):
     *
     *   {
     *     "multiDailyMetricTimeSeries": [       ← outer array (one element per requested metric group)
     *       {
     *         "dailyMetricTimeSeries": [         ← INNER array (one element per metric)
     *           {
     *             "dailyMetric": "CALL_CLICKS",
     *             "timeSeries": {
     *               "datedValues": [
     *                 { "date": {"year":2026,"month":2,"day":27}, "value": "2" },
     *                 { "date": {"year":2026,"month":2,"day":28} }   ← absent value = 0
     *               ]
     *             }
     *           }
     *         ]
     *       }
     *     ]
     *   }
     *
     * Notes:
     * - "value" is a STRING (e.g. "2"), not an integer.
     * - Absent "value" key means 0, not null.
     *
     * @param array $raw_response
     * @param array $requested_metrics
     * @return array { 'dates' => string[], 'series' => array }
     */
    private function process_multi_metrics( $raw_response, $requested_metrics ) {
        $definitions = $this->get_all_metrics_definition();
        $series      = array();
        $all_dates   = array();

        // Guard: non-array (should not happen after caller checks, but be safe)
        if ( ! is_array( $raw_response ) ) {
            return array( 'dates' => array(), 'series' => array() );
        }

        // Outer array: each element wraps an inner dailyMetricTimeSeries array
        $outer_list = isset( $raw_response['multiDailyMetricTimeSeries'] )
            ? (array) $raw_response['multiDailyMetricTimeSeries']
            : array();

        foreach ( $outer_list as $outer_item ) {
            if ( ! is_array( $outer_item ) ) {
                continue;
            }

            // Inner array: each element is one metric series
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

                    // "value" is a string (e.g. "2") or absent (means 0).
                    // Use array_key_exists so we catch null explicitly.
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
     * @return array  metric_key => ['current_total', 'prev_total', 'change_pct']
     */
    private function fetch_comparison( $business_id, $location_name, $metrics, $range ) {
        $days = max( 1, (int) ( $range['days'] ?? 30 ) );

        // Build previous period
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
            true // use cache for previous period
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

        $saved = array();
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
                false // force fresh
            );

            if ( ! is_wp_error( $result_30 ) ) {
                $processed_30 = $this->process_multi_metrics( $result_30, $all_metrics );
                $series_30    = $processed_30['series'] ?? array();

                // Impressions (views) = sum of all 4 impression metrics
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
                false // force fresh
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
            'message'  => __( 'Métricas sincronizadas y guardadas correctamente.', 'lealez' ),
            'saved'    => $saved,
            'errors'   => $errors,
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
     * información detallada del response para identificar problemas:
     * - OAuth scope faltante (businessprofileperformance.readonly)
     * - Location ID incorrecto
     * - Estructura de respuesta inesperada
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

        // Test call: single metric, last 7 days, always fresh
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
            false // force fresh
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
                $info['http_code']  = $err_data['code'] ?? '';
                $info['raw_body']   = isset( $err_data['raw_body'] ) ? substr( (string) $err_data['raw_body'], 0, 600 ) : '';
            }
            wp_send_json_success( array( 'diagnostic' => $info ) );
        }

        if ( ! is_array( $result ) ) {
            $info['api_result'] = 'Non-array: ' . gettype( $result );
            wp_send_json_success( array( 'diagnostic' => $info ) );
        }

        // Decode structure
        $outer = isset( $result['multiDailyMetricTimeSeries'] ) ? (array) $result['multiDailyMetricTimeSeries'] : null;
        $info['api_result']      = 'HTTP 200 array';
        $info['response_keys']   = array_keys( $result );
        $info['has_outer_key']   = ( null !== $outer ) ? 'yes' : 'NO — clave faltante';
        $info['outer_count']     = is_array( $outer ) ? count( $outer ) : 0;

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
            $first   = $inner_items[0];
            $dated   = isset( $first['timeSeries']['datedValues'] ) ? (array) $first['timeSeries']['datedValues'] : array();
            $info['first_metric']           = isset( $first['dailyMetric'] ) ? $first['dailyMetric'] : 'unknown';
            $info['datedValues_count']       = count( $dated );
            $info['datedValues_sample']      = ! empty( $dated ) ? array_slice( $dated, 0, 3 ) : 'empty';
            $info['value_field_is_string']   = ( ! empty( $dated ) && isset( $dated[0]['value'] ) ) ? ( is_string( $dated[0]['value'] ) ? 'yes (string)' : 'no (int)' ) : 'n/a (absent)';
        } else {
            $info['diagnosis'] = 'No inner series encontradas. Causa probable: scope OAuth businessprofileperformance.readonly faltante, o no hay datos para el período.';
        }

        $info['raw_response_preview'] = substr( wp_json_encode( $result ), 0, 1500 );

        wp_send_json_success( array( 'diagnostic' => $info ) );
    }

    // -----------------------------------------------------------------------
    // Inline JS
    // -----------------------------------------------------------------------

    private function get_inline_js() {
        $impressions_keys = wp_json_encode( array( 'BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH' ) );
        $actions_keys     = wp_json_encode( array( 'BUSINESS_CONVERSATIONS', 'BUSINESS_DIRECTION_REQUESTS', 'CALL_CLICKS', 'WEBSITE_CLICKS', 'BUSINESS_BOOKINGS', 'BUSINESS_FOOD_ORDERS', 'BUSINESS_FOOD_MENU_CLICKS' ) );

        // phpcs:disable
        return <<<'JS'
(function($){
    'use strict';

    var OyPerf = {
        postId          : oyPerfConfig.postId,
        nonce           : oyPerfConfig.nonce,
        ajaxUrl         : oyPerfConfig.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php',
        chartInstance   : null,
        kwChartInstance : null,
        metricsDef      : oyPerfConfig.metricsDef,
        impKeys         : oyPerfConfig.impKeys,
        actKeys         : oyPerfConfig.actKeys,
        lastData        : null,
        lastKeywordsData: null,
        sortAsc         : true,
        currentView     : 'all',   // 'all' | 'cards' | 'chart' | 'table' | 'comparison'

        // Month names in Spanish
        monthNames: ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'],

        init: function(){
            var self = this;

            // Populate month selects before first fetch
            self.populateMonthSelects();

            // Load automatically on page open with defaults
            self.fetchMetrics(false);

            // Period change → show/hide month range picker
            $('#oy-perf-period').on('change', function(){
                if ('month_range' === $(this).val()) {
                    $('#oy-perf-month-range').show();
                } else {
                    $('#oy-perf-month-range').hide();
                }
            });

            // Chart type toggle
            $('#oy-perf-chart-type').on('change', function(){
                if (self.chartInstance && self.lastData) {
                    self.buildChart(self.lastData, $(this).val());
                }
            });

            // Metric pill toggle
            $(document).on('change', '.oy-perf-metric-chk', function(){
                var $lbl = $(this).closest('.oy-perf-pill');
                if ($(this).is(':checked')) {
                    $lbl.addClass('oy-perf-pill--active');
                } else {
                    $lbl.removeClass('oy-perf-pill--active');
                }
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

            // Apply / Refresh buttons
            $('#oy-perf-btn-apply').on('click', function(){ self.fetchMetrics(false); });
            $('#oy-perf-btn-refresh').on('click', function(){ self.fetchMetrics(true); });

            // Sync button
            $('#oy-perf-btn-sync').on('click', function(){ self.syncMetrics(); });

            // Diagnostic button
            $('#oy-perf-btn-diag').on('click', function(){ self.diagMetrics(); });

            // Sort table
            $('#oy-perf-sort-date-asc').on('click', function(){
                self.sortAsc = true;
                if (self.lastData) self.buildTable(self.lastData);
            });
            $('#oy-perf-sort-date-desc').on('click', function(){
                self.sortAsc = false;
                if (self.lastData) self.buildTable(self.lastData);
            });

            // CSV export
            $('#oy-perf-export-csv').on('click', function(){
                if (self.lastData) self.exportCSV(self.lastData);
            });

            // View mode toggle buttons
            $(document).on('click', '.oy-perf-view-btn', function(){
                var mode = $(this).data('view');
                self.setViewMode(mode);
            });

            // Comparison toggle checkbox - re-apply view visibility
            $('#oy-perf-compare-toggle').on('change', function(){
                if (self.lastData) {
                    if ($(this).is(':checked')) {
                        self.buildComparison(self.lastData);
                    } else {
                        $('#oy-perf-comparison-wrap').hide();
                    }
                    self.applyViewMode();
                }
            });

            // Chart type change: re-render existing data without new AJAX call
            $('#oy-perf-chart-type').on('change', function(){
                if (self.lastData) {
                    var newType = $(this).val();
                    self.buildChart(self.lastData, newType);
                    self.applyViewMode();
                }
            });

            // Keywords refresh button (force)
            $('#oy-perf-btn-keywords').on('click', function(){ self.fetchKeywords(true); });

            // Keyword month filter
            $('#oy-kw-month-filter').on('change', function(){
                if (self.lastKeywordsData) {
                    var selectedMonth = $(this).val();
                    self.buildKeywordsTableEnhanced(self.lastKeywordsData.aggregated, selectedMonth);
                }
            });
        },

        // ----------------------------------------------------------------
        // Month selects helpers
        // ----------------------------------------------------------------

        populateMonthSelects: function(){
            var self = this;
            var now  = new Date();
            var opts = '';

            // Build 24 months, newest first
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

            // Default: from = 6 months ago, to = current month
            var fromDate = new Date(now.getFullYear(), now.getMonth() - 5, 1);
            $from.val(fromDate.getFullYear() + '-' + String(fromDate.getMonth()+1).padStart(2,'0'));
            $to.val(now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0'));
        },

        getMonthRangeForKeywords: function(){
            var self   = this;
            var period = $('#oy-perf-period').val();
            var now    = new Date();
            var toVal  = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0');

            if ('month_range' === period) {
                return {
                    from: $('#oy-perf-month-from').val(),
                    to  : $('#oy-perf-month-to').val(),
                };
            }

            var monthsBack = {this_month: 0, '3months': 2, '6months': 5, '12months': 11}[period];
            if (monthsBack === undefined) { monthsBack = 5; }

            var fromDate = new Date(now.getFullYear(), now.getMonth() - monthsBack, 1);
            return {
                from: fromDate.getFullYear() + '-' + String(fromDate.getMonth()+1).padStart(2,'0'),
                to  : toVal,
            };
        },

        // ----------------------------------------------------------------
        // View Mode
        // ----------------------------------------------------------------

        setViewMode: function(mode){
            var self = this;
            self.currentView = mode || 'all';
            // Update button active state
            $('.oy-perf-view-btn').removeClass('oy-perf-view-btn--active');
            $('.oy-perf-view-btn[data-view="'+self.currentView+'"]').addClass('oy-perf-view-btn--active');
            self.applyViewMode();
        },

        applyViewMode: function(){
            var self = this;
            var showCards      = false;
            var showChart      = false;
            var showTable      = false;
            var showComparison = false;

            switch (self.currentView) {
                case 'cards':
                    showCards = true;
                    break;
                case 'chart':
                    showChart = true;
                    break;
                case 'table':
                    showTable = true;
                    break;
                case 'comparison':
                    showComparison = true;
                    break;
                case 'all':
                default:
                    showCards = showChart = showTable = showComparison = true;
                    break;
            }

            // KPIs
            if (showCards && self.lastData && self.lastData.data && Object.keys(self.lastData.data.series||{}).length > 0) {
                $('#oy-perf-kpis').show();
            } else {
                $('#oy-perf-kpis').hide();
            }

            // Chart
            if (showChart && self.chartInstance) {
                $('#oy-perf-chart-wrap').show();
            } else {
                $('#oy-perf-chart-wrap').hide();
            }

            // Table
            if (showTable && self.lastData && self.lastData.data && (self.lastData.data.dates||[]).length > 0) {
                $('#oy-perf-table-wrap').show();
            } else {
                $('#oy-perf-table-wrap').hide();
            }

            // Comparison — also gated on the checkbox
            var compareEnabled = $('#oy-perf-compare-toggle').is(':checked');
            if (showComparison && compareEnabled && self.lastData && Object.keys(self.lastData.comparison||{}).length > 0) {
                $('#oy-perf-comparison-wrap').show();
            } else {
                $('#oy-perf-comparison-wrap').hide();
            }
        },

        // ----------------------------------------------------------------
        // Core fetch
        // ----------------------------------------------------------------

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

                // If API returned no series, surface debug info automatically
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

                // KPIs
                self.buildKPIs(pd);

                // Chart
                var chartType = $('#oy-perf-chart-type').val() || 'line';
                self.buildChart(pd, chartType);

                // Table
                self.buildTable(pd);

                // Comparison (gated on checkbox)
                var compareEnabled = $('#oy-perf-compare-toggle').is(':checked');
                if (compareEnabled) {
                    self.buildComparison(pd);
                } else {
                    $('#oy-perf-comparison-wrap').hide();
                }

                // Show & apply view mode
                $('#oy-perf-view-toggle').show();
                self.applyViewMode();

                // Period badge
                $('#oy-perf-chart-period').text(pd.period.label || '');

                // Footer
                $('#oy-perf-last-sync').text('Última consulta: ' + pd.cached_at);
                var totalDays = pd.data.dates ? pd.data.dates.length : 0;
                $('#oy-perf-cache-info').text(' | ' + totalDays + ' días de datos');
                $('#oy-perf-footer').show();

                // Show keywords section and auto-load
                $('#oy-perf-keywords-wrap').show();
                self.fetchKeywords(false);

            }).fail(function(xhr){
                self.showStatus('error', 'Error de conexión: ' + (xhr.statusText || 'unknown'));
            });
        },

        // ----------------------------------------------------------------
        // Keywords
        // ----------------------------------------------------------------

        fetchKeywords: function(forceRefresh){
            var self      = this;
            var kwRange   = self.getMonthRangeForKeywords();

            self.showKeywordsStatus('loading', 'Cargando palabras clave...');
            $('#oy-kw-kpis').hide();
            $('#oy-kw-chart-wrap').hide();
            $('#oy-perf-keywords-content').hide();

            $.post(self.ajaxUrl, {
                action        : 'oy_gmb_perf_keywords',
                nonce         : self.nonce,
                post_id       : self.postId,
                force_refresh : forceRefresh ? 1 : 0,
                kw_month_from : kwRange.from,
                kw_month_to   : kwRange.to,
            }, function(resp){
                self.hideKeywordsStatus();
                if (!resp.success) {
                    self.showKeywordsStatus('error', resp.data.message || 'Error al cargar keywords');
                    return;
                }

                var aggregated = resp.data.aggregated || [];
                var period     = resp.data.period || {};

                if (!aggregated.length) {
                    self.showKeywordsStatus('info', 'No se encontraron palabras clave para el período.');
                    return;
                }

                self.lastKeywordsData = resp.data;

                // Populate month filter
                if (period.start && period.end) {
                    self.buildKeywordsMonthFilter(period.start, period.end);
                }

                // KPI top 3
                self.buildKeywordsKPIs(aggregated);

                // Chart top 10
                self.buildKeywordsChart(aggregated);

                // Table
                self.buildKeywordsTableEnhanced(aggregated, '');

            }).fail(function(xhr){
                self.showKeywordsStatus('error', 'Error de conexión: ' + (xhr.statusText || 'unknown'));
            });
        },

        buildKeywordsMonthFilter: function(startMonth, endMonth){
            var self = this;
            var opts = '<option value="">Todos los meses</option>';
            var allMonths = [];
            var y = startMonth.year;
            var m = startMonth.month;

            while ( y < endMonth.year || (y === endMonth.year && m <= endMonth.month) ) {
                var val = y + '-' + String(m).padStart(2,'0');
                allMonths.push({ val: val, label: self.monthNames[m-1] + ' ' + y });
                m++;
                if (m > 12) { m = 1; y++; }
            }

            // Newest first
            allMonths.reverse();
            $.each(allMonths, function(i, mo){
                opts += '<option value="'+mo.val+'">'+mo.label+'</option>';
            });
            $('#oy-kw-month-filter').html(opts);
        },

        buildKeywordsKPIs: function(aggregated){
            var self  = this;
            var top3  = aggregated.slice(0, 3);
            var $kpis = $('#oy-kw-kpis');
            $kpis.empty();

            if (!top3.length) { $kpis.hide(); return; }

            var colors = ['#4285f4','#34a853','#fbbc05'];
            $.each(top3, function(i, kw){
                $kpis.append(
                    '<div class="oy-kw-kpi" style="border-top:3px solid '+colors[i]+';">' +
                        '<div class="oy-kw-kpi__rank">#'+(i+1)+'</div>' +
                        '<div class="oy-kw-kpi__keyword" title="'+self.escHtml(kw.keyword)+'">'+self.escHtml(kw.keyword)+'</div>' +
                        '<div class="oy-kw-kpi__value">'+self.formatNum(kw.total)+' <span>imp.</span></div>' +
                    '</div>'
                );
            });
            $kpis.show();
        },

        buildKeywordsChart: function(aggregated){
            var self   = this;
            var top10  = aggregated.slice(0, 10);
            var $wrap  = $('#oy-kw-chart-wrap');

            if (!top10.length) { $wrap.hide(); return; }

            if (self.kwChartInstance) {
                self.kwChartInstance.destroy();
                self.kwChartInstance = null;
            }

            var labels  = top10.map(function(kw){ return kw.keyword; });
            var values  = top10.map(function(kw){ return kw.total; });
            var palette = ['#4285f4','#34a853','#fbbc05','#ea4335','#9c27b0','#00bcd4','#ff9800','#795548','#607d8b','#e91e63'];
            var bgColors = labels.map(function(l, i){ return palette[i % palette.length]; });

            var ctx = document.getElementById('oy-kw-chart');
            if (!ctx) { return; }

            self.kwChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels  : labels,
                    datasets: [{
                        label          : 'Impresiones',
                        data           : values,
                        backgroundColor: bgColors,
                        borderRadius   : 4,
                    }]
                },
                options: {
                    indexAxis : 'y',
                    responsive: true,
                    plugins: {
                        legend : { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(ctx){
                                    return ' ' + self.formatNum(ctx.raw) + ' imp.';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: { callback: function(v){ return self.formatNum(v); } }
                        },
                        y: {
                            ticks: {
                                font: { size: 11 },
                                callback: function(v){
                                    // Truncate long keywords in Y axis
                                    return String(v).length > 28 ? String(v).substring(0,25)+'…' : v;
                                }
                            }
                        }
                    }
                }
            });

            $wrap.show();
        },

        buildKeywordsTableEnhanced: function(aggregated, selectedMonth){
            var self  = this;
            var $tbody = $('#oy-perf-keywords-body');
            var html  = '';
            var data  = aggregated;

            // Filter by month if selected
            if (selectedMonth && selectedMonth !== '') {
                data = [];
                $.each(aggregated, function(i, kw){
                    var monthVal = kw.monthly && kw.monthly[selectedMonth] ? kw.monthly[selectedMonth] : 0;
                    if (monthVal > 0) {
                        data.push({
                            keyword: kw.keyword,
                            total  : monthVal,
                            monthly: kw.monthly,
                        });
                    }
                });
                data.sort(function(a,b){ return b.total - a.total; });
            }

            $.each(data, function(i, kw){
                // Best month
                var monthlyObj = kw.monthly || {};
                var monthKeys  = Object.keys(monthlyObj).sort();
                var bestMonth  = '—';
                var bestVal    = 0;
                $.each(monthlyObj, function(mk, mv){
                    if (mv > bestVal) { bestVal = mv; bestMonth = mk; }
                });
                if (bestMonth !== '—') {
                    var bp = bestMonth.split('-');
                    bestMonth = self.monthNames[parseInt(bp[1],10)-1] + ' ' + bp[0] + ' (' + self.formatNum(bestVal) + ')';
                }

                var monthCount = monthKeys.length;

                // Trend: compare last 2 available months
                var trend = '';
                if (monthKeys.length >= 2) {
                    var last = monthlyObj[monthKeys[monthKeys.length-1]] || 0;
                    var prev = monthlyObj[monthKeys[monthKeys.length-2]] || 0;
                    if (last > prev)      trend = '<span style="color:#2e7d32;font-weight:700;">▲</span>';
                    else if (last < prev) trend = '<span style="color:#c62828;font-weight:700;">▼</span>';
                    else                  trend = '<span style="color:#999;">→</span>';
                }

                html += '<tr>' +
                    '<td>'+(i+1)+'</td>' +
                    '<td><strong>'+self.escHtml(String(kw.keyword))+'</strong></td>' +
                    '<td>'+self.formatNum(kw.total)+'</td>' +
                    '<td style="font-size:11px;color:#555;">'+self.escHtml(bestMonth)+'</td>' +
                    '<td style="text-align:center;">'+monthCount+'</td>' +
                    '<td style="text-align:center;">'+trend+'</td>' +
                    '</tr>';
            });

            $tbody.html(html || '<tr><td colspan="6">Sin datos disponibles para este período/mes.</td></tr>');
            $('#oy-perf-keywords-content').show();
        },

        // ----------------------------------------------------------------
        // Diagnostic (unchanged)
        // ----------------------------------------------------------------

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

                var d = resp.data.diagnostic || {};
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
                    rows.push('• Código error: <code>' + self.escHtml(String(d.error_code)) + '</code>');
                    rows.push('• Mensaje: <code>' + self.escHtml(d.error_message || '') + '</code>');
                    if (d.http_code) rows.push('• HTTP code: <code>' + d.http_code + '</code>');
                    if (d.raw_body)  rows.push('• Raw body: <code style="font-size:11px;">' + self.escHtml(d.raw_body) + '</code>');
                } else {
                    rows.push('• Clave multiDailyMetricTimeSeries: <code>' + self.escHtml(d.has_outer_key || '—') + '</code>');
                    rows.push('• Outer wrappers: <code>' + (d.outer_count !== undefined ? d.outer_count : '—') + '</code>');
                    rows.push('• Inner series: <code>' + (d.inner_series_count !== undefined ? d.inner_series_count : '—') + '</code>');
                    if (d.response_keys) rows.push('• Claves respuesta: <code>' + self.escHtml(d.response_keys.join(', ')) + '</code>');
                    if (d.first_metric)  rows.push('• Primera métrica: <code>' + self.escHtml(d.first_metric) + '</code>');
                    if (d.datedValues_count !== undefined) {
                        rows.push('• datedValues count: <code>' + d.datedValues_count + '</code>');
                        rows.push('• value es string: <code>' + self.escHtml(d.value_field_is_string || 'n/a') + '</code>');
                    }
                    if (d.datedValues_sample && Array.isArray(d.datedValues_sample)) {
                        rows.push('• Muestra datedValues: <code>' + self.escHtml(JSON.stringify(d.datedValues_sample)) + '</code>');
                    }
                    if (d.diagnosis) rows.push('⚠️ <strong>' + self.escHtml(d.diagnosis) + '</strong>');
                    if (d.raw_response_preview) {
                        rows.push('<strong>Raw response (primeros 1500 chars):</strong>');
                        rows.push('<code style="font-size:10px;word-break:break-all;white-space:pre-wrap;">' + self.escHtml(d.raw_response_preview) + '</code>');
                    }
                }

                var okClass = (d.inner_series_count > 0) ? 'oy-perf-status--success' : 'oy-perf-status--info';
                $status.removeClass('oy-perf-status--loading')
                       .addClass(okClass)
                       .html(rows.join('<br>'))
                       .show();

            }).fail(function(xhr){
                $btn.prop('disabled', false);
                $status.removeClass('oy-perf-status--loading')
                       .addClass('oy-perf-status--error')
                       .html('❌ Error de conexión: ' + (xhr.statusText || 'unknown'));
            });
        },

        // ----------------------------------------------------------------
        // Sync (unchanged)
        // ----------------------------------------------------------------

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
                if (saved.gmb_profile_views_30d  !== undefined) details.push('Vistas 30d: ' + saved.gmb_profile_views_30d);
                if (saved.gmb_calls_30d           !== undefined) details.push('Llamadas 30d: ' + saved.gmb_calls_30d);
                if (saved.gmb_website_clicks_30d  !== undefined) details.push('Web clicks 30d: ' + saved.gmb_website_clicks_30d);
                if (details.length) msg += ' (' + details.join(', ') + ')';
                if (resp.data.synced_at) msg += ' — ' + resp.data.synced_at;
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

        // ----------------------------------------------------------------
        // Build KPIs / Chart / Table / Comparison (unchanged)
        // ----------------------------------------------------------------

        buildKPIs: function(pd){
            var self    = this;
            var series  = pd.data.series || {};
            var $kpis   = $('#oy-perf-kpis');
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

                    $kpis.append(
                        '<div class="oy-perf-kpi" style="border-top:3px solid '+s.color+';">' +
                            '<div class="oy-perf-kpi__label">'+self.escHtml(s.label)+'</div>' +
                            '<div class="oy-perf-kpi__value">'+self.formatNum(s.total)+chg+'</div>' +
                            '<div class="oy-perf-kpi__meta">Prom: '+s.avg+' / día &nbsp;|&nbsp; Máx: '+self.formatNum(s.max)+'</div>' +
                        '</div>'
                    );
                });
            }

            $kpis.show();
        },

        buildChart: function(pd, chartType){
            var self   = this;
            var dates  = pd.data.dates || [];
            var series = pd.data.series || {};
            var $wrap  = $('#oy-perf-chart-wrap');
            var $title = $('#oy-perf-chart-title');
            var $sub   = $('#oy-perf-chart-subtitle');

            if (!Object.keys(series).length) {
                $wrap.hide();
                return;
            }

            if (self.chartInstance) {
                self.chartInstance.destroy();
                self.chartInstance = null;
            }

            var ctx = document.getElementById('oy-perf-chart');
            if (!ctx) { return; }

            var chartConfig = null;

            // --- PIE / DOUGHNUT: totals distribution -------------------------
            if (chartType === 'pie' || chartType === 'doughnut') {
                var pieLabels  = [];
                var pieValues  = [];
                var pieColors  = [];
                $.each(series, function(k, s){
                    pieLabels.push(s.label);
                    pieValues.push(s.total);
                    pieColors.push(s.color);
                });
                $title.text('Distribución de métricas');
                $sub.text('Total del período seleccionado').show();
                // Adjust canvas height for pie/doughnut
                $('#oy-perf-chart-container').css('height', '320px');
                chartConfig = {
                    type : chartType,
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
                        responsive  : true,
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

            // --- BAR BY MONTH ------------------------------------------------
            else if (chartType === 'bar_month') {
                if (!dates.length) { $wrap.hide(); return; }
                var monthMap  = {}; // { 'YYYY-MM': { metricKey: sum } }
                var monthList = [];

                $.each(dates, function(i, d){
                    var ym = d.substring(0, 7); // 'YYYY-MM'
                    if (!monthMap[ym]) {
                        monthMap[ym] = {};
                        monthList.push(ym);
                    }
                    $.each(series, function(k, s){
                        monthMap[ym][k] = (monthMap[ym][k] || 0) + (s.data[d] !== undefined ? s.data[d] : 0);
                    });
                });

                // Build nice month labels
                var monthLabels = monthList.map(function(ym){
                    var parts = ym.split('-');
                    var mNames = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
                    return mNames[parseInt(parts[1],10)-1] + ' ' + parts[0];
                });

                var datasets = [];
                $.each(series, function(k, s){
                    datasets.push({
                        label          : s.label,
                        data           : monthList.map(function(ym){ return monthMap[ym][k] || 0; }),
                        backgroundColor: s.color + 'CC',
                        borderColor    : s.color,
                        borderWidth    : 1.5,
                        borderRadius   : 3,
                    });
                });

                $title.text('Métricas por mes');
                $sub.hide();
                $('#oy-perf-chart-container').css('height', '280px');
                chartConfig = {
                    type : 'bar',
                    data : { labels: monthLabels, datasets: datasets },
                    options: {
                        responsive : true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend  : { position: 'bottom', labels: { boxWidth: 12, padding: 10 } },
                            tooltip : {
                                callbacks: {
                                    label: function(ctx){
                                        return ' ' + ctx.dataset.label + ': ' + self.formatNum(ctx.raw);
                                    }
                                }
                            }
                        },
                        scales: {
                            x: { ticks: { maxRotation: 45 } },
                            y: { beginAtZero: true, ticks: { callback: function(v){ return self.formatNum(v); } } }
                        }
                    }
                };
            }

            // --- BAR BY YEAR -------------------------------------------------
            else if (chartType === 'bar_year') {
                if (!dates.length) { $wrap.hide(); return; }
                var yearMap  = {}; // { 'YYYY': { metricKey: sum } }
                var yearList = [];

                $.each(dates, function(i, d){
                    var yr = d.substring(0, 4);
                    if (!yearMap[yr]) {
                        yearMap[yr] = {};
                        yearList.push(yr);
                    }
                    $.each(series, function(k, s){
                        yearMap[yr][k] = (yearMap[yr][k] || 0) + (s.data[d] !== undefined ? s.data[d] : 0);
                    });
                });

                var datasets = [];
                $.each(series, function(k, s){
                    datasets.push({
                        label          : s.label,
                        data           : yearList.map(function(yr){ return yearMap[yr][k] || 0; }),
                        backgroundColor: s.color + 'CC',
                        borderColor    : s.color,
                        borderWidth    : 1.5,
                        borderRadius   : 4,
                    });
                });

                $title.text('Métricas por año');
                $sub.hide();
                $('#oy-perf-chart-container').css('height', '280px');
                chartConfig = {
                    type : 'bar',
                    data : { labels: yearList, datasets: datasets },
                    options: {
                        responsive : true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend  : { position: 'bottom', labels: { boxWidth: 12, padding: 10 } },
                            tooltip : {
                                callbacks: {
                                    label: function(ctx){
                                        return ' ' + ctx.dataset.label + ': ' + self.formatNum(ctx.raw);
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {},
                            y: { beginAtZero: true, ticks: { callback: function(v){ return self.formatNum(v); } } }
                        }
                    }
                };
            }

            // --- LINE / BAR (by day) ----------------------------------------
            else {
                if (!dates.length) { $wrap.hide(); return; }
                var datasets = [];
                $.each(series, function(k, s){
                    var values = dates.map(function(d){ return s.data[d] !== undefined ? s.data[d] : null; });
                    datasets.push({
                        label          : s.label,
                        data           : values,
                        borderColor    : s.color,
                        backgroundColor: chartType === 'bar' ? s.color + 'BB' : s.color + '22',
                        borderWidth    : 2,
                        tension        : 0.3,
                        fill           : chartType === 'line',
                        pointRadius    : dates.length > 60 ? 1 : 3,
                        spanGaps       : true,
                    });
                });

                $title.text('Evolución de métricas');
                $sub.hide();
                $('#oy-perf-chart-container').css('height', '280px');
                chartConfig = {
                    type : chartType,
                    data : { labels: dates, datasets: datasets },
                    options: {
                        responsive : true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend  : { position: 'bottom', labels: { boxWidth: 12, padding: 10 } },
                            tooltip : {
                                callbacks: {
                                    label: function(ctx){
                                        return ' ' + ctx.dataset.label + ': ' + (ctx.raw !== null ? self.formatNum(ctx.raw) : '—');
                                    }
                                }
                            }
                        },
                        scales: {
                            x: { ticks: { maxTicksLimit: 20, maxRotation: 45 } },
                            y: {
                                beginAtZero: true,
                                ticks: { callback: function(v){ return self.formatNum(v); } }
                            }
                        }
                    }
                };
            }

            if (!chartConfig) { $wrap.hide(); return; }

            self.chartInstance = new Chart(ctx, chartConfig);
            $wrap.show();
        },

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

            var $thead = $('#oy-perf-table-head');
            var $tfoot = $('#oy-perf-table-foot');
            var headHtml = '<tr><th>Fecha</th>';
            $.each(keys, function(i, k){ headHtml += '<th style="color:'+series[k].color+';">'+self.escHtml(series[k].label)+'</th>'; });
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

        buildComparison: function(pd){
            var self       = this;
            var series     = pd.data.series || {};
            var comparison = pd.comparison || {};
            var $grid      = $('#oy-perf-comparison-grid');
            var $wrap      = $('#oy-perf-comparison-wrap');
            $grid.empty();

            // Gate on the comparison toggle checkbox
            var compareEnabled = $('#oy-perf-compare-toggle').is(':checked');
            if (!compareEnabled) {
                $wrap.hide();
                return;
            }

            var hasComp = Object.keys(comparison).length > 0;
            if (!hasComp) {
                $wrap.hide();
                return;
            }

            $.each(series, function(k, s){
                var c = comparison[k];
                if (!c) { return; }

                var prev    = c.prev_total || 0;
                var curr    = s.total || 0;
                var chgHtml = '';

                if (prev > 0) {
                    var pct   = ((curr - prev) / prev * 100).toFixed(1);
                    var sign  = pct > 0 ? '+' : '';
                    var cls   = pct > 0 ? 'up' : (pct < 0 ? 'down' : 'flat');
                    var arrow = pct > 0 ? '▲' : (pct < 0 ? '▼' : '→');
                    chgHtml = '<span class="oy-perf-comp__arrow oy-perf-comp__arrow--'+cls+'">'+arrow+' '+sign+pct+'%</span>';
                } else if (curr > 0) {
                    chgHtml = '<span class="oy-perf-comp__arrow oy-perf-comp__arrow--up">▲ Nuevo</span>';
                } else {
                    chgHtml = '<span class="oy-perf-comp__arrow oy-perf-comp__arrow--flat">→ Sin cambio</span>';
                }

                $grid.append(
                    '<div class="oy-perf-comp-card" style="border-left:4px solid '+s.color+';">' +
                        '<div class="oy-perf-comp__label">'+self.escHtml(s.label)+'</div>' +
                        '<div class="oy-perf-comp__values">' +
                            '<div class="oy-perf-comp__current"><span>Actual</span><strong>'+self.formatNum(curr)+'</strong></div>' +
                            '<div>'+chgHtml+'</div>' +
                            '<div class="oy-perf-comp__prev"><span>Anterior</span><strong>'+self.formatNum(prev)+'</strong></div>' +
                        '</div>' +
                        '<div class="oy-perf-comp__period">Anterior: '+(c.prev_period || '')+'</div>' +
                    '</div>'
                );
            });

            $wrap.show();
        },

        // ----------------------------------------------------------------
        // CSV Export (unchanged)
        // ----------------------------------------------------------------

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

        // ----------------------------------------------------------------
        // Status helpers
        // ----------------------------------------------------------------

        showStatus: function(type, msg){
            var icons = { loading: '⏳', error: '❌', info: 'ℹ️', success: '✅' };
            $('#oy-perf-status')
               .removeClass('oy-perf-status--loading oy-perf-status--error oy-perf-status--info oy-perf-status--success')
               .addClass('oy-perf-status--'+type)
               .html((icons[type]||'') + ' ' + msg)
               .show();
        },
        hideStatus: function(){ $('#oy-perf-status').hide(); },

        showKeywordsStatus: function(type, msg){
            var icons = { loading: '⏳', error: '❌', info: 'ℹ️', success: '✅' };
            var $el = $('#oy-perf-keywords-status');
            $el.removeClass('oy-perf-status--loading oy-perf-status--error oy-perf-status--info oy-perf-status--success')
               .addClass('oy-perf-status--'+type)
               .html((icons[type]||'') + ' ' + msg)
               .show();
        },
        hideKeywordsStatus: function(){ $('#oy-perf-keywords-status').hide(); },

        hideResults: function(){
            $('#oy-perf-view-toggle').hide();
            $('#oy-perf-kpis, #oy-perf-chart-wrap, #oy-perf-table-wrap, #oy-perf-comparison-wrap, #oy-perf-footer').hide();
        },

        formatNum: function(n){
            if (n === null || n === undefined) return '—';
            return Number(n).toLocaleString('es-CO');
        },

        escHtml: function(str){
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
    };

    $(document).ready(function(){
        if ($('#oy-perf-dashboard').length) {
            OyPerf.init();
        }
    });

})(jQuery);
JS;
        // phpcs:enable
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
.oy-perf-toolbar__right { display:flex; gap:8px; }
.oy-perf-field-group { display:flex; align-items:center; gap:6px; }
.oy-perf-field-group label { font-weight:600; white-space:nowrap; }
.oy-perf-select { min-width:150px; }
.oy-perf-date-input { width:130px; }

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
.oy-perf-chart-subtitle {
    background:#fce8b2; color:#b06000; padding:2px 10px;
    border-radius:20px; font-size:12px; font-weight:500;
}
.oy-perf-chart-container { position:relative; height:280px; }
.oy-perf-chart-container canvas { max-height:100%; }

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

/* Comparison toggle label */
.oy-perf-toggle-label {
    display:inline-flex; align-items:center; gap:5px;
    cursor:pointer; font-size:12px; color:#444; font-weight:500;
    white-space:nowrap;
}
.oy-perf-toggle-label input[type="checkbox"] { cursor:pointer; margin:0; }

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

/* Comparison */
.oy-perf-comparison-wrap {
    background:#fff; border:1px solid #ddd; border-radius:6px;
    padding:14px; margin-bottom:16px;
}
.oy-perf-comparison-wrap h4 { margin:0 0 12px; font-size:14px; font-weight:600; }
.oy-perf-comparison-grid {
    display:grid;
    grid-template-columns: repeat(auto-fill, minmax(200px,1fr));
    gap:10px;
}
.oy-perf-comp-card {
    background:#f9f9f9; border:1px solid #e0e0e0; border-radius:6px;
    padding:10px 12px;
}
.oy-perf-comp__label { font-size:11px; color:#555; margin-bottom:6px; font-weight:600; }
.oy-perf-comp__values { display:flex; align-items:center; gap:10px; justify-content:space-between; }
.oy-perf-comp__current, .oy-perf-comp__prev { text-align:center; }
.oy-perf-comp__current span, .oy-perf-comp__prev span { font-size:10px; color:#888; display:block; }
.oy-perf-comp__current strong { font-size:18px; font-weight:700; color:#1e1e1e; }
.oy-perf-comp__prev strong    { font-size:14px; font-weight:600; color:#666; }
.oy-perf-comp__arrow { font-size:13px; font-weight:700; }
.oy-perf-comp__arrow--up   { color:#2e7d32; }
.oy-perf-comp__arrow--down { color:#c62828; }
.oy-perf-comp__arrow--flat { color:#999; }
.oy-perf-comp__period { font-size:10px; color:#999; margin-top:6px; }

/* Keywords */
.oy-perf-keywords-wrap {
    background:#fff; border:1px solid #ddd; border-radius:6px;
    padding:14px; margin-bottom:16px;
}

/* Month-range selector */
.oy-perf-select-month { min-width:120px; }

/* Keyword KPI top 3 */
.oy-kw-kpis {
    display:grid;
    grid-template-columns: repeat(3, 1fr);
    gap:8px; margin-bottom:12px;
}
.oy-kw-kpi {
    background:#f9f9f9; border:1px solid #e0e0e0; border-radius:6px;
    padding:10px 12px; text-align:center;
}
.oy-kw-kpi__rank    { font-size:11px; color:#999; font-weight:700; margin-bottom:4px; }
.oy-kw-kpi__keyword {
    font-size:12px; font-weight:600; color:#333;
    margin-bottom:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.oy-kw-kpi__value   { font-size:20px; font-weight:700; color:#1e1e1e; }
.oy-kw-kpi__value span { font-size:11px; color:#888; font-weight:400; }

/* Keyword chart wrapper */
.oy-kw-chart-wrap {
    background:#f9f9f9; border:1px solid #e8e8e8; border-radius:6px;
    padding:10px 12px; margin-bottom:12px;
}

/* Footer */
.oy-perf-footer {
    font-size:11px; color:#888; padding:6px 0;
    border-top:1px solid #eee; margin-top:4px;
}
';
    }
}
