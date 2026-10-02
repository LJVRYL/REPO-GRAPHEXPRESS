<?php
if(!defined('ABSPATH'))exit;
$section=sanitize_key(wp_unslash($_GET['section']??'dashboard'));
$view=sanitize_key(wp_unslash($_GET['view']??'queue'));
$active='supplier-invoices'===$section?'production':$section;
$nav=GE_WTP_Gestion_V3::nav();
$global_query=sanitize_text_field(wp_unslash($_GET['global_q']??''));
$title=$nav[$active]??array('library'=>'Archivos','candidates'=>'Candidatos','settings'=>'Configuración','notifications'=>'Notificaciones')[$section]??'Gestión';
// Render first so module assets enqueued during render are available to wp_head.
ob_start();
if($global_query){GE_WTP_Gestion_V3::render_search($global_query);}
else {
    if(in_array($section,array('production','supplier-invoices'),true)) GE_WTP_Gestion_V3::domain_tabs('supplier-invoices'===$section?'documents':$view);
    GE_WTP_Staff_Portal::render();
    if('dashboard'===$section)GE_WTP_Gestion_V3::secondary_links();
}
$content=ob_get_clean();
$assets=array('ge-staff-admin-components'=>'admin.css','ge-staff-portal'=>'staff.css');
if('dashboard'===$section)$assets['ge-control-dashboard']='dashboard.css';
if(in_array($section,array('quotes','production'),true))$assets['ge-production']='production.css';
if('quotes'===$section)$assets['ge-commercial-quotes']='commercial-quotes.css';
foreach($assets as $handle=>$css){wp_enqueue_style($handle,GE_WTP_PLUGIN_URL.'assets/css/'.$css,array(),(string)filemtime(GE_WTP_PLUGIN_DIR.'assets/css/'.$css));}
$styles=wp_styles();
wp_enqueue_style('ge-gestion-v3',GE_WTP_PLUGIN_URL.'assets/css/gestion-v3.css',$styles->queue,(string)filemtime(GE_WTP_PLUGIN_DIR.'assets/css/gestion-v3.css'));
// wp_head triggers theme enqueue later. Resolve dependencies at print time to
// keep the operational system after every module and storefront stylesheet.
add_action('wp_print_styles',static function(){
    $styles=wp_styles();
    $styles->registered['ge-gestion-v3']->deps=array_values(array_diff($styles->queue,array('ge-gestion-v3','ge-operations')));
},999);
if(GE_WTP_Operations::enabled()) wp_enqueue_style('ge-operations',GE_WTP_PLUGIN_URL.'assets/css/operations.css',array('ge-gestion-v3'),GE_WTP_Operations::VERSION);
wp_enqueue_script('ge-gestion-v3',GE_WTP_PLUGIN_URL.'assets/js/gestion-v3.js',array(),(string)filemtime(GE_WTP_PLUGIN_DIR.'assets/js/gestion-v3.js'),true);
?><!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class('ge-staff-body ge-gestion-v3'); ?>>
<?php wp_body_open(); ?>
<a class="ge-v3-skip" href="#ge-main">Ir al contenido</a>
<aside class="ge-v3-sidebar" id="ge-navigation" aria-label="Navegación de gestión">
 <a class="ge-v3-brand" href="<?php echo esc_url(GE_WTP_Gestion_V3::url()); ?>"><img src="<?php echo esc_url(GE_WTP_PLUGIN_URL.'assets/images/graphex-simbolo.svg'); ?>" width="36" height="36" alt=""><span><strong>GRAPHEX</strong><small>Gestión</small></span></a>
 <span class="ge-v3-nav-caption">OPERACIÓN</span>
 <nav class="ge-v3-nav" aria-label="Principal">
 <?php foreach($nav as $key=>$label): ?><a <?php echo $key===$active&&!$global_query?'aria-current="page" class="is-active"':''; ?> href="<?php echo esc_url(GE_WTP_Gestion_V3::url('dashboard'===$key?'':$key)); ?>"><?php echo GE_WTP_Gestion_V3::icon($key); ?><span><?php echo esc_html($label); ?></span></a><?php endforeach; ?>
 </nav>
 <div class="ge-v3-sidebar-footer"><a href="<?php echo esc_url(home_url('/')); ?>">Ir a la web <?php echo GE_WTP_Gestion_V3::icon('arrow'); ?></a><small>Graph Express · Operación interna</small></div>
</aside>
<div class="ge-v3-app">
 <header class="ge-v3-topbar">
  <button class="ge-v3-icon-button ge-v3-menu" type="button" aria-controls="ge-navigation" aria-expanded="false" aria-label="Abrir navegación" title="Navegación"><?php echo GE_WTP_Gestion_V3::icon('menu'); ?></button>
  <form class="ge-v3-global-search" role="search" method="get" action="<?php echo esc_url(GE_WTP_Gestion_V3::url()); ?>"><label class="ge-v3-sr-only" for="ge-v3-global">Buscar en gestión</label><?php echo GE_WTP_Gestion_V3::icon('search'); ?><input id="ge-v3-global" type="search" name="global_q" value="<?php echo esc_attr($global_query); ?>" placeholder="Buscar trabajo, cliente, proveedor o archivo…" maxlength="120"><button type="submit" aria-label="Buscar" title="Buscar"><?php echo GE_WTP_Gestion_V3::icon('arrow'); ?></button></form>
  <details class="ge-v3-quick"><summary title="Crear" aria-label="Crear presupuesto o pedido"><?php echo GE_WTP_Gestion_V3::icon('plus'); ?><span>Crear</span></summary><div><a href="<?php echo esc_url(GE_WTP_Gestion_V3::url('quotes',array('new'=>1))); ?>">Nuevo presupuesto</a><a href="<?php echo esc_url(GE_WTP_Gestion_V3::url('production',array('view'=>'new'))); ?>">Nuevo pedido</a></div></details>
  <a class="ge-v3-icon-button <?php echo in_array($section,array('settings','notifications'),true)?'is-active':''; ?>" href="<?php echo esc_url(GE_WTP_Gestion_V3::url('settings')); ?>" title="Configuración" aria-label="Configuración"><?php echo GE_WTP_Gestion_V3::icon('settings'); ?></a>
  <details class="ge-v3-user"><summary aria-label="Cuenta de usuario"><span><?php echo esc_html(mb_substr(wp_get_current_user()->display_name,0,1)); ?></span></summary><div><strong><?php echo esc_html(wp_get_current_user()->display_name); ?></strong><a href="<?php echo esc_url(wp_logout_url(GE_WTP_Gestion_V3::url())); ?>">Cerrar sesión</a></div></details>
 </header>
 <main id="ge-main" class="ge-v3-main" tabindex="-1"><nav class="ge-v3-breadcrumb" aria-label="Ubicación"><a href="<?php echo esc_url(GE_WTP_Gestion_V3::url()); ?>">Gestión</a><span aria-hidden="true">/</span><span><?php echo esc_html($global_query?'Buscar':$title); ?></span><?php if('supplier-invoices'===$section):?><span aria-hidden="true">/</span><span>Documentos de proveedor</span><?php endif; ?></nav><?php echo $content; ?></main>
</div>
<?php wp_footer(); ?>
</body></html>
