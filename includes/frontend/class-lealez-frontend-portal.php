<?php
/**
 * Frontend portal for Lealez businesses, locations and user profiles.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/lealez-frontend-pages-trait.php';
require_once __DIR__ . '/lealez-frontend-business-trait.php';
require_once __DIR__ . '/lealez-frontend-location-trait.php';
require_once __DIR__ . '/lealez-frontend-user-helpers-trait.php';
require_once __DIR__ . '/lealez-frontend-admin-compat.php';
require_once __DIR__ . '/class-lealez-frontend-gmb-center.php';
require_once __DIR__ . '/class-lealez-frontend-unified-location-profile.php';

if ( ! class_exists( 'Lealez_Frontend_Portal' ) ) :

class Lealez_Frontend_Portal {
    use Lealez_Frontend_Pages_Trait;
    use Lealez_Frontend_Business_Trait;
    use Lealez_Frontend_Location_Trait;
    use Lealez_Frontend_User_Helpers_Trait;

    const PAGE_OPTION = 'lealez_frontend_page_ids';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'template_redirect', array( $this, 'redirect_legacy_frontend_pages' ), 1 );
        add_action( 'template_redirect', array( $this, 'protect_portal_pages_from_cache' ), 0 );
        add_action( 'template_redirect', array( $this, 'handle_frontend_actions' ), 5 );
        add_action( 'admin_menu', array( $this, 'register_pages_admin_menu' ), 30 );
        add_action( 'admin_post_lealez_create_frontend_page', array( $this, 'handle_create_frontend_page' ) );
        add_action( 'admin_post_lealez_create_all_frontend_pages', array( $this, 'handle_create_all_frontend_pages' ) );
        add_action( 'admin_post_lealez_cleanup_frontend_pages', array( $this, 'handle_cleanup_frontend_pages' ) );
    }

    /**
     * Prevent full-page cache plugins and proxies from serving one user's
     * personalized portal content to another user.
     */
    public function protect_portal_pages_from_cache() {
        if ( ! is_page() ) {
            return;
        }

        $page_id = get_queried_object_id();
        if ( ! $page_id || ! get_post_meta( $page_id, '_lealez_frontend_page_key', true ) ) {
            return;
        }

        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
            define( 'DONOTCACHEOBJECT', true );
        }
        nocache_headers();
    }
}

new Lealez_Frontend_Portal();

endif;
