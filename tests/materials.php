<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/material_rules.php';
$application=dirname(__DIR__);
$root=sys_get_temp_dir().'/helpdesk-materials-'.bin2hex(random_bytes(8));
foreach(['source/src','source/public','source/config','sessions','evidencias'] as $folder) {
    if(!mkdir($root.'/'.$folder,0700,true) && !is_dir($root.'/'.$folder)) throw new RuntimeException('Falha ao preparar teste.');
}
foreach(['src','public'] as $folder) {
    foreach(glob($application.'/'.$folder.'/*.php') as $file) {
        if(!copy($file,$root.'/source/'.$folder.'/'.basename($file))) throw new RuntimeException('Falha de copia.');
    }
}
// Nunca ler/copiar config/config.php ou conectar no servidor/banco reais.
file_put_contents($root.'/source/config/config.php',"<?php return ['app_name'=>'Teste de Materiais','base_url'=>''];\n");
$bootstrap=file_get_contents($root.'/source/src/bootstrap.php');
$bootstrap=preg_replace_callback('/^function db\(\): PDO\r?\n\{.*?^\}/ms',static fn()=> 'function db(): PDO { static $pdo=null; return $pdo ??= new MaterialTestPDO(); }',$bootstrap,1,$replacements);
if($replacements!==1) throw new RuntimeException('Falha ao isolar banco; abortando.');
file_put_contents($root.'/source/src/bootstrap.php',$bootstrap);
$database=$root.'/materials.sqlite';
$pdo=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys=ON');
$schema=<<<'SQL'
CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,role TEXT);
INSERT INTO users VALUES(999999,'Pessoa de teste','admin');
CREATE TABLE clients(id INTEGER PRIMARY KEY,name TEXT,has_contract INTEGER,due_day INTEGER,status TEXT,deleted_at TEXT);
INSERT INTO clients VALUES(1,'Cliente ficticio',0,20,'ativo',NULL);
CREATE TABLE materials(id INTEGER PRIMARY KEY AUTOINCREMENT,description TEXT NOT NULL,minimum_stock NUMERIC NOT NULL DEFAULT 0,notes TEXT,deleted_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE material_purchases(id INTEGER PRIMARY KEY AUTOINCREMENT,material_id INTEGER NOT NULL REFERENCES materials(id),purchase_date TEXT NOT NULL,supplier TEXT,quantity NUMERIC NOT NULL,total_cost NUMERIC NOT NULL,unit_cost NUMERIC NOT NULL,notes TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE tickets(id INTEGER PRIMARY KEY AUTOINCREMENT,ticket_number TEXT,occurred_at TEXT,client_id INTEGER,complainant TEXT,request_text TEXT,service_type TEXT,notes TEXT,status TEXT,solution_text TEXT,service_description TEXT,service_charged INTEGER DEFAULT 0,service_amount NUMERIC DEFAULT 0,materials_sold INTEGER DEFAULT 0,created_by INTEGER,deleted_at TEXT,receivable_id INTEGER,billed_at TEXT);
CREATE TABLE ticket_materials(id INTEGER PRIMARY KEY AUTOINCREMENT,ticket_id INTEGER REFERENCES tickets(id),material_id INTEGER REFERENCES materials(id),description TEXT,quantity NUMERIC,unit_price NUMERIC,total_price NUMERIC GENERATED ALWAYS AS(quantity*unit_price) STORED);
CREATE TABLE stock_movements(id INTEGER PRIMARY KEY AUTOINCREMENT,material_id INTEGER NOT NULL REFERENCES materials(id),movement_type TEXT NOT NULL,quantity NUMERIC NOT NULL,purchase_id INTEGER REFERENCES material_purchases(id),ticket_id INTEGER REFERENCES tickets(id),reference TEXT,notes TEXT,created_by INTEGER REFERENCES users(id),created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE ticket_daily_sequences(sequence_date TEXT PRIMARY KEY,last_number INTEGER);
CREATE TABLE receivables(id INTEGER PRIMARY KEY,monthly_fee NUMERIC,services_amount NUMERIC,materials_amount NUMERIC,extra_visits_amount NUMERIC,previous_balance NUMERIC,advances_amount NUMERIC,discount_amount NUMERIC,total_amount NUMERIC,paid_amount NUMERIC,status TEXT);
INSERT INTO receivables VALUES(1,500,0,0,0,0,0,0,500,500,'pago');
CREATE TABLE ticket_billing_installments(id INTEGER PRIMARY KEY AUTOINCREMENT,ticket_id INTEGER,charge_type TEXT,installment_number INTEGER,installment_count INTEGER,due_date TEXT,amount NUMERIC,receivable_id INTEGER);
CREATE TABLE payables(id INTEGER PRIMARY KEY,description TEXT,total_amount NUMERIC);
INSERT INTO payables VALUES(1,'Obrigacao ficticia preservada',100);
INSERT INTO materials(id,description,minimum_stock) VALUES(1,'Material A',1),(2,'Material B',0),(3,'Cadastro sem historico',0),(4,'Material com uso legado',0),(5,'Movimento sem compra legado',0);
INSERT INTO material_purchases(id,material_id,purchase_date,quantity,total_cost,unit_cost) VALUES(1,1,'2026-10-20',5,250,50),(2,2,'2026-10-10',2,20,10);
INSERT INTO stock_movements(material_id,movement_type,quantity,purchase_id,reference,created_by) VALUES(1,'entrada',5,1,'Compra ficticia',999999),(2,'entrada',2,2,'Compra ficticia',999999),(5,'ajuste',1,NULL,'Historico excepcional preservado',999999);
INSERT INTO tickets(id,ticket_number,occurred_at,client_id,status) VALUES(1,'LEGADO','2026-10-10',1,'fechado');
INSERT INTO ticket_materials(ticket_id,material_id,description,quantity,unit_price) VALUES(1,4,'Material com uso legado',1,0);
CREATE TRIGGER fail_stock BEFORE INSERT ON stock_movements WHEN NEW.notes='falha simulada' BEGIN SELECT RAISE(ABORT,'Falha simulada ao gravar movimento'); END;
SQL;
$pdo->exec($schema);
$financialBefore=json_encode([$pdo->query('SELECT * FROM receivables')->fetchAll(),$pdo->query('SELECT * FROM payables')->fetchAll()]);
session_save_path($root.'/sessions');
$cookies=[];
foreach(['admin','financeiro','tecnico'] as $role) {
    $id='materialtest'.bin2hex(random_bytes(12)); session_id($id); session_start();
    $_SESSION=['user'=>['id'=>999999,'name'=>'Pessoa de teste','email'=>'teste@example.invalid','role'=>$role],'csrf'=>'material-test-csrf'];
    session_write_close(); $cookies[$role]='PHPSESSID='.$id;
}
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if(!$socket) throw new RuntimeException('Porta de teste indisponivel.');
$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);
$environment=getenv();
$environment['HELPDESK_MATERIAL_TEST_DB']=$database;
$environment['HELPDESK_MATERIAL_TEST_SESSIONS']=$root.'/sessions';
$process=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$root.'/source/public',__DIR__.'/materials-router.php'],[0=>['pipe','r'],1=>['file',$root.'/evidencias/server.log','a'],2=>['file',$root.'/evidencias/server.log','a']],$pipes,$root,$environment,['bypass_shell'=>true,'create_new_console'=>false]);
if(!is_resource($process)) throw new RuntimeException('Servidor isolado nao iniciou.');
fclose($pipes[0]);
$checks=0;$failures=[];
function checkMaterial(bool $condition,string $label):void {global $checks,$failures;$checks++;if(!$condition)$failures[]=$label;}
function materialHttp(string $path,?array $post=null,string $role='admin'):array {
    global $port,$cookies;
    $headers='Cookie: '.$cookies[$role]."\r\n";
    if($post!==null)$headers.="Content-Type: application/x-www-form-urlencoded\r\n";
    $context=stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>$headers,'content'=>$post===null?null:http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>5]]);
    $body=file_get_contents('http://127.0.0.1:'.$port.'/'.$path,false,$context);
    preg_match('/^HTTP\/\S+\s+(\d+)/',$http_response_header[0]??'',$match);
    return ['status'=>(int)($match[1]??0),'body'=>(string)$body];
}
function inventorySnapshot():string {global $pdo;$result=[];foreach(['materials','material_purchases','stock_movements','tickets','ticket_materials','ticket_billing_installments','ticket_daily_sequences'] as $table)$result[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();return json_encode($result);}
function postMaterial(array $changes=[],string $role='admin'):array {
    return materialHttp('materials.php',array_replace(['csrf'=>'material-test-csrf','action'=>'save','id'=>'','description'=>'Material novo ficticio','minimum_stock'=>'0','purchase_date'=>'2026-10-20','supplier'=>'Fornecedor ficticio','quantity'=>'2','total_cost'=>'100','notes'=>''],$changes),$role);
}
function postPurchase(array $changes=[],string $role='admin'):array {
    return materialHttp('materials.php',array_replace(['csrf'=>'material-test-csrf','action'=>'purchase','material_id'=>'1','purchase_date'=>'2026-10-20','supplier'=>'Fornecedor ficticio','quantity'=>'2','total_cost'=>'100','purchase_notes'=>''],$changes),$role);
}
function postTicket(array $changes=[],string $role='admin'):array {
    return materialHttp('tickets.php',array_replace(['csrf'=>'material-test-csrf','id'=>'','client_id'=>'1','occurred_at'=>'2026-10-20','complainant'=>'Pessoa ficticia','request_text'=>'Solicitacao ficticia','service_type'=>'remoto','status'=>'aberto','service_amount'=>'0','materials'=>[['material_id'=>'1','quantity'=>'1','unit_price'=>'0']]],$changes),$role);
}
function stock(int $id):float {global $pdo;$stmt=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type='saida' THEN -quantity ELSE quantity END),0) FROM stock_movements WHERE material_id=?");$stmt->execute([$id]);return (float)$stmt->fetchColumn();}
function materialXPath(string $html):DOMXPath {
    $previous=libxml_use_internal_errors(true);$document=new DOMDocument();$document->loadHTML('<?xml encoding="UTF-8">'.$html);libxml_clear_errors();libxml_use_internal_errors($previous);return new DOMXPath($document);
}
try {
    $ready=false;
    for($i=0;$i<50;$i++){$connection=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,0.1);if($connection){fclose($connection);$ready=true;break;}usleep(100000);}
    if(!$ready)throw new RuntimeException('Servidor de teste nao ficou disponivel.');
    foreach(['1'=>1,'2.00'=>2,'2,00'=>2,'0003'=>3] as $raw=>$expected)checkMaterial(material_whole_quantity((string)$raw,'Quantidade')===$expected,'Inteiro valido '.$raw);
    foreach(['1.5','2,5','-1','0','1e3','1abc','99999999999',[]] as $invalid){try{material_whole_quantity($invalid,'Quantidade');checkMaterial(false,'Recusar quantidade invalida');}catch(InvalidArgumentException){checkMaterial(true,'Recusar quantidade invalida');}}
    checkMaterial(material_whole_quantity('0','Minimo',true)===0,'Minimo zero');
    foreach(['2026-02-30','2026-13-01','20/10/2026',''] as $invalid){try{material_purchase_date($invalid);checkMaterial(false,'Recusar data invalida');}catch(InvalidArgumentException){checkMaterial(true,'Recusar data invalida');}}
    $page=materialHttp('materials.php');checkMaterial($page['status']===200 && !str_contains($page['body'],'Fatal error'),'Tela de materiais abre');
    $pageXPath=materialXPath($page['body']);
    $integerStock=true;foreach($pageXPath->query('//table[@id="materialsTable"]/tbody/tr/td[5]') as $cell){if(str_contains(trim($cell->textContent),','))$integerStock=false;}
    checkMaterial($integerStock&&$pageXPath->query('//table[@id="materialsTable"]/tbody/tr/td[5]')->length>0,'Coluna de estoque sem casas decimais');
    checkMaterial($pageXPath->query('//input[@id="total_cost"]/../span[normalize-space(.)="R$"]')->length===1,'Compra inicial mostra prefixo R$');
    $purchasePage=materialXPath(materialHttp('materials.php?purchase=1')['body']);
    checkMaterial($purchasePage->query('//input[@id="total_cost"]/../span[normalize-space(.)="R$"]')->length===1,'Nova compra mostra prefixo R$');
    checkMaterial(material_quantity_display('1000.00')==='1.000'&&material_quantity_display('0.00')==='0','Inteiros exibidos sem zeros decimais');
    checkMaterial(material_quantity_display('2.50')==='2,5','Fracao inesperada nao e arredondada ou ocultada');
    checkMaterial(materialHttp('materials.php',null,'financeiro')['status']===200,'Financeiro acessa materiais');
    checkMaterial(materialHttp('materials.php',null,'tecnico')['status']===403,'Tecnico nao acessa materiais');
    foreach([['quantity'=>'2.5'],['quantity'=>'2,5'],['minimum_stock'=>'1.5'],['quantity'=>'0'],['purchase_date'=>'2026-02-30'],['quantity'=>'10000000000']] as $changes){$before=inventorySnapshot();postMaterial($changes);checkMaterial(inventorySnapshot()===$before,'Cadastro invalido nao altera material/compra/estoque: '.json_encode($changes));}
    $before=inventorySnapshot();postMaterial(['notes'=>'falha simulada']);checkMaterial(inventorySnapshot()===$before,'Falha na entrada desfaz cadastro e compra');
    postMaterial(['description'=>'Material gratuito','total_cost'=>'0','minimum_stock'=>'0'],'financeiro');
    $free=$pdo->query("SELECT id FROM materials WHERE description='Material gratuito'")->fetchColumn();checkMaterial($free!==false && stock((int)$free)===2.0,'Compra inicial inteira com custo zero');
    $before=inventorySnapshot();postPurchase(['purchase_date'=>'2026-10-19']);checkMaterial(inventorySnapshot()===$before,'Compra anterior a ultima do proprio material recusada');
    $startStock=stock(1);postPurchase();checkMaterial(stock(1)===$startStock+2,'Compra no mesmo dia permitida');
    postPurchase(['purchase_date'=>'2026-10-21']);checkMaterial(stock(1)===$startStock+4,'Compra posterior permitida');
    $bStock=stock(2);postPurchase(['material_id'=>'2','purchase_date'=>'2026-10-15']);checkMaterial(stock(2)===$bStock+2,'Data de outro material nao interfere');
    postPurchase(['material_id'=>'2','purchase_date'=>'2026-10-16','quantity'=>'2','total_cost'=>'1634.99']);
    $currencyPurchase=$pdo->query('SELECT total_cost,unit_cost FROM material_purchases WHERE material_id=2 ORDER BY id DESC LIMIT 1')->fetch();
    checkMaterial((float)$currencyPurchase['total_cost']===1634.99,'Prefixo visual nao altera total monetario salvo');
    checkMaterial(abs((float)$currencyPurchase['unit_cost']-817.495)<0.000001,'Calculo unitario preserva centavos do valor total');
    foreach([['quantity'=>'0.5'],['quantity'=>'-1'],['purchase_date'=>'2026-02-30'],['material_id'=>'9999999'],['purchase_notes'=>'falha simulada','purchase_date'=>'2026-10-22']] as $changes){$before=inventorySnapshot();postPurchase($changes);checkMaterial(inventorySnapshot()===$before,'Nova compra invalida/falha nao deixa registros parciais: '.json_encode($changes));}
    $before=inventorySnapshot();postMaterial(['id'=>'1','minimum_stock'=>'2.5']);checkMaterial(inventorySnapshot()===$before,'Edicao recusa minimo fracionado sem alterar compra');
    postMaterial(['id'=>'1','description'=>'Material A atualizado','minimum_stock'=>'2']);checkMaterial((int)$pdo->query('SELECT minimum_stock FROM materials WHERE id=1')->fetchColumn()===2,'Edicao cadastral com historico permitida');
    foreach([1,4,5] as $material){$before=inventorySnapshot();postMaterial(['action'=>'delete','id'=>(string)$material]);checkMaterial(inventorySnapshot()===$before,'Preservar material/historico '.$material);}
    postMaterial(['action'=>'delete','id'=>'3']);checkMaterial((int)$pdo->query('SELECT COUNT(*) FROM materials WHERE id=3')->fetchColumn()===0,'Cadastro sem historico pode ser excluido');
    $before=inventorySnapshot();postMaterial(['action'=>'delete','id'=>'3']);checkMaterial(inventorySnapshot()===$before,'Exclusao repetida nao afeta outros cadastros');
    $before=inventorySnapshot();postPurchase([], 'tecnico');checkMaterial(inventorySnapshot()===$before,'Tecnico nao compra por POST');
    $before=inventorySnapshot();postMaterial(['csrf'=>'invalido']);checkMaterial(inventorySnapshot()===$before,'CSRF invalido nao grava');
    $before=inventorySnapshot();postTicket(['materials'=>[['material_id'=>'1','quantity'=>'1.5','unit_price'=>'0']]]);checkMaterial(inventorySnapshot()===$before,'Chamado recusa venda fracionada sem gravar');
    $before=inventorySnapshot();postTicket(['materials'=>[['material_id'=>'1','quantity'=>'100000000','unit_price'=>'0']]]);checkMaterial(inventorySnapshot()===$before,'Limite da coluna de venda respeitado');
    $oldStock=stock(1);$result=postTicket([], 'tecnico');
    $newTicket=$pdo->query("SELECT MAX(id) FROM tickets WHERE ticket_number <> 'LEGADO'")->fetchColumn();
    checkMaterial($result['status']===302 && $newTicket && stock(1)===$oldStock-1,'Chamado aberto baixa unidade com preco zero');
    if($newTicket){
        $items=$pdo->query('SELECT * FROM ticket_materials WHERE ticket_id='.(int)$newTicket)->fetchAll();checkMaterial(count($items)===1 && (float)$items[0]['unit_price']===0.0,'Preco de venda zero preservado');
        $change=['id'=>(string)$newTicket,'materials'=>[['material_id'=>'1','quantity'=>'2','unit_price'=>'0']]];
        postTicket($change);checkMaterial(stock(1)===$oldStock-2,'Edicao ajusta estoque em unidades inteiras');
        $movementCount=(int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();postTicket($change);checkMaterial((int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn()===$movementCount,'Repetir edicao nao duplica baixa');
        postTicket(array_replace($change,['status'=>'cancelado']));checkMaterial(stock(1)===$oldStock,'Cancelamento devolve unidades inteiras');
        $movementCount=(int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();postTicket(array_replace($change,['status'=>'cancelado']));checkMaterial((int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn()===$movementCount,'Repetir cancelamento nao duplica devolucao');
    }
    $before=inventorySnapshot();postTicket(['materials'=>[['material_id'=>'1','quantity'=>'999','unit_price'=>'0']]]);checkMaterial(inventorySnapshot()===$before,'Estoque insuficiente nao cria chamado/baixa/parcela');
    checkMaterial((int)$pdo->query('SELECT COUNT(*) FROM stock_movements WHERE quantity <> CAST(quantity AS INTEGER)')->fetchColumn()===0,'Movimentos novos e ajustes sao inteiros');
    checkMaterial(json_encode([$pdo->query('SELECT * FROM receivables')->fetchAll(),$pdo->query('SELECT * FROM payables')->fetchAll()])===$financialBefore,'Recebiveis e pagaveis preexistentes intactos nesta subentrega');
    $report=['checks'=>$checks,'failures'=>$failures,'test_directory'=>$root,'scope'=>'Paginas reais via HTTP; SQLite sintetico; sem configuracao ou banco reais. FOR UPDATE removido somente pelo adaptador SQLite de teste; concorrencia MariaDB nao validada por esta suite.'];
    $evidence=$argv[1]??($root.'/evidencias');if(!is_dir($evidence))throw new RuntimeException('Pasta de evidencia inexistente.');
    file_put_contents($evidence.'/resultado-materiais.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
} finally {proc_terminate($process);proc_close($process);}
exit($failures?1:0);
