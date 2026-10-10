<?php
require __DIR__.'/../wp-content/mu-plugins/ge-crm-agent/budget.php';
define('ABSPATH',__DIR__);
require __DIR__.'/../wp-content/mu-plugins/ge-crm-agent/agent.php';
$count=0;
function check($v,$name) { global $count; if(!$v) throw new RuntimeException($name); $count++; }
function fails($fn,$name) { try{$fn();}catch(RuntimeException $e){check(true,$name);return;}throw new RuntimeException($name); }
$dir=sys_get_temp_dir().'/ge-agent-test-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
$b=new GE_CRM_Agent_Budget($dir);
check($b->summary('graph-express')['committed']===0,'initial zero');
$c=$b->begin('graph-express','thread1','message1',10000);
check(!$c['cached'],'first request reserved');
fails(function()use($b){$b->begin('graph-express','thread1','message1',10000);},'double request blocked');
check($b->summary('graph-express')['held']===10000,'reservation counted');
check($b->summary('other')['held']===0,'scope separated');
$draft=['respuesta'=>'Hola, ¿qué cantidad necesitás?','resumen'=>'Consulta de tarjetas.','requiere_revision'=>false,'motivo_revision'=>'Faltan datos.','datos_pedido'=>array_fill_keys(['producto','cantidad','medidas','material','terminacion','fecha','archivo'],''),'faltantes'=>['cantidad'],'siguiente_paso'=>'pedir_datos'];
check(GE_CRM_Agent::validate_draft($draft)['requiere_revision']===true,'model cannot waive review');
$cost=$b->finish($c['id'],['input_tokens'=>1000,'output_tokens'=>100],$draft);
check($cost===1200,'cost includes input and output');
check($b->summary('graph-express')['held']===0,'reservation settled');
check($b->summary('graph-express')['charged']===1200,'actual cost retained');
check($b->begin('graph-express','thread1','message1',10000)['cached'],'repeat uses cached draft');
check((new GE_CRM_Agent_Budget($dir))->summary('graph-express')['charged']===1200,'durable across restart');
$c=$b->begin('graph-express','thread1','message2',10000); $b->uncertain($c['id']);
fails(function()use($b){$b->begin('graph-express','thread1','message2',10000);},'timeout not retried');
check($b->summary('graph-express')['held']===10000,'timeout retains reserve');
for($i=0;$i<19;$i++) $b->begin('graph-express','capped','cap'.$i,100000);
$b->begin('graph-express','capped','cap-last',100000);
fails(function()use($b){$b->begin('graph-express','capped','cap-over',1);},'conversation limit enforced');
$s=json_decode(file_get_contents($dir.'/budget.json'),true);
$s['calls']['month-near-limit']=['month'=>GE_CRM_Agent_Budget::month(),'scope'=>'graph-express','thread'=>'seed','fingerprint'=>'seed','status'=>'done','reserve'=>47988700,'cost'=>47988700];
file_put_contents($dir.'/budget.json',json_encode($s));
check($b->summary('graph-express')['warning'],'80 percent warning');
fails(function()use($b){$b->begin('graph-express','new','over-month',101);},'reserve blocks crossing monthly cap');
$c=$b->begin('graph-express','new','at-month',100);
check($b->summary('graph-express')['committed']===50000000,'exact cap permitted');
fails(function()use($b){$b->begin('graph-express','new','after-cap',1);},'cap stops further calls');
fails(function()use($b,$c){$b->finish($c['id'],[],null);},'missing usage not forgiven');
check($b->summary('graph-express')['held']>0,'missing usage retains reserve');
$bad=$draft;$bad['siguiente_paso']='liberar_produccion';fails(function()use($bad){GE_CRM_Agent::validate_draft($bad);},'unauthorized action rejected');
$bad=$draft;unset($bad['datos_pedido']['archivo']);fails(function()use($bad){GE_CRM_Agent::validate_draft($bad);},'partial order rejected');
$bad=$draft;$bad['respuesta']=['tool'=>'send'];fails(function()use($bad){GE_CRM_Agent::validate_draft($bad);},'tool injection rejected');
check(GE_CRM_Agent_Budget::month('2026-11-01T01:00:00Z')==='2026-10','Argentina month boundary');
file_put_contents($dir.'/budget.json','broken');fails(function()use($b){$b->summary('graph-express');},'corrupted ledger fails closed');
echo json_encode(['passed'=>$count,'paid_calls'=>0,'production_data_writes'=>0]).PHP_EOL;
// Retain isolated fixture for reproducibility; no deletion of external directories.
