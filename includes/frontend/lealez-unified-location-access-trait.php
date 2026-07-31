<?php
/** Unified location profile: access. @package Lealez */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Lealez_Unified_Location_Access_Trait {

    private function render_module_status_badge( $location_id, $module, $large = false ) {
            $state = $this->get_module_publish_state( $location_id, $module );
            if ( ! $state ) {
                return '';
            }
            $class = 'lealez-gmb-state is-' . sanitize_html_class( $state['class'] ) . ( $large ? ' is-large' : '' );
            return '<span class="' . esc_attr( $class ) . '">' . esc_html( $state['label'] ) . '</span>';
        }

    private function get_module_publish_state( $location_id, $module ) {
            $map = array(
                'basic'      => array( 'pending' => 'oy_basic_info_local_pending_publish', 'job' => 'gmb_basic_info_push_job' ),
                'address'    => array( 'pending' => 'oy_address_local_pending_publish', 'job' => 'gmb_address_push_job' ),
                'contact'    => array( 'pending' => 'oy_contact_local_pending_publish', 'job' => 'gmb_contact_push_job' ),
                'hours'      => array( 'pending' => 'oy_hours_local_pending_publish', 'job' => 'gmb_hours_push_job' ),
                'attributes' => array( 'pending' => 'oy_gmb_more_local_pending_publish', 'job' => 'gmb_more_push_job' ),
                'menu'       => array( 'pending' => 'oy_menu_local_pending_publish', 'job' => 'gmb_menu_push_job' ),
            );
            if ( ! isset( $map[ $module ] ) ) {
                return null;
            }
    
            $pending = (bool) get_post_meta( $location_id, $map[ $module ]['pending'], true );
            $job     = get_post_meta( $location_id, $map[ $module ]['job'], true );
            $status  = is_array( $job ) ? sanitize_key( (string) ( $job['status'] ?? '' ) ) : '';
            $statuses = array(
                'queued'           => array( 'label' => __( 'Enviado · en cola', 'lealez' ), 'class' => 'review' ),
                'pending_review'   => array( 'label' => __( 'Google está revisando', 'lealez' ), 'class' => 'review' ),
                'applied'          => array( 'label' => __( 'Aplicado en Google', 'lealez' ), 'class' => 'success' ),
                'partial'          => array( 'label' => __( 'Aplicado parcialmente', 'lealez' ), 'class' => 'warning' ),
                'rejected'         => array( 'label' => __( 'No aplicado', 'lealez' ), 'class' => 'error' ),
                'google_override'  => array( 'label' => __( 'Google devolvió otro valor', 'lealez' ), 'class' => 'warning' ),
                'timeout'          => array( 'label' => __( 'Pendiente de verificación', 'lealez' ), 'class' => 'warning' ),
                'validation_error' => array( 'label' => __( 'Requiere corregir datos', 'lealez' ), 'class' => 'error' ),
                'local_pending'    => array( 'label' => __( 'Guardado · falta enviar', 'lealez' ), 'class' => 'local' ),
                'error'            => array( 'label' => __( 'Error de envío', 'lealez' ), 'class' => 'error' ),
            );
            if ( $status && isset( $statuses[ $status ] ) ) {
                return $statuses[ $status ];
            }
            if ( $pending ) {
                return array( 'label' => __( 'Guardado · falta enviar', 'lealez' ), 'class' => 'local' );
            }
            return array( 'label' => __( 'Sin cambios pendientes', 'lealez' ), 'class' => 'neutral' );
        }

    private function requested_section() {
            if ( isset( $_GET['section'] ) ) {
                return sanitize_key( wp_unslash( $_GET['section'] ) );
            }
            if ( isset( $_GET['module'] ) ) {
                return sanitize_key( wp_unslash( $_GET['module'] ) );
            }
            return 'overview';
        }

    private function profile_url( $location_id, $section = 'overview', array $extra = array() ) {
            return $this->page_url(
                'location_editor',
                array_merge(
                    array(
                        'location_id' => absint( $location_id ),
                        'section'     => sanitize_key( $section ),
                    ),
                    $extra
                )
            );
        }

    private function get_page_ids() {
            $ids = get_option( self::PAGE_OPTION, array() );
            return is_array( $ids ) ? $ids : array();
        }

    private function page_url( $key, $args = array() ) {
            $ids     = $this->get_page_ids();
            $page_id = isset( $ids[ $key ] ) ? absint( $ids[ $key ] ) : 0;
            $url     = $page_id && 'trash' !== get_post_status( $page_id ) ? get_permalink( $page_id ) : home_url( '/' );
            return ! empty( $args ) ? add_query_arg( $args, $url ) : $url;
        }

    private function is_location_profile_page() {
            if ( ! is_singular( 'page' ) ) {
                return false;
            }
            $ids = $this->get_page_ids();
            return ! empty( $ids['location_editor'] ) && (int) $ids['location_editor'] === (int) get_queried_object_id();
        }

    private function is_legacy_location_google_page() {
            if ( ! is_singular( 'page' ) ) {
                return false;
            }
            $ids = $this->get_page_ids();
            return ! empty( $ids['location_google'] ) && (int) $ids['location_google'] === (int) get_queried_object_id();
        }

    private function can_access_business( $business_id, $user_id = 0 ) {
            $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
            $post    = get_post( absint( $business_id ) );
            if ( ! $post || 'oy_business' !== $post->post_type || ! $user_id ) {
                return false;
            }
            if ( $this->is_site_admin( $user_id ) || (int) $post->post_author === $user_id ) {
                return true;
            }
            $admins   = get_post_meta( $post->ID, '_admin_users', true );
            $managers = get_post_meta( $post->ID, '_manager_users', true );
            $ids      = array_merge( is_array( $admins ) ? $admins : array(), is_array( $managers ) ? $managers : array() );
            return in_array( $user_id, array_map( 'intval', $ids ), true );
        }

    private function can_manage_business( $business_id, $user_id = 0 ) {
            $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
            $post    = get_post( absint( $business_id ) );
            if ( ! $post || 'oy_business' !== $post->post_type || ! $user_id ) {
                return false;
            }
            if ( $this->is_site_admin( $user_id ) || (int) $post->post_author === $user_id ) {
                return true;
            }
            $admins = get_post_meta( $post->ID, '_admin_users', true );
            return in_array( $user_id, array_map( 'intval', is_array( $admins ) ? $admins : array() ), true );
        }

    private function can_access_location( $location_id, $user_id = 0 ) {
            $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
            $post    = get_post( absint( $location_id ) );
            if ( ! $post || 'oy_location' !== $post->post_type || ! $user_id ) {
                return false;
            }
            if ( $this->is_site_admin( $user_id ) || (int) $post->post_author === $user_id ) {
                return true;
            }
            $business_id = absint( get_post_meta( $post->ID, 'parent_business_id', true ) );
            return $business_id ? $this->can_access_business( $business_id, $user_id ) : false;
        }

    private function can_manage_location_business( $location_id, $user_id = 0 ) {
            $business_id = absint( get_post_meta( $location_id, 'parent_business_id', true ) );
            return $business_id ? $this->can_manage_business( $business_id, $user_id ) : $this->is_site_admin( $user_id );
        }

    private function get_accessible_businesses() {
            $user_id = get_current_user_id();
            $posts   = get_posts( array( 'post_type' => 'oy_business', 'post_status' => array( 'publish', 'private' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
            if ( $this->is_site_admin( $user_id ) ) {
                return $posts;
            }
            return array_values( array_filter( $posts, function( $post ) use ( $user_id ) { return $this->can_access_business( $post->ID, $user_id ); } ) );
        }

    private function update_business_location_count( $business_id ) {
            $business_id = absint( $business_id );
            if ( ! $business_id ) {
                return;
            }
            $ids = get_posts(
                array(
                    'post_type'      => 'oy_location',
                    'post_status'    => array( 'publish', 'private' ),
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'meta_key'       => 'parent_business_id',
                    'meta_value'     => $business_id,
                )
            );
            update_post_meta( $business_id, '_total_locations', count( $ids ) );
        }

    private function is_site_admin( $user_id = 0 ) {
            $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
            if ( ! $user_id ) {
                return false;
            }
            if ( is_multisite() && is_super_admin( $user_id ) ) {
                return true;
            }
            $user = get_userdata( $user_id );
            return $user instanceof WP_User && ! empty( $user->allcaps['manage_options'] );
        }

    private function forbidden_panel() {
            return '<div class="lealez-portal"><div class="lealez-empty"><h3>' . esc_html__( 'Acceso no autorizado', 'lealez' ) . '</h3><p>' . esc_html__( 'No tienes acceso a esta ubicación.', 'lealez' ) . '</p></div></div>';
        }
}
