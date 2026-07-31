<?php
/**
 * Frontend page discovery and Elementor widget validation.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Lealez_Frontend_Page_Status_Trait {
    private function get_page_status( $key ) {
        $definitions = $this->get_page_definitions();
        if ( ! isset( $definitions[ $key ] ) ) {
            return array( 'exists' => false, 'valid' => false, 'elementor' => false, 'page_id' => 0 );
        }

        $ids     = get_option( self::PAGE_OPTION, array() );
        $ids     = is_array( $ids ) ? $ids : array();
        $page_id = isset( $ids[ $key ] ) ? absint( $ids[ $key ] ) : 0;
        $page    = $page_id ? get_post( $page_id ) : null;

        if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
            $path = $definitions[ $key ]['slug'];
            if ( $definitions[ $key ]['parent'] ) {
                $path = $definitions[ $definitions[ $key ]['parent'] ]['slug'] . '/' . $path;
            }
            $page = get_page_by_path( $path );
            if ( $page ) {
                $page_id    = (int) $page->ID;
                $ids[ $key ] = $page_id;
                update_option( self::PAGE_OPTION, $ids, false );
            }
        }

        if ( ! $page ) {
            return array( 'exists' => false, 'valid' => false, 'elementor' => false, 'page_id' => 0 );
        }

        $data      = $this->get_elementor_page_data( $page->ID );
        $elementor = 'builder' === get_post_meta( $page->ID, '_elementor_edit_mode', true ) || ! empty( $data );

        return array(
            'exists'    => true,
            'valid'     => $this->elementor_data_contains_widget( $data, $definitions[ $key ]['widget'] ),
            'elementor' => $elementor,
            'page_id'   => (int) $page->ID,
        );
    }
}
