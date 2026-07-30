<?php
/**
 * Minimal wp-admin API bridge required to render native metabox callbacks on
 * authenticated frontend pages.
 *
 * This file does not bootstrap an administration screen. It only loads the
 * WordPress core helpers that are normally unavailable outside wp-admin.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'add_meta_box' ) && file_exists( ABSPATH . 'wp-admin/includes/template.php' ) ) {
    require_once ABSPATH . 'wp-admin/includes/template.php';
}

if ( ! function_exists( 'get_current_screen' ) && file_exists( ABSPATH . 'wp-admin/includes/screen.php' ) ) {
    require_once ABSPATH . 'wp-admin/includes/screen.php';
}

if ( ! class_exists( 'WP_Screen' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-screen.php' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
}
