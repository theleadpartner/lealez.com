<?php
/**
 * OY Location Address & Geolocation Metabox
 *
 * Externalized metabox for "Dirección y Geolocalización" to keep CPT file smaller.
 *
 * @package Lealez
 * @subpackage CPTs/Metaboxes
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'OY_Location_Address_Metabox' ) ) {

    class OY_Location_Address_Metabox {

        /**
         * Post type slug
         *
         * @var string
         */
        private $post_type = 'oy_location';

        /**
         * Constructor
         */
        public function __construct() {
            add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );

            // ✅ Guardado autocontenido del campo "Áreas de servicio" (JSON → array)
            // Sin tocar el CPT principal.
            add_action( 'save_post_oy_location', array( $this, 'save_meta_box' ), 19, 2 );

            // ── Guardado independiente del metabox de dirección ───────────────────────
            add_action( 'wp_ajax_oy_save_address_metabox', array( $this, 'ajax_save_address_metabox' ) );

            // ── Push de dirección hacia GMB (nueva arquitectura, Playbook v1) ─────────
            add_action( 'wp_ajax_oy_push_address_to_gmb',       array( $this, 'ajax_push_address_to_gmb' ) );
            add_action( 'wp_ajax_oy_check_address_push_status', array( $this, 'ajax_check_address_push_status' ) );

            // ── Assets/footer del editor del metabox ──────────────────────────────────
            add_action( 'admin_footer', array( $this, 'render_address_editor_footer_assets' ) );

            // ── Hook de WP-Cron para el ciclo de polling post-PATCH ───────────────────
            // Registrado aquí Y en el CPT para garantizar que se ejecute aunque
            // el metabox no sea la clase que inicia la request de cron.
            add_action( 'oy_poll_address_push_status', array( 'OY_Location_Address_Metabox', 'cron_poll_address_push_status' ) );
        }

        /**
         * Register metabox
         */
        public function register_metabox() {

            add_meta_box(
                'oy_location_address',
                __( 'Dirección y Geolocalización', 'lealez' ),
                array( $this, 'render_meta_box' ),
                $this->post_type,
                'normal',
                'high'
            );
        }

        /**
         * ✅ Save meta box data (solo lo de Áreas de servicio)
         * - Recibe JSON desde hidden input location_service_areas_json
         * - Guarda array limpio en meta location_service_areas
         *
         * @param int     $post_id
         * @param WP_Post $post
         * @return void
         */
        public function save_meta_box( $post_id, $post ) {

            // Security: autosave / permisos
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }

            if ( ! $post_id || ! is_object( $post ) ) {
                return;
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }

            // Solo nos interesa este campo (si no viene, no borramos nada)
            if ( ! isset( $_POST['location_service_areas_json'] ) ) {
                return;
            }

            $raw = wp_unslash( $_POST['location_service_areas_json'] );
            $raw = is_string( $raw ) ? trim( $raw ) : '';

            $arr = json_decode( $raw, true );
            if ( ! is_array( $arr ) ) {
                $arr = array();
            }

            $clean = array();
            $seen  = array();

            foreach ( $arr as $v ) {
                if ( ! is_string( $v ) ) {
                    continue;
                }
                $s = trim( sanitize_text_field( $v ) );
                if ( '' === $s ) {
                    continue;
                }
                $k = strtolower( $s );
                if ( isset( $seen[ $k ] ) ) {
                    continue;
                }
                $seen[ $k ] = true;
                $clean[]    = $s;
            }

            update_post_meta( $post_id, 'location_service_areas', $clean );
        }

        /**
         * Normaliza un payload de dirección recibido por POST/AJAX.
         *
         * @return array
         */
        private function build_address_payload_from_request() {
            $country = isset( $_POST['location_country'] )
                ? strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['location_country'] ) ), 0, 2 ) )
                : '';

            $service_areas_json = isset( $_POST['location_service_areas_json'] )
                ? wp_unslash( $_POST['location_service_areas_json'] )
                : '[]';

            $service_areas = json_decode( is_string( $service_areas_json ) ? trim( $service_areas_json ) : '[]', true );
            if ( ! is_array( $service_areas ) ) {
                $service_areas = array();
            }

            $clean_service_areas = array();
            $seen_service_areas  = array();

            foreach ( $service_areas as $area ) {
                if ( ! is_string( $area ) ) {
                    continue;
                }

                $area = trim( sanitize_text_field( $area ) );
                if ( '' === $area ) {
                    continue;
                }

                $area_key = strtolower( $area );
                if ( isset( $seen_service_areas[ $area_key ] ) ) {
                    continue;
                }

                $seen_service_areas[ $area_key ] = true;
                $clean_service_areas[]           = $area;
            }

            return array(
                'service_area_only'         => ! empty( $_POST['service_area_only'] ) ? '1' : '0',
                'show_address_to_customers' => ! empty( $_POST['show_address_to_customers'] ) ? '1' : '0',
                'location_address_line1'    => isset( $_POST['location_address_line1'] ) ? sanitize_text_field( wp_unslash( $_POST['location_address_line1'] ) ) : '',
                'location_address_line2'    => isset( $_POST['location_address_line2'] ) ? sanitize_text_field( wp_unslash( $_POST['location_address_line2'] ) ) : '',
                'location_neighborhood'     => isset( $_POST['location_neighborhood'] ) ? sanitize_text_field( wp_unslash( $_POST['location_neighborhood'] ) ) : '',
                'location_city'             => isset( $_POST['location_city'] ) ? sanitize_text_field( wp_unslash( $_POST['location_city'] ) ) : '',
                'location_state'            => isset( $_POST['location_state'] ) ? sanitize_text_field( wp_unslash( $_POST['location_state'] ) ) : '',
                'location_country'          => $country,
                'location_postal_code'      => isset( $_POST['location_postal_code'] ) ? sanitize_text_field( wp_unslash( $_POST['location_postal_code'] ) ) : '',
                'location_latitude'         => isset( $_POST['location_latitude'] ) ? sanitize_text_field( wp_unslash( $_POST['location_latitude'] ) ) : '',
                'location_longitude'        => isset( $_POST['location_longitude'] ) ? sanitize_text_field( wp_unslash( $_POST['location_longitude'] ) ) : '',
                'location_map_url'          => isset( $_POST['location_map_url'] ) ? esc_url_raw( wp_unslash( $_POST['location_map_url'] ) ) : '',
                'location_service_areas'    => $clean_service_areas,
            );
        }

        /**
         * Persiste el payload del metabox de dirección directamente en post meta.
         *
         * @param int    $post_id
         * @param array  $payload
         * @param string $save_source
         * @return array
         */
        private function persist_address_payload( $post_id, array $payload, $save_source = 'manual_metabox_save' ) {
            $post_id     = absint( $post_id );
            $save_source = sanitize_key( (string) $save_source );

            if ( ! $post_id ) {
                return array();
            }

            $scalar_fields = array(
                'location_address_line1',
                'location_address_line2',
                'location_neighborhood',
                'location_city',
                'location_state',
                'location_country',
                'location_postal_code',
                'location_latitude',
                'location_longitude',
                'location_map_url',
            );

            foreach ( $scalar_fields as $meta_key ) {
                $meta_value = isset( $payload[ $meta_key ] ) ? $payload[ $meta_key ] : '';
                update_post_meta( $post_id, $meta_key, $meta_value );
            }

            if ( ! empty( $payload['service_area_only'] ) && '1' === (string) $payload['service_area_only'] ) {
                update_post_meta( $post_id, 'service_area_only', '1' );
            } else {
                delete_post_meta( $post_id, 'service_area_only' );
            }

            if ( ! empty( $payload['show_address_to_customers'] ) && '1' === (string) $payload['show_address_to_customers'] ) {
                update_post_meta( $post_id, 'show_address_to_customers', '1' );
            } else {
                delete_post_meta( $post_id, 'show_address_to_customers' );
            }

            update_post_meta(
                $post_id,
                'location_service_areas',
                isset( $payload['location_service_areas'] ) && is_array( $payload['location_service_areas'] )
                    ? array_values( $payload['location_service_areas'] )
                    : array()
            );

            $now_ts   = current_time( 'timestamp' );
            $user     = wp_get_current_user();
            $by       = ( $user instanceof WP_User && ! empty( $user->user_login ) ) ? $user->user_login : 'system';
            $at_label = date_i18n(
                get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                $now_ts
            );

            $save_meta = array(
                'at'       => gmdate( 'Y-m-d\TH:i:s\Z', $now_ts ),
                'at_ts'    => $now_ts,
                'at_label' => $at_label,
                'by'       => $by,
                'source'   => $save_source,
            );

            update_post_meta( $post_id, 'oy_address_last_manual_save', $save_meta );
            update_post_meta( $post_id, 'oy_address_local_pending_publish', '1' );
            update_post_meta( $post_id, 'date_modified', current_time( 'mysql' ) );
            update_post_meta( $post_id, 'modified_by_user_id', get_current_user_id() );

            $job = get_post_meta( $post_id, 'gmb_address_push_job', true );
            if ( is_array( $job ) ) {
                if ( ! isset( $job['history'] ) || ! is_array( $job['history'] ) ) {
                    $job['history'] = array();
                }

                $job['history'][] = array(
                    'event'  => 'local_metabox_save',
                    'at'     => $save_meta['at'],
                    'at_ts'  => $save_meta['at_ts'],
                    'by'     => $by,
                    'detail' => 'Se guardaron cambios locales del metabox de dirección. Pendiente por publicar en GMB.',
                );

                update_post_meta( $post_id, 'gmb_address_push_job', $job );
            }

            return $save_meta;
        }

        /**
         * Guarda los campos del metabox de dirección sin depender del botón Actualizar del CPT.
         *
         * @return void
         */
        public function ajax_save_address_metabox() {
            $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

            if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_save_address_metabox_' . $post_id ) ) {
                wp_send_json_error( array(
                    'message' => __( 'Nonce inválido o post_id faltante.', 'lealez' ),
                ) );
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array(
                    'message' => __( 'No tienes permisos para guardar esta ubicación.', 'lealez' ),
                ) );
            }

            $post = get_post( $post_id );
            if ( ! $post || 'oy_location' !== $post->post_type ) {
                wp_send_json_error( array(
                    'message' => __( 'El post indicado no es una ubicación válida.', 'lealez' ),
                ) );
            }

            $payload   = $this->build_address_payload_from_request();
            $save_meta = $this->persist_address_payload( $post_id, $payload, 'manual_metabox_save' );

            wp_send_json_success( array(
                'message'               => __( 'Cambios del metabox guardados correctamente. Ya puedes usar "Enviar a GMB" y se publicará exactamente lo que acabas de guardar.', 'lealez' ),
                'saved_at'              => isset( $save_meta['at'] ) ? $save_meta['at'] : '',
                'saved_at_label'        => isset( $save_meta['at_label'] ) ? $save_meta['at_label'] : '',
                'saved_by'              => isset( $save_meta['by'] ) ? $save_meta['by'] : '',
                'panel_html'            => $this->render_address_push_panel( $post_id ),
                'local_pending_publish' => true,
            ) );
        }

        /**
         * Renderiza el JS/CSS del modo Editar / Guardar propio del metabox.
         *
         * @return void
         */
        public function render_address_editor_footer_assets() {
            if ( ! is_admin() ) {
                return;
            }

            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            if ( ! $screen || 'oy_location' !== $screen->post_type ) {
                return;
            }

            if ( ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
                return;
            }

            global $post;
            if ( ! $post || 'oy_location' !== $post->post_type ) {
                return;
            }

            $post_id           = (int) $post->ID;
            $save_nonce        = wp_create_nonce( 'oy_save_address_metabox_' . $post_id );
            $local_pending     = (bool) get_post_meta( $post_id, 'oy_address_local_pending_publish', true );
            $last_manual_save  = get_post_meta( $post_id, 'oy_address_last_manual_save', true );
            $last_manual_label = ( is_array( $last_manual_save ) && ! empty( $last_manual_save['at_label'] ) )
                ? (string) $last_manual_save['at_label']
                : '';
            ?>
            <style id="oy-address-editor-style">
                #oy_location_address .oy-address-editor-bar{
                    display:flex;
                    align-items:center;
                    gap:10px;
                    flex-wrap:wrap;
                    padding:10px 14px;
                    margin:0 0 16px;
                    border:1px solid #dadce0;
                    border-radius:4px;
                    background:#fff;
                }
                #oy_location_address .oy-address-editor-status{
                    font-size:12px;
                    color:#555;
                }
                #oy_location_address .oy-address-readonly{
                    background:#f6f7f7 !important;
                    color:#50575e !important;
                    cursor:not-allowed !important;
                }
                #oy_location_address .oy-address-editor-note{
                    display:block;
                    width:100%;
                    font-size:11px;
                    color:#666;
                }
                #oy_location_address.oy-address-editing-active .oy-address-editor-bar{
                    border-color:#2271b1;
                    background:#f0f6fc;
                }
                #oy_location_address .oy-address-local-pending{
                    display:block;
                    width:100%;
                    margin-top:4px;
                    font-size:11px;
                    color:#1d4ed8;
                }
            </style>
            <script type="text/javascript">
                (function() {
                    var $ = jQuery;
                    var ajaxUrl   = (typeof ajaxurl !== 'undefined') ? ajaxurl : <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
                    var postId    = <?php echo wp_json_encode( (string) $post_id ); ?>;
                    var saveNonce = <?php echo wp_json_encode( $save_nonce ); ?>;
                    var localPending = <?php echo $local_pending ? 'true' : 'false'; ?>;
                    var lastManualLabel = <?php echo wp_json_encode( $last_manual_label ); ?>;

                    var editorState = {
                        enabled: false,
                        dirty: false,
                        saving: false,
                        baseline: null
                    };

                    var FIELD_MAP = [
                        { key: 'service_area_only',         selector: '#service_area_only',           label: 'Sin ubicación física', type: 'checkbox' },
                        { key: 'show_address_to_customers', selector: '#show_address_to_customers',   label: 'Mostrar dirección',   type: 'checkbox' },
                        { key: 'location_address_line1',    selector: '#location_address_line1',      label: 'Dirección Principal' },
                        { key: 'location_address_line2',    selector: '#location_address_line2',      label: 'Complemento' },
                        { key: 'location_neighborhood',     selector: '#location_neighborhood',       label: 'Barrio/Colonia' },
                        { key: 'location_city',             selector: '#location_city',               label: 'Ciudad' },
                        { key: 'location_state',            selector: '#location_state',              label: 'Estado/Dpto' },
                        { key: 'location_country',          selector: '#location_country',            label: 'País (ISO 2)' },
                        { key: 'location_postal_code',      selector: '#location_postal_code',        label: 'Código Postal' },
                        { key: 'location_latitude',         selector: '#location_latitude',           label: 'Latitud' },
                        { key: 'location_longitude',        selector: '#location_longitude',          label: 'Longitud' },
                        { key: 'location_map_url',          selector: '#location_map_url',            label: 'URL Google Maps' },
                        { key: 'location_service_areas',    selector: '#location_service_areas_json', label: 'Áreas de Servicio', type: 'json' }
                    ];

                    function ensureUi() {
                        var $metabox = $('#oy_location_address');
                        if (!$metabox.length) {
                            return;
                        }

                        if ($metabox.find('.oy-address-editor-bar').length) {
                            return;
                        }

                        var barHtml = ''
                            + '<div class="oy-address-editor-bar">'
                            + '  <button type="button" class="button button-primary" id="oy-address-editor-start">Editar dirección</button>'
                            + '  <button type="button" class="button button-primary" id="oy-address-editor-save" style="display:none;">Guardar cambios del metabox</button>'
                            + '  <button type="button" class="button button-secondary" id="oy-address-editor-cancel" style="display:none;">Cancelar edición</button>'
                            + '  <span class="oy-address-editor-status" id="oy-address-editor-status"></span>'
                            + '  <span class="oy-address-editor-note">Los cambios de este metabox NO se guardan con el botón "Actualizar" del CPT. Debes usar "Guardar cambios del metabox".</span>'
                            + (localPending ? '<span class="oy-address-local-pending" id="oy-address-local-pending-note">Hay cambios locales guardados pendientes por publicar en GMB.</span>' : '<span class="oy-address-local-pending" id="oy-address-local-pending-note" style="display:none;"></span>')
                            + '</div>';

                        var $anchor = $('#oy-address-sync-bar');
                        if ($anchor.length) {
                            $(barHtml).insertAfter($anchor);
                        } else {
                            $metabox.find('.inside').prepend(barHtml);
                        }
                    }

                    function parseJsonSafe(raw) {
                        try {
                            var parsed = JSON.parse(raw || '[]');
                            return Array.isArray(parsed) ? parsed : [];
                        } catch (e) {
                            return [];
                        }
                    }

                    function captureState() {
                        var state = {};
                        FIELD_MAP.forEach(function(field) {
                            if (field.type === 'checkbox') {
                                state[field.key] = $(field.selector).is(':checked') ? '1' : '0';
                                return;
                            }

                            if (field.type === 'json') {
                                state[field.key] = parseJsonSafe($(field.selector).val() || '[]');
                                return;
                            }

                            state[field.key] = ($(field.selector).val() || '').toString();
                        });
                        return state;
                    }

                    function statesEqual(a, b) {
                        return JSON.stringify(a || {}) === JSON.stringify(b || {});
                    }

                    function buildDiff(before, after) {
                        var rows = [];

                        FIELD_MAP.forEach(function(field) {
                            var beforeVal = before[field.key];
                            var afterVal  = after[field.key];

                            var beforeText;
                            var afterText;

                            if (field.type === 'checkbox') {
                                beforeText = beforeVal === '1' ? 'Sí' : 'No';
                                afterText  = afterVal === '1' ? 'Sí' : 'No';
                            } else if (field.type === 'json') {
                                beforeText = Array.isArray(beforeVal) ? beforeVal.join(', ') : '';
                                afterText  = Array.isArray(afterVal) ? afterVal.join(', ') : '';
                            } else {
                                beforeText = (beforeVal || '').toString();
                                afterText  = (afterVal || '').toString();
                            }

                            var status = beforeText === afterText ? 'unchanged' : ( beforeText ? 'changed' : 'new' );

                            rows.push({
                                label: field.label,
                                before: beforeText,
                                after: afterText,
                                status: status
                            });
                        });

                        return rows;
                    }

                    function applyState(state) {
                        if (!state) {
                            return;
                        }

                        FIELD_MAP.forEach(function(field) {
                            if (field.type === 'checkbox') {
                                $(field.selector).prop('checked', state[field.key] === '1');
                                return;
                            }

                            if (field.type === 'json') {
                                var jsonValue = Array.isArray(state[field.key]) ? state[field.key] : [];
                                $('#location_service_areas_json').val(JSON.stringify(jsonValue));
                                if (typeof window.oy_service_areas_set === 'function') {
                                    window.oy_service_areas_set(jsonValue);
                                }
                                return;
                            }

                            $(field.selector).val(state[field.key] || '');
                        });

                        if (typeof window.oy_toggle_address_fields === 'function') {
                            window.oy_toggle_address_fields();
                        }
                        if (typeof window.oy_update_map_preview === 'function') {
                            window.oy_update_map_preview();
                        }
                    }

                    function setStatus(message, type) {
                        var colors = {
                            info: '#555',
                            success: '#166534',
                            error: '#dc3232',
                            loading: '#1a73e8'
                        };

                        $('#oy-address-editor-status')
                            .text(message || '')
                            .css('color', colors[type] || '#555');
                    }

                    function setPushStatus(message, type) {
                        var colors = {
                            info: '#555',
                            success: '#166534',
                            error: '#dc3232',
                            loading: '#1a73e8'
                        };

                        $('#oy-push-state-action-msg')
                            .text(message || '')
                            .css({
                                color: colors[type] || '#555',
                                display: message ? 'block' : 'none'
                            });
                    }

                    function updateUiState() {
                        var lock = !editorState.enabled;

                        [
                            '#location_address_line1',
                            '#location_address_line2',
                            '#location_neighborhood',
                            '#location_city',
                            '#location_state',
                            '#location_country',
                            '#location_postal_code',
                            '#location_latitude',
                            '#location_longitude',
                            '#location_map_url',
                            '#oy-service-area-search'
                        ].forEach(function(selector) {
                            $(selector).prop('readonly', lock).toggleClass('oy-address-readonly', lock);
                        });

                        $('#oy_location_address')
                            .toggleClass('oy-address-editing-active', editorState.enabled);

                        $('#oy-address-editor-start').toggle(!editorState.enabled);
                        $('#oy-address-editor-save, #oy-address-editor-cancel').toggle(editorState.enabled);
                        $('#oy-address-editor-save').prop('disabled', !editorState.dirty || editorState.saving);
                    }

                    function refreshDirtyState() {
                        editorState.dirty = !statesEqual(editorState.baseline, captureState());
                        updateUiState();
                    }

                    function beginEditMode() {
                        if (!postId || postId === '0') {
                            setStatus('Guarda primero la ubicación para poder editar este metabox de forma independiente.', 'error');
                            return;
                        }

                        editorState.baseline = captureState();
                        editorState.enabled  = true;
                        editorState.dirty    = false;
                        updateUiState();
                        setStatus('Modo edición activo. Cuando termines, usa "Guardar cambios del metabox".', 'info');
                    }

                    function cancelEditMode() {
                        applyState(editorState.baseline || {});
                        editorState.enabled = false;
                        editorState.dirty   = false;
                        updateUiState();
                        setStatus('Edición cancelada. Se restauró el último estado guardado.', 'info');
                    }

                    function replacePushPanel(html) {
                        if (!html) {
                            return;
                        }

                        var $old = $('#oy-address-push-panel');
                        if (!$old.length) {
                            return;
                        }

                        var $new = $(html);
                        $old.replaceWith($new);
                    }

                    function addMetaboxLogEntry(diffRows, savedAtLabel) {
                        if (!window.oyAddrLogAPI || typeof window.oyAddrLogAPI.addLogEntry !== 'function') {
                            return;
                        }

                        window.oyAddrLogAPI.addLogEntry(
                            {
                                action: 'manual_address_save',
                                saved_at: savedAtLabel || '',
                                message: 'Cambios locales del metabox guardados',
                                pending_publish: true
                            },
                            diffRows,
                            'metabox_save'
                        );
                    }

                    function collectAjaxPayload() {
                        var state = captureState();

                        return {
                            action:                      'oy_save_address_metabox',
                            nonce:                       saveNonce,
                            post_id:                     postId,
                            service_area_only:           state.service_area_only === '1' ? '1' : '',
                            show_address_to_customers:   state.show_address_to_customers === '1' ? '1' : '',
                            location_address_line1:      state.location_address_line1,
                            location_address_line2:      state.location_address_line2,
                            location_neighborhood:       state.location_neighborhood,
                            location_city:               state.location_city,
                            location_state:              state.location_state,
                            location_country:            state.location_country,
                            location_postal_code:        state.location_postal_code,
                            location_latitude:           state.location_latitude,
                            location_longitude:          state.location_longitude,
                            location_map_url:            state.location_map_url,
                            location_service_areas_json: JSON.stringify(state.location_service_areas || [])
                        };
                    }

                    function saveMetabox() {
                        if (!editorState.enabled || editorState.saving) {
                            return;
                        }

                        var before = editorState.baseline || captureState();
                        var after  = captureState();
                        var diff   = buildDiff(before, after);
                        var hasRealChanges = diff.some(function(row) {
                            return row.status === 'changed' || row.status === 'new';
                        });

                        if (!hasRealChanges) {
                            setStatus('No hay cambios para guardar en el metabox.', 'info');
                            return;
                        }

                        editorState.saving = true;
                        updateUiState();
                        setStatus('Guardando cambios del metabox...', 'loading');

                        $.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            timeout: 45000,
                            data: collectAjaxPayload()
                        })
                        .done(function(resp) {
                            if (!resp || !resp.success) {
                                var errorMessage = (resp && resp.data && resp.data.message)
                                    ? resp.data.message
                                    : 'No se pudieron guardar los cambios del metabox.';
                                setStatus(errorMessage, 'error');
                                return;
                            }

                            editorState.baseline = captureState();
                            editorState.enabled  = false;
                            editorState.dirty    = false;
                            localPending = true;

                            updateUiState();
                            setStatus(resp.data && resp.data.message ? resp.data.message : 'Cambios del metabox guardados correctamente.', 'success');

                            if (resp.data && resp.data.panel_html) {
                                replacePushPanel(resp.data.panel_html);
                            }

                            $('#oy-address-local-pending-note')
                                .text('Hay cambios locales guardados pendientes por publicar en GMB.')
                                .show();

                            addMetaboxLogEntry(diff, resp.data && resp.data.saved_at_label ? resp.data.saved_at_label : '');
                        })
                        .fail(function(xhr, status) {
                            var message = (status === 'timeout')
                                ? 'Timeout al guardar el metabox. Intenta de nuevo.'
                                : 'Error de red al guardar el metabox.';
                            setStatus(message, 'error');
                        })
                        .always(function() {
                            editorState.saving = false;
                            updateUiState();
                        });
                    }

                    function blockIfNotEditing(event, message) {
                        if (editorState.enabled) {
                            return false;
                        }

                        event.preventDefault();
                        event.stopPropagation();
                        if (typeof event.stopImmediatePropagation === 'function') {
                            event.stopImmediatePropagation();
                        }

                        setStatus(message, 'error');
                        return true;
                    }

                    function lockCheckboxInteraction() {
                        $(document).on('click.oyAddrEditLock', '#service_area_only, #show_address_to_customers', function(event) {
                            if (blockIfNotEditing(event, 'Activa primero "Editar dirección" para modificar estos campos.')) {
                                return false;
                            }

                            setTimeout(function() {
                                refreshDirtyState();
                            }, 0);
                        });
                    }

                    function lockServiceAreaInteraction() {
                        $(document).on('click.oyAddrEditLock', '#oy-service-area-selected button, #oy-service-area-suggestions > div', function(event) {
                            if (editorState.enabled) {
                                setTimeout(function() {
                                    refreshDirtyState();
                                }, 0);
                                return;
                            }

                            blockIfNotEditing(event, 'Activa primero "Editar dirección" para cambiar las áreas de servicio.');
                        });

                        $(document).on('focus.oyAddrEditLock input.oyAddrEditLock', '#oy-service-area-search', function() {
                            if (!editorState.enabled) {
                                $(this).blur();
                                setStatus('Activa primero "Editar dirección" para cambiar las áreas de servicio.', 'error');
                            }
                        });

                        $(document).on('input.oyAddrEditLock', '#oy-service-area-search', function() {
                            if (!editorState.enabled) {
                                $(this).val('');
                            }
                        });
                    }

                    function bindDirtyWatchers() {
                        $(document).on(
                            'input.oyAddrEdit change.oyAddrEdit',
                            '#location_address_line1, #location_address_line2, #location_neighborhood, #location_city, #location_state, #location_country, #location_postal_code, #location_latitude, #location_longitude, #location_map_url, #location_service_areas_json',
                            function() {
                                if (!editorState.enabled) {
                                    return;
                                }
                                refreshDirtyState();
                            }
                        );
                    }

                    function bindButtons() {
                        $(document).on('click.oyAddrEdit', '#oy-address-editor-start', function(event) {
                            event.preventDefault();
                            beginEditMode();
                        });

                        $(document).on('click.oyAddrEdit', '#oy-address-editor-cancel', function(event) {
                            event.preventDefault();
                            cancelEditMode();
                        });

                        $(document).on('click.oyAddrEdit', '#oy-address-editor-save', function(event) {
                            event.preventDefault();
                            saveMetabox();
                        });
                    }

                    function guardCptSaveButtons() {
                        document.addEventListener('click', function(event) {
                            var saveButton = event.target.closest('#publish, #save-post');
                            if (!saveButton) {
                                return;
                            }

                            if (editorState.enabled && editorState.dirty) {
                                event.preventDefault();
                                event.stopPropagation();
                                if (typeof event.stopImmediatePropagation === 'function') {
                                    event.stopImmediatePropagation();
                                }
                                setStatus('Primero guarda los cambios del metabox de dirección antes de usar "Actualizar" o "Guardar borrador".', 'error');
                            }
                        }, true);
                    }

                    function guardExternalButtons() {
                        document.addEventListener('click', function(event) {
                            var pushButton = event.target.closest('#oy-push-address-btn');
                            if (pushButton && editorState.enabled && editorState.dirty) {
                                event.preventDefault();
                                event.stopPropagation();
                                if (typeof event.stopImmediatePropagation === 'function') {
                                    event.stopImmediatePropagation();
                                }
                                setPushStatus('Primero guarda los cambios del metabox de dirección. "Enviar a GMB" usa únicamente lo que ya quedó guardado.', 'error');
                                setStatus('Primero guarda los cambios del metabox de dirección.', 'error');
                                return;
                            }

                            var syncButton = event.target.closest('#oy-address-sync-btn');
                            if (syncButton && editorState.enabled && editorState.dirty) {
                                event.preventDefault();
                                event.stopPropagation();
                                if (typeof event.stopImmediatePropagation === 'function') {
                                    event.stopImmediatePropagation();
                                }
                                setStatus('Primero guarda o cancela la edición actual antes de sincronizar desde GMB.', 'error');
                            }
                        }, true);
                    }

                    $(document).ready(function() {
                        if (!$('#oy_location_address').length) {
                            return;
                        }

                        ensureUi();
                        editorState.baseline = captureState();
                        updateUiState();

                        if (lastManualLabel) {
                            setStatus('Último guardado local del metabox: ' + lastManualLabel, 'info');
                        } else if (localPending) {
                            setStatus('Hay cambios locales pendientes por publicar en GMB.', 'info');
                        }

                        lockCheckboxInteraction();
                        lockServiceAreaInteraction();
                        bindDirtyWatchers();
                        bindButtons();
                        guardCptSaveButtons();
                        guardExternalButtons();
                    });
                })();
            </script>
            <?php
        }

        /**
         * ✅ Extrae "Áreas de servicio" desde RAW de GMB y devuelve array de strings "humanos"
         *
         * Fuentes:
         * - gmb_service_area_raw (meta)
         * - gmb_location_raw['serviceArea'] (meta)
         *
         * Estructuras que tolera (defensivo):
         * - serviceArea.places[] como strings
         * - serviceArea.places[] como objetos con: name / placeName / placeId / displayName / title / address
         *
         * @param int $post_id
         * @return array
         */
        private function extract_service_areas_from_gmb_raw( $post_id ) {
            $post_id = absint( $post_id );
            if ( ! $post_id ) {
                return array();
            }

            $service_area_raw = get_post_meta( $post_id, 'gmb_service_area_raw', true );

            // Fallback: buscar dentro del Location RAW completo
            if ( empty( $service_area_raw ) || ! is_array( $service_area_raw ) ) {
                $loc_raw = get_post_meta( $post_id, 'gmb_location_raw', true );
                if ( is_array( $loc_raw ) && isset( $loc_raw['serviceArea'] ) && is_array( $loc_raw['serviceArea'] ) ) {
                    $service_area_raw = $loc_raw['serviceArea'];
                }
            }

            if ( empty( $service_area_raw ) || ! is_array( $service_area_raw ) ) {
                return array();
            }

            $places = array();

            // Google suele usar serviceArea.places[]
            if ( isset( $service_area_raw['places'] ) && is_array( $service_area_raw['places'] ) ) {
                $places = $service_area_raw['places'];
            }

            if ( empty( $places ) ) {
                return array();
            }

            $out  = array();
            $seen = array();

            foreach ( $places as $p ) {

                $label = '';

                // Caso 1: string directo
                if ( is_string( $p ) ) {
                    $label = trim( $p );
                }

                // Caso 2: objeto/array
                if ( '' === $label && is_array( $p ) ) {

                    // Prioridades "humanas"
                    $candidates = array(
                        $p['displayName'] ?? '',
                        $p['title'] ?? '',
                        $p['name'] ?? '',
                        $p['placeName'] ?? '',
                        $p['placeId'] ?? '',
                    );

                    foreach ( $candidates as $cand ) {
                        if ( is_string( $cand ) && trim( $cand ) !== '' ) {
                            $label = trim( $cand );
                            break;
                        }
                    }

                    // Si hay address formateado, lo preferimos como fallback "humano"
                    if ( '' === $label && isset( $p['address'] ) ) {
                        if ( is_string( $p['address'] ) ) {
                            $label = trim( $p['address'] );
                        } elseif ( is_array( $p['address'] ) ) {
                            // addressLines + locality + administrativeArea + regionCode
                            $lines = array();
                            if ( ! empty( $p['address']['addressLines'] ) && is_array( $p['address']['addressLines'] ) ) {
                                foreach ( $p['address']['addressLines'] as $ln ) {
                                    if ( is_string( $ln ) && trim( $ln ) !== '' ) {
                                        $lines[] = trim( $ln );
                                    }
                                }
                            }
                            $city  = isset( $p['address']['locality'] ) ? trim( (string) $p['address']['locality'] ) : '';
                            $state = isset( $p['address']['administrativeArea'] ) ? trim( (string) $p['address']['administrativeArea'] ) : '';
                            $cty   = trim( implode( ', ', array_filter( array( $city, $state ) ) ) );
                            if ( $cty ) {
                                $lines[] = $cty;
                            }
                            $label = trim( implode( ' — ', array_filter( $lines ) ) );
                        }
                    }
                }

                $label = is_string( $label ) ? trim( $label ) : '';

                // Limpieza final
                if ( '' === $label ) {
                    continue;
                }

                $label = sanitize_text_field( $label );

                $k = strtolower( $label );
                if ( isset( $seen[ $k ] ) ) {
                    continue;
                }

                $seen[ $k ] = true;
                $out[]      = $label;
            }

            return array_values( $out );
        }

        // =====================================================================
        // PUSH DIRECCIÓN → GMB (arquitectura Playbook v1)
        // =====================================================================

        /**
         * AJAX: Envía la dirección guardada en Lealez hacia GMB.
         *
         * Flujo (Playbook Sección 9):
         * 1. Lee campos de dirección desde post_meta (guardados con el Save del post)
         * 2. GET snapshot pre-PATCH → detecta hasPendingEdits en curso
         * 3. Bloquea si ya hay un job pending_review (Playbook Sección 7)
         * 4. PATCH via Lealez_GMB_API::push_location_address()
         * 5. Guarda job en gmb_address_push_job (post_meta)
         * 6. Programa primer ciclo de polling con WP-Cron a 60 s
         *
         * @return void  Responde con wp_send_json_success / wp_send_json_error
         */
        public function ajax_push_address_to_gmb() {
            $nonce   = isset( $_POST['nonce'] )   ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) )             : 0;

            if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_push_address_gmb_' . $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Nonce inválido o post_id faltante.', 'lealez' ) ) );
            }
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Sin permisos para editar esta ubicación.', 'lealez' ) ) );
            }

            $post = get_post( $post_id );
            if ( ! $post || 'oy_location' !== $post->post_type ) {
                wp_send_json_error( array( 'message' => __( 'Post no válido o no es una oy_location.', 'lealez' ) ) );
            }

            $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );

            if ( ! $business_id || '' === trim( $location_name ) ) {
                wp_send_json_error( array( 'message' => __( 'Esta ubicación no tiene empresa o ubicación GMB vinculada. Vincula primero en el metabox de Integración GMB.', 'lealez' ) ) );
            }

            if ( ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'push_location_address' ) ) {
                wp_send_json_error( array( 'message' => __( 'Lealez_GMB_API::push_location_address no disponible. Actualiza el plugin.', 'lealez' ) ) );
            }

            // ── 1. Leer campos de dirección desde DB ─────────────────────────────────
            $address_line1 = (string) get_post_meta( $post_id, 'location_address_line1', true );
            $address_line2 = (string) get_post_meta( $post_id, 'location_address_line2', true );
            $city          = (string) get_post_meta( $post_id, 'location_city', true );
            $state         = (string) get_post_meta( $post_id, 'location_state', true );
            $country       = strtoupper( trim( (string) get_post_meta( $post_id, 'location_country', true ) ) );
            $postal_code   = (string) get_post_meta( $post_id, 'location_postal_code', true );
            $is_sab        = '1' === (string) get_post_meta( $post_id, 'service_area_only', true );
            $show_address  = ( '' === (string) get_post_meta( $post_id, 'show_address_to_customers', true ) )
                ? true
                : ( '1' === (string) get_post_meta( $post_id, 'show_address_to_customers', true ) );

            // País obligatorio salvo SAB puro (sin dirección)
            if ( '' === $country && ! ( $is_sab && ! $show_address ) ) {
                wp_send_json_error( array( 'message' => __( 'El campo "País (ISO 2)" es obligatorio. Guarda el post primero con el código de país (ej: CO, MX, US).', 'lealez' ) ) );
            }

            // ── 2. GET snapshot pre-PATCH ─────────────────────────────────────────────
            $snapshot = Lealez_GMB_API::get_location_snapshot( $business_id, $location_name );

            if ( is_wp_error( $snapshot ) ) {
                wp_send_json_error( array(
                    'message' => sprintf( __( 'No se pudo obtener estado actual de GMB: %s', 'lealez' ), $snapshot->get_error_message() ),
                ) );
            }

            // ── 3. Bloquear si ya hay un cambio pendiente (Playbook Sección 7) ────────
            // "do not PATCH the same field while hasPendingEdits=true — follow-up edits
            //  are often silently dropped and repeated rapid reverts can trigger listing suspension."
            if ( ! empty( $snapshot['metadata']['hasPendingEdits'] ) ) {
                // Verificar también si el job local ya estaba resuelto antes de bloquear
                $current_job = get_post_meta( $post_id, 'gmb_address_push_job', true );
                $local_resolved = is_array( $current_job ) && in_array( $current_job['status'] ?? '', array( 'applied', 'rejected', 'google_override', 'timeout', 'error' ), true );

                if ( ! $local_resolved ) {
                    wp_send_json_error( array(
                        'message'    => __( 'Google tiene un cambio de dirección en revisión. No se puede enviar otro hasta que se resuelva. Usa el botón "Verificar estado" para actualizarlo.', 'lealez' ),
                        'panel_html' => $this->render_address_push_panel( $post_id ),
                    ) );
                }
            }

            // ── 4. Construir address_data ─────────────────────────────────────────────
            $address_lines = array_values( array_filter(
                array( trim( $address_line1 ), trim( $address_line2 ) ),
                function ( $l ) { return '' !== $l; }
            ) );

            $address_data = array(
                'regionCode'         => $country,
                'addressLines'       => $address_lines,
                'locality'           => trim( $city ),
                'administrativeArea' => trim( $state ),
                'postalCode'         => trim( $postal_code ),
            );

            // ── 5. PATCH → push_location_address ─────────────────────────────────────
            $result = Lealez_GMB_API::push_location_address(
                $business_id,
                $location_name,
                $address_data,
                array( 'is_sab' => $is_sab, 'show_address' => $show_address )
            );

            if ( is_wp_error( $result ) ) {
                $err_data     = $result->get_error_data();
                $violations   = isset( $err_data['field_violations'] ) ? $err_data['field_violations'] : array();
                $viol_txt     = '';
                if ( ! empty( $violations ) ) {
                    foreach ( $violations as $v ) {
                        $viol_txt .= ' | ' . ( $v['field'] ?? '' ) . ': ' . ( $v['description'] ?? '' );
                    }
                }
                $raw_preview = '';
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $err_data['raw_body'] ) ) {
                    $raw_preview = substr( (string) $err_data['raw_body'], 0, 500 );
                }
                $response_arr = array( 'message' => $result->get_error_message() . $viol_txt );
                if ( $raw_preview ) {
                    $response_arr['debug_raw'] = $raw_preview;
                }
                wp_send_json_error( $response_arr );
            }

            // ── 6. Persistir job de push ──────────────────────────────────────────────
            $current_user = wp_get_current_user();
            $user_login   = ( $current_user instanceof WP_User && $current_user->user_login )
                ? $current_user->user_login : 'system';
            $now_ts  = time();
            $now_iso = gmdate( 'Y-m-d\\TH:i:s\\Z', $now_ts );

            $job = array(
                'status'          => 'pending_review',
                'pushed_at'       => $now_iso,
                'pushed_at_ts'    => $now_ts,
                'pushed_by'       => $user_login,
                'update_mask'     => $result['update_mask'],
                'business_type'   => $result['business_type'],
                'is_sab'          => $result['is_sab'],
                'show_address'    => $result['show_address'],
                'submitted'       => array(
                    'storefrontAddress' => $address_data,
                    'serviceArea'       => array( 'businessType' => $result['business_type'] ),
                ),
                'snapshot_before' => array(
                    'storefrontAddress' => $snapshot['storefrontAddress'] ?? null,
                    'serviceArea'       => $snapshot['serviceArea'] ?? null,
                ),
                'poll_count'      => 0,
                'next_poll_at'    => $now_ts + 60,
                'resolved_at'     => null,
                'history'         => array(
                    array(
                        'event'  => 'push_sent',
                        'at'     => $now_iso,
                        'at_ts'  => $now_ts,
                        'by'     => $user_login,
                        'detail' => 'PATCH enviado a GMB. updateMask=' . $result['update_mask'],
                    ),
                ),
            );

            update_post_meta( $post_id, 'gmb_address_push_job', $job );

            // ── 7. Programar primer ciclo de polling en 60 s ─────────────────────────
            // Playbook Sección 4: "schedule (seconds): 60, 120, 300, 600..."
            wp_schedule_single_event( $now_ts + 60, 'oy_poll_address_push_status', array( $post_id ) );

            wp_send_json_success( array(
                'message'    => __( 'Cambio enviado a Google Business Profile. Estado: pendiente de revisión por Google. El sistema chequeará automáticamente cada pocos minutos.', 'lealez' ),
                'panel_html' => $this->render_address_push_panel( $post_id ),
            ) );
        }

        /**
         * AJAX: Verifica manualmente el estado del último push de dirección.
         *
         * Hace un GET a GMB con el readMask correcto y compara contra el snapshot
         * pre-PATCH para determinar si fue aplicado, rechazado o sobreescrito.
         * Playbook Sección 3 — lógica de comparación de snapshots.
         *
         * @return void
         */
        public function ajax_check_address_push_status() {
            $nonce   = isset( $_POST['nonce'] )   ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) )             : 0;

            if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_check_push_status_' . $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
            }
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
            }

            $job = get_post_meta( $post_id, 'gmb_address_push_job', true );
            if ( empty( $job ) || ! is_array( $job ) ) {
                wp_send_json_error( array( 'message' => __( 'No hay push registrado para esta ubicación.', 'lealez' ) ) );
            }

            // Estados ya resueltos: solo devolver panel actual
            if ( in_array( $job['status'] ?? '', array( 'applied', 'rejected', 'google_override', 'timeout', 'cancelled' ), true ) ) {
                wp_send_json_success( array(
                    'message'    => __( 'El cambio ya está resuelto.', 'lealez' ),
                    'status'     => $job['status'],
                    'panel_html' => $this->render_address_push_panel( $post_id ),
                ) );
            }

            $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );

            if ( ! $business_id || ! $location_name ) {
                wp_send_json_error( array( 'message' => __( 'No hay empresa/ubicación GMB vinculada.', 'lealez' ) ) );
            }

            $current = Lealez_GMB_API::poll_location_address_status( $business_id, $location_name );

            if ( is_wp_error( $current ) ) {
                wp_send_json_error( array(
                    'message'    => __( 'Error al consultar GMB: ', 'lealez' ) . $current->get_error_message(),
                    'panel_html' => $this->render_address_push_panel( $post_id ),
                ) );
            }

            $now_ts  = time();
            $now_iso = gmdate( 'Y-m-d\\TH:i:s\\Z', $now_ts );

            // Determinar resultado
            $new_status = self::determine_push_outcome( $job, $current );

            $job['status']     = $new_status;
            $job['poll_count'] = ( $job['poll_count'] ?? 0 ) + 1;
            $job['history'][]  = array(
                'event'  => 'manual_check',
                'at'     => $now_iso,
                'at_ts'  => $now_ts,
                'by'     => wp_get_current_user()->user_login ?? 'system',
                'detail' => 'Verificación manual → estado: ' . $new_status
                    . ' | hasPendingEdits=' . ( ! empty( $current['metadata']['hasPendingEdits'] ) ? 'true' : 'false' )
                    . ' | hasGoogleUpdated=' . ( ! empty( $current['metadata']['hasGoogleUpdated'] ) ? 'true' : 'false' ),
            );

            if ( in_array( $new_status, array( 'applied', 'rejected', 'google_override' ), true ) ) {
                $job['resolved_at'] = $now_iso;
            }

            update_post_meta( $post_id, 'gmb_address_push_job', $job );

            if ( 'applied' === $new_status ) {
                delete_post_meta( $post_id, 'oy_address_local_pending_publish' );
            }

            wp_send_json_success( array(
                'message'    => '',
                'status'     => $new_status,
                'panel_html' => $this->render_address_push_panel( $post_id ),
            ) );
        }

        /**
         * WP-Cron: Ciclo de polling automático post-PATCH.
         *
         * Se ejecuta en los intervalos del Playbook Sección 4:
         * 60, 120, 300, 600, 900, 1800, 3600, 7200, 21600, luego cada hora hasta 30 días.
         *
         * Compara el estado actual de GMB contra el snapshot pre-PATCH y el valor
         * enviado para determinar APPLIED / REJECTED / GOOGLE_OVERRIDE.
         * Playbook Sección 3: comparación de snapshots.
         *
         * @param int $post_id  WP post ID del oy_location.
         * @return void
         */
        public static function cron_poll_address_push_status( $post_id ) {
            $post_id = absint( $post_id );
            if ( ! $post_id ) {
                return;
            }

            $job = get_post_meta( $post_id, 'gmb_address_push_job', true );
            if ( empty( $job ) || ! is_array( $job ) ) {
                return;
            }

            // Solo procesar si el job está pendiente
            if ( ! in_array( $job['status'] ?? '', array( 'pending_review', 'queued' ), true ) ) {
                return;
            }

            // Verificar límite de 30 días (Playbook Sección 4: "30-day ceiling")
            $pushed_ts   = $job['pushed_at_ts'] ?? 0;
            $thirty_days = 30 * DAY_IN_SECONDS;
            if ( $pushed_ts && ( time() - $pushed_ts ) > $thirty_days ) {
                $job['status']      = 'timeout';
                $job['resolved_at'] = gmdate( 'Y-m-d\\TH:i:s\\Z' );
                $job['history'][]   = array(
                    'event'  => 'timeout',
                    'at'     => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
                    'at_ts'  => time(),
                    'by'     => 'cron',
                    'detail' => 'Sin respuesta de Google en 30 días. El cambio puede haberse perdido.',
                );
                update_post_meta( $post_id, 'gmb_address_push_job', $job );
                return;
            }

            $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );

            if ( ! $business_id || ! $location_name ) {
                return;
            }

            $current = Lealez_GMB_API::poll_location_address_status( $business_id, $location_name );

            $now_ts  = time();
            $now_iso = gmdate( 'Y-m-d\\TH:i:s\\Z', $now_ts );

            if ( is_wp_error( $current ) ) {
                $job['poll_count'] = ( $job['poll_count'] ?? 0 ) + 1;
                $job['history'][]  = array(
                    'event'  => 'poll_error',
                    'at'     => $now_iso,
                    'at_ts'  => $now_ts,
                    'by'     => 'cron',
                    'detail' => 'Error API: ' . $current->get_error_message(),
                );
                update_post_meta( $post_id, 'gmb_address_push_job', $job );

                // Continuar ciclo de polling aunque haya error temporal
                self::schedule_next_poll( $post_id, $job['poll_count'] ?? 0 );
                return;
            }

            $new_status        = self::determine_push_outcome( $job, $current );
            $job['poll_count'] = ( $job['poll_count'] ?? 0 ) + 1;
            $job['status']     = $new_status;
            $job['history'][]  = array(
                'event'  => 'cron_poll',
                'at'     => $now_iso,
                'at_ts'  => $now_ts,
                'by'     => 'cron',
                'detail' => 'Poll #' . $job['poll_count'] . ' → estado: ' . $new_status
                    . ' | hasPendingEdits=' . ( ! empty( $current['metadata']['hasPendingEdits'] ) ? 'true' : 'false' )
                    . ' | hasGoogleUpdated=' . ( ! empty( $current['metadata']['hasGoogleUpdated'] ) ? 'true' : 'false' ),
            );

            if ( in_array( $new_status, array( 'applied', 'rejected', 'google_override' ), true ) ) {
                $job['resolved_at'] = $now_iso;
            }

            update_post_meta( $post_id, 'gmb_address_push_job', $job );

            if ( 'applied' === $new_status ) {
                delete_post_meta( $post_id, 'oy_address_local_pending_publish' );
            }

            // Si todavía está pendiente, programar el siguiente ciclo
            if ( 'pending_review' === $new_status ) {
                self::schedule_next_poll( $post_id, $job['poll_count'] );
            }
        }

        /**
         * Programa el siguiente ciclo de polling siguiendo los intervalos del Playbook.
         *
         * Playbook Sección 4: 60, 120, 300, 600, 900, 1800, 3600, 7200, 21600,
         * luego HOUR_IN_SECONDS cada hora hasta 30 días.
         *
         * @param int $post_id    WP post ID del oy_location.
         * @param int $poll_count Número de polls ejecutados hasta ahora.
         * @return void
         */
        private static function schedule_next_poll( $post_id, $poll_count ) {
            // Intervalos en segundos (Playbook Sección 4)
            $intervals = array( 60, 120, 300, 600, 900, 1800, 3600, 7200, 21600 );
            $idx       = (int) $poll_count;

            if ( isset( $intervals[ $idx ] ) ) {
                $delay = $intervals[ $idx ];
            } else {
                // Después de los intervalos predefinidos: cada hora
                $delay = HOUR_IN_SECONDS;
            }

            wp_schedule_single_event( time() + $delay, 'oy_poll_address_push_status', array( $post_id ) );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[Lealez GMB] schedule_next_poll post_id=%d poll_count=%d → siguiente en %d s',
                    $post_id, $poll_count, $delay
                ) );
            }
        }

        /**
         * Determina el resultado del push comparando estado actual de GMB con los snapshots.
         *
         * Playbook Sección 3:
         * - hasPendingEdits=true  → sigue en revisión (pending_review)
         * - current == submitted  → aplicado (applied)
         * - current == before     → rechazado (rejected)
         * - current != ambos      → sobreescrito por Google (google_override)
         *
         * La comparación usa `locality` del storefrontAddress como proxy principal
         * (es el campo más fiable que Google siempre devuelve y que no varía por
         * normalización tipográfica menor). Para SAB puro, compara businessType.
         *
         * @param array $job     El job guardado en post_meta (contiene submitted y snapshot_before).
         * @param array $current La respuesta del GET de polling.
         * @return string        Estado: pending_review | applied | rejected | google_override
         */
        private static function determine_push_outcome( array $job, array $current ) {
            // Si Google sigue procesando → seguir esperando
            if ( ! empty( $current['metadata']['hasPendingEdits'] ) ) {
                return 'pending_review';
            }

            $is_sab       = ! empty( $job['is_sab'] );
            $show_address = ! empty( $job['show_address'] );

            // ── SAB puro (CUSTOMER_LOCATION_ONLY): comparar businessType ──────────
            if ( $is_sab && ! $show_address ) {
                $submitted_bt = $job['submitted']['serviceArea']['businessType'] ?? 'CUSTOMER_LOCATION_ONLY';
                $before_bt    = $job['snapshot_before']['serviceArea']['businessType'] ?? '';
                $current_bt   = $current['serviceArea']['businessType'] ?? '';

                if ( strtolower( $current_bt ) === strtolower( $submitted_bt ) ) {
                    return 'applied';
                }
                if ( '' === $current_bt || strtolower( $current_bt ) === strtolower( $before_bt ) ) {
                    return 'rejected';
                }
                return 'google_override';
            }

            // ── Con dirección: comparar locality (city) ───────────────────────────
            $submitted_locality = strtolower( trim( (string) ( $job['submitted']['storefrontAddress']['locality'] ?? '' ) ) );
            $before_locality    = strtolower( trim( (string) ( $job['snapshot_before']['storefrontAddress']['locality'] ?? '' ) ) );
            $current_locality   = strtolower( trim( (string) ( $current['storefrontAddress']['locality'] ?? '' ) ) );

            if ( '' !== $submitted_locality && $current_locality === $submitted_locality ) {
                // Validación adicional: verificar primera línea de dirección si está disponible
                $submitted_line = strtolower( trim( (string) ( $job['submitted']['storefrontAddress']['addressLines'][0] ?? '' ) ) );
                $current_line   = strtolower( trim( (string) ( $current['storefrontAddress']['addressLines'][0] ?? '' ) ) );
                // Si la línea de dirección también coincide (o no hay línea para comparar), es aplicado
                if ( '' === $submitted_line || $current_line === $submitted_line ) {
                    return 'applied';
                }
            }

            if ( '' !== $before_locality && $current_locality === $before_locality ) {
                return 'rejected';
            }

            if ( '' === $current_locality ) {
                // Google no devolvió dirección — situación inconclusa, seguir esperando
                return 'pending_review';
            }

            return 'google_override';
        }

        /**
         * Renderiza el panel HTML del estado del último push de dirección.
         *
         * Muestra el estado actual del job con iconos y mensajes claros.
         * Es retornado como HTML en las respuestas AJAX para reemplazar el panel en la UI.
         *
         * @param int $post_id  WP post ID del oy_location.
         * @return string       HTML del panel.
         */
        private function render_address_push_panel( $post_id ) {
            $post_id      = absint( $post_id );
            $job          = get_post_meta( $post_id, 'gmb_address_push_job', true );
            $push_nonce   = wp_create_nonce( 'oy_push_address_gmb_' . $post_id );
            $check_nonce  = wp_create_nonce( 'oy_check_push_status_' . $post_id );

            $business_id        = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $location_name      = (string) get_post_meta( $post_id, 'gmb_location_name', true );
            $gmb_connected      = ! empty( $business_id ) && ! empty( $location_name );
            $local_pending      = (bool) get_post_meta( $post_id, 'oy_address_local_pending_publish', true );
            $last_manual_save   = get_post_meta( $post_id, 'oy_address_last_manual_save', true );
            $last_manual_label  = ( is_array( $last_manual_save ) && ! empty( $last_manual_save['at_label'] ) ) ? (string) $last_manual_save['at_label'] : '';
            $last_manual_user   = ( is_array( $last_manual_save ) && ! empty( $last_manual_save['by'] ) ) ? (string) $last_manual_save['by'] : '';

            $job_status         = is_array( $job ) ? (string) ( $job['status'] ?? '' ) : '';
            $push_is_locked     = in_array( $job_status, array( 'pending_review', 'queued' ), true );
            $push_disabled_attr = ( $gmb_connected && ! $push_is_locked ) ? '' : 'disabled';

            ob_start();
            ?>
            <div id="oy-address-push-panel"
                 data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
                 data-push-nonce="<?php echo esc_attr( $push_nonce ); ?>"
                 data-check-nonce="<?php echo esc_attr( $check_nonce ); ?>"
                 style="border:1px solid #dadce0; border-radius:4px; background:#fff; margin-bottom:16px; overflow:hidden;">

                <?php /* ── Header del panel ── */ ?>
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:10px 14px; background:#f6f7f7; border-bottom:1px solid #dadce0;">
                    <span style="font-size:13px; font-weight:600; color:#1d2327;">
                        📤 <?php _e( 'Publicar dirección en Google Business Profile', 'lealez' ); ?>
                    </span>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">

                        <?php /* Botón Push */ ?>
                        <button type="button"
                                id="oy-push-address-btn"
                                class="button button-primary"
                                <?php echo $push_disabled_attr; ?>
                                style="display:inline-flex; align-items:center; gap:6px;">
                            <span class="dashicons dashicons-upload" style="margin-top:3px;"></span>
                            <?php _e( 'Enviar a GMB', 'lealez' ); ?>
                        </button>

                        <?php if ( ! empty( $job ) && is_array( $job ) && ! in_array( $job['status'] ?? '', array( 'applied', 'rejected', 'google_override', 'timeout', 'cancelled' ), true ) ) : ?>
                            <?php /* Botón Verificar (solo si hay un job pendiente) */ ?>
                            <button type="button"
                                    id="oy-check-push-status-btn"
                                    class="button button-secondary"
                                    style="display:inline-flex; align-items:center; gap:6px;">
                                <span class="dashicons dashicons-search" style="margin-top:3px;"></span>
                                <?php _e( 'Verificar estado', 'lealez' ); ?>
                            </button>
                        <?php endif; ?>

                    </div>
                </div>

                <?php if ( $local_pending ) : ?>
                    <div style="padding:10px 14px; background:#eef4ff; border-bottom:1px solid #d7e3ff;">
                        <p style="margin:0; font-size:12px; color:#1d4ed8; font-weight:600;">
                            <?php _e( 'Hay cambios locales guardados en este metabox pendientes por publicar en GMB.', 'lealez' ); ?>
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

                <?php /* ── Aviso: GMB no conectado ── */ ?>
                <?php if ( ! $gmb_connected ) : ?>
                    <div style="padding:10px 14px;">
                        <p style="margin:0; font-size:12px; color:#999; font-style:italic;">
                            <?php _e( 'Requiere empresa y ubicación GMB vinculadas para poder publicar.', 'lealez' ); ?>
                        </p>
                    </div>

                <?php /* ── Estado del job ── */ ?>
                <?php elseif ( ! empty( $job ) && is_array( $job ) ) :
                    $status    = $job['status'] ?? 'unknown';
                    $pushed_at = $job['pushed_at'] ?? '';
                    $pushed_by = $job['pushed_by'] ?? '';
                    $resolved  = $job['resolved_at'] ?? '';
                    $poll_n    = $job['poll_count'] ?? 0;

                    $status_cfg = array(
                        'pending_review'  => array( '🕐', '#e07800', __( 'Pendiente de revisión por Google', 'lealez' ) ),
                        'queued'          => array( '🕐', '#e07800', __( 'En cola de envío', 'lealez' ) ),
                        'applied'         => array( '✅', '#166534', __( 'Cambio aplicado en Google', 'lealez' ) ),
                        'rejected'        => array( '❌', '#dc3232', __( 'Google rechazó el cambio', 'lealez' ) ),
                        'google_override' => array( '⚠️', '#b45309', __( 'Google reemplazó el valor con sus propios datos', 'lealez' ) ),
                        'timeout'         => array( '⏳', '#6b7280', __( 'Sin respuesta de Google en 30 días', 'lealez' ) ),
                        'error'           => array( '🔴', '#dc3232', __( 'Error técnico al enviar', 'lealez' ) ),
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
                                &nbsp;·&nbsp;<?php printf( esc_html__( 'Verificaciones automáticas: %d', 'lealez' ), (int) $poll_n ); ?>
                            <?php endif; ?>
                        </p>

                        <?php if ( 'pending_review' === $status || 'queued' === $status ) : ?>
                            <p style="margin:6px 0 0; font-size:11px; color:#888; font-style:italic;">
                                <?php _e( 'Google revisa los cambios de dirección. El proceso puede tardar entre 10 minutos y 30 días. El sistema verificará automáticamente.', 'lealez' ); ?>
                            </p>
                        <?php endif; ?>

                        <?php if ( 'rejected' === $status ) : ?>
                            <p style="margin:6px 0 0; font-size:11px; color:#dc3232;">
                                <?php _e( 'Google no aceptó el cambio. Revisa la dirección o edítala directamente en Google Business Profile.', 'lealez' ); ?>
                            </p>
                        <?php endif; ?>

                        <?php if ( 'google_override' === $status ) : ?>
                            <p style="margin:6px 0 0; font-size:11px; color:#b45309;">
                                <?php _e( 'Google reemplazó el valor con sus propios datos (mapas, usuarios, etc.). Usa "Sincronizar desde GMB" para importar los datos actuales.', 'lealez' ); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                <?php else : ?>
                    <div style="padding:10px 14px;">
                        <p style="margin:0; font-size:12px; color:#888; font-style:italic;">
                            <?php _e( 'Ningún cambio enviado aún. Activa "Editar dirección", guarda los cambios del metabox y luego usa "Enviar a GMB".', 'lealez' ); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php /* ── Mensaje de acción (se rellena por JS) ── */ ?>
                <div id="oy-push-state-action-msg"
                     style="padding:0 14px 10px; font-size:12px; min-height:0; display:none;">
                </div>

            </div>
            <?php
            return ob_get_clean();
        }

        /**
         * Render Address meta box
         *
         * @param WP_Post $post
         */
        public function render_meta_box( $post ) {
            $address_line1        = get_post_meta( $post->ID, 'location_address_line1', true );
            $address_line2        = get_post_meta( $post->ID, 'location_address_line2', true );
            $neighborhood         = get_post_meta( $post->ID, 'location_neighborhood', true );
            $city                 = get_post_meta( $post->ID, 'location_city', true );
            $state                = get_post_meta( $post->ID, 'location_state', true );
            $country              = get_post_meta( $post->ID, 'location_country', true );
            $postal_code          = get_post_meta( $post->ID, 'location_postal_code', true );
            $latitude             = get_post_meta( $post->ID, 'location_latitude', true );
            $longitude            = get_post_meta( $post->ID, 'location_longitude', true );

            // ✅ Fallback lat/lng desde RAW
            if ( ( $latitude === '' || $latitude === false ) || ( $longitude === '' || $longitude === false ) ) {
                $latlng_raw = get_post_meta( $post->ID, 'gmb_latlng_raw', true );
                if ( is_array( $latlng_raw ) ) {
                    if ( ( $latitude === '' || $latitude === false ) && ! empty( $latlng_raw['latitude'] ) ) {
                        $latitude = (string) $latlng_raw['latitude'];
                        update_post_meta( $post->ID, 'location_latitude', sanitize_text_field( $latitude ) );
                    }
                    if ( ( $longitude === '' || $longitude === false ) && ! empty( $latlng_raw['longitude'] ) ) {
                        $longitude = (string) $latlng_raw['longitude'];
                        update_post_meta( $post->ID, 'location_longitude', sanitize_text_field( $longitude ) );
                    }
                }
            }

            $formatted_address    = get_post_meta( $post->ID, 'location_formatted_address', true );
            $map_url              = get_post_meta( $post->ID, 'location_map_url', true );
            $service_area_only    = get_post_meta( $post->ID, 'service_area_only', true );
            $show_address         = get_post_meta( $post->ID, 'show_address_to_customers', true );

            // ✅ Áreas de servicio (guardado como array)
            $service_areas = get_post_meta( $post->ID, 'location_service_areas', true );
            if ( ! is_array( $service_areas ) ) {
                $service_areas = array();
            }

            /**
             * ✅ PULL AUTOMÁTICO DESDE GMB (sin predictivo)
             * Si el post tiene RAW de GMB con serviceArea y el meta humano está vacío,
             * lo calculamos y lo guardamos para que la UI muestre chips.
             */
            if ( empty( $service_areas ) ) {
                $derived = $this->extract_service_areas_from_gmb_raw( $post->ID );
                if ( ! empty( $derived ) ) {
                    $service_areas = $derived;
                    update_post_meta( $post->ID, 'location_service_areas', $service_areas );
                }
            }

            // Default: show address to customers unless explicitly disabled
            if ( '' === $show_address ) {
                $show_address = '1';
            }

            if ( empty( $country ) ) {
                $country = '';
            }

            // Determine initial states
            $is_service_area    = ( '1' === (string) $service_area_only );
            $is_show_address    = ( '1' === (string) $show_address );
            $address_hidden     = $is_service_area && ! $is_show_address;
            $show_address_row   = $is_service_area;

            // Build initial map embed URL (iframe embed — no API key required)
            $has_coords   = ( $latitude && $longitude );
            $embed_url    = '';
            $map_link_url = $map_url;

            $has_embed = false;
            if ( $map_url && strpos( $map_url, 'cid=' ) !== false ) {
                $parsed_cid = '';
                parse_str( wp_parse_url( $map_url, PHP_URL_QUERY ), $qs );
                if ( ! empty( $qs['cid'] ) ) {
                    $parsed_cid = $qs['cid'];
                }
                if ( $parsed_cid ) {
                    $embed_url = 'https://maps.google.com/maps?cid=' . rawurlencode( $parsed_cid ) . '&output=embed';
                    $has_embed = true;
                }
            }

            if ( ! $has_embed && $has_coords ) {
                $embed_url = 'https://maps.google.com/maps?q=' . rawurlencode( $latitude . ',' . $longitude ) . '&z=17&output=embed';
                $has_embed = true;
            }

            if ( empty( $map_link_url ) && $has_coords ) {
                $map_link_url = 'https://maps.google.com/maps?q=' . rawurlencode( $latitude . ',' . $longitude );
            }

            $has_coords = $has_embed;

            // Nonce para AJAX del autocomplete (mismo action del CPT)
            $ajax_nonce = wp_create_nonce( 'oy_location_gmb_ajax' );

            // ── Variables para el botón de sync de dirección ──────────────────────────
            $addr_business_id   = (int) get_post_meta( $post->ID, 'parent_business_id', true );
            $addr_location_name = (string) get_post_meta( $post->ID, 'gmb_location_name', true );
            $addr_gmb_connected = ! empty( $addr_business_id ) && ! empty( $addr_location_name );
            ?>

            <?php /* ── Panel de publicación (push) de dirección hacia GMB ── */ ?>
            <?php echo $this->render_address_push_panel( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <?php /* ── Barra de sincronización de dirección ── */ ?>
            <div id="oy-address-sync-bar" style="
                display:flex;
                align-items:center;
                gap:12px;
                background:#f6f7f7;
                border:1px solid #dadce0;
                border-radius:4px;
                padding:10px 14px;
                margin-bottom:16px;
                flex-wrap:wrap;
            ">
                <button type="button"
                        id="oy-address-sync-btn"
                        class="button button-secondary"
                        <?php echo $addr_gmb_connected ? '' : 'disabled'; ?>
                        style="display:inline-flex; align-items:center; gap:6px;">
                    <span class="dashicons dashicons-update" style="margin-top:3px;"></span>
                    <?php _e( 'Sincronizar dirección desde GMB', 'lealez' ); ?>
                </button>
                <span id="oy-address-sync-msg" style="font-size:12px; color:#555;"></span>
                <?php if ( ! $addr_gmb_connected ) : ?>
                    <span style="font-size:11px; color:#999; font-style:italic;">
                        <?php _e( '(Requiere empresa y ubicación GMB vinculadas)', 'lealez' ); ?>
                    </span>
                <?php endif; ?>
            </div>


<?php /* ── Log de Sincronización ── */ ?>
            <div id="oy-address-log-panel" style="margin-bottom:16px; border:1px solid #dadce0; border-radius:4px; overflow:hidden; background:#fff;">
                <div id="oy-address-log-header" style="
                    display:flex; align-items:center; justify-content:space-between;
                    padding:8px 14px; background:#f6f7f7; cursor:pointer;
                    border-bottom:1px solid transparent; user-select:none;
                ">
                    <span style="font-size:13px; font-weight:600; color:#1d2327;">
                        🔍 <?php _e( 'Log de Sincronización — Dirección & Geolocalización', 'lealez' ); ?>
                    </span>
                    <span id="oy-address-log-toggle-icon" style="font-size:13px; color:#888; transition:transform .2s;">▶</span>
                </div>
                <div id="oy-address-log-body" style="display:none;">
                    <div id="oy-address-log-entries"></div>
                    <div style="padding:8px 14px; border-top:1px solid #f0f0f0; background:#fafafa; display:flex; gap:10px; align-items:center;">
                        <button type="button" id="oy-address-log-clear" class="button button-small"
                                style="font-size:11px; color:#dc3232; border-color:#dc3232;">
                            🗑 <?php _e( 'Limpiar historial', 'lealez' ); ?>
                        </button>
                        <span style="font-size:11px; color:#aaa; font-style:italic;">
                            <?php _e( 'Historial guardado en el navegador (localStorage). Máx 20 entradas.', 'lealez' ); ?>
                        </span>
                    </div>
                </div>
            </div>


            <?php /* ── Ubicación de la empresa ── */ ?>
            <div style="background:#f0f6fc; border:1px solid #c3d4e6; border-radius:4px; padding:14px 16px; margin-bottom:20px;">
                <h4 style="margin:0 0 8px; font-size:14px; color:#1d2327;">
                    📍 <?php _e( 'Ubicación de la empresa', 'lealez' ); ?>
                </h4>
                <p class="description" style="margin:0 0 12px;">
                    <?php _e( 'Si los clientes visitan tu empresa, agrega una dirección. Si solo ofreces servicios en el domicilio del cliente o en línea, activa la opción "Sin ubicación física".', 'lealez' ); ?>
                </p>

                <label style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                    <input type="checkbox"
                           name="service_area_only"
                           id="service_area_only"
                           value="1"
                        <?php checked( $service_area_only, '1' ); ?>>
                    <?php _e( 'Sin ubicación física — solo envíos y servicios en el hogar', 'lealez' ); ?>
                </label>

                <div id="oy-show-address-row" style="display:<?php echo $show_address_row ? 'flex' : 'none'; ?>; align-items:center; gap:8px; margin-left:24px;">
                    <input type="checkbox"
                           name="show_address_to_customers"
                           id="show_address_to_customers"
                           value="1"
                        <?php checked( $show_address, '1' ); ?>>
                    <?php _e( 'Mostrar la dirección de la empresa a los clientes', 'lealez' ); ?>
                </div>
            </div>

            <?php /* ── Áreas de servicio ── */ ?>
            <div style="border:1px solid #dadce0; border-radius:6px; padding:14px 16px; margin:0 0 18px; background:#fff;">
                <h4 style="margin:0 0 8px; font-size:14px; color:#1d2327;">
                    🧭 <?php _e( 'Áreas de servicio', 'lealez' ); ?>
                </h4>
                <p class="description" style="margin:0 0 12px;">
                    <?php _e( 'Define ciudades/zonas donde atiendes. (Aquí se muestran las importadas desde Google).', 'lealez' ); ?>
                </p>

                <div style="max-width:520px; position:relative;">
                    <input type="text"
                           id="oy-service-area-search"
                           class="large-text"
                           placeholder="<?php esc_attr_e( 'Busca áreas (ej: Barranquilla, Atlántico, Colombia)', 'lealez' ); ?>"
                           autocomplete="off">

                    <div id="oy-service-area-suggestions"
                         style="display:none; position:absolute; top:100%; left:0; right:0; z-index:9999; background:#fff; border:1px solid #dadce0; border-top:none; max-height:220px; overflow:auto;">
                    </div>
                </div>

                <div id="oy-service-area-selected"
                     style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
                </div>

                <input type="hidden"
                       id="location_service_areas_json"
                       name="location_service_areas_json"
                       value="<?php echo esc_attr( wp_json_encode( array_values( $service_areas ) ) ); ?>">

                <p class="description" style="margin-top:10px;">
                    <?php _e( 'Importado desde GMB cuando exista. Guardado en meta: <code>location_service_areas</code>.', 'lealez' ); ?>
                </p>
            </div>

            <?php /* ── Layout de dos columnas: Campos | Mapa ── */ ?>
            <div id="oy-address-map-layout" style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap;">

                <?php /* ── Columna izquierda: campos de dirección ── */ ?>
                <div id="oy-address-fields-col" style="flex:1; min-width:280px;">

                    <div id="oy-address-fields-wrap" <?php echo $address_hidden ? 'style="display:none;"' : ''; ?>>
                        <table class="form-table" style="margin-top:0;">
                            <tr>
                                <th scope="row" style="width:160px;">
                                    <label for="location_address_line1"><?php _e( 'Dirección Principal', 'lealez' ); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="location_address_line1"
                                           id="location_address_line1"
                                           value="<?php echo esc_attr( $address_line1 ); ?>"
                                           class="large-text"
                                           placeholder="<?php esc_attr_e( 'Ej: Calle 10 # 25-30', 'lealez' ); ?>">
                                    <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.addressLines[0]</code>', 'lealez' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="location_address_line2"><?php _e( 'Complemento', 'lealez' ); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="location_address_line2"
                                           id="location_address_line2"
                                           value="<?php echo esc_attr( $address_line2 ); ?>"
                                           class="large-text"
                                           placeholder="<?php esc_attr_e( 'Ej: Local 202, Piso 2, Edificio Torre Norte', 'lealez' ); ?>">
                                    <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.subPremise</code> o <code>addressLines[1]</code>', 'lealez' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="location_neighborhood"><?php _e( 'Barrio/Colonia', 'lealez' ); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="location_neighborhood"
                                           id="location_neighborhood"
                                           value="<?php echo esc_attr( $neighborhood ); ?>"
                                           class="regular-text">
                                    <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.sublocality</code> (si disponible).', 'lealez' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="location_city"><?php _e( 'Ciudad', 'lealez' ); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="location_city"
                                           id="location_city"
                                           value="<?php echo esc_attr( $city ); ?>"
                                           class="regular-text">
                                    <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.locality</code>', 'lealez' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="location_state"><?php _e( 'Estado/Departamento', 'lealez' ); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="location_state"
                                           id="location_state"
                                           value="<?php echo esc_attr( $state ); ?>"
                                           class="regular-text">
                                    <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.administrativeArea</code>', 'lealez' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="location_country"><?php _e( 'País (ISO 2)', 'lealez' ); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="location_country"
                                           id="location_country"
                                           value="<?php echo esc_attr( $country ); ?>"
                                           class="regular-text"
                                           placeholder="<?php esc_attr_e( 'CO, MX, US', 'lealez' ); ?>"
                                           maxlength="2">
                                    <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.regionCode</code>.', 'lealez' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="location_postal_code"><?php _e( 'Código Postal', 'lealez' ); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="location_postal_code"
                                           id="location_postal_code"
                                           value="<?php echo esc_attr( $postal_code ); ?>"
                                           class="regular-text">
                                    <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.postalCode</code>', 'lealez' ); ?></p>
                                </td>
                            </tr>
                            <?php if ( $formatted_address ) : ?>
                                <tr>
                                    <th scope="row">
                                        <label><?php _e( 'Dirección Formateada', 'lealez' ); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" readonly class="large-text" value="<?php echo esc_attr( $formatted_address ); ?>">
                                        <p class="description"><?php _e( 'Auto-generada al importar desde GMB.', 'lealez' ); ?></p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div><!-- #oy-address-fields-wrap -->

                    <?php /* ── Coordenadas GPS: siempre visibles ── */ ?>
                    <table class="form-table" id="oy-coords-map-wrap" style="margin-top:0;">
                        <tr>
                            <th scope="row" style="width:160px;">
                                <label><?php _e( 'Coordenadas GPS', 'lealez' ); ?></label>
                            </th>
                            <td>
                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <div>
                                        <label for="location_latitude"><?php _e( 'Latitud', 'lealez' ); ?></label>
                                        <input type="text"
                                               name="location_latitude"
                                               id="location_latitude"
                                               value="<?php echo esc_attr( $latitude ); ?>"
                                               class="regular-text"
                                               placeholder="6.2476376">
                                    </div>
                                    <div>
                                        <label for="location_longitude"><?php _e( 'Longitud', 'lealez' ); ?></label>
                                        <input type="text"
                                               name="location_longitude"
                                               id="location_longitude"
                                               value="<?php echo esc_attr( $longitude ); ?>"
                                               class="regular-text"
                                               placeholder="-75.5658153">
                                    </div>
                                </div>
                                <p class="description"><?php _e( 'Importado desde GMB: <code>latlng.latitude</code> / <code>latlng.longitude</code>', 'lealez' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="location_map_url"><?php _e( 'URL en Google Maps', 'lealez' ); ?></label>
                            </th>
                            <td>
                                <input type="url"
                                       name="location_map_url"
                                       id="location_map_url"
                                       value="<?php echo esc_attr( $map_url ); ?>"
                                       class="large-text">
                                <p class="description">
                                    <?php _e( 'Auto-importado desde GMB: <code>metadata.mapsUri</code>.', 'lealez' ); ?>
                                    <?php if ( $map_url ) : ?>
                                        &nbsp;<a href="<?php echo esc_url( $map_url ); ?>" target="_blank" id="oy-maps-open-link"><?php _e( 'Ver en Maps ↗', 'lealez' ); ?></a>
                                    <?php else : ?>
                                        &nbsp;<a href="#" target="_blank" id="oy-maps-open-link" style="<?php echo $has_coords ? '' : 'display:none;'; ?>"><?php _e( 'Ver en Maps ↗', 'lealez' ); ?></a>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                </div><!-- #oy-address-fields-col -->

                <?php /* ── Columna derecha: mapa ── */ ?>
                <div id="oy-map-preview-col" style="flex:0 0 380px; min-width:280px;">
                    <div id="oy-map-preview-wrap" style="
                        border:1px solid #c3d4e6;
                        border-radius:4px;
                        overflow:hidden;
                        background:#e8eaf0;
                        position:relative;
                        height:320px;
                        display:<?php echo $has_coords ? 'block' : 'flex'; ?>;
                        align-items:center;
                        justify-content:center;
                    ">
                        <?php if ( $has_coords ) : ?>
                            <iframe
                                id="oy-map-iframe"
                                src="<?php echo esc_url( $embed_url ); ?>"
                                width="100%"
                                height="320"
                                style="border:0; display:block;"
                                allowfullscreen=""
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                            ></iframe>
                        <?php else : ?>
                            <div id="oy-map-placeholder" style="text-align:center; color:#757575; padding:20px;">
                                <span style="font-size:40px; display:block; margin-bottom:10px;">🗺️</span>
                                <p style="margin:0; font-size:13px;"><?php _e( 'El mapa aparecerá cuando se ingresen las coordenadas GPS o se sincronice con GMB.', 'lealez' ); ?></p>
                            </div>
                            <iframe
                                id="oy-map-iframe"
                                src=""
                                width="100%"
                                height="320"
                                style="border:0; display:none;"
                                allowfullscreen=""
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                            ></iframe>
                        <?php endif; ?>

                        <?php if ( $map_link_url ) : ?>
                            <a href="<?php echo esc_url( $map_link_url ); ?>"
                               id="oy-map-adjust-btn"
                               target="_blank"
                               style="
                                position:absolute;
                                top:10px;
                                right:10px;
                                background:#fff;
                                border:1px solid #dadce0;
                                border-radius:4px;
                                padding:6px 14px;
                                font-size:13px;
                                font-weight:500;
                                color:#1a73e8;
                                text-decoration:none;
                                box-shadow:0 1px 3px rgba(0,0,0,.2);
                                z-index:10;
                                cursor:pointer;
                                line-height:1.4;
                               "><?php _e( 'Ajustar', 'lealez' ); ?></a>
                        <?php else : ?>
                            <a href="#"
                               id="oy-map-adjust-btn"
                               target="_blank"
                               style="
                                position:absolute;
                                top:10px;
                                right:10px;
                                background:#fff;
                                border:1px solid #dadce0;
                                border-radius:4px;
                                padding:6px 14px;
                                font-size:13px;
                                font-weight:500;
                                color:#1a73e8;
                                text-decoration:none;
                                box-shadow:0 1px 3px rgba(0,0,0,.2);
                                z-index:10;
                                cursor:pointer;
                                line-height:1.4;
                                display:<?php echo $has_coords ? 'block' : 'none'; ?>;
                               "><?php _e( 'Ajustar', 'lealez' ); ?></a>
                        <?php endif; ?>
                    </div>
                    <p class="description" style="margin-top:6px; font-size:11px; color:#757575;">
                        <?php _e( 'Vista previa del mapa. Se actualiza al cambiar las coordenadas GPS.', 'lealez' ); ?>
                    </p>
                </div><!-- #oy-map-preview-col -->

            </div><!-- #oy-address-map-layout -->

            <script type="text/javascript">
                // Vars para AJAX del autocomplete (mismo action del CPT)
                window.oyServiceAreasAjax = {
                    ajaxurl: (typeof ajaxurl !== 'undefined') ? ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
                    nonce: '<?php echo esc_js( $ajax_nonce ); ?>'
                };

                /**
                 * ✅ Service Areas UI (chips + sugerencias)
                 * Exponemos helpers a window para que el import (applyLocationToForm) lo pueda rellenar.
                 */
                (function(){
                    var $ = jQuery;

                    function safeJsonParse(v, fallback){
                        try { return JSON.parse(v); } catch(e){ return fallback; }
                    }

                    function getAreas(){
                        var raw = $('#location_service_areas_json').val() || '[]';
                        var arr = safeJsonParse(raw, []);
                        if (!Array.isArray(arr)) arr = [];
                        var out = [];
                        arr.forEach(function(x){
                            if (typeof x === 'string') {
                                var s = x.trim();
                                if (s) out.push(s);
                            }
                        });
                        return out;
                    }

                    function setAreas(arr){
                        if (!Array.isArray(arr)) arr = [];
                        var seen = {};
                        var out = [];
                        arr.forEach(function(x){
                            if (typeof x === 'string') {
                                var s = x.trim();
                                if (s && !seen[s.toLowerCase()]) {
                                    seen[s.toLowerCase()] = true;
                                    out.push(s);
                                }
                            }
                        });
                        $('#location_service_areas_json').val(JSON.stringify(out));
                        renderChips(out);
                    }

                    function renderChips(arr){
                        var $wrap = $('#oy-service-area-selected');
                        $wrap.empty();
                        arr.forEach(function(label){
                            var chip = $('<span/>').css({
                                display:'inline-flex',
                                alignItems:'center',
                                gap:'8px',
                                padding:'6px 10px',
                                border:'1px solid #dadce0',
                                borderRadius:'18px',
                                background:'#f6f7f7',
                                fontSize:'12px'
                            });
                            chip.append($('<span/>').text(label));
                            var btn = $('<button type="button" aria-label="remove">✕</button>').addClass('button-link')
                                .css({color:'#dc3232', textDecoration:'none', border:'none', background:'transparent', cursor:'pointer', padding:0, margin:0});
                            btn.on('click', function(){
                                var cur = getAreas().filter(function(x){ return x !== label; });
                                setAreas(cur);
                            });
                            chip.append(btn);
                            $wrap.append(chip);
                        });
                    }

                    function hideSuggestions(){
                        $('#oy-service-area-suggestions').hide().empty();
                    }

                    function showSuggestions(list){
                        var $box = $('#oy-service-area-suggestions');
                        $box.empty();

                        if (!list || !list.length){
                            hideSuggestions();
                            return;
                        }

                        list.forEach(function(item){
                            var row = $('<div/>').css({
                                padding:'10px 12px',
                                cursor:'pointer',
                                borderTop:'1px solid #f1f1f1'
                            }).text(item.description || item.label || '');

                            row.on('mouseenter', function(){ $(this).css('background','#f6f7f7'); });
                            row.on('mouseleave', function(){ $(this).css('background','#fff'); });

                            row.on('click', function(){
                                var label = (item.description || item.label || '').trim();
                                if (!label) return;

                                var cur = getAreas();
                                cur.push(label);
                                setAreas(cur);

                                $('#oy-service-area-search').val('');
                                hideSuggestions();
                            });

                            $box.append(row);
                        });

                        $box.show();
                    }

                    var debounceTimer = null;
                    function fetchSuggestions(q){
                        q = (q || '').trim();
                        if (!q || q.length < 2){
                            hideSuggestions();
                            return;
                        }

                        clearTimeout(debounceTimer);
                        debounceTimer = setTimeout(function(){
                            $.post(window.oyServiceAreasAjax.ajaxurl, {
                                action: 'oy_gmb_service_area_autocomplete',
                                nonce: window.oyServiceAreasAjax.nonce,
                                q: q,
                                country: ($('#location_country').val() || '').trim()
                            }, function(resp){
                                if (!resp || !resp.success){
                                    hideSuggestions();
                                    return;
                                }
                                showSuggestions(resp.data && resp.data.suggestions ? resp.data.suggestions : []);
                            });
                        }, 250);
                    }

                    // ✅ Expuesto para applyLocationToForm y para el botón de sync de dirección
                    window.oy_service_areas_set = function(arr){
                        setAreas(Array.isArray(arr) ? arr : []);
                    };

                    $(document).ready(function(){
                        // Inicial render
                        renderChips(getAreas());

                        $('#oy-service-area-search').on('input', function(){
                            fetchSuggestions($(this).val());
                        });

                        // cerrar dropdown al click afuera
                        $(document).on('click', function(e){
                            var $t = $(e.target);
                            if ($t.closest('#oy-service-area-search').length) return;
                            if ($t.closest('#oy-service-area-suggestions').length) return;
                            hideSuggestions();
                        });
                    });
                })();

                /**
                 * oy_toggle_address_fields
                 */
                window.oy_toggle_address_fields = function() {
                    var $ = jQuery;
                    var isServiceAreaOnly  = $('#service_area_only').is(':checked');
                    var showAddressChecked = $('#show_address_to_customers').is(':checked');

                    if ( isServiceAreaOnly ) {
                        $('#oy-show-address-row').css('display', 'flex');
                    } else {
                        $('#oy-show-address-row').css('display', 'none');
                    }

                    if ( isServiceAreaOnly && ! showAddressChecked ) {
                        $('#oy-address-fields-wrap').hide();
                    } else {
                        $('#oy-address-fields-wrap').show();
                    }
                };

                /**
                 * oy_update_map_preview
                 */
                window.oy_update_map_preview = function() {
                    var $ = jQuery;
                    var lat        = $.trim( $('#location_latitude').val() );
                    var lng        = $.trim( $('#location_longitude').val() );
                    var savedMapUrl = $.trim( $('#location_map_url').val() );

                    var hasCoords  = lat && lng && !isNaN(parseFloat(lat)) && !isNaN(parseFloat(lng));

                    var embedUrl = '';
                    var mapsUrl  = savedMapUrl || '';

                    if ( savedMapUrl && savedMapUrl.indexOf('cid=') !== -1 ) {
                        var cidMatch = savedMapUrl.match(/[?&]cid=([^&]+)/);
                        if ( cidMatch && cidMatch[1] ) {
                            embedUrl = 'https://maps.google.com/maps?cid=' + encodeURIComponent(cidMatch[1]) + '&output=embed';
                        }
                    }

                    if ( ! embedUrl && hasCoords ) {
                        embedUrl = 'https://maps.google.com/maps?q=' + encodeURIComponent(lat + ',' + lng) + '&z=17&output=embed';
                    }

                    if ( ! mapsUrl && hasCoords ) {
                        mapsUrl = 'https://maps.google.com/maps?q=' + encodeURIComponent(lat + ',' + lng);
                    }

                    if ( ! embedUrl ) {
                        $('#oy-map-iframe').hide().attr('src', '');
                        $('#oy-map-placeholder').show();
                        $('#oy-map-preview-wrap').css({ 'display': 'flex' });
                        $('#oy-map-adjust-btn').hide();
                        $('#oy-maps-open-link').hide();
                        return;
                    }

                    $('#oy-map-placeholder').hide();
                    $('#oy-map-iframe').attr('src', embedUrl).css('display', 'block');
                    $('#oy-map-preview-wrap').css({ 'display': 'block' });

                    if ( mapsUrl ) {
                        $('#oy-map-adjust-btn').attr('href', mapsUrl).show();
                        $('#oy-maps-open-link').attr('href', mapsUrl).show();
                    }
                };

                jQuery(document).ready(function($){
                    $('#service_area_only').on('change', window.oy_toggle_address_fields);
                    $('#show_address_to_customers').on('change', window.oy_toggle_address_fields);

                    var oy_map_debounce_timer;
                    $('#location_latitude, #location_longitude').on('input change', function() {
                        clearTimeout(oy_map_debounce_timer);
                        oy_map_debounce_timer = setTimeout(function() {
                            window.oy_update_map_preview();
                        }, 600);
                    });

                    $('#location_map_url').on('input change', function() {
                        var mapUrl = $.trim( $(this).val() );
                        if ( mapUrl ) {
                            $('#oy-map-adjust-btn').attr('href', mapUrl).show();
                            $('#oy-maps-open-link').attr('href', mapUrl).show();
                        }
                    });

                    window.oy_toggle_address_fields();

                    (function() {
                        var lat = $.trim( $('#location_latitude').val() );
                        var lng = $.trim( $('#location_longitude').val() );
                        if ( lat && lng && !isNaN(parseFloat(lat)) && !isNaN(parseFloat(lng)) ) {
                            var iframeSrc = $('#oy-map-iframe').attr('src');
                            var savedMapUrl = $.trim( $('#location_map_url').val() );
                            var mapsUrl = savedMapUrl || 'https://maps.google.com/maps?q=' + encodeURIComponent(lat) + ',' + encodeURIComponent(lng);

                            if ( iframeSrc && iframeSrc.length > 5 ) {
                                $('#oy-map-adjust-btn').attr('href', mapsUrl).show();
                                $('#oy-maps-open-link').attr('href', mapsUrl).show();
                                $('#oy-map-placeholder').hide();
                                $('#oy-map-iframe').css('display', 'block');
                                $('#oy-map-preview-wrap').css('display', 'block');
                            } else {
                                window.oy_update_map_preview();
                            }
                        }
                    })();
                });

/**
                 * ── Botón "Sincronizar dirección desde GMB" ──────────────────────────────
                 *
                 * Compatible con class-oy-location-gmb-integration-metabox.php:
                 *  - Reutiliza el mismo nonce action 'oy_location_gmb_ajax'
                 *  - Llama al mismo AJAX handler que usa el pipeline completo (PASO 1)
                 *  - Delega en applyLocationToForm(loc) del CPT JS para rellenar campos
                 *  - Actualiza chips de "Áreas de servicio" vía window.oy_service_areas_set()
                 *  - Emite evento oy:gmb:address:refreshed para extensibilidad futura
                 *  - NO compite con #oy-full-sync-btn ni desactiva el pipeline completo
                 *  - Bloquea si window.oyIntegrationSyncRunning === true (pipeline corriendo)
                 *  - Expone window.oyAddrLogAPI para que el pipeline alimente el log
                 */
                (function(){
                    var $ = jQuery;

                    var ajaxUrl     = (typeof ajaxurl !== 'undefined') ? ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
                    var addrNonce   = '<?php echo esc_js( $ajax_nonce ); ?>';
                    var postId      = '<?php echo esc_js( (string) $post->ID ); ?>';
                    var syncRunning = false;

                    // ── LocalStorage key (por post) ───────────────────────────────────────
                    var LS_KEY  = 'oy_addr_log_' + postId;
                    var MAX_LOG = 20;

                    // ── Campos monitoreados ───────────────────────────────────────────────
                    var FIELD_MAP = [
                        { key: 'address_line1', selector: '#location_address_line1',      label: 'Dirección Principal' },
                        { key: 'address_line2', selector: '#location_address_line2',      label: 'Complemento' },
                        { key: 'neighborhood',  selector: '#location_neighborhood',       label: 'Barrio/Colonia' },
                        { key: 'city',          selector: '#location_city',               label: 'Ciudad' },
                        { key: 'state',         selector: '#location_state',              label: 'Estado/Dpto' },
                        { key: 'country',       selector: '#location_country',            label: 'País (ISO 2)' },
                        { key: 'postal_code',   selector: '#location_postal_code',        label: 'Código Postal' },
                        { key: 'latitude',      selector: '#location_latitude',           label: 'Latitud' },
                        { key: 'longitude',     selector: '#location_longitude',          label: 'Longitud' },
                        { key: 'map_url',       selector: '#location_map_url',            label: 'URL Google Maps' },
                        { key: 'service_areas', selector: '#location_service_areas_json', label: 'Áreas de Servicio', isJson: true },
                    ];

                    // ── Helpers de UI ─────────────────────────────────────────────────────
                    function setAddrMsg(msg, type) {
                        var colors = { info: '#555', success: '#46b450', error: '#dc3232' };
                        $('#oy-address-sync-msg').text(msg).css('color', colors[type] || '#555');
                    }

                    /**
                     * Resetea el estado del botón de forma segura.
                     * Se llama al inicio de .done() y .fail() para evitar bloqueos
                     * en jQuery 3.x (una excepción en .done() aborta la cadena, omitiendo .always()).
                     */
                    function resetBtn() {
                        syncRunning = false;
                        var $b = $('#oy-address-sync-btn');
                        $b.prop('disabled', false);
                        $b.find('.dashicons').removeClass('spin');
                    }

                    // ── Snapshot de todos los campos ──────────────────────────────────────
                    function captureSnapshot() {
                        var snap = {};
                        FIELD_MAP.forEach(function(f) {
                            var val = $(f.selector).val() || '';
                            if (f.isJson) {
                                try { val = JSON.parse(val); } catch(e) { val = []; }
                                if (!Array.isArray(val)) { val = []; }
                            }
                            snap[f.key] = val;
                        });
                        return snap;
                    }

                    // ── Diff entre dos snapshots ──────────────────────────────────────────
                    function buildDiff(before, after) {
                        var rows = [];
                        FIELD_MAP.forEach(function(f) {
                            var bVal = before[f.key];
                            var aVal = after[f.key];

                            var bStr = f.isJson
                                ? (Array.isArray(bVal) ? bVal.join(', ') : JSON.stringify(bVal))
                                : String(bVal == null ? '' : bVal);
                            var aStr = f.isJson
                                ? (Array.isArray(aVal) ? aVal.join(', ') : JSON.stringify(aVal))
                                : String(aVal == null ? '' : aVal);

                            var rawBefore = f.isJson ? JSON.stringify(bVal) : bStr;
                            var rawAfter  = f.isJson ? JSON.stringify(aVal) : aStr;

                            var status;
                            if (rawBefore === rawAfter) {
                                status = 'unchanged';
                            } else if (!bStr || bStr === '""' || bStr === '[]' || bStr.trim() === '') {
                                status = 'new';
                            } else {
                                status = 'changed';
                            }

                            rows.push({ label: f.label, before: bStr, after: aStr, status: status });
                        });
                        return rows;
                    }

                    // ── localStorage helpers ──────────────────────────────────────────────
                    function loadLog() {
                        try {
                            var raw = localStorage.getItem(LS_KEY);
                            if (!raw) { return []; }
                            var arr = JSON.parse(raw);
                            return Array.isArray(arr) ? arr : [];
                        } catch(e) { return []; }
                    }

                    function saveLog(entries) {
                        try {
                            if (entries.length > MAX_LOG) {
                                entries = entries.slice(entries.length - MAX_LOG);
                            }
                            localStorage.setItem(LS_KEY, JSON.stringify(entries));
                        } catch(e) {}
                    }

                    function clearLog() {
                        try { localStorage.removeItem(LS_KEY); } catch(e) {}
                    }

                    // ── Agregar entrada al log ────────────────────────────────────────────
                    function addLogEntry(rawGmb, diff, source) {
                        try {
                            var now = new Date();
                            var ts = now.toLocaleDateString('es-CO', {
                                    year: 'numeric', month: '2-digit', day: '2-digit'
                                }) + ' ' + now.toLocaleTimeString('es-CO', {
                                    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
                                });

                            // Sanitizar el raw para que sea serializable (evitar circulares)
                            var safeRaw = rawGmb;
                            try {
                                JSON.stringify(rawGmb);
                            } catch(circErr) {
                                safeRaw = { _serializeError: 'Objeto no serializable: ' + circErr.message };
                            }

                            // Indicar si vino del pipeline completo o del botón individual
                            var entrySource = source || 'button';

                            var log = loadLog();
                            log.push({ timestamp: ts, raw: safeRaw, diff: diff || [], source: entrySource });
                            saveLog(log);
                            renderLog();

                            // Abrir panel si estaba cerrado
                            if ($('#oy-address-log-body').is(':hidden')) {
                                $('#oy-address-log-body').show();
                                $('#oy-address-log-header').css('borderBottomColor', '#dadce0');
                                $('#oy-address-log-toggle-icon').text('▼');
                            }
                        } catch(e) {
                            if (window.console && window.console.error) {
                                console.error('[OY Address Log] addLogEntry error:', e);
                            }
                        }
                    }

                    // ── Render completo del log ───────────────────────────────────────────
                    function renderLog() {
                        try {
                            var entries    = loadLog();
                            var $container = $('#oy-address-log-entries');
                            if (!$container.length) { return; }
                            $container.empty();

                            if (!entries.length) {
                                $container.append(
                                    $('<p/>').css({
                                        padding: '12px 16px', margin: 0,
                                        fontSize: '12px', color: '#888', fontStyle: 'italic'
                                    }).text('Aún no hay sincronizaciones registradas. Usa el botón "Sincronizar dirección desde GMB" o ejecuta la Sync completa.')
                                );
                                return;
                            }

                            // Más reciente primero
                            var sorted = entries.slice().reverse();

                            sorted.forEach(function(entry, idx) {
                                var diff = Array.isArray(entry.diff) ? entry.diff : [];
                                var changedCount = diff.filter(function(r) {
                                    return r.status === 'changed' || r.status === 'new';
                                }).length;
                                var isFirst  = idx === 0;
                                var bgHeader = isFirst ? '#f0f7ff' : '#fff';

                                // Origen del registro (botón individual vs pipeline completo)
                                var srcIcon  = (entry.source === 'pipeline') ? '⚙️' : '🔵';
                                var srcLabel = (entry.source === 'pipeline') ? ' · Sync completa' : ' · Botón dirección';

                                var $entry = $('<div/>').css({
                                    borderBottom: '1px solid ' + (isFirst ? '#c3d4e6' : '#f0f0f0'),
                                });

                                // — Header del entry —
                                var $entryHeader = $('<div/>').css({
                                    display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                                    padding: '8px 14px', cursor: 'pointer', userSelect: 'none',
                                    background: bgHeader, gap: '10px',
                                });

                                var statusLabel, statusColor;
                                var hasError = entry.raw && (entry.raw.error || entry.raw._serializeError);
                                if (hasError) {
                                    statusLabel = '❌ Error';
                                    statusColor = '#dc3232';
                                } else if (!diff.length) {
                                    statusLabel = '⚠️ Sin datos de diff';
                                    statusColor = '#999';
                                } else if (changedCount === 0) {
                                    statusLabel = '✅ Sin cambios';
                                    statusColor = '#46b450';
                                } else {
                                    statusLabel = '✏️ ' + changedCount + ' campo' + (changedCount !== 1 ? 's' : '') + ' modificado' + (changedCount !== 1 ? 's' : '');
                                    statusColor = '#e06800';
                                }

                                $entryHeader.append(
                                    $('<span/>').css({ fontSize: '11px', color: '#555', flex: '1', fontFamily: 'monospace' })
                                        .text(srcIcon + ' 🕐 ' + entry.timestamp + srcLabel + (isFirst ? '  ← más reciente' : ''))
                                );
                                $entryHeader.append(
                                    $('<span/>').css({ fontSize: '11px', fontWeight: '600', color: statusColor })
                                        .text(statusLabel)
                                );
                                var $toggleIcon = $('<span/>').css({ fontSize: '11px', color: '#aaa', marginLeft: '6px' })
                                    .text(isFirst ? '▼' : '▶');
                                $entryHeader.append($toggleIcon);

                                // — Body del entry —
                                var $body = $('<div/>').css({
                                    display: isFirst ? 'block' : 'none',
                                    padding: '0 14px 14px',
                                    background: '#fff',
                                });

                                $entryHeader.on('click', function() {
                                    $body.toggle();
                                    $toggleIcon.text($body.is(':visible') ? '▼' : '▶');
                                });

                                // — Tabla de diff —
                                if (diff.length) {
                                    var $table = $('<table/>').css({
                                        width: '100%', borderCollapse: 'collapse',
                                        fontSize: '11px', marginTop: '10px', marginBottom: '10px',
                                    });

                                    $('<thead/>').append(
                                        $('<tr/>').append(
                                            $('<th/>').css({ textAlign:'left', padding:'5px 8px', background:'#f6f7f7', borderBottom:'2px solid #e5e5e5', color:'#555', width:'140px' }).text('Campo'),
                                            $('<th/>').css({ textAlign:'left', padding:'5px 8px', background:'#f6f7f7', borderBottom:'2px solid #e5e5e5', color:'#555' }).text('Antes'),
                                            $('<th/>').css({ textAlign:'left', padding:'5px 8px', background:'#f6f7f7', borderBottom:'2px solid #e5e5e5', color:'#555' }).text('Después'),
                                            $('<th/>').css({ textAlign:'center', padding:'5px 8px', background:'#f6f7f7', borderBottom:'2px solid #e5e5e5', color:'#555', width:'100px' }).text('Estado')
                                        )
                                    ).appendTo($table);

                                    var $tbody = $('<tbody/>');

                                    diff.forEach(function(row) {
                                        var cfgMap = {
                                            unchanged: { rowBg:'#fff',    bColor:'#aaa',    aColor:'#aaa',    icon:'—',  iColor:'#ccc'    },
                                            new:       { rowBg:'#f6fff9', bColor:'#aaa',    aColor:'#276749', icon:'🆕', iColor:'#276749' },
                                            changed:   { rowBg:'#fffbea', bColor:'#dc3232', aColor:'#2271b1', icon:'✏️', iColor:'#e06800' },
                                        };
                                        var cfg = cfgMap[row.status] || cfgMap.unchanged;

                                        var bText = row.before && row.before.trim() ? row.before : '(vacío)';
                                        var aText = row.after  && row.after.trim()  ? row.after  : '(vacío)';

                                        var $tr = $('<tr/>').css({ background: cfg.rowBg });
                                        $('<td/>').css({ padding:'4px 8px', borderBottom:'1px solid #f3f3f3', fontWeight:'600', color:'#1d2327', whiteSpace:'nowrap' }).text(row.label).appendTo($tr);
                                        $('<td/>').css({ padding:'4px 8px', borderBottom:'1px solid #f3f3f3', color:cfg.bColor, fontFamily:'monospace', maxWidth:'200px', overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap', textDecoration: row.status === 'changed' ? 'line-through' : 'none' }).attr('title', bText).text(bText).appendTo($tr);
                                        $('<td/>').css({ padding:'4px 8px', borderBottom:'1px solid #f3f3f3', color:cfg.aColor, fontFamily:'monospace', fontWeight: row.status !== 'unchanged' ? '600' : '400', maxWidth:'200px', overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }).attr('title', aText).text(aText).appendTo($tr);
                                        $('<td/>').css({ padding:'4px 8px', borderBottom:'1px solid #f3f3f3', textAlign:'center', color:cfg.iColor, fontSize:'13px' }).text(cfg.icon).appendTo($tr);
                                        $tbody.append($tr);
                                    });

                                    $table.append($tbody);
                                    $body.append($table);
                                }

                                // — Raw GMB collapsible —
                                try {
                                    var rawStr = JSON.stringify(entry.raw, null, 2);
                                    if (rawStr && rawStr !== 'null' && rawStr !== 'undefined') {
                                        var $rawToggleBtn = $('<button type="button"/>').addClass('button-link').css({
                                            fontSize: '11px', color: '#1a73e8', padding: '0',
                                            border: 'none', background: 'transparent',
                                            cursor: 'pointer', textDecoration: 'underline',
                                            display: 'block', marginBottom: '6px',
                                        }).text('📡 Ver respuesta completa de GMB ▶');

                                        var $rawPre = $('<pre/>').css({
                                            display: 'none', background: '#1e1e2e', color: '#cdd6f4',
                                            padding: '10px', borderRadius: '4px', fontSize: '10px',
                                            lineHeight: '1.6', maxHeight: '240px', overflowY: 'auto',
                                            whiteSpace: 'pre-wrap', wordBreak: 'break-all',
                                            marginBottom: '8px', fontFamily: 'monospace',
                                        }).text(rawStr);

                                        $rawToggleBtn.on('click', function() {
                                            $rawPre.toggle();
                                            $(this).text($rawPre.is(':visible')
                                                ? '📡 Ocultar respuesta de GMB ▼'
                                                : '📡 Ver respuesta completa de GMB ▶');
                                        });

                                        $body.append($rawToggleBtn).append($rawPre);
                                    }
                                } catch(serErr) {
                                    $body.append($('<p/>').css({ fontSize:'11px', color:'#888' }).text('(raw no serializable)'));
                                }

                                // — Error badge si aplica —
                                if (hasError) {
                                    var errText = entry.raw.error || entry.raw._serializeError || 'Error desconocido';
                                    $body.append(
                                        $('<p/>').css({ margin:'8px 0 0', fontSize:'11px', color:'#dc3232', fontStyle:'italic', padding:'6px 8px', background:'#fff5f5', borderRadius:'3px', border:'1px solid #f5c6c6' })
                                            .text('⚠️ ' + errText)
                                    );
                                }

                                $entry.append($entryHeader).append($body);
                                $container.append($entry);
                            });
                        } catch(renderErr) {
                            if (window.console && window.console.error) {
                                console.error('[OY Address Log] renderLog error:', renderErr);
                            }
                        }
                    }

                    // ── CSS animación para ícono giratorio ────────────────────────────────
                    if (!$('#oy-address-sync-style').length) {
                        $('head').append(
                            '<style id="oy-address-sync-style">' +
                            '@keyframes oy-addr-spin { to { transform: rotate(360deg); } }' +
                            '#oy-address-sync-btn .dashicons.spin { animation: oy-addr-spin 1s linear infinite; display:inline-block; }' +
                            '</style>'
                        );
                    }

                    // ── Toggle panel de log ───────────────────────────────────────────────
                    $(document).on('click', '#oy-address-log-header', function() {
                        var $body = $('#oy-address-log-body');
                        var $icon = $('#oy-address-log-toggle-icon');
                        $body.toggle();
                        $('#oy-address-log-header').css('borderBottomColor', $body.is(':visible') ? '#dadce0' : 'transparent');
                        $icon.text($body.is(':visible') ? '▼' : '▶');
                    });

                    // ── Limpiar log ───────────────────────────────────────────────────────
                    $(document).on('click', '#oy-address-log-clear', function(e) {
                        e.stopPropagation();
                        if (!confirm('<?php echo esc_js( __( '¿Borrar todo el historial de sincronizaciones de dirección?', 'lealez' ) ); ?>')) { return; }
                        clearLog();
                        renderLog();
                    });

                    // ── Escuchar el evento del pipeline para refrescar el log ─────────────
                    // Cuando runFullSync() completa PASO 1 exitosamente, emite
                    // oy:gmb:address:refreshed con source='pipeline'. Actualizamos el panel.
                    $(document).on('oy:gmb:address:refreshed', function(e, data) {
                        // Si vino del pipeline (no del botón individual) ya fue procesado
                        // por oyAddrLogAPI.addLogEntry() dentro del pipeline. Solo re-renderizamos.
                        if (data && data.source === 'pipeline') {
                            renderLog();
                        }
                    });

                    // ── Click handler del botón sync individual ───────────────────────────
                    $(document).on('click', '#oy-address-sync-btn', function(e) {
                        e.preventDefault();
                        if (syncRunning) { return; }

                        // ── Guardia: no disparar si el pipeline completo está corriendo ────
                        // window.oyIntegrationSyncRunning lo establece runFullSync() en el
                        // metabox de Integración GMB — Control de Sincronización.
                        if (window.oyIntegrationSyncRunning) {
                            setAddrMsg('<?php echo esc_js( __( '⚙️ Sincronización completa en progreso. Espera que termine.', 'lealez' ) ); ?>', 'error');
                            return;
                        }

                        var businessId   = $.trim($('#parent_business_id').val()        || '');
                        var locationName = $.trim($('#gmb_location_name').val()         || '');
                        var accountName  = $.trim($('#gmb_location_account_name').val() || '');

                        if (!businessId || !locationName) {
                            setAddrMsg('<?php echo esc_js( __( 'Vincula primero una empresa y ubicación GMB.', 'lealez' ) ); ?>', 'error');
                            return;
                        }

                        // 🔵 Snapshot ANTES de aplicar
                        var snapshotBefore = captureSnapshot();

                        syncRunning = true;
                        var $btn = $('#oy-address-sync-btn');
                        $btn.prop('disabled', true);
                        $btn.find('.dashicons').addClass('spin');
                        setAddrMsg('<?php echo esc_js( __( 'Consultando Google...', 'lealez' ) ); ?>', 'info');

                        if (window.console && window.console.log) {
                            console.log('[OY Address Sync] Iniciando → business:', businessId, '| location:', locationName);
                        }

                        // ── $.ajax() con timeout explícito de 45 s ────────────────────────
                        $.ajax({
                            url:     ajaxUrl,
                            type:    'POST',
                            timeout: 45000,
                            data: {
                                action:        'oy_get_gmb_location_details',
                                nonce:         addrNonce,
                                business_id:   businessId,
                                location_name: locationName,
                                account_name:  accountName,
                            },
                        })
                        .done(function(resp) {
                            resetBtn();

                            try {
                                if (window.console && window.console.log) {
                                    console.log('[OY Address Sync] Respuesta recibida → success:', resp && resp.success);
                                }

                                if (!resp || !resp.success) {
                                    var errMsg = (resp && resp.data && resp.data.message)
                                        ? resp.data.message
                                        : '<?php echo esc_js( __( 'No se pudo importar la dirección.', 'lealez' ) ); ?>';
                                    setAddrMsg(errMsg, 'error');
                                    addLogEntry({
                                        error:   errMsg,
                                        request: { businessId: businessId, locationName: locationName },
                                        rawResp: resp || null,
                                    }, [], 'button');
                                    return;
                                }

                                var loc = (resp.data && resp.data.location) ? resp.data.location : null;
                                if (!loc) {
                                    setAddrMsg('<?php echo esc_js( __( 'Respuesta vacía de GMB.', 'lealez' ) ); ?>', 'error');
                                    addLogEntry({
                                        error:   'Respuesta vacía: location es null',
                                        rawResp: resp.data || null,
                                    }, [], 'button');
                                    return;
                                }

                                // ── 1. Aplicar datos al formulario ────────────────────────
                                if (typeof window.applyLocationToForm === 'function') {
                                    window.applyLocationToForm(loc);
                                } else {
                                    if (loc.storefrontAddress) {
                                        var a = loc.storefrontAddress;
                                        if (a.addressLines && a.addressLines[0]) { $('#location_address_line1').val(a.addressLines[0]); }
                                        if (a.addressLines && a.addressLines[1]) { $('#location_address_line2').val(a.addressLines[1]); }
                                        if (a.sublocality)         { $('#location_neighborhood').val(a.sublocality); }
                                        if (a.locality)            { $('#location_city').val(a.locality); }
                                        if (a.administrativeArea)  { $('#location_state').val(a.administrativeArea); }
                                        if (a.postalCode)          { $('#location_postal_code').val(a.postalCode); }
                                        if (a.regionCode)          { $('#location_country').val(a.regionCode); }
                                    }
                                    if (loc.latlng) {
                                        if (loc.latlng.latitude)  { $('#location_latitude').val(loc.latlng.latitude); }
                                        if (loc.latlng.longitude) { $('#location_longitude').val(loc.latlng.longitude); }
                                    }
                                    if (typeof window.oy_update_map_preview === 'function') {
                                        window.oy_update_map_preview();
                                    }
                                }

                                // ── 2. Áreas de servicio ──────────────────────────────────
                                if (typeof window.oy_service_areas_set === 'function') {
                                    try {
                                        var areas = extractServiceAreas(loc);
                                        if (areas.length) { window.oy_service_areas_set(areas); }
                                    } catch(saErr) {
                                        if (window.console && window.console.error) {
                                            console.error('[OY Address Sync] oy_service_areas_set error:', saErr);
                                        }
                                    }
                                }

                                // ── 3. Snapshot DESPUÉS + Diff + Log ─────────────────────
                                var snapshotAfter = captureSnapshot();
                                var diff = buildDiff(snapshotBefore, snapshotAfter);
                                var changedCount = diff.filter(function(r) {
                                    return r.status === 'changed' || r.status === 'new';
                                }).length;

                                if (window.console && window.console.log) {
                                    console.log('[OY Address Sync] Diff →', changedCount, 'campo(s) con cambios de', diff.length, 'total.');
                                }

                                addLogEntry(loc, diff, 'button');

                                // ── 4. Mensaje de éxito ────────────────────────────────────
                                if (changedCount > 0) {
                                    setAddrMsg('<?php echo esc_js( __( '✅ Dirección importada. Guarda el post para persistir.', 'lealez' ) ); ?>', 'success');
                                } else {
                                    setAddrMsg('<?php echo esc_js( __( '✅ Sin cambios: los datos de GMB coinciden con los actuales.', 'lealez' ) ); ?>', 'success');
                                }

                                // ── 5. Evento para extensibilidad ─────────────────────────
                                try {
                                    $(document).trigger('oy:gmb:address:refreshed', [{ location: loc, diff: diff, source: 'button' }]);
                                } catch(triggerErr) {
                                    if (window.console && window.console.error) {
                                        console.error('[OY Address Sync] trigger error:', triggerErr);
                                    }
                                }

                            } catch(doneErr) {
                                if (window.console && window.console.error) {
                                    console.error('[OY Address Sync] Error en .done():', doneErr);
                                }
                                setAddrMsg('Error inesperado al procesar respuesta. Revisa la consola.', 'error');
                                addLogEntry({ error: 'Error JS en .done(): ' + doneErr.message }, [], 'button');
                            }
                        })
                        .fail(function(xhr, status, error) {
                            resetBtn();

                            var isTimeout  = status === 'timeout';
                            var httpStatus = xhr && xhr.status ? xhr.status : 0;
                            var errDetail  = isTimeout
                                ? 'Timeout: el servidor tardó más de 45 s'
                                : ('HTTP ' + httpStatus + ' — ' + (error || status || 'desconocido'));

                            if (window.console && window.console.error) {
                                console.error('[OY Address Sync] AJAX fail →', status, '| HTTP:', httpStatus, '| error:', error);
                                if (xhr && xhr.responseText) {
                                    console.error('[OY Address Sync] Respuesta cruda:', xhr.responseText.substring(0, 500));
                                }
                            }

                            var userMsg = isTimeout
                                ? '<?php echo esc_js( __( 'Timeout: Google tardó demasiado. Intenta de nuevo.', 'lealez' ) ); ?>'
                                : '<?php echo esc_js( __( 'Error de red al sincronizar. Revisa la consola.', 'lealez' ) ); ?>';

                            setAddrMsg(userMsg, 'error');

                            try {
                                addLogEntry({
                                    error:          errDetail,
                                    xhr_status:     httpStatus,
                                    ajax_status:    status,
                                    responsePreview: xhr && xhr.responseText
                                        ? xhr.responseText.substring(0, 300)
                                        : null,
                                }, [], 'button');
                            } catch(logErr) {
                                if (window.console && window.console.error) {
                                    console.error('[OY Address Sync] Error al registrar en log:', logErr);
                                }
                            }
                        })
                        .always(function() {
                            // Seguridad final: resetea si resetBtn() no fue llamado antes
                            if (syncRunning) { resetBtn(); }
                        });
                    });

                    // ── Inicializar log al cargar ─────────────────────────────────────────
                    $(document).ready(function() {
                        renderLog();
                    });

                    // ── Exponer API pública para que el pipeline pueda alimentar el log ───
                    // El pipeline (runFullSync PASO 1) llama a estas funciones cuando
                    // oy_get_gmb_location_details retorna exitosamente, para que la
                    // entrada aparezca en el log de "Dirección y Geolocalización" también.
                    window.oyAddrLogAPI = {
                        captureSnapshot: captureSnapshot,
                        buildDiff:       buildDiff,
                        addLogEntry:     addLogEntry,
                    };

                })(); // end address sync IIFE

                // ── IIFE: Botón "Enviar a GMB" — Push de dirección ──────────────────
                // Arquitectura: Playbook v1. HTTP 200 = cola; el resultado real viene del polling.
                (function($) {
                    'use strict';

                    var ajaxUrl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
                    var $panel  = $('#oy-address-push-panel');
                    if (!$panel.length) { return; }

                    var postId  = $panel.data('post-id')    || '';
                    var pushRunning = false;

                    // ── Helpers de mensaje ───────────────────────────────────────────
                    function setPushMsg(msg, type) {
                        var colors = { info:'#555', success:'#166534', error:'#dc3232', loading:'#1a73e8' };
                        var $msg = $('#oy-push-state-action-msg');
                        $msg.text(msg).css({ color: colors[type] || '#555', display: msg ? 'block' : 'none' });
                    }

                    function resetPushBtn() {
                        pushRunning = false;
                        var $btn = $('#oy-push-address-btn');
                        $btn.prop('disabled', false);
                        $btn.find('.dashicons').css('animation', '');
                        $btn.find('.dashicons').removeClass('dashicons-update-alt').addClass('dashicons-upload');
                    }

                    // ── Reemplazar panel completo con HTML recibido ──────────────────
                    function replacePanelHtml(html) {
                        if (html) {
                            var $new = $(html);
                            $panel.replaceWith($new);
                            $panel = $new;
                        }
                    }

                    // ── Botón "Enviar a GMB" ─────────────────────────────────────────
                    $(document).on('click', '#oy-push-address-btn', function(e) {
                        e.preventDefault();

                        if (pushRunning) { return; }

                        var $panel = $('#oy-address-push-panel');
                        var nonce  = $panel.data('push-nonce');

                        if (!nonce) {
                            setPushMsg('Error: nonce de push no disponible. Recarga la página.', 'error');
                            return;
                        }

                        // Verificar que el post está guardado (no nuevo post sin ID)
                        if (!postId || postId === '0') {
                            setPushMsg('<?php echo esc_js( __( 'Guarda el post primero antes de publicar en GMB.', 'lealez' ) ); ?>', 'error');
                            return;
                        }

                        pushRunning = true;
                        var $btn = $(this);
                        $btn.prop('disabled', true);
                        $btn.find('.dashicons').removeClass('dashicons-upload').addClass('dashicons-update-alt')
                            .css('animation', 'oy-addr-spin 1s linear infinite');
                        setPushMsg('<?php echo esc_js( __( 'Enviando dirección a Google Business Profile...', 'lealez' ) ); ?>', 'loading');

                        $.ajax({
                            url:     ajaxUrl,
                            type:    'POST',
                            timeout: 60000,
                            data: {
                                action:  'oy_push_address_to_gmb',
                                nonce:   nonce,
                                post_id: postId,
                            },
                        })
                        .done(function(resp) {
                            resetPushBtn();
                            try {
                                if (resp && resp.success) {
                                    setPushMsg(resp.data && resp.data.message ? resp.data.message : '', 'success');
                                    if (resp.data && resp.data.panel_html) {
                                        replacePanelHtml(resp.data.panel_html);
                                    }
                                } else {
                                    var errMsg = (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'Error desconocido al enviar.', 'lealez' ) ); ?>';
                                    setPushMsg(errMsg, 'error');
                                    if (resp && resp.data && resp.data.panel_html) {
                                        replacePanelHtml(resp.data.panel_html);
                                    }
                                }
                            } catch(doneErr) {
                                if (window.console && window.console.error) {
                                    console.error('[OY Push Address] Error en .done():', doneErr);
                                }
                                setPushMsg('Error inesperado al procesar respuesta. Revisa la consola.', 'error');
                            }
                        })
                        .fail(function(xhr, status, error) {
                            resetPushBtn();
                            var isTimeout  = status === 'timeout';
                            var httpStatus = xhr && xhr.status ? xhr.status : 0;
                            if (window.console && window.console.error) {
                                console.error('[OY Push Address] AJAX fail → HTTP:', httpStatus, '| error:', error);
                            }
                            var userMsg = isTimeout
                                ? '<?php echo esc_js( __( 'Timeout: Google tardó demasiado. Intenta de nuevo.', 'lealez' ) ); ?>'
                                : '<?php echo esc_js( __( 'Error de red al enviar. Revisa la consola.', 'lealez' ) ); ?>';
                            setPushMsg(userMsg, 'error');
                        });
                    });

                    // ── Botón "Verificar estado" ─────────────────────────────────────
                    $(document).on('click', '#oy-check-push-status-btn', function(e) {
                        e.preventDefault();
                        var $btn    = $(this);
                        var $panel  = $('#oy-address-push-panel');
                        var nonce   = $panel.data('check-nonce');

                        if (!nonce) {
                            setPushMsg('Error: nonce de verificación no disponible. Recarga la página.', 'error');
                            return;
                        }

                        $btn.prop('disabled', true);
                        $btn.find('.dashicons').css('animation', 'oy-addr-spin 1s linear infinite');
                        setPushMsg('<?php echo esc_js( __( 'Consultando Google Business Profile...', 'lealez' ) ); ?>', 'loading');

                        $.ajax({
                            url:     ajaxUrl,
                            type:    'POST',
                            timeout: 45000,
                            data: {
                                action:  'oy_check_address_push_status',
                                nonce:   nonce,
                                post_id: postId,
                            },
                        })
                        .done(function(resp) {
                            $btn.prop('disabled', false);
                            $btn.find('.dashicons').css('animation', '');
                            if (window.console && window.console.log) {
                                console.log('[OY Push Address] Verificación →', resp);
                            }
                            if (resp && resp.success && resp.data && resp.data.panel_html) {
                                replacePanelHtml(resp.data.panel_html);
                                setPushMsg('', 'info');
                            } else {
                                var errMsg = (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'Error desconocido al verificar.', 'lealez' ) ); ?>';
                                setPushMsg(errMsg, 'error');
                            }
                        })
                        .fail(function(xhr, status, error) {
                            $btn.prop('disabled', false);
                            $btn.find('.dashicons').css('animation', '');
                            if (window.console && window.console.error) {
                                console.error('[OY Push Address] Verificación AJAX fail:', status, error);
                            }
                            setPushMsg('<?php echo esc_js( __( 'Error de red al verificar. Intenta de nuevo.', 'lealez' ) ); ?>', 'error');
                        });
                    });

                })(jQuery);
                // ── FIN: Push de dirección ───────────────────────────────────────────

            </script>
            <?php
        }
    }
}
