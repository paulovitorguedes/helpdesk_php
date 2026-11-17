<?php
if(PHP_SAPI!=='cli'||!isset($pdo,$port,$cookies,$folder))exit(1);
$root=$folder;
if(!is_dir($root.'/evidencias'))mkdir($root.'/evidencias');
$nfCookie=$cookies;
// Existing sessions use the financial suite CSRF token. Build additional isolated sessions for shared NF tests.
session_save_path($folder.'/sessions');
foreach(['admin','financeiro','tecnico'] as $role){$id='nftest'.bin2hex(random_bytes(10));session_id($id);session_start();$_SESSION=['user'=>['id'=>1,'name'=>'Pessoa ficticia','role'=>$role],'csrf'=>'material-test-csrf'];session_write_close();$cookies[$role]='PHPSESSID='.$id;}
function checkMaterial(bool $condition,string $label):void {global $checks,$failures;$checks++;if(!$condition)$failures[]=$label;}
function materialHttp(string $path,?array $post=null,string $role='admin',array $extraHeaders=[]):array {
    global $port,$cookies;
    $headers='Cookie: '.$cookies[$role]."\r\n";
    foreach($extraHeaders as $extraHeader)$headers.=$extraHeader."\r\n";
    if($post!==null)$headers.="Content-Type: application/x-www-form-urlencoded\r\n";
    $context=stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>$headers,'content'=>$post===null?null:http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>5]]);
    $body=file_get_contents('http://127.0.0.1:'.$port.'/'.$path,false,$context);
    preg_match('/^HTTP\/\S+\s+(\d+)/',$http_response_header[0]??'',$match);
    return ['status'=>(int)($match[1]??0),'body'=>(string)$body,'headers'=>$http_response_header];
}
function inventorySnapshot():string {global $pdo;$result=[];foreach(['materials','material_purchases','stock_movements','tickets','ticket_materials','ticket_billing_installments','ticket_daily_sequences','payables','payable_payments'] as $table)$result[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();return json_encode($result);}
function postMaterial(array $changes=[],string $role='admin'):array {
    return materialHttp('materials.php',array_replace(['csrf'=>'material-test-csrf','action'=>'save','id'=>'','description'=>'Material novo ficticio','minimum_stock'=>'0','purchase_date'=>'2026-10-20','supplier'=>'Fornecedor ficticio','quantity'=>'2','total_cost'=>'100','notes'=>''],$changes),$role);
}
function postPurchase(array $changes=[],string $role='admin'):array {
    return materialHttp('materials.php',array_replace(['csrf'=>'material-test-csrf','action'=>'purchase','material_id'=>'1','purchase_date'=>'2026-10-20','supplier'=>'Fornecedor ficticio','quantity'=>'2','total_cost'=>'100','purchase_notes'=>''],$changes),$role);
}

require __DIR__.'/material-invoice-cases.php';
foreach(['invoice-lock','invoice-delete'] as $label){
    postMaterial(['description'=>'Concorrencia NF '.$label]);
    $mid=(int)$pdo->query("SELECT MAX(id) FROM materials")->fetchColumn();
    $pid=(int)$pdo->query('SELECT id FROM material_purchases WHERE material_id='.$mid)->fetchColumn();
    $boundary='nf-worker-'.bin2hex(random_bytes(8));$body='';
    foreach(['csrf'=>'material-test-csrf','purchase_id'=>$pid,'request_token'=>bin2hex(random_bytes(32))] as $key=>$value)$body.="--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
    $body.="--$boundary\r\nContent-Disposition: form-data; name=\"invoice_pdf\"; filename=\"NF.pdf\"\r\nContent-Type: application/pdf\r\n\r\n".invoiceTestPdf()."\r\n--$boundary--\r\n";
    file_put_contents($folder.'/'.$label.'-input.json',json_encode(['url'=>'http://127.0.0.1:'.$port.'/material-invoice.php','headers'=>'Cookie: '.$cookies['admin']."\r\nAccept: application/json\r\nContent-Type: multipart/form-data; boundary=$boundary\r\n",'body'=>base64_encode($body)],JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();material_lock($pdo,$mid);
    $child=proc_open([PHP_BINARY,__DIR__.'/material-invoice-worker.php',$label],[0=>['pipe','r'],1=>['file',$folder.'/'.$label.'-out.log','a'],2=>['file',$folder.'/'.$label.'-err.log','a']],$pipes,null,getenv(),['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($child))throw new RuntimeException('Worker NF nao iniciou.');fclose($pipes[0]);
    for($i=0;$i<50&&!file_exists($folder.'/'.$label.'-ready');$i++)usleep(100000);
    if(!file_exists($folder.'/'.$label.'-ready'))throw new RuntimeException('Worker NF nao ficou pronto.');
    usleep(300000);checkMaterial(!file_exists($folder.'/'.$label.'-result.json'),'Envio aguarda transacao do material: '.$label);
    if($label==='invoice-delete'){
        // Same deletion order as the production transaction, on a newly seeded unsold/unpaid material.
        purchase_payables_delete_for_material($pdo,$mid);
        $pdo->exec('DELETE FROM stock_movements WHERE material_id='.$mid);
        $pdo->exec('DELETE FROM material_purchases WHERE material_id='.$mid);
        $pdo->exec('DELETE FROM materials WHERE id='.$mid);
    }
    $pdo->commit();$exit=proc_close($child);$child=null;
    $result=json_decode(file_get_contents($folder.'/'.$label.'-result.json'),true,512,JSON_THROW_ON_ERROR);
    checkMaterial($exit===0&&$result['status']===($label==='invoice-delete'?409:200),'Envio revalida material depois do lock: '.$label);
    $count=(int)$pdo->query('SELECT COUNT(*) FROM material_purchase_invoices WHERE material_purchase_id='.$pid)->fetchColumn();
    checkMaterial($count===($label==='invoice-delete'?0:1),'Concorrencia nao deixa anexo orfao ou duplicado: '.$label);
}
$cookies=$nfCookie;
