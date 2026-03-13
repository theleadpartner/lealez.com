<?php
/**
 * OY Location Products Metabox
 *
 * Metabox dedicado a la gestión del catálogo de productos de la ubicación,
 * para negocios que NO son restaurantes ni usan menú de alimentos
 * (ropa, accesorios, electrónicos, libros, cosméticos, etc.).
 *
 * Características:
 *   - Categorías de productos con items anidados (sortable vía jQuery UI)
 *   - Campos por ítem: imagen, nombre, precio, descripción, URL, SKU
 *   - Productos Destacados
 *   - Botón de sincronización con Google My Business (/products endpoint)
 *     — maneja con gracia el 404 del endpoint v4 obsoleto
 *   - AJAX propio: oy_sync_location_products
 *
 * Meta keys utilizados:
 *   - location_products_sections    : array de categorías con productos
 *   - location_products_featured    : array de productos destacados
 *   - gmb_products_last_sync        : timestamp de última sync
 *   - gmb_products_api_notice       : aviso informativo de última sync
 *
 * Archivo: includes/cpts/metaboxes/class-oy-location-products-metabox.php
 *
 * @package Lealez
 * @subpackage CPTs/Metaboxes
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'OY_Location_Products_Metabox' ) ) :

class OY_Location_Products_Metabox {

    /**
     * Nonce action para save_post
     * @var string
     */
    private $nonce_action = 'oy_location_products_save';

    /**
     * Nonce name para save_post
     * @var string
     */
    private $nonce_name = 'oy_location_products_nonce';

    /**
     * Nonce action para AJAX (compartido con otros metaboxes GMB)
     * @var string
     */
    private $ajax_nonce_action = 'oy_location_gmb_ajax';

    /**
     * Post type slug
     * @var string
     */
    private $post_type = 'oy_location';

    /**
     * Previene doble registro de hooks
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

        // Priority 16: posterior a OY_Location_Menu_Metabox (priority 15),
        // evitando el bug de duplicado en WP 6.9.1.
        add_action( 'add_meta_boxes',        array( $this, 'add_meta_box' ), 16 );
        add_action( 'save_post_oy_location', array( $this, 'save_meta_box' ), 16, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX propio — no depende del CPT file
        add_action( 'wp_ajax_oy_sync_location_products', array( $this, 'ajax_sync_products' ) );
    }

    // =========================================================================
    // REGISTRO
    // =========================================================================

    /**
     * Registrar metabox
     */
    public function add_meta_box() {
        add_meta_box(
            'oy_location_products',
            __( '📦 Catálogo de Productos', 'lealez' ),
            array( $this, 'render' ),
            $this->post_type,
            'normal',
            'default'
        );
    }

    /**
     * Encolar assets (solo en pantallas oy_location)
     *
     * @param string $hook
     */
    public function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== $this->post_type ) {
            return;
        }
        wp_enqueue_media();
    }

    // =========================================================================
    // RENDER PRINCIPAL
    // =========================================================================

    /**
     * Render del metabox
     *
     * @param WP_Post $post
     */
    public function render( $post ) {
        wp_nonce_field( $this->nonce_action, $this->nonce_name );

        // ── Leer meta fields ──────────────────────────────────────────────────
        $products_sections      = get_post_meta( $post->ID, 'location_products_sections', true );
        $products_featured      = get_post_meta( $post->ID, 'location_products_featured', true );
        $gmb_products_last_sync = get_post_meta( $post->ID, 'gmb_products_last_sync', true );
        $gmb_products_api_notice = (string) get_post_meta( $post->ID, 'gmb_products_api_notice', true );

        // ── Datos de conexión GMB ──────────────────────────────────────────────
        $parent_business_id = (int) get_post_meta( $post->ID, 'parent_business_id', true );
        $gmb_location_id    = (string) get_post_meta( $post->ID, 'gmb_location_id', true );
        $gmb_location_name  = (string) get_post_meta( $post->ID, 'gmb_location_name', true );
        $gmb_sync_nonce     = wp_create_nonce( $this->ajax_nonce_action );
        $gmb_connected      = ( $parent_business_id > 0 && ( '' !== $gmb_location_id || '' !== $gmb_location_name ) );

        if ( ! is_array( $products_sections ) ) $products_sections = array();
        if ( ! is_array( $products_featured ) ) $products_featured = array();
        ?>
        <style>
        /* ── Tabs ─────────────────────────────────────────────────────────── */
        .oy-products-tabs {
            display:flex; border-bottom:2px solid #e0e0e0; margin-bottom:20px; gap:0;
        }
        .oy-products-tab-btn {
            padding:9px 20px; cursor:pointer; font-size:13px; font-weight:600;
            border:none; border-bottom:3px solid transparent; background:none;
            color:#666; transition:color .2s,border-color .2s; margin-bottom:-2px;
        }
        .oy-products-tab-btn.active { color:#2271b1; border-bottom-color:#2271b1; }
        .oy-products-tab-btn:hover  { color:#2271b1; }
        .oy-products-tab-panel      { display:none; }
        .oy-products-tab-panel.active { display:block; }

        /* ── Secciones / Categorías ───────────────────────────────────────── */
        .oy-products-section {
            border:1px solid #ddd; border-radius:6px; margin-bottom:16px;
            background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.06);
        }
        .oy-products-section-header {
            display:flex; align-items:center; gap:10px;
            padding:10px 14px; background:#f8f9fa;
            border-radius:6px 6px 0 0; border-bottom:1px solid #e0e0e0; cursor:grab;
        }
        .oy-products-section-header:active { cursor:grabbing; }
        .oy-products-section-handle { color:#aaa; font-size:18px; line-height:1; }
        .oy-products-section-name-input {
            flex:1; border:1px solid transparent; background:transparent;
            font-size:14px; font-weight:600; padding:4px 8px; border-radius:4px;
        }
        .oy-products-section-name-input:focus {
            border-color:#2271b1; background:#fff; outline:none;
        }
        .oy-products-section-count  { font-size:11px; color:#888; margin-left:auto; }
        .oy-products-section-toggle { color:#666; cursor:pointer; background:none; border:none; font-size:16px; }
        .oy-products-section-remove { color:#dc3232; cursor:pointer; background:none; border:none; font-size:18px; font-weight:bold; }
        .oy-products-items-wrap     { padding:12px 14px; }

        /* ── Items ────────────────────────────────────────────────────────── */
        .oy-product-item {
            display:grid; grid-template-columns:80px 1fr auto; gap:12px;
            align-items:start; padding:12px; margin-bottom:10px;
            border:1px solid #e8e8e8; border-radius:5px; background:#fafafa;
        }
        .oy-product-item-image {
            width:80px; height:80px; border:2px dashed #ccc; border-radius:5px;
            display:flex; align-items:center; justify-content:center;
            cursor:pointer; overflow:hidden; background:#f0f0f0; flex-shrink:0;
        }
        .oy-product-item-image img     { width:100%; height:100%; object-fit:cover; }
        .oy-product-item-image .oy-prod-img-placeholder { color:#aaa; font-size:24px; text-align:center; }
        .oy-product-item-fields        { display:flex; flex-direction:column; gap:6px; }
        .oy-product-item-fields input[type=text],
        .oy-product-item-fields input[type=url],
        .oy-product-item-fields textarea { width:100%; box-sizing:border-box; }
        .oy-product-item-fields textarea { resize:vertical; min-height:54px; font-size:12px; }
        .oy-product-item-row   { display:flex; gap:8px; align-items:center; }
        .oy-product-item-actions { display:flex; flex-direction:column; gap:4px; }
        .oy-product-item-remove  { color:#dc3232; cursor:pointer; background:none; border:none; font-size:18px; }
        .oy-add-product-item-btn { display:block; width:100%; text-align:center; margin-top:8px; }

        /* ── Destacados ───────────────────────────────────────────────────── */
        .oy-product-featured-item {
            display:grid; grid-template-columns:80px 1fr auto; gap:12px;
            align-items:start; padding:12px; margin-bottom:10px;
            border:1px solid #e8e8e8; border-radius:5px; background:#fafafa;
        }
        .oy-product-featured-image {
            width:80px; height:80px; border:2px dashed #ccc; border-radius:5px;
            display:flex; align-items:center; justify-content:center;
            cursor:pointer; overflow:hidden; background:#f0f0f0;
        }
        .oy-product-featured-image img { width:100%; height:100%; object-fit:cover; }

        /* ── Avisos GMB ───────────────────────────────────────────────────── */
        .oy-products-notice {
            background:#f0f6ff; border:1px solid #90c5f7; border-radius:5px;
            padding:10px 14px; margin-bottom:16px; display:flex;
            align-items:flex-start; gap:10px; font-size:12px; line-height:1.5;
        }
        .oy-products-notice.is-info   { background:#f0f6ff; border-color:#90c5f7; }
        .oy-products-notice.is-ok     { background:#edfaee; border-color:#a3d6a7; }
        .oy-products-notice-icon      { font-size:20px; flex-shrink:0; }
        </style>

        <div id="oy-products-metabox-wrap">

            <?php /* ── Aviso de última sincronización / estado de la API ── */ ?>
            <?php if ( ! empty( $gmb_products_api_notice ) ) : ?>
                <div class="oy-products-notice is-info">
                    <div class="oy-products-notice-icon">ℹ️</div>
                    <div><?php echo esc_html( $gmb_products_api_notice ); ?></div>
                </div>
            <?php elseif ( $gmb_products_last_sync ) : ?>
                <div class="oy-products-notice is-ok">
                    <div class="oy-products-notice-icon">✅</div>
                    <div>
                        <strong><?php _e( 'Catálogo sincronizado desde Google My Business', 'lealez' ); ?></strong><br>
                        <?php printf(
                            /* translators: %s: fecha */
                            __( 'Última sincronización: %s — Los cambios guardados aquí se almacenan en Lealez.', 'lealez' ),
                            esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', $gmb_products_last_sync ) )
                        ); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php /* ── Tabs ── */ ?>
            <div class="oy-products-tabs">
                <button type="button" class="oy-products-tab-btn active" data-tab="catalog">
                    <?php _e( 'Catálogo', 'lealez' ); ?>
                </button>
                <button type="button" class="oy-products-tab-btn" data-tab="featured">
                    <?php _e( 'Destacados', 'lealez' ); ?>
                </button>
            </div>

            <?php /* ══════════════════════════════════════════════════════
                   TAB 1: CATÁLOGO (Categorías + Productos)
                   ══════════════════════════════════════════════════════ */ ?>
            <div class="oy-products-tab-panel active" id="oy-products-tab-catalog">

                <p class="description" style="margin-bottom:14px;">
                    <?php _e( 'Organiza tu catálogo por categorías (ej: Ropa de hombre, Accesorios, Electrónicos). Este metabox es para negocios que venden productos físicos o digitales. Para restaurantes, usa el metabox <strong>Menú del Restaurante</strong>.', 'lealez' ); ?>
                </p>

                <?php /* ── Botón de sincronización GMB ── */ ?>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">
                    <button
                        type="button"
                        id="oy-sync-products-btn"
                        class="button button-secondary"
                        <?php echo $gmb_connected ? '' : 'disabled'; ?>
                        data-post-id="<?php echo esc_attr( $post->ID ); ?>"
                        data-business-id="<?php echo esc_attr( $parent_business_id ); ?>"
                        data-nonce="<?php echo esc_attr( $gmb_sync_nonce ); ?>"
                    >
                        🔄 <?php _e( 'Sincronizar desde Google', 'lealez' ); ?>
                    </button>
                    <span id="oy-sync-products-status" style="font-size:12px;color:#666;">
                        <?php if ( ! $gmb_connected ) : ?>
                            <em><?php _e( 'Conecta primero la ubicación a Google My Business', 'lealez' ); ?></em>
                        <?php elseif ( $gmb_products_last_sync ) : ?>
                            <?php printf(
                                __( 'Última sync: %s', 'lealez' ),
                                esc_html( date_i18n( 'd/m/Y H:i', $gmb_products_last_sync ) )
                            ); ?>
                        <?php else : ?>
                            <em><?php _e( 'Sin sincronizar aún — haz clic para intentar traer el catálogo desde Google', 'lealez' ); ?></em>
                        <?php endif; ?>
                    </span>
                </div>

                <div id="oy-products-sections-list">
                    <?php foreach ( $products_sections as $sec_idx => $section ) :
                        $sec_name     = sanitize_text_field( $section['name'] ?? '' );
                        $sec_items    = is_array( $section['items'] ?? null ) ? $section['items'] : array();
                        $sec_from_gmb = ! empty( $section['from_gmb'] );
                        $this->render_section_html( $sec_idx, $sec_name, $sec_items, $sec_from_gmb );
                    endforeach; ?>
                </div>

                <button type="button" id="oy-add-products-section" class="button button-primary">
                    + <?php _e( 'Agregar categoría', 'lealez' ); ?>
                </button>

                <p class="description" style="margin-top:8px;">
                    <?php _e( 'Cada categoría debe tener al menos un producto. Puedes reordenar las categorías arrastrándolas.', 'lealez' ); ?>
                </p>
            </div>

            <?php /* ══════════════════════════════════════════════════════
                   TAB 2: PRODUCTOS DESTACADOS
                   ══════════════════════════════════════════════════════ */ ?>
            <div class="oy-products-tab-panel" id="oy-products-tab-featured">

                <p class="description" style="margin-bottom:14px;">
                    <?php _e( 'Selecciona los productos que quieres destacar en tu perfil de Google Business. Aparecerán en la sección de "Productos destacados".', 'lealez' ); ?>
                </p>

                <div id="oy-products-featured-list">
                    <?php foreach ( $products_featured as $feat_idx => $feat ) :
                        $feat_name    = sanitize_text_field( $feat['name']        ?? '' );
                        $feat_price   = sanitize_text_field( $feat['price']       ?? '' );
                        $feat_desc    = sanitize_textarea_field( $feat['description'] ?? '' );
                        $feat_url     = esc_url_raw( $feat['product_url']         ?? '' );
                        $feat_img     = (int) ( $feat['image_id'] ?? 0 );
                        $feat_img_url = $feat_img ? wp_get_attachment_image_url( $feat_img, 'thumbnail' ) : '';
                        $this->render_featured_item_html( $feat_idx, $feat_name, $feat_price, $feat_desc, $feat_url, $feat_img, $feat_img_url );
                    endforeach; ?>
                </div>

                <button type="button" id="oy-add-products-featured" class="button button-primary">
                    ⭐ <?php _e( 'Agregar producto destacado', 'lealez' ); ?>
                </button>
            </div>

        </div><!-- /#oy-products-metabox-wrap -->

        <?php /* ── Templates HTML ocultos para JS ── */ ?>
        <script type="text/html" id="oy-products-section-template">
            <?php $this->render_section_html( '__SEC_IDX__', '', array(), false ); ?>
        </script>
        <script type="text/html" id="oy-products-item-template">
            <?php $this->render_item_html( '__SEC_IDX__', '__ITEM_IDX__', '', '', '', 0, '' ); ?>
        </script>
        <script type="text/html" id="oy-products-featured-template">
            <?php $this->render_featured_item_html( '__FEAT_IDX__', '', '', '', '', 0, '' ); ?>
        </script>

        <script>
        (function($){
            'use strict';

            // ── Tabs ────────────────────────────────────────────────────
            $(document).on('click', '.oy-products-tab-btn', function(){
                var tab = $(this).data('tab');
                $('.oy-products-tab-btn').removeClass('active');
                $(this).addClass('active');
                $('.oy-products-tab-panel').removeClass('active');
                $('#oy-products-tab-' + tab).addClass('active');
            });

            // ── Toggle sección ──────────────────────────────────────────
            $(document).on('click', '.oy-products-section-toggle', function(){
                $(this).closest('.oy-products-section').find('.oy-products-items-wrap').slideToggle(200);
                var icon = $(this).text().trim();
                $(this).text(icon === '▲' ? '▼' : '▲');
            });

            // ── Eliminar sección ────────────────────────────────────────
            $(document).on('click', '.oy-products-section-remove', function(){
                if ( confirm('<?php echo esc_js( __( '¿Eliminar esta categoría y todos sus productos?', 'lealez' ) ); ?>') ) {
                    $(this).closest('.oy-products-section').slideUp(200, function(){
                        $(this).remove();
                        oy_products_reindex_sections();
                    });
                }
            });

            // ── Agregar sección ─────────────────────────────────────────
            $('#oy-add-products-section').on('click', function(){
                var tpl = $('#oy-products-section-template').html();
                var idx = $('#oy-products-sections-list .oy-products-section').length;
                tpl = tpl.replace(/__SEC_IDX__/g, idx);
                var $sec = $(tpl);
                $('#oy-products-sections-list').append($sec);
                $sec.find('.oy-products-section-name-input').focus();
            });

            // ── Agregar item en sección ─────────────────────────────────
            $(document).on('click', '.oy-add-product-item-btn', function(){
                var $section = $(this).closest('.oy-products-section');
                var sec_idx  = $section.data('section-idx');
                var tpl      = $('#oy-products-item-template').html();
                var item_idx = $section.find('.oy-product-item').length;
                tpl = tpl.replace(/__SEC_IDX__/g, sec_idx).replace(/__ITEM_IDX__/g, item_idx);
                $(this).before($(tpl));
            });

            // ── Eliminar item ───────────────────────────────────────────
            $(document).on('click', '.oy-product-item-remove', function(){
                $(this).closest('.oy-product-item').slideUp(150, function(){ $(this).remove(); });
            });

            // ── Imagen de item (media library) ──────────────────────────
            $(document).on('click', '.oy-product-item-image', function(){
                var $thumb   = $(this);
                var $idInput = $thumb.siblings('input.oy-product-image-id');
                var frame    = wp.media({
                    title:    '<?php echo esc_js( __( 'Seleccionar imagen del producto', 'lealez' ) ); ?>',
                    button:   { text: '<?php echo esc_js( __( 'Usar imagen', 'lealez' ) ); ?>' },
                    multiple: false,
                    library:  { type: 'image' }
                });
                frame.on('select', function(){
                    var att       = frame.state().get('selection').first().toJSON();
                    var thumb_url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                    $thumb.html('<img src="' + thumb_url + '" alt="">');
                    $idInput.val(att.id);
                });
                frame.open();
            });

            // ── Imagen de producto destacado ────────────────────────────
            $(document).on('click', '.oy-product-featured-image', function(){
                var $thumb   = $(this);
                var $idInput = $thumb.siblings('input.oy-product-featured-image-id');
                var frame    = wp.media({
                    title:    '<?php echo esc_js( __( 'Seleccionar imagen del producto destacado', 'lealez' ) ); ?>',
                    button:   { text: '<?php echo esc_js( __( 'Usar imagen', 'lealez' ) ); ?>' },
                    multiple: false,
                    library:  { type: 'image' }
                });
                frame.on('select', function(){
                    var att       = frame.state().get('selection').first().toJSON();
                    var thumb_url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                    $thumb.html('<img src="' + thumb_url + '" alt="">');
                    $idInput.val(att.id);
                });
                frame.open();
            });

            // ── Agregar producto destacado ──────────────────────────────
            $('#oy-add-products-featured').on('click', function(){
                var tpl = $('#oy-products-featured-template').html();
                var idx = $('#oy-products-featured-list .oy-product-featured-item').length;
                tpl = tpl.replace(/__FEAT_IDX__/g, idx);
                $('#oy-products-featured-list').append($(tpl));
            });

            // ── Eliminar producto destacado ─────────────────────────────
            $(document).on('click', '.oy-product-featured-remove', function(){
                $(this).closest('.oy-product-featured-item').slideUp(150, function(){ $(this).remove(); });
            });

            // ── Reindexar secciones ─────────────────────────────────────
            function oy_products_reindex_sections() {
                $('#oy-products-sections-list .oy-products-section').each(function(sec_idx){
                    var $sec = $(this);
                    $sec.attr('data-section-idx', sec_idx);
                    $sec.find('[name*="location_products_sections"]').each(function(){
                        var oldName = $(this).attr('name');
                        var newName = oldName.replace(
                            /location_products_sections\[\d+\]/,
                            'location_products_sections[' + sec_idx + ']'
                        );
                        $(this).attr('name', newName);
                    });
                });
            }

            // ── Sortable (categorías) ───────────────────────────────────
            if ( $.fn.sortable ) {
                $('#oy-products-sections-list').sortable({
                    handle:      '.oy-products-section-handle',
                    placeholder: 'oy-products-section-placeholder',
                    stop:        function(){ oy_products_reindex_sections(); }
                });
            }

            // ── Sincronizar catálogo desde GMB ──────────────────────────
            $('#oy-sync-products-btn').on('click', function(){
                var $btn    = $(this);
                var $status = $('#oy-sync-products-status');
                var postId  = $btn.data('post-id');
                var bizId   = $btn.data('business-id');
                var nonce   = $btn.data('nonce');
                var ajaxUrl = ( typeof ajaxurl !== 'undefined' )
                    ? ajaxurl
                    : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

                if ( ! postId || ! bizId ) {
                    $status.html('<span style="color:#c0392b;"><?php echo esc_js( __( 'Error: faltan parámetros.', 'lealez' ) ); ?></span>');
                    return;
                }

                $btn.prop('disabled', true).text('⏳ <?php echo esc_js( __( 'Consultando...', 'lealez' ) ); ?>');
                $status.html('<em style="color:#2271b1;"><?php echo esc_js( __( 'Consultando Google My Business...', 'lealez' ) ); ?></em>');

                $.post( ajaxUrl, {
                    action:      'oy_sync_location_products',
                    nonce:       nonce,
                    post_id:     postId,
                    business_id: bizId
                })
                .done(function( resp ){
                    if ( resp && resp.success ) {
                        var d   = resp.data || {};
                        var msg = d.message || '<?php echo esc_js( __( 'Completado.', 'lealez' ) ); ?>';
                        $status.html('<span style="color:#2e7d32;font-weight:600;">' + $('<div>').text(msg).html() + '</span>');
                        setTimeout(function(){ window.location.reload(); }, 1500);
                    } else {
                        var msg = ( resp && resp.data && resp.data.message )
                            ? resp.data.message
                            : '<?php echo esc_js( __( 'Error al consultar.', 'lealez' ) ); ?>';
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

    // =========================================================================
    // HELPERS DE RENDER
    // =========================================================================

    /**
     * Render HTML para una categoría de productos.
     *
     * @param int|string $sec_idx  Índice de la sección
     * @param string     $name     Nombre de la categoría
     * @param array      $items    Array de productos
     * @param bool       $from_gmb Si fue importado desde GMB
     */
    private function render_section_html( $sec_idx, $name, $items, $from_gmb = false ) {
        $item_count = count( $items );
        ?>
        <div class="oy-products-section" data-section-idx="<?php echo esc_attr( $sec_idx ); ?>">
            <div class="oy-products-section-header">
                <span class="oy-products-section-handle" title="<?php esc_attr_e( 'Arrastrar para reordenar', 'lealez' ); ?>">⠿</span>
                <input type="text"
                       class="oy-products-section-name-input"
                       name="location_products_sections[<?php echo esc_attr( $sec_idx ); ?>][name]"
                       value="<?php echo esc_attr( $name ); ?>"
                       placeholder="<?php esc_attr_e( 'Nombre de la categoría (ej: Ropa, Accesorios, Electrónicos...)', 'lealez' ); ?>">
                <?php if ( $from_gmb ) : ?>
                    <span style="display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:600;padding:2px 7px;border-radius:20px;background:#e8f0fe;border:1px solid #b3d4f5;color:#1a56a0;margin-left:4px;white-space:nowrap;">🔄 GMB</span>
                <?php endif; ?>
                <input type="hidden"
                       name="location_products_sections[<?php echo esc_attr( $sec_idx ); ?>][from_gmb]"
                       value="<?php echo $from_gmb ? '1' : '0'; ?>">
                <span class="oy-products-section-count">
                    <?php echo sprintf( _n( '%d producto', '%d productos', $item_count, 'lealez' ), $item_count ); ?>
                </span>
                <button type="button" class="oy-products-section-toggle">▲</button>
                <button type="button" class="oy-products-section-remove" title="<?php esc_attr_e( 'Eliminar categoría', 'lealez' ); ?>">×</button>
            </div>
            <div class="oy-products-items-wrap">
                <?php foreach ( $items as $item_idx => $item ) :
                    $item_name  = sanitize_text_field( $item['name']        ?? '' );
                    $item_price = sanitize_text_field( $item['price']       ?? '' );
                    $item_desc  = sanitize_textarea_field( $item['description'] ?? '' );
                    $item_url   = esc_url_raw( $item['product_url']         ?? '' );
                    $item_sku   = sanitize_text_field( $item['sku']         ?? '' );
                    $item_img   = (int) ( $item['image_id'] ?? 0 );
                    $item_img_url = $item_img ? wp_get_attachment_image_url( $item_img, 'thumbnail' ) : '';
                    $this->render_item_html( $sec_idx, $item_idx, $item_name, $item_price, $item_desc, $item_img, $item_img_url, $item_url, $item_sku );
                endforeach; ?>
                <button type="button" class="button button-small oy-add-product-item-btn">
                    + <?php _e( 'Agregar un producto', 'lealez' ); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Render HTML para un producto individual.
     *
     * @param int|string $sec_idx     Índice de la sección
     * @param int|string $item_idx    Índice del item
     * @param string     $name        Nombre del producto
     * @param string     $price       Precio
     * @param string     $desc        Descripción
     * @param int        $image_id    WP Media Library ID
     * @param string     $image_url   URL de la imagen
     * @param string     $product_url URL del producto (siempre visible)
     * @param string     $sku         SKU / código del producto
     */
    private function render_item_html( $sec_idx, $item_idx, $name, $price, $desc, $image_id, $image_url, $product_url = '', $sku = '' ) {
        $base      = "location_products_sections[{$sec_idx}][items][{$item_idx}]";
        $has_image = ! empty( $image_url );
        ?>
        <div class="oy-product-item">
            <div>
                <div class="oy-product-item-image"
                     title="<?php esc_attr_e( 'Clic para seleccionar imagen', 'lealez' ); ?>">
                    <?php if ( $has_image ) : ?>
                        <img src="<?php echo esc_url( $image_url ); ?>" alt="">
                    <?php else : ?>
                        <div class="oy-prod-img-placeholder">📦<br><small style="font-size:10px;"><?php _e( 'Imagen', 'lealez' ); ?></small></div>
                    <?php endif; ?>
                </div>
                <input type="hidden"
                       class="oy-product-image-id"
                       name="<?php echo esc_attr( $base ); ?>[image_id]"
                       value="<?php echo esc_attr( $image_id ?: '' ); ?>">
            </div>
            <div class="oy-product-item-fields">
                <div class="oy-product-item-row">
                    <input type="text"
                           name="<?php echo esc_attr( $base ); ?>[name]"
                           value="<?php echo esc_attr( $name ); ?>"
                           placeholder="<?php esc_attr_e( 'Nombre del producto*', 'lealez' ); ?>"
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
                          placeholder="<?php esc_attr_e( 'Descripción del producto (opcional, máx 1000 caracteres)', 'lealez' ); ?>"
                          maxlength="1000"><?php echo esc_textarea( $desc ); ?></textarea>
                <div class="oy-product-item-row">
                    <input type="url"
                           name="<?php echo esc_attr( $base ); ?>[product_url]"
                           value="<?php echo esc_attr( $product_url ); ?>"
                           placeholder="<?php esc_attr_e( 'URL del producto (https://...)', 'lealez' ); ?>"
                           style="flex:1;"
                           maxlength="2048">
                    <input type="text"
                           name="<?php echo esc_attr( $base ); ?>[sku]"
                           value="<?php echo esc_attr( $sku ); ?>"
                           placeholder="<?php esc_attr_e( 'SKU / Ref.', 'lealez' ); ?>"
                           style="max-width:100px;"
                           maxlength="100">
                </div>
            </div>
            <div class="oy-product-item-actions">
                <button type="button"
                        class="oy-product-item-remove"
                        title="<?php esc_attr_e( 'Eliminar producto', 'lealez' ); ?>">×</button>
            </div>
        </div>
        <?php
    }

    /**
     * Render HTML para un producto destacado.
     *
     * @param int|string $feat_idx   Índice
     * @param string     $name       Nombre
     * @param string     $price      Precio
     * @param string     $desc       Descripción
     * @param string     $product_url URL del producto
     * @param int        $image_id   WP Media ID
     * @param string     $image_url  URL de la imagen
     */
    private function render_featured_item_html( $feat_idx, $name, $price, $desc, $product_url, $image_id, $image_url ) {
        $base      = "location_products_featured[{$feat_idx}]";
        $has_image = ! empty( $image_url );
        ?>
        <div class="oy-product-featured-item">
            <div>
                <div class="oy-product-featured-image"
                     title="<?php esc_attr_e( 'Clic para seleccionar imagen', 'lealez' ); ?>">
                    <?php if ( $has_image ) : ?>
                        <img src="<?php echo esc_url( $image_url ); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                    <?php else : ?>
                        <div style="color:#aaa;font-size:24px;text-align:center;">⭐<br><small style="font-size:10px;"><?php _e( 'Imagen', 'lealez' ); ?></small></div>
                    <?php endif; ?>
                </div>
                <input type="hidden"
                       class="oy-product-featured-image-id"
                       name="<?php echo esc_attr( $base ); ?>[image_id]"
                       value="<?php echo esc_attr( $image_id ?: '' ); ?>">
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;">
                <div style="display:flex;gap:8px;">
                    <input type="text"
                           name="<?php echo esc_attr( $base ); ?>[name]"
                           value="<?php echo esc_attr( $name ); ?>"
                           placeholder="<?php esc_attr_e( 'Nombre del producto*', 'lealez' ); ?>"
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
                <input type="url"
                       name="<?php echo esc_attr( $base ); ?>[product_url]"
                       value="<?php echo esc_attr( $product_url ); ?>"
                       placeholder="<?php esc_attr_e( 'URL del producto (opcional)', 'lealez' ); ?>"
                       style="width:100%;"
                       maxlength="2048">
            </div>
            <div>
                <button type="button"
                        class="oy-product-featured-remove"
                        style="color:#dc3232;cursor:pointer;background:none;border:none;font-size:18px;font-weight:bold;"
                        title="<?php esc_attr_e( 'Eliminar producto destacado', 'lealez' ); ?>">×</button>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    /**
     * AJAX: Sincronizar catálogo de productos desde Google My Business.
     * Acción: oy_sync_location_products
     *
     * Intenta el endpoint /products de la GMB API v4.
     * Si devuelve 404 (endpoint obsoleto), guarda aviso informativo
     * y devuelve success con api_available=false para que el usuario
     * pueda gestionar el catálogo manualmente.
     */
    public function ajax_sync_products() {

        // ── 1. Seguridad ─────────────────────────────────────────────────────
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, $this->ajax_nonce_action ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos suficientes.', 'lealez' ) ) );
        }

        // ── 2. Parámetros ─────────────────────────────────────────────────────
        $post_id     = isset( $_POST['post_id'] )     ? absint( wp_unslash( $_POST['post_id'] ) )     : 0;
        $business_id = isset( $_POST['business_id'] ) ? absint( wp_unslash( $_POST['business_id'] ) ) : 0;

        if ( ! $post_id || ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Parámetros inválidos (post_id o business_id vacíos).', 'lealez' ) ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'oy_location' !== $post->post_type ) {
            wp_send_json_error( array( 'message' => __( 'La ubicación indicada no existe.', 'lealez' ) ) );
        }

        if ( ! class_exists( 'Lealez_GMB_API' ) ) {
            wp_send_json_error( array( 'message' => __( 'Lealez_GMB_API no está disponible.', 'lealez' ) ) );
        }

        // ── 3. Obtener IDs GMB desde post meta ────────────────────────────────
        $account_id  = (string) get_post_meta( $post_id, 'gmb_account_id',  true );
        $location_id = (string) get_post_meta( $post_id, 'gmb_location_id', true );

        // Fallback: extraer desde gmb_location_name si los meta separados están vacíos
        if ( '' === $location_id || '' === $account_id ) {
            $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
            if ( '' !== $location_name && method_exists( 'Lealez_GMB_API', 'extract_location_id_from_resource_name' ) ) {
                // Intentamos extracción si Lealez_GMB_API expone el método (estático o de instancia)
                // Este bloque es defensivo; en producción account_id y location_id suelen estar en meta.
            }
        }

        if ( '' === $account_id || '' === $location_id ) {
            wp_send_json_error( array(
                'message' => __( 'No se encontraron los IDs de la ubicación en Google (gmb_account_id / gmb_location_id). Importa primero la ubicación desde el metabox de Configuración GMB.', 'lealez' ),
            ) );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[OY Products] ajax_sync_products START — post_id=' . $post_id . ' account_id=' . $account_id . ' location_id=' . $location_id );
        }

        // ── 4. Verificar método disponible ────────────────────────────────────
        if ( ! method_exists( 'Lealez_GMB_API', 'get_location_products' ) ) {
            $notice = __( 'El método get_location_products no está disponible en Lealez_GMB_API. Verifica que class-lealez-gmb-api.php esté actualizado.', 'lealez' );
            update_post_meta( $post_id, 'gmb_products_last_sync', time() );
            update_post_meta( $post_id, 'gmb_products_api_notice', $notice );
            wp_send_json_success( array(
                'message'           => $notice,
                'api_available'     => false,
                'sections_imported' => 0,
                'items_imported'    => 0,
            ) );
        }

        // ── 5. Llamar API de productos ────────────────────────────────────────
        update_post_meta( $post_id, 'gmb_products_last_sync', time() );

        $result = Lealez_GMB_API::get_location_products(
            $business_id,
            $account_id,
            $location_id,
            true // force_refresh siempre
        );

        // ── 6. Manejar error ──────────────────────────────────────────────────
        if ( is_wp_error( $result ) ) {
            $code      = $result->get_error_code();
            $message   = $result->get_error_message();
            $err_data  = $result->get_error_data();
            $http_code = is_array( $err_data ) ? (int) ( $err_data['code'] ?? 0 ) : 0;

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Products] ajax_sync_products — API ERROR (' . $code . ' / HTTP ' . $http_code . '): ' . $message );
                if ( is_array( $err_data ) && ! empty( $err_data['raw_body'] ) ) {
                    error_log( '[OY Products] ajax_sync_products — RAW BODY (500 chars): ' . substr( (string) $err_data['raw_body'], 0, 500 ) );
                }
            }

            // 404 = endpoint v4 /products no existe / fue discontinuado por Google
            $is_404 = (
                404 === $http_code
                || false !== strpos( (string) $message, '404' )
                || false !== strpos( (string) $code, 'not_found' )
            );

            if ( $is_404 ) {
                $notice = __( 'El catálogo de productos de Google My Business no está disponible para esta ubicación a través de la API (el endpoint /products de la API v4 ha sido discontinuado por Google). Puedes gestionar tu catálogo de productos manualmente en las secciones de abajo.', 'lealez' );
            } else {
                /* translators: 1: código de error, 2: mensaje de error */
                $notice = sprintf(
                    __( 'Error al consultar Google (%1$s): %2$s — Puedes gestionar el catálogo manualmente.', 'lealez' ),
                    esc_html( (string) $code ),
                    esc_html( $message )
                );
            }

            update_post_meta( $post_id, 'gmb_products_api_notice', $notice );

            // Devolvemos success (no error) para que el JS recargue la página
            // y muestre el aviso informativo en el metabox.
            wp_send_json_success( array(
                'message'           => $notice,
                'api_available'     => false,
                'sections_imported' => 0,
                'items_imported'    => 0,
            ) );
        }

        // ── 7. Procesar resultado exitoso ─────────────────────────────────────
        $sections_imported = 0;
        $items_imported    = 0;
        $mapped_sections   = $this->map_products_to_sections( $result );

        if ( ! empty( $mapped_sections ) ) {
            update_post_meta( $post_id, 'location_products_sections', $mapped_sections );
            delete_post_meta( $post_id, 'gmb_products_api_notice' );
            $sections_imported = count( $mapped_sections );
            foreach ( $mapped_sections as $sec ) {
                $items_imported += count( $sec['items'] ?? array() );
            }
            /* translators: 1: número de categorías, 2: número de productos */
            $message = sprintf(
                __( 'Sincronizado correctamente: %1$d categoría(s) con %2$d producto(s).', 'lealez' ),
                $sections_imported,
                $items_imported
            );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Products] ajax_sync_products OK: ' . $sections_imported . ' categorías, ' . $items_imported . ' productos.' );
            }
        } else {
            $message = __( 'Google My Business respondió correctamente pero no devolvió productos para esta ubicación. Puedes agregar el catálogo manualmente.', 'lealez' );
            update_post_meta( $post_id, 'gmb_products_api_notice', $message );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Products] ajax_sync_products — API OK pero sin productos. Keys: ' . wp_json_encode( is_array( $result ) ? array_keys( $result ) : 'no-array' ) );
            }
        }

        wp_send_json_success( array(
            'message'           => $message,
            'api_available'     => true,
            'sections_imported' => $sections_imported,
            'items_imported'    => $items_imported,
        ) );
    }

    // =========================================================================
    // MAPEO DE DATOS GMB
    // =========================================================================

    /**
     * Mapear la respuesta de la API de productos al formato interno
     * location_products_sections.
     *
     * Soporta dos formatos de respuesta conocidos de GMB API v4:
     *   - {categories: [{name, items: [{name, price, description, landingPageUrl}]}]}
     *   - {productItems: [{name, price, description, landingPageUrl}]}
     *
     * @param array $result Respuesta de Lealez_GMB_API::get_location_products()
     * @return array Sections en formato interno {name, items[], from_gmb}
     */
    private function map_products_to_sections( $result ) {
        if ( ! is_array( $result ) || empty( $result ) ) {
            return array();
        }

        $sections = array();

        // ── Formato 1: {categories: [{name, items: [...]}]} ──────────────────
        if ( ! empty( $result['categories'] ) && is_array( $result['categories'] ) ) {
            foreach ( $result['categories'] as $cat ) {
                if ( ! is_array( $cat ) ) continue;
                $sec_name  = sanitize_text_field( $cat['name'] ?? __( 'Productos', 'lealez' ) );
                $cat_items = is_array( $cat['items'] ?? null ) ? $cat['items'] : array();
                $items     = array();
                foreach ( $cat_items as $item ) {
                    if ( ! is_array( $item ) ) continue;
                    $item_name = sanitize_text_field( $item['name'] ?? '' );
                    if ( empty( $item_name ) ) continue;
                    $items[] = $this->map_product_item( $item );
                }
                $sections[] = array(
                    'name'     => $sec_name,
                    'items'    => $items,
                    'from_gmb' => 1,
                );
            }
            return $sections;
        }

        // ── Formato 2: {productItems: [{name, ...}]} (sin categorías) ────────
        if ( ! empty( $result['productItems'] ) && is_array( $result['productItems'] ) ) {
            $items = array();
            foreach ( $result['productItems'] as $item ) {
                if ( ! is_array( $item ) ) continue;
                $item_name = sanitize_text_field( $item['name'] ?? '' );
                if ( empty( $item_name ) ) continue;
                $items[] = $this->map_product_item( $item );
            }
            if ( ! empty( $items ) ) {
                $sections[] = array(
                    'name'     => __( 'Productos', 'lealez' ),
                    'items'    => $items,
                    'from_gmb' => 1,
                );
            }
            return $sections;
        }

        return array();
    }

    /**
     * Mapear un item de producto GMB al formato interno.
     *
     * @param array $item Item de la API de GMB
     * @return array Item en formato interno
     */
    private function map_product_item( $item ) {
        // Precio: puede venir como objeto {units, nanos} o como string
        $price_data = $item['price'] ?? $item['priceRange'] ?? array();
        $price_str  = '';
        if ( is_array( $price_data ) ) {
            $units = $price_data['units'] ?? $price_data['minUnits'] ?? '';
            $nanos = (int) ( $price_data['nanos'] ?? 0 );
            if ( '' !== (string) $units ) {
                $amount    = (float) $units + $nanos / 1e9;
                $price_str = number_format( $amount, 2, ',', '.' );
            }
        } elseif ( is_string( $price_data ) && '' !== $price_data ) {
            $price_str = sanitize_text_field( $price_data );
        }

        return array(
            'name'        => sanitize_text_field( $item['name'] ?? '' ),
            'price'       => sanitize_text_field( $price_str ),
            'description' => sanitize_textarea_field( $item['description'] ?? '' ),
            'product_url' => esc_url_raw( $item['landingPageUrl'] ?? $item['productUrl'] ?? '' ),
            'sku'         => sanitize_text_field( $item['productCode'] ?? $item['sku'] ?? '' ),
            'image_id'    => 0,
            'from_gmb'    => 1,
        );
    }

    // =========================================================================
    // GUARDADO
    // =========================================================================

    /**
     * Guardar datos del metabox al hacer save_post
     *
     * @param int     $post_id
     * @param WP_Post $post
     */
    public function save_meta_box( $post_id, $post ) {

        // Verificar nonce
        if ( ! isset( $_POST[ $this->nonce_name ] ) ||
             ! wp_verify_nonce( wp_unslash( $_POST[ $this->nonce_name ] ), $this->nonce_action ) ) {
            return;
        }

        // Autosave
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Permisos
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // ── Secciones / Categorías ──────────────────────────────────────────
        $sections_raw = isset( $_POST['location_products_sections'] ) && is_array( $_POST['location_products_sections'] )
            ? wp_unslash( $_POST['location_products_sections'] )
            : array();

        $sections_clean = array();
        foreach ( $sections_raw as $section ) {
            if ( ! is_array( $section ) ) continue;
            $sec_name = sanitize_text_field( $section['name'] ?? '' );
            if ( empty( $sec_name ) ) continue;

            $items_raw   = is_array( $section['items'] ?? null ) ? $section['items'] : array();
            $items_clean = array();
            foreach ( $items_raw as $item ) {
                if ( ! is_array( $item ) ) continue;
                $item_name = sanitize_text_field( $item['name'] ?? '' );
                if ( empty( $item_name ) ) continue;
                $items_clean[] = array(
                    'name'        => $item_name,
                    'price'       => sanitize_text_field( $item['price']       ?? '' ),
                    'description' => sanitize_textarea_field( $item['description'] ?? '' ),
                    'product_url' => esc_url_raw( (string) ( $item['product_url'] ?? '' ) ),
                    'sku'         => sanitize_text_field( $item['sku']          ?? '' ),
                    'image_id'    => absint( $item['image_id']                   ?? 0 ),
                    'from_gmb'    => ! empty( $item['from_gmb'] ) ? 1 : 0,
                );
            }
            $sections_clean[] = array(
                'name'     => $sec_name,
                'items'    => $items_clean,
                'from_gmb' => ! empty( $section['from_gmb'] ) ? 1 : 0,
            );
        }
        update_post_meta( $post_id, 'location_products_sections', $sections_clean );

        // ── Productos Destacados ────────────────────────────────────────────
        $featured_raw = isset( $_POST['location_products_featured'] ) && is_array( $_POST['location_products_featured'] )
            ? wp_unslash( $_POST['location_products_featured'] )
            : array();

        $featured_clean = array();
        foreach ( $featured_raw as $feat ) {
            if ( ! is_array( $feat ) ) continue;
            $feat_name = sanitize_text_field( $feat['name'] ?? '' );
            if ( empty( $feat_name ) ) continue;
            $featured_clean[] = array(
                'name'        => $feat_name,
                'price'       => sanitize_text_field( $feat['price']       ?? '' ),
                'description' => sanitize_textarea_field( $feat['description'] ?? '' ),
                'product_url' => esc_url_raw( (string) ( $feat['product_url'] ?? '' ) ),
                'image_id'    => absint( $feat['image_id']                   ?? 0 ),
            );
        }
        update_post_meta( $post_id, 'location_products_featured', $featured_clean );
    }

} // class OY_Location_Products_Metabox

endif; // class_exists OY_Location_Products_Metabox
