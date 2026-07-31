<?php
/** Concrete Lealez Elementor widget classes. @package Lealez */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Lealez_Elementor_Account_Dashboard_Widget extends Lealez_Elementor_Portal_Widget {
    public function get_name() { return 'lealez-account-dashboard'; }
    public function get_title() { return __( 'Lealez — Panel de cuenta', 'lealez' ); }
    public function get_icon() { return 'eicon-dashboard'; }
    protected function get_lealez_screen() { return 'account_dashboard'; }
}

class Lealez_Elementor_Business_List_Widget extends Lealez_Elementor_Portal_Widget {
    public function get_name() { return 'lealez-business-list'; }
    public function get_title() { return __( 'Lealez — Mis empresas', 'lealez' ); }
    public function get_icon() { return 'eicon-post-list'; }
    protected function get_lealez_screen() { return 'business_list'; }
}

class Lealez_Elementor_Business_Profile_Widget extends Lealez_Elementor_Portal_Widget {
    public function get_name() { return 'lealez-business-profile'; }
    public function get_title() { return __( 'Lealez — Perfil de empresa', 'lealez' ); }
    public function get_icon() { return 'eicon-site-identity'; }
    protected function get_lealez_screen() { return 'business_profile'; }
}

class Lealez_Elementor_Location_List_Widget extends Lealez_Elementor_Portal_Widget {
    public function get_name() { return 'lealez-location-list'; }
    public function get_title() { return __( 'Lealez — Mis ubicaciones', 'lealez' ); }
    public function get_icon() { return 'eicon-google-maps'; }
    protected function get_lealez_screen() { return 'location_list'; }
}

class Lealez_Elementor_Location_Profile_Widget extends Lealez_Elementor_Portal_Widget {
    public function get_name() { return 'lealez-location-profile'; }
    public function get_title() { return __( 'Lealez — Perfil de ubicación', 'lealez' ); }
    public function get_icon() { return 'eicon-map-pin'; }
    protected function get_lealez_screen() { return 'location_profile'; }
}

class Lealez_Elementor_User_Profile_Widget extends Lealez_Elementor_Portal_Widget {
    public function get_name() { return 'lealez-user-profile'; }
    public function get_title() { return __( 'Lealez — Mi perfil', 'lealez' ); }
    public function get_icon() { return 'eicon-person'; }
    protected function get_lealez_screen() { return 'user_profile'; }
}
