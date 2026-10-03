<?php
defined('ABSPATH') || exit;

/** Short entry point; persistence, ownership and notifications stay in Quote_Requests. */
final class GE_WTP_Quick_Quote {
    public static function requested() {
        return '1' === (string) ($_POST['ge_quote_intent'] ?? $_GET['rapida'] ?? '')
            || '1' === (string) ($_COOKIE['ge_quote_intent'] ?? '');
    }
    public static function remember() {
        if (!is_page('cliente-markcom') || is_user_logged_in() || '1' !== ($_GET['rapida'] ?? '')) return;
        // This cookie contains only a fixed navigation intent. None on HTTPS preserves
        // it through the existing Google Identity redirect POST; auth/CSRF cookies are untouched.
        setcookie('ge_quote_intent', '1', array('expires'=>time()+1800,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>is_ssl()?'None':'Lax'));
        $_COOKIE['ge_quote_intent']='1';
    }
    public static function destination($user) {
        if (!self::requested() || GE_WTP_Portal::is_staff_user($user)) return '';
        setcookie('ge_quote_intent', '', array('expires'=>time()-3600,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'));
        return GE_WTP_Portal::portal_url('personalizado',array('rapida'=>'1'));
    }
    public static function report() {
        if(!current_user_can('ge_manage_operations')&&!current_user_can('manage_woocommerce'))return new WP_Error('forbidden','Acceso denegado.');
        if(class_exists('GE_Organization')&&!GE_Organization::can(GE_Organization::PRIMARY,get_current_user_id()))return new WP_Error('forbidden','Acceso denegado.');
        global $wpdb;
        $out=array('version'=>'graphex-landing-v1','registered'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value=%s",'_ge_registration_source','landing-v1')),'submitted'=>0,'quoted'=>0,'ordered'=>0);
        foreach(get_posts(array('post_type'=>GE_WTP_Quote_Requests::TYPE,'post_status'=>'private','posts_per_page'=>-1,'fields'=>'ids')) as $id){
            $d=get_post_meta($id,GE_WTP_Quote_Requests::META,true);
            if('landing-v1'!==($d['funnel_source']??'')||'draft'===($d['status']??''))continue;
            $out['submitted']++;
            if(!empty($d['quote_id'])){$out['quoted']++;$q=GE_WTP_Commercial_Quotes::get($d['quote_id'],get_current_user_id());if(!is_wp_error($q)&&!empty($q['converted_order_id']))$out['ordered']++;}
        }
        $out['landing_events']='dataLayer; conectar a analytics aprobado para medir clics y abandonos';
        return $out;
    }
    public static function report_download() {
        check_admin_referer('ge_landing_funnel_report');$data=self::report();
        if(is_wp_error($data))wp_die('Acceso denegado.','',array('response'=>403));
        wp_send_json($data);
    }
    public static function registration() {
        ?>
        <form class="ge-portal-login-form ge-quick-register" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ge_customer_register"><input type="hidden" name="ge_quote_intent" value="1"><?php wp_nonce_field('ge_customer_register'); ?><input type="hidden" name="cuenta" value="<?php echo 'empresa'===($_GET['cuenta']??'')?'empresa':''; ?>">
            <p data-auth-progress role="status">Primero, creá tu cuenta. Después contanos qué necesitás.</p>
            <section data-auth-step="1"><h3>¿Cómo te llamás?</h3><p><label for="ge-quick-name">Nombre</label><input id="ge-quick-name" name="first_name" autocomplete="name" required maxlength="100"></p><p><label for="ge-quick-email">Email</label><input id="ge-quick-email" name="email" type="email" autocomplete="email" required maxlength="190"></p></section>
            <section data-auth-step="2"><h3>Protegé tu cuenta</h3><p><label for="ge-quick-password">Contraseña</label><input id="ge-quick-password" name="password" type="password" autocomplete="new-password" required minlength="10"><small>Mínimo 10 caracteres.</small></p><p class="ge-auth-check"><label><input name="terms" type="checkbox" value="1" required> Acepto que Graph Express use estos datos para gestionar mi cuenta y mis pedidos.</label></p><?php if(class_exists('GE_WTP_Turnstile')) GE_WTP_Turnstile::render_widget('portal_register'); ?></section>
            <p><button type="button" data-auth-back hidden>Atrás</button> <button type="button" data-auth-next hidden>Continuar</button><button type="submit" data-auth-submit>Crear cuenta y cotizar</button></p>
            <p>No necesitás datos fiscales para empezar.</p>
        </form>
        <?php
    }
    public static function form() {
        $customer=GE_WTP_Portal::portal_customer_id();$profiles=GE_WTP_Quote_Requests::profiles($customer);
        ?>
        <section class="ge-panel ge-request ge-quick-quote">
            <span class="ge-eyebrow">Hecho a tu medida</span><h1>Contanos qué necesitás</h1><p>Unas preguntas cortas. Nuestro equipo revisa los detalles y prepara tu presupuesto.</p>
            <p data-quick-progress role="status">Tu idea</p><progress data-quick-bar max="7" value="1" aria-label="Progreso de solicitud"></progress>
            <form class="ge-request-form" novalidate>
                <input name="artwork_session" type="hidden" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
                <fieldset <?php disabled(GE_WTP_Portal::is_staff_preview()); ?>>
                <section data-quick-step="intent"><h2>¿Qué necesitás hacer?</h2><div class="ge-quick-choices"><button type="button" data-quick-mode="catalog">Buscar en productos <small>Ya sé qué quiero</small></button><button type="button" data-quick-mode="custom">Contarles mi idea <small>Necesito algo a medida</small></button></div></section>
                <section data-quick-step="catalog" hidden><h2>Encontrá tu producto</h2><label>Buscar productos<input type="search" data-request-search placeholder="Stickers, folletos, vinilos…" maxlength="160"></label><label>Categoría<select data-request-category><option value="">Todas</option><?php $terms=get_terms(array('taxonomy'=>'product_cat','hide_empty'=>true));if(!is_wp_error($terms))foreach($terms as $t)echo '<option value="'.esc_attr($t->slug).'">'.esc_html($t->name).'</option>'; ?></select></label><div data-request-products class="ge-request-products" aria-live="polite"></div><button type="button" data-quick-mode="custom">No lo encuentro, quiero describirlo</button></section>
                <div data-request-items><article data-ge-line class="ge-request-item">
                    <input data-ge-line-uuid name="line_uuid" type="hidden" value="<?php echo esc_attr(wp_generate_uuid4()); ?>"><input name="product_id" type="hidden" value="0"><input name="title" type="hidden"><input name="category" type="hidden"><input name="material" type="hidden"><input name="finishing" type="hidden"><input name="options" type="hidden"><input name="usage" type="hidden"><input name="notes" type="hidden">
                    <section data-quick-step="description" hidden><h2>Contanos qué estás buscando</h2><label for="ge-quick-description">Tu idea<textarea id="ge-quick-description" name="description" rows="5" maxlength="2000" placeholder="Necesito 500 stickers redondos de 5 cm para frascos…"></textarea></label><p>Si sabés la cantidad, medidas o material, podés incluirlos acá.</p></section>
                    <section data-quick-step="quantity" hidden><h2>¿Cuántas unidades necesitás?</h2><p data-quick-product></p><label>Cantidad aproximada<input name="quantity" type="number" inputmode="numeric" min="1" max="100000" step="1" required></label><p>No tiene que ser definitiva: la confirmamos al cotizar.</p></section>
                    <section data-quick-step="measure" hidden><h2>¿Tenés una medida aproximada?</h2><p>Es opcional. Si ya la incluiste en tu descripción, podés continuar.</p><div class="ge-quick-measures"><label>Ancho<input name="width" type="number" inputmode="decimal" min="0.01" max="100000" step="any"></label><label>Alto<input name="height" type="number" inputmode="decimal" min="0.01" max="100000" step="any"></label></div><label>Unidad<select name="measure_unit"><option value="cm">Centímetros</option><option value="mm">Milímetros</option><option value="m">Metros</option></select></label></section>
                    <section data-quick-step="artwork" hidden><h2>¿Tenés un archivo o una referencia?</h2><p>Podés adjuntar una foto o PDF, compartir un enlace o seguir sin archivo.</p><?php GE_WTP_Quote_Artwork_V2::block(0,'',array(),0); ?></section>
                </article></div>
                <section data-quick-step="date" hidden><h2>¿Para cuándo lo necesitás?</h2><label>Fecha aproximada <span>(opcional)</span><input name="needed_by" type="date"></label><label>Plazo<select name="urgency"><option value="normal">Podemos coordinarlo</option><option value="urgent">Lo necesito con urgencia</option></select></label><p>Confirmamos la disponibilidad cuando revisamos tu solicitud.</p></section>
                <?php if(count($profiles)>1): ?><section data-quick-step="profile" hidden><h2>¿Para qué perfil es este trabajo?</h2><label>Perfil<select name="billing_profile_id" required><option value="">Elegí uno de tus perfiles</option><?php foreach($profiles as $p)echo '<option value="'.esc_attr($p['id']).'">'.esc_html($p['label'].' · '.$p['legal_name']).'</option>'; ?></select></label></section><?php else: ?><input name="billing_profile_id" type="hidden" value="<?php echo esc_attr(count($profiles)===1?$profiles[0]['id']:''); ?>"><?php endif; ?>
                <input name="notes" type="hidden" value="">
                <section data-quick-step="review" hidden><h2>Esto es lo que entendimos</h2><div data-request-review></div><p>Te enviamos un presupuesto después de revisar tu solicitud. No se confirma precio ni fecha automáticamente.</p><a data-quick-shop hidden class="ge-button" target="_blank" rel="noopener">También podés ver este producto en la tienda</a><button type="button" data-quick-edit>Editar mi solicitud</button></section>
                <p data-request-notice role="status" aria-live="polite"></p><div class="ge-request-actions"><button type="button" data-quick-back hidden>Atrás</button><button type="button" data-quick-next hidden>Continuar</button><button type="button" data-request-draft hidden>Guardar borrador</button><button type="submit" data-request-submit hidden>Enviar solicitud</button></div><p data-quick-autosave role="status"></p>
                </fieldset>
            </form><noscript><p>Para usar las preguntas guiadas, habilitá JavaScript o <a href="<?php echo esc_url(GE_WTP_Portal::portal_url('personalizado')); ?>">abrí el formulario completo</a>.</p></noscript>
        </section>
        <?php
    }
}
add_action('template_redirect',array('GE_WTP_Quick_Quote','remember'),1);
add_action('admin_post_ge_landing_funnel_report',array('GE_WTP_Quick_Quote','report_download'));
add_action('user_register',function($id){if(GE_WTP_Quick_Quote::requested())update_user_meta($id,'_ge_registration_source','landing-v1');});
