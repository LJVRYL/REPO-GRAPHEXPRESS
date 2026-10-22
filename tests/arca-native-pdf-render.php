<?php
// Offline renderer only. Fixtures/outputs are outside the repository; never connects to ARCA.
define('ABSPATH',dirname(__DIR__));
class WP_Error {public function __construct(public $code,public $message){} public function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v){return json_encode($v);}
function wp_strip_all_tags($v){return strip_tags($v);}
require dirname(__DIR__).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-quotes.php';
require dirname(__DIR__).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-arca-invoice-pdf.php';
$row=json_decode(file_get_contents($argv[1]),true);
$before=serialize($row);$bytes=GE_WTP_ARCA_Invoice_PDF::build($row);
if(is_wp_error($bytes)){throw new Exception($bytes->get_error_message());}
if(serialize($row)!==$before){throw new Exception('Immutable row changed');}
file_put_contents($argv[2],$bytes);
echo json_encode(['pages'=>substr_count($bytes,'/Type /Page '),'sha256'=>hash('sha256',$bytes),'immutable_row_preserved'=>true,'qr_url'=>GE_WTP_ARCA_Invoice_PDF::qr_url($row)]).PHP_EOL;
