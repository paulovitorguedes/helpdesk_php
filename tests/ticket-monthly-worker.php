<?php
if(PHP_SAPI!=='cli'||getenv('HELPDESK_MATERIAL_MYSQL_TEST_PORT')!=='13308')exit(1);
$folder=getenv('HELPDESK_MATERIAL_MYSQL_TEST_FOLDER');if(!$folder)exit(1);
$input=json_decode(file_get_contents($folder.'/monthly-worker-input.json'),true,512,JSON_THROW_ON_ERROR);
file_put_contents($folder.'/monthly-worker-ready','ready');
$context=stream_context_create(['http'=>['method'=>'POST','header'=>'Cookie: '.$input['cookie']."\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>http_build_query($input['post']),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]);
$body=file_get_contents($input['url'],false,$context);preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$match);
file_put_contents($folder.'/monthly-worker-result.json',json_encode(['status'=>(int)($match[1]??0),'body'=>$body]));
