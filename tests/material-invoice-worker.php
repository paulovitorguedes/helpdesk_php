<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('HELPDESK_MATERIAL_MYSQL_TEST_PORT')!=='13308')exit(1);
$folder=getenv('HELPDESK_MATERIAL_MYSQL_TEST_FOLDER');$label=$argv[1]??'';
if(!$folder||!in_array($label,['invoice-lock','invoice-delete'],true))exit(1);
$input=json_decode(file_get_contents($folder.'/'.$label.'-input.json'),true,512,JSON_THROW_ON_ERROR);
file_put_contents($folder.'/'.$label.'-ready','ready');
$context=stream_context_create(['http'=>['method'=>'POST','header'=>$input['headers'],'content'=>base64_decode($input['body'],true),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]);
$body=file_get_contents($input['url'],false,$context);preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$match);
file_put_contents($folder.'/'.$label.'-result.json',json_encode(['status'=>(int)($match[1]??0),'body'=>$body]));
