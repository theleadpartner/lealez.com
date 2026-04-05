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

            // ✅ AJAX: Enviar dirección desde Lealez hacia GMB (push inverso)
            add_action( 'wp_ajax_oy_push_address_to_gmb', array( $this, 'ajax_push_address_to_gmb' ) );
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
 * AJAX: Envía la dirección guardada en Lealez hacia Google Business Profile (push).
 *
 * Acción:   oy_push_address_to_gmb
 * Nonce:    oy_push_address_gmb_{post_id}
 *
 * Lee los campos de dirección desde post_meta (ya guardados en WP) y ejecuta
 * un PATCH a Business Information API v1 actualizando únicamente storefrontAddress.
 * Tras el PATCH, realiza un GET de verificación para confirmar si los cambios fueron
 * realmente aplicados por Google. Informa al frontend sobre hasPendingEdits y el tipo
 * de negocio para mostrar mensajes apropiados.
 *
 * IMPORTANTE:
 * - latlng NO se envía. Las coordenadas son calculadas automáticamente por Google
 *   al cambiar la dirección. Enviar latlng causa HTTP 400 en ubicaciones verificadas.
 * - serviceArea NO se envía. Las áreas de servicio en Lealez son texto libre,
 *   pero la API de GMB requiere resource names de Places (ej: places/ChIJ...).
 * - Para negocios CUSTOMER_LOCATION_ONLY (SAB): Google puede aceptar el PATCH con
 *   hasPendingEdits: true y la dirección NO aparecer en el perfil público si el
 *   toggle "Mostrar dirección" está desactivado en el panel de GBP.
 *
 * @return void Responde con wp_send_json_success() o wp_send_json_error().
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

    // ── Obtener business_id y location_name ───────────────────────────────
    $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
    $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );

    if ( ! $business_id || '' === $location_name ) {
        wp_send_json_error( array( 'message' => __( 'Esta ubicación no tiene empresa o ubicación GMB vinculada. Vincula primero en el metabox de Integración GMB.', 'lealez' ) ) );
    }

    if ( ! class_exists( 'Lealez_GMB_API' ) ) {
        wp_send_json_error( array( 'message' => __( 'Lealez_GMB_API no está disponible.', 'lealez' ) ) );
    }

    if ( ! method_exists( 'Lealez_GMB_API', 'update_location_address' ) ) {
        wp_send_json_error( array( 'message' => __( 'El método update_location_address no existe en Lealez_GMB_API. Actualiza el plugin.', 'lealez' ) ) );
    }

    // ── Leer campos de dirección desde post_meta (ya guardados por WP) ───
    // NOTA: latitude y longitude NO se incluyen en el payload a GMB.
    // La API de Google rechaza latlng en ubicaciones verificadas (HTTP 400).
    // Google recalcula las coordenadas automáticamente al actualizar storefrontAddress.
    $address_line1 = (string) get_post_meta( $post_id, 'location_address_line1', true );
    $address_line2 = (string) get_post_meta( $post_id, 'location_address_line2', true );
    $city          = (string) get_post_meta( $post_id, 'location_city', true );
    $state         = (string) get_post_meta( $post_id, 'location_state', true );
    $country       = (string) get_post_meta( $post_id, 'location_country', true );
    $postal_code   = (string) get_post_meta( $post_id, 'location_postal_code', true );

    // regionCode es obligatorio en la API de GMB
    if ( '' === trim( $country ) ) {
        wp_send_json_error( array(
            'message' => __( 'El campo "País (ISO 2)" es obligatorio para enviar a GMB. Guarda primero el post con el código del país (ej: CO, MX, US).', 'lealez' ),
        ) );
    }

    // ── Construir payload (solo storefrontAddress, sin latlng) ───────────
    $address_lines = array_values( array_filter(
        array( trim( $address_line1 ), trim( $address_line2 ) ),
        function ( $l ) { return '' !== $l; }
    ) );

    $address_data = array(
        'regionCode'         => strtoupper( trim( $country ) ),
        'addressLines'       => $address_lines,
        'locality'           => trim( $city ),
        'administrativeArea' => trim( $state ),
        'postalCode'         => trim( $postal_code ),
    );

    // ── Debug: log payload antes de enviar ────────────────────────────────
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( sprintf(
            '[OY GMB Address] ajax_push_address_to_gmb ── post_id=%d | location=%s | payload=%s',
            $post_id,
            $location_name,
            wp_json_encode( $address_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        ) );
    }

    // ── Llamar a la API ───────────────────────────────────────────────────
    $result = Lealez_GMB_API::update_location_address( $business_id, $location_name, $address_data );

    if ( is_wp_error( $result ) ) {
        $err_msg  = $result->get_error_message();
        $err_code = $result->get_error_code();
        $err_data = $result->get_error_data();

        // Exponer raw body de Google en modo debug para facilitar diagnóstico
        $raw_body_preview = '';
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && is_array( $err_data ) && ! empty( $err_data['raw_body'] ) ) {
            $raw_body_preview = substr( (string) $err_data['raw_body'], 0, 500 );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[OY GMB Address] ajax_push_address_to_gmb ── WP_Error: ' . $err_msg
                . ( $raw_body_preview ? ' | raw: ' . $raw_body_preview : '' ) );
        }

        $response = array(
            'message' => $err_msg,
            'code'    => $err_code,
        );

        if ( '' !== $raw_body_preview ) {
            $response['debug_raw'] = $raw_body_preview;
        }

        wp_send_json_error( $response );
    }

    // ── Desempaquetar el array enriquecido devuelto por update_location_address ──
    // update_location_address devuelve un array (no el raw response) para transportar
    // flags de diagnóstico (hasPendingEdits, verificación, etc.).
    $patch_result      = isset( $result['patch_result'] )      ? $result['patch_result']      : $result;
    $has_pending_edits = ! empty( $result['has_pending_edits'] );
    $is_sab            = ! empty( $result['is_sab'] );
    $address_matched   = ! empty( $result['address_matched'] );
    $verify_address    = isset( $result['verify_address'] )    ? $result['verify_address']    : null;
    $business_type     = isset( $result['business_type'] )     ? (string) $result['business_type'] : '';

    // ── Debug: resumen del resultado ──────────────────────────────────────
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( sprintf(
            '[OY GMB Address] ajax_push_address_to_gmb ── RESULT: hasPendingEdits=%s | isSAB=%s | businessType="%s" | addressMatched=%s | verifyAddress=%s',
            $has_pending_edits ? 'true' : 'false',
            $is_sab            ? 'true' : 'false',
            $business_type     ?: '(absent)',
            $address_matched   ? 'true' : 'false',
            wp_json_encode( $verify_address, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        ) );
    }

    // ── Éxito — construir pushed_fields para el log del frontend ─────────
    $pushed_fields = array(
        'regionCode'         => $address_data['regionCode'],
        'addressLines'       => $address_data['addressLines'],
        'locality'           => $address_data['locality'],
        'administrativeArea' => $address_data['administrativeArea'],
        'postalCode'         => $address_data['postalCode'],
    );

    wp_send_json_success( array(
        'message'           => __( 'Dirección enviada a GMB correctamente.', 'lealez' ),
        'pushed_fields'     => $pushed_fields,
        'gmb_response'      => $patch_result,
        'has_pending_edits' => $has_pending_edits,
        'is_sab'            => $is_sab,
        'address_matched'   => $address_matched,
        'verify_address'    => $verify_address,
        'business_type'     => $business_type,
    ) );
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

<?php
            // Nonce dedicado para el push (distinto del pull para mayor seguridad)
            $push_nonce = wp_create_nonce( 'oy_push_address_gmb_' . $post->ID );
            ?>

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
                <?php /* ── Sección: Importar desde GMB ── */ ?>
                <button type="button"
                        id="oy-address-sync-btn"
                        class="button button-secondary"
                        <?php echo $addr_gmb_connected ? '' : 'disabled'; ?>
                        style="display:inline-flex; align-items:center; gap:6px;">
                    <span class="dashicons dashicons-download" style="margin-top:3px;"></span>
                    <?php _e( '← Importar desde GMB', 'lealez' ); ?>
                </button>
                <span id="oy-address-sync-msg" style="font-size:12px; color:#555;"></span>

                <?php /* ── Separador visual ── */ ?>
                <span style="color:#dadce0; font-size:18px; line-height:1; user-select:none;">|</span>

                <?php /* ── Sección: Enviar a GMB ── */ ?>
                <button type="button"
                        id="oy-address-push-btn"
                        class="button"
                        <?php echo $addr_gmb_connected ? '' : 'disabled'; ?>
                        style="display:inline-flex; align-items:center; gap:6px; background:#1a73e8; color:#fff; border-color:#1558b0;">
                    <span class="dashicons dashicons-upload" style="margin-top:3px;"></span>
                    <?php _e( 'Publicar en GMB →', 'lealez' ); ?>
                </button>
                <span id="oy-address-push-msg" style="font-size:12px; color:#555;"></span>

                <?php if ( ! $addr_gmb_connected ) : ?>
                    <span style="font-size:11px; color:#999; font-style:italic; width:100%;">
                        <?php _e( '(Requiere empresa y ubicación GMB vinculadas)', 'lealez' ); ?>
                    </span>
                <?php else : ?>
                    <span style="font-size:11px; color:#888; font-style:italic; width:100%;">
                        <?php _e( '⚠️ Para <strong>Publicar en GMB</strong>: primero guarda el post (Actualizar), luego presiona el botón. No se envían las Áreas de Servicio (requieren Place IDs de Google).', 'lealez' ); ?>
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
                                    }).text('Aún no hay entradas registradas. Usa "← Importar desde GMB" para traer datos, "Publicar en GMB →" para enviar los guardados, o ejecuta la Sync completa.')

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
var srcIcon, srcLabel;
                                if (entry.source === 'pipeline') {
                                    srcIcon  = '⚙️';
                                    srcLabel = ' · Sync completa';
                                } else if (entry.source === 'push') {
                                    srcIcon  = '📤';
                                    srcLabel = ' · Publicado en GMB';
                                } else {
                                    srcIcon  = '🔵';
                                    srcLabel = ' · Importado desde GMB';
                                }

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
                                    statusLabel = (entry.source === 'push') ? '❌ Error al publicar' : '❌ Error';
                                    statusColor = '#dc3232';
                                } else if (entry.source === 'push') {
                                    statusLabel = '📤 Publicado en GMB';
                                    statusColor = '#1a73e8';
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
                            '#oy-address-sync-btn .dashicons.spin,' +
                            '#oy-address-push-btn .dashicons.spin { animation: oy-addr-spin 1s linear infinite; display:inline-block; }' +
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

(function(){
                    var $ = jQuery;
                    var ajaxUrl    = (typeof ajaxurl !== 'undefined') ? ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
                    var pushNonce  = '<?php echo esc_js( $push_nonce ); ?>';
                    var postId     = '<?php echo esc_js( (string) $post->ID ); ?>';
                    var pushRunning = false;

                    // ── Helpers de UI ─────────────────────────────────────────────────────
                    function setPushMsg( msg, type ) {
                        var colors = { info: '#555', success: '#46b450', error: '#dc3232', blue: '#1a73e8' };
                        $('#oy-address-push-msg').text( msg ).css( 'color', colors[type] || '#555' );
                    }

                    function resetPushBtn() {
                        pushRunning = false;
                        var $b = $('#oy-address-push-btn');
                        $b.prop( 'disabled', false );
                        $b.find('.dashicons').removeClass('spin');
                    }

                    // ── Click handler del botón "Publicar en GMB →" ───────────────────────
                    $(document).on('click', '#oy-address-push-btn', function(e) {
                        e.preventDefault();
                        if (pushRunning) { return; }

                        // Guardia: no disparar si el pipeline completo está corriendo
                        if (window.oyIntegrationSyncRunning) {
                            setPushMsg('<?php echo esc_js( __( '⚙️ Sincronización completa en progreso. Espera que termine.', 'lealez' ) ); ?>', 'error');
                            return;
                        }

                        var businessId   = $.trim( $('#parent_business_id').val()  || '' );
                        var locationName = $.trim( $('#gmb_location_name').val()   || '' );

                        if ( !businessId || !locationName ) {
                            setPushMsg('<?php echo esc_js( __( 'Vincula primero una empresa y ubicación GMB.', 'lealez' ) ); ?>', 'error');
                            return;
                        }

                        // Confirmar antes de sobrescribir datos en GMB
                        if ( !confirm('<?php echo esc_js( __( '¿Enviar la dirección guardada en Lealez a Google Business Profile? Esto sobreescribirá los datos de dirección actuales en GMB.', 'lealez' ) ); ?>') ) {
                            return;
                        }

                        pushRunning = true;
                        var $btn = $('#oy-address-push-btn');
                        $btn.prop('disabled', true);
                        $btn.find('.dashicons').addClass('spin');
                        setPushMsg('<?php echo esc_js( __( 'Enviando a Google...', 'lealez' ) ); ?>', 'info');

                        if (window.console && window.console.log) {
                            console.log('[OY Address Push] Iniciando push → postId:', postId, '| location:', locationName);
                        }

                        $.ajax({
                            url:     ajaxUrl,
                            type:    'POST',
                            timeout: 45000,
                            data: {
                                action:  'oy_push_address_to_gmb',
                                nonce:   pushNonce,
                                post_id: postId,
                            },
                        })
.done(function(resp) {
                            resetPushBtn();

                            try {
                                if (window.console && window.console.log) {
                                    console.log('[OY Address Push] Respuesta recibida → success:', resp && resp.success, '| data:', resp && resp.data);
                                }

                                if (!resp || !resp.success) {
                                    var errMsg = (resp && resp.data && resp.data.message)
                                        ? resp.data.message
                                        : '<?php echo esc_js( __( 'No se pudo enviar la dirección a GMB.', 'lealez' ) ); ?>';
                                    setPushMsg(errMsg, 'error');

                                    // Registrar error en log
                                    if (window.oyAddrLogAPI && typeof window.oyAddrLogAPI.addLogEntry === 'function') {
                                        window.oyAddrLogAPI.addLogEntry(
                                            { error: errMsg, rawResp: resp || null },
                                            [],
                                            'push'
                                        );
                                    }
                                    return;
                                }

                                var pushed         = (resp.data && resp.data.pushed_fields)    ? resp.data.pushed_fields    : {};
                                var hasPending     = !!(resp.data && resp.data.has_pending_edits);
                                var isSab          = !!(resp.data && resp.data.is_sab);
                                var addressMatched = !!(resp.data && resp.data.address_matched);
                                var verifyAddr     = (resp.data && resp.data.verify_address)   ? resp.data.verify_address   : null;
                                var businessType   = (resp.data && resp.data.business_type)    ? resp.data.business_type    : '';
                                var verifyRan      = (resp.data && typeof resp.data.address_matched !== 'undefined');

                                if (window.console && window.console.log) {
                                    console.log('[OY Address Push] Diagnóstico → hasPendingEdits:', hasPending, '| isSAB:', isSab, '| businessType:', businessType, '| addressMatched:', addressMatched, '| verifyAddress:', verifyAddr);
                                }

                                // ── Mensaje de resultado según estado real ────────────────────
                                var successMsg, msgType;

                                if (hasPending) {
                                    successMsg = '<?php echo esc_js( __( '⚠️ Solicitud enviada a GMB, pero Google tiene cambios pendientes de revisión (hasPendingEdits). Los datos pueden tardar horas en aparecer o estar en cola de moderación. Verifica directamente en el panel de Google Business Profile.', 'lealez' ) ); ?>';
                                    msgType    = 'blue';
                                } else if (isSab && null === verifyAddr) {
                                    successMsg = '<?php echo esc_js( __( '⚠️ Enviado a GMB. Este es un negocio de tipo "Área de Servicio" — Google almacena la dirección internamente pero puede no mostrarla en el perfil público si el toggle "Mostrar dirección" está desactivado en GBP.', 'lealez' ) ); ?>';
                                    msgType    = 'blue';
                                } else if (verifyRan && !addressMatched && null === verifyAddr) {
                                    successMsg = '<?php echo esc_js( __( '⚠️ Enviado a GMB, pero la verificación no encontró storefrontAddress en el perfil. Google puede estar procesando el cambio o rechazarlo silenciosamente. Revisa en Google Business Profile en unos minutos.', 'lealez' ) ); ?>';
                                    msgType    = 'blue';
                                } else if (verifyRan && !addressMatched && null !== verifyAddr) {
                                    successMsg = '<?php echo esc_js( __( '⚠️ Enviado a GMB, pero la verificación detectó diferencias entre lo enviado y lo guardado. Los cambios pueden tardar unos minutos. Revisa en Google Business Profile.', 'lealez' ) ); ?>';
                                    msgType    = 'blue';
                                } else {
                                    successMsg = '<?php echo esc_js( __( '✅ Dirección publicada en GMB y verificada correctamente.', 'lealez' ) ); ?>';
                                    msgType    = 'success';
                                }

                                setPushMsg(successMsg, msgType);

                                // Registrar en log (reutiliza el sistema del pull)
                                if (window.oyAddrLogAPI && typeof window.oyAddrLogAPI.addLogEntry === 'function') {
                                    var logRaw = {
                                        pushed_fields:    pushed,
                                        gmb_response:     resp.data.gmb_response    || null,
                                        has_pending_edits: hasPending,
                                        is_sab:           isSab,
                                        address_matched:  addressMatched,
                                        verify_address:   verifyAddr,
                                        business_type:    businessType,
                                    };
                                    window.oyAddrLogAPI.addLogEntry( logRaw, [], 'push' );
                                }

                                // Emitir evento para extensibilidad futura
                                try {
                                    $(document).trigger('oy:gmb:address:pushed', [{ pushed_fields: pushed, response: resp.data }]);
                                } catch(triggerErr) {
                                    if (window.console && window.console.error) {
                                        console.error('[OY Address Push] trigger error:', triggerErr);
                                    }
                                }

                            } catch(doneErr) {
                                if (window.console && window.console.error) {
                                    console.error('[OY Address Push] Error en .done():', doneErr);
                                }
                                setPushMsg('Error inesperado al procesar respuesta. Revisa la consola.', 'error');
                            }
                        })
                        .fail(function(xhr, status, error) {
                            resetPushBtn();

                            var isTimeout  = status === 'timeout';
                            var httpStatus = xhr && xhr.status ? xhr.status : 0;
                            var errDetail  = isTimeout
                                ? 'Timeout: el servidor tardó más de 45 s'
                                : ('HTTP ' + httpStatus + ' — ' + (error || status || 'desconocido'));

                            if (window.console && window.console.error) {
                                console.error('[OY Address Push] AJAX fail →', status, '| HTTP:', httpStatus, '| error:', error);
                                if (xhr && xhr.responseText) {
                                    console.error('[OY Address Push] Respuesta cruda:', xhr.responseText.substring(0, 500));
                                }
                            }

                            var userMsg = isTimeout
                                ? '<?php echo esc_js( __( 'Timeout: Google tardó demasiado. Intenta de nuevo.', 'lealez' ) ); ?>'
                                : '<?php echo esc_js( __( 'Error de red al enviar. Revisa la consola.', 'lealez' ) ); ?>';

                            setPushMsg(userMsg, 'error');

                            try {
                                if (window.oyAddrLogAPI && typeof window.oyAddrLogAPI.addLogEntry === 'function') {
                                    window.oyAddrLogAPI.addLogEntry({
                                        error:           errDetail,
                                        xhr_status:      httpStatus,
                                        ajax_status:     status,
                                        responsePreview: xhr && xhr.responseText
                                            ? xhr.responseText.substring(0, 300)
                                            : null,
                                    }, [], 'push');
                                }
                            } catch(logErr) {
                                if (window.console && window.console.error) {
                                    console.error('[OY Address Push] Error al registrar en log:', logErr);
                                }
                            }
                        })
                        .always(function() {
                            // Seguridad final: resetea si .done()/.fail() no lo hicieron
                            if (pushRunning) { resetPushBtn(); }
                        });
                    });

                })(); // end address push IIFE
                
            </script>
            <?php
        }
    }
}
