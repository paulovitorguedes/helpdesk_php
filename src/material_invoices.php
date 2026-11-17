<?php
declare(strict_types=1);

const MATERIAL_INVOICE_MAX_BYTES = 10485760;
const MATERIAL_INVOICE_PART_BYTES = 262144;

function material_invoice_upload_data(array $file): array
{
    $error=$file['error']??UPLOAD_ERR_NO_FILE;
    if(!is_int($error)||$error!==UPLOAD_ERR_OK){
        throw new InvalidArgumentException(in_array($error,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)
            ?'O PDF deve ter no máximo 10 MB.':'Selecione um PDF e aguarde o envio completo.');
    }
    $temporary=$file['tmp_name']??null;$name=$file['name']??null;
    if(!is_string($temporary)||!is_uploaded_file($temporary)||!is_string($name))throw new InvalidArgumentException('Arquivo de envio inválido.');
    $name=basename(str_replace('\\','/',$name));
    if(!mb_check_encoding($name,'UTF-8')||mb_strlen($name)>255||preg_match('/[\x00-\x1f\x7f]/',$name)
        ||strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf')throw new InvalidArgumentException('Selecione um arquivo com extensão PDF.');
    $size=filesize($temporary);
    if($size===false||$size<1||$size>MATERIAL_INVOICE_MAX_BYTES)throw new InvalidArgumentException('O PDF deve ter conteúdo e no máximo 10 MB.');
    $data=file_get_contents($temporary);
    if($data===false||strlen($data)!==$size)throw new RuntimeException('Não foi possível ler o PDF enviado.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    if($mime!=='application/pdf'||!str_starts_with($data,'%PDF-'))throw new InvalidArgumentException('O arquivo enviado não foi identificado como PDF.');
    return ['name'=>$name,'size'=>$size,'sha256'=>hash('sha256',$data),'data'=>$data];
}

function material_invoice_upload(PDO $pdo,int $purchaseId,array $file,string $token,int $authorId):array
{
    if($purchaseId<=0||$authorId<=0||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('Envio inválido. Reabra o histórico da compra.');
    $pdf=material_invoice_upload_data($file);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try{
        $statement=$pdo->prepare('SELECT material_id FROM material_purchases WHERE id=?');$statement->execute([$purchaseId]);
        $materialId=(int)$statement->fetchColumn();
        material_lock($pdo,$materialId);
        $statement=$pdo->prepare('SELECT id FROM material_purchases WHERE id=? AND material_id=? FOR UPDATE');$statement->execute([$purchaseId,$materialId]);
        if(!$statement->fetchColumn())throw new RuntimeException('A compra não está mais disponível.');
        $statement=$pdo->prepare('SELECT id,material_purchase_id,sha256,size_bytes FROM material_purchase_invoices WHERE request_token=?');$statement->execute([$token]);$existing=$statement->fetch(PDO::FETCH_ASSOC);
        if($existing){
            if((int)$existing['material_purchase_id']!==$purchaseId||!hash_equals($existing['sha256'],$pdf['sha256'])||(int)$existing['size_bytes']!==$pdf['size'])throw new RuntimeException('Este envio já foi utilizado para outro arquivo. Reabra o histórico antes de enviar.');
            $pdo->commit();return ['id'=>(int)$existing['id'],'material_id'=>$materialId];
        }
        $statement=$pdo->prepare('INSERT INTO material_purchase_invoices(material_purchase_id,original_name,size_bytes,sha256,request_token,uploaded_by) VALUES(?,?,?,?,?,?)');
        $statement->execute([$purchaseId,$pdf['name'],$pdf['size'],$pdf['sha256'],$token,$authorId]);$invoiceId=(int)$pdo->lastInsertId();
        $statement=$pdo->prepare('INSERT INTO material_purchase_invoice_parts(invoice_id,part_number,pdf_bytes) VALUES(?,?,?)');
        // Partes pequenas respeitam max_allowed_packet=1 MB e mantem o PDF na mesma transacao do historico.
        for($offset=0,$part=0;$offset<$pdf['size'];$offset+=MATERIAL_INVOICE_PART_BYTES,$part++){
            $statement->bindValue(1,$invoiceId,PDO::PARAM_INT);$statement->bindValue(2,$part,PDO::PARAM_INT);
            $statement->bindValue(3,substr($pdf['data'],$offset,MATERIAL_INVOICE_PART_BYTES),PDO::PARAM_LOB);$statement->execute();
        }
        $pdo->commit();return ['id'=>$invoiceId,'material_id'=>$materialId];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function material_invoice_read(PDO $pdo,int $invoiceId):?array
{
    if($invoiceId<=0)return null;
    $pdo->beginTransaction();
    try{
        $statement=$pdo->prepare('SELECT i.* FROM material_purchase_invoices i JOIN material_purchases p ON p.id=i.material_purchase_id JOIN materials m ON m.id=p.material_id WHERE i.id=? AND m.deleted_at IS NULL');
        $statement->execute([$invoiceId]);$invoice=$statement->fetch(PDO::FETCH_ASSOC);
        if(!$invoice){$pdo->commit();return null;}
        $statement=$pdo->prepare('SELECT part_number,pdf_bytes FROM material_purchase_invoice_parts WHERE invoice_id=? ORDER BY part_number');$statement->execute([$invoiceId]);
        $data='';$part=0;
        while($row=$statement->fetch(PDO::FETCH_ASSOC)){
            if((int)$row['part_number']!==$part++)throw new RuntimeException('O PDF precisa de conferência antes da consulta.');
            $bytes=is_resource($row['pdf_bytes'])?stream_get_contents($row['pdf_bytes']):$row['pdf_bytes'];
            if(!is_string($bytes)||strlen($bytes)>MATERIAL_INVOICE_PART_BYTES)throw new RuntimeException('O PDF precisa de conferência antes da consulta.');
            $data.=$bytes;
            if(strlen($data)>MATERIAL_INVOICE_MAX_BYTES)throw new RuntimeException('O PDF precisa de conferência antes da consulta.');
        }
        if(strlen($data)!==(int)$invoice['size_bytes']||!hash_equals($invoice['sha256'],hash('sha256',$data)))throw new RuntimeException('O PDF precisa de conferência antes da consulta.');
        $invoice['data']=$data;$pdo->commit();return $invoice;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function material_invoice_history(PDO $pdo,int $materialId):array
{
    $statement=$pdo->prepare('SELECT i.id,i.material_purchase_id,i.original_name,i.size_bytes,i.created_at,u.name AS author FROM material_purchase_invoices i JOIN material_purchases p ON p.id=i.material_purchase_id JOIN users u ON u.id=i.uploaded_by WHERE p.material_id=? ORDER BY i.id DESC');
    $statement->execute([$materialId]);$history=[];
    foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $invoice)$history[(int)$invoice['material_purchase_id']][]=$invoice;
    return $history;
}
