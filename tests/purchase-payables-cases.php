<?php
if(PHP_SAPI!=='cli'||!isset($pdo,$checks))exit(1);
function testFinancialAccount(int $purchaseId):array {global $pdo;$s=$pdo->prepare('SELECT * FROM payables WHERE material_purchase_id=?');$s->execute([$purchaseId]);return $s->fetch();}
function testConfigureAccount(array $account,array $changes=[],string $role='admin'):array {
    $payload=array_replace(['csrf'=>'material-test-csrf','action'=>'update','payable_id'=>(string)$account['id'],'return_month'=>'11','return_year'=>'2026',
        'due_date'=>'2026-11-15','description'=>$account['description'],'category'=>'Materiais','supplier'=>$account['supplier'],
        'total_amount'=>(string)$account['total_amount'],'payment_method'=>'boleto','installments'=>'2','current_installment'=>'1',
        'installment_value'=>(string)((float)$account['total_amount']/2),'notes'=>'Configuracao ficticia','linked_revision'=>purchase_payable_revision($account)],$changes);
    return materialHttp('payables.php?month=11&year=2026',$payload,$role);
}
function testCorrectLinkedPurchase(int $materialId,array $changes=[]):array {
    global $pdo;$p=material_latest_purchase($pdo,$materialId);
    return postMaterial(array_replace(['id'=>(string)$materialId,'description'=>'Integracao financeira ficticia','minimum_stock'=>'0','edit_last_purchase'=>'1',
        'last_purchase_id'=>(string)$p['id'],'last_purchase_revision'=>material_purchase_revision($p),'last_purchase_date'=>$p['purchase_date'],
        'last_purchase_quantity'=>(string)$p['quantity'],'last_purchase_total_cost'=>(string)$p['total_cost'],'last_purchase_supplier'=>$p['supplier'],'last_purchase_notes'=>$p['notes']],$changes));
}
checkMaterial((int)$pdo->query('SELECT COUNT(*) FROM payables WHERE material_purchase_id=1')->fetchColumn()===0,'Integracao nao cria divida retroativa para compra legada');
postMaterial(['description'=>'Integracao financeira ficticia','purchase_date'=>'2026-11-05','total_cost'=>'100']);
$mid=(int)$pdo->query("SELECT id FROM materials WHERE description='Integracao financeira ficticia' ORDER BY id DESC LIMIT 1")->fetchColumn();
$p=material_latest_purchase($pdo,$mid);$account=testFinancialAccount((int)$p['id']);
checkMaterial($account&&(int)$account['configuration_pending']===1&&(float)$account['total_amount']===100.0,'Compra inicial gera exatamente uma conta vinculada a configurar');
checkMaterial($account['due_date']===null&&$account['payment_method']===null&&$account['installments']===null&&$account['current_installment']===null&&$account['installment_value']===null,'Conta nasce sem vencimento forma ou parcelas inventados');
$page=materialHttp('payables.php?month=11&year=2026');$x=materialXPath($page['body']);
checkMaterial($page['status']===200&&!str_contains($page['body'],'Fatal error'),'Contas a Pagar abre com contas sem data');
checkMaterial($x->query('//table[@id="unconfiguredPayables"]//a[contains(@href,"edit_id='.$account['id'].'")]')->length===1,'Conta aparece na lista separada A configurar');
checkMaterial($x->query('//table[@id="payables"]//a[contains(@href,"edit_id='.$account['id'].'")]')->length===0,'Conta A configurar nao participa da lista mensal');
$edit=materialHttp('payables.php?edit_id='.$account['id']);$x=materialXPath($edit['body']);
checkMaterial($x->query('//input[@name="due_date" and @value=""]')->length===1&&$x->query('//input[@name="installments" and @value=""]')->length===1,'Formulario de configuracao nao preenche data ou parcelas automaticamente');
testConfigureAccount($account,[],'financeiro');$account=testFinancialAccount((int)$p['id']);
checkMaterial((int)$account['configuration_pending']===0&&(int)$account['total_manually_changed']===0&&$account['due_date']==='2026-11-15','Financeiro configura conta sem marcar valor inalterado como manual');
$page=materialHttp('payables.php?month=11&year=2026');$x=materialXPath($page['body']);
checkMaterial($x->query('//table[@id="payables"]//a[contains(@href,"edit_id='.$account['id'].'")]')->length===1,'Conta configurada passa a participar da lista mensal');
$stale=$account;testCorrectLinkedPurchase($mid,['last_purchase_total_cost'=>'110']);$account=testFinancialAccount((int)$p['id']);
checkMaterial((float)$account['total_amount']===110.0&&(int)$account['configuration_pending']===1,'Correcao do total sincroniza conta e exige nova revisao');
checkMaterial($account['due_date']==='2026-11-15'&&(int)$account['installments']===2,'Revisao conserva configuracao anterior como rascunho sem recalcular parcelas');
$before=inventorySnapshot();testConfigureAccount($stale);checkMaterial(inventorySnapshot()===$before,'Formulario financeiro antigo nao desfaz correcao da compra');
testConfigureAccount($account);$account=testFinancialAccount((int)$p['id']);testConfigureAccount($account,['total_amount'=>'120','installment_value'=>'60']);$account=testFinancialAccount((int)$p['id']);
checkMaterial((float)$account['total_amount']===120.0&&(int)$account['total_manually_changed']===1,'Alteracao direta do total e permitida e identificada como manual');
testCorrectLinkedPurchase($mid,['last_purchase_total_cost'=>'130']);$account=testFinancialAccount((int)$p['id']);
checkMaterial((float)$account['total_amount']===120.0&&(int)$account['configuration_pending']===0,'Correcao posterior preserva total manual e sua configuracao');
checkMaterial(str_contains(materialHttp('__test_flash')['body'],'valor manual'),'Correcao informa que total financeiro manual foi mantido');
$page=materialHttp('payables.php?month=11&year=2026');checkMaterial(str_contains($page['body'],'Total manual diferente da compra.'),'Lista financeira avisa divergencia manual');
foreach([['installments'=>''],['installments'=>'1.5'],['due_date'=>'2026-02-30'],['payment_method'=>''],['total_amount'=>'120.001']] as $invalid){
    $before=inventorySnapshot();testConfigureAccount($account,$invalid);checkMaterial(inventorySnapshot()===$before,'Configuracao incompleta ou invalida nao altera conta');
}
$before=inventorySnapshot();testConfigureAccount($account,['total_amount'=>'140'],'tecnico');checkMaterial(inventorySnapshot()===$before,'Tecnico nao configura conta por POST direto');
$before=inventorySnapshot();testConfigureAccount($account,['csrf'=>'invalido']);checkMaterial(inventorySnapshot()===$before,'Configuracao exige CSRF');
$before=inventorySnapshot();materialHttp('payables.php',['csrf'=>'material-test-csrf','action'=>'delete','payable_id'=>$account['id']]);checkMaterial(inventorySnapshot()===$before,'Conta vinculada nao pode ser excluida isoladamente');
postPurchase(['material_id'=>(string)$mid,'purchase_date'=>'2026-11-06','total_cost'=>'40']);$p2=material_latest_purchase($pdo,$mid);$account2=testFinancialAccount((int)$p2['id']);
checkMaterial($account2&&(int)$account2['id']!==(int)$account['id']&&(float)$account2['total_amount']===40.0,'Cada nova compra gera sua propria obrigacao');
$before=inventorySnapshot();try{$pdo->beginTransaction();purchase_payable_create($pdo,$mid,(int)$p2['id'],'Duplicado',40);$pdo->commit();checkMaterial(false,'Vinculo duplicado deve falhar');}catch(Throwable){if($pdo->inTransaction())$pdo->rollBack();checkMaterial(inventorySnapshot()===$before,'Restricao unica impede duas contas para a mesma compra');}
$pdo->exec("CREATE TRIGGER fail_financial_sync BEFORE UPDATE ON payables WHEN NEW.total_amount=999 BEGIN SELECT RAISE(ABORT,'Falha financeira simulada'); END");
$before=inventorySnapshot();testCorrectLinkedPurchase($mid,['last_purchase_total_cost'=>'999','last_purchase_quantity'=>'3']);checkMaterial(inventorySnapshot()===$before,'Falha financeira desfaz correcao de compra cadastro e estoque');
$pdo->exec('DROP TRIGGER fail_financial_sync');
$pdo->exec("UPDATE payables SET paid_at='2026-11-06',status='pendente' WHERE id=".(int)$account2['id']);
$before=inventorySnapshot();testCorrectLinkedPurchase($mid,['last_purchase_quantity'=>'3']);checkMaterial(inventorySnapshot()===$before,'Marcador de pagamento parcial bloqueia correcao numerica da compra');
$before=inventorySnapshot();postMaterial(['action'=>'delete','id'=>(string)$mid]);checkMaterial(inventorySnapshot()===$before,'Pagamento parcial bloqueia exclusao conjunta do material');
testCorrectLinkedPurchase($mid,['last_purchase_supplier'=>'Texto permitido']);checkMaterial(material_latest_purchase($pdo,$mid)['supplier']==='Texto permitido','Pagamento nao bloqueia correcao descritiva');
$pdo->exec('UPDATE payables SET paid_at=NULL,status="pago" WHERE id='.(int)$account2['id']);
$before=inventorySnapshot();testCorrectLinkedPurchase($mid,['last_purchase_total_cost'=>'50']);checkMaterial(inventorySnapshot()===$before,'Status pago tambem protege compra mesmo sem data preenchida');
$pdo->exec('UPDATE payables SET paid_at=NULL,status="pendente" WHERE id='.(int)$account2['id']);
postMaterial(['action'=>'delete','id'=>(string)$mid]);
checkMaterial((int)$pdo->query('SELECT COUNT(*) FROM materials WHERE id='.$mid)->fetchColumn()===0,'Material sem venda e sem pagamento pode ser excluido');
checkMaterial((int)$pdo->query('SELECT COUNT(*) FROM payables WHERE id IN ('.(int)$account['id'].','.(int)$account2['id'].')')->fetchColumn()===0,'Exclusao conjunta apaga somente contas vinculadas elegiveis');
checkMaterial((int)$pdo->query('SELECT COUNT(*) FROM payables WHERE id=1')->fetchColumn()===1,'Conta manual antiga nao e apagada por nome ou valor');
$pdo->exec("CREATE TRIGGER fail_financial_create BEFORE INSERT ON payables WHEN NEW.description='Falha financeira' BEGIN SELECT RAISE(ABORT,'Falha financeira simulada'); END");
$before=inventorySnapshot();postMaterial(['description'=>'Falha financeira']);checkMaterial(inventorySnapshot()===$before,'Falha ao gerar obrigacao desfaz material compra e entrada juntos');
$pdo->exec('DROP TRIGGER fail_financial_create');
postMaterial(['description'=>'Compra gratuita vinculada','purchase_date'=>'2026-11-05','total_cost'=>'0']);$freeMid=(int)$pdo->query("SELECT id FROM materials WHERE description='Compra gratuita vinculada'")->fetchColumn();$free=testFinancialAccount((int)material_latest_purchase($pdo,$freeMid)['id']);
testConfigureAccount($free,['installments'=>'1','installment_value'=>'0']);checkMaterial((int)testFinancialAccount((int)$free['material_purchase_id'])['configuration_pending']===0,'Compra gratuita gera obrigacao zero que pode ser configurada');
