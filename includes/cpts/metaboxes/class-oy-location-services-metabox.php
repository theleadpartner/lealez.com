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
 * Archivo: includes/cpts/metaboxes/class-oy-location-services-metabox.php
 *
 * @package Lealez
 * @subpackage CPTs/Metaboxes
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'OY_Location_Services_Metabox' ) ) :

class OY_Location_Services_Metabox {

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
            __( '🛎️ Catálogo de Servicios', 'lealez' ),
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

        /* ── Items — grid simplificado sin imagen ─────────────────────────── */
        .oy-product-item {
            display:grid; grid-template-columns:1fr auto; gap:12px;
            align-items:start; padding:12px; margin-bottom:10px;
            border:1px solid #e8e8e8; border-radius:5px; background:#fafafa;
        }
        .oy-product-item-fields        { display:flex; flex-direction:column; gap:6px; }
        .oy-product-item-fields input[type=text],
        .oy-product-item-fields select,
        .oy-product-item-fields textarea { width:100%; box-sizing:border-box; }
        .oy-product-item-fields textarea { resize:vertical; min-height:54px; font-size:12px; }
        .oy-product-item-row   { display:flex; gap:8px; align-items:center; }
        .oy-item-price-row     { display:flex; gap:8px; align-items:center; }
        .oy-item-price-amount  { display:flex; align-items:center; gap:4px; }
        .oy-product-item-actions { display:flex; flex-direction:column; gap:4px; padding-top:2px; }
        .oy-product-item-remove  { color:#dc3232; cursor:pointer; background:none; border:none; font-size:18px; }
        .oy-add-product-item-btn { display:block; width:100%; text-align:center; margin-top:8px; }
        .oy-service-name-input   { font-size:13px; }
        .oy-service-desc-input   { font-size:12px; min-height:50px; }
        .oy-desc-counter         { font-size:11px; color:#999; text-align:right; display:block; }

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
                        <strong><?php _e( 'Servicios sincronizados desde Google My Business', 'lealez' ); ?></strong><br>
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
                    <?php _e( 'Servicios', 'lealez' ); ?>
                </button>
                <button type="button" class="oy-products-tab-btn" data-tab="featured">
                    <?php _e( 'Destacados', 'lealez' ); ?>
                </button>
            </div>

            <?php /* ══════════════════════════════════════════════════════
                   TAB 1: SERVICIOS (Categorías + Servicios)
                   ══════════════════════════════════════════════════════ */ ?>
            <div class="oy-products-tab-panel active" id="oy-products-tab-catalog">

                <p class="description" style="margin-bottom:14px;">
                    <?php _e( 'Organiza los servicios por categorías (ej: Marketing digital, Diseño web, Consultoría). Este metabox sincroniza con la sección "Servicios" de tu perfil de Google Business Profile. Para restaurantes, usa el metabox <strong>Menú del Restaurante</strong>.', 'lealez' ); ?>
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
                            <em><?php _e( 'Sin sincronizar aún — haz clic para importar los servicios desde Google', 'lealez' ); ?></em>
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
                    <?php _e( 'Cada categoría debe tener al menos un servicio. Puedes reordenar las categorías arrastrándolas.', 'lealez' ); ?>
                </p>
            </div>

            <?php /* ══════════════════════════════════════════════════════
                   TAB 2: SERVICIOS DESTACADOS
                   ══════════════════════════════════════════════════════ */ ?>
            <div class="oy-products-tab-panel" id="oy-products-tab-featured">

                <p class="description" style="margin-bottom:14px;">
                    <?php _e( 'Selecciona los servicios que quieres destacar en tu perfil de Google Business. Aparecerán en la sección de "Servicios destacados".', 'lealez' ); ?>
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
                    ⭐ <?php _e( 'Agregar servicio destacado', 'lealez' ); ?>
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
                if ( confirm('<?php echo esc_js( __( '¿Eliminar esta categoría y todos sus servicios?', 'lealez' ) ); ?>') ) {
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

            // ── Toggle visibilidad del monto según tipo de precio ───────
            // Aplica al cargar la página (estado inicial) y al cambiar el select
            function oy_toggle_price_amount( $select ) {
                var type    = $select.val();
                var $amount = $select.closest('.oy-item-price-row').find('.oy-item-price-amount');
                if ( type === 'no_price' || type === 'free' ) {
                    $amount.hide();
                    $amount.find('input').val('');
                } else {
                    $amount.show();
                }
            }

            // Al cambiar el select de precio
            $(document).on('change', '.oy-price-type-select', function(){
                oy_toggle_price_amount( $(this) );
            });

            // Inicializar en todos los selects existentes al cargar
            $('.oy-price-type-select').each(function(){
                oy_toggle_price_amount( $(this) );
            });

            // ── Contador de caracteres en descripción ───────────────────
            $(document).on('input', '.oy-service-desc-input', function(){
                var len     = $(this).val().length;
                var $counter = $(this).siblings('.oy-desc-counter');
                $counter.text( len + '/300' );
                if ( len >= 280 ) {
                    $counter.css('color', '#c0392b');
                } else {
                    $counter.css('color', '#999');
                }
            });

// ── Imagen de producto destacado ────────────────────────────
            $(document).on('click', '.oy-product-featured-image', function(){
                var $thumb   = $(this);
                var $idInput = $thumb.siblings('input.oy-product-featured-image-id');
                var frame    = wp.media({
                    title:    '<?php echo esc_js( __( 'Seleccionar imagen del servicio destacado', 'lealez' ) ); ?>',
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
                       placeholder="<?php esc_attr_e( 'Nombre de la categoría (ej: Marketing, Diseño web...)', 'lealez' ); ?>">
                <?php if ( $from_gmb ) : ?>
                    <span style="display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:600;padding:2px 7px;border-radius:20px;background:#e8f0fe;border:1px solid #b3d4f5;color:#1a56a0;margin-left:4px;white-space:nowrap;">🔄 GMB</span>
                <?php endif; ?>
                <input type="hidden"
                       name="location_products_sections[<?php echo esc_attr( $sec_idx ); ?>][from_gmb]"
                       value="<?php echo $from_gmb ? '1' : '0'; ?>">
                <span class="oy-products-section-count">
                    <?php echo sprintf( _n( '%d servicio', '%d servicios', $item_count, 'lealez' ), $item_count ); ?>
                </span>
                <button type="button" class="oy-products-section-toggle">▲</button>
                <button type="button" class="oy-products-section-remove" title="<?php esc_attr_e( 'Eliminar categoría', 'lealez' ); ?>">×</button>
            </div>
            <div class="oy-products-items-wrap">
                <?php foreach ( $items as $item_idx => $item ) :
                    $item_name       = sanitize_text_field( $item['name']        ?? '' );
                    $item_price_raw  = sanitize_text_field( $item['price']       ?? '' );
                    $item_desc       = sanitize_textarea_field( $item['description'] ?? '' );
                    $item_from_gmb   = ! empty( $item['from_gmb'] ) ? 1 : 0;
                    // Inferir price_type para retrocompatibilidad con datos guardados sin este campo
                    if ( ! empty( $item['price_type'] ) ) {
                        $item_price_type = sanitize_text_field( $item['price_type'] );
                    } elseif ( '' !== $item_price_raw ) {
                        $item_price_type = 'fixed';
                    } else {
                        $item_price_type = 'no_price';
                    }
                    $this->render_item_html(
                        $sec_idx,
                        $item_idx,
                        $item_name,
                        $item_price_type,
                        $item_price_raw,
                        $item_desc,
                        $item_from_gmb
                    );
                endforeach; ?>
                <button type="button" class="button button-small oy-add-product-item-btn">
                    + <?php _e( 'Agregar un servicio', 'lealez' ); ?>
                </button>
            </div>
        </div>
        <?php
    }

/**
     * Render HTML para un servicio/producto individual.
     * Campos alineados con la UI de Google Business Profile:
     * nombre, tipo de precio (Sin precio/Gratis/Fijo/Desde), monto, descripción (máx 300).
     *
     * @param int|string $sec_idx
     * @param int|string $item_idx
     * @param string     $name
     * @param string     $price_type  no_price | free | fixed | from
     * @param string     $price       Monto/texto del precio
     * @param string     $desc
     * @param int        $from_gmb
     */
    private function render_item_html( $sec_idx, $item_idx, $name, $price_type = 'no_price', $price = '', $desc = '', $from_gmb = 0 ) {
        $base            = "location_products_sections[{$sec_idx}][items][{$item_idx}]";
        $show_amount     = in_array( $price_type, array( 'fixed', 'from' ), true );
        $amount_style    = $show_amount ? '' : 'display:none;';
        ?>
        <div class="oy-product-item">
            <div class="oy-product-item-fields">

                <input type="hidden"
                       name="<?php echo esc_attr( $base ); ?>[from_gmb]"
                       value="<?php echo ! empty( $from_gmb ) ? '1' : '0'; ?>">

                <?php /* ── Nombre del servicio ── */ ?>
                <div class="oy-product-item-row">
                    <input type="text"
                           name="<?php echo esc_attr( $base ); ?>[name]"
                           value="<?php echo esc_attr( $name ); ?>"
                           placeholder="<?php esc_attr_e( 'Nombre del servicio*', 'lealez' ); ?>"
                           class="regular-text oy-service-name-input"
                           style="flex:1;"
                           maxlength="140">
                    <?php if ( $from_gmb ) : ?>
                        <span style="font-size:10px;font-weight:600;padding:2px 7px;border-radius:20px;background:#e8f0fe;border:1px solid #b3d4f5;color:#1a56a0;white-space:nowrap;">🔄 GMB</span>
                    <?php endif; ?>
                </div>

                <?php /* ── Precio: tipo + monto (igual que UI de GBP) ── */ ?>
                <div class="oy-product-item-row oy-item-price-row">
                    <select name="<?php echo esc_attr( $base ); ?>[price_type]"
                            class="oy-price-type-select"
                            style="min-width:130px;">
                        <option value="no_price" <?php selected( $price_type, 'no_price' ); ?>><?php _e( 'Sin precio', 'lealez' ); ?></option>
                        <option value="free"     <?php selected( $price_type, 'free' ); ?>><?php _e( 'Gratis', 'lealez' ); ?></option>
                        <option value="fixed"    <?php selected( $price_type, 'fixed' ); ?>><?php _e( 'Fijo', 'lealez' ); ?></option>
                        <option value="from"     <?php selected( $price_type, 'from' ); ?>><?php _e( 'Desde', 'lealez' ); ?></option>
                    </select>
                    <span class="oy-item-price-amount" style="display:flex;align-items:center;gap:4px;<?php echo esc_attr( $amount_style ); ?>">
                        <input type="text"
                               name="<?php echo esc_attr( $base ); ?>[price]"
                               value="<?php echo esc_attr( $price ); ?>"
                               placeholder="<?php esc_attr_e( 'Ej: 300000', 'lealez' ); ?>"
                               style="max-width:140px;">
                    </span>
                </div>

                <?php /* ── Descripción del servicio (máx 300, igual que GBP) ── */ ?>
                <textarea name="<?php echo esc_attr( $base ); ?>[description]"
                          placeholder="<?php esc_attr_e( 'Descripción del servicio (opcional, máx 300 caracteres)', 'lealez' ); ?>"
                          maxlength="300"
                          class="oy-service-desc-input"><?php echo esc_textarea( $desc ); ?></textarea>
                <span class="oy-desc-counter" style="font-size:11px;color:#888;text-align:right;display:block;">
                    <?php echo mb_strlen( $desc ); ?>/300
                </span>

            </div>
            <div class="oy-product-item-actions">
                <button type="button"
                        class="oy-product-item-remove"
                        title="<?php esc_attr_e( 'Eliminar servicio', 'lealez' ); ?>">×</button>
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
                           placeholder="<?php esc_attr_e( 'Nombre del servicio*', 'lealez' ); ?>"
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
                          placeholder="<?php esc_attr_e( 'Descripción breve del servicio', 'lealez' ); ?>"
                          style="width:100%;resize:vertical;min-height:54px;font-size:12px;"
                          maxlength="1000"><?php echo esc_textarea( $desc ); ?></textarea>
                <input type="url"
                       name="<?php echo esc_attr( $base ); ?>[product_url]"
                       value="<?php echo esc_attr( $product_url ); ?>"
                       placeholder="<?php esc_attr_e( 'URL del servicio (opcional)', 'lealez' ); ?>"
                       style="width:100%;"
                       maxlength="2048">
            </div>
            <div>
                <button type="button"
                        class="oy-product-featured-remove"
                        style="color:#dc3232;cursor:pointer;background:none;border:none;font-size:18px;font-weight:bold;"
                        title="<?php esc_attr_e( 'Eliminar servicio destacado', 'lealez' ); ?>">×</button>
            </div>
        </div>
        <?php
    }

    /**
     * Resolver account_id y location_id de Google a partir del post.
     *
     * Prioridad:
     * 1) gmb_account_id / gmb_location_id
     * 2) gmb_location_account_name
     * 3) gmb_location_name (accounts/{acc}/locations/{loc} o locations/{loc})
     *
     * @param int $post_id
     * @return array{
     *   account_id:string,
     *   location_id:string,
     *   location_name:string,
     *   account_name:string
     * }
     */
    private function resolve_google_ids_from_post( $post_id ) {
        $post_id = absint( $post_id );

        $account_id    = trim( (string) get_post_meta( $post_id, 'gmb_account_id', true ) );
        $location_id   = trim( (string) get_post_meta( $post_id, 'gmb_location_id', true ) );
        $location_name = trim( (string) get_post_meta( $post_id, 'gmb_location_name', true ) );
        $account_name  = trim( (string) get_post_meta( $post_id, 'gmb_location_account_name', true ) );

        if ( '' === $location_id && '' !== $location_name ) {
            $location_id = $this->extract_location_id_from_resource_name( $location_name );
        }

        if ( '' === $account_id && '' !== $account_name ) {
            $account_id = $this->extract_account_id_from_resource_name( $account_name );
        }

        if ( '' === $account_id && '' !== $location_name ) {
            $account_id = $this->extract_account_id_from_resource_name( $location_name );
        }

        return array(
            'account_id'    => $account_id,
            'location_id'   => $location_id,
            'location_name' => $location_name,
            'account_name'  => $account_name,
        );
    }

    /**
     * Extraer account_id desde un resource name.
     *
     * Soporta:
     * - accounts/123
     * - accounts/123/locations/456
     * - 123
     *
     * @param string $resource_name
     * @return string
     */
    private function extract_account_id_from_resource_name( $resource_name ) {
        $resource_name = trim( (string) $resource_name );

        if ( '' === $resource_name ) {
            return '';
        }

        if ( preg_match( '/^\d+$/', $resource_name ) ) {
            return $resource_name;
        }

        if ( preg_match( '~accounts/([^/]+)~', $resource_name, $matches ) ) {
            return trim( (string) $matches[1] );
        }

        return '';
    }

    /**
     * Extraer location_id desde un resource name.
     *
     * Soporta:
     * - locations/456
     * - accounts/123/locations/456
     * - 456
     *
     * @param string $resource_name
     * @return string
     */
    private function extract_location_id_from_resource_name( $resource_name ) {
        $resource_name = trim( (string) $resource_name );

        if ( '' === $resource_name ) {
            return '';
        }

        if ( preg_match( '/^\d+$/', $resource_name ) ) {
            return $resource_name;
        }

        if ( preg_match( '~locations/([^/]+)~', $resource_name, $matches ) ) {
            return trim( (string) $matches[1] );
        }

        return '';
    }
    
    // =========================================================================
    // AJAX
    // =========================================================================

/**
     * AJAX: Sincronizar catálogo de productos/servicios desde Google My Business.
     * Acción: oy_sync_location_products
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
        wp_send_json_error( array(
            'message' => __( 'Parámetros inválidos (post_id o business_id vacíos).', 'lealez' ),
        ) );
    }

    $post = get_post( $post_id );
    if ( ! $post || $this->post_type !== $post->post_type ) {
        wp_send_json_error( array(
            'message' => __( 'La ubicación indicada no existe.', 'lealez' ),
        ) );
    }

    if ( ! class_exists( 'Lealez_GMB_API' ) ) {
        wp_send_json_error( array(
            'message' => __( 'Lealez_GMB_API no está disponible.', 'lealez' ),
        ) );
    }

    if ( ! method_exists( 'Lealez_GMB_API', 'get_location_products' ) ) {
        wp_send_json_error( array(
            'message' => __( 'El método get_location_products no está disponible en Lealez_GMB_API.', 'lealez' ),
        ) );
    }

    // ── 3. Resolver IDs Google de forma robusta ──────────────────────────
    $google_ids  = $this->resolve_google_ids_from_post( $post_id );
    $account_id  = $google_ids['account_id'];
    $location_id = $google_ids['location_id'];

    if ( '' === $location_id ) {
        wp_send_json_error( array(
            'message' => __( 'No se pudo resolver el location_id de Google para esta ubicación. Reimporta la ubicación desde el metabox de Integración GMB.', 'lealez' ),
        ) );
    }

    if ( '' === $account_id ) {
        wp_send_json_error( array(
            'message' => __( 'No se pudo resolver el account_id de Google para esta ubicación. Reimporta la ubicación desde el metabox de Integración GMB.', 'lealez' ),
        ) );
    }

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log(
            '[OY Products] ajax_sync_products START — post_id=' . $post_id .
            ' business_id=' . $business_id .
            ' account_id=' . $account_id .
            ' location_id=' . $location_id
        );
    }

    // ── 4. Llamar API ─────────────────────────────────────────────────────
    $result = Lealez_GMB_API::get_location_products(
        $business_id,
        $account_id,
        $location_id,
        true
    );

    update_post_meta( $post_id, 'gmb_products_last_sync', time() );

    // Guardar respuesta de diagnóstico
    if ( is_wp_error( $result ) ) {
        update_post_meta( $post_id, '_gmb_debug_products_last_response', array(
            'status'      => 'error',
            'code'        => $result->get_error_code(),
            'message'     => $result->get_error_message(),
            'error_data'  => $result->get_error_data(),
            'account_id'  => $account_id,
            'location_id' => $location_id,
            'timestamp'   => current_time( 'mysql' ),
        ) );
    } else {
        update_post_meta( $post_id, '_gmb_debug_products_last_response', array(
            'status'      => 'ok',
            'keys'        => is_array( $result ) ? array_keys( $result ) : array(),
            'raw_head'    => substr( wp_json_encode( $result ), 0, 2000 ),
            'account_id'  => $account_id,
            'location_id' => $location_id,
            'timestamp'   => current_time( 'mysql' ),
        ) );
    }

    // ── 5. Manejo de error ────────────────────────────────────────────────
    if ( is_wp_error( $result ) ) {
        $error_code = (string) $result->get_error_code();
        $error_msg  = (string) $result->get_error_message();
        $error_data = $result->get_error_data();

        $http_code = 0;
        if ( is_array( $error_data ) && isset( $error_data['code'] ) ) {
            $http_code = (int) $error_data['code'];
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log(
                '[OY Products] ajax_sync_products ERROR — code=' . $error_code .
                ' http=' . $http_code .
                ' msg=' . $error_msg
            );
            if ( is_array( $error_data ) ) {
                error_log(
                    '[OY Products] ajax_sync_products ERROR_DATA: ' .
                    substr( wp_json_encode( $error_data ), 0, 2500 )
                );
            }
        }

        /**
         * 'service_list_endpoint_unavailable' — Google devolvió HTML 404.
         *
         * Esto NO significa que la ubicación no tenga servicios configurados.
         * Significa que el endpoint /serviceList no está disponible para el
         * proyecto de Google Cloud que usa este plugin.
         *
         * Causa más probable: en Google Cloud Console solo está habilitada
         * "Business Profile API" pero NO la legacy "Google My Business API" (v4).
         * El endpoint /serviceList pertenece a la API v4.
         *
         * Pasos para resolver:
         * 1. Ir a console.cloud.google.com → APIs y servicios → Biblioteca
         * 2. Buscar "Google My Business API"
         * 3. Habilitarla si no lo está
         * 4. Esperar ~5 minutos y volver a sincronizar
         */
        if ( 'service_list_endpoint_unavailable' === $error_code ) {
            $notice = __(
                'El endpoint de Servicios de Google Business Profile (API v4 /serviceList) no está accesible desde este proyecto de Google Cloud. Esto es independiente de si tienes servicios configurados en GBP. Para habilitarlo: ve a console.cloud.google.com → "APIs y servicios" → "Biblioteca" → busca "Google My Business API" → habilítala. Si ya está habilitada, verifica que la cuenta de servicio tenga los permisos correctos.',
                'lealez'
            );
            update_post_meta( $post_id, 'gmb_products_api_notice', $notice );
            wp_send_json_error( array(
                'message'    => $notice,
                'error_type' => 'endpoint_unavailable',
            ) );
        }

        /**
         * 'no_service_list_yet' — JSON 404: la ubicación no tiene serviceList.
         * Los servicios están en GBP pero el recurso serviceList aún no fue
         * creado (requiere guardar al menos un servicio desde GBP).
         */
        if ( 'no_service_list_yet' === $error_code ) {
            $notice = __(
                'Google respondió que esta ubicación aún no tiene servicios guardados en Google Business Profile (JSON 404 para /serviceList). Ve a tu perfil de Google → "Editar servicios" → guarda al menos un servicio → vuelve a sincronizar.',
                'lealez'
            );
            update_post_meta( $post_id, 'gmb_products_api_notice', $notice );
            wp_send_json_error( array(
                'message'    => $notice,
                'error_type' => 'no_service_list_yet',
            ) );
        }

        /**
         * 'products_api_error' — error real de API (auth, permisos, servidor).
         */
        if ( 'products_api_error' === $error_code ) {
            $notice = sprintf(
                __( 'Error al consultar Google Business Profile (HTTP %1$d): %2$s', 'lealez' ),
                $http_code,
                $error_msg
            );
            update_post_meta( $post_id, 'gmb_products_api_notice', $notice );
            wp_send_json_error( array(
                'message'    => $notice,
                'error_type' => 'api_error',
            ) );
        }

        /**
         * 'products_api_unavailable' — código legacy. Se mantiene para compatibilidad.
         */
        if ( 'products_api_unavailable' === $error_code ) {
            $notice = __(
                'Google Business Profile no devolvió datos de servicios utilizables para esta ubicación. El catálogo puede administrarse manualmente en Lealez.',
                'lealez'
            );
            update_post_meta( $post_id, 'gmb_products_api_notice', $notice );
            wp_send_json_error( array(
                'message'    => $notice,
                'error_type' => 'products_api_unavailable',
            ) );
        }

        // Cualquier otro error WP_Error no esperado
        $notice = sprintf(
            __( 'Error inesperado al consultar Google (%1$s): %2$s', 'lealez' ),
            $error_code,
            $error_msg
        );
        update_post_meta( $post_id, 'gmb_products_api_notice', $notice );

        wp_send_json_error( array(
            'message'    => $notice,
            'error_type' => 'unknown',
        ) );
    }

    // ── 6. Mapear respuesta ───────────────────────────────────────────────
    $mapped_sections = $this->map_products_to_sections( $result );

    if ( empty( $mapped_sections ) ) {
        $notice = __(
            'Google respondió correctamente con datos de serviceList, pero no se encontraron servicios con estructura utilizable. Verifica que los servicios tengan nombre y estén completamente configurados en tu perfil de Google Business Profile.',
            'lealez'
        );

        update_post_meta( $post_id, 'gmb_products_api_notice', $notice );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log(
                '[OY Products] ajax_sync_products EMPTY_AFTER_MAP — keys=' .
                wp_json_encode( is_array( $result ) ? array_keys( $result ) : array() )
            );
        }

        wp_send_json_error( array(
            'message'    => $notice,
            'error_type' => 'empty_result',
        ) );
    }

    // ── 7. Guardar metabox ────────────────────────────────────────────────
    update_post_meta( $post_id, 'location_products_sections', $mapped_sections );
    delete_post_meta( $post_id, 'gmb_products_api_notice' );

    $sections_imported = count( $mapped_sections );
    $items_imported    = 0;

    foreach ( $mapped_sections as $section ) {
        $items_imported += count( $section['items'] ?? array() );
    }

    $message = sprintf(
        __( 'Sincronizado correctamente: %1$d categoría(s) con %2$d elemento(s).', 'lealez' ),
        $sections_imported,
        $items_imported
    );

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log(
            '[OY Products] ajax_sync_products OK — sections=' . $sections_imported .
            ' items=' . $items_imported
        );
    }

    wp_send_json_success( array(
        'message'           => $message,
        'sections_imported' => $sections_imported,
        'items_imported'    => $items_imported,
    ) );
}

    // =========================================================================
    // MAPEO DE DATOS GMB
    // =========================================================================

/**
 * Mapear la respuesta de productos/servicios de Google al formato interno
 * location_products_sections.
 *
 * Soporta:
 * - serviceItems[] (Business Information API v1 — fuente actual)
 * - categories[] (formato retail legacy — array indexado)
 * - productItems[] (formato legacy)
 * - sections[] (formato serviceList legacy)
 * - array indexado plano
 *
 * @param array $result
 * @return array
 */
private function map_products_to_sections( $result ) {
    $sections = array();

    if ( ! is_array( $result ) || empty( $result ) ) {
        return $sections;
    }

    // Alias alternativo
    if ( empty( $result['productItems'] ) && ! empty( $result['items'] ) && is_array( $result['items'] ) ) {
        $result['productItems'] = $result['items'];
    }

    // Array plano
    if ( empty( $result['productItems'] ) && isset( $result[0] ) && is_array( $result[0] ) ) {
        $result['productItems'] = $result;
    }

    /**
     * ── Formato C: serviceItems[] — Business Information API v1 (fuente actual).
     * Se evalúa PRIMERO porque la respuesta v1 puede incluir también un objeto
     * 'categories' (con primaryCategory, additionalCategories) que no debe
     * confundirse con el Formato A de categorías de productos.
     * Incluye lookup de serviceTypeId → displayName usando 'categories' de la misma respuesta.
     */
    if ( ! empty( $result['serviceItems'] ) && is_array( $result['serviceItems'] ) ) {

        /**
         * Construir lookup: serviceTypeId → { displayName, categoryName }
         * a partir del objeto categories incluido cuando se solicitó
         * readMask=serviceItems,categories.
         *
         * Estructura esperada de $result['categories']:
         * {
         *   "primaryCategory": { "displayName": "...", "serviceTypes": [{serviceTypeId, displayName}] },
         *   "additionalCategories": [ { "displayName": "...", "serviceTypes": [...] } ]
         * }
         */
        $service_type_lookup = array();
        $categories_data     = $result['categories'] ?? array();

        $all_categories = array();
        if ( ! empty( $categories_data['primaryCategory'] ) && is_array( $categories_data['primaryCategory'] ) ) {
            $all_categories[] = $categories_data['primaryCategory'];
        }
        if ( ! empty( $categories_data['additionalCategories'] ) && is_array( $categories_data['additionalCategories'] ) ) {
            foreach ( $categories_data['additionalCategories'] as $add_cat ) {
                if ( is_array( $add_cat ) ) {
                    $all_categories[] = $add_cat;
                }
            }
        }

        foreach ( $all_categories as $cat ) {
            $cat_display_name = sanitize_text_field( (string) ( $cat['displayName'] ?? '' ) );
            if ( empty( $cat['serviceTypes'] ) || ! is_array( $cat['serviceTypes'] ) ) {
                continue;
            }
            foreach ( $cat['serviceTypes'] as $stype ) {
                $stype_id = (string) ( $stype['serviceTypeId'] ?? '' );
                if ( '' === $stype_id ) {
                    continue;
                }
                $service_type_lookup[ $stype_id ] = array(
                    'displayName'  => sanitize_text_field( (string) ( $stype['displayName'] ?? '' ) ),
                    'categoryName' => $cat_display_name,
                );
            }
        }

        $grouped = array();

        foreach ( $result['serviceItems'] as $item ) {
            if ( isset( $item['isOffered'] ) && false === $item['isOffered'] ) {
                continue;
            }

            $mapped = $this->map_product_item( $item, $service_type_lookup );

            if ( empty( $mapped['name'] ) ) {
                continue;
            }

            // ── Determinar categoría del item ────────────────────────────
            $category_name = '';

            if ( isset( $item['freeFormServiceItem'] ) && is_array( $item['freeFormServiceItem'] ) ) {
                // freeFormServiceItem.category = nombre de categoría libre definido por el negocio
                if ( ! empty( $item['freeFormServiceItem']['category'] ) ) {
                    $category_name = sanitize_text_field( (string) $item['freeFormServiceItem']['category'] );
                }
            } elseif ( isset( $item['structuredServiceItem'] ) && is_array( $item['structuredServiceItem'] ) ) {
                // Para items estructurados, la categoría es el displayName de la categoría padre en GBP
                $stype_id = (string) ( $item['structuredServiceItem']['serviceTypeId'] ?? '' );
                if ( ! empty( $service_type_lookup[ $stype_id ]['categoryName'] ) ) {
                    $category_name = $service_type_lookup[ $stype_id ]['categoryName'];
                }
            }

            // Fallbacks para formatos legacy o alternativos
            if ( '' === $category_name ) {
                if ( ! empty( $item['serviceType'] ) ) {
                    $category_name = sanitize_text_field( (string) $item['serviceType'] );
                } elseif ( ! empty( $item['category'] ) ) {
                    $category_name = sanitize_text_field( (string) $item['category'] );
                } elseif ( ! empty( $item['categoryName'] ) ) {
                    $category_name = sanitize_text_field( (string) $item['categoryName'] );
                }
            }

            if ( '' === $category_name ) {
                $category_name = __( 'Servicios', 'lealez' );
            }

            if ( ! isset( $grouped[ $category_name ] ) ) {
                $grouped[ $category_name ] = array();
            }

            $grouped[ $category_name ][] = $mapped;
        }

        foreach ( $grouped as $cat_name => $items ) {
            if ( ! empty( $items ) ) {
                $sections[] = array(
                    'name'     => $cat_name,
                    'items'    => $items,
                    'from_gmb' => 1,
                );
            }
        }

        return $sections;
    }

    /**
     * ── Formato A: categories[] — formato retail legacy (array indexado de categorías).
     *
     * IMPORTANTE: solo aplica cuando categories es un array indexado (0, 1, 2...)
     * de objetos de categoría con sus productos. NO aplica cuando categories es
     * el objeto de Business Information API v1 (que tiene clave 'primaryCategory').
     */
    if ( ! empty( $result['categories'] ) && is_array( $result['categories'] )
         && isset( $result['categories'][0] ) && is_array( $result['categories'][0] ) ) {

        foreach ( $result['categories'] as $category ) {
            if ( ! is_array( $category ) ) {
                continue;
            }

            $section_name = sanitize_text_field(
                (string) ( $category['displayName'] ?? $category['name'] ?? $category['categoryName'] ?? '' )
            );

            if ( '' === $section_name ) {
                $section_name = __( 'Catálogo', 'lealez' );
            }

            $items_raw = array();
            if ( ! empty( $category['items'] ) && is_array( $category['items'] ) ) {
                $items_raw = $category['items'];
            } elseif ( ! empty( $category['productItems'] ) && is_array( $category['productItems'] ) ) {
                $items_raw = $category['productItems'];
            } elseif ( ! empty( $category['products'] ) && is_array( $category['products'] ) ) {
                $items_raw = $category['products'];
            }

            $items_clean = array();
            foreach ( $items_raw as $item ) {
                $mapped = $this->map_product_item( $item );
                if ( ! empty( $mapped['name'] ) ) {
                    $items_clean[] = $mapped;
                }
            }

            if ( ! empty( $items_clean ) ) {
                $sections[] = array(
                    'name'     => $section_name,
                    'items'    => $items_clean,
                    'from_gmb' => 1,
                );
            }
        }

        return $sections;
    }

    // ── Formato B: productItems[] agrupado por categoría ─────────────────
    if ( ! empty( $result['productItems'] ) && is_array( $result['productItems'] ) ) {
        $grouped = array();

        foreach ( $result['productItems'] as $item ) {
            $mapped = $this->map_product_item( $item );

            if ( empty( $mapped['name'] ) ) {
                continue;
            }

            $category_name = sanitize_text_field(
                (string) ( $item['category'] ?? $item['categoryName'] ?? '' )
            );

            if ( '' === $category_name ) {
                $category_name = __( 'Catálogo', 'lealez' );
            }

            if ( ! isset( $grouped[ $category_name ] ) ) {
                $grouped[ $category_name ] = array();
            }

            $grouped[ $category_name ][] = $mapped;
        }

        foreach ( $grouped as $category_name => $items ) {
            if ( ! empty( $items ) ) {
                $sections[] = array(
                    'name'     => $category_name,
                    'items'    => $items,
                    'from_gmb' => 1,
                );
            }
        }

        return $sections;
    }

    /**
     * ── Formato D: serviceList con sections[] ─────────────────────────────
     */
    if ( ! empty( $result['sections'] ) && is_array( $result['sections'] ) ) {
        foreach ( $result['sections'] as $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }

            $section_name = sanitize_text_field(
                (string) (
                    $section['displayName'] ??
                    $section['name'] ??
                    $section['sectionName'] ??
                    $section['serviceType'] ??
                    ''
                )
            );

            if ( '' === $section_name ) {
                $section_name = __( 'Servicios / Catálogo', 'lealez' );
            }

            $items_raw = array();

            if ( ! empty( $section['serviceItems'] ) && is_array( $section['serviceItems'] ) ) {
                $items_raw = $section['serviceItems'];
            } elseif ( ! empty( $section['items'] ) && is_array( $section['items'] ) ) {
                $items_raw = $section['items'];
            }

            $items_clean = array();

            foreach ( $items_raw as $item ) {
                $mapped = $this->map_product_item( $item );
                if ( ! empty( $mapped['name'] ) ) {
                    $items_clean[] = $mapped;
                }
            }

            if ( ! empty( $items_clean ) ) {
                $sections[] = array(
                    'name'     => $section_name,
                    'items'    => $items_clean,
                    'from_gmb' => 1,
                );
            }
        }

        return $sections;
    }

    return $sections;
}

/**
     * Mapear un ítem de serviceItems de GBP al formato interno del metabox.
     *
     * Formatos soportados:
     *
     * 1. freeFormServiceItem (Business Information API v1):
     *    item.freeFormServiceItem.label.displayName  → name
     *    item.freeFormServiceItem.label.description  → description
     *    item.price {units, nanos, currencyCode}     → price + price_type
     *
     * 2. structuredServiceItem (Business Information API v1):
     *    item.structuredServiceItem.serviceTypeId    → lookup → name
     *    item.structuredServiceItem.description      → description (override)
     *    item.price                                  → price + price_type
     *
     * @param array $item
     * @param array $service_type_lookup  Mapa serviceTypeId → ['displayName', 'categoryName']
     * @return array
     */
    private function map_product_item( $item, $service_type_lookup = array() ) {
        if ( ! is_array( $item ) ) {
            return array(
                'name'        => '',
                'price_type'  => 'no_price',
                'price'       => '',
                'description' => '',
                'from_gmb'    => 1,
            );
        }

        $free_form  = isset( $item['freeFormServiceItem'] )   && is_array( $item['freeFormServiceItem'] )   ? $item['freeFormServiceItem']   : null;
        $structured = isset( $item['structuredServiceItem'] ) && is_array( $item['structuredServiceItem'] ) ? $item['structuredServiceItem'] : null;
        $free_label = ( $free_form && isset( $free_form['label'] ) && is_array( $free_form['label'] ) ) ? $free_form['label'] : null;

        // ── Nombre ───────────────────────────────────────────────────────────
        $name = '';

        if ( $free_label && ! empty( $free_label['displayName'] ) ) {
            $name = sanitize_text_field( (string) $free_label['displayName'] );
        }

        if ( '' === $name && $structured && ! empty( $structured['serviceTypeId'] ) ) {
            $service_type_id = (string) $structured['serviceTypeId'];
            // Primero intentar resolver desde el lookup de categorías
            if ( ! empty( $service_type_lookup[ $service_type_id ]['displayName'] ) ) {
                $name = sanitize_text_field( (string) $service_type_lookup[ $service_type_id ]['displayName'] );
            } else {
                // Fallback: formatear el ID legiblemente (quitar prefijo, reemplazar _ por espacios)
                $readable = preg_replace( '/^job_type_id:/', '', $service_type_id );
                $readable = str_replace( '_', ' ', $readable );
                $name     = sanitize_text_field( ucwords( $readable ) );
            }
        }

        // Legacy: otros formatos
        if ( '' === $name ) {
            if ( ! empty( $item['productTitle'] ) ) {
                $name = sanitize_text_field( (string) $item['productTitle'] );
            } elseif ( ! empty( $item['displayName'] ) ) {
                $name = sanitize_text_field( (string) $item['displayName'] );
            } elseif ( ! empty( $item['name'] ) ) {
                $raw_name = (string) $item['name'];
                if ( false === strpos( $raw_name, 'accounts/' ) && false === strpos( $raw_name, 'locations/' ) ) {
                    $name = sanitize_text_field( $raw_name );
                }
            }
        }

        // ── Descripción ──────────────────────────────────────────────────────
        $description = '';

        if ( $free_label && ! empty( $free_label['description'] ) ) {
            $description = sanitize_textarea_field( (string) $free_label['description'] );
        } elseif ( $structured && ! empty( $structured['description'] ) ) {
            $description = sanitize_textarea_field( (string) $structured['description'] );
        } elseif ( ! empty( $item['productDescription'] ) ) {
            $description = sanitize_textarea_field( (string) $item['productDescription'] );
        } elseif ( ! empty( $item['description'] ) ) {
            $description = sanitize_textarea_field( (string) $item['description'] );
        }
        // GBP limita a 300 caracteres
        if ( mb_strlen( $description ) > 300 ) {
            $description = mb_substr( $description, 0, 300 );
        }

        // ── Precio y tipo de precio ───────────────────────────────────────────
        // En Business Information API v1, el precio está en item.price (Money object).
        // No existe campo priceType explícito; se infiere del valor.
        $price_type = 'no_price';
        $price      = '';
        $price_data = null;

        if ( isset( $item['price'] ) ) {
            $price_data = $item['price'];
        } elseif ( isset( $item['priceRange'] ) ) {
            $price_data = $item['priceRange'];
        }

        if ( is_array( $price_data ) ) {
            $units        = isset( $price_data['units'] )        ? (string) $price_data['units']        : '';
            $nanos        = isset( $price_data['nanos'] )        ? (int)    $price_data['nanos']        : 0;
            $currencyCode = isset( $price_data['currencyCode'] ) ? sanitize_text_field( (string) $price_data['currencyCode'] ) : '';

            if ( '' !== $units && '0' !== $units ) {
                $amount = (float) $units;
                if ( 0 !== $nanos ) {
                    $amount += ( $nanos / 1000000000 );
                }
                // Mostrar número entero si es entero, con decimales si los tiene
                $price      = ( $amount == (int) $amount )
                    ? number_format( $amount, 0, '.', '' )
                    : number_format( $amount, 2, '.', '' );
                if ( '' !== $currencyCode ) {
                    $price .= ' ' . $currencyCode;
                }
                $price_type = 'fixed';
            } else {
                // units = '0' o vacío → gratis
                $price_type = 'free';
            }
        } elseif ( is_string( $price_data ) && '' !== trim( $price_data ) ) {
            $price      = sanitize_text_field( $price_data );
            $price_type = 'fixed';
        }

        return array(
            'name'        => $name,
            'price_type'  => $price_type,
            'price'       => $price,
            'description' => $description,
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

        if ( ! isset( $_POST[ $this->nonce_name ] ) ||
             ! wp_verify_nonce( wp_unslash( $_POST[ $this->nonce_name ] ), $this->nonce_action ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

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

                $price_type_raw = sanitize_text_field( $item['price_type'] ?? 'no_price' );
                $allowed_types  = array( 'no_price', 'free', 'fixed', 'from' );
                $price_type     = in_array( $price_type_raw, $allowed_types, true ) ? $price_type_raw : 'no_price';

                $items_clean[] = array(
                    'name'        => $item_name,
                    'price_type'  => $price_type,
                    'price'       => sanitize_text_field( $item['price'] ?? '' ),
                    'description' => mb_substr( sanitize_textarea_field( $item['description'] ?? '' ), 0, 300 ),
                    'from_gmb'    => ! empty( $item['from_gmb'] ) ? 1 : 0,
                    // Campos legacy — se preservan si vienen en POST para no perder datos existentes
                    'product_url' => esc_url_raw( (string) ( $item['product_url'] ?? '' ) ),
                    'sku'         => sanitize_text_field( $item['sku'] ?? '' ),
                    'image_id'    => absint( $item['image_id'] ?? 0 ),
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
                'price'       => sanitize_text_field( $feat['price'] ?? '' ),
                'description' => sanitize_textarea_field( $feat['description'] ?? '' ),
                'product_url' => esc_url_raw( (string) ( $feat['product_url'] ?? '' ) ),
                'image_id'    => absint( $feat['image_id'] ?? 0 ),
            );
        }
        update_post_meta( $post_id, 'location_products_featured', $featured_clean );
    }

} // class OY_Location_Services_Metabox

endif; // class_exists OY_Location_Services_Metabox
