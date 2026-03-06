<?php
/**
 * OY Location GMB Reviews Metabox
 *
 * Módulo completo de Reseñas de Google My Business para oy_location.
 * Replica la experiencia nativa de GBP (Opiniones) dentro de la UI de Lealez:
 *   - Tabs: Todas / Respondidas / Sin responder
 *   - Visualización de reseñas con avatar, rating, fecha, texto
 *   - Responder, editar y borrar respuestas inline
 *   - Link + QR "Consigue más opiniones"
 *   - Sincronización manual con caché de 30 min
 *
 * API usada: Google My Business API v4 (legacy)
 *   GET    /v4/accounts/{a}/locations/{l}/reviews
 *   PUT    /v4/accounts/{a}/locations/{l}/reviews/{r}/reply
 *   DELETE /v4/accounts/{a}/locations/{l}/reviews/{r}/reply
 *
 * AJAX actions expuestos:
 *   oy_gmb_reviews_fetch        – Cargar / refrescar reseñas
 *   oy_gmb_reviews_reply        – Crear o actualizar respuesta
 *   oy_gmb_reviews_delete_reply – Borrar respuesta de propietario
 *
 * @package    Lealez
 * @subpackage CPTs/Metaboxes
 * @since      1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class OY_Location_GMB_Reviews_Metabox
 */
class OY_Location_GMB_Reviews_Metabox {

    /**
     * Nonce action para todas las operaciones de reseñas
     *
     * @var string
     */
    private $nonce_action = 'oy_gmb_reviews_nonce';

    /**
     * Constructor – registra hooks
     */
    public function __construct() {
        add_action( 'add_meta_boxes',    array( $this, 'register_meta_box' ) );
        add_action( 'admin_footer',      array( $this, 'render_inline_assets' ) );

        // AJAX – logueado (solo admin)
        add_action( 'wp_ajax_oy_gmb_reviews_fetch',        array( $this, 'ajax_fetch_reviews' ) );
        add_action( 'wp_ajax_oy_gmb_reviews_reply',        array( $this, 'ajax_reply_to_review' ) );
        add_action( 'wp_ajax_oy_gmb_reviews_delete_reply', array( $this, 'ajax_delete_review_reply' ) );
    }

    // =========================================================================
    // REGISTRO Y RENDERIZADO DEL METABOX
    // =========================================================================

    /**
     * Registra el metabox en la pantalla de edición de oy_location
     */
    public function register_meta_box() {
        add_meta_box(
            'oy_location_gmb_reviews',
            __( '⭐ Opiniones de Google My Business', 'lealez' ),
            array( $this, 'render_meta_box' ),
            'oy_location',
            'normal',
            'default'
        );
    }

    /**
     * Renderiza el HTML del metabox
     *
     * @param WP_Post $post
     */
    public function render_meta_box( $post ) {
        $post_id     = absint( $post->ID );
        $business_id = absint( get_post_meta( $post_id, 'parent_business_id', true ) );
        $location_name = get_post_meta( $post_id, 'gmb_location_id', true );
        $gmb_connected = $business_id ? (bool) get_post_meta( $business_id, 'gmb_connected', true ) : false;

        // Link "Consigue más opiniones" – usa el metadata de GMB o el campo guardado
        $review_url    = get_post_meta( $post_id, 'google_reviews_url', true );
        $last_sync     = get_post_meta( $post_id, '_gmb_reviews_last_sync', true );
        $cached_stats  = get_post_meta( $post_id, '_gmb_reviews_stats_cache', true );

        $avg_rating    = '';
        $total_reviews = '';
        if ( is_array( $cached_stats ) ) {
            $avg_rating    = isset( $cached_stats['averageRating'] )    ? round( (float) $cached_stats['averageRating'], 1 ) : '';
            $total_reviews = isset( $cached_stats['totalReviewCount'] ) ? (int) $cached_stats['totalReviewCount'] : '';
        }

        wp_nonce_field( $this->nonce_action, 'oy_gmb_reviews_nonce_field' );
        ?>
        <div id="oy-gmb-reviews-wrap"
             data-post-id="<?php echo esc_attr( $post_id ); ?>"
             data-business-id="<?php echo esc_attr( $business_id ); ?>"
             data-location="<?php echo esc_attr( $location_name ); ?>"
             data-nonce="<?php echo esc_attr( wp_create_nonce( $this->nonce_action ) ); ?>"
             data-review-url="<?php echo esc_attr( $review_url ); ?>">

            <?php if ( ! $gmb_connected || empty( $location_name ) ) : ?>
                <div class="oy-reviews-notice oy-reviews-notice--warning">
                    <span class="dashicons dashicons-warning"></span>
                    <?php if ( ! $gmb_connected ) : ?>
                        <?php esc_html_e( 'Esta ubicación no tiene una cuenta de Google My Business conectada. Conecta GMB en el negocio padre para gestionar reseñas.', 'lealez' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Esta ubicación aún no está vinculada a un perfil GMB. Selecciona la ubicación GMB en la sección de integración.', 'lealez' ); ?>
                    <?php endif; ?>
                </div>
            <?php else : ?>

                <!-- ENCABEZADO: rating global + acciones -->
                <div class="oy-reviews-header">
                    <div class="oy-reviews-header__rating" id="oy-reviews-global-rating">
                        <?php if ( $avg_rating !== '' ) : ?>
                            <span class="oy-reviews-header__score"><?php echo esc_html( $avg_rating ); ?></span>
                            <span class="oy-reviews-header__stars" aria-hidden="true">
                                <?php echo $this->render_stars( (float) $avg_rating ); ?>
                            </span>
                            <span class="oy-reviews-header__count">
                                <?php
                                printf(
                                    /* translators: %d: number of reviews */
                                    esc_html( _n( '(%d opinión)', '(%d opiniones)', (int) $total_reviews, 'lealez' ) ),
                                    (int) $total_reviews
                                );
                                ?>
                            </span>
                        <?php else : ?>
                            <span class="oy-reviews-header__no-data">
                                <?php esc_html_e( 'Cargando estadísticas…', 'lealez' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="oy-reviews-header__actions">
                        <?php if ( ! empty( $review_url ) ) : ?>
                            <button type="button"
                                    class="button oy-btn-get-reviews"
                                    id="oy-btn-get-more-reviews"
                                    title="<?php esc_attr_e( 'Consigue más opiniones', 'lealez' ); ?>">
                                <span class="dashicons dashicons-share"></span>
                                <?php esc_html_e( 'Consigue más opiniones', 'lealez' ); ?>
                            </button>
                        <?php endif; ?>

                        <button type="button"
                                class="button button-primary oy-btn-reviews-sync"
                                id="oy-btn-reviews-sync">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e( 'Sincronizar', 'lealez' ); ?>
                        </button>
                    </div>
                </div>

                <?php if ( $last_sync ) : ?>
                    <p class="oy-reviews-last-sync">
                        <?php
                        printf(
                            /* translators: %s: formatted date-time */
                            esc_html__( 'Última sincronización: %s', 'lealez' ),
                            esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last_sync ) )
                        );
                        ?>
                    </p>
                <?php endif; ?>

                <!-- TABS -->
                <div class="oy-reviews-tabs" role="tablist">
                    <button type="button"
                            class="oy-reviews-tab oy-reviews-tab--active"
                            role="tab"
                            data-tab="all"
                            aria-selected="true">
                        <?php esc_html_e( 'Todas', 'lealez' ); ?>
                        <span class="oy-tab-badge" id="oy-tab-badge-all">0</span>
                    </button>
                    <button type="button"
                            class="oy-reviews-tab"
                            role="tab"
                            data-tab="replied"
                            aria-selected="false">
                        <?php esc_html_e( 'Respondidas', 'lealez' ); ?>
                        <span class="oy-tab-badge" id="oy-tab-badge-replied">0</span>
                    </button>
                    <button type="button"
                            class="oy-reviews-tab"
                            role="tab"
                            data-tab="unreplied"
                            aria-selected="false">
                        <?php esc_html_e( 'Sin responder', 'lealez' ); ?>
                        <span class="oy-tab-badge oy-tab-badge--alert" id="oy-tab-badge-unreplied">0</span>
                    </button>
                </div>

                <!-- SORT -->
                <div class="oy-reviews-toolbar">
                    <label for="oy-reviews-sort" class="screen-reader-text">
                        <?php esc_html_e( 'Ordenar por', 'lealez' ); ?>
                    </label>
                    <select id="oy-reviews-sort" class="oy-reviews-sort">
                        <option value="updateTimestamp desc">
                            <?php esc_html_e( 'Más reciente', 'lealez' ); ?>
                        </option>
                        <option value="updateTimestamp asc">
                            <?php esc_html_e( 'Más antiguo', 'lealez' ); ?>
                        </option>
                        <option value="rating desc">
                            <?php esc_html_e( 'Mayor calificación', 'lealez' ); ?>
                        </option>
                        <option value="rating asc">
                            <?php esc_html_e( 'Menor calificación', 'lealez' ); ?>
                        </option>
                    </select>
                </div>

                <!-- LISTA DE RESEÑAS -->
                <div class="oy-reviews-list" id="oy-reviews-list" role="list">
                    <div class="oy-reviews-loading" id="oy-reviews-loading">
                        <span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
                        <?php esc_html_e( 'Cargando reseñas…', 'lealez' ); ?>
                    </div>
                    <div class="oy-reviews-empty" id="oy-reviews-empty" style="display:none;">
                        <span class="dashicons dashicons-smiley" style="font-size:2rem;width:2rem;height:2rem;color:#ccc;"></span>
                        <p><?php esc_html_e( 'No hay reseñas en esta categoría.', 'lealez' ); ?></p>
                    </div>
                </div>

                <!-- PAGINACIÓN -->
                <div class="oy-reviews-pagination" id="oy-reviews-pagination" style="display:none;">
                    <button type="button"
                            class="button oy-btn-load-more"
                            id="oy-btn-load-more">
                        <?php esc_html_e( 'Cargar más reseñas', 'lealez' ); ?>
                    </button>
                </div>

                <!-- ERROR GLOBAL -->
                <div class="oy-reviews-error" id="oy-reviews-error" style="display:none;"></div>

            <?php endif; // gmb_connected && location_name ?>

            <!-- MODAL: Consigue más opiniones -->
            <div id="oy-modal-get-reviews"
                 class="oy-modal-overlay"
                 style="display:none;"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="oy-modal-reviews-title">
                <div class="oy-modal-box">
                    <button type="button"
                            class="oy-modal-close"
                            id="oy-modal-reviews-close"
                            aria-label="<?php esc_attr_e( 'Cerrar', 'lealez' ); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                    <h2 id="oy-modal-reviews-title">
                        <?php esc_html_e( 'Consigue más opiniones', 'lealez' ); ?>
                    </h2>
                    <p class="oy-modal-desc">
                        <?php esc_html_e( 'Proporciona a los clientes un vínculo para dejar una opinión sobre tu empresa a Google.', 'lealez' ); ?>
                    </p>
                    <?php if ( ! empty( $review_url ) ) : ?>
                        <div class="oy-modal-review-link-wrap">
                            <label for="oy-modal-review-link">
                                <?php esc_html_e( 'Vínculo de la opinión', 'lealez' ); ?>
                            </label>
                            <div class="oy-modal-review-link-row">
                                <input type="text"
                                       id="oy-modal-review-link"
                                       class="oy-modal-review-link"
                                       value="<?php echo esc_url( $review_url ); ?>"
                                       readonly />
                                <button type="button"
                                        class="button oy-btn-copy-link"
                                        data-copy-target="oy-modal-review-link"
                                        title="<?php esc_attr_e( 'Copiar enlace', 'lealez' ); ?>">
                                    <span class="dashicons dashicons-clipboard"></span>
                                </button>
                            </div>
                        </div>
                        <div class="oy-modal-share-buttons">
                            <a href="mailto:?subject=<?php echo rawurlencode( __( 'Deja tu opinión', 'lealez' ) ); ?>&body=<?php echo rawurlencode( $review_url ); ?>"
                               class="button oy-btn-share oy-btn-share--email"
                               target="_blank"
                               rel="noopener">
                                <span class="dashicons dashicons-email-alt"></span>
                                <?php esc_html_e( 'Correo', 'lealez' ); ?>
                            </a>
                            <a href="https://wa.me/?text=<?php echo rawurlencode( $review_url ); ?>"
                               class="button oy-btn-share oy-btn-share--whatsapp"
                               target="_blank"
                               rel="noopener">
                                <?php esc_html_e( 'WhatsApp', 'lealez' ); ?>
                            </a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode( $review_url ); ?>"
                               class="button oy-btn-share oy-btn-share--facebook"
                               target="_blank"
                               rel="noopener">
                                <?php esc_html_e( 'Facebook', 'lealez' ); ?>
                            </a>
                        </div>
                    <?php else : ?>
                        <p class="oy-modal-no-link">
                            <?php esc_html_e( 'No hay un vínculo de reseña disponible. Sincroniza los datos de la ubicación para obtenerlo.', 'lealez' ); ?>
                        </p>
                    <?php endif; ?>
                    <p class="oy-modal-copy-confirm" id="oy-modal-copy-confirm" style="display:none;">
                        ✅ <?php esc_html_e( 'Enlace copiado al portapapeles.', 'lealez' ); ?>
                    </p>
                </div>
            </div>

        </div><!-- #oy-gmb-reviews-wrap -->
        <?php
    }

    // =========================================================================
    // HELPERS PHP
    // =========================================================================

    /**
     * Genera HTML de estrellas (llenas, medias y vacías) para un rating
     *
     * @param float $rating  0-5
     * @return string
     */
    private function render_stars( $rating ) {
        $html  = '';
        $full  = floor( $rating );
        $half  = ( $rating - $full ) >= 0.5 ? 1 : 0;
        $empty = 5 - $full - $half;

        for ( $i = 0; $i < $full; $i++ ) {
            $html .= '<span class="oy-star oy-star--full">★</span>';
        }
        if ( $half ) {
            $html .= '<span class="oy-star oy-star--half">★</span>';
        }
        for ( $i = 0; $i < $empty; $i++ ) {
            $html .= '<span class="oy-star oy-star--empty">☆</span>';
        }
        return $html;
    }

    // =========================================================================
    // CSS + JS (inline en admin_footer, solo en pantalla oy_location)
    // =========================================================================

    /**
     * Inyecta CSS y JS solo cuando se edita un oy_location
     */
    public function render_inline_assets() {
        $screen = get_current_screen();
        if ( ! $screen || 'oy_location' !== $screen->post_type ) {
            return;
        }
        $this->render_css();
        $this->render_js();
    }

    /**
     * CSS del módulo de reseñas
     */
    private function render_css() {
        ?>
        <style id="oy-gmb-reviews-css">
        /* =====================================================================
           OY GMB Reviews Metabox – Styles
           ===================================================================== */
        #oy-gmb-reviews-wrap {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        /* Aviso sin conexión */
        .oy-reviews-notice {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            border-radius: 4px;
            font-size: 13px;
        }
        .oy-reviews-notice--warning {
            background: #fff8e1;
            border-left: 4px solid #ffc107;
            color: #6d4c00;
        }

        /* Header */
        .oy-reviews-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 10px;
        }
        .oy-reviews-header__rating {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .oy-reviews-header__score {
            font-size: 28px;
            font-weight: 700;
            color: #1a1a1a;
            line-height: 1;
        }
        .oy-reviews-header__stars {
            font-size: 22px;
            line-height: 1;
        }
        .oy-star--full, .oy-star--half { color: #f9ab00; }
        .oy-star--empty { color: #ddd; }
        .oy-reviews-header__count {
            font-size: 13px;
            color: #555;
        }
        .oy-reviews-header__actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .oy-btn-get-reviews .dashicons,
        .oy-btn-reviews-sync .dashicons {
            vertical-align: middle;
            margin-right: 4px;
            font-size: 16px;
            width: 16px;
            height: 16px;
        }

        /* Last sync */
        .oy-reviews-last-sync {
            font-size: 11px;
            color: #888;
            margin: 0 0 10px;
        }

        /* Tabs */
        .oy-reviews-tabs {
            display: flex;
            gap: 0;
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 12px;
        }
        .oy-reviews-tab {
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 500;
            color: #555;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: color 0.15s, border-color 0.15s;
        }
        .oy-reviews-tab:hover { color: #1a73e8; }
        .oy-reviews-tab--active {
            color: #1a73e8;
            border-bottom-color: #1a73e8;
        }
        .oy-tab-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 5px;
            border-radius: 10px;
            background: #e8eaed;
            font-size: 11px;
            font-weight: 600;
            color: #555;
            line-height: 1;
        }
        .oy-tab-badge--alert { background: #ea4335; color: #fff; }

        /* Toolbar */
        .oy-reviews-toolbar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
        }
        .oy-reviews-sort {
            font-size: 12px;
            height: 30px;
            padding: 0 6px;
            border-color: #ccc;
            border-radius: 4px;
        }

        /* Loading / empty */
        .oy-reviews-loading,
        .oy-reviews-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 8px;
            padding: 32px 16px;
            color: #888;
            font-size: 13px;
        }

        /* Lista */
        .oy-reviews-list { list-style: none; margin: 0; padding: 0; }

        /* Tarjeta de reseña */
        .oy-review-card {
            border: 1px solid #e8eaed;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            background: #fff;
            transition: box-shadow 0.15s;
        }
        .oy-review-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .oy-review-card--new { border-left: 3px solid #1a73e8; }

        /* Cabecera de la tarjeta */
        .oy-review-card__head {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 10px;
        }
        .oy-review-card__avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #1a73e8;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 600;
            flex-shrink: 0;
            overflow: hidden;
        }
        .oy-review-card__avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .oy-review-card__meta { flex: 1; }
        .oy-review-card__author {
            font-size: 14px;
            font-weight: 600;
            color: #1a1a1a;
            margin: 0 0 2px;
        }
        .oy-review-card__sub {
            font-size: 11px;
            color: #888;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .oy-review-card__stars { font-size: 14px; }
        .oy-review-card__date { }
        .oy-review-card__badge-new {
            display: inline-block;
            padding: 1px 7px;
            background: transparent;
            border: 1px solid #1a1a1a;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .05em;
            color: #1a1a1a;
        }

        /* Cuerpo de la reseña */
        .oy-review-card__comment {
            font-size: 13px;
            color: #333;
            line-height: 1.5;
            margin: 0 0 10px;
        }
        .oy-review-card__comment--truncated {
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
        }
        .oy-review-read-more {
            background: none;
            border: none;
            color: #1a73e8;
            font-size: 12px;
            cursor: pointer;
            padding: 0;
            margin-bottom: 8px;
        }

        /* Respuesta del propietario */
        .oy-review-reply {
            background: #f8f9fa;
            border-left: 3px solid #1a73e8;
            border-radius: 0 6px 6px 0;
            padding: 12px 14px;
            margin-top: 10px;
        }
        .oy-review-reply__head {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }
        .oy-review-reply__owner-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #1a73e8;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            flex-shrink: 0;
        }
        .oy-review-reply__owner-name {
            font-size: 13px;
            font-weight: 600;
            color: #1a1a1a;
        }
        .oy-review-reply__badge {
            display: flex;
            align-items: center;
            gap: 3px;
            font-size: 11px;
            color: #555;
        }
        .oy-review-reply__badge .dashicons {
            font-size: 14px;
            width: 14px;
            height: 14px;
            color: #1a73e8;
        }
        .oy-review-reply__date {
            font-size: 11px;
            color: #888;
            margin-left: auto;
        }
        .oy-review-reply__comment {
            font-size: 13px;
            color: #333;
            line-height: 1.5;
            margin: 0 0 10px;
        }
        .oy-review-reply__actions {
            display: flex;
            gap: 8px;
        }
        .oy-btn-edit-reply,
        .oy-btn-delete-reply {
            background: none;
            border: none;
            font-size: 12px;
            cursor: pointer;
            padding: 2px 0;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .oy-btn-edit-reply { color: #1a73e8; }
        .oy-btn-delete-reply { color: #ea4335; }
        .oy-btn-edit-reply .dashicons,
        .oy-btn-delete-reply .dashicons {
            font-size: 14px;
            width: 14px;
            height: 14px;
        }

        /* Formulario de respuesta inline */
        .oy-reply-form {
            margin-top: 12px;
            background: #f0f4ff;
            border-radius: 6px;
            padding: 14px;
        }
        .oy-reply-form__header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #1a1a1a;
        }
        .oy-reply-form__header .dashicons {
            color: #1a73e8;
            font-size: 18px;
            width: 18px;
            height: 18px;
        }
        .oy-reply-textarea {
            width: 100%;
            min-height: 90px;
            font-size: 13px;
            padding: 10px 12px;
            border: 1px solid #c5c5c5;
            border-radius: 6px;
            resize: vertical;
            box-sizing: border-box;
            line-height: 1.5;
            transition: border-color 0.15s;
        }
        .oy-reply-textarea:focus {
            border-color: #1a73e8;
            outline: none;
            box-shadow: 0 0 0 2px rgba(26,115,232,.15);
        }
        .oy-reply-form__footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 8px;
        }
        .oy-reply-char-count {
            font-size: 11px;
            color: #888;
        }
        .oy-reply-char-count--over { color: #ea4335; font-weight: 600; }
        .oy-reply-form__buttons { display: flex; gap: 8px; }
        .oy-btn-reply-submit { font-size: 13px; }
        .oy-btn-reply-cancel {
            background: none;
            border: none;
            color: #1a73e8;
            font-size: 13px;
            cursor: pointer;
            padding: 4px 8px;
        }

        /* Botón responder (sin reply aún) */
        .oy-btn-add-reply {
            margin-top: 10px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .oy-btn-add-reply .dashicons {
            font-size: 14px;
            width: 14px;
            height: 14px;
        }

        /* Mensaje de error en tarjeta */
        .oy-review-card__error {
            margin-top: 8px;
            padding: 8px 12px;
            background: #fce8e6;
            border-radius: 4px;
            color: #c5221f;
            font-size: 12px;
            display: none;
        }

        /* Paginación */
        .oy-reviews-pagination {
            text-align: center;
            padding: 12px 0 4px;
        }

        /* Error global */
        .oy-reviews-error {
            padding: 12px 16px;
            background: #fce8e6;
            border-radius: 6px;
            color: #c5221f;
            font-size: 13px;
            margin-top: 10px;
        }

        /* MODAL */
        .oy-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.55);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .oy-modal-box {
            background: #fff;
            border-radius: 8px;
            padding: 28px 32px;
            max-width: 520px;
            width: 90%;
            position: relative;
            box-shadow: 0 8px 32px rgba(0,0,0,.18);
        }
        .oy-modal-box h2 {
            margin: 0 0 8px;
            font-size: 18px;
            color: #1a1a1a;
        }
        .oy-modal-desc {
            font-size: 13px;
            color: #555;
            margin-bottom: 20px;
        }
        .oy-modal-close {
            position: absolute;
            top: 14px; right: 14px;
            background: none; border: none;
            cursor: pointer; padding: 4px;
            color: #555;
        }
        .oy-modal-close:hover { color: #1a1a1a; }
        .oy-modal-review-link-wrap label {
            display: block;
            font-size: 12px;
            color: #888;
            margin-bottom: 4px;
        }
        .oy-modal-review-link-row {
            display: flex;
            gap: 6px;
        }
        .oy-modal-review-link {
            flex: 1;
            font-size: 13px;
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: #f8f9fa;
            color: #333;
        }
        .oy-btn-copy-link {
            flex-shrink: 0;
        }
        .oy-modal-share-buttons {
            display: flex;
            gap: 8px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        .oy-btn-share {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 13px;
        }
        .oy-btn-share--whatsapp { background: #25d366; color: #fff !important; border-color: #25d366; }
        .oy-btn-share--whatsapp:hover { background: #1da851 !important; }
        .oy-btn-share--facebook { background: #1877f2; color: #fff !important; border-color: #1877f2; }
        .oy-btn-share--facebook:hover { background: #166fe5 !important; }
        .oy-modal-copy-confirm {
            font-size: 12px;
            color: #188038;
            margin: 10px 0 0;
        }
        .oy-modal-no-link {
            font-size: 13px;
            color: #888;
            font-style: italic;
        }

        /* Spinner override dentro del metabox */
        #oy-gmb-reviews-wrap .spinner { vertical-align: middle; }
        </style>
        <?php
    }

    /**
     * JavaScript del módulo de reseñas
     */
    private function render_js() {
        ?>
        <script id="oy-gmb-reviews-js">
        (function($) {
            'use strict';

            /* ----------------------------------------------------------------
               Estado del módulo
            ---------------------------------------------------------------- */
            var $wrap        = null;
            var postId       = 0;
            var businessId   = 0;
            var locationName = '';
            var nonce        = '';
            var reviewUrl    = '';

            var allReviews   = [];  // cache completo de reseñas
            var nextPageToken = '';
            var currentTab   = 'all';
            var currentSort  = 'updateTimestamp desc';
            var isLoading    = false;

            /* ----------------------------------------------------------------
               Conversión de starRating string → número
            ---------------------------------------------------------------- */
            var STAR_MAP = { ONE: 1, TWO: 2, THREE: 3, FOUR: 4, FIVE: 5 };

            function starStringToNum(s) {
                return STAR_MAP[s] || 0;
            }

            function renderStarsHtml(num) {
                var html = '';
                for (var i = 1; i <= 5; i++) {
                    html += i <= num
                        ? '<span class="oy-star oy-star--full">★</span>'
                        : '<span class="oy-star oy-star--empty">☆</span>';
                }
                return html;
            }

            /* ----------------------------------------------------------------
               Utilidades de fecha
            ---------------------------------------------------------------- */
            function formatDate(isoString) {
                if (!isoString) return '';
                var d = new Date(isoString);
                if (isNaN(d.getTime())) return isoString;
                var now  = new Date();
                var diff = Math.floor((now - d) / 1000);

                if (diff < 60)   return 'Hace un momento';
                if (diff < 3600) return 'Hace ' + Math.floor(diff/60) + ' min';
                if (diff < 86400) return 'Hace ' + Math.floor(diff/3600) + ' h';

                var days = Math.floor(diff/86400);
                if (days < 7) return 'Hace ' + days + ' días';
                if (days < 30) return 'Hace ' + Math.floor(days/7) + ' sem';
                if (days < 365) return 'Hace ' + Math.floor(days/30) + ' meses';
                return 'Hace ' + Math.floor(days/365) + ' años';
            }

            function isNew(isoString) {
                if (!isoString) return false;
                var d = new Date(isoString);
                return (Date.now() - d.getTime()) < (7 * 86400 * 1000);
            }

            /* ----------------------------------------------------------------
               Extrae las iniciales del nombre del revisor
            ---------------------------------------------------------------- */
            function getInitial(name) {
                if (!name) return '?';
                return name.trim().charAt(0).toUpperCase();
            }

            /* ----------------------------------------------------------------
               Colores de avatar basados en inicial
            ---------------------------------------------------------------- */
            var AVATAR_COLORS = [
                '#1a73e8','#ea4335','#34a853','#fa7b17',
                '#8430ce','#007b83','#e52592','#e8710a'
            ];
            function avatarColor(initial) {
                var code = (initial.charCodeAt(0) || 65) - 65;
                return AVATAR_COLORS[code % AVATAR_COLORS.length];
            }

            /* ----------------------------------------------------------------
               Extrae el reviewId del resource name
               "accounts/X/locations/Y/reviews/Z" → "Z"
            ---------------------------------------------------------------- */
            function extractReviewId(resourceName) {
                if (!resourceName) return '';
                var parts = resourceName.split('/');
                return parts[parts.length - 1] || '';
            }

            /* ----------------------------------------------------------------
               Filtra la lista según tab activo
            ---------------------------------------------------------------- */
            function getFilteredReviews() {
                return allReviews.filter(function(r) {
                    if (currentTab === 'replied')   return !!(r.reviewReply && r.reviewReply.comment);
                    if (currentTab === 'unreplied') return !(r.reviewReply && r.reviewReply.comment);
                    return true;
                });
            }

            /* ----------------------------------------------------------------
               Ordena las reseñas según el sort actual
            ---------------------------------------------------------------- */
            function sortReviews(reviews) {
                var list = reviews.slice();
                if (currentSort === 'updateTimestamp desc') {
                    list.sort(function(a,b){ return new Date(b.updateTime||0) - new Date(a.updateTime||0); });
                } else if (currentSort === 'updateTimestamp asc') {
                    list.sort(function(a,b){ return new Date(a.updateTime||0) - new Date(b.updateTime||0); });
                } else if (currentSort === 'rating desc') {
                    list.sort(function(a,b){ return starStringToNum(b.starRating) - starStringToNum(a.starRating); });
                } else if (currentSort === 'rating asc') {
                    list.sort(function(a,b){ return starStringToNum(a.starRating) - starStringToNum(b.starRating); });
                }
                return list;
            }

            /* ----------------------------------------------------------------
               Actualiza los contadores de los tabs
            ---------------------------------------------------------------- */
            function updateTabBadges() {
                var replied   = allReviews.filter(function(r){ return !!(r.reviewReply && r.reviewReply.comment); }).length;
                var unreplied = allReviews.filter(function(r){ return !(r.reviewReply && r.reviewReply.comment); }).length;
                $('#oy-tab-badge-all').text(allReviews.length);
                $('#oy-tab-badge-replied').text(replied);
                $('#oy-tab-badge-unreplied').text(unreplied);
            }

            /* ----------------------------------------------------------------
               Renderiza una sola tarjeta de reseña
            ---------------------------------------------------------------- */
            function renderReviewCard(review) {
                var reviewId  = extractReviewId(review.name || '');
                var author    = (review.reviewer && review.reviewer.displayName) ? review.reviewer.displayName : 'Anónimo';
                var photoUrl  = (review.reviewer && review.reviewer.profilePhotoUrl) ? review.reviewer.profilePhotoUrl : '';
                var starNum   = starStringToNum(review.starRating || 'FIVE');
                var date      = formatDate(review.updateTime || review.createTime || '');
                var isRecent  = isNew(review.createTime || '');
                var comment   = review.comment || '';
                var hasReply  = !!(review.reviewReply && review.reviewReply.comment);
                var initial   = getInitial(author);
                var color     = avatarColor(initial);

                var avatarHtml = photoUrl
                    ? '<img src="' + photoUrl + '" alt="' + escHtml(author) + '" loading="lazy"/>'
                    : initial;

                var commentHtml = '';
                var isTruncated = comment.length > 200;
                if (comment) {
                    var displayComment = isTruncated ? comment.substring(0,200) + '…' : comment;
                    commentHtml = '<p class="oy-review-card__comment' + (isTruncated ? ' oy-review-card__comment--truncated' : '') + '">' +
                        escHtml(displayComment) + '</p>';
                    if (isTruncated) {
                        commentHtml += '<button type="button" class="oy-review-read-more" data-full="' + escAttr(comment) + '" data-short="' + escAttr(displayComment) + '">Ver la opinión completa</button>';
                    }
                } else {
                    commentHtml = '<p class="oy-review-card__comment" style="color:#aaa;font-style:italic">Sin comentario escrito</p>';
                }

                // Respuesta del propietario
                var replyHtml = '';
                if (hasReply) {
                    var replyDate    = formatDate(review.reviewReply.updateTime || '');
                    var replyComment = review.reviewReply.comment || '';
                    replyHtml = '<div class="oy-review-reply" data-review-id="' + escAttr(reviewId) + '">' +
                        '<div class="oy-review-reply__head">' +
                            '<div class="oy-review-reply__owner-avatar" style="background:' + color + ';">' + initial + '</div>' +
                            '<span class="oy-review-reply__owner-name">Propietario</span>' +
                            '<span class="oy-review-reply__badge"><span class="dashicons dashicons-yes-alt"></span>Propietario</span>' +
                            '<span class="oy-review-reply__date">' + escHtml(replyDate) + '</span>' +
                        '</div>' +
                        '<p class="oy-review-reply__comment">' + escHtml(replyComment) + '</p>' +
                        '<div class="oy-review-reply__actions">' +
                            '<button type="button" class="oy-btn-edit-reply" data-review-id="' + escAttr(reviewId) + '" data-review-name="' + escAttr(review.name||'') + '" data-current-reply="' + escAttr(replyComment) + '">' +
                                '<span class="dashicons dashicons-edit"></span>Editar' +
                            '</button>' +
                            '<button type="button" class="oy-btn-delete-reply" data-review-id="' + escAttr(reviewId) + '" data-review-name="' + escAttr(review.name||'') + '">' +
                                '<span class="dashicons dashicons-trash"></span>Borrar' +
                            '</button>' +
                        '</div>' +
                        '</div>';
                } else {
                    replyHtml = '<button type="button" class="button oy-btn-add-reply" data-review-id="' + escAttr(reviewId) + '" data-review-name="' + escAttr(review.name||'') + '">' +
                        '<span class="dashicons dashicons-format-chat"></span>Responder' +
                    '</button>';
                }

                var cardClass = 'oy-review-card' + (isRecent ? ' oy-review-card--new' : '');
                var newBadge  = isRecent ? '<span class="oy-review-card__badge-new">NUEVO</span>' : '';

                return '<div class="' + cardClass + '" data-review-id="' + escAttr(reviewId) + '" role="listitem">' +
                    '<div class="oy-review-card__head">' +
                        '<div class="oy-review-card__avatar" style="background:' + color + ';">' + avatarHtml + '</div>' +
                        '<div class="oy-review-card__meta">' +
                            '<p class="oy-review-card__author">' + escHtml(author) + '</p>' +
                            '<div class="oy-review-card__sub">' +
                                '<span class="oy-review-card__stars">' + renderStarsHtml(starNum) + '</span>' +
                                '<span class="oy-review-card__date">' + escHtml(date) + '</span>' +
                                newBadge +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    commentHtml +
                    replyHtml +
                    '<div class="oy-review-card__error"></div>' +
                '</div>';
            }

            /* ----------------------------------------------------------------
               Renderiza toda la lista filtrada + ordenada
            ---------------------------------------------------------------- */
            function renderList() {
                var $list   = $('#oy-reviews-list');
                var $empty  = $('#oy-reviews-empty');
                var filtered = sortReviews(getFilteredReviews());

                // limpia solo las tarjetas (no el spinner ni el empty)
                $list.find('.oy-review-card').remove();

                if (filtered.length === 0) {
                    $empty.show();
                } else {
                    $empty.hide();
                    var html = '';
                    for (var i = 0; i < filtered.length; i++) {
                        html += renderReviewCard(filtered[i]);
                    }
                    $list.append(html);
                }

                updateTabBadges();

                // Paginación
                if (nextPageToken) {
                    $('#oy-reviews-pagination').show();
                } else {
                    $('#oy-reviews-pagination').hide();
                }
            }

            /* ----------------------------------------------------------------
               Actualiza el rating en el header
            ---------------------------------------------------------------- */
            function updateHeaderRating(averageRating, totalCount) {
                if (!averageRating) return;
                var rounded = parseFloat(averageRating).toFixed(1);
                var $rating = $('#oy-reviews-global-rating');
                $rating.html(
                    '<span class="oy-reviews-header__score">' + rounded + '</span>' +
                    '<span class="oy-reviews-header__stars" aria-hidden="true">' + renderStarsHtml(Math.round(parseFloat(averageRating))) + '</span>' +
                    '<span class="oy-reviews-header__count">(' + totalCount + ' opiniones)</span>'
                );
            }

            /* ----------------------------------------------------------------
               AJAX: cargar reseñas
            ---------------------------------------------------------------- */
            function loadReviews(append) {
                if (isLoading) return;
                isLoading = true;

                var $loading = $('#oy-reviews-loading');
                var $error   = $('#oy-reviews-error');
                $error.hide();
                $loading.show();

                if (!append) {
                    allReviews    = [];
                    nextPageToken = '';
                    $('#oy-reviews-list .oy-review-card').remove();
                }

                $.ajax({
                    url:    ajaxurl,
                    method: 'POST',
                    data: {
                        action:       'oy_gmb_reviews_fetch',
                        post_id:      postId,
                        business_id:  businessId,
                        location:     locationName,
                        page_token:   nextPageToken,
                        order_by:     currentSort,
                        security:     nonce
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            var data = response.data;

                            if (data.reviews && data.reviews.length) {
                                allReviews = append
                                    ? allReviews.concat(data.reviews)
                                    : data.reviews;
                            }
                            nextPageToken = data.nextPageToken || '';

                            if (data.averageRating) {
                                updateHeaderRating(data.averageRating, data.totalReviewCount || allReviews.length);
                            }
                        } else {
                            var msg = (response.data && response.data.message) ? response.data.message : 'Error desconocido.';
                            $error.text('Error al cargar reseñas: ' + msg).show();
                        }
                    },
                    error: function(xhr) {
                        $error.text('Error de conexión (' + xhr.status + '). Intenta de nuevo.').show();
                    },
                    complete: function() {
                        isLoading = false;
                        $loading.hide();
                        renderList();
                    }
                });
            }

            /* ----------------------------------------------------------------
               AJAX: responder / editar una reseña
            ---------------------------------------------------------------- */
            function submitReply($card, reviewId, reviewName, commentText) {
                var $btn    = $card.find('.oy-reply-form .oy-btn-reply-submit');
                var $error  = $card.find('.oy-review-card__error');
                $btn.prop('disabled', true).text('Enviando…');
                $error.hide();

                $.ajax({
                    url:    ajaxurl,
                    method: 'POST',
                    data: {
                        action:       'oy_gmb_reviews_reply',
                        post_id:      postId,
                        business_id:  businessId,
                        location:     locationName,
                        review_id:    reviewId,
                        review_name:  reviewName,
                        comment:      commentText,
                        security:     nonce
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            // Actualizar en allReviews
                            updateReviewInCache(reviewId, response.data.reviewReply);
                            renderList();
                        } else {
                            var msg = (response.data && response.data.message) ? response.data.message : 'Error desconocido.';
                            $error.text('Error: ' + msg).show();
                            $btn.prop('disabled', false).text('Responder');
                        }
                    },
                    error: function(xhr) {
                        $error.text('Error de conexión (' + xhr.status + ').').show();
                        $btn.prop('disabled', false).text('Responder');
                    }
                });
            }

            /* ----------------------------------------------------------------
               AJAX: borrar respuesta de propietario
            ---------------------------------------------------------------- */
            function deleteReply($card, reviewId, reviewName) {
                var $error = $card.find('.oy-review-card__error');
                $error.hide();

                if (!confirm('¿Seguro que deseas borrar tu respuesta? Esta acción no se puede deshacer.')) return;

                $.ajax({
                    url:    ajaxurl,
                    method: 'POST',
                    data: {
                        action:      'oy_gmb_reviews_delete_reply',
                        post_id:     postId,
                        business_id: businessId,
                        location:    locationName,
                        review_id:   reviewId,
                        review_name: reviewName,
                        security:    nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            updateReviewInCache(reviewId, null);
                            renderList();
                        } else {
                            var msg = (response.data && response.data.message) ? response.data.message : 'Error desconocido.';
                            $error.text('Error: ' + msg).show();
                        }
                    },
                    error: function(xhr) {
                        $error.text('Error de conexión (' + xhr.status + ').').show();
                    }
                });
            }

            /* ----------------------------------------------------------------
               Actualiza reviewReply en caché local
            ---------------------------------------------------------------- */
            function updateReviewInCache(reviewId, reviewReply) {
                for (var i = 0; i < allReviews.length; i++) {
                    var rid = extractReviewId(allReviews[i].name || '');
                    if (rid === reviewId) {
                        if (reviewReply) {
                            allReviews[i].reviewReply = reviewReply;
                        } else {
                            delete allReviews[i].reviewReply;
                        }
                        break;
                    }
                }
            }

            /* ----------------------------------------------------------------
               Muestra formulario de respuesta inline dentro de una tarjeta
            ---------------------------------------------------------------- */
            function showReplyForm($card, reviewId, reviewName, existingText) {
                // Elimina form previo si existe
                $card.find('.oy-reply-form').remove();

                var placeholder = 'Responder públicamente…';
                var formHtml =
                    '<div class="oy-reply-form">' +
                        '<div class="oy-reply-form__header"><span class="dashicons dashicons-format-chat"></span>Responder públicamente</div>' +
                        '<textarea class="oy-reply-textarea" maxlength="4000" placeholder="' + placeholder + '">' + escHtml(existingText || '') + '</textarea>' +
                        '<div class="oy-reply-form__footer">' +
                            '<span class="oy-reply-char-count"><span class="oy-char-current">0</span>/4,000</span>' +
                            '<div class="oy-reply-form__buttons">' +
                                '<button type="button" class="oy-btn-reply-cancel">Cancelar</button>' +
                                '<button type="button" class="button button-primary oy-btn-reply-submit" data-review-id="' + escAttr(reviewId) + '" data-review-name="' + escAttr(reviewName) + '">Responder</button>' +
                            '</div>' +
                        '</div>' +
                        '<p class="oy-modal-copy-confirm" style="font-size:11px;color:#888;margin:6px 0 0;">Este cliente recibirá una notificación sobre tu respuesta, que será visible públicamente en tu Perfil de Negocio</p>' +
                    '</div>';

                // Inserta después del botón o de la respuesta existente
                $card.append(formHtml);

                var $ta = $card.find('.oy-reply-textarea');
                // Init char count
                var initLen = $ta.val().length;
                $card.find('.oy-char-current').text(initLen);
                $ta.focus();
            }

            /* ----------------------------------------------------------------
               Escape helpers
            ---------------------------------------------------------------- */
            function escHtml(str) {
                return String(str)
                    .replace(/&/g,'&amp;')
                    .replace(/</g,'&lt;')
                    .replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;')
                    .replace(/'/g,'&#039;');
            }
            function escAttr(str) {
                return String(str)
                    .replace(/&/g,'&amp;')
                    .replace(/"/g,'&quot;')
                    .replace(/'/g,'&#039;');
            }

            /* ----------------------------------------------------------------
               Inicialización
            ---------------------------------------------------------------- */
            $(document).ready(function() {
                $wrap = $('#oy-gmb-reviews-wrap');
                if (!$wrap.length) return;

                postId       = parseInt($wrap.data('post-id'),   10) || 0;
                businessId   = parseInt($wrap.data('business-id'), 10) || 0;
                locationName = $wrap.data('location') || '';
                nonce        = $wrap.data('nonce')    || '';
                reviewUrl    = $wrap.data('review-url') || '';

                if (!postId || !businessId || !locationName) return;

                // Auto-carga al abrir el metabox
                loadReviews(false);

                /* --- Tabs --- */
                $(document).on('click', '.oy-reviews-tab', function() {
                    $('.oy-reviews-tab').removeClass('oy-reviews-tab--active').attr('aria-selected','false');
                    $(this).addClass('oy-reviews-tab--active').attr('aria-selected','true');
                    currentTab = $(this).data('tab');
                    renderList();
                });

                /* --- Sort --- */
                $(document).on('change', '.oy-reviews-sort', function() {
                    currentSort = $(this).val();
                    renderList();
                });

                /* --- Sync button --- */
                $(document).on('click', '#oy-btn-reviews-sync', function() {
                    loadReviews(false);
                });

                /* --- Load more --- */
                $(document).on('click', '#oy-btn-load-more', function() {
                    if (nextPageToken) loadReviews(true);
                });

                /* --- Ver más / menos en comentario --- */
                $(document).on('click', '.oy-review-read-more', function() {
                    var $btn  = $(this);
                    var $p    = $btn.prev('.oy-review-card__comment');
                    var full  = $btn.data('full');
                    var short = $btn.data('short');
                    if ($btn.text() === 'Ver la opinión completa') {
                        $p.text(full).removeClass('oy-review-card__comment--truncated');
                        $btn.text('Ver menos');
                    } else {
                        $p.text(short).addClass('oy-review-card__comment--truncated');
                        $btn.text('Ver la opinión completa');
                    }
                });

                /* --- Botón Responder (nueva) --- */
                $(document).on('click', '.oy-btn-add-reply', function() {
                    var $card      = $(this).closest('.oy-review-card');
                    var reviewId   = $(this).data('review-id');
                    var reviewName = $(this).data('review-name');
                    $(this).hide();
                    showReplyForm($card, String(reviewId), String(reviewName), '');
                });

                /* --- Botón Editar respuesta --- */
                $(document).on('click', '.oy-btn-edit-reply', function() {
                    var $card        = $(this).closest('.oy-review-card');
                    var reviewId     = $(this).data('review-id');
                    var reviewName   = $(this).data('review-name');
                    var currentReply = $(this).data('current-reply');
                    $card.find('.oy-review-reply').hide();
                    showReplyForm($card, String(reviewId), String(reviewName), String(currentReply));
                });

                /* --- Botón Borrar respuesta --- */
                $(document).on('click', '.oy-btn-delete-reply', function() {
                    var $card      = $(this).closest('.oy-review-card');
                    var reviewId   = $(this).data('review-id');
                    var reviewName = $(this).data('review-name');
                    deleteReply($card, String(reviewId), String(reviewName));
                });

                /* --- Char count en textarea de respuesta --- */
                $(document).on('input', '.oy-reply-textarea', function() {
                    var len      = $(this).val().length;
                    var $counter = $(this).closest('.oy-reply-form').find('.oy-char-current');
                    $counter.text(len);
                    if (len >= 4000) {
                        $counter.closest('.oy-reply-char-count').addClass('oy-reply-char-count--over');
                    } else {
                        $counter.closest('.oy-reply-char-count').removeClass('oy-reply-char-count--over');
                    }
                });

                /* --- Enviar respuesta --- */
                $(document).on('click', '.oy-btn-reply-submit', function() {
                    var $card      = $(this).closest('.oy-review-card');
                    var reviewId   = $(this).data('review-id');
                    var reviewName = $(this).data('review-name');
                    var $ta        = $card.find('.oy-reply-textarea');
                    var text       = $.trim($ta.val());
                    if (!text) {
                        $ta.focus();
                        return;
                    }
                    if (text.length > 4000) {
                        alert('La respuesta no puede superar los 4,000 caracteres.');
                        return;
                    }
                    submitReply($card, String(reviewId), String(reviewName), text);
                });

                /* --- Cancelar formulario de respuesta --- */
                $(document).on('click', '.oy-btn-reply-cancel', function() {
                    var $card = $(this).closest('.oy-review-card');
                    $card.find('.oy-reply-form').remove();
                    $card.find('.oy-review-reply').show();
                    $card.find('.oy-btn-add-reply').show();
                });

                /* --- Modal "Consigue más opiniones" --- */
                $(document).on('click', '#oy-btn-get-more-reviews', function() {
                    $('#oy-modal-get-reviews').show();
                });
                $(document).on('click', '#oy-modal-reviews-close, .oy-modal-overlay', function(e) {
                    if (e.target === this) {
                        $('#oy-modal-get-reviews').hide();
                    }
                });
                $(document).on('click', '.oy-modal-box', function(e) { e.stopPropagation(); });

                /* --- Copiar enlace --- */
                $(document).on('click', '.oy-btn-copy-link', function() {
                    var targetId = $(this).data('copy-target');
                    var val      = $('#' + targetId).val();
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(val).then(function() {
                            $('#oy-modal-copy-confirm').show().delay(3000).fadeOut();
                        });
                    } else {
                        var $tmp = $('<textarea>').val(val).appendTo('body').select();
                        document.execCommand('copy');
                        $tmp.remove();
                        $('#oy-modal-copy-confirm').show().delay(3000).fadeOut();
                    }
                });

                /* --- Teclado Escape cierra modal --- */
                $(document).on('keydown', function(e) {
                    if (e.key === 'Escape') {
                        $('#oy-modal-get-reviews').hide();
                    }
                });
            });

        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Carga / refresca reseñas desde GMB API v4.
     *
     * $_POST:
     *   post_id     int
     *   business_id int
     *   location    string  (resource name o location_id)
     *   page_token  string  (paginación)
     *   order_by    string  (ej: "updateTimestamp desc")
     *   security    string  (nonce)
     */
    public function ajax_fetch_reviews() {
        // Seguridad
        if ( ! check_ajax_referer( $this->nonce_action, 'security', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ), 403 );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ), 403 );
        }

        $post_id     = absint( $_POST['post_id']     ?? 0 );
        $business_id = absint( $_POST['business_id'] ?? 0 );
        $location    = sanitize_text_field( $_POST['location'] ?? '' );
        $page_token  = sanitize_text_field( $_POST['page_token'] ?? '' );
        $order_by    = sanitize_text_field( $_POST['order_by'] ?? 'updateTimestamp desc' );

        if ( ! $post_id || ! $business_id || empty( $location ) ) {
            wp_send_json_error( array( 'message' => __( 'Parámetros incompletos.', 'lealez' ) ) );
        }

        if ( ! class_exists( 'Lealez_GMB_API' ) ) {
            wp_send_json_error( array( 'message' => __( 'Lealez_GMB_API no disponible.', 'lealez' ) ) );
        }

        $result = Lealez_GMB_API::get_location_reviews(
            $business_id,
            $location,
            $page_token,
            50,
            $order_by,
            ! empty( $page_token ) ? false : true // no cache si es paginación manual
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ) );
        }

        // Actualiza stats cache + last_sync en el post meta
        if ( ! empty( $result['averageRating'] ) ) {
            update_post_meta( $post_id, '_gmb_reviews_stats_cache', array(
                'averageRating'    => $result['averageRating'],
                'totalReviewCount' => $result['totalReviewCount'] ?? 0,
            ) );
        }
        update_post_meta( $post_id, '_gmb_reviews_last_sync', time() );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Crea o actualiza una respuesta de propietario.
     *
     * $_POST:
     *   post_id      int
     *   business_id  int
     *   location     string
     *   review_id    string  (último segmento del resource name)
     *   review_name  string  (resource name completo, opcional pero preferido)
     *   comment      string
     *   security     string
     */
    public function ajax_reply_to_review() {
        if ( ! check_ajax_referer( $this->nonce_action, 'security', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ), 403 );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ), 403 );
        }

        $post_id     = absint( $_POST['post_id']     ?? 0 );
        $business_id = absint( $_POST['business_id'] ?? 0 );
        $location    = sanitize_text_field( $_POST['location']    ?? '' );
        $review_id   = sanitize_text_field( $_POST['review_id']   ?? '' );
        $review_name = sanitize_text_field( $_POST['review_name'] ?? '' );
        $comment     = sanitize_textarea_field( $_POST['comment'] ?? '' );

        if ( ! $post_id || ! $business_id || empty( $location ) || empty( $review_id ) || empty( $comment ) ) {
            wp_send_json_error( array( 'message' => __( 'Parámetros incompletos.', 'lealez' ) ) );
        }

        if ( mb_strlen( $comment ) > 4000 ) {
            wp_send_json_error( array( 'message' => __( 'La respuesta supera los 4,000 caracteres permitidos.', 'lealez' ) ) );
        }

        if ( ! class_exists( 'Lealez_GMB_API' ) ) {
            wp_send_json_error( array( 'message' => __( 'Lealez_GMB_API no disponible.', 'lealez' ) ) );
        }

        $result = Lealez_GMB_API::reply_to_review(
            $business_id,
            $location,
            $review_id,
            $comment,
            $review_name
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ) );
        }

        // Invalida caché de reseñas para que el siguiente fetch traiga datos frescos
        Lealez_GMB_API::clear_reviews_cache( $business_id, $location );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Elimina la respuesta de propietario a una reseña.
     *
     * $_POST:
     *   post_id      int
     *   business_id  int
     *   location     string
     *   review_id    string
     *   review_name  string
     *   security     string
     */
    public function ajax_delete_review_reply() {
        if ( ! check_ajax_referer( $this->nonce_action, 'security', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ), 403 );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ), 403 );
        }

        $post_id     = absint( $_POST['post_id']     ?? 0 );
        $business_id = absint( $_POST['business_id'] ?? 0 );
        $location    = sanitize_text_field( $_POST['location']    ?? '' );
        $review_id   = sanitize_text_field( $_POST['review_id']   ?? '' );
        $review_name = sanitize_text_field( $_POST['review_name'] ?? '' );

        if ( ! $post_id || ! $business_id || empty( $location ) || empty( $review_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Parámetros incompletos.', 'lealez' ) ) );
        }

        if ( ! class_exists( 'Lealez_GMB_API' ) ) {
            wp_send_json_error( array( 'message' => __( 'Lealez_GMB_API no disponible.', 'lealez' ) ) );
        }

        $result = Lealez_GMB_API::delete_review_reply(
            $business_id,
            $location,
            $review_id,
            $review_name
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ) );
        }

        Lealez_GMB_API::clear_reviews_cache( $business_id, $location );

        wp_send_json_success( array( 'deleted' => true ) );
    }

} // class OY_Location_GMB_Reviews_Metabox
