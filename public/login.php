<?php
require_once __DIR__ . '/../src/layout.php';
if (auth_user()) redirect(base_url('dashboard.php'));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT * FROM users WHERE email=? AND active=1 LIMIT 1');
    $st->execute([trim($_POST['email'] ?? '')]);
    $u = $st->fetch();
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        $_SESSION['user'] = ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
        redirect(base_url('dashboard.php'));
    }
    $error = 'Usuário ou senha inválidos.';
}
render_header('Login');
?>
<div class="login-wrap">
    <div class="card login-card shadow-sm">
        <div class="card-body p-4">
            <h3 class="mb-1">Help-Desk</h3>
            <p class="text-body-secondary">Gestão de chamados</p>
            <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <div class="mb-3"><label class="form-label">E-mail</label><input class="form-control" type="email" name="email" value="admin@gmb.local" required></div>
                <div class="mb-3"><label class="form-label">Senha</label><input class="form-control" type="password" name="password" value="admin123" required></div>
                <button class="btn btn-primary w-100">Entrar</button>
            </form>
        </div>
    </div>
</div>
<?php render_footer(); ?>