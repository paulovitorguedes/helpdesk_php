<?php
declare(strict_types=1);
session_start();
$config = require __DIR__ . '/../config/config.php';

date_default_timezone_set('America/Sao_Paulo');

function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $d = $config['db'];
    $dsn = "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}";
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money(float|int|string|null $v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function base_url(string $path=''): string { global $config; return rtrim($config['base_url'], '/') . ($path ? '/' . ltrim($path, '/') : ''); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_check(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('CSRF inválido.'); } }
function auth_user(): ?array { return $_SESSION['user'] ?? null; }
function require_auth(): void { if (!auth_user()) redirect(base_url('login.php')); }
function flash(string $type, string $msg): void { $_SESSION['flash'][] = compact('type','msg'); }
function flashes(): array { $f=$_SESSION['flash']??[]; unset($_SESSION['flash']); return $f; }
function role_allowed(array $roles): bool { $u=auth_user(); return $u && in_array($u['role'], $roles, true); }
function cycle_from_close_date(string $closeDate): array {
    $end = new DateTimeImmutable($closeDate);
    $start = $end->modify('-1 month')->setDate((int)$end->modify('-1 month')->format('Y'), (int)$end->modify('-1 month')->format('m'), 16);
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}
