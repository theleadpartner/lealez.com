<?php
/**
 * Unified frontend profile for oy_location.
 *
 * Keeps internal Lealez data and Google Business Profile modules in one screen
 * while reusing the original metabox callbacks, AJAX handlers and push jobs.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/lealez-unified-location-routing-trait.php';
require_once __DIR__ . '/lealez-unified-location-render-trait.php';
require_once __DIR__ . '/lealez-unified-location-modules-trait.php';
require_once __DIR__ . '/lealez-unified-location-quality-trait.php';
require_once __DIR__ . '/lealez-unified-location-internal-trait.php';
require_once __DIR__ . '/lealez-unified-location-metabox-trait.php';
require_once __DIR__ . '/lealez-unified-location-access-trait.php';

if ( ! class_exists( 'Lealez_Frontend_Unified_Location_Profile' ) ) :

class Lealez_Frontend_Unified_Location_Profile {
    use Lealez_Unified_Location_Routing_Trait;
    use Lealez_Unified_Location_Render_Trait;
    use Lealez_Unified_Location_Modules_Trait;
    use Lealez_Unified_Location_Quality_Trait;
    use Lealez_Unified_Location_Internal_Trait;
    use Lealez_Unified_Location_Metabox_Trait;
    use Lealez_Unified_Location_Access_Trait;

    const PAGE_OPTION = 'lealez_frontend_page_ids';

    /** @var array<string,bool> */
    private $registered_metaboxes = array();

    /** @var array<string,bool> */
    private $invoked_asset_methods = array();

    /** @var array<int,array<string,mixed>> */
    private $footer_asset_callbacks = array();
}

new Lealez_Frontend_Unified_Location_Profile();

endif;
