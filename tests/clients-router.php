<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || !getenv('HELPDESK_CLIENT_TEST_SOURCE') || !getenv('HELPDESK_CLIENT_TEST_SESSIONS')) {
    http_response_code(404);
    exit;
}
ini_set('session.save_path', getenv('HELPDESK_CLIENT_TEST_SESSIONS'));
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__test_login') {
    session_start();
    $_SESSION = ['user' => ['id' => 999999, 'name' => 'Pessoa de teste',
        'email' => 'teste@example.invalid', 'role' => 'admin'], 'csrf' => 'client-test-csrf'];
    header('Location: /clients.php');
    exit;
}
return false;
