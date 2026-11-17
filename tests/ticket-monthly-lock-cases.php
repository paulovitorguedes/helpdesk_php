<?php
if(PHP_SAPI!=='cli'||!isset($pdo,$port,$cookies))exit(1);
require_once dirname(__DIR__).'/src/ticket_monthly_lock.php';
$caseNumber=0;
function monthlyFixture(string $competence='2026-09',bool $linked=true,string $status='fechado'):array {
    global $pdo,$caseNumber;$caseNumber++;
    $pdo->exec("INSERT INTO materials(description,minimum_stock) VALUES('Material mensal ficticio $caseNumber',0)");$material=(int)$pdo->lastInsertId();$author=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?1:999999;
    $statement=$pdo->prepare("INSERT INTO tickets(ticket_number,client_id,occurred_at,complainant,request_text,service_type,status,solution_text,service_charged,service_amount,materials_sold) VALUES(?,1,?,'Pessoa ficticia','Solicitacao original','remoto',?,'Solucao original',0,0,1)");
    $statement->execute(['MENSAL-'.$caseNumber,$competence.'-10 10:00:00',$status]);$ticket=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO ticket_materials(ticket_id,material_id,description,quantity,unit_price) VALUES($ticket,$material,'Material mensal ficticio',1,100)");
    $pdo->exec("INSERT INTO stock_movements(material_id,movement_type,quantity,ticket_id,created_by) VALUES($material,'entrada',2,NULL,$author),($material,'saida',1,$ticket,$author)");
    $statement=$pdo->prepare("INSERT INTO receivables(client_id,competence,cycle_start,cycle_end,due_date,materials_amount,total_amount,paid_amount,status) VALUES(1,?,?,?,?,100,100,0,'pendente')");
    $statement->execute([$competence,$competence.'-01',$competence.'-28',$competence.'-20']);$account=(int)$pdo->lastInsertId();
    if($linked){$statement=$pdo->prepare("INSERT INTO ticket_billing_installments(ticket_id,charge_type,installment_number,installment_count,due_date,amount,receivable_id) VALUES(?,'materiais',1,1,?,100,?)");$statement->execute([$ticket,$competence.'-20',$account]);}
    return ['ticket'=>$ticket,'material'=>$material,'account'=>$account];
}
function monthlyBusinessSnapshot(int $ticket):string {
    global $pdo;$result=[];
    foreach(['stock_movements','ticket_materials','ticket_billing_installments','receivables','payables','material_purchases'] as $table)$result[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();
    $row=$pdo->query('SELECT * FROM tickets WHERE id='.$ticket)->fetch();unset($row['complainant'],$row['request_text'],$row['solution_text']);$result['ticket']=$row;
    return serialize($result);
}

$past=monthlyFixture();$dayBefore=new DateTimeImmutable('2026-09-30');$dayOne=new DateTimeImmutable('2026-10-01');
checkMaterial(ticket_monthly_lock_reason($pdo,$past['ticket'],$dayBefore)===null,'Competencia vigente continua editavel ate ultimo dia');
checkMaterial(ticket_monthly_lock_reason($pdo,$past['ticket'],$dayOne)!==null,'Primeiro dia do mes seguinte bloqueia competencia anterior');
checkMaterial(ticket_monthly_lock_reason($pdo,$past['ticket'],new DateTimeImmutable('2026-10-05'))!==null,'Primeiro acesso tardio aplica bloqueio desde a virada');
checkMaterial(ticket_monthly_lock_reason($pdo,$past['ticket'],new DateTimeImmutable('2027-01-01'))!==null,'Bloqueio permanece apos varios meses sem acesso');
$current=monthlyFixture('2026-10');
checkMaterial(ticket_monthly_lock_reason($pdo,$current['ticket'],new DateTimeImmutable('2026-10-21'))===null,'Competencia atual sem encerramento nao e bloqueada pela trava mensal');
$pdo->exec('UPDATE receivables SET paid_amount=40,status=\'parcial\' WHERE id='.$current['account']);
checkMaterial(ticket_monthly_lock_reason($pdo,$current['ticket'],new DateTimeImmutable('2026-10-21'))===null,'A nova trava mensal nao classifica parcial atual como virada mensal');
$pdo->exec('UPDATE receivables SET paid_amount=0,status=\'pendente\' WHERE id='.$current['account']);
$pdo->exec("UPDATE receivables SET closed_at='2026-10-20 10:00:00' WHERE id=".$current['account']);
checkMaterial(ticket_monthly_lock_reason($pdo,$current['ticket'],new DateTimeImmutable('2026-10-21'))!==null,'Encerramento persistido tambem e respeitado');
$pdo->exec('UPDATE receivables SET closed_at=NULL WHERE id='.$current['account']);
$unlinked=monthlyFixture('2026-08');$pdo->exec('UPDATE ticket_billing_installments SET receivable_id=NULL WHERE ticket_id='.$unlinked['ticket']);
checkMaterial(ticket_monthly_lock_reason($pdo,$unlinked['ticket'],$dayOne)!==null,'Parcela sem conta vinculada preserva competencia ja registrada no vencimento');
$future=monthlyFixture('2026-12');
checkMaterial(ticket_monthly_lock_reason($pdo,$future['ticket'],new DateTimeImmutable('2026-10-21'))===null,'Competencia futura nao sofre bloqueio por abertura antiga');
$noInstallment=monthlyFixture('2026-07',false);
checkMaterial(ticket_monthly_lock_reason($pdo,$noInstallment['ticket'],$dayOne)!==null,'Sem parcela propria usa ciclo financeiro existente do cliente');
$before=monthlyBusinessSnapshot($past['ticket']);
foreach(['admin','tecnico'] as $role){
    $response=postTicket(['id'=>(string)$past['ticket'],'occurred_at'=>'2026-12-10','status'=>'cancelado','request_text'=>'Texto alterado '.$role,'solution_text'=>'Solucao alterada','complainant'=>'Reclamante alterado','materials'=>[]],$role);
    checkMaterial($response['status']===302&&monthlyBusinessSnapshot($past['ticket'])===$before,'Competencia anterior preserva status/data/estoque/financeiro: '.$role);
    $text=$pdo->query('SELECT complainant,request_text,solution_text FROM tickets WHERE id='.$past['ticket'])->fetch();
    checkMaterial($text['request_text']==='Texto alterado '.$role&&$text['solution_text']==='Solucao alterada'&&$text['complainant']==='Reclamante alterado','Tres campos textuais continuam editaveis: '.$role);
}
$page=materialHttp('tickets.php?edit='.$past['ticket']);$xp=materialXPath($page['body']);
checkMaterial($page['status']===200&&$xp->query('//form[@data-ticket-edit-locked]')->length===1&&str_contains($page['body'],'virada mensal'),'Tela mostra bloqueio mensal e marca somente o formulario de edicao');
checkMaterial(materialHttp('tickets.php',['csrf'=>'material-test-csrf','id'=>(string)$past['ticket'],'request_text'=>'Somente texto'],'financeiro')['status']===403,'Financeiro nao ganha acesso geral a Chamados com a trava');
$saved=$pdo->query('SELECT request_text FROM tickets WHERE id='.$past['ticket'])->fetchColumn();
materialHttp('tickets.php',['csrf'=>'invalido','id'=>(string)$past['ticket'],'request_text'=>'Nao salvar']);
checkMaterial($pdo->query('SELECT request_text FROM tickets WHERE id='.$past['ticket'])->fetchColumn()===$saved,'Excecao textual continua exigindo CSRF');
materialHttp('tickets.php',['csrf'=>'material-test-csrf','id'=>(string)$past['ticket'],'request_text'=>'']);
checkMaterial($pdo->query('SELECT request_text FROM tickets WHERE id='.$past['ticket'])->fetchColumn()===$saved&&monthlyBusinessSnapshot($past['ticket'])===$before,'Solicitacao vazia nao altera textos ou negocio');
materialHttp('tickets.php',['csrf'=>'material-test-csrf','id'=>(string)$past['ticket'],'request_text'=>'Texto sem campos financeiros']);
checkMaterial($pdo->query('SELECT request_text FROM tickets WHERE id='.$past['ticket'])->fetchColumn()==='Texto sem campos financeiros'&&monthlyBusinessSnapshot($past['ticket'])===$before,'Edicao bloqueada aceita formulario contendo somente textos autorizados');
$pdo->exec('UPDATE receivables SET paid_amount=100,status=\'pago\' WHERE id='.$current['account']);$paidBefore=monthlyBusinessSnapshot($current['ticket']);
materialHttp('tickets.php',['csrf'=>'material-test-csrf','id'=>(string)$current['ticket'],'request_text'=>'Texto pago','status'=>'cancelado']);
checkMaterial($pdo->query('SELECT request_text FROM tickets WHERE id='.$current['ticket'])->fetchColumn()==='Texto pago'&&monthlyBusinessSnapshot($current['ticket'])===$paidBefore,'Quitacao mantem protecao e salva somente texto sem recriar parcelas');
// Cycle ambiguity must not silently assign a historical competence.
$pdo->exec("INSERT INTO receivables(client_id,competence,cycle_start,cycle_end,due_date,total_amount,paid_amount,status) VALUES(1,'2026-06','2026-07-01','2026-07-28','2026-06-20',0,0,'pendente')");
checkMaterial(str_contains(ticket_monthly_lock_reason($pdo,$noInstallment['ticket'],$dayOne)??'','conferência'),'Ciclos sobrepostos exigem conferencia sem atribuir competencia');
$pdo->exec('UPDATE tickets SET status=\'pendente\' WHERE id='.$noInstallment['ticket']);
checkMaterial(ticket_monthly_lock_reason($pdo,$noInstallment['ticket'],$dayOne)===null,'Chamado pendente sem competencia faturada nao recebe competencia por data de abertura');
$draft=monthlyFixture('2026-05',true,'pendente');$pdo->exec('UPDATE ticket_billing_installments SET receivable_id=NULL WHERE ticket_id='.$draft['ticket']);
checkMaterial(ticket_monthly_lock_reason($pdo,$draft['ticket'],$dayOne)===null,'Parcelas previstas de chamado pendente sem faturamento nao causam bloqueio mensal');
$snapshot=monthlyBusinessSnapshot($past['ticket']);
try{$pdo->beginTransaction();ticket_save_locked_text($pdo,$past['ticket'],['request_text'=>'Desfazer']);throw new RuntimeException('falha simulada');}catch(Throwable){$pdo->rollBack();}
checkMaterial(monthlyBusinessSnapshot($past['ticket'])===$snapshot&&$pdo->query('SELECT request_text FROM tickets WHERE id='.$past['ticket'])->fetchColumn()==='Texto sem campos financeiros','Rollback da excecao textual preserva historico e texto');
$nullTexts=monthlyFixture('2026-04');$pdo->exec('UPDATE tickets SET complainant=NULL,solution_text=NULL WHERE id='.$nullTexts['ticket']);
materialHttp('tickets.php',['csrf'=>'material-test-csrf','id'=>(string)$nullTexts['ticket'],'request_text'=>'Texto com campos omitidos']);
$nullRow=$pdo->query('SELECT complainant,solution_text,request_text FROM tickets WHERE id='.$nullTexts['ticket'])->fetch();
checkMaterial($nullRow['complainant']===null&&$nullRow['solution_text']===null&&$nullRow['request_text']==='Texto com campos omitidos','Campos textuais omitidos preservam NULL legado sem normalizacao silenciosa');
