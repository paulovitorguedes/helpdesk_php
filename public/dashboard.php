<?php

require_once __DIR__ . '/../src/layout.php';

require_auth();

$year =
    (int)(
        $_GET['year']
        ?? date('Y')
    );

$pdo = db();


/*
|--------------------------------------------------------------------------
| KPIs
|--------------------------------------------------------------------------
*/

$k = [];

$k['ativos'] =
    (int)$pdo
        ->query("
        SELECT COUNT(*)
        FROM clients
        WHERE status = 'ativo'
          AND deleted_at IS NULL
    ")
        ->fetchColumn();

$k['inativos'] =
    (int)$pdo
        ->query("
        SELECT COUNT(*)
        FROM clients
        WHERE status = 'inativo'
          AND deleted_at IS NULL
    ")
        ->fetchColumn();

foreach (
    [
        'aberto',
        'pendente',
        'solucionado',
        'fechado'
    ]
    as $status
) {

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tickets
        WHERE status = ?
    ");

    $stmt->execute([
        $status
    ]);

    $k[$status] =
        (int)$stmt->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| RECEITA DO MÊS
|--------------------------------------------------------------------------
|
| IMPORTANTE:
|
| total_amount contém o Saldo anterior transportado pelo Contas a Receber.
| Para o Dashboard, "Receita prevista" deve representar somente aquilo que
| pertence à competência do mês.
|
| Portanto:
|
| receita mensal = total_amount - previous_balance
|
| Isso impede que uma dívida de setembro seja somada novamente em outubro,
| novembro etc.
|--------------------------------------------------------------------------
*/

$currentMonth =
    (int)date('m');

$stmt = $pdo->prepare("
    SELECT
        COALESCE(
            SUM(
                GREATEST(
                    total_amount - previous_balance,
                    0
                )
            ),
            0
        ) AS previsto_mes,

        COALESCE(
            SUM(paid_amount),
            0
        ) AS recebido_mes

    FROM receivables

    WHERE YEAR(due_date) = ?
      AND MONTH(due_date) = ?
");

$stmt->execute([
    $year,
    $currentMonth
]);

[
    $prev,
    $recv
] =
    $stmt->fetch(
        PDO::FETCH_NUM
    );


/*
|--------------------------------------------------------------------------
| DESPESAS
|--------------------------------------------------------------------------
|
| Mesma regra usada em Contas a Pagar:
|
| - sem parcelamento: total no mês do vencimento;
| - parcelado: somente installment_value em cada mês projetado.
|--------------------------------------------------------------------------
*/

$expenses =
    array_fill(
        1,
        12,
        0.0
    );

$payables =
    $pdo
    ->query("
        SELECT
            due_date,
            total_amount,
            installments,
            current_installment,
            installment_value
        FROM payables
    ")
    ->fetchAll();

foreach ($payables as $payable) {

    $due =
        new DateTimeImmutable(
            $payable['due_date']
        );

    $installments =
        max(
            1,
            (int)$payable['installments']
        );

    $currentInstallment =
        max(
            1,
            (int)$payable['current_installment']
        );


    /*
    | À vista / parcela única
    */

    if ($installments <= 1) {

        if (
            (int)$due->format('Y')
            ===
            $year
        ) {

            $expenses[(int)$due->format('m')] +=
                (float)$payable['total_amount'];
        }

        continue;
    }


    /*
    | Parcelado
    */

    $installmentValue =
        (float)$payable['installment_value'];

    if ($installmentValue <= 0) {

        $installmentValue =
            round(
                (float)$payable['total_amount']
                    /
                    $installments,
                2
            );
    }

    $remaining =
        $installments
        -
        $currentInstallment
        +
        1;

    $baseMonth =
        $due
        ->modify(
            'first day of this month'
        );

    for (
        $offset = 0;
        $offset < $remaining;
        $offset++
    ) {

        $projected =
            $baseMonth
            ->modify(
                '+'
                    . $offset
                    . ' months'
            );

        if (
            (int)$projected->format('Y')
            !==
            $year
        ) {
            continue;
        }

        $expenses[(int)$projected->format('m')] +=
            $installmentValue;
    }
}

$desp =
    (float)(
        $expenses[$currentMonth]
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| PAINEL FINANCEIRO ANUAL
|--------------------------------------------------------------------------
|
| A linha BRUTO mostra somente o movimento da própria competência.
|
| Exemplo:
|
| Outubro:
| total_amount      = R$ 1.500,00
| previous_balance  = R$   500,00
|
| Bruto de outubro  = R$ 1.000,00
|
| Os R$ 500,00 continuam existindo no Contas a Receber, mas não são
| contabilizados novamente como faturamento de outubro.
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        MONTH(due_date) AS m,

        COALESCE(
            SUM(
                GREATEST(
                    total_amount - previous_balance,
                    0
                )
            ),
            0
        ) AS bruto,

        COALESCE(
            SUM(paid_amount),
            0
        ) AS recebido

    FROM receivables

    WHERE YEAR(due_date) = ?

    GROUP BY
        MONTH(due_date)
");

$stmt->execute([
    $year
]);

$fin = [];

foreach ($stmt as $row) {

    $fin[(int)$row['m']] = $row;
}


/*
|--------------------------------------------------------------------------
| ATENDIMENTOS POR CLIENTE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        c.name,
        MONTH(t.occurred_at) AS m,
        COUNT(*) AS total
    FROM tickets t
    INNER JOIN clients c
        ON c.id = t.client_id
    WHERE YEAR(t.occurred_at) = ?
    GROUP BY
        c.id,
        c.name,
        MONTH(t.occurred_at)
    ORDER BY c.name
");

$stmt->execute([
    $year
]);

$attMap = [];

foreach ($stmt as $row) {

    $attMap[$row['name']][(int)$row['m']] =
        $row['total'];
}


render_header(
    'Dashboard Gerencial'
);

$months = [
    'Jan',
    'Fev',
    'Mar',
    'Abr',
    'Mai',
    'Jun',
    'Jul',
    'Ago',
    'Set',
    'Out',
    'Nov',
    'Dez'
];

?>


<div class="d-flex justify-content-between align-items-center mb-3">

    <h4 class="mb-0">
        Visão geral
    </h4>

    <form>

        <select
            class="form-select form-select-sm"
            name="year"
            onchange="this.form.submit()">

            <?php
            for (
                $y = date('Y') - 3;
                $y <= date('Y') + 1;
                $y++
            ):
            ?>

                <option
                    <?= $y == $year
                        ? 'selected'
                        : ''
                    ?>>
                    <?= $y ?>
                </option>

            <?php endfor; ?>

        </select>

    </form>

</div>


<div class="row g-3 mb-4">

    <?php
    foreach (
        [
            [
                'Clientes ativos',
                $k['ativos']
            ],
            [
                'Clientes inativos',
                $k['inativos']
            ],
            [
                'Chamados abertos',
                $k['aberto']
            ],
            [
                'Pendentes',
                $k['pendente']
            ],
            [
                'Receita prevista',
                money($prev)
            ],
            [
                'Receita recebida',
                money($recv)
            ],
            [
                'Despesas do mês',
                money($desp)
            ],
            [
                'Lucro do mês',
                money(
                    (float)$recv
                        -
                        $desp
                )
            ]
        ]
        as [
            $label,
            $value
        ]
    ):
    ?>

        <div class="col-6 col-lg-3">

            <div class="card card-kpi">

                <div class="card-body">

                    <div class="text-body-secondary small">
                        <?= e($label) ?>
                    </div>

                    <div class="fs-4 fw-semibold">
                        <?= e(
                            (string)$value
                        ) ?>
                    </div>

                </div>

            </div>

        </div>

    <?php endforeach; ?>

</div>


<div class="table-wrap mb-4">

    <h5>
        Painel Financeiro Anual
    </h5>

    <div class="table-responsive">

        <table class="table table-sm align-middle">

            <thead>

                <tr>

                    <th></th>

                    <?php foreach ($months as $month): ?>

                        <th>
                            <?= $month ?>
                        </th>

                    <?php endforeach; ?>

                    <th>
                        Total
                    </th>

                </tr>

            </thead>


            <tbody>

                <?php

                foreach (
                    [
                        'Bruto' =>
                        'bruto',
                        'Recebido' =>
                        'recebido',
                        'Despesas' =>
                        'desp',
                        'Líquido' =>
                        'liquido'
                    ]
                    as $label =>
                    $type
                ):

                    $total = 0;

                ?>

                    <tr>

                        <th>
                            <?= e($label) ?>
                        </th>


                        <?php
                        for (
                            $month = 1;
                            $month <= 12;
                            $month++
                        ):

                            $gross =
                                (float)(
                                    $fin[$month]['bruto']
                                    ?? 0
                                );

                            $received =
                                (float)(
                                    $fin[$month]['recebido']
                                    ?? 0
                                );

                            $expense =
                                (float)(
                                    $expenses[$month]
                                    ?? 0
                                );

                            $value =
                                $type === 'bruto'
                                ? $gross
                                : (
                                    $type === 'recebido'
                                    ? $received
                                    : (
                                        $type === 'desp'
                                        ? $expense
                                        : $received - $expense
                                    )
                                );

                            $total +=
                                $value;

                        ?>

                            <td class="money">
                                <?= money($value) ?>
                            </td>

                        <?php endfor; ?>


                        <th class="money">
                            <?= money($total) ?>
                        </th>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>


<div class="card mb-4">

    <div class="card-body">

        <canvas
            id="financeChart"
            height="90">
        </canvas>

    </div>

</div>


<div class="table-wrap">

    <h5>
        Atendimentos por Cliente
    </h5>

    <div class="table-responsive">

        <table class="table table-sm">

            <thead>

                <tr>

                    <th>
                        Cliente
                    </th>

                    <?php foreach ($months as $month): ?>

                        <th>
                            <?= $month ?>
                        </th>

                    <?php endforeach; ?>

                    <th>
                        Total
                    </th>

                </tr>

            </thead>


            <tbody>

                <?php
                foreach (
                    $attMap
                    as $client =>
                    $monthValues
                ):

                    $total = 0;
                ?>

                    <tr>

                        <td>
                            <?= e($client) ?>
                        </td>

                        <?php
                        for (
                            $month = 1;
                            $month <= 12;
                            $month++
                        ):

                            $value =
                                (int)(
                                    $monthValues[$month]
                                    ?? 0
                                );

                            $total +=
                                $value;
                        ?>

                            <td>
                                <?= $value ?>
                            </td>

                        <?php endfor; ?>

                        <th>
                            <?= $total ?>
                        </th>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>


<script>
    document.addEventListener(
        'DOMContentLoaded',
        () => {

            new Chart(
                document.getElementById(
                    'financeChart'
                ), {

                    type: 'bar',

                    data: {

                        labels: <?= json_encode(
                                    $months
                                ) ?>,

                        datasets: [

                            {
                                label: 'Bruto',
                                data: <?= json_encode(
                                            array_map(
                                                fn($month) =>
                                                (float)(
                                                    $fin[$month]['bruto']
                                                    ?? 0
                                                ),
                                                range(
                                                    1,
                                                    12
                                                )
                                            )
                                        ) ?>
                            },

                            {
                                label: 'Recebido',
                                data: <?= json_encode(
                                            array_map(
                                                fn($month) =>
                                                (float)(
                                                    $fin[$month]['recebido']
                                                    ?? 0
                                                ),
                                                range(
                                                    1,
                                                    12
                                                )
                                            )
                                        ) ?>
                            },

                            {
                                label: 'Despesas',
                                data: <?= json_encode(
                                            array_map(
                                                fn($month) =>
                                                (float)(
                                                    $expenses[$month]
                                                    ?? 0
                                                ),
                                                range(
                                                    1,
                                                    12
                                                )
                                            )
                                        ) ?>
                            }

                        ]

                    },

                    options: {

                        responsive: true,

                        interaction: {
                            mode: 'index',
                            intersect: false
                        }

                    }

                }
            );

        }
    );
</script>


<?php render_footer(); ?>