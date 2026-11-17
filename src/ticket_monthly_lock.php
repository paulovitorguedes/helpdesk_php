<?php
declare(strict_types=1);

function ticket_financial_today(): DateTimeImmutable
{
    return new DateTimeImmutable('today');
}

/** Resolve only existing financial bindings; never derive a competence from the opening month. */
function ticket_monthly_lock_reason(PDO $pdo, int $ticketId, ?DateTimeImmutable $today = null, bool $lock = false): ?string
{
    if ($ticketId <= 0) return null;
    if ($lock && !$pdo->inTransaction()) throw new LogicException('Conferência exige transação.');
    $suffix = $lock ? ' FOR UPDATE' : '';
    $statement = $pdo->prepare('SELECT client_id,status,occurred_at FROM tickets WHERE id=? AND deleted_at IS NULL'.$suffix);
    $statement->execute([$ticketId]);$ticket=$statement->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) return null;
    $statement=$pdo->prepare('SELECT b.due_date,b.receivable_id,r.id AS source_id,r.client_id,r.competence,r.closed_at FROM ticket_billing_installments b LEFT JOIN receivables r ON r.id=b.receivable_id WHERE b.ticket_id=? ORDER BY b.receivable_id,b.id'.$suffix);
    $statement->execute([$ticketId]);$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
    $competences=[];$closed=false;
    foreach ($rows as $row) {
        // A draft schedule in an open/pending call is not an invoiced financial competence.
        if ($row['receivable_id'] === null && !in_array($ticket['status'],['fechado','solucionado'],true)) continue;
        $planned=substr((string)$row['due_date'],0,7);
        if ($row['receivable_id'] !== null) {
            if ($row['source_id'] === null || (int)$row['client_id'] !== (int)$ticket['client_id'] || $planned !== $row['competence']) {
                return 'O vínculo da competência precisa de conferência antes de alterar dados financeiros ou materiais.';
            }
            $competences[]=(string)$row['competence'];
            $closed=$closed || $row['closed_at'] !== null;
        } else {
            // The installment already records its planned financial month; do not replace it with a new calculation.
            $competences[]=$planned;
        }
    }
    if (!$rows && in_array($ticket['status'],['fechado','solucionado'],true)) {
        // Same membership used by the existing financial screen for visits without their own installments.
        $statement=$pdo->prepare('SELECT competence,closed_at FROM receivables WHERE client_id=? AND DATE(?) BETWEEN cycle_start AND cycle_end ORDER BY id'.$suffix);
        $statement->execute([(int)$ticket['client_id'],$ticket['occurred_at']]);$cycles=$statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($cycles)!==1) return 'A competência deste chamado precisa de conferência antes de alterar dados financeiros ou materiais.';
        $competences[]=(string)$cycles[0]['competence'];$closed=$cycles[0]['closed_at'] !== null;
    }
    foreach ($competences as $competence) {
        if (!preg_match('/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])$/D',$competence)) return 'A competência deste chamado precisa de conferência antes de alterar dados financeiros ou materiais.';
    }
    if ($closed) return 'A competência financeira está encerrada. Somente Reclamante, Solicitação e Solução aplicada podem ser alterados.';
    sort($competences,SORT_STRING);
    if ($competences && $competences[0] < ($today ?? ticket_financial_today())->format('Y-m')) {
        return 'A competência '.substr($competences[0],5,2).'/'.substr($competences[0],0,4).' está bloqueada pela virada mensal. Somente Reclamante, Solicitação e Solução aplicada podem ser alterados.';
    }
    return null;
}

/** Caller holds the ticket and financial rows; no schedule, stock or receivable write is performed. */
function ticket_save_locked_text(PDO $pdo, int $ticketId, array $input): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Edição textual exige transação.');
    $statement=$pdo->prepare('SELECT complainant,request_text,solution_text FROM tickets WHERE id=? AND deleted_at IS NULL FOR UPDATE');
    $statement->execute([$ticketId]);$old=$statement->fetch(PDO::FETCH_ASSOC);
    if (!$old) throw new RuntimeException('Chamado não encontrado.');
    $values=[];
    foreach (['complainant','request_text','solution_text'] as $field) {
        if (!array_key_exists($field,$input)) { $values[$field]=$old[$field];continue; }
        $raw=$input[$field];
        if (!is_string($raw)) throw new InvalidArgumentException('Informe textos válidos para o chamado.');
        $values[$field]=trim($raw);
    }
    if (trim((string)$values['request_text'])==='') throw new InvalidArgumentException('Informe a solicitação.');
    if (mb_strlen((string)$values['complainant'])>190) throw new InvalidArgumentException('O Reclamante deve ter até 190 caracteres.');
    $statement=$pdo->prepare('UPDATE tickets SET complainant=?,request_text=?,solution_text=? WHERE id=?');
    $statement->execute([$values['complainant'],$values['request_text'],$values['solution_text'],$ticketId]);
}
