<?php
/**
 * OY Location GMB Integration Metabox
 *
 * Metabox de "Integración Google My Business — Control de Sincronización" para oy_location.
 * Gestiona:
 *  - Sincronización secuencial automática de todos los metaboxes GMB
 *  - Límites de sincronización manual (máx por día, intervalo mínimo)
 *  - Programación de sincronización automática vía WP-Cron
 *  - Log persistente de sincronizaciones (manual y automática)
 *
 * Archivo: includes/cpts/metaboxes/class-oy-location-gmb-integration-metabox.php
 *
 * AJAX actions expuestas:
 *  - wp_ajax_oy_gmb_full_sync        → Valida límites de tasa antes de iniciar sync
 *  - wp_ajax_oy_gmb_sync_complete    → Registra resultado, actualiza contadores
 *  - wp_ajax_oy_gmb_sync_log_get     → Devuelve tabla HTML del log
 *  - wp_ajax_oy_gmb_sync_log_clear   → Limpia el log
 *
 * @package Lealez
 * @subpackage CPTs/Metaboxes
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class OY_Location_GMB_Integration_Metabox
 */
class OY_Location_GMB_Integration_Metabox {

    /**
     * Nonce para guardar la configuración del metabox
     */
    const NONCE_ACTION = 'oy_gmb_integration_save';
    const NONCE_NAME   = '_oy_gmb_integration_nonce';

    /**
     * Nonce AJAX — comparte el del CPT principal para compatibilidad con oy_get_gmb_location_details
     */
    const AJAX_NONCE_ACTION = 'oy_location_gmb_ajax';

    /**
     * Máximo de entradas en el log
     */
    const MAX_LOG_ENTRIES = 50;

    /**
     * Hook de WP-Cron para auto-sync
     */
    const CRON_HOOK = 'oy_gmb_auto_sync_location';

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'add_meta_boxes',        array( $this, 'register_metabox' ) );
        add_action( 'save_post_oy_location', array( $this, 'save_metabox' ), 25, 2 );

        // AJAX handlers
        add_action( 'wp_ajax_oy_gmb_full_sync',      array( $this, 'ajax_full_sync_init' ) );
        add_action( 'wp_ajax_oy_gmb_sync_complete',  array( $this, 'ajax_sync_complete' ) );
        add_action( 'wp_ajax_oy_gmb_sync_log_get',   array( $this, 'ajax_log_get' ) );
        add_action( 'wp_ajax_oy_gmb_sync_log_clear', array( $this, 'ajax_log_clear' ) );

        // Cron handler y schedules personalizados
        add_action( self::CRON_HOOK,   array( $this, 'cron_auto_sync' ) );
        add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );
    }

    // =========================================================================
    // REGISTRO Y RENDER DEL METABOX
    // =========================================================================

    /**
     * Registrar el metabox en el sidebar de oy_location
     */
    public function register_metabox() {
        add_meta_box(
            'oy_gmb_integration_control',
            __( '⚙️ Integración GMB — Control de Sincronización', 'lealez' ),
            array( $this, 'render_metabox' ),
            'oy_location',
            'side',
            'high'
        );
    }

    /**
     * Renderizar el metabox completo
     *
     * @param WP_Post $post
     */
    public function render_metabox( $post ) {
        $post_id = $post->ID;

        // ── Cargar configuración guardada ──────────────────────────────────
        $max_per_day        = (int) get_post_meta( $post_id, 'gmb_sync_max_per_day', true );
        $max_per_day        = $max_per_day > 0 ? $max_per_day : 3;
        $bypass_limit       = (bool) get_post_meta( $post_id, 'gmb_sync_bypass_limit', true );
        $min_interval_hours = (int) get_post_meta( $post_id, 'gmb_sync_min_interval_hours', true );
        $min_interval_hours = $min_interval_hours >= 24 ? $min_interval_hours : 24;
        $schedule_enabled   = (bool) get_post_meta( $post_id, 'gmb_sync_schedule_enabled', true );
        $last_manual        = (int) get_post_meta( $post_id, 'gmb_sync_last_manual', true );

        // ── Contador diario ──────────────────────────────────────────────────
        $count_date  = get_post_meta( $post_id, 'gmb_sync_manual_count_date', true );
        $count_today = (int) get_post_meta( $post_id, 'gmb_sync_manual_count_today', true );
        if ( $count_date !== current_time( 'Y-m-d' ) ) {
            $count_today = 0;
        }

        // ── Estado del límite ────────────────────────────────────────────────
        $is_superadmin    = is_super_admin();
        $effective_bypass = ( $is_superadmin && $bypass_limit );
        $limit_reached    = ( ! $effective_bypass ) && ( $max_per_day > 0 ) && ( $count_today >= $max_per_day );

        // ── Intervalo mínimo ─────────────────────────────────────────────────
        $next_allowed  = $last_manual ? ( $last_manual + $min_interval_hours * HOUR_IN_SECONDS ) : 0;
        $interval_ok   = ( $next_allowed <= time() );
        $wait_minutes  = $interval_ok ? 0 : ceil( ( $next_allowed - time() ) / 60 );

        // ── Próxima ejecución del cron ────────────────────────────────────────
        $next_cron = wp_next_scheduled( self::CRON_HOOK, array( $post_id ) );

        // ── Datos de la ubicación para los sub-handlers ──────────────────────
        $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        $account_name  = $business_id ? (string) get_post_meta( $business_id, 'gmb_account_name', true ) : '';

        // ── Nonces para todos los sub-handlers ──────────────────────────────
        // Cada metabox verifica su propio nonce — generamos todos aquí para el JS
        $nonces = array(
            'main'        => wp_create_nonce( self::AJAX_NONCE_ACTION ),
            'integration' => wp_create_nonce( self::AJAX_NONCE_ACTION ),
            'hours'       => wp_create_nonce( 'oy_hours_gmb_sync' ),
            'more'        => wp_create_nonce( 'oy_gmb_more_ajax' ),
            'performance' => wp_create_nonce( 'oy_gmb_performance_nonce' ),
            'keywords'    => wp_create_nonce( 'oy_gmb_keywords_nonce' ),
            'reviews'     => wp_create_nonce( 'oy_gmb_reviews_nonce' ),
            'posts'       => wp_create_nonce( 'oy_gmb_posts_nonce' ),
            'busyhours'   => wp_create_nonce( 'oy_gmb_busyhours_nonce' ),
        );

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
        ?>
        <style>
        #oy_gmb_integration_control .oy-int-section {
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e4e7;
        }
        #oy_gmb_integration_control .oy-int-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        #oy_gmb_integration_control .oy-int-section-title {
            font-size: 11px;
            text-transform: uppercase;
            color: #777;
            letter-spacing: .5px;
            font-weight: 600;
            margin: 0 0 8px;
        }
        #oy_gmb_integration_control .oy-sync-steps {
            list-style: none;
            margin: 10px 0 0;
            padding: 0;
            font-size: 12px;
        }
        #oy_gmb_integration_control .oy-sync-steps li {
            padding: 3px 0;
            display: flex;
            align-items: flex-start;
            gap: 5px;
            line-height: 1.4;
        }
        #oy_gmb_integration_control .oy-step-icon { width: 16px; flex-shrink: 0; text-align: center; font-style: normal; }
        #oy_gmb_integration_control .oy-step-label { flex: 1; color: #444; }
        #oy_gmb_integration_control .oy-step-msg   { font-size: 10px; color: #999; margin-top: 1px; display: block; }
        #oy_gmb_integration_control .oy-step-ok   .oy-step-msg { color: #46b450; }
        #oy_gmb_integration_control .oy-step-error .oy-step-msg { color: #dc3232; }
        #oy_gmb_integration_control .oy-step-running .oy-step-icon { display: inline-block; animation: oy-int-spin .8s linear infinite; }
        @keyframes oy-int-spin { to { transform: rotate(360deg); } }
        #oy_gmb_integration_control .oy-progress-wrap {
            background: #e2e4e7;
            border-radius: 3px;
            height: 5px;
            margin: 6px 0 4px;
            overflow: hidden;
        }
        #oy_gmb_integration_control .oy-progress-fill {
            background: linear-gradient(90deg, #4e9af1, #2c7be5);
            height: 100%;
            width: 0;
            transition: width .35s ease;
            border-radius: 3px;
        }
        #oy_gmb_integration_control .oy-counter-badge {
            display: inline-block;
            background: #e2e4e7;
            color: #555;
            border-radius: 10px;
            font-size: 11px;
            padding: 1px 8px;
            font-weight: 600;
        }
        #oy_gmb_integration_control .oy-counter-badge.limit-reached {
            background: #fde8e8;
            color: #b32d2e;
        }
        #oy_gmb_integration_control .oy-sync-log-wrap {
            max-height: 200px;
            overflow-y: auto;
            font-size: 11px;
            border: 1px solid #e2e4e7;
            border-radius: 3px;
        }
        #oy_gmb_integration_control .oy-log-table {
            width: 100%;
            border-collapse: collapse;
        }
        #oy_gmb_integration_control .oy-log-table th,
        #oy_gmb_integration_control .oy-log-table td {
            padding: 4px 6px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        #oy_gmb_integration_control .oy-log-table th {
            background: #f6f7f7;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        #oy_gmb_integration_control .log-ok      { color: #46b450; font-weight: 600; }
        #oy_gmb_integration_control .log-partial  { color: #e67e22; font-weight: 600; }
        #oy_gmb_integration_control .log-error    { color: #dc3232; font-weight: 600; }
        #oy_gmb_integration_control .log-empty-msg { padding: 8px; color: #888; font-style: italic; }
        </style>

        <?php if ( ! $business_id || ! $location_name ) : ?>
        <p class="description" style="color:#b32d2e; font-size:12px;">
            <strong><?php _e( '⚠️ Esta ubicación no está conectada a GMB. Configura el negocio padre y selecciona una ubicación GMB primero.', 'lealez' ); ?></strong>
        </p>
        <?php
        return; // Nada más que mostrar sin conexión GMB
        endif;

        // ── El siguiente bloque solo se muestra si la ubicación está conectada ──
        $can_sync     = ( ! $limit_reached ) && ( $interval_ok || $effective_bypass );
        $btn_disabled = $can_sync ? '' : 'disabled';
        ?>

        <!-- ===== SECCIÓN 1: Sincronización manual ===== -->
        <div class="oy-int-section">
            <p class="oy-int-section-title"><?php _e( '🔄 Sincronización Manual', 'lealez' ); ?></p>

            <button type="button"
                    id="oy-full-sync-btn"
                    class="button button-primary"
                    style="width:100%; margin-bottom:6px;"
                    <?php echo esc_attr( $btn_disabled ); ?>>
                <?php _e( '🔄 Sincronizar Todo Ahora', 'lealez' ); ?>
            </button>

            <?php if ( $limit_reached && ! $effective_bypass ) : ?>
            <p class="description" style="font-size:11px; color:#b32d2e; margin-bottom:4px;">
                <?php printf(
                    /* translators: 1: count used, 2: max per day */
                    esc_html__( '⛔ Límite diario alcanzado (%1$d/%2$d). Disponible mañana.', 'lealez' ),
                    $count_today,
                    $max_per_day
                ); ?>
            </p>
            <?php elseif ( ! $interval_ok && ! $effective_bypass ) : ?>
            <p class="description" style="font-size:11px; color:#e67e22; margin-bottom:4px;">
                <?php printf(
                    /* translators: %d: minutes to wait */
                    esc_html__( '⏳ Próxima sync disponible en %d min.', 'lealez' ),
                    $wait_minutes
                ); ?>
            </p>
            <?php endif; ?>

            <div style="display:flex; align-items:center; justify-content:space-between; margin-top:4px;">
                <span class="description" style="font-size:11px;"><?php _e( 'Uso hoy:', 'lealez' ); ?></span>
                <span id="oy-sync-counter-badge"
                      class="oy-counter-badge <?php echo ( $limit_reached && ! $effective_bypass ) ? 'limit-reached' : ''; ?>">
                    <?php echo esc_html( $count_today ); ?> /
                    <?php echo $effective_bypass ? '∞' : esc_html( $max_per_day ); ?>
                </span>
            </div>

            <?php if ( $last_manual ) : ?>
            <p class="description" style="font-size:11px; margin-top:4px;">
                <?php printf(
                    /* translators: %s: formatted date */
                    esc_html__( 'Última: %s', 'lealez' ),
                    esc_html( date_i18n( 'd/m/Y H:i', $last_manual ) )
                ); ?>
            </p>
            <?php endif; ?>

            <!-- Área de progreso (oculta hasta que inicia sync) -->
            <div id="oy-full-sync-progress" style="display:none; margin-top:12px;">
                <div class="oy-progress-wrap">
                    <div class="oy-progress-fill" id="oy-progress-fill"></div>
                </div>
                <ul class="oy-sync-steps" id="oy-sync-steps">
                    <li id="oy-step-import">
                        <em class="oy-step-icon">⏳</em>
                        <span class="oy-step-label"><?php _e( 'Importar datos base', 'lealez' ); ?><br><small class="oy-step-msg"></small></span>
                    </li>
                    <li id="oy-step-hours">
                        <em class="oy-step-icon">⏳</em>
                        <span class="oy-step-label"><?php _e( 'Horarios de atención', 'lealez' ); ?><br><small class="oy-step-msg"></small></span>
                    </li>
                    <li id="oy-step-more">
                        <em class="oy-step-icon">⏳</em>
                        <span class="oy-step-label"><?php _e( 'Atributos (sección Más)', 'lealez' ); ?><br><small class="oy-step-msg"></small></span>
                    </li>
                    <li id="oy-step-perf">
                        <em class="oy-step-icon">⏳</em>
                        <span class="oy-step-label"><?php _e( 'Métricas de rendimiento', 'lealez' ); ?><br><small class="oy-step-msg"></small></span>
                    </li>
                    <li id="oy-step-keywords">
                        <em class="oy-step-icon">⏳</em>
                        <span class="oy-step-label"><?php _e( 'Frases clave de búsqueda', 'lealez' ); ?><br><small class="oy-step-msg"></small></span>
                    </li>
                    <li id="oy-step-reviews">
                        <em class="oy-step-icon">⏳</em>
                        <span class="oy-step-label"><?php _e( 'Reseñas de Google', 'lealez' ); ?><br><small class="oy-step-msg"></small></span>
                    </li>
                    <li id="oy-step-posts">
                        <em class="oy-step-icon">⏳</em>
                        <span class="oy-step-label"><?php _e( 'Publicaciones GMB', 'lealez' ); ?><br><small class="oy-step-msg"></small></span>
                    </li>
                    <li id="oy-step-menus">
                        <em class="oy-step-icon">⏳</em>
                        <span class="oy-step-label"><?php _e( 'Menú de comida', 'lealez' ); ?><br><small class="oy-step-msg"></small></span>
                    </li>
                    <li id="oy-step-busyhours">
                        <em class="oy-step-icon">⏳</em>
                        <span class="oy-step-label"><?php _e( 'Horario de mayor interés', 'lealez' ); ?><br><small class="oy-step-msg"></small></span>
                    </li>
                </ul>
                <p id="oy-sync-global-msg" style="font-size:11px; margin:8px 0 0; font-weight:600;"></p>
            </div>
        </div>

        <!-- ===== SECCIÓN 2: Límites de sincronización ===== -->
        <div class="oy-int-section">
            <p class="oy-int-section-title"><?php _e( '🛡️ Límites de Sincronización', 'lealez' ); ?></p>
            <table style="width:100%; font-size:12px; border-spacing:0;">
                <tr>
                    <td style="padding:3px 0; padding-right:6px;">
                        <label for="gmb_sync_max_per_day"><?php _e( 'Máx. sync/día:', 'lealez' ); ?></label>
                    </td>
                    <td>
                        <input type="number"
                               id="gmb_sync_max_per_day"
                               name="gmb_sync_max_per_day"
                               value="<?php echo esc_attr( $max_per_day ); ?>"
                               min="1" max="50" step="1"
                               style="width:55px;">
                    </td>
                </tr>
                <?php if ( $is_superadmin ) : ?>
                <tr>
                    <td colspan="2" style="padding-top:6px;">
                        <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                            <input type="checkbox"
                                   name="gmb_sync_bypass_limit"
                                   id="gmb_sync_bypass_limit"
                                   value="1"
                                   <?php checked( $bypass_limit ); ?>>
                            <span style="color:#c0392b; font-weight:600;">
                                <?php _e( '👑 Bypass límites (Superadmin)', 'lealez' ); ?>
                            </span>
                        </label>
                        <p class="description" style="font-size:10px; margin-left:20px;">
                            <?php _e( 'Permite sincronizar sin restricción de cantidad ni intervalo.', 'lealez' ); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- ===== SECCIÓN 3: Sincronización automática ===== -->
        <div class="oy-int-section">
            <p class="oy-int-section-title"><?php _e( '🕐 Sincronización Automática', 'lealez' ); ?></p>

            <label style="display:flex; align-items:center; gap:5px; cursor:pointer; margin-bottom:8px; font-size:12px;">
                <input type="checkbox"
                       name="gmb_sync_schedule_enabled"
                       id="gmb_sync_schedule_enabled"
                       value="1"
                       <?php checked( $schedule_enabled ); ?>>
                <?php _e( 'Activar sincronización automática', 'lealez' ); ?>
            </label>

            <label style="font-size:12px; display:block;">
                <?php _e( 'Ejecutar cada:', 'lealez' ); ?>
                <select name="gmb_sync_min_interval_hours"
                        id="gmb_sync_min_interval_hours"
                        style="margin-left:4px; font-size:12px;"
                        <?php echo $schedule_enabled ? '' : 'disabled'; ?>>
                    <option value="24"  <?php selected( $min_interval_hours, 24  ); ?>><?php _e( '24 horas',         'lealez' ); ?></option>
                    <option value="48"  <?php selected( $min_interval_hours, 48  ); ?>><?php _e( '48 horas',         'lealez' ); ?></option>
                    <option value="72"  <?php selected( $min_interval_hours, 72  ); ?>><?php _e( '72 horas',         'lealez' ); ?></option>
                    <option value="168" <?php selected( $min_interval_hours, 168 ); ?>><?php _e( 'Semanal (168 h)',  'lealez' ); ?></option>
                </select>
            </label>

            <?php if ( $next_cron && $schedule_enabled ) : ?>
            <p class="description" style="margin-top:6px; font-size:11px;">
                <?php printf(
                    /* translators: %s: formatted date */
                    esc_html__( '📅 Próxima auto-sync: %s', 'lealez' ),
                    esc_html( date_i18n( 'd/m/Y H:i', $next_cron ) )
                ); ?>
            </p>
            <?php elseif ( $schedule_enabled ) : ?>
            <p class="description" style="margin-top:6px; font-size:11px; color:#e67e22;">
                <?php _e( 'ℹ️ Guarda el post para activar el cron.', 'lealez' ); ?>
            </p>
            <?php endif; ?>
        </div>

        <!-- ===== SECCIÓN 4: Log de sincronizaciones ===== -->
        <div class="oy-int-section">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <p class="oy-int-section-title" style="margin:0;"><?php _e( '📋 Log de Sincronizaciones', 'lealez' ); ?></p>
                <button type="button"
                        id="oy-sync-log-clear-btn"
                        class="button button-small"
                        style="font-size:10px; padding:0 7px; min-height:22px; line-height:22px;">
                    <?php _e( 'Limpiar log', 'lealez' ); ?>
                </button>
            </div>
            <div class="oy-sync-log-wrap" id="oy-sync-log-container">
                <?php echo $this->render_log_table( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){

            var ajaxUrl      = (window.ajaxurl) ? window.ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var postId       = <?php echo (int) $post_id; ?>;
            var businessId   = <?php echo (int) $business_id; ?>;
            var locationName = <?php echo wp_json_encode( $location_name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
            var accountName  = <?php echo wp_json_encode( $account_name,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
            var nonces       = <?php echo wp_json_encode( $nonces, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
            var syncRunning  = false;
            var stepResults  = {};

            // ── Definición de los 8 pasos ──────────────────────────────────────
            // Cada paso puede ser 'single' (una llamada) o 'multi' (lógica propia)
            var TOTAL_STEPS = 9;

            // ── Helper: actualizar estado visual de un paso ───────────────────
            function setStep(id, state, msg) {
                var $li = $('#oy-step-' + id);
                $li.removeClass('oy-step-running oy-step-ok oy-step-error oy-step-skip');
                var icon = '⏳';
                switch (state) {
                    case 'running': icon = '🔄'; $li.addClass('oy-step-running'); break;
                    case 'ok':      icon = '✅'; $li.addClass('oy-step-ok');      break;
                    case 'error':   icon = '❌'; $li.addClass('oy-step-error');   break;
                    case 'skip':    icon = '⏭️'; $li.addClass('oy-step-skip');    break;
                }
                $li.find('.oy-step-icon').text(icon);
                if (msg !== undefined) {
                    $li.find('.oy-step-msg').text(msg);
                }
            }

            // ── Helper: actualizar barra de progreso ──────────────────────────
            function setProgress(done) {
                var pct = Math.round((done / TOTAL_STEPS) * 100);
                $('#oy-progress-fill').css('width', pct + '%');
            }

            // ── Helper: extraer mensaje de error de respuesta AJAX ─────────────
            function errMsg(r) {
                if (r && r.responseJSON && r.responseJSON.data && r.responseJSON.data.message) {
                    return r.responseJSON.data.message;
                }
                if (r && r.data && r.data.message) {
                    return r.data.message;
                }
                return '<?php echo esc_js( __( 'Error desconocido', 'lealez' ) ); ?>';
            }

            // ── Helper: ejecutar una llamada AJAX genérica ────────────────────
            function doAjax(action, data, nonceField, nonce) {
                var params = $.extend({}, data, { action: action });
                params[nonceField] = nonce;
                return $.ajax({ url: ajaxUrl, type: 'POST', data: params, timeout: 60000 });
            }

            // ── Base de datos comunes para todos los sub-handlers ─────────────
            var baseData = {
                post_id:       postId,
                business_id:   businessId,
                location_name: locationName,
                location:      locationName,   // reviews usa 'location'
                account_name:  accountName,
            };

            // ── Función principal de sync secuencial ─────────────────────────
            function runFullSync() {
                if (syncRunning) return;
                syncRunning  = true;
                stepResults  = {};
                var doneCount = 0;

                // Reset UI
                $('#oy-full-sync-btn').prop('disabled', true)
                    .text('<?php echo esc_js( __( 'Sincronizando...', 'lealez' ) ); ?>');
                $('#sync-gmb-data').prop('disabled', true);
                $('#oy-full-sync-progress').slideDown(150);
                $('#oy-sync-global-msg').text('<?php echo esc_js( __( 'Iniciando sincronización...', 'lealez' ) ); ?>').css('color','#555');
                setProgress(0);

                // Resetear todos los pasos
                ['import','hours','more','perf','keywords','reviews','posts','menus','busyhours'].forEach(function(id){
                    setStep(id, 'pending', '');
                });

                // ── Cadena secuencial de promesas ──────────────────────────────
                var chain = $.Deferred().resolve();

                // ── PASO 1: Importar datos base ────────────────────────────────
                chain = chain.then(function(){
                    setStep('import', 'running', '<?php echo esc_js( __( 'consultando API...', 'lealez' ) ); ?>');
                    var def = $.Deferred();
                    doAjax('oy_get_gmb_location_details', baseData, 'nonce', nonces.main)
                    .done(function(r){
                        var ok = r && r.success;
                        stepResults.import = ok ? 'ok' : 'error';
                        setStep('import', ok ? 'ok' : 'error',
                            ok ? '<?php echo esc_js( __( 'importado', 'lealez' ) ); ?>'
                               : errMsg(r));
                        doneCount++; setProgress(doneCount);
                        def.resolve();
                    })
                    .fail(function(){ stepResults.import='error'; setStep('import','error','<?php echo esc_js( __( 'error de red', 'lealez' ) ); ?>'); doneCount++; setProgress(doneCount); def.resolve(); });
                    return def.promise();
                });

                // ── PASO 2: Horarios ───────────────────────────────────────────
                chain = chain.then(function(){
                    setStep('hours', 'running', '<?php echo esc_js( __( 'sincronizando...', 'lealez' ) ); ?>');
                    var def = $.Deferred();
                    doAjax('oy_sync_location_hours_from_gmb', baseData, 'nonce', nonces.hours)
                    .done(function(r){
                        var ok = r && r.success;
                        stepResults.hours = ok ? 'ok' : 'error';
                        setStep('hours', ok ? 'ok' : 'error', ok ? '<?php echo esc_js( __( 'actualizados', 'lealez' ) ); ?>' : errMsg(r));
                        doneCount++; setProgress(doneCount); def.resolve();
                    })
                    .fail(function(){ stepResults.hours='error'; setStep('hours','error','<?php echo esc_js( __( 'error de red', 'lealez' ) ); ?>'); doneCount++; setProgress(doneCount); def.resolve(); });
                    return def.promise();
                });

                // ── PASO 3: Atributos "Más" ────────────────────────────────────
                chain = chain.then(function(){
                    setStep('more', 'running', '<?php echo esc_js( __( 'actualizando...', 'lealez' ) ); ?>');
                    var def = $.Deferred();
                    doAjax('oy_gmb_more_refresh_metadata', baseData, 'nonce', nonces.more)
                    .done(function(r){
                        var ok = r && r.success;
                        stepResults.more = ok ? 'ok' : 'error';
                        var msg = ok ? (r.data && r.data.message ? r.data.message : '<?php echo esc_js( __( 'actualizados', 'lealez' ) ); ?>') : errMsg(r);
                        setStep('more', ok ? 'ok' : 'error', msg);
                        doneCount++; setProgress(doneCount); def.resolve();
                    })
                    .fail(function(){ stepResults.more='error'; setStep('more','error','<?php echo esc_js( __( 'error de red', 'lealez' ) ); ?>'); doneCount++; setProgress(doneCount); def.resolve(); });
                    return def.promise();
                });

                // ── PASO 4: Métricas de rendimiento (fetch → sync) ─────────────
                chain = chain.then(function(){
                    setStep('perf', 'running', '<?php echo esc_js( __( 'obteniendo métricas...', 'lealez' ) ); ?>');
                    var def = $.Deferred();
                    var perfData = $.extend({}, baseData, { period: '30', force_refresh: '1' });
                    doAjax('oy_gmb_perf_fetch', perfData, 'nonce', nonces.performance)
                    .then(function(){
                        // Si el fetch fue bien, guardar en post meta
                        setStep('perf', 'running', '<?php echo esc_js( __( 'guardando métricas...', 'lealez' ) ); ?>');
                        return doAjax('oy_gmb_perf_sync', baseData, 'nonce', nonces.performance);
                    })
                    .done(function(r){
                        var ok = r && r.success;
                        stepResults.perf = ok ? 'ok' : 'error';
                        setStep('perf', ok ? 'ok' : 'error', ok ? '<?php echo esc_js( __( 'guardadas', 'lealez' ) ); ?>' : errMsg(r));
                        doneCount++; setProgress(doneCount); def.resolve();
                    })
                    .fail(function(){ stepResults.perf='error'; setStep('perf','error','<?php echo esc_js( __( 'error de red', 'lealez' ) ); ?>'); doneCount++; setProgress(doneCount); def.resolve(); });
                    return def.promise();
                });

                // ── PASO 5: Frases clave (fetch → save) ───────────────────────
                chain = chain.then(function(){
                    setStep('keywords', 'running', '<?php echo esc_js( __( 'obteniendo keywords...', 'lealez' ) ); ?>');
                    var def = $.Deferred();
                    var kwFetchData = $.extend({}, baseData, { per_month: '0', force_refresh: '1' });
                    doAjax('oy_gmb_kw_fetch', kwFetchData, 'nonce', nonces.keywords)
                    .then(function(r1){
                        if (r1 && r1.success && r1.data && r1.data.aggregated && r1.data.aggregated.length) {
                            setStep('keywords', 'running', '<?php echo esc_js( __( 'guardando keywords...', 'lealez' ) ); ?>');
                            var kwJson = JSON.stringify(
                                r1.data.aggregated.map(function(kw){
                                    return { keyword: kw.keyword || '', total: kw.total || 0 };
                                })
                            );
                            var kwSaveData = $.extend({}, baseData, { keywords: kwJson });
                            return doAjax('oy_gmb_kw_save', kwSaveData, 'nonce', nonces.keywords);
                        }
                        // Sin keywords en el rango — registrar como ok (vacío)
                        return $.Deferred().resolve(r1).promise();
                    })
                    .done(function(r){
                        var ok = r && r.success;
                        stepResults.keywords = ok ? 'ok' : 'error';
                        var msg = ok
                            ? (r.data && r.data.message ? r.data.message : '<?php echo esc_js( __( 'guardadas', 'lealez' ) ); ?>')
                            : errMsg(r);
                        setStep('keywords', ok ? 'ok' : 'error', msg);
                        doneCount++; setProgress(doneCount); def.resolve();
                    })
                    .fail(function(){ stepResults.keywords='error'; setStep('keywords','error','<?php echo esc_js( __( 'error de red', 'lealez' ) ); ?>'); doneCount++; setProgress(doneCount); def.resolve(); });
                    return def.promise();
                });

                // ── PASO 6: Reseñas ────────────────────────────────────────────
                // Reviews solo hace fetch (no guarda en meta, muestra en UI)
                chain = chain.then(function(){
                    setStep('reviews', 'running', '<?php echo esc_js( __( 'cargando reseñas...', 'lealez' ) ); ?>');
                    var def = $.Deferred();
                    var revData = $.extend({}, baseData, {
                        page_token:    '',
                        order_by:      'updateTime desc',
                        force_refresh: '1',
                    });
                    // Reviews usa 'security' como campo de nonce
                    doAjax('oy_gmb_reviews_fetch', revData, 'security', nonces.reviews)
                    .done(function(r){
                        var ok = r && r.success;
                        stepResults.reviews = ok ? 'ok' : 'error';
                        var msg = ok ? '<?php echo esc_js( __( 'cargadas', 'lealez' ) ); ?>' : errMsg(r);
                        setStep('reviews', ok ? 'ok' : 'error', msg);
                        // Notificar al metabox de reseñas para que re-renderice
                        if ( ok ) { $(document).trigger('oy:gmb:reviews:refreshed'); }
                        doneCount++; setProgress(doneCount); def.resolve();
                    })
                    .fail(function(){ stepResults.reviews='error'; setStep('reviews','error','<?php echo esc_js( __( 'error de red', 'lealez' ) ); ?>'); doneCount++; setProgress(doneCount); def.resolve(); });
                    return def.promise();
                });

                // ── PASO 7: Publicaciones GMB ──────────────────────────────────
                chain = chain.then(function(){
                    setStep('posts', 'running', '<?php echo esc_js( __( 'sincronizando publicaciones...', 'lealez' ) ); ?>');
                    var def = $.Deferred();
                    var postsData = $.extend({}, baseData, {
                        location_id   : postId,
                        gmb_loc_name  : locationName,
                        force_refresh : 1,
                    });
                    doAjax('oy_gmb_posts_fetch', postsData, 'nonce', nonces.posts)
                    .done(function(r){
                        var ok = r && r.success;
                        stepResults.posts = ok ? 'ok' : 'error';
                        var msg = ok
                            ? (r.data && r.data.total !== undefined
                                ? r.data.total + ' <?php echo esc_js( __( 'publicaciones', 'lealez' ) ); ?>'
                                : '<?php echo esc_js( __( 'sincronizadas', 'lealez' ) ); ?>')
                            : errMsg(r);
                        setStep('posts', ok ? 'ok' : 'error', msg);
                        // Notificar al metabox de publicaciones para que re-renderice
                        if ( ok ) { $(document).trigger('oy:gmb:posts:refreshed'); }
                        doneCount++; setProgress(doneCount); def.resolve();
                    })
                    .fail(function(){ stepResults.posts='error'; setStep('posts','error','<?php echo esc_js( __( 'error de red', 'lealez' ) ); ?>'); doneCount++; setProgress(doneCount); def.resolve(); });
                    return def.promise();
                });

                // ── PASO 8: Menú de comida ─────────────────────────────────────
                chain = chain.then(function(){
                    setStep('menus', 'running', '<?php echo esc_js( __( 'sincronizando...', 'lealez' ) ); ?>');
                    var def = $.Deferred();
                    doAjax('oy_sync_location_food_menus', baseData, 'nonce', nonces.main)
                    .done(function(r){
                        var ok = r && r.success;
                        stepResults.menus = ok ? 'ok' : 'error';
                        var msg = ok
                            ? (r.data && r.data.message ? r.data.message : '<?php echo esc_js( __( 'sincronizado', 'lealez' ) ); ?>')
                            : errMsg(r);
                        setStep('menus', ok ? 'ok' : 'error', msg);
                        doneCount++; setProgress(doneCount); def.resolve();
                    })
                    .fail(function(){ stepResults.menus='error'; setStep('menus','error','<?php echo esc_js( __( 'error de red', 'lealez' ) ); ?>'); doneCount++; setProgress(doneCount); def.resolve(); });
                    return def.promise();
                });

// ── PASO 9: Horario de mayor interés (compute → save) ──────────
                chain = chain.then(function(){
                    setStep('busyhours', 'running', '<?php echo esc_js( __( 'calculando...', 'lealez' ) ); ?>');
                    var def = $.Deferred();
                    doAjax('oy_gmb_busy_compute', baseData, 'nonce', nonces.busyhours)
                    .then(function(r1){
                        if (r1 && r1.success && r1.data) {
                            setStep('busyhours', 'running', '<?php echo esc_js( __( 'guardando...', 'lealez' ) ); ?>');
                            // Construir el payload exacto que espera ajax_save_peak_hours:
                            // - hours           ← r1.data.hours
                            // - day_weights     ← r1.data.day_weights
                            // - metric_breakdown ← r1.data.group_breakdown  (distinto nombre)
                            // - auto_template   ← r1.data.auto_template
                            // - period          ← r1.data.period
                            // - metrics_used    ← r1.data.metrics_used
                            // - last_computed   ← r1.data.computed_at       (distinto nombre)
                            var peakPayload = {
                                hours:             r1.data.hours             || {},
                                day_weights:       r1.data.day_weights       || {},
                                metric_breakdown:  r1.data.group_breakdown   || {},
                                auto_template:     r1.data.auto_template     || '',
                                period:            r1.data.period            || {},
                                metrics_used:      r1.data.metrics_used      || 0,
                                last_computed:     r1.data.computed_at       || '',
                                avg_stay_min:      45,
                                avg_stay_max:      90,
                            };
                            // El campo POST debe llamarse 'peak_data' (no 'peak_hours')
                            var saveData = $.extend({}, baseData, { peak_data: JSON.stringify(peakPayload) });
                            return doAjax('oy_gmb_busy_save', saveData, 'nonce', nonces.busyhours);
                        }
                        // Si compute falló, propagar el error
                        return $.Deferred().resolve(r1).promise();
                    })
                    .done(function(r){
                        var ok = r && r.success;
                        stepResults.busyhours = ok ? 'ok' : 'error';
                        var msg = ok
                            ? (r.data && r.data.message ? r.data.message : '<?php echo esc_js( __( 'calculado y guardado', 'lealez' ) ); ?>')
                            : errMsg(r);
                        setStep('busyhours', ok ? 'ok' : 'error', msg);
                        doneCount++; setProgress(doneCount); def.resolve();
                    })
                    .fail(function(){ stepResults.busyhours='error'; setStep('busyhours','error','<?php echo esc_js( __( 'error de red', 'lealez' ) ); ?>'); doneCount++; setProgress(doneCount); def.resolve(); });
                    return def.promise();
                });

                // ── Finalización ───────────────────────────────────────────────
                chain.done(function(){
                    var errCount = 0;
                    $.each(stepResults, function(k, v){ if (v === 'error') errCount++; });
                    var totalSynced = Object.keys(stepResults).length;
                    var finalStatus = (errCount === 0)
                        ? 'success'
                        : (errCount < totalSynced ? 'partial' : 'error');

                    var finalColors = { success: '#46b450', partial: '#e67e22', error: '#dc3232' };
                    var finalMsgs  = {
                        success: '<?php echo esc_js( __( '✅ Sincronización completada sin errores.', 'lealez' ) ); ?>',
                        partial: '<?php echo esc_js( __( '⚠️ Completada con errores en algunos pasos.', 'lealez' ) ); ?>',
                        error:   '<?php echo esc_js( __( '❌ Sincronización fallida. Revisa los pasos.', 'lealez' ) ); ?>',
                    };

                    $('#oy-sync-global-msg')
                        .text(finalMsgs[finalStatus] || finalMsgs.error)
                        .css('color', finalColors[finalStatus] || '#dc3232');

                    setProgress(TOTAL_STEPS);

                    // Notificar al servidor — registrar en log y actualizar contadores
                    $.post(ajaxUrl, {
                        action:   'oy_gmb_sync_complete',
                        nonce:    nonces.integration,
                        post_id:  postId,
                        status:   finalStatus,
                        results:  JSON.stringify(stepResults),
                        trigger:  'button',
                    }, function(resp){
                        // Actualizar badge del contador
                        if (resp && resp.success && resp.data) {
                            var count   = resp.data.count_today;
                            var max     = resp.data.max_per_day;
                            var bypass  = resp.data.bypass;
                            var badgeTxt = count + ' / ' + (bypass ? '∞' : max);
                            var $badge  = $('#oy-sync-counter-badge');
                            $badge.text(badgeTxt);
                            if (!bypass && count >= max) {
                                $badge.addClass('limit-reached');
                                $('#oy-full-sync-btn').prop('disabled', true)
                                    .text('<?php echo esc_js( __( '🔄 Sincronizar Todo Ahora', 'lealez' ) ); ?>');
                                $('#sync-gmb-data').prop('disabled', true);
                            } else {
                                $badge.removeClass('limit-reached');
                                $('#oy-full-sync-btn').prop('disabled', false)
                                    .text('<?php echo esc_js( __( '🔄 Sincronizar Todo Ahora', 'lealez' ) ); ?>');
                                $('#sync-gmb-data').prop('disabled', false);
                            }
                        }

                        // Recargar log
                        $.post(ajaxUrl, {
                            action:  'oy_gmb_sync_log_get',
                            nonce:   nonces.integration,
                            post_id: postId,
                        }, function(logResp){
                            if (logResp && logResp.success && logResp.data && logResp.data.html) {
                                $('#oy-sync-log-container').html(logResp.data.html);
                            }
                        });
                    });

                    syncRunning = false;
                }); // end chain.done
            } // end runFullSync

            // ── Evento del botón principal ─────────────────────────────────────
            $('#oy-full-sync-btn').on('click', function(e){
                e.preventDefault();
                if (syncRunning) return;

                // Validar límites antes de iniciar (consulta al servidor)
                $.post(ajaxUrl, {
                    action:  'oy_gmb_full_sync',
                    nonce:   nonces.main,
                    post_id: postId,
                }, function(r){
                    if (r && r.success && r.data && r.data.allowed) {
                        runFullSync();
                    } else {
                        var msg = (r && r.data && r.data.message) ? r.data.message : '<?php echo esc_js( __( 'No se puede sincronizar ahora.', 'lealez' ) ); ?>';
                        alert(msg);
                    }
                }).fail(function(){
                    alert('<?php echo esc_js( __( 'Error al verificar los límites de sincronización.', 'lealez' ) ); ?>');
                });
            });

            // ── Interceptar el botón #sync-gmb-data del metabox principal del CPT ─
            // Si este metabox está presente, delegar la acción al full sync.
            $(document).on('click', '#sync-gmb-data', function(e){
                if ($('#oy-full-sync-btn').length) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    if (syncRunning) return;

                    // Hacer scroll al metabox de integración y ejecutar
                    var $intBox = $('#oy_gmb_integration_control');
                    if ($intBox.length) {
                        $('html, body').animate({ scrollTop: $intBox.offset().top - 50 }, 250, function(){
                            $('#oy-full-sync-btn').trigger('click');
                        });
                    } else {
                        $('#oy-full-sync-btn').trigger('click');
                    }
                }
            });

            // ── Habilitar/deshabilitar select de frecuencia con checkbox ───────
            $('#gmb_sync_schedule_enabled').on('change', function(){
                $('#gmb_sync_min_interval_hours').prop('disabled', !this.checked);
            });

            // ── Botón de limpiar log ───────────────────────────────────────────
            $('#oy-sync-log-clear-btn').on('click', function(){
                if (!confirm('<?php echo esc_js( __( '¿Deseas limpiar el historial de sincronizaciones?', 'lealez' ) ); ?>')) return;
                $.post(ajaxUrl, {
                    action:  'oy_gmb_sync_log_clear',
                    nonce:   nonces.integration,
                    post_id: postId,
                }, function(r){
                    if (r && r.success) {
                        $('#oy-sync-log-container').html(
                            '<p class="log-empty-msg"><?php echo esc_js( __( 'Sin entradas de log.', 'lealez' ) ); ?></p>'
                        );
                    }
                });
            });

        }); // end document.ready
        </script>
        <?php
    }

    // =========================================================================
    // RENDERIZADO DE LA TABLA DE LOG
    // =========================================================================

    /**
     * Renderizar la tabla HTML del log para mostrar en el metabox
     *
     * @param int $post_id
     * @return string HTML
     */
    private function render_log_table( $post_id ) {
        $log = get_post_meta( $post_id, 'gmb_sync_log', true );

        if ( ! is_array( $log ) || empty( $log ) ) {
            return '<p class="log-empty-msg">' . esc_html__( 'Sin entradas de log aún.', 'lealez' ) . '</p>';
        }

        // Mostrar las 20 más recientes, de más nueva a más antigua
        $entries = array_reverse( $log );
        $entries = array_slice( $entries, 0, 20 );

        $step_labels = array(
            'import'    => __( 'Base', 'lealez' ),
            'hours'     => __( 'Horarios', 'lealez' ),
            'more'      => __( 'Atributos', 'lealez' ),
            'perf'      => __( 'Métricas', 'lealez' ),
            'keywords'  => __( 'Keywords', 'lealez' ),
            'reviews'   => __( 'Reseñas', 'lealez' ),
            'menus'     => __( 'Menú', 'lealez' ),
            'busyhours' => __( 'Pico', 'lealez' ),
        );

        ob_start();
        ?>
        <table class="oy-log-table">
            <thead>
                <tr>
                    <th><?php _e( 'Fecha', 'lealez' ); ?></th>
                    <th><?php _e( 'Tipo', 'lealez' ); ?></th>
                    <th><?php _e( 'Estado', 'lealez' ); ?></th>
                    <th><?php _e( 'Pasos', 'lealez' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $entries as $entry ) :
                $ts      = isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0;
                $type    = isset( $entry['type'] )    ? (string) $entry['type']   : 'manual';
                $status  = isset( $entry['status'] )  ? (string) $entry['status'] : 'unknown';
                $results = isset( $entry['results'] ) && is_array( $entry['results'] ) ? $entry['results'] : array();

                $ok_count  = count( array_filter( $results, function( $v ) { return 'ok' === $v; } ) );
                $err_count = count( array_filter( $results, function( $v ) { return 'error' === $v; } ) );

                $status_class = '';
                if ( 'success' === $status ) $status_class = 'log-ok';
                if ( 'partial' === $status ) $status_class = 'log-partial';
                if ( 'error'   === $status ) $status_class = 'log-error';

                $type_icon = ( 'auto' === $type ) ? '🕐' : '🖱️';
                $steps_txt = $ok_count . '✅ ' . $err_count . '❌';
            ?>
                <tr title="<?php
                    $detail_parts = array();
                    foreach ( $results as $step_key => $step_val ) {
                        $lbl = isset( $step_labels[ $step_key ] ) ? $step_labels[ $step_key ] : $step_key;
                        $icon = ( 'ok' === $step_val ) ? '✅' : '❌';
                        $detail_parts[] = $icon . ' ' . $lbl;
                    }
                    echo esc_attr( implode( ' | ', $detail_parts ) );
                ?>">
                    <td style="white-space:nowrap;"><?php echo esc_html( $ts ? date_i18n( 'd/m H:i', $ts ) : '–' ); ?></td>
                    <td title="<?php echo 'auto' === $type ? esc_attr__( 'Automático (cron)', 'lealez' ) : esc_attr__( 'Manual (botón)', 'lealez' ); ?>">
                        <?php echo esc_html( $type_icon . ' ' . ucfirst( $type ) ); ?>
                    </td>
                    <td class="<?php echo esc_attr( $status_class ); ?>">
                        <?php echo esc_html( strtoupper( $status ) ); ?>
                    </td>
                    <td><?php echo esc_html( $steps_txt ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // GUARDAR CONFIGURACIÓN
    // =========================================================================

    /**
     * Guardar la configuración del metabox al guardar el post
     *
     * @param int     $post_id
     * @param WP_Post $post
     */
    public function save_metabox( $post_id, $post ) {
        // ── Seguridad ────────────────────────────────────────────────────────
        if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) return;
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( 'oy_location' !== get_post_type( $post_id ) ) return;

        // ── Guardar máximo por día (1–50) ────────────────────────────────────
        if ( isset( $_POST['gmb_sync_max_per_day'] ) ) {
            $max_per_day = max( 1, min( 50, absint( wp_unslash( $_POST['gmb_sync_max_per_day'] ) ) ) );
            update_post_meta( $post_id, 'gmb_sync_max_per_day', $max_per_day );
        }

        // ── Guardar bypass (solo superadmin) ─────────────────────────────────
        if ( is_super_admin() ) {
            $bypass = ( isset( $_POST['gmb_sync_bypass_limit'] ) && '1' === $_POST['gmb_sync_bypass_limit'] );
            update_post_meta( $post_id, 'gmb_sync_bypass_limit', $bypass ? '1' : '' );
        }

        // ── Guardar intervalo mínimo (solo valores permitidos, mínimo 24h) ───
        if ( isset( $_POST['gmb_sync_min_interval_hours'] ) ) {
            $interval = absint( wp_unslash( $_POST['gmb_sync_min_interval_hours'] ) );
            if ( ! in_array( $interval, array( 24, 48, 72, 168 ), true ) ) {
                $interval = 24;
            }
            update_post_meta( $post_id, 'gmb_sync_min_interval_hours', $interval );
        } else {
            $interval = (int) get_post_meta( $post_id, 'gmb_sync_min_interval_hours', true );
            $interval = $interval >= 24 ? $interval : 24;
        }

        // ── Guardar y gestionar schedule ─────────────────────────────────────
        $schedule_enabled = ( isset( $_POST['gmb_sync_schedule_enabled'] ) && '1' === $_POST['gmb_sync_schedule_enabled'] );
        update_post_meta( $post_id, 'gmb_sync_schedule_enabled', $schedule_enabled ? '1' : '' );

        if ( $schedule_enabled ) {
            $this->schedule_cron( $post_id, $interval );
        } else {
            $this->unschedule_cron( $post_id );
        }
    }

    // =========================================================================
    // WP-CRON
    // =========================================================================

    /**
     * Agregar intervalos de cron personalizados
     *
     * @param array $schedules
     * @return array
     */
    public function add_cron_intervals( $schedules ) {
        $intervals = array(
            24  => __( 'Cada 24 horas',  'lealez' ),
            48  => __( 'Cada 48 horas',  'lealez' ),
            72  => __( 'Cada 72 horas',  'lealez' ),
            168 => __( 'Semanal',        'lealez' ),
        );
        foreach ( $intervals as $hours => $display ) {
            $key = 'oy_gmb_every_' . $hours . 'h';
            if ( ! isset( $schedules[ $key ] ) ) {
                $schedules[ $key ] = array(
                    'interval' => $hours * HOUR_IN_SECONDS,
                    'display'  => $display,
                );
            }
        }
        return $schedules;
    }

    /**
     * Programar (o reprogramar) el cron de auto-sync para un location
     *
     * @param int $post_id
     * @param int $interval_hours
     */
    private function schedule_cron( $post_id, $interval_hours ) {
        // Cancelar cron previo con cualquier intervalo
        $this->unschedule_cron( $post_id );

        $recurrence = 'oy_gmb_every_' . $interval_hours . 'h';
        $first_run  = time() + ( $interval_hours * HOUR_IN_SECONDS );

        wp_schedule_event( $first_run, $recurrence, self::CRON_HOOK, array( $post_id ) );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[OY Integration] Cron programado para post #%d: cada %dh, primera ejecución %s.',
                $post_id,
                $interval_hours,
                date_i18n( 'd/m/Y H:i', $first_run )
            ) );
        }
    }

    /**
     * Cancelar el cron de auto-sync para un location
     *
     * @param int $post_id
     */
    private function unschedule_cron( $post_id ) {
        $ts = wp_next_scheduled( self::CRON_HOOK, array( $post_id ) );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK, array( $post_id ) );
        }
    }

    /**
     * Ejecutar la auto-sync desde WP-Cron
     * Llama a los métodos API directamente (sin HTTP interno)
     *
     * @param int $post_id
     */
    public function cron_auto_sync( $post_id ) {
        $post_id = (int) $post_id;
        if ( ! $post_id || 'oy_location' !== get_post_type( $post_id ) ) return;

        // Verificar que el schedule sigue activo (podría haberse desactivado sin cancelar cron)
        if ( ! get_post_meta( $post_id, 'gmb_sync_schedule_enabled', true ) ) return;

        $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        $location_id   = (string) get_post_meta( $post_id, 'gmb_location_id', true );

        if ( ! $business_id || ! $location_name ) return;
        if ( ! class_exists( 'Lealez_GMB_API' ) ) return;

        $results = array();

        // ── Paso 1: Sync datos base ──────────────────────────────────────────
        try {
            $sync_data       = Lealez_GMB_API::sync_location_data( $business_id, $location_name );
            $results['import'] = ( ! is_wp_error( $sync_data ) && is_array( $sync_data ) ) ? 'ok' : 'error';
        } catch ( Exception $e ) {
            $results['import'] = 'error';
        }

        // ── Paso 2: Sync horarios ────────────────────────────────────────────
        // Los horarios se actualizan junto con sync_location_data arriba
        $results['hours'] = $results['import']; // depende del mismo call

        // ── Paso 3: Atributos (refresh metadata) ────────────────────────────
        try {
            if ( method_exists( 'Lealez_GMB_API', 'get_location_attributes' ) ) {
                $attrs              = Lealez_GMB_API::get_location_attributes( $business_id, $location_name, false );
                $results['more']    = ( ! is_wp_error( $attrs ) && is_array( $attrs ) ) ? 'ok' : 'error';
            } else {
                $results['more'] = 'skip';
            }
        } catch ( Exception $e ) {
            $results['more'] = 'error';
        }

        // ── Paso 4: Métricas de rendimiento ─────────────────────────────────
        try {
            if ( $location_id && method_exists( 'Lealez_GMB_API', 'get_location_performance_metrics' ) ) {
                $metrics = array(
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
                $end_ts  = strtotime( 'yesterday' );
                $start_ts = $end_ts - ( 29 * DAY_IN_SECONDS );
                $start   = array( 'year' => (int) gmdate( 'Y', $start_ts ), 'month' => (int) gmdate( 'n', $start_ts ), 'day' => (int) gmdate( 'j', $start_ts ) );
                $end     = array( 'year' => (int) gmdate( 'Y', $end_ts ),   'month' => (int) gmdate( 'n', $end_ts ),   'day' => (int) gmdate( 'j', $end_ts ) );
                $perf    = Lealez_GMB_API::get_location_performance_metrics( $business_id, $location_id, $metrics, $start, $end, false );
                $results['perf'] = ( ! is_wp_error( $perf ) && is_array( $perf ) ) ? 'ok' : 'error';
            } else {
                $results['perf'] = 'skip';
            }
        } catch ( Exception $e ) {
            $results['perf'] = 'error';
        }

        // ── Calcular estado global y registrar en log ────────────────────────
        $total  = count( $results );
        $errors = count( array_filter( $results, function( $v ) { return 'error' === $v; } ) );

        $log_entry = array(
            'timestamp' => time(),
            'type'      => 'auto',
            'trigger'   => 'cron',
            'results'   => $results,
            'status'    => $errors === 0 ? 'success' : ( $errors < $total ? 'partial' : 'error' ),
        );
        $this->append_log_entry( $post_id, $log_entry );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[OY Integration] Cron auto-sync post #%d: %s (%d/%d errores).',
                $post_id,
                $log_entry['status'],
                $errors,
                $total
            ) );
        }
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Validar límites de tasa antes de iniciar la sync completa
     * El JS llama esto primero; si es success, procede con los sub-handlers.
     */
    public function ajax_full_sync_init() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::AJAX_NONCE_ACTION ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || 'oy_location' !== get_post_type( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Post ID inválido.', 'lealez' ) ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos para editar esta ubicación.', 'lealez' ) ) );
        }

        // ── Verificar límite diario ──────────────────────────────────────────
        $max_per_day    = (int) get_post_meta( $post_id, 'gmb_sync_max_per_day', true );
        $max_per_day    = $max_per_day > 0 ? $max_per_day : 3;
        $bypass_limit   = (bool) get_post_meta( $post_id, 'gmb_sync_bypass_limit', true );
        $effective_bypass = ( is_super_admin() && $bypass_limit );

        $count_date  = get_post_meta( $post_id, 'gmb_sync_manual_count_date', true );
        $count_today = (int) get_post_meta( $post_id, 'gmb_sync_manual_count_today', true );
        if ( $count_date !== current_time( 'Y-m-d' ) ) {
            $count_today = 0;
        }

        if ( ! $effective_bypass && $count_today >= $max_per_day ) {
            wp_send_json_error( array(
                'message'     => sprintf(
                    /* translators: 1: count, 2: max */
                    __( 'Límite diario alcanzado (%1$d/%2$d). Disponible mañana.', 'lealez' ),
                    $count_today,
                    $max_per_day
                ),
                'count_today' => $count_today,
                'max_per_day' => $max_per_day,
            ) );
        }

        // ── Verificar intervalo mínimo ───────────────────────────────────────
        $min_interval_hours = (int) get_post_meta( $post_id, 'gmb_sync_min_interval_hours', true );
        $min_interval_hours = $min_interval_hours >= 24 ? $min_interval_hours : 24;
        $last_manual        = (int) get_post_meta( $post_id, 'gmb_sync_last_manual', true );
        $next_allowed       = $last_manual ? ( $last_manual + $min_interval_hours * HOUR_IN_SECONDS ) : 0;

        if ( ! $effective_bypass && $last_manual && $next_allowed > time() ) {
            $wait_min = ceil( ( $next_allowed - time() ) / 60 );
            wp_send_json_error( array(
                'message' => sprintf(
                    /* translators: %d: minutes to wait */
                    __( 'Espera %d minuto(s) antes de volver a sincronizar (intervalo mínimo: %dh).', 'lealez' ),
                    $wait_min,
                    $min_interval_hours
                ),
            ) );
        }

        wp_send_json_success( array( 'allowed' => true ) );
    }

    /**
     * AJAX: Registrar resultado de la sync, actualizar contadores
     */
    public function ajax_sync_complete() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::AJAX_NONCE_ACTION ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || 'oy_location' !== get_post_type( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Post ID inválido.', 'lealez' ) ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
        }

        $status      = sanitize_text_field( wp_unslash( $_POST['status']  ?? 'unknown' ) );
        $results_raw = sanitize_text_field( wp_unslash( $_POST['results'] ?? '{}' ) );
        $trigger     = sanitize_text_field( wp_unslash( $_POST['trigger'] ?? 'button' ) );

        // Asegurarse de que $status sea un valor permitido
        $allowed_statuses = array( 'success', 'partial', 'error', 'unknown' );
        if ( ! in_array( $status, $allowed_statuses, true ) ) {
            $status = 'unknown';
        }

        $results = json_decode( $results_raw, true );
        if ( ! is_array( $results ) ) {
            $results = array();
        }

        // ── Actualizar contador diario ───────────────────────────────────────
        $count_date  = get_post_meta( $post_id, 'gmb_sync_manual_count_date', true );
        $count_today = (int) get_post_meta( $post_id, 'gmb_sync_manual_count_today', true );
        if ( $count_date !== current_time( 'Y-m-d' ) ) {
            $count_today = 0;
        }
        $count_today++;
        update_post_meta( $post_id, 'gmb_sync_manual_count_today', $count_today );
        update_post_meta( $post_id, 'gmb_sync_manual_count_date',  current_time( 'Y-m-d' ) );
        update_post_meta( $post_id, 'gmb_sync_last_manual',        time() );

        // ── Registrar en log ─────────────────────────────────────────────────
        $log_entry = array(
            'timestamp' => time(),
            'type'      => 'manual',
            'trigger'   => $trigger,
            'results'   => $results,
            'status'    => $status,
        );
        $this->append_log_entry( $post_id, $log_entry );

        $max_per_day  = (int) get_post_meta( $post_id, 'gmb_sync_max_per_day', true );
        $max_per_day  = $max_per_day > 0 ? $max_per_day : 3;
        $bypass_limit = (bool) get_post_meta( $post_id, 'gmb_sync_bypass_limit', true );
        $bypass_eff   = ( is_super_admin() && $bypass_limit );

        wp_send_json_success( array(
            'count_today' => $count_today,
            'max_per_day' => $max_per_day,
            'bypass'      => $bypass_eff,
            'message'     => __( 'Sincronización registrada en el log.', 'lealez' ),
        ) );
    }

    /**
     * AJAX: Devolver HTML actualizado de la tabla del log
     */
    public function ajax_log_get() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::AJAX_NONCE_ACTION ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
        }

        wp_send_json_success( array( 'html' => $this->render_log_table( $post_id ) ) );
    }

    /**
     * AJAX: Limpiar el log (solo admins)
     */
    public function ajax_log_clear() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::AJAX_NONCE_ACTION ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
        }

        delete_post_meta( $post_id, 'gmb_sync_log' );
        wp_send_json_success( array( 'message' => __( 'Log limpiado correctamente.', 'lealez' ) ) );
    }

    // =========================================================================
    // HELPERS PRIVADOS
    // =========================================================================

    /**
     * Agregar una entrada al log (máximo MAX_LOG_ENTRIES, FIFO)
     *
     * @param int   $post_id
     * @param array $entry  { timestamp, type, trigger, results, status }
     */
    private function append_log_entry( $post_id, array $entry ) {
        $log = get_post_meta( $post_id, 'gmb_sync_log', true );
        if ( ! is_array( $log ) ) {
            $log = array();
        }

        $log[] = $entry;

        // Mantener máximo MAX_LOG_ENTRIES (eliminar las más antiguas)
        if ( count( $log ) > self::MAX_LOG_ENTRIES ) {
            $log = array_slice( $log, - self::MAX_LOG_ENTRIES );
        }

        update_post_meta( $post_id, 'gmb_sync_log', $log );
    }

} // end class OY_Location_GMB_Integration_Metabox
