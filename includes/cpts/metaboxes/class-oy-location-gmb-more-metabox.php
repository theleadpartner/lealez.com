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

    // Guardar cuando WordPress hace save_post (compatibilidad con el flujo clásico del post)
    add_action( 'save_post_oy_location', array( $this, 'save_metabox' ), 15, 2 );

    // AJAX: guardar cambios locales del metabox sin publicar el post completo
    add_action( 'wp_ajax_oy_gmb_more_save_metabox', array( $this, 'ajax_save_metabox' ) );

    // AJAX: refrescar metadatos desde la API de Google
    add_action( 'wp_ajax_oy_gmb_more_refresh_metadata', array( $this, 'ajax_refresh_metadata' ) );

    // AJAX: renderizar (pintar) los atributos en la UI del metabox sin llamar a Google
    add_action( 'wp_ajax_oy_gmb_more_render_attributes', array( $this, 'ajax_render_attributes' ) );

    // AJAX: enviar atributos editados a la API de Google
    add_action( 'wp_ajax_oy_gmb_more_push_to_gmb', array( $this, 'ajax_push_to_gmb' ) );

    // AJAX: leer nuevamente los atributos desde Google y verificar el último envío
    add_action( 'wp_ajax_oy_gmb_more_check_push_status', array( $this, 'ajax_check_push_status' ) );
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
                'confirmPush'    => __( '¿Enviar los cambios guardados localmente a Google Business Profile?', 'lealez' ),

                'editMode'       => __( 'Modo edición activo.', 'lealez' ),
                'readMode'       => __( 'Modo lectura.', 'lealez' ),
                'dirtyState'     => __( 'Tienes cambios locales sin guardar.', 'lealez' ),
                'saving'         => __( 'Guardando metabox...', 'lealez' ),
                'saveDone'       => __( 'Cambios locales guardados.', 'lealez' ),
                'saveError'      => __( 'No se pudieron guardar los cambios locales.', 'lealez' ),
                'mustSaveFirst'  => __( 'Primero guarda los cambios locales del metabox antes de enviar a Google.', 'lealez' ),
                'checking'       => __( 'Verificando estado en Google...', 'lealez' ),
                'checkError'     => __( 'No se pudo verificar el estado en Google.', 'lealez' ),
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
                            class="button button-secondary oy-gmb-more-btn-edit"
                            data-post-id="<?php echo esc_attr( $post_id ); ?>"
                            <?php disabled( ! $has_metadata ); ?>>
                        <span class="dashicons dashicons-edit" style="margin-top:3px;"></span>
                        <?php esc_html_e( 'Editar atributos', 'lealez' ); ?>
                    </button>

                    <button type="button"
                            class="button button-primary oy-gmb-more-btn-save"
                            data-post-id="<?php echo esc_attr( $post_id ); ?>"
                            style="display:none;">
                        <span class="dashicons dashicons-saved" style="margin-top:3px;"></span>
                        <?php esc_html_e( 'Guardar metabox', 'lealez' ); ?>
                    </button>

                    <button type="button"
                            class="button oy-gmb-more-btn-cancel"
                            data-post-id="<?php echo esc_attr( $post_id ); ?>"
                            style="display:none;">
                        <span class="dashicons dashicons-no-alt" style="margin-top:3px;"></span>
                        <?php esc_html_e( 'Cancelar edición', 'lealez' ); ?>
                    </button>

                    <button type="button"
                            class="button button-primary oy-gmb-more-btn-push"
                            data-post-id="<?php echo esc_attr( $post_id ); ?>"
                            data-base-disabled="<?php echo esc_attr( ! $has_metadata ? '1' : '0' ); ?>"
                            <?php disabled( ! $has_metadata ); ?>>
                        <span class="dashicons dashicons-upload" style="margin-top:3px;"></span>
                        <?php esc_html_e( 'Enviar a Google ↑', 'lealez' ); ?>
                    </button>

                <?php endif; ?>
            </span>
        </div>

        <div class="oy-gmb-more-editor-state" id="oy-gmb-more-editor-state-<?php echo esc_attr( $post_id ); ?>"></div>

        <div class="oy-gmb-more-notice" id="oy-gmb-more-notice-<?php echo esc_attr( $post_id ); ?>" style="display:none;"></div>

        <?php echo $this->render_more_push_panel( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

        <?php echo $this->render_more_log_panel( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

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
        <input type="hidden"
               name="oy_gmb_more_editor_active"
               id="oy_gmb_more_editor_active_<?php echo esc_attr( $post_id ); ?>"
               value="0" />

    </div><!-- /.oy-gmb-more-wrapper -->
    <?php
}

/**
 * Renderiza los atributos agrupados por `groupDisplayName`.
 * Los grupos que quedan vacíos tras filtrar atributos URL/deprecated se omiten,
 * evitando que aparezca un cabezote sin contenido.
 *
 * @param array $metadata       Array de AttributeMetadata objects.
 * @param array $current_values Mapa [attr_id => value].
 * @param int   $post_id        ID del post.
 */
private function render_attribute_groups( $metadata, $current_values, $post_id ) {
    // ── Organizar por grupo ──────────────────────────────────────────────────
    $groups = array();
    foreach ( $metadata as $attr_meta ) {
        if ( ! is_array( $attr_meta ) ) {
            continue;
        }

        $attr_id = $this->extract_attr_id_from_meta( $attr_meta );
        if ( '' === $attr_id ) {
            continue;
        }

        // ── Pre-filtro: excluir deprecated ──────────────────────────────────
        $is_deprecated = ! empty( $attr_meta['deprecated'] ) || ! empty( $attr_meta['isDeprecated'] );
        if ( $is_deprecated ) {
            continue;
        }

        // ── Pre-filtro: excluir atributos de tipo URL ────────────────────────
        // Estos ya están gestionados en class-oy-location-cpt.php (redes sociales,
        // URLs de reservas, pedidos, menú, WhatsApp, etc.). Omitirlos aquí evita
        // duplicados y que aparezcan cabezotes de grupo vacíos.
        $value_type = isset( $attr_meta['valueType'] ) ? strtoupper( (string) $attr_meta['valueType'] ) : 'BOOL';
        if ( 'URL' === $value_type ) {
            continue;
        }

        // ── Pre-filtro: excluir el campo no funcional "Chat principal" ─────
        // Este atributo aparece dentro del grupo "Atributos de la página de un lugar"
        // y no está aportando una función operativa en Lealez. Se oculta solo de
        // este metabox para no afectar la sincronización RAW existente con Google.
        if ( $this->should_exclude_more_attribute_from_metabox( $attr_id, $attr_meta ) ) {
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

    // ── Priorizar el grupo "Información proporcionada por la empresa" ────────
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

    // ── Renderizar cada grupo (solo si tiene atributos visibles) ────────────
    foreach ( $sorted_groups as $group_name => $attrs ) {
        // Saltar grupos vacíos (por si quedaron tras el filtro)
        if ( empty( $attrs ) ) {
            continue;
        }

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
     * ✅ FILTRO Chat principal:
     * Si este método es llamado directamente, volvemos a validar que el atributo
     * no pertenezca al campo no funcional "Chat principal" del grupo
     * "Atributos de la página de un lugar".
     */
    if ( $this->should_exclude_more_attribute_from_metabox( $attr_id, $attr_meta ) ) {
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
        <input type="hidden"
               name="oy_gmb_more_attr_present[<?php echo esc_attr( $attr_id ); ?>]"
               value="1"
               class="oy-gmb-more-present-input" />
        <input type="hidden"
               name="oy_gmb_more_attr_type[<?php echo esc_attr( $attr_id ); ?>]"
               value="<?php echo esc_attr( $value_type ); ?>"
               class="oy-gmb-more-type-input" />
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

            // Si los campos están en modo lectura, los inputs visibles están deshabilitados.
            // Los hidden técnicos sí viajan en POST, por eso exigimos la bandera de edición activa
            // para no limpiar cambios al presionar el botón general "Actualizar" del post.
            $editor_active = isset( $_POST['oy_gmb_more_editor_active'] )
                ? sanitize_text_field( wp_unslash( $_POST['oy_gmb_more_editor_active'] ) )
                : '0';
            if ( '1' !== $editor_active ) {
                return;
            }

            if ( ! isset( $_POST['oy_gmb_more_attr_present'] ) && ! isset( $_POST['oy_gmb_more_attr'] ) ) {
                return;
            }

            $payload = $this->build_more_attributes_payload_from_request();
            $this->persist_more_attributes_payload( $post_id, $payload, 'post_update_save' );
        }


        /**
         * Construye el payload normalizado de atributos desde la petición POST.
         *
         * @return array
         */
        private function build_more_attributes_payload_from_request() {
            $raw_input = array();
            if ( isset( $_POST['oy_gmb_more_attr'] ) && is_array( $_POST['oy_gmb_more_attr'] ) ) {
                $raw_input = wp_unslash( $_POST['oy_gmb_more_attr'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            }

            $present = array();
            if ( isset( $_POST['oy_gmb_more_attr_present'] ) && is_array( $_POST['oy_gmb_more_attr_present'] ) ) {
                $present = array_keys( wp_unslash( $_POST['oy_gmb_more_attr_present'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            } elseif ( ! empty( $raw_input ) ) {
                $present = array_keys( $raw_input );
            }

            $types = array();
            if ( isset( $_POST['oy_gmb_more_attr_type'] ) && is_array( $_POST['oy_gmb_more_attr_type'] ) ) {
                $raw_types = wp_unslash( $_POST['oy_gmb_more_attr_type'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                foreach ( $raw_types as $attr_id => $type ) {
                    $types[ sanitize_key( $attr_id ) ] = strtoupper( sanitize_text_field( (string) $type ) );
                }
            }

            $sanitized = array();

            foreach ( $present as $attr_id ) {
                $attr_id = sanitize_key( $attr_id );
                if ( '' === $attr_id ) {
                    continue;
                }

                $value_type = isset( $types[ $attr_id ] ) ? $types[ $attr_id ] : '';

                if ( array_key_exists( $attr_id, $raw_input ) ) {
                    $value = $raw_input[ $attr_id ];

                    if ( is_array( $value ) ) {
                        $clean_values = array();
                        foreach ( $value as $item ) {
                            $item = sanitize_text_field( (string) $item );
                            if ( '' !== $item ) {
                                $clean_values[] = $item;
                            }
                        }
                        $sanitized[ $attr_id ] = array_values( array_unique( $clean_values ) );
                    } else {
                        $sanitized[ $attr_id ] = sanitize_text_field( (string) $value );
                    }
                } else {
                    // En REPEATED_ENUM, si no llega ningún checkbox marcado, significa limpiar el atributo.
                    $sanitized[ $attr_id ] = ( 'REPEATED_ENUM' === $value_type ) ? array() : '';
                }
            }

            $request_post_id = 0;
            if ( isset( $_POST['post_id'] ) ) {
                $request_post_id = absint( $_POST['post_id'] );
            } elseif ( isset( $_POST['post_ID'] ) ) {
                $request_post_id = absint( $_POST['post_ID'] );
            }

            return $this->filter_excluded_more_attributes_from_payload( $sanitized, $request_post_id );
        }

        /**
         * Persiste cambios locales comparando contra lo último sincronizado desde GMB.
         * Solo guarda overrides realmente diferentes para no reenviar atributos sin cambios.
         *
         * @param int    $post_id
         * @param array  $submitted
         * @param string $save_source
         * @return array
         */
        private function persist_more_attributes_payload( $post_id, array $submitted, $save_source = 'manual_metabox_save' ) {
            $post_id     = absint( $post_id );
            $save_source = sanitize_key( (string) $save_source );

            if ( ! $post_id ) {
                return array();
            }

            // Mantener fuera del flujo local cualquier atributo oculto de este metabox
            // aunque llegue desde un DOM antiguo, caché de navegador o payload manual.
            $submitted = $this->filter_excluded_more_attributes_from_payload( $submitted, $post_id );

            $raw_attributes = get_post_meta( $post_id, 'gmb_attributes_raw', true );
            if ( is_string( $raw_attributes ) && '' !== trim( $raw_attributes ) ) {
                $decoded = json_decode( $raw_attributes, true );
                if ( is_array( $decoded ) ) {
                    $raw_attributes = $decoded;
                }
            }
            if ( ! is_array( $raw_attributes ) ) {
                $raw_attributes = array();
            }

            $base_values = $this->build_current_values_map( $raw_attributes );
            $overrides   = array();

            foreach ( $submitted as $attr_id => $value ) {
                $attr_id = sanitize_key( $attr_id );
                if ( '' === $attr_id ) {
                    continue;
                }

                $base_value = array_key_exists( $attr_id, $base_values ) ? $base_values[ $attr_id ] : null;

                if ( ! $this->attribute_values_equal( $base_value, $value ) ) {
                    $overrides[ $attr_id ] = $value;
                }
            }

            $now_ts   = current_time( 'timestamp' );
            $user     = wp_get_current_user();
            $by       = ( $user instanceof WP_User && ! empty( $user->user_login ) ) ? $user->user_login : 'system';
            $at_label = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $now_ts );

            $save_meta = array(
                'at'              => gmdate( 'Y-m-d\TH:i:s\Z', $now_ts ),
                'at_ts'           => $now_ts,
                'at_label'        => $at_label,
                'by'              => $by,
                'source'          => $save_source,
                'submitted_count' => count( $submitted ),
                'changed_count'   => count( $overrides ),
            );

            if ( ! empty( $overrides ) ) {
                update_post_meta( $post_id, '_gmb_more_attributes_overrides', wp_json_encode( $overrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
                update_post_meta( $post_id, 'oy_gmb_more_local_pending_publish', '1' );
            } else {
                delete_post_meta( $post_id, '_gmb_more_attributes_overrides' );
                delete_post_meta( $post_id, 'oy_gmb_more_local_pending_publish' );
            }

            update_post_meta( $post_id, 'oy_gmb_more_last_manual_save', $save_meta );
            update_post_meta( $post_id, 'date_modified', current_time( 'mysql' ) );
            update_post_meta( $post_id, 'modified_by_user_id', get_current_user_id() );

            $detail = ! empty( $overrides )
                ? sprintf( 'Se guardaron %d cambio(s) local(es) del metabox Más. Pendiente por publicar en GMB.', count( $overrides ) )
                : 'Se guardó el metabox Más, pero no quedaron diferencias locales frente a lo sincronizado desde GMB.';

            $this->append_more_job_history( $post_id, array(
                'event'  => 'local_metabox_save',
                'at'     => $save_meta['at'],
                'at_ts'  => $save_meta['at_ts'],
                'by'     => $by,
                'detail' => $detail,
            ) );

            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    (int) get_post_meta( $post_id, 'parent_business_id', true ),
                    'info',
                    'GMB More attributes saved locally in Lealez.',
                    array(
                        'post_id'         => $post_id,
                        'submitted_count' => count( $submitted ),
                        'changed_count'   => count( $overrides ),
                        'source'          => $save_source,
                    )
                );
            }

            return $save_meta;
        }

        /**
         * Renderiza el panel de publicación y estado del flujo de la sección Más.
         *
         * @param int $post_id
         * @return string
         */
        private function render_more_push_panel( $post_id ) {
            $post_id      = absint( $post_id );
            $job          = get_post_meta( $post_id, 'gmb_more_push_job', true );
            $business_id  = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $location     = (string) get_post_meta( $post_id, 'gmb_location_name', true );
            $connected    = ! empty( $business_id ) && ! empty( $location );
            $pending      = (bool) get_post_meta( $post_id, 'oy_gmb_more_local_pending_publish', true );
            $last_save    = get_post_meta( $post_id, 'oy_gmb_more_last_manual_save', true );
            $last_label   = ( is_array( $last_save ) && ! empty( $last_save['at_label'] ) ) ? (string) $last_save['at_label'] : '';
            $last_user    = ( is_array( $last_save ) && ! empty( $last_save['by'] ) ) ? (string) $last_save['by'] : '';
            $changed_cnt  = ( is_array( $last_save ) && isset( $last_save['changed_count'] ) ) ? (int) $last_save['changed_count'] : 0;
            $push_disabled = $connected ? '' : 'disabled';
            $check_disabled = ( $connected && is_array( $job ) ) ? '' : 'disabled';

            ob_start();
            ?>
            <div id="oy-gmb-more-push-panel"
                 data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
                 style="border:1px solid #dadce0; border-radius:4px; background:#fff; margin-bottom:14px; overflow:hidden;">
                <div class="oy-gmb-more-panel-header">
                    <span class="oy-gmb-more-panel-title">📤 <?php esc_html_e( 'Publicar atributos de la sección Más en Google Business Profile', 'lealez' ); ?></span>
                    <div class="oy-gmb-more-panel-actions">
                        <button type="button" class="button button-primary oy-gmb-more-btn-push" data-base-disabled="<?php echo esc_attr( $connected ? '0' : '1' ); ?>" <?php echo $push_disabled; ?>>
                            <span class="dashicons dashicons-upload" style="margin-top:3px;"></span>
                            <?php esc_html_e( 'Enviar a GMB', 'lealez' ); ?>
                        </button>
                        <button type="button" class="button button-secondary oy-gmb-more-btn-check-status" data-base-disabled="<?php echo esc_attr( ( $connected && is_array( $job ) ) ? '0' : '1' ); ?>" <?php echo $check_disabled; ?>>
                            <span class="dashicons dashicons-search" style="margin-top:3px;"></span>
                            <?php esc_html_e( 'Verificar estado', 'lealez' ); ?>
                        </button>
                    </div>
                </div>
                <div class="oy-gmb-more-panel-body">
                    <?php if ( ! $connected ) : ?>
                        <div class="oy-gmb-more-panel-alert warning">
                            <?php esc_html_e( 'Esta ubicación aún no tiene empresa/ubicación GMB vinculada.', 'lealez' ); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( $pending ) : ?>
                        <div class="oy-gmb-more-panel-alert info">
                            <?php
                            printf(
                                esc_html__( 'Hay cambios locales pendientes por publicar en GMB%s.', 'lealez' ),
                                $changed_cnt > 0 ? ' (' . esc_html( (string) $changed_cnt ) . ')' : ''
                            );
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( $last_label ) : ?>
                        <div class="oy-gmb-more-panel-meta">
                            <strong><?php esc_html_e( 'Último guardado local:', 'lealez' ); ?></strong>
                            <?php echo esc_html( $last_label ); ?>
                            <?php if ( $last_user ) : ?> — <?php echo esc_html( $last_user ); ?><?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( is_array( $job ) ) : ?>
                        <?php
                        $status = (string) ( $job['status'] ?? 'queued' );
                        $status_map = array(
                            'queued'          => array( 'label' => __( 'En cola', 'lealez' ), 'class' => 'info' ),
                            'applied'         => array( 'label' => __( 'Aplicado', 'lealez' ), 'class' => 'success' ),
                            'partial'         => array( 'label' => __( 'Aplicado parcialmente / verificar', 'lealez' ), 'class' => 'warning' ),
                            'rejected'        => array( 'label' => __( 'Rechazado / No aplicado', 'lealez' ), 'class' => 'error' ),
                            'google_override' => array( 'label' => __( 'Google devolvió un resultado diferente', 'lealez' ), 'class' => 'warning' ),
                            'error'           => array( 'label' => __( 'Error', 'lealez' ), 'class' => 'error' ),
                        );
                        $style = isset( $status_map[ $status ] ) ? $status_map[ $status ] : $status_map['queued'];
                        ?>
                        <div class="oy-gmb-more-panel-alert <?php echo esc_attr( $style['class'] ); ?>">
                            <strong><?php esc_html_e( 'Estado del último envío:', 'lealez' ); ?></strong>
                            <?php echo esc_html( $style['label'] ); ?>
                        </div>

                        <?php if ( ! empty( $job['pushed_at'] ) ) : ?>
                            <div class="oy-gmb-more-panel-meta">
                                <strong><?php esc_html_e( 'Último envío:', 'lealez' ); ?></strong>
                                <?php echo esc_html( (string) $job['pushed_at'] ); ?>
                                <?php if ( ! empty( $job['pushed_by'] ) ) : ?> — <?php echo esc_html( (string) $job['pushed_by'] ); ?><?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $job['attribute_mask'] ) ) : ?>
                            <div class="oy-gmb-more-panel-meta">
                                <strong><?php esc_html_e( 'attributeMask:', 'lealez' ); ?></strong>
                                <code><?php echo esc_html( (string) $job['attribute_mask'] ); ?></code>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $job['history'] ) && is_array( $job['history'] ) ) : ?>
                            <div class="oy-gmb-more-history-wrap">
                                <strong><?php esc_html_e( 'Historial del metabox', 'lealez' ); ?></strong>
                                <div class="oy-gmb-more-history-scroll">
                                    <table class="oy-gmb-more-history-table">
                                        <thead>
                                            <tr>
                                                <th><?php esc_html_e( 'Fecha', 'lealez' ); ?></th>
                                                <th><?php esc_html_e( 'Actor', 'lealez' ); ?></th>
                                                <th><?php esc_html_e( 'Detalle', 'lealez' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( array_reverse( $job['history'] ) as $entry ) : ?>
                                                <tr>
                                                    <td><?php echo esc_html( (string) ( $entry['at'] ?? '' ) ); ?></td>
                                                    <td><?php echo esc_html( (string) ( $entry['by'] ?? 'system' ) ); ?></td>
                                                    <td><?php echo esc_html( (string) ( $entry['detail'] ?? ( $entry['event'] ?? '' ) ) ); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /**
         * Renderiza el log visual local del navegador.
         *
         * @param int $post_id
         * @return string
         */
        private function render_more_log_panel( $post_id ) {
            $post_id = absint( $post_id );

            ob_start();
            ?>
            <div id="oy-gmb-more-log-panel" data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
                <div id="oy-gmb-more-log-header">
                    <span>🔍 <?php esc_html_e( 'Log de Sincronización — Más / Atributos', 'lealez' ); ?></span>
                    <span id="oy-gmb-more-log-toggle-icon">▶</span>
                </div>
                <div id="oy-gmb-more-log-body" style="display:none;">
                    <div id="oy-gmb-more-log-entries"></div>
                    <div id="oy-gmb-more-log-footer">
                        <button type="button" id="oy-gmb-more-log-clear" class="button button-small">
                            🗑 <?php esc_html_e( 'Limpiar historial', 'lealez' ); ?>
                        </button>
                        <span><?php esc_html_e( 'Historial guardado en el navegador (localStorage). Máx 20 entradas.', 'lealez' ); ?></span>
                    </div>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /**
         * AJAX: Guardado independiente de cambios locales.
         */
        public function ajax_save_metabox() {
            check_ajax_referer( self::NONCE_AJAX_ACTION, 'nonce' );

            $post_id = absint( $_POST['post_id'] ?? 0 );
            if ( ! $post_id ) {
                wp_send_json_error( array( 'message' => __( 'post_id inválido.', 'lealez' ) ) );
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'No tienes permisos para editar esta ubicación.', 'lealez' ) ) );
            }

            $post = get_post( $post_id );
            if ( ! $post || 'oy_location' !== $post->post_type ) {
                wp_send_json_error( array( 'message' => __( 'Post no válido o no es una oy_location.', 'lealez' ) ) );
            }

            $submitted = $this->build_more_attributes_payload_from_request();
            $before    = $this->get_more_base_snapshot( $post_id );
            $save_meta = $this->persist_more_attributes_payload( $post_id, $submitted, 'manual_metabox_save' );
            $after     = $this->get_more_local_snapshot( $post_id );

            wp_send_json_success( array(
                'message'    => empty( $save_meta['changed_count'] )
                    ? __( 'Metabox guardado. No quedaron cambios pendientes frente a GMB.', 'lealez' )
                    : sprintf( __( 'Cambios locales guardados: %d atributo(s) pendiente(s) por publicar.', 'lealez' ), (int) $save_meta['changed_count'] ),
                'save_meta'  => $save_meta,
                'panel_html' => $this->render_more_push_panel( $post_id ),
                'log_context' => array(
                    'before' => $before,
                    'after'  => $after,
                    'raw'    => array(
                        'action'      => 'manual_gmb_more_metabox_save',
                        'save_meta'   => $save_meta,
                        'submitted'   => $submitted,
                    ),
                ),
            ) );
        }


        /**
         * Compara valores normalizados para detectar cambios reales.
         *
         * @param mixed $a
         * @param mixed $b
         * @return bool
         */
        private function attribute_values_equal( $a, $b ) {
            return $this->normalize_attribute_value_for_compare( $a ) === $this->normalize_attribute_value_for_compare( $b );
        }

        /**
         * Normaliza valores de atributos para comparación.
         *
         * @param mixed $value
         * @return mixed
         */
        private function normalize_attribute_value_for_compare( $value ) {
            if ( null === $value ) {
                return '';
            }

            if ( is_bool( $value ) ) {
                return $value ? 'true' : 'false';
            }

            if ( is_array( $value ) ) {
                $clean = array();
                foreach ( $value as $item ) {
                    if ( is_bool( $item ) ) {
                        $item = $item ? 'true' : 'false';
                    } elseif ( is_array( $item ) ) {
                        $item = wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                    }
                    $item = sanitize_text_field( (string) $item );
                    if ( '' !== $item ) {
                        $clean[] = $item;
                    }
                }
                $clean = array_values( array_unique( $clean ) );
                sort( $clean, SORT_NATURAL );
                return empty( $clean ) ? '' : $clean;
            }

            $value = sanitize_text_field( (string) $value );
            if ( '1' === $value ) {
                return 'true';
            }
            if ( '0' === $value ) {
                return 'false';
            }
            return $value;
        }

        /**
         * Agrega una entrada al historial persistente del metabox Más.
         *
         * @param int   $post_id
         * @param array $entry
         * @return void
         */
        private function append_more_job_history( $post_id, array $entry ) {
            $post_id = absint( $post_id );
            if ( ! $post_id ) {
                return;
            }

            $job = get_post_meta( $post_id, 'gmb_more_push_job', true );
            if ( ! is_array( $job ) ) {
                $job = array(
                    'status'  => 'queued',
                    'history' => array(),
                );
            }
            if ( ! isset( $job['history'] ) || ! is_array( $job['history'] ) ) {
                $job['history'] = array();
            }

            $job['history'][] = $entry;
            if ( count( $job['history'] ) > 50 ) {
                $job['history'] = array_slice( $job['history'], -50 );
            }

            update_post_meta( $post_id, 'gmb_more_push_job', $job );
        }

        /**
         * Snapshot de valores sincronizados desde GMB, sin overrides.
         *
         * @param int $post_id
         * @return array
         */
        private function get_more_base_snapshot( $post_id ) {
            $raw = get_post_meta( $post_id, 'gmb_attributes_raw', true );
            if ( is_string( $raw ) && '' !== trim( $raw ) ) {
                $decoded = json_decode( $raw, true );
                if ( is_array( $decoded ) ) {
                    $raw = $decoded;
                }
            }
            if ( ! is_array( $raw ) ) {
                $raw = array();
            }

            return $this->get_more_snapshot_from_map( $post_id, $this->build_current_values_map( $raw ) );
        }

        /**
         * Snapshot local efectivo: GMB RAW + overrides pendientes.
         *
         * @param int $post_id
         * @return array
         */
        private function get_more_local_snapshot( $post_id ) {
            $raw = get_post_meta( $post_id, 'gmb_attributes_raw', true );
            if ( is_string( $raw ) && '' !== trim( $raw ) ) {
                $decoded = json_decode( $raw, true );
                if ( is_array( $decoded ) ) {
                    $raw = $decoded;
                }
            }
            if ( ! is_array( $raw ) ) {
                $raw = array();
            }

            $map = $this->build_current_values_map( $raw );
            $overrides_json = get_post_meta( $post_id, '_gmb_more_attributes_overrides', true );
            $overrides      = array();
            if ( is_string( $overrides_json ) && '' !== trim( $overrides_json ) ) {
                $decoded = json_decode( $overrides_json, true );
                if ( is_array( $decoded ) ) {
                    $overrides = $decoded;
                }
            }
            $overrides = $this->filter_excluded_more_attributes_from_payload( $overrides, $post_id );
            foreach ( $overrides as $attr_id => $value ) {
                $map[ sanitize_key( $attr_id ) ] = $value;
            }

            return $this->get_more_snapshot_from_map( $post_id, $map );
        }

        /**
         * Snapshot a partir del array de atributos retornado por GMB.
         *
         * @param int   $post_id
         * @param array $attributes
         * @return array
         */
        private function get_more_snapshot_from_attributes( $post_id, array $attributes ) {
            return $this->get_more_snapshot_from_map( $post_id, $this->build_current_values_map( $attributes ) );
        }

        /**
         * Construye snapshot legible para logs.
         *
         * @param int   $post_id
         * @param array $map
         * @return array
         */
        private function get_more_snapshot_from_map( $post_id, array $map ) {
            $labels   = $this->get_more_attribute_label_map( $post_id );
            $snapshot = array();

            foreach ( $map as $attr_id => $value ) {
                $attr_id = sanitize_key( $attr_id );
                if ( '' === $attr_id ) {
                    continue;
                }

                if ( $this->is_excluded_more_attribute_id( $attr_id, $post_id ) ) {
                    continue;
                }

                $snapshot[ $attr_id ] = array(
                    'label'   => isset( $labels[ $attr_id ] ) ? $labels[ $attr_id ] : $this->humanize_attr_id( $attr_id ),
                    'value'   => $this->normalize_attribute_value_for_compare( $value ),
                    'display' => $this->format_attribute_value_for_log( $value ),
                );
            }

            ksort( $snapshot );
            return $snapshot;
        }

        /**
         * Obtiene mapa [attr_id => displayName] desde metadata cacheada.
         *
         * @param int $post_id
         * @return array
         */
        private function get_more_attribute_label_map( $post_id ) {
            $metadata = get_post_meta( $post_id, '_gmb_attrs_metadata', true );
            if ( is_string( $metadata ) && '' !== trim( $metadata ) ) {
                $decoded = json_decode( $metadata, true );
                if ( is_array( $decoded ) ) {
                    $metadata = $decoded;
                }
            }
            if ( ! is_array( $metadata ) ) {
                $metadata = array();
            }

            $labels = array();
            foreach ( $metadata as $attr_meta ) {
                if ( ! is_array( $attr_meta ) ) {
                    continue;
                }
                $attr_id = $this->extract_attr_id_from_meta( $attr_meta );
                if ( '' === $attr_id ) {
                    continue;
                }
                if ( $this->should_exclude_more_attribute_from_metabox( $attr_id, $attr_meta ) ) {
                    continue;
                }
                $labels[ $attr_id ] = ! empty( $attr_meta['displayName'] ) ? (string) $attr_meta['displayName'] : $this->humanize_attr_id( $attr_id );
            }

            return $labels;
        }

        /**
         * Formatea un valor para mostrar en el log.
         *
         * @param mixed $value
         * @return string
         */
        private function format_attribute_value_for_log( $value ) {
            if ( null === $value || '' === $value ) {
                return __( 'No especificado', 'lealez' );
            }
            if ( true === $value || 'true' === $value || '1' === $value || 1 === $value ) {
                return __( 'Sí', 'lealez' );
            }
            if ( false === $value || 'false' === $value || '0' === $value || 0 === $value ) {
                return __( 'No', 'lealez' );
            }
            if ( is_array( $value ) ) {
                $clean = array();
                foreach ( $value as $item ) {
                    if ( is_scalar( $item ) ) {
                        $clean[] = (string) $item;
                    }
                }
                return empty( $clean ) ? __( 'No especificado', 'lealez' ) : implode( ', ', $clean );
            }

            return (string) $value;
        }

        /**
         * Determina si el estado remoto coincide con lo enviado.
         *
         * @param array $submitted_map
         * @param array $current_map
         * @param array $before_map
         * @return string
         */
        private function determine_more_push_outcome( array $submitted_map, array $current_map, array $before_map = array() ) {
            $checked = 0;
            $matched = 0;
            $still_before = 0;

            foreach ( $submitted_map as $attr_id => $value ) {
                $attr_id = sanitize_key( $attr_id );
                if ( '' === $attr_id ) {
                    continue;
                }
                if ( $this->is_excluded_more_attribute_id( $attr_id ) ) {
                    continue;
                }
                $checked++;

                $submitted_norm = $this->normalize_attribute_value_for_compare( $value );
                $current_value  = array_key_exists( $attr_id, $current_map ) ? $current_map[ $attr_id ] : null;
                $current_norm   = $this->normalize_attribute_value_for_compare( $current_value );
                $before_value   = array_key_exists( $attr_id, $before_map ) ? $before_map[ $attr_id ] : null;
                $before_norm    = $this->normalize_attribute_value_for_compare( $before_value );

                if ( $current_norm === $submitted_norm ) {
                    $matched++;
                }
                if ( $current_norm === $before_norm ) {
                    $still_before++;
                }
            }

            if ( 0 === $checked ) {
                return 'error';
            }
            if ( $matched === $checked ) {
                return 'applied';
            }
            if ( $matched > 0 ) {
                return 'partial';
            }
            if ( $still_before === $checked ) {
                return 'rejected';
            }
            return 'google_override';
        }

        /**
         * Normaliza un ID de atributo desde nombres cortos o resource names.
         *
         * @param string $raw
         * @return string
         */
        private function normalize_gmb_more_attribute_id( $raw ) {
            $raw = trim( (string) $raw );
            if ( '' === $raw ) {
                return '';
            }
            if ( false !== strpos( $raw, '/attributes/' ) ) {
                $parts = explode( '/attributes/', $raw, 2 );
                $raw   = $parts[1] ?? '';
            } elseif ( 0 === strpos( $raw, 'attributes/' ) ) {
                $raw = substr( $raw, strlen( 'attributes/' ) );
            } elseif ( false !== strpos( $raw, 'attributes/' ) ) {
                $parts = explode( 'attributes/', $raw, 2 );
                $raw   = $parts[1] ?? '';
            }
            return sanitize_key( trim( $raw, '/' ) );
        }

        /**
         * Normaliza textos de metadata para comparaciones estables.
         *
         * @param string $text Texto original.
         * @return string
         */
        private function normalize_more_attribute_text( $text ) {
            $text = wp_strip_all_tags( (string) $text );
            $text = trim( $text );

            if ( function_exists( 'remove_accents' ) ) {
                $text = remove_accents( $text );
            }

            $text = strtolower( $text );
            $text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
            $text = preg_replace( '/\s+/', ' ', $text );

            return trim( (string) $text );
        }

        /**
         * Indica si el grupo corresponde a "Atributos de la página de un lugar".
         *
         * @param string $group_name Nombre del grupo entregado por Google.
         * @return bool
         */
        private function is_place_page_attribute_group( $group_name ) {
            $normalized = $this->normalize_more_attribute_text( $group_name );

            $blocked_groups = array(
                'atributos de la pagina de un lugar',
                'atributos pagina de un lugar',
                'place page attributes',
            );

            return in_array( $normalized, $blocked_groups, true );
        }

        /**
         * Determina si un atributo debe quedar fuera de este metabox.
         *
         * Por solicitud, se retira únicamente el campo "Chat principal" que Google
         * agrupa en "Atributos de la página de un lugar". La sincronización RAW con
         * Google se mantiene intacta; solo se oculta y se excluye del guardado/envío
         * de este metabox para que no genere cambios locales inútiles.
         *
         * @param string $attr_id   ID normalizado del atributo.
         * @param array  $attr_meta Metadata del atributo.
         * @return bool
         */
        private function should_exclude_more_attribute_from_metabox( $attr_id, $attr_meta = array() ) {
            $attr_id = sanitize_key( (string) $attr_id );
            if ( '' === $attr_id || ! is_array( $attr_meta ) ) {
                return false;
            }

            $group_name   = isset( $attr_meta['groupDisplayName'] ) ? (string) $attr_meta['groupDisplayName'] : '';
            $display_name = isset( $attr_meta['displayName'] ) ? (string) $attr_meta['displayName'] : '';

            if ( ! $this->is_place_page_attribute_group( $group_name ) ) {
                return false;
            }

            $display_normalized = $this->normalize_more_attribute_text( $display_name );
            $chat_labels        = array(
                'chat principal',
                'primary chat',
            );

            if ( in_array( $display_normalized, $chat_labels, true ) ) {
                return true;
            }

            // Respaldo por ID dentro del mismo grupo, por si Google cambia el idioma
            // del displayName pero conserva un identificador relacionado con chat.
            return false !== strpos( $attr_id, 'chat' );
        }

        /**
         * Obtiene los IDs de atributos ocultos de este metabox usando metadata cacheada.
         *
         * @param int        $post_id  ID del post.
         * @param array|null $metadata Metadata opcional ya cargada.
         * @return array
         */
        private function get_excluded_more_attribute_ids( $post_id = 0, $metadata = null ) {
            $post_id = absint( $post_id );

            if ( null === $metadata && $post_id ) {
                $metadata = get_post_meta( $post_id, '_gmb_attrs_metadata', true );
                if ( is_string( $metadata ) && '' !== trim( $metadata ) ) {
                    $decoded = json_decode( $metadata, true );
                    if ( is_array( $decoded ) ) {
                        $metadata = $decoded;
                    }
                }
            }

            if ( ! is_array( $metadata ) ) {
                $metadata = array();
            }

            $excluded = array();
            foreach ( $metadata as $attr_meta ) {
                if ( ! is_array( $attr_meta ) ) {
                    continue;
                }

                $attr_id = $this->extract_attr_id_from_meta( $attr_meta );
                if ( '' === $attr_id ) {
                    continue;
                }

                if ( $this->should_exclude_more_attribute_from_metabox( $attr_id, $attr_meta ) ) {
                    $excluded[] = sanitize_key( $attr_id );
                }
            }

            $excluded = array_values( array_unique( array_filter( $excluded ) ) );

            return $excluded;
        }

        /**
         * Verifica si un attr_id pertenece a los atributos ocultos de este metabox.
         *
         * @param string $attr_id ID del atributo.
         * @param int    $post_id ID del post, si está disponible.
         * @return bool
         */
        private function is_excluded_more_attribute_id( $attr_id, $post_id = 0 ) {
            $attr_id = sanitize_key( (string) $attr_id );
            if ( '' === $attr_id ) {
                return false;
            }

            $excluded_ids = $this->get_excluded_more_attribute_ids( $post_id );
            if ( in_array( $attr_id, $excluded_ids, true ) ) {
                return true;
            }

            // Fallback controlado: solo para IDs claramente asociados al chat principal
            // de place page cuando no se pudo leer metadata cacheada.
            $fallback_ids = array(
                'primary_chat',
                'chat_primary',
                'place_page_primary_chat',
                'primary_business_chat',
            );

            return in_array( $attr_id, $fallback_ids, true );
        }

        /**
         * Quita del payload local los atributos ocultos de este metabox.
         *
         * @param array      $payload  Payload [attr_id => value].
         * @param int        $post_id  ID del post.
         * @param array|null $metadata Metadata opcional.
         * @return array
         */
        private function filter_excluded_more_attributes_from_payload( array $payload, $post_id = 0, $metadata = null ) {
            if ( empty( $payload ) ) {
                return array();
            }

            $excluded_ids = $this->get_excluded_more_attribute_ids( $post_id, $metadata );
            if ( empty( $excluded_ids ) ) {
                return $payload;
            }

            foreach ( $excluded_ids as $excluded_id ) {
                if ( array_key_exists( $excluded_id, $payload ) ) {
                    unset( $payload[ $excluded_id ] );
                }
            }

            return $payload;
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
    $overrides = $this->filter_excluded_more_attributes_from_payload( $overrides, $post_id );
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

            $post_id = absint( $_POST['post_id'] ?? 0 );
            if ( ! $post_id ) {
                wp_send_json_error( array( 'message' => __( 'post_id inválido.', 'lealez' ) ) );
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'No tienes permisos para editar esta ubicación.', 'lealez' ) ) );
            }

            $parent_business_id = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $gmb_location_name  = (string) get_post_meta( $post_id, 'gmb_location_name', true );

            if ( ! $parent_business_id || ! $gmb_location_name ) {
                wp_send_json_error( array(
                    'message'    => __( 'Esta location no tiene una conexión GMB configurada.', 'lealez' ),
                    'panel_html' => $this->render_more_push_panel( $post_id ),
                ) );
            }

            $overrides_json = get_post_meta( $post_id, '_gmb_more_attributes_overrides', true );
            $overrides      = array();
            if ( is_string( $overrides_json ) && '' !== trim( $overrides_json ) ) {
                $decoded = json_decode( $overrides_json, true );
                if ( is_array( $decoded ) ) {
                    $overrides = $decoded;
                }
            } elseif ( is_array( $overrides_json ) ) {
                $overrides = $overrides_json;
            }

            $original_overrides_count = count( $overrides );
            $overrides                = $this->filter_excluded_more_attributes_from_payload( $overrides, $post_id );

            if ( count( $overrides ) !== $original_overrides_count ) {
                if ( ! empty( $overrides ) ) {
                    update_post_meta( $post_id, '_gmb_more_attributes_overrides', wp_json_encode( $overrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
                } else {
                    delete_post_meta( $post_id, '_gmb_more_attributes_overrides' );
                    delete_post_meta( $post_id, 'oy_gmb_more_local_pending_publish' );
                }

                $this->append_more_job_history( $post_id, array(
                    'event'  => 'hidden_attribute_removed_from_pending_payload',
                    'at'     => gmdate( 'Y-m-d\TH:i:s\Z', time() ),
                    'at_ts'  => time(),
                    'by'     => ( wp_get_current_user() instanceof WP_User ) ? wp_get_current_user()->user_login : 'system',
                    'detail' => 'Se retiró del payload pendiente el campo oculto Chat principal de Atributos de la página de un lugar.',
                ) );
            }

            if ( empty( $overrides ) ) {
                wp_send_json_error( array(
                    'message'    => __( 'No hay cambios locales pendientes para enviar. Primero entra en modo edición y guarda el metabox.', 'lealez' ),
                    'panel_html' => $this->render_more_push_panel( $post_id ),
                    'log_context' => array(
                        'before' => $this->get_more_base_snapshot( $post_id ),
                        'after'  => $this->get_more_local_snapshot( $post_id ),
                        'raw'    => array( 'action' => 'push_gmb_more_without_pending_changes' ),
                    ),
                ) );
            }

            if ( ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'update_location_attributes' ) ) {
                wp_send_json_error( array(
                    'message' => __( 'El método de actualización de atributos no está disponible en Lealez_GMB_API.', 'lealez' ),
                ) );
            }

            $snapshot_before_attrs = array();
            if ( method_exists( 'Lealez_GMB_API', 'get_location_attributes' ) ) {
                $snapshot_result = Lealez_GMB_API::get_location_attributes( $parent_business_id, $gmb_location_name, false );
                if ( is_wp_error( $snapshot_result ) ) {
                    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                        Lealez_GMB_Logger::log(
                            $parent_business_id,
                            'warning',
                            'Could not fetch GMB More attributes before push; using local raw snapshot.',
                            array(
                                'post_id'  => $post_id,
                                'location' => $gmb_location_name,
                                'error'    => $snapshot_result->get_error_message(),
                            )
                        );
                    }
                    $snapshot_before_attrs = get_post_meta( $post_id, 'gmb_attributes_raw', true );
                    if ( is_string( $snapshot_before_attrs ) && '' !== trim( $snapshot_before_attrs ) ) {
                        $tmp = json_decode( $snapshot_before_attrs, true );
                        if ( is_array( $tmp ) ) {
                            $snapshot_before_attrs = $tmp;
                        }
                    }
                    if ( ! is_array( $snapshot_before_attrs ) ) {
                        $snapshot_before_attrs = array();
                    }
                } elseif ( is_array( $snapshot_result ) ) {
                    $snapshot_before_attrs = $snapshot_result;
                }
            }

            $attributes_payload = $this->build_gmb_attributes_payload( $overrides );
            $attribute_mask     = array();
            foreach ( $overrides as $attr_id => $value ) {
                $attr_id = sanitize_key( $attr_id );
                if ( '' !== $attr_id ) {
                    $attribute_mask[] = 'attributes/' . $attr_id;
                }
            }
            $attribute_mask = array_values( array_unique( $attribute_mask ) );

            $result = Lealez_GMB_API::update_location_attributes(
                $parent_business_id,
                $gmb_location_name,
                $attributes_payload,
                $attribute_mask
            );

            if ( is_wp_error( $result ) ) {
                $err_data = $result->get_error_data();
                $this->append_more_job_history( $post_id, array(
                    'event'  => 'push_error',
                    'at'     => gmdate( 'Y-m-d\TH:i:s\Z', time() ),
                    'at_ts'  => time(),
                    'by'     => ( wp_get_current_user() instanceof WP_User ) ? wp_get_current_user()->user_login : 'system',
                    'detail' => 'Error al enviar atributos Más a GMB: ' . $result->get_error_message(),
                ) );

                $job = get_post_meta( $post_id, 'gmb_more_push_job', true );
                if ( ! is_array( $job ) ) {
                    $job = array( 'history' => array() );
                }
                $job['status']        = 'error';
                $job['last_error']    = $result->get_error_message();
                $job['error_data']    = is_array( $err_data ) ? $err_data : array();
                $job['attribute_mask'] = implode( ',', $attribute_mask );
                update_post_meta( $post_id, 'gmb_more_push_job', $job );

                wp_send_json_error( array(
                    'message'    => $result->get_error_message(),
                    'panel_html' => $this->render_more_push_panel( $post_id ),
                    'log_context' => array(
                        'before' => $this->get_more_snapshot_from_attributes( $post_id, is_array( $snapshot_before_attrs ) ? $snapshot_before_attrs : array() ),
                        'after'  => $this->get_more_local_snapshot( $post_id ),
                        'raw'    => array(
                            'action'            => 'push_gmb_more_error',
                            'error_message'     => $result->get_error_message(),
                            'error_data'        => is_array( $err_data ) ? $err_data : array(),
                            'submitted_payload' => $attributes_payload,
                            'attribute_mask'    => $attribute_mask,
                        ),
                    ),
                ) );
            }

            $fresh_attrs = array();
            $fresh_error = '';
            if ( method_exists( 'Lealez_GMB_API', 'get_location_attributes' ) ) {
                $fresh_result = Lealez_GMB_API::get_location_attributes( $parent_business_id, $gmb_location_name, false );
                if ( is_wp_error( $fresh_result ) ) {
                    $fresh_error = $fresh_result->get_error_message();
                    $fresh_attrs = $this->merge_overrides_into_raw( $post_id, $overrides );
                } elseif ( is_array( $fresh_result ) ) {
                    $fresh_attrs = $fresh_result;
                }
            }

            if ( empty( $fresh_attrs ) ) {
                $fresh_attrs = $this->merge_overrides_into_raw( $post_id, $overrides );
            }

            update_post_meta( $post_id, 'gmb_attributes_raw', $fresh_attrs );
            update_post_meta( $post_id, 'gmb_attributes_last_sync', current_time( 'mysql' ) );
            delete_post_meta( $post_id, '_gmb_more_attributes_overrides' );
            delete_post_meta( $post_id, 'oy_gmb_more_local_pending_publish' );

            $before_map    = $this->build_current_values_map( is_array( $snapshot_before_attrs ) ? $snapshot_before_attrs : array() );
            $current_map   = $this->build_current_values_map( is_array( $fresh_attrs ) ? $fresh_attrs : array() );
            $status        = $this->determine_more_push_outcome( $overrides, $current_map, $before_map );
            $current_user  = wp_get_current_user();
            $user_login    = ( $current_user instanceof WP_User && $current_user->user_login ) ? $current_user->user_login : 'system';
            $now_ts        = time();
            $now_iso       = gmdate( 'Y-m-d\TH:i:s\Z', $now_ts );
            $attribute_mask_str = implode( ',', $attribute_mask );

            $job = get_post_meta( $post_id, 'gmb_more_push_job', true );
            if ( ! is_array( $job ) ) {
                $job = array();
            }
            $history = isset( $job['history'] ) && is_array( $job['history'] ) ? $job['history'] : array();
            $history[] = array(
                'event'  => 'push_submitted',
                'at'     => $now_iso,
                'at_ts'  => $now_ts,
                'by'     => $user_login,
                'detail' => sprintf( 'Se enviaron %d atributo(s) de la sección Más a Google Business Profile. Resultado de verificación: %s.', count( $overrides ), $status ),
            );
            if ( $fresh_error ) {
                $history[] = array(
                    'event'  => 'post_push_refresh_warning',
                    'at'     => $now_iso,
                    'at_ts'  => $now_ts,
                    'by'     => 'system',
                    'detail' => 'Google aceptó el PATCH, pero la lectura posterior falló. Se actualizó el caché local fusionando los cambios. Error: ' . $fresh_error,
                );
            }
            if ( count( $history ) > 50 ) {
                $history = array_slice( $history, -50 );
            }

            $job = array(
                'status'          => $status,
                'pushed_at'       => $now_iso,
                'pushed_at_ts'    => $now_ts,
                'pushed_by'       => $user_login,
                'attribute_mask'  => $attribute_mask_str,
                'submitted'       => $overrides,
                'snapshot_before' => $this->get_more_snapshot_from_attributes( $post_id, is_array( $snapshot_before_attrs ) ? $snapshot_before_attrs : array() ),
                'snapshot_after'  => $this->get_more_snapshot_from_attributes( $post_id, is_array( $fresh_attrs ) ? $fresh_attrs : array() ),
                'patch_response'  => is_array( $result ) ? $result : array(),
                'history'         => $history,
            );
            update_post_meta( $post_id, 'gmb_more_push_job', $job );

            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $parent_business_id,
                    'success',
                    'GMB More attributes pushed from Lealez.',
                    array(
                        'post_id'        => $post_id,
                        'location'       => $gmb_location_name,
                        'attribute_mask' => $attribute_mask_str,
                        'status'         => $status,
                        'count'          => count( $overrides ),
                    )
                );
            }

            wp_send_json_success( array(
                'message'     => __( 'Atributos enviados a Google Business Profile. Lealez actualizó el estado local y el caché sincronizado.', 'lealez' ),
                'status'      => $status,
                'panel_html'  => $this->render_more_push_panel( $post_id ),
                'log_context' => array(
                    'before' => $job['snapshot_before'],
                    'after'  => $job['snapshot_after'],
                    'raw'    => array(
                        'action'          => 'push_gmb_more_to_gmb',
                        'attribute_mask'  => $attribute_mask_str,
                        'patch_response'  => is_array( $result ) ? $result : array(),
                        'submitted'       => $overrides,
                        'status'          => $status,
                        'post_push_refresh_warning' => $fresh_error,
                    ),
                ),
            ) );
        }

        /**
         * AJAX: Verifica el último envío leyendo nuevamente los atributos desde GMB.
         */
        public function ajax_check_push_status() {
            check_ajax_referer( self::NONCE_AJAX_ACTION, 'nonce' );

            $post_id = absint( $_POST['post_id'] ?? 0 );
            if ( ! $post_id ) {
                wp_send_json_error( array( 'message' => __( 'post_id inválido.', 'lealez' ) ) );
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'No tienes permisos para editar esta ubicación.', 'lealez' ) ) );
            }

            $job = get_post_meta( $post_id, 'gmb_more_push_job', true );
            if ( empty( $job ) || ! is_array( $job ) ) {
                wp_send_json_error( array( 'message' => __( 'No hay un envío registrado para verificar.', 'lealez' ) ) );
            }

            $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );

            if ( ! $business_id || ! $location_name ) {
                wp_send_json_error( array( 'message' => __( 'No hay empresa/ubicación GMB vinculada.', 'lealez' ) ) );
            }

            if ( ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'get_location_attributes' ) ) {
                wp_send_json_error( array( 'message' => __( 'No está disponible la lectura de atributos GMB.', 'lealez' ) ) );
            }

            $fresh_attrs = Lealez_GMB_API::get_location_attributes( $business_id, $location_name, false );
            if ( is_wp_error( $fresh_attrs ) ) {
                wp_send_json_error( array(
                    'message'    => __( 'Error al consultar GMB: ', 'lealez' ) . $fresh_attrs->get_error_message(),
                    'panel_html' => $this->render_more_push_panel( $post_id ),
                    'log_context' => array(
                        'before' => isset( $job['snapshot_before'] ) && is_array( $job['snapshot_before'] ) ? $job['snapshot_before'] : array(),
                        'after'  => $this->get_more_local_snapshot( $post_id ),
                        'raw'    => array(
                            'action'        => 'check_gmb_more_status_error',
                            'error_message' => $fresh_attrs->get_error_message(),
                            'error_data'    => $fresh_attrs->get_error_data(),
                        ),
                    ),
                ) );
            }

            update_post_meta( $post_id, 'gmb_attributes_raw', $fresh_attrs );
            update_post_meta( $post_id, 'gmb_attributes_last_sync', current_time( 'mysql' ) );

            $submitted  = isset( $job['submitted'] ) && is_array( $job['submitted'] ) ? $job['submitted'] : array();
            $before_map = array();
            if ( isset( $job['snapshot_before'] ) && is_array( $job['snapshot_before'] ) ) {
                foreach ( $job['snapshot_before'] as $attr_id => $item ) {
                    $before_map[ sanitize_key( $attr_id ) ] = isset( $item['value'] ) ? $item['value'] : null;
                }
            }
            $current_map = $this->build_current_values_map( is_array( $fresh_attrs ) ? $fresh_attrs : array() );
            $status      = $this->determine_more_push_outcome( $submitted, $current_map, $before_map );
            $now_ts      = time();
            $now_iso     = gmdate( 'Y-m-d\TH:i:s\Z', $now_ts );
            $user        = wp_get_current_user();
            $by          = ( $user instanceof WP_User && ! empty( $user->user_login ) ) ? $user->user_login : 'system';

            $job['status']         = $status;
            $job['last_checked_at'] = $now_iso;
            $job['snapshot_after'] = $this->get_more_snapshot_from_attributes( $post_id, is_array( $fresh_attrs ) ? $fresh_attrs : array() );
            if ( ! isset( $job['history'] ) || ! is_array( $job['history'] ) ) {
                $job['history'] = array();
            }
            $job['history'][] = array(
                'event'  => 'manual_check',
                'at'     => $now_iso,
                'at_ts'  => $now_ts,
                'by'     => $by,
                'detail' => 'Se verificó el estado de atributos Más contra Google. Resultado: ' . $status . '.',
            );
            if ( count( $job['history'] ) > 50 ) {
                $job['history'] = array_slice( $job['history'], -50 );
            }
            update_post_meta( $post_id, 'gmb_more_push_job', $job );

            wp_send_json_success( array(
                'message'     => __( 'Estado verificado contra Google Business Profile.', 'lealez' ),
                'status'      => $status,
                'panel_html'  => $this->render_more_push_panel( $post_id ),
                'log_context' => array(
                    'before' => isset( $job['snapshot_before'] ) && is_array( $job['snapshot_before'] ) ? $job['snapshot_before'] : array(),
                    'after'  => $job['snapshot_after'],
                    'raw'    => array(
                        'action' => 'check_gmb_more_status',
                        'status' => $status,
                        'job'    => $job,
                    ),
                ),
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
                $attr_id = sanitize_key( (string) $attr_id );
                if ( '' === $attr_id || $this->is_excluded_more_attribute_id( $attr_id ) ) {
                    continue;
                }

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
            if ( is_string( $raw ) && '' !== trim( $raw ) ) {
                $decoded = json_decode( $raw, true );
                if ( is_array( $decoded ) ) {
                    $raw = $decoded;
                }
            }
            if ( ! is_array( $raw ) ) {
                $raw = array();
            }

            $payload_attrs = $this->build_gmb_attributes_payload( $overrides );

            // Construir índice por ID normalizado, no por name completo, porque GMB puede
            // devolver locations/{id}/attributes/foo y el PATCH usa attributes/foo.
            $raw_index = array();
            foreach ( $raw as $i => $attr ) {
                if ( ! is_array( $attr ) ) {
                    continue;
                }

                $attr_id = '';
                if ( ! empty( $attr['attributeId'] ) ) {
                    $attr_id = $this->normalize_gmb_more_attribute_id( (string) $attr['attributeId'] );
                } elseif ( ! empty( $attr['name'] ) ) {
                    $attr_id = $this->normalize_gmb_more_attribute_id( (string) $attr['name'] );
                }

                if ( '' !== $attr_id ) {
                    $raw_index[ $attr_id ] = $i;
                }
            }

            foreach ( $payload_attrs as $new_attr ) {
                if ( ! is_array( $new_attr ) || empty( $new_attr['name'] ) ) {
                    continue;
                }

                $attr_id = $this->normalize_gmb_more_attribute_id( (string) $new_attr['name'] );
                if ( '' === $attr_id ) {
                    continue;
                }

                $has_values = false;
                if ( isset( $new_attr['values'] ) && is_array( $new_attr['values'] ) && ! empty( $new_attr['values'] ) ) {
                    $has_values = true;
                }
                if ( isset( $new_attr['uriValues'] ) && is_array( $new_attr['uriValues'] ) && ! empty( $new_attr['uriValues'] ) ) {
                    $has_values = true;
                }
                if ( isset( $new_attr['repeatedEnumValue']['setValues'] ) && is_array( $new_attr['repeatedEnumValue']['setValues'] ) && ! empty( $new_attr['repeatedEnumValue']['setValues'] ) ) {
                    $has_values = true;
                }

                if ( ! $has_values ) {
                    if ( isset( $raw_index[ $attr_id ] ) ) {
                        unset( $raw[ $raw_index[ $attr_id ] ] );
                    }
                    continue;
                }

                $new_attr['name'] = 'attributes/' . $attr_id;

                if ( isset( $raw_index[ $attr_id ] ) ) {
                    $raw[ $raw_index[ $attr_id ] ] = $new_attr;
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

            /* Estado de edición */
            .oy-gmb-more-editor-state {
                margin: 0 0 10px;
                font-size: 12px;
                color: #646970;
            }
            .oy-gmb-more-wrapper.is-editing .oy-gmb-more-content {
                outline: 2px solid rgba(34,113,177,.18);
                outline-offset: 2px;
            }
            .oy-gmb-more-wrapper:not(.is-editing) .oy-gmb-more-field-control {
                opacity: .72;
            }

            /* Panel de publicación */
            .oy-gmb-more-panel-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 10px;
                padding: 10px 14px;
                background: #f6f7f7;
                border-bottom: 1px solid #dadce0;
            }
            .oy-gmb-more-panel-title { font-weight: 600; color: #1d2327; }
            .oy-gmb-more-panel-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
            .oy-gmb-more-panel-body { padding: 12px 14px; }
            .oy-gmb-more-panel-alert { margin-bottom: 10px; padding: 10px 12px; border-left: 4px solid #2271b1; background: #eef6ff; }
            .oy-gmb-more-panel-alert.success { border-left-color: #00a32a; background: #edfaef; }
            .oy-gmb-more-panel-alert.warning { border-left-color: #dba617; background: #fff8e5; }
            .oy-gmb-more-panel-alert.error { border-left-color: #d63638; background: #fff1f0; }
            .oy-gmb-more-panel-meta { margin-bottom: 8px; color: #50575e; font-size: 12px; }
            .oy-gmb-more-history-wrap { margin-top: 12px; }
            .oy-gmb-more-history-scroll { max-height: 220px; overflow: auto; border: 1px solid #dcdcde; background: #fff; margin-top: 8px; }
            .oy-gmb-more-history-table { width: 100%; border-collapse: collapse; }
            .oy-gmb-more-history-table th { text-align: left; padding: 8px; border-bottom: 1px solid #dcdcde; background: #f6f7f7; }
            .oy-gmb-more-history-table td { padding: 8px; border-bottom: 1px solid #f0f0f1; vertical-align: top; }

            /* Log visual */
            #oy-gmb-more-log-panel { margin-bottom: 16px; border: 1px solid #dadce0; border-radius: 4px; overflow: hidden; background: #fff; }
            #oy-gmb-more-log-header { display: flex; align-items: center; justify-content: space-between; padding: 8px 14px; background: #f6f7f7; cursor: pointer; border-bottom: 1px solid transparent; user-select: none; font-size: 13px; font-weight: 600; color: #1d2327; }
            #oy-gmb-more-log-toggle-icon { font-size: 13px; color: #888; transition: transform .2s; }
            #oy-gmb-more-log-entries { padding: 10px 14px; }
            #oy-gmb-more-log-footer { padding: 8px 14px; border-top: 1px solid #f0f0f0; background: #fafafa; display: flex; gap: 10px; align-items: center; }
            #oy-gmb-more-log-clear { font-size: 11px; color: #dc3232; border-color: #dc3232; }
            #oy-gmb-more-log-footer span { font-size: 11px; color: #aaa; font-style: italic; }

            /* Botones en toolbar */
            .oy-gmb-more-btn-refresh .dashicons,
            .oy-gmb-more-btn-push .dashicons,
            .oy-gmb-more-btn-edit .dashicons,
            .oy-gmb-more-btn-save .dashicons,
            .oy-gmb-more-btn-cancel .dashicons {
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
