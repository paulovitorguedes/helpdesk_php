<?php
declare(strict_types=1);

function payable_cents(mixed $value): int
{
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^([0-9]{1,10})(?:[.,]([0-9]{1,2}))?$/D', trim((string)$value), $parts)) {
        throw new InvalidArgumentException('Informe um valor válido, com até duas casas decimais.');
    }
    return (int)$parts[1] * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
}

function payable_decimal(int $cents): string
{
    if ($cents < 0) { throw new LogicException('Valor negativo inesperado.'); }
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function payable_schedule_date(string $date, int $offset): string
{
    material_purchase_date($date);
    $original = new DateTimeImmutable($date);
    $month = $original->modify('first day of this month')->modify('+' . $offset . ' months');
    $result = $month->setDate((int)$month->format('Y'), (int)$month->format('m'), min((int)$original->format('d'), (int)$month->format('t')))->format('Y-m-d');
    return material_purchase_date($result);
}

function payable_schedule_terms(array $account): array
{
    if ((int)$account['configuration_pending'] === 1 || empty($account['due_date'])) {
        throw new RuntimeException('Configure vencimento, forma de pagamento e parcelas antes de registrar pagamentos.');
    }
    $count = (int)$account['installments'];
    if ($count < 1) { throw new RuntimeException('Revise o parcelamento da conta.'); }
    $total = payable_cents((string)$account['total_amount']);
    $base = $count === 1 ? $total : payable_cents((string)($account['installment_value'] ?? '0'));
    if ($count > 1 && $base === 0) { $base = intdiv($total, $count); }
    if ($base > 0 && $count - 1 > intdiv($total, $base)) {
        throw new RuntimeException('As parcelas informadas ultrapassam o total. Revise a conta antes de registrar pagamentos.');
    }
    return ['count'=>$count, 'total'=>$total, 'base'=>$base, 'last'=>$total - $base * ($count - 1)];
}

function payable_installment_amount(array $account, int $number): int
{
    $terms = payable_schedule_terms($account);
    if ($number < 1 || $number > $terms['count']) { throw new InvalidArgumentException('Parcela inválida.'); }
    return $number === $terms['count'] ? $terms['last'] : $terms['base'];
}

function payable_projected_amount(array $account, int $number): float
{
    try { return payable_installment_amount($account, $number) / 100; }
    catch (Throwable) {
        // Existing inconsistent plans stay visible for review; never fabricate a payment.
        return (int)$account['installments'] <= 1 ? (float)$account['total_amount'] : (float)$account['installment_value'];
    }
}

function payable_payment_eligible(array $account): void
{
    $terms = payable_schedule_terms($account);
    if ((int)$account['current_installment'] !== 1) {
        throw new RuntimeException('Esta conta começa em uma parcela avançada. O histórico anterior precisa de revisão antes de iniciar o controle de pagamentos. Nenhuma baixa antiga foi criada.');
    }
    if (!(int)($account['payment_history_locked'] ?? 0) && ($account['status'] === 'pago' || !empty($account['paid_at']))) {
        throw new RuntimeException('Esta conta já possui marcador de pagamento anterior ao novo extrato. Revise o histórico antes de registrar novas baixas.');
    }
    payable_schedule_date($account['due_date'], $terms['count'] - 1);
}

function payable_payment_rows(PDO $pdo, int $accountId, bool $lock = false): array
{
    $statement = $pdo->prepare('SELECT * FROM payable_payments WHERE payable_id = ? ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$accountId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function payable_payment_totals(array $payments): array
{
    $totals = [];
    foreach ($payments as $payment) {
        if ($payment['reversed_at'] !== null) { continue; }
        $number = (int)$payment['installment_number'];
        $totals[$number] = ($totals[$number] ?? 0) + payable_cents((string)$payment['amount']);
    }
    return $totals;
}

function payable_refresh_payment_markers(PDO $pdo, array $account, array $payments): void
{
    $active = 0; $firstDate = null;
    foreach ($payments as $payment) {
        if ($payment['reversed_at'] !== null) { continue; }
        $active += payable_cents((string)$payment['amount']);
        if ($firstDate === null || $payment['payment_date'] < $firstDate) { $firstDate = $payment['payment_date']; }
    }
    $status = $active >= payable_cents((string)$account['total_amount']) && $active > 0 ? 'pago' : 'pendente';
    $statement = $pdo->prepare('UPDATE payables SET payment_history_locked=1, status=?, paid_at=? WHERE id=?');
    $statement->execute([$status, $firstDate, (int)$account['id']]);
}

function payable_record_payment(PDO $pdo, int $accountId, array $input, int $author): array
{
    $amount = payable_cents($input['amount'] ?? '');
    if ($amount <= 0) { throw new InvalidArgumentException('O pagamento deve ser maior que zero.'); }
    $number = material_whole_quantity($input['installment_number'] ?? '', 'A parcela', false, 4294967295);
    try { $date = material_purchase_date($input['payment_date'] ?? ''); }
    catch (InvalidArgumentException) { throw new InvalidArgumentException('Informe uma data de pagamento válida.'); }
    $method = $input['payment_method'] ?? '';
    if (!in_array($method, ['pix','debito','credito','dinheiro','boleto','transferencia'], true)) { throw new InvalidArgumentException('Escolha a forma do pagamento realizado.'); }
    $token = $input['request_token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new InvalidArgumentException('Reabra a tela de pagamentos e tente novamente.'); }
    if (!is_string($input['notes'] ?? '')) { throw new InvalidArgumentException('Observação inválida.'); }
    $notes = trim($input['notes'] ?? '');
    $hash = hash('sha256', json_encode([$accountId,$number,$amount,$date,$method,$notes,$author], JSON_THROW_ON_ERROR));
    // Account locks serialize a balance; READ COMMITTED avoids unrelated empty-ledger gap locks.
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); }
    $pdo->beginTransaction();
    try {
        $account = payable_lock($pdo, $accountId);
        // Current locking reads are required even after payable_lock's initial non-locking lookup.
        $payments = payable_payment_rows($pdo, $accountId, true);
        foreach ($payments as $payment) {
            if ($payment['request_token'] !== $token) { continue; }
            if (!hash_equals($payment['request_hash'], $hash)) { throw new RuntimeException('Este formulário já foi usado com outros dados. Reabra a tela de pagamentos.'); }
            $pdo->commit(); return ['id'=>(int)$payment['id'], 'duplicate'=>true];
        }
        payable_payment_eligible($account);
        if (!is_string($input['account_revision'] ?? null) || !hash_equals(purchase_payable_revision($account), $input['account_revision'])) {
            throw new RuntimeException('A conta mudou desde a abertura da tela. Reabra os pagamentos para conferir os dados atuais.');
        }
        $due = payable_installment_amount($account, $number);
        $paid = payable_payment_totals($payments)[$number] ?? 0;
        if ($amount > $due - $paid) { throw new RuntimeException('Pagamento acima do saldo atual da parcela: R$ ' . number_format(($due - $paid) / 100, 2, ',', '.') . '.'); }
        $statement = $pdo->prepare('INSERT INTO payable_payments (payable_id,installment_number,amount,payment_date,payment_method,notes,request_token,request_hash,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $statement->execute([$accountId,$number,payable_decimal($amount),$date,$method,$notes,$token,$hash,$author]);
        $id = (int)$pdo->lastInsertId();
        payable_refresh_payment_markers($pdo, $account, payable_payment_rows($pdo, $accountId, true));
        $pdo->commit(); return ['id'=>$id, 'duplicate'=>false];
    } catch (Throwable $error) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $error; }
}

function payable_reverse_payment(PDO $pdo, int $accountId, int $paymentId, mixed $reason, int $author): bool
{
    if (!is_string($reason) || trim($reason) === '') { throw new InvalidArgumentException('Informe o motivo do estorno.'); }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); }
    $pdo->beginTransaction();
    try {
        $account = payable_lock($pdo, $accountId);
        $payment = null;
        foreach (payable_payment_rows($pdo, $accountId, true) as $candidate) { if ((int)$candidate['id'] === $paymentId) { $payment = $candidate; break; } }
        if (!$payment) { throw new RuntimeException('Pagamento não encontrado nesta conta.'); }
        if ($payment['reversed_at'] !== null) { $pdo->commit(); return false; }
        $statement = $pdo->prepare('UPDATE payable_payments SET reversed_at=CURRENT_TIMESTAMP,reversed_by=?,reversal_reason=? WHERE id=? AND payable_id=? AND reversed_at IS NULL');
        $statement->execute([$author,trim($reason),$paymentId,$accountId]);
        payable_refresh_payment_markers($pdo, $account, payable_payment_rows($pdo, $accountId, true));
        $pdo->commit(); return true;
    } catch (Throwable $error) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $error; }
}
