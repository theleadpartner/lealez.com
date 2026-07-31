<?php
/**
 * Frontend page definitions and Elementor detection.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Lealez_Frontend_Page_Definitions_Trait {
    public function get_page_definitions() {
        return array(
            'portal' => array(
                'title'       => __( 'Mi cuenta Lealez', 'lealez' ),
                'slug'        => 'mi-cuenta-lealez',
                'shortcode'   => '[lealez_account_dashboard]',
                'widget'      => 'lealez-account-dashboard',
                'description' => __( 'Panel principal del usuario.', 'lealez' ),
                'parent'      => '',
            ),
            'businesses' => array(
                'title'       => __( 'Mis empresas', 'lealez' ),
                'slug'        => 'mis-empresas',
                'shortcode'   => '[lealez_business_list]',
                'widget'      => 'lealez-business-list',
                'description' => __( 'Listado, creación y archivo de empresas.', 'lealez' ),
                'parent'      => 'portal',
            ),
            'business_editor' => array(
                'title'       => __( 'Perfil de empresa', 'lealez' ),
                'slug'        => 'editar-empresa',
                'shortcode'   => '[lealez_business_editor]',
                'widget'      => 'lealez-business-profile',
                'description' => __( 'Perfil empresarial unificado: información, equipo, integraciones y Google.', 'lealez' ),
                'parent'      => 'portal',
            ),
            'locations' => array(
                'title'       => __( 'Mis ubicaciones', 'lealez' ),
                'slug'        => 'mis-ubicaciones',
                'shortcode'   => '[lealez_location_list]',
                'widget'      => 'lealez-location-list',
                'description' => __( 'Listado, creación y archivo de ubicaciones.', 'lealez' ),
                'parent'      => 'portal',
            ),
            'location_editor' => array(
                'title'       => __( 'Perfil de ubicación', 'lealez' ),
                'slug'        => 'editar-ubicacion',
                'shortcode'   => '[lealez_location_editor]',
                'widget'      => 'lealez-location-profile',
                'description' => __( 'Perfil unificado con datos internos, Google Business Profile, contenido, interacción y analítica.', 'lealez' ),
                'parent'      => 'portal',
            ),
            'user_profile' => array(
                'title'       => __( 'Mi perfil', 'lealez' ),
                'slug'        => 'mi-perfil',
                'shortcode'   => '[lealez_user_profile]',
                'widget'      => 'lealez-user-profile',
                'description' => __( 'Datos personales y contraseña del usuario.', 'lealez' ),
                'parent'      => 'portal',
            ),
        );
    }

    /**
     * Standalone pages replaced by unified profile sections.
     *
     * @return array<string,array<string,mixed>>
     */
    private function get_legacy_page_definitions() {
        return array(
            'business_team' => array(
                'paths'      => array( 'mi-cuenta-lealez/equipo-empresa', 'mi-cuenta-lealez/business-team' ),
                'shortcode'  => 'lealez_business_team',
                'target'     => 'business_editor',
                'target_args'=> array( 'section' => 'team' ),
            ),
            'business_integrations' => array(
                'paths'      => array( 'mi-cuenta-lealez/integraciones-empresa', 'mi-cuenta-lealez/business-integrations' ),
                'shortcode'  => 'lealez_business_integrations',
                'target'     => 'business_editor',
                'target_args'=> array( 'section' => 'integrations' ),
            ),
            'business_google' => array(
                'paths'      => array( 'mi-cuenta-lealez/google-empresa', 'mi-cuenta-lealez/business-google' ),
                'shortcode'  => 'lealez_business_google_center',
                'target'     => 'business_editor',
                'target_args'=> array( 'section' => 'google' ),
            ),
            'location_google' => array(
                'paths'      => array( 'mi-cuenta-lealez/google-ubicacion', 'mi-cuenta-lealez/location-google' ),
                'shortcode'  => 'lealez_location_google_center',
                'target'     => 'location_editor',
                'target_args'=> array(),
            ),
        );
    }

    /**
     * Elementor is required because generated pages contain native widgets,
     * not shortcodes in post_content.
     *
     * @return bool
     */
    private function is_elementor_active() {
        return did_action( 'elementor/loaded' ) || class_exists( '\\Elementor\\Plugin' );
    }

    /**
     * Detect whether Elementor files exist even when the plugin is inactive.
     *
     * @return bool
     */
    private function is_elementor_installed() {
        if ( $this->is_elementor_active() ) {
            return true;
        }

        return defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/elementor/elementor.php' );
    }
}
