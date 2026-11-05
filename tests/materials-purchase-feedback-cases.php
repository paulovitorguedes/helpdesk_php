<?php
if(PHP_SAPI!=='cli'||!isset($pdo,$checks))exit(1);
$pdo->exec("INSERT INTO materials(id,description) VALUES(200,'Compra com mensagem'); INSERT INTO material_purchases(material_id,purchase_date,quantity,total_cost,unit_cost) VALUES(200,'2026-11-05',2,20,10)");
$payload=['csrf'=>'material-test-csrf','action'=>'purchase','material_id'=>'200','purchase_date'=>'2026-11-04','supplier'=>'Fornecedor & NF','quantity'=>'3','total_cost'=>'60.90','purchase_notes'=>'Texto preenchido'];
$send=static fn(array $changes=[])=>materialHttp('materials.php?purchase=200',array_replace($payload,$changes),'admin',['Accept: application/json']);
$page=materialHttp('materials.php?purchase=200');$xpath=materialXPath($page['body']);
checkMaterial($xpath->query('//form[@data-material-purchase]')->length===1,'Nova compra identifica formulario sem navegacao');
checkMaterial($xpath->query('//*[@data-purchase-feedback and @role="alert"]')->length===1&&str_contains($page['body'],'assets/js/materials-purchase.js?v='),'Nova compra possui alerta acessivel e script com versao');
$before=inventorySnapshot();$flashBefore=materialHttp('__test_flash')['body'];$result=$send();$body=json_decode($result['body'],true);
checkMaterial($result['status']===409&&($body['ok']??true)===false&&str_contains($body['message']??'','anterior'),'Compra anterior retorna motivo JSON sem navegar');
checkMaterial(!array_filter($result['headers'],static fn($h)=>str_starts_with(strtolower($h),'location:')),'Compra recusada nao envia Location');
checkMaterial(inventorySnapshot()===$before,'Compra recusada preserva todas as tabelas');
checkMaterial(materialHttp('__test_flash')['body']===$flashBefore,'Erro AJAX nao deixa mensagem duplicada na sessao');
foreach([['purchase_date'=>'2026-02-30'],['quantity'=>'1.5'],['total_cost'=>'-1']] as $invalid){
    $before=inventorySnapshot();$result=$send($invalid);$body=json_decode($result['body'],true);
    checkMaterial($result['status']===422&&($body['ok']??true)===false&&inventorySnapshot()===$before,'Validacao de compra retorna JSON e nao grava');
}
$before=inventorySnapshot();$result=$send(['purchase_date'=>'2026-11-05','purchase_notes'=>'falha simulada']);$body=json_decode($result['body'],true);
checkMaterial($result['status']===500&&($body['ok']??true)===false&&inventorySnapshot()===$before,'Falha na entrada faz rollback e retorna JSON');
checkMaterial(!str_contains($body['message']??'','SQLSTATE'),'Mensagem publica de falha nao expoe SQL');
$before=inventorySnapshot();$result=$send(['csrf'=>'invalido']);checkMaterial($result['status']===419&&inventorySnapshot()===$before,'Compra AJAX preserva verificacao CSRF');
$count=(int)$pdo->query('SELECT COUNT(*) FROM material_purchases WHERE material_id=200')->fetchColumn();
$result=$send(['purchase_date'=>'2026-11-05']);$body=json_decode($result['body'],true);
checkMaterial($result['status']===200&&($body['ok']??false)===true&&($body['redirect']??'')==='/materials.php','Correcao e reenvio concluem compra via JSON');
checkMaterial((int)$pdo->query('SELECT COUNT(*) FROM material_purchases WHERE material_id=200')->fetchColumn()===$count+1,'Reenvio corrigido cria somente uma compra');
$p=$pdo->query('SELECT * FROM material_purchases WHERE material_id=200 ORDER BY id DESC LIMIT 1')->fetch();
checkMaterial($p['supplier']==='Fornecedor & NF'&&$p['notes']==='Texto preenchido'&&(float)$p['quantity']===3.0&&(float)$p['total_cost']===60.9,'Compra corrigida mantem fornecedor quantidade valor e observacoes enviados');
