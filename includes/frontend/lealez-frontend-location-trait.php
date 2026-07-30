<?php
/**
 * Frontend location screens and actions.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Lealez_Frontend_Location_Trait {
    public function shortcode_location_list() {
        if ( ! is_user_logged_in() ) {
            return $this->login_required();
        }

        $this->enqueue_assets();
        $locations  = $this->get_accessible_locations();
        $businesses = $this->get_accessible_businesses( 0, false );
        $filter     = isset( $_GET['business_id'] ) ? absint( $_GET['business_id'] ) : 0;

        if ( $filter ) {
            $locations = array_values(
                array_filter(
                    $locations,
                    static function( $location ) use ( $filter ) {
                        return $filter === absint( get_post_meta( $location->ID, 'parent_business_id', true ) );
                    }
                )
            );
        }

        ob_start();
        ?>
        <div class="lealez-portal">
            <?php $this->render_notice(); ?>
            <div class="lealez-page-head">
                <div>
                    <span class="lealez-eyebrow"><?php esc_html_e( 'Administración', 'lealez' ); ?></span>
                    <h2><?php esc_html_e( 'Mis ubicaciones', 'lealez' ); ?></h2>
                    <p><?php esc_html_e( 'Administra sucursales, puntos de atención y perfiles individuales de negocio.', 'lealez' ); ?></p>
                </div>
                <?php if ( $businesses ) : ?>
                    <a class="lealez-btn lealez-btn-primary" href="<?php echo esc_url( $this->page_url( 'location_editor', array( 'location_id' => 0, 'business_id' => $filter ) ) ); ?>">
                        <?php esc_html_e( 'Nueva ubicación', 'lealez' ); ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ( ! $businesses ) : ?>
                <div class="lealez-empty">
                    <h3><?php esc_html_e( 'Primero crea una empresa', 'lealez' ); ?></h3>
                    <p><?php esc_html_e( 'Toda ubicación debe pertenecer a una empresa a la que tengas acceso.', 'lealez' ); ?></p>
                    <a class="lealez-btn lealez-btn-primary" href="<?php echo esc_url( $this->page_url( 'business_editor' ) ); ?>"><?php esc_html_e( 'Crear empresa', 'lealez' ); ?></a>
                </div>
            <?php elseif ( ! $locations ) : ?>
                <div class="lealez-empty">
                    <h3><?php esc_html_e( 'No hay ubicaciones para mostrar', 'lealez' ); ?></h3>
                    <p><?php esc_html_e( 'Crea la primera ubicación o cambia el filtro de empresa.', 'lealez' ); ?></p>
                </div>
            <?php else : ?>
                <div class="lealez-card-grid lealez-card-grid-2">
                    <?php foreach ( $locations as $location ) :
                        $business = get_post( absint( get_post_meta( $location->ID, 'parent_business_id', true ) ) );
                        $archived = 'draft' === $location->post_status;
                        $address  = implode(
                            ', ',
                            array_filter(
                                array(
                                    get_post_meta( $location->ID, 'location_address_line1', true ),
                                    get_post_meta( $location->ID, 'location_city', true ),
                                    get_post_meta( $location->ID, 'location_country', true ),
                                )
                            )
                        );
                        ?>
                        <article class="lealez-entity-card<?php echo $archived ? ' is-archived' : ''; ?>">
                            <div class="lealez-entity-top">
                                <div>
                                    <span class="lealez-status <?php echo $archived ? 'is-muted' : 'is-active'; ?>">
                                        <?php echo $archived ? esc_html__( 'Archivada', 'lealez' ) : esc_html__( 'Activa', 'lealez' ); ?>
                                    </span>
                                    <h3><?php echo esc_html( $location->post_title ); ?></h3>
                                    <small><?php echo esc_html( $business ? $business->post_title : __( 'Sin empresa', 'lealez' ) ); ?></small>
                                </div>
                                <span class="lealez-action-icon">📍</span>
                            </div>
                            <p><?php echo esc_html( $address ? $address : __( 'Dirección no registrada', 'lealez' ) ); ?></p>
                            <div class="lealez-card-actions">
                                <a class="lealez-btn lealez-btn-primary" href="<?php echo esc_url( $this->page_url( 'location_editor', array( 'location_id' => $location->ID ) ) ); ?>">
                                    <?php esc_html_e( 'Editar perfil', 'lealez' ); ?>
                                </a>
                                <form method="post" class="lealez-inline-form">
                                    <input type="hidden" name="lealez_frontend_action" value="<?php echo $archived ? 'restore_location' : 'archive_location'; ?>">
                                    <input type="hidden" name="location_id" value="<?php echo esc_attr( $location->ID ); ?>">
                                    <?php wp_nonce_field( 'lealez_location_status_' . $location->ID, 'lealez_nonce' ); ?>
                                    <button class="lealez-link-button" type="submit" data-lealez-confirm="<?php echo esc_attr( $archived ? __( '¿Reactivar esta ubicación?', 'lealez' ) : __( '¿Archivar esta ubicación? Sus datos no se eliminarán.', 'lealez' ) ); ?>">
                                        <?php echo $archived ? esc_html__( 'Reactivar', 'lealez' ) : esc_html__( 'Archivar', 'lealez' ); ?>
                                    </button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function shortcode_location_editor() {
        if ( ! is_user_logged_in() ) {
            return $this->login_required();
        }

        $this->enqueue_assets();
        $location_id = isset( $_GET['location_id'] ) ? absint( $_GET['location_id'] ) : 0;
        $location    = $location_id ? get_post( $location_id ) : null;

        if ( $location_id && ( ! $this->can_access_location( $location_id ) || ! $location || 'oy_location' !== $location->post_type ) ) {
            return $this->forbidden_panel();
        }

        $businesses = $this->get_accessible_businesses( 0, false );
        if ( ! $businesses ) {
            return '<div class="lealez-portal"><div class="lealez-empty"><h3>' . esc_html__( 'Necesitas una empresa antes de crear ubicaciones.', 'lealez' ) . '</h3><a class="lealez-btn lealez-btn-primary" href="' . esc_url( $this->page_url( 'business_editor' ) ) . '">' . esc_html__( 'Crear empresa', 'lealez' ) . '</a></div></div>';
        }

        $get = static function( $key, $default = '' ) use ( $location_id ) {
            if ( ! $location_id ) {
                return $default;
            }
            $value = get_post_meta( $location_id, $key, true );
            return ( '' === $value || false === $value ) ? $default : $value;
        };

        $parent_business_id = $get( 'parent_business_id', isset( $_GET['business_id'] ) ? absint( $_GET['business_id'] ) : 0 );
        $socials             = $get( 'social_profiles_manual', array() );
        $socials             = is_array( $socials ) ? $socials : array();
        $phones              = $get( 'gmb_phone_additional_list', array() );
        $phones              = is_array( $phones ) ? $phones : array();
        $booking_urls        = $this->url_entries_to_text( $get( 'location_booking_urls', array() ) );
        $order_urls          = $this->url_entries_to_text( $get( 'location_order_urls', array() ) );
        $days                = array(
            'monday'    => __( 'Lunes', 'lealez' ),
            'tuesday'   => __( 'Martes', 'lealez' ),
            'wednesday' => __( 'Miércoles', 'lealez' ),
            'thursday'  => __( 'Jueves', 'lealez' ),
            'friday'    => __( 'Viernes', 'lealez' ),
            'saturday'  => __( 'Sábado', 'lealez' ),
            'sunday'    => __( 'Domingo', 'lealez' ),
        );

        ob_start();
        ?>
        <div class="lealez-portal">
            <?php $this->render_notice(); ?>
            <div class="lealez-page-head">
                <div>
                    <a class="lealez-back" href="<?php echo esc_url( $this->page_url( 'locations' ) ); ?>">← <?php esc_html_e( 'Volver a ubicaciones', 'lealez' ); ?></a>
                    <h2><?php echo $location_id ? esc_html__( 'Editar ubicación', 'lealez' ) : esc_html__( 'Nueva ubicación', 'lealez' ); ?></h2>
                    <p><?php esc_html_e( 'Los datos del perfil se organizan por pestañas para evitar formularios extensos y confusos.', 'lealez' ); ?></p>
                </div>
            </div>

            <form method="post" class="lealez-form lealez-tab-form">
                <input type="hidden" name="lealez_frontend_action" value="save_location">
                <input type="hidden" name="location_id" value="<?php echo esc_attr( $location_id ); ?>">
                <?php wp_nonce_field( 'lealez_save_location_' . $location_id, 'lealez_nonce' ); ?>

                <div class="lealez-tabs" role="tablist">
                    <button type="button" class="is-active" data-lealez-tab="location-general"><?php esc_html_e( 'Información', 'lealez' ); ?></button>
                    <button type="button" data-lealez-tab="location-address"><?php esc_html_e( 'Dirección', 'lealez' ); ?></button>
                    <button type="button" data-lealez-tab="location-contact"><?php esc_html_e( 'Contacto', 'lealez' ); ?></button>
                    <button type="button" data-lealez-tab="location-hours"><?php esc_html_e( 'Horarios', 'lealez' ); ?></button>
                    <button type="button" data-lealez-tab="location-loyalty"><?php esc_html_e( 'Lealtad y responsables', 'lealez' ); ?></button>
                </div>

                <section class="lealez-tab-panel is-active" data-lealez-panel="location-general">
                    <div class="lealez-section-head"><h3><?php esc_html_e( 'Perfil de la ubicación', 'lealez' ); ?></h3></div>
                    <div class="lealez-field-grid">
                        <div class="lealez-field">
                            <label for="parent_business_id"><?php esc_html_e( 'Empresa', 'lealez' ); ?> <span>*</span></label>
                            <select required name="parent_business_id" id="parent_business_id">
                                <option value=""><?php esc_html_e( 'Seleccionar empresa', 'lealez' ); ?></option>
                                <?php foreach ( $businesses as $business ) : ?>
                                    <option value="<?php echo esc_attr( $business->ID ); ?>" <?php selected( (int) $parent_business_id, (int) $business->ID ); ?>><?php echo esc_html( $business->post_title ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php $this->input_field( 'location_title', __( 'Nombre de la ubicación', 'lealez' ), $location ? $location->post_title : '', 'text', true ); ?>
                        <?php $this->input_field( 'location_code', __( 'Código interno', 'lealez' ), $get( 'location_code' ) ); ?>
                        <?php $this->select_field( 'location_status', __( 'Estado', 'lealez' ), $get( 'location_status', 'active' ), array( 'active' => __( 'Activa', 'lealez' ), 'inactive' => __( 'Inactiva', 'lealez' ), 'temporarily_closed' => __( 'Cerrada temporalmente', 'lealez' ), 'permanently_closed' => __( 'Cerrada permanentemente', 'lealez' ) ) ); ?>
                        <?php $this->textarea_field( 'location_short_description', __( 'Descripción para Google', 'lealez' ), $get( 'location_short_description' ), 6, 'lealez-field-span-2', __( 'Máximo 750 caracteres.', 'lealez' ), 750 ); ?>
                        <?php $this->input_field( 'opening_date', __( 'Fecha de apertura', 'lealez' ), $get( 'opening_date' ), 'date' ); ?>
                        <?php $this->input_field( 'google_primary_category', __( 'Categoría principal', 'lealez' ), $get( 'google_primary_category' ) ); ?>
                        <?php $this->select_field( 'price_range', __( 'Rango de precios', 'lealez' ), $get( 'price_range' ), array( '' => __( 'No especificado', 'lealez' ), '1' => '$ - ' . __( 'Económico', 'lealez' ), '2' => '$$ - ' . __( 'Moderado', 'lealez' ), '3' => '$$$ - ' . __( 'Caro', 'lealez' ), '4' => '$$$$ - ' . __( 'Muy caro', 'lealez' ) ) ); ?>
                    </div>
                </section>

                <section class="lealez-tab-panel" data-lealez-panel="location-address">
                    <div class="lealez-section-head"><h3><?php esc_html_e( 'Dirección y geolocalización', 'lealez' ); ?></h3></div>
                    <div class="lealez-field-grid">
                        <?php $this->checkbox_field( 'service_area_only', __( 'Negocio de área de servicio', 'lealez' ), $get( 'service_area_only' ) ); ?>
                        <?php $this->checkbox_field( 'show_address_to_customers', __( 'Mostrar dirección a clientes', 'lealez' ), $get( 'show_address_to_customers', '1' ) ); ?>
                        <?php
                        foreach ( array(
                            'location_address_line1' => __( 'Dirección principal', 'lealez' ),
                            'location_address_line2' => __( 'Complemento', 'lealez' ),
                            'location_neighborhood'  => __( 'Barrio', 'lealez' ),
                            'location_city'          => __( 'Ciudad', 'lealez' ),
                            'location_state'         => __( 'Departamento / estado', 'lealez' ),
                            'location_country'       => __( 'País', 'lealez' ),
                            'location_postal_code'   => __( 'Código postal', 'lealez' ),
                            'location_latitude'      => __( 'Latitud', 'lealez' ),
                            'location_longitude'     => __( 'Longitud', 'lealez' ),
                        ) as $field => $label ) {
                            $this->input_field( $field, $label, $get( $field ) );
                        }
                        ?>
                        <?php $this->input_field( 'location_map_url', __( 'URL de Google Maps', 'lealez' ), $get( 'location_map_url' ), 'url' ); ?>
                    </div>
                </section>

                <section class="lealez-tab-panel" data-lealez-panel="location-contact">
                    <div class="lealez-section-head"><h3><?php esc_html_e( 'Contacto y enlaces públicos', 'lealez' ); ?></h3></div>
                    <div class="lealez-field-grid">
                        <?php $this->input_field( 'location_phone', __( 'Teléfono principal', 'lealez' ), $get( 'location_phone' ), 'tel' ); ?>
                        <?php $this->textarea_field( 'location_additional_phones', __( 'Teléfonos adicionales', 'lealez' ), implode( "\n", $phones ), 4, '', __( 'Un teléfono por línea.', 'lealez' ) ); ?>
                        <?php $this->input_field( 'location_website', __( 'Sitio web', 'lealez' ), $get( 'location_website' ), 'url' ); ?>
                        <?php $this->select_field( 'location_chat_type', __( 'Canal de chat', 'lealez' ), $get( 'location_chat_type', 'whatsapp' ), array( 'whatsapp' => 'WhatsApp', 'sms' => 'SMS' ) ); ?>
                        <?php $this->input_field( 'location_chat_country', __( 'País del canal', 'lealez' ), $get( 'location_chat_country', 'CO' ) ); ?>
                        <?php $this->input_field( 'location_chat_url', __( 'Usuario, URL o número de chat', 'lealez' ), $get( 'location_chat_url' ) ); ?>
                        <?php $this->input_field( 'location_menu_url', __( 'URL de menú o servicios', 'lealez' ), $get( 'location_menu_url' ), 'url' ); ?>
                        <?php $this->textarea_field( 'location_booking_urls_text', __( 'URLs de reservas', 'lealez' ), $booking_urls, 4, '', __( 'Una URL por línea. Los enlaces importados desde Google se conservan.', 'lealez' ) ); ?>
                        <?php $this->textarea_field( 'location_order_urls_text', __( 'URLs para ordenar', 'lealez' ), $order_urls, 4, '', __( 'Una URL por línea. Los enlaces importados desde Google se conservan.', 'lealez' ) ); ?>
                        <?php foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok' ) as $network => $label ) { $this->input_field( 'location_social_' . $network, $label, isset( $socials[ $network ] ) ? $socials[ $network ] : '', 'url' ); } ?>
                    </div>
                </section>

                <section class="lealez-tab-panel" data-lealez-panel="location-hours">
                    <div class="lealez-section-head">
                        <h3><?php esc_html_e( 'Horarios de atención', 'lealez' ); ?></h3>
                        <p><?php esc_html_e( 'Los horarios especiales existentes se preservan y no se modifican desde este formulario.', 'lealez' ); ?></p>
                    </div>
                    <div class="lealez-field-grid">
                        <?php $this->input_field( 'location_hours_timezone', __( 'Zona horaria', 'lealez' ), $get( 'location_hours_timezone', 'America/Bogota' ) ); ?>
                        <?php $this->select_field( 'location_hours_status', __( 'Estado de horarios', 'lealez' ), $get( 'location_hours_status', 'open_with_hours' ), array( 'open_with_hours' => __( 'Abierto con horarios', 'lealez' ), 'open_without_hours' => __( 'Abierto sin publicar horarios', 'lealez' ), 'temporarily_closed' => __( 'Cerrado temporalmente', 'lealez' ), 'permanently_closed' => __( 'Cerrado permanentemente', 'lealez' ) ) ); ?>
                    </div>
                    <div class="lealez-hours-table">
                        <?php foreach ( $days as $day_key => $day_label ) :
                            $data    = $get( 'location_hours_' . $day_key, array() );
                            $data    = is_array( $data ) ? $data : array();
                            $closed  = ! empty( $data['closed'] );
                            $all_day = ! empty( $data['all_day'] ) || ( isset( $data['open'] ) && '24_hours' === $data['open'] );
                            $periods = ! empty( $data['periods'] ) && is_array( $data['periods'] ) ? $data['periods'] : array( array( 'open' => '09:00', 'close' => '18:00' ) );
                            ?>
                            <div class="lealez-hours-day<?php echo $closed || $all_day ? ' is-disabled' : ''; ?>" data-hours-day="<?php echo esc_attr( $day_key ); ?>">
                                <div class="lealez-hours-day-name">
                                    <strong><?php echo esc_html( $day_label ); ?></strong>
                                    <label><input type="checkbox" class="lealez-hours-closed" name="location_hours[<?php echo esc_attr( $day_key ); ?>][closed]" value="1" <?php checked( $closed ); ?>> <?php esc_html_e( 'Cerrado', 'lealez' ); ?></label>
                                    <label><input type="checkbox" class="lealez-hours-all-day" name="location_hours[<?php echo esc_attr( $day_key ); ?>][all_day]" value="1" <?php checked( $all_day ); ?>> <?php esc_html_e( '24 horas', 'lealez' ); ?></label>
                                </div>
                                <div class="lealez-hours-periods">
                                    <?php foreach ( $periods as $index => $period ) : ?>
                                        <div class="lealez-hours-period">
                                            <input type="time" name="location_hours[<?php echo esc_attr( $day_key ); ?>][periods][<?php echo esc_attr( $index ); ?>][open]" value="<?php echo esc_attr( isset( $period['open'] ) && '24_hours' !== $period['open'] ? $period['open'] : '09:00' ); ?>">
                                            <span>—</span>
                                            <input type="time" name="location_hours[<?php echo esc_attr( $day_key ); ?>][periods][<?php echo esc_attr( $index ); ?>][close]" value="<?php echo esc_attr( ! empty( $period['close'] ) ? $period['close'] : '18:00' ); ?>">
                                            <button type="button" class="lealez-hours-remove" aria-label="<?php esc_attr_e( 'Eliminar intervalo', 'lealez' ); ?>">×</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="lealez-hours-add">+ <?php esc_html_e( 'Agregar intervalo', 'lealez' ); ?></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="lealez-tab-panel" data-lealez-panel="location-loyalty">
                    <div class="lealez-section-head"><h3><?php esc_html_e( 'Lealtad, responsables y notas', 'lealez' ); ?></h3></div>
                    <div class="lealez-field-grid">
                        <?php $this->checkbox_field( 'accepts_loyalty', __( 'Aceptar programas de lealtad', 'lealez' ), $get( 'accepts_loyalty' ) ); ?>
                        <?php $this->checkbox_field( 'loyalty_earning_enabled', __( 'Permitir acumulación de puntos', 'lealez' ), $get( 'loyalty_earning_enabled' ) ); ?>
                        <?php $this->checkbox_field( 'loyalty_redemption_enabled', __( 'Permitir redención', 'lealez' ), $get( 'loyalty_redemption_enabled' ) ); ?>
                        <?php $this->input_field( 'loyalty_multiplier', __( 'Multiplicador', 'lealez' ), $get( 'loyalty_multiplier', '1' ), 'number', false, '0.01' ); ?>
                        <?php $this->input_field( 'loyalty_terminal_id', __( 'ID de terminal', 'lealez' ), $get( 'loyalty_terminal_id' ) ); ?>
                        <?php $this->input_field( 'location_manager', __( 'Responsable de la ubicación', 'lealez' ), $get( 'location_manager' ) ); ?>
                        <?php $this->input_field( 'location_manager_email', __( 'Correo del responsable', 'lealez' ), $get( 'location_manager_email' ), 'email' ); ?>
                        <?php $this->input_field( 'location_manager_phone', __( 'Teléfono del responsable', 'lealez' ), $get( 'location_manager_phone' ), 'tel' ); ?>
                        <?php $this->textarea_field( 'manager_notes', __( 'Notas para el responsable', 'lealez' ), $get( 'manager_notes' ), 5 ); ?>
                        <?php $this->textarea_field( 'internal_notes', __( 'Notas internas', 'lealez' ), $get( 'internal_notes' ), 5 ); ?>
                    </div>
                </section>

                <div class="lealez-form-footer">
                    <a class="lealez-btn lealez-btn-secondary" href="<?php echo esc_url( $this->page_url( 'locations' ) ); ?>"><?php esc_html_e( 'Cancelar', 'lealez' ); ?></a>
                    <button type="submit" class="lealez-btn lealez-btn-primary"><?php esc_html_e( 'Guardar ubicación', 'lealez' ); ?></button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function process_save_location() {
        $location_id = isset( $_POST['location_id'] ) ? absint( $_POST['location_id'] ) : 0;
        $nonce       = isset( $_POST['lealez_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lealez_nonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'lealez_save_location_' . $location_id ) ) {
            $this->redirect_notice( 'location_editor', 'invalid', array( 'location_id' => $location_id ) );
        }
        if ( $location_id && ! $this->can_access_location( $location_id ) ) {
            $this->redirect_notice( 'locations', 'forbidden' );
        }

        $business_id = isset( $_POST['parent_business_id'] ) ? absint( $_POST['parent_business_id'] ) : 0;
        $title       = isset( $_POST['location_title'] ) ? sanitize_text_field( wp_unslash( $_POST['location_title'] ) ) : '';

        if ( ! $business_id || ! $title || ! $this->can_access_business( $business_id ) ) {
            $this->redirect_notice( 'location_editor', 'invalid', array( 'location_id' => $location_id ) );
        }

        $old_business_id = $location_id ? absint( get_post_meta( $location_id, 'parent_business_id', true ) ) : 0;
        $post_data       = array( 'post_type' => 'oy_location', 'post_title' => $title );

        if ( $location_id ) {
            $existing                 = get_post( $location_id );
            $post_data['ID']          = $location_id;
            $post_data['post_status'] = $existing ? $existing->post_status : 'publish';
            $result                   = wp_update_post( $post_data, true );
        } else {
            $post_data['post_status'] = 'publish';
            $post_data['post_author'] = get_current_user_id();
            $result                   = wp_insert_post( $post_data, true );
        }

        if ( is_wp_error( $result ) ) {
            $this->redirect_notice( 'location_editor', 'invalid', array( 'location_id' => $location_id ) );
        }

        $location_id = (int) $result;
        update_post_meta( $location_id, 'parent_business_id', $business_id );

        $fields = array(
            'location_code'              => 'text',
            'location_short_description' => 'textarea',
            'location_status'            => 'key',
            'opening_date'               => 'date',
            'location_address_line1'     => 'text',
            'location_address_line2'     => 'text',
            'location_neighborhood'      => 'text',
            'location_city'              => 'text',
            'location_state'             => 'text',
            'location_country'           => 'text',
            'location_postal_code'       => 'text',
            'location_latitude'          => 'text',
            'location_longitude'         => 'text',
            'location_map_url'           => 'url',
            'google_primary_category'    => 'text',
            'price_range'                => 'text',
            'loyalty_multiplier'         => 'float',
            'loyalty_terminal_id'        => 'text',
            'location_manager'           => 'text',
            'location_manager_email'     => 'email',
            'location_manager_phone'     => 'text',
            'internal_notes'             => 'textarea',
            'manager_notes'              => 'textarea',
            'location_phone'             => 'text',
            'location_website'           => 'url',
            'location_chat_type'         => 'key',
            'location_chat_country'      => 'text',
            'location_chat_url'          => 'text',
            'location_menu_url'          => 'url',
        );

        foreach ( $fields as $field => $type ) {
            update_post_meta( $location_id, $field, isset( $_POST[ $field ] ) ? $this->sanitize_value( $_POST[ $field ], $type ) : '' );
        }

        $description = (string) get_post_meta( $location_id, 'location_short_description', true );
        update_post_meta( $location_id, 'location_short_description', function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 750 ) : substr( $description, 0, 750 ) );

        if ( ! in_array( get_post_meta( $location_id, 'location_status', true ), array( 'active', 'inactive', 'temporarily_closed', 'permanently_closed' ), true ) ) {
            update_post_meta( $location_id, 'location_status', 'active' );
        }
        if ( ! in_array( get_post_meta( $location_id, 'price_range', true ), array( '', '1', '2', '3', '4' ), true ) ) {
            update_post_meta( $location_id, 'price_range', '' );
        }

        foreach ( array( 'service_area_only', 'show_address_to_customers', 'accepts_loyalty', 'loyalty_earning_enabled', 'loyalty_redemption_enabled' ) as $checkbox ) {
            update_post_meta( $location_id, $checkbox, isset( $_POST[ $checkbox ] ) ? '1' : '0' );
        }

        $additional_phones = $this->text_lines( isset( $_POST['location_additional_phones'] ) ? $_POST['location_additional_phones'] : '' );
        update_post_meta( $location_id, 'gmb_phone_additional_list', $additional_phones );
        if ( $additional_phones ) {
            update_post_meta( $location_id, 'location_phone_additional', $additional_phones[0] );
        } else {
            delete_post_meta( $location_id, 'location_phone_additional' );
        }

        $chat_type    = (string) get_post_meta( $location_id, 'location_chat_type', true );
        $chat_country = (string) get_post_meta( $location_id, 'location_chat_country', true );
        $chat_value   = (string) get_post_meta( $location_id, 'location_chat_url', true );
        if ( $chat_value ) {
            update_post_meta( $location_id, 'location_chat_channels', array( array( 'type' => $chat_type, 'country' => $chat_country, 'value' => $chat_value ) ) );
            if ( 'whatsapp' === $chat_type ) {
                update_post_meta( $location_id, 'location_whatsapp', $chat_value );
            } else {
                delete_post_meta( $location_id, 'location_whatsapp' );
            }
        } else {
            delete_post_meta( $location_id, 'location_chat_channels' );
            delete_post_meta( $location_id, 'location_whatsapp' );
        }

        $booking_urls = $this->merge_manual_url_entries(
            get_post_meta( $location_id, 'location_booking_urls', true ),
            $this->text_lines( isset( $_POST['location_booking_urls_text'] ) ? $_POST['location_booking_urls_text'] : '', 'url' ),
            'APPOINTMENT',
            __( 'Reservas', 'lealez' )
        );
        $order_urls = $this->merge_manual_url_entries(
            get_post_meta( $location_id, 'location_order_urls', true ),
            $this->text_lines( isset( $_POST['location_order_urls_text'] ) ? $_POST['location_order_urls_text'] : '', 'url' ),
            'FOOD_ORDERING',
            __( 'Ordenar en línea', 'lealez' )
        );
        $this->save_url_entries( $location_id, 'location_booking_urls', 'location_booking_url', $booking_urls );
        $this->save_url_entries( $location_id, 'location_order_urls', 'location_order_url', $order_urls );

        $social_profiles = array();
        foreach ( array( 'facebook', 'instagram', 'linkedin', 'tiktok' ) as $network ) {
            $field = 'location_social_' . $network;
            $url   = isset( $_POST[ $field ] ) ? esc_url_raw( wp_unslash( $_POST[ $field ] ) ) : '';
            if ( $url ) {
                $social_profiles[ $network ] = $url;
            }
        }
        update_post_meta( $location_id, 'social_profiles_manual', $social_profiles );
        if ( isset( $social_profiles['facebook'] ) ) {
            update_post_meta( $location_id, 'social_facebook_local', $social_profiles['facebook'] );
        } else {
            delete_post_meta( $location_id, 'social_facebook_local' );
        }
        if ( isset( $social_profiles['instagram'] ) ) {
            update_post_meta( $location_id, 'social_instagram_local', $social_profiles['instagram'] );
        } else {
            delete_post_meta( $location_id, 'social_instagram_local' );
        }

        $this->save_frontend_hours( $location_id );

        update_post_meta( $location_id, 'oy_basic_info_local_pending_publish', '1' );
        update_post_meta( $location_id, 'oy_address_local_pending_publish', '1' );
        update_post_meta( $location_id, 'oy_contact_local_pending_publish', '1' );
        update_post_meta( $location_id, 'date_modified', current_time( 'mysql' ) );
        update_post_meta( $location_id, 'modified_by_user_id', get_current_user_id() );
        if ( ! get_post_meta( $location_id, 'date_created', true ) ) {
            update_post_meta( $location_id, 'date_created', current_time( 'mysql' ) );
            update_post_meta( $location_id, 'created_by_user_id', get_current_user_id() );
        }

        $this->update_business_location_count( $business_id );
        if ( $old_business_id && $old_business_id !== $business_id ) {
            $this->update_business_location_count( $old_business_id );
        }

        $this->redirect_notice( 'location_editor', 'location_saved', array( 'location_id' => $location_id ) );
    }

    private function save_frontend_hours( $location_id ) {
        $timezone = isset( $_POST['location_hours_timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['location_hours_timezone'] ) ) : 'America/Bogota';
        $status   = isset( $_POST['location_hours_status'] ) ? sanitize_key( wp_unslash( $_POST['location_hours_status'] ) ) : 'open_with_hours';
        if ( ! in_array( $status, array( 'open_with_hours', 'open_without_hours', 'temporarily_closed', 'permanently_closed' ), true ) ) {
            $status = 'open_with_hours';
        }
        update_post_meta( $location_id, 'location_hours_timezone', $timezone ? $timezone : 'America/Bogota' );
        update_post_meta( $location_id, 'location_hours_status', $status );

        $raw_days = isset( $_POST['location_hours'] ) && is_array( $_POST['location_hours'] ) ? wp_unslash( $_POST['location_hours'] ) : array();
        foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $day ) {
            $raw     = isset( $raw_days[ $day ] ) && is_array( $raw_days[ $day ] ) ? $raw_days[ $day ] : array();
            $closed  = ! empty( $raw['closed'] );
            $all_day = ! $closed && ! empty( $raw['all_day'] );
            $periods = array();

            if ( $all_day ) {
                $periods[] = array( 'open' => '24_hours', 'close' => '' );
            } else {
                $raw_periods = isset( $raw['periods'] ) && is_array( $raw['periods'] ) ? $raw['periods'] : array();
                foreach ( $raw_periods as $period ) {
                    if ( ! is_array( $period ) ) {
                        continue;
                    }
                    $open  = isset( $period['open'] ) ? sanitize_text_field( $period['open'] ) : '';
                    $close = isset( $period['close'] ) ? sanitize_text_field( $period['close'] ) : '';
                    if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $open ) || ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $close ) ) {
                        continue;
                    }
                    $periods[] = array( 'open' => $open, 'close' => $close );
                }
            }

            if ( ! $periods ) {
                $periods[] = array( 'open' => '09:00', 'close' => '18:00' );
            }

            update_post_meta(
                $location_id,
                'location_hours_' . $day,
                array(
                    'closed'  => $closed,
                    'all_day' => $all_day,
                    'periods' => $periods,
                    'open'    => $all_day ? '24_hours' : $periods[0]['open'],
                    'close'   => $all_day ? '' : $periods[0]['close'],
                )
            );
        }

        update_post_meta( $location_id, 'oy_hours_local_pending_publish', '1' );
        update_post_meta(
            $location_id,
            'oy_hours_last_manual_save',
            array(
                'at'       => gmdate( 'Y-m-d\TH:i:s\Z', current_time( 'timestamp' ) ),
                'at_ts'    => current_time( 'timestamp' ),
                'at_label' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
                'by'       => wp_get_current_user()->user_login,
                'source'   => 'frontend_portal',
            )
        );
    }

    private function process_location_status( $action ) {
        $location_id = isset( $_POST['location_id'] ) ? absint( $_POST['location_id'] ) : 0;
        $nonce       = isset( $_POST['lealez_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lealez_nonce'] ) ) : '';

        if ( ! $location_id || ! wp_verify_nonce( $nonce, 'lealez_location_status_' . $location_id ) || ! $this->can_access_location( $location_id ) ) {
            $this->redirect_notice( 'locations', 'forbidden' );
        }

        $business_id = absint( get_post_meta( $location_id, 'parent_business_id', true ) );
        wp_update_post( array( 'ID' => $location_id, 'post_status' => 'archive_location' === $action ? 'draft' : 'publish' ) );
        if ( $business_id ) {
            $this->update_business_location_count( $business_id );
        }
        $this->redirect_notice( 'locations', 'archive_location' === $action ? 'location_archived' : 'location_restored' );
    }
}
