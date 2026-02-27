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
     * Constructor
     */
    public function __construct() {
        add_action( 'add_meta_boxes',            array( $this, 'add_meta_box' ) );
        add_action( 'save_post_oy_location',     array( $this, 'save_meta_box' ), 15, 2 );
        add_action( 'admin_enqueue_scripts',     array( $this, 'enqueue_assets' ) );
    }

    /**
     * Register metabox
     */
    public function add_meta_box() {
        add_meta_box(
            'oy_location_menu',
            __( '🍽️ Menú del Negocio', 'lealez' ),
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

        if ( ! is_array( $menu_photos ) )   $menu_photos   = array();
        if ( ! is_array( $menu_sections ) ) $menu_sections = array();
        if ( ! is_array( $menu_featured ) ) $menu_featured = array();

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
        </style>

        <div id="oy-menu-metabox-wrap">

            <?php /* ── URL del Menú (GMB Place Actions) — solo referencia ── */ ?>
            <div class="oy-menu-url-info">
                <strong>🔗 URL del Menú (GMB Place Actions)</strong>&nbsp;
                <?php if ( $menu_url ) : ?>
                    <a href="<?php echo esc_url( $menu_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $menu_url ); ?></a>
                    <span style="margin-left:6px;font-size:11px;color:#2271b1;background:#e8f0fe;border:1px solid #b3d4f5;border-radius:3px;padding:1px 6px;">🔄 GMB · MENU</span>
                <?php else : ?>
                    <span style="color:#888;"><?php _e( 'No configurada — se sincroniza desde GMB (Place Actions API → MENU)', 'lealez' ); ?></span>
                <?php endif; ?>
                <input type="hidden" name="location_menu_url" value="<?php echo esc_attr( $menu_url ); ?>">
            </div>

            <?php /* ── Tabs ── */ ?>
            <div class="oy-menu-tabs">
                <button type="button" class="oy-menu-tab-btn active" data-tab="complete"><?php _e( 'Menú Completo', 'lealez' ); ?></button>
                <button type="button" class="oy-menu-tab-btn" data-tab="photos"><?php _e( 'Fotos del Menú', 'lealez' ); ?></button>
                <button type="button" class="oy-menu-tab-btn" data-tab="featured"><?php _e( 'Platos Destacados', 'lealez' ); ?></button>
            </div>

            <?php /* ═══════════════════════════════════════════════════════
                   TAB 1: MENÚ COMPLETO (Secciones + Productos)
                   ═══════════════════════════════════════════════════════ */ ?>
            <div class="oy-menu-tab-panel active" id="oy-menu-tab-complete">

                <p class="description" style="margin-bottom:14px;">
                    <?php _e( 'Agrega secciones (ej: "Sopas", "Entradas", "Bebidas") y dentro de cada sección agrega los productos del menú con nombre, precio, descripción e imagen.', 'lealez' ); ?>
                </p>

                <div id="oy-menu-sections-list">
                    <?php foreach ( $menu_sections as $sec_idx => $section ) :
                        $sec_name  = sanitize_text_field( $section['name'] ?? '' );
                        $sec_items = is_array( $section['items'] ?? null ) ? $section['items'] : array();
                        ?>
                        <?php $this->render_section_html( $sec_idx, $sec_name, $sec_items ); ?>
                    <?php endforeach; ?>
                </div>

                <button type="button" id="oy-add-section" class="button button-primary">
                    + <?php _e( 'Agregar sección del menú', 'lealez' ); ?>
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

                <?php /* Fotos del Menú */ ?>
                <h4 style="margin:0 0 8px;"><?php _e( '📸 Fotos del Menú', 'lealez' ); ?></h4>
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
                    <?php _e( 'Las imágenes cargadas aquí se mostrarán en la pestaña "Menú" de tu Perfil de Negocio de Google.', 'lealez' ); ?>
                </p>
            </div>

            <?php /* ═══════════════════════════════════════════════════════
                   TAB 3: PLATOS DESTACADOS
                   ═══════════════════════════════════════════════════════ */ ?>
            <div class="oy-menu-tab-panel" id="oy-menu-tab-featured">

                <p class="description" style="margin-bottom:14px;">
                    <?php _e( 'Selecciona los platos o productos que quieres destacar en tu perfil. Estos aparecerán en la sección "Platos destacados" de Google Business Profile.', 'lealez' ); ?>
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
                    ⭐ <?php _e( 'Agregar plato destacado', 'lealez' ); ?>
                </button>
            </div>

        </div><!-- /#oy-menu-metabox-wrap -->

        <?php /* ── Templates HTML (ocultos) para JS ── */ ?>
        <script type="text/html" id="oy-section-template">
            <?php $this->render_section_html( '__SEC_IDX__', '', array() ); ?>
        </script>
        <script type="text/html" id="oy-item-template">
            <?php $this->render_item_html( '__SEC_IDX__', '__ITEM_IDX__', '', '', '', 0, '' ); ?>
        </script>
        <script type="text/html" id="oy-featured-template">
            <?php $this->render_featured_item_html( '__FEAT_IDX__', '', '', '', 0, '' ); ?>
        </script>

        <?php /* ── JavaScript ── */ ?>
        <script>
        (function($){
            'use strict';

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
            $('#oy-select-pdf').on('click', function(){
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

        })(jQuery);
        </script>
        <?php
    }

    /**
     * Render HTML for a menu section
     *
     * @param int|string $sec_idx  Índice de la sección
     * @param string     $name     Nombre de la sección
     * @param array      $items    Array de items
     */
    private function render_section_html( $sec_idx, $name, $items ) {
        $item_count = count( $items );
        ?>
        <div class="oy-menu-section" data-section-idx="<?php echo esc_attr( $sec_idx ); ?>">
            <div class="oy-menu-section-header">
                <span class="oy-section-handle" title="<?php esc_attr_e( 'Arrastrar para reordenar', 'lealez' ); ?>">⠿</span>
                <input type="text"
                       class="oy-section-name-input"
                       name="location_menu_sections[<?php echo esc_attr( $sec_idx ); ?>][name]"
                       value="<?php echo esc_attr( $name ); ?>"
                       placeholder="<?php esc_attr_e( 'Nombre de la sección (ej: Sopas, Entradas...)', 'lealez' ); ?>">
                <span class="oy-menu-section-count">
                    <?php echo sprintf( _n( '%d producto', '%d productos', $item_count, 'lealez' ), $item_count ); ?>
                </span>
                <button type="button" class="oy-section-toggle">▲</button>
                <button type="button" class="oy-section-remove" title="<?php esc_attr_e( 'Eliminar sección', 'lealez' ); ?>">×</button>
            </div>
            <div class="oy-menu-items-wrap">
                <?php foreach ( $items as $item_idx => $item ) :
                    $item_name  = sanitize_text_field( $item['name']        ?? '' );
                    $item_price = sanitize_text_field( $item['price']       ?? '' );
                    $item_desc  = sanitize_textarea_field( $item['description'] ?? '' );
                    $item_img   = (int) ( $item['image_id'] ?? 0 );
                    $item_img_url = $item_img ? wp_get_attachment_image_url( $item_img, 'thumbnail' ) : '';
                    $item_dietary = is_array( $item['dietary'] ?? null ) ? $item['dietary'] : array();
                    $this->render_item_html( $sec_idx, $item_idx, $item_name, $item_price, $item_desc, $item_img, $item_img_url, $item_dietary );
                endforeach; ?>
                <button type="button" class="button button-small oy-add-item-btn">
                    + <?php _e( 'Agregar un producto del menú', 'lealez' ); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Render HTML for a single menu item
     *
     * @param int|string $sec_idx
     * @param int|string $item_idx
     * @param string     $name
     * @param string     $price
     * @param string     $desc
     * @param int        $image_id
     * @param string     $image_url
     * @param array      $dietary
     */
    private function render_item_html( $sec_idx, $item_idx, $name, $price, $desc, $image_id, $image_url, $dietary = array() ) {
        $base = "location_menu_sections[{$sec_idx}][items][{$item_idx}]";
        $has_image = ! empty( $image_url );
        ?>
        <div class="oy-menu-item">
            <div>
                <div class="oy-menu-item-image" title="<?php esc_attr_e( 'Clic para seleccionar imagen', 'lealez' ); ?>">
                    <?php if ( $has_image ) : ?>
                        <img src="<?php echo esc_url( $image_url ); ?>" alt="">
                    <?php else : ?>
                        <div class="oy-img-placeholder">🖼️<br><small style="font-size:10px;"><?php _e( 'Imagen', 'lealez' ); ?></small></div>
                    <?php endif; ?>
                </div>
                <input type="hidden"
                       class="oy-item-image-id"
                       name="<?php echo esc_attr( $base ); ?>[image_id]"
                       value="<?php echo esc_attr( $image_id ?: '' ); ?>">
            </div>
            <div class="oy-menu-item-fields">
                <div class="oy-menu-item-row">
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
                           placeholder="<?php esc_attr_e( 'Precio (ej: 25000)', 'lealez' ); ?>"
                           style="max-width:140px;">
                </div>
                <textarea name="<?php echo esc_attr( $base ); ?>[description]"
                          placeholder="<?php esc_attr_e( 'Descripción del producto (opcional, máx 1000 caracteres)', 'lealez' ); ?>"
                          maxlength="1000"><?php echo esc_textarea( $desc ); ?></textarea>
                <?php /* Restricciones alimentarias */ ?>
                <div class="oy-dietary-badges">
                    <span style="font-size:11px;color:#888;align-self:center;"><?php _e( 'Restricciones:', 'lealez' ); ?></span>
                    <?php
                    $dietary_options = array(
                        'vegetarian' => __( '🥦 Vegetariano', 'lealez' ),
                        'vegan'      => __( '🌱 Vegano', 'lealez' ),
                        'gluten_free'=> __( '🚫🌾 Sin gluten', 'lealez' ),
                        'halal'      => __( '🌙 Halal', 'lealez' ),
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
                <button type="button" class="oy-item-remove" title="<?php esc_attr_e( 'Eliminar producto', 'lealez' ); ?>">×</button>
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
                           placeholder="<?php esc_attr_e( 'Nombre del plato*', 'lealez' ); ?>"
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
                          placeholder="<?php esc_attr_e( 'Descripción breve del plato', 'lealez' ); ?>"
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
     * Save metabox data
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

        // ── location_menu_url (hidden field — valor readonly de GMB) ────
        if ( isset( $_POST['location_menu_url'] ) ) {
            update_post_meta( $post_id, 'location_menu_url', esc_url_raw( wp_unslash( $_POST['location_menu_url'] ) ) );
        }

        // ── PDF del menú ────────────────────────────────────────────────
        if ( isset( $_POST['location_menu_pdf_id'] ) ) {
            $pdf_id = absint( $_POST['location_menu_pdf_id'] );
            if ( $pdf_id > 0 ) {
                update_post_meta( $post_id, 'location_menu_pdf_id', $pdf_id );
            } else {
                delete_post_meta( $post_id, 'location_menu_pdf_id' );
            }
        }

        // ── Fotos del menú ──────────────────────────────────────────────
        $menu_photos = array();
        if ( isset( $_POST['location_menu_photos'] ) && is_array( $_POST['location_menu_photos'] ) ) {
            foreach ( $_POST['location_menu_photos'] as $photo_id ) {
                $photo_id = absint( $photo_id );
                if ( $photo_id > 0 ) {
                    $menu_photos[] = $photo_id;
                }
            }
        }
        update_post_meta( $post_id, 'location_menu_photos', array_unique( $menu_photos ) );

        // ── Secciones del menú ──────────────────────────────────────────
        $menu_sections_raw = isset( $_POST['location_menu_sections'] ) && is_array( $_POST['location_menu_sections'] )
            ? wp_unslash( $_POST['location_menu_sections'] )
            : array();

        $menu_sections_clean = array();
        foreach ( $menu_sections_raw as $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }
            $sec_name = sanitize_text_field( $section['name'] ?? '' );
            if ( empty( $sec_name ) ) {
                continue; // Omitir secciones sin nombre
            }
            $items_raw   = is_array( $section['items'] ?? null ) ? $section['items'] : array();
            $items_clean = array();
            foreach ( $items_raw as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $item_name = sanitize_text_field( $item['name'] ?? '' );
                if ( empty( $item_name ) ) {
                    continue; // Omitir items sin nombre
                }
                $dietary_raw   = is_array( $item['dietary'] ?? null ) ? $item['dietary'] : array();
                $dietary_clean = array_map( 'sanitize_key', $dietary_raw );
                $valid_dietary = array( 'vegetarian', 'vegan', 'gluten_free', 'halal' );
                $dietary_clean = array_values( array_intersect( $dietary_clean, $valid_dietary ) );

                $items_clean[] = array(
                    'name'        => $item_name,
                    'price'       => sanitize_text_field( $item['price'] ?? '' ),
                    'description' => sanitize_textarea_field( $item['description'] ?? '' ),
                    'image_id'    => absint( $item['image_id'] ?? 0 ),
                    'dietary'     => $dietary_clean,
                );
            }
            $menu_sections_clean[] = array(
                'name'  => $sec_name,
                'items' => $items_clean,
            );
        }
        update_post_meta( $post_id, 'location_menu_sections', $menu_sections_clean );

        // ── Platos destacados ───────────────────────────────────────────
        $featured_raw = isset( $_POST['location_menu_featured_items'] ) && is_array( $_POST['location_menu_featured_items'] )
            ? wp_unslash( $_POST['location_menu_featured_items'] )
            : array();

        $featured_clean = array();
        foreach ( $featured_raw as $feat ) {
            if ( ! is_array( $feat ) ) {
                continue;
            }
            $feat_name = sanitize_text_field( $feat['name'] ?? '' );
            if ( empty( $feat_name ) ) {
                continue;
            }
            $featured_clean[] = array(
                'name'        => $feat_name,
                'price'       => sanitize_text_field( $feat['price'] ?? '' ),
                'description' => sanitize_textarea_field( $feat['description'] ?? '' ),
                'image_id'    => absint( $feat['image_id'] ?? 0 ),
            );
        }
        update_post_meta( $post_id, 'location_menu_featured_items', $featured_clean );
    }
}
