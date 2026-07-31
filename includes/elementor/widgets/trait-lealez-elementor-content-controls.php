<?php
/** Elementor content controls for Lealez portal widgets. @package Lealez */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Lealez_Elementor_Content_Controls_Trait {
    private function register_lealez_content_controls() {

        $this->start_controls_section(
            'section_layout',
            array(
                'label' => __( 'Configuración', 'lealez' ),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            )
        );

        $this->add_responsive_control(
            'content_max_width',
            array(
                'label'      => __( 'Ancho máximo', 'lealez' ),
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => array( 'px', '%', 'vw' ),
                'range'      => array(
                    'px' => array( 'min' => 720, 'max' => 1800, 'step' => 10 ),
                    '%'  => array( 'min' => 60, 'max' => 100 ),
                    'vw' => array( 'min' => 60, 'max' => 100 ),
                ),
                'default'    => array( 'unit' => 'px', 'size' => 1280 ),
                'selectors'  => array(
                    '{{WRAPPER}} .lealez-elementor-shell' => 'max-width: {{SIZE}}{{UNIT}};',
                ),
            )
        );

        $this->add_control(
            'density',
            array(
                'label'   => __( 'Densidad visual', 'lealez' ),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'default' => 'comfortable',
                'options' => array(
                    'compact'     => __( 'Compacta', 'lealez' ),
                    'comfortable' => __( 'Cómoda', 'lealez' ),
                    'spacious'    => __( 'Amplia', 'lealez' ),
                ),
            )
        );

        $this->add_control(
            'hide_internal_header',
            array(
                'label'        => __( 'Ocultar encabezado interno', 'lealez' ),
                'description'  => __( 'Útil cuando agregas tu propio título o hero con otros widgets de Elementor.', 'lealez' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __( 'Sí', 'lealez' ),
                'label_off'    => __( 'No', 'lealez' ),
                'return_value' => 'yes',
                'default'      => '',
            )
        );

        if ( in_array( $this->get_lealez_screen(), array( 'business_profile', 'location_profile' ), true ) ) {
            $this->add_control(
                'show_profile_summary',
                array(
                    'label'        => __( 'Mostrar resumen visual del perfil', 'lealez' ),
                    'description'  => __( 'Agrega portada, identidad, estado y datos principales antes del formulario.', 'lealez' ),
                    'type'         => \Elementor\Controls_Manager::SWITCHER,
                    'label_on'     => __( 'Sí', 'lealez' ),
                    'label_off'    => __( 'No', 'lealez' ),
                    'return_value' => 'yes',
                    'default'      => 'yes',
                )
            );
        }

        if ( 'business_profile' === $this->get_lealez_screen() ) {
            $this->add_control(
                'preview_business_section',
                array(
                    'label'       => __( 'Sección para la vista previa', 'lealez' ),
                    'description' => __( 'En la página publicada la navegación y la URL deciden la sección activa.', 'lealez' ),
                    'type'        => \Elementor\Controls_Manager::SELECT,
                    'default'     => 'profile',
                    'options'     => array(
                        'profile'      => __( 'Perfil', 'lealez' ),
                        'team'         => __( 'Equipo', 'lealez' ),
                        'integrations' => __( 'Integraciones', 'lealez' ),
                        'google'       => __( 'Google Business Profile', 'lealez' ),
                    ),
                )
            );
        }

        $this->end_controls_section();
    }
}
