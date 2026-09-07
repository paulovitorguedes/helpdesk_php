<?php
require_once __DIR__ . '/../src/layout.php'; require_auth();
$year=(int)($_GET['year']??date('Y'));
$pdo=db();
$k=[];
$k['ativos']=(int)$pdo->query("SELECT COUNT(*) FROM clients WHERE status='ativo' AND deleted_at IS NULL")->fetchColumn();
$k['inativos']=(int)$pdo->query("SELECT COUNT(*) FROM clients WHERE status='inativo' AND deleted_at IS NULL")->fetchColumn();
foreach(['aberto','pendente','solucionado','fechado'] as $s){$st=$pdo->prepare('SELECT COUNT(*) FROM tickets WHERE status=?');$st->execute([$s]);$k[$s]=(int)$st->fetchColumn();}
$st=$pdo->prepare("SELECT COALESCE(SUM(total_amount),0),COALESCE(SUM(paid_amount),0) FROM receivables WHERE YEAR(due_date)=? AND MONTH(due_date)=?");$st->execute([$year,(int)date('m')]);[$prev,$recv]=$st->fetch(PDO::FETCH_NUM);
$st=$pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM payables WHERE YEAR(due_date)=? AND MONTH(due_date)=?");$st->execute([$year,(int)date('m')]);$desp=(float)$st->fetchColumn();
$rows=$pdo->prepare("SELECT MONTH(due_date) m,COALESCE(SUM(total_amount),0) bruto,COALESCE(SUM(paid_amount),0) recebido FROM receivables WHERE YEAR(due_date)=? GROUP BY MONTH(due_date)");$rows->execute([$year]);$fin=[];foreach($rows as $r)$fin[(int)$r['m']]=$r;
$er=$pdo->prepare("SELECT MONTH(due_date) m,COALESCE(SUM(total_amount),0) desp FROM payables WHERE YEAR(due_date)=? GROUP BY MONTH(due_date)");$er->execute([$year]);$expenses=[];foreach($er as $r)$expenses[(int)$r['m']]=$r['desp'];
$att=$pdo->prepare("SELECT c.name,MONTH(t.occurred_at) m,COUNT(*) total FROM tickets t JOIN clients c ON c.id=t.client_id WHERE YEAR(t.occurred_at)=? GROUP BY c.id,c.name,MONTH(t.occurred_at) ORDER BY c.name");$att->execute([$year]);$attMap=[];foreach($att as $r){$attMap[$r['name']][(int)$r['m']]=$r['total'];}
render_header('Dashboard Gerencial');
$months=['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0">Visão geral</h4><form><select class="form-select form-select-sm" name="year" onchange="this.form.submit()"><?php for($y=date('Y')-3;$y<=date('Y')+1;$y++):?><option <?=$y==$year?'selected':''?>><?=$y?></option><?php endfor;?></select></form></div>
<div class="row g-3 mb-4">
<?php foreach([['Clientes ativos',$k['ativos']],['Clientes inativos',$k['inativos']],['Chamados abertos',$k['aberto']],['Pendentes',$k['pendente']],['Receita prevista',money($prev)],['Receita recebida',money($recv)],['Despesas do mês',money($desp)],['Lucro do mês',money((float)$recv-$desp)]] as [$l,$v]):?><div class="col-6 col-lg-3"><div class="card card-kpi"><div class="card-body"><div class="text-body-secondary small"><?=e($l)?></div><div class="fs-4 fw-semibold"><?=e((string)$v)?></div></div></div></div><?php endforeach;?>
</div>
<div class="table-wrap mb-4"><h5>Painel Financeiro Anual</h5><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th></th><?php foreach($months as $m):?><th><?=$m?></th><?php endforeach;?><th>Total</th></tr></thead><tbody>
<?php foreach(['Bruto'=>'bruto','Recebido'=>'recebido','Despesas'=>'desp','Líquido'=>'liquido'] as $label=>$type): $tot=0;?><tr><th><?=$label?></th><?php for($m=1;$m<=12;$m++): $br=(float)($fin[$m]['bruto']??0);$re=(float)($fin[$m]['recebido']??0);$de=(float)($expenses[$m]??0);$v=$type==='bruto'?$br:($type==='recebido'?$re:($type==='desp'?$de:$re-$de));$tot+=$v;?><td class="money"><?=money($v)?></td><?php endfor;?><th class="money"><?=money($tot)?></th></tr><?php endforeach;?></tbody></table></div></div>
<div class="card mb-4"><div class="card-body"><canvas id="financeChart" height="90"></canvas></div></div>
<div class="table-wrap"><h5>Atendimentos por Cliente</h5><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Cliente</th><?php foreach($months as $m):?><th><?=$m?></th><?php endforeach;?><th>Total</th></tr></thead><tbody><?php foreach($attMap as $client=>$ms):$tot=0;?><tr><td><?=e($client)?></td><?php for($m=1;$m<=12;$m++):$v=(int)($ms[$m]??0);$tot+=$v;?><td><?=$v?></td><?php endfor;?><th><?=$tot?></th></tr><?php endforeach;?></tbody></table></div></div>
<script>document.addEventListener('DOMContentLoaded',()=>{new Chart(document.getElementById('financeChart'),{type:'bar',data:{labels:<?=json_encode($months)?>,datasets:[{label:'Bruto',data:<?=json_encode(array_map(fn($m)=>(float)($fin[$m]['bruto']??0),range(1,12)))?>},{label:'Recebido',data:<?=json_encode(array_map(fn($m)=>(float)($fin[$m]['recebido']??0),range(1,12)))?>},{label:'Despesas',data:<?=json_encode(array_map(fn($m)=>(float)($expenses[$m]??0),range(1,12)))?>}]},options:{responsive:true,interaction:{mode:'index',intersect:false}}})})</script>
<?php render_footer(); ?>
