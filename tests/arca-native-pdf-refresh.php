<?php
// Local synthetic storage fixture around the actual native publisher. No real DB or ARCA.
define('ABSPATH',dirname(__DIR__));
class WP_Error {public function __construct(public $code,public $message){} public function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v){return json_encode($v);} function wp_strip_all_tags($v){return strip_tags($v);}
function trailingslashit($v){return rtrim($v,'/\\').DIRECTORY_SEPARATOR;}
class SyntheticWPDB {
 public $busy=false;
 function prepare($sql,$value){return str_replace('%s',"'".$value."'",$sql);}
 function get_var($sql){if(strpos($sql,'SELECT GET_LOCK(')===0)return $this->busy?'0':'1';if(strpos($sql,'SELECT RELEASE_LOCK(')===0)return '1';throw new Exception('Unexpected DB access');}
}
$wpdb=new SyntheticWPDB();
class SyntheticOrder {
 public $docs=[],$saves=0,$notes=[],$on_refresh=null;
 function get_id(){return 700;}
 function read_meta_data($force){if($this->on_refresh){$f=$this->on_refresh;$this->on_refresh=null;$f();}}
 function get_meta($key,$single){return $this->docs;}
 function update_meta_data($key,$value){if($key!==GE_WTP_Documents::META_KEY)throw new Exception('Unexpected metadata change');$this->docs=$value;}
 function save(){$this->saves++;}
 function add_order_note($text){$this->notes[]=$text;}
}
$order=new SyntheticOrder(); function wc_get_order($id){global $order;return $id===700?$order:false;}
class GE_WTP_Documents {
 const META_KEY='_synthetic_documents';
 static function private_directory(){return getenv('GE_NATIVE_PDF_QA_DIR');}
 static function ensure_private_directory(){return is_dir(self::private_directory());}
 static function get_documents($id){global $order;return $order->docs;}
}
class GE_WTP_Customer_Invoices {
 static function file_path($row){$name=$row['file']['stored_name']??'';if(basename($name)!==$name)return ''; $p=GE_WTP_Documents::private_directory().DIRECTORY_SEPARATOR.$name;return is_file($p)?$p:'';}
}
require dirname(__DIR__).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-quotes.php';
require dirname(__DIR__).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-arca-invoice-pdf.php';
$row=['id'=>7,'number'=>7,'state'=>'authorized','authorization'=>['cae'=>'11111111111111','expires'=>'20260111'],'payload'=>['order_id'=>700,'environment'=>'production','class'=>'C','issuer'=>['legal_name'=>'SYNTHETIC ISSUER','cuit'=>'SYNTHETIC-ISSUER','iibb'=>'SYNTHETIC','fiscal_address'=>'Synthetic address'],'receiver'=>['legal_name'=>'SYNTHETIC RECEIVER','cuit'=>'20000000001','fiscal_address'=>'Synthetic recipient address'],'header'=>['PtoVta'=>11,'CbteTipo'=>11],'detail'=>['DocTipo'=>80,'DocNro'=>'20000000001','CbteFch'=>'20260101','Concepto'=>1,'CondicionIVAReceptorId'=>1,'ImpTotal'=>'25.50','ImpNeto'=>'25.50','ImpIVA'=>'0.00','ImpTrib'=>'0.00','MonId'=>'PES','MonCotiz'=>1],'activity_start'=>'2018-04-01','sale_terms'=>'Contado','items'=>[['name'=>'Synthetic item','quantity'=>1,'net'=>'25.50']],'shipping_net'=>'0.00','fees'=>[],'approved_by'=>1]];
$data="%PDF-1.4\nSYNTHETIC PREVIOUS PRESENTATION ONLY\n%%EOF\n";
$old=['id'=>'arca-7','version_id'=>'arca-7','arca_invoice_id'=>7,'document_number'=>'00011-00000007','cae'=>$row['authorization']['cae'],'issuer_snapshot'=>$row['payload']['issuer'],'billing_profile_snapshot'=>$row['payload']['receiver'],'stored_name'=>'previous.pdf','sha256'=>hash('sha256',$data),'uploaded_at'=>'2026-01-01 00:00:00','uploaded_by'=>1];
file_put_contents(GE_WTP_Documents::private_directory().'/previous.pdf',$data);$order->docs=[$old];
$checks=[];function check($name,$ok){global $checks;if(!$ok)throw new Exception($name);$checks[]=$name;}
$before=serialize($row);
$same=GE_WTP_ARCA_Invoice_PDF::publish($row);check('Ordinary publish keeps existing PDF until explicit native refresh',$same===$old&&$order->saves===0);
$new=GE_WTP_ARCA_Invoice_PDF::publish($row,true);check('Native refresh succeeds',!is_wp_error($new));
check('Exactly one active invoice with stable references',count($order->docs)===1&&$new['id']===$old['id']&&$new['version_id']===$old['version_id']);
check('History preserves old record and exact original bytes',count($new['presentation_history'])===1&&$new['presentation_history'][0]['sha256']===$old['sha256']&&file_get_contents(GE_WTP_Documents::private_directory().'/previous.pdf')===$data);
check('Financial number CAE issuer receiver and uploaded metadata unchanged',$new['document_number']===$old['document_number']&&$new['cae']===$old['cae']&&$new['issuer_snapshot']===$old['issuer_snapshot']&&$new['billing_profile_snapshot']===$old['billing_profile_snapshot']&&$new['uploaded_at']===$old['uploaded_at']);
check('New native PDF exact stored hash',hash_file('sha256',GE_WTP_Documents::private_directory().'/'.$new['stored_name'])===$new['sha256']);
check('Immutable fiscal row unchanged',serialize($row)===$before);
$again=GE_WTP_ARCA_Invoice_PDF::publish($row,true);check('Idempotent refresh no repeated save note or history',$again===$new&&$order->saves===1&&count($order->notes)===1);
$bad=$row;$bad['state']='uncertain';check('Uncertain row cannot refresh',is_wp_error(GE_WTP_ARCA_Invoice_PDF::publish($bad,true)));
$order->docs=[$old];$order->docs[0]['cae']='22222222222222';check('Mismatched CAE rejects replacement',is_wp_error(GE_WTP_ARCA_Invoice_PDF::publish($row,true)));
$order->docs=[$old];$order->docs[0]['issuer_snapshot']['cuit']='DIFFERENT';check('Mismatched issuer rejects replacement',is_wp_error(GE_WTP_ARCA_Invoice_PDF::publish($row,true)));
$order->docs=[$old];file_put_contents(GE_WTP_Documents::private_directory().'/previous.pdf','CORRUPTED');check('Corrupt old original rejects refresh',is_wp_error(GE_WTP_ARCA_Invoice_PDF::publish($row,true)));file_put_contents(GE_WTP_Documents::private_directory().'/previous.pdf',$data);
$wpdb->busy=true;check('Concurrent refresh lock rejects write',is_wp_error(GE_WTP_ARCA_Invoice_PDF::publish($row,true)));$wpdb->busy=false;
$order->docs=[$old];$order->docs[0]['stored_name']='missing-previous.pdf';$recovered=GE_WTP_ARCA_Invoice_PDF::publish($row,true);check('Missing PDF regenerated from authorized journal without new fiscal request',!is_wp_error($recovered)&&!$recovered['presentation_history'][0]['presentation_file_available']);
$order->docs=[];$fresh=GE_WTP_ARCA_Invoice_PDF::publish($row);check('First publication natively uses compact branded PDF without second copy',!is_wp_error($fresh)&&count($order->docs)===1&&$fresh['pdf_render_revision']===GE_WTP_ARCA_Invoice_Compact_PDF::REVISION&&strpos(file_get_contents(GE_WTP_Documents::private_directory().'/'.$fresh['stored_name']),'/Jost-Regular')!==false&&empty($fresh['presentation_history']));
$order->docs=[$old];$order->on_refresh=function()use($order){$order->docs[]=['id'=>'unrelated-upload'];};$merged=GE_WTP_ARCA_Invoice_PDF::publish($row,true);check('Unrelated concurrent document retained on metadata reload',!is_wp_error($merged)&&count($order->docs)===2&&$order->docs[1]['id']==='unrelated-upload');
$order->docs=[$old];$order->on_refresh=function()use($order){$order->docs[0]['sha256']=str_repeat('a',64);};$saves=$order->saves;$conflict=GE_WTP_ARCA_Invoice_PDF::publish($row,true);check('Changed active document rejected before metadata save',is_wp_error($conflict)&&$order->saves===$saves);
echo json_encode(['status'=>'PASS','count'=>count($checks),'checks'=>$checks,'real_database_access'=>false,'arca_transport_loaded'=>false,'emails_or_fiscal_requests'=>0]).PHP_EOL;
