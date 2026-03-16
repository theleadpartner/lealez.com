<?php
/**
 * Lealez GMB Writer — Capa de Escritura Bidireccional hacia Google Business Profile
 *
 * Complementa Lealez_GMB_API (solo lectura) agregando todos los métodos de
 * escritura (PATCH / POST / DELETE). No modifica ni extiende class-lealez-gmb-api.php;
 * se carga por separado e invoca Lealez_GMB_API::make_request() como transporte HTTP.
 *
 * Archivo: includes/class-lealez-gmb-writer.php
 *
 * Métodos públicos estáticos expuestos:
 *  - update_location_core()              PATCH Business Information API v1
 *                                        (title, phoneNumbers, websiteUri,
 *                                         profile.description, categories, storeCode)
 *  - update_location_hours()             PATCH Business Information API v1
 *                                        (regularHours, specialHours, openInfo)
 *  - get_or_create_place_action_link()   POST o PATCH Place Actions API v1
 *                                        (decide automáticamente según si el link ya existe)
 *  - delete_place_action_link()          DELETE Place Actions API v1
 *
 * Helper privado estático:
 *  - log_write_operation()               Logging robusto dual:
 *                                          1) Lealez_GMB_Logger::log()
 *                                          2) post_meta '_gmb_writer_log' (FIFO, 20 entradas)
 *
 * Consideraciones de diseño:
 *  - updateMask dinámico: solo se envían los campos que realmente cambiaron.
 *  - Campos moderados por Google (title, categories, storefrontAddress): tras un PATCH
 *    exitoso se hace un GET de follow-up para leer metadata.hasPendingEdits y se guarda
 *    en '_gmb_has_pending_edits' para mostrarlo en la UI.
 *  - El rate limiter y el refresh de tokens son responsabilidad de make_request().
 *  - El caché del location se invalida tras cada escritura exitosa.
 *
 * @package    Lealez
 * @subpackage API
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Lealez_GMB_Writer' ) ) {

    /**
     * Class Lealez_GMB_Writer
     *
     * Todos los métodos son estáticos para mantener paridad con Lealez_GMB_API.
     */
    class Lealez_GMB_Writer {

        // =====================================================================
        // CONSTANTES
        // =====================================================================

        /**
         * Base URL para Business Information API v1.
         *
         * @var string
         */
        const BUSINESS_INFO_API_BASE = 'https://mybusinessbusinessinformation.googleapis.com/v1/';

        /**
         * Base URL para Place Actions API v1.
         *
         * @var string
         */
        const PLACE_ACTIONS_API_BASE = 'https://mybusinessplaceactions.googleapis.com/v1/';

        /**
         * Número máximo de entradas en el log persistido (_gmb_writer_log).
         *
         * @var int
         */
        const MAX_LOG_ENTRIES = 20;

        /**
         * Campos que Google moddera antes de aplicar (el cambio no es inmediato).
         * Tras un PATCH exitoso de cualquiera de estos, se hace un GET de follow-up
         * para leer metadata.hasPendingEdits.
         *
         * @var string[]
         */
        const MODERATED_FIELDS = array( 'title', 'categories', 'storefrontAddress' );

        // =====================================================================
        // MÉTODO 1: update_location_core()
        // =====================================================================

        /**
         * Envía un PATCH a Business Information API v1 para actualizar campos
         * del perfil principal de la ubicación.
         *
         * Campos soportados en $fields:
         *   'title'        => (string)  Nombre del negocio en Google.
         *   'phoneNumbers' => (array)   ['primaryPhone' => '+57...', 'additionalPhones' => [...]]
         *   'websiteUri'   => (string)  URL del sitio web.
         *   'description'  => (string)  Descripción del perfil (máx. 750 chars).
         *                               Se mapea a profile.description en la API.
         *   'storeCode'    => (string)  Código interno de tienda.
         *   'categories'   => (array)   ['primaryCategory' => ['name' => 'gcid:...'],
         *                                'additionalCategories' => [ [...], ... ]]
         *
         * Solo los campos presentes en $fields se incluyen en el updateMask.
         *
         * @param int    $post_id       ID del post oy_location (para logging y post_meta).
         * @param int    $business_id   ID del oy_business (para OAuth y rate limiting).
         * @param string $location_name Resource name completo de la location en GMB
         *                             (ej: "accounts/123/locations/456").
         * @param array  $fields        Campos a actualizar (ver descripción arriba).
         *
         * @return array|WP_Error  En éxito: array con claves 'success', 'pending_moderation',
         *                         'response'. En error: WP_Error.
         */
        public static function update_location_core( $post_id, $business_id, $location_name, $fields ) {
            $start_time = microtime( true );
            $user_id    = get_current_user_id();
            $method_key = 'update_location_core';

            // ── Validaciones básicas ─────────────────────────────────────────
            if ( ! $post_id || ! $business_id || ! $location_name || empty( $fields ) ) {
                $error = new WP_Error(
                    'writer_invalid_params',
                    __( '[Writer] Parámetros insuficientes para update_location_core.', 'lealez' )
                );
                self::log_write_operation( $post_id, array(
                    'method'     => $method_key,
                    'user_id'    => $user_id,
                    'mask'       => array(),
                    'changes'    => array(),
                    'status'     => 'error',
                    'error_code' => 'writer_invalid_params',
                    'error_msg'  => $error->get_error_message(),
                    'duration_ms' => 0,
                ) );
                return $error;
            }

            // ── Verificar dependencias ───────────────────────────────────────
            if ( ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'make_request' ) ) {
                $error = new WP_Error(
                    'writer_api_unavailable',
                    __( '[Writer] Lealez_GMB_API::make_request() no está disponible.', 'lealez' )
                );
                self::log_write_operation( $post_id, array(
                    'method'     => $method_key,
                    'user_id'    => $user_id,
                    'mask'       => array(),
                    'changes'    => array(),
                    'status'     => 'error',
                    'error_code' => 'writer_api_unavailable',
                    'error_msg'  => $error->get_error_message(),
                    'duration_ms' => 0,
                ) );
                return $error;
            }

            // ── Log de inicio ────────────────────────────────────────────────
            self::_logger_log( 'info', sprintf(
                '[Writer] Iniciando %s | post_id=%d | business_id=%d | location=%s | user_id=%d | ts=%s',
                $method_key, $post_id, $business_id, $location_name, $user_id,
                date( 'Y-m-d H:i:s' )
            ) );

            // ── Construir cuerpo y updateMask dinámico ───────────────────────
            $body        = array();
            $update_mask = array();
            $changes     = array();

            // Campo: title
            if ( isset( $fields['title'] ) ) {
                $new_title  = sanitize_text_field( (string) $fields['title'] );
                $old_title  = (string) get_post_meta( $post_id, 'location_name', true );
                if ( '' === $old_title ) {
                    $old_title = (string) get_post_meta( $post_id, 'gmb_title', true );
                }
                $body['title'] = $new_title;
                $update_mask[] = 'title';
                $changes['title'] = array(
                    'before' => $old_title,
                    'after'  => $new_title,
                );
                self::_logger_log( 'info', sprintf(
                    '[Writer] Campo "title": ANTES="%s" | DESPUÉS="%s"',
                    $old_title, $new_title
                ) );
            }

            // Campo: phoneNumbers
            if ( isset( $fields['phoneNumbers'] ) && is_array( $fields['phoneNumbers'] ) ) {
                $phone_data = $fields['phoneNumbers'];
                $phone_body = array();

                if ( isset( $phone_data['primaryPhone'] ) && '' !== $phone_data['primaryPhone'] ) {
                    $phone_body['primaryPhone'] = sanitize_text_field( (string) $phone_data['primaryPhone'] );
                }
                if ( isset( $phone_data['additionalPhones'] ) && is_array( $phone_data['additionalPhones'] ) ) {
                    $phone_body['additionalPhones'] = array_map( 'sanitize_text_field', $phone_data['additionalPhones'] );
                }

                if ( ! empty( $phone_body ) ) {
                    $old_phone_primary    = (string) get_post_meta( $post_id, 'location_phone', true );
                    $old_phone_additional = (string) get_post_meta( $post_id, 'location_phone_additional', true );
                    $old_phone_str        = $old_phone_primary;
                    if ( $old_phone_additional ) {
                        $old_phone_str .= ', ' . $old_phone_additional;
                    }
                    $new_phone_str = $phone_body['primaryPhone'] ?? '';
                    if ( isset( $phone_body['additionalPhones'] ) ) {
                        $new_phone_str .= ', ' . implode( ', ', $phone_body['additionalPhones'] );
                    }

                    $body['phoneNumbers'] = $phone_body;
                    $update_mask[]        = 'phoneNumbers';
                    $changes['phoneNumbers'] = array(
                        'before' => $old_phone_str,
                        'after'  => $new_phone_str,
                    );
                    self::_logger_log( 'info', sprintf(
                        '[Writer] Campo "phoneNumbers.primaryPhone": ANTES="%s" | DESPUÉS="%s"',
                        $old_phone_primary,
                        $phone_body['primaryPhone'] ?? ''
                    ) );
                }
            }

            // Campo: websiteUri
            if ( isset( $fields['websiteUri'] ) ) {
                $new_website = esc_url_raw( (string) $fields['websiteUri'] );
                $old_website = (string) get_post_meta( $post_id, 'location_website', true );
                $body['websiteUri'] = $new_website;
                $update_mask[]      = 'websiteUri';
                $changes['websiteUri'] = array(
                    'before' => $old_website,
                    'after'  => $new_website,
                );
                self::_logger_log( 'info', sprintf(
                    '[Writer] Campo "websiteUri": ANTES="%s" | DESPUÉS="%s"',
                    $old_website, $new_website
                ) );
            }

            // Campo: description → se mapea a profile.description en la API
            if ( isset( $fields['description'] ) ) {
                $new_desc = sanitize_textarea_field( (string) $fields['description'] );
                $new_desc = mb_substr( $new_desc, 0, 750 ); // máximo 750 caracteres
                $old_desc = (string) get_post_meta( $post_id, 'location_description', true );
                $body['profile'] = array( 'description' => $new_desc );
                $update_mask[]   = 'profile';
                $changes['profile.description'] = array(
                    'before' => $old_desc,
                    'after'  => $new_desc,
                );
                self::_logger_log( 'info', sprintf(
                    '[Writer] Campo "profile.description": ANTES="%s..." | DESPUÉS="%s..."',
                    mb_substr( $old_desc, 0, 50 ),
                    mb_substr( $new_desc, 0, 50 )
                ) );
            }

            // Campo: storeCode
            if ( isset( $fields['storeCode'] ) ) {
                $new_store_code = sanitize_text_field( (string) $fields['storeCode'] );
                $old_store_code = (string) get_post_meta( $post_id, 'location_code', true );
                $body['storeCode'] = $new_store_code;
                $update_mask[]     = 'storeCode';
                $changes['storeCode'] = array(
                    'before' => $old_store_code,
                    'after'  => $new_store_code,
                );
                self::_logger_log( 'info', sprintf(
                    '[Writer] Campo "storeCode": ANTES="%s" | DESPUÉS="%s"',
                    $old_store_code, $new_store_code
                ) );
            }

            // Campo: categories → MODERADO por Google
            if ( isset( $fields['categories'] ) && is_array( $fields['categories'] ) ) {
                $old_primary    = (string) get_post_meta( $post_id, 'google_primary_category', true );
                $old_additional = get_post_meta( $post_id, 'google_additional_categories', true );
                $old_cats_str   = $old_primary;
                if ( ! empty( $old_additional ) ) {
                    $old_cats_str .= ' | ' . ( is_array( $old_additional )
                        ? implode( ', ', $old_additional )
                        : (string) $old_additional );
                }

                $cats_body = array();
                if ( isset( $fields['categories']['primaryCategory'] ) ) {
                    $cats_body['primaryCategory'] = $fields['categories']['primaryCategory'];
                }
                if ( isset( $fields['categories']['additionalCategories'] ) && is_array( $fields['categories']['additionalCategories'] ) ) {
                    $cats_body['additionalCategories'] = $fields['categories']['additionalCategories'];
                }

                if ( ! empty( $cats_body ) ) {
                    $body['categories'] = $cats_body;
                    $update_mask[]      = 'categories';
                    $changes['categories'] = array(
                        'before' => $old_cats_str,
                        'after'  => wp_json_encode( $cats_body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                    );
                    self::_logger_log( 'info', sprintf(
                        '[Writer] Campo "categories" (MODERADO): ANTES="%s" | DESPUÉS=%s',
                        $old_cats_str,
                        wp_json_encode( $cats_body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
                    ) );
                }
            }

            // ── Guardia: nada que enviar ─────────────────────────────────────
            if ( empty( $update_mask ) || empty( $body ) ) {
                $error = new WP_Error(
                    'writer_nothing_to_update',
                    __( '[Writer] No hay campos válidos para incluir en el updateMask.', 'lealez' )
                );
                self::log_write_operation( $post_id, array(
                    'method'     => $method_key,
                    'user_id'    => $user_id,
                    'mask'       => array(),
                    'changes'    => array(),
                    'status'     => 'error',
                    'error_code' => 'writer_nothing_to_update',
                    'error_msg'  => $error->get_error_message(),
                    'duration_ms' => intval( ( microtime( true ) - $start_time ) * 1000 ),
                ) );
                return $error;
            }

            // ── Construir URL del endpoint ───────────────────────────────────
            $mask_string = implode( ',', $update_mask );
            $endpoint    = self::BUSINESS_INFO_API_BASE . ltrim( $location_name, '/' )
                           . '?updateMask=' . rawurlencode( $mask_string );

            // ── Log de envío ─────────────────────────────────────────────────
            self::_logger_log( 'info', sprintf(
                '[Writer] Enviando PATCH | endpoint=%s | mask=%s | body=%s',
                $endpoint,
                $mask_string,
                wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            ) );

            // ── Llamada HTTP via Lealez_GMB_API::make_request() ─────────────
            $response = Lealez_GMB_API::make_request( $business_id, 'PATCH', $endpoint, $body, false );

            $duration_ms = intval( ( microtime( true ) - $start_time ) * 1000 );

            // ── Manejo de error ──────────────────────────────────────────────
            if ( is_wp_error( $response ) ) {
                $err_code = $response->get_error_code();
                $err_msg  = $response->get_error_message();

                $source = 'api';
                if ( false !== strpos( $err_code, 'rate_limit' ) ) {
                    $source = 'rate_limiter';
                } elseif ( false !== strpos( $err_code, 'token' ) || false !== strpos( $err_code, 'oauth' ) ) {
                    $source = 'oauth_token';
                }

                self::_logger_log( 'error', sprintf(
                    '[Writer] ERROR en %s | fuente=%s | código=%s | mensaje=%s | mask=%s | duration_ms=%d',
                    $method_key, $source, $err_code, $err_msg, $mask_string, $duration_ms
                ) );

                self::log_write_operation( $post_id, array(
                    'method'      => $method_key,
                    'user_id'     => $user_id,
                    'mask'        => $update_mask,
                    'changes'     => $changes,
                    'status'      => 'error',
                    'error_code'  => $err_code,
                    'error_msg'   => $err_msg,
                    'error_source' => $source,
                    'duration_ms' => $duration_ms,
                ) );

                return $response;
            }

            // ── Éxito: verificar hasPendingEdits en campos moderados ─────────
            $pending_moderation = false;
            $moderated_in_mask  = array_intersect( $update_mask, self::MODERATED_FIELDS );

            if ( ! empty( $moderated_in_mask ) ) {
                $pending_moderation = self::_check_pending_edits( $post_id, $business_id, $location_name );
            }

            // ── Invalidar caché del location ─────────────────────────────────
            if ( method_exists( 'Lealez_GMB_API', 'clear_location_cache' ) ) {
                Lealez_GMB_API::clear_location_cache( $business_id, $location_name );
            } elseif ( method_exists( 'Lealez_GMB_API', 'clear_business_cache' ) ) {
                Lealez_GMB_API::clear_business_cache( $business_id );
            }

            // ── Log de éxito ─────────────────────────────────────────────────
            $log_level   = $pending_moderation ? 'warning' : 'success';
            $log_message = $pending_moderation
                ? sprintf(
                    '[Writer] Campos enviados con éxito pero PENDIENTES DE MODERACIÓN por Google | method=%s | mask=%s | duration_ms=%d',
                    $method_key, $mask_string, $duration_ms
                )
                : sprintf(
                    '[Writer] ÉXITO | method=%s | mask=%s | duration_ms=%d',
                    $method_key, $mask_string, $duration_ms
                );

            self::_logger_log( $log_level, $log_message );

            self::log_write_operation( $post_id, array(
                'method'             => $method_key,
                'user_id'            => $user_id,
                'mask'               => $update_mask,
                'changes'            => $changes,
                'status'             => 'success',
                'pending_moderation' => $pending_moderation,
                'duration_ms'        => $duration_ms,
            ) );

            return array(
                'success'            => true,
                'pending_moderation' => $pending_moderation,
                'mask'               => $update_mask,
                'response'           => $response,
            );
        }

        // =====================================================================
        // MÉTODO 2: update_location_hours()
        // =====================================================================

        /**
         * Envía un PATCH a Business Information API v1 para actualizar los
         * horarios de atención (regularHours y/o specialHours) y el estado
         * operativo (openInfo).
         *
         * El formato de $regular_hours es el mismo que devuelve la API de Google
         * y que se guarda en la meta 'gmb_regular_hours_raw':
         *   [ 'periods' => [ ['openDay'=>'MONDAY','openTime'=>['hours'=>9],...], ... ] ]
         *   o [ 'openingHoursType' => 'ALWAYS_OPEN' ]
         *
         * El formato de $special_hours sigue el esquema GMB:
         *   [ 'specialHourPeriods' => [ ['startDate'=>[...],'isClosed'=>true,...], ... ] ]
         *
         * El formato de $open_info es:
         *   [ 'status' => 'OPEN' | 'CLOSED_TEMPORARILY' | 'CLOSED_PERMANENTLY' ]
         *
         * Pasar null en cualquiera de los tres excluye ese campo del updateMask.
         *
         * @param int        $post_id        ID del post oy_location.
         * @param int        $business_id    ID del oy_business.
         * @param string     $location_name  Resource name de la location en GMB.
         * @param array|null $regular_hours  Objeto regularHours a enviar, o null.
         * @param array|null $special_hours  Objeto specialHours a enviar, o null.
         * @param array|null $open_info      Objeto openInfo a enviar, o null.
         *
         * @return array|WP_Error
         */
        public static function update_location_hours(
            $post_id,
            $business_id,
            $location_name,
            $regular_hours = null,
            $special_hours = null,
            $open_info     = null
        ) {
            $start_time = microtime( true );
            $user_id    = get_current_user_id();
            $method_key = 'update_location_hours';

            // ── Validaciones básicas ─────────────────────────────────────────
            if ( ! $post_id || ! $business_id || ! $location_name ) {
                $error = new WP_Error(
                    'writer_invalid_params',
                    __( '[Writer] Parámetros insuficientes para update_location_hours.', 'lealez' )
                );
                self::log_write_operation( $post_id, array(
                    'method'     => $method_key,
                    'user_id'    => $user_id,
                    'mask'       => array(),
                    'changes'    => array(),
                    'status'     => 'error',
                    'error_code' => 'writer_invalid_params',
                    'error_msg'  => $error->get_error_message(),
                    'duration_ms' => 0,
                ) );
                return $error;
            }

            if ( ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'make_request' ) ) {
                $error = new WP_Error(
                    'writer_api_unavailable',
                    __( '[Writer] Lealez_GMB_API::make_request() no está disponible.', 'lealez' )
                );
                self::log_write_operation( $post_id, array(
                    'method'     => $method_key,
                    'user_id'    => $user_id,
                    'mask'       => array(),
                    'changes'    => array(),
                    'status'     => 'error',
                    'error_code' => 'writer_api_unavailable',
                    'error_msg'  => $error->get_error_message(),
                    'duration_ms' => 0,
                ) );
                return $error;
            }

            if ( null === $regular_hours && null === $special_hours && null === $open_info ) {
                $error = new WP_Error(
                    'writer_nothing_to_update',
                    __( '[Writer] Debes proveer al menos uno de: regularHours, specialHours, openInfo.', 'lealez' )
                );
                self::log_write_operation( $post_id, array(
                    'method'     => $method_key,
                    'user_id'    => $user_id,
                    'mask'       => array(),
                    'changes'    => array(),
                    'status'     => 'error',
                    'error_code' => 'writer_nothing_to_update',
                    'error_msg'  => $error->get_error_message(),
                    'duration_ms' => 0,
                ) );
                return $error;
            }

            // ── Log de inicio ────────────────────────────────────────────────
            self::_logger_log( 'info', sprintf(
                '[Writer] Iniciando %s | post_id=%d | business_id=%d | location=%s | user_id=%d | ts=%s',
                $method_key, $post_id, $business_id, $location_name, $user_id,
                date( 'Y-m-d H:i:s' )
            ) );

            // ── Construir cuerpo y updateMask ────────────────────────────────
            $body        = array();
            $update_mask = array();
            $changes     = array();

            if ( null !== $regular_hours && is_array( $regular_hours ) ) {
                $old_regular     = get_post_meta( $post_id, 'gmb_regular_hours_raw', true );
                $old_regular_str = wp_json_encode(
                    is_array( $old_regular ) ? $old_regular : array(),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                $new_regular_str = wp_json_encode( $regular_hours, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

                $body['regularHours'] = $regular_hours;
                $update_mask[]        = 'regularHours';
                $changes['regularHours'] = array(
                    'before' => $old_regular_str,
                    'after'  => $new_regular_str,
                );
                self::_logger_log( 'info', sprintf(
                    '[Writer] Campo "regularHours": ANTES=%s | DESPUÉS=%s',
                    $old_regular_str, $new_regular_str
                ) );
            }

            if ( null !== $special_hours && is_array( $special_hours ) ) {
                $old_special     = get_post_meta( $post_id, 'gmb_special_hours_raw', true );
                $old_special_str = wp_json_encode(
                    is_array( $old_special ) ? $old_special : array(),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                $new_special_str = wp_json_encode( $special_hours, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

                $body['specialHours'] = $special_hours;
                $update_mask[]        = 'specialHours';
                $changes['specialHours'] = array(
                    'before' => $old_special_str,
                    'after'  => $new_special_str,
                );
                self::_logger_log( 'info', sprintf(
                    '[Writer] Campo "specialHours": ANTES=%s | DESPUÉS=%s',
                    $old_special_str, $new_special_str
                ) );
            }

            if ( null !== $open_info && is_array( $open_info ) ) {
                $old_open_info     = get_post_meta( $post_id, 'gmb_open_info_raw', true );
                $old_open_info_str = wp_json_encode(
                    is_array( $old_open_info ) ? $old_open_info : array(),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                $new_open_info_str = wp_json_encode( $open_info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

                $body['openInfo'] = $open_info;
                $update_mask[]    = 'openInfo';
                $changes['openInfo'] = array(
                    'before' => $old_open_info_str,
                    'after'  => $new_open_info_str,
                );
                self::_logger_log( 'info', sprintf(
                    '[Writer] Campo "openInfo": ANTES=%s | DESPUÉS=%s',
                    $old_open_info_str, $new_open_info_str
                ) );
            }

            if ( empty( $update_mask ) || empty( $body ) ) {
                $error = new WP_Error(
                    'writer_nothing_to_update',
                    __( '[Writer] Todos los parámetros resultaron vacíos o inválidos.', 'lealez' )
                );
                self::log_write_operation( $post_id, array(
                    'method'     => $method_key,
                    'user_id'    => $user_id,
                    'mask'       => array(),
                    'changes'    => array(),
                    'status'     => 'error',
                    'error_code' => 'writer_nothing_to_update',
                    'error_msg'  => $error->get_error_message(),
                    'duration_ms' => intval( ( microtime( true ) - $start_time ) * 1000 ),
                ) );
                return $error;
            }

            // ── Endpoint ─────────────────────────────────────────────────────
            $mask_string = implode( ',', $update_mask );
            $endpoint    = self::BUSINESS_INFO_API_BASE . ltrim( $location_name, '/' )
                           . '?updateMask=' . rawurlencode( $mask_string );

            self::_logger_log( 'info', sprintf(
                '[Writer] Enviando PATCH | endpoint=%s | mask=%s | body=%s',
                $endpoint,
                $mask_string,
                wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            ) );

            // ── Llamada HTTP ─────────────────────────────────────────────────
            $response    = Lealez_GMB_API::make_request( $business_id, 'PATCH', $endpoint, $body, false );
            $duration_ms = intval( ( microtime( true ) - $start_time ) * 1000 );

            // ── Manejo de error ──────────────────────────────────────────────
            if ( is_wp_error( $response ) ) {
                $err_code = $response->get_error_code();
                $err_msg  = $response->get_error_message();

                $source = 'api';
                if ( false !== strpos( $err_code, 'rate_limit' ) ) {
                    $source = 'rate_limiter';
                } elseif ( false !== strpos( $err_code, 'token' ) || false !== strpos( $err_code, 'oauth' ) ) {
                    $source = 'oauth_token';
                }

                self::_logger_log( 'error', sprintf(
                    '[Writer] ERROR en %s | fuente=%s | código=%s | mensaje=%s | duration_ms=%d',
                    $method_key, $source, $err_code, $err_msg, $duration_ms
                ) );

                self::log_write_operation( $post_id, array(
                    'method'      => $method_key,
                    'user_id'     => $user_id,
                    'mask'        => $update_mask,
                    'changes'     => $changes,
                    'status'      => 'error',
                    'error_code'  => $err_code,
                    'error_msg'   => $err_msg,
                    'error_source' => $source,
                    'duration_ms' => $duration_ms,
                ) );

                return $response;
            }

            // ── Invalidar caché ──────────────────────────────────────────────
            if ( method_exists( 'Lealez_GMB_API', 'clear_location_cache' ) ) {
                Lealez_GMB_API::clear_location_cache( $business_id, $location_name );
            } elseif ( method_exists( 'Lealez_GMB_API', 'clear_business_cache' ) ) {
                Lealez_GMB_API::clear_business_cache( $business_id );
            }

            self::_logger_log( 'success', sprintf(
                '[Writer] ÉXITO | method=%s | mask=%s | duration_ms=%d',
                $method_key, $mask_string, $duration_ms
            ) );

            self::log_write_operation( $post_id, array(
                'method'             => $method_key,
                'user_id'            => $user_id,
                'mask'               => $update_mask,
                'changes'            => $changes,
                'status'             => 'success',
                'pending_moderation' => false,
                'duration_ms'        => $duration_ms,
            ) );

            return array(
                'success'  => true,
                'mask'     => $update_mask,
                'response' => $response,
            );
        }

        // =====================================================================
        // MÉTODO 3: get_or_create_place_action_link()
        // =====================================================================

        /**
         * Crea o actualiza un Place Action Link en la ubicación.
         *
         * Si ya existe un link del tipo dado ($place_action_type), hace un PATCH
         * para actualizar la URI. Si no existe, hace un POST para crearlo.
         *
         * Tipos de placeActionType soportados por la API:
         *   APPOINTMENT, ONLINE_APPOINTMENT, DINING_RESERVATION, FOOD_ORDERING,
         *   FOOD_DELIVERY, FOOD_TAKEOUT, MENU, SHOP_ONLINE, etc.
         *
         * @param int    $post_id           ID del post oy_location.
         * @param int    $business_id       ID del oy_business.
         * @param string $location_name     Resource name de la location en GMB.
         * @param string $place_action_type Tipo de link (enum). Ej: 'APPOINTMENT', 'MENU'.
         * @param string $url               URL destino del link.
         *
         * @return array|WP_Error  En éxito: array con 'success', 'action' ('created'|'updated'),
         *                         'link_name', 'response'. En error: WP_Error.
         */
        public static function get_or_create_place_action_link(
            $post_id,
            $business_id,
            $location_name,
            $place_action_type,
            $url
        ) {
            $start_time = microtime( true );
            $user_id    = get_current_user_id();
            $method_key = 'get_or_create_place_action_link';

            // ── Validaciones básicas ─────────────────────────────────────────
            if ( ! $post_id || ! $business_id || ! $location_name || ! $place_action_type || ! $url ) {
                $error = new WP_Error(
                    'writer_invalid_params',
                    __( '[Writer] Parámetros insuficientes para get_or_create_place_action_link.', 'lealez' )
                );
                self::log_write_operation( $post_id, array(
                    'method'     => $method_key,
                    'user_id'    => $user_id,
                    'mask'       => array(),
                    'changes'    => array(),
                    'status'     => 'error',
                    'error_code' => 'writer_invalid_params',
                    'error_msg'  => $error->get_error_message(),
                    'duration_ms' => 0,
                ) );
                return $error;
            }

            if ( ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'make_request' ) ) {
                $error = new WP_Error(
                    'writer_api_unavailable',
                    __( '[Writer] Lealez_GMB_API::make_request() no está disponible.', 'lealez' )
                );
                self::log_write_operation( $post_id, array(
                    'method'     => $method_key,
                    'user_id'    => $user_id,
                    'mask'       => array(),
                    'changes'    => array(),
                    'status'     => 'error',
                    'error_code' => 'writer_api_unavailable',
                    'error_msg'  => $error->get_error_message(),
                    'duration_ms' => 0,
                ) );
                return $error;
            }

            $new_url          = esc_url_raw( (string) $url );
            $place_action_type = strtoupper( sanitize_text_field( $place_action_type ) );

            // ── Log de inicio ────────────────────────────────────────────────
            self::_logger_log( 'info', sprintf(
                '[Writer] Iniciando %s | post_id=%d | business_id=%d | location=%s | type=%s | url=%s | user_id=%d',
                $method_key, $post_id, $business_id, $location_name,
                $place_action_type, $new_url, $user_id
            ) );

            // ── Buscar si ya existe un link del mismo tipo ───────────────────
            $existing_link      = null;
            $existing_link_name = null;
            $existing_link_uri  = '';

            if ( method_exists( 'Lealez_GMB_API', 'get_location_place_action_links' ) ) {
                $existing_links = Lealez_GMB_API::get_location_place_action_links( $business_id, $location_name );

                if ( ! is_wp_error( $existing_links ) && is_array( $existing_links ) ) {
                    $links_list = $existing_links['placeActionLinks'] ?? $existing_links;
                    if ( is_array( $links_list ) ) {
                        foreach ( $links_list as $link ) {
                            if ( ! is_array( $link ) ) {
                                continue;
                            }
                            $link_type = strtoupper( (string) ( $link['placeActionType'] ?? '' ) );
                            if ( $link_type === $place_action_type ) {
                                $existing_link      = $link;
                                $existing_link_name = (string) ( $link['name'] ?? '' );
                                $existing_link_uri  = (string) ( $link['uri'] ?? '' );
                                break;
                            }
                        }
                    }
                }
            }

            // ── Log: valor anterior ──────────────────────────────────────────
            self::_logger_log( 'info', sprintf(
                '[Writer] Campo "placeActionLink[%s].uri": ANTES="%s" | DESPUÉS="%s"',
                $place_action_type, $existing_link_uri, $new_url
            ) );

            $changes = array(
                'placeActionType' => $place_action_type,
                'uri' => array(
                    'before' => $existing_link_uri,
                    'after'  => $new_url,
                ),
            );

            // ── Decidir: PATCH (actualizar) o POST (crear) ───────────────────
            if ( $existing_link_name ) {
                // ── PATCH: actualizar URI del link existente ─────────────────
                $endpoint = self::PLACE_ACTIONS_API_BASE . ltrim( $existing_link_name, '/' )
                            . '?updateMask=uri';
                $body     = array( 'uri' => $new_url );

                self::_logger_log( 'info', sprintf(
                    '[Writer] PATCH place action link existente | endpoint=%s | body=%s',
                    $endpoint,
                    wp_json_encode( $body, JSON_UNESCAPED_UNICODE )
                ) );

                $response    = Lealez_GMB_API::make_request( $business_id, 'PATCH', $endpoint, $body, false );
                $duration_ms = intval( ( microtime( true ) - $start_time ) * 1000 );

                if ( is_wp_error( $response ) ) {
                    $err_code = $response->get_error_code();
                    $err_msg  = $response->get_error_message();
                    self::_logger_log( 'error', sprintf(
                        '[Writer] ERROR PATCH place action link | tipo=%s | código=%s | mensaje=%s | duration_ms=%d',
                        $place_action_type, $err_code, $err_msg, $duration_ms
                    ) );
                    self::log_write_operation( $post_id, array(
                        'method'      => $method_key,
                        'user_id'     => $user_id,
                        'mask'        => array( 'uri' ),
                        'changes'     => $changes,
                        'status'      => 'error',
                        'error_code'  => $err_code,
                        'error_msg'   => $err_msg,
                        'duration_ms' => $duration_ms,
                    ) );
                    return $response;
                }

                // Invalidar caché
                if ( method_exists( 'Lealez_GMB_API', 'clear_location_cache' ) ) {
                    Lealez_GMB_API::clear_location_cache( $business_id, $location_name );
                } elseif ( method_exists( 'Lealez_GMB_API', 'clear_business_cache' ) ) {
                    Lealez_GMB_API::clear_business_cache( $business_id );
                }

                self::_logger_log( 'success', sprintf(
                    '[Writer] ÉXITO PATCH place action link | tipo=%s | link=%s | duration_ms=%d',
                    $place_action_type, $existing_link_name, $duration_ms
                ) );

                self::log_write_operation( $post_id, array(
                    'method'             => $method_key,
                    'user_id'            => $user_id,
                    'mask'               => array( 'uri' ),
                    'changes'            => $changes,
                    'status'             => 'success',
                    'pending_moderation' => false,
                    'duration_ms'        => $duration_ms,
                ) );

                return array(
                    'success'   => true,
                    'action'    => 'updated',
                    'link_name' => $existing_link_name,
                    'response'  => $response,
                );

            } else {
                // ── POST: crear nuevo link ────────────────────────────────────
                $endpoint = self::PLACE_ACTIONS_API_BASE . ltrim( $location_name, '/' ) . '/placeActionLinks';
                $body     = array(
                    'placeActionType' => $place_action_type,
                    'uri'             => $new_url,
                    'isPreferred'     => true,
                );

                self::_logger_log( 'info', sprintf(
                    '[Writer] POST nuevo place action link | endpoint=%s | body=%s',
                    $endpoint,
                    wp_json_encode( $body, JSON_UNESCAPED_UNICODE )
                ) );

                $response    = Lealez_GMB_API::make_request( $business_id, 'POST', $endpoint, $body, false );
                $duration_ms = intval( ( microtime( true ) - $start_time ) * 1000 );

                if ( is_wp_error( $response ) ) {
                    $err_code = $response->get_error_code();
                    $err_msg  = $response->get_error_message();
                    self::_logger_log( 'error', sprintf(
                        '[Writer] ERROR POST place action link | tipo=%s | código=%s | mensaje=%s | duration_ms=%d',
                        $place_action_type, $err_code, $err_msg, $duration_ms
                    ) );
                    self::log_write_operation( $post_id, array(
                        'method'      => $method_key,
                        'user_id'     => $user_id,
                        'mask'        => array( 'placeActionType', 'uri' ),
                        'changes'     => $changes,
                        'status'      => 'error',
                        'error_code'  => $err_code,
                        'error_msg'   => $err_msg,
                        'duration_ms' => $duration_ms,
                    ) );
                    return $response;
                }

                // Extraer nombre del link recién creado
                $created_link_name = is_array( $response ) ? ( $response['name'] ?? '' ) : '';

                // Invalidar caché
                if ( method_exists( 'Lealez_GMB_API', 'clear_location_cache' ) ) {
                    Lealez_GMB_API::clear_location_cache( $business_id, $location_name );
                } elseif ( method_exists( 'Lealez_GMB_API', 'clear_business_cache' ) ) {
                    Lealez_GMB_API::clear_business_cache( $business_id );
                }

                self::_logger_log( 'success', sprintf(
                    '[Writer] ÉXITO POST place action link | tipo=%s | link_name=%s | duration_ms=%d',
                    $place_action_type, $created_link_name, $duration_ms
                ) );

                self::log_write_operation( $post_id, array(
                    'method'             => $method_key,
                    'user_id'            => $user_id,
                    'mask'               => array( 'placeActionType', 'uri' ),
                    'changes'            => $changes,
                    'status'             => 'success',
                    'pending_moderation' => false,
                    'duration_ms'        => $duration_ms,
                ) );

                return array(
                    'success'   => true,
                    'action'    => 'created',
                    'link_name' => $created_link_name,
                    'response'  => $response,
                );
            }
        }

        // =====================================================================
        // MÉTODO 4: delete_place_action_link()
        // =====================================================================

        /**
         * Elimina un Place Action Link específico por su resource name completo.
         *
         * @param int    $post_id     ID del post oy_location.
         * @param int    $business_id ID del oy_business.
         * @param string $location_name Resource name de la location (solo para logging/caché).
         * @param string $link_name   Resource name completo del link a eliminar.
         *                            Ej: "accounts/123/locations/456/placeActionLinks/789"
         *
         * @return array|WP_Error  En éxito: array con 'success', 'link_name'. En error: WP_Error.
         */
        public static function delete_place_action_link( $post_id, $business_id, $location_name, $link_name ) {
            $start_time = microtime( true );
            $user_id    = get_current_user_id();
            $method_key = 'delete_place_action_link';

            // ── Validaciones básicas ─────────────────────────────────────────
            if ( ! $post_id || ! $business_id || ! $location_name || ! $link_name ) {
                $error = new WP_Error(
                    'writer_invalid_params',
                    __( '[Writer] Parámetros insuficientes para delete_place_action_link.', 'lealez' )
                );
                self::log_write_operation( $post_id, array(
                    'method'     => $method_key,
                    'user_id'    => $user_id,
                    'mask'       => array(),
                    'changes'    => array(),
                    'status'     => 'error',
                    'error_code' => 'writer_invalid_params',
                    'error_msg'  => $error->get_error_message(),
                    'duration_ms' => 0,
                ) );
                return $error;
            }

            if ( ! class_exists( 'Lealez_GMB_API' ) || ! method_exists( 'Lealez_GMB_API', 'make_request' ) ) {
                $error = new WP_Error(
                    'writer_api_unavailable',
                    __( '[Writer] Lealez_GMB_API::make_request() no está disponible.', 'lealez' )
                );
                self::log_write_operation( $post_id, array(
                    'method'     => $method_key,
                    'user_id'    => $user_id,
                    'mask'       => array(),
                    'changes'    => array(),
                    'status'     => 'error',
                    'error_code' => 'writer_api_unavailable',
                    'error_msg'  => $error->get_error_message(),
                    'duration_ms' => 0,
                ) );
                return $error;
            }

            // ── Log de inicio ────────────────────────────────────────────────
            self::_logger_log( 'info', sprintf(
                '[Writer] Iniciando %s | post_id=%d | business_id=%d | link_name=%s | user_id=%d | ts=%s',
                $method_key, $post_id, $business_id, $link_name, $user_id,
                date( 'Y-m-d H:i:s' )
            ) );

            $changes = array(
                'link_name' => array( 'before' => $link_name, 'after' => '(eliminado)' ),
            );

            // ── DELETE ───────────────────────────────────────────────────────
            $endpoint    = self::PLACE_ACTIONS_API_BASE . ltrim( $link_name, '/' );
            $response    = Lealez_GMB_API::make_request( $business_id, 'DELETE', $endpoint, array(), false );
            $duration_ms = intval( ( microtime( true ) - $start_time ) * 1000 );

            // ── Manejo de error ──────────────────────────────────────────────
            if ( is_wp_error( $response ) ) {
                $err_code = $response->get_error_code();
                $err_msg  = $response->get_error_message();

                $source = 'api';
                if ( false !== strpos( $err_code, 'rate_limit' ) ) {
                    $source = 'rate_limiter';
                } elseif ( false !== strpos( $err_code, 'token' ) || false !== strpos( $err_code, 'oauth' ) ) {
                    $source = 'oauth_token';
                }

                self::_logger_log( 'error', sprintf(
                    '[Writer] ERROR en %s | fuente=%s | link=%s | código=%s | mensaje=%s | duration_ms=%d',
                    $method_key, $source, $link_name, $err_code, $err_msg, $duration_ms
                ) );

                self::log_write_operation( $post_id, array(
                    'method'      => $method_key,
                    'user_id'     => $user_id,
                    'mask'        => array(),
                    'changes'     => $changes,
                    'status'      => 'error',
                    'error_code'  => $err_code,
                    'error_msg'   => $err_msg,
                    'error_source' => $source,
                    'duration_ms' => $duration_ms,
                ) );

                return $response;
            }

            // ── Invalidar caché ──────────────────────────────────────────────
            if ( method_exists( 'Lealez_GMB_API', 'clear_location_cache' ) ) {
                Lealez_GMB_API::clear_location_cache( $business_id, $location_name );
            } elseif ( method_exists( 'Lealez_GMB_API', 'clear_business_cache' ) ) {
                Lealez_GMB_API::clear_business_cache( $business_id );
            }

            self::_logger_log( 'success', sprintf(
                '[Writer] ÉXITO DELETE place action link | link=%s | duration_ms=%d',
                $link_name, $duration_ms
            ) );

            self::log_write_operation( $post_id, array(
                'method'             => $method_key,
                'user_id'            => $user_id,
                'mask'               => array(),
                'changes'            => $changes,
                'status'             => 'success',
                'pending_moderation' => false,
                'duration_ms'        => $duration_ms,
            ) );

            return array(
                'success'   => true,
                'link_name' => $link_name,
            );
        }

        // =====================================================================
        // HELPER PÚBLICO: get_writer_log()
        // =====================================================================

        /**
         * Devuelve el log persistido de operaciones de escritura para un post.
         * Útil para renderizar el historial en metaboxes.
         *
         * @param int $post_id ID del post oy_location.
         * @return array  Array de entradas de log (puede estar vacío).
         */
        public static function get_writer_log( $post_id ) {
            $raw = get_post_meta( $post_id, '_gmb_writer_log', true );
            if ( empty( $raw ) ) {
                return array();
            }
            if ( is_array( $raw ) ) {
                return $raw;
            }
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : array();
        }

        // =====================================================================
        // HELPER PÚBLICO: clear_writer_log()
        // =====================================================================

        /**
         * Borra el log de escritura de un post.
         *
         * @param int $post_id ID del post oy_location.
         * @return bool
         */
        public static function clear_writer_log( $post_id ) {
            return delete_post_meta( (int) $post_id, '_gmb_writer_log' );
        }

        // =====================================================================
        // HELPER PRIVADO: log_write_operation()
        // =====================================================================

        /**
         * Registra una operación de escritura de forma persistente y en el logger.
         *
         * Escritura dual:
         *  1) Llama a Lealez_GMB_Logger::log() si el método existe.
         *  2) Guarda/actualiza el array '_gmb_writer_log' en post_meta:
         *     - Lee el array actual.
         *     - Antepone la nueva entrada.
         *     - Trunca a MAX_LOG_ENTRIES entradas (FIFO).
         *     - Guarda con wp_json_encode + JSON_UNESCAPED_UNICODE.
         *
         * Estructura de $entry (todos los campos son opcionales salvo los marcados *):
         *   'method'*            (string)  Nombre del método que invocó la operación.
         *   'user_id'*           (int)     WP User ID del usuario que ejecutó la acción.
         *   'mask'               (array)   Lista de campos incluidos en el updateMask.
         *   'changes'            (array)   ['campo' => ['before' => X, 'after' => Y], ...]
         *   'status'*            (string)  'success' | 'error' | 'warning'.
         *   'pending_moderation' (bool)    true si Google revisará el cambio antes de aplicar.
         *   'error_code'         (string)  Código del WP_Error si aplica.
         *   'error_msg'          (string)  Mensaje de error si aplica.
         *   'error_source'       (string)  'api' | 'rate_limiter' | 'oauth_token'.
         *   'duration_ms'        (int)     Duración de la operación en milisegundos.
         *
         * @param int   $post_id  ID del post oy_location (para post_meta).
         * @param array $entry    Datos de la entrada a registrar.
         */
        public static function log_write_operation( $post_id, array $entry ) {
            // Asegurar campos obligatorios con defaults seguros
            $entry = wp_parse_args( $entry, array(
                'method'             => 'unknown',
                'user_id'            => get_current_user_id(),
                'mask'               => array(),
                'changes'            => array(),
                'status'             => 'unknown',
                'pending_moderation' => false,
                'duration_ms'        => 0,
            ) );

            // Añadir timestamp
            $entry['timestamp'] = time();

            // ── 1) Lealez_GMB_Logger ─────────────────────────────────────────
            $log_level = 'info';
            if ( 'success' === $entry['status'] ) {
                $log_level = isset( $entry['pending_moderation'] ) && $entry['pending_moderation']
                    ? 'warning'
                    : 'success';
            } elseif ( 'error' === $entry['status'] ) {
                $log_level = 'error';
            } elseif ( 'warning' === $entry['status'] ) {
                $log_level = 'warning';
            }

            $log_summary = sprintf(
                '[Writer log] method=%s | status=%s | mask=[%s] | user=%d | duration=%dms%s%s',
                $entry['method'],
                $entry['status'],
                implode( ',', (array) $entry['mask'] ),
                (int) $entry['user_id'],
                (int) $entry['duration_ms'],
                isset( $entry['error_code'] )   ? ' | error_code=' . $entry['error_code']  : '',
                isset( $entry['pending_moderation'] ) && $entry['pending_moderation']
                    ? ' | PENDIENTE_MODERACION=true'
                    : ''
            );

            self::_logger_log( $log_level, $log_summary );

            // ── 2) Persistir en post_meta _gmb_writer_log ────────────────────
            if ( ! $post_id ) {
                return;
            }

            // Leer log actual
            $current_log_raw = get_post_meta( $post_id, '_gmb_writer_log', true );
            $current_log     = array();

            if ( ! empty( $current_log_raw ) ) {
                if ( is_array( $current_log_raw ) ) {
                    $current_log = $current_log_raw;
                } elseif ( is_string( $current_log_raw ) ) {
                    $decoded = json_decode( $current_log_raw, true );
                    if ( is_array( $decoded ) ) {
                        $current_log = $decoded;
                    }
                }
            }

            // Anteponer nueva entrada
            array_unshift( $current_log, $entry );

            // Truncar a MAX_LOG_ENTRIES
            if ( count( $current_log ) > self::MAX_LOG_ENTRIES ) {
                $current_log = array_slice( $current_log, 0, self::MAX_LOG_ENTRIES );
            }

            // Guardar — usar wp_json_encode con JSON_UNESCAPED_UNICODE para preservar acentos
            update_post_meta(
                (int) $post_id,
                '_gmb_writer_log',
                wp_json_encode( $current_log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            );
        }

        // =====================================================================
        // HELPERS PRIVADOS INTERNOS
        // =====================================================================

        /**
         * Hace un GET de follow-up para leer metadata.hasPendingEdits después de
         * un PATCH de campos moderados (title, categories, storefrontAddress).
         * Guarda el resultado en '_gmb_has_pending_edits' del post.
         *
         * @param int    $post_id       ID del post oy_location.
         * @param int    $business_id   ID del oy_business.
         * @param string $location_name Resource name de la location.
         *
         * @return bool  true si hay ediciones pendientes de moderación, false en caso contrario.
         */
        private static function _check_pending_edits( $post_id, $business_id, $location_name ) {
            $pending = false;

            try {
                $endpoint = self::BUSINESS_INFO_API_BASE
                            . ltrim( $location_name, '/' )
                            . '?readMask=metadata';

                $get_response = Lealez_GMB_API::make_request( $business_id, 'GET', $endpoint, array(), false );

                if ( ! is_wp_error( $get_response ) && is_array( $get_response ) ) {
                    $metadata = $get_response['metadata'] ?? array();
                    $pending  = ! empty( $metadata['hasPendingEdits'] );
                }
            } catch ( Exception $e ) {
                // Fallo silencioso: no interrumpir el flujo principal
                self::_logger_log( 'warning', sprintf(
                    '[Writer] No se pudo verificar hasPendingEdits para location=%s: %s',
                    $location_name, $e->getMessage()
                ) );
            }

            // Persistir para que la UI pueda mostrarlo
            update_post_meta( (int) $post_id, '_gmb_has_pending_edits', $pending ? '1' : '0' );

            if ( $pending ) {
                self::_logger_log( 'warning', sprintf(
                    '[Writer] metadata.hasPendingEdits=true detectado para location=%s — Google revisará el cambio.',
                    $location_name
                ) );
            }

            return $pending;
        }

        /**
         * Proxy hacia Lealez_GMB_Logger::log().
         * Si la clase no existe (por ejemplo en tests), hace fallback a error_log().
         *
         * @param string $level   'info' | 'success' | 'warning' | 'error'
         * @param string $message Mensaje a registrar.
         * @param array  $context Datos adicionales opcionales.
         */
        private static function _logger_log( $level, $message, $context = array() ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) && method_exists( 'Lealez_GMB_Logger', 'log' ) ) {
                Lealez_GMB_Logger::log( $level, $message, $context );
            } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[' . strtoupper( $level ) . '] ' . $message );
            }
        }

    } // end class Lealez_GMB_Writer

} // end if ! class_exists
