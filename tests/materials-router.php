<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || !getenv('HELPDESK_MATERIAL_TEST_DB') || !getenv('HELPDESK_MATERIAL_TEST_SESSIONS')) { http_response_code(404); exit; }
ini_set('session.save_path', getenv('HELPDESK_MATERIAL_TEST_SESSIONS'));
class MaterialTestPDO extends PDO
{
    public function __construct() {
        parent::__construct('sqlite:' . getenv('HELPDESK_MATERIAL_TEST_DB'), null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->exec('PRAGMA foreign_keys=ON');
    }
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        return parent::prepare(preg_replace('/\s+FOR UPDATE\b/i', '', $query), $options);
    }
}
if (getenv('HELPDESK_MATERIAL_TEST_PREVIEW') === '1' && parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__test_login') {
    session_start();
    $_SESSION=['user'=>['id'=>999999,'name'=>'Pessoa de teste','email'=>'teste@example.invalid','role'=>'admin'],'csrf'=>'material-test-csrf'];
    header('Location: /materials.php?edit=4');exit;
}
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__test_flash') {
    session_start();
    header('Content-Type: application/json');
    echo json_encode($_SESSION);
    exit;
}
return false;
