<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$site = getenv( 'GE_ANALYZER_SITE_ROOT' ) ?: '/home/graphexpress/public_html';
$lock_dir = getenv( 'GE_ANALYZER_LOCK_DIR' ) ?: '/var/lib/graphexpress/file-analyzer';
$lock = fopen( $lock_dir . '/worker.lock', 'c' );
if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) { exit( 0 ); }
define( 'WP_USE_THEMES', false );
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }
require $site . '/wp-load.php';
if ( ! class_exists( 'GE_WTP_File_Analysis' ) ) { exit( 2 ); }
$started = microtime( true ); $count = 0;
while ( $count < 8 && microtime( true ) - $started < 90 && GE_WTP_File_Analysis::run_next() ) { $count++; }
echo 'jobs_completed=' . $count . PHP_EOL;
