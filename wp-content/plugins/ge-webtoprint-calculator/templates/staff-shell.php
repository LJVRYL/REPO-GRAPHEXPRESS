<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$logged_in = is_user_logged_in();
$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'dashboard';
wp_enqueue_style( 'ge-staff-admin-components', GE_WTP_PLUGIN_URL . 'assets/css/admin.css', array(), GE_WTP_VERSION );
wp_enqueue_style( 'ge-staff-portal', GE_WTP_PLUGIN_URL . 'assets/css/staff.css', array( 'ge-staff-admin-components' ), GE_WTP_VERSION );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class( 'ge-staff-body' ); ?>>
<?php wp_body_open(); ?>
<header class="ge-staff-header"><div class="ge-staff-shell"><a class="ge-staff-brand" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url() ); ?>"><b>GE</b><span><strong>GRAPH EXPRESS</strong><small>Centro de gestión</small></span></a><?php if ( $logged_in && GE_WTP_Staff_Portal::can_access() ) : ?><nav aria-label="Gestión"><a class="<?php echo 'dashboard' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url() ); ?>">Inicio</a><a class="<?php echo 'orders' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'orders' ) ); ?>">Pedidos</a><a class="<?php echo 'production' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'production' ) ); ?>">Producción</a><a class="<?php echo 'customers' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'customers' ) ); ?>">Clientes</a><a class="<?php echo 'library' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'library' ) ); ?>">Archivos</a><a class="<?php echo 'communications' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'communications' ) ); ?>">Comunicaciones</a><a class="<?php echo 'candidates' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'candidates' ) ); ?>">Candidatos</a><a class="<?php echo in_array( $section, array( 'settings', 'notifications' ), true ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'settings' ) ); ?>">Configuración</a></nav><div class="ge-staff-account"><span><?php echo esc_html( wp_get_current_user()->display_name ); ?></span><a href="<?php echo esc_url( wp_logout_url( GE_WTP_Staff_Portal::portal_url() ) ); ?>">Salir</a></div><?php else : ?><a class="ge-staff-site-link" href="<?php echo esc_url( home_url( '/' ) ); ?>">Volver al sitio</a><?php endif; ?></div></header>
<main class="ge-staff-main"><div class="ge-staff-shell"><?php GE_WTP_Staff_Portal::render(); ?></div></main>
<footer class="ge-staff-footer"><div class="ge-staff-shell"><span>Graph Express · Gestión interna</span><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Ir a la web ↗</a></div></footer>
<?php wp_footer(); ?>
</body></html>
