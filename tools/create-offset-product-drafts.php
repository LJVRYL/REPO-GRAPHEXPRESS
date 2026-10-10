<?php
/** Explicit administrative seed; never loaded by WordPress automatically. */
if (PHP_SAPI !== 'cli') { exit(1); }
if (count($argv) !== 4 || $argv[1] !== '--create-drafts') { fwrite(STDERR,"Usage: script --create-drafts products.json private-backup-directory\n"); exit(1); }
define('DISABLE_WP_CRON',true);
$_SERVER['HTTP_HOST']='graphex.ar'; $_SERVER['SERVER_NAME']='graphex.ar'; $_SERVER['HTTPS']='on';
require '/home/graphexpress/public_html/wp-load.php';
if (home_url() !== 'https://graphex.ar') { throw new RuntimeException('Unexpected site'); }
$data=json_decode(file_get_contents($argv[2]),true);
if (!$data || $data['mode']!=='draft_only' || count($data['products'])!==4) { throw new RuntimeException('Invalid plan'); }
$category=get_term_by('slug',$data['category_slug'],'product_cat');
if (!$category) { throw new RuntimeException('Missing category'); }
foreach($data['products'] as $row) {
 if (wc_get_product_id_by_sku($row['sku']) || get_page_by_path($row['slug'],OBJECT,'product')) { throw new RuntimeException('Existing product; do not overwrite: '.$row['slug']); }
}
$published=function(){
 $rows=[]; foreach(wc_get_products(['status'=>'publish','limit'=>-1,'orderby'=>'ID','order'=>'ASC']) as $p) $rows[]=['id'=>$p->get_id(),'slug'=>$p->get_slug(),'price'=>$p->get_price(),'modified'=>$p->get_date_modified() ? $p->get_date_modified()->getTimestamp() : null];
 return $rows;
};
$before=$published(); $backup=$argv[3];
if (strpos($backup,'/root/ge-backups/offset-drafts-')!==0 || file_exists($backup)) { throw new RuntimeException('Backup path must be fresh and private'); }
if(!mkdir($backup,0700,true)) { throw new RuntimeException('Backup unavailable'); }
$snapshot=json_encode(['site'=>home_url(),'existing_targets'=>[],'published_products'=>$before],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
$path=$backup.'/before.json';
if(file_put_contents($path,$snapshot)!==strlen($snapshot) || hash_file('sha256',$path)!==hash('sha256',$snapshot)) { throw new RuntimeException('Backup verification failed'); }
$result=['backup_ref'=>$backup,'backup_sha256'=>hash_file('sha256',$path),'products'=>[]];
foreach($data['products'] as $row) {
 $p=new WC_Product_Simple(); $p->set_name($row['name']); $p->set_slug($row['slug']); $p->set_sku($row['sku']);
 $p->set_status('draft'); $p->set_catalog_visibility('hidden'); $p->set_regular_price(''); $p->set_sale_price('');
 $p->set_short_description($row['short_description']); $p->set_description($row['description']); $p->set_category_ids([(int)$category->term_id]); $p->set_reviews_allowed(false);
 $p->update_meta_data('_ge_quote_only','yes'); $p->update_meta_data('_ge_expansion_key',$row['key']);
 $p->update_meta_data('_ge_expansion_revision',$data['revision']); $p->update_meta_data('_ge_expansion_specification',$row);
 $p->update_meta_data('_ge_commercial_validation_status','pending');
 $id=$p->save(); $check=wc_get_product($id);
 if (!$check || $check->get_status()!=='draft' || $check->get_price()!=='' || $check->is_purchasable() || (class_exists('GE_WTP_Storefront') && GE_WTP_Storefront::config($id))) { throw new RuntimeException('Unexpected sale configuration: '.$id); }
 $result['products'][]=['id'=>$id,'slug'=>$check->get_slug(),'status'=>$check->get_status(),'price'=>$check->get_price(),'purchasable'=>$check->is_purchasable(),'image_id'=>$check->get_image_id(),'content_sha256'=>hash('sha256',$check->get_description()),'admin_url'=>admin_url('post.php?post='.$id.'&action=edit')];
 file_put_contents($backup.'/created.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
$result['published_before_count']=count($before); $result['published_after_count']=count($published()); $result['published_unchanged']=($before===$published());
file_put_contents($backup.'/created.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
if(!$result['published_unchanged']) { throw new RuntimeException('Published catalogue changed concurrently; review'); }
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
