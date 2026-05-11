<?php
/**
 * OY Location – Metabox de Horarios de Atención
 *
 * Registro, renderizado, guardado (form save) y sincronización AJAX
 * de los horarios de atención de la ubicación con Google My Business.
 *
 * Archivo: includes/cpts/metaboxes/class-oy-location-hours-metabox.php
 *
 * Cargado desde: includes/cpts/class-oy-location-cpt.php  (constructor)
 *
 * @package Lealez
 * @subpackage CPTs/Metaboxes
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class OY_Location_Hours_Metabox
 */
class OY_Location_Hours_Metabox {

    /** @var string CPT slug */
    private $post_type = 'oy_location';

    /** @var string Nombre del campo oculto de nonce (form save) */
    private $nonce_name = 'oy_hours_meta_nonce';

    /** @var string Acción del nonce de form save */
    private $nonce_action = 'oy_hours_save_meta';

    /** @var string Acción del nonce para la llamada AJAX del botón de sincronización */
    private $ajax_nonce_action = 'oy_hours_gmb_sync';

    // ─────────────────────────────────────────────────────────────────────────
    // Constructor
    // ─────────────────────────────────────────────────────────────────────────

    public function __construct() {
        // Registrar el metabox
        add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );

        // Guardar campos al publicar/actualizar — prioridad 25 (después del save principal a 20)
        add_action( 'save_post_oy_location', array( $this, 'save_metabox' ), 25, 2 );

        // Endpoint AJAX exclusivo del botón "Sincronizar Horario desde GMB"
        add_action( 'wp_ajax_oy_sync_location_hours_from_gmb', array( $this, 'ajax_sync_hours' ) );

        // Guardado independiente del metabox de Horarios de Atención.
        add_action( 'wp_ajax_oy_save_hours_metabox', array( $this, 'ajax_save_hours_metabox' ) );

        // Push independiente de Horarios de Atención hacia GMB + verificación posterior.
        add_action( 'wp_ajax_oy_push_hours_to_gmb', array( $this, 'ajax_push_hours_to_gmb' ) );
        add_action( 'wp_ajax_oy_check_hours_push_status', array( $this, 'ajax_check_hours_push_status' ) );

        // Hook de WP-Cron para polling post-PATCH de horarios.
        add_action( 'oy_poll_hours_push_status', array( 'OY_Location_Hours_Metabox', 'cron_poll_hours_push_status' ) );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Registro del metabox
    // ─────────────────────────────────────────────────────────────────────────

    public function register_metabox() {
        add_meta_box(
            'oy_location_hours',
            __( 'Horarios de Atención', 'lealez' ),
            array( $this, 'render_metabox' ),
            $this->post_type,
            'normal',
            'default'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Renderizado
    // ─────────────────────────────────────────────────────────────────────────

    public function render_metabox( $post ) {

        // Nonce propio de este metabox (para form save)
        wp_nonce_field( $this->nonce_action, $this->nonce_name );

        // Nonce AJAX para el botón de sincronización
        $ajax_sync_nonce  = wp_create_nonce( $this->ajax_nonce_action );
        $ajax_save_nonce  = wp_create_nonce( 'oy_save_hours_metabox_' . $post->ID );
        $ajax_push_nonce  = wp_create_nonce( 'oy_push_hours_gmb_' . $post->ID );
        $ajax_check_nonce = wp_create_nonce( 'oy_check_hours_push_status_' . $post->ID );

        $days = array(
            'monday'    => __( 'Lunes',      'lealez' ),
            'tuesday'   => __( 'Martes',     'lealez' ),
            'wednesday' => __( 'Miércoles',  'lealez' ),
            'thursday'  => __( 'Jueves',     'lealez' ),
            'friday'    => __( 'Viernes',    'lealez' ),
            'saturday'  => __( 'Sábado',     'lealez' ),
            'sunday'    => __( 'Domingo',    'lealez' ),
        );

        $timezone     = get_post_meta( $post->ID, 'location_hours_timezone', true );
        $hours_status = get_post_meta( $post->ID, 'location_hours_status',   true );

        if ( empty( $timezone ) )     { $timezone     = 'America/Bogota'; }
        if ( empty( $hours_status ) ) { $hours_status = 'open_with_hours'; }

        // ✅ Auditoría: última sincronización hecha específicamente por el botón del metabox de Horarios
        $last_sync_source = (string) get_post_meta( $post->ID, 'oy_hours_last_sync_source', true );
        $last_sync_at     = (string) get_post_meta( $post->ID, 'oy_hours_last_sync_at', true );

        if ( 'hours_metabox_button' === $last_sync_source && ! empty( $last_sync_at ) ) {
            $last_sync_label = sprintf(
                __( 'Última sincronización (botón Horarios): %s', 'lealez' ),
                $last_sync_at
            );
        } else {
            $last_sync_label = __( 'Última sincronización (botón Horarios): — (aún no se ha ejecutado)', 'lealez' );
        }

        // ── Opciones de horario: "24 horas" + intervalos de 15 min ─────────────
        $time_options = array();
        $time_options['24_hours'] = __( '24 horas', 'lealez' );
        for ( $h = 0; $h < 24; $h++ ) {
            foreach ( array( 0, 15, 30, 45 ) as $m ) {
                $hh     = sprintf( '%02d', $h );
                $mm     = sprintf( '%02d', $m );
                $period = $h < 12 ? 'a.m.' : 'p.m.';
                $h12    = $h % 12;
                if ( $h12 === 0 ) { $h12 = 12; }
                $time_options[ $hh . ':' . $mm ] = sprintf( '%d:%s %s', $h12, $mm, $period );
            }
        }

        // ── Helper: render un <select> de hora ──────────────────────────────────
        $render_select = function( $name, $selected_val, $include_all_day, $disabled = false, $extra_class = '' ) use ( $time_options ) {
            $out = '<select name="' . esc_attr( $name ) . '" class="oy-hours-sel' . ( $extra_class ? ' ' . esc_attr( $extra_class ) : '' ) . '" style="min-width:130px;"' . ( $disabled ? ' disabled' : '' ) . '>';
            foreach ( $time_options as $tval => $tlabel ) {
                if ( ! $include_all_day && $tval === '24_hours' ) { continue; }
                $out .= '<option value="' . esc_attr( $tval ) . '"' . selected( $selected_val, $tval, false ) . '>' . esc_html( $tlabel ) . '</option>';
            }
            $out .= '</select>';
            return $out;
        };

        // ── Metadatos del post para el JS ───────────────────────────────────────
        $parent_business_id = get_post_meta( $post->ID, 'parent_business_id',  true );
        $gmb_location_name  = get_post_meta( $post->ID, 'gmb_location_name',   true );
        $gmb_connected      = ( ! empty( $parent_business_id ) && ! empty( $gmb_location_name ) );

        // ✅ Horario especial (editable) — estructura: array de filas
        // Cada fila: ['date'=>'YYYY-MM-DD','closed'=>bool,'open'=>'HH:MM','close'=>'HH:MM']
        $special_hours = get_post_meta( $post->ID, 'location_special_hours', true );
        if ( ! is_array( $special_hours ) ) {
            $special_hours = array();
        }

        // Normalizar filas mínimas
        $special_hours = array_values( array_filter( array_map( function( $row ) {
            if ( ! is_array( $row ) ) { return null; }
            $date   = isset( $row['date'] ) ? (string) $row['date'] : '';
            $closed = ! empty( $row['closed'] );
            $open   = isset( $row['open'] ) ? (string) $row['open'] : '09:00';
            $close  = isset( $row['close'] ) ? (string) $row['close'] : '18:00';
            if ( '' === $date ) { return null; }
            return array(
                'date'   => $date,
                'closed' => $closed ? 1 : 0,
                'open'   => $open,
                'close'  => $close,
            );
        }, $special_hours ) ) );

        ?>
        <?php /* ── BARRA DE SINCRONIZACIÓN PROPIA ── */ ?>
        <div id="oy-hours-sync-bar" style="background:#f0f6fc; border:1px solid #c3d4e4; border-radius:4px; padding:10px 14px; margin-bottom:14px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <span style="font-weight:600; color:#1d5b8e;">
                <span class="dashicons dashicons-clock" style="vertical-align:middle;"></span>
                <?php _e( 'Horarios de Atención', 'lealez' ); ?>
            </span>

            <button type="button"
                    id="oy-hours-sync-btn"
                    class="button button-secondary"
                    <?php echo ( $parent_business_id && $gmb_location_name ) ? '' : 'disabled'; ?>
                    style="margin-left:auto;">
                <span class="dashicons dashicons-update" style="vertical-align:middle; margin-top:2px;"></span>
                <?php _e( 'Sincronizar Horario desde GMB', 'lealez' ); ?>
            </button>

            <div id="oy-hours-sync-msg" style="font-size:13px; color:#555;"></div>

            <div id="oy-hours-sync-lastinfo" style="flex-basis:100%; font-size:12px; color:#666; margin-top:2px;">
                <?php echo esc_html( $last_sync_label ); ?>
            </div>
        </div>

        <?php /* ── FLUJO INDEPENDIENTE: EDITAR / GUARDAR LOCAL / ENVIAR A GMB ── */ ?>
        <div id="oy-hours-editor-bar"
             data-post-id="<?php echo esc_attr( $post->ID ); ?>"
             data-save-nonce="<?php echo esc_attr( $ajax_save_nonce ); ?>"
             data-push-nonce="<?php echo esc_attr( $ajax_push_nonce ); ?>"
             data-check-nonce="<?php echo esc_attr( $ajax_check_nonce ); ?>"
             data-gmb-connected="<?php echo $gmb_connected ? '1' : '0'; ?>"
             style="background:#fff; border:1px solid #dcdcde; border-radius:4px; padding:10px 14px; margin-bottom:14px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <button type="button" class="button button-primary" id="oy-hours-editor-start">
                <?php _e( 'Editar horarios', 'lealez' ); ?>
            </button>
            <button type="button" class="button button-primary" id="oy-hours-editor-save" style="display:none;">
                <?php _e( 'Guardar cambios del metabox', 'lealez' ); ?>
            </button>
            <button type="button" class="button button-secondary" id="oy-hours-editor-cancel" style="display:none;">
                <?php _e( 'Cancelar edición', 'lealez' ); ?>
            </button>
            <span id="oy-hours-editor-status" style="font-size:12px; margin-left:4px;"></span>
            <span style="font-size:12px; color:#666; flex-basis:100%;">
                <?php _e( 'Los campos de horarios permanecen bloqueados hasta activar “Editar horarios”. Primero guarda los cambios locales del metabox y luego usa “Enviar a GMB”.', 'lealez' ); ?>
            </span>
        </div>

        <div id="oy-hours-push-panel-wrap">
            <?php echo $this->render_hours_push_panel( $post->ID ); ?>
        </div>

        <div id="oy-hours-log-panel" style="margin-bottom:16px; border:1px solid #dadce0; border-radius:4px; overflow:hidden; background:#fff;">
            <div id="oy-hours-log-header" style="padding:9px 12px; background:#f8f9fa; border-bottom:1px solid #dadce0; cursor:pointer; display:flex; align-items:center; justify-content:space-between; gap:10px;">
                <strong style="font-size:13px; color:#1f2937;">
                    <span class="dashicons dashicons-media-text" style="font-size:16px; width:16px; height:16px; vertical-align:middle;"></span>
                    <?php _e( 'Log de Horarios de Atención', 'lealez' ); ?>
                </strong>
                <span id="oy-hours-log-toggle-icon" style="font-size:13px; color:#888;">▶</span>
            </div>
            <div id="oy-hours-log-body" style="display:none;">
                <div id="oy-hours-log-entries">
                    <?php echo $this->render_hours_log_entries( $post->ID ); ?>
                </div>
            </div>
        </div>

        <?php /* ── ZONA HORARIA ── */ ?>
        <table class="form-table" style="margin-bottom:0;">
            <tr>
                <th scope="row" style="width:160px;">
                    <label for="location_hours_timezone"><?php _e( 'Zona Horaria', 'lealez' ); ?></label>
                </th>
                <td>
                    <select name="location_hours_timezone" id="location_hours_timezone" class="regular-text">
                        <?php foreach ( timezone_identifiers_list() as $tz ) {
                            printf( '<option value="%s" %s>%s</option>', esc_attr( $tz ), selected( $timezone, $tz, false ), esc_html( $tz ) );
                        } ?>
                    </select>
                    <p class="description">⚙️ <?php _e( 'Solo manual — Google no retorna timezone en Business Information API.', 'lealez' ); ?></p>
                </td>
            </tr>
        </table>

        <hr style="margin:12px 0;">

        <?php /* ── ESTADO DEL HORARIO ── */ ?>
        <div id="oy-hours-status-wrap" style="margin-bottom:16px;">
            <h4 style="margin:0 0 8px;"><?php _e( 'Horario de atención', 'lealez' ); ?></h4>
            <p class="description" style="margin-bottom:10px;"><?php _e( 'Establece el horario de atención principal o marca tu negocio como cerrado.', 'lealez' ); ?></p>
            <?php
            $status_options = array(
                'open_with_hours'    => array(
                    'label' => __( 'Abierto, con horarios de atención', 'lealez' ),
                    'desc'  => __( 'Mostrar cuándo tu negocio está abierto', 'lealez' ),
                ),
                'open_without_hours' => array(
                    'label' => __( 'Abierto, sin horarios de atención', 'lealez' ),
                    'desc'  => __( 'No mostrar ningún horario de atención', 'lealez' ),
                ),
                'temporarily_closed' => array(
                    'label' => __( 'Cerrado temporalmente', 'lealez' ),
                    'desc'  => __( 'Indicar si tu empresa o negocio abrirán de nuevo en el futuro', 'lealez' ),
                ),
                'permanently_closed' => array(
                    'label' => __( 'Cerrado permanentemente', 'lealez' ),
                    'desc'  => __( 'Mostrar que tu empresa o negocio ya no existen', 'lealez' ),
                ),
            );
            foreach ( $status_options as $val => $info ) : ?>
                <label style="display:flex; align-items:flex-start; gap:10px; margin-bottom:8px; cursor:pointer;">
                    <input type="radio" name="location_hours_status" value="<?php echo esc_attr( $val ); ?>"
                           <?php checked( $hours_status, $val ); ?> class="oy-hours-status-radio" style="margin-top:3px; flex-shrink:0;">
                    <span>
                        <strong><?php echo esc_html( $info['label'] ); ?></strong><br>
                        <span class="description"><?php echo esc_html( $info['desc'] ); ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <?php /* ── GRILLA DE HORARIOS POR DÍA — multi período ── */ ?>
        <div id="oy-hours-grid-wrap" <?php echo ( $hours_status !== 'open_with_hours' ) ? 'style="display:none;"' : ''; ?>>
            <p class="description" style="margin-bottom:8px;">
                <?php _e( 'Importado desde GMB: <code>regularHours.periods</code>. Puedes editar manualmente también.', 'lealez' ); ?>
            </p>

            <script type="text/javascript">
            var oyHoursTimeOptions = <?php
                $js_opts = array();
                foreach ( $time_options as $tv => $tl ) { $js_opts[] = array( 'value' => $tv, 'label' => $tl ); }
                echo wp_json_encode( $js_opts );
            ?>;
            var oyHoursI18n = {
                addPeriod:    '<?php echo esc_js( __( 'Agregar otro turno', 'lealez' ) ); ?>',
                removePeriod: '<?php echo esc_js( __( 'Eliminar turno', 'lealez' ) ); ?>',
                opensAt:      '<?php echo esc_js( __( 'Abre a la(s)', 'lealez' ) ); ?>',
                closesAt:     '<?php echo esc_js( __( 'Cierra a la(s)', 'lealez' ) ); ?>',
                addSpecial:   '<?php echo esc_js( __( 'Agregar horario especial', 'lealez' ) ); ?>',
                specialTitle: '<?php echo esc_js( __( 'Horario especial', 'lealez' ) ); ?>',
                closedLabel:  '<?php echo esc_js( __( 'Cerrado', 'lealez' ) ); ?>',
                dateLabel:    '<?php echo esc_js( __( 'Fecha', 'lealez' ) ); ?>'
            };
            var oyHoursAjaxConfig = {
                postId: '<?php echo esc_js( (string) $post->ID ); ?>',
                syncNonce: '<?php echo esc_js( $ajax_sync_nonce ); ?>',
                saveNonce: '<?php echo esc_js( $ajax_save_nonce ); ?>',
                pushNonce: '<?php echo esc_js( $ajax_push_nonce ); ?>',
                checkNonce: '<?php echo esc_js( $ajax_check_nonce ); ?>',
                gmbConnected: <?php echo $gmb_connected ? 'true' : 'false'; ?>
            };
            </script>

            <div id="oy-hours-days-container">
            <?php foreach ( $days as $day_key => $day_label ) :
                $hours = get_post_meta( $post->ID, 'location_hours_' . $day_key, true );

                if ( ! is_array( $hours ) ) {
                    $hours = array( 'closed' => false, 'all_day' => false, 'periods' => array( array( 'open' => '09:00', 'close' => '18:00' ) ) );
                }
                if ( ! isset( $hours['periods'] ) || ! is_array( $hours['periods'] ) || empty( $hours['periods'] ) ) {
                    $old_open  = isset( $hours['open'] )  ? $hours['open']  : '09:00';
                    $old_close = isset( $hours['close'] ) ? $hours['close'] : '18:00';
                    if ( $old_open === '24_hours' ) {
                        $hours['all_day'] = true;
                        $hours['periods'] = array( array( 'open' => '24_hours', 'close' => '' ) );
                    } else {
                        $hours['all_day'] = false;
                        $hours['periods'] = array( array( 'open' => $old_open, 'close' => $old_close ) );
                    }
                }

                $is_closed  = ! empty( $hours['closed'] );
                $is_all_day = ! empty( $hours['all_day'] );
                $periods    = is_array( $hours['periods'] ) ? $hours['periods'] : array( array( 'open' => '09:00', 'close' => '18:00' ) );
                if ( $is_all_day ) { $periods = array( array( 'open' => '24_hours', 'close' => '' ) ); }
                ?>
                <div class="oy-day-section" data-day="<?php echo esc_attr( $day_key ); ?>"
                     style="display:flex; align-items:flex-start; gap:0; margin-bottom:4px; padding:6px 0; border-bottom:1px solid #f0f0f0;">

                    <div style="width:130px; flex-shrink:0; padding-top:8px;">
                        <strong><?php echo esc_html( $day_label ); ?></strong>
                    </div>

                    <div style="width:80px; flex-shrink:0; text-align:center; padding-top:6px;">
                        <input type="checkbox"
                               name="location_hours_<?php echo esc_attr( $day_key ); ?>[closed]"
                               value="1"
                               <?php checked( $is_closed, true ); ?>
                               class="oy-hours-closed-cb"
                               data-day="<?php echo esc_attr( $day_key ); ?>"
                               style="width:16px;height:16px;">
                        <?php if ( $is_closed ) : ?>
                            <br><small style="color:#999;"><?php _e( 'Cerrada', 'lealez' ); ?></small>
                        <?php endif; ?>
                    </div>

                    <div class="oy-day-periods" data-day="<?php echo esc_attr( $day_key ); ?>"
                         style="flex:1; <?php echo $is_closed ? 'opacity:0.5;' : ''; ?>">
                        <?php foreach ( $periods as $pidx => $period ) :
                            $popen   = isset( $period['open'] )  ? $period['open']  : '09:00';
                            $pclose  = isset( $period['close'] ) ? $period['close'] : '18:00';
                            $p24h    = ( $popen === '24_hours' );
                            $is_first = ( $pidx === 0 );
                            ?>
                            <div class="oy-period-row" style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                <div>
                                    <?php if ( $is_first ) : ?><div style="font-size:10px;color:#888;margin-bottom:2px;"><?php _e( 'Abre a la(s)', 'lealez' ); ?></div><?php endif; ?>
                                    <?php echo $render_select(
                                        'location_hours_' . $day_key . '[periods][' . $pidx . '][open]',
                                        $popen,
                                        true,
                                        $is_closed,
                                        'oy-period-open'
                                    ); ?>
                                </div>
                                <div>
                                    <?php if ( $is_first ) : ?><div style="font-size:10px;color:#888;margin-bottom:2px;"><?php _e( 'Cierra a la(s)', 'lealez' ); ?></div><?php endif; ?>
                                    <?php echo $render_select(
                                        'location_hours_' . $day_key . '[periods][' . $pidx . '][close]',
                                        $pclose,
                                        false,
                                        ( $is_closed || $p24h ),
                                        'oy-period-close'
                                    ); ?>
                                </div>
                                <div style="<?php echo $is_first ? 'margin-top:18px;' : ''; ?>">
                                    <?php if ( ! $is_all_day ) : ?>
                                        <?php if ( $pidx === 0 ) : ?>
                                            <button type="button" class="button oy-add-period" data-day="<?php echo esc_attr( $day_key ); ?>"
                                                    title="<?php esc_attr_e( 'Agregar otro turno', 'lealez' ); ?>" style="padding:2px 8px;min-height:28px;">＋</button>
                                        <?php endif; ?>
                                        <?php if ( $pidx > 0 ) : ?>
                                            <button type="button" class="button oy-remove-period" data-day="<?php echo esc_attr( $day_key ); ?>"
                                                    title="<?php esc_attr_e( 'Eliminar turno', 'lealez' ); ?>" style="padding:2px 8px;min-height:28px;color:#dc3232;">🗑</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div><!-- #oy-hours-days-container -->
        </div><!-- #oy-hours-grid-wrap -->

        <hr style="margin:16px 0;">

        <?php /* ── HORARIO ESPECIAL (Nuevo) ── */ ?>
        <div id="oy-special-hours-wrap" style="margin-top:10px;">
            <h4 style="margin:0 0 6px;"><?php _e( 'Horario especial', 'lealez' ); ?></h4>
            <p class="description" style="margin:0 0 12px;">
                <?php _e( 'Importado desde GMB: <code>specialHours.specialHourPeriods</code>. Estos horarios aplican por fecha específica (feriados u horarios puntuales).', 'lealez' ); ?>
            </p>
            <input type="hidden" name="location_special_hours_present" value="1" class="oy-special-hours-present-flag">

            <div id="oy-special-hours-list">
                <?php if ( ! empty( $special_hours ) ) : ?>
                    <?php foreach ( $special_hours as $idx => $row ) :
                        $row_date   = isset( $row['date'] ) ? (string) $row['date'] : '';
                        $row_closed = ! empty( $row['closed'] );
                        $row_open   = isset( $row['open'] ) ? (string) $row['open'] : '09:00';
                        $row_close  = isset( $row['close'] ) ? (string) $row['close'] : '18:00';
                        ?>
                        <div class="oy-special-row" data-idx="<?php echo esc_attr( $idx ); ?>" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; padding:10px 12px; border:1px solid #e5e5e5; border-radius:4px; margin-bottom:10px; background:#fafafa;">
                            <div style="min-width:160px;">
                                <label style="display:block; font-size:11px; color:#666; margin-bottom:4px;"><?php _e( 'Fecha', 'lealez' ); ?></label>
                                <input type="date"
                                       name="location_special_hours[<?php echo esc_attr( $idx ); ?>][date]"
                                       value="<?php echo esc_attr( $row_date ); ?>"
                                       class="regular-text"
                                       style="min-width:160px;">
                            </div>

                            <div style="min-width:120px; padding-top:18px;">
                                <label style="display:flex; align-items:center; gap:8px;">
                                    <input type="checkbox"
                                           class="oy-special-closed"
                                           name="location_special_hours[<?php echo esc_attr( $idx ); ?>][closed]"
                                           value="1"
                                           <?php checked( $row_closed, true ); ?>>
                                    <strong style="font-size:12px;"><?php _e( 'Cerrado', 'lealez' ); ?></strong>
                                </label>
                            </div>

                            <div>
                                <label style="display:block; font-size:11px; color:#666; margin-bottom:4px;"><?php _e( 'Abre a la(s)', 'lealez' ); ?></label>
                                <?php echo $render_select(
                                    'location_special_hours[' . $idx . '][open]',
                                    $row_open,
                                    true,
                                    $row_closed,
                                    'oy-special-open'
                                ); ?>
                            </div>

                            <div>
                                <label style="display:block; font-size:11px; color:#666; margin-bottom:4px;"><?php _e( 'Cierra a la(s)', 'lealez' ); ?></label>
                                <?php echo $render_select(
                                    'location_special_hours[' . $idx . '][close]',
                                    $row_close,
                                    false,
                                    $row_closed,
                                    'oy-special-close'
                                ); ?>
                            </div>

                            <div style="padding-top:18px;">
                                <button type="button" class="button oy-remove-special" style="color:#dc3232;">✕ <?php _e( 'Eliminar', 'lealez' ); ?></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <button type="button" id="oy-add-special-hour" class="button button-secondary">
                + <?php _e( 'Agregar horario especial', 'lealez' ); ?>
            </button>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {

            // ── Radio: mostrar/ocultar grilla ───────────────────────────────
            $('.oy-hours-status-radio').on('change', function() {
                var v = $('input[name="location_hours_status"]:checked').val();
                if (v === 'open_with_hours') {
                    $('#oy-hours-grid-wrap').show();
                } else {
                    $('#oy-hours-grid-wrap').hide();
                }
            });

            // ── Helper: generar <option> HTML para selects ──────────────────
            function buildOptions(selectedVal, includeAllDay) {
                var html = '';
                for (var i = 0; i < oyHoursTimeOptions.length; i++) {
                    var opt = oyHoursTimeOptions[i];
                    if (!includeAllDay && opt.value === '24_hours') continue;
                    html += '<option value="' + opt.value + '"' + (opt.value === selectedVal ? ' selected' : '') + '>' + opt.label + '</option>';
                }
                return html;
            }

            // ── Helper: construir un nuevo row de período (horario normal) ───────────────────
            function buildPeriodRow(dayKey, idx, openVal, closeVal, isFirst) {
                var marginTop = isFirst ? 'margin-top:18px;' : '';
                var labelO = isFirst ? '<div style="font-size:10px;color:#888;margin-bottom:2px;">' + oyHoursI18n.opensAt  + '</div>' : '';
                var labelC = isFirst ? '<div style="font-size:10px;color:#888;margin-bottom:2px;">' + oyHoursI18n.closesAt + '</div>' : '';
                return '<div class="oy-period-row" style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">' +
                    '<div>' + labelO + '<select name="location_hours_' + dayKey + '[periods][' + idx + '][open]" class="oy-hours-sel oy-period-open" style="min-width:130px;">' + buildOptions(openVal, true)  + '</select></div>' +
                    '<div>' + labelC + '<select name="location_hours_' + dayKey + '[periods][' + idx + '][close]" class="oy-hours-sel oy-period-close" style="min-width:130px;">' + buildOptions(closeVal, false) + '</select></div>' +
                    '<div style="' + marginTop + '"><button type="button" class="button oy-add-period" data-day="' + dayKey + '" title="' + oyHoursI18n.addPeriod + '" style="padding:2px 8px;min-height:28px;">＋</button></div>' +
                '</div>';
            }

            // ── Helper: reindexar names y botones tras add/remove ───────────
            function reindexPeriods(dayKey) {
                var $rows = $('.oy-day-periods[data-day="' + dayKey + '"] .oy-period-row');
                $rows.each(function(idx) {
                    $(this).find('.oy-period-open' ).attr('name', 'location_hours_' + dayKey + '[periods][' + idx + '][open]');
                    $(this).find('.oy-period-close').attr('name', 'location_hours_' + dayKey + '[periods][' + idx + '][close]');
                    var $btnWrap = $(this).find('div:last-child');
                    $btnWrap.find('.oy-add-period, .oy-remove-period').remove();
                    if (idx === 0) {
                        $btnWrap.append('<button type="button" class="button oy-add-period" data-day="' + dayKey + '" title="' + oyHoursI18n.addPeriod + '" style="padding:2px 8px;min-height:28px;">＋</button>');
                    } else {
                        $btnWrap.append('<button type="button" class="button oy-remove-period" data-day="' + dayKey + '" title="' + oyHoursI18n.removePeriod + '" style="padding:2px 8px;min-height:28px;color:#dc3232;">🗑</button>');
                    }
                });
            }

            // ── Checkbox "Cerrada" (horario normal) ───────────────────────────
            $(document).on('change', '.oy-hours-closed-cb', function() {
                var day    = $(this).data('day');
                var closed = $(this).is(':checked');
                $('.oy-day-periods[data-day="' + day + '"]').css('opacity', closed ? 0.5 : 1).find('select').prop('disabled', closed);
            });

            // ── Agregar período (horario normal) ──────────────────────────────
            $(document).on('click', '.oy-add-period', function() {
                var dayKey   = $(this).data('day');
                var $periods = $('.oy-day-periods[data-day="' + dayKey + '"]');
                var newIdx   = $periods.find('.oy-period-row').length;
                $periods.append(buildPeriodRow(dayKey, newIdx, '09:00', '18:00', false));
                reindexPeriods(dayKey);
            });

            // ── Eliminar período (horario normal) ─────────────────────────────
            $(document).on('click', '.oy-remove-period', function() {
                var dayKey = $(this).data('day');
                $(this).closest('.oy-period-row').remove();
                reindexPeriods(dayKey);
            });

            // ── Select "Abre a la(s)" = 24h → deshabilitar cierre (horario normal) ───────────
            $(document).on('change', '.oy-period-open', function() {
                var isAllDay = $(this).val() === '24_hours';
                var $row = $(this).closest('.oy-period-row');
                $row.find('.oy-period-close').prop('disabled', isAllDay);
                enforceHoursConditionalDisabled();
                if (isAllDay) { $row.find('.oy-period-close').val(''); }
                $row.find('.oy-add-period')[ isAllDay ? 'hide' : 'show' ]();
            });

            // ── Exponer helpers a window ───────────────────────────────────────
            window.oyHours_buildOptions  = buildOptions;
            window.oyHours_buildRow      = buildPeriodRow;
            window.oyHours_reindex       = reindexPeriods;

            // ── Helper local: reconstruir DOM de períodos para un día concreto ──
            function rebuildDayFromData(dayKey, dayData) {
                var $pane = $('.oy-day-periods[data-day="' + dayKey + '"]');
                var $cb   = $('[name="location_hours_' + dayKey + '[closed]"]');
                if (!$pane.length) return;

                var isClosed   = !!dayData.closed;
                var isAllDay   = !!dayData.all_day;
                var periodsArr = dayData.periods || [];

                $cb.prop('checked', isClosed);
                $cb.siblings('small').remove();
                if (isClosed) {
                    $cb.after('<br><small style="color:#999;"><?php echo esc_js( __( 'Cerrada', 'lealez' ) ); ?></small>');
                }

                $pane.css('opacity', isClosed ? 0.5 : 1);
                $pane.find('.oy-period-row').remove();

                if (isClosed) {
                    $pane.append(buildPeriodRow(dayKey, 0, '09:00', '18:00', true));
                    $pane.find('select').prop('disabled', true);
                    return;
                }

                if (isAllDay || (periodsArr.length === 1 && periodsArr[0].open === '24_hours')) {
                    $pane.append(buildPeriodRow(dayKey, 0, '24_hours', '', true));
                    $pane.find('.oy-period-close').prop('disabled', true).val('');
                    $pane.find('.oy-add-period').hide();
                    return;
                }

                for (var ri = 0; ri < periodsArr.length; ri++) {
                    var rowHtml = buildPeriodRow(
                        dayKey,
                        ri,
                        (periodsArr[ri].open  || '09:00'),
                        (periodsArr[ri].close || '18:00'),
                        (ri === 0)
                    );
                    $pane.append(rowHtml);
                }
                reindexPeriods(dayKey);
            }

            // ─────────────────────────────────────────────────────────────────
            // HORARIO ESPECIAL (JS)
            // ─────────────────────────────────────────────────────────────────

            function specialNextIdx() {
                var max = -1;
                $('#oy-special-hours-list .oy-special-row').each(function(){
                    var idx = parseInt($(this).attr('data-idx'), 10);
                    if (!isNaN(idx)) max = Math.max(max, idx);
                });
                return max + 1;
            }

            function buildSpecialRow(idx, row) {
                row = row || {};
                var dateVal   = row.date   ? String(row.date) : '';
                var closedVal = row.closed ? true : false;
                var openVal   = row.open   ? String(row.open) : '09:00';
                var closeVal  = row.close  ? String(row.close) : '18:00';

                var dis = closedVal ? ' disabled' : '';
                var closeDis = (closedVal || openVal === '24_hours') ? ' disabled' : '';

                var html  = '';
                html += '<div class="oy-special-row" data-idx="' + idx + '" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; padding:10px 12px; border:1px solid #e5e5e5; border-radius:4px; margin-bottom:10px; background:#fafafa;">';

                html +=   '<div style="min-width:160px;">' +
                            '<label style="display:block; font-size:11px; color:#666; margin-bottom:4px;">' + oyHoursI18n.dateLabel + '</label>' +
                            '<input type="date" name="location_special_hours[' + idx + '][date]" value="' + $('<div>').text(dateVal).html() + '" class="regular-text" style="min-width:160px;">' +
                          '</div>';

                html +=   '<div style="min-width:120px; padding-top:18px;">' +
                            '<label style="display:flex; align-items:center; gap:8px;">' +
                                '<input type="checkbox" class="oy-special-closed" name="location_special_hours[' + idx + '][closed]" value="1"' + (closedVal ? ' checked' : '') + '>' +
                                '<strong style="font-size:12px;">' + oyHoursI18n.closedLabel + '</strong>' +
                            '</label>' +
                          '</div>';

                html +=   '<div>' +
                            '<label style="display:block; font-size:11px; color:#666; margin-bottom:4px;">' + oyHoursI18n.opensAt + '</label>' +
                            '<select name="location_special_hours[' + idx + '][open]" class="oy-hours-sel oy-special-open" style="min-width:130px;"' + dis + '>' + buildOptions(openVal, true) + '</select>' +
                          '</div>';

                html +=   '<div>' +
                            '<label style="display:block; font-size:11px; color:#666; margin-bottom:4px;">' + oyHoursI18n.closesAt + '</label>' +
                            '<select name="location_special_hours[' + idx + '][close]" class="oy-hours-sel oy-special-close" style="min-width:130px;"' + closeDis + '>' + buildOptions(closeVal, false) + '</select>' +
                          '</div>';

                html +=   '<div style="padding-top:18px;">' +
                            '<button type="button" class="button oy-remove-special" style="color:#dc3232;">✕ <?php echo esc_js( __( 'Eliminar', 'lealez' ) ); ?></button>' +
                          '</div>';

                html += '</div>';
                return html;
            }

            function rebuildSpecialHours(list) {
                $('#oy-special-hours-list').empty();
                if (!list || !Array.isArray(list) || !list.length) return;
                for (var i=0;i<list.length;i++){
                    $('#oy-special-hours-list').append(buildSpecialRow(i, list[i]));
                }
            }

            function reindexSpecialRows() {
                $('#oy-special-hours-list .oy-special-row').each(function(idx) {
                    $(this).attr('data-idx', idx);
                    $(this).find('input[type="date"]').attr('name', 'location_special_hours[' + idx + '][date]');
                    $(this).find('.oy-special-closed').attr('name', 'location_special_hours[' + idx + '][closed]');
                    $(this).find('.oy-special-open').attr('name', 'location_special_hours[' + idx + '][open]');
                    $(this).find('.oy-special-close').attr('name', 'location_special_hours[' + idx + '][close]');
                });
            }

            function markHoursEditorDirty(message) {
                if (oyHoursEditor.enabled) {
                    refreshHoursDirtyState();
                    setHoursEditorStatus(message || 'Cambios sin guardar en este metabox.', 'warn');
                }
            }

            // Add special row (manual)
            $('#oy-add-special-hour').on('click', function(e){
                e.preventDefault();
                var idx = specialNextIdx();
                $('#oy-special-hours-list').append(buildSpecialRow(idx, {date:'', closed:false, open:'09:00', close:'18:00'}));
                reindexSpecialRows();
                enforceHoursConditionalDisabled();
                markHoursEditorDirty('Cambios sin guardar en horario especial.');
            });

            // Remove special row
            $(document).on('click', '.oy-remove-special', function(e){
                e.preventDefault();
                $(this).closest('.oy-special-row').remove();
                reindexSpecialRows();
                markHoursEditorDirty('Cambios sin guardar en horario especial.');
            });

            // Toggle closed → disable selects
            $(document).on('change', '.oy-special-closed', function(){
                var $row = $(this).closest('.oy-special-row');
                var closed = $(this).is(':checked');
                $row.find('.oy-special-open').prop('disabled', closed);
                $row.find('.oy-special-close').prop('disabled', closed || $row.find('.oy-special-open').val() === '24_hours');
                enforceHoursConditionalDisabled();
                markHoursEditorDirty('Cambios sin guardar en horario especial.');
            });

            // Select "Abre a la(s)" = 24h → deshabilitar cierre (horario especial)
            $(document).on('change', '.oy-special-open', function(){
                var $row = $(this).closest('.oy-special-row');
                var isAllDay = $(this).val() === '24_hours';
                if (isAllDay) {
                    $row.find('.oy-special-close').val('');
                }
                enforceHoursConditionalDisabled();
                markHoursEditorDirty('Cambios sin guardar en horario especial.');
            });

            // ─────────────────────────────────────────────────────────────────
            // FLUJO INDEPENDIENTE: Editar / Guardar local / Enviar a GMB
            // ─────────────────────────────────────────────────────────────────

            var _dayOrder = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
            var oyHoursEditor = {
                enabled: false,
                dirty: false,
                saving: false,
                baseline: null
            };

            function setHoursEditorStatus(msg, type) {
                var colors = { success:'#166534', error:'#dc3232', warn:'#b45309', info:'#555' };
                $('#oy-hours-editor-status').text(msg || '').css('color', colors[type] || '#555');
            }

            function captureHoursState() {
                var state = {
                    timezone: $('#location_hours_timezone').val() || '',
                    status: $('input[name="location_hours_status"]:checked').val() || 'open_with_hours',
                    days: {},
                    special_hours: []
                };

                for (var i = 0; i < _dayOrder.length; i++) {
                    var dk = _dayOrder[i];
                    var $cb = $('[name="location_hours_' + dk + '[closed]"]');
                    var $pane = $('.oy-day-periods[data-day="' + dk + '"]');
                    var periods = [];
                    $pane.find('.oy-period-row').each(function() {
                        var openVal = $(this).find('.oy-period-open').val() || '09:00';
                        var closeVal = $(this).find('.oy-period-close').val() || '18:00';
                        periods.push({ open: openVal, close: openVal === '24_hours' ? '' : closeVal });
                    });
                    if (!periods.length) {
                        periods.push({ open:'09:00', close:'18:00' });
                    }
                    state.days[dk] = {
                        closed: $cb.is(':checked') ? 1 : 0,
                        all_day: (periods.length === 1 && periods[0].open === '24_hours') ? 1 : 0,
                        periods: periods
                    };
                }

                $('#oy-special-hours-list .oy-special-row').each(function() {
                    var openVal = $(this).find('.oy-special-open').val() || '09:00';
                    var row = {
                        date: $(this).find('input[type="date"]').val() || '',
                        closed: $(this).find('.oy-special-closed').is(':checked') ? 1 : 0,
                        open: openVal,
                        close: openVal === '24_hours' ? '' : ($(this).find('.oy-special-close').val() || '18:00')
                    };
                    if (row.date) {
                        state.special_hours.push(row);
                    }
                });

                return state;
            }

            function applyHoursState(state) {
                state = state || {};
                if (typeof state.timezone !== 'undefined') {
                    $('#location_hours_timezone').val(state.timezone);
                }
                if (state.status) {
                    $('input[name="location_hours_status"][value="' + state.status + '"]').prop('checked', true).trigger('change');
                }
                if (state.days && typeof state.days === 'object') {
                    for (var i = 0; i < _dayOrder.length; i++) {
                        var dk = _dayOrder[i];
                        if (typeof state.days[dk] !== 'undefined') {
                            rebuildDayFromData(dk, state.days[dk]);
                        }
                    }
                }
                rebuildSpecialHours(state.special_hours || []);
                reindexSpecialRows();
                enforceHoursConditionalDisabled();
            }

            function sameHoursState(a, b) {
                return JSON.stringify(a || {}) === JSON.stringify(b || {});
            }

            function refreshHoursDirtyState() {
                if (!oyHoursEditor.enabled) {
                    oyHoursEditor.dirty = false;
                } else {
                    oyHoursEditor.dirty = !sameHoursState(oyHoursEditor.baseline, captureHoursState());
                }
                $('#oy-hours-editor-save').prop('disabled', !oyHoursEditor.dirty || oyHoursEditor.saving);
            }

            function enforceHoursConditionalDisabled() {
                if (!oyHoursEditor.enabled) {
                    return;
                }

                $('.oy-day-section').each(function() {
                    var dayKey = $(this).data('day');
                    var isClosed = $('[name="location_hours_' + dayKey + '[closed]"]').is(':checked');
                    var $pane = $('.oy-day-periods[data-day="' + dayKey + '"]');
                    if (isClosed) {
                        $pane.find('select, button').prop('disabled', true);
                        return;
                    }
                    $pane.find('select, button').prop('disabled', false);
                    $pane.find('.oy-period-row').each(function() {
                        var isAllDay = ($(this).find('.oy-period-open').val() === '24_hours');
                        $(this).find('.oy-period-close').prop('disabled', isAllDay);
                        if (isAllDay) {
                            $(this).find('.oy-remove-period').prop('disabled', true);
                        }
                    });
                });

                $('#oy-special-hours-list .oy-special-row').each(function() {
                    var closed = $(this).find('.oy-special-closed').is(':checked');
                    var isAllDay = $(this).find('.oy-special-open').val() === '24_hours';
                    $(this).find('.oy-special-open').prop('disabled', closed);
                    $(this).find('.oy-special-close').prop('disabled', closed || isAllDay);
                });
            }

            function setHoursFieldsLocked(locked) {
                var $metabox = $('#oy_location_hours');
                var exceptions = '#oy-hours-editor-start,#oy-hours-editor-save,#oy-hours-editor-cancel,#oy-hours-sync-btn,#oy-push-hours-to-gmb-btn,#oy-check-hours-push-status-btn';
                $metabox.find('input, select, textarea, button').not(exceptions).prop('disabled', !!locked);

                $('#oy-hours-editor-start').toggle(locked);
                $('#oy-hours-editor-save, #oy-hours-editor-cancel').toggle(!locked);
                $('#oy-hours-sync-btn').prop('disabled', !oyHoursAjaxConfig.gmbConnected || oyHoursEditor.enabled || oyHoursEditor.saving);
                $('#oy-push-hours-to-gmb-btn, #oy-check-hours-push-status-btn').prop('disabled', !oyHoursAjaxConfig.gmbConnected || oyHoursEditor.enabled || oyHoursEditor.saving);
                $('#oy_location_hours').toggleClass('oy-hours-editing-active', !locked);
                if (!locked) {
                    enforceHoursConditionalDisabled();
                }
                refreshHoursDirtyState();
            }

            function refreshHoursPanelsFromResponse(d) {
                if (d && d.panel_html) {
                    $('#oy-hours-push-panel-wrap').html(d.panel_html);
                }
                if (d && d.log_html) {
                    $('#oy-hours-log-entries').html(d.log_html);
                }
            }

            $(document).on('click', '#oy-hours-log-header', function() {
                var $body = $('#oy-hours-log-body');
                $body.toggle();
                $('#oy-hours-log-toggle-icon').text($body.is(':visible') ? '▼' : '▶');
            });

            $('#oy_location_hours').on('change input', 'input, select, textarea', function() {
                if (oyHoursEditor.enabled) {
                    refreshHoursDirtyState();
                    setHoursEditorStatus('Cambios sin guardar en este metabox.', 'warn');
                }
            });

            $(document).on('click', '#oy-hours-editor-start', function(e) {
                e.preventDefault();
                if (!$('#post_ID').val()) {
                    setHoursEditorStatus('Guarda primero la ubicación para poder editar este metabox de forma independiente.', 'error');
                    return;
                }
                oyHoursEditor.baseline = captureHoursState();
                oyHoursEditor.enabled = true;
                oyHoursEditor.dirty = false;
                setHoursFieldsLocked(false);
                setHoursEditorStatus('Modo edición activo. Guarda los cambios del metabox antes de enviar a GMB.', 'info');
            });

            $(document).on('click', '#oy-hours-editor-cancel', function(e) {
                e.preventDefault();
                applyHoursState(oyHoursEditor.baseline || captureHoursState());
                oyHoursEditor.enabled = false;
                oyHoursEditor.dirty = false;
                setHoursFieldsLocked(true);
                setHoursEditorStatus('Edición cancelada. Se restauró el último estado guardado/local.', 'info');
            });

            $(document).on('click', '#oy-hours-editor-save', function(e) {
                e.preventDefault();
                if (!oyHoursEditor.enabled || oyHoursEditor.saving) {
                    return;
                }
                var postId = $('#post_ID').val();
                if (!postId) {
                    setHoursEditorStatus('Guarda primero la ubicación antes de guardar el metabox.', 'error');
                    return;
                }

                oyHoursEditor.saving = true;
                $('#oy-hours-editor-save').prop('disabled', true);
                setHoursEditorStatus('Guardando cambios locales...', 'info');

                var formData = $('#post').serializeArray();
                formData.push({ name:'action', value:'oy_save_hours_metabox' });
                formData.push({ name:'nonce', value:oyHoursAjaxConfig.saveNonce });
                formData.push({ name:'post_id', value:postId });

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    success: function(resp) {
                        if (!resp || !resp.success) {
                            setHoursEditorStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'No se pudo guardar el metabox.', 'error');
                            return;
                        }
                        refreshHoursPanelsFromResponse(resp.data || {});
                        oyHoursEditor.baseline = captureHoursState();
                        oyHoursEditor.enabled = false;
                        oyHoursEditor.dirty = false;
                        setHoursFieldsLocked(true);
                        setHoursEditorStatus((resp.data && resp.data.message) ? resp.data.message : 'Cambios locales guardados.', 'success');
                    },
                    error: function() {
                        setHoursEditorStatus('Error de conexión al guardar el metabox.', 'error');
                    },
                    complete: function() {
                        oyHoursEditor.saving = false;
                        refreshHoursDirtyState();
                        setHoursFieldsLocked(!oyHoursEditor.enabled);
                    }
                });
            });

            $(document).on('click', '#oy-push-hours-to-gmb-btn', function(e) {
                e.preventDefault();
                if (oyHoursEditor.enabled && oyHoursEditor.dirty) {
                    setHoursEditorStatus('Guarda primero los cambios locales del metabox antes de enviar a GMB.', 'error');
                    return;
                }
                var $btn = $(this);
                var postId = $('#post_ID').val();
                var $msg = $('#oy-hours-push-action-msg');
                if (!postId) {
                    setHoursEditorStatus('Guarda primero la ubicación antes de enviar a GMB.', 'error');
                    return;
                }

                $btn.prop('disabled', true);
                $msg.show().css('color', '#555').text('Enviando horarios a GMB...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'oy_push_hours_to_gmb',
                        nonce: oyHoursAjaxConfig.pushNonce,
                        post_id: postId
                    },
                    success: function(resp) {
                        if (!resp || !resp.success) {
                            $msg.show().css('color', '#dc3232').text((resp && resp.data && resp.data.message) ? resp.data.message : 'No se pudo enviar a GMB.');
                            if (resp && resp.data) { refreshHoursPanelsFromResponse(resp.data); }
                            return;
                        }
                        refreshHoursPanelsFromResponse(resp.data || {});
                        $msg.show().css('color', '#166534').text((resp.data && resp.data.message) ? resp.data.message : 'Horarios enviados a GMB.');
                    },
                    error: function() {
                        $msg.show().css('color', '#dc3232').text('Error de conexión al enviar a GMB.');
                    },
                    complete: function() {
                        setHoursFieldsLocked(!oyHoursEditor.enabled);
                    }
                });
            });

            $(document).on('click', '#oy-check-hours-push-status-btn', function(e) {
                e.preventDefault();
                var postId = $('#post_ID').val();
                var $btn = $(this);
                var $msg = $('#oy-hours-push-action-msg');
                $btn.prop('disabled', true);
                $msg.show().css('color', '#555').text('Verificando estado en GMB...');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'oy_check_hours_push_status',
                        nonce: oyHoursAjaxConfig.checkNonce,
                        post_id: postId
                    },
                    success: function(resp) {
                        if (!resp || !resp.success) {
                            $msg.show().css('color', '#dc3232').text((resp && resp.data && resp.data.message) ? resp.data.message : 'No se pudo verificar el estado.');
                            if (resp && resp.data) { refreshHoursPanelsFromResponse(resp.data); }
                            return;
                        }
                        refreshHoursPanelsFromResponse(resp.data || {});
                        $msg.show().css('color', '#166534').text((resp.data && resp.data.message) ? resp.data.message : 'Estado verificado.');
                    },
                    error: function() {
                        $msg.show().css('color', '#dc3232').text('Error de conexión al verificar estado.');
                    },
                    complete: function() {
                        setHoursFieldsLocked(!oyHoursEditor.enabled);
                    }
                });
            });

            setHoursFieldsLocked(true);
            oyHoursEditor.baseline = captureHoursState();

            // ─────────────────────────────────────────────────────────────────
            // BOTÓN: Sincronizar Horario desde GMB
            // ─────────────────────────────────────────────────────────────────

            $('#oy-hours-sync-btn').on('click', function() {
                var $btn   = $(this);
                var $msg   = $('#oy-hours-sync-msg');
                var $last  = $('#oy-hours-sync-lastinfo');
                var postId = $('#post_ID').val();
                var bizId  = $('#parent_business_id').val();
                var locName= $('#gmb_location_name').val();

                if (oyHoursEditor.enabled && oyHoursEditor.dirty) {
                    $msg.html('<span style="color:#dc3232;">⚠ Guarda o cancela primero la edición local antes de sincronizar desde GMB.</span>');
                    return;
                }

                if (!postId || !bizId || !locName) {
                    $msg.html('<span style="color:#dc3232;"><?php echo esc_js( __( '⚠ Selecciona la empresa y la ubicación GMB primero.', 'lealez' ) ); ?></span>');
                    return;
                }

                $btn.prop('disabled', true);
                $msg.html('<span style="color:#555;"><?php echo esc_js( __( '⏳ Sincronizando horarios...', 'lealez' ) ); ?></span>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action:        'oy_sync_location_hours_from_gmb',
                        nonce:         oyHoursAjaxConfig.syncNonce,
                        post_id:       postId,
                        business_id:   bizId,
                        location_name: locName
                    },
                    success: function(response) {
                        $btn.prop('disabled', false);

                        if (!response.success) {
                            $msg.html('<span style="color:#dc3232;">⚠ ' + (response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( 'Error al sincronizar.', 'lealez' ) ); ?>') + '</span>');
                            return;
                        }

                        var d = response.data;

                        if (d.hours_status) {
                            $('input[name="location_hours_status"][value="' + d.hours_status + '"]')
                                .prop('checked', true)
                                .trigger('change');
                        }

                        if (d.days && typeof d.days === 'object') {
                            for (var i = 0; i < _dayOrder.length; i++) {
                                var dk = _dayOrder[i];
                                if (d.days[dk] !== undefined) {
                                    rebuildDayFromData(dk, d.days[dk]);
                                }
                            }
                        }

                        // ✅ Nuevo: Horario especial
                        if (d.special_hours && Array.isArray(d.special_hours)) {
                            rebuildSpecialHours(d.special_hours);
                        }

                        if (d.synced_at) {
                            $last.text('<?php echo esc_js( __( 'Última sincronización (botón Horarios): ', 'lealez' ) ); ?>' + d.synced_at);
                        } else {
                            $last.text('<?php echo esc_js( __( 'Última sincronización (botón Horarios): (sin timestamp)', 'lealez' ) ); ?>');
                        }

                        refreshHoursPanelsFromResponse(d);
                        oyHoursEditor.baseline = captureHoursState();
                        oyHoursEditor.enabled = false;
                        oyHoursEditor.dirty = false;
                        setHoursFieldsLocked(true);

                        $msg.html('<span style="color:#46b450;">✓ ' + (d.message ? d.message : '<?php echo esc_js( __( 'Horarios sincronizados', 'lealez' ) ); ?>') + '</span>');
                    },
                    error: function() {
                        $btn.prop('disabled', false);
                        $msg.html('<span style="color:#dc3232;"><?php echo esc_js( __( '⚠ Error de conexión al sincronizar.', 'lealez' ) ); ?></span>');
                    }
                });
            });

        }); // end jQuery ready
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guardado al publicar/actualizar el post (form save)
    // ─────────────────────────────────────────────────────────────────────────

    public function save_metabox( $post_id, $post ) {

        if ( ! isset( $_POST[ $this->nonce_name ] ) || ! wp_verify_nonce( $_POST[ $this->nonce_name ], $this->nonce_action ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( get_post_type( $post_id ) !== $this->post_type ) {
            return;
        }

        // Si el metabox está bloqueado por el flujo independiente, los campos no viajan en POST.
        // En ese caso no tocamos nada para evitar sobrescribir horarios ya guardados.
        if ( ! isset( $_POST['location_hours_status'] ) && ! isset( $_POST['location_hours_timezone'] ) && ! isset( $_POST['location_special_hours'] ) ) {
            return;
        }

        $payload = $this->build_hours_payload_from_request( $post_id );
        $this->persist_hours_payload( $post_id, $payload, 'form_save', true );
    }

    /**
     * Construye un payload normalizado de horarios desde $_POST.
     *
     * @param int $post_id
     * @return array
     */
    private function build_hours_payload_from_request( $post_id = 0 ) {
        $post_id = absint( $post_id );

        $timezone = isset( $_POST['location_hours_timezone'] )
            ? sanitize_text_field( wp_unslash( $_POST['location_hours_timezone'] ) )
            : ( $post_id ? (string) get_post_meta( $post_id, 'location_hours_timezone', true ) : 'America/Bogota' );

        if ( '' === $timezone ) {
            $timezone = 'America/Bogota';
        }

        $valid_hours_statuses = array( 'open_with_hours', 'open_without_hours', 'temporarily_closed', 'permanently_closed' );
        $hours_status = isset( $_POST['location_hours_status'] )
            ? sanitize_text_field( wp_unslash( $_POST['location_hours_status'] ) )
            : ( $post_id ? (string) get_post_meta( $post_id, 'location_hours_status', true ) : 'open_with_hours' );

        if ( ! in_array( $hours_status, $valid_hours_statuses, true ) ) {
            $hours_status = 'open_with_hours';
        }

        $days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        $days_payload = array();

        foreach ( $days as $day ) {
            $raw = isset( $_POST[ 'location_hours_' . $day ] )
                ? wp_unslash( $_POST[ 'location_hours_' . $day ] )
                : ( $post_id ? get_post_meta( $post_id, 'location_hours_' . $day, true ) : array() );

            if ( ! is_array( $raw ) ) {
                $raw = array();
            }

            $closed = ! empty( $raw['closed'] );
            $periods_raw = isset( $raw['periods'] ) && is_array( $raw['periods'] ) ? $raw['periods'] : array();
            $periods_clean = array();
            $is_all_day = false;

            foreach ( $periods_raw as $praw ) {
                if ( ! is_array( $praw ) ) {
                    continue;
                }

                $popen  = sanitize_text_field( isset( $praw['open'] ) ? (string) $praw['open'] : '09:00' );
                $pclose = sanitize_text_field( isset( $praw['close'] ) ? (string) $praw['close'] : '18:00' );

                if ( '24_hours' === $popen ) {
                    $is_all_day    = true;
                    $periods_clean = array( array( 'open' => '24_hours', 'close' => '' ) );
                    break;
                }

                if ( ! $this->is_valid_ui_time( $popen ) ) {
                    continue;
                }

                if ( ! $this->is_valid_ui_time( $pclose ) ) {
                    $pclose = '18:00';
                }

                $periods_clean[] = array(
                    'open'  => $popen,
                    'close' => $pclose,
                );
            }

            if ( empty( $periods_clean ) ) {
                $periods_clean = array( array( 'open' => '09:00', 'close' => '18:00' ) );
            }

            $first_open  = isset( $periods_clean[0]['open'] ) ? $periods_clean[0]['open'] : '09:00';
            $first_close = isset( $periods_clean[0]['close'] ) ? $periods_clean[0]['close'] : '18:00';

            $days_payload[ $day ] = array(
                'closed'  => $closed,
                'all_day' => $is_all_day,
                'periods' => $periods_clean,
                'open'    => $is_all_day ? '24_hours' : $first_open,
                'close'   => $is_all_day ? '' : $first_close,
            );
        }

        $special_present_in_request = array_key_exists( 'location_special_hours_present', $_POST ) || array_key_exists( 'location_special_hours', $_POST );

        if ( isset( $_POST['location_special_hours'] ) && is_array( $_POST['location_special_hours'] ) ) {
            $special_raw = wp_unslash( $_POST['location_special_hours'] );
        } elseif ( $special_present_in_request ) {
            // Importante: si el metabox envía la bandera pero no hay filas, significa que el usuario eliminó todos los horarios especiales.
            $special_raw = array();
        } elseif ( $post_id ) {
            // Compatibilidad: si el metabox estaba bloqueado y sus campos no viajaron en POST, conservar el valor actual.
            $special_raw = get_post_meta( $post_id, 'location_special_hours', true );
            if ( ! is_array( $special_raw ) ) {
                $special_raw = array();
            }
        } else {
            $special_raw = array();
        }

        $special_clean = $this->normalize_special_hours_rows( $special_raw );

        return array(
            'timezone'      => $timezone,
            'hours_status'  => $hours_status,
            'days'          => $days_payload,
            'special_hours' => $special_clean,
        );
    }

    /**
     * Persiste el payload local en post_meta y registra auditoría.
     *
     * @param int    $post_id
     * @param array  $payload
     * @param string $source
     * @param bool   $mark_pending_publish
     * @return array
     */
    private function persist_hours_payload( $post_id, array $payload, $source = 'manual_metabox_save', $mark_pending_publish = true ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return array();
        }

        $timezone     = isset( $payload['timezone'] ) ? sanitize_text_field( (string) $payload['timezone'] ) : 'America/Bogota';
        $hours_status = isset( $payload['hours_status'] ) ? sanitize_key( (string) $payload['hours_status'] ) : 'open_with_hours';

        update_post_meta( $post_id, 'location_hours_timezone', $timezone );
        update_post_meta( $post_id, 'location_hours_status', $hours_status );

        $days = isset( $payload['days'] ) && is_array( $payload['days'] ) ? $payload['days'] : array();
        foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $day ) {
            if ( isset( $days[ $day ] ) && is_array( $days[ $day ] ) ) {
                update_post_meta( $post_id, 'location_hours_' . $day, $days[ $day ] );
            }
        }

        $special_hours = isset( $payload['special_hours'] ) && is_array( $payload['special_hours'] ) ? $payload['special_hours'] : array();
        update_post_meta( $post_id, 'location_special_hours', $special_hours );
        update_post_meta( $post_id, 'date_modified', current_time( 'mysql' ) );
        update_post_meta( $post_id, 'modified_by_user_id', get_current_user_id() );

        $now_ts = current_time( 'timestamp' );
        $user   = wp_get_current_user();
        $by     = ( $user instanceof WP_User && ! empty( $user->user_login ) ) ? $user->user_login : 'system';

        $save_meta = array(
            'at'       => gmdate( 'Y-m-d\TH:i:s\Z', $now_ts ),
            'at_ts'    => $now_ts,
            'at_label' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $now_ts ),
            'by'       => $by,
            'source'   => sanitize_key( (string) $source ),
        );

        update_post_meta( $post_id, 'oy_hours_last_manual_save', $save_meta );

        if ( $mark_pending_publish ) {
            update_post_meta( $post_id, 'oy_hours_local_pending_publish', '1' );
        } else {
            delete_post_meta( $post_id, 'oy_hours_local_pending_publish' );
        }

        $this->append_hours_history(
            $post_id,
            $mark_pending_publish ? 'local_metabox_save' : 'gmb_sync_applied_locally',
            $mark_pending_publish
                ? __( 'Se guardaron cambios locales del metabox de horarios. Pendiente por enviar a GMB.', 'lealez' )
                : __( 'Se importaron horarios desde GMB y se actualizaron los campos locales.', 'lealez' ),
            array(
                'source'       => $source,
                'hours_status' => $hours_status,
                'timezone'     => $timezone,
                'days_count'   => is_array( $days ) ? count( $days ) : 0,
                'special_count'=> count( $special_hours ),
                'payload'      => $payload,
            )
        );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[OY Hours] persist_hours_payload source=' . $source . ' post_id=' . $post_id . ' pending=' . ( $mark_pending_publish ? '1' : '0' ) . ' payload=' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        }

        return $save_meta;
    }

    /**
     * AJAX: guarda localmente el metabox sin depender del botón Actualizar del CPT.
     */
    public function ajax_save_hours_metabox() {
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_save_hours_metabox_' . $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido o post_id faltante.', 'lealez' ) ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'No tienes permisos para guardar esta ubicación.', 'lealez' ) ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || $this->post_type !== $post->post_type ) {
            wp_send_json_error( array( 'message' => __( 'El post indicado no es una ubicación válida.', 'lealez' ) ) );
        }

        $payload   = $this->build_hours_payload_from_request( $post_id );
        $save_meta = $this->persist_hours_payload( $post_id, $payload, 'manual_metabox_save', true );

        wp_send_json_success( array(
            'message'               => __( 'Cambios locales de horarios guardados correctamente. Ya puedes usar “Enviar a GMB”.', 'lealez' ),
            'saved_at'              => isset( $save_meta['at'] ) ? $save_meta['at'] : '',
            'saved_at_label'        => isset( $save_meta['at_label'] ) ? $save_meta['at_label'] : '',
            'saved_by'              => isset( $save_meta['by'] ) ? $save_meta['by'] : '',
            'panel_html'            => $this->render_hours_push_panel( $post_id ),
            'log_html'              => $this->render_hours_log_entries( $post_id ),
            'local_pending_publish' => true,
        ) );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: Sincronizar horarios desde GMB (botón propio del metabox)
    // ─────────────────────────────────────────────────────────────────────────

    public function ajax_sync_hours() {

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, $this->ajax_nonce_action ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }

        $post_id       = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
        $business_id   = isset( $_POST['business_id'] ) ? absint( wp_unslash( $_POST['business_id'] ) ) : 0;
        $location_name = isset( $_POST['location_name'] ) ? sanitize_text_field( wp_unslash( $_POST['location_name'] ) ) : '';

        if ( ! $post_id || ! $business_id || empty( $location_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Parámetros inválidos. Verifica que el post, la empresa y la ubicación GMB estén seleccionados.', 'lealez' ) ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos para editar este post.', 'lealez' ) ) );
        }

        if ( ! class_exists( 'Lealez_GMB_API' ) ) {
            wp_send_json_error( array( 'message' => __( 'La clase Lealez_GMB_API no está disponible.', 'lealez' ) ) );
        }

        $this->append_hours_history( $post_id, 'gmb_sync_request', __( 'Solicitud de sincronización de horarios enviada a GMB.', 'lealez' ), array(
            'business_id'   => $business_id,
            'location_name' => $location_name,
            'direction'     => 'GMB → Lealez',
        ) );

        $data = Lealez_GMB_API::sync_location_data( $business_id, $location_name );

        if ( is_wp_error( $data ) ) {
            $this->append_hours_history( $post_id, 'gmb_sync_error', $data->get_error_message(), array( 'direction' => 'GMB → Lealez' ) );
            wp_send_json_error( array(
                'message'   => $data->get_error_message(),
                'log_html'  => $this->render_hours_log_entries( $post_id ),
                'panel_html'=> $this->render_hours_push_panel( $post_id ),
            ) );
        }

        if ( ! is_array( $data ) ) {
            $this->append_hours_history( $post_id, 'gmb_sync_error', __( 'GMB devolvió una respuesta no válida al sincronizar horarios.', 'lealez' ), array( 'response' => $data ) );
            wp_send_json_error( array(
                'message'   => __( 'No se pudo obtener información de la ubicación desde Google.', 'lealez' ),
                'log_html'  => $this->render_hours_log_entries( $post_id ),
                'panel_html'=> $this->render_hours_push_panel( $post_id ),
            ) );
        }

        $regular       = ( isset( $data['regularHours'] ) && is_array( $data['regularHours'] ) ) ? $data['regularHours'] : array();
        $open_info_raw = ( isset( $data['openInfo'] ) && is_array( $data['openInfo'] ) ) ? $data['openInfo'] : array();
        $special_raw   = ( isset( $data['specialHours'] ) && is_array( $data['specialHours'] ) ) ? $data['specialHours'] : array();

        $open_status    = strtoupper( (string) ( isset( $open_info_raw['status'] ) ? $open_info_raw['status'] : '' ) );
        $hours_type     = strtoupper( (string) ( isset( $open_info_raw['openingHoursType'] ) ? $open_info_raw['openingHoursType'] : '' ) );
        $is_always_open = ( $hours_type === 'ALWAYS_OPEN' );

        if ( $open_status === 'CLOSED_TEMPORARILY' ) {
            $hours_status = 'temporarily_closed';
        } elseif ( $open_status === 'CLOSED_PERMANENTLY' ) {
            $hours_status = 'permanently_closed';
        } else {
            $has_periods  = ! empty( $regular['periods'] );
            $hours_status = ( $has_periods || $is_always_open ) ? 'open_with_hours' : 'open_without_hours';
        }

        $days_meta = array();
        if ( $is_always_open ) {
            $days_meta = $this->build_always_open_daily_meta();
            update_post_meta( $post_id, 'gmb_regular_hours_raw', array( 'openingHoursType' => 'ALWAYS_OPEN' ) );
        } elseif ( ! empty( $regular ) ) {
            $days_meta = $this->map_gmb_regular_hours_to_daily_meta( $regular );
            update_post_meta( $post_id, 'gmb_regular_hours_raw', $regular );
        } else {
            update_post_meta( $post_id, 'gmb_regular_hours_raw', array() );
            $days_meta = $this->build_closed_daily_meta();
        }

        $all_days      = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        $days_response = array();
        foreach ( $all_days as $dk ) {
            if ( isset( $days_meta[ $dk ] ) ) {
                $days_response[ $dk ] = $days_meta[ $dk ];
            }
        }

        $special_meta = array();
        if ( ! empty( $special_raw ) ) {
            update_post_meta( $post_id, 'gmb_special_hours_raw', $special_raw );
            $special_meta = $this->map_gmb_special_hours_to_meta( $special_raw );
        } else {
            update_post_meta( $post_id, 'gmb_special_hours_raw', array() );
            $special_meta = array();
        }

        if ( ! empty( $open_info_raw ) ) {
            update_post_meta( $post_id, 'gmb_open_info_raw', $open_info_raw );
        } else {
            update_post_meta( $post_id, 'gmb_open_info_raw', array() );
        }

        $payload = array(
            'timezone'      => (string) get_post_meta( $post_id, 'location_hours_timezone', true ),
            'hours_status'  => $hours_status,
            'days'          => $days_response,
            'special_hours' => $special_meta,
        );
        if ( '' === $payload['timezone'] ) {
            $payload['timezone'] = 'America/Bogota';
        }

        $this->persist_hours_payload( $post_id, $payload, 'gmb_sync_button', false );

        $synced_at = current_time( 'mysql' );
        update_post_meta( $post_id, 'oy_hours_last_sync_source', 'hours_metabox_button' );
        update_post_meta( $post_id, 'oy_hours_last_sync_at', $synced_at );

        $this->append_hours_history( $post_id, 'gmb_sync_response', __( 'Horarios recibidos desde GMB y aplicados localmente.', 'lealez' ), array(
            'direction'      => 'GMB → Lealez',
            'hours_status'   => $hours_status,
            'regular_raw'    => $regular,
            'special_raw'    => $special_raw,
            'open_info_raw'  => $open_info_raw,
            'days_synced'    => count( $days_response ),
            'special_synced' => count( $special_meta ),
        ) );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log(
                '[OY Hours Sync] post_id=' . $post_id .
                ' | hours_status=' . $hours_status .
                ' | days_synced=' . count( $days_response ) .
                ' | special_hours=' . ( is_array( $special_meta ) ? count( $special_meta ) : 0 ) .
                ' | synced_at=' . $synced_at
            );
        }

        wp_send_json_success( array(
            'hours_status'  => $hours_status,
            'days'          => $days_response,
            'special_hours' => $special_meta,
            'synced_at'     => $synced_at,
            'message'       => __( 'Horarios sincronizados correctamente.', 'lealez' ),
            'panel_html'    => $this->render_hours_push_panel( $post_id ),
            'log_html'      => $this->render_hours_log_entries( $post_id ),
        ) );
    }

    /**
     * AJAX: envía los horarios guardados localmente hacia GMB.
     */
    public function ajax_push_hours_to_gmb() {
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_push_hours_gmb_' . $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido o post_id faltante.', 'lealez' ) ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos para editar esta ubicación.', 'lealez' ) ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || $this->post_type !== $post->post_type ) {
            wp_send_json_error( array( 'message' => __( 'Post no válido o no es una ubicación.', 'lealez' ) ) );
        }

        $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );

        if ( ! $business_id || '' === trim( $location_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Esta ubicación no tiene empresa o ubicación GMB vinculada.', 'lealez' ) ) );
        }

        if ( ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'push_location_hours' ) ) {
            wp_send_json_error( array( 'message' => __( 'La API de GMB para enviar horarios no está disponible. Actualiza class-lealez-gmb-api.php.', 'lealez' ) ) );
        }

        $existing_job = get_post_meta( $post_id, 'gmb_hours_push_job', true );
        if ( is_array( $existing_job ) && in_array( (string) ( $existing_job['status'] ?? '' ), array( 'pending_review', 'queued' ), true ) ) {
            wp_send_json_error( array(
                'message'    => __( 'Ya existe un envío de horarios pendiente. Usa “Verificar estado” antes de enviar otro cambio.', 'lealez' ),
                'panel_html' => $this->render_hours_push_panel( $post_id ),
                'log_html'   => $this->render_hours_log_entries( $post_id ),
            ) );
        }

        $snapshot = Lealez_GMB_API::get_location_hours_snapshot( $business_id, $location_name );
        if ( is_wp_error( $snapshot ) ) {
            $this->append_hours_history( $post_id, 'push_snapshot_error', $snapshot->get_error_message(), array( 'direction' => 'Lealez → GMB' ) );
            wp_send_json_error( array(
                'message'    => __( 'No se pudo leer el estado actual de horarios en GMB: ', 'lealez' ) . $snapshot->get_error_message(),
                'panel_html' => $this->render_hours_push_panel( $post_id ),
                'log_html'   => $this->render_hours_log_entries( $post_id ),
            ) );
        }

        if ( ! empty( $snapshot['metadata']['hasPendingEdits'] ) ) {
            $this->append_hours_history( $post_id, 'push_blocked_pending_edits', __( 'GMB reporta ediciones pendientes. Se bloqueó el envío para evitar pisar una revisión en curso.', 'lealez' ), array( 'snapshot' => $snapshot ) );
            wp_send_json_error( array(
                'message'    => __( 'Google indica que esta ubicación tiene ediciones pendientes. Verifica primero el estado o sincroniza desde GMB antes de enviar un nuevo cambio.', 'lealez' ),
                'panel_html' => $this->render_hours_push_panel( $post_id ),
                'log_html'   => $this->render_hours_log_entries( $post_id ),
            ) );
        }

        $gmb_payload = $this->build_gmb_hours_payload_from_meta( $post_id );
        if ( is_wp_error( $gmb_payload ) ) {
            wp_send_json_error( array(
                'message'    => $gmb_payload->get_error_message(),
                'panel_html' => $this->render_hours_push_panel( $post_id ),
                'log_html'   => $this->render_hours_log_entries( $post_id ),
            ) );
        }

        $this->append_hours_history( $post_id, 'push_request', __( 'Enviando horarios locales a GMB.', 'lealez' ), array(
            'direction'     => 'Lealez → GMB',
            'business_id'   => $business_id,
            'location_name' => $location_name,
            'payload'       => $gmb_payload,
            'snapshot_before' => $snapshot,
        ) );

        $result = Lealez_GMB_API::push_location_hours( $business_id, $location_name, $gmb_payload );

        if ( is_wp_error( $result ) ) {
            $err_data   = $result->get_error_data();
            $violations = isset( $err_data['field_violations'] ) && is_array( $err_data['field_violations'] ) ? $err_data['field_violations'] : array();
            $viol_txt   = '';
            foreach ( $violations as $v ) {
                if ( is_array( $v ) ) {
                    $viol_txt .= ' | ' . ( $v['field'] ?? '' ) . ': ' . ( $v['description'] ?? '' );
                }
            }

            $this->append_hours_history( $post_id, 'push_error', $result->get_error_message() . $viol_txt, array(
                'direction' => 'Lealez → GMB',
                'error'     => $err_data,
            ) );

            wp_send_json_error( array(
                'message'    => $result->get_error_message() . $viol_txt,
                'panel_html' => $this->render_hours_push_panel( $post_id ),
                'log_html'   => $this->render_hours_log_entries( $post_id ),
            ) );
        }

        $current_user = wp_get_current_user();
        $user_login   = ( $current_user instanceof WP_User && $current_user->user_login ) ? $current_user->user_login : 'system';
        $now_ts       = time();
        $now_iso      = gmdate( 'Y-m-d\TH:i:s\Z', $now_ts );

        $job = array(
            'status'          => 'pending_review',
            'pushed_at'       => $now_iso,
            'pushed_at_ts'    => $now_ts,
            'pushed_by'       => $user_login,
            'update_mask'     => isset( $result['update_mask'] ) ? $result['update_mask'] : '',
            'submitted'       => isset( $result['submitted'] ) ? $result['submitted'] : $gmb_payload,
            'snapshot_before' => array(
                'regularHours' => $snapshot['regularHours'] ?? null,
                'specialHours' => $snapshot['specialHours'] ?? null,
                'openInfo'     => $snapshot['openInfo'] ?? null,
            ),
            'api_response'    => isset( $result['patch_response'] ) ? $result['patch_response'] : array(),
            'poll_count'      => 0,
            'next_poll_at'    => $now_ts + 60,
            'resolved_at'     => null,
            'history'         => array(
                array(
                    'event'  => 'push_sent',
                    'at'     => $now_iso,
                    'at_ts'  => $now_ts,
                    'by'     => $user_login,
                    'detail' => 'PATCH de horarios enviado a GMB. updateMask=' . ( isset( $result['update_mask'] ) ? $result['update_mask'] : '' ),
                ),
            ),
        );

        update_post_meta( $post_id, 'gmb_hours_push_job', $job );
        wp_schedule_single_event( $now_ts + 60, 'oy_poll_hours_push_status', array( $post_id ) );

        $this->append_hours_history( $post_id, 'push_response', __( 'GMB aceptó la solicitud PATCH de horarios.', 'lealez' ), array(
            'direction'   => 'Lealez → GMB',
            'update_mask' => $job['update_mask'],
            'response'    => $result,
        ) );

        wp_send_json_success( array(
            'message'    => __( 'Horarios enviados a Google Business Profile. Estado inicial: pendiente de verificación.', 'lealez' ),
            'panel_html' => $this->render_hours_push_panel( $post_id ),
            'log_html'   => $this->render_hours_log_entries( $post_id ),
        ) );
    }

    /**
     * AJAX: verifica manualmente el estado del último push de horarios.
     */
    public function ajax_check_hours_push_status() {
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_check_hours_push_status_' . $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
        }

        $job = get_post_meta( $post_id, 'gmb_hours_push_job', true );
        if ( empty( $job ) || ! is_array( $job ) ) {
            wp_send_json_error( array( 'message' => __( 'No hay envío de horarios registrado para esta ubicación.', 'lealez' ) ) );
        }

        if ( in_array( (string) ( $job['status'] ?? '' ), array( 'applied', 'rejected', 'google_override', 'timeout', 'cancelled' ), true ) ) {
            wp_send_json_success( array(
                'message'    => __( 'El cambio de horarios ya está resuelto.', 'lealez' ),
                'status'     => $job['status'],
                'panel_html' => $this->render_hours_push_panel( $post_id ),
                'log_html'   => $this->render_hours_log_entries( $post_id ),
            ) );
        }

        $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );

        if ( ! $business_id || ! $location_name ) {
            wp_send_json_error( array( 'message' => __( 'No hay empresa/ubicación GMB vinculada.', 'lealez' ) ) );
        }

        $current = Lealez_GMB_API::poll_location_hours_status( $business_id, $location_name );
        if ( is_wp_error( $current ) ) {
            $this->append_hours_history( $post_id, 'manual_check_error', $current->get_error_message(), array( 'direction' => 'GMB → Lealez' ) );
            wp_send_json_error( array(
                'message'    => __( 'Error al consultar GMB: ', 'lealez' ) . $current->get_error_message(),
                'panel_html' => $this->render_hours_push_panel( $post_id ),
                'log_html'   => $this->render_hours_log_entries( $post_id ),
            ) );
        }

        $new_status = self::determine_hours_push_outcome( $job, $current );
        $now_ts     = time();
        $now_iso    = gmdate( 'Y-m-d\TH:i:s\Z', $now_ts );

        $job['status']     = $new_status;
        $job['poll_count'] = (int) ( $job['poll_count'] ?? 0 ) + 1;
        if ( ! isset( $job['history'] ) || ! is_array( $job['history'] ) ) {
            $job['history'] = array();
        }
        $job['history'][] = array(
            'event'  => 'manual_check',
            'at'     => $now_iso,
            'at_ts'  => $now_ts,
            'by'     => wp_get_current_user()->user_login ?? 'system',
            'detail' => 'Verificación manual de horarios → estado: ' . $new_status
                . ' | hasPendingEdits=' . ( ! empty( $current['metadata']['hasPendingEdits'] ) ? 'true' : 'false' )
                . ' | hasGoogleUpdated=' . ( ! empty( $current['metadata']['hasGoogleUpdated'] ) ? 'true' : 'false' ),
        );

        if ( in_array( $new_status, array( 'applied', 'rejected', 'google_override' ), true ) ) {
            $job['resolved_at'] = $now_iso;
        }

        update_post_meta( $post_id, 'gmb_hours_push_job', $job );

        if ( 'applied' === $new_status ) {
            delete_post_meta( $post_id, 'oy_hours_local_pending_publish' );
        }

        $this->append_hours_history( $post_id, 'manual_check_response', 'Verificación manual de horarios → ' . $new_status, array(
            'current' => $current,
            'job'     => $job,
        ) );

        wp_send_json_success( array(
            'message'    => __( 'Estado de horarios verificado.', 'lealez' ),
            'status'     => $new_status,
            'panel_html' => $this->render_hours_push_panel( $post_id ),
            'log_html'   => $this->render_hours_log_entries( $post_id ),
        ) );
    }

    /**
     * WP-Cron: polling automático post-PATCH de horarios.
     *
     * @param int $post_id
     */
    public static function cron_poll_hours_push_status( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        $job = get_post_meta( $post_id, 'gmb_hours_push_job', true );
        if ( empty( $job ) || ! is_array( $job ) ) {
            return;
        }

        if ( in_array( (string) ( $job['status'] ?? '' ), array( 'applied', 'rejected', 'google_override', 'timeout', 'cancelled' ), true ) ) {
            return;
        }

        $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );

        if ( ! $business_id || ! $location_name || ! class_exists( 'Lealez_GMB_API' ) ) {
            return;
        }

        $current = Lealez_GMB_API::poll_location_hours_status( $business_id, $location_name );
        $now_ts  = time();
        $now_iso = gmdate( 'Y-m-d\TH:i:s\Z', $now_ts );

        if ( is_wp_error( $current ) ) {
            $job['poll_count'] = (int) ( $job['poll_count'] ?? 0 ) + 1;
            $job['history'][] = array(
                'event'  => 'poll_error',
                'at'     => $now_iso,
                'at_ts'  => $now_ts,
                'by'     => 'cron',
                'detail' => 'Error API horarios: ' . $current->get_error_message(),
            );
            update_post_meta( $post_id, 'gmb_hours_push_job', $job );
            self::schedule_next_hours_poll( $post_id, $job['poll_count'] );
            return;
        }

        $new_status        = self::determine_hours_push_outcome( $job, $current );
        $job['poll_count'] = (int) ( $job['poll_count'] ?? 0 ) + 1;
        $job['status']     = $new_status;
        $job['history'][]  = array(
            'event'  => 'cron_poll',
            'at'     => $now_iso,
            'at_ts'  => $now_ts,
            'by'     => 'cron',
            'detail' => 'Poll horarios #' . $job['poll_count'] . ' → estado: ' . $new_status
                . ' | hasPendingEdits=' . ( ! empty( $current['metadata']['hasPendingEdits'] ) ? 'true' : 'false' )
                . ' | hasGoogleUpdated=' . ( ! empty( $current['metadata']['hasGoogleUpdated'] ) ? 'true' : 'false' ),
        );

        if ( in_array( $new_status, array( 'applied', 'rejected', 'google_override' ), true ) ) {
            $job['resolved_at'] = $now_iso;
        }

        update_post_meta( $post_id, 'gmb_hours_push_job', $job );

        if ( 'applied' === $new_status ) {
            delete_post_meta( $post_id, 'oy_hours_local_pending_publish' );
        }

        if ( 'pending_review' === $new_status ) {
            self::schedule_next_hours_poll( $post_id, $job['poll_count'] );
        }
    }

    /**
     * Programa el siguiente polling de horarios.
     *
     * @param int $post_id
     * @param int $poll_count
     */
    private static function schedule_next_hours_poll( $post_id, $poll_count ) {
        $intervals = array( 60, 120, 300, 600, 900, 1800, 3600, 7200, 21600 );
        $idx       = (int) $poll_count;
        $delay     = isset( $intervals[ $idx ] ) ? $intervals[ $idx ] : HOUR_IN_SECONDS;
        wp_schedule_single_event( time() + $delay, 'oy_poll_hours_push_status', array( absint( $post_id ) ) );
    }

    /**
     * Determina resultado de push comparando snapshot actual contra submitted y before.
     *
     * @param array $job
     * @param array $current
     * @return string
     */
    private static function determine_hours_push_outcome( array $job, array $current ) {
        if ( ! empty( $current['metadata']['hasPendingEdits'] ) ) {
            return 'pending_review';
        }

        $submitted = isset( $job['submitted'] ) && is_array( $job['submitted'] ) ? $job['submitted'] : array();
        $before    = isset( $job['snapshot_before'] ) && is_array( $job['snapshot_before'] ) ? $job['snapshot_before'] : array();

        $current_cmp   = self::normalize_gmb_hours_for_compare( $current );
        $submitted_cmp = self::normalize_gmb_hours_for_compare( $submitted );
        $before_cmp    = self::normalize_gmb_hours_for_compare( $before );

        if ( $current_cmp === $submitted_cmp ) {
            return 'applied';
        }

        if ( $current_cmp === $before_cmp ) {
            return 'rejected';
        }

        return 'google_override';
    }

    /**
     * Normaliza horarios GMB para comparación estable.
     *
     * @param array $payload
     * @return array
     */
    private static function normalize_gmb_hours_for_compare( array $payload ) {
        $open_status = isset( $payload['openInfo']['status'] ) ? strtoupper( (string) $payload['openInfo']['status'] ) : '';

        $regular = array();
        $regular_periods = array();
        if ( isset( $payload['regularHours']['periods'] ) && is_array( $payload['regularHours']['periods'] ) ) {
            $regular_periods = $payload['regularHours']['periods'];
        }
        foreach ( $regular_periods as $period ) {
            if ( ! is_array( $period ) ) {
                continue;
            }
            $regular[] = array(
                'openDay'    => strtoupper( (string) ( $period['openDay'] ?? '' ) ),
                'openTime'   => self::normalize_gmb_time_for_compare( $period['openTime'] ?? array() ),
                'closeDay'   => strtoupper( (string) ( $period['closeDay'] ?? '' ) ),
                'closeTime'  => self::normalize_gmb_time_for_compare( $period['closeTime'] ?? array() ),
            );
        }
        usort( $regular, function( $a, $b ) {
            return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
        } );

        $special = array();
        $special_periods = array();
        if ( isset( $payload['specialHours']['specialHourPeriods'] ) && is_array( $payload['specialHours']['specialHourPeriods'] ) ) {
            $special_periods = $payload['specialHours']['specialHourPeriods'];
        }
        foreach ( $special_periods as $period ) {
            if ( ! is_array( $period ) ) {
                continue;
            }
            $special[] = array(
                'startDate' => self::normalize_gmb_date_for_compare( $period['startDate'] ?? array() ),
                'endDate'   => self::normalize_gmb_date_for_compare( $period['endDate'] ?? array() ),
                'closed'    => ! empty( $period['closed'] ) ? 1 : 0,
                'openTime'  => self::normalize_gmb_time_for_compare( $period['openTime'] ?? array() ),
                'closeTime' => self::normalize_gmb_time_for_compare( $period['closeTime'] ?? array() ),
            );
        }
        usort( $special, function( $a, $b ) {
            return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
        } );

        return array(
            'openStatus' => $open_status,
            'regular'    => $regular,
            'special'    => $special,
        );
    }

    private static function normalize_gmb_time_for_compare( $time ) {
        if ( ! is_array( $time ) ) {
            return array( 'hours' => 0, 'minutes' => 0 );
        }
        return array(
            'hours'   => isset( $time['hours'] ) ? (int) $time['hours'] : 0,
            'minutes' => isset( $time['minutes'] ) ? (int) $time['minutes'] : 0,
        );
    }

    private static function normalize_gmb_date_for_compare( $date ) {
        if ( ! is_array( $date ) ) {
            return array( 'year' => 0, 'month' => 0, 'day' => 0 );
        }
        return array(
            'year'  => isset( $date['year'] ) ? (int) $date['year'] : 0,
            'month' => isset( $date['month'] ) ? (int) $date['month'] : 0,
            'day'   => isset( $date['day'] ) ? (int) $date['day'] : 0,
        );
    }

    /**
     * Renderiza el panel del último envío de horarios a GMB.
     *
     * @param int $post_id
     * @return string
     */
    private function render_hours_push_panel( $post_id ) {
        $post_id       = absint( $post_id );
        $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        $gmb_connected = ( $business_id && '' !== trim( $location_name ) );
        $local_pending = (bool) get_post_meta( $post_id, 'oy_hours_local_pending_publish', true );
        $last_manual   = get_post_meta( $post_id, 'oy_hours_last_manual_save', true );
        $job           = get_post_meta( $post_id, 'gmb_hours_push_job', true );

        $last_manual_label = is_array( $last_manual ) && ! empty( $last_manual['at_label'] ) ? (string) $last_manual['at_label'] : '';
        $last_manual_user  = is_array( $last_manual ) && ! empty( $last_manual['by'] ) ? (string) $last_manual['by'] : '';

        ob_start();
        ?>
        <div id="oy-hours-push-panel" style="margin-bottom:16px; border:1px solid #dadce0; border-radius:4px; overflow:hidden; background:#fff;">
            <div style="padding:10px 14px; background:#f8f9fa; border-bottom:1px solid #dadce0; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                <strong style="font-size:13px; color:#1f2937;">
                    <span class="dashicons dashicons-upload" style="font-size:16px; width:16px; height:16px; vertical-align:middle;"></span>
                    <?php _e( 'Publicación de Horarios en GMB', 'lealez' ); ?>
                </strong>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <button type="button"
                            id="oy-push-hours-to-gmb-btn"
                            class="button button-primary"
                            <?php echo $gmb_connected ? '' : 'disabled'; ?>
                            style="display:inline-flex; align-items:center; gap:6px;">
                        <span class="dashicons dashicons-cloud-upload" style="margin-top:3px;"></span>
                        <?php _e( 'Enviar a GMB', 'lealez' ); ?>
                    </button>

                    <?php if ( ! empty( $job ) && is_array( $job ) && ! in_array( (string) ( $job['status'] ?? '' ), array( 'applied', 'rejected', 'google_override', 'timeout', 'cancelled' ), true ) ) : ?>
                        <button type="button" id="oy-check-hours-push-status-btn" class="button button-secondary" style="display:inline-flex; align-items:center; gap:6px;">
                            <span class="dashicons dashicons-search" style="margin-top:3px;"></span>
                            <?php _e( 'Verificar estado', 'lealez' ); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( $local_pending ) : ?>
                <div style="padding:10px 14px; background:#eef4ff; border-bottom:1px solid #d7e3ff;">
                    <p style="margin:0; font-size:12px; color:#1d4ed8; font-weight:600;">
                        <?php _e( 'Hay cambios locales de horarios pendientes por publicar en GMB.', 'lealez' ); ?>
                    </p>
                    <?php if ( $last_manual_label ) : ?>
                        <p style="margin:4px 0 0; font-size:11px; color:#4b5563;">
                            <?php printf( esc_html__( 'Último guardado local: %s', 'lealez' ), esc_html( $last_manual_label ) ); ?>
                            <?php if ( $last_manual_user ) : ?>
                                &nbsp;·&nbsp;<?php printf( esc_html__( 'por %s', 'lealez' ), esc_html( $last_manual_user ) ); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( ! $gmb_connected ) : ?>
                <div style="padding:10px 14px;">
                    <p style="margin:0; font-size:12px; color:#999; font-style:italic;">
                        <?php _e( 'Requiere empresa y ubicación GMB vinculadas para poder publicar horarios.', 'lealez' ); ?>
                    </p>
                </div>
            <?php elseif ( ! empty( $job ) && is_array( $job ) ) :
                $status    = (string) ( $job['status'] ?? 'unknown' );
                $pushed_at = (string) ( $job['pushed_at'] ?? '' );
                $pushed_by = (string) ( $job['pushed_by'] ?? '' );
                $resolved  = (string) ( $job['resolved_at'] ?? '' );
                $poll_n    = (int) ( $job['poll_count'] ?? 0 );

                $status_cfg = array(
                    'pending_review'  => array( '🕐', '#e07800', __( 'Pendiente de verificación por Google', 'lealez' ) ),
                    'queued'          => array( '🕐', '#e07800', __( 'En cola de envío', 'lealez' ) ),
                    'applied'         => array( '✅', '#166534', __( 'Horarios aplicados en Google', 'lealez' ) ),
                    'rejected'        => array( '❌', '#dc3232', __( 'Google rechazó el cambio de horarios', 'lealez' ) ),
                    'google_override' => array( '⚠️', '#b45309', __( 'Google devolvió un horario diferente al enviado', 'lealez' ) ),
                    'timeout'         => array( '⏳', '#6b7280', __( 'Sin respuesta de Google en 30 días', 'lealez' ) ),
                    'error'           => array( '🔴', '#dc3232', __( 'Error técnico al enviar horarios', 'lealez' ) ),
                );
                $cfg = $status_cfg[ $status ] ?? array( '⚪', '#555', $status );
                ?>
                <div style="padding:10px 14px;">
                    <p style="margin:0 0 6px; font-size:13px; font-weight:600; color:<?php echo esc_attr( $cfg[1] ); ?>;">
                        <?php echo esc_html( $cfg[0] . ' ' . $cfg[2] ); ?>
                    </p>
                    <p style="margin:0; font-size:11px; color:#666;">
                        <?php if ( $pushed_at ) : ?>
                            <?php printf( esc_html__( 'Enviado: %s por %s', 'lealez' ), esc_html( $pushed_at ), esc_html( $pushed_by ) ); ?>
                        <?php endif; ?>
                        <?php if ( $resolved ) : ?>
                            &nbsp;·&nbsp;<?php printf( esc_html__( 'Resuelto: %s', 'lealez' ), esc_html( $resolved ) ); ?>
                        <?php endif; ?>
                        <?php if ( $poll_n ) : ?>
                            &nbsp;·&nbsp;<?php printf( esc_html__( 'Verificaciones: %d', 'lealez' ), $poll_n ); ?>
                        <?php endif; ?>
                    </p>
                    <?php if ( 'pending_review' === $status || 'queued' === $status ) : ?>
                        <p style="margin:6px 0 0; font-size:11px; color:#888; font-style:italic;">
                            <?php _e( 'El sistema verificará si Google aplicó, rechazó o normalizó los horarios enviados.', 'lealez' ); ?>
                        </p>
                    <?php endif; ?>
                    <?php if ( 'google_override' === $status ) : ?>
                        <p style="margin:6px 0 0; font-size:11px; color:#b45309;">
                            <?php _e( 'Google devolvió valores distintos. Usa “Sincronizar Horario desde GMB” para importar el estado actual.', 'lealez' ); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <div style="padding:10px 14px;">
                    <p style="margin:0; font-size:12px; color:#888; font-style:italic;">
                        <?php _e( 'Ningún cambio de horarios enviado aún. Activa “Editar horarios”, guarda los cambios del metabox y luego usa “Enviar a GMB”.', 'lealez' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div id="oy-hours-push-action-msg" style="padding:0 14px 10px; font-size:12px; min-height:0; display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza entradas de auditoría de horarios.
     *
     * @param int $post_id
     * @return string
     */
    private function render_hours_log_entries( $post_id ) {
        $post_id = absint( $post_id );
        $entries = get_post_meta( $post_id, 'oy_hours_edit_log', true );
        if ( ! is_array( $entries ) ) {
            $entries = array();
        }

        $job = get_post_meta( $post_id, 'gmb_hours_push_job', true );
        if ( is_array( $job ) && ! empty( $job['history'] ) && is_array( $job['history'] ) ) {
            foreach ( $job['history'] as $job_entry ) {
                if ( ! is_array( $job_entry ) ) {
                    continue;
                }
                $entries[] = array(
                    'at_label' => ! empty( $job_entry['at'] ) ? (string) $job_entry['at'] : '',
                    'event'    => 'job_' . (string) ( $job_entry['event'] ?? 'history' ),
                    'by'       => (string) ( $job_entry['by'] ?? 'system' ),
                    'detail'   => (string) ( $job_entry['detail'] ?? '' ),
                    'context'  => array(),
                );
            }
        }

        if ( empty( $entries ) ) {
            return '<div style="padding:12px 14px; color:#777; font-size:12px;">' . esc_html__( 'Aún no hay ediciones registradas. Usa “Sincronizar Horario desde GMB”, guarda cambios locales o envía a GMB.', 'lealez' ) . '</div>';
        }

        usort( $entries, function( $a, $b ) {
            $ats = (int) ( $a['at_ts'] ?? 0 );
            $bts = (int) ( $b['at_ts'] ?? 0 );
            if ( $ats === $bts ) {
                return 0;
            }
            return ( $ats > $bts ) ? -1 : 1;
        } );

        $entries = array_slice( $entries, 0, 30 );

        ob_start();
        ?>
        <div style="padding:10px 14px;">
            <?php foreach ( $entries as $entry ) :
                $event   = (string) ( $entry['event'] ?? 'log' );
                $detail  = (string) ( $entry['detail'] ?? '' );
                $by      = (string) ( $entry['by'] ?? 'system' );
                $label   = (string) ( $entry['at_label'] ?? ( $entry['at'] ?? '' ) );
                $context = isset( $entry['context'] ) ? $entry['context'] : array();
                ?>
                <div style="border-bottom:1px solid #f0f0f0; padding:8px 0;">
                    <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start;">
                        <strong style="font-size:12px; color:#1f2937;"><?php echo esc_html( $event ); ?></strong>
                        <span style="font-size:11px; color:#777; white-space:nowrap;"><?php echo esc_html( $label ); ?></span>
                    </div>
                    <div style="font-size:12px; color:#444; margin-top:3px;"><?php echo esc_html( $detail ); ?></div>
                    <div style="font-size:11px; color:#777; margin-top:2px;">
                        <?php printf( esc_html__( 'Usuario: %s', 'lealez' ), esc_html( $by ) ); ?>
                    </div>
                    <?php if ( ! empty( $context ) ) : ?>
                        <details style="margin-top:6px;">
                            <summary style="font-size:11px; color:#2271b1; cursor:pointer;"><?php _e( 'Ver entrada/salida técnica', 'lealez' ); ?></summary>
                            <pre style="white-space:pre-wrap; max-height:260px; overflow:auto; background:#f6f7f7; border:1px solid #e5e7eb; padding:8px; font-size:11px; margin:6px 0 0;"> <?php echo esc_html( wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Agrega una entrada persistente al log de horarios.
     */
    private function append_hours_history( $post_id, $event, $detail, array $context = array() ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        $entries = get_post_meta( $post_id, 'oy_hours_edit_log', true );
        if ( ! is_array( $entries ) ) {
            $entries = array();
        }

        $now_ts = current_time( 'timestamp' );
        $user   = wp_get_current_user();
        $by     = ( $user instanceof WP_User && ! empty( $user->user_login ) ) ? $user->user_login : 'system';

        $entries[] = array(
            'at'       => gmdate( 'Y-m-d\TH:i:s\Z', $now_ts ),
            'at_ts'    => $now_ts,
            'at_label' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $now_ts ),
            'by'       => $by,
            'event'    => sanitize_key( (string) $event ),
            'detail'   => wp_strip_all_tags( (string) $detail ),
            'context'  => $this->sanitize_log_context( $context ),
        );

        if ( count( $entries ) > 80 ) {
            $entries = array_slice( $entries, -80 );
        }

        update_post_meta( $post_id, 'oy_hours_edit_log', $entries );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[OY Hours Log] post_id=' . $post_id . ' event=' . $event . ' detail=' . $detail . ' context=' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        }
    }

    /**
     * Limpia recursivamente contexto antes de guardarlo en el log.
     */
    private function sanitize_log_context( $value ) {
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $key => $item ) {
                $safe_key = is_string( $key ) ? sanitize_key( $key ) : $key;
                $out[ $safe_key ] = $this->sanitize_log_context( $item );
            }
            return $out;
        }

        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
            return $value;
        }

        return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
    }

    /**
     * Construye payload GMB desde post_meta local.
     *
     * @param int $post_id
     * @return array|WP_Error
     */
    private function build_gmb_hours_payload_from_meta( $post_id ) {
        $post_id      = absint( $post_id );
        $hours_status = (string) get_post_meta( $post_id, 'location_hours_status', true );
        if ( '' === $hours_status ) {
            $hours_status = 'open_with_hours';
        }

        $open_status_map = array(
            'open_with_hours'    => 'OPEN',
            'open_without_hours' => 'OPEN',
            'temporarily_closed' => 'CLOSED_TEMPORARILY',
            'permanently_closed' => 'CLOSED_PERMANENTLY',
        );

        if ( ! isset( $open_status_map[ $hours_status ] ) ) {
            return new WP_Error( 'invalid_hours_status', __( 'El estado de horarios local no es válido.', 'lealez' ) );
        }

        $payload = array(
            'openInfo' => array(
                'status' => $open_status_map[ $hours_status ],
            ),
        );

        if ( 'open_with_hours' === $hours_status ) {
            $regular_periods = $this->build_gmb_regular_periods_from_meta( $post_id );
            if ( empty( $regular_periods ) ) {
                return new WP_Error( 'empty_regular_hours', __( 'No hay periodos de horario regular para enviar. Marca días abiertos o usa “Abierto, sin horarios de atención”.', 'lealez' ) );
            }
            $payload['regularHours'] = array(
                'periods' => $regular_periods,
            );
        } elseif ( 'open_without_hours' === $hours_status ) {
            $payload['regularHours'] = array();
        }

        $special_periods = $this->build_gmb_special_periods_from_meta( $post_id );
        $payload['specialHours'] = ! empty( $special_periods )
            ? array( 'specialHourPeriods' => $special_periods )
            : array();

        return $payload;
    }

    private function build_gmb_regular_periods_from_meta( $post_id ) {
        $day_map = array(
            'monday'    => 'MONDAY',
            'tuesday'   => 'TUESDAY',
            'wednesday' => 'WEDNESDAY',
            'thursday'  => 'THURSDAY',
            'friday'    => 'FRIDAY',
            'saturday'  => 'SATURDAY',
            'sunday'    => 'SUNDAY',
        );
        $day_keys = array_keys( $day_map );
        $out      = array();

        foreach ( $day_keys as $index => $day_key ) {
            $hours = get_post_meta( $post_id, 'location_hours_' . $day_key, true );
            if ( ! is_array( $hours ) || ! empty( $hours['closed'] ) ) {
                continue;
            }

            $periods = isset( $hours['periods'] ) && is_array( $hours['periods'] ) ? $hours['periods'] : array();
            if ( ! empty( $hours['all_day'] ) ) {
                $periods = array( array( 'open' => '24_hours', 'close' => '' ) );
            }

            foreach ( $periods as $period ) {
                if ( ! is_array( $period ) ) {
                    continue;
                }

                $open = isset( $period['open'] ) ? (string) $period['open'] : '09:00';
                $close = isset( $period['close'] ) ? (string) $period['close'] : '18:00';

                if ( '24_hours' === $open ) {
                    $next_day_key = $day_keys[ ( $index + 1 ) % 7 ];
                    $out[] = array(
                        'openDay'   => $day_map[ $day_key ],
                        'openTime'  => array( 'hours' => 0, 'minutes' => 0 ),
                        'closeDay'  => $day_map[ $next_day_key ],
                        'closeTime' => array( 'hours' => 0, 'minutes' => 0 ),
                    );
                    continue;
                }

                if ( ! $this->is_valid_ui_time( $open ) || ! $this->is_valid_ui_time( $close ) ) {
                    continue;
                }

                $open_parts  = $this->ui_time_to_gmb_time( $open );
                $close_parts = $this->ui_time_to_gmb_time( $close );
                $close_day   = $day_map[ $day_key ];

                if ( $this->time_minutes( $close ) <= $this->time_minutes( $open ) ) {
                    $next_day_key = $day_keys[ ( $index + 1 ) % 7 ];
                    $close_day    = $day_map[ $next_day_key ];
                }

                $out[] = array(
                    'openDay'   => $day_map[ $day_key ],
                    'openTime'  => $open_parts,
                    'closeDay'  => $close_day,
                    'closeTime' => $close_parts,
                );
            }
        }

        return $out;
    }

    private function build_gmb_special_periods_from_meta( $post_id ) {
        $rows = get_post_meta( $post_id, 'location_special_hours', true );
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return array();
        }

        $rows = $this->normalize_special_hours_rows( $rows );
        $out  = array();

        foreach ( $rows as $row ) {
            $date = isset( $row['date'] ) ? (string) $row['date'] : '';
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                continue;
            }
            list( $year, $month, $day ) = array_map( 'intval', explode( '-', $date ) );
            $date_obj = array( 'year' => $year, 'month' => $month, 'day' => $day );

            if ( ! empty( $row['closed'] ) ) {
                $out[] = array(
                    'startDate' => $date_obj,
                    'endDate'   => $date_obj,
                    'closed'    => true,
                );
                continue;
            }

            $open  = isset( $row['open'] ) ? (string) $row['open'] : '09:00';
            $close = isset( $row['close'] ) ? (string) $row['close'] : '18:00';

            if ( '24_hours' === $open ) {
                $out[] = array(
                    'startDate' => $date_obj,
                    'endDate'   => $date_obj,
                    'openTime'  => array( 'hours' => 0, 'minutes' => 0 ),
                    'closeTime' => array( 'hours' => 24, 'minutes' => 0 ),
                );
                continue;
            }

            if ( ! $this->is_valid_ui_time( $open ) || ! $this->is_valid_ui_time( $close ) ) {
                continue;
            }

            $out[] = array(
                'startDate' => $date_obj,
                'endDate'   => $date_obj,
                'openTime'  => $this->ui_time_to_gmb_time( $open ),
                'closeTime' => $this->ui_time_to_gmb_time( $close ),
            );
        }

        return $out;
    }

    private function normalize_special_hours_rows( $rows ) {
        if ( ! is_array( $rows ) ) {
            return array();
        }

        $special_clean = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $date = isset( $row['date'] ) ? sanitize_text_field( (string) $row['date'] ) : '';
            $date = trim( $date );
            if ( '' === $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                continue;
            }

            $closed = ! empty( $row['closed'] ) ? 1 : 0;
            $open   = isset( $row['open'] ) ? sanitize_text_field( (string) $row['open'] ) : '09:00';
            $close  = isset( $row['close'] ) ? sanitize_text_field( (string) $row['close'] ) : '18:00';

            if ( $closed ) {
                $open  = '09:00';
                $close = '18:00';
            } elseif ( '24_hours' !== $open ) {
                if ( ! $this->is_valid_ui_time( $open ) ) {
                    $open = '09:00';
                }
                if ( ! $this->is_valid_ui_time( $close ) ) {
                    $close = '18:00';
                }
            } else {
                $close = '';
            }

            $special_clean[] = array(
                'date'   => $date,
                'closed' => $closed,
                'open'   => $open,
                'close'  => $close,
            );
        }

        usort( $special_clean, function( $a, $b ) {
            return strcmp( (string) $a['date'], (string) $b['date'] );
        } );

        return $special_clean;
    }

    private function is_valid_ui_time( $value ) {
        return is_string( $value ) && preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value );
    }

    private function ui_time_to_gmb_time( $value ) {
        if ( ! $this->is_valid_ui_time( $value ) ) {
            return array( 'hours' => 0, 'minutes' => 0 );
        }
        list( $h, $m ) = array_map( 'intval', explode( ':', $value ) );
        return array( 'hours' => $h, 'minutes' => $m );
    }

    private function time_minutes( $value ) {
        if ( ! $this->is_valid_ui_time( $value ) ) {
            return 0;
        }
        list( $h, $m ) = array_map( 'intval', explode( ':', $value ) );
        return ( $h * 60 ) + $m;
    }

    private function build_closed_daily_meta() {
        $out = array();
        foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $dk ) {
            $out[ $dk ] = array(
                'closed'  => true,
                'all_day' => false,
                'periods' => array( array( 'open' => '09:00', 'close' => '18:00' ) ),
                'open'    => '09:00',
                'close'   => '18:00',
            );
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados de mapeo GMB → meta (horario normal)
    // ─────────────────────────────────────────────────────────────────────────

    private function map_gmb_regular_hours_to_daily_meta( $regular ) {
        if ( ! is_array( $regular ) ) {
            return array();
        }

        $periods = isset( $regular['periods'] ) && is_array( $regular['periods'] ) ? $regular['periods'] : array();
        if ( empty( $periods ) ) {
            return array();
        }

        $day_order = array(
            'MONDAY' => 0, 'TUESDAY' => 1, 'WEDNESDAY' => 2, 'THURSDAY' => 3,
            'FRIDAY' => 4, 'SATURDAY' => 5, 'SUNDAY'   => 6,
        );
        $day_map = array(
            'MONDAY'    => 'monday',    'TUESDAY'   => 'tuesday',   'WEDNESDAY' => 'wednesday',
            'THURSDAY'  => 'thursday',  'FRIDAY'    => 'friday',    'SATURDAY'  => 'saturday',
            'SUNDAY'    => 'sunday',
        );

        $periods_by_day = array();

        foreach ( $periods as $p ) {
            if ( ! is_array( $p ) ) { continue; }

            $open_day_raw = isset( $p['openDay'] ) ? strtoupper( (string) $p['openDay'] ) : '';
            if ( ! $open_day_raw || ! isset( $day_map[ $open_day_raw ] ) ) { continue; }

            $day_key = $day_map[ $open_day_raw ];

            $open_time  = ( isset( $p['openTime'] ) && is_array( $p['openTime'] ) ) ? $p['openTime'] : array();
            $close_time = ( isset( $p['closeTime'] ) && is_array( $p['closeTime'] ) ) ? $p['closeTime'] : null;

            $open_h  = isset( $open_time['hours'] ) ? (int) $open_time['hours'] : 0;
            $open_m  = isset( $open_time['minutes'] ) ? (int) $open_time['minutes'] : 0;

            $close_h = ( null !== $close_time && isset( $close_time['hours'] ) ) ? (int) $close_time['hours'] : 0;
            $close_m = ( null !== $close_time && isset( $close_time['minutes'] ) ) ? (int) $close_time['minutes'] : 0;

            $close_day_raw = isset( $p['closeDay'] ) ? strtoupper( (string) $p['closeDay'] ) : $open_day_raw;
            $open_day_idx  = isset( $day_order[ $open_day_raw ] )  ? $day_order[ $open_day_raw ]  : -1;
            $close_day_idx = isset( $day_order[ $close_day_raw ] ) ? $day_order[ $close_day_raw ] : -1;

            $is_next_day   = ( $close_day_idx >= 0 && $open_day_idx >= 0 )
                             && ( $close_day_idx === ( ( $open_day_idx + 1 ) % 7 ) );

            $is_24h = false;
            if ( $open_h === 0 && $open_m === 0 && $close_h === 0 && $close_m === 0 && $is_next_day ) { $is_24h = true; }
            if ( ! $is_24h && null !== $close_time && $close_h === 24 && $open_h === 0 && $open_m === 0 ) { $is_24h = true; }
            if ( ! $is_24h && null === $close_time && $open_h === 0 && $open_m === 0 ) { $is_24h = true; }

            if ( ! $is_24h && $close_h === 24 ) { $close_h = 0; $close_m = 0; }

            if ( $is_24h ) {
                $periods_by_day[ $day_key ] = array( 'is_24h' => true, 'periods' => array() );
            } else {
                if ( ! isset( $periods_by_day[ $day_key ] ) ) {
                    $periods_by_day[ $day_key ] = array( 'is_24h' => false, 'periods' => array() );
                }
                if ( ! $periods_by_day[ $day_key ]['is_24h'] ) {
                    $periods_by_day[ $day_key ]['periods'][] = array(
                        'open'  => sprintf( '%02d:%02d', $open_h, $open_m ),
                        'close' => sprintf( '%02d:%02d', $close_h, $close_m ),
                    );
                }
            }
        }

        $out = array();
        foreach ( $periods_by_day as $day_key => $day_data ) {
            if ( $day_data['is_24h'] ) {
                $out[ $day_key ] = array(
                    'closed'  => false, 'all_day' => true,
                    'periods' => array( array( 'open' => '24_hours', 'close' => '' ) ),
                    'open'    => '24_hours', 'close' => '',
                );
            } else {
                $day_periods = $day_data['periods'];
                $first_open  = isset( $day_periods[0]['open'] ) ? $day_periods[0]['open'] : '09:00';
                $first_close = isset( $day_periods[0]['close'] ) ? $day_periods[0]['close'] : '18:00';
                $out[ $day_key ] = array(
                    'closed'  => false, 'all_day' => false,
                    'periods' => $day_periods,
                    'open'    => $first_open, 'close' => $first_close,
                );
            }
        }

        $all_days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        foreach ( $all_days as $dk ) {
            if ( ! isset( $out[ $dk ] ) ) {
                $out[ $dk ] = array(
                    'closed'  => true, 'all_day' => false,
                    'periods' => array( array( 'open' => '09:00', 'close' => '18:00' ) ),
                    'open'    => '09:00', 'close' => '18:00',
                );
            }
        }

        return $out;
    }

    private function build_always_open_daily_meta() {
        $out  = array();
        $days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        foreach ( $days as $dk ) {
            $out[ $dk ] = array(
                'closed'  => false,
                'all_day' => true,
                'periods' => array( array( 'open' => '24_hours', 'close' => '' ) ),
                'open'    => '24_hours',
                'close'   => '',
            );
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados de mapeo GMB → meta (HORARIO ESPECIAL)  ✅ NUEVO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Mapea specialHours.specialHourPeriods de GMB a una lista plana por fecha para UI.
     *
     * Retorna: [
     *   ['date'=>'YYYY-MM-DD','closed'=>0|1,'open'=>'HH:MM','close'=>'HH:MM'],
     *   ...
     * ]
     *
     * Soporta:
     * - periodos por una fecha
     * - periodos por rango (startDate/endDate) → se expanden día a día
     * - closed=true
     * - closeTime.hours=24 → normaliza a 00:00
     */
    private function map_gmb_special_hours_to_meta( $special_raw ) {
        if ( ! is_array( $special_raw ) ) {
            return array();
        }

        $periods = array();
        if ( isset( $special_raw['specialHourPeriods'] ) && is_array( $special_raw['specialHourPeriods'] ) ) {
            $periods = $special_raw['specialHourPeriods'];
        } elseif ( isset( $special_raw['periods'] ) && is_array( $special_raw['periods'] ) ) {
            // Compatibilidad si algún wrapper devuelve "periods"
            $periods = $special_raw['periods'];
        }

        if ( empty( $periods ) ) {
            return array();
        }

        $out = array();

        foreach ( $periods as $p ) {
            if ( ! is_array( $p ) ) {
                continue;
            }

            $is_closed = ! empty( $p['closed'] );

            // Fecha inicio/fin (puede ser 1 día o rango)
            $start = isset( $p['startDate'] ) && is_array( $p['startDate'] ) ? $p['startDate'] : null;
            $end   = isset( $p['endDate'] )   && is_array( $p['endDate'] )   ? $p['endDate']   : null;

            if ( ! $start ) {
                continue;
            }

            $start_str = $this->gmb_date_to_ymd( $start );
            if ( '' === $start_str ) {
                continue;
            }

            $end_str = $start_str;
            if ( $end ) {
                $tmp = $this->gmb_date_to_ymd( $end );
                if ( '' !== $tmp ) {
                    $end_str = $tmp;
                }
            }

            // open/close
            $open = '09:00';
            $close = '18:00';

            if ( ! $is_closed ) {
                $open_time  = isset( $p['openTime'] )  && is_array( $p['openTime'] )  ? $p['openTime']  : array();
                $close_time = isset( $p['closeTime'] ) && is_array( $p['closeTime'] ) ? $p['closeTime'] : null;

                $oh = isset( $open_time['hours'] ) ? (int) $open_time['hours'] : 9;
                $om = isset( $open_time['minutes'] ) ? (int) $open_time['minutes'] : 0;

                $ch = ( null !== $close_time && isset( $close_time['hours'] ) ) ? (int) $close_time['hours'] : 18;
                $cm = ( null !== $close_time && isset( $close_time['minutes'] ) ) ? (int) $close_time['minutes'] : 0;

                // Normalizar closeTime.hours = 24 → 00:00
                if ( 24 === $ch ) {
                    $ch = 0;
                    $cm = 0;
                }

                // Caso raro: si open=00:00 y close=00:00 sin closed → interpretar 24h (UI lo soporta)
                if ( 0 === $oh && 0 === $om && 0 === $ch && 0 === $cm && null !== $close_time ) {
                    $open  = '24_hours';
                    $close = '';
                } else {
                    $open  = sprintf( '%02d:%02d', $oh, $om );
                    $close = sprintf( '%02d:%02d', $ch, $cm );
                }
            }

            // Expandir rango start→end (inclusive)
            $dates = $this->expand_date_range_ymd( $start_str, $end_str );
            foreach ( $dates as $ymd ) {
                $out[] = array(
                    'date'   => $ymd,
                    'closed' => $is_closed ? 1 : 0,
                    'open'   => $open,
                    'close'  => $close,
                );
            }
        }

        // De-duplicar por fecha (si Google manda repetidos, gana el último)
        $by_date = array();
        foreach ( $out as $row ) {
            if ( ! is_array( $row ) || empty( $row['date'] ) ) { continue; }
            $by_date[ (string) $row['date'] ] = $row;
        }

        $out = array_values( $by_date );

        // Ordenar por fecha ascendente
        usort( $out, function( $a, $b ) {
            return strcmp( (string) $a['date'], (string) $b['date'] );
        } );

        return $out;
    }

    /**
     * Convierte un objeto Date de GMB {year,month,day} a YYYY-MM-DD
     */
    private function gmb_date_to_ymd( $date_obj ) {
        if ( ! is_array( $date_obj ) ) {
            return '';
        }
        $y = isset( $date_obj['year'] ) ? (int) $date_obj['year'] : 0;
        $m = isset( $date_obj['month'] ) ? (int) $date_obj['month'] : 0;
        $d = isset( $date_obj['day'] ) ? (int) $date_obj['day'] : 0;
        if ( $y <= 0 || $m <= 0 || $d <= 0 ) {
            return '';
        }
        return sprintf( '%04d-%02d-%02d', $y, $m, $d );
    }

    /**
     * Expande rango YYYY-MM-DD inclusive.
     */
    private function expand_date_range_ymd( $start_ymd, $end_ymd ) {
        $start_ymd = (string) $start_ymd;
        $end_ymd   = (string) $end_ymd;

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_ymd ) ) {
            return array();
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_ymd ) ) {
            $end_ymd = $start_ymd;
        }

        try {
            $start = new DateTime( $start_ymd );
            $end   = new DateTime( $end_ymd );
        } catch ( Exception $e ) {
            return array( $start_ymd );
        }

        if ( $end < $start ) {
            $end = $start;
        }

        $out = array();
        $cur = clone $start;
        while ( $cur <= $end ) {
            $out[] = $cur->format( 'Y-m-d' );
            $cur->modify( '+1 day' );
            // Seguridad: evitar loops absurdos si algo viene mal
            if ( count( $out ) > 400 ) {
                break;
            }
        }
        return $out;
    }
}
