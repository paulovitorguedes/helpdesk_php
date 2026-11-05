<?php
if(PHP_SAPI!=='cli-server'||getenv('HELPDESK_MATERIAL_MYSQL_TEST_PORT')!=='13308'||!getenv('HELPDESK_FINANCIAL_SESSIONS')){http_response_code(404);exit;}
ini_set('session.save_path',getenv('HELPDESK_FINANCIAL_SESSIONS'));
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/__test_login'){
    session_start();$_SESSION=['user'=>['id'=>1,'name'=>'Administrador fictício','role'=>'admin'],'csrf'=>'financial-test-csrf'];
    header('Location: /payables.php?month=11&year=2026');exit;
}
return false;
