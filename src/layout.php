<?php
require_once __DIR__ . '/bootstrap.php';
function render_header(string $title): void
{
    global $config;
    $u = auth_user();
    $menu = [
        'dashboard.php' => 'Dashboard',
        'clients.php' => 'Clientes',
        'materials.php' => 'Materiais',
        'tickets.php' => 'Chamados',
        'receivables.php' => 'Contas a Receber',
        'payables.php' => 'Contas a Pagar',
        'reports.php' => 'Relatórios',
        'users.php' => 'Usuários'
    ];
    echo '<!doctype html><html lang="pt-BR" data-bs-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' - ' . e($config['app_name']) . '</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="' . e(base_url('assets/css/app.css')) . '">';
    echo '</head><body><div class="app-shell">';
    if ($u) {
        echo '<aside class="sidebar"><div class="brand">Help-Desk</div><nav class="nav flex-column">';
        foreach ($menu as $file => $label) echo '<a class="nav-link" href="' . e(base_url($file)) . '">' . e($label) . '</a>';
        echo '</nav></aside><main class="content"><header class="topbar"><div><h5 class="mb-0">' . e($title) . '</h5></div><div class="d-flex gap-2 align-items-center"><button class="btn btn-sm btn-outline-secondary" id="themeToggle">☾</button><span class="small">' . e($u['name']) . ' · ' . e(strtoupper($u['role'])) . '</span><a class="btn btn-sm btn-outline-danger" href="' . e(base_url('logout.php')) . '">Sair</a></div></header><div class="container-fluid py-3">';
        foreach (flashes() as $f) echo '<div class="alert alert-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
}
function render_footer(): void
{
    if (auth_user()) echo '</div></main>';
    echo '</div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script><script src="' . e(base_url('assets/js/app.js')) . '"></script></body></html>';
}
