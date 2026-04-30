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

        add_action( 'wp_ajax_oy_sync_contact_from_gmb', array( $this, 'ajax_sync_contact_from_gmb' ) );
        add_action( 'wp_ajax_oy_clear_contact_log', array( $this, 'ajax_clear_contact_log' ) );
        add_action( 'wp_ajax_oy_push_contact_to_gmb', array( $this, 'ajax_push_contact_to_gmb' ) );
        add_action( 'wp_ajax_oy_check_contact_push_status', array( $this, 'ajax_check_contact_push_status' ) );
        add_action( 'oy_poll_contact_push_status', array( 'OY_Location_Contact_Metabox', 'cron_poll_contact_push_status' ) );
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
     * Devuelve la etiqueta de fecha/hora local para logs UI.
     *
     * @param int|null $timestamp Timestamp UNIX.
     * @return string
     */
    private static function contact_datetime_label( $timestamp = null ) {
        $timestamp = $timestamp ? (int) $timestamp : time();
        return wp_date( 'Y-m-d H:i:s', $timestamp );
    }

    /**
     * Normaliza URLs dinámicas guardadas en el metabox.
     *
     * @param mixed  $raw_entries Entradas crudas.
     * @param string $default_type Tipo GMB por defecto.
     * @return array
     */
    private function normalize_contact_url_entries( $raw_entries, $default_type = '' ) {
        if ( ! is_array( $raw_entries ) ) {
            return array();
        }

        $out  = array();
        $seen = array();

        foreach ( $raw_entries as $entry ) {
            if ( is_string( $entry ) ) {
                $entry = array( 'url' => $entry );
            }
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $url = ! empty( $entry['url'] ) ? esc_url_raw( (string) $entry['url'] ) : '';
            if ( '' === $url ) {
                continue;
            }

            $key = strtolower( $url );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;

            $out[] = array(
                'url'      => $url,
                'label'    => ! empty( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '',
                'type'     => ! empty( $entry['type'] ) ? strtoupper( sanitize_text_field( (string) $entry['type'] ) ) : $default_type,
                'from_gmb' => ! empty( $entry['from_gmb'] ) ? 1 : 0,
            );
        }

        return $out;
    }

    /**
     * Lee los datos actuales del metabox de Contacto desde post_meta.
     *
     * @param int $post_id Post ID.
     * @return array
     */
    private function collect_local_contact_data( $post_id ) {
        $post_id = absint( $post_id );

        $additional_phones = get_post_meta( $post_id, 'gmb_phone_additional_list', true );
        if ( ! is_array( $additional_phones ) ) {
            $additional_phones = array();
        }
        $additional_phones = array_values( array_filter( array_map( 'sanitize_text_field', $additional_phones ) ) );

        $gmb_social    = get_post_meta( $post_id, 'gmb_social_profiles_raw', true );
        $manual_social = get_post_meta( $post_id, 'social_profiles_manual', true );
        if ( ! is_array( $gmb_social ) ) {
            $gmb_social = array();
        }
        if ( ! is_array( $manual_social ) ) {
            $manual_social = array();
        }

        $social_profiles = array();
        foreach ( array_merge( $gmb_social, $manual_social ) as $network => $url ) {
            $network = sanitize_key( (string) $network );
            $url     = esc_url_raw( (string) $url );
            if ( '' !== $network && '' !== $url ) {
                $social_profiles[ $network ] = $url;
            }
        }

        return array(
            'primaryPhone'     => sanitize_text_field( (string) get_post_meta( $post_id, 'location_phone', true ) ),
            'additionalPhones' => $additional_phones,
            'websiteUri'       => esc_url_raw( (string) get_post_meta( $post_id, 'location_website', true ) ),
            'email'            => sanitize_email( (string) get_post_meta( $post_id, 'location_email', true ) ),
            'chatUrl'          => esc_url_raw( (string) get_post_meta( $post_id, 'location_chat_url', true ) ),
            'menuUrl'          => esc_url_raw( (string) get_post_meta( $post_id, 'location_menu_url', true ) ),
            'bookingUrls'      => $this->normalize_contact_url_entries( get_post_meta( $post_id, 'location_booking_urls', true ), 'APPOINTMENT' ),
            'orderUrls'        => $this->normalize_contact_url_entries( get_post_meta( $post_id, 'location_order_urls', true ), 'FOOD_ORDERING' ),
            'socialProfiles'   => $social_profiles,
        );
    }

    /**
     * Normaliza un array para comparación estable.
     *
     * @param mixed $value Valor.
     * @return mixed
     */
    private static function normalize_for_compare( $value ) {
        if ( is_array( $value ) ) {
            $is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
            $out      = array();
            foreach ( $value as $k => $v ) {
                $out[ $k ] = self::normalize_for_compare( $v );
            }
            if ( $is_assoc ) {
                ksort( $out );
            } else {
                usort( $out, function( $a, $b ) {
                    return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
                } );
            }
            return $out;
        }
        if ( is_string( $value ) ) {
            return trim( $value );
        }
        return $value;
    }

    /**
     * Genera diff legible entre dos snapshots de contacto.
     *
     * @param array $before Antes.
     * @param array $after  Después.
     * @return array
     */
    private function build_contact_diff( array $before, array $after ) {
        $labels = array(
            'primaryPhone'     => __( 'Teléfono principal', 'lealez' ),
            'additionalPhones' => __( 'Teléfonos adicionales', 'lealez' ),
            'websiteUri'       => __( 'Sitio web', 'lealez' ),
            'email'            => __( 'Email manual', 'lealez' ),
            'chatUrl'          => __( 'Usuario de chat', 'lealez' ),
            'menuUrl'          => __( 'Vínculo del menú / servicios', 'lealez' ),
            'bookingUrls'      => __( 'URLs de reservas', 'lealez' ),
            'orderUrls'        => __( 'URLs para ordenar online', 'lealez' ),
            'socialProfiles'   => __( 'Redes sociales', 'lealez' ),
        );

        $keys = array_values( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) );
        $diff = array();

        foreach ( $keys as $key ) {
            $old = $before[ $key ] ?? null;
            $new = $after[ $key ] ?? null;
            if ( self::normalize_for_compare( $old ) === self::normalize_for_compare( $new ) ) {
                continue;
            }
            $diff[] = array(
                'field' => $key,
                'label' => $labels[ $key ] ?? $key,
                'old'   => $old,
                'new'   => $new,
            );
        }

        return $diff;
    }

    /**
     * Agrega una entrada al log persistente del metabox Contacto.
     *
     * @param int    $post_id Post ID.
     * @param string $event   Evento.
     * @param string $status  Estado: success|warning|error|info.
     * @param string $message Mensaje.
     * @param array  $context Contexto adicional.
     * @return void
     */
    private static function add_contact_log_entry( $post_id, $event, $status, $message, array $context = array() ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        $entries = get_post_meta( $post_id, 'gmb_contact_sync_log', true );
        if ( ! is_array( $entries ) ) {
            $entries = array();
        }

        $user       = wp_get_current_user();
        $user_login = ( $user instanceof WP_User && ! empty( $user->user_login ) ) ? $user->user_login : 'system';
        $now        = time();

        $entries[] = array(
            'event'    => sanitize_key( $event ),
            'status'   => sanitize_key( $status ),
            'message'  => sanitize_text_field( (string) $message ),
            'at'       => gmdate( 'Y-m-d\\TH:i:s\\Z', $now ),
            'at_ts'    => $now,
            'at_label' => self::contact_datetime_label( $now ),
            'by'       => $user_login,
            'context'  => $context,
        );

        if ( count( $entries ) > 80 ) {
            $entries = array_slice( $entries, -80 );
        }

        update_post_meta( $post_id, 'gmb_contact_sync_log', $entries );
    }

    /**
     * Extrae datos de contacto desde snapshot GMB crudo.
     *
     * @param array $snapshot Snapshot de Lealez_GMB_API::get_location_contact_snapshot().
     * @return array
     */
    private static function extract_gmb_contact_data_from_snapshot( array $snapshot ) {
        $phone_numbers = isset( $snapshot['phoneNumbers'] ) && is_array( $snapshot['phoneNumbers'] ) ? $snapshot['phoneNumbers'] : array();

        $data = array(
            'primaryPhone'     => ! empty( $phone_numbers['primaryPhone'] ) ? sanitize_text_field( (string) $phone_numbers['primaryPhone'] ) : '',
            'additionalPhones' => array(),
            'websiteUri'       => ! empty( $snapshot['websiteUri'] ) ? esc_url_raw( (string) $snapshot['websiteUri'] ) : '',
            'email'            => '',
            'chatUrl'          => '',
            'menuUrl'          => '',
            'bookingUrls'      => array(),
            'orderUrls'        => array(),
            'socialProfiles'   => array(),
        );

        if ( ! empty( $phone_numbers['additionalPhones'] ) && is_array( $phone_numbers['additionalPhones'] ) ) {
            $data['additionalPhones'] = array_values( array_filter( array_map( 'sanitize_text_field', $phone_numbers['additionalPhones'] ) ) );
        }

        $attributes = isset( $snapshot['attributes'] ) && is_array( $snapshot['attributes'] ) ? $snapshot['attributes'] : array();
        $social_uri_map = array(
            'url_facebook'  => 'facebook',
            'url_instagram' => 'instagram',
            'url_twitter'   => 'twitter',
            'url_linkedin'  => 'linkedin',
            'url_youtube'   => 'youtube',
            'url_tiktok'    => 'tiktok',
            'url_pinterest' => 'pinterest',
        );

        foreach ( $attributes as $attr ) {
            if ( ! is_array( $attr ) ) {
                continue;
            }

            $attr_id_raw  = '';
            $attr_name    = '';
            if ( ! empty( $attr['attributeId'] ) ) {
                $attr_id_raw = (string) $attr['attributeId'];
                $attr_name   = $attr_id_raw;
            } elseif ( ! empty( $attr['name'] ) ) {
                $attr_name   = (string) $attr['name'];
                $parts       = explode( '/attributes/', $attr_name );
                $attr_id_raw = trim( end( $parts ), '/' );
            }

            $attr_id_lower = strtolower( trim( $attr_id_raw ) );
            $attr_name_lc  = strtolower( trim( $attr_name ) );
            $uri           = '';
            if ( ! empty( $attr['uriValues'] ) && is_array( $attr['uriValues'] ) ) {
                $uri = isset( $attr['uriValues'][0]['uri'] ) ? esc_url_raw( (string) $attr['uriValues'][0]['uri'] ) : '';
            }
            if ( '' === $uri ) {
                continue;
            }

            if ( in_array( $attr_id_lower, array( 'url_whatsapp', 'url_text_messaging', 'url_text_messaging3' ), true ) || false !== strpos( $attr_id_lower, 'whatsapp' ) ) {
                if ( '' === $data['chatUrl'] ) {
                    $data['chatUrl'] = $uri;
                }
                continue;
            }

            if ( false !== strpos( $attr_name_lc, 'menu' ) || false !== strpos( $attr_id_lower, 'menu' ) ) {
                if ( '' === $data['menuUrl'] ) {
                    $data['menuUrl'] = $uri;
                }
                continue;
            }

            foreach ( $social_uri_map as $gmb_key => $network ) {
                if ( $attr_id_lower === $gmb_key || false !== strpos( $attr_id_lower, $gmb_key ) ) {
                    $data['socialProfiles'][ $network ] = $uri;
                    break;
                }
            }
        }

        $action_type_label_map = array(
            'APPOINTMENT'        => __( 'Reservas', 'lealez' ),
            'ONLINE_APPOINTMENT' => __( 'Cita online', 'lealez' ),
            'DINING_RESERVATION' => __( 'Reserva de mesa', 'lealez' ),
            'MENU'               => __( 'Menú', 'lealez' ),
            'FOOD_ORDERING'      => __( 'Ordenar en línea', 'lealez' ),
            'FOOD_DELIVERY'      => __( 'Domicilio', 'lealez' ),
            'FOOD_TAKEOUT'       => __( 'Para llevar', 'lealez' ),
            'SHOP_ONLINE'        => __( 'Tienda online', 'lealez' ),
            'ORDER_AHEAD'        => __( 'Ordenar anticipado', 'lealez' ),
            'ORDER_FOOD'         => __( 'Pedir comida', 'lealez' ),
        );
        $booking_types = array( 'APPOINTMENT', 'ONLINE_APPOINTMENT', 'DINING_RESERVATION' );
        $order_types   = array( 'FOOD_ORDERING', 'FOOD_DELIVERY', 'FOOD_TAKEOUT', 'SHOP_ONLINE', 'ORDER_AHEAD', 'ORDER_FOOD' );

        $place_action_links = isset( $snapshot['placeActionLinks'] ) && is_array( $snapshot['placeActionLinks'] ) ? $snapshot['placeActionLinks'] : array();
        foreach ( $place_action_links as $link ) {
            if ( ! is_array( $link ) ) {
                continue;
            }
            $uri  = ! empty( $link['uri'] ) ? esc_url_raw( (string) $link['uri'] ) : '';
            $type = ! empty( $link['placeActionType'] ) ? strtoupper( trim( (string) $link['placeActionType'] ) ) : '';
            if ( '' === $uri || '' === $type ) {
                continue;
            }
            $entry = array(
                'url'      => $uri,
                'label'    => $action_type_label_map[ $type ] ?? $type,
                'type'     => $type,
                'from_gmb' => 1,
            );
            if ( in_array( $type, $booking_types, true ) ) {
                $data['bookingUrls'][] = $entry;
            } elseif ( in_array( $type, $order_types, true ) ) {
                $data['orderUrls'][] = $entry;
            } elseif ( 'MENU' === $type && '' === $data['menuUrl'] ) {
                $data['menuUrl'] = $uri;
            }
        }

        return $data;
    }

    /**
     * Aplica datos GMB de contacto a post_meta del CPT.
     *
     * @param int   $post_id Post ID.
     * @param array $snapshot Snapshot GMB.
     * @return array Datos aplicados.
     */
    private function map_gmb_contact_snapshot_to_meta( $post_id, array $snapshot ) {
        $post_id = absint( $post_id );
        $data    = self::extract_gmb_contact_data_from_snapshot( $snapshot );

        update_post_meta( $post_id, 'gmb_phone_numbers_raw', isset( $snapshot['phoneNumbers'] ) && is_array( $snapshot['phoneNumbers'] ) ? $snapshot['phoneNumbers'] : array() );
        update_post_meta( $post_id, 'location_phone', $data['primaryPhone'] );
        update_post_meta( $post_id, 'gmb_phone_additional_list', $data['additionalPhones'] );
        if ( ! empty( $data['additionalPhones'] ) ) {
            update_post_meta( $post_id, 'location_phone_additional', $data['additionalPhones'][0] );
        } else {
            delete_post_meta( $post_id, 'location_phone_additional' );
        }

        update_post_meta( $post_id, 'gmb_website_uri', $data['websiteUri'] );
        update_post_meta( $post_id, 'location_website', $data['websiteUri'] );

        update_post_meta( $post_id, 'location_chat_url', $data['chatUrl'] );
        if ( false !== strpos( strtolower( $data['chatUrl'] ), 'wa.me/' ) || false !== strpos( strtolower( $data['chatUrl'] ), 'whatsapp' ) ) {
            update_post_meta( $post_id, 'location_whatsapp', $data['chatUrl'] );
            update_post_meta( $post_id, 'gmb_chat_type', 'whatsapp' );
        } elseif ( '' !== $data['chatUrl'] ) {
            delete_post_meta( $post_id, 'location_whatsapp' );
            update_post_meta( $post_id, 'gmb_chat_type', 'sms' );
        } else {
            delete_post_meta( $post_id, 'location_whatsapp' );
            delete_post_meta( $post_id, 'gmb_chat_type' );
        }
        update_post_meta( $post_id, 'gmb_chat_url_raw', $data['chatUrl'] );

        update_post_meta( $post_id, 'location_menu_url', $data['menuUrl'] );
        if ( '' !== $data['menuUrl'] ) {
            update_post_meta( $post_id, 'location_menu_url_from_gmb', 1 );
        } else {
            delete_post_meta( $post_id, 'location_menu_url_from_gmb' );
        }

        update_post_meta( $post_id, 'gmb_social_profiles_raw', $data['socialProfiles'] );
        if ( isset( $data['socialProfiles']['facebook'] ) ) {
            update_post_meta( $post_id, 'social_facebook_local', $data['socialProfiles']['facebook'] );
        }
        if ( isset( $data['socialProfiles']['instagram'] ) ) {
            update_post_meta( $post_id, 'social_instagram_local', $data['socialProfiles']['instagram'] );
        }

        $existing_booking = get_post_meta( $post_id, 'location_booking_urls', true );
        $manual_booking   = is_array( $existing_booking ) ? array_values( array_filter( $existing_booking, function( $e ) { return is_array( $e ) && empty( $e['from_gmb'] ); } ) ) : array();
        $booking_merged   = array_merge( $data['bookingUrls'], $manual_booking );
        update_post_meta( $post_id, 'location_booking_urls', $booking_merged );
        if ( ! empty( $booking_merged ) ) {
            update_post_meta( $post_id, 'location_booking_url', $booking_merged[0]['url'] );
        } else {
            delete_post_meta( $post_id, 'location_booking_url' );
        }

        $existing_order = get_post_meta( $post_id, 'location_order_urls', true );
        $manual_order   = is_array( $existing_order ) ? array_values( array_filter( $existing_order, function( $e ) { return is_array( $e ) && empty( $e['from_gmb'] ); } ) ) : array();
        $order_merged   = array_merge( $data['orderUrls'], $manual_order );
        update_post_meta( $post_id, 'location_order_urls', $order_merged );
        if ( ! empty( $order_merged ) ) {
            update_post_meta( $post_id, 'location_order_url', $order_merged[0]['url'] );
        } else {
            delete_post_meta( $post_id, 'location_order_url' );
        }

        update_post_meta( $post_id, 'gmb_contact_last_sync', array(
            'at'       => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
            'at_label' => self::contact_datetime_label(),
            'source'   => 'gmb_contact_metabox_button',
        ) );

        delete_post_meta( $post_id, 'oy_contact_local_pending_publish' );

        return $this->collect_local_contact_data( $post_id );
    }

    /**
     * Renderiza la barra de sincronización desde GMB.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private function render_contact_sync_bar( $post_id ) {
        $post_id       = absint( $post_id );
        $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        $connected     = ! empty( $business_id ) && ! empty( $location_name );
        $nonce         = wp_create_nonce( 'oy_sync_contact_gmb_' . $post_id );

        ob_start();
        ?>
        <div id="oy-contact-sync-bar"
             data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
             data-sync-nonce="<?php echo esc_attr( $nonce ); ?>"
             style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0 0 14px;padding:10px 12px;background:#f6f7f7;border:1px solid #dadce0;border-radius:4px;">
            <div>
                <strong style="font-size:13px;">🔄 <?php _e( 'Sincronización de Contacto con Google Business Profile', 'lealez' ); ?></strong>
                <p style="margin:3px 0 0;font-size:12px;color:#666;">
                    <?php _e( 'Trae teléfonos, sitio web, chat, menú/servicios, redes sociales, reservas y pedidos online desde GMB.', 'lealez' ); ?>
                </p>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <button type="button" id="oy-contact-sync-btn" class="button button-secondary" <?php echo $connected ? '' : 'disabled'; ?> style="display:inline-flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-update" style="margin-top:3px;"></span>
                    <?php _e( 'Sincronizar desde GMB', 'lealez' ); ?>
                </button>
                <span id="oy-contact-sync-msg" style="font-size:12px;color:#555;"></span>
            </div>
            <?php if ( ! $connected ) : ?>
                <div style="flex-basis:100%;font-size:12px;color:#999;font-style:italic;">
                    <?php _e( 'Requiere empresa y ubicación GMB vinculadas en el metabox Integración Google My Business.', 'lealez' ); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza el log persistente de contacto.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private function render_contact_log_panel( $post_id ) {
        $post_id = absint( $post_id );
        $entries = get_post_meta( $post_id, 'gmb_contact_sync_log', true );
        if ( ! is_array( $entries ) ) {
            $entries = array();
        }
        $entries = array_reverse( $entries );
        $clear_nonce = wp_create_nonce( 'oy_clear_contact_log_' . $post_id );

        ob_start();
        ?>
        <div id="oy-contact-log-panel" data-clear-nonce="<?php echo esc_attr( $clear_nonce ); ?>" style="margin-bottom:16px;border:1px solid #dadce0;border-radius:4px;overflow:hidden;background:#fff;">
            <div id="oy-contact-log-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;background:#f6f7f7;cursor:pointer;border-bottom:1px solid transparent;">
                <strong style="font-size:13px;color:#1d2327;">🔍 <?php _e( 'Log de Sincronización — Información de Contacto', 'lealez' ); ?></strong>
                <span id="oy-contact-log-toggle-icon" style="font-size:13px;color:#888;transition:transform .2s;">▶</span>
            </div>
            <div id="oy-contact-log-body" style="display:none;">
                <div id="oy-contact-log-entries" style="padding:10px 14px;max-height:360px;overflow:auto;">
                    <?php if ( empty( $entries ) ) : ?>
                        <p style="margin:0;color:#777;font-size:12px;font-style:italic;"><?php _e( 'Aún no hay registros de sincronización para Contacto.', 'lealez' ); ?></p>
                    <?php else : ?>
                        <?php foreach ( $entries as $entry ) :
                            $status = sanitize_key( (string) ( $entry['status'] ?? 'info' ) );
                            $colors = array(
                                'success' => '#166534',
                                'warning' => '#b45309',
                                'error'   => '#dc3232',
                                'info'    => '#1d4ed8',
                            );
                            $color = $colors[ $status ] ?? '#555';
                            $diff  = isset( $entry['context']['diff'] ) && is_array( $entry['context']['diff'] ) ? $entry['context']['diff'] : array();
                            ?>
                            <div style="border:1px solid #e5e7eb;border-left:4px solid <?php echo esc_attr( $color ); ?>;border-radius:4px;padding:8px 10px;margin-bottom:8px;background:#fff;">
                                <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                                    <strong style="font-size:12px;color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( strtoupper( $status ) . ' · ' . ( $entry['event'] ?? 'log' ) ); ?></strong>
                                    <span style="font-size:11px;color:#777;"><?php echo esc_html( ( $entry['at_label'] ?? '' ) . ( ! empty( $entry['by'] ) ? ' · ' . $entry['by'] : '' ) ); ?></span>
                                </div>
                                <p style="margin:0 0 6px;font-size:12px;color:#333;"><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></p>
                                <?php if ( ! empty( $diff ) ) : ?>
                                    <details style="font-size:11px;color:#444;">
                                        <summary><?php printf( esc_html__( '%d campo(s) con cambios', 'lealez' ), count( $diff ) ); ?></summary>
                                        <ul style="margin:6px 0 0 18px;">
                                            <?php foreach ( $diff as $row ) : ?>
                                                <li><strong><?php echo esc_html( $row['label'] ?? $row['field'] ?? '' ); ?>:</strong>
                                                    <code><?php echo esc_html( wp_json_encode( $row['old'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></code>
                                                    →
                                                    <code><?php echo esc_html( wp_json_encode( $row['new'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></code>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                                <?php if ( ! empty( $entry['context']['warnings'] ) ) : ?>
                                    <details style="font-size:11px;color:#7a5c00;margin-top:6px;">
                                        <summary><?php _e( 'Advertencias técnicas', 'lealez' ); ?></summary>
                                        <pre style="white-space:pre-wrap;background:#fffbe5;border:1px solid #f0c000;padding:6px;border-radius:4px;"><?php echo esc_html( wp_json_encode( $entry['context']['warnings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
                                    </details>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div style="border-top:1px solid #eee;padding:8px 14px;background:#fafafa;display:flex;justify-content:flex-end;">
                    <button type="button" id="oy-contact-log-clear" class="button button-small" style="color:#dc3232;">
                        <?php _e( 'Limpiar log', 'lealez' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza el panel de envío de Contacto hacia GMB.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private function render_contact_push_panel( $post_id ) {
        $post_id      = absint( $post_id );
        $job          = get_post_meta( $post_id, 'gmb_contact_push_job', true );
        $push_nonce   = wp_create_nonce( 'oy_push_contact_gmb_' . $post_id );
        $check_nonce  = wp_create_nonce( 'oy_check_contact_push_status_' . $post_id );

        $business_id      = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name    = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        $gmb_connected    = ! empty( $business_id ) && ! empty( $location_name );
        $local_pending    = (bool) get_post_meta( $post_id, 'oy_contact_local_pending_publish', true );
        $last_manual_save = get_post_meta( $post_id, 'oy_contact_last_manual_save', true );
        $last_label       = ( is_array( $last_manual_save ) && ! empty( $last_manual_save['at_label'] ) ) ? (string) $last_manual_save['at_label'] : '';
        $last_user        = ( is_array( $last_manual_save ) && ! empty( $last_manual_save['by'] ) ) ? (string) $last_manual_save['by'] : '';

        $job_status         = is_array( $job ) ? (string) ( $job['status'] ?? '' ) : '';
        $push_is_locked     = in_array( $job_status, array( 'pending_review', 'queued' ), true );
        $push_disabled_attr = ( $gmb_connected && ! $push_is_locked ) ? '' : 'disabled';

        ob_start();
        ?>
        <div id="oy-contact-push-panel"
             data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
             data-push-nonce="<?php echo esc_attr( $push_nonce ); ?>"
             data-check-nonce="<?php echo esc_attr( $check_nonce ); ?>"
             style="border:1px solid #dadce0;border-radius:4px;background:#fff;margin-bottom:16px;overflow:hidden;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:10px 14px;background:#f6f7f7;border-bottom:1px solid #dadce0;">
                <span style="font-size:13px;font-weight:600;color:#1d2327;">📤 <?php _e( 'Publicar información de contacto en Google Business Profile', 'lealez' ); ?></span>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <button type="button" id="oy-push-contact-btn" class="button button-primary" <?php echo $push_disabled_attr; ?> style="display:inline-flex;align-items:center;gap:6px;">
                        <span class="dashicons dashicons-upload" style="margin-top:3px;"></span>
                        <?php _e( 'Enviar a GMB', 'lealez' ); ?>
                    </button>
                    <?php if ( ! empty( $job ) && is_array( $job ) && ! in_array( $job['status'] ?? '', array( 'applied', 'rejected', 'google_override', 'timeout', 'cancelled' ), true ) ) : ?>
                        <button type="button" id="oy-check-contact-push-status-btn" class="button button-secondary" style="display:inline-flex;align-items:center;gap:6px;">
                            <span class="dashicons dashicons-search" style="margin-top:3px;"></span>
                            <?php _e( 'Verificar estado', 'lealez' ); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( $local_pending ) : ?>
                <div style="padding:10px 14px;background:#eef4ff;border-bottom:1px solid #d7e3ff;">
                    <p style="margin:0;font-size:12px;color:#1d4ed8;font-weight:600;"><?php _e( 'Hay cambios locales guardados en este metabox pendientes por publicar en GMB.', 'lealez' ); ?></p>
                    <?php if ( $last_label ) : ?>
                        <p style="margin:4px 0 0;font-size:11px;color:#4b5563;">
                            <?php printf( esc_html__( 'Último guardado local: %s', 'lealez' ), esc_html( $last_label ) ); ?>
                            <?php if ( $last_user ) : ?>&nbsp;·&nbsp;<?php printf( esc_html__( 'por %s', 'lealez' ), esc_html( $last_user ) ); ?><?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( ! $gmb_connected ) : ?>
                <div style="padding:10px 14px;">
                    <p style="margin:0;font-size:12px;color:#999;font-style:italic;"><?php _e( 'Requiere empresa y ubicación GMB vinculadas para poder publicar.', 'lealez' ); ?></p>
                </div>
            <?php elseif ( ! empty( $job ) && is_array( $job ) ) :
                $status    = $job['status'] ?? 'unknown';
                $pushed_at = $job['pushed_at'] ?? '';
                $pushed_by = $job['pushed_by'] ?? '';
                $resolved  = $job['resolved_at'] ?? '';
                $poll_n    = $job['poll_count'] ?? 0;
                $warnings  = $job['warnings'] ?? array();
                $status_cfg = array(
                    'pending_review'  => array( '🕐', '#e07800', __( 'Pendiente de revisión por Google', 'lealez' ) ),
                    'queued'          => array( '🕐', '#e07800', __( 'En cola de envío', 'lealez' ) ),
                    'applied'         => array( '✅', '#166534', __( 'Cambio aplicado en Google', 'lealez' ) ),
                    'rejected'        => array( '❌', '#dc3232', __( 'Google no conservó el cambio enviado', 'lealez' ) ),
                    'google_override' => array( '⚠️', '#b45309', __( 'Google reemplazó el valor con sus propios datos', 'lealez' ) ),
                    'timeout'         => array( '⏳', '#6b7280', __( 'Sin respuesta de Google en 30 días', 'lealez' ) ),
                    'error'           => array( '🔴', '#dc3232', __( 'Error técnico al enviar', 'lealez' ) ),
                );
                $cfg = $status_cfg[ $status ] ?? array( '⚪', '#555', $status );
                ?>
                <div style="padding:10px 14px;">
                    <p style="margin:0 0 6px;font-size:13px;font-weight:600;color:<?php echo esc_attr( $cfg[1] ); ?>;"><?php echo esc_html( $cfg[0] . ' ' . $cfg[2] ); ?></p>
                    <p style="margin:0;font-size:11px;color:#666;">
                        <?php if ( $pushed_at ) : ?><?php printf( esc_html__( 'Enviado: %s por %s', 'lealez' ), esc_html( $pushed_at ), esc_html( $pushed_by ) ); ?><?php endif; ?>
                        <?php if ( $resolved ) : ?>&nbsp;·&nbsp;<?php printf( esc_html__( 'Resuelto: %s', 'lealez' ), esc_html( $resolved ) ); ?><?php endif; ?>
                        <?php if ( $poll_n ) : ?>&nbsp;·&nbsp;<?php printf( esc_html__( 'Verificaciones automáticas: %d', 'lealez' ), (int) $poll_n ); ?><?php endif; ?>
                    </p>
                    <?php if ( ! empty( $warnings ) ) : ?>
                        <details style="margin-top:6px;font-size:11px;color:#7a5c00;">
                            <summary><?php _e( 'El envío terminó con advertencias técnicas', 'lealez' ); ?></summary>
                            <pre style="white-space:pre-wrap;background:#fffbe5;border:1px solid #f0c000;padding:6px;border-radius:4px;"><?php echo esc_html( wp_json_encode( $warnings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
                        </details>
                    <?php endif; ?>
                    <?php if ( 'pending_review' === $status || 'queued' === $status ) : ?>
                        <p style="margin:6px 0 0;font-size:11px;color:#888;font-style:italic;"><?php _e( 'Google puede aplicar algunos cambios de contacto de inmediato y dejar otros en revisión. El sistema verificará automáticamente.', 'lealez' ); ?></p>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <div style="padding:10px 14px;">
                    <p style="margin:0;font-size:12px;color:#888;font-style:italic;"><?php _e( 'Ningún cambio enviado aún. Guarda los cambios del metabox y luego usa "Enviar a GMB".', 'lealez' ); ?></p>
                </div>
            <?php endif; ?>

            <div id="oy-contact-push-action-msg" style="padding:0 14px 10px;font-size:12px;min-height:0;display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Sincroniza Contacto desde GMB hacia Lealez.
     */
    public function ajax_sync_contact_from_gmb() {
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_sync_contact_gmb_' . $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido o post_id faltante.', 'lealez' ) ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos para editar esta ubicación.', 'lealez' ) ) );
        }

        $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        if ( ! $business_id || '' === trim( $location_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Esta ubicación no tiene empresa o ubicación GMB vinculada.', 'lealez' ) ) );
        }
        if ( ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'get_location_contact_snapshot' ) ) {
            wp_send_json_error( array( 'message' => __( 'Lealez_GMB_API::get_location_contact_snapshot no disponible. Actualiza el plugin.', 'lealez' ) ) );
        }

        $before  = $this->collect_local_contact_data( $post_id );
        $snapshot = Lealez_GMB_API::get_location_contact_snapshot( $business_id, $location_name );
        if ( is_wp_error( $snapshot ) ) {
            self::add_contact_log_entry( $post_id, 'sync_from_gmb', 'error', $snapshot->get_error_message(), array( 'error_code' => $snapshot->get_error_code() ) );
            wp_send_json_error( array(
                'message'  => $snapshot->get_error_message(),
                'log_html' => $this->render_contact_log_panel( $post_id ),
            ) );
        }

        $after = $this->map_gmb_contact_snapshot_to_meta( $post_id, $snapshot );
        $diff  = $this->build_contact_diff( $before, $after );
        $warnings = isset( $snapshot['_contact_aux_errors'] ) && is_array( $snapshot['_contact_aux_errors'] ) ? $snapshot['_contact_aux_errors'] : array();

        self::add_contact_log_entry(
            $post_id,
            'sync_from_gmb',
            empty( $warnings ) ? 'success' : 'warning',
            sprintf( __( 'Sincronización desde GMB completada. %d campo(s) cambiaron.', 'lealez' ), count( $diff ) ),
            array(
                'diff'     => $diff,
                'warnings' => $warnings,
            )
        );

        wp_send_json_success( array(
            'message'       => sprintf( __( 'Sincronización completada. %d campo(s) actualizados.', 'lealez' ), count( $diff ) ),
            'field_values'  => $after,
            'diff'          => $diff,
            'warnings'      => $warnings,
            'log_html'      => $this->render_contact_log_panel( $post_id ),
            'push_html'     => $this->render_contact_push_panel( $post_id ),
        ) );
    }

    /**
     * AJAX: Limpia el log de Contacto.
     */
    public function ajax_clear_contact_log() {
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_clear_contact_log_' . $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
        }
        delete_post_meta( $post_id, 'gmb_contact_sync_log' );
        wp_send_json_success( array( 'log_html' => $this->render_contact_log_panel( $post_id ) ) );
    }

    /**
     * AJAX: Envía Contacto a GMB.
     */
    public function ajax_push_contact_to_gmb() {
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_push_contact_gmb_' . $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido o post_id faltante.', 'lealez' ) ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos para editar esta ubicación.', 'lealez' ) ) );
        }

        $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        if ( ! $business_id || '' === trim( $location_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Esta ubicación no tiene empresa o ubicación GMB vinculada.', 'lealez' ) ) );
        }
        if ( ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'push_location_contact' ) ) {
            wp_send_json_error( array( 'message' => __( 'Lealez_GMB_API::push_location_contact no disponible. Actualiza el plugin.', 'lealez' ) ) );
        }

        $snapshot = Lealez_GMB_API::get_location_contact_snapshot( $business_id, $location_name );
        if ( is_wp_error( $snapshot ) ) {
            self::add_contact_log_entry( $post_id, 'push_to_gmb', 'error', $snapshot->get_error_message(), array( 'error_code' => $snapshot->get_error_code() ) );
            wp_send_json_error( array(
                'message'  => sprintf( __( 'No se pudo obtener estado actual de GMB: %s', 'lealez' ), $snapshot->get_error_message() ),
                'log_html' => $this->render_contact_log_panel( $post_id ),
            ) );
        }

        $current_job = get_post_meta( $post_id, 'gmb_contact_push_job', true );
        $local_resolved = is_array( $current_job ) && in_array( $current_job['status'] ?? '', array( 'applied', 'rejected', 'google_override', 'timeout', 'error', 'cancelled' ), true );
        if ( ! empty( $snapshot['metadata']['hasPendingEdits'] ) && ! $local_resolved ) {
            wp_send_json_error( array(
                'message'    => __( 'Google tiene un cambio de contacto en revisión. No se puede enviar otro hasta verificar o resolver el estado actual.', 'lealez' ),
                'panel_html' => $this->render_contact_push_panel( $post_id ),
            ) );
        }

        $submitted = $this->collect_local_contact_data( $post_id );
        $result    = Lealez_GMB_API::push_location_contact( $business_id, $location_name, $submitted );

        if ( is_wp_error( $result ) ) {
            $err_data = $result->get_error_data();
            self::add_contact_log_entry( $post_id, 'push_to_gmb', 'error', $result->get_error_message(), array( 'error_code' => $result->get_error_code(), 'error_data' => $err_data ) );
            $response = array(
                'message'    => $result->get_error_message(),
                'log_html'   => $this->render_contact_log_panel( $post_id ),
                'panel_html' => $this->render_contact_push_panel( $post_id ),
            );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && is_array( $err_data ) && ! empty( $err_data['raw_body'] ) ) {
                $response['debug_raw'] = substr( (string) $err_data['raw_body'], 0, 500 );
            }
            wp_send_json_error( $response );
        }

        $now_ts       = time();
        $now_iso      = gmdate( 'Y-m-d\\TH:i:s\\Z', $now_ts );
        $current_user = wp_get_current_user();
        $user_login   = ( $current_user instanceof WP_User && $current_user->user_login ) ? $current_user->user_login : 'system';
        $warnings     = isset( $result['warnings'] ) && is_array( $result['warnings'] ) ? $result['warnings'] : array();

        $job = array(
            'status'          => 'pending_review',
            'pushed_at'       => $now_iso,
            'pushed_at_ts'    => $now_ts,
            'pushed_by'       => $user_login,
            'update_mask'     => $result['update_mask'] ?? '',
            'submitted'       => $submitted,
            'snapshot_before' => self::extract_gmb_contact_data_from_snapshot( $snapshot ),
            'warnings'        => $warnings,
            'poll_count'      => 0,
            'next_poll_at'    => $now_ts + 60,
            'resolved_at'     => null,
            'history'         => array(
                array(
                    'event'  => 'push_sent',
                    'at'     => $now_iso,
                    'at_ts'  => $now_ts,
                    'by'     => $user_login,
                    'detail' => 'PATCH enviado a GMB. updateMask=' . ( $result['update_mask'] ?? '' ) . ' | warnings=' . count( $warnings ),
                ),
            ),
        );

        update_post_meta( $post_id, 'gmb_contact_push_job', $job );
        wp_schedule_single_event( $now_ts + 60, 'oy_poll_contact_push_status', array( $post_id ) );

        self::add_contact_log_entry(
            $post_id,
            'push_to_gmb',
            empty( $warnings ) ? 'success' : 'warning',
            empty( $warnings ) ? __( 'Información de contacto enviada a GMB. Pendiente de verificación.', 'lealez' ) : __( 'Información de contacto enviada a GMB con advertencias técnicas. Pendiente de verificación.', 'lealez' ),
            array(
                'submitted' => $submitted,
                'warnings'  => $warnings,
            )
        );

        wp_send_json_success( array(
            'message'    => __( 'Información de contacto enviada a Google Business Profile. El sistema verificará el estado automáticamente.', 'lealez' ),
            'panel_html' => $this->render_contact_push_panel( $post_id ),
            'log_html'   => $this->render_contact_log_panel( $post_id ),
            'warnings'   => $warnings,
        ) );
    }

    /**
     * AJAX: verifica estado del último push de Contacto.
     */
    public function ajax_check_contact_push_status() {
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_check_contact_push_status_' . $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lealez' ) ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'lealez' ) ) );
        }

        $job = get_post_meta( $post_id, 'gmb_contact_push_job', true );
        if ( empty( $job ) || ! is_array( $job ) ) {
            wp_send_json_error( array( 'message' => __( 'No hay push registrado para esta ubicación.', 'lealez' ) ) );
        }

        if ( in_array( $job['status'] ?? '', array( 'applied', 'rejected', 'google_override', 'timeout', 'cancelled' ), true ) ) {
            wp_send_json_success( array(
                'message'    => __( 'El cambio ya está resuelto.', 'lealez' ),
                'status'     => $job['status'],
                'panel_html' => $this->render_contact_push_panel( $post_id ),
                'log_html'   => $this->render_contact_log_panel( $post_id ),
            ) );
        }

        $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        $current       = Lealez_GMB_API::poll_location_contact_status( $business_id, $location_name );
        if ( is_wp_error( $current ) ) {
            wp_send_json_error( array(
                'message'    => __( 'Error al consultar GMB: ', 'lealez' ) . $current->get_error_message(),
                'panel_html' => $this->render_contact_push_panel( $post_id ),
                'log_html'   => $this->render_contact_log_panel( $post_id ),
            ) );
        }

        $now_ts     = time();
        $now_iso    = gmdate( 'Y-m-d\\TH:i:s\\Z', $now_ts );
        $new_status = self::determine_contact_push_outcome( $job, self::extract_gmb_contact_data_from_snapshot( $current ), $current );

        $job['status']     = $new_status;
        $job['poll_count'] = ( $job['poll_count'] ?? 0 ) + 1;
        $job['history'][]  = array(
            'event'  => 'manual_check',
            'at'     => $now_iso,
            'at_ts'  => $now_ts,
            'by'     => wp_get_current_user()->user_login ?? 'system',
            'detail' => 'Verificación manual → estado: ' . $new_status,
        );

        if ( in_array( $new_status, array( 'applied', 'rejected', 'google_override' ), true ) ) {
            $job['resolved_at'] = $now_iso;
        }
        update_post_meta( $post_id, 'gmb_contact_push_job', $job );
        if ( 'applied' === $new_status ) {
            delete_post_meta( $post_id, 'oy_contact_local_pending_publish' );
        }

        self::add_contact_log_entry( $post_id, 'check_push_status', 'info', 'Verificación manual de contacto → ' . $new_status, array( 'status' => $new_status ) );

        wp_send_json_success( array(
            'message'    => '',
            'status'     => $new_status,
            'panel_html' => $this->render_contact_push_panel( $post_id ),
            'log_html'   => $this->render_contact_log_panel( $post_id ),
        ) );
    }

    /**
     * WP-Cron: ciclo automático de polling post-PATCH de Contacto.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public static function cron_poll_contact_push_status( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        $job = get_post_meta( $post_id, 'gmb_contact_push_job', true );
        if ( empty( $job ) || ! is_array( $job ) || ! in_array( $job['status'] ?? '', array( 'pending_review', 'queued' ), true ) ) {
            return;
        }

        $pushed_ts = $job['pushed_at_ts'] ?? 0;
        if ( $pushed_ts && ( time() - $pushed_ts ) > 30 * DAY_IN_SECONDS ) {
            $job['status']      = 'timeout';
            $job['resolved_at'] = gmdate( 'Y-m-d\\TH:i:s\\Z' );
            $job['history'][]   = array(
                'event'  => 'timeout',
                'at'     => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
                'at_ts'  => time(),
                'by'     => 'cron',
                'detail' => 'Sin respuesta de Google en 30 días.',
            );
            update_post_meta( $post_id, 'gmb_contact_push_job', $job );
            self::add_contact_log_entry( $post_id, 'cron_poll', 'warning', 'Timeout del push de contacto después de 30 días.', array( 'status' => 'timeout' ) );
            return;
        }

        $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        if ( ! $business_id || ! $location_name || ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'poll_location_contact_status' ) ) {
            return;
        }

        $current = Lealez_GMB_API::poll_location_contact_status( $business_id, $location_name );
        $now_ts  = time();
        $now_iso = gmdate( 'Y-m-d\\TH:i:s\\Z', $now_ts );

        if ( is_wp_error( $current ) ) {
            $job['poll_count'] = ( $job['poll_count'] ?? 0 ) + 1;
            $job['history'][]  = array(
                'event'  => 'poll_error',
                'at'     => $now_iso,
                'at_ts'  => $now_ts,
                'by'     => 'cron',
                'detail' => 'Error API: ' . $current->get_error_message(),
            );
            update_post_meta( $post_id, 'gmb_contact_push_job', $job );
            self::schedule_next_contact_poll( $post_id, $job['poll_count'] ?? 0 );
            return;
        }
        $new_status = self::determine_contact_push_outcome( $job, self::extract_gmb_contact_data_from_snapshot( $current ), $current );

        $job['poll_count'] = ( $job['poll_count'] ?? 0 ) + 1;
        $job['status']     = $new_status;
        $job['history'][]  = array(
            'event'  => 'cron_poll',
            'at'     => $now_iso,
            'at_ts'  => $now_ts,
            'by'     => 'cron',
            'detail' => 'Poll #' . $job['poll_count'] . ' → estado: ' . $new_status,
        );

        if ( in_array( $new_status, array( 'applied', 'rejected', 'google_override' ), true ) ) {
            $job['resolved_at'] = $now_iso;
        }
        update_post_meta( $post_id, 'gmb_contact_push_job', $job );

        if ( 'applied' === $new_status ) {
            delete_post_meta( $post_id, 'oy_contact_local_pending_publish' );
        }

        self::add_contact_log_entry( $post_id, 'cron_poll', 'info', 'Verificación automática de contacto → ' . $new_status, array( 'status' => $new_status ) );

        if ( 'pending_review' === $new_status ) {
            self::schedule_next_contact_poll( $post_id, $job['poll_count'] );
        }
    }

    /**
     * Programa siguiente polling de contacto.
     *
     * @param int $post_id Post ID.
     * @param int $poll_count Cantidad de polls ejecutados.
     * @return void
     */
    private static function schedule_next_contact_poll( $post_id, $poll_count ) {
        $intervals = array( 60, 120, 300, 600, 900, 1800, 3600, 7200, 21600 );
        $idx       = (int) $poll_count;
        $delay     = isset( $intervals[ $idx ] ) ? $intervals[ $idx ] : HOUR_IN_SECONDS;
        wp_schedule_single_event( time() + $delay, 'oy_poll_contact_push_status', array( absint( $post_id ) ) );
    }


    /**
     * Prepara datos de contacto para comparar resultado GMB sin falsos negativos.
     *
     * Ignora campos decorativos de UI como labels/from_gmb en URLs dinámicas y compara
     * únicamente los valores que realmente se publican en Google.
     *
     * @param array $data Datos de contacto.
     * @return mixed
     */
    private static function normalize_contact_compare_payload( array $data ) {
        unset( $data['email'] );

        foreach ( array( 'bookingUrls', 'orderUrls' ) as $url_key ) {
            $clean = array();
            $rows  = isset( $data[ $url_key ] ) && is_array( $data[ $url_key ] ) ? $data[ $url_key ] : array();
            foreach ( $rows as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $url = isset( $entry['url'] ) ? esc_url_raw( (string) $entry['url'] ) : '';
                if ( '' === $url ) {
                    continue;
                }
                $clean[] = array(
                    'url'  => $url,
                    'type' => isset( $entry['type'] ) ? strtoupper( sanitize_text_field( (string) $entry['type'] ) ) : '',
                );
            }
            $data[ $url_key ] = $clean;
        }

        if ( ! empty( $data['additionalPhones'] ) && is_array( $data['additionalPhones'] ) ) {
            $phones = array_values( array_filter( array_map( 'sanitize_text_field', $data['additionalPhones'] ) ) );
            sort( $phones );
            $data['additionalPhones'] = $phones;
        }

        if ( ! empty( $data['socialProfiles'] ) && is_array( $data['socialProfiles'] ) ) {
            $social = array();
            foreach ( $data['socialProfiles'] as $network => $url ) {
                $network = sanitize_key( (string) $network );
                $url     = esc_url_raw( (string) $url );
                if ( '' !== $network && '' !== $url ) {
                    $social[ $network ] = $url;
                }
            }
            ksort( $social );
            $data['socialProfiles'] = $social;
        }

        return self::normalize_for_compare( $data );
    }
    /**
     * Determina resultado del push de contacto.
     *
     * @param array $job Job guardado.
     * @param array $current_data Datos actuales parseados desde GMB.
     * @param array $raw_current Snapshot crudo.
     * @return string
     */
    private static function determine_contact_push_outcome( array $job, array $current_data, array $raw_current = array() ) {
        if ( ! empty( $raw_current['metadata']['hasPendingEdits'] ) ) {
            return 'pending_review';
        }

        $submitted = isset( $job['submitted'] ) && is_array( $job['submitted'] ) ? $job['submitted'] : array();
        $before    = isset( $job['snapshot_before'] ) && is_array( $job['snapshot_before'] ) ? $job['snapshot_before'] : array();

        $core_keys = array( 'primaryPhone', 'additionalPhones', 'websiteUri', 'chatUrl', 'menuUrl', 'socialProfiles', 'bookingUrls', 'orderUrls' );
        $submitted_core = array_intersect_key( $submitted, array_flip( $core_keys ) );
        $before_core    = array_intersect_key( $before, array_flip( $core_keys ) );
        $current_core   = array_intersect_key( $current_data, array_flip( $core_keys ) );

        if ( self::normalize_contact_compare_payload( $current_core ) === self::normalize_contact_compare_payload( $submitted_core ) ) {
            return 'applied';
        }
        if ( ! empty( $before_core ) && self::normalize_contact_compare_payload( $current_core ) === self::normalize_contact_compare_payload( $before_core ) ) {
            return 'rejected';
        }
        return 'google_override';
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
        echo $this->render_contact_sync_bar( $post->ID );
        echo $this->render_contact_log_panel( $post->ID );
        echo $this->render_contact_push_panel( $post->ID );

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

        <style>
            @keyframes oy-contact-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
            .oy-contact-is-loading .dashicons { animation: oy-contact-spin .85s linear infinite; }
        </style>
        <script type="text/javascript">
        jQuery(document).ready(function($){
            var contactNetworkOptions = '<?php
                $contact_opts = '';
                foreach ( $social_network_labels as $val => $lbl ) {
                    $contact_opts .= '<option value="' . esc_attr( $val ) . '">' . esc_html( $lbl ) . '</option>';
                }
                $contact_opts .= '<option value="other">' . esc_html__( 'Otra', 'lealez' ) . '</option>';
                echo esc_js( $contact_opts );
            ?>';

            function oyContactMessage($target, message, type) {
                var colors = {
                    success: '#166534',
                    warning: '#b45309',
                    error: '#dc3232',
                    info: '#1d4ed8'
                };
                $target.text(message || '').css('color', colors[type] || '#555').show();
            }

            function oyContactEscape(value) {
                return $('<div/>').text(value || '').html();
            }

            function oyContactSetLoading($button, isLoading, loadingText) {
                if (! $button || ! $button.length) {
                    return;
                }
                if (isLoading) {
                    $button.data('original-html', $button.html());
                    $button.prop('disabled', true).addClass('oy-contact-is-loading');
                    if (loadingText) {
                        $button.html('<span class="dashicons dashicons-update" style="margin-top:3px;"></span> ' + loadingText);
                    }
                } else {
                    var original = $button.data('original-html');
                    if (original) {
                        $button.html(original);
                    }
                    $button.prop('disabled', false).removeClass('oy-contact-is-loading');
                }
            }

            function oyContactReplacePanels(responseData) {
                if (responseData && responseData.log_html && $('#oy-contact-log-panel').length) {
                    $('#oy-contact-log-panel').replaceWith(responseData.log_html);
                }
                if (responseData && (responseData.push_html || responseData.panel_html) && $('#oy-contact-push-panel').length) {
                    $('#oy-contact-push-panel').replaceWith(responseData.push_html || responseData.panel_html);
                }
            }

            function oyContactRebuildPhones(phones) {
                var $list = $('#oy-additional-phones-list');
                $list.empty();
                if (! $.isArray(phones)) {
                    return;
                }
                phones.forEach(function(phone){
                    if (! phone) {
                        return;
                    }
                    $list.append(
                        '<div class="oy-phone-row" style="display:flex;gap:6px;margin-bottom:6px;align-items:center;">' +
                            '<input type="tel" name="gmb_phone_additional_list[]" value="' + oyContactEscape(phone) + '" class="regular-text" placeholder="+573001234567">' +
                            '<button type="button" class="button button-small oy-remove-phone" style="color:#dc3232;">✕</button>' +
                        '</div>'
                    );
                });
            }

            function oyContactRebuildUrlRows(selector, inputName, rowClass, removeClass, entries) {
                var $list = $(selector);
                $list.empty();
                if (! $.isArray(entries)) {
                    return;
                }
                entries.forEach(function(entry, idx){
                    if (! entry || ! entry.url) {
                        return;
                    }
                    $list.append(
                        '<div class="' + rowClass + '" style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">' +
                            '<input type="url" name="' + inputName + '[' + idx + '][url]" value="' + oyContactEscape(entry.url) + '" class="large-text" placeholder="https://..." style="flex:1;min-width:250px;">' +
                            '<input type="text" name="' + inputName + '[' + idx + '][label]" value="' + oyContactEscape(entry.label || '') + '" class="regular-text" placeholder="Etiqueta" style="max-width:180px;">' +
                            '<input type="hidden" name="' + inputName + '[' + idx + '][type]" value="' + oyContactEscape(entry.type || '') + '">' +
                            '<input type="hidden" name="' + inputName + '[' + idx + '][from_gmb]" value="' + (entry.from_gmb ? '1' : '0') + '">' +
                            '<button type="button" class="button button-small ' + removeClass + '" style="color:#dc3232;">✕</button>' +
                        '</div>'
                    );
                });
            }

            function oyContactRebuildSocials(socialProfiles) {
                var $list = $('#oy-social-profiles-list');
                $list.empty();
                if (! socialProfiles || typeof socialProfiles !== 'object') {
                    return;
                }
                Object.keys(socialProfiles).forEach(function(network){
                    var url = socialProfiles[network];
                    if (! url) {
                        return;
                    }
                    var $row = $('<div class="oy-social-row" style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">' +
                        '<select name="social_profiles_manual_network[]" class="oy-social-network-select" style="min-width:130px;">' + contactNetworkOptions + '</select>' +
                        '<input type="url" name="social_profiles_manual_url[]" class="large-text" placeholder="https://...">' +
                        '<button type="button" class="button button-small oy-remove-social" style="color:#dc3232;">✕</button>' +
                    '</div>');
                    $row.find('select').val(network);
                    if (! $row.find('select').val()) {
                        $row.find('select').val('other');
                    }
                    $row.find('input[type="url"]').val(url);
                    $list.append($row);
                });
            }

            function oyContactApplyFieldValues(values) {
                if (! values || typeof values !== 'object') {
                    return;
                }
                $('#location_phone').val(values.primaryPhone || '').trigger('change');
                $('#location_website').val(values.websiteUri || '').trigger('change');
                $('#location_chat_url').val(values.chatUrl || '').trigger('change');
                $('#location_menu_url_gmb').val(values.menuUrl || '').trigger('change');
                if (typeof values.email !== 'undefined') {
                    $('#location_email').val(values.email || '').trigger('change');
                }
                oyContactRebuildPhones(values.additionalPhones || []);
                oyContactRebuildUrlRows('#oy-booking-urls-list', 'location_booking_urls', 'oy-booking-url-row', 'oy-remove-booking-url', values.bookingUrls || []);
                oyContactRebuildUrlRows('#oy-order-urls-list', 'location_order_urls', 'oy-order-url-row', 'oy-remove-order-url', values.orderUrls || []);
                oyContactRebuildSocials(values.socialProfiles || {});
            }

            $(document).on('click', '#oy-contact-log-header', function(){
                var $body = $('#oy-contact-log-body');
                var $icon = $('#oy-contact-log-toggle-icon');
                $body.slideToggle(150, function(){
                    $icon.text($body.is(':visible') ? '▼' : '▶');
                });
            });

            $(document).on('click', '#oy-contact-log-clear', function(e){
                e.preventDefault();
                var $panel = $('#oy-contact-log-panel');
                var postId = $('#oy-contact-sync-bar').data('post-id') || $('#oy-contact-push-panel').data('post-id');
                var nonce = $panel.data('clear-nonce');
                if (! postId || ! nonce) {
                    return;
                }
                $.post(ajaxurl, {
                    action: 'oy_clear_contact_log',
                    post_id: postId,
                    nonce: nonce
                }).done(function(resp){
                    if (resp && resp.success) {
                        oyContactReplacePanels(resp.data || {});
                    } else {
                        alert((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'No se pudo limpiar el log.', 'lealez' ) ); ?>');
                    }
                });
            });

            $(document).on('click', '#oy-contact-sync-btn', function(e){
                e.preventDefault();
                var $button = $(this);
                var $bar = $('#oy-contact-sync-bar');
                var $msg = $('#oy-contact-sync-msg');
                var postId = $bar.data('post-id');
                var nonce = $bar.data('sync-nonce');
                oyContactSetLoading($button, true, '<?php echo esc_js( __( 'Sincronizando...', 'lealez' ) ); ?>');
                oyContactMessage($msg, '<?php echo esc_js( __( 'Consultando Google Business Profile...', 'lealez' ) ); ?>', 'info');
                $.post(ajaxurl, {
                    action: 'oy_sync_contact_from_gmb',
                    post_id: postId,
                    nonce: nonce
                }).done(function(resp){
                    if (resp && resp.success) {
                        oyContactApplyFieldValues(resp.data.field_values || {});
                        oyContactReplacePanels(resp.data || {});
                        oyContactMessage($('#oy-contact-sync-msg'), resp.data.message || '<?php echo esc_js( __( 'Sincronización completada.', 'lealez' ) ); ?>', resp.data.warnings && resp.data.warnings.length ? 'warning' : 'success');
                    } else {
                        oyContactReplacePanels((resp && resp.data) || {});
                        oyContactMessage($('#oy-contact-sync-msg'), (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'No se pudo sincronizar desde GMB.', 'lealez' ) ); ?>', 'error');
                    }
                }).fail(function(xhr){
                    oyContactMessage($('#oy-contact-sync-msg'), '<?php echo esc_js( __( 'Error AJAX al sincronizar Contacto.', 'lealez' ) ); ?>' + ' HTTP ' + xhr.status, 'error');
                }).always(function(){
                    oyContactSetLoading($button, false);
                });
            });

            $(document).on('click', '#oy-push-contact-btn', function(e){
                e.preventDefault();
                var $button = $(this);
                var $panel = $('#oy-contact-push-panel');
                var $msg = $('#oy-contact-push-action-msg');
                var postId = $panel.data('post-id');
                var nonce = $panel.data('push-nonce');
                oyContactSetLoading($button, true, '<?php echo esc_js( __( 'Enviando...', 'lealez' ) ); ?>');
                oyContactMessage($msg, '<?php echo esc_js( __( 'Enviando información de contacto a GMB...', 'lealez' ) ); ?>', 'info');
                $.post(ajaxurl, {
                    action: 'oy_push_contact_to_gmb',
                    post_id: postId,
                    nonce: nonce
                }).done(function(resp){
                    if (resp && resp.success) {
                        oyContactReplacePanels(resp.data || {});
                        oyContactMessage($('#oy-contact-push-action-msg'), resp.data.message || '<?php echo esc_js( __( 'Envío completado.', 'lealez' ) ); ?>', resp.data.warnings && resp.data.warnings.length ? 'warning' : 'success');
                    } else {
                        oyContactReplacePanels((resp && resp.data) || {});
                        oyContactMessage($('#oy-contact-push-action-msg'), (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'No se pudo enviar Contacto a GMB.', 'lealez' ) ); ?>', 'error');
                    }
                }).fail(function(xhr){
                    oyContactMessage($('#oy-contact-push-action-msg'), '<?php echo esc_js( __( 'Error AJAX al enviar Contacto.', 'lealez' ) ); ?>' + ' HTTP ' + xhr.status, 'error');
                }).always(function(){
                    oyContactSetLoading($button, false);
                });
            });

            $(document).on('click', '#oy-check-contact-push-status-btn', function(e){
                e.preventDefault();
                var $button = $(this);
                var $panel = $('#oy-contact-push-panel');
                var $msg = $('#oy-contact-push-action-msg');
                var postId = $panel.data('post-id');
                var nonce = $panel.data('check-nonce');
                oyContactSetLoading($button, true, '<?php echo esc_js( __( 'Verificando...', 'lealez' ) ); ?>');
                oyContactMessage($msg, '<?php echo esc_js( __( 'Consultando estado actual en GMB...', 'lealez' ) ); ?>', 'info');
                $.post(ajaxurl, {
                    action: 'oy_check_contact_push_status',
                    post_id: postId,
                    nonce: nonce
                }).done(function(resp){
                    if (resp && resp.success) {
                        oyContactReplacePanels(resp.data || {});
                        var statusMsg = resp.data.status ? '<?php echo esc_js( __( 'Estado actual:', 'lealez' ) ); ?> ' + resp.data.status : '<?php echo esc_js( __( 'Verificación completada.', 'lealez' ) ); ?>';
                        oyContactMessage($('#oy-contact-push-action-msg'), resp.data.message || statusMsg, 'info');
                    } else {
                        oyContactReplacePanels((resp && resp.data) || {});
                        oyContactMessage($('#oy-contact-push-action-msg'), (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'No se pudo verificar el estado.', 'lealez' ) ); ?>', 'error');
                    }
                }).fail(function(xhr){
                    oyContactMessage($('#oy-contact-push-action-msg'), '<?php echo esc_js( __( 'Error AJAX al verificar Contacto.', 'lealez' ) ); ?>' + ' HTTP ' + xhr.status, 'error');
                }).always(function(){
                    oyContactSetLoading($button, false);
                });
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

        $contact_before = $this->collect_local_contact_data( $post_id );

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

        $contact_after = $this->collect_local_contact_data( $post_id );
        $contact_diff  = $this->build_contact_diff( $contact_before, $contact_after );

        if ( ! empty( $contact_diff ) ) {
            $current_user = wp_get_current_user();
            $user_login   = ( $current_user instanceof WP_User && ! empty( $current_user->user_login ) ) ? $current_user->user_login : 'system';
            $now_ts       = time();

            update_post_meta( $post_id, 'oy_contact_local_pending_publish', 1 );
            update_post_meta( $post_id, 'oy_contact_last_manual_save', array(
                'at'       => gmdate( 'Y-m-d\\TH:i:s\\Z', $now_ts ),
                'at_ts'    => $now_ts,
                'at_label' => self::contact_datetime_label( $now_ts ),
                'by'       => $user_login,
                'diff'     => $contact_diff,
            ) );

            self::add_contact_log_entry(
                $post_id,
                'local_save',
                'info',
                sprintf( __( 'Cambios locales guardados en Contacto. %d campo(s) cambiaron y quedan pendientes por publicar en GMB.', 'lealez' ), count( $contact_diff ) ),
                array( 'diff' => $contact_diff )
            );
        }
    }
}

endif;
