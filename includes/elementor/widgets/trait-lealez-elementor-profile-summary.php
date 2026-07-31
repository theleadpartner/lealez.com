<?php
/** Visual identity summaries for Lealez profile widgets. @package Lealez */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Lealez_Elementor_Profile_Summary_Trait {
    private function render_business_summary( $business_id ) {
        $business = get_post( $business_id );
        if ( ! $business || 'oy_business' !== $business->post_type ) {
            return '';
        }

        $name      = get_post_meta( $business_id, '_business_name', true );
        $name      = $name ? $name : $business->post_title;
        $tagline   = get_post_meta( $business_id, '_brand_tagline', true );
        $cover     = get_post_meta( $business_id, '_brand_cover_image', true );
        $logo      = get_post_meta( $business_id, '_brand_logo', true );
        $industry  = get_post_meta( $business_id, '_business_industry', true );
        $status    = get_post_meta( $business_id, '_status', true );
        $locations = get_posts(
            array(
                'post_type'      => 'oy_location',
                'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_key'       => 'parent_business_id',
                'meta_value'     => $business_id,
            )
        );

        ob_start();
        ?>
        <section class="lealez-profile-summary lealez-business-summary<?php echo $cover ? ' has-cover' : ''; ?>"<?php echo $cover ? ' style="--lz-profile-cover:url(' . esc_url( $cover ) . ')"' : ''; ?>>
            <div class="lealez-profile-cover" aria-hidden="true"></div>
            <div class="lealez-profile-summary-body">
                <?php if ( $logo ) : ?><img class="lealez-profile-logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $name ); ?>"><?php else : ?><span class="lealez-profile-logo is-placeholder" aria-hidden="true">🏢</span><?php endif; ?>
                <div class="lealez-profile-summary-copy">
                    <span class="lealez-eyebrow"><?php esc_html_e( 'Perfil de empresa', 'lealez' ); ?></span>
                    <h2><?php echo esc_html( $name ); ?></h2>
                    <?php if ( $tagline ) : ?><p><?php echo esc_html( $tagline ); ?></p><?php endif; ?>
                    <div class="lealez-profile-meta">
                        <?php if ( $industry ) : ?><span><?php echo esc_html( $industry ); ?></span><?php endif; ?>
                        <span><?php echo esc_html( sprintf( _n( '%d ubicación', '%d ubicaciones', count( $locations ), 'lealez' ), count( $locations ) ) ); ?></span>
                        <span class="lealez-status <?php echo 'active' === $status || ! $status ? 'is-active' : 'is-muted'; ?>"><?php echo 'active' === $status || ! $status ? esc_html__( 'Activa', 'lealez' ) : esc_html__( 'Inactiva', 'lealez' ); ?></span>
                    </div>
                </div>
                <a class="lealez-btn lealez-btn-secondary" href="<?php echo esc_url( add_query_arg( 'business_id', $business_id, $this->get_portal_page_url( 'locations' ) ) ); ?>"><?php esc_html_e( 'Ver ubicaciones', 'lealez' ); ?></a>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private function render_location_summary( $location_id ) {
        $location = get_post( $location_id );
        if ( ! $location || 'oy_location' !== $location->post_type ) {
            return '';
        }

        $business_id = absint( get_post_meta( $location_id, 'parent_business_id', true ) );
        $business    = $business_id ? get_post( $business_id ) : null;
        $cover       = get_post_meta( $location_id, 'location_cover_image', true );
        if ( ! $cover ) {
            $cover = get_post_meta( $location_id, 'gmb_cover_photo_url', true );
        }
        if ( ! $cover && $business_id ) {
            $cover = get_post_meta( $business_id, '_brand_cover_image', true );
        }
        $logo = $business_id ? get_post_meta( $business_id, '_brand_logo', true ) : '';

        $address = implode(
            ', ',
            array_filter(
                array(
                    get_post_meta( $location_id, 'location_address_line1', true ),
                    get_post_meta( $location_id, 'location_city', true ),
                    get_post_meta( $location_id, 'location_country', true ),
                )
            )
        );
        $category = get_post_meta( $location_id, 'google_primary_category', true );
        $phone    = get_post_meta( $location_id, 'location_phone_primary', true );
        $website  = get_post_meta( $location_id, 'location_website', true );
        $status   = get_post_meta( $location_id, 'location_status', true );

        ob_start();
        ?>
        <section class="lealez-profile-summary lealez-location-summary<?php echo $cover ? ' has-cover' : ''; ?>"<?php echo $cover ? ' style="--lz-profile-cover:url(' . esc_url( $cover ) . ')"' : ''; ?>>
            <div class="lealez-profile-cover" aria-hidden="true"></div>
            <div class="lealez-profile-summary-body">
                <?php if ( $logo ) : ?><img class="lealez-profile-logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $location->post_title ); ?>"><?php else : ?><span class="lealez-profile-logo is-placeholder" aria-hidden="true">📍</span><?php endif; ?>
                <div class="lealez-profile-summary-copy">
                    <span class="lealez-eyebrow"><?php echo esc_html( $business ? $business->post_title : __( 'Perfil de ubicación', 'lealez' ) ); ?></span>
                    <h2><?php echo esc_html( $location->post_title ); ?></h2>
                    <?php if ( $address ) : ?><p><?php echo esc_html( $address ); ?></p><?php endif; ?>
                    <div class="lealez-profile-meta">
                        <?php if ( $category ) : ?><span><?php echo esc_html( $category ); ?></span><?php endif; ?>
                        <?php if ( $phone ) : ?><span><?php echo esc_html( $phone ); ?></span><?php endif; ?>
                        <span class="lealez-status <?php echo 'active' === $status || ! $status ? 'is-active' : 'is-muted'; ?>"><?php echo 'active' === $status || ! $status ? esc_html__( 'Activa', 'lealez' ) : esc_html__( 'Inactiva', 'lealez' ); ?></span>
                    </div>
                </div>
                <?php if ( $website ) : ?><a class="lealez-btn lealez-btn-secondary" target="_blank" rel="noopener" href="<?php echo esc_url( $website ); ?>"><?php esc_html_e( 'Visitar sitio web', 'lealez' ); ?></a><?php endif; ?>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}
