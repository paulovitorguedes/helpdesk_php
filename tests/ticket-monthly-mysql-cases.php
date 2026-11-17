<?php
if(PHP_SAPI!=='cli'||getenv('HELPDESK_MATERIAL_MYSQL_TEST_PORT')!=='13308')exit(1);
$monthlyCookies=$cookies;
session_save_path($folder.'/sessions');
foreach(['admin','financeiro','tecnico'] as $role){$id='monthlytest'.bin2hex(random_bytes(10));session_id($id);session_start();$_SESSION=['user'=>['id'=>1,'name'=>'Pessoa ficticia','role'=>$role],'csrf'=>'material-test-csrf'];session_write_close();$cookies[$role]='PHPSESSID='.$id;}
$pdo->exec("INSERT INTO clients(id,name,has_contract,due_day,status) VALUES(1,'Cliente mensal ficticio',0,20,'ativo')");
function postTicket(array $changes=[],string $role='admin'):array {
    return materialHttp('tickets.php',array_replace(['csrf'=>'material-test-csrf','id'=>'','client_id'=>'1','occurred_at'=>'2026-10-20','complainant'=>'Pessoa ficticia','request_text'=>'Solicitacao ficticia','service_type'=>'remoto','status'=>'aberto','service_amount'=>'0','materials'=>[['material_id'=>'1','quantity'=>'1','unit_price'=>'0']]],$changes),$role);
}
function materialXPath(string $html):DOMXPath {
    $previous=libxml_use_internal_errors(true);$document=new DOMDocument();$document->loadHTML('<?xml encoding="UTF-8">'.$html);libxml_clear_errors();libxml_use_internal_errors($previous);return new DOMXPath($document);
}
require __DIR__.'/ticket-monthly-lock-cases.php';
// The HTTP writer must wait, then re-evaluate the financial row after another transaction closes it.
$pdo->beginTransaction();
$statement=$pdo->prepare('SELECT id FROM tickets WHERE id=? FOR UPDATE');$statement->execute([$future['ticket']]);
$statement=$pdo->prepare('SELECT id FROM receivables WHERE id=? FOR UPDATE');$statement->execute([$future['account']]);
file_put_contents($folder.'/monthly-worker-input.json',json_encode(['url'=>'http://127.0.0.1:'.$port.'/tickets.php','cookie'=>$cookies['admin'],'post'=>['csrf'=>'material-test-csrf','id'=>(string)$future['ticket'],'request_text'=>'Texto depois do encerramento','status'=>'cancelado']],JSON_THROW_ON_ERROR));
$child=proc_open([PHP_BINARY,__DIR__.'/ticket-monthly-worker.php'],[0=>['pipe','r'],1=>['file',$folder.'/monthly-worker-out.log','a'],2=>['file',$folder.'/monthly-worker-err.log','a']],$pipes,null,getenv(),['bypass_shell'=>true,'create_new_console'=>false]);
if(!is_resource($child))throw new RuntimeException('Worker mensal nao iniciou.');fclose($pipes[0]);
for($i=0;$i<50&&!file_exists($folder.'/monthly-worker-ready');$i++)usleep(100000);
if(!file_exists($folder.'/monthly-worker-ready'))throw new RuntimeException('Worker mensal nao ficou pronto.');
usleep(300000);checkMaterial(!file_exists($folder.'/monthly-worker-result.json'),'Edicao HTTP aguarda transacao concorrente');
$pdo->exec("UPDATE receivables SET closed_at='2026-10-21 10:00:00' WHERE id=".$future['account']);
$pdo->commit();$snapshot=monthlyBusinessSnapshot($future['ticket']);$exit=proc_close($child);$child=null;
$result=json_decode(file_get_contents($folder.'/monthly-worker-result.json'),true,512,JSON_THROW_ON_ERROR);
checkMaterial($exit===0&&$result['status']===302&&monthlyBusinessSnapshot($future['ticket'])===$snapshot,'Encerramento concorrente impede cancelamento e movimentacao de estoque');
checkMaterial($pdo->query('SELECT request_text FROM tickets WHERE id='.$future['ticket'])->fetchColumn()==='Texto depois do encerramento','Concorrencia conserva a excecao textual permitida');
$cookies=$monthlyCookies;
