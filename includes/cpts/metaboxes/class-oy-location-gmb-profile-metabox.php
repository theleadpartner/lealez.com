<?php
/**
 * OY Location – GMB Profile Metabox
 *
 * Panel de control para visualizar y enviar a Google Business Profile los campos
 * principales del perfil de la ubicación:
 *  - Nombre del negocio    → GMB: title
 *  - Teléfonos             → GMB: phoneNumbers
 *  - Sitio web             → GMB: websiteUri
 *  - Descripción del perfil→ GMB: profile.description  (meta: location_short_description)
 *  - Categorías            → GMB: categories  (requiere moderación de Google)
 *
 * La escritura en Google se delega a Lealez_GMB_Writer::update_location_core().
 * El historial de cambios se renderiza desde el meta '_gmb_writer_log'.
 *
 * Este metabox NO duplica campos de formulario del CPT. Muestra los valores
 * actuales guardados y permite "empujar" cada sección a Google con un clic.
 *
 * Archivo: includes/cpts/metaboxes/class-oy-location-gmb-profile-metabox.php
 *
 * AJAX actions expuestas:
 *  - wp_ajax_oy_gmb_profile_push_core  → Envía una sección a Google vía Lealez_GMB_Writer
 *  - wp_ajax_oy_gmb_profile_log_clear  → Limpia el historial _gmb_writer_log
 *
 * Convenciones:
 *  - class_exists() guard
 *  - Nonce action AJAX: 'oy_gmb_profile_ajax'
 *  - add_meta_boxes_oy_location, priority 20
 *  - Sin save_post hook propio (todos los campos ya los guarda el CPT)
 *  - CSS inline; JS inline en render_metabox()
 *
 * @package    Lealez
 * @subpackage CPTs/Metaboxes
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'OY_Location_GMB_Profile_Metabox' ) ) {

    /**
     * Class OY_Location_GMB_Profile_Metabox
     */
    class OY_Location_GMB_Profile_Metabox {

        // =====================================================================
        // CONSTANTES
        // =====================================================================

        /** Nonce para llamadas AJAX de este metabox. */
        const NONCE_AJAX_ACTION = 'oy_gmb_profile_ajax';

        /** Prioridad de add_meta_box (distincta de las usadas por otros metaboxes: 10 CPT, 15 standalone, 25 more). */
        const METABOX_PRIORITY = 20;

        /** Número máximo de filas del historial a renderizar en la UI. */
        const MAX_LOG_DISPLAY = 20;

        // =====================================================================
        // REGISTRO DE HOOKS
        // =====================================================================

        /**
         * Constructor: registra todos los hooks de forma autocontenida.
         */
        public function __construct() {
            add_action( 'add_meta_boxes_oy_location', array( $this, 'register_metabox' ), self::METABOX_PRIORITY );
            add_action( 'admin_enqueue_scripts',      array( $this, 'enqueue_styles' ) );
            add_action( 'wp_ajax_oy_gmb_profile_push_core',  array( $this, 'ajax_push_core' ) );
            add_action( 'wp_ajax_oy_gmb_profile_log_clear',  array( $this, 'ajax_log_clear' ) );
        }

        // =====================================================================
        // REGISTRO Y ESTILOS
        // =====================================================================

        /**
         * Registra el metabox en la pantalla de edición de oy_location.
         *
         * @param WP_Post $post Post actual.
         */
        public function register_metabox( $post ) {
            add_meta_box(
                'oy_location_gmb_profile',
                __( '✏️ Google Business Profile – Información del Perfil', 'lealez' ),
                array( $this, 'render_metabox' ),
                'oy_location',
                'normal',
                'default'
            );
        }

        /**
         * Encola CSS inline solo en la edición de oy_location.
         *
         * @param string $hook Hook de la pantalla actual.
         */
        public function enqueue_styles( $hook ) {
            if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
                return;
            }

            global $post;
            if ( ! $post || 'oy_location' !== $post->post_type ) {
                return;
            }

            $handle  = 'oy-gmb-profile-metabox-css';
            $version = defined( 'LEALEZ_VERSION' ) ? LEALEZ_VERSION : '1.0';

            wp_register_style( $handle, false, array(), $version );
            wp_enqueue_style( $handle );
            wp_add_inline_style( $handle, $this->get_inline_styles() );
        }

        // =====================================================================
        // RENDER
        // =====================================================================

        /**
         * Renderiza el metabox completo.
         *
         * @param WP_Post $post Post de oy_location.
         */
        public function render_metabox( $post ) {
            $post_id            = (int) $post->ID;
            $parent_business_id = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $gmb_location_name  = (string) get_post_meta( $post_id, 'gmb_location_name', true );
            $is_connected       = $parent_business_id && $gmb_location_name;

            // ── Estado: ediciones pendientes de moderación ─────────────────────
            $has_pending_edits = (string) get_post_meta( $post_id, '_gmb_has_pending_edits', true );

            // ── Leer valores actuales desde post meta ──────────────────────────

            // Título: WP post title (fuente canónica; el import lo actualiza vía wp_update_post)
            $current_title = (string) $post->post_title;

            // Teléfonos
            $current_phone_primary    = (string) get_post_meta( $post_id, 'location_phone', true );
            $current_phone_additional = get_post_meta( $post_id, 'gmb_phone_additional_list', true );
            if ( ! is_array( $current_phone_additional ) ) {
                // Fallback: intentar del campo legacy
                $leg = (string) get_post_meta( $post_id, 'location_phone_additional', true );
                $current_phone_additional = $leg ? array( $leg ) : array();
            }

            // Sitio web
            $current_website = (string) get_post_meta( $post_id, 'location_website', true );

            // Descripción (meta key: location_short_description — consistente con el CPT)
            $current_description = (string) get_post_meta( $post_id, 'location_short_description', true );
            $desc_length         = mb_strlen( $current_description );

            // Categorías — display names
            $cat_primary_display    = (string) get_post_meta( $post_id, 'google_primary_category', true );
            $cat_primary_gcid       = (string) get_post_meta( $post_id, 'gmb_primary_category_name', true );
            $cat_additional_raw     = get_post_meta( $post_id, 'gmb_additional_categories', true );
            $cat_additional         = is_array( $cat_additional_raw ) ? $cat_additional_raw : array();

            // ── Preparar nonce AJAX ────────────────────────────────────────────
            $ajax_nonce = wp_create_nonce( self::NONCE_AJAX_ACTION );

            // ── Log de cambios ─────────────────────────────────────────────────
            $log_html = $this->render_log_table( $post_id );

            ?>
            <div class="oy-gmb-profile-wrap" id="oy-gmb-profile-wrap">

                <?php if ( ! $is_connected ) : ?>
                <div class="oy-gmb-profile-notice warning">
                    <span class="dashicons dashicons-warning"></span>
                    <?php _e( 'Esta ubicación no tiene un Perfil de Google Business (GMB) vinculado. Conéctala primero desde el selector de GMB.', 'lealez' ); ?>
                </div>
                <?php endif; ?>

                <?php if ( $is_connected && '1' === $has_pending_edits ) : ?>
                <div class="oy-gmb-profile-notice pending">
                    <span class="dashicons dashicons-clock"></span>
                    <strong><?php _e( 'Cambios pendientes de moderación:', 'lealez' ); ?></strong>
                    <?php _e( 'Google está revisando uno o más campos moderados (nombre, categorías). Los cambios aparecerán en el perfil público cuando sean aprobados.', 'lealez' ); ?>
                </div>
                <?php endif; ?>

                <div id="oy-gmb-profile-global-notice" class="oy-gmb-profile-notice" style="display:none;"></div>

                <?php /* ── Barra superior ── */ ?>
                <div class="oy-gmb-profile-header-bar">
                    <div class="oy-gmb-profile-location-info">
                        <?php if ( $gmb_location_name ) : ?>
                            <code style="font-size:11px; color:#666;"><?php echo esc_html( $gmb_location_name ); ?></code>
                        <?php else : ?>
                            <span style="color:#aaa; font-size:12px;"><?php _e( 'Sin ubicación GMB vinculada', 'lealez' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="oy-gmb-profile-header-note">
                        <span class="dashicons dashicons-info-outline" style="vertical-align:middle;"></span>
                        <?php _e( 'Edita los valores en los campos del formulario superior, guarda el post y usa los botones de cada sección para enviarlos a Google.', 'lealez' ); ?>
                    </div>
                </div>

                <?php /* ──────────────────────────────────────────────────────────
                    SECCIÓN 1: NOMBRE DEL NEGOCIO
                ────────────────────────────────────────────────────────── */ ?>
                <div class="oy-gmb-profile-section" data-section="title">
                    <div class="oy-gmb-profile-section-header">
                        <h4 class="oy-gmb-profile-section-title">
                            <span class="dashicons dashicons-store"></span>
                            <?php _e( 'Nombre del Negocio', 'lealez' ); ?>
                            <span class="oy-gmb-moderated-badge"><?php _e( '⚠ Moderado por Google', 'lealez' ); ?></span>
                        </h4>
                        <button type="button"
                                class="button button-primary oy-gmb-push-btn"
                                data-section="title"
                                <?php echo $is_connected ? '' : 'disabled'; ?>>
                            <span class="dashicons dashicons-upload"></span>
                            <?php _e( 'Enviar nombre a GMB', 'lealez' ); ?>
                        </button>
                    </div>
                    <div class="oy-gmb-profile-section-body">
                        <div class="oy-gmb-field-preview">
                            <span class="oy-gmb-field-label"><?php _e( 'Valor actual (título del post):', 'lealez' ); ?></span>
                            <span class="oy-gmb-field-value"><?php echo $current_title ? esc_html( $current_title ) : '<em style="color:#aaa;">' . esc_html__( 'Sin título', 'lealez' ) . '</em>'; ?></span>
                        </div>
                        <p class="oy-gmb-section-hint">
                            <?php _e( 'El nombre se toma del título del post de WordPress. Cámbialo en el campo "Título" de la parte superior antes de enviarlo a Google.', 'lealez' ); ?>
                            <?php _e( '<strong>Cambios de nombre requieren revisión de Google antes de publicarse.</strong>', 'lealez' ); ?>
                        </p>
                        <div class="oy-gmb-section-feedback" style="display:none;"></div>
                    </div>
                </div>

                <?php /* ──────────────────────────────────────────────────────────
                    SECCIÓN 2: TELÉFONOS
                ────────────────────────────────────────────────────────── */ ?>
                <div class="oy-gmb-profile-section" data-section="phoneNumbers">
                    <div class="oy-gmb-profile-section-header">
                        <h4 class="oy-gmb-profile-section-title">
                            <span class="dashicons dashicons-phone"></span>
                            <?php _e( 'Teléfonos', 'lealez' ); ?>
                        </h4>
                        <button type="button"
                                class="button button-primary oy-gmb-push-btn"
                                data-section="phoneNumbers"
                                <?php echo ( $is_connected && $current_phone_primary ) ? '' : 'disabled'; ?>>
                            <span class="dashicons dashicons-upload"></span>
                            <?php _e( 'Enviar teléfonos a GMB', 'lealez' ); ?>
                        </button>
                    </div>
                    <div class="oy-gmb-profile-section-body">
                        <div class="oy-gmb-field-preview">
                            <span class="oy-gmb-field-label"><?php _e( 'Principal:', 'lealez' ); ?></span>
                            <span class="oy-gmb-field-value">
                                <?php echo $current_phone_primary
                                    ? esc_html( $current_phone_primary )
                                    : '<em style="color:#aaa;">' . esc_html__( 'Sin teléfono principal', 'lealez' ) . '</em>'; ?>
                            </span>
                        </div>
                        <?php if ( ! empty( $current_phone_additional ) ) : ?>
                        <div class="oy-gmb-field-preview">
                            <span class="oy-gmb-field-label"><?php _e( 'Adicional(es):', 'lealez' ); ?></span>
                            <span class="oy-gmb-field-value"><?php echo esc_html( implode( ', ', $current_phone_additional ) ); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ( ! $current_phone_primary ) : ?>
                        <p class="oy-gmb-section-hint" style="color:#c0392b;">
                            <span class="dashicons dashicons-no-alt" style="font-size:14px; vertical-align:middle;"></span>
                            <?php _e( 'Agrega un teléfono principal en la sección "Contacto" del formulario superior.', 'lealez' ); ?>
                        </p>
                        <?php else : ?>
                        <p class="oy-gmb-section-hint"><?php _e( 'Edita los teléfonos en la sección "Contacto" del formulario superior, guarda el post y luego usa el botón.', 'lealez' ); ?></p>
                        <?php endif; ?>
                        <div class="oy-gmb-section-feedback" style="display:none;"></div>
                    </div>
                </div>

                <?php /* ──────────────────────────────────────────────────────────
                    SECCIÓN 3: SITIO WEB
                ────────────────────────────────────────────────────────── */ ?>
                <div class="oy-gmb-profile-section" data-section="websiteUri">
                    <div class="oy-gmb-profile-section-header">
                        <h4 class="oy-gmb-profile-section-title">
                            <span class="dashicons dashicons-admin-site-alt3"></span>
                            <?php _e( 'Sitio Web', 'lealez' ); ?>
                        </h4>
                        <button type="button"
                                class="button button-primary oy-gmb-push-btn"
                                data-section="websiteUri"
                                <?php echo ( $is_connected && $current_website ) ? '' : 'disabled'; ?>>
                            <span class="dashicons dashicons-upload"></span>
                            <?php _e( 'Enviar sitio web a GMB', 'lealez' ); ?>
                        </button>
                    </div>
                    <div class="oy-gmb-profile-section-body">
                        <div class="oy-gmb-field-preview">
                            <span class="oy-gmb-field-label"><?php _e( 'URL actual:', 'lealez' ); ?></span>
                            <span class="oy-gmb-field-value">
                                <?php if ( $current_website ) : ?>
                                    <a href="<?php echo esc_url( $current_website ); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html( $current_website ); ?>
                                        <span class="dashicons dashicons-external" style="font-size:13px;"></span>
                                    </a>
                                <?php else : ?>
                                    <em style="color:#aaa;"><?php _e( 'Sin sitio web configurado', 'lealez' ); ?></em>
                                <?php endif; ?>
                            </span>
                        </div>
                        <p class="oy-gmb-section-hint"><?php _e( 'Edita la URL en la sección "Contacto" del formulario superior y guarda el post antes de enviar.', 'lealez' ); ?></p>
                        <div class="oy-gmb-section-feedback" style="display:none;"></div>
                    </div>
                </div>

                <?php /* ──────────────────────────────────────────────────────────
                    SECCIÓN 4: DESCRIPCIÓN DEL PERFIL
                ────────────────────────────────────────────────────────── */ ?>
                <div class="oy-gmb-profile-section" data-section="description">
                    <div class="oy-gmb-profile-section-header">
                        <h4 class="oy-gmb-profile-section-title">
                            <span class="dashicons dashicons-text-page"></span>
                            <?php _e( 'Descripción del Perfil', 'lealez' ); ?>
                        </h4>
                        <button type="button"
                                class="button button-primary oy-gmb-push-btn"
                                data-section="description"
                                <?php echo $is_connected ? '' : 'disabled'; ?>>
                            <span class="dashicons dashicons-upload"></span>
                            <?php _e( 'Enviar descripción a GMB', 'lealez' ); ?>
                        </button>
                    </div>
                    <div class="oy-gmb-profile-section-body">
                        <?php if ( $current_description ) : ?>
                        <div class="oy-gmb-desc-preview">
                            <?php echo esc_html( mb_substr( $current_description, 0, 200 ) ); ?>
                            <?php if ( $desc_length > 200 ) echo '…'; ?>
                        </div>
                        <div class="oy-gmb-char-info">
                            <span class="oy-gmb-char-count <?php echo $desc_length > 750 ? 'over' : ( $desc_length > 700 ? 'near' : '' ); ?>">
                                <?php echo esc_html( $desc_length ); ?>/750
                            </span>
                            <?php if ( $desc_length > 750 ) : ?>
                            <span class="oy-gmb-char-warning">
                                <span class="dashicons dashicons-warning" style="font-size:13px;"></span>
                                <?php _e( 'Supera 750 caracteres — Google truncará la descripción al enviar.', 'lealez' ); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php else : ?>
                        <div class="oy-gmb-field-preview">
                            <em style="color:#aaa;"><?php _e( 'Sin descripción configurada. Agrégala en la sección "Información Básica" del formulario superior.', 'lealez' ); ?></em>
                        </div>
                        <?php endif; ?>
                        <p class="oy-gmb-section-hint">
                            <?php _e( 'La descripción se toma del meta <code>location_short_description</code> (campo "Descripción (GMB)" del formulario superior). Máximo 750 caracteres.', 'lealez' ); ?>
                        </p>
                        <div class="oy-gmb-section-feedback" style="display:none;"></div>
                    </div>
                </div>

                <?php /* ──────────────────────────────────────────────────────────
                    SECCIÓN 5: CATEGORÍAS
                ────────────────────────────────────────────────────────── */ ?>
                <div class="oy-gmb-profile-section" data-section="categories">
                    <div class="oy-gmb-profile-section-header">
                        <h4 class="oy-gmb-profile-section-title">
                            <span class="dashicons dashicons-category"></span>
                            <?php _e( 'Categorías del Negocio', 'lealez' ); ?>
                            <span class="oy-gmb-moderated-badge"><?php _e( '⚠ Moderado por Google', 'lealez' ); ?></span>
                        </h4>
                        <button type="button"
                                class="button button-primary oy-gmb-push-btn"
                                data-section="categories"
                                <?php echo ( $is_connected && $cat_primary_gcid ) ? '' : 'disabled'; ?>>
                            <span class="dashicons dashicons-upload"></span>
                            <?php _e( 'Enviar categorías a GMB', 'lealez' ); ?>
                        </button>
                    </div>
                    <div class="oy-gmb-profile-section-body">
                        <?php if ( $cat_primary_gcid ) : ?>
                        <div class="oy-gmb-field-preview">
                            <span class="oy-gmb-field-label"><?php _e( 'Categoría principal:', 'lealez' ); ?></span>
                            <span class="oy-gmb-field-value">
                                <?php echo $cat_primary_display ? esc_html( $cat_primary_display ) : ''; ?>
                                <code class="oy-gmb-gcid"><?php echo esc_html( $cat_primary_gcid ); ?></code>
                            </span>
                        </div>
                        <?php if ( ! empty( $cat_additional ) ) : ?>
                        <div class="oy-gmb-field-preview" style="align-items:flex-start;">
                            <span class="oy-gmb-field-label"><?php _e( 'Categorías adicionales:', 'lealez' ); ?></span>
                            <span class="oy-gmb-field-value">
                                <?php foreach ( $cat_additional as $cat ) :
                                    $d = isset( $cat['displayName'] ) ? (string) $cat['displayName'] : '';
                                    $n = isset( $cat['name'] ) ? (string) $cat['name'] : '';
                                    if ( ! $n ) continue;
                                ?>
                                <span class="oy-gmb-cat-chip">
                                    <?php echo $d ? esc_html( $d ) : ''; ?>
                                    <code class="oy-gmb-gcid"><?php echo esc_html( $n ); ?></code>
                                </span>
                                <?php endforeach; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php else : ?>
                        <div class="oy-gmb-field-preview">
                            <em style="color:#aaa;"><?php _e( 'No hay categorías sincronizadas desde GMB. Realiza una sincronización completa primero.', 'lealez' ); ?></em>
                        </div>
                        <?php endif; ?>
                        <div class="oy-gmb-moderation-alert">
                            <span class="dashicons dashicons-warning"></span>
                            <?php _e( '<strong>Importante:</strong> Los cambios de categorías requieren revisión manual de Google antes de reflejarse en el perfil público. Pueden tardar varios días.', 'lealez' ); ?>
                        </div>
                        <p class="oy-gmb-section-hint"><?php _e( 'Las categorías se envían con los identificadores gcid (formato <code>gcid:restaurant</code>) guardados en la última sincronización desde GMB.', 'lealez' ); ?></p>
                        <div class="oy-gmb-section-feedback" style="display:none;"></div>
                    </div>
                </div>

                <?php /* ──────────────────────────────────────────────────────────
                    SECCIÓN 6: HISTORIAL DE CAMBIOS
                ────────────────────────────────────────────────────────── */ ?>
                <div class="oy-gmb-profile-section oy-gmb-log-section" id="oy-gmb-profile-log-section">
                    <div class="oy-gmb-profile-section-header">
                        <h4 class="oy-gmb-profile-section-title">
                            <span class="dashicons dashicons-list-view"></span>
                            <?php _e( 'Historial de Cambios Enviados a Google', 'lealez' ); ?>
                        </h4>
                        <button type="button"
                                class="button button-small button-secondary"
                                id="oy-gmb-profile-log-clear"
                                style="color:#c0392b; border-color:#c0392b;">
                            <span class="dashicons dashicons-trash" style="font-size:14px; line-height:1.6;"></span>
                            <?php _e( 'Borrar historial', 'lealez' ); ?>
                        </button>
                    </div>
                    <div class="oy-gmb-profile-section-body" id="oy-gmb-profile-log-body" style="padding:0;">
                        <?php echo $log_html; ?>
                    </div>
                </div>

            </div><!-- /.oy-gmb-profile-wrap -->

            <?php /* ──────────────────────────────────────────────────────────
                JAVASCRIPT INLINE
            ────────────────────────────────────────────────────────── */ ?>
            <script type="text/javascript">
            (function($) {
                'use strict';

                var CONFIG = {
                    ajaxUrl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
                    nonce   : '<?php echo esc_js( $ajax_nonce ); ?>',
                    postId  : <?php echo (int) $post_id; ?>,
                    i18n    : {
                        pushing        : '<?php echo esc_js( __( 'Enviando a Google...', 'lealez' ) ); ?>',
                        pushOk         : '<?php echo esc_js( __( 'Enviado correctamente a Google Business Profile.', 'lealez' ) ); ?>',
                        pushPending    : '<?php echo esc_js( __( 'Enviado — cambio pendiente de moderación por Google.', 'lealez' ) ); ?>',
                        pushError      : '<?php echo esc_js( __( 'Error al enviar. Ver consola para detalles.', 'lealez' ) ); ?>',
                        notConnected   : '<?php echo esc_js( __( 'Esta ubicación no está conectada a GMB.', 'lealez' ) ); ?>',
                        confirmPush    : '<?php echo esc_js( __( '¿Confirmas el envío de esta sección a Google Business Profile?', 'lealez' ) ); ?>',
                        confirmClear   : '<?php echo esc_js( __( '¿Borrar el historial de cambios de esta ubicación? Esta acción no se puede deshacer.', 'lealez' ) ); ?>',
                        clearing       : '<?php echo esc_js( __( 'Borrando historial...', 'lealez' ) ); ?>',
                        clearOk        : '<?php echo esc_js( __( 'Historial borrado.', 'lealez' ) ); ?>',
                        clearError     : '<?php echo esc_js( __( 'Error al borrar el historial.', 'lealez' ) ); ?>',
                        logEmpty       : '<?php echo esc_js( __( 'No hay registros de cambios para esta ubicación.', 'lealez' ) ); ?>'
                    }
                };

                // ── Botones "Enviar sección a GMB" ────────────────────────────
                $(document).on('click', '.oy-gmb-push-btn', function() {
                    var $btn     = $(this);
                    var section  = $btn.data('section');
                    var $section = $btn.closest('.oy-gmb-profile-section');
                    var $fb      = $section.find('.oy-gmb-section-feedback');

                    if ( $btn.prop('disabled') ) {
                        return;
                    }

                    if ( ! window.confirm( CONFIG.i18n.confirmPush ) ) {
                        return;
                    }

                    // UI: loading
                    $btn.prop('disabled', true).addClass('oy-gmb-btn-loading');
                    $btn.find('.dashicons').removeClass('dashicons-upload').addClass('dashicons-update oy-spin');
                    $fb.hide().removeClass('success error pending');

                    $.ajax({
                        url     : CONFIG.ajaxUrl,
                        type    : 'POST',
                        data    : {
                            action  : 'oy_gmb_profile_push_core',
                            nonce   : CONFIG.nonce,
                            post_id : CONFIG.postId,
                            section : section
                        },
                        success : function(resp) {
                            if ( resp && resp.success ) {
                                var isPending = resp.data && resp.data.pending_moderation;
                                var cls       = isPending ? 'pending' : 'success';
                                var msg       = isPending ? CONFIG.i18n.pushPending : CONFIG.i18n.pushOk;

                                if ( resp.data && resp.data.message ) {
                                    msg = resp.data.message;
                                }

                                $fb.text( msg )
                                   .addClass( cls )
                                   .fadeIn();

                                // Mostrar aviso global de moderación pendiente
                                if ( isPending ) {
                                    showGlobalNotice(
                                        '⚠ ' + '<?php echo esc_js( __( 'Cambios pendientes de moderación por Google.', 'lealez' ) ); ?>',
                                        'pending'
                                    );
                                    // Actualizar badge visible si existe
                                    if ( ! $('#oy-gmb-pending-edits-notice').length ) {
                                        $('#oy-gmb-profile-wrap').prepend(
                                            '<div id="oy-gmb-pending-edits-notice" class="oy-gmb-profile-notice pending">' +
                                            '<span class="dashicons dashicons-clock"></span> ' +
                                            '<?php echo esc_js( __( 'Google está revisando uno o más campos moderados.', 'lealez' ) ); ?>' +
                                            '</div>'
                                        );
                                    }
                                }

                                // Refrescar log
                                if ( resp.data && resp.data.log_html ) {
                                    $('#oy-gmb-profile-log-body').html( resp.data.log_html );
                                }

                            } else {
                                var errMsg = ( resp && resp.data && resp.data.message )
                                    ? resp.data.message
                                    : CONFIG.i18n.pushError;
                                $fb.text( errMsg )
                                   .addClass('error')
                                   .fadeIn();
                                showGlobalNotice( errMsg, 'error' );
                            }
                        },
                        error : function( xhr ) {
                            $fb.text( CONFIG.i18n.pushError )
                               .addClass('error')
                               .fadeIn();
                        },
                        complete : function() {
                            $btn.prop('disabled', false).removeClass('oy-gmb-btn-loading');
                            $btn.find('.dashicons')
                                .removeClass('dashicons-update oy-spin')
                                .addClass('dashicons-upload');
                            // Auto-ocultar feedback tras 8 s
                            setTimeout(function() { $fb.fadeOut(); }, 8000);
                        }
                    });
                });

                // ── Borrar historial ───────────────────────────────────────────
                $(document).on('click', '#oy-gmb-profile-log-clear', function() {
                    var $btn = $(this);

                    if ( ! window.confirm( CONFIG.i18n.confirmClear ) ) {
                        return;
                    }

                    $btn.prop('disabled', true).text( CONFIG.i18n.clearing );

                    $.ajax({
                        url  : CONFIG.ajaxUrl,
                        type : 'POST',
                        data : {
                            action  : 'oy_gmb_profile_log_clear',
                            nonce   : CONFIG.nonce,
                            post_id : CONFIG.postId
                        },
                        success : function(resp) {
                            if ( resp && resp.success ) {
                                var emptyHtml = '<p class="oy-gmb-log-empty">' +
                                    CONFIG.i18n.logEmpty + '</p>';
                                if ( resp.data && resp.data.log_html ) {
                                    emptyHtml = resp.data.log_html;
                                }
                                $('#oy-gmb-profile-log-body').html( emptyHtml );
                                showGlobalNotice( CONFIG.i18n.clearOk, 'success' );
                            } else {
                                showGlobalNotice( CONFIG.i18n.clearError, 'error' );
                            }
                        },
                        error : function() {
                            showGlobalNotice( CONFIG.i18n.clearError, 'error' );
                        },
                        complete : function() {
                            $btn.prop('disabled', false);
                            $btn.html(
                                '<span class="dashicons dashicons-trash" style="font-size:14px;line-height:1.6;"></span> ' +
                                '<?php echo esc_js( __( 'Borrar historial', 'lealez' ) ); ?>'
                            );
                        }
                    });
                });

                // ── Helper: aviso global superior ─────────────────────────────
                function showGlobalNotice( msg, type ) {
                    var $notice = $('#oy-gmb-profile-global-notice');
                    $notice
                        .text( msg )
                        .removeClass('success error warning pending info')
                        .addClass( type || 'info' )
                        .fadeIn();
                    setTimeout(function() { $notice.fadeOut(); }, 7000);
                }

            })(jQuery);
            </script>
            <?php
        }

        // =====================================================================
        // AJAX — PUSH CORE
        // =====================================================================

        /**
         * AJAX: envía UNA sección del perfil a Google Business Profile
         * mediante Lealez_GMB_Writer::update_location_core().
         *
         * Secciones soportadas (param 'section'):
         *  - 'title'        Nombre del negocio (campo moderado)
         *  - 'phoneNumbers' Teléfonos principal y adicionales
         *  - 'websiteUri'   Sitio web
         *  - 'description'  Descripción del perfil (profile.description)
         *  - 'categories'   Categorías primaria y adicionales (campo moderado)
         */
        public function ajax_push_core() {
            // ── Verificar nonce ──────────────────────────────────────────────
            if ( ! check_ajax_referer( self::NONCE_AJAX_ACTION, 'nonce', false ) ) {
                wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ), 403 );
            }

            // ── Permisos ─────────────────────────────────────────────────────
            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_send_json_error( array( 'message' => __( 'Sin permisos suficientes.', 'lealez' ) ), 403 );
            }

            // ── Parámetros ───────────────────────────────────────────────────
            $post_id = (int) ( $_POST['post_id'] ?? 0 );
            $section = sanitize_key( $_POST['section'] ?? '' );

            if ( ! $post_id || ! $section ) {
                wp_send_json_error( array( 'message' => __( 'Parámetros incompletos.', 'lealez' ) ) );
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Sin permisos para editar este post.', 'lealez' ) ), 403 );
            }

            // ── Leer meta de control ─────────────────────────────────────────
            $business_id       = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $gmb_location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );

            if ( ! $business_id || ! $gmb_location_name ) {
                wp_send_json_error( array(
                    'message' => __( 'Esta ubicación no tiene un Perfil de Google Business vinculado.', 'lealez' ),
                ) );
            }

            // ── Cargar Lealez_GMB_Writer si no está disponible ───────────────
            if ( ! class_exists( 'Lealez_GMB_Writer' ) ) {
                $writer_file = defined( 'LEALEZ_PLUGIN_DIR' )
                    ? LEALEZ_PLUGIN_DIR . 'includes/class-lealez-gmb-writer.php'
                    : '';
                if ( $writer_file && file_exists( $writer_file ) ) {
                    require_once $writer_file;
                }
            }

            if ( ! class_exists( 'Lealez_GMB_Writer' ) ) {
                wp_send_json_error( array(
                    'message' => __( 'Lealez_GMB_Writer no está disponible. Verifica la instalación del plugin.', 'lealez' ),
                ) );
            }

            // ── Construir el array $fields según la sección ──────────────────
            $fields = array();

            switch ( $section ) {

                // ── Nombre del negocio ──────────────────────────────────────
                case 'title':
                    $post = get_post( $post_id );
                    if ( ! $post ) {
                        wp_send_json_error( array( 'message' => __( 'Post no encontrado.', 'lealez' ) ) );
                    }
                    $title = trim( (string) $post->post_title );
                    if ( '' === $title ) {
                        wp_send_json_error( array( 'message' => __( 'El título del post está vacío. Agrega un nombre antes de enviar.', 'lealez' ) ) );
                    }
                    $fields['title'] = $title;
                    break;

                // ── Teléfonos ───────────────────────────────────────────────
                case 'phoneNumbers':
                    $primary = (string) get_post_meta( $post_id, 'location_phone', true );
                    if ( '' === $primary ) {
                        wp_send_json_error( array( 'message' => __( 'No hay teléfono principal configurado.', 'lealez' ) ) );
                    }
                    $additional = get_post_meta( $post_id, 'gmb_phone_additional_list', true );
                    if ( ! is_array( $additional ) ) {
                        $leg = (string) get_post_meta( $post_id, 'location_phone_additional', true );
                        $additional = $leg ? array( $leg ) : array();
                    }
                    $additional = array_values( array_filter( array_map( 'sanitize_text_field', $additional ) ) );

                    $fields['phoneNumbers'] = array( 'primaryPhone' => $primary );
                    if ( ! empty( $additional ) ) {
                        $fields['phoneNumbers']['additionalPhones'] = $additional;
                    }
                    break;

                // ── Sitio web ───────────────────────────────────────────────
                case 'websiteUri':
                    $website = (string) get_post_meta( $post_id, 'location_website', true );
                    if ( '' === $website ) {
                        wp_send_json_error( array( 'message' => __( 'No hay sitio web configurado.', 'lealez' ) ) );
                    }
                    $fields['websiteUri'] = $website;
                    break;

                // ── Descripción del perfil ──────────────────────────────────
                case 'description':
                    $desc = (string) get_post_meta( $post_id, 'location_short_description', true );
                    // La descripción puede estar vacía (para borrarla de Google)
                    $fields['description'] = mb_substr( $desc, 0, 750 );
                    break;

                // ── Categorías ──────────────────────────────────────────────
                case 'categories':
                    $primary_gcid = (string) get_post_meta( $post_id, 'gmb_primary_category_name', true );
                    if ( '' === $primary_gcid ) {
                        wp_send_json_error( array(
                            'message' => __( 'No hay categoría principal con identificador gcid. Realiza una sincronización completa desde GMB primero.', 'lealez' ),
                        ) );
                    }

                    $cats_body = array(
                        'primaryCategory' => array( 'name' => $primary_gcid ),
                    );

                    $additional_cats = get_post_meta( $post_id, 'gmb_additional_categories', true );
                    if ( is_array( $additional_cats ) && ! empty( $additional_cats ) ) {
                        $additional_out = array();
                        foreach ( $additional_cats as $cat ) {
                            if ( is_array( $cat ) && ! empty( $cat['name'] ) ) {
                                $additional_out[] = array( 'name' => sanitize_text_field( (string) $cat['name'] ) );
                            }
                        }
                        if ( ! empty( $additional_out ) ) {
                            $cats_body['additionalCategories'] = $additional_out;
                        }
                    }

                    $fields['categories'] = $cats_body;
                    break;

                default:
                    wp_send_json_error( array(
                        'message' => sprintf(
                            /* translators: %s: section name */
                            __( 'Sección desconocida: "%s".', 'lealez' ),
                            $section
                        ),
                    ) );
            }

            // ── Llamar al writer ─────────────────────────────────────────────
            $result = Lealez_GMB_Writer::update_location_core(
                $post_id,
                $business_id,
                $gmb_location_name,
                $fields
            );

            // ── Manejar WP_Error ─────────────────────────────────────────────
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array(
                    'message'    => $result->get_error_message(),
                    'error_code' => $result->get_error_code(),
                    'log_html'   => $this->render_log_table( $post_id ),
                ) );
            }

            // ── Éxito ────────────────────────────────────────────────────────
            $pending = ! empty( $result['pending_moderation'] );

            $message = $pending
                ? __( 'Enviado correctamente — Google revisará el cambio antes de publicarlo en el perfil.', 'lealez' )
                : __( 'Enviado y aplicado correctamente en Google Business Profile.', 'lealez' );

            wp_send_json_success( array(
                'pending_moderation' => $pending,
                'mask'               => $result['mask'] ?? array(),
                'message'            => $message,
                'log_html'           => $this->render_log_table( $post_id ),
            ) );
        }

        // =====================================================================
        // AJAX — LIMPIAR HISTORIAL
        // =====================================================================

        /**
         * AJAX: borra el meta '_gmb_writer_log' de la ubicación.
         */
        public function ajax_log_clear() {
            if ( ! check_ajax_referer( self::NONCE_AJAX_ACTION, 'nonce', false ) ) {
                wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ), 403 );
            }

            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ), 403 );
            }

            $post_id = (int) ( $_POST['post_id'] ?? 0 );
            if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Post no válido.', 'lealez' ) ) );
            }

            delete_post_meta( $post_id, '_gmb_writer_log' );

            wp_send_json_success( array(
                'log_html' => $this->render_log_table( $post_id ),
            ) );
        }

        // =====================================================================
        // HELPERS PRIVADOS
        // =====================================================================

        /**
         * Renderiza la tabla HTML del historial de cambios desde '_gmb_writer_log'.
         *
         * @param int $post_id ID del post oy_location.
         * @return string HTML listo para insertar.
         */
public function render_log_table( $post_id ) {
            $raw = get_post_meta( $post_id, '_gmb_writer_log', true );
            $log = array();

            if ( ! empty( $raw ) ) {
                if ( is_array( $raw ) ) {
                    $log = $raw;
                } elseif ( is_string( $raw ) ) {
                    $decoded = json_decode( $raw, true );
                    if ( is_array( $decoded ) ) {
                        $log = $decoded;
                    }
                }
            }

            if ( empty( $log ) ) {
                return '<p class="oy-gmb-log-empty">' .
                    esc_html__( 'No hay registros de cambios enviados a Google para esta ubicación.', 'lealez' ) .
                    '</p>';
            }

            // Limitar a MAX_LOG_DISPLAY entradas
            $log = array_slice( $log, 0, self::MAX_LOG_DISPLAY );

            $section_labels = array(
                // Campos de update_location_core
                'title'        => __( 'Nombre', 'lealez' ),
                'phoneNumbers' => __( 'Teléfonos', 'lealez' ),
                'websiteUri'   => __( 'Sitio web', 'lealez' ),
                'profile'      => __( 'Descripción', 'lealez' ),
                'categories'   => __( 'Categorías', 'lealez' ),
                'storeCode'    => __( 'Código de tienda', 'lealez' ),
                // Campos de update_location_hours
                'regularHours' => __( 'Horarios regulares', 'lealez' ),
                'specialHours' => __( 'Horarios especiales', 'lealez' ),
                'openInfo'     => __( 'Estado de apertura', 'lealez' ),
            );

            $html  = '<div class="oy-gmb-log-table-wrap">';
            $html .= '<table class="oy-gmb-log-table widefat striped">';
            $html .= '<thead><tr>';
            $html .= '<th>' . esc_html__( 'Fecha', 'lealez' ) . '</th>';
            $html .= '<th>' . esc_html__( 'Sección(es)', 'lealez' ) . '</th>';
            $html .= '<th>' . esc_html__( 'Estado', 'lealez' ) . '</th>';
            $html .= '<th>' . esc_html__( 'Cambios', 'lealez' ) . '</th>';
            $html .= '<th>' . esc_html__( 'Usuario', 'lealez' ) . '</th>';
            $html .= '<th>' . esc_html__( 'ms', 'lealez' ) . '</th>';
            $html .= '</tr></thead><tbody>';

            foreach ( $log as $entry ) {
                $ts          = ! empty( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0;
                $date_str    = $ts ? esc_html( date_i18n( 'd/m/Y H:i:s', $ts ) ) : '—';
                $status      = (string) ( $entry['status'] ?? 'unknown' );
                $mask        = is_array( $entry['mask'] ) ? $entry['mask'] : array();
                $changes     = is_array( $entry['changes'] ) ? $entry['changes'] : array();
                $pending     = ! empty( $entry['pending_moderation'] );
                $user_id     = (int) ( $entry['user_id'] ?? 0 );
                $duration_ms = (int) ( $entry['duration_ms'] ?? 0 );
                $err_msg     = (string) ( $entry['error_msg'] ?? '' );

                // Clase de fila
                $row_class = 'oy-log-row-' . esc_attr( $status );
                if ( $pending ) {
                    $row_class .= ' oy-log-row-pending';
                }

                // Secciones legibles
                $mask_labels = array();
                foreach ( $mask as $m ) {
                    $mask_labels[] = isset( $section_labels[ $m ] )
                        ? esc_html( $section_labels[ $m ] )
                        : esc_html( $m );
                }
                $mask_html = ! empty( $mask_labels )
                    ? implode( ', ', $mask_labels )
                    : ( $err_msg ? '<em>' . esc_html( $err_msg ) . '</em>' : '—' );

                // Badge de estado
                switch ( $status ) {
                    case 'success':
                        $badge = $pending
                            ? '<span class="oy-log-badge pending">⏳ ' . esc_html__( 'Pendiente mod.', 'lealez' ) . '</span>'
                            : '<span class="oy-log-badge success">✔ ' . esc_html__( 'Aplicado', 'lealez' ) . '</span>';
                        break;
                    case 'error':
                        $badge = '<span class="oy-log-badge error">✖ ' . esc_html__( 'Error', 'lealez' ) . '</span>';
                        break;
                    case 'warning':
                        $badge = '<span class="oy-log-badge warning">⚠ ' . esc_html__( 'Aviso', 'lealez' ) . '</span>';
                        break;
                    default:
                        $badge = '<span class="oy-log-badge">' . esc_html( $status ) . '</span>';
                }

                // Cambios: antes / después resumidos
                $changes_html = '';
                if ( ! empty( $changes ) ) {
                    $changes_html = '<ul class="oy-log-changes">';
                    foreach ( $changes as $field => $diff ) {
                        $before = isset( $diff['before'] ) ? mb_substr( (string) $diff['before'], 0, 60 ) : '';
                        $after  = isset( $diff['after'] )  ? mb_substr( (string) $diff['after'],  0, 60 ) : '';
                        $changes_html .= '<li>';
                        $changes_html .= '<strong>' . esc_html( $field ) . ':</strong> ';
                        if ( '' !== $before ) {
                            $changes_html .= '<del>' . esc_html( $before ) . '</del> → ';
                        }
                        $changes_html .= esc_html( $after );
                        $changes_html .= '</li>';
                    }
                    $changes_html .= '</ul>';
                } elseif ( $err_msg ) {
                    $changes_html = '<span class="oy-log-err-msg">' . esc_html( mb_substr( $err_msg, 0, 120 ) ) . '</span>';
                } else {
                    $changes_html = '—';
                }

                // Nombre del usuario
                $user_name = '—';
                if ( $user_id ) {
                    $user_obj  = get_userdata( $user_id );
                    $user_name = $user_obj ? esc_html( $user_obj->display_name ) : '#' . $user_id;
                }

                $html .= sprintf(
                    '<tr class="%s"><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_attr( $row_class ),
                    $date_str,
                    $mask_html,
                    $badge,
                    $changes_html,
                    $user_name,
                    esc_html( $duration_ms )
                );
            }

            $html .= '</tbody></table></div>';

            // Nota si hay más entradas no mostradas
            $total = is_array( $raw ) ? count( $raw ) : ( is_array( $log ) ? count( $log ) : 0 );
            if ( is_string( $raw ) ) {
                $all = json_decode( $raw, true );
                $total = is_array( $all ) ? count( $all ) : 0;
            }
            if ( $total > self::MAX_LOG_DISPLAY ) {
                $html .= '<p class="oy-gmb-log-note">' .
                    sprintf(
                        /* translators: 1: number shown, 2: total */
                        esc_html__( 'Mostrando %1$d de %2$d entradas (FIFO, máximo %3$d almacenadas).', 'lealez' ),
                        min( self::MAX_LOG_DISPLAY, $total ),
                        $total,
                        20
                    ) .
                    '</p>';
            }

            return $html;
        }

        // =====================================================================
        // ESTILOS INLINE
        // =====================================================================

        /**
         * Devuelve el CSS del metabox como string.
         *
         * @return string
         */
        private function get_inline_styles() {
            return '
            /* ── OY GMB Profile Metabox ─────────────────────────────────── */
            #oy_location_gmb_profile .oy-gmb-profile-wrap {
                font-size: 13px;
            }

            /* Avisos generales */
            .oy-gmb-profile-notice {
                display: flex;
                align-items: flex-start;
                gap: 8px;
                padding: 10px 14px;
                border-radius: 4px;
                margin-bottom: 12px;
                font-size: 13px;
                line-height: 1.5;
            }
            .oy-gmb-profile-notice .dashicons { flex-shrink: 0; margin-top: 1px; }
            .oy-gmb-profile-notice.warning  { background: #fff3cd; border-left: 4px solid #ffc107; color: #664d03; }
            .oy-gmb-profile-notice.pending  { background: #fff3cd; border-left: 4px solid #ffc107; color: #664d03; }
            .oy-gmb-profile-notice.error    { background: #fce8e8; border-left: 4px solid #dc3232; color: #721c24; }
            .oy-gmb-profile-notice.success  { background: #d4edda; border-left: 4px solid #46b450; color: #155724; }
            .oy-gmb-profile-notice.info     { background: #d5e5ff; border-left: 4px solid #2271b1; color: #0c4a8a; }

            /* Barra de encabezado del metabox */
            .oy-gmb-profile-header-bar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 8px 0 10px;
                border-bottom: 1px solid #e0e0e0;
                margin-bottom: 14px;
                gap: 12px;
                flex-wrap: wrap;
            }
            .oy-gmb-profile-header-note {
                font-size: 12px;
                color: #666;
                display: flex;
                align-items: center;
                gap: 4px;
            }

            /* Secciones */
            .oy-gmb-profile-section {
                border: 1px solid #e5e5e5;
                border-radius: 4px;
                margin-bottom: 14px;
                overflow: hidden;
            }
            .oy-gmb-log-section { border-color: #d0d0d0; }

            /* Encabezado de cada sección */
            .oy-gmb-profile-section-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 9px 14px;
                background: #f6f7f7;
                border-bottom: 1px solid #e5e5e5;
                gap: 10px;
                flex-wrap: wrap;
            }
            .oy-gmb-profile-section-title {
                margin: 0;
                font-size: 13px;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 6px;
                color: #1d2327;
            }
            .oy-gmb-profile-section-title .dashicons {
                color: #2271b1;
                font-size: 16px;
            }

            /* Badge de moderación */
            .oy-gmb-moderated-badge {
                font-size: 11px;
                font-weight: normal;
                background: #fff3cd;
                color: #664d03;
                border: 1px solid #ffc107;
                border-radius: 3px;
                padding: 1px 6px;
            }

            /* Cuerpo de cada sección */
            .oy-gmb-profile-section-body {
                padding: 12px 14px;
            }

            /* Vista previa de campo (etiqueta + valor) */
            .oy-gmb-field-preview {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 8px;
                flex-wrap: wrap;
            }
            .oy-gmb-field-label {
                font-weight: 500;
                color: #555;
                min-width: 130px;
                flex-shrink: 0;
            }
            .oy-gmb-field-value {
                color: #1d2327;
            }

            /* Hint / descripción */
            .oy-gmb-section-hint {
                margin: 8px 0 4px;
                font-size: 12px;
                color: #777;
                font-style: italic;
            }

            /* Preview de descripción */
            .oy-gmb-desc-preview {
                background: #f9f9f9;
                border: 1px solid #e5e5e5;
                border-radius: 3px;
                padding: 10px 12px;
                font-size: 13px;
                line-height: 1.6;
                margin-bottom: 6px;
                color: #1d2327;
                white-space: pre-wrap;
                word-break: break-word;
            }

            /* Contador de caracteres */
            .oy-gmb-char-info {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 6px;
                font-size: 12px;
            }
            .oy-gmb-char-count { color: #555; }
            .oy-gmb-char-count.near { color: #e67e22; font-weight: 600; }
            .oy-gmb-char-count.over { color: #c0392b; font-weight: 600; }
            .oy-gmb-char-warning {
                display: flex;
                align-items: center;
                gap: 4px;
                color: #c0392b;
            }

            /* Categorías: chips */
            .oy-gmb-cat-chip {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                background: #f0f6ff;
                border: 1px solid #cce0ff;
                border-radius: 3px;
                padding: 2px 8px;
                margin: 2px;
                font-size: 12px;
            }
            .oy-gmb-gcid {
                font-size: 10px;
                color: #888;
                background: #efefef;
                border-radius: 2px;
                padding: 0 4px;
            }

            /* Alerta de moderación */
            .oy-gmb-moderation-alert {
                display: flex;
                align-items: flex-start;
                gap: 6px;
                background: #fff8e1;
                border: 1px solid #ffe082;
                border-radius: 3px;
                padding: 8px 10px;
                font-size: 12px;
                color: #5d4037;
                margin: 8px 0;
            }
            .oy-gmb-moderation-alert .dashicons { flex-shrink: 0; font-size: 14px; color: #f57c00; }

            /* Feedback de cada sección */
            .oy-gmb-section-feedback {
                margin-top: 8px;
                padding: 6px 10px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .oy-gmb-section-feedback.success { background: #d4edda; color: #155724; }
            .oy-gmb-section-feedback.pending { background: #fff3cd; color: #664d03; }
            .oy-gmb-section-feedback.error   { background: #fce8e8; color: #721c24; }

            /* Botón de envío: estado loading */
            .oy-gmb-btn-loading { opacity: 0.7; }

            /* Spinner */
            @keyframes oy-spin { to { transform: rotate(360deg); } }
            .oy-spin { animation: oy-spin 0.8s linear infinite; display: inline-block; }

            /* ── Tabla del Historial ───────────────────────────────────── */
            .oy-gmb-log-table-wrap { overflow-x: auto; }
            .oy-gmb-log-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 12px;
            }
            .oy-gmb-log-table th,
            .oy-gmb-log-table td {
                padding: 7px 10px;
                vertical-align: top;
                text-align: left;
                border-bottom: 1px solid #f0f0f0;
            }
            .oy-gmb-log-table th {
                background: #f6f7f7;
                font-weight: 600;
                font-size: 11px;
                color: #23282d;
                text-transform: uppercase;
                letter-spacing: 0.03em;
            }
            .oy-log-row-success { }
            .oy-log-row-error   { background: #fef8f8 !important; }
            .oy-log-row-pending { background: #fffdf0 !important; }

            /* Badges de estado */
            .oy-log-badge {
                display: inline-flex;
                align-items: center;
                gap: 3px;
                padding: 2px 7px;
                border-radius: 10px;
                font-size: 11px;
                font-weight: 600;
                white-space: nowrap;
            }
            .oy-log-badge.success { background: #d4edda; color: #155724; }
            .oy-log-badge.error   { background: #fce8e8; color: #721c24; }
            .oy-log-badge.warning { background: #fff3cd; color: #664d03; }
            .oy-log-badge.pending { background: #fff3cd; color: #664d03; }

            /* Lista de cambios */
            .oy-log-changes {
                margin: 0;
                padding: 0;
                list-style: none;
            }
            .oy-log-changes li {
                font-size: 11px;
                margin-bottom: 3px;
                word-break: break-all;
            }
            .oy-log-changes del {
                color: #c0392b;
                text-decoration: line-through;
                opacity: 0.8;
            }
            .oy-log-err-msg { color: #721c24; font-size: 11px; }

            /* Vacío / nota */
            .oy-gmb-log-empty {
                margin: 0;
                padding: 14px;
                color: #888;
                font-size: 12px;
                font-style: italic;
                text-align: center;
            }
            .oy-gmb-log-note {
                margin: 6px 0 0;
                padding: 6px 14px;
                font-size: 11px;
                color: #888;
                font-style: italic;
            }

            /* Responsive */
            @media (max-width: 782px) {
                .oy-gmb-profile-section-header { flex-direction: column; align-items: flex-start; }
                .oy-gmb-field-label { min-width: auto; }
                .oy-gmb-profile-header-bar { flex-direction: column; }
            }
            ';
        }

    } // end class OY_Location_GMB_Profile_Metabox

} // end if ! class_exists
