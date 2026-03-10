<?php
/**
 * GMB Posts Metabox for oy_location CPT
 *
 * Muestra las publicaciones (localPosts) de Google My Business vinculadas a la ubicación,
 * con filtros, vista de detalle y formulario para crear nuevas publicaciones en el futuro.
 *
 * Archivo: includes/cpts/metaboxes/class-oy-location-gmb-posts-metabox.php
 *
 * @package Lealez
 * @subpackage CPTs/Metaboxes
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class OY_Location_GMB_Posts_Metabox
 *
 * Gestiona el metabox de Publicaciones (Posts) de Google My Business
 * en el CPT oy_location.
 */
class OY_Location_GMB_Posts_Metabox {

    /**
     * Nombre del nonce para AJAX
     */
    const NONCE_KEY = 'oy_gmb_posts_nonce';

    /**
     * Constructor – registra hooks
     */
    public function __construct() {
        add_action( 'add_meta_boxes',       array( $this, 'register_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX: traer publicaciones desde GMB
        add_action( 'wp_ajax_oy_gmb_posts_fetch',  array( $this, 'ajax_fetch_posts' ) );

        // AJAX: crear publicación en GMB
        add_action( 'wp_ajax_oy_gmb_posts_create', array( $this, 'ajax_create_post' ) );

        // AJAX: eliminar publicación en GMB
        add_action( 'wp_ajax_oy_gmb_posts_delete', array( $this, 'ajax_delete_post' ) );
    }

    // =========================================================================
    // REGISTRO DEL METABOX
    // =========================================================================

    /**
     * Registra el metabox en el CPT oy_location
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

    // =========================================================================
    // ASSETS
    // =========================================================================

    /**
     * Encola CSS y JS solo en la pantalla de edición de oy_location
     *
     * @param string $hook Hook de la página actual de admin
     */
    public function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'oy_location' !== $screen->post_type ) {
            return;
        }

        // ── Inline CSS ─────────────────────────────────────────────────────
        $css = '
        /* ============================================================
           GMB Posts Metabox — Lealez
        ============================================================ */
        #oy_location_gmb_posts .inside { padding: 0; }

        .oy-posts-metabox-wrap {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        /* Tabs */
        .oy-posts-tabs {
            display: flex;
            gap: 0;
            border-bottom: 2px solid #e0e0e0;
            background: #f8f9fa;
            padding: 0 16px;
        }
        .oy-posts-tab-btn {
            padding: 12px 20px;
            cursor: pointer;
            border: none;
            background: transparent;
            font-size: 13px;
            font-weight: 600;
            color: #666;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: all .2s;
        }
        .oy-posts-tab-btn.active {
            color: #1a73e8;
            border-bottom-color: #1a73e8;
        }
        .oy-posts-tab-btn:hover:not(.active) { color: #333; }
        .oy-posts-tab-pane { display: none; padding: 16px; }
        .oy-posts-tab-pane.active { display: block; }

        /* Toolbar */
        .oy-posts-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 16px;
        }
        .oy-posts-toolbar select,
        .oy-posts-toolbar .button { height: 32px; line-height: 30px; }
        .oy-posts-count-badge {
            margin-left: auto;
            background: #e8f0fe;
            color: #1a73e8;
            font-size: 12px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
        }

        /* Status / type notice when no GMB connection */
        .oy-posts-notice {
            padding: 12px 16px;
            border-left: 4px solid #ccc;
            background: #f9f9f9;
            font-size: 13px;
            color: #555;
            border-radius: 0 4px 4px 0;
        }
        .oy-posts-notice.warning { border-left-color: #f0b429; background: #fffbee; }
        .oy-posts-notice.error   { border-left-color: #d63638; background: #fdf2f2; }
        .oy-posts-notice.info    { border-left-color: #1a73e8; background: #eef3fd; }

        /* Loading */
        .oy-posts-loading {
            text-align: center;
            padding: 40px 20px;
            color: #888;
            font-size: 13px;
        }
        .oy-posts-loading .spinner { float: none; margin: 0 auto 10px; display: block; visibility: visible; }

        /* Grid */
        .oy-posts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }

        /* Card */
        .oy-post-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
            transition: box-shadow .2s;
            display: flex;
            flex-direction: column;
        }
        .oy-post-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.12); }

        .oy-post-card-image {
            width: 100%;
            height: 160px;
            object-fit: cover;
            display: block;
            background: #f0f0f0;
        }
        .oy-post-card-image-placeholder {
            width: 100%;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f0f4ff 0%, #e8f0fe 100%);
            color: #aaa;
            font-size: 32px;
        }

        .oy-post-card-body {
            padding: 12px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .oy-post-card-badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .oy-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .3px;
        }
        .oy-badge-type-STANDARD { background: #e8f0fe; color: #1a73e8; }
        .oy-badge-type-OFFER    { background: #e6f4ea; color: #1e8e3e; }
        .oy-badge-type-EVENT    { background: #fce8b2; color: #f9ab00; }
        .oy-badge-type-PRODUCT  { background: #f3e8fd; color: #7b1fa2; }
        .oy-badge-type-ALERT    { background: #fce8e6; color: #d93025; }
        .oy-badge-state-LIVE       { background: #e6f4ea; color: #1e8e3e; }
        .oy-badge-state-PROCESSING { background: #fff3e0; color: #e65100; }
        .oy-badge-state-REJECTED   { background: #fce8e6; color: #d93025; }
        .oy-badge-state-DELETED    { background: #f1f3f4; color: #80868b; }
        .oy-badge-state-UNKNOWN    { background: #f1f3f4; color: #80868b; }

        .oy-post-card-summary {
            font-size: 13px;
            line-height: 1.5;
            color: #333;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
        }

        .oy-post-card-meta {
            font-size: 11px;
            color: #888;
            margin-top: auto;
            padding-top: 8px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
        }
        .oy-post-card-cta {
            font-size: 11px;
            color: #1a73e8;
            font-weight: 600;
        }

        .oy-post-card-actions {
            padding: 8px 12px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            gap: 6px;
            justify-content: flex-end;
        }
        .oy-post-card-actions .button-link-delete { color: #d63638; }
        .oy-post-card-actions .button-link-delete:hover { color: #b12121; }

        /* Event / Offer detail row */
        .oy-post-card-extra {
            font-size: 11px;
            color: #555;
            background: #f8f9fa;
            padding: 6px 8px;
            border-radius: 4px;
            margin-top: 4px;
        }
        .oy-post-card-extra strong { color: #333; }

        /* Empty state */
        .oy-posts-empty {
            text-align: center;
            padding: 40px 20px;
            color: #888;
        }
        .oy-posts-empty .dashicons { font-size: 40px; width: 40px; height: 40px; color: #ccc; margin: 0 auto 10px; display: block; }

        /* ── Formulario nueva publicación ───────────────────────────── */
        .oy-new-post-form { max-width: 700px; }

        .oy-new-post-form .oy-form-row {
            margin-bottom: 16px;
        }
        .oy-new-post-form label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            color: #333;
            margin-bottom: 5px;
        }
        .oy-new-post-form label span.required { color: #d63638; }
        .oy-new-post-form input[type="text"],
        .oy-new-post-form input[type="url"],
        .oy-new-post-form input[type="date"],
        .oy-new-post-form select,
        .oy-new-post-form textarea {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 8px 10px;
            font-size: 13px;
            color: #333;
        }
        .oy-new-post-form textarea { resize: vertical; min-height: 100px; }
        .oy-new-post-form .oy-char-counter {
            text-align: right;
            font-size: 11px;
            color: #888;
            margin-top: 3px;
        }
        .oy-new-post-form .oy-char-counter.over { color: #d63638; font-weight: 700; }

        .oy-new-post-form .oy-form-cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .oy-topic-type-selector {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .oy-topic-type-option {
            flex: 1;
            min-width: 100px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            transition: all .2s;
            background: #fff;
        }
        .oy-topic-type-option:hover { border-color: #1a73e8; background: #f0f5ff; }
        .oy-topic-type-option.selected { border-color: #1a73e8; background: #e8f0fe; }
        .oy-topic-type-option .oy-topic-icon { font-size: 24px; display: block; margin-bottom: 4px; }
        .oy-topic-type-option .oy-topic-label { font-size: 12px; font-weight: 600; color: #333; }

        .oy-conditional-fields { display: none; }
        .oy-conditional-fields.visible { display: block; }

        .oy-new-post-notice {
            padding: 10px 14px;
            border-radius: 4px;
            font-size: 13px;
            margin-top: 12px;
            display: none;
        }
        .oy-new-post-notice.success { background: #e6f4ea; color: #1e8e3e; border: 1px solid #ceead6; display: block; }
        .oy-new-post-notice.error   { background: #fce8e6; color: #d93025; border: 1px solid #f5c6c6; display: block; }
        .oy-new-post-notice.info    { background: #eef3fd; color: #1a73e8; border: 1px solid #c5d8fa; display: block; }

        .oy-form-actions { display: flex; gap: 10px; align-items: center; }
        ';

        wp_add_inline_style( 'wp-admin', $css );
    }

    // =========================================================================
    // RENDER METABOX
    // =========================================================================

    /**
     * Renderiza el contenido del metabox
     *
     * @param WP_Post $post Post actual
     */
    public function render_meta_box( $post ) {
        $location_id    = $post->ID;
        $gmb_loc_name   = (string) get_post_meta( $location_id, 'gmb_location_name', true );
        $business_id    = (int)    get_post_meta( $location_id, 'parent_business_id', true );
        $gmb_connected  = $business_id ? (bool) get_post_meta( $business_id, '_gmb_connected', true ) : false;
        $nonce          = wp_create_nonce( self::NONCE_KEY );

        // Caché persistente de publicaciones
        $preloaded_posts   = get_post_meta( $location_id, '_gmb_posts_cache', true );
        $posts_last_sync   = (int) get_post_meta( $location_id, '_gmb_posts_last_sync', true );
        if ( ! is_array( $preloaded_posts ) ) {
            $preloaded_posts = array();
        }
        $has_posts_cache = ! empty( $preloaded_posts );

        ?>
        <div class="oy-posts-metabox-wrap"
             data-location-id="<?php echo esc_attr( $location_id ); ?>"
             data-business-id="<?php echo esc_attr( $business_id ); ?>"
             data-gmb-location-name="<?php echo esc_attr( $gmb_loc_name ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>">

            <?php if ( ! $business_id || ! $gmb_connected || empty( $gmb_loc_name ) ) : ?>
                <?php $this->render_no_connection_notice( $business_id, $gmb_connected, $gmb_loc_name ); ?>
            <?php else : ?>

            <!-- TABS -->
            <div class="oy-posts-tabs">
                <button type="button" class="oy-posts-tab-btn active" data-tab="list">
                    <?php esc_html_e( '📋 Publicaciones', 'lealez' ); ?>
                </button>
                <button type="button" class="oy-posts-tab-btn" data-tab="create">
                    <?php esc_html_e( '✏️ Nueva Publicación', 'lealez' ); ?>
                </button>
            </div>

            <!-- TAB: Lista de publicaciones -->
            <div class="oy-posts-tab-pane active" id="oy-posts-tab-list">

                <!-- Toolbar -->
                <div class="oy-posts-toolbar">
                    <label for="oy-posts-filter-type" class="screen-reader-text"><?php esc_html_e( 'Filtrar por tipo', 'lealez' ); ?></label>
                    <select id="oy-posts-filter-type">
                        <option value=""><?php esc_html_e( 'Todos los tipos', 'lealez' ); ?></option>
                        <option value="STANDARD"><?php esc_html_e( 'Actualizar', 'lealez' ); ?></option>
                        <option value="OFFER"><?php esc_html_e( 'Oferta', 'lealez' ); ?></option>
                        <option value="EVENT"><?php esc_html_e( 'Evento', 'lealez' ); ?></option>
                        <option value="PRODUCT"><?php esc_html_e( 'Producto', 'lealez' ); ?></option>
                        <option value="ALERT"><?php esc_html_e( 'Alerta', 'lealez' ); ?></option>
                    </select>

                    <label for="oy-posts-filter-state" class="screen-reader-text"><?php esc_html_e( 'Filtrar por estado', 'lealez' ); ?></label>
                    <select id="oy-posts-filter-state">
                        <option value=""><?php esc_html_e( 'Todos los estados', 'lealez' ); ?></option>
                        <option value="LIVE"><?php esc_html_e( 'Publicada', 'lealez' ); ?></option>
                        <option value="PROCESSING"><?php esc_html_e( 'Procesando', 'lealez' ); ?></option>
                        <option value="REJECTED"><?php esc_html_e( 'Rechazada', 'lealez' ); ?></option>
                    </select>

                    <button type="button" class="button" id="oy-posts-btn-refresh" title="<?php esc_attr_e( 'Recargar desde GMB', 'lealez' ); ?>">
                        🔄 <?php esc_html_e( 'Actualizar', 'lealez' ); ?>
                    </button>

                    <span class="oy-posts-count-badge" id="oy-posts-count" style="display:none;"></span>
                </div>

                <!-- Contenedor de publicaciones -->\
                <div id="oy-posts-list-container">
                    <?php if ( $has_posts_cache ) : ?>
                    <div class="oy-posts-loading" style="display:none;">
                        <span class="spinner is-active"></span>
                        <?php esc_html_e( 'Cargando publicaciones desde Google My Business…', 'lealez' ); ?>
                    </div>
                    <?php else : ?>
                    <div class="oy-posts-loading">
                        <span class="spinner is-active"></span>
                        <?php esc_html_e( 'Cargando publicaciones desde Google My Business…', 'lealez' ); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ( $posts_last_sync ) : ?>
                <p style="font-size:11px;color:#888;margin:4px 0 0;padding:0 0 8px;">
                    <?php printf(
                        esc_html__( 'Última sincronización: %s', 'lealez' ),
                        esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $posts_last_sync ) )
                    ); ?>
                </p>
                <?php endif; ?>
            </div><!-- /tab list -->

            <!-- TAB: Nueva publicación -->
            <div class="oy-posts-tab-pane" id="oy-posts-tab-create">

                <div class="oy-posts-notice info" style="margin-bottom:16px;">
                    <?php esc_html_e( 'Completa el formulario para crear una nueva publicación directamente en tu perfil de Google My Business.', 'lealez' ); ?>
                </div>

                <form class="oy-new-post-form" id="oy-new-post-form" autocomplete="off">

                    <!-- Tipo de publicación -->
                    <div class="oy-form-row">
                        <label><?php esc_html_e( 'Tipo de publicación', 'lealez' ); ?> <span class="required">*</span></label>
                        <div class="oy-topic-type-selector">
                            <div class="oy-topic-type-option selected" data-type="STANDARD" tabindex="0" role="button" aria-pressed="true">
                                <span class="oy-topic-icon">📢</span>
                                <span class="oy-topic-label"><?php esc_html_e( 'Actualizar', 'lealez' ); ?></span>
                            </div>
                            <div class="oy-topic-type-option" data-type="OFFER" tabindex="0" role="button" aria-pressed="false">
                                <span class="oy-topic-icon">🏷️</span>
                                <span class="oy-topic-label"><?php esc_html_e( 'Oferta', 'lealez' ); ?></span>
                            </div>
                            <div class="oy-topic-type-option" data-type="EVENT" tabindex="0" role="button" aria-pressed="false">
                                <span class="oy-topic-icon">📅</span>
                                <span class="oy-topic-label"><?php esc_html_e( 'Evento', 'lealez' ); ?></span>
                            </div>
                        </div>
                        <input type="hidden" id="oy-new-post-topic-type" name="topic_type" value="STANDARD">
                    </div>

                    <!-- Descripción -->
                    <div class="oy-form-row">
                        <label for="oy-new-post-summary">
                            <?php esc_html_e( 'Descripción', 'lealez' ); ?> <span class="required">*</span>
                        </label>
                        <textarea id="oy-new-post-summary" name="summary" maxlength="1500"
                                  placeholder="<?php esc_attr_e( 'Escribe el texto de tu publicación (máx. 1,500 caracteres)…', 'lealez' ); ?>"></textarea>
                        <div class="oy-char-counter"><span id="oy-summary-chars">0</span> / 1500</div>
                    </div>

                    <!-- URL de imagen -->
                    <div class="oy-form-row">
                        <label for="oy-new-post-image-url"><?php esc_html_e( 'Imagen (URL pública)', 'lealez' ); ?></label>
                        <input type="url" id="oy-new-post-image-url" name="image_url"
                               placeholder="https://ejemplo.com/imagen.jpg">
                        <p class="description" style="margin-top:4px;font-size:11px;color:#888;">
                            <?php esc_html_e( 'Ingresa la URL pública de la imagen. Tamaño recomendado: 400×300 px o mayor.', 'lealez' ); ?>
                        </p>
                    </div>

                    <!-- CTA Button -->
                    <div class="oy-form-row">
                        <label for="oy-new-post-cta-type"><?php esc_html_e( 'Botón de acción (CTA)', 'lealez' ); ?></label>
                        <div class="oy-form-cols">
                            <div>
                                <select id="oy-new-post-cta-type" name="cta_action_type">
                                    <option value=""><?php esc_html_e( '— Sin botón —', 'lealez' ); ?></option>
                                    <option value="LEARN_MORE"><?php esc_html_e( 'Más información', 'lealez' ); ?></option>
                                    <option value="BOOK"><?php esc_html_e( 'Reservar', 'lealez' ); ?></option>
                                    <option value="ORDER"><?php esc_html_e( 'Pedir', 'lealez' ); ?></option>
                                    <option value="SHOP"><?php esc_html_e( 'Comprar', 'lealez' ); ?></option>
                                    <option value="SIGN_UP"><?php esc_html_e( 'Registrarse', 'lealez' ); ?></option>
                                    <option value="CALL"><?php esc_html_e( 'Llamar ahora', 'lealez' ); ?></option>
                                </select>
                            </div>
                            <div id="oy-cta-url-wrap">
                                <input type="url" id="oy-new-post-cta-url" name="cta_url"
                                       placeholder="https://ejemplo.com/pagina">
                            </div>
                        </div>
                    </div>

                    <!-- Campos condicionales: EVENTO -->
                    <div class="oy-conditional-fields" id="oy-event-fields">
                        <div class="oy-form-row">
                            <label for="oy-new-post-event-title">
                                <?php esc_html_e( 'Título del evento', 'lealez' ); ?> <span class="required">*</span>
                            </label>
                            <input type="text" id="oy-new-post-event-title" name="event_title"
                                   placeholder="<?php esc_attr_e( 'Nombre del evento…', 'lealez' ); ?>">
                        </div>
                        <div class="oy-form-row">
                            <label><?php esc_html_e( 'Fecha y hora del evento', 'lealez' ); ?></label>
                            <div class="oy-form-cols">
                                <div>
                                    <label for="oy-new-post-event-start-date" style="font-weight:normal;font-size:12px;color:#666;">
                                        <?php esc_html_e( 'Inicio', 'lealez' ); ?>
                                    </label>
                                    <input type="date" id="oy-new-post-event-start-date" name="event_start_date">
                                </div>
                                <div>
                                    <label for="oy-new-post-event-end-date" style="font-weight:normal;font-size:12px;color:#666;">
                                        <?php esc_html_e( 'Fin', 'lealez' ); ?>
                                    </label>
                                    <input type="date" id="oy-new-post-event-end-date" name="event_end_date">
                                </div>
                            </div>
                        </div>
                    </div><!-- /event fields -->

                    <!-- Campos condicionales: OFERTA -->
                    <div class="oy-conditional-fields" id="oy-offer-fields">
                        <div class="oy-form-row">
                            <label for="oy-new-post-coupon"><?php esc_html_e( 'Código de cupón', 'lealez' ); ?></label>
                            <input type="text" id="oy-new-post-coupon" name="coupon_code"
                                   placeholder="PROMO20">
                        </div>
                        <div class="oy-form-row">
                            <label for="oy-new-post-redeem-url"><?php esc_html_e( 'URL para canjear online', 'lealez' ); ?></label>
                            <input type="url" id="oy-new-post-redeem-url" name="redeem_url"
                                   placeholder="https://ejemplo.com/oferta">
                        </div>
                        <div class="oy-form-row">
                            <label for="oy-new-post-offer-terms"><?php esc_html_e( 'Términos y condiciones', 'lealez' ); ?></label>
                            <textarea id="oy-new-post-offer-terms" name="offer_terms" style="min-height:60px;"
                                      placeholder="<?php esc_attr_e( 'Aplican restricciones…', 'lealez' ); ?>"></textarea>
                        </div>
                    </div><!-- /offer fields -->

                    <!-- Aviso de resultado -->
                    <div class="oy-new-post-notice" id="oy-new-post-result"></div>

                    <!-- Botones -->
                    <div class="oy-form-actions">
                        <button type="submit" class="button button-primary" id="oy-new-post-submit">
                            🚀 <?php esc_html_e( 'Publicar en Google', 'lealez' ); ?>
                        </button>
                        <button type="reset" class="button" id="oy-new-post-reset">
                            <?php esc_html_e( 'Limpiar', 'lealez' ); ?>
                        </button>
                        <span class="spinner" id="oy-new-post-spinner"></span>
                    </div>

                </form>
            </div><!-- /tab create -->

            <?php endif; // GMB connected ?>
        </div><!-- /.oy-posts-metabox-wrap -->

        <?php $this->render_inline_script( $location_id, $business_id, $gmb_loc_name, $nonce, $preloaded_posts ); ?>
        <?php
    }

    // =========================================================================
    // HELPERS DE RENDER
    // =========================================================================

    /**
     * Renderiza aviso cuando no hay conexión GMB
     *
     * @param int    $business_id
     * @param bool   $gmb_connected
     * @param string $gmb_loc_name
     */
    private function render_no_connection_notice( $business_id, $gmb_connected, $gmb_loc_name ) {
        if ( ! $business_id ) {
            echo '<div class="oy-posts-notice warning" style="margin:16px;">';
            esc_html_e( '⚠️ Asigna una Empresa (oy_business) a esta ubicación para habilitar la integración con Google My Business.', 'lealez' );
            echo '</div>';
        } elseif ( ! $gmb_connected ) {
            echo '<div class="oy-posts-notice warning" style="margin:16px;">';
            printf(
                /* translators: %s: edit business link */
                esc_html__( '⚠️ La empresa no está conectada a Google My Business. %s para conectar.', 'lealez' ),
                '<a href="' . esc_url( get_edit_post_link( $business_id ) ) . '">' . esc_html__( 'Editar empresa', 'lealez' ) . '</a>'
            );
            echo '</div>';
        } elseif ( empty( $gmb_loc_name ) ) {
            echo '<div class="oy-posts-notice info" style="margin:16px;">';
            esc_html_e( 'ℹ️ Esta ubicación aún no tiene vinculado un perfil de Google My Business. Guarda el campo "Nombre GMB" (gmb_location_name) desde el metabox de Google My Business.', 'lealez' );
            echo '</div>';
        }
    }

    /**
     * Renderiza el JS inline del metabox
     *
     * @param int    $location_id
     * @param int    $business_id
     * @param string $gmb_loc_name
     * @param string $nonce
     */
    private function render_inline_script( $location_id, $business_id, $gmb_loc_name, $nonce, $preloaded_posts = array() ) {
        if ( ! $business_id || empty( $gmb_loc_name ) ) {
            return;
        }
        $preloaded_json = ! empty( $preloaded_posts ) && is_array( $preloaded_posts )
            ? wp_json_encode( $preloaded_posts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            : 'null';
        ?>
        <script type="text/javascript">
        (function($){
            'use strict';

            /* ── Config ────────────────────────────────────────────────── */
            var AJAXURL     = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var NONCE       = <?php echo wp_json_encode( $nonce ); ?>;
            var LOCATION_ID = <?php echo (int) $location_id; ?>;
            var BUSINESS_ID = <?php echo (int) $business_id; ?>;
            var GMB_LOC     = <?php echo wp_json_encode( $gmb_loc_name ); ?>;

            /* Publicaciones precargadas desde post_meta (pueden ser null si no hay caché) */
            var PRELOADED_POSTS = <?php echo $preloaded_json; ?>;

            /* CTA label mapping */
            var CTA_LABELS = {
                LEARN_MORE : '<?php echo esc_js( __( 'Más información', 'lealez' ) ); ?>',
                BOOK       : '<?php echo esc_js( __( 'Reservar', 'lealez' ) ); ?>',
                ORDER      : '<?php echo esc_js( __( 'Pedir', 'lealez' ) ); ?>',
                SHOP       : '<?php echo esc_js( __( 'Comprar', 'lealez' ) ); ?>',
                SIGN_UP    : '<?php echo esc_js( __( 'Registrarse', 'lealez' ) ); ?>',
                CALL       : '<?php echo esc_js( __( 'Llamar ahora', 'lealez' ) ); ?>'
            };

            /* Type label mapping */
            var TYPE_LABELS = {
                STANDARD : '<?php echo esc_js( __( 'Actualizar', 'lealez' ) ); ?>',
                OFFER    : '<?php echo esc_js( __( 'Oferta', 'lealez' ) ); ?>',
                EVENT    : '<?php echo esc_js( __( 'Evento', 'lealez' ) ); ?>',
                PRODUCT  : '<?php echo esc_js( __( 'Producto', 'lealez' ) ); ?>',
                ALERT    : '<?php echo esc_js( __( 'Alerta', 'lealez' ) ); ?>'
            };

            /* State label mapping */
            var STATE_LABELS = {
                LIVE       : '<?php echo esc_js( __( 'Publicada', 'lealez' ) ); ?>',
                PROCESSING : '<?php echo esc_js( __( 'Procesando', 'lealez' ) ); ?>',
                REJECTED   : '<?php echo esc_js( __( 'Rechazada', 'lealez' ) ); ?>',
                DELETED    : '<?php echo esc_js( __( 'Eliminada', 'lealez' ) ); ?>',
                UNKNOWN    : '<?php echo esc_js( __( 'Desconocido', 'lealez' ) ); ?>'
            };

            /* All loaded posts (for client-side filter) */
            var allPosts = [];

            /* ── Utility ────────────────────────────────────────────── */
            function formatDate( isoStr ) {
                if ( ! isoStr ) return '—';
                var d = new Date( isoStr );
                if ( isNaN( d.getTime() ) ) return isoStr;
                return d.toLocaleDateString( 'es-CO', { year:'numeric', month:'short', day:'numeric' } );
            }

            function extractPostId( name ) {
                /* name = "accounts/X/locations/Y/localPosts/Z" */
                var parts = ( name || '' ).split( '/' );
                return parts[ parts.length - 1 ] || '';
            }

            function escHtml( str ) {
                return $('<div>').text( str || '' ).html();
            }

            /* ── Tab switching ─────────────────────────────────────── */
            $( document ).on( 'click', '.oy-posts-tab-btn', function() {
                var tab = $( this ).data( 'tab' );
                $( '.oy-posts-tab-btn' ).removeClass( 'active' );
                $( this ).addClass( 'active' );
                $( '.oy-posts-tab-pane' ).removeClass( 'active' );
                $( '#oy-posts-tab-' + tab ).addClass( 'active' );
            });

            /* ── Fetch posts ───────────────────────────────────────── */
            function fetchPosts( forceRefresh ) {
                $( '#oy-posts-list-container' ).html(
                    '<div class="oy-posts-loading"><span class="spinner is-active"></span> <?php echo esc_js( __( 'Cargando publicaciones…', 'lealez' ) ); ?></div>'
                );
                $( '#oy-posts-count' ).hide();

                $.post( AJAXURL, {
                    action        : 'oy_gmb_posts_fetch',
                    nonce         : NONCE,
                    location_id   : LOCATION_ID,
                    business_id   : BUSINESS_ID,
                    gmb_loc_name  : GMB_LOC,
                    force_refresh : forceRefresh ? 1 : 0
                }, function( resp ) {
                    if ( resp && resp.success ) {
                        allPosts = resp.data.posts || [];
                        renderPostsList( allPosts );
                    } else {
                        var msg = ( resp && resp.data && resp.data.message ) ? resp.data.message
                                  : '<?php echo esc_js( __( 'Error al cargar publicaciones.', 'lealez' ) ); ?>';
                        $( '#oy-posts-list-container' ).html(
                            '<div class="oy-posts-notice error">' + escHtml( msg ) + '</div>'
                        );
                    }
                } ).fail( function() {
                    $( '#oy-posts-list-container' ).html(
                        '<div class="oy-posts-notice error"><?php echo esc_js( __( 'Error de conexión al servidor.', 'lealez' ) ); ?></div>'
                    );
                });
            }

            /* ── Render post list ──────────────────────────────────── */
            function renderPostsList( posts ) {
                var typeFilter  = $( '#oy-posts-filter-type' ).val();
                var stateFilter = $( '#oy-posts-filter-state' ).val();

                var filtered = posts.filter( function( p ) {
                    var type  = p.topicType || 'STANDARD';
                    var state = p.state     || 'UNKNOWN';
                    if ( typeFilter  && type  !== typeFilter  ) return false;
                    if ( stateFilter && state !== stateFilter ) return false;
                    return true;
                });

                /* Count badge */
                $( '#oy-posts-count' )
                    .text( filtered.length + ' / ' + posts.length + ' <?php echo esc_js( __( 'publicaciones', 'lealez' ) ); ?>' )
                    .show();

                if ( ! filtered.length ) {
                    $( '#oy-posts-list-container' ).html(
                        '<div class="oy-posts-empty">' +
                        '<span class="dashicons dashicons-megaphone"></span>' +
                        '<p><?php echo esc_js( __( 'No se encontraron publicaciones con los filtros seleccionados.', 'lealez' ) ); ?></p>' +
                        '</div>'
                    );
                    return;
                }

                var html = '<div class="oy-posts-grid">';
                $.each( filtered, function( i, post ) {
                    html += buildPostCard( post );
                });
                html += '</div>';

                $( '#oy-posts-list-container' ).html( html );
            }

            /* ── Build a single post card ──────────────────────────── */
            function buildPostCard( post ) {
                var name      = post.name     || '';
                var postId    = extractPostId( name );
                var type      = post.topicType || 'STANDARD';
                var state     = post.state     || 'UNKNOWN';
                var summary   = post.summary   || '';
                var created   = post.createTime || '';
                var updated   = post.updateTime || '';
                var cta       = post.callToAction || {};
                var media     = ( post.media && post.media.length ) ? post.media[0] : null;
                var event     = post.event  || null;
                var offer     = post.offer  || null;
                var searchUrl = post.searchUrl || '';

                var typeLabel  = TYPE_LABELS[ type ]  || type;
                var stateLabel = STATE_LABELS[ state ] || state;

                /* Image */
                var imgHtml;
                if ( media && media.googleUrl ) {
                    imgHtml = '<img class="oy-post-card-image" src="' + escHtml( media.googleUrl ) + '" alt="" loading="lazy">';
                } else {
                    var emoji = { STANDARD:'📢', OFFER:'🏷️', EVENT:'📅', PRODUCT:'📦', ALERT:'🔔' };
                    imgHtml = '<div class="oy-post-card-image-placeholder">' + ( emoji[ type ] || '📢' ) + '</div>';
                }

                /* CTA text */
                var ctaHtml = '';
                if ( cta.actionType && cta.actionType !== 'ACTION_TYPE_UNSPECIFIED' ) {
                    var ctaLabel = CTA_LABELS[ cta.actionType ] || cta.actionType;
                    ctaHtml = '<span class="oy-post-card-cta">🔗 ' + escHtml( ctaLabel ) + '</span>';
                }

                /* Event detail */
                var extraHtml = '';
                if ( event ) {
                    var evTitle = event.title || '';
                    var sched   = event.schedule || {};
                    var start   = sched.startDate ? ( sched.startDate.year + '-' + ( '0' + sched.startDate.month ).slice(-2) + '-' + ( '0' + sched.startDate.day ).slice(-2) ) : '';
                    var end     = sched.endDate   ? ( sched.endDate.year   + '-' + ( '0' + sched.endDate.month   ).slice(-2) + '-' + ( '0' + sched.endDate.day   ).slice(-2) ) : '';
                    extraHtml = '<div class="oy-post-card-extra">' +
                        '<strong>📅 <?php echo esc_js( __( 'Evento', 'lealez' ) ); ?>:</strong> ' + escHtml( evTitle ) +
                        ( start ? ' · ' + formatDate( start ) : '' ) +
                        ( end && end !== start ? ' → ' + formatDate( end ) : '' ) +
                        '</div>';
                }
                if ( offer ) {
                    var coupon = offer.couponCode || '';
                    extraHtml = '<div class="oy-post-card-extra">' +
                        '<strong>🏷️ <?php echo esc_js( __( 'Oferta', 'lealez' ) ); ?>:</strong>' +
                        ( coupon ? ' <?php echo esc_js( __( 'Cupón:', 'lealez' ) ); ?> <code>' + escHtml( coupon ) + '</code>' : '' ) +
                        '</div>';
                }

                /* Delete button (solo si no está en Processing) */
                var deleteBtn = '';
                if ( postId && state !== 'PROCESSING' ) {
                    deleteBtn = '<button type="button" class="button-link button-link-delete oy-post-delete-btn"' +
                        ' data-post-name="' + escHtml( name ) + '"' +
                        ' data-post-id="' + escHtml( postId ) + '"' +
                        ' title="<?php echo esc_js( __( 'Eliminar publicación', 'lealez' ) ); ?>">' +
                        '🗑️ <?php echo esc_js( __( 'Eliminar', 'lealez' ) ); ?></button>';
                }

                /* View on GMB link */
                var viewBtn = searchUrl
                    ? '<a href="' + escHtml( searchUrl ) + '" target="_blank" class="button button-small">' +
                      '🔗 <?php echo esc_js( __( 'Ver', 'lealez' ) ); ?></a>'
                    : '';

                return '<div class="oy-post-card" data-post-name="' + escHtml( name ) + '">' +
                    imgHtml +
                    '<div class="oy-post-card-body">' +
                        '<div class="oy-post-card-badges">' +
                            '<span class="oy-badge oy-badge-type-' + escHtml( type ) + '">' + escHtml( typeLabel ) + '</span>' +
                            '<span class="oy-badge oy-badge-state-' + escHtml( state ) + '">' + escHtml( stateLabel ) + '</span>' +
                        '</div>' +
                        ( summary ? '<p class="oy-post-card-summary">' + escHtml( summary ) + '</p>' : '' ) +
                        extraHtml +
                        '<div class="oy-post-card-meta">' +
                            '<span>🕐 ' + formatDate( updated || created ) + '</span>' +
                            ctaHtml +
                        '</div>' +
                    '</div>' +
                    '<div class="oy-post-card-actions">' +
                        viewBtn + ' ' + deleteBtn +
                    '</div>' +
                '</div>';
            }

            /* ── Filter change ─────────────────────────────────────── */
            $( document ).on( 'change', '#oy-posts-filter-type, #oy-posts-filter-state', function() {
                if ( allPosts.length ) {
                    renderPostsList( allPosts );
                }
            });

            /* ── Refresh button ────────────────────────────────────── */
            $( document ).on( 'click', '#oy-posts-btn-refresh', function() {
                fetchPosts( true );
            });

            /* ── Delete post ───────────────────────────────────────── */
            $( document ).on( 'click', '.oy-post-delete-btn', function() {
                var $btn     = $( this );
                var postName = $btn.data( 'post-name' );
                var confirm  = window.confirm( '<?php echo esc_js( __( '¿Estás seguro que deseas eliminar esta publicación de Google My Business? Esta acción no se puede deshacer.', 'lealez' ) ); ?>' );
                if ( ! confirm ) return;

                $btn.prop( 'disabled', true ).text( '⏳' );

                $.post( AJAXURL, {
                    action      : 'oy_gmb_posts_delete',
                    nonce       : NONCE,
                    location_id : LOCATION_ID,
                    business_id : BUSINESS_ID,
                    gmb_loc_name: GMB_LOC,
                    post_name   : postName
                }, function( resp ) {
                    if ( resp && resp.success ) {
                        /* Remove from allPosts and re-render */
                        allPosts = allPosts.filter( function( p ) {
                            return p.name !== postName;
                        });
                        renderPostsList( allPosts );
                    } else {
                        var msg = ( resp && resp.data && resp.data.message ) ? resp.data.message
                                  : '<?php echo esc_js( __( 'Error al eliminar la publicación.', 'lealez' ) ); ?>';
                        alert( msg );
                        $btn.prop( 'disabled', false ).text( '🗑️ <?php echo esc_js( __( 'Eliminar', 'lealez' ) ); ?>' );
                    }
                }).fail( function() {
                    alert( '<?php echo esc_js( __( 'Error de conexión al servidor.', 'lealez' ) ); ?>' );
                    $btn.prop( 'disabled', false ).text( '🗑️ <?php echo esc_js( __( 'Eliminar', 'lealez' ) ); ?>' );
                });
            });

            /* ── Topic type selector ───────────────────────────────── */
            $( document ).on( 'click keypress', '.oy-topic-type-option', function( e ) {
                if ( e.type === 'keypress' && e.which !== 13 && e.which !== 32 ) return;
                var type = $( this ).data( 'type' );
                $( '.oy-topic-type-option' ).removeClass( 'selected' ).attr( 'aria-pressed', 'false' );
                $( this ).addClass( 'selected' ).attr( 'aria-pressed', 'true' );
                $( '#oy-new-post-topic-type' ).val( type );

                /* Show/hide conditional fields */
                $( '.oy-conditional-fields' ).removeClass( 'visible' );
                if ( type === 'EVENT' ) {
                    $( '#oy-event-fields' ).addClass( 'visible' );
                } else if ( type === 'OFFER' ) {
                    $( '#oy-offer-fields' ).addClass( 'visible' );
                }
            });

            /* ── Char counter for summary ──────────────────────────── */
            $( document ).on( 'input', '#oy-new-post-summary', function() {
                var len = $( this ).val().length;
                var $counter = $( '#oy-summary-chars' );
                $counter.text( len );
                $counter.closest( '.oy-char-counter' ).toggleClass( 'over', len > 1500 );
            });

            /* ── CTA type: hide URL field for CALL ─────────────────── */
            $( document ).on( 'change', '#oy-new-post-cta-type', function() {
                var val = $( this ).val();
                $( '#oy-cta-url-wrap' ).toggle( val !== '' && val !== 'CALL' );
            });

            /* ── Form submit ───────────────────────────────────────── */
            $( '#oy-new-post-form' ).on( 'submit', function( e ) {
                e.preventDefault();

                var $form    = $( this );
                var $submit  = $( '#oy-new-post-submit' );
                var $spinner = $( '#oy-new-post-spinner' );
                var $result  = $( '#oy-new-post-result' );

                $result.removeClass( 'success error info' ).hide().text( '' );

                /* Validate */
                var summary = $.trim( $( '#oy-new-post-summary' ).val() );
                if ( ! summary ) {
                    $result.addClass( 'error' )
                        .text( '<?php echo esc_js( __( 'La descripción es obligatoria.', 'lealez' ) ); ?>' );
                    return;
                }
                if ( summary.length > 1500 ) {
                    $result.addClass( 'error' )
                        .text( '<?php echo esc_js( __( 'La descripción supera el límite de 1,500 caracteres.', 'lealez' ) ); ?>' );
                    return;
                }

                var topicType = $( '#oy-new-post-topic-type' ).val();
                if ( topicType === 'EVENT' && ! $.trim( $( '#oy-new-post-event-title' ).val() ) ) {
                    $result.addClass( 'error' )
                        .text( '<?php echo esc_js( __( 'El título del evento es obligatorio.', 'lealez' ) ); ?>' );
                    return;
                }

                $submit.prop( 'disabled', true );
                $spinner.addClass( 'is-active' ).css( 'visibility', 'visible' );

                var postData = {
                    action       : 'oy_gmb_posts_create',
                    nonce        : NONCE,
                    location_id  : LOCATION_ID,
                    business_id  : BUSINESS_ID,
                    gmb_loc_name : GMB_LOC,
                    topic_type   : topicType,
                    summary      : summary,
                    image_url    : $.trim( $( '#oy-new-post-image-url' ).val() ),
                    cta_type     : $( '#oy-new-post-cta-type' ).val(),
                    cta_url      : $.trim( $( '#oy-new-post-cta-url' ).val() ),
                    event_title      : $.trim( $( '#oy-new-post-event-title' ).val() ),
                    event_start_date : $( '#oy-new-post-event-start-date' ).val(),
                    event_end_date   : $( '#oy-new-post-event-end-date' ).val(),
                    coupon_code  : $.trim( $( '#oy-new-post-coupon' ).val() ),
                    redeem_url   : $.trim( $( '#oy-new-post-redeem-url' ).val() ),
                    offer_terms  : $.trim( $( '#oy-new-post-offer-terms' ).val() )
                };

                $.post( AJAXURL, postData, function( resp ) {
                    if ( resp && resp.success ) {
                        $result.addClass( 'success' )
                            .text( resp.data.message || '<?php echo esc_js( __( 'Publicación creada correctamente.', 'lealez' ) ); ?>' );
                        $form[ 0 ].reset();
                        $( '#oy-summary-chars' ).text( '0' );
                        $( '.oy-conditional-fields' ).removeClass( 'visible' );
                        /* Refresh list after a short delay */
                        setTimeout( function() {
                            fetchPosts( true );
                            /* Switch back to list tab */
                            $( '.oy-posts-tab-btn[data-tab="list"]' ).trigger( 'click' );
                        }, 1500 );
                    } else {
                        var msg = ( resp && resp.data && resp.data.message ) ? resp.data.message
                                  : '<?php echo esc_js( __( 'Error al crear la publicación.', 'lealez' ) ); ?>';
                        $result.addClass( 'error' ).text( msg );
                    }
                }).fail( function() {
                    $result.addClass( 'error' ).text( '<?php echo esc_js( __( 'Error de conexión al servidor.', 'lealez' ) ); ?>' );
                }).always( function() {
                    $submit.prop( 'disabled', false );
                    $spinner.removeClass( 'is-active' ).css( 'visibility', 'hidden' );
                });
            });

            /* ── Form reset ────────────────────────────────────────── */
            $( '#oy-new-post-reset' ).on( 'click', function() {
                $( '#oy-new-post-result' ).removeClass( 'success error info' ).hide().text( '' );
                $( '#oy-summary-chars' ).text( '0' );
                $( '.oy-conditional-fields' ).removeClass( 'visible' );
                $( '.oy-topic-type-option' ).removeClass( 'selected' ).attr( 'aria-pressed', 'false' );
                $( '.oy-topic-type-option[data-type="STANDARD"]' ).addClass( 'selected' ).attr( 'aria-pressed', 'true' );
                $( '#oy-new-post-topic-type' ).val( 'STANDARD' );
            });

            /* ── Auto-load on page ready ───────────────────────────── */
            if ( PRELOADED_POSTS && PRELOADED_POSTS.length > 0 ) {
                // Tenemos caché guardado — renderizar sin petición AJAX
                allPosts = PRELOADED_POSTS.slice();
                renderPostsList( allPosts );
            } else {
                // Sin caché — traer desde GMB
                fetchPosts( false );
            }

            /* ── Escuchar evento de sincronización automatizada ────── */
            $( document ).on( 'oy:gmb:posts:refreshed', function() {
                fetchPosts( false );
            });

        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Obtiene la lista de publicaciones desde GMB (localPosts v4)
     */
    public function ajax_fetch_posts() {
        // Verificar nonce
        if ( ! check_ajax_referer( self::NONCE_KEY, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }

        // Verificar capacidades
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
        }

        $location_id   = absint( $_POST['location_id'] ?? 0 );
        $business_id   = absint( $_POST['business_id'] ?? 0 );
        $gmb_loc_name  = sanitize_text_field( $_POST['gmb_loc_name'] ?? '' );
        $force_refresh = ! empty( $_POST['force_refresh'] );

        if ( ! $location_id || ! $business_id || empty( $gmb_loc_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Parámetros incompletos.', 'lealez' ) ) );
        }

        if ( ! class_exists( 'Lealez_GMB_API' ) ) {
            wp_send_json_error( array( 'message' => __( 'API de Google My Business no disponible.', 'lealez' ) ) );
        }

        $result = Lealez_GMB_API::get_location_local_posts( $business_id, $gmb_loc_name, ! $force_refresh );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ) );
        }

        $posts_list = $result['localPosts'] ?? array();

        // Persistir la lista de publicaciones para precarga al reabrir la página
        if ( ! empty( $posts_list ) && is_array( $posts_list ) ) {
            update_post_meta( $location_id, '_gmb_posts_cache', $posts_list );
            update_post_meta( $location_id, '_gmb_posts_last_sync', time() );
        }

        wp_send_json_success( array(
            'posts' => $posts_list,
            'total' => $result['total'] ?? 0,
        ) );
    }

    /**
     * AJAX: Crea una nueva publicación en GMB (localPosts POST v4)
     */
    public function ajax_create_post() {
        // Verificar nonce
        if ( ! check_ajax_referer( self::NONCE_KEY, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
        }

        $location_id  = absint( $_POST['location_id'] ?? 0 );
        $business_id  = absint( $_POST['business_id'] ?? 0 );
        $gmb_loc_name = sanitize_text_field( $_POST['gmb_loc_name'] ?? '' );

        if ( ! $location_id || ! $business_id || empty( $gmb_loc_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Parámetros incompletos.', 'lealez' ) ) );
        }

        if ( ! class_exists( 'Lealez_GMB_API' ) ) {
            wp_send_json_error( array( 'message' => __( 'API de Google My Business no disponible.', 'lealez' ) ) );
        }

        $topic_type = sanitize_text_field( $_POST['topic_type'] ?? 'STANDARD' );
        $summary    = sanitize_textarea_field( $_POST['summary'] ?? '' );

        if ( empty( $summary ) ) {
            wp_send_json_error( array( 'message' => __( 'La descripción es obligatoria.', 'lealez' ) ) );
        }
        if ( mb_strlen( $summary ) > 1500 ) {
            wp_send_json_error( array( 'message' => __( 'La descripción supera el límite de 1,500 caracteres.', 'lealez' ) ) );
        }

        // Build payload
        $payload = array(
            'topicType'    => $topic_type,
            'languageCode' => 'es',
            'summary'      => $summary,
        );

        // CTA
        $cta_type = sanitize_text_field( $_POST['cta_type'] ?? '' );
        $cta_url  = esc_url_raw( $_POST['cta_url']  ?? '' );
        if ( ! empty( $cta_type ) && 'ACTION_TYPE_UNSPECIFIED' !== $cta_type ) {
            $payload['callToAction'] = array( 'actionType' => $cta_type );
            if ( ! empty( $cta_url ) && 'CALL' !== $cta_type ) {
                $payload['callToAction']['url'] = $cta_url;
            }
        }

        // Image
        $image_url = esc_url_raw( $_POST['image_url'] ?? '' );
        if ( ! empty( $image_url ) ) {
            $payload['media'] = array(
                array(
                    'mediaFormat' => 'PHOTO',
                    'sourceUrl'   => $image_url,
                ),
            );
        }

        // Event-specific fields
        if ( 'EVENT' === $topic_type ) {
            $event_title      = sanitize_text_field( $_POST['event_title']      ?? '' );
            $event_start_date = sanitize_text_field( $_POST['event_start_date'] ?? '' );
            $event_end_date   = sanitize_text_field( $_POST['event_end_date']   ?? '' );

            if ( empty( $event_title ) ) {
                wp_send_json_error( array( 'message' => __( 'El título del evento es obligatorio.', 'lealez' ) ) );
            }

            $event_data = array( 'title' => $event_title );

            if ( ! empty( $event_start_date ) || ! empty( $event_end_date ) ) {
                $schedule = array();
                if ( ! empty( $event_start_date ) ) {
                    $parts = explode( '-', $event_start_date );
                    if ( count( $parts ) === 3 ) {
                        $schedule['startDate'] = array(
                            'year'  => (int) $parts[0],
                            'month' => (int) $parts[1],
                            'day'   => (int) $parts[2],
                        );
                    }
                }
                if ( ! empty( $event_end_date ) ) {
                    $parts = explode( '-', $event_end_date );
                    if ( count( $parts ) === 3 ) {
                        $schedule['endDate'] = array(
                            'year'  => (int) $parts[0],
                            'month' => (int) $parts[1],
                            'day'   => (int) $parts[2],
                        );
                    }
                }
                if ( ! empty( $schedule ) ) {
                    $event_data['schedule'] = $schedule;
                }
            }

            $payload['event'] = $event_data;
        }

        // Offer-specific fields
        if ( 'OFFER' === $topic_type ) {
            $offer = array();
            $coupon_code = sanitize_text_field( $_POST['coupon_code'] ?? '' );
            $redeem_url  = esc_url_raw( $_POST['redeem_url']  ?? '' );
            $offer_terms = sanitize_textarea_field( $_POST['offer_terms'] ?? '' );

            if ( ! empty( $coupon_code ) ) { $offer['couponCode']       = $coupon_code; }
            if ( ! empty( $redeem_url )  ) { $offer['redeemOnlineUrl']  = $redeem_url;  }
            if ( ! empty( $offer_terms ) ) { $offer['termsConditions']  = $offer_terms; }

            if ( ! empty( $offer ) ) {
                $payload['offer'] = $offer;
            }
        }

        $result = Lealez_GMB_API::create_location_local_post( $business_id, $gmb_loc_name, $payload );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Publicación creada correctamente en Google My Business.', 'lealez' ),
            'post'    => $result,
        ) );
    }

    /**
     * AJAX: Elimina una publicación de GMB (DELETE localPosts v4)
     */
    public function ajax_delete_post() {
        // Verificar nonce
        if ( ! check_ajax_referer( self::NONCE_KEY, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
        }

        $location_id  = absint( $_POST['location_id'] ?? 0 );
        $business_id  = absint( $_POST['business_id'] ?? 0 );
        $gmb_loc_name = sanitize_text_field( $_POST['gmb_loc_name'] ?? '' );
        $post_name    = sanitize_text_field( $_POST['post_name']    ?? '' );

        if ( ! $location_id || ! $business_id || empty( $gmb_loc_name ) || empty( $post_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Parámetros incompletos.', 'lealez' ) ) );
        }

        if ( ! class_exists( 'Lealez_GMB_API' ) ) {
            wp_send_json_error( array( 'message' => __( 'API de Google My Business no disponible.', 'lealez' ) ) );
        }

        $result = Lealez_GMB_API::delete_location_local_post( $business_id, $gmb_loc_name, $post_name );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Publicación eliminada correctamente.', 'lealez' ),
        ) );
    }

} // end class OY_Location_GMB_Posts_Metabox
