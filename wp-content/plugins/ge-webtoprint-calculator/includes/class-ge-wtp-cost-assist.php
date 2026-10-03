<?php
defined('ABSPATH') || exit;
/** Private outbound queue consumed by the existing AI-GRUPO SSH runtime. */
final class GE_WTP_Cost_Assist {
    public static function request($a){
        GE_WTP_Cost_Engine::permission(true);$list=GE_WTP_Cost_Engine::list_get(absint($a['list_id']??0));
        $health=get_option('ge_cost_assist_runtime',array());if(wp_get_environment_type()==='production'&&(empty($health['available'])||($health['checked_at']??0)<time()-180))throw new RuntimeException('AI-GRUPO no está disponible ahora. Podés continuar la revisión manual.');
        if(!in_array($list['normalized']['status'],array('draft','parsed'),true)||!empty($list['workspace']['active']))throw new RuntimeException('La asistencia requiere una lista sin revisar.');
        return GE_WTP_Cost_Engine::lock('assist-list:'.$list['id'],function()use($list,$a){
            foreach(self::requests($list['id']) as $r)if($r['status']==='queued')return $r;
            $id=wp_insert_post(array('post_type'=>GE_WTP_Cost_Engine::CHANGE,'post_status'=>'private','post_title'=>'AI-GRUPO · lista #'.$list['id']),true);if(is_wp_error($id)||!$id)throw new RuntimeException('No se pudo registrar la tarea.');
            $r=array('request_id'=>(int)$id,'list_id'=>$list['id'],'checksum'=>$list['workspace']['file_hash'],'status'=>'queued','created_by'=>get_current_user_id(),'created_at'=>gmdate('c'),'instruction'=>sanitize_textarea_field($a['instruction']??'Interpretar referencias, unidades, escalas y precios del archivo. Identificar dudas sin inventar valores.'));
            GE_WTP_Cost_Engine::put($id,'_ge_cost_assist',$r);GE_WTP_Cost_Engine::put($id,'_ge_cost_assist_list',$list['id']);GE_WTP_Cost_Engine::audit('ai_requested',$id,null,$r);return $r;
        });
    }
    public static function requests($list_id=0){GE_WTP_Cost_Engine::permission();$args=array('post_type'=>GE_WTP_Cost_Engine::CHANGE,'post_status'=>'private','numberposts'=>20,'fields'=>'ids','meta_key'=>'_ge_cost_assist');if($list_id){$args['meta_key']='_ge_cost_assist_list';$args['meta_value']=$list_id;}$out=array();foreach(get_posts($args) as $id){$r=get_post_meta($id,'_ge_cost_assist',true);if($r)$out[]=$r;}return $out;}
    public static function pending($a=array()){GE_WTP_Cost_Engine::permission();if(PHP_SAPI==='cli')update_option('ge_cost_assist_runtime',array('available'=>!empty($a['runtime_available']),'checked_at'=>time()),false);return array('requests'=>array_values(array_filter(self::requests(),function($r){return $r['status']==='queued';})));}
    public static function packet($a){
        GE_WTP_Cost_Engine::permission();$id=absint($a['request_id']??0);$r=get_post_meta($id,'_ge_cost_assist',true);if(!$r||$r['status']!=='queued')throw new RuntimeException('Tarea no disponible.');$packet=GE_WTP_Cost_Engine::assist_packet(array('list_id'=>$r['list_id']));if(!hash_equals($r['checksum'],$packet['checksum']))throw new RuntimeException('El archivo cambió.');
        $packet['request']=$r;$packet['current_costs']=array_slice($packet['current_costs'],0,100);$packet['comparison']=array('counts'=>$packet['comparison']['counts']);$packet['items']=array_slice($packet['items'],0,500);
        if(isset($packet['extraction']['rows'])){$packet['extraction']['truncated']=count($packet['extraction']['rows'])>500;$packet['extraction']['rows']=array_slice($packet['extraction']['rows'],0,500);}if(isset($packet['extraction']['text']))$packet['extraction']['text']=mb_substr($packet['extraction']['text'],0,50000);
        $list=GE_WTP_Cost_Engine::list_get($r['list_id']);$base=realpath(WP_CONTENT_DIR.'/ge-private/supplier-workspace/'.$list['supplier_id']);$path=realpath($base.'/'.$list['workspace']['stored_name']);
        if(!$base||!$path||strpos($path,$base.DIRECTORY_SEPARATOR)!==0||!hash_equals($r['checksum'],hash_file('sha256',$path)))throw new RuntimeException('Original privado inválido.');
        $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));if(in_array($ext,array('png','jpg','jpeg'),true)){
            $editor=wp_get_image_editor($path);if(is_wp_error($editor))throw new RuntimeException('No se pudo preparar imagen de lectura.');$editor->resize(1600,1600,false);$editor->set_quality(75);$temp=wp_tempnam('cost-assist.jpg');try{$saved=$editor->save($temp,'image/jpeg');if(is_wp_error($saved)||filesize($saved['path'])>700000)throw new RuntimeException('Imagen demasiado grande para la lectura privada.');$packet['images']=array('data:image/jpeg;base64,'.base64_encode(file_get_contents($saved['path'])));}finally{if(!empty($saved['path'])&&is_file($saved['path']))unlink($saved['path']);if(is_file($temp))unlink($temp);}
        }
        if($ext==='pdf'&&empty($packet['extraction']['rows'])){
            $python=defined('GE_FILE_ANALYZER_PYTHON')?GE_FILE_ANALYZER_PYTHON:'/opt/ge-file-analyzer/runtime/bin/python3';
            $proc=@proc_open(array($python,GE_WTP_PLUGIN_DIR.'bin/supplier-assist-images.py',$path),array(array('pipe','r'),array('pipe','w'),array('pipe','w')),$pipes,null,array('PATH'=>dirname($python).':/usr/bin:/bin','LC_ALL'=>'C'));
            if(!is_resource($proc))throw new RuntimeException('No se pudo preparar PDF privado.');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$raw='';$start=microtime(true);
            do{$raw.=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);$status=proc_get_status($proc);if(strlen($raw)>1000000||microtime(true)-$start>12){proc_terminate($proc,9);break;}if($status['running'])usleep(20000);}while($status['running']);$raw.=stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);
            $pages=json_decode($raw,true);if(empty($pages['images']))throw new RuntimeException('PDF sin imágenes de lectura disponibles.');$packet['images']=$pages['images'];unset($pages['images']);$packet['pdf_preview']=$pages;
        }
        return $packet;
    }
    public static function result($a){
        GE_WTP_Cost_Engine::permission(true);$id=absint($a['request_id']??0);return GE_WTP_Cost_Engine::lock('assist:'.$id,function()use($id,$a){$r=get_post_meta($id,'_ge_cost_assist',true);if(!$r)throw new RuntimeException('Tarea inexistente.');if($r['status']!=='queued')return $r;
            if(!hash_equals($r['checksum'],$a['checksum']??''))throw new RuntimeException('Checksum de tarea inválido.');
            $r['finished_at']=gmdate('c');$r['task_ref']=sanitize_text_field($a['task_ref']??'');
            try{if(!empty($a['error']))throw new RuntimeException(sanitize_textarea_field($a['error']));GE_WTP_Cost_Engine::propose(array('list_id'=>$r['list_id'],'items'=>$a['items']??array(),'task_ref'=>$r['task_ref'],'notes'=>$a['notes']??''));$r['status']='completed';$r['review_required']=true;}
            catch(Throwable $e){$r['status']='failed';$r['error']=$e->getMessage();}
            GE_WTP_Cost_Engine::put($id,'_ge_cost_assist',$r);GE_WTP_Cost_Engine::audit('ai_finished',$id,null,$r);return $r;
        });
    }
}
