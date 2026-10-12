<?php
defined('ABSPATH') || exit;

// Preserve the store's legal/contact footer and WooCommerce's existing filters.
$ge_footer_text=get_option('woocommerce_email_footer_text');
$ge_footer_text=preg_replace('/^\{site_title\}(?:<br\s*\/?\s*>|\r?\n)/u','',(string)$ge_footer_text,1);
$ge_footer_text=str_replace('{site_title}',esc_html(GE_WTP_Email_Branding::profile()['name']),$ge_footer_text);
$ge_profile=GE_WTP_Email_Branding::profile();
$ge_footer_text=str_replace('{store_name}',esc_html($ge_profile['name']),$ge_footer_text);
$ge_footer_text=str_replace('{store_email}',esc_html($ge_profile['email']),$ge_footer_text);
$ge_settings=class_exists('GE_Organization_Runtime')?GE_Organization_Runtime::settings():array();
$ge_display=$ge_settings['general']['display_name']??''; $ge_legal=$ge_settings['general']['legal_name']??'';
if ($ge_display!=='' && $ge_display!==$ge_legal) $ge_footer_text=preg_replace('/^'.preg_quote($ge_display,'/').'(?:<br\s*\/?\s*>|\r?\n|$)/u','',(string)$ge_footer_text,1);
$ge_footer_text = apply_filters('woocommerce_email_footer_text', $ge_footer_text, isset($email) ? $email : null);
$ge_footer_html = wp_kses_post(wpautop(wptexturize($ge_footer_text)));
echo GE_WTP_Email_Branding::footer($ge_footer_html); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
