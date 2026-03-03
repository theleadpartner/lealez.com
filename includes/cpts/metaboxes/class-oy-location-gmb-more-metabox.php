<?php
/**
 * OY Location - Google Business Profile "Más" (Atributos) Metabox
 *
 * Metabox que muestra y gestiona los atributos dinámicos del Perfil de Negocio en Google,
 * correspondientes a la sección "Más" (accesibilidad, pagos, servicios, amenidades, etc.).
 *
 * Flujo:
 *  1. Al cargar la página → render_metabox() lee el transient de metadatos de atributos.
 *  2. Botón "Actualizar metadatos" → AJAX oy_gmb_more_refresh_metadata:
 *       llama a la API de Google, guarda en transient, recarga la página.
 *  3. Botón "Enviar a Google ↑" → AJAX oy_gmb_more_push_to_gmb:
 *       lee los overrides del post_meta, los envía vía PATCH a la API de Google.
 *
 * CLAVE DEL TRANSIENT:
 *   'oy_gmb_more_attr_meta_' . (int) $post_id
 *   Tanto el AJAX handler como render_metabox() usan EXACTAMENTE esta misma clave.
 *
 * @package    Lealez
 * @subpackage CPTs/Metaboxes
 * @since      1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'OY_Location_GMB_More_Metabox' ) ) {

    /**
     * Class OY_Location_GMB_More_Metabox
     */
    class OY_Location_GMB_More_Metabox {

        /**
         * Prefijo del transient para metadatos de atributos.
         * CONSTANTE — mismo valor en AJAX handler y en render_metabox.
         *
         * @var string
         */
        const TRANSIENT_PREFIX = 'oy_gmb_more_attr_meta_';

        /**
         * TTL del transient: 24 horas.
         *
         * @var int
         */
        const TRANSIENT_TTL = DAY_IN_SECONDS;

        /**
         * Meta key para guardar el timestamp de la última actualización de metadatos.
         *
         * @var string
         */
        const META_UPDATED = '_gmb_attr_metadata_updated';

        /**
         * Meta key base para guardar overrides de atributos.
         * Se concatena con el attributeId.  Ej: '_gmb_attr_override_pay_cash_only'
         *
         * @var string
         */
        const META_OVERRIDE_PREFIX = '_gmb_attr_override_';

        // =====================================================================
        // CONSTRUCTOR + REGISTRO
        // =====================================================================

        /**
         * Constructor
         */
        public function __construct() {
            // Registrar metabox
            add_action( 'add_meta_boxes_oy_location', array( $this, 'register_metabox' ), 25 );

            // Encolar scripts/estilos del admin
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

            // AJAX: actualizar metadatos desde Google
            add_action( 'wp_ajax_oy_gmb_more_refresh_metadata', array( $this, 'ajax_refresh_metadata' ) );

            // AJAX: enviar cambios a Google
            add_action( 'wp_ajax_oy_gmb_more_push_to_gmb', array( $this, 'ajax_push_to_gmb' ) );
        }

        // =====================================================================
        // REGISTRO DEL METABOX
        // =====================================================================

        /**
         * Registrar el metabox en el CPT oy_location.
         *
         * @param WP_Post $post
         */
        public function register_metabox( $post ) {
            add_meta_box(
                'oy_location_gmb_more',
                __( '📋 Google Business Profile – Sección "Más" (Atributos)', 'lealez' ),
                array( $this, 'render_metabox' ),
                'oy_location',
                'normal',
                'default'
            );
        }

        // =====================================================================
        // SCRIPTS Y ESTILOS
        // =====================================================================

        /**
         * Encolar el script JS del metabox.
         *
         * @param string $hook
         */
        public function enqueue_scripts( $hook ) {
            global $post;

            if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
                return;
            }
            if ( ! $post || 'oy_location' !== $post->post_type ) {
                return;
            }

            $js_file = defined( 'LEALEZ_ASSETS_URL' )
                ? LEALEZ_ASSETS_URL . 'js/admin/oy-location-gmb-more.js'
                : '';

            if ( $js_file ) {
                wp_enqueue_script(
                    'oy-location-gmb-more',
                    $js_file,
                    array( 'jquery' ),
                    defined( 'LEALEZ_VERSION' ) ? LEALEZ_VERSION : '1.0.0',
                    true
                );

                wp_localize_script(
                    'oy-location-gmb-more',
                    'oyGmbMoreConfig',
                    array(
                        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                        'nonce'   => wp_create_nonce( 'oy_gmb_more_nonce_' . $post->ID ),
                        'postId'  => $post->ID,
                        'i18n'    => array(
                            'refreshing'   => __( 'Actualizando metadatos desde Google...', 'lealez' ),
                            'refreshDone'  => __( 'Metadatos actualizados correctamente.', 'lealez' ),
                            'refreshError' => __( 'Error al actualizar metadatos.', 'lealez' ),
                            'confirmPush'  => __( '¿Enviar los cambios directamente a Google Business Profile?', 'lealez' ),
                            'pushing'      => __( 'Enviando a Google...', 'lealez' ),
                            'pushDone'     => __( 'Cambios enviados a Google correctamente.', 'lealez' ),
                            'pushError'    => __( 'Error al enviar a Google.', 'lealez' ),
                        ),
                    )
                );
            }
        }

        // =====================================================================
        // RENDER DEL METABOX
        // =====================================================================

        /**
         * Renderizar el contenido del metabox.
         *
         * @param WP_Post $post
         */
        public function render_metabox( $post ) {
            $post_id            = (int) $post->ID;
            $parent_business_id = (int) get_post_meta( $post_id, 'parent_business_id', true );

            // Verificar conexión GMB
            $gmb_location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
            $gmb_connected     = ! empty( $gmb_location_name );

            // Timestamp de última actualización de metadatos
            $last_updated = (int) get_post_meta( $post_id, self::META_UPDATED, true );

            echo '<div id="oy-gmb-more-metabox-' . esc_attr( $post_id ) . '" class="oy-gmb-more-metabox-wrap">';

            // ── Barra de estado y acciones ──────────────────────────────────
            echo '<div class="oy-gmb-more-header" style="display:flex; align-items:center; gap:12px; margin-bottom:12px; flex-wrap:wrap;">';

            if ( $gmb_connected ) {
                echo '<span style="color:#2ea44f; font-weight:600;">'
                   . '<span class="dashicons dashicons-yes-alt" style="vertical-align:middle;"></span> '
                   . esc_html__( 'Ubicación conectada a Google Business Profile.', 'lealez' )
                   . '</span>';
            } else {
                echo '<span style="color:#b32d2e;">'
                   . '<span class="dashicons dashicons-warning" style="vertical-align:middle;"></span> '
                   . esc_html__( 'Ubicación no conectada a Google Business Profile.', 'lealez' )
                   . '</span>';
            }

            // Botones de acción
            echo '<div style="margin-left:auto; display:flex; gap:8px;">';

            echo '<button type="button" class="button button-secondary oy-gmb-more-btn-refresh">'
               . '<span class="dashicons dashicons-update" style="vertical-align:middle; margin-top:3px;"></span> '
               . esc_html__( 'Actualizar metadatos', 'lealez' )
               . '</button>';

            echo '<button type="button" class="button button-primary oy-gmb-more-btn-push" '
               . ( $gmb_connected ? '' : 'disabled ' )
               . '>'
               . '<span class="dashicons dashicons-upload" style="vertical-align:middle; margin-top:3px;"></span> '
               . esc_html__( 'Enviar a Google ↑', 'lealez' )
               . '</button>';

            echo '</div>'; // .header buttons

            echo '</div>'; // .oy-gmb-more-header

            // ── Campo oculto para tracking de cambios ───────────────────────
            echo '<input type="hidden" id="oy_gmb_more_has_changes_' . esc_attr( $post_id ) . '" value="0">';

            // ── Área de notificaciones del JS ───────────────────────────────
            echo '<div id="oy-gmb-more-notice-' . esc_attr( $post_id ) . '" '
               . 'class="oy-gmb-more-notice" '
               . 'style="display:none; padding:10px 14px; border-left:4px solid #2ea44f; background:#f0fff4; margin-bottom:12px; border-radius:2px;">'
               . '</div>';

            // ── Leer metadatos cacheados ────────────────────────────────────
            // CLAVE ÚNICA: 'oy_gmb_more_attr_meta_' . $post_id
            // Exactamente la misma que usa ajax_refresh_metadata().
            $transient_key     = self::TRANSIENT_PREFIX . $post_id;
            $attribute_schemas = get_transient( $transient_key );

            // Normalizar: si el transient guardó el objeto completo de la API
            // { attributeMetadata: [...] }, extraemos el array interno.
            if ( is_array( $attribute_schemas ) && isset( $attribute_schemas['attributeMetadata'] ) ) {
                $attribute_schemas = $attribute_schemas['attributeMetadata'];
            }

            $has_metadata = ( is_array( $attribute_schemas ) && ! empty( $attribute_schemas ) );

            // ── Info de cuándo se cargaron los metadatos ─────────────────────
            if ( $last_updated ) {
                $seconds_ago = time() - $last_updated;
                if ( $seconds_ago < 60 ) {
                    $when = __( 'hace unos segundos', 'lealez' );
                } elseif ( $seconds_ago < 3600 ) {
                    $when = sprintf( _n( 'hace %d minuto', 'hace %d minutos', (int) floor( $seconds_ago / 60 ), 'lealez' ), (int) floor( $seconds_ago / 60 ) );
                } else {
                    $when = sprintf( _n( 'hace %d hora', 'hace %d horas', (int) floor( $seconds_ago / 3600 ), 'lealez' ), (int) floor( $seconds_ago / 3600 ) );
                }
                echo '<p class="description" style="margin-bottom:10px; font-style:italic; color:#666;">'
                   . sprintf(
                       esc_html__( 'Metadatos cargados %s. Se actualizan automáticamente cada 24 h.', 'lealez' ),
                       esc_html( $when )
                   )
                   . '</p>';
            }

            // ── Renderizar campos o mensaje vacío ────────────────────────────
            if ( ! $has_metadata ) {
                echo '<p class="description">'
                   . esc_html__( 'No se encontraron atributos disponibles para esta categoría de negocio.', 'lealez' )
                   . '</p>';
                echo '<p class="description" style="margin-top:6px; color:#666;">'
                   . esc_html__( 'Haz clic en "Actualizar metadatos" para cargar los atributos desde Google.', 'lealez' )
                   . '</p>';
            } else {
                // Leer valores actuales de atributos (guardados en post_meta por la sincronización GMB)
                $current_values_raw = get_post_meta( $post_id, 'gmb_attributes_raw', true );
                $current_values     = $this->normalize_attribute_values( $current_values_raw );

                // Agrupar esquemas por groupDisplayName
                $groups = $this->group_schemas_by_group( $attribute_schemas );

                echo '<div class="oy-gmb-more-fields-wrap">';

                foreach ( $groups as $group_name => $schemas ) {
                    echo '<div class="oy-gmb-more-group" style="margin-bottom:20px;">';
                    echo '<h4 style="border-bottom:1px solid #ddd; padding-bottom:6px; margin-bottom:10px;">'
                       . esc_html( $group_name )
                       . '</h4>';
                    echo '<table class="form-table" style="margin-top:0;">';

                    foreach ( $schemas as $schema ) {
                        $this->render_attribute_field( $post_id, $schema, $current_values );
                    }

                    echo '</table>';
                    echo '</div>'; // .oy-gmb-more-group
                }

                echo '</div>'; // .oy-gmb-more-fields-wrap
            }

            echo '</div>'; // #oy-gmb-more-metabox-{id}
        }

        // =====================================================================
        // HELPERS DE RENDER
        // =====================================================================

        /**
         * Agrupa los esquemas de atributos por su groupDisplayName (null-safe).
         *
         * @param array $schemas  Array de atributo-metadata de la API de Google.
         * @return array          [ 'nombre_grupo' => [ $schema, ... ], ... ]
         */
        private function group_schemas_by_group( array $schemas ) {
            $groups = array();

            foreach ( $schemas as $schema ) {
                if ( ! is_array( $schema ) ) {
                    continue;
                }

                // NULL-SAFE: groupDisplayName puede ser null para algunos atributos
                $group = isset( $schema['groupDisplayName'] ) && ! is_null( $schema['groupDisplayName'] )
                    ? (string) $schema['groupDisplayName']
                    : __( 'Otros', 'lealez' );

                if ( '' === trim( $group ) ) {
                    $group = __( 'Otros', 'lealez' );
                }

                if ( ! isset( $groups[ $group ] ) ) {
                    $groups[ $group ] = array();
                }
                $groups[ $group ][] = $schema;
            }

            // Mover 'Otros' al final si existe
            $other_key = __( 'Otros', 'lealez' );
            if ( isset( $groups[ $other_key ] ) && count( $groups ) > 1 ) {
                $other = $groups[ $other_key ];
                unset( $groups[ $other_key ] );
                $groups[ $other_key ] = $other;
            }

            return $groups;
        }

        /**
         * Normaliza el array de valores de atributos desde post_meta a un mapa simple:
         * [ 'attributeId' => 'valor' ]  ó  [ 'attributeId' => ['uri1', 'uri2'] ]
         *
         * @param mixed $raw  Valor de get_post_meta( $post_id, 'gmb_attributes_raw', true )
         * @return array
         */
        private function normalize_attribute_values( $raw ) {
            if ( ! is_array( $raw ) || empty( $raw ) ) {
                return array();
            }

            $map = array();

            foreach ( $raw as $attr ) {
                if ( ! is_array( $attr ) ) {
                    continue;
                }

                // Extraer el attributeId desde 'name' (e.g. 'locations/xxx/attributes/pay_cash_only')
                $attr_id = '';
                if ( ! empty( $attr['attributeId'] ) ) {
                    $attr_id = (string) $attr['attributeId'];
                } elseif ( ! empty( $attr['name'] ) ) {
                    $parts   = explode( '/attributes/', (string) $attr['name'], 2 );
                    $attr_id = isset( $parts[1] ) ? trim( $parts[1], '/' ) : '';
                }

                if ( '' === $attr_id ) {
                    continue;
                }

                $value_type = isset( $attr['valueType'] ) ? (string) $attr['valueType'] : 'BOOL';

                if ( 'URL' === $value_type || isset( $attr['uriValues'] ) ) {
                    $uris = array();
                    if ( ! empty( $attr['uriValues'] ) && is_array( $attr['uriValues'] ) ) {
                        foreach ( $attr['uriValues'] as $uv ) {
                            if ( isset( $uv['uri'] ) ) {
                                $uris[] = (string) $uv['uri'];
                            }
                        }
                    }
                    $map[ $attr_id ] = $uris;
                } elseif ( isset( $attr['values'] ) && is_array( $attr['values'] ) ) {
                    $map[ $attr_id ] = $attr['values'];
                } else {
                    $map[ $attr_id ] = null;
                }
            }

            return $map;
        }

        /**
         * Renderiza una fila de tabla para un atributo dado.
         *
         * @param int    $post_id
         * @param array  $schema         Esquema del atributo (de la API de Google).
         * @param array  $current_values Mapa [ attributeId => valor_actual ].
         */
        private function render_attribute_field( $post_id, array $schema, array $current_values ) {
            // ── Extraer campos del esquema con NULL-SAFETY ──────────────────
            $attr_id = isset( $schema['attributeId'] ) && ! is_null( $schema['attributeId'] )
                ? (string) $schema['attributeId']
                : '';

            if ( '' === $attr_id ) {
                return; // Sin ID no podemos hacer nada
            }

            $display_name = isset( $schema['displayName'] ) && ! is_null( $schema['displayName'] )
                ? (string) $schema['displayName']
                : $this->humanize_attr_id( $attr_id );

            $value_type   = isset( $schema['valueType'] ) && ! is_null( $schema['valueType'] )
                ? (string) $schema['valueType']
                : 'BOOL';

            $is_repeatable = ! empty( $schema['isRepeatable'] );

            $value_metadata = ( isset( $schema['valueMetadata'] ) && is_array( $schema['valueMetadata'] ) )
                ? $schema['valueMetadata']
                : array();

            // ── Valor actual (GMB sincronizado) ─────────────────────────────
            $current_val = isset( $current_values[ $attr_id ] ) ? $current_values[ $attr_id ] : null;

            // ── Override guardado manualmente ───────────────────────────────
            $override_meta_key = self::META_OVERRIDE_PREFIX . sanitize_key( $attr_id );
            $override_val      = get_post_meta( $post_id, $override_meta_key, true );

            // El valor a mostrar: override si existe, si no el actual de GMB
            $display_val = ( '' !== $override_val && false !== $override_val ) ? $override_val : $current_val;

            // ── Field name base ─────────────────────────────────────────────
            $field_name = 'gmb_attr_override[' . esc_attr( $attr_id ) . ']';
            $field_id   = 'gmb_attr_' . sanitize_key( $attr_id );

            ?>
            <tr class="oy-gmb-more-attr-row" data-attr-id="<?php echo esc_attr( $attr_id ); ?>">
                <th scope="row" style="vertical-align:top; padding-top:12px;">
                    <label for="<?php echo esc_attr( $field_id ); ?>">
                        <?php echo esc_html( $display_name ); ?>
                    </label>
                    <br>
                    <small style="color:#999; font-weight:normal; font-size:11px;">
                        <?php echo esc_html( $attr_id ); ?>
                    </small>
                </th>
                <td>
                <?php

                switch ( $value_type ) {

                    // ── URL ──────────────────────────────────────────────────
                    case 'URL':
                        $uri_val = '';
                        if ( is_array( $display_val ) && ! empty( $display_val ) ) {
                            $uri_val = (string) $display_val[0];
                        } elseif ( is_string( $display_val ) ) {
                            $uri_val = $display_val;
                        }
                        echo '<input type="url" '
                           . 'id="' . esc_attr( $field_id ) . '" '
                           . 'name="' . esc_attr( $field_name ) . '" '
                           . 'value="' . esc_attr( $uri_val ) . '" '
                           . 'class="large-text oy-gmb-more-field-input" '
                           . 'placeholder="https://" '
                           . '>';
                        break;

                    // ── BOOL ─────────────────────────────────────────────────
                    case 'BOOL':
                        $bool_val = null;
                        if ( is_array( $display_val ) && isset( $display_val[0] ) ) {
                            $bool_val = (bool) $display_val[0];
                        } elseif ( is_bool( $display_val ) ) {
                            $bool_val = $display_val;
                        } elseif ( is_string( $display_val ) && '' !== $display_val ) {
                            $bool_val = filter_var( $display_val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
                        }

                        $selected_true  = ( true  === $bool_val ) ? ' selected' : '';
                        $selected_false = ( false === $bool_val ) ? ' selected' : '';
                        $selected_none  = ( null  === $bool_val ) ? ' selected' : '';

                        echo '<select id="' . esc_attr( $field_id ) . '" '
                           . 'name="' . esc_attr( $field_name ) . '" '
                           . 'class="regular-text oy-gmb-more-field-input">';
                        echo '<option value=""' . esc_attr( $selected_none )  . '>' . esc_html__( '— Sin especificar —', 'lealez' ) . '</option>';
                        echo '<option value="true"'  . esc_attr( $selected_true )  . '>' . esc_html__( 'Sí', 'lealez' ) . '</option>';
                        echo '<option value="false"' . esc_attr( $selected_false ) . '>' . esc_html__( 'No', 'lealez' ) . '</option>';
                        echo '</select>';
                        break;

                    // ── ENUM ─────────────────────────────────────────────────
                    case 'ENUM':
                        $enum_val = '';
                        if ( is_array( $display_val ) && isset( $display_val[0] ) ) {
                            $enum_val = (string) $display_val[0];
                        } elseif ( is_string( $display_val ) ) {
                            $enum_val = $display_val;
                        }

                        echo '<select id="' . esc_attr( $field_id ) . '" '
                           . 'name="' . esc_attr( $field_name ) . '" '
                           . 'class="regular-text oy-gmb-more-field-input">';
                        echo '<option value="">' . esc_html__( '— Sin especificar —', 'lealez' ) . '</option>';

                        foreach ( $value_metadata as $vm ) {
                            if ( ! is_array( $vm ) ) {
                                continue;
                            }
                            // NULL-SAFE: value y displayName pueden ser null
                            $vm_value   = isset( $vm['value'] ) && ! is_null( $vm['value'] )
                                ? (string) $vm['value']
                                : '';
                            $vm_display = isset( $vm['displayName'] ) && ! is_null( $vm['displayName'] )
                                ? (string) $vm['displayName']
                                : $vm_value;

                            if ( '' === $vm_value ) {
                                continue;
                            }
                            $selected = selected( $enum_val, $vm_value, false );
                            echo '<option value="' . esc_attr( $vm_value ) . '"' . $selected . '>'
                               . esc_html( $vm_display )
                               . '</option>';
                        }

                        echo '</select>';
                        break;

                    // ── INTEGER ──────────────────────────────────────────────
                    case 'INTEGER':
                        $int_val = '';
                        if ( is_array( $display_val ) && isset( $display_val[0] ) ) {
                            $int_val = (string) intval( $display_val[0] );
                        } elseif ( is_numeric( $display_val ) ) {
                            $int_val = (string) intval( $display_val );
                        }
                        echo '<input type="number" '
                           . 'id="' . esc_attr( $field_id ) . '" '
                           . 'name="' . esc_attr( $field_name ) . '" '
                           . 'value="' . esc_attr( $int_val ) . '" '
                           . 'class="small-text oy-gmb-more-field-input" '
                           . 'min="0" step="1"'
                           . '>';
                        break;

                    // ── Default (texto) ───────────────────────────────────────
                    default:
                        $text_val = '';
                        if ( is_array( $display_val ) && isset( $display_val[0] ) ) {
                            $text_val = (string) $display_val[0];
                        } elseif ( is_string( $display_val ) ) {
                            $text_val = $display_val;
                        }
                        echo '<input type="text" '
                           . 'id="' . esc_attr( $field_id ) . '" '
                           . 'name="' . esc_attr( $field_name ) . '" '
                           . 'value="' . esc_attr( $text_val ) . '" '
                           . 'class="regular-text oy-gmb-more-field-input"'
                           . '>';
                }

                // Indicador: ¿viene de GMB o es override manual?
                if ( '' !== $override_val && false !== $override_val ) {
                    echo '<p class="description" style="color:#996800; margin-top:4px;">'
                       . '<span class="dashicons dashicons-edit" style="font-size:14px; vertical-align:middle;"></span> '
                       . esc_html__( 'Valor modificado manualmente (override). Se enviará a GMB al presionar "Enviar a Google ↑".', 'lealez' )
                       . '</p>';
                } elseif ( null !== $current_val ) {
                    echo '<p class="description" style="color:#2ea44f; margin-top:4px;">'
                       . '<span class="dashicons dashicons-cloud" style="font-size:14px; vertical-align:middle;"></span> '
                       . esc_html__( 'Sincronizado desde Google My Business.', 'lealez' )
                       . '</p>';
                }

                ?>
                </td>
            </tr>
            <?php
        }

        /**
         * Convierte un attributeId a nombre legible (fallback cuando displayName es null).
         *
         * @param string $attr_id
         * @return string
         */
        private function humanize_attr_id( $attr_id ) {
            $name = (string) $attr_id;
            $name = str_replace( array( '_', '-' ), ' ', $name );
            $name = ucwords( strtolower( $name ) );
            $name = str_replace(
                array( 'Has ', 'Offers ', 'Accepts ', 'Is ', 'Url ' ),
                array( '', '', '', '', 'URL ' ),
                $name
            );
            return trim( $name );
        }

        // =====================================================================
        // AJAX: ACTUALIZAR METADATOS
        // =====================================================================

        /**
         * AJAX handler: obtiene los metadatos de atributos desde la API de Google
         * y los guarda en el transient con la clave canónica.
         *
         * Action: oy_gmb_more_refresh_metadata
         */
        public function ajax_refresh_metadata() {
            // Seguridad
            $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
            if ( ! $post_id ) {
                wp_send_json_error( array( 'message' => __( 'ID de post inválido.', 'lealez' ) ) );
            }

            check_ajax_referer( 'oy_gmb_more_nonce_' . $post_id, 'nonce' );

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
            }

            // Obtener datos necesarios para la llamada a la API
            $parent_business_id = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $gmb_location_name  = (string) get_post_meta( $post_id, 'gmb_location_name', true );

            if ( ! $parent_business_id || '' === $gmb_location_name ) {
                wp_send_json_error( array(
                    'message' => __( 'Esta ubicación no tiene Business ID o Location Name configurado.', 'lealez' ),
                ) );
            }

            // Normalizar location_name al formato corto 'locations/{id}'
            $location_short = $gmb_location_name;
            if ( strpos( $gmb_location_name, 'accounts/' ) === 0 ) {
                $parts = explode( '/locations/', $gmb_location_name, 2 );
                if ( ! empty( $parts[1] ) ) {
                    $location_short = 'locations/' . $parts[1];
                }
            }

            // Llamar a la API de Google para obtener metadatos de atributos
            $metadata = $this->get_attribute_metadata( $parent_business_id, $location_short );

            if ( is_wp_error( $metadata ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY GMB More] ajax_refresh_metadata error: ' . $metadata->get_error_message() );
                }
                wp_send_json_error( array( 'message' => $metadata->get_error_message() ) );
            }

            $count = is_array( $metadata ) ? count( $metadata ) : 0;

            // ── GUARDAR TRANSIENT (CLAVE CANÓNICA) ──────────────────────────
            // IMPORTANTE: misma clave que usa render_metabox() para leer.
            $transient_key = self::TRANSIENT_PREFIX . $post_id;
            set_transient( $transient_key, $metadata, self::TRANSIENT_TTL );

            // Guardar timestamp de actualización en post_meta (para UI)
            update_post_meta( $post_id, self::META_UPDATED, time() );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[OY GMB More] Attribute metadata cached: %d attribute(s) for post %d (transient: %s)',
                    $count,
                    $post_id,
                    $transient_key
                ) );
            }

            wp_send_json_success( array(
                'message' => sprintf(
                    /* translators: %d = número de atributos */
                    _n(
                        'Metadatos actualizados: %d atributo disponible.',
                        'Metadatos actualizados: %d atributo(s) disponible(s).',
                        $count,
                        'lealez'
                    ),
                    $count
                ),
                'count'  => $count,
                'reload' => true,
            ) );
        }

        // =====================================================================
        // AJAX: ENVIAR CAMBIOS A GOOGLE
        // =====================================================================

        /**
         * AJAX handler: envía los overrides de atributos a Google Business Profile
         * vía PATCH al endpoint de atributos.
         *
         * Action: oy_gmb_more_push_to_gmb
         */
        public function ajax_push_to_gmb() {
            $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
            if ( ! $post_id ) {
                wp_send_json_error( array( 'message' => __( 'ID de post inválido.', 'lealez' ) ) );
            }

            check_ajax_referer( 'oy_gmb_more_nonce_' . $post_id, 'nonce' );

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
            }

            $parent_business_id = (int) get_post_meta( $post_id, 'parent_business_id', true );
            $gmb_location_name  = (string) get_post_meta( $post_id, 'gmb_location_name', true );

            if ( ! $parent_business_id || '' === $gmb_location_name ) {
                wp_send_json_error( array(
                    'message' => __( 'Ubicación no conectada a Google Business Profile.', 'lealez' ),
                ) );
            }

            // Normalizar location_name
            $location_short = $gmb_location_name;
            if ( strpos( $gmb_location_name, 'accounts/' ) === 0 ) {
                $parts = explode( '/locations/', $gmb_location_name, 2 );
                if ( ! empty( $parts[1] ) ) {
                    $location_short = 'locations/' . $parts[1];
                }
            }

            // Leer transient con metadatos de atributos (para saber tipos)
            $transient_key     = self::TRANSIENT_PREFIX . $post_id;
            $attribute_schemas = get_transient( $transient_key );

            if ( is_array( $attribute_schemas ) && isset( $attribute_schemas['attributeMetadata'] ) ) {
                $attribute_schemas = $attribute_schemas['attributeMetadata'];
            }

            $schema_map = array();
            if ( is_array( $attribute_schemas ) ) {
                foreach ( $attribute_schemas as $s ) {
                    if ( is_array( $s ) && ! empty( $s['attributeId'] ) ) {
                        $schema_map[ (string) $s['attributeId'] ] = $s;
                    }
                }
            }

            // Construir payload de atributos para PATCH
            $attributes_to_push = array();
            $prefix_len         = strlen( self::META_OVERRIDE_PREFIX );

            // Obtener todos los post_meta con el prefijo de override
            global $wpdb;
            $like     = $wpdb->esc_like( self::META_OVERRIDE_PREFIX ) . '%';
            $results  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
                    $post_id,
                    $like
                )
            );

            foreach ( (array) $results as $row ) {
                $attr_id    = substr( $row->meta_key, $prefix_len );
                $attr_value = $row->meta_value;

                if ( '' === $attr_value ) {
                    continue; // Ignorar campos vacíos
                }

                $schema     = isset( $schema_map[ $attr_id ] ) ? $schema_map[ $attr_id ] : array();
                $value_type = isset( $schema['valueType'] ) ? (string) $schema['valueType'] : 'BOOL';

                $attr_resource_name = rtrim( $location_short, '/' ) . '/attributes/' . $attr_id;

                switch ( $value_type ) {
                    case 'URL':
                        $attributes_to_push[] = array(
                            'name'       => $attr_resource_name,
                            'valueType'  => 'URL',
                            'uriValues'  => array( array( 'uri' => esc_url_raw( $attr_value ) ) ),
                        );
                        break;

                    case 'BOOL':
                        $bool = filter_var( $attr_value, FILTER_VALIDATE_BOOLEAN );
                        $attributes_to_push[] = array(
                            'name'      => $attr_resource_name,
                            'valueType' => 'BOOL',
                            'values'    => array( $bool ),
                        );
                        break;

                    case 'ENUM':
                        $attributes_to_push[] = array(
                            'name'      => $attr_resource_name,
                            'valueType' => 'ENUM',
                            'values'    => array( sanitize_text_field( $attr_value ) ),
                        );
                        break;

                    case 'INTEGER':
                        $attributes_to_push[] = array(
                            'name'      => $attr_resource_name,
                            'valueType' => 'INTEGER',
                            'values'    => array( (int) $attr_value ),
                        );
                        break;

                    default:
                        $attributes_to_push[] = array(
                            'name'      => $attr_resource_name,
                            'valueType' => $value_type,
                            'values'    => array( sanitize_text_field( $attr_value ) ),
                        );
                }
            }

            if ( empty( $attributes_to_push ) ) {
                wp_send_json_error( array(
                    'message' => __( 'No hay atributos modificados para enviar.', 'lealez' ),
                ) );
            }

            // Llamar a la API de Google: PATCH attributes
            $result = $this->patch_location_attributes(
                $parent_business_id,
                $location_short,
                $attributes_to_push
            );

            if ( is_wp_error( $result ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY GMB More] ajax_push_to_gmb error: ' . $result->get_error_message() );
                }
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            // Actualizar gmb_attributes_raw con los overrides enviados
            $current_raw = get_post_meta( $post_id, 'gmb_attributes_raw', true );
            if ( ! is_array( $current_raw ) ) {
                $current_raw = array();
            }

            foreach ( $attributes_to_push as $pushed ) {
                $pushed_id = $pushed['name'];
                // Actualizar o agregar en el raw
                $found = false;
                foreach ( $current_raw as &$existing ) {
                    if ( is_array( $existing ) && isset( $existing['name'] ) && $existing['name'] === $pushed_id ) {
                        $existing = $pushed;
                        $found    = true;
                        break;
                    }
                }
                unset( $existing );
                if ( ! $found ) {
                    $current_raw[] = $pushed;
                }
            }
            update_post_meta( $post_id, 'gmb_attributes_raw', $current_raw );

            wp_send_json_success( array(
                'message' => sprintf(
                    /* translators: %d = número de atributos enviados */
                    _n(
                        '%d atributo enviado a Google correctamente.',
                        '%d atributo(s) enviado(s) a Google correctamente.',
                        count( $attributes_to_push ),
                        'lealez'
                    ),
                    count( $attributes_to_push )
                ),
            ) );
        }

        // =====================================================================
        // MÉTODOS DE API
        // =====================================================================

        /**
         * Obtiene los metadatos de atributos disponibles para una ubicación
         * desde la Business Information API v1.
         *
         * Endpoint: GET https://mybusinessbusinessinformation.googleapis.com/v1/attributes
         *           ?parent=locations/{locationId}&languageCode=es
         *
         * @param int    $business_id    WP Post ID del oy_business (para tokens OAuth).
         * @param string $location_short Resource name corto: 'locations/{id}'.
         * @return array|WP_Error        Array plano de atributo-metadata, o WP_Error.
         */
        private function get_attribute_metadata( $business_id, $location_short ) {
            if ( ! class_exists( 'Lealez_GMB_API' ) ) {
                return new WP_Error(
                    'class_missing',
                    __( 'Lealez_GMB_API no disponible.', 'lealez' )
                );
            }

            // Extraer solo el location ID numérico del resource name
            $location_id = $location_short;
            if ( strpos( $location_short, 'locations/' ) === 0 ) {
                $location_id = substr( $location_short, strlen( 'locations/' ) );
            }
            $location_id = trim( $location_id, '/' );

            // El endpoint es /v1/attributes?parent=locations/{id}
            $endpoint = '/attributes';
            $params   = array(
                'parent'       => 'locations/' . $location_id,
                'languageCode' => 'es',
            );

            $max_attempts = 2;
            $result       = null;

            for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
                $url = 'https://mybusinessbusinessinformation.googleapis.com/v1' . $endpoint
                     . '?' . http_build_query( $params );

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf(
                        '[OY GMB More] get_attribute_metadata attempt %d — URL: %s',
                        $attempt,
                        $url
                    ) );
                }

                // Usar make_request de Lealez_GMB_API (maneja OAuth, refresh tokens, etc.)
                $result = Lealez_GMB_API::make_request(
                    $business_id,
                    $endpoint . '?' . http_build_query( $params ),
                    'https://mybusinessbusinessinformation.googleapis.com/v1',
                    'GET',
                    array(),
                    false, // no usar cache del rate limiter (queremos datos frescos)
                    array() // sin readMask
                );

                if ( ! is_wp_error( $result ) ) {
                    break;
                }

                if ( $attempt < $max_attempts ) {
                    sleep( 2 );
                }
            }

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            // Extraer el array de metadatos del objeto de respuesta
            $schemas = array();

            if ( isset( $result['attributeMetadata'] ) && is_array( $result['attributeMetadata'] ) ) {
                $schemas = $result['attributeMetadata'];
            } elseif ( is_array( $result ) && ! empty( $result ) ) {
                // Respuesta directamente como array de atributos (formato alternativo)
                $schemas = $result;
            }

            // Limpiar: asegurar que todos los campos sean strings (null-safe)
            $cleaned = array();
            foreach ( $schemas as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $cleaned[] = array(
                    'attributeId'      => isset( $item['attributeId'] )      ? (string) $item['attributeId']      : '',
                    'displayName'      => isset( $item['displayName'] )      ? (string) $item['displayName']      : '',
                    'groupDisplayName' => isset( $item['groupDisplayName'] ) ? (string) $item['groupDisplayName'] : '',
                    'valueType'        => isset( $item['valueType'] )        ? (string) $item['valueType']        : 'BOOL',
                    'isRepeatable'     => ! empty( $item['isRepeatable'] ),
                    'valueMetadata'    => ( isset( $item['valueMetadata'] ) && is_array( $item['valueMetadata'] ) )
                        ? $this->sanitize_value_metadata( $item['valueMetadata'] )
                        : array(),
                );
            }

            $count = count( $cleaned );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[OY GMB More] get_attribute_metadata OK: %d attribute(s) for parent=locations/%s',
                    $count,
                    $location_id
                ) );
            }

            return $cleaned;
        }

        /**
         * Sanitiza el array valueMetadata de un atributo (null-safe).
         *
         * @param array $value_metadata
         * @return array
         */
        private function sanitize_value_metadata( array $value_metadata ) {
            $out = array();
            foreach ( $value_metadata as $vm ) {
                if ( ! is_array( $vm ) ) {
                    continue;
                }
                $out[] = array(
                    'value'       => isset( $vm['value'] )       ? (string) $vm['value']       : '',
                    'displayName' => isset( $vm['displayName'] ) ? (string) $vm['displayName'] : '',
                );
            }
            return $out;
        }

        /**
         * Envía atributos actualizados a Google vía PATCH.
         *
         * Endpoint: PATCH https://mybusinessbusinessinformation.googleapis.com/v1/locations/{id}/attributes
         *
         * @param int    $business_id
         * @param string $location_short  'locations/{id}'
         * @param array  $attributes      Array de objetos de atributo en formato API.
         * @return array|WP_Error
         */
        private function patch_location_attributes( $business_id, $location_short, array $attributes ) {
            if ( ! class_exists( 'Lealez_GMB_API' ) ) {
                return new WP_Error( 'class_missing', __( 'Lealez_GMB_API no disponible.', 'lealez' ) );
            }

            // Construir el resource name de atributos: locations/{id}/attributes
            $attributes_resource = rtrim( $location_short, '/' ) . '/attributes';

            $body = array(
                'name'       => $attributes_resource,
                'attributes' => $attributes,
            );

            // Construir updateMask con los attributeId a actualizar
            $attr_ids   = array_map( function( $a ) {
                $parts = explode( '/attributes/', $a['name'], 2 );
                return isset( $parts[1] ) ? $parts[1] : '';
            }, $attributes );
            $attr_ids   = array_filter( $attr_ids );
            $update_mask = implode( ',', array_map( function( $id ) {
                return 'attributes/' . $id;
            }, $attr_ids ) );

            $endpoint = '/' . $attributes_resource . '?updateMask=' . rawurlencode( $update_mask );

            $result = Lealez_GMB_API::make_request(
                $business_id,
                $endpoint,
                'https://mybusinessbusinessinformation.googleapis.com/v1',
                'PATCH',
                $body,
                false,
                array()
            );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                if ( is_wp_error( $result ) ) {
                    error_log( '[OY GMB More] patch_location_attributes error: ' . $result->get_error_message() );
                } else {
                    error_log( '[OY GMB More] patch_location_attributes OK for ' . $location_short );
                }
            }

            return $result;
        }

    } // class OY_Location_GMB_More_Metabox

} // if ! class_exists

// Instanciar la clase
new OY_Location_GMB_More_Metabox();
