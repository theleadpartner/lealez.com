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
    
            if ( ! isset( $modules[ $section ] ) ) {
                $section = 'overview';
            }
            if ( ! empty( $modules[ $section ]['business_admin_only'] ) && ! $this->can_manage_location_business( $location_id ) ) {
                $section = 'overview';
            }
    
            $this->prepare_module_assets( $location_id, $section );
    
            ob_start();
            ?>
            <div class="lealez-portal lealez-gmb-center lealez-unified-location-profile">
                <?php $this->render_query_notice(); ?>
                <div class="lealez-page-head">
                    <div>
                        <a class="lealez-back" href="<?php echo esc_url( $this->page_url( 'locations' ) ); ?>">← <?php esc_html_e( 'Volver a ubicaciones', 'lealez' ); ?></a>
                        <span class="lealez-eyebrow"><?php esc_html_e( 'Perfil unificado de ubicación', 'lealez' ); ?></span>
                        <h2><?php echo esc_html( $location->post_title ); ?></h2>
                        <p><?php echo esc_html( $business ? $business->post_title : __( 'Sin empresa asignada', 'lealez' ) ); ?></p>
                    </div>
                    <?php if ( $business_id ) : ?>
                        <div class="lealez-gmb-head-actions">
                            <a class="lealez-btn lealez-btn-secondary" href="<?php echo esc_url( $this->page_url( 'business_google', array( 'business_id' => $business_id ) ) ); ?>"><?php esc_html_e( 'Conexión Google de la empresa', 'lealez' ); ?></a>
                        </div>
                    <?php endif; ?>
                </div>
    
                <?php $this->render_sync_legend(); ?>
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
                            ?>
                            <a class="lealez-gmb-nav-item<?php echo $section === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( $this->profile_url( $location_id, $key ) ); ?>">
                                <span class="dashicons <?php echo esc_attr( $definition['icon'] ); ?>"></span>
                                <span><strong><?php echo esc_html( $definition['label'] ); ?></strong><small><?php echo esc_html( $definition['description'] ); ?></small></span>
                                <span class="lealez-gmb-nav-meta">
                                    <?php echo $this->render_scope_badge( $definition['scope'] ); ?>
                                    <?php echo $this->render_module_status_badge( $location_id, $key ); ?>
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
                                <?php echo $this->render_scope_badge( $modules[ $section ]['scope'], true ); ?>
                            </div>
                            <?php $this->render_scope_panel( $modules[ $section ] ); ?>
                            <?php echo $this->render_internal_profile_form( $location_id ); ?>
                        <?php else : ?>
                            <div class="lealez-gmb-module-heading">
                                <div><span class="dashicons <?php echo esc_attr( $modules[ $section ]['icon'] ); ?>"></span><div><h3><?php echo esc_html( $modules[ $section ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $section ]['long_description'] ); ?></p></div></div>
                                <div class="lealez-module-heading-badges">
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

    private function render_sync_legend() {
            ?>
            <div class="lealez-sync-legend lealez-sync-legend-main">
                <div><span class="lealez-sync-chip is-google-write"><?php esc_html_e( 'Sincroniza con Google', 'lealez' ); ?></span><p><?php esc_html_e( 'Campos que pueden enviarse a Google. Guardar localmente no equivale a publicar.', 'lealez' ); ?></p></div>
                <div><span class="lealez-sync-chip is-google-read"><?php esc_html_e( 'Datos de Google', 'lealez' ); ?></span><p><?php esc_html_e( 'Información consultada desde Google y, en algunos módulos, almacenada como caché local.', 'lealez' ); ?></p></div>
                <div><span class="lealez-sync-chip is-internal"><?php esc_html_e( 'Solo Lealez', 'lealez' ); ?></span><p><?php esc_html_e( 'Datos administrativos que nunca se envían a Google Business Profile.', 'lealez' ); ?></p></div>
                <div><span class="lealez-sync-chip is-mixed"><?php esc_html_e( 'Mixto', 'lealez' ); ?></span><p><?php esc_html_e( 'La sección contiene campos de Google y campos exclusivamente internos; el detalle se muestra al abrirla.', 'lealez' ); ?></p></div>
            </div>
            <?php
        }

    private function render_scope_badge( $scope, $large = false ) {
            $map = array(
                'google_write'  => array( __( 'Sincroniza con Google', 'lealez' ), 'google-write' ),
                'google_read'   => array( __( 'Datos de Google', 'lealez' ), 'google-read' ),
                'google_direct' => array( __( 'Acción directa en Google', 'lealez' ), 'google-write' ),
                'internal'      => array( __( 'Solo Lealez', 'lealez' ), 'internal' ),
                'mixed'         => array( __( 'Mixto', 'lealez' ), 'mixed' ),
                'system'        => array( __( 'Control técnico', 'lealez' ), 'system' ),
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
                    <section><span class="lealez-sync-chip is-google-write"><?php esc_html_e( 'Se envía a Google', 'lealez' ); ?></span><ul><?php foreach ( $google as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></section>
                <?php endif; ?>
                <?php if ( $read ) : ?>
                    <section><span class="lealez-sync-chip is-google-read"><?php esc_html_e( 'Se obtiene de Google', 'lealez' ); ?></span><ul><?php foreach ( $read as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></section>
                <?php endif; ?>
                <?php if ( $local ) : ?>
                    <section><span class="lealez-sync-chip is-internal"><?php esc_html_e( 'No se publica en Google', 'lealez' ); ?></span><ul><?php foreach ( $local as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></section>
                <?php endif; ?>
            </div>
            <?php
        }

    private function render_google_workflow_guidance( $location_id ) {
            $resource = (string) get_post_meta( $location_id, 'gmb_location_name', true );
            ?>
            <div class="lealez-gmb-guidance <?php echo $resource ? 'is-info' : 'is-warning'; ?>">
                <strong><?php echo $resource ? esc_html__( 'Un solo perfil, dos estados de guardado', 'lealez' ) : esc_html__( 'La ficha aún no está vinculada con Google', 'lealez' ); ?></strong>
                <?php if ( $resource ) : ?>
                    <p><?php esc_html_e( 'Los campos compatibles se editan dentro de este perfil. Primero se guardan en Lealez; después debes presionar “Enviar a GMB” y consultar “Verificar estado”. Google puede aplicar, modificar, revisar o rechazar el cambio.', 'lealez' ); ?></p>
                <?php else : ?>
                    <p><?php esc_html_e( 'Puedes completar todos los datos internos. Los campos marcados para Google quedarán guardados localmente, pero no podrán publicarse hasta que un administrador vincule esta ficha a una propiedad de Google Business Profile.', 'lealez' ); ?></p>
                <?php endif; ?>
            </div>
            <?php
        }

    private function render_unified_overview( $location_id, $business_id, $modules ) {
            $resource           = (string) get_post_meta( $location_id, 'gmb_location_name', true );
            $google_location_id = (string) get_post_meta( $location_id, 'gmb_location_id', true );
            $account_id         = (string) get_post_meta( $location_id, 'gmb_account_id', true );
            ?>
            <div class="lealez-gmb-overview-card">
                <div><span class="dashicons dashicons-location-alt"></span><div><h3><?php esc_html_e( 'Perfil único de la ubicación', 'lealez' ); ?></h3><p><?php echo $resource ? esc_html( $resource ) : esc_html__( 'La ubicación funciona localmente en Lealez y está pendiente de vinculación con Google.', 'lealez' ); ?></p></div></div>
                <dl><div><dt><?php esc_html_e( 'Account ID', 'lealez' ); ?></dt><dd><?php echo $account_id ? esc_html( $account_id ) : '—'; ?></dd></div><div><dt><?php esc_html_e( 'Location ID', 'lealez' ); ?></dt><dd><?php echo $google_location_id ? esc_html( $google_location_id ) : '—'; ?></dd></div></dl>
            </div>
    
            <div class="lealez-gmb-status-grid lealez-unified-status-grid">
                <?php foreach ( array( 'internal', 'basic', 'address', 'contact', 'hours', 'attributes', 'menu' ) as $key ) : ?>
                    <a class="lealez-gmb-status-card" href="<?php echo esc_url( $this->profile_url( $location_id, $key ) ); ?>">
                        <span class="dashicons <?php echo esc_attr( $modules[ $key ]['icon'] ); ?>"></span>
                        <div><strong><?php echo esc_html( $modules[ $key ]['label'] ); ?></strong><div class="lealez-card-badges"><?php echo $this->render_scope_badge( $modules[ $key ]['scope'] ); ?><?php echo $this->render_module_status_badge( $location_id, $key, true ); ?></div></div>
                    </a>
                <?php endforeach; ?>
            </div>
    
            <div class="lealez-card-grid lealez-card-grid-3">
                <?php foreach ( array( 'connection', 'sync', 'media', 'services', 'posts', 'reviews', 'performance', 'keywords', 'busyhours' ) as $key ) : ?>
                    <?php if ( ! isset( $modules[ $key ] ) || ( ! empty( $modules[ $key ]['business_admin_only'] ) && ! $this->can_manage_location_business( $location_id ) ) ) { continue; } ?>
                    <a class="lealez-action-card" href="<?php echo esc_url( $this->profile_url( $location_id, $key ) ); ?>"><span class="lealez-action-icon"><span class="dashicons <?php echo esc_attr( $modules[ $key ]['icon'] ); ?>"></span></span><h3><?php echo esc_html( $modules[ $key ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $key ]['description'] ); ?></p><?php echo $this->render_scope_badge( $modules[ $key ]['scope'] ); ?></a>
                <?php endforeach; ?>
            </div>
            <?php
        }
}
