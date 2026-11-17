<?php
// Included only by the isolated CLI/HTTP test suite, after synthetic schema/session setup.
if(PHP_SAPI!=='cli'||!isset($pdo,$port,$cookies))exit(1);
function invoiceTestPdf(string $text='NF ficticia - data impressa 01/01/2000'):string {
    $text=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$text);$stream="BT /F1 12 Tf 50 750 Td ($text) Tj ET";
    $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>','<< /Length '.strlen($stream).' >>' . "\nstream\n".$stream."\nendstream",'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $index=>$object){$offsets[]=strlen($pdf);$pdf.=($index+1)." 0 obj\n".$object."\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";foreach(array_slice($offsets,1) as $offset)$pdf.=sprintf('%010d 00000 n ',$offset)."\n";return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}
function invoiceTestUpload(int $purchase,string $data,string $name='NF ficticia.pdf',?string $token=null,string $role='admin',string $csrf='material-test-csrf'):array {
    global $port,$cookies;
    $boundary='nf-'.bin2hex(random_bytes(16));$body='';
    foreach(['csrf'=>$csrf,'purchase_id'=>(string)$purchase,'request_token'=>$token??bin2hex(random_bytes(32))] as $key=>$value)$body.="--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
    $name=addcslashes($name,'"\\');$body.="--$boundary\r\nContent-Disposition: form-data; name=\"invoice_pdf\"; filename=\"$name\"\r\nContent-Type: application/pdf\r\n\r\n".$data."\r\n--$boundary--\r\n";
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>'Cookie: '.$cookies[$role]."\r\nAccept: application/json\r\nContent-Type: multipart/form-data; boundary=$boundary\r\n",'content'=>$body,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>30]]);
    $result=file_get_contents('http://127.0.0.1:'.$port.'/material-invoice.php',false,$context);preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$match);
    return ['status'=>(int)($match[1]??0),'body'=>(string)$result,'json'=>json_decode((string)$result,true)];
}
function invoiceTestCount():int {global $pdo;return (int)$pdo->query('SELECT COUNT(*) FROM material_purchase_invoices')->fetchColumn();}

$pdf=invoiceTestPdf();
file_put_contents($root.'/evidencias/NF-ficticia.pdf',$pdf);
postMaterial(['description'=>'Material NF ficticia','purchase_date'=>'2026-10-20','quantity'=>'2','total_cost'=>'90']);
$invoiceMaterial=(int)$pdo->query("SELECT id FROM materials WHERE description='Material NF ficticia'")->fetchColumn();
$invoicePurchase=(int)$pdo->query('SELECT id FROM material_purchases WHERE material_id='.$invoiceMaterial)->fetchColumn();
$original=inventorySnapshot();$token=bin2hex(random_bytes(32));
$upload=invoiceTestUpload($invoicePurchase,$pdf,'NF ficticia.PDF',$token,'financeiro');
checkMaterial($upload['status']===200&&($upload['json']['ok']??false),'Financeiro anexa PDF na compra sem comparar data impressa');
$invoiceId=(int)$pdo->query('SELECT MAX(id) FROM material_purchase_invoices')->fetchColumn();
checkMaterial($invoiceId>0&&inventorySnapshot()===$original,'Anexar PDF nao altera compra estoque ou financeiro');
$download=materialHttp('material-invoice.php?id='.$invoiceId);
checkMaterial($download['status']===200&&hash('sha256',$download['body'])===hash('sha256',$pdf),'Consulta devolve bytes originais do PDF');
checkMaterial((bool)array_filter($download['headers'],static fn($h)=>stripos($h,'Content-Type: application/pdf')===0),'Consulta usa tipo PDF');
checkMaterial((bool)array_filter($download['headers'],static fn($h)=>stripos($h,'X-Content-Type-Options: nosniff')===0),'Consulta impede inferencia de outro tipo');
checkMaterial(materialHttp('material-invoice.php?id='.$invoiceId,null,'tecnico')['status']===403,'Tecnico nao consulta PDF por URL direta');
$before=invoiceTestCount();invoiceTestUpload($invoicePurchase,$pdf,'NF ficticia.PDF',$token,'financeiro');checkMaterial(invoiceTestCount()===$before,'Repetir envio nao duplica anexo');
checkMaterial(invoiceTestUpload($invoicePurchase,invoiceTestPdf('Outro arquivo'),'NF.pdf',$token)['status']===409&&invoiceTestCount()===$before,'Token reutilizado com arquivo diferente recusado');
$second=invoiceTestUpload($invoicePurchase,invoiceTestPdf('Segunda NF'));checkMaterial($second['status']===200&&invoiceTestCount()===$before+1,'Novo PDF preserva primeira versao');
checkMaterial(materialHttp('material-invoice.php?id='.$invoiceId)['body']===$pdf,'Primeiro PDF continua consultavel apos novo envio');
$before=invoiceTestCount();
foreach([[$pdf,'NF.jpg'],['<html>arquivo falso</html>','NF.pdf'],['','NF.pdf']] as [$bytes,$name]){checkMaterial(invoiceTestUpload($invoicePurchase,$bytes,$name)['status']===422&&invoiceTestCount()===$before,'Arquivo invalido nao grava anexo: '.$name);}
checkMaterial(invoiceTestUpload($invoicePurchase,$pdf,'NF.pdf',null,'tecnico')['status']===403&&invoiceTestCount()===$before,'Tecnico nao envia PDF');
checkMaterial(invoiceTestUpload($invoicePurchase,$pdf,'NF.pdf',null,'admin','csrf-invalido')['status']===419&&invoiceTestCount()===$before,'Envio exige CSRF valido');
checkMaterial(invoiceTestUpload(99999999,$pdf)['status']===409&&invoiceTestCount()===$before,'Compra inexistente nao recebe PDF');
$oversized=$pdf.str_repeat(' ',MATERIAL_INVOICE_MAX_BYTES+1-strlen($pdf));checkMaterial(invoiceTestUpload($invoicePurchase,$oversized)['status']===422&&invoiceTestCount()===$before,'PDF acima de 10 MB recusado');unset($oversized);
$maximum=$pdf.str_repeat(' ',MATERIAL_INVOICE_MAX_BYTES-strlen($pdf));$maxUpload=invoiceTestUpload($invoicePurchase,$maximum);$maxId=(int)$pdo->query('SELECT MAX(id) FROM material_purchase_invoices')->fetchColumn();
checkMaterial($maxUpload['status']===200,'PDF de exatamente 10 MB aceito');
$parts=$pdo->query('SELECT MAX(LENGTH(pdf_bytes)) AS largest,COUNT(*) AS parts FROM material_purchase_invoice_parts WHERE invoice_id='.$maxId)->fetch();
checkMaterial((int)$parts['largest']<=262144&&(int)$parts['parts']===40,'Partes ficam abaixo do limite do servidor');
checkMaterial(hash('sha256',materialHttp('material-invoice.php?id='.$maxId)['body'])===hash('sha256',$maximum),'PDF de 10 MB reconstruido integralmente');unset($maximum);
$before=invoiceTestCount();$partCount=(int)$pdo->query('SELECT COUNT(*) FROM material_purchase_invoice_parts')->fetchColumn();
$pdo->exec($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
    ?"CREATE TRIGGER fail_invoice_part BEFORE INSERT ON material_purchase_invoice_parts FOR EACH ROW BEGIN IF NEW.part_number=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Falha simulada de parte'; END IF; END"
    :"CREATE TRIGGER fail_invoice_part BEFORE INSERT ON material_purchase_invoice_parts WHEN NEW.part_number=1 BEGIN SELECT RAISE(ABORT,'Falha simulada de parte'); END");
$failed=invoiceTestUpload($invoicePurchase,$pdf.str_repeat(' ',300000));
checkMaterial($failed['status']===500&&invoiceTestCount()===$before&&(int)$pdo->query('SELECT COUNT(*) FROM material_purchase_invoice_parts')->fetchColumn()===$partCount,'Falha em parte posterior desfaz metadados e partes anteriores');$pdo->exec('DROP TRIGGER fail_invoice_part');
checkMaterial(inventorySnapshot()===$original,'Envios recusados preservam dados de negocio');
$pdo->exec('UPDATE payables SET payment_history_locked=1 WHERE material_purchase_id='.$invoicePurchase);
$paidOriginal=inventorySnapshot();$before=invoiceTestCount();
checkMaterial(invoiceTestUpload($invoicePurchase,invoiceTestPdf('NF anexada apos pagamento'))['status']===200&&invoiceTestCount()===$before+1,'PDF pode ser anexado apos pagamento sem alterar valores');
checkMaterial(inventorySnapshot()===$paidOriginal,'Anexo apos pagamento preserva bloqueio e extrato');
postMaterial(['action'=>'delete','id'=>(string)$invoiceMaterial]);checkMaterial(invoiceTestCount()===$before+1&&inventorySnapshot()===$paidOriginal,'Pagamento protege material e todos os PDFs');
postMaterial(['description'=>'Material NF excluivel','purchase_date'=>'2026-10-20']);$deletable=(int)$pdo->query("SELECT id FROM materials WHERE description='Material NF excluivel'")->fetchColumn();$deletablePurchase=(int)$pdo->query('SELECT id FROM material_purchases WHERE material_id='.$deletable)->fetchColumn();
invoiceTestUpload($deletablePurchase,$pdf);$deletedInvoice=(int)$pdo->query('SELECT MAX(id) FROM material_purchase_invoices')->fetchColumn();postMaterial(['action'=>'delete','id'=>(string)$deletable]);
checkMaterial((int)$pdo->query('SELECT COUNT(*) FROM material_purchase_invoices WHERE id='.$deletedInvoice)->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM material_purchase_invoice_parts WHERE invoice_id='.$deletedInvoice)->fetchColumn()===0,'Exclusao elegivel apaga PDFs e partes na mesma transacao');
checkMaterial(materialHttp('material-invoice.php?id='.$deletedInvoice)['status']===404,'PDF de compra excluida fica indisponivel');
checkMaterial(materialHttp('material-invoice.php?id=99999999')['status']===404,'NF inexistente retorna 404');
$name='NF <img src=x onerror=alert(1)>.pdf';invoiceTestUpload($invoicePurchase,$pdf,$name);$history=materialHttp('materials.php?history='.$invoiceMaterial);
checkMaterial(str_contains($history['body'],htmlspecialchars($name,ENT_QUOTES,'UTF-8'))&&!str_contains($history['body'],$name),'Nome do arquivo escapado na consulta');
$savedPart=$pdo->query('SELECT pdf_bytes FROM material_purchase_invoice_parts WHERE invoice_id='.$invoiceId.' AND part_number=0')->fetchColumn();$pdo->exec("UPDATE material_purchase_invoice_parts SET pdf_bytes='conteudo alterado' WHERE invoice_id=$invoiceId AND part_number=0");
checkMaterial(materialHttp('material-invoice.php?id='.$invoiceId)['status']===500,'Falha de hash impede consulta de PDF corrompido');$restore=$pdo->prepare('UPDATE material_purchase_invoice_parts SET pdf_bytes=? WHERE invoice_id=? AND part_number=0');$restore->bindValue(1,$savedPart,PDO::PARAM_LOB);$restore->bindValue(2,$invoiceId,PDO::PARAM_INT);$restore->execute();
postMaterial(['description'=>'Material data padrao','purchase_date'=>'']);$defaultMaterial=(int)$pdo->query("SELECT id FROM materials WHERE description='Material data padrao'")->fetchColumn();
checkMaterial($pdo->query('SELECT purchase_date FROM material_purchases WHERE material_id='.$defaultMaterial)->fetchColumn()===date('Y-m-d'),'Compra inicial sem data recebe data atual');
postPurchase(['material_id'=>(string)$defaultMaterial,'purchase_date'=>'']);checkMaterial((int)$pdo->query('SELECT COUNT(*) FROM material_purchases WHERE material_id='.$defaultMaterial." AND purchase_date='".date('Y-m-d')."'")->fetchColumn()===2,'Nova compra sem data recebe data atual');
