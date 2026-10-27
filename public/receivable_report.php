<?php

require_once __DIR__ . '/../src/layout.php';

require_auth();
if (!role_allowed(['admin', 'financeiro'])) exit('Acesso negado.');

$pdo = db();

$receivableId =
    (int)(
        $_GET['id']
        ?? 0
    );

if (!$receivableId) {

    flash(
        'danger',
        'Conta a receber inválida.'
    );

    redirect(
        base_url(
            'receivables.php'
        )
    );
}


/*
|--------------------------------------------------------------------------
| CONTA / CLIENTE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        r.*,
        c.name AS client_name,
        c.has_contract,
        c.monthly_fee AS client_monthly_fee,
        c.extra_visit_fee,
        c.due_day
    FROM receivables r
    INNER JOIN clients c
        ON c.id = r.client_id
    WHERE r.id = ?
    LIMIT 1
");

$stmt->execute([
    $receivableId
]);

$receivable =
    $stmt->fetch();

if (!$receivable) {

    flash(
        'danger',
        'Conta a receber não encontrada.'
    );

    redirect(
        base_url(
            'receivables.php'
        )
    );
}


/*
|--------------------------------------------------------------------------
| PARCELAS / SERVIÇOS / MATERIAIS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        tbi.id,
        tbi.ticket_id,
        tbi.charge_type,
        tbi.installment_number,
        tbi.installment_count,
        tbi.due_date,
        tbi.amount,
        t.ticket_number,
        t.occurred_at,
        t.service_description
    FROM ticket_billing_installments tbi
    INNER JOIN tickets t
        ON t.id = tbi.ticket_id
    WHERE tbi.receivable_id = ?
    ORDER BY
        t.occurred_at,
        t.ticket_number,
        tbi.charge_type,
        tbi.installment_number
");

$stmt->execute([
    $receivableId
]);

$installments =
    $stmt->fetchAll();

$serviceInstallments = [];
$materialInstallments = [];

foreach ($installments as $item) {

    if ($item['charge_type'] === 'servico') {
        $serviceInstallments[] = $item;
    }

    if ($item['charge_type'] === 'materiais') {
        $materialInstallments[] = $item;
    }
}


/*
|--------------------------------------------------------------------------
| MATERIAIS DETALHADOS
|--------------------------------------------------------------------------
*/

$materialsByTicket = [];

$materialTicketIds =
    array_values(
        array_unique(
            array_map(
                static fn(array $row): int =>
                (int)$row['ticket_id'],
                $materialInstallments
            )
        )
    );

if (!empty($materialTicketIds)) {

    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($materialTicketIds),
                '?'
            )
        );

    $stmt = $pdo->prepare("
        SELECT
            tm.ticket_id,
            tm.description,
            tm.quantity,
            tm.unit_price,
            tm.total_price
        FROM ticket_materials tm
        WHERE tm.ticket_id IN ({$placeholders})
        ORDER BY
            tm.ticket_id,
            tm.id
    ");

    $stmt->execute(
        $materialTicketIds
    );

    foreach (
        $stmt->fetchAll()
        as $material
    ) {

        $materialsByTicket[(int)$material['ticket_id']][] = $material;
    }
}


/*
|--------------------------------------------------------------------------
| VISITAS PRESENCIAIS COBRADAS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        ticket_number,
        occurred_at
    FROM tickets
    WHERE client_id = ?
      AND DATE(occurred_at) BETWEEN ? AND ?
      AND deleted_at IS NULL
      AND status IN ('solucionado', 'fechado')
      AND service_type = 'presencial'
    ORDER BY occurred_at, id
");

$stmt->execute([
    $receivable['client_id'],
    $receivable['cycle_start'],
    $receivable['cycle_end']
]);

$presentialTickets =
    $stmt->fetchAll();

$chargedVisits = [];
$freeVisitUsed = false;

if ($receivable['has_contract']) {

    foreach (
        $presentialTickets
        as $ticket
    ) {

        if (!$freeVisitUsed) {
            $freeVisitUsed = true;
            continue;
        }

        $chargedVisits[] = [
            'ticket_number' =>
            $ticket['ticket_number'],
            'occurred_at' =>
            $ticket['occurred_at'],
            'amount' =>
            (float)$receivable['extra_visit_fee']
        ];
    }
}


/*
|--------------------------------------------------------------------------
| PAGAMENTOS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        p.*,
        u.name AS reversed_by_name
    FROM payments p
    LEFT JOIN users u
        ON u.id = p.reversed_by
    WHERE p.receivable_id = ?
    ORDER BY
        p.payment_date,
        p.created_at,
        p.id
");

$stmt->execute([
    $receivableId
]);

$payments =
    $stmt->fetchAll();


function reportPaymentMethodLabel(
    string $method
): string {

    return match ($method) {
        'pix' => 'PIX',
        'debito' => 'Cartão de débito',
        'credito' => 'Cartão de crédito',
        'dinheiro' => 'Dinheiro',
        'boleto' => 'Boleto',
        'transferencia' => 'Transferência bancária',
        default => ucfirst($method),
    };
}


$balance =
    max(
        0,
        (float)$receivable['total_amount']
            -
            (float)$receivable['paid_amount']
    );


render_header(
    'Relatório da Cobrança'
);

?>

<style>
    @media print {

        .no-print,
        nav,
        .navbar,
        footer {
            display: none !important;
        }

        body {
            background: #fff !important;
        }

        .container,
        .container-fluid {
            max-width: 100% !important;
            width: 100% !important;
        }

        /*
        |--------------------------------------------------------------------------
        | EVITAR QUE O TÍTULO DE UMA SEÇÃO FIQUE ISOLADO
        |--------------------------------------------------------------------------
        |
        | Se a seção couber inteira na próxima página, o navegador tentará
        | movê-la para lá. Caso a seção seja maior do que uma página inteira,
        | ela naturalmente precisará ser quebrada.
        |
        */

        .report-section {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .card {
            border: 1px solid #bbb !important;
            box-shadow: none !important;
        }

        .card-header {
            break-after: avoid;
            page-break-after: avoid;
        }

        table {
            break-inside: auto;
            page-break-inside: auto;
        }

        thead {
            display: table-header-group;
        }

        tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        /*
        |--------------------------------------------------------------------------
        | CINCO INDICADORES FINANCEIROS NA MESMA LINHA
        |--------------------------------------------------------------------------
        */

        .report-summary-grid {
            display: grid !important;
            grid-template-columns: repeat(5, 1fr) !important;
            gap: 8px !important;
        }

        .report-summary-grid>div {
            width: auto !important;
            max-width: none !important;
            flex: none !important;
        }
    }
</style>


<div class="d-flex justify-content-between align-items-center mb-3 no-print">

    <div>
        <a
            href="<?= e(base_url('receivables.php')) ?>"
            class="btn btn-outline-secondary">
            Voltar
        </a>
    </div>

    <div class="d-flex gap-2">

        <a
            class="btn btn-success"
            href="<?= e(
                        base_url(
                            'receivable_report_wa.php?id='
                                . (int)$receivableId
                        )
                    ) ?>">
            Impressão WA
        </a>

        <button
            type="button"
            class="btn btn-primary"
            onclick="window.print()">
            Imprimir
        </button>

    </div>

</div>


<div class="card mb-3 report-section">

    <div class="card-body">

        <div class="row g-3">

            <div class="col-md-6">

                <h4 class="mb-3">
                    <?= e($receivable['client_name']) ?>
                </h4>

                <div>
                    <strong>Competência:</strong>
                    <?= e($receivable['competence']) ?>
                </div>

                <div>
                    <strong>Período:</strong>
                    <?= date('d/m/Y', strtotime($receivable['cycle_start'])) ?>
                    até
                    <?= date('d/m/Y', strtotime($receivable['cycle_end'])) ?>
                </div>

                <div>
                    <strong>Vencimento:</strong>
                    <?= date('d/m/Y', strtotime($receivable['due_date'])) ?>
                </div>

            </div>


            <div class="col-md-6">

                <div class="row g-2 report-summary-grid">

                    <div class="col-6">
                        <div class="border rounded p-2 h-100">
                            <div class="small text-muted">Total</div>
                            <div class="fw-bold fs-5">
                                <?= money($receivable['total_amount']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-6">
                        <div class="border rounded p-2 h-100">
                            <div class="small text-muted">Desconto</div>
                            <div class="fw-bold fs-5">
                                <?= money($receivable['discount_amount']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-6">
                        <div class="border rounded p-2 h-100">
                            <div class="small text-muted">Pago</div>
                            <div class="fw-bold fs-5">
                                <?= money($receivable['paid_amount']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-6">
                        <div class="border rounded p-2 h-100">
                            <div class="small text-muted">Saldo</div>
                            <div class="fw-bold fs-5">
                                <?= money($balance) ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-6">
                        <div class="border rounded p-2 h-100">
                            <div class="small text-muted">Status</div>
                            <div class="fw-bold fs-5 text-capitalize">
                                <?= e($receivable['status']) ?>
                            </div>
                        </div>
                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


<div class="card mb-3 report-section">

    <div class="card-header fw-bold">
        Resumo da cobrança
    </div>

    <div class="card-body">

        <div class="table-responsive">

            <table class="table table-sm mb-0">

                <tbody>

                    <tr>
                        <td>Mensalidade</td>
                        <td class="text-end">
                            <?= money($receivable['monthly_fee']) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Serviços</td>
                        <td class="text-end">
                            <?= money($receivable['services_amount']) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Materiais</td>
                        <td class="text-end">
                            <?= money($receivable['materials_amount']) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Visitas presenciais extras</td>
                        <td class="text-end">
                            <?= money($receivable['extra_visits_amount']) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Saldo anterior</td>
                        <td class="text-end">
                            <?= money($receivable['previous_balance']) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Descontos</td>
                        <td class="text-end">
                            - <?= money($receivable['discount_amount']) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Adiantamentos</td>
                        <td class="text-end">
                            - <?= money($receivable['advances_amount']) ?>
                        </td>
                    </tr>

                    <tr class="table-light fw-bold">
                        <td>Total da cobrança</td>
                        <td class="text-end">
                            <?= money($receivable['total_amount']) ?>
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

    </div>

</div>


<div class="card mb-3 report-section">

    <div class="card-header fw-bold">
        Serviços
    </div>

    <div class="card-body">

        <?php if (empty($serviceInstallments)): ?>

            <div class="text-muted">
                Nenhum serviço parcelado vinculado a esta cobrança.
            </div>

        <?php else: ?>

            <div class="table-responsive">

                <table class="table table-sm align-middle">

                    <thead>
                        <tr>
                            <th>Chamado</th>
                            <th>Data</th>
                            <th>Descrição</th>
                            <th>Parcela</th>
                            <th>Vencimento</th>
                            <th class="text-end">Valor</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($serviceInstallments as $item): ?>

                            <tr>

                                <td>
                                    <?= e($item['ticket_number']) ?>
                                </td>

                                <td>
                                    <?= date(
                                        'd/m/Y',
                                        strtotime($item['occurred_at'])
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $item['service_description']
                                            ?: 'Serviço sem descrição'
                                    ) ?>
                                </td>

                                <td>
                                    <?= (int)$item['installment_number'] ?>
                                    x
                                    <?= (int)$item['installment_count'] ?>
                                </td>

                                <td>
                                    <?= date(
                                        'd/m/Y',
                                        strtotime($item['due_date'])
                                    ) ?>
                                </td>

                                <td class="text-end">
                                    <?= money($item['amount']) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>


<div class="card mb-3 report-section">

    <div class="card-header fw-bold">
        Materiais
    </div>

    <div class="card-body">

        <?php if (empty($materialInstallments)): ?>

            <div class="text-muted">
                Nenhum material parcelado vinculado a esta cobrança.
            </div>

        <?php else: ?>

            <?php foreach ($materialInstallments as $item): ?>

                <div class="border rounded p-3 mb-3">

                    <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">

                        <div>
                            <strong>Chamado:</strong>
                            <?= e($item['ticket_number']) ?>
                        </div>

                        <div>
                            <strong>Parcela:</strong>
                            <?= (int)$item['installment_number'] ?>
                            x
                            <?= (int)$item['installment_count'] ?>

                            &nbsp;|&nbsp;

                            <strong>Valor da parcela:</strong>
                            <?= money($item['amount']) ?>
                        </div>

                    </div>

                    <?php
                    $ticketMaterials =
                        $materialsByTicket[(int)$item['ticket_id']]
                        ?? [];
                    ?>

                    <?php if (empty($ticketMaterials)): ?>

                        <div class="text-muted">
                            Nenhum item de material encontrado neste chamado.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-sm mb-0">

                                <thead>
                                    <tr>
                                        <th>Material</th>
                                        <th class="text-end">Qtd.</th>
                                        <th class="text-end">Unitário</th>
                                        <th class="text-end">Total do item</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($ticketMaterials as $material): ?>

                                        <tr>
                                            <td>
                                                <?= e($material['description']) ?>
                                            </td>
                                            <td class="text-end">
                                                <?= e($material['quantity']) ?>
                                            </td>
                                            <td class="text-end">
                                                <?= money($material['unit_price']) ?>
                                            </td>
                                            <td class="text-end">
                                                <?= money($material['total_price']) ?>
                                            </td>
                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>


<div class="card mb-3 report-section">

    <div class="card-header fw-bold">
        Visitas presenciais cobradas
    </div>

    <div class="card-body">

        <?php if (empty($chargedVisits)): ?>

            <div class="text-muted">
                Nenhuma visita presencial extra cobrada nesta competência.
            </div>

        <?php else: ?>

            <div class="table-responsive">

                <table class="table table-sm">

                    <thead>
                        <tr>
                            <th>Chamado</th>
                            <th>Data</th>
                            <th class="text-end">Valor</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($chargedVisits as $visit): ?>

                            <tr>
                                <td>
                                    <?= e($visit['ticket_number']) ?>
                                </td>
                                <td>
                                    <?= date(
                                        'd/m/Y',
                                        strtotime($visit['occurred_at'])
                                    ) ?>
                                </td>
                                <td class="text-end">
                                    <?= money($visit['amount']) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>


<div class="card mb-3 report-section">

    <div class="card-header fw-bold">
        Pagamentos
    </div>

    <div class="card-body">

        <?php if (empty($payments)): ?>

            <div class="text-muted">
                Nenhum pagamento registrado nesta cobrança.
            </div>

        <?php else: ?>

            <div class="table-responsive">

                <table class="table table-sm align-middle">

                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Forma</th>
                            <th>Observação</th>
                            <th>Status</th>
                            <th class="text-end">Valor</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($payments as $payment): ?>

                            <tr>

                                <td>
                                    <?= date(
                                        'd/m/Y',
                                        strtotime($payment['payment_date'])
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        reportPaymentMethodLabel(
                                            $payment['payment_method']
                                        )
                                    ) ?>
                                </td>

                                <td>

                                    <?= nl2br(
                                        e(
                                            $payment['notes']
                                                ?? ''
                                        )
                                    ) ?>

                                    <?php if (!empty($payment['reversed_at'])): ?>

                                        <div class="small text-danger mt-1">
                                            Estornado em
                                            <?= date(
                                                'd/m/Y H:i',
                                                strtotime($payment['reversed_at'])
                                            ) ?>

                                            <?php if (!empty($payment['reversed_by_name'])): ?>
                                                por
                                                <?= e($payment['reversed_by_name']) ?>
                                            <?php endif; ?>

                                            —
                                            <?= e($payment['reversal_reason'] ?? '') ?>
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?php if (!empty($payment['reversed_at'])): ?>
                                        <span class="badge bg-danger">Estornado</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Ativo</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-end">
                                    <?= money($payment['amount']) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>


<?php render_footer(); ?>