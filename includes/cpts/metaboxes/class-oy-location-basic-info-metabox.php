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
        
        /**
         * Constructor.
         */
        public function __construct() {
            add_action( 'add_meta_boxes_oy_location', array( $this, 'register_metabox' ), 11, 1 );

            // Guardado clásico al actualizar el post (compatibilidad con flujo existente)
            add_action( 'save_post_oy_location', array( $this, 'save_meta_box' ), 21, 2 );

            // Guardado independiente del metabox
            add_action( 'wp_ajax_oy_save_basic_info_metabox', array( $this, 'ajax_save_basic_info_metabox' ) );

            // Autocomplete dinámico de categorías GBP
            add_action( 'wp_ajax_oy_search_gmb_categories', array( $this, 'ajax_search_gmb_categories' ) );

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

            $google_primary_category_name = isset( $_POST['google_primary_category_name'] )
                ? $this->normalize_google_category_name( wp_unslash( $_POST['google_primary_category_name'] ) )
                : '';

            if ( '' === $google_primary_category ) {
                $google_primary_category_name = '';
            }

            $google_additional_categories = isset( $_POST['google_additional_categories_json'] )
                ? $this->normalize_google_additional_categories( wp_unslash( $_POST['google_additional_categories_json'] ) )
                : array();

            $price_range = isset( $_POST['price_range'] )
                ? sanitize_text_field( wp_unslash( $_POST['price_range'] ) )
                : '';

            if ( ! in_array( $price_range, array( '', '1', '2', '3', '4' ), true ) ) {
                $price_range = '';
            }

            return array(
                'location_short_description'   => $description,
                'opening_date'                 => $opening_date,
                'google_primary_category'      => $google_primary_category,
                'google_primary_category_name' => $google_primary_category_name,
                'google_additional_categories' => $google_additional_categories,
                'price_range'                  => $price_range,
            );
        }

        /**
         * Normaliza el resource name de una categoría de GBP.
         *
         * @param mixed $value
         * @return string
         */
        private function normalize_google_category_name( $value ) {
            $value = trim( sanitize_text_field( (string) $value ) );

            if ( '' === $value ) {
                return '';
            }

            if ( 0 !== strpos( $value, 'categories/' ) ) {
                return '';
            }

            return $value;
        }

        /**
         * Normaliza una fila de categoría para uso local del metabox.
         *
         * @param mixed $item
         * @return array|null
         */
        private function normalize_google_category_item( $item ) {
            $display_name  = '';
            $resource_name = '';

            if ( is_string( $item ) ) {
                $display_name = sanitize_text_field( trim( $item ) );
            } elseif ( is_array( $item ) ) {
                $display_name = isset( $item['displayName'] )
                    ? sanitize_text_field( trim( (string) $item['displayName'] ) )
                    : '';

                if ( '' === $display_name && isset( $item['label'] ) ) {
                    $display_name = sanitize_text_field( trim( (string) $item['label'] ) );
                }

                if ( '' === $display_name && isset( $item['text'] ) ) {
                    $display_name = sanitize_text_field( trim( (string) $item['text'] ) );
                }

                if ( '' === $display_name && isset( $item['name'] ) ) {
                    $display_name = sanitize_text_field( trim( (string) $item['name'] ) );
                }

                if ( isset( $item['name'] ) ) {
                    $resource_name = $this->normalize_google_category_name( $item['name'] );
                }
            }

            if ( '' === $display_name && '' === $resource_name ) {
                return null;
            }

            if ( '' === $display_name && '' !== $resource_name ) {
                $display_name = $resource_name;
            }

            return array(
                'displayName' => $display_name,
                'name'        => $resource_name,
            );
        }

        /**
         * Normaliza el listado de categorías adicionales guardadas localmente.
         *
         * @param mixed $raw_value
         * @return array
         */
        private function normalize_google_additional_categories( $raw_value ) {
            if ( is_string( $raw_value ) ) {
                $decoded   = json_decode( trim( $raw_value ), true );
                $raw_value = is_array( $decoded ) ? $decoded : array();
            }

            if ( ! is_array( $raw_value ) ) {
                return array();
            }

            $normalized = array();
            $seen       = array();

            foreach ( $raw_value as $item ) {
                $category = $this->normalize_google_category_item( $item );
                if ( ! is_array( $category ) ) {
                    continue;
                }

                $display_name  = (string) $category['displayName'];
                $resource_name = (string) $category['name'];
                $dedupe_key    = '' !== $resource_name
                    ? 'name:' . strtolower( $resource_name )
                    : 'display:' . strtolower( $display_name );

                if ( isset( $seen[ $dedupe_key ] ) ) {
                    continue;
                }
                $seen[ $dedupe_key ] = true;

                $normalized[] = $category;

                if ( count( $normalized ) >= 20 ) {
                    break;
                }
            }

            return array_values( $normalized );
        }

        /**
         * Devuelve las categorías adicionales efectivas del editor.
         *
         * @param int $post_id
         * @return array
         */
        private function get_effective_google_additional_categories( $post_id ) {
            $post_id     = absint( $post_id );
            $initialized = (bool) get_post_meta( $post_id, 'google_additional_categories_initialized', true );
            $local_items = $this->normalize_google_additional_categories( get_post_meta( $post_id, 'google_additional_categories', true ) );

            if ( $initialized || ! empty( $local_items ) ) {
                return $local_items;
            }

            return $this->normalize_google_additional_categories( get_post_meta( $post_id, 'gmb_additional_categories', true ) );
        }

        /**
         * Convierte un listado de categorías a una cadena legible para logs/UI.
         *
         * @param mixed $categories
         * @return string
         */
        private function format_google_categories_for_display( $categories ) {
            $categories = $this->normalize_google_additional_categories( $categories );

            if ( empty( $categories ) ) {
                return '';
            }

            $labels = array();

            foreach ( $categories as $category ) {
                $label = isset( $category['displayName'] ) ? trim( (string) $category['displayName'] ) : '';
                $name  = isset( $category['name'] ) ? trim( (string) $category['name'] ) : '';

                if ( '' === $label && '' !== $name ) {
                    $label = $name;
                }

                if ( '' === $label ) {
                    continue;
                }

                if ( '' === $name ) {
                    $label .= ' (sin vincular)';
                }

                $labels[] = $label;
            }

            return implode( ' | ', $labels );
        }

        /**
         * Devuelve una colección comparable de keys de categorías adicionales.
         *
         * @param mixed $categories
         * @return array
         */
        private static function get_additional_category_comparison_keys( $categories ) {
            if ( is_string( $categories ) ) {
                $decoded    = json_decode( trim( $categories ), true );
                $categories = is_array( $decoded ) ? $decoded : array();
            }

            if ( ! is_array( $categories ) ) {
                return array();
            }

            $items = array();

            foreach ( $categories as $category ) {
                if ( ! is_array( $category ) ) {
                    continue;
                }

                $name         = isset( $category['name'] ) ? trim( sanitize_text_field( (string) $category['name'] ) ) : '';
                $display_name = isset( $category['displayName'] ) ? trim( sanitize_text_field( (string) $category['displayName'] ) ) : '';

                $key = '' !== $name ? 'name:' . strtolower( $name ) : '';
                if ( '' === $key && '' !== $display_name ) {
                    $key = 'display:' . strtolower( $display_name );
                }

                if ( '' === $key ) {
                    continue;
                }

                $items[] = $key;
            }

            $items = array_values( array_unique( $items ) );
            sort( $items );

            return $items;
        }

        /**
         * Determina el contexto de país/idioma para categories.list.
         *
         * @param int $post_id
         * @return array
         */
        private function get_basic_info_category_context( $post_id ) {
            $post_id = absint( $post_id );

            $region_code = '';
            $language_code = '';

            $raw_country = (string) get_post_meta( $post_id, 'location_country', true );
            if ( '' !== $raw_country ) {
                $region_code = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $raw_country ), 0, 2 ) );
            }

            if ( '' === $region_code ) {
                $region_code = 'CO';
            }

            $raw_language = (string) get_post_meta( $post_id, 'gmb_language_code', true );
            if ( '' === $raw_language ) {
                if ( function_exists( 'determine_locale' ) ) {
                    $raw_language = (string) determine_locale();
                } else {
                    $raw_language = (string) get_locale();
                }
            }

            $raw_language = str_replace( '_', '-', trim( $raw_language ) );
            if ( ! preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $raw_language ) ) {
                $raw_language = strtolower( substr( preg_replace( '/[^A-Za-z]/', '', $raw_language ), 0, 2 ) );
            }

            if ( '' === $raw_language ) {
                $raw_language = 'es';
            }

            $language_code = $raw_language;

            return array(
                'regionCode'   => $region_code,
                'languageCode' => $language_code,
            );
        }

        /**
         * AJAX: busca categorías oficiales de Google Business Profile.
         *
         * @return void
         */
        public function ajax_search_gmb_categories() {
            $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
            $term    = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

            if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_search_gmb_categories_' . $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Nonce inválido para buscar categorías.', 'lealez' ) ) );
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'No tienes permisos para buscar categorías en esta ubicación.', 'lealez' ) ) );
            }

            $post = get_post( $post_id );
            if ( ! $post || 'oy_location' !== $post->post_type ) {
                wp_send_json_error( array( 'message' => __( 'Post no válido para búsqueda de categorías.', 'lealez' ) ) );
            }

            if ( function_exists( 'mb_strlen' ) ) {
                $term_length = mb_strlen( $term );
            } else {
                $term_length = strlen( $term );
            }

            if ( $term_length < 2 ) {
                wp_send_json_success( array(
                    'items'   => array(),
                    'context' => $this->get_basic_info_category_context( $post_id ),
                ) );
            }

            $business_id = (int) get_post_meta( $post_id, 'parent_business_id', true );
            if ( ! $business_id ) {
                wp_send_json_error( array( 'message' => __( 'Esta ubicación no tiene negocio padre asignado.', 'lealez' ) ) );
            }

            if ( ! class_exists( 'Lealez_GMB_API' ) ) {
                wp_send_json_error( array( 'message' => __( 'La clase Lealez_GMB_API no está disponible.', 'lealez' ) ) );
            }

            $context = $this->get_basic_info_category_context( $post_id );

            $result = Lealez_GMB_API::search_business_categories(
                $business_id,
                $term,
                $context['regionCode'],
                $context['languageCode'],
                20
            );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array(
                    'message' => sprintf(
                        __( 'No se pudieron cargar las categorías de Google: %s', 'lealez' ),
                        $result->get_error_message()
                    ),
                ) );
            }

            wp_send_json_success( array(
                'items'   => isset( $result['categories'] ) && is_array( $result['categories'] ) ? array_values( $result['categories'] ) : array(),
                'context' => array(
                    'regionCode'   => (string) ( $result['regionCode'] ?? $context['regionCode'] ),
                    'languageCode' => (string) ( $result['languageCode'] ?? $context['languageCode'] ),
                ),
            ) );
        }


        /**
         * Persiste el payload local del metabox.
         *
         * @param int    $post_id
         * @param array  $payload
         * @param string $save_source
         * @return array
         */
        
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

            $additional_categories = $this->normalize_google_additional_categories(
                isset( $payload['google_additional_categories'] ) ? $payload['google_additional_categories'] : array()
            );

            update_post_meta( $post_id, 'location_short_description',   (string) ( $payload['location_short_description'] ?? '' ) );
            update_post_meta( $post_id, 'opening_date',                 (string) ( $payload['opening_date'] ?? '' ) );
            update_post_meta( $post_id, 'google_primary_category',      (string) ( $payload['google_primary_category'] ?? '' ) );
            update_post_meta( $post_id, 'google_primary_category_name', (string) ( $payload['google_primary_category_name'] ?? '' ) );
            update_post_meta( $post_id, 'google_additional_categories', array_values( $additional_categories ) );
            update_post_meta( $post_id, 'google_additional_categories_initialized', '1' );
            update_post_meta( $post_id, 'price_range',                  (string) ( $payload['price_range'] ?? '' ) );

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
         * Renderiza una fila editable de categoría adicional.
         *
         * @param array $category
         * @param int   $index
         * @return string
         */
        private function render_basic_info_additional_category_row( array $category, $index ) {
            $display_name  = isset( $category['displayName'] ) ? (string) $category['displayName'] : '';
            $resource_name = isset( $category['name'] ) ? (string) $category['name'] : '';

            ob_start();
            ?>
            <div class="oy-basic-additional-category-row" data-row-index="<?php echo esc_attr( (int) $index ); ?>" style="position:relative; display:flex; gap:8px; align-items:flex-start; margin-bottom:8px;">
                <div style="flex:1; position:relative; min-width:0;">
                    <input
                        type="text"
                        class="regular-text oy-basic-additional-category-label"
                        value="<?php echo esc_attr( $display_name ); ?>"
                        data-oy-basic-field="1"
                        readonly="readonly"
                        autocomplete="off"
                        placeholder="<?php esc_attr_e( 'Escribe para buscar una categoría oficial de Google', 'lealez' ); ?>"
                        style="width:100%;"
                    >
                    <input
                        type="hidden"
                        class="oy-basic-additional-category-name"
                        value="<?php echo esc_attr( $resource_name ); ?>"
                    >
                    <div class="oy-basic-additional-category-suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1000; background:#fff; border:1px solid #c3c4c7; border-top:none; box-shadow:0 8px 18px rgba(0,0,0,.08); max-height:260px; overflow:auto;"></div>
                </div>
                <button
                    type="button"
                    class="button-link-delete oy-basic-additional-category-remove"
                    data-oy-basic-field="1"
                    disabled="disabled"
                    style="padding-top:6px; white-space:nowrap; color:#b32d2e; text-decoration:none;"
                >
                    <?php _e( 'Quitar', 'lealez' ); ?>
                </button>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /**
         * Render del metabox.
         *
         * @param WP_Post $post
         * @return void
         */
        
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
            $manual_primary_cat_name    = (string) get_post_meta( $post->ID, 'google_primary_category_name', true );
            $price_range                = (string) get_post_meta( $post->ID, 'price_range', true );

            $gmb_primary_category_name         = (string) get_post_meta( $post->ID, 'gmb_primary_category_name', true );
            $gmb_primary_category_display_name = (string) get_post_meta( $post->ID, 'gmb_primary_category_display_name', true );
            $gmb_additional_categories         = $this->normalize_google_additional_categories( get_post_meta( $post->ID, 'gmb_additional_categories', true ) );
            $editor_additional_categories      = $this->get_effective_google_additional_categories( $post->ID );
            $editor_additional_categories_json = wp_json_encode( $editor_additional_categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            $gmb_additional_categories_text    = $this->format_google_categories_for_display( $gmb_additional_categories );

            if ( '' === $manual_primary_cat_name && '' !== $gmb_primary_category_name ) {
                $manual_primary_cat_name = $gmb_primary_category_name;
            }

            if ( '' === $google_primary_category && '' !== $gmb_primary_category_display_name ) {
                $google_primary_category = $gmb_primary_category_display_name;
            }

            $gmb_open_info_raw = get_post_meta( $post->ID, 'gmb_open_info_raw', true );
            $gmb_opening_date  = '';
            if ( is_array( $gmb_open_info_raw ) && ! empty( $gmb_open_info_raw['openingDate'] ) ) {
                $gmb_opening_date = self::normalize_opening_date_for_compare( $gmb_open_info_raw['openingDate'] );
            }

            $category_context = $this->get_basic_info_category_context( $post->ID );

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
                        <li><?php _e( 'Categoría Principal', 'lealez' ); ?></li>
                        <li><?php _e( 'Categorías Adicionales', 'lealez' ); ?></li>
                    </ul>
                    <p style="margin:8px 0 0;">
                        <?php _e( 'La categoría principal y las categorías adicionales usan autocomplete dinámico con las categorías oficiales de Google Business Profile. Para publicar en Google, cada categoría debe escogerse desde una sugerencia válida del predictivo. El Rango de Precios se sigue guardando solo localmente.', 'lealez' ); ?>
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
                            <label for="google_primary_category"><?php _e( 'Categoría Principal', 'lealez' ); ?></label>
                        </th>
                        <td>
                            <div style="position:relative; max-width:720px;">
                                <input
                                    type="text"
                                    name="google_primary_category"
                                    id="google_primary_category"
                                    value="<?php echo esc_attr( $google_primary_category ); ?>"
                                    class="regular-text"
                                    data-oy-basic-field="1"
                                    readonly="readonly"
                                    autocomplete="off"
                                    placeholder="<?php esc_attr_e( 'Escribe para buscar categorías oficiales de Google', 'lealez' ); ?>"
                                    style="width:100%; max-width:700px;"
                                >
                                <input
                                    type="hidden"
                                    name="google_primary_category_name"
                                    id="google_primary_category_name"
                                    value="<?php echo esc_attr( $manual_primary_cat_name ); ?>"
                                >
                                <div
                                    id="oy-basic-category-suggestions"
                                    style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1000; background:#fff; border:1px solid #c3c4c7; border-top:none; box-shadow:0 8px 18px rgba(0,0,0,.08); max-height:280px; overflow:auto;"
                                ></div>
                            </div>

                            <p class="description">
                                <?php
                                printf(
                                    esc_html__( 'Autocomplete oficial vía Business Information API categories.list. Contexto usado para la búsqueda: país %1$s / idioma %2$s. Para publicar en GMB debes elegir una opción del predictivo.', 'lealez' ),
                                    esc_html( $category_context['regionCode'] ),
                                    esc_html( $category_context['languageCode'] )
                                );
                                ?>
                            </p>

                            <div id="oy-basic-category-selected-meta" style="margin-top:8px;">
                                <?php if ( '' !== $google_primary_category && '' !== $manual_primary_cat_name ) : ?>
                                    <div style="padding:10px 12px; background:#edfaef; border:1px solid #b8dcbf;">
                                        <div><strong><?php _e( 'Categoría lista para publicar en GMB:', 'lealez' ); ?></strong> <?php echo esc_html( $google_primary_category ); ?></div>
                                        <div style="margin-top:4px;"><code><?php echo esc_html( $manual_primary_cat_name ); ?></code></div>
                                    </div>
                                <?php elseif ( '' !== $google_primary_category ) : ?>
                                    <div style="padding:10px 12px; background:#fff8e5; border:1px solid #ecd58d;">
                                        <?php _e( 'Hay un texto local en Categoría Principal, pero aún no está vinculado a una categoría oficial de Google. Selecciona una sugerencia del predictivo para que pueda publicarse en GMB.', 'lealez' ); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

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
                            <label for="oy-basic-additional-categories-list"><?php _e( 'Categorías Adicionales', 'lealez' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="hidden"
                                name="google_additional_categories_json"
                                id="google_additional_categories_json"
                                value="<?php echo esc_attr( $editor_additional_categories_json ); ?>"
                            >

                            <div id="oy-basic-additional-categories-list" data-initial-json="<?php echo esc_attr( $editor_additional_categories_json ); ?>" style="max-width:720px;">
                                <?php
                                if ( ! empty( $editor_additional_categories ) ) {
                                    foreach ( array_values( $editor_additional_categories ) as $index => $category_item ) {
                                        echo $this->render_basic_info_additional_category_row( $category_item, $index );
                                    }
                                }
                                ?>
                            </div>

                            <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                <button
                                    type="button"
                                    id="oy-basic-additional-category-add"
                                    class="button"
                                    data-oy-basic-field="1"
                                    disabled="disabled"
                                >
                                    <?php _e( 'Agregar categoría adicional', 'lealez' ); ?>
                                </button>
                                <span style="font-size:12px; color:#666;">
                                    <?php _e( 'Cada categoría adicional debe escogerse desde el predictivo oficial de Google.', 'lealez' ); ?>
                                </span>
                            </div>

                            <p class="description" style="margin-top:8px;">
                                <?php _e( 'Primero define la Categoría Principal y luego agrega las categorías adicionales que también representen al negocio. Si una fila tiene texto pero no resource name oficial, no podrá publicarse en GMB.', 'lealez' ); ?>
                            </p>

                            <div id="oy-basic-additional-categories-selected-meta" style="margin-top:8px;"></div>

                            <?php if ( '' !== $gmb_additional_categories_text ) : ?>
                                <div style="margin-top:8px; padding:10px 12px; background:#f6f7f7; border:1px solid #dcdcde;">
                                    <div><strong><?php _e( 'Categorías adicionales actuales en GMB:', 'lealez' ); ?></strong></div>
                                    <div style="margin-top:6px;"><?php echo esc_html( $gmb_additional_categories_text ); ?></div>
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
                'location_short_description'   => (string) get_post_meta( $post_id, 'location_short_description', true ),
                'opening_date'                 => (string) get_post_meta( $post_id, 'opening_date', true ),
                'google_primary_category'      => (string) get_post_meta( $post_id, 'google_primary_category', true ),
                'google_primary_category_name' => (string) get_post_meta( $post_id, 'google_primary_category_name', true ),
                'google_additional_categories' => $this->get_effective_google_additional_categories( $post_id ),
                'price_range'                  => (string) get_post_meta( $post_id, 'price_range', true ),
            );
        }

        /**
         * Construye un snapshot comparable para el log visual usando un snapshot GMB.
         *
         * @param int   $post_id
         * @param array $snapshot
         * @return array
         */
        
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

            if ( isset( $snapshot['categories']['primaryCategory'] ) && is_array( $snapshot['categories']['primaryCategory'] ) ) {
                $primary_category = $snapshot['categories']['primaryCategory'];
                $local['google_primary_category'] = isset( $primary_category['displayName'] ) && '' !== (string) $primary_category['displayName']
                    ? (string) $primary_category['displayName']
                    : (string) ( $primary_category['name'] ?? '' );
                $local['google_primary_category_name'] = (string) ( $primary_category['name'] ?? '' );
            } elseif ( array_key_exists( 'categories', $snapshot ) ) {
                $local['google_primary_category'] = '';
                $local['google_primary_category_name'] = '';
            }

            if ( isset( $snapshot['categories']['additionalCategories'] ) ) {
                $local['google_additional_categories'] = $this->normalize_google_additional_categories( $snapshot['categories']['additionalCategories'] );
            } elseif ( isset( $snapshot['categories'] ) && is_array( $snapshot['categories'] ) ) {
                $local['google_additional_categories'] = array();
            }

            return $local;
        }

        /**
         * Construye un snapshot comparable para el log visual usando el payload enviado a GMB.
         *
         * @param int   $post_id
         * @param array $submitted
         * @return array
         */
        
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

            if ( isset( $submitted['categories']['primaryCategory'] ) && is_array( $submitted['categories']['primaryCategory'] ) ) {
                $primary_category = $submitted['categories']['primaryCategory'];
                $local['google_primary_category'] = isset( $primary_category['displayName'] ) && '' !== (string) $primary_category['displayName']
                    ? (string) $primary_category['displayName']
                    : (string) ( $primary_category['name'] ?? '' );
                $local['google_primary_category_name'] = (string) ( $primary_category['name'] ?? '' );
            }

            if ( isset( $submitted['categories']['additionalCategories'] ) ) {
                $local['google_additional_categories'] = $this->normalize_google_additional_categories( $submitted['categories']['additionalCategories'] );
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

            $submitted_cat_name = trim( (string) ( $job['submitted']['categories']['primaryCategory']['name'] ?? '' ) );
            $before_cat_name    = trim( (string) ( $job['snapshot_before']['categories']['primaryCategory']['name'] ?? '' ) );
            $current_cat_name   = trim( (string) ( $current['categories']['primaryCategory']['name'] ?? '' ) );

            $submitted_cat_text = trim( (string) ( $job['submitted']['categories']['primaryCategory']['displayName'] ?? '' ) );
            $before_cat_text    = trim( (string) ( $job['snapshot_before']['categories']['primaryCategory']['displayName'] ?? '' ) );
            $current_cat_text   = trim( (string) ( $current['categories']['primaryCategory']['displayName'] ?? '' ) );

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

            if ( isset( $job['submitted']['categories'] ) && is_array( $job['submitted']['categories'] ) ) {
                $submitted_additional = self::get_additional_category_comparison_keys( $job['submitted']['categories']['additionalCategories'] ?? array() );
                $before_additional    = self::get_additional_category_comparison_keys( $job['snapshot_before']['categories']['additionalCategories'] ?? array() );
                $current_additional   = self::get_additional_category_comparison_keys( $current['categories']['additionalCategories'] ?? array() );

                $submitted_category_applied = true;
                $before_category_same       = true;

                if ( '' !== $submitted_cat_name ) {
                    $submitted_category_applied = $submitted_category_applied && ( $current_cat_name === $submitted_cat_name );
                    $before_category_same       = $before_category_same && ( $current_cat_name === $before_cat_name );
                } elseif ( '' !== $submitted_cat_text ) {
                    $submitted_category_applied = $submitted_category_applied && ( $current_cat_text === $submitted_cat_text );
                    $before_category_same       = $before_category_same && ( $current_cat_text === $before_cat_text );
                }

                $submitted_category_applied = $submitted_category_applied && ( $current_additional === $submitted_additional );
                $before_category_same       = $before_category_same && ( $current_additional === $before_additional );

                $submitted_checks[] = $submitted_category_applied;
                $before_checks[]    = $before_category_same;
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

            if ( '' === $current_desc && '' === $current_date && '' === $current_cat_name && '' === $current_cat_text && empty( self::get_additional_category_comparison_keys( $current['categories']['additionalCategories'] ?? array() ) ) ) {
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

            $description                = (string) get_post_meta( $post_id, 'location_short_description', true );
            $opening_date               = (string) get_post_meta( $post_id, 'opening_date', true );
            $primary_category_display   = (string) get_post_meta( $post_id, 'google_primary_category', true );
            $primary_category_name      = $this->normalize_google_category_name( get_post_meta( $post_id, 'google_primary_category_name', true ) );
            $fallback_gmb_category_name = $this->normalize_google_category_name( get_post_meta( $post_id, 'gmb_primary_category_name', true ) );
            $additional_initialized     = (bool) get_post_meta( $post_id, 'google_additional_categories_initialized', true );
            $local_additional           = $this->normalize_google_additional_categories( get_post_meta( $post_id, 'google_additional_categories', true ) );
            $gmb_additional             = $this->normalize_google_additional_categories( get_post_meta( $post_id, 'gmb_additional_categories', true ) );
            $effective_additional       = ( $additional_initialized || ! empty( $local_additional ) ) ? $local_additional : $gmb_additional;

            if ( '' === $primary_category_name && '' !== $primary_category_display && $primary_category_display === (string) get_post_meta( $post_id, 'gmb_primary_category_display_name', true ) ) {
                $primary_category_name = $fallback_gmb_category_name;
            }

            $payload = array(
                'location_short_description'   => $description,
                'opening_date'                 => $opening_date,
                'google_primary_category'      => $primary_category_display,
                'google_primary_category_name' => $primary_category_name,
                'google_additional_categories' => $effective_additional,
            );

            if ( '' !== trim( $primary_category_display ) && '' === trim( $primary_category_name ) ) {
                wp_send_json_error( array(
                    'message'    => __( 'La Categoría Principal tiene texto local, pero no está vinculada a una categoría oficial de Google. Selecciona una sugerencia del predictivo y vuelve a guardar el metabox antes de publicar.', 'lealez' ),
                    'panel_html' => $this->render_basic_info_push_panel( $post_id ),
                    'log_context' => array(
                        'before' => $this->get_basic_info_local_snapshot( $post_id ),
                        'after'  => $this->get_basic_info_local_snapshot( $post_id ),
                        'raw'    => array(
                            'action'                  => 'push_basic_info_invalid_category_selection',
                            'google_primary_category' => $primary_category_display,
                        ),
                    ),
                ) );
            }

            foreach ( $effective_additional as $category_item ) {
                $category_text = isset( $category_item['displayName'] ) ? trim( (string) $category_item['displayName'] ) : '';
                $category_name = isset( $category_item['name'] ) ? trim( (string) $category_item['name'] ) : '';
                if ( '' !== $category_text && '' === $category_name ) {
                    wp_send_json_error( array(
                        'message'    => __( 'Hay una Categoría Adicional con texto local pero sin selección oficial de Google. Selecciona una sugerencia válida del predictivo en cada fila antes de publicar.', 'lealez' ),
                        'panel_html' => $this->render_basic_info_push_panel( $post_id ),
                        'log_context' => array(
                            'before' => $this->get_basic_info_local_snapshot( $post_id ),
                            'after'  => $this->get_basic_info_local_snapshot( $post_id ),
                            'raw'    => array(
                                'action'                       => 'push_basic_info_invalid_additional_category_selection',
                                'google_additional_categories' => $effective_additional,
                            ),
                        ),
                    ) );
                }
            }

            if ( '' === trim( $description ) && '' === trim( $opening_date ) && '' === trim( $primary_category_name ) && empty( $effective_additional ) ) {
                wp_send_json_error( array( 'message' => __( 'No hay Descripción, Fecha de Apertura ni categorías válidas para enviar a GMB.', 'lealez' ) ) );
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

            $payload['current_categories'] = isset( $snapshot['categories'] ) && is_array( $snapshot['categories'] )
                ? $snapshot['categories']
                : get_post_meta( $post_id, 'gmb_categories_raw', true );

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
                        'action'            => 'push_basic_info_error',
                        'error_message'     => $result->get_error_message(),
                        'field_violations'  => $violations,
                        'error_data'        => is_array( $err_data ) ? $err_data : array(),
                        'submitted_payload' => $payload,
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
                    'profile'    => isset( $result['submitted']['profile'] ) && is_array( $result['submitted']['profile'] ) ? $result['submitted']['profile'] : array(),
                    'openInfo'   => isset( $result['submitted']['openInfo'] ) && is_array( $result['submitted']['openInfo'] ) ? $result['submitted']['openInfo'] : array(),
                    'categories' => isset( $result['submitted']['categories'] ) && is_array( $result['submitted']['categories'] ) ? $result['submitted']['categories'] : array(),
                ),
                'snapshot_before' => array(
                    'profile'    => isset( $snapshot['profile'] ) && is_array( $snapshot['profile'] ) ? $snapshot['profile'] : array(),
                    'openInfo'   => isset( $snapshot['openInfo'] ) && is_array( $snapshot['openInfo'] ) ? $snapshot['openInfo'] : array(),
                    'categories' => isset( $snapshot['categories'] ) && is_array( $snapshot['categories'] ) ? $snapshot['categories'] : array(),
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
                'message'     => __( 'Cambio enviado a Google Business Profile. Estado: pendiente de revisión por Google. El sistema lo verificará automáticamente.', 'lealez' ),
                'panel_html'  => $this->render_basic_info_push_panel( $post_id ),
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

            $snapshot_before = array(
                'profile'    => isset( $job['snapshot_before']['profile'] ) && is_array( $job['snapshot_before']['profile'] ) ? $job['snapshot_before']['profile'] : array(),
                'openInfo'   => isset( $job['snapshot_before']['openInfo'] ) && is_array( $job['snapshot_before']['openInfo'] ) ? $job['snapshot_before']['openInfo'] : array(),
                'categories' => isset( $job['snapshot_before']['categories'] ) && is_array( $job['snapshot_before']['categories'] ) ? $job['snapshot_before']['categories'] : array(),
            );

            if ( in_array( $job['status'] ?? '', array( 'applied', 'rejected', 'google_override', 'timeout', 'cancelled' ), true ) ) {
                wp_send_json_success( array(
                    'message'     => __( 'El cambio ya está resuelto.', 'lealez' ),
                    'status'      => $job['status'],
                    'panel_html'  => $this->render_basic_info_push_panel( $post_id ),
                    'log_context' => array(
                        'before' => $this->build_basic_info_log_snapshot_from_gmb_snapshot( $post_id, $snapshot_before ),
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
                    'message'     => __( 'Error al consultar GMB: ', 'lealez' ) . $current->get_error_message(),
                    'panel_html'  => $this->render_basic_info_push_panel( $post_id ),
                    'log_context' => array(
                        'before' => $this->build_basic_info_log_snapshot_from_gmb_snapshot( $post_id, $snapshot_before ),
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
                'message'     => '',
                'status'      => $new_status,
                'panel_html'  => $this->render_basic_info_push_panel( $post_id ),
                'log_context' => array(
                    'before' => $this->build_basic_info_log_snapshot_from_gmb_snapshot( $post_id, $snapshot_before ),
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
                    var SEARCH_NONCE = <?php echo wp_json_encode( wp_create_nonce( 'oy_search_gmb_categories_' . $post_id ) ); ?>;
                    var POST_ID = <?php echo (int) $post_id; ?>;
                    var AJAX_URL = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
                    var lastManualLabel = <?php echo wp_json_encode( $last_manual_label ); ?>;
                    var localPending = <?php echo $local_pending ? 'true' : 'false'; ?>;
                    var LS_KEY = 'oy_basic_info_log_' + POST_ID;
                    var MAX_LOG = 20;
                    var categorySearchTimer = null;
                    var categorySearchXhr = null;

                    var FIELD_MAP = [
                        { key: 'location_short_description', label: 'Descripción (GMB)' },
                        { key: 'opening_date', label: 'Fecha de Apertura' },
                        { key: 'google_primary_category', label: 'Categoría Principal' },
                        { key: 'google_additional_categories', label: 'Categorías Adicionales' },
                        { key: 'price_range', label: 'Rango de Precios' }
                    ];

                    function escapeHtml(value) {
                        return $('<div/>').text(value == null ? '' : String(value)).html();
                    }

                    function cloneValue(value) {
                        return JSON.parse(JSON.stringify(value == null ? null : value));
                    }

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

                    function parseJsonSafe(value, fallback) {
                        if (!value) { return cloneValue(fallback); }
                        try {
                            var parsed = JSON.parse(value);
                            return Array.isArray(parsed) ? parsed : cloneValue(fallback);
                        } catch (err) {
                            return cloneValue(fallback);
                        }
                    }

                    function getCategoryDisplayValue() {
                        return $('#google_primary_category').val() || '';
                    }

                    function getCategoryResourceName() {
                        return $('#google_primary_category_name').val() || '';
                    }

                    function normalizeCategoryItem(item) {
                        var out = { displayName: '', name: '' };
                        if (!item) { return out; }
                        if (typeof item === 'string') {
                            out.displayName = item;
                            return out;
                        }
                        if (typeof item === 'object') {
                            out.displayName = item.displayName || item.label || item.text || item.name || '';
                            out.name = item.name || '';
                        }
                        return out;
                    }

                    function formatCategoriesForDiff(value) {
                        var items = [];
                        if (Array.isArray(value)) {
                            items = value;
                        } else if (typeof value === 'string' && value.trim()) {
                            items = parseJsonSafe(value, []);
                        }
                        if (!Array.isArray(items) || !items.length) { return ''; }
                        return items.map(function(item) {
                            var normalized = normalizeCategoryItem(item);
                            if (!normalized.displayName && normalized.name) {
                                normalized.displayName = normalized.name;
                            }
                            if (!normalized.displayName) { return ''; }
                            return normalized.displayName + (normalized.name ? '' : ' (sin vincular)');
                        }).filter(Boolean).join(' | ');
                    }

                    function valueToText(fieldKey, value) {
                        if (fieldKey === 'price_range') return formatPriceRange(value);
                        if (fieldKey === 'google_additional_categories') return formatCategoriesForDiff(value);
                        return String(value == null ? '' : value);
                    }

                    function hideCategorySuggestions() {
                        $('#oy-basic-category-suggestions').hide().empty();
                    }

                    function hideAdditionalCategorySuggestions($row) {
                        if ($row && $row.length) {
                            $row.find('.oy-basic-additional-category-suggestions').hide().empty();
                            return;
                        }
                        $('.oy-basic-additional-category-suggestions').hide().empty();
                    }

                    function renderCategorySelectionState() {
                        var text = getCategoryDisplayValue();
                        var name = getCategoryResourceName();
                        var $box = $('#oy-basic-category-selected-meta');
                        if (!$box.length) { return; }

                        if (!text) {
                            $box.empty();
                            return;
                        }

                        if (name) {
                            $box.html(
                                '<div style="padding:10px 12px; background:#edfaef; border:1px solid #b8dcbf;">' +
                                    '<div><strong>Categoría lista para publicar en GMB:</strong> ' + escapeHtml(text) + '</div>' +
                                    '<div style="margin-top:4px;"><code>' + escapeHtml(name) + '</code></div>' +
                                '</div>'
                            );
                            return;
                        }

                        $box.html(
                            '<div style="padding:10px 12px; background:#fff8e5; border:1px solid #ecd58d;">' +
                                'Hay un texto local en Categoría Principal, pero aún no está vinculado a una categoría oficial de Google. Selecciona una sugerencia del predictivo para que pueda publicarse en GMB.' +
                            '</div>'
                        );
                    }

                    function clearSelectedCategoryIfTextChanged() {
                        var $input = $('#google_primary_category');
                        var currentText = getCategoryDisplayValue();
                        var selectedLabel = $input.data('selectedLabel') || '';

                        if (!currentText) {
                            $('#google_primary_category_name').val('');
                            $input.data('selectedLabel', '');
                            renderCategorySelectionState();
                            return;
                        }

                        if (selectedLabel && currentText !== selectedLabel) {
                            $('#google_primary_category_name').val('');
                            $input.data('selectedLabel', '');
                        }

                        renderCategorySelectionState();
                    }

                    function syncAdditionalCategoriesInputFromDom() {
                        var items = [];
                        $('#oy-basic-additional-categories-list .oy-basic-additional-category-row').each(function() {
                            var $row = $(this);
                            var label = $.trim($row.find('.oy-basic-additional-category-label').val() || '');
                            var name = $.trim($row.find('.oy-basic-additional-category-name').val() || '');
                            if (!label && !name) { return; }
                            items.push({ displayName: label || name, name: name || '' });
                        });
                        $('#google_additional_categories_json').val(JSON.stringify(items));
                        return items;
                    }

                    function getAdditionalCategoriesFromDom() {
                        return syncAdditionalCategoriesInputFromDom();
                    }

                    function renderAdditionalCategoriesSelectionState() {
                        var $box = $('#oy-basic-additional-categories-selected-meta');
                        if (!$box.length) { return; }

                        var items = getAdditionalCategoriesFromDom();
                        if (!items.length) {
                            $box.empty();
                            return;
                        }

                        var valid = [];
                        var invalid = [];

                        items.forEach(function(item) {
                            var normalized = normalizeCategoryItem(item);
                            if (!normalized.displayName && normalized.name) {
                                normalized.displayName = normalized.name;
                            }
                            if (!normalized.displayName) { return; }
                            if (normalized.name) valid.push(normalized);
                            else invalid.push(normalized);
                        });

                        var html = '';
                        if (valid.length) {
                            html += '<div style="padding:10px 12px; background:#edfaef; border:1px solid #b8dcbf; margin-bottom:8px;">' +
                                '<div><strong>Categorías adicionales listas para publicar en GMB:</strong></div>' +
                                '<ul style="margin:8px 0 0 18px; list-style:disc;">' +
                                valid.map(function(item) {
                                    return '<li>' + escapeHtml(item.displayName) + ' <code>' + escapeHtml(item.name) + '</code></li>';
                                }).join('') +
                                '</ul>' +
                            '</div>';
                        }

                        if (invalid.length) {
                            html += '<div style="padding:10px 12px; background:#fff8e5; border:1px solid #ecd58d;">' +
                                '<div><strong>Hay categorías adicionales aún no vinculadas a Google:</strong></div>' +
                                '<ul style="margin:8px 0 0 18px; list-style:disc;">' +
                                invalid.map(function(item) {
                                    return '<li>' + escapeHtml(item.displayName) + '</li>';
                                }).join('') +
                                '</ul>' +
                                '<div style="margin-top:8px;">Selecciona una sugerencia oficial del predictivo en cada fila antes de publicar.</div>' +
                            '</div>';
                        }

                        $box.html(html);
                    }

                    function clearAdditionalCategorySelectionIfTextChanged($row) {
                        if (!$row || !$row.length) { return; }
                        var $input = $row.find('.oy-basic-additional-category-label');
                        var currentText = $.trim($input.val() || '');
                        var selectedLabel = $input.data('selectedLabel') || '';

                        if (!currentText) {
                            $row.find('.oy-basic-additional-category-name').val('');
                            $input.data('selectedLabel', '');
                            syncAdditionalCategoriesInputFromDom();
                            renderAdditionalCategoriesSelectionState();
                            return;
                        }

                        if (selectedLabel && currentText !== selectedLabel) {
                            $row.find('.oy-basic-additional-category-name').val('');
                            $input.data('selectedLabel', '');
                        }

                        syncAdditionalCategoriesInputFromDom();
                        renderAdditionalCategoriesSelectionState();
                    }

                    function createAdditionalCategoryRow(item, index) {
                        var normalized = normalizeCategoryItem(item || {});
                        var html = '' +
                            '<div class="oy-basic-additional-category-row" data-row-index="'+ index +'" style="position:relative; display:flex; gap:8px; align-items:flex-start; margin-bottom:8px;">' +
                                '<div style="flex:1; position:relative; min-width:0;">' +
                                    '<input type="text" class="regular-text oy-basic-additional-category-label" value="'+ escapeHtml(normalized.displayName || '') +'" data-oy-basic-field="1" readonly="readonly" autocomplete="off" placeholder="Escribe para buscar una categoría oficial de Google" style="width:100%;">' +
                                    '<input type="hidden" class="oy-basic-additional-category-name" value="'+ escapeHtml(normalized.name || '') +'">' +
                                    '<div class="oy-basic-additional-category-suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1000; background:#fff; border:1px solid #c3c4c7; border-top:none; box-shadow:0 8px 18px rgba(0,0,0,.08); max-height:260px; overflow:auto;"></div>' +
                                '</div>' +
                                '<button type="button" class="button-link-delete oy-basic-additional-category-remove" data-oy-basic-field="1" disabled="disabled" style="padding-top:6px; white-space:nowrap; color:#b32d2e; text-decoration:none;">Quitar</button>' +
                            '</div>';
                        var $row = $(html);
                        if (normalized.name) {
                            $row.find('.oy-basic-additional-category-label').data('selectedLabel', normalized.displayName || normalized.name);
                        }
                        return $row;
                    }

                    function reindexAdditionalCategoryRows() {
                        $('#oy-basic-additional-categories-list .oy-basic-additional-category-row').each(function(index) {
                            $(this).attr('data-row-index', index);
                        });
                    }

                    function renderAdditionalCategoryRows(items) {
                        var $list = $('#oy-basic-additional-categories-list');
                        if (!$list.length) { return; }
                        $list.empty();
                        (Array.isArray(items) ? items : []).forEach(function(item, index) {
                            $list.append(createAdditionalCategoryRow(item, index));
                        });
                        reindexAdditionalCategoryRows();
                        syncAdditionalCategoriesInputFromDom();
                        renderAdditionalCategoriesSelectionState();
                    }

                    function addAdditionalCategoryRow(item) {
                        var $list = $('#oy-basic-additional-categories-list');
                        if (!$list.length) { return; }
                        var $row = createAdditionalCategoryRow(item || {}, $list.find('.oy-basic-additional-category-row').length);
                        $list.append($row);
                        reindexAdditionalCategoryRows();
                        syncAdditionalCategoriesInputFromDom();
                        renderAdditionalCategoriesSelectionState();
                        updateUiState();
                        return $row;
                    }

                    function hasInvalidAdditionalCategories() {
                        return getAdditionalCategoriesFromDom().some(function(item) {
                            var normalized = normalizeCategoryItem(item);
                            return !!normalized.displayName && !normalized.name;
                        });
                    }

                    function selectCategorySuggestion(item) {
                        if (!item || !item.name) { return; }
                        $('#google_primary_category').val(item.displayName || item.name).data('selectedLabel', item.displayName || item.name);
                        $('#google_primary_category_name').val(item.name || '');
                        hideCategorySuggestions();
                        renderCategorySelectionState();
                        refreshDirtyState();
                    }

                    function selectAdditionalCategorySuggestion($row, item) {
                        if (!$row || !$row.length || !item || !item.name) { return; }
                        $row.find('.oy-basic-additional-category-label').val(item.displayName || item.name).data('selectedLabel', item.displayName || item.name);
                        $row.find('.oy-basic-additional-category-name').val(item.name || '');
                        hideAdditionalCategorySuggestions($row);
                        syncAdditionalCategoriesInputFromDom();
                        renderAdditionalCategoriesSelectionState();
                        refreshDirtyState();
                    }

                    function renderCategorySuggestions(items) {
                        var $box = $('#oy-basic-category-suggestions');
                        if (!$box.length) { return; }

                        if (!Array.isArray(items) || !items.length) {
                            $box.html('<div style="padding:10px 12px; font-size:12px; color:#666;">No se encontraron categorías oficiales de Google para ese texto.</div>').show();
                            return;
                        }

                        var html = '';
                        items.forEach(function(item) {
                            var label = item && item.displayName ? item.displayName : (item && item.name ? item.name : '');
                            var code = item && item.name ? item.name : '';
                            if (!label || !code) { return; }
                            html += '<button type="button" class="oy-basic-category-option" ' +
                                'data-label="' + escapeHtml(label).replace(/"/g, '&quot;') + '" ' +
                                'data-name="' + escapeHtml(code).replace(/"/g, '&quot;') + '" ' +
                                'style="display:block; width:100%; text-align:left; padding:10px 12px; border:none; border-top:1px solid #f0f0f1; background:#fff; cursor:pointer;">' +
                                    '<div style="font-weight:600; color:#1d2327;">' + escapeHtml(label) + '</div>' +
                                    '<div style="margin-top:3px; font-size:11px; color:#666;"><code>' + escapeHtml(code) + '</code></div>' +
                                '</button>';
                        });

                        if (!html) {
                            $box.html('<div style="padding:10px 12px; font-size:12px; color:#666;">No se encontraron categorías oficiales de Google para ese texto.</div>').show();
                            return;
                        }

                        $box.html(html).show();
                    }

                    function renderAdditionalCategorySuggestions($row, items) {
                        if (!$row || !$row.length) { return; }
                        var $box = $row.find('.oy-basic-additional-category-suggestions');
                        if (!$box.length) { return; }

                        if (!Array.isArray(items) || !items.length) {
                            $box.html('<div style="padding:10px 12px; font-size:12px; color:#666;">No se encontraron categorías oficiales de Google para ese texto.</div>').show();
                            return;
                        }

                        var html = '';
                        items.forEach(function(item) {
                            var label = item && item.displayName ? item.displayName : (item && item.name ? item.name : '');
                            var code = item && item.name ? item.name : '';
                            if (!label || !code) { return; }
                            html += '<button type="button" class="oy-basic-additional-category-option" ' +
                                'data-label="' + escapeHtml(label).replace(/"/g, '&quot;') + '" ' +
                                'data-name="' + escapeHtml(code).replace(/"/g, '&quot;') + '" ' +
                                'style="display:block; width:100%; text-align:left; padding:10px 12px; border:none; border-top:1px solid #f0f0f1; background:#fff; cursor:pointer;">' +
                                    '<div style="font-weight:600; color:#1d2327;">' + escapeHtml(label) + '</div>' +
                                    '<div style="margin-top:3px; font-size:11px; color:#666;"><code>' + escapeHtml(code) + '</code></div>' +
                                '</button>';
                        });

                        if (!html) {
                            $box.html('<div style="padding:10px 12px; font-size:12px; color:#666;">No se encontraron categorías oficiales de Google para ese texto.</div>').show();
                            return;
                        }

                        $box.html(html).show();
                    }

                    function requestCategorySuggestions(term, onSuccess) {
                        if (categorySearchXhr && typeof categorySearchXhr.abort === 'function') {
                            categorySearchXhr.abort();
                        }

                        categorySearchXhr = $.post(AJAX_URL, {
                            action: 'oy_search_gmb_categories',
                            nonce: SEARCH_NONCE,
                            post_id: POST_ID,
                            term: term
                        }).done(function(response) {
                            if (!response || !response.success) {
                                var msg = response && response.data && response.data.message ? response.data.message : 'No se pudieron cargar las categorías de Google.';
                                hideCategorySuggestions();
                                hideAdditionalCategorySuggestions();
                                setStatus(msg, 'error');
                                return;
                            }

                            if (typeof onSuccess === 'function') {
                                onSuccess(response.data && response.data.items ? response.data.items : []);
                            }
                        }).fail(function(xhr, status) {
                            if (status === 'abort') { return; }
                            hideCategorySuggestions();
                            hideAdditionalCategorySuggestions();
                            var msg = 'Error de red al consultar categorías de Google.';
                            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                                msg = xhr.responseJSON.data.message;
                            }
                            setStatus(msg, 'error');
                        }).always(function() {
                            categorySearchXhr = null;
                        });
                    }

                    function queuePrimaryCategorySearch() {
                        if (!editorState.enabled) { return; }
                        var term = getCategoryDisplayValue();
                        clearSelectedCategoryIfTextChanged();
                        if (!term || term.length < 2) {
                            hideCategorySuggestions();
                            return;
                        }
                        if (categorySearchTimer) { clearTimeout(categorySearchTimer); }
                        categorySearchTimer = setTimeout(function() {
                            requestCategorySuggestions(term, function(items) { renderCategorySuggestions(items); });
                        }, 250);
                    }

                    function queueAdditionalCategorySearch($row) {
                        if (!editorState.enabled || !$row || !$row.length) { return; }
                        var term = $.trim($row.find('.oy-basic-additional-category-label').val() || '');
                        clearAdditionalCategorySelectionIfTextChanged($row);
                        if (!term || term.length < 2) {
                            hideAdditionalCategorySuggestions($row);
                            return;
                        }
                        var timer = $row.data('searchTimer');
                        if (timer) { clearTimeout(timer); }
                        timer = setTimeout(function() {
                            requestCategorySuggestions(term, function(items) {
                                hideCategorySuggestions();
                                renderAdditionalCategorySuggestions($row, items);
                            });
                        }, 250);
                        $row.data('searchTimer', timer);
                    }

                    function captureState() {
                        return {
                            location_short_description: $('#location_short_description').val() || '',
                            opening_date: $('#opening_date').val() || '',
                            google_primary_category: $('#google_primary_category').val() || '',
                            google_primary_category_name: $('#google_primary_category_name').val() || '',
                            google_additional_categories: cloneValue(getAdditionalCategoriesFromDom()),
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
                        $('#oy-basic-additional-category-add').prop('disabled', !editorState.enabled || editorState.saving);
                        $('.oy-basic-additional-category-remove').prop('disabled', !editorState.enabled || editorState.saving);

                        if (editorState.saving) $('#oy-basic-info-editor-state').text('Guardando metabox...');
                        else if (editorState.enabled && editorState.dirty) $('#oy-basic-info-editor-state').text('Tienes cambios locales sin guardar.');
                        else if (editorState.enabled) $('#oy-basic-info-editor-state').text('Modo edición activo.');
                        else $('#oy-basic-info-editor-state').text('Modo lectura.');
                    }

                    function beginEditMode() {
                        editorState.enabled = true;
                        editorState.saving = false;
                        editorState.baseline = captureState();
                        hideCategorySuggestions();
                        hideAdditionalCategorySuggestions();
                        renderCategorySelectionState();
                        renderAdditionalCategoriesSelectionState();
                        refreshDirtyState();
                        setStatus('Modo edición activado. Guarda el metabox antes de usar "Enviar a GMB".', 'info');
                    }

                    function cancelEditMode() {
                        if (!editorState.baseline) editorState.baseline = captureState();
                        $('#location_short_description').val(editorState.baseline.location_short_description);
                        $('#opening_date').val(editorState.baseline.opening_date);
                        $('#google_primary_category').val(editorState.baseline.google_primary_category);
                        $('#google_primary_category_name').val(editorState.baseline.google_primary_category_name || '');
                        $('#google_primary_category').data('selectedLabel', (editorState.baseline.google_primary_category_name ? editorState.baseline.google_primary_category : ''));
                        renderAdditionalCategoryRows(editorState.baseline.google_additional_categories || []);
                        $('#price_range').val(editorState.baseline.price_range);
                        hideCategorySuggestions();
                        hideAdditionalCategorySuggestions();
                        updateDescriptionCounter();
                        renderCategorySelectionState();
                        renderAdditionalCategoriesSelectionState();
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

                    function buildDiff(before, after) {
                        var rows = [];
                        FIELD_MAP.forEach(function(field) {
                            var bStr = valueToText(field.key, before && Object.prototype.hasOwnProperty.call(before, field.key) ? before[field.key] : '');
                            var aStr = valueToText(field.key, after && Object.prototype.hasOwnProperty.call(after, field.key) ? after[field.key] : '');
                            var rawBefore = String(bStr == null ? '' : bStr);
                            var rawAfter = String(aStr == null ? '' : aStr);
                            if (rawBefore === rawAfter) {
                                rows.push({ key: field.key, label: field.label, before: rawBefore, after: rawAfter, status: 'unchanged' });
                            } else if (!rawBefore && rawAfter) {
                                rows.push({ key: field.key, label: field.label, before: rawBefore, after: rawAfter, status: 'new' });
                            } else {
                                rows.push({ key: field.key, label: field.label, before: rawBefore, after: rawAfter, status: 'changed' });
                            }
                        });
                        return rows;
                    }

                    function getLog() {
                        return parseJsonSafe(localStorage.getItem(LS_KEY), []);
                    }

                    function setLog(entries) {
                        localStorage.setItem(LS_KEY, JSON.stringify((entries || []).slice(0, MAX_LOG)));
                    }

                    function clearLog() {
                        localStorage.removeItem(LS_KEY);
                    }

                    function addLogEntry(raw, diff, actionType) {
                        var log = getLog();
                        log.unshift({
                            at: new Date().toISOString(),
                            action: actionType || (raw && raw.action ? raw.action : 'info'),
                            diff: Array.isArray(diff) ? diff : [],
                            raw: raw || {}
                        });
                        setLog(log);
                        renderLog();
                    }

                    function renderLog() {
                        var $container = $('#oy-basic-info-log-entries');
                        if (!$container.length) { return; }
                        var log = getLog();
                        $container.empty();

                        if (!log.length) {
                            $container.html('<div style="padding:14px; color:#666; font-size:12px;">Aún no hay eventos registrados en el historial local del navegador.</div>');
                            return;
                        }

                        try {
                            log.forEach(function(entry, index) {
                                var diff = Array.isArray(entry.diff) ? entry.diff : [];
                                var hasError = entry.raw && (entry.raw.error || entry.raw.error_message || entry.raw._serializeError);
                                var title = entry.action || 'evento';
                                var date = entry.at ? new Date(entry.at) : new Date();
                                var dateLabel = isNaN(date.getTime()) ? String(entry.at || '') : date.toLocaleString();

                                var palette = {
                                    manual_save: { bg:'#f6fff9', border:'#46b450', icon:'💾' },
                                    push_to_gmb: { bg:'#eef6ff', border:'#2271b1', icon:'🚀' },
                                    manual_check: { bg:'#fff8e5', border:'#dba617', icon:'🔎' },
                                    push_error: { bg:'#fff5f5', border:'#dc3232', icon:'⚠️' },
                                    check_error: { bg:'#fff5f5', border:'#dc3232', icon:'⚠️' }
                                };
                                var paletteCfg = palette[title] || { bg:'#fff', border:'#dcdcde', icon:'•' };

                                var $entry = $('<div/>').css({ borderLeft:'4px solid '+paletteCfg.border, background:paletteCfg.bg, margin:'0', borderBottom:'1px solid #f0f0f1' });
                                var $entryHeader = $('<div/>').css({ padding:'10px 14px', cursor:'pointer', display:'flex', justifyContent:'space-between', alignItems:'center', gap:'10px' });
                                var headerText = paletteCfg.icon + ' ' + title + ' — ' + dateLabel;
                                $('<div/>').css({ fontWeight:'600', color:'#1d2327', fontSize:'12px' }).text(headerText).appendTo($entryHeader);
                                var isFirst = index === 0;
                                var $toggleIcon = $('<span/>').css({ color:'#666', fontSize:'12px' }).text(isFirst ? '▼' : '▶');
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

                        var beforeState = editorState.baseline ? cloneValue(editorState.baseline) : captureState();
                        var additionalCategoriesJson = JSON.stringify(getAdditionalCategoriesFromDom());

                        $.post(AJAX_URL, {
                            action: 'oy_save_basic_info_metabox',
                            nonce: SAVE_NONCE,
                            post_id: POST_ID,
                            location_short_description: $('#location_short_description').val() || '',
                            opening_date: $('#opening_date').val() || '',
                            google_primary_category: $('#google_primary_category').val() || '',
                            google_primary_category_name: $('#google_primary_category_name').val() || '',
                            google_additional_categories_json: additionalCategoriesJson,
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
                        $(document).on('input.oyBasicInfo change.oyBasicInfo', '#location_short_description, #opening_date, #price_range', function() {
                            if (!editorState.enabled) return;
                            updateDescriptionCounter();
                            refreshDirtyState();
                        });

                        $(document).on('input.oyBasicInfo', '#google_primary_category', function() {
                            if (!editorState.enabled) { return; }
                            queuePrimaryCategorySearch();
                            refreshDirtyState();
                        });

                        $(document).on('focus.oyBasicInfo', '#google_primary_category', function() {
                            if (!editorState.enabled) { return; }
                            if (getCategoryDisplayValue() && getCategoryDisplayValue().length >= 2 && !getCategoryResourceName()) {
                                queuePrimaryCategorySearch();
                            }
                        });

                        $(document).on('blur.oyBasicInfo', '#google_primary_category', function() {
                            setTimeout(function() { hideCategorySuggestions(); }, 180);
                        });

                        $(document).on('click.oyBasicInfo', '.oy-basic-category-option', function(event) {
                            event.preventDefault();
                            selectCategorySuggestion({
                                displayName: $(this).data('label') || '',
                                name: $(this).data('name') || ''
                            });
                        });

                        $(document).on('input.oyBasicInfo', '.oy-basic-additional-category-label', function() {
                            if (!editorState.enabled) { return; }
                            var $row = $(this).closest('.oy-basic-additional-category-row');
                            queueAdditionalCategorySearch($row);
                            refreshDirtyState();
                        });

                        $(document).on('focus.oyBasicInfo', '.oy-basic-additional-category-label', function() {
                            if (!editorState.enabled) { return; }
                            var $row = $(this).closest('.oy-basic-additional-category-row');
                            var text = $.trim($(this).val() || '');
                            var name = $.trim($row.find('.oy-basic-additional-category-name').val() || '');
                            if (text.length >= 2 && !name) {
                                queueAdditionalCategorySearch($row);
                            }
                        });

                        $(document).on('blur.oyBasicInfo', '.oy-basic-additional-category-label', function() {
                            var $row = $(this).closest('.oy-basic-additional-category-row');
                            setTimeout(function() { hideAdditionalCategorySuggestions($row); }, 180);
                        });

                        $(document).on('click.oyBasicInfo', '.oy-basic-additional-category-option', function(event) {
                            event.preventDefault();
                            var $row = $(this).closest('.oy-basic-additional-category-row');
                            selectAdditionalCategorySuggestion($row, {
                                displayName: $(this).data('label') || '',
                                name: $(this).data('name') || ''
                            });
                        });

                        $(document).on('click.oyBasicInfo', '.oy-basic-additional-category-remove', function(event) {
                            event.preventDefault();
                            if (!editorState.enabled) { return; }
                            $(this).closest('.oy-basic-additional-category-row').remove();
                            reindexAdditionalCategoryRows();
                            syncAdditionalCategoriesInputFromDom();
                            renderAdditionalCategoriesSelectionState();
                            refreshDirtyState();
                        });

                        $(document).on('click.oyBasicInfo', '#oy-basic-additional-category-add', function(event) {
                            event.preventDefault();
                            if (!editorState.enabled) { return; }
                            if (!$.trim(getCategoryDisplayValue())) {
                                setStatus('Define primero la Categoría Principal antes de agregar categorías adicionales.', 'warning');
                                return;
                            }
                            var $row = addAdditionalCategoryRow({ displayName:'', name:'' });
                            refreshDirtyState();
                            if ($row && $row.length) {
                                $row.find('.oy-basic-additional-category-label').trigger('focus');
                            }
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

                            if (getCategoryDisplayValue() && !getCategoryResourceName()) {
                                setPushStatus('La Categoría Principal debe seleccionarse desde el predictivo oficial antes de poder enviarse a GMB.', 'error');
                                setStatus('Selecciona una categoría oficial del predictivo y guarda el metabox antes de publicar.', 'error');
                                return;
                            }

                            if (hasInvalidAdditionalCategories()) {
                                setPushStatus('Cada Categoría Adicional debe seleccionarse desde el predictivo oficial antes de poder enviarse a GMB.', 'error');
                                setStatus('Corrige las categorías adicionales que aún no están vinculadas a Google, guarda el metabox y vuelve a intentar.', 'error');
                                return;
                            }

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

                        if ($('#google_primary_category_name').val()) {
                            $('#google_primary_category').data('selectedLabel', $('#google_primary_category').val() || '');
                        }

                        $('#oy-basic-additional-categories-list .oy-basic-additional-category-row').each(function() {
                            var $row = $(this);
                            var label = $.trim($row.find('.oy-basic-additional-category-label').val() || '');
                            var name = $.trim($row.find('.oy-basic-additional-category-name').val() || '');
                            if (name && label) {
                                $row.find('.oy-basic-additional-category-label').data('selectedLabel', label);
                            }
                        });

                        if (!$('#oy-basic-additional-categories-list .oy-basic-additional-category-row').length) {
                            var initialRows = parseJsonSafe($('#oy-basic-additional-categories-list').attr('data-initial-json') || $('#google_additional_categories_json').val(), []);
                            if (initialRows.length) {
                                renderAdditionalCategoryRows(initialRows);
                            } else {
                                syncAdditionalCategoriesInputFromDom();
                            }
                        } else {
                            syncAdditionalCategoriesInputFromDom();
                        }

                        editorState.baseline = captureState();
                        updateDescriptionCounter();
                        updateUiState();
                        renderCategorySelectionState();
                        renderAdditionalCategoriesSelectionState();
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
