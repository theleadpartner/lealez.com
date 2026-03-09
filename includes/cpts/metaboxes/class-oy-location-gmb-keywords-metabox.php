<?php
/**
 * Metabox: Palabras Clave de Búsqueda GMB (Search Keywords)
 *
 * Panel dedicado exclusivamente a las frases clave de búsqueda,
 * consumiendo el endpoint /searchkeywords/impressions/monthly
 * de la Business Profile Performance API v1.
 *
 * Archivo destino: includes/cpts/metaboxes/class-oy-location-gmb-keywords-metabox.php
 *
 * AJAX handlers:
 *  - wp_ajax_oy_gmb_kw_fetch  → consulta keywords para el período seleccionado
 *  - wp_ajax_oy_gmb_kw_save   → guarda top keywords en post meta
 *
 * @package Lealez
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class OY_Location_GMB_Keywords_Metabox
 */
class OY_Location_GMB_Keywords_Metabox {

    // -----------------------------------------------------------------------
    // Constructor / Hooks
    // -----------------------------------------------------------------------

    public function __construct() {
        add_action( 'add_meta_boxes',        array( $this, 'register_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_oy_gmb_kw_fetch',   array( $this, 'ajax_fetch_keywords' ) );
        add_action( 'wp_ajax_oy_gmb_kw_save',    array( $this, 'ajax_save_keywords' ) );
        add_action( 'wp_ajax_oy_gmb_kw_context', array( $this, 'ajax_fetch_search_context' ) );
    }

    // -----------------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------------

    public function register_meta_box() {
        add_meta_box(
            'oy_location_gmb_keywords',
            __( '🔍 Frases Clave de Búsqueda — Google Business Profile', 'lealez' ),
            array( $this, 'render_meta_box' ),
            'oy_location',
            'normal',
            'default'
        );
    }

    // -----------------------------------------------------------------------
    // Scripts / Styles
    // -----------------------------------------------------------------------

public function enqueue_scripts( $hook ) {
    global $post;

    if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
        return;
    }

    if ( ! $post || 'oy_location' !== $post->post_type ) {
        return;
    }

    // ── Chart.js 4.4.3 — LOCAL (no CDN) ──
    // La performance metabox lo registra primero; este bloque es fallback
    // por si este metabox se carga en ausencia de la performance metabox.
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
    }
    wp_enqueue_script( 'chartjs-v4' );

    $nonce = wp_create_nonce( 'oy_gmb_keywords_nonce' );

    wp_localize_script( 'chartjs-v4', 'oyKwConfig', array(
        'postId'  => $post->ID,
        'nonce'   => $nonce,
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'isDebug' => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 1 : 0,
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

        $gmb_connected = false;
        if ( $business_id ) {
            $flag        = get_post_meta( $business_id, '_gmb_connected', true );
            $has_refresh = (bool) get_post_meta( $business_id, 'gmb_refresh_token', true );
            $gmb_connected = $flag || $has_refresh;
        }

        // Guard: not connected or no location ID
        if ( ! $gmb_connected || ! $location_id ) {
            echo '<div class="oy-kw-notice oy-kw-notice--warn">';
            echo '<span class="dashicons dashicons-warning"></span> ';
            if ( ! $gmb_connected ) {
                esc_html_e( 'El negocio padre no tiene Google Business Profile conectado. Conecta GMB primero.', 'lealez' );
            } else {
                esc_html_e( 'Esta ubicación no tiene un Google Location ID asignado. Importa los datos desde GMB primero.', 'lealez' );
            }
            echo '</div>';
            return;
        }

        // Stored top queries
        $stored_queries = get_post_meta( $post->ID, 'gmb_top_queries', true );
        $last_kw_sync   = get_post_meta( $post->ID, 'gmb_keywords_last_sync', true );
        ?>
        <div id="oy-kw-dashboard" class="oy-kw-dashboard" data-post-id="<?php echo esc_attr( $post->ID ); ?>">

            <!-- TOOLBAR -->
            <div class="oy-kw-toolbar">
                <div class="oy-kw-toolbar__left">

                    <!-- Mes de inicio -->
                    <div class="oy-kw-field-group">
                        <label for="oy-kw-month-from"><?php esc_html_e( 'Desde', 'lealez' ); ?></label>
                        <select id="oy-kw-month-from" class="oy-kw-select"></select>
                    </div>

                    <!-- Mes de fin -->
                    <div class="oy-kw-field-group">
                        <label for="oy-kw-month-to"><?php esc_html_e( 'Hasta', 'lealez' ); ?></label>
                        <select id="oy-kw-month-to" class="oy-kw-select"></select>
                    </div>

                    <!-- Modo mensual -->
                    <div class="oy-kw-field-group">
                        <label for="oy-kw-per-month" title="<?php esc_attr_e( 'Realiza una llamada separada por cada mes para obtener el desglose mensual. Más lento pero más detallado.', 'lealez' ); ?>">
                            <input type="checkbox" id="oy-kw-per-month" value="1" />
                            <?php esc_html_e( 'Desglose por mes', 'lealez' ); ?>
                        </label>
                    </div>

                </div><!-- /.left -->

                <div class="oy-kw-toolbar__right">
                    <button type="button" id="oy-kw-btn-fetch" class="button button-primary">
                        <span class="dashicons dashicons-search" style="vertical-align:middle;margin-top:-2px;"></span>
                        <?php esc_html_e( 'Consultar', 'lealez' ); ?>
                    </button>
                    <button type="button" id="oy-kw-btn-refresh" class="button" title="<?php esc_attr_e( 'Forzar recarga desde la API ignorando caché', 'lealez' ); ?>">
                        <span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:-2px;"></span>
                        <?php esc_html_e( 'Actualizar', 'lealez' ); ?>
                    </button>
                    <button type="button" id="oy-kw-btn-save" class="button button-secondary" title="<?php esc_attr_e( 'Guarda las top 20 frases clave en los meta-campos de esta ubicación (gmb_top_queries)', 'lealez' ); ?>">
                        <span class="dashicons dashicons-cloud-saved" style="vertical-align:middle;margin-top:-2px;"></span>
                        <?php esc_html_e( 'Guardar en meta', 'lealez' ); ?>
                    </button>
                </div>
            </div><!-- /.toolbar -->

            <!-- STATUS MESSAGES -->
            <div id="oy-kw-status"      class="oy-kw-status" style="display:none;"></div>
            <div id="oy-kw-save-status" class="oy-kw-status" style="display:none;"></div>

            <!-- DEBUG PANEL (visible only when WP_DEBUG=true and result is empty/error) -->
            <div id="oy-kw-debug" class="oy-kw-debug-wrap" style="display:none;">
                <div class="oy-kw-debug-header" id="oy-kw-debug-toggle">
                    <span class="dashicons dashicons-info" style="vertical-align:middle;font-size:16px;margin-right:4px;"></span>
                    <strong><?php esc_html_e( 'Diagnóstico de API — Keywords', 'lealez' ); ?></strong>
                    <span class="oy-kw-debug-caret">▼</span>
                </div>
                <div id="oy-kw-debug-body" class="oy-kw-debug-body"></div>
            </div>

            <!-- KPI TOP 3 -->
            <div id="oy-kw-kpis" class="oy-kw-kpis" style="display:none;"></div>

            <!-- CONTEXT: Total Search Impressions vs Keywords -->
            <div id="oy-kw-context" class="oy-kw-context-wrap" style="display:none;"></div>

            <!-- CHART TOP 20 -->
            <div id="oy-kw-chart-wrap" class="oy-kw-chart-wrap" style="display:none;">
                <div class="oy-kw-section-header">
                    <h4><?php esc_html_e( 'Top 20 frases clave por impresiones', 'lealez' ); ?></h4>
                    <span id="oy-kw-period-badge" class="oy-kw-badge"></span>
                </div>
                <div class="oy-kw-chart-container">
                    <canvas id="oy-kw-main-chart"></canvas>
                </div>
            </div>

            <!-- MONTH FILTER (only visible when per-month mode is active) -->
            <div id="oy-kw-month-filter-wrap" style="display:none;margin-bottom:10px;">
                <label for="oy-kw-filter-month"><strong><?php esc_html_e( 'Filtrar tabla por mes:', 'lealez' ); ?></strong></label>
                <select id="oy-kw-filter-month" class="oy-kw-select" style="margin-left:6px;min-width:160px;">
                    <option value=""><?php esc_html_e( 'Todos los meses (total acumulado)', 'lealez' ); ?></option>
                </select>
            </div>

            <!-- DATA TABLE -->
            <div id="oy-kw-table-wrap" class="oy-kw-table-wrap" style="display:none;">
                <div class="oy-kw-section-header">
                    <h4><?php esc_html_e( 'Tabla de frases clave', 'lealez' ); ?></h4>
                    <div style="display:flex;gap:6px;">
                        <button type="button" id="oy-kw-export-csv" class="button button-small">
                            ⬇ <?php esc_html_e( 'CSV', 'lealez' ); ?>
                        </button>
                    </div>
                </div>
                <div class="oy-kw-table-scroll">
                    <table class="wp-list-table widefat striped oy-kw-table">
                        <thead id="oy-kw-thead"></thead>
                        <tbody id="oy-kw-tbody"></tbody>
                    </table>
                </div>
                <p id="oy-kw-threshold-note" class="oy-kw-threshold-note" style="display:none;">
                    <span class="dashicons dashicons-info-outline" style="font-size:14px;vertical-align:middle;"></span>
                    <?php esc_html_e( '(*) Google no muestra el valor exacto cuando las impresiones están por debajo del umbral mínimo de privacidad. Se muestra el umbral como referencia.', 'lealez' ); ?>
                </p>
            </div>

            <!-- STORED KEYWORDS INFO -->
            <?php if ( $stored_queries && is_array( $stored_queries ) && ! empty( $stored_queries ) ) : ?>
            <div id="oy-kw-stored" class="oy-kw-stored-wrap">
                <p class="oy-kw-stored-title">
                    <span class="dashicons dashicons-saved" style="color:#2e7d32;vertical-align:middle;"></span>
                    <?php
                    printf(
                        esc_html__( 'Última sincronización guardada: %s — %d frases clave almacenadas.', 'lealez' ),
                        esc_html( $last_kw_sync ? $last_kw_sync : '—' ),
                        count( $stored_queries )
                    );
                    ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- FOOTER -->
            <div id="oy-kw-footer" class="oy-kw-footer" style="display:none;">
                <span id="oy-kw-last-fetched"></span>
                <span id="oy-kw-total-info"></span>
            </div>

        </div><!-- /#oy-kw-dashboard -->
        <?php
    }

    // -----------------------------------------------------------------------
    // AJAX: Fetch Keywords
    // -----------------------------------------------------------------------

    /**
     * AJAX handler: consulta keywords de búsqueda.
     *
     * POST params:
     *   post_id       int     ID del post oy_location
     *   month_from    string  YYYY-MM  mes de inicio
     *   month_to      string  YYYY-MM  mes de fin
     *   per_month     int     0|1  si 1, hace una llamada por mes para desglose mensual
     *   force_refresh int     0|1  ignorar caché
     *
     * Action: oy_gmb_kw_fetch
     */
    public function ajax_fetch_keywords() {
        check_ajax_referer( 'oy_gmb_keywords_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'lealez' ) ), 403 );
        }

        $post_id      = absint( $_POST['post_id'] ?? 0 );
        $month_from   = sanitize_text_field( $_POST['month_from'] ?? '' );
        $month_to     = sanitize_text_field( $_POST['month_to'] ?? '' );
        $per_month    = ! empty( $_POST['per_month'] );
        $force        = ! empty( $_POST['force_refresh'] );
        $is_debug     = defined( 'WP_DEBUG' ) && WP_DEBUG;

        if ( ! $post_id || 'oy_location' !== get_post_type( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Post ID inválido.', 'lealez' ) ) );
        }

        $location_id = get_post_meta( $post_id, 'gmb_location_id', true );
        $business_id = get_post_meta( $post_id, 'parent_business_id', true );

        if ( ! $location_id || ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Faltan datos de configuración GMB (location_id o business_id).', 'lealez' ) ) );
        }

        // Parse month range
        if ( ! $month_from || ! $month_to ) {
            // Default: last 6 months
            $now       = current_time( 'timestamp' );
            $month_to  = gmdate( 'Y-m', $now );
            $month_from = gmdate( 'Y-m', mktime( 0, 0, 0, (int) gmdate( 'n', $now ) - 5, 1, (int) gmdate( 'Y', $now ) ) );
        }

        $parts_from = explode( '-', $month_from );
        $parts_to   = explode( '-', $month_to );

        if ( count( $parts_from ) !== 2 || count( $parts_to ) !== 2 ) {
            wp_send_json_error( array( 'message' => __( 'Formato de mes inválido. Usa YYYY-MM.', 'lealez' ) ) );
        }

        $start_month = array( 'year' => (int) $parts_from[0], 'month' => (int) $parts_from[1] );
        $end_month   = array( 'year' => (int) $parts_to[0],   'month' => (int) $parts_to[1] );

        if ( $start_month['year'] > $end_month['year'] ||
             ( $start_month['year'] === $end_month['year'] && $start_month['month'] > $end_month['month'] ) ) {
            wp_send_json_error( array( 'message' => __( 'El mes de inicio debe ser anterior o igual al mes de fin.', 'lealez' ) ) );
        }

        // ── Build base debug info (always populated) ────────────────────────
        $debug_info = array(
            'location_id'  => $location_id,
            'business_id'  => (int) $business_id,
            'start_month'  => sprintf( '%04d-%02d', $start_month['year'], $start_month['month'] ),
            'end_month'    => sprintf( '%04d-%02d', $end_month['year'], $end_month['month'] ),
            'mode'         => $per_month ? 'per_month' : 'flat',
            'force_cache'  => $force,
            'api_endpoint' => sprintf(
                'https://businessprofileperformance.googleapis.com/v1/locations/%s/searchkeywords/impressions/monthly',
                $location_id
            ),
            'raw_response' => null,
            'http_code'    => null,
            'items_found'  => 0,
            'error'        => null,
            'wp_debug'     => $is_debug,
        );

        if ( $per_month ) {
            // ── Mode: one call per month ─────────────────────────────────
            $monthly_data = $this->fetch_keywords_per_month(
                $business_id,
                $location_id,
                $start_month,
                $end_month,
                $force
            );

            if ( is_wp_error( $monthly_data ) ) {
                $debug_info['error'] = $monthly_data->get_error_message();
                wp_send_json_error( array(
                    'message' => $monthly_data->get_error_message(),
                    'debug'   => $is_debug ? $debug_info : null,
                ) );
            }

            $aggregated = $this->aggregate_monthly( $monthly_data );
            $debug_info['items_found'] = count( $aggregated );

            // When empty in debug mode, perform a raw API probe
            if ( empty( $aggregated ) && $is_debug ) {
                $raw = $this->probe_keywords_api_raw( $business_id, $location_id, $start_month, $end_month );
                $debug_info['raw_response'] = $raw['body'];
                $debug_info['http_code']    = $raw['code'];
            }

            wp_send_json_success( array(
                'mode'        => 'per_month',
                'aggregated'  => $aggregated,
                'monthly_raw' => $monthly_data,
                'period'      => array( 'start' => $start_month, 'end' => $end_month ),
                'fetched_at'  => current_time( 'mysql' ),
                'debug'       => $is_debug ? $debug_info : null,
            ) );

        } else {
            // ── Mode: single call for the full range ─────────────────────
            $result = Lealez_GMB_API::get_location_search_keywords(
                $business_id,
                $location_id,
                $start_month,
                $end_month,
                ! $force
            );

            if ( is_wp_error( $result ) ) {
                $debug_info['error'] = $result->get_error_message();
                // On WP_Error, always do raw probe to show actual HTTP response
                if ( $is_debug ) {
                    $raw = $this->probe_keywords_api_raw( $business_id, $location_id, $start_month, $end_month );
                    $debug_info['raw_response'] = $raw['body'];
                    $debug_info['http_code']    = $raw['code'];
                }
                wp_send_json_error( array(
                    'message' => $result->get_error_message(),
                    'debug'   => $is_debug ? $debug_info : null,
                ) );
            }

            if ( ! is_array( $result ) ) {
                $debug_info['error'] = 'Respuesta no-array de la API.';
                wp_send_json_error( array(
                    'message' => __( 'Respuesta inválida de la API de keywords.', 'lealez' ),
                    'debug'   => $is_debug ? $debug_info : null,
                ) );
            }

            $aggregated = $this->parse_flat_keywords( $result );
            $debug_info['items_found'] = count( $aggregated );

            // When empty in debug mode, do a raw API probe
            if ( empty( $aggregated ) && $is_debug ) {
                $raw = $this->probe_keywords_api_raw( $business_id, $location_id, $start_month, $end_month );
                $debug_info['raw_response'] = $raw['body'];
                $debug_info['http_code']    = $raw['code'];
            }

            wp_send_json_success( array(
                'mode'       => 'flat',
                'aggregated' => $aggregated,
                'period'     => array( 'start' => $start_month, 'end' => $end_month ),
                'fetched_at' => current_time( 'mysql' ),
                'debug'      => $is_debug ? $debug_info : null,
            ) );
        }
    }

    // -----------------------------------------------------------------------
    // Debug: Raw API Probe
    // -----------------------------------------------------------------------

    /**
     * Realiza una petición HTTP directa al endpoint de keywords y devuelve la
     * respuesta sin procesar. Solo se llama cuando WP_DEBUG = true y el resultado
     * principal está vacío, para mostrar en el panel de diagnóstico.
     *
     * @param int    $business_id
     * @param string $location_id   Numeric location ID.
     * @param array  $start_month   ['year'=>int, 'month'=>int]
     * @param array  $end_month     ['year'=>int, 'month'=>int]
     * @return array  ['code'=>int, 'body'=>string, 'error'=>string|null]
     */
    private function probe_keywords_api_raw( $business_id, $location_id, $start_month, $end_month ) {
        $result = array( 'code' => 0, 'body' => '', 'error' => null );

        if ( ! class_exists( 'Lealez_GMB_Encryption' ) ) {
            $result['error'] = 'Clase Lealez_GMB_Encryption no disponible.';
            return $result;
        }

        $tokens = Lealez_GMB_Encryption::get_tokens( $business_id );
        if ( ! $tokens || empty( $tokens['access_token'] ) ) {
            $result['error'] = 'No se encontró access_token para business_id ' . $business_id . '.';
            return $result;
        }

        // Strip "locations/" prefix if it came through extract_location_id_from_any already
        $loc_id_clean = $location_id;
        if ( strpos( $loc_id_clean, 'locations/' ) === 0 ) {
            $loc_id_clean = substr( $loc_id_clean, strlen( 'locations/' ) );
        }

        $qs = http_build_query( array(
            'monthlyRange.startMonth.year'  => (int) ( $start_month['year']  ?? 0 ),
            'monthlyRange.startMonth.month' => (int) ( $start_month['month'] ?? 0 ),
            'monthlyRange.endMonth.year'    => (int) ( $end_month['year']    ?? 0 ),
            'monthlyRange.endMonth.month'   => (int) ( $end_month['month']   ?? 0 ),
            'pageSize'                      => 20,
        ) );

        $url = 'https://businessprofileperformance.googleapis.com/v1/locations/' . rawurlencode( $loc_id_clean )
               . '/searchkeywords/impressions/monthly?' . $qs;

        $result['url'] = $url;

        $response = wp_remote_get( $url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $tokens['access_token'],
                'Content-Type'  => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            $result['error'] = 'wp_remote_get error: ' . $response->get_error_message();
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[Lealez KW DEBUG PROBE] ' . $result['error'] );
            }
            return $result;
        }

        $result['code'] = wp_remote_retrieve_response_code( $response );
        $result['body'] = wp_remote_retrieve_body( $response );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[Lealez KW DEBUG PROBE] HTTP %d | URL: %s | Body: %s',
                $result['code'],
                $url,
                substr( $result['body'], 0, 1000 )
            ) );
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // AJAX: Save Keywords to Post Meta
    // -----------------------------------------------------------------------

    /**
     * AJAX handler: guarda las top keywords en el meta del post.
     *
     * Guarda en:
     *   - gmb_top_queries        JSON: [{query: string, count: int}, ...]  top 20
     *   - gmb_keywords_last_sync timestamp
     *
     * Action: oy_gmb_kw_save
     */
    public function ajax_save_keywords() {
        check_ajax_referer( 'oy_gmb_keywords_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'lealez' ) ), 403 );
        }

        $post_id    = absint( $_POST['post_id'] ?? 0 );
        $raw_data   = isset( $_POST['keywords'] ) ? wp_unslash( $_POST['keywords'] ) : '';

        if ( ! $post_id || 'oy_location' !== get_post_type( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Post ID inválido.', 'lealez' ) ) );
        }

        // Decode JSON sent from JS
        $keywords = json_decode( $raw_data, true );
        if ( ! is_array( $keywords ) ) {
            wp_send_json_error( array( 'message' => __( 'Datos de keywords inválidos.', 'lealez' ) ) );
        }

        // Sanitize and limit to top 20
        $sanitized = array();
        $limit      = 20;
        $count      = 0;
        foreach ( $keywords as $kw ) {
            if ( $count >= $limit ) {
                break;
            }
            $text  = isset( $kw['keyword'] ) ? sanitize_text_field( $kw['keyword'] ) : '';
            $total = isset( $kw['total'] ) ? absint( $kw['total'] ) : 0;
            if ( $text === '' ) {
                continue;
            }
            $sanitized[] = array(
                'query' => $text,
                'count' => $total,
            );
            $count++;
        }

        $sync_time = current_time( 'mysql' );

        update_post_meta( $post_id, 'gmb_top_queries', $sanitized );
        update_post_meta( $post_id, 'gmb_keywords_last_sync', $sync_time );

        wp_send_json_success( array(
            'message'   => sprintf(
                __( '%d frases clave guardadas correctamente.', 'lealez' ),
                count( $sanitized )
            ),
            'saved'     => count( $sanitized ),
            'synced_at' => $sync_time,
        ) );
    }

    // -----------------------------------------------------------------------
    // AJAX: Fetch Total Search Impressions Context
    // -----------------------------------------------------------------------

    /**
     * AJAX handler: obtiene el total de impresiones en Google Search (desktop + mobile)
     * para el mismo período del filtro de keywords, usando el endpoint fetchMultiDailyMetricsTimeSeries.
     *
     * Esto permite mostrar cuántas impresiones totales de búsqueda tuvo el negocio
     * y qué porcentaje de esas impresiones están "explicadas" por las frases clave visibles.
     *
     * Nota oficial (Google Business Profile Performance API - DailyMetric enum):
     *   BUSINESS_IMPRESSIONS_DESKTOP_SEARCH → impresiones en Google Search desde Desktop
     *   BUSINESS_IMPRESSIONS_MOBILE_SEARCH  → impresiones en Google Search desde Mobile
     *   Ambas cuentan usuarios únicos por día.
     *
     * POST params:
     *   post_id       int     ID del post oy_location
     *   month_from    string  YYYY-MM
     *   month_to      string  YYYY-MM
     *   force_refresh int     0|1
     *
     * Action: oy_gmb_kw_context
     */
    public function ajax_fetch_search_context() {
        check_ajax_referer( 'oy_gmb_keywords_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'lealez' ) ), 403 );
        }

        $post_id    = absint( $_POST['post_id'] ?? 0 );
        $month_from = sanitize_text_field( $_POST['month_from'] ?? '' );
        $month_to   = sanitize_text_field( $_POST['month_to'] ?? '' );
        $force      = ! empty( $_POST['force_refresh'] );

        if ( ! $post_id || 'oy_location' !== get_post_type( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Post ID inválido.', 'lealez' ) ) );
        }

        $location_id = get_post_meta( $post_id, 'gmb_location_id', true );
        $business_id = get_post_meta( $post_id, 'parent_business_id', true );

        if ( ! $location_id || ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Faltan datos de configuración GMB.', 'lealez' ) ) );
        }

        // Parse month range and convert to daily range
        if ( ! $month_from || ! $month_to ) {
            wp_send_json_error( array( 'message' => __( 'Rango de meses requerido.', 'lealez' ) ) );
        }

        $parts_from = explode( '-', $month_from );
        $parts_to   = explode( '-', $month_to );

        if ( count( $parts_from ) !== 2 || count( $parts_to ) !== 2 ) {
            wp_send_json_error( array( 'message' => __( 'Formato de mes inválido.', 'lealez' ) ) );
        }

        $start_year  = (int) $parts_from[0];
        $start_month = (int) $parts_from[1];
        $end_year    = (int) $parts_to[0];
        $end_month   = (int) $parts_to[1];

        // Daily range: first day of start_month → last day of end_month
        $start_date = array( 'year' => $start_year, 'month' => $start_month, 'day' => 1 );
        $last_day   = (int) gmdate( 't', mktime( 0, 0, 0, $end_month, 1, $end_year ) );
        $end_date   = array( 'year' => $end_year, 'month' => $end_month, 'day' => $last_day );

        // Fetch both Search metrics (Desktop + Mobile) in one call
        $metrics = array(
            'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
            'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
        );

        $result = Lealez_GMB_API::get_location_performance_metrics(
            $business_id,
            $location_id,
            $metrics,
            $start_date,
            $end_date,
            ! $force
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
            ) );
        }

        // Sum all daily values for Desktop Search + Mobile Search
        $total_desktop_search = 0;
        $total_mobile_search  = 0;

        if ( ! empty( $result['multiDailyMetricTimeSeries'] ) ) {
            foreach ( $result['multiDailyMetricTimeSeries'] as $series_group ) {
                if ( empty( $series_group['dailyMetricTimeSeries'] ) ) {
                    continue;
                }
                foreach ( $series_group['dailyMetricTimeSeries'] as $series ) {
                    $metric_name = $series['dailyMetric'] ?? '';
                    if ( empty( $series['timeSeries']['datedValues'] ) ) {
                        continue;
                    }
                    foreach ( $series['timeSeries']['datedValues'] as $dv ) {
                        $day_value = isset( $dv['value'] ) ? (int) $dv['value'] : 0;
                        if ( 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' === $metric_name ) {
                            $total_desktop_search += $day_value;
                        } elseif ( 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH' === $metric_name ) {
                            $total_mobile_search += $day_value;
                        }
                    }
                }
            }
        }

        $total_search = $total_desktop_search + $total_mobile_search;

        wp_send_json_success( array(
            'total_search'          => $total_search,
            'total_desktop_search'  => $total_desktop_search,
            'total_mobile_search'   => $total_mobile_search,
            'period_start'          => sprintf( '%04d-%02d-01', $start_year, $start_month ),
            'period_end'            => sprintf( '%04d-%02d-%02d', $end_year, $end_month, $last_day ),
            'note'                  => __( 'Impresiones únicas por usuario/día en Google Search. Fuente: Business Profile Performance API (BUSINESS_IMPRESSIONS_DESKTOP_SEARCH + BUSINESS_IMPRESSIONS_MOBILE_SEARCH).', 'lealez' ),
        ) );
    }

    // -----------------------------------------------------------------------
    // Data Processing Helpers
    // -----------------------------------------------------------------------

    /**
     * Parse the flat keyword response (single API call for full range).
     *
     * GMB API response shape for searchkeywords/impressions/monthly:
     *   searchKeywordCounts: [
     *     {
     *       searchKeyword: "keyword text",     // plain string
     *       insightsValue: {
     *         value: "45",                     // impressions as string
     *         threshold: "15"                  // privacy threshold (optional)
     *       }
     *     }, ...
     *   ]
     *
     * @param array $raw_keywords Raw API items (already extracted from searchKeywordCounts).
     * @return array  Sorted array of ['keyword', 'total', 'threshold', 'below_threshold'].
     */
    private function parse_flat_keywords( array $raw_keywords ) {
        $out = array();

        foreach ( $raw_keywords as $item ) {
            // Keyword text: always a plain string in the API response
            $keyword = '';
            if ( ! empty( $item['searchKeyword'] ) && is_string( $item['searchKeyword'] ) ) {
                $keyword = (string) $item['searchKeyword'];
            }

            if ( '' === $keyword ) {
                continue;
            }

            // Impressions value (string in API, cast to int)
            $value     = isset( $item['insightsValue']['value'] )
                ? (int) $item['insightsValue']['value']
                : 0;

            // Privacy threshold — when present and value equals threshold,
            // Google is masking the real number.
            $threshold        = isset( $item['insightsValue']['threshold'] )
                ? (int) $item['insightsValue']['threshold']
                : 0;
            $below_threshold  = ( $threshold > 0 && $value <= $threshold );

            $out[] = array(
                'keyword'         => $keyword,
                'total'           => $value,
                'threshold'       => $threshold,
                'below_threshold' => $below_threshold,
                'monthly'         => array(), // no monthly data in flat mode
            );
        }

        // Sort by total descending
        usort( $out, function( $a, $b ) {
            return $b['total'] - $a['total'];
        } );

        return $out;
    }

    /**
     * Fetch keywords one call per month, building a monthly breakdown.
     *
     * @param int    $business_id
     * @param string $location_id
     * @param array  $start_month  ['year'=>int, 'month'=>int]
     * @param array  $end_month    ['year'=>int, 'month'=>int]
     * @param bool   $force        Ignore cache.
     * @return array|WP_Error  ['YYYY-MM' => parsed_flat_keywords_array, ...]
     */
    private function fetch_keywords_per_month( $business_id, $location_id, $start_month, $end_month, $force ) {
        $months    = $this->build_month_list( $start_month, $end_month );
        $all_data  = array();
        $max_calls = 12; // Safety: no more than 12 months
        $calls     = 0;

        foreach ( $months as $month ) {
            if ( $calls >= $max_calls ) {
                break;
            }

            $m_start = $month;
            $m_end   = $month;

            $result = Lealez_GMB_API::get_location_search_keywords(
                $business_id,
                $location_id,
                $m_start,
                $m_end,
                ! $force
            );

            $month_key = sprintf( '%04d-%02d', $month['year'], $month['month'] );

            if ( is_wp_error( $result ) ) {
                // Store error note but continue
                $all_data[ $month_key ] = array();
            } else {
                $all_data[ $month_key ] = $this->parse_flat_keywords( is_array( $result ) ? $result : array() );
            }

            $calls++;
        }

        return $all_data;
    }

    /**
     * Aggregate per-month keyword data into a unified keyword list with monthly breakdown.
     *
     * @param array $monthly_data ['YYYY-MM' => flat_keywords_array, ...]
     * @return array  [['keyword', 'total', 'monthly'=>['YYYY-MM'=>int,...], 'below_threshold'], ...]
     */
    private function aggregate_monthly( array $monthly_data ) {
        $agg = array();

        foreach ( $monthly_data as $month_key => $keywords ) {
            foreach ( $keywords as $kw ) {
                $text = $kw['keyword'];
                if ( '' === $text ) {
                    continue;
                }

                if ( ! isset( $agg[ $text ] ) ) {
                    $agg[ $text ] = array(
                        'keyword'         => $text,
                        'total'           => 0,
                        'monthly'         => array(),
                        'below_threshold' => false,
                    );
                }

                $agg[ $text ]['total']                 += $kw['total'];
                $agg[ $text ]['monthly'][ $month_key ]  = $kw['total'];
                if ( $kw['below_threshold'] ) {
                    $agg[ $text ]['below_threshold'] = true;
                }
            }
        }

        // Sort by total descending
        uasort( $agg, function( $a, $b ) {
            return $b['total'] - $a['total'];
        } );

        return array_values( $agg );
    }

    /**
     * Build a list of year/month arrays from start to end (inclusive).
     *
     * @param array $start_month ['year'=>int, 'month'=>int]
     * @param array $end_month   ['year'=>int, 'month'=>int]
     * @return array  [['year'=>int, 'month'=>int], ...]
     */
    private function build_month_list( array $start_month, array $end_month ) {
        $list = array();
        $y    = (int) $start_month['year'];
        $m    = (int) $start_month['month'];
        $ey   = (int) $end_month['year'];
        $em   = (int) $end_month['month'];

        while ( $y < $ey || ( $y === $ey && $m <= $em ) ) {
            $list[] = array( 'year' => $y, 'month' => $m );
            $m++;
            if ( $m > 12 ) {
                $m = 1;
                $y++;
            }
        }

        return $list;
    }

    // -----------------------------------------------------------------------
    // Inline JS
    // -----------------------------------------------------------------------

    private function get_inline_js() {
        // phpcs:disable
        return <<<'JS'
(function($){
    'use strict';

    // Guard: only run if our dashboard is present
    if (!$('#oy-kw-dashboard').length) { return; }

    // Guard: wait for oyKwConfig to be set (localized via wp_localize_script on chartjs-v4)
    if (typeof oyKwConfig === 'undefined') { return; }

    var OyKw = {
        postId       : oyKwConfig.postId,
        nonce        : oyKwConfig.nonce,
        ajaxUrl      : oyKwConfig.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php',
        isDebug      : (oyKwConfig.isDebug === 1 || oyKwConfig.isDebug === '1'),
        chartInstance: null,
        lastData     : null,
        contextData  : null,
        monthNames   : ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'],

        init: function(){
            var self = this;
            self.populateMonthSelects();

            // Fetch on init automatically
            self.fetchKeywords(false, false);

            // Buttons
            $('#oy-kw-btn-fetch').on('click', function(){
                var perMonth = $('#oy-kw-per-month').is(':checked');
                self.fetchKeywords(false, perMonth);
            });
            $('#oy-kw-btn-refresh').on('click', function(){
                var perMonth = $('#oy-kw-per-month').is(':checked');
                self.fetchKeywords(true, perMonth);
            });
            $('#oy-kw-btn-save').on('click', function(){
                self.saveKeywords();
            });
            $('#oy-kw-export-csv').on('click', function(){
                if (self.lastData) self.exportCSV(self.lastData);
            });

            // Month filter (per-month mode)
            $('#oy-kw-filter-month').on('change', function(){
                if (self.lastData) {
                    self.buildTable(self.lastData, $(this).val());
                }
            });

            // Debug panel toggle
            $('#oy-kw-debug-toggle').on('click', function(){
                $('#oy-kw-debug-body').slideToggle(160);
                var $c = $(this).find('.oy-kw-debug-caret');
                $c.text($c.text() === '▼' ? '▲' : '▼');
            });
        },

        // ----------------------------------------------------------------
        // Populate selects
        // ----------------------------------------------------------------

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
                opts  += '<option value="'+v+'">'+lb+'</option>';
            }

            $('#oy-kw-month-from').html(opts);
            $('#oy-kw-month-to').html(opts);

            // FIX: default avoids the current month (API lag ~30 days).
            // from = 7 months ago, to = 2 months ago.
            var fromD = new Date(now.getFullYear(), now.getMonth() - 7, 1);
            var toD   = new Date(now.getFullYear(), now.getMonth() - 2, 1);
            var fromV = fromD.getFullYear() + '-' + String(fromD.getMonth()+1).padStart(2,'0');
            var toV   = toD.getFullYear()   + '-' + String(toD.getMonth()+1).padStart(2,'0');
            $('#oy-kw-month-from').val(fromV);
            $('#oy-kw-month-to').val(toV);
        },

        // ----------------------------------------------------------------
        // Fetch
        // ----------------------------------------------------------------

        fetchKeywords: function(forceRefresh, perMonth){
            var self     = this;
            var fromVal  = $('#oy-kw-month-from').val();
            var toVal    = $('#oy-kw-month-to').val();

            self.showStatus('loading', perMonth
                ? 'Consultando frases clave mes a mes... esto puede tardar unos segundos.'
                : 'Consultando frases clave en Google Business Profile...'
            );
            self.hideResults();

            $.post(self.ajaxUrl, {
                action        : 'oy_gmb_kw_fetch',
                nonce         : self.nonce,
                post_id       : self.postId,
                month_from    : fromVal,
                month_to      : toVal,
                per_month     : perMonth ? 1 : 0,
                force_refresh : forceRefresh ? 1 : 0,
            }, function(resp){
                self.hideStatus();

                if (!resp.success) {
                    var errMsg = (resp.data && resp.data.message) ? resp.data.message : 'Error al consultar keywords.';
                    self.showStatus('error', errMsg);
                    // Show debug on error too
                    if (resp.data && resp.data.debug) {
                        self.buildDebugPanel(resp.data.debug);
                    }
                    return;
                }

                var data       = resp.data;
                var aggregated = data.aggregated || [];

                if (!aggregated.length) {
                    self.showStatus('info', 'No se encontraron frases clave para el período seleccionado. La API de keywords puede tardar hasta 7 días en actualizar datos nuevos.');
                    // Show debug panel when empty
                    if (data.debug) {
                        self.buildDebugPanel(data.debug);
                    }
                    return;
                }

                // Hide debug panel when we have results
                $('#oy-kw-debug').hide();

                self.lastData = data;

                // Period badge
                var p = data.period || {};
                if (p.start && p.end) {
                    var label = self.monthNames[(p.start.month||1)-1]+' '+p.start.year
                                + ' — '
                                + self.monthNames[(p.end.month||1)-1]+' '+p.end.year;
                    $('#oy-kw-period-badge').text(label);
                }

                // Show/hide month filter
                var isPerMonth = (data.mode === 'per_month');
                if (isPerMonth && p.start && p.end) {
                    self.buildMonthFilter(p.start, p.end, data.monthly_raw || {});
                    $('#oy-kw-month-filter-wrap').show();
                } else {
                    $('#oy-kw-month-filter-wrap').hide();
                }

                // KPIs top 3
                self.buildKPIs(aggregated);

                // FIX: table and footer render first — isolated from buildChart.
                // Chart.js throws when the canvas is inside a display:none parent
                // (0x0 dimensions). That exception was silently aborting the rest
                // of this callback, so buildTable and the footer never ran.
                self.buildTable(data, '');

                // Footer
                $('#oy-kw-last-fetched').text('Última consulta: ' + (data.fetched_at || ''));
                $('#oy-kw-total-info').text(' | ' + aggregated.length + ' frases clave encontradas');
                $('#oy-kw-footer').show();

                // Chart top 20 — after table so any Chart.js exception is isolated
                try {
                    self.buildChart(aggregated);
                } catch(chartErr) {
                    if (window.console && console.warn) {
                        console.warn('[OyKw] buildChart error (non-fatal):', chartErr);
                    }
                }

                // Fetch total search impressions context (non-blocking, async)
                self.fetchSearchContext(fromVal, toVal, forceRefresh);

            }).fail(function(xhr){
                self.showStatus('error', 'Error de conexión: ' + (xhr.statusText || 'desconocido'));
            });
        },

        // ----------------------------------------------------------------
        // Fetch Total Search Impressions Context
        // ----------------------------------------------------------------

        /**
         * Consulta el total de impresiones en Google Search (Desktop + Mobile)
         * para el mismo período. Usa fetchMultiDailyMetricsTimeSeries.
         *
         * Nota: La GBP Performance API NO proporciona clicks ni posición por
         * frase clave. Solo impresiones mensuales. El contexto de búsqueda
         * permite saber cuántas impresiones totales hubo en Search y qué
         * cobertura tienen las frases clave visibles.
         */
        fetchSearchContext: function(monthFrom, monthTo, forceRefresh){
            var self = this;
            $('#oy-kw-context').html(
                '<div class="oy-kw-ctx-loading"><span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span>' +
                'Cargando contexto de impresiones en Search...</div>'
            ).show();

            $.post(self.ajaxUrl, {
                action        : 'oy_gmb_kw_context',
                nonce         : self.nonce,
                post_id       : self.postId,
                month_from    : monthFrom,
                month_to      : monthTo,
                force_refresh : forceRefresh ? 1 : 0,
            }, function(resp){
                if (!resp.success || !resp.data) {
                    $('#oy-kw-context').hide();
                    return;
                }
                self.contextData = resp.data;
                var kwData = self.lastData && self.lastData.aggregated ? self.lastData.aggregated : [];
                self.buildContextPanel(resp.data, kwData);
            }).fail(function(){
                $('#oy-kw-context').hide();
            });
        },

        // ----------------------------------------------------------------
        // Build Context Panel
        // ----------------------------------------------------------------

        buildContextPanel: function(ctx, aggregated){
            var self = this;

            // Sum all keyword impressions
            var totalKwImpressions = 0;
            $.each(aggregated, function(i, kw){ totalKwImpressions += (kw.total || 0); });

            var totalSearch    = ctx.total_search || 0;
            var totalDesktop   = ctx.total_desktop_search || 0;
            var totalMobile    = ctx.total_mobile_search || 0;

            // Coverage: keyword impressions / total search impressions
            // (These count differently: keywords = unique users/month, DailyMetric = unique users/day summed.
            //  Coverage > 100% is possible. We cap display at 100% for UX clarity.)
            var coveragePct = totalSearch > 0
                ? Math.min(100, Math.round((totalKwImpressions / totalSearch) * 100))
                : 0;

            var mobilePct = totalSearch > 0
                ? Math.round((totalMobile / totalSearch) * 100)
                : 0;
            var desktopPct = totalSearch > 0
                ? Math.round((totalDesktop / totalSearch) * 100)
                : 0;

            var coverageColor = coveragePct >= 70 ? '#2e7d32' : (coveragePct >= 40 ? '#f57f17' : '#c62828');

            var html =
                '<div class="oy-kw-ctx-panel">' +
                    '<div class="oy-kw-ctx-header">' +
                        '<span class="dashicons dashicons-chart-area" style="vertical-align:middle;color:#1a73e8;margin-right:6px;"></span>' +
                        '<strong>Contexto de Búsqueda — Google Search (mismo período)</strong>' +
                        '<span class="oy-kw-ctx-badge">Performance API · BUSINESS_IMPRESSIONS_SEARCH</span>' +
                    '</div>' +
                    '<div class="oy-kw-ctx-grid">' +

                        // Total Search Impressions
                        '<div class="oy-kw-ctx-card">' +
                            '<div class="oy-kw-ctx-label">Total Impresiones en Search</div>' +
                            '<div class="oy-kw-ctx-value">' + self.formatNum(totalSearch) + '</div>' +
                            '<div class="oy-kw-ctx-sub">Usuarios únicos que vieron el perfil en Google Search</div>' +
                        '</div>' +

                        // Desktop vs Mobile
                        '<div class="oy-kw-ctx-card">' +
                            '<div class="oy-kw-ctx-label">Dispositivos</div>' +
                            '<div class="oy-kw-ctx-device">' +
                                '<span class="dashicons dashicons-desktop" style="color:#4285f4;"></span> ' +
                                '<strong>' + self.formatNum(totalDesktop) + '</strong> Desktop <em>(' + desktopPct + '%)</em>' +
                            '</div>' +
                            '<div class="oy-kw-ctx-device">' +
                                '<span class="dashicons dashicons-smartphone" style="color:#34a853;"></span> ' +
                                '<strong>' + self.formatNum(totalMobile) + '</strong> Mobile <em>(' + mobilePct + '%)</em>' +
                            '</div>' +
                        '</div>' +

                        // Keyword impressions vs total search
                        '<div class="oy-kw-ctx-card">' +
                            '<div class="oy-kw-ctx-label">Cobertura de Frases Clave</div>' +
                            '<div class="oy-kw-ctx-value" style="color:' + coverageColor + ';">' + coveragePct + '%</div>' +
                            '<div class="oy-kw-ctx-sub">' +
                                self.formatNum(totalKwImpressions) + ' imp. en frases clave visibles' +
                                (coveragePct >= 100 ? ' <span title="Las frases clave se cuentan mensualmente por usuario; las impresiones diarias pueden ser mayores">ℹ️</span>' : '') +
                            '</div>' +
                        '</div>' +

                    '</div>' +
                    '<p class="oy-kw-ctx-note">' +
                        '⚠️ <strong>Limitación de la API:</strong> La GBP Performance API ' +
                        '<strong>no proporciona clics ni posición por frase clave</strong> ' +
                        '(esos datos están en Google Search Console). Las frases clave solo incluyen impresiones mensuales.' +
                    '</p>' +
                '</div>';

            $('#oy-kw-context').html(html).show();

            // Also rebuild table to update % share column if context changed coverage
            if (self.lastData) {
                self.buildTable(self.lastData, $('#oy-kw-filter-month').val() || '');
            }
        },

        // ----------------------------------------------------------------
        // Save to Post Meta
        // ----------------------------------------------------------------

        saveKeywords: function(){
            var self = this;
            if (!self.lastData || !self.lastData.aggregated || !self.lastData.aggregated.length) {
                self.showSaveStatus('error', 'No hay frases clave cargadas para guardar. Consulta primero.');
                return;
            }

            var $btn    = $('#oy-kw-btn-save');
            var payload = JSON.stringify(self.lastData.aggregated);

            $btn.prop('disabled', true);
            self.showSaveStatus('loading', 'Guardando frases clave...');

            $.post(self.ajaxUrl, {
                action  : 'oy_gmb_kw_save',
                nonce   : self.nonce,
                post_id : self.postId,
                keywords: payload,
            }, function(resp){
                $btn.prop('disabled', false);
                if (!resp.success) {
                    self.showSaveStatus('error', (resp.data && resp.data.message) ? resp.data.message : 'Error al guardar.');
                    return;
                }
                // FIX: showSaveStatus already prepends the ✅ icon — do not duplicate it here.
                self.showSaveStatus('success',
                    resp.data.message + ' — ' + (resp.data.synced_at || '')
                );
            }).fail(function(xhr){
                $btn.prop('disabled', false);
                self.showSaveStatus('error', 'Error de conexión: ' + (xhr.statusText || 'desconocido'));
            });
        },

        // ----------------------------------------------------------------
        // Build UI components
        // ----------------------------------------------------------------

        buildKPIs: function(aggregated){
            var self   = this;
            var top3   = aggregated.slice(0, 3);
            var $kpis  = $('#oy-kw-kpis');
            $kpis.empty();

            if (!top3.length) { $kpis.hide(); return; }

            // Calculate total keyword impressions for % share
            var totalKwImpressions = 0;
            $.each(aggregated, function(i, kw){ totalKwImpressions += (kw.total || 0); });

            var colors = ['#4285f4','#34a853','#fbbc05'];
            $.each(top3, function(i, kw){
                var badge = kw.below_threshold
                    ? '<span class="oy-kw-threshold-badge" title="Valor aproximado">~</span>'
                    : '';
                var sharePct = totalKwImpressions > 0
                    ? (Math.round((kw.total / totalKwImpressions) * 1000) / 10).toFixed(1)
                    : '0.0';
                $kpis.append(
                    '<div class="oy-kw-kpi-card" style="border-top:3px solid '+colors[i]+';">' +
                        '<div class="oy-kw-kpi-rank">#'+(i+1)+'</div>' +
                        '<div class="oy-kw-kpi-keyword" title="'+self.escHtml(kw.keyword)+'">'+self.escHtml(kw.keyword)+'</div>' +
                        '<div class="oy-kw-kpi-value">'+self.formatNum(kw.total)+badge+' <span>imp.</span></div>' +
                        '<div class="oy-kw-kpi-share">'+sharePct+'% del total</div>' +
                    '</div>'
                );
            });
            $kpis.show();
        },

buildChart: function(aggregated){
    var self  = this;
    var top20 = aggregated.slice(0, 20);
    var $wrap = $('#oy-kw-chart-wrap');

    if (!top20.length) { $wrap.hide(); return; }

    // ── Guard: si Chart.js aún no está listo, reintenta cuando lo esté ────────
    if (typeof Chart === 'undefined') {
        if (typeof waitForChart === 'function') {
            waitForChart(function(){ self.buildChart(aggregated); });
        } else {
            console.warn('[OyKw] Chart.js no disponible (buildChart).');
        }
        return;
    }
    // ───────────────────────────────────────────────────────────────────────────

    if (self.chartInstance) {
        self.chartInstance.destroy();
        self.chartInstance = null;
    }

    var labels  = top20.map(function(kw){ return kw.keyword; });
    var values  = top20.map(function(kw){ return kw.total; });
    var palette = [
        '#4285f4','#34a853','#fbbc05','#ea4335','#9c27b0',
        '#00bcd4','#ff9800','#795548','#607d8b','#e91e63',
        '#1565c0','#2e7d32','#f57f17','#bf360c','#6a1b9a',
        '#00838f','#ad1457','#4e342e','#546e7a','#c62828'
    ];
    var bgColors = labels.map(function(l, i){ return palette[i % palette.length]; });

    // Dynamic chart height based on number of items
    var chartH = Math.max(280, top20.length * 28);
    $('.oy-kw-chart-container').css('height', chartH + 'px');

    var ctx = document.getElementById('oy-kw-main-chart');
    if (!ctx) { return; }

    // FIX: show the wrapper BEFORE new Chart() so canvas has real dimensions.
    // Chart.js reads canvas.offsetWidth/Height at instantiation — 0x0 throws.
    $wrap.show();

    self.chartInstance = new Chart(ctx, {
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
            maintainAspectRatio: false,
            plugins: {
                legend : { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx){
                            return ' ' + self.formatNum(ctx.raw) + ' impresiones';
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
                        font    : { size: 11 },
                        callback: function(val, index){
                            var label = labels[index] || '';
                            return label.length > 30 ? label.substring(0,27)+'…' : label;
                        }
                    }
                }
            }
        }
    });
},

        buildMonthFilter: function(startM, endM, monthlyRaw){
            var self = this;
            var opts = '<option value="">'+self.escHtml('Todos los meses (total acumulado)')+'</option>';
            var keys = Object.keys(monthlyRaw).sort().reverse(); // newest first
            $.each(keys, function(i, k){
                var parts = k.split('-');
                var label = self.monthNames[(parseInt(parts[1],10)-1)] + ' ' + parts[0];
                var count = (monthlyRaw[k] || []).length;
                opts += '<option value="'+self.escHtml(k)+'">'+self.escHtml(label)+' ('+count+' frases)</option>';
            });
            $('#oy-kw-filter-month').html(opts);
        },

        buildTable: function(data, selectedMonth){
            var self       = this;
            var aggregated = data.aggregated || [];
            var isPerMonth = (data.mode === 'per_month');
            var monthlyRaw = data.monthly_raw || {};
            var months     = Object.keys(monthlyRaw).sort();

            // Determine dataset to show
            var rows = [];
            if (selectedMonth && selectedMonth !== '' && isPerMonth) {
                // Filter: show only keywords from that month
                var mData = monthlyRaw[selectedMonth] || [];
                rows = mData.slice(); // already sorted
            } else {
                rows = aggregated.slice();
            }

            // Calculate total keyword impressions for % share column
            var totalKwImpressions = 0;
            $.each(rows, function(i, kw){ totalKwImpressions += (kw.total || 0); });

            // Build header
            var headHtml = '<tr><th style="width:40px;">#</th><th>Frase Clave</th>' +
                '<th style="text-align:right;">Impresiones</th>' +
                '<th style="text-align:right;white-space:nowrap;" title="Porcentaje del total de impresiones de frases clave en el período">% Share</th>';
            if (isPerMonth && !selectedMonth) {
                $.each(months, function(i, mk){
                    var parts = mk.split('-');
                    headHtml += '<th style="text-align:right;font-size:11px;">'+self.monthNames[(parseInt(parts[1],10)-1)]+' '+parts[0]+'</th>';
                });
                headHtml += '<th style="text-align:center;font-size:11px;">Tendencia</th>';
            }
            headHtml += '</tr>';
            $('#oy-kw-thead').html(headHtml);

            // Build body
            var bodyHtml       = '';
            var hasThreshold   = false;

            $.each(rows, function(i, kw){
                var thBadge = '';
                if (kw.below_threshold || kw.below_threshold === true) {
                    thBadge    = ' <sup class="oy-kw-threshold-sup" title="Valor aproximado por umbral de privacidad">*</sup>';
                    hasThreshold = true;
                }

                // % Share calculation
                var sharePct = totalKwImpressions > 0
                    ? (Math.round((kw.total / totalKwImpressions) * 1000) / 10).toFixed(1)
                    : '0.0';
                var shareBarWidth = totalKwImpressions > 0
                    ? Math.round((kw.total / totalKwImpressions) * 100)
                    : 0;

                bodyHtml += '<tr>';
                bodyHtml += '<td><strong>'+(i+1)+'</strong></td>';
                bodyHtml += '<td>'+self.escHtml(String(kw.keyword))+'</td>';
                bodyHtml += '<td style="text-align:right;font-weight:700;">'+self.formatNum(kw.total)+thBadge+'</td>';
                bodyHtml += '<td style="text-align:right;white-space:nowrap;">' +
                    '<span style="font-weight:600;color:#1a73e8;">'+sharePct+'%</span>' +
                    '<div style="background:#e8f0fe;border-radius:3px;height:4px;margin-top:3px;width:60px;display:inline-block;vertical-align:middle;margin-left:6px;">' +
                        '<div style="background:#1a73e8;border-radius:3px;height:4px;width:'+shareBarWidth+'%;"></div>' +
                    '</div>' +
                    '</td>';

                // Monthly columns (only in per-month mode, no filter active)
                if (isPerMonth && !selectedMonth) {
                    var monthly = kw.monthly || {};
                    $.each(months, function(j, mk){
                        var mv = monthly[mk] !== undefined ? monthly[mk] : 0;
                        bodyHtml += '<td style="text-align:right;font-size:12px;color:#555;">'+(mv > 0 ? self.formatNum(mv) : '—')+'</td>';
                    });

                    // Trend: compare last 2 months
                    var sortedKeys = Object.keys(monthly).sort();
                    var trend = '';
                    if (sortedKeys.length >= 2) {
                        var last = monthly[sortedKeys[sortedKeys.length-1]] || 0;
                        var prev = monthly[sortedKeys[sortedKeys.length-2]] || 0;
                        if (last > prev)      trend = '<span style="color:#2e7d32;font-weight:700;">▲</span>';
                        else if (last < prev) trend = '<span style="color:#c62828;font-weight:700;">▼</span>';
                        else                  trend = '<span style="color:#999;">→</span>';
                    }
                    bodyHtml += '<td style="text-align:center;">'+trend+'</td>';
                }

                bodyHtml += '</tr>';
            });

            if (!bodyHtml) {
                var colspan = isPerMonth && !selectedMonth ? (4 + months.length + 1) : 4;
                bodyHtml = '<tr><td colspan="'+colspan+'" style="text-align:center;color:#888;padding:16px;">Sin datos disponibles para este período.</td></tr>';
            }

            $('#oy-kw-tbody').html(bodyHtml);
            $('#oy-kw-table-wrap').show();

            // Threshold note
            if (hasThreshold) {
                $('#oy-kw-threshold-note').show();
            } else {
                $('#oy-kw-threshold-note').hide();
            }
        },

        // ----------------------------------------------------------------
        // Debug Panel
        // ----------------------------------------------------------------

        buildDebugPanel: function(debug){
            if (!debug) { return; }

            var self = this;
            var httpCode   = debug.http_code || '—';
            var httpColor  = (httpCode >= 200 && httpCode < 300) ? '#2e7d32' : (httpCode >= 400 ? '#c62828' : '#555');
            var rawBody    = debug.raw_response ? self.escHtml(String(debug.raw_response).substring(0, 2000)) : '(no se pudo obtener respuesta directa)';
            var errHtml    = debug.error ? '<p style="color:#c62828;"><strong>Error:</strong> '+self.escHtml(debug.error)+'</p>' : '';

            var html =
                '<table class="oy-kw-debug-table">' +
                '<tr><th>location_id</th><td><code>'+self.escHtml(String(debug.location_id || '—'))+'</code></td></tr>' +
                '<tr><th>business_id</th><td><code>'+self.escHtml(String(debug.business_id || '—'))+'</code></td></tr>' +
                '<tr><th>Período</th><td><code>'+self.escHtml(String(debug.start_month||'—'))+' → '+self.escHtml(String(debug.end_month||'—'))+'</code></td></tr>' +
                '<tr><th>Modo</th><td><code>'+self.escHtml(String(debug.mode||'—'))+'</code></td></tr>' +
                '<tr><th>Force cache</th><td><code>'+(debug.force_cache ? 'sí' : 'no')+'</code></td></tr>' +
                '<tr><th>Items encontrados</th><td><code>'+self.escHtml(String(debug.items_found||0))+'</code></td></tr>' +
                '<tr><th>HTTP probe</th><td><code style="color:'+httpColor+';font-weight:700;">'+httpCode+'</code></td></tr>' +
                '<tr><th>Endpoint</th><td style="word-break:break-all;font-size:11px;"><code>'+self.escHtml(String(debug.api_endpoint||'—'))+'</code></td></tr>' +
                '</table>' +
                errHtml +
                '<p style="margin:8px 0 4px;font-weight:700;font-size:12px;">Respuesta raw de la API (probe directo):</p>' +
                '<pre class="oy-kw-debug-raw">'+rawBody+'</pre>' +
                '<p class="oy-kw-debug-hint">'+
                    '💡 <strong>Posibles causas de resultado vacío:</strong> (1) El negocio tiene bajo volumen de búsquedas orgánicas en Google. ' +
                    '(2) Google no muestra keywords con impresiones por debajo del umbral de privacidad (~15). ' +
                    '(3) El mes actual puede tardar hasta 7 días en aparecer. ' +
                    '(4) Prueba con un rango más amplio o mes anterior. ' +
                    '(5) Revisa el log de PHP en <code>wp-content/debug.log</code> para más detalles ([Lealez KW]).'+
                '</p>';

            $('#oy-kw-debug-body').html(html).show();
            $('#oy-kw-debug').show();
            // Auto-expand
            var $caret = $('#oy-kw-debug-toggle .oy-kw-debug-caret');
            $caret.text('▲');
        },

        // ----------------------------------------------------------------
        // CSV Export
        // ----------------------------------------------------------------

        exportCSV: function(data){
            var self       = this;
            var aggregated = data.aggregated || [];
            var isPerMonth = (data.mode === 'per_month');
            var monthlyRaw = data.monthly_raw || {};
            var months     = Object.keys(monthlyRaw).sort();

            if (!aggregated.length) { return; }

            // Calculate total for % share
            var totalKwImpressions = 0;
            $.each(aggregated, function(i, kw){ totalKwImpressions += (kw.total || 0); });

            var header = ['#', 'Frase Clave', 'Total Impresiones', '% Share'];
            if (isPerMonth) {
                $.each(months, function(i, mk){ header.push(mk); });
            }

            var rows = [header.join(',')];

            $.each(aggregated, function(i, kw){
                var sharePct = totalKwImpressions > 0
                    ? (Math.round((kw.total / totalKwImpressions) * 1000) / 10).toFixed(1)
                    : '0.0';
                var row = [(i+1), '"'+String(kw.keyword).replace(/"/g,'""')+'"', kw.total, sharePct+'%'];
                if (isPerMonth) {
                    var monthly = kw.monthly || {};
                    $.each(months, function(j, mk){
                        row.push(monthly[mk] !== undefined ? monthly[mk] : 0);
                    });
                }
                rows.push(row.join(','));
            });

            var blob = new Blob([rows.join('\n')], {type:'text/csv;charset=utf-8;'});
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href     = url;
            a.download = 'gmb-keywords-' + ($('#oy-kw-month-from').val() || 'export') + '_' + ($('#oy-kw-month-to').val() || '') + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },

        // ----------------------------------------------------------------
        // Status helpers
        // ----------------------------------------------------------------

        showStatus: function(type, msg){
            var icons = {loading:'⏳', error:'❌', info:'ℹ️', success:'✅'};
            $('#oy-kw-status')
                .removeClass('oy-kw-status--loading oy-kw-status--error oy-kw-status--info oy-kw-status--success')
                .addClass('oy-kw-status--'+type)
                .html((icons[type]||'')+' '+msg)
                .show();
        },
        hideStatus: function(){ $('#oy-kw-status').hide(); },

        showSaveStatus: function(type, msg){
            var icons = {loading:'⏳', error:'❌', info:'ℹ️', success:'✅'};
            $('#oy-kw-save-status')
                .removeClass('oy-kw-status--loading oy-kw-status--error oy-kw-status--info oy-kw-status--success')
                .addClass('oy-kw-status--'+type)
                .html((icons[type]||'')+' '+msg)
                .show();
        },

        hideResults: function(){
            $('#oy-kw-kpis, #oy-kw-context, #oy-kw-chart-wrap, #oy-kw-table-wrap, #oy-kw-footer, #oy-kw-month-filter-wrap').hide();
            $('#oy-kw-threshold-note').hide();
            $('#oy-kw-debug').hide();
        },

        formatNum: function(n){
            if (n === null || n === undefined) return '—';
            return Number(n).toLocaleString('es-CO');
        },

        escHtml: function(str){
            return String(str)
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;');
        }
    };

    $(document).ready(function(){
        OyKw.init();
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
/* ============================================================
   OY Location — GMB Keywords Metabox
   ============================================================ */
#oy-kw-dashboard { font-size:13px; color:#1e1e1e; }

/* Toolbar */
.oy-kw-toolbar {
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:8px;
    background:#f6f7f7; border:1px solid #ddd; border-radius:6px;
    padding:10px 14px; margin-bottom:14px;
}
.oy-kw-toolbar__left  { display:flex; align-items:center; flex-wrap:wrap; gap:12px; }
.oy-kw-toolbar__right { display:flex; gap:8px; }
.oy-kw-field-group { display:flex; align-items:center; gap:6px; }
.oy-kw-field-group label { font-weight:600; white-space:nowrap; cursor:pointer; }
.oy-kw-select { min-width:140px; }

/* Status */
.oy-kw-status {
    padding:10px 14px; border-radius:4px; margin-bottom:12px;
    border:1px solid transparent;
}
.oy-kw-status--loading { background:#e8f4fb; border-color:#bee5eb; color:#0c5460; }
.oy-kw-status--error   { background:#fff3cd; border-color:#ffc107; color:#856404; }
.oy-kw-status--info    { background:#d1ecf1; border-color:#bee5eb; color:#0c5460; }
.oy-kw-status--success { background:#d4edda; border-color:#c3e6cb; color:#155724; }

/* Notices */
.oy-kw-notice {
    padding:10px 14px; border-radius:4px; margin:8px 0;
    border:1px solid transparent;
}
.oy-kw-notice--warn { background:#fff3cd; border-color:#ffc107; color:#856404; }

/* KPI Cards */
.oy-kw-kpis {
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    gap:10px; margin-bottom:16px;
}
.oy-kw-kpi-card {
    background:#fff; border:1px solid #ddd; border-radius:6px;
    padding:12px; box-shadow:0 1px 3px rgba(0,0,0,.06); text-align:center;
}
.oy-kw-kpi-rank    { font-size:11px; color:#999; font-weight:700; margin-bottom:4px; }
.oy-kw-kpi-keyword {
    font-size:13px; font-weight:600; color:#333; margin-bottom:6px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.oy-kw-kpi-value { font-size:22px; font-weight:700; color:#1e1e1e; }
.oy-kw-kpi-value span { font-size:11px; color:#888; font-weight:400; }

/* Chart */
.oy-kw-chart-wrap {
    background:#fff; border:1px solid #ddd; border-radius:6px;
    padding:14px; margin-bottom:16px;
}
.oy-kw-section-header {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:10px;
}
.oy-kw-section-header h4 { margin:0; font-size:14px; font-weight:600; }
.oy-kw-badge {
    background:#e8f0fe; color:#1a73e8; padding:2px 10px;
    border-radius:20px; font-size:12px; font-weight:600;
}
.oy-kw-chart-container {
    position:relative;
    min-height:280px;
    transition: height 0.2s ease;
}
.oy-kw-chart-container canvas { width:100% !important; }

/* Table */
.oy-kw-table-wrap {
    background:#fff; border:1px solid #ddd; border-radius:6px;
    padding:14px; margin-bottom:16px;
}
.oy-kw-table-scroll { overflow-x:auto; max-height:500px; overflow-y:auto; }
.oy-kw-table th, .oy-kw-table td { padding:6px 10px; }
.oy-kw-table th { position:sticky; top:0; background:#f6f7f7; z-index:1; }

/* Threshold */
.oy-kw-threshold-note {
    font-size:11px; color:#666; margin-top:8px; padding:6px 10px;
    background:#fafafa; border:1px solid #e0e0e0; border-radius:4px;
}
.oy-kw-threshold-sup  { color:#e65100; font-weight:700; font-size:10px; }
.oy-kw-threshold-badge {
    display:inline-block; background:#fff3cd; color:#856404;
    font-size:10px; font-weight:700; padding:0 4px;
    border-radius:3px; margin-left:2px; vertical-align:middle;
}

/* Stored info */
.oy-kw-stored-wrap {
    background:#e8f5e9; border:1px solid #c8e6c9; border-radius:4px;
    padding:8px 12px; margin-bottom:12px;
}
.oy-kw-stored-title { margin:0; font-size:12px; color:#1b5e20; }

/* Footer */
.oy-kw-footer {
    font-size:11px; color:#888; padding:6px 0;
    border-top:1px solid #eee; margin-top:4px;
}

/* Debug Panel */
.oy-kw-debug-wrap {
    border:1px solid #f0c35a; border-radius:6px;
    margin-bottom:14px; background:#fffdf0; overflow:hidden;
}
.oy-kw-debug-header {
    padding:8px 14px; background:#fff8e1; cursor:pointer;
    display:flex; align-items:center; gap:4px;
    font-size:12px; color:#5d4037; user-select:none;
}
.oy-kw-debug-header:hover { background:#fff3cd; }
.oy-kw-debug-caret { margin-left:auto; font-size:10px; color:#888; }
.oy-kw-debug-body { padding:12px 14px; font-size:12px; }
.oy-kw-debug-table { width:100%; border-collapse:collapse; margin-bottom:10px; }
.oy-kw-debug-table th,
.oy-kw-debug-table td { padding:4px 8px; border:1px solid #e0e0e0; vertical-align:top; }
.oy-kw-debug-table th { background:#f5f5f5; width:140px; font-weight:600; color:#555; }
.oy-kw-debug-raw {
    background:#1e1e1e; color:#80cbc4; font-size:11px; line-height:1.5;
    padding:10px 12px; border-radius:4px; overflow-x:auto; white-space:pre-wrap;
    word-break:break-all; max-height:300px; overflow-y:auto; margin:0 0 10px 0;
}
.oy-kw-debug-hint {
    font-size:11px; color:#5d4037; background:#fff3e0;
    border:1px solid #ffe0b2; border-radius:4px;
    padding:8px 10px; margin:0; line-height:1.6;
}

/* KPI Share label */
.oy-kw-kpi-share {
    font-size:11px; color:#1a73e8; font-weight:600;
    margin-top:4px; letter-spacing:0.02em;
}

/* Context Panel */
.oy-kw-context-wrap {
    margin-bottom:16px;
}
.oy-kw-ctx-loading {
    padding:10px 14px; font-size:12px; color:#666;
    background:#f6f7f7; border:1px solid #ddd; border-radius:6px;
}
.oy-kw-ctx-panel {
    background:#fff; border:1px solid #c8d8f5; border-radius:6px;
    padding:14px 16px; box-shadow:0 1px 4px rgba(26,115,232,.08);
}
.oy-kw-ctx-header {
    display:flex; align-items:center; gap:4px;
    margin-bottom:12px; flex-wrap:wrap;
}
.oy-kw-ctx-header strong {
    font-size:13px; color:#1a1a1a; flex:1; min-width:200px;
}
.oy-kw-ctx-badge {
    background:#e8f0fe; color:#1a73e8; font-size:10px; font-weight:600;
    padding:2px 8px; border-radius:20px; white-space:nowrap;
}
.oy-kw-ctx-grid {
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    gap:10px; margin-bottom:12px;
}
.oy-kw-ctx-card {
    background:#f8f9ff; border:1px solid #dce8fc; border-radius:5px;
    padding:10px 12px;
}
.oy-kw-ctx-label {
    font-size:11px; color:#666; font-weight:600;
    text-transform:uppercase; letter-spacing:0.04em; margin-bottom:4px;
}
.oy-kw-ctx-value {
    font-size:22px; font-weight:700; color:#1a1a1a; line-height:1.2;
}
.oy-kw-ctx-sub {
    font-size:11px; color:#888; margin-top:4px; line-height:1.4;
}
.oy-kw-ctx-device {
    font-size:12px; color:#333; margin-bottom:3px;
    display:flex; align-items:center; gap:4px;
}
.oy-kw-ctx-device .dashicons {
    font-size:16px; width:16px; height:16px; margin-right:2px;
}
.oy-kw-ctx-device em {
    color:#888; font-style:normal; font-size:11px;
}
.oy-kw-ctx-note {
    font-size:11px; color:#5d4037; background:#fff8e1;
    border:1px solid #ffe082; border-radius:4px;
    padding:7px 10px; margin:0; line-height:1.6;
}
@media (max-width: 782px) {
    .oy-kw-ctx-grid { grid-template-columns:1fr; }
}
';
    }
}
