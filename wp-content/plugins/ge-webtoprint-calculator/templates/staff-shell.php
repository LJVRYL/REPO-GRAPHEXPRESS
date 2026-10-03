<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$logged_in = is_user_logged_in();
$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'dashboard';
wp_enqueue_style( 'ge-staff-admin-components', GE_WTP_PLUGIN_URL . 'assets/css/admin.css', array(), GE_WTP_VERSION );
$staff_css = GE_WTP_PLUGIN_DIR . 'assets/css/staff.css';
wp_enqueue_style( 'ge-staff-portal', GE_WTP_PLUGIN_URL . 'assets/css/staff.css', array( 'ge-staff-admin-components' ), is_file( $staff_css ) ? (string) filemtime( $staff_css ) : GE_WTP_VERSION );
if ( in_array( $section, array( 'quotes', 'production' ), true ) && GE_WTP_Staff_Portal::can_access() ) {
    $production_css = GE_WTP_PLUGIN_DIR . 'assets/css/production.css';
    wp_enqueue_style( 'ge-production', GE_WTP_PLUGIN_URL . 'assets/css/production.css', array( 'ge-staff-portal' ), is_file( $production_css ) ? (string) filemtime( $production_css ) : GE_WTP_VERSION );
}
if ( 'quotes' === $section && GE_WTP_Staff_Portal::can_access() ) {
    $quote_css = GE_WTP_PLUGIN_DIR . 'assets/css/commercial-quotes.css';
    wp_enqueue_style( 'ge-commercial-quotes', GE_WTP_PLUGIN_URL . 'assets/css/commercial-quotes.css', array( 'ge-production' ), is_file( $quote_css ) ? (string) filemtime( $quote_css ) : GE_WTP_VERSION );
}
if ( 'dashboard' === $section && GE_WTP_Staff_Portal::can_access() ) {
    $dashboard_css = GE_WTP_PLUGIN_DIR . 'assets/css/dashboard.css';
    wp_enqueue_style( 'ge-control-dashboard', GE_WTP_PLUGIN_URL . 'assets/css/dashboard.css', array( 'ge-staff-portal' ), is_file( $dashboard_css ) ? (string) filemtime( $dashboard_css ) : GE_WTP_VERSION );
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class( 'ge-staff-body' ); ?>>
<?php wp_body_open(); ?>
<header class="ge-staff-header"><div class="ge-staff-shell"><a class="ge-staff-brand" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url() ); ?>"><img src="<?php echo esc_url( GE_WTP_PLUGIN_URL . 'assets/images/graphex-simbolo.svg' ); ?>" width="42" height="42" alt=""><span><strong>GRAPHEX</strong><small>Centro de gestión</small></span></a><?php if ( $logged_in && GE_WTP_Staff_Portal::can_access() ) : ?><nav aria-label="Gestión"><a class="<?php echo 'dashboard' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url() ); ?>">Inicio</a><a class="<?php echo 'quotes' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'quotes' ) ); ?>">Presupuestos</a><a class="<?php echo 'orders' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'orders' ) ); ?>">Pedidos</a><a class="<?php echo 'production' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'production' ) ); ?>">Producción</a><a class="<?php echo 'customers' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'customers' ) ); ?>">Clientes</a><a class="<?php echo 'library' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'library' ) ); ?>">Archivos</a><a class="<?php echo 'supplier-invoices' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'supplier-invoices' ) ); ?>">Facturas prov.</a><a class="<?php echo 'communications' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'communications' ) ); ?>">Comunicaciones</a><a class="<?php echo 'candidates' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'candidates' ) ); ?>">Candidatos</a><a class="<?php echo in_array( $section, array( 'settings', 'notifications' ), true ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'settings' ) ); ?>">Configuración</a></nav><?php GE_WTP_Internal_Alerts::bell(); ?><div class="ge-staff-account"><span><?php echo esc_html( wp_get_current_user()->display_name ); ?></span><a href="<?php echo esc_url( wp_logout_url( GE_WTP_Staff_Portal::portal_url() ) ); ?>">Salir</a></div><?php else : ?><a class="ge-staff-site-link" href="<?php echo esc_url( home_url( '/' ) ); ?>">Volver al sitio</a><?php endif; ?></div></header>
<main class="ge-staff-main"><div class="ge-staff-shell"><?php GE_WTP_Staff_Portal::render(); ?></div></main>
<footer class="ge-staff-footer"><div class="ge-staff-shell"><span>Graph Express · Gestión interna</span><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Ir a la web ↗</a></div></footer>
<?php wp_footer(); ?>
</body></html>
