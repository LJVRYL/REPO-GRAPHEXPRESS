<?php
defined('ABSPATH') || exit;
// Set only native's own site identity variable; native keeps all security data and copy.
$blogname=GE_WTP_Email_Branding::profile()['name'];
include WC()->plugin_path().'/templates/emails/plain/customer-reset-password.php';
