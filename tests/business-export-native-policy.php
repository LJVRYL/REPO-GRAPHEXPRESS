<?php
define('ABSPATH',__DIR__.'/');
function wp_doing_ajax(){return false;}
function get_current_user_id(){return 1;}
class GE_Organization {const PRIMARY='graph-express';static function get($id){return array('members'=>array('1'=>'owner'));}}
require __DIR__.'/../wp-content/mu-plugins/ge-organization/class-ge-organization-runtime.php';
$policy=GE_Organization_Runtime::action_policy('ge_organization_business_download');
if($policy!==array('company',false))throw new RuntimeException('Native policy mismatch');
echo "Native organization action policy: company/read PASS (local runtime source; live behavior still to verify)\n";
