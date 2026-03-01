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
    // Priority 20 ensures this runs AFTER OY_Location_Menu_Metabox::save_meta_box (priority 15)
    // so that contact-metabox fields (incl. location_menu_url_gmb) win over the menu metabox hidden field.
    add_action( 'save_post_oy_location', array( $this, 'save_meta_boxes' ), 20, 2 );

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
    add_action( 'wp_ajax_oy_sync_location_food_menus', array( $this, 'ajax_sync_location_food_menus' ) );

    /**
     * ✅ Metabox externo: Fotos del propietario (GBP Media)
     * Archivo: includes/cpts/metaboxes/class-oy-location-gmb-media-metabox.php
     */
    $media_metabox_file = dirname( __FILE__ ) . '/metaboxes/class-oy-location-gmb-media-metabox.php';
    if ( file_exists( $media_metabox_file ) ) {
        require_once $media_metabox_file;

        if ( class_exists( 'OY_Location_GMB_Media_Metabox' ) ) {
            new OY_Location_GMB_Media_Metabox();
        }
    }

    /**
     * ✅ Metabox externo: Menú del Negocio
     * Archivo: includes/cpts/metaboxes/class-oy-location-menu-metabox.php
     */
    $menu_metabox_file = dirname( __FILE__ ) . '/metaboxes/class-oy-location-menu-metabox.php';
    if ( file_exists( $menu_metabox_file ) ) {
        require_once $menu_metabox_file;

        if ( class_exists( 'OY_Location_Menu_Metabox' ) ) {
            new OY_Location_Menu_Metabox();
        }
    }
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
        'supports'              => array( 'title', 'thumbnail', 'author' ),
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

        // ── SIDEBAR ─────────────────────────────────────────────

        // Parent Business Selection (sidebar top)
        add_meta_box(
            'oy_location_parent_business',
            __( 'Empresa/Negocio', 'lealez' ),
            array( $this, 'render_parent_business_meta_box' ),
            $this->post_type,
            'side',
            'high'
        );

        // GMB Metrics (sidebar)
        add_meta_box(
            'oy_location_gmb_metrics',
            __( 'Métricas de Google My Business', 'lealez' ),
            array( $this, 'render_gmb_metrics_meta_box' ),
            $this->post_type,
            'side',
            'default'
        );

        // ── MAIN COLUMN ──────────────────────────────────────────

        // 1. Google My Business Integration — primero para que el import rellene los campos
        add_meta_box(
            'oy_location_gmb',
            __( 'Integración Google My Business', 'lealez' ),
            array( $this, 'render_gmb_meta_box' ),
            $this->post_type,
            'normal',
            'high'
        );

        // 2. Basic Information
        add_meta_box(
            'oy_location_basic_info',
            __( 'Información Básica', 'lealez' ),
            array( $this, 'render_basic_info_meta_box' ),
            $this->post_type,
            'normal',
            'high'
        );

        // 3. Address and Geolocation
        add_meta_box(
            'oy_location_address',
            __( 'Dirección y Geolocalización', 'lealez' ),
            array( $this, 'render_address_meta_box' ),
            $this->post_type,
            'normal',
            'high'
        );

        // 4. Contact Information
        add_meta_box(
            'oy_location_contact',
            __( 'Información de Contacto', 'lealez' ),
            array( $this, 'render_contact_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // 5. Business Hours
        add_meta_box(
            'oy_location_hours',
            __( 'Horarios de Atención', 'lealez' ),
            array( $this, 'render_hours_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // 6. Attributes and Features
        add_meta_box(
            'oy_location_attributes',
            __( 'Atributos y Características', 'lealez' ),
            array( $this, 'render_attributes_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // 7. Loyalty Program Settings
        add_meta_box(
            'oy_location_loyalty',
            __( 'Configuración de Lealtad', 'lealez' ),
            array( $this, 'render_loyalty_meta_box' ),
            $this->post_type,
            'normal',
            'low'
        );

        // 8. Staff, Social & Notes — campos manuales que NO vienen de GMB
        add_meta_box(
            'oy_location_staff_notes',
            __( 'Personal, Redes Sociales & Notas', 'lealez' ),
            array( $this, 'render_staff_notes_meta_box' ),
            $this->post_type,
            'normal',
            'low'
        );

        // 9. Technical Data / RAW — consolidado, colapsado por defecto
        add_meta_box(
            'oy_location_technical_data',
            __( '🔧 Datos Técnicos / RAW Google', 'lealez' ),
            array( $this, 'render_technical_data_meta_box' ),
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

        if ( empty( $location_status ) ) {
            $location_status = 'active';
        }

        $desc_len = mb_strlen( $location_short_description );
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
                    <label for="location_short_description"><?php _e( 'Descripción (GMB)', 'lealez' ); ?></label>
                </th>
                <td>
                    <textarea name="location_short_description"
                              id="location_short_description"
                              rows="4"
                              class="large-text"
                              maxlength="750"
                              placeholder="<?php esc_attr_e( 'Máximo 750 caracteres (límite de Google My Business)', 'lealez' ); ?>"><?php echo esc_textarea( $location_short_description ); ?></textarea>
                    <p class="description">
                        <?php _e( 'Descripción del negocio para Google My Business (máximo 750 caracteres). Importado desde GMB: <code>profile.description</code>.', 'lealez' ); ?>
                        <span id="gmb-desc-char-count" style="font-weight:600;"><?php echo esc_html( $desc_len ); ?>/750</span>
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
        </table>
        <script>
        jQuery(document).ready(function($){
            var $ta  = $('#location_short_description');
            var $cnt = $('#gmb-desc-char-count');
            $ta.on('input', function(){
                var len = $(this).val().length;
                $cnt.text(len + '/750');
                $cnt.css('color', len > 700 ? '#dc3232' : '');
            });
        });
        </script>
        <?php
    }

/**
 * Render Address meta box
 */
public function render_address_meta_box( $post ) {
    $address_line1        = get_post_meta( $post->ID, 'location_address_line1', true );
    $address_line2        = get_post_meta( $post->ID, 'location_address_line2', true );
    $neighborhood         = get_post_meta( $post->ID, 'location_neighborhood', true );
    $city                 = get_post_meta( $post->ID, 'location_city', true );
    $state                = get_post_meta( $post->ID, 'location_state', true );
    $country              = get_post_meta( $post->ID, 'location_country', true );
    $postal_code          = get_post_meta( $post->ID, 'location_postal_code', true );
    $latitude             = get_post_meta( $post->ID, 'location_latitude', true );
    $longitude            = get_post_meta( $post->ID, 'location_longitude', true );
    $formatted_address    = get_post_meta( $post->ID, 'location_formatted_address', true );
    $map_url              = get_post_meta( $post->ID, 'location_map_url', true );
    $service_area_only    = get_post_meta( $post->ID, 'service_area_only', true );
    $show_address         = get_post_meta( $post->ID, 'show_address_to_customers', true );

    // Default: show address to customers unless explicitly disabled
    if ( '' === $show_address ) {
        $show_address = '1';
    }

    if ( empty( $country ) ) {
        $country = '';
    }

    // Determine initial states
    $is_service_area    = ( '1' === (string) $service_area_only );
    $is_show_address    = ( '1' === (string) $show_address );
    $address_hidden     = $is_service_area && ! $is_show_address;
    $show_address_row   = $is_service_area;

    // Build initial map embed URL (iframe embed — no API key required)
    $has_coords     = ( $latitude && $longitude );
    $embed_url      = '';
    $map_link_url   = $map_url;

    if ( $has_coords ) {
        $embed_url    = 'https://maps.google.com/maps?q=' . esc_attr( $latitude ) . ',' . esc_attr( $longitude ) . '&z=17&output=embed';
        if ( empty( $map_link_url ) ) {
            $map_link_url = 'https://maps.google.com/maps?q=' . esc_attr( $latitude ) . ',' . esc_attr( $longitude );
        }
    }
    ?>

    <?php /* ── Ubicación de la empresa (alineado con GMB) ── */ ?>
    <div style="background:#f0f6fc; border:1px solid #c3d4e6; border-radius:4px; padding:14px 16px; margin-bottom:20px;">
        <h4 style="margin:0 0 8px; font-size:14px; color:#1d2327;">
            📍 <?php _e( 'Ubicación de la empresa', 'lealez' ); ?>
        </h4>
        <p class="description" style="margin:0 0 12px;">
            <?php _e( 'Si los clientes visitan tu empresa, agrega una dirección. Si solo ofreces servicios en el domicilio del cliente o en línea, activa la opción "Sin ubicación física".', 'lealez' ); ?>
        </p>

        <label style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
            <input type="checkbox"
                   name="service_area_only"
                   id="service_area_only"
                   value="1"
                   <?php checked( $service_area_only, '1' ); ?>>
            <strong><?php _e( 'Sin ubicación física — solo envíos y servicios en el hogar', 'lealez' ); ?></strong>
        </label>

        <div id="oy-show-address-row"
             style="display:<?php echo $show_address_row ? 'flex' : 'none'; ?>; align-items:center; gap:10px; margin-top:6px;">
            <label class="oy-toggle-label" style="display:flex; align-items:center; gap:8px;">
                <input type="checkbox"
                       name="show_address_to_customers"
                       id="show_address_to_customers"
                       value="1"
                       <?php checked( $show_address, '1' ); ?>>
                <?php _e( 'Mostrar la dirección de la empresa a los clientes', 'lealez' ); ?>
            </label>
        </div>
    </div>

    <?php /* ── Layout de dos columnas: Campos | Mapa (igual a la UI de GMB) ── */ ?>
    <div id="oy-address-map-layout" style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap;">

        <?php /* ── Columna izquierda: campos de dirección ── */ ?>
        <div id="oy-address-fields-col" style="flex:1; min-width:280px;">

            <div id="oy-address-fields-wrap" <?php echo $address_hidden ? 'style="display:none;"' : ''; ?>>
            <table class="form-table" style="margin-top:0;">
                <tr>
                    <th scope="row" style="width:160px;">
                        <label for="location_address_line1"><?php _e( 'Dirección Principal', 'lealez' ); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               name="location_address_line1"
                               id="location_address_line1"
                               value="<?php echo esc_attr( $address_line1 ); ?>"
                               class="large-text"
                               placeholder="<?php esc_attr_e( 'Ej: Calle 10 # 25-30', 'lealez' ); ?>">
                        <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.addressLines[0]</code>', 'lealez' ); ?></p>
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
                        <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.subPremise</code> o <code>addressLines[1]</code>', 'lealez' ); ?></p>
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
                        <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.sublocality</code> (si disponible). ⚙️ También editable manualmente.', 'lealez' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="location_city"><?php _e( 'Ciudad', 'lealez' ); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               name="location_city"
                               id="location_city"
                               value="<?php echo esc_attr( $city ); ?>"
                               class="regular-text">
                        <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.locality</code>', 'lealez' ); ?></p>
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
                        <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.administrativeArea</code>', 'lealez' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="location_country"><?php _e( 'País (ISO 2)', 'lealez' ); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               name="location_country"
                               id="location_country"
                               value="<?php echo esc_attr( $country ); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'CO, MX, US', 'lealez' ); ?>"
                               maxlength="2">
                        <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.regionCode</code> (ISO 3166-1 alpha-2).', 'lealez' ); ?></p>
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
                        <p class="description"><?php _e( 'Importado desde GMB: <code>storefrontAddress.postalCode</code>', 'lealez' ); ?></p>
                    </td>
                </tr>
                <?php if ( $formatted_address ) : ?>
                <tr>
                    <th scope="row">
                        <label><?php _e( 'Dirección Formateada', 'lealez' ); ?></label>
                    </th>
                    <td>
                        <input type="text" readonly class="large-text" value="<?php echo esc_attr( $formatted_address ); ?>">
                        <p class="description"><?php _e( 'Auto-generada al importar desde GMB.', 'lealez' ); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            </div><!-- #oy-address-fields-wrap -->

            <?php /* ── Coordenadas GPS: siempre visibles ── */ ?>
            <table class="form-table" id="oy-coords-map-wrap" style="margin-top:0;">
                <tr>
                    <th scope="row" style="width:160px;">
                        <label><?php _e( 'Coordenadas GPS', 'lealez' ); ?></label>
                    </th>
                    <td>
                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
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
                        <p class="description"><?php _e( 'Importado desde GMB: <code>latlng.latitude</code> / <code>latlng.longitude</code>', 'lealez' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="location_map_url"><?php _e( 'URL en Google Maps', 'lealez' ); ?></label>
                    </th>
                    <td>
                        <input type="url"
                               name="location_map_url"
                               id="location_map_url"
                               value="<?php echo esc_attr( $map_url ); ?>"
                               class="large-text">
                        <p class="description">
                            <?php _e( 'Auto-importado desde GMB: <code>metadata.mapsUri</code>. Se llena automáticamente al sincronizar con Google My Business.', 'lealez' ); ?>
                            <?php if ( $map_url ) : ?>
                                &nbsp;<a href="<?php echo esc_url( $map_url ); ?>" target="_blank" id="oy-maps-open-link"><?php _e( 'Ver en Maps ↗', 'lealez' ); ?></a>
                            <?php else : ?>
                                &nbsp;<a href="#" target="_blank" id="oy-maps-open-link" style="<?php echo $has_coords ? '' : 'display:none;'; ?>"><?php _e( 'Ver en Maps ↗', 'lealez' ); ?></a>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>

        </div><!-- #oy-address-fields-col -->

        <?php /* ── Columna derecha: mapa embebido al estilo GMB ── */ ?>
        <div id="oy-map-preview-col" style="flex:0 0 380px; min-width:280px;">
            <div id="oy-map-preview-wrap" style="
                border:1px solid #c3d4e6;
                border-radius:4px;
                overflow:hidden;
                background:#e8eaf0;
                position:relative;
                height:320px;
                display:<?php echo $has_coords ? 'block' : 'flex'; ?>;
                align-items:center;
                justify-content:center;
            ">
                <?php if ( $has_coords ) : ?>
                    <iframe
                        id="oy-map-iframe"
                        src="<?php echo esc_url( $embed_url ); ?>"
                        width="100%"
                        height="320"
                        style="border:0; display:block;"
                        allowfullscreen=""
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                    ></iframe>
                <?php else : ?>
                    <div id="oy-map-placeholder" style="text-align:center; color:#757575; padding:20px;">
                        <span style="font-size:40px; display:block; margin-bottom:10px;">🗺️</span>
                        <p style="margin:0; font-size:13px;"><?php _e( 'El mapa aparecerá cuando se ingresen las coordenadas GPS o se sincronice con GMB.', 'lealez' ); ?></p>
                    </div>
                    <iframe
                        id="oy-map-iframe"
                        src=""
                        width="100%"
                        height="320"
                        style="border:0; display:none;"
                        allowfullscreen=""
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                    ></iframe>
                <?php endif; ?>

                <?php /* Botón "Ajustar" al estilo GMB — abre Google Maps en nueva pestaña */ ?>
                <?php if ( $map_link_url ) : ?>
                <a href="<?php echo esc_url( $map_link_url ); ?>"
                   id="oy-map-adjust-btn"
                   target="_blank"
                   style="
                    position:absolute;
                    top:10px;
                    right:10px;
                    background:#fff;
                    border:1px solid #dadce0;
                    border-radius:4px;
                    padding:6px 14px;
                    font-size:13px;
                    font-weight:500;
                    color:#1a73e8;
                    text-decoration:none;
                    box-shadow:0 1px 3px rgba(0,0,0,.2);
                    z-index:10;
                    cursor:pointer;
                    line-height:1.4;
                   "><?php _e( 'Ajustar', 'lealez' ); ?></a>
                <?php else : ?>
                <a href="#"
                   id="oy-map-adjust-btn"
                   target="_blank"
                   style="
                    position:absolute;
                    top:10px;
                    right:10px;
                    background:#fff;
                    border:1px solid #dadce0;
                    border-radius:4px;
                    padding:6px 14px;
                    font-size:13px;
                    font-weight:500;
                    color:#1a73e8;
                    text-decoration:none;
                    box-shadow:0 1px 3px rgba(0,0,0,.2);
                    z-index:10;
                    cursor:pointer;
                    line-height:1.4;
                    display:<?php echo $has_coords ? 'block' : 'none'; ?>;
                   "><?php _e( 'Ajustar', 'lealez' ); ?></a>
                <?php endif; ?>
            </div>
            <p class="description" style="margin-top:6px; font-size:11px; color:#757575;">
                <?php _e( 'Vista previa del mapa. Se actualiza al cambiar las coordenadas GPS.', 'lealez' ); ?>
            </p>
        </div><!-- #oy-map-preview-col -->

    </div><!-- #oy-address-map-layout -->

    <script type="text/javascript">
    /**
     * oy_toggle_address_fields
     * Controla visibilidad del bloque de dirección y del row "mostrar dirección".
     * Se declara en window para que applyLocationToForm (GMB metabox) pueda llamarla.
     * NOTA: Coordenadas GPS y URL en Google Maps se muestran SIEMPRE (#oy-coords-map-wrap).
     */
    window.oy_toggle_address_fields = function() {
        var $ = jQuery;
        var isServiceAreaOnly  = $('#service_area_only').is(':checked');
        var showAddressChecked = $('#show_address_to_customers').is(':checked');

        // Mostrar/ocultar el row "mostrar dirección" solo cuando service_area_only está activo
        if ( isServiceAreaOnly ) {
            $('#oy-show-address-row').css('display', 'flex');
        } else {
            $('#oy-show-address-row').css('display', 'none');
        }

        // Ocultar campos de dirección si: solo servicio Y no mostrar dirección
        // Coordenadas y Maps URL (#oy-coords-map-wrap) quedan siempre visibles
        if ( isServiceAreaOnly && ! showAddressChecked ) {
            $('#oy-address-fields-wrap').hide();
        } else {
            $('#oy-address-fields-wrap').show();
        }
    };

    /**
     * oy_update_map_preview
     * Actualiza el mapa embebido cuando cambian las coordenadas GPS.
     * También actualiza el botón "Ajustar" y el enlace "Ver en Maps".
     */
    window.oy_update_map_preview = function() {
        var $ = jQuery;
        var lat = $.trim( $('#location_latitude').val() );
        var lng = $.trim( $('#location_longitude').val() );

        if ( ! lat || ! lng || isNaN( parseFloat(lat) ) || isNaN( parseFloat(lng) ) ) {
            // Sin coordenadas válidas: mostrar placeholder, ocultar iframe y botones
            $('#oy-map-iframe').hide().attr('src', '');
            $('#oy-map-placeholder').show();
            $('#oy-map-preview-wrap').css({ 'display': 'flex' });
            $('#oy-map-adjust-btn').hide();
            $('#oy-maps-open-link').hide();
            return;
        }

        var embedUrl   = 'https://maps.google.com/maps?q=' + encodeURIComponent(lat) + ',' + encodeURIComponent(lng) + '&z=17&output=embed';
        var mapsUrl    = 'https://maps.google.com/maps?q=' + encodeURIComponent(lat) + ',' + encodeURIComponent(lng);

        // Actualizar iframe
        $('#oy-map-placeholder').hide();
        $('#oy-map-iframe').attr('src', embedUrl).css('display', 'block');
        $('#oy-map-preview-wrap').css({ 'display': 'block' });

        // Actualizar botón Ajustar
        var $adjustBtn = $('#oy-map-adjust-btn');
        // Preferir la URL de Maps ya guardada si existe, sino usar coords
        var savedMapUrl = $.trim( $('#location_map_url').val() );
        $adjustBtn.attr('href', savedMapUrl || mapsUrl).show();

        // Actualizar enlace "Ver en Maps"
        var $openLink = $('#oy-maps-open-link');
        $openLink.attr('href', savedMapUrl || mapsUrl).show();
    };

    jQuery(document).ready(function($){
        // Toggle de dirección
        $('#service_area_only').on('change', window.oy_toggle_address_fields);
        $('#show_address_to_customers').on('change', window.oy_toggle_address_fields);

        // Actualizar mapa al cambiar coordenadas (con debounce de 600ms para no recargar en cada tecla)
        var oy_map_debounce_timer;
        $('#location_latitude, #location_longitude').on('input change', function() {
            clearTimeout(oy_map_debounce_timer);
            oy_map_debounce_timer = setTimeout(function() {
                window.oy_update_map_preview();
            }, 600);
        });

        // Actualizar botón Ajustar cuando cambia la URL de Maps manualmente
        $('#location_map_url').on('input change', function() {
            var mapUrl = $.trim( $(this).val() );
            if ( mapUrl ) {
                $('#oy-map-adjust-btn').attr('href', mapUrl).show();
                $('#oy-maps-open-link').attr('href', mapUrl).show();
            }
        });

// Ejecutar al cargar la página
        window.oy_toggle_address_fields();

        // Inicializar el mapa al cargar: si hay coordenadas ya guardadas, renderizar iframe.
        // Se llama a oy_update_map_preview() para unificar la lógica de iframe, botones y enlaces.
        // Esto también cubre el caso en que el iframe ya venía de PHP pero los botones necesitan
        // sincronizar sus href con la URL de Maps guardada.
        (function() {
            var lat = $.trim( $('#location_latitude').val() );
            var lng = $.trim( $('#location_longitude').val() );
            if ( lat && lng && !isNaN(parseFloat(lat)) && !isNaN(parseFloat(lng)) ) {
                // Si el iframe ya tiene src (renderizado por PHP), solo sincronizar botones/links.
                var iframeSrc = $('#oy-map-iframe').attr('src');
                var savedMapUrl = $.trim( $('#location_map_url').val() );
                var mapsUrl = savedMapUrl || 'https://maps.google.com/maps?q=' + encodeURIComponent(lat) + ',' + encodeURIComponent(lng);

                if ( iframeSrc && iframeSrc.length > 5 ) {
                    // iframe ya renderizado por PHP, solo sincronizar botones
                    $('#oy-map-adjust-btn').attr('href', mapsUrl).show();
                    $('#oy-maps-open-link').attr('href', mapsUrl).show();
                    $('#oy-map-placeholder').hide();
                    $('#oy-map-iframe').css('display', 'block');
                    $('#oy-map-preview-wrap').css('display', 'block');
                } else {
                    // Sin iframe de PHP (coords no disponibles al cargar en PHP), generar ahora.
                    window.oy_update_map_preview();
                }
            }
        })();
    });
    </script>
    <?php
}

    /**
     * Render Contact Information meta box
     */
    public function render_contact_meta_box( $post ) {
        $phone               = get_post_meta( $post->ID, 'location_phone', true );
        $phone_additional_list = get_post_meta( $post->ID, 'gmb_phone_additional_list', true );
        $chat_url            = get_post_meta( $post->ID, 'location_chat_url', true );
        // Backward compat: if no chat_url set but old whatsapp field exists
        if ( empty( $chat_url ) ) {
            $chat_url = get_post_meta( $post->ID, 'location_whatsapp', true );
        }
        $email               = get_post_meta( $post->ID, 'location_email', true );
        $website             = get_post_meta( $post->ID, 'location_website', true );
        // NOTA: location_menu_url se gestiona en el metabox "Menú del Negocio" (class-oy-location-menu-metabox.php)

        // ── Arrays dinámicos de URLs de Reservas y Ordenar Online ──────────────────────
        // Estructura de cada entrada: ['url' => '', 'label' => '', 'type' => '', 'from_gmb' => 0]
        $booking_urls = get_post_meta( $post->ID, 'location_booking_urls', true );
        if ( ! is_array( $booking_urls ) || empty( $booking_urls ) ) {
            // Migración desde campo legacy location_booking_url
            $legacy_booking = get_post_meta( $post->ID, 'location_booking_url', true );
            $booking_urls   = $legacy_booking
                ? array( array( 'url' => $legacy_booking, 'label' => __( 'Reservas', 'lealez' ), 'type' => 'APPOINTMENT', 'from_gmb' => 0 ) )
                : array();
        }

        $order_urls = get_post_meta( $post->ID, 'location_order_urls', true );
        if ( ! is_array( $order_urls ) || empty( $order_urls ) ) {
            // Migración desde campo legacy location_order_url
            $legacy_order = get_post_meta( $post->ID, 'location_order_url', true );
            $order_urls   = $legacy_order
                ? array( array( 'url' => $legacy_order, 'label' => __( 'Ordenar en línea', 'lealez' ), 'type' => 'FOOD_ORDERING', 'from_gmb' => 0 ) )
                : array();
        }

        // Etiquetas legibles para cada placeActionType
        $action_type_labels = array(
            'APPOINTMENT'        => __( 'Reservas', 'lealez' ),
            'ONLINE_APPOINTMENT' => __( 'Cita online', 'lealez' ),
            'DINING_RESERVATION' => __( 'Reserva de mesa', 'lealez' ),
            'FOOD_ORDERING'      => __( 'Ordenar en línea', 'lealez' ),
            'FOOD_DELIVERY'      => __( 'Domicilio', 'lealez' ),
            'FOOD_TAKEOUT'       => __( 'Para llevar', 'lealez' ),
            'SHOP_ONLINE'        => __( 'Tienda online', 'lealez' ),
            'ORDER_AHEAD'        => __( 'Ordenar anticipado', 'lealez' ),
            'ORDER_FOOD'         => __( 'Pedir comida', 'lealez' ),
        );

        // Social profiles: from GMB attributes (auto) + manual overrides
        $gmb_social_profiles = get_post_meta( $post->ID, 'gmb_social_profiles_raw', true );
        $social_profiles_manual = get_post_meta( $post->ID, 'social_profiles_manual', true );

        if ( ! is_array( $phone_additional_list ) ) {
            $phone_additional_list = array();
        }
        if ( ! is_array( $gmb_social_profiles ) ) {
            $gmb_social_profiles = array();
        }
        if ( ! is_array( $social_profiles_manual ) ) {
            $social_profiles_manual = array();
        }

        // Social network labels
        $social_network_labels = array(
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'twitter'   => 'Twitter / X',
            'linkedin'  => 'LinkedIn',
            'youtube'   => 'YouTube',
            'tiktok'    => 'TikTok',
            'pinterest' => 'Pinterest',
        );
        ?>
        <h4 style="margin-top:0;"><?php _e( '📞 Teléfonos', 'lealez' ); ?></h4>
        <p class="description" style="margin-bottom:10px;"><?php _e( 'Importado desde GMB: <code>phoneNumbers</code>. Puedes agregar o quitar teléfonos adicionales.', 'lealez' ); ?></p>

        <table class="form-table" style="margin-bottom:0;">
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
                    <p class="description"><?php _e( 'GMB: <code>phoneNumbers.primaryPhone</code>. Formato E.164 recomendado.', 'lealez' ); ?></p>
                </td>
            </tr>
        </table>

        <?php /* Teléfonos adicionales dinámicos */ ?>
        <div style="margin: 8px 0 16px 160px;" id="oy-additional-phones-wrap">
            <p style="font-weight:600; margin:0 0 6px; font-size:13px;"><?php _e( 'Teléfonos Adicionales', 'lealez' ); ?> <span style="font-weight:400; color:#777; font-size:12px;"><?php _e( '(GMB: <code>phoneNumbers.additionalPhones</code>)', 'lealez' ); ?></span></p>
            <div id="oy-additional-phones-list">
                <?php if ( ! empty( $phone_additional_list ) ) :
                    foreach ( $phone_additional_list as $idx => $extra_phone ) : ?>
                    <div class="oy-phone-row" style="display:flex; gap:6px; margin-bottom:6px; align-items:center;">
                        <input type="tel"
                               name="gmb_phone_additional_list[]"
                               value="<?php echo esc_attr( $extra_phone ); ?>"
                               class="regular-text"
                               placeholder="+573001234567">
                        <button type="button" class="button button-small oy-remove-phone" style="color:#dc3232;">✕</button>
                    </div>
                    <?php endforeach;
                endif; ?>
            </div>
            <button type="button" id="oy-add-phone" class="button button-small">+ <?php _e( 'Agregar teléfono', 'lealez' ); ?></button>
        </div>

        <hr style="margin:0 0 16px;">

        <h4><?php _e( '💬 Mensajería', 'lealez' ); ?></h4>
        <table class="form-table" style="margin-bottom:0;">
            <tr>
                <th scope="row">
                    <label for="location_chat_url"><?php _e( 'Usuario de chat', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="url"
                           name="location_chat_url"
                           id="location_chat_url"
                           value="<?php echo esc_attr( $chat_url ); ?>"
                           class="large-text"
                           placeholder="https://wa.me/573001234567">
<p class=\"description\"><?php _e( 'Permite que los clientes chateen con tu empresa vía WhatsApp o SMS. 🔄 Se importa automáticamente desde GMB (<code>url_whatsapp</code> / <code>url_text_messaging</code>) — o puedes ingresarlo manualmente.', 'lealez' ); ?></p>
        <hr style="margin:16px 0;">

        <h4><?php _e( '📧 Contacto Web', 'lealez' ); ?></h4>
        <?php
        // ── Alerta de error de Place Actions API ─────────────────────────────────────────
        $pa_api_error = get_post_meta( $post->ID, 'gmb_place_actions_api_error', true );
        if ( ! empty( $pa_api_error ) && is_array( $pa_api_error ) ) :
            $pa_err_code    = ! empty( $pa_api_error['code'] ) ? ' [' . esc_html( $pa_api_error['code'] ) . ']' : '';
            $pa_err_msg     = ! empty( $pa_api_error['message'] ) ? esc_html( $pa_api_error['message'] ) : __( 'Error desconocido', 'lealez' );
            $pa_err_time    = ! empty( $pa_api_error['timestamp'] ) ? ' — ' . esc_html( $pa_api_error['timestamp'] ) : '';
            ?>
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:10px 14px;margin-bottom:12px;font-size:13px;line-height:1.5;">
                <strong>⚠️ <?php _e( 'Place Actions API — Error al sincronizar URLs de Reserva / Menú / Ordenar Online', 'lealez' ); ?></strong><?php echo $pa_err_time; ?><br>
                <em><?php echo $pa_err_code . ' ' . $pa_err_msg; ?></em><br><br>
                <strong><?php _e( 'Solución:', 'lealez' ); ?></strong>
                <ol style="margin:6px 0 0 18px;padding:0;">
                    <li><?php _e( 'Ve a <a href="https://console.cloud.google.com/apis/library/mybusinessplaceactions.googleapis.com" target="_blank" rel="noopener">Google Cloud Console → APIs → My Business Place Actions API</a> y habilítala.', 'lealez' ); ?></li>
                    <li><?php _e( 'Desconecta y vuelve a conectar tu cuenta Google My Business para renovar el token OAuth.', 'lealez' ); ?></li>
                    <li><?php _e( 'Guarda o re-importa esta ubicación para que se intente de nuevo.', 'lealez' ); ?></li>
                </ol>
                <p style="margin:8px 0 0;color:#666;"><?php _e( 'Los campos "URL de Reservas", "URL del Menú" y "URL para Ordenar Online" deben completarse manualmente hasta que el error se resuelva.', 'lealez' ); ?></p>
            </div>
        <?php endif; ?>
        <table class="form-table" style="margin-bottom:0;">
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
                    <p class="description"><?php _e( '⚙️ Solo manual — Google My Business no tiene campo de email.', 'lealez' ); ?></p>
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
                    <p class="description"><?php _e( 'Importado desde GMB: <code>websiteUri</code>', 'lealez' ); ?></p>
                </td>
            </tr>
        </table>

        <?php /* ── URLs de Reservas (dinámica, múltiple) ── */ ?>
        <div style="margin:12px 0 16px 0;" id="oy-booking-urls-wrap">
            <p style="font-weight:600; margin:0 0 4px; font-size:13px;">
                <?php _e( 'URLs de Reservas', 'lealez' ); ?>
                <span style="font-weight:400; color:#777; font-size:12px;">
                    <?php _e( '(GMB: Place Actions API — <code>APPOINTMENT</code> / <code>ONLINE_APPOINTMENT</code> / <code>DINING_RESERVATION</code>)', 'lealez' ); ?>
                </span>
            </p>
            <div id="oy-booking-urls-list">
                <?php foreach ( $booking_urls as $idx => $entry ) :
                    $burl      = ! empty( $entry['url'] )      ? $entry['url']      : '';
                    $blabel    = ! empty( $entry['label'] )     ? $entry['label']    : '';
                    $btype     = ! empty( $entry['type'] )      ? $entry['type']     : '';
                    $bfromgmb  = ! empty( $entry['from_gmb'] )  ? 1 : 0;
                ?>
                <div class="oy-booking-url-row" style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">
                    <input type="url"
                           name="location_booking_urls[<?php echo $idx; ?>][url]"
                           value="<?php echo esc_attr( $burl ); ?>"
                           class="large-text"
                           placeholder="https://..."
                           style="flex:1;min-width:250px;">
                    <input type="text"
                           name="location_booking_urls[<?php echo $idx; ?>][label]"
                           value="<?php echo esc_attr( $blabel ); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'Etiqueta (ej: Reservas)', 'lealez' ); ?>"
                           style="max-width:180px;">
                    <input type="hidden" name="location_booking_urls[<?php echo $idx; ?>][type]"     value="<?php echo esc_attr( $btype ); ?>">
                    <input type="hidden" name="location_booking_urls[<?php echo $idx; ?>][from_gmb]" value="<?php echo $bfromgmb; ?>">
                    <?php if ( $bfromgmb ) : ?>
                        <span style="font-size:11px;color:#2271b1;white-space:nowrap;background:#e8f0fe;border:1px solid #b3d4f5;border-radius:3px;padding:2px 6px;">
                            🔄 GMB<?php if ( $btype ) echo ' · ' . esc_html( $btype ); ?>
                        </span>
                    <?php endif; ?>
                    <button type="button" class="button button-small oy-remove-booking-url" style="color:#dc3232;">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="oy-add-booking-url" class="button button-small">
                + <?php _e( 'Agregar URL de reservas', 'lealez' ); ?>
            </button>
        </div>

        <?php /* ── URLs Ordenar Online (dinámica, múltiple) ── */ ?>
        <div style="margin:12px 0 16px 0;" id="oy-order-urls-wrap">
            <p style="font-weight:600; margin:0 0 4px; font-size:13px;">
                <?php _e( 'URLs para Ordenar Online', 'lealez' ); ?>
                <span style="font-weight:400; color:#777; font-size:12px;">
                    <?php _e( '(GMB: Place Actions API — <code>FOOD_ORDERING</code> / <code>FOOD_DELIVERY</code> / <code>FOOD_TAKEOUT</code> / <code>SHOP_ONLINE</code> / etc.)', 'lealez' ); ?>
                </span>
            </p>
            <div id="oy-order-urls-list">
                <?php foreach ( $order_urls as $idx => $entry ) :
                    $ourl     = ! empty( $entry['url'] )      ? $entry['url']      : '';
                    $olabel   = ! empty( $entry['label'] )     ? $entry['label']    : '';
                    $otype    = ! empty( $entry['type'] )      ? $entry['type']     : '';
                    $ofromgmb = ! empty( $entry['from_gmb'] )  ? 1 : 0;
                ?>
                <div class="oy-order-url-row" style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">
                    <input type="url"
                           name="location_order_urls[<?php echo $idx; ?>][url]"
                           value="<?php echo esc_attr( $ourl ); ?>"
                           class="large-text"
                           placeholder="https://..."
                           style="flex:1;min-width:250px;">
                    <input type="text"
                           name="location_order_urls[<?php echo $idx; ?>][label]"
                           value="<?php echo esc_attr( $olabel ); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'Etiqueta (ej: Domicilio)', 'lealez' ); ?>"
                           style="max-width:180px;">
                    <input type="hidden" name="location_order_urls[<?php echo $idx; ?>][type]"     value="<?php echo esc_attr( $otype ); ?>">
                    <input type="hidden" name="location_order_urls[<?php echo $idx; ?>][from_gmb]" value="<?php echo $ofromgmb; ?>">
                    <?php if ( $ofromgmb ) : ?>
                        <span style="font-size:11px;color:#2271b1;white-space:nowrap;background:#e8f0fe;border:1px solid #b3d4f5;border-radius:3px;padding:2px 6px;">
                            🔄 GMB<?php if ( $otype ) echo ' · ' . esc_html( $otype ); ?>
                        </span>
                    <?php endif; ?>
                    <button type="button" class="button button-small oy-remove-order-url" style="color:#dc3232;">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="oy-add-order-url" class="button button-small">
                + <?php _e( 'Agregar URL de pedidos', 'lealez' ); ?>
            </button>
        </div>

        <?php /* ── URL Vínculo del Menú / Servicios (GMB: Place Actions API → MENU) ── */ ?>
        <div style="margin:12px 0 16px 0;" id="oy-menu-link-wrap">
            <p style="font-weight:600; margin:0 0 4px; font-size:13px;">
                <?php _e( 'Vínculo del Menú / Servicios', 'lealez' ); ?>
                <span style="font-weight:400; color:#777; font-size:12px;">
                    <?php _e( '(GMB: Place Actions API — <code>MENU</code>)', 'lealez' ); ?>
                </span>
            </p>
            <p class="description" style="margin:0 0 8px;">
                <?php _e( 'Enlace que Google muestra en tu Perfil de Negocio como "Vínculo del menú o los servicios". Se sincroniza automáticamente desde GMB (Place Actions → MENU) o puedes ingresarlo manualmente aquí.', 'lealez' ); ?>
            </p>
            <?php
            $current_menu_url = (string) get_post_meta( $post->ID, 'location_menu_url', true );
            $menu_url_from_gmb = (bool) get_post_meta( $post->ID, 'location_menu_url_from_gmb', true );
            ?>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="url"
                       name="location_menu_url_gmb"
                       id="location_menu_url_gmb"
                       value="<?php echo esc_attr( $current_menu_url ); ?>"
                       class="large-text"
                       placeholder="https://tu-restaurante.com/menu"
                       style="flex:1;min-width:280px;">
                <?php if ( $menu_url_from_gmb && $current_menu_url ) : ?>
                    <span style="font-size:11px;color:#2271b1;white-space:nowrap;background:#e8f0fe;border:1px solid #b3d4f5;border-radius:3px;padding:2px 6px;">
                        🔄 GMB · MENU
                    </span>
                <?php endif; ?>
            </div>
            <?php if ( $current_menu_url ) : ?>
                <p style="margin:4px 0 0;font-size:12px;">
                    <a href="<?php echo esc_url( $current_menu_url ); ?>" target="_blank" rel="noopener">
                        <?php echo esc_html( $current_menu_url ); ?> ↗
                    </a>
                </p>
            <?php endif; ?>
        </div>

        <hr style="margin:16px 0;">
        <p class="description" style="margin-bottom:10px;">
            <?php _e( '🔄 Se sincronizan automáticamente desde los atributos de Google My Business (<code>url_facebook</code>, <code>url_instagram</code>, etc.). Puedes editar o agregar perfiles adicionales manualmente.', 'lealez' ); ?>
        </p>

        <?php if ( ! empty( $gmb_social_profiles ) ) : ?>
        <div style="background:#f0f6fc; border:1px solid #b3d4f5; border-radius:4px; padding:10px 14px; margin-bottom:12px;">
            <strong style="font-size:12px; color:#2271b1; display:block; margin-bottom:8px;">
                🔄 <?php _e( 'Sincronizados desde Google My Business:', 'lealez' ); ?>
            </strong>
            <?php foreach ( $gmb_social_profiles as $network => $url ) :
                $network_label = isset( $social_network_labels[ $network ] ) ? $social_network_labels[ $network ] : ucfirst( $network );
                // Detectar ícono por red
                $icons = array(
                    'facebook'  => '📘',
                    'instagram' => '📸',
                    'twitter'   => '🐦',
                    'linkedin'  => '💼',
                    'youtube'   => '▶️',
                    'tiktok'    => '🎵',
                    'pinterest' => '📌',
                );
                $icon = isset( $icons[ $network ] ) ? $icons[ $network ] . ' ' : '';
                ?>
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <span style="min-width:100px; font-weight:600; font-size:12px;"><?php echo esc_html( $icon . $network_label ); ?></span>
                    <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" style="font-size:12px; color:#2271b1; word-break:break-all;"><?php echo esc_html( $url ); ?></a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else : ?>
        <div style="background:#fffbe5; border:1px solid #f0c000; border-radius:4px; padding:8px 14px; margin-bottom:12px; font-size:12px; color:#7a5c00;">
            <?php _e( '⚠️ No se han sincronizado redes sociales desde GMB. Asegúrate de que la ubicación esté sincronizada y que hayas configurado las redes sociales en tu Perfil de Negocio de Google.', 'lealez' ); ?>
        </div>
        <?php endif; ?>

        <div id="oy-social-profiles-list">
            <?php
            // Construir lista editable: GMB como base, sobreescrita por entradas manuales
            // Las entradas manuales permiten añadir redes que no vienen de GMB o corregir URLs
            $all_social = array_merge( $gmb_social_profiles, $social_profiles_manual );
            if ( ! empty( $all_social ) ) :
                foreach ( $all_social as $network => $url ) :
                    if ( empty( $url ) ) continue; // Saltar entradas vacías
                    $is_from_gmb = isset( $gmb_social_profiles[ $network ] ) && ! isset( $social_profiles_manual[ $network ] );
                    ?>
                    <div class="oy-social-row" style="display:flex; gap:6px; margin-bottom:8px; align-items:center; flex-wrap:wrap;">
                        <select name="social_profiles_manual_network[]" class="oy-social-network-select" style="min-width:130px;">
                            <?php foreach ( $social_network_labels as $val => $lbl ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $network, $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                            <?php endforeach; ?>
                            <option value="other" <?php selected( ! isset( $social_network_labels[ $network ] ), true ); ?>><?php _e( 'Otra', 'lealez' ); ?></option>
                        </select>
                        <input type="url"
                               name="social_profiles_manual_url[]"
                               value="<?php echo esc_attr( $url ); ?>"
                               class="large-text"
                               placeholder="https://..."
                               <?php echo $is_from_gmb ? 'data-from-gmb="1"' : ''; ?>>
                        <?php if ( $is_from_gmb ) : ?>
                            <span style="font-size:11px; color:#2271b1; white-space:nowrap;">🔄 GMB</span>
                        <?php endif; ?>
                        <button type="button" class="button button-small oy-remove-social" style="color:#dc3232;">✕</button>
                    </div>
                <?php endforeach;
            endif; ?>
        </div>
        <button type="button" id="oy-add-social" class="button button-small">+ <?php _e( 'Agregar red social', 'lealez' ); ?></button>

        <script type="text/javascript">
        jQuery(document).ready(function($){

            // ── Teléfonos adicionales ──
            $('#oy-add-phone').on('click', function(){
                var row = '<div class="oy-phone-row" style="display:flex;gap:6px;margin-bottom:6px;align-items:center;">' +
                    '<input type="tel" name="gmb_phone_additional_list[]" class="regular-text" placeholder="+573001234567">' +
                    '<button type="button" class="button button-small oy-remove-phone" style="color:#dc3232;">✕</button>' +
                    '</div>';
                $('#oy-additional-phones-list').append(row);
            });
            $(document).on('click', '.oy-remove-phone', function(){
                $(this).closest('.oy-phone-row').remove();
            });

            // ── URLs de Reservas (múltiple) ──────────────────────────────────────────
            function oyBookingUrlNextIdx() {
                var max = -1;
                $('#oy-booking-urls-list .oy-booking-url-row').each(function(){
                    $(this).find('input[name^="location_booking_urls["]').each(function(){
                        var m = this.name.match(/location_booking_urls\[(\d+)\]/);
                        if (m) { max = Math.max(max, parseInt(m[1], 10)); }
                    });
                });
                return max + 1;
            }
            $('#oy-add-booking-url').on('click', function(){
                var idx = oyBookingUrlNextIdx();
                var row = '<div class="oy-booking-url-row" style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">' +
                    '<input type="url" name="location_booking_urls[' + idx + '][url]" class="large-text" placeholder="https://..." style="flex:1;min-width:250px;">' +
                    '<input type="text" name="location_booking_urls[' + idx + '][label]" class="regular-text" placeholder="<?php echo esc_js( __( 'Etiqueta (ej: Reservas)', 'lealez' ) ); ?>" style="max-width:180px;">' +
                    '<input type="hidden" name="location_booking_urls[' + idx + '][type]" value="">' +
                    '<input type="hidden" name="location_booking_urls[' + idx + '][from_gmb]" value="0">' +
                    '<button type="button" class="button button-small oy-remove-booking-url" style="color:#dc3232;">✕</button>' +
                    '</div>';
                $('#oy-booking-urls-list').append(row);
            });
            $(document).on('click', '.oy-remove-booking-url', function(){
                $(this).closest('.oy-booking-url-row').remove();
            });

            // ── URLs para Ordenar Online (múltiple) ──────────────────────────────────
            function oyOrderUrlNextIdx() {
                var max = -1;
                $('#oy-order-urls-list .oy-order-url-row').each(function(){
                    $(this).find('input[name^="location_order_urls["]').each(function(){
                        var m = this.name.match(/location_order_urls\[(\d+)\]/);
                        if (m) { max = Math.max(max, parseInt(m[1], 10)); }
                    });
                });
                return max + 1;
            }
            $('#oy-add-order-url').on('click', function(){
                var idx = oyOrderUrlNextIdx();
                var row = '<div class="oy-order-url-row" style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">' +
                    '<input type="url" name="location_order_urls[' + idx + '][url]" class="large-text" placeholder="https://..." style="flex:1;min-width:250px;">' +
                    '<input type="text" name="location_order_urls[' + idx + '][label]" class="regular-text" placeholder="<?php echo esc_js( __( 'Etiqueta (ej: Domicilio)', 'lealez' ) ); ?>" style="max-width:180px;">' +
                    '<input type="hidden" name="location_order_urls[' + idx + '][type]" value="">' +
                    '<input type="hidden" name="location_order_urls[' + idx + '][from_gmb]" value="0">' +
                    '<button type="button" class="button button-small oy-remove-order-url" style="color:#dc3232;">✕</button>' +
                    '</div>';
                $('#oy-order-urls-list').append(row);
            });
            $(document).on('click', '.oy-remove-order-url', function(){
                $(this).closest('.oy-order-url-row').remove();
            });

            // ── Redes sociales ──
            var networkOptions = '<?php
                $opts = '';
                foreach ( $social_network_labels as $val => $lbl ) {
                    $opts .= '<option value="' . esc_attr( $val ) . '">' . esc_html( $lbl ) . '</option>';
                }
                $opts .= '<option value="other">' . esc_html__( 'Otra', 'lealez' ) . '</option>';
                echo esc_js( $opts );
            ?>';

            $('#oy-add-social').on('click', function(){
                var row = '<div class="oy-social-row" style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">' +
                    '<select name="social_profiles_manual_network[]" class="oy-social-network-select" style="min-width:130px;">' + networkOptions + '</select>' +
                    '<input type="url" name="social_profiles_manual_url[]" class="large-text" placeholder="https://...">' +
                    '<button type="button" class="button button-small oy-remove-social" style="color:#dc3232;">✕</button>' +
                    '</div>';
                $('#oy-social-profiles-list').append(row);
            });
            $(document).on('click', '.oy-remove-social', function(){
                $(this).closest('.oy-social-row').remove();
            });
        });
        </script>
        <?php
    }

    /**
     * Render Business Hours meta box
     *
     * UI alineada con Google My Business:
     * - Radio para estado del negocio (abierto con horarios, abierto sin horarios, cerrado temp., cerrado perm.)
     * - Checkbox "Cerrada" por día
     * - Select de horario con incrementos de 30 min + opción "24 horas"
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

        $timezone     = get_post_meta( $post->ID, 'location_hours_timezone', true );
        $hours_status = get_post_meta( $post->ID, 'location_hours_status', true );

        if ( empty( $timezone ) )     { $timezone     = 'America/Bogota'; }
        if ( empty( $hours_status ) ) { $hours_status = 'open_with_hours'; }

// ── Genera opciones de horario: "24 horas" + intervalos de 15 min ──────
// Google Business Profile permite horarios en intervalos de 15 min.
$time_options = array();
$time_options['24_hours'] = __( '24 horas', 'lealez' );
for ( $h = 0; $h < 24; $h++ ) {
    foreach ( array( 0, 15, 30, 45 ) as $m ) {
        $hh     = sprintf( '%02d', $h );
        $mm     = sprintf( '%02d', $m );
        $period = $h < 12 ? 'a.m.' : 'p.m.';
        $h12    = $h % 12;
        if ( $h12 === 0 ) { $h12 = 12; }
        $time_options[ $hh . ':' . $mm ] = sprintf( '%d:%s %s', $h12, $mm, $period );
    }
}

        // ── Helper: render un <select> de hora ────────────────────────────────
        $render_select = function( $name, $selected_val, $include_all_day, $disabled = false, $extra_class = '' ) use ( $time_options ) {
            $out = '<select name="' . esc_attr( $name ) . '" class="oy-hours-sel' . ( $extra_class ? ' ' . esc_attr( $extra_class ) : '' ) . '" style="min-width:130px;"' . ( $disabled ? ' disabled' : '' ) . '>';
            foreach ( $time_options as $tval => $tlabel ) {
                if ( ! $include_all_day && $tval === '24_hours' ) { continue; }
                $out .= '<option value="' . esc_attr( $tval ) . '"' . selected( $selected_val, $tval, false ) . '>' . esc_html( $tlabel ) . '</option>';
            }
            $out .= '</select>';
            return $out;
        };
        ?>

        <?php /* ── ZONA HORARIA ── */ ?>
        <table class="form-table" style="margin-bottom:0;">
            <tr>
                <th scope="row" style="width:160px;">
                    <label for="location_hours_timezone"><?php _e( 'Zona Horaria', 'lealez' ); ?></label>
                </th>
                <td>
                    <select name="location_hours_timezone" id="location_hours_timezone" class="regular-text">
                        <?php foreach ( timezone_identifiers_list() as $tz ) {
                            printf( '<option value="%s" %s>%s</option>', esc_attr( $tz ), selected( $timezone, $tz, false ), esc_html( $tz ) );
                        } ?>
                    </select>
                    <p class="description">⚙️ <?php _e( 'Solo manual — Google no retorna timezone en Business Information API.', 'lealez' ); ?></p>
                </td>
            </tr>
        </table>

        <hr style="margin:12px 0;">

        <?php /* ── ESTADO DEL HORARIO ── */ ?>
        <div id="oy-hours-status-wrap" style="margin-bottom:16px;">
            <h4 style="margin:0 0 8px;"><?php _e( 'Horario de atención', 'lealez' ); ?></h4>
            <p class="description" style="margin-bottom:10px;"><?php _e( 'Establece el horario de atención principal o marca tu negocio como cerrado.', 'lealez' ); ?></p>
            <?php
            $status_options = array(
                'open_with_hours'    => array( 'label' => __( 'Abierto, con horarios de atención', 'lealez' ),   'desc' => __( 'Mostrar cuándo tu negocio está abierto', 'lealez' ) ),
                'open_without_hours' => array( 'label' => __( 'Abierto, sin horarios de atención', 'lealez' ),   'desc' => __( 'No mostrar ningún horario de atención', 'lealez' ) ),
                'temporarily_closed' => array( 'label' => __( 'Cerrado temporalmente', 'lealez' ),               'desc' => __( 'Indicar si tu empresa o negocio abrirán de nuevo en el futuro', 'lealez' ) ),
                'permanently_closed' => array( 'label' => __( 'Cerrado permanentemente', 'lealez' ),             'desc' => __( 'Mostrar que tu empresa o negocio ya no existen', 'lealez' ) ),
            );
            foreach ( $status_options as $val => $info ) : ?>
                <label style="display:flex; align-items:flex-start; gap:10px; margin-bottom:8px; cursor:pointer;">
                    <input type="radio" name="location_hours_status" value="<?php echo esc_attr( $val ); ?>"
                           <?php checked( $hours_status, $val ); ?> class="oy-hours-status-radio" style="margin-top:3px; flex-shrink:0;">
                    <span>
                        <strong><?php echo esc_html( $info['label'] ); ?></strong><br>
                        <span class="description"><?php echo esc_html( $info['desc'] ); ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <?php /* ── GRILLA DE HORARIOS POR DÍA — multi período ── */ ?>
        <div id="oy-hours-grid-wrap" <?php echo ( $hours_status !== 'open_with_hours' ) ? 'style="display:none;"' : ''; ?>>
            <p class="description" style="margin-bottom:8px;">
                <?php _e( 'Importado desde GMB: <code>regularHours.periods</code>. Puedes editar manualmente también.', 'lealez' ); ?>
            </p>

            <script type="text/javascript">
            var oyHoursTimeOptions = <?php
                $js_opts = array();
                foreach ( $time_options as $tv => $tl ) { $js_opts[] = array( 'value' => $tv, 'label' => $tl ); }
                echo wp_json_encode( $js_opts );
            ?>;
            var oyHoursI18n = {
                addPeriod:    '<?php echo esc_js( __( 'Agregar otro turno', 'lealez' ) ); ?>',
                removePeriod: '<?php echo esc_js( __( 'Eliminar turno', 'lealez' ) ); ?>',
                opensAt:      '<?php echo esc_js( __( 'Abre a la(s)', 'lealez' ) ); ?>',
                closesAt:     '<?php echo esc_js( __( 'Cierra a la(s)', 'lealez' ) ); ?>',
            };
            </script>

            <div id="oy-hours-days-container">
            <?php foreach ( $days as $day_key => $day_label ) :
                $hours = get_post_meta( $post->ID, 'location_hours_' . $day_key, true );

                // Normalizar: si no existe o es v1 (open/close al nivel raíz), migrar a v2 (periods[])
                if ( ! is_array( $hours ) ) {
                    $hours = array( 'closed' => false, 'all_day' => false, 'periods' => array( array( 'open' => '09:00', 'close' => '18:00' ) ) );
                }
                if ( ! isset( $hours['periods'] ) || ! is_array( $hours['periods'] ) || empty( $hours['periods'] ) ) {
                    $old_open  = $hours['open']  ?? '09:00';
                    $old_close = $hours['close'] ?? '18:00';
                    if ( $old_open === '24_hours' ) {
                        $hours['all_day'] = true;
                        $hours['periods'] = array( array( 'open' => '24_hours', 'close' => '' ) );
                    } else {
                        $hours['all_day'] = false;
                        $hours['periods'] = array( array( 'open' => $old_open, 'close' => $old_close ) );
                    }
                }

                $is_closed     = ! empty( $hours['closed'] );
                $is_all_day    = ! empty( $hours['all_day'] );
                $periods       = is_array( $hours['periods'] ) ? $hours['periods'] : array( array( 'open' => '09:00', 'close' => '18:00' ) );
                if ( $is_all_day ) { $periods = array( array( 'open' => '24_hours', 'close' => '' ) ); }
                $periods_total = count( $periods );
                ?>
                <div class="oy-day-section" data-day="<?php echo esc_attr( $day_key ); ?>"
                     style="display:flex; align-items:flex-start; gap:0; margin-bottom:4px; padding:6px 0; border-bottom:1px solid #f0f0f0;">

                    <div style="width:130px; flex-shrink:0; padding-top:8px;">
                        <strong><?php echo esc_html( $day_label ); ?></strong>
                    </div>

                    <div style="width:80px; flex-shrink:0; text-align:center; padding-top:6px;">
                        <input type="checkbox"
                               name="location_hours_<?php echo esc_attr( $day_key ); ?>[closed]"
                               value="1"
                               <?php checked( $is_closed, true ); ?>
                               class="oy-hours-closed-cb"
                               data-day="<?php echo esc_attr( $day_key ); ?>"
                               style="width:16px;height:16px;">
                        <?php if ( $is_closed ) : ?>
                            <br><small style="color:#999;"><?php _e( 'Cerrada', 'lealez' ); ?></small>
                        <?php endif; ?>
                    </div>

                    <div class="oy-day-periods" data-day="<?php echo esc_attr( $day_key ); ?>"
                         style="flex:1; <?php echo $is_closed ? 'opacity:0.5;' : ''; ?>">
                        <?php foreach ( $periods as $pidx => $period ) :
                            $popen   = $period['open']  ?? '09:00';
                            $pclose  = $period['close'] ?? '18:00';
                            $p24h    = ( $popen === '24_hours' );
                            $is_first = ( $pidx === 0 );
                            ?>
                            <div class="oy-period-row" style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                <div>
                                    <?php if ( $is_first ) : ?><div style="font-size:10px;color:#888;margin-bottom:2px;"><?php _e( 'Abre a la(s)', 'lealez' ); ?></div><?php endif; ?>
                                    <?php echo $render_select(
                                        'location_hours_' . $day_key . '[periods][' . $pidx . '][open]',
                                        $popen, true, $is_closed, 'oy-period-open'
                                    ); ?>
                                </div>
                                <div>
                                    <?php if ( $is_first ) : ?><div style="font-size:10px;color:#888;margin-bottom:2px;"><?php _e( 'Cierra a la(s)', 'lealez' ); ?></div><?php endif; ?>
                                    <?php echo $render_select(
                                        'location_hours_' . $day_key . '[periods][' . $pidx . '][close]',
                                        $pclose, false, ( $is_closed || $p24h ), 'oy-period-close'
                                    ); ?>
                                </div>
                                <div style="<?php echo $is_first ? 'margin-top:18px;' : ''; ?>">
                                    <?php if ( ! $is_all_day ) : ?>
                                        <?php if ( $pidx === 0 ) : ?>
                                            <button type="button" class="button oy-add-period" data-day="<?php echo esc_attr( $day_key ); ?>"
                                                    title="<?php esc_attr_e( 'Agregar otro turno', 'lealez' ); ?>" style="padding:2px 8px;min-height:28px;">＋</button>
                                        <?php endif; ?>
                                        <?php if ( $pidx > 0 ) : ?>
                                            <button type="button" class="button oy-remove-period" data-day="<?php echo esc_attr( $day_key ); ?>"
                                                    title="<?php esc_attr_e( 'Eliminar turno', 'lealez' ); ?>" style="padding:2px 8px;min-height:28px;color:#dc3232;">🗑</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div><!-- #oy-hours-days-container -->
        </div><!-- #oy-hours-grid-wrap -->

        <script type="text/javascript">
        jQuery(document).ready(function($) {

            // ── Radio: mostrar/ocultar grilla ───────────────────────────────
            $('.oy-hours-status-radio').on('change', function() {
                $('#oy-hours-grid-wrap')[ $(this).val() === 'open_with_hours' ? 'slideDown' : 'slideUp' ](150);
            });

            // ── Helper: generar <option> HTML para selects ──────────────────
            function buildOptions(selectedVal, includeAllDay) {
                var html = '';
                for (var i = 0; i < oyHoursTimeOptions.length; i++) {
                    var opt = oyHoursTimeOptions[i];
                    if (!includeAllDay && opt.value === '24_hours') continue;
                    html += '<option value="' + opt.value + '"' + (opt.value === selectedVal ? ' selected' : '') + '>' + opt.label + '</option>';
                }
                return html;
            }

            // ── Helper: construir un nuevo row de período ───────────────────
            function buildPeriodRow(dayKey, idx, openVal, closeVal, isFirst) {
                var marginTop = isFirst ? 'margin-top:18px;' : '';
                var labelO = isFirst ? '<div style="font-size:10px;color:#888;margin-bottom:2px;">' + oyHoursI18n.opensAt  + '</div>' : '';
                var labelC = isFirst ? '<div style="font-size:10px;color:#888;margin-bottom:2px;">' + oyHoursI18n.closesAt + '</div>' : '';
                return '<div class="oy-period-row" style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">' +
                    '<div>' + labelO + '<select name="location_hours_' + dayKey + '[periods][' + idx + '][open]" class="oy-hours-sel oy-period-open" style="min-width:130px;">' + buildOptions(openVal, true)  + '</select></div>' +
                    '<div>' + labelC + '<select name="location_hours_' + dayKey + '[periods][' + idx + '][close]" class="oy-hours-sel oy-period-close" style="min-width:130px;">' + buildOptions(closeVal, false) + '</select></div>' +
                    '<div style="' + marginTop + '"><button type="button" class="button oy-add-period" data-day="' + dayKey + '" title="' + oyHoursI18n.addPeriod + '" style="padding:2px 8px;min-height:28px;">＋</button></div>' +
                '</div>';
            }

            // ── Helper: reindexar names y botones tras add/remove ───────────
            function reindexPeriods(dayKey) {
                var $rows = $('.oy-day-periods[data-day="' + dayKey + '"] .oy-period-row');
                $rows.each(function(idx) {
                    $(this).find('.oy-period-open' ).attr('name', 'location_hours_' + dayKey + '[periods][' + idx + '][open]');
                    $(this).find('.oy-period-close').attr('name', 'location_hours_' + dayKey + '[periods][' + idx + '][close]');
                    // Botón de acción del row: primer row → solo +; resto → 🗑 (+ en el último)
                    var $btnWrap = $(this).find('div:last-child');
                    $btnWrap.find('.oy-add-period, .oy-remove-period').remove();
                    if (idx === 0) {
                        $btnWrap.append('<button type="button" class="button oy-add-period" data-day="' + dayKey + '" title="' + oyHoursI18n.addPeriod + '" style="padding:2px 8px;min-height:28px;">＋</button>');
                    } else {
                        $btnWrap.append('<button type="button" class="button oy-remove-period" data-day="' + dayKey + '" title="' + oyHoursI18n.removePeriod + '" style="padding:2px 8px;min-height:28px;color:#dc3232;">🗑</button>');
                    }
                });
            }

            // ── Checkbox "Cerrada" ───────────────────────────────────────────
            $(document).on('change', '.oy-hours-closed-cb', function() {
                var day    = $(this).data('day');
                var closed = $(this).is(':checked');
                $('.oy-day-periods[data-day="' + day + '"]'). css('opacity', closed ? 0.5 : 1).find('select').prop('disabled', closed);
            });

            // ── Agregar período ──────────────────────────────────────────────
            $(document).on('click', '.oy-add-period', function() {
                var dayKey   = $(this).data('day');
                var $periods = $('.oy-day-periods[data-day="' + dayKey + '"]');
                var newIdx   = $periods.find('.oy-period-row').length;
                $periods.append(buildPeriodRow(dayKey, newIdx, '09:00', '18:00', false));
                reindexPeriods(dayKey);
            });

            // ── Eliminar período ─────────────────────────────────────────────
            $(document).on('click', '.oy-remove-period', function() {
                var dayKey = $(this).data('day');
                $(this).closest('.oy-period-row').remove();
                reindexPeriods(dayKey);
            });

            // ── Select "Abre a la(s)" = 24h → deshabilitar cierre ───────────
            $(document).on('change', '.oy-period-open', function() {
                var isAllDay = $(this).val() === '24_hours';
                var $row = $(this).closest('.oy-period-row');
                $row.find('.oy-period-close').prop('disabled', isAllDay).val(isAllDay ? '' : undefined);
                $row.find('.oy-add-period')[ isAllDay ? 'hide' : 'show' ]();
            });

            // ── Exponer helpers a window para uso desde applyLocationToForm ──
            window.oyHours_buildOptions  = buildOptions;
            window.oyHours_buildRow      = buildPeriodRow;
            window.oyHours_reindex       = reindexPeriods;

        });
        </script>
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
                // ✅ CORRECCIÓN: Verifications API usa COMPLETED para indicar verificación completada.
                $state = strtoupper( (string) $gmb_verification_state );

                $icon  = '';
                $color = '';
                $text  = __( 'No disponible', 'lealez' );

                if ( in_array( $state, array( 'VERIFIED', 'COMPLETED' ), true ) ) {
                    $icon  = '✓';
                    $color = '#46b450'; // verde
                    $text  = __( 'Verificado', 'lealez' );
                } elseif ( $state === 'UNVERIFIED' ) {
                    $icon  = '⚠';
                    $color = '#ffb900'; // amarillo
                    $text  = __( 'No verificado', 'lealez' );
                } elseif ( in_array( $state, array( 'VERIFICATION_REQUESTED', 'VERIFICATION_IN_PROGRESS', 'PENDING' ), true ) ) {
                    $icon  = '⏳';
                    $color = '#00a0d2'; // azul
                    $text  = __( 'Verificación en progreso', 'lealez' );
                } elseif ( in_array( $state, array( 'FAILED', 'SUSPENDED' ), true ) ) {
                    $icon  = '✖';
                    $color = '#dc3232'; // rojo
                    $text  = __( 'Verificación fallida / suspendida', 'lealez' );
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
                       value="<?php echo in_array( $state, array( 'VERIFIED', 'COMPLETED' ), true ) ? '1' : '0'; ?>">

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

        <?php if ( in_array( $state, array( 'VERIFIED', 'COMPLETED' ), true ) ) : ?>
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

        // ── Dirección — espeja la UX "Ubicación de la empresa" de GMB ──────────
        //
        // Determinar si la ubicación tiene dirección física real.
        // Una dirección se considera vacía si storefrontAddress no existe o no tiene
        // addressLines[0], locality ni regionCode poblados.
        var hasStorefront = !!(
            loc.storefrontAddress &&
            (
                ( loc.storefrontAddress.addressLines &&
                  loc.storefrontAddress.addressLines.length &&
                  loc.storefrontAddress.addressLines[0] ) ||
                loc.storefrontAddress.locality ||
                loc.storefrontAddress.regionCode
            )
        );

        if ( !hasStorefront ) {
            // Sin dirección física: activar "Sin ubicación física"
            $('#service_area_only').prop('checked', true);
            // Desmarcar "Mostrar dirección" (no hay dirección que mostrar)
            $('#show_address_to_customers').prop('checked', false);
            // Limpiar campos de dirección para no dejar datos residuales
            $('#location_address_line1').val('');
            $('#location_address_line2').val('');
            $('#location_city').val('');
            $('#location_state').val('');
            $('#location_country').val('');
            $('#location_postal_code').val('');
        } else {
            // Con dirección física: desmarcar "Solo servicio" y poblar campos
            $('#service_area_only').prop('checked', false);

            var a = loc.storefrontAddress;

            // Línea 1
            if(a.addressLines && a.addressLines.length){
                $('#location_address_line1').val(a.addressLines[0] || '');
            }

            // Línea 2: subPremise tiene prioridad (GMB); fallback a addressLines[1]
            var line2 = '';
            if(a.subPremise){
                line2 = a.subPremise;
            } else if(a.addressLines && a.addressLines[1]){
                line2 = a.addressLines[1];
            }
            $('#location_address_line2').val(line2);

            // Barrio/Colonia: sublocality si viene de GMB
            if(a.sublocality){
                $('#location_neighborhood').val(a.sublocality);
            }

            // Demás campos de dirección
            if(a.locality)           { $('#location_city').val(a.locality); }
            if(a.administrativeArea) { $('#location_state').val(a.administrativeArea); }
            if(a.postalCode)         { $('#location_postal_code').val(a.postalCode); }
            if(a.regionCode)         { $('#location_country').val(a.regionCode); }
        }

        // Disparar toggle de visibilidad SIEMPRE después de cambiar checkboxes
        if(typeof window.oy_toggle_address_fields === 'function'){
            window.oy_toggle_address_fields();
        }
        // ── Fin bloque dirección ─────────────────────────────────────────────────

// LatLng
if(loc.latlng){
    var latSet = false;
    var lngSet = false;
    if(typeof loc.latlng.latitude !== 'undefined'){
        $('#location_latitude').val(loc.latlng.latitude);
        latSet = true;
    }
    if(typeof loc.latlng.longitude !== 'undefined'){
        $('#location_longitude').val(loc.latlng.longitude);
        lngSet = true;
    }
    // ✅ Disparar actualización del mapa embebido una vez que ambas coords se han asignado.
    // jQuery .val() NO dispara eventos change/input automáticamente, por eso se llama
    // explícitamente a oy_update_map_preview después de setear lat y lng.
    if((latSet || lngSet) && typeof window.oy_update_map_preview === 'function'){
        window.oy_update_map_preview();
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

        // ✅ Profile description → Descripción (GMB)
        if(loc.profile && loc.profile.description){
            var desc = loc.profile.description;
            var $desc = $('#location_short_description');
            if($desc.length){
                $desc.val(desc);
                // Actualizar contador de caracteres
                var $counter = $desc.closest('td').find('.oy-char-count, #gmb-desc-char-count');
                if($counter.length){
                    $counter.text(desc.length + '/750');
                }
            }
        }

        // ── Horarios de atención: mapeo completo GMB → UI (multi-período) ─────────
        //
        // Soporta 3 fuentes de GMB:
        //   1. openInfo.openingHoursType = 'ALWAYS_OPEN' → todos 24h
        //   2. regularHours.periods[]                    → n períodos por día
        //   3. openInfo.status CLOSED_*                  → cerrado temporal/permanente
        //
        // La UI usa la nueva estructura .oy-day-periods / .oy-period-row generada
        // por render_hours_meta_box. Los helpers buildRow/reindex se expusieron como
        // window.oyHours_buildRow / window.oyHours_reindex desde ese mismo metabox.
        // ─────────────────────────────────────────────────────────────────────────

        var _openInfoObj  = loc.openInfo    || null;
        var _regularHours = loc.regularHours || null;

        var _openStatus  = _openInfoObj ? String(_openInfoObj.status           || '').toUpperCase() : '';
        var _hoursType   = _openInfoObj ? String(_openInfoObj.openingHoursType || '').toUpperCase() : '';
        var _hasPeriods  = !!(_regularHours && _regularHours.periods && _regularHours.periods.length);
        var _isAlwaysOpen = (_hoursType === 'ALWAYS_OPEN');

        // Determinar y aplicar radio de estado
        var _hoursStatus;
        if      (_openStatus === 'CLOSED_TEMPORARILY' ) { _hoursStatus = 'temporarily_closed'; }
        else if (_openStatus === 'CLOSED_PERMANENTLY' ) { _hoursStatus = 'permanently_closed'; }
        else { _hoursStatus = (_hasPeriods || _isAlwaysOpen) ? 'open_with_hours' : 'open_without_hours'; }
        $('input[name="location_hours_status"][value="' + _hoursStatus + '"]').prop('checked', true).trigger('change');

        // ── Helper local: reconstruir DOM de períodos para un día ────────────────
        // Usa window.oyHours_buildRow expuesto desde render_hours_meta_box.
        // Si aún no está disponible (orden de carga), construye HTML inline.
        function _rebuildDayPeriods(dayKey, periodsArr, isClosed) {
            var $pane = $('.oy-day-periods[data-day="' + dayKey + '"]');
            var $cb   = $('[name="location_hours_' + dayKey + '[closed]"]');
            if (!$pane.length) return;

            // Actualizar checkbox cerrada
            $cb.prop('checked', !!isClosed);
            $pane.css('opacity', isClosed ? 0.5 : 1);

            // Limpiar todos los period-rows actuales
            $pane.find('.oy-period-row').remove();

            if (isClosed) {
                // Día cerrado: añadir un row deshabilitado con valores por defecto
                var closedHtml = _buildRowHtml(dayKey, 0, '09:00', '18:00', true, true);
                $pane.append(closedHtml);
                return;
            }

            // Añadir un row por cada período
            for (var ri = 0; ri < periodsArr.length; ri++) {
                var rowHtml = _buildRowHtml(dayKey, ri, periodsArr[ri].open, periodsArr[ri].close, false, false);
                $pane.append(rowHtml);
            }

            // Reindexar names y botones
            if (typeof window.oyHours_reindex === 'function') {
                window.oyHours_reindex(dayKey);
            }
        }

        // ── Helper: construir HTML de un period-row ──────────────────────────────
        function _buildRowHtml(dayKey, idx, openVal, closeVal, disabled, showLabels) {
            // Usar helper expuesto si disponible
            if (typeof window.oyHours_buildRow === 'function') {
                return window.oyHours_buildRow(dayKey, idx, openVal, closeVal, (idx === 0));
            }
            // Fallback inline (usa oyHoursTimeOptions global)
            var openOpts = '', closeOpts = '';
            if (typeof oyHoursTimeOptions !== 'undefined') {
                for (var oi = 0; oi < oyHoursTimeOptions.length; oi++) {
                    var opt = oyHoursTimeOptions[oi];
                    openOpts  += '<option value="' + opt.value + '"' + (opt.value === openVal  ? ' selected' : '') + '>' + opt.label + '</option>';
                    if (opt.value !== '24_hours') {
                        closeOpts += '<option value="' + opt.value + '"' + (opt.value === closeVal ? ' selected' : '') + '>' + opt.label + '</option>';
                    }
                }
            }
            var disAttr  = disabled ? ' disabled' : '';
            var dis24    = (disabled || openVal === '24_hours') ? ' disabled' : '';
            var lblOpen  = (idx === 0) ? '<div style="font-size:10px;color:#888;margin-bottom:2px;">Abre a la(s)</div>' : '';
            var lblClose = (idx === 0) ? '<div style="font-size:10px;color:#888;margin-bottom:2px;">Cierra a la(s)</div>' : '';
            var mTop     = (idx === 0) ? 'margin-top:18px;' : '';
            return '<div class="oy-period-row" style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">' +
                '<div>' + lblOpen  + '<select name="location_hours_' + dayKey + '[periods][' + idx + '][open]"  class="oy-hours-sel oy-period-open"  style="min-width:130px;"' + disAttr + '>' + openOpts  + '</select></div>' +
                '<div>' + lblClose + '<select name="location_hours_' + dayKey + '[periods][' + idx + '][close]" class="oy-hours-sel oy-period-close" style="min-width:130px;"' + dis24  + '>' + closeOpts + '</select></div>' +
                '<div style="' + mTop + '"><button type="button" class="button oy-add-period" data-day="' + dayKey + '" style="padding:2px 8px;min-height:28px;">＋</button></div>' +
            '</div>';
        }

        // ── Normalizar un período de GMB a {open:'HH:MM', close:'HH:MM'} ─────────
        var _dayOrder = {MONDAY:0,TUESDAY:1,WEDNESDAY:2,THURSDAY:3,FRIDAY:4,SATURDAY:5,SUNDAY:6};

function _normalizePeriod(p) {
            var od = p.openDay ? String(p.openDay).toUpperCase() : '';
            var openH  = (p.openTime  && typeof p.openTime.hours  !== 'undefined') ? parseInt(p.openTime.hours,  10) : 0;
            var openM  = (p.openTime  && typeof p.openTime.minutes!== 'undefined') ? parseInt(p.openTime.minutes,10) : 0;
            var closeH = (p.closeTime && typeof p.closeTime.hours !== 'undefined') ? parseInt(p.closeTime.hours, 10) : 0;
            var closeM = (p.closeTime && typeof p.closeTime.minutes!=='undefined') ? parseInt(p.closeTime.minutes,10): 0;
            var closeDay    = p.closeDay ? String(p.closeDay).toUpperCase() : od;
            var closeMissing = !(p.closeTime);
            var odIdx  = (typeof _dayOrder[od]       !== 'undefined') ? _dayOrder[od]       : -1;
            var cdIdx  = (typeof _dayOrder[closeDay] !== 'undefined') ? _dayOrder[closeDay] : -1;
            var isNext = (odIdx >= 0 && cdIdx >= 0 && cdIdx === ((odIdx + 1) % 7));
            var is24h  = false;
            // Regla 1: open=0:0 && close=0:0 && closeDay es el día siguiente (forma estándar de Google para 24h)
            if (openH===0 && openM===0 && closeH===0 && closeM===0 && isNext)  { is24h = true; }
            // Regla 2: closeTime.hours===24 (Google usa 24 para fin-de-día/medianoche) y open es medianoche
            if (!is24h && closeH===24  && openH===0  && openM===0)             { is24h = true; }
            // Regla 3: closeTime ausente y open es medianoche (Google a veces omite closeTime en 24h)
            if (!is24h && closeMissing && openH===0  && openM===0)             { is24h = true; }
            // NOTA: El check "openH===0 && closeH===0 sin isNext" fue ELIMINADO porque
            // causaba falsos positivos (negocios que cierran a medianoche 00:00).

            // ── Normalizar closeH=24 → 0 cuando NO es 24h ──────────────────────────
            // Google usa closeTime.hours=24 para medianoche. Si no es 24h (ej. abre 8am,
            // cierra a medianoche), lo convertimos a "00:00" que sí está en el <select>.
            if (!is24h && closeH === 24) { closeH = 0; closeM = 0; }

            return {
                is24h:  is24h,
                open:   is24h ? '24_hours' : (('0'+openH).slice(-2)  + ':' + ('0'+openM).slice(-2)),
                close:  is24h ? ''         : (('0'+closeH).slice(-2) + ':' + ('0'+closeM).slice(-2))
            };
        }

        // ── Construir mapa día → [períodos] acumulando TODOS los turnos ──────────
        var _dayKeys = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        var _dayGmb  = {monday:'MONDAY',tuesday:'TUESDAY',wednesday:'WEDNESDAY',thursday:'THURSDAY',
                        friday:'FRIDAY',saturday:'SATURDAY',sunday:'SUNDAY'};

        // Por defecto todos los días = cerrado (se sobreescribirá si viene en periods)
        var _dayData = {};
        for (var _di = 0; _di < _dayKeys.length; _di++) {
            _dayData[_dayGmb[_dayKeys[_di]]] = { closed: true, periods: [] };
        }

        if (_isAlwaysOpen) {
            // Todos los días: 24 horas
            for (var _ai = 0; _ai < _dayKeys.length; _ai++) {
                var _gk = _dayGmb[_dayKeys[_ai]];
                _dayData[_gk] = { closed: false, periods: [{open:'24_hours', close:''}] };
            }

        } else if (_hasPeriods) {
            // Procesar TODOS los períodos sin descartar duplicados
            var _rawPeriods = _regularHours.periods;
            for (var _pi = 0; _pi < _rawPeriods.length; _pi++) {
                var _p  = _rawPeriods[_pi];
                if (!_p || !_p.openDay) continue;
                var _od = String(_p.openDay).toUpperCase();
                if (!_dayData[_od]) continue;

                var _norm = _normalizePeriod(_p);

                if (_norm.is24h) {
                    // 24h: reemplazar todo con un solo período 24h
                    _dayData[_od] = { closed: false, periods: [{open:'24_hours', close:''}] };
                } else {
                    // Si ya estaba marcado como 24h, no agregar más períodos
                    if (_dayData[_od].periods.length === 1 && _dayData[_od].periods[0].open === '24_hours') continue;
                    _dayData[_od].closed = false;
                    _dayData[_od].periods.push({ open: _norm.open, close: _norm.close });
                }
            }
        }

        // ── Aplicar cada día al DOM ───────────────────────────────────────────────
        for (var _ki = 0; _ki < _dayKeys.length; _ki++) {
            var _dk  = _dayKeys[_ki];
            var _gk2 = _dayGmb[_dk];
            var _dd  = _dayData[_gk2];
            _rebuildDayPeriods(_dk, _dd.periods.length ? _dd.periods : [{open:'09:00',close:'18:00'}], _dd.closed);
        }
        // ── Fin bloque horarios ──────────────────────────────────────────────────

        // ✅ metadata → URL en Google Maps (con fallback placeId y latlng)
        // Intentamos primero metadata.mapsUri; si no existe, construimos desde placeId o latlng.
        var mapsUrlFilled = false;
        var $mapUrl = $('#location_map_url');

        if($mapUrl.length){
            // Opción 1: metadata.mapsUri (valor oficial de Google)
            if(loc.metadata && loc.metadata.mapsUri){
                $mapUrl.val(loc.metadata.mapsUri);
                mapsUrlFilled = true;
            }

            // Opción 2: fallback desde placeId
            if(!mapsUrlFilled){
                var placeIdVal = (loc.metadata && loc.metadata.placeId) ? loc.metadata.placeId : '';
                if(placeIdVal){
                    $mapUrl.val('https://www.google.com/maps/place/?q=place_id:' + encodeURIComponent(placeIdVal));
                    mapsUrlFilled = true;
                }
            }

            // Opción 3: fallback desde latlng
            if(!mapsUrlFilled && loc.latlng){
                var lat = (typeof loc.latlng.latitude  !== 'undefined') ? loc.latlng.latitude  : 0;
                var lng = (typeof loc.latlng.longitude !== 'undefined') ? loc.latlng.longitude : 0;
                if(lat && lng){
                    $mapUrl.val('https://www.google.com/maps/search/?api=1&query=' + lat + ',' + lng);
                    mapsUrlFilled = true;
                }
            }
        }

        // ✅ metadata.newReviewUri → URL de reseñas de Google (si el campo existe)
        if(loc.metadata && loc.metadata.newReviewUri){
            var $reviewUrl = $('#google_reviews_url');
            if($reviewUrl.length && !$reviewUrl.val()){
                $reviewUrl.val(loc.metadata.newReviewUri);
            }
        }

// ✅ metadata.placeId → location_place_id
        if(loc.metadata && loc.metadata.placeId){
            var $placeId = $('#location_place_id');
            if($placeId.length){
                $placeId.val(loc.metadata.placeId);
            }
        }

// ✅ Atributos GMB → Usuario de chat (location_chat_url)
        // IDs oficiales según Google Business Profile API:
        //   url_whatsapp       → WhatsApp click-to-chat
        //   url_text_messaging → SMS / texto
        // https://developers.google.com/my-business/content/whatsapp-text
        //
        // La API puede devolver el atributo con:
        //   - attr.attributeId = 'url_whatsapp'  (formato corto)
        //   - attr.name = 'attributes/url_whatsapp'  (formato resource name)
        //   - attr.name = 'locations/123/attributes/url_whatsapp'  (formato completo)
        if(loc.attributes && Array.isArray(loc.attributes)){
            var chatOfficialIds = ['url_whatsapp', 'url_text_messaging', 'url_text_messaging3'];
            var gmbChatUrl = '';

            for(var ai = 0; ai < loc.attributes.length; ai++){
                var attr = loc.attributes[ai];
                if(!attr){ continue; }

                // ── Normalizar el ID del atributo (igual que en PHP) ──────────
                var attrIdRaw = '';
                if(attr.attributeId){
                    attrIdRaw = attr.attributeId;
                } else if(attr.name){
                    // Extraer la parte después del último 'attributes/'
                    var nameParts = attr.name.split('/attributes/');
                    attrIdRaw = nameParts[nameParts.length - 1] || '';
                }
                if(!attrIdRaw){ continue; }
                var attrIdLower = attrIdRaw.toLowerCase();

                // ── Verificar si es atributo de chat oficial ──────────────────
                var isChatAttr = false;
                for(var ci = 0; ci < chatOfficialIds.length; ci++){
                    if(attrIdLower === chatOfficialIds[ci]){
                        isChatAttr = true;
                        break;
                    }
                }
                // Fallback: cualquier atributo con 'whatsapp' en el nombre y uriValues
                if(!isChatAttr && attrIdLower.indexOf('whatsapp') !== -1 && attr.uriValues && attr.uriValues.length){
                    isChatAttr = true;
                }

                if(!isChatAttr){ continue; }

                // ── Extraer el URI ────────────────────────────────────────────
                if(attr.uriValues && attr.uriValues.length && attr.uriValues[0] && attr.uriValues[0].uri){
                    gmbChatUrl = attr.uriValues[0].uri;
                    break;
                }
            }

            if(gmbChatUrl){
                var $chatField = $('#location_chat_url');
                if($chatField.length){
                    // Siempre actualizar desde GMB (es la fuente de verdad para este campo)
                    $chatField.val(gmbChatUrl);
                }
                if(window.console && window.console.log){
                    console.log('[OY Location] GMB chat URL aplicado desde atributo oficial:', gmbChatUrl);
                }
            } else {
                if(window.console && window.console.log){
                    console.log('[OY Location] GMB chat URL: no se encontró url_whatsapp ni url_text_messaging en loc.attributes');
                    if(window.location.search.indexOf('debug=1') !== -1){
                        console.log('[OY Location] Atributos disponibles:', loc.attributes);
                    }
                }
            }
        }

        // ✅ Place Action Links → Vínculo del Menú / Reservas / Pedidos Online
        // ─────────────────────────────────────────────────────────────────────────────────
        // Place Actions API v1 es la única fuente de estos vínculos en el perfil de Google.
        // El PHP (ajax_get_gmb_location_details) ya los adjunta como loc.placeActionLinks.
        // Los clasificamos por placeActionType y los aplicamos a los campos correspondientes:
        //   MENU                                          → #location_menu_url_gmb
        //   APPOINTMENT / ONLINE_APPOINTMENT / DINING_RESERVATION → #oy-booking-urls-list (DOM dinámico)
        //   FOOD_ORDERING / FOOD_DELIVERY / FOOD_TAKEOUT / etc.   → #oy-order-urls-list   (DOM dinámico)
        // ─────────────────────────────────────────────────────────────────────────────────
        if(loc.placeActionLinks && Array.isArray(loc.placeActionLinks) && loc.placeActionLinks.length){

            var paBookingTypes = ['APPOINTMENT','ONLINE_APPOINTMENT','DINING_RESERVATION'];
            var paOrderTypes   = ['FOOD_ORDERING','FOOD_DELIVERY','FOOD_TAKEOUT','SHOP_ONLINE','ORDER_AHEAD','ORDER_FOOD'];
            var paTypeLabelMap = {
                'APPOINTMENT':        '<?php echo esc_js( __( 'Reservas', 'lealez' ) ); ?>',
                'ONLINE_APPOINTMENT': '<?php echo esc_js( __( 'Cita online', 'lealez' ) ); ?>',
                'DINING_RESERVATION': '<?php echo esc_js( __( 'Reserva de mesa', 'lealez' ) ); ?>',
                'MENU':               '<?php echo esc_js( __( 'Menú', 'lealez' ) ); ?>',
                'FOOD_ORDERING':      '<?php echo esc_js( __( 'Ordenar en línea', 'lealez' ) ); ?>',
                'FOOD_DELIVERY':      '<?php echo esc_js( __( 'Domicilio', 'lealez' ) ); ?>',
                'FOOD_TAKEOUT':       '<?php echo esc_js( __( 'Para llevar', 'lealez' ) ); ?>',
                'SHOP_ONLINE':        '<?php echo esc_js( __( 'Tienda online', 'lealez' ) ); ?>',
                'ORDER_AHEAD':        '<?php echo esc_js( __( 'Ordenar anticipado', 'lealez' ) ); ?>',
                'ORDER_FOOD':         '<?php echo esc_js( __( 'Pedir comida', 'lealez' ) ); ?>'
            };

            // Acumuladores para evitar duplicados de URL
            var paBookingUrls = [];
            var paOrderUrls   = [];
            var paMenuUrl     = '';

            for(var pli = 0; pli < loc.placeActionLinks.length; pli++){
                var paLink = loc.placeActionLinks[pli];
                if(!paLink){ continue; }
                var paUri  = paLink.uri  ? String(paLink.uri).trim()  : '';
                var paType = paLink.placeActionType ? String(paLink.placeActionType).toUpperCase().trim() : '';
                if(!paUri || !paType){ continue; }
                var paLabel = paTypeLabelMap[paType] || paType;

                if(paType === 'MENU'){
                    // Solo la primera URL de tipo MENU
                    if(!paMenuUrl){ paMenuUrl = paUri; }

                } else if(paBookingTypes.indexOf(paType) !== -1){
                    // Evitar duplicados de URL en booking
                    var paBAlready = false;
                    for(var pabi = 0; pabi < paBookingUrls.length; pabi++){
                        if(paBookingUrls[pabi].url === paUri){ paBAlready = true; break; }
                    }
                    if(!paBAlready){
                        paBookingUrls.push({ url: paUri, label: paLabel, type: paType, from_gmb: 1 });
                    }

                } else if(paOrderTypes.indexOf(paType) !== -1){
                    // Evitar duplicados de URL en order
                    var paOAlready = false;
                    for(var paoi = 0; paoi < paOrderUrls.length; paoi++){
                        if(paOrderUrls[paoi].url === paUri){ paOAlready = true; break; }
                    }
                    if(!paOAlready){
                        paOrderUrls.push({ url: paUri, label: paLabel, type: paType, from_gmb: 1 });
                    }
                }
            }

            // ── Aplicar MENU URL → campo #location_menu_url_gmb ──────────────
            if(paMenuUrl){
                var $menuField = $('#location_menu_url_gmb');
                if($menuField.length){
                    $menuField.val(paMenuUrl);
                    // Actualizar el enlace de vista previa si existe
                    var $menuWrap = $('#oy-menu-link-wrap');
                    if($menuWrap.length){
                        var $previewLink = $menuWrap.find('a');
                        if($previewLink.length){
                            $previewLink.attr('href', paMenuUrl).text(paMenuUrl + ' ↗');
                        } else {
                            // Crear enlace de vista previa dinámicamente
                            $menuWrap.append('<p style="margin:4px 0 0;font-size:12px;"><a href="' +
                                $('<div>').text(paMenuUrl).html() + '" target="_blank" rel="noopener">' +
                                $('<div>').text(paMenuUrl).html() + ' ↗</a></p>');
                        }
                    }
                    // Mostrar badge GMB si existe
                    var $gmbBadge = $menuWrap.find('span');
                    if($gmbBadge.length){
                        $gmbBadge.show();
                    }
                }
                if(window.console && window.console.log){
                    console.log('[OY Location] Place Action MENU aplicado:', paMenuUrl);
                }
            }

            // ── Helper: construir HTML de una fila de URL dinámica ───────────────
            function _buildPlaceActionRow(listSelector, urlsArray, namePrefix, removeBtnClass){
                var $list = $(listSelector);
                if(!$list.length || !urlsArray.length){ return; }

                // Obtener el índice máximo actual en el DOM para no sobrescribir manuales
                var maxIdx = -1;
                $list.find('input[name^="' + namePrefix + '["]').each(function(){
                    var m = this.name.match(new RegExp(namePrefix.replace(/[[\]]/g,'\\$&') + '\\[(\\d+)\\]'));
                    if(m){ var i = parseInt(m[1], 10); if(i > maxIdx){ maxIdx = i; } }
                });

                for(var rowi = 0; rowi < urlsArray.length; rowi++){
                    var entry = urlsArray[rowi];
                    var idx   = maxIdx + 1 + rowi;

                    // Verificar si ya existe una fila con from_gmb=1 y mismo tipo — reemplazar en vez de duplicar
                    var $existingGmb = $list.find('input[name="' + namePrefix + '[' + idx + '][from_gmb]"]');

                    var rowHtml =
                        '<div class="' + removeBtnClass.replace('oy-remove-','oy-') + '-row" ' +
                        'style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">' +
                        '<input type="url" name="' + namePrefix + '[' + idx + '][url]" ' +
                        'value="' + $('<div>').text(entry.url).html() + '" class="large-text" ' +
                        'placeholder="https://..." style="flex:1;min-width:250px;">' +
                        '<input type="text" name="' + namePrefix + '[' + idx + '][label]" ' +
                        'value="' + $('<div>').text(entry.label).html() + '" class="regular-text" ' +
                        'style="max-width:180px;">' +
                        '<input type="hidden" name="' + namePrefix + '[' + idx + '][type]"     value="' + $('<div>').text(entry.type).html() + '">' +
                        '<input type="hidden" name="' + namePrefix + '[' + idx + '][from_gmb]" value="1">' +
                        '<span style="font-size:11px;color:#2271b1;white-space:nowrap;background:#e8f0fe;border:1px solid #b3d4f5;border-radius:3px;padding:2px 6px;">' +
                        '🔄 GMB · ' + $('<div>').text(entry.type).html() + '</span>' +
                        '<button type="button" class="button button-small ' + removeBtnClass + '" style="color:#dc3232;">✕</button>' +
                        '</div>';

                    $list.append(rowHtml);
                }
            }

            // ── Aplicar Booking URLs → #oy-booking-urls-list ─────────────────
            if(paBookingUrls.length){
                // Primero eliminar las filas GMB existentes para no duplicar
                $('#oy-booking-urls-list .oy-booking-url-row').filter(function(){
                    return $(this).find('input[name$="[from_gmb]"]').val() === '1';
                }).remove();
                // Reindexar las filas manuales que quedan
                var paBookIdx = 0;
                $('#oy-booking-urls-list .oy-booking-url-row').each(function(){
                    $(this).find('input').each(function(){
                        this.name = this.name.replace(/location_booking_urls\[\d+\]/, 'location_booking_urls[' + paBookIdx + ']');
                    });
                    paBookIdx++;
                });
                _buildPlaceActionRow('#oy-booking-urls-list', paBookingUrls, 'location_booking_urls', 'oy-remove-booking-url');
                if(window.console && window.console.log){
                    console.log('[OY Location] Place Action Booking URLs aplicados:', paBookingUrls.length);
                }
            }

            // ── Aplicar Order URLs → #oy-order-urls-list ─────────────────────
            if(paOrderUrls.length){
                // Primero eliminar las filas GMB existentes para no duplicar
                $('#oy-order-urls-list .oy-order-url-row').filter(function(){
                    return $(this).find('input[name$="[from_gmb]"]').val() === '1';
                }).remove();
                // Reindexar las filas manuales que quedan
                var paOrderIdx = 0;
                $('#oy-order-urls-list .oy-order-url-row').each(function(){
                    $(this).find('input').each(function(){
                        this.name = this.name.replace(/location_order_urls\[\d+\]/, 'location_order_urls[' + paOrderIdx + ']');
                    });
                    paOrderIdx++;
                });
                _buildPlaceActionRow('#oy-order-urls-list', paOrderUrls, 'location_order_urls', 'oy-remove-order-url');
                if(window.console && window.console.log){
                    console.log('[OY Location] Place Action Order URLs aplicados:', paOrderUrls.length);
                }
            }

            if(window.console && window.console.log){
                console.log('[OY Location] placeActionLinks procesados — menu:', paMenuUrl || 'ninguno',
                    '| booking:', paBookingUrls.length, '| order:', paOrderUrls.length);
            }

        } else {
            if(window.console && window.console.log){
                console.log('[OY Location] placeActionLinks: no disponibles o vacíos en la respuesta del servidor.');
            }
        }
        // ── Fin bloque Place Action Links ──────────────────────────────────────────────

    }catch(e){
        if(window.console && window.console.error){ console.error('[OY Location] applyLocationToForm error:', e); }
    }
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

        // Google RAW categories
        $gmb_primary_category_name = get_post_meta( $post->ID, 'gmb_primary_category_name', true );
        $gmb_primary_category_dn   = get_post_meta( $post->ID, 'gmb_primary_category_display_name', true );
        $gmb_additional_categories = get_post_meta( $post->ID, 'gmb_additional_categories', true );

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
                        <?php _e( 'Este campo es tu vista "humana". Al importar, se poblará desde categories.primaryCategory.displayName.', 'lealez' ); ?>
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
                    <textarea readonly class="large-text" rows="3" style="font-family:monospace;"><?php
                        echo esc_textarea( ! empty( $gmb_additional_categories ) ? wp_json_encode( $gmb_additional_categories, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : '' );
                    ?></textarea>
                </td>
            </tr>
        </table>

        <hr>

        <?php
        // Classify attributes from GMB
        $gmb_attributes_raw = get_post_meta( $post->ID, 'gmb_attributes_raw', true );

        // Helper: get display value from attribute (handles both values[] and uriValues[])
        $get_attr_value = function( $attr ) {
            if ( ! empty( $attr['uriValues'] ) && is_array( $attr['uriValues'] ) ) {
                $uris = array_map( function( $u ) { return isset( $u['uri'] ) ? (string) $u['uri'] : ''; }, $attr['uriValues'] );
                return implode( ', ', array_filter( $uris ) );
            }
            if ( isset( $attr['values'] ) && is_array( $attr['values'] ) ) {
                return implode( ', ', array_map( 'strval', $attr['values'] ) );
            }
            return '';
        };

        // Credit card display labels
        $credit_card_labels = array(
            'visa'            => 'VISA',
            'mastercard'      => 'MasterCard',
            'amex'            => 'American Express',
            'american_express'=> 'American Express',
            'diners'          => 'Diners Club',
            'diners_club'     => 'Diners Club',
            'discover'        => 'Discover',
            'jcb'             => 'JCB',
            'china_union_pay' => 'China Union Pay',
            'unionpay'        => 'China Union Pay',
        );

        if ( ! empty( $gmb_attributes_raw ) && is_array( $gmb_attributes_raw ) ) {

            $accessibility_attrs   = array();
            $amenities_attrs       = array();
            $payment_general_attrs = array();
            $payment_credit_attrs  = array();
            $payment_debit_attrs   = array();
            $identity_attrs        = array();
            $audience_attrs        = array();
            $other_attrs           = array();

            foreach ( $gmb_attributes_raw as $attr ) {
                if ( ! is_array( $attr ) || empty( $attr['attributeId'] ) ) {
                    continue;
                }
                $attr_id_raw = (string) $attr['attributeId'];
                $attr_id     = strtolower( $attr_id_raw );
                $attr_name   = $this->humanize_attribute_id( $attr_id_raw );
                $value_str   = $get_attr_value( $attr );
                $entry       = array( 'name' => $attr_name, 'value' => $value_str, 'raw' => $attr_id_raw );

                if ( stripos( $attr_id, 'wheelchair' ) !== false || stripos( $attr_id, 'accessible' ) !== false ) {
                    $accessibility_attrs[] = $entry;
                } elseif ( stripos( $attr_id, 'wifi' ) !== false || stripos( $attr_id, 'parking' ) !== false ||
                           stripos( $attr_id, 'restroom' ) !== false || stripos( $attr_id, 'outdoor' ) !== false ||
                           stripos( $attr_id, 'seating' ) !== false ) {
                    $amenities_attrs[] = $entry;
                } elseif (
                    stripos( $attr_id, 'women_owned' ) !== false || stripos( $attr_id, 'women-owned' ) !== false ||
                    stripos( $attr_id, 'veteran_owned' ) !== false || stripos( $attr_id, 'veteran-owned' ) !== false ||
                    stripos( $attr_id, 'identifies_as' ) !== false || stripos( $attr_id, 'black_owned' ) !== false ||
                    stripos( $attr_id, 'latinx_owned' ) !== false || stripos( $attr_id, 'minority_owned' ) !== false
                ) {
                    $identity_attrs[] = $entry;
                } elseif (
                    stripos( $attr_id, 'lgbtq' ) !== false || stripos( $attr_id, 'lgbt' ) !== false ||
                    stripos( $attr_id, 'transgender' ) !== false || stripos( $attr_id, 'friendly' ) !== false
                ) {
                    $audience_attrs[] = $entry;
                } elseif (
                    stripos( $attr_id, 'visa' ) !== false || stripos( $attr_id, 'mastercard' ) !== false ||
                    stripos( $attr_id, 'amex' ) !== false || stripos( $attr_id, 'american_express' ) !== false ||
                    stripos( $attr_id, 'diners' ) !== false || stripos( $attr_id, 'discover' ) !== false ||
                    stripos( $attr_id, 'jcb' ) !== false || stripos( $attr_id, 'union_pay' ) !== false ||
                    stripos( $attr_id, 'unionpay' ) !== false
                ) {
                    $card_label = $attr_name;
                    foreach ( $credit_card_labels as $key => $lbl ) {
                        if ( stripos( $attr_id, $key ) !== false ) {
                            $card_label = $lbl;
                            break;
                        }
                    }
                    $entry['name'] = $card_label;
                    $payment_credit_attrs[] = $entry;
                } elseif ( stripos( $attr_id, 'debit' ) !== false ) {
                    $payment_debit_attrs[] = $entry;
                } elseif (
                    stripos( $attr_id, 'payment' ) !== false || stripos( $attr_id, 'credit_card' ) !== false ||
                    stripos( $attr_id, 'cash' ) !== false || stripos( $attr_id, 'mobile_payment' ) !== false ||
                    stripos( $attr_id, 'nfc' ) !== false || strpos( $attr_id, 'pay-' ) !== false
                ) {
                    $payment_general_attrs[] = $entry;
                } else {
                    $other_attrs[] = $entry;
                }
            }

            // Helper: render attribute table
            $render_attr_table = function( $attrs, $show_raw = true ) {
                if ( empty( $attrs ) ) return;
                echo '<table class="widefat" style="max-width:800px; margin-bottom:16px;">';
                echo '<thead><tr>';
                echo '<th style="width:45%;">' . esc_html__( 'Atributo', 'lealez' ) . '</th>';
                echo '<th style="width:25%;">' . esc_html__( 'Valor', 'lealez' ) . '</th>';
                if ( $show_raw ) {
                    echo '<th style="width:30%;">' . esc_html__( 'ID GMB', 'lealez' ) . '</th>';
                }
                echo '</tr></thead><tbody>';
                foreach ( $attrs as $a ) {
                    $v = strtolower( trim( $a['value'] ) );
                    if ( in_array( $v, array( 'true', '1' ), true ) ) {
                        $val_html = '<span style="color:#46b450;font-weight:600;">&#10003; Sí</span>';
                    } elseif ( in_array( $v, array( 'false', '0', '' ), true ) ) {
                        $val_html = '<span style="color:#dc3232;">&#10007; No / Sin datos</span>';
                    } else {
                        $val_html = '<strong>' . esc_html( $a['value'] ) . '</strong>';
                    }
                    echo '<tr>';
                    echo '<td>' . esc_html( $a['name'] ) . '</td>';
                    echo '<td>' . wp_kses_post( $val_html ) . '</td>';
                    if ( $show_raw ) {
                        echo '<td><code style="font-size:11px;">' . esc_html( $a['raw'] ) . '</code></td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table>';
            };

            // Accesibilidad
            if ( ! empty( $accessibility_attrs ) ) {
                echo '<h4>' . esc_html__( 'Accesibilidad', 'lealez' ) . '</h4>';
                $render_attr_table( $accessibility_attrs );
            }

            // Comodidades
            if ( ! empty( $amenities_attrs ) ) {
                echo '<h4>' . esc_html__( 'Comodidades', 'lealez' ) . '</h4>';
                $render_attr_table( $amenities_attrs );
            }

            // Pagos
            if ( ! empty( $payment_general_attrs ) || ! empty( $payment_credit_attrs ) || ! empty( $payment_debit_attrs ) ) {
                echo '<h4 style="margin-bottom:4px;">💳 ' . esc_html__( 'Pagos', 'lealez' ) . '</h4>';
                echo '<p class="description" style="margin-bottom:10px;">' .
                    esc_html__( 'Atributos de pago sincronizados desde Google My Business. Aparecen públicamente en Búsqueda y Maps.', 'lealez' ) .
                    '</p>';
                if ( ! empty( $payment_general_attrs ) ) {
                    echo '<p style="font-weight:600;font-size:12px;margin:4px 0;">' . esc_html__( 'General', 'lealez' ) . '</p>';
                    $render_attr_table( $payment_general_attrs );
                }
                if ( ! empty( $payment_credit_attrs ) ) {
                    echo '<p style="font-weight:600;font-size:12px;margin:4px 0;">' . esc_html__( 'Tarjetas de Crédito', 'lealez' ) . '</p>';
                    $render_attr_table( $payment_credit_attrs, false );
                }
                if ( ! empty( $payment_debit_attrs ) ) {
                    echo '<p style="font-weight:600;font-size:12px;margin:4px 0;">' . esc_html__( 'Tarjetas de Débito', 'lealez' ) . '</p>';
                    $render_attr_table( $payment_debit_attrs, false );
                }
            }

            // Información proporcionada por la empresa
            if ( ! empty( $identity_attrs ) ) {
                echo '<h4 style="margin-bottom:4px;">🏷️ ' . esc_html__( 'Información proporcionada por la empresa', 'lealez' ) . '</h4>';
                echo '<p class="description" style="margin-bottom:10px;">' .
                    esc_html__( 'Atributos de identidad del negocio (ej: mujer empresaria, propiedad de veteranos). Pueden aparecer públicamente.', 'lealez' ) .
                    '</p>';
                $render_attr_table( $identity_attrs );
            }

            // Público usual
            if ( ! empty( $audience_attrs ) ) {
                echo '<h4 style="margin-bottom:4px;">🌈 ' . esc_html__( 'Público usual', 'lealez' ) . '</h4>';
                echo '<p class="description" style="margin-bottom:10px;">' .
                    esc_html__( 'Atributos de audiencia objetivo (ej: Amigable con LGBTQ+). Pueden aparecer públicamente.', 'lealez' ) .
                    '</p>';
                $render_attr_table( $audience_attrs );
            }

            // Otros Atributos
            if ( ! empty( $other_attrs ) ) {
                echo '<h4>' . esc_html__( 'Otros Atributos', 'lealez' ) . '</h4>';
                $render_attr_table( $other_attrs );
            }

            $has_any = ! empty( $accessibility_attrs ) || ! empty( $amenities_attrs ) || ! empty( $payment_general_attrs ) ||
                       ! empty( $payment_credit_attrs ) || ! empty( $payment_debit_attrs ) || ! empty( $identity_attrs ) ||
                       ! empty( $audience_attrs ) || ! empty( $other_attrs );
            if ( ! $has_any ) {
                echo '<p class="description"><strong>' . esc_html__( 'No hay atributos sincronizados desde Google My Business.', 'lealez' ) . '</strong></p>';
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
        ?>
        <p class="description" style="margin-top:10px; font-style:italic;">
            <?php _e( 'Los datos RAW de categorías y atributos están disponibles en el metabox "🔧 Datos Técnicos / RAW Google".', 'lealez' ); ?>
        </p>
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
        $accepts_loyalty             = get_post_meta( $post->ID, 'accepts_loyalty', true );
        $loyalty_redemption_enabled  = get_post_meta( $post->ID, 'loyalty_redemption_enabled', true );
        $loyalty_earning_enabled     = get_post_meta( $post->ID, 'loyalty_earning_enabled', true );
        $loyalty_multiplier          = get_post_meta( $post->ID, 'loyalty_multiplier', true );
        $loyalty_terminal_id         = get_post_meta( $post->ID, 'loyalty_terminal_id', true );
        $loyalty_programs_accepted   = get_post_meta( $post->ID, 'loyalty_programs_accepted', true );

        if ( empty( $loyalty_multiplier ) ) {
            $loyalty_multiplier = '1.0';
        }
        if ( ! is_array( $loyalty_programs_accepted ) ) {
            $loyalty_programs_accepted = array();
        }

        // Get all available loyalty programs linked to the parent business
        $parent_business_id = get_post_meta( $post->ID, 'parent_business_id', true );
        $available_programs = array();
        if ( $parent_business_id ) {
            $available_programs = get_posts( array(
                'post_type'      => 'oy_loyalty_program',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'orderby'        => 'title',
                'order'          => 'ASC',
                'meta_query'     => array(
                    array(
                        'key'   => 'parent_business_id',
                        'value' => $parent_business_id,
                    ),
                ),
            ) );
        }
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label><?php _e( 'Acepta Programa de Lealtad', 'lealez' ); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="accepts_loyalty"
                               value="1"
                               <?php checked( $accepts_loyalty, '1' ); ?>>
                        <?php _e( 'Esta ubicación participa en el programa de lealtad', 'lealez' ); ?>
                    </label>
                </td>
            </tr>
            <?php if ( ! empty( $available_programs ) ) : ?>
            <tr>
                <th scope="row">
                    <label><?php _e( 'Programas Aceptados', 'lealez' ); ?></label>
                </th>
                <td>
                    <?php foreach ( $available_programs as $program ) : ?>
                        <label style="display:block; margin-bottom:5px;">
                            <input type="checkbox"
                                   name="loyalty_programs_accepted[]"
                                   value="<?php echo esc_attr( $program->ID ); ?>"
                                   <?php checked( in_array( (string) $program->ID, array_map( 'strval', $loyalty_programs_accepted ), true ) ); ?>>
                            <?php echo esc_html( $program->post_title ); ?>
                            <a href="<?php echo esc_url( get_edit_post_link( $program->ID ) ); ?>" target="_blank" style="font-size:11px; color:#999;">↗</a>
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php _e( 'Selecciona los programas de lealtad que aplican en esta sucursal.', 'lealez' ); ?></p>
                </td>
            </tr>
            <?php elseif ( $parent_business_id ) : ?>
            <tr>
                <th scope="row"><?php _e( 'Programas Aceptados', 'lealez' ); ?></th>
                <td>
                    <p class="description"><?php _e( 'No hay programas de lealtad creados para esta empresa.', 'lealez' ); ?>
                    <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=oy_loyalty_program' ) ); ?>"><?php _e( 'Crear programa →', 'lealez' ); ?></a></p>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th scope="row">
                    <label><?php _e( 'Ganar / Canjear', 'lealez' ); ?></label>
                </th>
                <td>
                    <label style="display:block; margin-bottom:5px;">
                        <input type="checkbox"
                               name="loyalty_earning_enabled"
                               value="1"
                               <?php checked( $loyalty_earning_enabled, '1' ); ?>>
                        <?php _e( 'Permitir ganar puntos en esta ubicación', 'lealez' ); ?>
                    </label>
                    <label>
                        <input type="checkbox"
                               name="loyalty_redemption_enabled"
                               value="1"
                               <?php checked( $loyalty_redemption_enabled, '1' ); ?>>
                        <?php _e( 'Permitir canjear puntos en esta ubicación', 'lealez' ); ?>
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
                    <p class="description"><?php _e( 'Multiplicador de puntos ganados aquí (ej: 1.5 = 50% extra).', 'lealez' ); ?></p>
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
                    <p class="description"><?php _e( 'ID del terminal POS asociado a esta sucursal.', 'lealez' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Staff, Social & Notes meta box — campos manuales que no vienen de GMB
     */
    public function render_staff_notes_meta_box( $post ) {
        $location_manager       = get_post_meta( $post->ID, 'location_manager', true );
        $location_manager_email = get_post_meta( $post->ID, 'location_manager_email', true );
        $location_manager_phone = get_post_meta( $post->ID, 'location_manager_phone', true );
        $internal_notes         = get_post_meta( $post->ID, 'internal_notes', true );
        $manager_notes          = get_post_meta( $post->ID, 'manager_notes', true );
        ?>
        <style>
        .oy-staff-section-title {
            font-size: 13px;
            font-weight: 600;
            color: #1d2327;
            margin: 18px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e2e4e7;
        }
        .oy-staff-section-title:first-child { margin-top: 0; }
        </style>

        <p class="oy-staff-section-title"><?php _e( '👤 Responsable / Gerente', 'lealez' ); ?></p>
        <table class="form-table" style="margin-top:0;">
            <tr>
                <th scope="row"><label for="location_manager"><?php _e( 'Nombre del Gerente', 'lealez' ); ?></label></th>
                <td>
                    <input type="text"
                           name="location_manager"
                           id="location_manager"
                           value="<?php echo esc_attr( $location_manager ); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'Ej: Juan Pérez', 'lealez' ); ?>">
                    <p class="description"><?php _e( '⚙️ Solo manual. No se exporta a GMB.', 'lealez' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="location_manager_email"><?php _e( 'Email del Gerente', 'lealez' ); ?></label></th>
                <td>
                    <input type="email"
                           name="location_manager_email"
                           id="location_manager_email"
                           value="<?php echo esc_attr( $location_manager_email ); ?>"
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="location_manager_phone"><?php _e( 'Teléfono del Gerente', 'lealez' ); ?></label></th>
                <td>
                    <input type="tel"
                           name="location_manager_phone"
                           id="location_manager_phone"
                           value="<?php echo esc_attr( $location_manager_phone ); ?>"
                           class="regular-text"
                           placeholder="+573001234567">
                </td>
            </tr>
        </table>

        <p class="oy-staff-section-title"><?php _e( '📝 Notas Internas', 'lealez' ); ?></p>
        <p class="description"><?php _e( 'Visibles solo para administradores. No se publican ni exportan.', 'lealez' ); ?></p>
        <table class="form-table" style="margin-top:0;">
            <tr>
                <th scope="row"><label for="internal_notes"><?php _e( 'Notas Internas', 'lealez' ); ?></label></th>
                <td>
                    <textarea name="internal_notes"
                              id="internal_notes"
                              rows="4"
                              class="large-text"><?php echo esc_textarea( $internal_notes ); ?></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="manager_notes"><?php _e( 'Notas del Gerente', 'lealez' ); ?></label></th>
                <td>
                    <textarea name="manager_notes"
                              id="manager_notes"
                              rows="4"
                              class="large-text"><?php echo esc_textarea( $manager_notes ); ?></textarea>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Technical Data / RAW Google metabox
     * Consolidates all RAW JSON objects from Google APIs for debugging/homologation
     */
    public function render_technical_data_meta_box( $post ) {
        $gmb_storefront_address_raw = get_post_meta( $post->ID, 'gmb_storefront_address_raw', true );
        $gmb_phone_numbers_raw      = get_post_meta( $post->ID, 'gmb_phone_numbers_raw', true );
        $gmb_regular_hours_raw      = get_post_meta( $post->ID, 'gmb_regular_hours_raw', true );
        $gmb_special_hours_raw      = get_post_meta( $post->ID, 'gmb_special_hours_raw', true );
        $gmb_more_hours_raw         = get_post_meta( $post->ID, 'gmb_more_hours_raw', true );
        $gmb_categories_raw         = get_post_meta( $post->ID, 'gmb_categories_raw', true );
        $gmb_attributes_raw         = get_post_meta( $post->ID, 'gmb_attributes_raw', true );
        $gmb_location_raw           = get_post_meta( $post->ID, 'gmb_location_raw', true );

        $raw_sections = array(
            'gmb_location_raw'           => array(
                'label' => __( 'Location Resource (Business Information API) — completo', 'lealez' ),
                'rows'  => 12,
                'data'  => $gmb_location_raw,
            ),
            'gmb_storefront_address_raw' => array(
                'label' => __( 'storefrontAddress', 'lealez' ),
                'rows'  => 6,
                'data'  => $gmb_storefront_address_raw,
            ),
            'gmb_phone_numbers_raw'      => array(
                'label' => __( 'phoneNumbers', 'lealez' ),
                'rows'  => 5,
                'data'  => $gmb_phone_numbers_raw,
            ),
            'gmb_regular_hours_raw'      => array(
                'label' => __( 'regularHours', 'lealez' ),
                'rows'  => 8,
                'data'  => $gmb_regular_hours_raw,
            ),
            'gmb_special_hours_raw'      => array(
                'label' => __( 'specialHours', 'lealez' ),
                'rows'  => 6,
                'data'  => $gmb_special_hours_raw,
            ),
            'gmb_more_hours_raw'         => array(
                'label' => __( 'moreHours', 'lealez' ),
                'rows'  => 6,
                'data'  => $gmb_more_hours_raw,
            ),
            'gmb_categories_raw'         => array(
                'label' => __( 'categories (primaryCategory + additionalCategories)', 'lealez' ),
                'rows'  => 6,
                'data'  => $gmb_categories_raw,
            ),
            'gmb_attributes_raw'         => array(
                'label' => __( 'attributes (array completo)', 'lealez' ),
                'rows'  => 8,
                'data'  => $gmb_attributes_raw,
            ),
        );

        $has_data = false;
        foreach ( $raw_sections as $sec ) {
            if ( ! empty( $sec['data'] ) ) {
                $has_data = true;
                break;
            }
        }
        ?>
        <style>
        .oy-raw-section { margin-bottom: 20px; }
        .oy-raw-section summary {
            cursor: pointer;
            font-weight: 600;
            color: #2271b1;
            padding: 6px 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
            font-size: 12px;
        }
        .oy-raw-section summary:hover { color: #135e96; }
        .oy-raw-section textarea {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
            font-size: 12px;
            margin-top: 4px;
        }
        </style>

        <?php if ( ! $has_data ) : ?>
            <p class="description">
                <em><?php _e( 'No hay datos RAW disponibles todavía. Los datos RAW se llenan automáticamente al importar la ubicación desde Google My Business.', 'lealez' ); ?></em>
            </p>
        <?php else : ?>
            <p class="description" style="margin-bottom:12px;">
                <?php _e( 'Datos JSON exactamente como los retorna Google Business Information API. Solo lectura. Se actualizan al importar o sincronizar.', 'lealez' ); ?>
            </p>
            <?php foreach ( $raw_sections as $key => $sec ) :
                $json_value = ! empty( $sec['data'] ) ? wp_json_encode( $sec['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : '';
                $is_empty = empty( $json_value );
                ?>
                <details class="oy-raw-section" <?php echo ! $is_empty ? 'open' : ''; ?>>
                    <summary>
                        <?php echo esc_html( $sec['label'] ); ?>
                        <?php if ( $is_empty ) : ?>
                            <span style="color:#999; font-weight:400;"> — <?php _e( 'sin datos', 'lealez' ); ?></span>
                        <?php endif; ?>
                    </summary>
                    <?php if ( ! $is_empty ) : ?>
                        <textarea readonly
                                  class="large-text"
                                  rows="<?php echo esc_attr( $sec['rows'] ); ?>"><?php echo esc_textarea( $json_value ); ?></textarea>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
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

            // Basic Info (closing_date removed from form but kept as meta)
            'location_code'                   => 'sanitize_text_field',
            'location_short_description'      => 'sanitize_textarea_field',
            'location_status'                 => 'sanitize_text_field',
            'opening_date'                    => 'sanitize_text_field',

            // Address (human)
            'service_area_only'               => 'absint',
            'show_address_to_customers'       => 'absint',
            'location_address_line1'          => 'sanitize_text_field',
            'location_address_line2'          => 'sanitize_text_field',
            'location_neighborhood'           => 'sanitize_text_field',
            'location_city'                   => 'sanitize_text_field',
            'location_state'                  => 'sanitize_text_field',
            'location_country'                => 'sanitize_text_field',
            'location_postal_code'            => 'sanitize_text_field',
            'location_latitude'               => 'sanitize_text_field',
            'location_longitude'              => 'sanitize_text_field',
            'location_place_id'               => 'sanitize_text_field', // kept as meta even though not in UI
            'location_plus_code'              => 'sanitize_text_field',

            // Contact (human)
            'location_phone'                  => 'sanitize_text_field',
            'location_chat_url'               => 'esc_url_raw',
            'location_email'                  => 'sanitize_email',
            'location_website'                => 'esc_url_raw',
            // NOTA: location_menu_url se guarda más abajo desde location_menu_url_gmb (POST name distinto
            // para evitar conflicto con el hidden field del menu metabox que corre en prioridad 15).
            // save_meta_boxes corre en prioridad 20, por lo que GANA sobre el menu metabox.
            // NOTA: location_booking_url y location_order_url se derivan de los arrays
            // location_booking_urls / location_order_urls (guardados más abajo).

            // Hours
            'location_hours_timezone'         => 'sanitize_text_field',
            'location_hours_status'           => 'sanitize_text_field',

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

            // Staff & Notes
            'location_manager'                => 'sanitize_text_field',
            'location_manager_email'          => 'sanitize_email',
            'location_manager_phone'          => 'sanitize_text_field',
            'internal_notes'                  => 'sanitize_textarea_field',
            'manager_notes'                   => 'sanitize_textarea_field',

            // Address extras
            'location_map_url'                => 'esc_url_raw',
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
                if ( in_array( $field_name, array( 'gmb_verified', 'gmb_auto_sync_enabled', 'accepts_loyalty', 'loyalty_redemption_enabled', 'loyalty_earning_enabled', 'gmb_import_on_save', 'service_area_only', 'show_address_to_customers' ), true ) ) {
                    delete_post_meta( $post_id, $field_name );
                } else {
                    // Para el resto, mantenemos comportamiento original
                    delete_post_meta( $post_id, $field_name );
                }
            }
        }

        // ✅ Save loyalty_programs_accepted (array of IDs)
        if ( isset( $_POST['loyalty_programs_accepted'] ) && is_array( $_POST['loyalty_programs_accepted'] ) ) {
            $program_ids = array_map( 'absint', wp_unslash( $_POST['loyalty_programs_accepted'] ) );
            $program_ids = array_filter( $program_ids ); // remove zeros
            update_post_meta( $post_id, 'loyalty_programs_accepted', $program_ids );
        } else {
            // No programs selected (all checkboxes unchecked)
            update_post_meta( $post_id, 'loyalty_programs_accepted', array() );
        }

        // ✅ Save additional phones (dynamic list from gmb_phone_additional_list[])
        if ( isset( $_POST['gmb_phone_additional_list'] ) && is_array( $_POST['gmb_phone_additional_list'] ) ) {
            $additional_phones = array_map(
                'sanitize_text_field',
                array_map( 'wp_unslash', $_POST['gmb_phone_additional_list'] )
            );
            $additional_phones = array_values( array_filter( $additional_phones ) );
            update_post_meta( $post_id, 'gmb_phone_additional_list', $additional_phones );

            // Backward compat: fill location_phone_additional with first entry
            if ( ! empty( $additional_phones ) ) {
                update_post_meta( $post_id, 'location_phone_additional', $additional_phones[0] );
            } else {
                delete_post_meta( $post_id, 'location_phone_additional' );
            }
        } else {
            update_post_meta( $post_id, 'gmb_phone_additional_list', array() );
            delete_post_meta( $post_id, 'location_phone_additional' );
        }

        // ✅ Save social profiles (manual entries from dynamic list)
        $social_networks_raw = isset( $_POST['social_profiles_manual_network'] ) && is_array( $_POST['social_profiles_manual_network'] )
            ? array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['social_profiles_manual_network'] ) )
            : array();
        $social_urls_raw     = isset( $_POST['social_profiles_manual_url'] ) && is_array( $_POST['social_profiles_manual_url'] )
            ? array_map( 'esc_url_raw', array_map( 'wp_unslash', $_POST['social_profiles_manual_url'] ) )
            : array();

        $social_profiles_manual = array();
        foreach ( $social_networks_raw as $idx => $net ) {
            if ( ! empty( $net ) && ! empty( $social_urls_raw[ $idx ] ) ) {
                $social_profiles_manual[ sanitize_key( $net ) ] = $social_urls_raw[ $idx ];
            }
        }
        update_post_meta( $post_id, 'social_profiles_manual', $social_profiles_manual );

        // Backward compat: keep old social_facebook_local / social_instagram_local
        if ( isset( $social_profiles_manual['facebook'] ) ) {
            update_post_meta( $post_id, 'social_facebook_local', $social_profiles_manual['facebook'] );
        }
        if ( isset( $social_profiles_manual['instagram'] ) ) {
            update_post_meta( $post_id, 'social_instagram_local', $social_profiles_manual['instagram'] );
        }

        // ✅ Save location_booking_urls (array dinámico de URLs de Reservas)
        // Estructura: [ ['url'=>'...','label'=>'...','type'=>'...','from_gmb'=>0], ... ]
        $booking_urls_raw = isset( $_POST['location_booking_urls'] ) && is_array( $_POST['location_booking_urls'] )
            ? wp_unslash( $_POST['location_booking_urls'] )
            : array();

        $booking_urls_clean = array();
        foreach ( $booking_urls_raw as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $burl  = esc_url_raw( (string) ( $entry['url']      ?? '' ) );
            $blbl  = sanitize_text_field( (string) ( $entry['label']    ?? '' ) );
            $btype = sanitize_text_field( (string) ( $entry['type']     ?? '' ) );
            $bfgmb = absint( $entry['from_gmb'] ?? 0 );
            if ( $burl ) {
                $booking_urls_clean[] = array(
                    'url'      => $burl,
                    'label'    => $blbl,
                    'type'     => $btype,
                    'from_gmb' => $bfgmb,
                );
            }
        }
        update_post_meta( $post_id, 'location_booking_urls', $booking_urls_clean );
        // Backward compat: mantener location_booking_url con la primera entrada
        if ( ! empty( $booking_urls_clean ) ) {
            update_post_meta( $post_id, 'location_booking_url', $booking_urls_clean[0]['url'] );
        } else {
            delete_post_meta( $post_id, 'location_booking_url' );
        }

        // ✅ Save location_order_urls (array dinámico de URLs para Ordenar Online)
        $order_urls_raw = isset( $_POST['location_order_urls'] ) && is_array( $_POST['location_order_urls'] )
            ? wp_unslash( $_POST['location_order_urls'] )
            : array();

        $order_urls_clean = array();
        foreach ( $order_urls_raw as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $ourl  = esc_url_raw( (string) ( $entry['url']      ?? '' ) );
            $olbl  = sanitize_text_field( (string) ( $entry['label']    ?? '' ) );
            $otype = sanitize_text_field( (string) ( $entry['type']     ?? '' ) );
            $ofgmb = absint( $entry['from_gmb'] ?? 0 );
            if ( $ourl ) {
                $order_urls_clean[] = array(
                    'url'      => $ourl,
                    'label'    => $olbl,
                    'type'     => $otype,
                    'from_gmb' => $ofgmb,
                );
            }
        }
        update_post_meta( $post_id, 'location_order_urls', $order_urls_clean );
        // Backward compat: mantener location_order_url con la primera entrada
        if ( ! empty( $order_urls_clean ) ) {
            update_post_meta( $post_id, 'location_order_url', $order_urls_clean[0]['url'] );
        } else {
            delete_post_meta( $post_id, 'location_order_url' );
        }

        // ✅ Save location_menu_url desde el campo editable del contact metabox.
        // POST name: location_menu_url_gmb (distinto del hidden field del menu metabox que usa
        // location_menu_url). Al correr en prioridad 20 (después del menu metabox en prioridad 15),
        // este valor SIEMPRE gana, preservando el control manual del usuario.
        if ( isset( $_POST['location_menu_url_gmb'] ) ) {
            $menu_url_val = esc_url_raw( wp_unslash( (string) $_POST['location_menu_url_gmb'] ) );
            update_post_meta( $post_id, 'location_menu_url', $menu_url_val );
            // Marcar si fue ingresado manualmente (vs auto-sync de GMB) — se limpia en gmb import
            if ( '' === $menu_url_val ) {
                delete_post_meta( $post_id, 'location_menu_url_from_gmb' );
            }
            // Nota: location_menu_url_from_gmb solo se escribe a '1' durante el import de GMB.
            // Si el usuario borra el campo manualmente, se limpia el flag de GMB.
        }

        // Save hours status (alineado con GMB: open_with_hours, open_without_hours, temporarily_closed, permanently_closed)
        $valid_hours_statuses = array( 'open_with_hours', 'open_without_hours', 'temporarily_closed', 'permanently_closed' );
        if ( isset( $_POST['location_hours_status'] ) ) {
            $hours_status_raw = sanitize_text_field( wp_unslash( $_POST['location_hours_status'] ) );
            if ( in_array( $hours_status_raw, $valid_hours_statuses, true ) ) {
                update_post_meta( $post_id, 'location_hours_status', $hours_status_raw );
            }
        }

        // Save hours (per day) — estructura v2 con periods[]
        // Formato POST: location_hours_{day}[closed]=1, location_hours_{day}[periods][0][open], [0][close], [1][open], etc.
        $days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        foreach ( $days as $day ) {
            if ( ! isset( $_POST[ 'location_hours_' . $day ] ) ) {
                continue;
            }
            $raw    = wp_unslash( $_POST[ 'location_hours_' . $day ] );
            $closed = ! empty( $raw['closed'] );

            $periods_raw = isset( $raw['periods'] ) && is_array( $raw['periods'] ) ? $raw['periods'] : array();

            // Normalizar y validar cada período
            $periods_clean = array();
            $is_all_day    = false;
            foreach ( $periods_raw as $praw ) {
                if ( ! is_array( $praw ) ) { continue; }
                $popen  = sanitize_text_field( $praw['open']  ?? '09:00' );
                $pclose = sanitize_text_field( $praw['close'] ?? '18:00' );
                if ( $popen === '24_hours' ) {
                    $is_all_day     = true;
                    $pclose         = '';
                    $periods_clean  = array( array( 'open' => '24_hours', 'close' => '' ) );
                    break; // 24h → un solo período, ignorar el resto
                }
                if ( empty( $popen ) ) { continue; }
                $periods_clean[] = array( 'open' => $popen, 'close' => $pclose );
            }

            if ( empty( $periods_clean ) ) {
                $periods_clean = array( array( 'open' => '09:00', 'close' => '18:00' ) );
            }

            // Backward-compat: guardar 'open'/'close' del primer período al nivel raíz
            $first_open  = $periods_clean[0]['open']  ?? '09:00';
            $first_close = $periods_clean[0]['close'] ?? '18:00';

            $hours_data = array(
                'closed'   => $closed,
                'all_day'  => $is_all_day,
                'periods'  => $periods_clean,
                // v1 compat keys:
                'open'     => $is_all_day ? '24_hours' : $first_open,
                'close'    => $is_all_day ? '' : $first_close,
            );
            update_post_meta( $post_id, 'location_hours_' . $day, $hours_data );
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

        // ✅ PLACE ACTIONS API — Se llama PRIMERO, antes de sync_location_data().
        // Razón: sync_location_data() puede fallar por rate limit (Business Information API)
        // y causar un return temprano que impediría que Place Actions API sea llamado nunca.
        // Llamándolo primero garantizamos que location_menu_url se guarda aunque
        // Business Information API esté temporalmente no disponible.
        // ─────────────────────────────────────────────────────────────────────────────────────
        // La My Business Place Actions API v1 es la fuente oficial de los "vínculos de acción":
        //   • "Editar perfil → Reserva"           → placeActionType APPOINTMENT / ONLINE_APPOINTMENT / DINING_RESERVATION
        //   • "Editar perfil → Menú"              → placeActionType MENU  ← location_menu_url
        //   • "Editar perfil → Ordenar en línea"  → placeActionType FOOD_ORDERING / FOOD_DELIVERY / FOOD_TAKEOUT / SHOP_ONLINE / ORDER_AHEAD
        // Endpoint: GET https://mybusinessplaceactions.googleapis.com/v1/locations/{id}/placeActionLinks
        // ─────────────────────────────────────────────────────────────────────────────────────
        if ( method_exists( 'Lealez_GMB_API', 'get_location_place_action_links' ) ) {

            // $use_cache = true: usa el WP transient (15 min) seteado previamente por el AJAX.
            // Esto evita una segunda llamada al API si el Import Now ya obtuvo los datos.
            $place_action_links = Lealez_GMB_API::get_location_place_action_links( $business_id, $location_name, true );

            if ( is_wp_error( $place_action_links ) ) {

                // ── Error real de API (403, rate limit, red, etc.) ──────────────────────────────
                $pa_error_msg  = $place_action_links->get_error_message();
                $pa_error_code = $place_action_links->get_error_code();

                $pa_error_data = array(
                    'code'      => $pa_error_code,
                    'message'   => $pa_error_msg,
                    'timestamp' => current_time( 'mysql' ),
                    'hint'      => 'Verifica que "My Business Place Actions API" esté habilitada en Google Cloud Console (console.cloud.google.com → APIs y servicios → Biblioteca). También confirma que el token OAuth tenga el scope https://www.googleapis.com/auth/business.manage',
                );
                update_post_meta( $post_id, 'gmb_place_actions_api_error', $pa_error_data );

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] Place Actions API ERROR (' . $pa_error_code . '): ' . $pa_error_msg );
                    error_log( '[OY Location] Place Actions API HINT: Habilita "My Business Place Actions API" en Google Cloud Console.' );
                }

            } elseif ( is_array( $place_action_links ) && ! empty( $place_action_links ) ) {

                // ── Éxito: hay links configurados ────────────────────────────────────────────────
                delete_post_meta( $post_id, 'gmb_place_actions_api_error' );
                update_post_meta( $post_id, 'gmb_place_action_links_raw', $place_action_links );

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] Place Actions API: ' . count( $place_action_links ) . ' link(s) encontrado(s).' );
                }

                $action_type_label_map = array(
                    'APPOINTMENT'        => __( 'Reservas', 'lealez' ),
                    'ONLINE_APPOINTMENT' => __( 'Cita online', 'lealez' ),
                    'DINING_RESERVATION' => __( 'Reserva de mesa', 'lealez' ),
                    'MENU'               => __( 'Menú', 'lealez' ),
                    'FOOD_ORDERING'      => __( 'Ordenar en línea', 'lealez' ),
                    'FOOD_DELIVERY'      => __( 'Domicilio', 'lealez' ),
                    'FOOD_TAKEOUT'       => __( 'Para llevar', 'lealez' ),
                    'SHOP_ONLINE'        => __( 'Tienda online', 'lealez' ),
                    'ORDER_AHEAD'        => __( 'Ordenar anticipado', 'lealez' ),
                    'ORDER_FOOD'         => __( 'Pedir comida', 'lealez' ),
                );

                $booking_types = array( 'APPOINTMENT', 'ONLINE_APPOINTMENT', 'DINING_RESERVATION' );
                $order_types   = array( 'FOOD_ORDERING', 'FOOD_DELIVERY', 'FOOD_TAKEOUT', 'SHOP_ONLINE', 'ORDER_AHEAD', 'ORDER_FOOD' );

                $gmb_booking_urls = array();
                $gmb_order_urls   = array();
                $gmb_menu_url     = '';

                foreach ( $place_action_links as $link ) {
                    if ( ! is_array( $link ) ) {
                        continue;
                    }

                    $uri         = ! empty( $link['uri'] ) ? esc_url_raw( (string) $link['uri'] ) : '';
                    $action_type = ! empty( $link['placeActionType'] ) ? strtoupper( trim( (string) $link['placeActionType'] ) ) : '';

                    if ( ! $uri || ! $action_type ) {
                        continue;
                    }

                    $label = isset( $action_type_label_map[ $action_type ] ) ? $action_type_label_map[ $action_type ] : $action_type;

                    if ( in_array( $action_type, $booking_types, true ) ) {
                        $already = array_filter( $gmb_booking_urls, fn( $e ) => $e['url'] === $uri );
                        if ( empty( $already ) ) {
                            $gmb_booking_urls[] = array(
                                'url'      => $uri,
                                'label'    => $label,
                                'type'     => $action_type,
                                'from_gmb' => 1,
                            );
                        }
                    } elseif ( in_array( $action_type, $order_types, true ) ) {
                        $already = array_filter( $gmb_order_urls, fn( $e ) => $e['url'] === $uri );
                        if ( empty( $already ) ) {
                            $gmb_order_urls[] = array(
                                'url'      => $uri,
                                'label'    => $label,
                                'type'     => $action_type,
                                'from_gmb' => 1,
                            );
                        }
                    } elseif ( 'MENU' === $action_type ) {
                        if ( ! $gmb_menu_url ) {
                            $gmb_menu_url = $uri;
                            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                error_log( '[OY Location] Place Actions API — MENU link encontrado: ' . $uri );
                            }
                        }
                    } else {
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[OY Location] Place Actions API — tipo no categorizado: ' . $action_type . ' (' . $uri . ')' );
                        }
                    }
                }

                // Guardar booking URLs
                if ( ! empty( $gmb_booking_urls ) ) {
                    $existing_booking = get_post_meta( $post_id, 'location_booking_urls', true );
                    if ( ! is_array( $existing_booking ) ) {
                        $existing_booking = array();
                    }
                    $manual_booking = array_values( array_filter( $existing_booking, fn( $e ) => empty( $e['from_gmb'] ) ) );
                    $merged_booking = array_merge( $gmb_booking_urls, $manual_booking );
                    update_post_meta( $post_id, 'location_booking_urls', $merged_booking );
                    update_post_meta( $post_id, 'location_booking_url', $merged_booking[0]['url'] );
                }

                // Guardar order URLs
                if ( ! empty( $gmb_order_urls ) ) {
                    $existing_order = get_post_meta( $post_id, 'location_order_urls', true );
                    if ( ! is_array( $existing_order ) ) {
                        $existing_order = array();
                    }
                    $manual_order = array_values( array_filter( $existing_order, fn( $e ) => empty( $e['from_gmb'] ) ) );
                    $merged_order = array_merge( $gmb_order_urls, $manual_order );
                    update_post_meta( $post_id, 'location_order_urls', $merged_order );
                    update_post_meta( $post_id, 'location_order_url', $merged_order[0]['url'] );
                }

                // ✅ Guardar menu URL — ESTE ES EL CAMPO CRÍTICO QUE FALLABA
                if ( $gmb_menu_url ) {
                    update_post_meta( $post_id, 'location_menu_url', $gmb_menu_url );
                    update_post_meta( $post_id, 'location_menu_url_from_gmb', 1 );
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[OY Location] ✅ location_menu_url guardado desde Place Actions API: ' . $gmb_menu_url );
                    }
                }

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    if ( empty( $gmb_booking_urls ) && empty( $gmb_order_urls ) && ! $gmb_menu_url ) {
                        error_log( '[OY Location] Place Actions API — links recibidos pero ninguno tiene tipo reconocido.' );
                    }
                }

            } else {

                // ── Respuesta vacía ────────────────────────────────────────────────────────────
                delete_post_meta( $post_id, 'gmb_place_actions_api_error' );

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] Place Actions API — respuesta vacía. El negocio no tiene vínculos de acción configurados en Google Business Profile.' );
                }
            }

        } else {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] AVISO: Lealez_GMB_API::get_location_place_action_links() no disponible. Actualiza class-lealez-gmb-api.php.' );
            }
        }
        // ── Fin bloque Place Actions (ejecutado ANTES de sync_location_data) ──────────────

$data = Lealez_GMB_API::sync_location_data( $business_id, $location_name );
if ( is_wp_error( $data ) || ! is_array( $data ) ) {
    // No rompemos el save: solo registramos last error
    // NOTA: Place Actions (menu URL, booking URLs) ya fueron guardados arriba si el API respondió.
    update_post_meta( $post_id, 'gmb_last_import_error', is_wp_error( $data ) ? $data->get_error_message() : 'Unknown error' );
    return;
}

// ✅ Cargar atributos desde el endpoint dedicado de Business Information API v1.
// IMPORTANTE: Siempre se llama, independientemente de si $data['attributes'] ya tiene datos,
// porque sync_location_data() NO incluye 'attributes' en su readMask. El endpoint
// GET /v1/locations/{id}/attributes es la fuente oficial para: url_menu, url_whatsapp,
// url_instagram, url_facebook, etc.
// Ref: https://developers.google.com/my-business/content/attributes
// Se usa use_cache=true para aprovechar el transient (15 min) generado por el AJAX previo.
$attributes_result = Lealez_GMB_API::get_location_attributes( $business_id, $location_name, true );
if ( ! is_wp_error( $attributes_result ) && is_array( $attributes_result ) && ! empty( $attributes_result ) ) {
    $data['attributes'] = $attributes_result;
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[OY Location] Attributes loaded via dedicated endpoint: ' . count( $attributes_result ) . ' attribute(s).' );
    }
} else {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $err_msg = is_wp_error( $attributes_result ) ? $attributes_result->get_error_message() : 'empty response';
        error_log( '[OY Location] get_location_attributes failed or returned empty: ' . $err_msg );
    }
    if ( ! isset( $data['attributes'] ) ) {
        $data['attributes'] = array();
    }
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
            remove_action( 'save_post_oy_location', array( $this, 'save_meta_boxes' ), 20 );
            wp_update_post( array(
                'ID'         => $post_id,
                'post_title' => $title,
            ) );
            add_action( 'save_post_oy_location', array( $this, 'save_meta_boxes' ), 20, 2 );
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

        // openInfo (RAW + map status + openingHoursType)
        $open_info_raw = isset( $data['openInfo'] ) && is_array( $data['openInfo'] ) ? $data['openInfo'] : array();
        $open_status   = strtoupper( (string) ( $open_info_raw['status']           ?? '' ) );
        $hours_type    = strtoupper( (string) ( $open_info_raw['openingHoursType'] ?? '' ) );

        if ( ! empty( $open_info_raw ) ) {
            update_post_meta( $post_id, 'gmb_open_info_raw', $open_info_raw );
        }

        // ── Detectar ALWAYS_OPEN (24/7) desde openInfo.openingHoursType ──────────
        // Cuando Google tiene configurado "Abierto las 24 horas" para todos los días,
        // puede retornar openingHoursType = "ALWAYS_OPEN" con regularHours vacío o
        // con períodos que representan 24h. Manejamos ambos casos.
        $is_always_open = ( $hours_type === 'ALWAYS_OPEN' );

        // ── Mapear openInfo.status → location_hours_status ────────────────────────
        if ( $open_status === 'CLOSED_TEMPORARILY' ) {
            update_post_meta( $post_id, 'location_hours_status', 'temporarily_closed' );
        } elseif ( $open_status === 'CLOSED_PERMANENTLY' ) {
            update_post_meta( $post_id, 'location_hours_status', 'permanently_closed' );
        } else {
            // OPEN o vacío: distinguir si tiene horarios publicados o no
            $has_periods = ! empty( $regular['periods'] );
            update_post_meta( $post_id, 'location_hours_status', ( $has_periods || $is_always_open ) ? 'open_with_hours' : 'open_without_hours' );
        }

        // ── Guardar y mapear horarios ─────────────────────────────────────────────
        if ( $is_always_open ) {
            // Caso ALWAYS_OPEN: todos los días son 24 horas
            update_post_meta( $post_id, 'gmb_regular_hours_raw', array( 'openingHoursType' => 'ALWAYS_OPEN' ) );
            $mapped_all_open = $this->build_always_open_daily_meta();
            foreach ( $mapped_all_open as $day_key => $hours_data ) {
                update_post_meta( $post_id, 'location_hours_' . $day_key, $hours_data );
            }
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] ALWAYS_OPEN detectado → todos los días marcados como 24 horas.' );
            }
        } elseif ( ! empty( $regular ) ) {
            update_post_meta( $post_id, 'gmb_regular_hours_raw', $regular );

            $mapped = $this->map_gmb_regular_hours_to_daily_meta( $regular );
            if ( is_array( $mapped ) && ! empty( $mapped ) ) {
                foreach ( $mapped as $day_key => $hours_data ) {
                    update_post_meta( $post_id, 'location_hours_' . $day_key, $hours_data );
                }
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] regularHours mapeados: ' . wp_json_encode( $mapped ) );
                }
            }
        }

        // specialHours / moreHours (RAW — solo lectura en UI, editar desde Google Business Profile)
        $special = isset( $data['specialHours'] ) && is_array( $data['specialHours'] ) ? $data['specialHours'] : array();
        if ( ! empty( $special ) ) {
            update_post_meta( $post_id, 'gmb_special_hours_raw', $special );
        }
        $more = isset( $data['moreHours'] ) && is_array( $data['moreHours'] ) ? $data['moreHours'] : array();
        if ( ! empty( $more ) ) {
            update_post_meta( $post_id, 'gmb_more_hours_raw', $more );
        }

        // ── Detección de "Sin ubicación física" (service_area_only) ──────────────
        // Se evalúa SIEMPRE, independiente de si openInfo viene en la respuesta.
        // Espeja la lógica de GMB "Ubicación de la empresa":
        //   - Sin storefrontAddress con datos reales → negocio de servicio a domicilio/online
        //   - Con storefrontAddress → negocio con local físico
        $addr_raw        = isset( $data['storefrontAddress'] ) && is_array( $data['storefrontAddress'] ) ? $data['storefrontAddress'] : array();
        $has_storefront  = ! empty( $addr_raw ) && (
            ! empty( $addr_raw['addressLines'][0] ) ||
            ! empty( $addr_raw['locality'] )         ||
            ! empty( $addr_raw['regionCode'] )
        );

        if ( ! $has_storefront ) {
            // Sin dirección física: marcar como solo-servicio
            update_post_meta( $post_id, 'service_area_only', '1' );
            // Aseguramos que "mostrar dirección" quede en false para no confundir la UI
            update_post_meta( $post_id, 'show_address_to_customers', '0' );
        } else {
            // Con dirección física: siempre actualizar (override valores anteriores)
            update_post_meta( $post_id, 'service_area_only', '0' );
        }

        // serviceArea RAW — para negocios sin local físico (área de cobertura)
        if ( ! empty( $data['serviceArea'] ) && is_array( $data['serviceArea'] ) ) {
            update_post_meta( $post_id, 'gmb_service_area_raw', $data['serviceArea'] );
            update_post_meta( $post_id, 'service_area_enabled', '1' );
        }
        // ── Fin bloque service_area_only ─────────────────────────────────────────
        
        if ( ! empty( $data['locationState'] ) && is_array( $data['locationState'] ) ) {
            update_post_meta( $post_id, 'gmb_location_state_raw', $data['locationState'] );
        }
        if ( ! empty( $data['metadata'] ) && is_array( $data['metadata'] ) ) {
            update_post_meta( $post_id, 'gmb_metadata_raw', $data['metadata'] );

            // ✅ Map metadata.mapsUri → location_map_url
            if ( ! empty( $data['metadata']['mapsUri'] ) ) {
                update_post_meta( $post_id, 'location_map_url', esc_url_raw( (string) $data['metadata']['mapsUri'] ) );
            }

            // placeId → location_place_id
            if ( ! empty( $data['metadata']['placeId'] ) ) {
                update_post_meta( $post_id, 'location_place_id', sanitize_text_field( (string) $data['metadata']['placeId'] ) );
            }

            // newReviewUri → google_reviews_url (si el campo está vacío)
            if ( ! empty( $data['metadata']['newReviewUri'] ) ) {
                $existing_reviews_url = (string) get_post_meta( $post_id, 'google_reviews_url', true );
                if ( '' === $existing_reviews_url ) {
                    update_post_meta( $post_id, 'google_reviews_url', esc_url_raw( (string) $data['metadata']['newReviewUri'] ) );
                }
            }
        }

        /**
         * ✅ FALLBACK URL de Google Maps:
         * Si metadata.mapsUri no llegó (p.ej. readMask cayó a un nivel sin metadata),
         * generamos la URL desde placeId o desde las coordenadas latlng.
         * Esto garantiza que location_map_url siempre tenga un valor válido.
         */
        $existing_map_url = (string) get_post_meta( $post_id, 'location_map_url', true );
        if ( '' === $existing_map_url ) {

            // Opción 1: usar placeId (más preciso)
            $place_id = (string) get_post_meta( $post_id, 'location_place_id', true );
            if ( '' === $place_id && ! empty( $data['metadata']['placeId'] ) ) {
                $place_id = sanitize_text_field( (string) $data['metadata']['placeId'] );
            }
            if ( $place_id ) {
                $fallback_url = 'https://www.google.com/maps/place/?q=place_id:' . rawurlencode( $place_id );
                update_post_meta( $post_id, 'location_map_url', esc_url_raw( $fallback_url ) );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] location_map_url: fallback generada desde placeId: ' . $fallback_url );
                }
            } else {
                // Opción 2: usar coordenadas latlng
                $lat = ! empty( $data['latlng']['latitude'] )  ? (float) $data['latlng']['latitude']  : 0;
                $lng = ! empty( $data['latlng']['longitude'] ) ? (float) $data['latlng']['longitude'] : 0;
                if ( $lat && $lng ) {
                    $fallback_url = 'https://www.google.com/maps/search/?api=1&query=' . $lat . ',' . $lng;
                    update_post_meta( $post_id, 'location_map_url', esc_url_raw( $fallback_url ) );
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[OY Location] location_map_url: fallback generada desde latlng: ' . $fallback_url );
                    }
                }
            }
        }
        if ( ! empty( $data['labels'] ) && is_array( $data['labels'] ) ) {
            update_post_meta( $post_id, 'gmb_labels_raw', $data['labels'] );
        }

        // ✅ Map profile.description → location_short_description (GMB description, max 750 chars)
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[OY Location] Profile field in API response: ' . ( isset( $data['profile'] ) ? wp_json_encode( $data['profile'] ) : 'NOT PRESENT' ) );
        }
        if ( ! empty( $data['profile'] ) && is_array( $data['profile'] ) ) {
            update_post_meta( $post_id, 'gmb_profile_raw', $data['profile'] );
            if ( ! empty( $data['profile']['description'] ) ) {
                $gmb_description = sanitize_textarea_field( (string) $data['profile']['description'] );
                $gmb_description = mb_substr( $gmb_description, 0, 750 );
                update_post_meta( $post_id, 'location_short_description', $gmb_description );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] Profile description saved (' . mb_strlen( $gmb_description ) . ' chars): ' . mb_substr( $gmb_description, 0, 80 ) . '...' );
                }
            } else {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] Profile present but description is empty in API response.' );
                }
            }
        } else {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] Profile NOT in API response – description field will remain empty.' );
            }
        }

        // ✅ NUEVO: Guardar attributes desde GMB
        if ( ! empty( $data['attributes'] ) && is_array( $data['attributes'] ) ) {
            update_post_meta( $post_id, 'gmb_attributes_raw', $data['attributes'] );

// ✅ Extract social media profiles from uriValues attributes
            // ─────────────────────────────────────────────────────────────────────
            // Business Information API v1 devuelve atributos con el campo 'name'
            // en formato 'locations/{id}/attributes/url_facebook'. NO hay campo
            // 'attributeId' en este endpoint. Se normaliza igual que en el bloque
            // de WhatsApp más abajo.
            // ─────────────────────────────────────────────────────────────────────
            $social_uri_map = array(
                'url_facebook'  => 'facebook',
                'url_instagram' => 'instagram',
                'url_twitter'   => 'twitter',
                'url_linkedin'  => 'linkedin',
                'url_youtube'   => 'youtube',
                'url_tiktok'    => 'tiktok',
                'url_pinterest' => 'pinterest',
            );
            $gmb_social_profiles = array();

            foreach ( $data['attributes'] as $attr ) {
                if ( ! is_array( $attr ) ) {
                    continue;
                }

                // ── Normalizar el ID del atributo (mismo patrón que WhatsApp) ──
                $attr_id_raw = '';
                if ( ! empty( $attr['attributeId'] ) ) {
                    // Formato legacy / algunos wrappers
                    $attr_id_raw = (string) $attr['attributeId'];
                } elseif ( ! empty( $attr['name'] ) ) {
                    // Formato real de Business Information API v1:
                    // "locations/{id}/attributes/url_facebook"
                    $name_str    = (string) $attr['name'];
                    $parts       = explode( '/attributes/', $name_str );
                    $attr_id_raw = trim( end( $parts ), '/' );
                }

                if ( '' === $attr_id_raw ) {
                    continue;
                }

                $attr_id_lower = strtolower( $attr_id_raw );

                foreach ( $social_uri_map as $gmb_key => $network_key ) {
                    // Coincidencia exacta o que el ID contenga la clave (p.ej. "url_facebook" dentro de un nombre compuesto)
                    if ( $attr_id_lower === $gmb_key || strpos( $attr_id_lower, $gmb_key ) !== false ) {
                        if ( ! empty( $attr['uriValues'] ) && is_array( $attr['uriValues'] ) ) {
                            $uri = isset( $attr['uriValues'][0]['uri'] ) ? esc_url_raw( (string) $attr['uriValues'][0]['uri'] ) : '';
                            if ( $uri ) {
                                $gmb_social_profiles[ $network_key ] = $uri;
                                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                    error_log( '[OY Location] Social profile found: ' . $network_key . ' = ' . $uri . ' (attr id: ' . $attr_id_raw . ')' );
                                }
                            }
                        }
                        break;
                    }
                }
            }

            if ( ! empty( $gmb_social_profiles ) ) {
                update_post_meta( $post_id, 'gmb_social_profiles_raw', $gmb_social_profiles );
                // Backward compat: mantener campos individuales
                if ( isset( $gmb_social_profiles['facebook'] ) ) {
                    update_post_meta( $post_id, 'social_facebook_local', $gmb_social_profiles['facebook'] );
                }
                if ( isset( $gmb_social_profiles['instagram'] ) ) {
                    update_post_meta( $post_id, 'social_instagram_local', $gmb_social_profiles['instagram'] );
                }
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] gmb_social_profiles_raw saved: ' . wp_json_encode( $gmb_social_profiles ) );
                }
            } else {
                // Limpiar meta si GMB ya no tiene redes sociales configuradas
                delete_post_meta( $post_id, 'gmb_social_profiles_raw' );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] No social profiles found in GMB attributes.' );
                }
            }

// ✅ Extraer URL de chat (WhatsApp / SMS) desde atributos GMB
            // ─────────────────────────────────────────────────────────────────────
            // Según la documentación oficial de Google Business Profile API:
            //   - Atributo WhatsApp : url_whatsapp
            //   - Atributo SMS/texto: url_text_messaging
            // https://developers.google.com/my-business/content/whatsapp-text
            //
            // La API puede devolver el atributo con el campo 'attributeId' (solo el ID)
            // O con el campo 'name' con formato 'attributes/url_whatsapp'
            // o 'locations/{id}/attributes/url_whatsapp'. Ambos formatos se normalizan.
            // ─────────────────────────────────────────────────────────────────────

            // IDs oficiales de Google para chat, en orden de prioridad
            $chat_official_ids = array(
                'url_whatsapp',       // WhatsApp — ID oficial
                'url_text_messaging', // SMS/texto — ID oficial
                'url_text_messaging3',// Variante documentada en ejemplos de Google
            );

            $gmb_chat_url     = '';
            $gmb_chat_type    = ''; // 'whatsapp' | 'sms' | ''

            foreach ( $data['attributes'] as $attr ) {
                if ( ! is_array( $attr ) ) {
                    continue;
                }

                // ── Normalizar el ID del atributo ──────────────────────────────
                // La API puede devolver 'attributeId' directamente (ej: "url_whatsapp")
                // o 'name' con prefijo (ej: "attributes/url_whatsapp" o
                // "locations/123456/attributes/url_whatsapp").
                $attr_id_raw = '';
                if ( ! empty( $attr['attributeId'] ) ) {
                    $attr_id_raw = (string) $attr['attributeId'];
                } elseif ( ! empty( $attr['name'] ) ) {
                    // Extraer solo la parte después del último 'attributes/'
                    $name_str    = (string) $attr['name'];
                    $parts       = explode( '/attributes/', $name_str );
                    $attr_id_raw = trim( end( $parts ), '/' );
                }

                if ( '' === $attr_id_raw ) {
                    continue;
                }

                $attr_id_lower = strtolower( $attr_id_raw );

                // ── Verificar si es un atributo de chat conocido ───────────────
                $is_chat_attr = false;
                foreach ( $chat_official_ids as $official_id ) {
                    if ( $attr_id_lower === $official_id ) {
                        $is_chat_attr = true;
                        break;
                    }
                }

                // ── Fallback robusto: cualquier attr con 'whatsapp' en el nombre y uriValues
                if ( ! $is_chat_attr && strpos( $attr_id_lower, 'whatsapp' ) !== false && ! empty( $attr['uriValues'] ) ) {
                    $is_chat_attr = true;
                }

                if ( ! $is_chat_attr ) {
                    continue;
                }

                // ── Extraer el URI del atributo ────────────────────────────────
                if ( ! empty( $attr['uriValues'] ) && is_array( $attr['uriValues'] ) ) {
                    $uri = isset( $attr['uriValues'][0]['uri'] ) ? esc_url_raw( (string) $attr['uriValues'][0]['uri'] ) : '';
                    if ( $uri ) {
                        $gmb_chat_url  = $uri;
                        $gmb_chat_type = ( strpos( $attr_id_lower, 'whatsapp' ) !== false ) ? 'whatsapp' : 'sms';
                        break; // Tomamos el primero válido (WhatsApp tiene prioridad por orden del array)
                    }
                }
            }

            // ── Guardar y mapear el chat URL ───────────────────────────────────
            if ( $gmb_chat_url ) {
                // Guardar raw para auditoría/debug
                update_post_meta( $post_id, 'gmb_chat_url_raw', $gmb_chat_url );
                update_post_meta( $post_id, 'gmb_chat_type', $gmb_chat_type );

                // Siempre actualizar location_chat_url desde GMB (es la fuente de verdad)
                update_post_meta( $post_id, 'location_chat_url', $gmb_chat_url );

                // Backward compat: también llenar el campo legacy location_whatsapp
                if ( 'whatsapp' === $gmb_chat_type ) {
                    update_post_meta( $post_id, 'location_whatsapp', $gmb_chat_url );
                }

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] GMB chat URL (' . $gmb_chat_type . ') guardada: ' . $gmb_chat_url );
                }
            } else {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] GMB chat URL: ningún atributo url_whatsapp / url_text_messaging encontrado en la respuesta.' );
                }
            }

// ✅ Extraer URLs desde atributos GMB (Business Information API v1 — Attributes endpoint).
            // ─────────────────────────────────────────────────────────────────────────────────────────
            // FUENTES Y PRIORIDAD:
            //   • url_menu        → FUENTE PRINCIPAL para location_menu_url (campo "Vínculo del menú
            //                       o los servicios" en la sección Contacto de GBP — igual que redes
            //                       sociales). Siempre sobreescribe lo que Place Actions haya guardado.
            //   • url_appointment → FALLBACK para booking si Place Actions no devolvió ninguno.
            //   • url_order_ahead → FALLBACK para order si Place Actions no devolvió ninguno.
            //
            // MATCHING: Para url_menu se usa strpos('menu') en lugar de igualdad exacta, cubriendo
            // variantes del atributo: url_menu, url_food_menu, menu_url, etc.
            // ─────────────────────────────────────────────────────────────────────────────────────────

            $attr_fallback_labels = array(
                'url_appointment' => __( 'Reservas', 'lealez' ),
                'url_order_ahead' => __( 'Ordenar en línea', 'lealez' ),
            );

            // Mapa EXACTO para booking y order (no cambian de nombre)
            $url_attr_exact_map = array(
                'url_appointment' => 'booking',
                'url_order_ahead' => 'order',
            );

            foreach ( $data['attributes'] as $attr ) {
                if ( ! is_array( $attr ) ) {
                    continue;
                }

                // Normalizar el ID / nombre del atributo
                $attr_id_raw  = '';
                $attr_name_full = '';
                if ( ! empty( $attr['attributeId'] ) ) {
                    $attr_id_raw    = (string) $attr['attributeId'];
                    $attr_name_full = $attr_id_raw;
                } elseif ( ! empty( $attr['name'] ) ) {
                    $attr_name_full = (string) $attr['name'];
                    $parts          = explode( '/attributes/', $attr_name_full );
                    $attr_id_raw    = trim( end( $parts ), '/' );
                }

                if ( '' === $attr_id_raw ) {
                    continue;
                }

                $attr_id_lower    = strtolower( $attr_id_raw );
                $attr_name_lower  = strtolower( $attr_name_full );

                // ── CASO ESPECIAL: url_menu → fuente principal del campo "Vínculo del menú" ──────
                // Se usa strpos para capturar variantes: url_menu, url_food_menu, menu_url, etc.
                // Solo se procesa si tiene uriValues (es un atributo de tipo URL).
                if ( false !== strpos( $attr_name_lower, 'menu' ) || false !== strpos( $attr_id_lower, 'menu' ) ) {
                    $menu_uri = '';
                    if ( ! empty( $attr['uriValues'] ) && is_array( $attr['uriValues'] ) ) {
                        $menu_uri = isset( $attr['uriValues'][0]['uri'] ) ? esc_url_raw( (string) $attr['uriValues'][0]['uri'] ) : '';
                    }
                    if ( $menu_uri ) {
                        // FUENTE PRINCIPAL: siempre sobreescribe, incluso si Place Actions ya guardó algo.
                        // "Vínculo del menú o los servicios" en GBP Contacto = este atributo.
                        update_post_meta( $post_id, 'location_menu_url', $menu_uri );
                        update_post_meta( $post_id, 'location_menu_url_from_gmb', 1 );
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( sprintf(
                                '[OY Location] ✅ Atributo "%s" (menú) → location_menu_url: %s',
                                $attr_id_raw,
                                $menu_uri
                            ) );
                        }
                    }
                    continue; // Atributo de menú procesado, pasar al siguiente atributo
                }

                // ── FALLBACK para booking y order: solo si Place Actions no los aportó ────────────
                foreach ( $url_attr_exact_map as $gmb_id => $category ) {
                    if ( $attr_id_lower !== $gmb_id ) {
                        continue;
                    }

                    $uri = '';
                    if ( ! empty( $attr['uriValues'] ) && is_array( $attr['uriValues'] ) ) {
                        $uri = isset( $attr['uriValues'][0]['uri'] ) ? esc_url_raw( (string) $attr['uriValues'][0]['uri'] ) : '';
                    }

                    if ( ! $uri ) {
                        break;
                    }

                    if ( 'booking' === $category ) {
                        $existing = get_post_meta( $post_id, 'location_booking_urls', true );
                        if ( empty( $existing ) || ! is_array( $existing ) ) {
                            $fallback_entry = array(
                                'url'      => $uri,
                                'label'    => $attr_fallback_labels[ $gmb_id ] ?? __( 'Reservas', 'lealez' ),
                                'type'     => 'APPOINTMENT',
                                'from_gmb' => 1,
                            );
                            update_post_meta( $post_id, 'location_booking_urls', array( $fallback_entry ) );
                            update_post_meta( $post_id, 'location_booking_url', $uri );
                            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                error_log( sprintf( '[OY Location] Fallback atributo %s → location_booking_urls: %s', $gmb_id, $uri ) );
                            }
                        } else {
                            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                error_log( '[OY Location] Fallback atributo ' . $gmb_id . ' ignorado: location_booking_urls ya tiene entradas de Place Actions API.' );
                            }
                        }
                    } elseif ( 'order' === $category ) {
                        $existing = get_post_meta( $post_id, 'location_order_urls', true );
                        if ( empty( $existing ) || ! is_array( $existing ) ) {
                            $fallback_entry = array(
                                'url'      => $uri,
                                'label'    => $attr_fallback_labels[ $gmb_id ] ?? __( 'Ordenar en línea', 'lealez' ),
                                'type'     => 'ORDER_AHEAD',
                                'from_gmb' => 1,
                            );
                            update_post_meta( $post_id, 'location_order_urls', array( $fallback_entry ) );
                            update_post_meta( $post_id, 'location_order_url', $uri );
                            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                error_log( sprintf( '[OY Location] Fallback atributo %s → location_order_urls: %s', $gmb_id, $uri ) );
                            }
                        } else {
                            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                error_log( '[OY Location] Fallback atributo ' . $gmb_id . ' ignorado: location_order_urls ya tiene entradas de Place Actions API.' );
                            }
                        }
                    }

                    break;
                }
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // Log resumen final de URLs de acción (todas las fuentes combinadas)
                $found_booking = get_post_meta( $post_id, 'location_booking_urls', true );
                $found_order   = get_post_meta( $post_id, 'location_order_urls', true );
                $found_menu    = get_post_meta( $post_id, 'location_menu_url', true );
                $summary_parts = array();
                if ( ! empty( $found_booking ) ) {
                    $summary_parts[] = 'booking_urls=' . count( $found_booking );
                }
                if ( ! empty( $found_order ) ) {
                    $summary_parts[] = 'order_urls=' . count( $found_order );
                }
                if ( $found_menu ) {
                    $summary_parts[] = 'menu_url=1';
                }
                if ( ! empty( $summary_parts ) ) {
                    error_log( '[OY Location] URLs de acción finales (Place Actions + fallback): ' . implode( ', ', $summary_parts ) );
                } else {
                    error_log( '[OY Location] Sin URLs de acción: no encontradas en Place Actions API ni en atributos GMB.' );
                }
            }
        }

// ✅ VERIFICACIÓN (SIEMPRE desde My Business Verifications API)
// -----------------------------------------------------------------
// 1) Si el payload ya trae "verification", lo usamos como fallback
// 2) PERO siempre intentamos refrescar usando Verifications API
//    porque sync_location_data normalmente viene de Business Information API.
$verification_payload = array();

// Fallback desde payload (si existe)
if ( ! empty( $data['verification'] ) && is_array( $data['verification'] ) ) {
    $verification_payload = $data['verification'];
}

// Refresco real desde Verifications API
$location_id_for_verification = $this->extract_location_id_from_resource_name( $location_name );

if ( ! empty( $location_id_for_verification ) && class_exists( 'Lealez_GMB_API' ) && method_exists( 'Lealez_GMB_API', 'get_location_verification_state' ) ) {

    $verification_api = Lealez_GMB_API::get_location_verification_state( $business_id, $location_id_for_verification, true );

    if ( is_array( $verification_api ) && ! empty( $verification_api ) ) {
        $verification_payload = $verification_api;

        // Para debugging controlado
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[OY Location Import] Verification refreshed from Verifications API: ' . print_r( $verification_payload, true ) );
        }
    } else {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[OY Location Import] Verifications API returned empty for location_id: ' . $location_id_for_verification );
        }
    }
} else {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[OY Location Import] Cannot refresh verification: missing location_id or Lealez_GMB_API::get_location_verification_state not available.' );
    }
}

// Guardar verificación normalizada
if ( ! empty( $verification_payload ) && is_array( $verification_payload ) ) {

    $state = isset( $verification_payload['state'] ) ? strtoupper( (string) $verification_payload['state'] ) : '';

    if ( $state ) {
        update_post_meta( $post_id, 'gmb_verification_state', sanitize_text_field( $state ) );

        // ✅ Backward compatibility boolean
        // Verifications API usa COMPLETED para indicar verificación completada.
        // Para compatibilidad: VERIFIED o COMPLETED => verificado real.
        $is_verified = in_array( $state, array( 'VERIFIED', 'COMPLETED' ), true );
        update_post_meta( $post_id, 'gmb_verified', $is_verified ? 1 : 0 );
    }

    if ( ! empty( $verification_payload['name'] ) ) {
        update_post_meta( $post_id, 'gmb_verification_name', sanitize_text_field( (string) $verification_payload['name'] ) );
    }

    if ( ! empty( $verification_payload['createTime'] ) ) {
        update_post_meta( $post_id, 'gmb_verification_create_time', sanitize_text_field( (string) $verification_payload['createTime'] ) );
    }

} else {
    // Si no hay datos de verificación, no borramos meta para no perder historial;
    // simplemente registramos debug.
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[OY Location Import] No verification payload available after refresh attempt. post_id=' . $post_id );
    }
}



        // ✅ FOOD MENUS: Importar menú estructurado (secciones + items) desde API v4
        // ─────────────────────────────────────────────────────────────────────────────
        // La API v4 de Google My Business expone el menú estructurado en:
        // GET /v4/accounts/{accountId}/locations/{locationId}/foodMenus
        // Solo disponible para negocios de categoría restaurante/comida.
        // Documenta: secciones, productos, precios, descripciones y restricciones dietarias.
        // NOTA: Las fotos de cada item vienen como mediaKeys (referencias), no URLs.
        //       Las URLs reales se resuelven en el bloque FOOD PHOTOS cruzando con el Media API.
        // ─────────────────────────────────────────────────────────────────────────────

        // Variable compartida con el bloque FOOD PHOTOS para enriquecer secciones con URLs de foto
        $fm_mapped_sections_for_photo_resolve = array();

        if ( ! empty( $account_id ) && ! empty( $location_id ) && method_exists( 'Lealez_GMB_API', 'get_location_food_menus' ) ) {

            $food_menus_result = Lealez_GMB_API::get_location_food_menus(
                $business_id,
                $account_id,
                $location_id,
                true // force_refresh: siempre fresco durante el import
            );

            if ( is_wp_error( $food_menus_result ) ) {

                $fm_error_data = array(
                    'code'      => $food_menus_result->get_error_code(),
                    'message'   => $food_menus_result->get_error_message(),
                    'timestamp' => current_time( 'mysql' ),
                );
                update_post_meta( $post_id, 'gmb_food_menus_api_error', $fm_error_data );
                delete_post_meta( $post_id, 'gmb_food_menus_raw' );

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] Food Menus API ERROR (' . $food_menus_result->get_error_code() . '): ' . $food_menus_result->get_error_message() );
                }

            } elseif ( ! empty( $food_menus_result['menus'] ) ) {

                // ── Éxito: hay menú configurado en GMB ─────────────────────────────
                delete_post_meta( $post_id, 'gmb_food_menus_api_error' );
                update_post_meta( $post_id, 'gmb_food_menus_raw', $food_menus_result );
                update_post_meta( $post_id, 'gmb_food_menus_last_sync', time() );

                // Mapear a formato interno de secciones (con media_keys por item, sin URLs aún)
                $mapped_sections = $this->map_gmb_food_menus_to_sections( $food_menus_result );

                if ( ! empty( $mapped_sections ) ) {
                    // Guardar provisoriamente sin foto de item (se enriquece tras el bloque Media)
                    $fm_mapped_sections_for_photo_resolve = $mapped_sections;
                    update_post_meta( $post_id, 'location_menu_sections', $mapped_sections );
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[OY Location] Food menus imported: ' . count( $mapped_sections ) . ' sección(es) (fotos de item pendientes de resolución).' );
                    }
                }

            } else {

                // ── Respuesta vacía: negocio sin menú o no es restaurante ───────────
                delete_post_meta( $post_id, 'gmb_food_menus_api_error' );
                update_post_meta( $post_id, 'gmb_food_menus_raw', $food_menus_result );
                update_post_meta( $post_id, 'gmb_food_menus_last_sync', time() );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] Food Menus API — respuesta vacía. El negocio no tiene menú configurado en Google o no es de tipo restaurante.' );
                }
            }

        } else {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                if ( empty( $account_id ) || empty( $location_id ) ) {
                    error_log( '[OY Location] Food Menus API — skipped: account_id o location_id vacíos.' );
                } elseif ( ! method_exists( 'Lealez_GMB_API', 'get_location_food_menus' ) ) {
                    error_log( '[OY Location] AVISO: Lealez_GMB_API::get_location_food_menus() no disponible. Actualiza class-lealez-gmb-api.php.' );
                }
            }
        }

        // ✅ FOOD PHOTOS: Importar fotos de comida/menú desde Media API v4
        // ─────────────────────────────────────────────────────────────────────────────
        // El Media API v4 devuelve todos los items de media de la ubicación.
        // Filtramos los que tienen locationAssociation.category = FOOD_AND_DRINK o MENU
        // para guardarlos en gmb_menu_photos_raw (galería del metabox de Menú).
        //
        // RESOLUCIÓN DE FOTOS POR ITEM: Adicionalmente, cada ítem del foodMenu tiene
        // mediaKeys (referencias cortas tipo "AF1Qip..."). Este bloque cruza esas keys
        // con el campo 'name' del Media API ("accounts/.../locations/.../media/AF1Qip...")
        // para obtener la googleUrl de la foto de cada producto y guardarla como
        // gmb_image_url en la sección location_menu_sections.
        // ─────────────────────────────────────────────────────────────────────────────
        if ( ! empty( $account_id ) && ! empty( $location_id ) && method_exists( 'Lealez_GMB_API', 'get_location_media_items' ) ) {

            $all_media_result = Lealez_GMB_API::get_location_media_items(
                $business_id,
                $account_id,
                $location_id,
                true, // force_refresh
                $location_name // location_resource_name para resolución adicional
            );

            if ( ! is_wp_error( $all_media_result ) && ! empty( $all_media_result['mediaItems'] ) ) {

                $food_photo_categories = array( 'FOOD_AND_DRINK', 'MENU' );
                $gmb_food_photos       = array();

                foreach ( $all_media_result['mediaItems'] as $media_item ) {
                    if ( ! is_array( $media_item ) ) {
                        continue;
                    }
                    $category = '';
                    if ( ! empty( $media_item['locationAssociation']['category'] ) ) {
                        $category = strtoupper( (string) $media_item['locationAssociation']['category'] );
                    }
                    if ( ! in_array( $category, $food_photo_categories, true ) ) {
                        continue;
                    }
                    $google_url = ! empty( $media_item['googleUrl'] ) ? esc_url_raw( (string) $media_item['googleUrl'] ) : '';
                    if ( ! $google_url ) {
                        continue;
                    }
                    $gmb_food_photos[] = array(
                        'googleUrl'  => $google_url,
                        'mediaKey'   => sanitize_text_field( (string) ( $media_item['name']        ?? '' ) ),
                        'createTime' => sanitize_text_field( (string) ( $media_item['createTime']  ?? '' ) ),
                        'category'   => $category,
                    );
                }

                if ( ! empty( $gmb_food_photos ) ) {
                    update_post_meta( $post_id, 'gmb_menu_photos_raw', $gmb_food_photos );
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[OY Location] Food photos imported: ' . count( $gmb_food_photos ) . ' foto(s) de comida.' );
                    }
                } else {
                    // Limpiar si ya no hay fotos de comida
                    delete_post_meta( $post_id, 'gmb_menu_photos_raw' );
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[OY Location] Food Photos — ningún item con categoría FOOD_AND_DRINK o MENU.' );
                    }
                }

                // ── Resolver fotos de productos del menú por mediaKey ─────────────────
                // Si en el bloque anterior se mapearon secciones con media_keys,
                // ahora cruzamos esas keys con todos los mediaItems para obtener la googleUrl
                // de la foto de cada producto y guardarla como gmb_image_url en el meta.
                if ( ! empty( $fm_mapped_sections_for_photo_resolve ) ) {
                    $enriched_sections = $this->resolve_item_photos_from_media(
                        $fm_mapped_sections_for_photo_resolve,
                        $all_media_result['mediaItems']
                    );
                    update_post_meta( $post_id, 'location_menu_sections', $enriched_sections );
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[OY Location] Item photos resolved from Media API (import_from_gmb).' );
                    }
                }
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
 *  regularHours: { periods: [ { openDay: 'MONDAY', openTime: { hours: 9, minutes: 0 }, closeDay: 'MONDAY', closeTime: {hours: 21} } ] }
 *
 * Notes from Google Business Information API v1:
 *  - closeTime.hours can be 24 (meaning end-of-day / midnight). Normalize to 0.
 *  - minutes key may be absent when value is 0.
 *  - For 24h days: openDay=MONDAY/closeDay=TUESDAY with both times at 0:00.
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

    $day_order = array(
        'MONDAY'    => 0, 'TUESDAY'  => 1, 'WEDNESDAY' => 2, 'THURSDAY' => 3,
        'FRIDAY'    => 4, 'SATURDAY' => 5, 'SUNDAY'    => 6,
    );
    $day_map = array(
        'MONDAY'    => 'monday',    'TUESDAY'   => 'tuesday',   'WEDNESDAY' => 'wednesday',
        'THURSDAY'  => 'thursday',  'FRIDAY'    => 'friday',    'SATURDAY'  => 'saturday',
        'SUNDAY'    => 'sunday',
    );

    $periods_by_day = array();

    foreach ( $periods as $p ) {
        if ( ! is_array( $p ) ) { continue; }

        $open_day_raw = isset( $p['openDay'] ) ? strtoupper( (string) $p['openDay'] ) : '';
        if ( ! $open_day_raw || ! isset( $day_map[ $open_day_raw ] ) ) { continue; }

        $day_key = $day_map[ $open_day_raw ];

        $open_time  = isset( $p['openTime'] )  && is_array( $p['openTime'] )  ? $p['openTime']  : array();
        $close_time = isset( $p['closeTime'] ) && is_array( $p['closeTime'] ) ? $p['closeTime'] : null;

        $open_h  = isset( $open_time['hours'] )   ? (int) $open_time['hours']   : 0;
        $open_m  = isset( $open_time['minutes'] ) ? (int) $open_time['minutes'] : 0;
        $close_h = ( null !== $close_time && isset( $close_time['hours'] ) )   ? (int) $close_time['hours']   : 0;
        $close_m = ( null !== $close_time && isset( $close_time['minutes'] ) ) ? (int) $close_time['minutes'] : 0;

        $close_day_raw = isset( $p['closeDay'] ) ? strtoupper( (string) $p['closeDay'] ) : $open_day_raw;
        $open_day_idx  = $day_order[ $open_day_raw ]  ?? -1;
        $close_day_idx = $day_order[ $close_day_raw ] ?? -1;
        $is_next_day   = ( $close_day_idx >= 0 && $open_day_idx >= 0 )
                         && ( $close_day_idx === ( ( $open_day_idx + 1 ) % 7 ) );

        // ── Detectar 24 horas ────────────────────────────────────────────────────
        // Regla 1: openTime=0:0, closeTime=0:0 Y closeDay es el día siguiente
        $is_24h = false;
        if ( $open_h === 0 && $open_m === 0 && $close_h === 0 && $close_m === 0 && $is_next_day ) {
            $is_24h = true;
        }
        // Regla 2: closeTime.hours = 24 (formato especial de Google: fin de día) y open es medianoche
        if ( ! $is_24h && null !== $close_time && $close_h === 24 && $open_h === 0 && $open_m === 0 ) {
            $is_24h = true;
        }
        // Regla 3: closeTime ausente y openTime es medianoche (Google a veces omite closeTime en 24h)
        if ( ! $is_24h && null === $close_time && $open_h === 0 && $open_m === 0 ) {
            $is_24h = true;
        }
        // NOTA: El check anterior "openH===0 && closeH===0 && closeM===0" sin isNext fue ELIMINADO
        // porque causaba falsos positivos (ej. negocios que cierran a medianoche 00:00).

        // ── Normalizar closeTime.hours=24 → 0 cuando NO es 24h ──────────────────
        // Google usa hours:24 para indicar "fin de día / medianoche".
        // En ese caso el negocio NO es 24h (ej. abre 8am, cierra a medianoche=24:00).
        // Lo convertimos a "00:00" que sí existe en el <select>.
        if ( ! $is_24h && $close_h === 24 ) {
            $close_h = 0;
            $close_m = 0;
        }

        if ( $is_24h ) {
            $periods_by_day[ $day_key ] = array( 'is_24h' => true, 'periods' => array() );
        } else {
            if ( ! isset( $periods_by_day[ $day_key ] ) ) {
                $periods_by_day[ $day_key ] = array( 'is_24h' => false, 'periods' => array() );
            }
            if ( ! $periods_by_day[ $day_key ]['is_24h'] ) {
                $periods_by_day[ $day_key ]['periods'][] = array(
                    'open'  => sprintf( '%02d:%02d', $open_h, $open_m ),
                    'close' => sprintf( '%02d:%02d', $close_h, $close_m ),
                );
            }
        }
    }

    // ── Convertir a estructura de meta por día ───────────────────────────────────
    $out = array();

    foreach ( $periods_by_day as $day_key => $day_data ) {
        if ( $day_data['is_24h'] ) {
            $out[ $day_key ] = array(
                'closed'  => false,
                'all_day' => true,
                'periods' => array( array( 'open' => '24_hours', 'close' => '' ) ),
                'open'    => '24_hours',
                'close'   => '',
            );
        } else {
            $day_periods = $day_data['periods'];
            $first_open  = $day_periods[0]['open']  ?? '09:00';
            $first_close = $day_periods[0]['close'] ?? '18:00';
            $out[ $day_key ] = array(
                'closed'  => false,
                'all_day' => false,
                'periods' => $day_periods,
                'open'    => $first_open,
                'close'   => $first_close,
            );
        }
    }

    // ── Días ausentes en GMB → marcar como cerrados ──────────────────────────────
    $all_days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
    foreach ( $all_days as $dk ) {
        if ( ! isset( $out[ $dk ] ) ) {
            $out[ $dk ] = array(
                'closed'  => true,
                'all_day' => false,
                'periods' => array( array( 'open' => '09:00', 'close' => '18:00' ) ),
                'open'    => '09:00',
                'close'   => '18:00',
            );
        }
    }

    return $out;
}

    /**
     * Build a daily meta array with all 7 days set to 24 hours.
     * Used when openInfo.openingHoursType = ALWAYS_OPEN (24/7).
     *
     * @return array
     */
    private function build_always_open_daily_meta() {
        $out  = array();
        $days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        foreach ( $days as $dk ) {
            $out[ $dk ] = array(
                'closed'  => false,
                'open'    => '24_hours',
                'close'   => '',
                'all_day' => true,
            );
        }
        return $out;
    }


    /**
     * Map Google My Business v4 foodMenus response to location_menu_sections format.
     *
     * GMB food menus structure (v4):
     * {
     *   "menus": [
     *     {
     *       "labels": [{"displayName": "Menú", "languageCode": "es"}],
     *       "sections": [
     *         {
     *           "labels": [{"displayName": "Sopas"}],
     *           "items": [
     *             {
     *               "labels": [{"displayName": "Sopa de Mondongo", "description": "Incluye porción de arroz"}],
     *               "price": {"currencyCode": "COP", "units": "25000"},
     *               "itemAttributes": {"dietaryRestriction": "VEGETARIAN", "mediaKeys": [...]}
     *             }
     *           ]
     *         }
     *       ]
     *     }
     *   ]
     * }
     *
     * @param array $food_menus_data Respuesta de la API GET /foodMenus
     * @return array Formato location_menu_sections: [['name'=>'...', 'items'=>[...], 'from_gmb'=>1], ...]
     */
    private function map_gmb_food_menus_to_sections( $food_menus_data ) {
        $sections_output = array();

        if ( ! is_array( $food_menus_data ) ) {
            return $sections_output;
        }

        $menus = is_array( $food_menus_data['menus'] ?? null ) ? $food_menus_data['menus'] : array();

        foreach ( $menus as $menu ) {
            if ( ! is_array( $menu ) ) {
                continue;
            }

            $menu_sections = is_array( $menu['sections'] ?? null ) ? $menu['sections'] : array();

            foreach ( $menu_sections as $sec ) {
                if ( ! is_array( $sec ) ) {
                    continue;
                }

                // Extraer nombre de la sección desde labels (primer label con displayName)
                $sec_name   = '';
                $sec_labels = is_array( $sec['labels'] ?? null ) ? $sec['labels'] : array();
                foreach ( $sec_labels as $lbl ) {
                    if ( ! empty( $lbl['displayName'] ) ) {
                        $sec_name = sanitize_text_field( (string) $lbl['displayName'] );
                        break;
                    }
                }
                if ( '' === $sec_name ) {
                    continue; // Sección sin nombre, omitir
                }

                $items_output = array();
                $sec_items    = is_array( $sec['items'] ?? null ) ? $sec['items'] : array();

                foreach ( $sec_items as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }

                    // ── Nombre y descripción del producto ───────────────────────
                    $item_name   = '';
                    $item_desc   = '';
                    $item_labels = is_array( $item['labels'] ?? null ) ? $item['labels'] : array();
                    foreach ( $item_labels as $lbl ) {
                        if ( ! empty( $lbl['displayName'] ) && '' === $item_name ) {
                            $item_name = sanitize_text_field( (string) $lbl['displayName'] );
                        }
                        if ( ! empty( $lbl['description'] ) && '' === $item_desc ) {
                            $item_desc = sanitize_textarea_field( (string) $lbl['description'] );
                        }
                    }
                    if ( '' === $item_name ) {
                        continue; // Producto sin nombre, omitir
                    }

                    // ── Precio ───────────────────────────────────────────────────
                    // Google v4 foodMenus usa item.price = {currencyCode: "COP", units: "25000"}
                    // También puede venir en item.attributes.price (formato alternativo).
                    $price_str  = '';
                    $price_data = array();
                    if ( ! empty( $item['price'] ) && is_array( $item['price'] ) ) {
                        $price_data = $item['price'];
                    } elseif ( ! empty( $item['attributes']['price'] ) && is_array( $item['attributes']['price'] ) ) {
                        $price_data = $item['attributes']['price'];
                    }
                    if ( ! empty( $price_data['units'] ) ) {
                        $price_str = sanitize_text_field( (string) $price_data['units'] );
                        if ( ! empty( $price_data['currencyCode'] ) ) {
                            $price_str .= ' ' . sanitize_text_field( (string) $price_data['currencyCode'] );
                        }
                    }

                    // ── Restricciones dietarias ──────────────────────────────────
                    // Google v4 foodMenus puede enviar dietaryRestriction en DOS formatos:
                    // Formato A (enum string): item.itemAttributes.dietaryRestriction = "VEGETARIAN"
                    // Formato B (booleanos):   item.attributes.vegetarian = true, item.attributes.vegan = true
                    // Ambos se manejan aquí para máxima compatibilidad.
                    $dietary_out = array();

                    // Formato A: campo itemAttributes con string enum (documentación oficial v4)
                    $item_attr_a = is_array( $item['itemAttributes'] ?? null ) ? $item['itemAttributes'] : array();
                    if ( ! empty( $item_attr_a['dietaryRestriction'] ) ) {
                        $dr_raw = strtoupper( (string) $item_attr_a['dietaryRestriction'] );
                        $dietary_enum_map = array(
                            'VEGETARIAN' => 'vegetarian',
                            'VEGAN'      => 'vegan',
                            'GLUTEN_FREE'=> 'gluten_free',
                            'HALAL'      => 'halal',
                            'KOSHER'     => 'kosher',
                        );
                        foreach ( $dietary_enum_map as $gmb_val => $lealez_val ) {
                            if ( false !== strpos( $dr_raw, $gmb_val ) ) {
                                $dietary_out[] = $lealez_val;
                            }
                        }
                    }

                    // Formato B: booleanos en item.attributes (variante observada en produción)
                    $item_attr_b = is_array( $item['attributes'] ?? null ) ? $item['attributes'] : array();
                    $dietary_bool_map = array(
                        'vegetarian' => 'vegetarian',
                        'vegan'      => 'vegan',
                        'glutenFree' => 'gluten_free',
                        'halal'      => 'halal',
                        'kosher'     => 'kosher',
                    );
                    foreach ( $dietary_bool_map as $api_key => $lealez_val ) {
                        if ( ! empty( $item_attr_b[ $api_key ] ) && ! in_array( $lealez_val, $dietary_out, true ) ) {
                            $dietary_out[] = $lealez_val;
                        }
                    }

                    // ── mediaKeys (referencia futura para resolver fotos por item) ─
                    $media_keys = array();
                    if ( ! empty( $item_attr_a['mediaKeys'] ) && is_array( $item_attr_a['mediaKeys'] ) ) {
                        $media_keys = array_map( 'sanitize_text_field', $item_attr_a['mediaKeys'] );
                    } elseif ( ! empty( $item_attr_b['mediaKeys'] ) && is_array( $item_attr_b['mediaKeys'] ) ) {
                        $media_keys = array_map( 'sanitize_text_field', $item_attr_b['mediaKeys'] );
                    }

                    $items_output[] = array(
                        'name'        => $item_name,
                        'price'       => $price_str,
                        'description' => $item_desc,
                        'image_id'    => 0,          // WP Media ID — no disponible desde API v4
                        'dietary'     => $dietary_out,
                        'media_keys'  => $media_keys, // mediaKeys de GMB para referencia futura
                        'from_gmb'    => 1,
                    );
                }

                $sections_output[] = array(
                    'name'     => $sec_name,
                    'items'    => $items_output,
                    'from_gmb' => 1,
                );
            }
        }

        return $sections_output;
    }


    /**
     * Resolver fotos de ítems del menú cruzando mediaKeys con el Media API.
     *
     * La foodMenus API v4 devuelve por cada ítem uno o más `mediaKeys` (ej: "AF1Qip...")
     * que son referencias internas de Google, NO URLs directas. Las URLs reales están en
     * el Media API v4, donde cada mediaItem tiene:
     *   - name:       "accounts/{id}/locations/{id}/media/AF1Qip..." (el sufijo es el mediaKey)
     *   - mediaKey:   campo directo con la key (cuando existe)
     *   - googleUrl:  la URL pública real de la foto
     *
     * Este método construye un lookup mediaKey → googleUrl y enriquece cada ítem con
     * el campo `gmb_image_url` = URL de la primera foto encontrada para ese ítem.
     *
     * @param array $mapped_sections Array de secciones con campo 'media_keys' por item.
     * @param array $all_media_items Array de mediaItems del Media API v4.
     * @return array Secciones enriquecidas con 'gmb_image_url' en cada item que tiene foto.
     */
    private function resolve_item_photos_from_media( array $mapped_sections, array $all_media_items ) {

        if ( empty( $all_media_items ) || empty( $mapped_sections ) ) {
            return $mapped_sections;
        }

        // ── Construir lookup: mediaKey (sufijo corto) → googleUrl ─────────────────
        // El campo 'name' del media item es: "accounts/123/locations/456/media/AF1Qip..."
        // El mediaKey del ítem del menú es solo el sufijo: "AF1Qip..."
        $lookup = array(); // [ 'AF1Qip...' => 'https://lh3.googleusercontent.com/...' ]

        foreach ( $all_media_items as $mitem ) {
            if ( ! is_array( $mitem ) ) {
                continue;
            }

            $google_url = (string) ( $mitem['googleUrl'] ?? $mitem['thumbnailUrl'] ?? '' );
            if ( '' === $google_url ) {
                continue;
            }

            // Formato 1: campo mediaKey directo (algunas versiones de la API lo incluyen)
            if ( ! empty( $mitem['mediaKey'] ) ) {
                $lookup[ trim( (string) $mitem['mediaKey'] ) ] = $google_url;
            }

            // Formato 2: extraer sufijo del campo 'name' después de "/media/"
            // Ejemplo: "accounts/123/locations/456/media/AF1QipXYZ" → key = "AF1QipXYZ"
            if ( ! empty( $mitem['name'] ) ) {
                $name_str = (string) $mitem['name'];
                if ( strpos( $name_str, '/media/' ) !== false ) {
                    $parts = explode( '/media/', $name_str );
                    $key   = trim( (string) end( $parts ) );
                    if ( '' !== $key ) {
                        $lookup[ $key ] = $google_url;
                    }
                }
            }
        }

        if ( empty( $lookup ) ) {
            return $mapped_sections;
        }

        // ── Enriquecer secciones e ítems con gmb_image_url ──────────────────────────
        foreach ( $mapped_sections as $s_idx => $section ) {
            if ( empty( $section['items'] ) || ! is_array( $section['items'] ) ) {
                continue;
            }

            foreach ( $section['items'] as $i_idx => $item ) {
                $media_keys = is_array( $item['media_keys'] ?? null ) ? $item['media_keys'] : array();

                if ( empty( $media_keys ) ) {
                    continue;
                }

                $resolved_url = '';
                foreach ( $media_keys as $mk ) {
                    $mk = trim( (string) $mk );
                    if ( '' === $mk ) {
                        continue;
                    }
                    // Buscar exacto
                    if ( isset( $lookup[ $mk ] ) ) {
                        $resolved_url = $lookup[ $mk ];
                        break;
                    }
                    // Buscar solo el sufijo (por si la key viene como path completo)
                    $parts = explode( '/', $mk );
                    $short = trim( (string) end( $parts ) );
                    if ( '' !== $short && isset( $lookup[ $short ] ) ) {
                        $resolved_url = $lookup[ $short ];
                        break;
                    }
                }

                if ( '' !== $resolved_url ) {
                    $mapped_sections[ $s_idx ]['items'][ $i_idx ]['gmb_image_url'] = esc_url_raw( $resolved_url );
                }
            }
        }

        return $mapped_sections;
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

public function ajax_get_gmb_location_details() {
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, $this->ajax_nonce_action ) ) {
        wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
    }

    $business_id   = isset( $_POST['business_id'] )   ? absint( wp_unslash( $_POST['business_id'] ) )                             : 0;
    $location_name = isset( $_POST['location_name'] ) ? sanitize_text_field( wp_unslash( $_POST['location_name'] ) ) : '';
    $account_name  = isset( $_POST['account_name'] )  ? sanitize_text_field( wp_unslash( $_POST['account_name'] ) )  : '';

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

    // ✅ CORRECCIÓN: Forzar re-fetch si la caché NO tiene campos críticos para la UI.
    if ( null !== $found ) {
        $missing_profile       = empty( $found['profile'] );
        $missing_metadata      = empty( $found['metadata'] );
        $missing_regular_hours = ! array_key_exists( 'regularHours', (array) $found );

        if ( $missing_profile || $missing_metadata || $missing_regular_hours ) {
            $found = null;
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[OY Location] Cache miss — re-fetching from API. Missing: profile=%s, metadata=%s, regularHours=%s',
                    $missing_profile      ? 'YES' : 'no',
                    $missing_metadata     ? 'YES' : 'no',
                    $missing_regular_hours ? 'YES' : 'no'
                ) );
            }
        }
    }

    // 2) If not found (or cache missing critical fields), call API get
    if ( null === $found ) {
        if ( ! class_exists( 'Lealez_GMB_API' ) ) {
            wp_send_json_error( array( 'message' => __( 'Lealez_GMB_API no está disponible.', 'lealez' ) ) );
        }

        $fresh = Lealez_GMB_API::sync_location_data( $business_id, $location_name );
        if ( is_wp_error( $fresh ) || ! is_array( $fresh ) ) {
            $msg = is_wp_error( $fresh ) ? $fresh->get_error_message() : __( 'No se pudo obtener la ubicación.', 'lealez' );
            wp_send_json_error( array( 'message' => $msg ) );
        }

        // ✅ Obtener verification desde Verifications API
        $location_id = $this->extract_location_id_from_resource_name( $location_name );
        if ( ! empty( $location_id ) ) {
            $verification = Lealez_GMB_API::get_location_verification_state( $business_id, $location_id, true );

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

        $fresh['account_name'] = $account_name;

        if ( ! array_key_exists( 'regularHours', $fresh ) ) {
            $fresh['regularHours'] = array();
        }

        $found = $fresh;
    }

    $location_id = $this->extract_location_id_from_resource_name( $location_name );
    $account_id  = $this->extract_account_name_from_location_name( $location_name );

    // ✅ Obtener Place Action Links (Reservas / Menú / Pedidos Online) desde Place Actions API v1.
    // Estos links NO están en Business Information API v1 ni en el endpoint de atributos.
    // Se incluyen en la respuesta para que applyLocationToForm() los aplique visualmente al formulario.
    // El JS los procesará en loc.placeActionLinks → poblar #location_menu_url_gmb, booking_urls, order_urls.
    if ( class_exists( 'Lealez_GMB_API' ) && method_exists( 'Lealez_GMB_API', 'get_location_place_action_links' ) ) {
        $place_action_links_ajax = Lealez_GMB_API::get_location_place_action_links( $business_id, $location_name, true ); // ✅ usa WP transient cache
        if ( ! is_wp_error( $place_action_links_ajax ) && is_array( $place_action_links_ajax ) ) {
            $found['placeActionLinks'] = $place_action_links_ajax;
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] ajax_get_gmb_location_details — placeActionLinks incluidos: ' . count( $place_action_links_ajax ) . ' link(s).' );
            }
        } else {
            $found['placeActionLinks'] = array();
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $pa_err_msg = is_wp_error( $place_action_links_ajax ) ? $place_action_links_ajax->get_error_message() : 'empty response';
                error_log( '[OY Location] ajax_get_gmb_location_details — Place Actions API fallo o vacío: ' . $pa_err_msg );
            }
        }
    } else {
        $found['placeActionLinks'] = array();
    }

// ✅ FUENTE AUTORITATIVA para "Vínculo del menú o los servicios":
    // Business Information API v1 — Attributes endpoint (url_menu / url_food_menu / variantes).
    //
    // CAMBIO vs. versión anterior: ya NO es un fallback condicional. Siempre se consultan
    // los atributos y el url_menu encontrado SIEMPRE reemplaza cualquier MENU de Place Actions.
    // Razón: "Vínculo del menú o los servicios" está en la sección Contacto de GBP (igual que
    // las redes sociales), no en la sección Acciones. Por tanto proviene de Attributes API,
    // no de Place Actions API. El matching usa strpos('menu') para cubrir variantes de nombre.
    if ( class_exists( 'Lealez_GMB_API' ) && method_exists( 'Lealez_GMB_API', 'get_location_attributes' ) ) {
        $attrs_ajax = Lealez_GMB_API::get_location_attributes( $business_id, $location_name, false ); // force fresh — evita cache vacío
        if ( ! is_wp_error( $attrs_ajax ) && is_array( $attrs_ajax ) ) {
            $_menu_uri_from_attrs = '';
            foreach ( $attrs_ajax as $_attr ) {
                if ( ! is_array( $_attr ) ) {
                    continue;
                }

                // Normalizar ID del atributo desde 'locations/{id}/attributes/url_menu'
                $_attr_id_raw  = '';
                $_attr_name_full = '';
                if ( ! empty( $_attr['name'] ) ) {
                    $_attr_name_full = strtolower( (string) $_attr['name'] );
                    $_parts          = explode( '/attributes/', (string) $_attr['name'], 2 );
                    $_attr_id_raw    = strtolower( trim( end( $_parts ), '/' ) );
                } elseif ( ! empty( $_attr['attributeId'] ) ) {
                    $_attr_id_raw    = strtolower( trim( (string) $_attr['attributeId'] ) );
                    $_attr_name_full = $_attr_id_raw;
                }

                // Matching flexible: busca cualquier atributo con 'menu' en el nombre
                // y que tenga uriValues (es de tipo URL, no booleano como has_menu).
                if (
                    ( false !== strpos( $_attr_name_full, 'menu' ) || false !== strpos( $_attr_id_raw, 'menu' ) )
                    && ! empty( $_attr['uriValues'] )
                    && is_array( $_attr['uriValues'] )
                ) {
                    $_candidate = (string) ( $_attr['uriValues'][0]['uri'] ?? '' );
                    if ( '' !== $_candidate ) {
                        $_menu_uri_from_attrs = esc_url_raw( $_candidate );
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( sprintf(
                                '[OY Location] ajax — url_menu desde Attributes API (atributo: %s): %s',
                                $_attr_id_raw,
                                $_menu_uri_from_attrs
                            ) );
                        }
                        break;
                    }
                }
            }

            if ( '' !== $_menu_uri_from_attrs ) {
                // Eliminar cualquier entrada MENU previa de Place Actions para evitar duplicados
                if ( ! empty( $found['placeActionLinks'] ) && is_array( $found['placeActionLinks'] ) ) {
                    $found['placeActionLinks'] = array_values( array_filter(
                        $found['placeActionLinks'],
                        function( $_e ) {
                            return strtoupper( (string) ( $_e['placeActionType'] ?? '' ) ) !== 'MENU';
                        }
                    ) );
                }
                // Inyectar url_menu como entrada MENU autoritativa para que el JS la aplique
                $found['placeActionLinks'][] = array(
                    'uri'             => $_menu_uri_from_attrs,
                    'placeActionType' => 'MENU',
                    'providerType'    => 'MERCHANT',
                );
            } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] ajax — No se encontró atributo de menú con uriValues en Attributes API.' );
            }
} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $_attrs_err = is_wp_error( $attrs_ajax ) ? $attrs_ajax->get_error_message() : 'empty/null';
            error_log( '[OY Location] ajax — get_location_attributes falló o vacío: ' . $_attrs_err );
        }
    }

    wp_send_json_success( array(
        'location'    => $found,
        'location_id' => $location_id,
        'account_id'  => $account_id,
    ) );
}


    /**
     * ✅ AJAX: Sincronizar menú estructurado (foodMenus) + fotos de comida desde GMB para un Location post.
     *
     * Se llama desde el botón "🔄 Sincronizar desde Google" en el metabox de Menú.
     * Escribe directamente en la base de datos (post meta), sin requerir guardar el post.
     *
     * POST params:
     *   nonce       string  Nonce 'oy_location_gmb_ajax'
     *   post_id     int     ID del oy_location post
     *   business_id int     ID del oy_business post
     *
     * @return void  Envía JSON con success|error
     */
    public function ajax_sync_location_food_menus() {
        // ── 1. Seguridad ─────────────────────────────────────────────────────
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, $this->ajax_nonce_action ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos suficientes.', 'lealez' ) ) );
        }

        // ── 2. Parámetros ─────────────────────────────────────────────────────
        $post_id     = isset( $_POST['post_id'] )     ? absint( wp_unslash( $_POST['post_id'] ) )     : 0;
        $business_id = isset( $_POST['business_id'] ) ? absint( wp_unslash( $_POST['business_id'] ) ) : 0;

        if ( ! $post_id || ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Parámetros inválidos (post_id o business_id vacíos).', 'lealez' ) ) );
        }

        // Verificar que el post existe y es del tipo correcto
        $post = get_post( $post_id );
        if ( ! $post || 'oy_location' !== $post->post_type ) {
            wp_send_json_error( array( 'message' => __( 'La ubicación indicada no existe.', 'lealez' ) ) );
        }

        // ── 3. Obtener IDs de GMB desde post meta ────────────────────────────
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        $account_id    = (string) get_post_meta( $post_id, 'gmb_account_id', true );
        $location_id   = (string) get_post_meta( $post_id, 'gmb_location_id', true );

        // Si gmb_location_id no está en meta, intentar extraerlo del resource name
        if ( '' === $location_id && '' !== $location_name ) {
            $location_id = $this->extract_location_id_from_resource_name( $location_name );
        }
        // Si gmb_account_id no está en meta, intentar extraerlo del resource name
        if ( '' === $account_id && '' !== $location_name ) {
            $account_id = $this->extract_account_name_from_location_name( $location_name );
        }

        if ( '' === $account_id || '' === $location_id ) {
            wp_send_json_error( array(
                'message' => __( 'No se encontraron los IDs de la ubicación en Google (gmb_account_id / gmb_location_id). Importa primero la ubicación desde el metabox de Configuración GMB.', 'lealez' ),
            ) );
        }

        if ( ! class_exists( 'Lealez_GMB_API' ) ) {
            wp_send_json_error( array( 'message' => __( 'Lealez_GMB_API no está disponible.', 'lealez' ) ) );
        }

        // ── 4. Llamar Food Menus API (v4) ─────────────────────────────────────
        $food_menus_result = Lealez_GMB_API::get_location_food_menus(
            $business_id,
            $account_id,
            $location_id,
            true // force_refresh siempre
        );

        $sections_imported = 0;
        $items_imported    = 0;
        $menu_error        = '';

        // Variable compartida con el bloque Media para resolución de fotos por item
        $ajax_mapped_sections_for_resolve = array();

        if ( is_wp_error( $food_menus_result ) ) {
            $menu_error = $food_menus_result->get_error_message();
            $fm_error_data = array(
                'code'      => $food_menus_result->get_error_code(),
                'message'   => $menu_error,
                'timestamp' => current_time( 'mysql' ),
            );
            update_post_meta( $post_id, 'gmb_food_menus_api_error', $fm_error_data );
            delete_post_meta( $post_id, 'gmb_food_menus_raw' );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] ajax_sync_food_menus ERROR: ' . $menu_error );
                error_log( '[OY Location] account_id=' . $account_id . ' location_id=' . $location_id );
            }

        } elseif ( ! empty( $food_menus_result['menus'] ) ) {

            // ── Éxito: hay menú ────────────────────────────────────────────────
            delete_post_meta( $post_id, 'gmb_food_menus_api_error' );
            update_post_meta( $post_id, 'gmb_food_menus_raw', $food_menus_result );
            update_post_meta( $post_id, 'gmb_food_menus_last_sync', time() );

            $mapped_sections = $this->map_gmb_food_menus_to_sections( $food_menus_result );
            if ( ! empty( $mapped_sections ) ) {
                // Guardar provisionalmente; se enriquece con gmb_image_url tras Media API
                $ajax_mapped_sections_for_resolve = $mapped_sections;
                update_post_meta( $post_id, 'location_menu_sections', $mapped_sections );
                $sections_imported = count( $mapped_sections );
                foreach ( $mapped_sections as $sec ) {
                    $items_imported += count( $sec['items'] ?? array() );
                }
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] ajax_sync_food_menus OK: ' . $sections_imported . ' secciones, ' . $items_imported . ' items (fotos de item pendientes de resolución).' );
            }

        } else {
            // ── Respuesta vacía ────────────────────────────────────────────────
            delete_post_meta( $post_id, 'gmb_food_menus_api_error' );
            update_post_meta( $post_id, 'gmb_food_menus_raw', $food_menus_result );
            update_post_meta( $post_id, 'gmb_food_menus_last_sync', time() );
            $menu_error = __( 'El negocio no tiene menú estructurado en Google My Business, o la cuenta no es de tipo restaurante.', 'lealez' );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] ajax_sync_food_menus — respuesta vacía. account_id=' . $account_id . ' location_id=' . $location_id );
            }
        }

        // ── 5. Llamar Food Photos (Media API v4) ─────────────────────────────
        $photos_imported = 0;
        $media_result    = Lealez_GMB_API::get_location_media_items(
            $business_id,
            $account_id,
            $location_id,
            true // force_refresh
        );

        if ( ! is_wp_error( $media_result ) && is_array( $media_result ) ) {
            $media_items = $media_result['mediaItems'] ?? ( isset( $media_result[0] ) ? $media_result : array() );
            if ( ! is_array( $media_items ) ) {
                $media_items = array();
            }

            $food_photo_cats = array( 'FOOD_AND_DRINK', 'MENU' );
            $gmb_food_photos = array();

            foreach ( $media_items as $mitem ) {
                if ( ! is_array( $mitem ) ) {
                    continue;
                }
                $category = (string) ( $mitem['locationAssociation']['category'] ?? $mitem['category'] ?? '' );
                if ( ! in_array( strtoupper( $category ), $food_photo_cats, true ) ) {
                    continue;
                }
                $google_url = (string) ( $mitem['googleUrl'] ?? $mitem['thumbnailUrl'] ?? '' );
                if ( '' === $google_url ) {
                    continue;
                }
                $gmb_food_photos[] = array(
                    'googleUrl'    => $google_url,
                    'thumbnailUrl' => (string) ( $mitem['thumbnailUrl'] ?? $google_url ),
                    'mediaKey'     => (string) ( $mitem['mediaKey'] ?? '' ),
                    'createTime'   => (string) ( $mitem['createTime'] ?? '' ),
                    'category'     => $category,
                );
            }

            if ( ! empty( $gmb_food_photos ) ) {
                update_post_meta( $post_id, 'gmb_menu_photos_raw', $gmb_food_photos );
                $photos_imported = count( $gmb_food_photos );
            } else {
                delete_post_meta( $post_id, 'gmb_menu_photos_raw' );
            }

            // ── Resolver fotos de productos del menú por mediaKey ─────────────────
            // Cruzar los media_keys de cada ítem del foodMenu con las URLs reales del Media API.
            // El resultado se guarda como gmb_image_url en cada ítem de location_menu_sections.
            if ( ! empty( $ajax_mapped_sections_for_resolve ) && ! empty( $media_items ) ) {
                $enriched_sections = $this->resolve_item_photos_from_media(
                    $ajax_mapped_sections_for_resolve,
                    $media_items
                );
                update_post_meta( $post_id, 'location_menu_sections', $enriched_sections );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[OY Location] Item photos resolved from Media API (ajax_sync).' );
                }
            }
        }

        // ── 6. Respuesta JSON ─────────────────────────────────────────────────
        if ( '' !== $menu_error && 0 === $sections_imported ) {
            // No hay menú en GMB (puede ser válido para fotos igual)
            wp_send_json_success( array(
                'sections_imported' => $sections_imported,
                'items_imported'    => $items_imported,
                'photos_imported'   => $photos_imported,
                'menu_warning'      => $menu_error,
                'message'           => sprintf(
                    /* translators: 1: photos count */
                    _n(
                        'No hay menú estructurado en Google. %1$d foto importada.',
                        'No hay menú estructurado en Google. %1$d fotos importadas.',
                        $photos_imported,
                        'lealez'
                    ),
                    $photos_imported
                ),
            ) );
        }

        wp_send_json_success( array(
            'sections_imported' => $sections_imported,
            'items_imported'    => $items_imported,
            'photos_imported'   => $photos_imported,
            'message'           => sprintf(
                /* translators: 1: sections, 2: items, 3: photos */
                __( '✅ Sincronizado: %1$d sección(es), %2$d producto(s), %3$d foto(s).', 'lealez' ),
                $sections_imported,
                $items_imported,
                $photos_imported
            ),
        ) );
    }
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
            $gmb_location_id        = get_post_meta( $post_id, 'gmb_location_id', true );
            $gmb_verification_state = strtoupper( (string) get_post_meta( $post_id, 'gmb_verification_state', true ) );

            if ( ! $gmb_location_id ) {
                echo '<span style="color:#999;" title="' . esc_attr__( 'No conectada', 'lealez' ) . '">—</span>';
                break;
            }

            // ✅ CORRECCIÓN: COMPLETED también significa verificado (Verifications API)
            $icon  = '⚠';
            $color = '#f0b322';
            $title = __( 'Conectada pero no verificada', 'lealez' );

            if ( in_array( $gmb_verification_state, array( 'VERIFIED', 'COMPLETED' ), true ) ) {
                $icon  = '✓';
                $color = '#46b450';
                $title = __( 'Verificada', 'lealez' );
            } elseif ( in_array( $gmb_verification_state, array( 'VERIFICATION_REQUESTED', 'VERIFICATION_IN_PROGRESS', 'PENDING' ), true ) ) {
                $icon  = '⏳';
                $color = '#00a0d2';
                $title = __( 'Verificación en proceso', 'lealez' );
            } elseif ( in_array( $gmb_verification_state, array( 'FAILED', 'SUSPENDED' ), true ) ) {
                $icon  = '✖';
                $color = '#dc3232';
                $title = __( 'Verificación fallida / suspendida', 'lealez' );
            }

            echo '<span style="color:' . esc_attr( $color ) . ';" title="' . esc_attr( $title . ( $gmb_verification_state ? ' [' . $gmb_verification_state . ']' : '' ) ) . '">' . esc_html( $icon ) . '</span>';
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
