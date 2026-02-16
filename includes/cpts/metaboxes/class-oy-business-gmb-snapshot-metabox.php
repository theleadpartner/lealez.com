<?php
/**
 * Oy Business - GMB Snapshot Metabox
 *
 * Muestra un resumen "solo lectura" de:
 * - Cuentas sincronizadas con Google Business Profile
 * - Ubicaciones disponibles + campos clave para reconocerlas (verificación, IDs, ciudad/país, etc.)
 *
 * @package Lealez
 * @subpackage CPTs/Metaboxes
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Lealez_OY_Business_GMB_Snapshot_Metabox' ) ) {

    class Lealez_OY_Business_GMB_Snapshot_Metabox {

        /**
         * Constructor
         */
        public function __construct() {
            add_action('add_meta_boxes', array($this, 'register_metabox'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
            
            // Registrar manejador AJAX para crear ubicación
            add_action('wp_ajax_create_location_from_gmb', array($this, 'ajax_create_location_from_gmb'));
        }

        /**
         * Enqueue scripts and styles for the metabox
         *
         * @param string $hook Current admin page hook
         * @return void
         */
        public function enqueue_scripts( $hook ) {
            // Solo cargar en la página de edición de oy_business
            if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
                return;
            }

            global $post;
            if ( ! $post || 'oy_business' !== $post->post_type ) {
                return;
            }

            // Enqueue JavaScript
            wp_enqueue_script(
                'lealez-gmb-snapshot-metabox',
                LEALEZ_PLUGIN_URL . 'assets/js/admin/gmb-snapshot-metabox.js',
                array( 'jquery' ),
                LEALEZ_VERSION,
                true
            );

            // Localizar script con datos necesarios
            wp_localize_script(
                'lealez-gmb-snapshot-metabox',
                'gmbSnapshotData',
                array(
                    'ajaxurl'    => admin_url( 'admin-ajax.php' ),
                    'businessId' => $post->ID,
                    'nonce'      => wp_create_nonce( 'gmb_snapshot_nonce' )
                )
            );
        }

        /**
         * Register metabox
         *
         * @param WP_Post $post
         * @return void
         */
        public function register_metabox( $post ) {
            add_meta_box(
                'lealez_business_gmb_snapshot',
                __( '📍 GMB - Cuentas y Ubicaciones Sincronizadas', 'lealez' ),
                array( $this, 'render_metabox' ),
                'oy_business',
                'normal',
                'default'
            );
        }

        /**
         * Render metabox
         *
         * @param WP_Post $post
         * @return void
         */
        public function render_metabox( $post ) {
            $business_id = (int) $post->ID;

            $gmb_connected            = (bool) get_post_meta( $business_id, '_gmb_connected', true );
            $gmb_account_email        = (string) get_post_meta( $business_id, '_gmb_account_email', true );
            $gmb_account_name         = (string) get_post_meta( $business_id, '_gmb_account_name', true );

            $accounts                 = get_post_meta( $business_id, '_gmb_accounts', true );
            $locations                = get_post_meta( $business_id, '_gmb_locations_available', true );

            $accounts_last_fetch      = (int) get_post_meta( $business_id, '_gmb_accounts_last_fetch', true );
            $locations_last_fetch     = (int) get_post_meta( $business_id, '_gmb_locations_last_fetch', true );

            $total_accounts           = (int) get_post_meta( $business_id, '_gmb_total_accounts', true );
            $total_locations          = (int) get_post_meta( $business_id, '_gmb_total_locations_available', true );

            $next_scheduled_refresh   = (int) get_post_meta( $business_id, '_gmb_next_scheduled_refresh', true );

            if ( ! is_array( $accounts ) ) {
                $accounts = array();
            }
            if ( ! is_array( $locations ) ) {
                $locations = array();
            }

            // Agrupar ubicaciones por cuenta si existe el campo (lo agregamos desde la API)
            $locations_by_account = array();
            foreach ( $locations as $loc ) {
                if ( ! is_array( $loc ) ) {
                    continue;
                }
                $acc = isset( $loc['account_name'] ) ? (string) $loc['account_name'] : 'unknown';
                if ( ! isset( $locations_by_account[ $acc ] ) ) {
                    $locations_by_account[ $acc ] = array();
                }
                $locations_by_account[ $acc ][] = $loc;
            }

            ?>
            <div class="lealez-gmb-snapshot-wrap">

                <?php if ( $gmb_connected ) : ?>
                    <div class="notice notice-success inline" style="margin: 0 0 10px 0;">
                        <p><strong><?php esc_html_e( 'Conectado a Google Business Profile', 'lealez' ); ?></strong></p>
                        <?php if ( $gmb_account_name ) : ?>
                            <p><?php esc_html_e( 'Cuenta (label):', 'lealez' ); ?> <strong><?php echo esc_html( $gmb_account_name ); ?></strong></p>
                        <?php endif; ?>
                        <?php if ( $gmb_account_email ) : ?>
                            <p><?php esc_html_e( 'Email:', 'lealez' ); ?> <strong><?php echo esc_html( $gmb_account_email ); ?></strong></p>
                        <?php endif; ?>
                    </div>
                <?php else : ?>
                    <div class="notice notice-warning inline" style="margin: 0 0 10px 0;">
                        <p><strong><?php esc_html_e( 'No hay conexión activa con Google Business Profile.', 'lealez' ); ?></strong></p>
                        <p><?php esc_html_e( 'Conecta la cuenta en el metabox "Google My Business" y luego presiona "Actualizar Ubicaciones".', 'lealez' ); ?></p>
                    </div>
                <?php endif; ?>

                <!-- Resumen -->
                <div class="notice notice-info inline" style="margin: 0 0 10px 0;">
                    <p><strong><?php esc_html_e( 'Resumen de Sincronización (cache local)', 'lealez' ); ?></strong></p>
                    <table style="width:100%; border-collapse: collapse;">
                        <tr>
                            <td style="width: 220px;"><strong><?php esc_html_e( 'Cuentas detectadas:', 'lealez' ); ?></strong></td>
                            <td><?php echo esc_html( $total_accounts ? $total_accounts : count( $accounts ) ); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Ubicaciones detectadas:', 'lealez' ); ?></strong></td>
                            <td><?php echo esc_html( $total_locations ? $total_locations : count( $locations ) ); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Última sync de cuentas:', 'lealez' ); ?></strong></td>
                            <td>
                                <?php
                                echo $accounts_last_fetch
                                    ? esc_html( human_time_diff( $accounts_last_fetch, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'lealez' ) )
                                    : '<span style="color:#999;">' . esc_html__( 'No disponible', 'lealez' ) . '</span>';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Última sync de ubicaciones:', 'lealez' ); ?></strong></td>
                            <td>
                                <?php
                                echo $locations_last_fetch
                                    ? esc_html( human_time_diff( $locations_last_fetch, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'lealez' ) )
                                    : '<span style="color:#999;">' . esc_html__( 'No disponible', 'lealez' ) . '</span>';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Próximo refresh programado:', 'lealez' ); ?></strong></td>
                            <td>
                                <?php
                                echo $next_scheduled_refresh
                                    ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_scheduled_refresh ) )
                                    : '<span style="color:#999;">' . esc_html__( 'No', 'lealez' ) . '</span>';
                                ?>
                            </td>
                        </tr>
                    </table>
                    <p class="description" style="margin-top:8px;">
                        <?php esc_html_e( 'Este panel NO hace llamadas a Google. Solo muestra lo que ya está guardado en el post_meta. Para actualizar, usa el botón "Actualizar Ubicaciones".', 'lealez' ); ?>
                    </p>
                </div>

                <!-- Cuentas -->
                <h4 style="margin: 15px 0 8px;"><?php esc_html_e( 'Cuentas sincronizadas', 'lealez' ); ?></h4>

                <?php if ( empty( $accounts ) ) : ?>
                    <div class="notice notice-warning inline" style="margin: 0 0 10px 0;">
                        <p><strong><?php esc_html_e( 'No hay cuentas guardadas en cache.', 'lealez' ); ?></strong></p>
                        <p><?php esc_html_e( 'Conéctate y presiona "Actualizar Ubicaciones" para cargar las cuentas y ubicaciones desde Google.', 'lealez' ); ?></p>
                    </div>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
                        <thead>
                            <tr>
                                <th style="width: 25%;"><?php esc_html_e( 'Nombre de la Cuenta', 'lealez' ); ?></th>
                                <th style="width: 20%;"><?php esc_html_e( 'Tipo', 'lealez' ); ?></th>
                                <th style="width: 20%;"><?php esc_html_e( 'Rol', 'lealez' ); ?></th>
                                <th style="width: 35%;"><?php esc_html_e( 'Account ID (name)', 'lealez' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $accounts as $account ) : ?>
                                <?php
                                if ( ! is_array( $account ) ) {
                                    continue;
                                }
                                $acc_name = $account['account_name'] ?? '-';
                                $acc_type = $account['type'] ?? '-';
                                $acc_role = $account['role'] ?? '-';
                                $acc_id   = $account['account_id'] ?? '-';
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $acc_name ); ?></strong></td>
                                    <td><?php echo esc_html( $acc_type ); ?></td>
                                    <td><?php echo esc_html( $acc_role ); ?></td>
                                    <td style="font-family: monospace; font-size: 11px; color: #666;"><?php echo esc_html( $acc_id ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <!-- Ubicaciones -->
                <h4 style="margin: 15px 0 8px;"><?php esc_html_e( 'Ubicaciones sincronizadas', 'lealez' ); ?></h4>

                <?php if ( empty( $locations ) ) : ?>
                    <div class="notice notice-warning inline" style="margin: 0 0 10px 0;">
                        <p><strong><?php esc_html_e( 'No hay ubicaciones guardadas en cache.', 'lealez' ); ?></strong></p>
                        <p><?php esc_html_e( 'Presiona "Actualizar Ubicaciones" para cargar las ubicaciones desde Google.', 'lealez' ); ?></p>
                    </div>
                <?php else : ?>
                    <?php $this->render_locations_table( $business_id, $locations ); ?>
                <?php endif; ?>

            </div>
            <?php
        }

        /**
         * Manejador AJAX para crear un CPT oy_location desde datos de GMB
         * ✅ CORRECCIÓN COMPLETA: Todos los meta fields SIN prefijo _ (excepto los de oy_business)
         */
        public function ajax_create_location_from_gmb() {
            // Verificar nonce
            check_ajax_referer('gmb_snapshot_nonce', 'nonce');
            
            // Verificar permisos
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(array(
                    'message' => 'No tienes permisos para crear ubicaciones.'
                ));
            }
            
            // Obtener datos del POST
            $business_id = isset($_POST['business_id']) ? intval($_POST['business_id']) : 0;
            $gmb_name = isset($_POST['gmb_name']) ? sanitize_text_field($_POST['gmb_name']) : '';
            $gmb_title = isset($_POST['gmb_title']) ? sanitize_text_field($_POST['gmb_title']) : '';
            
            if (!$business_id || empty($gmb_name)) {
                wp_send_json_error(array(
                    'message' => 'Datos inválidos.'
                ));
            }
            
            // Verificar que el business existe
            if (get_post_type($business_id) !== 'oy_business') {
                wp_send_json_error(array(
                    'message' => 'El negocio no existe.'
                ));
            }
            
            // ✅ Meta fields de oy_business SÍ llevan prefijo _
            $gmb_locations = get_post_meta($business_id, '_gmb_locations_available', true);
            if (empty($gmb_locations) || !is_array($gmb_locations)) {
                wp_send_json_error(array(
                    'message' => 'No hay ubicaciones de GMB disponibles.'
                ));
            }
            
            // Buscar la ubicación por el campo 'name'
            $gmb_location = null;
            foreach ($gmb_locations as $loc) {
                if (isset($loc['name']) && $loc['name'] === $gmb_name) {
                    $gmb_location = $loc;
                    break;
                }
            }
            
            if (!$gmb_location) {
                wp_send_json_error(array(
                    'message' => 'Ubicación no encontrada en los datos de GMB.'
                ));
            }
            
            // ✅ VERIFICACIÓN MEJORADA: Buscar si ya existe usando múltiples criterios
            $existing_location = $this->find_existing_location_cpt($business_id, $gmb_name, $gmb_location);
            
            if ($existing_location) {
                wp_send_json_error(array(
                    'message' => 'Ya existe una ubicación con estos datos de GMB.',
                    'location_id' => $existing_location->ID,
                    'edit_url' => get_edit_post_link($existing_location->ID, 'raw')
                ));
            }
            
            // Crear el CPT oy_location como borrador
            $location_title = !empty($gmb_title) ? $gmb_title : (!empty($gmb_location['title']) ? $gmb_location['title'] : 'Ubicación GMB');
            
            $location_post = array(
                'post_title'   => sanitize_text_field($location_title),
                'post_type'    => 'oy_location',
                'post_status'  => 'draft',
                'post_author'  => get_current_user_id(),
            );
            
            $location_id = wp_insert_post($location_post);
            
            if (is_wp_error($location_id)) {
                wp_send_json_error(array(
                    'message' => 'Error al crear la ubicación: ' . $location_id->get_error_message()
                ));
            }
            
            // ✅ CORRECCIÓN CRÍTICA: Meta fields de oy_location NO llevan prefijo _
            // (excepto campos de sistema como _date_created que se agregan al final)
            
            // Campos básicos
            update_post_meta($location_id, 'parent_business_id', $business_id);
            update_post_meta($location_id, 'location_name', sanitize_text_field($location_title));
            update_post_meta($location_id, 'location_status', 'active');
            
            // GMB identification fields
            update_post_meta($location_id, 'gmb_location_name', sanitize_text_field($gmb_name));
            
            // Extraer y guardar el location_id desde el campo 'name' (formato: accounts/XXX/locations/YYY)
            if (preg_match('/locations\/([^\/]+)$/', $gmb_name, $matches)) {
                update_post_meta($location_id, 'gmb_location_id', sanitize_text_field($matches[1]));
            }
            
            // Extraer y guardar el account_id
            if (isset($gmb_location['account_id'])) {
                update_post_meta($location_id, 'gmb_account_id', sanitize_text_field($gmb_location['account_id']));
            } elseif (preg_match('/accounts\/([^\/]+)\//', $gmb_name, $matches)) {
                update_post_meta($location_id, 'gmb_account_id', sanitize_text_field($matches[1]));
            }
            
            // ✅ Popular dirección si está disponible
            if (!empty($gmb_location['storefrontAddress'])) {
                $address = $gmb_location['storefrontAddress'];
                if (is_array($address)) {
                    // Guardar dirección formateada
                    $formatted_address = $this->format_address_compact($address);
                    if ($formatted_address) {
                        update_post_meta($location_id, 'location_formatted_address', sanitize_text_field($formatted_address));
                    }
                    
                    // Guardar componentes individuales
                    if (!empty($address['locality'])) {
                        update_post_meta($location_id, 'location_city', sanitize_text_field($address['locality']));
                    }
                    if (!empty($address['administrativeArea'])) {
                        update_post_meta($location_id, 'location_state', sanitize_text_field($address['administrativeArea']));
                    }
                    if (!empty($address['regionCode'])) {
                        update_post_meta($location_id, 'location_country', sanitize_text_field($address['regionCode']));
                    }
                    if (!empty($address['postalCode'])) {
                        update_post_meta($location_id, 'location_postal_code', sanitize_text_field($address['postalCode']));
                    }
                    
                    // Dirección línea 1 (addressLines[0])
                    if (!empty($address['addressLines'][0])) {
                        update_post_meta($location_id, 'location_address_line1', sanitize_text_field($address['addressLines'][0]));
                    }
                    
                    // Dirección línea 2 (addressLines[1] si existe)
                    if (!empty($address['addressLines'][1])) {
                        update_post_meta($location_id, 'location_address_line2', sanitize_text_field($address['addressLines'][1]));
                    }
                }
            } elseif (!empty($gmb_location['address'])) {
                $address = $gmb_location['address'];
                if (is_array($address)) {
                    $formatted_address = $this->format_address_compact($address);
                    if ($formatted_address) {
                        update_post_meta($location_id, 'location_formatted_address', sanitize_text_field($formatted_address));
                    }
                }
            }
            
            // ✅ Popular teléfono si está disponible
            if (!empty($gmb_location['phoneNumbers']['primaryPhone'])) {
                update_post_meta($location_id, 'location_phone', sanitize_text_field($gmb_location['phoneNumbers']['primaryPhone']));
            }
            
            // ✅ Popular website si está disponible
            if (!empty($gmb_location['websiteUri'])) {
                update_post_meta($location_id, 'location_website', esc_url_raw($gmb_location['websiteUri']));
            }
            
            // ✅ Popular categoría principal si está disponible
            if (!empty($gmb_location['primaryCategory']['displayName'])) {
                update_post_meta($location_id, 'google_primary_category', sanitize_text_field($gmb_location['primaryCategory']['displayName']));
            } elseif (!empty($gmb_location['categories']['primaryCategory']['displayName'])) {
                update_post_meta($location_id, 'google_primary_category', sanitize_text_field($gmb_location['categories']['primaryCategory']['displayName']));
            }
            
            // ✅ Popular estado de verificación
            $is_verified = 0;
            if (isset($gmb_location['verificationState'])) {
                $is_verified = ($gmb_location['verificationState'] === 'VERIFIED') ? 1 : 0;
            } elseif (isset($gmb_location['locationState']['verificationState'])) {
                $is_verified = ($gmb_location['locationState']['verificationState'] === 'VERIFIED') ? 1 : 0;
            } elseif (isset($gmb_location['locationState']['isVerified'])) {
                $is_verified = (bool) $gmb_location['locationState']['isVerified'] ? 1 : 0;
            }
            update_post_meta($location_id, 'gmb_verified', $is_verified);
            
            // ✅ Guardar metadata (placeId, mapsUri, etc.)
            if (!empty($gmb_location['metadata']['placeId'])) {
                update_post_meta($location_id, 'location_place_id', sanitize_text_field($gmb_location['metadata']['placeId']));
            }
            if (!empty($gmb_location['metadata']['mapsUri'])) {
                update_post_meta($location_id, 'location_map_url', esc_url_raw($gmb_location['metadata']['mapsUri']));
            }
            if (!empty($gmb_location['metadata']['newReviewUri'])) {
                update_post_meta($location_id, 'google_reviews_url', esc_url_raw($gmb_location['metadata']['newReviewUri']));
            }
            
            // ✅ Marcar timestamp de sincronización
            update_post_meta($location_id, 'gmb_last_sync', current_time('timestamp'));
            
            // ✅ Fechas del sistema (estos SÍ pueden llevar prefijo _ si es convención interna)
            update_post_meta($location_id, 'date_created', current_time('mysql'));
            update_post_meta($location_id, 'created_by_user_id', get_current_user_id());
            
            // ✅ Incrementar contador en el business padre (meta field de oy_business SÍ lleva prefijo _)
            $total_locations = get_post_meta($business_id, '_total_locations', true);
            $total_locations = $total_locations ? intval($total_locations) + 1 : 1;
            update_post_meta($business_id, '_total_locations', $total_locations);
            
            // Retornar éxito con URL de edición
            wp_send_json_success(array(
                'message' => 'Ubicación creada exitosamente como borrador.',
                'location_id' => $location_id,
                'location_name' => $location_title,
                'edit_url' => get_edit_post_link($location_id, 'raw')
            ));
        }

        /**
         * ✅ NUEVA FUNCIÓN: Buscar CPT oy_location existente usando múltiples criterios
         * 
         * Busca por:
         * 1. gmb_location_name (campo completo "accounts/XXX/locations/YYY")
         * 2. gmb_location_id + parent_business_id (combinación única)
         * 3. location_place_id + parent_business_id (si existe place_id)
         *
         * @param int    $business_id   ID del business padre
         * @param string $gmb_name      Nombre GMB completo (accounts/XXX/locations/YYY)
         * @param array  $gmb_location  Datos completos de la ubicación GMB
         * @return WP_Post|null
         */
        private function find_existing_location_cpt($business_id, $gmb_name, $gmb_location) {
            // Criterio 1: Buscar por gmb_location_name exacto
            $args = array(
                'post_type'      => 'oy_location',
                'post_status'    => array('publish', 'draft', 'pending', 'private'),
                'posts_per_page' => 1,
                'meta_query'     => array(
                    array(
                        'key'     => 'gmb_location_name',
                        'value'   => $gmb_name,
                        'compare' => '='
                    )
                )
            );
            
            $query = new WP_Query($args);
            if ($query->have_posts()) {
                return $query->posts[0];
            }
            
            // Criterio 2: Buscar por gmb_location_id + parent_business_id
            $location_id = null;
            if (preg_match('/locations\/([^\/]+)$/', $gmb_name, $matches)) {
                $location_id = $matches[1];
            }
            
            if ($location_id) {
                $args = array(
                    'post_type'      => 'oy_location',
                    'post_status'    => array('publish', 'draft', 'pending', 'private'),
                    'posts_per_page' => 1,
                    'meta_query'     => array(
                        'relation' => 'AND',
                        array(
                            'key'     => 'gmb_location_id',
                            'value'   => $location_id,
                            'compare' => '='
                        ),
                        array(
                            'key'     => 'parent_business_id',
                            'value'   => $business_id,
                            'compare' => '='
                        )
                    )
                );
                
                $query = new WP_Query($args);
                if ($query->have_posts()) {
                    return $query->posts[0];
                }
            }
            
            // Criterio 3: Buscar por location_place_id + parent_business_id (si existe)
            $place_id = null;
            if (!empty($gmb_location['metadata']['placeId'])) {
                $place_id = $gmb_location['metadata']['placeId'];
            }
            
            if ($place_id) {
                $args = array(
                    'post_type'      => 'oy_location',
                    'post_status'    => array('publish', 'draft', 'pending', 'private'),
                    'posts_per_page' => 1,
                    'meta_query'     => array(
                        'relation' => 'AND',
                        array(
                            'key'     => 'location_place_id',
                            'value'   => $place_id,
                            'compare' => '='
                        ),
                        array(
                            'key'     => 'parent_business_id',
                            'value'   => $business_id,
                            'compare' => '='
                        )
                    )
                );
                
                $query = new WP_Query($args);
                if ($query->have_posts()) {
                    return $query->posts[0];
                }
            }
            
            // No se encontró ninguna ubicación existente
            return null;
        }

        /**
         * Render locations table with "Ficha" column
         *
         * @param int   $business_id Business post ID
         * @param array $locations   Array of location data
         * @return void
         */
        private function render_locations_table( $business_id, $locations ) {
            ?>
            <table class="wp-list-table widefat fixed striped lealez-gmb-locations-table" style="margin-bottom: 20px;">
                <thead>
                    <tr>
                        <th style="width: 15%;"><?php esc_html_e( 'Título', 'lealez' ); ?></th>
                        <th style="width: 18%;"><?php esc_html_e( 'IDs (name, placeId)', 'lealez' ); ?></th>
                        <th style="width: 12%;"><?php esc_html_e( 'Verificación', 'lealez' ); ?></th>
                        <th style="width: 15%;"><?php esc_html_e( 'Ciudad/País', 'lealez' ); ?></th>
                        <th style="width: 15%;"><?php esc_html_e( 'Contacto', 'lealez' ); ?></th>
                        <th style="width: 12%;"><?php esc_html_e( 'Categoría', 'lealez' ); ?></th>
                        <th style="width: 13%;"><?php esc_html_e( 'Ficha', 'lealez' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $locations as $loc ) :
                        if ( ! is_array( $loc ) ) {
                            continue;
                        }

                        // Extract location data
                        $title      = $loc['title'] ?? '';
                        $name       = $loc['name'] ?? '';
                        $store_code = $loc['storeCode'] ?? '';

                        // Address
                        $address = isset( $loc['storefrontAddress'] ) && is_array( $loc['storefrontAddress'] )
                            ? $loc['storefrontAddress']
                            : ( isset( $loc['address'] ) && is_array( $loc['address'] ) ? $loc['address'] : array() );

                        $city    = $address['locality'] ?? '';
                        $country = $address['regionCode'] ?? '';

                        // Verification
                        $verification_state       = '';
                        $verification_state_label = '';
                        $is_verified              = null;

                        if ( isset( $loc['verificationState'] ) ) {
                            $verification_state = (string) $loc['verificationState'];
                            switch ( $verification_state ) {
                                case 'VERIFIED':
                                    $is_verified              = true;
                                    $verification_state_label = __( 'Verificada', 'lealez' );
                                    break;
                                case 'UNVERIFIED':
                                    $is_verified              = false;
                                    $verification_state_label = __( 'No verificada', 'lealez' );
                                    break;
                                case 'PENDING':
                                    $is_verified              = null;
                                    $verification_state_label = __( 'Pendiente', 'lealez' );
                                    break;
                            }
                        } elseif ( isset( $loc['locationState'] ) && is_array( $loc['locationState'] ) ) {
                            $verification_state = $loc['locationState']['verificationState'] ?? '';
                            if ( 'VERIFIED' === $verification_state ) {
                                $is_verified              = true;
                                $verification_state_label = __( 'Verificada', 'lealez' );
                            } elseif ( 'UNVERIFIED' === $verification_state ) {
                                $is_verified              = false;
                                $verification_state_label = __( 'No verificada', 'lealez' );
                            } elseif ( 'PENDING' === $verification_state ) {
                                $is_verified = null;
                            } else {
                                $is_verified = null;
                            }
                        } else {
                            $location_state = isset( $loc['locationState'] ) && is_array( $loc['locationState'] ) ? $loc['locationState'] : array();
                            if ( array_key_exists( 'isVerified', $location_state ) ) {
                                $is_verified = (bool) $location_state['isVerified'];
                            }
                        }

                        $metadata   = isset( $loc['metadata'] ) && is_array( $loc['metadata'] ) ? $loc['metadata'] : array();
                        $place_id   = $metadata['placeId'] ?? '';
                        $maps_uri   = $metadata['mapsUri'] ?? '';
                        $review_uri = $metadata['newReviewUri'] ?? '';

                        // Contacto
                        $phone_numbers = isset( $loc['phoneNumbers'] ) && is_array( $loc['phoneNumbers'] ) ? $loc['phoneNumbers'] : array();
                        $primary_phone = $phone_numbers['primaryPhone'] ?? '';
                        $website       = $loc['websiteUri'] ?? '';

                        // Categoría
                        $primary_cat = '';
                        if ( isset( $loc['primaryCategory'] ) && is_array( $loc['primaryCategory'] ) ) {
                            $primary_cat = $loc['primaryCategory']['displayName'] ?? ( $loc['primaryCategory']['name'] ?? '' );
                        } elseif ( isset( $loc['categories']['primaryCategory'] ) && is_array( $loc['categories']['primaryCategory'] ) ) {
                            $primary_cat = $loc['categories']['primaryCategory']['displayName'] ?? ( $loc['categories']['primaryCategory']['name'] ?? '' );
                        }

                        // ✅ CORRECCIÓN: Usar el nuevo método mejorado de búsqueda
                        $location_cpt = $this->find_existing_location_cpt($business_id, $name, $loc);
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $title ? $title : '-' ); ?></strong>
                                <?php if ( $store_code ) : ?>
                                    <div style="color:#666; font-size: 12px;">
                                        <?php esc_html_e( 'StoreCode:', 'lealez' ); ?>
                                        <span style="font-family: monospace;"><?php echo esc_html( $store_code ); ?></span>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td style="font-size: 12px;">
                                <?php if ( $name ) : ?>
                                    <div><strong>name:</strong> <span style="font-family: monospace;"><?php echo esc_html( $name ); ?></span></div>
                                <?php endif; ?>
                                <?php if ( $place_id ) : ?>
                                    <div><strong>placeId:</strong> <span style="font-family: monospace;"><?php echo esc_html( $place_id ); ?></span></div>
                                <?php endif; ?>
                                <?php if ( ! $name && ! $place_id ) : ?>
                                    <span style="color:#999;">-</span>
                                <?php endif; ?>
                                <?php if ( $maps_uri ) : ?>
                                    <div><a href="<?php echo esc_url( $maps_uri ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Maps', 'lealez' ); ?></a></div>
                                <?php endif; ?>
                                <?php if ( $review_uri ) : ?>
                                    <div><a href="<?php echo esc_url( $review_uri ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Review Link', 'lealez' ); ?></a></div>
                                <?php endif; ?>
                            </td>

                            <td style="font-size: 12px;">
                                <div>
                                    <strong><?php esc_html_e( 'Verified:', 'lealez' ); ?></strong>
                                    <?php echo $this->format_bool_badge( $is_verified ); ?>

                                    <?php if ( $verification_state_label ) : ?>
                                        <span style="margin-left:6px; color:#666;">
                                            (<?php echo esc_html( $verification_state_label ); ?>)
                                            <span style="font-family: monospace; color:#999;"><?php echo esc_html( $verification_state ); ?></span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td style="font-size: 12px;">
                                <div><strong><?php esc_html_e( 'Ciudad:', 'lealez' ); ?></strong> <?php echo esc_html( $city ? $city : '-' ); ?></div>
                                <div><strong><?php esc_html_e( 'País:', 'lealez' ); ?></strong> <?php echo esc_html( $country ? $country : '-' ); ?></div>
                                <?php
                                $addr_line = $this->format_address_compact( $address );
                                if ( $addr_line ) :
                                ?>
                                    <div style="color:#666; margin-top: 4px;">
                                        <?php echo esc_html( $addr_line ); ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td style="font-size: 12px;">
                                <div><strong><?php esc_html_e( 'Tel:', 'lealez' ); ?></strong> <?php echo esc_html( $primary_phone ? $primary_phone : '-' ); ?></div>
                                <div><strong><?php esc_html_e( 'Web:', 'lealez' ); ?></strong>
                                    <?php if ( $website ) : ?>
                                        <a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $website ); ?></a>
                                    <?php else : ?>
                                        <span style="color:#999;">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td style="font-size: 12px;">
                                <?php echo esc_html( $primary_cat ? $primary_cat : '-' ); ?>
                            </td>

                            <td style="text-align: center; padding: 8px;">
                                <?php if ( $location_cpt ) : ?>
                                    <!-- ✅ Ficha exists -->
                                    <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <span style="display: inline-block; width: 12px; height: 12px; background-color: #46b450; border-radius: 50%;"></span>
                                            <span style="font-size: 12px; color: #46b450; font-weight: 600;"><?php esc_html_e( 'Creado', 'lealez' ); ?></span>
                                        </div>
                                        <a href="<?php echo esc_url( get_edit_post_link( $location_cpt->ID ) ); ?>" class="button button-small button-secondary">
                                            <?php esc_html_e( 'Ver', 'lealez' ); ?>
                                        </a>
                                    </div>
                                <?php else : ?>
                                    <!-- ✅ Ficha does NOT exist -->
                                    <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <span style="display: inline-block; width: 12px; height: 12px; background-color: #dc3232; border-radius: 50%;"></span>
                                            <span style="font-size: 12px; color: #dc3232; font-weight: 600;"><?php esc_html_e( 'No creado', 'lealez' ); ?></span>
                                        </div>
                                        <button type="button" class="button button-small button-primary create-location-btn" 
                                                data-business-id="<?php echo esc_attr( $business_id ); ?>"
                                                data-gmb-name="<?php echo esc_attr( $name ); ?>"
                                                data-gmb-title="<?php echo esc_attr( $title ); ?>">
                                            <?php esc_html_e( 'Crear', 'lealez' ); ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }

        /**
         * Get oy_location CPT by GMB name (DEPRECATED - usar find_existing_location_cpt())
         * ✅ Mantenido por compatibilidad pero ya no se usa
         *
         * @param string $gmb_name GMB location name (e.g., "accounts/123/locations/456")
         * @return WP_Post|null
         */
        private function get_location_cpt_by_gmb_name( $gmb_name ) {
            if ( empty( $gmb_name ) ) {
                return null;
            }

            $args = array(
                'post_type'      => 'oy_location',
                'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                'posts_per_page' => 1,
                'meta_query'     => array(
                    array(
                        'key'     => 'gmb_location_name',
                        'value'   => $gmb_name,
                        'compare' => '='
                    )
                )
            );

            $query = new WP_Query( $args );

            if ( $query->have_posts() ) {
                return $query->posts[0];
            }

            return null;
        }

        /**
         * Format compact address line
         *
         * @param array $address
         * @return string
         */
        private function format_address_compact( $address ) {
            if ( ! is_array( $address ) ) {
                return '';
            }

            $parts = array();

            if ( ! empty( $address['addressLines'] ) && is_array( $address['addressLines'] ) ) {
                $parts[] = implode( ', ', array_map( 'sanitize_text_field', $address['addressLines'] ) );
            }

            if ( ! empty( $address['locality'] ) ) {
                $parts[] = sanitize_text_field( $address['locality'] );
            }

            if ( ! empty( $address['administrativeArea'] ) ) {
                $parts[] = sanitize_text_field( $address['administrativeArea'] );
            }

            if ( ! empty( $address['postalCode'] ) ) {
                $parts[] = sanitize_text_field( $address['postalCode'] );
            }

            return trim( implode( ' | ', $parts ) );
        }

        /**
         * Format boolean badge
         *
         * @param bool|null $value
         * @return string HTML (safe)
         */
        private function format_bool_badge( $value ) {
            if ( is_null( $value ) ) {
                return '<span style="color:#999;">-</span>';
            }

            if ( true === $value ) {
                return '<span style="color:#46b450; font-weight:600;">✓</span>';
            }

            return '<span style="color:#dc3232; font-weight:600;">✗</span>';
        }
    }
}

// Boot
new Lealez_OY_Business_GMB_Snapshot_Metabox();
