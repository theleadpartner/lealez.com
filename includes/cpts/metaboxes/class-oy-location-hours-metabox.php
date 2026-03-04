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
 *
 * Gestiona el metabox "Horarios de Atención" de forma completamente independiente
 * del archivo principal class-oy-location-cpt.php.
 *
 * Responsabilidades:
 *  - Registra el metabox en la columna normal.
 *  - Renderiza el formulario de horarios (timezone + status + grilla multi-período).
 *  - Guarda los campos al hacer "Actualizar" (hook save_post_oy_location, prioridad 25).
 *  - Expone el endpoint AJAX oy_sync_location_hours_from_gmb para el botón propio
 *    de sincronización, sin depender del botón "Importar Ahora" del metabox GMB.
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
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Registro del metabox
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Registra el metabox en WordPress.
     *
     * El ID 'oy_location_hours' mantiene el mismo que tenía en el CPT principal
     * para preservar la posición guardada por el usuario en el admin.
     */
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

    /**
     * Renderiza el metabox completo de horarios.
     *
     * @param WP_Post $post
     */
    public function render_metabox( $post ) {

        // Nonce propio de este metabox (para form save)
        wp_nonce_field( $this->nonce_action, $this->nonce_name );

        // Nonce AJAX para el botón de sincronización
        $ajax_sync_nonce = wp_create_nonce( $this->ajax_nonce_action );

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

        $last_sync_label = '';
        if ( 'hours_metabox_button' === $last_sync_source && ! empty( $last_sync_at ) ) {
            $last_sync_label = sprintf(
                /* translators: %s: datetime */
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
                closesAt:     '<?php echo esc_js( __( 'Cierra a la(s)', 'lealez' ) ); ?>'
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

            // ── Helper: construir un nuevo row de período ───────────────────
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

            // ── Checkbox "Cerrada" ───────────────────────────────────────────
            $(document).on('change', '.oy-hours-closed-cb', function() {
                var day    = $(this).data('day');
                var closed = $(this).is(':checked');
                $('.oy-day-periods[data-day="' + day + '"]').css('opacity', closed ? 0.5 : 1).find('select').prop('disabled', closed);
            });

            // ── Agregar período ──────────────────────────────────────────────
            $(document).on('click', '.oy-add-period', function() {
                var dayKey   = $(this).data('day');
                var $periods = $('.oy-day-periods[data-day="' + dayKey + '"]');
                var newIdx   = $periods.find('.oy-period-row').length;
                $periods.append(buildPeriodRow(dayKey, newIdx, '09:00', '18:00', false));
                reindexPeriods(dayKey);
            });

            // ── Eliminar período ─────────────────────────────────────────────
            $(document).on('click', '.oy-remove-period', function() {
                var dayKey = $(this).data('day');
                $(this).closest('.oy-period-row').remove();
                reindexPeriods(dayKey);
            });

            // ── Select "Abre a la(s)" = 24h → deshabilitar cierre ───────────
            $(document).on('change', '.oy-period-open', function() {
                var isAllDay = $(this).val() === '24_hours';
                var $row = $(this).closest('.oy-period-row');
                $row.find('.oy-period-close').prop('disabled', isAllDay);
                if (isAllDay) { $row.find('.oy-period-close').val(''); }
                $row.find('.oy-add-period')[ isAllDay ? 'hide' : 'show' ]();
            });

            // ── Exponer helpers a window para que applyLocationToForm (GMB metabox)
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
            // BOTÓN: Sincronizar Horario desde GMB
            // ─────────────────────────────────────────────────────────────────

            var _dayOrder = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];

            $('#oy-hours-sync-btn').on('click', function() {
                var $btn   = $(this);
                var $msg   = $('#oy-hours-sync-msg');
                var $last  = $('#oy-hours-sync-lastinfo');
                var postId = $('#post_ID').val();
                var bizId  = $('#parent_business_id').val();
                var locName= $('#gmb_location_name').val();

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
                        nonce:         '<?php echo esc_js( $ajax_sync_nonce ); ?>',
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

                        if (d.synced_at) {
                            $last.text('<?php echo esc_js( __( 'Última sincronización (botón Horarios): ', 'lealez' ) ); ?>' + d.synced_at);
                        } else {
                            $last.text('<?php echo esc_js( __( 'Última sincronización (botón Horarios): (sin timestamp)', 'lealez' ) ); ?>');
                        }

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

    /**
     * Guarda los campos de horario cuando el usuario publica o actualiza el post.
     *
     * @param int     $post_id
     * @param WP_Post $post
     */
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

        if ( isset( $_POST['location_hours_timezone'] ) ) {
            update_post_meta( $post_id, 'location_hours_timezone', sanitize_text_field( wp_unslash( $_POST['location_hours_timezone'] ) ) );
        }

        $valid_hours_statuses = array( 'open_with_hours', 'open_without_hours', 'temporarily_closed', 'permanently_closed' );
        if ( isset( $_POST['location_hours_status'] ) ) {
            $hours_status_raw = sanitize_text_field( wp_unslash( $_POST['location_hours_status'] ) );
            if ( in_array( $hours_status_raw, $valid_hours_statuses, true ) ) {
                update_post_meta( $post_id, 'location_hours_status', $hours_status_raw );
            }
        }

        $days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        foreach ( $days as $day ) {
            if ( ! isset( $_POST[ 'location_hours_' . $day ] ) ) {
                continue;
            }

            $raw    = wp_unslash( $_POST[ 'location_hours_' . $day ] );
            $closed = ! empty( $raw['closed'] );

            $periods_raw   = isset( $raw['periods'] ) && is_array( $raw['periods'] ) ? $raw['periods'] : array();
            $periods_clean = array();
            $is_all_day    = false;

            foreach ( $periods_raw as $praw ) {
                if ( ! is_array( $praw ) ) { continue; }
                $popen  = sanitize_text_field( isset( $praw['open'] )  ? $praw['open']  : '09:00' );
                $pclose = sanitize_text_field( isset( $praw['close'] ) ? $praw['close'] : '18:00' );

                if ( $popen === '24_hours' ) {
                    $is_all_day    = true;
                    $pclose        = '';
                    $periods_clean = array( array( 'open' => '24_hours', 'close' => '' ) );
                    break;
                }
                if ( empty( $popen ) ) { continue; }
                $periods_clean[] = array( 'open' => $popen, 'close' => $pclose );
            }

            if ( empty( $periods_clean ) ) {
                $periods_clean = array( array( 'open' => '09:00', 'close' => '18:00' ) );
            }

            $first_open  = isset( $periods_clean[0]['open'] )  ? $periods_clean[0]['open']  : '09:00';
            $first_close = isset( $periods_clean[0]['close'] ) ? $periods_clean[0]['close'] : '18:00';

            $hours_data = array(
                'closed'  => $closed,
                'all_day' => $is_all_day,
                'periods' => $periods_clean,
                'open'    => $is_all_day ? '24_hours' : $first_open,
                'close'   => $is_all_day ? '' : $first_close,
            );

            update_post_meta( $post_id, 'location_hours_' . $day, $hours_data );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: Sincronizar horarios desde GMB (botón propio del metabox)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Handler AJAX para el botón "Sincronizar Horario desde GMB".
     */
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

        $data = Lealez_GMB_API::sync_location_data( $business_id, $location_name );

        if ( is_wp_error( $data ) ) {
            wp_send_json_error( array( 'message' => $data->get_error_message() ) );
        }

        if ( ! is_array( $data ) ) {
            wp_send_json_error( array( 'message' => __( 'No se pudo obtener información de la ubicación desde Google.', 'lealez' ) ) );
        }

        $regular       = ( isset( $data['regularHours'] ) && is_array( $data['regularHours'] ) ) ? $data['regularHours'] : array();
        $open_info_raw = ( isset( $data['openInfo'] ) && is_array( $data['openInfo'] ) ) ? $data['openInfo'] : array();

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
        }

        $all_days      = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        $days_response = array();

        foreach ( $all_days as $dk ) {
            if ( isset( $days_meta[ $dk ] ) ) {
                update_post_meta( $post_id, 'location_hours_' . $dk, $days_meta[ $dk ] );
                $days_response[ $dk ] = $days_meta[ $dk ];
            }
        }

        if ( ! empty( $open_info_raw ) ) {
            update_post_meta( $post_id, 'gmb_open_info_raw', $open_info_raw );
        }
        update_post_meta( $post_id, 'location_hours_status', $hours_status );

        $synced_at = current_time( 'mysql' );
        update_post_meta( $post_id, 'oy_hours_last_sync_source', 'hours_metabox_button' );
        update_post_meta( $post_id, 'oy_hours_last_sync_at', $synced_at );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[OY Hours Sync] post_id=' . $post_id . ' | hours_status=' . $hours_status . ' | days_synced=' . count( $days_response ) . ' | synced_at=' . $synced_at );
        }

        wp_send_json_success( array(
            'hours_status' => $hours_status,
            'days'         => $days_response,
            'synced_at'    => $synced_at,
            'message'      => __( 'Horarios sincronizados correctamente.', 'lealez' ),
        ) );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados de mapeo GMB → meta
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
}
