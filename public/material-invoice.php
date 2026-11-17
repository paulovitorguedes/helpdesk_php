<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/material_rules.php';
require_once __DIR__.'/../src/material_invoices.php';
require_page_access('materials.php');

if($_SERVER['REQUEST_METHOD']==='POST'){
    $json=str_contains($_SERVER['HTTP_ACCEPT']??'','application/json');
    $reply=static function(int $status,string $message,?string $redirect=null)use($json):never{
        if($json){http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>$status===200,'message'=>$message,'redirect'=>$redirect]);exit;}
        flash($status===200?'success':'warning',$message);redirect($redirect??base_url('materials.php'));
    };
    if((int)($_SERVER['CONTENT_LENGTH']??0)>MATERIAL_INVOICE_MAX_BYTES+65536)$reply(413,'O PDF deve ter no máximo 10 MB.');
    csrf_check();
    try{
        $file=$_FILES['invoice_pdf']??[];$token=$_POST['request_token']??'';
        if(!is_array($file)||!is_string($token))throw new InvalidArgumentException('Arquivo de envio inválido.');
        $result=material_invoice_upload(db(),(int)($_POST['purchase_id']??0),$file,$token,(int)auth_user()['id']);
        $reply(200,'PDF da NF anexado. Os arquivos anteriores foram preservados.',base_url('materials.php?history='.$result['material_id']));
    }catch(InvalidArgumentException $error){$reply(422,$error->getMessage());}
    catch(RuntimeException $error){if($error instanceof PDOException){error_log($error->getMessage());$reply(500,'Não foi possível concluir o envio. Nenhum anexo parcial foi mantido.');}$reply(409,$error->getMessage());}
    catch(Throwable $error){error_log($error->getMessage());$reply(500,'Não foi possível concluir o envio. Nenhum anexo parcial foi mantido.');}
}
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET, POST');exit;}
try{$invoice=material_invoice_read(db(),(int)($_GET['id']??0));}
catch(Throwable $error){error_log($error->getMessage());http_response_code(500);exit('Não foi possível consultar o PDF. O arquivo precisa de conferência.');}
if(!$invoice){http_response_code(404);exit('PDF não encontrado.');}
header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store');
header('Pragma: no-cache');
header('Content-Length: '.strlen($invoice['data']));
$disposition=isset($_GET['download'])?'attachment':'inline';
header('Content-Disposition: '.$disposition.'; filename="NF-compra-'.(int)$invoice['material_purchase_id'].'.pdf"; filename*=UTF-8\'\''.rawurlencode($invoice['original_name']));
echo $invoice['data'];
