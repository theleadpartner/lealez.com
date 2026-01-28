<?php
/**
 * Business CPT Class
 *
 * Handles the registration and meta fields for oy_business Custom Post Type
 *
 * @package Lealez
 * @subpackage CPTs
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lealez_Business_CPT Class
 *
 * @class Lealez_Business_CPT
 * @version 1.0.0
 */
class Lealez_Business_CPT {

    /**
     * Post type name
     *
     * @var string
     */
    private $post_type = 'oy_business';

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post_oy_business', array( $this, 'save_meta_boxes' ), 10, 2 );
        add_filter( 'manage_oy_business_posts_columns', array( $this, 'set_custom_columns' ) );
        add_action( 'manage_oy_business_posts_custom_column', array( $this, 'custom_column_content' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
    }

    /**
     * Register the Business CPT
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => _x( 'Empresas', 'Post type general name', 'lealez' ),
            'singular_name'         => _x( 'Empresa', 'Post type singular name', 'lealez' ),
            'menu_name'             => _x( 'Empresas', 'Admin Menu text', 'lealez' ),
            'name_admin_bar'        => _x( 'Empresa', 'Add New on Toolbar', 'lealez' ),
            'add_new'               => __( 'Agregar Nueva', 'lealez' ),
            'add_new_item'          => __( 'Agregar Nueva Empresa', 'lealez' ),
            'new_item'              => __( 'Nueva Empresa', 'lealez' ),
            'edit_item'             => __( 'Editar Empresa', 'lealez' ),
            'view_item'             => __( 'Ver Empresa', 'lealez' ),
            'all_items'             => __( 'Todas las Empresas', 'lealez' ),
            'search_items'          => __( 'Buscar Empresas', 'lealez' ),
            'parent_item_colon'     => __( 'Empresa Padre:', 'lealez' ),
            'not_found'             => __( 'No se encontraron empresas.', 'lealez' ),
            'not_found_in_trash'    => __( 'No se encontraron empresas en la papelera.', 'lealez' ),
            'featured_image'        => _x( 'Logo de la Empresa', 'Overrides the "Featured Image" phrase', 'lealez' ),
            'set_featured_image'    => _x( 'Establecer logo', 'Overrides the "Set featured image" phrase', 'lealez' ),
            'remove_featured_image' => _x( 'Eliminar logo', 'Overrides the "Remove featured image" phrase', 'lealez' ),
            'use_featured_image'    => _x( 'Usar como logo', 'Overrides the "Use as featured image" phrase', 'lealez' ),
            'archives'              => _x( 'Archivo de Empresas', 'The post type archive label', 'lealez' ),
            'insert_into_item'      => _x( 'Insertar en empresa', 'Overrides the "Insert into post" phrase', 'lealez' ),
            'uploaded_to_this_item' => _x( 'Subido a esta empresa', 'Overrides the "Uploaded to this post" phrase', 'lealez' ),
            'filter_items_list'     => _x( 'Filtrar lista de empresas', 'Screen reader text for the filter links', 'lealez' ),
            'items_list_navigation' => _x( 'Navegación de lista de empresas', 'Screen reader text for the pagination', 'lealez' ),
            'items_list'            => _x( 'Lista de empresas', 'Screen reader text for the items list', 'lealez' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'menu_position'      => 20,
            'menu_icon'          => 'dashicons-building',
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'business' ),
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'supports'           => array( 'title', 'editor', 'thumbnail', 'author' ),
            'show_in_rest'       => false,
        );

        register_post_type( $this->post_type, $args );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts( $hook ) {
        global $post_type;
        
        // Only load on oy_business edit pages
        if ( ( 'post.php' === $hook || 'post-new.php' === $hook ) && 'oy_business' === $post_type ) {
            
            // Enqueue GMB connection script
            wp_enqueue_script(
                'lealez-gmb-connection',
                LEALEZ_ASSETS_URL . 'js/admin/lealez-gmb-connection.js',
                array( 'jquery' ),
                LEALEZ_VERSION,
                true
            );

            // Localize script
            wp_localize_script(
                'lealez-gmb-connection',
                'lealezGMBData',
                array(
                    'nonce'             => wp_create_nonce( 'lealez_gmb_nonce' ),
                    'businessId'        => get_the_ID(),
                    'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
                    'i18n'              => array(
                        'processing'        => __( 'Procesando...', 'lealez' ),
                        'error'             => __( 'Error', 'lealez' ),
                        'saveFirst'         => __( 'Por favor guarda el post primero', 'lealez' ),
                        'confirmDisconnect' => __( '¿Estás seguro de que deseas desconectar la cuenta de Google My Business?', 'lealez' ),
                    ),
                )
            );
        }
    }

    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        // Información Básica
        add_meta_box(
            'lealez_business_basic_info',
            __( 'Información Básica', 'lealez' ),
            array( $this, 'render_basic_info_meta_box' ),
            $this->post_type,
            'normal',
            'high'
        );

        // Identidad de Marca
        add_meta_box(
            'lealez_business_brand_identity',
            __( 'Identidad de Marca', 'lealez' ),
            array( $this, 'render_brand_identity_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // Categorización
        add_meta_box(
            'lealez_business_categorization',
            __( 'Categorización', 'lealez' ),
            array( $this, 'render_categorization_meta_box' ),
            $this->post_type,
            'side',
            'default'
        );

        // Contacto Corporativo
        add_meta_box(
            'lealez_business_contact',
            __( 'Contacto Corporativo', 'lealez' ),
            array( $this, 'render_contact_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // Redes Sociales
        add_meta_box(
            'lealez_business_social',
            __( 'Redes Sociales Corporativas', 'lealez' ),
            array( $this, 'render_social_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // Google Pay / Wallet
        add_meta_box(
            'lealez_business_google_wallet',
            __( 'Google Pay / Wallet', 'lealez' ),
            array( $this, 'render_google_wallet_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // Información Fiscal/Legal
        add_meta_box(
            'lealez_business_legal',
            __( 'Información Fiscal/Legal', 'lealez' ),
            array( $this, 'render_legal_meta_box' ),
            $this->post_type,
            'side',
            'default'
        );

        // Google My Business
        add_meta_box(
            'lealez_business_gmb',
            __( 'Google My Business', 'lealez' ),
            array( $this, 'render_gmb_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // Estadísticas del Sistema
        add_meta_box(
            'lealez_business_stats',
            __( 'Estadísticas del Sistema', 'lealez' ),
            array( $this, 'render_stats_meta_box' ),
            $this->post_type,
            'side',
            'low'
        );

        // Permisos y Roles
        add_meta_box(
            'lealez_business_permissions',
            __( 'Permisos y Roles', 'lealez' ),
            array( $this, 'render_permissions_meta_box' ),
            $this->post_type,
            'normal',
            'low'
        );
    }

    /**
     * Render Basic Info Meta Box
     */
    public function render_basic_info_meta_box( $post ) {
        wp_nonce_field( 'lealez_business_meta_box', 'lealez_business_meta_box_nonce' );

        $business_name = get_post_meta( $post->ID, '_business_name', true );
        $business_legal_name = get_post_meta( $post->ID, '_business_legal_name', true );
        $business_type = get_post_meta( $post->ID, '_business_type', true );
        $business_description = get_post_meta( $post->ID, '_business_description', true );
        $business_founded_date = get_post_meta( $post->ID, '_business_founded_date', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="business_name"><?php _e( 'Nombre de la Empresa', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" id="business_name" name="business_name" value="<?php echo esc_attr( $business_name ); ?>" class="large-text" />
                    <p class="description"><?php _e( 'Nombre comercial de la empresa (ej: Starbucks, McDonald\'s)', 'lealez' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="business_legal_name"><?php _e( 'Razón Social Legal', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" id="business_legal_name" name="business_legal_name" value="<?php echo esc_attr( $business_legal_name ); ?>" class="large-text" />
                    <p class="description"><?php _e( 'Nombre legal registrado de la empresa', 'lealez' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="business_type"><?php _e( 'Tipo de Negocio', 'lealez' ); ?></label></th>
                <td>
                    <select id="business_type" name="business_type">
                        <option value=""><?php _e( 'Seleccionar...', 'lealez' ); ?></option>
                        <option value="single_location" <?php selected( $business_type, 'single_location' ); ?>><?php _e( 'Ubicación Única', 'lealez' ); ?></option>
                        <option value="multi_location" <?php selected( $business_type, 'multi_location' ); ?>><?php _e( 'Multi-Ubicación', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="business_description"><?php _e( 'Descripción Corporativa', 'lealez' ); ?></label></th>
                <td>
                    <textarea id="business_description" name="business_description" rows="4" class="large-text"><?php echo esc_textarea( $business_description ); ?></textarea>
                </td>
            </tr>
            <tr>
                <th><label for="business_founded_date"><?php _e( 'Fecha de Fundación', 'lealez' ); ?></label></th>
                <td>
                    <input type="date" id="business_founded_date" name="business_founded_date" value="<?php echo esc_attr( $business_founded_date ); ?>" />
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Brand Identity Meta Box
     */
    public function render_brand_identity_meta_box( $post ) {
        $brand_logo = get_post_meta( $post->ID, '_brand_logo', true );
        $brand_logo_id = get_post_meta( $post->ID, '_brand_logo_id', true );
        $brand_icon = get_post_meta( $post->ID, '_brand_icon', true );
        $brand_colors = get_post_meta( $post->ID, '_brand_colors', true );
        $brand_cover_image = get_post_meta( $post->ID, '_brand_cover_image', true );
        $brand_tagline = get_post_meta( $post->ID, '_brand_tagline', true );

        $brand_colors_array = maybe_unserialize( $brand_colors );
        if ( ! is_array( $brand_colors_array ) ) {
            $brand_colors_array = array( 'primary' => '', 'secondary' => '', 'accent' => '' );
        }
        ?>
        <table class="form-table">
            <tr>
                <th><label for="brand_logo"><?php _e( 'Logo Principal', 'lealez' ); ?></label></th>
                <td>
                    <div class="lealez-image-upload">
                        <input type="hidden" id="brand_logo" name="brand_logo" value="<?php echo esc_url( $brand_logo ); ?>" />
                        <input type="hidden" id="brand_logo_id" name="brand_logo_id" value="<?php echo esc_attr( $brand_logo_id ); ?>" />
                        <div class="lealez-image-preview">
                            <?php if ( $brand_logo ) : ?>
                                <img src="<?php echo esc_url( $brand_logo ); ?>" style="max-width: 200px; height: auto;" />
                            <?php endif; ?>
                        </div>
                        <button type="button" class="button lealez-upload-image-button" data-target="brand_logo"><?php _e( 'Subir Logo', 'lealez' ); ?></button>
                        <button type="button" class="button lealez-remove-image-button" data-target="brand_logo"><?php _e( 'Eliminar', 'lealez' ); ?></button>
                    </div>
                </td>
            </tr>
            <tr>
                <th><label for="brand_icon"><?php _e( 'Icono Cuadrado', 'lealez' ); ?></label></th>
                <td>
                    <input type="url" id="brand_icon" name="brand_icon" value="<?php echo esc_url( $brand_icon ); ?>" class="large-text" />
                    <p class="description"><?php _e( 'Icono cuadrado para aplicaciones móviles', 'lealez' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label><?php _e( 'Colores de Marca', 'lealez' ); ?></label></th>
                <td>
                    <p>
                        <label for="brand_color_primary"><?php _e( 'Color Primario:', 'lealez' ); ?></label>
                        <input type="color" id="brand_color_primary" name="brand_colors[primary]" value="<?php echo esc_attr( $brand_colors_array['primary'] ); ?>" />
                    </p>
                    <p>
                        <label for="brand_color_secondary"><?php _e( 'Color Secundario:', 'lealez' ); ?></label>
                        <input type="color" id="brand_color_secondary" name="brand_colors[secondary]" value="<?php echo esc_attr( $brand_colors_array['secondary'] ); ?>" />
                    </p>
                    <p>
                        <label for="brand_color_accent"><?php _e( 'Color de Acento:', 'lealez' ); ?></label>
                        <input type="color" id="brand_color_accent" name="brand_colors[accent]" value="<?php echo esc_attr( $brand_colors_array['accent'] ); ?>" />
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="brand_cover_image"><?php _e( 'Imagen de Portada', 'lealez' ); ?></label></th>
                <td>
                    <input type="url" id="brand_cover_image" name="brand_cover_image" value="<?php echo esc_url( $brand_cover_image ); ?>" class="large-text" />
                </td>
            </tr>
            <tr>
                <th><label for="brand_tagline"><?php _e( 'Lema de la Empresa', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" id="brand_tagline" name="brand_tagline" value="<?php echo esc_attr( $brand_tagline ); ?>" class="large-text" />
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Categorization Meta Box
     */
    public function render_categorization_meta_box( $post ) {
        $business_industry = get_post_meta( $post->ID, '_business_industry', true );
        $business_category = get_post_meta( $post->ID, '_business_category', true );
        $business_subcategory = get_post_meta( $post->ID, '_business_subcategory', true );
        ?>
        <p>
            <label for="business_industry"><strong><?php _e( 'Industria Principal', 'lealez' ); ?></strong></label>
            <input type="text" id="business_industry" name="business_industry" value="<?php echo esc_attr( $business_industry ); ?>" class="widefat" />
        </p>
        <p>
            <label for="business_category"><strong><?php _e( 'Categoría de Negocio', 'lealez' ); ?></strong></label>
            <input type="text" id="business_category" name="business_category" value="<?php echo esc_attr( $business_category ); ?>" class="widefat" />
        </p>
        <p>
            <label for="business_subcategory"><strong><?php _e( 'Subcategoría', 'lealez' ); ?></strong></label>
            <input type="text" id="business_subcategory" name="business_subcategory" value="<?php echo esc_attr( $business_subcategory ); ?>" class="widefat" />
        </p>
        <?php
    }

    /**
     * Render Contact Meta Box
     */
    public function render_contact_meta_box( $post ) {
        $corporate_email = get_post_meta( $post->ID, '_corporate_email', true );
        $corporate_phone = get_post_meta( $post->ID, '_corporate_phone', true );
        $corporate_website = get_post_meta( $post->ID, '_corporate_website', true );
        $corporate_address = get_post_meta( $post->ID, '_corporate_address', true );
        $corporate_city = get_post_meta( $post->ID, '_corporate_city', true );
        $corporate_state = get_post_meta( $post->ID, '_corporate_state', true );
        $corporate_country = get_post_meta( $post->ID, '_corporate_country', true );
        $corporate_postal_code = get_post_meta( $post->ID, '_corporate_postal_code', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="corporate_email"><?php _e( 'Email Principal', 'lealez' ); ?></label></th>
                <td><input type="email" id="corporate_email" name="corporate_email" value="<?php echo esc_attr( $corporate_email ); ?>" class="large-text" /></td>
            </tr>
            <tr>
                <th><label for="corporate_phone"><?php _e( 'Teléfono Corporativo', 'lealez' ); ?></label></th>
                <td><input type="tel" id="corporate_phone" name="corporate_phone" value="<?php echo esc_attr( $corporate_phone ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="corporate_website"><?php _e( 'Sitio Web Oficial', 'lealez' ); ?></label></th>
                <td><input type="url" id="corporate_website" name="corporate_website" value="<?php echo esc_url( $corporate_website ); ?>" class="large-text" /></td>
            </tr>
            <tr>
                <th><label for="corporate_address"><?php _e( 'Dirección', 'lealez' ); ?></label></th>
                <td><input type="text" id="corporate_address" name="corporate_address" value="<?php echo esc_attr( $corporate_address ); ?>" class="large-text" /></td>
            </tr>
            <tr>
                <th><label for="corporate_city"><?php _e( 'Ciudad', 'lealez' ); ?></label></th>
                <td><input type="text" id="corporate_city" name="corporate_city" value="<?php echo esc_attr( $corporate_city ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="corporate_state"><?php _e( 'Estado/Departamento', 'lealez' ); ?></label></th>
                <td><input type="text" id="corporate_state" name="corporate_state" value="<?php echo esc_attr( $corporate_state ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="corporate_country"><?php _e( 'País', 'lealez' ); ?></label></th>
                <td><input type="text" id="corporate_country" name="corporate_country" value="<?php echo esc_attr( $corporate_country ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="corporate_postal_code"><?php _e( 'Código Postal', 'lealez' ); ?></label></th>
                <td><input type="text" id="corporate_postal_code" name="corporate_postal_code" value="<?php echo esc_attr( $corporate_postal_code ); ?>" class="regular-text" /></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Social Meta Box
     */
    public function render_social_meta_box( $post ) {
        $social_facebook = get_post_meta( $post->ID, '_social_facebook', true );
        $social_instagram = get_post_meta( $post->ID, '_social_instagram', true );
        $social_twitter = get_post_meta( $post->ID, '_social_twitter', true );
        $social_linkedin = get_post_meta( $post->ID, '_social_linkedin', true );
        $social_youtube = get_post_meta( $post->ID, '_social_youtube', true );
        $social_tiktok = get_post_meta( $post->ID, '_social_tiktok', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="social_facebook"><?php _e( 'Facebook', 'lealez' ); ?></label></th>
                <td><input type="url" id="social_facebook" name="social_facebook" value="<?php echo esc_url( $social_facebook ); ?>" class="large-text" placeholder="https://facebook.com/empresa" /></td>
            </tr>
            <tr>
                <th><label for="social_instagram"><?php _e( 'Instagram', 'lealez' ); ?></label></th>
                <td><input type="url" id="social_instagram" name="social_instagram" value="<?php echo esc_url( $social_instagram ); ?>" class="large-text" placeholder="https://instagram.com/empresa" /></td>
            </tr>
            <tr>
                <th><label for="social_twitter"><?php _e( 'Twitter', 'lealez' ); ?></label></th>
                <td><input type="url" id="social_twitter" name="social_twitter" value="<?php echo esc_url( $social_twitter ); ?>" class="large-text" placeholder="https://twitter.com/empresa" /></td>
            </tr>
            <tr>
                <th><label for="social_linkedin"><?php _e( 'LinkedIn', 'lealez' ); ?></label></th>
                <td><input type="url" id="social_linkedin" name="social_linkedin" value="<?php echo esc_url( $social_linkedin ); ?>" class="large-text" placeholder="https://linkedin.com/company/empresa" /></td>
            </tr>
            <tr>
                <th><label for="social_youtube"><?php _e( 'YouTube', 'lealez' ); ?></label></th>
                <td><input type="url" id="social_youtube" name="social_youtube" value="<?php echo esc_url( $social_youtube ); ?>" class="large-text" placeholder="https://youtube.com/c/empresa" /></td>
            </tr>
            <tr>
                <th><label for="social_tiktok"><?php _e( 'TikTok', 'lealez' ); ?></label></th>
                <td><input type="url" id="social_tiktok" name="social_tiktok" value="<?php echo esc_url( $social_tiktok ); ?>" class="large-text" placeholder="https://tiktok.com/@empresa" /></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Google Wallet Meta Box
     */
    public function render_google_wallet_meta_box( $post ) {
        $google_issuer_id = get_post_meta( $post->ID, '_google_issuer_id', true );
        $google_merchant_id = get_post_meta( $post->ID, '_google_merchant_id', true );
        $google_service_account_email = get_post_meta( $post->ID, '_google_service_account_email', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="google_issuer_id"><?php _e( 'Google Issuer ID', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" id="google_issuer_id" name="google_issuer_id" value="<?php echo esc_attr( $google_issuer_id ); ?>" class="large-text" />
                    <p class="description"><?php _e( 'ID del emisor en Google Pay', 'lealez' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="google_merchant_id"><?php _e( 'Google Merchant ID', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" id="google_merchant_id" name="google_merchant_id" value="<?php echo esc_attr( $google_merchant_id ); ?>" class="large-text" />
                </td>
            </tr>
            <tr>
                <th><label for="google_service_account_email"><?php _e( 'Service Account Email', 'lealez' ); ?></label></th>
                <td>
                    <input type="email" id="google_service_account_email" name="google_service_account_email" value="<?php echo esc_attr( $google_service_account_email ); ?>" class="large-text" />
                    <p class="description"><?php _e( 'Email de la cuenta de servicio de Google', 'lealez' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="google_api_credentials"><?php _e( 'API Credentials (JSON)', 'lealez' ); ?></label></th>
                <td>
                    <textarea id="google_api_credentials" name="google_api_credentials" rows="6" class="large-text code" placeholder='{"type": "service_account", ...}'></textarea>
                    <p class="description"><?php _e( 'Pegue aquí el contenido del archivo JSON de credenciales. Se guardará encriptado.', 'lealez' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Legal Meta Box
     */
    public function render_legal_meta_box( $post ) {
        $tax_id = get_post_meta( $post->ID, '_tax_id', true );
        $business_license = get_post_meta( $post->ID, '_business_license', true );
        $registration_number = get_post_meta( $post->ID, '_registration_number', true );
        ?>
        <p>
            <label for="tax_id"><strong><?php _e( 'NIT / RFC / Tax ID', 'lealez' ); ?></strong></label>
            <input type="text" id="tax_id" name="tax_id" value="<?php echo esc_attr( $tax_id ); ?>" class="widefat" />
        </p>
        <p>
            <label for="business_license"><strong><?php _e( 'Licencia Comercial', 'lealez' ); ?></strong></label>
            <input type="text" id="business_license" name="business_license" value="<?php echo esc_attr( $business_license ); ?>" class="widefat" />
        </p>
        <p>
            <label for="registration_number"><strong><?php _e( 'Registro Mercantil', 'lealez' ); ?></strong></label>
            <input type="text" id="registration_number" name="registration_number" value="<?php echo esc_attr( $registration_number ); ?>" class="widefat" />
        </p>
        <?php
    }

/**
 * Render GMB Meta Box
 */
public function render_gmb_meta_box( $post ) {
    $gmb_connected = get_post_meta( $post->ID, '_gmb_connected', true );
    $gmb_account_name = get_post_meta( $post->ID, '_gmb_account_name', true );
    $gmb_account_email = get_post_meta( $post->ID, '_gmb_account_email', true );
    $gmb_connection_date = get_post_meta( $post->ID, '_gmb_connection_date', true );
    $gmb_last_manual_refresh = get_post_meta( $post->ID, '_gmb_last_manual_refresh', true );
    $gmb_accounts_last_fetch = get_post_meta( $post->ID, '_gmb_accounts_last_fetch', true );
    $gmb_locations_last_fetch = get_post_meta( $post->ID, '_gmb_locations_last_fetch', true );
    $gmb_locations_available = get_post_meta( $post->ID, '_gmb_locations_available', true );
    $gmb_total_locations_available = get_post_meta( $post->ID, '_gmb_total_locations_available', true );
    $gmb_accounts = get_post_meta( $post->ID, '_gmb_accounts', true );
    
    // Check if we can refresh now
    $can_refresh = true;
    $wait_message = '';
    if ( class_exists( 'Lealez_GMB_API' ) && $gmb_connected ) {
        $can_refresh = Lealez_GMB_API::can_refresh_now( $post->ID );
        if ( ! $can_refresh ) {
            $wait_minutes = Lealez_GMB_API::get_minutes_until_next_refresh( $post->ID );
            $wait_message = sprintf( __( 'Please wait %d minutes before refreshing again.', 'lealez' ), $wait_minutes );
        }
    }
    ?>
    <div class="lealez-gmb-connection">
        <?php if ( $gmb_connected ) : ?>
            <div class="notice notice-success inline">
                <p><strong><?php _e( 'Cuenta de Google My Business Conectada', 'lealez' ); ?></strong></p>
                <?php if ( $gmb_account_name ) : ?>
                    <p><?php _e( 'Cuenta:', 'lealez' ); ?> <strong><?php echo esc_html( $gmb_account_name ); ?></strong></p>
                <?php endif; ?>
                <?php if ( $gmb_account_email ) : ?>
                    <p><?php _e( 'Email:', 'lealez' ); ?> <?php echo esc_html( $gmb_account_email ); ?></p>
                <?php endif; ?>
                <?php if ( $gmb_connection_date ) : ?>
                    <p><?php _e( 'Conectado el:', 'lealez' ); ?> <?php echo date_i18n( get_option( 'date_format' ), $gmb_connection_date ); ?></p>
                <?php endif; ?>
            </div>
            
            <?php if ( $gmb_last_manual_refresh || $gmb_accounts_last_fetch || $gmb_locations_last_fetch ) : ?>
                <div class="notice notice-info inline" style="margin-top: 10px;">
                    <p><strong><?php _e( 'Información de Sincronización:', 'lealez' ); ?></strong></p>
                    <?php if ( $gmb_last_manual_refresh ) : ?>
                        <p><?php _e( 'Última actualización manual:', 'lealez' ); ?> 
                            <strong><?php echo human_time_diff( $gmb_last_manual_refresh, current_time( 'timestamp' ) ); ?> <?php _e( 'ago', 'lealez' ); ?></strong>
                        </p>
                    <?php endif; ?>
                    <?php if ( $gmb_accounts_last_fetch ) : ?>
                        <p><?php _e( 'Cuentas actualizadas:', 'lealez' ); ?> 
                            <?php echo human_time_diff( $gmb_accounts_last_fetch, current_time( 'timestamp' ) ); ?> <?php _e( 'ago', 'lealez' ); ?>
                        </p>
                    <?php endif; ?>
                    <?php if ( $gmb_locations_last_fetch ) : ?>
                        <p><?php _e( 'Ubicaciones actualizadas:', 'lealez' ); ?> 
                            <?php echo human_time_diff( $gmb_locations_last_fetch, current_time( 'timestamp' ) ); ?> <?php _e( 'ago', 'lealez' ); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ( ! $can_refresh && $wait_message ) : ?>
                <div class="notice notice-warning inline" style="margin-top: 10px;">
                    <p><strong>⚠ <?php echo esc_html( $wait_message ); ?></strong></p>
                    <p><?php _e( 'This helps avoid Google API rate limits.', 'lealez' ); ?></p>
                </div>
            <?php endif; ?>
            
            <!-- ACCOUNTS INFORMATION -->
            <?php if ( ! empty( $gmb_accounts ) && is_array( $gmb_accounts ) ) : ?>
                <div style="margin-top: 20px;">
                    <h3><?php _e( 'Cuentas de Google My Business', 'lealez' ); ?></h3>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php _e( 'Nombre de la Cuenta', 'lealez' ); ?></th>
                                <th><?php _e( 'Tipo', 'lealez' ); ?></th>
                                <th><?php _e( 'Role', 'lealez' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $gmb_accounts as $account ) : ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $account['accountName'] ?? $account['name'] ?? __( 'Sin nombre', 'lealez' ) ); ?></strong>
                                    </td>
                                    <td><?php echo esc_html( $account['type'] ?? '—' ); ?></td>
                                    <td><?php echo esc_html( $account['role'] ?? '—' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- LOCATIONS INFORMATION -->
            <div style="margin-top: 20px;">
                <h3><?php _e( 'Ubicaciones Disponibles en Google My Business', 'lealez' ); ?></h3>
                
                <?php if ( ! empty( $gmb_locations_available ) && is_array( $gmb_locations_available ) ) : ?>
                    <div class="notice notice-success inline">
                        <p>
                            <strong><?php printf( __( 'Se encontraron %d ubicaciones en tu cuenta de Google My Business', 'lealez' ), count( $gmb_locations_available ) ); ?></strong>
                        </p>
                    </div>
                    
                    <table class="widefat striped" style="margin-top: 15px;">
                        <thead>
                            <tr>
                                <th><?php _e( 'Nombre', 'lealez' ); ?></th>
                                <th><?php _e( 'Dirección', 'lealez' ); ?></th>
                                <th><?php _e( 'Teléfono', 'lealez' ); ?></th>
                                <th><?php _e( 'GMB ID', 'lealez' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $gmb_locations_available as $location ) : ?>
                                <?php
                                $location_name = $location['title'] ?? __( 'Sin nombre', 'lealez' );
                                $location_id = $location['name'] ?? '';
                                
                                // Extract address
                                $address_parts = array();
                                if ( isset( $location['storefrontAddress'] ) ) {
                                    $addr = $location['storefrontAddress'];
                                    if ( ! empty( $addr['addressLines'] ) ) {
                                        $address_parts[] = implode( ', ', $addr['addressLines'] );
                                    }
                                    if ( ! empty( $addr['locality'] ) ) {
                                        $address_parts[] = $addr['locality'];
                                    }
                                    if ( ! empty( $addr['administrativeArea'] ) ) {
                                        $address_parts[] = $addr['administrativeArea'];
                                    }
                                }
                                $full_address = ! empty( $address_parts ) ? implode( ', ', $address_parts ) : '—';
                                
                                // Extract phone
                                $phone = '—';
                                if ( ! empty( $location['phoneNumbers']['primaryPhone'] ) ) {
                                    $phone = $location['phoneNumbers']['primaryPhone'];
                                }
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $location_name ); ?></strong></td>
                                    <td><?php echo esc_html( $full_address ); ?></td>
                                    <td><?php echo esc_html( $phone ); ?></td>
                                    <td><code style="font-size: 11px;"><?php echo esc_html( $location_id ); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <p style="margin-top: 15px;">
                        <strong><?php _e( 'Nota:', 'lealez' ); ?></strong> 
                        <?php _e( 'Para usar estas ubicaciones, crea nuevos posts de tipo "Ubicación" y selecciona la ubicación GMB correspondiente.', 'lealez' ); ?>
                    </p>
                    
                <?php else : ?>
                    <div class="notice notice-warning inline">
                        <p>
                            <strong><?php _e( 'No se han cargado ubicaciones aún', 'lealez' ); ?></strong>
                        </p>
                        <p><?php _e( 'Haz clic en el botón "Actualizar Ubicaciones" a continuación para cargar tus ubicaciones desde Google My Business.', 'lealez' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            
            <p style="margin-top: 15px;">
                <button type="button" class="button button-secondary lealez-disconnect-gmb"><?php _e( 'Desconectar Cuenta', 'lealez' ); ?></button>
                <button type="button" class="button button-primary lealez-refresh-gmb-locations" <?php echo ! $can_refresh ? 'disabled' : ''; ?>>
                    <?php _e( 'Actualizar Ubicaciones', 'lealez' ); ?>
                </button>
            </p>
            <p class="description">
                <?php _e( 'Note: To avoid API rate limits, please wait at least 15 minutes between manual refreshes.', 'lealez' ); ?>
            </p>
        <?php else : ?>
            <div class="notice notice-info inline">
                <p><?php _e( 'No hay ninguna cuenta de Google My Business conectada.', 'lealez' ); ?></p>
            </div>
            <p>
                <button type="button" class="button button-primary lealez-connect-gmb"><?php _e( 'Conectar con Google My Business', 'lealez' ); ?></button>
            </p>
            <p class="description">
                <?php _e( 'After connecting, your Google My Business accounts and locations will be loaded automatically.', 'lealez' ); ?>
            </p>
        <?php endif; ?>
    </div>

    <hr>

    <h4><?php _e( 'Configuración de Sincronización', 'lealez' ); ?></h4>
    <table class="form-table">
        <tr>
            <th><label for="gmb_auto_refresh_token"><?php _e( 'Refresh Automático', 'lealez' ); ?></label></th>
            <td>
                <label>
                    <input type="checkbox" id="gmb_auto_refresh_token" name="gmb_auto_refresh_token" value="1" <?php checked( get_post_meta( $post->ID, '_gmb_auto_refresh_token', true ), '1' ); ?> />
                    <?php _e( 'Renovar tokens automáticamente', 'lealez' ); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th><label for="gmb_delegation_enabled"><?php _e( 'Permitir Delegación', 'lealez' ); ?></label></th>
            <td>
                <label>
                    <input type="checkbox" id="gmb_delegation_enabled" name="gmb_delegation_enabled" value="1" <?php checked( get_post_meta( $post->ID, '_gmb_delegation_enabled', true ), '1' ); ?> />
                    <?php _e( 'Permitir que otros usuarios creen ubicaciones', 'lealez' ); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th><label for="gmb_total_auto_sync_enabled"><?php _e( 'Sincronización Automática', 'lealez' ); ?></label></th>
            <td>
                <label>
                    <input type="checkbox" id="gmb_total_auto_sync_enabled" name="gmb_total_auto_sync_enabled" value="1" <?php checked( get_post_meta( $post->ID, '_gmb_total_auto_sync_enabled', true ), '1' ); ?> />
                    <?php _e( 'Sincronizar métricas automáticamente', 'lealez' ); ?>
                </label>
                <p class="description"><?php _e( '(Feature not yet implemented - coming soon)', 'lealez' ); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="gmb_total_sync_frequency"><?php _e( 'Frecuencia de Sincronización', 'lealez' ); ?></label></th>
            <td>
                <select id="gmb_total_sync_frequency" name="gmb_total_sync_frequency">
                    <option value="daily" <?php selected( get_post_meta( $post->ID, '_gmb_total_sync_frequency', true ), 'daily' ); ?>><?php _e( 'Diaria', 'lealez' ); ?></option>
                    <option value="weekly" <?php selected( get_post_meta( $post->ID, '_gmb_total_sync_frequency', true ), 'weekly' ); ?>><?php _e( 'Semanal', 'lealez' ); ?></option>
                    <option value="monthly" <?php selected( get_post_meta( $post->ID, '_gmb_total_sync_frequency', true ), 'monthly' ); ?>><?php _e( 'Mensual', 'lealez' ); ?></option>
                </select>
            </td>
        </tr>
    </table>

    <hr>

    <h4><?php _e( 'Configuración de Reportes', 'lealez' ); ?></h4>
    <table class="form-table">
        <tr>
            <th><label for="gmb_reports_email_enabled"><?php _e( 'Reportes por Email', 'lealez' ); ?></label></th>
            <td>
                <label>
                    <input type="checkbox" id="gmb_reports_email_enabled" name="gmb_reports_email_enabled" value="1" <?php checked( get_post_meta( $post->ID, '_gmb_reports_email_enabled', true ), '1' ); ?> />
                    <?php _e( 'Enviar reportes periódicos por email', 'lealez' ); ?>
                </label>
                <p class="description"><?php _e( '(Feature not yet implemented - coming soon)', 'lealez' ); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="gmb_reports_frequency"><?php _e( 'Frecuencia de Reportes', 'lealez' ); ?></label></th>
            <td>
                <select id="gmb_reports_frequency" name="gmb_reports_frequency">
                    <option value="weekly" <?php selected( get_post_meta( $post->ID, '_gmb_reports_frequency', true ), 'weekly' ); ?>><?php _e( 'Semanal', 'lealez' ); ?></option>
                    <option value="monthly" <?php selected( get_post_meta( $post->ID, '_gmb_reports_frequency', true ), 'monthly' ); ?>><?php _e( 'Mensual', 'lealez' ); ?></option>
                    <option value="quarterly" <?php selected( get_post_meta( $post->ID, '_gmb_reports_frequency', true ), 'quarterly' ); ?>><?php _e( 'Trimestral', 'lealez' ); ?></option>
                </select>
            </td>
        </tr>
    </table>
    
    <style>
    .lealez-gmb-connection .widefat {
        margin-top: 10px;
    }
    .lealez-gmb-connection .widefat th {
        background: #f0f0f1;
        font-weight: 600;
    }
    .lealez-gmb-connection code {
        background: #f0f0f1;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
    }
    </style>
    <?php
}

    /**
     * Render Stats Meta Box
     */
    public function render_stats_meta_box( $post ) {
        $total_locations = get_post_meta( $post->ID, '_total_locations', true );
        $total_programs = get_post_meta( $post->ID, '_total_programs', true );
        $total_active_cards = get_post_meta( $post->ID, '_total_active_cards', true );
        $status = get_post_meta( $post->ID, '_status', true );
        $date_created = get_post_meta( $post->ID, '_date_created', true );
        ?>
        <p>
            <strong><?php _e( 'Estado:', 'lealez' ); ?></strong>
            <select id="business_status" name="status" class="widefat">
                <option value="active" <?php selected( $status, 'active' ); ?>><?php _e( 'Activo', 'lealez' ); ?></option>
                <option value="inactive" <?php selected( $status, 'inactive' ); ?>><?php _e( 'Inactivo', 'lealez' ); ?></option>
                <option value="suspended" <?php selected( $status, 'suspended' ); ?>><?php _e( 'Suspendido', 'lealez' ); ?></option>
            </select>
        </p>
        <hr>
        <p><strong><?php _e( 'Total Sucursales:', 'lealez' ); ?></strong> <?php echo esc_html( $total_locations ? $total_locations : '0' ); ?></p>
        <p><strong><?php _e( 'Programas de Lealtad:', 'lealez' ); ?></strong> <?php echo esc_html( $total_programs ? $total_programs : '0' ); ?></p>
        <p><strong><?php _e( 'Tarjetas Activas:', 'lealez' ); ?></strong> <?php echo esc_html( $total_active_cards ? $total_active_cards : '0' ); ?></p>
        <?php if ( $date_created ) : ?>
            <p><strong><?php _e( 'Creado el:', 'lealez' ); ?></strong> <?php echo date_i18n( get_option( 'date_format' ), $date_created ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render Permissions Meta Box
     */
    public function render_permissions_meta_box( $post ) {
        $admin_users = get_post_meta( $post->ID, '_admin_users', true );
        $manager_users = get_post_meta( $post->ID, '_manager_users', true );

        $admin_users_array = maybe_unserialize( $admin_users );
        $manager_users_array = maybe_unserialize( $manager_users );

        if ( ! is_array( $admin_users_array ) ) {
            $admin_users_array = array();
        }
        if ( ! is_array( $manager_users_array ) ) {
            $manager_users_array = array();
        }

        $all_users = get_users( array( 'orderby' => 'display_name' ) );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="admin_users"><?php _e( 'Administradores', 'lealez' ); ?></label></th>
                <td>
                    <select id="admin_users" name="admin_users[]" multiple class="widefat" style="height: 150px;">
                        <?php foreach ( $all_users as $user ) : ?>
                            <option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( in_array( $user->ID, $admin_users_array ) ); ?>>
                                <?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php _e( 'Usuarios con acceso de administrador a esta empresa', 'lealez' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="manager_users"><?php _e( 'Gerentes', 'lealez' ); ?></label></th>
                <td>
                    <select id="manager_users" name="manager_users[]" multiple class="widefat" style="height: 150px;">
                        <?php foreach ( $all_users as $user ) : ?>
                            <option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( in_array( $user->ID, $manager_users_array ) ); ?>>
                                <?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php _e( 'Usuarios con acceso de gerente a esta empresa', 'lealez' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save meta boxes data
     */
    public function save_meta_boxes( $post_id, $post ) {
        // Verify nonce
        if ( ! isset( $_POST['lealez_business_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['lealez_business_meta_box_nonce'], 'lealez_business_meta_box' ) ) {
            return;
        }

        // Check autosave
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Check permissions
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Define all meta fields
        $meta_fields = array(
            // Basic Info
            'business_name',
            'business_legal_name',
            'business_type',
            'business_description',
            'business_founded_date',

            // Brand Identity
            'brand_logo',
            'brand_logo_id',
            'brand_icon',
            'brand_cover_image',
            'brand_tagline',

            // Categorization
            'business_industry',
            'business_category',
            'business_subcategory',

            // Contact
            'corporate_email',
            'corporate_phone',
            'corporate_website',
            'corporate_address',
            'corporate_city',
            'corporate_state',
            'corporate_country',
            'corporate_postal_code',

            // Social
            'social_facebook',
            'social_instagram',
            'social_twitter',
            'social_linkedin',
            'social_youtube',
            'social_tiktok',

            // Google Wallet
            'google_issuer_id',
            'google_merchant_id',
            'google_service_account_email',

            // Legal
            'tax_id',
            'business_license',
            'registration_number',

            // Status
            'status',

            // GMB Settings
            'gmb_auto_refresh_token',
            'gmb_delegation_enabled',
            'gmb_total_auto_sync_enabled',
            'gmb_total_sync_frequency',
            'gmb_reports_email_enabled',
            'gmb_reports_frequency',
        );

        // Save text fields
        foreach ( $meta_fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, '_' . $field, sanitize_text_field( $_POST[ $field ] ) );
            }
        }

        // Save brand colors (array)
        if ( isset( $_POST['brand_colors'] ) && is_array( $_POST['brand_colors'] ) ) {
            $brand_colors = array_map( 'sanitize_hex_color', $_POST['brand_colors'] );
            update_post_meta( $post_id, '_brand_colors', $brand_colors );
        }

        // Save admin users (array)
        if ( isset( $_POST['admin_users'] ) && is_array( $_POST['admin_users'] ) ) {
            $admin_users = array_map( 'intval', $_POST['admin_users'] );
            update_post_meta( $post_id, '_admin_users', $admin_users );
        } else {
            update_post_meta( $post_id, '_admin_users', array() );
        }

        // Save manager users (array)
        if ( isset( $_POST['manager_users'] ) && is_array( $_POST['manager_users'] ) ) {
            $manager_users = array_map( 'intval', $_POST['manager_users'] );
            update_post_meta( $post_id, '_manager_users', $manager_users );
        } else {
            update_post_meta( $post_id, '_manager_users', array() );
        }

        // Save Google API credentials (encrypted)
        if ( isset( $_POST['google_api_credentials'] ) && ! empty( $_POST['google_api_credentials'] ) ) {
            $credentials = sanitize_textarea_field( $_POST['google_api_credentials'] );
            // TODO: Implement encryption before saving
            update_post_meta( $post_id, '_google_api_credentials', $credentials );
        }

        // Set date_created if new post
        if ( ! get_post_meta( $post_id, '_date_created', true ) ) {
            update_post_meta( $post_id, '_date_created', time() );
        }

        // Always update last_updated
        update_post_meta( $post_id, '_last_updated', time() );
    }

    /**
     * Set custom columns for admin list
     */
    public function set_custom_columns( $columns ) {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = __( 'Nombre de la Empresa', 'lealez' );
        $new_columns['business_type'] = __( 'Tipo', 'lealez' );
        $new_columns['locations'] = __( 'Sucursales', 'lealez' );
        $new_columns['programs'] = __( 'Programas', 'lealez' );
        $new_columns['gmb_status'] = __( 'GMB', 'lealez' );
        $new_columns['status'] = __( 'Estado', 'lealez' );
        $new_columns['date'] = __( 'Fecha', 'lealez' );

        return $new_columns;
    }

    /**
     * Display custom column content
     */
    public function custom_column_content( $column, $post_id ) {
        switch ( $column ) {
            case 'business_type':
                $business_type = get_post_meta( $post_id, '_business_type', true );
                if ( $business_type === 'single_location' ) {
                    echo '<span class="dashicons dashicons-location"></span> ' . __( 'Única', 'lealez' );
                } elseif ( $business_type === 'multi_location' ) {
                    echo '<span class="dashicons dashicons-location-alt"></span> ' . __( 'Multi', 'lealez' );
                }
                break;

            case 'locations':
                $total_locations = get_post_meta( $post_id, '_total_locations', true );
                echo esc_html( $total_locations ? $total_locations : '0' );
                break;

            case 'programs':
                $total_programs = get_post_meta( $post_id, '_total_programs', true );
                echo esc_html( $total_programs ? $total_programs : '0' );
                break;

            case 'gmb_status':
                $gmb_connected = get_post_meta( $post_id, '_gmb_connected', true );
                if ( $gmb_connected ) {
                    echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="' . __( 'Conectado', 'lealez' ) . '"></span>';
                } else {
                    echo '<span class="dashicons dashicons-dismiss" style="color: #dc3232;" title="' . __( 'No conectado', 'lealez' ) . '"></span>';
                }
                break;

            case 'status':
                $status = get_post_meta( $post_id, '_status', true );
                $status_labels = array(
                    'active' => '<span style="color: #46b450;">● ' . __( 'Activo', 'lealez' ) . '</span>',
                    'inactive' => '<span style="color: #999;">● ' . __( 'Inactivo', 'lealez' ) . '</span>',
                    'suspended' => '<span style="color: #dc3232;">● ' . __( 'Suspendido', 'lealez' ) . '</span>',
                );
                echo isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : '';
                break;
        }
    }
}

// Initialize the class
new Lealez_Business_CPT();
