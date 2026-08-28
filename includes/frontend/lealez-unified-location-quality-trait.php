<?php
/** Unified location profile: quality, applicability and client-safe summaries. @package Lealez */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Lealez_Unified_Location_Quality_Trait {

    /**
     * Returns only modules that make sense for the current business profile.
     * Google capabilities are preferred when Lealez has already detected them;
     * the primary category is used only as a conservative fallback.
     *
     * @param int   $location_id Location ID.
     * @param array $modules     Module definitions.
     * @return array
     */
    private function get_visible_location_modules( $location_id, array $modules ) {
        foreach ( array_keys( $modules ) as $key ) {
            if ( ! $this->is_location_module_applicable( $location_id, $key ) ) {
                unset( $modules[ $key ] );
            }
        }
        return $modules;
    }

    /**
     * Category-aware applicability for catalog modules.
     *
     * `gmb_catalog_type` is populated by the existing Google synchronization
     * logic and is therefore more reliable than guessing from category text.
     *
     * @param int    $location_id Location ID.
     * @param string $module      Module key.
     * @return bool
     */
    private function is_location_module_applicable( $location_id, $module ) {
        if ( ! in_array( $module, array( 'menu', 'services' ), true ) ) {
            return true;
        }

        $catalog_type = sanitize_key( (string) get_post_meta( $location_id, 'gmb_catalog_type', true ) );
        $has_menu     = $this->location_has_local_menu( $location_id );
        $has_services = $this->location_has_local_services( $location_id );

        if ( 'menu' === $module ) {
            if ( $has_menu ) {
                return true;
            }
            if ( 'food_menu' === $catalog_type ) {
                return true;
            }
            if ( in_array( $catalog_type, array( 'services', 'products', 'none' ), true ) ) {
                return false;
            }
            return $this->location_category_looks_food_related( $location_id );
        }

        if ( $has_services ) {
            return true;
        }
        if ( in_array( $catalog_type, array( 'services', 'products' ), true ) ) {
            return true;
        }
        if ( in_array( $catalog_type, array( 'food_menu', 'none' ), true ) ) {
            return false;
        }
        return ! $this->location_category_looks_food_related( $location_id );
    }

    /**
     * Human-readable category context. Resource names remain internal.
     *
     * @param int $location_id Location ID.
     * @return array{label:string,region:string,connected:bool,catalog_type:string}
     */
    private function get_location_category_context( $location_id ) {
        $label = trim( (string) get_post_meta( $location_id, 'google_primary_category', true ) );
        if ( '' === $label ) {
            $label = trim( (string) get_post_meta( $location_id, 'gmb_primary_category', true ) );
        }

        $region = strtoupper( trim( (string) get_post_meta( $location_id, 'location_country', true ) ) );
        if ( '' === $region ) {
            $region = strtoupper( trim( (string) get_post_meta( $location_id, 'gmb_address_region_code', true ) ) );
        }

        return array(
            'label'        => $label,
            'region'       => $region,
            'connected'    => '' !== trim( (string) get_post_meta( $location_id, 'gmb_location_name', true ) ),
            'catalog_type' => sanitize_key( (string) get_post_meta( $location_id, 'gmb_catalog_type', true ) ),
        );
    }

    /**
     * Computes the overall completion percentage and per-section traffic light.
     * This measures how much recommended location information is filled in,
     * not ranking, verification, or Google quality score.
     *
     * @param int   $location_id Location ID.
     * @param array $modules     Visible module definitions.
     * @return array
     */
    private function get_location_profile_completion( $location_id, array $modules = array() ) {
        $post     = get_post( $location_id );
        $category = $this->get_location_category_context( $location_id );
        $sections = array();

        $sections['basic'] = $this->build_completion_section(
            __( 'Información', 'lealez' ),
            'dashicons-info-outline',
            array(
                $post && '' !== trim( (string) $post->post_title ),
                $this->meta_has_value( $location_id, 'location_short_description' ),
                '' !== $category['label'] || $this->meta_has_value( $location_id, 'google_primary_category_name' ),
                $this->meta_has_value( $location_id, 'opening_date' ),
            ),
            __( 'Nombre, descripción, categoría y fecha de apertura.', 'lealez' )
        );

        $service_area_only = $this->meta_is_truthy( $location_id, 'service_area_only' );
        if ( $service_area_only ) {
            $address_checks = array(
                $this->meta_has_value( $location_id, 'location_country' ),
                $this->has_any_meta_value( $location_id, array( 'location_service_areas', 'location_service_areas_text', 'gmb_service_areas' ) ),
            );
            $address_help = __( 'País y áreas donde prestas servicio.', 'lealez' );
        } else {
            $address_checks = array(
                $this->meta_has_value( $location_id, 'location_address_line1' ),
                $this->meta_has_value( $location_id, 'location_city' ),
                $this->meta_has_value( $location_id, 'location_state' ),
                $this->meta_has_value( $location_id, 'location_country' ),
                $this->meta_has_value( $location_id, 'location_postal_code' ),
            );
            $address_help = __( 'Dirección, ciudad, región, país y código postal.', 'lealez' );
        }
        $sections['address'] = $this->build_completion_section( __( 'Ubicación', 'lealez' ), 'dashicons-location', $address_checks, $address_help );

        $sections['contact'] = $this->build_completion_section(
            __( 'Contacto', 'lealez' ),
            'dashicons-phone',
            array(
                $this->meta_has_value( $location_id, 'location_phone' ),
                $this->meta_has_value( $location_id, 'location_website' ),
                $this->has_any_meta_value( $location_id, array( 'location_booking_urls', 'location_order_urls', 'location_menu_url', 'location_chat_url' ) ),
            ),
            __( 'Teléfono, sitio web y al menos un enlace de acción cuando aplique.', 'lealez' )
        );

        $sections['hours'] = $this->build_completion_section(
            __( 'Horarios', 'lealez' ),
            'dashicons-clock',
            array( $this->location_has_regular_hours( $location_id ) ),
            __( 'Horario regular de atención.', 'lealez' )
        );

        if ( isset( $modules['attributes'] ) && ( '' !== $category['label'] || $category['connected'] ) ) {
            $sections['attributes'] = $this->build_completion_section(
                __( 'Características', 'lealez' ),
                'dashicons-yes-alt',
                array( $this->location_has_attributes( $location_id ) ),
                __( 'Características disponibles para esta categoría y país.', 'lealez' )
            );
        }

        if ( isset( $modules['menu'] ) ) {
            $sections['menu'] = $this->build_completion_section(
                __( 'Menú', 'lealez' ),
                'dashicons-food',
                array( $this->location_has_local_menu( $location_id ) ),
                __( 'Contenido del menú cuando Google lo admite para esta ubicación.', 'lealez' )
            );
        } elseif ( isset( $modules['services'] ) ) {
            $sections['services'] = $this->build_completion_section(
                __( 'Servicios', 'lealez' ),
                'dashicons-store',
                array( $this->location_has_local_services( $location_id ) ),
                __( 'Servicios o catálogo aplicable a la categoría del negocio.', 'lealez' )
            );
        }

        $sum = 0;
        foreach ( $sections as $section ) {
            $sum += (int) $section['percent'];
        }
        $overall = $sections ? (int) round( $sum / count( $sections ) ) : 0;

        return array(
            'percent'  => $overall,
            'state'    => $this->completion_state( $overall ),
            'sections' => $sections,
        );
    }

    /**
     * Completion of one module for the sidebar indicator.
     *
     * @param int    $location_id Location ID.
     * @param string $module      Module key.
     * @param array  $modules     Visible modules.
     * @return array|null
     */
    private function get_module_completion( $location_id, $module, array $modules ) {
        $profile = $this->get_location_profile_completion( $location_id, $modules );
        $map = array(
            'basic'      => 'basic',
            'address'    => 'address',
            'contact'    => 'contact',
            'hours'      => 'hours',
            'attributes' => 'attributes',
            'menu'       => 'menu',
            'services'   => 'services',
        );
        if ( empty( $map[ $module ] ) || empty( $profile['sections'][ $map[ $module ] ] ) ) {
            return null;
        }
        return $profile['sections'][ $map[ $module ] ];
    }

    private function build_completion_section( $label, $icon, array $checks, $help = '' ) {
        $total    = count( $checks );
        $complete = count( array_filter( $checks ) );
        $percent  = $total ? (int) round( ( $complete / $total ) * 100 ) : 100;
        return array(
            'label'    => $label,
            'icon'     => $icon,
            'percent'  => $percent,
            'state'    => $this->completion_state( $percent ),
            'help'     => $help,
            'complete' => $complete,
            'total'    => $total,
        );
    }

    private function completion_state( $percent ) {
        $percent = (int) $percent;
        if ( $percent >= 80 ) {
            return 'good';
        }
        if ( $percent >= 45 ) {
            return 'medium';
        }
        return 'low';
    }

    private function render_completion_badge( $section ) {
        if ( ! is_array( $section ) ) {
            return '';
        }
        return '<span class="lealez-completion-mini is-' . esc_attr( $section['state'] ) . '"><span class="lealez-completion-dot" aria-hidden="true"></span><span>' . esc_html( (int) $section['percent'] . '%' ) . '</span></span>';
    }

    private function render_profile_completion( $location_id, array $modules ) {
        $completion = $this->get_location_profile_completion( $location_id, $modules );
        ?>
        <section class="lealez-profile-completion is-<?php echo esc_attr( $completion['state'] ); ?>" aria-label="<?php esc_attr_e( 'Nivel de diligenciamiento del perfil', 'lealez' ); ?>">
            <div class="lealez-profile-score">
                <div class="lealez-profile-score-ring" style="--lealez-score:<?php echo esc_attr( $completion['percent'] ); ?>">
                    <strong><?php echo esc_html( $completion['percent'] ); ?>%</strong>
                </div>
                <div>
                    <span class="lealez-eyebrow"><?php esc_html_e( 'Perfil diligenciado', 'lealez' ); ?></span>
                    <h3><?php esc_html_e( 'Completa la información relevante de esta ubicación', 'lealez' ); ?></h3>
                    <p><?php esc_html_e( 'El porcentaje indica qué tanta información recomendada está diligenciada en Lealez. No representa posicionamiento ni aprobación de Google.', 'lealez' ); ?></p>
                </div>
            </div>
            <div class="lealez-completion-sections">
                <?php foreach ( $completion['sections'] as $key => $section ) : ?>
                    <a class="lealez-completion-section is-<?php echo esc_attr( $section['state'] ); ?>" href="<?php echo esc_url( $this->profile_url( $location_id, $key ) ); ?>" title="<?php echo esc_attr( $section['help'] ); ?>">
                        <span class="dashicons <?php echo esc_attr( $section['icon'] ); ?>"></span>
                        <span><strong><?php echo esc_html( $section['label'] ); ?></strong><small><?php echo esc_html( $section['percent'] ); ?>%</small></span>
                        <span class="lealez-completion-dot" aria-hidden="true"></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    private function render_friendly_connection_panel( $location_id, $business_id ) {
        $connected = '' !== trim( (string) get_post_meta( $location_id, 'gmb_location_name', true ) );
        $verified  = $this->location_is_verified( $location_id );
        $category  = $this->get_location_category_context( $location_id );
        ?>
        <div class="lealez-friendly-connection <?php echo $connected ? 'is-connected' : 'is-disconnected'; ?>">
            <div class="lealez-friendly-connection-icon"><span class="dashicons <?php echo $connected ? 'dashicons-google' : 'dashicons-admin-links'; ?>"></span></div>
            <div class="lealez-friendly-connection-copy">
                <span class="lealez-eyebrow"><?php esc_html_e( 'Google Business Profile', 'lealez' ); ?></span>
                <h3><?php echo $connected ? esc_html__( 'Ubicación conectada', 'lealez' ) : esc_html__( 'Ubicación pendiente de conexión', 'lealez' ); ?></h3>
                <p><?php echo $connected ? esc_html__( 'Lealez puede consultar el perfil de Google y publicar los cambios de los módulos compatibles.', 'lealez' ) : esc_html__( 'Completa el perfil local y vincula esta ubicación desde la conexión de la empresa para habilitar la sincronización.', 'lealez' ); ?></p>
                <div class="lealez-friendly-connection-meta">
                    <?php if ( $category['label'] ) : ?><span><strong><?php esc_html_e( 'Categoría', 'lealez' ); ?>:</strong> <?php echo esc_html( $category['label'] ); ?></span><?php endif; ?>
                    <?php if ( $connected ) : ?><span class="lealez-status <?php echo $verified ? 'is-active' : 'is-muted'; ?>"><?php echo $verified ? esc_html__( 'Verificada en Google', 'lealez' ) : esc_html__( 'Conectada a Google', 'lealez' ); ?></span><?php endif; ?>
                </div>
            </div>
            <div class="lealez-friendly-connection-actions">
                <?php if ( $connected ) : ?>
                    <a class="lealez-btn lealez-btn-primary" href="<?php echo esc_url( $this->profile_url( $location_id, 'sync' ) ); ?>"><?php esc_html_e( 'Sincronizar perfil', 'lealez' ); ?></a>
                <?php elseif ( $business_id ) : ?>
                    <a class="lealez-btn lealez-btn-primary" href="<?php echo esc_url( $this->page_url( 'business_google', array( 'business_id' => $business_id ) ) ); ?>"><?php esc_html_e( 'Ir a conexión de Google', 'lealez' ); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_edit_sync_flow() {
        ?>
        <div class="lealez-edit-sync-flow" aria-label="<?php esc_attr_e( 'Flujo de edición y publicación', 'lealez' ); ?>">
            <div class="lealez-edit-sync-step"><span>1</span><div><strong><?php esc_html_e( 'Editar y guardar en Lealez', 'lealez' ); ?></strong><small><?php esc_html_e( 'Los cambios quedan primero guardados localmente.', 'lealez' ); ?></small></div></div>
            <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
            <div class="lealez-edit-sync-step"><span>2</span><div><strong><?php esc_html_e( 'Publicar en Google', 'lealez' ); ?></strong><small><?php esc_html_e( 'Envía solo los campos compatibles y revisa su estado.', 'lealez' ); ?></small></div></div>
        </div>
        <?php
    }

    private function location_is_verified( $location_id ) {
        foreach ( array( 'gmb_is_verified', 'gmb_verified', 'is_verified' ) as $key ) {
            $value = get_post_meta( $location_id, $key, true );
            if ( '' !== $value && false !== $value ) {
                return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'verified' ), true );
            }
        }
        $status = strtolower( trim( (string) get_post_meta( $location_id, 'gmb_verification_status', true ) ) );
        return in_array( $status, array( 'verified', 'success', 'complete' ), true );
    }

    private function location_category_looks_food_related( $location_id ) {
        $label    = strtolower( trim( (string) get_post_meta( $location_id, 'google_primary_category', true ) ) );
        $resource = strtolower( trim( (string) get_post_meta( $location_id, 'google_primary_category_name', true ) ) );
        $haystack = $label . ' ' . $resource;
        foreach ( array( 'restaurant', 'restaurante', 'cafe', 'café', 'coffee', 'bakery', 'panader', 'bar ', 'food', 'comida', 'pizza', 'burger', 'hamburg' ) as $needle ) {
            if ( false !== strpos( $haystack, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    private function location_has_local_menu( $location_id ) {
        return $this->has_any_meta_value( $location_id, array( 'location_menu_sections', 'location_menu_featured_items', 'location_menu_photos' ) );
    }

    private function location_has_local_services( $location_id ) {
        return $this->has_any_meta_value( $location_id, array( 'location_products_sections', 'location_products_featured' ) );
    }

    private function location_has_regular_hours( $location_id ) {
        if ( $this->has_any_meta_value( $location_id, array( 'location_hours', 'gmb_regular_hours', 'regular_hours' ) ) ) {
            return true;
        }
        foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $day ) {
            if ( $this->has_any_meta_value( $location_id, array( 'location_hours_' . $day, 'hours_' . $day ) ) ) {
                return true;
            }
        }
        return false;
    }

    private function location_has_attributes( $location_id ) {
        return $this->has_any_meta_value( $location_id, array( '_gmb_more_attributes_overrides', 'gmb_attributes_raw' ) );
    }

    private function meta_is_truthy( $location_id, $key ) {
        $value = get_post_meta( $location_id, $key, true );
        return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
    }

    private function meta_has_value( $location_id, $key ) {
        return $this->value_is_filled( get_post_meta( $location_id, $key, true ) );
    }

    private function has_any_meta_value( $location_id, array $keys ) {
        foreach ( $keys as $key ) {
            if ( $this->meta_has_value( $location_id, $key ) ) {
                return true;
            }
        }
        return false;
    }

    private function value_is_filled( $value ) {
        if ( is_array( $value ) ) {
            return ! empty( $value );
        }
        if ( is_object( $value ) ) {
            return ! empty( get_object_vars( $value ) );
        }
        if ( is_bool( $value ) ) {
            return $value;
        }
        return '' !== trim( (string) $value );
    }
}
