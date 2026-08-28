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
        $modules     = $this->get_visible_location_modules( $location_id, $this->get_location_modules() );
        $section     = $this->requested_section();
        $completion  = $this->get_location_profile_completion( $location_id, $modules );

        if ( ! isset( $modules[ $section ] ) ) {
            $section = 'overview';
        }
        if ( ! empty( $modules[ $section ]['business_admin_only'] ) && ! $this->can_manage_location_business( $location_id ) ) {
            $section = 'overview';
        }

        $this->prepare_module_assets( $location_id, $section );

        ob_start();
        ?>
        <div class="lealez-portal lealez-gmb-center lealez-unified-location-profile" data-lealez-client-profile="1">
            <?php $this->render_query_notice(); ?>
            <div class="lealez-page-head lealez-location-page-head">
                <div>
                    <a class="lealez-back" href="<?php echo esc_url( $this->page_url( 'locations' ) ); ?>">← <?php esc_html_e( 'Volver a ubicaciones', 'lealez' ); ?></a>
                    <span class="lealez-eyebrow"><?php esc_html_e( 'Perfil de ubicación', 'lealez' ); ?></span>
                    <h2><?php echo esc_html( $location->post_title ); ?></h2>
                    <p><?php echo esc_html( $business ? $business->post_title : __( 'Sin empresa asignada', 'lealez' ) ); ?></p>
                </div>
                <div class="lealez-gmb-head-actions lealez-location-head-actions">
                    <span class="lealez-overall-score is-<?php echo esc_attr( $completion['state'] ); ?>"><span class="lealez-completion-dot" aria-hidden="true"></span><strong><?php echo esc_html( $completion['percent'] ); ?>%</strong> <?php esc_html_e( 'diligenciado', 'lealez' ); ?></span>
                    <?php if ( $business_id ) : ?>
                        <a class="lealez-btn lealez-btn-secondary" href="<?php echo esc_url( $this->page_url( 'business_google', array( 'business_id' => $business_id ) ) ); ?>"><?php esc_html_e( 'Conexión de Google', 'lealez' ); ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <?php $this->render_edit_sync_flow(); ?>
            <?php $this->render_google_workflow_guidance( $location_id ); ?>

            <div class="lealez-gmb-layout lealez-unified-location-layout">
                <aside class="lealez-gmb-sidebar">
                    <?php $current_group = ''; ?>
                    <?php foreach ( $modules as $key => $definition ) : ?>
                        <?php
                        if ( ! empty( $definition['business_admin_only'] ) && ! $this->can_manage_location_business( $location_id ) ) {
                            continue;
                        }
                        if ( $definition['group'] !== $current_group ) {
                            $current_group = $definition['group'];
                            echo '<div class="lealez-gmb-nav-group">' . esc_html( $current_group ) . '</div>';
                        }
                        $module_completion = $this->get_module_completion( $location_id, $key, $modules );
                        ?>
                        <a class="lealez-gmb-nav-item<?php echo $section === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( $this->profile_url( $location_id, $key ) ); ?>">
                            <span class="dashicons <?php echo esc_attr( $definition['icon'] ); ?>"></span>
                            <span class="lealez-gmb-nav-copy"><strong><?php echo esc_html( $definition['label'] ); ?></strong><small><?php echo esc_html( $definition['description'] ); ?></small></span>
                            <span class="lealez-gmb-nav-meta">
                                <?php if ( $module_completion ) : ?><?php echo $this->render_completion_badge( $module_completion ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php endif; ?>
                                <?php echo $this->render_module_status_badge( $location_id, $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </aside>

                <main class="lealez-gmb-main">
                    <?php if ( 'overview' === $section ) : ?>
                        <?php $this->render_unified_overview( $location_id, $business_id, $modules ); ?>
                    <?php elseif ( 'internal' === $section ) : ?>
                        <div class="lealez-gmb-module-heading">
                            <div><span class="dashicons <?php echo esc_attr( $modules[ $section ]['icon'] ); ?>"></span><div><h3><?php echo esc_html( $modules[ $section ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $section ]['long_description'] ); ?></p></div></div>
                            <?php echo $this->render_scope_badge( $modules[ $section ]['scope'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                        <?php $this->render_scope_panel( $modules[ $section ] ); ?>
                        <?php echo $this->render_internal_profile_form( $location_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php elseif ( 'connection' === $section ) : ?>
                        <div class="lealez-gmb-module-heading">
                            <div><span class="dashicons <?php echo esc_attr( $modules[ $section ]['icon'] ); ?>"></span><div><h3><?php echo esc_html( $modules[ $section ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $section ]['long_description'] ); ?></p></div></div>
                            <?php echo $this->render_scope_badge( $modules[ $section ]['scope'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                        <?php $this->render_friendly_connection_panel( $location_id, $business_id ); ?>
                    <?php else : ?>
                        <div class="lealez-gmb-module-heading">
                            <div><span class="dashicons <?php echo esc_attr( $modules[ $section ]['icon'] ); ?>"></span><div><h3><?php echo esc_html( $modules[ $section ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $section ]['long_description'] ); ?></p></div></div>
                            <div class="lealez-module-heading-badges">
                                <?php echo $this->render_scope_badge( $modules[ $section ]['scope'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo $this->render_module_status_badge( $location_id, $section, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                        </div>
                        <?php $this->render_scope_panel( $modules[ $section ] ); ?>
                        <?php echo $this->render_embedded_metabox( $location_id, $modules[ $section ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endif; ?>
                </main>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_sync_legend() {
        ?>
        <div class="lealez-sync-legend lealez-sync-legend-main">
            <div><span class="lealez-sync-chip is-internal"><?php esc_html_e( 'Guardado en Lealez', 'lealez' ); ?></span><p><?php esc_html_e( 'Los cambios se conservan primero en tu perfil y todavía no se publican.', 'lealez' ); ?></p></div>
            <div><span class="lealez-sync-chip is-google-write"><?php esc_html_e( 'Publica en Google', 'lealez' ); ?></span><p><?php esc_html_e( 'Campos compatibles que puedes enviar cuando termines de revisarlos.', 'lealez' ); ?></p></div>
            <div><span class="lealez-sync-chip is-google-read"><?php esc_html_e( 'Información de Google', 'lealez' ); ?></span><p><?php esc_html_e( 'Datos consultados desde el perfil conectado para mantener Lealez actualizado.', 'lealez' ); ?></p></div>
        </div>
        <?php
    }

    private function render_scope_badge( $scope, $large = false ) {
        $map = array(
            'google_write'  => array( __( 'Publica en Google', 'lealez' ), 'google-write' ),
            'google_read'   => array( __( 'Información de Google', 'lealez' ), 'google-read' ),
            'google_direct' => array( __( 'Se gestiona en Google', 'lealez' ), 'google-write' ),
            'internal'      => array( __( 'Solo Lealez', 'lealez' ), 'internal' ),
            'mixed'         => array( __( 'Lealez + Google', 'lealez' ), 'mixed' ),
            'system'        => array( __( 'Configuración de sincronización', 'lealez' ), 'system' ),
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
                <section><span class="lealez-sync-chip is-google-write"><?php esc_html_e( 'Se puede publicar en Google', 'lealez' ); ?></span><ul><?php foreach ( $google as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></section>
            <?php endif; ?>
            <?php if ( $read ) : ?>
                <section><span class="lealez-sync-chip is-google-read"><?php esc_html_e( 'Se consulta desde Google', 'lealez' ); ?></span><ul><?php foreach ( $read as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></section>
            <?php endif; ?>
            <?php if ( $local ) : ?>
                <section><span class="lealez-sync-chip is-internal"><?php esc_html_e( 'Se conserva solo en Lealez', 'lealez' ); ?></span><ul><?php foreach ( $local as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></section>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_google_workflow_guidance( $location_id ) {
        $connected = '' !== trim( (string) get_post_meta( $location_id, 'gmb_location_name', true ) );
        ?>
        <div class="lealez-gmb-guidance <?php echo $connected ? 'is-info' : 'is-warning'; ?>">
            <strong><?php echo $connected ? esc_html__( 'Guardar no publica automáticamente', 'lealez' ) : esc_html__( 'Completa el perfil mientras conectas Google', 'lealez' ); ?></strong>
            <?php if ( $connected ) : ?>
                <p><?php esc_html_e( 'Edita cada sección y guarda primero en Lealez. Cuando estés listo, usa “Publicar en Google” o la acción de sincronización del módulo. Google puede revisar algunos cambios antes de mostrarlos.', 'lealez' ); ?></p>
            <?php else : ?>
                <p><?php esc_html_e( 'Puedes diligenciar y guardar la ubicación completa en Lealez. Las acciones de publicación se habilitarán cuando la empresa conecte esta ubicación con Google Business Profile.', 'lealez' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_unified_overview( $location_id, $business_id, $modules ) {
        $business   = $business_id ? get_post( $business_id ) : null;
        $category   = $this->get_location_category_context( $location_id );
        $connected  = $category['connected'];
        $address    = implode( ', ', array_filter( array(
            get_post_meta( $location_id, 'location_address_line1', true ),
            get_post_meta( $location_id, 'location_city', true ),
            get_post_meta( $location_id, 'location_state', true ),
            get_post_meta( $location_id, 'location_country', true ),
        ) ) );
        ?>
        <?php $this->render_profile_completion( $location_id, $modules ); ?>

        <div class="lealez-gmb-overview-card lealez-location-summary-card">
            <div class="lealez-location-summary-main">
                <span class="dashicons dashicons-location-alt"></span>
                <div>
                    <span class="lealez-eyebrow"><?php esc_html_e( 'Vista rápida', 'lealez' ); ?></span>
                    <h3><?php echo esc_html( get_the_title( $location_id ) ); ?></h3>
                    <p><?php echo esc_html( $address ? $address : __( 'Dirección pendiente de diligenciar', 'lealez' ) ); ?></p>
                </div>
            </div>
            <dl class="lealez-location-summary-details">
                <div><dt><?php esc_html_e( 'Empresa', 'lealez' ); ?></dt><dd><?php echo esc_html( $business ? $business->post_title : __( 'Sin empresa asignada', 'lealez' ) ); ?></dd></div>
                <div><dt><?php esc_html_e( 'Categoría', 'lealez' ); ?></dt><dd><?php echo esc_html( $category['label'] ? $category['label'] : __( 'Pendiente', 'lealez' ) ); ?></dd></div>
                <div><dt><?php esc_html_e( 'Google', 'lealez' ); ?></dt><dd><span class="lealez-status <?php echo $connected ? 'is-active' : 'is-muted'; ?>"><?php echo $connected ? esc_html__( 'Conectado', 'lealez' ) : esc_html__( 'Sin conectar', 'lealez' ); ?></span></dd></div>
            </dl>
        </div>

        <div class="lealez-gmb-status-grid lealez-unified-status-grid">
            <?php foreach ( array( 'basic', 'address', 'contact', 'hours', 'attributes', 'menu', 'services' ) as $key ) : ?>
                <?php if ( ! isset( $modules[ $key ] ) ) { continue; } ?>
                <?php $section_completion = $this->get_module_completion( $location_id, $key, $modules ); ?>
                <a class="lealez-gmb-status-card" href="<?php echo esc_url( $this->profile_url( $location_id, $key ) ); ?>">
                    <span class="dashicons <?php echo esc_attr( $modules[ $key ]['icon'] ); ?>"></span>
                    <div><strong><?php echo esc_html( $modules[ $key ]['label'] ); ?></strong><div class="lealez-card-badges"><?php if ( $section_completion ) { echo $this->render_completion_badge( $section_completion ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $this->render_module_status_badge( $location_id, $key, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="lealez-card-grid lealez-card-grid-3">
            <?php foreach ( array( 'connection', 'sync', 'media', 'posts', 'reviews', 'performance', 'keywords', 'busyhours' ) as $key ) : ?>
                <?php if ( ! isset( $modules[ $key ] ) || ( ! empty( $modules[ $key ]['business_admin_only'] ) && ! $this->can_manage_location_business( $location_id ) ) ) { continue; } ?>
                <a class="lealez-action-card" href="<?php echo esc_url( $this->profile_url( $location_id, $key ) ); ?>"><span class="lealez-action-icon"><span class="dashicons <?php echo esc_attr( $modules[ $key ]['icon'] ); ?>"></span></span><h3><?php echo esc_html( $modules[ $key ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $key ]['description'] ); ?></p><?php echo $this->render_scope_badge( $modules[ $key ]['scope'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
