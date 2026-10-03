<?php
defined( 'ABSPATH' ) || exit;

/** Customer-owned fiscal identities. No issuer settings or verification claims. */
final class GE_WTP_Portal_Profiles {
    public static function init() { add_action( 'admin_post_ge_portal_profile', array( __CLASS__, 'handle' ) ); }
    public static function save( $customer, $input, $actor ) {
        if ( (int) $customer !== (int) $actor || ! user_can( $actor, 'read' ) ) { return new WP_Error( 'forbidden', 'Acceso denegado.' ); }
        $id = sanitize_text_field( $input['id'] ?? '' );
        if ( $id && ! GE_WTP_Customer_Branches::find( $customer, $id, true ) ) { return new WP_Error( 'missing', 'Perfil no encontrado.' ); }
        unset( $input['verification_status'], $input['verified_at'], $input['source'], $input['checked_at'], $input['is_default'], $input['tax_preview_token'] );
        if ( 'default' !== $id ) { return GE_WTP_Customer_Branches::save( $customer, $input, $actor ); }
        if ( ! empty( $input['archive'] ) ) {
            update_user_meta( $customer, '_ge_primary_profile_inactive', 'yes' );
            add_user_meta( $customer, '_ge_billing_audit', array( 'at' => gmdate('c'), 'action' => 'archive', 'profile_id' => 'default', 'actor_id' => $actor ) );
            return true;
        }
        try { $result = GE_WTP_Billing::save_profile( $customer, $input, $actor ); }
        catch ( InvalidArgumentException $e ) { return new WP_Error( 'invalid', $e->getMessage() ); }
        update_user_meta( $customer, '_ge_primary_profile_label', sanitize_text_field( $input['label'] ?? '' ) ?: 'Perfil principal' );
        update_user_meta( $customer, '_ge_primary_profile_inactive', 'no' );
        update_user_meta($customer,'_ge_primary_profile_contact',array('contact_name'=>sanitize_text_field($input['contact_name']??''),'contact_phone'=>sanitize_text_field($input['contact_phone']??'')));
        return $result;
    }
    public static function handle() {
        check_admin_referer( 'ge_portal_profile' );
        if ( GE_WTP_Portal::is_staff_preview() || GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Vista protegida.', '', array('response'=>403) ); }
        $result = self::save( get_current_user_id(), wp_unslash( $_POST ), get_current_user_id() );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array('response'=>422) ); }
        wp_safe_redirect( GE_WTP_Portal::portal_url('perfil', array('billing_status'=>'saved')) ); exit;
    }
    public static function render( $customer, $readonly = false ) {
        echo '<section class="ge-profile-card ge-portal-profiles"><h2>Mis perfiles de facturación</h2><p>Guardá las razones sociales que necesitás. Podés elegir una diferente en cada solicitud.</p>';
        foreach ( GE_WTP_Customer_Branches::profiles( $customer, true ) as $p ) {
            echo '<details class="ge-workspace-item"><summary><strong>'.esc_html($p['label']).'</strong><span>'.esc_html($p['legal_name'] ?: 'Completar datos fiscales').' · '.esc_html($p['cuit']).(empty($p['active'])?' · Inactivo':'').'</span></summary>';
            self::form($p,$readonly); echo '</details>';
        }
        echo '<details class="ge-workspace-item"><summary>Agregar perfil fiscal</summary>'; self::form(array(),$readonly); echo '</details></section>';
    }
    private static function form( $p, $readonly ) {
        echo '<form class="ge-profile-fields" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_portal_profile"><input type="hidden" name="id" value="'.esc_attr($p['id']??'').'">'; wp_nonce_field('ge_portal_profile');
        echo '<fieldset'.($readonly?' disabled':'').' class="ge-profile-fields">';
        foreach(array('label'=>'Etiqueta / sucursal','legal_name'=>'Razón social','cuit'=>'CUIT','fiscal_address'=>'Domicilio fiscal','billing_email'=>'Email de facturación','contact_name'=>'Contacto (opcional)','contact_phone'=>'Teléfono (opcional)') as $key=>$label) {
            echo '<label>'.esc_html($label).'<input name="'.esc_attr($key).'" type="'.('billing_email'===$key?'email':'text').'" maxlength="220" value="'.esc_attr($p[$key]??'').'"'.(in_array($key,array('label','legal_name'),true)?' required':'').'></label>';
        }
        echo '<label>Condición fiscal<select name="vat_status">'; foreach(array(''=>'Seleccionar','registered'=>'Responsable inscripto','monotributo'=>'Monotributista','exempt'=>'Exento','final_consumer'=>'Consumidor final') as $k=>$v) { echo '<option value="'.esc_attr($k).'"'.selected($p['vat_status']??'',$k,false).'>'.esc_html($v).'</option>'; } echo '</select></label><input type="hidden" name="billing_mode" value="'.esc_attr($p['billing_mode']??'common').'">';
        echo '<p>'.('verified'===($p['verification_status']??'')?'Datos verificados por nuestro equipo':'Datos declarados · nuestro equipo puede revisarlos').'</p><button type="submit">'.(empty($p['id'])?'Agregar perfil fiscal':'Guardar perfil').'</button>';
        if(!empty($p['id'])&&!empty($p['active'])) { echo '<button type="submit" name="archive" value="1" formnovalidate>Desactivar perfil</button>'; } echo '</fieldset></form>';
    }
    public static function legal() {
        $entity=GE_WTP_Billing::entity();
        echo '<section class="ge-panel"><span class="ge-eyebrow">Información pública</span><h1>Legales</h1><h2>Graph Express</h2><dl>';
        foreach(array('legal_name'=>'Razón social','cuit'=>'CUIT','fiscal_address'=>'Domicilio fiscal') as $k=>$label) { if(!empty($entity[$k])) { echo '<dt>'.esc_html($label).'</dt><dd>'.esc_html($entity[$k]).'</dd>'; } }
        echo '</dl>';
        $privacy=get_privacy_policy_url(); if($privacy) { echo '<p><a href="'.esc_url($privacy).'">Política de privacidad</a></p>'; }
        $terms=wc_get_page_permalink('terms'); if($terms&&'-1'!==$terms) { echo '<p><a href="'.esc_url($terms).'">Términos y condiciones</a></p>'; }
        echo '</section>';
    }
}
