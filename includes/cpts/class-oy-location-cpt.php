<?php
/**
 * OY Location CPT Class
 *
 * Handles the registration and functionality of the oy_location Custom Post Type
 * Represents each physical business location/branch
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
 * Class OY_Location_CPT
 *
 * Manages the oy_location custom post type
 */
class OY_Location_CPT {

    /**
     * Post type slug
     *
     * @var string
     */
    public $post_type = 'oy_location';

    /**
     * Meta box nonce name
     *
     * @var string
     */
    private $nonce_name = 'oy_location_meta_nonce';

    /**
     * Meta box nonce action
     *
     * @var string
     */
    private $nonce_action = 'oy_location_save_meta';

    /**
     * Constructor
     */
    public function __construct() {
        // Register CPT
        add_action( 'init', array( $this, 'register_post_type' ) );
        
        // Add meta boxes
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        
        // Save meta data
        add_action( 'save_post_oy_location', array( $this, 'save_meta_boxes' ), 10, 2 );
        
        // Customize admin columns
        add_filter( 'manage_oy_location_posts_columns', array( $this, 'set_custom_columns' ) );
        add_action( 'manage_oy_location_posts_custom_column', array( $this, 'custom_column_content' ), 10, 2 );
        
        // Make columns sortable
        add_filter( 'manage_edit-oy_location_sortable_columns', array( $this, 'sortable_columns' ) );
        
        // Admin scripts and styles
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
        
        // Update parent business counters when location is saved/deleted
        add_action( 'save_post_oy_location', array( $this, 'update_parent_business_counter' ), 20, 2 );
        add_action( 'before_delete_post', array( $this, 'update_parent_on_delete' ) );
        
        // Update parent business aggregated metrics
        add_action( 'updated_post_meta', array( $this, 'sync_metrics_to_parent' ), 10, 4 );
    }

    /**
     * Register the custom post type
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => _x( 'Ubicaciones', 'Post Type General Name', 'lealez' ),
            'singular_name'         => _x( 'Ubicación', 'Post Type Singular Name', 'lealez' ),
            'menu_name'             => __( 'Ubicaciones', 'lealez' ),
            'name_admin_bar'        => __( 'Ubicación', 'lealez' ),
            'archives'              => __( 'Archivo de Ubicaciones', 'lealez' ),
            'attributes'            => __( 'Atributos de Ubicación', 'lealez' ),
            'parent_item_colon'     => __( 'Ubicación Padre:', 'lealez' ),
            'all_items'             => __( 'Todas las Ubicaciones', 'lealez' ),
            'add_new_item'          => __( 'Agregar Nueva Ubicación', 'lealez' ),
            'add_new'               => __( 'Agregar Nueva', 'lealez' ),
            'new_item'              => __( 'Nueva Ubicación', 'lealez' ),
            'edit_item'             => __( 'Editar Ubicación', 'lealez' ),
            'update_item'           => __( 'Actualizar Ubicación', 'lealez' ),
            'view_item'             => __( 'Ver Ubicación', 'lealez' ),
            'view_items'            => __( 'Ver Ubicaciones', 'lealez' ),
            'search_items'          => __( 'Buscar Ubicación', 'lealez' ),
            'not_found'             => __( 'No se encontraron ubicaciones', 'lealez' ),
            'not_found_in_trash'    => __( 'No se encontraron ubicaciones en la papelera', 'lealez' ),
            'featured_image'        => __( 'Foto de Portada', 'lealez' ),
            'set_featured_image'    => __( 'Establecer foto de portada', 'lealez' ),
            'remove_featured_image' => __( 'Remover foto de portada', 'lealez' ),
            'use_featured_image'    => __( 'Usar como foto de portada', 'lealez' ),
            'insert_into_item'      => __( 'Insertar en ubicación', 'lealez' ),
            'uploaded_to_this_item' => __( 'Subido a esta ubicación', 'lealez' ),
            'items_list'            => __( 'Lista de ubicaciones', 'lealez' ),
            'items_list_navigation' => __( 'Navegación de lista de ubicaciones', 'lealez' ),
            'filter_items_list'     => __( 'Filtrar lista de ubicaciones', 'lealez' ),
        );

        $args = array(
            'label'                 => __( 'Ubicación', 'lealez' ),
            'description'           => __( 'Ubicaciones físicas del negocio / sucursales', 'lealez' ),
            'labels'                => $labels,
            'supports'              => array( 'title', 'editor', 'thumbnail', 'author' ),
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 21,
            'menu_icon'             => 'dashicons-location',
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => true,
            'can_export'            => true,
            'has_archive'           => true,
            'exclude_from_search'   => false,
            'publicly_queryable'    => true,
            'capability_type'       => 'post',
            'show_in_rest'          => false,
            'rest_base'             => 'locations',
            'rest_controller_class' => 'WP_REST_Posts_Controller',
        );

        register_post_type( $this->post_type, $args );
    }

    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        // Parent Business Selection
        add_meta_box(
            'oy_location_parent_business',
            __( 'Empresa/Negocio', 'lealez' ),
            array( $this, 'render_parent_business_meta_box' ),
            $this->post_type,
            'side',
            'high'
        );

        // Basic Information
        add_meta_box(
            'oy_location_basic_info',
            __( 'Información Básica', 'lealez' ),
            array( $this, 'render_basic_info_meta_box' ),
            $this->post_type,
            'normal',
            'high'
        );

        // Address and Geolocation
        add_meta_box(
            'oy_location_address',
            __( 'Dirección y Geolocalización', 'lealez' ),
            array( $this, 'render_address_meta_box' ),
            $this->post_type,
            'normal',
            'high'
        );

        // Contact Information
        add_meta_box(
            'oy_location_contact',
            __( 'Información de Contacto', 'lealez' ),
            array( $this, 'render_contact_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // Business Hours
        add_meta_box(
            'oy_location_hours',
            __( 'Horarios de Atención', 'lealez' ),
            array( $this, 'render_hours_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // Google My Business Integration
        add_meta_box(
            'oy_location_gmb',
            __( 'Integración Google My Business', 'lealez' ),
            array( $this, 'render_gmb_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // Attributes and Features
        add_meta_box(
            'oy_location_attributes',
            __( 'Atributos y Características', 'lealez' ),
            array( $this, 'render_attributes_meta_box' ),
            $this->post_type,
            'normal',
            'low'
        );

        // GMB Metrics
        add_meta_box(
            'oy_location_gmb_metrics',
            __( 'Métricas de Google My Business', 'lealez' ),
            array( $this, 'render_gmb_metrics_meta_box' ),
            $this->post_type,
            'side',
            'default'
        );

        // Loyalty Program Settings
        add_meta_box(
            'oy_location_loyalty',
            __( 'Configuración de Lealtad', 'lealez' ),
            array( $this, 'render_loyalty_meta_box' ),
            $this->post_type,
            'normal',
            'low'
        );
    }

    /**
     * Render Parent Business meta box
     */
    public function render_parent_business_meta_box( $post ) {
        wp_nonce_field( $this->nonce_action, $this->nonce_name );
        
        $parent_business_id = get_post_meta( $post->ID, 'parent_business_id', true );
        
        // Get all businesses
        $businesses = get_posts( array(
            'post_type'      => 'oy_business',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post_status'    => 'publish',
        ) );
        
        ?>
        <div class="oy-meta-field">
            <label for="parent_business_id">
                <strong><?php _e( 'Empresa/Negocio', 'lealez' ); ?> <span class="required">*</span></strong>
            </label>
            <select name="parent_business_id" id="parent_business_id" class="widefat" required>
                <option value=""><?php _e( 'Seleccionar empresa...', 'lealez' ); ?></option>
                <?php foreach ( $businesses as $business ) : ?>
                    <option value="<?php echo esc_attr( $business->ID ); ?>" <?php selected( $parent_business_id, $business->ID ); ?>>
                        <?php echo esc_html( $business->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="description">
                <?php _e( 'Selecciona la empresa a la que pertenece esta ubicación.', 'lealez' ); ?>
            </p>
        </div>
        
        <?php if ( $parent_business_id ) : ?>
            <div class="oy-meta-field" style="margin-top: 15px;">
                <a href="<?php echo esc_url( get_edit_post_link( $parent_business_id ) ); ?>" class="button button-secondary" target="_blank">
                    <?php _e( 'Ver Empresa', 'lealez' ); ?>
                </a>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Render Basic Information meta box
     */
    public function render_basic_info_meta_box( $post ) {
        $location_code = get_post_meta( $post->ID, 'location_code', true );
        $location_short_description = get_post_meta( $post->ID, 'location_short_description', true );
        $location_status = get_post_meta( $post->ID, 'location_status', true );
        $opening_date = get_post_meta( $post->ID, 'opening_date', true );
        $closing_date = get_post_meta( $post->ID, 'closing_date', true );
        
        if ( empty( $location_status ) ) {
            $location_status = 'active';
        }
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="location_code"><?php _e( 'Código de Ubicación', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="location_code" 
                           id="location_code" 
                           value="<?php echo esc_attr( $location_code ); ?>" 
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'Ej: STB-MDE-001', 'lealez' ); ?>">
                    <p class="description">
                        <?php _e( 'Código único interno para identificar esta ubicación.', 'lealez' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_short_description"><?php _e( 'Descripción Breve', 'lealez' ); ?></label>
                </th>
                <td>
                    <textarea name="location_short_description" 
                              id="location_short_description" 
                              rows="3" 
                              class="large-text"
                              maxlength="160"
                              placeholder="<?php esc_attr_e( 'Máximo 160 caracteres', 'lealez' ); ?>"><?php echo esc_textarea( $location_short_description ); ?></textarea>
                    <p class="description">
                        <?php _e( 'Descripción corta (máximo 160 caracteres) - Se usa en listados y previsualizaciones.', 'lealez' ); ?>
                        <span id="char-count">0/160</span>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_status"><?php _e( 'Estado', 'lealez' ); ?></label>
                </th>
                <td>
                    <select name="location_status" id="location_status" class="regular-text">
                        <option value="active" <?php selected( $location_status, 'active' ); ?>><?php _e( 'Activa', 'lealez' ); ?></option>
                        <option value="inactive" <?php selected( $location_status, 'inactive' ); ?>><?php _e( 'Inactiva', 'lealez' ); ?></option>
                        <option value="temporarily_closed" <?php selected( $location_status, 'temporarily_closed' ); ?>><?php _e( 'Cerrada Temporalmente', 'lealez' ); ?></option>
                        <option value="permanently_closed" <?php selected( $location_status, 'permanently_closed' ); ?>><?php _e( 'Cerrada Permanentemente', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="opening_date"><?php _e( 'Fecha de Apertura', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="date" 
                           name="opening_date" 
                           id="opening_date" 
                           value="<?php echo esc_attr( $opening_date ); ?>" 
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="closing_date"><?php _e( 'Fecha de Cierre', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="date" 
                           name="closing_date" 
                           id="closing_date" 
                           value="<?php echo esc_attr( $closing_date ); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e( 'Solo si la ubicación está cerrada permanentemente.', 'lealez' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Address meta box
     */
    public function render_address_meta_box( $post ) {
        $address_line1 = get_post_meta( $post->ID, 'location_address_line1', true );
        $address_line2 = get_post_meta( $post->ID, 'location_address_line2', true );
        $neighborhood = get_post_meta( $post->ID, 'location_neighborhood', true );
        $city = get_post_meta( $post->ID, 'location_city', true );
        $state = get_post_meta( $post->ID, 'location_state', true );
        $country = get_post_meta( $post->ID, 'location_country', true );
        $postal_code = get_post_meta( $post->ID, 'location_postal_code', true );
        $latitude = get_post_meta( $post->ID, 'location_latitude', true );
        $longitude = get_post_meta( $post->ID, 'location_longitude', true );
        $place_id = get_post_meta( $post->ID, 'location_place_id', true );
        $plus_code = get_post_meta( $post->ID, 'location_plus_code', true );
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="location_address_line1"><?php _e( 'Dirección Principal', 'lealez' ); ?> <span class="required">*</span></label>
                </th>
                <td>
                    <input type="text" 
                           name="location_address_line1" 
                           id="location_address_line1" 
                           value="<?php echo esc_attr( $address_line1 ); ?>" 
                           class="large-text"
                           required
                           placeholder="<?php esc_attr_e( 'Ej: Calle 10 # 25-30', 'lealez' ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_address_line2"><?php _e( 'Complemento', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="location_address_line2" 
                           id="location_address_line2" 
                           value="<?php echo esc_attr( $address_line2 ); ?>" 
                           class="large-text"
                           placeholder="<?php esc_attr_e( 'Ej: Local 202, Piso 2, Edificio Torre Norte', 'lealez' ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_neighborhood"><?php _e( 'Barrio/Colonia', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="location_neighborhood" 
                           id="location_neighborhood" 
                           value="<?php echo esc_attr( $neighborhood ); ?>" 
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_city"><?php _e( 'Ciudad', 'lealez' ); ?> <span class="required">*</span></label>
                </th>
                <td>
                    <input type="text" 
                           name="location_city" 
                           id="location_city" 
                           value="<?php echo esc_attr( $city ); ?>" 
                           class="regular-text"
                           required>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_state"><?php _e( 'Estado/Departamento', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="location_state" 
                           id="location_state" 
                           value="<?php echo esc_attr( $state ); ?>" 
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_country"><?php _e( 'País', 'lealez' ); ?> <span class="required">*</span></label>
                </th>
                <td>
                    <input type="text" 
                           name="location_country" 
                           id="location_country" 
                           value="<?php echo esc_attr( $country ); ?>" 
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'Código ISO: CO, MX, US', 'lealez' ); ?>"
                           required
                           maxlength="2">
                    <p class="description">
                        <?php _e( 'Código ISO de 2 letras del país.', 'lealez' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_postal_code"><?php _e( 'Código Postal', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="location_postal_code" 
                           id="location_postal_code" 
                           value="<?php echo esc_attr( $postal_code ); ?>" 
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e( 'Coordenadas GPS', 'lealez' ); ?></label>
                </th>
                <td>
                    <div style="display: flex; gap: 10px;">
                        <div>
                            <label for="location_latitude"><?php _e( 'Latitud', 'lealez' ); ?></label>
                            <input type="text" 
                                   name="location_latitude" 
                                   id="location_latitude" 
                                   value="<?php echo esc_attr( $latitude ); ?>" 
                                   class="regular-text"
                                   placeholder="6.2476376">
                        </div>
                        <div>
                            <label for="location_longitude"><?php _e( 'Longitud', 'lealez' ); ?></label>
                            <input type="text" 
                                   name="location_longitude" 
                                   id="location_longitude" 
                                   value="<?php echo esc_attr( $longitude ); ?>" 
                                   class="regular-text"
                                   placeholder="-75.5658153">
                        </div>
                    </div>
                    <p class="description">
                        <?php _e( 'Coordenadas para geolocalización y mapas.', 'lealez' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_place_id"><?php _e( 'Google Place ID', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="location_place_id" 
                           id="location_place_id" 
                           value="<?php echo esc_attr( $place_id ); ?>" 
                           class="large-text"
                           placeholder="ChIJ...">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_plus_code"><?php _e( 'Google Plus Code', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="location_plus_code" 
                           id="location_plus_code" 
                           value="<?php echo esc_attr( $plus_code ); ?>" 
                           class="regular-text"
                           placeholder="849VCWC8+R9">
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Contact Information meta box
     */
    public function render_contact_meta_box( $post ) {
        $phone = get_post_meta( $post->ID, 'location_phone', true );
        $phone_additional = get_post_meta( $post->ID, 'location_phone_additional', true );
        $whatsapp = get_post_meta( $post->ID, 'location_whatsapp', true );
        $email = get_post_meta( $post->ID, 'location_email', true );
        $website = get_post_meta( $post->ID, 'location_website', true );
        $booking_url = get_post_meta( $post->ID, 'location_booking_url', true );
        $menu_url = get_post_meta( $post->ID, 'location_menu_url', true );
        $order_url = get_post_meta( $post->ID, 'location_order_url', true );
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="location_phone"><?php _e( 'Teléfono Principal', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="tel" 
                           name="location_phone" 
                           id="location_phone" 
                           value="<?php echo esc_attr( $phone ); ?>" 
                           class="regular-text"
                           placeholder="+573001234567">
                    <p class="description">
                        <?php _e( 'Formato E.164 recomendado: +573001234567', 'lealez' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_phone_additional"><?php _e( 'Teléfono Adicional', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="tel" 
                           name="location_phone_additional" 
                           id="location_phone_additional" 
                           value="<?php echo esc_attr( $phone_additional ); ?>" 
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_whatsapp"><?php _e( 'WhatsApp Business', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="tel" 
                           name="location_whatsapp" 
                           id="location_whatsapp" 
                           value="<?php echo esc_attr( $whatsapp ); ?>" 
                           class="regular-text"
                           placeholder="+573001234567">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_email"><?php _e( 'Email', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="email" 
                           name="location_email" 
                           id="location_email" 
                           value="<?php echo esc_attr( $email ); ?>" 
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_website"><?php _e( 'Sitio Web', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="url" 
                           name="location_website" 
                           id="location_website" 
                           value="<?php echo esc_attr( $website ); ?>" 
                           class="large-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_booking_url"><?php _e( 'URL de Reservas', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="url" 
                           name="location_booking_url" 
                           id="location_booking_url" 
                           value="<?php echo esc_attr( $booking_url ); ?>" 
                           class="large-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_menu_url"><?php _e( 'URL del Menú', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="url" 
                           name="location_menu_url" 
                           id="location_menu_url" 
                           value="<?php echo esc_attr( $menu_url ); ?>" 
                           class="large-text">
                    <p class="description">
                        <?php _e( 'Para restaurantes y cafeterías.', 'lealez' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_order_url"><?php _e( 'URL para Ordenar Online', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="url" 
                           name="location_order_url" 
                           id="location_order_url" 
                           value="<?php echo esc_attr( $order_url ); ?>" 
                           class="large-text">
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Business Hours meta box
     */
    public function render_hours_meta_box( $post ) {
        $days = array(
            'monday'    => __( 'Lunes', 'lealez' ),
            'tuesday'   => __( 'Martes', 'lealez' ),
            'wednesday' => __( 'Miércoles', 'lealez' ),
            'thursday'  => __( 'Jueves', 'lealez' ),
            'friday'    => __( 'Viernes', 'lealez' ),
            'saturday'  => __( 'Sábado', 'lealez' ),
            'sunday'    => __( 'Domingo', 'lealez' ),
        );
        
        $timezone = get_post_meta( $post->ID, 'location_hours_timezone', true );
        if ( empty( $timezone ) ) {
            $timezone = 'America/Bogota';
        }
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="location_hours_timezone"><?php _e( 'Zona Horaria', 'lealez' ); ?></label>
                </th>
                <td>
                    <select name="location_hours_timezone" id="location_hours_timezone" class="regular-text">
                        <?php
                        $timezones = timezone_identifiers_list();
                        foreach ( $timezones as $tz ) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr( $tz ),
                                selected( $timezone, $tz, false ),
                                esc_html( $tz )
                            );
                        }
                        ?>
                    </select>
                </td>
            </tr>
        </table>
        
        <h4><?php _e( 'Horarios por Día', 'lealez' ); ?></h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php _e( 'Día', 'lealez' ); ?></th>
                    <th><?php _e( 'Cerrado', 'lealez' ); ?></th>
                    <th><?php _e( 'Hora Apertura', 'lealez' ); ?></th>
                    <th><?php _e( 'Hora Cierre', 'lealez' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $days as $day_key => $day_label ) :
                    $hours = get_post_meta( $post->ID, 'location_hours_' . $day_key, true );
                    if ( ! is_array( $hours ) ) {
                        $hours = array( 'closed' => false, 'open' => '09:00', 'close' => '18:00' );
                    }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $day_label ); ?></strong></td>
                        <td>
                            <input type="checkbox" 
                                   name="location_hours_<?php echo esc_attr( $day_key ); ?>[closed]" 
                                   value="1" 
                                   <?php checked( ! empty( $hours['closed'] ), true ); ?>
                                   class="hours-closed-checkbox"
                                   data-day="<?php echo esc_attr( $day_key ); ?>">
                        </td>
                        <td>
                            <input type="time" 
                                   name="location_hours_<?php echo esc_attr( $day_key ); ?>[open]" 
                                   value="<?php echo esc_attr( $hours['open'] ?? '09:00' ); ?>"
                                   class="hours-time-input hours-open-<?php echo esc_attr( $day_key ); ?>"
                                   <?php echo ! empty( $hours['closed'] ) ? 'disabled' : ''; ?>>
                        </td>
                        <td>
                            <input type="time" 
                                   name="location_hours_<?php echo esc_attr( $day_key ); ?>[close]" 
                                   value="<?php echo esc_attr( $hours['close'] ?? '18:00' ); ?>"
                                   class="hours-time-input hours-close-<?php echo esc_attr( $day_key ); ?>"
                                   <?php echo ! empty( $hours['closed'] ) ? 'disabled' : ''; ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.hours-closed-checkbox').on('change', function() {
                var day = $(this).data('day');
                var isClosed = $(this).is(':checked');
                $('.hours-open-' + day + ', .hours-close-' + day).prop('disabled', isClosed);
            });
        });
        </script>
        <?php
    }

    /**
     * Render GMB Integration meta box
     */
    public function render_gmb_meta_box( $post ) {
        $gmb_location_id = get_post_meta( $post->ID, 'gmb_location_id', true );
        $gmb_account_id = get_post_meta( $post->ID, 'gmb_account_id', true );
        $gmb_verified = get_post_meta( $post->ID, 'gmb_verified', true );
        $gmb_verification_method = get_post_meta( $post->ID, 'gmb_verification_method', true );
        $gmb_auto_sync_enabled = get_post_meta( $post->ID, 'gmb_auto_sync_enabled', true );
        $gmb_sync_frequency = get_post_meta( $post->ID, 'gmb_sync_frequency', true );
        $gmb_last_sync = get_post_meta( $post->ID, 'gmb_last_sync', true );
        
        if ( empty( $gmb_sync_frequency ) ) {
            $gmb_sync_frequency = 'daily';
        }
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="gmb_location_id"><?php _e( 'GMB Location ID', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="gmb_location_id" 
                           id="gmb_location_id" 
                           value="<?php echo esc_attr( $gmb_location_id ); ?>" 
                           class="large-text"
                           readonly>
                    <p class="description">
                        <?php _e( 'Este ID se obtiene automáticamente desde Google My Business.', 'lealez' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="gmb_account_id"><?php _e( 'GMB Account ID', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="gmb_account_id" 
                           id="gmb_account_id" 
                           value="<?php echo esc_attr( $gmb_account_id ); ?>" 
                           class="large-text"
                           readonly>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e( 'Estado de Verificación', 'lealez' ); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="gmb_verified" 
                               value="1" 
                               <?php checked( $gmb_verified, '1' ); ?>>
                        <?php _e( 'Ubicación verificada en Google My Business', 'lealez' ); ?>
                    </label>
                </td>
            </tr>
            <?php if ( $gmb_verified ) : ?>
            <tr>
                <th scope="row">
                    <label for="gmb_verification_method"><?php _e( 'Método de Verificación', 'lealez' ); ?></label>
                </th>
                <td>
                    <select name="gmb_verification_method" id="gmb_verification_method" class="regular-text">
                        <option value=""><?php _e( 'Seleccionar...', 'lealez' ); ?></option>
                        <option value="email" <?php selected( $gmb_verification_method, 'email' ); ?>><?php _e( 'Email', 'lealez' ); ?></option>
                        <option value="phone" <?php selected( $gmb_verification_method, 'phone' ); ?>><?php _e( 'Teléfono', 'lealez' ); ?></option>
                        <option value="postcard" <?php selected( $gmb_verification_method, 'postcard' ); ?>><?php _e( 'Postal', 'lealez' ); ?></option>
                        <option value="video" <?php selected( $gmb_verification_method, 'video' ); ?>><?php _e( 'Video', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th scope="row">
                    <label><?php _e( 'Sincronización Automática', 'lealez' ); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="gmb_auto_sync_enabled" 
                               value="1" 
                               <?php checked( $gmb_auto_sync_enabled, '1' ); ?>>
                        <?php _e( 'Sincronizar automáticamente con Google My Business', 'lealez' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="gmb_sync_frequency"><?php _e( 'Frecuencia de Sincronización', 'lealez' ); ?></label>
                </th>
                <td>
                    <select name="gmb_sync_frequency" id="gmb_sync_frequency" class="regular-text">
                        <option value="hourly" <?php selected( $gmb_sync_frequency, 'hourly' ); ?>><?php _e( 'Cada hora', 'lealez' ); ?></option>
                        <option value="daily" <?php selected( $gmb_sync_frequency, 'daily' ); ?>><?php _e( 'Diariamente', 'lealez' ); ?></option>
                        <option value="weekly" <?php selected( $gmb_sync_frequency, 'weekly' ); ?>><?php _e( 'Semanalmente', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>
            <?php if ( $gmb_last_sync ) : ?>
            <tr>
                <th scope="row">
                    <label><?php _e( 'Última Sincronización', 'lealez' ); ?></label>
                </th>
                <td>
                    <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $gmb_last_sync ) ); ?>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        
        <p>
            <button type="button" class="button button-secondary" id="sync-gmb-data">
                <?php _e( 'Sincronizar Ahora', 'lealez' ); ?>
            </button>
        </p>
        <?php
    }

    /**
     * Render Attributes meta box
     */
    public function render_attributes_meta_box( $post ) {
        $google_primary_category = get_post_meta( $post->ID, 'google_primary_category', true );
        $price_range = get_post_meta( $post->ID, 'price_range', true );
        $attributes_accessibility = get_post_meta( $post->ID, 'attributes_accessibility', true );
        $attributes_amenities = get_post_meta( $post->ID, 'attributes_amenities', true );
        $attributes_payments = get_post_meta( $post->ID, 'attributes_payments', true );
        
        if ( ! is_array( $attributes_accessibility ) ) {
            $attributes_accessibility = array();
        }
        if ( ! is_array( $attributes_amenities ) ) {
            $attributes_amenities = array();
        }
        if ( ! is_array( $attributes_payments ) ) {
            $attributes_payments = array();
        }
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="google_primary_category"><?php _e( 'Categoría Principal', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="google_primary_category" 
                           id="google_primary_category" 
                           value="<?php echo esc_attr( $google_primary_category ); ?>" 
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'Ej: Restaurant, Retail Store, Gym', 'lealez' ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="price_range"><?php _e( 'Rango de Precios', 'lealez' ); ?></label>
                </th>
                <td>
                    <select name="price_range" id="price_range" class="regular-text">
                        <option value=""><?php _e( 'No especificado', 'lealez' ); ?></option>
                        <option value="1" <?php selected( $price_range, '1' ); ?>>$ - <?php _e( 'Económico', 'lealez' ); ?></option>
                        <option value="2" <?php selected( $price_range, '2' ); ?>>$$ - <?php _e( 'Moderado', 'lealez' ); ?></option>
                        <option value="3" <?php selected( $price_range, '3' ); ?>>$$$ - <?php _e( 'Caro', 'lealez' ); ?></option>
                        <option value="4" <?php selected( $price_range, '4' ); ?>>$$$$ - <?php _e( 'Muy Caro', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        
        <h4><?php _e( 'Accesibilidad', 'lealez' ); ?></h4>
        <table class="form-table">
            <tr>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="attributes_accessibility[wheelchair_accessible]" 
                               value="1" 
                               <?php checked( ! empty( $attributes_accessibility['wheelchair_accessible'] ), true ); ?>>
                        <?php _e( 'Accesible en silla de ruedas', 'lealez' ); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" 
                               name="attributes_accessibility[parking_accessible]" 
                               value="1" 
                               <?php checked( ! empty( $attributes_accessibility['parking_accessible'] ), true ); ?>>
                        <?php _e( 'Estacionamiento accesible', 'lealez' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        
        <h4><?php _e( 'Comodidades', 'lealez' ); ?></h4>
        <table class="form-table">
            <tr>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="attributes_amenities[wifi]" 
                               value="1" 
                               <?php checked( ! empty( $attributes_amenities['wifi'] ), true ); ?>>
                        <?php _e( 'Wi-Fi', 'lealez' ); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" 
                               name="attributes_amenities[parking]" 
                               value="1" 
                               <?php checked( ! empty( $attributes_amenities['parking'] ), true ); ?>>
                        <?php _e( 'Estacionamiento', 'lealez' ); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" 
                               name="attributes_amenities[restrooms]" 
                               value="1" 
                               <?php checked( ! empty( $attributes_amenities['restrooms'] ), true ); ?>>
                        <?php _e( 'Baños', 'lealez' ); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" 
                               name="attributes_amenities[outdoor_seating]" 
                               value="1" 
                               <?php checked( ! empty( $attributes_amenities['outdoor_seating'] ), true ); ?>>
                        <?php _e( 'Asientos al aire libre', 'lealez' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        
        <h4><?php _e( 'Métodos de Pago', 'lealez' ); ?></h4>
        <table class="form-table">
            <tr>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="attributes_payments[cash]" 
                               value="1" 
                               <?php checked( ! empty( $attributes_payments['cash'] ), true ); ?>>
                        <?php _e( 'Efectivo', 'lealez' ); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" 
                               name="attributes_payments[credit_cards]" 
                               value="1" 
                               <?php checked( ! empty( $attributes_payments['credit_cards'] ), true ); ?>>
                        <?php _e( 'Tarjetas de crédito', 'lealez' ); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" 
                               name="attributes_payments[debit_cards]" 
                               value="1" 
                               <?php checked( ! empty( $attributes_payments['debit_cards'] ), true ); ?>>
                        <?php _e( 'Tarjetas de débito', 'lealez' ); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" 
                               name="attributes_payments[mobile_payments]" 
                               value="1" 
                               <?php checked( ! empty( $attributes_payments['mobile_payments'] ), true ); ?>>
                        <?php _e( 'Pagos móviles', 'lealez' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render GMB Metrics meta box
     */
    public function render_gmb_metrics_meta_box( $post ) {
        $profile_views = get_post_meta( $post->ID, 'gmb_profile_views_30d', true );
        $calls = get_post_meta( $post->ID, 'gmb_calls_30d', true );
        $website_clicks = get_post_meta( $post->ID, 'gmb_website_clicks_30d', true );
        $direction_requests = get_post_meta( $post->ID, 'gmb_direction_requests_30d', true );
        $google_rating = get_post_meta( $post->ID, 'google_rating', true );
        $reviews_count = get_post_meta( $post->ID, 'google_reviews_count', true );
        ?>
        <div class="oy-metrics-summary">
            <h4><?php _e( 'Últimos 30 Días', 'lealez' ); ?></h4>
            <table class="widefat">
                <tr>
                    <td><strong><?php _e( 'Vistas del Perfil:', 'lealez' ); ?></strong></td>
                    <td><?php echo esc_html( $profile_views ? number_format_i18n( $profile_views ) : '-' ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e( 'Llamadas:', 'lealez' ); ?></strong></td>
                    <td><?php echo esc_html( $calls ? number_format_i18n( $calls ) : '-' ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e( 'Clics al Sitio Web:', 'lealez' ); ?></strong></td>
                    <td><?php echo esc_html( $website_clicks ? number_format_i18n( $website_clicks ) : '-' ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e( 'Solicitudes de Dirección:', 'lealez' ); ?></strong></td>
                    <td><?php echo esc_html( $direction_requests ? number_format_i18n( $direction_requests ) : '-' ); ?></td>
                </tr>
            </table>
            
            <h4 style="margin-top: 15px;"><?php _e( 'Reputación', 'lealez' ); ?></h4>
            <table class="widefat">
                <tr>
                    <td><strong><?php _e( 'Rating Promedio:', 'lealez' ); ?></strong></td>
                    <td><?php echo esc_html( $google_rating ? $google_rating . ' ⭐' : '-' ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e( 'Total de Reseñas:', 'lealez' ); ?></strong></td>
                    <td><?php echo esc_html( $reviews_count ? number_format_i18n( $reviews_count ) : '-' ); ?></td>
                </tr>
            </table>
            
            <p style="margin-top: 15px;">
                <a href="#" class="button button-small button-secondary">
                    <?php _e( 'Ver Métricas Completas', 'lealez' ); ?>
                </a>
            </p>
        </div>
        
        <style>
        .oy-metrics-summary table {
            margin-bottom: 0;
        }
        .oy-metrics-summary table td {
            padding: 8px 10px;
        }
        </style>
        <?php
    }

    /**
     * Render Loyalty Settings meta box
     */
    public function render_loyalty_meta_box( $post ) {
        $accepts_loyalty = get_post_meta( $post->ID, 'accepts_loyalty', true );
        $loyalty_redemption_enabled = get_post_meta( $post->ID, 'loyalty_redemption_enabled', true );
        $loyalty_earning_enabled = get_post_meta( $post->ID, 'loyalty_earning_enabled', true );
        $loyalty_multiplier = get_post_meta( $post->ID, 'loyalty_multiplier', true );
        $loyalty_terminal_id = get_post_meta( $post->ID, 'loyalty_terminal_id', true );
        
        if ( empty( $loyalty_multiplier ) ) {
            $loyalty_multiplier = '1.0';
        }
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label><?php _e( 'Programa de Lealtad', 'lealez' ); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="accepts_loyalty" 
                               value="1" 
                               <?php checked( $accepts_loyalty, '1' ); ?>>
                        <?php _e( 'Esta ubicación acepta el programa de lealtad', 'lealez' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e( 'Configuración', 'lealez' ); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="loyalty_earning_enabled" 
                               value="1" 
                               <?php checked( $loyalty_earning_enabled, '1' ); ?>>
                        <?php _e( 'Permitir ganar puntos', 'lealez' ); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" 
                               name="loyalty_redemption_enabled" 
                               value="1" 
                               <?php checked( $loyalty_redemption_enabled, '1' ); ?>>
                        <?php _e( 'Permitir canjear puntos', 'lealez' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="loyalty_multiplier"><?php _e( 'Multiplicador de Puntos', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="number" 
                           name="loyalty_multiplier" 
                           id="loyalty_multiplier" 
                           value="<?php echo esc_attr( $loyalty_multiplier ); ?>" 
                           step="0.1"
                           min="0.1"
                           max="10"
                           class="small-text">
                    <p class="description">
                        <?php _e( 'Multiplicador para puntos ganados en esta ubicación (ej: 1.5 = 50% más puntos).', 'lealez' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="loyalty_terminal_id"><?php _e( 'ID del Terminal POS', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="loyalty_terminal_id" 
                           id="loyalty_terminal_id" 
                           value="<?php echo esc_attr( $loyalty_terminal_id ); ?>" 
                           class="regular-text">
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save meta boxes data
     */
    public function save_meta_boxes( $post_id, $post ) {
        // Security checks
        if ( ! isset( $_POST[ $this->nonce_name ] ) || ! wp_verify_nonce( $_POST[ $this->nonce_name ], $this->nonce_action ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Define all meta fields to save
        $meta_fields = array(
            // Parent Business
            'parent_business_id'              => 'sanitize_text_field',
            
            // Basic Info
            'location_code'                   => 'sanitize_text_field',
            'location_short_description'      => 'sanitize_textarea_field',
            'location_status'                 => 'sanitize_text_field',
            'opening_date'                    => 'sanitize_text_field',
            'closing_date'                    => 'sanitize_text_field',
            
            // Address
            'location_address_line1'          => 'sanitize_text_field',
            'location_address_line2'          => 'sanitize_text_field',
            'location_neighborhood'           => 'sanitize_text_field',
            'location_city'                   => 'sanitize_text_field',
            'location_state'                  => 'sanitize_text_field',
            'location_country'                => 'sanitize_text_field',
            'location_postal_code'            => 'sanitize_text_field',
            'location_latitude'               => 'sanitize_text_field',
            'location_longitude'              => 'sanitize_text_field',
            'location_place_id'               => 'sanitize_text_field',
            'location_plus_code'              => 'sanitize_text_field',
            
            // Contact
            'location_phone'                  => 'sanitize_text_field',
            'location_phone_additional'       => 'sanitize_text_field',
            'location_whatsapp'               => 'sanitize_text_field',
            'location_email'                  => 'sanitize_email',
            'location_website'                => 'esc_url_raw',
            'location_booking_url'            => 'esc_url_raw',
            'location_menu_url'               => 'esc_url_raw',
            'location_order_url'              => 'esc_url_raw',
            
            // Hours
            'location_hours_timezone'         => 'sanitize_text_field',
            
            // GMB
            'gmb_location_id'                 => 'sanitize_text_field',
            'gmb_account_id'                  => 'sanitize_text_field',
            'gmb_verified'                    => 'absint',
            'gmb_verification_method'         => 'sanitize_text_field',
            'gmb_auto_sync_enabled'           => 'absint',
            'gmb_sync_frequency'              => 'sanitize_text_field',
            
            // Attributes
            'google_primary_category'         => 'sanitize_text_field',
            'price_range'                     => 'sanitize_text_field',
            
            // Loyalty
            'accepts_loyalty'                 => 'absint',
            'loyalty_redemption_enabled'      => 'absint',
            'loyalty_earning_enabled'         => 'absint',
            'loyalty_multiplier'              => 'floatval',
            'loyalty_terminal_id'             => 'sanitize_text_field',
        );

        // Save simple meta fields
        foreach ( $meta_fields as $field_name => $sanitize_callback ) {
            if ( isset( $_POST[ $field_name ] ) ) {
                $value = call_user_func( $sanitize_callback, $_POST[ $field_name ] );
                update_post_meta( $post_id, $field_name, $value );
            } else {
                delete_post_meta( $post_id, $field_name );
            }
        }

        // Save hours (JSON format)
        $days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        foreach ( $days as $day ) {
            if ( isset( $_POST[ 'location_hours_' . $day ] ) ) {
                $hours_data = array(
                    'closed' => ! empty( $_POST[ 'location_hours_' . $day ]['closed'] ),
                    'open'   => sanitize_text_field( $_POST[ 'location_hours_' . $day ]['open'] ?? '09:00' ),
                    'close'  => sanitize_text_field( $_POST[ 'location_hours_' . $day ]['close'] ?? '18:00' ),
                );
                update_post_meta( $post_id, 'location_hours_' . $day, $hours_data );
            }
        }

        // Save attributes (arrays as JSON)
        if ( isset( $_POST['attributes_accessibility'] ) ) {
            update_post_meta( $post_id, 'attributes_accessibility', array_map( 'sanitize_text_field', $_POST['attributes_accessibility'] ) );
        } else {
            delete_post_meta( $post_id, 'attributes_accessibility' );
        }

        if ( isset( $_POST['attributes_amenities'] ) ) {
            update_post_meta( $post_id, 'attributes_amenities', array_map( 'sanitize_text_field', $_POST['attributes_amenities'] ) );
        } else {
            delete_post_meta( $post_id, 'attributes_amenities' );
        }

        if ( isset( $_POST['attributes_payments'] ) ) {
            update_post_meta( $post_id, 'attributes_payments', array_map( 'sanitize_text_field', $_POST['attributes_payments'] ) );
        } else {
            delete_post_meta( $post_id, 'attributes_payments' );
        }

        // Save system metadata
        update_post_meta( $post_id, 'date_modified', current_time( 'mysql' ) );
        update_post_meta( $post_id, 'modified_by_user_id', get_current_user_id() );

        if ( ! get_post_meta( $post_id, 'date_created', true ) ) {
            update_post_meta( $post_id, 'date_created', current_time( 'mysql' ) );
            update_post_meta( $post_id, 'created_by_user_id', get_current_user_id() );
        }
    }

    /**
     * Set custom admin columns
     */
    public function set_custom_columns( $columns ) {
        $new_columns = array();
        
        // Reorder columns
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = __( 'Nombre de Ubicación', 'lealez' );
        $new_columns['parent_business'] = __( 'Empresa', 'lealez' );
        $new_columns['location_code'] = __( 'Código', 'lealez' );
        $new_columns['city'] = __( 'Ciudad', 'lealez' );
        $new_columns['status'] = __( 'Estado', 'lealez' );
        $new_columns['gmb_status'] = __( 'GMB', 'lealez' );
        $new_columns['metrics'] = __( 'Métricas (30d)', 'lealez' );
        $new_columns['date'] = __( 'Fecha', 'lealez' );
        
        return $new_columns;
    }

    /**
     * Display custom column content
     */
    public function custom_column_content( $column, $post_id ) {
        switch ( $column ) {
            case 'parent_business':
                $parent_id = get_post_meta( $post_id, 'parent_business_id', true );
                if ( $parent_id ) {
                    $parent_title = get_the_title( $parent_id );
                    echo '<a href="' . esc_url( get_edit_post_link( $parent_id ) ) . '">' . esc_html( $parent_title ) . '</a>';
                } else {
                    echo '<span style="color: #dc3232;">—</span>';
                }
                break;

            case 'location_code':
                $code = get_post_meta( $post_id, 'location_code', true );
                echo $code ? '<code>' . esc_html( $code ) . '</code>' : '—';
                break;

            case 'city':
                $city = get_post_meta( $post_id, 'location_city', true );
                $state = get_post_meta( $post_id, 'location_state', true );
                $parts = array_filter( array( $city, $state ) );
                echo $parts ? esc_html( implode( ', ', $parts ) ) : '—';
                break;

            case 'status':
                $status = get_post_meta( $post_id, 'location_status', true );
                $status_labels = array(
                    'active'              => array( 'label' => __( 'Activa', 'lealez' ), 'color' => '#46b450' ),
                    'inactive'            => array( 'label' => __( 'Inactiva', 'lealez' ), 'color' => '#999' ),
                    'temporarily_closed'  => array( 'label' => __( 'Cerrada Temp.', 'lealez' ), 'color' => '#f0b322' ),
                    'permanently_closed'  => array( 'label' => __( 'Cerrada Perm.', 'lealez' ), 'color' => '#dc3232' ),
                );
                if ( isset( $status_labels[ $status ] ) ) {
                    printf(
                        '<span style="color: %s; font-weight: 600;">%s</span>',
                        esc_attr( $status_labels[ $status ]['color'] ),
                        esc_html( $status_labels[ $status ]['label'] )
                    );
                } else {
                    echo '—';
                }
                break;

            case 'gmb_status':
                $gmb_verified = get_post_meta( $post_id, 'gmb_verified', true );
                $gmb_location_id = get_post_meta( $post_id, 'gmb_location_id', true );
                if ( $gmb_verified && $gmb_location_id ) {
                    echo '<span style="color: #46b450;" title="' . esc_attr__( 'Verificada', 'lealez' ) . '">✓</span>';
                } elseif ( $gmb_location_id ) {
                    echo '<span style="color: #f0b322;" title="' . esc_attr__( 'Conectada pero no verificada', 'lealez' ) . '">⚠</span>';
                } else {
                    echo '<span style="color: #999;" title="' . esc_attr__( 'No conectada', 'lealez' ) . '">—</span>';
                }
                break;

            case 'metrics':
                $views = get_post_meta( $post_id, 'gmb_profile_views_30d', true );
                $calls = get_post_meta( $post_id, 'gmb_calls_30d', true );
                if ( $views || $calls ) {
                    printf(
                        '<small>👁 %s | 📞 %s</small>',
                        $views ? number_format_i18n( $views ) : '0',
                        $calls ? number_format_i18n( $calls ) : '0'
                    );
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                break;
        }
    }

    /**
     * Make columns sortable
     */
    public function sortable_columns( $columns ) {
        $columns['location_code'] = 'location_code';
        $columns['city'] = 'city';
        $columns['status'] = 'status';
        return $columns;
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function admin_scripts( $hook ) {
        global $post_type;
        
        if ( $this->post_type !== $post_type ) {
            return;
        }

        if ( 'post.php' === $hook || 'post-new.php' === $hook ) {
            wp_enqueue_script(
                'oy-location-admin',
                LEALEZ_ASSETS_URL . 'js/admin/oy-location.js',
                array( 'jquery' ),
                LEALEZ_VERSION,
                true
            );

            wp_enqueue_style(
                'oy-location-admin',
                LEALEZ_ASSETS_URL . 'css/admin/oy-location.css',
                array(),
                LEALEZ_VERSION
            );
        }
    }

    /**
     * Update parent business location counter
     */
    public function update_parent_business_counter( $post_id, $post ) {
        if ( $post->post_status !== 'publish' ) {
            return;
        }

        $parent_id = get_post_meta( $post_id, 'parent_business_id', true );
        if ( ! $parent_id ) {
            return;
        }

        // Count all published locations for this business
        $count = get_posts( array(
            'post_type'      => $this->post_type,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                array(
                    'key'   => 'parent_business_id',
                    'value' => $parent_id,
                ),
            ),
            'fields'         => 'ids',
        ) );

        update_post_meta( $parent_id, 'total_locations', count( $count ) );
    }

    /**
     * Update parent business counter on location deletion
     */
    public function update_parent_on_delete( $post_id ) {
        if ( get_post_type( $post_id ) !== $this->post_type ) {
            return;
        }

        $parent_id = get_post_meta( $post_id, 'parent_business_id', true );
        if ( $parent_id ) {
            // Trigger counter update after deletion
            wp_schedule_single_event( time() + 5, 'oy_update_business_location_counter', array( $parent_id ) );
        }
    }

    /**
     * Sync metrics to parent business (aggregate)
     */
    public function sync_metrics_to_parent( $meta_id, $object_id, $meta_key, $meta_value ) {
        // Only process GMB metrics
        if ( strpos( $meta_key, 'gmb_' ) !== 0 ) {
            return;
        }

        // Only for location posts
        if ( get_post_type( $object_id ) !== $this->post_type ) {
            return;
        }

        $parent_id = get_post_meta( $object_id, 'parent_business_id', true );
        if ( ! $parent_id ) {
            return;
        }

        // Schedule aggregation task (to avoid running on every meta update)
        if ( ! wp_next_scheduled( 'oy_aggregate_business_metrics', array( $parent_id ) ) ) {
            wp_schedule_single_event( time() + 60, 'oy_aggregate_business_metrics', array( $parent_id ) );
        }
    }
}

// Initialize the CPT
new OY_Location_CPT();
