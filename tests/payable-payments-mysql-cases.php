<?php
if(PHP_SAPI!=='cli'||!isset($pdo,$check,$folder,$request))exit(1);
$makeAccount=static function(int $material,int $count=1)use($pdo):array{
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO materials(id,description) VALUES(?,?)')->execute([$material,'Conta de pagamentos fictícia '.$material]);
    $pdo->prepare('INSERT INTO material_purchases(id,material_id,purchase_date,quantity,total_cost,unit_cost) VALUES(?,?,?,1,100,100)')->execute([$material,$material,'2026-11-05']);
    $pdo->prepare("INSERT INTO stock_movements(material_id,movement_type,quantity,purchase_id,created_by) VALUES(?,'entrada',1,?,1)")->execute([$material,$material]);
    purchase_payable_create($pdo,$material,$material,'Fornecedor fictício',100);$pdo->commit();
    $s=$pdo->prepare('SELECT * FROM payables WHERE material_purchase_id=?');$s->execute([$material]);$a=$s->fetch();$s->closeCursor();
    payable_update_configuration($pdo,(int)$a['id'],['due_date'=>'2026-11-15','description'=>$a['description'],'category'=>'Materiais','supplier'=>'Fornecedor fictício','total_amount'=>'100.00','payment_method'=>'pix','installments'=>(string)$count,'current_installment'=>'1','installment_value'=>$count===1?100:33.33,'notes'=>'Teste'],purchase_payable_revision($a));
    $s->execute([$material]);return $s->fetch();
};
$load=static function(int $id)use($pdo):array{$s=$pdo->prepare('SELECT * FROM payables WHERE id=?');$s->execute([$id]);return $s->fetch();};
$makePayment=static fn(array $a,string $amount='10.00'):array=>['installment_number'=>'1','amount'=>$amount,'payment_date'=>'2026-11-05','payment_method'=>'pix','notes'=>'Somente teste','request_token'=>bin2hex(random_bytes(32)),'account_revision'=>purchase_payable_revision($a)];
$account=$makeAccount(20);$accountId=(int)$account['id'];$seed=payable_record_payment($pdo,$accountId,$makePayment($account),1);
$runConcurrent=static function(string $prefix,array $inputs,int|array $material=20)use($pdo,$folder,$check):array{
    $children=[];$results=[];
    $pdo->beginTransaction();foreach((array)$material as $materialId)material_lock($pdo,$materialId);
    try{
        foreach(['a','b'] as $suffix){
            $label=$prefix.'-'.$suffix;file_put_contents($folder.'/'.$label.'-input.json',json_encode($inputs[$suffix],JSON_THROW_ON_ERROR));
            $process=proc_open([PHP_BINARY,__DIR__.'/purchase-payables-mysql.php','--worker',$label],[0=>['pipe','r'],1=>['file',$folder.'/'.$label.'-out.log','a'],2=>['file',$folder.'/'.$label.'-err.log','a']],$pipes,null,getenv(),['bypass_shell'=>true,'create_new_console'=>false]);
            if(!is_resource($process))throw new RuntimeException('Worker financeiro nao iniciou.');fclose($pipes[0]);$children[$label]=$process;
        }
        foreach(array_keys($children) as $label){for($i=0;$i<50&&!file_exists($folder.'/'.$label.'-ready');$i++)usleep(100000);if(!file_exists($folder.'/'.$label.'-ready'))throw new RuntimeException('Worker financeiro nao ficou pronto.');}
        usleep(200000);
        foreach(array_keys($children) as $label)$check(!file_exists($folder.'/'.$label.'-result.json'),'Operacao concorrente aguarda material/conta: '.$label);
        $pdo->commit();
        foreach($children as $label=>$process){$code=proc_close($process);unset($children[$label]);$check($code===0,'Worker financeiro conclui: '.$label);$results[]=json_decode(file_get_contents($folder.'/'.$label.'-result.json'),true,512,JSON_THROW_ON_ERROR);}
    }finally{if($pdo->inTransaction())$pdo->rollBack();foreach($children as $process){proc_terminate($process);proc_close($process);}}
    return $results;
};
$a=$load($accountId);$results=$runConcurrent('pay',['a'=>['account'=>$accountId,'values'=>$makePayment($a,'60.00')],'b'=>['account'=>$accountId,'values'=>$makePayment($a,'60.00')]]);
$success=count(array_filter($results,static fn($r)=>$r['error']===null));$paid=array_sum(payable_payment_totals(payable_payment_rows($pdo,$accountId)));
$check($success===1&&$paid===7000,'Dois pagamentos concorrentes nao ultrapassam saldo apesar de snapshots antigos');
$failed=array_values(array_filter($results,static fn($r)=>$r['error']!==null));$check(count($failed)===1&&str_contains($failed[0]['error'],'saldo atual'),'Segundo pagamento revalida saldo atual apos aguardar lock');
$a=$load($accountId);$same=$makePayment($a,'5.00');$count=count(payable_payment_rows($pdo,$accountId));
$results=$runConcurrent('repeat',['a'=>['account'=>$accountId,'values'=>$same],'b'=>['account'=>$accountId,'values'=>$same]]);
$check($results[0]['error']===null&&$results[1]['error']===null&&count(payable_payment_rows($pdo,$accountId))===$count+1,'Token igual em dois processos cria somente uma baixa');
$check(count(array_filter($results,static fn($r)=>$r['result']['duplicate']===true))===1,'Repeticao concorrente retorna o pagamento original');
$results=$runConcurrent('reverse',['a'=>['account'=>$accountId,'payment'=>$seed['id'],'reason'=>'Conferencia A'],'b'=>['account'=>$accountId,'payment'=>$seed['id'],'reason'=>'Conferencia B']]);
$check($results[0]['error']===null&&$results[1]['error']===null&&count(array_filter($results,static fn($r)=>$r['result']===true))===1,'Estorno concorrente modifica historico uma unica vez');
$check(array_sum(payable_payment_totals(payable_payment_rows($pdo,$accountId)))===6500&&(int)$load($accountId)['payment_history_locked']===1,'Estorno concorrente reabre valor correto e conserva bloqueio');
$a=$load($accountId);$snapshot=$fingerprint($pdo);$blocked=false;
try{payable_update_configuration($pdo,$accountId,['due_date'=>'2026-11-15','description'=>$a['description'],'category'=>'Materiais','supplier'=>'Fornecedor fictício','total_amount'=>'101.00','payment_method'=>'pix','installments'=>'1','current_installment'=>'1','installment_value'=>101,'notes'=>'Nao alterar'],purchase_payable_revision($a));}catch(Throwable){$blocked=true;}
$check($blocked&&$snapshot===$fingerprint($pdo),'MariaDB impede alteracao financeira apos parcial');
$a=$makeAccount(21,3);$aid=(int)$a['id'];$payload=$makePayment($a)+['csrf'=>'financial-test-csrf','action'=>'pay','payable_id'=>(string)$aid];
$page=$request('payable-payments.php?id='.$aid,$payload,'financeiro');
$check($page['status']===302&&array_sum(payable_payment_totals(payable_payment_rows($pdo,$aid)))===1000,'Endpoint real Financeiro registra parcial no MariaDB');
$first=payable_payment_rows($pdo,$aid)[0];
$page=$request('payable-payments.php?id='.$aid,['csrf'=>'financial-test-csrf','action'=>'reverse','payable_id'=>(string)$aid,'payment_id'=>(string)$first['id'],'reason'=>'Valor digitado incorretamente (teste)']);
$check($page['status']===302&&array_sum(payable_payment_totals(payable_payment_rows($pdo,$aid)))===0&&(int)$load($aid)['payment_history_locked']===1,'Endpoint real estorna sem apagar a protecao');
$page=$request('payable-payments.php?id='.$aid,$makePayment($load($aid),'20.00')+['csrf'=>'financial-test-csrf','action'=>'pay','payable_id'=>(string)$aid]);
$view=$request('payable-payments.php?id='.$aid);
$check($page['status']===302&&$view['status']===200&&str_contains($view['body'],'13,33')&&str_contains($view['body'],'33,34')&&str_contains($view['body'],'Valor digitado incorretamente (teste)'),'Pagina real apresenta saldos centavos e motivo do estorno');
$before=$fingerprint($pdo);$request('payable-payments.php?id='.$aid,$makePayment($load($aid))+['csrf'=>'financial-test-csrf','action'=>'pay','payable_id'=>(string)$aid],'tecnico');$check($before===$fingerprint($pdo),'Tecnico nao registra pagamentos no MariaDB via HTTP');
$page=$request('payables.php?month=11&year=2026');$check($page['status']===200&&str_contains($page['body'],'Total previsto em')&&str_contains($page['body'],'Pagamentos'),'Listagem mensal diferencia previsao de saldo registrado');
$a=$makeAccount(22);$failId=(int)$a['id'];
$pdo->exec("CREATE TRIGGER fail_payment_marker BEFORE UPDATE ON payables FOR EACH ROW BEGIN IF NEW.id=$failId AND NEW.payment_history_locked=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Falha de marcador simulada'; END IF; END");
$snapshot=$fingerprint($pdo);$failed=false;try{payable_record_payment($pdo,$failId,$makePayment($a),1);}catch(Throwable){$failed=true;}
$check($failed&&$snapshot===$fingerprint($pdo),'MariaDB rollback desfaz pagamento e marcadores em falha intermediaria');$pdo->exec('DROP TRIGGER fail_payment_marker');
$payment=payable_record_payment($pdo,$failId,$makePayment($load($failId)),1);
$pdo->exec("CREATE TRIGGER fail_reversal_marker BEFORE UPDATE ON payables FOR EACH ROW BEGIN IF NEW.id=$failId AND NEW.paid_at IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Falha de estorno simulada'; END IF; END");
$snapshot=$fingerprint($pdo);$failed=false;try{payable_reverse_payment($pdo,$failId,$payment['id'],'Teste de falha',1);}catch(Throwable){$failed=true;}
$check($failed&&$snapshot===$fingerprint($pdo),'MariaDB rollback do estorno preserva saldo pagamento e auditoria');$pdo->exec('DROP TRIGGER fail_reversal_marker');
$race=$makeAccount(23);$raceId=(int)$race['id'];
$results=$runConcurrent('payment-delete',['a'=>['account'=>$raceId,'values'=>$makePayment($race)],'b'=>['material'=>23]],23);
$success=count(array_filter($results,static fn($r)=>$r['error']===null));
$exists=(int)$pdo->query('SELECT COUNT(*) FROM materials WHERE id=23')->fetchColumn();$entries=payable_payment_rows($pdo,$raceId);
$check($success===1&&(($exists===1&&count($entries)===1)||($exists===0&&count($entries)===0)),'Pagamento versus exclusao deixa somente um resultado coerente sem orfaos');
$check((int)$pdo->query('SELECT COUNT(*) FROM payable_payments pp LEFT JOIN payables p ON p.id=pp.payable_id WHERE p.id IS NULL')->fetchColumn()===0,'Concorrencia nao produz pagamento sem conta');
$crossA=$makeAccount(24);$crossB=$makeAccount(25);
$results=$runConcurrent('cross-account',['a'=>['account'=>(int)$crossA['id'],'values'=>$makePayment($crossA)],'b'=>['account'=>(int)$crossB['id'],'values'=>$makePayment($crossB)]],[24,25]);
$check($results[0]['error']===null&&$results[1]['error']===null&&count(payable_payment_rows($pdo,(int)$crossA['id']))===1&&count(payable_payment_rows($pdo,(int)$crossB['id']))===1,'Primeiros pagamentos em contas distintas concluem sem conflito de intervalos vazios');
file_put_contents($folder.'/preview-payment-account.txt',(string)$aid);
