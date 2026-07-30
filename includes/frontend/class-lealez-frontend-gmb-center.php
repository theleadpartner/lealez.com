<?php
/**
 * Frontend Google Business Profile center.
 *
 * Reuses the exact metabox callbacks, AJAX handlers, push jobs and status
 * verification flows already used by the WordPress administration screens.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Lealez_Frontend_GMB_Center' ) ) :

class Lealez_Frontend_GMB_Center {

    const PAGE_OPTION = 'lealez_frontend_page_ids';

    /** @var array<string,bool> */
    private $registered_metaboxes = array();

    /** @var array<string,bool> */
    private $invoked_asset_methods = array();

    /** @var array<int,array<string,mixed>> */
    private $footer_asset_callbacks = array();

    /** @var array<string,mixed>|null */
    private $active_page_context = null;

    public function __construct() {
        add_action( 'init', array( $this, 'register_shortcodes' ), 20 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_page_assets' ), 20 );
        add_action( 'wp_footer', array( $this, 'render_deferred_footer_assets' ), 5 );
        add_action( 'admin_post_lealez_frontend_save_gmb_metabox', array( $this, 'handle_classic_metabox_save' ) );

        add_filter( 'do_shortcode_tag', array( $this, 'enhance_existing_portal_screens' ), 20, 4 );
        add_filter( 'map_meta_cap', array( $this, 'map_portal_post_capabilities' ), 20, 4 );
        add_filter( 'user_has_cap', array( $this, 'grant_scoped_gmb_capabilities' ), 20, 4 );
    }

    public function register_shortcodes() {
        add_shortcode( 'lealez_business_google_center', array( $this, 'shortcode_business_google_center' ) );
        add_shortcode( 'lealez_location_google_center', array( $this, 'shortcode_location_google_center' ) );
    }

    /**
     * Frontend page assets are loaded before wp_head whenever one of the
     * generated Google center pages is being viewed.
     */
    public function enqueue_page_assets() {
        if ( ! $this->is_google_center_page() ) {
            return;
        }

        $this->enqueue_common_assets();

        $page_ids = $this->get_page_ids();
        $page_id  = get_queried_object_id();

        if ( ! empty( $page_ids['location_google'] ) && (int) $page_ids['location_google'] === (int) $page_id ) {
            $location_id = isset( $_GET['location_id'] ) ? absint( wp_unslash( $_GET['location_id'] ) ) : 0;
            $module      = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : 'overview';
            if ( $location_id && $this->can_access_location( $location_id ) ) {
                $this->prepare_module_assets( 'oy_location', $location_id, $module );
            }
        }

        if ( ! empty( $page_ids['business_google'] ) && (int) $page_ids['business_google'] === (int) $page_id ) {
            $business_id = isset( $_GET['business_id'] ) ? absint( wp_unslash( $_GET['business_id'] ) ) : 0;
            $module      = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : 'snapshot';
            if ( $business_id && $this->can_access_business( $business_id ) ) {
                $this->prepare_module_assets( 'oy_business', $business_id, $module );
            }
        }
    }

    private function enqueue_common_assets() {
        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'jquery-ui-sortable' );
        wp_enqueue_style( 'dashicons' );

        if ( function_exists( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }

        // Several existing metaboxes attach their inline CSS to the wp-admin
        // handle. On frontend we register a safe compatibility stylesheet under
        // the same handle so those exact rules are printed normally.
        if ( ! wp_style_is( 'wp-admin', 'registered' ) ) {
            wp_register_style(
                'wp-admin',
                LEALEZ_ASSETS_URL . 'css/frontend/lealez-gmb-admin-compat.css',
                array( 'dashicons' ),
                LEALEZ_VERSION
            );
        }
        wp_enqueue_style( 'wp-admin' );

        wp_enqueue_style(
            'lealez-frontend-portal',
            LEALEZ_ASSETS_URL . 'css/frontend/lealez-frontend-portal.css',
            array(),
            LEALEZ_VERSION
        );

        wp_enqueue_style(
            'lealez-frontend-gmb-center',
            LEALEZ_ASSETS_URL . 'css/frontend/lealez-frontend-gmb-center.css',
            array( 'lealez-frontend-portal', 'wp-admin', 'dashicons' ),
            LEALEZ_VERSION
        );

        wp_enqueue_script(
            'lealez-frontend-gmb-center',
            LEALEZ_ASSETS_URL . 'js/frontend/lealez-frontend-gmb-center.js',
            array( 'jquery' ),
            LEALEZ_VERSION,
            true
        );
        wp_localize_script(
            'lealez-frontend-gmb-center',
            'lealezGmbFrontend',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            )
        );
    }

    private function is_google_center_page() {
        if ( ! is_singular( 'page' ) ) {
            return false;
        }
        $ids     = $this->get_page_ids();
        $page_id = get_queried_object_id();
        return ( ! empty( $ids['location_google'] ) && (int) $ids['location_google'] === (int) $page_id )
            || ( ! empty( $ids['business_google'] ) && (int) $ids['business_google'] === (int) $page_id );
    }

    private function get_page_ids() {
        $ids = get_option( self::PAGE_OPTION, array() );
        return is_array( $ids ) ? $ids : array();
    }

    private function page_url( $key, $args = array() ) {
        $ids     = $this->get_page_ids();
        $page_id = isset( $ids[ $key ] ) ? absint( $ids[ $key ] ) : 0;
        $url     = $page_id && 'trash' !== get_post_status( $page_id ) ? get_permalink( $page_id ) : home_url( '/' );
        return ! empty( $args ) ? add_query_arg( $args, $url ) : $url;
    }

    public function shortcode_business_google_center() {
        if ( ! is_user_logged_in() ) {
            return $this->login_required_panel();
        }

        $this->enqueue_common_assets();

        $business_id = isset( $_GET['business_id'] ) ? absint( wp_unslash( $_GET['business_id'] ) ) : 0;
        if ( ! $business_id ) {
            return $this->render_business_selector();
        }
        if ( ! $this->can_access_business( $business_id ) ) {
            return $this->forbidden_panel();
        }

        $business = get_post( $business_id );
        if ( ! $business || 'oy_business' !== $business->post_type ) {
            return $this->forbidden_panel();
        }

        $module  = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : 'snapshot';
        $modules = $this->get_business_modules();
        if ( ! isset( $modules[ $module ] ) ) {
            $module = 'snapshot';
        }

        // OAuth credentials and connection lifecycle remain restricted to site
        // administrators. Business administrators can inspect the synchronized
        // snapshot and manage every linked location from the location center.
        if ( 'connection' === $module && ! $this->is_site_admin() ) {
            $module = 'snapshot';
        }

        $this->prepare_module_assets( 'oy_business', $business_id, $module );

        ob_start();
        ?>
        <div class="lealez-portal lealez-gmb-center">
            <?php $this->render_query_notice(); ?>
            <div class="lealez-page-head">
                <div>
                    <a class="lealez-back" href="<?php echo esc_url( $this->page_url( 'businesses' ) ); ?>">← <?php esc_html_e( 'Volver a empresas', 'lealez' ); ?></a>
                    <span class="lealez-eyebrow"><?php esc_html_e( 'Google Business Profile', 'lealez' ); ?></span>
                    <h2><?php echo esc_html( $business->post_title ); ?></h2>
                    <p><?php esc_html_e( 'Conexión, cuentas detectadas y ubicaciones disponibles en Google.', 'lealez' ); ?></p>
                </div>
            </div>

            <div class="lealez-gmb-layout">
                <aside class="lealez-gmb-sidebar">
                    <?php foreach ( $modules as $key => $definition ) : ?>
                        <?php if ( ! empty( $definition['site_admin_only'] ) && ! $this->is_site_admin() ) { continue; } ?>
                        <a class="lealez-gmb-nav-item<?php echo $module === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( $this->page_url( 'business_google', array( 'business_id' => $business_id, 'module' => $key ) ) ); ?>">
                            <span class="dashicons <?php echo esc_attr( $definition['icon'] ); ?>"></span>
                            <span><strong><?php echo esc_html( $definition['label'] ); ?></strong><small><?php echo esc_html( $definition['description'] ); ?></small></span>
                        </a>
                    <?php endforeach; ?>
                    <a class="lealez-gmb-nav-item" href="<?php echo esc_url( $this->page_url( 'location_google', array( 'business_id' => $business_id ) ) ); ?>">
                        <span class="dashicons dashicons-location-alt"></span>
                        <span><strong><?php esc_html_e( 'Administrar ubicaciones', 'lealez' ); ?></strong><small><?php esc_html_e( 'Perfiles, contenido y métricas', 'lealez' ); ?></small></span>
                    </a>
                </aside>
                <main class="lealez-gmb-main">
                    <?php if ( ! $this->is_site_admin() ) : ?>
                        <div class="lealez-gmb-guidance is-info">
                            <strong><?php esc_html_e( 'Conexión protegida', 'lealez' ); ?></strong>
                            <p><?php esc_html_e( 'Por seguridad, conectar o desconectar la cuenta OAuth sigue reservado al administrador del sitio. La administración diaria de las ubicaciones sí está disponible para el equipo autorizado.', 'lealez' ); ?></p>
                        </div>
                    <?php endif; ?>
                    <div class="lealez-gmb-module-heading">
                        <div><span class="dashicons <?php echo esc_attr( $modules[ $module ]['icon'] ); ?>"></span><div><h3><?php echo esc_html( $modules[ $module ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $module ]['long_description'] ); ?></p></div></div>
                    </div>
                    <?php echo $this->render_embedded_metabox( 'oy_business', $business_id, $modules[ $module ] ); ?>
                </main>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function shortcode_location_google_center() {
        if ( ! is_user_logged_in() ) {
            return $this->login_required_panel();
        }

        $this->enqueue_common_assets();

        $location_id = isset( $_GET['location_id'] ) ? absint( wp_unslash( $_GET['location_id'] ) ) : 0;
        if ( ! $location_id ) {
            return $this->render_location_selector();
        }
        if ( ! $this->can_access_location( $location_id ) ) {
            return $this->forbidden_panel();
        }

        $location = get_post( $location_id );
        if ( ! $location || 'oy_location' !== $location->post_type ) {
            return $this->forbidden_panel();
        }

        $business_id = absint( get_post_meta( $location_id, 'parent_business_id', true ) );
        $business    = $business_id ? get_post( $business_id ) : null;
        $module      = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : 'overview';
        $modules     = $this->get_location_modules();
        if ( ! isset( $modules[ $module ] ) ) {
            $module = 'overview';
        }

        if ( ! empty( $modules[ $module ]['business_admin_only'] ) && ! $this->can_manage_location_business( $location_id ) ) {
            $module = 'overview';
        }

        $this->prepare_module_assets( 'oy_location', $location_id, $module );

        ob_start();
        ?>
        <div class="lealez-portal lealez-gmb-center">
            <?php $this->render_query_notice(); ?>
            <div class="lealez-page-head">
                <div>
                    <a class="lealez-back" href="<?php echo esc_url( $this->page_url( 'locations' ) ); ?>">← <?php esc_html_e( 'Volver a ubicaciones', 'lealez' ); ?></a>
                    <span class="lealez-eyebrow"><?php esc_html_e( 'Google Business Profile', 'lealez' ); ?></span>
                    <h2><?php echo esc_html( $location->post_title ); ?></h2>
                    <p><?php echo $business ? esc_html( $business->post_title ) : esc_html__( 'Sin empresa asignada', 'lealez' ); ?></p>
                </div>
                <div class="lealez-gmb-head-actions">
                    <a class="lealez-btn lealez-btn-secondary" href="<?php echo esc_url( $this->page_url( 'location_editor', array( 'location_id' => $location_id ) ) ); ?>"><?php esc_html_e( 'Datos internos', 'lealez' ); ?></a>
                    <?php if ( $business_id ) : ?><a class="lealez-btn lealez-btn-secondary" href="<?php echo esc_url( $this->page_url( 'business_google', array( 'business_id' => $business_id ) ) ); ?>"><?php esc_html_e( 'Google de la empresa', 'lealez' ); ?></a><?php endif; ?>
                </div>
            </div>

            <?php $this->render_google_workflow_guidance( $location_id ); ?>

            <div class="lealez-gmb-layout">
                <aside class="lealez-gmb-sidebar">
                    <?php $current_group = ''; ?>
                    <?php foreach ( $modules as $key => $definition ) : ?>
                        <?php
                        if ( ! empty( $definition['business_admin_only'] ) && ! $this->can_manage_location_business( $location_id ) ) {
                            continue;
                        }
                        if ( $definition['group'] !== $current_group ) {
                            $current_group = $definition['group'];
                            echo '<div class="lealez-gmb-nav-group">' . esc_html( $current_group ) . '</div>';
                        }
                        ?>
                        <a class="lealez-gmb-nav-item<?php echo $module === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( $this->page_url( 'location_google', array( 'location_id' => $location_id, 'module' => $key ) ) ); ?>">
                            <span class="dashicons <?php echo esc_attr( $definition['icon'] ); ?>"></span>
                            <span><strong><?php echo esc_html( $definition['label'] ); ?></strong><small><?php echo esc_html( $definition['description'] ); ?></small></span>
                            <?php echo $this->render_module_status_badge( $location_id, $key ); ?>
                        </a>
                    <?php endforeach; ?>
                </aside>
                <main class="lealez-gmb-main">
                    <?php if ( 'overview' === $module ) : ?>
                        <?php $this->render_location_google_overview( $location_id, $business_id, $modules ); ?>
                    <?php else : ?>
                        <div class="lealez-gmb-module-heading">
                            <div><span class="dashicons <?php echo esc_attr( $modules[ $module ]['icon'] ); ?>"></span><div><h3><?php echo esc_html( $modules[ $module ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $module ]['long_description'] ); ?></p></div></div>
                            <?php echo $this->render_module_status_badge( $location_id, $module, true ); ?>
                        </div>
                        <?php echo $this->render_embedded_metabox( 'oy_location', $location_id, $modules[ $module ] ); ?>
                    <?php endif; ?>
                </main>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function get_business_modules() {
        return array(
            'snapshot' => array(
                'label' => __( 'Cuentas y ubicaciones', 'lealez' ),
                'description' => __( 'Resumen sincronizado', 'lealez' ),
                'long_description' => __( 'Consulta las cuentas y propiedades detectadas en Google, junto con la fecha de la última actualización.', 'lealez' ),
                'icon' => 'dashicons-location-alt',
                'metabox_id' => 'lealez_business_gmb_snapshot',
            ),
            'connection' => array(
                'label' => __( 'Conexión OAuth', 'lealez' ),
                'description' => __( 'Administración protegida', 'lealez' ),
                'long_description' => __( 'Conecta, prueba, actualiza o desconecta la cuenta de Google Business Profile.', 'lealez' ),
                'icon' => 'dashicons-admin-links',
                'metabox_id' => 'lealez_business_gmb',
                'site_admin_only' => true,
            ),
        );
    }

    private function get_location_modules() {
        return array(
            'overview' => array(
                'label' => __( 'Resumen', 'lealez' ),
                'description' => __( 'Estado general de publicación', 'lealez' ),
                'long_description' => __( 'Comprueba el vínculo con Google y los módulos con cambios locales o revisiones pendientes.', 'lealez' ),
                'icon' => 'dashicons-dashboard',
                'group' => __( 'General', 'lealez' ),
                'metabox_id' => '',
            ),
            'connection' => array(
                'label' => __( 'Vinculación GMB', 'lealez' ),
                'description' => __( 'Origen e identificadores', 'lealez' ),
                'long_description' => __( 'Información de la propiedad de Google vinculada. La vinculación se protege para evitar asociaciones duplicadas.', 'lealez' ),
                'icon' => 'dashicons-admin-links',
                'group' => __( 'General', 'lealez' ),
                'metabox_id' => 'oy_location_gmb',
                'business_admin_only' => true,
                'read_only' => true,
            ),
            'sync' => array(
                'label' => __( 'Centro de sincronización', 'lealez' ),
                'description' => __( 'Sync total, límites y log', 'lealez' ),
                'long_description' => __( 'Ejecuta la sincronización secuencial de los módulos, respeta límites y conserva el historial técnico.', 'lealez' ),
                'icon' => 'dashicons-update',
                'group' => __( 'General', 'lealez' ),
                'metabox_id' => 'oy_gmb_integration_control',
                'business_admin_only' => true,
                'classic_save' => true,
            ),
            'basic' => array(
                'label' => __( 'Información básica', 'lealez' ),
                'description' => __( 'Descripción, apertura y categorías', 'lealez' ),
                'long_description' => __( 'Guarda localmente y publica en Google la descripción, fecha de apertura y categorías oficiales.', 'lealez' ),
                'icon' => 'dashicons-info-outline',
                'group' => __( 'Perfil', 'lealez' ),
                'metabox_id' => 'oy_location_basic_info',
                'publish_flow' => true,
            ),
            'address' => array(
                'label' => __( 'Dirección', 'lealez' ),
                'description' => __( 'Dirección, áreas y mapa', 'lealez' ),
                'long_description' => __( 'Administra dirección, área de servicio y coordenadas, y envía el cambio a Google para revisión.', 'lealez' ),
                'icon' => 'dashicons-location',
                'group' => __( 'Perfil', 'lealez' ),
                'metabox_id' => 'oy_location_address',
                'publish_flow' => true,
            ),
            'contact' => array(
                'label' => __( 'Contacto', 'lealez' ),
                'description' => __( 'Teléfonos, web, chat y enlaces', 'lealez' ),
                'long_description' => __( 'Administra los canales de contacto y vínculos públicos compatibles con Google Business Profile.', 'lealez' ),
                'icon' => 'dashicons-phone',
                'group' => __( 'Perfil', 'lealez' ),
                'metabox_id' => 'oy_location_contact',
                'publish_flow' => true,
            ),
            'hours' => array(
                'label' => __( 'Horarios', 'lealez' ),
                'description' => __( 'Horario regular y especial', 'lealez' ),
                'long_description' => __( 'Configura horarios normales y fechas especiales; luego envíalos y verifica su aplicación en Google.', 'lealez' ),
                'icon' => 'dashicons-clock',
                'group' => __( 'Perfil', 'lealez' ),
                'metabox_id' => 'oy_location_hours',
                'publish_flow' => true,
            ),
            'attributes' => array(
                'label' => __( 'Atributos “Más”', 'lealez' ),
                'description' => __( 'Accesibilidad y características', 'lealez' ),
                'long_description' => __( 'Carga los atributos permitidos para la categoría, guarda diferencias locales y publícalas en Google.', 'lealez' ),
                'icon' => 'dashicons-yes-alt',
                'group' => __( 'Perfil', 'lealez' ),
                'metabox_id' => 'oy_location_gmb_more_attrs',
                'publish_flow' => true,
            ),
            'media' => array(
                'label' => __( 'Fotos del propietario', 'lealez' ),
                'description' => __( 'Biblioteca sincronizada', 'lealez' ),
                'long_description' => __( 'Consulta las fotografías asociadas al perfil y abre cada recurso directamente en Google.', 'lealez' ),
                'icon' => 'dashicons-format-gallery',
                'group' => __( 'Contenido', 'lealez' ),
                'metabox_id' => 'oy_location_gmb_owner_media',
            ),
            'menu' => array(
                'label' => __( 'Menú', 'lealez' ),
                'description' => __( 'Secciones, platos y fotografías', 'lealez' ),
                'long_description' => __( 'Gestiona el menú local, envíalo mediante el flujo disponible y verifica el resultado devuelto por Google.', 'lealez' ),
                'icon' => 'dashicons-food',
                'group' => __( 'Contenido', 'lealez' ),
                'metabox_id' => 'oy_location_menu',
                'publish_flow' => true,
            ),
            'services' => array(
                'label' => __( 'Servicios', 'lealez' ),
                'description' => __( 'Catálogo para no restaurantes', 'lealez' ),
                'long_description' => __( 'Administra el catálogo local y sincroniza la información disponible desde Google. El módulo informa cuando un endpoint de publicación no está disponible.', 'lealez' ),
                'icon' => 'dashicons-store',
                'group' => __( 'Contenido', 'lealez' ),
                'metabox_id' => 'oy_location_products',
                'classic_save' => true,
            ),
            'posts' => array(
                'label' => __( 'Publicaciones', 'lealez' ),
                'description' => __( 'Crear, editar y eliminar posts', 'lealez' ),
                'long_description' => __( 'Gestiona publicaciones y borradores; las acciones de publicar, editar o eliminar se ejecutan directamente contra Google.', 'lealez' ),
                'icon' => 'dashicons-megaphone',
                'group' => __( 'Interacción', 'lealez' ),
                'metabox_id' => 'oy_location_gmb_posts',
            ),
            'reviews' => array(
                'label' => __( 'Opiniones', 'lealez' ),
                'description' => __( 'Consultar y responder reseñas', 'lealez' ),
                'long_description' => __( 'Sincroniza reseñas y publica o elimina respuestas del propietario directamente en Google.', 'lealez' ),
                'icon' => 'dashicons-star-filled',
                'group' => __( 'Interacción', 'lealez' ),
                'metabox_id' => 'oy_location_gmb_reviews',
            ),
            'performance' => array(
                'label' => __( 'Rendimiento', 'lealez' ),
                'description' => __( 'Métricas y comparativas', 'lealez' ),
                'long_description' => __( 'Consulta las métricas de Business Profile Performance API y conserva snapshots locales.', 'lealez' ),
                'icon' => 'dashicons-chart-area',
                'group' => __( 'Analítica', 'lealez' ),
                'metabox_id' => 'oy_location_gmb_performance',
            ),
            'keywords' => array(
                'label' => __( 'Frases de búsqueda', 'lealez' ),
                'description' => __( 'Keywords mensuales', 'lealez' ),
                'long_description' => __( 'Consulta las frases con las que los usuarios encontraron la ubicación y guarda los resultados seleccionados.', 'lealez' ),
                'icon' => 'dashicons-search',
                'group' => __( 'Analítica', 'lealez' ),
                'metabox_id' => 'oy_location_gmb_keywords',
            ),
            'busyhours' => array(
                'label' => __( 'Mayor interés', 'lealez' ),
                'description' => __( 'Índice por día y hora', 'lealez' ),
                'long_description' => __( 'Calcula el patrón de interés desde Performance API y conserva la distribución resultante.', 'lealez' ),
                'icon' => 'dashicons-chart-bar',
                'group' => __( 'Analítica', 'lealez' ),
                'metabox_id' => 'oy_location_gmb_busyhours',
            ),
        );
    }

    private function render_location_selector() {
        $business_filter = isset( $_GET['business_id'] ) ? absint( wp_unslash( $_GET['business_id'] ) ) : 0;
        $locations       = get_posts(
            array(
                'post_type'      => 'oy_location',
                'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );
        $locations = array_values(
            array_filter(
                $locations,
                function( $location ) use ( $business_filter ) {
                    if ( ! $this->can_access_location( $location->ID ) ) {
                        return false;
                    }
                    return ! $business_filter || $business_filter === absint( get_post_meta( $location->ID, 'parent_business_id', true ) );
                }
            )
        );

        ob_start();
        ?>
        <div class="lealez-portal lealez-gmb-center">
            <div class="lealez-page-head"><div><span class="lealez-eyebrow"><?php esc_html_e( 'Google Business Profile', 'lealez' ); ?></span><h2><?php esc_html_e( 'Selecciona una ubicación', 'lealez' ); ?></h2><p><?php esc_html_e( 'Abre el centro de perfil, contenido, interacción y analítica de una ubicación autorizada.', 'lealez' ); ?></p></div></div>
            <?php if ( empty( $locations ) ) : ?>
                <div class="lealez-empty"><h3><?php esc_html_e( 'No hay ubicaciones disponibles', 'lealez' ); ?></h3><p><?php esc_html_e( 'Crea una ubicación o solicita acceso a una empresa.', 'lealez' ); ?></p></div>
            <?php else : ?>
                <div class="lealez-card-grid lealez-card-grid-2">
                    <?php foreach ( $locations as $location ) : ?>
                        <?php $resource = (string) get_post_meta( $location->ID, 'gmb_location_name', true ); ?>
                        <article class="lealez-entity-card"><div class="lealez-entity-top"><div><span class="lealez-status <?php echo $resource ? 'is-active' : 'is-muted'; ?>"><?php echo $resource ? esc_html__( 'Vinculada', 'lealez' ) : esc_html__( 'Sin vincular', 'lealez' ); ?></span><h3><?php echo esc_html( $location->post_title ); ?></h3></div><span class="lealez-action-icon"><span class="dashicons dashicons-location-alt"></span></span></div><p><?php echo $resource ? esc_html( $resource ) : esc_html__( 'Conecta esta ficha a una ubicación de Google para publicar cambios.', 'lealez' ); ?></p><div class="lealez-card-actions"><a class="lealez-btn lealez-btn-primary" href="<?php echo esc_url( $this->page_url( 'location_google', array( 'location_id' => $location->ID ) ) ); ?>"><?php esc_html_e( 'Abrir centro Google', 'lealez' ); ?></a></div></article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_business_selector() {
        $businesses = get_posts( array( 'post_type' => 'oy_business', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
        $businesses = array_values( array_filter( $businesses, array( $this, 'filter_accessible_business' ) ) );
        ob_start(); ?>
        <div class="lealez-portal lealez-gmb-center"><div class="lealez-page-head"><div><span class="lealez-eyebrow"><?php esc_html_e( 'Google Business Profile', 'lealez' ); ?></span><h2><?php esc_html_e( 'Selecciona una empresa', 'lealez' ); ?></h2><p><?php esc_html_e( 'Consulta su conexión, cuentas y propiedades sincronizadas.', 'lealez' ); ?></p></div></div>
            <?php if ( empty( $businesses ) ) : ?><div class="lealez-empty"><h3><?php esc_html_e( 'No hay empresas disponibles', 'lealez' ); ?></h3></div><?php else : ?><div class="lealez-card-grid lealez-card-grid-2"><?php foreach ( $businesses as $business ) : $connected = (bool) get_post_meta( $business->ID, '_gmb_connected', true ); ?><article class="lealez-entity-card"><div class="lealez-entity-top"><div><span class="lealez-status <?php echo $connected ? 'is-active' : 'is-muted'; ?>"><?php echo $connected ? esc_html__( 'Conectada', 'lealez' ) : esc_html__( 'Sin conexión', 'lealez' ); ?></span><h3><?php echo esc_html( $business->post_title ); ?></h3></div><span class="lealez-action-icon">G</span></div><div class="lealez-card-actions"><a class="lealez-btn lealez-btn-primary" href="<?php echo esc_url( $this->page_url( 'business_google', array( 'business_id' => $business->ID ) ) ); ?>"><?php esc_html_e( 'Abrir Google', 'lealez' ); ?></a></div></article><?php endforeach; ?></div><?php endif; ?>
        </div><?php return (string) ob_get_clean();
    }

    public function filter_accessible_business( $business ) {
        return $business instanceof WP_Post && $this->can_access_business( $business->ID );
    }

    private function render_location_google_overview( $location_id, $business_id, $modules ) {
        $resource    = (string) get_post_meta( $location_id, 'gmb_location_name', true );
        $location_id_google = (string) get_post_meta( $location_id, 'gmb_location_id', true );
        $account_id  = (string) get_post_meta( $location_id, 'gmb_account_id', true );
        ?>
        <div class="lealez-gmb-overview-card">
            <div><span class="dashicons dashicons-admin-site-alt3"></span><div><h3><?php esc_html_e( 'Vinculación actual', 'lealez' ); ?></h3><p><?php echo $resource ? esc_html( $resource ) : esc_html__( 'Esta ubicación todavía no tiene un resource name de Google asociado.', 'lealez' ); ?></p></div></div>
            <dl><div><dt><?php esc_html_e( 'Account ID', 'lealez' ); ?></dt><dd><?php echo $account_id ? esc_html( $account_id ) : '—'; ?></dd></div><div><dt><?php esc_html_e( 'Location ID', 'lealez' ); ?></dt><dd><?php echo $location_id_google ? esc_html( $location_id_google ) : '—'; ?></dd></div></dl>
        </div>
        <div class="lealez-gmb-status-grid">
            <?php foreach ( array( 'basic', 'address', 'contact', 'hours', 'attributes', 'menu' ) as $key ) : ?>
                <a class="lealez-gmb-status-card" href="<?php echo esc_url( $this->page_url( 'location_google', array( 'location_id' => $location_id, 'module' => $key ) ) ); ?>"><span class="dashicons <?php echo esc_attr( $modules[ $key ]['icon'] ); ?>"></span><div><strong><?php echo esc_html( $modules[ $key ]['label'] ); ?></strong><?php echo $this->render_module_status_badge( $location_id, $key, true ); ?></div></a>
            <?php endforeach; ?>
        </div>
        <div class="lealez-card-grid lealez-card-grid-3">
            <?php foreach ( array( 'sync', 'media', 'posts', 'reviews', 'performance', 'keywords', 'busyhours' ) as $key ) : ?>
                <?php if ( ! isset( $modules[ $key ] ) || ( ! empty( $modules[ $key ]['business_admin_only'] ) && ! $this->can_manage_location_business( $location_id ) ) ) { continue; } ?>
                <a class="lealez-action-card" href="<?php echo esc_url( $this->page_url( 'location_google', array( 'location_id' => $location_id, 'module' => $key ) ) ); ?>"><span class="lealez-action-icon"><span class="dashicons <?php echo esc_attr( $modules[ $key ]['icon'] ); ?>"></span></span><h3><?php echo esc_html( $modules[ $key ]['label'] ); ?></h3><p><?php echo esc_html( $modules[ $key ]['description'] ); ?></p></a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_google_workflow_guidance( $location_id ) {
        $resource = (string) get_post_meta( $location_id, 'gmb_location_name', true );
        ?>
        <div class="lealez-gmb-guidance <?php echo $resource ? 'is-info' : 'is-warning'; ?>">
            <strong><?php echo $resource ? esc_html__( 'Cómo se publican los cambios', 'lealez' ) : esc_html__( 'Ubicación sin vínculo con Google', 'lealez' ); ?></strong>
            <?php if ( $resource ) : ?>
                <p><?php esc_html_e( '1) Activa la edición del módulo. 2) Guarda los cambios locales. 3) Presiona “Enviar a GMB”. 4) Consulta “Verificar estado”. Google puede aplicar el cambio inmediatamente o dejarlo pendiente de revisión; el sistema no mostrará “Aplicado” hasta comprobar la respuesta de Google.', 'lealez' ); ?></p>
            <?php else : ?>
                <p><?php esc_html_e( 'Los módulos pueden mostrar información local, pero no podrán enviar cambios hasta que un administrador vincule esta ubicación con una propiedad de Google Business Profile.', 'lealez' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_embedded_metabox( $post_type, $post_id, $definition ) {
        $post = get_post( $post_id );
        if ( ! $post || $post_type !== $post->post_type || empty( $definition['metabox_id'] ) ) {
            return '<div class="lealez-empty"><h3>' . esc_html__( 'Módulo no disponible', 'lealez' ) . '</h3></div>';
        }

        $box = $this->find_metabox( $post_type, $definition['metabox_id'], $post );
        if ( ! $box || empty( $box['callback'] ) || ! is_callable( $box['callback'] ) ) {
            return '<div class="lealez-empty"><h3>' . esc_html__( 'No se pudo cargar el módulo', 'lealez' ) . '</h3><p>' . esc_html__( 'El metabox no está registrado en esta instalación.', 'lealez' ) . '</p></div>';
        }

        $this->invoke_metabox_asset_methods( $box['callback'], $post );
        $this->schedule_footer_asset_methods( $box['callback'], $post );

        $business_id  = 'oy_location' === $post_type ? absint( get_post_meta( $post_id, 'parent_business_id', true ) ) : 0;
        $location_name = 'oy_location' === $post_type ? (string) get_post_meta( $post_id, 'gmb_location_name', true ) : '';

        ob_start();
        ?>
        <form id="post" class="lealez-gmb-metabox-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="lealez_frontend_save_gmb_metabox">
            <input type="hidden" name="post_id" id="post_ID" value="<?php echo esc_attr( $post_id ); ?>">
            <input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">
            <input type="hidden" name="module" value="<?php echo esc_attr( $this->definition_key_from_id( $post_type, $definition['metabox_id'] ) ); ?>">
            <?php wp_nonce_field( 'lealez_frontend_save_gmb_metabox_' . $post_id, 'lealez_frontend_gmb_nonce' ); ?>
            <?php if ( 'oy_location' === $post_type ) : ?>
                <input type="hidden" name="parent_business_id" id="parent_business_id" value="<?php echo esc_attr( $business_id ); ?>">
                <input type="hidden" name="gmb_location_name" id="gmb_location_name" value="<?php echo esc_attr( $location_name ); ?>">
            <?php endif; ?>

            <div id="<?php echo esc_attr( $definition['metabox_id'] ); ?>" class="postbox lealez-embedded-metabox<?php echo ! empty( $definition['read_only'] ) ? ' is-read-only' : ''; ?>">
                <div class="inside">
                    <?php
                    $this->with_post_context(
                        $post,
                        function() use ( $box, $post ) {
                            call_user_func( $box['callback'], $post, $box );
                        }
                    );
                    ?>
                </div>
            </div>

            <?php if ( ! empty( $definition['read_only'] ) ) : ?>
                <div class="lealez-gmb-readonly-note"><span class="dashicons dashicons-lock"></span><p><?php esc_html_e( 'Esta vinculación se muestra en modo consulta para impedir que una ubicación sea asociada accidentalmente a otra propiedad. Los administradores del sitio pueden modificarla desde el backend.', 'lealez' ); ?></p></div>
                <?php if ( $this->is_site_admin() ) : ?><a class="lealez-btn lealez-btn-secondary" href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php esc_html_e( 'Abrir vinculación en backend', 'lealez' ); ?></a><?php endif; ?>
            <?php elseif ( ! empty( $definition['classic_save'] ) ) : ?>
                <div class="lealez-form-footer"><button class="lealez-btn lealez-btn-primary" type="submit"><?php esc_html_e( 'Guardar configuración local', 'lealez' ); ?></button></div>
            <?php endif; ?>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    private function definition_key_from_id( $post_type, $metabox_id ) {
        $definitions = 'oy_business' === $post_type ? $this->get_business_modules() : $this->get_location_modules();
        foreach ( $definitions as $key => $definition ) {
            if ( isset( $definition['metabox_id'] ) && $metabox_id === $definition['metabox_id'] ) {
                return $key;
            }
        }
        return '';
    }

    private function prepare_module_assets( $post_type, $post_id, $module ) {
        $definitions = 'oy_business' === $post_type ? $this->get_business_modules() : $this->get_location_modules();
        if ( empty( $definitions[ $module ]['metabox_id'] ) ) {
            return;
        }
        $post = get_post( $post_id );
        if ( ! $post || $post_type !== $post->post_type ) {
            return;
        }
        $box = $this->find_metabox( $post_type, $definitions[ $module ]['metabox_id'], $post );
        if ( $box && ! empty( $box['callback'] ) ) {
            $this->invoke_metabox_asset_methods( $box['callback'], $post );
            $this->schedule_footer_asset_methods( $box['callback'], $post );
        }
    }

    private function find_metabox( $post_type, $metabox_id, $post ) {
        $this->register_metaboxes_for_post( $post );
        global $wp_meta_boxes;
        if ( empty( $wp_meta_boxes[ $post_type ] ) || ! is_array( $wp_meta_boxes[ $post_type ] ) ) {
            return null;
        }
        foreach ( $wp_meta_boxes[ $post_type ] as $contexts ) {
            if ( ! is_array( $contexts ) ) { continue; }
            foreach ( $contexts as $priorities ) {
                if ( ! is_array( $priorities ) ) { continue; }
                if ( isset( $priorities[ $metabox_id ] ) ) {
                    return $priorities[ $metabox_id ];
                }
            }
        }
        return null;
    }

    private function register_metaboxes_for_post( $post ) {
        $key = $post->post_type . ':' . $post->ID;
        if ( isset( $this->registered_metaboxes[ $key ] ) ) {
            return;
        }
        $this->registered_metaboxes[ $key ] = true;
        $this->with_post_context(
            $post,
            function() use ( $post ) {
                do_action( 'add_meta_boxes', $post->post_type, $post );
                do_action( 'add_meta_boxes_' . $post->post_type, $post );
            }
        );
    }

    private function invoke_metabox_asset_methods( $callback, $post ) {
        if ( ! is_array( $callback ) || ! is_object( $callback[0] ) ) {
            return;
        }
        $object = $callback[0];
        foreach ( array( 'enqueue_assets', 'enqueue_scripts', 'enqueue_admin_scripts', 'admin_scripts' ) as $method ) {
            if ( ! method_exists( $object, $method ) ) {
                continue;
            }
            $key = spl_object_hash( $object ) . ':' . $method . ':' . $post->ID;
            if ( isset( $this->invoked_asset_methods[ $key ] ) ) {
                continue;
            }
            $reflection = new ReflectionMethod( $object, $method );
            if ( ! $reflection->isPublic() || $reflection->getNumberOfRequiredParameters() > 1 ) {
                continue;
            }
            $this->invoked_asset_methods[ $key ] = true;
            $this->with_post_context(
                $post,
                function() use ( $object, $method, $reflection ) {
                    if ( 0 === $reflection->getNumberOfParameters() ) {
                        $object->{$method}();
                    } else {
                        $object->{$method}( 'post.php' );
                    }
                }
            );
        }
    }

    private function schedule_footer_asset_methods( $callback, $post ) {
        if ( ! is_array( $callback ) || ! is_object( $callback[0] ) ) {
            return;
        }
        $object     = $callback[0];
        $reflection = new ReflectionObject( $object );
        foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
            $name = $method->getName();
            if ( 0 !== $method->getNumberOfRequiredParameters() ) {
                continue;
            }
            if ( false === strpos( $name, 'footer_assets' ) && false === strpos( $name, 'inline_assets' ) ) {
                continue;
            }
            $key = spl_object_hash( $object ) . ':' . $name . ':' . $post->ID;
            foreach ( $this->footer_asset_callbacks as $registered ) {
                if ( $registered['key'] === $key ) {
                    continue 2;
                }
            }
            $this->footer_asset_callbacks[] = array( 'key' => $key, 'object' => $object, 'method' => $name, 'post' => $post );
        }
    }

    public function render_deferred_footer_assets() {
        foreach ( $this->footer_asset_callbacks as $callback ) {
            $this->with_post_context(
                $callback['post'],
                function() use ( $callback ) {
                    call_user_func( array( $callback['object'], $callback['method'] ) );
                }
            );
        }
    }

    private function with_post_context( $target_post, $callback ) {
        global $post, $post_type, $typenow, $current_screen;
        $previous_post           = $post;
        $previous_post_type      = $post_type;
        $previous_typenow        = $typenow;
        $previous_current_screen = isset( $current_screen ) ? $current_screen : null;

        $post      = $target_post;
        $post_type = $target_post->post_type;
        $typenow   = $target_post->post_type;

        if ( ! class_exists( 'WP_Screen' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-screen.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        }
        if ( class_exists( 'WP_Screen' ) ) {
            $screen = WP_Screen::get( 'post' );
            $screen->post_type = $target_post->post_type;
            $screen->base      = 'post';
            $screen->id        = $target_post->post_type;
            $current_screen    = $screen;
        }

        try {
            return call_user_func( $callback );
        } finally {
            $post           = $previous_post;
            $post_type      = $previous_post_type;
            $typenow        = $previous_typenow;
            $current_screen = $previous_current_screen;
        }
    }

    public function handle_classic_metabox_save() {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Debes iniciar sesión.', 'lealez' ) );
        }
        $post_id   = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
        $post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
        $module    = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
        $nonce     = isset( $_POST['lealez_frontend_gmb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lealez_frontend_gmb_nonce'] ) ) : '';

        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'lealez_frontend_save_gmb_metabox_' . $post_id ) ) {
            wp_die( esc_html__( 'La solicitud no es válida.', 'lealez' ) );
        }
        if ( 'oy_location' !== $post_type || ! $this->can_access_location( $post_id ) ) {
            wp_die( esc_html__( 'No tienes permisos para guardar este módulo.', 'lealez' ) );
        }
        if ( ! in_array( $module, array( 'sync', 'services' ), true ) ) {
            wp_die( esc_html__( 'Este módulo utiliza su propio botón de guardado.', 'lealez' ) );
        }
        if ( 'sync' === $module && ! $this->can_manage_location_business( $post_id ) ) {
            wp_die( esc_html__( 'Solo un administrador de la empresa puede cambiar la sincronización.', 'lealez' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_die( esc_html__( 'Ubicación no encontrada.', 'lealez' ) );
        }

        // Triggers only the save callback whose own nonce was rendered inside
        // the selected metabox. The main location nonce is intentionally absent,
        // preventing unrelated fields from being deleted or overwritten.
        wp_update_post( array( 'ID' => $post_id ) );

        $url = $this->page_url( 'location_google', array( 'location_id' => $post_id, 'module' => $module, 'gmb_notice' => 'local_saved' ) );
        wp_safe_redirect( $url );
        exit;
    }

    public function enhance_existing_portal_screens( $output, $tag, $attr, $m ) {
        if ( ! is_user_logged_in() ) {
            return $output;
        }

        if ( 'lealez_location_list' === $tag ) {
            return $this->portal_bridge_banner(
                __( 'Centro Google de ubicaciones', 'lealez' ),
                __( 'Administra información, horarios, contenido, reseñas y analítica usando los mismos flujos del backend.', 'lealez' ),
                $this->page_url( 'location_google' ),
                __( 'Abrir centro Google', 'lealez' )
            ) . $output;
        }

        if ( 'lealez_location_editor' === $tag ) {
            $location_id = isset( $_GET['location_id'] ) ? absint( wp_unslash( $_GET['location_id'] ) ) : 0;
            if ( $location_id && $this->can_access_location( $location_id ) ) {
                return $this->portal_bridge_banner(
                    __( 'Publicación en Google Business Profile', 'lealez' ),
                    __( 'Los datos internos se guardan aquí. Para enviar campos compatibles a Google y consultar su revisión, usa el Centro Google.', 'lealez' ),
                    $this->page_url( 'location_google', array( 'location_id' => $location_id ) ),
                    __( 'Gestionar y publicar en Google', 'lealez' )
                ) . $output;
            }
        }

        if ( in_array( $tag, array( 'lealez_business_editor', 'lealez_business_integrations' ), true ) ) {
            $business_id = isset( $_GET['business_id'] ) ? absint( wp_unslash( $_GET['business_id'] ) ) : 0;
            if ( $business_id && $this->can_access_business( $business_id ) ) {
                return $this->portal_bridge_banner(
                    __( 'Google Business Profile', 'lealez' ),
                    __( 'Consulta la conexión, las cuentas y las ubicaciones sincronizadas de esta empresa.', 'lealez' ),
                    $this->page_url( 'business_google', array( 'business_id' => $business_id ) ),
                    __( 'Abrir Google de la empresa', 'lealez' )
                ) . $output;
            }
        }

        return $output;
    }

    private function portal_bridge_banner( $title, $text, $url, $button ) {
        return '<div class="lealez-portal lealez-gmb-bridge"><div><span class="dashicons dashicons-admin-site-alt3"></span><div><strong>' . esc_html( $title ) . '</strong><p>' . esc_html( $text ) . '</p></div></div><a class="lealez-btn lealez-btn-primary" href="' . esc_url( $url ) . '">' . esc_html( $button ) . '</a></div>';
    }

    private function render_query_notice() {
        $notice = isset( $_GET['gmb_notice'] ) ? sanitize_key( wp_unslash( $_GET['gmb_notice'] ) ) : '';
        if ( 'local_saved' === $notice ) {
            echo '<div class="lealez-notice lealez-notice-success">' . esc_html__( 'La configuración local se guardó correctamente. Esto no implica que Google haya publicado un cambio; utiliza el botón de sincronización o envío del módulo cuando esté disponible.', 'lealez' ) . '</div>';
        }
    }

    private function render_module_status_badge( $location_id, $module, $large = false ) {
        $state = $this->get_module_publish_state( $location_id, $module );
        if ( ! $state ) {
            return '';
        }
        $class = 'lealez-gmb-state is-' . sanitize_html_class( $state['class'] ) . ( $large ? ' is-large' : '' );
        return '<span class="' . esc_attr( $class ) . '">' . esc_html( $state['label'] ) . '</span>';
    }

    private function get_module_publish_state( $location_id, $module ) {
        $map = array(
            'basic'      => array( 'pending' => 'oy_basic_info_local_pending_publish', 'job' => 'gmb_basic_info_push_job' ),
            'address'    => array( 'pending' => 'oy_address_local_pending_publish', 'job' => 'gmb_address_push_job' ),
            'contact'    => array( 'pending' => 'oy_contact_local_pending_publish', 'job' => 'gmb_contact_push_job' ),
            'hours'      => array( 'pending' => 'oy_hours_local_pending_publish', 'job' => 'gmb_hours_push_job' ),
            'attributes' => array( 'pending' => 'oy_gmb_more_local_pending_publish', 'job' => 'gmb_more_push_job' ),
            'menu'       => array( 'pending' => 'oy_menu_local_pending_publish', 'job' => 'gmb_menu_push_job' ),
        );
        if ( ! isset( $map[ $module ] ) ) {
            return null;
        }
        $pending = (bool) get_post_meta( $location_id, $map[ $module ]['pending'], true );
        $job     = get_post_meta( $location_id, $map[ $module ]['job'], true );
        $status  = is_array( $job ) ? sanitize_key( (string) ( $job['status'] ?? '' ) ) : '';

        $statuses = array(
            'queued'          => array( 'label' => __( 'Enviado · en cola', 'lealez' ), 'class' => 'review' ),
            'pending_review'  => array( 'label' => __( 'Google está revisando', 'lealez' ), 'class' => 'review' ),
            'applied'         => array( 'label' => __( 'Aplicado en Google', 'lealez' ), 'class' => 'success' ),
            'partial'         => array( 'label' => __( 'Aplicado parcialmente', 'lealez' ), 'class' => 'warning' ),
            'rejected'        => array( 'label' => __( 'No aplicado', 'lealez' ), 'class' => 'error' ),
            'google_override' => array( 'label' => __( 'Google devolvió otro valor', 'lealez' ), 'class' => 'warning' ),
            'timeout'         => array( 'label' => __( 'Pendiente de verificación', 'lealez' ), 'class' => 'warning' ),
            'validation_error'=> array( 'label' => __( 'Requiere corregir datos', 'lealez' ), 'class' => 'error' ),
            'local_pending'   => array( 'label' => __( 'Guardado · falta enviar', 'lealez' ), 'class' => 'local' ),
            'error'           => array( 'label' => __( 'Error de envío', 'lealez' ), 'class' => 'error' ),
        );
        if ( $status && isset( $statuses[ $status ] ) ) {
            return $statuses[ $status ];
        }
        if ( $pending ) {
            return array( 'label' => __( 'Guardado · falta enviar', 'lealez' ), 'class' => 'local' );
        }
        return array( 'label' => __( 'Sin cambios pendientes', 'lealez' ), 'class' => 'neutral' );
    }

    public function map_portal_post_capabilities( $caps, $cap, $user_id, $args ) {
        if ( ! in_array( $cap, array( 'edit_post', 'read_post' ), true ) || empty( $args[0] ) ) {
            return $caps;
        }
        $post_id = absint( $args[0] );
        $post    = get_post( $post_id );
        if ( ! $post ) {
            return $caps;
        }
        if ( 'oy_business' === $post->post_type && $this->can_access_business( $post_id, $user_id ) ) {
            return array( 'exist' );
        }
        if ( 'oy_location' === $post->post_type && $this->can_access_location( $post_id, $user_id ) ) {
            return array( 'exist' );
        }
        return $caps;
    }

    /**
     * Existing Google handlers occasionally require edit_posts. Grant it only
     * for the authenticated AJAX action, and only to an administrator of the
     * business referenced by that request.
     */
    public function grant_scoped_gmb_capabilities( $allcaps, $caps, $args, $user ) {
        if ( ! ( $user instanceof WP_User ) || empty( $_REQUEST['action'] ) ) {
            return $allcaps;
        }
        $action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
        if ( ! in_array( $action, array( 'lealez_gmb_create_location_from_gmb', 'create_location_from_gmb' ), true ) ) {
            return $allcaps;
        }
        $business_id = isset( $_REQUEST['business_id'] ) ? absint( wp_unslash( $_REQUEST['business_id'] ) ) : 0;
        if ( $business_id && $this->can_manage_business( $business_id, $user->ID ) ) {
            $allcaps['edit_posts'] = true;
        }
        return $allcaps;
    }

    private function can_access_business( $business_id, $user_id = 0 ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        $post    = get_post( absint( $business_id ) );
        if ( ! $post || 'oy_business' !== $post->post_type || ! $user_id ) {
            return false;
        }
        if ( $this->is_site_admin( $user_id ) || (int) $post->post_author === $user_id ) {
            return true;
        }
        $admins   = get_post_meta( $post->ID, '_admin_users', true );
        $managers = get_post_meta( $post->ID, '_manager_users', true );
        $ids      = array_merge( is_array( $admins ) ? $admins : array(), is_array( $managers ) ? $managers : array() );
        return in_array( $user_id, array_map( 'intval', $ids ), true );
    }

    private function can_manage_business( $business_id, $user_id = 0 ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        $post    = get_post( absint( $business_id ) );
        if ( ! $post || 'oy_business' !== $post->post_type || ! $user_id ) {
            return false;
        }
        if ( $this->is_site_admin( $user_id ) || (int) $post->post_author === $user_id ) {
            return true;
        }
        $admins = get_post_meta( $post->ID, '_admin_users', true );
        return in_array( $user_id, array_map( 'intval', is_array( $admins ) ? $admins : array() ), true );
    }

    private function can_access_location( $location_id, $user_id = 0 ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        $post    = get_post( absint( $location_id ) );
        if ( ! $post || 'oy_location' !== $post->post_type || ! $user_id ) {
            return false;
        }
        if ( $this->is_site_admin( $user_id ) || (int) $post->post_author === $user_id ) {
            return true;
        }
        $business_id = absint( get_post_meta( $post->ID, 'parent_business_id', true ) );
        return $business_id ? $this->can_access_business( $business_id, $user_id ) : false;
    }

    private function can_manage_location_business( $location_id, $user_id = 0 ) {
        $business_id = absint( get_post_meta( $location_id, 'parent_business_id', true ) );
        return $business_id ? $this->can_manage_business( $business_id, $user_id ) : $this->is_site_admin( $user_id );
    }

    private function is_site_admin( $user_id = 0 ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }
        if ( is_multisite() && is_super_admin( $user_id ) ) {
            return true;
        }
        $user = get_userdata( $user_id );
        return $user instanceof WP_User && ! empty( $user->allcaps['manage_options'] );
    }

    private function login_required_panel() {
        $redirect = is_singular() ? get_permalink() : home_url( '/' );
        return '<div class="lealez-portal"><div class="lealez-empty"><h3>' . esc_html__( 'Inicia sesión para continuar', 'lealez' ) . '</h3><a class="lealez-btn lealez-btn-primary" href="' . esc_url( wp_login_url( $redirect ) ) . '">' . esc_html__( 'Iniciar sesión', 'lealez' ) . '</a></div></div>';
    }

    private function forbidden_panel() {
        return '<div class="lealez-portal"><div class="lealez-empty"><h3>' . esc_html__( 'Acceso no autorizado', 'lealez' ) . '</h3><p>' . esc_html__( 'No tienes acceso a este registro o módulo.', 'lealez' ) . '</p></div></div>';
    }
}

new Lealez_Frontend_GMB_Center();

endif;
