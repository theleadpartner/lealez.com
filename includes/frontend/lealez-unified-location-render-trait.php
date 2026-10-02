<?php
/** Unified location profile: render. @package Lealez */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Lealez_Unified_Location_Render_Trait {

    private function render_unified_location_profile( $location_id ) {
        $this->enqueue_common_assets();

        $location = get_post( $location_id );
        if ( ! $location || 'oy_location' !== $location->post_type || ! $this->can_access_location( $location_id ) ) {
            return $this->forbidden_panel();
        }

        $business_id = absint( get_post_meta( $location_id, 'parent_business_id', true ) );
        $business    = $business_id ? get_post( $business_id ) : null;
        $modules     = $this->get_location_modules();
        $section     = $this->requested_section();

        if ( ! isset( $modules[ $section ] ) || ! $this->can_view_location_module( $location_id, $section, $modules[ $section ] ) ) {
            $section = 'overview';
        }

        $completion = $this->get_location_profile_completion( $location_id, $modules );
        $this->prepare_module_assets( $location_id, $section );

        ob_start();
        ?>
        <div class="lealez-portal lealez-gmb-center lealez-unified-location-profile">
            <?php $this->render_query_notice(); ?>

            <div class="lealez-page-head lealez-location-profile-head">
                <div>
                    <a class="lealez-back" href="<?php echo esc_url( $this->page_url( 'locations' ) ); ?>">← <?php esc_html_e( 'Volver a ubicaciones', 'lealez' ); ?></a>
                    <span class="lealez-eyebrow"><?php esc_html_e( 'Perfil de ubicación', 'lealez' ); ?></span>
                    <h2><?php echo esc_html( $location->post_title ); ?></h2>
                    <p><?php echo esc_html( $business ? $business->post_title : __( 'Sin empresa asignada', 'lealez' ) ); ?></p>
                </div>
                <div class="lealez-location-head-status">
                    <span class="lealez-google-connection-pill <?php echo $completion['connected'] ? 'is-connected' : 'is-disconnected'; ?>">
                        <span class="lealez-connection-dot" aria-hidden="true"></span>
                        <?php echo $completion['connected'] ? esc_html__( 'Conectada con Google', 'lealez' ) : esc_html__( 'Pendiente de conectar con Google', 'lealez' ); ?>
                    </span>
                    <?php if ( $business_id ) : ?>
                        <a class="lealez-btn lealez-btn-secondary" href="<?php echo esc_url( $this->page_url( 'business_google', array( 'business_id' => $business_id ) ) ); ?>"><?php esc_html_e( 'Administrar conexión de la empresa', 'lealez' ); ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <?php $this->render_profile_health_summary( $location_id, $completion, $modules ); ?>
            <?php $this->render_google_workflow_guidance( $location_id ); ?>

            <div class="lealez-gmb-layout lealez-unified-location-layout">
                <aside class="lealez-gmb-sidebar" aria-label="<?php esc_attr_e( 'Secciones del perfil de ubicación', 'lealez' ); ?>">
                    <?php $current_group = ''; ?>
                    <?php foreach ( $modules as $key => $definition ) : ?>
                        <?php
                        if ( ! $this->can_view_location_module( $location_id, $key, $definition ) ) {
                            continue;
                        }
                        if ( $definition['group'] !== $current_group ) {
                            $current_group = $definition['group'];
                            echo '<div class="lealez-gmb-nav-group">' . esc_html( $current_group ) . '</div>';
                        }
                        ?>
                        <a class="lealez-gmb-nav-item<?php echo $section === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( $this->profile_url( $location_id, $key ) ); ?>">
                            <span class="dashicons <?php echo esc_attr( $definition['icon'] ); ?>"></span>
                            <span class="lealez-gmb-nav-copy"><strong><?php echo esc_html( $definition['label'] ); ?></strong><small><?php echo esc_html( $definition['description'] ); ?></small></span>
                            <span class="lealez-gmb-nav-meta">
                                <?php
                                $completion_badge = $this->render_module_completion_badge( $location_id, $key, $completion );
                                echo $completion_badge ? $completion_badge : $this->render_scope_badge( $definition['scope'] );
                                echo $this->render_module_status_badge( $location_id, $key );
                                ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </aside>

                <main class="lealez-gmb-main">
                    <?php if ( 'overview' === $section ) : ?>
                        <?php $this->render_unified_overview( $location_id, $business_id, $modules, $completion ); ?>
                    <?php elseif ( 'internal' === $section ) : ?>
                        <div class="lealez-gmb-module-heading">
                            <div><span class="dashicons <?php echo esc_attr( $modules[ $section ]['icon'] ); ?>"></span><div><h3><?php echo esc_html( $modules[ $section ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $section ]['long_description'] ); ?></p></div></div>
                            <?php echo $this->render_scope_badge( $modules[ $section ]['scope'], true ); ?>
                        </div>
                        <?php $this->render_scope_panel( $modules[ $section ] ); ?>
                        <?php echo $this->render_internal_profile_form( $location_id ); ?>
                    <?php else : ?>
                        <div class="lealez-gmb-module-heading">
                            <div><span class="dashicons <?php echo esc_attr( $modules[ $section ]['icon'] ); ?>"></span><div><h3><?php echo esc_html( $modules[ $section ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $section ]['long_description'] ); ?></p></div></div>
                            <div class="lealez-module-heading-badges">
                                <?php echo $this->render_module_completion_badge( $location_id, $section, $completion ); ?>
                                <?php echo $this->render_scope_badge( $modules[ $section ]['scope'], true ); ?>
                                <?php echo $this->render_module_status_badge( $location_id, $section, true ); ?>
                            </div>
                        </div>
                        <?php $this->render_scope_panel( $modules[ $section ] ); ?>
                        <?php echo $this->render_embedded_metabox( $location_id, $modules[ $section ] ); ?>
                    <?php endif; ?>
                </main>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_profile_health_summary( $location_id, array $completion, array $modules ) {
        $catalog = $this->get_catalog_context( $location_id );
        ?>
        <section class="lealez-profile-health" aria-label="<?php esc_attr_e( 'Completitud del perfil', 'lealez' ); ?>">
            <div class="lealez-profile-health-score">
                <div class="lealez-completion-ring is-<?php echo esc_attr( $completion['state']['class'] ); ?>" style="--lealez-completion: <?php echo (int) $completion['overall']; ?>;">
                    <strong><?php echo (int) $completion['overall']; ?>%</strong>
                </div>
                <div>
                    <span class="lealez-health-kicker"><?php esc_html_e( 'Completitud en Lealez', 'lealez' ); ?></span>
                    <h3><?php echo esc_html( $completion['state']['label'] ); ?></h3>
                    <p><?php esc_html_e( 'Es una guía para detectar información pendiente en esta ubicación; no corresponde a una puntuación oficial de Google.', 'lealez' ); ?></p>
                </div>
            </div>

            <div class="lealez-profile-health-sections">
                <?php foreach ( $completion['sections'] as $section ) : ?>
                    <?php if ( ! isset( $modules[ $section['key'] ] ) || ! $this->can_view_location_module( $location_id, $section['key'], $modules[ $section['key'] ] ) ) { continue; } ?>
                    <a href="<?php echo esc_url( $this->profile_url( $location_id, $section['key'] ) ); ?>" class="lealez-health-section is-<?php echo esc_attr( $section['state']['class'] ); ?>">
                        <span class="lealez-completion-dot" aria-hidden="true"></span>
                        <span><strong><?php echo esc_html( $section['label'] ); ?></strong><small><?php echo esc_html( $section['state']['label'] ); ?></small></span>
                        <b><?php echo (int) $section['score']; ?>%</b>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="lealez-profile-health-footer">
                <div class="lealez-catalog-context is-<?php echo esc_attr( $catalog['class'] ); ?>">
                    <strong><?php echo esc_html( $catalog['label'] ); ?></strong>
                    <span><?php echo esc_html( $catalog['description'] ); ?></span>
                </div>
                <div class="lealez-pending-summary">
                    <strong><?php echo (int) $completion['pending']; ?></strong>
                    <span><?php echo 1 === (int) $completion['pending'] ? esc_html__( 'sección con cambios por revisar', 'lealez' ) : esc_html__( 'secciones con cambios por revisar', 'lealez' ); ?></span>
                </div>
            </div>
        </section>
        <?php
    }

    private function render_sync_legend() {
        ?>
        <div class="lealez-sync-legend lealez-sync-legend-main">
            <div><span class="lealez-sync-chip is-google-write"><?php esc_html_e( 'Se puede publicar', 'lealez' ); ?></span><p><?php esc_html_e( 'Los cambios se guardan primero en Lealez y se envían solo cuando tú lo solicitas.', 'lealez' ); ?></p></div>
            <div><span class="lealez-sync-chip is-google-read"><?php esc_html_e( 'Información de Google', 'lealez' ); ?></span><p><?php esc_html_e( 'Datos consultados para ayudarte a administrar la ficha y sus resultados.', 'lealez' ); ?></p></div>
            <div><span class="lealez-sync-chip is-internal"><?php esc_html_e( 'Solo en Lealez', 'lealez' ); ?></span><p><?php esc_html_e( 'Información interna que nunca se publica en la ficha de Google.', 'lealez' ); ?></p></div>
        </div>
        <?php
    }

    private function render_scope_badge( $scope, $large = false ) {
        $map = array(
            'google_write'  => array( __( 'Se puede publicar', 'lealez' ), 'google-write' ),
            'google_read'   => array( __( 'Información de Google', 'lealez' ), 'google-read' ),
            'google_direct' => array( __( 'Acción en Google', 'lealez' ), 'google-write' ),
            'internal'      => array( __( 'Solo en Lealez', 'lealez' ), 'internal' ),
            'mixed'         => array( __( 'Lealez + Google', 'lealez' ), 'mixed' ),
            'system'        => array( __( 'Administración técnica', 'lealez' ), 'system' ),
        );
        $data  = isset( $map[ $scope ] ) ? $map[ $scope ] : $map['mixed'];
        $class = 'lealez-sync-chip is-' . $data[1] . ( $large ? ' is-large' : '' );
        return '<span class="' . esc_attr( $class ) . '">' . esc_html( $data[0] ) . '</span>';
    }

    private function render_scope_panel( $definition ) {
        $google = isset( $definition['google_fields'] ) && is_array( $definition['google_fields'] ) ? $definition['google_fields'] : array();
        $read   = isset( $definition['read_fields'] ) && is_array( $definition['read_fields'] ) ? $definition['read_fields'] : array();
        $local  = isset( $definition['local_fields'] ) && is_array( $definition['local_fields'] ) ? $definition['local_fields'] : array();

        if ( empty( $google ) && empty( $read ) && empty( $local ) ) {
            return;
        }
        ?>
        <div class="lealez-field-scope-panel">
            <?php if ( $google ) : ?>
                <section><span class="lealez-sync-chip is-google-write"><?php esc_html_e( 'Puede enviarse a Google', 'lealez' ); ?></span><ul><?php foreach ( $google as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></section>
            <?php endif; ?>
            <?php if ( $read ) : ?>
                <section><span class="lealez-sync-chip is-google-read"><?php esc_html_e( 'Se consulta desde Google', 'lealez' ); ?></span><ul><?php foreach ( $read as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></section>
            <?php endif; ?>
            <?php if ( $local ) : ?>
                <section><span class="lealez-sync-chip is-internal"><?php esc_html_e( 'Permanece en Lealez', 'lealez' ); ?></span><ul><?php foreach ( $local as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></section>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_google_workflow_guidance( $location_id ) {
        $resource = (string) get_post_meta( $location_id, 'gmb_location_name', true );
        ?>
        <div class="lealez-gmb-guidance <?php echo $resource ? 'is-info' : 'is-warning'; ?>">
            <strong><?php echo $resource ? esc_html__( 'Edita con tranquilidad: guardar no publica automáticamente', 'lealez' ) : esc_html__( 'Esta ubicación todavía no está conectada con Google', 'lealez' ); ?></strong>
            <?php if ( $resource ) : ?>
                <p><?php esc_html_e( 'El flujo es siempre el mismo: 1) editas y guardas en Lealez, 2) revisas los cambios, 3) eliges “Enviar a GMB” en la sección correspondiente y 4) verificas el resultado. Google puede aceptar, revisar, ajustar o rechazar un cambio.', 'lealez' ); ?></p>
            <?php else : ?>
                <p><?php esc_html_e( 'Puedes completar la ficha en Lealez desde ahora. Los campos compatibles quedarán preparados, pero no se publicará nada hasta que la empresa conecte esta ubicación con su perfil de Google.', 'lealez' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_unified_overview( $location_id, $business_id, $modules, array $completion ) {
        $resource  = (string) get_post_meta( $location_id, 'gmb_location_name', true );
        $category  = (string) get_post_meta( $location_id, 'google_primary_category', true );
        $city      = (string) get_post_meta( $location_id, 'location_city', true );
        $country   = (string) get_post_meta( $location_id, 'location_country', true );
        $place     = implode( ', ', array_filter( array( $city, $country ) ) );
        ?>
        <div class="lealez-gmb-overview-card lealez-customer-overview-card">
            <div>
                <span class="dashicons dashicons-location-alt"></span>
                <div>
                    <h3><?php esc_html_e( 'Estado de la ubicación', 'lealez' ); ?></h3>
                    <p><?php echo $resource ? esc_html__( 'La ficha está conectada. Administra cada sección y publica únicamente los cambios que decidas enviar.', 'lealez' ) : esc_html__( 'La ficha está lista para completarse en Lealez y queda pendiente de conexión con Google.', 'lealez' ); ?></p>
                </div>
            </div>
            <dl>
                <div><dt><?php esc_html_e( 'Categoría', 'lealez' ); ?></dt><dd><?php echo $category ? esc_html( $category ) : esc_html__( 'Pendiente', 'lealez' ); ?></dd></div>
                <div><dt><?php esc_html_e( 'Ubicación', 'lealez' ); ?></dt><dd><?php echo $place ? esc_html( $place ) : esc_html__( 'Pendiente', 'lealez' ); ?></dd></div>
                <div><dt><?php esc_html_e( 'Google', 'lealez' ); ?></dt><dd><?php echo $completion['connected'] ? esc_html__( 'Conectada', 'lealez' ) : esc_html__( 'Sin conectar', 'lealez' ); ?></dd></div>
            </dl>
        </div>

        <div class="lealez-gmb-status-grid lealez-unified-status-grid">
            <?php foreach ( array( 'basic', 'address', 'contact', 'hours', 'attributes', 'menu', 'services' ) as $key ) : ?>
                <?php if ( ! isset( $modules[ $key ] ) || ! $this->can_view_location_module( $location_id, $key, $modules[ $key ] ) ) { continue; } ?>
                <a class="lealez-gmb-status-card" href="<?php echo esc_url( $this->profile_url( $location_id, $key ) ); ?>">
                    <span class="dashicons <?php echo esc_attr( $modules[ $key ]['icon'] ); ?>"></span>
                    <div><strong><?php echo esc_html( $modules[ $key ]['label'] ); ?></strong><div class="lealez-card-badges"><?php echo $this->render_module_completion_badge( $location_id, $key, $completion ); ?><?php echo $this->render_module_status_badge( $location_id, $key, true ); ?></div></div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="lealez-section-divider"><span><?php esc_html_e( 'Contenido e interacción', 'lealez' ); ?></span></div>
        <div class="lealez-card-grid lealez-card-grid-3">
            <?php foreach ( array( 'media', 'posts', 'reviews' ) as $key ) : ?>
                <?php if ( ! isset( $modules[ $key ] ) || ! $this->can_view_location_module( $location_id, $key, $modules[ $key ] ) ) { continue; } ?>
                <a class="lealez-action-card" href="<?php echo esc_url( $this->profile_url( $location_id, $key ) ); ?>"><span class="lealez-action-icon"><span class="dashicons <?php echo esc_attr( $modules[ $key ]['icon'] ); ?>"></span></span><h3><?php echo esc_html( $modules[ $key ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $key ]['description'] ); ?></p><?php echo $this->render_scope_badge( $modules[ $key ]['scope'] ); ?></a>
            <?php endforeach; ?>
        </div>

        <div class="lealez-section-divider"><span><?php esc_html_e( 'Resultados', 'lealez' ); ?></span></div>
        <div class="lealez-card-grid lealez-card-grid-3">
            <?php foreach ( array( 'performance', 'keywords', 'busyhours' ) as $key ) : ?>
                <?php if ( ! isset( $modules[ $key ] ) || ! $this->can_view_location_module( $location_id, $key, $modules[ $key ] ) ) { continue; } ?>
                <a class="lealez-action-card" href="<?php echo esc_url( $this->profile_url( $location_id, $key ) ); ?>"><span class="lealez-action-icon"><span class="dashicons <?php echo esc_attr( $modules[ $key ]['icon'] ); ?>"></span></span><h3><?php echo esc_html( $modules[ $key ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $key ]['description'] ); ?></p><?php echo $this->render_scope_badge( $modules[ $key ]['scope'] ); ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ( $this->is_site_admin() ) : ?>
            <div class="lealez-section-divider"><span><?php esc_html_e( 'Administración técnica', 'lealez' ); ?></span></div>
            <div class="lealez-card-grid lealez-card-grid-2">
                <?php foreach ( array( 'connection', 'sync' ) as $key ) : ?>
                    <?php if ( ! isset( $modules[ $key ] ) || ! $this->can_view_location_module( $location_id, $key, $modules[ $key ] ) ) { continue; } ?>
                    <a class="lealez-action-card is-technical" href="<?php echo esc_url( $this->profile_url( $location_id, $key ) ); ?>"><span class="lealez-action-icon"><span class="dashicons <?php echo esc_attr( $modules[ $key ]['icon'] ); ?>"></span></span><h3><?php echo esc_html( $modules[ $key ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $key ]['description'] ); ?></p><?php echo $this->render_scope_badge( $modules[ $key ]['scope'] ); ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
    }
}
