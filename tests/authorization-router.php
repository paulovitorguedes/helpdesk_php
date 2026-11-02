<?php
declare(strict_types=1);

// Roteador exclusivo do servidor temporario de testes, fora da aplicacao real.
$source = getenv('HELPDESK_AUTH_TEST_SOURCE');
$sessions = getenv('HELPDESK_AUTH_TEST_SESSIONS');
if (!$source || !$sessions || PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
// Evita interpretar aliases Windows (com ~) como expressao de arquivo INI no -d.
ini_set('session.save_path', $sessions);
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/__authorization_probe') {
    return false;
}

require_once $source . '/src/layout.php';
require_page_access((string)($_GET['page'] ?? ''));
render_header('Verificacao de acesso');
echo '<p>ACESSO_PERMITIDO</p>';
render_footer();
