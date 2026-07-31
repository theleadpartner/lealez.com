<?php
/**
 * Portal rendering helpers and access rules.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Lealez_Frontend_Page_Access_Trait {
    private function login_required() {
        $this->enqueue_assets();
        $redirect = is_singular() ? get_permalink() : home_url( '/' );
        return '<div class="lealez-portal"><div class="lealez-empty"><h3>' . esc_html__( 'Inicia sesión para continuar', 'lealez' ) . '</h3><p>' . esc_html__( 'Este contenido está disponible para usuarios autenticados.', 'lealez' ) . '</p><a class="lealez-btn lealez-btn-primary" href="' . esc_url( wp_login_url( $redirect ) ) . '">' . esc_html__( 'Iniciar sesión', 'lealez' ) . '</a></div></div>';
    }

    private function forbidden_panel() {
        $this->enqueue_assets();
        return '<div class="lealez-portal"><div class="lealez-empty"><h3>' . esc_html__( 'Acceso no autorizado', 'lealez' ) . '</h3><p>' . esc_html__( 'No tienes acceso a este registro o ya no está disponible.', 'lealez' ) . '</p></div></div>';
    }

    private function render_notice() {
        $code    = isset( $_GET['lealez_notice'] ) ? sanitize_key( wp_unslash( $_GET['lealez_notice'] ) ) : '';
        $missing = isset( $_GET['missing_count'] ) ? absint( $_GET['missing_count'] ) : 0;
        $messages = array(
            'business_saved'       => array( 'success', __( 'La empresa se guardó correctamente.', 'lealez' ) ),
            'business_archived'    => array( 'success', __( 'La empresa fue archivada sin eliminar sus datos.', 'lealez' ) ),
            'business_restored'    => array( 'success', __( 'La empresa volvió a estar activa.', 'lealez' ) ),
            'team_saved'           => array( 'success', __( 'El equipo se actualizó correctamente.', 'lealez' ) ),
            'team_saved_partial'   => array( 'warning', sprintf( _n( 'El equipo se guardó, pero %d correo no pertenece a un usuario existente.', 'El equipo se guardó, pero %d correos no pertenecen a usuarios existentes.', $missing, 'lealez' ), $missing ) ),
            'integrations_saved'   => array( 'success', __( 'Las preferencias de integración se guardaron.', 'lealez' ) ),
            'location_saved'       => array( 'success', __( 'La ubicación se guardó correctamente.', 'lealez' ) ),
            'location_archived'    => array( 'success', __( 'La ubicación fue archivada sin eliminar sus datos.', 'lealez' ) ),
            'location_restored'    => array( 'success', __( 'La ubicación volvió a estar activa.', 'lealez' ) ),
            'profile_saved'        => array( 'success', __( 'Tu perfil se actualizó correctamente.', 'lealez' ) ),
            'invalid'              => array( 'error', __( 'No fue posible procesar la solicitud. Revisa los datos.', 'lealez' ) ),
            'forbidden'            => array( 'error', __( 'No tienes permisos para realizar esta acción.', 'lealez' ) ),
            'email_exists'         => array( 'error', __( 'El correo indicado ya pertenece a otro usuario.', 'lealez' ) ),
            'password_mismatch'    => array( 'error', __( 'Las contraseñas no coinciden.', 'lealez' ) ),
        );

        if ( isset( $messages[ $code ] ) ) {
            echo '<div class="lealez-notice lealez-notice-' . esc_attr( $messages[ $code ][0] ) . '">' . esc_html( $messages[ $code ][1] ) . '</div>';
        }
    }

    private function can_access_business( $business_id, $user_id = 0 ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        $post = get_post( absint( $business_id ) );
        if ( ! $post || 'oy_business' !== $post->post_type || ! $user_id ) { return false; }
        if ( user_can( $user_id, 'manage_options' ) || (int) $post->post_author === $user_id ) { return true; }
        $admins = get_post_meta( $post->ID, '_admin_users', true );
        $managers = get_post_meta( $post->ID, '_manager_users', true );
        $ids = array_merge( is_array( $admins ) ? $admins : array(), is_array( $managers ) ? $managers : array() );
        return in_array( $user_id, array_map( 'intval', $ids ), true );
    }

    private function can_manage_business_team( $business_id, $user_id = 0 ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        $post = get_post( absint( $business_id ) );
        if ( ! $post || 'oy_business' !== $post->post_type || ! $user_id ) { return false; }
        if ( user_can( $user_id, 'manage_options' ) || (int) $post->post_author === $user_id ) { return true; }
        $admins = get_post_meta( $post->ID, '_admin_users', true );
        return in_array( $user_id, array_map( 'intval', is_array( $admins ) ? $admins : array() ), true );
    }

    private function can_access_location( $location_id, $user_id = 0 ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        $post = get_post( absint( $location_id ) );
        if ( ! $post || 'oy_location' !== $post->post_type || ! $user_id ) { return false; }
        if ( user_can( $user_id, 'manage_options' ) || (int) $post->post_author === $user_id ) { return true; }
        $business_id = absint( get_post_meta( $post->ID, 'parent_business_id', true ) );
        return $business_id ? $this->can_access_business( $business_id, $user_id ) : false;
    }

    private function get_accessible_businesses( $user_id = 0, $include_archived = true ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        $posts = get_posts( array( 'post_type' => 'oy_business', 'post_status' => $include_archived ? array( 'publish', 'draft', 'pending', 'private' ) : array( 'publish', 'private' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
        if ( user_can( $user_id, 'manage_options' ) ) { return $posts; }
        return array_values( array_filter( $posts, function( $post ) use ( $user_id ) { return $this->can_access_business( $post->ID, $user_id ); } ) );
    }

    private function get_accessible_locations( $user_id = 0, $include_archived = true ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        $posts = get_posts( array( 'post_type' => 'oy_location', 'post_status' => $include_archived ? array( 'publish', 'draft', 'pending', 'private' ) : array( 'publish', 'private' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
        if ( user_can( $user_id, 'manage_options' ) ) { return $posts; }
        return array_values( array_filter( $posts, function( $post ) use ( $user_id ) { return $this->can_access_location( $post->ID, $user_id ); } ) );
    }
}
