<?php
define('ABSPATH',__DIR__.'/');
function wp_doing_ajax(){return false;}
function get_current_user_id(){return 1;}
class GE_Organization {const PRIMARY='graph-express';static function get($id){return array('members'=>array('1'=>'owner'));}}
require __DIR__.'/../wp-content/mu-plugins/ge-organization/class-ge-organization-runtime.php';
foreach(array('ge_organization_reconciliation_save'=>true,'ge_organization_reconciliation_download'=>false) as $action=>$write)if(GE_Organization_Runtime::action_policy($action)!==array('company',$write))throw new RuntimeException('Native action policy mismatch');
echo "PASS native organization policy: historical declaration company/write, backup company/read.\n";
