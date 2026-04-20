<?php
/**
 * OY Location - Basic Information Metabox
 *
 * Metabox externo para "Información Básica" del CPT oy_location.
 * Ahora implementa flujo completo tipo "Dirección":
 * - modo edición local
 * - guardado independiente por AJAX
 * - envío a Google Business Profile para campos soportados
 * - polling de estado post-PATCH
 * - log local detallado del historial de cambios
 *
 * Campos del metabox:
 * - Descripción (GMB)            → SOPORTADO para push a GMB
 * - Fecha de Apertura           → SOPORTADO para push a GMB
 * - Categoría Principal (manual)→ guardado local (pendiente flujo dinámico categories.list)
 * - Rango de Precios            → guardado local (pendiente homologación segura con GBP)
 *
 * @package Lealez
 * @subpackage CPTs/Metaboxes
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'OY_Location_Basic_Info_Metabox' ) ) {

    class OY_Location_Basic_Info_Metabox {

        /**
         * Post type slug.
         *
         * @var string
         */
        private $post_type = 'oy_location';

        /**
         * Constructor.
         */
        public function __construct() {
            add_action( 'add_meta_boxes_oy_location', array( $this, 'register_metabox' ), 11, 1 );

            // Guardado clásico al actualizar el post (compatibilidad con flujo existente)
            add_action( 'save_post_oy_location', array( $this, 'save_meta_box' ), 21, 2 );

            // Guardado independiente del metabox
            add_action( 'wp_ajax_oy_save_basic_info_metabox', array( $this, 'ajax_save_basic_info_metabox' ) );

            // Push a GMB + verificación manual
            add_action( 'wp_ajax_oy_push_basic_info_to_gmb', array( $this, 'ajax_push_basic_info_to_gmb' ) );
            add_action( 'wp_ajax_oy_check_basic_info_push_status', array( $this, 'ajax_check_basic_info_push_status' ) );

            // Assets inline del metabox
            add_action( 'admin_footer', array( $this, 'render_basic_info_footer_assets' ) );

            // Polling WP-Cron post PATCH
            add_action( 'oy_poll_basic_info_push_status', array( 'OY_Location_Basic_Info_Metabox', 'cron_poll_basic_info_push_status' ) );
        }

        /**
         * Registrar metabox.
         *
         * @param WP_Post $post Post actual.
         * @return void
         */
        public function register_metabox( $post ) {
            add_meta_box(
                'oy_location_basic_info',
                __( 'Información Básica', 'lealez' ),
                array( $this, 'render_metabox' ),
                $this->post_type,
                'normal',
                'high'
            );
        }

        /**
         * Guarda el metabox cuando WordPress hace save_post.
         *
         * Mantiene compatibilidad con el guardado normal del post, pero el flujo
         * recomendado del metabox será el guardado independiente por AJAX.
         *
         * @param int     $post_id Post ID.
         * @param WP_Post $post    Post object.
         * @return void
         */
        public function save_meta_box( $post_id, $post ) {
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }

            if ( ! $post_id || ! is_object( $post ) || 'oy_location' !== $post->post_type ) {
                return;
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }

            if ( ! isset( $_POST['location_short_description'], $_POST['opening_date'], $_POST['google_primary_category'], $_POST['price_range'] ) ) {
                return;
            }

            $payload = $this->build_basic_info_payload_from_request();
            $this->persist_basic_info_payload( $post_id, $payload, 'post_update_save' );
        }

        /**
         * Construye el payload normalizado del metabox desde POST.
         *
         * @return array
         */
        private function build_basic_info_payload_from_request() {
            $description = isset( $_POST['location_short_description'] )
                ? sanitize_textarea_field( wp_unslash( $_POST['location_short_description'] ) )
                : '';

            if ( function_exists( 'mb_substr' ) ) {
                $description = mb_substr( $description, 0, 750 );
            } else {
                $description = substr( $description, 0, 750 );
            }

            $opening_date = isset( $_POST['opening_date'] )
                ? sanitize_text_field( wp_unslash( $_POST['opening_date'] ) )
                : '';

            if ( '' !== $opening_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $opening_date ) ) {
                $opening_date = '';
            }

            $google_primary_category = isset( $_POST['google_primary_category'] )
                ? sanitize_text_field( wp_unslash( $_POST['google_primary_category'] ) )
                : '';

            $price_range = isset( $_POST['price_range'] )
                ? sanitize_text_field( wp_unslash( $_POST['price_range'] ) )
                : '';

            if ( ! in_array( $price_range, array( '', '1', '2', '3', '4' ), true ) ) {
                $price_range = '';
            }

            return array(
                'location_short_description' => $description,
                'opening_date'               => $opening_date,
                'google_primary_category'    => $google_primary_category,
                'price_range'                => $price_range,
            );
        }

        /**
         * Persiste el payload local del metabox.
         *
         * @param int    $post_id
         * @param array  $payload
         * @param string $save_source
         * @return array
         */
        private function persist_basic_info_payload( $post_id, array $payload, $save_source = 'manual_metabox_save' ) {
            $post_id     = absint( $post_id );
            $save_source = sanitize_key( (string) $save_source );

            if ( ! $post_id ) {
                return array();
            }

            update_post_meta( $post_id, 'location_short_description', (string) ( $payload['location_short_description'] ?? '' ) );
            update_post_meta( $post_id, 'opening_date',               (string) ( $payload['opening_date'] ?? '' ) );
            update_post_meta( $post_id, 'google_primary_category',    (string) ( $payload['google_primary_category'] ?? '' ) );
            update_post_meta( $post_id, 'price_range',                (string) ( $payload['price_range'] ?? '' ) );

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

            update_post_meta( $post_id, 'oy_basic_info_last_manual_save', $save_meta );
            update_post_meta( $post_id, 'oy_basic_info_local_pending_publish', '1' );
            update_post_meta( $post_id, 'date_modified', current_time( 'mysql' ) );
            update_post_meta( $post_id, 'modified_by_user_id', get_current_user_id() );

            $job = get_post_meta( $post_id, 'gmb_basic_info_push_job', true );
            if ( is_array( $job ) ) {
                if ( ! isset( $job['history'] ) || ! is_array( $job['history'] ) ) {
                    $job['history'] = array();
                }

                $job['history'][] = array(
                    'event'  => 'local_metabox_save',
                    'at'     => $save_meta['at'],
                    'at_ts'  => $save_meta['at_ts'],
                    'by'     => $by,
                    'detail' => 'Se guardaron cambios locales del metabox de Información Básica. Pendiente por publicar en GMB.',
                );

                update_post_meta( $post_id, 'gmb_basic_info_push_job', $job );
            }

            return $save_meta;
        }

        /**
         * Guardado independiente del metabox por AJAX.
         *
         * @return void
         */
        public function ajax_save_basic_info_metabox() {
            $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

            if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_save_basic_info_metabox_' . $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Nonce inválido o post_id faltante.', 'lealez' ) ) );
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'No tienes permisos para editar esta ubicación.', 'lealez' ) ) );
            }

            $post = get_post( $post_id );
            if ( ! $post || 'oy_location' !== $post->post_type ) {
                wp_send_json_error( array( 'message' => __( 'Post no válido o no es una oy_location.', 'lealez' ) ) );
            }

            $payload   = $this->build_basic_info_payload_from_request();
            $save_meta = $this->persist_basic_info_payload( $post_id, $payload, 'manual_metabox_save' );

            wp_send_json_success( array(
                'message'    => __( 'Información Básica guardada localmente.', 'lealez' ),
                'save_meta'  => $save_meta,
                'panel_html' => $this->render_basic_info_push_panel( $post_id ),
            ) );
        }

        /**
         * Render del metabox.
         *
         * @param WP_Post $post
         * @return void
         */
        public function render_metabox( $post ) {
            wp_nonce_field( 'oy_location_save_meta', 'oy_location_meta_nonce' );

            $location_short_description = (string) get_post_meta( $post->ID, 'location_short_description', true );
            $opening_date               = (string) get_post_meta( $post->ID, 'opening_date', true );
            $google_primary_category    = (string) get_post_meta( $post->ID, 'google_primary_category', true );
            $price_range                = (string) get_post_meta( $post->ID, 'price_range', true );

            $gmb_primary_category_name         = (string) get_post_meta( $post->ID, 'gmb_primary_category_name', true );
            $gmb_primary_category_display_name = (string) get_post_meta( $post->ID, 'gmb_primary_category_display_name', true );

            $gmb_open_info_raw = get_post_meta( $post->ID, 'gmb_open_info_raw', true );
            $gmb_opening_date  = '';
            if ( is_array( $gmb_open_info_raw ) && ! empty( $gmb_open_info_raw['openingDate'] ) ) {
                $gmb_opening_date = self::normalize_opening_date_for_compare( $gmb_open_info_raw['openingDate'] );
            }

            $desc_len = function_exists( 'mb_strlen' )
                ? mb_strlen( (string) $location_short_description )
                : strlen( (string) $location_short_description );
            ?>
            <div id="oy-basic-info-wrap">
                <?php echo $this->render_basic_info_push_panel( $post->ID ); ?>

                <div id="oy-basic-info-log-panel" style="margin-bottom:16px; border:1px solid #dadce0; border-radius:4px; overflow:hidden; background:#fff;">
                    <div id="oy-basic-info-log-header" style="
                        display:flex; align-items:center; justify-content:space-between;
                        padding:8px 14px; background:#f6f7f7; cursor:pointer;
                        border-bottom:1px solid transparent; user-select:none;
                    ">
                        <span style="font-size:13px; font-weight:600; color:#1d2327;">
                            🔍 <?php _e( 'Log de Sincronización — Información Básica', 'lealez' ); ?>
                        </span>
                        <span id="oy-basic-info-log-toggle-icon" style="font-size:13px; color:#888; transition:transform .2s;">▶</span>
                    </div>
                    <div id="oy-basic-info-log-body" style="display:none;">
                        <div id="oy-basic-info-log-entries"></div>
                        <div style="padding:8px 14px; border-top:1px solid #f0f0f0; background:#fafafa; display:flex; gap:10px; align-items:center;">
                            <button type="button" id="oy-basic-info-log-clear" class="button button-small"
                                    style="font-size:11px; color:#dc3232; border-color:#dc3232;">
                                🗑 <?php _e( 'Limpiar historial', 'lealez' ); ?>
                            </button>
                            <span style="font-size:11px; color:#aaa; font-style:italic;">
                                <?php _e( 'Historial guardado en el navegador (localStorage). Máx 20 entradas.', 'lealez' ); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom:14px; padding:12px 14px; background:#f6f7f7; border-left:4px solid #2271b1;">
                    <strong><?php _e( 'Campos que hoy se publican en GMB desde este metabox:', 'lealez' ); ?></strong>
                    <ul style="margin:8px 0 0 18px; list-style:disc;">
                        <li><?php _e( 'Descripción (GMB)', 'lealez' ); ?></li>
                        <li><?php _e( 'Fecha de Apertura', 'lealez' ); ?></li>
                    </ul>
                    <p style="margin:8px 0 0;">
                        <?php _e( 'La Categoría Principal y el Rango de Precios se seguirán guardando localmente en Lealez. Para categoría, Google exige un resource name dinámico de categoría válido; por eso aún no se envía desde este flujo.', 'lealez' ); ?>
                    </p>
                </div>

                <div id="oy-basic-info-editor-toolbar" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:12px;">
                    <button type="button" id="oy-basic-info-editor-start" class="button button-secondary">
                        <span class="dashicons dashicons-edit" style="margin-top:3px;"></span>
                        <?php _e( 'Editar información básica', 'lealez' ); ?>
                    </button>
                    <button type="button" id="oy-basic-info-editor-save" class="button button-primary" style="display:none;">
                        <span class="dashicons dashicons-saved" style="margin-top:3px;"></span>
                        <?php _e( 'Guardar metabox', 'lealez' ); ?>
                    </button>
                    <button type="button" id="oy-basic-info-editor-cancel" class="button" style="display:none;">
                        <span class="dashicons dashicons-no-alt" style="margin-top:3px;"></span>
                        <?php _e( 'Cancelar edición', 'lealez' ); ?>
                    </button>
                    <span id="oy-basic-info-editor-state" style="font-size:12px; color:#666;"></span>
                </div>

                <div id="oy-basic-info-inline-status" style="display:none; margin-bottom:12px;"></div>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="location_short_description"><?php _e( 'Descripción (GMB)', 'lealez' ); ?></label>
                        </th>
                        <td>
                            <textarea
                                name="location_short_description"
                                id="location_short_description"
                                rows="4"
                                class="large-text"
                                maxlength="750"
                                data-oy-basic-field="1"
                                readonly="readonly"
                                placeholder="<?php esc_attr_e( 'Máximo 750 caracteres (límite de Google My Business)', 'lealez' ); ?>"
                            ><?php echo esc_textarea( $location_short_description ); ?></textarea>

                            <p class="description">
                                <?php _e( 'Campo soportado para publicación en GMB. Importado desde <code>profile.description</code>.', 'lealez' ); ?>
                                <span id="gmb-desc-char-count" style="font-weight:600;"><?php echo esc_html( $desc_len ); ?>/750</span>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="opening_date"><?php _e( 'Fecha de Apertura', 'lealez' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="date"
                                name="opening_date"
                                id="opening_date"
                                value="<?php echo esc_attr( $opening_date ); ?>"
                                class="regular-text"
                                data-oy-basic-field="1"
                                readonly="readonly"
                                disabled="disabled"
                            >
                            <?php if ( '' !== $gmb_opening_date ) : ?>
                                <p class="description">
                                    <?php
                                    printf(
                                        esc_html__( 'Última fecha detectada desde Google: %s', 'lealez' ),
                                        esc_html( $gmb_opening_date )
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="google_primary_category"><?php _e( 'Categoría Principal (manual)', 'lealez' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                name="google_primary_category"
                                id="google_primary_category"
                                value="<?php echo esc_attr( $google_primary_category ); ?>"
                                class="regular-text"
                                data-oy-basic-field="1"
                                readonly="readonly"
                                placeholder="<?php esc_attr_e( 'Ej: Restaurant, Retail Store, Gym', 'lealez' ); ?>"
                            >
                            <p class="description">
                                <?php _e( 'Se guarda localmente. El valor importado desde Google se sigue poblando desde <code>categories.primaryCategory.displayName</code>.', 'lealez' ); ?>
                            </p>

                            <?php if ( '' !== $gmb_primary_category_display_name || '' !== $gmb_primary_category_name ) : ?>
                                <div style="margin-top:8px; padding:10px 12px; background:#f6f7f7; border:1px solid #dcdcde;">
                                    <?php if ( '' !== $gmb_primary_category_display_name ) : ?>
                                        <div><strong><?php _e( 'Categoría actual en GMB:', 'lealez' ); ?></strong> <?php echo esc_html( $gmb_primary_category_display_name ); ?></div>
                                    <?php endif; ?>
                                    <?php if ( '' !== $gmb_primary_category_name ) : ?>
                                        <div style="margin-top:4px;"><code><?php echo esc_html( $gmb_primary_category_name ); ?></code></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="price_range"><?php _e( 'Rango de Precios', 'lealez' ); ?></label>
                        </th>
                        <td>
                            <select
                                name="price_range"
                                id="price_range"
                                class="regular-text"
                                data-oy-basic-field="1"
                                disabled="disabled">
                                <option value=""><?php _e( 'No especificado', 'lealez' ); ?></option>
                                <option value="1" <?php selected( $price_range, '1' ); ?>>$ - <?php _e( 'Económico', 'lealez' ); ?></option>
                                <option value="2" <?php selected( $price_range, '2' ); ?>>$$ - <?php _e( 'Moderado', 'lealez' ); ?></option>
                                <option value="3" <?php selected( $price_range, '3' ); ?>>$$$ - <?php _e( 'Caro', 'lealez' ); ?></option>
                                <option value="4" <?php selected( $price_range, '4' ); ?>>$$$$ - <?php _e( 'Muy Caro', 'lealez' ); ?></option>
                            </select>
                            <p class="description">
                                <?php _e( 'Se guarda localmente en Lealez. Aún no se envía a GMB en este flujo para evitar empujar un campo no homologado de forma insegura.', 'lealez' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            <?php
        }

        /**
         * Renderiza el panel HTML del estado del último push de Información Básica.
         *
         * @param int $post_id
         * @return string
         */
        private function render_basic_info_push_panel( $post_id ) {
            $post_id      = absint( $post_id );
            $job          = get_post_meta( $post_id, 'gmb_basic_info_push_job', true );
            $push_nonce   = wp_create_nonce( 'oy_push_basic_info_gmb_' . $post_id );
            $check_nonce  = wp_create_nonce( 'oy_check_basic_info_push_status_' . $post_id );

            $business_id       = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $location_name     = (string) get_post_meta( $post_id, 'gmb_location_name', true );
            $gmb_connected     = ! empty( $business_id ) && ! empty( $location_name );
            $local_pending     = (bool) get_post_meta( $post_id, 'oy_basic_info_local_pending_publish', true );
            $last_manual_save  = get_post_meta( $post_id, 'oy_basic_info_last_manual_save', true );
            $last_manual_label = ( is_array( $last_manual_save ) && ! empty( $last_manual_save['at_label'] ) ) ? (string) $last_manual_save['at_label'] : '';
            $last_manual_user  = ( is_array( $last_manual_save ) && ! empty( $last_manual_save['by'] ) ) ? (string) $last_manual_save['by'] : '';

            $job_status         = is_array( $job ) ? (string) ( $job['status'] ?? '' ) : '';
            $push_is_locked     = in_array( $job_status, array( 'pending_review', 'queued' ), true );
            $push_disabled_attr = ( $gmb_connected && ! $push_is_locked ) ? '' : 'disabled';

            ob_start();
            ?>
            <div id="oy-basic-info-push-panel"
                 data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
                 data-push-nonce="<?php echo esc_attr( $push_nonce ); ?>"
                 data-check-nonce="<?php echo esc_attr( $check_nonce ); ?>"
                 style="border:1px solid #dadce0; border-radius:4px; background:#fff; margin-bottom:16px; overflow:hidden;">

                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:10px 14px; background:#f6f7f7; border-bottom:1px solid #dadce0;">
                    <span style="font-size:13px; font-weight:600; color:#1d2327;">
                        📤 <?php _e( 'Publicar Información Básica en Google Business Profile', 'lealez' ); ?>
                    </span>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <button type="button"
                                id="oy-push-basic-info-btn"
                                class="button button-primary"
                                <?php echo $push_disabled_attr; ?>
                                style="display:inline-flex; align-items:center; gap:6px;">
                            <span class="dashicons dashicons-upload" style="margin-top:3px;"></span>
                            <?php _e( 'Enviar a GMB', 'lealez' ); ?>
                        </button>

                        <?php if ( ! empty( $job ) && is_array( $job ) && ! in_array( $job['status'] ?? '', array( 'applied', 'rejected', 'google_override', 'timeout', 'cancelled' ), true ) ) : ?>
                            <button type="button"
                                    id="oy-check-basic-info-push-status-btn"
                                    class="button button-secondary"
                                    style="display:inline-flex; align-items:center; gap:6px;">
                                <span class="dashicons dashicons-search" style="margin-top:3px;"></span>
                                <?php _e( 'Verificar estado', 'lealez' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="padding:12px 14px;">
                    <?php if ( ! $gmb_connected ) : ?>
                        <div style="margin-bottom:10px; padding:10px 12px; background:#fff8e5; border-left:4px solid #dba617;">
                            <?php _e( 'Esta ubicación aún no tiene una cuenta/ubicación GMB vinculada. Vincúlala primero en el metabox de Integración Google My Business.', 'lealez' ); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( $local_pending ) : ?>
                        <div style="margin-bottom:10px; padding:10px 12px; background:#eef6ff; border-left:4px solid #2271b1;">
                            <?php _e( 'Hay cambios locales pendientes por publicar en GMB.', 'lealez' ); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( $last_manual_label ) : ?>
                        <div style="margin-bottom:10px; color:#50575e; font-size:12px;">
                            <strong><?php _e( 'Último guardado local:', 'lealez' ); ?></strong>
                            <?php echo esc_html( $last_manual_label ); ?>
                            <?php if ( $last_manual_user ) : ?>
                                — <?php echo esc_html( $last_manual_user ); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( is_array( $job ) ) : ?>
                        <?php
                        $status = (string) ( $job['status'] ?? 'queued' );
                        $status_map = array(
                            'queued'          => array( 'label' => __( 'En cola', 'lealez' ), 'bg' => '#eef6ff', 'border' => '#2271b1' ),
                            'pending_review'  => array( 'label' => __( 'Pendiente de revisión', 'lealez' ), 'bg' => '#fff8e5', 'border' => '#dba617' ),
                            'applied'         => array( 'label' => __( 'Aplicado', 'lealez' ), 'bg' => '#edfaef', 'border' => '#00a32a' ),
                            'rejected'        => array( 'label' => __( 'Rechazado / No aplicado', 'lealez' ), 'bg' => '#fff1f0', 'border' => '#d63638' ),
                            'google_override' => array( 'label' => __( 'Google lo modificó de otra forma', 'lealez' ), 'bg' => '#fff1f0', 'border' => '#d63638' ),
                            'timeout'         => array( 'label' => __( 'Sin resolución (timeout)', 'lealez' ), 'bg' => '#fff8e5', 'border' => '#dba617' ),
                            'error'           => array( 'label' => __( 'Error', 'lealez' ), 'bg' => '#fff1f0', 'border' => '#d63638' ),
                        );
                        $style = isset( $status_map[ $status ] ) ? $status_map[ $status ] : $status_map['queued'];
                        ?>
                        <div style="margin-bottom:10px; padding:10px 12px; background:<?php echo esc_attr( $style['bg'] ); ?>; border-left:4px solid <?php echo esc_attr( $style['border'] ); ?>;">
                            <strong><?php _e( 'Estado del último envío:', 'lealez' ); ?></strong>
                            <?php echo esc_html( $style['label'] ); ?>
                        </div>

                        <?php if ( ! empty( $job['pushed_at'] ) ) : ?>
                            <div style="margin-bottom:8px; color:#50575e; font-size:12px;">
                                <strong><?php _e( 'Último envío:', 'lealez' ); ?></strong>
                                <?php echo esc_html( (string) $job['pushed_at'] ); ?>
                                <?php if ( ! empty( $job['pushed_by'] ) ) : ?>
                                    — <?php echo esc_html( (string) $job['pushed_by'] ); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $job['update_mask'] ) ) : ?>
                            <div style="margin-bottom:8px; color:#50575e; font-size:12px;">
                                <strong><?php _e( 'updateMask:', 'lealez' ); ?></strong>
                                <code><?php echo esc_html( (string) $job['update_mask'] ); ?></code>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $job['history'] ) && is_array( $job['history'] ) ) : ?>
                            <div style="margin-top:12px;">
                                <strong style="display:block; margin-bottom:8px;"><?php _e( 'Historial del metabox', 'lealez' ); ?></strong>
                                <div style="max-height:220px; overflow:auto; border:1px solid #dcdcde; background:#fff;">
                                    <table style="width:100%; border-collapse:collapse;">
                                        <thead>
                                            <tr style="background:#f6f7f7;">
                                                <th style="text-align:left; padding:8px; border-bottom:1px solid #dcdcde;"><?php _e( 'Fecha', 'lealez' ); ?></th>
                                                <th style="text-align:left; padding:8px; border-bottom:1px solid #dcdcde;"><?php _e( 'Actor', 'lealez' ); ?></th>
                                                <th style="text-align:left; padding:8px; border-bottom:1px solid #dcdcde;"><?php _e( 'Detalle', 'lealez' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( array_reverse( $job['history'] ) as $entry ) : ?>
                                                <tr>
                                                    <td style="padding:8px; border-bottom:1px solid #f0f0f1; vertical-align:top;">
                                                        <?php echo esc_html( (string) ( $entry['at'] ?? '' ) ); ?>
                                                    </td>
                                                    <td style="padding:8px; border-bottom:1px solid #f0f0f1; vertical-align:top;">
                                                        <?php echo esc_html( (string) ( $entry['by'] ?? '' ) ); ?>
                                                    </td>
                                                    <td style="padding:8px; border-bottom:1px solid #f0f0f1; vertical-align:top;">
                                                        <?php echo esc_html( (string) ( $entry['detail'] ?? '' ) ); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else : ?>
                        <div style="color:#50575e; font-size:12px;">
                            <?php _e( 'Aún no se ha enviado este metabox a Google Business Profile.', 'lealez' ); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /**
         * Convierte un valor openingDate a YYYY-MM-DD para comparaciones.
         *
         * @param mixed $value
         * @return string
         */
        private static function normalize_opening_date_for_compare( $value ) {
            if ( is_string( $value ) ) {
                $value = trim( $value );
                return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
            }

            if ( is_array( $value ) ) {
                $year  = isset( $value['year'] ) ? (int) $value['year'] : 0;
                $month = isset( $value['month'] ) ? (int) $value['month'] : 0;
                $day   = isset( $value['day'] ) ? (int) $value['day'] : 0;

                if ( $year > 0 && $month > 0 && $day > 0 ) {
                    return sprintf( '%04d-%02d-%02d', $year, $month, $day );
                }
            }

            return '';
        }

        private function get_basic_info_local_snapshot( $post_id ) {
            $post_id = absint( $post_id );

            return array(
                'location_short_description' => (string) get_post_meta( $post_id, 'location_short_description', true ),
                'opening_date'               => (string) get_post_meta( $post_id, 'opening_date', true ),
                'google_primary_category'    => (string) get_post_meta( $post_id, 'google_primary_category', true ),
                'price_range'                => (string) get_post_meta( $post_id, 'price_range', true ),
            );
        }

        /**
         * Construye un snapshot comparable para el log visual usando un snapshot GMB.
         *
         * @param int   $post_id
         * @param array $snapshot
         * @return array
         */
        private function build_basic_info_log_snapshot_from_gmb_snapshot( $post_id, array $snapshot ) {
            $local = $this->get_basic_info_local_snapshot( $post_id );

            $local['location_short_description'] = isset( $snapshot['profile']['description'] )
                ? (string) $snapshot['profile']['description']
                : '';

            $local['opening_date'] = self::normalize_opening_date_for_compare( $snapshot['openInfo']['openingDate'] ?? '' );

            return $local;
        }

        /**
         * Construye un snapshot comparable para el log visual usando el payload enviado a GMB.
         *
         * @param int   $post_id
         * @param array $submitted
         * @return array
         */
        private function build_basic_info_log_snapshot_from_submitted_payload( $post_id, array $submitted ) {
            $local = $this->get_basic_info_local_snapshot( $post_id );

            if ( isset( $submitted['profile']['description'] ) ) {
                $local['location_short_description'] = (string) $submitted['profile']['description'];
            }

            if ( isset( $submitted['openInfo']['openingDate'] ) ) {
                $local['opening_date'] = self::normalize_opening_date_for_compare( $submitted['openInfo']['openingDate'] );
            }

            return $local;
        }

        /**
         * Determina el resultado del push comparando snapshots.
         *
         * @param array $job
         * @param array $current
         * @return string
         */
        private static function determine_push_outcome( array $job, array $current ) {
            if ( ! empty( $current['metadata']['hasPendingEdits'] ) ) {
                return 'pending_review';
            }

            $submitted_desc = trim( (string) ( $job['submitted']['profile']['description'] ?? '' ) );
            $before_desc    = trim( (string) ( $job['snapshot_before']['profile']['description'] ?? '' ) );
            $current_desc   = trim( (string) ( $current['profile']['description'] ?? '' ) );

            $submitted_date = self::normalize_opening_date_for_compare( $job['submitted']['openInfo']['openingDate'] ?? '' );
            $before_date    = self::normalize_opening_date_for_compare( $job['snapshot_before']['openInfo']['openingDate'] ?? '' );
            $current_date   = self::normalize_opening_date_for_compare( $current['openInfo']['openingDate'] ?? '' );

            $submitted_checks = array();
            $before_checks    = array();

            if ( '' !== $submitted_desc ) {
                $submitted_checks[] = ( $current_desc === $submitted_desc );
                $before_checks[]    = ( $current_desc === $before_desc );
            }

            if ( '' !== $submitted_date ) {
                $submitted_checks[] = ( $current_date === $submitted_date );
                $before_checks[]    = ( $current_date === $before_date );
            }

            if ( empty( $submitted_checks ) ) {
                return 'rejected';
            }

            if ( ! in_array( false, $submitted_checks, true ) ) {
                return 'applied';
            }

            if ( ! empty( $before_checks ) && ! in_array( false, $before_checks, true ) ) {
                return 'rejected';
            }

            if ( '' === $current_desc && '' === $current_date ) {
                return 'pending_review';
            }

            return 'google_override';
        }

        /**
         * Programa el siguiente ciclo de polling.
         *
         * @param int $post_id
         * @param int $poll_count
         * @return void
         */
        private static function schedule_next_poll( $post_id, $poll_count ) {
            $intervals = array( 60, 120, 300, 600, 900, 1800, 3600, 7200, 21600 );
            $idx       = (int) $poll_count;

            if ( isset( $intervals[ $idx ] ) ) {
                $delay = $intervals[ $idx ];
            } else {
                $delay = HOUR_IN_SECONDS;
            }

            wp_schedule_single_event( time() + $delay, 'oy_poll_basic_info_push_status', array( absint( $post_id ) ) );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[Lealez GMB] schedule_next_poll basic_info post_id=%d poll_count=%d → siguiente en %d s',
                    absint( $post_id ),
                    (int) $poll_count,
                    (int) $delay
                ) );
            }
        }

        /**
         * AJAX: Envía la Información Básica guardada en Lealez hacia GMB.
         *
         * @return void
         */
        public function ajax_push_basic_info_to_gmb() {
            $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

            if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_push_basic_info_gmb_' . $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
            }

            $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );

            if ( ! $business_id || ! $location_name ) {
                wp_send_json_error( array(
                    'message'    => __( 'Esta ubicación no tiene empresa/ubicación GMB vinculada.', 'lealez' ),
                    'panel_html' => $this->render_basic_info_push_panel( $post_id ),
                ) );
            }

            if ( ! class_exists( 'Lealez_GMB_API' ) ) {
                wp_send_json_error( array( 'message' => __( 'La clase Lealez_GMB_API no está disponible.', 'lealez' ) ) );
            }

            $description  = (string) get_post_meta( $post_id, 'location_short_description', true );
            $opening_date = (string) get_post_meta( $post_id, 'opening_date', true );

            $payload = array(
                'location_short_description' => $description,
                'opening_date'               => $opening_date,
            );

            if ( '' === trim( $description ) && '' === trim( $opening_date ) ) {
                wp_send_json_error( array( 'message' => __( 'No hay Descripción ni Fecha de Apertura listas para enviar a GMB.', 'lealez' ) ) );
            }

            $snapshot = Lealez_GMB_API::get_location_basic_info_snapshot( $business_id, $location_name );

            if ( is_wp_error( $snapshot ) ) {
                wp_send_json_error( array(
                    'message' => sprintf( __( 'No se pudo obtener el estado actual de GMB: %s', 'lealez' ), $snapshot->get_error_message() ),
                ) );
            }

            if ( ! empty( $snapshot['metadata']['hasPendingEdits'] ) ) {
                $current_job    = get_post_meta( $post_id, 'gmb_basic_info_push_job', true );
                $local_resolved = is_array( $current_job ) && in_array( $current_job['status'] ?? '', array( 'applied', 'rejected', 'google_override', 'timeout', 'error' ), true );

                if ( ! $local_resolved ) {
                    wp_send_json_error( array(
                        'message'    => __( 'Google tiene un cambio de Información Básica en revisión. No se puede enviar otro hasta que se resuelva. Usa el botón "Verificar estado".', 'lealez' ),
                        'panel_html' => $this->render_basic_info_push_panel( $post_id ),
                        'log_context' => array(
                            'before' => $this->build_basic_info_log_snapshot_from_gmb_snapshot( $post_id, $snapshot ),
                            'after'  => $this->get_basic_info_local_snapshot( $post_id ),
                            'raw'    => array(
                                'action'   => 'push_basic_info_blocked_pending_edits',
                                'snapshot' => $snapshot,
                            ),
                        ),
                    ) );
                }
            }

            $result = Lealez_GMB_API::push_location_basic_info( $business_id, $location_name, $payload );

            if ( is_wp_error( $result ) ) {
                $err_data   = $result->get_error_data();
                $violations = isset( $err_data['field_violations'] ) ? $err_data['field_violations'] : array();
                $viol_txt   = '';
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

                $response_arr['log_context'] = array(
                    'before' => $this->build_basic_info_log_snapshot_from_gmb_snapshot( $post_id, is_array( $snapshot ) ? $snapshot : array() ),
                    'after'  => $this->get_basic_info_local_snapshot( $post_id ),
                    'raw'    => array(
                        'action'           => 'push_basic_info_error',
                        'error_message'    => $result->get_error_message(),
                        'field_violations' => $violations,
                        'error_data'       => is_array( $err_data ) ? $err_data : array(),
                    ),
                );

                wp_send_json_error( $response_arr );
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
                'update_mask'     => (string) ( $result['update_mask'] ?? '' ),
                'submitted'       => array(
                    'profile'  => isset( $result['submitted']['profile'] ) && is_array( $result['submitted']['profile'] ) ? $result['submitted']['profile'] : array(),
                    'openInfo' => isset( $result['submitted']['openInfo'] ) && is_array( $result['submitted']['openInfo'] ) ? $result['submitted']['openInfo'] : array(),
                ),
                'snapshot_before' => array(
                    'profile'  => isset( $snapshot['profile'] ) && is_array( $snapshot['profile'] ) ? $snapshot['profile'] : array(),
                    'openInfo' => isset( $snapshot['openInfo'] ) && is_array( $snapshot['openInfo'] ) ? $snapshot['openInfo'] : array(),
                ),
                'poll_count'      => 0,
                'next_poll_at'    => $now_ts + 60,
                'history'         => array(
                    array(
                        'event'  => 'push_submitted',
                        'at'     => $now_iso,
                        'at_ts'  => $now_ts,
                        'by'     => $user_login,
                        'detail' => 'Se envió Información Básica a Google Business Profile. Estado inicial: pending_review.',
                    ),
                ),
            );

            update_post_meta( $post_id, 'gmb_basic_info_push_job', $job );

            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'info',
                    'Basic info pushed to GBP from Lealez.',
                    array(
                        'post_id'     => $post_id,
                        'location'    => $location_name,
                        'update_mask' => $job['update_mask'],
                    )
                );
            }

            wp_schedule_single_event( $now_ts + 60, 'oy_poll_basic_info_push_status', array( $post_id ) );

            wp_send_json_success( array(
                'message'    => __( 'Cambio enviado a Google Business Profile. Estado: pendiente de revisión por Google. El sistema lo verificará automáticamente.', 'lealez' ),
                'panel_html' => $this->render_basic_info_push_panel( $post_id ),
                'log_context' => array(
                    'before' => $this->build_basic_info_log_snapshot_from_gmb_snapshot( $post_id, $snapshot ),
                    'after'  => $this->build_basic_info_log_snapshot_from_submitted_payload( $post_id, $job['submitted'] ),
                    'raw'    => array(
                        'action'          => 'push_basic_info_to_gmb',
                        'update_mask'     => $job['update_mask'],
                        'patch_response'  => isset( $result['patch_response'] ) && is_array( $result['patch_response'] ) ? $result['patch_response'] : array(),
                        'snapshot_before' => $job['snapshot_before'],
                        'submitted'       => $job['submitted'],
                    ),
                ),
            ) );
        }

        /**
         * AJAX: Verifica manualmente el estado del último push de Información Básica.
         *
         * @return void
         */
        public function ajax_check_basic_info_push_status() {
            $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

            if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_check_basic_info_push_status_' . $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
            }

            $job = get_post_meta( $post_id, 'gmb_basic_info_push_job', true );
            if ( empty( $job ) || ! is_array( $job ) ) {
                wp_send_json_error( array( 'message' => __( 'No hay push registrado para esta ubicación.', 'lealez' ) ) );
            }

            if ( in_array( $job['status'] ?? '', array( 'applied', 'rejected', 'google_override', 'timeout', 'cancelled' ), true ) ) {
                wp_send_json_success( array(
                    'message'    => __( 'El cambio ya está resuelto.', 'lealez' ),
                    'status'     => $job['status'],
                    'panel_html' => $this->render_basic_info_push_panel( $post_id ),
                    'log_context' => array(
                        'before' => $this->build_basic_info_log_snapshot_from_gmb_snapshot( $post_id, array(
                            'profile'  => isset( $job['snapshot_before']['profile'] ) && is_array( $job['snapshot_before']['profile'] ) ? $job['snapshot_before']['profile'] : array(),
                            'openInfo' => isset( $job['snapshot_before']['openInfo'] ) && is_array( $job['snapshot_before']['openInfo'] ) ? $job['snapshot_before']['openInfo'] : array(),
                        ) ),
                        'after'  => $this->build_basic_info_log_snapshot_from_submitted_payload( $post_id, isset( $job['submitted'] ) && is_array( $job['submitted'] ) ? $job['submitted'] : array() ),
                        'raw'    => array(
                            'action' => 'manual_check_already_resolved',
                            'job'    => $job,
                        ),
                    ),
                ) );
            }

            $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );

            if ( ! $business_id || ! $location_name ) {
                wp_send_json_error( array( 'message' => __( 'No hay empresa/ubicación GMB vinculada.', 'lealez' ) ) );
            }

            $current = Lealez_GMB_API::poll_location_basic_info_status( $business_id, $location_name );

            if ( is_wp_error( $current ) ) {
                wp_send_json_error( array(
                    'message'    => __( 'Error al consultar GMB: ', 'lealez' ) . $current->get_error_message(),
                    'panel_html' => $this->render_basic_info_push_panel( $post_id ),
                    'log_context' => array(
                        'before' => $this->build_basic_info_log_snapshot_from_gmb_snapshot( $post_id, array(
                            'profile'  => isset( $job['snapshot_before']['profile'] ) && is_array( $job['snapshot_before']['profile'] ) ? $job['snapshot_before']['profile'] : array(),
                            'openInfo' => isset( $job['snapshot_before']['openInfo'] ) && is_array( $job['snapshot_before']['openInfo'] ) ? $job['snapshot_before']['openInfo'] : array(),
                        ) ),
                        'after'  => $this->get_basic_info_local_snapshot( $post_id ),
                        'raw'    => array(
                            'action'        => 'manual_check_error',
                            'error_message' => $current->get_error_message(),
                            'error_data'    => $current->get_error_data(),
                        ),
                    ),
                ) );
            }

            $now_ts     = time();
            $now_iso    = gmdate( 'Y-m-d\TH:i:s\Z', $now_ts );
            $new_status = self::determine_push_outcome( $job, $current );

            $job['status']     = $new_status;
            $job['poll_count'] = ( $job['poll_count'] ?? 0 ) + 1;
            if ( ! isset( $job['history'] ) || ! is_array( $job['history'] ) ) {
                $job['history'] = array();
            }

            $current_user = wp_get_current_user();
            $job['history'][] = array(
                'event'  => 'manual_check',
                'at'     => $now_iso,
                'at_ts'  => $now_ts,
                'by'     => ( $current_user instanceof WP_User && ! empty( $current_user->user_login ) ) ? $current_user->user_login : 'system',
                'detail' => 'Verificación manual → estado: ' . $new_status
                    . ' | hasPendingEdits=' . ( ! empty( $current['metadata']['hasPendingEdits'] ) ? 'true' : 'false' )
                    . ' | hasGoogleUpdated=' . ( ! empty( $current['metadata']['hasGoogleUpdated'] ) ? 'true' : 'false' ),
            );

            if ( in_array( $new_status, array( 'applied', 'rejected', 'google_override' ), true ) ) {
                $job['resolved_at'] = $now_iso;
            }

            update_post_meta( $post_id, 'gmb_basic_info_push_job', $job );

            if ( 'applied' === $new_status ) {
                delete_post_meta( $post_id, 'oy_basic_info_local_pending_publish' );
            }

            wp_send_json_success( array(
                'message'    => '',
                'status'     => $new_status,
                'panel_html' => $this->render_basic_info_push_panel( $post_id ),
                'log_context' => array(
                    'before' => $this->build_basic_info_log_snapshot_from_gmb_snapshot( $post_id, array(
                        'profile'  => isset( $job['snapshot_before']['profile'] ) && is_array( $job['snapshot_before']['profile'] ) ? $job['snapshot_before']['profile'] : array(),
                        'openInfo' => isset( $job['snapshot_before']['openInfo'] ) && is_array( $job['snapshot_before']['openInfo'] ) ? $job['snapshot_before']['openInfo'] : array(),
                    ) ),
                    'after'  => $this->build_basic_info_log_snapshot_from_gmb_snapshot( $post_id, $current ),
                    'raw'    => array(
                        'action'           => 'manual_check_basic_info_status',
                        'status'           => $new_status,
                        'metadata'         => isset( $current['metadata'] ) && is_array( $current['metadata'] ) ? $current['metadata'] : array(),
                        'current_snapshot' => $current,
                        'submitted'        => isset( $job['submitted'] ) && is_array( $job['submitted'] ) ? $job['submitted'] : array(),
                    ),
                ),
            ) );
        }

        /**
         * WP-Cron: ciclo de polling automático post-PATCH.
         *
         * @param int $post_id
         * @return void
         */
        public static function cron_poll_basic_info_push_status( $post_id ) {
            $post_id = absint( $post_id );
            if ( ! $post_id ) {
                return;
            }

            $job = get_post_meta( $post_id, 'gmb_basic_info_push_job', true );
            if ( empty( $job ) || ! is_array( $job ) ) {
                return;
            }

            if ( ! in_array( $job['status'] ?? '', array( 'pending_review', 'queued' ), true ) ) {
                return;
            }

            $pushed_ts   = $job['pushed_at_ts'] ?? 0;
            $thirty_days = 30 * DAY_IN_SECONDS;
            if ( $pushed_ts && ( time() - $pushed_ts ) > $thirty_days ) {
                $job['status']      = 'timeout';
                $job['resolved_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                $job['history'][]   = array(
                    'event'  => 'timeout',
                    'at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
                    'at_ts'  => time(),
                    'by'     => 'cron',
                    'detail' => 'Sin respuesta de Google en 30 días. El cambio puede haberse perdido.',
                );
                update_post_meta( $post_id, 'gmb_basic_info_push_job', $job );
                return;
            }

            $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );

            if ( ! $business_id || ! $location_name ) {
                return;
            }

            $current = Lealez_GMB_API::poll_location_basic_info_status( $business_id, $location_name );

            $now_ts  = time();
            $now_iso = gmdate( 'Y-m-d\TH:i:s\Z', $now_ts );

            if ( is_wp_error( $current ) ) {
                $job['poll_count'] = ( $job['poll_count'] ?? 0 ) + 1;
                $job['history'][]  = array(
                    'event'  => 'poll_error',
                    'at'     => $now_iso,
                    'at_ts'  => $now_ts,
                    'by'     => 'cron',
                    'detail' => 'Error API: ' . $current->get_error_message(),
                );
                update_post_meta( $post_id, 'gmb_basic_info_push_job', $job );
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

            update_post_meta( $post_id, 'gmb_basic_info_push_job', $job );

            if ( 'applied' === $new_status ) {
                delete_post_meta( $post_id, 'oy_basic_info_local_pending_publish' );
            }

            if ( 'pending_review' === $new_status ) {
                self::schedule_next_poll( $post_id, $job['poll_count'] );
            }
        }

        /**
         * Renderiza JS inline del metabox.
         *
         * @return void
         */
        public function render_basic_info_footer_assets() {
            global $post;

            if ( ! $post || 'oy_location' !== $post->post_type ) {
                return;
            }

            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            if ( ! $screen || 'oy_location' !== $screen->post_type ) {
                return;
            }

            $post_id           = (int) $post->ID;
            $save_nonce        = wp_create_nonce( 'oy_save_basic_info_metabox_' . $post_id );
            $last_manual_save  = get_post_meta( $post_id, 'oy_basic_info_last_manual_save', true );
            $last_manual_label = ( is_array( $last_manual_save ) && ! empty( $last_manual_save['at_label'] ) ) ? (string) $last_manual_save['at_label'] : '';
            $local_pending     = (bool) get_post_meta( $post_id, 'oy_basic_info_local_pending_publish', true );
            ?>
            <script>
                (function($){
                    'use strict';

                    var editorState = {
                        enabled: false,
                        dirty: false,
                        saving: false,
                        baseline: null
                    };

                    var SAVE_NONCE = <?php echo wp_json_encode( $save_nonce ); ?>;
                    var POST_ID = <?php echo (int) $post_id; ?>;
                    var AJAX_URL = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
                    var lastManualLabel = <?php echo wp_json_encode( $last_manual_label ); ?>;
                    var localPending = <?php echo $local_pending ? 'true' : 'false'; ?>;
                    var LS_KEY = 'oy_basic_info_log_' + POST_ID;
                    var MAX_LOG = 20;

                    var FIELD_MAP = [
                        { key: 'location_short_description', label: 'Descripción (GMB)' },
                        { key: 'opening_date', label: 'Fecha de Apertura' },
                        { key: 'google_primary_category', label: 'Categoría Principal (manual)' },
                        { key: 'price_range', label: 'Rango de Precios' }
                    ];

                    function setStatus(message, type) {
                        var $box = $('#oy-basic-info-inline-status');
                        if (!$box.length) { return; }
                        if (!message) { $box.hide().empty(); return; }
                        var border = '#2271b1', bg = '#eef6ff';
                        if (type === 'error') { border = '#d63638'; bg = '#fff1f0'; }
                        else if (type === 'success') { border = '#00a32a'; bg = '#edfaef'; }
                        else if (type === 'warning') { border = '#dba617'; bg = '#fff8e5'; }
                        $box.show().html('<div style="padding:10px 12px; background:'+bg+'; border-left:4px solid '+border+';">'+message+'</div>');
                    }

                    function setPushStatus(message, type) {
                        var $panel = $('#oy-basic-info-push-panel');
                        if (!$panel.length) { return; }
                        var $slot = $panel.find('.oy-basic-info-push-inline-message');
                        if (!$slot.length) {
                            $slot = $('<div class="oy-basic-info-push-inline-message" style="padding:0 14px 14px;"></div>');
                            $panel.append($slot);
                        }
                        if (!message) { $slot.empty(); return; }
                        var border = '#2271b1', bg = '#eef6ff';
                        if (type === 'error') { border = '#d63638'; bg = '#fff1f0'; }
                        else if (type === 'success') { border = '#00a32a'; bg = '#edfaef'; }
                        else if (type === 'warning') { border = '#dba617'; bg = '#fff8e5'; }
                        $slot.html('<div style="padding:10px 12px; background:'+bg+'; border-left:4px solid '+border+';">'+message+'</div>');
                    }

                    function captureState() {
                        return {
                            location_short_description: $('#location_short_description').val() || '',
                            opening_date: $('#opening_date').val() || '',
                            google_primary_category: $('#google_primary_category').val() || '',
                            price_range: $('#price_range').val() || ''
                        };
                    }

                    function statesEqual(a, b) { return JSON.stringify(a) === JSON.stringify(b); }

                    function setFieldsEnabled(enabled) {
                        var $fields = $('[data-oy-basic-field="1"]');
                        $fields.each(function() {
                            var $field = $(this);
                            if ($field.is('textarea') || ($field.is('input') && $field.attr('type') !== 'date')) {
                                enabled ? $field.removeAttr('readonly') : $field.attr('readonly', 'readonly');
                            }
                            if ($field.is('select') || ($field.is('input') && $field.attr('type') === 'date')) {
                                $field.prop('disabled', !enabled);
                            }
                        });
                    }

                    function refreshDirtyState() {
                        editorState.dirty = editorState.enabled ? !statesEqual(editorState.baseline, captureState()) : false;
                        updateUiState();
                    }

                    function updateUiState() {
                        setFieldsEnabled(editorState.enabled && !editorState.saving);
                        $('#oy-basic-info-editor-start').toggle(!editorState.enabled && !editorState.saving);
                        $('#oy-basic-info-editor-save').toggle(editorState.enabled);
                        $('#oy-basic-info-editor-cancel').toggle(editorState.enabled);
                        $('#oy-basic-info-editor-save').prop('disabled', !editorState.enabled || !editorState.dirty || editorState.saving);
                        $('#oy-basic-info-editor-cancel').prop('disabled', editorState.saving);

                        if (editorState.saving) $('#oy-basic-info-editor-state').text('Guardando metabox...');
                        else if (editorState.enabled && editorState.dirty) $('#oy-basic-info-editor-state').text('Tienes cambios locales sin guardar.');
                        else if (editorState.enabled) $('#oy-basic-info-editor-state').text('Modo edición activo.');
                        else $('#oy-basic-info-editor-state').text('Modo lectura.');
                    }

                    function beginEditMode() {
                        editorState.enabled = true;
                        editorState.saving = false;
                        editorState.baseline = captureState();
                        refreshDirtyState();
                        setStatus('Modo edición activado. Guarda el metabox antes de usar "Enviar a GMB".', 'info');
                    }

                    function cancelEditMode() {
                        if (!editorState.baseline) editorState.baseline = captureState();
                        $('#location_short_description').val(editorState.baseline.location_short_description);
                        $('#opening_date').val(editorState.baseline.opening_date);
                        $('#google_primary_category').val(editorState.baseline.google_primary_category);
                        $('#price_range').val(editorState.baseline.price_range);
                        updateDescriptionCounter();
                        editorState.enabled = false;
                        editorState.dirty = false;
                        editorState.saving = false;
                        updateUiState();
                        setStatus('Edición cancelada. Se restauró el último estado guardado localmente.', 'warning');
                    }

                    function updateDescriptionCounter() {
                        var val = $('#location_short_description').val() || '';
                        var len = val.length;
                        $('#gmb-desc-char-count').text(len + '/750').css('color', len > 700 ? '#d63638' : '');
                    }

                    function replacePushPanel(html) {
                        if (html) { $('#oy-basic-info-push-panel').replaceWith(html); }
                    }

                    function formatPriceRange(value) {
                        var map = {'':'No especificado','1':'$ - Económico','2':'$$ - Moderado','3':'$$$ - Caro','4':'$$$$ - Muy Caro'};
                        var key = String(value == null ? '' : value);
                        return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : key;
                    }

                    function valueToText(fieldKey, value) {
                        if (fieldKey === 'price_range') return formatPriceRange(value);
                        return String(value == null ? '' : value);
                    }

                    function buildDiff(before, after) {
                        var rows = [];
                        FIELD_MAP.forEach(function(field) {
                            var bStr = valueToText(field.key, before && Object.prototype.hasOwnProperty.call(before, field.key) ? before[field.key] : '');
                            var aStr = valueToText(field.key, after && Object.prototype.hasOwnProperty.call(after, field.key) ? after[field.key] : '');
                            var rawBefore = String(bStr == null ? '' : bStr);
                            var rawAfter  = String(aStr == null ? '' : aStr);
                            var status = (rawBefore === rawAfter) ? 'unchanged' : ((!rawBefore || rawBefore.trim() === '') ? 'new' : 'changed');
                            rows.push({ label: field.label, before: bStr, after: aStr, status: status });
                        });
                        return rows;
                    }

                    function loadLog() {
                        try {
                            var raw = localStorage.getItem(LS_KEY);
                            if (!raw) return [];
                            var parsed = JSON.parse(raw);
                            return Array.isArray(parsed) ? parsed : [];
                        } catch (e) { return []; }
                    }

                    function saveLog(entries) {
                        try {
                            if (entries.length > MAX_LOG) entries = entries.slice(entries.length - MAX_LOG);
                            localStorage.setItem(LS_KEY, JSON.stringify(entries));
                        } catch (e) {}
                    }

                    function clearLog() {
                        try { localStorage.removeItem(LS_KEY); } catch (e) {}
                    }

                    function addLogEntry(rawPayload, diff, source) {
                        try {
                            var now = new Date();
                            var timestamp = now.toLocaleDateString('es-CO', {year:'numeric',month:'2-digit',day:'2-digit'}) + ' ' +
                                            now.toLocaleTimeString('es-CO', {hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false});
                            var safeRaw = rawPayload;
                            try { JSON.stringify(rawPayload); } catch (err) { safeRaw = { _serializeError: 'Objeto no serializable: ' + err.message }; }
                            var entries = loadLog();
                            entries.push({ timestamp: timestamp, raw: safeRaw, diff: Array.isArray(diff) ? diff : [], source: source || 'manual_save' });
                            saveLog(entries);
                            renderLog();
                            if ($('#oy-basic-info-log-body').is(':hidden')) {
                                $('#oy-basic-info-log-body').show();
                                $('#oy-basic-info-log-header').css('borderBottomColor', '#dadce0');
                                $('#oy-basic-info-log-toggle-icon').text('▼');
                            }
                        } catch (e) {
                            if (window.console && window.console.error) console.error('[OY Basic Info Log] addLogEntry error:', e);
                        }
                    }

                    function getSourceMeta(source) {
                        var map = {
                            manual_save:  { icon: '💾', label: ' · Guardado local' },
                            push_to_gmb:  { icon: '🚀', label: ' · Enviar a GMB' },
                            manual_check: { icon: '🔎', label: ' · Verificación manual' },
                            push_error:   { icon: '❌', label: ' · Error al enviar' },
                            check_error:  { icon: '⚠️', label: ' · Error al verificar' }
                        };
                        return map[source] || { icon: '🔵', label: ' · Acción del metabox' };
                    }

                    function renderLog() {
                        try {
                            var entries = loadLog();
                            var $container = $('#oy-basic-info-log-entries');
                            if (!$container.length) return;
                            $container.empty();

                            if (!entries.length) {
                                $container.append(
                                    $('<p/>').css({ padding:'12px 16px', margin:0, fontSize:'12px', color:'#888', fontStyle:'italic' })
                                        .text('Aún no hay acciones registradas. Guarda cambios locales o usa "Enviar a GMB" para empezar a construir el historial detallado.')
                                );
                                return;
                            }

                            var sorted = entries.slice().reverse();

                            sorted.forEach(function(entry, idx) {
                                var diff = Array.isArray(entry.diff) ? entry.diff : [];
                                var changedCount = diff.filter(function(row){ return row.status === 'changed' || row.status === 'new'; }).length;
                                var isFirst = idx === 0;
                                var bgHeader = isFirst ? '#f0f7ff' : '#fff';
                                var sourceMeta = getSourceMeta(entry.source);
                                var hasError = entry.raw && (entry.raw.error || entry.raw._serializeError || entry.raw.error_message);

                                var $entry = $('<div/>').css({ borderBottom: '1px solid ' + (isFirst ? '#c3d4e6' : '#f0f0f0') });
                                var $entryHeader = $('<div/>').css({
                                    display:'flex', alignItems:'center', justifyContent:'space-between',
                                    padding:'8px 14px', cursor:'pointer', userSelect:'none', background:bgHeader, gap:'10px'
                                });

                                var statusLabel, statusColor;
                                if (hasError) { statusLabel = '❌ Error'; statusColor = '#dc3232'; }
                                else if (!diff.length) { statusLabel = '⚠️ Sin datos de diff'; statusColor = '#999'; }
                                else if (changedCount === 0) { statusLabel = '✅ Sin cambios'; statusColor = '#46b450'; }
                                else { statusLabel = '✏️ ' + changedCount + ' campo' + (changedCount !== 1 ? 's' : '') + ' modificado' + (changedCount !== 1 ? 's' : ''); statusColor = '#e06800'; }

                                $entryHeader.append(
                                    $('<span/>').css({ fontSize:'11px', color:'#555', flex:'1', fontFamily:'monospace' })
                                        .text(sourceMeta.icon + ' 🕐 ' + entry.timestamp + sourceMeta.label + (isFirst ? '  ← más reciente' : ''))
                                );
                                $entryHeader.append(
                                    $('<span/>').css({ fontSize:'11px', fontWeight:'600', color:statusColor }).text(statusLabel)
                                );
                                var $toggleIcon = $('<span/>').css({ fontSize:'11px', color:'#aaa', marginLeft:'6px' }).text(isFirst ? '▼' : '▶');
                                $entryHeader.append($toggleIcon);

                                var $body = $('<div/>').css({ display:isFirst ? 'block' : 'none', padding:'0 14px 14px', background:'#fff' });
                                $entryHeader.on('click', function(){ $body.toggle(); $toggleIcon.text($body.is(':visible') ? '▼' : '▶'); });

                                if (diff.length) {
                                    var $table = $('<table/>').css({ width:'100%', borderCollapse:'collapse', fontSize:'11px', marginTop:'10px', marginBottom:'10px' });
                                    $('<thead/>').append(
                                        $('<tr/>').append(
                                            $('<th/>').css({ textAlign:'left', padding:'5px 8px', background:'#f6f7f7', borderBottom:'2px solid #e5e5e5', color:'#555', width:'160px' }).text('Campo'),
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
                                            changed:   { rowBg:'#fffbea', bColor:'#dc3232', aColor:'#2271b1', icon:'✏️', iColor:'#e06800' }
                                        };
                                        var cfg = cfgMap[row.status] || cfgMap.unchanged;
                                        var bText = row.before && String(row.before).trim() ? row.before : '(vacío)';
                                        var aText = row.after  && String(row.after).trim()  ? row.after  : '(vacío)';
                                        var $tr = $('<tr/>').css({ background: cfg.rowBg });
                                        $('<td/>').css({ padding:'4px 8px', borderBottom:'1px solid #f3f3f3', fontWeight:'600', color:'#1d2327', whiteSpace:'nowrap' }).text(row.label).appendTo($tr);
                                        $('<td/>').css({ padding:'4px 8px', borderBottom:'1px solid #f3f3f3', color:cfg.bColor, fontFamily:'monospace', maxWidth:'220px', overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap', textDecoration: row.status === 'changed' ? 'line-through' : 'none' }).attr('title', bText).text(bText).appendTo($tr);
                                        $('<td/>').css({ padding:'4px 8px', borderBottom:'1px solid #f3f3f3', color:cfg.aColor, fontFamily:'monospace', fontWeight: row.status !== 'unchanged' ? '600' : '400', maxWidth:'220px', overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }).attr('title', aText).text(aText).appendTo($tr);
                                        $('<td/>').css({ padding:'4px 8px', borderBottom:'1px solid #f3f3f3', textAlign:'center', color:cfg.iColor, fontSize:'13px' }).text(cfg.icon).appendTo($tr);
                                        $tbody.append($tr);
                                    });
                                    $table.append($tbody);
                                    $body.append($table);
                                }

                                try {
                                    var rawStr = JSON.stringify(entry.raw, null, 2);
                                    if (rawStr && rawStr !== 'null' && rawStr !== 'undefined') {
                                        var $rawToggleBtn = $('<button type="button"/>').addClass('button-link').css({
                                            fontSize:'11px', color:'#1a73e8', padding:'0', border:'none', background:'transparent',
                                            cursor:'pointer', textDecoration:'underline', display:'block', marginBottom:'6px'
                                        }).text('📡 Ver payload / respuesta completa ▶');

                                        var $rawPre = $('<pre/>').css({
                                            display:'none', background:'#1e1e2e', color:'#cdd6f4', padding:'10px', borderRadius:'4px',
                                            fontSize:'10px', lineHeight:'1.6', maxHeight:'240px', overflowY:'auto',
                                            whiteSpace:'pre-wrap', wordBreak:'break-all', marginBottom:'8px', fontFamily:'monospace'
                                        }).text(rawStr);

                                        $rawToggleBtn.on('click', function() {
                                            $rawPre.toggle();
                                            $(this).text($rawPre.is(':visible') ? '📡 Ocultar payload / respuesta ▼' : '📡 Ver payload / respuesta completa ▶');
                                        });

                                        $body.append($rawToggleBtn).append($rawPre);
                                    }
                                } catch (err) {
                                    $body.append($('<p/>').css({ fontSize:'11px', color:'#888' }).text('(payload no serializable)'));
                                }

                                if (hasError) {
                                    var errorText = entry.raw.error || entry.raw._serializeError || entry.raw.error_message || 'Error desconocido';
                                    $body.append(
                                        $('<p/>').css({ margin:'8px 0 0', fontSize:'11px', color:'#dc3232', fontStyle:'italic', padding:'6px 8px', background:'#fff5f5', borderRadius:'3px', border:'1px solid #f5c6c6' })
                                            .text('⚠️ ' + errorText)
                                    );
                                }

                                $entry.append($entryHeader).append($body);
                                $container.append($entry);
                            });
                        } catch (err) {
                            if (window.console && window.console.error) console.error('[OY Basic Info Log] renderLog error:', err);
                        }
                    }

                    function saveMetabox() {
                        if (editorState.saving || !editorState.enabled) return;
                        editorState.saving = true;
                        updateUiState();
                        setStatus('Guardando cambios locales...', 'info');

                        var beforeState = editorState.baseline ? $.extend({}, editorState.baseline) : captureState();

                        $.post(AJAX_URL, {
                            action: 'oy_save_basic_info_metabox',
                            nonce: SAVE_NONCE,
                            post_id: POST_ID,
                            location_short_description: $('#location_short_description').val() || '',
                            opening_date: $('#opening_date').val() || '',
                            google_primary_category: $('#google_primary_category').val() || '',
                            price_range: $('#price_range').val() || ''
                        }).done(function(response) {
                            if (!response || !response.success) {
                                var msg = response && response.data && response.data.message ? response.data.message : 'No se pudo guardar el metabox.';
                                setStatus(msg, 'error');
                                addLogEntry({ action:'manual_metabox_save_error', error_message:msg, response: response && response.data ? response.data : response }, buildDiff(beforeState, captureState()), 'push_error');
                                return;
                            }

                            var afterState = captureState();
                            addLogEntry({ action:'manual_metabox_save', response: response && response.data ? response.data : response }, buildDiff(beforeState, afterState), 'manual_save');

                            editorState.baseline = afterState;
                            editorState.enabled = false;
                            editorState.dirty = false;
                            localPending = true;
                            lastManualLabel = response.data && response.data.save_meta && response.data.save_meta.at_label ? response.data.save_meta.at_label : lastManualLabel;

                            if (response.data && response.data.panel_html) replacePushPanel(response.data.panel_html);

                            updateUiState();
                            setStatus(response.data && response.data.message ? response.data.message : 'Metabox guardado localmente.', 'success');
                        }).fail(function(xhr) {
                            var msg = 'Error de red al guardar el metabox.';
                            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) msg = xhr.responseJSON.data.message;
                            setStatus(msg, 'error');
                            addLogEntry({ action:'manual_metabox_save_network_error', error_message:msg }, buildDiff(beforeState, captureState()), 'push_error');
                        }).always(function() {
                            editorState.saving = false;
                            updateUiState();
                        });
                    }

                    function bindDirtyWatchers() {
                        $(document).on('input.oyBasicInfo change.oyBasicInfo', '#location_short_description, #opening_date, #google_primary_category, #price_range', function() {
                            if (!editorState.enabled) return;
                            updateDescriptionCounter();
                            refreshDirtyState();
                        });
                    }

                    function guardCptSaveButtons() {
                        document.addEventListener('click', function(event) {
                            var saveButton = event.target.closest('#publish, #save-post');
                            if (!saveButton) return;
                            if (editorState.enabled && editorState.dirty) {
                                event.preventDefault();
                                event.stopPropagation();
                                if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
                                setStatus('Primero guarda los cambios del metabox de Información Básica antes de usar "Actualizar" o "Guardar borrador".', 'error');
                            }
                        }, true);
                    }

                    function guardExternalButtons() {
                        document.addEventListener('click', function(event) {
                            var pushButton = event.target.closest('#oy-push-basic-info-btn');
                            if (pushButton && editorState.enabled && editorState.dirty) {
                                event.preventDefault();
                                event.stopPropagation();
                                if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
                                setPushStatus('Primero guarda los cambios del metabox. "Enviar a GMB" usa únicamente lo que ya quedó guardado.', 'error');
                                setStatus('Primero guarda los cambios del metabox.', 'error');
                            }
                        }, true);
                    }

                    function bindButtons() {
                        $(document).on('click.oyBasicInfo', '#oy-basic-info-editor-start', function(event){ event.preventDefault(); beginEditMode(); });
                        $(document).on('click.oyBasicInfo', '#oy-basic-info-editor-cancel', function(event){ event.preventDefault(); cancelEditMode(); });
                        $(document).on('click.oyBasicInfo', '#oy-basic-info-editor-save', function(event){ event.preventDefault(); saveMetabox(); });

                        $(document).on('click.oyBasicInfo', '#oy-push-basic-info-btn', function(event) {
                            event.preventDefault();
                            var $panel = $('#oy-basic-info-push-panel');
                            var pushNonce = $panel.data('push-nonce');
                            var postId = $panel.data('post-id');
                            setPushStatus('Enviando Información Básica a GMB...', 'info');

                            $.post(AJAX_URL, { action:'oy_push_basic_info_to_gmb', nonce:pushNonce, post_id:postId })
                                .done(function(response) {
                                    if (!response || !response.success) {
                                        var msg = response && response.data && response.data.message ? response.data.message : 'No se pudo enviar a GMB.';
                                        setPushStatus(msg, 'error');
                                        if (response && response.data && response.data.panel_html) replacePushPanel(response.data.panel_html);
                                        addLogEntry(
                                            response && response.data && response.data.log_context && response.data.log_context.raw ? response.data.log_context.raw : { action:'push_basic_info_error', error_message:msg, response: response && response.data ? response.data : response },
                                            response && response.data && response.data.log_context ? buildDiff(response.data.log_context.before || {}, response.data.log_context.after || {}) : [],
                                            'push_error'
                                        );
                                        return;
                                    }

                                    localPending = true;
                                    if (response.data && response.data.panel_html) replacePushPanel(response.data.panel_html);
                                    if (response.data && response.data.log_context) {
                                        addLogEntry(response.data.log_context.raw || { action:'push_basic_info_to_gmb', response:response.data }, buildDiff(response.data.log_context.before || {}, response.data.log_context.after || {}), 'push_to_gmb');
                                    }
                                    setPushStatus(response.data && response.data.message ? response.data.message : 'Enviado a GMB.', 'success');
                                })
                                .fail(function(xhr) {
                                    var msg = 'Error de red al enviar a GMB.';
                                    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) msg = xhr.responseJSON.data.message;
                                    setPushStatus(msg, 'error');
                                    addLogEntry({ action:'push_basic_info_network_error', error_message:msg }, [], 'push_error');
                                });
                        });

                        $(document).on('click.oyBasicInfo', '#oy-check-basic-info-push-status-btn', function(event) {
                            event.preventDefault();
                            var $panel = $('#oy-basic-info-push-panel');
                            var checkNonce = $panel.data('check-nonce');
                            var postId = $panel.data('post-id');
                            setPushStatus('Verificando estado en Google...', 'info');

                            $.post(AJAX_URL, { action:'oy_check_basic_info_push_status', nonce:checkNonce, post_id:postId })
                                .done(function(response) {
                                    if (!response || !response.success) {
                                        var msg = response && response.data && response.data.message ? response.data.message : 'No se pudo verificar el estado.';
                                        setPushStatus(msg, 'error');
                                        if (response && response.data && response.data.panel_html) replacePushPanel(response.data.panel_html);
                                        addLogEntry(
                                            response && response.data && response.data.log_context && response.data.log_context.raw ? response.data.log_context.raw : { action:'manual_check_error', error_message:msg, response: response && response.data ? response.data : response },
                                            response && response.data && response.data.log_context ? buildDiff(response.data.log_context.before || {}, response.data.log_context.after || {}) : [],
                                            'check_error'
                                        );
                                        return;
                                    }

                                    if (response.data && response.data.panel_html) replacePushPanel(response.data.panel_html);
                                    if (response.data && response.data.log_context) {
                                        addLogEntry(response.data.log_context.raw || { action:'manual_check_basic_info_status', response:response.data }, buildDiff(response.data.log_context.before || {}, response.data.log_context.after || {}), 'manual_check');
                                    }

                                    if (response.data && response.data.status === 'applied') {
                                        localPending = false;
                                        setPushStatus('Google ya refleja los cambios enviados.', 'success');
                                    } else if (response.data && response.data.status === 'pending_review') {
                                        setPushStatus('El cambio sigue pendiente de revisión por Google.', 'warning');
                                    } else {
                                        setPushStatus('Estado actualizado: ' + (response.data && response.data.status ? response.data.status : 'sin dato'), 'info');
                                    }
                                })
                                .fail(function(xhr) {
                                    var msg = 'Error de red al verificar el estado.';
                                    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) msg = xhr.responseJSON.data.message;
                                    setPushStatus(msg, 'error');
                                    addLogEntry({ action:'manual_check_network_error', error_message:msg }, [], 'check_error');
                                });
                        });

                        $(document).on('click', '#oy-basic-info-log-header', function() {
                            var $body = $('#oy-basic-info-log-body');
                            var $icon = $('#oy-basic-info-log-toggle-icon');
                            $body.toggle();
                            $('#oy-basic-info-log-header').css('borderBottomColor', $body.is(':visible') ? '#dadce0' : 'transparent');
                            $icon.text($body.is(':visible') ? '▼' : '▶');
                        });

                        $(document).on('click', '#oy-basic-info-log-clear', function(event) {
                            event.stopPropagation();
                            if (!confirm('<?php echo esc_js( __( '¿Borrar todo el historial detallado del metabox de Información Básica?', 'lealez' ) ); ?>')) return;
                            clearLog();
                            renderLog();
                        });
                    }

                    $(document).ready(function() {
                        if (!$('#oy_location_basic_info').length) return;

                        editorState.baseline = captureState();
                        updateDescriptionCounter();
                        updateUiState();
                        renderLog();

                        if (lastManualLabel) setStatus('Último guardado local del metabox: ' + lastManualLabel, 'info');
                        else if (localPending) setStatus('Hay cambios locales pendientes por publicar en GMB.', 'info');

                        bindDirtyWatchers();
                        bindButtons();
                        guardCptSaveButtons();
                        guardExternalButtons();
                    });
                })(jQuery);
            </script>
            <?php
        }
    }
}
