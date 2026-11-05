<?php
declare(strict_types=1);

function purchase_payable_paid(array $account): bool
{
    return $account['status'] === 'pago' || !empty($account['paid_at']);
}

function purchase_payable_create(PDO $pdo, int $materialId, int $purchaseId, string $supplier, float $total): void
{
    if (!$pdo->inTransaction()) { throw new LogicException('Compra e obrigação exigem transação.'); }
    $statement = $pdo->prepare('SELECT description FROM materials WHERE id = ?');
    $statement->execute([$materialId]);
    $name = $statement->fetchColumn();
    if ($name === false) { throw new RuntimeException('Material não encontrado para gerar a conta.'); }
    $statement = $pdo->prepare("INSERT INTO payables (description, supplier, total_amount, status, material_purchase_id, configuration_pending, total_manually_changed) VALUES (?, ?, ?, 'pendente', ?, 1, 0)");
    $statement->execute([$name, $supplier, number_format($total, 2, '.', ''), $purchaseId]);
}

function purchase_payable_for_update(PDO $pdo, int $purchaseId): ?array
{
    if (!$pdo->inTransaction()) { throw new LogicException('Leitura financeira exige transação.'); }
    $statement = $pdo->prepare('SELECT * FROM payables WHERE material_purchase_id = ? FOR UPDATE');
    $statement->execute([$purchaseId]);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function purchase_payable_sync(PDO $pdo, int $purchaseId, float $total): bool
{
    $account = purchase_payable_for_update($pdo, $purchaseId);
    if (!$account) { return false; } // Legacy purchases never acquire debts implicitly.
    if (purchase_payable_paid($account)) { throw new RuntimeException('Compra protegida: já existe pagamento registrado na conta vinculada.'); }
    if ((int)$account['total_manually_changed'] === 1) {
        return number_format((float)$account['total_amount'], 2, '.', '') !== number_format($total, 2, '.', '');
    }
    if (number_format((float)$account['total_amount'], 2, '.', '') !== number_format($total, 2, '.', '')) {
        $statement = $pdo->prepare('UPDATE payables SET total_amount = ?, configuration_pending = 1 WHERE id = ?');
        $statement->execute([number_format($total, 2, '.', ''), (int)$account['id']]);
    }
    return false;
}

function purchase_payables_delete_for_material(PDO $pdo, int $materialId): void
{
    // Caller already holds the material lock. All financial edits follow the same lock order.
    $statement = $pdo->prepare('SELECT p.* FROM payables p JOIN material_purchases mp ON mp.id = p.material_purchase_id WHERE mp.material_id = ? ORDER BY p.id FOR UPDATE');
    $statement->execute([$materialId]);
    $accounts = $statement->fetchAll(PDO::FETCH_ASSOC);
    foreach ($accounts as $account) {
        if (purchase_payable_paid($account)) { throw new RuntimeException('Não é possível excluir: existe pagamento registrado em uma conta vinculada a este material.'); }
    }
    $statement = $pdo->prepare('DELETE FROM payables WHERE id = ? AND material_purchase_id = ?');
    foreach ($accounts as $account) { $statement->execute([(int)$account['id'], (int)$account['material_purchase_id']]); }
}

function purchase_payable_revision(array $account): string
{
    $values = [];
    foreach (['id','material_purchase_id','description','supplier','category','total_amount','due_date','payment_method','installments','current_installment','installment_value','configuration_pending','total_manually_changed','paid_at','status','notes'] as $key) {
        $values[$key] = (string)($account[$key] ?? '');
    }
    return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
}

function payable_lock(PDO $pdo, int $accountId): array
{
    $statement = $pdo->prepare('SELECT mp.material_id FROM payables p JOIN material_purchases mp ON mp.id = p.material_purchase_id WHERE p.id = ?');
    $statement->execute([$accountId]);
    $materialId = $statement->fetchColumn();
    if ($materialId !== false) { material_lock($pdo, (int)$materialId); }
    $statement = $pdo->prepare('SELECT * FROM payables WHERE id = ? FOR UPDATE');
    $statement->execute([$accountId]);
    $account = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$account) { throw new RuntimeException('Conta a pagar não encontrada.'); }
    return $account;
}

function payable_delete(PDO $pdo, int $accountId): void
{
    $pdo->beginTransaction();
    try {
        $account = payable_lock($pdo, $accountId);
        if ($account['material_purchase_id'] !== null) { throw new RuntimeException('Conta vinculada a compra de material não pode ser excluída separadamente.'); }
        $statement = $pdo->prepare('DELETE FROM payables WHERE id = ?');
        $statement->execute([$accountId]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $error; }
}

function payable_update_configuration(PDO $pdo, int $accountId, array $values, string $revision): void
{
    $pdo->beginTransaction();
    try {
        $account = payable_lock($pdo, $accountId);
        if ($account['material_purchase_id'] !== null && !hash_equals(purchase_payable_revision($account), $revision)) {
            throw new RuntimeException('A compra ou a conta mudou desde a abertura do formulário. Reabra a conta para revisar os dados atuais.');
        }
        material_purchase_date($values['due_date']);
        if (!is_string($values['total_amount']) || !preg_match('/^[0-9]{1,10}(?:[.,][0-9]{1,2})?$/D', trim($values['total_amount']))) {
            throw new InvalidArgumentException('Informe um total válido, com até duas casas decimais.');
        }
        $values['total_amount'] = number_format((float)str_replace(',', '.', $values['total_amount']), 2, '.', '');
        $values['installments'] = material_whole_quantity($values['installments'], 'A quantidade de parcelas', false, 4294967295);
        $values['current_installment'] = material_whole_quantity($values['current_installment'], 'A parcela atual', false, 4294967295);
        if (!in_array($values['payment_method'], ['pix','debito','credito','dinheiro','boleto','transferencia'], true)) {
            throw new InvalidArgumentException('Escolha a forma de pagamento.');
        }
        if ($values['installments'] < 1 || $values['current_installment'] < 1 || $values['current_installment'] > $values['installments']) {
            throw new InvalidArgumentException('Informe parcelas válidas.');
        }
        $manual = (int)$account['total_manually_changed'];
        if ($account['material_purchase_id'] !== null && (float)$account['total_amount'] !== (float)$values['total_amount']) { $manual = 1; }
        $statement = $pdo->prepare('UPDATE payables SET due_date=?, description=?, category=?, supplier=?, total_amount=?, payment_method=?, installments=?, current_installment=?, installment_value=?, notes=?, configuration_pending=0, total_manually_changed=? WHERE id=?');
        $statement->execute([$values['due_date'],$values['description'],$values['category'],$values['supplier'],$values['total_amount'],$values['payment_method'],$values['installments'],$values['current_installment'],$values['installment_value'],$values['notes'],$manual,$accountId]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $error; }
}
