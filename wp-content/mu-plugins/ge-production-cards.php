<?php
defined('ABSPATH') || exit;
require_once __DIR__.'/ge-production-cards/core.php';
require_once __DIR__.'/ge-production-cards/ui.php';
add_action('plugins_loaded',array('GE_WTP_Production_Cards','init'),40);
