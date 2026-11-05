<?php
if(PHP_SAPI!=='cli'||!isset($pdo,$check,$testFolder))exit(1);
$pdo->exec("ALTER TABLE materials ADD description VARCHAR(190) NOT NULL DEFAULT '', ADD minimum_stock DECIMAL(12,2) NOT NULL DEFAULT 0, ADD notes TEXT NULL;
ALTER TABLE material_purchases ADD supplier VARCHAR(190) NULL, ADD quantity DECIMAL(12,2) NOT NULL DEFAULT 1, ADD total_cost DECIMAL(12,2) NOT NULL DEFAULT 0, ADD unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0, ADD notes TEXT NULL;
ALTER TABLE stock_movements ADD purchase_id INT NULL, ADD quantity DECIMAL(12,2) NOT NULL DEFAULT 1;
INSERT INTO materials(id,description) VALUES(10,'Compra ficticia');
INSERT INTO material_purchases(id,material_id,purchase_date,quantity,total_cost,unit_cost) VALUES(10,10,'2026-10-10',2,40,20),(11,10,'2026-10-20',5,250,50);
INSERT INTO stock_movements(id,material_id,movement_type,purchase_id,quantity) VALUES(100,10,'entrada',10,2),(101,10,'entrada',11,5)");
$makeInput=static function(array $changes=[])use($pdo):array{
    $p=material_latest_purchase($pdo,10);
    return array_replace(['last_purchase_id'=>(string)$p['id'],'last_purchase_revision'=>material_purchase_revision($p),'last_purchase_date'=>$p['purchase_date'],
        'last_purchase_quantity'=>(string)$p['quantity'],'last_purchase_total_cost'=>(string)$p['total_cost'],'last_purchase_supplier'=>'Fornecedor ficticio','last_purchase_notes'=>'NF conferida',
        'description'=>'Compra corrigida','minimum_stock'=>'0','notes'=>'Teste isolado'],$changes);
};
material_update_latest_purchase($pdo,10,$makeInput(['last_purchase_date'=>'2026-10-15','last_purchase_quantity'=>'3','last_purchase_total_cost'=>'100.00']));
$p=material_latest_purchase($pdo,10);
$check($p['purchase_date']==='2026-10-15'&&(float)$p['quantity']===3.0&&(float)$p['unit_cost']===33.3333,'MariaDB corrige data/quantidade e custo na precisao real');
$check((float)$pdo->query('SELECT quantity FROM stock_movements WHERE id=101')->fetchColumn()===3.0,'MariaDB corrige a mesma entrada sem duplicar estoque');
$snapshot=static function()use($pdo):string{$all=[];foreach(['materials','material_purchases','stock_movements'] as $table)$all[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();return json_encode($all);};
$pdo->exec("CREATE TRIGGER fail_edit_stock BEFORE UPDATE ON stock_movements FOR EACH ROW BEGIN IF NEW.id=101 AND NEW.quantity=4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Falha simulada de estoque'; END IF; END");
$before=$snapshot();$error=null;try{material_update_latest_purchase($pdo,10,$makeInput(['last_purchase_quantity'=>'4','description'=>'Nao salvar']));}catch(Throwable $e){$error=$e;}
$check($error!==null&&$snapshot()===$before&&!$pdo->inTransaction(),'MariaDB rollback preserva compra/cadastro/estoque apos falha intermediaria');
$pdo->exec('DROP TRIGGER fail_edit_stock');
foreach(['edit-sale','edit-new'] as $label){
    file_put_contents($testFolder.'/'.$label.'-input.json',json_encode($makeInput(['last_purchase_quantity'=>'4']),JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();material_lock($pdo,10);
    $child=proc_open([PHP_BINARY,__DIR__.'/materials-mysql.php','--worker',$label],[0=>['pipe','r'],1=>['file',$testFolder.'/'.$label.'-stdout.log','a'],2=>['file',$testFolder.'/'.$label.'-stderr.log','a']],$pipes,null,getenv(),['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($child))throw new RuntimeException('Nao iniciou edicao concorrente.');fclose($pipes[0]);
    $readyFile=$testFolder.'/'.$label.'-ready';$resultFile=$testFolder.'/'.$label.'-result.json';
    for($i=0;$i<50&&!file_exists($readyFile);$i++)usleep(100000);
    if(!file_exists($readyFile))throw new RuntimeException('Worker de edicao nao ficou pronto.');
    usleep(200000);$check(!file_exists($resultFile),'Edicao aguarda lock real do material: '.$label);
    if($label==='edit-sale')$pdo->exec("INSERT INTO stock_movements(id,material_id,movement_type,quantity) VALUES(102,10,'saida',1)");
    else $pdo->exec("INSERT INTO material_purchases(id,material_id,purchase_date,quantity,total_cost,unit_cost) VALUES(12,10,'2026-10-25',2,20,10); INSERT INTO stock_movements(id,material_id,movement_type,purchase_id,quantity) VALUES(103,10,'entrada',12,2)");
    $pdo->commit();$before=$snapshot();
    $code=proc_close($child);$child=null;
    $check($code===0&&file_exists($resultFile),'Worker de edicao conclui: '.$label);
    $result=json_decode(file_get_contents($resultFile),true,512,JSON_THROW_ON_ERROR);
    $check(is_string($result['error'])&&str_contains($result['error'],$label==='edit-sale'?'saída':'última compra mudou'),'Edicao revalida venda/nova compra apos aguardar lock: '.$label);
    $check($snapshot()===$before,'Edicao concorrente recusada preserva todos registros: '.$label);
}
