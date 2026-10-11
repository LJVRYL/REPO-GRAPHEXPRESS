<?php
/** Explicit CLI-only image release. Never loaded as a plugin. */
if(PHP_SAPI!=='cli'||count($argv)!==4||!in_array($argv[1],['--preflight','--apply','--verify','--rollback'],true)) exit(1);
define('DISABLE_WP_CRON',true);
$_SERVER['HTTP_HOST']='graphex.ar';$_SERVER['SERVER_NAME']='graphex.ar';$_SERVER['HTTPS']='on';
require '/home/graphexpress/public_html/wp-load.php';
if(home_url()!=='https://graphex.ar')throw new RuntimeException('Unexpected site');
$plan=json_decode(file_get_contents($argv[2]),true);$backup=$argv[3];$mode=$argv[1];
if(!$plan||count($plan['bindings'])!==17||count($plan['assets'])!==22||strpos($backup,'/root/ge-backups/bags-images-')!==0)throw new RuntimeException('Invalid release');
if(hash_file('sha256',get_stylesheet_directory().'/assets/images/graphex-simbolo.svg')!==$plan['assets'][0]['logo_source_sha256'])throw new RuntimeException('Official logo drift');
if(count(array_unique(array_column($plan['bindings'],'id')))!==17||count(array_unique(array_column($plan['assets'],'key')))!==22)throw new RuntimeException('Ambiguous mapping');
function bag_protected($id){$m=get_post_meta($id);unset($m['_thumbnail_id'],$m['_product_image_gallery']);ksort($m);return hash('sha256',serialize([$GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare("SELECT * FROM {$GLOBALS['wpdb']->posts} WHERE ID=%d",$id),ARRAY_A),$m]));}
function bag_visible_state($id){return ['thumbnail'=>(int)get_post_meta($id,'_thumbnail_id',true),'gallery'=>(string)get_post_meta($id,'_product_image_gallery',true),'protected'=>bag_protected($id)];}
if($mode==='--preflight'||$mode==='--apply'){
 foreach($plan['bindings'] as $b){$p=wc_get_product($b['id']);if(!$p||$p->get_status()!=='publish'||$p->get_sku()!==$b['sku']||$p->get_slug()!==$b['slug']||$p->get_image_id()!=$b['expected_thumbnail']||$p->get_gallery_image_ids()!==$b['expected_gallery'])throw new RuntimeException('Product drift '.$b['id']);}
 foreach($plan['assets'] as $a){$f='/home/graphexpress/public_html/'.$a['asset'];if(!is_file($f)||hash_file('sha256',$f)!==$a['sha256'])throw new RuntimeException('Asset mismatch '.$a['key']);}
 if($mode==='--preflight'){echo json_encode(['preflight'=>true,'products'=>17,'assets'=>22]);exit;}
 if(file_exists($backup)||!mkdir($backup,0700,true))throw new RuntimeException('Fresh backup required');
 $snapshot=['products'=>[],'original_files'=>[]];
 foreach($plan['bindings'] as $b){$id=$b['id'];$snapshot['products'][$id]=['state'=>bag_visible_state($id),'meta'=>get_post_meta($id),'post'=>get_post($id,ARRAY_A)];
  foreach(array_unique(array_merge([$b['expected_thumbnail']],$b['expected_gallery'])) as $aid){$f=get_attached_file($aid);if(!is_file($f))throw new RuntimeException('Original missing');$dst=$backup.'/original-'.$aid.'-'.basename($f);if(!copy($f,$dst)||hash_file('sha256',$dst)!==hash_file('sha256',$f))throw new RuntimeException('Original backup failed');$snapshot['original_files'][$aid]=['source'=>$f,'backup'=>$dst,'sha256'=>hash_file('sha256',$dst),'meta'=>get_post_meta($aid)];}
 }
 $snap=json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$f=$backup.'/before.json';if(file_put_contents($f,$snap)!==strlen($snap)||hash_file('sha256',$f)!==hash('sha256',$snap))throw new RuntimeException('Snapshot verification failed');
 require_once ABSPATH.'wp-admin/includes/image.php';
 $journal=['backup_sha256'=>hash_file('sha256',$f),'attachments'=>[],'products'=>[]];
 foreach($plan['assets'] as $a){$source='/home/graphexpress/public_html/'.$a['asset'];$upload=wp_upload_bits('ge-bag-'.$a['key'].'-20261011.webp',null,file_get_contents($source));if(!empty($upload['error']))throw new RuntimeException($upload['error']);$f=$upload['file'];if(hash_file('sha256',$f)!==$a['sha256'])throw new RuntimeException('Upload changed bytes');$type=wp_check_filetype($f);$id=wp_insert_attachment(['post_mime_type'=>$type['type'],'post_title'=>$a['title'],'post_content'=>$a['description'],'post_excerpt'=>$a['description'],'post_status'=>'inherit'],$f,0,true);if(is_wp_error($id))throw new RuntimeException($id->get_error_message());update_post_meta($id,'_wp_attachment_image_alt',$a['alt']);wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$f));$journal['attachments'][$a['key']]=['id'=>$id,'url'=>wp_get_attachment_url($id),'sha256'=>$a['sha256']];file_put_contents($backup.'/journal.json',json_encode($journal,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}
 foreach($plan['bindings'] as $b){$id=$b['id'];if(bag_visible_state($id)!==$snapshot['products'][$id]['state'])throw new RuntimeException('Concurrent edit '.$id);$new=$journal['attachments'][$b['featured']]['id'];$gallery=array_map(function($key)use($journal){return $journal['attachments'][$key]['id'];},$b['gallery']);
  update_post_meta($id,'_thumbnail_id',$new);update_post_meta($id,'_product_image_gallery',implode(',',$gallery));wc_delete_product_transients($id);clean_post_cache($id);
  $after=bag_visible_state($id);if($after['protected']!==$snapshot['products'][$id]['state']['protected'])throw new RuntimeException('Protected fields changed '.$id);
  $journal['products'][$id]=['before'=>$snapshot['products'][$id]['state'],'after'=>$after,'url'=>get_permalink($id)];file_put_contents($backup.'/journal.json',json_encode($journal,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
 }
 echo json_encode($journal,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);exit;
}
$snapshot=json_decode(file_get_contents($backup.'/before.json'),true);$journal=json_decode(file_get_contents($backup.'/journal.json'),true);
if(count($journal['products'])!==17)throw new RuntimeException('Incomplete journal; review partial release');
if(hash_file('sha256',$backup.'/before.json')!==$journal['backup_sha256'])throw new RuntimeException('Backup integrity failure');
foreach($snapshot['original_files'] as $f)if(hash_file('sha256',$f['backup'])!==$f['sha256'])throw new RuntimeException('Original backup corrupted');
foreach($journal['products'] as $id=>$r)if(bag_visible_state($id)!==$r['after'])throw new RuntimeException('Post-release drift '.$id);
foreach($journal['attachments'] as $a)if(hash_file('sha256',get_attached_file($a['id']))!==$a['sha256'])throw new RuntimeException('Published file drift');
if($mode==='--rollback'){foreach($journal['products'] as $id=>$r){update_post_meta($id,'_thumbnail_id',$r['before']['thumbnail']);update_post_meta($id,'_product_image_gallery',$r['before']['gallery']);wc_delete_product_transients($id);clean_post_cache($id);}echo json_encode(['rolled_back'=>17,'new_attachments_preserved'=>true]);exit;}
echo json_encode(['verified'=>true,'products'=>17,'assets'=>22,'protected_fields_unchanged'=>true,'original_backups_verified'=>count($snapshot['original_files']),'backup_sha256'=>$journal['backup_sha256']],JSON_PRETTY_PRINT);
