<?php
/** Selective release. Run only on graph-wordpress-prod; no orders or quotes are written. */
if (PHP_SAPI !== 'cli') { exit(1); }
$mode=$argv[1]??'';$release=$argv[2]??'';$backup=$argv[3]??'';
if(!in_array($mode,array('backup','deploy','verify','rollback'),true)||strpos($backup,'/root/ge-backups/volantes-20261008')!==0||strpos($release,'/tmp/ge-volantes-release-')!==0)throw new RuntimeException('Invalid release paths');
$root='/home/graphexpress/public_html';
if(gethostname()!=='vps-3673733-x.dattaweb.com'||!is_file($root.'/wp-load.php'))throw new RuntimeException('Wrong host');
$manifest=json_decode(file_get_contents($release.'/release-manifest.json'),true);
if(!$manifest||empty($manifest['files']))throw new RuntimeException('Missing manifest');
$lock=fopen('/root/ge-backups/volantes-release.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Release locked');
define('DISABLE_WP_CRON',true);$_SERVER['HTTP_HOST']='graphex.ar';$_SERVER['SERVER_NAME']='graphex.ar';$_SERVER['HTTPS']='on';require $root.'/wp-load.php';
if(DB_NAME!=='graphexpress_wp'||home_url()!=='https://graphex.ar')throw new RuntimeException('Wrong WordPress');
add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);
$p=get_page_by_path('volantes-full-color',OBJECT,'product');if(!$p||$p->ID!==81)throw new RuntimeException('Wrong product');
$keys=array('_ge_storefront_config','_ge_reference_price_min','_ge_public_price_sections','_ge_public_price_notes','_ge_supplier_source','_ge_supplier_source_date','_ge_supplier_source_files','_ge_production_calendar','_ge_minimum_dpi','_ge_expected_color_mode','_ge_bleed_mm','_product_attributes','_thumbnail_id');
function product_state($keys){$p=get_post(81);$m=array();foreach($keys as $k)$m[$k]=get_post_meta(81,$k,false);return array('content'=>$p->post_content,'excerpt'=>$p->post_excerpt,'meta'=>$m);}
function business_hash(){global $wpdb;$ids=$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_refund','ge_commercial_quote') ORDER BY ID");$ids=array_map('intval',$ids);$in=$ids?implode(',',$ids):'0';$parts=array($wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE ID IN ($in) ORDER BY ID",ARRAY_A),$wpdb->get_results("SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ($in) ORDER BY meta_id",ARRAY_A),$wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_order_items ORDER BY order_item_id",ARRAY_A),$wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_order_itemmeta ORDER BY meta_id",ARRAY_A));return hash('sha256',serialize($parts));}
function verify_code($manifest,$root){foreach($manifest['files'] as $f=>$sha)if(!is_file($root.'/'.$f)||hash_file('sha256',$root.'/'.$f)!==$sha)throw new RuntimeException('Code mismatch: '.$f);}
if($mode==='backup'){
 if(file_exists($backup))throw new RuntimeException('Backup already exists');mkdir($backup,0700,true);mkdir($backup.'/files',0700,true);
 foreach($manifest['base_hashes'] as $f=>$sha)if(hash_file('sha256',$root.'/'.$f)!==$sha)throw new RuntimeException('Concurrent code change: '.$f);
 $saved=array();foreach($manifest['files'] as $f=>$sha){$old=$root.'/'.$f;$saved[$f]=is_file($old)?hash_file('sha256',$old):null;if(is_file($old)){mkdir(dirname($backup.'/files/'.$f),0700,true);if(!copy($old,$backup.'/files/'.$f)||hash_file('sha256',$backup.'/files/'.$f)!==$saved[$f])throw new RuntimeException('Unusable code backup');}}
 $state=product_state($keys);file_put_contents($backup.'/product-state.ser',serialize($state));if(unserialize(file_get_contents($backup.'/product-state.ser'))!==$state)throw new RuntimeException('Unusable metadata backup');
 $thumb=(int)get_post_thumbnail_id(81);$image=get_attached_file($thumb);$media=array();if($image&&is_file($image)){$media[]=$image;$md=wp_get_attachment_metadata($thumb);foreach(($md['sizes']??array()) as $sz)$media[]=dirname($image).'/'.$sz['file'];}
 mkdir($backup.'/old-media',0700,true);foreach(array_unique($media) as $file)if(is_file($file)){copy($file,$backup.'/old-media/'.basename($file));if(hash_file('sha256',$file)!==hash_file('sha256',$backup.'/old-media/'.basename($file)))throw new RuntimeException('Unusable media backup');}
 file_put_contents($backup.'/backup-manifest.json',json_encode(array('files'=>$saved,'state_hash'=>hash('sha256',serialize($state)),'business_hash'=>business_hash(),'previous_thumbnail'=>$thumb,'release_commit'=>$manifest['commit']),JSON_PRETTY_PRINT));
 echo json_encode(array('backup_verified'=>true,'path'=>$backup,'previous_thumbnail'=>$thumb))."\n";exit;
}
$before=json_decode(file_get_contents($backup.'/backup-manifest.json'),true);$state=unserialize(file_get_contents($backup.'/product-state.ser'));if(!$before||hash('sha256',serialize($state))!==$before['state_hash'])throw new RuntimeException('Invalid backup');
if($mode==='deploy'){
 foreach($before['files'] as $f=>$sha){if($sha===null?file_exists($root.'/'.$f):hash_file('sha256',$root.'/'.$f)!==$sha)throw new RuntimeException('Concurrent deployment: '.$f);}
 if(hash('sha256',serialize(product_state($keys)))!==$before['state_hash'])throw new RuntimeException('Concurrent product change');
 foreach($manifest['files'] as $f=>$sha)if(hash_file('sha256',$release.'/'.$f)!==$sha)throw new RuntimeException('Artifact mismatch: '.$f);
 // Dependencies first; then the module, then consumers. Atomic per-file replacement.
 $files=array_keys($manifest['files']);usort($files,function($a,$b){$rank=function($f){return strpos($f,'mu-plugins/ge-volantes/')!==false?0:($f==='wp-content/mu-plugins/ge-volantes.php'?1:2);};return $rank($a)<=>$rank($b);});
 foreach($files as $f){$dst=$root.'/'.$f;if(!is_dir(dirname($dst)))mkdir(dirname($dst),0755,true);if(!copy($release.'/'.$f,$dst.'.ge-release'))throw new RuntimeException('Copy failed');chmod($dst.'.ge-release',0644);if(!rename($dst.'.ge-release',$dst))throw new RuntimeException('Atomic replacement failed');}
 if(!class_exists('GE_Volantes'))require $root.'/wp-content/mu-plugins/ge-volantes.php';
 $data=GE_Volantes::catalog_product();$attributes=array();$pos=0;foreach($data['attributes'] as $name=>$opts)$attributes[sanitize_title($name)]=array('name'=>$name,'value'=>implode(' | ',$opts),'position'=>$pos++,'is_visible'=>1,'is_variation'=>0,'is_taxonomy'=>0);
 // Avoid WC_Product::save and its historical file-context reanalysis queue.
 wp_update_post(array('ID'=>81,'post_content'=>$data['description'],'post_excerpt'=>$data['description']));
 $meta=array('_ge_storefront_config'=>GE_Volantes::config(),'_ge_reference_price_min'=>$data['minimum'],'_ge_public_price_sections'=>$data['sections'],'_ge_public_price_notes'=>$data['notes'],'_ge_supplier_source'=>$data['source_name'],'_ge_supplier_source_date'=>$data['source_date'],'_ge_supplier_source_files'=>$data['source_files'],'_ge_production_calendar'=>GE_Volantes::defaults(),'_ge_minimum_dpi'=>300,'_ge_expected_color_mode'=>'CMYK','_ge_bleed_mm'=>5,'_product_attributes'=>$attributes);
 foreach($meta as $k=>$v)update_post_meta(81,$k,$v);
 require_once ABSPATH.'wp-admin/includes/image.php';
 $image_source=$root.'/wp-content/mu-plugins/ge-volantes/volantes-graphex-20261008.webp';$attachment=(int)get_post_thumbnail_id(81);$current_image=get_attached_file($attachment);
 if(!$current_image||!is_file($current_image)||hash_file('sha256',$current_image)!==hash_file('sha256',$image_source)) {
  $upload=wp_upload_bits('volantes-graphex-20261008.webp',null,file_get_contents($image_source));if($upload['error'])throw new RuntimeException($upload['error']);
  $attachment=wp_insert_attachment(array('post_title'=>'Volantes full color · Graphex','post_mime_type'=>'image/webp','post_status'=>'inherit'),$upload['file'],81,true);if(is_wp_error($attachment))throw new RuntimeException($attachment->get_error_message());
  wp_update_attachment_metadata($attachment,wp_generate_attachment_metadata($attachment,$upload['file']));update_post_meta($attachment,'_wp_attachment_image_alt','Volantes full color de Graphex en diferentes formatos');update_post_meta(81,'_thumbnail_id',$attachment);
 }
 clean_post_cache(81);wc_delete_product_transients(81);
 file_put_contents($backup.'/deployed-product-state.ser',serialize(product_state($keys)));file_put_contents($backup.'/deployment.json',json_encode(array('commit'=>$manifest['commit'],'thumbnail'=>$attachment,'time'=>gmdate('c')),JSON_PRETTY_PRINT));
 verify_code($manifest,$root);echo json_encode(array('deployed'=>true,'thumbnail'=>$attachment,'commit'=>$manifest['commit']))."\n";exit;
}
if($mode==='verify'){
 verify_code($manifest,$root);if(product_state($keys)!==unserialize(file_get_contents($backup.'/deployed-product-state.ser')))throw new RuntimeException('Product state differs');
 if(business_hash()!==$before['business_hash'])throw new RuntimeException('Commercial data differs: investigate concurrent writes');
 $c=GE_Volantes::config();$o=$c['options'][$c['option_map']['115|15x20|horizontal|double|1000']];if($o['total_net']!==81000||GE_WTP_Storefront::minimum_price(81)<=0)throw new RuntimeException('Price smoke failed');
 $test_item=array('data'=>wc_get_product(81),'product_id'=>81,'ge_configuration_key'=>$c['option_map']['115|15x20|horizontal|double|1000'],'quantity'=>1000);$limits=(new \Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits())->get_cart_item_quantity_limits($test_item);if($limits['minimum']!==1000||$limits['maximum']!==1000||$limits['multiple_of']!==1000||$limits['editable']!==false)throw new RuntimeException('Block cart limits mismatch');
 echo json_encode(array('verified'=>true,'business_unchanged'=>true,'store_api_limits'=>$limits,'files'=>count($manifest['files']),'price_net'=>81000,'price_final'=>98010,'calendar_example'=>GE_Volantes::dispatch('2026-10-13T00:00:00-03:00','115')['date'],'context_job_81'=>get_option('ge_fa_context_job_81','absent')))."\n";exit;
}
if($mode==='rollback'){
 // Refuse to overwrite any work performed after this deployment.
 verify_code($manifest,$root);if(product_state($keys)!==unserialize(file_get_contents($backup.'/deployed-product-state.ser')))throw new RuntimeException('Concurrent product change: selective reconciliation required');
 foreach($before['files'] as $f=>$sha)if($sha!==null){if(hash_file('sha256',$backup.'/files/'.$f)!==$sha)throw new RuntimeException('Invalid backup file');copy($backup.'/files/'.$f,$root.'/'.$f);}
 wp_update_post(array('ID'=>81,'post_content'=>$state['content'],'post_excerpt'=>$state['excerpt']));foreach($state['meta'] as $k=>$values){delete_post_meta(81,$k);foreach($values as $v)add_post_meta(81,$k,$v);}
 foreach($before['files'] as $f=>$sha)if($sha===null){$dst=$backup.'/withdrawn/'.$f;if(!is_dir(dirname($dst)))mkdir(dirname($dst),0700,true);rename($root.'/'.$f,$dst);}
 clean_post_cache(81);wc_delete_product_transients(81);if(product_state($keys)!==$state)throw new RuntimeException('Rollback metadata mismatch');echo "rollback_verified; new unused media retained for traceability\n";
}
