<?php
/**
 * Elementor integration for the Lealez frontend portal.
 *
 * @package Lealez
 * @subpackage Elementor
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Lealez_Elementor_Integration' ) ) :

class Lealez_Elementor_Integration {

    /** @var bool */
    private $initialized = false;

    /** @var bool */
    private $widgets_registered = false;

    public function __construct() {
        add_action( 'elementor/init', array( $this, 'init' ) );

        if ( did_action( 'elementor/init' ) ) {
            $this->init();
        }
    }

    /**
     * Register Elementor hooks after Elementor is available.
     */
    public function init() {
        if ( $this->initialized || ! class_exists( '\\Elementor\\Widget_Base' ) ) {
            return;
        }

        $this->initialized = true;

        require_once __DIR__ . '/widgets/class-lealez-elementor-portal-widget.php';

        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );
        add_action( 'elementor/editor/before_enqueue_styles', array( $this, 'register_assets' ), 5 );
        add_action( 'elementor/preview/enqueue_styles', array( $this, 'register_assets' ), 5 );
        add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
        add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );

        // Compatibility with Elementor versions that still expose the legacy
        // registration hook.
        add_action( 'elementor/widgets/widgets_registered', array( $this, 'register_legacy_widgets' ) );
    }

    /**
     * Register shared assets early so Elementor can resolve widget dependencies
     * before rendering the shortcode-backed functional output.
     */
    public function register_assets() {
        if ( ! wp_style_is( 'lealez-frontend-portal', 'registered' ) ) {
            wp_register_style(
                'lealez-frontend-portal',
                LEALEZ_ASSETS_URL . 'css/frontend/lealez-frontend-portal.css',
                array(),
                LEALEZ_VERSION
            );
        }

        if ( ! wp_style_is( 'lealez-elementor-portal', 'registered' ) ) {
            wp_register_style(
                'lealez-elementor-portal',
                LEALEZ_ASSETS_URL . 'css/frontend/lealez-elementor-portal.css',
                array( 'lealez-frontend-portal' ),
                LEALEZ_VERSION
            );
        }

        if ( ! wp_script_is( 'lealez-frontend-portal', 'registered' ) ) {
            wp_register_script(
                'lealez-frontend-portal',
                LEALEZ_ASSETS_URL . 'js/frontend/lealez-frontend-portal.js',
                array(),
                LEALEZ_VERSION,
                true
            );
        }
    }

    /**
     * Add Lealez as a dedicated widget category.
     *
     * @param object $elements_manager Elementor elements manager.
     */
    public function register_category( $elements_manager ) {
        if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
            return;
        }

        $elements_manager->add_category(
            'lealez',
            array(
                'title' => __( 'Lealez', 'lealez' ),
                'icon'  => 'eicon-site-identity',
            )
        );
    }

    /**
     * Register all native Lealez widgets.
     *
     * @param object $widgets_manager Elementor widgets manager.
     */
    public function register_widgets( $widgets_manager ) {
        if ( $this->widgets_registered || ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
            return;
        }

        $this->widgets_registered = true;

        foreach ( $this->get_widget_instances() as $widget ) {
            $widgets_manager->register( $widget );
        }
    }

    /**
     * Legacy Elementor registration fallback.
     */
    public function register_legacy_widgets() {
        if ( $this->widgets_registered || ! class_exists( '\\Elementor\\Plugin' ) ) {
            return;
        }

        $manager = \Elementor\Plugin::instance()->widgets_manager;
        if ( ! $manager || ! method_exists( $manager, 'register_widget_type' ) ) {
            return;
        }

        $this->widgets_registered = true;
        foreach ( $this->get_widget_instances() as $widget ) {
            $manager->register_widget_type( $widget );
        }
    }

    /**
     * @return array<int,\Elementor\Widget_Base>
     */
    private function get_widget_instances() {
        return array(
            new Lealez_Elementor_Account_Dashboard_Widget(),
            new Lealez_Elementor_Business_List_Widget(),
            new Lealez_Elementor_Business_Profile_Widget(),
            new Lealez_Elementor_Location_List_Widget(),
            new Lealez_Elementor_Location_Profile_Widget(),
            new Lealez_Elementor_User_Profile_Widget(),
        );
    }
}

new Lealez_Elementor_Integration();

endif;
