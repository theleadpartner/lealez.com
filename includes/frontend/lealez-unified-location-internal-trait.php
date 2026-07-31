<?php
/** Unified location profile: internal. @package Lealez */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Lealez_Unified_Location_Internal_Trait {

    private function render_internal_profile_form( $location_id ) {
            $location    = get_post( $location_id );
            $business_id = absint( get_post_meta( $location_id, 'parent_business_id', true ) );
            $businesses  = $this->get_accessible_businesses();
            $get         = static function( $key, $default = '' ) use ( $location_id ) {
                $value = get_post_meta( $location_id, $key, true );
                return ( '' === $value || false === $value ) ? $default : $value;
            };
    
            ob_start();
            ?>
            <form method="post" class="lealez-form lealez-internal-location-form">
                <input type="hidden" name="lealez_frontend_action" value="save_location_internal">
                <input type="hidden" name="location_id" value="<?php echo esc_attr( $location_id ); ?>">
                <?php wp_nonce_field( 'lealez_save_location_internal_' . $location_id, 'lealez_internal_nonce' ); ?>
    
                <div class="lealez-section-head"><h3><?php esc_html_e( 'Organización de la ficha', 'lealez' ); ?></h3><p><?php esc_html_e( 'Estos campos controlan la administración dentro de Lealez y no se envían a Google.', 'lealez' ); ?></p></div>
                <div class="lealez-field-grid">
                    <div class="lealez-field"><label for="parent_business_id"><?php esc_html_e( 'Empresa propietaria', 'lealez' ); ?> <span class="lealez-sync-chip is-internal"><?php esc_html_e( 'Solo Lealez', 'lealez' ); ?></span></label><select required name="parent_business_id" id="parent_business_id"><option value=""><?php esc_html_e( 'Seleccionar empresa', 'lealez' ); ?></option><?php foreach ( $businesses as $business ) : ?><option value="<?php echo esc_attr( $business->ID ); ?>" <?php selected( $business_id, $business->ID ); ?>><?php echo esc_html( $business->post_title ); ?></option><?php endforeach; ?></select></div>
                    <?php $this->internal_input( 'location_title', __( 'Nombre interno de la ubicación', 'lealez' ), $location ? $location->post_title : '', 'text', true ); ?>
                    <?php $this->internal_input( 'location_code', __( 'Código interno', 'lealez' ), $get( 'location_code' ) ); ?>
                    <?php $this->internal_select( 'location_status', __( 'Estado interno', 'lealez' ), $get( 'location_status', 'active' ), array( 'active' => __( 'Activa', 'lealez' ), 'inactive' => __( 'Inactiva', 'lealez' ), 'temporarily_closed' => __( 'Cerrada temporalmente', 'lealez' ), 'permanently_closed' => __( 'Cerrada permanentemente', 'lealez' ) ) ); ?>
                </div>
    
                <hr class="lealez-divider">
                <div class="lealez-section-head"><h3><?php esc_html_e( 'Lealtad', 'lealez' ); ?></h3></div>
                <div class="lealez-field-grid">
                    <?php $this->internal_checkbox( 'accepts_loyalty', __( 'Aceptar programas de lealtad', 'lealez' ), $get( 'accepts_loyalty' ) ); ?>
                    <?php $this->internal_checkbox( 'loyalty_earning_enabled', __( 'Permitir acumulación de puntos', 'lealez' ), $get( 'loyalty_earning_enabled' ) ); ?>
                    <?php $this->internal_checkbox( 'loyalty_redemption_enabled', __( 'Permitir redención', 'lealez' ), $get( 'loyalty_redemption_enabled' ) ); ?>
                    <?php $this->internal_input( 'loyalty_multiplier', __( 'Multiplicador', 'lealez' ), $get( 'loyalty_multiplier', '1' ), 'number', false, '0.01' ); ?>
                    <?php $this->internal_input( 'loyalty_terminal_id', __( 'ID de terminal', 'lealez' ), $get( 'loyalty_terminal_id' ) ); ?>
                </div>
    
                <hr class="lealez-divider">
                <div class="lealez-section-head"><h3><?php esc_html_e( 'Responsable y notas', 'lealez' ); ?></h3></div>
                <div class="lealez-field-grid">
                    <?php $this->internal_input( 'location_manager', __( 'Responsable de la ubicación', 'lealez' ), $get( 'location_manager' ) ); ?>
                    <?php $this->internal_input( 'location_manager_email', __( 'Correo del responsable', 'lealez' ), $get( 'location_manager_email' ), 'email' ); ?>
                    <?php $this->internal_input( 'location_manager_phone', __( 'Teléfono del responsable', 'lealez' ), $get( 'location_manager_phone' ), 'tel' ); ?>
                    <?php $this->internal_textarea( 'manager_notes', __( 'Notas para el responsable', 'lealez' ), $get( 'manager_notes' ) ); ?>
                    <?php $this->internal_textarea( 'internal_notes', __( 'Notas internas', 'lealez' ), $get( 'internal_notes' ) ); ?>
                </div>
    
                <div class="lealez-form-footer"><button type="submit" class="lealez-btn lealez-btn-primary"><?php esc_html_e( 'Guardar datos internos', 'lealez' ); ?></button></div>
            </form>
            <?php
            return (string) ob_get_clean();
        }

    private function internal_input( $name, $label, $value, $type = 'text', $required = false, $step = '' ) {
            ?>
            <div class="lealez-field"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?> <?php if ( $required ) : ?><span>*</span><?php endif; ?> <span class="lealez-sync-chip is-internal"><?php esc_html_e( 'Solo Lealez', 'lealez' ); ?></span></label><input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo $required ? 'required' : ''; ?> <?php echo $step ? 'step="' . esc_attr( $step ) . '"' : ''; ?>></div>
            <?php
        }

    private function internal_select( $name, $label, $value, array $options ) {
            ?>
            <div class="lealez-field"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?> <span class="lealez-sync-chip is-internal"><?php esc_html_e( 'Solo Lealez', 'lealez' ); ?></span></label><select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>"><?php foreach ( $options as $option_value => $option_label ) : ?><option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( (string) $value, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option><?php endforeach; ?></select></div>
            <?php
        }

    private function internal_checkbox( $name, $label, $checked ) {
            ?>
            <div class="lealez-field lealez-checkbox-field"><label for="<?php echo esc_attr( $name ); ?>"><input type="checkbox" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (string) $checked, '1' ); ?>> <?php echo esc_html( $label ); ?> <span class="lealez-sync-chip is-internal"><?php esc_html_e( 'Solo Lealez', 'lealez' ); ?></span></label></div>
            <?php
        }

    private function internal_textarea( $name, $label, $value ) {
            ?>
            <div class="lealez-field"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?> <span class="lealez-sync-chip is-internal"><?php esc_html_e( 'Solo Lealez', 'lealez' ); ?></span></label><textarea id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="5"><?php echo esc_textarea( $value ); ?></textarea></div>
            <?php
        }

    public function handle_internal_profile_save() {
            if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
                return;
            }
            $action = isset( $_POST['lealez_frontend_action'] ) ? sanitize_key( wp_unslash( $_POST['lealez_frontend_action'] ) ) : '';
            if ( 'save_location_internal' !== $action ) {
                return;
            }
            if ( ! is_user_logged_in() ) {
                wp_safe_redirect( wp_login_url( wp_get_referer() ? wp_get_referer() : home_url( '/' ) ) );
                exit;
            }
    
            $location_id = isset( $_POST['location_id'] ) ? absint( wp_unslash( $_POST['location_id'] ) ) : 0;
            $nonce       = isset( $_POST['lealez_internal_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lealez_internal_nonce'] ) ) : '';
            if ( ! $location_id || ! wp_verify_nonce( $nonce, 'lealez_save_location_internal_' . $location_id ) || ! $this->can_access_location( $location_id ) ) {
                wp_die( esc_html__( 'No tienes permisos para guardar esta ubicación.', 'lealez' ) );
            }
    
            $business_id = isset( $_POST['parent_business_id'] ) ? absint( wp_unslash( $_POST['parent_business_id'] ) ) : 0;
            $title       = isset( $_POST['location_title'] ) ? sanitize_text_field( wp_unslash( $_POST['location_title'] ) ) : '';
            if ( ! $business_id || ! $title || ! $this->can_access_business( $business_id ) ) {
                wp_safe_redirect( $this->profile_url( $location_id, 'internal', array( 'profile_notice' => 'invalid' ) ) );
                exit;
            }
    
            $old_business_id = absint( get_post_meta( $location_id, 'parent_business_id', true ) );
            update_post_meta( $location_id, 'parent_business_id', $business_id );
    
            $result = wp_update_post( array( 'ID' => $location_id, 'post_title' => $title ), true );
            if ( is_wp_error( $result ) ) {
                wp_safe_redirect( $this->profile_url( $location_id, 'internal', array( 'profile_notice' => 'invalid' ) ) );
                exit;
            }
    
            $text_fields = array( 'location_code', 'loyalty_terminal_id', 'location_manager', 'location_manager_phone' );
            foreach ( $text_fields as $field ) {
                update_post_meta( $location_id, $field, isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '' );
            }
    
            $email = isset( $_POST['location_manager_email'] ) ? sanitize_email( wp_unslash( $_POST['location_manager_email'] ) ) : '';
            update_post_meta( $location_id, 'location_manager_email', $email );
    
            foreach ( array( 'manager_notes', 'internal_notes' ) as $field ) {
                update_post_meta( $location_id, $field, isset( $_POST[ $field ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) : '' );
            }
    
            $status = isset( $_POST['location_status'] ) ? sanitize_key( wp_unslash( $_POST['location_status'] ) ) : 'active';
            if ( ! in_array( $status, array( 'active', 'inactive', 'temporarily_closed', 'permanently_closed' ), true ) ) {
                $status = 'active';
            }
            update_post_meta( $location_id, 'location_status', $status );
    
            $multiplier = isset( $_POST['loyalty_multiplier'] ) ? (string) (float) wp_unslash( $_POST['loyalty_multiplier'] ) : '1';
            update_post_meta( $location_id, 'loyalty_multiplier', $multiplier );
    
            foreach ( array( 'accepts_loyalty', 'loyalty_earning_enabled', 'loyalty_redemption_enabled' ) as $checkbox ) {
                update_post_meta( $location_id, $checkbox, isset( $_POST[ $checkbox ] ) ? '1' : '0' );
            }
    
            update_post_meta( $location_id, 'date_modified', current_time( 'mysql' ) );
            update_post_meta( $location_id, 'modified_by_user_id', get_current_user_id() );
            $this->update_business_location_count( $business_id );
            if ( $old_business_id && $old_business_id !== $business_id ) {
                $this->update_business_location_count( $old_business_id );
            }
    
            wp_safe_redirect( $this->profile_url( $location_id, 'internal', array( 'profile_notice' => 'internal_saved' ) ) );
            exit;
        }
}
