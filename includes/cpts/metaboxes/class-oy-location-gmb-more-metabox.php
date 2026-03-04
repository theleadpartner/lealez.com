<?php
/**
 * OY Location - GMB "Más" Attributes Metabox
 *
 * Metabox dinámico para gestionar los atributos de la sección "Más"
 * del Perfil de Negocio de Google (pestaña "Más" → grupos como
 * "Información proporcionada por la empresa", "Accesibilidad", etc.).
 *
 * Estrategia dinámica:
 * 1. Obtiene los VALORES actuales desde el meta `gmb_attributes_raw`
 *    (ya guardado en la sincronización existente).
 * 2. Obtiene los METADATOS disponibles (nombres, tipos, grupos) vía
 *    `Lealez_GMB_API::get_attribute_metadata()` con caché de 24 h.
 * 3. Renderiza cada atributo según su `valueType` (BOOL, ENUM, URL, etc.).
 * 4. Guarda sobreescrituras manuales en `_gmb_more_attributes_overrides`.
 * 5. AJAX "Sync ↑ to GMB" envía los cambios a la API de Google.
 *
 * Si Google añade o cambia atributos, el sistema los detecta automáticamente
 * en la próxima actualización del caché — sin hardcodear metas estáticas.
 *
 * @package Lealez
 * @subpackage CPTs/Metaboxes
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'OY_Location_GMB_More_Metabox' ) ) {

    class OY_Location_GMB_More_Metabox {

        /**
         * TTL del caché de metadatos de atributos (24 horas).
         *
         * @var int
         */
        const METADATA_CACHE_TTL = DAY_IN_SECONDS;

        /**
         * Nonce action para el metabox save.
         *
         * @var string
         */
        const NONCE_SAVE_ACTION = 'oy_gmb_more_save_attrs';

        /**
         * Nonce action para AJAX.
         *
         * @var string
         */
        const NONCE_AJAX_ACTION = 'oy_gmb_more_ajax';

        /**
         * Constructor: registra hooks.
         */
public function __construct() {
    // Registrar el metabox en la pantalla de edición de oy_location
    add_action( 'add_meta_boxes_oy_location', array( $this, 'register_metabox' ), 25, 1 );

    // Encolar scripts/estilos solo en la pantalla correcta
    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

    // Guardar cuando WordPress hace save_post
    add_action( 'save_post_oy_location', array( $this, 'save_metabox' ), 15, 2 );

    // AJAX: refrescar metadatos desde la API de Google
    add_action( 'wp_ajax_oy_gmb_more_refresh_metadata', array( $this, 'ajax_refresh_metadata' ) );

    // ✅ AJAX: renderizar (pintar) los atributos en la UI del metabox sin llamar a Google
    add_action( 'wp_ajax_oy_gmb_more_render_attributes', array( $this, 'ajax_render_attributes' ) );

    // AJAX: enviar atributos editados a la API de Google
    add_action( 'wp_ajax_oy_gmb_more_push_to_gmb', array( $this, 'ajax_push_to_gmb' ) );
}

        // =========================================================================
        // REGISTRO Y SCRIPTS
        // =========================================================================

        /**
         * Registra el metabox en el editor de oy_location.
         *
         * @param WP_Post $post Post actual.
         */
        public function register_metabox( $post ) {
            add_meta_box(
                'oy_location_gmb_more_attrs',
                __( '📋 Google Business Profile – Sección "Más" (Atributos)', 'lealez' ),
                array( $this, 'render_metabox' ),
                'oy_location',
                'normal',
                'default'
            );
        }

        /**
         * Encola el JS del metabox solo en la edición de oy_location.
         *
         * @param string $hook Hook de la pantalla actual.
         */
public function enqueue_scripts( $hook ) {
    if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
        return;
    }

    global $post;
    if ( ! $post || 'oy_location' !== $post->post_type ) {
        return;
    }

    $js_file = LEALEZ_PLUGIN_DIR . 'assets/js/admin/oy-location-gmb-more.js';
    $js_url  = LEALEZ_PLUGIN_URL . 'assets/js/admin/oy-location-gmb-more.js';
    $version = file_exists( $js_file ) ? filemtime( $js_file ) : LEALEZ_VERSION;

    wp_enqueue_script(
        'oy-location-gmb-more',
        $js_url,
        array( 'jquery' ),
        $version,
        true
    );

    wp_localize_script(
        'oy-location-gmb-more',
        'oyGmbMoreConfig',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE_AJAX_ACTION ),
            'postId'  => $post->ID,
            'i18n'    => array(
                'refreshing'     => __( 'Actualizando metadatos...', 'lealez' ),
                'refreshDone'    => __( 'Metadatos actualizados.', 'lealez' ),
                'refreshError'   => __( 'Error al actualizar metadatos.', 'lealez' ),

                // ✅ Nuevo: render UI
                'rendering'      => __( 'Agregando atributos en la UI...', 'lealez' ),
                'renderDone'     => __( 'Atributos agregados en la UI.', 'lealez' ),
                'renderError'    => __( 'No se pudieron renderizar los atributos.', 'lealez' ),
                'renderNeedMeta' => __( 'No hay metadatos cargados. Primero haz clic en "Actualizar metadatos".', 'lealez' ),

                'pushing'        => __( 'Enviando a Google...', 'lealez' ),
                'pushDone'       => __( 'Atributos actualizados en Google Business Profile.', 'lealez' ),
                'pushError'      => __( 'Error al enviar a Google.', 'lealez' ),
                'confirmPush'    => __( '¿Enviar los cambios directamente a Google Business Profile?', 'lealez' ),
            ),
        )
    );

    // ✅ CSS inline SIN src=false (evita null/false en internals de WP/PHP)
    $css_handle = 'oy-location-gmb-more-inline';
    wp_register_style( $css_handle, '', array(), $version );
    wp_enqueue_style( $css_handle );

    wp_add_inline_style( $css_handle, $this->get_inline_styles() );
}

        // =========================================================================
        // RENDER
        // =========================================================================

        /**
         * Renderiza el metabox completo.
         *
         * @param WP_Post $post Post de oy_location.
         */
public function render_metabox( $post ) {
    // Nonce para el guardado del formulario
    wp_nonce_field( self::NONCE_SAVE_ACTION, '_oy_gmb_more_nonce' );

    $post_id            = (int) $post->ID;
    $parent_business_id = (int) get_post_meta( $post_id, 'parent_business_id', true );
    $gmb_location_name  = (string) get_post_meta( $post_id, 'gmb_location_name', true );

    $raw_attributes = get_post_meta( $post_id, 'gmb_attributes_raw', true );

    /**
     * ✅ ROBUSTEZ:
     * - puede venir como array (ideal)
     * - puede venir como JSON string (por cómo se guardó en otro flujo)
     */
    if ( is_string( $raw_attributes ) && '' !== trim( $raw_attributes ) ) {
        $decoded_raw = json_decode( $raw_attributes, true );
        if ( is_array( $decoded_raw ) ) {
            $raw_attributes = $decoded_raw;
        }
    }

    if ( ! is_array( $raw_attributes ) ) {
        $raw_attributes = array();
    }

    // ── Sobreescrituras manuales guardadas en Lealez (no enviadas aún) ───
    $overrides_json = get_post_meta( $post_id, '_gmb_more_attributes_overrides', true );
    $overrides      = array();
    if ( ! empty( $overrides_json ) ) {
        $decoded = json_decode( $overrides_json, true );
        if ( is_array( $decoded ) ) {
            $overrides = $decoded;
        }
    }

    // ── Metadatos de atributos disponibles (con caché de 24 h) ──────────
    $metadata = $this->get_cached_attribute_metadata( $post_id, $parent_business_id, $gmb_location_name );

    // ── Construir un mapa de valores actuales [attr_id => value] ─────────
    $current_values = $this->build_current_values_map( $raw_attributes );

    // Los overrides manuales tienen precedencia sobre los valores sincronizados
    foreach ( $overrides as $attr_id => $val ) {
        $current_values[ sanitize_text_field( $attr_id ) ] = $val;
    }

    $has_gmb_connection = ! empty( $gmb_location_name ) && ! empty( $parent_business_id );
    $has_metadata       = ! empty( $metadata );

    ?>
    <div class="oy-gmb-more-wrapper" id="oy-gmb-more-metabox-<?php echo esc_attr( $post_id ); ?>">

        <?php // ── Barra de herramientas ──────────────────────────────────── ?>
        <div class="oy-gmb-more-toolbar">
            <span class="oy-gmb-more-toolbar-info">
                <?php if ( $has_gmb_connection ) : ?>
                    <span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
                    <?php esc_html_e( 'Ubicación conectada a Google Business Profile.', 'lealez' ); ?>
                <?php else : ?>
                    <span class="dashicons dashicons-warning" style="color:#dba617;"></span>
                    <?php esc_html_e( 'Ubica y conecta esta location con GMB para ver los atributos disponibles.', 'lealez' ); ?>
                <?php endif; ?>
            </span>

            <span class="oy-gmb-more-toolbar-actions">
                <?php if ( $has_gmb_connection ) : ?>

                    <button type="button"
                            class="button button-secondary oy-gmb-more-btn-refresh"
                            data-post-id="<?php echo esc_attr( $post_id ); ?>">
                        <span class="dashicons dashicons-update" style="margin-top:3px;"></span>
                        <?php esc_html_e( 'Actualizar metadatos', 'lealez' ); ?>
                    </button>

                    <?php
                    // ✅ Nuevo botón: solo renderiza la UI en el metabox con lo que ya está guardado
                    // Requiere metadata para poder agrupar, traducir displayName y elegir control (BOOL/ENUM/URL...)
                    ?>
                    <button type="button"
                            class="button button-secondary oy-gmb-more-btn-render"
                            data-post-id="<?php echo esc_attr( $post_id ); ?>"
                            <?php disabled( ! $has_metadata ); ?>>
                        <span class="dashicons dashicons-forms" style="margin-top:3px;"></span>
                        <?php esc_html_e( 'Agregar los atributos', 'lealez' ); ?>
                    </button>

                    <button type="button"
                            class="button button-primary oy-gmb-more-btn-push"
                            data-post-id="<?php echo esc_attr( $post_id ); ?>"
                            <?php disabled( ! $has_metadata ); ?>>
                        <span class="dashicons dashicons-upload" style="margin-top:3px;"></span>
                        <?php esc_html_e( 'Enviar a Google ↑', 'lealez' ); ?>
                    </button>

                <?php endif; ?>
            </span>
        </div>

        <div class="oy-gmb-more-notice" id="oy-gmb-more-notice-<?php echo esc_attr( $post_id ); ?>" style="display:none;"></div>

        <?php // ── Contenido principal ─────────────────────────────────────── ?>
        <div class="oy-gmb-more-content" id="oy-gmb-more-content-<?php echo esc_attr( $post_id ); ?>">
            <?php
            if ( ! $has_gmb_connection ) {
                echo '<p class="description">' .
                        esc_html__( 'Conecta esta ubicación con Google My Business para gestionar sus atributos.', 'lealez' ) .
                     '</p>';
            } elseif ( ! $has_metadata ) {
                // Sin metadatos: mostrar solo los valores RAW disponibles + botón de refresh
                echo '<p class="description">' .
                        esc_html__( 'Los metadatos de atributos aún no se han cargado. Haz clic en "Actualizar metadatos" para obtenerlos desde Google, o sincroniza la ubicación desde GMB.', 'lealez' ) .
                     '</p>';

                // Si hay valores RAW, mostrarlos de todas formas
                if ( ! empty( $current_values ) ) {
                    echo '<details style="margin-top:10px;">';
                    echo '<summary><strong>' . esc_html__( 'Valores RAW disponibles', 'lealez' ) . ' (' . count( $current_values ) . ')</strong></summary>';
                    echo '<div style="margin-top:8px; padding:8px; background:#f9f9f9; border:1px solid #ddd;">';
                    foreach ( $current_values as $attr_id => $val ) {
                        $display_val = is_bool( $val ) ? ( $val ? 'Sí' : 'No' ) : ( is_array( $val ) ? implode( ', ', $val ) : esc_html( (string) $val ) );
                        echo '<div style="margin-bottom:4px;"><code>' . esc_html( $attr_id ) . '</code>: <strong>' . esc_html( $display_val ) . '</strong></div>';
                    }
                    echo '</div></details>';
                }
            } else {
                // ── Renderizar por grupos ──────────────────────────────────────
                $this->render_attribute_groups( $metadata, $current_values, $post_id );
            }
            ?>
        </div>

        <?php // ── Meta de estado del caché ────────────────────────────────── ?>
        <?php
        $last_fetch = (int) get_post_meta( $post_id, '_gmb_attrs_metadata_last_fetch', true );
        if ( $last_fetch ) :
            $age = human_time_diff( $last_fetch, time() );
        ?>
        <p class="description" style="margin-top:10px; font-size:11px; color:#888;">
            <?php
            printf(
                /* translators: %s: human time diff */
                esc_html__( 'Metadatos cargados hace %s. Se actualizan automáticamente cada 24 h.', 'lealez' ),
                esc_html( $age )
            );
            ?>
        </p>
        <?php endif; ?>

        <?php // ── Campo oculto con estado de overrides pendientes ──────────── ?>
        <input type="hidden"
               name="oy_gmb_more_has_changes"
               id="oy_gmb_more_has_changes_<?php echo esc_attr( $post_id ); ?>"
               value="0" />

    </div><!-- /.oy-gmb-more-wrapper -->
    <?php
}

        /**
         * Renderiza los atributos agrupados por `groupDisplayName`.
         *
         * @param array $metadata       Array de AttributeMetadata objects.
         * @param array $current_values Mapa [attr_id => value].
         * @param int   $post_id        ID del post.
         */
        private function render_attribute_groups( $metadata, $current_values, $post_id ) {
            // Organizar por grupo
            $groups = array();
            foreach ( $metadata as $attr_meta ) {
                if ( ! is_array( $attr_meta ) ) {
                    continue;
                }
                // Compatibilidad: attributeId puede estar en 'attributeId' o derivarse de 'name'
                $attr_id = $this->extract_attr_id_from_meta( $attr_meta );
                if ( '' === $attr_id ) {
                    continue;
                }

                $group_name = ! empty( $attr_meta['groupDisplayName'] )
                    ? (string) $attr_meta['groupDisplayName']
                    : __( 'Otros atributos', 'lealez' );

                if ( ! isset( $groups[ $group_name ] ) ) {
                    $groups[ $group_name ] = array();
                }
                $groups[ $group_name ][ $attr_id ] = $attr_meta;
            }

            if ( empty( $groups ) ) {
                echo '<p class="description">' .
                     esc_html__( 'No se encontraron atributos disponibles para esta categoría de negocio.', 'lealez' ) .
                     '</p>';
                return;
            }

            // Priorizar el grupo "Información proporcionada por la empresa"
            $priority_groups = array(
                'Información proporcionada por la empresa',
                'Information provided by the business',
            );

            $sorted_groups = array();
            foreach ( $priority_groups as $pname ) {
                if ( isset( $groups[ $pname ] ) ) {
                    $sorted_groups[ $pname ] = $groups[ $pname ];
                    unset( $groups[ $pname ] );
                }
            }
            foreach ( $groups as $gname => $attrs ) {
                $sorted_groups[ $gname ] = $attrs;
            }

            // Renderizar cada grupo
            foreach ( $sorted_groups as $group_name => $attrs ) {
                $group_id = 'oy-gmb-group-' . sanitize_title( $group_name );
                ?>
                <div class="oy-gmb-more-group" id="<?php echo esc_attr( $group_id ); ?>">
                    <h4 class="oy-gmb-more-group-title">
                        <?php echo esc_html( $group_name ); ?>
                        <span class="oy-gmb-more-group-count"><?php echo count( $attrs ); ?></span>
                    </h4>
                    <div class="oy-gmb-more-group-fields">
                        <?php foreach ( $attrs as $attr_id => $attr_meta ) : ?>
                            <?php $this->render_single_attribute( $attr_id, $attr_meta, $current_values, $post_id ); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
            }
        }

        /**
         * Renderiza un campo de atributo individual según su `valueType`.
         *
         * @param string $attr_id       ID del atributo (e.g. 'has_women_led').
         * @param array  $attr_meta     Metadata del atributo.
         * @param array  $current_values Mapa actual de valores.
         * @param int    $post_id       ID del post.
         */
/**
 * Renderiza un campo de atributo individual según su `valueType`.
 *
 * Los atributos de tipo URL (url_whatsapp, url_facebook, url_booking, etc.)
 * se omiten porque ya están gestionados en class-oy-location-cpt.php.
 *
 * @param string $attr_id        ID del atributo (e.g. 'has_women_led').
 * @param array  $attr_meta      Metadata del atributo.
 * @param array  $current_values Mapa actual de valores.
 * @param int    $post_id        ID del post.
 */
private function render_single_attribute( $attr_id, $attr_meta, $current_values, $post_id ) {

    $value_type   = isset( $attr_meta['valueType'] ) ? strtoupper( (string) $attr_meta['valueType'] ) : 'BOOL';
    $display_name = ! empty( $attr_meta['displayName'] ) ? (string) $attr_meta['displayName'] : $this->humanize_attr_id( $attr_id );

    /**
     * ✅ FIX:
     * Google usa "deprecated" (no "isDeprecated") en AttributeMetadata.
     * Conservamos compat con isDeprecated por si llega desde otro origen.
     */
    $is_deprecated = false;
    if ( ! empty( $attr_meta['deprecated'] ) ) {
        $is_deprecated = true;
    } elseif ( ! empty( $attr_meta['isDeprecated'] ) ) {
        $is_deprecated = true;
    }

    if ( $is_deprecated ) {
        return;
    }

    /**
     * ✅ FILTRO URLs:
     * Los atributos de tipo URL (url_whatsapp, url_facebook, url_booking,
     * url_menu, url_order, url_text_messaging, etc.) ya son gestionados
     * en class-oy-location-cpt.php (campos de contacto, redes sociales,
     * URLs de reservas/pedidos). No los mostramos aquí para evitar duplicados.
     */
    if ( 'URL' === $value_type ) {
        return;
    }

    /**
     * ✅ FIX:
     * AttributeMetadata trae "repeatable" (bool).
     * Si valueType=ENUM y repeatable=true → UI debe ser multi-select (checkboxes) => REPEATED_ENUM.
     */
    $repeatable = ! empty( $attr_meta['repeatable'] );
    if ( $repeatable && 'ENUM' === $value_type ) {
        $value_type = 'REPEATED_ENUM';
    }

    $field_name = 'oy_gmb_more_attr[' . esc_attr( $attr_id ) . ']';
    $field_id   = 'oy_gmb_more_attr_' . sanitize_html_class( $attr_id ) . '_' . $post_id;

    $current_raw = isset( $current_values[ $attr_id ] ) ? $current_values[ $attr_id ] : null;
    ?>
    <div class="oy-gmb-more-field"
         data-attr-id="<?php echo esc_attr( $attr_id ); ?>"
         data-value-type="<?php echo esc_attr( $value_type ); ?>">
        <label class="oy-gmb-more-field-label" for="<?php echo esc_attr( $field_id ); ?>">
            <?php echo esc_html( $display_name ); ?>
        </label>

        <div class="oy-gmb-more-field-control">
            <?php
            switch ( $value_type ) {

                case 'BOOL':
                    $this->render_bool_field( $field_name, $field_id, $current_raw );
                    break;

                case 'ENUM':
                    $value_metadata = isset( $attr_meta['valueMetadata'] ) && is_array( $attr_meta['valueMetadata'] )
                        ? $attr_meta['valueMetadata']
                        : array();
                    $this->render_enum_field( $field_name, $field_id, $current_raw, $value_metadata );
                    break;

                case 'REPEATED_ENUM':
                    $value_metadata = isset( $attr_meta['valueMetadata'] ) && is_array( $attr_meta['valueMetadata'] )
                        ? $attr_meta['valueMetadata']
                        : array();
                    $this->render_repeated_enum_field( $field_name, $field_id, $current_raw, $value_metadata );
                    break;

                default:
                    // Tipos no reconocidos: mostrar como texto plano de solo lectura
                    $fallback_val = is_array( $current_raw ) ? implode( ', ', $current_raw ) : (string) $current_raw;
                    echo '<span class="oy-gmb-more-field-unset">' . esc_html( $fallback_val ) . '</span>';
                    break;
            }
            ?>
        </div>

        <?php if ( null === $current_raw ) : ?>
            <span class="oy-gmb-more-field-unset"><?php esc_html_e( 'No configurado en GMB', 'lealez' ); ?></span>
        <?php endif; ?>
    </div>
    <?php
}



        /**
 * Normaliza AttributeValueMetadata.value a un string usable en <option value="">
 *
 * En attributes.list, valueMetadata[].value viene en formato "Value" (protobuf),
 * por lo que puede llegar como:
 * - string: "PAID"
 * - array:  ["stringValue" => "PAID"]
 * - array:  ["boolValue" => true]
 * - array:  ["numberValue" => 1]
 *
 * @param mixed $v
 * @return string
 */
private function normalize_value_metadata_value( $v ) {
    if ( is_string( $v ) ) {
        return $v;
    }
    if ( is_bool( $v ) ) {
        return $v ? 'true' : 'false';
    }
    if ( is_numeric( $v ) ) {
        return (string) $v;
    }
    if ( is_array( $v ) ) {
        // Protobuf Value forms
        if ( array_key_exists( 'stringValue', $v ) ) {
            return (string) $v['stringValue'];
        }
        if ( array_key_exists( 'boolValue', $v ) ) {
            return ! empty( $v['boolValue'] ) ? 'true' : 'false';
        }
        if ( array_key_exists( 'numberValue', $v ) ) {
            return (string) $v['numberValue'];
        }

        // Fallback: primer scalar
        foreach ( $v as $vv ) {
            if ( is_scalar( $vv ) ) {
                return (string) $vv;
            }
        }
    }

    return '';
}

        /**
         * Renderiza un campo booleano (Sí / No / No configurado).
         * Usa tres radio buttons para poder representar "no especificado".
         */
        private function render_bool_field( $field_name, $field_id, $current_raw ) {
            // $current_raw puede ser: true, false, null (no establecido)
            if ( null === $current_raw ) {
                $current_string = '';
            } elseif ( true === $current_raw || 1 === $current_raw || 'true' === $current_raw ) {
                $current_string = 'true';
            } else {
                $current_string = 'false';
            }
            ?>
            <div class="oy-gmb-bool-radio-group">
                <label class="oy-gmb-radio-option oy-gmb-radio-yes">
                    <input type="radio"
                           name="<?php echo esc_attr( $field_name ); ?>"
                           id="<?php echo esc_attr( $field_id ); ?>_true"
                           value="true"
                           class="oy-gmb-more-field-input"
                           <?php checked( $current_string, 'true' ); ?> />
                    <span class="oy-gmb-radio-label"><?php esc_html_e( 'Sí', 'lealez' ); ?></span>
                </label>
                <label class="oy-gmb-radio-option oy-gmb-radio-no">
                    <input type="radio"
                           name="<?php echo esc_attr( $field_name ); ?>"
                           id="<?php echo esc_attr( $field_id ); ?>_false"
                           value="false"
                           class="oy-gmb-more-field-input"
                           <?php checked( $current_string, 'false' ); ?> />
                    <span class="oy-gmb-radio-label"><?php esc_html_e( 'No', 'lealez' ); ?></span>
                </label>
                <label class="oy-gmb-radio-option oy-gmb-radio-unset">
                    <input type="radio"
                           name="<?php echo esc_attr( $field_name ); ?>"
                           id="<?php echo esc_attr( $field_id ); ?>_unset"
                           value=""
                           class="oy-gmb-more-field-input"
                           <?php checked( $current_string, '' ); ?> />
                    <span class="oy-gmb-radio-label"><?php esc_html_e( 'No especificado', 'lealez' ); ?></span>
                </label>
            </div>
            <?php
        }

        /**
         * Renderiza un campo de tipo ENUM (select).
         */
private function render_enum_field( $field_name, $field_id, $current_raw, $value_metadata ) {
    $current_string = is_array( $current_raw )
        ? ( isset( $current_raw[0] ) ? (string) $current_raw[0] : '' )
        : (string) $current_raw;
    ?>
    <select name="<?php echo esc_attr( $field_name ); ?>"
            id="<?php echo esc_attr( $field_id ); ?>"
            class="oy-gmb-more-field-input">
        <option value=""><?php esc_html_e( '— No especificado —', 'lealez' ); ?></option>
        <?php foreach ( $value_metadata as $vm ) : ?>
            <?php
            if ( ! is_array( $vm ) ) {
                continue;
            }

            $vm_value_raw = $vm['value'] ?? '';
            $vm_value     = $this->normalize_value_metadata_value( $vm_value_raw );
            $vm_display   = isset( $vm['displayName'] ) ? (string) $vm['displayName'] : $vm_value;

            // Google usa "deprecated" (no isDeprecated) aquí también.
            $vm_deprecated = ! empty( $vm['deprecated'] ) || ! empty( $vm['isDeprecated'] );
            if ( $vm_deprecated ) {
                continue;
            }

            if ( '' === $vm_value ) {
                continue;
            }
            ?>
            <option value="<?php echo esc_attr( $vm_value ); ?>"
                    <?php selected( $current_string, $vm_value ); ?>>
                <?php echo esc_html( $vm_display ); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

        /**
         * Renderiza un campo URL.
         */
        private function render_url_field( $field_name, $field_id, $current_raw ) {
            $uri = '';
            if ( is_array( $current_raw ) && isset( $current_raw['uri'] ) ) {
                $uri = (string) $current_raw['uri'];
            } elseif ( is_string( $current_raw ) ) {
                $uri = $current_raw;
            }
            ?>
            <input type="url"
                   name="<?php echo esc_attr( $field_name ); ?>"
                   id="<?php echo esc_attr( $field_id ); ?>"
                   class="oy-gmb-more-field-input regular-text"
                   value="<?php echo esc_attr( $uri ); ?>"
                   placeholder="https://" />
            <?php
        }

        /**
         * Renderiza un campo REPEATED_ENUM (checkboxes múltiples).
         */
private function render_repeated_enum_field( $field_name, $field_id, $current_raw, $value_metadata ) {
    $selected_values = array();

    if ( is_array( $current_raw ) ) {
        $selected_values = array_map( 'strval', $current_raw );
    } elseif ( is_string( $current_raw ) && '' !== $current_raw ) {
        $selected_values = array( $current_raw );
    }

    echo '<div class="oy-gmb-repeated-enum-group">';

    foreach ( $value_metadata as $vm ) {
        if ( ! is_array( $vm ) ) {
            continue;
        }

        $vm_value_raw = $vm['value'] ?? '';
        $vm_value     = $this->normalize_value_metadata_value( $vm_value_raw );
        $vm_display   = isset( $vm['displayName'] ) ? (string) $vm['displayName'] : $vm_value;

        $vm_deprecated = ! empty( $vm['deprecated'] ) || ! empty( $vm['isDeprecated'] );
        if ( $vm_deprecated ) {
            continue;
        }

        if ( '' === $vm_value ) {
            continue;
        }

        $cb_id = $field_id . '_' . sanitize_html_class( $vm_value );
        ?>
        <label class="oy-gmb-checkbox-option">
            <input type="checkbox"
                   name="<?php echo esc_attr( $field_name ); ?>[]"
                   id="<?php echo esc_attr( $cb_id ); ?>"
                   value="<?php echo esc_attr( $vm_value ); ?>"
                   class="oy-gmb-more-field-input"
                   <?php checked( in_array( $vm_value, $selected_values, true ) ); ?> />
            <span><?php echo esc_html( $vm_display ); ?></span>
        </label>
        <?php
    }

    echo '</div>';
}

        // =========================================================================
        // GUARDAR METABOX
        // =========================================================================

        /**
         * Guarda los valores editados en el metabox como overrides en post_meta.
         * No envía automáticamente a GMB — eso se hace con el botón AJAX.
         *
         * @param int     $post_id ID del post.
         * @param WP_Post $post    Objeto post.
         */
        public function save_metabox( $post_id, $post ) {
            // Verificaciones estándar de WordPress
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }
            if ( ! isset( $_POST['_oy_gmb_more_nonce'] ) ) {
                return;
            }
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_oy_gmb_more_nonce'] ) ), self::NONCE_SAVE_ACTION ) ) {
                return;
            }
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }
            if ( 'oy_location' !== get_post_type( $post_id ) ) {
                return;
            }

            // Si no viene el array de atributos, no hay nada que hacer
            if ( ! isset( $_POST['oy_gmb_more_attr'] ) || ! is_array( $_POST['oy_gmb_more_attr'] ) ) {
                return;
            }

            $raw_input  = wp_unslash( $_POST['oy_gmb_more_attr'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $sanitized  = array();

            foreach ( $raw_input as $attr_id => $value ) {
                $attr_id = sanitize_key( $attr_id );
                if ( '' === $attr_id ) {
                    continue;
                }

                if ( is_array( $value ) ) {
                    // REPEATED_ENUM
                    $sanitized[ $attr_id ] = array_map( 'sanitize_text_field', $value );
                } else {
                    $sanitized[ $attr_id ] = sanitize_text_field( (string) $value );
                }
            }

            // Guardar como JSON en _gmb_more_attributes_overrides
            update_post_meta( $post_id, '_gmb_more_attributes_overrides', wp_json_encode( $sanitized ) );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log(
                    '[OY GMB More] Overrides saved for post ' . $post_id . ': ' .
                    count( $sanitized ) . ' attribute(s).'
                );
            }
        }

        // =========================================================================
        // AJAX HANDLERS
        // =========================================================================

        /**
         * AJAX: Refresca los metadatos de atributos disponibles desde la API de Google.
         * Borra el caché local y vuelve a fetchar.
         */
        public function ajax_refresh_metadata() {
            check_ajax_referer( self::NONCE_AJAX_ACTION, 'nonce' );

            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'lealez' ) ) );
                return;
            }

            $post_id = absint( $_POST['post_id'] ?? 0 );
            if ( ! $post_id ) {
                wp_send_json_error( array( 'message' => __( 'post_id inválido.', 'lealez' ) ) );
                return;
            }

            $parent_business_id = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $gmb_location_name  = (string) get_post_meta( $post_id, 'gmb_location_name', true );

            if ( ! $parent_business_id || ! $gmb_location_name ) {
                wp_send_json_error( array(
                    'message' => __( 'Esta location no tiene una conexión GMB configurada.', 'lealez' ),
                ) );
                return;
            }

            // Forzar refresco (ignorar caché)
            $metadata = $this->fetch_and_cache_attribute_metadata( $post_id, $parent_business_id, $gmb_location_name, true );

            if ( is_wp_error( $metadata ) ) {
                wp_send_json_error( array(
                    'message' => $metadata->get_error_message(),
                ) );
                return;
            }

            $count = is_array( $metadata ) ? count( $metadata ) : 0;

            wp_send_json_success( array(
                'message'        => sprintf(
                    /* translators: %d: number of attributes */
                    __( 'Metadatos actualizados: %d atributo(s) disponible(s).', 'lealez' ),
                    $count
                ),
                'attributes_count' => $count,
                'reload'         => true, // El JS recargará el metabox via wp_ajax o recargará la página
            ) );
        }



public function ajax_render_attributes() {
    check_ajax_referer( self::NONCE_AJAX_ACTION, 'nonce' );

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'lealez' ) ) );
        return;
    }

    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id ) {
        wp_send_json_error( array( 'message' => __( 'post_id inválido.', 'lealez' ) ) );
        return;
    }

    $parent_business_id = (int) get_post_meta( $post_id, 'parent_business_id', true );
    $gmb_location_name  = (string) get_post_meta( $post_id, 'gmb_location_name', true );

    if ( ! $parent_business_id || ! $gmb_location_name ) {
        wp_send_json_error( array(
            'message' => __( 'Esta location no tiene una conexión GMB configurada.', 'lealez' ),
        ) );
        return;
    }

    // 1) Leer RAW attributes (valores)
    $raw_attributes = get_post_meta( $post_id, 'gmb_attributes_raw', true );

    // Robustez: puede venir string JSON
    if ( is_string( $raw_attributes ) && '' !== trim( $raw_attributes ) ) {
        $decoded_raw = json_decode( $raw_attributes, true );
        if ( is_array( $decoded_raw ) ) {
            $raw_attributes = $decoded_raw;
        }
    }
    if ( ! is_array( $raw_attributes ) ) {
        $raw_attributes = array();
    }

    $current_values = $this->build_current_values_map( $raw_attributes );

    // 2) Overrides (precedencia)
    $overrides_json = get_post_meta( $post_id, '_gmb_more_attributes_overrides', true );
    $overrides      = array();
    if ( ! empty( $overrides_json ) ) {
        $decoded = json_decode( $overrides_json, true );
        if ( is_array( $decoded ) ) {
            $overrides = $decoded;
        }
    }
    foreach ( $overrides as $attr_id => $val ) {
        $current_values[ sanitize_text_field( $attr_id ) ] = $val;
    }

    // 3) Metadata cacheada
    $metadata = $this->get_cached_attribute_metadata( $post_id, $parent_business_id, $gmb_location_name );
    $meta_count = is_array( $metadata ) ? count( $metadata ) : 0;

    if ( empty( $metadata ) || ! is_array( $metadata ) ) {
        wp_send_json_error( array(
            'message' => __( 'No hay metadatos cacheados para renderizar. Primero haz clic en "Actualizar metadatos".', 'lealez' ),
        ) );
        return;
    }

    // 4) Renderizar HTML de grupos
    ob_start();
    $this->render_attribute_groups( $metadata, $current_values, $post_id );
    $html = (string) ob_get_clean();

    $html_len = strlen( trim( $html ) );

    // ✅ GARANTÍA: si no hay HTML real, esto NO es success
    if ( $html_len < 50 ) {
        // Log opcional para debug
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[OY GMB More] ajax_render_attributes produced EMPTY html. post_id=%d meta=%d values=%d overrides=%d',
                $post_id,
                $meta_count,
                is_array( $current_values ) ? count( $current_values ) : 0,
                is_array( $overrides ) ? count( $overrides ) : 0
            ) );
        }

        wp_send_json_error( array(
            'message'    => __( 'No se generó HTML para renderizar. Revisa que metadata y render_attribute_groups estén retornando grupos válidos.', 'lealez' ),
            'meta_count' => $meta_count,
            'html_len'   => $html_len,
        ) );
        return;
    }

    wp_send_json_success( array(
        'message'    => __( 'Atributos agregados/renderizados en la UI del metabox.', 'lealez' ),
        'html'       => $html,
        'meta_count' => $meta_count,
        'html_len'   => $html_len,
    ) );
}

        /**
         * AJAX: Envía los atributos editados directamente a Google Business Profile API.
         */
        public function ajax_push_to_gmb() {
            check_ajax_referer( self::NONCE_AJAX_ACTION, 'nonce' );

            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'lealez' ) ) );
                return;
            }

            $post_id = absint( $_POST['post_id'] ?? 0 );
            if ( ! $post_id ) {
                wp_send_json_error( array( 'message' => __( 'post_id inválido.', 'lealez' ) ) );
                return;
            }

            $parent_business_id = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $gmb_location_name  = (string) get_post_meta( $post_id, 'gmb_location_name', true );

            if ( ! $parent_business_id || ! $gmb_location_name ) {
                wp_send_json_error( array(
                    'message' => __( 'Esta location no tiene una conexión GMB configurada.', 'lealez' ),
                ) );
                return;
            }

            // Leer overrides guardados
            $overrides_json = get_post_meta( $post_id, '_gmb_more_attributes_overrides', true );
            $overrides      = array();
            if ( ! empty( $overrides_json ) ) {
                $decoded = json_decode( $overrides_json, true );
                if ( is_array( $decoded ) ) {
                    $overrides = $decoded;
                }
            }

            if ( empty( $overrides ) ) {
                wp_send_json_error( array(
                    'message' => __( 'No hay cambios pendientes para enviar.', 'lealez' ),
                ) );
                return;
            }

            // Verificar que el método de API existe
            if ( ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'update_location_attributes' ) ) {
                wp_send_json_error( array(
                    'message' => __( 'El método de actualización de atributos aún no está implementado en la API. Por favor actualiza el plugin.', 'lealez' ),
                ) );
                return;
            }

            // Convertir overrides al formato de GMB API
            $attributes_payload = $this->build_gmb_attributes_payload( $overrides );

            $result = Lealez_GMB_API::update_location_attributes(
                $parent_business_id,
                $gmb_location_name,
                $attributes_payload
            );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array(
                    'message' => $result->get_error_message(),
                ) );
                return;
            }

            // Limpiar overrides tras envío exitoso
            delete_post_meta( $post_id, '_gmb_more_attributes_overrides' );

            // Actualizar gmb_attributes_raw con los nuevos valores
            $merged = $this->merge_overrides_into_raw( $post_id, $overrides );
            update_post_meta( $post_id, 'gmb_attributes_raw', $merged );

            wp_send_json_success( array(
                'message' => __( 'Atributos actualizados correctamente en Google Business Profile.', 'lealez' ),
            ) );
        }

        // =========================================================================
        // HELPERS: METADATOS Y CACHÉ
        // =========================================================================

        /**
         * Obtiene los metadatos de atributos desde caché o fetcha desde la API.
         *
         * @param int    $post_id
         * @param int    $business_id
         * @param string $location_name
         * @return array
         */
private function get_cached_attribute_metadata( $post_id, $business_id, $location_name ) {
    if ( ! $business_id || ! $location_name ) {
        return array();
    }

    $last_fetch = (int) get_post_meta( $post_id, '_gmb_attrs_metadata_last_fetch', true );
    $cached_raw = get_post_meta( $post_id, '_gmb_attrs_metadata', true );

    /**
     * ✅ ROBUSTEZ:
     * - A veces WP puede tener este meta como ARRAY (no JSON string), por flows viejos o updates.
     * - Si viene como array, lo usamos directo.
     * - Si viene como string, intentamos json_decode.
     */
    $cached_decoded = array();

    if ( is_array( $cached_raw ) ) {
        $cached_decoded = $cached_raw;
    } elseif ( is_string( $cached_raw ) && '' !== trim( $cached_raw ) ) {
        $tmp = json_decode( $cached_raw, true );
        if ( is_array( $tmp ) ) {
            $cached_decoded = $tmp;
        }
    }

    // 1) Si el caché está vigente y tiene datos, usarlo
    if (
        $last_fetch > 0 &&
        ( time() - $last_fetch ) < self::METADATA_CACHE_TTL &&
        ! empty( $cached_decoded )
    ) {
        return $cached_decoded;
    }

    // 2) Si está vencido o vacío, intentar fetch fresco
    $metadata = $this->fetch_and_cache_attribute_metadata( $post_id, $business_id, $location_name, false );

    if ( is_wp_error( $metadata ) || ! is_array( $metadata ) || empty( $metadata ) ) {
        // 3) Si falla el fetch, devolver caché previo aunque sea viejo (si existe)
        if ( ! empty( $cached_decoded ) ) {
            return $cached_decoded;
        }
        return array();
    }

    return $metadata;
}

        /**
         * Fetcha los metadatos desde la API y los guarda en post_meta.
         *
         * @param int    $post_id
         * @param int    $business_id
         * @param string $location_name
         * @param bool   $force_refresh Si true, ignora el caché anterior.
         * @return array|WP_Error
         */
/**
 * Fetcha los metadatos desde la API y los guarda en post_meta.
 *
 * @param int    $post_id
 * @param int    $business_id
 * @param string $location_name
 * @param bool   $force_refresh Si true, ignora el caché anterior.
 * @return array|WP_Error
 */
private function fetch_and_cache_attribute_metadata( $post_id, $business_id, $location_name, $force_refresh = false ) {
    if ( ! class_exists( 'Lealez_GMB_API' ) ) {
        return new WP_Error( 'missing_class', __( 'Lealez_GMB_API no está disponible.', 'lealez' ) );
    }

    if ( ! method_exists( 'Lealez_GMB_API', 'get_attribute_metadata' ) ) {
        // El método aún no existe: devolver array vacío sin error fatal
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[OY GMB More] Lealez_GMB_API::get_attribute_metadata() no está definido.' );
        }
        return array();
    }

    // Obtener la categoría principal de la location (necesaria para el endpoint de metadatos)
    $primary_category = (string) get_post_meta( $post_id, 'google_primary_category', true );
    $region_code      = (string) get_post_meta( $post_id, 'location_country', true );
    if ( '' === $region_code ) {
        $region_code = 'CO'; // Default Colombia
    }

    $metadata = Lealez_GMB_API::get_attribute_metadata(
        $business_id,
        $location_name,
        $primary_category,
        $region_code,
        'es',
        ! $force_refresh
    );

    if ( is_wp_error( $metadata ) ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[OY GMB More] fetch_attribute_metadata error: ' . $metadata->get_error_message() );
        }
        return $metadata;
    }

    if ( ! is_array( $metadata ) ) {
        return array();
    }

    // ✅ FIX UNICODE: usar JSON_UNESCAPED_UNICODE para evitar que WordPress
    // corrompa las secuencias \uXXXX al aplicar stripslashes_deep() en get_post_meta().
    // Con JSON_UNESCAPED_UNICODE los caracteres como ñ, á, é se almacenan directamente
    // como UTF-8, sin secuencias de escape que WordPress pueda dañar.
    update_post_meta(
        $post_id,
        '_gmb_attrs_metadata',
        wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
    );
    update_post_meta( $post_id, '_gmb_attrs_metadata_last_fetch', time() );

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[OY GMB More] Attribute metadata cached: ' . count( $metadata ) . ' attribute(s) for post ' . $post_id );
    }

    return $metadata;
}

        // =========================================================================
        // HELPERS: DATOS
        // =========================================================================

        /**
         * Construye un mapa [attr_id => valor] desde el array de atributos RAW de GMB.
         *
         * El formato de GMB v1 Business Information API es:
         * [
         *   { "name": "locations/{id}/attributes/has_women_led", "valueType": "BOOL", "values": [true] },
         *   { "name": "locations/{id}/attributes/url_whatsapp",  "valueType": "URL",  "uriValues": [{"uri":"..."}] },
         * ]
         *
         * @param array $raw_attributes
         * @return array
         */
private function build_current_values_map( $raw_attributes ) {
    $map = array();

    foreach ( $raw_attributes as $attr ) {
        if ( ! is_array( $attr ) ) {
            continue;
        }

        /**
         * Normalizar ID del atributo.
         *
         * Casos reales que soportamos:
         * - attributeId: "attributes/serves_beer"  (o "serves_beer")
         * - name: "locations/123/attributes/serves_beer"
         * - name: "attributes/serves_beer"   ✅ (este era el bug)
         */
        $attr_id = '';

        if ( ! empty( $attr['attributeId'] ) ) {
            $raw = (string) $attr['attributeId'];

            if ( false !== strpos( $raw, '/attributes/' ) ) {
                $parts = explode( '/attributes/', $raw, 2 );
                $raw   = $parts[1] ?? $raw;
            } elseif ( 0 === strpos( $raw, 'attributes/' ) ) {
                $raw = substr( $raw, strlen( 'attributes/' ) );
            }

            $attr_id = sanitize_key( trim( $raw, '/' ) );

        } elseif ( ! empty( $attr['name'] ) ) {
            $raw = (string) $attr['name'];

            if ( false !== strpos( $raw, '/attributes/' ) ) {
                $parts = explode( '/attributes/', $raw, 2 );
                $raw   = $parts[1] ?? '';
            } elseif ( 0 === strpos( $raw, 'attributes/' ) ) {
                // ✅ FIX: name="attributes/serves_beer"
                $raw = substr( $raw, strlen( 'attributes/' ) );
            } else {
                // fallback: último segmento
                $chunks = explode( '/', $raw );
                $raw    = end( $chunks );
            }

            $attr_id = sanitize_key( trim( (string) $raw, '/' ) );
        }

        if ( '' === $attr_id ) {
            continue;
        }

        $value_type = strtoupper( isset( $attr['valueType'] ) ? (string) $attr['valueType'] : 'BOOL' );

        switch ( $value_type ) {
            case 'BOOL':
                if ( isset( $attr['values'] ) && is_array( $attr['values'] ) && array_key_exists( 0, $attr['values'] ) ) {
                    $map[ $attr_id ] = (bool) $attr['values'][0];
                }
                break;

            case 'ENUM':
                if ( isset( $attr['values'] ) && is_array( $attr['values'] ) && array_key_exists( 0, $attr['values'] ) ) {
                    $map[ $attr_id ] = (string) $attr['values'][0];
                }
                break;

            case 'URL':
                if ( ! empty( $attr['uriValues'] ) && is_array( $attr['uriValues'] ) ) {
                    $uri = '';
                    if ( isset( $attr['uriValues'][0] ) && is_array( $attr['uriValues'][0] ) ) {
                        $uri = isset( $attr['uriValues'][0]['uri'] ) ? (string) $attr['uriValues'][0]['uri'] : '';
                    }
                    $map[ $attr_id ] = $uri;
                }
                break;

            case 'REPEATED_ENUM':
                if ( ! empty( $attr['repeatedEnumValue'] ) && is_array( $attr['repeatedEnumValue'] ) ) {
                    $set_values = isset( $attr['repeatedEnumValue']['setValues'] )
                        ? (array) $attr['repeatedEnumValue']['setValues']
                        : array();
                    $map[ $attr_id ] = array_map( 'strval', $set_values );
                }
                break;

            default:
                if ( isset( $attr['values'] ) ) {
                    $map[ $attr_id ] = $attr['values'];
                }
                break;
        }
    }

    return $map;
}

        /**
         * Extrae el `attributeId` desde un objeto de metadato de atributo.
         *
         * @param array $attr_meta
         * @return string
         */
private function extract_attr_id_from_meta( $attr_meta ) {
    /**
     * ✅ FIX REAL:
     * AttributeMetadata (attributes.list) NO trae attributeId/name.
     * Trae "parent" como identificador único del atributo, típicamente:
     *   parent: "attributes/serves_beer"
     *
     * Mantenemos compatibilidad con posibles formatos alternos (attributeId / name).
     */

    // 0) Campo correcto según API oficial: "parent"
    if ( ! empty( $attr_meta['parent'] ) ) {
        $raw = (string) $attr_meta['parent'];

        if ( false !== strpos( $raw, '/attributes/' ) ) {
            $parts = explode( '/attributes/', $raw, 2 );
            $raw   = $parts[1] ?? $raw;
        } elseif ( 0 === strpos( $raw, 'attributes/' ) ) {
            $raw = substr( $raw, strlen( 'attributes/' ) );
        }

        $raw = trim( $raw, '/' );
        return sanitize_key( $raw );
    }

    // 1) Compat: attributeId directo (si existiera)
    if ( ! empty( $attr_meta['attributeId'] ) ) {
        $raw = (string) $attr_meta['attributeId'];

        if ( false !== strpos( $raw, '/attributes/' ) ) {
            $parts = explode( '/attributes/', $raw, 2 );
            $raw   = $parts[1] ?? $raw;
        } elseif ( 0 === strpos( $raw, 'attributes/' ) ) {
            $raw = substr( $raw, strlen( 'attributes/' ) );
        }

        $raw = trim( $raw, '/' );
        return sanitize_key( $raw );
    }

    // 2) Compat: derivar desde name (si existiera)
    if ( ! empty( $attr_meta['name'] ) ) {
        $raw = (string) $attr_meta['name'];

        if ( false !== strpos( $raw, '/attributes/' ) ) {
            $parts = explode( '/attributes/', $raw, 2 );
            $raw   = $parts[1] ?? '';
        } elseif ( 0 === strpos( $raw, 'attributes/' ) ) {
            $raw = substr( $raw, strlen( 'attributes/' ) );
        } else {
            $chunks = explode( '/', $raw );
            $raw    = end( $chunks );
        }

        $raw = trim( (string) $raw, '/' );
        return sanitize_key( $raw );
    }

    return '';
}

        /**
         * Convierte overrides al formato de payload para la GMB API PATCH.
         *
         * @param array $overrides [attr_id => value]
         * @return array
         */
        private function build_gmb_attributes_payload( $overrides ) {
            $attributes = array();

            foreach ( $overrides as $attr_id => $value ) {
                $attr_name = 'attributes/' . $attr_id;

                if ( '' === $value || null === $value ) {
                    // Para quitar un atributo: mandar values vacío
                    $attributes[] = array(
                        'name'   => $attr_name,
                        'values' => array(),
                    );
                } elseif ( 'true' === $value || true === $value ) {
                    $attributes[] = array(
                        'name'      => $attr_name,
                        'valueType' => 'BOOL',
                        'values'    => array( true ),
                    );
                } elseif ( 'false' === $value || false === $value ) {
                    $attributes[] = array(
                        'name'      => $attr_name,
                        'valueType' => 'BOOL',
                        'values'    => array( false ),
                    );
                } elseif ( is_array( $value ) ) {
                    // REPEATED_ENUM
                    $attributes[] = array(
                        'name'               => $attr_name,
                        'valueType'          => 'REPEATED_ENUM',
                        'repeatedEnumValue'  => array( 'setValues' => $value ),
                    );
                } elseif ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
                    $attributes[] = array(
                        'name'      => $attr_name,
                        'valueType' => 'URL',
                        'uriValues' => array( array( 'uri' => esc_url_raw( $value ) ) ),
                    );
                } else {
                    // ENUM u otro
                    $attributes[] = array(
                        'name'      => $attr_name,
                        'valueType' => 'ENUM',
                        'values'    => array( $value ),
                    );
                }
            }

            return $attributes;
        }

        /**
         * Fusiona los overrides manuales en el array raw para actualizar el caché local.
         *
         * @param int   $post_id
         * @param array $overrides
         * @return array
         */
        private function merge_overrides_into_raw( $post_id, $overrides ) {
            $raw = get_post_meta( $post_id, 'gmb_attributes_raw', true );
            if ( ! is_array( $raw ) ) {
                $raw = array();
            }

            $payload_attrs = $this->build_gmb_attributes_payload( $overrides );

            // Construir índice por nombre
            $raw_index = array();
            foreach ( $raw as $i => $attr ) {
                if ( ! empty( $attr['name'] ) ) {
                    $raw_index[ $attr['name'] ] = $i;
                }
            }

            foreach ( $payload_attrs as $new_attr ) {
                $name = $new_attr['name'];
                if ( isset( $raw_index[ $name ] ) ) {
                    $raw[ $raw_index[ $name ] ] = $new_attr;
                } else {
                    $raw[] = $new_attr;
                }
            }

            return array_values( $raw );
        }

        /**
         * Convierte un attr_id de snake_case a texto legible.
         *
         * @param string $attr_id
         * @return string
         */
        private function humanize_attr_id( $attr_id ) {
            $cleaned = preg_replace( '/^(has_|is_|offers_|accepts_|url_)/', '', $attr_id );
            return ucfirst( str_replace( '_', ' ', $cleaned ) );
        }

        // =========================================================================
        // CSS INLINE
        // =========================================================================

        /**
         * Devuelve los estilos CSS del metabox como string.
         *
         * @return string
         */
        private function get_inline_styles() {
            return '
            /* === OY GMB More Metabox === */
            .oy-gmb-more-wrapper { font-size: 13px; }

            .oy-gmb-more-toolbar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 8px 0 10px;
                border-bottom: 1px solid #e0e0e0;
                margin-bottom: 14px;
                flex-wrap: wrap;
                gap: 8px;
            }
            .oy-gmb-more-toolbar-info { color: #555; }
            .oy-gmb-more-toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }

            .oy-gmb-more-notice {
                padding: 8px 12px;
                border-radius: 4px;
                margin-bottom: 10px;
                font-size: 13px;
            }
            .oy-gmb-more-notice.success { background: #d4edda; border-left: 4px solid #46b450; }
            .oy-gmb-more-notice.error   { background: #f8d7da; border-left: 4px solid #dc3232; }
            .oy-gmb-more-notice.info    { background: #d5e5ff; border-left: 4px solid #2271b1; }

            /* Grupos */
            .oy-gmb-more-group {
                margin-bottom: 18px;
                border: 1px solid #e5e5e5;
                border-radius: 4px;
                overflow: hidden;
            }
            .oy-gmb-more-group-title {
                margin: 0;
                padding: 8px 12px;
                background: #f6f7f7;
                border-bottom: 1px solid #e5e5e5;
                font-size: 13px;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .oy-gmb-more-group-count {
                background: #2271b1;
                color: #fff;
                border-radius: 10px;
                padding: 1px 7px;
                font-size: 11px;
                font-weight: normal;
            }
            .oy-gmb-more-group-fields { padding: 0; }

            /* Campos individuales */
            .oy-gmb-more-field {
                display: flex;
                align-items: center;
                padding: 9px 12px;
                border-bottom: 1px solid #f0f0f0;
                gap: 12px;
                flex-wrap: wrap;
            }
            .oy-gmb-more-field:last-child { border-bottom: none; }
            .oy-gmb-more-field-label {
                flex: 0 0 280px;
                max-width: 280px;
                font-weight: 500;
                color: #23282d;
                cursor: pointer;
            }
            .oy-gmb-more-field-control { flex: 1; min-width: 200px; }
            .oy-gmb-more-field-unset {
                font-size: 11px;
                color: #aaa;
                font-style: italic;
            }

            /* Bool radio group */
            .oy-gmb-bool-radio-group {
                display: flex;
                gap: 6px;
                flex-wrap: wrap;
            }
            .oy-gmb-radio-option {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                padding: 5px 12px;
                border: 1px solid #ccc;
                border-radius: 4px;
                cursor: pointer;
                transition: all 0.15s;
                white-space: nowrap;
            }
            .oy-gmb-radio-option:hover { background: #f0f6ff; border-color: #2271b1; }
            .oy-gmb-radio-option input[type="radio"] { margin: 0; }
            .oy-gmb-radio-option input[type="radio"]:checked + .oy-gmb-radio-label { font-weight: 600; }
            .oy-gmb-radio-yes  input[type="radio"]:checked ~ * { color: #1e7e34; }
            .oy-gmb-radio-no   input[type="radio"]:checked ~ * { color: #c0392b; }
            .oy-gmb-radio-yes:has(input:checked) { background: #d4edda; border-color: #46b450; }
            .oy-gmb-radio-no:has(input:checked)  { background: #fde8e8; border-color: #dc3232; }
            .oy-gmb-radio-unset:has(input:checked) { background: #f0f0f0; border-color: #999; }

            /* REPEATED_ENUM checkboxes */
            .oy-gmb-repeated-enum-group { display: flex; flex-wrap: wrap; gap: 6px; }
            .oy-gmb-checkbox-option {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                padding: 4px 10px;
                border: 1px solid #ccc;
                border-radius: 4px;
                cursor: pointer;
            }
            .oy-gmb-checkbox-option:hover { background: #f0f6ff; }

            /* Botones en toolbar */
            .oy-gmb-more-btn-refresh .dashicons,
            .oy-gmb-more-btn-push .dashicons {
                vertical-align: middle;
            }

            /* Responsive */
            @media (max-width: 768px) {
                .oy-gmb-more-field-label { flex: 1 1 100%; max-width: 100%; }
                .oy-gmb-more-field-control { flex: 1 1 100%; }
            }
            ';
        }

    } // end class OY_Location_GMB_More_Metabox

} // end if class_exists
