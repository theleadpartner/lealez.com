<?php
/** Access and page helpers for Lealez Elementor widgets. @package Lealez */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Lealez_Elementor_Profile_Access_Trait {
    private function is_elementor_edit_mode() {
        if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
            return false;
        }

        $plugin = \Elementor\Plugin::instance();
        return isset( $plugin->editor ) && is_object( $plugin->editor ) && method_exists( $plugin->editor, 'is_edit_mode' ) && $plugin->editor->is_edit_mode();
    }

    private function get_portal_page_url( $key ) {
        $ids     = get_option( 'lealez_frontend_page_ids', array() );
        $ids     = is_array( $ids ) ? $ids : array();
        $page_id = isset( $ids[ $key ] ) ? absint( $ids[ $key ] ) : 0;
        return $page_id && 'trash' !== get_post_status( $page_id ) ? get_permalink( $page_id ) : home_url( '/' );
    }

    private function can_access_business( $business_id ) {
        $user_id = get_current_user_id();
        $post    = get_post( absint( $business_id ) );
        if ( ! $post || 'oy_business' !== $post->post_type || ! $user_id ) {
            return false;
        }
        if ( current_user_can( 'manage_options' ) || (int) $post->post_author === $user_id ) {
            return true;
        }
        $admins   = get_post_meta( $post->ID, '_admin_users', true );
        $managers = get_post_meta( $post->ID, '_manager_users', true );
        $ids      = array_merge( is_array( $admins ) ? $admins : array(), is_array( $managers ) ? $managers : array() );
        return in_array( $user_id, array_map( 'intval', $ids ), true );
    }

    private function can_access_location( $location_id ) {
        $user_id  = get_current_user_id();
        $location = get_post( absint( $location_id ) );
        if ( ! $location || 'oy_location' !== $location->post_type || ! $user_id ) {
            return false;
        }
        if ( current_user_can( 'manage_options' ) || (int) $location->post_author === $user_id ) {
            return true;
        }
        $business_id = absint( get_post_meta( $location->ID, 'parent_business_id', true ) );
        return $business_id ? $this->can_access_business( $business_id ) : false;
    }
}
