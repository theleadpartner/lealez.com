<?php
/**
 * Unified location profile: completion, applicability and customer-safe status.
 *
 * @package Lealez
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Lealez_Unified_Location_Health_Trait {

    /**
     * Decide if a module should be visible for the current user and location.
     *
     * Technical connection/synchronization modules are intentionally restricted
     * to site administrators. Customer-facing users still see friendly Google
     * connection and publication states, without internal identifiers or logs.
     *
     * @param int    $location_id Location post ID.
     * @param string $module      Module key.
     * @param array  $definition  Module definition.
     * @return bool
     */
    private function can_view_location_module( $location_id, $module, array $definition ) {
        if ( ! empty( $definition['site_admin_only'] ) && ! $this->is_site_admin() ) {
            return false;
        }

        if ( ! empty( $definition['business_admin_only'] ) && ! $this->can_manage_location_business( $location_id ) ) {
            return false;
        }

        $catalog_type = $this->get_location_catalog_type( $location_id );

        if ( 'menu' === $module && '' !== $catalog_type && 'food_menu' !== $catalog_type ) {
            return false;
        }

        if ( 'services' === $module && in_array( $catalog_type, array( 'food_menu', 'none' ), true ) ) {
            return false;
        }

        return true;
    }

    /**
     * Return the Google catalog type already detected by the existing sync flow.
     *
     * @param int $location_id Location post ID.
     * @return string food_menu|services|products|none|''
     */
    private function get_location_catalog_type( $location_id ) {
        $catalog_type = sanitize_key( (string) get_post_meta( $location_id, 'gmb_catalog_type', true ) );
        return in_array( $catalog_type, array( 'food_menu', 'services', 'products', 'none' ), true ) ? $catalog_type : '';
    }

    /**
     * Build a customer-friendly explanation of the content configuration that
     * Google makes available for the current business profile.
     *
     * @param int $location_id Location post ID.
     * @return array{label:string,description:string,class:string}
     */
    private function get_catalog_context( $location_id ) {
        $catalog_type = $this->get_location_catalog_type( $location_id );

        $map = array(
            'food_menu' => array(
                'label'       => __( 'Menú disponible', 'lealez' ),
                'description' => __( 'Google reconoce esta ubicación como apta para administrar un menú. Lealez mostrará las opciones de menú y ocultará el catálogo de servicios para evitar confusiones.', 'lealez' ),
                'class'       => 'success',
            ),
            'services' => array(
                'label'       => __( 'Servicios disponibles', 'lealez' ),
                'description' => __( 'Google reconoce un catálogo de servicios para esta ubicación. Lealez prioriza ese módulo y oculta las opciones exclusivas de restaurantes.', 'lealez' ),
                'class'       => 'success',
            ),
            'products' => array(
                'label'       => __( 'Catálogo disponible', 'lealez' ),
                'description' => __( 'La ubicación dispone de información de catálogo. Lealez utiliza el módulo de servicios y catálogo compatible con la integración actual.', 'lealez' ),
                'class'       => 'success',
            ),
            'none' => array(
                'label'       => __( 'Sin catálogo adicional', 'lealez' ),
                'description' => __( 'Google no reporta por ahora un menú o catálogo adicional compatible para esta ubicación. Las demás secciones del perfil continúan disponibles.', 'lealez' ),
                'class'       => 'neutral',
            ),
        );

        if ( isset( $map[ $catalog_type ] ) ) {
            return $map[ $catalog_type ];
        }

        return array(
            'label'       => __( 'Opciones según el tipo de negocio', 'lealez' ),
            'description' => __( 'Al sincronizar con Google, Lealez adapta las opciones de contenido a las capacidades disponibles para la categoría de esta ubicación. Mientras se confirma, puedes consultar Menú y Servicios sin publicar nada automáticamente.', 'lealez' ),
            'class'       => 'info',
        );
    }

    /**
     * Completion model used only as a Lealez UX guide. It is not a Google score.
     * Only customer-editable profile information is counted in the overall score.
     *
     * @param int   $location_id Location post ID.
     * @param array $modules     Location modules.
     * @return array{overall:int,state:array,sections:array,pending:int,connected:bool}
     */
    private function get_location_profile_completion( $location_id, array $modules ) {
        $post            = get_post( $location_id );
        $service_area    = (bool) get_post_meta( $location_id, 'service_area_only', true );
        $catalog_type    = $this->get_location_catalog_type( $location_id );
        $sections        = array();
        $overall_scores  = array();
        $pending_modules = 0;

        $basic_values = array(
            ! empty( $post ) && '' !== trim( (string) $post->post_title ),
            '' !== trim( (string) get_post_meta( $location_id, 'google_primary_category', true ) ),
            '' !== trim( (string) get_post_meta( $location_id, 'location_short_description', true ) ),
            '' !== trim( (string) get_post_meta( $location_id, 'opening_date', true ) ),
        );
        $sections['basic'] = $this->build_completion_section( $modules, 'basic', $this->weighted_completion( $basic_values, array( 25, 30, 30, 15 ) ) );
        $overall_scores[]  = $sections['basic']['score'];

        if ( $service_area ) {
            $address_values = array(
                '' !== trim( (string) get_post_meta( $location_id, 'location_country', true ) ),
                '' !== trim( (string) get_post_meta( $location_id, 'location_city', true ) ),
                true,
            );
            $address_score = $this->weighted_completion( $address_values, array( 35, 45, 20 ) );
        } else {
            $address_values = array(
                '' !== trim( (string) get_post_meta( $location_id, 'location_address_line1', true ) ),
                '' !== trim( (string) get_post_meta( $location_id, 'location_city', true ) ),
                '' !== trim( (string) get_post_meta( $location_id, 'location_country', true ) ),
                '' !== trim( (string) get_post_meta( $location_id, 'location_postal_code', true ) ),
            );
            $address_score = $this->weighted_completion( $address_values, array( 35, 25, 25, 15 ) );
        }
        $sections['address'] = $this->build_completion_section( $modules, 'address', $address_score );
        $overall_scores[]    = $address_score;

        $has_actions = $this->has_any_location_action( $location_id );
        $contact_values = array(
            '' !== trim( (string) get_post_meta( $location_id, 'location_phone', true ) ),
            '' !== trim( (string) get_post_meta( $location_id, 'location_website', true ) ),
            $has_actions,
        );
        $contact_score       = $this->weighted_completion( $contact_values, array( 45, 35, 20 ) );
        $sections['contact'] = $this->build_completion_section( $modules, 'contact', $contact_score );
        $overall_scores[]    = $contact_score;

        $hours_score       = $this->calculate_hours_completion( $location_id );
        $sections['hours'] = $this->build_completion_section( $modules, 'hours', $hours_score );
        $overall_scores[]  = $hours_score;

        $attribute_score        = $this->calculate_attributes_completion( $location_id );
        $sections['attributes'] = $this->build_completion_section( $modules, 'attributes', $attribute_score );

        if ( 'food_menu' === $catalog_type && isset( $modules['menu'] ) ) {
            $menu_sections = get_post_meta( $location_id, 'location_menu_sections', true );
            $menu_score    = $this->weighted_completion(
                array(
                    is_array( $menu_sections ) && ! empty( $menu_sections ),
                    '' !== trim( (string) get_post_meta( $location_id, 'location_menu_url', true ) ),
                ),
                array( 75, 25 )
            );
            $sections['menu'] = $this->build_completion_section( $modules, 'menu', $menu_score );
            $overall_scores[] = $menu_score;
        } elseif ( in_array( $catalog_type, array( 'services', 'products' ), true ) && isset( $modules['services'] ) ) {
            $service_sections = get_post_meta( $location_id, 'location_products_sections', true );
            $service_score    = is_array( $service_sections ) && ! empty( $service_sections ) ? 100 : 0;
            $sections['services'] = $this->build_completion_section( $modules, 'services', $service_score );
            $overall_scores[]     = $service_score;
        }

        foreach ( array( 'basic', 'address', 'contact', 'hours', 'attributes', 'menu' ) as $module ) {
            if ( ! isset( $modules[ $module ] ) || ! $this->can_view_location_module( $location_id, $module, $modules[ $module ] ) ) {
                continue;
            }
            $publish_state = $this->get_module_publish_state( $location_id, $module );
            if ( $publish_state && in_array( $publish_state['class'], array( 'local', 'review', 'warning', 'error' ), true ) ) {
                $pending_modules++;
            }
        }

        $overall = $overall_scores ? (int) round( array_sum( $overall_scores ) / count( $overall_scores ) ) : 0;

        return array(
            'overall'   => max( 0, min( 100, $overall ) ),
            'state'     => $this->get_completion_state( $overall ),
            'sections'  => $sections,
            'pending'   => $pending_modules,
            'connected' => '' !== trim( (string) get_post_meta( $location_id, 'gmb_location_name', true ) ),
        );
    }

    private function build_completion_section( array $modules, $key, $score ) {
        $label = isset( $modules[ $key ]['label'] ) ? (string) $modules[ $key ]['label'] : ucfirst( $key );
        return array(
            'key'   => $key,
            'label' => $label,
            'score' => max( 0, min( 100, (int) $score ) ),
            'state' => $this->get_completion_state( $score ),
        );
    }

    private function weighted_completion( array $values, array $weights ) {
        $score = 0;
        foreach ( $weights as $index => $weight ) {
            if ( ! empty( $values[ $index ] ) ) {
                $score += (int) $weight;
            }
        }
        return max( 0, min( 100, $score ) );
    }

    private function get_completion_state( $score ) {
        $score = (int) $score;
        if ( $score >= 80 ) {
            return array( 'label' => __( 'Bien completado', 'lealez' ), 'class' => 'good' );
        }
        if ( $score >= 50 ) {
            return array( 'label' => __( 'En progreso', 'lealez' ), 'class' => 'medium' );
        }
        return array( 'label' => __( 'Requiere atención', 'lealez' ), 'class' => 'low' );
    }

    private function render_module_completion_badge( $location_id, $module, $completion = null ) {
        if ( ! is_array( $completion ) ) {
            $completion = $this->get_location_profile_completion( $location_id, $this->get_location_modules() );
        }
        if ( empty( $completion['sections'][ $module ] ) ) {
            return '';
        }

        $section = $completion['sections'][ $module ];
        return sprintf(
            '<span class="lealez-completion-badge is-%1$s"><span class="lealez-completion-dot" aria-hidden="true"></span><span>%2$d%%</span><span class="screen-reader-text"> %3$s</span></span>',
            esc_attr( $section['state']['class'] ),
            (int) $section['score'],
            esc_html( $section['state']['label'] )
        );
    }

    private function has_any_location_action( $location_id ) {
        foreach ( array( 'location_chat_url', 'location_menu_url', 'location_booking_url', 'location_order_url' ) as $meta_key ) {
            if ( '' !== trim( (string) get_post_meta( $location_id, $meta_key, true ) ) ) {
                return true;
            }
        }

        foreach ( array( 'location_booking_urls', 'location_order_urls' ) as $meta_key ) {
            $value = get_post_meta( $location_id, $meta_key, true );
            if ( is_array( $value ) && ! empty( $value ) ) {
                return true;
            }
        }

        return false;
    }

    private function calculate_hours_completion( $location_id ) {
        $status = sanitize_key( (string) get_post_meta( $location_id, 'location_hours_status', true ) );
        if ( in_array( $status, array( 'open_without_hours', 'temporarily_closed', 'permanently_closed' ), true ) ) {
            return 100;
        }

        $configured_days = 0;
        foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $day ) {
            $hours = get_post_meta( $location_id, 'location_hours_' . $day, true );
            if ( ! is_array( $hours ) || empty( $hours ) ) {
                continue;
            }
            if ( ! empty( $hours['closed'] ) || ! empty( $hours['all_day'] ) || ! empty( $hours['periods'] ) || ! empty( $hours['open'] ) ) {
                $configured_days++;
            }
        }

        if ( 7 === $configured_days ) {
            return 100;
        }
        if ( $configured_days >= 5 ) {
            return 80;
        }
        if ( $configured_days >= 1 ) {
            return 50;
        }
        return 0;
    }

    private function calculate_attributes_completion( $location_id ) {
        $raw       = get_post_meta( $location_id, 'gmb_attributes_raw', true );
        $overrides = get_post_meta( $location_id, '_gmb_more_attributes_overrides', true );

        if ( is_string( $raw ) && '' !== trim( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) && ! empty( $decoded ) ) {
                return 100;
            }
        } elseif ( is_array( $raw ) && ! empty( $raw ) ) {
            return 100;
        }

        if ( is_string( $overrides ) && '' !== trim( $overrides ) ) {
            $decoded = json_decode( $overrides, true );
            if ( is_array( $decoded ) && ! empty( $decoded ) ) {
                return 100;
            }
        } elseif ( is_array( $overrides ) && ! empty( $overrides ) ) {
            return 100;
        }

        return '' !== trim( (string) get_post_meta( $location_id, 'google_primary_category', true ) ) ? 50 : 0;
    }
}
