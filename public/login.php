<?php
require_once __DIR__ . '/../src/layout.php';
if (auth_user()) redirect(base_url('dashboard.php'));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT * FROM users WHERE email=? AND active=1 LIMIT 1');
    $st->execute([trim($_POST['email'] ?? '')]);

    $u = $st->fetch();

    $bloqueado = $u && $u['locked_until'] && strtotime($u['locked_until']) > time();

    if ($bloqueado) {
        $minutos = (int)ceil((strtotime($u['locked_until']) - time()) / 60);
        $error = 'Muitas tentativas. Tente novamente em ' . $minutos . ' minuto(s).';
    } elseif ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);

        db()->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([$u['id']]);

        $_SESSION['user'] = ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
        redirect(base_url('dashboard.php'));
    } else {
        if ($u) {
            $tentativas = (int)$u['failed_attempts'] + 1;

            if ($tentativas >= 5) {
                db()->prepare('UPDATE users SET failed_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?')
                    ->execute([$u['id']]);
            } else {
                db()->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')
                    ->execute([$tentativas, $u['id']]);
            }
        }

        $error = 'Usuário ou senha inválidos.';
    }
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
                <div class="mb-3"><label class="form-label">E-mail</label><input class="form-control" type="email" name="email" required></div>
                <div class="mb-3"><label class="form-label">Senha</label><input class="form-control" type="password" name="password" required></div>
                <button class="btn btn-primary w-100">Entrar</button>
            </form>
        </div>
    </div>
</div>
<?php render_footer(); ?>