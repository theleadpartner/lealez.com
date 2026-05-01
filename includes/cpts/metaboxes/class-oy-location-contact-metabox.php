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

        // ── Flujo independiente de edición del metabox Contacto ───────────────────────
        add_action( 'wp_ajax_oy_save_contact_metabox', array( $this, 'ajax_save_contact_metabox' ) );

        // ── Sincronización individual desde GMB ───────────────────────────────────────
        add_action( 'wp_ajax_oy_sync_contact_from_gmb', array( $this, 'ajax_sync_contact_from_gmb' ) );

        // ── Push de contacto hacia GMB + verificación de estado ───────────────────────
        add_action( 'wp_ajax_oy_push_contact_to_gmb', array( $this, 'ajax_push_contact_to_gmb' ) );
        add_action( 'wp_ajax_oy_check_contact_push_status', array( $this, 'ajax_check_contact_push_status' ) );

        // ── Assets/footer del editor del metabox ──────────────────────────────────────
        add_action( 'admin_footer', array( $this, 'render_contact_editor_footer_assets' ) );

        // ── WP-Cron para polling post-PATCH ───────────────────────────────────────────
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
     * Normaliza una lista de teléfonos adicionales.
     *
     * @param mixed $raw_value Valor crudo.
     * @return array
     */
    private function normalize_phone_list( $raw_value ) {
        if ( ! is_array( $raw_value ) ) {
            return array();
        }

        $out = array();
        foreach ( $raw_value as $phone ) {
            $phone = trim( sanitize_text_field( (string) $phone ) );
            if ( '' === $phone ) {
                continue;
            }
            $out[] = $phone;
        }

        return array_values( array_unique( $out ) );
    }

    /**
     * Normaliza entradas URL dinámicas usadas por reservas y pedidos.
     *
     * @param mixed  $raw_value Valor crudo.
     * @param string $default_type Tipo GMB por defecto.
     * @param string $default_label Etiqueta por defecto.
     * @return array
     */
    private function normalize_url_entries( $raw_value, $default_type = '', $default_label = '' ) {
        if ( ! is_array( $raw_value ) ) {
            return array();
        }

        $out  = array();
        $seen = array();

        foreach ( $raw_value as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $url = isset( $entry['url'] ) ? esc_url_raw( (string) $entry['url'] ) : '';
            if ( '' === $url ) {
                continue;
            }

            $type  = isset( $entry['type'] ) ? strtoupper( sanitize_text_field( (string) $entry['type'] ) ) : '';
            $label = isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '';

            if ( '' === $type ) {
                $type = $default_type;
            }
            if ( '' === $label ) {
                $label = $default_label;
            }

            $key = strtolower( $type . '|' . $url );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;

            $out[] = array(
                'url'      => $url,
                'label'    => $label,
                'type'     => $type,
                'from_gmb' => ! empty( $entry['from_gmb'] ) ? 1 : 0,
            );
        }

        return array_values( $out );
    }

    /**
     * Normaliza perfiles sociales manuales.
     *
     * @param mixed $networks Redes.
     * @param mixed $urls URLs.
     * @return array
     */
    private function normalize_social_profiles_from_request( $networks, $urls ) {
        $networks = is_array( $networks ) ? $networks : array();
        $urls     = is_array( $urls ) ? $urls : array();
        $out      = array();

        foreach ( $networks as $idx => $network ) {
            $network = sanitize_key( wp_unslash( (string) $network ) );
            $url     = isset( $urls[ $idx ] ) ? esc_url_raw( wp_unslash( (string) $urls[ $idx ] ) ) : '';

            if ( '' === $network || '' === $url ) {
                continue;
            }
            $out[ $network ] = $url;
        }

        return $out;
    }

    /**
     * Normaliza el tipo del campo Usuario de chat.
     *
     * @param string $chat_type Tipo recibido.
     * @param string $chat_value Valor actual.
     * @return string whatsapp|sms
     */
    private function normalize_chat_type( $chat_type, $chat_value = '' ) {
        if ( class_exists( 'Lealez_GMB_API' ) && method_exists( 'Lealez_GMB_API', 'normalize_contact_chat_type' ) ) {
            return Lealez_GMB_API::normalize_contact_chat_type( $chat_type, $chat_value );
        }

        $chat_type  = strtolower( sanitize_key( (string) $chat_type ) );
        $chat_value = trim( (string) $chat_value );
        if ( in_array( $chat_type, array( 'whatsapp', 'sms' ), true ) ) {
            return $chat_type;
        }
        if ( preg_match( '/^\+?[0-9][0-9\s\-\.\(\)]{6,}$/', $chat_value ) || 0 === strpos( strtolower( $chat_value ), 'sms:' ) ) {
            return 'sms';
        }
        return 'whatsapp';
    }

    /**
     * Normaliza país del campo SMS.
     *
     * @param string $country País.
     * @return string
     */
    private function normalize_chat_country( $country = 'CO' ) {
        if ( class_exists( 'Lealez_GMB_API' ) && method_exists( 'Lealez_GMB_API', 'normalize_contact_chat_country' ) ) {
            return Lealez_GMB_API::normalize_contact_chat_country( $country );
        }

        $country = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $country ), 0, 2 ) );
        return $country ? $country : 'CO';
    }

    /**
     * Normaliza el valor de Usuario de chat para guardarlo en Lealez.
     * WhatsApp se guarda como URL wa.me; SMS se guarda como número internacional.
     *
     * @param string $chat_type Tipo.
     * @param string $chat_value Valor.
     * @param string $country País.
     * @param string $primary_phone Teléfono principal.
     * @param array  $additional_phones Teléfonos adicionales.
     * @return string
     */
    private function normalize_chat_value_for_storage( $chat_type, $chat_value, $country = 'CO', $primary_phone = '', array $additional_phones = array() ) {
        $chat_type  = $this->normalize_chat_type( $chat_type, $chat_value );
        $country    = $this->normalize_chat_country( $country );
        $chat_value = trim( (string) $chat_value );

        if ( class_exists( 'Lealez_GMB_API' ) && method_exists( 'Lealez_GMB_API', 'normalize_contact_chat_value_for_google' ) ) {
            $normalized = Lealez_GMB_API::normalize_contact_chat_value_for_google( $chat_type, $chat_value, $country, $primary_phone, $additional_phones );
            if ( 'sms' === $chat_type ) {
                if ( class_exists( 'Lealez_GMB_API' ) && method_exists( 'Lealez_GMB_API', 'normalize_contact_sms_phone_for_profile' ) ) {
                    return Lealez_GMB_API::normalize_contact_sms_phone_for_profile( $normalized, $country );
                }
                return preg_replace( '/^sms:/i', '', (string) $normalized );
            }
            return esc_url_raw( (string) $normalized );
        }

        if ( 'sms' === $chat_type ) {
            $digits = preg_replace( '/\D+/', '', $chat_value );
            if ( $digits && 'CO' === $country && 0 !== strpos( $digits, '57' ) ) {
                $digits = '57' . ltrim( $digits, '0' );
            }
            return $digits ? '+' . $digits : sanitize_text_field( $chat_value );
        }

        return esc_url_raw( $chat_value );
    }

    /**
     * Normaliza varios canales de chat para guardar y enviar a GMB.
     *
     * @param mixed  $raw_channels Canales crudos.
     * @param string $primary_phone Teléfono principal.
     * @param array  $additional_phones Teléfonos adicionales.
     * @param string $default_country País por defecto.
     * @return array
     */
    private function normalize_chat_channels( $raw_channels, $primary_phone = '', array $additional_phones = array(), $default_country = 'CO' ) {
        $default_country = $this->normalize_chat_country( $default_country );

        if ( ! is_array( $raw_channels ) ) {
            $raw_channels = array();
        }

        // Compatibilidad: payload legacy en formato asociativo.
        $is_assoc = array_keys( $raw_channels ) !== range( 0, count( $raw_channels ) - 1 );
        if ( $is_assoc && ( isset( $raw_channels['type'] ) || isset( $raw_channels['value'] ) || isset( $raw_channels['url'] ) || isset( $raw_channels['location_chat_type'] ) || isset( $raw_channels['location_chat_url'] ) ) ) {
            $raw_channels = array( $raw_channels );
        }

        if ( class_exists( 'Lealez_GMB_API' ) && method_exists( 'Lealez_GMB_API', 'normalize_contact_chat_channels' ) ) {
            $normalized = Lealez_GMB_API::normalize_contact_chat_channels( $raw_channels, $primary_phone, $additional_phones, $default_country );
            return is_array( $normalized ) ? array_values( $normalized ) : array();
        }

        $out = array();
        foreach ( $raw_channels as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $value = '';
            if ( isset( $entry['value'] ) ) {
                $value = (string) $entry['value'];
            } elseif ( isset( $entry['url'] ) ) {
                $value = (string) $entry['url'];
            } elseif ( isset( $entry['uri'] ) ) {
                $value = (string) $entry['uri'];
            } elseif ( isset( $entry['location_chat_url'] ) ) {
                $value = (string) $entry['location_chat_url'];
            }
            $value = trim( $value );
            if ( '' === $value ) {
                continue;
            }

            $type = isset( $entry['type'] ) ? (string) $entry['type'] : ( isset( $entry['location_chat_type'] ) ? (string) $entry['location_chat_type'] : '' );
            $country = isset( $entry['country'] ) ? (string) $entry['country'] : ( isset( $entry['location_chat_country'] ) ? (string) $entry['location_chat_country'] : $default_country );
            $type = $this->normalize_chat_type( $type, $value );
            $country = $this->normalize_chat_country( $country );
            $stored_value = $this->normalize_chat_value_for_storage( $type, $value, $country, $primary_phone, $additional_phones );
            if ( '' === trim( (string) $stored_value ) ) {
                continue;
            }

            $out[ $type ] = array(
                'type'         => $type,
                'country'      => $country,
                'value'        => $stored_value,
                'api_uri'      => 'sms' === $type ? 'sms:' . $stored_value : $stored_value,
                'attribute_id' => 'sms' === $type ? 'url_text_messaging' : 'url_whatsapp',
            );
        }

        $ordered = array();
        foreach ( array( 'whatsapp', 'sms' ) as $type ) {
            if ( isset( $out[ $type ] ) ) {
                $ordered[] = $out[ $type ];
            }
        }

        return $ordered;
    }

    /**
     * Extrae todos los canales de chat desde atributos GMB.
     *
     * @param array $attributes Atributos GMB.
     * @return array
     */
    private function extract_chat_payload_from_attributes( array $attributes ) {
        $raw_channels = array();

        foreach ( $attributes as $attr ) {
            if ( ! is_array( $attr ) ) {
                continue;
            }

            $attr_id = '';
            if ( ! empty( $attr['attributeId'] ) ) {
                $attr_id = strtolower( trim( (string) $attr['attributeId'] ) );
            } elseif ( ! empty( $attr['name'] ) ) {
                $parts   = explode( '/attributes/', (string) $attr['name'], 2 );
                $attr_id = strtolower( trim( end( $parts ), '/' ) );
            }

            if ( ! in_array( $attr_id, array( 'url_whatsapp', 'url_text_messaging' ), true ) ) {
                continue;
            }

            if ( empty( $attr['uriValues'] ) || ! is_array( $attr['uriValues'] ) ) {
                continue;
            }

            foreach ( $attr['uriValues'] as $uri_value ) {
                if ( empty( $uri_value['uri'] ) ) {
                    continue;
                }
                $raw_channels[] = array(
                    'type'    => 'url_whatsapp' === $attr_id ? 'whatsapp' : 'sms',
                    'country' => 'CO',
                    'value'   => trim( (string) $uri_value['uri'] ),
                );
                // Business Profile usa un valor por atributo de chat. Si Google
                // devuelve varios, conservamos el primero para evitar duplicados.
                break;
            }
        }

        $channels = $this->normalize_chat_channels( $raw_channels, '', array(), 'CO' );
        $first    = ! empty( $channels[0] ) ? $channels[0] : array();

        return array(
            'type'     => isset( $first['type'] ) ? $first['type'] : '',
            'value'    => isset( $first['value'] ) ? $first['value'] : '',
            'country'  => isset( $first['country'] ) ? $first['country'] : 'CO',
            'raw'      => isset( $first['api_uri'] ) ? $first['api_uri'] : '',
            'channels' => $channels,
        );
    }

    /**
     * Construye el payload de Contacto desde POST/AJAX.
     *
     * @return array
     */
    private function build_contact_payload_from_request() {
        $booking_urls_raw = isset( $_POST['location_booking_urls'] ) && is_array( $_POST['location_booking_urls'] )
            ? wp_unslash( $_POST['location_booking_urls'] )
            : array();

        $order_urls_raw = isset( $_POST['location_order_urls'] ) && is_array( $_POST['location_order_urls'] )
            ? wp_unslash( $_POST['location_order_urls'] )
            : array();

        $additional_phones_raw = isset( $_POST['gmb_phone_additional_list'] ) && is_array( $_POST['gmb_phone_additional_list'] )
            ? array_map( 'wp_unslash', $_POST['gmb_phone_additional_list'] )
            : array();

        $primary_phone     = isset( $_POST['location_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['location_phone'] ) ) : '';
        $additional_phones = $this->normalize_phone_list( $additional_phones_raw );

        $chat_channels_raw = isset( $_POST['location_chat_channels'] ) && is_array( $_POST['location_chat_channels'] )
            ? wp_unslash( $_POST['location_chat_channels'] )
            : array();

        // Compatibilidad con versiones anteriores del metabox que enviaban un solo campo.
        if ( empty( $chat_channels_raw ) && ( isset( $_POST['location_chat_url'] ) || isset( $_POST['location_chat_type'] ) ) ) {
            $chat_channels_raw[] = array(
                'type'    => isset( $_POST['location_chat_type'] ) ? sanitize_text_field( wp_unslash( $_POST['location_chat_type'] ) ) : '',
                'country' => isset( $_POST['location_chat_country'] ) ? sanitize_text_field( wp_unslash( $_POST['location_chat_country'] ) ) : 'CO',
                'value'   => isset( $_POST['location_chat_url'] ) ? sanitize_text_field( wp_unslash( $_POST['location_chat_url'] ) ) : '',
            );
        }

        $chat_channels = $this->normalize_chat_channels( $chat_channels_raw, $primary_phone, $additional_phones, 'CO' );
        $primary_chat  = ! empty( $chat_channels[0] ) ? $chat_channels[0] : array();

        return array(
            'location_phone'             => $primary_phone,
            'gmb_phone_additional_list'  => $additional_phones,
            'location_chat_channels'     => $chat_channels,
            'location_chat_type'         => isset( $primary_chat['type'] ) ? $primary_chat['type'] : '',
            'location_chat_country'      => isset( $primary_chat['country'] ) ? $primary_chat['country'] : 'CO',
            'location_chat_url'          => isset( $primary_chat['value'] ) ? $primary_chat['value'] : '',
            'location_website'           => isset( $_POST['location_website'] ) ? esc_url_raw( wp_unslash( $_POST['location_website'] ) ) : '',
            'location_menu_url'          => isset( $_POST['location_menu_url_gmb'] ) ? esc_url_raw( wp_unslash( $_POST['location_menu_url_gmb'] ) ) : '',
            'location_booking_urls'      => $this->normalize_url_entries( $booking_urls_raw, 'APPOINTMENT', __( 'Reservas', 'lealez' ) ),
            'location_order_urls'        => $this->normalize_url_entries( $order_urls_raw, 'FOOD_ORDERING', __( 'Ordenar en línea', 'lealez' ) ),
            'social_profiles_manual'     => $this->normalize_social_profiles_from_request(
                isset( $_POST['social_profiles_manual_network'] ) ? $_POST['social_profiles_manual_network'] : array(),
                isset( $_POST['social_profiles_manual_url'] ) ? $_POST['social_profiles_manual_url'] : array()
            ),
        );
    }

    /**
     * Persiste el payload de contacto sin depender del botón Actualizar del CPT.
     *
     * @param int    $post_id Post ID.
     * @param array  $payload Datos normalizados.
     * @param string $save_source Fuente del guardado.
     * @param bool   $mark_pending Si debe marcar como pendiente por publicar.
     * @return array
     */
    private function persist_contact_payload( $post_id, array $payload, $save_source = 'manual_metabox_save', $mark_pending = true ) {
        $post_id     = absint( $post_id );
        $save_source = sanitize_key( (string) $save_source );

        if ( ! $post_id ) {
            return array();
        }

        $simple_fields = array(
            'location_phone',
            'location_chat_type',
            'location_chat_country',
            'location_chat_url',
            'location_website',
        );

        foreach ( $simple_fields as $meta_key ) {
            $value = isset( $payload[ $meta_key ] ) ? $payload[ $meta_key ] : '';
            if ( '' !== $value ) {
                update_post_meta( $post_id, $meta_key, $value );
            } else {
                delete_post_meta( $post_id, $meta_key );
            }
        }

        $chat_channels = isset( $payload['location_chat_channels'] ) && is_array( $payload['location_chat_channels'] )
            ? array_values( $payload['location_chat_channels'] )
            : array();
        if ( ! empty( $chat_channels ) ) {
            update_post_meta( $post_id, 'location_chat_channels', $chat_channels );

            $has_whatsapp_channel = false;
            foreach ( $chat_channels as $chat_channel ) {
                if ( isset( $chat_channel['type'], $chat_channel['value'] ) && 'whatsapp' === $chat_channel['type'] ) {
                    update_post_meta( $post_id, 'location_whatsapp', $chat_channel['value'] );
                    $has_whatsapp_channel = true;
                    break;
                }
            }

            if ( ! $has_whatsapp_channel ) {
                delete_post_meta( $post_id, 'location_whatsapp' );
            }
        } else {
            delete_post_meta( $post_id, 'location_chat_channels' );
            delete_post_meta( $post_id, 'location_whatsapp' );
        }

        // GMB no expone un campo de email en Business Profile; se elimina de Contacto para evitar datos que no sincronizan.
        delete_post_meta( $post_id, 'location_email' );

        $additional_phones = isset( $payload['gmb_phone_additional_list'] ) && is_array( $payload['gmb_phone_additional_list'] )
            ? array_values( $payload['gmb_phone_additional_list'] )
            : array();
        update_post_meta( $post_id, 'gmb_phone_additional_list', $additional_phones );
        if ( ! empty( $additional_phones ) ) {
            update_post_meta( $post_id, 'location_phone_additional', $additional_phones[0] );
        } else {
            delete_post_meta( $post_id, 'location_phone_additional' );
        }

        $booking_urls = isset( $payload['location_booking_urls'] ) && is_array( $payload['location_booking_urls'] ) ? array_values( $payload['location_booking_urls'] ) : array();
        update_post_meta( $post_id, 'location_booking_urls', $booking_urls );
        if ( ! empty( $booking_urls ) ) {
            update_post_meta( $post_id, 'location_booking_url', $booking_urls[0]['url'] );
        } else {
            delete_post_meta( $post_id, 'location_booking_url' );
        }

        $order_urls = isset( $payload['location_order_urls'] ) && is_array( $payload['location_order_urls'] ) ? array_values( $payload['location_order_urls'] ) : array();
        update_post_meta( $post_id, 'location_order_urls', $order_urls );
        if ( ! empty( $order_urls ) ) {
            update_post_meta( $post_id, 'location_order_url', $order_urls[0]['url'] );
        } else {
            delete_post_meta( $post_id, 'location_order_url' );
        }

        $menu_url = isset( $payload['location_menu_url'] ) ? esc_url_raw( (string) $payload['location_menu_url'] ) : '';
        if ( '' !== $menu_url ) {
            update_post_meta( $post_id, 'location_menu_url', $menu_url );
        } else {
            delete_post_meta( $post_id, 'location_menu_url' );
            delete_post_meta( $post_id, 'location_menu_url_from_gmb' );
        }

        $social_profiles = isset( $payload['social_profiles_manual'] ) && is_array( $payload['social_profiles_manual'] ) ? $payload['social_profiles_manual'] : array();
        update_post_meta( $post_id, 'social_profiles_manual', $social_profiles );
        if ( isset( $social_profiles['facebook'] ) ) {
            update_post_meta( $post_id, 'social_facebook_local', $social_profiles['facebook'] );
        }
        if ( isset( $social_profiles['instagram'] ) ) {
            update_post_meta( $post_id, 'social_instagram_local', $social_profiles['instagram'] );
        }

        $now_ts   = current_time( 'timestamp' );
        $user     = wp_get_current_user();
        $by       = ( $user instanceof WP_User && ! empty( $user->user_login ) ) ? $user->user_login : 'system';
        $at_label = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $now_ts );

        $save_meta = array(
            'at'       => gmdate( 'Y-m-d\\TH:i:s\\Z', $now_ts ),
            'at_ts'    => $now_ts,
            'at_label' => $at_label,
            'by'       => $by,
            'source'   => $save_source,
        );

        update_post_meta( $post_id, 'oy_contact_last_manual_save', $save_meta );
        update_post_meta( $post_id, 'date_modified', current_time( 'mysql' ) );
        update_post_meta( $post_id, 'modified_by_user_id', get_current_user_id() );

        if ( $mark_pending ) {
            update_post_meta( $post_id, 'oy_contact_local_pending_publish', '1' );
        } else {
            delete_post_meta( $post_id, 'oy_contact_local_pending_publish' );
        }

        $job = get_post_meta( $post_id, 'gmb_contact_push_job', true );
        if ( is_array( $job ) ) {
            if ( ! isset( $job['history'] ) || ! is_array( $job['history'] ) ) {
                $job['history'] = array();
            }
            $job['history'][] = array(
                'event'  => $mark_pending ? 'local_metabox_save' : 'sync_from_gmb',
                'at'     => $save_meta['at'],
                'at_ts'  => $save_meta['at_ts'],
                'by'     => $by,
                'detail' => $mark_pending ? 'Se guardaron cambios locales del metabox de contacto. Pendiente por publicar en GMB.' : 'Se sincronizó contacto desde GMB. Se limpia pendiente local.',
            );
            update_post_meta( $post_id, 'gmb_contact_push_job', $job );
        }

        return $save_meta;
    }

    /**
     * Convierte un valor de contacto en texto para el log visual.
     *
     * @param mixed $value Valor.
     * @return string
     */
    private function stringify_contact_value_for_log( $value ) {
        if ( is_array( $value ) ) {
            $parts = array();
            foreach ( $value as $key => $item ) {
                if ( is_array( $item ) ) {
                    if ( isset( $item['network'], $item['url'] ) ) {
                        $parts[] = sanitize_key( (string) $item['network'] ) . ': ' . esc_url_raw( (string) $item['url'] );
                    } elseif ( isset( $item['value'], $item['type'] ) ) {
                        $type    = strtoupper( sanitize_text_field( (string) $item['type'] ) );
                        $parts[] = ( $type ? $type . ': ' : '' ) . trim( (string) $item['value'] );
                    } elseif ( isset( $item['url'] ) ) {
                        $type    = isset( $item['type'] ) ? strtoupper( sanitize_text_field( (string) $item['type'] ) ) : '';
                        $parts[] = ( $type ? $type . ': ' : '' ) . esc_url_raw( (string) $item['url'] );
                    } else {
                        $parts[] = wp_json_encode( $item );
                    }
                } elseif ( is_string( $key ) && ! is_numeric( $key ) ) {
                    $parts[] = sanitize_key( (string) $key ) . ': ' . trim( (string) $item );
                } else {
                    $parts[] = trim( (string) $item );
                }
            }
            $parts = array_values( array_filter( $parts, static function( $item ) {
                return '' !== trim( (string) $item );
            } ) );
            sort( $parts );
            return implode( ', ', $parts );
        }

        return trim( (string) $value );
    }

    /**
     * Construye filas de diff de Contacto para el log, usando el estado actual de GMB y lo enviado desde Lealez.
     *
     * @param array $before_payload Payload antes.
     * @param array $after_payload  Payload después.
     * @return array
     */
    private function build_contact_diff_rows( array $before_payload, array $after_payload ) {
        $fields = array(
            'location_phone'            => __( 'Teléfono principal', 'lealez' ),
            'gmb_phone_additional_list' => __( 'Teléfonos adicionales', 'lealez' ),
            'location_chat_channels'    => __( 'Usuarios de chat', 'lealez' ),
            'location_website'          => __( 'Sitio Web', 'lealez' ),
            'location_menu_url'         => __( 'Vínculo del Menú / Servicios', 'lealez' ),
            'location_booking_urls'     => __( 'URLs de Reservas', 'lealez' ),
            'location_order_urls'       => __( 'URLs para Ordenar Online', 'lealez' ),
            'social_profiles_manual'    => __( 'Redes sociales', 'lealez' ),
        );

        $rows = array();
        foreach ( $fields as $key => $label ) {
            $before = $this->stringify_contact_value_for_log( $before_payload[ $key ] ?? '' );
            $after  = $this->stringify_contact_value_for_log( $after_payload[ $key ] ?? '' );

            if ( $before === $after ) {
                $status = 'unchanged';
            } elseif ( '' === $before && '' !== $after ) {
                $status = 'new';
            } elseif ( '' !== $before && '' === $after ) {
                $status = 'removed';
            } else {
                $status = 'changed';
            }

            $rows[] = array(
                'label'  => $label,
                'before' => $before,
                'after'  => $after,
                'status' => $status,
            );
        }

        return $rows;
    }

    /**
     * Guarda el metabox Contacto por AJAX.
     */
    public function ajax_save_contact_metabox() {
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_save_contact_metabox_' . $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido o post_id faltante.', 'lealez' ) ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'No tienes permisos para guardar esta ubicación.', 'lealez' ) ) );
        }
        $post = get_post( $post_id );
        if ( ! $post || 'oy_location' !== $post->post_type ) {
            wp_send_json_error( array( 'message' => __( 'El post indicado no es una ubicación válida.', 'lealez' ) ) );
        }

        $payload   = $this->build_contact_payload_from_request();
        $save_meta = $this->persist_contact_payload( $post_id, $payload, 'manual_metabox_save', true );

        wp_send_json_success( array(
            'message'               => __( 'Cambios del metabox guardados correctamente. Ya puedes usar "Enviar a GMB" y se publicará exactamente lo que acabas de guardar.', 'lealez' ),
            'saved_at'              => isset( $save_meta['at'] ) ? $save_meta['at'] : '',
            'saved_at_label'        => isset( $save_meta['at_label'] ) ? $save_meta['at_label'] : '',
            'saved_by'              => isset( $save_meta['by'] ) ? $save_meta['by'] : '',
            'panel_html'            => $this->render_contact_push_panel( $post_id ),
            'local_pending_publish' => true,
        ) );
    }

    /**
     * Devuelve el primer URI de un atributo GMB cuyo ID coincida con alguna llave.
     *
     * @param array $attributes Atributos GMB.
     * @param array $keys Llaves a buscar.
     * @return string
     */
    private function get_first_attribute_uri_by_keys( array $attributes, array $keys ) {
        $keys = array_map( 'strtolower', $keys );
        foreach ( $attributes as $attr ) {
            if ( ! is_array( $attr ) ) {
                continue;
            }
            $attr_id = '';
            if ( ! empty( $attr['attributeId'] ) ) {
                $attr_id = strtolower( trim( (string) $attr['attributeId'] ) );
            } elseif ( ! empty( $attr['name'] ) ) {
                $parts   = explode( '/attributes/', (string) $attr['name'], 2 );
                $attr_id = strtolower( trim( end( $parts ), '/' ) );
            }
            if ( '' === $attr_id ) {
                continue;
            }
            foreach ( $keys as $key ) {
                if ( $attr_id === $key || false !== strpos( $attr_id, $key ) ) {
                    if ( ! empty( $attr['uriValues'] ) && is_array( $attr['uriValues'] ) ) {
                        $uri = isset( $attr['uriValues'][0]['uri'] ) ? esc_url_raw( (string) $attr['uriValues'][0]['uri'] ) : '';
                        if ( $uri ) {
                            return $uri;
                        }
                    }
                }
            }
        }
        return '';
    }

    /**
     * Convierte links de Place Actions a payload local.
     *
     * @param array $links Links GMB.
     * @return array
     */
    private function map_place_action_links_to_contact_payload( array $links ) {
        $action_type_label_map = array(
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
        $booking_types = array( 'APPOINTMENT', 'ONLINE_APPOINTMENT', 'DINING_RESERVATION' );
        $order_types   = array( 'FOOD_ORDERING', 'FOOD_DELIVERY', 'FOOD_TAKEOUT', 'SHOP_ONLINE', 'ORDER_AHEAD', 'ORDER_FOOD' );
        $booking       = array();
        $order         = array();

        foreach ( $links as $link ) {
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
                'label'    => isset( $action_type_label_map[ $type ] ) ? $action_type_label_map[ $type ] : $type,
                'type'     => $type,
                'from_gmb' => 1,
            );
            if ( in_array( $type, $booking_types, true ) ) {
                $booking[] = $entry;
            } elseif ( in_array( $type, $order_types, true ) ) {
                $order[] = $entry;
            }
        }

        return array(
            'booking' => $booking,
            'order'   => $order,
        );
    }

    /**
     * Construye payload local desde snapshot GMB.
     *
     * @param array $snapshot Snapshot GMB.
     * @return array
     */
    private function build_contact_payload_from_gmb_snapshot( array $snapshot ) {
        $phone_numbers = isset( $snapshot['phoneNumbers'] ) && is_array( $snapshot['phoneNumbers'] ) ? $snapshot['phoneNumbers'] : array();
        $attributes    = isset( $snapshot['attributes'] ) && is_array( $snapshot['attributes'] ) ? $snapshot['attributes'] : array();
        $links         = isset( $snapshot['placeActionLinks'] ) && is_array( $snapshot['placeActionLinks'] ) ? $snapshot['placeActionLinks'] : array();
        $mapped_links  = $this->map_place_action_links_to_contact_payload( $links );

        $social_profiles = array();
        $social_attr_map  = array(
            'facebook'  => array( 'url_facebook' ),
            'instagram' => array( 'url_instagram' ),
            'twitter'   => array( 'url_twitter', 'url_x' ),
            'linkedin'  => array( 'url_linkedin' ),
            'youtube'   => array( 'url_youtube' ),
            'tiktok'    => array( 'url_tiktok' ),
            'pinterest' => array( 'url_pinterest' ),
        );
        foreach ( $social_attr_map as $network => $keys ) {
            $uri = $this->get_first_attribute_uri_by_keys( $attributes, $keys );
            if ( $uri ) {
                $social_profiles[ $network ] = $uri;
            }
        }

        $chat_payload = $this->extract_chat_payload_from_attributes( $attributes );

        return array(
            'location_phone'            => isset( $phone_numbers['primaryPhone'] ) ? sanitize_text_field( (string) $phone_numbers['primaryPhone'] ) : '',
            'gmb_phone_additional_list' => isset( $phone_numbers['additionalPhones'] ) && is_array( $phone_numbers['additionalPhones'] ) ? $this->normalize_phone_list( $phone_numbers['additionalPhones'] ) : array(),
            'location_chat_channels'    => isset( $chat_payload['channels'] ) && is_array( $chat_payload['channels'] ) ? $chat_payload['channels'] : array(),
            'location_chat_type'        => $chat_payload['type'] ? $chat_payload['type'] : '',
            'location_chat_country'     => $chat_payload['country'] ? $chat_payload['country'] : 'CO',
            'location_chat_url'         => $chat_payload['value'],
            'location_website'          => ! empty( $snapshot['websiteUri'] ) ? esc_url_raw( (string) $snapshot['websiteUri'] ) : '',
            'location_menu_url'         => $this->get_first_attribute_uri_by_keys( $attributes, array( 'url_menu', 'url_food_menu', 'menu_url' ) ),
            'location_booking_urls'     => $mapped_links['booking'],
            'location_order_urls'       => $mapped_links['order'],
            'social_profiles_manual'    => $social_profiles,
        );
    }

    /**
     * AJAX: sincroniza contacto desde GMB y lo persiste localmente.
     */
    public function ajax_sync_contact_from_gmb() {
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_sync_contact_from_gmb_' . $post_id ) ) {
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

        $snapshot = Lealez_GMB_API::get_location_contact_snapshot( $business_id, $location_name, true );
        if ( is_wp_error( $snapshot ) ) {
            wp_send_json_error( array( 'message' => $snapshot->get_error_message() ) );
        }

        $payload = $this->build_contact_payload_from_gmb_snapshot( $snapshot );
        $this->persist_contact_payload( $post_id, $payload, 'sync_from_gmb_button', false );

        update_post_meta( $post_id, 'gmb_contact_last_sync_snapshot', $snapshot );
        update_post_meta( $post_id, 'gmb_contact_last_sync_at', current_time( 'mysql' ) );

        wp_send_json_success( array(
            'message'     => __( 'Contacto sincronizado desde GMB y guardado localmente.', 'lealez' ),
            'snapshot'    => $snapshot,
            'payload'     => $payload,
            'panel_html'  => $this->render_contact_push_panel( $post_id ),
        ) );
    }

    /**
     * Payload de contacto actual en DB para enviar a GMB.
     *
     * @param int $post_id Post ID.
     * @return array
     */
    private function get_contact_payload_from_db( $post_id ) {
        $booking_urls = get_post_meta( $post_id, 'location_booking_urls', true );
        $order_urls   = get_post_meta( $post_id, 'location_order_urls', true );
        $social       = get_post_meta( $post_id, 'social_profiles_manual', true );

        $phone        = (string) get_post_meta( $post_id, 'location_phone', true );
        $extra_phones = $this->normalize_phone_list( get_post_meta( $post_id, 'gmb_phone_additional_list', true ) );
        $chat_channels_meta = get_post_meta( $post_id, 'location_chat_channels', true );

        if ( ! is_array( $chat_channels_meta ) || empty( $chat_channels_meta ) ) {
            $legacy_chat_value = (string) get_post_meta( $post_id, 'location_chat_url', true );
            if ( '' !== trim( $legacy_chat_value ) ) {
                $chat_channels_meta = array(
                    array(
                        'type'    => (string) get_post_meta( $post_id, 'location_chat_type', true ),
                        'country' => (string) get_post_meta( $post_id, 'location_chat_country', true ),
                        'value'   => $legacy_chat_value,
                    ),
                );
            } else {
                $chat_channels_meta = array();
            }
        }

        $chat_channels = $this->normalize_chat_channels( $chat_channels_meta, $phone, $extra_phones, 'CO' );
        $primary_chat  = ! empty( $chat_channels[0] ) ? $chat_channels[0] : array();

        return array(
            'location_phone'            => $phone,
            'gmb_phone_additional_list' => $extra_phones,
            'location_chat_channels'    => $chat_channels,
            'location_chat_type'        => isset( $primary_chat['type'] ) ? $primary_chat['type'] : '',
            'location_chat_country'     => isset( $primary_chat['country'] ) ? $primary_chat['country'] : 'CO',
            'location_chat_url'         => isset( $primary_chat['value'] ) ? $primary_chat['value'] : '',
            'location_website'          => (string) get_post_meta( $post_id, 'location_website', true ),
            'location_menu_url'         => (string) get_post_meta( $post_id, 'location_menu_url', true ),
            'location_booking_urls'     => is_array( $booking_urls ) ? $this->normalize_url_entries( $booking_urls, 'APPOINTMENT', __( 'Reservas', 'lealez' ) ) : array(),
            'location_order_urls'       => is_array( $order_urls ) ? $this->normalize_url_entries( $order_urls, 'FOOD_ORDERING', __( 'Ordenar en línea', 'lealez' ) ) : array(),
            'social_profiles_manual'    => is_array( $social ) ? $social : array(),
        );
    }

    /**
     * AJAX: envía Contacto a GMB.
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

        $snapshot = Lealez_GMB_API::get_location_contact_snapshot( $business_id, $location_name, true );
        if ( is_wp_error( $snapshot ) ) {
            wp_send_json_error( array( 'message' => sprintf( __( 'No se pudo obtener estado actual de GMB: %s', 'lealez' ), $snapshot->get_error_message() ) ) );
        }

        if ( ! empty( $snapshot['metadata']['hasPendingEdits'] ) ) {
            $current_job = get_post_meta( $post_id, 'gmb_contact_push_job', true );
            $local_resolved = is_array( $current_job ) && in_array( $current_job['status'] ?? '', array( 'applied', 'rejected', 'google_override', 'timeout', 'error' ), true );
            if ( ! $local_resolved ) {
                wp_send_json_error( array(
                    'message'    => __( 'Google tiene cambios en revisión para esta ubicación. No se recomienda enviar otro cambio hasta que se resuelva. Usa "Verificar estado".', 'lealez' ),
                    'panel_html' => $this->render_contact_push_panel( $post_id ),
                ) );
            }
        }

        $payload            = $this->get_contact_payload_from_db( $post_id );
        $gmb_payload_before = $this->build_contact_payload_from_gmb_snapshot( is_array( $snapshot ) ? $snapshot : array() );
        $diff_before_push   = $this->build_contact_diff_rows( $gmb_payload_before, $payload );
        $result             = Lealez_GMB_API::push_location_contact( $business_id, $location_name, $payload );
        if ( is_wp_error( $result ) ) {
            $err_data   = $result->get_error_data();
            $raw_preview = '';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $err_data['raw_body'] ) ) {
                $raw_preview = substr( (string) $err_data['raw_body'], 0, 500 );
            }
            $response_arr = array( 'message' => $result->get_error_message() );
            if ( $raw_preview ) {
                $response_arr['debug_raw'] = $raw_preview;
            }
            wp_send_json_error( $response_arr );
        }

        $current_user = wp_get_current_user();
        $user_login   = ( $current_user instanceof WP_User && $current_user->user_login ) ? $current_user->user_login : 'system';
        $now_ts       = time();
        $now_iso      = gmdate( 'Y-m-d\\TH:i:s\\Z', $now_ts );

        $job = array(
            'status'          => 'pending_review',
            'pushed_at'       => $now_iso,
            'pushed_at_ts'    => $now_ts,
            'pushed_by'       => $user_login,
            'update_mask'     => isset( $result['update_mask'] ) ? $result['update_mask'] : '',
            'attribute_mask'  => isset( $result['attribute_mask'] ) ? $result['attribute_mask'] : '',
            'submitted'       => isset( $result['submitted'] ) && is_array( $result['submitted'] ) ? $result['submitted'] : $payload,
            'snapshot_before' => $snapshot,
            'api_result'      => $result,
            'poll_count'      => 0,
            'next_poll_at'    => $now_ts + 60,
            'resolved_at'     => null,
            'history'         => array(
                array(
                    'event'  => 'push_sent',
                    'at'     => $now_iso,
                    'at_ts'  => $now_ts,
                    'by'     => $user_login,
                    'detail' => 'PATCH/PlaceAction/Attributes enviado a GMB. updateMask=' . ( isset( $result['update_mask'] ) ? $result['update_mask'] : '' ) . ' · attributeMask=' . ( isset( $result['attribute_mask'] ) ? $result['attribute_mask'] : '' ),
                ),
            ),
        );

        update_post_meta( $post_id, 'gmb_contact_push_job', $job );
        wp_schedule_single_event( $now_ts + 60, 'oy_poll_contact_push_status', array( $post_id ) );

        wp_send_json_success( array(
            'message'    => __( 'Cambios de contacto enviados a Google Business Profile. Estado: pendiente de revisión/verificación. El sistema chequeará automáticamente.', 'lealez' ),
            'status'          => 'pending_review',
            'panel_html'      => $this->render_contact_push_panel( $post_id ),
            'result'          => $result,
            'snapshot_before' => $snapshot,
            'submitted'       => isset( $result['submitted'] ) && is_array( $result['submitted'] ) ? $result['submitted'] : $payload,
            'diff'            => $diff_before_push,
        ) );
    }

    /**
     * AJAX: verifica estado del último push de contacto.
     */
    public function ajax_check_contact_push_status() {
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'oy_check_contact_push_status_' . $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido o post_id faltante.', 'lealez' ) ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos para editar esta ubicación.', 'lealez' ) ) );
        }

        $job = get_post_meta( $post_id, 'gmb_contact_push_job', true );
        if ( empty( $job ) || ! is_array( $job ) ) {
            wp_send_json_error( array( 'message' => __( 'No hay push de contacto registrado para esta ubicación.', 'lealez' ) ) );
        }
        if ( in_array( $job['status'] ?? '', array( 'applied', 'rejected', 'google_override', 'timeout', 'cancelled' ), true ) ) {
            wp_send_json_success( array(
                'status'     => $job['status'],
                'panel_html' => $this->render_contact_push_panel( $post_id ),
                'job'        => $job,
                'message'    => __( 'El cambio de contacto ya estaba resuelto localmente.', 'lealez' ),
            ) );
        }

        $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        $current = Lealez_GMB_API::poll_location_contact_status( $business_id, $location_name );
        if ( is_wp_error( $current ) ) {
            wp_send_json_error( array(
                'message'    => $current->get_error_message(),
                'panel_html' => $this->render_contact_push_panel( $post_id ),
            ) );
        }

        $new_status = self::determine_contact_push_outcome( $job, $current );
        $job['status']     = $new_status;
        $job['poll_count'] = isset( $job['poll_count'] ) ? absint( $job['poll_count'] ) + 1 : 1;
        $job['history'][] = array(
            'event'  => 'manual_check',
            'at'     => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
            'at_ts'  => time(),
            'by'     => wp_get_current_user()->user_login ?? 'system',
            'detail' => 'Verificación manual → estado: ' . $new_status,
        );
        if ( in_array( $new_status, array( 'applied', 'rejected', 'google_override' ), true ) ) {
            $job['resolved_at'] = gmdate( 'Y-m-d\\TH:i:s\\Z' );
        }
        update_post_meta( $post_id, 'gmb_contact_push_job', $job );
        if ( 'applied' === $new_status ) {
            delete_post_meta( $post_id, 'oy_contact_local_pending_publish' );
        }

        wp_send_json_success( array(
            'status'          => $new_status,
            'panel_html'      => $this->render_contact_push_panel( $post_id ),
            'job'             => $job,
            'current_snapshot'=> $current,
            'message'         => sprintf( __( 'Verificación ejecutada. Estado actual: %s', 'lealez' ), $new_status ),
        ) );
    }

    /**
     * Cron: verifica estado de push de contacto.
     *
     * @param int $post_id Post ID.
     */
    public static function cron_poll_contact_push_status( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id || 'oy_location' !== get_post_type( $post_id ) ) {
            return;
        }

        $job = get_post_meta( $post_id, 'gmb_contact_push_job', true );
        if ( empty( $job ) || ! is_array( $job ) ) {
            return;
        }
        if ( ! in_array( $job['status'] ?? '', array( 'pending_review', 'queued' ), true ) ) {
            return;
        }

        $pushed_ts = isset( $job['pushed_at_ts'] ) ? absint( $job['pushed_at_ts'] ) : 0;
        if ( $pushed_ts && ( time() - $pushed_ts ) > 30 * DAY_IN_SECONDS ) {
            $job['status']      = 'timeout';
            $job['resolved_at'] = gmdate( 'Y-m-d\\TH:i:s\\Z' );
            $job['history'][]   = array(
                'event'  => 'timeout',
                'at'     => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
                'at_ts'  => time(),
                'by'     => 'cron',
                'detail' => 'Se alcanzó el límite de 30 días sin confirmación de Google.',
            );
            update_post_meta( $post_id, 'gmb_contact_push_job', $job );
            return;
        }

        if ( ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'poll_location_contact_status' ) ) {
            return;
        }

        $business_id   = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        $current = Lealez_GMB_API::poll_location_contact_status( $business_id, $location_name );
        if ( is_wp_error( $current ) ) {
            self::schedule_next_poll( $post_id, isset( $job['poll_count'] ) ? absint( $job['poll_count'] ) + 1 : 1 );
            return;
        }

        $new_status        = self::determine_contact_push_outcome( $job, $current );
        $job['status']     = $new_status;
        $job['poll_count'] = isset( $job['poll_count'] ) ? absint( $job['poll_count'] ) + 1 : 1;
        $job['history'][]  = array(
            'event'  => 'cron_poll',
            'at'     => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
            'at_ts'  => time(),
            'by'     => 'cron',
            'detail' => 'Poll #' . $job['poll_count'] . ' → estado: ' . $new_status,
        );
        if ( in_array( $new_status, array( 'applied', 'rejected', 'google_override' ), true ) ) {
            $job['resolved_at'] = gmdate( 'Y-m-d\\TH:i:s\\Z' );
        }
        update_post_meta( $post_id, 'gmb_contact_push_job', $job );
        if ( 'applied' === $new_status ) {
            delete_post_meta( $post_id, 'oy_contact_local_pending_publish' );
        }
        if ( 'pending_review' === $new_status ) {
            self::schedule_next_poll( $post_id, $job['poll_count'] );
        }
    }

    /**
     * Programa siguiente polling.
     *
     * @param int $post_id Post ID.
     * @param int $poll_count Cantidad.
     */
    private static function schedule_next_poll( $post_id, $poll_count ) {
        $delays = array( 60, 120, 300, 600, 1800, 3600, 7200, 14400 );
        $idx    = min( max( 0, absint( $poll_count ) ), count( $delays ) - 1 );
        wp_schedule_single_event( time() + $delays[ $idx ], 'oy_poll_contact_push_status', array( absint( $post_id ) ) );
    }

    /**
     * Normaliza payload para comparación.
     *
     * @param array $payload Payload.
     * @return array
     */
    private static function normalize_contact_comparison_payload( array $payload ) {
        $norm_url_entries = static function( $items ) {
            $out = array();
            if ( ! is_array( $items ) ) {
                return $out;
            }
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $url  = isset( $item['url'] ) ? strtolower( trim( (string) $item['url'] ) ) : '';
                $type = isset( $item['type'] ) ? strtoupper( trim( (string) $item['type'] ) ) : '';
                if ( '' !== $url ) {
                    $out[] = $type . '|' . $url;
                }
            }
            $out = array_values( array_unique( $out ) );
            sort( $out );
            return $out;
        };

        $social = isset( $payload['social_profiles_manual'] ) && is_array( $payload['social_profiles_manual'] ) ? $payload['social_profiles_manual'] : array();
        ksort( $social );
        foreach ( $social as $k => $v ) {
            $social[ $k ] = strtolower( trim( (string) $v ) );
        }

        $phones = isset( $payload['gmb_phone_additional_list'] ) && is_array( $payload['gmb_phone_additional_list'] ) ? $payload['gmb_phone_additional_list'] : array();
        $phones = array_map( 'strval', $phones );
        sort( $phones );

        $chat_channels = array();
        if ( isset( $payload['location_chat_channels'] ) && is_array( $payload['location_chat_channels'] ) ) {
            $chat_channels = $payload['location_chat_channels'];
        } elseif ( ! empty( $payload['location_chat_url'] ) ) {
            $chat_channels = array(
                array(
                    'type'    => isset( $payload['location_chat_type'] ) ? (string) $payload['location_chat_type'] : '',
                    'country' => isset( $payload['location_chat_country'] ) ? (string) $payload['location_chat_country'] : 'CO',
                    'value'   => (string) $payload['location_chat_url'],
                ),
            );
        }

        if ( class_exists( 'Lealez_GMB_API' ) && method_exists( 'Lealez_GMB_API', 'normalize_contact_chat_channels' ) ) {
            $chat_channels = Lealez_GMB_API::normalize_contact_chat_channels( $chat_channels, '', array(), isset( $payload['location_chat_country'] ) ? (string) $payload['location_chat_country'] : 'CO' );
        }

        $chat_norm = array();
        if ( is_array( $chat_channels ) ) {
            foreach ( $chat_channels as $channel ) {
                if ( ! is_array( $channel ) ) {
                    continue;
                }
                $type    = isset( $channel['type'] ) ? strtolower( trim( (string) $channel['type'] ) ) : '';
                $country = isset( $channel['country'] ) ? strtoupper( trim( (string) $channel['country'] ) ) : 'CO';
                $value   = isset( $channel['api_uri'] ) ? (string) $channel['api_uri'] : ( isset( $channel['value'] ) ? (string) $channel['value'] : '' );
                if ( '' === $type || '' === trim( $value ) ) {
                    continue;
                }
                $chat_norm[] = $type . '|' . $country . '|' . strtolower( rtrim( trim( $value ), '/' ) );
            }
        }
        $chat_norm = array_values( array_unique( $chat_norm ) );
        sort( $chat_norm );

        return array(
            'primary_phone' => strtolower( trim( (string) ( $payload['location_phone'] ?? '' ) ) ),
            'phones'        => $phones,
            'chat_channels' => $chat_norm,
            'website'       => strtolower( trim( (string) ( $payload['location_website'] ?? '' ) ) ),
            'menu'          => strtolower( trim( (string) ( $payload['location_menu_url'] ?? '' ) ) ),
            'booking'       => $norm_url_entries( $payload['location_booking_urls'] ?? array() ),
            'order'         => $norm_url_entries( $payload['location_order_urls'] ?? array() ),
            'social'        => $social,
        );
    }

    /**
     * Determina estado del push de contacto.
     *
     * @param array $job Job.
     * @param array $current Snapshot actual.
     * @return string
     */
    private static function determine_contact_push_outcome( array $job, array $current ) {
        if ( ! empty( $current['metadata']['hasPendingEdits'] ) ) {
            return 'pending_review';
        }

        $get_first_uri = static function( array $attributes, array $keys ) {
            $keys = array_map( 'strtolower', $keys );
            foreach ( $attributes as $attr ) {
                if ( ! is_array( $attr ) ) {
                    continue;
                }
                $attr_id = '';
                if ( ! empty( $attr['attributeId'] ) ) {
                    $attr_id = strtolower( trim( (string) $attr['attributeId'] ) );
                } elseif ( ! empty( $attr['name'] ) ) {
                    $parts   = explode( '/attributes/', (string) $attr['name'], 2 );
                    $attr_id = strtolower( trim( end( $parts ), '/' ) );
                }
                foreach ( $keys as $key ) {
                    if ( $attr_id === $key || false !== strpos( $attr_id, $key ) ) {
                        if ( ! empty( $attr['uriValues'] ) && is_array( $attr['uriValues'] ) ) {
                            $uri = isset( $attr['uriValues'][0]['uri'] ) ? esc_url_raw( (string) $attr['uriValues'][0]['uri'] ) : '';
                            if ( $uri ) {
                                return $uri;
                            }
                        }
                    }
                }
            }
            return '';
        };

        $map_links = static function( array $links ) {
            $booking_types = array( 'APPOINTMENT', 'ONLINE_APPOINTMENT', 'DINING_RESERVATION' );
            $order_types   = array( 'FOOD_ORDERING', 'FOOD_DELIVERY', 'FOOD_TAKEOUT', 'SHOP_ONLINE', 'ORDER_AHEAD', 'ORDER_FOOD' );
            $booking       = array();
            $order         = array();
            foreach ( $links as $link ) {
                if ( ! is_array( $link ) ) {
                    continue;
                }
                $uri  = ! empty( $link['uri'] ) ? esc_url_raw( (string) $link['uri'] ) : '';
                $type = ! empty( $link['placeActionType'] ) ? strtoupper( trim( (string) $link['placeActionType'] ) ) : '';
                if ( '' === $uri || '' === $type ) {
                    continue;
                }
                $entry = array( 'url' => $uri, 'label' => $type, 'type' => $type, 'from_gmb' => 1 );
                if ( in_array( $type, $booking_types, true ) ) {
                    $booking[] = $entry;
                } elseif ( in_array( $type, $order_types, true ) ) {
                    $order[] = $entry;
                }
            }
            return array( 'booking' => $booking, 'order' => $order );
        };

        $submitted = self::normalize_contact_comparison_payload( isset( $job['submitted'] ) && is_array( $job['submitted'] ) ? $job['submitted'] : array() );

        $current_payload = array(
            'location_phone'            => isset( $current['phoneNumbers']['primaryPhone'] ) ? (string) $current['phoneNumbers']['primaryPhone'] : '',
            'gmb_phone_additional_list' => isset( $current['phoneNumbers']['additionalPhones'] ) && is_array( $current['phoneNumbers']['additionalPhones'] ) ? $current['phoneNumbers']['additionalPhones'] : array(),
            'location_website'          => isset( $current['websiteUri'] ) ? (string) $current['websiteUri'] : '',
            'location_chat_channels'    => array(),
            'location_chat_type'        => '',
            'location_chat_country'     => isset( $job['submitted']['location_chat_country'] ) ? (string) $job['submitted']['location_chat_country'] : 'CO',
            'location_chat_url'         => '',
            'location_menu_url'         => '',
            'location_booking_urls'     => array(),
            'location_order_urls'       => array(),
            'social_profiles_manual'    => array(),
        );

        $attrs = isset( $current['attributes'] ) && is_array( $current['attributes'] ) ? $current['attributes'] : array();
        $links = isset( $current['placeActionLinks'] ) && is_array( $current['placeActionLinks'] ) ? $current['placeActionLinks'] : array();

        $current_whatsapp_uri = $get_first_uri( $attrs, array( 'url_whatsapp' ) );
        $current_sms_uri      = $get_first_uri( $attrs, array( 'url_text_messaging' ) );
        $current_chat_channels = array();
        if ( $current_whatsapp_uri ) {
            $current_chat_channels[] = array(
                'type'    => 'whatsapp',
                'country' => $current_payload['location_chat_country'],
                'value'   => $current_whatsapp_uri,
            );
        }
        if ( $current_sms_uri ) {
            $current_chat_channels[] = array(
                'type'    => 'sms',
                'country' => $current_payload['location_chat_country'],
                'value'   => $current_sms_uri,
            );
        }
        if ( class_exists( 'Lealez_GMB_API' ) && method_exists( 'Lealez_GMB_API', 'normalize_contact_chat_channels' ) ) {
            $current_chat_channels = Lealez_GMB_API::normalize_contact_chat_channels( $current_chat_channels, '', array(), $current_payload['location_chat_country'] );
        }
        $current_payload['location_chat_channels'] = is_array( $current_chat_channels ) ? $current_chat_channels : array();
        if ( ! empty( $current_payload['location_chat_channels'][0] ) ) {
            $current_payload['location_chat_type']    = $current_payload['location_chat_channels'][0]['type'];
            $current_payload['location_chat_country'] = $current_payload['location_chat_channels'][0]['country'];
            $current_payload['location_chat_url']     = $current_payload['location_chat_channels'][0]['value'];
        }
        $current_payload['location_menu_url'] = $get_first_uri( $attrs, array( 'url_menu', 'url_food_menu', 'menu_url' ) );

        $mapped = $map_links( $links );
        $current_payload['location_booking_urls'] = $mapped['booking'];
        $current_payload['location_order_urls']   = $mapped['order'];

        foreach ( array( 'facebook', 'instagram', 'twitter', 'linkedin', 'youtube', 'tiktok', 'pinterest' ) as $network ) {
            $uri = $get_first_uri( $attrs, array( 'url_' . $network ) );
            if ( $uri ) {
                $current_payload['social_profiles_manual'][ $network ] = $uri;
            }
        }

        $current_norm = self::normalize_contact_comparison_payload( $current_payload );

        if ( $current_norm === $submitted ) {
            return 'applied';
        }

        return 'google_override';
    }

    /**
     * Renderiza panel de publicación de Contacto.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private function render_contact_push_panel( $post_id ) {
        $post_id      = absint( $post_id );
        $job          = get_post_meta( $post_id, 'gmb_contact_push_job', true );
        $push_nonce   = wp_create_nonce( 'oy_push_contact_gmb_' . $post_id );
        $check_nonce  = wp_create_nonce( 'oy_check_contact_push_status_' . $post_id );
        $business_id  = (int) get_post_meta( $post_id, 'parent_business_id', true );
        $location_name = (string) get_post_meta( $post_id, 'gmb_location_name', true );
        $gmb_connected = ! empty( $business_id ) && ! empty( $location_name );
        $local_pending = (bool) get_post_meta( $post_id, 'oy_contact_local_pending_publish', true );
        $last_manual_save  = get_post_meta( $post_id, 'oy_contact_last_manual_save', true );
        $last_manual_label = ( is_array( $last_manual_save ) && ! empty( $last_manual_save['at_label'] ) ) ? (string) $last_manual_save['at_label'] : '';
        $last_manual_user  = ( is_array( $last_manual_save ) && ! empty( $last_manual_save['by'] ) ) ? (string) $last_manual_save['by'] : '';
        $job_status         = is_array( $job ) ? (string) ( $job['status'] ?? '' ) : '';
        $push_is_locked     = in_array( $job_status, array( 'pending_review', 'queued' ), true );
        $push_disabled_attr = ( $gmb_connected && ! $push_is_locked ) ? '' : 'disabled';

        ob_start();
        ?>
        <div id="oy-contact-push-panel"
             data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
             data-push-nonce="<?php echo esc_attr( $push_nonce ); ?>"
             data-check-nonce="<?php echo esc_attr( $check_nonce ); ?>"
             style="border:1px solid #dadce0; border-radius:4px; background:#fff; margin-bottom:16px; overflow:hidden;">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:10px 14px; background:#f6f7f7; border-bottom:1px solid #dadce0;">
                <span style="font-size:13px; font-weight:600; color:#1d2327;">📤 <?php _e( 'Publicar contacto en Google Business Profile', 'lealez' ); ?></span>
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <button type="button" id="oy-push-contact-btn" class="button button-primary" <?php echo $push_disabled_attr; ?> style="display:inline-flex; align-items:center; gap:6px;">
                        <span class="dashicons dashicons-upload" style="margin-top:3px;"></span>
                        <?php _e( 'Enviar a GMB', 'lealez' ); ?>
                    </button>
                    <?php if ( ! empty( $job ) && is_array( $job ) && ! in_array( $job['status'] ?? '', array( 'applied', 'rejected', 'google_override', 'timeout', 'cancelled' ), true ) ) : ?>
                        <button type="button" id="oy-check-contact-push-status-btn" class="button button-secondary" style="display:inline-flex; align-items:center; gap:6px;">
                            <span class="dashicons dashicons-search" style="margin-top:3px;"></span>
                            <?php _e( 'Verificar estado', 'lealez' ); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ( $local_pending ) : ?>
                <div style="padding:10px 14px; background:#eef4ff; border-bottom:1px solid #d7e3ff;">
                    <p style="margin:0; font-size:12px; color:#1d4ed8; font-weight:600;"><?php _e( 'Hay cambios locales guardados en este metabox pendientes por publicar en GMB.', 'lealez' ); ?></p>
                    <?php if ( $last_manual_label ) : ?>
                        <p style="margin:4px 0 0; font-size:11px; color:#4b5563;">
                            <?php printf( esc_html__( 'Último guardado local: %s', 'lealez' ), esc_html( $last_manual_label ) ); ?>
                            <?php if ( $last_manual_user ) : ?>&nbsp;·&nbsp;<?php printf( esc_html__( 'por %s', 'lealez' ), esc_html( $last_manual_user ) ); ?><?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ( ! $gmb_connected ) : ?>
                <div style="padding:10px 14px;"><p style="margin:0; font-size:12px; color:#999; font-style:italic;"><?php _e( 'Requiere empresa y ubicación GMB vinculadas para poder publicar.', 'lealez' ); ?></p></div>
            <?php elseif ( ! empty( $job ) && is_array( $job ) ) :
                $status    = $job['status'] ?? 'unknown';
                $pushed_at = $job['pushed_at'] ?? '';
                $pushed_by = $job['pushed_by'] ?? '';
                $resolved  = $job['resolved_at'] ?? '';
                $poll_n    = $job['poll_count'] ?? 0;
                $status_cfg = array(
                    'pending_review'  => array( '🕐', '#e07800', __( 'Pendiente de revisión/verificación por Google', 'lealez' ) ),
                    'queued'          => array( '🕐', '#e07800', __( 'En cola de envío', 'lealez' ) ),
                    'applied'         => array( '✅', '#166534', __( 'Cambio aplicado en Google', 'lealez' ) ),
                    'rejected'        => array( '❌', '#dc3232', __( 'Google rechazó el cambio', 'lealez' ) ),
                    'google_override' => array( '⚠️', '#b45309', __( 'Google devolvió datos diferentes a los enviados', 'lealez' ) ),
                    'timeout'         => array( '⏳', '#6b7280', __( 'Sin respuesta de Google en 30 días', 'lealez' ) ),
                    'error'           => array( '🔴', '#dc3232', __( 'Error técnico al enviar', 'lealez' ) ),
                );
                $cfg = $status_cfg[ $status ] ?? array( '⚪', '#555', $status );
                ?>
                <div style="padding:10px 14px;">
                    <p style="margin:0 0 6px; font-size:13px; font-weight:600; color:<?php echo esc_attr( $cfg[1] ); ?>;"><?php echo esc_html( $cfg[0] . ' ' . $cfg[2] ); ?></p>
                    <p style="margin:0; font-size:11px; color:#666;">
                        <?php if ( $pushed_at ) : ?><?php printf( esc_html__( 'Enviado: %s por %s', 'lealez' ), esc_html( $pushed_at ), esc_html( $pushed_by ) ); ?><?php endif; ?>
                        <?php if ( $resolved ) : ?>&nbsp;·&nbsp;<?php printf( esc_html__( 'Resuelto: %s', 'lealez' ), esc_html( $resolved ) ); ?><?php endif; ?>
                        <?php if ( $poll_n ) : ?>&nbsp;·&nbsp;<?php printf( esc_html__( 'Verificaciones automáticas: %d', 'lealez' ), (int) $poll_n ); ?><?php endif; ?>
                    </p>
                    <?php if ( 'pending_review' === $status || 'queued' === $status ) : ?>
                        <p style="margin:6px 0 0; font-size:11px; color:#888; font-style:italic;"><?php _e( 'Google puede moderar cambios de contacto. Usa "Verificar estado" para confirmar si ya quedaron reflejados.', 'lealez' ); ?></p>
                    <?php endif; ?>
                    <?php if ( 'google_override' === $status ) : ?>
                        <p style="margin:6px 0 0; font-size:11px; color:#b45309;"><?php _e( 'Los datos actuales de Google no coinciden exactamente con lo enviado. Usa "Sincronizar desde GMB" para traer el estado real.', 'lealez' ); ?></p>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <div style="padding:10px 14px;"><p style="margin:0; font-size:12px; color:#888; font-style:italic;"><?php _e( 'Ningún cambio enviado aún. Activa "Editar contacto", guarda los cambios del metabox y luego usa "Enviar a GMB".', 'lealez' ); ?></p></div>
            <?php endif; ?>
            <div id="oy-contact-push-state-action-msg" style="padding:0 14px 10px; font-size:12px; min-height:0; display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza JS/CSS del modo Editar / Guardar propio del metabox de contacto.
     */
    public function render_contact_editor_footer_assets() {
        if ( ! is_admin() ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'oy_location' !== $screen->post_type || ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
            return;
        }
        global $post;
        if ( ! $post || 'oy_location' !== $post->post_type ) {
            return;
        }

        $post_id           = (int) $post->ID;
        $save_nonce        = wp_create_nonce( 'oy_save_contact_metabox_' . $post_id );
        $sync_nonce        = wp_create_nonce( 'oy_sync_contact_from_gmb_' . $post_id );
        $local_pending     = (bool) get_post_meta( $post_id, 'oy_contact_local_pending_publish', true );
        $last_manual_save  = get_post_meta( $post_id, 'oy_contact_last_manual_save', true );
        $last_manual_label = ( is_array( $last_manual_save ) && ! empty( $last_manual_save['at_label'] ) ) ? (string) $last_manual_save['at_label'] : '';
        ?>
        <style id="oy-contact-editor-style">
            #oy_location_contact .oy-contact-editor-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 14px;margin:0 0 16px;border:1px solid #dadce0;border-radius:4px;background:#fff;}
            #oy_location_contact .oy-contact-editor-status{font-size:12px;color:#555;}
            #oy_location_contact .oy-contact-readonly{background:#f6f7f7!important;color:#50575e!important;cursor:not-allowed!important;}
            #oy_location_contact .oy-contact-editor-note{display:block;width:100%;font-size:11px;color:#666;}
            #oy_location_contact.oy-contact-editing-active .oy-contact-editor-bar{border-color:#2271b1;background:#f0f6fc;}
            #oy_location_contact .oy-contact-local-pending{display:block;width:100%;margin-top:4px;font-size:11px;color:#1d4ed8;}
            @keyframes oy-contact-spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
            #oy-contact-sync-btn .dashicons.spin,#oy-push-contact-btn .dashicons.spin,#oy-check-contact-push-status-btn .dashicons.spin{animation:oy-contact-spin 1s linear infinite;display:inline-block;}
        </style>
        <script type="text/javascript">
        (function($){
            'use strict';
            var ajaxUrl = (typeof ajaxurl !== 'undefined') ? ajaxurl : <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var postId = <?php echo wp_json_encode( (string) $post_id ); ?>;
            var saveNonce = <?php echo wp_json_encode( $save_nonce ); ?>;
            var syncNonce = <?php echo wp_json_encode( $sync_nonce ); ?>;
            var localPending = <?php echo $local_pending ? 'true' : 'false'; ?>;
            var lastManualLabel = <?php echo wp_json_encode( $last_manual_label ); ?>;
            var editorState = { enabled:false, dirty:false, saving:false, baseline:null };
            var LS_KEY = 'oy_contact_log_' + postId;
            var MAX_LOG = 20;

            var FIELD_SELECTORS = [
                '#location_phone', '#location_website', '#location_menu_url_gmb',
                'select[name^="location_chat_channels"]',
                'input[name^="location_chat_channels"]',
                'input[name="gmb_phone_additional_list[]"]',
                'input[name^="location_booking_urls"]',
                'input[name^="location_order_urls"]',
                'select[name="social_profiles_manual_network[]"]',
                'input[name="social_profiles_manual_url[]"]'
            ];
            var CONTROL_SELECTORS = [
                '#oy-add-phone', '.oy-remove-phone', '#oy-add-chat-channel', '.oy-remove-chat-channel', '#oy-add-booking-url', '.oy-remove-booking-url', '#oy-add-order-url', '.oy-remove-order-url', '#oy-add-social', '.oy-remove-social'
            ];

            var FIELD_MAP = [
                { key:'location_phone', selector:'#location_phone', label:'Teléfono principal' },
                { key:'gmb_phone_additional_list', selector:'input[name="gmb_phone_additional_list[]"]', label:'Teléfonos adicionales', type:'array' },
                { key:'location_chat_channels', selector:'#oy-chat-channels-list .oy-chat-channel-row', label:'Usuarios de chat', type:'chatRows' },
                { key:'location_website', selector:'#location_website', label:'Sitio Web' },
                { key:'location_menu_url', selector:'#location_menu_url_gmb', label:'Vínculo del Menú / Servicios' },
                { key:'location_booking_urls', selector:'#oy-booking-urls-list .oy-booking-url-row', label:'URLs de Reservas', type:'urlRows' },
                { key:'location_order_urls', selector:'#oy-order-urls-list .oy-order-url-row', label:'URLs para Ordenar Online', type:'urlRows' },
                { key:'social_profiles_manual', selector:'#oy-social-profiles-list .oy-social-row', label:'Redes sociales', type:'socialRows' }
            ];

            function ensureUi(){
                var $metabox = $('#oy_location_contact');
                if (!$metabox.length || $metabox.find('.oy-contact-editor-bar').length) { return; }
                var barHtml = ''
                    + '<div class="oy-contact-editor-bar">'
                    + '  <button type="button" class="button button-primary" id="oy-contact-editor-start">Editar contacto</button>'
                    + '  <button type="button" class="button button-primary" id="oy-contact-editor-save" style="display:none;">Guardar cambios del metabox</button>'
                    + '  <button type="button" class="button button-secondary" id="oy-contact-editor-cancel" style="display:none;">Cancelar edición</button>'
                    + '  <span class="oy-contact-editor-status" id="oy-contact-editor-status"></span>'
                    + '  <span class="oy-contact-editor-note">Los cambios de este metabox NO se guardan con el botón "Actualizar" del CPT. Debes usar "Guardar cambios del metabox".</span>'
                    + (localPending ? '<span class="oy-contact-local-pending" id="oy-contact-local-pending-note">Hay cambios locales guardados pendientes por publicar en GMB.</span>' : '<span class="oy-contact-local-pending" id="oy-contact-local-pending-note" style="display:none;"></span>')
                    + '</div>';
                var $anchor = $('#oy-contact-sync-bar');
                if ($anchor.length) { $(barHtml).insertAfter($anchor); } else { $metabox.find('.inside').prepend(barHtml); }
            }

            function collectUrlRows(selector){
                var rows = [];
                $(selector).each(function(){
                    var $row = $(this);
                    var url = $row.find('input[type="url"]').first().val() || '';
                    var label = $row.find('input[name$="[label]"]').val() || '';
                    var type = $row.find('input[name$="[type]"]').val() || '';
                    var fromGmb = $row.find('input[name$="[from_gmb]"]').val() || '0';
                    if (url) { rows.push({url:url,label:label,type:type,from_gmb:fromGmb}); }
                });
                return rows;
            }
            function collectSocialRows(){
                var rows = [];
                $('#oy-social-profiles-list .oy-social-row').each(function(){
                    var net = $(this).find('select[name="social_profiles_manual_network[]"]').val() || '';
                    var url = $(this).find('input[name="social_profiles_manual_url[]"]').val() || '';
                    if (net && url) { rows.push({network:net,url:url}); }
                });
                return rows;
            }
            function collectChatRows(){
                var rows = [];
                $('#oy-chat-channels-list .oy-chat-channel-row').each(function(){
                    var $row = $(this);
                    var type = $row.find('.oy-chat-channel-type').val() || 'whatsapp';
                    var country = $row.find('.oy-chat-channel-country').val() || 'CO';
                    var value = $row.find('.oy-chat-channel-value').val() || '';
                    if (value) { rows.push({type:type,country:country,value:value}); }
                });
                return rows;
            }
            function captureState(){
                var state = {};
                FIELD_MAP.forEach(function(field){
                    if (field.type === 'array') {
                        var arr = [];
                        $(field.selector).each(function(){ var v = $(this).val() || ''; if (v) { arr.push(v); } });
                        state[field.key] = arr;
                    } else if (field.type === 'urlRows') {
                        state[field.key] = collectUrlRows(field.selector);
                    } else if (field.type === 'socialRows') {
                        state[field.key] = collectSocialRows();
                    } else if (field.type === 'chatRows') {
                        state[field.key] = collectChatRows();
                    } else {
                        state[field.key] = ($(field.selector).val() || '').toString();
                    }
                });
                return state;
            }
            function statesEqual(a,b){ return JSON.stringify(a || {}) === JSON.stringify(b || {}); }
            function stringifyValue(v){
                if (Array.isArray(v)) {
                    return v.map(function(item){
                        if (typeof item === 'string') { return item; }
                        if (item.value && item.type) { return item.type + ': ' + item.value; }
                        if (item.url && item.type) { return item.type + ': ' + item.url; }
                        if (item.url) { return item.url; }
                        if (item.network && item.url) { return item.network + ': ' + item.url; }
                        return JSON.stringify(item);
                    }).join(', ');
                }
                return (v || '').toString();
            }
            function buildDiff(before, after){
                var rows = [];
                FIELD_MAP.forEach(function(field){
                    var beforeText = stringifyValue(before[field.key]);
                    var afterText = stringifyValue(after[field.key]);
                    if (beforeText === afterText) { return; }
                    rows.push({ label:field.label, before:beforeText, after:afterText, status: beforeText ? 'changed' : 'new' });
                });
                return rows;
            }
            function setStatus(message,type){
                var colors = { info:'#555', success:'#166534', error:'#dc3232', loading:'#1a73e8' };
                $('#oy-contact-editor-status').text(message || '').css('color', colors[type] || '#555');
            }
            function setPushMsg(message,type){
                var colors = { info:'#555', success:'#166534', error:'#dc3232', loading:'#1a73e8' };
                $('#oy-contact-push-state-action-msg').text(message || '').css({ color: colors[type] || '#555', display: message ? 'block' : 'none' });
            }
            function setSyncMsg(message,type){
                var colors = { info:'#555', success:'#166534', error:'#dc3232', loading:'#1a73e8' };
                $('#oy-contact-sync-msg').text(message || '').css('color', colors[type] || '#555');
            }
            var CHAT_COUNTRIES = {
                CO:'Colombia (+57)', US:'Estados Unidos (+1)', MX:'México (+52)', ES:'España (+34)', AR:'Argentina (+54)', CL:'Chile (+56)', PE:'Perú (+51)', EC:'Ecuador (+593)', PA:'Panamá (+507)', BR:'Brasil (+55)', CA:'Canadá (+1)'
            };
            function chatCountryOptions(selected){
                var html = '';
                selected = selected || 'CO';
                Object.keys(CHAT_COUNTRIES).forEach(function(code){
                    html += '<option value="' + escHtml(code) + '"' + (code === selected ? ' selected' : '') + '>' + escHtml(CHAT_COUNTRIES[code]) + '</option>';
                });
                return html;
            }
            function nextChatIndex(){
                var max = -1;
                $('#oy-chat-channels-list .oy-chat-channel-row').each(function(){
                    var name = $(this).find(':input[name]').first().attr('name') || '';
                    var match = name.match(/location_chat_channels\[(\d+)\]/);
                    if (match) { max = Math.max(max, parseInt(match[1], 10)); }
                });
                return max + 1;
            }
            function buildChatRow(channel, index){
                channel = channel || {};
                var type = channel.type || 'whatsapp';
                var country = channel.country || 'CO';
                var value = channel.value || '';
                var smsDisplay = type === 'sms' ? '' : 'display:none;';
                var placeholder = type === 'sms' ? '+573001234567' : 'https://wa.me/573001234567';
                var html = '';
                html += '<div class="oy-chat-channel-row" style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;margin-bottom:8px;max-width:980px;">';
                html += '<select name="location_chat_channels[' + index + '][type]" class="oy-chat-channel-type" style="min-width:220px;">';
                html += '<option value="whatsapp"' + (type === 'whatsapp' ? ' selected' : '') + '>WhatsApp</option>';
                html += '<option value="sms"' + (type === 'sms' ? ' selected' : '') + '>Mensaje de texto</option>';
                html += '</select>';
                html += '<select name="location_chat_channels[' + index + '][country]" class="oy-chat-channel-country" style="min-width:145px;' + smsDisplay + '">' + chatCountryOptions(country) + '</select>';
                html += '<input type="text" name="location_chat_channels[' + index + '][value]" value="' + escHtml(value) + '" class="regular-text oy-chat-channel-value" style="flex:1;min-width:280px;" data-placeholder-whatsapp="https://wa.me/573001234567" data-placeholder-sms="+573001234567" placeholder="' + escHtml(placeholder) + '">';
                html += '<button type="button" class="button button-small oy-remove-chat-channel" style="color:#dc3232;">✕</button>';
                html += '</div>';
                return html;
            }
            function updateChatFieldUi($context){
                var $rows = $context ? $context.find('.oy-chat-channel-row').addBack('.oy-chat-channel-row') : $('#oy-chat-channels-list .oy-chat-channel-row');
                $rows.each(function(){
                    var $row = $(this);
                    var type = ($row.find('.oy-chat-channel-type').val() || 'whatsapp').toString();
                    var $country = $row.find('.oy-chat-channel-country');
                    var $input = $row.find('.oy-chat-channel-value');
                    if (type === 'sms') {
                        $country.show();
                        $input.attr('placeholder', $input.data('placeholder-sms') || '+573001234567');
                    } else {
                        $country.hide();
                        $input.attr('placeholder', $input.data('placeholder-whatsapp') || 'https://wa.me/573001234567');
                    }
                });
            }
            function renderChatRows(channels){
                var $list = $('#oy-chat-channels-list');
                $list.empty();
                channels = Array.isArray(channels) ? channels : [];
                channels.forEach(function(channel, idx){ $list.append(buildChatRow(channel, idx)); });
                updateChatFieldUi();
            }
            function updateUiState(){
                var lock = !editorState.enabled;
                $(FIELD_SELECTORS.join(',')).each(function(){
                    $(this).prop('disabled', lock).toggleClass('oy-contact-readonly', lock);
                });
                $(CONTROL_SELECTORS.join(',')).prop('disabled', lock).toggleClass('oy-contact-readonly', lock);
                $('#oy_location_contact').toggleClass('oy-contact-editing-active', editorState.enabled);
                $('#oy-contact-editor-start').toggle(!editorState.enabled);
                $('#oy-contact-editor-save,#oy-contact-editor-cancel').toggle(editorState.enabled);
                $('#oy-contact-editor-save').prop('disabled', !editorState.dirty || editorState.saving);
            }
            function refreshDirtyState(){ editorState.dirty = !statesEqual(editorState.baseline, captureState()); updateUiState(); }
            function applyState(state){
                $('#location_phone').val(state.location_phone || '');
                renderChatRows(state.location_chat_channels || []);
                $('#location_website').val(state.location_website || '');
                $('#location_menu_url_gmb').val(state.location_menu_url || '');
                $('#oy-additional-phones-list').empty();
                (state.gmb_phone_additional_list || []).forEach(function(v){ $('#oy-additional-phones-list').append('<div class="oy-phone-row" style="display:flex; gap:6px; margin-bottom:6px; align-items:center;"><input type="tel" name="gmb_phone_additional_list[]" value="'+ $('<div>').text(v).html() +'" class="regular-text" placeholder="+573001234567"><button type="button" class="button button-small oy-remove-phone" style="color:#dc3232;">✕</button></div>'); });
                // En cancelación restauramos campos simples y teléfonos. Los rows complejos quedan como estaban visualmente salvo recarga.
            }
            function beginEditMode(){
                if (!postId || postId === '0') { setStatus('Guarda primero la ubicación para poder editar este metabox de forma independiente.','error'); return; }
                editorState.baseline = captureState(); editorState.enabled = true; editorState.dirty = false; updateUiState(); setStatus('Modo edición activo. Modifica los campos y luego guarda el metabox.','info');
            }
            function cancelEditMode(){ if (editorState.dirty) { window.location.reload(); return; } editorState.enabled = false; editorState.dirty = false; updateUiState(); setStatus('Edición cancelada.','info'); }
            function collectAjaxPayload(){
                var data = $('#oy_location_contact :input[name]').serializeArray();
                data.push({name:'action', value:'oy_save_contact_metabox'});
                data.push({name:'nonce', value:saveNonce});
                data.push({name:'post_id', value:postId});
                return $.param(data);
            }
            function replacePushPanel(html){ if (html) { $('#oy-contact-push-panel').replaceWith($(html)); } }
            function saveMetabox(){
                if (!editorState.enabled || editorState.saving) { return; }
                var before = editorState.baseline || captureState();
                var after = captureState();
                var diff = buildDiff(before, after);
                if (!diff.length) { setStatus('No hay cambios para guardar en el metabox.','info'); return; }
                editorState.saving = true; updateUiState(); setStatus('Guardando cambios del metabox...','loading');
                $.ajax({ url:ajaxUrl, type:'POST', timeout:45000, data:collectAjaxPayload() })
                    .done(function(resp){
                        if (!resp || !resp.success) { setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'No se pudieron guardar los cambios del metabox.','error'); return; }
                        editorState.baseline = captureState(); editorState.enabled = false; editorState.dirty = false; localPending = true; updateUiState();
                        setStatus((resp.data && resp.data.message) ? resp.data.message : 'Cambios guardados.','success');
                        if (resp.data && resp.data.panel_html) { replacePushPanel(resp.data.panel_html); }
                        $('#oy-contact-local-pending-note').text('Hay cambios locales guardados pendientes por publicar en GMB.').show();
                        addLogEntry({source:'metabox_save', message:'Guardado local del metabox', saved_at:(resp.data && resp.data.saved_at_label) ? resp.data.saved_at_label : ''}, diff, 'metabox_save');
                    })
                    .fail(function(xhr,status){ setStatus(status === 'timeout' ? 'Timeout al guardar el metabox. Intenta de nuevo.' : 'Error de red al guardar el metabox.','error'); })
                    .always(function(){ editorState.saving = false; updateUiState(); });
            }
            function escHtml(value){ return $('<div>').text(value === null || typeof value === 'undefined' ? '' : value).html(); }
            function readLog(){ try { var arr = JSON.parse(localStorage.getItem(LS_KEY) || '[]'); return Array.isArray(arr) ? arr : []; } catch(e){ return []; } }
            function writeLog(arr){ try { localStorage.setItem(LS_KEY, JSON.stringify(arr.slice(0, MAX_LOG))); } catch(e){} }
            function summarizeRaw(raw){
                raw = raw || {};
                var parts = [];
                if (raw.status) { parts.push('Estado: ' + raw.status); }
                if (raw.message) { parts.push(raw.message); }
                if (raw.result && raw.result.update_mask) { parts.push('updateMask: ' + raw.result.update_mask); }
                if (raw.result && raw.result.attributes_submitted) { parts.push('Atributos URL enviados: ' + Object.keys(raw.result.attributes_submitted || {}).join(', ')); }
                if (raw.result && raw.result.place_actions_response && raw.result.place_actions_response.operations) { parts.push('Place Actions: ' + raw.result.place_actions_response.operations.length + ' operación(es)'); }
                if (raw.result && raw.result.warnings && raw.result.warnings.length) { parts.push('Advertencias API: ' + raw.result.warnings.length); }
                if (raw.snapshot && raw.snapshot._contact_aux_errors) { parts.push('Errores auxiliares: ' + JSON.stringify(raw.snapshot._contact_aux_errors)); }
                return parts.join(' · ');
            }
            function renderLog(){
                var $container = $('#oy-contact-log-entries'); if (!$container.length) { return; }
                var entries = readLog(); $container.empty();
                if (!entries.length) { $container.html('<div style="padding:12px 14px; font-size:12px; color:#777;">Sin registros todavía.</div>'); return; }
                entries.forEach(function(entry){
                    var diff = Array.isArray(entry.diff) ? entry.diff : [];
                    var changedCount = diff.filter(function(r){ return r.status === 'changed' || r.status === 'new' || r.status === 'removed'; }).length;
                    var statusLabel = changedCount ? ('✏️ ' + changedCount + ' campo' + (changedCount !== 1 ? 's' : '') + ' con diferencia') : '✅ Sin diferencias de campos';
                    var statusColor = changedCount ? '#e06800' : '#46b450';
                    var rawSummary = summarizeRaw(entry.raw || {});
                    var html = '';
                    html += '<div style="padding:12px 14px;border-bottom:1px solid #f0f0f0;font-size:12px;background:#fff;">';
                    html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">';
                    html += '<div><strong style="font-size:13px;color:#1d2327;">' + escHtml(entry.title || 'Evento') + '</strong> <span style="color:#777;">' + escHtml(entry.at || '') + '</span></div>';
                    html += '<span style="font-size:11px;font-weight:600;color:' + statusColor + ';">' + escHtml(statusLabel) + '</span>';
                    html += '</div>';
                    if (rawSummary) { html += '<div style="margin-top:5px;color:#666;line-height:1.45;">' + escHtml(rawSummary) + '</div>'; }
                    if (diff.length) {
                        html += '<table style="margin-top:8px;width:100%;border-collapse:collapse;font-size:11px;">';
                        html += '<thead><tr style="background:#f6f7f7;"><th style="text-align:left;padding:5px 7px;border:1px solid #ececec;">Campo</th><th style="text-align:left;padding:5px 7px;border:1px solid #ececec;">Antes / GMB</th><th style="text-align:left;padding:5px 7px;border:1px solid #ececec;">Después / Lealez</th><th style="text-align:left;padding:5px 7px;border:1px solid #ececec;">Estado</th></tr></thead><tbody>';
                        diff.forEach(function(r){
                            var cfg = { changed:['Modificado','#e06800'], new:['Nuevo','#2271b1'], removed:['Eliminado','#dc3232'], unchanged:['Sin cambio','#46b450'] }[r.status || 'changed'] || ['Modificado','#e06800'];
                            html += '<tr>';
                            html += '<td style="padding:5px 7px;border:1px solid #f0f0f0;font-weight:600;">' + escHtml(r.label || '') + '</td>';
                            html += '<td style="padding:5px 7px;border:1px solid #f0f0f0;font-family:monospace;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + escHtml(r.before || '—') + '">' + escHtml(r.before || '—') + '</td>';
                            html += '<td style="padding:5px 7px;border:1px solid #f0f0f0;font-family:monospace;font-weight:' + ((r.status || '') !== 'unchanged' ? '600' : '400') + ';max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + escHtml(r.after || '—') + '">' + escHtml(r.after || '—') + '</td>';
                            html += '<td style="padding:5px 7px;border:1px solid #f0f0f0;color:' + cfg[1] + ';font-weight:600;">' + escHtml(cfg[0]) + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                    } else {
                        html += '<div style="color:#777;margin-top:6px;">Sin cambios detectados en los campos comparados.</div>';
                    }
                    if (entry.raw && Object.keys(entry.raw).length) {
                        html += '<details style="margin-top:8px;"><summary style="cursor:pointer;color:#2271b1;font-weight:600;">Ver detalle técnico del evento</summary>';
                        html += '<pre style="margin:6px 0 0;padding:8px;max-height:240px;overflow:auto;background:#f6f7f7;border:1px solid #e5e5e5;border-radius:3px;font-size:11px;line-height:1.4;white-space:pre-wrap;">' + escHtml(JSON.stringify(entry.raw, null, 2)) + '</pre>';
                        html += '</details>';
                    }
                    html += '</div>';
                    $container.append(html);
                });
            }
            function addLogEntry(raw, diff, source){
                var entries = readLog();
                var titleMap = { button:'Sincronización desde GMB', push:'Envío a GMB', metabox_save:'Guardado local', check:'Verificación de estado' };
                entries.unshift({ title: titleMap[source] || 'Evento de Contacto', at:(new Date()).toLocaleString(), raw:raw || {}, diff:diff || [] });
                writeLog(entries); renderLog();
                if ($('#oy-contact-log-body').is(':hidden')) { $('#oy-contact-log-body').show(); $('#oy-contact-log-toggle-icon').text('▼'); }
            }
            function updateFormFromPayload(payload){
                if (!payload) { return; }
                $('#location_phone').val(payload.location_phone || '');
                renderChatRows(payload.location_chat_channels || []);
                $('#location_website').val(payload.location_website || '');
                $('#location_menu_url_gmb').val(payload.location_menu_url || '');
                $('#oy-additional-phones-list').empty();
                (payload.gmb_phone_additional_list || []).forEach(function(v){ $('#oy-additional-phones-list').append('<div class="oy-phone-row" style="display:flex; gap:6px; margin-bottom:6px; align-items:center;"><input type="tel" name="gmb_phone_additional_list[]" value="'+ $('<div>').text(v).html() +'" class="regular-text" placeholder="+573001234567"><button type="button" class="button button-small oy-remove-phone" style="color:#dc3232;">✕</button></div>'); });
                // Para listas complejas se recarga la página después de sincronizar para evitar reconstruir índices dinámicos manualmente.
            }

            $(document).on('change', '.oy-chat-channel-type', function(){ updateChatFieldUi($(this).closest('.oy-chat-channel-row')); if (editorState.enabled) { refreshDirtyState(); } });
            $(document).on('click', '#oy-add-chat-channel', function(e){ e.preventDefault(); $('#oy-chat-channels-list').append(buildChatRow({type:'sms',country:'CO',value:''}, nextChatIndex())); updateChatFieldUi(); if (editorState.enabled) { refreshDirtyState(); updateUiState(); } });
            $(document).on('click', '.oy-remove-chat-channel', function(e){ e.preventDefault(); $(this).closest('.oy-chat-channel-row').remove(); if (editorState.enabled) { refreshDirtyState(); } });
            $(document).on('input change', FIELD_SELECTORS.join(','), function(){ if (editorState.enabled) { refreshDirtyState(); } });
            $(document).on('click', CONTROL_SELECTORS.join(','), function(){ if (editorState.enabled) { setTimeout(refreshDirtyState, 0); } });
            $(document).on('click', '#oy-contact-editor-start', function(e){ e.preventDefault(); beginEditMode(); });
            $(document).on('click', '#oy-contact-editor-cancel', function(e){ e.preventDefault(); cancelEditMode(); });
            $(document).on('click', '#oy-contact-editor-save', function(e){ e.preventDefault(); saveMetabox(); });

            document.addEventListener('click', function(event){
                var saveButton = event.target.closest('#publish, #save-post');
                if (saveButton && editorState.enabled && editorState.dirty) {
                    event.preventDefault(); event.stopPropagation(); if (event.stopImmediatePropagation) { event.stopImmediatePropagation(); }
                    setStatus('Primero guarda los cambios del metabox de contacto antes de usar "Actualizar" o "Guardar borrador".','error');
                }
                var pushButton = event.target.closest('#oy-push-contact-btn');
                if (pushButton && editorState.enabled && editorState.dirty) {
                    event.preventDefault(); event.stopPropagation(); if (event.stopImmediatePropagation) { event.stopImmediatePropagation(); }
                    setPushMsg('Primero guarda los cambios del metabox de contacto. "Enviar a GMB" usa únicamente lo que ya quedó guardado.','error');
                    setStatus('Primero guarda los cambios del metabox de contacto.','error');
                }
                var syncButton = event.target.closest('#oy-contact-sync-btn');
                if (syncButton && editorState.enabled && editorState.dirty) {
                    event.preventDefault(); event.stopPropagation(); if (event.stopImmediatePropagation) { event.stopImmediatePropagation(); }
                    setStatus('Primero guarda o cancela la edición actual antes de sincronizar desde GMB.','error');
                }
            }, true);

            $(document).on('click', '#oy-contact-log-header', function(){ var $body = $('#oy-contact-log-body'); var $icon = $('#oy-contact-log-toggle-icon'); $body.toggle(); $icon.text($body.is(':visible') ? '▼' : '▶'); });
            $(document).on('click', '#oy-contact-log-clear', function(e){ e.preventDefault(); writeLog([]); renderLog(); });

            $(document).on('click', '#oy-contact-sync-btn', function(e){
                e.preventDefault();
                if (editorState.enabled && editorState.dirty) { setStatus('Primero guarda o cancela la edición actual antes de sincronizar desde GMB.','error'); return; }
                var before = captureState(); var $btn = $(this); $btn.prop('disabled', true); $btn.find('.dashicons').addClass('spin'); setSyncMsg('Sincronizando contacto desde GMB...','loading');
                $.ajax({ url:ajaxUrl, type:'POST', timeout:60000, data:{ action:'oy_sync_contact_from_gmb', nonce:syncNonce, post_id:postId } })
                    .done(function(resp){
                        if (!resp || !resp.success) { setSyncMsg((resp && resp.data && resp.data.message) ? resp.data.message : 'No se pudo sincronizar contacto desde GMB.','error'); return; }
                        updateFormFromPayload(resp.data && resp.data.payload ? resp.data.payload : null);
                        var after = captureState(); var diff = buildDiff(before, after);
                        addLogEntry(resp.data || {}, diff, 'button');
                        setSyncMsg(diff.length ? '✅ Contacto sincronizado y guardado. Recargando para mostrar todas las listas actualizadas...' : '✅ Sin cambios: GMB coincide con Lealez.','success');
                        if (resp.data && resp.data.panel_html) { replacePushPanel(resp.data.panel_html); }
                        localPending = false; $('#oy-contact-local-pending-note').hide(); editorState.baseline = captureState(); editorState.dirty = false; updateUiState(); if (diff.length) { setTimeout(function(){ window.location.reload(); }, 700); }
                    })
                    .fail(function(xhr,status){ setSyncMsg(status === 'timeout' ? 'Timeout: Google tardó demasiado. Intenta de nuevo.' : 'Error de red al sincronizar. Revisa la consola.','error'); })
                    .always(function(){ $btn.prop('disabled', false); $btn.find('.dashicons').removeClass('spin'); });
            });

            $(document).on('click', '#oy-push-contact-btn', function(e){
                e.preventDefault();
                var $panel = $('#oy-contact-push-panel'); var nonce = $panel.data('push-nonce'); var $btn = $(this);
                if (!nonce) { setPushMsg('Error: nonce de push no disponible. Recarga la página.','error'); return; }
                $btn.prop('disabled', true); $btn.find('.dashicons').addClass('spin'); setPushMsg('Enviando contacto a Google Business Profile...','loading');
                $.ajax({ url:ajaxUrl, type:'POST', timeout:60000, data:{ action:'oy_push_contact_to_gmb', nonce:nonce, post_id:postId } })
                    .done(function(resp){
                        if (resp && resp.success) { setPushMsg((resp.data && resp.data.message) ? resp.data.message : 'Contacto enviado.','success'); addLogEntry(resp.data || {}, (resp.data && resp.data.diff) ? resp.data.diff : [], 'push'); if (resp.data && resp.data.panel_html) { replacePushPanel(resp.data.panel_html); } }
                        else { setPushMsg((resp && resp.data && resp.data.message) ? resp.data.message : 'Error desconocido al enviar.','error'); if (resp && resp.data && resp.data.panel_html) { replacePushPanel(resp.data.panel_html); } }
                    })
                    .fail(function(xhr,status){ setPushMsg(status === 'timeout' ? 'Timeout: Google tardó demasiado. Intenta de nuevo.' : 'Error de red al enviar. Revisa la consola.','error'); })
                    .always(function(){ $btn.prop('disabled', false); $btn.find('.dashicons').removeClass('spin'); });
            });

            $(document).on('click', '#oy-check-contact-push-status-btn', function(e){
                e.preventDefault();
                var $panel = $('#oy-contact-push-panel'); var nonce = $panel.data('check-nonce'); var $btn = $(this);
                if (!nonce) { setPushMsg('Error: nonce de verificación no disponible. Recarga la página.','error'); return; }
                $btn.prop('disabled', true); $btn.find('.dashicons').addClass('spin'); setPushMsg('Consultando Google Business Profile...','loading');
                $.ajax({ url:ajaxUrl, type:'POST', timeout:45000, data:{ action:'oy_check_contact_push_status', nonce:nonce, post_id:postId } })
                    .done(function(resp){ if (resp && resp.success && resp.data && resp.data.panel_html) { replacePushPanel(resp.data.panel_html); addLogEntry(resp.data || {}, [], 'check'); setPushMsg('', 'info'); } else { setPushMsg((resp && resp.data && resp.data.message) ? resp.data.message : 'Error desconocido al verificar.','error'); } })
                    .fail(function(){ setPushMsg('Error de red al verificar. Intenta de nuevo.','error'); })
                    .always(function(){ $btn.prop('disabled', false); $btn.find('.dashicons').removeClass('spin'); });
            });

            $(document).ready(function(){
                if (!$('#oy_location_contact').length) { return; }
                ensureUi(); updateChatFieldUi(); editorState.baseline = captureState(); updateUiState(); renderLog();
                if (lastManualLabel) { setStatus('Último guardado local del metabox: ' + lastManualLabel, 'info'); }
                else if (localPending) { setStatus('Hay cambios locales pendientes por publicar en GMB.', 'info'); }
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Render Contact Information meta box
     */
    public function render_contact_meta_box( $post ) {
        $phone                 = get_post_meta( $post->ID, 'location_phone', true );
        $phone_additional_list = get_post_meta( $post->ID, 'gmb_phone_additional_list', true );
        $chat_channels         = get_post_meta( $post->ID, 'location_chat_channels', true );
        $legacy_chat_url        = get_post_meta( $post->ID, 'location_chat_url', true );
        $legacy_chat_type       = get_post_meta( $post->ID, 'location_chat_type', true );
        $legacy_chat_country    = get_post_meta( $post->ID, 'location_chat_country', true );
        // Backward compat: if no chat channels set but old whatsapp field exists.
        if ( ( ! is_array( $chat_channels ) || empty( $chat_channels ) ) && empty( $legacy_chat_url ) ) {
            $legacy_chat_url = get_post_meta( $post->ID, 'location_whatsapp', true );
        }
        if ( ! is_array( $chat_channels ) || empty( $chat_channels ) ) {
            $chat_channels = '' !== trim( (string) $legacy_chat_url )
                ? array(
                    array(
                        'type'    => $legacy_chat_type,
                        'country' => $legacy_chat_country ? $legacy_chat_country : 'CO',
                        'value'   => $legacy_chat_url,
                    ),
                )
                : array();
        }
        $chat_channels = $this->normalize_chat_channels( $chat_channels, (string) $phone, is_array( $phone_additional_list ) ? $phone_additional_list : array(), 'CO' );
        $website       = get_post_meta( $post->ID, 'location_website', true );
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
        $contact_business_id   = (int) get_post_meta( $post->ID, 'parent_business_id', true );
        $contact_location_name = (string) get_post_meta( $post->ID, 'gmb_location_name', true );
        $contact_gmb_connected = ! empty( $contact_business_id ) && ! empty( $contact_location_name );
        ?>

        <?php echo $this->render_contact_push_panel( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

        <div id="oy-contact-sync-bar" style="display:flex;align-items:center;gap:12px;background:#f6f7f7;border:1px solid #dadce0;border-radius:4px;padding:10px 14px;margin-bottom:16px;flex-wrap:wrap;">
            <button type="button" id="oy-contact-sync-btn" class="button button-secondary" <?php echo $contact_gmb_connected ? '' : 'disabled'; ?> style="display:inline-flex;align-items:center;gap:6px;">
                <span class="dashicons dashicons-update" style="margin-top:3px;"></span>
                <?php _e( 'Sincronizar contacto desde GMB', 'lealez' ); ?>
            </button>
            <span id="oy-contact-sync-msg" style="font-size:12px;color:#555;"></span>
            <?php if ( ! $contact_gmb_connected ) : ?>
                <span style="font-size:11px;color:#999;font-style:italic;"><?php _e( '(Requiere empresa y ubicación GMB vinculadas)', 'lealez' ); ?></span>
            <?php endif; ?>
        </div>

        <div id="oy-contact-log-panel" style="margin-bottom:16px;border:1px solid #dadce0;border-radius:4px;overflow:hidden;background:#fff;">
            <div id="oy-contact-log-header" style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;background:#f6f7f7;cursor:pointer;border-bottom:1px solid transparent;user-select:none;">
                <span style="font-size:13px;font-weight:600;color:#1d2327;">🔍 <?php _e( 'Log de Sincronización — Información de Contacto', 'lealez' ); ?></span>
                <span id="oy-contact-log-toggle-icon" style="font-size:13px;color:#888;transition:transform .2s;">▶</span>
            </div>
            <div id="oy-contact-log-body" style="display:none;">
                <div id="oy-contact-log-entries"></div>
                <div style="padding:8px 14px;border-top:1px solid #f0f0f0;background:#fafafa;display:flex;gap:10px;align-items:center;">
                    <button type="button" id="oy-contact-log-clear" class="button button-small" style="font-size:11px;color:#dc3232;border-color:#dc3232;">🗑 <?php _e( 'Limpiar historial', 'lealez' ); ?></button>
                    <span style="font-size:11px;color:#aaa;font-style:italic;"><?php _e( 'Historial guardado en el navegador (localStorage). Máx 20 entradas.', 'lealez' ); ?></span>
                </div>
            </div>
        </div>

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
        <?php
        $chat_countries = array(
            'CO' => __( 'Colombia (+57)', 'lealez' ),
            'US' => __( 'Estados Unidos (+1)', 'lealez' ),
            'MX' => __( 'México (+52)', 'lealez' ),
            'ES' => __( 'España (+34)', 'lealez' ),
            'AR' => __( 'Argentina (+54)', 'lealez' ),
            'CL' => __( 'Chile (+56)', 'lealez' ),
            'PE' => __( 'Perú (+51)', 'lealez' ),
            'EC' => __( 'Ecuador (+593)', 'lealez' ),
            'PA' => __( 'Panamá (+507)', 'lealez' ),
            'BR' => __( 'Brasil (+55)', 'lealez' ),
            'CA' => __( 'Canadá (+1)', 'lealez' ),
        );
        ?>
        <div id="oy-chat-channels-wrap" style="margin:0 0 16px 0;">
            <p class="description" style="margin-bottom:10px;">
                <?php _e( 'GMB permite configurar más de un canal de chat. Lealez enviará WhatsApp como <code>url_whatsapp</code> y Mensaje de texto como <code>url_text_messaging</code>. Si borras todos los canales y guardas, Lealez limpiará ambos atributos en GMB cuando uses "Enviar a GMB".', 'lealez' ); ?>
            </p>
            <div id="oy-chat-channels-list">
                <?php foreach ( $chat_channels as $idx => $channel ) :
                    $channel_type    = isset( $channel['type'] ) ? $this->normalize_chat_type( $channel['type'], $channel['value'] ?? '' ) : 'whatsapp';
                    $channel_country = isset( $channel['country'] ) ? $this->normalize_chat_country( $channel['country'] ) : 'CO';
                    $channel_value   = isset( $channel['value'] ) ? (string) $channel['value'] : '';
                    ?>
                    <div class="oy-chat-channel-row" style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;margin-bottom:8px;max-width:980px;">
                        <select name="location_chat_channels[<?php echo esc_attr( (string) $idx ); ?>][type]"
                                class="oy-chat-channel-type"
                                style="min-width:220px;">
                            <option value="whatsapp" <?php selected( $channel_type, 'whatsapp' ); ?>><?php _e( 'WhatsApp', 'lealez' ); ?></option>
                            <option value="sms" <?php selected( $channel_type, 'sms' ); ?>><?php _e( 'Mensaje de texto', 'lealez' ); ?></option>
                        </select>

                        <select name="location_chat_channels[<?php echo esc_attr( (string) $idx ); ?>][country]"
                                class="oy-chat-channel-country"
                                style="min-width:145px;<?php echo 'sms' === $channel_type ? '' : 'display:none;'; ?>">
                            <?php foreach ( $chat_countries as $country_code => $country_label ) : ?>
                                <option value="<?php echo esc_attr( $country_code ); ?>" <?php selected( $channel_country, $country_code ); ?>><?php echo esc_html( $country_label ); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <input type="text"
                               name="location_chat_channels[<?php echo esc_attr( (string) $idx ); ?>][value]"
                               value="<?php echo esc_attr( $channel_value ); ?>"
                               class="regular-text oy-chat-channel-value"
                               style="flex:1;min-width:280px;"
                               data-placeholder-whatsapp="https://wa.me/573001234567"
                               data-placeholder-sms="+573001234567"
                               placeholder="<?php echo 'sms' === $channel_type ? esc_attr( '+573001234567' ) : esc_attr( 'https://wa.me/573001234567' ); ?>">
                        <button type="button" class="button button-small oy-remove-chat-channel" style="color:#dc3232;">✕</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="oy-add-chat-channel" class="button button-small">+ <?php _e( 'Agregar chat adicional', 'lealez' ); ?></button>
            <p class="description oy-chat-help" style="margin-top:8px;">
                <?php _e( 'Para WhatsApp usa una URL click-to-chat como <code>https://wa.me/573102695744</code>. Para SMS usa el número de teléfono; Lealez lo normaliza como número internacional y lo envía técnicamente como <code>sms:+...</code>.', 'lealez' ); ?>
            </p>
        </div>
        <hr style="margin:16px 0;">

        <h4><?php _e( '🌐 Contacto Web', 'lealez' ); ?></h4>
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

        // Si el metabox está bloqueado por el flujo de edición, sus inputs quedan disabled
        // y WordPress no los envía en el POST del botón general "Actualizar".
        // En ese escenario NO se debe borrar meta existente por ausencia de campos.
        $contact_fields_present = isset( $_POST['location_phone'] )
            || isset( $_POST['location_chat_channels'] )
            || isset( $_POST['location_chat_type'] )
            || isset( $_POST['location_chat_country'] )
            || isset( $_POST['location_chat_url'] )
            || isset( $_POST['location_website'] )
            || isset( $_POST['gmb_phone_additional_list'] )
            || isset( $_POST['social_profiles_manual_network'] )
            || isset( $_POST['social_profiles_manual_url'] )
            || isset( $_POST['location_booking_urls'] )
            || isset( $_POST['location_order_urls'] )
            || isset( $_POST['location_menu_url_gmb'] );

        if ( ! $contact_fields_present ) {
            return;
        }

        $payload = $this->build_contact_payload_from_request();
        $this->persist_contact_payload( $post_id, $payload, 'post_update_button', true );
    }

}

endif;
