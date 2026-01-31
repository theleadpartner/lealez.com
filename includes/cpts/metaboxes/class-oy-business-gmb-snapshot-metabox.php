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
            // Solo para el CPT oy_business (hook específico)
            add_action( 'add_meta_boxes_oy_business', array( $this, 'register_metabox' ) );
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
                        <p><?php esc_html_e( 'Presiona "Actualizar Ubicaciones" para traer cuentas y ubicaciones.', 'lealez' ); ?></p>
                    </div>
                <?php else : ?>
                    <table class="widefat striped" style="margin-bottom: 15px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Account Name (label)', 'lealez' ); ?></th>
                                <th><?php esc_html_e( 'Resource Name (ID)', 'lealez' ); ?></th>
                                <th><?php esc_html_e( 'Type', 'lealez' ); ?></th>
                                <th><?php esc_html_e( 'Role / State', 'lealez' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $accounts as $acc ) : ?>
                                <?php if ( ! is_array( $acc ) ) { continue; } ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $acc['accountName'] ?? '-' ); ?></strong>
                                    </td>
                                    <td style="font-family: monospace;">
                                        <?php echo esc_html( $acc['name'] ?? '-' ); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html( $acc['type'] ?? '-' ); ?>
                                    </td>
                                    <td>
                                        <?php
                                        $role  = $acc['role'] ?? '';
                                        $state = $acc['state'] ?? '';
                                        $out   = array();

                                        if ( $role ) {
                                            $out[] = 'role=' . $role;
                                        }
                                        if ( $state ) {
                                            $out[] = 'state=' . $state;
                                        }

                                        echo esc_html( ! empty( $out ) ? implode( ' | ', $out ) : '-' );
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <!-- Ubicaciones -->
                <h4 style="margin: 15px 0 8px;"><?php esc_html_e( 'Ubicaciones sincronizadas', 'lealez' ); ?></h4>

                <?php if ( empty( $locations ) ) : ?>
                    <div class="notice notice-warning inline" style="margin: 0;">
                        <p><strong><?php esc_html_e( 'No hay ubicaciones guardadas en cache.', 'lealez' ); ?></strong></p>
                        <p><?php esc_html_e( 'Presiona "Actualizar Ubicaciones" para traer la data.', 'lealez' ); ?></p>
                    </div>
                <?php else : ?>

                    <?php
                    // Si no hay account_name en las locations (cache viejo), mostramos “sin agrupar”.
                    $has_account_grouping = ( count( $locations_by_account ) > 1 || isset( $locations_by_account['unknown'] ) === false );
                    ?>

                    <?php if ( $has_account_grouping ) : ?>
                        <?php foreach ( $locations_by_account as $acc_name => $acc_locations ) : ?>
                            <div style="margin: 12px 0 6px;">
                                <strong><?php esc_html_e( 'Cuenta:', 'lealez' ); ?></strong>
                                <span style="font-family: monospace;"><?php echo esc_html( $acc_name ); ?></span>
                                <span style="color:#666;">(<?php echo esc_html( count( $acc_locations ) ); ?>)</span>
                            </div>

                            <?php $this->render_locations_table( $acc_locations ); ?>

                        <?php endforeach; ?>
                    <?php else : ?>
                        <?php $this->render_locations_table( $locations ); ?>
                    <?php endif; ?>

                    <p class="description" style="margin-top:10px;">
                        <?php esc_html_e( 'Nota: si acabas de aplicar el cambio de readMask, debes refrescar ubicaciones para que aparezcan campos como verificación (locationState) y metadata (placeId/mapsUri).', 'lealez' ); ?>
                    </p>

                <?php endif; ?>

            </div>
            <?php
        }

        /**
         * Render locations table
         *
         * @param array $locations
         * @return void
         */
private function render_locations_table( $locations ) {
    ?>
    <table class="widefat striped" style="margin-bottom: 15px;">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Título / Nombre', 'lealez' ); ?></th>
                <th><?php esc_html_e( 'IDs', 'lealez' ); ?></th>
                <th><?php esc_html_e( 'Verificación / Estado', 'lealez' ); ?></th>
                <th><?php esc_html_e( 'Ciudad / País', 'lealez' ); ?></th>
                <th><?php esc_html_e( 'Contacto', 'lealez' ); ?></th>
                <th><?php esc_html_e( 'Categoría', 'lealez' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $locations as $loc ) : ?>
                <?php
                if ( ! is_array( $loc ) ) {
                    continue;
                }

                $title      = $loc['title'] ?? '';
                $name       = $loc['name'] ?? ''; // resource name
                $store_code = $loc['storeCode'] ?? '';

                $address = isset( $loc['storefrontAddress'] ) && is_array( $loc['storefrontAddress'] ) ? $loc['storefrontAddress'] : array();
                $city    = $address['locality'] ?? '';
                $country = $address['regionCode'] ?? '';

                /**
                 * ✅ VERIFIED (solo este campo queda)
                 *
                 * Fuente principal: My Business Verifications API (guardado en $loc['verification'])
                 * Fallback: locationState.isVerified si existe en cache (puede venir vacío si el readMask usado fue mínimo)
                 */
                $verification       = isset( $loc['verification'] ) && is_array( $loc['verification'] ) ? $loc['verification'] : array();
                $verification_state = isset( $verification['state'] ) ? (string) $verification['state'] : '';

                // Map a etiqueta legible
                $verification_state_label = '';
                if ( $verification_state ) {
                    $map = array(
                        'VERIFIED' => __( 'Verificada', 'lealez' ),
                        'PENDING'  => __( 'Pendiente', 'lealez' ),
                        'FAILED'   => __( 'Fallida', 'lealez' ),
                    );
                    $verification_state_label = isset( $map[ $verification_state ] ) ? $map[ $verification_state ] : $verification_state;
                }

                // Determinar boolean final del badge:
                // - Si tenemos enum: VERIFIED => true, PENDING/FAILED => false
                // - Si no tenemos enum, usamos locationState.isVerified si está
                $is_verified = null;

                if ( $verification_state ) {
                    $is_verified = ( 'VERIFIED' === $verification_state );
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
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
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
