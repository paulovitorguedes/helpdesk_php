<?php
if(PHP_SAPI!=='cli'||getenv('HELPDESK_MATERIAL_MYSQL_TEST_PORT')!=='13308')exit(1);
require dirname(__DIR__).'/src/ticket_billing_integrity.php';
$source=file_get_contents(dirname(__DIR__).'/public/receivables.php');$a=strpos($source,'function validDate(');$b=strpos($source,'$today =',$a);
if($a===false||$b===false)throw new RuntimeException('Funcoes de recebiveis nao localizadas.');
define('FINANCIAL_START_COMPETENCE','2026-09');eval(substr($source,$a,$b-$a));
$source=file_get_contents(dirname(__DIR__).'/public/tickets.php');$a=strpos($source,'function billingSchedulesAreEqual(');$b=strpos($source,'function ticket_locked(',$a);
if($a===false||$b===false)throw new RuntimeException('Funcoes de cronograma nao localizadas.');eval(substr($source,$a,$b-$a));
$checks=0;$failures=[];$check=static function(bool $ok,string $label)use(&$checks,&$failures){$checks++;if(!$ok)$failures[]=$label;};
$p=new PDO('mysql:host=127.0.0.1;port=13308;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
try{
    $p->exec('CREATE DATABASE ssd_receivable_test');$p->exec('USE ssd_receivable_test');$p->exec(file_get_contents(__DIR__.'/fixtures/purchase-integration-base.sql'));
    $p->exec("INSERT INTO clients(id,name,has_contract,due_day,monthly_fee,extra_visit_fee) VALUES(10,'A Cliente ficticio',1,20,350,35),(12,'B Cliente ficticio',1,20,350,35);
    INSERT INTO materials(id,description) VALUES(27,'SSD ficticio');
    INSERT INTO tickets(id,ticket_number,client_id,occurred_at,complainant,request_text,service_type,status,materials_sold) VALUES(51,'TESTE-01',12,'2026-11-01','Pessoa ficticia','Teste','presencial','fechado',0),(52,'TESTE-02',12,'2026-11-06','Pessoa ficticia','Teste','presencial','fechado',1);
    INSERT INTO ticket_materials(ticket_id,material_id,description,quantity,unit_price) VALUES(52,27,'SSD ficticio',1,230);
    INSERT INTO receivables(id,client_id,competence,cycle_start,cycle_end,due_date,monthly_fee,total_amount) VALUES(354,10,'2026-11','2026-10-16','2026-11-15','2026-11-20',350,350),(1166,12,'2026-11','2026-10-16','2026-11-15','2026-11-20',350,350);
    INSERT INTO ticket_billing_installments(id,ticket_id,charge_type,installment_number,installment_count,due_date,amount) VALUES(50,52,'materiais',1,1,'2026-11-20',230)");
    recalculate_cycle($p,'2026-10-16','2026-11-15');
    $check((int)$p->query('SELECT receivable_id FROM ticket_billing_installments WHERE id=50')->fetchColumn()===1166,'Criacao vincula venda ao cliente correto');
    $check((float)$p->query('SELECT materials_amount FROM receivables WHERE id=1166')->fetchColumn()===230.0,'Venda de R$230 entra na competencia de novembro');
    $check((float)$p->query('SELECT extra_visits_amount FROM receivables WHERE id=1166')->fetchColumn()===35.0,'Visita adicional continua separada em R$35');
    $r=['schedule'=>$p->query('SELECT id,receivable_id FROM ticket_billing_installments')->fetchAll(),'accounts'=>$p->query('SELECT id,client_id,materials_amount,extra_visits_amount,total_amount FROM receivables ORDER BY id')->fetchAll()];
    file_put_contents(getenv('HELPDESK_MATERIAL_MYSQL_TEST_FOLDER').'/reproducao.json',json_encode($r,JSON_PRETTY_PRINT));echo json_encode($r,JSON_PRETTY_PRINT).PHP_EOL;
    $p->exec('UPDATE tickets SET client_id=10 WHERE id=52');$p->exec('UPDATE ticket_billing_installments SET receivable_id=NULL WHERE id=50');
    recalculate_cycle($p,'2026-10-16','2026-11-15');
    $before=$p->query('SELECT receivable_id FROM ticket_billing_installments WHERE id=50')->fetchColumn();
    $p->beginTransaction();$p->exec('UPDATE tickets SET client_id=12 WHERE id=52');
    saveBillingSchedule($p,52,'materiais',[['installment_number'=>1,'installment_count'=>1,'due_date'=>'2026-11-20','amount'=>230]]);$p->commit();
    recalculate_cycle($p,'2026-10-16','2026-11-15');
    $r=['previous_link'=>$before,'schedule'=>$p->query('SELECT id,receivable_id FROM ticket_billing_installments')->fetchAll(),'accounts'=>$p->query('SELECT id,client_id,materials_amount,extra_visits_amount,total_amount FROM receivables ORDER BY id')->fetchAll()];
    file_put_contents(getenv('HELPDESK_MATERIAL_MYSQL_TEST_FOLDER').'/reproducao-troca.json',json_encode($r,JSON_PRETTY_PRINT));echo json_encode($r,JSON_PRETTY_PRINT).PHP_EOL;
    $check((int)$r['schedule'][0]['receivable_id']===1166,'Troca de cliente com cronograma igual nao conserva vinculo anterior');
    $check((float)$r['accounts'][0]['materials_amount']===0.0&&(float)$r['accounts'][1]['materials_amount']===230.0,'Recalculo transfere somente a venda, sem cobrar em dois clientes');
    $check((float)$r['accounts'][1]['total_amount']===615.0,'Total sintetico inclui mensalidade 350 visita 35 e SSD 230');
    $snapshot=static function()use($p):string{$r=[];foreach(['tickets','ticket_materials','ticket_billing_installments','receivables'] as $t)$r[$t]=$p->query('SELECT * FROM '.$t.' ORDER BY id')->fetchAll();return json_encode($r);};
    $before=$snapshot();for($i=0;$i<3;$i++)recalculate_cycle($p,'2026-10-16','2026-11-15');$check($snapshot()===$before,'Reabrir e recalcular repetidamente nao duplica cobranca');
    $schedule=[['installment_number'=>1,'installment_count'=>1,'due_date'=>'2026-11-20','amount'=>230]];
    $p->exec("UPDATE receivables SET paid_amount=10,status='parcial' WHERE id=1166");$before=$snapshot();$failed=false;
    try{$p->beginTransaction();$p->exec('UPDATE tickets SET client_id=10 WHERE id=52');saveBillingSchedule($p,52,'materiais',$schedule);$p->commit();}catch(Throwable){if($p->inTransaction())$p->rollBack();$failed=true;}
    $check($failed&&$snapshot()===$before,'Conta com pagamento nao e transferida e toda edicao sofre rollback');
    $p->beginTransaction();saveBillingSchedule($p,52,'materiais',$schedule);assert_ticket_billing_ownership($p,52);$p->commit();$check($snapshot()===$before,'Cronograma igual e cliente correto preservam vinculo pago');
    $p->exec("UPDATE receivables SET paid_amount=0,status='pendente',closed_at='2026-11-01 10:00:00' WHERE id=1166");$before=$snapshot();$failed=false;
    try{$p->beginTransaction();$p->exec('UPDATE tickets SET client_id=10 WHERE id=52');saveBillingSchedule($p,52,'materiais',$schedule);$p->commit();}catch(Throwable){if($p->inTransaction())$p->rollBack();$failed=true;}
    $check($failed&&$snapshot()===$before,'Competencia encerrada nao e transferida');$p->exec('UPDATE receivables SET closed_at=NULL WHERE id=1166');
    $p->exec("INSERT INTO tickets(id,ticket_number,client_id,occurred_at,complainant,request_text,service_type,status) VALUES(53,'TESTE-03',10,'2026-11-06','Pessoa ficticia','Outro material','remoto','fechado');
    INSERT INTO ticket_billing_installments(ticket_id,charge_type,installment_number,installment_count,due_date,amount,receivable_id) VALUES(53,'materiais',1,1,'2026-11-20',50,354)");
    recalculate_cycle($p,'2026-10-16','2026-11-15');$p->exec('UPDATE ticket_billing_installments SET receivable_id=354 WHERE id=50');
    $p->beginTransaction();saveBillingSchedule($p,52,'materiais',$schedule);assert_ticket_billing_ownership($p,52);$p->commit();
    $check((float)$p->query('SELECT materials_amount FROM receivables WHERE id=354')->fetchColumn()===50.0,'Limpar vinculo inconsistente nao subtrai cobranca legitima de outro cliente');
    recalculate_cycle($p,'2026-10-16','2026-11-15');$check((float)$p->query('SELECT materials_amount FROM receivables WHERE id=354')->fetchColumn()===50.0&&(float)$p->query('SELECT materials_amount FROM receivables WHERE id=1166')->fetchColumn()===230.0,'Recalculo preserva materiais de ambos clientes apos reparar vinculo');
    $before=$snapshot();$failed=false;try{$p->beginTransaction();$p->exec('UPDATE ticket_billing_installments SET receivable_id=354 WHERE id=50');assert_ticket_billing_ownership($p,52);$p->commit();}catch(Throwable){if($p->inTransaction())$p->rollBack();$failed=true;}
    $check($failed&&$snapshot()===$before,'Conferencia final impede commit de vinculo cruzado');
    $p->beginTransaction();saveBillingSchedule($p,52,'materiais',[['installment_number'=>1,'installment_count'=>1,'due_date'=>'2026-11-20','amount'=>250]]);assert_ticket_billing_ownership($p,52);$p->commit();recalculate_cycle($p,'2026-10-16','2026-11-15');
    $check((int)$p->query('SELECT COUNT(*) FROM ticket_billing_installments WHERE ticket_id=52')->fetchColumn()===1&&(float)$p->query('SELECT materials_amount FROM receivables WHERE id=1166')->fetchColumn()===250.0,'Alteracao de valor continua funcionando sem duplicar parcelas');
    $p->exec("INSERT INTO receivables(id,client_id,competence,cycle_start,cycle_end,due_date,monthly_fee,total_amount) VALUES(2000,12,'2026-12','2026-11-16','2026-12-15','2026-12-20',350,350); UPDATE ticket_billing_installments SET receivable_id=2000 WHERE ticket_id=52");
    $p->beginTransaction();saveBillingSchedule($p,52,'materiais',[['installment_number'=>1,'installment_count'=>1,'due_date'=>'2026-11-20','amount'=>250]]);assert_ticket_billing_ownership($p,52);$p->commit();recalculate_cycle($p,'2026-10-16','2026-11-15');
    $check((int)$p->query('SELECT receivable_id FROM ticket_billing_installments WHERE ticket_id=52')->fetchColumn()===1166&&(float)$p->query('SELECT total_amount FROM receivables WHERE id=2000')->fetchColumn()===350.0,'Competencia incorreta e desvinculada sem alterar historico de outro mes');
    $repair=dirname(__DIR__,2).'/reparar-caso.php';
    if(is_file($repair)){
        $p->exec("UPDATE tickets SET ticket_number='261106-01',client_id=12 WHERE id=52; UPDATE ticket_billing_installments SET amount=230,receivable_id=354 WHERE ticket_id=52;
        UPDATE tickets SET client_id=12 WHERE id=53; UPDATE ticket_billing_installments SET amount=630,receivable_id=1166 WHERE ticket_id=53;
        INSERT INTO tickets(id,ticket_number,client_id,occurred_at,complainant,request_text,service_type,status) VALUES(54,'TESTE-04',12,'2026-11-03','Pessoa ficticia','Visita','presencial','fechado'),(55,'TESTE-05',12,'2026-11-04','Pessoa ficticia','Visita','presencial','fechado');
        UPDATE receivables SET materials_amount=0,extra_visits_amount=0,total_amount=350,paid_amount=0,closed_at=NULL,status='pendente' WHERE id=354;
        UPDATE receivables SET materials_amount=630,extra_visits_amount=105,total_amount=1085,paid_amount=0,closed_at=NULL,status='pendente' WHERE id=1166;
        UPDATE ticket_billing_installments SET id=50 WHERE ticket_id=52");
        $p->query('SELECT 1')->fetchColumn();
        $process=proc_open([PHP_BINARY,$repair,'--test'],[0=>['pipe','r'],1=>['file',getenv('HELPDESK_MATERIAL_MYSQL_TEST_FOLDER').'/reparo-out.log','a'],2=>['file',getenv('HELPDESK_MATERIAL_MYSQL_TEST_FOLDER').'/reparo-err.log','a']],$pipes,null,getenv());fclose($pipes[0]);$code=proc_close($process);
        $check($code===0,'Reparo especifico ensaiado em dados sinteticos');
        $check((float)$p->query('SELECT materials_amount FROM receivables WHERE id=1166')->fetchColumn()===860.0&&(float)$p->query('SELECT total_amount FROM receivables WHERE id=1166')->fetchColumn()===1315.0,'Reparo acrescenta exatamente R$230 e mantem visitas');
        $before=$snapshot();$process=proc_open([PHP_BINARY,$repair,'--test'],[0=>['pipe','r'],1=>['file',getenv('HELPDESK_MATERIAL_MYSQL_TEST_FOLDER').'/reparo-out.log','a'],2=>['file',getenv('HELPDESK_MATERIAL_MYSQL_TEST_FOLDER').'/reparo-err.log','a']],$pipes,null,getenv());fclose($pipes[0]);$code=proc_close($process);
        $check($code===0&&$before===$snapshot(),'Repetir reparo nao adiciona venda novamente');
        recalculate_cycle($p,'2026-10-16','2026-11-15');$check((float)$p->query('SELECT materials_amount FROM receivables WHERE id=1166')->fetchColumn()===860.0&&(float)$p->query('SELECT total_amount FROM receivables WHERE id=1166')->fetchColumn()===1315.0,'Recalculo real depois do reparo conserva resultado correto');
    }
    $report=['checks'=>$checks,'failures'=>$failures,'scope'=>'Funcoes reais de Chamados/Contas a Receber em MariaDB novo; somente dados ficticios.'];file_put_contents(getenv('HELPDESK_MATERIAL_MYSQL_TEST_FOLDER').'/resultado-correcao.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
}finally{$p->exec('SHUTDOWN');}
exit($failures?1:0);
