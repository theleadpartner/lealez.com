<?php
/**
 * Loyalty Program Custom Post Type
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
 * Class OY_Loyalty_Program_CPT
 *
 * Handles the Loyalty Program custom post type registration and functionality
 */
class OY_Loyalty_Program_CPT {

    /**
     * Post type slug
     *
     * @var string
     */
    const POST_TYPE = 'oy_loyalty_program';

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta_boxes' ), 10, 2 );
        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'set_custom_columns' ) );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'custom_column_content' ), 10, 2 );
        add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( $this, 'set_sortable_columns' ) );
    }

/**
 * Register the Loyalty Program custom post type
 */
public function register_post_type() {
    $labels = array(
        'name'                  => _x( 'Programas de Lealtad', 'Post Type General Name', 'lealez' ),
        'singular_name'         => _x( 'Programa de Lealtad', 'Post Type Singular Name', 'lealez' ),
        'menu_name'             => __( 'Programas de Lealtad', 'lealez' ),
        'name_admin_bar'        => __( 'Programa de Lealtad', 'lealez' ),
        'archives'              => __( 'Archivos de Programas', 'lealez' ),
        'attributes'            => __( 'Atributos del Programa', 'lealez' ),
        'parent_item_colon'     => __( 'Programa Padre:', 'lealez' ),
        'all_items'             => __( 'Todos los Programas', 'lealez' ),
        'add_new_item'          => __( 'Agregar Nuevo Programa', 'lealez' ),
        'add_new'               => __( 'Agregar Nuevo', 'lealez' ),
        'new_item'              => __( 'Nuevo Programa', 'lealez' ),
        'edit_item'             => __( 'Editar Programa', 'lealez' ),
        'update_item'           => __( 'Actualizar Programa', 'lealez' ),
        'view_item'             => __( 'Ver Programa', 'lealez' ),
        'view_items'            => __( 'Ver Programas', 'lealez' ),
        'search_items'          => __( 'Buscar Programa', 'lealez' ),
        'not_found'             => __( 'No encontrado', 'lealez' ),
        'not_found_in_trash'    => __( 'No encontrado en la papelera', 'lealez' ),
        'featured_image'        => __( 'Imagen destacada', 'lealez' ),
        'set_featured_image'    => __( 'Establecer imagen destacada', 'lealez' ),
        'remove_featured_image' => __( 'Remover imagen destacada', 'lealez' ),
        'use_featured_image'    => __( 'Usar como imagen destacada', 'lealez' ),
        'insert_into_item'      => __( 'Insertar en programa', 'lealez' ),
        'uploaded_to_this_item' => __( 'Subido a este programa', 'lealez' ),
        'items_list'            => __( 'Lista de programas', 'lealez' ),
        'items_list_navigation' => __( 'Navegación de lista de programas', 'lealez' ),
        'filter_items_list'     => __( 'Filtrar lista de programas', 'lealez' ),
    );

    $args = array(
        'label'               => __( 'Programa de Lealtad', 'lealez' ),
        'description'         => __( 'Gestión de Programas de Lealtad', 'lealez' ),
        'labels'              => $labels,
        'supports'            => array( 'title', 'thumbnail', 'author' ),
        'hierarchical'        => false,
        'public'              => true,
        'show_ui'             => true,
        'show_in_menu'        => false,
        'menu_position'       => 25,
        'menu_icon'           => 'dashicons-awards',
        'show_in_admin_bar'   => true,
        'show_in_nav_menus'   => true,
        'can_export'          => true,
        'has_archive'         => true,
        'exclude_from_search' => false,
        'publicly_queryable'  => true,
        'capability_type'     => 'post',
        'show_in_rest'        => false,
        'rest_base'           => 'loyalty-programs',
        'rewrite'             => array(
            'slug'       => 'loyalty-program',
            'with_front' => false,
        ),
    );

    register_post_type( self::POST_TYPE, $args );
}

    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        // Información Básica
        add_meta_box(
            'loyalty_program_basic_info',
            __( 'Información Básica del Programa', 'lealez' ),
            array( $this, 'render_basic_info_meta_box' ),
            self::POST_TYPE,
            'normal',
            'high'
        );

        // Alcance Geográfico
        add_meta_box(
            'loyalty_program_scope',
            __( 'Alcance Geográfico', 'lealez' ),
            array( $this, 'render_scope_meta_box' ),
            self::POST_TYPE,
            'normal',
            'high'
        );

        // Mecánica de Puntos
        add_meta_box(
            'loyalty_program_points_mechanics',
            __( 'Mecánica de Puntos/Recompensas', 'lealez' ),
            array( $this, 'render_points_mechanics_meta_box' ),
            self::POST_TYPE,
            'normal',
            'default'
        );

        // Diseño de Tarjeta
        add_meta_box(
            'loyalty_program_card_design',
            __( 'Diseño de Tarjeta', 'lealez' ),
            array( $this, 'render_card_design_meta_box' ),
            self::POST_TYPE,
            'side',
            'default'
        );

        // Estadísticas
        add_meta_box(
            'loyalty_program_statistics',
            __( 'Estadísticas del Programa', 'lealez' ),
            array( $this, 'render_statistics_meta_box' ),
            self::POST_TYPE,
            'side',
            'low'
        );
    }

    /**
     * Render Basic Info meta box
     */
    public function render_basic_info_meta_box( $post ) {
        wp_nonce_field( 'loyalty_program_meta_box', 'loyalty_program_meta_box_nonce' );

        $parent_business_id = get_post_meta( $post->ID, 'parent_business_id', true );
        $program_type       = get_post_meta( $post->ID, 'program_type', true );
        $program_status     = get_post_meta( $post->ID, 'program_status', true );
        $currency_name      = get_post_meta( $post->ID, 'program_currency_name', true );
        $currency_plural    = get_post_meta( $post->ID, 'program_currency_plural', true );

        // Get all businesses
        $businesses = get_posts(
            array(
                'post_type'      => 'oy_business',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );

        ?>
        <table class="form-table">
            <tr>
                <th><label for="parent_business_id"><?php esc_html_e( 'Empresa/Negocio', 'lealez' ); ?></label></th>
                <td>
                    <select name="parent_business_id" id="parent_business_id" class="regular-text" required>
                        <option value=""><?php esc_html_e( 'Seleccionar Empresa', 'lealez' ); ?></option>
                        <?php foreach ( $businesses as $business ) : ?>
                            <option value="<?php echo esc_attr( $business->ID ); ?>" <?php selected( $parent_business_id, $business->ID ); ?>>
                                <?php echo esc_html( get_the_title( $business->ID ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Selecciona la empresa a la que pertenece este programa.', 'lealez' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="program_type"><?php esc_html_e( 'Tipo de Programa', 'lealez' ); ?></label></th>
                <td>
                    <select name="program_type" id="program_type" class="regular-text">
                        <option value="points" <?php selected( $program_type, 'points' ); ?>><?php esc_html_e( 'Puntos', 'lealez' ); ?></option>
                        <option value="stamps" <?php selected( $program_type, 'stamps' ); ?>><?php esc_html_e( 'Sellos', 'lealez' ); ?></option>
                        <option value="visits" <?php selected( $program_type, 'visits' ); ?>><?php esc_html_e( 'Visitas', 'lealez' ); ?></option>
                        <option value="cashback" <?php selected( $program_type, 'cashback' ); ?>><?php esc_html_e( 'Cashback', 'lealez' ); ?></option>
                        <option value="tiered" <?php selected( $program_type, 'tiered' ); ?>><?php esc_html_e( 'Por Niveles', 'lealez' ); ?></option>
                        <option value="hybrid" <?php selected( $program_type, 'hybrid' ); ?>><?php esc_html_e( 'Híbrido', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>

            <tr>
                <th><label for="program_currency_name"><?php esc_html_e( 'Nombre de Moneda (Singular)', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" name="program_currency_name" id="program_currency_name" value="<?php echo esc_attr( $currency_name ); ?>" class="regular-text" placeholder="Punto, Estrella, Milla">
                    <p class="description"><?php esc_html_e( 'Ej: Punto, Estrella, Milla', 'lealez' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="program_currency_plural"><?php esc_html_e( 'Nombre de Moneda (Plural)', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" name="program_currency_plural" id="program_currency_plural" value="<?php echo esc_attr( $currency_plural ); ?>" class="regular-text" placeholder="Puntos, Estrellas, Millas">
                    <p class="description"><?php esc_html_e( 'Ej: Puntos, Estrellas, Millas', 'lealez' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="program_status"><?php esc_html_e( 'Estado del Programa', 'lealez' ); ?></label></th>
                <td>
                    <select name="program_status" id="program_status" class="regular-text">
                        <option value="active" <?php selected( $program_status, 'active' ); ?>><?php esc_html_e( 'Activo', 'lealez' ); ?></option>
                        <option value="inactive" <?php selected( $program_status, 'inactive' ); ?>><?php esc_html_e( 'Inactivo', 'lealez' ); ?></option>
                        <option value="draft" <?php selected( $program_status, 'draft' ); ?>><?php esc_html_e( 'Borrador', 'lealez' ); ?></option>
                        <option value="archived" <?php selected( $program_status, 'archived' ); ?>><?php esc_html_e( 'Archivado', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Scope meta box (CAMPO CRÍTICO)
     */
    public function render_scope_meta_box( $post ) {
        $program_scope         = get_post_meta( $post->ID, 'program_scope', true );
        $applicable_locations  = get_post_meta( $post->ID, 'applicable_locations', true );
        $parent_business_id    = get_post_meta( $post->ID, 'parent_business_id', true );

        if ( ! is_array( $applicable_locations ) ) {
            $applicable_locations = array();
        }

        // Get locations from parent business
        $locations = array();
        if ( $parent_business_id ) {
            $locations = get_posts(
                array(
                    'post_type'      => 'oy_location',
                    'posts_per_page' => -1,
                    'meta_query'     => array(
                        array(
                            'key'     => 'parent_business_id',
                            'value'   => $parent_business_id,
                            'compare' => '=',
                        ),
                    ),
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                )
            );
        }

        ?>
        <div class="loyalty-scope-settings">
            <p class="description" style="margin-bottom: 15px;">
                <strong><?php esc_html_e( 'Define dónde es válido este programa de lealtad:', 'lealez' ); ?></strong>
            </p>

            <p>
                <label>
                    <input type="radio" name="program_scope" value="global" <?php checked( $program_scope, 'global' ); ?> class="program-scope-radio">
                    <strong><?php esc_html_e( 'Global', 'lealez' ); ?></strong> - <?php esc_html_e( 'Válido en todas las ubicaciones del negocio', 'lealez' ); ?>
                </label>
            </p>

            <p>
                <label>
                    <input type="radio" name="program_scope" value="specific_locations" <?php checked( $program_scope, 'specific_locations' ); ?> class="program-scope-radio">
                    <strong><?php esc_html_e( 'Ubicaciones Específicas', 'lealez' ); ?></strong> - <?php esc_html_e( 'Selecciona las ubicaciones donde aplica', 'lealez' ); ?>
                </label>
            </p>

            <p>
                <label>
                    <input type="radio" name="program_scope" value="single_location" <?php checked( $program_scope, 'single_location' ); ?> class="program-scope-radio">
                    <strong><?php esc_html_e( 'Una Sola Ubicación', 'lealez' ); ?></strong> - <?php esc_html_e( 'Válido solo en una ubicación', 'lealez' ); ?>
                </label>
            </p>

            <div id="locations-selector" style="margin-top: 20px; <?php echo ( 'global' === $program_scope ) ? 'display:none;' : ''; ?>">
                <hr>
                <h4><?php esc_html_e( 'Selecciona las Ubicaciones', 'lealez' ); ?></h4>
                <?php if ( ! empty( $locations ) ) : ?>
                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                        <?php foreach ( $locations as $location ) : ?>
                            <p>
                                <label>
                                    <input type="checkbox" name="applicable_locations[]" value="<?php echo esc_attr( $location->ID ); ?>" <?php checked( in_array( $location->ID, $applicable_locations, true ) ); ?>>
                                    <?php echo esc_html( get_the_title( $location->ID ) ); ?>
                                    <?php
                                    $city = get_post_meta( $location->ID, 'location_city', true );
                                    if ( $city ) {
                                        echo ' - ' . esc_html( $city );
                                    }
                                    ?>
                                </label>
                            </p>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <p class="description"><?php esc_html_e( 'No hay ubicaciones disponibles. Por favor, crea ubicaciones primero o selecciona una empresa.', 'lealez' ); ?></p>
                <?php endif; ?>
            </div>

            <hr style="margin: 20px 0;">

            <h3><?php _e( 'Categorías de Cliente Aplicables', 'lealez' ); ?></h3>
            <p class="description">
                <?php _e( 'Selecciona las categorías de cliente que pueden acceder a este programa de lealtad.', 'lealez' ); ?>
            </p>

            <?php
            $applicable_customer_categories = get_post_meta( $post->ID, 'applicable_customer_categories', true );
            if ( ! is_array( $applicable_customer_categories ) ) {
                $applicable_customer_categories = array();
            }

            // Get customer categories
            $customer_categories = get_terms( array(
                'taxonomy'   => 'oy_customer_category',
                'hide_empty' => false,
            ) );

            if ( ! empty( $customer_categories ) && ! is_wp_error( $customer_categories ) ) :
                ?>
                <div style="margin-top: 15px; border: 1px solid #ddd; padding: 15px; background: #f9f9f9; max-height: 300px; overflow-y: auto;">
                    <?php foreach ( $customer_categories as $category ) :
                        $category_color = get_term_meta( $category->term_id, 'category_color', true );
                        $category_type = get_term_meta( $category->term_id, 'category_type', true );
                        $parent_business_id_cat = get_term_meta( $category->term_id, 'parent_business_id', true );
                        
                        // Only show categories from the same business
                        if ( $parent_business_id && $parent_business_id_cat != $parent_business_id ) {
                            continue;
                        }
                        ?>
                        <p>
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" name="applicable_customer_categories[]" value="<?php echo esc_attr( $category->term_id ); ?>" <?php checked( in_array( $category->term_id, $applicable_customer_categories, true ) ); ?>>
                                <?php if ( $category_color ) : ?>
                                    <span style="display: inline-block; width: 20px; height: 20px; background-color: <?php echo esc_attr( $category_color ); ?>; border: 1px solid #ddd; border-radius: 3px;"></span>
                                <?php endif; ?>
                                <strong><?php echo esc_html( $category->name ); ?></strong>
                                <?php if ( $category_type === 'restrictive' ) : ?>
                                    <span style="color: #dc3232; font-size: 11px;">(<?php _e( 'Restrictiva', 'lealez' ); ?>)</span>
                                <?php else : ?>
                                    <span style="color: #46b450; font-size: 11px;">(<?php _e( 'Acumulativa', 'lealez' ); ?>)</span>
                                <?php endif; ?>
                            </label>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="description" style="color: #dc3232;">
                    <?php _e( 'No hay categorías de cliente disponibles. Por favor, crea categorías de cliente primero.', 'lealez' ); ?>
                </p>
            <?php endif; ?>
            
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.program-scope-radio').on('change', function() {
                if ($(this).val() === 'global') {
                    $('#locations-selector').slideUp();
                } else {
                    $('#locations-selector').slideDown();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render Points Mechanics meta box
     */
    public function render_points_mechanics_meta_box( $post ) {
        $points_per_currency      = get_post_meta( $post->ID, 'points_per_currency', true );
        $min_purchase_for_points  = get_post_meta( $post->ID, 'min_purchase_for_points', true );
        $points_rounding          = get_post_meta( $post->ID, 'points_rounding', true );
        $welcome_bonus_points     = get_post_meta( $post->ID, 'welcome_bonus_points', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="points_per_currency"><?php esc_html_e( 'Puntos por Unidad Monetaria', 'lealez' ); ?></label></th>
                <td>
                    <input type="number" step="0.01" name="points_per_currency" id="points_per_currency" value="<?php echo esc_attr( $points_per_currency ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Ej: 1 = 1 punto por cada $1 gastado', 'lealez' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="min_purchase_for_points"><?php esc_html_e( 'Compra Mínima para Puntos', 'lealez' ); ?></label></th>
                <td>
                    <input type="number" step="0.01" name="min_purchase_for_points" id="min_purchase_for_points" value="<?php echo esc_attr( $min_purchase_for_points ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Monto mínimo de compra para ganar puntos', 'lealez' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="points_rounding"><?php esc_html_e( 'Redondeo de Puntos', 'lealez' ); ?></label></th>
                <td>
                    <select name="points_rounding" id="points_rounding" class="regular-text">
                        <option value="round_up" <?php selected( $points_rounding, 'round_up' ); ?>><?php esc_html_e( 'Redondear hacia arriba', 'lealez' ); ?></option>
                        <option value="round_down" <?php selected( $points_rounding, 'round_down' ); ?>><?php esc_html_e( 'Redondear hacia abajo', 'lealez' ); ?></option>
                        <option value="round_nearest" <?php selected( $points_rounding, 'round_nearest' ); ?>><?php esc_html_e( 'Redondear al más cercano', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>

            <tr>
                <th><label for="welcome_bonus_points"><?php esc_html_e( 'Puntos de Bienvenida', 'lealez' ); ?></label></th>
                <td>
                    <input type="number" name="welcome_bonus_points" id="welcome_bonus_points" value="<?php echo esc_attr( $welcome_bonus_points ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Puntos otorgados al inscribirse al programa', 'lealez' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Card Design meta box
     */
    public function render_card_design_meta_box( $post ) {
        $card_design_template   = get_post_meta( $post->ID, 'card_design_template', true );
        $card_background_color  = get_post_meta( $post->ID, 'card_background_color', true );
        $card_text_color        = get_post_meta( $post->ID, 'card_text_color', true );
        $card_show_barcode      = get_post_meta( $post->ID, 'card_show_barcode', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="card_design_template"><?php esc_html_e( 'Plantilla', 'lealez' ); ?></label></th>
                <td>
                    <select name="card_design_template" id="card_design_template" class="widefat">
                        <option value="default" <?php selected( $card_design_template, 'default' ); ?>><?php esc_html_e( 'Predeterminada', 'lealez' ); ?></option>
                        <option value="minimal" <?php selected( $card_design_template, 'minimal' ); ?>><?php esc_html_e( 'Minimalista', 'lealez' ); ?></option>
                        <option value="premium" <?php selected( $card_design_template, 'premium' ); ?>><?php esc_html_e( 'Premium', 'lealez' ); ?></option>
                        <option value="custom" <?php selected( $card_design_template, 'custom' ); ?>><?php esc_html_e( 'Personalizada', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>

            <tr>
                <th><label for="card_background_color"><?php esc_html_e( 'Color de Fondo', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" name="card_background_color" id="card_background_color" value="<?php echo esc_attr( $card_background_color ); ?>" class="color-picker" data-default-color="#0073aa">
                </td>
            </tr>

            <tr>
                <th><label for="card_text_color"><?php esc_html_e( 'Color de Texto', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" name="card_text_color" id="card_text_color" value="<?php echo esc_attr( $card_text_color ); ?>" class="color-picker" data-default-color="#ffffff">
                </td>
            </tr>

            <tr>
                <th><label for="card_show_barcode"><?php esc_html_e( 'Mostrar Código de Barras', 'lealez' ); ?></label></th>
                <td>
                    <input type="checkbox" name="card_show_barcode" id="card_show_barcode" value="1" <?php checked( $card_show_barcode, '1' ); ?>>
                </td>
            </tr>
        </table>

        <script>
        jQuery(document).ready(function($) {
            $('.color-picker').wpColorPicker();
        });
        </script>
        <?php
    }

    /**
     * Render Statistics meta box
     */
    public function render_statistics_meta_box( $post ) {
        $total_members       = get_post_meta( $post->ID, 'total_members', true );
        $total_cards_issued  = get_post_meta( $post->ID, 'total_cards_issued', true );
        $total_points_issued = get_post_meta( $post->ID, 'total_points_issued', true );
        ?>
        <div class="loyalty-stats">
            <p><strong><?php esc_html_e( 'Total Miembros:', 'lealez' ); ?></strong> <?php echo esc_html( $total_members ? number_format( $total_members ) : '0' ); ?></p>
            <p><strong><?php esc_html_e( 'Tarjetas Emitidas:', 'lealez' ); ?></strong> <?php echo esc_html( $total_cards_issued ? number_format( $total_cards_issued ) : '0' ); ?></p>
            <p><strong><?php esc_html_e( 'Puntos Emitidos:', 'lealez' ); ?></strong> <?php echo esc_html( $total_points_issued ? number_format( $total_points_issued ) : '0' ); ?></p>
        </div>
        <?php
    }

    /**
     * Save meta boxes data
     */
    public function save_meta_boxes( $post_id, $post ) {
        // Verify nonce
        if ( ! isset( $_POST['loyalty_program_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['loyalty_program_meta_box_nonce'], 'loyalty_program_meta_box' ) ) {
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

        // Save parent_business_id
        if ( isset( $_POST['parent_business_id'] ) ) {
            update_post_meta( $post_id, 'parent_business_id', absint( $_POST['parent_business_id'] ) );
        }

        // Save program_type
        if ( isset( $_POST['program_type'] ) ) {
            update_post_meta( $post_id, 'program_type', sanitize_text_field( $_POST['program_type'] ) );
        }

        // Save program_status
        if ( isset( $_POST['program_status'] ) ) {
            update_post_meta( $post_id, 'program_status', sanitize_text_field( $_POST['program_status'] ) );
        }

        // Save currency names
        if ( isset( $_POST['program_currency_name'] ) ) {
            update_post_meta( $post_id, 'program_currency_name', sanitize_text_field( $_POST['program_currency_name'] ) );
        }
        if ( isset( $_POST['program_currency_plural'] ) ) {
            update_post_meta( $post_id, 'program_currency_plural', sanitize_text_field( $_POST['program_currency_plural'] ) );
        }

        // Save program_scope (CRÍTICO)
        if ( isset( $_POST['program_scope'] ) ) {
            $program_scope = sanitize_text_field( $_POST['program_scope'] );
            update_post_meta( $post_id, 'program_scope', $program_scope );

            // Save applicable_locations
            if ( 'global' !== $program_scope && isset( $_POST['applicable_locations'] ) ) {
                $locations = array_map( 'absint', $_POST['applicable_locations'] );
                update_post_meta( $post_id, 'applicable_locations', $locations );
            } else {
                // If global, clear specific locations
                delete_post_meta( $post_id, 'applicable_locations' );
            }

            // Save applicable_customer_categories
            if ( isset( $_POST['applicable_customer_categories'] ) && is_array( $_POST['applicable_customer_categories'] ) ) {
                $categories = array_map( 'absint', $_POST['applicable_customer_categories'] );
                update_post_meta( $post_id, 'applicable_customer_categories', $categories );
            } else {
                update_post_meta( $post_id, 'applicable_customer_categories', array() );
            }
            
        }

        // Save points mechanics - USANDO SANITIZACIÓN DIRECTA
        if ( isset( $_POST['points_per_currency'] ) ) {
            $value = sanitize_text_field( $_POST['points_per_currency'] );
            update_post_meta( $post_id, 'points_per_currency', $value );
        }
        if ( isset( $_POST['min_purchase_for_points'] ) ) {
            $value = sanitize_text_field( $_POST['min_purchase_for_points'] );
            update_post_meta( $post_id, 'min_purchase_for_points', $value );
        }
        if ( isset( $_POST['points_rounding'] ) ) {
            update_post_meta( $post_id, 'points_rounding', sanitize_text_field( $_POST['points_rounding'] ) );
        }
        if ( isset( $_POST['welcome_bonus_points'] ) ) {
            update_post_meta( $post_id, 'welcome_bonus_points', absint( $_POST['welcome_bonus_points'] ) );
        }

        // Save card design
        if ( isset( $_POST['card_design_template'] ) ) {
            update_post_meta( $post_id, 'card_design_template', sanitize_text_field( $_POST['card_design_template'] ) );
        }
        if ( isset( $_POST['card_background_color'] ) ) {
            update_post_meta( $post_id, 'card_background_color', sanitize_hex_color( $_POST['card_background_color'] ) );
        }
        if ( isset( $_POST['card_text_color'] ) ) {
            update_post_meta( $post_id, 'card_text_color', sanitize_hex_color( $_POST['card_text_color'] ) );
        }
        update_post_meta( $post_id, 'card_show_barcode', isset( $_POST['card_show_barcode'] ) ? '1' : '0' );

        // Auto-generate program_slug if not set
        $program_slug = get_post_meta( $post_id, 'program_slug', true );
        if ( empty( $program_slug ) ) {
            $program_slug = sanitize_title( $post->post_title );
            update_post_meta( $post_id, 'program_slug', $program_slug );
        }

        // Save creation metadata
        $date_created = get_post_meta( $post_id, 'date_created', true );
        if ( empty( $date_created ) ) {
            update_post_meta( $post_id, 'date_created', current_time( 'mysql' ) );
            update_post_meta( $post_id, 'created_by_user_id', get_current_user_id() );
        }

        // Update modification metadata
        update_post_meta( $post_id, 'date_modified', current_time( 'mysql' ) );
    }

    /**
     * Set custom columns for admin list
     */
    public function set_custom_columns( $columns ) {
        $new_columns = array(
            'cb'             => $columns['cb'],
            'title'          => __( 'Nombre del Programa', 'lealez' ),
            'business'       => __( 'Empresa', 'lealez' ),
            'program_type'   => __( 'Tipo', 'lealez' ),
            'program_scope'  => __( 'Alcance', 'lealez' ),
            'total_members'  => __( 'Miembros', 'lealez' ),
            'program_status' => __( 'Estado', 'lealez' ),
            'date'           => $columns['date'],
        );

        return $new_columns;
    }

    /**
     * Render custom column content
     */
    public function custom_column_content( $column, $post_id ) {
        switch ( $column ) {
            case 'business':
                $business_id = get_post_meta( $post_id, 'parent_business_id', true );
                if ( $business_id ) {
                    echo '<a href="' . esc_url( get_edit_post_link( $business_id ) ) . '">' . esc_html( get_the_title( $business_id ) ) . '</a>';
                } else {
                    echo '<span style="color: #999;">' . esc_html__( 'Sin empresa', 'lealez' ) . '</span>';
                }
                break;

            case 'program_type':
                $program_type = get_post_meta( $post_id, 'program_type', true );
                $types        = array(
                    'points'   => __( 'Puntos', 'lealez' ),
                    'stamps'   => __( 'Sellos', 'lealez' ),
                    'visits'   => __( 'Visitas', 'lealez' ),
                    'cashback' => __( 'Cashback', 'lealez' ),
                    'tiered'   => __( 'Niveles', 'lealez' ),
                    'hybrid'   => __( 'Híbrido', 'lealez' ),
                );
                echo isset( $types[ $program_type ] ) ? esc_html( $types[ $program_type ] ) : '—';
                break;

            case 'program_scope':
                $program_scope = get_post_meta( $post_id, 'program_scope', true );
                $scopes        = array(
                    'global'             => '<span style="color: #0073aa;">&#x25CF;</span> ' . __( 'Global', 'lealez' ),
                    'specific_locations' => '<span style="color: #ca4a1f;">&#x25CF;</span> ' . __( 'Específicas', 'lealez' ),
                    'single_location'    => '<span style="color: #7ad03a;">&#x25CF;</span> ' . __( 'Una ubicación', 'lealez' ),
                );
                echo isset( $scopes[ $program_scope ] ) ? wp_kses_post( $scopes[ $program_scope ] ) : '—';
                break;

            case 'total_members':
                $total_members = get_post_meta( $post_id, 'total_members', true );
                echo esc_html( $total_members ? number_format( $total_members ) : '0' );
                break;

            case 'program_status':
                $program_status = get_post_meta( $post_id, 'program_status', true );
                $statuses       = array(
                    'active'   => '<span style="color: #7ad03a;">&#x25CF;</span> ' . __( 'Activo', 'lealez' ),
                    'inactive' => '<span style="color: #999;">&#x25CF;</span> ' . __( 'Inactivo', 'lealez' ),
                    'draft'    => '<span style="color: #ca4a1f;">&#x25CF;</span> ' . __( 'Borrador', 'lealez' ),
                    'archived' => '<span style="color: #333;">&#x25CF;</span> ' . __( 'Archivado', 'lealez' ),
                );
                echo isset( $statuses[ $program_status ] ) ? wp_kses_post( $statuses[ $program_status ] ) : '—';
                break;
        }
    }

    /**
     * Set sortable columns
     */
    public function set_sortable_columns( $columns ) {
        $columns['business']       = 'parent_business_id';
        $columns['program_type']   = 'program_type';
        $columns['program_scope']  = 'program_scope';
        $columns['total_members']  = 'total_members';
        $columns['program_status'] = 'program_status';

        return $columns;
    }
}

// Initialize the class
new OY_Loyalty_Program_CPT();
