<?php
defined('ABSPATH') || exit;

/** Core mail presentation adapter. Core retains recipients, security links and copy. */
final class GE_WTP_Email_Core {
    private static $initialized = false;

    public static function catalog() {
        $map = array(
            'send_recovery_mode_email'=>array('recovery_mode_email'),
            'wp_send_note_notification'=>array(),
            'wp_site_admin_email_change_notification'=>array('site_admin_email_change_email'),
            'wpmu_signup_blog_notification'=>array('wpmu_signup_blog_notification_email','wpmu_signup_blog_notification_subject'),
            'wpmu_signup_user_notification'=>array('wpmu_signup_user_notification_email','wpmu_signup_user_notification_subject'),
            'newblog_notify_siteadmin'=>array('newblog_notify_siteadmin'),
            'newuser_notify_siteadmin'=>array('newuser_notify_siteadmin'),
            'wpmu_welcome_notification'=>array('update_welcome_email','update_welcome_subject'),
            'wpmu_new_site_admin_notification'=>array('new_site_email'),
            'wpmu_welcome_user_notification'=>array('update_welcome_user_email','update_welcome_user_subject'),
            'update_network_option_new_admin_email'=>array('new_network_admin_email_content'),
            'wp_network_admin_email_change_notification'=>array('network_admin_email_change_email'),
            'wp_notify_postauthor'=>array('comment_notification_subject','comment_notification_text'),
            'wp_notify_moderator'=>array('comment_moderation_subject','comment_moderation_text'),
            'wp_password_change_notification'=>array('wp_password_change_notification_email'),
            'wp_new_user_notification'=>array('wp_new_user_notification_email','wp_new_user_notification_email_admin'),
            'wp_update_user'=>array('password_change_email','email_change_email'),
            'retrieve_password'=>array('retrieve_password_notification_email'),
            'send_confirmation_on_profile_email'=>array('new_user_email_content'),
            '_wp_privacy_send_request_confirmation_notification'=>array('user_request_confirmed_email_subject','user_request_confirmed_email_content'),
            '_wp_privacy_send_erasure_fulfillment_notification'=>array('user_erasure_fulfillment_email_subject','user_erasure_fulfillment_email_content'),
            'wp_send_user_request'=>array('user_request_action_email_subject','user_request_action_email_content'),
            'update_option_new_admin_email'=>array('new_admin_email_content'),
            'send_email'=>array('auto_core_update_email'),
            'send_plugin_theme_email'=>array('auto_plugin_theme_update_email'),
            'send_debug_email'=>array('automatic_updates_debug_email'),
            'wp_new_blog_notification'=>array('wp_installed_email'),
            'wp_privacy_send_personal_data_export_email'=>array('wp_privacy_personal_data_email_subject','wp_privacy_personal_data_email_content'),
            'ms-delete-site'=>array('delete_site_email_content'),
            'user-new'=>array('invited_user_email'),
        );
        $rows = array();
        foreach ($map as $name=>$hooks) $rows['wordpress_'.$name] = array('hooks'=>$hooks, 'native_preview'=>false,
            'preview_mode'=>'signature_reference', 'coverage'=>empty($hooks) ? 'shared_signature_only' : 'core_specific_filters',
            'limitation'=>'Se muestra la firma compartida. El contenido completo depende del evento real y de sus enlaces privados; esta vista no ejecuta ese evento.');
        $network=array('wpmu_signup_blog_notification','wpmu_signup_user_notification','newblog_notify_siteadmin','newuser_notify_siteadmin','wpmu_welcome_notification','wpmu_new_site_admin_notification','wpmu_welcome_user_notification','update_network_option_new_admin_email','wp_network_admin_email_change_notification','ms-delete-site','user-new');
        foreach($rows as $id=>&$row) $row['activation']=in_array(substr($id,10),$network,true)&&!is_multisite()?'Multisite · no activo en esta instancia':'Se ejecuta sólo cuando ocurre su evento de sistema'; unset($row);
        return $rows;
    }

    private static function array_hooks() {
        return array('recovery_mode_email','site_admin_email_change_email','new_site_email','network_admin_email_change_email',
            'wp_password_change_notification_email','wp_new_user_notification_email','wp_new_user_notification_email_admin',
            'password_change_email','email_change_email','retrieve_password_notification_email','auto_core_update_email',
            'auto_plugin_theme_update_email','automatic_updates_debug_email','wp_installed_email','invited_user_email');
    }

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;
        $arrays = self::array_hooks();
        $registered = array();
        foreach (self::catalog() as $row) foreach ($row['hooks'] as $hook) {
            if (isset($registered[$hook])) continue;
            $registered[$hook] = true;
            $callback = in_array($hook, $arrays, true) ? 'email_array' : (strpos($hook, 'subject') !== false ? 'subject' : 'content');
            add_filter($hook, array(__CLASS__, $callback), 90, 1);
        }
    }

    public static function email_array($email) {
        if (!is_array($email)) return $email;
        if (isset($email['subject'])) $email['subject'] = self::subject($email['subject']);
        foreach (array('message','body') as $key) if (isset($email[$key])) $email[$key] = self::content($email[$key]);
        return $email;
    }

    public static function subject($subject) {
        if (!is_string($subject) || !class_exists('GE_WTP_Email_Branding')) return $subject;
        $profile = GE_WTP_Email_Branding::profile();
        $site = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        // Replace only core's generated leading site label, never user text elsewhere.
        foreach (array($site, get_option('blogname')) as $label) {
            $prefix = '[' . $label . ']';
            if ($label !== '' && strpos($subject, $prefix) === 0) return '[' . $profile['name'] . ']' . substr($subject, strlen($prefix));
        }
        return $subject;
    }

    public static function content($content) {
        if (!is_string($content) || !class_exists('GE_WTP_Email_Branding')) return $content;
        $hook = function_exists('current_filter') ? current_filter() : '';
        $before_expansion = array('password_change_email','email_change_email','site_admin_email_change_email','network_admin_email_change_email',
            'new_user_email_content','new_admin_email_content','new_network_admin_email_content','user_request_confirmed_email_content',
            'user_erasure_fulfillment_email_content','user_request_action_email_content','wp_privacy_personal_data_email_content','delete_site_email_content');
        $profile = GE_WTP_Email_Branding::profile();
        // These reserved core placeholders precede native substitution. No links,
        // names, addresses, keys, legal paragraphs or already expanded text change.
        if (in_array($hook, $before_expansion, true)) $content = str_replace('###SITENAME###', $profile['name'], $content);
        if (in_array($hook, array('update_welcome_email','update_welcome_user_email'), true)) {
            $content = str_replace('SITE_NAME', $profile['name'], $content);
        }
        return $content;
    }

    public static function preview($id) {
        $rows = self::catalog();
        if (!isset($rows[$id]) || !class_exists('GE_WTP_Email_Branding')) return new WP_Error('ge_core_preview_unavailable', 'Referencia de correo no disponible.');
        $note = $rows[$id]['limitation'];
        $body = GE_WTP_Email_Branding::render('Referencia de identidad', '<p>'.esc_html($note).'</p>');
        return array('subject'=>'Referencia de identidad', 'body'=>$body, 'plain'=>GE_WTP_Email_Branding::plain($body),
            'fixture'=>true, 'native_preview'=>false, 'preview_mode'=>'signature_reference', 'note'=>$note, 'hooks'=>$rows[$id]['hooks'],
            'attachments'=>array(), 'attachments_note'=>'No se ejecuta el generador del aviso ni se evalúan adjuntos.');
    }
}
