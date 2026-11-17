<?php
declare(strict_types=1);
require_once __DIR__ . '/purchase_payables.php';

function material_quantity_display(float|int|string $quantity): string
{
    // Retira apenas zeros decimais; uma fracao inesperada continua visivel.
    return rtrim(rtrim(number_format((float)$quantity, 2, ',', '.'), '0'), ',');
}

function material_whole_quantity(mixed $value, string $label, bool $allowZero = false, int $maximum = 9999999999): int
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException($label . ' deve ser um número inteiro.');
    }
    $text = trim((string)$value);
    if (!preg_match('/^[0-9]+(?:[.,]0+)?$/D', $text)) {
        throw new InvalidArgumentException($label . ' deve ser um número inteiro, sem frações.');
    }
    $digits = ltrim(preg_split('/[.,]/', $text)[0], '0');
    $digits = $digits === '' ? '0' : $digits;
    $limit = (string)$maximum;
    if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
        throw new InvalidArgumentException($label . ' ultrapassa o limite permitido.');
    }
    $quantity = (int)$digits;
    if (!$allowZero && $quantity === 0) {
        throw new InvalidArgumentException($label . ' deve ser maior que zero.');
    }
    return $quantity;
}

function material_new_purchase_date(mixed $value): string
{
    if ($value === null || (is_string($value) && trim($value) === '')) { $value = date('Y-m-d'); }
    return material_purchase_date($value);
}

function material_purchase_date(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)
        || (int)$parts[1] < 1000 || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
        throw new InvalidArgumentException('Informe uma data de compra válida.');
    }
    return $value;
}

// A linha do material serializa compras/exclusões com as baixas de estoque dos chamados.
function material_lock(PDO $pdo, int $materialId): void
{
    if (!$pdo->inTransaction()) { throw new LogicException('Operação de material exige transação.'); }
    $statement = $pdo->prepare('SELECT id FROM materials WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
    $statement->execute([$materialId]);
    if (!$statement->fetchColumn()) { throw new RuntimeException('Material não encontrado.'); }
}

function material_validate_next_purchase(PDO $pdo, int $materialId, string $purchaseDate): void
{
    material_lock($pdo, $materialId);
    $statement = $pdo->prepare('SELECT MAX(purchase_date) FROM material_purchases WHERE material_id = ?');
    $statement->execute([$materialId]);
    $lastDate = $statement->fetchColumn();
    if ($lastDate && $purchaseDate < $lastDate) {
        throw new RuntimeException('A data da compra não pode ser anterior à última compra deste material (' . date('d/m/Y', strtotime($lastDate)) . ').');
    }
}

function material_delete_unused(PDO $pdo, int $materialId): void
{
    $pdo->beginTransaction();
    try {
        material_lock($pdo, $materialId);
        $usageQueries = [
            'SELECT COUNT(*) FROM ticket_materials WHERE material_id = ?',
            "SELECT COUNT(*) FROM stock_movements WHERE material_id = ? AND (movement_type = 'saida' OR ticket_id IS NOT NULL)",
        ];
        foreach ($usageQueries as $query) {
            $statement = $pdo->prepare($query);
            $statement->execute([$materialId]);
            if ((int)$statement->fetchColumn() > 0) {
                throw new RuntimeException('Não é possível excluir este material: ele já possui venda ou uso registrado em chamado, mesmo que o item tenha sido removido ou devolvido.');
            }
        }
        purchase_payables_delete_for_material($pdo, $materialId);
        // Compra e entrada sem venda pertencem ao cadastro de teste que sera removido.
        $statement = $pdo->prepare('DELETE FROM stock_movements WHERE material_id = ?');
        $statement->execute([$materialId]);
        $statement = $pdo->prepare('DELETE FROM material_purchases WHERE material_id = ?');
        $statement->execute([$materialId]);
        $statement = $pdo->prepare('DELETE FROM materials WHERE id = ? AND deleted_at IS NULL');
        $statement->execute([$materialId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}
