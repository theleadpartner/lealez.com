<?php
/**
 * OY Location Menu Metabox
 *
 * Metabox dedicado a la gestión del Menú de la ubicación.
 * Replica la estructura de Google My Business:
 *   - Menú Completo (secciones + productos)
 *   - Fotos del Menú (PDF o imágenes)
 *   - Platos Destacados
 *
 * Archivo: includes/cpts/metaboxes/class-oy-location-menu-metabox.php
 *
 * @package Lealez
 * @subpackage CPTs/Metaboxes
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class OY_Location_Menu_Metabox
 */
if ( ! class_exists( 'OY_Location_Menu_Metabox' ) ) :

class OY_Location_Menu_Metabox {

/**
     * Nonce action
     * @var string
     */
    private $nonce_action = 'oy_location_menu_save';

    /**
     * Nonce name
     * @var string
     */
    private $nonce_name = 'oy_location_menu_nonce';

    /**
     * Post type slug
     * @var string
     */
    private $post_type = 'oy_location';

    /**
     * Prevents double hook registration when the class is instantiated
     * more than once in the same request (WP 6.9.1 duplicate-metabox guard).
     *
     * @var bool
     */
    private static bool $registered = false;

    /**
     * Constructor
     */
    public function __construct() {
        if ( self::$registered ) {
            return;
        }
        self::$registered = true;

        // Priority 15 keeps this distinct from OY_Location_CPT::add_meta_boxes (priority 10),
        // avoiding the WP 6.9.1 same-priority duplicate-registration bug.
        add_action( 'add_meta_boxes',            array( $this, 'add_meta_box' ), 15 );
        add_action( 'save_post_oy_location',     array( $this, 'save_meta_box' ), 15, 2 );
        add_action( 'admin_enqueue_scripts',     array( $this, 'enqueue_assets' ) );

        // Flujo de edición local + publicación hacia GMB para el módulo Menú.
        add_action( 'wp_ajax_oy_save_menu_metabox',        array( $this, 'ajax_save_menu_metabox' ) );
        add_action( 'wp_ajax_oy_push_menu_to_gmb',         array( $this, 'ajax_push_menu_to_gmb' ) );
        add_action( 'wp_ajax_oy_check_menu_push_status',   array( $this, 'ajax_check_menu_push_status' ) );
        add_action( 'oy_poll_menu_push_status',            array( __CLASS__, 'cron_poll_menu_push_status' ) );
    }

/**
 * Register metabox
 */
public function add_meta_box() {
    add_meta_box(
        'oy_location_menu',
        __( '🍽️ Menú del Restaurante', 'lealez' ),
        array( $this, 'render' ),
        $this->post_type,
        'normal',
        'default'
    );
}

    /**
     * Enqueue scripts and styles (only on oy_location screens)
     */
    public function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== $this->post_type ) {
            return;
        }
        // WordPress Media Library
        wp_enqueue_media();
    }

    /**
     * Render metabox
     *
     * @param WP_Post $post
     */
    public function render( $post ) {
        wp_nonce_field( $this->nonce_action, $this->nonce_name );

        // ── Leer meta fields ──────────────────────────────────────────────────
        $menu_url        = get_post_meta( $post->ID, 'location_menu_url', true );
        $menu_pdf_id     = (int) get_post_meta( $post->ID, 'location_menu_pdf_id', true );
        $menu_pdf_url    = $menu_pdf_id ? wp_get_attachment_url( $menu_pdf_id ) : '';
        $menu_photos     = get_post_meta( $post->ID, 'location_menu_photos', true );
        $menu_sections   = get_post_meta( $post->ID, 'location_menu_sections', true );
        $menu_featured   = get_post_meta( $post->ID, 'location_menu_featured_items', true );

        // ── Datos sincronizados desde GMB ──────────────────────────────────────
        $gmb_food_photos       = get_post_meta( $post->ID, 'gmb_menu_photos_raw', true );       // Array de {googleUrl, mediaKey, category}
        $gmb_food_menus_sync   = get_post_meta( $post->ID, 'gmb_food_menus_last_sync', true );  // Timestamp de última sync
        $gmb_food_menus_error  = get_post_meta( $post->ID, 'gmb_food_menus_api_error', true );  // Error si no se pudo obtener
        $gmb_catalog_sync_notice = (string) get_post_meta( $post->ID, 'gmb_catalog_sync_notice', true );

// ── Datos de conexión GMB (para el botón de sync del menú) ─────────────
        $parent_business_id = (int) get_post_meta( $post->ID, 'parent_business_id', true );
        $gmb_account_id     = (string) get_post_meta( $post->ID, 'gmb_account_id', true );
        $gmb_location_id    = (string) get_post_meta( $post->ID, 'gmb_location_id', true );
        $gmb_location_name  = (string) get_post_meta( $post->ID, 'gmb_location_name', true );
        $gmb_sync_nonce     = wp_create_nonce( 'oy_location_gmb_ajax' );
        $gmb_connected      = ( $parent_business_id > 0 && ( '' !== $gmb_location_id || '' !== $gmb_location_name ) );

        // ── Tipo de catálogo detectado por el sync (food_menu | services | products | none | '') ──
        // Controla qué campos se muestran en cada ítem: restricciones alimentarias vs URL del producto.
        $catalog_type = (string) get_post_meta( $post->ID, 'gmb_catalog_type', true );
        if ( ! in_array( $catalog_type, array( 'food_menu', 'services', 'products', 'none' ), true ) ) {
            $catalog_type = ''; // No sincronizado aún — modo manual: mostrar todos los campos
        }

        if ( ! is_array( $menu_photos ) )    $menu_photos    = array();
        if ( ! is_array( $menu_sections ) )  $menu_sections  = array();
        if ( ! is_array( $menu_featured ) )  $menu_featured  = array();
        if ( ! is_array( $gmb_food_photos ) ) $gmb_food_photos = array();

        $active_tab = isset( $_GET['menu_tab'] ) ? sanitize_key( $_GET['menu_tab'] ) : 'complete';
        ?>
        <style>
        /* ── Tabs ── */
        .oy-menu-tabs { display:flex; border-bottom:2px solid #e0e0e0; margin-bottom:20px; gap:0; }
        .oy-menu-tab-btn {
            padding:9px 20px; cursor:pointer; font-size:13px; font-weight:600;
            border:none; border-bottom:3px solid transparent; background:none;
            color:#666; transition:color .2s, border-color .2s; margin-bottom:-2px;
        }
        .oy-menu-tab-btn.active { color:#2271b1; border-bottom-color:#2271b1; }
        .oy-menu-tab-btn:hover { color:#2271b1; }
        .oy-menu-tab-panel { display:none; }
        .oy-menu-tab-panel.active { display:block; }

        /* ── Secciones ── */
        .oy-menu-section {
            border:1px solid #ddd; border-radius:6px; margin-bottom:16px;
            background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.06);
        }
        .oy-menu-section-header {
            display:flex; align-items:center; gap:10px;
            padding:10px 14px; background:#f8f9fa; border-radius:6px 6px 0 0;
            border-bottom:1px solid #e0e0e0; cursor:grab;
        }
        .oy-menu-section-header:active { cursor:grabbing; }
        .oy-section-handle { color:#aaa; font-size:18px; line-height:1; }
        .oy-section-name-input {
            flex:1; border:1px solid transparent; background:transparent;
            font-size:14px; font-weight:600; padding:4px 8px; border-radius:4px;
        }
        .oy-section-name-input:focus {
            border-color:#2271b1; background:#fff; outline:none;
        }
        .oy-section-toggle { color:#666; cursor:pointer; background:none; border:none; font-size:16px; }
        .oy-section-remove { color:#dc3232; cursor:pointer; background:none; border:none; font-size:18px; font-weight:bold; }
        .oy-menu-items-wrap { padding:12px 14px; }

        /* ── Items ── */
        .oy-menu-item {
            display:grid; grid-template-columns:80px 1fr auto; gap:12px;
            align-items:start; padding:12px; margin-bottom:10px;
            border:1px solid #e8e8e8; border-radius:5px; background:#fafafa;
        }
        .oy-menu-item-image {
            width:80px; height:80px; border:2px dashed #ccc; border-radius:5px;
            display:flex; align-items:center; justify-content:center;
            cursor:pointer; overflow:hidden; background:#f0f0f0; flex-shrink:0;
            position:relative; /* permite posicionar el badge 🔄 GMB sobre la foto */
        }
        .oy-menu-item-image img { width:100%; height:100%; object-fit:cover; }
        .oy-menu-item-image .oy-img-placeholder { color:#aaa; font-size:24px; text-align:center; }
        .oy-menu-item-fields { display:flex; flex-direction:column; gap:6px; }
        .oy-menu-item-fields input[type=text],
        .oy-menu-item-fields input[type=number],
        .oy-menu-item-fields textarea {
            width:100%; box-sizing:border-box;
        }
        .oy-menu-item-fields textarea { resize:vertical; min-height:54px; font-size:12px; }
        .oy-menu-item-row { display:flex; gap:8px; align-items:center; }
        .oy-menu-item-actions { display:flex; flex-direction:column; gap:4px; }
        .oy-item-remove { color:#dc3232; cursor:pointer; background:none; border:none; font-size:18px; }
        .oy-dietary-badges { display:flex; gap:6px; flex-wrap:wrap; margin-top:4px; }
        .oy-dietary-badge {
            display:inline-flex; align-items:center; gap:4px;
            font-size:11px; padding:2px 8px; border-radius:20px;
            border:1px solid #ccc; background:#fff; cursor:pointer; user-select:none;
        }
        .oy-dietary-badge input[type=checkbox] { margin:0; }
        .oy-dietary-badge.checked { border-color:#46b450; background:#edfaee; color:#2a7a2a; }
        .oy-add-item-btn { display:block; width:100%; text-align:center; margin-top:8px; }

        /* ── Fotos del Menú ── */
        .oy-menu-photos-grid {
            display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr));
            gap:12px; margin-bottom:12px;
        }
        .oy-menu-photo-thumb {
            position:relative; width:100%; padding-top:100%; border-radius:5px;
            overflow:hidden; border:1px solid #ddd; background:#f0f0f0;
        }
        .oy-menu-photo-thumb img {
            position:absolute; top:0; left:0; width:100%; height:100%; object-fit:cover;
        }
        .oy-menu-photo-remove {
            position:absolute; top:4px; right:4px;
            background:rgba(220,50,50,.85); color:#fff; border:none;
            border-radius:50%; width:22px; height:22px; cursor:pointer;
            font-size:14px; line-height:1; display:flex; align-items:center; justify-content:center;
        }
        .oy-pdf-preview {
            display:flex; align-items:center; gap:10px; padding:10px 14px;
            background:#f0f6ff; border:1px solid #b3d4f5; border-radius:5px; margin-bottom:12px;
        }
        .oy-pdf-icon { font-size:28px; }
        .oy-pdf-info { flex:1; }
        .oy-pdf-info a { font-weight:600; word-break:break-all; }

        /* ── Platos destacados ── */
        .oy-featured-item {
            display:grid; grid-template-columns:80px 1fr auto; gap:12px;
            align-items:start; padding:12px; margin-bottom:10px;
            border:1px solid #e8e8e8; border-radius:5px; background:#fafafa;
        }

        /* ── Misc ── */
        .oy-menu-url-info {
            background:#f8f9fa; border:1px solid #ddd; border-radius:4px;
            padding:10px 14px; font-size:13px; margin-bottom:16px;
        }
        .oy-menu-section-count { font-size:11px; color:#888; margin-left:auto; }

        /* ── GMB sync badges ── */
        .oy-gmb-badge {
            display:inline-flex; align-items:center; gap:3px;
            font-size:10px; font-weight:600; padding:2px 7px;
            border-radius:20px; white-space:nowrap; vertical-align:middle;
        }
        .oy-gmb-badge-blue   { background:#e8f0fe; border:1px solid #b3d4f5; color:#1a56a0; }
        .oy-gmb-badge-green  { background:#edfaee; border:1px solid #a3d6a7; color:#1e6b22; }
        .oy-gmb-badge-orange { background:#fff3e0; border:1px solid #ffcc80; color:#b45309; }

        /* ── GMB sync notice ── */
        .oy-gmb-sync-notice {
            display:flex; align-items:flex-start; gap:10px;
            background:#e8f4fd; border:1px solid #90caf9; border-radius:5px;
            padding:10px 14px; margin-bottom:16px; font-size:12px; line-height:1.5;
        }
        .oy-gmb-sync-notice-icon { font-size:20px; flex-shrink:0; }
        .oy-gmb-api-error {
            background:#fff3cd; border:1px solid #ffc107; border-radius:5px;
            padding:10px 14px; margin-bottom:16px; font-size:12px; line-height:1.5;
        }

        /* ── GMB Photos grid ── */
        .oy-gmb-photos-grid {
            display:grid; grid-template-columns:repeat(auto-fill, minmax(120px, 1fr));
            gap:10px; margin-bottom:12px;
        }
        .oy-gmb-photo-thumb {
            position:relative; width:100%; padding-top:100%; border-radius:5px;
            overflow:hidden; border:2px solid #b3d4f5; background:#e8f0fe;
        }
        .oy-gmb-photo-thumb img {
            position:absolute; top:0; left:0; width:100%; height:100%; object-fit:cover;
        }
        .oy-gmb-photo-label {
            position:absolute; bottom:0; left:0; right:0;
            background:rgba(33,113,177,.8); color:#fff; font-size:9px;
            padding:2px 4px; text-align:center;
        }

        /* ── Flujo de edición local + publicación GMB ── */
        .oy-menu-action-panel {
            border:1px solid #dcdcde; background:#fff; border-radius:6px;
            padding:12px 14px; margin:0 0 16px; box-shadow:0 1px 2px rgba(0,0,0,.04);
        }
        .oy-menu-action-panel .oy-menu-action-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .oy-menu-action-panel .oy-menu-action-title { font-weight:600; margin-right:8px; }
        .oy-menu-action-panel .oy-menu-state-pill {
            display:inline-flex; align-items:center; gap:4px; font-size:11px; line-height:1;
            padding:5px 8px; border-radius:20px; border:1px solid #dcdcde; background:#f6f7f7; color:#50575e;
        }
        .oy-menu-action-panel .oy-menu-state-pending { background:#fff8e5; border-color:#f0c36d; color:#7a4b00; }
        .oy-menu-action-panel .oy-menu-state-applied { background:#edfaee; border-color:#9bd19f; color:#1e6b22; }
        .oy-menu-action-panel .oy-menu-state-error { background:#fcf0f1; border-color:#ffb8c1; color:#8a2424; }
        .oy-menu-action-panel .oy-menu-action-help { margin:8px 0 0; font-size:12px; color:#646970; }
        .oy-menu-action-panel .oy-menu-inline-status { font-size:12px; margin-left:4px; }
        .oy-menu-action-panel .oy-menu-log-toggle { margin-left:auto; }
        .oy-menu-editable-area { transition:opacity .15s ease; }
        #oy-menu-metabox-wrap.oy-menu-readonly .oy-menu-editable-area {
            opacity:.92;
        }
        #oy-menu-metabox-wrap.oy-menu-readonly .oy-menu-editable-area input,
        #oy-menu-metabox-wrap.oy-menu-readonly .oy-menu-editable-area textarea {
            background:#fafafa;
        }
        #oy-menu-metabox-wrap.oy-menu-editing .oy-menu-editable-area { opacity:1; pointer-events:auto; user-select:auto; }
        .oy-menu-log-panel {
            display:none; background:#111827; color:#e5e7eb; border-radius:6px; padding:10px 12px;
            margin-top:10px; max-height:280px; overflow:auto; font-family:Menlo,Consolas,monospace; font-size:11px;
        }
        .oy-menu-log-panel.is-open { display:block; }
        .oy-menu-log-entry { border-top:1px solid rgba(255,255,255,.12); padding:7px 0; }
        .oy-menu-log-entry:first-child { border-top:none; padding-top:0; }
        .oy-menu-log-entry strong { color:#fff; }
        .oy-menu-log-entry small { color:#9ca3af; }
        .oy-menu-diff-list { margin:5px 0 0 18px; color:#d1d5db; }
        </style>

        <div id="oy-menu-metabox-wrap" class="oy-menu-readonly">
            <?php echo $this->render_menu_action_panel( $post->ID, $gmb_connected ); ?>

            <div class="oy-menu-editable-area">

            <?php /* ── URL del Menú (GMB Place Actions) — editable localmente / referencia GMB ── */ ?>
            <div class="oy-menu-url-info">
                <strong>🔗 URL del Menú / sourceUrl para GMB</strong><br>
                <input type="url"
                       class="regular-text"
                       style="width:100%;max-width:720px;margin-top:6px;"
                       name="location_menu_url"
                       value="<?php echo esc_attr( $menu_url ); ?>"
                       placeholder="https://tusitio.com/menu">
                <?php if ( $menu_url ) : ?>
                    <div style="margin-top:6px;font-size:12px;">
                        <a href="<?php echo esc_url( $menu_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $menu_url ); ?></a>
                        <span class="oy-gmb-badge oy-gmb-badge-blue">🔄 GMB · sourceUrl</span>
                    </div>
                <?php else : ?>
                    <div style="margin-top:6px;color:#888;font-size:12px;"><?php _e( 'Opcional. Si lo dejas vacío y cargas PDF, se usará la URL pública del PDF como sourceUrl del menú.', 'lealez' ); ?></div>
                <?php endif; ?>
            </div>

<?php
            // ── Bloque de error: solo se muestra para errores reales de API ──────────────
            // IMPORTANTE: FAILED_PRECONDITION (negocio no es restaurante) NO se muestra como
            // error al usuario — es comportamiento esperado y completamente normal.
            if ( ! empty( $gmb_food_menus_error ) && is_array( $gmb_food_menus_error ) ) :
                $fm_err_code_val = (string) ( $gmb_food_menus_error['code']    ?? '' );
                $fm_err_msg_val  = (string) ( $gmb_food_menus_error['message'] ?? '' );

                // Detectar si es un FAILED_PRECONDITION (= negocio no-restaurante, no es error)
                $is_expected_precondition = (
                    'FAILED_PRECONDITION'  === $fm_err_code_val
                    || stripos( $fm_err_msg_val, 'FAILED_PRECONDITION' )    !== false
                    || stripos( $fm_err_msg_val, 'not in an eligible category' ) !== false
                    || stripos( $fm_err_msg_val, 'food menu' )              !== false
                );

                if ( ! $is_expected_precondition ) :
                    $fm_err_time = ! empty( $gmb_food_menus_error['timestamp'] )
                        ? ' — ' . esc_html( $gmb_food_menus_error['timestamp'] )
                        : '';
                    ?>
                    <div class="oy-gmb-api-error">
                        <strong>⚠️ <?php _e( 'Error al sincronizar el catálogo desde Google', 'lealez' ); ?></strong>
                        <em><?php echo esc_html( $fm_err_code_val . ' ' . $fm_err_msg_val . $fm_err_time ); ?></em><br>
                        <span style="color:#666;"><?php _e( 'Intenta sincronizar nuevamente. Si el error persiste, verifica que el token de Google esté activo en el metabox de Configuración GMB.', 'lealez' ); ?></span>
                    </div>
                    <?php
                endif;
            endif;

            // ── Aviso informativo cuando el catálogo no está disponible vía API ──────────
            // Se muestra cuando: (a) no hay secciones importadas, y (b) hay un aviso guardado
            // (por ejemplo, cuando el negocio no es restaurante y /products también devolvió vacío).
            if ( ! empty( $gmb_catalog_sync_notice ) && empty( $menu_sections ) ) :
                ?>
                <div class="oy-gmb-sync-notice" style="background:#f0f6ff;border:1px solid #90c5f7;border-radius:5px;padding:10px 14px;margin-bottom:16px;display:flex;align-items:flex-start;gap:10px;font-size:12px;line-height:1.5;">
                    <div style="font-size:20px;flex-shrink:0;">ℹ️</div>
                    <div><?php echo esc_html( $gmb_catalog_sync_notice ); ?></div>
                </div>
                <?php
            endif;
            ?>

            <?php
            // ── Aviso de última sincronización exitosa ──────────────────────────────
            if ( $gmb_food_menus_sync && empty( $gmb_food_menus_error ) ) :
                $sections_from_gmb = array_filter( $menu_sections, fn( $s ) => ! empty( $s['from_gmb'] ) );
                ?>
                <div class="oy-gmb-sync-notice">
                    <div class="oy-gmb-sync-notice-icon">🔄</div>
                    <div>
                        <strong><?php _e( 'Catálogo / Menú sincronizado desde Google My Business', 'lealez' ); ?></strong><br>
                        <?php printf(
                            /* translators: 1: date, 2: number of sections */
                            __( 'Última sincronización: %1$s — %2$d sección(es) importada(s). Ahora puedes editar el menú localmente, guardar los cambios en Lealez y luego enviarlos a Google Business Profile desde el panel superior.', 'lealez' ),
                            esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', $gmb_food_menus_sync ) ),
                            count( $sections_from_gmb )
                        ); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php /* ── Tabs ── */ ?>
            <div class="oy-menu-tabs">
                <button type="button" class="oy-menu-tab-btn active" data-tab="complete"><?php _e( 'Catálogo / Menú', 'lealez' ); ?></button>
                <button type="button" class="oy-menu-tab-btn" data-tab="photos"><?php _e( 'Fotos del Catálogo', 'lealez' ); ?></button>
                <button type="button" class="oy-menu-tab-btn" data-tab="featured"><?php _e( 'Destacados', 'lealez' ); ?></button>
            </div>

            <?php /* ═══════════════════════════════════════════════════════
                   TAB 1: MENÚ COMPLETO (Secciones + Productos)
                   ═══════════════════════════════════════════════════════ */ ?>
            <div class="oy-menu-tab-panel active" id="oy-menu-tab-complete">

                <p class="description" style="margin-bottom:14px;">
                    <?php _e( 'Las secciones y platos se importan automáticamente desde Google My Business al sincronizar la ubicación. Este metabox es exclusivo para restaurantes y negocios con menú de alimentos. Para negocios con catálogo de productos (ropa, electrónicos, etc.), usa el metabox de Catálogo de Productos.', 'lealez' ); ?>
                </p>

                <?php /* ── Botón de sincronización directa desde GMB ── */ ?>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">
                    <button
                        type="button"
                        id="oy-sync-food-menus-btn"
                        class="button button-secondary"
                        <?php echo $gmb_connected ? '' : 'disabled'; ?>
                        data-post-id="<?php echo esc_attr( $post->ID ); ?>"
                        data-business-id="<?php echo esc_attr( $parent_business_id ); ?>"
                        data-nonce="<?php echo esc_attr( $gmb_sync_nonce ); ?>"
                    >
                        🔄 <?php _e( 'Sincronizar desde Google', 'lealez' ); ?>
                    </button>
                    <span id="oy-sync-food-menus-status" style="font-size:12px;color:#666;">
                        <?php if ( ! $gmb_connected ) : ?>
                            <em><?php _e( 'Conecta primero la ubicación a Google My Business', 'lealez' ); ?></em>
                        <?php elseif ( $gmb_food_menus_sync ) : ?>
                            <?php printf(
                                /* translators: %s: fecha */
                                __( 'Última sync: %s', 'lealez' ),
                                esc_html( date_i18n( 'd/m/Y H:i', $gmb_food_menus_sync ) )
                            ); ?>
                        <?php else : ?>
                            <em><?php _e( 'Sin sincronizar aún — haz clic para traer el menú desde Google', 'lealez' ); ?></em>
                        <?php endif; ?>
                    </span>
                </div>

                <div id="oy-menu-sections-list">
                    <?php foreach ( $menu_sections as $sec_idx => $section ) :
                        $sec_name     = sanitize_text_field( $section['name'] ?? '' );
                        $sec_items    = is_array( $section['items'] ?? null ) ? $section['items'] : array();
                        $sec_from_gmb = ! empty( $section['from_gmb'] );
                        ?>
                        <?php $this->render_section_html( $sec_idx, $sec_name, $sec_items, $sec_from_gmb, $catalog_type ); ?>
                    <?php endforeach; ?>
                </div>

                <button type="button" id="oy-add-section" class="button button-primary">
                    + <?php _e( 'Agregar sección', 'lealez' ); ?>
                </button>

                <p class="description" style="margin-top:8px;">
                    <?php _e( 'Cada sección debe tener al menos un producto. Puedes reordenar las secciones arrastrándolas.', 'lealez' ); ?>
                </p>
            </div>

            <?php /* ═══════════════════════════════════════════════════════
                   TAB 2: FOTOS DEL MENÚ
                   ═══════════════════════════════════════════════════════ */ ?>
            <div class="oy-menu-tab-panel" id="oy-menu-tab-photos">

                <p class="description" style="margin-bottom:14px;">
                    <?php _e( 'Sube fotos o un PDF de tu menú. Las fotos y el PDF se mostrarán en tu perfil de Google Business. Formatos aceptados: JPG, PNG, PDF.', 'lealez' ); ?>
                </p>

                <?php /* ── Fotos sincronizadas desde GMB (lectura, solo si hay) ── */ ?>
                <?php if ( ! empty( $gmb_food_photos ) ) : ?>
                    <div style="background:#e8f4fd;border:1px solid #90caf9;border-radius:5px;padding:12px 14px;margin-bottom:18px;">
                        <h4 style="margin:0 0 10px;font-size:13px;">
                            🔄 <?php _e( 'Fotos sincronizadas desde Google My Business', 'lealez' ); ?>
                            <span class="oy-gmb-badge oy-gmb-badge-green" style="margin-left:6px;"><?php echo count( $gmb_food_photos ); ?> fotos</span>
                        </h4>
                        <p class="description" style="margin:0 0 10px;font-size:12px;">
                            <?php _e( 'Estas son las fotos de categoría "Comida y bebida / Menú" registradas en tu Perfil de Negocio de Google. Son de solo lectura — para modificarlas hazlo desde Google Business Profile.', 'lealez' ); ?>
                        </p>
                        <div class="oy-gmb-photos-grid">
                            <?php foreach ( $gmb_food_photos as $gphoto ) :
                                $gurl      = ! empty( $gphoto['googleUrl'] ) ? esc_url( $gphoto['googleUrl'] ) : '';
                                $gcategory = ! empty( $gphoto['category'] ) ? esc_html( $gphoto['category'] ) : 'FOOD';
                                if ( ! $gurl ) continue;
                                ?>
                                <div class="oy-gmb-photo-thumb">
                                    <a href="<?php echo $gurl; ?>" target="_blank" rel="noopener" title="<?php _e( 'Ver en tamaño completo', 'lealez' ); ?>">
                                        <img src="<?php echo $gurl; ?>=w200-h200-c" alt="" loading="lazy"
                                             onerror="this.src='<?php echo $gurl; ?>';this.onerror=null;">
                                    </a>
                                    <div class="oy-gmb-photo-label"><?php echo $gcategory; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php /* PDF del Menú */ ?>
                <h4 style="margin:0 0 8px;"><?php _e( '📄 PDF del Menú', 'lealez' ); ?></h4>
                <?php if ( $menu_pdf_id && $menu_pdf_url ) : ?>
                    <div class="oy-pdf-preview">
                        <div class="oy-pdf-icon">📄</div>
                        <div class="oy-pdf-info">
                            <a href="<?php echo esc_url( $menu_pdf_url ); ?>" target="_blank" rel="noopener">
                                <?php echo esc_html( basename( $menu_pdf_url ) ); ?>
                            </a><br>
                            <span style="font-size:11px;color:#888;"><?php _e( 'PDF cargado correctamente', 'lealez' ); ?></span>
                        </div>
                        <div>
                            <button type="button" class="button button-small oy-remove-pdf">✕ <?php _e( 'Quitar PDF', 'lealez' ); ?></button>
                        </div>
                    </div>
                <?php else : ?>
                    <div style="padding:30px; border:2px dashed #ccc; border-radius:6px; text-align:center; background:#f9f9f9; margin-bottom:14px;" id="oy-pdf-drop-area">
                        <p style="margin:0 0 8px;font-size:14px;color:#666;">
                            <?php _e( 'Arrastra un archivo PDF aquí', 'lealez' ); ?>
                        </p>
                        <p style="margin:0 0 10px;color:#aaa;"><?php _e( 'o bien', 'lealez' ); ?></p>
                        <button type="button" class="button button-primary" id="oy-select-pdf">
                            📁 <?php _e( 'Selecciona un archivo PDF', 'lealez' ); ?>
                        </button>
                    </div>
                <?php endif; ?>
                <input type="hidden" name="location_menu_pdf_id" id="location_menu_pdf_id" value="<?php echo esc_attr( $menu_pdf_id ?: '' ); ?>">

                <hr style="margin:16px 0;">

                <?php /* Fotos del Menú (WordPress Media Library) */ ?>
                <h4 style="margin:0 0 8px;"><?php _e( '📸 Fotos del Menú (subidas manualmente)', 'lealez' ); ?></h4>
                <div class="oy-menu-photos-grid" id="oy-menu-photos-grid">
                    <?php foreach ( $menu_photos as $photo_id ) :
                        $photo_url = wp_get_attachment_image_url( $photo_id, 'thumbnail' );
                        if ( ! $photo_url ) continue;
                        ?>
                        <div class="oy-menu-photo-thumb" data-photo-id="<?php echo esc_attr( $photo_id ); ?>">
                            <img src="<?php echo esc_url( $photo_url ); ?>" alt="">
                            <button type="button" class="oy-menu-photo-remove" title="<?php esc_attr_e( 'Eliminar foto', 'lealez' ); ?>">✕</button>
                            <input type="hidden" name="location_menu_photos[]" value="<?php echo esc_attr( $photo_id ); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="padding:20px; border:2px dashed #ccc; border-radius:6px; text-align:center; background:#f9f9f9;" id="oy-photos-drop-area">
                    <p style="margin:0 0 8px;font-size:14px;color:#666;">
                        <?php _e( 'Arrastra imágenes aquí', 'lealez' ); ?>
                    </p>
                    <p style="margin:0 0 10px;color:#aaa;"><?php _e( 'o bien', 'lealez' ); ?></p>
                    <button type="button" class="button button-primary" id="oy-select-menu-photos">
                        📷 <?php _e( 'Selecciona imágenes', 'lealez' ); ?>
                    </button>
                </div>
                <p class="description" style="margin-top:8px;">
                    <?php _e( 'Las imágenes cargadas aquí se almacenan en la Biblioteca de Medios de WordPress.', 'lealez' ); ?>
                </p>
            </div>

            <?php /* ═══════════════════════════════════════════════════════
                   TAB 3: PLATOS DESTACADOS
                   ═══════════════════════════════════════════════════════ */ ?>
            <div class="oy-menu-tab-panel" id="oy-menu-tab-featured">

                <p class="description" style="margin-bottom:14px;">
                    <?php _e( 'Selecciona los productos, platos o servicios que quieres destacar en tu perfil. Estos aparecerán en la sección de destacados de tu Perfil de Negocio de Google.', 'lealez' ); ?>
                </p>

                <div id="oy-featured-items-list">
                    <?php foreach ( $menu_featured as $feat_idx => $feat ) :
                        $feat_name  = sanitize_text_field( $feat['name']        ?? '' );
                        $feat_price = sanitize_text_field( $feat['price']       ?? '' );
                        $feat_desc  = sanitize_textarea_field( $feat['description'] ?? '' );
                        $feat_img   = (int) ( $feat['image_id'] ?? 0 );
                        $feat_img_url = $feat_img ? wp_get_attachment_image_url( $feat_img, 'thumbnail' ) : '';
                        ?>
                        <?php $this->render_featured_item_html( $feat_idx, $feat_name, $feat_price, $feat_desc, $feat_img, $feat_img_url ); ?>
                    <?php endforeach; ?>
                </div>

                <button type="button" id="oy-add-featured-item" class="button button-primary">
                    ⭐ <?php _e( 'Agregar elemento destacado', 'lealez' ); ?>
                </button>
            </div>

            </div><!-- /.oy-menu-editable-area -->
        </div><!-- /#oy-menu-metabox-wrap -->

<?php /* ── Templates HTML (ocultos) para JS ── */ ?>
        <script type="text/html" id="oy-section-template">
            <?php $this->render_section_html( '__SEC_IDX__', '', array(), false, $catalog_type ); ?>
        </script>
        <script type="text/html" id="oy-item-template">
            <?php $this->render_item_html( '__SEC_IDX__', '__ITEM_IDX__', '', '', '', 0, '', array(), '', array() ); ?>
        </script>
        <script type="text/html" id="oy-featured-template">
            <?php $this->render_featured_item_html( '__FEAT_IDX__', '', '', '', 0, '' ); ?>
        </script>

        <?php /* ── JavaScript ── */ ?>
        <script>
        (function($){
            'use strict';

            var oyMenuDirty = false;
            var oyMenuEditing = false;
            var oyMenuInitialSnapshot = captureMenuSnapshot();
            var oyMenuLogKey = 'oy_menu_metabox_log_' + ($('#oy-menu-action-panel').data('post-id') || '0');
            var oyAjaxUrl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

            function escHtml(value) {
                return $('<div>').text(value === undefined || value === null ? '' : String(value)).html();
            }

            function captureMenuSnapshot() {
                var sections = [];
                var itemsCount = 0;
                $('#oy-menu-sections-list .oy-menu-section').each(function(){
                    var secName = $(this).find('.oy-section-name-input').first().val() || '';
                    var itemNames = [];
                    $(this).find('.oy-menu-item').each(function(){
                        var name = $(this).find('input[name$="[name]"]').first().val() || '';
                        if (name) { itemNames.push(name); }
                        itemsCount++;
                    });
                    sections.push({ name: secName, items: itemNames });
                });
                return {
                    menu_url: $('input[name="location_menu_url"]').val() || '',
                    pdf_id: $('#location_menu_pdf_id').val() || '',
                    photos_count: $('#oy-menu-photos-grid .oy-menu-photo-thumb').length,
                    sections_count: sections.length,
                    items_count: itemsCount,
                    sections: sections,
                    featured_count: $('#oy-featured-items-list .oy-featured-item').length
                };
            }

            function buildMenuDiff(before, after) {
                var diff = [];
                before = before || {};
                after = after || {};
                ['menu_url','pdf_id','photos_count','sections_count','items_count','featured_count'].forEach(function(key){
                    if (JSON.stringify(before[key]) !== JSON.stringify(after[key])) {
                        diff.push(key + ': ' + (before[key] || '—') + ' → ' + (after[key] || '—'));
                    }
                });
                return diff;
            }

            function getLocalLog() {
                try {
                    var raw = window.localStorage.getItem(oyMenuLogKey);
                    return raw ? JSON.parse(raw) : [];
                } catch(e) { return []; }
            }

            function setLocalLog(entries) {
                try {
                    window.localStorage.setItem(oyMenuLogKey, JSON.stringify(entries.slice(0, 30)));
                } catch(e) {}
            }

            function addLocalLog(eventName, message, before, after, extra) {
                var entries = getLocalLog();
                entries.unshift({
                    at: new Date().toLocaleString(),
                    event: eventName,
                    message: message || '',
                    diff: buildMenuDiff(before || {}, after || {}),
                    snapshot: after || {},
                    extra: extra || {}
                });
                setLocalLog(entries);
                renderLocalLog();
            }

            function renderLocalLog() {
                var entries = getLocalLog();
                var $panel = $('#oy-menu-log-panel');
                if (!$panel.length) { return; }
                if (!entries.length) {
                    $panel.html('<div class="oy-menu-log-entry"><strong><?php echo esc_js( __( 'Sin eventos locales todavía.', 'lealez' ) ); ?></strong></div>');
                    return;
                }
                var html = '';
                entries.slice(0, 15).forEach(function(entry){
                    html += '<div class="oy-menu-log-entry"><strong>' + escHtml(entry.event) + '</strong> <small>— ' + escHtml(entry.at) + '</small><br>';
                    if (entry.message) { html += '<span>' + escHtml(entry.message) + '</span>'; }
                    if (entry.diff && entry.diff.length) {
                        html += '<ul class="oy-menu-diff-list">';
                        entry.diff.forEach(function(line){ html += '<li>' + escHtml(line) + '</li>'; });
                        html += '</ul>';
                    }
                    html += '</div>';
                });
                $panel.html(html);
            }

            function setInlineStatus(message, type) {
                var color = '#646970';
                if (type === 'success') { color = '#2e7d32'; }
                if (type === 'error') { color = '#c0392b'; }
                if (type === 'info') { color = '#2271b1'; }
                $('#oy-menu-inline-status').html('<span style="color:' + color + ';">' + escHtml(message) + '</span>');
            }

            function setEditMode(enabled) {
                oyMenuEditing = !!enabled;
                $('#oy-menu-metabox-wrap').toggleClass('oy-menu-editing', oyMenuEditing).toggleClass('oy-menu-readonly', !oyMenuEditing);
                $('#oy-menu-save-local-btn, #oy-menu-cancel-edit-btn').prop('disabled', !oyMenuEditing);
                $('#oy-menu-edit-btn').prop('disabled', oyMenuEditing);
                if (oyMenuEditing) {
                    setInlineStatus('<?php echo esc_js( __( 'Modo edición activo. Guarda antes de enviar a GMB.', 'lealez' ) ); ?>', 'info');
                } else if (!oyMenuDirty) {
                    setInlineStatus('', '');
                }
            }

            function markMenuDirty() {
                oyMenuDirty = true;
                if (!oyMenuEditing) {
                    setEditMode(true);
                }
                $('#oy-menu-save-local-btn').prop('disabled', false);
                setInlineStatus('<?php echo esc_js( __( 'Hay cambios sin guardar.', 'lealez' ) ); ?>', 'info');
            }

            function replaceActionPanel(html) {
                if (html) {
                    $('#oy-menu-action-panel').replaceWith(html);
                    renderLocalLog();
                    if (oyMenuDirty || oyMenuEditing) {
                        setEditMode(oyMenuEditing);
                    }
                }
            }

            renderLocalLog();

            $(document).on('click', '#oy-menu-edit-btn', function(){
                oyMenuInitialSnapshot = captureMenuSnapshot();
                setEditMode(true);
                addLocalLog('edit_mode', '<?php echo esc_js( __( 'Se activó el modo edición del menú.', 'lealez' ) ); ?>', oyMenuInitialSnapshot, oyMenuInitialSnapshot, {});
            });

            $(document).on('click', '#oy-menu-cancel-edit-btn', function(){
                if (oyMenuDirty && !confirm('<?php echo esc_js( __( 'Hay cambios sin guardar. Se recargará la página para volver al último estado guardado. ¿Continuar?', 'lealez' ) ); ?>')) {
                    return;
                }
                if (oyMenuDirty) {
                    window.location.reload();
                    return;
                }
                setEditMode(false);
            });

            $(document).on('click', '#oy-menu-toggle-log', function(){
                $('#oy-menu-log-panel').toggleClass('is-open');
                renderLocalLog();
            });

            $(document).on('input change', '.oy-menu-editable-area input, .oy-menu-editable-area textarea', function(){
                markMenuDirty();
            });

            $(document).on('click', '#oy-add-section,.oy-section-remove,.oy-add-item-btn,.oy-item-remove,#oy-add-featured-item,.oy-featured-remove,.oy-menu-photo-remove,.oy-remove-pdf,.oy-menu-item-image,.oy-featured-item-image,#oy-select-pdf,#oy-select-menu-photos', function(){
                setTimeout(markMenuDirty, 220);
            });

            $(document).on('click', '#oy-menu-save-local-btn', function(){
                var $btn = $(this);
                var $panel = $('#oy-menu-action-panel');
                var before = oyMenuInitialSnapshot || captureMenuSnapshot();
                var formData = $('#post').serializeArray();
                formData.push({ name: 'action', value: 'oy_save_menu_metabox' });
                formData.push({ name: 'post_id', value: $panel.data('post-id') });
                formData.push({ name: 'nonce', value: $panel.data('save-nonce') });

                $btn.prop('disabled', true).text('⏳ <?php echo esc_js( __( 'Guardando...', 'lealez' ) ); ?>');
                setInlineStatus('<?php echo esc_js( __( 'Guardando cambios locales...', 'lealez' ) ); ?>', 'info');

                $.post(oyAjaxUrl, $.param(formData))
                    .done(function(resp){
                        if (resp && resp.success) {
                            var after = captureMenuSnapshot();
                            oyMenuDirty = false;
                            oyMenuInitialSnapshot = after;
                            addLocalLog('manual_save', resp.data && resp.data.message ? resp.data.message : '<?php echo esc_js( __( 'Cambios locales guardados.', 'lealez' ) ); ?>', before, after, resp.data || {});
                            replaceActionPanel(resp.data ? resp.data.panel_html : '');
                            setEditMode(false);
                            setInlineStatus('<?php echo esc_js( __( 'Guardado local correcto.', 'lealez' ) ); ?>', 'success');
                        } else {
                            var msg = resp && resp.data && resp.data.message ? resp.data.message : '<?php echo esc_js( __( 'No se pudo guardar el menú.', 'lealez' ) ); ?>';
                            addLocalLog('save_error', msg, before, captureMenuSnapshot(), resp || {});
                            setInlineStatus(msg, 'error');
                        }
                    })
                    .fail(function(){
                        var msg = '<?php echo esc_js( __( 'Error de red al guardar el menú.', 'lealez' ) ); ?>';
                        addLocalLog('save_error', msg, before, captureMenuSnapshot(), {});
                        setInlineStatus(msg, 'error');
                    })
                    .always(function(){
                        $btn.prop('disabled', !oyMenuEditing).text('💾 <?php echo esc_js( __( 'Guardar cambios locales', 'lealez' ) ); ?>');
                    });
            });

            $(document).on('click', '#oy-menu-push-gmb-btn', function(){
                var $btn = $(this);
                var $panel = $('#oy-menu-action-panel');
                var before = captureMenuSnapshot();

                if (oyMenuDirty) {
                    setInlineStatus('<?php echo esc_js( __( 'Primero guarda los cambios locales antes de enviarlos a GMB.', 'lealez' ) ); ?>', 'error');
                    return;
                }
                if (!$panel.data('connected')) {
                    setInlineStatus('<?php echo esc_js( __( 'Esta ubicación no está conectada a GMB.', 'lealez' ) ); ?>', 'error');
                    return;
                }

                $btn.prop('disabled', true).text('⏳ <?php echo esc_js( __( 'Enviando...', 'lealez' ) ); ?>');
                setInlineStatus('<?php echo esc_js( __( 'Enviando menú a Google Business Profile...', 'lealez' ) ); ?>', 'info');

                $.post(oyAjaxUrl, {
                    action: 'oy_push_menu_to_gmb',
                    post_id: $panel.data('post-id'),
                    nonce: $panel.data('push-nonce')
                }).done(function(resp){
                    var after = captureMenuSnapshot();
                    if (resp && resp.success) {
                        addLocalLog('push_to_gmb', resp.data && resp.data.message ? resp.data.message : '<?php echo esc_js( __( 'Menú enviado a GMB.', 'lealez' ) ); ?>', before, after, resp.data || {});
                        replaceActionPanel(resp.data ? resp.data.panel_html : '');
                        setInlineStatus('<?php echo esc_js( __( 'Solicitud enviada a GMB.', 'lealez' ) ); ?>', 'success');
                    } else {
                        var msg = resp && resp.data && resp.data.message ? resp.data.message : '<?php echo esc_js( __( 'No se pudo enviar el menú a GMB.', 'lealez' ) ); ?>';
                        addLocalLog('push_error', msg, before, after, resp || {});
                        replaceActionPanel(resp && resp.data ? resp.data.panel_html : '');
                        setInlineStatus(msg, 'error');
                    }
                }).fail(function(){
                    var msg = '<?php echo esc_js( __( 'Error de red al enviar el menú a GMB.', 'lealez' ) ); ?>';
                    addLocalLog('push_error', msg, before, captureMenuSnapshot(), {});
                    setInlineStatus(msg, 'error');
                }).always(function(){
                    $btn.prop('disabled', false).text('☁️ <?php echo esc_js( __( 'Enviar a GMB', 'lealez' ) ); ?>');
                });
            });

            $(document).on('click', '#oy-menu-check-gmb-btn', function(){
                var $btn = $(this);
                var $panel = $('#oy-menu-action-panel');
                var before = captureMenuSnapshot();

                $btn.prop('disabled', true).text('⏳ <?php echo esc_js( __( 'Verificando...', 'lealez' ) ); ?>');
                setInlineStatus('<?php echo esc_js( __( 'Consultando estado actual en GMB...', 'lealez' ) ); ?>', 'info');

                $.post(oyAjaxUrl, {
                    action: 'oy_check_menu_push_status',
                    post_id: $panel.data('post-id'),
                    nonce: $panel.data('check-nonce')
                }).done(function(resp){
                    var after = captureMenuSnapshot();
                    if (resp && resp.success) {
                        addLocalLog(resp.data && resp.data.applied ? 'check_applied' : 'check_pending', resp.data && resp.data.message ? resp.data.message : '<?php echo esc_js( __( 'Verificación completada.', 'lealez' ) ); ?>', before, after, resp.data || {});
                        replaceActionPanel(resp.data ? resp.data.panel_html : '');
                        setInlineStatus(resp.data && resp.data.message ? resp.data.message : '<?php echo esc_js( __( 'Verificación completada.', 'lealez' ) ); ?>', resp.data && resp.data.applied ? 'success' : 'info');
                    } else {
                        var msg = resp && resp.data && resp.data.message ? resp.data.message : '<?php echo esc_js( __( 'No se pudo verificar el estado en GMB.', 'lealez' ) ); ?>';
                        addLocalLog('check_error', msg, before, after, resp || {});
                        replaceActionPanel(resp && resp.data ? resp.data.panel_html : '');
                        setInlineStatus(msg, 'error');
                    }
                }).fail(function(){
                    var msg = '<?php echo esc_js( __( 'Error de red al verificar GMB.', 'lealez' ) ); ?>';
                    addLocalLog('check_error', msg, before, captureMenuSnapshot(), {});
                    setInlineStatus(msg, 'error');
                }).always(function(){
                    $btn.prop('disabled', false).text('✅ <?php echo esc_js( __( 'Verificar estado', 'lealez' ) ); ?>');
                });
            });

            // ── Tabs ────────────────────────────────────────────────────
            $(document).on('click', '.oy-menu-tab-btn', function(){
                var tab = $(this).data('tab');
                $('.oy-menu-tab-btn').removeClass('active');
                $(this).addClass('active');
                $('.oy-menu-tab-panel').removeClass('active');
                $('#oy-menu-tab-' + tab).addClass('active');
            });

            // ── Toggle sección ──────────────────────────────────────────
            $(document).on('click', '.oy-section-toggle', function(){
                $(this).closest('.oy-menu-section').find('.oy-menu-items-wrap').slideToggle(200);
                var icon = $(this).text().trim();
                $(this).text(icon === '▲' ? '▼' : '▲');
            });

            // ── Eliminar sección ────────────────────────────────────────
            $(document).on('click', '.oy-section-remove', function(){
                if ( confirm('<?php echo esc_js( __( '¿Eliminar esta sección y todos sus productos?', 'lealez' ) ); ?>') ) {
                    $(this).closest('.oy-menu-section').slideUp(200, function(){ $(this).remove(); oy_reindex_sections(); });
                }
            });

            // ── Agregar sección ─────────────────────────────────────────
            $('#oy-add-section').on('click', function(){
                var tpl   = $('#oy-section-template').html();
                var idx   = $('#oy-menu-sections-list .oy-menu-section').length;
                tpl = tpl.replace(/__SEC_IDX__/g, idx);
                var $sec = $(tpl);
                $('#oy-menu-sections-list').append($sec);
                $sec.find('.oy-section-name-input').focus();
            });

            // ── Agregar item en sección ─────────────────────────────────
            $(document).on('click', '.oy-add-item-btn', function(){
                var $section = $(this).closest('.oy-menu-section');
                var sec_idx  = $section.data('section-idx');
                var tpl      = $('#oy-item-template').html();
                var item_idx = $section.find('.oy-menu-item').length;
                tpl = tpl.replace(/__SEC_IDX__/g, sec_idx).replace(/__ITEM_IDX__/g, item_idx);
                $(this).before($(tpl));
            });

            // ── Eliminar item ───────────────────────────────────────────
            $(document).on('click', '.oy-item-remove', function(){
                $(this).closest('.oy-menu-item').slideUp(150, function(){ $(this).remove(); });
            });

            // ── Dietary badge toggle ────────────────────────────────────
            $(document).on('change', '.oy-dietary-badge input[type=checkbox]', function(){
                $(this).closest('.oy-dietary-badge').toggleClass('checked', this.checked);
            });

            // ── Imagen de item (media library) ──────────────────────────
            $(document).on('click', '.oy-menu-item-image', function(){
                var $thumb = $(this);
                var $idInput = $thumb.siblings('input.oy-item-image-id');
                var frame = wp.media({
                    title: '<?php echo esc_js( __( 'Seleccionar imagen del producto', 'lealez' ) ); ?>',
                    button: { text: '<?php echo esc_js( __( 'Usar imagen', 'lealez' ) ); ?>' },
                    multiple: false,
                    library: { type: 'image' }
                });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    var thumb_url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                    $thumb.html('<img src="' + thumb_url + '" alt="">');
                    $idInput.val(att.id);
                });
                frame.open();
            });

            // ── Imagen de plato destacado (media library) ───────────────
            $(document).on('click', '.oy-featured-item-image', function(){
                var $thumb = $(this);
                var $idInput = $thumb.siblings('input.oy-featured-image-id');
                var frame = wp.media({
                    title: '<?php echo esc_js( __( 'Seleccionar imagen del plato', 'lealez' ) ); ?>',
                    button: { text: '<?php echo esc_js( __( 'Usar imagen', 'lealez' ) ); ?>' },
                    multiple: false,
                    library: { type: 'image' }
                });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    var thumb_url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                    $thumb.html('<img src="' + thumb_url + '" alt="">');
                    $idInput.val(att.id);
                });
                frame.open();
            });

            // ── Agregar plato destacado ─────────────────────────────────
            $('#oy-add-featured-item').on('click', function(){
                var tpl  = $('#oy-featured-template').html();
                var idx  = $('#oy-featured-items-list .oy-featured-item').length;
                tpl = tpl.replace(/__FEAT_IDX__/g, idx);
                $('#oy-featured-items-list').append($(tpl));
            });

            // ── Eliminar plato destacado ────────────────────────────────
            $(document).on('click', '.oy-featured-remove', function(){
                $(this).closest('.oy-featured-item').slideUp(150, function(){ $(this).remove(); });
            });

            // ── Seleccionar PDF ─────────────────────────────────────────
            $(document).on('click', '#oy-select-pdf', function(){
                var frame = wp.media({
                    title: '<?php echo esc_js( __( 'Seleccionar PDF del Menú', 'lealez' ) ); ?>',
                    button: { text: '<?php echo esc_js( __( 'Usar este archivo', 'lealez' ) ); ?>' },
                    multiple: false,
                    library: { type: 'application/pdf' }
                });
                frame.on('select', function(){
                    var att  = frame.state().get('selection').first().toJSON();
                    var name = att.filename || att.title || att.url.split('/').pop();
                    $('#location_menu_pdf_id').val(att.id);
                    $('#oy-pdf-drop-area').replaceWith(
                        '<div class="oy-pdf-preview">' +
                        '<div class="oy-pdf-icon">📄</div>' +
                        '<div class="oy-pdf-info"><a href="' + att.url + '" target="_blank" rel="noopener">' + name + '</a><br>' +
                        '<span style="font-size:11px;color:#888;"><?php echo esc_js( __( 'PDF cargado correctamente', 'lealez' ) ); ?></span></div>' +
                        '<div><button type="button" class="button button-small oy-remove-pdf">✕ <?php echo esc_js( __( 'Quitar PDF', 'lealez' ) ); ?></button></div>' +
                        '</div>'
                    );
                });
                frame.open();
            });

            // ── Quitar PDF ──────────────────────────────────────────────
            $(document).on('click', '.oy-remove-pdf', function(){
                $('#location_menu_pdf_id').val('');
                $('.oy-pdf-preview').replaceWith(
                    '<div style="padding:30px;border:2px dashed #ccc;border-radius:6px;text-align:center;background:#f9f9f9;margin-bottom:14px;" id="oy-pdf-drop-area">' +
                    '<p style="margin:0 0 8px;font-size:14px;color:#666;"><?php echo esc_js( __( 'Arrastra un archivo PDF aquí', 'lealez' ) ); ?></p>' +
                    '<p style="margin:0 0 10px;color:#aaa;"><?php echo esc_js( __( 'o bien', 'lealez' ) ); ?></p>' +
                    '<button type="button" class="button button-primary" id="oy-select-pdf">📁 <?php echo esc_js( __( 'Selecciona un archivo PDF', 'lealez' ) ); ?></button>' +
                    '</div>'
                );
            });

            // ── Seleccionar fotos del menú ──────────────────────────────
            $(document).on('click', '#oy-select-menu-photos', function(){
                var frame = wp.media({
                    title: '<?php echo esc_js( __( 'Seleccionar fotos del Menú', 'lealez' ) ); ?>',
                    button: { text: '<?php echo esc_js( __( 'Agregar fotos', 'lealez' ) ); ?>' },
                    multiple: true,
                    library: { type: 'image' }
                });
                frame.on('select', function(){
                    frame.state().get('selection').each(function(att){
                        var data     = att.toJSON();
                        var thumb    = data.sizes && data.sizes.thumbnail ? data.sizes.thumbnail.url : data.url;
                        var $thumb   = $(
                            '<div class="oy-menu-photo-thumb" data-photo-id="' + data.id + '">' +
                            '<img src="' + thumb + '" alt="">' +
                            '<button type="button" class="oy-menu-photo-remove" title="<?php echo esc_js( __( 'Eliminar foto', 'lealez' ) ); ?>">✕</button>' +
                            '<input type="hidden" name="location_menu_photos[]" value="' + data.id + '">' +
                            '</div>'
                        );
                        $('#oy-menu-photos-grid').append($thumb);
                    });
                });
                frame.open();
            });

            // ── Eliminar foto del menú ──────────────────────────────────
            $(document).on('click', '.oy-menu-photo-remove', function(){
                $(this).closest('.oy-menu-photo-thumb').remove();
            });

            // ── Reindexar secciones (para nombres correctos en POST) ────
            function oy_reindex_sections() {
                $('#oy-menu-sections-list .oy-menu-section').each(function(sec_idx){
                    var $sec = $(this);
                    $sec.attr('data-section-idx', sec_idx);
                    $sec.find('input[name*="location_menu_sections"], textarea[name*="location_menu_sections"]').each(function(){
                        var name = $(this).attr('name');
                        name = name.replace(/location_menu_sections\[\d+\]/, 'location_menu_sections[' + sec_idx + ']');
                        $(this).attr('name', name);
                    });
                });
            }

            // ── Sortable (secciones) ────────────────────────────────────
            if ($.fn.sortable) {
                $('#oy-menu-sections-list').sortable({
                    handle: '.oy-section-handle',
                    placeholder: 'oy-section-placeholder',
                    stop: function(){ oy_reindex_sections(); }
                });
            }

            // ── Sincronizar menú desde Google My Business ─────────────
            $('#oy-sync-food-menus-btn').on('click', function(){
                var $btn     = $(this);
                var $status  = $('#oy-sync-food-menus-status');
                var postId   = $btn.data('post-id');
                var bizId    = $btn.data('business-id');
                var nonce    = $btn.data('nonce');
                var ajaxUrl  = (typeof ajaxurl !== 'undefined') ? ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

                if ( ! postId || ! bizId ) {
                    $status.html('<span style="color:#c0392b;"><?php echo esc_js( __( 'Error: falta post_id o business_id.', 'lealez' ) ); ?></span>');
                    return;
                }

                if (oyMenuDirty) {
                    $status.html('<span style="color:#c0392b;"><?php echo esc_js( __( 'Primero guarda o cancela los cambios locales antes de sincronizar desde Google.', 'lealez' ) ); ?></span>');
                    return;
                }

                if ($('#oy-menu-action-panel').data('pending') && !confirm('<?php echo esc_js( __( 'Hay cambios locales pendientes de confirmar en Google. Sincronizar desde Google puede sobrescribir el menú local. ¿Continuar?', 'lealez' ) ); ?>')) {
                    return;
                }

                $btn.prop('disabled', true).text('⏳ <?php echo esc_js( __( 'Sincronizando...', 'lealez' ) ); ?>');
                $status.html('<em style="color:#2271b1;"><?php echo esc_js( __( 'Contactando Google My Business API...', 'lealez' ) ); ?></em>');

                $.post(ajaxUrl, {
                    action:      'oy_sync_location_food_menus',
                    nonce:       nonce,
                    post_id:     postId,
                    business_id: bizId
                })
                .done(function(resp){
                    if ( resp && resp.success ) {
                        var d = resp.data || {};
                        $status.html('<span style="color:#2e7d32;font-weight:600;">' + ( d.message || '<?php echo esc_js( __( 'Sincronizado.', 'lealez' ) ); ?>' ) + '</span>');
                        addLocalLog('sync_from_gmb', d.message || '<?php echo esc_js( __( 'Menú sincronizado desde Google.', 'lealez' ) ); ?>', captureMenuSnapshot(), captureMenuSnapshot(), d);
                        // Recargar la página para mostrar las secciones/fotos actualizadas
                        setTimeout(function(){
                            window.location.reload();
                        }, 1200);
                    } else {
                        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'Error al sincronizar.', 'lealez' ) ); ?>';
                        $status.html('<span style="color:#c0392b;">' + $('<div>').text(msg).html() + '</span>');
                        $btn.prop('disabled', false).text('🔄 <?php echo esc_js( __( 'Sincronizar desde Google', 'lealez' ) ); ?>');
                    }
                })
                .fail(function(){
                    $status.html('<span style="color:#c0392b;"><?php echo esc_js( __( 'Error de red. Verifica la conexión e intenta de nuevo.', 'lealez' ) ); ?></span>');
                    $btn.prop('disabled', false).text('🔄 <?php echo esc_js( __( 'Sincronizar desde Google', 'lealez' ) ); ?>');
                });
            });

        })(jQuery);
        </script>
        <?php
    }

private function render_section_html( $sec_idx, $name, $items, $from_gmb = false, $catalog_type = '' ) {
    $item_count      = count( $items );
    $sec_placeholder = __( 'Nombre de la sección (ej: Sopas, Entradas, Postres...)', 'lealez' );
    $add_item_label  = __( 'Agregar un plato', 'lealez' );

    // Por ahora este metabox sigue siendo exclusivo para restaurante.
    // Se deja $catalog_type en la firma para mantener consistencia con las llamadas
    // y evitar errores al renderizar templates o futuras extensiones.
    unset( $catalog_type );
    ?>
    <div class="oy-menu-section" data-section-idx="<?php echo esc_attr( $sec_idx ); ?>">
        <div class="oy-menu-section-header">
            <span class="oy-section-handle" title="<?php esc_attr_e( 'Arrastrar para reordenar', 'lealez' ); ?>">⠿</span>
            <input type="text"
                   class="oy-section-name-input"
                   name="location_menu_sections[<?php echo esc_attr( $sec_idx ); ?>][name]"
                   value="<?php echo esc_attr( $name ); ?>"
                   placeholder="<?php echo esc_attr( $sec_placeholder ); ?>">
            <?php if ( $from_gmb ) : ?>
                <span class="oy-gmb-badge oy-gmb-badge-blue" style="margin-left:4px;">🔄 GMB</span>
            <?php endif; ?>
            <input type="hidden" name="location_menu_sections[<?php echo esc_attr( $sec_idx ); ?>][from_gmb]" value="<?php echo $from_gmb ? '1' : '0'; ?>">
            <span class="oy-menu-section-count">
                <?php echo sprintf( _n( '%d plato', '%d platos', $item_count, 'lealez' ), $item_count ); ?>
            </span>
            <button type="button" class="oy-section-toggle">▲</button>
            <button type="button" class="oy-section-remove" title="<?php esc_attr_e( 'Eliminar sección', 'lealez' ); ?>">×</button>
        </div>
        <div class="oy-menu-items-wrap">
            <?php foreach ( $items as $item_idx => $item ) :
                $item_name        = sanitize_text_field( $item['name'] ?? '' );
                $item_price       = sanitize_text_field( $item['price'] ?? '' );
                $item_desc        = sanitize_textarea_field( $item['description'] ?? '' );
                $item_img         = (int) ( $item['image_id'] ?? 0 );
                $item_img_url     = $item_img ? wp_get_attachment_image_url( $item_img, 'thumbnail' ) : '';
                $item_dietary     = is_array( $item['dietary'] ?? null ) ? $item['dietary'] : array();
                $item_gmb_img_url = (string) ( $item['gmb_image_url'] ?? '' );
                $item_media_keys  = is_array( $item['media_keys'] ?? null ) ? $item['media_keys'] : array();

                $this->render_item_html(
                    $sec_idx,
                    $item_idx,
                    $item_name,
                    $item_price,
                    $item_desc,
                    $item_img,
                    $item_img_url,
                    $item_dietary,
                    $item_gmb_img_url,
                    $item_media_keys
                );
            endforeach; ?>
            <button type="button" class="button button-small oy-add-item-btn">
                + <?php echo esc_html( $add_item_label ); ?>
            </button>
        </div>
    </div>
    <?php
}

/**
 * Render HTML for a single menu item (plato / dish).
 * Exclusivo para restaurantes: muestra restricciones alimentarias, no URL de producto.
 *
 * @param int|string $sec_idx       Índice de la sección
 * @param int|string $item_idx      Índice del item
 * @param string     $name          Nombre del plato
 * @param string     $price         Precio
 * @param string     $desc          Descripción
 * @param int        $image_id      WP Media Library ID (0 si no tiene)
 * @param string     $image_url     URL resuelta del WP Media Library
 * @param array      $dietary       Restricciones alimentarias
 * @param string     $gmb_image_url URL de foto desde Google My Business
 * @param array      $media_keys    mediaKeys de GMB (se preservan entre saves)
 */
private function render_item_html( $sec_idx, $item_idx, $name, $price, $desc, $image_id, $image_url, $dietary = array(), $gmb_image_url = '', $media_keys = array() ) {
    $base = "location_menu_sections[{$sec_idx}][items][{$item_idx}]";

    // Prioridad de imagen: 1) WP Media Library  2) gmb_image_url (foto de GMB)
    $display_url  = $image_url;
    $is_gmb_photo = false;
    if ( empty( $display_url ) && ! empty( $gmb_image_url ) ) {
        $display_url  = $gmb_image_url;
        $is_gmb_photo = true;
    }
    $has_image = ! empty( $display_url );
    ?>
    <div class="oy-menu-item">
        <div>
            <div class="oy-menu-item-image" title="<?php esc_attr_e( 'Clic para seleccionar imagen', 'lealez' ); ?>">
                <?php if ( $has_image ) : ?>
                    <img src="<?php echo esc_url( $display_url ); ?>" alt="">
                    <?php if ( $is_gmb_photo ) : ?>
                        <span class="oy-gmb-item-photo-badge" title="<?php esc_attr_e( 'Foto sincronizada desde Google My Business', 'lealez' ); ?>" style="position:absolute;top:2px;left:2px;font-size:9px;background:rgba(66,133,244,0.85);color:#fff;padding:1px 4px;border-radius:3px;line-height:1.4;pointer-events:none;">🔄 GMB</span>
                    <?php endif; ?>
                <?php else : ?>
                    <div class="oy-img-placeholder">🍽️<br><small style="font-size:10px;"><?php _e( 'Imagen', 'lealez' ); ?></small></div>
                <?php endif; ?>
            </div>
            <?php /* WP Media Library ID — editable por el usuario */ ?>
            <input type="hidden"
                   class="oy-item-image-id"
                   name="<?php echo esc_attr( $base ); ?>[image_id]"
                   value="<?php echo esc_attr( $image_id ?: '' ); ?>">
            <?php /* gmb_image_url — readonly, se preserva para no perder la foto de GMB */ ?>
            <input type="hidden"
                   name="<?php echo esc_attr( $base ); ?>[gmb_image_url]"
                   value="<?php echo esc_attr( $gmb_image_url ); ?>">
            <?php /* media_keys — readonly, se preservan para futuras re-sincronizaciones */ ?>
            <input type="hidden"
                   name="<?php echo esc_attr( $base ); ?>[media_keys]"
                   value="<?php echo esc_attr( wp_json_encode( $media_keys ) ); ?>">
        </div>
        <div class="oy-menu-item-fields">
            <div class="oy-menu-item-row">
                <input type="text"
                       name="<?php echo esc_attr( $base ); ?>[name]"
                       value="<?php echo esc_attr( $name ); ?>"
                       placeholder="<?php esc_attr_e( 'Nombre del plato*', 'lealez' ); ?>"
                       class="regular-text"
                       style="flex:1;max-width:280px;"
                       maxlength="140">
                <input type="text"
                       name="<?php echo esc_attr( $base ); ?>[price]"
                       value="<?php echo esc_attr( $price ); ?>"
                       placeholder="<?php esc_attr_e( 'Precio', 'lealez' ); ?>"
                       style="max-width:140px;">
            </div>
            <textarea name="<?php echo esc_attr( $base ); ?>[description]"
                      placeholder="<?php esc_attr_e( 'Descripción del plato (opcional, máx 1000 caracteres)', 'lealez' ); ?>"
                      maxlength="1000"><?php echo esc_textarea( $desc ); ?></textarea>

            <?php /* Restricciones alimentarias — siempre visibles en metabox de restaurante */ ?>
            <div class="oy-dietary-badges">
                <span style="font-size:11px;color:#888;align-self:center;"><?php _e( 'Restricciones:', 'lealez' ); ?></span>
                <?php
                $dietary_options = array(
                    'vegetarian'  => __( '🥦 Vegetariano', 'lealez' ),
                    'vegan'       => __( '🌱 Vegano', 'lealez' ),
                    'gluten_free' => __( '🚫🌾 Sin gluten', 'lealez' ),
                    'halal'       => __( '🌙 Halal', 'lealez' ),
                );
                foreach ( $dietary_options as $key => $label ) :
                    $checked = in_array( $key, $dietary, true );
                    ?>
                    <label class="oy-dietary-badge<?php echo $checked ? ' checked' : ''; ?>">
                        <input type="checkbox"
                               name="<?php echo esc_attr( $base ); ?>[dietary][]"
                               value="<?php echo esc_attr( $key ); ?>"
                               <?php checked( $checked ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="oy-menu-item-actions">
            <button type="button" class="oy-item-remove" title="<?php esc_attr_e( 'Eliminar plato', 'lealez' ); ?>">×</button>
        </div>
    </div>
    <?php
}

    /**
     * Render HTML for a featured/highlighted dish
     *
     * @param int|string $feat_idx
     * @param string     $name
     * @param string     $price
     * @param string     $desc
     * @param int        $image_id
     * @param string     $image_url
     */
    private function render_featured_item_html( $feat_idx, $name, $price, $desc, $image_id, $image_url ) {
        $base = "location_menu_featured_items[{$feat_idx}]";
        $has_image = ! empty( $image_url );
        ?>
        <div class="oy-featured-item">
            <div>
                <div class="oy-featured-item-image" style="
                    width:80px;height:80px;border:2px dashed #ccc;border-radius:5px;
                    display:flex;align-items:center;justify-content:center;
                    cursor:pointer;overflow:hidden;background:#f0f0f0;" title="<?php esc_attr_e( 'Clic para seleccionar imagen', 'lealez' ); ?>">
                    <?php if ( $has_image ) : ?>
                        <img src="<?php echo esc_url( $image_url ); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                    <?php else : ?>
                        <div style="color:#aaa;font-size:24px;text-align:center;">⭐<br><small style="font-size:10px;"><?php _e( 'Imagen', 'lealez' ); ?></small></div>
                    <?php endif; ?>
                </div>
                <input type="hidden"
                       class="oy-featured-image-id"
                       name="<?php echo esc_attr( $base ); ?>[image_id]"
                       value="<?php echo esc_attr( $image_id ?: '' ); ?>">
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;">
                <div style="display:flex;gap:8px;">
                    <input type="text"
                           name="<?php echo esc_attr( $base ); ?>[name]"
                           value="<?php echo esc_attr( $name ); ?>"
                           placeholder="<?php esc_attr_e( 'Nombre del producto / plato*', 'lealez' ); ?>"
                           class="regular-text"
                           style="flex:1;max-width:280px;"
                           maxlength="140">
                    <input type="text"
                           name="<?php echo esc_attr( $base ); ?>[price]"
                           value="<?php echo esc_attr( $price ); ?>"
                           placeholder="<?php esc_attr_e( 'Precio', 'lealez' ); ?>"
                           style="max-width:120px;">
                </div>
                <textarea name="<?php echo esc_attr( $base ); ?>[description]"
                          placeholder="<?php esc_attr_e( 'Descripción breve del producto', 'lealez' ); ?>"
                          style="width:100%;resize:vertical;min-height:54px;font-size:12px;"
                          maxlength="1000"><?php echo esc_textarea( $desc ); ?></textarea>
            </div>
            <div>
                <button type="button" class="oy-featured-remove" style="color:#dc3232;cursor:pointer;background:none;border:none;font-size:18px;font-weight:bold;" title="<?php esc_attr_e( 'Eliminar plato destacado', 'lealez' ); ?>">×</button>
            </div>
        </div>
        <?php
    }

/**
 * Renderiza el panel superior del flujo de edición/local publish para el Menú.
 *
 * @param int       $post_id
 * @param bool|null $gmb_connected
 * @return string
 */
private function render_menu_action_panel( $post_id, $gmb_connected = null ) {
    $post_id = absint( $post_id );

    if ( null === $gmb_connected ) {
        $ctx           = $this->resolve_gmb_context( $post_id );
        $gmb_connected = ! empty( $ctx['connected'] );
    }

    $job           = get_post_meta( $post_id, 'gmb_menu_push_job', true );
    $pending       = (bool) get_post_meta( $post_id, 'oy_menu_local_pending_publish', true );
    $last_save     = get_post_meta( $post_id, 'oy_menu_last_manual_save', true );
    $last_sync     = (int) get_post_meta( $post_id, 'gmb_food_menus_last_sync', true );
    $status        = is_array( $job ) ? (string) ( $job['status'] ?? '' ) : '';
    $message       = is_array( $job ) ? (string) ( $job['message'] ?? '' ) : '';
    $updated_at    = is_array( $job ) ? (string) ( $job['updated_at_label'] ?? ( $job['pushed_at_label'] ?? '' ) ) : '';
    $history       = is_array( $job ) && is_array( $job['history'] ?? null ) ? $job['history'] : array();
    $history_count = count( $history );

    $state_class = 'oy-menu-state-pill';
    $state_label = __( 'Sin cambios pendientes', 'lealez' );

    if ( $pending ) {
        $state_class .= ' oy-menu-state-pending';
        $state_label  = __( 'Pendiente de enviar a GMB', 'lealez' );
    }

    if ( 'applied' === $status ) {
        $state_class .= ' oy-menu-state-applied';
        $state_label  = __( 'Aplicado en GMB', 'lealez' );
    } elseif ( in_array( $status, array( 'error', 'failed', 'validation_error' ), true ) ) {
        $state_class .= ' oy-menu-state-error';
        $state_label  = __( 'Error en publicación GMB', 'lealez' );
    } elseif ( in_array( $status, array( 'pending_verification', 'queued', 'media_only_pushed' ), true ) ) {
        $state_class .= ' oy-menu-state-pending';
        $state_label  = __( 'Enviado, pendiente de verificar', 'lealez' );
    }

    $save_nonce  = wp_create_nonce( 'oy_save_menu_metabox_' . $post_id );
    $push_nonce  = wp_create_nonce( 'oy_push_menu_to_gmb_' . $post_id );
    $check_nonce = wp_create_nonce( 'oy_check_menu_push_status_' . $post_id );

    ob_start();
    ?>
    <div id="oy-menu-action-panel"
         class="oy-menu-action-panel"
         data-post-id="<?php echo esc_attr( $post_id ); ?>"
         data-save-nonce="<?php echo esc_attr( $save_nonce ); ?>"
         data-push-nonce="<?php echo esc_attr( $push_nonce ); ?>"
         data-check-nonce="<?php echo esc_attr( $check_nonce ); ?>"
         data-connected="<?php echo $gmb_connected ? '1' : '0'; ?>"
         data-pending="<?php echo $pending ? '1' : '0'; ?>">
        <div class="oy-menu-action-row">
            <span class="oy-menu-action-title">✏️ <?php esc_html_e( 'Flujo de edición del menú', 'lealez' ); ?></span>
            <span class="<?php echo esc_attr( $state_class ); ?>"><?php echo esc_html( $state_label ); ?></span>

            <button type="button" class="button" id="oy-menu-edit-btn">✏️ <?php esc_html_e( 'Editar menú', 'lealez' ); ?></button>
            <button type="button" class="button button-primary" id="oy-menu-save-local-btn" disabled>💾 <?php esc_html_e( 'Guardar cambios locales', 'lealez' ); ?></button>
            <button type="button" class="button" id="oy-menu-cancel-edit-btn" disabled>↩ <?php esc_html_e( 'Cancelar edición', 'lealez' ); ?></button>
            <button type="button" class="button button-secondary" id="oy-menu-push-gmb-btn" <?php disabled( ! $gmb_connected ); ?>>☁️ <?php esc_html_e( 'Enviar a GMB', 'lealez' ); ?></button>
            <button type="button" class="button button-secondary" id="oy-menu-check-gmb-btn" <?php disabled( ! $gmb_connected ); ?>>✅ <?php esc_html_e( 'Verificar estado', 'lealez' ); ?></button>
            <button type="button" class="button-link oy-menu-log-toggle" id="oy-menu-toggle-log">🧾 <?php esc_html_e( 'Ver log local', 'lealez' ); ?><?php echo $history_count ? ' (' . esc_html( (string) $history_count ) . ')' : ''; ?></button>
            <span class="oy-menu-inline-status" id="oy-menu-inline-status"></span>
        </div>

        <p class="oy-menu-action-help">
            <?php
            if ( ! $gmb_connected ) {
                esc_html_e( 'Conecta primero esta ubicación a Google Business Profile para enviar o verificar cambios del menú.', 'lealez' );
            } elseif ( $pending ) {
                esc_html_e( 'Hay cambios locales guardados que todavía no han sido confirmados como aplicados en Google.', 'lealez' );
            } else {
                esc_html_e( 'Edita el menú, guarda los cambios locales y luego envíalos a Google Business Profile. La sincronización desde Google sigue disponible y puede sobrescribir cambios locales.', 'lealez' );
            }

            if ( $last_save && is_array( $last_save ) && ! empty( $last_save['at_label'] ) ) {
                echo ' ' . esc_html__( 'Último guardado local:', 'lealez' ) . ' ' . esc_html( (string) $last_save['at_label'] ) . '.';
            }
            if ( $last_sync ) {
                echo ' ' . esc_html__( 'Última sincronización GMB:', 'lealez' ) . ' ' . esc_html( date_i18n( 'd/m/Y H:i', $last_sync ) ) . '.';
            }
            if ( $message ) {
                echo ' ' . esc_html__( 'Último estado:', 'lealez' ) . ' ' . esc_html( $message );
            }
            if ( $updated_at ) {
                echo ' (' . esc_html( $updated_at ) . ').';
            }
            ?>
        </p>

        <div class="oy-menu-log-panel" id="oy-menu-log-panel" aria-live="polite">
            <?php if ( empty( $history ) ) : ?>
                <div class="oy-menu-log-entry"><strong><?php esc_html_e( 'Sin eventos guardados todavía.', 'lealez' ); ?></strong></div>
            <?php else : ?>
                <?php foreach ( array_reverse( array_slice( $history, -10 ) ) as $entry ) : ?>
                    <div class="oy-menu-log-entry">
                        <strong><?php echo esc_html( (string) ( $entry['event'] ?? 'evento' ) ); ?></strong>
                        <small> — <?php echo esc_html( (string) ( $entry['at_label'] ?? '' ) ); ?></small><br>
                        <?php if ( ! empty( $entry['message'] ) ) : ?>
                            <span><?php echo esc_html( (string) $entry['message'] ); ?></span><br>
                        <?php endif; ?>
                        <?php if ( ! empty( $entry['summary'] ) && is_array( $entry['summary'] ) ) : ?>
                            <small><?php echo esc_html( wp_json_encode( $entry['summary'] ) ); ?></small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * AJAX: Guarda el metabox localmente sin publicar el post completo.
 */
public function ajax_save_menu_metabox() {
    $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
    $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

    if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_save_menu_metabox_' . $post_id ) ) {
        wp_send_json_error( array( 'message' => __( 'Nonce inválido para guardar el menú.', 'lealez' ) ) );
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => __( 'Sin permisos para editar esta ubicación.', 'lealez' ) ) );
    }

    $post = get_post( $post_id );
    if ( ! $post || $this->post_type !== $post->post_type ) {
        wp_send_json_error( array( 'message' => __( 'La ubicación indicada no existe.', 'lealez' ) ) );
    }

    $payload = $this->sanitize_menu_payload_from_array( $_POST );
    $result  = $this->persist_menu_payload( $post_id, $payload, 'ajax_local_save' );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    wp_send_json_success( array(
        'message'    => __( 'Cambios locales del menú guardados en Lealez.', 'lealez' ),
        'snapshot'   => $this->build_menu_log_snapshot_from_payload( $payload ),
        'panel_html' => $this->render_menu_action_panel( $post_id ),
    ) );
}

/**
 * AJAX: Envía el menú local guardado a Google Business Profile.
 */
public function ajax_push_menu_to_gmb() {
    $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
    $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

    if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_push_menu_to_gmb_' . $post_id ) ) {
        wp_send_json_error( array( 'message' => __( 'Nonce inválido para enviar el menú a GMB.', 'lealez' ) ) );
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => __( 'Sin permisos para publicar cambios de esta ubicación.', 'lealez' ) ) );
    }

    $result = $this->push_menu_to_gmb( $post_id, 'manual_push' );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array(
            'message'    => $result->get_error_message(),
            'panel_html' => $this->render_menu_action_panel( $post_id ),
            'error_data' => $result->get_error_data(),
        ) );
    }

    wp_send_json_success( array(
        'message'    => (string) ( $result['message'] ?? __( 'Solicitud enviada a Google Business Profile.', 'lealez' ) ),
        'snapshot'   => $result['snapshot'] ?? array(),
        'panel_html' => $this->render_menu_action_panel( $post_id ),
        'result'     => $result,
    ) );
}

/**
 * AJAX: verifica si Google ya refleja el último envío del menú.
 */
public function ajax_check_menu_push_status() {
    $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
    $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

    if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_check_menu_push_status_' . $post_id ) ) {
        wp_send_json_error( array( 'message' => __( 'Nonce inválido para verificar el menú.', 'lealez' ) ) );
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => __( 'Sin permisos para verificar esta ubicación.', 'lealez' ) ) );
    }

    $result = $this->check_menu_push_status( $post_id, 'manual_check' );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array(
            'message'    => $result->get_error_message(),
            'panel_html' => $this->render_menu_action_panel( $post_id ),
            'error_data' => $result->get_error_data(),
        ) );
    }

    wp_send_json_success( array(
        'message'    => (string) ( $result['message'] ?? __( 'Verificación completada.', 'lealez' ) ),
        'applied'    => ! empty( $result['applied'] ),
        'panel_html' => $this->render_menu_action_panel( $post_id ),
        'result'     => $result,
    ) );
}

/**
 * Cron: poll de verificación diferida.
 *
 * @param int $post_id
 */
public static function cron_poll_menu_push_status( $post_id ) {
    $instance = new self();
    $instance->check_menu_push_status( absint( $post_id ), 'cron_poll' );
}

/**
 * Save metabox data.
 *
 * @param int     $post_id
 * @param WP_Post $post
 */
public function save_meta_box( $post_id, $post ) {
    if ( ! isset( $_POST[ $this->nonce_name ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ $this->nonce_name ] ), $this->nonce_action ) ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    if ( ! $post || $this->post_type !== $post->post_type ) {
        return;
    }

    $payload = $this->sanitize_menu_payload_from_array( $_POST );
    $this->persist_menu_payload( $post_id, $payload, 'post_update_save' );
}

/**
 * Sanitiza payload del metabox desde POST o desde un array equivalente.
 *
 * @param array $source
 * @return array
 */
private function sanitize_menu_payload_from_array( array $source ) {
    $payload = array(
        'location_menu_url'            => '',
        'location_menu_pdf_id'         => 0,
        'location_menu_photos'         => array(),
        'location_menu_sections'       => array(),
        'location_menu_featured_items' => array(),
    );

    if ( isset( $source['location_menu_url'] ) ) {
        $payload['location_menu_url'] = esc_url_raw( wp_unslash( $source['location_menu_url'] ) );
    }

    if ( isset( $source['location_menu_pdf_id'] ) ) {
        $payload['location_menu_pdf_id'] = absint( wp_unslash( $source['location_menu_pdf_id'] ) );
    }

    if ( isset( $source['location_menu_photos'] ) && is_array( $source['location_menu_photos'] ) ) {
        foreach ( wp_unslash( $source['location_menu_photos'] ) as $photo_id ) {
            $photo_id = absint( $photo_id );
            if ( $photo_id > 0 ) {
                $payload['location_menu_photos'][] = $photo_id;
            }
        }
        $payload['location_menu_photos'] = array_values( array_unique( $payload['location_menu_photos'] ) );
    }

    $sections_raw = isset( $source['location_menu_sections'] ) && is_array( $source['location_menu_sections'] )
        ? wp_unslash( $source['location_menu_sections'] )
        : array();

    $valid_dietary = array( 'vegetarian', 'vegan', 'gluten_free', 'halal', 'kosher', 'organic' );

    foreach ( $sections_raw as $section ) {
        if ( ! is_array( $section ) ) {
            continue;
        }

        $sec_name = sanitize_text_field( (string) ( $section['name'] ?? '' ) );
        if ( '' === $sec_name ) {
            continue;
        }

        $items_raw   = is_array( $section['items'] ?? null ) ? $section['items'] : array();
        $items_clean = array();

        foreach ( $items_raw as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $item_name = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
            if ( '' === $item_name ) {
                continue;
            }

            $dietary_raw = is_array( $item['dietary'] ?? null ) ? $item['dietary'] : array();
            $dietary     = array();
            foreach ( $dietary_raw as $dr ) {
                $dr = sanitize_key( (string) $dr );
                if ( in_array( $dr, $valid_dietary, true ) && ! in_array( $dr, $dietary, true ) ) {
                    $dietary[] = $dr;
                }
            }

            $media_keys_raw = $item['media_keys'] ?? array();
            if ( is_string( $media_keys_raw ) ) {
                $decoded = json_decode( wp_unslash( $media_keys_raw ), true );
                $media_keys_raw = is_array( $decoded ) ? $decoded : array();
            }
            $media_keys = array();
            if ( is_array( $media_keys_raw ) ) {
                foreach ( $media_keys_raw as $mk ) {
                    $mk = sanitize_text_field( (string) $mk );
                    if ( '' !== $mk && ! in_array( $mk, $media_keys, true ) ) {
                        $media_keys[] = $mk;
                    }
                }
            }

            $items_clean[] = array(
                'name'          => $item_name,
                'price'         => sanitize_text_field( (string) ( $item['price'] ?? '' ) ),
                'description'   => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
                'image_id'      => absint( $item['image_id'] ?? 0 ),
                'dietary'       => $dietary,
                'gmb_image_url' => esc_url_raw( (string) ( $item['gmb_image_url'] ?? '' ) ),
                'media_keys'    => $media_keys,
                'from_gmb'      => ! empty( $item['from_gmb'] ) ? 1 : 0,
            );
        }

        $payload['location_menu_sections'][] = array(
            'name'     => $sec_name,
            'items'    => $items_clean,
            'from_gmb' => ! empty( $section['from_gmb'] ) ? 1 : 0,
        );
    }

    $featured_raw = isset( $source['location_menu_featured_items'] ) && is_array( $source['location_menu_featured_items'] )
        ? wp_unslash( $source['location_menu_featured_items'] )
        : array();

    foreach ( $featured_raw as $feat ) {
        if ( ! is_array( $feat ) ) {
            continue;
        }
        $feat_name = sanitize_text_field( (string) ( $feat['name'] ?? '' ) );
        if ( '' === $feat_name ) {
            continue;
        }
        $payload['location_menu_featured_items'][] = array(
            'name'        => $feat_name,
            'price'       => sanitize_text_field( (string) ( $feat['price'] ?? '' ) ),
            'description' => sanitize_textarea_field( (string) ( $feat['description'] ?? '' ) ),
            'image_id'    => absint( $feat['image_id'] ?? 0 ),
        );
    }

    return $payload;
}

/**
 * Persiste el payload local y marca pendiente de publicación GMB.
 *
 * @param int    $post_id
 * @param array  $payload
 * @param string $source
 * @return true|WP_Error
 */
private function persist_menu_payload( $post_id, array $payload, $source = 'local_save' ) {
    $post_id = absint( $post_id );
    if ( ! $post_id ) {
        return new WP_Error( 'invalid_post', __( 'Ubicación inválida.', 'lealez' ) );
    }

    if ( '' !== $payload['location_menu_url'] ) {
        update_post_meta( $post_id, 'location_menu_url', $payload['location_menu_url'] );
    } else {
        delete_post_meta( $post_id, 'location_menu_url' );
    }

    if ( $payload['location_menu_pdf_id'] > 0 ) {
        update_post_meta( $post_id, 'location_menu_pdf_id', $payload['location_menu_pdf_id'] );
    } else {
        delete_post_meta( $post_id, 'location_menu_pdf_id' );
    }

    update_post_meta( $post_id, 'location_menu_photos', $payload['location_menu_photos'] );
    update_post_meta( $post_id, 'location_menu_sections', $payload['location_menu_sections'] );
    update_post_meta( $post_id, 'location_menu_featured_items', $payload['location_menu_featured_items'] );

    $now = time();
    update_post_meta( $post_id, 'oy_menu_local_pending_publish', 1 );
    update_post_meta( $post_id, 'oy_menu_last_manual_save', array(
        'at'         => current_time( 'mysql' ),
        'at_ts'      => $now,
        'at_label'   => date_i18n( 'd/m/Y H:i:s', $now ),
        'source'     => sanitize_key( (string) $source ),
        'user_id'    => get_current_user_id(),
        'user_label' => $this->get_admin_user_label(),
        'summary'    => $this->build_menu_log_snapshot_from_payload( $payload ),
    ) );

    $this->append_menu_job_history( $post_id, 'local_metabox_save', __( 'Cambios locales guardados en Lealez.', 'lealez' ), $this->build_menu_log_snapshot_from_payload( $payload ), array(
        'status' => 'local_pending',
    ) );

    return true;
}

/**
 * Lee el payload actual desde post meta.
 *
 * @param int $post_id
 * @return array
 */
private function get_menu_payload_from_meta( $post_id ) {
    $payload = array(
        'location_menu_url'            => (string) get_post_meta( $post_id, 'location_menu_url', true ),
        'location_menu_pdf_id'         => (int) get_post_meta( $post_id, 'location_menu_pdf_id', true ),
        'location_menu_photos'         => get_post_meta( $post_id, 'location_menu_photos', true ),
        'location_menu_sections'       => get_post_meta( $post_id, 'location_menu_sections', true ),
        'location_menu_featured_items' => get_post_meta( $post_id, 'location_menu_featured_items', true ),
    );

    if ( ! is_array( $payload['location_menu_photos'] ) ) {
        $payload['location_menu_photos'] = array();
    }
    if ( ! is_array( $payload['location_menu_sections'] ) ) {
        $payload['location_menu_sections'] = array();
    }
    if ( ! is_array( $payload['location_menu_featured_items'] ) ) {
        $payload['location_menu_featured_items'] = array();
    }

    // Reusar sanitización para dejar una forma consistente.
    return $this->sanitize_menu_payload_from_array( array(
        'location_menu_url'            => $payload['location_menu_url'],
        'location_menu_pdf_id'         => $payload['location_menu_pdf_id'],
        'location_menu_photos'         => $payload['location_menu_photos'],
        'location_menu_sections'       => $payload['location_menu_sections'],
        'location_menu_featured_items' => $payload['location_menu_featured_items'],
    ) );
}

/**
 * Crea snapshot compacto para logs.
 *
 * @param array $payload
 * @return array
 */
private function build_menu_log_snapshot_from_payload( array $payload ) {
    $sections = is_array( $payload['location_menu_sections'] ?? null ) ? $payload['location_menu_sections'] : array();
    $featured = is_array( $payload['location_menu_featured_items'] ?? null ) ? $payload['location_menu_featured_items'] : array();
    $photos   = is_array( $payload['location_menu_photos'] ?? null ) ? $payload['location_menu_photos'] : array();
    $items    = 0;

    foreach ( $sections as $section ) {
        $items += is_array( $section['items'] ?? null ) ? count( $section['items'] ) : 0;
    }

    return array(
        'menu_url'       => (string) ( $payload['location_menu_url'] ?? '' ),
        'pdf_id'         => (int) ( $payload['location_menu_pdf_id'] ?? 0 ),
        'photos_count'   => count( $photos ),
        'sections_count' => count( $sections ),
        'items_count'    => $items,
        'featured_count' => count( $featured ),
    );
}

/**
 * Envía menú a GMB.
 *
 * @param int    $post_id
 * @param string $source
 * @return array|WP_Error
 */
private function push_menu_to_gmb( $post_id, $source = 'manual_push' ) {
    $post_id = absint( $post_id );

    $ctx = $this->resolve_gmb_context( $post_id );
    if ( empty( $ctx['connected'] ) ) {
        $err = new WP_Error( 'gmb_not_connected', __( 'La ubicación no tiene conexión GMB completa para publicar el menú.', 'lealez' ), $ctx );
        $this->append_menu_job_history( $post_id, 'push_error', $err->get_error_message(), array(), array( 'status' => 'error' ) );
        return $err;
    }

    if ( ! class_exists( 'Lealez_GMB_API' ) ) {
        return new WP_Error( 'api_missing', __( 'Lealez_GMB_API no está disponible.', 'lealez' ) );
    }

    $payload  = $this->get_menu_payload_from_meta( $post_id );
    $snapshot = $this->build_menu_log_snapshot_from_payload( $payload );

    // Validar estructura/precios antes de subir fotos para evitar crear MediaItems si el menú no puede publicarse.
    $prebuild = $this->build_food_menus_payload_for_gmb( $post_id, $ctx, $payload );
    if ( is_wp_error( $prebuild ) ) {
        update_post_meta( $post_id, 'gmb_menu_push_job', array(
            'status'           => 'validation_error',
            'message'          => $prebuild->get_error_message(),
            'updated_at'       => current_time( 'mysql' ),
            'updated_at_ts'    => time(),
            'updated_at_label' => date_i18n( 'd/m/Y H:i:s', time() ),
            'last_error_data'  => $prebuild->get_error_data(),
            'history'          => $this->append_menu_job_history( $post_id, 'validation_error', $prebuild->get_error_message(), $snapshot, array( 'status' => 'validation_error' ), false ),
        ) );
        return $prebuild;
    }

    $media_result = $this->upload_menu_media_assets( $post_id, $ctx, $payload );

    if ( is_wp_error( $media_result ) ) {
        $this->append_menu_job_history( $post_id, 'push_error', $media_result->get_error_message(), $snapshot, array( 'status' => 'error' ) );
        return $media_result;
    }

    $payload = $media_result['payload'];
    if ( ! empty( $media_result['changed'] ) ) {
        update_post_meta( $post_id, 'location_menu_sections', $payload['location_menu_sections'] );
    }

    $build = $this->build_food_menus_payload_for_gmb( $post_id, $ctx, $payload );
    if ( is_wp_error( $build ) ) {
        update_post_meta( $post_id, 'gmb_menu_push_job', array(
            'status'           => 'validation_error',
            'message'          => $build->get_error_message(),
            'updated_at'       => current_time( 'mysql' ),
            'updated_at_ts'    => time(),
            'updated_at_label' => date_i18n( 'd/m/Y H:i:s', time() ),
            'last_error_data'  => $build->get_error_data(),
            'history'          => $this->append_menu_job_history( $post_id, 'validation_error', $build->get_error_message(), $snapshot, array( 'status' => 'validation_error' ), false ),
        ) );
        return $build;
    }

    $api_result = Lealez_GMB_API::update_location_food_menus(
        (int) $ctx['business_id'],
        (string) $ctx['account_id'],
        (string) $ctx['location_id'],
        $build['foodMenus'],
        'menus'
    );

    if ( is_wp_error( $api_result ) ) {
        $this->append_menu_job_history( $post_id, 'push_error', $api_result->get_error_message(), $snapshot, array(
            'status'     => 'error',
            'error_code' => $api_result->get_error_code(),
        ) );
        return $api_result;
    }

    $now = time();
    $job = get_post_meta( $post_id, 'gmb_menu_push_job', true );
    if ( ! is_array( $job ) ) {
        $job = array( 'history' => array() );
    }

    $history = $this->append_menu_job_history( $post_id, 'push_to_gmb', __( 'Menú enviado a Google Business Profile; pendiente de verificación.', 'lealez' ), $snapshot, array(
        'status'         => 'pending_verification',
        'uploaded_media' => $media_result['uploaded_count'] ?? 0,
        'reused_media'   => $media_result['reused_count'] ?? 0,
    ), false );

    $job = array_merge( $job, array(
        'status'             => 'pending_verification',
        'message'            => __( 'Menú enviado a GMB. Verifica en unos minutos si Google ya refleja los cambios.', 'lealez' ),
        'pushed_at'          => current_time( 'mysql' ),
        'pushed_at_ts'       => $now,
        'pushed_at_label'    => date_i18n( 'd/m/Y H:i:s', $now ),
        'updated_at'         => current_time( 'mysql' ),
        'updated_at_ts'      => $now,
        'updated_at_label'   => date_i18n( 'd/m/Y H:i:s', $now ),
        'pushed_by_user_id'  => get_current_user_id(),
        'pushed_by_label'    => $this->get_admin_user_label(),
        'source'             => sanitize_key( (string) $source ),
        'submitted_payload'  => $build['foodMenus'],
        'normalized_payload' => $this->normalize_food_menus_for_compare( $build['foodMenus'] ),
        'api_response'       => $api_result,
        'snapshot'           => $snapshot,
        'notes'              => $build['notes'],
        'history'            => $history,
    ) );

    update_post_meta( $post_id, 'gmb_menu_push_job', $job );
    update_post_meta( $post_id, 'oy_menu_local_pending_publish', 1 );

    if ( ! wp_next_scheduled( 'oy_poll_menu_push_status', array( $post_id ) ) ) {
        wp_schedule_single_event( time() + 120, 'oy_poll_menu_push_status', array( $post_id ) );
    }

    return array(
        'message'        => __( 'Menú enviado a GMB. Se dejó pendiente de verificación para no asumir propagación inmediata.', 'lealez' ),
        'snapshot'       => $snapshot,
        'api_response'   => $api_result,
        'uploaded_media' => $media_result['uploaded_count'] ?? 0,
        'reused_media'   => $media_result['reused_count'] ?? 0,
        'notes'          => $build['notes'],
    );
}

/**
 * Verifica último envío contra el estado actual de GMB.
 *
 * @param int    $post_id
 * @param string $source
 * @return array|WP_Error
 */
private function check_menu_push_status( $post_id, $source = 'manual_check' ) {
    $post_id = absint( $post_id );
    $ctx     = $this->resolve_gmb_context( $post_id );

    if ( empty( $ctx['connected'] ) ) {
        return new WP_Error( 'gmb_not_connected', __( 'La ubicación no tiene conexión GMB completa para verificar el menú.', 'lealez' ), $ctx );
    }

    $job = get_post_meta( $post_id, 'gmb_menu_push_job', true );
    if ( ! is_array( $job ) || empty( $job['normalized_payload'] ) ) {
        return new WP_Error( 'missing_push_job', __( 'No hay un envío de menú pendiente para verificar.', 'lealez' ) );
    }

    if ( ! class_exists( 'Lealez_GMB_API' ) ) {
        return new WP_Error( 'api_missing', __( 'Lealez_GMB_API no está disponible.', 'lealez' ) );
    }

    $gmb_result = Lealez_GMB_API::get_location_food_menus(
        (int) $ctx['business_id'],
        (string) $ctx['account_id'],
        (string) $ctx['location_id'],
        true
    );

    if ( is_wp_error( $gmb_result ) ) {
        $this->append_menu_job_history( $post_id, 'check_error', $gmb_result->get_error_message(), array(), array(
            'status'     => 'pending_verification',
            'error_code' => $gmb_result->get_error_code(),
        ) );
        return $gmb_result;
    }

    $current_norm = $this->normalize_food_menus_for_compare( is_array( $gmb_result ) ? $gmb_result : array() );
    $sent_norm    = is_array( $job['normalized_payload'] ?? null ) ? $job['normalized_payload'] : array();
    $applied      = $this->compare_food_menus_payloads( $sent_norm, $current_norm );
    $now          = time();

    $job['last_check_at']       = current_time( 'mysql' );
    $job['last_check_at_ts']    = $now;
    $job['last_check_at_label'] = date_i18n( 'd/m/Y H:i:s', $now );
    $job['updated_at']          = current_time( 'mysql' );
    $job['updated_at_ts']       = $now;
    $job['updated_at_label']    = date_i18n( 'd/m/Y H:i:s', $now );
    $job['last_gmb_snapshot']   = $current_norm;

    if ( $applied ) {
        $job['status']  = 'applied';
        $job['message'] = __( 'Google ya refleja el menú enviado desde Lealez.', 'lealez' );
        delete_post_meta( $post_id, 'oy_menu_local_pending_publish' );
        update_post_meta( $post_id, 'gmb_food_menus_raw', $gmb_result );
        update_post_meta( $post_id, 'gmb_food_menus_last_sync', $now );
        $job['history'] = $this->append_menu_job_history( $post_id, 'check_applied', $job['message'], array(), array( 'status' => 'applied', 'source' => $source ), false );
    } else {
        $job['status']  = 'pending_verification';
        $job['message'] = __( 'Google respondió, pero todavía no coincide completamente con el último envío. Puede estar en propagación o revisión.', 'lealez' );
        update_post_meta( $post_id, 'oy_menu_local_pending_publish', 1 );
        $job['history'] = $this->append_menu_job_history( $post_id, 'check_pending', $job['message'], array(), array( 'status' => 'pending_verification', 'source' => $source ), false );
    }

    update_post_meta( $post_id, 'gmb_menu_push_job', $job );

    return array(
        'applied' => $applied,
        'message' => $job['message'],
        'status'  => $job['status'],
    );
}

/**
 * Resuelve datos de conexión GMB desde el post.
 *
 * @param int $post_id
 * @return array
 */
private function resolve_gmb_context( $post_id ) {
    $post_id       = absint( $post_id );
    $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
    $account_id    = trim( (string) get_post_meta( $post_id, 'gmb_account_id', true ) );
    $location_id   = trim( (string) get_post_meta( $post_id, 'gmb_location_id', true ) );
    $location_name = trim( (string) get_post_meta( $post_id, 'gmb_location_name', true ) );

    if ( '' === $location_id && '' !== $location_name ) {
        $location_id = $this->extract_location_id_from_any_local( $location_name );
    }
    if ( '' === $account_id && '' !== $location_name ) {
        $account_id = $this->extract_account_id_from_location_name_local( $location_name );
    }
    $account_id  = $this->extract_account_id_from_any_local( $account_id );
    $location_id = $this->extract_location_id_from_any_local( $location_id );

    return array(
        'post_id'       => $post_id,
        'business_id'   => $business_id,
        'account_id'    => $account_id,
        'location_id'   => $location_id,
        'location_name' => $location_name,
        'connected'     => ( $business_id > 0 && '' !== $account_id && '' !== $location_id ),
    );
}

private function extract_account_id_from_location_name_local( $location_name ) {
    $v = trim( (string) $location_name, '/' );
    if ( preg_match( '#accounts/([^/]+)/locations/#', $v, $m ) ) {
        return trim( (string) $m[1], '/' );
    }
    return '';
}

private function extract_account_id_from_any_local( $account ) {
    $v = trim( (string) $account, '/' );
    if ( '' === $v ) {
        return '';
    }
    if ( preg_match( '#accounts/([^/]+)#', $v, $m ) ) {
        return trim( (string) $m[1], '/' );
    }
    $parts = explode( '/', $v );
    return trim( (string) end( $parts ), '/' );
}

private function extract_location_id_from_any_local( $location ) {
    $v = trim( (string) $location, '/' );
    if ( '' === $v ) {
        return '';
    }
    if ( preg_match( '#locations/([^/]+)#', $v, $m ) ) {
        return trim( (string) $m[1], '/' );
    }
    $parts = explode( '/', $v );
    return trim( (string) end( $parts ), '/' );
}

/**
 * Agrega evento al historial del job.
 *
 * @param int    $post_id
 * @param string $event
 * @param string $message
 * @param array  $summary
 * @param array  $extra
 * @param bool   $persist
 * @return array
 */
private function append_menu_job_history( $post_id, $event, $message = '', array $summary = array(), array $extra = array(), $persist = true ) {
    $job = get_post_meta( $post_id, 'gmb_menu_push_job', true );
    if ( ! is_array( $job ) ) {
        $job = array();
    }
    $history = is_array( $job['history'] ?? null ) ? $job['history'] : array();
    $now     = time();

    $history[] = array_merge( array(
        'event'      => sanitize_key( (string) $event ),
        'message'    => (string) $message,
        'summary'    => $summary,
        'at'         => current_time( 'mysql' ),
        'at_ts'      => $now,
        'at_label'   => date_i18n( 'd/m/Y H:i:s', $now ),
        'user_id'    => get_current_user_id(),
        'user_label' => $this->get_admin_user_label(),
    ), $extra );

    $history = array_slice( $history, -50 );

    if ( $persist ) {
        $job['history'] = $history;
        if ( ! empty( $extra['status'] ) ) {
            $job['status'] = sanitize_key( (string) $extra['status'] );
        }
        if ( $message ) {
            $job['message'] = $message;
        }
        $job['updated_at']       = current_time( 'mysql' );
        $job['updated_at_ts']    = $now;
        $job['updated_at_label'] = date_i18n( 'd/m/Y H:i:s', $now );
        update_post_meta( $post_id, 'gmb_menu_push_job', $job );
    }

    return $history;
}

private function get_admin_user_label() {
    $user = wp_get_current_user();
    if ( $user && $user->exists() ) {
        return $user->display_name ? $user->display_name : $user->user_login;
    }
    return __( 'Sistema', 'lealez' );
}

/**
 * Construye payload FoodMenus válido para GMB.
 *
 * @param int   $post_id
 * @param array $ctx
 * @param array $payload
 * @return array|WP_Error
 */
private function build_food_menus_payload_for_gmb( $post_id, array $ctx, array $payload ) {
    $sections = is_array( $payload['location_menu_sections'] ?? null ) ? $payload['location_menu_sections'] : array();
    $language = $this->get_location_language_code( $post_id );
    $currency = $this->detect_currency_code( $post_id, $payload );
    $notes    = array();

    if ( empty( $sections ) ) {
        return new WP_Error( 'empty_menu', __( 'No hay secciones de menú para enviar a Google.', 'lealez' ) );
    }

    $gmb_sections = array();
    $missing      = array();

    foreach ( $sections as $section ) {
        if ( ! is_array( $section ) ) {
            continue;
        }
        $sec_name = trim( (string) ( $section['name'] ?? '' ) );
        if ( '' === $sec_name ) {
            continue;
        }

        $items     = is_array( $section['items'] ?? null ) ? $section['items'] : array();
        $gmb_items = array();

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $item_name = trim( (string) ( $item['name'] ?? '' ) );
            if ( '' === $item_name ) {
                continue;
            }

            $money = $this->normalize_price_to_money( (string) ( $item['price'] ?? '' ), $currency );
            if ( is_wp_error( $money ) ) {
                $missing[] = $sec_name . ' / ' . $item_name;
                continue;
            }

            $label = array(
                'displayName'  => substr( $item_name, 0, 140 ),
                'languageCode' => $language,
            );
            $desc = trim( (string) ( $item['description'] ?? '' ) );
            if ( '' !== $desc ) {
                $label['description'] = substr( $desc, 0, 1000 );
            }

            $attrs = array( 'price' => $money );

            $dietary = is_array( $item['dietary'] ?? null ) ? $item['dietary'] : array();
            $dietary_map = array(
                'vegetarian' => 'VEGETARIAN',
                'vegan'      => 'VEGAN',
                'halal'      => 'HALAL',
                'kosher'     => 'KOSHER',
                'organic'    => 'ORGANIC',
            );
            $dr_out = array();
            foreach ( $dietary as $dr ) {
                $dr = sanitize_key( (string) $dr );
                if ( isset( $dietary_map[ $dr ] ) ) {
                    $dr_out[] = $dietary_map[ $dr ];
                }
                if ( 'gluten_free' === $dr ) {
                    $notes[] = __( 'Google FoodMenus no expone enum GLUTEN_FREE en dietaryRestriction; se conserva localmente pero no se envía como dietaryRestriction.', 'lealez' );
                }
            }
            if ( ! empty( $dr_out ) ) {
                $attrs['dietaryRestriction'] = array_values( array_unique( $dr_out ) );
            }

            $media_keys = is_array( $item['media_keys'] ?? null ) ? array_values( array_filter( array_map( 'strval', $item['media_keys'] ) ) ) : array();
            if ( ! empty( $media_keys ) ) {
                $attrs['mediaKeys'] = array_values( array_unique( $media_keys ) );
            }

            $gmb_items[] = array(
                'labels'     => array( $label ),
                'attributes' => $attrs,
            );
        }

        if ( empty( $gmb_items ) ) {
            continue;
        }

        $gmb_sections[] = array(
            'labels' => array(
                array(
                    'displayName'  => substr( $sec_name, 0, 140 ),
                    'languageCode' => $language,
                ),
            ),
            'items'  => $gmb_items,
        );
    }

    if ( ! empty( $missing ) ) {
        return new WP_Error(
            'missing_required_prices',
            sprintf(
                /* translators: %s: item list */
                __( 'Google exige precio para cada plato del foodMenu. Agrega precio a: %s', 'lealez' ),
                implode( ', ', array_slice( $missing, 0, 12 ) )
            ),
            array( 'missing_items' => $missing )
        );
    }

    if ( empty( $gmb_sections ) ) {
        return new WP_Error( 'empty_gmb_sections', __( 'No hay secciones con platos válidos para enviar a Google.', 'lealez' ) );
    }

    $menu = array(
        'labels'   => array(
            array(
                'displayName'  => __( 'Menú', 'lealez' ),
                'languageCode' => $language,
            ),
        ),
        'sections' => $gmb_sections,
    );

    $source_url = trim( (string) ( $payload['location_menu_url'] ?? '' ) );
    if ( '' === $source_url && ! empty( $payload['location_menu_pdf_id'] ) ) {
        $pdf_url = wp_get_attachment_url( (int) $payload['location_menu_pdf_id'] );
        if ( $pdf_url ) {
            $source_url = $pdf_url;
            $notes[] = __( 'El PDF local se usa como sourceUrl del menú; Google Media API no acepta PDFs como MediaItem.', 'lealez' );
        }
    }
    if ( '' !== $source_url ) {
        $menu['sourceUrl'] = esc_url_raw( $source_url );
    }

    if ( ! empty( $payload['location_menu_featured_items'] ) ) {
        $notes[] = __( 'Los elementos destacados se guardan localmente. No se envían en foodMenus porque el recurso de Google no tiene campo independiente para destacados.', 'lealez' );
    }

    $food_menus = array(
        'name'  => 'accounts/' . $ctx['account_id'] . '/locations/' . $ctx['location_id'] . '/foodMenus',
        'menus' => array( $menu ),
    );

    return array(
        'foodMenus' => $food_menus,
        'notes'     => array_values( array_unique( $notes ) ),
    );
}

/**
 * Sube fotos locales a GMB para poder asociar mediaKeys a platos.
 *
 * @param int   $post_id
 * @param array $ctx
 * @param array $payload
 * @return array|WP_Error
 */
private function upload_menu_media_assets( $post_id, array $ctx, array $payload ) {
    if ( ! method_exists( 'Lealez_GMB_API', 'create_location_media_item' ) ) {
        return array(
            'payload'        => $payload,
            'changed'        => false,
            'uploaded_count' => 0,
            'reused_count'   => 0,
        );
    }

    $map = get_post_meta( $post_id, 'gmb_menu_uploaded_media_map', true );
    if ( ! is_array( $map ) ) {
        $map = array();
    }

    $changed  = false;
    $uploaded = 0;
    $reused   = 0;

    $sections = is_array( $payload['location_menu_sections'] ?? null ) ? $payload['location_menu_sections'] : array();
    foreach ( $sections as $sec_idx => $section ) {
        if ( empty( $section['items'] ) || ! is_array( $section['items'] ) ) {
            continue;
        }
        foreach ( $section['items'] as $item_idx => $item ) {
            $image_id = absint( $item['image_id'] ?? 0 );
            if ( ! $image_id ) {
                continue;
            }
            $source_url = wp_get_attachment_url( $image_id );
            if ( ! $source_url ) {
                continue;
            }
            $map_key = 'item_' . $image_id;
            $media_key = (string) ( $map[ $map_key ]['mediaKey'] ?? '' );
            if ( '' === $media_key ) {
                $created = Lealez_GMB_API::create_location_media_item(
                    (int) $ctx['business_id'],
                    (string) $ctx['account_id'],
                    (string) $ctx['location_id'],
                    array(
                        'mediaFormat'         => 'PHOTO',
                        'locationAssociation' => array( 'category' => 'FOOD_AND_DRINK' ),
                        'sourceUrl'           => $source_url,
                        'description'         => substr( (string) ( $item['name'] ?? __( 'Foto de plato', 'lealez' ) ), 0, 250 ),
                    )
                );
                if ( is_wp_error( $created ) ) {
                    return $created;
                }
                $media_key = $this->extract_media_key( $created );
                if ( '' !== $media_key ) {
                    $map[ $map_key ] = array(
                        'attachment_id' => $image_id,
                        'sourceUrl'     => $source_url,
                        'mediaKey'      => $media_key,
                        'name'          => (string) ( $created['name'] ?? '' ),
                        'created_at'    => current_time( 'mysql' ),
                    );
                    $uploaded++;
                }
            } else {
                $reused++;
            }

            if ( '' !== $media_key ) {
                $current_keys = is_array( $payload['location_menu_sections'][ $sec_idx ]['items'][ $item_idx ]['media_keys'] ?? null )
                    ? $payload['location_menu_sections'][ $sec_idx ]['items'][ $item_idx ]['media_keys']
                    : array();
                if ( ! in_array( $media_key, $current_keys, true ) ) {
                    $current_keys[] = $media_key;
                    $payload['location_menu_sections'][ $sec_idx ]['items'][ $item_idx ]['media_keys'] = array_values( array_unique( $current_keys ) );
                    $changed = true;
                }
            }
        }
    }

    $photos = is_array( $payload['location_menu_photos'] ?? null ) ? $payload['location_menu_photos'] : array();
    foreach ( $photos as $photo_id ) {
        $photo_id   = absint( $photo_id );
        $source_url = $photo_id ? wp_get_attachment_url( $photo_id ) : '';
        if ( ! $photo_id || ! $source_url ) {
            continue;
        }
        $map_key = 'gallery_' . $photo_id;
        if ( ! empty( $map[ $map_key ]['mediaKey'] ) ) {
            $reused++;
            continue;
        }
        $created = Lealez_GMB_API::create_location_media_item(
            (int) $ctx['business_id'],
            (string) $ctx['account_id'],
            (string) $ctx['location_id'],
            array(
                'mediaFormat'         => 'PHOTO',
                'locationAssociation' => array( 'category' => 'MENU' ),
                'sourceUrl'           => $source_url,
                'description'         => __( 'Foto del menú subida desde Lealez', 'lealez' ),
            )
        );
        if ( is_wp_error( $created ) ) {
            return $created;
        }
        $media_key = $this->extract_media_key( $created );
        if ( '' !== $media_key ) {
            $map[ $map_key ] = array(
                'attachment_id' => $photo_id,
                'sourceUrl'     => $source_url,
                'mediaKey'      => $media_key,
                'name'          => (string) ( $created['name'] ?? '' ),
                'created_at'    => current_time( 'mysql' ),
            );
            $uploaded++;
        }
    }

    update_post_meta( $post_id, 'gmb_menu_uploaded_media_map', $map );

    return array(
        'payload'        => $payload,
        'changed'        => $changed,
        'uploaded_count' => $uploaded,
        'reused_count'   => $reused,
    );
}

private function extract_media_key( array $media_item ) {
    if ( ! empty( $media_item['mediaKey'] ) ) {
        return sanitize_text_field( (string) $media_item['mediaKey'] );
    }
    if ( ! empty( $media_item['name'] ) && false !== strpos( (string) $media_item['name'], '/media/' ) ) {
        $parts = explode( '/media/', (string) $media_item['name'] );
        return sanitize_text_field( trim( (string) end( $parts ), '/' ) );
    }
    return '';
}

private function normalize_price_to_money( $price, $default_currency ) {
    $raw = trim( (string) $price );
    if ( '' === $raw ) {
        return new WP_Error( 'missing_price', __( 'Precio vacío.', 'lealez' ) );
    }

    $currency = strtoupper( (string) $default_currency );
    if ( preg_match( '/\b([A-Z]{3})\b/', strtoupper( $raw ), $m ) ) {
        $currency = $m[1];
    }

    $numeric = preg_replace( '/[^0-9,\.\-]/', '', $raw );
    if ( '' === $numeric || '-' === $numeric ) {
        return new WP_Error( 'invalid_price', __( 'Precio inválido.', 'lealez' ) );
    }

    // Soporta formatos frecuentes: 25000, 25.000, 25,000, 25.000,50 y 25,000.50.
    if ( false !== strpos( $numeric, ',' ) && false !== strpos( $numeric, '.' ) ) {
        $last_comma = strrpos( $numeric, ',' );
        $last_dot   = strrpos( $numeric, '.' );
        if ( $last_comma > $last_dot ) {
            // Formato latino: 25.000,50
            $numeric = str_replace( '.', '', $numeric );
            $numeric = str_replace( ',', '.', $numeric );
        } else {
            // Formato internacional: 25,000.50
            $numeric = str_replace( ',', '', $numeric );
        }
    } elseif ( false !== strpos( $numeric, ',' ) && false === strpos( $numeric, '.' ) ) {
        $tail = substr( $numeric, strrpos( $numeric, ',' ) + 1 );
        if ( 2 === strlen( $tail ) ) {
            $numeric = str_replace( ',', '.', $numeric );
        } else {
            $numeric = str_replace( ',', '', $numeric );
        }
    } elseif ( false !== strpos( $numeric, '.' ) && false === strpos( $numeric, ',' ) ) {
        $tail = substr( $numeric, strrpos( $numeric, '.' ) + 1 );
        if ( 3 === strlen( $tail ) ) {
            $numeric = str_replace( '.', '', $numeric );
        }
    }

    $amount = (float) $numeric;
    if ( $amount < 0 ) {
        return new WP_Error( 'invalid_price', __( 'Precio inválido.', 'lealez' ) );
    }

    $units = floor( $amount );
    $nanos = (int) round( ( $amount - $units ) * 1000000000 );

    return array(
        'currencyCode' => $currency ?: 'USD',
        'units'        => (string) (int) $units,
        'nanos'        => $nanos,
    );
}

private function detect_currency_code( $post_id, array $payload ) {
    $country = strtoupper( (string) get_post_meta( $post_id, 'location_country', true ) );
    $map = array(
        'CO' => 'COP', 'COL' => 'COP', 'COLOMBIA' => 'COP',
        'US' => 'USD', 'USA' => 'USD', 'UNITED STATES' => 'USD',
        'ES' => 'EUR', 'ESP' => 'EUR', 'SPAIN' => 'EUR', 'ESPAÑA' => 'EUR',
        'MX' => 'MXN', 'MEXICO' => 'MXN', 'MÉXICO' => 'MXN',
        'AR' => 'ARS', 'ARGENTINA' => 'ARS',
        'CL' => 'CLP', 'CHILE' => 'CLP',
        'PE' => 'PEN', 'PERU' => 'PEN', 'PERÚ' => 'PEN',
    );
    if ( isset( $map[ $country ] ) ) {
        return $map[ $country ];
    }

    $sections = is_array( $payload['location_menu_sections'] ?? null ) ? $payload['location_menu_sections'] : array();
    foreach ( $sections as $section ) {
        foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
            $price = strtoupper( (string) ( $item['price'] ?? '' ) );
            if ( preg_match( '/\b([A-Z]{3})\b/', $price, $m ) ) {
                return $m[1];
            }
        }
    }

    return 'COP';
}

private function get_location_language_code( $post_id ) {
    $lang = (string) get_post_meta( $post_id, 'gmb_language_code', true );
    if ( '' === $lang ) {
        $lang = substr( get_locale(), 0, 2 );
    }
    $lang = strtolower( preg_replace( '/[^a-zA-Z\-]/', '', $lang ) );
    return $lang ?: 'es';
}

private function normalize_food_menus_for_compare( array $food_menus ) {
    $menus = is_array( $food_menus['menus'] ?? null ) ? $food_menus['menus'] : array();
    $out   = array();

    foreach ( $menus as $menu ) {
        if ( ! is_array( $menu ) ) {
            continue;
        }
        $menu_out = array(
            'label'    => $this->first_label_display_name( $menu['labels'] ?? array() ),
            'source'   => esc_url_raw( (string) ( $menu['sourceUrl'] ?? '' ) ),
            'sections' => array(),
        );
        foreach ( (array) ( $menu['sections'] ?? array() ) as $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }
            $sec_out = array(
                'name'  => $this->normalize_compare_string( $this->first_label_display_name( $section['labels'] ?? array() ) ),
                'items' => array(),
            );
            foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $attrs = is_array( $item['attributes'] ?? null ) ? $item['attributes'] : ( is_array( $item['itemAttributes'] ?? null ) ? $item['itemAttributes'] : array() );
                $price = is_array( $attrs['price'] ?? null ) ? $attrs['price'] : ( is_array( $item['price'] ?? null ) ? $item['price'] : array() );
                $label = $this->first_label_array( $item['labels'] ?? array() );
                $sec_out['items'][] = array(
                    'name'        => $this->normalize_compare_string( (string) ( $label['displayName'] ?? '' ) ),
                    'description' => $this->normalize_compare_string( (string) ( $label['description'] ?? '' ) ),
                    'price'       => array(
                        'currencyCode' => strtoupper( (string) ( $price['currencyCode'] ?? '' ) ),
                        'units'        => (string) ( $price['units'] ?? '' ),
                        'nanos'        => (int) ( $price['nanos'] ?? 0 ),
                    ),
                    'dietary'     => array_values( array_unique( array_map( 'strval', (array) ( $attrs['dietaryRestriction'] ?? array() ) ) ) ),
                    'mediaKeys'   => array_values( array_unique( array_map( 'strval', (array) ( $attrs['mediaKeys'] ?? array() ) ) ) ),
                );
            }
            usort( $sec_out['items'], function( $a, $b ) {
                return strcmp( $a['name'], $b['name'] );
            } );
            if ( '' !== $sec_out['name'] || ! empty( $sec_out['items'] ) ) {
                $menu_out['sections'][] = $sec_out;
            }
        }
        usort( $menu_out['sections'], function( $a, $b ) {
            return strcmp( $a['name'], $b['name'] );
        } );
        $out[] = $menu_out;
    }

    return $out;
}

private function compare_food_menus_payloads( array $expected, array $current ) {
    return wp_json_encode( $expected ) === wp_json_encode( $current );
}

private function first_label_display_name( $labels ) {
    $label = $this->first_label_array( $labels );
    return (string) ( $label['displayName'] ?? '' );
}

private function first_label_array( $labels ) {
    if ( ! is_array( $labels ) ) {
        return array();
    }
    foreach ( $labels as $label ) {
        if ( is_array( $label ) && ! empty( $label['displayName'] ) ) {
            return $label;
        }
    }
    return array();
}

private function normalize_compare_string( $value ) {
    $value = wp_strip_all_tags( (string) $value );
    $value = preg_replace( '/\s+/', ' ', $value );
    return trim( strtolower( $value ) );
}

    
    
}
    endif; // class_exists OY_Location_Menu_Metabox

