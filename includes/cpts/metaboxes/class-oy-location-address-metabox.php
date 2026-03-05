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
         * Render Address meta box
         *
         * ⚠️ IMPORTANTE:
         * - Este contenido es el MISMO que estaba en OY_Location_CPT::render_address_meta_box()
         * - Se mantiene para no romper:
         *    - Botón "Importar Ahora" (applyLocationToForm usa window.oy_toggle_address_fields / oy_update_map_preview)
         *    - Guardado (save_meta_boxes sigue en el CPT)
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

            // ✅ Fallback: si location_latitude/longitude están vacíos, intenta recuperarlos de
            // gmb_latlng_raw (guardado por import_location_from_gmb_and_map_fields cuando latlng
            // viene en el API response). Esto sincroniza ambos meta keys y asegura que los campos
            // del formulario muestren las coordenadas aunque el save haya ocurrido en una versión
            // anterior que no guardaba por separado.
            if ( ( $latitude === '' || $latitude === false ) || ( $longitude === '' || $longitude === false ) ) {
                $latlng_raw = get_post_meta( $post->ID, 'gmb_latlng_raw', true );
                if ( is_array( $latlng_raw ) ) {
                    if ( ( $latitude === '' || $latitude === false ) && ! empty( $latlng_raw['latitude'] ) ) {
                        $latitude = (string) $latlng_raw['latitude'];
                        // Re-sync al meta principal para que el siguiente save lo tenga disponible
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
            // ✅ Preferencia de embed URL (de más confiable a menos):
            //   1. CID de location_map_url  → maps.google.com/maps?cid=XXX&output=embed  (más estable)
            //   2. Coordenadas GPS          → maps.google.com/maps?q=LAT,LNG&output=embed
            $has_coords   = ( $latitude && $longitude );
            $embed_url    = '';
            $map_link_url = $map_url;

            // Opción 1: extraer CID de la URL guardada de GMB (metadata.mapsUri → ?cid=XXXXXX)
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

            // Opción 2: fallback a coordenadas GPS si no hay CID
            if ( ! $has_embed && $has_coords ) {
                $embed_url = 'https://maps.google.com/maps?q=' . rawurlencode( $latitude . ',' . $longitude ) . '&z=17&output=embed';
                $has_embed = true;
            }

            // map_link_url para botón "Ajustar" — siempre construir si no viene de DB
            if ( empty( $map_link_url ) && $has_coords ) {
                $map_link_url = 'https://maps.google.com/maps?q=' . rawurlencode( $latitude . ',' . $longitude );
            }

            // $has_coords se usa para saber si mostrar iframe o placeholder en PHP
            $has_coords = $has_embed;
            ?>

            <?php /* ── Ubicación de la empresa (alineado con GMB) ── */ ?>
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

                        <?php /* Botón "Ajustar" al estilo GMB — abre Google Maps en nueva pestaña */ ?>
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
                 * Controla visibilidad del bloque de dirección y del row "mostrar dirección".
                 * Se declara en window para que applyLocationToForm (GMB metabox) pueda llamarla.
                 * NOTA: Coordenadas GPS y URL en Google Maps se muestran SIEMPRE (#oy-coords-map-wrap).
                 */
                window.oy_toggle_address_fields = function() {
                    var $ = jQuery;
                    var isServiceAreaOnly  = $('#service_area_only').is(':checked');
                    var showAddressChecked = $('#show_address_to_customers').is(':checked');

                    // Mostrar/ocultar el row "mostrar dirección" solo cuando service_area_only está activo
                    if ( isServiceAreaOnly ) {
                        $('#oy-show-address-row').css('display', 'flex');
                    } else {
                        $('#oy-show-address-row').css('display', 'none');
                    }

                    // Ocultar campos de dirección si: solo servicio Y no mostrar dirección
                    // Coordenadas y Maps URL (#oy-coords-map-wrap) quedan siempre visibles
                    if ( isServiceAreaOnly && ! showAddressChecked ) {
                        $('#oy-address-fields-wrap').hide();
                    } else {
                        $('#oy-address-fields-wrap').show();
                    }
                };

                /**
                 * oy_update_map_preview
                 * Actualiza el mapa embebido cuando cambian las coordenadas GPS.
                 * También actualiza el botón "Ajustar" y el enlace "Ver en Maps".
                 */
                window.oy_update_map_preview = function() {
                    var $ = jQuery;
                    var lat        = $.trim( $('#location_latitude').val() );
                    var lng        = $.trim( $('#location_longitude').val() );
                    var savedMapUrl = $.trim( $('#location_map_url').val() );

                    var hasCoords  = lat && lng && !isNaN(parseFloat(lat)) && !isNaN(parseFloat(lng));

                    // ✅ Preferencia de embed URL (igual que en PHP):
                    //   1. CID de location_map_url  → más estable en Google Maps
                    //   2. Coordenadas GPS          → fallback
                    var embedUrl = '';
                    var mapsUrl  = savedMapUrl || '';

                    if ( savedMapUrl && savedMapUrl.indexOf('cid=') !== -1 ) {
                        // Extraer CID de la URL guardada de GMB (metadata.mapsUri → ?cid=XXXXXX)
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
                        // Sin URL de embed válida: mostrar placeholder
                        $('#oy-map-iframe').hide().attr('src', '');
                        $('#oy-map-placeholder').show();
                        $('#oy-map-preview-wrap').css({ 'display': 'flex' });
                        $('#oy-map-adjust-btn').hide();
                        $('#oy-maps-open-link').hide();
                        return;
                    }

                    // Actualizar iframe
                    $('#oy-map-placeholder').hide();
                    $('#oy-map-iframe').attr('src', embedUrl).css('display', 'block');
                    $('#oy-map-preview-wrap').css({ 'display': 'block' });

                    // Actualizar botón Ajustar
                    if ( mapsUrl ) {
                        $('#oy-map-adjust-btn').attr('href', mapsUrl).show();
                        $('#oy-maps-open-link').attr('href', mapsUrl).show();
                    }
                };

                jQuery(document).ready(function($){
                    // Toggle de dirección
                    $('#service_area_only').on('change', window.oy_toggle_address_fields);
                    $('#show_address_to_customers').on('change', window.oy_toggle_address_fields);

                    // Actualizar mapa al cambiar coordenadas (con debounce de 600ms para no recargar en cada tecla)
                    var oy_map_debounce_timer;
                    $('#location_latitude, #location_longitude').on('input change', function() {
                        clearTimeout(oy_map_debounce_timer);
                        oy_map_debounce_timer = setTimeout(function() {
                            window.oy_update_map_preview();
                        }, 600);
                    });

                    // Actualizar botón Ajustar cuando cambia la URL de Maps manualmente
                    $('#location_map_url').on('input change', function() {
                        var mapUrl = $.trim( $(this).val() );
                        if ( mapUrl ) {
                            $('#oy-map-adjust-btn').attr('href', mapUrl).show();
                            $('#oy-maps-open-link').attr('href', mapUrl).show();
                        }
                    });

                    // Ejecutar al cargar la página
                    window.oy_toggle_address_fields();

                    // Inicializar el mapa al cargar: si hay coordenadas ya guardadas, renderizar iframe.
                    // Se llama a oy_update_map_preview() para unificar la lógica de iframe, botones y enlaces.
                    // Esto también cubre el caso en que el iframe ya venía de PHP pero los botones necesitan
                    // sincronizar sus href con la URL de Maps guardada.
                    (function() {
                        var lat = $.trim( $('#location_latitude').val() );
                        var lng = $.trim( $('#location_longitude').val() );
                        if ( lat && lng && !isNaN(parseFloat(lat)) && !isNaN(parseFloat(lng)) ) {
                            // Si el iframe ya tiene src (renderizado por PHP), solo sincronizar botones/links.
                            var iframeSrc = $('#oy-map-iframe').attr('src');
                            var savedMapUrl = $.trim( $('#location_map_url').val() );
                            var mapsUrl = savedMapUrl || 'https://maps.google.com/maps?q=' + encodeURIComponent(lat) + ',' + encodeURIComponent(lng);

                            if ( iframeSrc && iframeSrc.length > 5 ) {
                                // iframe ya renderizado por PHP, solo sincronizar botones
                                $('#oy-map-adjust-btn').attr('href', mapsUrl).show();
                                $('#oy-maps-open-link').attr('href', mapsUrl).show();
                                $('#oy-map-placeholder').hide();
                                $('#oy-map-iframe').css('display', 'block');
                                $('#oy-map-preview-wrap').css('display', 'block');
                            } else {
                                // Sin iframe de PHP (coords no disponibles al cargar en PHP), generar ahora.
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
