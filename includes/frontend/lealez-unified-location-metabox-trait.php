<?php
/** Unified location profile: metabox. @package Lealez */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Lealez_Unified_Location_Metabox_Trait {

    public function handle_classic_metabox_save() {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Debes iniciar sesión.', 'lealez' ) );
        }

        $post_id   = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
        $post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
        $module    = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
        $nonce     = isset( $_POST['lealez_frontend_gmb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lealez_frontend_gmb_nonce'] ) ) : '';

        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'lealez_frontend_save_gmb_metabox_' . $post_id ) ) {
            wp_die( esc_html__( 'La solicitud no es válida.', 'lealez' ) );
        }
        if ( 'oy_location' !== $post_type || ! $this->can_access_location( $post_id ) ) {
            wp_die( esc_html__( 'No tienes permisos para guardar este módulo.', 'lealez' ) );
        }
        if ( ! in_array( $module, array( 'sync', 'services' ), true ) ) {
            return;
        }

        $definitions = $this->get_location_modules();
        if ( empty( $definitions[ $module ] ) || ! $this->can_view_location_module( $post_id, $module, $definitions[ $module ] ) ) {
            wp_die( esc_html__( 'No tienes permisos para guardar este módulo.', 'lealez' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_die( esc_html__( 'Ubicación no encontrada.', 'lealez' ) );
        }

        wp_update_post( array( 'ID' => $post_id ) );
        wp_safe_redirect( $this->profile_url( $post_id, $module, array( 'gmb_notice' => 'local_saved' ) ) );
        exit;
    }

    private function render_query_notice() {
        $profile_notice = isset( $_GET['profile_notice'] ) ? sanitize_key( wp_unslash( $_GET['profile_notice'] ) ) : '';
        $gmb_notice     = isset( $_GET['gmb_notice'] ) ? sanitize_key( wp_unslash( $_GET['gmb_notice'] ) ) : '';

        $messages = array(
            'profile_unified' => array( 'success', __( 'El perfil de ubicación ahora se administra en una sola página. La sección solicitada fue conservada.', 'lealez' ) ),
            'internal_saved'  => array( 'success', __( 'Los datos internos se guardaron en Lealez. No se envió información a Google.', 'lealez' ) ),
            'invalid'         => array( 'error', __( 'No fue posible guardar los datos internos. Revisa los campos obligatorios.', 'lealez' ) ),
        );

        if ( isset( $messages[ $profile_notice ] ) ) {
            echo '<div class="lealez-notice lealez-notice-' . esc_attr( $messages[ $profile_notice ][0] ) . '">' . esc_html( $messages[ $profile_notice ][1] ) . '</div>';
        }
        if ( 'local_saved' === $gmb_notice ) {
            echo '<div class="lealez-notice lealez-notice-success">' . esc_html__( 'La configuración se guardó localmente. Esto no significa que Google la haya publicado; usa el botón de envío o sincronización del módulo y verifica el estado.', 'lealez' ) . '</div>';
        }
    }

    private function render_embedded_metabox( $post_id, $definition ) {
        $post = get_post( $post_id );
        if ( ! $post || 'oy_location' !== $post->post_type || empty( $definition['metabox_id'] ) ) {
            return '<div class="lealez-empty"><h3>' . esc_html__( 'Módulo no disponible', 'lealez' ) . '</h3></div>';
        }

        $module = $this->definition_key_from_id( $definition['metabox_id'] );
        if ( ! $module || ! $this->can_view_location_module( $post_id, $module, $definition ) ) {
            return $this->forbidden_panel();
        }

        $box = $this->find_metabox( 'oy_location', $definition['metabox_id'], $post );
        if ( ! $box || empty( $box['callback'] ) || ! is_callable( $box['callback'] ) ) {
            return '<div class="lealez-empty"><h3>' . esc_html__( 'No se pudo cargar el módulo', 'lealez' ) . '</h3><p>' . esc_html__( 'El componente original no está registrado en esta instalación.', 'lealez' ) . '</p></div>';
        }

        $this->invoke_metabox_asset_methods( $box['callback'], $post );
        $this->schedule_footer_asset_methods( $box['callback'], $post );

        $business_id   = absint( get_post_meta( $post_id, 'parent_business_id', true ) );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );

        ob_start();
        ?>
        <form id="post" class="lealez-gmb-metabox-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="lealez_frontend_save_gmb_metabox">
            <input type="hidden" name="post_id" id="post_ID" value="<?php echo esc_attr( $post_id ); ?>">
            <input type="hidden" name="post_type" value="oy_location">
            <input type="hidden" name="module" value="<?php echo esc_attr( $module ); ?>">
            <?php wp_nonce_field( 'lealez_frontend_save_gmb_metabox_' . $post_id, 'lealez_frontend_gmb_nonce' ); ?>
            <input type="hidden" name="parent_business_id" id="parent_business_id" value="<?php echo esc_attr( $business_id ); ?>">
            <input type="hidden" name="gmb_location_name" id="gmb_location_name" value="<?php echo esc_attr( $location_name ); ?>">

            <div id="<?php echo esc_attr( $definition['metabox_id'] ); ?>" class="postbox lealez-embedded-metabox<?php echo ! empty( $definition['read_only'] ) ? ' is-read-only' : ''; ?>">
                <div class="inside">
                    <?php
                    $this->with_post_context(
                        $post,
                        function() use ( $box, $post ) {
                            call_user_func( $box['callback'], $post, $box );
                        }
                    );
                    ?>
                </div>
            </div>

            <?php if ( ! empty( $definition['read_only'] ) ) : ?>
                <div class="lealez-gmb-readonly-note"><span class="dashicons dashicons-lock"></span><p><?php esc_html_e( 'Esta información se muestra en modo consulta. Para evitar asociaciones accidentales, solo el administrador del sitio puede cambiarla desde la administración técnica.', 'lealez' ); ?></p></div>
                <?php if ( $this->is_site_admin() ) : ?><a class="lealez-btn lealez-btn-secondary" href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php esc_html_e( 'Abrir administración técnica', 'lealez' ); ?></a><?php endif; ?>
            <?php elseif ( ! empty( $definition['classic_save'] ) ) : ?>
                <div class="lealez-form-footer"><button class="lealez-btn lealez-btn-primary" type="submit"><?php esc_html_e( 'Guardar configuración local', 'lealez' ); ?></button></div>
            <?php endif; ?>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    private function prepare_module_assets( $post_id, $module ) {
        $definitions = $this->get_location_modules();
        if ( empty( $definitions[ $module ]['metabox_id'] ) || ! $this->can_view_location_module( $post_id, $module, $definitions[ $module ] ) ) {
            return;
        }
        $post = get_post( $post_id );
        if ( ! $post || 'oy_location' !== $post->post_type ) {
            return;
        }
        $box = $this->find_metabox( 'oy_location', $definitions[ $module ]['metabox_id'], $post );
        if ( $box && ! empty( $box['callback'] ) ) {
            $this->invoke_metabox_asset_methods( $box['callback'], $post );
            $this->schedule_footer_asset_methods( $box['callback'], $post );
        }
    }

    private function definition_key_from_id( $metabox_id ) {
        foreach ( $this->get_location_modules() as $key => $definition ) {
            if ( isset( $definition['metabox_id'] ) && $metabox_id === $definition['metabox_id'] ) {
                return $key;
            }
        }
        return '';
    }

    private function find_metabox( $post_type, $metabox_id, $post ) {
        $this->register_metaboxes_for_post( $post );
        global $wp_meta_boxes;
        if ( empty( $wp_meta_boxes[ $post_type ] ) || ! is_array( $wp_meta_boxes[ $post_type ] ) ) {
            return null;
        }
        foreach ( $wp_meta_boxes[ $post_type ] as $contexts ) {
            if ( ! is_array( $contexts ) ) { continue; }
            foreach ( $contexts as $priorities ) {
                if ( ! is_array( $priorities ) ) { continue; }
                if ( isset( $priorities[ $metabox_id ] ) ) {
                    return $priorities[ $metabox_id ];
                }
            }
        }
        return null;
    }

    private function register_metaboxes_for_post( $post ) {
        $key = $post->post_type . ':' . $post->ID;
        if ( isset( $this->registered_metaboxes[ $key ] ) ) {
            return;
        }
        $this->registered_metaboxes[ $key ] = true;
        $this->with_post_context(
            $post,
            function() use ( $post ) {
                do_action( 'add_meta_boxes', $post->post_type, $post );
                do_action( 'add_meta_boxes_' . $post->post_type, $post );
            }
        );
    }

    private function invoke_metabox_asset_methods( $callback, $post ) {
        if ( ! is_array( $callback ) || ! is_object( $callback[0] ) ) {
            return;
        }
        $object = $callback[0];
        foreach ( array( 'enqueue_assets', 'enqueue_scripts', 'enqueue_admin_scripts', 'admin_scripts' ) as $method ) {
            if ( ! method_exists( $object, $method ) ) {
                continue;
            }
            $key = spl_object_hash( $object ) . ':' . $method . ':' . $post->ID;
            if ( isset( $this->invoked_asset_methods[ $key ] ) ) {
                continue;
            }
            $reflection = new ReflectionMethod( $object, $method );
            if ( ! $reflection->isPublic() || $reflection->getNumberOfRequiredParameters() > 1 ) {
                continue;
            }
            $this->invoked_asset_methods[ $key ] = true;
            $this->with_post_context(
                $post,
                function() use ( $object, $method, $reflection ) {
                    if ( 0 === $reflection->getNumberOfParameters() ) {
                        $object->{$method}();
                    } else {
                        $object->{$method}( 'post.php' );
                    }
                }
            );
        }
    }

    private function schedule_footer_asset_methods( $callback, $post ) {
        if ( ! is_array( $callback ) || ! is_object( $callback[0] ) ) {
            return;
        }
        $object     = $callback[0];
        $reflection = new ReflectionObject( $object );
        foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
            $name = $method->getName();
            if ( 0 !== $method->getNumberOfRequiredParameters() ) {
                continue;
            }
            if ( false === strpos( $name, 'footer_assets' ) && false === strpos( $name, 'inline_assets' ) ) {
                continue;
            }
            $key = spl_object_hash( $object ) . ':' . $name . ':' . $post->ID;
            foreach ( $this->footer_asset_callbacks as $registered ) {
                if ( $registered['key'] === $key ) {
                    continue 2;
                }
            }
            $this->footer_asset_callbacks[] = array( 'key' => $key, 'object' => $object, 'method' => $name, 'post' => $post );
        }
    }

    public function render_deferred_footer_assets() {
        foreach ( $this->footer_asset_callbacks as $callback ) {
            $this->with_post_context(
                $callback['post'],
                function() use ( $callback ) {
                    call_user_func( array( $callback['object'], $callback['method'] ) );
                }
            );
        }
    }

    private function with_post_context( $target_post, $callback ) {
        global $post, $post_type, $typenow, $current_screen;
        $previous_post           = $post;
        $previous_post_type      = $post_type;
        $previous_typenow        = $typenow;
        $previous_current_screen = isset( $current_screen ) ? $current_screen : null;

        $post      = $target_post;
        $post_type = $target_post->post_type;
        $typenow   = $target_post->post_type;

        if ( ! class_exists( 'WP_Screen' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-screen.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        }
        if ( class_exists( 'WP_Screen' ) ) {
            $screen            = WP_Screen::get( 'post' );
            $screen->post_type = $target_post->post_type;
            $screen->base      = 'post';
            $screen->id        = $target_post->post_type;
            $current_screen    = $screen;
        }

        try {
            return call_user_func( $callback );
        } finally {
            $post           = $previous_post;
            $post_type      = $previous_post_type;
            $typenow        = $previous_typenow;
            $current_screen = $previous_current_screen;
        }
    }
}
