<?php
/**
 * OY Location - GMB Owner Media Metabox
 *
 * Metabox que lista las fotos (mediaItems) subidas por el propietario
 * usando el endpoint:
 * GET https://mybusiness.googleapis.com/v4/accounts/{accountId}/locations/{locationId}/media
 *
 * @package Lealez
 * @subpackage CPTs/Metaboxes
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'OY_Location_GMB_Media_Metabox' ) ) {

    class OY_Location_GMB_Media_Metabox {

        /**
         * Constructor
         */
        public function __construct() {
            add_action( 'add_meta_boxes_oy_location', array( $this, 'register_metabox' ), 20, 1 );
        }

        /**
         * Register metabox
         *
         * @param WP_Post $post
         * @return void
         */
        public function register_metabox( $post ) {
            add_meta_box(
                'oy_location_gmb_owner_media',
                __( 'Google Business Profile - Fotos del propietario', 'lealez' ),
                array( $this, 'render_metabox' ),
                'oy_location',
                'normal',
                'default'
            );
        }

        /**
         * Extrae accountId y locationId para debug desde un resource name:
         * - accounts/123/locations/456
         *
         * @param string $location_resource_name
         * @return array{account_id:string, location_id:string}
         */
        private static function debug_extract_from_location_name( $location_resource_name ) {
            $v = trim( (string) $location_resource_name );
            $v = trim( $v, '/' );

            $out = array(
                'account_id'  => '',
                'location_id' => '',
            );

            if ( '' === $v ) {
                return $out;
            }

            // accountId
            if ( strpos( $v, 'accounts/' ) !== false ) {
                // accounts/{id}/...
                $pos = strpos( $v, 'accounts/' );
                $tail = substr( $v, $pos + strlen( 'accounts/' ) );
                $tail = trim( (string) $tail, '/' );
                $chunks = explode( '/', $tail );
                $out['account_id'] = isset( $chunks[0] ) ? trim( (string) $chunks[0], '/' ) : '';
            }

            // locationId
            if ( strpos( $v, '/locations/' ) !== false ) {
                $parts = explode( '/locations/', $v );
                $tail  = end( $parts );
                $tail  = trim( (string) $tail, '/' );
                $chunks = explode( '/', $tail );
                $out['location_id'] = isset( $chunks[0] ) ? trim( (string) $chunks[0], '/' ) : '';
            } elseif ( strpos( $v, 'locations/' ) === 0 ) {
                $tail = substr( $v, strlen( 'locations/' ) );
                $tail = trim( (string) $tail, '/' );
                $chunks = explode( '/', $tail );
                $out['location_id'] = isset( $chunks[0] ) ? trim( (string) $chunks[0], '/' ) : '';
            }

            return $out;
        }

        /**
         * Extrae un locationId “probable” desde:
         * - "456"
         * - "locations/456"
         * - "accounts/123/locations/456"
         * - "accounts/123/locations/456/media/..."
         *
         * @param string $location_any
         * @return string
         */
        private static function debug_extract_location_id_from_any( $location_any ) {
            $v = trim( (string) $location_any );
            if ( '' === $v ) {
                return '';
            }

            if ( preg_match( '/^\d+$/', $v ) ) {
                return $v;
            }

            $v = trim( $v, '/' );

            if ( strpos( $v, '/locations/' ) !== false ) {
                $parts = explode( '/locations/', $v );
                $tail  = end( $parts );
                $tail  = trim( (string) $tail, '/' );
                $chunks = explode( '/', $tail );
                return isset( $chunks[0] ) ? trim( (string) $chunks[0], '/' ) : '';
            }

            if ( strpos( $v, 'locations/' ) === 0 ) {
                $tail = substr( $v, strlen( 'locations/' ) );
                $tail = trim( (string) $tail, '/' );
                $chunks = explode( '/', $tail );
                return isset( $chunks[0] ) ? trim( (string) $chunks[0], '/' ) : '';
            }

            $chunks = explode( '/', $v );
            $last = end( $chunks );
            return trim( (string) $last, '/' );
        }

        /**
         * Extrae accountId “probable” desde:
         * - "accounts/123"
         * - "123"
         * - "accounts/123/locations/456"
         *
         * @param string $account_any
         * @return string
         */
        private static function debug_extract_account_id_from_any( $account_any ) {
            $v = trim( (string) $account_any );
            if ( '' === $v ) {
                return '';
            }

            if ( preg_match( '/^\d+$/', $v ) ) {
                return $v;
            }

            $v = trim( $v, '/' );

            if ( strpos( $v, 'accounts/' ) === 0 ) {
                $tail = substr( $v, strlen( 'accounts/' ) );
                $tail = trim( (string) $tail, '/' );
                $chunks = explode( '/', $tail );
                return isset( $chunks[0] ) ? trim( (string) $chunks[0], '/' ) : '';
            }

            if ( strpos( $v, '/accounts/' ) !== false ) {
                $parts = explode( '/accounts/', $v );
                $tail  = end( $parts );
                $tail  = trim( (string) $tail, '/' );
                $chunks = explode( '/', $tail );
                return isset( $chunks[0] ) ? trim( (string) $chunks[0], '/' ) : '';
            }

            $chunks = explode( '/', $v );
            $last = end( $chunks );
            return trim( (string) $last, '/' );
        }

        /**
         * Render metabox
         *
         * @param WP_Post $post
         * @return void
         */
        public function render_metabox( $post ) {

            // Metas requeridas para poder llamar la API con el token del negocio padre
            $parent_business_id = (int) get_post_meta( $post->ID, 'parent_business_id', true );

            // Se guardan en el metabox "Integración Google My Business"
            $gmb_account_id     = (string) get_post_meta( $post->ID, 'gmb_account_id', true );      // "accounts/123..." (a veces)
            $gmb_location_id    = (string) get_post_meta( $post->ID, 'gmb_location_id', true );     // "456" o "locations/456" o "accounts/123/locations/456"
            $gmb_location_name  = (string) get_post_meta( $post->ID, 'gmb_location_name', true );   // "accounts/123/locations/456" (muy útil)

            // ============ DEBUG: derivaciones (solo para log / diagnóstico) ============
            $derived_from_name = self::debug_extract_from_location_name( $gmb_location_name );

            $derived_account_from_account_meta = self::debug_extract_account_id_from_any( $gmb_account_id );
            $derived_location_from_location_meta = self::debug_extract_location_id_from_any( $gmb_location_id );

            $derived_account_id = $derived_account_from_account_meta ? $derived_account_from_account_meta : ( $derived_from_name['account_id'] ?? '' );
            $derived_location_id = $derived_location_from_location_meta ? $derived_location_from_location_meta : ( $derived_from_name['location_id'] ?? '' );

            $debug_payload = array(
                'context' => 'oy_location_gmb_owner_media_metabox',
                'post_id' => (int) $post->ID,
                'parent_business_id' => (int) $parent_business_id,

                // Raw metas
                'meta_gmb_account_id'    => $gmb_account_id,
                'meta_gmb_location_id'   => $gmb_location_id,
                'meta_gmb_location_name' => $gmb_location_name,

                // Derived guesses (para saber qué debería estar pasando)
                'derived_account_id_guess'  => $derived_account_id,
                'derived_location_id_guess' => $derived_location_id,
                'derived_from_location_name' => $derived_from_name,
            );

            // Log a nivel plugin (si existe logger)
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $parent_business_id ? $parent_business_id : 0,
                    'info',
                    'GMB Media Metabox: render init (inputs + derived guesses).',
                    $debug_payload
                );
            }
            // ========================================================================

            echo '<div class="oy-gmb-media-metabox">';

            // Debug visible (solo admins): muestra inputs y derivados
            if ( current_user_can( 'manage_options' ) ) {
                echo '<details style="margin:0 0 10px 0;">';
                echo '<summary><strong>' . esc_html__( 'Debug (inputs del metabox)', 'lealez' ) . '</strong></summary>';
                echo '<pre style="white-space:pre-wrap; background:#f6f7f7; padding:10px; border:1px solid #ddd; margin-top:10px;">';
                echo esc_html( wp_json_encode( $debug_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
                echo '</pre>';
                echo '</details>';
            }

            // Pre-checks
            if ( ! $parent_business_id ) {
                echo '<p class="description"><strong style="color:#b32d2e;">⚠ </strong>' .
                    esc_html__( 'Esta ubicación no tiene Empresa/Negocio asociado (parent_business_id). Selecciona la empresa en el sidebar y guarda.', 'lealez' ) .
                '</p>';
                echo '</div>';
                return;
            }

            /**
             * IMPORTANTE:
             * Para media podemos resolver IDs desde gmb_location_name (accounts/123/locations/456).
             * Por eso, solo fallamos si NO tenemos ni location_id ni location_name.
             */
            if ( empty( $gmb_location_id ) && empty( $gmb_location_name ) ) {
                echo '<p class="description"><strong style="color:#b32d2e;">⚠ </strong>' .
                    esc_html__( 'No hay Location ID ni Resource Name de Google. Primero importa/selecciona una ubicación desde el metabox "Integración Google My Business" y guarda el post.', 'lealez' ) .
                '</p>';
                echo '</div>';
                return;
            }

            // Llamada a la API
            if ( ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'get_location_media_items' ) ) {
                echo '<p class="description"><strong style="color:#b32d2e;">✖ </strong>' .
                    esc_html__( 'No está disponible el método Lealez_GMB_API::get_location_media_items(). Revisa class-lealez-gmb-api.php.', 'lealez' ) .
                '</p>';
                echo '</div>';
                return;
            }

            // Log explícito justo antes de llamar API (para correlacionar con el WP_Error)
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $parent_business_id,
                    'info',
                    'GMB Media Metabox: calling get_location_media_items() with raw metas.',
                    array(
                        'post_id' => (int) $post->ID,
                        'parent_business_id' => (int) $parent_business_id,
                        'call_args' => array(
                            'business_id' => (int) $parent_business_id,
                            'gmb_account_id' => $gmb_account_id,
                            'gmb_location_id' => $gmb_location_id,
                            'force_refresh' => false,
                            'gmb_location_name' => $gmb_location_name,
                        ),
                        'derived_account_id_guess' => $derived_account_id,
                        'derived_location_id_guess' => $derived_location_id,
                    )
                );
            }

            $media = Lealez_GMB_API::get_location_media_items(
                $parent_business_id,
                $gmb_account_id,
                $gmb_location_id,
                false,              // force_refresh
                $gmb_location_name  // ayuda a derivar accountId/locationId correctos
            );

            if ( is_wp_error( $media ) ) {

                // Log detallado del error, con inputs + derived guesses
                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log(
                        $parent_business_id,
                        'error',
                        'GMB Media Metabox: WP_Error while fetching media items.',
                        array(
                            'post_id' => (int) $post->ID,
                            'error_code' => (string) $media->get_error_code(),
                            'error_message' => (string) $media->get_error_message(),
                            'error_data' => $media->get_error_data(),
                            'inputs_debug' => $debug_payload,
                        )
                    );
                }

                echo '<p class="description"><strong style="color:#b32d2e;">✖ </strong>' .
                    esc_html__( 'Error consultando fotos en Google:', 'lealez' ) . ' ' .
                    esc_html( $media->get_error_message() ) .
                '</p>';

                // Hint técnico
                $data = $media->get_error_data();

                // Mostrar un bloque adicional con lo más útil del WP_Error (en pantalla)
                if ( current_user_can( 'manage_options' ) ) {
                    echo '<details style="margin-top:10px;"><summary>' . esc_html__( 'Ver debug del error (WP_Error)', 'lealez' ) . '</summary>';

                    $to_print = array(
                        'error_code'    => (string) $media->get_error_code(),
                        'error_message' => (string) $media->get_error_message(),
                        'error_data'    => is_array( $data ) ? $data : $data,
                    );

                    echo '<pre style="white-space:pre-wrap; background:#f6f7f7; padding:10px; border:1px solid #ddd; margin-top:10px;">' .
                        esc_html( wp_json_encode( $to_print, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) .
                    '</pre>';

                    echo '</details>';
                } else {
                    // Mantener el bloque original por si el usuario no es admin pero hay raw_body
                    if ( is_array( $data ) && ! empty( $data['raw_body'] ) ) {
                        echo '<details style="margin-top:10px;"><summary>' . esc_html__( 'Ver detalle técnico', 'lealez' ) . '</summary>';
                        echo '<pre style="white-space:pre-wrap; background:#f6f7f7; padding:10px; border:1px solid #ddd;">' . esc_html( substr( (string) $data['raw_body'], 0, 3000 ) ) . '</pre>';
                        echo '</details>';
                    }
                }

                echo '</div>';
                return;
            }

            $items  = ( is_array( $media ) && isset( $media['mediaItems'] ) && is_array( $media['mediaItems'] ) ) ? $media['mediaItems'] : array();
            $total  = (int) ( $media['totalMediaItemCount'] ?? count( $items ) );

            echo '<p class="description" style="margin-top:0;">' .
                esc_html__( 'Lista de fotos/videos asociados a esta ubicación (endpoint /media).', 'lealez' ) .
                ' <strong>' . esc_html( (string) $total ) . '</strong>' . esc_html__( ' elementos.', 'lealez' ) .
            '</p>';

            if ( empty( $items ) ) {
                echo '<p class="description"><strong>ℹ </strong>' .
                    esc_html__( 'No se encontraron fotos del propietario para esta ubicación.', 'lealez' ) .
                '</p>';
                echo '</div>';
                return;
            }

            // Styles (locales y seguras)
            ?>
            <style>
                .oy-gmb-media-grid{
                    display:grid;
                    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                    gap:12px;
                    margin-top:12px;
                }
                .oy-gmb-media-card{
                    border:1px solid #e2e4e7;
                    background:#fff;
                    border-radius:6px;
                    overflow:hidden;
                    box-shadow:0 1px 0 rgba(0,0,0,.04);
                }
                .oy-gmb-media-thumb{
                    width:100%;
                    height:120px;
                    object-fit:cover;
                    display:block;
                    background:#f6f7f7;
                    cursor:pointer;
                }
                .oy-gmb-media-meta{
                    padding:10px;
                    font-size:12px;
                    color:#444;
                }
                .oy-gmb-media-meta code{
                    font-size:11px;
                }
                .oy-gmb-media-actions{
                    display:flex;
                    gap:8px;
                    margin-top:8px;
                    flex-wrap:wrap;
                }
                .oy-gmb-media-badge{
                    display:inline-block;
                    padding:2px 6px;
                    border-radius:999px;
                    background:#f0f0f1;
                    font-size:11px;
                    margin-bottom:6px;
                }

                /* Modal */
                .oy-gmb-media-modal{
                    position:fixed;
                    z-index:999999;
                    left:0; top:0;
                    width:100%; height:100%;
                    background: rgba(0,0,0,.65);
                    display:none;
                    align-items:center;
                    justify-content:center;
                    padding:20px;
                }
                .oy-gmb-media-modal-inner{
                    background:#fff;
                    max-width:900px;
                    width:100%;
                    border-radius:8px;
                    overflow:hidden;
                    box-shadow:0 10px 30px rgba(0,0,0,.25);
                }
                .oy-gmb-media-modal-header{
                    display:flex;
                    justify-content:space-between;
                    align-items:center;
                    padding:10px 14px;
                    border-bottom:1px solid #e2e4e7;
                    background:#f6f7f7;
                }
                .oy-gmb-media-modal-body{
                    padding:12px;
                }
                .oy-gmb-media-modal-img{
                    width:100%;
                    height:auto;
                    display:block;
                    background:#f6f7f7;
                }
                .oy-gmb-media-modal-caption{
                    margin-top:10px;
                    font-size:12px;
                    color:#444;
                }
                .oy-gmb-media-close{
                    cursor:pointer;
                    border:none;
                    background:transparent;
                    font-size:18px;
                    line-height:1;
                }
            </style>
            <?php

            echo '<div class="oy-gmb-media-grid">';

            foreach ( $items as $it ) {
                if ( ! is_array( $it ) ) {
                    continue;
                }

                $name         = (string) ( $it['name'] ?? '' );
                $format       = (string) ( $it['mediaFormat'] ?? '' );
                $google_url   = (string) ( $it['googleUrl'] ?? '' );
                $thumb_url    = (string) ( $it['thumbnailUrl'] ?? '' );
                $create_time  = (string) ( $it['createTime'] ?? '' );
                $desc         = (string) ( $it['description'] ?? '' );

                $category = '';
                if ( isset( $it['locationAssociation']['category'] ) ) {
                    $category = (string) $it['locationAssociation']['category'];
                }

                $w = '';
                $h = '';
                if ( isset( $it['dimensions'] ) && is_array( $it['dimensions'] ) ) {
                    $w = (string) ( $it['dimensions']['width'] ?? '' );
                    $h = (string) ( $it['dimensions']['height'] ?? '' );
                }

                $thumb_to_use = $thumb_url ? $thumb_url : $google_url;

                $modal_caption = trim(
                    implode(
                        ' | ',
                        array_filter(
                            array(
                                $category ? 'Category: ' . $category : '',
                                $format ? 'Format: ' . $format : '',
                                ( $w && $h ) ? ( $w . 'x' . $h ) : '',
                                $create_time ? 'Created: ' . $create_time : '',
                                $desc ? 'Desc: ' . $desc : '',
                            )
                        )
                    )
                );

                echo '<div class="oy-gmb-media-card">';

                if ( $thumb_to_use ) {
                    echo '<img class="oy-gmb-media-thumb" ' .
                        'src="' . esc_url( $thumb_to_use ) . '" ' .
                        'alt="' . esc_attr( $category ? $category : 'media' ) . '" ' .
                        'data-full="' . esc_url( $google_url ? $google_url : $thumb_to_use ) . '" ' .
                        'data-caption="' . esc_attr( $modal_caption ) . '" ' .
                    '/>';
                } else {
                    echo '<div style="height:120px; display:flex; align-items:center; justify-content:center; background:#f6f7f7; color:#666;">' .
                        esc_html__( 'Sin imagen', 'lealez' ) .
                    '</div>';
                }

                echo '<div class="oy-gmb-media-meta">';

                if ( $category ) {
                    echo '<span class="oy-gmb-media-badge">' . esc_html( $category ) . '</span>';
                }

                echo '<div><strong>' . esc_html__( 'Formato:', 'lealez' ) . '</strong> ' . esc_html( $format ? $format : '-' ) . '</div>';

                if ( $w && $h ) {
                    echo '<div><strong>' . esc_html__( 'Dim:', 'lealez' ) . '</strong> ' . esc_html( $w . 'x' . $h ) . '</div>';
                }

                if ( $create_time ) {
                    echo '<div><strong>' . esc_html__( 'Creado:', 'lealez' ) . '</strong> ' . esc_html( $create_time ) . '</div>';
                }

                if ( $name ) {
                    echo '<div style="margin-top:6px;"><code>' . esc_html( $name ) . '</code></div>';
                }

                echo '<div class="oy-gmb-media-actions">';
                if ( $google_url ) {
                    echo '<a class="button button-secondary" href="' . esc_url( $google_url ) . '" target="_blank" rel="noopener noreferrer">' .
                        esc_html__( 'Abrir en Google', 'lealez' ) .
                    '</a>';
                }
                echo '</div>';

                echo '</div>'; // meta
                echo '</div>'; // card
            }

            echo '</div>'; // grid
            ?>
            <div class="oy-gmb-media-modal" id="oy-gmb-media-modal">
                <div class="oy-gmb-media-modal-inner">
                    <div class="oy-gmb-media-modal-header">
                        <strong><?php echo esc_html__( 'Vista previa', 'lealez' ); ?></strong>
                        <button type="button" class="oy-gmb-media-close" id="oy-gmb-media-close" aria-label="<?php echo esc_attr__( 'Cerrar', 'lealez' ); ?>">✕</button>
                    </div>
                    <div class="oy-gmb-media-modal-body">
                        <img src="" alt="" class="oy-gmb-media-modal-img" id="oy-gmb-media-modal-img">
                        <div class="oy-gmb-media-modal-caption" id="oy-gmb-media-modal-caption"></div>
                    </div>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($){
                var $modal = $('#oy-gmb-media-modal');
                var $img   = $('#oy-gmb-media-modal-img');
                var $cap   = $('#oy-gmb-media-modal-caption');

                function openModal(src, caption){
                    $img.attr('src', src || '');
                    $cap.text(caption || '');
                    $modal.css('display','flex');
                }

                function closeModal(){
                    $modal.hide();
                    $img.attr('src','');
                    $cap.text('');
                }

                $(document).on('click', '.oy-gmb-media-thumb', function(){
                    var src = $(this).data('full') || $(this).attr('src');
                    var cap = $(this).data('caption') || '';
                    openModal(src, cap);
                });

                $(document).on('click', '#oy-gmb-media-close', function(e){
                    e.preventDefault();
                    closeModal();
                });

                $(document).on('click', '#oy-gmb-media-modal', function(e){
                    if(e.target === this){
                        closeModal();
                    }
                });

                $(document).on('keyup', function(e){
                    if(e.key === 'Escape'){
                        closeModal();
                    }
                });
            });
            </script>
            <?php

            echo '</div>'; // wrapper
        }

    }
}
