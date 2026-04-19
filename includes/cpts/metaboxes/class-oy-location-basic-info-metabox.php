<?php
/**
 * OY Location - Basic Information Metabox
 *
 * Metabox externo para "Información Básica" del CPT oy_location.
 * Se externaliza para reducir el peso de class-oy-location-cpt.php.
 *
 * Campos incluidos:
 * - Descripción (GMB)
 * - Fecha de Apertura
 * - Categoría Principal (manual)
 * - Rango de Precios
 *
 * @package Lealez
 * @subpackage CPTs/Metaboxes
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'OY_Location_Basic_Info_Metabox' ) ) {

    class OY_Location_Basic_Info_Metabox {

        /**
         * Post type slug
         *
         * @var string
         */
        private $post_type = 'oy_location';

        /**
         * Constructor
         */
        public function __construct() {
            add_action( 'add_meta_boxes_oy_location', array( $this, 'register_metabox' ), 11, 1 );
        }

        /**
         * Registrar metabox
         *
         * @param WP_Post $post Post actual.
         * @return void
         */
        public function register_metabox( $post ) {
            add_meta_box(
                'oy_location_basic_info',
                __( 'Información Básica', 'lealez' ),
                array( $this, 'render_metabox' ),
                $this->post_type,
                'normal',
                'high'
            );
        }

        /**
         * Render del metabox
         *
         * @param WP_Post $post Post actual.
         * @return void
         */
        public function render_metabox( $post ) {
            // Repetimos el nonce central del CPT para que este metabox siga siendo autosuficiente
            // aunque el usuario cambie el orden/visibilidad de metaboxes.
            wp_nonce_field( 'oy_location_save_meta', 'oy_location_meta_nonce' );

            $location_short_description = get_post_meta( $post->ID, 'location_short_description', true );
            $opening_date               = get_post_meta( $post->ID, 'opening_date', true );
            $google_primary_category    = get_post_meta( $post->ID, 'google_primary_category', true );
            $price_range                = get_post_meta( $post->ID, 'price_range', true );

            $desc_len = function_exists( 'mb_strlen' )
                ? mb_strlen( (string) $location_short_description )
                : strlen( (string) $location_short_description );
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="location_short_description"><?php _e( 'Descripción (GMB)', 'lealez' ); ?></label>
                    </th>
                    <td>
                        <textarea
                            name="location_short_description"
                            id="location_short_description"
                            rows="4"
                            class="large-text"
                            maxlength="750"
                            placeholder="<?php esc_attr_e( 'Máximo 750 caracteres (límite de Google My Business)', 'lealez' ); ?>"
                        ><?php echo esc_textarea( $location_short_description ); ?></textarea>

                        <p class="description">
                            <?php _e( 'Descripción del negocio para Google My Business (máximo 750 caracteres). Importado desde GMB: <code>profile.description</code>.', 'lealez' ); ?>
                            <span id="gmb-desc-char-count" style="font-weight:600;"><?php echo esc_html( $desc_len ); ?>/750</span>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="opening_date"><?php _e( 'Fecha de Apertura', 'lealez' ); ?></label>
                    </th>
                    <td>
                        <input
                            type="date"
                            name="opening_date"
                            id="opening_date"
                            value="<?php echo esc_attr( $opening_date ); ?>"
                            class="regular-text"
                        >
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="google_primary_category"><?php _e( 'Categoría Principal (manual)', 'lealez' ); ?></label>
                    </th>
                    <td>
                        <input
                            type="text"
                            name="google_primary_category"
                            id="google_primary_category"
                            value="<?php echo esc_attr( $google_primary_category ); ?>"
                            class="regular-text"
                            placeholder="<?php esc_attr_e( 'Ej: Restaurant, Retail Store, Gym', 'lealez' ); ?>"
                        >
                        <p class="description">
                            <?php _e( 'Este campo es tu vista "humana". Al importar, se poblará desde <code>categories.primaryCategory.displayName</code>.', 'lealez' ); ?>
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
    }
}
