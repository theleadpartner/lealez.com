<?php
/**
 * User profile, request routing and shared frontend helpers.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Lealez_Frontend_User_Helpers_Trait {
    public function handle_frontend_actions() {
        if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
            return;
        }

        $action = isset( $_POST['lealez_frontend_action'] ) ? sanitize_key( wp_unslash( $_POST['lealez_frontend_action'] ) ) : '';
        if ( ! $action ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( wp_get_referer() ? wp_get_referer() : home_url( '/' ) ) );
            exit;
        }

        switch ( $action ) {
            case 'save_business':
                $this->process_save_business();
                break;
            case 'archive_business':
            case 'restore_business':
                $this->process_business_status( $action );
                break;
            case 'save_business_team':
                $this->process_save_business_team();
                break;
            case 'save_business_integrations':
                $this->process_save_business_integrations();
                break;
            case 'save_location':
                $this->process_save_location();
                break;
            case 'archive_location':
            case 'restore_location':
                $this->process_location_status( $action );
                break;
            case 'save_user_profile':
                $this->process_save_user_profile();
                break;
        }
    }

    public function shortcode_user_profile() {
        if ( ! is_user_logged_in() ) {
            return $this->login_required();
        }

        $this->enqueue_assets();
        $user = wp_get_current_user();

        ob_start();
        ?>
        <div class="lealez-portal">
            <?php $this->render_notice(); ?>
            <div class="lealez-page-head">
                <div>
                    <a class="lealez-back" href="<?php echo esc_url( $this->page_url( 'portal' ) ); ?>">← <?php esc_html_e( 'Volver a mi cuenta', 'lealez' ); ?></a>
                    <h2><?php esc_html_e( 'Mi perfil', 'lealez' ); ?></h2>
                    <p><?php esc_html_e( 'Actualiza los datos con los que te identificas dentro de Lealez.', 'lealez' ); ?></p>
                </div>
            </div>

            <form method="post" class="lealez-form">
                <input type="hidden" name="lealez_frontend_action" value="save_user_profile">
                <?php wp_nonce_field( 'lealez_save_user_profile_' . $user->ID, 'lealez_nonce' ); ?>

                <div class="lealez-section-head"><h3><?php esc_html_e( 'Información personal', 'lealez' ); ?></h3></div>
                <div class="lealez-field-grid">
                    <?php $this->input_field( 'first_name', __( 'Nombre', 'lealez' ), $user->first_name ); ?>
                    <?php $this->input_field( 'last_name', __( 'Apellidos', 'lealez' ), $user->last_name ); ?>
                    <?php $this->input_field( 'display_name', __( 'Nombre visible', 'lealez' ), $user->display_name, 'text', true ); ?>
                    <?php $this->input_field( 'user_email', __( 'Correo electrónico', 'lealez' ), $user->user_email, 'email', true ); ?>
                    <?php $this->textarea_field( 'description', __( 'Descripción o cargo', 'lealez' ), $user->description, 5, 'lealez-field-span-2' ); ?>
                </div>

                <hr class="lealez-divider">
                <div class="lealez-section-head">
                    <h3><?php esc_html_e( 'Cambiar contraseña', 'lealez' ); ?></h3>
                    <p><?php esc_html_e( 'Déjala vacía para conservar la contraseña actual.', 'lealez' ); ?></p>
                </div>
                <div class="lealez-field-grid">
                    <?php $this->input_field( 'new_password', __( 'Nueva contraseña', 'lealez' ), '', 'password' ); ?>
                    <?php $this->input_field( 'confirm_password', __( 'Confirmar contraseña', 'lealez' ), '', 'password' ); ?>
                </div>

                <div class="lealez-form-footer">
                    <button class="lealez-btn lealez-btn-primary" type="submit"><?php esc_html_e( 'Guardar perfil', 'lealez' ); ?></button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function process_save_user_profile() {
        $user_id = get_current_user_id();
        $nonce   = isset( $_POST['lealez_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lealez_nonce'] ) ) : '';

        if ( ! $user_id || ! wp_verify_nonce( $nonce, 'lealez_save_user_profile_' . $user_id ) ) {
            $this->redirect_notice( 'user_profile', 'invalid' );
        }

        $email        = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
        $display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
        $password     = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';
        $confirmation = isset( $_POST['confirm_password'] ) ? (string) wp_unslash( $_POST['confirm_password'] ) : '';

        if ( ! is_email( $email ) || ! $display_name ) {
            $this->redirect_notice( 'user_profile', 'invalid' );
        }

        $email_owner = email_exists( $email );
        if ( $email_owner && (int) $email_owner !== $user_id ) {
            $this->redirect_notice( 'user_profile', 'email_exists' );
        }

        if ( $password !== $confirmation ) {
            $this->redirect_notice( 'user_profile', 'password_mismatch' );
        }

        $userdata = array(
            'ID'           => $user_id,
            'first_name'   => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
            'last_name'    => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
            'display_name' => $display_name,
            'user_email'   => $email,
            'description'  => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
        );

        if ( '' !== $password ) {
            $userdata['user_pass'] = $password;
        }

        $result = wp_update_user( $userdata );
        if ( is_wp_error( $result ) ) {
            $this->redirect_notice( 'user_profile', 'invalid' );
        }

        $this->redirect_notice( 'user_profile', 'profile_saved' );
    }

    private function input_field( $name, $label, $value, $type = 'text', $required = false, $step = '' ) {
        ?>
        <div class="lealez-field">
            <label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?><?php if ( $required ) : ?> <span>*</span><?php endif; ?></label>
            <input
                type="<?php echo esc_attr( $type ); ?>"
                id="<?php echo esc_attr( $name ); ?>"
                name="<?php echo esc_attr( $name ); ?>"
                value="<?php echo esc_attr( $value ); ?>"
                <?php echo $required ? 'required' : ''; ?>
                <?php echo $step ? 'step="' . esc_attr( $step ) . '"' : ''; ?>
            >
        </div>
        <?php
    }

    private function textarea_field( $name, $label, $value, $rows = 5, $class = '', $help = '', $maxlength = 0 ) {
        ?>
        <div class="lealez-field <?php echo esc_attr( $class ); ?>">
            <label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
            <textarea id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="<?php echo esc_attr( $rows ); ?>" <?php echo $maxlength ? 'maxlength="' . esc_attr( $maxlength ) . '"' : ''; ?>><?php echo esc_textarea( $value ); ?></textarea>
            <?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?>
        </div>
        <?php
    }

    private function select_field( $name, $label, $value, array $options ) {
        ?>
        <div class="lealez-field">
            <label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
            <select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
                <?php foreach ( $options as $option_value => $option_label ) : ?>
                    <option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( (string) $value, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    private function checkbox_field( $name, $label, $checked ) {
        ?>
        <div class="lealez-field lealez-checkbox-field">
            <label for="<?php echo esc_attr( $name ); ?>">
                <input type="checkbox" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (string) $checked, '1' ); ?>>
                <?php echo esc_html( $label ); ?>
            </label>
        </div>
        <?php
    }

    private function sanitize_value( $raw_value, $type = 'text' ) {
        $value = wp_unslash( $raw_value );
        switch ( $type ) {
            case 'textarea':
                return sanitize_textarea_field( $value );
            case 'email':
                return sanitize_email( $value );
            case 'url':
                return esc_url_raw( $value );
            case 'key':
                return sanitize_key( $value );
            case 'float':
                return (string) (float) $value;
            case 'date':
                $value = sanitize_text_field( $value );
                if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
                    return '';
                }
                return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ? $value : '';
            default:
                return sanitize_text_field( $value );
        }
    }

    private function redirect_notice( $page_key, $notice, array $args = array() ) {
        $args['lealez_notice'] = sanitize_key( $notice );
        wp_safe_redirect( $this->page_url( $page_key, $args ) );
        exit;
    }

    private function user_ids_to_emails( $ids ) {
        $emails = array();
        foreach ( is_array( $ids ) ? $ids : array() as $user_id ) {
            $user = get_user_by( 'id', absint( $user_id ) );
            if ( $user && $user->user_email ) {
                $emails[] = $user->user_email;
            }
        }
        return array_values( array_unique( $emails ) );
    }

    private function emails_to_user_ids( $raw_emails, array &$missing = array() ) {
        $ids = array();
        foreach ( $this->text_lines( $raw_emails, 'email' ) as $email ) {
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                $ids[] = (int) $user->ID;
            } else {
                $missing[] = $email;
            }
        }
        return array_values( array_unique( $ids ) );
    }

    private function text_lines( $raw_value, $type = 'text' ) {
        $raw_value = is_array( $raw_value ) ? implode( "\n", $raw_value ) : (string) wp_unslash( $raw_value );
        $lines     = preg_split( '/\r\n|\r|\n/', $raw_value );
        $clean     = array();
        foreach ( is_array( $lines ) ? $lines : array() as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            if ( 'url' === $type ) {
                $line = esc_url_raw( $line );
            } elseif ( 'email' === $type ) {
                $line = sanitize_email( $line );
            } else {
                $line = sanitize_text_field( $line );
            }
            if ( $line ) {
                $clean[] = $line;
            }
        }
        return array_values( array_unique( $clean ) );
    }

    private function url_entries_to_text( $entries ) {
        $urls = array();
        foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
            if ( is_array( $entry ) && ! empty( $entry['url'] ) ) {
                $urls[] = esc_url_raw( $entry['url'] );
            } elseif ( is_string( $entry ) ) {
                $urls[] = esc_url_raw( $entry );
            }
        }
        return implode( "\n", array_values( array_unique( array_filter( $urls ) ) ) );
    }

    private function merge_manual_url_entries( $existing, array $manual_urls, $default_type, $default_label ) {
        $entries = array();
        $seen    = array();

        foreach ( is_array( $existing ) ? $existing : array() as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['url'] ) || empty( $entry['from_gmb'] ) ) {
                continue;
            }
            $url = esc_url_raw( $entry['url'] );
            if ( ! $url ) {
                continue;
            }
            $seen[ strtolower( $url ) ] = true;
            $entries[] = array(
                'url'      => $url,
                'label'    => isset( $entry['label'] ) ? sanitize_text_field( $entry['label'] ) : $default_label,
                'type'     => isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : $default_type,
                'from_gmb' => 1,
            );
        }

        foreach ( $manual_urls as $url ) {
            $url = esc_url_raw( $url );
            $key = strtolower( $url );
            if ( ! $url || isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $entries[] = array( 'url' => $url, 'label' => $default_label, 'type' => $default_type, 'from_gmb' => 0 );
        }

        return $entries;
    }

    private function save_url_entries( $post_id, $array_key, $primary_key, array $entries ) {
        update_post_meta( $post_id, $array_key, array_values( $entries ) );
        if ( ! empty( $entries[0]['url'] ) ) {
            update_post_meta( $post_id, $primary_key, $entries[0]['url'] );
        } else {
            delete_post_meta( $post_id, $primary_key );
        }
    }

    private function update_business_location_count( $business_id ) {
        $business_id = absint( $business_id );
        if ( ! $business_id ) {
            return;
        }
        $ids = get_posts(
            array(
                'post_type'      => 'oy_location',
                'post_status'    => array( 'publish', 'private' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_key'       => 'parent_business_id',
                'meta_value'     => $business_id,
            )
        );
        update_post_meta( $business_id, '_total_locations', count( $ids ) );
    }
}
