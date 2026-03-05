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

                    // Prioridades “humanas”
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

                    // Si hay address formateado, lo preferimos como fallback “humano”
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
            ?>
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
                    <strong><?php _e( 'Sin ubicación física — solo envíos y servicios en el hogar', 'lealez' ); ?></strong>
                </label>

                <div id="oy-show-address-row"
                     style="display:<?php echo $show_address_row ? 'flex' : 'none'; ?>; align-items:center; gap:10px; margin-top:6px;">
                    <label class="oy-toggle-label" style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox"
                               name="show_address_to_customers"
                               id="show_address_to_customers"
                               value="1"
                            <?php checked( $show_address, '1' ); ?>>
                        <?php _e( 'Mostrar la dirección de la empresa a los clientes', 'lealez' ); ?>
                    </label>
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

            </div><!-- #oy-address-map-layout ?>

            <script type="text/javascript">
                // Vars para AJAX del autocomplete
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

                    // ✅ Expuesto para applyLocationToForm
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
            </script>
            <?php
        }
    }
}
