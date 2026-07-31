<?php
/**
 * Legacy cleanup, redirects and URL routing.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Lealez_Frontend_Page_Routing_Trait {
    private function count_legacy_frontend_pages() {
        return count( $this->find_legacy_frontend_pages() );
    }

    /**
     * Locate only plugin-managed legacy pages, never arbitrary user pages.
     *
     * @return array<string,int>
     */
    private function find_legacy_frontend_pages() {
        $legacy = $this->get_legacy_page_definitions();
        $ids    = get_option( self::PAGE_OPTION, array() );
        $ids    = is_array( $ids ) ? $ids : array();
        $found  = array();

        foreach ( $legacy as $key => $definition ) {
            $candidates = array();
            if ( isset( $ids[ $key ] ) ) {
                $candidates[] = absint( $ids[ $key ] );
            }
            foreach ( $definition['paths'] as $path ) {
                $page = get_page_by_path( $path );
                if ( $page ) {
                    $candidates[] = (int) $page->ID;
                }
            }

            foreach ( array_unique( array_filter( $candidates ) ) as $page_id ) {
                $page = get_post( $page_id );
                if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
                    continue;
                }

                $managed_key = (string) get_post_meta( $page_id, '_lealez_frontend_page_key', true );
                $has_legacy_shortcode = has_shortcode( (string) $page->post_content, $definition['shortcode'] );

                if ( $key === $managed_key || $has_legacy_shortcode ) {
                    $found[ $key ] = $page_id;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Move superseded plugin pages to Trash. Data is recoverable from WordPress.
     *
     * @return int
     */
    private function cleanup_legacy_frontend_pages() {
        $found   = $this->find_legacy_frontend_pages();
        $ids     = get_option( self::PAGE_OPTION, array() );
        $ids     = is_array( $ids ) ? $ids : array();
        $removed = 0;

        foreach ( $found as $key => $page_id ) {
            $managed_key = (string) get_post_meta( $page_id, '_lealez_frontend_page_key', true );

            // Never trash the unified business profile when business_google is
            // temporarily stored as a compatibility alias to the same page ID.
            if ( 'business_editor' === $managed_key || 'location_editor' === $managed_key ) {
                unset( $ids[ $key ] );
                continue;
            }

            if ( wp_trash_post( $page_id ) ) {
                $removed++;
            }
            unset( $ids[ $key ] );
        }

        update_option( self::PAGE_OPTION, $ids, false );
        return $removed;
    }

    /**
     * Redirect old slugs and query arguments to their unified destination.
     */
    public function redirect_legacy_frontend_pages() {
        if ( is_admin() || wp_doing_ajax() ) {
            return;
        }

        $request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $request_path = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
        if ( '' === $request_path ) {
            return;
        }

        foreach ( $this->get_legacy_page_definitions() as $definition ) {
            foreach ( $definition['paths'] as $legacy_path ) {
                $legacy_path = trim( $legacy_path, '/' );
                if ( $request_path !== $legacy_path && substr( $request_path, -strlen( $legacy_path ) ) !== $legacy_path ) {
                    continue;
                }

                $args = $definition['target_args'];
                foreach ( array( 'business_id', 'location_id' ) as $query_key ) {
                    if ( isset( $_GET[ $query_key ] ) ) {
                        $args[ $query_key ] = absint( wp_unslash( $_GET[ $query_key ] ) );
                    }
                }
                foreach ( array( 'module', 'section' ) as $query_key ) {
                    if ( isset( $_GET[ $query_key ] ) ) {
                        $args[ $query_key ] = sanitize_key( wp_unslash( $_GET[ $query_key ] ) );
                    }
                }
                if ( isset( $definition['target_args']['section'] ) ) {
                    $args['section'] = $definition['target_args']['section'];
                }
                if ( isset( $args['module'] ) && 'location_editor' === $definition['target'] ) {
                    $args['section'] = sanitize_key( $args['module'] );
                    unset( $args['module'] );
                }

                wp_safe_redirect( $this->page_url( $definition['target'], $args ), 301 );
                exit;
            }
        }
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

    /**
     * Resolve active pages and map removed standalone screens to unified tabs.
     *
     * @param string $key Page key.
     * @param array  $args Query arguments.
     * @return string
     */
    private function page_url( $key, $args = array() ) {
        $aliases = array(
            'business_team' => array( 'target' => 'business_editor', 'args' => array( 'section' => 'team' ) ),
            'business_integrations' => array( 'target' => 'business_editor', 'args' => array( 'section' => 'integrations' ) ),
            'business_google' => array( 'target' => 'business_editor', 'args' => array( 'section' => 'google' ) ),
            'location_google' => array( 'target' => 'location_editor', 'args' => array() ),
        );

        if ( isset( $aliases[ $key ] ) ) {
            $args = array_merge( $aliases[ $key ]['args'], $args );
            $key  = $aliases[ $key ]['target'];
        }

        $status = $this->get_page_status( $key );
        $url    = $status['exists'] ? get_permalink( $status['page_id'] ) : home_url( '/' );
        return $args ? add_query_arg( $args, $url ) : $url;
    }

    private function redirect_pages_admin( $args = array() ) {
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=lealez-frontend-pages' ) ) );
        exit;
    }

    private function clear_elementor_cache() {
        if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::instance()->files_manager ) ) {
            \Elementor\Plugin::instance()->files_manager->clear_cache();
        }
    }
}
