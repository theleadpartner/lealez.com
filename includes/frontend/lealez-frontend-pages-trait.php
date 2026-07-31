<?php
/**
 * Composed frontend page installer, routing and access rules.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/lealez-frontend-page-definitions-trait.php';
require_once __DIR__ . '/lealez-frontend-page-admin-trait.php';
require_once __DIR__ . '/lealez-frontend-page-status-trait.php';
require_once __DIR__ . '/lealez-frontend-page-installer-trait.php';
require_once __DIR__ . '/lealez-frontend-page-routing-trait.php';
require_once __DIR__ . '/lealez-frontend-page-access-trait.php';

trait Lealez_Frontend_Pages_Trait {
    use Lealez_Frontend_Page_Definitions_Trait;
    use Lealez_Frontend_Page_Admin_Trait;
    use Lealez_Frontend_Page_Status_Trait;
    use Lealez_Frontend_Page_Installer_Trait;
    use Lealez_Frontend_Page_Routing_Trait;
    use Lealez_Frontend_Page_Access_Trait;
}
