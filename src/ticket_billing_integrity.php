<?php
declare(strict_types=1);

function ticket_billing_link_rows(PDO $pdo, int $ticketId, ?string $chargeType = null): array
{
    if (!$pdo->inTransaction()) { throw new LogicException('Conferência financeira exige transação.'); }
    $sql = 'SELECT id FROM ticket_billing_installments WHERE ticket_id = ?';
    $parameters = [$ticketId];
    if ($chargeType !== null) { $sql .= ' AND charge_type = ?'; $parameters[] = $chargeType; }
    $statement = $pdo->prepare($sql . ' ORDER BY id');$statement->execute($parameters);
    $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
    // Ticket writers hold the ticket row. Lock existing installments by primary key, avoiding empty-range locks on new tickets.
    $statement = $pdo->prepare('SELECT bi.id, bi.receivable_id, bi.due_date, t.client_id AS ticket_client, r.id AS source_id, r.client_id AS source_client, r.competence, r.paid_amount, r.closed_at FROM ticket_billing_installments bi JOIN tickets t ON t.id=bi.ticket_id LEFT JOIN receivables r ON r.id=bi.receivable_id WHERE bi.id=? FOR UPDATE');
    $rows = [];
    foreach ($ids as $id) {
        $statement->execute([$id]);$row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('As parcelas mudaram durante a edição. Reabra o chamado.'); }
        $rows[] = $row;
    }
    return $rows;
}

function ticket_billing_link_valid(array $row): bool
{
    return $row['receivable_id'] === null || ($row['source_id'] !== null
        && (int)$row['ticket_client'] === (int)$row['source_client']
        && substr($row['due_date'], 0, 7) === $row['competence']);
}

function detach_mismatched_ticket_billing(PDO $pdo, int $ticketId, string $chargeType): void
{
    $statement = $pdo->prepare('UPDATE ticket_billing_installments SET receivable_id=NULL WHERE id=? AND receivable_id=?');
    foreach (ticket_billing_link_rows($pdo, $ticketId, $chargeType) as $row) {
        if (ticket_billing_link_valid($row)) { continue; }
        if ($row['source_id'] === null || (float)$row['paid_amount'] > 0 || $row['closed_at'] !== null) {
            throw new RuntimeException('Não é possível transferir a cobrança: o vínculo financeiro precisa de revisão ou a conta de origem possui pagamento/encerramento.');
        }
        // Do not subtract from an unrelated account. The existing cycle recalculation recomputes each client's own charges.
        $statement->execute([(int)$row['id'], (int)$row['receivable_id']]);
    }
}

function assert_ticket_billing_ownership(PDO $pdo, int $ticketId): void
{
    foreach (ticket_billing_link_rows($pdo, $ticketId) as $row) {
        if (!ticket_billing_link_valid($row)) { throw new RuntimeException('A cobrança ficou vinculada a outro cliente ou competência. Nenhuma alteração foi mantida.'); }
    }
}
