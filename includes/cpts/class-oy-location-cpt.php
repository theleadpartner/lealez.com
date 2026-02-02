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
     * AJAX nonce action
     *
     * @var string
     */
    private $ajax_nonce_action = 'oy_location_gmb_ajax';

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

        // ✅ AJAX: traer ubicaciones por business y detalles por location_name
        add_action( 'wp_ajax_oy_get_gmb_locations_for_business', array( $this, 'ajax_get_gmb_locations_for_business' ) );
        add_action( 'wp_ajax_oy_get_gmb_location_details', array( $this, 'ajax_get_gmb_location_details' ) );
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
            'show_in_menu'          => false,
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

        // Check if business_id is passed via URL (from business page)
        if ( empty( $parent_business_id ) && isset( $_GET['business_id'] ) ) {
            $parent_business_id = absint( $_GET['business_id'] );
        }

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
                    <span class="dashicons dashicons-building" style="margin-top: 3px;"></span>
                    <?php _e( 'Ver Empresa', 'lealez' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=oy_location&business_filter=' . $parent_business_id ) ); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-list-view" style="margin-top: 3px;"></span>
                    <?php _e( 'Ver Todas las Ubicaciones de esta Empresa', 'lealez' ); ?>
                </a>
            </div>
        <?php endif; ?>

        <script>
        jQuery(document).ready(function($) {
            // Pre-select business if passed via URL
            <?php if ( ! empty( $parent_business_id ) && isset( $_GET['business_id'] ) ) : ?>
            $('#parent_business_id').val('<?php echo esc_js( $parent_business_id ); ?>');
            <?php endif; ?>
        });
        </script>
        <?php
    }

    /**
     * Render Basic Information meta box
     */
    public function render_basic_info_meta_box( $post ) {
        $location_code              = get_post_meta( $post->ID, 'location_code', true );
        $location_short_description = get_post_meta( $post->ID, 'location_short_description', true );
        $location_status            = get_post_meta( $post->ID, 'location_status', true );
        $opening_date               = get_post_meta( $post->ID, 'opening_date', true );
        $closing_date               = get_post_meta( $post->ID, 'closing_date', true );

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
        $neighborhood  = get_post_meta( $post->ID, 'location_neighborhood', true );
        $city          = get_post_meta( $post->ID, 'location_city', true );
        $state         = get_post_meta( $post->ID, 'location_state', true );
        $country       = get_post_meta( $post->ID, 'location_country', true );
        $postal_code   = get_post_meta( $post->ID, 'location_postal_code', true );
        $latitude      = get_post_meta( $post->ID, 'location_latitude', true );
        $longitude     = get_post_meta( $post->ID, 'location_longitude', true );
        $place_id      = get_post_meta( $post->ID, 'location_place_id', true );
        $plus_code     = get_post_meta( $post->ID, 'location_plus_code', true );

        // ✅ Google RAW address (storefrontAddress)
        $gmb_storefront_address_raw = get_post_meta( $post->ID, 'gmb_storefront_address_raw', true );
        if ( empty( $country ) ) {
            $country = '';
        }
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
                    <label for="location_country"><?php _e( 'País (ISO 2)', 'lealez' ); ?> <span class="required">*</span></label>
                </th>
                <td>
                    <input type="text"
                           name="location_country"
                           id="location_country"
                           value="<?php echo esc_attr( $country ); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'CO, MX, US', 'lealez' ); ?>"
                           required
                           maxlength="2">
                    <p class="description">
                        <?php _e( 'Google usa regionCode (ISO 3166-1 alpha-2). Ej: CO', 'lealez' ); ?>
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
                    <div style="display:flex; gap:10px;">
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
                        <?php _e( 'Google usa latlng.latitude / latlng.longitude.', 'lealez' ); ?>
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

        <hr>

        <h4 style="margin-top:10px;"><?php _e( 'Google (RAW) - storefrontAddress', 'lealez' ); ?></h4>
        <p class="description">
            <?php _e( 'Este campo guarda el objeto storefrontAddress exactamente como lo entrega Google. Se actualiza al importar.', 'lealez' ); ?>
        </p>
        <textarea readonly class="large-text" rows="6" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?php
            echo esc_textarea( $gmb_storefront_address_raw ? wp_json_encode( $gmb_storefront_address_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : '' );
        ?></textarea>
        <?php
    }

    /**
     * Render Contact Information meta box
     */
    public function render_contact_meta_box( $post ) {
        $phone            = get_post_meta( $post->ID, 'location_phone', true );
        $phone_additional = get_post_meta( $post->ID, 'location_phone_additional', true );
        $whatsapp         = get_post_meta( $post->ID, 'location_whatsapp', true );
        $email            = get_post_meta( $post->ID, 'location_email', true );
        $website          = get_post_meta( $post->ID, 'location_website', true );
        $booking_url      = get_post_meta( $post->ID, 'location_booking_url', true );
        $menu_url         = get_post_meta( $post->ID, 'location_menu_url', true );
        $order_url        = get_post_meta( $post->ID, 'location_order_url', true );

        // ✅ Google RAW phones object
        $gmb_phone_numbers_raw = get_post_meta( $post->ID, 'gmb_phone_numbers_raw', true );
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
                        <?php _e( 'Formato E.164 recomendado. Google usa phoneNumbers.primaryPhone.', 'lealez' ); ?>
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
                    <p class="description">
                        <?php _e( 'En Google suele venir como phoneNumbers.additionalPhones[].', 'lealez' ); ?>
                    </p>
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
                    <p class="description">
                        <?php _e( 'Google usa websiteUri.', 'lealez' ); ?>
                    </p>
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

        <hr>

        <h4 style="margin-top:10px;"><?php _e( 'Google (RAW) - phoneNumbers', 'lealez' ); ?></h4>
        <p class="description">
            <?php _e( 'Se guarda el objeto phoneNumbers como lo entrega Google (primaryPhone + additionalPhones).', 'lealez' ); ?>
        </p>
        <textarea readonly class="large-text" rows="5" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?php
            echo esc_textarea( $gmb_phone_numbers_raw ? wp_json_encode( $gmb_phone_numbers_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : '' );
        ?></textarea>
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

        // ✅ Google RAW regularHours + specialHours + moreHours
        $gmb_regular_hours_raw = get_post_meta( $post->ID, 'gmb_regular_hours_raw', true );
        $gmb_special_hours_raw = get_post_meta( $post->ID, 'gmb_special_hours_raw', true );
        $gmb_more_hours_raw    = get_post_meta( $post->ID, 'gmb_more_hours_raw', true );
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
        <p class="description">
            <?php _e( 'Estos campos son “humanos” (open/close por día). Al importar, se mapearán desde Google regularHours.periods.', 'lealez' ); ?>
        </p>
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

        <hr>

        <h4 style="margin-top:10px;"><?php _e( 'Google (RAW) - regularHours / specialHours / moreHours', 'lealez' ); ?></h4>

        <p class="description"><?php _e( 'regularHours (objeto) tal cual Google lo entrega.', 'lealez' ); ?></p>
        <textarea readonly class="large-text" rows="6" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?php
            echo esc_textarea( $gmb_regular_hours_raw ? wp_json_encode( $gmb_regular_hours_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : '' );
        ?></textarea>

        <p class="description"><?php _e( 'specialHours (objeto) tal cual Google lo entrega.', 'lealez' ); ?></p>
        <textarea readonly class="large-text" rows="6" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?php
            echo esc_textarea( $gmb_special_hours_raw ? wp_json_encode( $gmb_special_hours_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : '' );
        ?></textarea>

        <p class="description"><?php _e( 'moreHours (objeto) tal cual Google lo entrega.', 'lealez' ); ?></p>
        <textarea readonly class="large-text" rows="6" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?php
            echo esc_textarea( $gmb_more_hours_raw ? wp_json_encode( $gmb_more_hours_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : '' );
        ?></textarea>
        <?php
    }

    /**
     * Render GMB Integration meta box
     */
    public function render_gmb_meta_box( $post ) {
        $parent_business_id        = get_post_meta( $post->ID, 'parent_business_id', true );

        // ✅ "Source" selector (location resource name) + import controls
        $gmb_location_name         = get_post_meta( $post->ID, 'gmb_location_name', true ); // e.g. accounts/123/locations/456
        $gmb_location_account_name = get_post_meta( $post->ID, 'gmb_location_account_name', true ); // account resource name used in cached list
        $gmb_import_on_save        = get_post_meta( $post->ID, 'gmb_import_on_save', true );
        if ( '' === $gmb_import_on_save ) {
            // default ON for new posts
            $gmb_import_on_save = '1';
        }

        // Existing meta
        $gmb_location_id           = get_post_meta( $post->ID, 'gmb_location_id', true );
        $gmb_account_id            = get_post_meta( $post->ID, 'gmb_account_id', true );
        $gmb_verified              = get_post_meta( $post->ID, 'gmb_verified', true );
        $gmb_verification_method   = get_post_meta( $post->ID, 'gmb_verification_method', true );
        $gmb_auto_sync_enabled     = get_post_meta( $post->ID, 'gmb_auto_sync_enabled', true );
        $gmb_sync_frequency        = get_post_meta( $post->ID, 'gmb_sync_frequency', true );
        $gmb_last_sync             = get_post_meta( $post->ID, 'gmb_last_sync', true );

        // ✅ Verification API RAW fields
        $gmb_verification_state    = get_post_meta( $post->ID, 'gmb_verification_state', true );
        $gmb_verification_name     = get_post_meta( $post->ID, 'gmb_verification_name', true );
        $gmb_verification_time     = get_post_meta( $post->ID, 'gmb_verification_create_time', true );

        // ✅ Google RAW fields (Location resource)
        $gmb_location_raw          = get_post_meta( $post->ID, 'gmb_location_raw', true );

        if ( empty( $gmb_sync_frequency ) ) {
            $gmb_sync_frequency = 'daily';
        }

        $ajax_nonce = wp_create_nonce( $this->ajax_nonce_action );
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label><?php _e( 'Origen (Google Business Profile)', 'lealez' ); ?></label>
                </th>
                <td>
                    <p class="description" style="margin-top:0;">
                        <?php _e( 'Primero selecciona la Empresa (sidebar). Luego podrás elegir una Ubicación de Google para importar y poblar automáticamente los campos del CPT.', 'lealez' ); ?>
                    </p>

                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <select
                            name="gmb_location_name"
                            id="gmb_location_name"
                            class="large-text"
                            style="max-width:520px;"
                            <?php echo $parent_business_id ? '' : 'disabled'; ?>
                        >
                            <option value="">
                                <?php echo $parent_business_id ? esc_html__( 'Cargando ubicaciones...', 'lealez' ) : esc_html__( 'Selecciona una empresa primero...', 'lealez' ); ?>
                            </option>
                        </select>

                        <button type="button" class="button button-secondary" id="oy-gmb-refresh-location-list" <?php echo $parent_business_id ? '' : 'disabled'; ?>>
                            <?php _e( 'Recargar Lista', 'lealez' ); ?>
                        </button>

                        <button type="button" class="button button-primary" id="oy-gmb-import-location-now" <?php echo ( $parent_business_id && $gmb_location_name ) ? '' : 'disabled'; ?>>
                            <?php _e( 'Importar Ahora', 'lealez' ); ?>
                        </button>
                    </div>

                    <input type="hidden" name="gmb_location_account_name" id="gmb_location_account_name" value="<?php echo esc_attr( $gmb_location_account_name ); ?>">

                    <p style="margin-top:10px;">
                        <label>
                            <input type="checkbox" name="gmb_import_on_save" value="1" <?php checked( $gmb_import_on_save, '1' ); ?>>
                            <?php _e( 'Importar / actualizar desde Google automáticamente al Guardar', 'lealez' ); ?>
                        </label>
                    </p>

                    <div id="oy-gmb-location-hint" style="margin-top:8px; color:#555;"></div>
                </td>
            </tr>

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
                        <?php _e( 'Se deriva del resource name (accounts/.../locations/ID).', 'lealez' ); ?>
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
                    <p class="description">
                        <?php _e( 'Se guarda como el account resource name que corresponde a la ubicación.', 'lealez' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label><?php _e( 'Estado de Verificación (Google)', 'lealez' ); ?></label>
                </th>
                <td>
                    <?php 
                    // ✅ CORRECCIÓN: Display visual basado en gmb_verification_state
                    $state = strtoupper( (string) $gmb_verification_state );
                    $icon = '';
                    $color = '';
                    $text = __( 'No disponible', 'lealez' );

                    if ( $state === 'VERIFIED' ) {
                        $icon = '✓';
                        $color = '#46b450'; // verde
                        $text = __( 'Verificado', 'lealez' );
                    } elseif ( $state === 'UNVERIFIED' ) {
                        $icon = '⚠';
                        $color = '#ffb900'; // amarillo
                        $text = __( 'No verificado', 'lealez' );
                    } elseif ( $state === 'VERIFICATION_REQUESTED' ) {
                        $icon = '⏳';
                        $color = '#00a0d2'; // azul
                        $text = __( 'Verificación solicitada', 'lealez' );
                    } elseif ( $state === 'VERIFICATION_IN_PROGRESS' ) {
                        $icon = '⏳';
                        $color = '#00a0d2'; // azul
                        $text = __( 'Verificación en progreso', 'lealez' );
                    } elseif ( $state === 'SUSPENDED' ) {
                        $icon = '✖';
                        $color = '#dc3232'; // rojo
                        $text = __( 'Suspendida', 'lealez' );
                    }
                    ?>

                    <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f5f5f5; border-radius: 4px; max-width: 400px;">
                        <span style="font-size: 24px; color: <?php echo esc_attr( $color ); ?>;">
                            <?php echo esc_html( $icon ); ?>
                        </span>
                        <div>
                            <strong style="color: <?php echo esc_attr( $color ); ?>;">
                                <?php echo esc_html( $text ); ?>
                            </strong>
                            <?php if ( $gmb_verification_state ) : ?>
                                <br>
                                <small style="color: #666;">
                                    <?php 
                                    echo esc_html( sprintf( 
                                        __( 'Estado API: %s', 'lealez' ), 
                                        $gmb_verification_state 
                                    ) ); 
                                    ?>
                                    <?php if ( $gmb_verification_time ) : ?>
                                        — <?php echo esc_html( $gmb_verification_time ); ?>
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ✅ Hidden field para mantener compatibilidad backward -->
                    <input type="hidden" 
                           name="gmb_verified" 
                           value="<?php echo ( $state === 'VERIFIED' ) ? '1' : '0'; ?>">

                    <p class="description" style="margin-top:8px;">
                        <?php 
                        if ( empty( $gmb_verification_state ) ) {
                            $has_location_id = ! empty( get_post_meta( $post->ID, 'gmb_location_id', true ) );
                            
                            if ( ! $has_location_id ) {
                                echo '<strong style="color: #d63638;">⚠ </strong>';
                                _e( 'Esta ubicación no ha sido importada desde Google My Business. Selecciona una ubicación del dropdown y haz clic en "Importar Ahora" para obtener el estado de verificación.', 'lealez' );
                            } else {
                                echo '<strong style="color: #ffb900;">⏳ </strong>';
                                _e( 'Información de verificación no disponible. Posibles causas: 1) La ubicación no tiene proceso de verificación iniciado en Google, 2) Problemas temporales con Verifications API, o 3) Se requiere sincronización manual. Intenta hacer clic en "Sincronizar Ahora".', 'lealez' );
                            }
                        } else {
                            _e( 'Estado de verificación obtenido desde Google My Business Verifications API. Se actualiza automáticamente al importar/sincronizar.', 'lealez' );
                        }
                        ?>
                    </p>
                </td>
            </tr>

            <?php if ( $state === 'VERIFIED' ) : ?>
            <tr>
                <th scope="row">
                    <label for="gmb_verification_method"><?php _e( 'Método de Verificación (manual)', 'lealez' ); ?></label>
                </th>
                <td>
                    <select name="gmb_verification_method" id="gmb_verification_method" class="regular-text">
                        <option value=""><?php _e( 'Seleccionar...', 'lealez' ); ?></option>
                        <option value="email" <?php selected( $gmb_verification_method, 'email' ); ?>><?php _e( 'Email', 'lealez' ); ?></option>
                        <option value="phone" <?php selected( $gmb_verification_method, 'phone' ); ?>><?php _e( 'Teléfono', 'lealez' ); ?></option>
                        <option value="postcard" <?php selected( $gmb_verification_method, 'postcard' ); ?>><?php _e( 'Postal', 'lealez' ); ?></option>
                        <option value="video" <?php selected( $gmb_verification_method, 'video' ); ?>><?php _e( 'Video', 'lealez' ); ?></option>
                    </select>
                    <p class="description">
                        <?php _e( 'Este campo es manual (opcional). El estado real se guarda en gmb_verification_state desde Verifications API.', 'lealez' ); ?>
                    </p>
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
                        <?php _e( 'Sincronizar automáticamente con Google', 'lealez' ); ?>
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
                <?php _e( 'Sincronizar Ahora (solo métricas/externo)', 'lealez' ); ?>
            </button>
        </p>

        <hr>

        <h4 style="margin-top:10px;"><?php _e( 'Google (RAW) - Location Resource', 'lealez' ); ?></h4>
        <p class="description">
            <?php _e( 'Se guarda el objeto Location (Business Information API) tal cual se obtiene en importación. Útil para homologación completa.', 'lealez' ); ?>
        </p>
        <textarea readonly class="large-text" rows="10" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?php
            echo esc_textarea( $gmb_location_raw ? wp_json_encode( $gmb_location_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : '' );
        ?></textarea>

        <script>
        jQuery(document).ready(function($){
            var ajaxUrl   = (window.ajaxurl) ? window.ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce     = '<?php echo esc_js( $ajax_nonce ); ?>';

            function setHint(msg, type){
                var $h = $('#oy-gmb-location-hint');
                $h.text(msg || '');
                if(type === 'error'){ $h.css('color','#b32d2e'); }
                else if(type === 'success'){ $h.css('color','#1e7e34'); }
                else { $h.css('color','#555'); }
            }

            function normalizeAddressShort(loc){
                try{
                    var a = loc.storefrontAddress || {};
                    var lines = a.addressLines || [];
                    var city = a.locality || '';
                    var st = a.administrativeArea || '';
                    var out = [];
                    if(lines.length){ out.push(lines.join(' ')); }
                    var cst = [city, st].filter(Boolean).join(', ');
                    if(cst){ out.push(cst); }
                    return out.join(' — ');
                }catch(e){
                    return '';
                }
            }

            function loadLocationsForBusiness(businessId, selectedLocationName){
                var $sel = $('#gmb_location_name');
                $sel.prop('disabled', true).html('<option value=""><?php echo esc_js( __( 'Cargando ubicaciones...', 'lealez' ) ); ?></option>');
                $('#oy-gmb-refresh-location-list').prop('disabled', true);
                $('#oy-gmb-import-location-now').prop('disabled', true);
                setHint('', 'info');

                if(!businessId){
                    $sel.html('<option value=""><?php echo esc_js( __( 'Selecciona una empresa primero...', 'lealez' ) ); ?></option>');
                    return;
                }

                $.post(ajaxUrl, {
                    action: 'oy_get_gmb_locations_for_business',
                    nonce: nonce,
                    business_id: businessId
                }).done(function(resp){
                    if(!resp || !resp.success){
                        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'No se pudo cargar la lista de ubicaciones.', 'lealez' ) ); ?>';
                        setHint(msg, 'error');
                        $sel.prop('disabled', true);
                        $('#oy-gmb-refresh-location-list').prop('disabled', false);
                        return;
                    }

                    var list = resp.data.locations || [];
                    var total = resp.data.total || list.length;

                    var html = '<option value=""><?php echo esc_js( __( 'Seleccionar ubicación de Google...', 'lealez' ) ); ?></option>';
                    for(var i=0;i<list.length;i++){
                        var it = list[i];
                        var addr = (it.address_short) ? it.address_short : '';
                        var ver = (it.verification_state) ? (' ['+it.verification_state+']') : '';
                        var label = (it.title ? it.title : it.name) + (addr ? ' — '+addr : '') + ver;
                        html += '<option data-account="'+ (it.account_name || '') +'" value="'+ (it.name || '') +'">' + $('<div>').text(label).html() + '</option>';
                    }

                    $sel.html(html).prop('disabled', false);
                    $('#oy-gmb-refresh-location-list').prop('disabled', false);

                    if(selectedLocationName){
                        $sel.val(selectedLocationName);
                        if($sel.val()){
                            $('#oy-gmb-import-location-now').prop('disabled', false);
                            var acc = $sel.find('option:selected').data('account') || '';
                            $('#gmb_location_account_name').val(acc);
                            setHint('<?php echo esc_js( __( 'Ubicación seleccionada: lista cargada correctamente.', 'lealez' ) ); ?>', 'success');
                        }
                    }else{
                        setHint(total + ' <?php echo esc_js( __( 'ubicaciones encontradas para esta empresa.', 'lealez' ) ); ?>', 'info');
                    }
                }).fail(function(){
                    setHint('<?php echo esc_js( __( 'Error de red al cargar ubicaciones.', 'lealez' ) ); ?>', 'error');
                    $sel.prop('disabled', true);
                    $('#oy-gmb-refresh-location-list').prop('disabled', false);
                });
            }

            function applyLocationToForm(loc){
                // Map a subset to existing fields
                try{
                    // Title
                    if(loc.title){
                        // WP title field
                        $('#title').val(loc.title);
                    }

                    // Store code -> location_code (si está vacío)
                    if(loc.storeCode){
                        var $code = $('#location_code');
                        if($code.length && !$code.val()){
                            $code.val(loc.storeCode);
                        }
                    }

                    // Website
                    if(loc.websiteUri){
                        $('#location_website').val(loc.websiteUri);
                    }

                    // Phones
                    if(loc.phoneNumbers){
                        if(loc.phoneNumbers.primaryPhone){
                            $('#location_phone').val(loc.phoneNumbers.primaryPhone);
                        }
                        if(loc.phoneNumbers.additionalPhones && loc.phoneNumbers.additionalPhones.length){
                            $('#location_phone_additional').val(loc.phoneNumbers.additionalPhones[0]);
                        }
                    }

                    // Address
                    if(loc.storefrontAddress){
                        var a = loc.storefrontAddress;
                        if(a.addressLines && a.addressLines.length){
                            $('#location_address_line1').val(a.addressLines[0] || '');
                            $('#location_address_line2').val(a.addressLines[1] || '');
                        }
                        if(a.locality){ $('#location_city').val(a.locality); }
                        if(a.administrativeArea){ $('#location_state').val(a.administrativeArea); }
                        if(a.postalCode){ $('#location_postal_code').val(a.postalCode); }
                        if(a.regionCode){ $('#location_country').val(a.regionCode); }
                    }

                    // LatLng
                    if(loc.latlng){
                        if(typeof loc.latlng.latitude !== 'undefined'){
                            $('#location_latitude').val(loc.latlng.latitude);
                        }
                        if(typeof loc.latlng.longitude !== 'undefined'){
                            $('#location_longitude').val(loc.latlng.longitude);
                        }
                    }

                    // Category
                    if(loc.categories && loc.categories.primaryCategory){
                        var pc = loc.categories.primaryCategory;
                        var cat = pc.displayName || pc.name || '';
                        if(cat){
                            $('#google_primary_category').val(cat);
                        }
                    }

                    // Hours mapping (simple) -> location_hours_* meta UI
                    // We don't directly update the hours UI reliably here (it exists, but mapping is best done server-side on Import).
                }catch(e){}
            }

            function importNow(businessId, locationName, accountName){
                if(!businessId || !locationName){
                    setHint('<?php echo esc_js( __( 'Selecciona una empresa y una ubicación.', 'lealez' ) ); ?>', 'error');
                    return;
                }

                $('#oy-gmb-import-location-now').prop('disabled', true);
                setHint('<?php echo esc_js( __( 'Importando desde Google...', 'lealez' ) ); ?>', 'info');

                $.post(ajaxUrl, {
                    action: 'oy_get_gmb_location_details',
                    nonce: nonce,
                    business_id: businessId,
                    location_name: locationName,
                    account_name: accountName || ''
                }).done(function(resp){
                    if(!resp || !resp.success){
                        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'No se pudo importar la ubicación.', 'lealez' ) ); ?>';
                        setHint(msg, 'error');
                        $('#oy-gmb-import-location-now').prop('disabled', false);
                        return;
                    }

                    var loc = resp.data.location || null;
                    if(!loc){
                        setHint('<?php echo esc_js( __( 'No se recibió data de la ubicación.', 'lealez' ) ); ?>', 'error');
                        $('#oy-gmb-import-location-now').prop('disabled', false);
                        return;
                    }

                    // Apply to form fields visually (pre-save)
                    applyLocationToForm(loc);

                    // Fill read-only fields
                    if(resp.data.location_id){
                        $('#gmb_location_id').val(resp.data.location_id);
                    }
                    if(resp.data.account_id){
                        $('#gmb_account_id').val(resp.data.account_id);
                    }

                    setHint('<?php echo esc_js( __( 'Importación aplicada al formulario. Ahora guarda el post para persistir.', 'lealez' ) ); ?>', 'success');
                    $('#oy-gmb-import-location-now').prop('disabled', false);
                }).fail(function(){
                    setHint('<?php echo esc_js( __( 'Error de red durante importación.', 'lealez' ) ); ?>', 'error');
                    $('#oy-gmb-import-location-now').prop('disabled', false);
                });
            }

            // On business change: reload locations list
            $(document).on('change', '#parent_business_id', function(){
                var businessId = $(this).val();
                loadLocationsForBusiness(businessId, '');
            });

            // On load: if business already selected, load list and select saved location
            var initialBusiness = $('#parent_business_id').val();
            var savedLocation   = '<?php echo esc_js( (string) $gmb_location_name ); ?>';
            if(initialBusiness){
                loadLocationsForBusiness(initialBusiness, savedLocation);
            }else{
                $('#gmb_location_name').prop('disabled', true);
                $('#oy-gmb-refresh-location-list').prop('disabled', true);
                $('#oy-gmb-import-location-now').prop('disabled', true);
            }

            // On reload list button
            $(document).on('click', '#oy-gmb-refresh-location-list', function(e){
                e.preventDefault();
                var businessId = $('#parent_business_id').val();
                loadLocationsForBusiness(businessId, $('#gmb_location_name').val() || '');
            });

            // On select location: enable import now
            $(document).on('change', '#gmb_location_name', function(){
                var val = $(this).val();
                var acc = $(this).find('option:selected').data('account') || '';
                $('#gmb_location_account_name').val(acc);

                if(val){
                    $('#oy-gmb-import-location-now').prop('disabled', false);
                    setHint('<?php echo esc_js( __( 'Ubicación seleccionada. Puedes importar ahora o guardar con auto-import.', 'lealez' ) ); ?>', 'info');
                }else{
                    $('#oy-gmb-import-location-now').prop('disabled', true);
                }
            });

            // Import now button
            $(document).on('click', '#oy-gmb-import-location-now', function(e){
                e.preventDefault();
                var businessId = $('#parent_business_id').val();
                var locName    = $('#gmb_location_name').val();
                var accName    = $('#gmb_location_account_name').val();
                importNow(businessId, locName, accName);
            });
        });
        </script>
        <?php
    }

    /**
     * Render Attributes meta box
     */
    public function render_attributes_meta_box( $post ) {
        $google_primary_category = get_post_meta( $post->ID, 'google_primary_category', true );
        $price_range             = get_post_meta( $post->ID, 'price_range', true );
        $attributes_accessibility = get_post_meta( $post->ID, 'attributes_accessibility', true );
        $attributes_amenities     = get_post_meta( $post->ID, 'attributes_amenities', true );
        $attributes_payments      = get_post_meta( $post->ID, 'attributes_payments', true );

        // ✅ Google RAW categories
        $gmb_categories_raw        = get_post_meta( $post->ID, 'gmb_categories_raw', true );
        $gmb_primary_category_name = get_post_meta( $post->ID, 'gmb_primary_category_name', true );
        $gmb_primary_category_dn   = get_post_meta( $post->ID, 'gmb_primary_category_display_name', true );
        $gmb_additional_categories = get_post_meta( $post->ID, 'gmb_additional_categories', true );

        if ( ! is_array( $attributes_accessibility ) ) {
            $attributes_accessibility = array();
        }
        if ( ! is_array( $attributes_amenities ) ) {
            $attributes_amenities = array();
        }
        if ( ! is_array( $attributes_payments ) ) {
            $attributes_payments = array();
        }

        if ( ! is_array( $gmb_additional_categories ) ) {
            $gmb_additional_categories = array();
        }
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="google_primary_category"><?php _e( 'Categoría Principal (manual)', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="text"
                           name="google_primary_category"
                           id="google_primary_category"
                           value="<?php echo esc_attr( $google_primary_category ); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'Ej: Restaurant, Retail Store, Gym', 'lealez' ); ?>">
                    <p class="description">
                        <?php _e( 'Este campo es tu vista “humana”. Al importar, se poblará desde categories.primaryCategory.displayName.', 'lealez' ); ?>
                    </p>
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

        <hr>

        <h4><?php _e( 'Google (homologación de categorías)', 'lealez' ); ?></h4>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e( 'Primary Category (name)', 'lealez' ); ?></th>
                <td><input type="text" readonly class="large-text" value="<?php echo esc_attr( $gmb_primary_category_name ); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><?php _e( 'Primary Category (displayName)', 'lealez' ); ?></th>
                <td><input type="text" readonly class="large-text" value="<?php echo esc_attr( $gmb_primary_category_dn ); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><?php _e( 'Additional Categories', 'lealez' ); ?></th>
                <td>
                    <textarea readonly class="large-text" rows="3" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?php
                        echo esc_textarea( ! empty( $gmb_additional_categories ) ? wp_json_encode( $gmb_additional_categories, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : '' );
                    ?></textarea>
                </td>
            </tr>
        </table>

        <p class="description">
            <?php _e( 'RAW categories (objeto completo) tal cual Google lo entrega:', 'lealez' ); ?>
        </p>
        <textarea readonly class="large-text" rows="6" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?php
            echo esc_textarea( $gmb_categories_raw ? wp_json_encode( $gmb_categories_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : '' );
        ?></textarea>

        <hr>

        <h4><?php _e( 'Atributos desde Google My Business', 'lealez' ); ?></h4>
        <?php
        // ✅ CORRECCIÓN: Mostrar atributos sincronizados desde GMB
        $gmb_attributes_raw = get_post_meta( $post->ID, 'gmb_attributes_raw', true );
        
        if ( ! empty( $gmb_attributes_raw ) && is_array( $gmb_attributes_raw ) ) {
            // Organizar atributos por categorías
            $accessibility_attrs = array();
            $amenities_attrs = array();
            $payment_attrs = array();
            $other_attrs = array();

            foreach ( $gmb_attributes_raw as $attr ) {
                if ( ! is_array( $attr ) || empty( $attr['attributeId'] ) ) {
                    continue;
                }

                $attr_id = (string) $attr['attributeId'];
                $attr_name = $this->humanize_attribute_id( $attr_id );
                $values = isset( $attr['values'] ) && is_array( $attr['values'] ) ? $attr['values'] : array();
                $value_str = implode( ', ', array_map( 'strval', $values ) );

                // Clasificar por tipo
                if ( stripos( $attr_id, 'wheelchair' ) !== false || stripos( $attr_id, 'accessible' ) !== false ) {
                    $accessibility_attrs[] = array( 'name' => $attr_name, 'value' => $value_str, 'raw' => $attr_id );
                } elseif ( stripos( $attr_id, 'wifi' ) !== false || stripos( $attr_id, 'parking' ) !== false || 
                           stripos( $attr_id, 'restroom' ) !== false || stripos( $attr_id, 'outdoor' ) !== false || 
                           stripos( $attr_id, 'seating' ) !== false ) {
                    $amenities_attrs[] = array( 'name' => $attr_name, 'value' => $value_str, 'raw' => $attr_id );
                } elseif ( stripos( $attr_id, 'payment' ) !== false || stripos( $attr_id, 'credit' ) !== false || 
                           stripos( $attr_id, 'debit' ) !== false || stripos( $attr_id, 'cash' ) !== false || 
                           stripos( $attr_id, 'mobile_payment' ) !== false ) {
                    $payment_attrs[] = array( 'name' => $attr_name, 'value' => $value_str, 'raw' => $attr_id );
                } else {
                    $other_attrs[] = array( 'name' => $attr_name, 'value' => $value_str, 'raw' => $attr_id );
                }
            }

            // Mostrar Accesibilidad
            if ( ! empty( $accessibility_attrs ) ) {
                ?>
                <h4><?php _e( 'Accesibilidad', 'lealez' ); ?></h4>
                <table class="widefat" style="max-width: 800px; margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th style="width: 50%;"><?php _e( 'Atributo', 'lealez' ); ?></th>
                            <th style="width: 30%;"><?php _e( 'Valor', 'lealez' ); ?></th>
                            <th style="width: 20%;"><?php _e( 'ID GMB', 'lealez' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $accessibility_attrs as $attr ) : ?>
                            <tr>
                                <td><?php echo esc_html( $attr['name'] ); ?></td>
                                <td><strong><?php echo esc_html( $attr['value'] ); ?></strong></td>
                                <td><code style="font-size: 11px;"><?php echo esc_html( $attr['raw'] ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
            }

            // Mostrar Comodidades
            if ( ! empty( $amenities_attrs ) ) {
                ?>
                <h4><?php _e( 'Comodidades', 'lealez' ); ?></h4>
                <table class="widefat" style="max-width: 800px; margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th style="width: 50%;"><?php _e( 'Atributo', 'lealez' ); ?></th>
                            <th style="width: 30%;"><?php _e( 'Valor', 'lealez' ); ?></th>
                            <th style="width: 20%;"><?php _e( 'ID GMB', 'lealez' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $amenities_attrs as $attr ) : ?>
                            <tr>
                                <td><?php echo esc_html( $attr['name'] ); ?></td>
                                <td><strong><?php echo esc_html( $attr['value'] ); ?></strong></td>
                                <td><code style="font-size: 11px;"><?php echo esc_html( $attr['raw'] ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
            }

            // Mostrar Métodos de Pago
            if ( ! empty( $payment_attrs ) ) {
                ?>
                <h4><?php _e( 'Métodos de Pago', 'lealez' ); ?></h4>
                <table class="widefat" style="max-width: 800px; margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th style="width: 50%;"><?php _e( 'Atributo', 'lealez' ); ?></th>
                            <th style="width: 30%;"><?php _e( 'Valor', 'lealez' ); ?></th>
                            <th style="width: 20%;"><?php _e( 'ID GMB', 'lealez' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $payment_attrs as $attr ) : ?>
                            <tr>
                                <td><?php echo esc_html( $attr['name'] ); ?></td>
                                <td><strong><?php echo esc_html( $attr['value'] ); ?></strong></td>
                                <td><code style="font-size: 11px;"><?php echo esc_html( $attr['raw'] ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
            }

            // Mostrar Otros Atributos
            if ( ! empty( $other_attrs ) ) {
                ?>
                <h4><?php _e( 'Otros Atributos', 'lealez' ); ?></h4>
                <table class="widefat" style="max-width: 800px; margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th style="width: 50%;"><?php _e( 'Atributo', 'lealez' ); ?></th>
                            <th style="width: 30%;"><?php _e( 'Valor', 'lealez' ); ?></th>
                            <th style="width: 20%;"><?php _e( 'ID GMB', 'lealez' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $other_attrs as $attr ) : ?>
                            <tr>
                                <td><?php echo esc_html( $attr['name'] ); ?></td>
                                <td><strong><?php echo esc_html( $attr['value'] ); ?></strong></td>
                                <td><code style="font-size: 11px;"><?php echo esc_html( $attr['raw'] ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
            }
        } else {
            ?>
            <p class="description">
                <strong><?php _e( 'No hay atributos sincronizados desde Google My Business.', 'lealez' ); ?></strong>
                <br>
                <?php _e( 'Los atributos se sincronizan automáticamente al importar o actualizar la ubicación desde GMB.', 'lealez' ); ?>
            </p>
            <?php
        }

        // Mostrar el RAW completo para debugging
        ?>
        <p class="description" style="margin-top: 20px;">
            <?php _e( 'RAW attributes (array completo) tal cual Google lo entrega:', 'lealez' ); ?>
        </p>
        <textarea readonly class="large-text" rows="8" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?php
            echo esc_textarea( $gmb_attributes_raw ? wp_json_encode( $gmb_attributes_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : '' );
        ?></textarea>
        <?php
    }

/**
 * Helper: Convert GMB attribute ID to human-readable name
 *
 * @param string $attr_id
 * @return string
 */
private function humanize_attribute_id( $attr_id ) {
    // Convert snake_case to Title Case
    $attr_id = str_replace( '_', ' ', $attr_id );
    $attr_id = ucwords( strtolower( $attr_id ) );

    // Remove common prefixes
    $attr_id = str_replace( array( 'Has ', 'Offers ', 'Accepts ' ), '', $attr_id );

    return $attr_id;
}


    /**
     * Render GMB Metrics meta box
     */
    public function render_gmb_metrics_meta_box( $post ) {
        $profile_views     = get_post_meta( $post->ID, 'gmb_profile_views_30d', true );
        $calls             = get_post_meta( $post->ID, 'gmb_calls_30d', true );
        $website_clicks    = get_post_meta( $post->ID, 'gmb_website_clicks_30d', true );
        $direction_requests = get_post_meta( $post->ID, 'gmb_direction_requests_30d', true );
        $google_rating     = get_post_meta( $post->ID, 'google_rating', true );
        $reviews_count     = get_post_meta( $post->ID, 'google_reviews_count', true );
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
        $accepts_loyalty           = get_post_meta( $post->ID, 'accepts_loyalty', true );
        $loyalty_redemption_enabled = get_post_meta( $post->ID, 'loyalty_redemption_enabled', true );
        $loyalty_earning_enabled   = get_post_meta( $post->ID, 'loyalty_earning_enabled', true );
        $loyalty_multiplier        = get_post_meta( $post->ID, 'loyalty_multiplier', true );
        $loyalty_terminal_id       = get_post_meta( $post->ID, 'loyalty_terminal_id', true );

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

        // Define all meta fields to save (solo los que vienen del formulario)
        $meta_fields = array(
            // Parent Business
            'parent_business_id'              => 'sanitize_text_field',

            // Basic Info
            'location_code'                   => 'sanitize_text_field',
            'location_short_description'      => 'sanitize_textarea_field',
            'location_status'                 => 'sanitize_text_field',
            'opening_date'                    => 'sanitize_text_field',
            'closing_date'                    => 'sanitize_text_field',

            // Address (human)
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

            // Contact (human)
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

            // GMB (existing)
            'gmb_location_id'                 => 'sanitize_text_field',
            'gmb_account_id'                  => 'sanitize_text_field',
            'gmb_verified'                    => 'absint',
            'gmb_verification_method'         => 'sanitize_text_field',
            'gmb_auto_sync_enabled'           => 'absint',
            'gmb_sync_frequency'              => 'sanitize_text_field',

            // ✅ NEW: selector & import flag
            'gmb_location_name'               => 'sanitize_text_field',
            'gmb_location_account_name'       => 'sanitize_text_field',
            'gmb_import_on_save'              => 'absint',

            // Attributes (human)
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
                $value = call_user_func( $sanitize_callback, wp_unslash( $_POST[ $field_name ] ) );
                update_post_meta( $post_id, $field_name, $value );
            } else {
                // ✅ Campos readonly que NO deben borrarse si no vienen en POST
                $readonly_fields = array( 'gmb_location_id', 'gmb_account_id' );
                if ( in_array( $field_name, $readonly_fields, true ) ) {
                    // No hacer nada, mantener el valor existente
                    continue;
                }
                
                // ✅ ojo: checkboxes no vienen si están off
                if ( in_array( $field_name, array( 'gmb_verified', 'gmb_auto_sync_enabled', 'accepts_loyalty', 'loyalty_redemption_enabled', 'loyalty_earning_enabled', 'gmb_import_on_save' ), true ) ) {
                    delete_post_meta( $post_id, $field_name );
                } else {
                    // Para el resto, mantenemos comportamiento original
                    delete_post_meta( $post_id, $field_name );
                }
            }
        }

        // Save hours (per day) arrays
        $days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        foreach ( $days as $day ) {
            if ( isset( $_POST[ 'location_hours_' . $day ] ) ) {
                $raw = wp_unslash( $_POST[ 'location_hours_' . $day ] );
                $hours_data = array(
                    'closed' => ! empty( $raw['closed'] ),
                    'open'   => sanitize_text_field( $raw['open'] ?? '09:00' ),
                    'close'  => sanitize_text_field( $raw['close'] ?? '18:00' ),
                );
                update_post_meta( $post_id, 'location_hours_' . $day, $hours_data );
            }
        }

        // ✅ CORRECCIÓN: Atributos ahora vienen exclusivamente desde GMB (gmb_attributes_raw)
        // Ya no se guardan manualmente desde checkboxes

        // Save system metadata
        update_post_meta( $post_id, 'date_modified', current_time( 'mysql' ) );
        update_post_meta( $post_id, 'modified_by_user_id', get_current_user_id() );

        if ( ! get_post_meta( $post_id, 'date_created', true ) ) {
            update_post_meta( $post_id, 'date_created', current_time( 'mysql' ) );
            update_post_meta( $post_id, 'created_by_user_id', get_current_user_id() );
        }

        /**
         * ✅ Importación desde Google al guardar (si está activado y hay business + location_name)
         */
        $business_id     = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name   = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        $import_on_save  = (int) get_post_meta( $post_id, 'gmb_import_on_save', true );

        if ( $import_on_save === 1 && $business_id && ! empty( $location_name ) ) {
            $this->import_location_from_gmb_and_map_fields( $post_id, $business_id, $location_name );
        }
    }

    /**
     * ✅ Import a location from GMB (API) and map fields to the CPT meta.
     *
     * - Guarda RAW fields exactamente como Google los entrega (para homologación completa)
     * - Mapea a campos “humanos” existentes (para compatibilidad con el resto del sistema)
     *
     * @param int    $post_id
     * @param int    $business_id
     * @param string $location_name e.g. accounts/123/locations/456
     * @return void
     */
    private function import_location_from_gmb_and_map_fields( $post_id, $business_id, $location_name ) {
        $post_id       = absint( $post_id );
        $business_id   = absint( $business_id );
        $location_name = sanitize_text_field( (string) $location_name );

        if ( ! $post_id || ! $business_id || '' === $location_name ) {
            return;
        }

        if ( ! class_exists( 'Lealez_GMB_API' ) ) {
            return;
        }

        $data = Lealez_GMB_API::sync_location_data( $business_id, $location_name );
        if ( is_wp_error( $data ) || ! is_array( $data ) ) {
            // No rompemos el save: solo registramos last error
            update_post_meta( $post_id, 'gmb_last_import_error', is_wp_error( $data ) ? $data->get_error_message() : 'Unknown error' );
            return;
        }

        // ✅ Guardar RAW completo
        update_post_meta( $post_id, 'gmb_location_raw', $data );

        // Extract locationId (último segmento)
        $location_id = $this->extract_location_id_from_resource_name( $location_name );
        if ( $location_id ) {
            update_post_meta( $post_id, 'gmb_location_id', $location_id );
        }

        // account id: intentamos inferirlo desde "accounts/{id}/locations/{id}"
        $account_id = $this->extract_account_name_from_location_name( $location_name );
        if ( $account_id ) {
            update_post_meta( $post_id, 'gmb_account_id', $account_id );
        }

        // Title -> WP title + meta
        $title = isset( $data['title'] ) ? (string) $data['title'] : '';
        if ( $title ) {
            // Update WP post_title safely
            remove_action( 'save_post_oy_location', array( $this, 'save_meta_boxes' ), 10 );
            wp_update_post( array(
                'ID'         => $post_id,
                'post_title' => $title,
            ) );
            add_action( 'save_post_oy_location', array( $this, 'save_meta_boxes' ), 10, 2 );
        }

        // storeCode
        $store_code = isset( $data['storeCode'] ) ? (string) $data['storeCode'] : '';
        if ( $store_code ) {
            update_post_meta( $post_id, 'gmb_store_code', $store_code );

            // Si location_code está vacío, lo llenamos
            $existing_code = (string) get_post_meta( $post_id, 'location_code', true );
            if ( '' === $existing_code ) {
                update_post_meta( $post_id, 'location_code', $store_code );
            }
        }

        // languageCode
        if ( ! empty( $data['languageCode'] ) ) {
            update_post_meta( $post_id, 'gmb_language_code', sanitize_text_field( (string) $data['languageCode'] ) );
        }

        // phoneNumbers (RAW + map)
        $phone_numbers = isset( $data['phoneNumbers'] ) && is_array( $data['phoneNumbers'] ) ? $data['phoneNumbers'] : array();
        if ( ! empty( $phone_numbers ) ) {
            update_post_meta( $post_id, 'gmb_phone_numbers_raw', $phone_numbers );

            if ( ! empty( $phone_numbers['primaryPhone'] ) ) {
                update_post_meta( $post_id, 'location_phone', sanitize_text_field( (string) $phone_numbers['primaryPhone'] ) );
            }
            if ( ! empty( $phone_numbers['additionalPhones'] ) && is_array( $phone_numbers['additionalPhones'] ) ) {
                update_post_meta( $post_id, 'gmb_phone_additional_list', array_map( 'sanitize_text_field', $phone_numbers['additionalPhones'] ) );

                // Si hay additionalPhones, ponemos el primero en el campo humano location_phone_additional
                $first = (string) ( $phone_numbers['additionalPhones'][0] ?? '' );
                if ( $first ) {
                    update_post_meta( $post_id, 'location_phone_additional', sanitize_text_field( $first ) );
                }
            }
        }

        // websiteUri (RAW + map)
        if ( ! empty( $data['websiteUri'] ) ) {
            $website = esc_url_raw( (string) $data['websiteUri'] );
            update_post_meta( $post_id, 'gmb_website_uri', $website );
            update_post_meta( $post_id, 'location_website', $website );
        }

        // categories (RAW + map)
        $categories = isset( $data['categories'] ) && is_array( $data['categories'] ) ? $data['categories'] : array();
        if ( ! empty( $categories ) ) {
            update_post_meta( $post_id, 'gmb_categories_raw', $categories );

            $primary = isset( $categories['primaryCategory'] ) && is_array( $categories['primaryCategory'] ) ? $categories['primaryCategory'] : array();
            if ( ! empty( $primary ) ) {
                if ( ! empty( $primary['name'] ) ) {
                    update_post_meta( $post_id, 'gmb_primary_category_name', sanitize_text_field( (string) $primary['name'] ) );
                }
                if ( ! empty( $primary['displayName'] ) ) {
                    update_post_meta( $post_id, 'gmb_primary_category_display_name', sanitize_text_field( (string) $primary['displayName'] ) );

                    // Map to human field
                    update_post_meta( $post_id, 'google_primary_category', sanitize_text_field( (string) $primary['displayName'] ) );
                }
            }

            $additional = isset( $categories['additionalCategories'] ) && is_array( $categories['additionalCategories'] ) ? $categories['additionalCategories'] : array();
            if ( ! empty( $additional ) ) {
                $out = array();
                foreach ( $additional as $c ) {
                    if ( is_array( $c ) ) {
                        $out[] = array(
                            'name'        => isset( $c['name'] ) ? sanitize_text_field( (string) $c['name'] ) : '',
                            'displayName' => isset( $c['displayName'] ) ? sanitize_text_field( (string) $c['displayName'] ) : '',
                        );
                    }
                }
                update_post_meta( $post_id, 'gmb_additional_categories', $out );
            }
        }

        // latlng (RAW + map)
        $latlng = isset( $data['latlng'] ) && is_array( $data['latlng'] ) ? $data['latlng'] : array();
        if ( ! empty( $latlng ) ) {
            update_post_meta( $post_id, 'gmb_latlng_raw', $latlng );
            if ( isset( $latlng['latitude'] ) ) {
                update_post_meta( $post_id, 'location_latitude', sanitize_text_field( (string) $latlng['latitude'] ) );
            }
            if ( isset( $latlng['longitude'] ) ) {
                update_post_meta( $post_id, 'location_longitude', sanitize_text_field( (string) $latlng['longitude'] ) );
            }
        }

        // storefrontAddress (RAW + map)
        $addr = isset( $data['storefrontAddress'] ) && is_array( $data['storefrontAddress'] ) ? $data['storefrontAddress'] : array();
        if ( ! empty( $addr ) ) {
            update_post_meta( $post_id, 'gmb_storefront_address_raw', $addr );

            // Map to human fields
            $lines = isset( $addr['addressLines'] ) && is_array( $addr['addressLines'] ) ? $addr['addressLines'] : array();
            if ( ! empty( $lines ) ) {
                update_post_meta( $post_id, 'location_address_line1', sanitize_text_field( (string) ( $lines[0] ?? '' ) ) );
                
                // ✅ CORRECCIÓN: Complemento usa subPremise primero, fallback a addressLines[1]
                $complement = '';
                if ( ! empty( $addr['subPremise'] ) ) {
                    $complement = sanitize_text_field( (string) $addr['subPremise'] );
                } elseif ( ! empty( $lines[1] ) ) {
                    $complement = sanitize_text_field( (string) $lines[1] );
                }
                if ( $complement ) {
                    update_post_meta( $post_id, 'location_address_line2', $complement );
                }
            }
            if ( ! empty( $addr['locality'] ) ) {
                update_post_meta( $post_id, 'location_city', sanitize_text_field( (string) $addr['locality'] ) );
            }
            // ✅ Estado/Departamento: administrativeArea (ya estaba bien mapeado)
            if ( ! empty( $addr['administrativeArea'] ) ) {
                update_post_meta( $post_id, 'location_state', sanitize_text_field( (string) $addr['administrativeArea'] ) );
            }
            if ( ! empty( $addr['postalCode'] ) ) {
                update_post_meta( $post_id, 'location_postal_code', sanitize_text_field( (string) $addr['postalCode'] ) );
            }
            if ( ! empty( $addr['regionCode'] ) ) {
                update_post_meta( $post_id, 'location_country', sanitize_text_field( (string) $addr['regionCode'] ) );
            }
            if ( ! empty( $addr['sublocality'] ) ) {
                update_post_meta( $post_id, 'location_neighborhood', sanitize_text_field( (string) $addr['sublocality'] ) );
            }
        }

        // regularHours (RAW + map to per day meta)
        $regular = isset( $data['regularHours'] ) && is_array( $data['regularHours'] ) ? $data['regularHours'] : array();
        if ( ! empty( $regular ) ) {
            update_post_meta( $post_id, 'gmb_regular_hours_raw', $regular );

            $mapped = $this->map_gmb_regular_hours_to_daily_meta( $regular );
            if ( is_array( $mapped ) && ! empty( $mapped ) ) {
                foreach ( $mapped as $day_key => $hours_data ) {
                    update_post_meta( $post_id, 'location_hours_' . $day_key, $hours_data );
                }
            }
        }

        // specialHours / moreHours (RAW)
        $special = isset( $data['specialHours'] ) && is_array( $data['specialHours'] ) ? $data['specialHours'] : array();
        if ( ! empty( $special ) ) {
            update_post_meta( $post_id, 'gmb_special_hours_raw', $special );
        }
        $more = isset( $data['moreHours'] ) && is_array( $data['moreHours'] ) ? $data['moreHours'] : array();
        if ( ! empty( $more ) ) {
            update_post_meta( $post_id, 'gmb_more_hours_raw', $more );
        }

        // openInfo / locationState / metadata / labels
        if ( ! empty( $data['openInfo'] ) && is_array( $data['openInfo'] ) ) {
            update_post_meta( $post_id, 'gmb_open_info_raw', $data['openInfo'] );
        }
        if ( ! empty( $data['locationState'] ) && is_array( $data['locationState'] ) ) {
            update_post_meta( $post_id, 'gmb_location_state_raw', $data['locationState'] );
        }
        if ( ! empty( $data['metadata'] ) && is_array( $data['metadata'] ) ) {
            update_post_meta( $post_id, 'gmb_metadata_raw', $data['metadata'] );
        }
        if ( ! empty( $data['labels'] ) && is_array( $data['labels'] ) ) {
            update_post_meta( $post_id, 'gmb_labels_raw', $data['labels'] );
        }

        // ✅ NUEVO: Guardar attributes desde GMB
        if ( ! empty( $data['attributes'] ) && is_array( $data['attributes'] ) ) {
            update_post_meta( $post_id, 'gmb_attributes_raw', $data['attributes'] );
        }

        // ✅ Verification details si existen (en tu cache ya traías verification)
        // Si en el response viene "verification" lo guardamos también
        if ( ! empty( $data['verification'] ) && is_array( $data['verification'] ) ) {
            $v = $data['verification'];
            
            // Debug logging
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location Import] Verification data received: ' . print_r( $v, true ) );
            }
            
            if ( ! empty( $v['state'] ) ) {
                update_post_meta( $post_id, 'gmb_verification_state', sanitize_text_field( (string) $v['state'] ) );
                
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location Import] Saved gmb_verification_state: ' . $v['state'] );
                }
                
                // checkbox boolean
                if ( strtoupper( (string) $v['state'] ) === 'VERIFIED' ) {
                    update_post_meta( $post_id, 'gmb_verified', 1 );
                }
            }
            if ( ! empty( $v['name'] ) ) {
                update_post_meta( $post_id, 'gmb_verification_name', sanitize_text_field( (string) $v['name'] ) );
            }
            if ( ! empty( $v['createTime'] ) ) {
                update_post_meta( $post_id, 'gmb_verification_create_time', sanitize_text_field( (string) $v['createTime'] ) );
            }
        } else {
            // Debug: no verification data
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location Import] No verification data in import payload for post_id: ' . $post_id );
            }
        }

        // Mark last sync timestamp
        update_post_meta( $post_id, 'gmb_last_sync', time() );
        delete_post_meta( $post_id, 'gmb_last_import_error' );
    }

    /**
     * ✅ Convert Google's regularHours.periods -> your daily meta fields
     *
     * Google regularHours format:
     *  regularHours: { periods: [ { openDay: 'MONDAY', openTime: { hours: 9, minutes: 0 }, closeDay: 'MONDAY', closeTime: {...} } ] }
     *
     * Your meta per day:
     *  location_hours_monday: { closed: bool, open: '09:00', close: '18:00' }
     *
     * We map the FIRST period per day (simple model). If multiple periods exist, we store RAW anyway.
     *
     * @param array $regular
     * @return array
     */
    private function map_gmb_regular_hours_to_daily_meta( $regular ) {
        if ( ! is_array( $regular ) ) {
            return array();
        }

        $periods = isset( $regular['periods'] ) && is_array( $regular['periods'] ) ? $regular['periods'] : array();
        if ( empty( $periods ) ) {
            return array();
        }

        $day_map = array(
            'MONDAY'    => 'monday',
            'TUESDAY'   => 'tuesday',
            'WEDNESDAY' => 'wednesday',
            'THURSDAY'  => 'thursday',
            'FRIDAY'    => 'friday',
            'SATURDAY'  => 'saturday',
            'SUNDAY'    => 'sunday',
        );

        // default all closed false with fallback times (only if a period exists)
        $out = array();

        foreach ( $periods as $p ) {
            if ( ! is_array( $p ) ) {
                continue;
            }

            $open_day = isset( $p['openDay'] ) ? (string) $p['openDay'] : '';
            if ( ! $open_day || ! isset( $day_map[ $open_day ] ) ) {
                continue;
            }

            $day_key = $day_map[ $open_day ];

            // If already mapped a period for this day, skip (simple model)
            if ( isset( $out[ $day_key ] ) ) {
                continue;
            }

            $open_time  = isset( $p['openTime'] ) && is_array( $p['openTime'] ) ? $p['openTime'] : array();
            $close_time = isset( $p['closeTime'] ) && is_array( $p['closeTime'] ) ? $p['closeTime'] : array();

            $open_h = isset( $open_time['hours'] ) ? (int) $open_time['hours'] : 9;
            $open_m = isset( $open_time['minutes'] ) ? (int) $open_time['minutes'] : 0;

            $close_h = isset( $close_time['hours'] ) ? (int) $close_time['hours'] : 18;
            $close_m = isset( $close_time['minutes'] ) ? (int) $close_time['minutes'] : 0;

            $open_str  = sprintf( '%02d:%02d', $open_h, $open_m );
            $close_str = sprintf( '%02d:%02d', $close_h, $close_m );

            $out[ $day_key ] = array(
                'closed' => false,
                'open'   => $open_str,
                'close'  => $close_str,
            );
        }

        // For days not present in periods, we do NOT forcibly set closed=true (Google may omit closed days).
        // If you want that behavior later, lo podemos endurecer.
        return $out;
    }

    /**
     * Extract locationId from accounts/.../locations/{id}
     *
     * @param string $location_name
     * @return string
     */
    private function extract_location_id_from_resource_name( $location_name ) {
        $location_name = trim( (string) $location_name, '/' );
        if ( '' === $location_name ) {
            return '';
        }

        if ( strpos( $location_name, '/locations/' ) !== false ) {
            $parts = explode( '/locations/', $location_name );
            $id    = end( $parts );
            return trim( (string) $id, '/' );
        }

        $chunks = explode( '/', $location_name );
        $last   = end( $chunks );
        return trim( (string) $last, '/' );
    }

    /**
     * Extract account resource name from location resource name
     * Example: accounts/123/locations/456 -> 123 (solo el ID numérico)
     *
     * @param string $location_name
     * @return string
     */
    private function extract_account_name_from_location_name( $location_name ) {
        $location_name = trim( (string) $location_name, '/' );
        if ( '' === $location_name ) {
            return '';
        }

        // accounts/{acc}/locations/{id}
        if ( strpos( $location_name, '/locations/' ) !== false && strpos( $location_name, 'accounts/' ) === 0 ) {
            $parts = explode( '/locations/', $location_name );
            $left  = $parts[0] ?? '';
            // ✅ CORRECCIÓN: Extraer solo el ID numérico, no "accounts/123"
            $left = trim( (string) $left, '/' );
            if ( strpos( $left, 'accounts/' ) === 0 ) {
                $left = str_replace( 'accounts/', '', $left );
            }
            return trim( (string) $left, '/' );
        }

        return '';
    }

    /**
     * ✅ AJAX: list locations for a business (from cached meta _gmb_locations_available)
     */
    public function ajax_get_gmb_locations_for_business() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, $this->ajax_nonce_action ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }

        $business_id = isset( $_POST['business_id'] ) ? absint( wp_unslash( $_POST['business_id'] ) ) : 0;
        if ( ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'business_id inválido.', 'lealez' ) ) );
        }

        $locations = get_post_meta( $business_id, '_gmb_locations_available', true );
        if ( ! is_array( $locations ) ) {
            $locations = array();
        }

        $out = array();
        foreach ( $locations as $loc ) {
            if ( ! is_array( $loc ) ) {
                continue;
            }

            $name  = $loc['name'] ?? '';
            $title = $loc['title'] ?? '';

            $addr_short = '';
            if ( ! empty( $loc['storefrontAddress'] ) && is_array( $loc['storefrontAddress'] ) ) {
                $a = $loc['storefrontAddress'];
                $lines = isset( $a['addressLines'] ) && is_array( $a['addressLines'] ) ? $a['addressLines'] : array();
                $city  = $a['locality'] ?? '';
                $st    = $a['administrativeArea'] ?? '';
                $addr_short = trim( implode( ' ', $lines ) );
                $cst = trim( implode( ', ', array_filter( array( $city, $st ) ) ) );
                if ( $cst ) {
                    $addr_short .= ( $addr_short ? ' — ' : '' ) . $cst;
                }
            }

            $verification_state = '';
            if ( ! empty( $loc['verification'] ) && is_array( $loc['verification'] ) && ! empty( $loc['verification']['state'] ) ) {
                $verification_state = (string) $loc['verification']['state'];
            }

            $out[] = array(
                'name'              => (string) $name,
                'title'             => (string) $title,
                'account_name'      => (string) ( $loc['account_name'] ?? '' ),
                'address_short'     => (string) $addr_short,
                'verification_state'=> (string) $verification_state,
            );
        }

        wp_send_json_success( array(
            'total'     => count( $out ),
            'locations' => $out,
        ) );
    }

    /**
     * ✅ AJAX: get details for one location.
     * First tries business cached list; if not found, calls Lealez_GMB_API::sync_location_data().
     */
    public function ajax_get_gmb_location_details() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, $this->ajax_nonce_action ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }

        $business_id    = isset( $_POST['business_id'] ) ? absint( wp_unslash( $_POST['business_id'] ) ) : 0;
        $location_name  = isset( $_POST['location_name'] ) ? sanitize_text_field( wp_unslash( $_POST['location_name'] ) ) : '';
        $account_name   = isset( $_POST['account_name'] ) ? sanitize_text_field( wp_unslash( $_POST['account_name'] ) ) : '';

        if ( ! $business_id || '' === $location_name ) {
            wp_send_json_error( array( 'message' => __( 'Parámetros inválidos.', 'lealez' ) ) );
        }

        // 1) Try cached list
        $locations = get_post_meta( $business_id, '_gmb_locations_available', true );
        if ( ! is_array( $locations ) ) {
            $locations = array();
        }

        $found = null;
        foreach ( $locations as $loc ) {
            if ( is_array( $loc ) && ( (string) ( $loc['name'] ?? '' ) === (string) $location_name ) ) {
                $found = $loc;
                break;
            }
        }

        // 2) If not found, call API get
        if ( null === $found ) {
            if ( ! class_exists( 'Lealez_GMB_API' ) ) {
                wp_send_json_error( array( 'message' => __( 'Lealez_GMB_API no está disponible.', 'lealez' ) ) );
            }

            $fresh = Lealez_GMB_API::sync_location_data( $business_id, $location_name );
            if ( is_wp_error( $fresh ) || ! is_array( $fresh ) ) {
                $msg = is_wp_error( $fresh ) ? $fresh->get_error_message() : __( 'No se pudo obtener la ubicación.', 'lealez' );
                wp_send_json_error( array( 'message' => $msg ) );
            }

            // ✅ CORRECCIÓN: Obtener verification desde Verifications API
            $location_id = $this->extract_location_id_from_resource_name( $location_name );
            if ( ! empty( $location_id ) ) {
                $verification = Lealez_GMB_API::get_location_verification_state( $business_id, $location_id, true );
                
                // Debug logging
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] Verification API call for location_id: ' . $location_id );
                    error_log( '[OY Location] Verification result: ' . print_r( $verification, true ) );
                }
                
                if ( is_array( $verification ) && ! empty( $verification ) ) {
                    $fresh['verification'] = $verification;
                } else {
                    $fresh['verification'] = array();
                }
            } else {
                $fresh['verification'] = array();
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] Could not extract location_id from: ' . $location_name );
                }
            }

            // Attach some compat fields to mimic cached structure
            $fresh['account_name'] = $account_name;
            $found = $fresh;
        }

        $location_id = $this->extract_location_id_from_resource_name( $location_name );
        $account_id  = $this->extract_account_name_from_location_name( $location_name );

        wp_send_json_success( array(
            'location'    => $found,
            'location_id' => $location_id,
            'account_id'  => $account_id,
        ) );
    }

    /**
     * Set custom admin columns
     */
    public function set_custom_columns( $columns ) {
        $new_columns = array();

        // Reorder columns
        $new_columns['cb']            = $columns['cb'];
        $new_columns['title']         = __( 'Nombre de Ubicación', 'lealez' );
        $new_columns['parent_business'] = __( 'Empresa', 'lealez' );
        $new_columns['location_code'] = __( 'Código', 'lealez' );
        $new_columns['city']          = __( 'Ciudad', 'lealez' );
        $new_columns['status']        = __( 'Estado', 'lealez' );
        $new_columns['gmb_status']    = __( 'GMB', 'lealez' );
        $new_columns['metrics']       = __( 'Métricas (30d)', 'lealez' );
        $new_columns['date']          = __( 'Fecha', 'lealez' );

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
                    echo '<span style="color:#dc3232;">—</span>';
                }
                break;

            case 'location_code':
                $code = get_post_meta( $post_id, 'location_code', true );
                echo $code ? '<code>' . esc_html( $code ) . '</code>' : '—';
                break;

            case 'city':
                $city  = get_post_meta( $post_id, 'location_city', true );
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
                        '<span style="color:%s; font-weight:600;">%s</span>',
                        esc_attr( $status_labels[ $status ]['color'] ),
                        esc_html( $status_labels[ $status ]['label'] )
                    );
                } else {
                    echo '—';
                }
                break;

            case 'gmb_status':
                $gmb_verified    = get_post_meta( $post_id, 'gmb_verified', true );
                $gmb_location_id = get_post_meta( $post_id, 'gmb_location_id', true );
                if ( $gmb_verified && $gmb_location_id ) {
                    echo '<span style="color:#46b450;" title="' . esc_attr__( 'Verificada', 'lealez' ) . '">✓</span>';
                } elseif ( $gmb_location_id ) {
                    echo '<span style="color:#f0b322;" title="' . esc_attr__( 'Conectada pero no verificada', 'lealez' ) . '">⚠</span>';
                } else {
                    echo '<span style="color:#999;" title="' . esc_attr__( 'No conectada', 'lealez' ) . '">—</span>';
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
                    echo '<span style="color:#999;">—</span>';
                }
                break;
        }
    }

    /**
     * Make columns sortable
     */
    public function sortable_columns( $columns ) {
        $columns['location_code'] = 'location_code';
        $columns['city']          = 'city';
        $columns['status']        = 'status';
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
                defined( 'LEALEZ_ASSETS_URL' ) ? LEALEZ_ASSETS_URL . 'js/admin/oy-location.js' : '',
                array( 'jquery' ),
                defined( 'LEALEZ_VERSION' ) ? LEALEZ_VERSION : '1.0.0',
                true
            );

            // Localize for potential external JS (even if we also have inline scripts)
            wp_localize_script(
                'oy-location-admin',
                'oyLocationGmb',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( $this->ajax_nonce_action ),
                )
            );

            wp_enqueue_style(
                'oy-location-admin',
                defined( 'LEALEZ_ASSETS_URL' ) ? LEALEZ_ASSETS_URL . 'css/admin/oy-location.css' : '',
                array(),
                defined( 'LEALEZ_VERSION' ) ? LEALEZ_VERSION : '1.0.0'
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
