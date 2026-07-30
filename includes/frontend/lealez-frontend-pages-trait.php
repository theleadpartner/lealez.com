<?php
/**
 * Frontend page installer, shortcodes and access rules.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Lealez_Frontend_Pages_Trait {
    public function get_page_definitions() {
        return array(
            'portal' => array(
                'title' => __( 'Mi cuenta Lealez', 'lealez' ),
                'slug' => 'mi-cuenta-lealez',
                'shortcode' => '[lealez_account_dashboard]',
                'description' => __( 'Panel principal del usuario.', 'lealez' ),
                'parent' => '',
            ),
            'businesses' => array(
                'title' => __( 'Mis empresas', 'lealez' ),
                'slug' => 'mis-empresas',
                'shortcode' => '[lealez_business_list]',
                'description' => __( 'Listado, creación y archivo de empresas.', 'lealez' ),
                'parent' => 'portal',
            ),
            'business_editor' => array(
                'title' => __( 'Editar empresa', 'lealez' ),
                'slug' => 'editar-empresa',
                'shortcode' => '[lealez_business_editor]',
                'description' => __( 'Perfil empresarial organizado por pestañas.', 'lealez' ),
                'parent' => 'portal',
            ),
            'business_team' => array(
                'title' => __( 'Equipo de empresa', 'lealez' ),
                'slug' => 'equipo-empresa',
                'shortcode' => '[lealez_business_team]',
                'description' => __( 'Administradores y gerentes autorizados.', 'lealez' ),
                'parent' => 'portal',
            ),
            'business_integrations' => array(
                'title' => __( 'Integraciones de empresa', 'lealez' ),
                'slug' => 'integraciones-empresa',
                'shortcode' => '[lealez_business_integrations]',
                'description' => __( 'Preferencias seguras de Google Business Profile y Wallet.', 'lealez' ),
                'parent' => 'portal',
            ),
            'business_google' => array(
                'title' => __( 'Google de empresa', 'lealez' ),
                'slug' => 'google-empresa',
                'shortcode' => '[lealez_business_google_center]',
                'description' => __( 'Conexión, cuentas y ubicaciones sincronizadas de la empresa.', 'lealez' ),
                'parent' => 'portal',
            ),
            'locations' => array(
                'title' => __( 'Mis ubicaciones', 'lealez' ),
                'slug' => 'mis-ubicaciones',
                'shortcode' => '[lealez_location_list]',
                'description' => __( 'Listado, creación y archivo de ubicaciones.', 'lealez' ),
                'parent' => 'portal',
            ),
            'location_editor' => array(
                'title' => __( 'Editar ubicación', 'lealez' ),
                'slug' => 'editar-ubicacion',
                'shortcode' => '[lealez_location_editor]',
                'description' => __( 'Perfil de ubicación organizado por pestañas.', 'lealez' ),
                'parent' => 'portal',
            ),
            'location_google' => array(
                'title' => __( 'Google de ubicación', 'lealez' ),
                'slug' => 'google-ubicacion',
                'shortcode' => '[lealez_location_google_center]',
                'description' => __( 'Perfil, contenido, reseñas, sincronización y analítica GMB.', 'lealez' ),
                'parent' => 'portal',
            ),
            'user_profile' => array(
                'title' => __( 'Mi perfil', 'lealez' ),
                'slug' => 'mi-perfil',
                'shortcode' => '[lealez_user_profile]',
                'description' => __( 'Datos personales y contraseña del usuario.', 'lealez' ),
                'parent' => 'portal',
            ),
        );
    }

    public function register_pages_admin_menu() {
        add_submenu_page(
            'lealez',
            __( 'Páginas frontend', 'lealez' ),
            __( 'Páginas frontend', 'lealez' ),
            'manage_options',
            'lealez-frontend-pages',
            array( $this, 'render_pages_admin' )
        );
    }

    public function render_pages_admin() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para acceder.', 'lealez' ) );
        }
        $definitions = $this->get_page_definitions();
        $created = isset( $_GET['lealez_pages_created'] ) ? absint( $_GET['lealez_pages_created'] ) : 0;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Páginas frontend de Lealez', 'lealez' ); ?></h1>
            <p><?php esc_html_e( 'Las páginas se crean con un shortcode funcional. Puedes diseñarlas con Elementor manteniendo el shortcode dentro del contenido.', 'lealez' ); ?></p>
            <?php if ( $created ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( _n( 'Se creó o reparó %d página.', 'Se crearon o repararon %d páginas.', $created, 'lealez' ), $created ) ); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:18px 0;">
                <input type="hidden" name="action" value="lealez_create_all_frontend_pages">
                <?php wp_nonce_field( 'lealez_create_all_frontend_pages' ); ?>
                <?php submit_button( __( 'Crear o reparar todas las páginas', 'lealez' ), 'primary', 'submit', false ); ?>
            </form>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e( 'Página', 'lealez' ); ?></th><th><?php esc_html_e( 'Uso', 'lealez' ); ?></th><th><?php esc_html_e( 'Shortcode', 'lealez' ); ?></th><th><?php esc_html_e( 'Estado', 'lealez' ); ?></th><th><?php esc_html_e( 'Acciones', 'lealez' ); ?></th></tr></thead>
                <tbody>
                <?php foreach ( $definitions as $key => $definition ) :
                    $status = $this->get_page_status( $key );
                    $path = $definition['slug'];
                    if ( $definition['parent'] ) {
                        $path = $definitions[ $definition['parent'] ]['slug'] . '/' . $path;
                    }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $definition['title'] ); ?></strong><br><code>/<?php echo esc_html( $path ); ?>/</code></td>
                        <td><?php echo esc_html( $definition['description'] ); ?></td>
                        <td><code><?php echo esc_html( $definition['shortcode'] ); ?></code></td>
                        <td><?php if ( $status['valid'] ) : ?><span style="color:#15803d;font-weight:600;">● <?php esc_html_e( 'Lista', 'lealez' ); ?></span><?php elseif ( $status['exists'] ) : ?><span style="color:#b45309;font-weight:600;">● <?php esc_html_e( 'Requiere shortcode', 'lealez' ); ?></span><?php else : ?><span style="color:#6b7280;">● <?php esc_html_e( 'No creada', 'lealez' ); ?></span><?php endif; ?></td>
                        <td>
                            <?php if ( $status['exists'] ) : ?><a class="button" href="<?php echo esc_url( get_edit_post_link( $status['page_id'] ) ); ?>"><?php esc_html_e( 'Editar', 'lealez' ); ?></a> <a class="button" target="_blank" href="<?php echo esc_url( get_permalink( $status['page_id'] ) ); ?>"><?php esc_html_e( 'Ver', 'lealez' ); ?></a><?php endif; ?>
                            <?php if ( ! $status['valid'] ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                                    <input type="hidden" name="action" value="lealez_create_frontend_page">
                                    <input type="hidden" name="page_key" value="<?php echo esc_attr( $key ); ?>">
                                    <input type="hidden" name="repair" value="<?php echo $status['exists'] ? '1' : '0'; ?>">
                                    <?php wp_nonce_field( 'lealez_create_frontend_page_' . $key ); ?>
                                    <button class="button button-primary" type="submit"><?php echo $status['exists'] ? esc_html__( 'Agregar shortcode', 'lealez' ) : esc_html__( 'Crear página', 'lealez' ); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function get_page_status( $key ) {
        $definitions = $this->get_page_definitions();
        if ( ! isset( $definitions[ $key ] ) ) {
            return array( 'exists' => false, 'valid' => false, 'page_id' => 0 );
        }
        $ids = get_option( self::PAGE_OPTION, array() );
        $page_id = isset( $ids[ $key ] ) ? absint( $ids[ $key ] ) : 0;
        $page = $page_id ? get_post( $page_id ) : null;
        if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
            $path = $definitions[ $key ]['slug'];
            if ( $definitions[ $key ]['parent'] ) {
                $path = $definitions[ $definitions[ $key ]['parent'] ]['slug'] . '/' . $path;
            }
            $page = get_page_by_path( $path );
            if ( $page ) {
                $page_id = (int) $page->ID;
                $ids[ $key ] = $page_id;
                update_option( self::PAGE_OPTION, $ids, false );
            }
        }
        if ( ! $page ) {
            return array( 'exists' => false, 'valid' => false, 'page_id' => 0 );
        }
        return array(
            'exists' => true,
            'valid' => has_shortcode( (string) $page->post_content, trim( $definitions[ $key ]['shortcode'], '[]' ) ),
            'page_id' => (int) $page->ID,
        );
    }

    public function handle_create_frontend_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos.', 'lealez' ) );
        }
        $key = isset( $_POST['page_key'] ) ? sanitize_key( wp_unslash( $_POST['page_key'] ) ) : '';
        check_admin_referer( 'lealez_create_frontend_page_' . $key );
        $done = $this->ensure_frontend_page( $key, ! empty( $_POST['repair'] ) );
        wp_safe_redirect( add_query_arg( 'lealez_pages_created', $done ? 1 : 0, admin_url( 'admin.php?page=lealez-frontend-pages' ) ) );
        exit;
    }

    public function handle_create_all_frontend_pages() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos.', 'lealez' ) );
        }
        check_admin_referer( 'lealez_create_all_frontend_pages' );
        $count = 0;
        foreach ( array_keys( $this->get_page_definitions() ) as $key ) {
            $status = $this->get_page_status( $key );
            if ( ! $status['valid'] && $this->ensure_frontend_page( $key, $status['exists'] ) ) {
                $count++;
            }
        }
        wp_safe_redirect( add_query_arg( 'lealez_pages_created', $count, admin_url( 'admin.php?page=lealez-frontend-pages' ) ) );
        exit;
    }

    private function ensure_frontend_page( $key, $repair = false ) {
        $definitions = $this->get_page_definitions();
        if ( ! isset( $definitions[ $key ] ) ) {
            return false;
        }
        $definition = $definitions[ $key ];
        $status = $this->get_page_status( $key );
        if ( $status['exists'] ) {
            if ( $status['valid'] || ! $repair ) {
                return false;
            }
            $page = get_post( $status['page_id'] );
            if ( ! $page ) {
                return false;
            }
            $content = trim( (string) $page->post_content );
            $result = wp_update_post( array( 'ID' => $page->ID, 'post_content' => $content ? $content . "\n\n" . $definition['shortcode'] : $definition['shortcode'] ), true );
            return ! is_wp_error( $result );
        }
        $parent_id = 0;
        if ( $definition['parent'] ) {
            $parent_status = $this->get_page_status( $definition['parent'] );
            if ( ! $parent_status['exists'] ) {
                $this->ensure_frontend_page( $definition['parent'], false );
                $parent_status = $this->get_page_status( $definition['parent'] );
            }
            $parent_id = $parent_status['exists'] ? (int) $parent_status['page_id'] : 0;
        }
        $page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $definition['title'], 'post_name' => $definition['slug'], 'post_content' => $definition['shortcode'], 'post_parent' => $parent_id ), true );
        if ( is_wp_error( $page_id ) ) {
            return false;
        }
        $ids = get_option( self::PAGE_OPTION, array() );
        $ids[ $key ] = (int) $page_id;
        update_option( self::PAGE_OPTION, $ids, false );
        update_post_meta( $page_id, '_lealez_frontend_page_key', $key );
        return true;
    }

    public function register_shortcodes() {
        add_shortcode( 'lealez_account_dashboard', array( $this, 'shortcode_account_dashboard' ) );
        add_shortcode( 'lealez_business_list', array( $this, 'shortcode_business_list' ) );
        add_shortcode( 'lealez_business_editor', array( $this, 'shortcode_business_editor' ) );
        add_shortcode( 'lealez_business_team', array( $this, 'shortcode_business_team' ) );
        add_shortcode( 'lealez_business_integrations', array( $this, 'shortcode_business_integrations' ) );
        add_shortcode( 'lealez_location_list', array( $this, 'shortcode_location_list' ) );
        add_shortcode( 'lealez_location_editor', array( $this, 'shortcode_location_editor' ) );
        add_shortcode( 'lealez_user_profile', array( $this, 'shortcode_user_profile' ) );
    }

    private function enqueue_assets() {
        wp_enqueue_style( 'lealez-frontend-portal', LEALEZ_ASSETS_URL . 'css/frontend/lealez-frontend-portal.css', array(), LEALEZ_VERSION );
        wp_enqueue_script( 'lealez-frontend-portal', LEALEZ_ASSETS_URL . 'js/frontend/lealez-frontend-portal.js', array(), LEALEZ_VERSION, true );
    }

    private function page_url( $key, $args = array() ) {
        $status = $this->get_page_status( $key );
        $url = $status['exists'] ? get_permalink( $status['page_id'] ) : home_url( '/' );
        return $args ? add_query_arg( $args, $url ) : $url;
    }

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
        $code = isset( $_GET['lealez_notice'] ) ? sanitize_key( wp_unslash( $_GET['lealez_notice'] ) ) : '';
        $missing = isset( $_GET['missing_count'] ) ? absint( $_GET['missing_count'] ) : 0;
        $messages = array(
            'business_saved' => array( 'success', __( 'La empresa se guardó correctamente.', 'lealez' ) ),
            'business_archived' => array( 'success', __( 'La empresa fue archivada sin eliminar sus datos.', 'lealez' ) ),
            'business_restored' => array( 'success', __( 'La empresa volvió a estar activa.', 'lealez' ) ),
            'team_saved' => array( 'success', __( 'El equipo se actualizó correctamente.', 'lealez' ) ),
            'team_saved_partial' => array( 'warning', sprintf( _n( 'El equipo se guardó, pero %d correo no pertenece a un usuario existente.', 'El equipo se guardó, pero %d correos no pertenecen a usuarios existentes.', $missing, 'lealez' ), $missing ) ),
            'integrations_saved' => array( 'success', __( 'Las preferencias de integración se guardaron.', 'lealez' ) ),
            'location_saved' => array( 'success', __( 'La ubicación se guardó correctamente.', 'lealez' ) ),
            'location_archived' => array( 'success', __( 'La ubicación fue archivada sin eliminar sus datos.', 'lealez' ) ),
            'location_restored' => array( 'success', __( 'La ubicación volvió a estar activa.', 'lealez' ) ),
            'profile_saved' => array( 'success', __( 'Tu perfil se actualizó correctamente.', 'lealez' ) ),
            'invalid' => array( 'error', __( 'No fue posible procesar la solicitud. Revisa los datos.', 'lealez' ) ),
            'forbidden' => array( 'error', __( 'No tienes permisos para realizar esta acción.', 'lealez' ) ),
            'email_exists' => array( 'error', __( 'El correo indicado ya pertenece a otro usuario.', 'lealez' ) ),
            'password_mismatch' => array( 'error', __( 'Las contraseñas no coinciden.', 'lealez' ) ),
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
