<?php

require_once __DIR__ . '/../src/layout.php';

require_auth();

$pdo = db();

$receivableId =
    (int)(
        $_GET['id']
        ?? 0
    );

if (!$receivableId) {
    http_response_code(400);
    exit('Conta a receber inválida.');
}


/*
|--------------------------------------------------------------------------
| FUNÇÕES AUXILIARES
|--------------------------------------------------------------------------
*/

function waMoney($value): string
{
    return 'R$ '
        . number_format(
            (float)$value,
            2,
            ',',
            '.'
        );
}


function waDate(?string $date): string
{
    if (!$date) {
        return '';
    }

    return date(
        'd/m/Y',
        strtotime($date)
    );
}


function waPaymentMethodLabel(
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
        c.extra_visit_fee
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
    http_response_code(404);
    exit('Conta a receber não encontrada.');
}


$balance =
    max(
        0,
        (float)$receivable['total_amount']
        -
        (float)$receivable['paid_amount']
    );


/*
|--------------------------------------------------------------------------
| SERVIÇOS VINCULADOS À COMPETÊNCIA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        t.ticket_number,
        t.service_description,
        tbi.installment_number,
        tbi.installment_count,
        tbi.amount
    FROM ticket_billing_installments tbi
    INNER JOIN tickets t
        ON t.id = tbi.ticket_id
    WHERE tbi.receivable_id = ?
      AND tbi.charge_type = 'servico'
    ORDER BY
        t.ticket_number,
        tbi.installment_number
");

$stmt->execute([
    $receivableId
]);

$services =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| MATERIAIS VINCULADOS À COMPETÊNCIA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        t.id AS ticket_id,
        t.ticket_number,
        tbi.installment_number,
        tbi.installment_count,
        tbi.amount AS installment_amount
    FROM ticket_billing_installments tbi
    INNER JOIN tickets t
        ON t.id = tbi.ticket_id
    WHERE tbi.receivable_id = ?
      AND tbi.charge_type = 'materiais'
    ORDER BY
        t.ticket_number,
        tbi.installment_number
");

$stmt->execute([
    $receivableId
]);

$materialInstallments =
    $stmt->fetchAll();

$materials = [];

foreach ($materialInstallments as $installment) {

    $stmt = $pdo->prepare("
        SELECT
            description,
            quantity
        FROM ticket_materials
        WHERE ticket_id = ?
        ORDER BY id
    ");

    $stmt->execute([
        $installment['ticket_id']
    ]);

    foreach ($stmt->fetchAll() as $material) {

        $materials[] = [
            'ticket_number' =>
                $installment['ticket_number'],
            'description' =>
                $material['description'],
            'quantity' =>
                $material['quantity'],
            'installment_number' =>
                $installment['installment_number'],
            'installment_count' =>
                $installment['installment_count'],
            'installment_amount' =>
                $installment['installment_amount']
        ];
    }
}


/*
|--------------------------------------------------------------------------
| VISITAS PRESENCIAIS COBRADAS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
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

    foreach ($presentialTickets as $ticket) {

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
| PAGAMENTOS ATIVOS
|--------------------------------------------------------------------------
|
| Pagamentos estornados não entram no TXT enviado ao cliente.
|
*/

$stmt = $pdo->prepare("
    SELECT
        payment_date,
        amount,
        payment_method
    FROM payments
    WHERE receivable_id = ?
      AND reversed_at IS NULL
    ORDER BY
        payment_date,
        created_at,
        id
");

$stmt->execute([
    $receivableId
]);

$payments =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| MONTAR TXT
|--------------------------------------------------------------------------
*/

$competence =
    DateTimeImmutable::createFromFormat(
        'Y-m',
        $receivable['competence']
    );

$competenceLabel =
    $competence
        ? $competence->format('m/Y')
        : $receivable['competence'];


$lines = [];

$lines[] = 'RESUMO DE COBRANÇA de Manutenção';
$lines[] = '';

$lines[] =
    'Cliente: '
    . $receivable['client_name'];

$lines[] =
    'Competência: '
    . $competenceLabel;

$lines[] =
    'Período: '
    . waDate($receivable['cycle_start'])
    . ' a '
    . waDate($receivable['cycle_end']);

$lines[] =
    'Vencimento: '
    . waDate($receivable['due_date']);

$lines[] = '';

$lines[] =
    'Mensalidade: '
    . waMoney($receivable['monthly_fee']);

$lines[] =
    'Serviços: '
    . waMoney($receivable['services_amount']);

$lines[] =
    'Materiais: '
    . waMoney($receivable['materials_amount']);

$lines[] =
    'Visitas extras: '
    . waMoney($receivable['extra_visits_amount']);

$lines[] =
    'Saldo anterior: '
    . waMoney($receivable['previous_balance']);

$lines[] =
    'Desconto: '
    . waMoney($receivable['discount_amount']);

$lines[] = '';

$lines[] =
    'TOTAL: *'
    . waMoney($receivable['total_amount'])
    . '*';

$lines[] =
    'PAGO: *'
    . waMoney($receivable['paid_amount'])
    . '*';

$lines[] =
    'SALDO: *'
    . waMoney($balance)
    . '*';


/*
|--------------------------------------------------------------------------
| SERVIÇOS
|--------------------------------------------------------------------------
*/

if (!empty($services)) {

    $lines[] = '';
    $lines[] = 'SERVIÇOS';

    foreach ($services as $service) {

        $description =
            trim(
                (string)$service['service_description']
            );

        if ($description === '') {
            $description = 'Serviço';
        }

        $lines[] =
            $service['ticket_number']
            . ' - '
            . $description
            . ' - Parcela '
            . (int)$service['installment_number']
            . 'x'
            . (int)$service['installment_count']
            . ' - Valor: '
            . waMoney($service['amount']);
    }
}


/*
|--------------------------------------------------------------------------
| MATERIAIS
|--------------------------------------------------------------------------
*/

if (!empty($materials)) {

    $lines[] = '';
    $lines[] = 'MATERIAIS';

    foreach ($materials as $material) {

        $quantity =
            rtrim(
                rtrim(
                    number_format(
                        (float)$material['quantity'],
                        2,
                        ',',
                        '.'
                    ),
                    '0'
                ),
                ','
            );

        $lines[] =
            $material['ticket_number']
            . ' - '
            . $material['description']
            . ' - Qtd: '
            . $quantity
            . ' - Parcela '
            . (int)$material['installment_number']
            . 'x'
            . (int)$material['installment_count']
            . ' - Valor da parcela: '
            . waMoney(
                $material['installment_amount']
            );
    }
}


/*
|--------------------------------------------------------------------------
| VISITAS PRESENCIAIS
|--------------------------------------------------------------------------
*/

if (!empty($chargedVisits)) {

    $lines[] = '';
    $lines[] = 'VISITAS PRESENCIAIS';

    foreach ($chargedVisits as $visit) {

        $lines[] =
            waDate($visit['occurred_at'])
            . ' - '
            . waMoney($visit['amount']);
    }
}


/*
|--------------------------------------------------------------------------
| PAGAMENTOS
|--------------------------------------------------------------------------
*/

if (!empty($payments)) {

    $lines[] = '';
    $lines[] = 'PAGAMENTOS';

    foreach ($payments as $payment) {

        $lines[] =
            waDate($payment['payment_date'])
            . ' - '
            . waPaymentMethodLabel(
                $payment['payment_method']
            )
            . ' - '
            . waMoney($payment['amount']);
    }
}


$lines[] = '';
$lines[] =
    'Total a Pagar: *'
    . waMoney($balance)
    . '*';


$content =
    implode(
        PHP_EOL,
        $lines
    );


/*
|--------------------------------------------------------------------------
| DOWNLOAD TXT
|--------------------------------------------------------------------------
*/

$filename =
    'cobranca_'
    . preg_replace(
        '/[^A-Za-z0-9_-]+/',
        '_',
        iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $receivable['client_name']
        ) ?: 'cliente'
    )
    . '_'
    . str_replace(
        '-',
        '_',
        $receivable['competence']
    )
    . '.txt';

header(
    'Content-Type: text/plain; charset=UTF-8'
);

header(
    'Content-Disposition: attachment; filename="'
    . $filename
    . '"'
);

header(
    'X-Content-Type-Options: nosniff'
);

echo $content;
exit;
