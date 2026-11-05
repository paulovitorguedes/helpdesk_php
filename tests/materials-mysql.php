<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('HELPDESK_MATERIAL_MYSQL_TEST_PORT') !== '13308') { exit(1); }
require_once dirname(__DIR__) . '/src/material_rules.php';
require_once dirname(__DIR__) . '/src/material_purchase_edit.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=13308;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
$testFolder=getenv('HELPDESK_MATERIAL_MYSQL_TEST_FOLDER');
if(!$testFolder || !is_dir($testFolder))throw new RuntimeException('Diretorio exclusivo de teste ausente.');
if(($argv[1]??'')==='--worker') {
    $pdo->exec('USE helpdesk_material_rules_test');
    $label=$argv[2]??'';
    if(!in_array($label,['purchase','delete','edit-sale','edit-new'],true))exit(1);
    file_put_contents($testFolder.'/'.$label.'-ready','ready');
    $error=null;
    try {
        if($label==='purchase') {$pdo->beginTransaction();material_validate_next_purchase($pdo,1,'2026-10-21');$pdo->commit();}
        elseif($label==='delete') {material_delete_unused($pdo,3);}
        else {material_update_latest_purchase($pdo,10,json_decode(file_get_contents($testFolder.'/'.$label.'-input.json'),true,512,JSON_THROW_ON_ERROR));}
    } catch(Throwable $exception) {if($pdo->inTransaction())$pdo->rollBack();$error=$exception->getMessage();}
    file_put_contents($testFolder.'/'.$label.'-result.json',json_encode(['error'=>$error],JSON_UNESCAPED_UNICODE));
    exit;
}
$checks=0;$failures=[];
$check=static function(bool $ok,string $label)use(&$checks,&$failures):void{$checks++;if(!$ok)$failures[]=$label;};
$child=null;
try {
    $pdo->exec('CREATE DATABASE helpdesk_material_rules_test');
    $pdo->exec('USE helpdesk_material_rules_test');
    $pdo->exec('CREATE TABLE materials(id INT PRIMARY KEY,deleted_at DATETIME NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE material_purchases(id INT AUTO_INCREMENT PRIMARY KEY,material_id INT NOT NULL,purchase_date DATE NOT NULL,FOREIGN KEY(material_id) REFERENCES materials(id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE ticket_materials(id INT PRIMARY KEY,material_id INT NOT NULL,FOREIGN KEY(material_id) REFERENCES materials(id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE stock_movements(id INT PRIMARY KEY,material_id INT NOT NULL,movement_type VARCHAR(20),ticket_id INT NULL,FOREIGN KEY(material_id) REFERENCES materials(id)) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE payables(id INT AUTO_INCREMENT PRIMARY KEY, description VARCHAR(190) NOT NULL DEFAULT '', supplier VARCHAR(190), category VARCHAR(120), notes TEXT, total_amount DECIMAL(12,2) NOT NULL DEFAULT 0, due_date DATE NULL, payment_method VARCHAR(20), installments INT, current_installment INT, installment_value DECIMAL(12,2), status VARCHAR(20) NOT NULL DEFAULT 'pendente', paid_at DATE NULL, material_purchase_id INT NULL UNIQUE, configuration_pending TINYINT NOT NULL DEFAULT 0, total_manually_changed TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
    $pdo->exec('INSERT INTO materials VALUES(1,NULL),(2,NULL),(3,NULL),(4,NULL)');
    $pdo->exec("INSERT INTO material_purchases(material_id,purchase_date) VALUES(1,'2026-10-20'),(2,'2026-10-10')");
    $pdo->beginTransaction();material_validate_next_purchase($pdo,1,'2026-10-20');$pdo->commit();$check(true,'MariaDB aceita compra na mesma data');
    $pdo->beginTransaction();material_validate_next_purchase($pdo,2,'2026-10-15');$pdo->commit();$check(true,'MariaDB compara datas por material');
    foreach(['purchase','delete'] as $label) {
        $pdo->beginTransaction();material_lock($pdo,$label==='purchase'?1:3);
        $child=proc_open([PHP_BINARY,__FILE__,'--worker',$label],[0=>['pipe','r'],1=>['file',$testFolder.'/'.$label.'-stdout.log','a'],2=>['file',$testFolder.'/'.$label.'-stderr.log','a']],$pipes,null,getenv(),['bypass_shell'=>true,'create_new_console'=>false]);
        if(!is_resource($child))throw new RuntimeException('Nao iniciou processo concorrente.');fclose($pipes[0]);
        $readyFile=$testFolder.'/'.$label.'-ready';$resultFile=$testFolder.'/'.$label.'-result.json';
        for($attempt=0;$attempt<50&&!file_exists($readyFile);$attempt++)usleep(100000);
        if(!file_exists($readyFile))throw new RuntimeException('Processo concorrente nao ficou pronto.');
        usleep(200000);
        $check(!file_exists($resultFile),'Operacao concorrente aguarda bloqueio: '.$label);
        $pdo->exec($label==='purchase'?"INSERT INTO material_purchases(material_id,purchase_date) VALUES(1,'2026-10-22')":"INSERT INTO stock_movements(id,material_id,movement_type,ticket_id) VALUES(1,3,'saida',1)");
        $pdo->commit();
        $workerExit=proc_close($child);$child=null;
        $check($workerExit===0&&file_exists($resultFile),'Processo concorrente conclui: '.$label);
        $result=json_decode(file_get_contents($resultFile),true,512,JSON_THROW_ON_ERROR);
        $check(is_string($result['error'])&&str_contains($result['error'],$label==='purchase'?'anterior':'venda'),'Revalidacao apos desbloqueio: '.$label);
        $check(!$pdo->inTransaction(),'Transacao principal finalizada: '.$label);
    }
    $check((int)$pdo->query('SELECT COUNT(*) FROM materials WHERE id=3')->fetchColumn()===1,'Exclusao concorrente preserva material que acabou de ter saida');
    material_delete_unused($pdo,2);
    $check((int)$pdo->query('SELECT COUNT(*) FROM materials WHERE id=2')->fetchColumn()===0,'MariaDB exclui material com compra e sem venda');
    $check((int)$pdo->query('SELECT COUNT(*) FROM material_purchases WHERE material_id=2')->fetchColumn()===0,'MariaDB apaga compras vinculadas sem violar FK');
    material_delete_unused($pdo,4);
    $check((int)$pdo->query('SELECT COUNT(*) FROM materials WHERE id=4')->fetchColumn()===0,'MariaDB exclui cadastro sem historico');
    require __DIR__.'/materials-purchase-edit-mysql-cases.php';
    $report=['checks'=>$checks,'failures'=>$failures,'scope'=>'MariaDB 10.4.32 novo e isolado, porta 13308, dados sinteticos; locks reais de InnoDB em dois processos. Nenhuma configuracao ou banco de producao utilizado.'];
    file_put_contents($testFolder.'/resultado-concorrencia.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    if(is_resource($child)){proc_terminate($child);proc_close($child);}
    $pdo->exec('SHUTDOWN');
}
exit($failures?1:0);
