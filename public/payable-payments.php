<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../src/material_rules.php';
require_page_access('payables.php');
$pdo = db();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { $pdo->exec("SET SESSION time_zone = '-03:00'"); }
$accountId = (int)($_POST['payable_id'] ?? $_GET['id'] ?? 0);
$destination = base_url('payable-payments.php?id=' . $accountId);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'pay') {
            $result = payable_record_payment($pdo, $accountId, $_POST, (int)auth_user()['id']);
            $message = $result['duplicate'] ? 'Este pagamento já foi registrado. Nenhum lançamento duplicado foi criado.' : 'Pagamento registrado com sucesso.';
        } elseif ($action === 'reverse') {
            $changed = payable_reverse_payment($pdo, $accountId, (int)($_POST['payment_id'] ?? 0), $_POST['reason'] ?? '', (int)auth_user()['id']);
            $message = $changed ? 'Pagamento estornado. O histórico e a proteção financeira foram mantidos.' : 'Este pagamento já havia sido estornado.';
        } else { throw new InvalidArgumentException('Operação inválida.'); }
        flash('success', $message);
        if ($json) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>true,'redirect'=>$destination]); exit; }
        redirect($destination);
    } catch (Throwable $error) {
        if ($error instanceof PDOException) { error_log($error->getMessage()); }
        $message = $error instanceof PDOException ? 'Não foi possível concluir. Nenhuma alteração parcial foi mantida.' : $error->getMessage();
        if ($json) { http_response_code($error instanceof PDOException ? 500 : 409); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'message'=>$message]); exit; }
        flash('warning', $message); redirect($destination);
    }
}
$statement = $pdo->prepare('SELECT * FROM payables WHERE id = ?');
$statement->execute([$accountId]);$account = $statement->fetch();
if (!$account) { http_response_code(404); render_header('Pagamentos'); echo '<div class="alert alert-warning">Conta não encontrada.</div>'; render_footer(); exit; }
$payments = payable_payment_rows($pdo, $accountId);
$totals = payable_payment_totals($payments);
$authors = [];
$authorIds = array_values(array_unique(array_filter(array_merge(array_column($payments, 'created_by'), array_column($payments, 'reversed_by')))));
if ($authorIds) {
    $statement = $pdo->prepare('SELECT id, name FROM users WHERE id IN (' . implode(',', array_fill(0, count($authorIds), '?')) . ')');
    $statement->execute($authorIds);$authors = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
}
$problem = null;
try { payable_payment_eligible($account); $terms = payable_schedule_terms($account); }
catch (Throwable $error) { $problem = $error->getMessage(); }
$page = max(1, (int)($_GET['page'] ?? 1));$size = 24;
if (!$problem) { $page = min($page, max(1, (int)ceil($terms['count'] / $size))); }
$first = ($page - 1) * $size + 1;
render_header('Pagamentos');
?>
<div class="d-flex justify-content-between align-items-start gap-3 mb-3">
    <div><h4 class="mb-1">Pagamentos por parcela</h4><p class="text-body-secondary mb-0"><?= e($account['description']) ?> · Total: <?= money($account['total_amount']) ?></p></div>
    <a class="btn btn-outline-secondary" href="<?= e(base_url('payables.php')) ?>">Voltar</a>
</div>
<?php if ($problem): ?>
    <div class="alert alert-warning"><?= e($problem) ?> <a href="<?= e(base_url('payables.php?edit_id=' . $accountId)) ?>">Revisar conta</a></div>
<?php else: ?>
    <p class="small text-body-secondary">Os centavos de diferença são ajustados na última parcela. Pagamentos não alteram a competência das despesas do Dashboard.</p>
    <?php if (purchase_payable_paid($account)): ?><div class="alert alert-info">Total, vencimentos e parcelamento estão protegidos pelo histórico de pagamentos. Um estorno reabre o saldo, mantendo essa proteção.</div><?php endif; ?>
    <div class="table-responsive mb-3"><table class="table align-middle" id="paymentInstallments">
        <thead><tr><th>Parcela</th><th>Vencimento</th><th>Valor</th><th>Pago</th><th>Saldo</th><th>Situação</th></tr></thead>
        <tbody><?php for ($number = $first; $number <= min($terms['count'], $first + $size - 1); $number++):
            $amount = payable_installment_amount($account, $number);$paid = $totals[$number] ?? 0;$balance = $amount - $paid;
        ?><tr><td><?= $number ?> / <?= $terms['count'] ?></td><td><?= date('d/m/Y', strtotime(payable_schedule_date($account['due_date'], $number - 1))) ?></td>
            <td><?= money(payable_decimal($amount)) ?></td><td><?= money(payable_decimal($paid)) ?></td><td><?= money(payable_decimal($balance)) ?></td>
            <td><?= $amount === 0 ? 'Sem valor' : ($balance === 0 ? 'Quitada' : ($paid > 0 ? 'Parcial' : 'Em aberto')) ?></td></tr>
        <?php endfor; ?></tbody>
    </table></div>
    <?php if ($terms['count'] > $size): ?><nav class="d-flex gap-2 mb-3" aria-label="Páginas de parcelas">
        <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e($destination . '&page=' . ($page - 1)) ?>">Anteriores</a><?php endif; ?>
        <span>Página <?= $page ?> de <?= (int)ceil($terms['count'] / $size) ?></span>
        <?php if ($page * $size < $terms['count']): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e($destination . '&page=' . ($page + 1)) ?>">Próximas</a><?php endif; ?>
    </nav><?php endif; ?>
    <?php if ($terms['total'] > array_sum($totals)): ?>
    <section class="card mb-4"><div class="card-body"><h5>Registrar pagamento</h5>
        <form method="post" data-payable-operation class="row g-3">
            <div class="col-12 alert alert-danger" role="alert" data-payment-feedback tabindex="-1" hidden></div>
            <?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="payable_id" value="<?= $accountId ?>">
            <input type="hidden" name="request_token" value="<?= bin2hex(random_bytes(32)) ?>"><input type="hidden" name="account_revision" value="<?= e(purchase_payable_revision($account)) ?>">
            <div class="col-sm-2"><label for="payment-installment" class="form-label">Parcela</label><input class="form-control" id="payment-installment" name="installment_number" type="number" min="1" max="<?= $terms['count'] ?>" step="1" required></div>
            <div class="col-sm-2"><label for="payment-amount" class="form-label">Valor pago (R$)</label><input class="form-control" id="payment-amount" name="amount" type="number" min="0.01" step="0.01" required></div>
            <div class="col-sm-3"><label for="payment-date" class="form-label">Data do pagamento</label><input class="form-control" id="payment-date" name="payment_date" type="date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-sm-3"><label for="payment-method" class="form-label">Forma utilizada</label><select class="form-select" id="payment-method" name="payment_method" required>
                <option value="">Selecione</option><?php foreach (['pix'=>'PIX','debito'=>'Débito','credito'=>'Crédito','dinheiro'=>'Dinheiro','boleto'=>'Boleto','transferencia'=>'Transferência'] as $value=>$label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
            <div class="col-12"><label for="payment-notes" class="form-label">Observações</label><textarea class="form-control" id="payment-notes" name="notes" rows="2"></textarea></div>
            <div class="col-12"><button class="btn btn-primary" type="submit">Registrar pagamento</button></div>
        </form>
    </div></section>
    <?php else: ?><div class="alert alert-success">Não há saldo em aberto nas parcelas deste controle.</div><?php endif; ?>
<?php endif; ?>
<section class="card"><div class="card-body"><h5>Histórico de pagamentos e estornos</h5>
    <?php if (!$payments): ?><p class="text-body-secondary mb-0">Nenhum pagamento registrado neste controle.</p><?php endif; ?>
    <?php foreach (array_reverse($payments) as $payment): ?>
        <article class="border rounded p-3 mt-3">
            <div><strong>Parcela <?= (int)$payment['installment_number'] ?> · <?= money($payment['amount']) ?></strong> · <?= date('d/m/Y', strtotime($payment['payment_date'])) ?> · <?= e($payment['payment_method']) ?></div>
            <div class="small text-body-secondary">Registro #<?= (int)$payment['id'] ?> · <?= e($authors[$payment['created_by']] ?? 'Usuário indisponível') ?> · <?= date('d/m/Y H:i', strtotime($payment['created_at'])) ?></div>
            <?php if ($payment['notes']): ?><p class="mb-1"><?= nl2br(e($payment['notes'])) ?></p><?php endif; ?>
            <?php if ($payment['reversed_at'] !== null): ?>
                <div class="alert alert-warning mb-0 mt-2">Estornado em <?= date('d/m/Y H:i', strtotime($payment['reversed_at'])) ?> por <?= e($authors[$payment['reversed_by']] ?? 'Usuário indisponível') ?>. Motivo: <?= e($payment['reversal_reason']) ?></div>
            <?php else: ?>
                <details class="mt-2"><summary class="text-danger">Estornar este pagamento</summary>
                    <form method="post" data-payable-operation class="mt-2" onsubmit="return confirm('Estornar este registro de pagamento, preservando o histórico e a proteção financeira?');">
                        <div class="alert alert-danger" role="alert" data-payment-feedback tabindex="-1" hidden></div>
                        <?= csrf_field() ?><input type="hidden" name="action" value="reverse"><input type="hidden" name="payable_id" value="<?= $accountId ?>"><input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
                        <label class="form-label" for="reversal-reason-<?= (int)$payment['id'] ?>">Motivo obrigatório</label><textarea class="form-control mb-2" id="reversal-reason-<?= (int)$payment['id'] ?>" name="reason" rows="2" required></textarea>
                        <button class="btn btn-outline-danger" type="submit">Confirmar estorno</button>
                    </form>
                </details>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div></section>
<script src="<?= e(base_url('assets/js/payable-payments.js') . '?v=' . filemtime(__DIR__ . '/assets/js/payable-payments.js')) ?>"></script>
<?php render_footer(); ?>
