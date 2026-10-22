<?php
// Exercise the actual registered containment callbacks without WordPress or customer data.
$hooks = array();
function add_action($name, $callback, $priority = 10) { $GLOBALS['hooks'][$name] = $callback; }
function is_page($name) { return $GLOBALS['on_management']; }
function nocache_headers() {}
function wp_die($message, $title, $args) { throw new RuntimeException('denied', $args['response']); }
$source = file_get_contents(__DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/ge-webtoprint-calculator.php');
if (strpos($source, "includes/class-ge-wtp-work-reconciliation.php") !== false) { throw new RuntimeException('Historical UI still loaded'); }
$marker = '// Historical standalone UI withdrawn;';
$offset = strpos($source, $marker);
if ($offset === false) { throw new RuntimeException('Containment missing'); }
eval(substr($source, $offset));
if (count($hooks) !== 5) { throw new RuntimeException('Unexpected hook registration'); }
$GLOBALS['on_management'] = true;
$_GET = array('ge_work_reconciliation' => '1');
foreach ($hooks as $callback) {
    try { $callback(); throw new LogicException('Retired endpoint allowed'); }
    catch (RuntimeException $e) { if ($e->getCode() !== 403) { throw $e; } }
}
$_GET = array();
$hooks['template_redirect']();
$panel = file_get_contents(__DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-work-panel.php');
if (strpos($panel, "apply_filters('ge_work_panel_rows'") !== false) { throw new RuntimeException('Historical overlay still active'); }
echo "PASS:5 retired callbacks deny403; native management unaffected; historical loader and overlay removed.\n";
