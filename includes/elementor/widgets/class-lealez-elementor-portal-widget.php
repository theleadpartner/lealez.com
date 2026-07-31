<?php
/**
 * Native Elementor widgets for Lealez frontend pages.
 *
 * The widgets preserve the existing shortcodes as the rendering API so all
 * access rules, nonces, AJAX handlers, metabox bridges and save flows remain
 * unchanged. The WordPress page itself stores a native Elementor widget.
 *
 * @package Lealez
 * @subpackage Elementor
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
    return;
}

require_once __DIR__ . '/trait-lealez-elementor-profile-render.php';
require_once __DIR__ . '/trait-lealez-elementor-content-controls.php';
require_once __DIR__ . '/trait-lealez-elementor-style-controls.php';

abstract class Lealez_Elementor_Portal_Widget extends \Elementor\Widget_Base {
    use Lealez_Elementor_Profile_Render_Trait;
    use Lealez_Elementor_Content_Controls_Trait;
    use Lealez_Elementor_Style_Controls_Trait;

    /**
     * Internal screen key.
     *
     * @return string
     */
    abstract protected function get_lealez_screen();

    public function get_categories() {
        return array( 'lealez' );
    }

    public function get_keywords() {
        return array( 'lealez', 'empresa', 'ubicación', 'perfil', 'portal', 'google business profile' );
    }

    public function get_style_depends() {
        if ( ! wp_style_is( 'lealez-elementor-portal', 'registered' ) ) {
            wp_register_style(
                'lealez-elementor-portal',
                LEALEZ_ASSETS_URL . 'css/frontend/lealez-elementor-portal.css',
                array( 'lealez-frontend-portal' ),
                LEALEZ_VERSION
            );
        }
        return array( 'lealez-frontend-portal', 'lealez-elementor-portal' );
    }

    public function get_script_depends() {
        return array( 'lealez-frontend-portal' );
    }

    /**
     * Common functional and visual controls shared by all portal widgets.
     */
    protected function register_controls() {
        $this->register_lealez_content_controls();
        $this->register_lealez_style_controls();
    }
}

require_once __DIR__ . '/class-lealez-elementor-portal-widgets.php';
