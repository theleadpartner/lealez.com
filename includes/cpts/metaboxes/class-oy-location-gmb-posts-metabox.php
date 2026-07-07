<?php
/**
 * GMB Posts Metabox for oy_location CPT
 *
 * Gestiona publicaciones (localPosts) de Google Business Profile desde Lealez:
 * listado, detalle, borradores locales, publicación, edición, eliminación e insights.
 *
 * Archivo: includes/cpts/metaboxes/class-oy-location-gmb-posts-metabox.php
 *
 * @package Lealez
 * @subpackage CPTs/Metaboxes
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class OY_Location_GMB_Posts_Metabox
 */
class OY_Location_GMB_Posts_Metabox {

    /**
     * Nonce para AJAX.
     */
    const NONCE_KEY = 'oy_gmb_posts_nonce';

    /**
     * Meta key de caché local de publicaciones GMB.
     */
    const META_CACHE = '_gmb_posts_cache';

    /**
     * Meta key de última sincronización de publicaciones GMB.
     */
    const META_LAST_SYNC = '_gmb_posts_last_sync';

    /**
     * Meta key de borradores locales.
     */
    const META_DRAFTS = '_gmb_posts_local_drafts';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        add_action( 'wp_ajax_oy_gmb_posts_fetch', array( $this, 'ajax_fetch_posts' ) );
        add_action( 'wp_ajax_oy_gmb_posts_get', array( $this, 'ajax_get_post' ) );
        add_action( 'wp_ajax_oy_gmb_posts_create', array( $this, 'ajax_create_post' ) );
        add_action( 'wp_ajax_oy_gmb_posts_update', array( $this, 'ajax_update_post' ) );
        add_action( 'wp_ajax_oy_gmb_posts_delete', array( $this, 'ajax_delete_post' ) );
        add_action( 'wp_ajax_oy_gmb_posts_insights', array( $this, 'ajax_post_insights' ) );

        add_action( 'wp_ajax_oy_gmb_posts_save_draft', array( $this, 'ajax_save_draft' ) );
        add_action( 'wp_ajax_oy_gmb_posts_delete_draft', array( $this, 'ajax_delete_draft' ) );
        add_action( 'wp_ajax_oy_gmb_posts_list_drafts', array( $this, 'ajax_list_drafts' ) );
    }

    /**
     * Registra el metabox.
     */
    public function register_meta_box() {
        add_meta_box(
            'oy_location_gmb_posts',
            __( '📢 Publicaciones de Google My Business', 'lealez' ),
            array( $this, 'render_meta_box' ),
            'oy_location',
            'normal',
            'default'
        );
    }

    /**
     * Encola estilos inline solo en edición de oy_location.
     *
     * @param string $hook Hook de admin.
     */
    public function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'oy_location' !== $screen->post_type ) {
            return;
        }

        wp_enqueue_script( 'jquery' );
        wp_enqueue_style( 'dashicons' );
        wp_enqueue_media();

        $css = '
        #oy_location_gmb_posts .inside{padding:0;margin:0}.oy-gmb-posts-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#fff}.oy-gmb-posts-topbar{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:16px 18px;border-bottom:1px solid #e5e7eb;background:linear-gradient(135deg,#f8fafc 0%,#fff 100%)}.oy-gmb-posts-title{display:flex;gap:10px;align-items:flex-start}.oy-gmb-posts-title-icon{width:36px;height:36px;border-radius:10px;background:#e8f0fe;color:#1a73e8;display:flex;align-items:center;justify-content:center;font-size:20px}.oy-gmb-posts-title h3{margin:0 0 4px;font-size:15px;line-height:1.3}.oy-gmb-posts-title p{margin:0;color:#5f6368;font-size:12px}.oy-gmb-posts-status{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.oy-gmb-pill{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:600;background:#f1f5f9;color:#334155}.oy-gmb-pill.good{background:#e6f4ea;color:#137333}.oy-gmb-pill.warn{background:#fff7e6;color:#b06000}.oy-gmb-pill.bad{background:#fce8e6;color:#b3261e}.oy-gmb-posts-tabs{display:flex;border-bottom:1px solid #dcdcde;background:#f6f7f7;overflow:auto}.oy-gmb-posts-tab{border:0;background:transparent;padding:12px 16px;cursor:pointer;font-weight:600;color:#50575e;border-bottom:3px solid transparent;white-space:nowrap}.oy-gmb-posts-tab:hover{color:#1d2327;background:#fff}.oy-gmb-posts-tab.active{background:#fff;color:#1a73e8;border-bottom-color:#1a73e8}.oy-gmb-posts-pane{display:none;padding:16px 18px}.oy-gmb-posts-pane.active{display:block}.oy-gmb-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px}.oy-gmb-toolbar select,.oy-gmb-toolbar input[type=search]{height:32px;min-width:150px}.oy-gmb-count{display:none;border-radius:999px;background:#eef3fd;color:#1a73e8;font-weight:700;font-size:11px;padding:5px 10px}.oy-gmb-notice{border-left:4px solid #1a73e8;background:#eef3fd;padding:10px 12px;margin:0 0 14px;color:#1d2327}.oy-gmb-notice.warning{border-left-color:#f0b429;background:#fffbee}.oy-gmb-notice.error{border-left-color:#d63638;background:#fdf2f2}.oy-gmb-notice.success{border-left-color:#1e8e3e;background:#e6f4ea}.oy-gmb-loading,.oy-gmb-empty{text-align:center;padding:28px;border:1px dashed #dcdcde;background:#fafafa;color:#646970}.oy-gmb-loading .spinner{float:none;margin:0 8px 0 0;visibility:visible}.oy-gmb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px}.oy-gmb-card{border:1px solid #dcdcde;border-radius:12px;background:#fff;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04);display:flex;flex-direction:column}.oy-gmb-card-media{height:130px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;overflow:hidden;color:#94a3b8;font-size:34px}.oy-gmb-card-media img{width:100%;height:100%;object-fit:cover}.oy-gmb-card-body{padding:12px;display:flex;flex-direction:column;gap:9px;flex:1}.oy-gmb-card-badges{display:flex;gap:6px;flex-wrap:wrap}.oy-gmb-badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:10px;font-weight:800;line-height:1.4;text-transform:uppercase;background:#f1f5f9;color:#475569}.oy-gmb-badge.state-live{background:#e6f4ea;color:#137333}.oy-gmb-badge.state-processing,.oy-gmb-badge.state-scheduled{background:#fff7e6;color:#b06000}.oy-gmb-badge.state-rejected,.oy-gmb-badge.state-deleted{background:#fce8e6;color:#b3261e}.oy-gmb-card-summary{font-size:13px;line-height:1.45;color:#1d2327;white-space:pre-line;max-height:86px;overflow:hidden}.oy-gmb-card-meta{display:flex;flex-direction:column;gap:4px;color:#646970;font-size:11px}.oy-gmb-card-extra{background:#f8fafc;border-radius:8px;padding:8px;font-size:11px;color:#334155}.oy-gmb-card-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:auto;padding-top:8px;border-top:1px solid #f1f5f9}.oy-gmb-card-actions .button-link-delete{color:#d63638}.oy-gmb-form-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,360px);gap:18px;align-items:start}.oy-gmb-panel{border:1px solid #dcdcde;border-radius:12px;background:#fff;overflow:hidden}.oy-gmb-panel h4{margin:0;padding:12px 14px;border-bottom:1px solid #e5e7eb;background:#f8fafc;font-size:13px}.oy-gmb-panel-body{padding:14px}.oy-gmb-form-row{margin-bottom:13px}.oy-gmb-form-row label{display:block;font-weight:700;font-size:12px;margin-bottom:5px;color:#1d2327}.oy-gmb-form-row label .required{color:#d63638}.oy-gmb-form-row input[type=text],.oy-gmb-form-row input[type=url],.oy-gmb-form-row input[type=date],.oy-gmb-form-row input[type=time],.oy-gmb-form-row input[type=datetime-local],.oy-gmb-form-row input[type=number],.oy-gmb-form-row select,.oy-gmb-form-row textarea{width:100%;max-width:100%;border:1px solid #c3c4c7;border-radius:8px}.oy-gmb-form-row textarea{min-height:130px;resize:vertical}.oy-gmb-two-cols{display:grid;grid-template-columns:1fr 1fr;gap:10px}.oy-gmb-three-cols{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}.oy-gmb-hint{font-size:11px;color:#646970;margin-top:4px}.oy-gmb-char-counter{text-align:right;font-size:11px;color:#646970}.oy-gmb-char-counter.over{color:#d63638;font-weight:800}.oy-gmb-preview-box{border:1px solid #e5e7eb;background:#f8fafc;border-radius:10px;padding:12px;min-height:190px}.oy-gmb-preview-media{height:120px;background:#e5e7eb;border-radius:8px;margin-bottom:10px;display:flex;align-items:center;justify-content:center;color:#64748b;overflow:hidden}.oy-gmb-preview-media img{width:100%;height:100%;object-fit:cover}.oy-gmb-preview-summary{white-space:pre-line;font-size:12px;line-height:1.45;color:#1d2327}.oy-gmb-actions-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px}.oy-gmb-actions-row .spinner{float:none;margin:0;visibility:hidden}.oy-gmb-actions-row .spinner.is-active{visibility:visible}.oy-gmb-result{display:none;margin-top:12px;border-radius:8px;padding:10px}.oy-gmb-result.success{display:block;background:#e6f4ea;color:#137333}.oy-gmb-result.error{display:block;background:#fce8e6;color:#b3261e}.oy-gmb-result.info{display:block;background:#eef3fd;color:#1a73e8}.oy-gmb-drafts-table{width:100%;border-collapse:collapse}.oy-gmb-drafts-table th,.oy-gmb-drafts-table td{border-bottom:1px solid #e5e7eb;padding:10px;text-align:left;vertical-align:top}.oy-gmb-drafts-table th{font-size:11px;text-transform:uppercase;color:#646970;background:#f8fafc}.oy-gmb-drafts-table .actions{white-space:nowrap}.oy-gmb-muted{color:#646970}.oy-gmb-section-hidden{display:none!important}.oy-gmb-media-picker{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.oy-gmb-media-picker input[type=url]{flex:1 1 360px}.oy-gmb-media-picker .button{white-space:nowrap}.oy-gmb-compact-notice{font-size:12px;padding:8px 10px;margin-bottom:12px}.oy-gmb-field-state{font-size:11px;color:#646970;margin-top:4px}.oy-gmb-field-state strong{color:#1d2327}.oy-gmb-cta-disabled input{background:#f6f7f7;color:#8c8f94}@media(max-width:900px){.oy-gmb-posts-topbar,.oy-gmb-form-grid{display:block}.oy-gmb-posts-status{justify-content:flex-start;margin-top:10px}.oy-gmb-two-cols,.oy-gmb-three-cols{grid-template-columns:1fr}.oy-gmb-grid{grid-template-columns:1fr}.oy-gmb-media-picker{display:block}.oy-gmb-media-picker .button{margin-top:8px}}
        ';

        wp_register_style( 'oy-gmb-posts-metabox-inline', false, array( 'dashicons' ), '1.0.0' );
        wp_enqueue_style( 'oy-gmb-posts-metabox-inline' );
        wp_add_inline_style( 'oy-gmb-posts-metabox-inline', $css );
    }

    /**
     * Renderiza el metabox.
     *
     * @param WP_Post $post Post actual.
     */
    public function render_meta_box( $post ) {
        $location_id   = $post->ID;
        $gmb_loc_name  = (string) get_post_meta( $location_id, 'gmb_location_name', true );
        $business_id   = (int) get_post_meta( $location_id, 'parent_business_id', true );
        $gmb_connected = $business_id ? (bool) get_post_meta( $business_id, '_gmb_connected', true ) : false;
        $nonce         = wp_create_nonce( self::NONCE_KEY );

        $preloaded_posts = get_post_meta( $location_id, self::META_CACHE, true );
        if ( ! is_array( $preloaded_posts ) ) {
            $preloaded_posts = array();
        }

        $drafts = $this->get_local_drafts( $location_id );
        $last_sync = (int) get_post_meta( $location_id, self::META_LAST_SYNC, true );
        ?>
        <div class="oy-gmb-posts-wrap" id="oy-gmb-posts-wrap"
             data-location-id="<?php echo esc_attr( $location_id ); ?>"
             data-business-id="<?php echo esc_attr( $business_id ); ?>"
             data-gmb-location-name="<?php echo esc_attr( $gmb_loc_name ); ?>">

            <?php if ( ! $business_id || ! $gmb_connected || empty( $gmb_loc_name ) ) : ?>
                <?php $this->render_no_connection_notice( $business_id, $gmb_connected, $gmb_loc_name ); ?>
            <?php else : ?>

                <div class="oy-gmb-posts-topbar">
                    <div class="oy-gmb-posts-title">
                        <div class="oy-gmb-posts-title-icon">📢</div>
                        <div>
                            <h3><?php esc_html_e( 'Centro de publicaciones GMB', 'lealez' ); ?></h3>
                            <p><?php esc_html_e( 'Crea borradores locales, publica en Google, edita publicaciones existentes, consulta el estado e insights sin salir de Lealez.', 'lealez' ); ?></p>
                        </div>
                    </div>
                    <div class="oy-gmb-posts-status">
                        <span class="oy-gmb-pill good">✅ <?php esc_html_e( 'Empresa conectada', 'lealez' ); ?></span>
                        <span class="oy-gmb-pill"><?php printf( esc_html__( '%d en caché', 'lealez' ), count( $preloaded_posts ) ); ?></span>
                        <span class="oy-gmb-pill warn"><?php printf( esc_html__( '%d borradores', 'lealez' ), count( $drafts ) ); ?></span>
                    </div>
                </div>

                <div class="oy-gmb-posts-tabs" role="tablist">
                    <button type="button" class="oy-gmb-posts-tab active" data-tab="list">📋 <?php esc_html_e( 'Publicaciones GMB', 'lealez' ); ?></button>
                    <button type="button" class="oy-gmb-posts-tab" data-tab="editor">✍️ <?php esc_html_e( 'Crear / Editar', 'lealez' ); ?></button>
                    <button type="button" class="oy-gmb-posts-tab" data-tab="drafts">💾 <?php esc_html_e( 'Borradores locales', 'lealez' ); ?> <span id="oy-gmb-drafts-tab-count"></span></button>
                    <button type="button" class="oy-gmb-posts-tab" data-tab="help">🧭 <?php esc_html_e( 'Guía rápida', 'lealez' ); ?></button>
                </div>

                <div class="oy-gmb-posts-pane active" id="oy-gmb-posts-tab-list">
                    <div class="oy-gmb-toolbar">
                        <select id="oy-gmb-filter-type">
                            <option value=""><?php esc_html_e( 'Todos los tipos', 'lealez' ); ?></option>
                            <option value="STANDARD"><?php esc_html_e( 'Actualizar', 'lealez' ); ?></option>
                            <option value="EVENT"><?php esc_html_e( 'Evento', 'lealez' ); ?></option>
                            <option value="OFFER"><?php esc_html_e( 'Oferta', 'lealez' ); ?></option>
                            <option value="ALERT"><?php esc_html_e( 'Alerta', 'lealez' ); ?></option>
                            <option value="PRODUCT"><?php esc_html_e( 'Producto / legado', 'lealez' ); ?></option>
                        </select>
                        <select id="oy-gmb-filter-state">
                            <option value=""><?php esc_html_e( 'Todos los estados', 'lealez' ); ?></option>
                            <option value="LIVE"><?php esc_html_e( 'Publicada', 'lealez' ); ?></option>
                            <option value="PROCESSING"><?php esc_html_e( 'Procesando', 'lealez' ); ?></option>
                            <option value="SCHEDULED"><?php esc_html_e( 'Programada', 'lealez' ); ?></option>
                            <option value="RECURRING"><?php esc_html_e( 'Recurrente', 'lealez' ); ?></option>
                            <option value="REJECTED"><?php esc_html_e( 'Rechazada', 'lealez' ); ?></option>
                            <option value="DELETED"><?php esc_html_e( 'Eliminada', 'lealez' ); ?></option>
                        </select>
                        <input type="search" id="oy-gmb-filter-search" placeholder="<?php esc_attr_e( 'Buscar por texto…', 'lealez' ); ?>">
                        <button type="button" class="button" id="oy-gmb-btn-refresh">🔄 <?php esc_html_e( 'Sincronizar desde GMB', 'lealez' ); ?></button>
                        <button type="button" class="button button-primary oy-gmb-go-editor" id="oy-gmb-new-post" aria-controls="oy-gmb-posts-tab-editor">➕ <?php esc_html_e( 'Nueva publicación', 'lealez' ); ?></button>
                        <span class="oy-gmb-count" id="oy-gmb-count"></span>
                    </div>

                    <div id="oy-gmb-posts-list-container">
                        <?php if ( ! empty( $preloaded_posts ) ) : ?>
                            <div class="oy-gmb-loading" style="display:none;"><span class="spinner is-active"></span><?php esc_html_e( 'Cargando publicaciones…', 'lealez' ); ?></div>
                        <?php else : ?>
                            <div class="oy-gmb-loading"><span class="spinner is-active"></span><?php esc_html_e( 'Cargando publicaciones desde Google…', 'lealez' ); ?></div>
                        <?php endif; ?>
                    </div>

                    <?php if ( $last_sync ) : ?>
                        <p class="oy-gmb-muted" style="margin-top:10px;">
                            <?php
                            printf(
                                esc_html__( 'Última sincronización: %s', 'lealez' ),
                                esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync ) )
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="oy-gmb-posts-pane" id="oy-gmb-posts-tab-editor">
                    <div class="oy-gmb-notice info">
                        <?php esc_html_e( 'Flujo recomendado: crea o edita en Lealez, guarda como borrador local y luego publica o actualiza en Google. Así evitas perder contenido si Google rechaza la solicitud o hay un error de conexión.', 'lealez' ); ?>
                    </div>

                    <div id="oy-gmb-post-form" class="oy-gmb-post-editor" autocomplete="off">
                        <input type="hidden" name="editor_mode" id="oy-gmb-editor-mode" value="create">
                        <input type="hidden" name="draft_id" id="oy-gmb-draft-id" value="">
                        <input type="hidden" name="post_name" id="oy-gmb-post-name" value="">

                        <div class="oy-gmb-form-grid">
                            <div class="oy-gmb-panel">
                                <h4><?php esc_html_e( 'Contenido principal', 'lealez' ); ?></h4>
                                <div class="oy-gmb-panel-body">
                                    <div class="oy-gmb-three-cols">
                                        <div class="oy-gmb-form-row">
                                            <label for="oy-gmb-topic-type"><?php esc_html_e( 'Tipo', 'lealez' ); ?> <span class="required">*</span></label>
                                            <select id="oy-gmb-topic-type" name="topic_type">
                                                <option value="STANDARD"><?php esc_html_e( 'Actualizar / Novedad', 'lealez' ); ?></option>
                                                <option value="EVENT"><?php esc_html_e( 'Evento', 'lealez' ); ?></option>
                                                <option value="OFFER"><?php esc_html_e( 'Oferta', 'lealez' ); ?></option>
                                                <option value="ALERT"><?php esc_html_e( 'Alerta', 'lealez' ); ?></option>
                                            </select>
                                        </div>
                                        <div class="oy-gmb-form-row">
                                            <label for="oy-gmb-language-code"><?php esc_html_e( 'Idioma', 'lealez' ); ?></label>
                                            <select id="oy-gmb-language-code" name="language_code">
                                                <option value="es">Español</option>
                                                <option value="en">English</option>
                                                <option value="pt-BR">Português</option>
                                            </select>
                                        </div>
                                        <div class="oy-gmb-form-row">
                                            <label for="oy-gmb-scheduled-time"><?php esc_html_e( 'Programar publicación', 'lealez' ); ?></label>
                                            <input type="datetime-local" id="oy-gmb-scheduled-time" name="scheduled_time">
                                        </div>
                                    </div>

                                    <div class="oy-gmb-form-row oy-gmb-alert-fields oy-gmb-section-hidden">
                                        <label for="oy-gmb-alert-type"><?php esc_html_e( 'Tipo de alerta', 'lealez' ); ?></label>
                                        <select id="oy-gmb-alert-type" name="alert_type">
                                            <option value="COVID_19"><?php esc_html_e( 'COVID-19', 'lealez' ); ?></option>
                                        </select>
                                        <div class="oy-gmb-hint"><?php esc_html_e( 'Google puede restringir la creación de alertas según disponibilidad de la API y políticas vigentes.', 'lealez' ); ?></div>
                                    </div>

                                    <div class="oy-gmb-form-row">
                                        <label for="oy-gmb-summary"><?php esc_html_e( 'Texto visible en Google', 'lealez' ); ?> <span class="required">*</span></label>
                                        <textarea id="oy-gmb-summary" name="summary" maxlength="1500" aria-describedby="oy-gmb-summary-help" placeholder="<?php esc_attr_e( 'Escribe aquí el mensaje/descripción que se verá públicamente en la publicación de Google…', 'lealez' ); ?>"></textarea>
                                        <div class="oy-gmb-char-counter"><span id="oy-gmb-summary-count">0</span>/1500</div>
                                        <div class="oy-gmb-hint" id="oy-gmb-summary-help"><?php esc_html_e( 'Campo obligatorio: es el texto principal de la publicación. Google lo recibe como “summary”.', 'lealez' ); ?></div>
                                    </div>

                                    <div class="oy-gmb-form-row">
                                        <label for="oy-gmb-image-url"><?php esc_html_e( 'Imagen de la publicación', 'lealez' ); ?></label>
                                        <div class="oy-gmb-media-picker">
                                            <input type="url" id="oy-gmb-image-url" name="image_url" placeholder="https://...">
                                            <button type="button" class="button" id="oy-gmb-select-image">🖼️ <?php esc_html_e( 'Elegir de biblioteca', 'lealez' ); ?></button>
                                            <button type="button" class="button" id="oy-gmb-clear-image">✕ <?php esc_html_e( 'Quitar', 'lealez' ); ?></button>
                                        </div>
                                        <div class="oy-gmb-hint"><?php esc_html_e( 'Puedes pegar una URL pública o elegir una imagen de la Biblioteca de Medios. Lealez copiará automáticamente la URL pública para enviarla a Google.', 'lealez' ); ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="oy-gmb-panel">
                                <h4><?php esc_html_e( 'Vista previa local', 'lealez' ); ?></h4>
                                <div class="oy-gmb-panel-body">
                                    <div class="oy-gmb-preview-box">
                                        <div class="oy-gmb-preview-media" id="oy-gmb-preview-media">📷</div>
                                        <div class="oy-gmb-card-badges" id="oy-gmb-preview-badges"></div>
                                        <div class="oy-gmb-preview-summary" id="oy-gmb-preview-summary"><?php esc_html_e( 'La vista previa aparecerá mientras escribes.', 'lealez' ); ?></div>
                                        <div class="oy-gmb-card-extra" id="oy-gmb-preview-extra" style="display:none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="oy-gmb-form-grid" style="margin-top:18px;">
                            <div class="oy-gmb-panel">
                                <h4><?php esc_html_e( 'Botón CTA / enlace de acción', 'lealez' ); ?></h4>
                                <div class="oy-gmb-panel-body">
                                    <div class="oy-gmb-notice info oy-gmb-compact-notice">
                                        <?php esc_html_e( 'El CTA es el botón público que Google puede mostrar debajo de la publicación. Lealez no toma esta URL de otro metabox: si eliges Más información, Reservar, Pedir, Comprar o Registrarse, debes escribir aquí la URL destino que Google enviará dentro de callToAction.url.', 'lealez' ); ?>
                                    </div>
                                    <div class="oy-gmb-two-cols">
                                        <div class="oy-gmb-form-row">
                                            <label for="oy-gmb-cta-type"><?php esc_html_e( 'Acción del botón', 'lealez' ); ?></label>
                                            <select id="oy-gmb-cta-type" name="cta_type">
                                                <option value="ACTION_TYPE_UNSPECIFIED"><?php esc_html_e( 'Sin botón', 'lealez' ); ?></option>
                                                <option value="LEARN_MORE"><?php esc_html_e( 'Más información', 'lealez' ); ?></option>
                                                <option value="BOOK"><?php esc_html_e( 'Reservar', 'lealez' ); ?></option>
                                                <option value="ORDER"><?php esc_html_e( 'Pedir', 'lealez' ); ?></option>
                                                <option value="SHOP"><?php esc_html_e( 'Comprar', 'lealez' ); ?></option>
                                                <option value="SIGN_UP"><?php esc_html_e( 'Registrarse', 'lealez' ); ?></option>
                                                <option value="CALL"><?php esc_html_e( 'Llamar ahora', 'lealez' ); ?></option>
                                            </select>
                                        </div>
                                        <div class="oy-gmb-form-row" id="oy-gmb-cta-url-row">
                                            <label for="oy-gmb-cta-url"><?php esc_html_e( 'URL destino del botón CTA', 'lealez' ); ?></label>
                                            <input type="url" id="oy-gmb-cta-url" name="cta_url" placeholder="https://tusitio.com/pagina-destino" aria-describedby="oy-gmb-cta-url-hint">
                                            <div class="oy-gmb-hint" id="oy-gmb-cta-url-hint"><?php esc_html_e( 'Escribe la URL pública que quieres usar como destino del botón. Solo se enviará cuando selecciones una acción compatible.', 'lealez' ); ?></div>
                                        </div>
                                    </div>
                                    <div class="oy-gmb-hint"><?php esc_html_e( 'Según la API de Google, la URL del CTA se envía en callToAction.url. Para “Llamar ahora”, la URL debe quedar vacía porque Google usa el teléfono del perfil. En publicaciones de oferta, Google ignora el CTA tradicional y usa la URL para canjear de la sección Oferta.', 'lealez' ); ?></div>
                                </div>
                            </div>

                            <div class="oy-gmb-panel oy-gmb-event-offer-fields oy-gmb-section-hidden">
                                <h4><?php esc_html_e( 'Evento / Oferta', 'lealez' ); ?></h4>
                                <div class="oy-gmb-panel-body">
                                    <div class="oy-gmb-form-row">
                                        <label for="oy-gmb-event-title"><?php esc_html_e( 'Título del evento/oferta', 'lealez' ); ?> <span class="required">*</span></label>
                                        <input type="text" id="oy-gmb-event-title" name="event_title" maxlength="58" placeholder="<?php esc_attr_e( 'Ej: Jornada especial, promoción de julio…', 'lealez' ); ?>">
                                    </div>
                                    <div class="oy-gmb-two-cols">
                                        <div class="oy-gmb-form-row">
                                            <label for="oy-gmb-event-start-date"><?php esc_html_e( 'Fecha inicio', 'lealez' ); ?></label>
                                            <input type="date" id="oy-gmb-event-start-date" name="event_start_date">
                                        </div>
                                        <div class="oy-gmb-form-row">
                                            <label for="oy-gmb-event-end-date"><?php esc_html_e( 'Fecha fin', 'lealez' ); ?></label>
                                            <input type="date" id="oy-gmb-event-end-date" name="event_end_date">
                                        </div>
                                    </div>
                                    <div class="oy-gmb-two-cols">
                                        <div class="oy-gmb-form-row">
                                            <label for="oy-gmb-event-start-time"><?php esc_html_e( 'Hora inicio', 'lealez' ); ?></label>
                                            <input type="time" id="oy-gmb-event-start-time" name="event_start_time">
                                        </div>
                                        <div class="oy-gmb-form-row">
                                            <label for="oy-gmb-event-end-time"><?php esc_html_e( 'Hora fin', 'lealez' ); ?></label>
                                            <input type="time" id="oy-gmb-event-end-time" name="event_end_time">
                                        </div>
                                    </div>

                                    <div class="oy-gmb-form-row oy-gmb-offer-fields oy-gmb-section-hidden">
                                        <label for="oy-gmb-coupon-code"><?php esc_html_e( 'Código de cupón', 'lealez' ); ?></label>
                                        <input type="text" id="oy-gmb-coupon-code" name="coupon_code" maxlength="80">
                                    </div>
                                    <div class="oy-gmb-form-row oy-gmb-offer-fields oy-gmb-section-hidden">
                                        <label for="oy-gmb-redeem-url"><?php esc_html_e( 'URL para canjear', 'lealez' ); ?></label>
                                        <input type="url" id="oy-gmb-redeem-url" name="redeem_url" placeholder="https://...">
                                    </div>
                                    <div class="oy-gmb-form-row oy-gmb-offer-fields oy-gmb-section-hidden">
                                        <label for="oy-gmb-offer-terms"><?php esc_html_e( 'Términos y condiciones', 'lealez' ); ?></label>
                                        <textarea id="oy-gmb-offer-terms" name="offer_terms" style="min-height:70px;"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="oy-gmb-panel" style="margin-top:18px;">
                            <h4><?php esc_html_e( 'Recurrencia opcional para eventos/ofertas', 'lealez' ); ?></h4>
                            <div class="oy-gmb-panel-body">
                                <div class="oy-gmb-three-cols">
                                    <div class="oy-gmb-form-row">
                                        <label for="oy-gmb-recurrence-type"><?php esc_html_e( 'Repetición', 'lealez' ); ?></label>
                                        <select id="oy-gmb-recurrence-type" name="recurrence_type">
                                            <option value="none"><?php esc_html_e( 'Sin recurrencia', 'lealez' ); ?></option>
                                            <option value="daily"><?php esc_html_e( 'Diaria', 'lealez' ); ?></option>
                                            <option value="weekly"><?php esc_html_e( 'Semanal', 'lealez' ); ?></option>
                                            <option value="monthly"><?php esc_html_e( 'Mensual', 'lealez' ); ?></option>
                                        </select>
                                    </div>
                                    <div class="oy-gmb-form-row">
                                        <label for="oy-gmb-recurrence-end-time"><?php esc_html_e( 'Finaliza', 'lealez' ); ?></label>
                                        <input type="datetime-local" id="oy-gmb-recurrence-end-time" name="recurrence_end_time">
                                    </div>
                                    <div class="oy-gmb-form-row oy-gmb-recurrence-monthly oy-gmb-section-hidden">
                                        <label for="oy-gmb-recurrence-monthly-day"><?php esc_html_e( 'Día del mes', 'lealez' ); ?></label>
                                        <input type="number" min="1" max="31" id="oy-gmb-recurrence-monthly-day" name="recurrence_monthly_day">
                                    </div>
                                </div>
                                <div class="oy-gmb-form-row oy-gmb-recurrence-weekly oy-gmb-section-hidden">
                                    <label><?php esc_html_e( 'Días de la semana', 'lealez' ); ?></label>
                                    <label style="font-weight:400;display:inline-block;margin-right:12px;"><input type="checkbox" name="recurrence_weekly_days[]" value="MONDAY"> <?php esc_html_e( 'Lun', 'lealez' ); ?></label>
                                    <label style="font-weight:400;display:inline-block;margin-right:12px;"><input type="checkbox" name="recurrence_weekly_days[]" value="TUESDAY"> <?php esc_html_e( 'Mar', 'lealez' ); ?></label>
                                    <label style="font-weight:400;display:inline-block;margin-right:12px;"><input type="checkbox" name="recurrence_weekly_days[]" value="WEDNESDAY"> <?php esc_html_e( 'Mié', 'lealez' ); ?></label>
                                    <label style="font-weight:400;display:inline-block;margin-right:12px;"><input type="checkbox" name="recurrence_weekly_days[]" value="THURSDAY"> <?php esc_html_e( 'Jue', 'lealez' ); ?></label>
                                    <label style="font-weight:400;display:inline-block;margin-right:12px;"><input type="checkbox" name="recurrence_weekly_days[]" value="FRIDAY"> <?php esc_html_e( 'Vie', 'lealez' ); ?></label>
                                    <label style="font-weight:400;display:inline-block;margin-right:12px;"><input type="checkbox" name="recurrence_weekly_days[]" value="SATURDAY"> <?php esc_html_e( 'Sáb', 'lealez' ); ?></label>
                                    <label style="font-weight:400;display:inline-block;margin-right:12px;"><input type="checkbox" name="recurrence_weekly_days[]" value="SUNDAY"> <?php esc_html_e( 'Dom', 'lealez' ); ?></label>
                                </div>
                            </div>
                        </div>

                        <div class="oy-gmb-actions-row">
                            <button type="button" class="button" id="oy-gmb-reset-form">🧹 <?php esc_html_e( 'Limpiar', 'lealez' ); ?></button>
                            <button type="button" class="button button-secondary" id="oy-gmb-save-draft">💾 <?php esc_html_e( 'Guardar borrador local', 'lealez' ); ?></button>
                            <button type="button" class="button button-primary" id="oy-gmb-publish-post">🚀 <?php esc_html_e( 'Publicar en Google', 'lealez' ); ?></button>
                            <button type="button" class="button button-primary oy-gmb-section-hidden" id="oy-gmb-update-post">🔁 <?php esc_html_e( 'Actualizar en Google', 'lealez' ); ?></button>
                            <span class="spinner" id="oy-gmb-form-spinner"></span>
                        </div>
                        <div class="oy-gmb-result" id="oy-gmb-form-result"></div>
                    </div>
                </div>

                <div class="oy-gmb-posts-pane" id="oy-gmb-posts-tab-drafts">
                    <div class="oy-gmb-notice info">
                        <?php esc_html_e( 'Los borradores locales se guardan solo en WordPress. No aparecen en Google hasta que presiones “Publicar en Google”.', 'lealez' ); ?>
                    </div>
                    <div id="oy-gmb-drafts-container"></div>
                </div>

                <div class="oy-gmb-posts-pane" id="oy-gmb-posts-tab-help">
                    <div class="oy-gmb-notice">
                        <strong><?php esc_html_e( 'Flujo recomendado para publicaciones', 'lealez' ); ?></strong><br>
                        <?php esc_html_e( '1) Redacta o edita en Lealez. 2) Guarda como borrador local. 3) Publica o actualiza en Google. 4) Sincroniza desde GMB para ver el estado real: publicada, procesando, programada o rechazada.', 'lealez' ); ?>
                    </div>
                    <div class="oy-gmb-grid">
                        <div class="oy-gmb-card"><div class="oy-gmb-card-body"><strong>📋 <?php esc_html_e( 'Listar / Obtener', 'lealez' ); ?></strong><p class="oy-gmb-muted"><?php esc_html_e( 'La pestaña Publicaciones GMB trae el listado y permite solicitar la ficha completa de cada publicación.', 'lealez' ); ?></p></div></div>
                        <div class="oy-gmb-card"><div class="oy-gmb-card-body"><strong>✍️ <?php esc_html_e( 'Crear / Editar', 'lealez' ); ?></strong><p class="oy-gmb-muted"><?php esc_html_e( 'El editor soporta publicación estándar, evento, oferta, alerta, CTA con URL personalizada, selección de imagen desde la Biblioteca de Medios, programación y recurrencia compatible con Local Posts.', 'lealez' ); ?></p></div></div>
                        <div class="oy-gmb-card"><div class="oy-gmb-card-body"><strong>📊 <?php esc_html_e( 'Insights', 'lealez' ); ?></strong><p class="oy-gmb-muted"><?php esc_html_e( 'Cuando Google devuelva métricas para el post, Lealez las muestra en una ventana de detalle.', 'lealez' ); ?></p></div></div>
                    </div>
                </div>

            <?php endif; ?>
        </div>
        <?php
        $this->render_inline_script( $location_id, $business_id, $gmb_loc_name, $nonce, $preloaded_posts, $drafts );
    }

    /**
     * Avisos cuando no hay conexión GMB suficiente.
     *
     * @param int    $business_id ID empresa.
     * @param bool   $gmb_connected Si empresa conectada.
     * @param string $gmb_loc_name Resource name ubicación.
     */
    private function render_no_connection_notice( $business_id, $gmb_connected, $gmb_loc_name ) {
        echo '<div class="oy-gmb-posts-topbar"><div class="oy-gmb-posts-title"><div class="oy-gmb-posts-title-icon">📢</div><div><h3>' . esc_html__( 'Publicaciones de Google My Business', 'lealez' ) . '</h3>';
        echo '<p>' . esc_html__( 'Conecta la empresa y vincula la ubicación GMB para habilitar el flujo de publicaciones.', 'lealez' ) . '</p></div></div></div>';

        if ( ! $business_id ) {
            echo '<div class="oy-gmb-notice warning" style="margin:16px;">';
            esc_html_e( '⚠️ Asigna una Empresa (oy_business) a esta ubicación para habilitar la integración con Google My Business.', 'lealez' );
            echo '</div>';
            return;
        }

        if ( ! $gmb_connected ) {
            echo '<div class="oy-gmb-notice warning" style="margin:16px;">';
            printf(
                wp_kses_post( __( '⚠️ La empresa no está conectada a Google My Business. %s para conectar.', 'lealez' ) ),
                '<a href="' . esc_url( get_edit_post_link( $business_id ) ) . '">' . esc_html__( 'Editar empresa', 'lealez' ) . '</a>'
            );
            echo '</div>';
            return;
        }

        if ( empty( $gmb_loc_name ) ) {
            echo '<div class="oy-gmb-notice info" style="margin:16px;">';
            esc_html_e( 'ℹ️ Esta ubicación aún no tiene vinculado un perfil GMB. Guarda el campo “Nombre GMB” (gmb_location_name) desde el metabox de Google My Business.', 'lealez' );
            echo '</div>';
        }
    }

    /**
     * Renderiza JS inline.
     *
     * @param int    $location_id ID ubicación.
     * @param int    $business_id ID empresa.
     * @param string $gmb_loc_name Nombre GMB.
     * @param string $nonce Nonce.
     * @param array  $preloaded_posts Posts precargados.
     * @param array  $drafts Borradores.
     */
    private function render_inline_script( $location_id, $business_id, $gmb_loc_name, $nonce, $preloaded_posts = array(), $drafts = array() ) {
        if ( ! $business_id || empty( $gmb_loc_name ) ) {
            return;
        }

        $preloaded_json = wp_json_encode( array_values( (array) $preloaded_posts ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $drafts_json    = wp_json_encode( array_values( (array) $drafts ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        ?>
        <script type="text/javascript">
        (function($){
            'use strict';

            var AJAXURL     = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var NONCE       = <?php echo wp_json_encode( $nonce ); ?>;
            var LOCATION_ID = <?php echo (int) $location_id; ?>;
            var BUSINESS_ID = <?php echo (int) $business_id; ?>;
            var GMB_LOC     = <?php echo wp_json_encode( $gmb_loc_name ); ?>;
            var allPosts    = <?php echo $preloaded_json ? $preloaded_json : '[]'; ?> || [];
            var drafts      = <?php echo $drafts_json ? $drafts_json : '[]'; ?> || [];

            var TYPE_LABELS = { STANDARD:'<?php echo esc_js( __( 'Actualizar', 'lealez' ) ); ?>', EVENT:'<?php echo esc_js( __( 'Evento', 'lealez' ) ); ?>', OFFER:'<?php echo esc_js( __( 'Oferta', 'lealez' ) ); ?>', ALERT:'<?php echo esc_js( __( 'Alerta', 'lealez' ) ); ?>', PRODUCT:'<?php echo esc_js( __( 'Producto / legado', 'lealez' ) ); ?>' };
            var STATE_LABELS = { LIVE:'<?php echo esc_js( __( 'Publicada', 'lealez' ) ); ?>', PROCESSING:'<?php echo esc_js( __( 'Procesando', 'lealez' ) ); ?>', SCHEDULED:'<?php echo esc_js( __( 'Programada', 'lealez' ) ); ?>', RECURRING:'<?php echo esc_js( __( 'Recurrente', 'lealez' ) ); ?>', REJECTED:'<?php echo esc_js( __( 'Rechazada', 'lealez' ) ); ?>', DELETED:'<?php echo esc_js( __( 'Eliminada', 'lealez' ) ); ?>', UNKNOWN:'<?php echo esc_js( __( 'Desconocido', 'lealez' ) ); ?>' };
            var CTA_LABELS = { LEARN_MORE:'<?php echo esc_js( __( 'Más información', 'lealez' ) ); ?>', BOOK:'<?php echo esc_js( __( 'Reservar', 'lealez' ) ); ?>', ORDER:'<?php echo esc_js( __( 'Pedir', 'lealez' ) ); ?>', SHOP:'<?php echo esc_js( __( 'Comprar', 'lealez' ) ); ?>', SIGN_UP:'<?php echo esc_js( __( 'Registrarse', 'lealez' ) ); ?>', CALL:'<?php echo esc_js( __( 'Llamar ahora', 'lealez' ) ); ?>' };
            var mediaFrame = null;

            function escHtml(str){ return $('<div>').text(str || '').html(); }
            function nl2br(str){ return escHtml(str || '').replace(/\n/g,'<br>'); }
            function formatDate(isoStr){ if(!isoStr){return '—';} var d=new Date(isoStr); if(isNaN(d.getTime())){return isoStr;} return d.toLocaleString('es-CO',{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); }
            function extractPostId(name){ var parts=(name || '').split('/'); return parts[parts.length-1] || ''; }
            function postEmoji(type){ return {STANDARD:'📢',EVENT:'📅',OFFER:'🏷️',ALERT:'🔔',PRODUCT:'🛍️'}[type] || '📢'; }
            function getPostImage(post){ var media=(post.media && post.media.length) ? post.media[0] : null; return media ? (media.googleUrl || media.sourceUrl || '') : ''; }
            function showResult(kind,msg){ $('#oy-gmb-form-result').removeClass('success error info').addClass(kind).html(escHtml(msg)).show(); }
            function clearResult(){ $('#oy-gmb-form-result').removeClass('success error info').hide().empty(); }
            function setBusy(isBusy){ $('#oy-gmb-form-spinner').toggleClass('is-active', !!isBusy); $('#oy-gmb-post-form button').prop('disabled', !!isBusy); }
            function api(action, data){ data = data || {}; data.action = action; data.nonce = NONCE; data.location_id = LOCATION_ID; data.business_id = BUSINESS_ID; data.gmb_loc_name = GMB_LOC; return $.post(AJAXURL, data); }

            function openMediaLibrary(e){
                if(e){ e.preventDefault(); }
                clearResult();
                if(typeof wp === 'undefined' || !wp.media){
                    showResult('error','<?php echo esc_js( __( 'La Biblioteca de Medios de WordPress no está disponible en esta pantalla.', 'lealez' ) ); ?>');
                    return;
                }
                if(mediaFrame){ mediaFrame.open(); return; }
                mediaFrame = wp.media({
                    title: '<?php echo esc_js( __( 'Seleccionar imagen para Google My Business', 'lealez' ) ); ?>',
                    button: { text: '<?php echo esc_js( __( 'Usar esta imagen', 'lealez' ) ); ?>' },
                    library: { type: 'image' },
                    multiple: false
                });
                mediaFrame.on('select', function(){
                    var attachment = mediaFrame.state().get('selection').first();
                    if(!attachment){ return; }
                    var data = attachment.toJSON ? attachment.toJSON() : {};
                    if(data && data.url){
                        $('#oy-gmb-image-url').val(data.url).trigger('input').trigger('change');
                        showResult('info','<?php echo esc_js( __( 'Imagen seleccionada desde la Biblioteca de Medios. Se usará su URL pública al enviar a Google.', 'lealez' ) ); ?>');
                    }
                });
                mediaFrame.open();
            }

            function switchTab(tab){ $('.oy-gmb-posts-tab').removeClass('active'); $('.oy-gmb-posts-tab[data-tab="'+tab+'"]').addClass('active'); $('.oy-gmb-posts-pane').removeClass('active'); $('#oy-gmb-posts-tab-'+tab).addClass('active'); }
            $(document).on('click','.oy-gmb-posts-tab',function(e){ e.preventDefault(); switchTab($(this).data('tab')); });
            function startNewPost(){
                resetEditor(true);
                $('#oy-gmb-topic-type').val('STANDARD');
                $('#oy-gmb-language-code').val('es');
                refreshConditionalFields();
                updatePreview();
                switchTab('editor');
                showResult('info','<?php echo esc_js( __( 'Nueva publicación lista para editar. Completa el texto visible en Google y publica o guarda el borrador local.', 'lealez' ) ); ?>');
                setTimeout(function(){ $('#oy-gmb-summary').trigger('focus'); }, 80);
            }
            $(document).on('click','#oy-gmb-new-post,.oy-gmb-go-editor',function(e){ e.preventDefault(); startNewPost(); });

            function fetchPosts(forceRefresh){
                $('#oy-gmb-posts-list-container').html('<div class="oy-gmb-loading"><span class="spinner is-active"></span><?php echo esc_js( __( 'Cargando publicaciones…', 'lealez' ) ); ?></div>');
                $('#oy-gmb-count').hide();
                api('oy_gmb_posts_fetch', { force_refresh: forceRefresh ? 1 : 0 }).done(function(resp){
                    if(resp && resp.success){ allPosts = resp.data.posts || []; renderPostsList(); }
                    else { $('#oy-gmb-posts-list-container').html('<div class="oy-gmb-notice error">'+escHtml((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'Error al cargar publicaciones.', 'lealez' ) ); ?>')+'</div>'); }
                }).fail(function(){ $('#oy-gmb-posts-list-container').html('<div class="oy-gmb-notice error"><?php echo esc_js( __( 'Error de conexión al servidor.', 'lealez' ) ); ?></div>'); });
            }

            function renderPostsList(){
                var typeFilter=$('#oy-gmb-filter-type').val();
                var stateFilter=$('#oy-gmb-filter-state').val();
                var search=($('#oy-gmb-filter-search').val() || '').toLowerCase();
                var filtered=allPosts.filter(function(p){
                    var type=p.topicType || 'STANDARD'; var state=p.state || 'UNKNOWN'; var txt=(p.summary || '').toLowerCase();
                    if(typeFilter && type !== typeFilter){return false;}
                    if(stateFilter && state !== stateFilter){return false;}
                    if(search && txt.indexOf(search) === -1){return false;}
                    return true;
                });
                $('#oy-gmb-count').text(filtered.length+' / '+allPosts.length+' <?php echo esc_js( __( 'publicaciones', 'lealez' ) ); ?>').show();
                if(!filtered.length){ $('#oy-gmb-posts-list-container').html('<div class="oy-gmb-empty"><span class="dashicons dashicons-megaphone"></span><p><?php echo esc_js( __( 'No hay publicaciones con los filtros seleccionados.', 'lealez' ) ); ?></p></div>'); return; }
                var html='<div class="oy-gmb-grid">'; $.each(filtered,function(i,p){ html += buildPostCard(p); }); html+='</div>'; $('#oy-gmb-posts-list-container').html(html);
            }

            function buildPostCard(post){
                var name=post.name || ''; var type=post.topicType || 'STANDARD'; var state=post.state || 'UNKNOWN'; var summary=post.summary || ''; var postId=extractPostId(name); var img=getPostImage(post); var cta=post.callToAction || {}; var event=post.event || null; var offer=post.offer || null; var searchUrl=post.searchUrl || '';
                var mediaHtml = img ? '<img src="'+escHtml(img)+'" alt="" loading="lazy">' : postEmoji(type);
                var ctaHtml = (cta.actionType && cta.actionType !== 'ACTION_TYPE_UNSPECIFIED') ? '<span>🔗 '+escHtml(CTA_LABELS[cta.actionType] || cta.actionType)+'</span>' : '';
                var extra='';
                if(event){ extra += '<strong>'+escHtml(event.title || '<?php echo esc_js( __( 'Evento / oferta', 'lealez' ) ); ?>')+'</strong>'; if(event.schedule){ extra += '<br>'+escHtml(scheduleText(event.schedule)); } }
                if(offer){ if(offer.couponCode){ extra += '<br>🎟️ '+escHtml(offer.couponCode); } if(offer.termsConditions){ extra += '<br>'+escHtml(offer.termsConditions); } }
                return '<div class="oy-gmb-card" data-post-name="'+escHtml(name)+'">'+
                    '<div class="oy-gmb-card-media">'+mediaHtml+'</div>'+
                    '<div class="oy-gmb-card-body">'+
                    '<div class="oy-gmb-card-badges"><span class="oy-gmb-badge">'+escHtml(TYPE_LABELS[type] || type)+'</span><span class="oy-gmb-badge state-'+escHtml(state.toLowerCase())+'">'+escHtml(STATE_LABELS[state] || state)+'</span></div>'+
                    '<div class="oy-gmb-card-summary">'+nl2br(summary || '—')+'</div>'+
                    (extra ? '<div class="oy-gmb-card-extra">'+extra+'</div>' : '')+
                    '<div class="oy-gmb-card-meta"><span>ID: '+escHtml(postId)+'</span><span>🕐 '+escHtml(formatDate(post.updateTime || post.createTime || post.scheduledTime))+'</span>'+ctaHtml+'</div>'+
                    '<div class="oy-gmb-card-actions">'+
                    (searchUrl ? '<a class="button button-small" href="'+escHtml(searchUrl)+'" target="_blank" rel="noopener">👁️ <?php echo esc_js( __( 'Ver', 'lealez' ) ); ?></a>' : '')+
                    '<button type="button" class="button button-small oy-gmb-edit-post" data-post-name="'+escHtml(name)+'">✏️ <?php echo esc_js( __( 'Editar', 'lealez' ) ); ?></button>'+
                    '<button type="button" class="button button-small oy-gmb-duplicate-post" data-post-name="'+escHtml(name)+'">📄 <?php echo esc_js( __( 'Duplicar', 'lealez' ) ); ?></button>'+
                    '<button type="button" class="button button-small oy-gmb-get-post" data-post-name="'+escHtml(name)+'">🔎 <?php echo esc_js( __( 'Detalle', 'lealez' ) ); ?></button>'+
                    '<button type="button" class="button button-small oy-gmb-insights-post" data-post-name="'+escHtml(name)+'">📊 <?php echo esc_js( __( 'Insights', 'lealez' ) ); ?></button>'+
                    '<button type="button" class="button-link button-link-delete oy-gmb-delete-post" data-post-name="'+escHtml(name)+'">🗑️ <?php echo esc_js( __( 'Eliminar', 'lealez' ) ); ?></button>'+
                    '</div></div></div>';
            }

            function scheduleText(schedule){
                function d(o){ return o ? [o.year, ('0'+o.month).slice(-2), ('0'+o.day).slice(-2)].join('-') : ''; }
                function t(o){ return o ? [('0'+(o.hours || 0)).slice(-2), ('0'+(o.minutes || 0)).slice(-2)].join(':') : ''; }
                var a=d(schedule.startDate), b=d(schedule.endDate), c=t(schedule.startTime), e=t(schedule.endTime);
                return [a,c,'→',b,e].join(' ').trim();
            }

            function formDataObject(){
                var data={};
                var weeklyDays=[];

                $('#oy-gmb-post-form').find('input, select, textarea').each(function(){
                    var el=this;
                    var $el=$(this);
                    var name=$el.attr('name');
                    var type=(el.type || '').toLowerCase();

                    if(!name || type === 'button' || type === 'submit' || type === 'reset' || type === 'file'){
                        return;
                    }

                    if(name === 'recurrence_weekly_days[]'){
                        if(el.checked){ weeklyDays.push($el.val()); }
                        return;
                    }

                    if(type === 'checkbox'){
                        data[name]=!!el.checked;
                        return;
                    }

                    if(type === 'radio'){
                        if(el.checked){ data[name]=$el.val(); }
                        return;
                    }

                    data[name]=$el.val();
                });

                data.recurrence_weekly_days=weeklyDays;
                data.summary=$.trim(data.summary || '');
                data.cta_url=$.trim(data.cta_url || '');
                data.image_url=$.trim(data.image_url || '');
                return data;
            }

            function fillForm(data, mode){
                resetEditor(false);
                data=data || {}; mode=mode || 'create';
                $('#oy-gmb-editor-mode').val(mode);
                $('#oy-gmb-draft-id').val(data.draft_id || '');
                $('#oy-gmb-post-name').val(data.post_name || data.name || '');
                $('#oy-gmb-topic-type').val(data.topic_type || data.topicType || 'STANDARD');
                $('#oy-gmb-language-code').val(data.language_code || data.languageCode || 'es');
                $('#oy-gmb-summary').val(data.summary || '');
                $('#oy-gmb-image-url').val(data.image_url || data.imageUrl || getPostImage(data) || '');
                var cta=data.callToAction || {}; $('#oy-gmb-cta-type').val(data.cta_type || cta.actionType || 'ACTION_TYPE_UNSPECIFIED'); $('#oy-gmb-cta-url').val(data.cta_url || cta.url || '');
                if(data.scheduled_time){ $('#oy-gmb-scheduled-time').val(data.scheduled_time); } else if(data.scheduledTime){ $('#oy-gmb-scheduled-time').val(toDatetimeLocal(data.scheduledTime)); }
                var event=data.event || {}; var schedule=event.schedule || {};
                $('#oy-gmb-event-title').val(data.event_title || event.title || '');
                $('#oy-gmb-event-start-date').val(data.event_start_date || dateObjToInput(schedule.startDate));
                $('#oy-gmb-event-end-date').val(data.event_end_date || dateObjToInput(schedule.endDate));
                $('#oy-gmb-event-start-time').val(data.event_start_time || timeObjToInput(schedule.startTime));
                $('#oy-gmb-event-end-time').val(data.event_end_time || timeObjToInput(schedule.endTime));
                var offer=data.offer || {}; $('#oy-gmb-coupon-code').val(data.coupon_code || offer.couponCode || ''); $('#oy-gmb-redeem-url').val(data.redeem_url || offer.redeemOnlineUrl || ''); $('#oy-gmb-offer-terms').val(data.offer_terms || offer.termsConditions || '');
                $('#oy-gmb-alert-type').val(data.alert_type || data.alertType || 'COVID_19');
                $('#oy-gmb-recurrence-type').val(data.recurrence_type || recurrenceTypeFromEvent(event) || 'none');
                $('#oy-gmb-recurrence-end-time').val(data.recurrence_end_time || (event.recurrenceInfo && event.recurrenceInfo.seriesEndTime ? toDatetimeLocal(event.recurrenceInfo.seriesEndTime) : ''));
                $('#oy-gmb-recurrence-monthly-day').val(data.recurrence_monthly_day || (event.recurrenceInfo && event.recurrenceInfo.monthlyPattern ? event.recurrenceInfo.monthlyPattern.dayOfMonth || '' : ''));
                $('input[name="recurrence_weekly_days[]"]').prop('checked', false);
                var days=data.recurrence_weekly_days || (event.recurrenceInfo && event.recurrenceInfo.weeklyPattern ? event.recurrenceInfo.weeklyPattern.daysOfWeek || [] : []);
                $.each(days,function(_,day){ $('input[name="recurrence_weekly_days[]"][value="'+day+'"]').prop('checked', true); });
                refreshConditionalFields(); updatePreview(); updateActionButtons(); switchTab('editor');
            }

            function resetEditor(doPreview){
                var $box=$('#oy-gmb-post-form');
                $box.find('input[type="text"],input[type="url"],input[type="date"],input[type="time"],input[type="datetime-local"],input[type="number"],textarea').val('');
                $box.find('input[type="checkbox"],input[type="radio"]').prop('checked', false);
                $box.find('select').each(function(){ this.selectedIndex=0; });
                $('#oy-gmb-editor-mode').val('create');
                $('#oy-gmb-draft-id').val('');
                $('#oy-gmb-post-name').val('');
                $('#oy-gmb-topic-type').val('STANDARD');
                $('#oy-gmb-language-code').val('es');
                $('#oy-gmb-cta-type').val('ACTION_TYPE_UNSPECIFIED');
                $('#oy-gmb-alert-type').val('COVID_19');
                $('#oy-gmb-recurrence-type').val('none');
                clearResult();
                refreshConditionalFields();
                updateActionButtons();
                if(doPreview !== false){ updatePreview(); }
            }
            function toDatetimeLocal(iso){ var d=new Date(iso); if(isNaN(d.getTime())){return '';} var z=function(n){return ('0'+n).slice(-2);}; return d.getFullYear()+'-'+z(d.getMonth()+1)+'-'+z(d.getDate())+'T'+z(d.getHours())+':'+z(d.getMinutes()); }
            function dateObjToInput(o){ return o ? [o.year,('0'+o.month).slice(-2),('0'+o.day).slice(-2)].join('-') : ''; }
            function timeObjToInput(o){ return o ? [('0'+(o.hours || 0)).slice(-2),('0'+(o.minutes || 0)).slice(-2)].join(':') : ''; }
            function recurrenceTypeFromEvent(event){ if(!event || !event.recurrenceInfo){return 'none';} if(event.recurrenceInfo.dailyPattern){return 'daily';} if(event.recurrenceInfo.weeklyPattern){return 'weekly';} if(event.recurrenceInfo.monthlyPattern){return 'monthly';} return 'none'; }

            function refreshConditionalFields(){
                var type=$('#oy-gmb-topic-type').val(); var cta=$('#oy-gmb-cta-type').val(); var rec=$('#oy-gmb-recurrence-type').val();
                var ctaNeedsUrl = (type !== 'OFFER' && cta !== 'CALL' && cta !== 'ACTION_TYPE_UNSPECIFIED');
                var ctaUrlBlocked = (type === 'OFFER' || cta === 'CALL');

                $('.oy-gmb-event-offer-fields').toggleClass('oy-gmb-section-hidden', !(type === 'EVENT' || type === 'OFFER'));
                $('.oy-gmb-offer-fields').toggleClass('oy-gmb-section-hidden', type !== 'OFFER');
                $('.oy-gmb-alert-fields').toggleClass('oy-gmb-section-hidden', type !== 'ALERT');

                $('#oy-gmb-cta-url-row').toggleClass('oy-gmb-cta-disabled', ctaUrlBlocked);
                $('#oy-gmb-cta-url').prop('disabled', ctaUrlBlocked).prop('required', ctaNeedsUrl);

                if(type === 'OFFER'){
                    $('#oy-gmb-cta-url-hint').text('<?php echo esc_js( __( 'Campo deshabilitado para Oferta: Google ignora callToAction en topicType OFFER. Usa “URL para canjear” en la sección Evento / Oferta.', 'lealez' ) ); ?>');
                } else if(cta === 'CALL'){
                    $('#oy-gmb-cta-url-hint').text('<?php echo esc_js( __( 'Campo deshabilitado para Llamar ahora: la documentación de Google indica que callToAction.url debe quedar vacío para CALL.', 'lealez' ) ); ?>');
                } else if(cta === 'ACTION_TYPE_UNSPECIFIED'){
                    $('#oy-gmb-cta-url-hint').text('<?php echo esc_js( __( 'No se enviará botón CTA mientras la acción sea “Sin botón”. Puedes escribir una URL, pero debes elegir una acción para que Google la use.', 'lealez' ) ); ?>');
                } else {
                    $('#oy-gmb-cta-url-hint').text('<?php echo esc_js( __( 'URL obligatoria: esta será exactamente la URL enviada a Google en callToAction.url para el botón seleccionado. No se toma de otro metabox.', 'lealez' ) ); ?>');
                }
                $('.oy-gmb-recurrence-weekly').toggleClass('oy-gmb-section-hidden', rec !== 'weekly');
                $('.oy-gmb-recurrence-monthly').toggleClass('oy-gmb-section-hidden', rec !== 'monthly');
            }
            function updateActionButtons(){ var mode=$('#oy-gmb-editor-mode').val(); $('#oy-gmb-update-post').toggleClass('oy-gmb-section-hidden', mode !== 'edit_gmb'); $('#oy-gmb-publish-post').toggleClass('oy-gmb-section-hidden', mode === 'edit_gmb'); }

            function updatePreview(){
                var d=formDataObject(); var type=d.topic_type || 'STANDARD'; var image=d.image_url || '';
                $('#oy-gmb-summary-count').text((d.summary || '').length).parent().toggleClass('over',(d.summary || '').length > 1500);
                $('#oy-gmb-preview-media').html(image ? '<img src="'+escHtml(image)+'" alt="">' : postEmoji(type));
                $('#oy-gmb-preview-badges').html('<span class="oy-gmb-badge">'+escHtml(TYPE_LABELS[type] || type)+'</span>'+(d.scheduled_time ? '<span class="oy-gmb-badge state-scheduled"><?php echo esc_js( __( 'Programada', 'lealez' ) ); ?></span>' : ''));
                $('#oy-gmb-preview-summary').html(nl2br(d.summary || '<?php echo esc_js( __( 'La vista previa aparecerá mientras escribes.', 'lealez' ) ); ?>'));
                var extra=''; if(type === 'EVENT' || type === 'OFFER'){ extra += '<strong>'+escHtml(d.event_title || '<?php echo esc_js( __( 'Título evento/oferta', 'lealez' ) ); ?>')+'</strong>'; if(d.event_start_date || d.event_end_date){ extra += '<br>'+escHtml((d.event_start_date || '')+' '+(d.event_start_time || '')+' → '+(d.event_end_date || '')+' '+(d.event_end_time || '')); } } if(type === 'OFFER' && d.coupon_code){ extra += '<br>🎟️ '+escHtml(d.coupon_code); }
                $('#oy-gmb-preview-extra').toggle(!!extra).html(extra);
            }
            $(document).on('change input','#oy-gmb-post-form input,#oy-gmb-post-form select,#oy-gmb-post-form textarea',function(){ refreshConditionalFields(); updatePreview(); });
            $('#oy-gmb-reset-form').on('click',function(e){ e.preventDefault(); resetEditor(); });
            $(document).on('click','#oy-gmb-select-image', openMediaLibrary);
            $(document).on('click','#oy-gmb-clear-image', function(e){ e.preventDefault(); $('#oy-gmb-image-url').val('').trigger('input').trigger('change'); });

            function validateEditorData(data){
                if(!data.summary){
                    showResult('error','<?php echo esc_js( __( 'El campo “Texto visible en Google” es obligatorio. Escribe el texto principal antes de guardar o publicar.', 'lealez' ) ); ?>');
                    $('#oy-gmb-summary').trigger('focus');
                    return false;
                }
                if(data.topic_type !== 'OFFER' && data.cta_type !== 'CALL' && data.cta_type !== 'ACTION_TYPE_UNSPECIFIED' && !data.cta_url){
                    showResult('error','<?php echo esc_js( __( 'La URL destino del botón CTA es obligatoria para la acción seleccionada. Escríbela en el campo “URL destino del botón CTA”.', 'lealez' ) ); ?>');
                    $('#oy-gmb-cta-url').trigger('focus');
                    return false;
                }
                return true;
            }

            function saveDraft(){
                clearResult();
                var payload=formDataObject();
                if(!validateEditorData(payload)){ return; }
                setBusy(true);
                api('oy_gmb_posts_save_draft', payload).done(function(resp){
                    if(resp && resp.success){ drafts=resp.data.drafts || []; renderDrafts(); $('#oy-gmb-draft-id').val(resp.data.draft.draft_id || ''); showResult('success', resp.data.message || '<?php echo esc_js( __( 'Borrador guardado.', 'lealez' ) ); ?>'); }
                    else { showResult('error', (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'No se pudo guardar el borrador.', 'lealez' ) ); ?>'); }
                }).fail(function(){ showResult('error','<?php echo esc_js( __( 'Error de conexión al guardar borrador.', 'lealez' ) ); ?>'); }).always(function(){ setBusy(false); });
            }
            $('#oy-gmb-save-draft').on('click', saveDraft);

            function submitToGoogle(isUpdate){
                clearResult();
                var payload=formDataObject();
                if(!validateEditorData(payload)){ return; }
                setBusy(true);
                var action=isUpdate ? 'oy_gmb_posts_update' : 'oy_gmb_posts_create';
                api(action, payload).done(function(resp){
                    if(resp && resp.success){ showResult('success', resp.data.message || '<?php echo esc_js( __( 'Operación completada.', 'lealez' ) ); ?>'); fetchPosts(true); if(!isUpdate){ resetEditor(false); } }
                    else { showResult('error', (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'Google rechazó la solicitud.', 'lealez' ) ); ?>'); }
                }).fail(function(){ showResult('error','<?php echo esc_js( __( 'Error de conexión con el servidor.', 'lealez' ) ); ?>'); }).always(function(){ setBusy(false); });
            }
            $('#oy-gmb-publish-post').on('click',function(){ submitToGoogle(false); });
            $('#oy-gmb-update-post').on('click',function(){ submitToGoogle(true); });

            function renderDrafts(){
                $('#oy-gmb-drafts-tab-count').text(drafts.length ? '('+drafts.length+')' : '');
                if(!drafts.length){ $('#oy-gmb-drafts-container').html('<div class="oy-gmb-empty"><span class="dashicons dashicons-saved"></span><p><?php echo esc_js( __( 'No hay borradores locales guardados.', 'lealez' ) ); ?></p></div>'); return; }
                var html='<table class="oy-gmb-drafts-table"><thead><tr><th><?php echo esc_js( __( 'Tipo', 'lealez' ) ); ?></th><th><?php echo esc_js( __( 'Resumen', 'lealez' ) ); ?></th><th><?php echo esc_js( __( 'Actualizado', 'lealez' ) ); ?></th><th><?php echo esc_js( __( 'Acciones', 'lealez' ) ); ?></th></tr></thead><tbody>';
                $.each(drafts,function(i,d){ html += '<tr><td><span class="oy-gmb-badge">'+escHtml(TYPE_LABELS[d.topic_type] || d.topic_type || 'STANDARD')+'</span></td><td>'+nl2br((d.summary || '').substring(0,180))+'</td><td class="oy-gmb-muted">'+escHtml(formatDate(d.updated_at || d.created_at))+'</td><td class="actions"><button type="button" class="button button-small oy-gmb-edit-draft" data-draft-id="'+escHtml(d.draft_id)+'">✏️ <?php echo esc_js( __( 'Editar', 'lealez' ) ); ?></button> <button type="button" class="button button-small oy-gmb-publish-draft" data-draft-id="'+escHtml(d.draft_id)+'">🚀 <?php echo esc_js( __( 'Publicar', 'lealez' ) ); ?></button> <button type="button" class="button-link button-link-delete oy-gmb-delete-draft" data-draft-id="'+escHtml(d.draft_id)+'">🗑️ <?php echo esc_js( __( 'Eliminar', 'lealez' ) ); ?></button></td></tr>'; });
                html+='</tbody></table>'; $('#oy-gmb-drafts-container').html(html);
            }
            function findDraft(id){ for(var i=0;i<drafts.length;i++){ if(drafts[i].draft_id === id){ return drafts[i]; } } return null; }
            $(document).on('click','.oy-gmb-edit-draft',function(){ var d=findDraft($(this).data('draft-id')); if(d){ fillForm(d,'create'); $('#oy-gmb-draft-id').val(d.draft_id); } });
            $(document).on('click','.oy-gmb-publish-draft',function(){ var d=findDraft($(this).data('draft-id')); if(d){ fillForm(d,'create'); submitToGoogle(false); } });
            $(document).on('click','.oy-gmb-delete-draft',function(){ var id=$(this).data('draft-id'); if(!confirm('<?php echo esc_js( __( '¿Eliminar este borrador local?', 'lealez' ) ); ?>')){return;} api('oy_gmb_posts_delete_draft',{draft_id:id}).done(function(resp){ if(resp && resp.success){ drafts=resp.data.drafts || []; renderDrafts(); } else { alert((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'No se pudo eliminar el borrador.', 'lealez' ) ); ?>'); } }); });

            function findPost(name){ for(var i=0;i<allPosts.length;i++){ if(allPosts[i].name === name){ return allPosts[i]; } } return null; }
            $(document).on('click','.oy-gmb-edit-post',function(){ var p=findPost($(this).data('post-name')); if(p){ p.post_name=p.name; fillForm(p,'edit_gmb'); } });
            $(document).on('click','.oy-gmb-duplicate-post',function(){ var p=findPost($(this).data('post-name')); if(p){ p.post_name=''; fillForm(p,'create'); $('#oy-gmb-post-name').val(''); showResult('info','<?php echo esc_js( __( 'Publicación duplicada como borrador editable. Guarda o publica cuando esté lista.', 'lealez' ) ); ?>'); } });
            $(document).on('click','.oy-gmb-delete-post',function(){ var name=$(this).data('post-name'); if(!confirm('<?php echo esc_js( __( '¿Eliminar esta publicación de Google? Esta acción se reflejará en GMB.', 'lealez' ) ); ?>')){return;} api('oy_gmb_posts_delete',{post_name:name}).done(function(resp){ if(resp && resp.success){ fetchPosts(true); } else { alert((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'No se pudo eliminar.', 'lealez' ) ); ?>'); } }); });
            $(document).on('click','.oy-gmb-get-post',function(){ var name=$(this).data('post-name'); api('oy_gmb_posts_get',{post_name:name}).done(function(resp){ if(resp && resp.success){ var p=resp.data.post || {}; alert('<?php echo esc_js( __( 'Detalle recibido desde GMB:', 'lealez' ) ); ?>\n\n'+JSON.stringify(p,null,2).substring(0,2500)); } else { alert((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'No se pudo obtener el detalle.', 'lealez' ) ); ?>'); } }); });
            $(document).on('click','.oy-gmb-insights-post',function(){ var name=$(this).data('post-name'); api('oy_gmb_posts_insights',{post_name:name}).done(function(resp){ if(resp && resp.success){ alert('<?php echo esc_js( __( 'Insights devueltos por Google:', 'lealez' ) ); ?>\n\n'+JSON.stringify(resp.data.insights || {},null,2).substring(0,2500)); } else { alert((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'No se pudieron obtener insights.', 'lealez' ) ); ?>'); } }); });

            $('#oy-gmb-btn-refresh').on('click',function(){ fetchPosts(true); });
            $('#oy-gmb-filter-type,#oy-gmb-filter-state').on('change', renderPostsList);
            $('#oy-gmb-filter-search').on('input', renderPostsList);
            $(document).on('oy:gmb:posts:refreshed',function(){ fetchPosts(false); });

            refreshConditionalFields(); updatePreview(); renderDrafts();
            if(allPosts && allPosts.length){ renderPostsList(); } else { fetchPosts(false); }
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // AJAX: GMB
    // =========================================================================

    /**
     * AJAX: Lista publicaciones desde GMB.
     */
    public function ajax_fetch_posts() {
        $context = $this->validate_ajax_context();
        if ( is_wp_error( $context ) ) {
            $this->send_error( $context );
        }

        $force_refresh = ! empty( $_POST['force_refresh'] );
        $result = Lealez_GMB_API::get_location_local_posts( $context['business_id'], $context['gmb_loc_name'], ! $force_refresh );

        if ( is_wp_error( $result ) ) {
            $this->send_error( $result );
        }

        $posts_list = isset( $result['localPosts'] ) && is_array( $result['localPosts'] ) ? $result['localPosts'] : array();
        update_post_meta( $context['location_id'], self::META_CACHE, $posts_list );
        update_post_meta( $context['location_id'], self::META_LAST_SYNC, time() );

        wp_send_json_success( array(
            'posts' => $posts_list,
            'total' => isset( $result['total'] ) ? (int) $result['total'] : count( $posts_list ),
        ) );
    }

    /**
     * AJAX: Obtiene una publicación específica desde GMB.
     */
    public function ajax_get_post() {
        $context = $this->validate_ajax_context();
        if ( is_wp_error( $context ) ) {
            $this->send_error( $context );
        }

        $post_name = sanitize_text_field( wp_unslash( $_POST['post_name'] ?? '' ) );
        if ( empty( $post_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Falta el nombre de la publicación.', 'lealez' ) ) );
        }

        $result = Lealez_GMB_API::get_location_local_post( $context['business_id'], $post_name, false );
        if ( is_wp_error( $result ) ) {
            $this->send_error( $result );
        }

        wp_send_json_success( array( 'post' => $result ) );
    }

    /**
     * AJAX: Crea publicación en GMB.
     */
    public function ajax_create_post() {
        $context = $this->validate_ajax_context();
        if ( is_wp_error( $context ) ) {
            $this->send_error( $context );
        }

        $built = $this->build_payload_from_request();
        if ( is_wp_error( $built ) ) {
            $this->send_error( $built );
        }

        $result = Lealez_GMB_API::create_location_local_post( $context['business_id'], $context['gmb_loc_name'], $built['payload'] );
        if ( is_wp_error( $result ) ) {
            $this->send_error( $result );
        }

        $this->clear_posts_cache( $context['location_id'] );

        wp_send_json_success( array(
            'message' => __( 'Publicación creada correctamente en Google My Business.', 'lealez' ),
            'post'    => $result,
        ) );
    }

    /**
     * AJAX: Actualiza publicación existente en GMB.
     */
    public function ajax_update_post() {
        $context = $this->validate_ajax_context();
        if ( is_wp_error( $context ) ) {
            $this->send_error( $context );
        }

        $post_name = sanitize_text_field( wp_unslash( $_POST['post_name'] ?? '' ) );
        if ( empty( $post_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Falta el nombre de la publicación GMB que se va a actualizar.', 'lealez' ) ) );
        }

        $built = $this->build_payload_from_request();
        if ( is_wp_error( $built ) ) {
            $this->send_error( $built );
        }

        $result = Lealez_GMB_API::update_location_local_post(
            $context['business_id'],
            $context['gmb_loc_name'],
            $post_name,
            $built['payload'],
            $built['update_mask']
        );

        if ( is_wp_error( $result ) ) {
            $this->send_error( $result );
        }

        $this->clear_posts_cache( $context['location_id'] );

        wp_send_json_success( array(
            'message' => __( 'Publicación actualizada correctamente en Google My Business.', 'lealez' ),
            'post'    => $result,
        ) );
    }

    /**
     * AJAX: Elimina publicación en GMB.
     */
    public function ajax_delete_post() {
        $context = $this->validate_ajax_context();
        if ( is_wp_error( $context ) ) {
            $this->send_error( $context );
        }

        $post_name = sanitize_text_field( wp_unslash( $_POST['post_name'] ?? '' ) );
        if ( empty( $post_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Falta el nombre de la publicación.', 'lealez' ) ) );
        }

        $result = Lealez_GMB_API::delete_location_local_post( $context['business_id'], $context['gmb_loc_name'], $post_name );
        if ( is_wp_error( $result ) ) {
            $this->send_error( $result );
        }

        $this->clear_posts_cache( $context['location_id'] );

        wp_send_json_success( array( 'message' => __( 'Publicación eliminada correctamente en Google My Business.', 'lealez' ) ) );
    }

    /**
     * AJAX: Insights de una publicación.
     */
    public function ajax_post_insights() {
        $context = $this->validate_ajax_context();
        if ( is_wp_error( $context ) ) {
            $this->send_error( $context );
        }

        $post_name = sanitize_text_field( wp_unslash( $_POST['post_name'] ?? '' ) );
        if ( empty( $post_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Falta el nombre de la publicación.', 'lealez' ) ) );
        }

        $result = Lealez_GMB_API::report_location_local_post_insights( $context['business_id'], $context['gmb_loc_name'], array( $post_name ) );
        if ( is_wp_error( $result ) ) {
            $this->send_error( $result );
        }

        wp_send_json_success( array( 'insights' => $result ) );
    }

    // =========================================================================
    // AJAX: BORRADORES LOCALES
    // =========================================================================

    /**
     * AJAX: Guarda borrador local.
     */
    public function ajax_save_draft() {
        $context = $this->validate_ajax_context( false );
        if ( is_wp_error( $context ) ) {
            $this->send_error( $context );
        }

        $draft = $this->sanitize_draft_from_request();
        if ( is_wp_error( $draft ) ) {
            $this->send_error( $draft );
        }

        $drafts = $this->get_local_drafts( $context['location_id'] );
        $draft_id = isset( $draft['draft_id'] ) ? (string) $draft['draft_id'] : '';
        if ( empty( $draft_id ) ) {
            $draft_id = 'draft_' . time() . '_' . wp_generate_password( 8, false, false );
            $draft['created_at'] = gmdate( 'c' );
        }
        $draft['draft_id'] = $draft_id;
        $draft['updated_at'] = gmdate( 'c' );

        $updated = false;
        foreach ( $drafts as $idx => $existing ) {
            if ( isset( $existing['draft_id'] ) && $existing['draft_id'] === $draft_id ) {
                $draft['created_at'] = $existing['created_at'] ?? $draft['created_at'];
                $drafts[ $idx ] = $draft;
                $updated = true;
                break;
            }
        }
        if ( ! $updated ) {
            array_unshift( $drafts, $draft );
        }

        update_post_meta( $context['location_id'], self::META_DRAFTS, $drafts );

        wp_send_json_success( array(
            'message' => __( 'Borrador local guardado correctamente.', 'lealez' ),
            'draft'   => $draft,
            'drafts'  => $drafts,
        ) );
    }

    /**
     * AJAX: Elimina borrador local.
     */
    public function ajax_delete_draft() {
        $context = $this->validate_ajax_context( false );
        if ( is_wp_error( $context ) ) {
            $this->send_error( $context );
        }

        $draft_id = sanitize_text_field( wp_unslash( $_POST['draft_id'] ?? '' ) );
        if ( empty( $draft_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Falta el ID del borrador.', 'lealez' ) ) );
        }

        $drafts = $this->get_local_drafts( $context['location_id'] );
        $drafts = array_values( array_filter( $drafts, function( $draft ) use ( $draft_id ) {
            return ! isset( $draft['draft_id'] ) || $draft['draft_id'] !== $draft_id;
        } ) );

        update_post_meta( $context['location_id'], self::META_DRAFTS, $drafts );

        wp_send_json_success( array(
            'message' => __( 'Borrador eliminado.', 'lealez' ),
            'drafts'  => $drafts,
        ) );
    }

    /**
     * AJAX: Lista borradores locales.
     */
    public function ajax_list_drafts() {
        $context = $this->validate_ajax_context( false );
        if ( is_wp_error( $context ) ) {
            $this->send_error( $context );
        }

        wp_send_json_success( array( 'drafts' => $this->get_local_drafts( $context['location_id'] ) ) );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Valida nonce, permisos, contexto y disponibilidad API.
     *
     * @param bool $require_api Si debe validar la clase API.
     * @return array|WP_Error
     */
    private function validate_ajax_context( $require_api = true ) {
        if ( ! check_ajax_referer( self::NONCE_KEY, 'nonce', false ) ) {
            return new WP_Error( 'invalid_nonce', __( 'Nonce inválido.', 'lealez' ) );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            return new WP_Error( 'permission_denied', __( 'Sin permisos.', 'lealez' ) );
        }

        $location_id  = absint( $_POST['location_id'] ?? 0 );
        $business_id  = absint( $_POST['business_id'] ?? 0 );
        $gmb_loc_name = sanitize_text_field( wp_unslash( $_POST['gmb_loc_name'] ?? '' ) );

        if ( ! $location_id || ! $business_id || empty( $gmb_loc_name ) ) {
            return new WP_Error( 'missing_params', __( 'Parámetros incompletos.', 'lealez' ) );
        }

        if ( $require_api && ! class_exists( 'Lealez_GMB_API' ) ) {
            return new WP_Error( 'api_missing', __( 'API de Google My Business no disponible.', 'lealez' ) );
        }

        return array(
            'location_id'  => $location_id,
            'business_id'  => $business_id,
            'gmb_loc_name' => $gmb_loc_name,
        );
    }

    /**
     * Envía WP_Error como JSON.
     *
     * @param WP_Error $error Error.
     */
    private function send_error( $error ) {
        wp_send_json_error( array(
            'message' => $error->get_error_message(),
            'code'    => $error->get_error_code(),
            'data'    => $error->get_error_data(),
        ) );
    }

    /**
     * Obtiene borradores locales.
     *
     * @param int $location_id ID ubicación.
     * @return array
     */
    private function get_local_drafts( $location_id ) {
        $drafts = get_post_meta( $location_id, self::META_DRAFTS, true );
        return is_array( $drafts ) ? array_values( $drafts ) : array();
    }

    /**
     * Limpia caché local de publicaciones.
     *
     * @param int $location_id ID ubicación.
     */
    private function clear_posts_cache( $location_id ) {
        delete_post_meta( $location_id, self::META_CACHE );
        delete_post_meta( $location_id, self::META_LAST_SYNC );
    }

    /**
     * Sanitiza borrador desde request.
     *
     * @return array|WP_Error
     */
    private function sanitize_draft_from_request() {
        $summary = sanitize_textarea_field( wp_unslash( $_POST['summary'] ?? '' ) );
        if ( empty( $summary ) ) {
            return new WP_Error( 'missing_summary', __( 'El campo “Texto visible en Google” es obligatorio para guardar el borrador o publicar en Google.', 'lealez' ) );
        }

        return array(
            'draft_id'               => sanitize_text_field( wp_unslash( $_POST['draft_id'] ?? '' ) ),
            'post_name'              => sanitize_text_field( wp_unslash( $_POST['post_name'] ?? '' ) ),
            'topic_type'             => $this->sanitize_topic_type( $_POST['topic_type'] ?? 'STANDARD' ),
            'language_code'          => $this->sanitize_language_code( $_POST['language_code'] ?? 'es' ),
            'summary'                => $summary,
            'image_url'              => esc_url_raw( wp_unslash( $_POST['image_url'] ?? '' ) ),
            'cta_type'               => $this->sanitize_cta_type( $_POST['cta_type'] ?? 'ACTION_TYPE_UNSPECIFIED' ),
            'cta_url'                => esc_url_raw( wp_unslash( $_POST['cta_url'] ?? '' ) ),
            'scheduled_time'         => sanitize_text_field( wp_unslash( $_POST['scheduled_time'] ?? '' ) ),
            'event_title'            => sanitize_text_field( wp_unslash( $_POST['event_title'] ?? '' ) ),
            'event_start_date'       => sanitize_text_field( wp_unslash( $_POST['event_start_date'] ?? '' ) ),
            'event_end_date'         => sanitize_text_field( wp_unslash( $_POST['event_end_date'] ?? '' ) ),
            'event_start_time'       => sanitize_text_field( wp_unslash( $_POST['event_start_time'] ?? '' ) ),
            'event_end_time'         => sanitize_text_field( wp_unslash( $_POST['event_end_time'] ?? '' ) ),
            'coupon_code'            => sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) ),
            'redeem_url'             => esc_url_raw( wp_unslash( $_POST['redeem_url'] ?? '' ) ),
            'offer_terms'            => sanitize_textarea_field( wp_unslash( $_POST['offer_terms'] ?? '' ) ),
            'alert_type'             => $this->sanitize_alert_type( $_POST['alert_type'] ?? 'COVID_19' ),
            'recurrence_type'        => $this->sanitize_recurrence_type( $_POST['recurrence_type'] ?? 'none' ),
            'recurrence_weekly_days' => $this->sanitize_weekly_days( $_POST['recurrence_weekly_days'] ?? array() ),
            'recurrence_monthly_day' => absint( $_POST['recurrence_monthly_day'] ?? 0 ),
            'recurrence_end_time'    => sanitize_text_field( wp_unslash( $_POST['recurrence_end_time'] ?? '' ) ),
        );
    }

    /**
     * Construye payload LocalPost para Google.
     *
     * @return array|WP_Error
     */
    private function build_payload_from_request() {
        $draft = $this->sanitize_draft_from_request();
        if ( is_wp_error( $draft ) ) {
            return $draft;
        }

        if ( mb_strlen( $draft['summary'] ) > 1500 ) {
            return new WP_Error( 'summary_too_long', __( 'El campo “Texto visible en Google” supera el límite de 1,500 caracteres.', 'lealez' ) );
        }

        $payload = array(
            'topicType'    => $draft['topic_type'],
            'languageCode' => $draft['language_code'],
            'summary'      => $draft['summary'],
        );

        $update_mask = array( 'topicType', 'languageCode', 'summary' );

        if ( ! empty( $draft['scheduled_time'] ) ) {
            $scheduled = $this->datetime_local_to_rfc3339( $draft['scheduled_time'] );
            if ( is_wp_error( $scheduled ) ) {
                return $scheduled;
            }
            $payload['scheduledTime'] = $scheduled;
            $update_mask[] = 'scheduledTime';
        }

        if ( ! empty( $draft['image_url'] ) ) {
            $payload['media'] = array(
                array(
                    'mediaFormat' => 'PHOTO',
                    'sourceUrl'   => $draft['image_url'],
                ),
            );
            $update_mask[] = 'media';
        }

        if ( 'OFFER' !== $draft['topic_type'] && 'ACTION_TYPE_UNSPECIFIED' !== $draft['cta_type'] ) {
            $payload['callToAction'] = array( 'actionType' => $draft['cta_type'] );
            if ( 'CALL' !== $draft['cta_type'] ) {
                if ( empty( $draft['cta_url'] ) ) {
                    return new WP_Error( 'missing_cta_url', __( 'La URL destino del botón CTA es obligatoria para la acción seleccionada.', 'lealez' ) );
                }
                $payload['callToAction']['url'] = $draft['cta_url'];
            }
            $update_mask[] = 'callToAction';
        }

        if ( 'EVENT' === $draft['topic_type'] || 'OFFER' === $draft['topic_type'] ) {
            if ( empty( $draft['event_title'] ) ) {
                return new WP_Error( 'missing_event_title', __( 'El título del evento/oferta es obligatorio para publicaciones de tipo Evento u Oferta.', 'lealez' ) );
            }

            $event = array( 'title' => $draft['event_title'] );
            $schedule = $this->build_schedule_from_draft( $draft );
            if ( ! empty( $schedule ) ) {
                $event['schedule'] = $schedule;
            }

            $recurrence = $this->build_recurrence_from_draft( $draft );
            if ( is_wp_error( $recurrence ) ) {
                return $recurrence;
            }
            if ( ! empty( $recurrence ) ) {
                $event['recurrenceInfo'] = $recurrence;
            }

            $payload['event'] = $event;
            $update_mask[] = 'event';
        }

        if ( 'OFFER' === $draft['topic_type'] ) {
            $offer = array();
            if ( ! empty( $draft['coupon_code'] ) ) {
                $offer['couponCode'] = $draft['coupon_code'];
            }
            if ( ! empty( $draft['redeem_url'] ) ) {
                $offer['redeemOnlineUrl'] = $draft['redeem_url'];
            }
            if ( ! empty( $draft['offer_terms'] ) ) {
                $offer['termsConditions'] = $draft['offer_terms'];
            }
            if ( ! empty( $offer ) ) {
                $payload['offer'] = $offer;
                $update_mask[] = 'offer';
            }
        }

        if ( 'ALERT' === $draft['topic_type'] ) {
            $payload['alertType'] = $draft['alert_type'];
            $update_mask[] = 'alertType';
        }

        return array(
            'payload'     => $payload,
            'update_mask' => array_values( array_unique( $update_mask ) ),
        );
    }

    /**
     * Construye schedule de evento.
     *
     * @param array $draft Datos sanitizados.
     * @return array
     */
    private function build_schedule_from_draft( array $draft ) {
        $schedule = array();

        if ( ! empty( $draft['event_start_date'] ) ) {
            $schedule['startDate'] = $this->date_string_to_google_date( $draft['event_start_date'] );
        }
        if ( ! empty( $draft['event_end_date'] ) ) {
            $schedule['endDate'] = $this->date_string_to_google_date( $draft['event_end_date'] );
        }
        if ( ! empty( $draft['event_start_time'] ) ) {
            $schedule['startTime'] = $this->time_string_to_google_time( $draft['event_start_time'] );
        }
        if ( ! empty( $draft['event_end_time'] ) ) {
            $schedule['endTime'] = $this->time_string_to_google_time( $draft['event_end_time'] );
        }

        return array_filter( $schedule );
    }

    /**
     * Construye recurrencia de evento.
     *
     * @param array $draft Datos sanitizados.
     * @return array|WP_Error
     */
    private function build_recurrence_from_draft( array $draft ) {
        if ( 'none' === $draft['recurrence_type'] ) {
            return array();
        }

        $recurrence = array();
        if ( ! empty( $draft['recurrence_end_time'] ) ) {
            $end_time = $this->datetime_local_to_rfc3339( $draft['recurrence_end_time'] );
            if ( is_wp_error( $end_time ) ) {
                return $end_time;
            }
            $recurrence['seriesEndTime'] = $end_time;
        }

        if ( 'daily' === $draft['recurrence_type'] ) {
            $recurrence['dailyPattern'] = new stdClass();
        }

        if ( 'weekly' === $draft['recurrence_type'] ) {
            $recurrence['weeklyPattern'] = array(
                'daysOfWeek' => ! empty( $draft['recurrence_weekly_days'] ) ? $draft['recurrence_weekly_days'] : array(),
            );
        }

        if ( 'monthly' === $draft['recurrence_type'] ) {
            $day = (int) $draft['recurrence_monthly_day'];
            if ( $day < 1 || $day > 31 ) {
                return new WP_Error( 'invalid_monthly_day', __( 'Para recurrencia mensual debes indicar un día entre 1 y 31.', 'lealez' ) );
            }
            $recurrence['monthlyPattern'] = array( 'dayOfMonth' => $day );
        }

        return $recurrence;
    }

    /**
     * Convierte fecha YYYY-MM-DD a google.type.Date.
     *
     * @param string $date Fecha.
     * @return array
     */
    private function date_string_to_google_date( $date ) {
        $parts = explode( '-', (string) $date );
        if ( 3 !== count( $parts ) ) {
            return array();
        }
        return array(
            'year'  => (int) $parts[0],
            'month' => (int) $parts[1],
            'day'   => (int) $parts[2],
        );
    }

    /**
     * Convierte hora HH:MM a google.type.TimeOfDay.
     *
     * @param string $time Hora.
     * @return array
     */
    private function time_string_to_google_time( $time ) {
        $parts = explode( ':', (string) $time );
        if ( count( $parts ) < 2 ) {
            return array();
        }
        return array(
            'hours'   => (int) $parts[0],
            'minutes' => (int) $parts[1],
            'seconds' => 0,
            'nanos'   => 0,
        );
    }

    /**
     * Convierte datetime-local a RFC3339 usando zona horaria WP.
     *
     * @param string $value Valor datetime-local.
     * @return string|WP_Error
     */
    private function datetime_local_to_rfc3339( $value ) {
        $value = trim( (string) $value );
        if ( empty( $value ) ) {
            return '';
        }

        try {
            $tz = wp_timezone();
            $dt = new DateTimeImmutable( $value, $tz );
            return $dt->format( DATE_RFC3339 );
        } catch ( Exception $e ) {
            return new WP_Error( 'invalid_datetime', __( 'La fecha/hora programada no tiene un formato válido.', 'lealez' ) );
        }
    }

    /**
     * Sanitiza topic type.
     *
     * @param string $value Valor.
     * @return string
     */
    private function sanitize_topic_type( $value ) {
        $value = strtoupper( sanitize_text_field( wp_unslash( $value ) ) );
        return in_array( $value, array( 'STANDARD', 'EVENT', 'OFFER', 'ALERT' ), true ) ? $value : 'STANDARD';
    }

    /**
     * Sanitiza idioma.
     *
     * @param string $value Valor.
     * @return string
     */
    private function sanitize_language_code( $value ) {
        $value = sanitize_text_field( wp_unslash( $value ) );
        return preg_match( '/^[a-z]{2}(?:-[A-Z]{2})?$/', $value ) ? $value : 'es';
    }

    /**
     * Sanitiza CTA.
     *
     * @param string $value Valor.
     * @return string
     */
    private function sanitize_cta_type( $value ) {
        $value = strtoupper( sanitize_text_field( wp_unslash( $value ) ) );
        $allowed = array( 'ACTION_TYPE_UNSPECIFIED', 'BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL' );
        return in_array( $value, $allowed, true ) ? $value : 'ACTION_TYPE_UNSPECIFIED';
    }

    /**
     * Sanitiza alert type.
     *
     * @param string $value Valor.
     * @return string
     */
    private function sanitize_alert_type( $value ) {
        $value = strtoupper( sanitize_text_field( wp_unslash( $value ) ) );
        return in_array( $value, array( 'COVID_19' ), true ) ? $value : 'COVID_19';
    }

    /**
     * Sanitiza recurrencia.
     *
     * @param string $value Valor.
     * @return string
     */
    private function sanitize_recurrence_type( $value ) {
        $value = strtolower( sanitize_text_field( wp_unslash( $value ) ) );
        return in_array( $value, array( 'none', 'daily', 'weekly', 'monthly' ), true ) ? $value : 'none';
    }

    /**
     * Sanitiza días semanales.
     *
     * @param mixed $days Días.
     * @return array
     */
    private function sanitize_weekly_days( $days ) {
        if ( ! is_array( $days ) ) {
            return array();
        }
        $allowed = array( 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY' );
        $clean = array();
        foreach ( $days as $day ) {
            $day = strtoupper( sanitize_text_field( wp_unslash( $day ) ) );
            if ( in_array( $day, $allowed, true ) ) {
                $clean[] = $day;
            }
        }
        return array_values( array_unique( $clean ) );
    }

} // end class OY_Location_GMB_Posts_Metabox
