<?php

require_once __DIR__ . '/../src/layout.php';

require_auth();

$pdo = db();


/*
|--------------------------------------------------------------------------
| AUXILIARES
|--------------------------------------------------------------------------
*/

function decimalValue($value): float
{
    $value = trim((string)$value);
    $value = str_replace(',', '.', $value);

    return (float)$value;
}


function generateTicketNumber(
    PDO $pdo,
    string $occurredAt
): string {

    $date = date(
        'Y-m-d',
        strtotime($occurredAt)
    );

    $stmt = $pdo->prepare("
        SELECT last_number
        FROM ticket_daily_sequences
        WHERE sequence_date = ?
    ");

    $stmt->execute([$date]);

    $lastNumber = $stmt->fetchColumn();


    if ($lastNumber === false) {

        $nextNumber = 1;

        $stmt = $pdo->prepare("
            INSERT INTO ticket_daily_sequences (
                sequence_date,
                last_number
            )
            VALUES (?, ?)
        ");

        $stmt->execute([
            $date,
            $nextNumber
        ]);
    } else {

        $nextNumber =
            ((int)$lastNumber) + 1;

        $stmt = $pdo->prepare("
            UPDATE ticket_daily_sequences
            SET last_number = ?
            WHERE sequence_date = ?
        ");

        $stmt->execute([
            $nextNumber,
            $date
        ]);
    }


    return
        date(
            'ymd',
            strtotime($occurredAt)
        )
        . '-'
        . str_pad(
            $nextNumber,
            2,
            '0',
            STR_PAD_LEFT
        );
}


function ticketFilterUrl(
    string $status,
    int $clientId,
    string $dateStart,
    string $dateEnd
): string {

    $params = [];


    if ($status !== '') {
        $params['status'] = $status;
    }


    if ($clientId > 0) {
        $params['client_id'] = $clientId;
    }


    if ($dateStart !== '') {
        $params['date_start'] = $dateStart;
    }


    if ($dateEnd !== '') {
        $params['date_end'] = $dateEnd;
    }


    $url = base_url('tickets.php');


    if ($params) {

        $url .= '?'
            . http_build_query($params);
    }


    return $url;
}


/*
|--------------------------------------------------------------------------
| TRATAR MATERIAIS RECEBIDOS
|--------------------------------------------------------------------------
*/

function normalizePostedMaterials(
    array $postedMaterials
): array {

    $items = [];


    foreach (
        $postedMaterials
        as $postedMaterial
    ) {

        $materialId =
            (int)(
                $postedMaterial['material_id']
                ?? 0
            );


        $quantity =
            decimalValue(
                $postedMaterial['quantity']
                    ?? 0
            );


        $unitPrice =
            decimalValue(
                $postedMaterial['unit_price']
                    ?? 0
            );


        /*
        |--------------------------------------------------------------------------
        | LINHA COMPLETAMENTE VAZIA
        |--------------------------------------------------------------------------
        */

        if (
            $materialId <= 0
            &&
            $quantity <= 0
            &&
            $unitPrice <= 0
        ) {

            continue;
        }


        if ($materialId <= 0) {

            throw new Exception(
                'Selecione o material em todas as linhas preenchidas.'
            );
        }


        if ($quantity <= 0) {

            throw new Exception(
                'Informe uma quantidade maior que zero para todos os materiais.'
            );
        }


        if ($unitPrice < 0) {

            throw new Exception(
                'O valor unitário não pode ser negativo.'
            );
        }


        $items[] = [

            'material_id' =>
            $materialId,

            'quantity' =>
            $quantity,

            'unit_price' =>
            $unitPrice

        ];
    }


    return $items;
}


/*
|--------------------------------------------------------------------------
| SOMAR QUANTIDADE POR MATERIAL
|--------------------------------------------------------------------------
*/

function aggregateMaterialQuantities(
    array $items
): array {

    $totals = [];


    foreach ($items as $item) {

        $materialId =
            (int)$item['material_id'];


        if (
            !isset(
                $totals[$materialId]
            )
        ) {

            $totals[$materialId] = 0;
        }


        $totals[$materialId] +=
            (float)$item['quantity'];
    }


    return $totals;
}


/*
|--------------------------------------------------------------------------
| ESTOQUE ATUAL
|--------------------------------------------------------------------------
*/

function currentMaterialStock(
    PDO $pdo,
    int $materialId
): float {

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(
                SUM(
                    CASE

                        WHEN movement_type = 'entrada'
                            THEN quantity

                        WHEN movement_type = 'saida'
                            THEN -quantity

                        WHEN movement_type = 'ajuste'
                            THEN quantity

                        ELSE 0

                    END
                ),
                0
            )
        FROM stock_movements
        WHERE material_id = ?
    ");


    $stmt->execute([
        $materialId
    ]);


    return
        (float)$stmt->fetchColumn();
}



/*
|--------------------------------------------------------------------------
| TOTAL DOS MATERIAIS
|--------------------------------------------------------------------------
*/

function materialItemsTotal(array $items): float
{
    $total = 0;

    foreach ($items as $item) {
        $total +=
            (float)$item['quantity']
            *
            (float)$item['unit_price'];
    }

    return round($total, 2);
}


/*
|--------------------------------------------------------------------------
| GERAR CRONOGRAMA DE PARCELAS
|--------------------------------------------------------------------------
|
| Regra do ciclo financeiro:
|
| dia 01 até 15  -> competência do próprio mês
| dia 16 em diante -> competência do mês seguinte
|
| O vencimento usa o dia configurado no cliente.
|--------------------------------------------------------------------------
*/

function buildBillingSchedule(
    string $occurredAt,
    int $dueDay,
    float $totalAmount,
    int $installmentCount
): array {

    if ($totalAmount <= 0) {
        return [];
    }

    if ($installmentCount < 1) {
        $installmentCount = 1;
    }

    $dueDay = max(1, min($dueDay, 28));

    $occurred =
        new DateTimeImmutable(
            date(
                'Y-m-d',
                strtotime($occurredAt)
            )
        );

    if ((int)$occurred->format('d') >= 16) {
        $firstMonth =
            $occurred
            ->modify('first day of next month');
    } else {
        $firstMonth =
            $occurred
            ->modify('first day of this month');
    }

    /*
    |--------------------------------------------------------------------------
    | DIVIDIR EM CENTAVOS
    |--------------------------------------------------------------------------
    |
    | Isso evita diferença de arredondamento.
    | Ex.: R$ 100,00 em 3x = 33,34 + 33,33 + 33,33
    |--------------------------------------------------------------------------
    */

    $totalCents =
        (int)round(
            $totalAmount * 100
        );

    $baseCents =
        intdiv(
            $totalCents,
            $installmentCount
        );

    $remainder =
        $totalCents
        %
        $installmentCount;

    $schedule = [];

    for (
        $number = 1;
        $number <= $installmentCount;
        $number++
    ) {

        $month =
            $firstMonth
            ->modify(
                '+'
                    . ($number - 1)
                    . ' month'
            );

        $dueDate =
            $month
            ->setDate(
                (int)$month->format('Y'),
                (int)$month->format('m'),
                $dueDay
            )
            ->format('Y-m-d');

        $cents =
            $baseCents
            +
            (
                $number <= $remainder
                ? 1
                : 0
            );

        $schedule[] = [
            'installment_number' =>
            $number,

            'installment_count' =>
            $installmentCount,

            'due_date' =>
            $dueDate,

            'amount' =>
            $cents / 100
        ];
    }

    return $schedule;
}


/*
|--------------------------------------------------------------------------
| COMPARAR CRONOGRAMAS
|--------------------------------------------------------------------------
*/

function billingSchedulesAreEqual(
    array $currentRows,
    array $newSchedule
): bool {

    if (
        count($currentRows)
        !==
        count($newSchedule)
    ) {
        return false;
    }

    foreach (
        $currentRows
        as $index => $current
    ) {

        $new =
            $newSchedule[$index]
            ?? null;

        if (!$new) {
            return false;
        }

        if (
            (int)$current['installment_number']
            !==
            (int)$new['installment_number']
            ||
            (int)$current['installment_count']
            !==
            (int)$new['installment_count']
            ||
            $current['due_date']
            !==
            $new['due_date']
            ||
            abs(
                (float)$current['amount']
                    -
                    (float)$new['amount']
            ) > 0.009
        ) {
            return false;
        }
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| SALVAR CRONOGRAMA DE COBRANÇA
|--------------------------------------------------------------------------
|
| Se alguma parcela já estiver vinculada a uma conta a receber,
| não permitimos alterar o cronograma financeiro.
|--------------------------------------------------------------------------
*/

function saveBillingSchedule(
    PDO $pdo,
    int $ticketId,
    string $chargeType,
    array $newSchedule
): void {

    $stmt = $pdo->prepare("
        SELECT
            id,
            installment_number,
            installment_count,
            due_date,
            amount,
            receivable_id
        FROM ticket_billing_installments
        WHERE ticket_id = ?
          AND charge_type = ?
        ORDER BY installment_number
    ");

    $stmt->execute([
        $ticketId,
        $chargeType
    ]);

    $currentRows =
        $stmt->fetchAll();

    $hasLinkedInstallment = false;

    foreach ($currentRows as $row) {

        if (
            !empty($row['receivable_id'])
        ) {
            $hasLinkedInstallment = true;
            break;
        }
    }

    if ($hasLinkedInstallment) {

        if (
            !billingSchedulesAreEqual(
                $currentRows,
                $newSchedule
            )
        ) {

            throw new Exception(
                'O parcelamento de '
                    . (
                        $chargeType === 'servico'
                        ? 'serviço'
                        : 'materiais'
                    )
                    . ' já possui parcela vinculada ao Contas a Receber e não pode ser alterado diretamente.'
            );
        }

        return;
    }

    $stmt = $pdo->prepare("
        DELETE FROM ticket_billing_installments
        WHERE ticket_id = ?
          AND charge_type = ?
    ");

    $stmt->execute([
        $ticketId,
        $chargeType
    ]);

    if (empty($newSchedule)) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO ticket_billing_installments (
            ticket_id,
            charge_type,
            installment_number,
            installment_count,
            due_date,
            amount
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($newSchedule as $installment) {

        $stmt->execute([
            $ticketId,
            $chargeType,
            $installment['installment_number'],
            $installment['installment_count'],
            $installment['due_date'],
            $installment['amount']
        ]);
    }
}


/*
|--------------------------------------------------------------------------
| MODO EDITAR
|--------------------------------------------------------------------------
*/

$editTicket = null;
$editTicketMaterials = [];

$editServiceInstallments = 1;
$editMaterialInstallments = 1;


if (isset($_GET['edit'])) {

    $editId =
        (int)$_GET['edit'];


    $stmt = $pdo->prepare("
        SELECT *
        FROM tickets
        WHERE id = ?
          AND deleted_at IS NULL
    ");

    $stmt->execute([
        $editId
    ]);


    $editTicket =
        $stmt->fetch();


    if (!$editTicket) {

        flash(
            'danger',
            'Chamado não encontrado.'
        );

        redirect(
            base_url('tickets.php')
        );
    }


    /*
    |--------------------------------------------------------------------------
    | MATERIAIS ATUAIS DO CHAMADO
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            material_id,
            description,
            quantity,
            unit_price,
            total_price
        FROM ticket_materials
        WHERE ticket_id = ?
        ORDER BY id
    ");

    $stmt->execute([
        $editId
    ]);


    $editTicketMaterials =
        $stmt->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | PARCELAMENTO ATUAL
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            charge_type,
            MAX(installment_count) AS installment_count
        FROM ticket_billing_installments
        WHERE ticket_id = ?
        GROUP BY charge_type
    ");

    $stmt->execute([
        $editId
    ]);

    foreach (
        $stmt->fetchAll()
        as $billingRow
    ) {

        if (
            $billingRow['charge_type']
            === 'servico'
        ) {

            $editServiceInstallments =
                max(
                    1,
                    (int)$billingRow['installment_count']
                );
        }

        if (
            $billingRow['charge_type']
            === 'materiais'
        ) {

            $editMaterialInstallments =
                max(
                    1,
                    (int)$billingRow['installment_count']
                );
        }
    }
}


/*
|--------------------------------------------------------------------------
| PROCESSAMENTO POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();


    /*
    |--------------------------------------------------------------------------
    | DADOS PRINCIPAIS
    |--------------------------------------------------------------------------
    */

    $id =
        (int)(
            $_POST['id']
            ?? 0
        );


    $client =
        (int)(
            $_POST['client_id']
            ?? 0
        );


    $occurredAt =
        $_POST['occurred_at']
        ?? '';


    $complainant =
        trim(
            $_POST['complainant']
                ?? ''
        );


    $requestText =
        trim(
            $_POST['request_text']
                ?? ''
        );


    $type =
        $_POST['service_type']
        ?? 'remoto';


    $status =
        $_POST['status']
        ?? 'aberto';


    $solutionText =
        trim(
            $_POST['solution_text']
                ?? ''
        );


    $serviceDescription =
        trim(
            $_POST['service_description']
                ?? ''
        );


    $serviceBillingMode =
        $_POST['service_billing_mode']
        ?? 'avista';


    $serviceInstallments =
        (int)(
            $_POST['service_installments']
            ?? 1
        );


    $materialBillingMode =
        $_POST['material_billing_mode']
        ?? 'avista';


    $materialInstallments =
        (int)(
            $_POST['material_installments']
            ?? 1
        );


    $notes =
        trim(
            $_POST['notes']
                ?? ''
        );


    $charged =
        isset(
            $_POST['service_charged']
        )
        ? 1
        : 0;


    $amount =
        decimalValue(
            $_POST['service_amount']
                ?? 0
        );


    /*
    |--------------------------------------------------------------------------
    | VALIDAR COBRANÇA DO SERVIÇO
    |--------------------------------------------------------------------------
    |
    | Se existe valor de serviço, a cobrança deve estar marcada.
    |
    */

    if (
        !$charged
        &&
        $amount > 0
        &&
        $status !== 'cancelado'
    ) {

        flash(
            'danger',
            'Existe um valor de serviço informado. Para remover a cobrança, primeiro altere o Valor serviço para R$ 0,00.'
        );


        redirect(
            $id
                ? base_url(
                    'tickets.php?edit='
                        . $id
                )
                : base_url(
                    'tickets.php'
                )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES
    |--------------------------------------------------------------------------
    */

    if (!$client) {

        flash(
            'danger',
            'Selecione um cliente.'
        );

        redirect(
            base_url('tickets.php')
        );
    }


    if ($occurredAt === '') {

        flash(
            'danger',
            'Informe a data e o horário.'
        );

        redirect(
            base_url('tickets.php')
        );
    }


    if ($requestText === '') {

        flash(
            'danger',
            'Informe a solicitação.'
        );

        redirect(
            base_url('tickets.php')
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR TIPO
    |--------------------------------------------------------------------------
    */

    $allowedTypes = [

        'remoto',
        'presencial',
        'preventiva',
        'vistoria',
        'orcamento'

    ];


    if (
        !in_array(
            $type,
            $allowedTypes,
            true
        )
    ) {

        $type = 'remoto';
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR STATUS
    |--------------------------------------------------------------------------
    */

    $allowedStatuses = [

        'aberto',
        'pendente',
        'solucionado',
        'fechado',
        'cancelado'

    ];


    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {

        $status = 'aberto';
    }

    /*
|--------------------------------------------------------------------------
| BLOQUEAR MAIS DE UM CHAMADO PRESENCIAL PENDENTE
| PARA O MESMO CLIENTE
|--------------------------------------------------------------------------
*/

    if (
        $type === 'presencial'
        &&
        $status === 'pendente'
    ) {

        if ($id) {

            /*
        |--------------------------------------------------------------------------
        | EDIÇÃO
        |--------------------------------------------------------------------------
        |
        | Ignora o próprio chamado que está sendo editado.
        |
        */

            $stmt = $pdo->prepare("
            SELECT
                id,
                ticket_number
            FROM tickets
            WHERE client_id = ?
              AND service_type = 'presencial'
              AND status = 'pendente'
              AND deleted_at IS NULL
              AND id <> ?
            LIMIT 1
        ");

            $stmt->execute([
                $client,
                $id
            ]);
        } else {

            /*
        |--------------------------------------------------------------------------
        | NOVO CHAMADO
        |--------------------------------------------------------------------------
        */

            $stmt = $pdo->prepare("
            SELECT
                id,
                ticket_number
            FROM tickets
            WHERE client_id = ?
              AND service_type = 'presencial'
              AND status = 'pendente'
              AND deleted_at IS NULL
            LIMIT 1
        ");

            $stmt->execute([
                $client
            ]);
        }


        $existingPendingTicket =
            $stmt->fetch();


        if ($existingPendingTicket) {

            flash(
                'danger',
                'Já existe um chamado presencial pendente para este cliente. Chamado: '
                    . $existingPendingTicket['ticket_number']
                    . '.'
            );


            redirect(
                $id
                    ? base_url(
                        'tickets.php?edit='
                            . $id
                    )
                    : base_url(
                        'tickets.php'
                    )
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CONTRATO
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            has_contract,
            due_day
        FROM clients
        WHERE id = ?
    ");

    $stmt->execute([
        $client
    ]);


    $clientFinancial =
        $stmt->fetch();


    if (!$clientFinancial) {

        flash(
            'danger',
            'Cliente não encontrado.'
        );

        redirect(
            base_url('tickets.php')
        );
    }


    $hasContract =
        (int)$clientFinancial['has_contract'];


    $clientDueDay =
        max(
            1,
            min(
                (int)$clientFinancial['due_day'],
                28
            )
        );


    /*
    |--------------------------------------------------------------------------
    | REGRAS DE COBRANÇA
    |--------------------------------------------------------------------------
    */

    if (
        (
            $hasContract
            &&
            $type === 'remoto'
        )
        ||
        in_array(
            $type,
            [
                'vistoria',
                'orcamento'
            ],
            true
        )
    ) {

        $charged = 0;
        $amount = 0;
    }


    /*
    |--------------------------------------------------------------------------
    | CANCELADO NÃO É FATURÁVEL
    |--------------------------------------------------------------------------
    |
    | Preservamos o valor informado no banco.
    |
    | Apenas service_charged passa a zero.
    |
    | Assim não destruímos informação histórica.
    |
    */

    if ($status === 'cancelado') {

        $charged = 0;
    }


    /*
    |--------------------------------------------------------------------------
    | REGRAS DO PARCELAMENTO DO SERVIÇO
    |--------------------------------------------------------------------------
    */

    if (
        !in_array(
            $serviceBillingMode,
            [
                'avista',
                'parcelado'
            ],
            true
        )
    ) {

        $serviceBillingMode =
            'avista';
    }


    if (
        $serviceBillingMode === 'avista'
    ) {

        $serviceInstallments = 1;
    }


    if (
        $serviceInstallments < 1
        ||
        $serviceInstallments > 60
    ) {

        flash(
            'danger',
            'A quantidade de parcelas do serviço deve estar entre 1 e 60.'
        );

        redirect(
            $id
                ? base_url(
                    'tickets.php?edit='
                        . $id
                )
                : base_url(
                    'tickets.php'
                )
        );
    }


    if (
        $charged
        &&
        $amount > 0
        &&
        $serviceDescription === ''
    ) {

        flash(
            'danger',
            'Informe a descrição do serviço cobrado.'
        );

        redirect(
            $id
                ? base_url(
                    'tickets.php?edit='
                        . $id
                )
                : base_url(
                    'tickets.php'
                )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | REGRAS DO PARCELAMENTO DOS MATERIAIS
    |--------------------------------------------------------------------------
    */

    if (
        !in_array(
            $materialBillingMode,
            [
                'avista',
                'parcelado'
            ],
            true
        )
    ) {

        $materialBillingMode =
            'avista';
    }


    if (
        $materialBillingMode === 'avista'
    ) {

        $materialInstallments = 1;
    }


    if (
        $materialInstallments < 1
        ||
        $materialInstallments > 60
    ) {

        flash(
            'danger',
            'A quantidade de parcelas dos materiais deve estar entre 1 e 60.'
        );

        redirect(
            $id
                ? base_url(
                    'tickets.php?edit='
                        . $id
                )
                : base_url(
                    'tickets.php'
                )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | MATERIAIS POSTADOS
    |--------------------------------------------------------------------------
    */

    try {

        $newMaterialItems =
            normalizePostedMaterials(
                $_POST['materials']
                    ?? []
            );
    } catch (Throwable $e) {

        flash(
            'danger',
            $e->getMessage()
        );


        redirect(
            $id
                ? base_url(
                    'tickets.php?edit='
                        . $id
                )
                : base_url(
                    'tickets.php'
                )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | TOTAL DA VENDA DE MATERIAIS
    |--------------------------------------------------------------------------
    */

    $newMaterialsAmount =
        materialItemsTotal(
            $newMaterialItems
        );


    if (
        empty($newMaterialItems)
        ||
        $newMaterialsAmount <= 0
    ) {

        $materialBillingMode =
            'avista';

        $materialInstallments = 1;
    }


    /*
    |--------------------------------------------------------------------------
    | CRONOGRAMAS FINANCEIROS
    |--------------------------------------------------------------------------
    */

    $serviceSchedule = [];

    if (
        $status !== 'cancelado'
        &&
        $charged
        &&
        $amount > 0
    ) {

        $serviceSchedule =
            buildBillingSchedule(
                $occurredAt,
                $clientDueDay,
                $amount,
                $serviceInstallments
            );
    }


    $materialSchedule = [];

    if (
        $status !== 'cancelado'
        &&
        $newMaterialsAmount > 0
    ) {

        $materialSchedule =
            buildBillingSchedule(
                $occurredAt,
                $clientDueDay,
                $newMaterialsAmount,
                $materialInstallments
            );
    }


    /*
    |--------------------------------------------------------------------------
    | TRANSAÇÃO
    |--------------------------------------------------------------------------
    */

    try {

        $pdo->beginTransaction();


        /*
        |--------------------------------------------------------------------------
        | NOVO CHAMADO
        |--------------------------------------------------------------------------
        */

        if (!$id) {

            /*
            |--------------------------------------------------------------------------
            | NOVO CHAMADO CANCELADO
            |--------------------------------------------------------------------------
            */

            if (
                $status === 'cancelado'
                &&
                !empty($newMaterialItems)
            ) {

                throw new Exception(
                    'Um novo chamado cancelado não pode registrar venda de materiais.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | SOMAR QUANTIDADES
            |--------------------------------------------------------------------------
            */

            $newTotals =
                aggregateMaterialQuantities(
                    $newMaterialItems
                );


            $materialDescriptions = [];


            /*
            |--------------------------------------------------------------------------
            | VALIDAR ESTOQUE
            |--------------------------------------------------------------------------
            */

            foreach (
                $newTotals
                as $materialId =>
                $requestedQuantity
            ) {

                /*
                |--------------------------------------------------------------------------
                | BLOQUEAR MATERIAL
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        description
                    FROM materials
                    WHERE id = ?
                      AND deleted_at IS NULL
                    FOR UPDATE
                ");

                $stmt->execute([
                    $materialId
                ]);


                $material =
                    $stmt->fetch();


                if (!$material) {

                    throw new Exception(
                        'Um dos materiais selecionados não existe mais.'
                    );
                }


                $materialDescriptions[$materialId] =
                    $material['description'];


                $stock =
                    currentMaterialStock(
                        $pdo,
                        $materialId
                    );


                if (
                    $requestedQuantity
                    >
                    $stock
                ) {

                    throw new Exception(

                        'Estoque insuficiente para "'
                            . $material['description']
                            . '". Disponível: '
                            . number_format(
                                $stock,
                                2,
                                ',',
                                '.'
                            )
                            . '. Solicitado: '
                            . number_format(
                                $requestedQuantity,
                                2,
                                ',',
                                '.'
                            )
                            . '.'
                    );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | GERAR NÚMERO
            |--------------------------------------------------------------------------
            */

            $ticketNumber =
                generateTicketNumber(
                    $pdo,
                    $occurredAt
                );


            /*
            |--------------------------------------------------------------------------
            | CRIAR CHAMADO
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO tickets (
                    ticket_number,
                    occurred_at,
                    client_id,
                    complainant,
                    request_text,
                    service_type,
                    notes,
                    status,
                    solution_text,
                    service_description,
                    service_charged,
                    service_amount,
                    materials_sold,
                    created_by
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");


            $stmt->execute([

                $ticketNumber,
                $occurredAt,
                $client,
                $complainant,
                $requestText,
                $type,
                $notes,
                $status,
                $solutionText,
                $serviceDescription,
                $charged,
                $amount,

                !empty($newMaterialItems)
                    ? 1
                    : 0,

                auth_user()['id']

            ]);


            $ticketId =
                (int)$pdo->lastInsertId();


            /*
            |--------------------------------------------------------------------------
            | GRAVAR ITENS
            |--------------------------------------------------------------------------
            */

            foreach (
                $newMaterialItems
                as $item
            ) {

                $materialId =
                    (int)$item['material_id'];


                $stmt = $pdo->prepare("
                    INSERT INTO ticket_materials (
                        ticket_id,
                        material_id,
                        description,
                        quantity,
                        unit_price
                    )
                    VALUES (?, ?, ?, ?, ?)
                ");


                $stmt->execute([

                    $ticketId,

                    $materialId,

                    $materialDescriptions[$materialId],

                    $item['quantity'],

                    $item['unit_price']

                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | BAIXA DO ESTOQUE
            |--------------------------------------------------------------------------
            |
            | Uma movimentação por material.
            |
            */

            foreach (
                $newTotals
                as $materialId =>
                $quantity
            ) {

                $stmt = $pdo->prepare("
                    INSERT INTO stock_movements (
                        material_id,
                        movement_type,
                        quantity,
                        ticket_id,
                        reference,
                        notes,
                        created_by
                    )
                    VALUES (
                        ?,
                        'saida',
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ");


                $stmt->execute([

                    $materialId,

                    $quantity,

                    $ticketId,

                    'Venda em chamado '
                        . $ticketNumber,

                    'Baixa automática de estoque.',

                    auth_user()['id']

                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | SALVAR PARCELAMENTO
            |--------------------------------------------------------------------------
            */

            saveBillingSchedule(
                $pdo,
                $ticketId,
                'servico',
                $serviceSchedule
            );


            saveBillingSchedule(
                $pdo,
                $ticketId,
                'materiais',
                $materialSchedule
            );


            $pdo->commit();


            flash(
                'success',
                'Chamado '
                    . $ticketNumber
                    . ' salvo com sucesso.'
            );


            redirect(
                base_url(
                    'tickets.php'
                )
            );
        }


        /*
        |--------------------------------------------------------------------------
        | EDITAR CHAMADO
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                id,
                ticket_number,
                status
            FROM tickets
            WHERE id = ?
              AND deleted_at IS NULL
            FOR UPDATE
        ");


        $stmt->execute([
            $id
        ]);


        $oldTicket =
            $stmt->fetch();


        if (!$oldTicket) {

            throw new Exception(
                'Chamado não encontrado.'
            );
        }


        $ticketNumber =
            $oldTicket['ticket_number'];


        $oldStatus =
            $oldTicket['status'];


        /*
        |--------------------------------------------------------------------------
        | ITENS ANTIGOS
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                material_id,
                quantity,
                unit_price
            FROM ticket_materials
            WHERE ticket_id = ?
            ORDER BY id
        ");


        $stmt->execute([
            $id
        ]);


        $oldMaterialItems =
            $stmt->fetchAll();


        /*
        |--------------------------------------------------------------------------
        | QUANTIDADES ANTIGAS
        |--------------------------------------------------------------------------
        */

        $oldTotals =
            aggregateMaterialQuantities(
                $oldMaterialItems
            );


        /*
        |--------------------------------------------------------------------------
        | QUANTIDADES NOVAS
        |--------------------------------------------------------------------------
        */

        $newTotals =
            aggregateMaterialQuantities(
                $newMaterialItems
            );


        /*
        |--------------------------------------------------------------------------
        | QUANTIDADE QUE REALMENTE ESTAVA BAIXADA
        |--------------------------------------------------------------------------
        |
        | Se o chamado já estava cancelado,
        | seu estoque já havia sido devolvido.
        |
        */

        $oldEffectiveTotals =

            $oldStatus === 'cancelado'

            ? []

            : $oldTotals;


        /*
        |--------------------------------------------------------------------------
        | QUANTIDADE QUE DEVERÁ FICAR BAIXADA
        |--------------------------------------------------------------------------
        |
        | Se o novo status for cancelado,
        | nenhum material permanecerá fora do estoque.
        |
        */

        $newEffectiveTotals =

            $status === 'cancelado'

            ? []

            : $newTotals;


        /*
        |--------------------------------------------------------------------------
        | TODOS OS MATERIAIS ENVOLVIDOS
        |--------------------------------------------------------------------------
        */

        $allMaterialIds =
            array_unique(
                array_merge(
                    array_keys(
                        $oldEffectiveTotals
                    ),
                    array_keys(
                        $newEffectiveTotals
                    )
                )
            );


        $materialDescriptions = [];


        /*
        |--------------------------------------------------------------------------
        | VALIDAR DIFERENÇAS
        |--------------------------------------------------------------------------
        */

        foreach (
            $allMaterialIds
            as $materialId
        ) {

            $materialId =
                (int)$materialId;


            /*
            |--------------------------------------------------------------------------
            | BLOQUEAR MATERIAL
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    description
                FROM materials
                WHERE id = ?
                  AND deleted_at IS NULL
                FOR UPDATE
            ");


            $stmt->execute([
                $materialId
            ]);


            $material =
                $stmt->fetch();


            if (!$material) {

                throw new Exception(
                    'Um dos materiais vinculados ao chamado não está disponível.'
                );
            }


            $materialDescriptions[$materialId] =
                $material['description'];


            $oldQuantity =
                (float)(
                    $oldEffectiveTotals[$materialId]
                    ?? 0
                );


            $newQuantity =
                (float)(
                    $newEffectiveTotals[$materialId]
                    ?? 0
                );


            /*
            |--------------------------------------------------------------------------
            | DIFERENÇA
            |--------------------------------------------------------------------------
            |
            | Positivo:
            | precisa retirar mais estoque.
            |
            | Negativo:
            | precisa devolver estoque.
            |
            */

            $difference =
                $newQuantity
                -
                $oldQuantity;


            /*
            |--------------------------------------------------------------------------
            | PRECISA DE MAIS ESTOQUE
            |--------------------------------------------------------------------------
            */

            if ($difference > 0) {

                $stock =
                    currentMaterialStock(
                        $pdo,
                        $materialId
                    );


                if (
                    $difference
                    >
                    $stock
                ) {

                    throw new Exception(

                        'Estoque insuficiente para "'
                            . $material['description']
                            . '". Disponível: '
                            . number_format(
                                $stock,
                                2,
                                ',',
                                '.'
                            )
                            . '. Necessário adicional: '
                            . number_format(
                                $difference,
                                2,
                                ',',
                                '.'
                            )
                            . '.'
                    );
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDAR DESCRIÇÕES DE MATERIAIS NOVOS
        |--------------------------------------------------------------------------
        */

        foreach (
            $newMaterialItems
            as $item
        ) {

            $materialId =
                (int)$item['material_id'];


            if (
                isset(
                    $materialDescriptions[$materialId]
                )
            ) {

                continue;
            }


            $stmt = $pdo->prepare("
                SELECT
                    id,
                    description
                FROM materials
                WHERE id = ?
                  AND deleted_at IS NULL
                FOR UPDATE
            ");


            $stmt->execute([
                $materialId
            ]);


            $material =
                $stmt->fetch();


            if (!$material) {

                throw new Exception(
                    'Um dos materiais selecionados não existe.'
                );
            }


            $materialDescriptions[$materialId] =
                $material['description'];
        }


        /*
        |--------------------------------------------------------------------------
        | ATUALIZAR CHAMADO
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            UPDATE tickets
            SET
                occurred_at = ?,
                client_id = ?,
                complainant = ?,
                request_text = ?,
                service_type = ?,
                notes = ?,
                status = ?,
                solution_text = ?,
                service_description = ?,
                service_charged = ?,
                service_amount = ?,
                materials_sold = ?
            WHERE id = ?
        ");


        $stmt->execute([

            $occurredAt,

            $client,

            $complainant,

            $requestText,

            $type,

            $notes,

            $status,

            $solutionText,

            $serviceDescription,

            $charged,

            $amount,

            !empty($newMaterialItems)
                ? 1
                : 0,

            $id

        ]);


        /*
        |--------------------------------------------------------------------------
        | RECRIAR ITENS DO CHAMADO
        |--------------------------------------------------------------------------
        |
        | Os movimentos antigos NÃO são apagados.
        |
        | Apenas as linhas atuais da venda são reconstruídas.
        |
        | Isso mantém o histórico do estoque.
        |
        */

        $stmt = $pdo->prepare("
            DELETE FROM ticket_materials
            WHERE ticket_id = ?
        ");


        $stmt->execute([
            $id
        ]);


        foreach (
            $newMaterialItems
            as $item
        ) {

            $materialId =
                (int)$item['material_id'];


            $stmt = $pdo->prepare("
                INSERT INTO ticket_materials (
                    ticket_id,
                    material_id,
                    description,
                    quantity,
                    unit_price
                )
                VALUES (?, ?, ?, ?, ?)
            ");


            $stmt->execute([

                $id,

                $materialId,

                $materialDescriptions[$materialId],

                $item['quantity'],

                $item['unit_price']

            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | MOVIMENTAR DIFERENÇAS DE ESTOQUE
        |--------------------------------------------------------------------------
        */

        foreach (
            $allMaterialIds
            as $materialId
        ) {

            $materialId =
                (int)$materialId;


            $oldQuantity =
                (float)(
                    $oldEffectiveTotals[$materialId]
                    ?? 0
                );


            $newQuantity =
                (float)(
                    $newEffectiveTotals[$materialId]
                    ?? 0
                );


            $difference =
                $newQuantity
                -
                $oldQuantity;


            /*
            |--------------------------------------------------------------------------
            | SEM ALTERAÇÃO
            |--------------------------------------------------------------------------
            */

            if (
                abs($difference)
                < 0.00001
            ) {

                continue;
            }


            /*
            |--------------------------------------------------------------------------
            | DEFINIR REFERÊNCIA
            |--------------------------------------------------------------------------
            */

            if (
                $oldStatus !== 'cancelado'
                &&
                $status === 'cancelado'
            ) {

                $reference =
                    'Estorno por cancelamento do chamado '
                    . $ticketNumber;

                $movementNotes =
                    'Material devolvido automaticamente ao estoque.';
            } elseif (
                $oldStatus === 'cancelado'
                &&
                $status !== 'cancelado'
            ) {

                $reference =
                    'Reativação do chamado '
                    . $ticketNumber;

                $movementNotes =
                    'Nova baixa automática após reativação do chamado.';
            } else {

                $reference =
                    'Ajuste de materiais do chamado '
                    . $ticketNumber;

                $movementNotes =
                    'Movimentação automática gerada pela edição do chamado.';
            }


            /*
            |--------------------------------------------------------------------------
            | AUMENTOU QUANTIDADE
            |--------------------------------------------------------------------------
            */

            if ($difference > 0) {

                $stmt = $pdo->prepare("
                    INSERT INTO stock_movements (
                        material_id,
                        movement_type,
                        quantity,
                        ticket_id,
                        reference,
                        notes,
                        created_by
                    )
                    VALUES (
                        ?,
                        'saida',
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ");


                $stmt->execute([

                    $materialId,

                    $difference,

                    $id,

                    $reference,

                    $movementNotes,

                    auth_user()['id']

                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | DIMINUIU / REMOVEU / CANCELOU
            |--------------------------------------------------------------------------
            */

            if ($difference < 0) {

                $returnQuantity =
                    abs(
                        $difference
                    );


                $stmt = $pdo->prepare("
                    INSERT INTO stock_movements (
                        material_id,
                        movement_type,
                        quantity,
                        ticket_id,
                        reference,
                        notes,
                        created_by
                    )
                    VALUES (
                        ?,
                        'entrada',
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ");


                $stmt->execute([

                    $materialId,

                    $returnQuantity,

                    $id,

                    $reference,

                    $movementNotes,

                    auth_user()['id']

                ]);
            }
        }


        /*
        |--------------------------------------------------------------------------
        | COMMIT
        |--------------------------------------------------------------------------
        */

        /*
        |--------------------------------------------------------------------------
        | SALVAR / ATUALIZAR PARCELAMENTO
        |--------------------------------------------------------------------------
        */

        saveBillingSchedule(
            $pdo,
            $id,
            'servico',
            $serviceSchedule
        );


        saveBillingSchedule(
            $pdo,
            $id,
            'materiais',
            $materialSchedule
        );


        $pdo->commit();


        flash(
            'success',
            'Chamado '
                . $ticketNumber
                . ' atualizado com sucesso.'
        );


        redirect(
            base_url(
                'tickets.php'
            )
        );
    } catch (Throwable $e) {

        if (
            $pdo->inTransaction()
        ) {

            $pdo->rollBack();
        }


        flash(
            'danger',
            $e->getMessage()
        );


        redirect(
            $id
                ? base_url(
                    'tickets.php?edit='
                        . $id
                )
                : base_url(
                    'tickets.php'
                )
        );
    }
}


/*
|--------------------------------------------------------------------------
| CLIENTES
|--------------------------------------------------------------------------
*/

if ($editTicket) {

    $stmt = $pdo->prepare("
        SELECT
            id,
            name
        FROM clients
        WHERE deleted_at IS NULL
          AND (
                status = 'ativo'
                OR id = ?
              )
        ORDER BY name
    ");


    $stmt->execute([
        $editTicket['client_id']
    ]);


    $clients =
        $stmt->fetchAll();
} else {

    $clients =
        $pdo->query("
            SELECT
                id,
                name
            FROM clients
            WHERE deleted_at IS NULL
              AND status = 'ativo'
            ORDER BY name
        ")->fetchAll();
}


/*
|--------------------------------------------------------------------------
| CLIENTES PARA FILTRO
|--------------------------------------------------------------------------
*/

$filterClients =
    $pdo->query("
        SELECT
            id,
            name
        FROM clients
        WHERE deleted_at IS NULL
        ORDER BY name
    ")->fetchAll();


/*
|--------------------------------------------------------------------------
| MATERIAIS + ESTOQUE
|--------------------------------------------------------------------------
*/

$materials =
    $pdo->query("
        SELECT

            m.id,

            m.description,

            COALESCE(
                SUM(
                    CASE

                        WHEN sm.movement_type = 'entrada'
                            THEN sm.quantity

                        WHEN sm.movement_type = 'saida'
                            THEN -sm.quantity

                        WHEN sm.movement_type = 'ajuste'
                            THEN sm.quantity

                        ELSE 0

                    END
                ),
                0
            ) AS stock_quantity

        FROM materials m

        LEFT JOIN stock_movements sm
            ON sm.material_id = m.id

        WHERE m.deleted_at IS NULL

        GROUP BY
            m.id,
            m.description

        ORDER BY
            m.description
    ")->fetchAll();


/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$filterStatus =
    $_GET['status']
    ?? '';


$filterClient =
    (int)(
        $_GET['client_id']
        ?? 0
    );


$filterStart =
    trim(
        $_GET['date_start']
            ?? ''
    );


$filterEnd =
    trim(
        $_GET['date_end']
            ?? ''
    );


$allowedFilterStatuses = [

    '',
    'aberto',
    'pendente',
    'solucionado',
    'fechado',
    'cancelado'

];


if (
    !in_array(
        $filterStatus,
        $allowedFilterStatuses,
        true
    )
) {

    $filterStatus = '';
}


/*
|--------------------------------------------------------------------------
| VALIDAR DATAS
|--------------------------------------------------------------------------
*/

if (
    $filterStart !== ''
    &&
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $filterStart
    )
) {

    $filterStart = '';
}


if (
    $filterEnd !== ''
    &&
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $filterEnd
    )
) {

    $filterEnd = '';
}


/*
|--------------------------------------------------------------------------
| WHERE
|--------------------------------------------------------------------------
*/

$where = [
    't.deleted_at IS NULL'
];


$params = [];


if ($filterStatus !== '') {

    $where[] =
        't.status = ?';

    $params[] =
        $filterStatus;
}


if ($filterClient > 0) {

    $where[] =
        't.client_id = ?';

    $params[] =
        $filterClient;
}


if ($filterStart !== '') {

    $where[] =
        't.occurred_at >= ?';

    $params[] =
        $filterStart
        . ' 00:00:00';
}


if ($filterEnd !== '') {

    $where[] =
        't.occurred_at <= ?';

    $params[] =
        $filterEnd
        . ' 23:59:59';
}


$whereSql =
    implode(
        ' AND ',
        $where
    );


/*
|--------------------------------------------------------------------------
| LISTAGEM
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT

        t.*,

        c.name AS client,

        CASE

            WHEN t.status = 'cancelado'
                THEN 0

            ELSE (
                SELECT
                    COALESCE(
                        SUM(
                            tm.total_price
                        ),
                        0
                    )

                FROM ticket_materials tm

                WHERE tm.ticket_id = t.id
            )

        END AS materials_total

    FROM tickets t

    INNER JOIN clients c
        ON c.id = t.client_id

    WHERE {$whereSql}

    ORDER BY
        t.occurred_at DESC

    LIMIT 500
";


$stmt =
    $pdo->prepare(
        $sql
    );


$stmt->execute(
    $params
);


$rows =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| CONTADORES
|--------------------------------------------------------------------------
*/

$countWhere = [
    'deleted_at IS NULL'
];


$countParams = [];


if ($filterClient > 0) {

    $countWhere[] =
        'client_id = ?';

    $countParams[] =
        $filterClient;
}


if ($filterStart !== '') {

    $countWhere[] =
        'occurred_at >= ?';

    $countParams[] =
        $filterStart
        . ' 00:00:00';
}


if ($filterEnd !== '') {

    $countWhere[] =
        'occurred_at <= ?';

    $countParams[] =
        $filterEnd
        . ' 23:59:59';
}


$countWhereSql =
    implode(
        ' AND ',
        $countWhere
    );


$statusCounts = [

    'todos' => 0,
    'aberto' => 0,
    'pendente' => 0,
    'solucionado' => 0,
    'fechado' => 0,
    'cancelado' => 0

];


$stmt =
    $pdo->prepare("
        SELECT
            status,
            COUNT(*) AS total
        FROM tickets
        WHERE {$countWhereSql}
        GROUP BY status
    ");


$stmt->execute(
    $countParams
);


foreach (
    $stmt->fetchAll()
    as $countRow
) {

    if (
        isset(
            $statusCounts[$countRow['status']]
        )
    ) {

        $statusCounts[$countRow['status']] =
            (int)$countRow['total'];
    }
}


$statusCounts['todos'] =

    $statusCounts['aberto']
    +
    $statusCounts['pendente']
    +
    $statusCounts['solucionado']
    +
    $statusCounts['fechado']
    +
    $statusCounts['cancelado'];


/*
|--------------------------------------------------------------------------
| CORES
|--------------------------------------------------------------------------
*/

$statusColors = [

    'aberto' =>
    'primary',

    'pendente' =>
    'warning',

    'solucionado' =>
    'success',

    'fechado' =>
    'secondary',

    'cancelado' =>
    'danger'

];


/*
|--------------------------------------------------------------------------
| LINHAS INICIAIS DE MATERIAL
|--------------------------------------------------------------------------
*/

$formMaterialRows = [];


if (
    $editTicket
    &&
    !empty($editTicketMaterials)
) {

    foreach (
        $editTicketMaterials
        as $item
    ) {

        $formMaterialRows[] = [

            'material_id' =>
            $item['material_id'],

            'quantity' =>
            $item['quantity'],

            'unit_price' =>
            $item['unit_price']

        ];
    }
} else {

    $formMaterialRows[] = [

        'material_id' => '',
        'quantity' => '',
        'unit_price' => ''

    ];
}


render_header(
    'Chamados'
);

?>


<div class="row g-3">


    <!-- ================================================================
         FORMULÁRIO
    ================================================================= -->

    <div class="col-xl-4">

        <div class="card">

            <div class="card-body">


                <h5 class="mb-3">


                    <?php if ($editTicket): ?>


                        Editar chamado
                        <?= e(
                            $editTicket['ticket_number']
                        ) ?>


                    <?php else: ?>


                        Abrir chamado


                    <?php endif; ?>


                </h5>


                <form method="post">


                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e(
                                    csrf_token()
                                ) ?>">


                    <input
                        type="hidden"
                        name="id"
                        value="<?= e(
                                    $editTicket['id']
                                        ?? ''
                                ) ?>">


                    <!-- DATA -->

                    <div class="mb-2">

                        <label class="form-label">

                            Data e horário

                        </label>


                        <input
                            class="form-control"
                            type="datetime-local"
                            name="occurred_at"
                            value="<?= e(

                                        $editTicket

                                            ? date(
                                                'Y-m-d\TH:i',
                                                strtotime(
                                                    $editTicket['occurred_at']
                                                )
                                            )

                                            : date(
                                                'Y-m-d\TH:i'
                                            )

                                    ) ?>"
                            required>

                    </div>


                    <!-- CLIENTE -->

                    <div class="mb-2">

                        <label class="form-label">

                            Cliente

                        </label>


                        <select
                            class="form-select"
                            name="client_id"
                            required>


                            <?php
                            foreach (
                                $clients
                                as $client
                            ):
                            ?>


                                <option
                                    value="<?= (int)$client['id'] ?>"
                                    <?= (
                                        $editTicket
                                        &&
                                        (int)$editTicket['client_id']
                                        ===
                                        (int)$client['id']
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>>

                                    <?= e(
                                        $client['name']
                                    ) ?>

                                </option>


                            <?php endforeach; ?>


                        </select>

                    </div>


                    <!-- RECLAMANTE -->

                    <div class="mb-2">

                        <label class="form-label">

                            Reclamante

                        </label>


                        <input
                            class="form-control"
                            name="complainant"
                            value="<?= e(
                                        $editTicket['complainant']
                                            ?? ''
                                    ) ?>">

                    </div>


                    <!-- SOLICITAÇÃO -->

                    <div class="mb-2">

                        <label class="form-label">

                            Solicitação

                        </label>


                        <textarea
                            class="form-control"
                            name="request_text"
                            required><?= e(
                                            $editTicket['request_text']
                                                ?? ''
                                        ) ?></textarea>

                    </div>


                    <!-- TIPO / STATUS -->

                    <div class="row">


                        <div class="col">

                            <label class="form-label">
                                Tipo
                            </label>


                            <?php

                            $selectedType =
                                $editTicket['service_type']
                                ?? 'remoto';

                            ?>


                            <select
                                class="form-select"
                                name="service_type">


                                <option
                                    value="remoto"
                                    <?= $selectedType === 'remoto'
                                        ? 'selected'
                                        : ''
                                    ?>>
                                    Remoto
                                </option>


                                <option
                                    value="presencial"
                                    <?= $selectedType === 'presencial'
                                        ? 'selected'
                                        : ''
                                    ?>>
                                    Presencial
                                </option>


                                <option
                                    value="preventiva"
                                    <?= $selectedType === 'preventiva'
                                        ? 'selected'
                                        : ''
                                    ?>>
                                    Preventiva
                                </option>


                                <option
                                    value="vistoria"
                                    <?= $selectedType === 'vistoria'
                                        ? 'selected'
                                        : ''
                                    ?>>
                                    Vistoria
                                </option>


                                <option
                                    value="orcamento"
                                    <?= $selectedType === 'orcamento'
                                        ? 'selected'
                                        : ''
                                    ?>>
                                    Orçamento
                                </option>


                            </select>

                        </div>


                        <div class="col">

                            <label class="form-label">
                                Status
                            </label>


                            <?php

                            $selectedStatus =
                                $editTicket['status']
                                ?? 'aberto';

                            ?>


                            <select
                                class="form-select"
                                name="status">


                                <option
                                    value="aberto"
                                    <?= $selectedStatus === 'aberto'
                                        ? 'selected'
                                        : ''
                                    ?>>
                                    Aberto
                                </option>


                                <option
                                    value="pendente"
                                    <?= $selectedStatus === 'pendente'
                                        ? 'selected'
                                        : ''
                                    ?>>
                                    Pendente
                                </option>


                                <option
                                    value="solucionado"
                                    <?= $selectedStatus === 'solucionado'
                                        ? 'selected'
                                        : ''
                                    ?>>
                                    Solucionado
                                </option>


                                <option
                                    value="fechado"
                                    <?= $selectedStatus === 'fechado'
                                        ? 'selected'
                                        : ''
                                    ?>>
                                    Fechado
                                </option>


                                <option
                                    value="cancelado"
                                    <?= $selectedStatus === 'cancelado'
                                        ? 'selected'
                                        : ''
                                    ?>>
                                    Cancelado
                                </option>


                            </select>

                        </div>


                    </div>


                    <!-- SOLUÇÃO -->

                    <div class="mb-2 mt-2">

                        <label class="form-label">

                            Solução aplicada

                        </label>


                        <textarea
                            class="form-control"
                            name="solution_text"><?= e(
                                                        $editTicket['solution_text']
                                                            ?? ''
                                                    ) ?></textarea>

                    </div>


                    <!-- SERVIÇO -->

                    <div class="form-check my-2">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="service_charged"
                            id="service_charged"
                            <?= !empty($editTicket['service_charged'])
                                ? 'checked'
                                : ''
                            ?>>

                        <label
                            class="form-check-label"
                            for="service_charged">
                            Houve cobrança de serviço?
                        </label>

                    </div>


                    <!-- DADOS DA COBRANÇA DO SERVIÇO -->

                    <div
                        id="serviceBillingContainer"
                        style="<?= !empty($editTicket['service_charged'])
                                    ? ''
                                    : 'display:none;'
                                ?>">

                        <div class="mb-2">

                            <label class="form-label">
                                Descrição do serviço cobrado
                            </label>

                            <input
                                class="form-control"
                                type="text"
                                maxlength="255"
                                name="service_description"
                                id="service_description"
                                value="<?= e(
                                            $editTicket['service_description']
                                                ?? ''
                                        ) ?>">

                            <div class="form-text">
                                Esta descrição será utilizada no demonstrativo de cobrança.
                            </div>

                        </div>


                        <div class="mb-2">

                            <label class="form-label">
                                Valor serviço
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                min="0"
                                step="0.01"
                                name="service_amount"
                                id="service_amount"
                                value="<?= e(
                                            $editTicket['service_amount']
                                                ?? '0'
                                        ) ?>">

                            <div class="form-text">
                                Para remover a cobrança, primeiro informe R$ 0,00.
                            </div>

                        </div>


                        <div class="row g-2 mb-2">

                            <div class="col-md-6">

                                <label class="form-label">
                                    Forma de cobrança
                                </label>

                                <select
                                    class="form-select"
                                    name="service_billing_mode"
                                    id="service_billing_mode">

                                    <option
                                        value="avista"
                                        <?= $editServiceInstallments <= 1
                                            ? 'selected'
                                            : ''
                                        ?>>
                                        À vista
                                    </option>

                                    <option
                                        value="parcelado"
                                        <?= $editServiceInstallments > 1
                                            ? 'selected'
                                            : ''
                                        ?>>
                                        Parcelado
                                    </option>

                                </select>

                            </div>


                            <div
                                class="col-md-6"
                                id="serviceInstallmentsContainer"
                                style="<?= $editServiceInstallments > 1
                                            ? ''
                                            : 'display:none;'
                                        ?>">

                                <label class="form-label">
                                    Quantidade de parcelas
                                </label>

                                <input
                                    class="form-control"
                                    type="number"
                                    min="2"
                                    max="60"
                                    step="1"
                                    name="service_installments"
                                    id="service_installments"
                                    value="<?= (int)max(
                                                2,
                                                $editServiceInstallments
                                            ) ?>">

                            </div>

                        </div>

                    </div>


                    <!-- ====================================================
                         MATERIAIS
                    ===================================================== -->

                    <hr>


                    <div
                        class="
                            d-flex
                            justify-content-between
                            align-items-center
                            mb-2
                        ">

                        <h6 class="mb-0">

                            Materiais vendidos

                        </h6>


                        <button
                            type="button"
                            id="addMaterialButton"
                            class="
                                btn
                                btn-sm
                                btn-outline-success
                            ">

                            + Adicionar material

                        </button>

                    </div>


                    <?php if ($editTicket): ?>

                        <div class="alert alert-secondary py-2">

                            Ao alterar quantidade ou remover um material,
                            o estoque será ajustado automaticamente.

                            Se o chamado for cancelado,
                            todos os materiais serão devolvidos ao estoque.

                        </div>

                    <?php endif; ?>


                    <div id="materialsContainer">


                        <?php
                        foreach (
                            $formMaterialRows
                            as $index =>
                            $formItem
                        ):
                        ?>


                            <div
                                class="
                                    material-row
                                    border
                                    rounded
                                    p-2
                                    mb-2
                                ">


                                <div class="mb-2">

                                    <label class="form-label">

                                        Material

                                    </label>


                                    <select
                                        class="form-select"
                                        name="materials[<?= $index ?>][material_id]">

                                        <option value="">

                                            Nenhum

                                        </option>


                                        <?php
                                        foreach (
                                            $materials
                                            as $material
                                        ):
                                        ?>


                                            <option
                                                value="<?= (int)$material['id'] ?>"
                                                <?= (
                                                    (int)$formItem['material_id']
                                                    ===
                                                    (int)$material['id']
                                                )
                                                    ? 'selected'
                                                    : ''
                                                ?>>

                                                <?= e(
                                                    $material['description']
                                                ) ?>

                                                - Estoque:
                                                <?= number_format(
                                                    (float)$material['stock_quantity'],
                                                    2,
                                                    ',',
                                                    '.'
                                                ) ?>

                                            </option>


                                        <?php endforeach; ?>


                                    </select>

                                </div>


                                <div class="row g-2">


                                    <div class="col-5">

                                        <label class="form-label">

                                            Quantidade

                                        </label>


                                        <input
                                            class="form-control"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            name="materials[<?= $index ?>][quantity]"
                                            value="<?= e(
                                                        $formItem['quantity']
                                                    ) ?>">

                                    </div>


                                    <div class="col-5">

                                        <label class="form-label">

                                            Valor unitário

                                        </label>


                                        <input
                                            class="form-control"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            name="materials[<?= $index ?>][unit_price]"
                                            value="<?= e(
                                                        $formItem['unit_price']
                                                    ) ?>">

                                    </div>


                                    <div
                                        class="
                                            col-2
                                            d-flex
                                            align-items-end
                                        ">

                                        <button
                                            type="button"
                                            class="
                                                btn
                                                btn-outline-danger
                                                w-100
                                                remove-material
                                            "
                                            title="Remover material">

                                            ×

                                        </button>

                                    </div>


                                </div>


                            </div>


                        <?php endforeach; ?>


                    </div>


                    <!-- COBRANÇA DOS MATERIAIS -->

                    <div
                        id="materialBillingContainer"
                        class="mt-3"
                        style="<?= !empty($editTicketMaterials)
                                    ? ''
                                    : 'display:none;'
                                ?>">

                        <div class="border rounded p-2">

                            <div class="d-flex justify-content-between mb-2">

                                <strong>
                                    Cobrança dos materiais
                                </strong>

                                <span>
                                    Total:
                                    <strong id="materialsBillingTotal">
                                        R$ 0,00
                                    </strong>
                                </span>

                            </div>


                            <div class="row g-2">

                                <div class="col-md-6">

                                    <label class="form-label">
                                        Forma de cobrança
                                    </label>

                                    <select
                                        class="form-select"
                                        name="material_billing_mode"
                                        id="material_billing_mode">

                                        <option
                                            value="avista"
                                            <?= $editMaterialInstallments <= 1
                                                ? 'selected'
                                                : ''
                                            ?>>
                                            À vista
                                        </option>

                                        <option
                                            value="parcelado"
                                            <?= $editMaterialInstallments > 1
                                                ? 'selected'
                                                : ''
                                            ?>>
                                            Parcelado
                                        </option>

                                    </select>

                                </div>


                                <div
                                    class="col-md-6"
                                    id="materialInstallmentsContainer"
                                    style="<?= $editMaterialInstallments > 1
                                                ? ''
                                                : 'display:none;'
                                            ?>">

                                    <label class="form-label">
                                        Quantidade de parcelas
                                    </label>

                                    <input
                                        class="form-control"
                                        type="number"
                                        min="2"
                                        max="60"
                                        step="1"
                                        name="material_installments"
                                        id="material_installments"
                                        value="<?= (int)max(
                                                    2,
                                                    $editMaterialInstallments
                                                ) ?>">

                                </div>

                            </div>

                            <div class="form-text mt-2">
                                O parcelamento é aplicado ao total dos materiais vendidos neste chamado.
                            </div>

                        </div>

                    </div>


                    <!-- OBSERVAÇÕES -->

                    <div class="mb-3 mt-3">

                        <label class="form-label">

                            Observações

                        </label>


                        <textarea
                            class="form-control"
                            name="notes"><?= e(
                                                $editTicket['notes']
                                                    ?? ''
                                            ) ?></textarea>

                    </div>


                    <!-- BOTÕES -->

                    <button
                        class="btn btn-primary"
                        type="submit">

                        <?= $editTicket

                            ? 'Atualizar chamado'

                            : 'Salvar chamado'

                        ?>

                    </button>


                    <?php if ($editTicket): ?>

                        <a
                            href="<?= e(
                                        base_url(
                                            'tickets.php'
                                        )
                                    ) ?>"
                            class="btn btn-secondary">

                            Cancelar edição

                        </a>

                    <?php endif; ?>


                </form>

            </div>

        </div>

    </div>


    <!-- ================================================================
         LISTAGEM
    ================================================================= -->

    <div class="col-xl-8">


        <!-- ABAS -->

        <div class="mb-3">

            <div class="d-flex flex-wrap gap-2 ticket-status-tabs">


                <?php

                $tabs = [

                    '' => [
                        'Todos',
                        'info',
                        'todos'
                    ],

                    'aberto' => [
                        'Abertos',
                        'primary',
                        'aberto'
                    ],

                    'pendente' => [
                        'Pendentes',
                        'warning',
                        'pendente'
                    ],

                    'solucionado' => [
                        'Solucionados',
                        'success',
                        'solucionado'
                    ],

                    'fechado' => [
                        'Fechados',
                        'secondary',
                        'fechado'
                    ],

                    'cancelado' => [
                        'Cancelados',
                        'danger',
                        'cancelado'
                    ]

                ];

                ?>


                <?php
                foreach (
                    $tabs
                    as $tabStatus =>
                    $tab
                ):
                ?>


                    <?php

                    [
                        $label,
                        $color,
                        $countKey
                    ] = $tab;

                    ?>


                    <a
                        href="<?= e(
                                    ticketFilterUrl(
                                        $tabStatus,
                                        $filterClient,
                                        $filterStart,
                                        $filterEnd
                                    )
                                ) ?>"
                        class="
                            btn
                            btn-sm
                            <?= $filterStatus === $tabStatus
                                ? 'btn-' . $color
                                : 'btn-outline-' . $color
                            ?>
                        ">

                        <?= e($label) ?>

                        (<?= $statusCounts[$countKey] ?>)

                    </a>


                <?php endforeach; ?>


            </div>

        </div>


        <!-- FILTRO -->

        <form
            method="get"
            class="card card-body mb-3">


            <?php if ($filterStatus !== ''): ?>

                <input
                    type="hidden"
                    name="status"
                    value="<?= e(
                                $filterStatus
                            ) ?>">

            <?php endif; ?>


            <div class="row g-2">


                <div class="col-lg-5">

                    <label class="form-label">

                        Cliente

                    </label>


                    <select
                        class="form-select"
                        name="client_id">

                        <option value="0">

                            Todos os clientes

                        </option>


                        <?php
                        foreach (
                            $filterClients
                            as $client
                        ):
                        ?>


                            <option
                                value="<?= (int)$client['id'] ?>"
                                <?= $filterClient
                                    ===
                                    (int)$client['id']
                                    ? 'selected'
                                    : ''
                                ?>>

                                <?= e(
                                    $client['name']
                                ) ?>

                            </option>


                        <?php endforeach; ?>


                    </select>

                </div>


                <div class="col-lg-3">

                    <label class="form-label">

                        Data inicial

                    </label>


                    <input
                        class="form-control"
                        type="date"
                        name="date_start"
                        value="<?= e(
                                    $filterStart
                                ) ?>">

                </div>


                <div class="col-lg-3">

                    <label class="form-label">

                        Data final

                    </label>


                    <input
                        class="form-control"
                        type="date"
                        name="date_end"
                        value="<?= e(
                                    $filterEnd
                                ) ?>">

                </div>


                <div
                    class="
                        col-lg-1
                        d-flex
                        align-items-end
                    ">

                    <button
                        class="btn btn-primary w-100"
                        type="submit"
                        title="Filtrar">

                        🔎

                    </button>

                </div>


            </div>


            <div class="mt-2">

                <a
                    href="<?= e(
                                base_url(
                                    'tickets.php'
                                )
                            ) ?>"
                    class="
                        btn
                        btn-sm
                        btn-outline-secondary
                    ">

                    Limpar filtros

                </a>

            </div>


        </form>


        <!-- PESQUISA -->

        <input
            class="form-control mb-2"
            placeholder="Pesquisa instantânea"
            data-search-table="#ticketTable">


        <!-- TABELA -->

        <div class="table-wrap">

            <div class="table-responsive">

                <table
                    id="ticketTable"
                    class="
                        table
                        table-sm
                        table-hover
                        align-middle
                    ">


                    <thead>

                        <tr>

                            <th>Nº</th>

                            <th>Data</th>

                            <th>Cliente</th>

                            <th>Tipo</th>

                            <th>Serviço</th>

                            <th>Materiais</th>

                            <th>Status</th>

                            <th>Ações</th>

                        </tr>

                    </thead>


                    <tbody>


                        <?php
                        foreach (
                            $rows
                            as $row
                        ):
                        ?>


                            <?php

                            $currentStatus =
                                $row['status'];


                            $statusClass =
                                $statusColors[$currentStatus]
                                ?? 'secondary';

                            ?>


                            <tr>


                                <td class="text-nowrap">

                                    <?= e(
                                        $row['ticket_number']
                                    ) ?>

                                </td>


                                <td class="text-nowrap">

                                    <?= date(
                                        'd/m/Y H:i',
                                        strtotime(
                                            $row['occurred_at']
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?= e(
                                        $row['client']
                                    ) ?>

                                </td>


                                <td>

                                    <?= ucfirst(
                                        e(
                                            $row['service_type']
                                        )
                                    ) ?>

                                </td>


                                <td class="text-nowrap">

                                    <?php if (
                                        $currentStatus
                                        ===
                                        'cancelado'
                                    ): ?>

                                        <?= money(0) ?>

                                    <?php else: ?>

                                        <?= money(
                                            $row['service_amount']
                                        ) ?>

                                    <?php endif; ?>

                                </td>


                                <td class="text-nowrap">

                                    <?= money(
                                        $row['materials_total']
                                    ) ?>

                                </td>


                                <td class="text-nowrap">

                                    <span
                                        class="
                                            d-inline-block
                                            rounded-circle
                                            bg-<?= e(
                                                    $statusClass
                                                ) ?>
                                        "
                                        style="
                                            width: 10px;
                                            height: 10px;
                                            margin-right: 6px;
                                        "></span>


                                    <?= ucfirst(
                                        e(
                                            $currentStatus
                                        )
                                    ) ?>

                                </td>


                                <td class="text-nowrap">

                                    <a
                                        href="<?= e(
                                                    base_url(
                                                        'tickets.php?edit='
                                                            . $row['id']
                                                    )
                                                ) ?>"
                                        class="
                                            btn
                                            btn-sm
                                            btn-outline-primary
                                        ">

                                        Editar

                                    </a>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                        <?php if (empty($rows)): ?>

                            <tr>

                                <td
                                    colspan="8"
                                    class="
                                        text-center
                                        text-muted
                                        py-4
                                    ">

                                    Nenhum chamado encontrado.

                                </td>

                            </tr>

                        <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </div>


    </div>


</div>


<!-- =====================================================================
     TEMPLATE DE MATERIAL
====================================================================== -->

<template id="materialRowTemplate">

    <div
        class="
            material-row
            border
            rounded
            p-2
            mb-2
        ">

        <!-- MATERIAL -->

        <div class="mb-2">

            <label class="form-label">
                Material
            </label>

            <select
                class="form-select"
                data-field="material_id">

                <option value="">
                    Nenhum
                </option>

                <?php foreach ($materials as $material): ?>

                    <option
                        value="<?= (int)$material['id'] ?>">

                        <?= e(
                            $material['description']
                        ) ?>

                        - Estoque:
                        <?= number_format(
                            (float)$material['stock_quantity'],
                            2,
                            ',',
                            '.'
                        ) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="row g-2">


            <!-- QUANTIDADE -->

            <div class="col-5">

                <label class="form-label">
                    Quantidade
                </label>

                <input
                    class="form-control"
                    type="number"
                    min="0"
                    step="0.01"
                    data-field="quantity">

            </div>


            <!-- VALOR UNITÁRIO -->

            <div class="col-5">

                <label class="form-label">
                    Valor unitário
                </label>

                <input
                    class="form-control"
                    type="number"
                    min="0"
                    step="0.01"
                    data-field="unit_price">

            </div>


            <!-- REMOVER -->

            <div
                class="
                    col-2
                    d-flex
                    align-items-end
                ">

                <button
                    type="button"
                    class="
                        btn
                        btn-outline-danger
                        w-100
                        remove-material
                    "
                    title="Remover material">
                    ×
                </button>

            </div>


        </div>

    </div>

</template>


<script>
    document.addEventListener(
        'DOMContentLoaded',
        function() {

            const container =
                document.getElementById(
                    'materialsContainer'
                );


            const addButton =
                document.getElementById(
                    'addMaterialButton'
                );


            const template =
                document.getElementById(
                    'materialRowTemplate'
                );


            if (
                !container ||
                !addButton ||
                !template
            ) {

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | ÍNDICE
            |--------------------------------------------------------------------------
            */

            let materialIndex =
                container.querySelectorAll(
                    '.material-row'
                ).length;


            /*
            |--------------------------------------------------------------------------
            | ADICIONAR
            |--------------------------------------------------------------------------
            */

            addButton.addEventListener(
                'click',
                function() {

                    const fragment =
                        template.content.cloneNode(
                            true
                        );


                    const row =
                        fragment.querySelector(
                            '.material-row'
                        );


                    row.querySelector(
                            '[data-field="material_id"]'
                        ).name =
                        'materials[' +
                        materialIndex +
                        '][material_id]';


                    row.querySelector(
                            '[data-field="quantity"]'
                        ).name =
                        'materials[' +
                        materialIndex +
                        '][quantity]';


                    row.querySelector(
                            '[data-field="unit_price"]'
                        ).name =
                        'materials[' +
                        materialIndex +
                        '][unit_price]';


                    container.appendChild(
                        fragment
                    );


                    materialIndex++;
                }
            );


            /*
            |--------------------------------------------------------------------------
            | REMOVER
            |--------------------------------------------------------------------------
            */

            container.addEventListener(
                'click',
                function(event) {

                    if (
                        !event.target
                        .classList
                        .contains(
                            'remove-material'
                        )
                    ) {

                        return;
                    }


                    const row =
                        event.target.closest(
                            '.material-row'
                        );


                    const rows =
                        container.querySelectorAll(
                            '.material-row'
                        );


                    /*
                    |--------------------------------------------------------------------------
                    | MAIS DE UMA LINHA
                    |--------------------------------------------------------------------------
                    */

                    if (
                        rows.length > 1
                    ) {

                        row.remove();

                        return;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | ÚLTIMA LINHA
                    |--------------------------------------------------------------------------
                    */

                    const select =
                        row.querySelector(
                            'select'
                        );


                    if (select) {

                        select.value = '';
                    }


                    row.querySelectorAll(
                        'input'
                    ).forEach(
                        function(input) {

                            input.value = '';

                        }
                    );

                }
            );

        }
    );
</script>

<script>
    document.addEventListener(
        'DOMContentLoaded',
        function() {

            const checkbox =
                document.getElementById(
                    'service_charged'
                );

            const serviceBillingContainer =
                document.getElementById(
                    'serviceBillingContainer'
                );

            const descriptionInput =
                document.getElementById(
                    'service_description'
                );

            const amountInput =
                document.getElementById(
                    'service_amount'
                );

            const serviceBillingMode =
                document.getElementById(
                    'service_billing_mode'
                );

            const serviceInstallmentsContainer =
                document.getElementById(
                    'serviceInstallmentsContainer'
                );

            const serviceInstallments =
                document.getElementById(
                    'service_installments'
                );


            /*
            |--------------------------------------------------------------------------
            | COBRANÇA DO SERVIÇO
            |--------------------------------------------------------------------------
            */

            function updateServiceVisibility() {

                if (
                    !checkbox ||
                    !serviceBillingContainer
                ) {
                    return;
                }

                serviceBillingContainer.style.display =
                    checkbox.checked ?
                    '' :
                    'none';

                if (descriptionInput) {
                    descriptionInput.required =
                        checkbox.checked;
                }

                if (amountInput) {
                    amountInput.required =
                        checkbox.checked;
                }
            }


            if (
                checkbox &&
                amountInput
            ) {

                checkbox.addEventListener(
                    'change',
                    function() {

                        if (!checkbox.checked) {

                            const amount =
                                parseFloat(
                                    amountInput.value
                                ) || 0;

                            if (amount > 0) {

                                checkbox.checked = true;

                                serviceBillingContainer.style.display = '';

                                amountInput.focus();

                                alert(
                                    'Para remover a cobrança do serviço, primeiro altere o Valor serviço para R$ 0,00.'
                                );

                                return;
                            }
                        }

                        updateServiceVisibility();
                    }
                );
            }


            /*
            |--------------------------------------------------------------------------
            | PARCELAMENTO DO SERVIÇO
            |--------------------------------------------------------------------------
            */

            function updateServiceInstallments() {

                if (
                    !serviceBillingMode ||
                    !serviceInstallmentsContainer ||
                    !serviceInstallments
                ) {
                    return;
                }

                const parcelado =
                    serviceBillingMode.value ===
                    'parcelado';

                serviceInstallmentsContainer.style.display =
                    parcelado ?
                    '' :
                    'none';

                serviceInstallments.required =
                    parcelado;

                if (!parcelado) {
                    serviceInstallments.value = '2';
                }
            }


            if (serviceBillingMode) {

                serviceBillingMode.addEventListener(
                    'change',
                    updateServiceInstallments
                );
            }


            /*
            |--------------------------------------------------------------------------
            | COBRANÇA DOS MATERIAIS
            |--------------------------------------------------------------------------
            */

            const materialsContainer =
                document.getElementById(
                    'materialsContainer'
                );

            const materialBillingContainer =
                document.getElementById(
                    'materialBillingContainer'
                );

            const materialsBillingTotal =
                document.getElementById(
                    'materialsBillingTotal'
                );

            const materialBillingMode =
                document.getElementById(
                    'material_billing_mode'
                );

            const materialInstallmentsContainer =
                document.getElementById(
                    'materialInstallmentsContainer'
                );

            const materialInstallments =
                document.getElementById(
                    'material_installments'
                );


            function formatBRL(value) {

                return new Intl.NumberFormat(
                    'pt-BR', {
                        style: 'currency',
                        currency: 'BRL'
                    }
                ).format(value);
            }


            function updateMaterialBilling() {

                if (
                    !materialsContainer ||
                    !materialBillingContainer
                ) {
                    return;
                }

                let total = 0;
                let hasMaterial = false;

                materialsContainer
                    .querySelectorAll(
                        '.material-row'
                    )
                    .forEach(
                        function(row) {

                            const select =
                                row.querySelector(
                                    'select'
                                );

                            const quantity =
                                row.querySelector(
                                    'input[name*="[quantity]"]'
                                );

                            const unitPrice =
                                row.querySelector(
                                    'input[name*="[unit_price]"]'
                                );

                            if (
                                select &&
                                select.value
                            ) {

                                hasMaterial = true;

                                const qty =
                                    parseFloat(
                                        quantity ?
                                        quantity.value :
                                        0
                                    ) || 0;

                                const price =
                                    parseFloat(
                                        unitPrice ?
                                        unitPrice.value :
                                        0
                                    ) || 0;

                                total +=
                                    qty * price;
                            }
                        }
                    );

                materialBillingContainer.style.display =
                    hasMaterial ?
                    '' :
                    'none';

                if (materialsBillingTotal) {

                    materialsBillingTotal.textContent =
                        formatBRL(total);
                }
            }


            if (materialsContainer) {

                materialsContainer.addEventListener(
                    'input',
                    updateMaterialBilling
                );

                materialsContainer.addEventListener(
                    'change',
                    updateMaterialBilling
                );

                materialsContainer.addEventListener(
                    'click',
                    function() {

                        setTimeout(
                            updateMaterialBilling,
                            0
                        );
                    }
                );
            }


            function updateMaterialInstallments() {

                if (
                    !materialBillingMode ||
                    !materialInstallmentsContainer ||
                    !materialInstallments
                ) {
                    return;
                }

                const parcelado =
                    materialBillingMode.value ===
                    'parcelado';

                materialInstallmentsContainer.style.display =
                    parcelado ?
                    '' :
                    'none';

                materialInstallments.required =
                    parcelado;

                if (!parcelado) {
                    materialInstallments.value = '2';
                }
            }


            if (materialBillingMode) {

                materialBillingMode.addEventListener(
                    'change',
                    updateMaterialInstallments
                );
            }


            /*
            |--------------------------------------------------------------------------
            | ESTADO INICIAL
            |--------------------------------------------------------------------------
            */

            updateServiceVisibility();

            updateServiceInstallments();

            updateMaterialBilling();

            updateMaterialInstallments();

        }
    );
</script>

<?php render_footer(); ?>