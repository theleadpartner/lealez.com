<?php
/**
 * Customer Category Taxonomy
 *
 * Handles the registration of oy_customer_category taxonomy
 *
 * @package Lealez
 * @subpackage Taxonomies
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class OY_Customer_Category_Taxonomy
 *
 * Manages the customer category taxonomy
 */
class OY_Customer_Category_Taxonomy {

    /**
     * Taxonomy name
     *
     * @var string
     */
    private $taxonomy = 'oy_customer_category';

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'init', array( $this, 'register_taxonomy' ) );
        add_action( 'oy_customer_category_add_form_fields', array( $this, 'add_category_fields' ) );
        add_action( 'oy_customer_category_edit_form_fields', array( $this, 'edit_category_fields' ) );
        add_action( 'created_oy_customer_category', array( $this, 'save_category_fields' ) );
        add_action( 'edited_oy_customer_category', array( $this, 'save_category_fields' ) );
        add_filter( 'manage_edit-oy_customer_category_columns', array( $this, 'add_custom_columns' ) );
        add_filter( 'manage_oy_customer_category_custom_column', array( $this, 'custom_column_content' ), 10, 3 );
    }

    /**
     * Register the taxonomy
     */
    public function register_taxonomy() {
        $labels = array(
            'name'                       => _x( 'Categorías de Cliente', 'taxonomy general name', 'lealez' ),
            'singular_name'              => _x( 'Categoría de Cliente', 'taxonomy singular name', 'lealez' ),
            'search_items'               => __( 'Buscar Categorías', 'lealez' ),
            'popular_items'              => __( 'Categorías Populares', 'lealez' ),
            'all_items'                  => __( 'Todas las Categorías', 'lealez' ),
            'parent_item'                => __( 'Categoría Padre', 'lealez' ),
            'parent_item_colon'          => __( 'Categoría Padre:', 'lealez' ),
            'edit_item'                  => __( 'Editar Categoría', 'lealez' ),
            'update_item'                => __( 'Actualizar Categoría', 'lealez' ),
            'add_new_item'               => __( 'Agregar Nueva Categoría', 'lealez' ),
            'new_item_name'              => __( 'Nuevo Nombre de Categoría', 'lealez' ),
            'separate_items_with_commas' => __( 'Separar categorías con comas', 'lealez' ),
            'add_or_remove_items'        => __( 'Agregar o eliminar categorías', 'lealez' ),
            'choose_from_most_used'      => __( 'Elegir de las más usadas', 'lealez' ),
            'not_found'                  => __( 'No se encontraron categorías', 'lealez' ),
            'menu_name'                  => __( 'Categorías de Cliente', 'lealez' ),
        );

        $args = array(
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => false,
            'show_tagcloud'     => false,
            'show_in_rest'      => false,
            'rewrite'           => false,
            'capabilities'      => array(
                'manage_terms' => 'manage_options',
                'edit_terms'   => 'manage_options',
                'delete_terms' => 'manage_options',
                'assign_terms' => 'edit_posts',
            ),
        );

        register_taxonomy( $this->taxonomy, array( 'oy_loyalty_card' ), $args );
    }

    /**
     * Add custom fields when creating a new category
     */
    public function add_category_fields( $taxonomy ) {
        ?>
        <div class="form-field term-group">
            <label for="parent_business_id"><?php _e( 'Empresa', 'lealez' ); ?></label>
            <?php
            $businesses = get_posts( array(
                'post_type'      => 'oy_business',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'post_status'    => 'publish',
            ) );
            ?>
            <select name="parent_business_id" id="parent_business_id" class="postform">
                <option value=""><?php _e( 'Seleccionar empresa...', 'lealez' ); ?></option>
                <?php foreach ( $businesses as $business ) : ?>
                    <option value="<?php echo esc_attr( $business->ID ); ?>">
                        <?php echo esc_html( $business->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php _e( 'Empresa a la que pertenece esta categoría.', 'lealez' ); ?></p>
        </div>

        <div class="form-field term-group">
            <label for="category_type"><?php _e( 'Tipo de Categoría', 'lealez' ); ?></label>
            <select name="category_type" id="category_type" class="postform">
                <option value="cumulative"><?php _e( 'Acumulativa (se puede combinar con otras)', 'lealez' ); ?></option>
                <option value="restrictive"><?php _e( 'Restrictiva (no se puede combinar)', 'lealez' ); ?></option>
            </select>
            <p class="description"><?php _e( 'Define si esta categoría puede combinarse con otras o no.', 'lealez' ); ?></p>
        </div>

        <div class="form-field term-group">
            <label for="category_color"><?php _e( 'Color de la Categoría', 'lealez' ); ?></label>
            <input type="color" name="category_color" id="category_color" value="#2271b1" />
            <p class="description"><?php _e( 'Color para identificar visualmente esta categoría.', 'lealez' ); ?></p>
        </div>

        <div class="form-field term-group">
            <label for="category_description_extended"><?php _e( 'Descripción Extendida', 'lealez' ); ?></label>
            <textarea name="category_description_extended" id="category_description_extended" rows="5" class="large-text"></textarea>
            <p class="description"><?php _e( 'Descripción detallada de los beneficios de esta categoría.', 'lealez' ); ?></p>
        </div>
        <?php
    }

    /**
     * Edit custom fields for existing category
     */
    public function edit_category_fields( $term ) {
        $parent_business_id = get_term_meta( $term->term_id, 'parent_business_id', true );
        $category_type = get_term_meta( $term->term_id, 'category_type', true );
        $category_color = get_term_meta( $term->term_id, 'category_color', true );
        $category_description_extended = get_term_meta( $term->term_id, 'category_description_extended', true );

        if ( empty( $category_type ) ) {
            $category_type = 'cumulative';
        }
        if ( empty( $category_color ) ) {
            $category_color = '#2271b1';
        }

        $businesses = get_posts( array(
            'post_type'      => 'oy_business',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post_status'    => 'publish',
        ) );
        ?>
        <tr class="form-field term-group-wrap">
            <th scope="row">
                <label for="parent_business_id"><?php _e( 'Empresa', 'lealez' ); ?></label>
            </th>
            <td>
                <select name="parent_business_id" id="parent_business_id" class="postform">
                    <option value=""><?php _e( 'Seleccionar empresa...', 'lealez' ); ?></option>
                    <?php foreach ( $businesses as $business ) : ?>
                        <option value="<?php echo esc_attr( $business->ID ); ?>" <?php selected( $parent_business_id, $business->ID ); ?>>
                            <?php echo esc_html( $business->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php _e( 'Empresa a la que pertenece esta categoría.', 'lealez' ); ?></p>
            </td>
        </tr>

        <tr class="form-field term-group-wrap">
            <th scope="row">
                <label for="category_type"><?php _e( 'Tipo de Categoría', 'lealez' ); ?></label>
            </th>
            <td>
                <select name="category_type" id="category_type" class="postform">
                    <option value="cumulative" <?php selected( $category_type, 'cumulative' ); ?>><?php _e( 'Acumulativa (se puede combinar con otras)', 'lealez' ); ?></option>
                    <option value="restrictive" <?php selected( $category_type, 'restrictive' ); ?>><?php _e( 'Restrictiva (no se puede combinar)', 'lealez' ); ?></option>
                </select>
                <p class="description"><?php _e( 'Define si esta categoría puede combinarse con otras o no.', 'lealez' ); ?></p>
            </td>
        </tr>

        <tr class="form-field term-group-wrap">
            <th scope="row">
                <label for="category_color"><?php _e( 'Color de la Categoría', 'lealez' ); ?></label>
            </th>
            <td>
                <input type="color" name="category_color" id="category_color" value="<?php echo esc_attr( $category_color ); ?>" />
                <p class="description"><?php _e( 'Color para identificar visualmente esta categoría.', 'lealez' ); ?></p>
            </td>
        </tr>

        <tr class="form-field term-group-wrap">
            <th scope="row">
                <label for="category_description_extended"><?php _e( 'Descripción Extendida', 'lealez' ); ?></label>
            </th>
            <td>
                <textarea name="category_description_extended" id="category_description_extended" rows="5" class="large-text"><?php echo esc_textarea( $category_description_extended ); ?></textarea>
                <p class="description"><?php _e( 'Descripción detallada de los beneficios de esta categoría.', 'lealez' ); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Save custom fields
     */
    public function save_category_fields( $term_id ) {
        if ( isset( $_POST['parent_business_id'] ) ) {
            update_term_meta( $term_id, 'parent_business_id', absint( $_POST['parent_business_id'] ) );
        }

        if ( isset( $_POST['category_type'] ) ) {
            update_term_meta( $term_id, 'category_type', sanitize_text_field( $_POST['category_type'] ) );
        }

        if ( isset( $_POST['category_color'] ) ) {
            update_term_meta( $term_id, 'category_color', sanitize_hex_color( $_POST['category_color'] ) );
        }

        if ( isset( $_POST['category_description_extended'] ) ) {
            update_term_meta( $term_id, 'category_description_extended', sanitize_textarea_field( $_POST['category_description_extended'] ) );
        }
    }

    /**
     * Add custom columns
     */
    public function add_custom_columns( $columns ) {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['name'] = $columns['name'];
        $new_columns['parent_business'] = __( 'Empresa', 'lealez' );
        $new_columns['category_type'] = __( 'Tipo', 'lealez' );
        $new_columns['category_color'] = __( 'Color', 'lealez' );
        $new_columns['posts'] = $columns['posts'];

        return $new_columns;
    }

    /**
     * Custom column content
     */
    public function custom_column_content( $content, $column_name, $term_id ) {
        switch ( $column_name ) {
            case 'parent_business':
                $parent_business_id = get_term_meta( $term_id, 'parent_business_id', true );
                if ( $parent_business_id ) {
                    $content = '<a href="' . esc_url( get_edit_post_link( $parent_business_id ) ) . '">' . esc_html( get_the_title( $parent_business_id ) ) . '</a>';
                } else {
                    $content = '—';
                }
                break;

            case 'category_type':
                $category_type = get_term_meta( $term_id, 'category_type', true );
                if ( $category_type === 'restrictive' ) {
                    $content = '<span style="color: #dc3232;">● ' . __( 'Restrictiva', 'lealez' ) . '</span>';
                } else {
                    $content = '<span style="color: #46b450;">● ' . __( 'Acumulativa', 'lealez' ) . '</span>';
                }
                break;

            case 'category_color':
                $category_color = get_term_meta( $term_id, 'category_color', true );
                if ( $category_color ) {
                    $content = '<span style="display: inline-block; width: 30px; height: 30px; background-color: ' . esc_attr( $category_color ) . '; border: 1px solid #ddd; border-radius: 3px; vertical-align: middle;"></span>';
                } else {
                    $content = '—';
                }
                break;
        }

        return $content;
    }
}

// Initialize the taxonomy
new OY_Customer_Category_Taxonomy();