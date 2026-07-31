<?php
/**
 * Frontend page installer administration screen.
 *
 * @package Lealez
 * @subpackage Frontend
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Lealez_Frontend_Page_Admin_Trait {
    public function register_pages_admin_menu() {
        add_submenu_page(
            'lealez',
            __( 'Páginas frontend', 'lealez' ),
            __( 'Páginas frontend', 'lealez' ),
            'manage_options',
            'lealez-frontend-pages',
            array( $this, 'render_pages_admin' )
        );
    }

    public function render_pages_admin() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para acceder.', 'lealez' ) );
        }

        $definitions       = $this->get_page_definitions();
        $elementor_active  = $this->is_elementor_active();
        $elementor_present = $this->is_elementor_installed();
        $created           = isset( $_GET['lealez_pages_created'] ) ? absint( $_GET['lealez_pages_created'] ) : 0;
        $removed           = isset( $_GET['lealez_pages_removed'] ) ? absint( $_GET['lealez_pages_removed'] ) : 0;
        $error             = isset( $_GET['lealez_pages_error'] ) ? sanitize_key( wp_unslash( $_GET['lealez_pages_error'] ) ) : '';
        $legacy_count      = $this->count_legacy_frontend_pages();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Páginas frontend de Lealez', 'lealez' ); ?></h1>
            <p><?php esc_html_e( 'Cada página se crea con un widget nativo de Elementor. El contenido funcional permanece conectado a Lealez, mientras la tipografía, colores, espaciados, tarjetas, botones y ancho se controlan desde Elementor.', 'lealez' ); ?></p>

            <?php if ( $elementor_active ) : ?>
                <div class="notice notice-success"><p><strong><?php esc_html_e( 'Elementor activo.', 'lealez' ); ?></strong> <?php esc_html_e( 'Puedes crear, reparar y editar las páginas frontend.', 'lealez' ); ?></p></div>
            <?php elseif ( $elementor_present ) : ?>
                <div class="notice notice-warning"><p><strong><?php esc_html_e( 'Elementor está instalado, pero inactivo.', 'lealez' ); ?></strong> <?php esc_html_e( 'Actívalo antes de crear o reparar páginas para evitar páginas sin su widget funcional.', 'lealez' ); ?></p></div>
            <?php else : ?>
                <div class="notice notice-error"><p><strong><?php esc_html_e( 'Elementor no está instalado.', 'lealez' ); ?></strong> <?php esc_html_e( 'Instala y activa Elementor antes de crear las páginas frontend de Lealez.', 'lealez' ); ?></p></div>
            <?php endif; ?>

            <?php if ( $created ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( _n( 'Se creó o reparó %d página con Elementor.', 'Se crearon o repararon %d páginas con Elementor.', $created, 'lealez' ), $created ) ); ?></p></div>
            <?php endif; ?>

            <?php if ( $removed ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( _n( 'Se retiró %d página heredada.', 'Se retiraron %d páginas heredadas.', $removed, 'lealez' ), $removed ) ); ?></p></div>
            <?php endif; ?>

            <?php if ( 'elementor_required' === $error ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'No se realizó la operación porque Elementor no está activo.', 'lealez' ); ?></p></div>
            <?php endif; ?>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:18px 0;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="lealez_create_all_frontend_pages">
                    <?php wp_nonce_field( 'lealez_create_all_frontend_pages' ); ?>
                    <?php submit_button( __( 'Crear o reparar todas con Elementor', 'lealez' ), 'primary', 'submit', false, $elementor_active ? array() : array( 'disabled' => 'disabled' ) ); ?>
                </form>

                <?php if ( $legacy_count ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="lealez_cleanup_frontend_pages">
                        <?php wp_nonce_field( 'lealez_cleanup_frontend_pages' ); ?>
                        <?php submit_button( sprintf( _n( 'Retirar %d página heredada', 'Retirar %d páginas heredadas', $legacy_count, 'lealez' ), $legacy_count ), 'secondary', 'submit', false ); ?>
                    </form>
                <?php endif; ?>
            </div>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Página', 'lealez' ); ?></th>
                        <th><?php esc_html_e( 'Uso', 'lealez' ); ?></th>
                        <th><?php esc_html_e( 'Widget Elementor', 'lealez' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'lealez' ); ?></th>
                        <th><?php esc_html_e( 'Acciones', 'lealez' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $definitions as $key => $definition ) :
                    $status = $this->get_page_status( $key );
                    $path   = $definition['slug'];
                    if ( $definition['parent'] ) {
                        $path = $definitions[ $definition['parent'] ]['slug'] . '/' . $path;
                    }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $definition['title'] ); ?></strong><br><code>/<?php echo esc_html( $path ); ?>/</code></td>
                        <td><?php echo esc_html( $definition['description'] ); ?></td>
                        <td><code><?php echo esc_html( $definition['widget'] ); ?></code></td>
                        <td>
                            <?php if ( $status['valid'] ) : ?>
                                <span style="color:#15803d;font-weight:600;">● <?php esc_html_e( 'Lista en Elementor', 'lealez' ); ?></span>
                            <?php elseif ( $status['exists'] && $status['elementor'] ) : ?>
                                <span style="color:#b45309;font-weight:600;">● <?php esc_html_e( 'Falta el widget de Lealez', 'lealez' ); ?></span>
                            <?php elseif ( $status['exists'] ) : ?>
                                <span style="color:#b45309;font-weight:600;">● <?php esc_html_e( 'Requiere migración a Elementor', 'lealez' ); ?></span>
                            <?php else : ?>
                                <span style="color:#6b7280;">● <?php esc_html_e( 'No creada', 'lealez' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $status['exists'] ) : ?>
                                <?php if ( $elementor_active ) : ?>
                                    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'post.php?post=' . $status['page_id'] . '&action=elementor' ) ); ?>"><?php esc_html_e( 'Editar con Elementor', 'lealez' ); ?></a>
                                <?php else : ?>
                                    <a class="button" href="<?php echo esc_url( get_edit_post_link( $status['page_id'] ) ); ?>"><?php esc_html_e( 'Editar página', 'lealez' ); ?></a>
                                <?php endif; ?>
                                <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( get_permalink( $status['page_id'] ) ); ?>"><?php esc_html_e( 'Ver', 'lealez' ); ?></a>
                            <?php endif; ?>

                            <?php if ( ! $status['valid'] ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                                    <input type="hidden" name="action" value="lealez_create_frontend_page">
                                    <input type="hidden" name="page_key" value="<?php echo esc_attr( $key ); ?>">
                                    <input type="hidden" name="repair" value="<?php echo $status['exists'] ? '1' : '0'; ?>">
                                    <?php wp_nonce_field( 'lealez_create_frontend_page_' . $key ); ?>
                                    <button class="button<?php echo $status['exists'] ? '' : ' button-primary'; ?>" type="submit" <?php disabled( ! $elementor_active ); ?>>
                                        <?php echo $status['exists'] ? esc_html__( 'Agregar widget de Lealez', 'lealez' ) : esc_html__( 'Crear página', 'lealez' ); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="description" style="margin-top:14px;"><?php esc_html_e( 'Las páginas heredadas de Equipo, Integraciones, Google de empresa y Google de ubicación se sustituyen por secciones de los perfiles unificados. Sus shortcodes continúan registrados para compatibilidad, pero ya no se crean como páginas separadas.', 'lealez' ); ?></p>
        </div>
        <?php
    }

}
