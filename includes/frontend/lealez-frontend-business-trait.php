<?php
/** Frontend business screens and actions. @package Lealez */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Lealez_Frontend_Business_Trait {
    public function shortcode_account_dashboard() {
        if ( ! is_user_logged_in() ) { return $this->login_required(); }
        $this->enqueue_assets();
        $businesses = $this->get_accessible_businesses();
        $locations = $this->get_accessible_locations();
        $user = wp_get_current_user();
        ob_start(); ?>
        <div class="lealez-portal">
            <?php $this->render_notice(); ?>
            <div class="lealez-hero"><div><span class="lealez-eyebrow"><?php esc_html_e( 'Portal de administración', 'lealez' ); ?></span><h2><?php echo esc_html( sprintf( __( 'Hola, %s', 'lealez' ), $user->display_name ) ); ?></h2><p><?php esc_html_e( 'Administra tus empresas, ubicaciones y perfil sin ingresar al panel de WordPress.', 'lealez' ); ?></p></div><a class="lealez-btn lealez-btn-light" href="<?php echo esc_url( $this->page_url( 'user_profile' ) ); ?>"><?php esc_html_e( 'Editar mi perfil', 'lealez' ); ?></a></div>
            <div class="lealez-stat-grid"><div class="lealez-stat-card"><span><?php esc_html_e( 'Empresas', 'lealez' ); ?></span><strong><?php echo esc_html( count( $businesses ) ); ?></strong></div><div class="lealez-stat-card"><span><?php esc_html_e( 'Ubicaciones', 'lealez' ); ?></span><strong><?php echo esc_html( count( $locations ) ); ?></strong></div><div class="lealez-stat-card"><span><?php esc_html_e( 'Perfil', 'lealez' ); ?></span><strong>✓</strong></div></div>
            <div class="lealez-card-grid lealez-card-grid-3">
                <a class="lealez-action-card" href="<?php echo esc_url( $this->page_url( 'businesses' ) ); ?>"><span class="lealez-action-icon">🏢</span><h3><?php esc_html_e( 'Mis empresas', 'lealez' ); ?></h3><p><?php esc_html_e( 'Identidad, marca, contacto, redes y datos legales.', 'lealez' ); ?></p></a>
                <a class="lealez-action-card" href="<?php echo esc_url( $this->page_url( 'locations' ) ); ?>"><span class="lealez-action-icon">📍</span><h3><?php esc_html_e( 'Mis ubicaciones', 'lealez' ); ?></h3><p><?php esc_html_e( 'Direcciones, contacto, horarios y programas.', 'lealez' ); ?></p></a>
                <a class="lealez-action-card" href="<?php echo esc_url( $this->page_url( 'user_profile' ) ); ?>"><span class="lealez-action-icon">👤</span><h3><?php esc_html_e( 'Mi perfil', 'lealez' ); ?></h3><p><?php esc_html_e( 'Datos personales, descripción y contraseña.', 'lealez' ); ?></p></a>
            </div>
        </div><?php return ob_get_clean();
    }

    public function shortcode_business_list() {
        if ( ! is_user_logged_in() ) { return $this->login_required(); }
        $this->enqueue_assets();
        $businesses = $this->get_accessible_businesses();
        ob_start(); ?>
        <div class="lealez-portal">
            <?php $this->render_notice(); ?>
            <div class="lealez-page-head"><div><span class="lealez-eyebrow"><?php esc_html_e( 'Administración', 'lealez' ); ?></span><h2><?php esc_html_e( 'Mis empresas', 'lealez' ); ?></h2><p><?php esc_html_e( 'Cada empresa agrupa sus ubicaciones, equipo e integraciones.', 'lealez' ); ?></p></div><a class="lealez-btn lealez-btn-primary" href="<?php echo esc_url( $this->page_url( 'business_editor', array( 'business_id' => 0 ) ) ); ?>"><?php esc_html_e( 'Nueva empresa', 'lealez' ); ?></a></div>
            <?php if ( ! $businesses ) : ?><div class="lealez-empty"><h3><?php esc_html_e( 'Aún no tienes empresas', 'lealez' ); ?></h3><p><?php esc_html_e( 'Crea la primera para comenzar a registrar ubicaciones.', 'lealez' ); ?></p></div><?php else : ?>
            <div class="lealez-card-grid lealez-card-grid-2"><?php foreach ( $businesses as $business ) :
                $archived = 'draft' === $business->post_status;
                $locations = get_posts( array( 'post_type' => 'oy_location', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => 'parent_business_id', 'meta_value' => $business->ID ) ); ?>
                <article class="lealez-entity-card<?php echo $archived ? ' is-archived' : ''; ?>"><div class="lealez-entity-top"><div><span class="lealez-status <?php echo $archived ? 'is-muted' : 'is-active'; ?>"><?php echo $archived ? esc_html__( 'Archivada', 'lealez' ) : esc_html__( 'Activa', 'lealez' ); ?></span><h3><?php echo esc_html( get_post_meta( $business->ID, '_business_name', true ) ?: $business->post_title ); ?></h3></div><strong class="lealez-count"><?php echo esc_html( count( $locations ) ); ?><small><?php esc_html_e( 'ubicaciones', 'lealez' ); ?></small></strong></div><p><?php echo esc_html( wp_trim_words( (string) get_post_meta( $business->ID, '_business_description', true ), 22 ) ); ?></p><div class="lealez-card-actions"><a class="lealez-btn lealez-btn-primary" href="<?php echo esc_url( $this->page_url( 'business_editor', array( 'business_id' => $business->ID ) ) ); ?>"><?php esc_html_e( 'Editar perfil', 'lealez' ); ?></a><?php if ( $this->can_manage_business_team( $business->ID ) ) : ?><a class="lealez-btn lealez-btn-secondary" href="<?php echo esc_url( $this->page_url( 'business_team', array( 'business_id' => $business->ID ) ) ); ?>"><?php esc_html_e( 'Equipo', 'lealez' ); ?></a><a class="lealez-btn lealez-btn-secondary" href="<?php echo esc_url( $this->page_url( 'business_integrations', array( 'business_id' => $business->ID ) ) ); ?>"><?php esc_html_e( 'Integraciones', 'lealez' ); ?></a><form method="post" class="lealez-inline-form"><input type="hidden" name="lealez_frontend_action" value="<?php echo $archived ? 'restore_business' : 'archive_business'; ?>"><input type="hidden" name="business_id" value="<?php echo esc_attr( $business->ID ); ?>"><?php wp_nonce_field( 'lealez_business_status_' . $business->ID, 'lealez_nonce' ); ?><button class="lealez-link-button" type="submit" data-lealez-confirm="<?php echo esc_attr( $archived ? __( '¿Reactivar esta empresa?', 'lealez' ) : __( '¿Archivar esta empresa? Sus datos no se eliminarán.', 'lealez' ) ); ?>"><?php echo $archived ? esc_html__( 'Reactivar', 'lealez' ) : esc_html__( 'Archivar', 'lealez' ); ?></button></form><?php endif; ?></div></article>
            <?php endforeach; ?></div><?php endif; ?>
        </div><?php return ob_get_clean();
    }

    public function shortcode_business_editor() {
        if ( ! is_user_logged_in() ) { return $this->login_required(); }
        $this->enqueue_assets();
        $business_id = isset( $_GET['business_id'] ) ? absint( $_GET['business_id'] ) : 0;
        $business = $business_id ? get_post( $business_id ) : null;
        if ( $business_id && ( ! $this->can_access_business( $business_id ) || ! $business || 'oy_business' !== $business->post_type ) ) { return $this->forbidden_panel(); }
        $get = function( $key, $default = '' ) use ( $business_id ) { return $business_id ? get_post_meta( $business_id, '_' . $key, true ) : $default; };
        $colors = $get( 'brand_colors', array() );
        $colors = is_array( $colors ) ? $colors : array();
        ob_start(); ?>
        <div class="lealez-portal">
            <?php $this->render_notice(); ?>
            <div class="lealez-page-head"><div><a class="lealez-back" href="<?php echo esc_url( $this->page_url( 'businesses' ) ); ?>">← <?php esc_html_e( 'Volver a empresas', 'lealez' ); ?></a><h2><?php echo $business_id ? esc_html__( 'Editar empresa', 'lealez' ) : esc_html__( 'Nueva empresa', 'lealez' ); ?></h2></div></div>
            <form method="post" class="lealez-form lealez-tab-form"><input type="hidden" name="lealez_frontend_action" value="save_business"><input type="hidden" name="business_id" value="<?php echo esc_attr( $business_id ); ?>"><?php wp_nonce_field( 'lealez_save_business_' . $business_id, 'lealez_nonce' ); ?>
                <div class="lealez-tabs" role="tablist"><button type="button" class="is-active" data-lealez-tab="business-general"><?php esc_html_e( 'Información', 'lealez' ); ?></button><button type="button" data-lealez-tab="business-brand"><?php esc_html_e( 'Marca', 'lealez' ); ?></button><button type="button" data-lealez-tab="business-contact"><?php esc_html_e( 'Contacto', 'lealez' ); ?></button><button type="button" data-lealez-tab="business-social"><?php esc_html_e( 'Redes', 'lealez' ); ?></button><button type="button" data-lealez-tab="business-legal"><?php esc_html_e( 'Legal', 'lealez' ); ?></button></div>
                <section class="lealez-tab-panel is-active" data-lealez-panel="business-general"><div class="lealez-section-head"><h3><?php esc_html_e( 'Información general', 'lealez' ); ?></h3><p><?php esc_html_e( 'Datos principales que identifican el perfil empresarial.', 'lealez' ); ?></p></div><div class="lealez-field-grid"><?php $this->input_field( 'business_title', __( 'Título interno', 'lealez' ), $business ? $business->post_title : '', 'text', true ); ?><?php $this->input_field( 'business_name', __( 'Nombre comercial', 'lealez' ), $get( 'business_name' ), 'text', true ); ?><?php $this->input_field( 'business_legal_name', __( 'Razón social', 'lealez' ), $get( 'business_legal_name' ) ); ?><?php $this->select_field( 'business_type', __( 'Tipo de empresa', 'lealez' ), $get( 'business_type' ), array( '' => __( 'No especificado', 'lealez' ), 'single_location' => __( 'Una ubicación', 'lealez' ), 'multi_location' => __( 'Varias ubicaciones', 'lealez' ) ) ); ?><?php $this->textarea_field( 'business_description', __( 'Descripción', 'lealez' ), $get( 'business_description' ), 6, 'lealez-field-span-2' ); ?><?php $this->input_field( 'business_founded_date', __( 'Fecha de fundación', 'lealez' ), $get( 'business_founded_date' ), 'date' ); ?><?php $this->input_field( 'business_industry', __( 'Industria', 'lealez' ), $get( 'business_industry' ) ); ?><?php $this->input_field( 'business_category', __( 'Categoría', 'lealez' ), $get( 'business_category' ) ); ?><?php $this->input_field( 'business_subcategory', __( 'Subcategoría', 'lealez' ), $get( 'business_subcategory' ) ); ?><?php $this->select_field( 'status', __( 'Estado operativo', 'lealez' ), $get( 'status', 'active' ), array( 'active' => __( 'Activa', 'lealez' ), 'inactive' => __( 'Inactiva', 'lealez' ), 'suspended' => __( 'Suspendida', 'lealez' ) ) ); ?></div></section>
                <section class="lealez-tab-panel" data-lealez-panel="business-brand"><div class="lealez-section-head"><h3><?php esc_html_e( 'Identidad de marca', 'lealez' ); ?></h3></div><div class="lealez-field-grid"><?php $this->input_field( 'brand_logo', __( 'URL del logotipo', 'lealez' ), $get( 'brand_logo' ), 'url' ); ?><?php $this->input_field( 'brand_icon', __( 'URL del icono', 'lealez' ), $get( 'brand_icon' ), 'url' ); ?><?php $this->input_field( 'brand_cover_image', __( 'URL de portada', 'lealez' ), $get( 'brand_cover_image' ), 'url' ); ?><?php $this->input_field( 'brand_tagline', __( 'Eslogan', 'lealez' ), $get( 'brand_tagline' ) ); ?><?php $this->input_field( 'brand_color_primary', __( 'Color principal', 'lealez' ), isset( $colors['primary'] ) ? $colors['primary'] : '#3782c4', 'color' ); ?><?php $this->input_field( 'brand_color_secondary', __( 'Color secundario', 'lealez' ), isset( $colors['secondary'] ) ? $colors['secondary'] : '#ffffff', 'color' ); ?><?php $this->input_field( 'brand_color_accent', __( 'Color de acento', 'lealez' ), isset( $colors['accent'] ) ? $colors['accent'] : '#172033', 'color' ); ?></div></section>
                <section class="lealez-tab-panel" data-lealez-panel="business-contact"><div class="lealez-section-head"><h3><?php esc_html_e( 'Contacto corporativo', 'lealez' ); ?></h3></div><div class="lealez-field-grid"><?php $this->input_field( 'corporate_email', __( 'Correo', 'lealez' ), $get( 'corporate_email' ), 'email' ); ?><?php $this->input_field( 'corporate_phone', __( 'Teléfono', 'lealez' ), $get( 'corporate_phone' ), 'tel' ); ?><?php $this->input_field( 'corporate_website', __( 'Sitio web', 'lealez' ), $get( 'corporate_website' ), 'url' ); ?><?php $this->input_field( 'corporate_address', __( 'Dirección', 'lealez' ), $get( 'corporate_address' ) ); ?><?php $this->input_field( 'corporate_city', __( 'Ciudad', 'lealez' ), $get( 'corporate_city' ) ); ?><?php $this->input_field( 'corporate_state', __( 'Departamento / estado', 'lealez' ), $get( 'corporate_state' ) ); ?><?php $this->input_field( 'corporate_country', __( 'País', 'lealez' ), $get( 'corporate_country' ) ); ?><?php $this->input_field( 'corporate_postal_code', __( 'Código postal', 'lealez' ), $get( 'corporate_postal_code' ) ); ?></div></section>
                <section class="lealez-tab-panel" data-lealez-panel="business-social"><div class="lealez-section-head"><h3><?php esc_html_e( 'Redes sociales', 'lealez' ); ?></h3></div><div class="lealez-field-grid"><?php foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'twitter' => 'X / Twitter', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'tiktok' => 'TikTok' ) as $network => $label ) { $this->input_field( 'social_' . $network, $label, $get( 'social_' . $network ), 'url' ); } ?></div></section>
                <section class="lealez-tab-panel" data-lealez-panel="business-legal"><div class="lealez-section-head"><h3><?php esc_html_e( 'Datos legales', 'lealez' ); ?></h3></div><div class="lealez-field-grid"><?php $this->input_field( 'tax_id', __( 'Identificación tributaria', 'lealez' ), $get( 'tax_id' ) ); ?><?php $this->input_field( 'business_license', __( 'Licencia comercial', 'lealez' ), $get( 'business_license' ) ); ?><?php $this->input_field( 'registration_number', __( 'Número de registro', 'lealez' ), $get( 'registration_number' ) ); ?></div></section>
                <div class="lealez-form-footer"><a class="lealez-btn lealez-btn-secondary" href="<?php echo esc_url( $this->page_url( 'businesses' ) ); ?>"><?php esc_html_e( 'Cancelar', 'lealez' ); ?></a><button class="lealez-btn lealez-btn-primary" type="submit"><?php esc_html_e( 'Guardar empresa', 'lealez' ); ?></button></div>
            </form>
        </div><?php return ob_get_clean();
    }

    public function shortcode_business_team() {
        if ( ! is_user_logged_in() ) { return $this->login_required(); }
        $this->enqueue_assets();
        $business_id = isset( $_GET['business_id'] ) ? absint( $_GET['business_id'] ) : 0;
        if ( ! $business_id || ! $this->can_manage_business_team( $business_id ) ) { return $this->forbidden_panel(); }
        $business = get_post( $business_id );
        $admins = $this->user_ids_to_emails( get_post_meta( $business_id, '_admin_users', true ) );
        $managers = $this->user_ids_to_emails( get_post_meta( $business_id, '_manager_users', true ) );
        ob_start(); ?>
        <div class="lealez-portal"><?php $this->render_notice(); ?><div class="lealez-page-head"><div><a class="lealez-back" href="<?php echo esc_url( $this->page_url( 'businesses' ) ); ?>">← <?php esc_html_e( 'Volver a empresas', 'lealez' ); ?></a><h2><?php echo esc_html( sprintf( __( 'Equipo de %s', 'lealez' ), $business->post_title ) ); ?></h2><p><?php esc_html_e( 'Los administradores pueden gestionar equipo e integraciones. Los gerentes pueden editar perfiles y ubicaciones.', 'lealez' ); ?></p></div></div><form method="post" class="lealez-form"><input type="hidden" name="lealez_frontend_action" value="save_business_team"><input type="hidden" name="business_id" value="<?php echo esc_attr( $business_id ); ?>"><?php wp_nonce_field( 'lealez_save_business_team_' . $business_id, 'lealez_nonce' ); ?><div class="lealez-field-grid"><?php $this->textarea_field( 'admin_user_emails', __( 'Administradores — un correo por línea', 'lealez' ), implode( "\n", $admins ), 8 ); ?><?php $this->textarea_field( 'manager_user_emails', __( 'Gerentes — un correo por línea', 'lealez' ), implode( "\n", $managers ), 8 ); ?></div><div class="lealez-info-box"><?php esc_html_e( 'Solo se agregan usuarios que ya existen en WordPress. El autor original de la empresa siempre conserva acceso administrativo.', 'lealez' ); ?></div><div class="lealez-form-footer"><button class="lealez-btn lealez-btn-primary" type="submit"><?php esc_html_e( 'Guardar equipo', 'lealez' ); ?></button></div></form></div><?php return ob_get_clean();
    }

    public function shortcode_business_integrations() {
        if ( ! is_user_logged_in() ) { return $this->login_required(); }
        $this->enqueue_assets();
        $business_id = isset( $_GET['business_id'] ) ? absint( $_GET['business_id'] ) : 0;
        if ( ! $business_id || ! $this->can_manage_business_team( $business_id ) ) { return $this->forbidden_panel(); }
        $business = get_post( $business_id );
        $gmb_connected = (bool) get_post_meta( $business_id, '_gmb_refresh_token', true );
        ob_start(); ?>
        <div class="lealez-portal"><?php $this->render_notice(); ?><div class="lealez-page-head"><div><a class="lealez-back" href="<?php echo esc_url( $this->page_url( 'businesses' ) ); ?>">← <?php esc_html_e( 'Volver a empresas', 'lealez' ); ?></a><h2><?php echo esc_html( sprintf( __( 'Integraciones de %s', 'lealez' ), $business->post_title ) ); ?></h2><p><?php esc_html_e( 'Esta página expone preferencias operativas, pero nunca tokens, secretos ni archivos JSON.', 'lealez' ); ?></p></div></div><div class="lealez-integration-status <?php echo $gmb_connected ? 'is-connected' : 'is-disconnected'; ?>"><div><span class="lealez-action-icon">G</span><div><h3>Google Business Profile</h3><p><?php echo $gmb_connected ? esc_html__( 'Conexión detectada', 'lealez' ) : esc_html__( 'Sin conexión activa', 'lealez' ); ?></p></div></div><strong><?php echo $gmb_connected ? esc_html__( 'Conectado', 'lealez' ) : esc_html__( 'Pendiente', 'lealez' ); ?></strong></div><form method="post" class="lealez-form"><input type="hidden" name="lealez_frontend_action" value="save_business_integrations"><input type="hidden" name="business_id" value="<?php echo esc_attr( $business_id ); ?>"><?php wp_nonce_field( 'lealez_save_business_integrations_' . $business_id, 'lealez_nonce' ); ?><div class="lealez-section-head"><h3><?php esc_html_e( 'Google Business Profile', 'lealez' ); ?></h3></div><div class="lealez-field-grid"><?php $this->checkbox_field( 'gmb_auto_refresh_token', __( 'Renovación automática del token', 'lealez' ), get_post_meta( $business_id, '_gmb_auto_refresh_token', true ) ); ?><?php $this->checkbox_field( 'gmb_delegation_enabled', __( 'Delegación habilitada', 'lealez' ), get_post_meta( $business_id, '_gmb_delegation_enabled', true ) ); ?><?php $this->checkbox_field( 'gmb_total_auto_sync_enabled', __( 'Sincronización total automática', 'lealez' ), get_post_meta( $business_id, '_gmb_total_auto_sync_enabled', true ) ); ?><?php $this->select_field( 'gmb_total_sync_frequency', __( 'Frecuencia de sincronización', 'lealez' ), get_post_meta( $business_id, '_gmb_total_sync_frequency', true ), array( 'daily' => __( 'Diaria', 'lealez' ), 'weekly' => __( 'Semanal', 'lealez' ), 'monthly' => __( 'Mensual', 'lealez' ) ) ); ?><?php $this->checkbox_field( 'gmb_reports_email_enabled', __( 'Enviar reportes por correo', 'lealez' ), get_post_meta( $business_id, '_gmb_reports_email_enabled', true ) ); ?><?php $this->select_field( 'gmb_reports_frequency', __( 'Frecuencia de reportes', 'lealez' ), get_post_meta( $business_id, '_gmb_reports_frequency', true ), array( 'weekly' => __( 'Semanal', 'lealez' ), 'monthly' => __( 'Mensual', 'lealez' ), 'quarterly' => __( 'Trimestral', 'lealez' ) ) ); ?></div><hr class="lealez-divider"><div class="lealez-section-head"><h3>Google Wallet</h3></div><div class="lealez-field-grid"><?php $this->input_field( 'google_issuer_id', __( 'Issuer ID', 'lealez' ), get_post_meta( $business_id, '_google_issuer_id', true ) ); ?><?php $this->input_field( 'google_merchant_id', __( 'Merchant ID', 'lealez' ), get_post_meta( $business_id, '_google_merchant_id', true ) ); ?><?php $this->input_field( 'google_service_account_email', __( 'Correo de la cuenta de servicio', 'lealez' ), get_post_meta( $business_id, '_google_service_account_email', true ), 'email' ); ?></div><div class="lealez-form-footer"><button class="lealez-btn lealez-btn-primary" type="submit"><?php esc_html_e( 'Guardar preferencias', 'lealez' ); ?></button></div></form></div><?php return ob_get_clean();
    }

    private function process_save_business() {
        $business_id = isset( $_POST['business_id'] ) ? absint( $_POST['business_id'] ) : 0;
        if ( ! isset( $_POST['lealez_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lealez_nonce'] ) ), 'lealez_save_business_' . $business_id ) ) { $this->redirect_notice( 'business_editor', 'invalid', array( 'business_id' => $business_id ) ); }
        if ( $business_id && ! $this->can_access_business( $business_id ) ) { $this->redirect_notice( 'businesses', 'forbidden' ); }
        $title = isset( $_POST['business_title'] ) ? sanitize_text_field( wp_unslash( $_POST['business_title'] ) ) : '';
        $name = isset( $_POST['business_name'] ) ? sanitize_text_field( wp_unslash( $_POST['business_name'] ) ) : '';
        if ( ! $title ) { $title = $name; }
        if ( ! $title ) { $this->redirect_notice( 'business_editor', 'invalid', array( 'business_id' => $business_id ) ); }
        $post_data = array( 'post_type' => 'oy_business', 'post_title' => $title );
        if ( $business_id ) { $existing = get_post( $business_id ); $post_data['ID'] = $business_id; $post_data['post_status'] = $existing ? $existing->post_status : 'publish'; $result = wp_update_post( $post_data, true ); }
        else { $post_data['post_status'] = 'publish'; $post_data['post_author'] = get_current_user_id(); $result = wp_insert_post( $post_data, true ); }
        if ( is_wp_error( $result ) ) { $this->redirect_notice( 'business_editor', 'invalid', array( 'business_id' => $business_id ) ); }
        $business_id = (int) $result;
        $fields = array( 'business_name'=>'text','business_legal_name'=>'text','business_type'=>'key','business_description'=>'textarea','business_founded_date'=>'date','business_industry'=>'text','business_category'=>'text','business_subcategory'=>'text','corporate_email'=>'email','corporate_phone'=>'text','corporate_website'=>'url','corporate_address'=>'text','corporate_city'=>'text','corporate_state'=>'text','corporate_country'=>'text','corporate_postal_code'=>'text','social_facebook'=>'url','social_instagram'=>'url','social_twitter'=>'url','social_linkedin'=>'url','social_youtube'=>'url','social_tiktok'=>'url','tax_id'=>'text','business_license'=>'text','registration_number'=>'text','brand_logo'=>'url','brand_icon'=>'url','brand_cover_image'=>'url','brand_tagline'=>'text','status'=>'key' );
        foreach ( $fields as $field => $type ) { update_post_meta( $business_id, '_' . $field, isset( $_POST[ $field ] ) ? $this->sanitize_value( $_POST[ $field ], $type ) : '' ); }
        if ( ! in_array( get_post_meta( $business_id, '_business_type', true ), array( '', 'single_location', 'multi_location' ), true ) ) { update_post_meta( $business_id, '_business_type', '' ); }
        if ( ! in_array( get_post_meta( $business_id, '_status', true ), array( 'active', 'inactive', 'suspended' ), true ) ) { update_post_meta( $business_id, '_status', 'active' ); }
        update_post_meta( $business_id, '_brand_colors', array( 'primary' => isset( $_POST['brand_color_primary'] ) ? sanitize_hex_color( wp_unslash( $_POST['brand_color_primary'] ) ) : '', 'secondary' => isset( $_POST['brand_color_secondary'] ) ? sanitize_hex_color( wp_unslash( $_POST['brand_color_secondary'] ) ) : '', 'accent' => isset( $_POST['brand_color_accent'] ) ? sanitize_hex_color( wp_unslash( $_POST['brand_color_accent'] ) ) : '' ) );
        if ( ! get_post_meta( $business_id, '_date_created', true ) ) { update_post_meta( $business_id, '_date_created', time() ); }
        update_post_meta( $business_id, '_last_updated', time() );
        $admins = get_post_meta( $business_id, '_admin_users', true ); $admins = is_array( $admins ) ? array_map( 'intval', $admins ) : array(); $admins[] = get_current_user_id(); update_post_meta( $business_id, '_admin_users', array_values( array_unique( $admins ) ) );
        $this->redirect_notice( 'business_editor', 'business_saved', array( 'business_id' => $business_id ) );
    }

    private function process_business_status( $action ) {
        $business_id = isset( $_POST['business_id'] ) ? absint( $_POST['business_id'] ) : 0;
        if ( ! $business_id || ! isset( $_POST['lealez_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lealez_nonce'] ) ), 'lealez_business_status_' . $business_id ) || ! $this->can_manage_business_team( $business_id ) ) { $this->redirect_notice( 'businesses', 'forbidden' ); }
        wp_update_post( array( 'ID' => $business_id, 'post_status' => 'archive_business' === $action ? 'draft' : 'publish' ) );
        $this->redirect_notice( 'businesses', 'archive_business' === $action ? 'business_archived' : 'business_restored' );
    }

    private function process_save_business_team() {
        $business_id = isset( $_POST['business_id'] ) ? absint( $_POST['business_id'] ) : 0;
        if ( ! $business_id || ! isset( $_POST['lealez_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lealez_nonce'] ) ), 'lealez_save_business_team_' . $business_id ) || ! $this->can_manage_business_team( $business_id ) ) { $this->redirect_notice( 'businesses', 'forbidden' ); }
        $missing_admins = array(); $missing_managers = array();
        $admins = $this->emails_to_user_ids( isset( $_POST['admin_user_emails'] ) ? $_POST['admin_user_emails'] : '', $missing_admins );
        $managers = $this->emails_to_user_ids( isset( $_POST['manager_user_emails'] ) ? $_POST['manager_user_emails'] : '', $missing_managers );
        $post = get_post( $business_id ); if ( $post && $post->post_author ) { $admins[] = (int) $post->post_author; }
        $admins = array_values( array_unique( array_filter( array_map( 'intval', $admins ) ) ) );
        $managers = array_values( array_diff( array_unique( array_filter( array_map( 'intval', $managers ) ) ), $admins ) );
        update_post_meta( $business_id, '_admin_users', $admins ); update_post_meta( $business_id, '_manager_users', $managers );
        $missing = count( array_unique( array_merge( $missing_admins, $missing_managers ) ) );
        $this->redirect_notice( 'business_team', $missing ? 'team_saved_partial' : 'team_saved', array( 'business_id' => $business_id, 'missing_count' => $missing ) );
    }

    private function process_save_business_integrations() {
        $business_id = isset( $_POST['business_id'] ) ? absint( $_POST['business_id'] ) : 0;
        if ( ! $business_id || ! isset( $_POST['lealez_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lealez_nonce'] ) ), 'lealez_save_business_integrations_' . $business_id ) || ! $this->can_manage_business_team( $business_id ) ) { $this->redirect_notice( 'businesses', 'forbidden' ); }
        foreach ( array( 'gmb_auto_refresh_token', 'gmb_delegation_enabled', 'gmb_total_auto_sync_enabled', 'gmb_reports_email_enabled' ) as $key ) { update_post_meta( $business_id, '_' . $key, isset( $_POST[ $key ] ) ? '1' : '0' ); }
        $sync = isset( $_POST['gmb_total_sync_frequency'] ) ? sanitize_key( wp_unslash( $_POST['gmb_total_sync_frequency'] ) ) : 'daily'; if ( ! in_array( $sync, array( 'daily', 'weekly', 'monthly' ), true ) ) { $sync = 'daily'; }
        $reports = isset( $_POST['gmb_reports_frequency'] ) ? sanitize_key( wp_unslash( $_POST['gmb_reports_frequency'] ) ) : 'monthly'; if ( ! in_array( $reports, array( 'weekly', 'monthly', 'quarterly' ), true ) ) { $reports = 'monthly'; }
        update_post_meta( $business_id, '_gmb_total_sync_frequency', $sync ); update_post_meta( $business_id, '_gmb_reports_frequency', $reports );
        update_post_meta( $business_id, '_google_issuer_id', isset( $_POST['google_issuer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_issuer_id'] ) ) : '' );
        update_post_meta( $business_id, '_google_merchant_id', isset( $_POST['google_merchant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_merchant_id'] ) ) : '' );
        update_post_meta( $business_id, '_google_service_account_email', isset( $_POST['google_service_account_email'] ) ? sanitize_email( wp_unslash( $_POST['google_service_account_email'] ) ) : '' );
        $this->redirect_notice( 'business_integrations', 'integrations_saved', array( 'business_id' => $business_id ) );
    }
}
