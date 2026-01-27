<?php
/**
 * Plugin Name: Lealez Plugin
 * Plugin URI: https://lealez.com
 * Description: Sistema completo de gestión de lealtad con integración Google My Business, Google Wallet y Apple Wallet
 * Version: 1.0.0
 * Author: The Lead Partner
 * Author URI: https://theleadpartner.com
 * Text Domain: lealez
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Lealez
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main Lealez Plugin Class
 *
 * @class Lealez_Plugin
 * @version 1.0.0
 */
final class Lealez_Plugin {

    /**
     * Plugin version
     *
     * @var string
     */
    public $version = '1.0.0';

    /**
     * The single instance of the class
     *
     * @var Lealez_Plugin
     */
    protected static $_instance = null;

    /**
     * Main Lealez Plugin Instance
     *
     * Ensures only one instance of Lealez Plugin is loaded or can be loaded.
     *
     * @static
     * @return Lealez_Plugin - Main instance
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Lealez Plugin Constructor
     */
    public function __construct() {
        $this->define_constants();
        $this->init_hooks();
    }

    /**
     * Define Lealez Plugin Constants
     */
    private function define_constants() {
        $this->define( 'LEALEZ_VERSION', $this->version );
        $this->define( 'LEALEZ_PLUGIN_FILE', __FILE__ );
        $this->define( 'LEALEZ_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
        $this->define( 'LEALEZ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
        $this->define( 'LEALEZ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
        $this->define( 'LEALEZ_ASSETS_URL', LEALEZ_PLUGIN_URL . 'assets/' );
        $this->define( 'LEALEZ_INCLUDES_DIR', LEALEZ_PLUGIN_DIR . 'includes/' );
        $this->define( 'LEALEZ_TEMPLATES_DIR', LEALEZ_PLUGIN_DIR . 'templates/' );
    }

    /**
     * Define constant if not already set
     *
     * @param string $name  Constant name
     * @param mixed  $value Constant value
     */
    private function define( $name, $value ) {
        if ( ! defined( $name ) ) {
            define( $name, $value );
        }
    }

    /**
     * Hook into actions and filters
     */
    private function init_hooks() {
        // Activation & Deactivation hooks
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        // Init hook
        add_action( 'init', array( $this, 'init' ), 0 );

        // Loaded action
        add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ), -1 );
    }

    /**
     * Init Lealez Plugin when WordPress Initializes
     */
    public function init() {
        // Load plugin textdomain for translations
        $this->load_textdomain();

        // Init action
        do_action( 'lealez_init' );
    }

    /**
     * Load Localization files
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'lealez',
            false,
            dirname( LEALEZ_PLUGIN_BASENAME ) . '/languages'
        );
    }

/**
 * When WordPress has loaded all plugins
 */
public function on_plugins_loaded() {
    // Include CPT classes
    $this->include_cpts();
    
    // Include admin classes
    if ( is_admin() ) {
        $this->include_admin();
    }
    
    do_action( 'lealez_loaded' );
}

/**
 * Include CPT classes
 */
private function include_cpts() {
    require_once LEALEZ_INCLUDES_DIR . 'cpts/class-oy-business-cpt.php';
    require_once LEALEZ_INCLUDES_DIR . 'cpts/class-oy-location-cpt.php';
    require_once LEALEZ_INCLUDES_DIR . 'cpts/class-oy-loyalty-program-cpt.php';
    require_once LEALEZ_INCLUDES_DIR . 'cpts/class-oy-loyalty-card-cpt.php';
    
    // Include taxonomies
    require_once LEALEZ_INCLUDES_DIR . 'taxonomies/class-oy-customer-category-taxonomy.php';
}
    
/**
 * Include admin classes
 */
private function include_admin() {
    require_once LEALEZ_INCLUDES_DIR . 'admin/class-lealez-admin-menu.php';
}

    /**
     * Plugin activation callback
     */
    public function activate() {
        // Check WordPress version
        if ( version_compare( get_bloginfo( 'version' ), '6.0', '<' ) ) {
            deactivate_plugins( LEALEZ_PLUGIN_BASENAME );
            wp_die(
                __( 'Lealez Plugin requiere WordPress 6.0 o superior.', 'lealez' ),
                __( 'Error de Activación', 'lealez' ),
                array( 'back_link' => true )
            );
        }

        // Check PHP version
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            deactivate_plugins( LEALEZ_PLUGIN_BASENAME );
            wp_die(
                __( 'Lealez Plugin requiere PHP 7.4 o superior.', 'lealez' ),
                __( 'Error de Activación', 'lealez' ),
                array( 'back_link' => true )
            );
        }

        // Include CPT classes before activation to register post types
        $this->include_cpts();

        // Set default options
        $this->set_default_options();

        // Set activation timestamp
        update_option( 'lealez_activated_timestamp', time() );
        update_option( 'lealez_version', LEALEZ_VERSION );

        // Flush rewrite rules
        flush_rewrite_rules();

        // Activation hook for other components
        do_action( 'lealez_activated' );
    }

    /**
     * Plugin deactivation callback
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();

        // Deactivation hook for other components
        do_action( 'lealez_deactivated' );
    }

    /**
     * Set default plugin options
     */
    private function set_default_options() {
        // General settings
        $defaults = array(
            'lealez_general_settings' => array(
                'plugin_enabled' => true,
                'debug_mode' => false,
            ),
            'lealez_google_settings' => array(
                'gmb_enabled' => false,
                'google_wallet_enabled' => false,
            ),
            'lealez_apple_settings' => array(
                'apple_wallet_enabled' => false,
            ),
        );

        foreach ( $defaults as $option_name => $option_value ) {
            if ( false === get_option( $option_name ) ) {
                add_option( $option_name, $option_value );
            }
        }
    }

    /**
     * Get the plugin url
     *
     * @return string
     */
    public function plugin_url() {
        return untrailingslashit( plugins_url( '/', __FILE__ ) );
    }

    /**
     * Get the plugin path
     *
     * @return string
     */
    public function plugin_path() {
        return untrailingslashit( plugin_dir_path( __FILE__ ) );
    }

    /**
     * Get Ajax URL
     *
     * @return string
     */
    public function ajax_url() {
        return admin_url( 'admin-ajax.php', 'relative' );
    }
}

/**
 * Main instance of Lealez Plugin
 *
 * Returns the main instance of Lealez Plugin to prevent the need to use globals.
 *
 * @return Lealez_Plugin
 */
function lealez() {
    return Lealez_Plugin::instance();
}

// Initialize the plugin
lealez();
