<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/layout.php';

require_auth();
if (!role_allowed(['admin'])) {
    exit('Acesso negado.');
}

$pdo    = db();
$eu     = auth_user();
$selfId = (int)($eu['id'] ?? 0);

$PERFIS = [
    'admin'      => 'Administrador',
    'tecnico'    => 'Técnico',
    'financeiro' => 'Financeiro',
];

$contarAdminsAtivos = function (int $excluirId = 0) use ($pdo): int {
    if ($excluirId > 0) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = ? AND active = 1 AND id <> ?');
        $st->execute(['admin', $excluirId]);
    } else {
        $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = ? AND active = 1');
        $st->execute(['admin']);
    }
    return (int)$st->fetchColumn();
};

$emailEmUso = function (string $email, int $ignorarId = 0) use ($pdo): bool {
    if ($ignorarId > 0) {
        $st = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $st->execute([$email, $ignorarId]);
    } else {
        $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
    }
    return (bool)$st->fetch();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    /* ---------- ATIVAR / DESATIVAR ---------- */
    if ($action === 'toggle') {
        $st = $pdo->prepare('SELECT id, role, active FROM users WHERE id = ?');
        $st->execute([$id]);
        $alvo = $st->fetch();

        if (!$alvo) {
            flash('danger', 'Usuário não encontrado.');
            redirect(base_url('users.php'));
        }

        if ($id === $selfId) {
            flash('danger', 'Você não pode desativar a própria conta.');
            redirect(base_url('users.php'));
        }

        if ((int)$alvo['active'] === 1) {
            if ($alvo['role'] === 'admin' && $contarAdminsAtivos($id) === 0) {
                flash('danger', 'Este é o último administrador ativo. Ative outro antes de desativar este.');
                redirect(base_url('users.php'));
            }
            $pdo->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute([$id]);
            flash('success', 'Usuário desativado.');
        } else {
            $pdo->prepare('UPDATE users SET active = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?')
                ->execute([$id]);
            flash('success', 'Usuário ativado.');
        }

        redirect(base_url('users.php'));
    }

    /* ---------- CRIAR ---------- */
    if ($action === 'create') {
        $name   = trim((string)($_POST['name'] ?? ''));
        $email  = trim((string)($_POST['email'] ?? ''));
        $role   = (string)($_POST['role'] ?? 'tecnico');
        $active = (int)($_POST['active'] ?? 1) === 1 ? 1 : 0;
        $senha  = (string)($_POST['password'] ?? '');

        $erros = [];
        if ($name === '')  $erros[] = 'Informe o nome.';
        if ($email === '') $erros[] = 'Informe o e-mail.';
        if (!array_key_exists($role, $PERFIS)) $erros[] = 'Perfil inválido.';
        if (strlen($senha) < 8) $erros[] = 'A senha precisa ter pelo menos 8 caracteres.';
        if ($email !== '' && $emailEmUso($email)) $erros[] = 'Este e-mail já está cadastrado.';

        if ($erros) {
            foreach ($erros as $msg) flash('danger', $msg);
            redirect(base_url('users.php'));
        }

        $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, active, failed_attempts, locked_until)
             VALUES (?, ?, ?, ?, ?, 0, NULL)'
        )->execute([$name, $email, password_hash($senha, PASSWORD_DEFAULT), $role, $active]);

        flash('success', 'Usuário criado.');
        redirect(base_url('users.php'));
    }

    /* ---------- EDITAR ---------- */
    if ($action === 'update') {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        $alvo = $st->fetch();

        if (!$alvo) {
            flash('danger', 'Usuário não encontrado.');
            redirect(base_url('users.php'));
        }

        $name   = trim((string)($_POST['name'] ?? ''));
        $email  = trim((string)($_POST['email'] ?? ''));
        $role   = (string)($_POST['role'] ?? $alvo['role']);
        $active = (int)($_POST['active'] ?? 1) === 1 ? 1 : 0;
        $senha  = (string)($_POST['password'] ?? '');

        $erros = [];
        if ($name === '')  $erros[] = 'Informe o nome.';
        if ($email === '') $erros[] = 'Informe o e-mail.';
        if (!array_key_exists($role, $PERFIS)) $erros[] = 'Perfil inválido.';
        if ($senha !== '' && strlen($senha) < 8) $erros[] = 'A senha precisa ter pelo menos 8 caracteres.';
        if ($email !== '' && $emailEmUso($email, $id)) $erros[] = 'Este e-mail já está cadastrado.';

        if ($id === $selfId) {
            $role   = $alvo['role'];
            $active = 1;
        }

        $eraUltimoAdmin = $alvo['role'] === 'admin'
            && (int)$alvo['active'] === 1
            && $contarAdminsAtivos($id) === 0;

        if ($eraUltimoAdmin && ($role !== 'admin' || $active !== 1)) {
            $erros[] = 'Este é o último administrador ativo. Mantenha o perfil Administrador e a conta ativa.';
        }

        if ($erros) {
            foreach ($erros as $msg) flash('danger', $msg);
            redirect(base_url('users.php?edit=' . $id));
        }

        if ($senha !== '') {
            $pdo->prepare(
                'UPDATE users SET name = ?, email = ?, role = ?, active = ?, password_hash = ?,
                        failed_attempts = 0, locked_until = NULL
                 WHERE id = ?'
            )->execute([$name, $email, $role, $active, password_hash($senha, PASSWORD_DEFAULT), $id]);
        } else {
            $pdo->prepare(
                'UPDATE users SET name = ?, email = ?, role = ?, active = ? WHERE id = ?'
            )->execute([$name, $email, $role, $active, $id]);
        }

        flash('success', 'Usuário atualizado.');
        redirect(base_url('users.php'));
    }

    flash('danger', 'Ação não reconhecida.');
    redirect(base_url('users.php'));
}

/* ---------- MODO DE EDIÇÃO ---------- */
$editId   = (int)($_GET['edit'] ?? 0);
$emEdicao = null;

if ($editId > 0) {
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$editId]);
    $emEdicao = $st->fetch() ?: null;

    if (!$emEdicao) {
        flash('danger', 'Usuário não encontrado.');
        redirect(base_url('users.php'));
    }
}

$editandoProprio = $emEdicao && (int)$emEdicao['id'] === $selfId;

$rows = $pdo->query(
    'SELECT id, name, email, role, active, failed_attempts, locked_until, created_at
     FROM users ORDER BY name'
)->fetchAll();

render_header('Usuários');
?>
<div class="row g-3">

    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h5><?= $emEdicao ? 'Editar usuário' : 'Novo usuário' ?></h5>

                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $emEdicao ? 'update' : 'create' ?>">
                    <?php if ($emEdicao): ?>
                        <input type="hidden" name="id" value="<?= (int)$emEdicao['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-2">
                        <label>Nome</label>
                        <input class="form-control" name="name" required
                            value="<?= e($emEdicao['name'] ?? '') ?>">
                    </div>

                    <div class="mb-2">
                        <label>E-mail</label>
                        <input class="form-control" type="email" name="email" required
                            value="<?= e($emEdicao['email'] ?? '') ?>">
                    </div>

                    <div class="mb-2">
                        <label><?= $emEdicao ? 'Nova senha (opcional)' : 'Senha' ?></label>
                        <input class="form-control" type="password" name="password"
                            minlength="8" <?= $emEdicao ? '' : 'required' ?>>
                        <small class="text-muted">
                            <?= $emEdicao
                                ? 'Deixe em branco para manter a senha atual. Mínimo de 8 caracteres.'
                                : 'Mínimo de 8 caracteres.' ?>
                        </small>
                    </div>

                    <div class="mb-2">
                        <label>Perfil</label>
                        <select class="form-select" name="role" <?= $editandoProprio ? 'disabled' : '' ?>>
                            <?php foreach ($PERFIS as $valor => $rotulo): ?>
                                <option value="<?= e($valor) ?>"
                                    <?= ($emEdicao['role'] ?? 'tecnico') === $valor ? 'selected' : '' ?>>
                                    <?= e($rotulo) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($editandoProprio): ?>
                            <small class="text-muted">Você não pode alterar o próprio perfil.</small>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label>Situação</label>
                        <select class="form-select" name="active" <?= $editandoProprio ? 'disabled' : '' ?>>
                            <option value="1" <?= (int)($emEdicao['active'] ?? 1) === 1 ? 'selected' : '' ?>>Ativo</option>
                            <option value="0" <?= (int)($emEdicao['active'] ?? 1) === 0 ? 'selected' : '' ?>>Inativo</option>
                        </select>
                        <?php if ($editandoProprio): ?>
                            <small class="text-muted">Você não pode desativar a própria conta.</small>
                        <?php endif; ?>
                    </div>

                    <button class="btn btn-primary"><?= $emEdicao ? 'Salvar' : 'Criar' ?></button>
                    <?php if ($emEdicao): ?>
                        <a class="btn btn-secondary" href="<?= e(base_url('users.php')) ?>">Cancelar</a>
                    <?php endif; ?>
                </form>

            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>E-mail</th>
                        <th>Perfil</th>
                        <th>Situação</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $travado = $r['locked_until'] && strtotime($r['locked_until']) > time();
                        $souEu   = (int)$r['id'] === $selfId;
                    ?>
                        <tr>
                            <td><?= e($r['name']) ?><?= $souEu ? ' <small class="text-muted">(você)</small>' : '' ?></td>
                            <td><?= e($r['email']) ?></td>
                            <td><?= e($PERFIS[$r['role']] ?? $r['role']) ?></td>
                            <td>
                                <?php if ((int)$r['active'] === 1): ?>
                                    <span class="badge bg-success">Ativo</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inativo</span>
                                <?php endif; ?>
                                <?php if ($travado): ?>
                                    <span class="badge bg-warning text-dark">Bloqueado</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary"
                                    href="<?= e(base_url('users.php?edit=' . (int)$r['id'])) ?>">Editar</a>

                                <?php if (!$souEu): ?>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm <?= (int)$r['active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                            <?= (int)$r['active'] === 1 ? 'Desativar' : 'Ativar' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
<?php render_footer(); ?>