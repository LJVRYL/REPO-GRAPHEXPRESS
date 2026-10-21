<?php
define('GE_EXPORT_HTTP_FIXTURE',true);
require __DIR__.'/business-export-fixture.php';
$GLOBALS['actor']=(int)($_GET['actor']??1);
$GLOBALS['binding_ok']=empty($_GET['foreign_binding']);
$GLOBALS['nonce']=($_POST['_wpnonce']??'')==='synthetic-nonce';
$GLOBALS['fail_source']=(string)($_GET['fail_source']??'');$GLOBALS['fail_page']=(int)($_GET['fail_page']??1);
GE_WTP_Business_Export::download();
