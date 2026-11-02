<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$application = dirname(__DIR__);
$root = sys_get_temp_dir() . '/helpdesk-authorization-' . bin2hex(random_bytes(8));
foreach (['source/src', 'source/public', 'source/config', 'evidencias'] as $directory) {
    if (!mkdir($root . '/' . $directory, 0700, true) && !is_dir($root . '/' . $directory)) {
        throw new RuntimeException('Nao foi possivel preparar copia isolada.');
    }
}
foreach (['src', 'public'] as $directory) {
    foreach (glob($application . '/' . $directory . '/*.php') as $file) {
        if (!copy($file, $root . '/source/' . $directory . '/' . basename($file))) {
            throw new RuntimeException('Falha ao copiar arquivo para teste.');
        }
    }
}
file_put_contents($root . '/source/config/config.php', <<<'CONFIG'
<?php
return [
    'app_name' => 'Teste de permissoes', 'base_url' => '',
    'db' => ['host' => '127.0.0.1', 'port' => 1, 'name' => 'nao_conectar',
        'user' => 'nao_conectar', 'pass' => '', 'charset' => 'utf8mb4'],
];
CONFIG
);
$evidenceDirectory = $argv[1] ?? ($root . '/evidencias');
if (!is_dir($evidenceDirectory)) throw new RuntimeException('Diretorio de evidencias inexistente.');
$sessions = $root . '/evidencias/sessoes-teste';
if (!is_dir($sessions) && !mkdir($sessions, 0700, true)) {
    throw new RuntimeException('Nao foi possivel preparar sessoes isoladas.');
}
session_save_path($sessions);

$pages = [
    'dashboard.php', 'clients.php', 'materials.php', 'payables.php',
    'receivables.php', 'receivable_report.php', 'receivable_report_wa.php',
    'reports.php', 'tickets.php', 'users.php',
];
$expected = [
    'admin' => $pages,
    'financeiro' => ['dashboard.php', 'clients.php', 'materials.php', 'payables.php',
        'receivables.php', 'receivable_report.php', 'receivable_report_wa.php', 'reports.php'],
    'tecnico' => ['tickets.php'],
    'desconhecido' => [],
];
$menuPages = ['dashboard.php', 'clients.php', 'materials.php', 'tickets.php',
    'receivables.php', 'payables.php', 'reports.php', 'users.php'];
$cookies = [];
foreach (array_keys($expected) as $role) {
    $sessionId = 'permissiontest' . bin2hex(random_bytes(12));
    session_id($sessionId);
    session_start();
    $_SESSION = ['user' => ['id' => 999999, 'name' => 'Usuario de teste',
        'email' => 'teste@example.invalid', 'role' => $role]];
    session_write_close();
    $cookies[$role] = 'PHPSESSID=' . $sessionId;
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Nao foi possivel reservar porta de teste.');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int)substr(strrchr($address, ':'), 1);
$command = [PHP_BINARY, '-S', '127.0.0.1:' . $port,
    '-t', $root . '/source/public', __DIR__ . '/authorization-router.php'];
$environment = getenv();
$environment['HELPDESK_AUTH_TEST_SOURCE'] = $root . '/source';
$environment['HELPDESK_AUTH_TEST_SESSIONS'] = $sessions;
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['file', $root . '/evidencias/servidor-teste.log', 'a'],
    2 => ['file', $root . '/evidencias/servidor-teste.log', 'a'],
], $pipes, $root, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
if (!is_resource($process)) throw new RuntimeException('Falha ao iniciar servidor isolado.');
fclose($pipes[0]);

$checks = 0;
$failures = [];
$responseDiagnostics = [];
function verify(bool $condition, string $description): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) $failures[] = $description;
}
function request(string $path, ?string $cookie = null, string $method = 'GET'): array
{
    global $port, $responseDiagnostics;
    $headers = $cookie ? 'Cookie: ' . $cookie . "\r\n" : '';
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => $headers, 'content' => $method === 'POST' ? '' : null,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 4,
    ]]);
    $body = file_get_contents('http://127.0.0.1:' . $port . '/' . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/^HTTP\/\S+\s+(\d+)/', $responseHeaders[0] ?? '', $status);
    $location = null;
    foreach ($responseHeaders as $header) {
        if (stripos($header, 'Location: ') === 0) $location = substr($header, 10);
    }
    $result = ['status' => (int)($status[1] ?? 0), 'location' => $location, 'body' => (string)$body];
    if (count($responseDiagnostics) < 4 && $cookie !== null) {
        $responseDiagnostics[] = ['path' => $path, 'status' => $result['status'],
            'location' => $location, 'body_preview' => substr($result['body'], 0, 300)];
    }
    return $result;
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('Servidor de teste nao ficou disponivel.');

    foreach ($pages as $page) {
        foreach (['GET', 'POST'] as $method) {
            $response = request($page, null, $method);
            verify($response['status'] === 302 && $response['location'] === '/login.php',
                "Sem sessao: $method $page deve redirecionar para login");
        }
    }

    foreach ($expected as $role => $allowed) {
        foreach ($pages as $page) {
            $response = request('__authorization_probe?page=' . rawurlencode($page), $cookies[$role]);
            $isAllowed = in_array($page, $allowed, true);
            verify($response['status'] === ($isAllowed ? 200 : 403), "$role: autorizacao de $page");
            if ($isAllowed) {
                verify(str_contains($response['body'], 'ACESSO_PERMITIDO'), "$role: acesso permitido em $page");
                foreach ($menuPages as $menuPage) {
                    $visible = str_contains($response['body'], 'class="nav-link" href="/' . $menuPage . '"');
                    verify($visible === in_array($menuPage, $allowed, true), "$role: menu $menuPage em $page");
                }
            } else {
                foreach (['GET', 'POST'] as $method) {
                    $denied = request($page . '?delete=999999&export=financeiro', $cookies[$role], $method);
                    verify($denied['status'] === 403 && $denied['body'] === 'Acesso negado.',
                        "$role: acesso direto $method $page deve parar antes do banco");
                }
            }
        }
        verify(request('__authorization_probe?page=pagina-inexistente.php', $cookies[$role])['status'] === 403,
            "$role: pagina nao cadastrada deve ser negada");

        $home = $role === 'tecnico' ? '/tickets.php' : '/dashboard.php';
        foreach (['index.php', 'login.php'] as $entry) {
            $response = request($entry, $cookies[$role]);
            verify($response['status'] === 302 && $response['location'] === $home, "$role: destino de $entry");
        }
    }
    verify(request('index.php')['location'] === '/login.php', 'Inicio sem sessao leva ao login');
    verify(request('login.php')['status'] === 200, 'Formulario de login disponivel sem banco');

    $report = ['checks' => $checks, 'failures' => $failures,
        'test_directory' => $root, 'response_diagnostics' => $failures ? $responseDiagnostics : [],
        'scope' => 'Sessao e servidor isolados; nenhum banco conectado; autorizacao/menu/redirecionamentos.',
        'manual_validation_pending' => 'Login com credenciais reais e operacoes permitidas de cadastro/exclusao; sem teste destrutivo em producao.'];
    file_put_contents($evidenceDirectory . '/resultado-autorizacao.json', json_encode($report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo json_encode(['checks' => $checks, 'failure_count' => count($failures),
        'first_failures' => array_slice($failures, 0, 4), 'test_directory' => $root,
        'response_diagnostics' => $failures ? $responseDiagnostics : []],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} finally {
    proc_terminate($process);
    proc_close($process);
}
exit($failures ? 1 : 0);
