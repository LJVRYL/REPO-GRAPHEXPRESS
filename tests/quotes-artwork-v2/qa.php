<?php
require '/home/leo/graph-quotes-artwork-v2-qa-20261002/site/wp-load.php';
if(DB_NAME!=='graph_quotes_artwork_v2'){exit('WRONG_QA_DB');}
add_filter('pre_wp_mail',function(){return true;},100);
$staff=get_users(array('role'=>'administrator','number'=>1))[0]->ID;wp_set_current_user($staff);
function check($ok,$name){echo ($ok?'PASS ':'FAIL ').$name."\n";if(!$ok)exit(1);}
$customer=email_exists('qa-artwork-v2@example.invalid');if(!$customer){$customer=wp_insert_user(array('user_login'=>'qa-artwork-v2','user_email'=>'qa-artwork-v2@example.invalid','user_pass'=>wp_generate_password(32),'display_name'=>'QA Artwork v2','role'=>'customer'));}
$uuids=array(wp_generate_uuid4(),wp_generate_uuid4(),wp_generate_uuid4());$lines=array();
foreach($uuids as $i=>$uuid){$lines[]=array('line_uuid'=>$uuid,'source_type'=>'custom','name'=>'QA Item '.($i+1),'quantity'=>1,'unit'=>'u','unit_net'=>'100','details'=>'QA fixture');}
$q=GE_WTP_Commercial_Quotes::create_draft($customer,$lines,array(),$staff);check(!is_wp_error($q),'3 custom items create');
check(array_column($q['snapshot']['items'],'line_uuid')===$uuids,'stable UUID persisted');
$again=GE_WTP_Commercial_Quotes::revise($q['id'],array_reverse($lines),array('expected_version'=>$q['version']),$staff);check(!is_wp_error($again),'reorder draft');check($again['snapshot']['items'][2]['line_uuid']===$uuids[0],'UUID follows line rather than index');
$dup=$lines;$dup[1]['line_uuid']=$dup[0]['line_uuid'];check(is_wp_error(GE_WTP_Commercial_Quotes::build_snapshot($dup)),'duplicate line UUID rejected');
$root=trailingslashit(GE_WTP_Documents::private_directory());GE_WTP_Documents::ensure_private_directory();$id=wp_generate_uuid4();$stored='qav2-legacy-'.$id.'.pdf';file_put_contents($root.$stored,"%PDF-1.4\n%%EOF\n");
$legacy=array('id'=>$id,'stored_name'=>$stored,'name'=>'legacy-global.pdf','size'=>filesize($root.$stored),'mime'=>'application/pdf','category'=>'arte','analysis'=>array('sha256'=>hash_file('sha256',$root.$stored)));
update_post_meta($q['id'],GE_WTP_Commercial_Quote_Files::META,array($legacy));
$current=GE_WTP_Commercial_Quotes::get($q['id']);$session=wp_generate_uuid4();
$prepared=GE_WTP_Quote_Artwork_V2::prepare($current,$lines,array($id),$session,$staff);check(!is_wp_error($prepared)&&empty($prepared[0]['quote_item_id']),'legacy global stays global');
check(is_wp_error(GE_WTP_Quote_Artwork_V2::prepare($current,array(array('line_uuid'=>$uuids[0],'artwork_refs'=>array(wp_generate_uuid4()))),array(),$session,$staff)),'foreign/missing reference rejected');
file_put_contents(__DIR__.'/qa-context.json',json_encode(array('staff'=>$staff,'customer'=>$customer,'quote_id'=>$q['id'],'uuids'=>$uuids,'legacy_id'=>$id,'session_id'=>$session)));
check(GE_WTP_Quote_Artwork_V2::MAX_FILE===250*1048576,'250MiB matches analyzer');
echo "QA_FIXTURE_READY\n";
