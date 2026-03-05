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
         * Nonce name/action usados por OY_Location_CPT
         * (reutilizamos el mismo para no romper el flujo de guardado)
         */
        private $nonce_name   = 'oy_location_meta_nonce';
        private $nonce_action = 'oy_location_save_meta';

        /**
         * Constructor
         */
        public function __construct() {
            add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );

            /**
             * ✅ Guardado propio SOLO para campos de este metabox que NO están
             * contemplados en el save_meta_boxes del CPT.
             *
             * Importante:
             * - No tocamos otros metas para evitar interferencia.
             * - Usamos el mismo nonce del CPT.
             */
            add_action( 'save_post_oy_location', array( $this, 'save_meta_box' ), 18, 2 );
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
         * ✅ Parse helper: convertir serviceArea RAW (Google) a lista "humana" de labels.
         *
         * La API de GBP puede variar la forma:
         * - serviceArea.places.placeInfos[] con placeName / name / placeId
         * - serviceArea.places.placeNames[] (strings)
         * - otros casos: usamos placeId o regionCode como fallback
         *
         * @param array $service_area_raw
         * @return array array de strings (labels) únicos
         */
        private function parse_service_area_labels_from_raw( $service_area_raw ) {
            if ( ! is_array( $service_area_raw ) || empty( $service_area_raw ) ) {
                return array();
            }

            $labels = array();

            // Caso A: places.placeInfos[]
            if (
                isset( $service_area_raw['places'] )
                && is_array( $service_area_raw['places'] )
                && isset( $service_area_raw['places']['placeInfos'] )
                && is_array( $service_area_raw['places']['placeInfos'] )
            ) {
                foreach ( $service_area_raw['places']['placeInfos'] as $pi ) {
                    if ( ! is_array( $pi ) ) {
                        continue;
                    }

                    $label = '';
                    if ( ! empty( $pi['placeName'] ) ) {
                        $label = (string) $pi['placeName'];
                    } elseif ( ! empty( $pi['displayName'] ) ) {
                        $label = (string) $pi['displayName'];
                    } elseif ( ! empty( $pi['name'] ) ) {
                        $label = (string) $pi['name'];
                    } elseif ( ! empty( $pi['placeId'] ) ) {
                        $label = (string) $pi['placeId'];
                    }

                    $label = trim( $label );
                    if ( '' !== $label ) {
                        $labels[] = $label;
                    }
                }
            }

            // Caso B: places.placeNames[] (strings)
            if (
                isset( $service_area_raw['places'] )
                && is_array( $service_area_raw['places'] )
                && isset( $service_area_raw['places']['placeNames'] )
                && is_array( $service_area_raw['places']['placeNames'] )
            ) {
                foreach ( $service_area_raw['places']['placeNames'] as $pn ) {
                    $pn = trim( (string) $pn );
                    if ( '' !== $pn ) {
                        $labels[] = $pn;
                    }
                }
            }

            // Caso C: places[] directo
            if ( isset( $service_area_raw['placeInfos'] ) && is_array( $service_area_raw['placeInfos'] ) ) {
                foreach ( $service_area_raw['placeInfos'] as $pi ) {
                    if ( ! is_array( $pi ) ) {
                        continue;
                    }
                    $label = '';
                    if ( ! empty( $pi['placeName'] ) ) {
                        $label = (string) $pi['placeName'];
                    } elseif ( ! empty( $pi['displayName'] ) ) {
                        $label = (string) $pi['displayName'];
                    } elseif ( ! empty( $pi['name'] ) ) {
                        $label = (string) $pi['name'];
                    } elseif ( ! empty( $pi['placeId'] ) ) {
                        $label = (string) $pi['placeId'];
                    }
                    $label = trim( $label );
                    if ( '' !== $label ) {
                        $labels[] = $label;
                    }
                }
            }

            // Fallback: si no sacamos nada, usar regionCode/placeId
            if ( empty( $labels ) ) {
                if ( ! empty( $service_area_raw['regionCode'] ) ) {
                    $labels[] = trim( (string) $service_area_raw['regionCode'] );
                }
                if ( ! empty( $service_area_raw['placeId'] ) ) {
                    $labels[] = trim( (string) $service_area_raw['placeId'] );
                }
            }

            // Normalizar: unique + limpiar
            $labels = array_map(
                function( $v ) {
                    $v = trim( (string) $v );
                    $v = preg_replace( '/\s{2,}/', ' ', $v );
                    return $v;
                },
                $labels
            );

            $labels = array_values( array_filter( array_unique( $labels ) ) );

            return $labels;
        }

        /**
         * Save metabox (solo service areas)
         *
         * @param int     $post_id
         * @param WP_Post $post
         */
        public function save_meta_box( $post_id, $post ) {

            // Security checks
            if ( ! isset( $_POST[ $this->nonce_name ] ) || ! wp_verify_nonce( $_POST[ $this->nonce_name ], $this->nonce_action ) ) {
                return;
            }

            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }

            // ✅ Guardar location_service_areas[] (chips)
            if ( isset( $_POST['location_service_areas'] ) && is_array( $_POST['location_service_areas'] ) ) {
                $raw = wp_unslash( $_POST['location_service_areas'] );

                $clean = array();
                foreach ( $raw as $v ) {
                    $v = sanitize_text_field( (string) $v );
                    $v = trim( preg_replace( '/\s{2,}/', ' ', $v ) );
                    if ( '' !== $v ) {
                        $clean[] = $v;
                    }
                }

                $clean = array_values( array_filter( array_unique( $clean ) ) );

                update_post_meta( $post_id, 'location_service_areas', $clean );

                // Si hay algo, marcamos flag (útil para UI/validaciones)
                if ( ! empty( $clean ) ) {
                    update_post_meta( $post_id, 'service_area_enabled', '1' );
                } else {
                    // Si el usuario lo dejó vacío manualmente, no borramos gmb_service_area_raw,
                    // solo indicamos que no hay selección manual.
                    delete_post_meta( $post_id, 'service_area_enabled' );
                }
            } else {
                // Si no viene el array, no borramos nada automáticamente para evitar pérdida accidental.
                // (ej: guardado parcial o conflicto de metaboxes)
            }
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

            // ✅ Service areas (campo humano)
            $service_areas = get_post_meta( $post->ID, 'location_service_areas', true );
            if ( ! is_array( $service_areas ) ) {
                $service_areas = array();
            }

            // ✅ Fallback: si no hay lista humana, intentar derivarla de RAW de GMB
            if ( empty( $service_areas ) ) {
                $gmb_service_area_raw = get_post_meta( $post->ID, 'gmb_service_area_raw', true );
                if ( is_array( $gmb_service_area_raw ) ) {
                    $derived = $this->parse_service_area_labels_from_raw( $gmb_service_area_raw );
                    if ( ! empty( $derived ) ) {
                        $service_areas = $derived;

                        // Persistimos para que ya quede “humano” en el post
                        update_post_meta( $post->ID, 'location_service_areas', $service_areas );
                        update_post_meta( $post->ID, 'service_area_enabled', '1' );
                    }
                }
            }

            // ✅ Fallback coords desde gmb_latlng_raw
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

            // Build initial map embed URL
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
            ?>

            <?php /* ── Ubicación de la empresa (alineado con GMB) ── */ ?>
            <div style="background:#f0f6fc; border:1px solid #c3d4e6; border-radius:4px; padding:14px 16px; margin-bottom:16px;">
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

            <?php /* ── Áreas de servicio (UI tipo GMB) ── */ ?>
            <div style="background:#fff; border:1px solid #e2e4e7; border-radius:4px; padding:14px 16px; margin-bottom:20px;">
                <h4 style="margin:0 0 6px; font-size:14px; color:#1d2327;">
                    🗺️ <?php _e( 'Áreas de servicio (Google)', 'lealez' ); ?>
                </h4>
                <p class="description" style="margin:0 0 12px;">
                    <?php _e( 'Define en qué ciudades / regiones / países prestas servicio. Se sincroniza desde GMB (<code>serviceArea</code>) y puedes ajustarlo manualmente aquí. La UI está preparada para múltiples áreas, igual que en Google.', 'lealez' ); ?>
                </p>

                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
                    <input type="text"
                           id="oy-service-area-input"
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'Ej: Barranquilla, Atlántico, Colombia', 'lealez' ); ?>"
                           style="min-width:280px; flex:1;">
                    <button type="button" class="button" id="oy-service-area-add">+ <?php _e( 'Agregar', 'lealez' ); ?></button>
                </div>

                <div id="oy-service-area-tags" style="display:flex; gap:8px; flex-wrap:wrap;">
                    <?php if ( ! empty( $service_areas ) ) : ?>
                        <?php foreach ( $service_areas as $sa ) :
                            $sa = trim( (string) $sa );
                            if ( '' === $sa ) { continue; }
                            ?>
                            <span class="oy-sa-tag" data-value="<?php echo esc_attr( $sa ); ?>" style="display:inline-flex;align-items:center;gap:8px;background:#f0f6fc;border:1px solid #c3d4e6;border-radius:18px;padding:6px 10px;font-size:12px;">
                                <span class="oy-sa-text"><?php echo esc_html( $sa ); ?></span>
                                <button type="button" class="button-link oy-sa-remove" style="color:#dc3232;text-decoration:none;font-weight:700;line-height:1;">✕</button>
                                <input type="hidden" name="location_service_areas[]" value="<?php echo esc_attr( $sa ); ?>">
                            </span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <p class="description" style="margin-top:10px;">
                    <?php _e( 'Tip: si presionas Enter en el campo, también se agrega el área.', 'lealez' ); ?>
                </p>
            </div>

            <?php /* ── Layout de dos columnas: Campos | Mapa (igual a la UI de GMB) ── */ ?>
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
                                    <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.sublocality</code> (si disponible). ⚙️ También editable manualmente.', 'lealez' ); ?></p>
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
                                    <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.regionCode</code> (ISO 3166-1 alpha-2).', 'lealez' ); ?></p>
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
                                    <?php _e( 'Auto-importado desde GMB: <code>metadata.mapsUri</code>. Se llena automáticamente al sincronizar con Google My Business.', 'lealez' ); ?>
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

                <?php /* ── Columna derecha: mapa embebido al estilo GMB ── */ ?>
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

                /**
                 * ✅ UI helper: Service Areas (chips)
                 * Exponemos una función global para que applyLocationToForm pueda usarla.
                 */
                window.oy_apply_service_areas = function(loc) {
                    try {
                        var $ = jQuery;
                        if (!loc) return;

                        var labels = [];

                        var sa = loc.serviceArea || null;

                        // Estructuras posibles
                        if (sa && typeof sa === 'object') {

                            // Caso A: sa.places.placeInfos[]
                            if (sa.places && sa.places.placeInfos && Array.isArray(sa.places.placeInfos)) {
                                sa.places.placeInfos.forEach(function(pi){
                                    if(!pi) return;
                                    var l = '';
                                    if (pi.placeName) l = String(pi.placeName);
                                    else if (pi.displayName) l = String(pi.displayName);
                                    else if (pi.name) l = String(pi.name);
                                    else if (pi.placeId) l = String(pi.placeId);
                                    l = l.trim();
                                    if (l) labels.push(l);
                                });
                            }

                            // Caso B: sa.places.placeNames[]
                            if (sa.places && sa.places.placeNames && Array.isArray(sa.places.placeNames)) {
                                sa.places.placeNames.forEach(function(pn){
                                    pn = String(pn || '').trim();
                                    if (pn) labels.push(pn);
                                });
                            }

                            // Caso C: sa.placeInfos[]
                            if (sa.placeInfos && Array.isArray(sa.placeInfos)) {
                                sa.placeInfos.forEach(function(pi){
                                    if(!pi) return;
                                    var l = '';
                                    if (pi.placeName) l = String(pi.placeName);
                                    else if (pi.displayName) l = String(pi.displayName);
                                    else if (pi.name) l = String(pi.name);
                                    else if (pi.placeId) l = String(pi.placeId);
                                    l = l.trim();
                                    if (l) labels.push(l);
                                });
                            }

                            // Fallback regionCode/placeId
                            if (!labels.length) {
                                if (sa.regionCode) labels.push(String(sa.regionCode).trim());
                                if (sa.placeId) labels.push(String(sa.placeId).trim());
                            }
                        }

                        // Normalizar unique
                        var map = {};
                        var out = [];
                        labels.forEach(function(v){
                            v = String(v || '').trim().replace(/\s{2,}/g,' ');
                            if (!v) return;
                            if (map[v]) return;
                            map[v] = true;
                            out.push(v);
                        });

                        // Pintar chips
                        var $wrap = $('#oy-service-area-tags');
                        if (!$wrap.length) return;

                        // Limpiar chips actuales
                        $wrap.find('.oy-sa-tag').remove();

                        // Render
                        out.forEach(function(v){
                            var chip =
                                '<span class="oy-sa-tag" data-value="'+ $('<div>').text(v).html() +'" ' +
                                'style="display:inline-flex;align-items:center;gap:8px;background:#f0f6fc;border:1px solid #c3d4e6;border-radius:18px;padding:6px 10px;font-size:12px;">' +
                                    '<span class="oy-sa-text">'+ $('<div>').text(v).html() +'</span>' +
                                    '<button type="button" class="button-link oy-sa-remove" style="color:#dc3232;text-decoration:none;font-weight:700;line-height:1;">✕</button>' +
                                    '<input type="hidden" name="location_service_areas[]" value="'+ $('<div>').text(v).html() +'">' +
                                '</span>';
                            $wrap.append(chip);
                        });

                    } catch(e) {
                        if (window.console && window.console.warn) {
                            console.warn('[OY Address] oy_apply_service_areas error:', e);
                        }
                    }
                };

                jQuery(document).ready(function($){

                    // Toggle dirección
                    $('#service_area_only').on('change', window.oy_toggle_address_fields);
                    $('#show_address_to_customers').on('change', window.oy_toggle_address_fields);

                    // Actualizar mapa al cambiar coordenadas (debounce)
                    var oy_map_debounce_timer;
                    $('#location_latitude, #location_longitude').on('input change', function() {
                        clearTimeout(oy_map_debounce_timer);
                        oy_map_debounce_timer = setTimeout(function() {
                            window.oy_update_map_preview();
                        }, 600);
                    });

                    // Ajustar links si cambia URL Maps manualmente
                    $('#location_map_url').on('input change', function() {
                        var mapUrl = $.trim( $(this).val() );
                        if ( mapUrl ) {
                            $('#oy-map-adjust-btn').attr('href', mapUrl).show();
                            $('#oy-maps-open-link').attr('href', mapUrl).show();
                        }
                    });

                    // ✅ Service Areas UI: add/remove
                    function oyServiceAreaAdd(value) {
                        value = String(value || '').trim().replace(/\s{2,}/g,' ');
                        if (!value) return;

                        // Evitar duplicados
                        var exists = false;
                        $('#oy-service-area-tags .oy-sa-tag').each(function(){
                            var v = String($(this).attr('data-value') || '').trim();
                            if (v === value) { exists = true; return false; }
                        });
                        if (exists) return;

                        var chip =
                            '<span class="oy-sa-tag" data-value="'+ $('<div>').text(value).html() +'" ' +
                            'style="display:inline-flex;align-items:center;gap:8px;background:#f0f6fc;border:1px solid #c3d4e6;border-radius:18px;padding:6px 10px;font-size:12px;">' +
                                '<span class="oy-sa-text">'+ $('<div>').text(value).html() +'</span>' +
                                '<button type="button" class="button-link oy-sa-remove" style="color:#dc3232;text-decoration:none;font-weight:700;line-height:1;">✕</button>' +
                                '<input type="hidden" name="location_service_areas[]" value="'+ $('<div>').text(value).html() +'">' +
                            '</span>';

                        $('#oy-service-area-tags').append(chip);
                    }

                    $('#oy-service-area-add').on('click', function(e){
                        e.preventDefault();
                        var v = $('#oy-service-area-input').val();
                        oyServiceAreaAdd(v);
                        $('#oy-service-area-input').val('').focus();
                    });

                    $('#oy-service-area-input').on('keydown', function(e){
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            $('#oy-service-area-add').trigger('click');
                        }
                    });

                    $(document).on('click', '.oy-sa-remove', function(e){
                        e.preventDefault();
                        $(this).closest('.oy-sa-tag').remove();
                    });

                    // Ejecutar al cargar
                    window.oy_toggle_address_fields();

                    // Inicializar mapa
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
