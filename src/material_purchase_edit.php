<?php
declare(strict_types=1);

function material_latest_purchase(PDO $pdo, int $materialId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM material_purchases WHERE material_id = ? ORDER BY purchase_date DESC, id DESC LIMIT 1');
    $statement->execute([$materialId]);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function material_purchase_revision(array $purchase): string
{
    $values = [];
    foreach (['id', 'material_id', 'purchase_date', 'supplier', 'quantity', 'total_cost', 'unit_cost', 'notes'] as $key) {
        $values[$key] = (string)($purchase[$key] ?? '');
    }
    return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
}

function material_purchase_edit_block(PDO $pdo, array $purchase): ?string
{
    $statement = $pdo->prepare('SELECT id, movement_type, quantity, ticket_id FROM stock_movements WHERE purchase_id = ? ORDER BY id');
    $statement->execute([(int)$purchase['id']]);
    $entries = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($entries) !== 1 || $entries[0]['movement_type'] !== 'entrada'
        || $entries[0]['ticket_id'] !== null || (float)$entries[0]['quantity'] !== (float)$purchase['quantity']) {
        return 'A entrada de estoque desta compra precisa de conferência antes de alterar data, quantidade ou valor.';
    }
    // IDs reflect recording order; backdating a purchase does not erase subsequent consumption.
    $statement = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE material_id = ? AND id > ? AND (movement_type <> 'entrada' OR ticket_id IS NOT NULL)");
    $statement->execute([(int)$purchase['material_id'], (int)$entries[0]['id']]);
    if ((int)$statement->fetchColumn() > 0) {
        return 'Data, quantidade e valor estão protegidos: houve saída ou movimentação posterior à entrada desta compra, mesmo que o material tenha sido devolvido.';
    }
    return null;
}

function material_purchase_edit_total(mixed $raw): string
{
    if (!is_string($raw) || !preg_match('/^[0-9]{1,10}(?:[.,][0-9]{1,2})?$/D', trim($raw))) {
        throw new InvalidArgumentException('Informe um valor total válido, com até duas casas decimais.');
    }
    return number_format((float)str_replace(',', '.', trim($raw)), 2, '.', '');
}

function material_update_latest_purchase(PDO $pdo, int $materialId, array $input): void
{
    $pdo->beginTransaction();
    try {
        material_lock($pdo, $materialId);
        $purchase = material_latest_purchase($pdo, $materialId);
        if (!$purchase || (int)($input['last_purchase_id'] ?? 0) !== (int)$purchase['id']
            || !is_string($input['last_purchase_revision'] ?? null)
            || !hash_equals(material_purchase_revision($purchase), $input['last_purchase_revision'])) {
            throw new RuntimeException('A última compra mudou desde a abertura da tela. Recarregue o material antes de corrigir.');
        }
        $date = material_purchase_date($input['last_purchase_date'] ?? '');
        $quantity = material_whole_quantity($input['last_purchase_quantity'] ?? '', 'A quantidade comprada');
        $total = material_purchase_edit_total($input['last_purchase_total_cost'] ?? '');
        $unitCost = round((float)$total / $quantity, 4);
        if ($unitCost > 99999999.9999) {
            throw new InvalidArgumentException('O custo unitário ultrapassa o limite permitido.');
        }
        $amountChanged = $quantity !== (int)$purchase['quantity'] || (float)$total !== (float)$purchase['total_cost'];
        $changed = $date !== $purchase['purchase_date'] || $amountChanged;
        if ($changed) {
            $blocked = material_purchase_edit_block($pdo, $purchase);
            if ($blocked !== null) { throw new RuntimeException($blocked); }
            $statement = $pdo->prepare('SELECT MAX(purchase_date) FROM material_purchases WHERE material_id = ? AND id <> ?');
            $statement->execute([$materialId, (int)$purchase['id']]);
            $previousDate = $statement->fetchColumn();
            if ($previousDate && $date < $previousDate) {
                throw new RuntimeException('A data corrigida não pode ser anterior à compra precedente deste material (' . date('d/m/Y', strtotime($previousDate)) . ').');
            }
        }
        $description = trim((string)($input['description'] ?? ''));
        $supplier = trim((string)($input['last_purchase_supplier'] ?? ''));
        if ($description === '' || mb_strlen($description) > 190 || mb_strlen($supplier) > 190) {
            throw new InvalidArgumentException('Informe a descrição e limite descrição e fornecedor a 190 caracteres.');
        }
        $minimum = material_whole_quantity($input['minimum_stock'] ?? '0', 'O estoque mínimo', true);
        $statement = $pdo->prepare('UPDATE materials SET description = ?, minimum_stock = ?, notes = ? WHERE id = ? AND deleted_at IS NULL');
        $statement->execute([$description, $minimum, trim((string)($input['notes'] ?? '')), $materialId]);
        // Preserve legacy rounding unless quantity or total actually changes.
        $statement = $pdo->prepare('UPDATE material_purchases SET purchase_date = ?, supplier = ?, quantity = ?, total_cost = ?, unit_cost = ?, notes = ? WHERE id = ? AND material_id = ?');
        $statement->execute([$date, $supplier, $quantity, $total, $amountChanged ? number_format($unitCost, 4, '.', '') : $purchase['unit_cost'], trim((string)($input['last_purchase_notes'] ?? '')), (int)$purchase['id'], $materialId]);
        if ($changed) {
            $statement = $pdo->prepare("UPDATE stock_movements SET quantity = ? WHERE material_id = ? AND purchase_id = ? AND movement_type = 'entrada' AND ticket_id IS NULL");
            $statement->execute([$quantity, $materialId, (int)$purchase['id']]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}
