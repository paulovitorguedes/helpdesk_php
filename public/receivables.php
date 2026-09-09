<?php

require_once __DIR__ . '/../src/layout.php';

require_auth();

$pdo = db();


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
    |
    | Ciclo:
    | 16/08/2026 até 15/09/2026
    |
    | Competência:
    | 2026-09
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
        | VERIFICAR SE JÁ EXISTE CONTA PARA ESTA COMPETÊNCIA
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
            (int)(
                $stmt->fetchColumn()
                ?: 0
            );


        /*
        |--------------------------------------------------------------------------
        | CHAMADOS DO CICLO
        |--------------------------------------------------------------------------
        |
        | IMPORTANTE:
        |
        | Pegamos:
        |
        | - chamados ainda não faturados
        |
        | OU
        |
        | - chamados já vinculados à própria conta desta competência
        |
        | Isso torna o recálculo seguro ao entrar novamente na página.
        |
        */

        $stmt = $pdo->prepare("
            SELECT

                t.*,

                (
                    SELECT
                        COALESCE(
                            SUM(
                                tm.total_price
                            ),
                            0
                        )

                    FROM ticket_materials tm

                    WHERE tm.ticket_id = t.id

                ) AS materials_total

            FROM tickets t

            WHERE t.client_id = ?

              AND DATE(t.occurred_at)
                  BETWEEN ? AND ?

              AND t.status IN (
                  'solucionado',
                  'fechado'
              )

              AND t.deleted_at IS NULL

              AND (
                    t.billed_at IS NULL

                    OR t.receivable_id = ?
                  )

            ORDER BY
                t.occurred_at,
                t.id
        ");


        $stmt->execute([
            $client['id'],
            $start,
            $end,
            $existingReceivableId
        ]);


        $tickets =
            $stmt->fetchAll();


        /*
        |--------------------------------------------------------------------------
        | MENSALIDADE
        |--------------------------------------------------------------------------
        */

        $monthly =

            $client['has_contract']

            ? (float)$client['monthly_fee']

            : 0;


        /*
        |--------------------------------------------------------------------------
        | SERVIÇOS / MATERIAIS / VISITAS
        |--------------------------------------------------------------------------
        */

        $services = 0;

        $materials = 0;

        $visits = 0;

        $freeVisitUsed = false;


        foreach ($tickets as $ticket) {


            /*
            |--------------------------------------------------------------------------
            | SERVIÇO
            |--------------------------------------------------------------------------
            */

            $services +=
                (float)$ticket['service_amount'];


            /*
            |--------------------------------------------------------------------------
            | MATERIAL
            |--------------------------------------------------------------------------
            */

            $materials +=
                (float)$ticket['materials_total'];


            /*
            |--------------------------------------------------------------------------
            | VISITA PRESENCIAL
            |--------------------------------------------------------------------------
            |
            | Para cliente com contrato:
            |
            | primeira visita presencial = gratuita
            |
            | demais visitas = taxa adicional
            |
            */

            if (
                $client['has_contract']
                &&
                $ticket['service_type']
                === 'presencial'
            ) {

                if (!$freeVisitUsed) {

                    $freeVisitUsed = true;
                } else {

                    $visits +=
                        (float)$client['extra_visit_fee'];
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | SALDO ANTERIOR
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(
                    SUM(
                        total_amount
                        -
                        paid_amount
                    ),
                    0
                )

            FROM receivables

            WHERE client_id = ?

              AND status <> 'pago'

              AND competence <> ?
        ");


        $stmt->execute([
            $client['id'],
            $competence
        ]);


        $previous =
            (float)$stmt->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | ADIANTAMENTOS
        |--------------------------------------------------------------------------
        |
        | Também consideramos adiantamentos já consumidos pela própria
        | conta quando estivermos recalculando o ciclo.
        |
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
            empty($tickets)
            &&
            !$client['has_contract']
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
            min(
                (int)$client['due_day'],
                28
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

                ON DUPLICATE KEY UPDATE

                    cycle_start =
                        VALUES(cycle_start),

                    cycle_end =
                        VALUES(cycle_end),

                    due_date =
                        VALUES(due_date),

                    monthly_fee =
                        VALUES(monthly_fee),

                    services_amount =
                        VALUES(services_amount),

                    materials_amount =
                        VALUES(materials_amount),

                    extra_visits_amount =
                        VALUES(extra_visits_amount),

                    discount_amount =
                        VALUES(discount_amount),

                    advances_amount =
                        VALUES(advances_amount),

                    previous_balance =
                        VALUES(previous_balance),

                    total_amount =
                        VALUES(total_amount)
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
            ");


            $stmt->execute([
                $client['id'],
                $competence
            ]);


            $receivableId =
                (int)$stmt->fetchColumn();


            /*
            |--------------------------------------------------------------------------
            | VINCULAR CHAMADOS
            |--------------------------------------------------------------------------
            */

            if (!empty($tickets)) {

                $ticketIds =
                    array_column(
                        $tickets,
                        'id'
                    );


                $placeholders =
                    implode(
                        ',',
                        array_fill(
                            0,
                            count($ticketIds),
                            '?'
                        )
                    );


                $stmt = $pdo->prepare("
                    UPDATE tickets

                    SET
                        billed_at = NOW(),
                        receivable_id = ?

                    WHERE id IN (
                        {$placeholders}
                    )
                ");


                $stmt->execute([
                    $receivableId,
                    ...$ticketIds
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

            $pdo->rollBack();

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


    if ($action === 'pay') {


        $receivableId =
            (int)(
                $_POST['receivable_id']
                ?? 0
            );


        $amount =
            (float)str_replace(
                ',',
                '.',
                $_POST['amount']
                    ?? 0
            );


        if (
            !$receivableId
            ||
            $amount <= 0
        ) {

            flash(
                'danger',
                'Informe um valor de pagamento válido.'
            );


            redirect(
                base_url(
                    'receivables.php'
                )
            );
        }


        $pdo->beginTransaction();


        try {


            /*
            |--------------------------------------------------------------------------
            | PAGAMENTO
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

                $_POST['payment_date'],

                $amount,

                $_POST['payment_method'],

                trim(
                    $_POST['notes']
                        ?? ''
                )

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
            | STATUS
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
                'Pagamento registrado.'
            );
        } catch (Throwable $e) {

            $pdo->rollBack();


            flash(
                'danger',
                'Não foi possível registrar o pagamento.'
            );
        }


        redirect(
            base_url(
                'receivables.php'
            )
        );
    }
}


/*
|--------------------------------------------------------------------------
| RECALCULAR CICLO AUTOMATICAMENTE
|--------------------------------------------------------------------------
|
| Esta é a rotina que antes dependia do botão Fechar ciclo.
|
*/

try {

    recalculate_cycle(
        $pdo,
        $defaultStart,
        $defaultEnd
    );
} catch (Throwable $e) {

    flash(
        'danger',
        'Não foi possível recalcular automaticamente o ciclo: '
            . $e->getMessage()
    );
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

        c.name AS client

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


<!-- ================================================================
     TABELA
================================================================= -->

<div class="table-wrap">

    <div class="table-responsive">

        <table
            class="
                table
                table-sm
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
                        Anterior
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
                        Status
                    </th>

                    <th>
                        Pagamento
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


                        <td>

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


                        <td>

                            <?= e(
                                $row['status']
                            ) ?>

                        </td>


                        <td>


                            <?php if (
                                $balance > 0
                            ): ?>


                                <form
                                    method="post"
                                    class="
                                        d-flex
                                        gap-1
                                    ">


                                    <input
                                        type="hidden"
                                        name="csrf"
                                        value="<?= e(
                                                    csrf_token()
                                                ) ?>">


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
                                        name="payment_date"
                                        value="<?= date(
                                                    'Y-m-d'
                                                ) ?>">


                                    <input
                                        type="hidden"
                                        name="payment_method"
                                        value="pix">


                                    <input
                                        type="hidden"
                                        name="notes"
                                        value="Pagamento rápido">


                                    <input
                                        class="
                                            form-control
                                            form-control-sm
                                        "
                                        style="
                                            width:110px
                                        "
                                        name="amount"
                                        placeholder="Valor">


                                    <button
                                        class="
                                            btn
                                            btn-sm
                                            btn-success
                                        "
                                        type="submit">

                                        R$

                                    </button>


                                </form>


                            <?php endif; ?>


                        </td>


                    </tr>


                <?php endforeach; ?>


                <?php if (
                    empty($rows)
                ): ?>


                    <tr>

                        <td
                            colspan="14"
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