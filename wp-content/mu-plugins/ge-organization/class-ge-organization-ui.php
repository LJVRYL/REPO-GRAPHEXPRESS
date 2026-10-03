<?php
defined('ABSPATH') || exit;
final class GE_Organization_UI {
    public static function init() {
        add_filter('template_include',array(__CLASS__,'template'),110);
        add_action('wp_head',array(__CLASS__,'head'));
        add_action('wp_footer',array(__CLASS__,'links'));
    }
    public static function template($file) {
        if(!is_page('gestion') || !GE_Organization::get(GE_Organization::PRIMARY))return $file;
        // The organization-aware shell delegates every operational section to its existing renderer.
        return class_exists('GE_WTP_Gestion_V3') && GE_WTP_Gestion_V3::shell_enabled() && GE_WTP_Staff_Portal::can_access() ? __DIR__.'/gestion-v3.php' : __DIR__.'/staff-shell.php';
    }
    public static function links() {
        if(!is_page('gestion') || !GE_WTP_Staff_Portal::can_access() || !GE_Organization::get(GE_Organization::PRIMARY) || in_array($_GET['section']??'',array('company','profile'),true))return;
        echo '<nav id="ge-org-links" aria-label="Mi empresa y cuenta"><a id="ge-org-company" href="'.esc_url(GE_WTP_Staff_Portal::portal_url('company')).'">Mi empresa</a><a id="ge-org-profile" href="'.esc_url(GE_WTP_Staff_Portal::portal_url('profile')).'">Mi perfil</a></nav>';
        echo '<script>(function(){var c=document.getElementById("ge-org-company"),p=document.getElementById("ge-org-profile"),h=document.querySelector(".ge-v3-topbar"),u=document.querySelector(".ge-v3-user div");if(h&&c){c.style.cssText="padding:12px;white-space:nowrap;color:inherit";h.insertBefore(c,h.querySelector(".ge-v3-user"));}if(u&&p){u.appendChild(p);}var n=document.getElementById("ge-org-links");if(n&&!n.children.length)n.remove();})();</script>';
    }
    public static function head() {
        if(!is_page('gestion'))return;
        echo '<style>'.file_get_contents(__DIR__.'/company.css').'</style>';
        $o=GE_Organization::get(GE_Organization::PRIMARY);
        if($o && $o['settings']['branding']['favicon_url'])echo '<link rel="icon" href="'.esc_url($o['settings']['branding']['favicon_url']).'">';
    }
    public static function tabs() { return array('general'=>'Datos generales','branding'=>'Branding','issuers'=>'Emisores fiscales','numbering'=>'Numeración','documents'=>'Documentos / PDF','commercial'=>'Preferencias comerciales','users'=>'Usuarios','integrations'=>'Integraciones','email'=>'Email','portal'=>'Portal cliente','operations'=>'Operaciones','modules'=>'Módulos','portability'=>'Exportar / importar','onboarding'=>'Onboarding de empresa'); }
    public static function url($id,$tab) { return GE_WTP_Staff_Portal::portal_url('company',array('organization_id'=>$id,'tab'=>$tab)); }
    public static function label($key) {
        $labels=array('display_name'=>'Nombre comercial','legal_name'=>'Razón social principal','brand_name'=>'Nombre de marca','website'=>'Sitio web','email'=>'Email de contacto','phone'=>'Teléfono','whatsapp'=>'WhatsApp','address'=>'Domicilio comercial','timezone'=>'Zona horaria','locale'=>'Idioma / región','currency'=>'Moneda predeterminada','country'=>'País','tax_jurisdiction'=>'Jurisdicción fiscal','logo_url'=>'Logo (URL)','favicon_url'=>'Favicon (URL)','primary_color'=>'Color principal','quote_prefix'=>'Prefijo de presupuestos','order_prefix'=>'Prefijo de pedidos','work_prefix'=>'Prefijo de trabajos','footer'=>'Pie de documentos','terms'=>'Términos comerciales','payment_terms'=>'Condiciones de pago','quote_valid_days'=>'Validez predeterminada (días)','email_text'=>'Texto de email','pdf_text'=>'Texto de PDF','default_discount_percent'=>'Descuento predeterminado (%)','price_display'=>'Presentación de precios','sender_name'=>'Nombre del remitente','reply_to'=>'Responder a','domain'=>'Dominio principal','portal_domain'=>'Dominio del portal','support_email'=>'Email de soporte','welcome_text'=>'Texto de bienvenida','internal_production'=>'Producción interna habilitada','waste_percent'=>'Merma predeterminada (%)','commerce_provider'=>'Integración de comercio');
        return $labels[$key]??$key;
    }
    public static function form_start($o,$tab,$operation='save',$file=false) {
        echo '<form class="ge-company-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"'.($file?' enctype="multipart/form-data"':'').'>';
        foreach(array('action'=>'ge_org_action','organization_id'=>$o['organization_id'],'operation'=>$operation,'tab'=>$tab,'revision'=>$o['revision']) as $k=>$v)echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
        wp_nonce_field('ge_org_'.$o['organization_id']);
    }
    public static function input($k,$v,$disabled) {
        echo '<label><span>'.esc_html(self::label($k)).'</span>';
        $d=$disabled?' disabled':'';
        if(in_array($k,array('price_display','commerce_provider','internal_production'),true)) {
            $opts=$k==='price_display'?array('existing'=>'Conservar política actual','tax_inclusive'=>'IVA incluido','tax_exclusive'=>'IVA no incluido'):($k==='commerce_provider'?array('none'=>'Sin e-commerce','woocommerce'=>'WooCommerce'):array('0'=>'No','1'=>'Sí'));
            echo '<select name="values['.esc_attr($k).']"'.$d.'>';foreach($opts as $val=>$label)echo '<option value="'.esc_attr($val).'"'.selected((string)$v,(string)$val,false).'>'.esc_html($label).'</option>';echo '</select>';
        } elseif(in_array($k,array('footer','terms','payment_terms','pdf_text','email_text','welcome_text'),true))echo '<textarea rows="4" name="values['.esc_attr($k).']"'.$d.'>'.esc_textarea($v).'</textarea>';
        else { $type=in_array($k,array('email','support_email','reply_to'),true)?'email':(in_array($k,array('website','logo_url','favicon_url'),true)?'url':'text');echo '<input type="'.$type.'" name="values['.esc_attr($k).']" value="'.esc_attr($v).'" maxlength="4000"'.$d.'>'; }
        echo '</label>';
    }
    public static function render() {
        $id=sanitize_key($_GET['organization_id']??GE_Organization::PRIMARY);$actor=get_current_user_id();
        if(!GE_Organization::can($id,$actor)) { echo '<p role="alert">No tenés acceso a esta organización.</p>';return; }
        $o=GE_Organization::get($id);$can=GE_Organization::can($id,$actor,true);$tab=sanitize_key($_GET['tab']??'general');if(!isset(self::tabs()[$tab]))$tab='general';
        echo '<div class="ge-staff-heading"><div><span>Organización</span><h1>Mi empresa</h1><p>'.esc_html($o['settings']['general']['display_name']).' · '.($id===GE_Organization::PRIMARY?'Operación actual':'QA de configuración, sin acceso a datos productivos').'</p></div></div>';
        if(isset($_GET['saved']))echo '<p class="ge-company-notice" role="status">Cambios guardados.</p>';
        if(user_can($actor,'manage_options')) { echo '<div class="ge-company-organizations">';foreach(GE_Organization::all() as $other)echo '<a href="'.esc_url(self::url($other['organization_id'],'general')).'">'.esc_html($other['settings']['general']['display_name']).($other['mode']==='qa-config-only'?' · QA':'').'</a>';echo '</div>'; }
        echo '<div class="ge-company-layout"><nav class="ge-company-tabs" aria-label="Secciones de Mi empresa">';foreach(self::tabs() as $key=>$label)echo '<a'.($key===$tab?' aria-current="page"':'').' href="'.esc_url(self::url($id,$key)).'">'.esc_html($label).'</a>';echo '</nav><section class="ge-company-panel"><h2>'.esc_html(self::tabs()[$tab]).'</h2>';
        if($tab==='issuers') {
            if($id===GE_Organization::PRIMARY)GE_WTP_Billing_Issuers::render_settings();
            else { echo '<p>Se reutiliza el esquema fiscal existente. Los emisores importados requieren nueva verificación y credenciales propias.</p>';foreach(GE_Organization::issuers($id) as $p)echo '<p><strong>'.esc_html($p['legal_name']).'</strong> · '.esc_html($p['cuit']).' · Pendiente de verificación</p>'; }
        } elseif($tab==='users') {
            echo '<p>Roles operativos por empresa. Cada módulo valida permisos en el servidor; las cuentas pertenecen a esta instancia.</p><table><thead><tr><th>Usuario</th><th>Rol</th></tr></thead><tbody>';
            foreach($o['members'] as $uid=>$role) { $u=get_userdata($uid);echo '<tr><td>'.esc_html($u?$u->display_name:'Usuario no disponible').'</td><td>'.esc_html($role).'</td></tr>'; }echo '</tbody></table>';
            if($can){self::form_start($o,$tab);echo '<label>Usuario existente (ID)<input type="number" name="values[user_id]" min="1"></label><label>Rol<select name="values[role]">';foreach(GE_Organization::roles() as $role)echo '<option>'.esc_html($role).'</option>';echo '</select></label><h3>O crear usuario del equipo</h3><label>Login<input name="values[new_login]"></label><label>Nombre<input name="values[new_name]"></label><label>Email<input type="email" name="values[new_email]"></label><p>La cuenta se crea sin compartir contraseñas. El usuario podrá usar recuperación de acceso cuando el correo propio esté configurado.</p><button>Guardar usuario y rol</button></form>';}
        } elseif($tab==='modules') {
            echo '<p>Los módulos apagados quedan protegidos en páginas, acciones y API. Los permisos del rol se aplican además del módulo.</p>';self::form_start($o,$tab);foreach(GE_Organization::modules() as $k)echo '<label class="ge-company-check"><input type="checkbox" name="values['.esc_attr($k).']" value="1"'.checked(!empty($o['settings']['modules'][$k]),true,false).(!$can?' disabled':'').'>'.esc_html($k).'</label>';if($can)echo '<button>Guardar preferencias</button>';echo '</form>';
        } elseif($tab==='onboarding') {
            echo '<p>Una empresa operativa usa su propia instancia, base, usuarios, archivos y referencias. No se selecciona otra base desde una URL.</p><ol>';
            foreach(array('general'=>'1 · Identidad, país y moneda','branding'=>'2 · Branding y logo','issuers'=>'3 · Emisores fiscales','users'=>'4 · Owner y equipo','modules'=>'5 · Módulos','integrations'=>'6 · Integraciones opcionales') as $step=>$label)echo '<li><a href="'.esc_url(self::url($id,$step)).'">'.esc_html($label).'</a></li>';
            echo '</ol><p>El alta pública permanece cerrada. ARCA y pagos pueden quedar sin configurar; no se habilita emisión fiscal.</p>';
            if($id===GE_Organization::PRIMARY && $can){self::form_start($o,$tab,'complete_onboarding');echo '<button>Finalizar y habilitar operación de esta empresa</button></form>';}
            if(!empty($o['onboarding']['ready']))echo '<p role="status">Empresa lista para operar en su instancia aislada.</p><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url()).'">Ir al dashboard</a>';
        } elseif($tab==='portability') {
            echo '<p>Exportación versionada de configuración y emisores, sin contraseñas, tokens, certificados ni referencias de credenciales. Los datos operativos y archivos todavía no se incluyen.</p>';
            if($can){self::form_start($o,$tab,'export');echo '<button>Descargar configuración JSON</button></form>';}
            if(user_can($actor,'manage_options') && GE_Organization::PRIMARY!=='graph-express'){echo '<h3>Importar configuración en esta instancia</h3><p>Se reemplaza la configuración, se mantienen usuarios propios y los emisores requieren verificación en destino. Los secretos no se copian.</p>';self::form_start($o,$tab,'import_current',true);echo '<label>Configuración JSON<input type="file" name="bundle" accept=".json,application/json" required></label><button>Importar configuración aquí</button></form>';}
            if(user_can($actor,'manage_options')){echo '<h3>Preparar configuración para otra instancia</h3><p>Se crea una organización independiente de configuración. No copia clientes, pedidos, usuarios ni habilita una operación multiempresa.</p><ol><li>Empresa, país y moneda</li><li>Branding</li><li>Emisores pendientes de verificar</li><li>Operación: migración pendiente</li><li>Owner actual; equipo por asignar</li><li>Integraciones por reconfigurar</li></ol>';self::form_start($o,$tab,'import',true);echo '<label>Configuración JSON<input type="file" name="bundle" accept=".json,application/json" required></label><button>Importar como empresa QA</button></form>';}
        } else {
            if($tab==='numbering')echo '<p>Los prefijos comerciales se aplican a documentos nuevos. La secuencia y los IDs técnicos pertenecen a esta base. Los históricos se conservan.</p>';
            if(in_array($tab,array('commercial','operations','documents','portal'),true))echo '<p>Los documentos nuevos capturan la configuración como snapshot. Los históricos conservan sus datos originales.</p>';
            self::form_start($o,$tab);foreach(GE_Organization::fields()[$tab] as $k)self::input($k,$o['settings'][$tab][$k],!$can);if($can)echo '<button>Guardar cambios</button>';echo '</form>';
            if($tab==='integrations') {
                echo '<h3>Referencias seguras</h3><p>Una referencia no confirma conexión. Los secretos se configuran por separado en el Credential Provider.</p>';
                foreach(array('arca','mercado-pago','email','woocommerce','google-drive') as $provider)echo '<p><strong>'.esc_html($provider).'</strong> · '.(!empty($o['settings']['integration_refs'][$provider])?'Referencia registrada, conexión no verificada':'Sin referencia por empresa').'</p>';
                if($can){self::form_start($o,$tab);echo '<label>Integración<select name="values[integration]">';foreach(array('arca','mercado-pago','email','woocommerce','google-drive') as $provider)echo '<option>'.esc_html($provider).'</option>';echo '</select></label><label>Referencia simbólica<input name="values[credential_ref]" pattern="[a-z][a-z0-9-]{2,79}"></label><button>Guardar referencia</button></form>';}
            }
        }
        echo '</section></div>';
    }
    public static function render_profile() {
        $u=wp_get_current_user();echo '<div class="ge-staff-heading"><div><h1>Mi perfil</h1><p>Cuenta personal</p></div></div><section class="ge-company-panel"><h2>'.esc_html($u->display_name).'</h2><p>'.esc_html($u->user_email).'</p><a href="'.esc_url(wp_lostpassword_url(GE_WTP_Staff_Portal::portal_url())).'">Cambiar contraseña</a></section>';
    }
}
