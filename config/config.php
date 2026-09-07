<?php
return [
    'app_name' => 'GMB Help-Desk ERP',
    'base_url' => getenv('APP_BASE_URL') ?: '/helpdesk_php/public',
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'helpdesk',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
];
