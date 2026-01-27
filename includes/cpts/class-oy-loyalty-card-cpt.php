<?php
/**
 * Loyalty Card CPT Class
 *
 * Handles the registration and meta fields for oy_loyalty_card Custom Post Type
 * Represents individual loyalty cards for end users
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
 * Lealez_Loyalty_Card_CPT Class
 *
 * @class Lealez_Loyalty_Card_CPT
 * @version 1.0.0
 */
class Lealez_Loyalty_Card_CPT {

    /**
     * Post type name
     *
     * @var string
     */
    private $post_type = 'oy_loyalty_card';

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post_oy_loyalty_card', array( $this, 'save_meta_boxes' ), 10, 2 );
        add_filter( 'manage_oy_loyalty_card_posts_columns', array( $this, 'set_custom_columns' ) );
        add_action( 'manage_oy_loyalty_card_posts_custom_column', array( $this, 'custom_column_content' ), 10, 2 );
        add_filter( 'manage_edit-oy_loyalty_card_sortable_columns', array( $this, 'set_sortable_columns' ) );
        
        // Auto-generate card number on creation
        add_action( 'wp_insert_post', array( $this, 'auto_generate_card_number' ), 10, 3 );
        
        // Update parent program counters
        add_action( 'save_post_oy_loyalty_card', array( $this, 'update_parent_program_counters' ), 20, 2 );
        add_action( 'before_delete_post', array( $this, 'update_counters_on_delete' ) );
    }

public function register_post_type() {
    $labels = array(
        'name'                  => _x( 'Tarjetas de Lealtad', 'Post type general name', 'lealez' ),
        'singular_name'         => _x( 'Tarjeta de Lealtad', 'Post type singular name', 'lealez' ),
        'menu_name'             => _x( 'Tarjetas de Lealtad', 'Admin Menu text', 'lealez' ),
        'name_admin_bar'        => _x( 'Tarjeta de Lealtad', 'Add New on Toolbar', 'lealez' ),
        'add_new'               => __( 'Agregar Nueva', 'lealez' ),
        'add_new_item'          => __( 'Agregar Nueva Tarjeta', 'lealez' ),
        'new_item'              => __( 'Nueva Tarjeta', 'lealez' ),
        'edit_item'             => __( 'Editar Tarjeta', 'lealez' ),
        'view_item'             => __( 'Ver Tarjeta', 'lealez' ),
        'all_items'             => __( 'Todas las Tarjetas', 'lealez' ),
        'search_items'          => __( 'Buscar Tarjetas', 'lealez' ),
        'parent_item_colon'     => __( 'Tarjeta Padre:', 'lealez' ),
        'not_found'             => __( 'No se encontraron tarjetas.', 'lealez' ),
        'not_found_in_trash'    => __( 'No se encontraron tarjetas en la papelera.', 'lealez' ),
        'featured_image'        => _x( 'Imagen de la Tarjeta', 'Overrides the "Featured Image" phrase', 'lealez' ),
        'set_featured_image'    => _x( 'Establecer imagen', 'Overrides the "Set featured image" phrase', 'lealez' ),
        'remove_featured_image' => _x( 'Eliminar imagen', 'Overrides the "Remove featured image" phrase', 'lealez' ),
        'use_featured_image'    => _x( 'Usar como imagen', 'Overrides the "Use as featured image" phrase', 'lealez' ),
        'archives'              => _x( 'Archivo de Tarjetas', 'The post type archive label', 'lealez' ),
        'insert_into_item'      => _x( 'Insertar en tarjeta', 'Overrides the "Insert into post" phrase', 'lealez' ),
        'uploaded_to_this_item' => _x( 'Subido a esta tarjeta', 'Overrides the "Uploaded to this post" phrase', 'lealez' ),
        'filter_items_list'     => _x( 'Filtrar lista de tarjetas', 'Screen reader text for the filter links', 'lealez' ),
        'items_list_navigation' => _x( 'Navegación de lista de tarjetas', 'Screen reader text for the pagination', 'lealez' ),
        'items_list'            => _x( 'Lista de tarjetas', 'Screen reader text for the items list', 'lealez' ),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => true,
        'show_in_menu'       => false,
        'menu_position'      => 27,
        'menu_icon'          => 'dashicons-id-alt',
        'query_var'          => true,
        'rewrite'            => array( 'slug' => 'loyalty-card' ),
        'capability_type'    => 'post',
        'has_archive'        => false,
        'hierarchical'       => false,
        'supports'           => array( 'title', 'author' ),
        'show_in_rest'       => false,
        'taxonomies'         => array( 'oy_customer_category' ),
    );

    register_post_type( $this->post_type, $args );
}

    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        // Relación con Programa y Empresa
        add_meta_box(
            'lealez_card_program_relation',
            __( 'Programa y Empresa', 'lealez' ),
            array( $this, 'render_program_relation_meta_box' ),
            $this->post_type,
            'side',
            'high'
        );

        // Información del Titular
        add_meta_box(
            'lealez_card_holder_info',
            __( 'Información del Titular', 'lealez' ),
            array( $this, 'render_holder_info_meta_box' ),
            $this->post_type,
            'normal',
            'high'
        );

        // Saldo y Puntos
        add_meta_box(
            'lealez_card_points_balance',
            __( 'Saldo y Puntos', 'lealez' ),
            array( $this, 'render_points_balance_meta_box' ),
            $this->post_type,
            'normal',
            'high'
        );

        // Nivel/Tier
        add_meta_box(
            'lealez_card_tier_info',
            __( 'Nivel/Tier', 'lealez' ),
            array( $this, 'render_tier_info_meta_box' ),
            $this->post_type,
            'side',
            'default'
        );

        // Integración Digital Wallets
        add_meta_box(
            'lealez_card_digital_wallets',
            __( 'Google Wallet / Apple Wallet', 'lealez' ),
            array( $this, 'render_digital_wallets_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // Códigos de Barras
        add_meta_box(
            'lealez_card_barcodes',
            __( 'Códigos de Barras', 'lealez' ),
            array( $this, 'render_barcodes_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // Ubicaciones
        add_meta_box(
            'lealez_card_locations',
            __( 'Ubicaciones', 'lealez' ),
            array( $this, 'render_locations_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // Actividad y Transacciones
        add_meta_box(
            'lealez_card_activity',
            __( 'Actividad y Transacciones', 'lealez' ),
            array( $this, 'render_activity_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );

        // Estado de la Tarjeta
        add_meta_box(
            'lealez_card_status',
            __( 'Estado de la Tarjeta', 'lealez' ),
            array( $this, 'render_status_meta_box' ),
            $this->post_type,
            'side',
            'default'
        );

        // Seguridad
        add_meta_box(
            'lealez_card_security',
            __( 'Seguridad', 'lealez' ),
            array( $this, 'render_security_meta_box' ),
            $this->post_type,
            'normal',
            'low'
        );

        // Preferencias del Usuario
        add_meta_box(
            'lealez_card_preferences',
            __( 'Preferencias del Usuario', 'lealez' ),
            array( $this, 'render_preferences_meta_box' ),
            $this->post_type,
            'normal',
            'low'
        );

        // Referidos
        add_meta_box(
            'lealez_card_referrals',
            __( 'Programa de Referidos', 'lealez' ),
            array( $this, 'render_referrals_meta_box' ),
            $this->post_type,
            'normal',
            'low'
        );
    }

/**
 * Render Program Relation Meta Box
 */
public function render_program_relation_meta_box( $post ) {
    wp_nonce_field( 'lealez_card_meta_box', 'lealez_card_meta_box_nonce' );

    $parent_business_id = get_post_meta( $post->ID, '_parent_business_id', true );
    $user_id = get_post_meta( $post->ID, '_user_id', true );
    $card_number = get_post_meta( $post->ID, '_card_number', true );

    // Get all businesses
    $businesses = get_posts( array(
        'post_type'      => 'oy_business',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'post_status'    => 'publish',
    ) );

    // Get all WordPress users
    $users = get_users( array( 'orderby' => 'display_name' ) );
    ?>
    <table class="form-table">
        <tr>
            <th><label for="parent_business_id"><?php _e( 'Empresa', 'lealez' ); ?> <span class="required">*</span></label></th>
            <td>
                <select name="parent_business_id" id="parent_business_id" class="widefat" required>
                    <option value=""><?php _e( 'Seleccionar empresa...', 'lealez' ); ?></option>
                    <?php foreach ( $businesses as $business ) : ?>
                        <option value="<?php echo esc_attr( $business->ID ); ?>" <?php selected( $parent_business_id, $business->ID ); ?>>
                            <?php echo esc_html( $business->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="user_id"><?php _e( 'Usuario (Propietario)', 'lealez' ); ?> <span class="required">*</span></label></th>
            <td>
                <select name="user_id" id="user_id" class="widefat" required>
                    <option value=""><?php _e( 'Seleccionar usuario...', 'lealez' ); ?></option>
                    <?php foreach ( $users as $user ) : ?>
                        <option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $user_id, $user->ID ); ?>>
                            <?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="card_number"><?php _e( 'Número de Tarjeta', 'lealez' ); ?></label></th>
            <td>
                <input type="text" id="card_number" name="card_number" value="<?php echo esc_attr( $card_number ); ?>" class="regular-text" readonly />
                <p class="description"><?php _e( 'Se genera automáticamente al crear la tarjeta.', 'lealez' ); ?></p>
            </td>
        </tr>
    </table>
    <?php
}

    /**
     * Render Holder Info Meta Box
     */
    public function render_holder_info_meta_box( $post ) {
        $card_holder_name = get_post_meta( $post->ID, '_card_holder_name', true );
        $card_holder_email = get_post_meta( $post->ID, '_card_holder_email', true );
        $card_holder_phone = get_post_meta( $post->ID, '_card_holder_phone', true );
        $member_id = get_post_meta( $post->ID, '_member_id', true );
        $date_of_birth = get_post_meta( $post->ID, '_date_of_birth', true );
        $gender = get_post_meta( $post->ID, '_gender', true );
        $country_of_residence = get_post_meta( $post->ID, '_country_of_residence', true );
        $city_of_residence = get_post_meta( $post->ID, '_city_of_residence', true );
        $postal_code = get_post_meta( $post->ID, '_postal_code', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="card_holder_name"><?php _e( 'Nombre del Titular', 'lealez' ); ?></label></th>
                <td><input type="text" id="card_holder_name" name="card_holder_name" value="<?php echo esc_attr( $card_holder_name ); ?>" class="large-text" /></td>
            </tr>
            <tr>
                <th><label for="card_holder_email"><?php _e( 'Email del Titular', 'lealez' ); ?></label></th>
                <td><input type="email" id="card_holder_email" name="card_holder_email" value="<?php echo esc_attr( $card_holder_email ); ?>" class="large-text" /></td>
            </tr>
            <tr>
                <th><label for="card_holder_phone"><?php _e( 'Teléfono del Titular', 'lealez' ); ?></label></th>
                <td><input type="tel" id="card_holder_phone" name="card_holder_phone" value="<?php echo esc_attr( $card_holder_phone ); ?>" class="regular-text" placeholder="+573001234567" /></td>
            </tr>
            <tr>
                <th><label for="member_id"><?php _e( 'ID de Membresía', 'lealez' ); ?></label></th>
                <td><input type="text" id="member_id" name="member_id" value="<?php echo esc_attr( $member_id ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="date_of_birth"><?php _e( 'Fecha de Nacimiento', 'lealez' ); ?></label></th>
                <td><input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo esc_attr( $date_of_birth ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="gender"><?php _e( 'Género', 'lealez' ); ?></label></th>
                <td>
                    <select id="gender" name="gender" class="regular-text">
                        <option value=""><?php _e( 'Seleccionar...', 'lealez' ); ?></option>
                        <option value="male" <?php selected( $gender, 'male' ); ?>><?php _e( 'Masculino', 'lealez' ); ?></option>
                        <option value="female" <?php selected( $gender, 'female' ); ?>><?php _e( 'Femenino', 'lealez' ); ?></option>
                        <option value="other" <?php selected( $gender, 'other' ); ?>><?php _e( 'Otro', 'lealez' ); ?></option>
                        <option value="prefer_not_to_say" <?php selected( $gender, 'prefer_not_to_say' ); ?>><?php _e( 'Prefiero no decir', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="country_of_residence"><?php _e( 'País de Residencia', 'lealez' ); ?></label></th>
                <td><input type="text" id="country_of_residence" name="country_of_residence" value="<?php echo esc_attr( $country_of_residence ); ?>" class="regular-text" placeholder="CO, MX, US" maxlength="2" /></td>
            </tr>
            <tr>
                <th><label for="city_of_residence"><?php _e( 'Ciudad de Residencia', 'lealez' ); ?></label></th>
                <td><input type="text" id="city_of_residence" name="city_of_residence" value="<?php echo esc_attr( $city_of_residence ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="postal_code"><?php _e( 'Código Postal', 'lealez' ); ?></label></th>
                <td><input type="text" id="postal_code" name="postal_code" value="<?php echo esc_attr( $postal_code ); ?>" class="regular-text" /></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Points Balance Meta Box
     */
    public function render_points_balance_meta_box( $post ) {
        $current_points = get_post_meta( $post->ID, '_current_points', true );
        $lifetime_points_earned = get_post_meta( $post->ID, '_lifetime_points_earned', true );
        $lifetime_points_redeemed = get_post_meta( $post->ID, '_lifetime_points_redeemed', true );
        $pending_points = get_post_meta( $post->ID, '_pending_points', true );
        $expired_points = get_post_meta( $post->ID, '_expired_points', true );

        if ( empty( $current_points ) ) {
            $current_points = 0;
        }
        if ( empty( $lifetime_points_earned ) ) {
            $lifetime_points_earned = 0;
        }
        if ( empty( $lifetime_points_redeemed ) ) {
            $lifetime_points_redeemed = 0;
        }
        ?>
        <table class="form-table">
            <tr>
                <th><label for="current_points"><?php _e( 'Puntos Actuales', 'lealez' ); ?></label></th>
                <td>
                    <input type="number" id="current_points" name="current_points" value="<?php echo esc_attr( $current_points ); ?>" class="regular-text" min="0" />
                    <p class="description"><?php _e( 'Saldo de puntos disponibles para canjear.', 'lealez' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="lifetime_points_earned"><?php _e( 'Total Puntos Ganados', 'lealez' ); ?></label></th>
                <td>
                    <input type="number" id="lifetime_points_earned" name="lifetime_points_earned" value="<?php echo esc_attr( $lifetime_points_earned ); ?>" class="regular-text" min="0" readonly />
                    <p class="description"><?php _e( 'Total de puntos ganados históricamente.', 'lealez' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="lifetime_points_redeemed"><?php _e( 'Total Puntos Canjeados', 'lealez' ); ?></label></th>
                <td>
                    <input type="number" id="lifetime_points_redeemed" name="lifetime_points_redeemed" value="<?php echo esc_attr( $lifetime_points_redeemed ); ?>" class="regular-text" min="0" readonly />
                    <p class="description"><?php _e( 'Total de puntos canjeados históricamente.', 'lealez' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="pending_points"><?php _e( 'Puntos Pendientes', 'lealez' ); ?></label></th>
                <td>
                    <input type="number" id="pending_points" name="pending_points" value="<?php echo esc_attr( $pending_points ); ?>" class="regular-text" min="0" />
                    <p class="description"><?php _e( 'Puntos pendientes de acreditación.', 'lealez' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="expired_points"><?php _e( 'Puntos Expirados', 'lealez' ); ?></label></th>
                <td>
                    <input type="number" id="expired_points" name="expired_points" value="<?php echo esc_attr( $expired_points ); ?>" class="regular-text" min="0" readonly />
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Tier Info Meta Box
     */
    public function render_tier_info_meta_box( $post ) {
        $current_tier = get_post_meta( $post->ID, '_current_tier', true );
        $tier_points = get_post_meta( $post->ID, '_tier_points', true );
        $tier_since_date = get_post_meta( $post->ID, '_tier_since_date', true );
        ?>
        <p>
            <label for="current_tier"><strong><?php _e( 'Nivel Actual', 'lealez' ); ?></strong></label>
            <select id="current_tier" name="current_tier" class="widefat">
                <option value=""><?php _e( 'Sin nivel', 'lealez' ); ?></option>
                <option value="bronze" <?php selected( $current_tier, 'bronze' ); ?>><?php _e( 'Bronce', 'lealez' ); ?></option>
                <option value="silver" <?php selected( $current_tier, 'silver' ); ?>><?php _e( 'Plata', 'lealez' ); ?></option>
                <option value="gold" <?php selected( $current_tier, 'gold' ); ?>><?php _e( 'Oro', 'lealez' ); ?></option>
                <option value="platinum" <?php selected( $current_tier, 'platinum' ); ?>><?php _e( 'Platino', 'lealez' ); ?></option>
            </select>
        </p>
        <p>
            <label for="tier_points"><strong><?php _e( 'Puntos del Tier', 'lealez' ); ?></strong></label>
            <input type="number" id="tier_points" name="tier_points" value="<?php echo esc_attr( $tier_points ); ?>" class="widefat" min="0" />
        </p>
        <p>
            <label for="tier_since_date"><strong><?php _e( 'Fecha Inicio Tier', 'lealez' ); ?></strong></label>
            <input type="date" id="tier_since_date" name="tier_since_date" value="<?php echo esc_attr( $tier_since_date ); ?>" class="widefat" />
        </p>
        <?php
    }

    /**
     * Render Digital Wallets Meta Box
     */
    public function render_digital_wallets_meta_box( $post ) {
        $google_object_id = get_post_meta( $post->ID, '_google_object_id', true );
        $google_save_url = get_post_meta( $post->ID, '_google_save_url', true );
        $google_wallet_added = get_post_meta( $post->ID, '_google_wallet_added', true );
        $apple_pass_serial_number = get_post_meta( $post->ID, '_apple_pass_serial_number', true );
        $apple_pass_url = get_post_meta( $post->ID, '_apple_pass_url', true );
        $apple_wallet_added = get_post_meta( $post->ID, '_apple_wallet_added', true );
        ?>
        <h3><?php _e( 'Google Wallet', 'lealez' ); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="google_object_id"><?php _e( 'Google Object ID', 'lealez' ); ?></label></th>
                <td><input type="text" id="google_object_id" name="google_object_id" value="<?php echo esc_attr( $google_object_id ); ?>" class="large-text" readonly /></td>
            </tr>
            <tr>
                <th><label for="google_save_url"><?php _e( 'URL Agregar a Google Wallet', 'lealez' ); ?></label></th>
                <td><input type="url" id="google_save_url" name="google_save_url" value="<?php echo esc_url( $google_save_url ); ?>" class="large-text" readonly /></td>
            </tr>
            <tr>
                <th><label for="google_wallet_added"><?php _e( 'Añadida a Google Wallet', 'lealez' ); ?></label></th>
                <td><input type="checkbox" id="google_wallet_added" name="google_wallet_added" value="1" <?php checked( $google_wallet_added, '1' ); ?> /></td>
            </tr>
        </table>

        <hr>

        <h3><?php _e( 'Apple Wallet', 'lealez' ); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="apple_pass_serial_number"><?php _e( 'Apple Pass Serial Number', 'lealez' ); ?></label></th>
                <td><input type="text" id="apple_pass_serial_number" name="apple_pass_serial_number" value="<?php echo esc_attr( $apple_pass_serial_number ); ?>" class="large-text" readonly /></td>
            </tr>
            <tr>
                <th><label for="apple_pass_url"><?php _e( 'URL Descarga .pkpass', 'lealez' ); ?></label></th>
                <td><input type="url" id="apple_pass_url" name="apple_pass_url" value="<?php echo esc_url( $apple_pass_url ); ?>" class="large-text" readonly /></td>
            </tr>
            <tr>
                <th><label for="apple_wallet_added"><?php _e( 'Añadida a Apple Wallet', 'lealez' ); ?></label></th>
                <td><input type="checkbox" id="apple_wallet_added" name="apple_wallet_added" value="1" <?php checked( $apple_wallet_added, '1' ); ?> /></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Barcodes Meta Box
     */
    public function render_barcodes_meta_box( $post ) {
        $barcode_type = get_post_meta( $post->ID, '_barcode_type', true );
        $barcode_value = get_post_meta( $post->ID, '_barcode_value', true );
        $barcode_alt_text = get_post_meta( $post->ID, '_barcode_alt_text', true );
        $qr_code_url = get_post_meta( $post->ID, '_qr_code_url', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="barcode_type"><?php _e( 'Tipo de Código de Barras', 'lealez' ); ?></label></th>
                <td>
                    <select id="barcode_type" name="barcode_type" class="regular-text">
                        <option value=""><?php _e( 'Seleccionar...', 'lealez' ); ?></option>
                        <option value="qr_code" <?php selected( $barcode_type, 'qr_code' ); ?>><?php _e( 'QR Code', 'lealez' ); ?></option>
                        <option value="aztec" <?php selected( $barcode_type, 'aztec' ); ?>><?php _e( 'Aztec', 'lealez' ); ?></option>
                        <option value="code128" <?php selected( $barcode_type, 'code128' ); ?>><?php _e( 'Code 128', 'lealez' ); ?></option>
                        <option value="pdf417" <?php selected( $barcode_type, 'pdf417' ); ?>><?php _e( 'PDF417', 'lealez' ); ?></option>
                        <option value="ean13" <?php selected( $barcode_type, 'ean13' ); ?>><?php _e( 'EAN-13', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="barcode_value"><?php _e( 'Valor del Código de Barras', 'lealez' ); ?></label></th>
                <td><input type="text" id="barcode_value" name="barcode_value" value="<?php echo esc_attr( $barcode_value ); ?>" class="large-text" /></td>
            </tr>
            <tr>
                <th><label for="barcode_alt_text"><?php _e( 'Texto Alternativo', 'lealez' ); ?></label></th>
                <td><input type="text" id="barcode_alt_text" name="barcode_alt_text" value="<?php echo esc_attr( $barcode_alt_text ); ?>" class="large-text" /></td>
            </tr>
            <tr>
                <th><label for="qr_code_url"><?php _e( 'URL del QR Code Generado', 'lealez' ); ?></label></th>
                <td>
                    <input type="url" id="qr_code_url" name="qr_code_url" value="<?php echo esc_url( $qr_code_url ); ?>" class="large-text" readonly />
                    <?php if ( $qr_code_url ) : ?>
                        <br><img src="<?php echo esc_url( $qr_code_url ); ?>" style="max-width: 200px; margin-top: 10px;" />
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Locations Meta Box
     */
    public function render_locations_meta_box( $post ) {
        $enrollment_location_id = get_post_meta( $post->ID, '_enrollment_location_id', true );
        $last_location_used = get_post_meta( $post->ID, '_last_location_used', true );
        $favorite_locations = get_post_meta( $post->ID, '_favorite_locations', true );

        if ( ! is_array( $favorite_locations ) ) {
            $favorite_locations = array();
        }

        // Get all locations
        $locations = get_posts( array(
            'post_type'      => 'oy_location',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post_status'    => 'publish',
        ) );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="enrollment_location_id"><?php _e( 'Ubicación de Inscripción', 'lealez' ); ?></label></th>
                <td>
                    <select id="enrollment_location_id" name="enrollment_location_id" class="regular-text">
                        <option value=""><?php _e( 'Seleccionar...', 'lealez' ); ?></option>
                        <?php foreach ( $locations as $location ) : ?>
                            <option value="<?php echo esc_attr( $location->ID ); ?>" <?php selected( $enrollment_location_id, $location->ID ); ?>>
                                <?php echo esc_html( $location->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="last_location_used"><?php _e( 'Última Ubicación Usada', 'lealez' ); ?></label></th>
                <td>
                    <select id="last_location_used" name="last_location_used" class="regular-text">
                        <option value=""><?php _e( 'Seleccionar...', 'lealez' ); ?></option>
                        <?php foreach ( $locations as $location ) : ?>
                            <option value="<?php echo esc_attr( $location->ID ); ?>" <?php selected( $last_location_used, $location->ID ); ?>>
                                <?php echo esc_html( $location->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label><?php _e( 'Ubicaciones Favoritas', 'lealez' ); ?></label></th>
                <td>
                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                        <?php foreach ( $locations as $location ) : ?>
                            <p>
                                <label>
                                    <input type="checkbox" name="favorite_locations[]" value="<?php echo esc_attr( $location->ID ); ?>" <?php checked( in_array( $location->ID, $favorite_locations, true ) ); ?>>
                                    <?php echo esc_html( $location->post_title ); ?>
                                </label>
                            </p>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Activity Meta Box
     */
    public function render_activity_meta_box( $post ) {
        $total_transactions = get_post_meta( $post->ID, '_total_transactions', true );
        $total_visits = get_post_meta( $post->ID, '_total_visits', true );
        $total_purchases = get_post_meta( $post->ID, '_total_purchases', true );
        $total_spent = get_post_meta( $post->ID, '_total_spent', true );
        $average_transaction_value = get_post_meta( $post->ID, '_average_transaction_value', true );
        $date_last_activity = get_post_meta( $post->ID, '_date_last_activity', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="total_transactions"><?php _e( 'Total de Transacciones', 'lealez' ); ?></label></th>
                <td><input type="number" id="total_transactions" name="total_transactions" value="<?php echo esc_attr( $total_transactions ); ?>" class="small-text" min="0" readonly /></td>
            </tr>
            <tr>
                <th><label for="total_visits"><?php _e( 'Total de Visitas', 'lealez' ); ?></label></th>
                <td><input type="number" id="total_visits" name="total_visits" value="<?php echo esc_attr( $total_visits ); ?>" class="small-text" min="0" readonly /></td>
            </tr>
            <tr>
                <th><label for="total_purchases"><?php _e( 'Total de Compras', 'lealez' ); ?></label></th>
                <td><input type="number" id="total_purchases" name="total_purchases" value="<?php echo esc_attr( $total_purchases ); ?>" class="small-text" min="0" readonly /></td>
            </tr>
            <tr>
                <th><label for="total_spent"><?php _e( 'Total Gastado', 'lealez' ); ?></label></th>
                <td><input type="number" step="0.01" id="total_spent" name="total_spent" value="<?php echo esc_attr( $total_spent ); ?>" class="regular-text" min="0" readonly /></td>
            </tr>
            <tr>
                <th><label for="average_transaction_value"><?php _e( 'Valor Promedio de Transacción', 'lealez' ); ?></label></th>
                <td><input type="number" step="0.01" id="average_transaction_value" name="average_transaction_value" value="<?php echo esc_attr( $average_transaction_value ); ?>" class="regular-text" min="0" readonly /></td>
            </tr>
            <tr>
                <th><label for="date_last_activity"><?php _e( 'Última Actividad', 'lealez' ); ?></label></th>
                <td>
                    <input type="datetime-local" id="date_last_activity" name="date_last_activity" value="<?php echo esc_attr( $date_last_activity ); ?>" class="regular-text" readonly />
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Status Meta Box
     */
    public function render_status_meta_box( $post ) {
        $card_status = get_post_meta( $post->ID, '_card_status', true );
        $status_reason = get_post_meta( $post->ID, '_status_reason', true );
        $is_verified = get_post_meta( $post->ID, '_is_verified', true );
        $verification_method = get_post_meta( $post->ID, '_verification_method', true );
        $date_issued = get_post_meta( $post->ID, '_date_issued', true );
        $date_expires = get_post_meta( $post->ID, '_date_expires', true );

        if ( empty( $card_status ) ) {
            $card_status = 'active';
        }
        ?>
        <p>
            <label for="card_status"><strong><?php _e( 'Estado de la Tarjeta', 'lealez' ); ?></strong></label>
            <select id="card_status" name="card_status" class="widefat">
                <option value="active" <?php selected( $card_status, 'active' ); ?>><?php _e( 'Activa', 'lealez' ); ?></option>
                <option value="inactive" <?php selected( $card_status, 'inactive' ); ?>><?php _e( 'Inactiva', 'lealez' ); ?></option>
                <option value="suspended" <?php selected( $card_status, 'suspended' ); ?>><?php _e( 'Suspendida', 'lealez' ); ?></option>
                <option value="expired" <?php selected( $card_status, 'expired' ); ?>><?php _e( 'Expirada', 'lealez' ); ?></option>
                <option value="blocked" <?php selected( $card_status, 'blocked' ); ?>><?php _e( 'Bloqueada', 'lealez' ); ?></option>
                <option value="cancelled" <?php selected( $card_status, 'cancelled' ); ?>><?php _e( 'Cancelada', 'lealez' ); ?></option>
            </select>
        </p>
        <p>
            <label for="status_reason"><strong><?php _e( 'Razón del Estado', 'lealez' ); ?></strong></label>
            <textarea id="status_reason" name="status_reason" rows="3" class="widefat"><?php echo esc_textarea( $status_reason ); ?></textarea>
        </p>
        <p>
            <label>
                <input type="checkbox" id="is_verified" name="is_verified" value="1" <?php checked( $is_verified, '1' ); ?> />
                <strong><?php _e( 'Tarjeta Verificada', 'lealez' ); ?></strong>
            </label>
        </p>
        <p>
            <label for="verification_method"><strong><?php _e( 'Método de Verificación', 'lealez' ); ?></strong></label>
            <select id="verification_method" name="verification_method" class="widefat">
                <option value=""><?php _e( 'Seleccionar...', 'lealez' ); ?></option>
                <option value="email" <?php selected( $verification_method, 'email' ); ?>><?php _e( 'Email', 'lealez' ); ?></option>
                <option value="sms" <?php selected( $verification_method, 'sms' ); ?>><?php _e( 'SMS', 'lealez' ); ?></option>
                <option value="id_check" <?php selected( $verification_method, 'id_check' ); ?>><?php _e( 'Verificación de ID', 'lealez' ); ?></option>
                <option value="in_person" <?php selected( $verification_method, 'in_person' ); ?>><?php _e( 'En Persona', 'lealez' ); ?></option>
            </select>
        </p>
        <p>
            <label for="date_issued"><strong><?php _e( 'Fecha de Emisión', 'lealez' ); ?></strong></label>
            <input type="date" id="date_issued" name="date_issued" value="<?php echo esc_attr( $date_issued ); ?>" class="widefat" />
        </p>
        <p>
            <label for="date_expires"><strong><?php _e( 'Fecha de Expiración', 'lealez' ); ?></strong></label>
            <input type="date" id="date_expires" name="date_expires" value="<?php echo esc_attr( $date_expires ); ?>" class="widefat" />
        </p>
        <?php
    }

    /**
     * Render Security Meta Box
     */
    public function render_security_meta_box( $post ) {
        $pin_enabled = get_post_meta( $post->ID, '_pin_enabled', true );
        $two_factor_enabled = get_post_meta( $post->ID, '_two_factor_enabled', true );
        $account_locked = get_post_meta( $post->ID, '_account_locked', true );
        $failed_pin_attempts = get_post_meta( $post->ID, '_failed_pin_attempts', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label><?php _e( 'PIN Activado', 'lealez' ); ?></label></th>
                <td><input type="checkbox" id="pin_enabled" name="pin_enabled" value="1" <?php checked( $pin_enabled, '1' ); ?> /></td>
            </tr>
            <tr>
                <th><label><?php _e( '2FA Activado', 'lealez' ); ?></label></th>
                <td><input type="checkbox" id="two_factor_enabled" name="two_factor_enabled" value="1" <?php checked( $two_factor_enabled, '1' ); ?> /></td>
            </tr>
            <tr>
                <th><label><?php _e( 'Cuenta Bloqueada', 'lealez' ); ?></label></th>
                <td><input type="checkbox" id="account_locked" name="account_locked" value="1" <?php checked( $account_locked, '1' ); ?> /></td>
            </tr>
            <tr>
                <th><label for="failed_pin_attempts"><?php _e( 'Intentos Fallidos de PIN', 'lealez' ); ?></label></th>
                <td><input type="number" id="failed_pin_attempts" name="failed_pin_attempts" value="<?php echo esc_attr( $failed_pin_attempts ); ?>" class="small-text" min="0" readonly /></td>
            </tr>
        </table>
        <p class="description"><?php _e( 'El PIN se almacena encriptado y no puede ser visualizado.', 'lealez' ); ?></p>
        <?php
    }

    /**
     * Render Preferences Meta Box
     */
    public function render_preferences_meta_box( $post ) {
        $language_preference = get_post_meta( $post->ID, '_language_preference', true );
        $notification_opt_in = get_post_meta( $post->ID, '_notification_opt_in', true );
        $marketing_opt_in = get_post_meta( $post->ID, '_marketing_opt_in', true );
        $share_data_opt_in = get_post_meta( $post->ID, '_share_data_opt_in', true );
        $preferred_contact_method = get_post_meta( $post->ID, '_preferred_contact_method', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="language_preference"><?php _e( 'Idioma Preferido', 'lealez' ); ?></label></th>
                <td>
                    <select id="language_preference" name="language_preference" class="regular-text">
                        <option value=""><?php _e( 'Seleccionar...', 'lealez' ); ?></option>
                        <option value="es" <?php selected( $language_preference, 'es' ); ?>><?php _e( 'Español', 'lealez' ); ?></option>
                        <option value="en" <?php selected( $language_preference, 'en' ); ?>><?php _e( 'English', 'lealez' ); ?></option>
                        <option value="pt" <?php selected( $language_preference, 'pt' ); ?>><?php _e( 'Português', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label><?php _e( 'Aceptar Notificaciones', 'lealez' ); ?></label></th>
                <td><input type="checkbox" id="notification_opt_in" name="notification_opt_in" value="1" <?php checked( $notification_opt_in, '1' ); ?> /></td>
            </tr>
            <tr>
                <th><label><?php _e( 'Aceptar Marketing', 'lealez' ); ?></label></th>
                <td><input type="checkbox" id="marketing_opt_in" name="marketing_opt_in" value="1" <?php checked( $marketing_opt_in, '1' ); ?> /></td>
            </tr>
            <tr>
                <th><label><?php _e( 'Compartir Datos', 'lealez' ); ?></label></th>
                <td><input type="checkbox" id="share_data_opt_in" name="share_data_opt_in" value="1" <?php checked( $share_data_opt_in, '1' ); ?> /></td>
            </tr>
            <tr>
                <th><label for="preferred_contact_method"><?php _e( 'Método de Contacto Preferido', 'lealez' ); ?></label></th>
                <td>
                    <select id="preferred_contact_method" name="preferred_contact_method" class="regular-text">
                        <option value=""><?php _e( 'Seleccionar...', 'lealez' ); ?></option>
                        <option value="email" <?php selected( $preferred_contact_method, 'email' ); ?>><?php _e( 'Email', 'lealez' ); ?></option>
                        <option value="sms" <?php selected( $preferred_contact_method, 'sms' ); ?>><?php _e( 'SMS', 'lealez' ); ?></option>
                        <option value="phone" <?php selected( $preferred_contact_method, 'phone' ); ?>><?php _e( 'Teléfono', 'lealez' ); ?></option>
                        <option value="app" <?php selected( $preferred_contact_method, 'app' ); ?>><?php _e( 'App', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Referrals Meta Box
     */
    public function render_referrals_meta_box( $post ) {
        $referral_code = get_post_meta( $post->ID, '_referral_code', true );
        $referred_by_user_id = get_post_meta( $post->ID, '_referred_by_user_id', true );
        $referral_bonus_earned = get_post_meta( $post->ID, '_referral_bonus_earned', true );
        $total_referrals_made = get_post_meta( $post->ID, '_total_referrals_made', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="referral_code"><?php _e( 'Código de Referido', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" id="referral_code" name="referral_code" value="<?php echo esc_attr( $referral_code ); ?>" class="regular-text" readonly />
                    <p class="description"><?php _e( 'Código único para referir amigos.', 'lealez' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="referred_by_user_id"><?php _e( 'Referido Por (User ID)', 'lealez' ); ?></label></th>
                <td><input type="number" id="referred_by_user_id" name="referred_by_user_id" value="<?php echo esc_attr( $referred_by_user_id ); ?>" class="small-text" min="0" /></td>
            </tr>
            <tr>
                <th><label><?php _e( 'Bonus de Referido Recibido', 'lealez' ); ?></label></th>
                <td><input type="checkbox" id="referral_bonus_earned" name="referral_bonus_earned" value="1" <?php checked( $referral_bonus_earned, '1' ); ?> /></td>
            </tr>
            <tr>
                <th><label for="total_referrals_made"><?php _e( 'Total de Referidos Hechos', 'lealez' ); ?></label></th>
                <td><input type="number" id="total_referrals_made" name="total_referrals_made" value="<?php echo esc_attr( $total_referrals_made ); ?>" class="small-text" min="0" readonly /></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save meta boxes data
     */
    public function save_meta_boxes( $post_id, $post ) {
        // Verify nonce
        if ( ! isset( $_POST['lealez_card_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['lealez_card_meta_box_nonce'], 'lealez_card_meta_box' ) ) {
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
    // Relación
    'parent_business_id',
    'user_id',
    'card_number',

    // Titular
    'card_holder_name',
    'card_holder_email',
    'card_holder_phone',
    'member_id',
    'date_of_birth',
    'gender',
    'country_of_residence',
    'city_of_residence',
    'postal_code',

    // Puntos
    'current_points',
    'lifetime_points_earned',
    'lifetime_points_redeemed',
    'pending_points',
    'expired_points',

    // Tier
    'current_tier',
    'tier_points',
    'tier_since_date',

    // Digital Wallets
    'google_object_id',
    'google_save_url',
    'google_wallet_added',
    'apple_pass_serial_number',
    'apple_pass_url',
    'apple_wallet_added',

    // Barcodes
    'barcode_type',
    'barcode_value',
    'barcode_alt_text',
    'qr_code_url',

    // Ubicaciones
    'enrollment_location_id',
    'last_location_used',

    // Actividad
    'total_transactions',
    'total_visits',
    'total_purchases',
    'total_spent',
    'average_transaction_value',
    'date_last_activity',

    // Estado
    'card_status',
    'status_reason',
    'is_verified',
    'verification_method',
    'date_issued',
    'date_expires',

    // Seguridad
    'pin_enabled',
    'two_factor_enabled',
    'account_locked',
    'failed_pin_attempts',

    // Preferencias
    'language_preference',
    'notification_opt_in',
    'marketing_opt_in',
    'share_data_opt_in',
    'preferred_contact_method',

    // Referidos
    'referral_code',
    'referred_by_user_id',
    'referral_bonus_earned',
    'total_referrals_made',
);

        // Save text fields
        foreach ( $meta_fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, '_' . $field, sanitize_text_field( $_POST[ $field ] ) );
            }
        }

        // Save favorite locations (array)
        if ( isset( $_POST['favorite_locations'] ) && is_array( $_POST['favorite_locations'] ) ) {
            $favorite_locations = array_map( 'absint', $_POST['favorite_locations'] );
            update_post_meta( $post_id, '_favorite_locations', $favorite_locations );
        } else {
            update_post_meta( $post_id, '_favorite_locations', array() );
        }

        // Set date_created if new post
        if ( ! get_post_meta( $post_id, '_date_created', true ) ) {
            update_post_meta( $post_id, '_date_created', current_time( 'mysql' ) );
            update_post_meta( $post_id, '_created_by_user_id', get_current_user_id() );
        }

        // Always update date_modified
        update_post_meta( $post_id, '_date_modified', current_time( 'mysql' ) );
        update_post_meta( $post_id, '_modified_by_user_id', get_current_user_id() );
    }

    /**
     * Auto-generate card number on creation
     */
    public function auto_generate_card_number( $post_id, $post, $update ) {
        // Only for new oy_loyalty_card posts
        if ( $post->post_type !== $this->post_type || $update ) {
            return;
        }

        // Check if card number already exists
        $card_number = get_post_meta( $post_id, '_card_number', true );
        if ( ! empty( $card_number ) ) {
            return;
        }

        // Generate unique card number
        $prefix = 'LEALEZ';
        $timestamp = time();
        $random = wp_rand( 1000, 9999 );
        $card_number = $prefix . '-' . $timestamp . '-' . $random;

        // Save card number
        update_post_meta( $post_id, '_card_number', $card_number );

        // Generate referral code
        $referral_code = strtoupper( substr( md5( $card_number ), 0, 8 ) );
        update_post_meta( $post_id, '_referral_code', $referral_code );

        // Set date_issued
        update_post_meta( $post_id, '_date_issued', current_time( 'mysql' ) );
    }

/**
 * Update parent business counters
 */
public function update_parent_program_counters( $post_id, $post ) {
    if ( $post->post_status !== 'publish' ) {
        return;
    }

    $parent_business_id = get_post_meta( $post_id, '_parent_business_id', true );
    if ( ! $parent_business_id ) {
        return;
    }

    // Count all published cards for this business
    $cards = get_posts( array(
        'post_type'      => $this->post_type,
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => array(
            array(
                'key'   => '_parent_business_id',
                'value' => $parent_business_id,
            ),
        ),
        'fields'         => 'ids',
    ) );

    update_post_meta( $parent_business_id, '_total_cards_issued', count( $cards ) );
}

    /**
     * Update counters on card deletion
     */
    public function update_counters_on_delete( $post_id ) {
        if ( get_post_type( $post_id ) !== $this->post_type ) {
            return;
        }

        $parent_program_id = get_post_meta( $post_id, '_parent_program_id', true );
        if ( $parent_program_id ) {
            // Schedule counter update after deletion
            wp_schedule_single_event( time() + 5, 'lealez_update_program_card_counter', array( $parent_program_id ) );
        }
    }

/**
 * Set custom columns for admin list
 */
public function set_custom_columns( $columns ) {
    $new_columns = array();
    $new_columns['cb'] = $columns['cb'];
    $new_columns['title'] = __( 'Tarjeta', 'lealez' );
    $new_columns['card_number'] = __( 'Número', 'lealez' );
    $new_columns['card_holder'] = __( 'Titular', 'lealez' );
    $new_columns['business'] = __( 'Empresa', 'lealez' );
    $new_columns['categories'] = __( 'Categorías', 'lealez' );
    $new_columns['current_points'] = __( 'Puntos', 'lealez' );
    $new_columns['tier'] = __( 'Nivel', 'lealez' );
    $new_columns['status'] = __( 'Estado', 'lealez' );
    $new_columns['date'] = __( 'Fecha', 'lealez' );

    return $new_columns;
}

/**
 * Display custom column content
 */
public function custom_column_content( $column, $post_id ) {
    switch ( $column ) {
        case 'card_number':
            $card_number = get_post_meta( $post_id, '_card_number', true );
            echo $card_number ? '<code>' . esc_html( $card_number ) . '</code>' : '—';
            break;

        case 'card_holder':
            $card_holder_name = get_post_meta( $post_id, '_card_holder_name', true );
            $card_holder_email = get_post_meta( $post_id, '_card_holder_email', true );
            if ( $card_holder_name ) {
                echo esc_html( $card_holder_name );
                if ( $card_holder_email ) {
                    echo '<br><small>' . esc_html( $card_holder_email ) . '</small>';
                }
            } else {
                echo '—';
            }
            break;

        case 'business':
            $parent_business_id = get_post_meta( $post_id, '_parent_business_id', true );
            if ( $parent_business_id ) {
                echo '<a href="' . esc_url( get_edit_post_link( $parent_business_id ) ) . '">' . esc_html( get_the_title( $parent_business_id ) ) . '</a>';
            } else {
                echo '<span style="color: #dc3232;">—</span>';
            }
            break;

        case 'categories':
            $terms = get_the_terms( $post_id, 'oy_customer_category' );
            if ( $terms && ! is_wp_error( $terms ) ) {
                $category_list = array();
                foreach ( $terms as $term ) {
                    $category_color = get_term_meta( $term->term_id, 'category_color', true );
                    $color_style = $category_color ? 'background-color: ' . esc_attr( $category_color ) . ';' : '';
                    $category_list[] = '<span style="display: inline-block; padding: 2px 8px; ' . $color_style . ' color: #fff; border-radius: 3px; font-size: 11px; margin: 2px;">' . esc_html( $term->name ) . '</span>';
                }
                echo implode( ' ', $category_list );
            } else {
                echo '—';
            }
            break;

        case 'current_points':
            $current_points = get_post_meta( $post_id, '_current_points', true );
            echo esc_html( $current_points ? number_format( $current_points ) : '0' );
            break;

        case 'tier':
            $current_tier = get_post_meta( $post_id, '_current_tier', true );
            $tier_labels = array(
                'bronze'   => '<span style="color: #cd7f32;">● Bronce</span>',
                'silver'   => '<span style="color: #c0c0c0;">● Plata</span>',
                'gold'     => '<span style="color: #ffd700;">● Oro</span>',
                'platinum' => '<span style="color: #e5e4e2;">● Platino</span>',
            );
            echo isset( $tier_labels[ $current_tier ] ) ? wp_kses_post( $tier_labels[ $current_tier ] ) : '—';
            break;

        case 'status':
            $card_status = get_post_meta( $post_id, '_card_status', true );
            $status_labels = array(
                'active'    => '<span style="color: #46b450;">● Activa</span>',
                'inactive'  => '<span style="color: #999;">● Inactiva</span>',
                'suspended' => '<span style="color: #dc3232;">● Suspendida</span>',
                'expired'   => '<span style="color: #999;">● Expirada</span>',
                'blocked'   => '<span style="color: #dc3232;">● Bloqueada</span>',
                'cancelled' => '<span style="color: #333;">● Cancelada</span>',
            );
            echo isset( $status_labels[ $card_status ] ) ? wp_kses_post( $status_labels[ $card_status ] ) : '—';
            break;
    }
}

    /**
     * Set sortable columns
     */
    public function set_sortable_columns( $columns ) {
        $columns['card_number'] = 'card_number';
        $columns['current_points'] = 'current_points';
        $columns['status'] = 'status';
        return $columns;
    }
}

// Initialize the class
new Lealez_Loyalty_Card_CPT();
