<?php
/** Elementor style controls for Lealez portal widgets. @package Lealez */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Lealez_Elementor_Style_Controls_Trait {
    private function register_lealez_style_controls() {
        $this->start_controls_section(
            'section_surface_style',
            array(
                'label' => __( 'Superficies y colores', 'lealez' ),
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_control(
            'page_background',
            array(
                'label'     => __( 'Fondo general', 'lealez' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#f6f7f8',
                'selectors' => array(
                    '{{WRAPPER}} .lealez-elementor-shell' => '--lealez-page: {{VALUE}}; background-color: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'surface_color',
            array(
                'label'     => __( 'Fondo de tarjetas', 'lealez' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#ffffff',
                'selectors' => array(
                    '{{WRAPPER}} .lealez-elementor-shell' => '--lealez-surface: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'primary_color',
            array(
                'label'     => __( 'Color principal', 'lealez' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#3782c4',
                'selectors' => array(
                    '{{WRAPPER}} .lealez-elementor-shell' => '--lealez-primary: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'primary_dark_color',
            array(
                'label'     => __( 'Color principal oscuro', 'lealez' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#245f91',
                'selectors' => array(
                    '{{WRAPPER}} .lealez-elementor-shell' => '--lealez-primary-dark: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'text_color',
            array(
                'label'     => __( 'Texto principal', 'lealez' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#172033',
                'selectors' => array(
                    '{{WRAPPER}} .lealez-elementor-shell' => '--lealez-ink: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'muted_color',
            array(
                'label'     => __( 'Texto secundario', 'lealez' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#657084',
                'selectors' => array(
                    '{{WRAPPER}} .lealez-elementor-shell' => '--lealez-muted: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'border_color',
            array(
                'label'     => __( 'Bordes', 'lealez' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#dce3ec',
                'selectors' => array(
                    '{{WRAPPER}} .lealez-elementor-shell' => '--lealez-line: {{VALUE}};',
                ),
            )
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_spacing_style',
            array(
                'label' => __( 'Espaciado y bordes', 'lealez' ),
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_responsive_control(
            'shell_padding',
            array(
                'label'      => __( 'Relleno general', 'lealez' ),
                'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => array( 'px', 'em', '%' ),
                'default'    => array( 'top' => 28, 'right' => 28, 'bottom' => 28, 'left' => 28, 'unit' => 'px', 'isLinked' => true ),
                'selectors'  => array(
                    '{{WRAPPER}} .lealez-elementor-shell' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ),
            )
        );

        $this->add_responsive_control(
            'card_padding',
            array(
                'label'      => __( 'Relleno de tarjetas', 'lealez' ),
                'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => array( 'px', 'em' ),
                'selectors'  => array(
                    '{{WRAPPER}} .lealez-elementor-shell' => '--lz-card-pad-top: {{TOP}}{{UNIT}}; --lz-card-pad-right: {{RIGHT}}{{UNIT}}; --lz-card-pad-bottom: {{BOTTOM}}{{UNIT}}; --lz-card-pad-left: {{LEFT}}{{UNIT}};',
                ),
            )
        );

        $this->add_responsive_control(
            'card_radius',
            array(
                'label'      => __( 'Radio de tarjetas', 'lealez' ),
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => array( 'px' ),
                'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
                'default'    => array( 'unit' => 'px', 'size' => 16 ),
                'selectors'  => array(
                    '{{WRAPPER}} .lealez-elementor-shell' => '--lz-radius: {{SIZE}}{{UNIT}};',
                ),
            )
        );

        $this->add_group_control(
            \Elementor\Group_Control_Box_Shadow::get_type(),
            array(
                'name'     => 'card_shadow',
                'label'    => __( 'Sombra de tarjetas', 'lealez' ),
                'selector' => '{{WRAPPER}} .lealez-elementor-shell .lealez-stat-card, {{WRAPPER}} .lealez-elementor-shell .lealez-action-card, {{WRAPPER}} .lealez-elementor-shell .lealez-entity-card, {{WRAPPER}} .lealez-elementor-shell .lealez-form, {{WRAPPER}} .lealez-elementor-shell .lealez-empty, {{WRAPPER}} .lealez-elementor-shell .lealez-gmb-sidebar, {{WRAPPER}} .lealez-elementor-shell .lealez-gmb-module-heading, {{WRAPPER}} .lealez-elementor-shell .lealez-profile-summary',
            )
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_typography_style',
            array(
                'label' => __( 'Tipografía', 'lealez' ),
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            array(
                'name'     => 'body_typography',
                'label'    => __( 'Texto general', 'lealez' ),
                'selector' => '{{WRAPPER}} .lealez-elementor-shell .lealez-portal, {{WRAPPER}} .lealez-elementor-shell .lealez-profile-summary',
            )
        );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            array(
                'name'     => 'heading_typography',
                'label'    => __( 'Títulos principales', 'lealez' ),
                'selector' => '{{WRAPPER}} .lealez-elementor-shell h1, {{WRAPPER}} .lealez-elementor-shell h2, {{WRAPPER}} .lealez-elementor-shell .lealez-profile-summary h2',
            )
        );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            array(
                'name'     => 'card_heading_typography',
                'label'    => __( 'Títulos de tarjetas', 'lealez' ),
                'selector' => '{{WRAPPER}} .lealez-elementor-shell h3, {{WRAPPER}} .lealez-elementor-shell .lealez-entity-card h3',
            )
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_button_style',
            array(
                'label' => __( 'Botones', 'lealez' ),
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_control(
            'button_text_color',
            array(
                'label'     => __( 'Texto del botón principal', 'lealez' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#ffffff',
                'selectors' => array(
                    '{{WRAPPER}} .lealez-elementor-shell .lealez-btn-primary' => 'color: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'button_background',
            array(
                'label'     => __( 'Fondo del botón principal', 'lealez' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#3782c4',
                'selectors' => array(
                    '{{WRAPPER}} .lealez-elementor-shell .lealez-btn-primary' => 'background-color: {{VALUE}};',
                ),
            )
        );

        $this->add_responsive_control(
            'button_radius',
            array(
                'label'      => __( 'Radio', 'lealez' ),
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => array( 'px' ),
                'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
                'default'    => array( 'unit' => 'px', 'size' => 10 ),
                'selectors'  => array(
                    '{{WRAPPER}} .lealez-elementor-shell .lealez-btn' => 'border-radius: {{SIZE}}{{UNIT}};',
                ),
            )
        );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            array(
                'name'     => 'button_typography',
                'selector' => '{{WRAPPER}} .lealez-elementor-shell .lealez-btn',
            )
        );

        $this->end_controls_section();
    }
}
