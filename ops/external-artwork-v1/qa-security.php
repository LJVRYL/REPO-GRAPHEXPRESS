<?php
require __DIR__.'/qa/site/wp-load.php';if(DB_NAME!=='graph_external_artwork_20261002'){exit(2);}wp_set_current_user(1);add_filter('pre_wp_mail','__return_true',100);
function check($ok,$name){echo($ok?'PASS ':'FAIL ').$name."\n";if(!$ok){exit(1);}}
foreach(array('javascript:alert(1)','file:///etc/passwd','data:text/plain,secret','http://127.0.0.1/test','http://10.1.2.3/x','http://169.254.169.254/','http://2130706433/x','http://0x7f.0.0.1','https://user:pass@example.com/x','http://localhost/x','http://foo.internal/x','http://[::1]/','https://example.com:22/x')as$u){check(is_wp_error(GE_WTP_External_Artwork::validate_url($u)),'reject unsafe URL '.parse_url($u,PHP_URL_SCHEME));}
foreach(array('127.0.0.1','10.0.0.1','172.16.0.1','192.168.0.1','169.254.169.254','100.64.0.1','192.0.0.8','198.18.0.1','224.0.0.1','255.255.255.255')as$ip){check(!GE_WTP_External_Artwork::public_ip($ip),'reject private/reserved IP '.$ip);}
check(GE_WTP_External_Artwork::public_ip('8.8.8.8'),'public IPv4 recognized');
foreach(array('https://drive.google.com/file/d/abc/view'=>'google_drive','https://dropbox.com/x'=>'dropbox','https://we.tl/x'=>'wetransfer','https://1drv.ms/x'=>'onedrive','https://example.org'=>'generic','https://drive.google.com.attacker.org'=>'generic')as$u=>$p){check(GE_WTP_External_Artwork::provider($u)===$p,'provider detection '.$p);}
check(GE_WTP_External_Artwork::fetch_url('https://drive.google.com/file/d/abc/view?resourcekey=xyz')==='https://drive.google.com/uc?export=download&id=abc&resourcekey=xyz','Drive download preserves resource key');
echo "SECURITY_QA_COMPLETE\n";
