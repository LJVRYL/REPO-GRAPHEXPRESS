<?php
defined('ABSPATH') || exit;

$ge_email_settings=isset($email)?get_option('woocommerce_'.$email->id.'_settings',array()):array();
if(empty($ge_email_settings['heading'])&&empty($ge_email_settings['heading_full'])&&empty($ge_email_settings['heading_partial']))$email_heading=GE_WTP_Email_WooCommerce::language($email_heading,$email_heading);
// Heading escaping and the table shell are owned by the shared brand renderer.
echo GE_WTP_Email_Branding::header(isset($email_heading) ? $email_heading : ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
