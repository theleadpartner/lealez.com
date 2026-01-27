<?php
/**
 * GMB Settings Page
 * 
 * Handles GMB OAuth configuration in admin settings
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
 * Class Lealez_GMB_Settings
 */
class Lealez_GMB_Settings {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ), 99 );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'lealez_gmb_settings',
            'lealez_gmb_oauth_config',
            array(
                'sanitize_callback' => array( $this, 'sanitize_oauth_config' ),
            )
        );

        add_settings_section(
            'lealez_gmb_oauth_section',
            __( 'Google OAuth Configuration', 'lealez' ),
            array( $this, 'render_section_description' ),
            'lealez-gmb-settings'
        );

        add_settings_field(
            'gmb_client_id',
            __( 'Client ID', 'lealez' ),
            array( $this, 'render_client_id_field' ),
            'lealez-gmb-settings',
            'lealez_gmb_oauth_section'
        );

        add_settings_field(
            'gmb_client_secret',
            __( 'Client Secret', 'lealez' ),
            array( $this, 'render_client_secret_field' ),
            'lealez-gmb-settings',
            'lealez_gmb_oauth_section'
        );

        add_settings_field(
            'gmb_redirect_uri',
            __( 'Redirect URI', 'lealez' ),
            array( $this, 'render_redirect_uri_field' ),
            'lealez-gmb-settings',
            'lealez_gmb_oauth_section'
        );
    }

    /**
     * Add settings page
     */
    public function add_settings_page() {
        add_submenu_page(
            'lealez',
            __( 'Google My Business Settings', 'lealez' ),
            __( 'GMB Settings', 'lealez' ),
            'manage_options',
            'lealez-gmb-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            
            <div class="notice notice-info">
                <p>
                    <strong><?php _e( 'Setup Instructions:', 'lealez' ); ?></strong>
                </p>
<ol>
    <li><?php _e( 'Go to Google Cloud Console: <a href="https://console.cloud.google.com/" target="_blank">https://console.cloud.google.com/</a>', 'lealez' ); ?></li>
    <li><?php _e( 'Create a new project or select an existing one', 'lealez' ); ?></li>
    <li>
        <?php _e( 'Enable the following APIs in the API Library:', 'lealez' ); ?>
        <ul style="margin-top: 8px;">
            <li><strong>My Business Business Information API</strong> <?php _e( '(required for location data)', 'lealez' ); ?></li>
            <li><strong>Business Profile Performance API</strong> <?php _e( '(required for metrics)', 'lealez' ); ?></li>
            <li><strong>My Business Account Management API</strong> <?php _e( '(required for account access)', 'lealez' ); ?></li>
        </ul>
    </li>
    <li><?php _e( 'Go to "Credentials" and create an OAuth 2.0 Client ID', 'lealez' ); ?></li>
    <li><?php _e( 'Add the Redirect URI shown below to your OAuth client', 'lealez' ); ?></li>
    <li><?php _e( 'Copy the Client ID and Client Secret and paste them below', 'lealez' ); ?></li>
</ol>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'lealez_gmb_settings' );
                do_settings_sections( 'lealez-gmb-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render section description
     */
    public function render_section_description() {
        echo '<p>' . __( 'Configure your Google OAuth credentials to enable Google My Business integration.', 'lealez' ) . '</p>';
    }

    /**
     * Render Client ID field
     */
    public function render_client_id_field() {
        $config = get_option( 'lealez_gmb_oauth_config', array() );
        $client_id = $config['client_id'] ?? '';
        ?>
        <input type="text" 
               name="lealez_gmb_oauth_config[client_id]" 
               value="<?php echo esc_attr( $client_id ); ?>" 
               class="large-text"
               placeholder="123456789-abcdefg.apps.googleusercontent.com">
        <p class="description">
            <?php _e( 'Your Google OAuth Client ID', 'lealez' ); ?>
        </p>
        <?php
    }

    /**
     * Render Client Secret field
     */
    public function render_client_secret_field() {
        $config = get_option( 'lealez_gmb_oauth_config', array() );
        $client_secret = $config['client_secret'] ?? '';
        ?>
        <input type="password" 
               name="lealez_gmb_oauth_config[client_secret]" 
               value="<?php echo esc_attr( $client_secret ); ?>" 
               class="large-text"
               placeholder="GOCSPX-xxxxxxxxxxxxxxxxxxxxx">
        <p class="description">
            <?php _e( 'Your Google OAuth Client Secret', 'lealez' ); ?>
        </p>
        <?php
    }

    /**
     * Render Redirect URI field
     */
    public function render_redirect_uri_field() {
        $redirect_uri = Lealez_GMB_OAuth::get_redirect_uri();
        ?>
        <input type="text" 
               value="<?php echo esc_attr( $redirect_uri ); ?>" 
               class="large-text" 
               readonly>
        <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $redirect_uri ); ?>')">
            <?php _e( 'Copy', 'lealez' ); ?>
        </button>
        <p class="description">
            <?php _e( 'Add this URL to the "Authorized redirect URIs" in your Google OAuth client configuration.', 'lealez' ); ?>
        </p>
        <?php
    }

    /**
     * Sanitize OAuth config
     */
    public function sanitize_oauth_config( $input ) {
        $sanitized = array();
        
        if ( isset( $input['client_id'] ) ) {
            $sanitized['client_id'] = sanitize_text_field( $input['client_id'] );
        }
        
        if ( isset( $input['client_secret'] ) ) {
            $sanitized['client_secret'] = sanitize_text_field( $input['client_secret'] );
        }
        
        return $sanitized;
    }
}

// Initialize
new Lealez_GMB_Settings();
