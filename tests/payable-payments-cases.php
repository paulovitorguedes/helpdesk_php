<?php
if(PHP_SAPI!=='cli'||!isset($pdo,$checks))exit(1);
foreach(['0'=>0,'0.01'=>1,'33,34'=>3334,'9999999999.99'=>999999999999] as $value=>$expected)checkMaterial(payable_cents((string)$value)===$expected,'Centavos exatos: '.$value);
foreach(['-1','1.001','1e2','NaN',[], '10000000000'] as $invalid){try{payable_cents($invalid);checkMaterial(false,'Valor monetario invalido');}catch(InvalidArgumentException){checkMaterial(true,'Valor monetario invalido recusado');}}
$plan=['configuration_pending'=>0,'due_date'=>'2026-01-31','total_amount'=>'100.00','installments'=>3,'installment_value'=>'33.33'];
checkMaterial([payable_installment_amount($plan,1),payable_installment_amount($plan,2),payable_installment_amount($plan,3)]===[3333,3333,3334],'Ultima parcela recebe centavos e soma exatamente o total');
checkMaterial(payable_schedule_date('2026-01-31',1)==='2026-02-28'&&payable_schedule_date('2028-01-31',1)==='2028-02-29','Vencimentos preservam ajuste para fim do mes e ano bissexto');
function createPaymentTestAccount(string $name='Conta de pagamentos ficticia',string $total='100'):array{
    global $pdo;postMaterial(['description'=>$name,'purchase_date'=>'2026-11-05','total_cost'=>$total]);
    $s=$pdo->prepare('SELECT id FROM materials WHERE description=? ORDER BY id DESC LIMIT 1');$s->execute([$name]);$mid=(int)$s->fetchColumn();$s->closeCursor();$p=material_latest_purchase($pdo,$mid);$a=testFinancialAccount((int)$p['id']);
    testConfigureAccount($a,['installments'=>'3','installment_value'=>'33.33','payment_method'=>'pix']);return ['mid'=>$mid,'purchase'=>(int)$p['id'],'account'=>testFinancialAccount((int)$p['id'])];
}
function paymentTestInput(array $account,array $changes=[]):array{
    return array_replace(['csrf'=>'material-test-csrf','action'=>'pay','payable_id'=>(string)$account['id'],'installment_number'=>'1','amount'=>'10.00','payment_date'=>'2026-11-05','payment_method'=>'pix','notes'=>'Pagamento ficticio','request_token'=>bin2hex(random_bytes(32)),'account_revision'=>purchase_payable_revision($account)],$changes);
}
function sendPaymentTest(array $input,string $role='admin'):array{return materialHttp('payable-payments.php?id='.(int)$input['payable_id'],$input,$role,['Accept: application/json']);}
function paymentAccountNow(int $id):array{global $pdo;$s=$pdo->prepare('SELECT * FROM payables WHERE id=?');$s->execute([$id]);return $s->fetch();}
$context=createPaymentTestAccount();$account=$context['account'];$aid=(int)$account['id'];
$page=materialHttp('payable-payments.php?id='.$aid);$x=materialXPath($page['body']);
checkMaterial($page['status']===200&&$x->query('//table[@id="paymentInstallments"]/tbody/tr')->length===3,'Pagina de pagamentos mostra saldo por parcela');
checkMaterial(str_contains($page['body'],'33,34'),'Pagina mostra ajuste dos centavos na ultima parcela');
$input=paymentTestInput($account);$response=sendPaymentTest($input,'financeiro');$result=json_decode($response['body'],true);$rows=payable_payment_rows($pdo,$aid);$paymentId=(int)$rows[0]['id'];
checkMaterial($response['status']===200&&($result['ok']??false)&&count($rows)===1,'Financeiro registra primeiro pagamento parcial');
checkMaterial((int)$rows[0]['created_by']===999999&&(float)$rows[0]['amount']===10.0,'Pagamento conserva valor original e autor');
$account=paymentAccountNow($aid);checkMaterial((int)$account['payment_history_locked']===1&&$account['status']==='pendente'&&$account['paid_at']==='2026-11-05','Primeiro parcial ativa protecao permanente sem quitar a conta');
$page=materialHttp('payable-payments.php?id='.$aid);checkMaterial(str_contains($page['body'],'23,33')&&str_contains($page['body'],'Parcial'),'Saldo parcial aparece corretamente na pagina');
$before=inventorySnapshot();$response=sendPaymentTest($input);checkMaterial($response['status']===200&&inventorySnapshot()===$before,'Reenviar a mesma requisicao nao duplica pagamento');
$before=inventorySnapshot();$response=sendPaymentTest(array_replace($input,['amount'=>'11.00']));checkMaterial($response['status']===409&&inventorySnapshot()===$before,'Token reutilizado com valor diferente e recusado');
foreach([['amount'=>'0'],['amount'=>'-1'],['amount'=>'1.001'],['amount'=>'24'],['installment_number'=>'0'],['installment_number'=>'4'],['payment_date'=>'2026-02-30'],['payment_method'=>''],['request_token'=>'alterado']] as $invalid){
    $before=inventorySnapshot();$response=sendPaymentTest(paymentTestInput(paymentAccountNow($aid),$invalid));checkMaterial($response['status']===409&&inventorySnapshot()===$before,'Pagamento invalido ou acima do saldo nao altera dados');
}
$before=inventorySnapshot();$response=sendPaymentTest(paymentTestInput($account),'tecnico');checkMaterial($response['status']===403&&inventorySnapshot()===$before,'Tecnico nao registra pagamento por acesso direto');
$before=inventorySnapshot();$response=sendPaymentTest(paymentTestInput($account,['csrf'=>'invalido']));checkMaterial($response['status']===419&&inventorySnapshot()===$before,'Pagamento exige CSRF');
$financialValues=['due_date'=>$account['due_date'],'payment_method'=>$account['payment_method'],'installments'=>(string)$account['installments'],'current_installment'=>(string)$account['current_installment'],'installment_value'=>(string)$account['installment_value']];
foreach([['total_amount'=>'101'],['due_date'=>'2026-12-15'],['payment_method'=>'boleto'],['installments'=>'4'],['current_installment'=>'2'],['installment_value'=>'34']] as $changed){
    $before=inventorySnapshot();testConfigureAccount(paymentAccountNow($aid),array_replace($financialValues,$changed));checkMaterial(inventorySnapshot()===$before,'Primeiro pagamento bloqueia alteracao financeira da conta');
}
testConfigureAccount(paymentAccountNow($aid),array_replace($financialValues,['description'=>'Descricao alterada apos parcial','notes'=>'Texto permitido']));
checkMaterial(paymentAccountNow($aid)['description']==='Descricao alterada apos parcial','Textos da conta continuam editaveis depois de pagamento');
$before=inventorySnapshot();testCorrectLinkedPurchase($context['mid'],['last_purchase_total_cost'=>'110']);checkMaterial(inventorySnapshot()===$before,'Pagamento parcial protege total da compra vinculada');
$before=inventorySnapshot();postMaterial(['action'=>'delete','id'=>(string)$context['mid']]);checkMaterial(inventorySnapshot()===$before,'Pagamento parcial impede exclusao de material sem venda');
$edit=materialHttp('payables.php?edit_id='.$aid);$x=materialXPath($edit['body']);checkMaterial($x->query('//input[@name="total_amount" and @readonly]')->length===1&&$x->query('//select[@name="payment_method" and @disabled]')->length===1,'Formulario protege campos financeiros apos parcial');
foreach([['amount'=>'20'],['amount'=>'3.33'],['installment_number'=>'2','amount'=>'33.33'],['installment_number'=>'3','amount'=>'33.34']] as $more){$response=sendPaymentTest(paymentTestInput(paymentAccountNow($aid),$more));checkMaterial($response['status']===200,'Pagamento parcial/complemento quita parcela sem arredondamento');}
$account=paymentAccountNow($aid);$totals=payable_payment_totals(payable_payment_rows($pdo,$aid));
checkMaterial($totals===[1=>3333,2=>3333,3=>3334]&&$account['status']==='pago','Tres parcelas quitam exatamente R$100 e marcam conta paga');
checkMaterial(payable_projected_amount($account,3)===33.34,'Quitacao nao altera a previsao por vencimento do Dashboard');
$page=materialHttp('payable-payments.php?id='.$aid);checkMaterial(str_contains($page['body'],'Não há saldo em aberto')&&!str_contains($page['body'],'id="payment-amount"'),'Conta quitada nao oferece novo pagamento sem saldo');
$original=payable_payment_rows($pdo,$aid)[0];$before=inventorySnapshot();
$reverse=['csrf'=>'material-test-csrf','action'=>'reverse','payable_id'=>(string)$aid,'payment_id'=>(string)$paymentId,'reason'=>''];
$response=sendPaymentTest($reverse);checkMaterial($response['status']===409&&inventorySnapshot()===$before,'Estorno sem motivo e recusado');
$reverse['reason']='Erro de digitacao <b>conferido</b>';$response=sendPaymentTest($reverse,'financeiro');$reversed=payable_payment_rows($pdo,$aid)[0];
checkMaterial($response['status']===200&&$reversed['reversed_at']!==null&&(int)$reversed['reversed_by']===999999&&$reversed['reversal_reason']===$reverse['reason'],'Estorno registra motivo autor e data');
$unchanged=$reversed;foreach(['reversed_at','reversed_by','reversal_reason'] as $key){$unchanged[$key]=$original[$key];}
checkMaterial($unchanged===$original,'Estorno preserva todos campos originais do pagamento');
$before=inventorySnapshot();sendPaymentTest($reverse);checkMaterial(inventorySnapshot()===$before,'Estorno repetido nao altera saldo nem auditoria novamente');
checkMaterial(paymentAccountNow($aid)['status']==='pendente'&&(payable_payment_totals(payable_payment_rows($pdo,$aid))[1]??0)===2333,'Estorno reabre somente o valor pago da parcela correta');
$page=materialHttp('payable-payments.php?id='.$aid);checkMaterial(str_contains($page['body'],'&lt;b&gt;conferido&lt;/b&gt;')&&!str_contains($page['body'],'<b>conferido</b>'),'Historico exibe motivo com HTML escapado');
$before=inventorySnapshot();sendPaymentTest(array_replace($reverse,['payment_id'=>'999999']));checkMaterial(inventorySnapshot()===$before,'Estorno nao altera pagamento inexistente ou de outra conta');
foreach(payable_payment_rows($pdo,$aid) as $entry)if($entry['reversed_at']===null)sendPaymentTest(array_replace($reverse,['payment_id'=>(string)$entry['id'],'reason'=>'Reversao total ficticia']));
$account=paymentAccountNow($aid);checkMaterial(array_sum(payable_payment_totals(payable_payment_rows($pdo,$aid)))===0&&$account['paid_at']===null&&$account['status']==='pendente'&&(int)$account['payment_history_locked']===1,'Estorno total zera baixas ativas e mantem bloqueio historico');
$before=inventorySnapshot();testCorrectLinkedPurchase($context['mid'],['last_purchase_quantity'=>'3']);checkMaterial(inventorySnapshot()===$before,'Compra continua protegida apos todos estornos');
$before=inventorySnapshot();testConfigureAccount($account,array_replace($financialValues,['total_amount'=>'110']));checkMaterial(inventorySnapshot()===$before,'Conta continua protegida apos todos estornos');
$before=inventorySnapshot();postMaterial(['action'=>'delete','id'=>(string)$context['mid']]);checkMaterial(inventorySnapshot()===$before,'Historico estornado ainda impede apagar material e pagamentos');
$response=sendPaymentTest($input);checkMaterial($response['status']===200&&array_sum(payable_payment_totals(payable_payment_rows($pdo,$aid)))===0,'Repeticao de requisicao original apos estorno nao reativa pagamento');
$fail=createPaymentTestAccount('Conta para falhas ficticia');$failId=(int)$fail['account']['id'];
$pdo->exec('CREATE TRIGGER fail_payment_marker BEFORE UPDATE ON payables WHEN NEW.id='.$failId.' AND NEW.payment_history_locked=1 BEGIN SELECT RAISE(ABORT,"Falha de marcador simulada"); END');
$before=inventorySnapshot();$response=sendPaymentTest(paymentTestInput($fail['account']));checkMaterial($response['status']===500&&inventorySnapshot()===$before,'Falha apos inserir pagamento faz rollback do extrato e dos marcadores');$pdo->exec('DROP TRIGGER fail_payment_marker');
sendPaymentTest(paymentTestInput(paymentAccountNow($failId),['amount'=>'5']));$failPayment=payable_payment_rows($pdo,$failId)[0];
$pdo->exec('CREATE TRIGGER fail_reversal_marker BEFORE UPDATE ON payables WHEN NEW.id='.$failId.' AND NEW.paid_at IS NULL BEGIN SELECT RAISE(ABORT,"Falha de estorno simulada"); END');
$before=inventorySnapshot();$response=sendPaymentTest(['csrf'=>'material-test-csrf','action'=>'reverse','payable_id'=>(string)$failId,'payment_id'=>(string)$failPayment['id'],'reason'=>'Teste rollback']);checkMaterial($response['status']===500&&inventorySnapshot()===$before,'Falha no estorno preserva pagamento original e saldo juntos');$pdo->exec('DROP TRIGGER fail_reversal_marker');
$legacy=createPaymentTestAccount('Conta legado avancado ficticia');$legacyId=(int)$legacy['account']['id'];$pdo->exec('UPDATE payables SET current_installment=2 WHERE id='.$legacyId);
$before=inventorySnapshot();$response=sendPaymentTest(paymentTestInput(paymentAccountNow($legacyId)));checkMaterial($response['status']===409&&inventorySnapshot()===$before,'Parcelas legadas avancadas exigem revisao sem baixas inventadas');
$page=materialHttp('payable-payments.php?id='.$legacyId);checkMaterial(str_contains($page['body'],'histórico anterior precisa de revisão')&&!str_contains($page['body'],'id="payment-amount"'),'Legado inconsistente mostra revisao sem formulario de pagamento');
$legacyMarker=createPaymentTestAccount('Conta marcador legado ficticia');$legacyId=(int)$legacyMarker['account']['id'];$pdo->exec("UPDATE payables SET paid_at='2026-10-01' WHERE id=".$legacyId);
$before=inventorySnapshot();$response=sendPaymentTest(paymentTestInput(paymentAccountNow($legacyId)));checkMaterial($response['status']===409&&inventorySnapshot()===$before,'Marcador antigo sem extrato nao e convertido em pagamento ficticio');
$pdo->exec("INSERT INTO payables(id,description,total_amount,due_date,payment_method,installments,current_installment,installment_value) VALUES(9000,'Conta manual para pagamento',30,'2026-11-05','pix',1,1,30)");
$manual=paymentAccountNow(9000);$response=sendPaymentTest(paymentTestInput($manual),'financeiro');
checkMaterial($response['status']===200&&array_sum(payable_payment_totals(payable_payment_rows($pdo,9000)))===1000,'Conta manual sem material tambem recebe pagamento parcial');
$before=inventorySnapshot();materialHttp('payables.php',['csrf'=>'material-test-csrf','action'=>'delete','payable_id'=>'9000']);checkMaterial(inventorySnapshot()===$before,'Pagamento protege exclusao de conta manual');
$manualPayment=payable_payment_rows($pdo,9000)[0];sendPaymentTest(['csrf'=>'material-test-csrf','action'=>'reverse','payable_id'=>'9000','payment_id'=>(string)$manualPayment['id'],'reason'=>'Estorno de teste manual']);
checkMaterial((int)paymentAccountNow(9000)['payment_history_locked']===1&&array_sum(payable_payment_totals(payable_payment_rows($pdo,9000)))===0,'Estorno total de conta manual conserva protecao e historico');
$before=inventorySnapshot();materialHttp('payables.php',['csrf'=>'material-test-csrf','action'=>'delete','payable_id'=>'9000']);checkMaterial(inventorySnapshot()===$before,'Conta manual com historico estornado nao pode ser apagada');
