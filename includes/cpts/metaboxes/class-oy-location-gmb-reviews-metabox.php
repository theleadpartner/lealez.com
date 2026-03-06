<?php
/**
 * OY Location GMB Reviews Metabox
 *
 * BUGS CORREGIDOS v1.1:
 *   1. Meta key '_gmb_connected' (con guion bajo) en lugar de 'gmb_connected'
 *   2. Campo ubicacion 'gmb_location_name' (resource name completo accounts/X/locations/Y)
 *      en lugar de 'gmb_location_id' (solo numero)
 *   3. La condicion de bloqueo ya NO oculta el metabox completo; muestra aviso banner
 *      pero siempre deja visible el boton de sincronizacion manual
 *   4. El boton Sincronizar fuerza force_refresh=1 (omite cache transient)
 *   5. NO auto-carga al abrir la pagina; espera accion del usuario
 *
 * API: Google My Business API v4 (legacy)
 *   GET    /v4/accounts/{a}/locations/{l}/reviews
 *   PUT    /v4/accounts/{a}/locations/{l}/reviews/{r}/reply
 *   DELETE /v4/accounts/{a}/locations/{l}/reviews/{r}/reply
 *
 * AJAX actions:
 *   oy_gmb_reviews_fetch        - Cargar / refrescar resenas
 *   oy_gmb_reviews_reply        - Crear o actualizar respuesta
 *   oy_gmb_reviews_delete_reply - Borrar respuesta de propietario
 *
 * @package    Lealez
 * @subpackage CPTs/Metaboxes
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OY_Location_GMB_Reviews_Metabox {

    private $nonce_action = 'oy_gmb_reviews_nonce';

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
        add_action( 'admin_footer',   array( $this, 'render_inline_assets' ) );

        add_action( 'wp_ajax_oy_gmb_reviews_fetch',        array( $this, 'ajax_fetch_reviews' ) );
        add_action( 'wp_ajax_oy_gmb_reviews_reply',        array( $this, 'ajax_reply_to_review' ) );
        add_action( 'wp_ajax_oy_gmb_reviews_delete_reply', array( $this, 'ajax_delete_review_reply' ) );
    }

    public function register_meta_box() {
        add_meta_box(
            'oy_location_gmb_reviews',
            __( 'Opiniones de Google My Business', 'lealez' ),
            array( $this, 'render_meta_box' ),
            'oy_location',
            'normal',
            'default'
        );
    }

    public function render_meta_box( $post ) {
        $post_id     = absint( $post->ID );
        $business_id = absint( get_post_meta( $post_id, 'parent_business_id', true ) );

        // FIX 1: Meta key correcto - el sistema escribe '_gmb_connected' (con guion bajo)
        $gmb_connected = $business_id
            ? (bool) get_post_meta( $business_id, '_gmb_connected', true )
            : false;

        // FIX 2: Usar 'gmb_location_name' = resource name completo (accounts/X/locations/Y)
        // Todos los handlers del sistema usan este campo, NO 'gmb_location_id'
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        $location_id   = (string) get_post_meta( $post_id, 'gmb_location_id', true );
        $account_id    = (string) get_post_meta( $post_id, 'gmb_account_id', true );

        // Si no tenemos el resource name completo pero si account_id + location_id, lo reconstruimos
        if ( empty( $location_name ) && ! empty( $account_id ) && ! empty( $location_id ) ) {
            $location_name = 'accounts/' . $account_id . '/locations/' . $location_id;
        }

        $review_url   = (string) get_post_meta( $post_id, 'google_reviews_url', true );
        $last_sync    = (int) get_post_meta( $post_id, '_gmb_reviews_last_sync', true );
        $cached_stats = get_post_meta( $post_id, '_gmb_reviews_stats_cache', true );

        $avg_rating    = '';
        $total_reviews = '';
        if ( is_array( $cached_stats ) ) {
            $avg_rating    = isset( $cached_stats['averageRating'] )    ? round( (float) $cached_stats['averageRating'], 1 ) : '';
            $total_reviews = isset( $cached_stats['totalReviewCount'] ) ? (int) $cached_stats['totalReviewCount'] : '';
        }

        $missing_business  = ! $business_id;
        $missing_connected = $business_id && ! $gmb_connected;
        $missing_location  = $business_id && $gmb_connected && empty( $location_name );
        $ready             = $business_id && $gmb_connected && ! empty( $location_name );

        wp_nonce_field( $this->nonce_action, 'oy_gmb_reviews_nonce_field' );
        ?>
        <div id="oy-gmb-reviews-wrap"
             data-post-id="<?php echo esc_attr( $post_id ); ?>"
             data-business-id="<?php echo esc_attr( $business_id ); ?>"
             data-location="<?php echo esc_attr( $location_name ); ?>"
             data-nonce="<?php echo esc_attr( wp_create_nonce( $this->nonce_action ) ); ?>"
             data-review-url="<?php echo esc_attr( $review_url ); ?>"
             data-ready="<?php echo $ready ? '1' : '0'; ?>">

            <?php if ( $missing_business ) : ?>
                <div class="oy-reviews-notice oy-reviews-notice--warning">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e( 'Esta ubicacion no tiene un negocio padre asignado. Selecciona el negocio primero.', 'lealez' ); ?>
                </div>
            <?php elseif ( $missing_connected ) : ?>
                <div class="oy-reviews-notice oy-reviews-notice--warning">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e( 'La cuenta de Google My Business no esta conectada. Ve al negocio padre y conecta GMB.', 'lealez' ); ?>
                </div>
            <?php elseif ( $missing_location ) : ?>
                <div class="oy-reviews-notice oy-reviews-notice--warning">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e( 'Esta ubicacion aun no esta vinculada a un perfil GMB. Selecciona la ubicacion en el selector de Integracion GMB y guarda.', 'lealez' ); ?>
                </div>
            <?php endif; ?>

            <div class="oy-reviews-topbar">
                <div class="oy-reviews-header__rating" id="oy-reviews-global-rating">
                    <?php if ( $avg_rating !== '' ) : ?>
                        <span class="oy-reviews-header__score"><?php echo esc_html( $avg_rating ); ?></span>
                        <span class="oy-reviews-header__stars" aria-hidden="true">
                            <?php echo $this->render_stars( (float) $avg_rating ); ?>
                        </span>
                        <span class="oy-reviews-header__count">
                            <?php
                            printf(
                                esc_html( _n( '(%d opinion)', '(%d opiniones)', (int) $total_reviews, 'lealez' ) ),
                                (int) $total_reviews
                            );
                            ?>
                        </span>
                    <?php else : ?>
                        <span class="oy-reviews-header__no-data">
                            <?php esc_html_e( 'Sin datos cargados aun', 'lealez' ); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="oy-reviews-topbar__actions">
                    <?php if ( ! empty( $review_url ) ) : ?>
                        <button type="button"
                                class="button oy-btn-get-reviews"
                                id="oy-btn-get-more-reviews">
                            <span class="dashicons dashicons-share"></span>
                            <?php esc_html_e( 'Consigue mas opiniones', 'lealez' ); ?>
                        </button>
                    <?php endif; ?>

                    <button type="button"
                            class="button button-primary oy-btn-reviews-sync"
                            id="oy-btn-reviews-sync"
                            title="<?php esc_attr_e( 'Obtener resenas directamente desde Google My Business (fuerza recarga sin cache)', 'lealez' ); ?>">
                        <span class="dashicons dashicons-update" id="oy-sync-icon"></span>
                        <?php esc_html_e( 'Sincronizar desde Google', 'lealez' ); ?>
                    </button>
                </div>
            </div>

            <p class="oy-reviews-last-sync" id="oy-reviews-last-sync-line"
               <?php echo $last_sync ? '' : 'style="display:none;"'; ?>>
                <?php
                if ( $last_sync ) {
                    printf(
                        esc_html__( 'Ultima sincronizacion: %s', 'lealez' ),
                        esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync ) )
                    );
                }
                ?>
            </p>

            <div id="oy-reviews-content" <?php echo $ready ? '' : 'style="display:none;"'; ?>>

                <div class="oy-reviews-tabs" role="tablist">
                    <button type="button" class="oy-reviews-tab oy-reviews-tab--active" role="tab" data-tab="all" aria-selected="true">
                        <?php esc_html_e( 'Todas', 'lealez' ); ?>
                        <span class="oy-tab-badge" id="oy-tab-badge-all">0</span>
                    </button>
                    <button type="button" class="oy-reviews-tab" role="tab" data-tab="replied" aria-selected="false">
                        <?php esc_html_e( 'Respondidas', 'lealez' ); ?>
                        <span class="oy-tab-badge" id="oy-tab-badge-replied">0</span>
                    </button>
                    <button type="button" class="oy-reviews-tab" role="tab" data-tab="unreplied" aria-selected="false">
                        <?php esc_html_e( 'Sin responder', 'lealez' ); ?>
                        <span class="oy-tab-badge oy-tab-badge--alert" id="oy-tab-badge-unreplied">0</span>
                    </button>
                </div>

                <div class="oy-reviews-toolbar">
                    <label for="oy-reviews-sort" class="screen-reader-text"><?php esc_html_e( 'Ordenar por', 'lealez' ); ?></label>
                    <select id="oy-reviews-sort" class="oy-reviews-sort">
                        <option value="updateTimestamp desc"><?php esc_html_e( 'Mas reciente', 'lealez' ); ?></option>
                        <option value="updateTimestamp asc"><?php esc_html_e( 'Mas antiguo', 'lealez' ); ?></option>
                        <option value="rating desc"><?php esc_html_e( 'Mayor calificacion', 'lealez' ); ?></option>
                        <option value="rating asc"><?php esc_html_e( 'Menor calificacion', 'lealez' ); ?></option>
                    </select>
                </div>

                <div class="oy-reviews-list" id="oy-reviews-list" role="list">
                    <div class="oy-reviews-loading" id="oy-reviews-loading" style="display:none;">
                        <span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
                        <?php esc_html_e( 'Cargando resenas desde Google...', 'lealez' ); ?>
                    </div>
                    <div class="oy-reviews-empty" id="oy-reviews-empty" style="display:none;">
                        <span class="dashicons dashicons-smiley" style="font-size:2rem;width:2rem;height:2rem;color:#ccc;"></span>
                        <p><?php esc_html_e( 'No hay resenas en esta categoria.', 'lealez' ); ?></p>
                    </div>
                    <div class="oy-reviews-placeholder" id="oy-reviews-placeholder">
                        <span class="dashicons dashicons-star-filled" style="font-size:2rem;width:2rem;height:2rem;color:#f9ab00;"></span>
                        <p><?php esc_html_e( 'Haz clic en "Sincronizar desde Google" para cargar las resenas.', 'lealez' ); ?></p>
                    </div>
                </div>

                <div class="oy-reviews-pagination" id="oy-reviews-pagination" style="display:none;">
                    <button type="button" class="button oy-btn-load-more" id="oy-btn-load-more">
                        <?php esc_html_e( 'Cargar mas resenas', 'lealez' ); ?>
                    </button>
                </div>

            </div>

            <div class="oy-reviews-error" id="oy-reviews-error" style="display:none;"></div>

            <div id="oy-modal-get-reviews" class="oy-modal-overlay" style="display:none;"
                 role="dialog" aria-modal="true" aria-labelledby="oy-modal-reviews-title">
                <div class="oy-modal-box">
                    <button type="button" class="oy-modal-close" id="oy-modal-reviews-close"
                            aria-label="<?php esc_attr_e( 'Cerrar', 'lealez' ); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                    <h2 id="oy-modal-reviews-title"><?php esc_html_e( 'Consigue mas opiniones', 'lealez' ); ?></h2>
                    <p class="oy-modal-desc">
                        <?php esc_html_e( 'Proporciona a los clientes un vinculo para dejar una opinion sobre tu empresa en Google.', 'lealez' ); ?>
                    </p>
                    <?php if ( ! empty( $review_url ) ) : ?>
                        <div class="oy-modal-review-link-wrap">
                            <label for="oy-modal-review-link"><?php esc_html_e( 'Vinculo de la opinion', 'lealez' ); ?></label>
                            <div class="oy-modal-review-link-row">
                                <input type="text" id="oy-modal-review-link" class="oy-modal-review-link"
                                       value="<?php echo esc_url( $review_url ); ?>" readonly />
                                <button type="button" class="button oy-btn-copy-link"
                                        data-copy-target="oy-modal-review-link">
                                    <span class="dashicons dashicons-clipboard"></span>
                                </button>
                            </div>
                        </div>
                        <div class="oy-modal-share-buttons">
                            <a href="mailto:?subject=<?php echo rawurlencode( __( 'Deja tu opinion', 'lealez' ) ); ?>&body=<?php echo rawurlencode( $review_url ); ?>"
                               class="button oy-btn-share oy-btn-share--email" target="_blank" rel="noopener">
                                <span class="dashicons dashicons-email-alt"></span><?php esc_html_e( 'Correo', 'lealez' ); ?>
                            </a>
                            <a href="https://wa.me/?text=<?php echo rawurlencode( $review_url ); ?>"
                               class="button oy-btn-share oy-btn-share--whatsapp" target="_blank" rel="noopener">
                                <?php esc_html_e( 'WhatsApp', 'lealez' ); ?>
                            </a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode( $review_url ); ?>"
                               class="button oy-btn-share oy-btn-share--facebook" target="_blank" rel="noopener">
                                <?php esc_html_e( 'Facebook', 'lealez' ); ?>
                            </a>
                        </div>
                    <?php else : ?>
                        <p class="oy-modal-no-link">
                            <?php esc_html_e( 'No hay un vinculo disponible. Sincroniza primero los datos de la ubicacion.', 'lealez' ); ?>
                        </p>
                    <?php endif; ?>
                    <p class="oy-modal-copy-confirm" id="oy-modal-copy-confirm" style="display:none;">
                        <?php esc_html_e( 'Enlace copiado al portapapeles.', 'lealez' ); ?>
                    </p>
                </div>
            </div>

        </div>
        <?php
    }

    private function render_stars( $rating ) {
        $html  = '';
        $full  = (int) floor( $rating );
        $half  = ( $rating - $full ) >= 0.5 ? 1 : 0;
        $empty = 5 - $full - $half;
        for ( $i = 0; $i < $full;  $i++ ) { $html .= '<span class="oy-star oy-star--full">&#9733;</span>'; }
        if ( $half )                        { $html .= '<span class="oy-star oy-star--half">&#9733;</span>'; }
        for ( $i = 0; $i < $empty; $i++ ) { $html .= '<span class="oy-star oy-star--empty">&#9734;</span>'; }
        return $html;
    }

    public function render_inline_assets() {
        $screen = get_current_screen();
        if ( ! $screen || 'oy_location' !== $screen->post_type ) {
            return;
        }
        $this->render_css();
        $this->render_js();
    }

    private function render_css() {
        ?>
<style id="oy-gmb-reviews-css">
#oy-gmb-reviews-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
.oy-reviews-notice{display:flex;align-items:flex-start;gap:8px;padding:12px 16px;border-radius:4px;font-size:13px;margin-bottom:12px;}
.oy-reviews-notice--warning{background:#fff8e1;border-left:4px solid #ffc107;color:#6d4c00;}
.oy-reviews-notice .dashicons{flex-shrink:0;margin-top:1px;}
.oy-reviews-topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:8px;}
.oy-reviews-header__rating{display:flex;align-items:center;gap:8px;}
.oy-reviews-header__score{font-size:28px;font-weight:700;color:#1a1a1a;line-height:1;}
.oy-reviews-header__stars{font-size:22px;line-height:1;}
.oy-star--full,.oy-star--half{color:#f9ab00;}
.oy-star--empty{color:#ddd;}
.oy-reviews-header__count{font-size:13px;color:#555;}
.oy-reviews-header__no-data{font-size:13px;color:#999;font-style:italic;}
.oy-reviews-topbar__actions{display:flex;align-items:center;gap:8px;}
#oy-btn-reviews-sync .dashicons{vertical-align:middle;margin-right:4px;font-size:16px;width:16px;height:16px;transition:transform .4s linear;}
#oy-btn-reviews-sync.oy-syncing .dashicons{animation:oy-spin .7s linear infinite;}
@keyframes oy-spin{to{transform:rotate(360deg);}}
.oy-btn-get-reviews .dashicons{vertical-align:middle;margin-right:4px;font-size:16px;width:16px;height:16px;}
.oy-reviews-last-sync{font-size:11px;color:#888;margin:0 0 10px;}
.oy-reviews-placeholder{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;padding:32px 16px;color:#888;font-size:13px;}
.oy-reviews-loading,.oy-reviews-empty{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;padding:32px 16px;color:#888;font-size:13px;}
.oy-reviews-tabs{display:flex;border-bottom:2px solid #e0e0e0;margin-bottom:12px;}
.oy-reviews-tab{background:none;border:none;border-bottom:3px solid transparent;margin-bottom:-2px;padding:8px 16px;font-size:13px;font-weight:500;color:#555;cursor:pointer;display:flex;align-items:center;gap:6px;transition:color .15s,border-color .15s;}
.oy-reviews-tab:hover{color:#1a73e8;}
.oy-reviews-tab--active{color:#1a73e8;border-bottom-color:#1a73e8;}
.oy-tab-badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 5px;border-radius:10px;background:#e8eaed;font-size:11px;font-weight:600;color:#555;line-height:1;}
.oy-tab-badge--alert{background:#ea4335;color:#fff;}
.oy-reviews-toolbar{display:flex;align-items:center;gap:8px;margin-bottom:14px;}
.oy-reviews-sort{font-size:12px;height:30px;padding:0 6px;border-color:#ccc;border-radius:4px;}
.oy-reviews-list{list-style:none;margin:0;padding:0;}
.oy-review-card{border:1px solid #e8eaed;border-radius:8px;padding:16px;margin-bottom:12px;background:#fff;transition:box-shadow .15s;}
.oy-review-card:hover{box-shadow:0 2px 8px rgba(0,0,0,.08);}
.oy-review-card--new{border-left:3px solid #1a73e8;}
.oy-review-card__head{display:flex;align-items:flex-start;gap:12px;margin-bottom:10px;}
.oy-review-card__avatar{width:40px;height:40px;border-radius:50%;background:#1a73e8;color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:600;flex-shrink:0;overflow:hidden;}
.oy-review-card__avatar img{width:100%;height:100%;object-fit:cover;}
.oy-review-card__meta{flex:1;}
.oy-review-card__author{font-size:14px;font-weight:600;color:#1a1a1a;margin:0 0 2px;}
.oy-review-card__sub{font-size:11px;color:#888;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.oy-review-card__stars{font-size:14px;}
.oy-review-card__badge-new{display:inline-block;padding:1px 7px;border:1px solid #1a1a1a;border-radius:3px;font-size:10px;font-weight:700;letter-spacing:.05em;color:#1a1a1a;}
.oy-review-card__comment{font-size:13px;color:#333;line-height:1.5;margin:0 0 10px;}
.oy-review-card__comment--truncated{overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;}
.oy-review-read-more{background:none;border:none;color:#1a73e8;font-size:12px;cursor:pointer;padding:0;margin-bottom:8px;}
.oy-review-reply{background:#f8f9fa;border-left:3px solid #1a73e8;border-radius:0 6px 6px 0;padding:12px 14px;margin-top:10px;}
.oy-review-reply__head{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
.oy-review-reply__owner-avatar{width:28px;height:28px;border-radius:50%;background:#1a73e8;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;}
.oy-review-reply__owner-name{font-size:13px;font-weight:600;color:#1a1a1a;}
.oy-review-reply__badge{display:flex;align-items:center;gap:3px;font-size:11px;color:#555;}
.oy-review-reply__badge .dashicons{font-size:14px;width:14px;height:14px;color:#1a73e8;}
.oy-review-reply__date{font-size:11px;color:#888;margin-left:auto;}
.oy-review-reply__comment{font-size:13px;color:#333;line-height:1.5;margin:0 0 10px;}
.oy-review-reply__actions{display:flex;gap:8px;}
.oy-btn-edit-reply,.oy-btn-delete-reply{background:none;border:none;font-size:12px;cursor:pointer;padding:2px 0;display:flex;align-items:center;gap:4px;}
.oy-btn-edit-reply{color:#1a73e8;}.oy-btn-delete-reply{color:#ea4335;}
.oy-btn-edit-reply .dashicons,.oy-btn-delete-reply .dashicons{font-size:14px;width:14px;height:14px;}
.oy-reply-form{margin-top:12px;background:#f0f4ff;border-radius:6px;padding:14px;}
.oy-reply-form__header{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:13px;font-weight:600;color:#1a1a1a;}
.oy-reply-form__header .dashicons{color:#1a73e8;font-size:18px;width:18px;height:18px;}
.oy-reply-textarea{width:100%;min-height:90px;font-size:13px;padding:10px 12px;border:1px solid #c5c5c5;border-radius:6px;resize:vertical;box-sizing:border-box;line-height:1.5;transition:border-color .15s;}
.oy-reply-textarea:focus{border-color:#1a73e8;outline:none;box-shadow:0 0 0 2px rgba(26,115,232,.15);}
.oy-reply-form__footer{display:flex;align-items:center;justify-content:space-between;margin-top:8px;}
.oy-reply-char-count{font-size:11px;color:#888;}
.oy-reply-char-count--over{color:#ea4335;font-weight:600;}
.oy-reply-form__buttons{display:flex;gap:8px;}
.oy-btn-reply-submit{font-size:13px;}
.oy-btn-reply-cancel{background:none;border:none;color:#1a73e8;font-size:13px;cursor:pointer;padding:4px 8px;}
.oy-btn-add-reply{margin-top:10px;font-size:12px;display:inline-flex;align-items:center;gap:4px;}
.oy-btn-add-reply .dashicons{font-size:14px;width:14px;height:14px;}
.oy-review-card__error{margin-top:8px;padding:8px 12px;background:#fce8e6;border-radius:4px;color:#c5221f;font-size:12px;display:none;}
.oy-reviews-pagination{text-align:center;padding:12px 0 4px;}
.oy-reviews-error{padding:12px 16px;background:#fce8e6;border-radius:6px;color:#c5221f;font-size:13px;margin-top:10px;}
.oy-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:center;justify-content:center;}
.oy-modal-box{background:#fff;border-radius:8px;padding:28px 32px;max-width:520px;width:90%;position:relative;box-shadow:0 8px 32px rgba(0,0,0,.18);}
.oy-modal-box h2{margin:0 0 8px;font-size:18px;color:#1a1a1a;}
.oy-modal-desc{font-size:13px;color:#555;margin-bottom:20px;}
.oy-modal-close{position:absolute;top:14px;right:14px;background:none;border:none;cursor:pointer;padding:4px;color:#555;}
.oy-modal-close:hover{color:#1a1a1a;}
.oy-modal-review-link-wrap label{display:block;font-size:12px;color:#888;margin-bottom:4px;}
.oy-modal-review-link-row{display:flex;gap:6px;}
.oy-modal-review-link{flex:1;font-size:13px;padding:8px 10px;border:1px solid #ddd;border-radius:6px;background:#f8f9fa;color:#333;}
.oy-modal-share-buttons{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;}
.oy-btn-share{display:inline-flex;align-items:center;gap:4px;font-size:13px;}
.oy-btn-share--whatsapp{background:#25d366!important;color:#fff!important;border-color:#25d366!important;}
.oy-btn-share--whatsapp:hover{background:#1da851!important;}
.oy-btn-share--facebook{background:#1877f2!important;color:#fff!important;border-color:#1877f2!important;}
.oy-btn-share--facebook:hover{background:#166fe5!important;}
.oy-modal-copy-confirm{font-size:12px;color:#188038;margin:10px 0 0;}
.oy-modal-no-link{font-size:13px;color:#888;font-style:italic;}
#oy-gmb-reviews-wrap .spinner{vertical-align:middle;}
</style>
        <?php
    }

    private function render_js() {
        ?>
<script id="oy-gmb-reviews-js">
(function($){
'use strict';
var $wrap,postId=0,businessId=0,locationName='',nonce='',isReady=false;
var allReviews=[],nextPageToken='',currentTab='all',currentSort='updateTimestamp desc',isLoading=false;
var STAR_MAP={ONE:1,TWO:2,THREE:3,FOUR:4,FIVE:5};
function starNum(s){return STAR_MAP[s]||0;}
function renderStarsHtml(n){var h='';for(var i=1;i<=5;i++){h+=i<=n?'<span class="oy-star oy-star--full">\u2605</span>':'<span class="oy-star oy-star--empty">\u2606</span>';}return h;}
function formatDate(iso){if(!iso)return '';var d=new Date(iso);if(isNaN(d.getTime()))return iso;var diff=Math.floor((Date.now()-d)/1000);if(diff<60)return 'Hace un momento';if(diff<3600)return 'Hace '+Math.floor(diff/60)+' min';if(diff<86400)return 'Hace '+Math.floor(diff/3600)+' h';var days=Math.floor(diff/86400);if(days<7)return 'Hace '+days+' d\u00edas';if(days<30)return 'Hace '+Math.floor(days/7)+' sem';if(days<365)return 'Hace '+Math.floor(days/30)+' meses';return 'Hace '+Math.floor(days/365)+' a\u00f1os';}
function isNew(iso){if(!iso)return false;return (Date.now()-new Date(iso).getTime())<(7*86400*1000);}
function getInitial(name){return name?name.trim().charAt(0).toUpperCase():'?';}
var ACOLORS=['#1a73e8','#ea4335','#34a853','#fa7b17','#8430ce','#007b83','#e52592','#e8710a'];
function avatarColor(i){var c=(i.charCodeAt(0)||65)-65;return ACOLORS[c%ACOLORS.length];}
function extractReviewId(name){if(!name)return '';var p=name.split('/');return p[p.length-1]||'';}
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function escAttr(s){return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function getFiltered(){return allReviews.filter(function(r){if(currentTab==='replied')return !!(r.reviewReply&&r.reviewReply.comment);if(currentTab==='unreplied')return !(r.reviewReply&&r.reviewReply.comment);return true;});}
function sortList(reviews){var list=reviews.slice();if(currentSort==='updateTimestamp desc'){list.sort(function(a,b){return new Date(b.updateTime||0)-new Date(a.updateTime||0);});}else if(currentSort==='updateTimestamp asc'){list.sort(function(a,b){return new Date(a.updateTime||0)-new Date(b.updateTime||0);});}else if(currentSort==='rating desc'){list.sort(function(a,b){return starNum(b.starRating)-starNum(a.starRating);});}else if(currentSort==='rating asc'){list.sort(function(a,b){return starNum(a.starRating)-starNum(b.starRating);});}return list;}
function updateBadges(){var replied=allReviews.filter(function(r){return !!(r.reviewReply&&r.reviewReply.comment);}).length;var unreplied=allReviews.length-replied;$('#oy-tab-badge-all').text(allReviews.length);$('#oy-tab-badge-replied').text(replied);$('#oy-tab-badge-unreplied').text(unreplied);}
function renderCard(review){
var reviewId=extractReviewId(review.name||'');
var author=(review.reviewer&&review.reviewer.displayName)?review.reviewer.displayName:'An\u00f3nimo';
var photoUrl=(review.reviewer&&review.reviewer.profilePhotoUrl)?review.reviewer.profilePhotoUrl:'';
var sNum=starNum(review.starRating||'FIVE');
var date=formatDate(review.updateTime||review.createTime||'');
var recent=isNew(review.createTime||'');
var comment=review.comment||'';
var hasReply=!!(review.reviewReply&&review.reviewReply.comment);
var initial=getInitial(author);
var color=avatarColor(initial);
var avatarHtml=photoUrl?'<img src="'+photoUrl+'" alt="'+escHtml(author)+'" loading="lazy"/>':initial;
var commentHtml='';
if(comment){
var isTrunc=comment.length>200;
var display=isTrunc?comment.substring(0,200)+'\u2026':comment;
commentHtml='<p class="oy-review-card__comment'+(isTrunc?' oy-review-card__comment--truncated':'')+'">'+ escHtml(display)+'</p>';
if(isTrunc){commentHtml+='<button type="button" class="oy-review-read-more" data-full="'+escAttr(comment)+'" data-short="'+escAttr(display)+'">Ver la opini\u00f3n completa</button>';}
}else{commentHtml='<p class="oy-review-card__comment" style="color:#aaa;font-style:italic">Sin comentario escrito</p>';}
var replyHtml='';
if(hasReply){
var rDate=formatDate(review.reviewReply.updateTime||'');
var rComment=review.reviewReply.comment||'';
replyHtml='<div class="oy-review-reply" data-review-id="'+escAttr(reviewId)+'">'+
'<div class="oy-review-reply__head">'+
'<div class="oy-review-reply__owner-avatar" style="background:'+color+';">'+initial+'</div>'+
'<span class="oy-review-reply__owner-name">Propietario</span>'+
'<span class="oy-review-reply__badge"><span class="dashicons dashicons-yes-alt"></span>Propietario</span>'+
'<span class="oy-review-reply__date">'+escHtml(rDate)+'</span>'+
'</div>'+
'<p class="oy-review-reply__comment">'+escHtml(rComment)+'</p>'+
'<div class="oy-review-reply__actions">'+
'<button type="button" class="oy-btn-edit-reply" data-review-id="'+escAttr(reviewId)+'" data-review-name="'+escAttr(review.name||'')+'" data-current-reply="'+escAttr(rComment)+'"><span class="dashicons dashicons-edit"></span>Editar</button>'+
'<button type="button" class="oy-btn-delete-reply" data-review-id="'+escAttr(reviewId)+'" data-review-name="'+escAttr(review.name||'')+'"><span class="dashicons dashicons-trash"></span>Borrar</button>'+
'</div>'+
'</div>';
}else{
replyHtml='<button type="button" class="button oy-btn-add-reply" data-review-id="'+escAttr(reviewId)+'" data-review-name="'+escAttr(review.name||'')+'"><span class="dashicons dashicons-format-chat"></span>Responder</button>';
}
var newBadge=recent?'<span class="oy-review-card__badge-new">NUEVO</span>':'';
var cls='oy-review-card'+(recent?' oy-review-card--new':'');
return '<div class="'+cls+'" data-review-id="'+escAttr(reviewId)+'" role="listitem">'+
'<div class="oy-review-card__head">'+
'<div class="oy-review-card__avatar" style="background:'+color+';">'+avatarHtml+'</div>'+
'<div class="oy-review-card__meta">'+
'<p class="oy-review-card__author">'+escHtml(author)+'</p>'+
'<div class="oy-review-card__sub">'+
'<span class="oy-review-card__stars">'+renderStarsHtml(sNum)+'</span>'+
'<span class="oy-review-card__date">'+escHtml(date)+'</span>'+
newBadge+
'</div>'+
'</div>'+
'</div>'+
commentHtml+replyHtml+
'<div class="oy-review-card__error"></div>'+
'</div>';
}
function renderList(){
var $list=	$('#oy-reviews-list');
var $empty=$('#oy-reviews-empty');
var $ph=$('#oy-reviews-placeholder');
var filtered=sortList(getFiltered());
$list.find('.oy-review-card').remove();
$ph.hide();
if(filtered.length===0){$empty.show();}else{$empty.hide();var html='';for(var i=0;i<filtered.length;i++){html+=renderCard(filtered[i]);}$list.append(html);}
updateBadges();
nextPageToken?$('#oy-reviews-pagination').show():$('#oy-reviews-pagination').hide();
}
function updateHeaderRating(avg,total){
if(!avg)return;
var r=parseFloat(avg).toFixed(1);
$('#oy-reviews-global-rating').html('<span class="oy-reviews-header__score">'+r+'</span><span class="oy-reviews-header__stars">'+renderStarsHtml(Math.round(parseFloat(avg)))+'</span><span class="oy-reviews-header__count">('+total+' opiniones)</span>');
}
function updateLastSyncLine(){
var now=new Date();
var str='ltima sincronizaci\u00f3n: '+now.toLocaleDateString('es-CO')+' '+now.toLocaleTimeString('es-CO');
$('#oy-reviews-last-sync-line').text('\u00da'+str).show();
}
function loadReviews(append,force){
if(isLoading)return;
if(!businessId||!locationName){$('#oy-reviews-error').text('Configura el negocio padre y la ubicaci\u00f3n GMB antes de sincronizar.').show();return;}
isLoading=true;force=force===true;
var $btn=$('#oy-btn-reviews-sync');
var $loading=$('#oy-reviews-loading');
var $error=$('#oy-reviews-error');
$btn.addClass('oy-syncing').prop('disabled',true);
$error.hide();$loading.show();$('#oy-reviews-placeholder').hide();
$('#oy-reviews-content').show();
if(!append){allReviews=[];nextPageToken='';$('#oy-reviews-list .oy-review-card').remove();}
$.ajax({
url:ajaxurl,method:'POST',
data:{action:'oy_gmb_reviews_fetch',post_id:postId,business_id:businessId,location:locationName,page_token:append?nextPageToken:'',order_by:currentSort,force_refresh:force?'1':'0',security:nonce},
success:function(response){
if(response.success&&response.data){
var data=response.data;
if(data.reviews&&data.reviews.length){allReviews=append?allReviews.concat(data.reviews):data.reviews;}
nextPageToken=data.nextPageToken||'';
if(data.averageRating){updateHeaderRating(data.averageRating,data.totalReviewCount||allReviews.length);}
updateLastSyncLine();
}else{
var msg=(response.data&&response.data.message)?response.data.message:'Error desconocido al obtener rese\u00f1as.';
var debugHtml='';
if(response.data&&response.data.debug){
var d=response.data.debug;
debugHtml='<br><small style="opacity:.75;word-break:break-all;">'
+'<strong>DEBUG (WP_DEBUG=true):</strong><br>'
+(d.wp_error_code?'Code: '+d.wp_error_code+'<br>':'')
+(d.http_code?'HTTP: '+d.http_code+'<br>':'')
+(d.api_base&&d.endpoint?'URL: '+d.api_base+d.endpoint+'<br>':'')
+(d.raw_body?'Google response: '+d.raw_body:'')
+'</small>';
}
$error.html('Error: '+escHtml(msg)+debugHtml).show();
}
},
error:function(xhr){$error.text('Error de conexi\u00f3n HTTP '+xhr.status+'. Intenta de nuevo.').show();},
complete:function(){isLoading=false;$loading.hide();$btn.removeClass('oy-syncing').prop('disabled',false);renderList();}
});
}
function submitReply($card,reviewId,reviewName,commentText){
var $btn=$card.find('.oy-btn-reply-submit');
var $error=$card.find('.oy-review-card__error');
$btn.prop('disabled',true).text('Enviando\u2026');$error.hide();
$.ajax({
url:ajaxurl,method:'POST',
data:{action:'oy_gmb_reviews_reply',post_id:postId,business_id:businessId,location:locationName,review_id:reviewId,review_name:reviewName,comment:commentText,security:nonce},
success:function(response){
if(response.success&&response.data){updateReviewInCache(reviewId,response.data.reviewReply||response.data);renderList();}
else{var msg=(response.data&&response.data.message)?response.data.message:'Error desconocido.';$error.text('Error: '+msg).show();$btn.prop('disabled',false).text('Responder');}
},
error:function(xhr){$error.text('Error de conexi\u00f3n ('+xhr.status+').').show();$btn.prop('disabled',false).text('Responder');}
});
}
function deleteReply($card,reviewId,reviewName){
var $error=$card.find('.oy-review-card__error');$error.hide();
if(!confirm('\u00bfSeguro que deseas borrar tu respuesta? Esta acci\u00f3n no se puede deshacer.'))return;
$.ajax({
url:ajaxurl,method:'POST',
data:{action:'oy_gmb_reviews_delete_reply',post_id:postId,business_id:businessId,location:locationName,review_id:reviewId,review_name:reviewName,security:nonce},
success:function(response){if(response.success){updateReviewInCache(reviewId,null);renderList();}else{var msg=(response.data&&response.data.message)?response.data.message:'Error.';$error.text('Error: '+msg).show();}},
error:function(xhr){$error.text('Error de conexi\u00f3n ('+xhr.status+').').show();}
});
}
function updateReviewInCache(reviewId,reviewReply){
for(var i=0;i<allReviews.length;i++){
if(extractReviewId(allReviews[i].name||'')===reviewId){
if(reviewReply){allReviews[i].reviewReply=reviewReply;}else{delete allReviews[i].reviewReply;}break;
}
}
}
function showReplyForm($card,reviewId,reviewName,existing){
$card.find('.oy-reply-form').remove();
var formHtml='<div class="oy-reply-form">'+
'<div class="oy-reply-form__header"><span class="dashicons dashicons-format-chat"></span>Responder p\u00fablicamente</div>'+
'<textarea class="oy-reply-textarea" maxlength="4000" placeholder="Responder p\u00fablicamente\u2026">'+escHtml(existing||'')+'</textarea>'+
'<div class="oy-reply-form__footer">'+
'<span class="oy-reply-char-count"><span class="oy-char-current">0</span>/4,000</span>'+
'<div class="oy-reply-form__buttons">'+
'<button type="button" class="oy-btn-reply-cancel">Cancelar</button>'+
'<button type="button" class="button button-primary oy-btn-reply-submit" data-review-id="'+escAttr(reviewId)+'" data-review-name="'+escAttr(reviewName)+'">Responder</button>'+
'</div></div>'+
'<p style="font-size:11px;color:#888;margin:6px 0 0;">Este cliente recibir\u00e1 una notificaci\u00f3n sobre tu respuesta, que ser\u00e1 visible p\u00fablicamente en tu Perfil de Negocio</p>'+
'</div>';
$card.append(formHtml);
var $ta=$card.find('.oy-reply-textarea');
$card.find('.oy-char-current').text($ta.val().length);
$ta.focus();
}
$(document).ready(function(){
$wrap=$('#oy-gmb-reviews-wrap');if(!$wrap.length)return;
postId=parseInt($wrap.data('post-id'),10)||0;
businessId=parseInt($wrap.data('business-id'),10)||0;
locationName=String($wrap.data('location')||'');
nonce=String($wrap.data('nonce')||'');
isReady=$wrap.data('ready')==='1'||$wrap.data('ready')===1;
$(document).on('click','.oy-reviews-tab',function(){
$('.oy-reviews-tab').removeClass('oy-reviews-tab--active').attr('aria-selected','false');
$(this).addClass('oy-reviews-tab--active').attr('aria-selected','true');
currentTab=$(this).data('tab');renderList();
});
$(document).on('change','.oy-reviews-sort',function(){currentSort=$(this).val();renderList();});
$(document).on('click','#oy-btn-reviews-sync',function(){loadReviews(false,true);});
$(document).on('click','#oy-btn-load-more',function(){if(nextPageToken)loadReviews(true,false);});
$(document).on('click','.oy-review-read-more',function(){
var $btn=$(this);var $p=$btn.prev('.oy-review-card__comment');
var full=$btn.data('full');var short=$btn.data('short');
if($btn.text()==='Ver la opini\u00f3n completa'){$p.text(full).removeClass('oy-review-card__comment--truncated');$btn.text('Ver menos');}
else{$p.text(short).addClass('oy-review-card__comment--truncated');$btn.text('Ver la opini\u00f3n completa');}
});
$(document).on('click','.oy-btn-add-reply',function(){
var $card=$(this).closest('.oy-review-card');
$(this).hide();showReplyForm($card,String($(this).data('review-id')),String($(this).data('review-name')),'');
});
$(document).on('click','.oy-btn-edit-reply',function(){
var $card=$(this).closest('.oy-review-card');
$card.find('.oy-review-reply').hide();
showReplyForm($card,String($(this).data('review-id')),String($(this).data('review-name')),String($(this).data('current-reply')));
});
$(document).on('click','.oy-btn-delete-reply',function(){
var $card=$(this).closest('.oy-review-card');
deleteReply($card,String($(this).data('review-id')),String($(this).data('review-name')));
});
$(document).on('input','.oy-reply-textarea',function(){
var len=$(this).val().length;
var $c=$(this).closest('.oy-reply-form').find('.oy-char-current');
$c.text(len);$c.closest('.oy-reply-char-count').toggleClass('oy-reply-char-count--over',len>=4000);
});
$(document).on('click','.oy-btn-reply-submit',function(){
var $card=$(this).closest('.oy-review-card');
var text=$.trim($card.find('.oy-reply-textarea').val());
if(!text){$card.find('.oy-reply-textarea').focus();return;}
if(text.length>4000){alert('La respuesta no puede superar los 4,000 caracteres.');return;}
submitReply($card,String($(this).data('review-id')),String($(this).data('review-name')),text);
});
$(document).on('click','.oy-btn-reply-cancel',function(){
var $card=$(this).closest('.oy-review-card');
$card.find('.oy-reply-form').remove();$card.find('.oy-review-reply').show();$card.find('.oy-btn-add-reply').show();
});
$(document).on('click','#oy-btn-get-more-reviews',function(){$('#oy-modal-get-reviews').show();});
$(document).on('click','#oy-modal-reviews-close, .oy-modal-overlay',function(e){if(e.target===this)$('#oy-modal-get-reviews').hide();});
$(document).on('click','.oy-modal-box',function(e){e.stopPropagation();});
$(document).on('click','.oy-btn-copy-link',function(){
var val=$('#'+$(this).data('copy-target')).val();
if(navigator.clipboard){navigator.clipboard.writeText(val).then(function(){$('#oy-modal-copy-confirm').show().delay(3000).fadeOut();});}
else{var $tmp=$('<textarea>').val(val).appendTo('body').select();document.execCommand('copy');$tmp.remove();$('#oy-modal-copy-confirm').show().delay(3000).fadeOut();}
});
$(document).on('keydown',function(e){if(e.key==='Escape')$('#oy-modal-get-reviews').hide();});
});
})(jQuery);
</script>
        <?php
    }

public function ajax_fetch_reviews() {
    if ( ! check_ajax_referer( $this->nonce_action, 'security', false ) ) {
        wp_send_json_error( array( 'message' => __( 'Nonce invalido.', 'lealez' ) ), 403 );
    }
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ), 403 );
    }

    $post_id       = absint( $_POST['post_id']      ?? 0 );
    $business_id   = absint( $_POST['business_id']  ?? 0 );
    $location      = sanitize_text_field( $_POST['location']     ?? '' );
    $page_token    = sanitize_text_field( $_POST['page_token']   ?? '' );
    $order_by      = sanitize_text_field( $_POST['order_by']     ?? 'updateTimestamp desc' );
    $force_refresh = ( '1' === ( $_POST['force_refresh'] ?? '0' ) );

    if ( ! $post_id || ! $business_id || empty( $location ) ) {
        $msg = sprintf(
            'Parametros incompletos. post_id=%d, business_id=%d, location="%s"',
            $post_id,
            $business_id,
            $location
        );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Lealez Reviews AJAX] ' . $msg );
        }
        wp_send_json_error( array( 'message' => $msg ) );
    }

    if ( ! class_exists( 'Lealez_GMB_API' ) ) {
        wp_send_json_error( array( 'message' => 'Lealez_GMB_API no disponible.' ) );
    }

    // ✅ WP_DEBUG: registrar parametros recibidos
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( sprintf(
            '[Lealez Reviews AJAX] fetch | post_id=%d | business_id=%d | location="%s" | order_by="%s" | force=%s | page_token="%s"',
            $post_id,
            $business_id,
            $location,
            $order_by,
            $force_refresh ? 'true' : 'false',
            $page_token
        ) );
    }

    // force_refresh=true omite cache transient (accion del boton Sincronizar desde Google)
    $use_cache = ! $force_refresh && empty( $page_token );

    $result = Lealez_GMB_API::get_location_reviews(
        $business_id,
        $location,
        $page_token,
        50,
        $order_by,
        $use_cache
    );

    if ( is_wp_error( $result ) ) {
        $error_response = array(
            'message' => $result->get_error_message(),
            'code'    => $result->get_error_code(),
        );

        // ✅ WP_DEBUG: incluir detalles tecnicos en la respuesta para mostrar en UI
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $err_data = $result->get_error_data();
            $debug_info = array(
                'wp_error_code' => $result->get_error_code(),
                'location_sent' => $location,
                'order_by_sent' => $order_by,
            );
            if ( is_array( $err_data ) ) {
                $debug_info['http_code'] = $err_data['code']     ?? null;
                $debug_info['endpoint']  = $err_data['endpoint'] ?? null;
                $debug_info['api_base']  = $err_data['api_base'] ?? null;
                $debug_info['raw_body']  = isset( $err_data['raw_body'] )
                    ? substr( (string) $err_data['raw_body'], 0, 500 )
                    : null;
            }
            $error_response['debug'] = $debug_info;
            error_log( '[Lealez Reviews AJAX] Error response: ' . wp_json_encode( $debug_info ) );
        }

        wp_send_json_error( $error_response );
    }

    if ( ! empty( $result['averageRating'] ) ) {
        update_post_meta( $post_id, '_gmb_reviews_stats_cache', array(
            'averageRating'    => $result['averageRating'],
            'totalReviewCount' => $result['totalReviewCount'] ?? 0,
        ) );
    }
    update_post_meta( $post_id, '_gmb_reviews_last_sync', time() );

    wp_send_json_success( $result );
}

    public function ajax_reply_to_review() {
        if ( ! check_ajax_referer( $this->nonce_action, 'security', false ) ) {
            wp_send_json_error( array( 'message' => 'Nonce invalido.' ), 403 );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        $post_id     = absint( $_POST['post_id']     ?? 0 );
        $business_id = absint( $_POST['business_id'] ?? 0 );
        $location    = sanitize_text_field( $_POST['location']    ?? '' );
        $review_id   = sanitize_text_field( $_POST['review_id']   ?? '' );
        $review_name = sanitize_text_field( $_POST['review_name'] ?? '' );
        $comment     = sanitize_textarea_field( $_POST['comment'] ?? '' );

        if ( ! $post_id || ! $business_id || empty( $location ) || empty( $review_id ) || empty( $comment ) ) {
            wp_send_json_error( array( 'message' => 'Parametros incompletos.' ) );
        }
        if ( mb_strlen( $comment ) > 4000 ) {
            wp_send_json_error( array( 'message' => 'La respuesta supera los 4,000 caracteres permitidos.' ) );
        }
        if ( ! class_exists( 'Lealez_GMB_API' ) ) {
            wp_send_json_error( array( 'message' => 'Lealez_GMB_API no disponible.' ) );
        }

        $result = Lealez_GMB_API::reply_to_review( $business_id, $location, $review_id, $comment, $review_name );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) );
        }

        Lealez_GMB_API::clear_reviews_cache( $business_id, $location );
        wp_send_json_success( $result );
    }

    public function ajax_delete_review_reply() {
        if ( ! check_ajax_referer( $this->nonce_action, 'security', false ) ) {
            wp_send_json_error( array( 'message' => 'Nonce invalido.' ), 403 );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        $post_id     = absint( $_POST['post_id']     ?? 0 );
        $business_id = absint( $_POST['business_id'] ?? 0 );
        $location    = sanitize_text_field( $_POST['location']    ?? '' );
        $review_id   = sanitize_text_field( $_POST['review_id']   ?? '' );
        $review_name = sanitize_text_field( $_POST['review_name'] ?? '' );

        if ( ! $post_id || ! $business_id || empty( $location ) || empty( $review_id ) ) {
            wp_send_json_error( array( 'message' => 'Parametros incompletos.' ) );
        }
        if ( ! class_exists( 'Lealez_GMB_API' ) ) {
            wp_send_json_error( array( 'message' => 'Lealez_GMB_API no disponible.' ) );
        }

        $result = Lealez_GMB_API::delete_review_reply( $business_id, $location, $review_id, $review_name );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) );
        }

        Lealez_GMB_API::clear_reviews_cache( $business_id, $location );
        wp_send_json_success( array( 'deleted' => true ) );
    }

} // class OY_Location_GMB_Reviews_Metabox
