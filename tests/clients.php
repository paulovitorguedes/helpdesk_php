<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/client_form.php';

$application = dirname(__DIR__);
$root = sys_get_temp_dir() . '/helpdesk-clients-' . bin2hex(random_bytes(8));
foreach (['source/src', 'source/public/assets/js', 'source/public/assets/css', 'source/config', 'sessions', 'evidencias'] as $folder) {
    if (!mkdir($root . '/' . $folder, 0700, true) && !is_dir($root . '/' . $folder)) throw new RuntimeException('Falha ao preparar teste.');
}
foreach (['src', 'public'] as $folder) {
    foreach (glob($application . '/' . $folder . '/*.php') as $file) {
        if (!copy($file, $root . '/source/' . $folder . '/' . basename($file))) throw new RuntimeException('Falha de copia.');
    }
}
foreach (['js', 'css'] as $folder) {
    foreach (glob($application . '/public/assets/' . $folder . '/*') as $file) {
        if (is_file($file)) copy($file, $root . '/source/public/assets/' . $folder . '/' . basename($file));
    }
}
file_put_contents($root . '/source/config/config.php', "<?php return ['app_name'=>'Teste de Clientes','base_url'=>''];\n");
$bootstrap = file_get_contents($root . '/source/src/bootstrap.php');
$sqliteFunction = <<<'FUNCTION'
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO('sqlite:' . getenv('HELPDESK_CLIENT_TEST_DB'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}
FUNCTION;
$bootstrap = preg_replace_callback('/^function db\(\): PDO\r?\n\{.*?^\}/ms', fn() => $sqliteFunction, $bootstrap, 1, $replacements);
if ($replacements !== 1) throw new RuntimeException('Nao foi possivel isolar acesso ao banco; teste interrompido.');
file_put_contents($root . '/source/src/bootstrap.php', $bootstrap);
$database = $root . '/clients.sqlite';
$pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, document TEXT, phone TEXT, email TEXT, address TEXT, status TEXT NOT NULL DEFAULT 'ativo', standard_km NUMERIC DEFAULT 0, has_contract INTEGER DEFAULT 0, monthly_fee NUMERIC DEFAULT 0, extra_visit_fee NUMERIC DEFAULT 0, due_day INTEGER NOT NULL DEFAULT 20, contract_notes TEXT, notes TEXT, deleted_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE receivables (id INTEGER PRIMARY KEY, client_id INTEGER, competence TEXT, total_amount NUMERIC, paid_amount NUMERIC)");
$seed = $pdo->prepare('INSERT INTO clients (name,address,status,has_contract,monthly_fee,extra_visit_fee,due_day,contract_notes) VALUES (?,?,?,?,?,?,?,?)');
$seed->execute(['Cliente com contrato', 'Rua Teste, 10', 'ativo', 1, 500, 100, 20, 'Preservar condições anteriores']);
$seed->execute(['Cliente sem contrato legado', 'Rua Teste, 20', 'ativo', 0, 0, 0, 5, 'Observação preservada']);
$pdo->exec("INSERT INTO receivables VALUES(1,1,'2026-10',500,500)");
$financialBefore = json_encode($pdo->query('SELECT * FROM receivables')->fetchAll());

session_save_path($root . '/sessions');
$cookies = [];
foreach (['admin','financeiro','tecnico'] as $role) {
    $id = 'clienttest' . bin2hex(random_bytes(12));
    session_id($id); session_start();
    $_SESSION = ['user'=>['id'=>999999,'name'=>'Pessoa de teste','email'=>'teste@example.invalid','role'=>$role], 'csrf'=>'client-test-csrf'];
    session_write_close();
    $cookies[$role] = 'PHPSESSID=' . $id;
}
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Nao foi possivel reservar porta.');
$address = stream_socket_get_name($socket, false); fclose($socket);
$port = (int)substr(strrchr($address, ':'), 1);
$environment = getenv();
$environment['HELPDESK_CLIENT_TEST_SOURCE'] = $root . '/source';
$environment['HELPDESK_CLIENT_TEST_SESSIONS'] = $root . '/sessions';
$environment['HELPDESK_CLIENT_TEST_DB'] = $database;
$process = proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$root.'/source/public',__DIR__.'/clients-router.php'],
    [0=>['pipe','r'],1=>['file',$root.'/evidencias/server.log','a'],2=>['file',$root.'/evidencias/server.log','a']],
    $pipes, $root, $environment, ['bypass_shell'=>true,'create_new_console'=>false]);
if (!is_resource($process)) throw new RuntimeException('Nao foi possivel iniciar servidor.');
fclose($pipes[0]);
$checks = 0; $failures = [];
function check(bool $ok, string $label): void { global $checks,$failures; $checks++; if (!$ok) $failures[]=$label; }
function http(string $path, ?array $post=null, string $role='admin'): array {
    global $port,$cookies;
    $headers='Cookie: '.$cookies[$role]."\r\n";
    if ($post!==null) $headers.="Content-Type: application/x-www-form-urlencoded\r\n";
    $context=stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>$headers,
        'content'=>$post===null?null:http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>5]]);
    $body=file_get_contents('http://127.0.0.1:'.$port.'/'.$path,false,$context);
    preg_match('/^HTTP\/\S+\s+(\d+)/',$http_response_header[0]??'',$match);
    return ['status'=>(int)($match[1]??0),'body'=>(string)$body];
}
function clientCount(): int { global $pdo; return (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn(); }
function postClient(array $changes=[],string $role='admin'): array {
    return http('clients.php',array_replace(['csrf'=>'client-test-csrf','id'=>'','name'=>'Novo cliente de teste',
        'document'=>'','phone'=>'','email'=>'','address'=>'Rua de Teste, 123','status'=>'ativo','standard_km'=>'12,5',
        'monthly_fee'=>'0,00','extra_visit_fee'=>'0,00','due_day'=>'','contract_notes'=>'','notes'=>''],$changes),$role);
}

try {
    $ready=false;
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,0.1);
        if($connection){fclose($connection);$ready=true;break;} usleep(100000); }
    if(!$ready) throw new RuntimeException('Servidor nao ficou disponivel.');
    if (($argv[1]??'')==='--serve') {
        echo json_encode(['preview_url'=>'http://127.0.0.1:'.$port.'/__test_login','test_directory'=>$root,
            'stop_file'=>$root.'/stop','database'=>'SQLite ficticio; nenhum dado de producao'])."\n";
        flush();
        $deadline=time()+1800;
        while(!file_exists($root.'/stop') && time()<$deadline) usleep(200000);
    } else {
        foreach (['1.234,56'=>'1234.56','R$ 0,00'=>'0.00','0'=>'0.00','500.25'=>'500.25','1.234'=>'1234.00'] as $raw=>$expected) {
            check(client_form_decimal((string)$raw,10,true)===$expected,'Moeda: '.$raw);
        }
        foreach (['-1','1,234','12,34,56','abc','10000000000,00'] as $invalid) {
            try {client_form_decimal($invalid,10,true);check(false,'Recusar moeda '.$invalid);} catch(InvalidArgumentException $error){check(true,'Recusar moeda '.$invalid);}
        }
        check(client_form_decimal('12,5',8)==='12.50','KM fracionado');
        check(client_form_document('12345678901')==='123.456.789-01','Mascara CPF sem validar digitos');
        check(client_form_document('12345678000199')==='12.345.678/0001-99','Mascara CNPJ');
        check(client_form_document('')==='','Documento opcional');

        $page=http('clients.php'); check($page['status']===200,'Formulario carregado');
        check(str_contains($page['body'],'id="contract-fields" hidden'),'Sem contrato inicia oculto');
        check(http('clients.php',null,'financeiro')['status']===200,'Financeiro pode consultar');
        check(http('clients.php',null,'tecnico')['status']===403,'Tecnico recusado');

        foreach ([['name'=>''],['address'=>''],['status'=>''],['status'=>'outro'],['standard_km'=>'12,555'],['standard_km'=>'-1']] as $invalid) {
            $before=clientCount();postClient($invalid);check(clientCount()===$before,'Servidor recusa '.json_encode($invalid));
        }
        $before=clientCount(); postClient(['csrf'=>'incorreto']);check(clientCount()===$before,'CSRF nao grava');
        $before=clientCount();postClient(['name'=>'Novo sem contrato','document'=>'12345678901','phone'=>'(11) 99999-0000'],'financeiro');
        check(clientCount()===$before+1,'Cadastro sem vencimento e sem contrato');
        $saved=$pdo->query("SELECT * FROM clients WHERE name='Novo sem contrato'")->fetch();
        check($saved['document']==='123.456.789-01','CPF mascarado armazenado');
        check($saved['phone']==='11999990000','Telefone numerico armazenado');
        check((float)$saved['standard_km']===12.5,'KM 12,5 persistido');

        foreach ([['due_day'=>'5'],['due_day'=>''],['monthly_fee'=>''],['extra_visit_fee'=>''],['monthly_fee'=>'-1']] as $invalid) {
            $before=clientCount();postClient(array_replace(['has_contract'=>'1','due_day'=>'10'],$invalid));
            check(clientCount()===$before,'Contrato recusa '.json_encode($invalid));
        }
        $before=clientCount();postClient(['name'=>'Contrato gratuito','has_contract'=>'1','due_day'=>'10']);
        check(clientCount()===$before+1,'Contrato com valores zero permitido');
        $contract=$pdo->query("SELECT * FROM clients WHERE name='Contrato gratuito'")->fetch();
        check((int)$contract['due_day']===10 && (float)$contract['monthly_fee']===0.0,'Vencimento 10 e mensalidade zero');
        $edit=http('clients.php?edit='.$contract['id']);
        check(str_contains($edit['body'],'Virada da competência dia 05'),'Mensagem de corte dia 05');

        postClient(['name'=>'Contrato monetario','has_contract'=>'1','due_day'=>'20','monthly_fee'=>'1.234,56','extra_visit_fee'=>'R$ 25,90']);
        $money=$pdo->query("SELECT * FROM clients WHERE name='Contrato monetario'")->fetch();
        check((float)$money['monthly_fee']===1234.56 && (float)$money['extra_visit_fee']===25.9,'Moeda brasileira persistida');
        check(str_contains(http('clients.php?edit='.$money['id'])['body'],'Virada da competência dia 15'),'Mensagem de corte dia 15');

        $before=clientCount();postClient(['name'=>'Outro nome','document'=>'123.456.789-01']);
        check(clientCount()===$before,'Duplicidade documento preservada');
        $before=clientCount();postClient(['name'=>'Novo sem contrato']);check(clientCount()===$before,'Duplicidade nome preservada');

        postClient(['id'=>'1','name'=>'Cliente com contrato','monthly_fee'=>'500,00','extra_visit_fee'=>'100,00']);
        check((int)$pdo->query('SELECT has_contract FROM clients WHERE id=1')->fetchColumn()===1,'Servidor impede desmarcar valores positivos');
        $response=http('clients.php?edit=1');check(str_contains($response['body'],'zere Mensalidade e Visita adicional'),'Erro de desmarcacao explicado');

        postClient(['id'=>'1','name'=>'Cliente com contrato','monthly_fee'=>'0,00','extra_visit_fee'=>'0,00','due_day'=>'20','contract_notes'=>'Preservar condições anteriores']);
        $off=$pdo->query('SELECT * FROM clients WHERE id=1')->fetch();
        check((int)$off['has_contract']===0 && $off['contract_notes']==='Preservar condições anteriores' && (int)$off['due_day']===20,'Desmarcar com zero preserva observacao e vencimento');
        postClient(['id'=>'1','name'=>'Cliente com contrato','has_contract'=>'1','due_day'=>'20','contract_notes'=>'Preservar condições anteriores']);
        check((int)$pdo->query('SELECT has_contract FROM clients WHERE id=1')->fetchColumn()===1,'Remarcar contrato permitido');

        postClient(['id'=>'2','name'=>'Cliente sem contrato legado','due_day'=>'5','contract_notes'=>'Observação preservada']);
        $legacy=$pdo->query('SELECT * FROM clients WHERE id=2')->fetch();
        check((int)$legacy['due_day']===5 && $legacy['contract_notes']==='Observação preservada','Legado sem contrato preserva dia e notas');
        $before=$pdo->query('SELECT name FROM clients WHERE id=1')->fetchColumn();
        postClient(['id'=>'1','name'=>'Tentativa Tecnico'],'tecnico');check($pdo->query('SELECT name FROM clients WHERE id=1')->fetchColumn()===$before,'Tecnico nao altera por POST');
        check(json_encode($pdo->query('SELECT * FROM receivables')->fetchAll())===$financialBefore,'Recebiveis da copia permanecem intactos');

        $report=['checks'=>$checks,'failures'=>$failures,'test_directory'=>$root,
            'scope'=>'Formulario real via HTTP e normalizacao; SQLite ficticio; sem configuracao ou banco reais.'];
        $evidence=$argv[1]??($root.'/evidencias');
        if(!is_dir($evidence)) throw new RuntimeException('Pasta de evidencias inexistente.');
        file_put_contents($evidence.'/resultado-clientes.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
        echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
    }
} finally {proc_terminate($process);proc_close($process);}
exit($failures?1:0);
