<?php
// Private CLI outside public_html. Contains private records; never print its contents.
define('DISABLE_WP_CRON',true);$_SERVER['HTTP_HOST']='graphex.ar';require '/home/graphexpress/public_html/wp-load.php';wp_set_current_user(1);
if(DB_NAME!=='graphexpress_wp'||!GE_CRM::can(true))exit(2);
$path=getenv('GE_HYGIENE_BACKUP');if(!$path||strpos($path,'/home/graphexpress/releases/newsletter-hygiene-')!==0||file_exists($path))exit(3);
function ge_hygiene_machine($r){$e=$r['attention_event']??array();return ($e['channel']??'')==='email'&&(preg_match('/multipart\/report|delivery-status/i',$e['headers']['content-type']??'')||in_array(GE_CRM_Attention::classify($e)['category'],array('delivery_failure','automated'),true));}
$data=GE_CRM::locked(function()use($wpdb){
 $all=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.GE_CRM::table().' WHERE organization_id=%s',GE_CRM::org()),ARRAY_A);$threads=array();$rows=array();
 foreach($all as $r)if($r['kind']==='thread'&&ge_hygiene_machine(GE_CRM::decode($r))){$threads[(int)$r['id']]=true;$rows[]=$r;}
 foreach($all as $r){$p=json_decode($r['payload'],true);if($r['kind']==='task'&&isset($threads[(int)($p['thread_id']??0)]))$rows[]=$r;}
 return array('at'=>gmdate('c'),'database'=>DB_NAME,'tables'=>array(
 GE_CRM::table()=>$rows,
 $wpdb->prefix.'ge_newsletter_contacts'=>$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ge_newsletter_contacts WHERE audience='first'",ARRAY_A),
 $wpdb->prefix.'ge_newsletter_deliveries'=>$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ge_newsletter_deliveries WHERE campaign_id=4",ARRAY_A)
 ));
});
$bytes=wp_json_encode($data);if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes))exit(4);chmod($path,0600);
$decoded=json_decode(file_get_contents($path),true);if($decoded!==$data)exit(5);
$counts=array();foreach($decoded['tables'] as $table=>$rows){
 $temp='ge_hygiene_drill_'.substr(hash('sha256',$table),0,12);$wpdb->query("CREATE TEMPORARY TABLE `$temp` LIKE `$table`");
 foreach($rows as $row)if(!$wpdb->insert($temp,$row))exit(6);
 $restored=$wpdb->get_results("SELECT * FROM `$temp` ORDER BY id",ARRAY_A);usort($rows,function($a,$b){return (int)$a['id']<=>(int)$b['id'];});
 if($restored!==$rows)exit(7);$counts[$table]=count($rows);$wpdb->query("DROP TEMPORARY TABLE `$temp`");
}
echo wp_json_encode(array('backup_sha256'=>hash_file('sha256',$path),'restore_drill'=>'exact_rows_verified','counts'=>$counts))."\n";
