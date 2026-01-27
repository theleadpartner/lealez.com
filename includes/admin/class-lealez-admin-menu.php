<?php
/**
 * Lealez Admin Menu
 *
 * Handles the main admin menu for Lealez Plugin
 *
 * @package Lealez
 * @subpackage Admin
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Lealez_Admin_Menu
 *
 * Creates and manages the main Lealez admin menu
 */
class Lealez_Admin_Menu {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 9 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
    }

/**
 * Register the main admin menu
 */
public function register_admin_menu() {
    // Main menu page
    add_menu_page(
        __( 'Lealez', 'lealez' ),                    // Page title
        __( 'Lealez', 'lealez' ),                    // Menu title
        'manage_options',                             // Capability
        'lealez',                                     // Menu slug
        array( $this, 'render_dashboard_page' ),     // Callback function
        'dashicons-awards',                           // Icon
        26                                            // Position
    );

    // Dashboard submenu (replaces the duplicate main menu item)
    add_submenu_page(
        'lealez',                                     // Parent slug
        __( 'Dashboard', 'lealez' ),                 // Page title
        __( 'Dashboard', 'lealez' ),                 // Menu title
        'manage_options',                             // Capability
        'lealez',                                     // Menu slug (same as parent)
        array( $this, 'render_dashboard_page' )      // Callback function
    );

    // Empresas submenu
    add_submenu_page(
        'lealez',                                     // Parent slug
        __( 'Empresas', 'lealez' ),                  // Page title
        __( 'Empresas', 'lealez' ),                  // Menu title
        'manage_options',                             // Capability
        'edit.php?post_type=oy_business',            // Menu slug
        null                                          // No callback needed
    );

    // Ubicaciones submenu
    add_submenu_page(
        'lealez',                                     // Parent slug
        __( 'Ubicaciones', 'lealez' ),               // Page title
        __( 'Ubicaciones', 'lealez' ),               // Menu title
        'manage_options',                             // Capability
        'edit.php?post_type=oy_location',            // Menu slug
        null                                          // No callback needed
    );

    // Programas de Lealtad submenu
    add_submenu_page(
        'lealez',                                     // Parent slug
        __( 'Programas de Lealtad', 'lealez' ),      // Page title
        __( 'Programas de Lealtad', 'lealez' ),      // Menu title
        'manage_options',                             // Capability
        'edit.php?post_type=oy_loyalty_program',     // Menu slug
        null                                          // No callback needed
    );

    // Tarjetas de Lealtad submenu
    add_submenu_page(
        'lealez',                                     // Parent slug
        __( 'Tarjetas de Lealtad', 'lealez' ),       // Page title
        __( 'Tarjetas de Lealtad', 'lealez' ),       // Menu title
        'manage_options',                             // Capability
        'edit.php?post_type=oy_loyalty_card',        // Menu slug
        null                                          // No callback needed
    );

    // Categorías de Cliente submenu
add_submenu_page(
    'lealez',                                     // Parent slug
    __( 'Categorías de Cliente', 'lealez' ),     // Page title
    __( 'Categorías de Cliente', 'lealez' ),     // Menu title
    'manage_options',                             // Capability
    'edit-tags.php?taxonomy=oy_customer_category&post_type=oy_loyalty_card', // Menu slug
    null                                          // No callback needed
);

    // Settings submenu
    add_submenu_page(
        'lealez',                                     // Parent slug
        __( 'Configuración', 'lealez' ),             // Page title
        __( 'Configuración', 'lealez' ),             // Menu title
        'manage_options',                             // Capability
        'lealez-settings',                            // Menu slug
        array( $this, 'render_settings_page' )       // Callback function
    );
}

    /**
     * Render the dashboard page
     */
    public function render_dashboard_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            
            <div class="lealez-dashboard">
                <div class="lealez-dashboard-widgets">
                    
<!-- Widget: Quick Stats -->
<div class="lealez-widget lealez-widget-stats">
    <h2><?php esc_html_e( 'Resumen Rápido', 'lealez' ); ?></h2>
    <div class="lealez-stats-grid">
        <?php
        // Get counts
        $business_count = wp_count_posts( 'oy_business' );
        $location_count = wp_count_posts( 'oy_location' );
        $program_count  = wp_count_posts( 'oy_loyalty_program' );
        $card_count     = wp_count_posts( 'oy_loyalty_card' );
        ?>
        
        <div class="lealez-stat-box">
            <span class="dashicons dashicons-building"></span>
            <div class="lealez-stat-content">
                <div class="lealez-stat-number"><?php echo esc_html( $business_count->publish ); ?></div>
                <div class="lealez-stat-label"><?php esc_html_e( 'Empresas', 'lealez' ); ?></div>
            </div>
        </div>

        <div class="lealez-stat-box">
            <span class="dashicons dashicons-location"></span>
            <div class="lealez-stat-content">
                <div class="lealez-stat-number"><?php echo esc_html( $location_count->publish ); ?></div>
                <div class="lealez-stat-label"><?php esc_html_e( 'Ubicaciones', 'lealez' ); ?></div>
            </div>
        </div>

        <div class="lealez-stat-box">
            <span class="dashicons dashicons-awards"></span>
            <div class="lealez-stat-content">
                <div class="lealez-stat-number"><?php echo esc_html( $program_count->publish ); ?></div>
                <div class="lealez-stat-label"><?php esc_html_e( 'Programas', 'lealez' ); ?></div>
            </div>
        </div>

        <div class="lealez-stat-box">
            <span class="dashicons dashicons-id-alt"></span>
            <div class="lealez-stat-content">
                <div class="lealez-stat-number"><?php echo esc_html( $card_count->publish ); ?></div>
                <div class="lealez-stat-label"><?php esc_html_e( 'Tarjetas', 'lealez' ); ?></div>
            </div>
        </div>
    </div>
</div>

                    <!-- Widget: Quick Actions -->
                    <div class="lealez-widget lealez-widget-actions">
                        <h2><?php esc_html_e( 'Acciones Rápidas', 'lealez' ); ?></h2>
                        <div class="lealez-quick-actions">
                            <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=oy_business' ) ); ?>" class="lealez-action-button">
                                <span class="dashicons dashicons-plus-alt"></span>
                                <?php esc_html_e( 'Nueva Empresa', 'lealez' ); ?>
                            </a>
                            <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=oy_location' ) ); ?>" class="lealez-action-button">
                                <span class="dashicons dashicons-plus-alt"></span>
                                <?php esc_html_e( 'Nueva Ubicación', 'lealez' ); ?>
                            </a>
                            <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=oy_loyalty_program' ) ); ?>" class="lealez-action-button">
                                <span class="dashicons dashicons-plus-alt"></span>
                                <?php esc_html_e( 'Nuevo Programa', 'lealez' ); ?>
                            </a>
                        </div>
                    </div>

                    <!-- Widget: Recent Activity -->
                    <div class="lealez-widget lealez-widget-activity">
                        <h2><?php esc_html_e( 'Actividad Reciente', 'lealez' ); ?></h2>
                        <?php
                        // Get recent posts
                        $recent_posts = get_posts(
                            array(
                                'post_type'      => array( 'oy_business', 'oy_location', 'oy_loyalty_program' ),
                                'posts_per_page' => 5,
                                'orderby'        => 'date',
                                'order'          => 'DESC',
                            )
                        );

                        if ( ! empty( $recent_posts ) ) {
                            echo '<ul class="lealez-activity-list">';
                            foreach ( $recent_posts as $recent_post ) {
                                $post_type_object = get_post_type_object( $recent_post->post_type );
                                ?>
                                <li>
                                    <span class="lealez-activity-date"><?php echo esc_html( human_time_diff( strtotime( $recent_post->post_date ), current_time( 'timestamp' ) ) ); ?> <?php esc_html_e( 'ago', 'lealez' ); ?></span>
                                    <span class="lealez-activity-type"><?php echo esc_html( $post_type_object->labels->singular_name ); ?>:</span>
                                    <a href="<?php echo esc_url( get_edit_post_link( $recent_post->ID ) ); ?>"><?php echo esc_html( get_the_title( $recent_post->ID ) ); ?></a>
                                </li>
                                <?php
                            }
                            echo '</ul>';
                        } else {
                            echo '<p>' . esc_html__( 'No hay actividad reciente.', 'lealez' ) . '</p>';
                        }
                        ?>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields( 'lealez_settings_group' );
                do_settings_sections( 'lealez-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

public function enqueue_admin_styles( $hook ) {
    // Only load on Lealez pages
    $screen = get_current_screen();
    $lealez_post_types = array( 'oy_business', 'oy_location', 'oy_loyalty_program', 'oy_loyalty_card' );
    $lealez_taxonomies = array( 'oy_customer_category' );
    
    if ( strpos( $hook, 'lealez' ) === false 
         && ! in_array( $screen->post_type, $lealez_post_types, true )
         && ! in_array( $screen->taxonomy, $lealez_taxonomies, true ) ) {
        return;
    }

    // Inline CSS for dashboard
    $custom_css = "
    .lealez-dashboard {
        margin-top: 20px;
    }
    .lealez-dashboard-widgets {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }
    .lealez-widget {
        background: #fff;
        border: 1px solid #ccd0d4;
        box-shadow: 0 1px 1px rgba(0,0,0,.04);
        padding: 20px;
    }
    .lealez-widget h2 {
        margin-top: 0;
        font-size: 18px;
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
        margin-bottom: 15px;
    }
    .lealez-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
    }
    .lealez-stat-box {
        display: flex;
        align-items: center;
        padding: 15px;
        background: #f9f9f9;
        border-radius: 4px;
    }
    .lealez-stat-box .dashicons {
        font-size: 40px;
        width: 40px;
        height: 40px;
        margin-right: 15px;
        color: #2271b1;
    }
    .lealez-stat-number {
        font-size: 28px;
        font-weight: bold;
        color: #2271b1;
        line-height: 1;
    }
    .lealez-stat-label {
        font-size: 13px;
        color: #666;
        margin-top: 5px;
    }
    .lealez-quick-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .lealez-action-button {
        display: flex;
        align-items: center;
        padding: 12px 15px;
        background: #2271b1;
        color: #fff;
        text-decoration: none;
        border-radius: 4px;
        transition: background 0.3s;
    }
    .lealez-action-button:hover {
        background: #135e96;
        color: #fff;
    }
    .lealez-action-button .dashicons {
        margin-right: 8px;
    }
    .lealez-activity-list {
        list-style: none;
        margin: 0;
        padding: 0;
    }
    .lealez-activity-list li {
        padding: 10px 0;
        border-bottom: 1px solid #eee;
    }
    .lealez-activity-list li:last-child {
        border-bottom: none;
    }
    .lealez-activity-date {
        display: inline-block;
        font-size: 12px;
        color: #999;
        margin-right: 10px;
    }
    .lealez-activity-type {
        font-weight: 600;
        margin-right: 5px;
    }
    
    /* Estilos para el meta box de ubicaciones */
    .lealez-no-locations {
        text-align: center;
        padding: 40px 20px;
        background: #f9f9f9;
        border: 2px dashed #ddd;
        border-radius: 4px;
    }
    .lealez-no-locations p {
        margin: 10px 0;
    }
    .lealez-locations-list table {
        margin-top: 10px;
    }
    .lealez-locations-list .button .dashicons {
        display: inline-block;
        vertical-align: middle;
    }
    .lealez-locations-header {
        display: flex;
        align-items: center;
        padding: 10px 0;
        border-bottom: 1px solid #ddd;
    }
    ";

    wp_add_inline_style( 'wp-admin', $custom_css );
}
}

// Initialize the admin menu
new Lealez_Admin_Menu();
