<?php
/** Unified location profile: routing. @package Lealez */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Lealez_Unified_Location_Routing_Trait {

    public function __construct() {
        add_action( 'template_redirect', array( $this, 'redirect_legacy_google_location_page' ), 1 );
        add_action( 'template_redirect', array( $this, 'handle_internal_profile_save' ), 4 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_page_assets' ), 25 );
        add_action( 'wp_footer', array( $this, 'render_deferred_footer_assets' ), 4 );

        // Runs before the legacy frontend GMB handler (priority 10) so classic
        // metabox saves return to the unified location profile.
        add_action( 'admin_post_lealez_frontend_save_gmb_metabox', array( $this, 'handle_classic_metabox_save' ), 5 );

        // Runs after Lealez_Frontend_GMB_Center (priority 20) so the previous
        // split-screen banners can be replaced without changing its handlers.
        add_filter( 'do_shortcode_tag', array( $this, 'replace_portal_output' ), 40, 4 );
    }

    /**
     * Load the portal, metabox compatibility and active module assets before
     * wp_head when the generated Editar ubicación page is being viewed.
     */
    public function enqueue_page_assets() {
        if ( ! $this->is_location_profile_page() && ! $this->is_legacy_location_google_page() ) {
            return;
        }

        $this->enqueue_common_assets();

        $location_id = isset( $_GET['location_id'] ) ? absint( wp_unslash( $_GET['location_id'] ) ) : 0;
        if ( ! $location_id || ! $this->can_access_location( $location_id ) ) {
            return;
        }

        $section = $this->requested_section();
        $modules = $this->get_location_modules();
        if ( ! isset( $modules[ $section ] ) || ! $this->can_view_location_module( $location_id, $section, $modules[ $section ] ) ) {
            $section = 'overview';
        }

        $this->prepare_module_assets( $location_id, $section );
    }

    private function enqueue_common_assets() {
        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'jquery-ui-sortable' );
        wp_enqueue_style( 'dashicons' );

        if ( function_exists( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }

        if ( ! wp_style_is( 'wp-admin', 'registered' ) ) {
            wp_register_style(
                'wp-admin',
                LEALEZ_ASSETS_URL . 'css/frontend/lealez-gmb-admin-compat.css',
                array( 'dashicons' ),
                LEALEZ_VERSION
            );
        }
        wp_enqueue_style( 'wp-admin' );

        wp_enqueue_style(
            'lealez-frontend-portal',
            LEALEZ_ASSETS_URL . 'css/frontend/lealez-frontend-portal.css',
            array(),
            LEALEZ_VERSION
        );
        wp_enqueue_style(
            'lealez-frontend-gmb-center',
            LEALEZ_ASSETS_URL . 'css/frontend/lealez-frontend-gmb-center.css',
            array( 'lealez-frontend-portal', 'wp-admin', 'dashicons' ),
            LEALEZ_VERSION
        );
        wp_enqueue_style(
            'lealez-frontend-unified-location',
            LEALEZ_ASSETS_URL . 'css/frontend/lealez-frontend-unified-location.css',
            array( 'lealez-frontend-gmb-center' ),
            LEALEZ_VERSION
        );

        wp_enqueue_script(
            'lealez-frontend-portal',
            LEALEZ_ASSETS_URL . 'js/frontend/lealez-frontend-portal.js',
            array(),
            LEALEZ_VERSION,
            true
        );
        wp_enqueue_script(
            'lealez-frontend-gmb-center',
            LEALEZ_ASSETS_URL . 'js/frontend/lealez-frontend-gmb-center.js',
            array( 'jquery' ),
            LEALEZ_VERSION,
            true
        );
        wp_enqueue_script(
            'lealez-frontend-unified-location',
            LEALEZ_ASSETS_URL . 'js/frontend/lealez-frontend-unified-location.js',
            array( 'jquery', 'lealez-frontend-gmb-center' ),
            LEALEZ_VERSION,
            true
        );

        wp_localize_script(
            'lealez-frontend-gmb-center',
            'lealezGmbFrontend',
            array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) )
        );
        wp_localize_script(
            'lealez-frontend-unified-location',
            'lealezUnifiedLocation',
            array(
                'googleLabel' => __( 'Se puede publicar', 'lealez' ),
                'localLabel'  => __( 'Solo en Lealez', 'lealez' ),
                'mixedLabel'  => __( 'Lealez + Google', 'lealez' ),
            )
        );
    }

    public function redirect_legacy_google_location_page() {
        if ( ! $this->is_legacy_location_google_page() ) {
            return;
        }

        $location_id = isset( $_GET['location_id'] ) ? absint( wp_unslash( $_GET['location_id'] ) ) : 0;
        $business_id = isset( $_GET['business_id'] ) ? absint( wp_unslash( $_GET['business_id'] ) ) : 0;
        $module      = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : 'overview';

        if ( $location_id ) {
            wp_safe_redirect(
                $this->page_url(
                    'location_editor',
                    array(
                        'location_id'    => $location_id,
                        'section'        => $module,
                        'profile_notice' => 'profile_unified',
                    )
                )
            );
            exit;
        }

        wp_safe_redirect(
            $this->page_url(
                'locations',
                array_filter(
                    array(
                        'business_id'    => $business_id,
                        'profile_notice' => 'profile_unified',
                    )
                )
            )
        );
        exit;
    }

    public function replace_portal_output( $output, $tag, $attr, $match ) {
        if ( ! is_user_logged_in() ) {
            return $output;
        }

        if ( 'lealez_location_editor' === $tag ) {
            $location_id = isset( $_GET['location_id'] ) ? absint( wp_unslash( $_GET['location_id'] ) ) : 0;
            if ( $location_id && $this->can_access_location( $location_id ) ) {
                return $this->render_unified_location_profile( $location_id );
            }

            // A metabox push needs an existing post ID. The first creation step
            // remains on the same URL and clearly identifies what will later be
            // managed by Google and what remains internal.
            return $this->render_creation_scope_notice() . $output;
        }

        if ( 'lealez_location_list' === $tag ) {
            return $this->remove_legacy_location_bridge( $output );
        }

        if ( 'lealez_location_google_center' === $tag ) {
            $location_id = isset( $_GET['location_id'] ) ? absint( wp_unslash( $_GET['location_id'] ) ) : 0;
            if ( $location_id && $this->can_access_location( $location_id ) ) {
                return $this->render_unified_location_profile( $location_id );
            }
            return '<div class="lealez-portal"><div class="lealez-empty"><h3>' . esc_html__( 'El perfil de ubicación ahora está unificado', 'lealez' ) . '</h3><p>' . esc_html__( 'Selecciona una ubicación desde el listado para administrar su información y Google Business Profile en una sola pantalla.', 'lealez' ) . '</p><a class="lealez-btn lealez-btn-primary" href="' . esc_url( $this->page_url( 'locations' ) ) . '">' . esc_html__( 'Ver ubicaciones', 'lealez' ) . '</a></div></div>';
        }

        if ( 'lealez_business_google_center' === $tag ) {
            $ids = $this->get_page_ids();
            if ( ! empty( $ids['location_google'] ) && ! empty( $ids['locations'] ) ) {
                $legacy_url    = get_permalink( absint( $ids['location_google'] ) );
                $locations_url = get_permalink( absint( $ids['locations'] ) );
                if ( $legacy_url && $locations_url ) {
                    $output = str_replace( array( $legacy_url, esc_url( $legacy_url ) ), array( $locations_url, esc_url( $locations_url ) ), $output );
                }
            }
        }

        return $output;
    }

    private function remove_legacy_location_bridge( $output ) {
        $clean = preg_replace(
            '/^<div class="lealez-portal lealez-gmb-bridge">.*?<\/a><\/div>(?=<div class="lealez-portal")/s',
            '',
            (string) $output,
            1
        );
        return is_string( $clean ) ? $clean : $output;
    }

    private function render_creation_scope_notice() {
        ob_start();
        ?>
        <div class="lealez-portal lealez-unified-create-notice">
            <div class="lealez-sync-legend">
                <div><span class="lealez-sync-chip is-google-write"><?php esc_html_e( 'Se puede publicar', 'lealez' ); ?></span><p><?php esc_html_e( 'Se guarda primero en Lealez. Después de crear la ubicación podrás revisar cada sección, enviar los cambios compatibles y verificar el resultado.', 'lealez' ); ?></p></div>
                <div><span class="lealez-sync-chip is-internal"><?php esc_html_e( 'Solo en Lealez', 'lealez' ); ?></span><p><?php esc_html_e( 'Datos administrativos, de lealtad o responsables que no se publican en Google Business Profile.', 'lealez' ); ?></p></div>
            </div>
            <div class="lealez-gmb-guidance is-info"><strong><?php esc_html_e( 'Creación inicial', 'lealez' ); ?></strong><p><?php esc_html_e( 'Primero se crea la ficha en Lealez. Después continuarás en el perfil completo, donde las opciones se adaptan al tipo de negocio y a las capacidades disponibles en Google.', 'lealez' ); ?></p></div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
