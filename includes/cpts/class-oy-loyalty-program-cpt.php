<?php
/**
 * Loyalty Program Custom Post Type
 *
 * @package Lealez
 * @subpackage CPTs
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class OY_Loyalty_Program_CPT
 *
 * Handles the Loyalty Program custom post type registration and functionality
 */
class OY_Loyalty_Program_CPT {

    /**
     * Post type slug
     *
     * @var string
     */
    const POST_TYPE = 'oy_loyalty_program';

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'init', array( $this, 'register_meta_fields' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta_boxes' ), 10, 2 );
        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'set_custom_columns' ) );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'custom_column_content' ), 10, 2 );
        add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( $this, 'set_sortable_columns' ) );
    }

    /**
     * Register the Loyalty Program custom post type
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => _x( 'Programas de Lealtad', 'Post Type General Name', 'lealez' ),
            'singular_name'         => _x( 'Programa de Lealtad', 'Post Type Singular Name', 'lealez' ),
            'menu_name'             => __( 'Programas de Lealtad', 'lealez' ),
            'name_admin_bar'        => __( 'Programa de Lealtad', 'lealez' ),
            'archives'              => __( 'Archivos de Programas', 'lealez' ),
            'attributes'            => __( 'Atributos del Programa', 'lealez' ),
            'parent_item_colon'     => __( 'Programa Padre:', 'lealez' ),
            'all_items'             => __( 'Todos los Programas', 'lealez' ),
            'add_new_item'          => __( 'Agregar Nuevo Programa', 'lealez' ),
            'add_new'               => __( 'Agregar Nuevo', 'lealez' ),
            'new_item'              => __( 'Nuevo Programa', 'lealez' ),
            'edit_item'             => __( 'Editar Programa', 'lealez' ),
            'update_item'           => __( 'Actualizar Programa', 'lealez' ),
            'view_item'             => __( 'Ver Programa', 'lealez' ),
            'view_items'            => __( 'Ver Programas', 'lealez' ),
            'search_items'          => __( 'Buscar Programa', 'lealez' ),
            'not_found'             => __( 'No encontrado', 'lealez' ),
            'not_found_in_trash'    => __( 'No encontrado en la papelera', 'lealez' ),
            'featured_image'        => __( 'Imagen destacada', 'lealez' ),
            'set_featured_image'    => __( 'Establecer imagen destacada', 'lealez' ),
            'remove_featured_image' => __( 'Remover imagen destacada', 'lealez' ),
            'use_featured_image'    => __( 'Usar como imagen destacada', 'lealez' ),
            'insert_into_item'      => __( 'Insertar en programa', 'lealez' ),
            'uploaded_to_this_item' => __( 'Subido a este programa', 'lealez' ),
            'items_list'            => __( 'Lista de programas', 'lealez' ),
            'items_list_navigation' => __( 'Navegación de lista de programas', 'lealez' ),
            'filter_items_list'     => __( 'Filtrar lista de programas', 'lealez' ),
        );

        $args = array(
            'label'               => __( 'Programa de Lealtad', 'lealez' ),
            'description'         => __( 'Gestión de Programas de Lealtad', 'lealez' ),
            'labels'              => $labels,
            'supports'            => array( 'title', 'editor', 'thumbnail', 'author' ),
            'hierarchical'        => false,
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => 'false',
            'menu_position'       => 25,
            'menu_icon'           => 'dashicons-awards',
            'show_in_admin_bar'   => true,
            'show_in_nav_menus'   => true,
            'can_export'          => true,
            'has_archive'         => true,
            'exclude_from_search' => false,
            'publicly_queryable'  => true,
            'capability_type'     => 'post',
            'show_in_rest'        => true,
            'rest_base'           => 'loyalty-programs',
            'rewrite'             => array(
                'slug'       => 'loyalty-program',
                'with_front' => false,
            ),
        );

        register_post_type( self::POST_TYPE, $args );
    }

    /**
     * Register meta fields for the Loyalty Program CPT
     */
    public function register_meta_fields() {
        $meta_fields = $this->get_meta_fields_config();

        foreach ( $meta_fields as $meta_key => $config ) {
            register_post_meta(
                self::POST_TYPE,
                $meta_key,
                array(
                    'type'              => $config['type'],
                    'description'       => $config['description'],
                    'single'            => true,
                    'show_in_rest'      => $config['show_in_rest'],
                    'sanitize_callback' => $config['sanitize_callback'],
                    'auth_callback'     => function() {
                        return current_user_can( 'edit_posts' );
                    },
                )
            );
        }
    }

    /**
     * Get meta fields configuration
     *
     * @return array
     */
    private function get_meta_fields_config() {
        return array(
            // ===== RELACIÓN Y BÁSICOS =====
            'parent_business_id'              => array(
                'type'              => 'integer',
                'description'       => 'ID del negocio padre (oy_business)',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'program_name'                    => array(
                'type'              => 'string',
                'description'       => 'Nombre del programa de lealtad',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'program_slug'                    => array(
                'type'              => 'string',
                'description'       => 'Slug único del programa',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_title',
            ),
            'program_description'             => array(
                'type'              => 'string',
                'description'       => 'Descripción completa del programa',
                'show_in_rest'      => true,
                'sanitize_callback' => 'wp_kses_post',
            ),
            'program_short_description'       => array(
                'type'              => 'string',
                'description'       => 'Descripción breve (160 caracteres)',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'program_terms'                   => array(
                'type'              => 'string',
                'description'       => 'Términos y condiciones (HTML)',
                'show_in_rest'      => true,
                'sanitize_callback' => 'wp_kses_post',
            ),
            'program_privacy_policy'          => array(
                'type'              => 'string',
                'description'       => 'Política de privacidad del programa',
                'show_in_rest'      => true,
                'sanitize_callback' => 'wp_kses_post',
            ),

            // ===== TIPO Y ESTRUCTURA =====
            'program_type'                    => array(
                'type'              => 'string',
                'description'       => 'Tipo: points, stamps, visits, cashback, tiered, hybrid',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'program_subtype'                 => array(
                'type'              => 'string',
                'description'       => 'Subtipo: earn_and_burn, tiered_benefits, subscription',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'program_currency_name'           => array(
                'type'              => 'string',
                'description'       => 'Nombre de la moneda (singular)',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'program_currency_plural'         => array(
                'type'              => 'string',
                'description'       => 'Nombre de la moneda (plural)',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'program_icon'                    => array(
                'type'              => 'string',
                'description'       => 'Icono del programa',
                'show_in_rest'      => true,
                'sanitize_callback' => 'esc_url_raw',
            ),

            // ===== ALCANCE GEOGRÁFICO (CAMPO CRÍTICO) =====
            'program_scope'                   => array(
                'type'              => 'string',
                'description'       => 'Alcance: global, specific_locations, single_location',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'applicable_locations'            => array(
                'type'              => 'array',
                'description'       => 'Array de IDs de oy_location',
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'integer',
                        ),
                    ),
                ),
                'sanitize_callback' => array( $this, 'sanitize_array_of_integers' ),
            ),

            // ===== MECÁNICA DE PUNTOS/RECOMPENSAS =====
            'points_per_currency'             => array(
                'type'              => 'number',
                'description'       => 'Puntos por cada unidad monetaria gastada',
                'show_in_rest'      => true,
                'sanitize_callback' => 'floatval',
            ),
            'min_purchase_for_points'         => array(
                'type'              => 'number',
                'description'       => 'Compra mínima para ganar puntos',
                'show_in_rest'      => true,
                'sanitize_callback' => 'floatval',
            ),
            'points_rounding'                 => array(
                'type'              => 'string',
                'description'       => 'Redondeo: round_up, round_down, round_nearest',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'cashback_percentage'             => array(
                'type'              => 'number',
                'description'       => 'Porcentaje de cashback',
                'show_in_rest'      => true,
                'sanitize_callback' => 'floatval',
            ),
            'visit_threshold'                 => array(
                'type'              => 'integer',
                'description'       => 'Visitas necesarias para recompensa',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),

            // ===== NIVELES/TIERS =====
            'is_tiered_program'               => array(
                'type'              => 'boolean',
                'description'       => 'Si el programa tiene niveles',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'tiers_configuration'             => array(
                'type'              => 'string',
                'description'       => 'Configuración de niveles (JSON)',
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitize_json' ),
            ),
            'tier_retention_period'           => array(
                'type'              => 'integer',
                'description'       => 'Período para mantener nivel (meses)',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'tier_downgrade_enabled'          => array(
                'type'              => 'boolean',
                'description'       => 'Permite bajar de nivel',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),

            // ===== RECOMPENSAS Y UMBRALES =====
            'reward_threshold'                => array(
                'type'              => 'integer',
                'description'       => 'Puntos para primera recompensa',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'reward_catalog'                  => array(
                'type'              => 'string',
                'description'       => 'Catálogo de recompensas (JSON)',
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitize_json' ),
            ),
            'rewards_expiry_days'             => array(
                'type'              => 'integer',
                'description'       => 'Días para usar recompensa canjeada',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'auto_apply_rewards'              => array(
                'type'              => 'boolean',
                'description'       => 'Aplicar recompensas automáticamente',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),

            // ===== EXPIRACIÓN Y CADUCIDAD =====
            'points_expiry_enabled'           => array(
                'type'              => 'boolean',
                'description'       => 'Habilitar expiración de puntos',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'points_expiry_months'            => array(
                'type'              => 'integer',
                'description'       => 'Meses para expiración de puntos',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'points_expiry_warning_days'      => array(
                'type'              => 'integer',
                'description'       => 'Días antes para avisar expiración',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'account_inactivity_months'       => array(
                'type'              => 'integer',
                'description'       => 'Meses de inactividad antes de suspender',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'expiration_policy_text'          => array(
                'type'              => 'string',
                'description'       => 'Texto de política de expiración',
                'show_in_rest'      => true,
                'sanitize_callback' => 'wp_kses_post',
            ),

            // ===== DISEÑO DE TARJETA =====
            'card_design_template'            => array(
                'type'              => 'string',
                'description'       => 'Plantilla: default, minimal, premium, custom',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'card_background_color'           => array(
                'type'              => 'string',
                'description'       => 'Color de fondo (#HEX)',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_hex_color',
            ),
            'card_text_color'                 => array(
                'type'              => 'string',
                'description'       => 'Color de texto (#HEX)',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_hex_color',
            ),
            'card_accent_color'               => array(
                'type'              => 'string',
                'description'       => 'Color de acento (#HEX)',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_hex_color',
            ),
            'card_background_image'           => array(
                'type'              => 'string',
                'description'       => 'URL imagen de fondo',
                'show_in_rest'      => true,
                'sanitize_callback' => 'esc_url_raw',
            ),
            'card_background_image_id'        => array(
                'type'              => 'integer',
                'description'       => 'Media ID de imagen de fondo',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'card_logo_position'              => array(
                'type'              => 'string',
                'description'       => 'Posición logo: top_left, top_center, top_right, center',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'card_show_barcode'               => array(
                'type'              => 'boolean',
                'description'       => 'Mostrar código de barras',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'card_barcode_type'               => array(
                'type'              => 'string',
                'description'       => 'Tipo: qr_code, aztec, code128, pdf417',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),

            // ===== INTEGRACIÓN GOOGLE WALLET =====
            'google_class_id'                 => array(
                'type'              => 'string',
                'description'       => 'ID de LoyaltyClass en Google',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'google_class_json'               => array(
                'type'              => 'string',
                'description'       => 'JSON completo de la clase de Google',
                'show_in_rest'      => false,
                'sanitize_callback' => array( $this, 'sanitize_json' ),
            ),
            'google_class_created_date'       => array(
                'type'              => 'string',
                'description'       => 'Fecha de creación en Google',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'google_class_last_updated'       => array(
                'type'              => 'string',
                'description'       => 'Última actualización en Google',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'google_issuer_name'              => array(
                'type'              => 'string',
                'description'       => 'Nombre del emisor en Google',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'google_program_logo'             => array(
                'type'              => 'string',
                'description'       => 'Logo para Google Wallet',
                'show_in_rest'      => true,
                'sanitize_callback' => 'esc_url_raw',
            ),
            'google_hero_image'               => array(
                'type'              => 'string',
                'description'       => 'Imagen hero para Google Wallet',
                'show_in_rest'      => true,
                'sanitize_callback' => 'esc_url_raw',
            ),
            'google_enable_smart_tap'         => array(
                'type'              => 'boolean',
                'description'       => 'Habilitar Smart Tap (NFC)',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),

            // ===== INTEGRACIÓN APPLE WALLET =====
            'apple_pass_type_id'              => array(
                'type'              => 'string',
                'description'       => 'Pass Type ID de Apple',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'apple_team_id'                   => array(
                'type'              => 'string',
                'description'       => 'Team ID de Apple',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'apple_certificate'               => array(
                'type'              => 'string',
                'description'       => 'Certificado para firmar passes',
                'show_in_rest'      => false,
                'sanitize_callback' => 'sanitize_textarea_field',
            ),
            'apple_pass_style'                => array(
                'type'              => 'string',
                'description'       => 'Estilo: generic, storeCard, coupon',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'apple_enable_location_updates'   => array(
                'type'              => 'boolean',
                'description'       => 'Actualizar con ubicación',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),

            // ===== BENEFICIOS Y VENTAJAS =====
            'welcome_bonus_points'            => array(
                'type'              => 'integer',
                'description'       => 'Puntos de bienvenida al inscribirse',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'referral_bonus_points'           => array(
                'type'              => 'integer',
                'description'       => 'Puntos por referir amigo',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'birthday_bonus_enabled'          => array(
                'type'              => 'boolean',
                'description'       => 'Bonus de cumpleaños habilitado',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'birthday_bonus_amount'           => array(
                'type'              => 'integer',
                'description'       => 'Puntos/descuento de cumpleaños',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'exclusive_benefits'              => array(
                'type'              => 'string',
                'description'       => 'Beneficios exclusivos (JSON)',
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitize_json' ),
            ),
            'early_access_enabled'            => array(
                'type'              => 'boolean',
                'description'       => 'Acceso anticipado habilitado',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),

            // ===== CONFIGURACIÓN DE INSCRIPCIÓN =====
            'enrollment_enabled'              => array(
                'type'              => 'boolean',
                'description'       => 'Aceptando nuevos miembros',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'enrollment_method'               => array(
                'type'              => 'string',
                'description'       => 'Método: auto, manual, invite_only',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'enrollment_fee'                  => array(
                'type'              => 'number',
                'description'       => 'Costo de inscripción (0 = gratis)',
                'show_in_rest'      => true,
                'sanitize_callback' => 'floatval',
            ),
            'enrollment_requires_approval'    => array(
                'type'              => 'boolean',
                'description'       => 'Requiere aprobación',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'enrollment_fields_required'      => array(
                'type'              => 'array',
                'description'       => 'Array de campos obligatorios',
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'string',
                        ),
                    ),
                ),
                'sanitize_callback' => array( $this, 'sanitize_array_of_strings' ),
            ),
            'enrollment_welcome_email'        => array(
                'type'              => 'boolean',
                'description'       => 'Enviar email de bienvenida',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'enrollment_welcome_message'      => array(
                'type'              => 'string',
                'description'       => 'Texto del mensaje de bienvenida',
                'show_in_rest'      => true,
                'sanitize_callback' => 'wp_kses_post',
            ),

            // ===== LÍMITES Y RESTRICCIONES =====
            'max_cards_per_user'              => array(
                'type'              => 'integer',
                'description'       => 'Máx tarjetas por usuario',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'max_points_per_day'              => array(
                'type'              => 'integer',
                'description'       => 'Máximo de puntos ganables por día',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'max_points_per_transaction'      => array(
                'type'              => 'integer',
                'description'       => 'Máximo de puntos por transacción',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'max_redemptions_per_day'         => array(
                'type'              => 'integer',
                'description'       => 'Máximas redenciones por día',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'min_points_for_redemption'       => array(
                'type'              => 'integer',
                'description'       => 'Puntos mínimos para canjear',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'redemption_increments'           => array(
                'type'              => 'integer',
                'description'       => 'Incrementos de canje',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),

            // ===== NOTIFICACIONES Y COMUNICACIÓN =====
            'notifications_enabled'           => array(
                'type'              => 'boolean',
                'description'       => 'Sistema de notificaciones habilitado',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'notify_points_earned'            => array(
                'type'              => 'boolean',
                'description'       => 'Notificar al ganar puntos',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'notify_points_redeemed'          => array(
                'type'              => 'boolean',
                'description'       => 'Notificar al canjear',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'notify_expiring_points'          => array(
                'type'              => 'boolean',
                'description'       => 'Notificar puntos próximos a expirar',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'notify_tier_upgrade'             => array(
                'type'              => 'boolean',
                'description'       => 'Notificar cambio de nivel',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'notify_new_rewards'              => array(
                'type'              => 'boolean',
                'description'       => 'Notificar nuevas recompensas',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'notification_channels'           => array(
                'type'              => 'array',
                'description'       => 'Canales: email, sms, push, in_app',
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'string',
                        ),
                    ),
                ),
                'sanitize_callback' => array( $this, 'sanitize_array_of_strings' ),
            ),

            // ===== PROMOCIONES Y CAMPAÑAS =====
            'active_promotions'               => array(
                'type'              => 'string',
                'description'       => 'Promociones activas (JSON)',
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitize_json' ),
            ),
            'seasonal_bonuses'                => array(
                'type'              => 'string',
                'description'       => 'Bonos por temporada (JSON)',
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitize_json' ),
            ),
            'double_points_days'              => array(
                'type'              => 'array',
                'description'       => 'Días con puntos dobles',
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'string',
                        ),
                    ),
                ),
                'sanitize_callback' => array( $this, 'sanitize_array_of_strings' ),
            ),
            'special_events'                  => array(
                'type'              => 'string',
                'description'       => 'Eventos especiales del programa (JSON)',
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitize_json' ),
            ),

            // ===== INTEGRACIÓN CON ECOMMERCE =====
            'woocommerce_enabled'             => array(
                'type'              => 'boolean',
                'description'       => 'Integración con WooCommerce',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'woocommerce_product_categories'  => array(
                'type'              => 'array',
                'description'       => 'Categorías que otorgan puntos',
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'integer',
                        ),
                    ),
                ),
                'sanitize_callback' => array( $this, 'sanitize_array_of_integers' ),
            ),
            'woocommerce_excluded_products'   => array(
                'type'              => 'array',
                'description'       => 'Productos excluidos',
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'integer',
                        ),
                    ),
                ),
                'sanitize_callback' => array( $this, 'sanitize_array_of_integers' ),
            ),
            'points_as_payment_enabled'       => array(
                'type'              => 'boolean',
                'description'       => 'Usar puntos como pago',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'points_to_currency_rate'         => array(
                'type'              => 'number',
                'description'       => 'Tasa de conversión puntos a moneda',
                'show_in_rest'      => true,
                'sanitize_callback' => 'floatval',
            ),

            // ===== GAMIFICACIÓN =====
            'achievements_enabled'            => array(
                'type'              => 'boolean',
                'description'       => 'Sistema de logros habilitado',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'achievements_list'               => array(
                'type'              => 'string',
                'description'       => 'Lista de logros (JSON)',
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitize_json' ),
            ),
            'leaderboard_enabled'             => array(
                'type'              => 'boolean',
                'description'       => 'Tabla de clasificación habilitada',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'badges_enabled'                  => array(
                'type'              => 'boolean',
                'description'       => 'Sistema de insignias habilitado',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'challenges_enabled'              => array(
                'type'              => 'boolean',
                'description'       => 'Desafíos periódicos habilitados',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),

            // ===== PROGRAMA DE REFERIDOS =====
            'referral_program_enabled'        => array(
                'type'              => 'boolean',
                'description'       => 'Programa de referidos habilitado',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'referral_reward_referrer'        => array(
                'type'              => 'integer',
                'description'       => 'Puntos para quien refiere',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'referral_reward_referee'         => array(
                'type'              => 'integer',
                'description'       => 'Puntos para quien se inscribe',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'referral_code_format'            => array(
                'type'              => 'string',
                'description'       => 'Formato del código de referido',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'max_referrals_per_user'          => array(
                'type'              => 'integer',
                'description'       => 'Máximo de referidos por usuario',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),

            // ===== ESTADÍSTICAS DEL PROGRAMA =====
            'total_members'                   => array(
                'type'              => 'integer',
                'description'       => 'Total de miembros activos',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'total_cards_issued'              => array(
                'type'              => 'integer',
                'description'       => 'Total de tarjetas emitidas',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'total_points_issued'             => array(
                'type'              => 'integer',
                'description'       => 'Total de puntos emitidos',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'total_points_redeemed'           => array(
                'type'              => 'integer',
                'description'       => 'Total de puntos canjeados',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'total_revenue_generated'         => array(
                'type'              => 'number',
                'description'       => 'Ingresos generados por el programa',
                'show_in_rest'      => true,
                'sanitize_callback' => 'floatval',
            ),
            'average_member_value'            => array(
                'type'              => 'number',
                'description'       => 'Valor promedio por miembro',
                'show_in_rest'      => true,
                'sanitize_callback' => 'floatval',
            ),
            'member_retention_rate'           => array(
                'type'              => 'number',
                'description'       => 'Tasa de retención (%)',
                'show_in_rest'      => true,
                'sanitize_callback' => 'floatval',
            ),
            'active_members_30_days'          => array(
                'type'              => 'integer',
                'description'       => 'Miembros activos últimos 30 días',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),

            // ===== CONFIGURACIÓN DE SEGURIDAD =====
            'fraud_detection_enabled'         => array(
                'type'              => 'boolean',
                'description'       => 'Detección de fraude habilitada',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'max_points_transfer'             => array(
                'type'              => 'integer',
                'description'       => 'Puntos máx transferibles entre usuarios',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'allow_point_gifting'             => array(
                'type'              => 'boolean',
                'description'       => 'Permitir regalar puntos',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'require_pin_for_redemption'      => array(
                'type'              => 'boolean',
                'description'       => 'PIN para canjear',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'require_location_for_redemption' => array(
                'type'              => 'boolean',
                'description'       => 'Verificar ubicación al canjear',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),

            // ===== METADATOS DEL SISTEMA =====
            'program_status'                  => array(
                'type'              => 'string',
                'description'       => 'Estado: active, inactive, draft, archived',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'is_featured'                     => array(
                'type'              => 'boolean',
                'description'       => 'Programa destacado',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'launch_date'                     => array(
                'type'              => 'string',
                'description'       => 'Fecha de lanzamiento',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'end_date'                        => array(
                'type'              => 'string',
                'description'       => 'Fecha de finalización',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'created_by_user_id'              => array(
                'type'              => 'integer',
                'description'       => 'WP User que creó',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'date_created'                    => array(
                'type'              => 'string',
                'description'       => 'Fecha de creación',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'date_modified'                   => array(
                'type'              => 'string',
                'description'       => 'Última modificación',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'version'                         => array(
                'type'              => 'string',
                'description'       => 'Versión del programa',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),

            // ===== LEGAL Y COMPLIANCE =====
            'program_legal_entity'            => array(
                'type'              => 'string',
                'description'       => 'Entidad legal responsable',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'tax_reporting_enabled'           => array(
                'type'              => 'boolean',
                'description'       => 'Requiere reporte fiscal',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'data_retention_months'           => array(
                'type'              => 'integer',
                'description'       => 'Meses de retención de datos',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
            ),
            'gdpr_compliant'                  => array(
                'type'              => 'boolean',
                'description'       => 'Cumple con GDPR',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'privacy_policy_url'              => array(
                'type'              => 'string',
                'description'       => 'URL de política de privacidad',
                'show_in_rest'      => true,
                'sanitize_callback' => 'esc_url_raw',
            ),
            'terms_last_updated'              => array(
                'type'              => 'string',
                'description'       => 'Fecha última actualización de términos',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'requires_terms_acceptance'       => array(
                'type'              => 'boolean',
                'description'       => 'Requiere aceptar T&C',
                'show_in_rest'      => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
        );
    }

    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        // Información Básica
        add_meta_box(
            'loyalty_program_basic_info',
            __( 'Información Básica del Programa', 'lealez' ),
            array( $this, 'render_basic_info_meta_box' ),
            self::POST_TYPE,
            'normal',
            'high'
        );

        // Alcance Geográfico
        add_meta_box(
            'loyalty_program_scope',
            __( 'Alcance Geográfico', 'lealez' ),
            array( $this, 'render_scope_meta_box' ),
            self::POST_TYPE,
            'normal',
            'high'
        );

        // Mecánica de Puntos
        add_meta_box(
            'loyalty_program_points_mechanics',
            __( 'Mecánica de Puntos/Recompensas', 'lealez' ),
            array( $this, 'render_points_mechanics_meta_box' ),
            self::POST_TYPE,
            'normal',
            'default'
        );

        // Diseño de Tarjeta
        add_meta_box(
            'loyalty_program_card_design',
            __( 'Diseño de Tarjeta', 'lealez' ),
            array( $this, 'render_card_design_meta_box' ),
            self::POST_TYPE,
            'side',
            'default'
        );

        // Estadísticas
        add_meta_box(
            'loyalty_program_statistics',
            __( 'Estadísticas del Programa', 'lealez' ),
            array( $this, 'render_statistics_meta_box' ),
            self::POST_TYPE,
            'side',
            'low'
        );
    }

    /**
     * Render Basic Info meta box
     */
    public function render_basic_info_meta_box( $post ) {
        wp_nonce_field( 'loyalty_program_meta_box', 'loyalty_program_meta_box_nonce' );

        $parent_business_id = get_post_meta( $post->ID, 'parent_business_id', true );
        $program_type       = get_post_meta( $post->ID, 'program_type', true );
        $program_status     = get_post_meta( $post->ID, 'program_status', true );
        $currency_name      = get_post_meta( $post->ID, 'program_currency_name', true );
        $currency_plural    = get_post_meta( $post->ID, 'program_currency_plural', true );

        // Get all businesses
        $businesses = get_posts(
            array(
                'post_type'      => 'oy_business',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );

        ?>
        <table class="form-table">
            <tr>
                <th><label for="parent_business_id"><?php esc_html_e( 'Empresa/Negocio', 'lealez' ); ?></label></th>
                <td>
                    <select name="parent_business_id" id="parent_business_id" class="regular-text" required>
                        <option value=""><?php esc_html_e( 'Seleccionar Empresa', 'lealez' ); ?></option>
                        <?php foreach ( $businesses as $business ) : ?>
                            <option value="<?php echo esc_attr( $business->ID ); ?>" <?php selected( $parent_business_id, $business->ID ); ?>>
                                <?php echo esc_html( get_the_title( $business->ID ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Selecciona la empresa a la que pertenece este programa.', 'lealez' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="program_type"><?php esc_html_e( 'Tipo de Programa', 'lealez' ); ?></label></th>
                <td>
                    <select name="program_type" id="program_type" class="regular-text">
                        <option value="points" <?php selected( $program_type, 'points' ); ?>><?php esc_html_e( 'Puntos', 'lealez' ); ?></option>
                        <option value="stamps" <?php selected( $program_type, 'stamps' ); ?>><?php esc_html_e( 'Sellos', 'lealez' ); ?></option>
                        <option value="visits" <?php selected( $program_type, 'visits' ); ?>><?php esc_html_e( 'Visitas', 'lealez' ); ?></option>
                        <option value="cashback" <?php selected( $program_type, 'cashback' ); ?>><?php esc_html_e( 'Cashback', 'lealez' ); ?></option>
                        <option value="tiered" <?php selected( $program_type, 'tiered' ); ?>><?php esc_html_e( 'Por Niveles', 'lealez' ); ?></option>
                        <option value="hybrid" <?php selected( $program_type, 'hybrid' ); ?>><?php esc_html_e( 'Híbrido', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>

            <tr>
                <th><label for="program_currency_name"><?php esc_html_e( 'Nombre de Moneda (Singular)', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" name="program_currency_name" id="program_currency_name" value="<?php echo esc_attr( $currency_name ); ?>" class="regular-text" placeholder="Punto, Estrella, Milla">
                    <p class="description"><?php esc_html_e( 'Ej: Punto, Estrella, Milla', 'lealez' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="program_currency_plural"><?php esc_html_e( 'Nombre de Moneda (Plural)', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" name="program_currency_plural" id="program_currency_plural" value="<?php echo esc_attr( $currency_plural ); ?>" class="regular-text" placeholder="Puntos, Estrellas, Millas">
                    <p class="description"><?php esc_html_e( 'Ej: Puntos, Estrellas, Millas', 'lealez' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="program_status"><?php esc_html_e( 'Estado del Programa', 'lealez' ); ?></label></th>
                <td>
                    <select name="program_status" id="program_status" class="regular-text">
                        <option value="active" <?php selected( $program_status, 'active' ); ?>><?php esc_html_e( 'Activo', 'lealez' ); ?></option>
                        <option value="inactive" <?php selected( $program_status, 'inactive' ); ?>><?php esc_html_e( 'Inactivo', 'lealez' ); ?></option>
                        <option value="draft" <?php selected( $program_status, 'draft' ); ?>><?php esc_html_e( 'Borrador', 'lealez' ); ?></option>
                        <option value="archived" <?php selected( $program_status, 'archived' ); ?>><?php esc_html_e( 'Archivado', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Scope meta box (CAMPO CRÍTICO)
     */
    public function render_scope_meta_box( $post ) {
        $program_scope         = get_post_meta( $post->ID, 'program_scope', true );
        $applicable_locations  = get_post_meta( $post->ID, 'applicable_locations', true );
        $parent_business_id    = get_post_meta( $post->ID, 'parent_business_id', true );

        if ( ! is_array( $applicable_locations ) ) {
            $applicable_locations = array();
        }

        // Get locations from parent business
        $locations = array();
        if ( $parent_business_id ) {
            $locations = get_posts(
                array(
                    'post_type'      => 'oy_location',
                    'posts_per_page' => -1,
                    'meta_query'     => array(
                        array(
                            'key'     => 'parent_business_id',
                            'value'   => $parent_business_id,
                            'compare' => '=',
                        ),
                    ),
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                )
            );
        }

        ?>
        <div class="loyalty-scope-settings">
            <p class="description" style="margin-bottom: 15px;">
                <strong><?php esc_html_e( 'Define dónde es válido este programa de lealtad:', 'lealez' ); ?></strong>
            </p>

            <p>
                <label>
                    <input type="radio" name="program_scope" value="global" <?php checked( $program_scope, 'global' ); ?> class="program-scope-radio">
                    <strong><?php esc_html_e( 'Global', 'lealez' ); ?></strong> - <?php esc_html_e( 'Válido en todas las ubicaciones del negocio', 'lealez' ); ?>
                </label>
            </p>

            <p>
                <label>
                    <input type="radio" name="program_scope" value="specific_locations" <?php checked( $program_scope, 'specific_locations' ); ?> class="program-scope-radio">
                    <strong><?php esc_html_e( 'Ubicaciones Específicas', 'lealez' ); ?></strong> - <?php esc_html_e( 'Selecciona las ubicaciones donde aplica', 'lealez' ); ?>
                </label>
            </p>

            <p>
                <label>
                    <input type="radio" name="program_scope" value="single_location" <?php checked( $program_scope, 'single_location' ); ?> class="program-scope-radio">
                    <strong><?php esc_html_e( 'Una Sola Ubicación', 'lealez' ); ?></strong> - <?php esc_html_e( 'Válido solo en una ubicación', 'lealez' ); ?>
                </label>
            </p>

            <div id="locations-selector" style="margin-top: 20px; <?php echo ( 'global' === $program_scope ) ? 'display:none;' : ''; ?>">
                <hr>
                <h4><?php esc_html_e( 'Selecciona las Ubicaciones', 'lealez' ); ?></h4>
                <?php if ( ! empty( $locations ) ) : ?>
                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                        <?php foreach ( $locations as $location ) : ?>
                            <p>
                                <label>
                                    <input type="checkbox" name="applicable_locations[]" value="<?php echo esc_attr( $location->ID ); ?>" <?php checked( in_array( $location->ID, $applicable_locations, true ) ); ?>>
                                    <?php echo esc_html( get_the_title( $location->ID ) ); ?>
                                    <?php
                                    $city = get_post_meta( $location->ID, 'location_city', true );
                                    if ( $city ) {
                                        echo ' - ' . esc_html( $city );
                                    }
                                    ?>
                                </label>
                            </p>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <p class="description"><?php esc_html_e( 'No hay ubicaciones disponibles. Por favor, crea ubicaciones primero o selecciona una empresa.', 'lealez' ); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.program-scope-radio').on('change', function() {
                if ($(this).val() === 'global') {
                    $('#locations-selector').slideUp();
                } else {
                    $('#locations-selector').slideDown();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render Points Mechanics meta box
     */
    public function render_points_mechanics_meta_box( $post ) {
        $points_per_currency      = get_post_meta( $post->ID, 'points_per_currency', true );
        $min_purchase_for_points  = get_post_meta( $post->ID, 'min_purchase_for_points', true );
        $points_rounding          = get_post_meta( $post->ID, 'points_rounding', true );
        $welcome_bonus_points     = get_post_meta( $post->ID, 'welcome_bonus_points', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="points_per_currency"><?php esc_html_e( 'Puntos por Unidad Monetaria', 'lealez' ); ?></label></th>
                <td>
                    <input type="number" step="0.01" name="points_per_currency" id="points_per_currency" value="<?php echo esc_attr( $points_per_currency ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Ej: 1 = 1 punto por cada $1 gastado', 'lealez' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="min_purchase_for_points"><?php esc_html_e( 'Compra Mínima para Puntos', 'lealez' ); ?></label></th>
                <td>
                    <input type="number" step="0.01" name="min_purchase_for_points" id="min_purchase_for_points" value="<?php echo esc_attr( $min_purchase_for_points ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Monto mínimo de compra para ganar puntos', 'lealez' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="points_rounding"><?php esc_html_e( 'Redondeo de Puntos', 'lealez' ); ?></label></th>
                <td>
                    <select name="points_rounding" id="points_rounding" class="regular-text">
                        <option value="round_up" <?php selected( $points_rounding, 'round_up' ); ?>><?php esc_html_e( 'Redondear hacia arriba', 'lealez' ); ?></option>
                        <option value="round_down" <?php selected( $points_rounding, 'round_down' ); ?>><?php esc_html_e( 'Redondear hacia abajo', 'lealez' ); ?></option>
                        <option value="round_nearest" <?php selected( $points_rounding, 'round_nearest' ); ?>><?php esc_html_e( 'Redondear al más cercano', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>

            <tr>
                <th><label for="welcome_bonus_points"><?php esc_html_e( 'Puntos de Bienvenida', 'lealez' ); ?></label></th>
                <td>
                    <input type="number" name="welcome_bonus_points" id="welcome_bonus_points" value="<?php echo esc_attr( $welcome_bonus_points ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Puntos otorgados al inscribirse al programa', 'lealez' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Card Design meta box
     */
    public function render_card_design_meta_box( $post ) {
        $card_design_template   = get_post_meta( $post->ID, 'card_design_template', true );
        $card_background_color  = get_post_meta( $post->ID, 'card_background_color', true );
        $card_text_color        = get_post_meta( $post->ID, 'card_text_color', true );
        $card_show_barcode      = get_post_meta( $post->ID, 'card_show_barcode', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="card_design_template"><?php esc_html_e( 'Plantilla', 'lealez' ); ?></label></th>
                <td>
                    <select name="card_design_template" id="card_design_template" class="widefat">
                        <option value="default" <?php selected( $card_design_template, 'default' ); ?>><?php esc_html_e( 'Predeterminada', 'lealez' ); ?></option>
                        <option value="minimal" <?php selected( $card_design_template, 'minimal' ); ?>><?php esc_html_e( 'Minimalista', 'lealez' ); ?></option>
                        <option value="premium" <?php selected( $card_design_template, 'premium' ); ?>><?php esc_html_e( 'Premium', 'lealez' ); ?></option>
                        <option value="custom" <?php selected( $card_design_template, 'custom' ); ?>><?php esc_html_e( 'Personalizada', 'lealez' ); ?></option>
                    </select>
                </td>
            </tr>

            <tr>
                <th><label for="card_background_color"><?php esc_html_e( 'Color de Fondo', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" name="card_background_color" id="card_background_color" value="<?php echo esc_attr( $card_background_color ); ?>" class="color-picker" data-default-color="#0073aa">
                </td>
            </tr>

            <tr>
                <th><label for="card_text_color"><?php esc_html_e( 'Color de Texto', 'lealez' ); ?></label></th>
                <td>
                    <input type="text" name="card_text_color" id="card_text_color" value="<?php echo esc_attr( $card_text_color ); ?>" class="color-picker" data-default-color="#ffffff">
                </td>
            </tr>

            <tr>
                <th><label for="card_show_barcode"><?php esc_html_e( 'Mostrar Código de Barras', 'lealez' ); ?></label></th>
                <td>
                    <input type="checkbox" name="card_show_barcode" id="card_show_barcode" value="1" <?php checked( $card_show_barcode, '1' ); ?>>
                </td>
            </tr>
        </table>

        <script>
        jQuery(document).ready(function($) {
            $('.color-picker').wpColorPicker();
        });
        </script>
        <?php
    }

    /**
     * Render Statistics meta box
     */
    public function render_statistics_meta_box( $post ) {
        $total_members       = get_post_meta( $post->ID, 'total_members', true );
        $total_cards_issued  = get_post_meta( $post->ID, 'total_cards_issued', true );
        $total_points_issued = get_post_meta( $post->ID, 'total_points_issued', true );
        ?>
        <div class="loyalty-stats">
            <p><strong><?php esc_html_e( 'Total Miembros:', 'lealez' ); ?></strong> <?php echo esc_html( $total_members ? number_format( $total_members ) : '0' ); ?></p>
            <p><strong><?php esc_html_e( 'Tarjetas Emitidas:', 'lealez' ); ?></strong> <?php echo esc_html( $total_cards_issued ? number_format( $total_cards_issued ) : '0' ); ?></p>
            <p><strong><?php esc_html_e( 'Puntos Emitidos:', 'lealez' ); ?></strong> <?php echo esc_html( $total_points_issued ? number_format( $total_points_issued ) : '0' ); ?></p>
        </div>
        <?php
    }

    /**
     * Save meta boxes data
     */
    public function save_meta_boxes( $post_id, $post ) {
        // Verify nonce
        if ( ! isset( $_POST['loyalty_program_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['loyalty_program_meta_box_nonce'], 'loyalty_program_meta_box' ) ) {
            return;
        }

        // Check autosave
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Check permissions
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Save parent_business_id
        if ( isset( $_POST['parent_business_id'] ) ) {
            update_post_meta( $post_id, 'parent_business_id', absint( $_POST['parent_business_id'] ) );
        }

        // Save program_type
        if ( isset( $_POST['program_type'] ) ) {
            update_post_meta( $post_id, 'program_type', sanitize_text_field( $_POST['program_type'] ) );
        }

        // Save program_status
        if ( isset( $_POST['program_status'] ) ) {
            update_post_meta( $post_id, 'program_status', sanitize_text_field( $_POST['program_status'] ) );
        }

        // Save currency names
        if ( isset( $_POST['program_currency_name'] ) ) {
            update_post_meta( $post_id, 'program_currency_name', sanitize_text_field( $_POST['program_currency_name'] ) );
        }
        if ( isset( $_POST['program_currency_plural'] ) ) {
            update_post_meta( $post_id, 'program_currency_plural', sanitize_text_field( $_POST['program_currency_plural'] ) );
        }

        // Save program_scope (CRÍTICO)
        if ( isset( $_POST['program_scope'] ) ) {
            $program_scope = sanitize_text_field( $_POST['program_scope'] );
            update_post_meta( $post_id, 'program_scope', $program_scope );

            // Save applicable_locations
            if ( 'global' !== $program_scope && isset( $_POST['applicable_locations'] ) ) {
                $locations = array_map( 'absint', $_POST['applicable_locations'] );
                update_post_meta( $post_id, 'applicable_locations', $locations );
            } else {
                // If global, clear specific locations
                delete_post_meta( $post_id, 'applicable_locations' );
            }
        }

        // Save points mechanics
        if ( isset( $_POST['points_per_currency'] ) ) {
            update_post_meta( $post_id, 'points_per_currency', floatval( $_POST['points_per_currency'] ) );
        }
        if ( isset( $_POST['min_purchase_for_points'] ) ) {
            update_post_meta( $post_id, 'min_purchase_for_points', floatval( $_POST['min_purchase_for_points'] ) );
        }
        if ( isset( $_POST['points_rounding'] ) ) {
            update_post_meta( $post_id, 'points_rounding', sanitize_text_field( $_POST['points_rounding'] ) );
        }
        if ( isset( $_POST['welcome_bonus_points'] ) ) {
            update_post_meta( $post_id, 'welcome_bonus_points', absint( $_POST['welcome_bonus_points'] ) );
        }

        // Save card design
        if ( isset( $_POST['card_design_template'] ) ) {
            update_post_meta( $post_id, 'card_design_template', sanitize_text_field( $_POST['card_design_template'] ) );
        }
        if ( isset( $_POST['card_background_color'] ) ) {
            update_post_meta( $post_id, 'card_background_color', sanitize_hex_color( $_POST['card_background_color'] ) );
        }
        if ( isset( $_POST['card_text_color'] ) ) {
            update_post_meta( $post_id, 'card_text_color', sanitize_hex_color( $_POST['card_text_color'] ) );
        }
        update_post_meta( $post_id, 'card_show_barcode', isset( $_POST['card_show_barcode'] ) ? '1' : '0' );

        // Auto-generate program_slug if not set
        $program_slug = get_post_meta( $post_id, 'program_slug', true );
        if ( empty( $program_slug ) ) {
            $program_slug = sanitize_title( $post->post_title );
            update_post_meta( $post_id, 'program_slug', $program_slug );
        }

        // Save creation metadata
        $date_created = get_post_meta( $post_id, 'date_created', true );
        if ( empty( $date_created ) ) {
            update_post_meta( $post_id, 'date_created', current_time( 'mysql' ) );
            update_post_meta( $post_id, 'created_by_user_id', get_current_user_id() );
        }

        // Update modification metadata
        update_post_meta( $post_id, 'date_modified', current_time( 'mysql' ) );
    }

    /**
     * Set custom columns for admin list
     */
    public function set_custom_columns( $columns ) {
        $new_columns = array(
            'cb'             => $columns['cb'],
            'title'          => __( 'Nombre del Programa', 'lealez' ),
            'business'       => __( 'Empresa', 'lealez' ),
            'program_type'   => __( 'Tipo', 'lealez' ),
            'program_scope'  => __( 'Alcance', 'lealez' ),
            'total_members'  => __( 'Miembros', 'lealez' ),
            'program_status' => __( 'Estado', 'lealez' ),
            'date'           => $columns['date'],
        );

        return $new_columns;
    }

    /**
     * Render custom column content
     */
    public function custom_column_content( $column, $post_id ) {
        switch ( $column ) {
            case 'business':
                $business_id = get_post_meta( $post_id, 'parent_business_id', true );
                if ( $business_id ) {
                    echo '<a href="' . esc_url( get_edit_post_link( $business_id ) ) . '">' . esc_html( get_the_title( $business_id ) ) . '</a>';
                } else {
                    echo '<span style="color: #999;">' . esc_html__( 'Sin empresa', 'lealez' ) . '</span>';
                }
                break;

            case 'program_type':
                $program_type = get_post_meta( $post_id, 'program_type', true );
                $types        = array(
                    'points'   => __( 'Puntos', 'lealez' ),
                    'stamps'   => __( 'Sellos', 'lealez' ),
                    'visits'   => __( 'Visitas', 'lealez' ),
                    'cashback' => __( 'Cashback', 'lealez' ),
                    'tiered'   => __( 'Niveles', 'lealez' ),
                    'hybrid'   => __( 'Híbrido', 'lealez' ),
                );
                echo isset( $types[ $program_type ] ) ? esc_html( $types[ $program_type ] ) : '—';
                break;

            case 'program_scope':
                $program_scope = get_post_meta( $post_id, 'program_scope', true );
                $scopes        = array(
                    'global'             => '<span style="color: #0073aa;">&#x25CF;</span> ' . __( 'Global', 'lealez' ),
                    'specific_locations' => '<span style="color: #ca4a1f;">&#x25CF;</span> ' . __( 'Específicas', 'lealez' ),
                    'single_location'    => '<span style="color: #7ad03a;">&#x25CF;</span> ' . __( 'Una ubicación', 'lealez' ),
                );
                echo isset( $scopes[ $program_scope ] ) ? wp_kses_post( $scopes[ $program_scope ] ) : '—';
                break;

            case 'total_members':
                $total_members = get_post_meta( $post_id, 'total_members', true );
                echo esc_html( $total_members ? number_format( $total_members ) : '0' );
                break;

            case 'program_status':
                $program_status = get_post_meta( $post_id, 'program_status', true );
                $statuses       = array(
                    'active'   => '<span style="color: #7ad03a;">&#x25CF;</span> ' . __( 'Activo', 'lealez' ),
                    'inactive' => '<span style="color: #999;">&#x25CF;</span> ' . __( 'Inactivo', 'lealez' ),
                    'draft'    => '<span style="color: #ca4a1f;">&#x25CF;</span> ' . __( 'Borrador', 'lealez' ),
                    'archived' => '<span style="color: #333;">&#x25CF;</span> ' . __( 'Archivado', 'lealez' ),
                );
                echo isset( $statuses[ $program_status ] ) ? wp_kses_post( $statuses[ $program_status ] ) : '—';
                break;
        }
    }

    /**
     * Set sortable columns
     */
    public function set_sortable_columns( $columns ) {
        $columns['business']       = 'parent_business_id';
        $columns['program_type']   = 'program_type';
        $columns['program_scope']  = 'program_scope';
        $columns['total_members']  = 'total_members';
        $columns['program_status'] = 'program_status';

        return $columns;
    }

    /**
     * Sanitize JSON data
     */
    public function sanitize_json( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        // If already JSON string, validate it
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                return wp_json_encode( $decoded );
            }
            return '';
        }

        // If array, encode it
        if ( is_array( $value ) ) {
            return wp_json_encode( $value );
        }

        return '';
    }

    /**
     * Sanitize array of integers
     */
    public function sanitize_array_of_integers( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }

        return array_map( 'absint', $value );
    }

    /**
     * Sanitize array of strings
     */
    public function sanitize_array_of_strings( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }

        return array_map( 'sanitize_text_field', $value );
    }
}

// Initialize the class
new OY_Loyalty_Program_CPT();
