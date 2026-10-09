<?php
// Isolated disposable files only; no WordPress bootstrap, API or customer records.
$fixture=sys_get_temp_dir().'/ge-canva-retention-fixture-'.bin2hex(random_bytes(6));
mkdir($fixture,0700);mkdir($fixture.'/public',0700);mkdir($fixture.'/private',0700);
define('ABSPATH',$fixture.'/public/');define('GE_WTP_PRIVATE_UPLOAD_DIR',$fixture.'/private');
$GLOBALS['opts']=array();$GLOBALS['orders']=array();$passed=0;$created=array();
function add_action(){} function add_filter(){}
function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;}function update_option($k,$v,$a=false){$GLOBALS['opts'][$k]=$v;return true;}function delete_option($k){unset($GLOBALS['opts'][$k]);}
function wc_get_order($id){return $GLOBALS['orders'][$id]??false;}
class RetentionFixtureOrder {public $status='completed',$user=7,$hold='';function get_customer_id(){return $this->user;}function get_date_created(){return new DateTimeImmutable('2020-02-29T12:00:00Z');}function get_status(){return $this->status;}function get_meta($k){return $this->hold;}}
class RetentionFixtureDB {public $options='fixture_options';function prepare($q,...$a){return array($q,$a);}function get_col($p){$names=array();foreach($GLOBALS['opts'] as $name=>$v){if(preg_match('/^ge_cc_retention_due_[a-f0-9]{64}$/D',$name)&&is_numeric($v)&&(int)$v<=$p[1][0])$names[]=$name;}return array_slice($names,0,100);}}
$GLOBALS['wpdb']=new RetentionFixtureDB;
require dirname(__DIR__).'/wp-content/mu-plugins/ge-cards-canva/class-ge-cards-canva-retention.php';
function check($v,$name){global $passed;if(!$v)throw new RuntimeException($name);$passed++;}
function file_fixture($n,$age=0){global $created;$r='pending/7/2026/10/11111111-1111-4111-8111-111111111111/'.$n.'.pdf';$p=GE_WTP_PRIVATE_UPLOAD_DIR.'/'.$r;if(!is_dir(dirname($p)))mkdir(dirname($p),0700,true);file_put_contents($p,'%PDF-1.7 synthetic '.$n);if($age)touch($p,time()-$age);$created[]=$p;return array($p,$r,hash_file('sha256',$p));}
function tracked($n,$age=0){$f=file_fixture($n,$age);check(GE_Cards_Canva_Retention::track($f[0],$f[2],7,'graph-express'),'Valid tracked '.$n);return $f;}
function key_for($r){return GE_Cards_Canva_Retention::PREFIX.hash('sha256',$r);}
[$p,$r,$sha]=tracked('future');$row=get_option(key_for($r));check($row['expires_at']-$row['created_at']===30*86400,'30 days without purchase');
check(!GE_Cards_Canva_Retention::pending_expired(true,$p),'Shared 7-day sweep preserves Canva import');
$expiry=$row['expires_at'];GE_Cards_Canva_Retention::track($p,$sha,7,'graph-express');check(get_option(key_for($r))['expires_at']===$expiry,'Re-analysis does not extend retention');
check(!GE_Cards_Canva_Retention::track($p,$sha,8,'graph-express'),'Foreign user cannot register file');
check(!GE_Cards_Canva_Retention::track($p,str_repeat('a',64),7,'graph-express'),'Wrong bytes cannot register file');
[$foreign]=file_fixture('foreign',40*86400);check(GE_Cards_Canva_Retention::pending_expired(true,$foreign),'Unregistered files keep shared policy');
$outside=$fixture.'/outside.pdf';file_put_contents($outside,'%PDF-fixture');$created[]=$outside;check(!GE_Cards_Canva_Retention::track($outside,hash_file('sha256',$outside),7,'graph-express'),'Outside private root rejected');
[$expired,$er,$esha]=tracked('expired',31*86400);$dry=GE_Cards_Canva_Retention::cleanup(true);check($dry['eligible']===1&&file_exists($expired),'Dry run does not delete');check(GE_Cards_Canva_Retention::cleanup()['deleted']===0,'Disabled kill switch does not delete');
update_option(GE_Cards_Canva_Retention::ENABLED,'yes');$done=GE_Cards_Canva_Retention::cleanup();check($done['deleted']===1&&!file_exists($expired),'Expired original deleted');check(file_exists($p)&&file_exists($foreign)&&file_exists($outside),'Current and unrelated files preserved');check(!get_option(GE_Cards_Canva_Retention::DUE.hash('sha256',$er)),'Deleted file leaves no expiry task');
[$changed,$cr]=tracked('changed',31*86400);file_put_contents($changed,'%PDF-changed-content');check(GE_Cards_Canva_Retention::cleanup()['held']===1&&file_exists($changed),'Modified bytes held');
[$purchase,$pr,$psha]=tracked('order',400*86400);$dest='orders/901/2/fixture-order.pdf';mkdir(dirname(GE_WTP_PRIVATE_UPLOAD_DIR.'/'.$dest),0700,true);rename($purchase,GE_WTP_PRIVATE_UPLOAD_DIR.'/'.$dest);$created[]=GE_WTP_PRIVATE_UPLOAD_DIR.'/'.$dest;$GLOBALS['orders'][901]=new RetentionFixtureOrder;
GE_Cards_Canva_Retention::finalized(array('provider'=>'vps','relative_path'=>$dest),$pr,901,2);$orderRow=get_option(key_for($pr));check($orderRow['order_id']===901&&$orderRow['relative_path']===$dest,'Tracking follows exact finalized order file');check($orderRow['expires_at']===(new DateTimeImmutable('@'.$orderRow['associated_at']))->modify('+12 months')->getTimestamp(),'12 calendar months from association, including old orders');
$orderRow['expires_at']=time()-1;update_option(key_for($pr),$orderRow);update_option(GE_Cards_Canva_Retention::DUE.hash('sha256',$pr),$orderRow['expires_at']);
$GLOBALS['orders'][901]->status='processing';check(GE_Cards_Canva_Retention::cleanup(true)['held']>=1&&file_exists(GE_WTP_PRIVATE_UPLOAD_DIR.'/'.$dest),'Active production order preserved');$GLOBALS['orders'][901]->status='completed';$GLOBALS['orders'][901]->hold='yes';check(GE_Cards_Canva_Retention::cleanup(true)['eligible']===0,'Explicit operational hold preserved');$GLOBALS['orders'][901]->hold='';$GLOBALS['orders'][901]->user=8;check(GE_Cards_Canva_Retention::cleanup(true)['eligible']===0,'Foreign order ownership held');$GLOBALS['orders'][901]->user=7;check(GE_Cards_Canva_Retention::cleanup()['deleted']===1&&!file_exists(GE_WTP_PRIVATE_UPLOAD_DIR.'/'.$dest),'Completed expired order original deleted without deleting order');
check(get_option(GE_Cards_Canva_Retention::DUE.hash('sha256',$cr))>time(),'Held file defers next check without blocking newer due files');
// Symlink substitution must fail closed.
[$link,$lr]=tracked('link',31*86400);unlink($link);symlink($outside,$link);check(GE_Cards_Canva_Retention::cleanup(true)['held']>=1&&file_exists($outside),'Symlink substitution never deletes external target');
foreach($created as $f){if(is_link($f)||is_file($f))unlink($f);} // all exclusively created fixture files
$dirs=array($fixture.'/private/orders/901/2',$fixture.'/private/orders/901',$fixture.'/private/orders',$fixture.'/private/pending/7/2026/10/11111111-1111-4111-8111-111111111111',$fixture.'/private/pending/7/2026/10',$fixture.'/private/pending/7/2026',$fixture.'/private/pending/7',$fixture.'/private/pending',$fixture.'/private',$fixture.'/public',$fixture);foreach($dirs as $dir){if(is_dir($dir))rmdir($dir);}
echo json_encode(array('passed'=>$passed,'fixture_only'=>true,'customer_files_deleted'=>0,'network_calls'=>0)).PHP_EOL;