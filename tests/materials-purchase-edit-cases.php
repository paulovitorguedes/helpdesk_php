<?php
if (PHP_SAPI !== 'cli' || !isset($pdo, $checks)) { exit(1); }
$pdo->exec("INSERT INTO materials(id,description) VALUES(100,'Compra corrigivel');
INSERT INTO material_purchases(id,material_id,purchase_date,supplier,quantity,total_cost,unit_cost,notes) VALUES(100,100,'2026-10-10','Anterior',2,40,20,''),(101,100,'2026-10-20','Ultimo',5,250,50,'Original');
INSERT INTO stock_movements(material_id,movement_type,quantity,purchase_id,created_by) VALUES(100,'entrada',2,100,999999),(100,'entrada',5,101,999999)");
function purchaseEditInput(array $changes=[]): array {
    global $pdo;
    $purchase=material_latest_purchase($pdo,100);
    return array_replace(['csrf'=>'material-test-csrf','action'=>'save','id'=>'100','edit_last_purchase'=>'1','description'=>'Compra corrigivel','minimum_stock'=>'0','notes'=>'Cadastro',
        'last_purchase_id'=>(string)$purchase['id'],'last_purchase_revision'=>material_purchase_revision($purchase),
        'last_purchase_date'=>$purchase['purchase_date'],'last_purchase_quantity'=>(string)$purchase['quantity'],'last_purchase_total_cost'=>(string)$purchase['total_cost'],
        'last_purchase_supplier'=>$purchase['supplier'],'last_purchase_notes'=>$purchase['notes']],$changes);
}
function purchaseEdit(array $changes=[],string $role='admin'): array {return materialHttp('materials.php?edit=100',purchaseEditInput($changes),$role);}
function latestTestPurchase():array {global $pdo;return material_latest_purchase($pdo,100);}
$page=materialHttp('materials.php?edit=100');$xpath=materialXPath($page['body']);
checkMaterial($page['status']===200&&$xpath->query('//input[@name="last_purchase_id" and @value="101"]')->length===1,'Editar exibe somente ultima compra');
checkMaterial($xpath->query('//input[@name="last_purchase_total_cost" and @value="250.00"]')->length===1,'Formulario preenche valores reais da ultima compra');
$prior=$pdo->query('SELECT * FROM material_purchases WHERE id=100')->fetch();
$stale=purchaseEditInput();
purchaseEdit(['last_purchase_date'=>'2026-10-15','last_purchase_quantity'=>'3','last_purchase_total_cost'=>'100.00','last_purchase_supplier'=>'Corrigido','last_purchase_notes'=>'NF conferida']);
$current=latestTestPurchase();
checkMaterial($current['purchase_date']==='2026-10-15'&&(int)$current['quantity']===3&&(float)$current['total_cost']===100.0,'Correcao conforme NF antes da data digitada e depois da precedente aceita');
checkMaterial((float)$current['unit_cost']===33.3333&&stock(100)===5.0,'Custo em quatro casas e entrada corrigidos juntos');
checkMaterial($current['supplier']==='Corrigido'&&$current['notes']==='NF conferida','Fornecedor e observacao da compra atualizados');
checkMaterial($prior===$pdo->query('SELECT * FROM material_purchases WHERE id=100')->fetch(),'Compra precedente permanece intacta');
$before=inventorySnapshot();materialHttp('materials.php',$stale);checkMaterial(inventorySnapshot()===$before,'Formulario antigo nao sobrescreve correcao concorrente');
$pdo->exec('UPDATE material_purchases SET unit_cost=33.3332 WHERE id=101');
purchaseEdit(['last_purchase_date'=>'2026-10-10']);checkMaterial(latestTestPurchase()['purchase_date']==='2026-10-10','Correcao pode igualar data da compra precedente');
checkMaterial((float)latestTestPurchase()['unit_cost']===33.3332,'Correcao somente de data preserva arredondamento legado do custo');
$before=inventorySnapshot();purchaseEdit(['last_purchase_date'=>'2026-10-09','description'=>'Nao gravar']);checkMaterial(inventorySnapshot()===$before,'Data anterior a precedente recusa sem alterar cadastro');
foreach(['1.5','0','-2','1e2'] as $invalid){$before=inventorySnapshot();purchaseEdit(['last_purchase_quantity'=>$invalid]);checkMaterial(inventorySnapshot()===$before,'Edicao recusa quantidade invalida '.$invalid);}
foreach(['-1','1.001','1e2','10000000000','NaN'] as $invalid){$before=inventorySnapshot();purchaseEdit(['last_purchase_total_cost'=>$invalid]);checkMaterial(inventorySnapshot()===$before,'Edicao recusa total invalido '.$invalid);}
$before=inventorySnapshot();purchaseEdit(['last_purchase_quantity'=>'1','last_purchase_total_cost'=>'9999999999.99']);checkMaterial(inventorySnapshot()===$before,'Limite decimal do custo unitario respeitado');
$before=inventorySnapshot();purchaseEdit(['last_purchase_date'=>'2026-02-30']);checkMaterial(inventorySnapshot()===$before,'Edicao recusa data impossivel');
$before=inventorySnapshot();purchaseEdit(['last_purchase_quantity'=>'4'],'tecnico');checkMaterial(inventorySnapshot()===$before,'Tecnico nao corrige compra por POST direto');
$before=inventorySnapshot();purchaseEdit(['csrf'=>'incorreto','last_purchase_quantity'=>'4']);checkMaterial(inventorySnapshot()===$before,'Correcao exige CSRF valido');
purchaseEdit(['last_purchase_total_cost'=>'0.00'],'financeiro');checkMaterial((float)latestTestPurchase()['unit_cost']===0.0,'Financeiro pode corrigir compra para custo zero');
$pdo->exec("CREATE TRIGGER fail_purchase_correction BEFORE UPDATE ON stock_movements WHEN NEW.purchase_id=101 AND NEW.quantity=4 BEGIN SELECT RAISE(ABORT,'Falha simulada na correcao'); END");
$before=inventorySnapshot();purchaseEdit(['last_purchase_quantity'=>'4','description'=>'Nao persistir parcialmente']);checkMaterial(inventorySnapshot()===$before,'Falha na entrada desfaz compra e cadastro na mesma transacao');
$pdo->exec('DROP TRIGGER fail_purchase_correction');
$pdo->exec("INSERT INTO stock_movements(material_id,movement_type,quantity,created_by) VALUES(100,'saida',1,999999),(100,'entrada',1,999999)");
$before=inventorySnapshot();purchaseEdit(['last_purchase_quantity'=>'4']);checkMaterial(inventorySnapshot()===$before,'Saida posterior protege mesmo depois de devolucao total');
$before=inventorySnapshot();purchaseEdit(['last_purchase_date'=>'2026-10-20']);checkMaterial(inventorySnapshot()===$before,'POST forjado nao altera data protegida');
$before=inventorySnapshot();purchaseEdit(['last_purchase_total_cost'=>'200']);checkMaterial(inventorySnapshot()===$before,'POST forjado nao altera custo protegido');
$unit=latestTestPurchase()['unit_cost'];purchaseEdit(['last_purchase_supplier'=>'Fornecedor corrigido','last_purchase_notes'=>'Somente texto','description'=>'Cadastro corrigido']);
checkMaterial(latestTestPurchase()['supplier']==='Fornecedor corrigido'&&latestTestPurchase()['unit_cost']===$unit,'Campos descritivos continuam editaveis apos saida sem reprecificar custo');
$page=materialHttp('materials.php?edit=100');$xpath=materialXPath($page['body']);
checkMaterial($xpath->query('//input[@name="last_purchase_quantity" and @readonly]')->length===1&&$xpath->query('//input[@name="last_purchase_supplier" and @readonly]')->length===0,'Interface explica bloqueio e preserva fornecedor editavel');
$before=inventorySnapshot();purchaseEdit(['last_purchase_id'=>'100']);checkMaterial(inventorySnapshot()===$before,'Nao permite selecionar compra antiga pelo POST');
$stale=purchaseEditInput();
$pdo->exec("INSERT INTO material_purchases(id,material_id,purchase_date,supplier,quantity,total_cost,unit_cost,notes) VALUES(102,100,'2026-10-25','Nova',2,20,10,''); INSERT INTO stock_movements(material_id,movement_type,quantity,purchase_id,created_by) VALUES(100,'entrada',2,102,999999)");
$before=inventorySnapshot();materialHttp('materials.php',$stale);checkMaterial(inventorySnapshot()===$before,'Nova compra apos abrir formulario invalida edicao anterior');
purchaseEdit(['last_purchase_quantity'=>'3']);checkMaterial((int)latestTestPurchase()['quantity']===3,'Saida anterior a ultima entrada nao bloqueia compra nova');
$pdo->exec('UPDATE stock_movements SET quantity=99 WHERE purchase_id=102');
$before=inventorySnapshot();purchaseEdit(['last_purchase_quantity'=>'4']);checkMaterial(inventorySnapshot()===$before,'Entrada inconsistente bloqueia correcao numerica');
checkMaterial(json_encode([$pdo->query('SELECT * FROM receivables')->fetchAll(),$pdo->query('SELECT * FROM payables')->fetchAll()])===$financialBefore,'Edicao isolada nao altera contas financeiras existentes');
