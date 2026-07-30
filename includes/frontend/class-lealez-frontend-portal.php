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
        add_action( 'template_redirect', array( $this, 'handle_frontend_actions' ), 5 );
        add_action( 'admin_menu', array( $this, 'register_pages_admin_menu' ), 30 );
        add_action( 'admin_post_lealez_create_frontend_page', array( $this, 'handle_create_frontend_page' ) );
        add_action( 'admin_post_lealez_create_all_frontend_pages', array( $this, 'handle_create_all_frontend_pages' ) );
    }
}

new Lealez_Frontend_Portal();

endif;
