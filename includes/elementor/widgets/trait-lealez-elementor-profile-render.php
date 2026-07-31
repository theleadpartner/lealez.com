<?php
/** Elementor rendering helpers for Lealez profile widgets. @package Lealez */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/trait-lealez-elementor-profile-summary.php';
require_once __DIR__ . '/trait-lealez-elementor-profile-access.php';

trait Lealez_Elementor_Profile_Render_Trait {
    use Lealez_Elementor_Profile_Summary_Trait;
    use Lealez_Elementor_Profile_Access_Trait;

    protected function render() {
        $settings = $this->get_settings_for_display();
        $classes  = array(
            'lealez-elementor-shell',
            'lealez-elementor-' . sanitize_html_class( $this->get_lealez_screen() ),
            'is-density-' . sanitize_html_class( isset( $settings['density'] ) ? $settings['density'] : 'comfortable' ),
        );

        if ( ! empty( $settings['hide_internal_header'] ) ) {
            $classes[] = 'is-internal-header-hidden';
        }

        echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
        echo $this->render_screen( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
    }

    /**
     * Route each widget to the existing secured rendering API.
     *
     * @param array<string,mixed> $settings Elementor settings.
     * @return string
     */
    private function render_screen( $settings ) {
        switch ( $this->get_lealez_screen() ) {
            case 'account_dashboard':
                return do_shortcode( '[lealez_account_dashboard]' );

            case 'business_list':
                return do_shortcode( '[lealez_business_list]' );

            case 'business_profile':
                return $this->render_business_profile_screen( $settings );

            case 'location_list':
                return do_shortcode( '[lealez_location_list]' );

            case 'location_profile':
                return $this->render_location_profile_screen( $settings );

            case 'user_profile':
                return do_shortcode( '[lealez_user_profile]' );
        }

        return '';
    }

    /**
     * Render the unified business profile and its internal navigation.
     *
     * @param array<string,mixed> $settings Elementor settings.
     * @return string
     */
    private function render_business_profile_screen( $settings ) {
        $business_id = isset( $_GET['business_id'] ) ? absint( wp_unslash( $_GET['business_id'] ) ) : 0;
        $section     = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

        if ( ! $section && isset( $_GET['module'] ) && $business_id ) {
            $section = 'google';
        }
        if ( ! $section && $this->is_elementor_edit_mode() ) {
            $section = isset( $settings['preview_business_section'] ) ? sanitize_key( $settings['preview_business_section'] ) : 'profile';
        }
        if ( ! in_array( $section, array( 'profile', 'team', 'integrations', 'google' ), true ) ) {
            $section = 'profile';
        }

        ob_start();

        if ( $business_id && $this->can_access_business( $business_id ) ) {
            if ( ! empty( $settings['show_profile_summary'] ) ) {
                echo $this->render_business_summary( $business_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            echo $this->render_business_navigation( $business_id, $section ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        if ( 'team' === $section ) {
            echo do_shortcode( '[lealez_business_team]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } elseif ( 'integrations' === $section ) {
            echo do_shortcode( '[lealez_business_integrations]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } elseif ( 'google' === $section ) {
            echo do_shortcode( '[lealez_business_google_center]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            echo do_shortcode( '[lealez_business_editor]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        return (string) ob_get_clean();
    }

    /**
     * Render the location profile with a readable summary inspired by modern
     * directory profiles while keeping all management modules below it.
     *
     * @param array<string,mixed> $settings Elementor settings.
     * @return string
     */
    private function render_location_profile_screen( $settings ) {
        $location_id = isset( $_GET['location_id'] ) ? absint( wp_unslash( $_GET['location_id'] ) ) : 0;

        ob_start();
        if ( $location_id && $this->can_access_location( $location_id ) && ! empty( $settings['show_profile_summary'] ) ) {
            echo $this->render_location_summary( $location_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo do_shortcode( '[lealez_location_editor]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        return (string) ob_get_clean();
    }

    private function render_business_navigation( $business_id, $section ) {
        $base = get_permalink();
        if ( ! $base ) {
            $base = home_url( '/' );
        }

        $items = array(
            'profile'      => __( 'Perfil', 'lealez' ),
            'team'         => __( 'Equipo', 'lealez' ),
            'integrations' => __( 'Integraciones', 'lealez' ),
            'google'       => __( 'Google Business Profile', 'lealez' ),
        );

        ob_start();
        ?>
        <nav class="lealez-profile-nav" aria-label="<?php esc_attr_e( 'Secciones del perfil de empresa', 'lealez' ); ?>">
            <?php foreach ( $items as $key => $label ) :
                $args = array( 'business_id' => $business_id );
                if ( 'profile' !== $key ) {
                    $args['section'] = $key;
                }
                ?>
                <a class="<?php echo $section === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( $args, $base ) ); ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
        return (string) ob_get_clean();
    }

}
