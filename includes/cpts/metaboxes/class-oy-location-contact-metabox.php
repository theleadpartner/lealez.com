<?php
/**
 * OY Location Contact Metabox
 *
 * Externaliza el metabox "Información de Contacto" del CPT oy_location.
 * Mantiene los mismos IDs, names y meta keys usados por class-oy-location-cpt.php
 * para no romper la importación desde GMB, Place Actions API ni el JS existente.
 *
 * @package Lealez
 * @subpackage CPTs\Metaboxes
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'OY_Location_Contact_Metabox' ) ) :

/**
 * Class OY_Location_Contact_Metabox
 *
 * Gestiona el metabox "Información de Contacto" para oy_location.
 */
class OY_Location_Contact_Metabox {

    /**
     * Post type slug.
     *
     * @var string
     */
    private $post_type = 'oy_location';

    /**
     * Meta box nonce name usado por el CPT principal.
     *
     * @var string
     */
    private $nonce_name = 'oy_location_meta_nonce';

    /**
     * Meta box nonce action usado por el CPT principal.
     *
     * @var string
     */
    private $nonce_action = 'oy_location_save_meta';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

        /**
         * Guardar antes del save principal del CPT, pero después del metabox de Menú.
         *
         * OY_Location_Menu_Metabox guarda en prioridad 15 un hidden field location_menu_url.
         * Este metabox guarda el campo editable location_menu_url_gmb en prioridad 19,
         * por lo que conserva el comportamiento anterior: Contacto gana sobre Menú,
         * y el import-on-save del CPT principal sigue ejecutándose después en prioridad 20.
         */
        add_action( 'save_post_oy_location', array( $this, 'save_meta_box' ), 19, 2 );
    }

    /**
     * Registra el metabox Información de Contacto.
     */
    public function add_meta_box() {
        add_meta_box(
            'oy_location_contact',
            __( 'Información de Contacto', 'lealez' ),
            array( $this, 'render_contact_meta_box' ),
            $this->post_type,
            'normal',
            'default'
        );
    }

    /**
     * Render Contact Information meta box
     */
    public function render_contact_meta_box( $post ) {
        $phone               = get_post_meta( $post->ID, 'location_phone', true );
        $phone_additional_list = get_post_meta( $post->ID, 'gmb_phone_additional_list', true );
        $chat_url            = get_post_meta( $post->ID, 'location_chat_url', true );
        // Backward compat: if no chat_url set but old whatsapp field exists
        if ( empty( $chat_url ) ) {
            $chat_url = get_post_meta( $post->ID, 'location_whatsapp', true );
        }
        $email               = get_post_meta( $post->ID, 'location_email', true );
        $website             = get_post_meta( $post->ID, 'location_website', true );
        // NOTA: location_menu_url se gestiona en el metabox "Menú del Negocio" (class-oy-location-menu-metabox.php)

        // ── Arrays dinámicos de URLs de Reservas y Ordenar Online ──────────────────────
        // Estructura de cada entrada: ['url' => '', 'label' => '', 'type' => '', 'from_gmb' => 0]
        $booking_urls = get_post_meta( $post->ID, 'location_booking_urls', true );
        if ( ! is_array( $booking_urls ) || empty( $booking_urls ) ) {
            // Migración desde campo legacy location_booking_url
            $legacy_booking = get_post_meta( $post->ID, 'location_booking_url', true );
            $booking_urls   = $legacy_booking
                ? array( array( 'url' => $legacy_booking, 'label' => __( 'Reservas', 'lealez' ), 'type' => 'APPOINTMENT', 'from_gmb' => 0 ) )
                : array();
        }

        $order_urls = get_post_meta( $post->ID, 'location_order_urls', true );
        if ( ! is_array( $order_urls ) || empty( $order_urls ) ) {
            // Migración desde campo legacy location_order_url
            $legacy_order = get_post_meta( $post->ID, 'location_order_url', true );
            $order_urls   = $legacy_order
                ? array( array( 'url' => $legacy_order, 'label' => __( 'Ordenar en línea', 'lealez' ), 'type' => 'FOOD_ORDERING', 'from_gmb' => 0 ) )
                : array();
        }

        // Etiquetas legibles para cada placeActionType
        $action_type_labels = array(
            'APPOINTMENT'        => __( 'Reservas', 'lealez' ),
            'ONLINE_APPOINTMENT' => __( 'Cita online', 'lealez' ),
            'DINING_RESERVATION' => __( 'Reserva de mesa', 'lealez' ),
            'FOOD_ORDERING'      => __( 'Ordenar en línea', 'lealez' ),
            'FOOD_DELIVERY'      => __( 'Domicilio', 'lealez' ),
            'FOOD_TAKEOUT'       => __( 'Para llevar', 'lealez' ),
            'SHOP_ONLINE'        => __( 'Tienda online', 'lealez' ),
            'ORDER_AHEAD'        => __( 'Ordenar anticipado', 'lealez' ),
            'ORDER_FOOD'         => __( 'Pedir comida', 'lealez' ),
        );

        // Social profiles: from GMB attributes (auto) + manual overrides
        $gmb_social_profiles = get_post_meta( $post->ID, 'gmb_social_profiles_raw', true );
        $social_profiles_manual = get_post_meta( $post->ID, 'social_profiles_manual', true );

        if ( ! is_array( $phone_additional_list ) ) {
            $phone_additional_list = array();
        }
        if ( ! is_array( $gmb_social_profiles ) ) {
            $gmb_social_profiles = array();
        }
        if ( ! is_array( $social_profiles_manual ) ) {
            $social_profiles_manual = array();
        }

        // Social network labels
        $social_network_labels = array(
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'twitter'   => 'Twitter / X',
            'linkedin'  => 'LinkedIn',
            'youtube'   => 'YouTube',
            'tiktok'    => 'TikTok',
            'pinterest' => 'Pinterest',
        );
        ?>
        <h4 style="margin-top:0;"><?php _e( '📞 Teléfonos', 'lealez' ); ?></h4>
        <p class="description" style="margin-bottom:10px;"><?php _e( 'Importado desde GMB: <code>phoneNumbers</code>. Puedes agregar o quitar teléfonos adicionales.', 'lealez' ); ?></p>

        <table class="form-table" style="margin-bottom:0;">
            <tr>
                <th scope="row">
                    <label for="location_phone"><?php _e( 'Teléfono Principal', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="tel"
                           name="location_phone"
                           id="location_phone"
                           value="<?php echo esc_attr( $phone ); ?>"
                           class="regular-text"
                           placeholder="+573001234567">
                    <p class="description"><?php _e( 'GMB: <code>phoneNumbers.primaryPhone</code>. Formato E.164 recomendado.', 'lealez' ); ?></p>
                </td>
            </tr>
        </table>

        <?php /* Teléfonos adicionales dinámicos */ ?>
        <div style="margin: 8px 0 16px 160px;" id="oy-additional-phones-wrap">
            <p style="font-weight:600; margin:0 0 6px; font-size:13px;"><?php _e( 'Teléfonos Adicionales', 'lealez' ); ?> <span style="font-weight:400; color:#777; font-size:12px;"><?php _e( '(GMB: <code>phoneNumbers.additionalPhones</code>)', 'lealez' ); ?></span></p>
            <div id="oy-additional-phones-list">
                <?php if ( ! empty( $phone_additional_list ) ) :
                    foreach ( $phone_additional_list as $idx => $extra_phone ) : ?>
                    <div class="oy-phone-row" style="display:flex; gap:6px; margin-bottom:6px; align-items:center;">
                        <input type="tel"
                               name="gmb_phone_additional_list[]"
                               value="<?php echo esc_attr( $extra_phone ); ?>"
                               class="regular-text"
                               placeholder="+573001234567">
                        <button type="button" class="button button-small oy-remove-phone" style="color:#dc3232;">✕</button>
                    </div>
                    <?php endforeach;
                endif; ?>
            </div>
            <button type="button" id="oy-add-phone" class="button button-small">+ <?php _e( 'Agregar teléfono', 'lealez' ); ?></button>
        </div>

        <hr style="margin:0 0 16px;">

        <h4><?php _e( '💬 Mensajería', 'lealez' ); ?></h4>
        <table class="form-table" style="margin-bottom:0;">
            <tr>
                <th scope="row">
                    <label for="location_chat_url"><?php _e( 'Usuario de chat', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="url"
                           name="location_chat_url"
                           id="location_chat_url"
                           value="<?php echo esc_attr( $chat_url ); ?>"
                           class="large-text"
                           placeholder="https://wa.me/573001234567">
                    <p class="description"><?php _e( 'Permite que los clientes chateen con tu empresa vía WhatsApp o SMS. 🔄 Se importa automáticamente desde GMB (<code>url_whatsapp</code> / <code>url_text_messaging</code>) — o puedes ingresarlo manualmente.', 'lealez' ); ?></p>
                </td>
            </tr>
        </table>

        <hr style="margin:16px 0;">

        <h4><?php _e( '📧 Contacto Web', 'lealez' ); ?></h4>
        <?php
        // ── Alerta de error de Place Actions API ─────────────────────────────────────────
        $pa_api_error = get_post_meta( $post->ID, 'gmb_place_actions_api_error', true );
        if ( ! empty( $pa_api_error ) && is_array( $pa_api_error ) ) :
            $pa_err_code    = ! empty( $pa_api_error['code'] ) ? ' [' . esc_html( $pa_api_error['code'] ) . ']' : '';
            $pa_err_msg     = ! empty( $pa_api_error['message'] ) ? esc_html( $pa_api_error['message'] ) : __( 'Error desconocido', 'lealez' );
            $pa_err_time    = ! empty( $pa_api_error['timestamp'] ) ? ' — ' . esc_html( $pa_api_error['timestamp'] ) : '';
            ?>
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:10px 14px;margin-bottom:12px;font-size:13px;line-height:1.5;">
                <strong>⚠️ <?php _e( 'Place Actions API — Error al sincronizar URLs de Reserva / Menú / Ordenar Online', 'lealez' ); ?></strong><?php echo $pa_err_time; ?><br>
                <em><?php echo $pa_err_code . ' ' . $pa_err_msg; ?></em><br><br>
                <strong><?php _e( 'Solución:', 'lealez' ); ?></strong>
                <ol style="margin:6px 0 0 18px;padding:0;">
                    <li><?php _e( 'Ve a <a href="https://console.cloud.google.com/apis/library/mybusinessplaceactions.googleapis.com" target="_blank" rel="noopener">Google Cloud Console → APIs → My Business Place Actions API</a> y habilítala.', 'lealez' ); ?></li>
                    <li><?php _e( 'Desconecta y vuelve a conectar tu cuenta Google My Business para renovar el token OAuth.', 'lealez' ); ?></li>
                    <li><?php _e( 'Guarda o re-importa esta ubicación para que se intente de nuevo.', 'lealez' ); ?></li>
                </ol>
                <p style="margin:8px 0 0;color:#666;"><?php _e( 'Los campos "URL de Reservas", "URL del Menú" y "URL para Ordenar Online" deben completarse manualmente hasta que el error se resuelva.', 'lealez' ); ?></p>
            </div>
        <?php endif; ?>
        <table class="form-table" style="margin-bottom:0;">
            <tr>
                <th scope="row">
                    <label for="location_email"><?php _e( 'Email', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="email"
                           name="location_email"
                           id="location_email"
                           value="<?php echo esc_attr( $email ); ?>"
                           class="regular-text">
                    <p class="description"><?php _e( '⚙️ Solo manual — Google My Business no tiene campo de email.', 'lealez' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location_website"><?php _e( 'Sitio Web', 'lealez' ); ?></label>
                </th>
                <td>
                    <input type="url"
                           name="location_website"
                           id="location_website"
                           value="<?php echo esc_attr( $website ); ?>"
                           class="large-text">
                    <p class="description"><?php _e( 'Importado desde GMB: <code>websiteUri</code>', 'lealez' ); ?></p>
                </td>
            </tr>
        </table>

        <?php /* ── URLs de Reservas (dinámica, múltiple) ── */ ?>
        <div style="margin:12px 0 16px 0;" id="oy-booking-urls-wrap">
            <p style="font-weight:600; margin:0 0 4px; font-size:13px;">
                <?php _e( 'URLs de Reservas', 'lealez' ); ?>
                <span style="font-weight:400; color:#777; font-size:12px;">
                    <?php _e( '(GMB: Place Actions API — <code>APPOINTMENT</code> / <code>ONLINE_APPOINTMENT</code> / <code>DINING_RESERVATION</code>)', 'lealez' ); ?>
                </span>
            </p>
            <div id="oy-booking-urls-list">
                <?php foreach ( $booking_urls as $idx => $entry ) :
                    $burl      = ! empty( $entry['url'] )      ? $entry['url']      : '';
                    $blabel    = ! empty( $entry['label'] )     ? $entry['label']    : '';
                    $btype     = ! empty( $entry['type'] )      ? $entry['type']     : '';
                    $bfromgmb  = ! empty( $entry['from_gmb'] )  ? 1 : 0;
                ?>
                <div class="oy-booking-url-row" style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">
                    <input type="url"
                           name="location_booking_urls[<?php echo $idx; ?>][url]"
                           value="<?php echo esc_attr( $burl ); ?>"
                           class="large-text"
                           placeholder="https://..."
                           style="flex:1;min-width:250px;">
                    <input type="text"
                           name="location_booking_urls[<?php echo $idx; ?>][label]"
                           value="<?php echo esc_attr( $blabel ); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'Etiqueta (ej: Reservas)', 'lealez' ); ?>"
                           style="max-width:180px;">
                    <input type="hidden" name="location_booking_urls[<?php echo $idx; ?>][type]"     value="<?php echo esc_attr( $btype ); ?>">
                    <input type="hidden" name="location_booking_urls[<?php echo $idx; ?>][from_gmb]" value="<?php echo $bfromgmb; ?>">
                    <?php if ( $bfromgmb ) : ?>
                        <span style="font-size:11px;color:#2271b1;white-space:nowrap;background:#e8f0fe;border:1px solid #b3d4f5;border-radius:3px;padding:2px 6px;">
                            🔄 GMB<?php if ( $btype ) echo ' · ' . esc_html( $btype ); ?>
                        </span>
                    <?php endif; ?>
                    <button type="button" class="button button-small oy-remove-booking-url" style="color:#dc3232;">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="oy-add-booking-url" class="button button-small">
                + <?php _e( 'Agregar URL de reservas', 'lealez' ); ?>
            </button>
        </div>

        <?php /* ── URLs Ordenar Online (dinámica, múltiple) ── */ ?>
        <div style="margin:12px 0 16px 0;" id="oy-order-urls-wrap">
            <p style="font-weight:600; margin:0 0 4px; font-size:13px;">
                <?php _e( 'URLs para Ordenar Online', 'lealez' ); ?>
                <span style="font-weight:400; color:#777; font-size:12px;">
                    <?php _e( '(GMB: Place Actions API — <code>FOOD_ORDERING</code> / <code>FOOD_DELIVERY</code> / <code>FOOD_TAKEOUT</code> / <code>SHOP_ONLINE</code> / etc.)', 'lealez' ); ?>
                </span>
            </p>
            <div id="oy-order-urls-list">
                <?php foreach ( $order_urls as $idx => $entry ) :
                    $ourl     = ! empty( $entry['url'] )      ? $entry['url']      : '';
                    $olabel   = ! empty( $entry['label'] )     ? $entry['label']    : '';
                    $otype    = ! empty( $entry['type'] )      ? $entry['type']     : '';
                    $ofromgmb = ! empty( $entry['from_gmb'] )  ? 1 : 0;
                ?>
                <div class="oy-order-url-row" style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">
                    <input type="url"
                           name="location_order_urls[<?php echo $idx; ?>][url]"
                           value="<?php echo esc_attr( $ourl ); ?>"
                           class="large-text"
                           placeholder="https://..."
                           style="flex:1;min-width:250px;">
                    <input type="text"
                           name="location_order_urls[<?php echo $idx; ?>][label]"
                           value="<?php echo esc_attr( $olabel ); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'Etiqueta (ej: Domicilio)', 'lealez' ); ?>"
                           style="max-width:180px;">
                    <input type="hidden" name="location_order_urls[<?php echo $idx; ?>][type]"     value="<?php echo esc_attr( $otype ); ?>">
                    <input type="hidden" name="location_order_urls[<?php echo $idx; ?>][from_gmb]" value="<?php echo $ofromgmb; ?>">
                    <?php if ( $ofromgmb ) : ?>
                        <span style="font-size:11px;color:#2271b1;white-space:nowrap;background:#e8f0fe;border:1px solid #b3d4f5;border-radius:3px;padding:2px 6px;">
                            🔄 GMB<?php if ( $otype ) echo ' · ' . esc_html( $otype ); ?>
                        </span>
                    <?php endif; ?>
                    <button type="button" class="button button-small oy-remove-order-url" style="color:#dc3232;">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="oy-add-order-url" class="button button-small">
                + <?php _e( 'Agregar URL de pedidos', 'lealez' ); ?>
            </button>
        </div>

        <?php /* ── URL Vínculo del Menú / Servicios (GMB: Place Actions API → MENU) ── */ ?>
        <div style="margin:12px 0 16px 0;" id="oy-menu-link-wrap">
            <p style="font-weight:600; margin:0 0 4px; font-size:13px;">
                <?php _e( 'Vínculo del Menú / Servicios', 'lealez' ); ?>
                <span style="font-weight:400; color:#777; font-size:12px;">
                    <?php _e( '(GMB: Place Actions API — <code>MENU</code>)', 'lealez' ); ?>
                </span>
            </p>
            <p class="description" style="margin:0 0 8px;">
                <?php _e( 'Enlace que Google muestra en tu Perfil de Negocio como "Vínculo del menú o los servicios". Se sincroniza automáticamente desde GMB (Place Actions → MENU) o puedes ingresarlo manualmente aquí.', 'lealez' ); ?>
            </p>
            <?php
            $current_menu_url = (string) get_post_meta( $post->ID, 'location_menu_url', true );
            $menu_url_from_gmb = (bool) get_post_meta( $post->ID, 'location_menu_url_from_gmb', true );
            ?>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="url"
                       name="location_menu_url_gmb"
                       id="location_menu_url_gmb"
                       value="<?php echo esc_attr( $current_menu_url ); ?>"
                       class="large-text"
                       placeholder="https://tu-restaurante.com/menu"
                       style="flex:1;min-width:280px;">
                <?php if ( $menu_url_from_gmb && $current_menu_url ) : ?>
                    <span style="font-size:11px;color:#2271b1;white-space:nowrap;background:#e8f0fe;border:1px solid #b3d4f5;border-radius:3px;padding:2px 6px;">
                        🔄 GMB · MENU
                    </span>
                <?php endif; ?>
            </div>
            <?php if ( $current_menu_url ) : ?>
                <p style="margin:4px 0 0;font-size:12px;">
                    <a href="<?php echo esc_url( $current_menu_url ); ?>" target="_blank" rel="noopener">
                        <?php echo esc_html( $current_menu_url ); ?> ↗
                    </a>
                </p>
            <?php endif; ?>
        </div>

        <hr style="margin:16px 0;">
        <p class="description" style="margin-bottom:10px;">
            <?php _e( '🔄 Se sincronizan automáticamente desde los atributos de Google My Business (<code>url_facebook</code>, <code>url_instagram</code>, etc.). Puedes editar o agregar perfiles adicionales manualmente.', 'lealez' ); ?>
        </p>

        <?php if ( ! empty( $gmb_social_profiles ) ) : ?>
        <div style="background:#f0f6fc; border:1px solid #b3d4f5; border-radius:4px; padding:10px 14px; margin-bottom:12px;">
            <strong style="font-size:12px; color:#2271b1; display:block; margin-bottom:8px;">
                🔄 <?php _e( 'Sincronizados desde Google My Business:', 'lealez' ); ?>
            </strong>
            <?php foreach ( $gmb_social_profiles as $network => $url ) :
                $network_label = isset( $social_network_labels[ $network ] ) ? $social_network_labels[ $network ] : ucfirst( $network );
                // Detectar ícono por red
                $icons = array(
                    'facebook'  => '📘',
                    'instagram' => '📸',
                    'twitter'   => '🐦',
                    'linkedin'  => '💼',
                    'youtube'   => '▶️',
                    'tiktok'    => '🎵',
                    'pinterest' => '📌',
                );
                $icon = isset( $icons[ $network ] ) ? $icons[ $network ] . ' ' : '';
                ?>
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <span style="min-width:100px; font-weight:600; font-size:12px;"><?php echo esc_html( $icon . $network_label ); ?></span>
                    <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" style="font-size:12px; color:#2271b1; word-break:break-all;"><?php echo esc_html( $url ); ?></a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else : ?>
        <div style="background:#fffbe5; border:1px solid #f0c000; border-radius:4px; padding:8px 14px; margin-bottom:12px; font-size:12px; color:#7a5c00;">
            <?php _e( '⚠️ No se han sincronizado redes sociales desde GMB. Asegúrate de que la ubicación esté sincronizada y que hayas configurado las redes sociales en tu Perfil de Negocio de Google.', 'lealez' ); ?>
        </div>
        <?php endif; ?>

        <div id="oy-social-profiles-list">
            <?php
            // Construir lista editable: GMB como base, sobreescrita por entradas manuales
            // Las entradas manuales permiten añadir redes que no vienen de GMB o corregir URLs
            $all_social = array_merge( $gmb_social_profiles, $social_profiles_manual );
            if ( ! empty( $all_social ) ) :
                foreach ( $all_social as $network => $url ) :
                    if ( empty( $url ) ) continue; // Saltar entradas vacías
                    $is_from_gmb = isset( $gmb_social_profiles[ $network ] ) && ! isset( $social_profiles_manual[ $network ] );
                    ?>
                    <div class="oy-social-row" style="display:flex; gap:6px; margin-bottom:8px; align-items:center; flex-wrap:wrap;">
                        <select name="social_profiles_manual_network[]" class="oy-social-network-select" style="min-width:130px;">
                            <?php foreach ( $social_network_labels as $val => $lbl ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $network, $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                            <?php endforeach; ?>
                            <option value="other" <?php selected( ! isset( $social_network_labels[ $network ] ), true ); ?>><?php _e( 'Otra', 'lealez' ); ?></option>
                        </select>
                        <input type="url"
                               name="social_profiles_manual_url[]"
                               value="<?php echo esc_attr( $url ); ?>"
                               class="large-text"
                               placeholder="https://..."
                               <?php echo $is_from_gmb ? 'data-from-gmb="1"' : ''; ?>>
                        <?php if ( $is_from_gmb ) : ?>
                            <span style="font-size:11px; color:#2271b1; white-space:nowrap;">🔄 GMB</span>
                        <?php endif; ?>
                        <button type="button" class="button button-small oy-remove-social" style="color:#dc3232;">✕</button>
                    </div>
                <?php endforeach;
            endif; ?>
        </div>
        <button type="button" id="oy-add-social" class="button button-small">+ <?php _e( 'Agregar red social', 'lealez' ); ?></button>

        <script type="text/javascript">
        jQuery(document).ready(function($){

            // ── Teléfonos adicionales ──
            $('#oy-add-phone').on('click', function(){
                var row = '<div class="oy-phone-row" style="display:flex;gap:6px;margin-bottom:6px;align-items:center;">' +
                    '<input type="tel" name="gmb_phone_additional_list[]" class="regular-text" placeholder="+573001234567">' +
                    '<button type="button" class="button button-small oy-remove-phone" style="color:#dc3232;">✕</button>' +
                    '</div>';
                $('#oy-additional-phones-list').append(row);
            });
            $(document).on('click', '.oy-remove-phone', function(){
                $(this).closest('.oy-phone-row').remove();
            });

            // ── URLs de Reservas (múltiple) ──────────────────────────────────────────
            function oyBookingUrlNextIdx() {
                var max = -1;
                $('#oy-booking-urls-list .oy-booking-url-row').each(function(){
                    $(this).find('input[name^="location_booking_urls["]').each(function(){
                        var m = this.name.match(/location_booking_urls\[(\d+)\]/);
                        if (m) { max = Math.max(max, parseInt(m[1], 10)); }
                    });
                });
                return max + 1;
            }
            $('#oy-add-booking-url').on('click', function(){
                var idx = oyBookingUrlNextIdx();
                var row = '<div class="oy-booking-url-row" style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">' +
                    '<input type="url" name="location_booking_urls[' + idx + '][url]" class="large-text" placeholder="https://..." style="flex:1;min-width:250px;">' +
                    '<input type="text" name="location_booking_urls[' + idx + '][label]" class="regular-text" placeholder="<?php echo esc_js( __( 'Etiqueta (ej: Reservas)', 'lealez' ) ); ?>" style="max-width:180px;">' +
                    '<input type="hidden" name="location_booking_urls[' + idx + '][type]" value="">' +
                    '<input type="hidden" name="location_booking_urls[' + idx + '][from_gmb]" value="0">' +
                    '<button type="button" class="button button-small oy-remove-booking-url" style="color:#dc3232;">✕</button>' +
                    '</div>';
                $('#oy-booking-urls-list').append(row);
            });
            $(document).on('click', '.oy-remove-booking-url', function(){
                $(this).closest('.oy-booking-url-row').remove();
            });

            // ── URLs para Ordenar Online (múltiple) ──────────────────────────────────
            function oyOrderUrlNextIdx() {
                var max = -1;
                $('#oy-order-urls-list .oy-order-url-row').each(function(){
                    $(this).find('input[name^="location_order_urls["]').each(function(){
                        var m = this.name.match(/location_order_urls\[(\d+)\]/);
                        if (m) { max = Math.max(max, parseInt(m[1], 10)); }
                    });
                });
                return max + 1;
            }
            $('#oy-add-order-url').on('click', function(){
                var idx = oyOrderUrlNextIdx();
                var row = '<div class="oy-order-url-row" style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">' +
                    '<input type="url" name="location_order_urls[' + idx + '][url]" class="large-text" placeholder="https://..." style="flex:1;min-width:250px;">' +
                    '<input type="text" name="location_order_urls[' + idx + '][label]" class="regular-text" placeholder="<?php echo esc_js( __( 'Etiqueta (ej: Domicilio)', 'lealez' ) ); ?>" style="max-width:180px;">' +
                    '<input type="hidden" name="location_order_urls[' + idx + '][type]" value="">' +
                    '<input type="hidden" name="location_order_urls[' + idx + '][from_gmb]" value="0">' +
                    '<button type="button" class="button button-small oy-remove-order-url" style="color:#dc3232;">✕</button>' +
                    '</div>';
                $('#oy-order-urls-list').append(row);
            });
            $(document).on('click', '.oy-remove-order-url', function(){
                $(this).closest('.oy-order-url-row').remove();
            });

            // ── Redes sociales ──
            var networkOptions = '<?php
                $opts = '';
                foreach ( $social_network_labels as $val => $lbl ) {
                    $opts .= '<option value="' . esc_attr( $val ) . '">' . esc_html( $lbl ) . '</option>';
                }
                $opts .= '<option value="other">' . esc_html__( 'Otra', 'lealez' ) . '</option>';
                echo esc_js( $opts );
            ?>';

            $('#oy-add-social').on('click', function(){
                var row = '<div class="oy-social-row" style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">' +
                    '<select name="social_profiles_manual_network[]" class="oy-social-network-select" style="min-width:130px;">' + networkOptions + '</select>' +
                    '<input type="url" name="social_profiles_manual_url[]" class="large-text" placeholder="https://...">' +
                    '<button type="button" class="button button-small oy-remove-social" style="color:#dc3232;">✕</button>' +
                    '</div>';
                $('#oy-social-profiles-list').append(row);
            });
            $(document).on('click', '.oy-remove-social', function(){
                $(this).closest('.oy-social-row').remove();
            });
        });
        </script>
        <?php
    }

    /**
     * Guarda los campos del metabox Información de Contacto.
     *
     * Mantiene compatibilidad con las meta keys anteriores:
     * - location_phone
     * - gmb_phone_additional_list
     * - location_phone_additional
     * - location_chat_url
     * - location_email
     * - location_website
     * - social_profiles_manual
     * - social_facebook_local
     * - social_instagram_local
     * - location_booking_urls / location_booking_url
     * - location_order_urls / location_order_url
     * - location_menu_url / location_menu_url_from_gmb
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public function save_meta_box( $post_id, $post ) {
        if ( ! isset( $_POST[ $this->nonce_name ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ $this->nonce_name ] ), $this->nonce_action ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! $post || $this->post_type !== $post->post_type ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Campos simples de contacto.
        $simple_fields = array(
            'location_phone'    => 'sanitize_text_field',
            'location_chat_url' => 'esc_url_raw',
            'location_email'    => 'sanitize_email',
            'location_website'  => 'esc_url_raw',
        );

        foreach ( $simple_fields as $field_name => $sanitize_callback ) {
            if ( isset( $_POST[ $field_name ] ) ) {
                $value = call_user_func( $sanitize_callback, wp_unslash( $_POST[ $field_name ] ) );
                update_post_meta( $post_id, $field_name, $value );
            } else {
                delete_post_meta( $post_id, $field_name );
            }
        }

        // Save additional phones (dynamic list from gmb_phone_additional_list[]).
        if ( isset( $_POST['gmb_phone_additional_list'] ) && is_array( $_POST['gmb_phone_additional_list'] ) ) {
            $additional_phones = array_map(
                'sanitize_text_field',
                array_map( 'wp_unslash', $_POST['gmb_phone_additional_list'] )
            );
            $additional_phones = array_values( array_filter( $additional_phones ) );
            update_post_meta( $post_id, 'gmb_phone_additional_list', $additional_phones );

            // Backward compat: fill location_phone_additional with first entry.
            if ( ! empty( $additional_phones ) ) {
                update_post_meta( $post_id, 'location_phone_additional', $additional_phones[0] );
            } else {
                delete_post_meta( $post_id, 'location_phone_additional' );
            }
        } else {
            update_post_meta( $post_id, 'gmb_phone_additional_list', array() );
            delete_post_meta( $post_id, 'location_phone_additional' );
        }

        // Save social profiles (manual entries from dynamic list).
        $social_networks_raw = isset( $_POST['social_profiles_manual_network'] ) && is_array( $_POST['social_profiles_manual_network'] )
            ? array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['social_profiles_manual_network'] ) )
            : array();
        $social_urls_raw     = isset( $_POST['social_profiles_manual_url'] ) && is_array( $_POST['social_profiles_manual_url'] )
            ? array_map( 'esc_url_raw', array_map( 'wp_unslash', $_POST['social_profiles_manual_url'] ) )
            : array();

        $social_profiles_manual = array();
        foreach ( $social_networks_raw as $idx => $net ) {
            if ( ! empty( $net ) && ! empty( $social_urls_raw[ $idx ] ) ) {
                $social_profiles_manual[ sanitize_key( $net ) ] = $social_urls_raw[ $idx ];
            }
        }
        update_post_meta( $post_id, 'social_profiles_manual', $social_profiles_manual );

        // Backward compat: keep old social_facebook_local / social_instagram_local.
        if ( isset( $social_profiles_manual['facebook'] ) ) {
            update_post_meta( $post_id, 'social_facebook_local', $social_profiles_manual['facebook'] );
        }
        if ( isset( $social_profiles_manual['instagram'] ) ) {
            update_post_meta( $post_id, 'social_instagram_local', $social_profiles_manual['instagram'] );
        }

        // Save location_booking_urls (array dinámico de URLs de Reservas).
        // Estructura: [ ['url'=>'...','label'=>'...','type'=>'...','from_gmb'=>0], ... ].
        $booking_urls_raw = isset( $_POST['location_booking_urls'] ) && is_array( $_POST['location_booking_urls'] )
            ? wp_unslash( $_POST['location_booking_urls'] )
            : array();

        $booking_urls_clean = array();
        foreach ( $booking_urls_raw as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $burl  = esc_url_raw( (string) ( $entry['url']      ?? '' ) );
            $blbl  = sanitize_text_field( (string) ( $entry['label']    ?? '' ) );
            $btype = sanitize_text_field( (string) ( $entry['type']     ?? '' ) );
            $bfgmb = absint( $entry['from_gmb'] ?? 0 );
            if ( $burl ) {
                $booking_urls_clean[] = array(
                    'url'      => $burl,
                    'label'    => $blbl,
                    'type'     => $btype,
                    'from_gmb' => $bfgmb,
                );
            }
        }
        update_post_meta( $post_id, 'location_booking_urls', $booking_urls_clean );

        // Backward compat: mantener location_booking_url con la primera entrada.
        if ( ! empty( $booking_urls_clean ) ) {
            update_post_meta( $post_id, 'location_booking_url', $booking_urls_clean[0]['url'] );
        } else {
            delete_post_meta( $post_id, 'location_booking_url' );
        }

        // Save location_order_urls (array dinámico de URLs para Ordenar Online).
        $order_urls_raw = isset( $_POST['location_order_urls'] ) && is_array( $_POST['location_order_urls'] )
            ? wp_unslash( $_POST['location_order_urls'] )
            : array();

        $order_urls_clean = array();
        foreach ( $order_urls_raw as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $ourl  = esc_url_raw( (string) ( $entry['url']      ?? '' ) );
            $olbl  = sanitize_text_field( (string) ( $entry['label']    ?? '' ) );
            $otype = sanitize_text_field( (string) ( $entry['type']     ?? '' ) );
            $ofgmb = absint( $entry['from_gmb'] ?? 0 );
            if ( $ourl ) {
                $order_urls_clean[] = array(
                    'url'      => $ourl,
                    'label'    => $olbl,
                    'type'     => $otype,
                    'from_gmb' => $ofgmb,
                );
            }
        }
        update_post_meta( $post_id, 'location_order_urls', $order_urls_clean );

        // Backward compat: mantener location_order_url con la primera entrada.
        if ( ! empty( $order_urls_clean ) ) {
            update_post_meta( $post_id, 'location_order_url', $order_urls_clean[0]['url'] );
        } else {
            delete_post_meta( $post_id, 'location_order_url' );
        }

        // Save location_menu_url desde el campo editable del contact metabox.
        // POST name: location_menu_url_gmb (distinto del hidden field del menu metabox que usa location_menu_url).
        if ( isset( $_POST['location_menu_url_gmb'] ) ) {
            $menu_url_val = esc_url_raw( wp_unslash( (string) $_POST['location_menu_url_gmb'] ) );
            update_post_meta( $post_id, 'location_menu_url', $menu_url_val );

            // location_menu_url_from_gmb solo se escribe a '1' durante el import de GMB.
            // Si el usuario borra el campo manualmente, se limpia el flag de GMB.
            if ( '' === $menu_url_val ) {
                delete_post_meta( $post_id, 'location_menu_url_from_gmb' );
            }
        }
    }
}

endif;
