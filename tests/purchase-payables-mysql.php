<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('HELPDESK_MATERIAL_MYSQL_TEST_PORT')!=='13308')exit(1);
require_once dirname(__DIR__).'/src/material_rules.php';
require_once dirname(__DIR__).'/src/material_purchase_edit.php';
require_once dirname(__DIR__).'/src/material_invoices.php';
date_default_timezone_set('America/Sao_Paulo');
$folder=getenv('HELPDESK_MATERIAL_MYSQL_TEST_FOLDER');if(!$folder||!is_dir($folder))exit(1);
$database='helpdesk_purchase_integration_test';
$pdo=new PDO('mysql:host=127.0.0.1;port=13308;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(($argv[1]??'')==='--worker'){
    $pdo->exec('USE '.$database);$label=$argv[2]??'';
    if(!in_array($label,['configuration','delete-paid','pay-a','pay-b','repeat-a','repeat-b','reverse-a','reverse-b','payment-delete-a','payment-delete-b','cross-account-a','cross-account-b'],true))exit(1);
    $input=json_decode(file_get_contents($folder.'/'.$label.'-input.json'),true,512,JSON_THROW_ON_ERROR);
    file_put_contents($folder.'/'.$label.'-ready','ready');$error=null;$workerResult=null;
    try{
        if($label==='configuration')payable_update_configuration($pdo,$input['id'],$input['values'],$input['revision']);
        elseif(in_array($label,['delete-paid','payment-delete-b'],true))material_delete_unused($pdo,$input['material']);
        elseif(str_starts_with($label,'reverse-'))$workerResult=payable_reverse_payment($pdo,$input['account'],$input['payment'],$input['reason'],1);
        else $workerResult=payable_record_payment($pdo,$input['account'],$input['values'],1);
    }catch(Throwable $e){$error=$e->getMessage();}
    file_put_contents($folder.'/'.$label.'-result.json',json_encode(['error'=>$error,'result'=>$workerResult],JSON_UNESCAPED_UNICODE));exit;
}
$checks=0;$failures=[];$http=null;$child=null;
$check=static function(bool $value,string $label)use(&$checks,&$failures){$checks++;if(!$value)$failures[]=$label;};
$fingerprint=static function(PDO $connection):array{
    $result=[];foreach($connection->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $table){if($table[1]!=='BASE TABLE')continue;
        $rows=$connection->query('SELECT * FROM `'.$table[0].'` ORDER BY 1')->fetchAll();$result[$table[0]]=hash('sha256',serialize($rows));}
    return $result;
};
try{
    $pdo->exec('CREATE DATABASE '.$database);$pdo->exec('USE '.$database);
    $pdo->exec(file_get_contents(__DIR__.'/fixtures/purchase-integration-base.sql'));
    $statement=$pdo->prepare("INSERT INTO users(id,name,email,password_hash,role) VALUES(1,'Administrador fictício','admin@example.invalid',?,'admin')");$statement->execute([password_hash('Teste exclusivamente local',PASSWORD_DEFAULT)]);
    $pdo->exec("INSERT INTO materials(id,description) VALUES(1,'Material legado fictício');
    INSERT INTO material_purchases(id,material_id,purchase_date,quantity,total_cost,unit_cost) VALUES(1,1,'2026-10-01',2,50,25);
    INSERT INTO stock_movements(material_id,movement_type,quantity,purchase_id,created_by) VALUES(1,'entrada',2,1,1);
    INSERT INTO payables(id,due_date,description,total_amount,payment_method,installments,current_installment,installment_value) VALUES(1,'2026-11-10','Conta manual legada',200,'pix',1,1,200)");
    $original=$pdo->query('SELECT * FROM payables WHERE id=1')->fetch();$originalColumns=array_keys($original);$before=$fingerprint($pdo);
    $pdo->exec(file_get_contents(dirname(__DIR__).'/sql/migrations/20261105_purchase_payables.sql'));
    $after=$pdo->query('SELECT * FROM payables WHERE id=1')->fetch();$legacy=array_intersect_key($after,array_flip($originalColumns));
    $check($original===$legacy&&$after['material_purchase_id']===null&&(int)$after['configuration_pending']===0,'Migracao preserva integralmente conta manual anterior');
    $afterHashes=$fingerprint($pdo);unset($before['payables'],$afterHashes['payables']);$check($before===$afterHashes,'Migracao preserva todos registros das outras quinze tabelas');
    $check((int)$pdo->query('SELECT COUNT(*) FROM payables WHERE material_purchase_id IS NOT NULL')->fetchColumn()===0,'Migracao nao cria contas retroativas');
    $pdo->exec(file_get_contents(dirname(__DIR__).'/sql/migrations/20261105_payable_payments.sql'));
    $nfBefore=$fingerprint($pdo);
    $pdo->exec(file_get_contents(dirname(__DIR__).'/sql/migrations/20261109_material_invoices.sql'));
    $nfAfter=$fingerprint($pdo);unset($nfAfter['material_purchase_invoices'],$nfAfter['material_purchase_invoice_parts']);
    $check($nfBefore===$nfAfter,'Migracao NF preserva todas as tabelas existentes');
    $check((int)$pdo->query('SELECT @@max_allowed_packet')->fetchColumn()===1048576,'Teste usa limite de pacote igual a producao: 1 MB');
    $pdo->beginTransaction();material_lock($pdo,1);
    $pdo->exec("INSERT INTO material_purchases(id,material_id,purchase_date,quantity,total_cost,unit_cost) VALUES(2,1,'2026-11-05',2,100,50);
    INSERT INTO stock_movements(material_id,movement_type,quantity,purchase_id,created_by) VALUES(1,'entrada',2,2,1)");
    purchase_payable_create($pdo,1,2,'Fornecedor fictício',100);$pdo->commit();
    $account=$pdo->query('SELECT * FROM payables WHERE material_purchase_id=2')->fetch();
    $check($account['due_date']===null&&$account['payment_method']===null&&$account['installments']===null&&(int)$account['configuration_pending']===1,'MariaDB aceita conta vinculada sem data forma ou parcelas');
    $check((float)$pdo->query('SELECT SUM(total_amount) FROM payables WHERE configuration_pending=0 AND due_date IS NOT NULL')->fetchColumn()===200.0,'Conta A configurar fica fora das despesas mensais');
    $config=['due_date'=>'2026-11-15','description'=>'Compra fictícia','category'=>'Materiais','supplier'=>'Fornecedor fictício','total_amount'=>'100.00','payment_method'=>'boleto','installments'=>'2','current_installment'=>'1','installment_value'=>50,'notes'=>'Teste'];
    payable_update_configuration($pdo,(int)$account['id'],$config,purchase_payable_revision($account));
    $check((float)$pdo->query('SELECT SUM(total_amount) FROM payables WHERE configuration_pending=0 AND due_date IS NOT NULL')->fetchColumn()===300.0,'Conta configurada passa a compor despesas');
    foreach(['configuration','delete-paid'] as $label){
        $account=$pdo->query('SELECT * FROM payables WHERE material_purchase_id=2')->fetch();
        $input=['id'=>(int)$account['id'],'values'=>$config,'revision'=>purchase_payable_revision($account),'material'=>1];
        file_put_contents($folder.'/'.$label.'-input.json',json_encode($input,JSON_THROW_ON_ERROR));
        $pdo->beginTransaction();material_lock($pdo,1);
        $child=proc_open([PHP_BINARY,__FILE__,'--worker',$label],[0=>['pipe','r'],1=>['file',$folder.'/'.$label.'-out.log','a'],2=>['file',$folder.'/'.$label.'-err.log','a']],$pipes,null,getenv(),['bypass_shell'=>true,'create_new_console'=>false]);
        if(!is_resource($child))throw new RuntimeException('Worker nao iniciou.');fclose($pipes[0]);
        for($i=0;$i<50&&!file_exists($folder.'/'.$label.'-ready');$i++)usleep(100000);
        if(!file_exists($folder.'/'.$label.'-ready'))throw new RuntimeException('Worker nao ficou pronto.');
        usleep(200000);$check(!file_exists($folder.'/'.$label.'-result.json'),'Financeiro aguarda lock do material: '.$label);
        if($label==='configuration'){$pdo->exec('UPDATE material_purchases SET total_cost=110,unit_cost=55 WHERE id=2');purchase_payable_sync($pdo,2,110);}
        else $pdo->exec("UPDATE payables SET paid_at='2026-11-05' WHERE material_purchase_id=2");
        $pdo->commit();$snapshot=$fingerprint($pdo);$exit=proc_close($child);$child=null;
        $result=json_decode(file_get_contents($folder.'/'.$label.'-result.json'),true,512,JSON_THROW_ON_ERROR);
        $check($exit===0&&is_string($result['error'])&&str_contains($result['error'],$label==='configuration'?'mudou':'pagamento'),'Operacao revalida apos concorrencia: '.$label);
        $check($snapshot===$fingerprint($pdo),'Operacao concorrente recusada nao deixa alteracao parcial: '.$label);
    }
    $pdo->exec("UPDATE payables SET paid_at=NULL WHERE material_purchase_id=2");
    $pdo->exec("CREATE TRIGGER fail_sync BEFORE UPDATE ON payables FOR EACH ROW BEGIN IF NEW.total_amount=999 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Falha simulada'; END IF; END");
    $p=material_latest_purchase($pdo,1);$snapshot=$fingerprint($pdo);$failed=false;
    try{material_update_latest_purchase($pdo,1,['last_purchase_id'=>'2','last_purchase_revision'=>material_purchase_revision($p),'last_purchase_date'=>$p['purchase_date'],'last_purchase_quantity'=>'3','last_purchase_total_cost'=>'999','last_purchase_supplier'=>'Teste','last_purchase_notes'=>'','description'=>'Nao salvar','minimum_stock'=>'0','notes'=>'']);}catch(Throwable){$failed=true;}
    $check($failed&&$snapshot===$fingerprint($pdo),'MariaDB rollback atomico entre cadastro compra estoque e conta');$pdo->exec('DROP TRIGGER fail_sync');
    // Real pages run only with a newly written test configuration and synthetic sessions.
    foreach(['source/src','source/public/assets/js','source/public/assets/css','source/config','sessions'] as $dir)mkdir($folder.'/'.$dir,0700,true);
    foreach(['src','public'] as $dir)foreach(glob(dirname(__DIR__).'/'.$dir.'/*.php') as $file)copy($file,$folder.'/source/'.$dir.'/'.basename($file));
    foreach(['js','css'] as $kind)foreach(glob(dirname(__DIR__).'/public/assets/'.$kind.'/*') as $file)if(is_file($file))copy($file,$folder.'/source/public/assets/'.$kind.'/'.basename($file));
    file_put_contents($folder.'/source/config/config.php',"<?php return ".var_export(['app_name'=>'Integração fictícia','base_url'=>'','db'=>['host'=>'127.0.0.1','port'=>13308,'name'=>$database,'charset'=>'utf8mb4','user'=>'root','pass'=>'']],true).';');
    session_save_path($folder.'/sessions');$cookies=[];
    foreach(['admin','financeiro','tecnico'] as $role){$id='financialtest'.bin2hex(random_bytes(10));session_id($id);session_start();$_SESSION=['user'=>['id'=>1,'name'=>'Pessoa fictícia','role'=>$role],'csrf'=>'financial-test-csrf'];session_write_close();$cookies[$role]='PHPSESSID='.$id;}
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);
    $env=getenv();$env['HELPDESK_FINANCIAL_SESSIONS']=$folder.'/sessions';
    $http=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$folder.'/source/public',__DIR__.'/purchase-payables-mysql-router.php'],[0=>['pipe','r'],1=>['file',$folder.'/http.log','a'],2=>['file',$folder.'/http.log','a']],$pipes,$folder,$env,['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($http))throw new RuntimeException('HTTP nao iniciou.');fclose($pipes[0]);
    for($i=0;$i<50;$i++){if($socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,0.1)){fclose($socket);break;}usleep(100000);}
    $request=static function(string $path,?array $post=null,string $role='admin')use($port,$cookies):array{
        $context=stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>'Cookie: '.$cookies[$role]."\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>8]]);
        $body=file_get_contents('http://127.0.0.1:'.$port.'/'.$path,false,$context);preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$match);return ['status'=>(int)($match[1]??0),'body'=>$body];
    };
    foreach(['payables.php?month=11&year=2026','dashboard.php?year=2026','materials.php'] as $path){$page=$request($path);$check($page['status']===200&&!str_contains($page['body'],'Fatal error'),'Pagina real com esquema migrado e dados ficticios: '.$path);}
    $page=$request('payables.php?month=11&year=2026');$check(str_contains($page['body'],'A configurar')&&str_contains($page['body'],'Conta manual legada'),'Lista separada preserva conta manual no mesmo ambiente');
    $account=$pdo->query('SELECT * FROM payables WHERE material_purchase_id=2')->fetch();$payload=['csrf'=>'financial-test-csrf','action'=>'update','payable_id'=>(string)$account['id'],'return_month'=>'11','return_year'=>'2026','linked_revision'=>purchase_payable_revision($account)]+$config;
    $payload['total_amount']='110.00';$payload['installment_value']='55';$page=$request('payables.php',$payload,'financeiro');
    $check($page['status']===302&&(int)$pdo->query('SELECT configuration_pending FROM payables WHERE material_purchase_id=2')->fetchColumn()===0,'Formulario real Financeiro configura registro MariaDB');
    $snapshot=$fingerprint($pdo);$request('payables.php',$payload,'tecnico');$check($snapshot===$fingerprint($pdo),'Tecnico nao grava pelo formulario financeiro real');
    $count=(int)$pdo->query('SELECT COUNT(*) FROM payables')->fetchColumn();
    $page=$request('materials.php',['csrf'=>'financial-test-csrf','action'=>'purchase','material_id'=>'1','purchase_date'=>'2026-11-06','supplier'=>'NF fictícia','quantity'=>'1','total_cost'=>'25','purchase_notes'=>'Teste HTTP']);
    $check($page['status']===302&&(int)$pdo->query('SELECT COUNT(*) FROM payables')->fetchColumn()===$count+1,'Pagina real cria compra estoque e obrigacao juntos no MariaDB');
    require __DIR__.'/payable-payments-mysql-cases.php';
    require __DIR__.'/material-invoices-mysql-cases.php';
    foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)foreach($pdo->query('CHECK TABLE `'.$table.'`') as $result)$check($result['Msg_type']!=='error','CHECK TABLE '.$table);
    // Rehearse a complete logical restore of the migrated synthetic database.
    $dump=$folder.'/synthetic-restoration.sql';
    $process=proc_open(['C:/xampp/mysql/bin/mysqldump.exe','--no-defaults','--host=127.0.0.1','--port=13308','--user=root','--single-transaction','--hex-blob','--skip-extended-insert','--result-file='.$dump,$database],[0=>['pipe','r'],1=>['file',$folder.'/dump-out.log','a'],2=>['file',$folder.'/dump-err.log','a']],$pipes);fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Dump sintetico falhou.');
    $restore=$database.'_restored';$pdo->exec('CREATE DATABASE '.$restore);
    $process=proc_open(['C:/xampp/mysql/bin/mysql.exe','--no-defaults','--host=127.0.0.1','--port=13308','--user=root','--database='.$restore],[0=>['file',$dump,'r'],1=>['file',$folder.'/restore-out.log','a'],2=>['file',$folder.'/restore-err.log','a']],$pipes);if(proc_close($process)!==0)throw new RuntimeException('Restauracao sintetica falhou.');
    $restored=new PDO('mysql:host=127.0.0.1;port=13308;dbname='.$restore.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $check($fingerprint($pdo)===$fingerprint($restored),'Backup logico migrado restaura com hashes iguais em todas as tabelas');
    $report=['checks'=>$checks,'failures'=>$failures,'scope'=>'Esquema real sem registros reais; migracao, legado sintetico, HTTP, concorrencia de dois processos, rollback e restauracao logica. MariaDB novo na porta 13308.'];
    file_put_contents($folder.'/resultado-integracao.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
    if(($argv[1]??'')==='--serve'){
        echo json_encode(['preview_url'=>'http://127.0.0.1:'.$port.'/__test_login','stop_file'=>$folder.'/stop','scope'=>'Dados exclusivamente ficticios'])."\n";flush();
        $deadline=time()+1800;while(!file_exists($folder.'/stop')&&time()<$deadline)usleep(200000);
    }
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    if(is_resource($child)){proc_terminate($child);proc_close($child);}
    if(is_resource($http)){proc_terminate($http);proc_close($http);}
    $pdo->exec('SHUTDOWN');
}
exit($failures?1:0);
