<?php
/**
 * Elementor page creation and migration.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Lealez_Frontend_Page_Installer_Trait {
    public function handle_create_frontend_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos.', 'lealez' ) );
        }

        $key = isset( $_POST['page_key'] ) ? sanitize_key( wp_unslash( $_POST['page_key'] ) ) : '';
        check_admin_referer( 'lealez_create_frontend_page_' . $key );

        if ( ! $this->is_elementor_active() ) {
            $this->redirect_pages_admin( array( 'lealez_pages_error' => 'elementor_required' ) );
        }

        $done = $this->ensure_frontend_page( $key, ! empty( $_POST['repair'] ) );
        $this->redirect_pages_admin( array( 'lealez_pages_created' => $done ? 1 : 0 ) );
    }

    public function handle_create_all_frontend_pages() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos.', 'lealez' ) );
        }

        check_admin_referer( 'lealez_create_all_frontend_pages' );

        if ( ! $this->is_elementor_active() ) {
            $this->redirect_pages_admin( array( 'lealez_pages_error' => 'elementor_required' ) );
        }

        $count = 0;
        foreach ( array_keys( $this->get_page_definitions() ) as $key ) {
            $status = $this->get_page_status( $key );
            if ( ! $status['valid'] && $this->ensure_frontend_page( $key, $status['exists'] ) ) {
                $count++;
            }
        }

        $removed = $this->cleanup_legacy_frontend_pages();
        $this->sync_compatibility_page_aliases();
        $this->clear_elementor_cache();

        $this->redirect_pages_admin(
            array(
                'lealez_pages_created' => $count,
                'lealez_pages_removed' => $removed,
            )
        );
    }

    public function handle_cleanup_frontend_pages() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos.', 'lealez' ) );
        }

        check_admin_referer( 'lealez_cleanup_frontend_pages' );
        $removed = $this->cleanup_legacy_frontend_pages();
        $this->sync_compatibility_page_aliases();
        $this->redirect_pages_admin( array( 'lealez_pages_removed' => $removed ) );
    }

    /**
     * Create or migrate a page to a native Elementor widget layout.
     *
     * @param string $key Page key.
     * @param bool   $repair Append/migrate when page already exists.
     * @return bool
     */
    private function ensure_frontend_page( $key, $repair = false ) {
        $definitions = $this->get_page_definitions();
        if ( ! $this->is_elementor_active() || ! isset( $definitions[ $key ] ) ) {
            return false;
        }

        $definition = $definitions[ $key ];
        $status     = $this->get_page_status( $key );

        if ( $status['exists'] ) {
            if ( $status['valid'] || ! $repair ) {
                return false;
            }

            $written = $this->write_elementor_page( $status['page_id'], $definition['widget'], true );
            if ( $written ) {
                update_post_meta( $status['page_id'], '_lealez_frontend_page_key', $key );
                $this->remember_page_id( $key, $status['page_id'] );
                $this->sync_compatibility_page_aliases();
            }
            return $written;
        }

        $parent_id = 0;
        if ( $definition['parent'] ) {
            $parent_status = $this->get_page_status( $definition['parent'] );
            if ( ! $parent_status['valid'] ) {
                $this->ensure_frontend_page( $definition['parent'], $parent_status['exists'] );
                $parent_status = $this->get_page_status( $definition['parent'] );
            }
            $parent_id = $parent_status['exists'] ? (int) $parent_status['page_id'] : 0;
        }

        $page_id = wp_insert_post(
            array(
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => $definition['title'],
                'post_name'    => $definition['slug'],
                'post_content' => '',
                'post_parent'  => $parent_id,
            ),
            true
        );

        if ( is_wp_error( $page_id ) ) {
            return false;
        }

        if ( ! $this->write_elementor_page( $page_id, $definition['widget'], false ) ) {
            wp_trash_post( $page_id );
            return false;
        }

        $this->remember_page_id( $key, $page_id );
        update_post_meta( $page_id, '_lealez_frontend_page_key', $key );
        $this->sync_compatibility_page_aliases();

        return true;
    }

    /**
     * Store Elementor metadata and preserve previous classic content as backup.
     *
     * @param int    $page_id Page ID.
     * @param string $widget Widget type.
     * @param bool   $repair Whether to preserve and append existing Elementor data.
     * @return bool
     */
    private function write_elementor_page( $page_id, $widget, $repair ) {
        $page = get_post( $page_id );
        if ( ! $page || 'page' !== $page->post_type ) {
            return false;
        }

        $content = trim( (string) $page->post_content );
        if ( $content && ! metadata_exists( 'post', $page_id, '_lealez_pre_elementor_content_backup' ) ) {
            update_post_meta( $page_id, '_lealez_pre_elementor_content_backup', $content );
        }

        $data = $repair ? $this->get_elementor_page_data( $page_id ) : array();
        if ( empty( $data ) ) {
            $data = array( $this->build_elementor_section( $widget ) );
        } elseif ( ! $this->elementor_data_contains_widget( $data, $widget ) ) {
            $data[] = $this->build_elementor_section( $widget );
        }

        $updated = wp_update_post(
            array(
                'ID'           => $page_id,
                'post_content' => '',
            ),
            true
        );

        if ( is_wp_error( $updated ) ) {
            return false;
        }

        update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
        update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
        update_post_meta( $page_id, '_wp_page_template', 'elementor_header_footer' );

        if ( defined( 'ELEMENTOR_VERSION' ) ) {
            update_post_meta( $page_id, '_elementor_version', ELEMENTOR_VERSION );
        }

        delete_post_meta( $page_id, '_elementor_css' );
        clean_post_cache( $page_id );
        return true;
    }

    /**
     * Build a classic section/column/widget structure supported by Elementor 3.x.
     *
     * @param string $widget Widget type.
     * @return array<string,mixed>
     */
    private function build_elementor_section( $widget ) {
        return array(
            'id'       => $this->new_elementor_id(),
            'elType'   => 'section',
            'settings' => array(
                'layout'  => 'full_width',
                'gap'     => 'no',
                'stretch_section' => 'section-stretched',
            ),
            'elements' => array(
                array(
                    'id'       => $this->new_elementor_id(),
                    'elType'   => 'column',
                    'settings' => array( '_column_size' => 100 ),
                    'elements' => array(
                        array(
                            'id'         => $this->new_elementor_id(),
                            'elType'     => 'widget',
                            'widgetType' => $widget,
                            'settings'   => array(),
                            'elements'   => array(),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Generate a seven-character Elementor element ID.
     *
     * @return string
     */
    private function new_elementor_id() {
        return substr( md5( uniqid( 'lealez', true ) ), 0, 7 );
    }

    /**
     * Read and decode Elementor page data.
     *
     * @param int $page_id Page ID.
     * @return array<int,mixed>
     */
    private function get_elementor_page_data( $page_id ) {
        $raw = get_post_meta( $page_id, '_elementor_data', true );
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return array();
        }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            $data = json_decode( wp_unslash( $raw ), true );
        }
        return is_array( $data ) ? $data : array();
    }

    /**
     * Recursively search Elementor elements for a widget type.
     *
     * @param array<int,mixed> $elements Elementor elements.
     * @param string           $widget Widget type.
     * @return bool
     */
    private function elementor_data_contains_widget( $elements, $widget ) {
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }
            if ( isset( $element['widgetType'] ) && $widget === $element['widgetType'] ) {
                return true;
            }
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && $this->elementor_data_contains_widget( $element['elements'], $widget ) ) {
                return true;
            }
        }
        return false;
    }

    private function remember_page_id( $key, $page_id ) {
        $ids         = get_option( self::PAGE_OPTION, array() );
        $ids         = is_array( $ids ) ? $ids : array();
        $ids[ $key ] = absint( $page_id );
        update_option( self::PAGE_OPTION, $ids, false );
    }

    /**
     * Preserve compatibility with the existing GMB center, which checks the
     * historical business_google option before wp_head.
     */
    private function sync_compatibility_page_aliases() {
        $definitions = $this->get_page_definitions();
        $ids         = get_option( self::PAGE_OPTION, array() );
        $ids         = is_array( $ids ) ? $ids : array();

        if ( isset( $definitions['business_editor'], $ids['business_editor'] ) && get_post( absint( $ids['business_editor'] ) ) ) {
            $ids['business_google'] = absint( $ids['business_editor'] );
        }

        update_option( self::PAGE_OPTION, $ids, false );
    }
}
