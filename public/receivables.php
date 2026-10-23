<?php

require_once __DIR__ . '/../src/layout.php';

require_auth();

$pdo = db();


/*
|--------------------------------------------------------------------------
| INÍCIO DO CONTROLE FINANCEIRO
|--------------------------------------------------------------------------
|
| 09/2026 é a competência inicial do Contas a Receber.
| Competências anteriores não carregam mensalidades antigas como pendência.
|--------------------------------------------------------------------------
*/

const FINANCIAL_START_COMPETENCE = '2026-09';


/*
|--------------------------------------------------------------------------
| VALIDAR DATA
|--------------------------------------------------------------------------
*/

function validDate(string $date): bool
{
    $d = DateTime::createFromFormat(
        'Y-m-d',
        $date
    );

    return
        $d
        &&
        $d->format('Y-m-d') === $date;
}


/*
|--------------------------------------------------------------------------
| URL DE RETORNO DO CONTAS A RECEBER
|--------------------------------------------------------------------------
|
| Mantém o período e o cliente selecionados depois de registrar pagamento.
|
*/

function receivablesReturnUrl(
    string $dateStart = '',
    string $dateEnd = '',
    int $clientId = 0
): string {

    $params = [];

    if ($dateStart !== '') {
        $params['date_start'] = $dateStart;
    }

    if ($dateEnd !== '') {
        $params['date_end'] = $dateEnd;
    }

    if ($clientId > 0) {
        $params['client_id'] = $clientId;
    }

    $url = base_url('receivables.php');

    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}


/*
|--------------------------------------------------------------------------
| NOME AMIGÁVEL DA FORMA DE PAGAMENTO
|--------------------------------------------------------------------------
*/

function paymentMethodLabel(string $method): string
{
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
| IDENTIFICAR COMPETÊNCIA DE UMA DATA
|--------------------------------------------------------------------------
|
| Regra:
| 01 a 15  -> competência do próprio mês
| 16 em diante -> competência do mês seguinte
|
*/

function competenceFromDate(string $date): DateTimeImmutable
{
    $d = new DateTimeImmutable($date);

    if ((int)$d->format('d') >= 16) {
        return $d
            ->modify('first day of next month');
    }

    return $d
        ->modify('first day of this month');
}


/*
|--------------------------------------------------------------------------
| PRIMEIRA COMPETÊNCIA A RECALCULAR
|--------------------------------------------------------------------------
*/

function firstReceivableCompetenceToRecalculate(
    PDO $pdo,
    DateTimeImmutable $requestedFirstCompetence
): DateTimeImmutable {

    $candidates = [
        $requestedFirstCompetence
    ];

    $minReceivable =
        $pdo->query("
            SELECT MIN(competence)
            FROM receivables
        ")->fetchColumn();

    if (
        is_string($minReceivable)
        &&
        preg_match('/^\d{4}-\d{2}$/', $minReceivable)
    ) {
        $candidates[] =
            new DateTimeImmutable(
                $minReceivable . '-01'
            );
    }

    $minInstallment =
        $pdo->query("
            SELECT MIN(due_date)
            FROM ticket_billing_installments
        ")->fetchColumn();

    if ($minInstallment) {
        $candidates[] =
            (new DateTimeImmutable($minInstallment))
            ->modify('first day of this month');
    }

    $first =
        $requestedFirstCompetence;

    foreach ($candidates as $candidate) {
        if ($candidate < $first) {
            $first = $candidate;
        }
    }

    return $first;
}


/*
|--------------------------------------------------------------------------
| RECALCULAR TODOS OS CICLOS DO PERÍODO PESQUISADO
|--------------------------------------------------------------------------
|
| Exemplo:
| pesquisa 16/09/2026 até 15/12/2026
|
| recalcula:
| competência 10/2026
| competência 11/2026
| competência 12/2026
|
| Assim as parcelas futuras já aparecem quando o usuário pesquisar
| os meses seguintes.
|
*/

function recalculateCyclesForPeriod(
    PDO $pdo,
    string $filterStart,
    string $filterEnd
): void {

    $requestedFirstCompetence =
        competenceFromDate(
            $filterStart
        );

    $firstCompetence =
        firstReceivableCompetenceToRecalculate(
            $pdo,
            $requestedFirstCompetence
        );

    $lastCompetence =
        competenceFromDate(
            $filterEnd
        );

    $current =
        $firstCompetence;

    while (
        $current <=
        $lastCompetence
    ) {

        $cycleEnd =
            $current
            ->setDate(
                (int)$current->format('Y'),
                (int)$current->format('m'),
                15
            );

        $previousMonth =
            $current
            ->modify(
                'first day of previous month'
            );

        $cycleStart =
            $previousMonth
            ->setDate(
                (int)$previousMonth->format('Y'),
                (int)$previousMonth->format('m'),
                16
            );

        recalculate_cycle(
            $pdo,
            $cycleStart->format('Y-m-d'),
            $cycleEnd->format('Y-m-d')
        );

        $current =
            $current
            ->modify(
                'first day of next month'
            );
    }
}


/*
|--------------------------------------------------------------------------
| RECALCULAR CICLO
|--------------------------------------------------------------------------
|
| Esta rotina substitui o antigo botão "Fechar ciclo".
|
| Ela será executada automaticamente ao abrir a página para o
| ciclo padrão:
|
| 16 do mês anterior
| até
| 15 do mês atual
|
|--------------------------------------------------------------------------
*/

function recalculate_cycle(
    PDO $pdo,
    string $start,
    string $end
): void {

    /*
    |--------------------------------------------------------------------------
    | COMPETÊNCIA
    |--------------------------------------------------------------------------
    |
    | Exemplo:
    | ciclo 16/08/2026 até 15/09/2026 = competência 2026-09
    |
    */

    $competence =
        (new DateTimeImmutable($end))
        ->format('Y-m');


    /*
    |--------------------------------------------------------------------------
    | CLIENTES ATIVOS
    |--------------------------------------------------------------------------
    */

    $clients =
        $pdo->query("
            SELECT *
            FROM clients
            WHERE deleted_at IS NULL
              AND status = 'ativo'
            ORDER BY name
        ")->fetchAll();


    foreach ($clients as $client) {

        /*
        |--------------------------------------------------------------------------
        | CONTA JÁ EXISTENTE DA COMPETÊNCIA
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT id
            FROM receivables
            WHERE client_id = ?
              AND competence = ?
            LIMIT 1
        ");

        $stmt->execute([
            $client['id'],
            $competence
        ]);

        $existingReceivableId =
            (int)($stmt->fetchColumn() ?: 0);


        /*
        |--------------------------------------------------------------------------
        | MENSALIDADE
        |--------------------------------------------------------------------------
        |
        | A mensalidade NÃO é parcelada.
        |
        */

        $monthly =
            $client['has_contract']
            ? (float)$client['monthly_fee']
            : 0;


        /*
        |--------------------------------------------------------------------------
        | PARCELAS DE SERVIÇOS DA COMPETÊNCIA
        |--------------------------------------------------------------------------
        |
        | Serviços parcelados entram somente no mês de vencimento
        | de cada parcela.
        |
        */

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(
                    SUM(tbi.amount),
                    0
                )
            FROM ticket_billing_installments tbi
            INNER JOIN tickets t
                ON t.id = tbi.ticket_id
            WHERE t.client_id = ?
              AND t.deleted_at IS NULL
              AND t.status IN ('solucionado', 'fechado')
              AND tbi.charge_type = 'servico'
              AND DATE_FORMAT(tbi.due_date, '%Y-%m') = ?
              AND (
                    tbi.receivable_id IS NULL
                    OR tbi.receivable_id = ?
                  )
        ");

        $stmt->execute([
            $client['id'],
            $competence,
            $existingReceivableId
        ]);

        $services =
            (float)$stmt->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | PARCELAS DE MATERIAIS DA COMPETÊNCIA
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(
                    SUM(tbi.amount),
                    0
                )
            FROM ticket_billing_installments tbi
            INNER JOIN tickets t
                ON t.id = tbi.ticket_id
            WHERE t.client_id = ?
              AND t.deleted_at IS NULL
              AND t.status IN ('solucionado', 'fechado')
              AND tbi.charge_type = 'materiais'
              AND DATE_FORMAT(tbi.due_date, '%Y-%m') = ?
              AND (
                    tbi.receivable_id IS NULL
                    OR tbi.receivable_id = ?
                  )
        ");

        $stmt->execute([
            $client['id'],
            $competence,
            $existingReceivableId
        ]);

        $materials =
            (float)$stmt->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | COMPATIBILIDADE COM CHAMADOS ANTIGOS - SERVIÇOS
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                t.id,
                t.service_amount
            FROM tickets t
            WHERE t.client_id = ?
              AND DATE(t.occurred_at) BETWEEN ? AND ?
              AND t.deleted_at IS NULL
              AND t.status IN ('solucionado', 'fechado')
              AND t.service_charged = 1
              AND t.service_amount > 0
              AND NOT EXISTS (
                    SELECT 1
                    FROM ticket_billing_installments tbi
                    WHERE tbi.ticket_id = t.id
                      AND tbi.charge_type = 'servico'
                  )
              AND (
                    t.billed_at IS NULL
                    OR t.receivable_id = ?
                  )
        ");

        $stmt->execute([
            $client['id'],
            $start,
            $end,
            $existingReceivableId
        ]);

        $legacyServiceTickets =
            $stmt->fetchAll();

        foreach ($legacyServiceTickets as $ticket) {
            $services +=
                (float)$ticket['service_amount'];
        }


        /*
        |--------------------------------------------------------------------------
        | COMPATIBILIDADE COM CHAMADOS ANTIGOS - MATERIAIS
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                t.id,
                COALESCE(
                    (
                        SELECT SUM(tm.total_price)
                        FROM ticket_materials tm
                        WHERE tm.ticket_id = t.id
                    ),
                    0
                ) AS materials_total
            FROM tickets t
            WHERE t.client_id = ?
              AND DATE(t.occurred_at) BETWEEN ? AND ?
              AND t.deleted_at IS NULL
              AND t.status IN ('solucionado', 'fechado')
              AND NOT EXISTS (
                    SELECT 1
                    FROM ticket_billing_installments tbi
                    WHERE tbi.ticket_id = t.id
                      AND tbi.charge_type = 'materiais'
                  )
              AND EXISTS (
                    SELECT 1
                    FROM ticket_materials tm2
                    WHERE tm2.ticket_id = t.id
                  )
              AND (
                    t.billed_at IS NULL
                    OR t.receivable_id = ?
                  )
        ");

        $stmt->execute([
            $client['id'],
            $start,
            $end,
            $existingReceivableId
        ]);

        $legacyMaterialTickets =
            $stmt->fetchAll();

        foreach ($legacyMaterialTickets as $ticket) {
            $materials +=
                (float)$ticket['materials_total'];
        }


        /*
        |--------------------------------------------------------------------------
        | VISITAS PRESENCIAIS
        |--------------------------------------------------------------------------
        |
        | O parcelamento se aplica somente a serviços e materiais.
        |
        */

        $stmt = $pdo->prepare("
            SELECT
                id,
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
            $client['id'],
            $start,
            $end
        ]);

        $presentialTickets =
            $stmt->fetchAll();

        $visits = 0;
        $freeVisitUsed = false;

        if ($client['has_contract']) {

            foreach ($presentialTickets as $ticket) {

                if (!$freeVisitUsed) {
                    $freeVisitUsed = true;
                    continue;
                }

                $visits +=
                    (float)$client['extra_visit_fee'];
            }
        }


        /*
        |--------------------------------------------------------------------------
        | SALDO ANTERIOR
        |--------------------------------------------------------------------------
        |
        | 09/2026 é a competência de implantação:
        | - antes dela, não propagamos mensalidades antigas;
        | - em 09/2026, entram somente serviços e materiais históricos pendentes;
        | - após 09/2026, segue a cadeia normal apenas para contas vencidas.
        |--------------------------------------------------------------------------
        */

        $todayDate =
            (new DateTimeImmutable('today'))
            ->format('Y-m-d');

        if (
            $competence
            <
            FINANCIAL_START_COMPETENCE
        ) {

            $previous = 0.0;
        } elseif (
            $competence
            ===
            FINANCIAL_START_COMPETENCE
        ) {

            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(
                        SUM(
                            LEAST(
                                GREATEST(
                                    services_amount
                                    + materials_amount,
                                    0
                                ),
                                GREATEST(
                                    total_amount
                                    - paid_amount,
                                    0
                                )
                            )
                        ),
                        0
                    )
                FROM receivables
                WHERE client_id = ?
                  AND competence < ?
            ");

            $stmt->execute([
                $client['id'],
                FINANCIAL_START_COMPETENCE
            ]);

            $previous =
                (float)$stmt->fetchColumn();
        } else {

            $stmt = $pdo->prepare("
                SELECT
                    GREATEST(
                        total_amount - paid_amount,
                        0
                    )
                FROM receivables
                WHERE client_id = ?
                  AND competence < ?
                  AND due_date < ?
                ORDER BY competence DESC
                LIMIT 1
            ");

            $stmt->execute([
                $client['id'],
                $competence,
                $todayDate
            ]);

            $previous =
                (float)($stmt->fetchColumn() ?: 0);
        }


        /*
        |--------------------------------------------------------------------------
        | ADIANTAMENTOS
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(
                    SUM(amount),
                    0
                )
            FROM advances
            WHERE client_id = ?
              AND advance_date <= ?
              AND (
                    consumed_at IS NULL
                    OR receivable_id = ?
                  )
        ");

        $stmt->execute([
            $client['id'],
            $end,
            $existingReceivableId
        ]);

        $advances =
            (float)$stmt->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | DESCONTOS
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(
                    SUM(amount),
                    0
                )
            FROM discounts
            WHERE client_id = ?
              AND reversed_at IS NULL
              AND discount_date <= ?
              AND (
                    consumed_at IS NULL
                    OR receivable_id = ?
                  )
        ");

        $stmt->execute([
            $client['id'],
            $end,
            $existingReceivableId
        ]);

        $discount =
            (float)$stmt->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | TOTAL
        |--------------------------------------------------------------------------
        */

        $total = max(
            0,
            $monthly
                + $services
                + $materials
                + $visits
                + $previous
                - $advances
                - $discount
        );


        /*
        |--------------------------------------------------------------------------
        | CLIENTE SEM MOVIMENTO
        |--------------------------------------------------------------------------
        */

        if (
            $total <= 0
            &&
            !$client['has_contract']
            &&
            $services <= 0
            &&
            $materials <= 0
            &&
            $visits <= 0
            &&
            !$existingReceivableId
        ) {
            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | VENCIMENTO
        |--------------------------------------------------------------------------
        */

        $endDate =
            new DateTimeImmutable($end);

        $year =
            (int)$endDate->format('Y');

        $month =
            (int)$endDate->format('m');

        $dueDay =
            max(
                1,
                min(
                    (int)$client['due_day'],
                    28
                )
            );

        $dueDate =
            $endDate
            ->setDate(
                $year,
                $month,
                $dueDay
            )
            ->format('Y-m-d');


        /*
        |--------------------------------------------------------------------------
        | TRANSAÇÃO
        |--------------------------------------------------------------------------
        */

        $pdo->beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | CRIAR / RECALCULAR CONTA
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO receivables (
                    client_id,
                    competence,
                    cycle_start,
                    cycle_end,
                    due_date,
                    monthly_fee,
                    services_amount,
                    materials_amount,
                    extra_visits_amount,
                    discount_amount,
                    advances_amount,
                    previous_balance,
                    total_amount
                )
                VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
                ON DUPLICATE KEY UPDATE
                    cycle_start = VALUES(cycle_start),
                    cycle_end = VALUES(cycle_end),
                    due_date = VALUES(due_date),
                    monthly_fee = VALUES(monthly_fee),
                    services_amount = VALUES(services_amount),
                    materials_amount = VALUES(materials_amount),
                    extra_visits_amount = VALUES(extra_visits_amount),
                    discount_amount = VALUES(discount_amount),
                    advances_amount = VALUES(advances_amount),
                    previous_balance = VALUES(previous_balance),
                    total_amount = VALUES(total_amount)
            ");

            $stmt->execute([
                $client['id'],
                $competence,
                $start,
                $end,
                $dueDate,
                $monthly,
                $services,
                $materials,
                $visits,
                $discount,
                $advances,
                $previous,
                $total
            ]);


            /*
            |--------------------------------------------------------------------------
            | BUSCAR ID DA CONTA
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM receivables
                WHERE client_id = ?
                  AND competence = ?
                LIMIT 1
            ");

            $stmt->execute([
                $client['id'],
                $competence
            ]);

            $receivableId =
                (int)$stmt->fetchColumn();


            /*
            |--------------------------------------------------------------------------
            | VINCULAR PARCELAS DA COMPETÊNCIA
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE ticket_billing_installments tbi
                INNER JOIN tickets t
                    ON t.id = tbi.ticket_id
                SET tbi.receivable_id = ?
                WHERE t.client_id = ?
                  AND t.deleted_at IS NULL
                  AND t.status IN ('solucionado', 'fechado')
                  AND DATE_FORMAT(tbi.due_date, '%Y-%m') = ?
                  AND (
                        tbi.receivable_id IS NULL
                        OR tbi.receivable_id = ?
                      )
            ");

            $stmt->execute([
                $receivableId,
                $client['id'],
                $competence,
                $receivableId
            ]);


            /*
            |--------------------------------------------------------------------------
            | MARCAR SOMENTE CHAMADOS LEGADOS COMO FATURADOS
            |--------------------------------------------------------------------------
            */

            $legacyTicketIds = [];

            foreach ($legacyServiceTickets as $ticket) {
                $legacyTicketIds[] =
                    (int)$ticket['id'];
            }

            foreach ($legacyMaterialTickets as $ticket) {
                $legacyTicketIds[] =
                    (int)$ticket['id'];
            }

            $legacyTicketIds =
                array_values(
                    array_unique(
                        $legacyTicketIds
                    )
                );

            if (!empty($legacyTicketIds)) {

                $placeholders =
                    implode(
                        ',',
                        array_fill(
                            0,
                            count($legacyTicketIds),
                            '?'
                        )
                    );

                $stmt = $pdo->prepare("
                    UPDATE tickets
                    SET
                        billed_at = COALESCE(
                            billed_at,
                            NOW()
                        ),
                        receivable_id = ?
                    WHERE id IN ({$placeholders})
                ");

                $stmt->execute([
                    $receivableId,
                    ...$legacyTicketIds
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | CONSUMIR ADIANTAMENTOS
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE advances
                SET
                    consumed_at = COALESCE(
                        consumed_at,
                        NOW()
                    ),
                    receivable_id = ?
                WHERE client_id = ?
                  AND advance_date <= ?
                  AND (
                        consumed_at IS NULL
                        OR receivable_id = ?
                      )
            ");

            $stmt->execute([
                $receivableId,
                $client['id'],
                $end,
                $receivableId
            ]);


            /*
            |--------------------------------------------------------------------------
            | CONSUMIR DESCONTOS
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE discounts
                SET
                    consumed_at = COALESCE(
                        consumed_at,
                        NOW()
                    ),
                    receivable_id = ?
                WHERE client_id = ?
                  AND reversed_at IS NULL
                  AND discount_date <= ?
                  AND (
                        consumed_at IS NULL
                        OR receivable_id = ?
                      )
            ");

            $stmt->execute([
                $receivableId,
                $client['id'],
                $end,
                $receivableId
            ]);


            /*
            |--------------------------------------------------------------------------
            | RECALCULAR STATUS
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE receivables
                SET status =
                    CASE
                        WHEN paid_amount >= total_amount
                            THEN 'pago'
                        WHEN paid_amount > 0
                            THEN 'parcial'
                        ELSE 'pendente'
                    END
                WHERE id = ?
            ");

            $stmt->execute([
                $receivableId
            ]);

            $pdo->commit();
        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}



/*
|--------------------------------------------------------------------------
| PERÍODO PADRÃO
|--------------------------------------------------------------------------
|
| Sempre:
|
| 16 do mês anterior
| até
| 15 do mês atual
|
*/

$today =
    new DateTimeImmutable('today');


$defaultStart =
    $today
    ->modify(
        'first day of previous month'
    )
    ->format('Y-m-16');


$defaultEnd =
    $today
    ->format('Y-m-15');


/*
|--------------------------------------------------------------------------
| PROCESSAR PAGAMENTO
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    csrf_check();

    $action =
        $_POST['action']
        ?? '';

    /*
    |--------------------------------------------------------------------------
    | DADOS PARA RETORNO
    |--------------------------------------------------------------------------
    */

    $returnDateStart =
        trim(
            $_POST['return_date_start']
                ?? ''
        );

    $returnDateEnd =
        trim(
            $_POST['return_date_end']
                ?? ''
        );

    $returnClientId =
        (int)(
            $_POST['return_client_id']
            ?? 0
        );

    $returnUrl =
        receivablesReturnUrl(
            $returnDateStart,
            $returnDateEnd,
            $returnClientId
        );


    /*
    |--------------------------------------------------------------------------
    | REGISTRAR PAGAMENTO
    |--------------------------------------------------------------------------
    */

    if ($action === 'pay') {

        $receivableId =
            (int)(
                $_POST['receivable_id']
                ?? 0
            );

        $paymentDate =
            trim(
                $_POST['payment_date']
                    ?? ''
            );

        $amount =
            (float)str_replace(
                ',',
                '.',
                $_POST['amount']
                    ?? 0
            );

        $paymentMethod =
            trim(
                $_POST['payment_method']
                    ?? ''
            );

        $notes =
            trim(
                $_POST['notes']
                    ?? ''
            );


        /*
        |--------------------------------------------------------------------------
        | FORMAS DE PAGAMENTO PERMITIDAS
        |--------------------------------------------------------------------------
        */

        $allowedPaymentMethods = [
            'pix',
            'debito',
            'credito',
            'dinheiro',
            'boleto',
            'transferencia'
        ];


        /*
        |--------------------------------------------------------------------------
        | VALIDAÇÕES BÁSICAS
        |--------------------------------------------------------------------------
        */

        if (!$receivableId) {

            flash(
                'danger',
                'Conta a receber inválida.'
            );

            redirect($returnUrl);
        }

        if (
            $paymentDate === ''
            ||
            !validDate($paymentDate)
        ) {

            flash(
                'danger',
                'Informe uma data de pagamento válida.'
            );

            redirect($returnUrl);
        }

        if ($amount <= 0) {

            flash(
                'danger',
                'Informe um valor de pagamento maior que zero.'
            );

            redirect($returnUrl);
        }

        if (
            !in_array(
                $paymentMethod,
                $allowedPaymentMethods,
                true
            )
        ) {

            flash(
                'danger',
                'Selecione uma forma de pagamento válida.'
            );

            redirect($returnUrl);
        }


        /*
        |--------------------------------------------------------------------------
        | TRANSAÇÃO
        |--------------------------------------------------------------------------
        */

        $pdo->beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | BLOQUEAR A CONTA E CONFERIR SALDO
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    r.id,
                    r.total_amount,
                    r.paid_amount,
                    r.status,
                    c.name AS client
                FROM receivables r
                INNER JOIN clients c
                    ON c.id = r.client_id
                WHERE r.id = ?
                FOR UPDATE
            ");

            $stmt->execute([
                $receivableId
            ]);

            $receivable =
                $stmt->fetch();

            if (!$receivable) {

                throw new Exception(
                    'Conta a receber não encontrada.'
                );
            }

            $currentBalance =
                max(
                    0,
                    (float)$receivable['total_amount']
                        -
                        (float)$receivable['paid_amount']
                );

            if ($currentBalance <= 0) {

                throw new Exception(
                    'Esta conta não possui saldo pendente.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | NÃO PERMITIR PAGAMENTO MAIOR QUE O SALDO
            |--------------------------------------------------------------------------
            */

            if (
                $amount
                >
                ($currentBalance + 0.00001)
            ) {

                throw new Exception(
                    'O pagamento informado é maior que o saldo da conta. '
                        . 'Saldo atual: '
                        . money($currentBalance)
                        . '.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | REGISTRAR PAGAMENTO
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO payments (
                    receivable_id,
                    payment_date,
                    amount,
                    payment_method,
                    notes
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            $stmt->execute([
                $receivableId,
                $paymentDate,
                $amount,
                $paymentMethod,
                $notes
            ]);


            /*
            |--------------------------------------------------------------------------
            | ATUALIZAR VALOR PAGO
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE receivables
                SET paid_amount =
                    paid_amount + ?
                WHERE id = ?
            ");

            $stmt->execute([
                $amount,
                $receivableId
            ]);


            /*
            |--------------------------------------------------------------------------
            | RECALCULAR STATUS
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE receivables
                SET status =
                    CASE
                        WHEN paid_amount >= total_amount
                            THEN 'pago'
                        WHEN paid_amount > 0
                            THEN 'parcial'
                        ELSE 'pendente'
                    END
                WHERE id = ?
            ");

            $stmt->execute([
                $receivableId
            ]);

            $pdo->commit();

            flash(
                'success',
                'Pagamento de '
                    . money($amount)
                    . ' registrado para '
                    . $receivable['client']
                    . ' em '
                    . date(
                        'd/m/Y',
                        strtotime(
                            $paymentDate
                        )
                    )
                    . '.'
            );
        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash(
                'danger',
                $e->getMessage()
            );
        }

        redirect($returnUrl);
    }


    /*
    |--------------------------------------------------------------------------
    | ESTORNAR ÚLTIMO PAGAMENTO
    |--------------------------------------------------------------------------
    |
    | O pagamento não é apagado.
    | Apenas recebe data, usuário e motivo do estorno.
    |
    */

    if ($action === 'reverse_payment') {

        $paymentId =
            (int)(
                $_POST['payment_id']
                ?? 0
            );

        $reason =
            trim(
                $_POST['reversal_reason']
                    ?? ''
            );

        if (!$paymentId) {

            flash(
                'danger',
                'Pagamento inválido para estorno.'
            );

            redirect($returnUrl);
        }

        if ($reason === '') {

            flash(
                'danger',
                'Informe o motivo do estorno.'
            );

            redirect($returnUrl);
        }


        $pdo->beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | BUSCAR PAGAMENTO
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    p.*,
                    r.client_id,
                    c.name AS client
                FROM payments p
                INNER JOIN receivables r
                    ON r.id = p.receivable_id
                INNER JOIN clients c
                    ON c.id = r.client_id
                WHERE p.id = ?
                FOR UPDATE
            ");

            $stmt->execute([
                $paymentId
            ]);

            $payment =
                $stmt->fetch();

            if (!$payment) {

                throw new Exception(
                    'Pagamento não encontrado.'
                );
            }

            if (!empty($payment['reversed_at'])) {

                throw new Exception(
                    'Este pagamento já foi estornado.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | GARANTIR QUE É O ÚLTIMO PAGAMENTO ATIVO
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM payments
                WHERE receivable_id = ?
                  AND reversed_at IS NULL
                ORDER BY
                    created_at DESC,
                    id DESC
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $payment['receivable_id']
            ]);

            $lastActivePaymentId =
                (int)$stmt->fetchColumn();

            if (
                $lastActivePaymentId
                !==
                $paymentId
            ) {

                throw new Exception(
                    'Somente o último pagamento ativo desta conta pode ser estornado.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | MARCAR ESTORNO
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE payments
                SET
                    reversed_at = NOW(),
                    reversed_by = ?,
                    reversal_reason = ?
                WHERE id = ?
                  AND reversed_at IS NULL
            ");

            $stmt->execute([
                auth_user()['id'],
                $reason,
                $paymentId
            ]);


            /*
            |--------------------------------------------------------------------------
            | RECALCULAR TOTAL PAGO A PARTIR DO HISTÓRICO ATIVO
            |--------------------------------------------------------------------------
            |
            | Preferimos recalcular em vez de apenas subtrair o valor.
            | Assim o paid_amount permanece consistente com payments.
            |
            */

            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(
                        SUM(amount),
                        0
                    )
                FROM payments
                WHERE receivable_id = ?
                  AND reversed_at IS NULL
            ");

            $stmt->execute([
                $payment['receivable_id']
            ]);

            $activePaidAmount =
                (float)$stmt->fetchColumn();


            /*
            |--------------------------------------------------------------------------
            | ATUALIZAR CONTA E STATUS
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE receivables
                SET
                    paid_amount = ?,
                    status =
                        CASE
                            WHEN ? >= total_amount
                                THEN 'pago'
                            WHEN ? > 0
                                THEN 'parcial'
                            ELSE 'pendente'
                        END
                WHERE id = ?
            ");

            $stmt->execute([
                $activePaidAmount,
                $activePaidAmount,
                $activePaidAmount,
                $payment['receivable_id']
            ]);

            $pdo->commit();

            flash(
                'success',
                'Pagamento de '
                    . money($payment['amount'])
                    . ' estornado com sucesso para '
                    . $payment['client']
                    . '.'
            );
        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash(
                'danger',
                $e->getMessage()
            );
        }

        redirect($returnUrl);
    }


    /*
    |--------------------------------------------------------------------------
    | REGISTRAR DESCONTO
    |--------------------------------------------------------------------------
    */

    if ($action === 'add_discount') {

        $receivableId = (int)($_POST['receivable_id'] ?? 0);
        $discountDate = trim($_POST['discount_date'] ?? '');
        $amount = (float)str_replace(',', '.', $_POST['amount'] ?? 0);
        $referenceText = trim($_POST['reference_text'] ?? '');

        if (!$receivableId) {
            flash('danger', 'Conta a receber inválida para desconto.');
            redirect($returnUrl);
        }

        if ($discountDate === '' || !validDate($discountDate)) {
            flash('danger', 'Informe uma data válida para o desconto.');
            redirect($returnUrl);
        }

        if ($amount <= 0) {
            flash('danger', 'Informe um valor de desconto maior que zero.');
            redirect($returnUrl);
        }

        if ($referenceText === '') {
            flash('danger', 'Informe o motivo ou referência do desconto.');
            redirect($returnUrl);
        }

        $pdo->beginTransaction();

        try {

            $stmt = $pdo->prepare("
                SELECT
                    r.*,
                    c.name AS client
                FROM receivables r
                INNER JOIN clients c
                    ON c.id = r.client_id
                WHERE r.id = ?
                FOR UPDATE
            ");

            $stmt->execute([$receivableId]);
            $receivable = $stmt->fetch();

            if (!$receivable) {
                throw new Exception('Conta a receber não encontrada.');
            }

            $currentBalance = max(
                0,
                (float)$receivable['total_amount']
                    - (float)$receivable['paid_amount']
            );

            if ($currentBalance <= 0) {
                throw new Exception(
                    'Esta conta não possui saldo pendente para aplicação de desconto.'
                );
            }

            if ($amount > ($currentBalance + 0.00001)) {
                throw new Exception(
                    'O desconto informado é maior que o saldo atual da conta. '
                        . 'Saldo atual: ' . money($currentBalance) . '.'
                );
            }

            $stmt = $pdo->prepare("
                INSERT INTO discounts (
                    client_id,
                    amount,
                    discount_date,
                    reference_text,
                    consumed_at,
                    receivable_id
                )
                VALUES (?, ?, ?, ?, NOW(), ?)
            ");

            $stmt->execute([
                $receivable['client_id'],
                $amount,
                $discountDate,
                $referenceText,
                $receivableId
            ]);

            $newDiscountAmount =
                (float)$receivable['discount_amount'] + $amount;

            $newTotal =
                max(0, (float)$receivable['total_amount'] - $amount);

            $newStatus =
                (float)$receivable['paid_amount'] >= $newTotal
                ? 'pago'
                : ((float)$receivable['paid_amount'] > 0
                    ? 'parcial'
                    : 'pendente');

            $stmt = $pdo->prepare("
                UPDATE receivables
                SET
                    discount_amount = ?,
                    total_amount = ?,
                    status = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $newDiscountAmount,
                $newTotal,
                $newStatus,
                $receivableId
            ]);

            $pdo->commit();

            flash(
                'success',
                'Desconto de '
                    . money($amount)
                    . ' registrado para '
                    . $receivable['client']
                    . '.'
            );
        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash('danger', $e->getMessage());
        }

        redirect($returnUrl);
    }


    /*
    |--------------------------------------------------------------------------
    | ESTORNAR ÚLTIMO DESCONTO ATIVO
    |--------------------------------------------------------------------------
    */

    if ($action === 'reverse_discount') {

        $discountId = (int)($_POST['discount_id'] ?? 0);
        $reason = trim($_POST['reversal_reason'] ?? '');

        if (!$discountId) {
            flash('danger', 'Desconto inválido para estorno.');
            redirect($returnUrl);
        }

        if ($reason === '') {
            flash('danger', 'Informe o motivo do estorno.');
            redirect($returnUrl);
        }

        $pdo->beginTransaction();

        try {

            $stmt = $pdo->prepare("
                SELECT
                    d.*,
                    c.name AS client
                FROM discounts d
                INNER JOIN clients c
                    ON c.id = d.client_id
                WHERE d.id = ?
                FOR UPDATE
            ");

            $stmt->execute([$discountId]);
            $discount = $stmt->fetch();

            if (!$discount) {
                throw new Exception('Desconto não encontrado.');
            }

            if (!empty($discount['reversed_at'])) {
                throw new Exception('Este desconto já foi estornado.');
            }

            $stmt = $pdo->prepare("
                SELECT id
                FROM discounts
                WHERE receivable_id = ?
                  AND reversed_at IS NULL
                ORDER BY created_at DESC, id DESC
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([$discount['receivable_id']]);
            $lastActiveDiscountId = (int)$stmt->fetchColumn();

            if ($lastActiveDiscountId !== $discountId) {
                throw new Exception(
                    'Somente o último desconto ativo desta conta pode ser estornado.'
                );
            }

            $stmt = $pdo->prepare("
                SELECT *
                FROM receivables
                WHERE id = ?
                FOR UPDATE
            ");

            $stmt->execute([$discount['receivable_id']]);
            $receivable = $stmt->fetch();

            if (!$receivable) {
                throw new Exception('Conta vinculada ao desconto não encontrada.');
            }

            $stmt = $pdo->prepare("
                UPDATE discounts
                SET
                    reversed_at = NOW(),
                    reversed_by = ?,
                    reversal_reason = ?
                WHERE id = ?
                  AND reversed_at IS NULL
            ");

            $stmt->execute([
                auth_user()['id'],
                $reason,
                $discountId
            ]);

            $newDiscountAmount = max(
                0,
                (float)$receivable['discount_amount']
                    - (float)$discount['amount']
            );

            $newTotal =
                (float)$receivable['total_amount']
                + (float)$discount['amount'];

            $newStatus =
                (float)$receivable['paid_amount'] >= $newTotal
                ? 'pago'
                : ((float)$receivable['paid_amount'] > 0
                    ? 'parcial'
                    : 'pendente');

            $stmt = $pdo->prepare("
                UPDATE receivables
                SET
                    discount_amount = ?,
                    total_amount = ?,
                    status = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $newDiscountAmount,
                $newTotal,
                $newStatus,
                $discount['receivable_id']
            ]);

            $pdo->commit();

            flash(
                'success',
                'Desconto de '
                    . money($discount['amount'])
                    . ' estornado com sucesso para '
                    . $discount['client']
                    . '.'
            );
        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash('danger', $e->getMessage());
        }

        redirect($returnUrl);
    }
}


/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$filterStart =
    trim(
        $_GET['date_start']
            ?? $defaultStart
    );


$filterEnd =
    trim(
        $_GET['date_end']
            ?? $defaultEnd
    );


$filterClient =
    (int)(
        $_GET['client_id']
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| VALIDAR DATA INICIAL
|--------------------------------------------------------------------------
*/

if (!validDate($filterStart)) {

    $filterStart =
        $defaultStart;
}


/*
|--------------------------------------------------------------------------
| VALIDAR DATA FINAL
|--------------------------------------------------------------------------
*/

if (!validDate($filterEnd)) {

    $filterEnd =
        $defaultEnd;
}


/*
|--------------------------------------------------------------------------
| DATA FINAL NÃO PODE SER MENOR QUE INICIAL
|--------------------------------------------------------------------------
*/

if ($filterEnd < $filterStart) {

    flash(
        'danger',
        'A data final não pode ser anterior à data inicial.'
    );


    redirect(
        base_url(
            'receivables.php'
        )
    );
}


/*
|--------------------------------------------------------------------------
| RECALCULAR CICLOS DA PESQUISA
|--------------------------------------------------------------------------
|
| Na abertura normal, recalcula o período padrão.
| Se o usuário pesquisar meses posteriores, os respectivos ciclos também
| serão calculados e apresentados, incluindo as próximas parcelas.
|
*/

try {

    recalculateCyclesForPeriod(
        $pdo,
        $filterStart,
        $filterEnd
    );
} catch (Throwable $e) {

    flash(
        'danger',
        'Não foi possível recalcular o período pesquisado: '
            . $e->getMessage()
    );
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
| CONSULTA
|--------------------------------------------------------------------------
|
| Um ciclo será exibido quando ele tiver interseção com o período
| pesquisado.
|
*/

$sql = "
    SELECT

        r.*,

        c.name AS client,

        (
            SELECT
                GROUP_CONCAT(
                    CONCAT(
                        t.ticket_number,
                        ': ',
                        CASE
                            WHEN tbi.charge_type = 'servico'
                                THEN 'Serviço '
                            ELSE 'Materiais '
                        END,
                        tbi.installment_number,
                        'x',
                        tbi.installment_count
                    )
                    ORDER BY
                        t.ticket_number,
                        tbi.charge_type,
                        tbi.installment_number
                    SEPARATOR '\n'
                )
            FROM ticket_billing_installments tbi
            INNER JOIN tickets t
                ON t.id = tbi.ticket_id
            WHERE tbi.receivable_id = r.id
        ) AS installment_info

    FROM receivables r

    INNER JOIN clients c
        ON c.id = r.client_id

    WHERE
        r.cycle_start <= ?
        AND r.cycle_end >= ?
";


$params = [
    $filterEnd,
    $filterStart
];


/*
|--------------------------------------------------------------------------
| CLIENTE
|--------------------------------------------------------------------------
*/

if ($filterClient > 0) {

    $sql .= "
        AND r.client_id = ?
    ";


    $params[] =
        $filterClient;
}


$sql .= "
    ORDER BY
        r.competence DESC,
        c.name
";


$stmt =
    $pdo->prepare($sql);


$stmt->execute($params);


$rows =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| HISTÓRICO DE PAGAMENTOS DAS CONTAS EXIBIDAS
|--------------------------------------------------------------------------
*/

$paymentsByReceivable = [];
$lastActivePaymentByReceivable = [];

if (!empty($rows)) {

    $receivableIds =
        array_map(
            static fn(array $row): int =>
            (int)$row['id'],
            $rows
        );

    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($receivableIds),
                '?'
            )
        );

    $stmt = $pdo->prepare("
        SELECT
            p.*,
            u.name AS reversed_by_name
        FROM payments p
        LEFT JOIN users u
            ON u.id = p.reversed_by
        WHERE p.receivable_id IN ({$placeholders})
        ORDER BY
            p.payment_date DESC,
            p.created_at DESC,
            p.id DESC
    ");

    $stmt->execute(
        $receivableIds
    );

    foreach (
        $stmt->fetchAll()
        as $payment
    ) {

        $rid =
            (int)$payment['receivable_id'];

        $paymentsByReceivable[$rid][] =
            $payment;
    }


    /*
    |--------------------------------------------------------------------------
    | IDENTIFICAR ÚLTIMO PAGAMENTO ATIVO POR CONTA
    |--------------------------------------------------------------------------
    */

    foreach (
        $paymentsByReceivable
        as $rid =>
        $paymentList
    ) {

        $lastActiveId = 0;

        foreach ($paymentList as $payment) {

            if (!empty($payment['reversed_at'])) {
                continue;
            }

            /*
            | A lista está ordenada por data de pagamento para exibição,
            | mas "último lançamento" deve respeitar created_at/id.
            */

            if (!$lastActiveId) {
                $lastActiveId =
                    (int)$payment['id'];
            }
        }

        if ($lastActiveId) {

            /*
            | Confirma pelo mesmo critério usado no backend.
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM payments
                WHERE receivable_id = ?
                  AND reversed_at IS NULL
                ORDER BY
                    created_at DESC,
                    id DESC
                LIMIT 1
            ");

            $stmt->execute([
                $rid
            ]);

            $lastActivePaymentByReceivable[$rid] =
                (int)$stmt->fetchColumn();
        }
    }
}



/*
|--------------------------------------------------------------------------
| HISTÓRICO DE DESCONTOS
|--------------------------------------------------------------------------
*/

$discountsByReceivable = [];
$lastActiveDiscountByReceivable = [];

if (!empty($rows)) {

    $receivableIds = array_map(
        static fn(array $row): int => (int)$row['id'],
        $rows
    );

    $placeholders = implode(
        ',',
        array_fill(0, count($receivableIds), '?')
    );

    $stmt = $pdo->prepare("
        SELECT
            d.*,
            u.name AS reversed_by_name
        FROM discounts d
        LEFT JOIN users u
            ON u.id = d.reversed_by
        WHERE d.receivable_id IN ({$placeholders})
        ORDER BY
            d.discount_date DESC,
            d.created_at DESC,
            d.id DESC
    ");

    $stmt->execute($receivableIds);

    foreach ($stmt->fetchAll() as $discount) {
        $rid = (int)$discount['receivable_id'];
        $discountsByReceivable[$rid][] = $discount;
    }

    foreach ($receivableIds as $rid) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM discounts
            WHERE receivable_id = ?
              AND reversed_at IS NULL
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");

        $stmt->execute([$rid]);

        $lastId = (int)$stmt->fetchColumn();

        if ($lastId) {
            $lastActiveDiscountByReceivable[$rid] = $lastId;
        }
    }
}


render_header(
    'Contas a Receber'
);

?>


<!-- ================================================================
     FILTROS
================================================================= -->

<div class="card mb-3">

    <div class="card-body">


        <form
            method="get"
            id="receivablesFilterForm">


            <div
                class="
                    row
                    g-2
                    align-items-end
                ">


                <!-- DE -->

                <div class="col-md-2">

                    <label class="form-label">

                        De

                    </label>


                    <input
                        class="form-control"
                        type="date"
                        name="date_start"
                        id="date_start"
                        value="<?= e(
                                    $filterStart
                                ) ?>"
                        required>

                </div>


                <!-- ATÉ -->

                <div class="col-md-2">

                    <label class="form-label">

                        Até

                    </label>


                    <input
                        class="form-control"
                        type="date"
                        name="date_end"
                        id="date_end"
                        min="<?= e(
                                    $filterStart
                                ) ?>"
                        value="<?= e(
                                    $filterEnd
                                ) ?>"
                        required>

                </div>


                <!-- CLIENTE -->

                <div class="col-md-4">

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
                                <?= (
                                    $filterClient
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


                <!-- FILTRAR -->

                <div class="col-md-auto">

                    <button
                        class="btn btn-primary"
                        type="submit">

                        Filtrar

                    </button>

                </div>


                <!-- LIMPAR -->

                <div class="col-md-auto">

                    <a
                        href="<?= e(
                                    base_url(
                                        'receivables.php'
                                    )
                                ) ?>"
                        class="
                            btn
                            btn-outline-secondary
                        ">

                        Período padrão

                    </a>

                </div>


            </div>


        </form>


    </div>

</div>


<!-- ================================================================
     INFORMAÇÃO DO PERÍODO
================================================================= -->

<div class="mb-3 text-muted">

    Período exibido:

    <strong>

        <?= date(
            'd/m/Y',
            strtotime(
                $filterStart
            )
        ) ?>

    </strong>

    até

    <strong>

        <?= date(
            'd/m/Y',
            strtotime(
                $filterEnd
            )
        ) ?>

    </strong>

</div>



<style>
    #receivablesTable {
        font-size: 0.92rem;
    }

    #receivablesTable> :not(caption)>*>* {
        padding-top: 0.78rem;
        padding-bottom: 0.78rem;
        padding-left: 0.55rem;
        padding-right: 0.55rem;
        vertical-align: middle;
    }

    #receivablesTable th,
    #receivablesTable td {
        white-space: nowrap;
    }

    #receivablesTable .client-cell {
        white-space: normal;
        min-width: 145px;
    }

    #receivablesTable .installment-cell {
        white-space: normal;
        min-width: 145px;
        line-height: 1.35;
    }

    .receivable-status {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 82px;
        padding: 0.42rem 0.72rem;
        border-radius: 999px;
        font-size: 0.84rem;
        font-weight: 700;
        line-height: 1;
        color: #fff;
        text-transform: capitalize;
    }

    .receivable-status-paid {
        background: var(--bs-success);
    }

    .receivable-status-pending {
        background: tomato;
    }

    .receivable-status-partial {
        background: var(--bs-warning);
        color: #212529;
    }

    .actions-menu-button {
        min-width: 42px;
        font-size: 1.2rem;
        line-height: 1;
        font-weight: 700;
        letter-spacing: 2px;
    }

    .receivable-actions .dropdown-menu {
        min-width: 210px;
    }

    .receivable-actions .dropdown-item {
        padding-top: 0.55rem;
        padding-bottom: 0.55rem;
    }
</style>

<!-- ================================================================
     TABELA
================================================================= -->

<div class="table-wrap">

    <div class="table-responsive">

        <table
            id="receivablesTable"
            class="
                table
                table-striped
                table-hover
                align-middle
            ">


            <thead>

                <tr>

                    <th>
                        Cliente
                    </th>

                    <th>
                        Competência
                    </th>

                    <th>
                        Vencimento
                    </th>

                    <th>
                        Mensalidade
                    </th>

                    <th>
                        Serviços
                    </th>

                    <th>
                        Materiais
                    </th>

                    <th>
                        Visitas
                    </th>

                    <th>
                        Desconto
                    </th>

                    <th>
                        Saldo anterior
                    </th>

                    <th>
                        Total
                    </th>

                    <th>
                        Pago
                    </th>

                    <th>
                        Saldo
                    </th>

                    <th>
                        Parcelas
                    </th>

                    <th>
                        Status
                    </th>

                    <th class="text-center">
                        Ações
                    </th>

                </tr>

            </thead>


            <tbody>


                <?php foreach ($rows as $row): ?>


                    <?php

                    $balance =

                        (float)$row['total_amount']

                        -

                        (float)$row['paid_amount'];

                    ?>


                    <tr>


                        <td class="client-cell">

                            <?= e(
                                $row['client']
                            ) ?>

                        </td>


                        <td>

                            <?= e(
                                $row['competence']
                            ) ?>

                        </td>


                        <td>

                            <?= date(
                                'd/m/Y',
                                strtotime(
                                    $row['due_date']
                                )
                            ) ?>

                        </td>


                        <td>

                            <?= money(
                                $row['monthly_fee']
                            ) ?>

                        </td>


                        <td>

                            <?= money(
                                $row['services_amount']
                            ) ?>

                        </td>


                        <td>

                            <?= money(
                                $row['materials_amount']
                            ) ?>

                        </td>


                        <td>

                            <?= money(
                                $row['extra_visits_amount']
                            ) ?>

                        </td>


                        <td>

                            <?= money(
                                $row['discount_amount']
                            ) ?>

                        </td>


                        <td>

                            <?= money(
                                $row['previous_balance']
                            ) ?>

                        </td>


                        <th>

                            <?= money(
                                $row['total_amount']
                            ) ?>

                        </th>


                        <td>

                            <?= money(
                                $row['paid_amount']
                            ) ?>

                        </td>


                        <td>

                            <?= money(
                                $balance
                            ) ?>

                        </td>


                        <td class="small installment-cell">

                            <?php if (
                                !empty($row['installment_info'])
                            ): ?>

                                <?= nl2br(
                                    e(
                                        $row['installment_info']
                                    )
                                ) ?>

                            <?php else: ?>

                                <span class="text-muted">
                                    —
                                </span>

                            <?php endif; ?>

                        </td>


                        <td>

                            <?php

                            $statusClass =
                                match ($row['status']) {
                                    'pago' =>
                                    'receivable-status-paid',
                                    'pendente' =>
                                    'receivable-status-pending',
                                    'parcial' =>
                                    'receivable-status-partial',
                                    default =>
                                    'bg-secondary',
                                };

                            ?>

                            <span
                                class="receivable-status <?= e($statusClass) ?>">
                                <?= e($row['status']) ?>
                            </span>

                        </td>


                        <td class="text-center receivable-actions">

                            <div class="dropdown">

                                <button
                                    class="btn btn-sm btn-outline-secondary actions-menu-button"
                                    type="button"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false"
                                    title="Ações">
                                    ⋮
                                </button>

                                <ul class="dropdown-menu dropdown-menu-end">

                                    <?php if ($balance > 0): ?>

                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item"
                                                data-bs-toggle="modal"
                                                data-bs-target="#paymentModal<?= (int)$row['id'] ?>">
                                                Registrar pagamento
                                            </button>
                                        </li>

                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item"
                                                data-bs-toggle="modal"
                                                data-bs-target="#discountModal<?= (int)$row['id'] ?>">
                                                Desconto
                                            </button>
                                        </li>

                                    <?php endif; ?>

                                    <li>
                                        <button
                                            type="button"
                                            class="dropdown-item"
                                            data-bs-toggle="modal"
                                            data-bs-target="#paymentHistoryModal<?= (int)$row['id'] ?>">
                                            Histórico
                                        </button>
                                    </li>

                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>

                                    <li>
                                        <a
                                            class="dropdown-item"
                                            href="<?= e(
                                                        base_url(
                                                            'receivable_report.php?id='
                                                                . (int)$row['id']
                                                        )
                                                    ) ?>">
                                            Relatório
                                        </a>
                                    </li>

                                </ul>

                            </div>

                        </td>


                    </tr>


                <?php endforeach; ?>


                <?php if (
                    empty($rows)
                ): ?>


                    <tr>

                        <td
                            colspan="15"
                            class="
                                text-center
                                text-muted
                                py-4
                            ">

                            Nenhuma conta encontrada
                            para o período e cliente
                            selecionados.

                        </td>

                    </tr>


                <?php endif; ?>


            </tbody>


        </table>

    </div>

</div>



<!-- ================================================================
     MODAIS DE PAGAMENTO
================================================================= -->

<?php foreach ($rows as $row): ?>

    <?php

    $modalBalance =
        max(
            0,
            (float)$row['total_amount']
                -
                (float)$row['paid_amount']
        );

    ?>

    <?php if ($modalBalance > 0): ?>

        <div
            class="modal fade"
            id="paymentModal<?= (int)$row['id'] ?>"
            tabindex="-1"
            aria-hidden="true">

            <div class="modal-dialog modal-dialog-centered">

                <div class="modal-content">

                    <form method="post">

                        <div class="modal-header">

                            <h5 class="modal-title">
                                Registrar pagamento
                            </h5>

                            <button
                                type="button"
                                class="btn-close"
                                data-bs-dismiss="modal"
                                aria-label="Fechar"></button>

                        </div>


                        <div class="modal-body">

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= e(csrf_token()) ?>">

                            <input
                                type="hidden"
                                name="action"
                                value="pay">

                            <input
                                type="hidden"
                                name="receivable_id"
                                value="<?= (int)$row['id'] ?>">

                            <input
                                type="hidden"
                                name="return_date_start"
                                value="<?= e($filterStart) ?>">

                            <input
                                type="hidden"
                                name="return_date_end"
                                value="<?= e($filterEnd) ?>">

                            <input
                                type="hidden"
                                name="return_client_id"
                                value="<?= (int)$filterClient ?>">


                            <!-- RESUMO -->

                            <div class="card bg-body-tertiary mb-3">

                                <div class="card-body py-2">

                                    <div class="mb-1">
                                        <strong>Cliente:</strong>
                                        <?= e($row['client']) ?>
                                    </div>

                                    <div class="mb-1">
                                        <strong>Competência:</strong>
                                        <?= e($row['competence']) ?>
                                    </div>

                                    <div class="mb-1">
                                        <strong>Total da conta:</strong>
                                        <?= money($row['total_amount']) ?>
                                    </div>

                                    <div class="mb-1">
                                        <strong>Já pago:</strong>
                                        <?= money($row['paid_amount']) ?>
                                    </div>

                                    <div>
                                        <strong>Saldo atual:</strong>
                                        <span class="fw-bold">
                                            <?= money($modalBalance) ?>
                                        </span>
                                    </div>

                                </div>

                            </div>


                            <!-- DATA -->

                            <div class="mb-3">

                                <label class="form-label">
                                    Data do pagamento
                                </label>

                                <input
                                    class="form-control"
                                    type="date"
                                    name="payment_date"
                                    value="<?= date('Y-m-d') ?>"
                                    required>

                            </div>


                            <!-- VALOR -->

                            <div class="mb-3">

                                <label class="form-label">
                                    Valor recebido
                                </label>

                                <input
                                    class="form-control"
                                    type="number"
                                    name="amount"
                                    min="0.01"
                                    max="<?= e(
                                                number_format(
                                                    $modalBalance,
                                                    2,
                                                    '.',
                                                    ''
                                                )
                                            ) ?>"
                                    step="0.01"
                                    value="<?= e(
                                                number_format(
                                                    $modalBalance,
                                                    2,
                                                    '.',
                                                    ''
                                                )
                                            ) ?>"
                                    required>

                                <div class="form-text">
                                    O valor não pode ser maior que o saldo atual.
                                </div>

                            </div>


                            <!-- FORMA -->

                            <div class="mb-3">

                                <label class="form-label">
                                    Forma de pagamento
                                </label>

                                <select
                                    class="form-select"
                                    name="payment_method"
                                    required>

                                    <option value="pix">
                                        PIX
                                    </option>

                                    <option value="transferencia">
                                        Transferência bancária
                                    </option>

                                    <option value="boleto">
                                        Boleto
                                    </option>

                                    <option value="dinheiro">
                                        Dinheiro
                                    </option>

                                    <option value="debito">
                                        Cartão de débito
                                    </option>

                                    <option value="credito">
                                        Cartão de crédito
                                    </option>

                                </select>

                            </div>


                            <!-- OBSERVAÇÃO -->

                            <div class="mb-0">

                                <label class="form-label">
                                    Observação
                                </label>

                                <textarea
                                    class="form-control"
                                    name="notes"
                                    rows="3"
                                    placeholder="Ex.: pagamento referente à cobrança da competência <?= e($row['competence']) ?>"></textarea>

                            </div>

                        </div>


                        <div class="modal-footer">

                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                data-bs-dismiss="modal">
                                Cancelar
                            </button>

                            <button
                                type="submit"
                                class="btn btn-success">
                                Registrar pagamento
                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    <?php endif; ?>

<?php endforeach; ?>



<!-- ================================================================
     MODAIS DE HISTÓRICO DE PAGAMENTOS
================================================================= -->

<?php foreach ($rows as $row): ?>

    <?php

    $receivableId =
        (int)$row['id'];

    $paymentHistory =
        $paymentsByReceivable[$receivableId]
        ?? [];

    $lastActivePaymentId =
        $lastActivePaymentByReceivable[$receivableId]
        ?? 0;

    ?>

    <div
        class="modal fade"
        id="paymentHistoryModal<?= $receivableId ?>"
        tabindex="-1"
        aria-hidden="true">

        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

            <div class="modal-content">

                <div class="modal-header">

                    <div>

                        <h5 class="modal-title mb-1">
                            Histórico de pagamentos
                        </h5>

                        <div class="small text-muted">
                            <?= e($row['client']) ?>
                            — competência
                            <?= e($row['competence']) ?>
                        </div>

                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Fechar"></button>

                </div>


                <div class="modal-body">

                    <?php if (empty($paymentHistory)): ?>

                        <div class="text-center text-muted py-4">
                            Nenhum pagamento registrado para esta conta.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-sm align-middle">

                                <thead>

                                    <tr>
                                        <th>Data</th>
                                        <th>Forma</th>
                                        <th>Valor</th>
                                        <th>Status</th>
                                        <th>Observação / Estorno</th>
                                        <th>Ação</th>
                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($paymentHistory as $payment): ?>

                                        <?php

                                        $isReversed =
                                            !empty($payment['reversed_at']);

                                        $isLastActive =
                                            !$isReversed
                                            &&
                                            (int)$payment['id']
                                            ===
                                            (int)$lastActivePaymentId;

                                        ?>

                                        <tr>

                                            <td class="text-nowrap">

                                                <?= date(
                                                    'd/m/Y',
                                                    strtotime(
                                                        $payment['payment_date']
                                                    )
                                                ) ?>

                                            </td>


                                            <td>

                                                <?= e(
                                                    paymentMethodLabel(
                                                        $payment['payment_method']
                                                    )
                                                ) ?>

                                            </td>


                                            <td class="text-nowrap fw-semibold">

                                                <?= money(
                                                    $payment['amount']
                                                ) ?>

                                            </td>


                                            <td class="text-nowrap">

                                                <?php if ($isReversed): ?>

                                                    <span class="badge bg-danger">
                                                        Estornado
                                                    </span>

                                                <?php else: ?>

                                                    <span class="badge bg-success">
                                                        Ativo
                                                    </span>

                                                <?php endif; ?>

                                            </td>


                                            <td>

                                                <?php if (
                                                    !empty($payment['notes'])
                                                ): ?>

                                                    <div>
                                                        <?= nl2br(
                                                            e(
                                                                $payment['notes']
                                                            )
                                                        ) ?>
                                                    </div>

                                                <?php endif; ?>


                                                <?php if ($isReversed): ?>

                                                    <div class="small text-danger mt-1">

                                                        <strong>Estorno:</strong>

                                                        <?= date(
                                                            'd/m/Y H:i',
                                                            strtotime(
                                                                $payment['reversed_at']
                                                            )
                                                        ) ?>

                                                        <?php if (
                                                            !empty($payment['reversed_by_name'])
                                                        ): ?>

                                                            por
                                                            <?= e(
                                                                $payment['reversed_by_name']
                                                            ) ?>

                                                        <?php endif; ?>

                                                        <br>

                                                        <strong>Motivo:</strong>
                                                        <?= e(
                                                            $payment['reversal_reason']
                                                                ?? ''
                                                        ) ?>

                                                    </div>

                                                <?php endif; ?>

                                            </td>


                                            <td>

                                                <?php if ($isLastActive): ?>

                                                    <form method="post">

                                                        <input
                                                            type="hidden"
                                                            name="csrf"
                                                            value="<?= e(csrf_token()) ?>">

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="reverse_payment">

                                                        <input
                                                            type="hidden"
                                                            name="payment_id"
                                                            value="<?= (int)$payment['id'] ?>">

                                                        <input
                                                            type="hidden"
                                                            name="return_date_start"
                                                            value="<?= e($filterStart) ?>">

                                                        <input
                                                            type="hidden"
                                                            name="return_date_end"
                                                            value="<?= e($filterEnd) ?>">

                                                        <input
                                                            type="hidden"
                                                            name="return_client_id"
                                                            value="<?= (int)$filterClient ?>">

                                                        <div class="input-group input-group-sm">

                                                            <input
                                                                class="form-control"
                                                                name="reversal_reason"
                                                                maxlength="255"
                                                                placeholder="Motivo do estorno"
                                                                required>

                                                            <button
                                                                class="btn btn-outline-danger"
                                                                type="submit"
                                                                data-confirm="Confirma o estorno deste pagamento?">
                                                                Estornar
                                                            </button>

                                                        </div>

                                                    </form>

                                                <?php elseif (!$isReversed): ?>

                                                    <span
                                                        class="small text-muted"
                                                        title="Somente o último pagamento ativo pode ser estornado.">
                                                        —
                                                    </span>

                                                <?php else: ?>

                                                    <span class="small text-muted">
                                                        —
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                        <div class="small text-muted mt-2">
                            Por segurança, somente o último pagamento ativo pode ser estornado.
                            O registro permanece no histórico.
                        </div>

                    <?php endif; ?>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">
                        Fechar
                    </button>

                </div>

            </div>

        </div>

    </div>

<?php endforeach; ?>



<!-- ================================================================
     MODAIS DE DESCONTO
================================================================= -->

<?php foreach ($rows as $row): ?>

    <?php

    $discountBalance = max(
        0,
        (float)$row['total_amount']
            - (float)$row['paid_amount']
    );

    $discountHistory =
        $discountsByReceivable[(int)$row['id']]
        ?? [];

    $lastActiveDiscountId =
        $lastActiveDiscountByReceivable[(int)$row['id']]
        ?? 0;

    ?>

    <div
        class="modal fade"
        id="discountModal<?= (int)$row['id'] ?>"
        tabindex="-1"
        aria-hidden="true">

        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

            <div class="modal-content">

                <div class="modal-header">

                    <div>
                        <h5 class="modal-title mb-1">
                            Desconto
                        </h5>

                        <div class="small text-muted">
                            <?= e($row['client']) ?>
                            — competência
                            <?= e($row['competence']) ?>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Fechar"></button>

                </div>


                <div class="modal-body">

                    <?php if ($discountBalance > 0): ?>

                        <form method="post" class="mb-4">

                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="add_discount">
                            <input type="hidden" name="receivable_id" value="<?= (int)$row['id'] ?>">
                            <input type="hidden" name="return_date_start" value="<?= e($filterStart) ?>">
                            <input type="hidden" name="return_date_end" value="<?= e($filterEnd) ?>">
                            <input type="hidden" name="return_client_id" value="<?= (int)$filterClient ?>">

                            <div class="card bg-body-tertiary mb-3">
                                <div class="card-body py-2">
                                    <div><strong>Total atual:</strong> <?= money($row['total_amount']) ?></div>
                                    <div><strong>Já pago:</strong> <?= money($row['paid_amount']) ?></div>
                                    <div><strong>Saldo atual:</strong> <?= money($discountBalance) ?></div>
                                </div>
                            </div>

                            <div class="row g-3">

                                <div class="col-md-4">
                                    <label class="form-label">Data do desconto</label>
                                    <input
                                        class="form-control"
                                        type="date"
                                        name="discount_date"
                                        value="<?= date('Y-m-d') ?>"
                                        required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Valor do desconto</label>
                                    <input
                                        class="form-control"
                                        type="number"
                                        name="amount"
                                        min="0.01"
                                        max="<?= e(number_format($discountBalance, 2, '.', '')) ?>"
                                        step="0.01"
                                        required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Motivo / referência</label>
                                    <input
                                        class="form-control"
                                        type="text"
                                        name="reference_text"
                                        maxlength="190"
                                        placeholder="Ex.: desconto comercial"
                                        required>
                                </div>

                            </div>

                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary">
                                    Registrar desconto
                                </button>
                            </div>

                        </form>

                    <?php else: ?>

                        <div class="alert alert-success">
                            Esta conta não possui saldo pendente para receber desconto.
                        </div>

                    <?php endif; ?>

                    <hr>

                    <h6>Histórico de descontos</h6>

                    <?php if (empty($discountHistory)): ?>

                        <div class="text-muted">
                            Nenhum desconto registrado para esta conta.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-sm align-middle">

                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Valor</th>
                                        <th>Motivo</th>
                                        <th>Status</th>
                                        <th>Ação</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($discountHistory as $discount): ?>

                                        <?php
                                        $isReversed = !empty($discount['reversed_at']);

                                        $isLastActive =
                                            !$isReversed
                                            &&
                                            (int)$discount['id']
                                            ===
                                            (int)$lastActiveDiscountId;
                                        ?>

                                        <tr>

                                            <td class="text-nowrap">
                                                <?= date(
                                                    'd/m/Y',
                                                    strtotime($discount['discount_date'])
                                                ) ?>
                                            </td>

                                            <td class="text-nowrap fw-semibold">
                                                <?= money($discount['amount']) ?>
                                            </td>

                                            <td>
                                                <?= e($discount['reference_text']) ?>

                                                <?php if ($isReversed): ?>
                                                    <div class="small text-danger mt-1">
                                                        <strong>Estorno:</strong>
                                                        <?= date(
                                                            'd/m/Y H:i',
                                                            strtotime($discount['reversed_at'])
                                                        ) ?>

                                                        <?php if (!empty($discount['reversed_by_name'])): ?>
                                                            por <?= e($discount['reversed_by_name']) ?>
                                                        <?php endif; ?>

                                                        <br>
                                                        <strong>Motivo:</strong>
                                                        <?= e($discount['reversal_reason'] ?? '') ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php if ($isReversed): ?>
                                                    <span class="badge bg-danger">Estornado</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Ativo</span>
                                                <?php endif; ?>
                                            </td>

                                            <td>

                                                <?php if ($isLastActive): ?>

                                                    <form method="post">

                                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="reverse_discount">
                                                        <input type="hidden" name="discount_id" value="<?= (int)$discount['id'] ?>">
                                                        <input type="hidden" name="return_date_start" value="<?= e($filterStart) ?>">
                                                        <input type="hidden" name="return_date_end" value="<?= e($filterEnd) ?>">
                                                        <input type="hidden" name="return_client_id" value="<?= (int)$filterClient ?>">

                                                        <div class="input-group input-group-sm">

                                                            <input
                                                                class="form-control"
                                                                name="reversal_reason"
                                                                maxlength="255"
                                                                placeholder="Motivo do estorno"
                                                                required>

                                                            <button
                                                                class="btn btn-outline-danger"
                                                                type="submit"
                                                                data-confirm="Confirma o estorno deste desconto?">
                                                                Estornar
                                                            </button>

                                                        </div>

                                                    </form>

                                                <?php else: ?>

                                                    <span class="text-muted">—</span>

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">
                        Fechar
                    </button>

                </div>

            </div>

        </div>

    </div>

<?php endforeach; ?>


<!-- ================================================================
     VALIDAÇÃO DAS DATAS
================================================================= -->

<script>
    document.addEventListener(
        'DOMContentLoaded',
        function() {

            const start =
                document.getElementById(
                    'date_start'
                );

            const end =
                document.getElementById(
                    'date_end'
                );

            const form =
                document.getElementById(
                    'receivablesFilterForm'
                );


            if (
                !start ||
                !end ||
                !form
            ) {

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | ALTEROU DATA INICIAL
            |--------------------------------------------------------------------------
            */

            start.addEventListener(
                'change',
                function() {

                    end.min =
                        start.value;


                    if (
                        end.value &&
                        end.value <
                        start.value
                    ) {

                        end.value =
                            start.value;
                    }

                }
            );


            /*
            |--------------------------------------------------------------------------
            | SUBMIT
            |--------------------------------------------------------------------------
            */

            form.addEventListener(
                'submit',
                function(event) {

                    if (
                        start.value &&
                        end.value &&
                        end.value <
                        start.value
                    ) {

                        event.preventDefault();


                        alert(
                            'A data final não pode ser anterior à data inicial.'
                        );


                        end.focus();
                    }

                }
            );

        }
    );
</script>


<?php render_footer(); ?>