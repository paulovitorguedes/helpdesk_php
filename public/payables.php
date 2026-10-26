<?php

require_once __DIR__ . '/../src/layout.php';

require_auth();
if (!role_allowed(['admin', 'financeiro'])) exit('Acesso negado.');

$pdo = db();


function payable_month_name(int $month): string
{
    return match ($month) {
        1 => 'Janeiro',
        2 => 'Fevereiro',
        3 => 'Março',
        4 => 'Abril',
        5 => 'Maio',
        6 => 'Junho',
        7 => 'Julho',
        8 => 'Agosto',
        9 => 'Setembro',
        10 => 'Outubro',
        11 => 'Novembro',
        12 => 'Dezembro',
        default => '',
    };
}


function payable_month_diff(DateTimeImmutable $from, DateTimeImmutable $to): int
{
    return (
        ((int)$to->format('Y') - (int)$from->format('Y')) * 12
    ) + (
        (int)$to->format('m') - (int)$from->format('m')
    );
}


function payable_add_months_clamped(string $date, int $months): string
{
    $original = new DateTimeImmutable($date);
    $day = (int)$original->format('d');

    $monthBase = $original
        ->modify('first day of this month')
        ->modify(($months >= 0 ? '+' : '') . $months . ' months');

    $lastDay = (int)$monthBase
        ->modify('last day of this month')
        ->format('d');

    return $monthBase
        ->setDate(
            (int)$monthBase->format('Y'),
            (int)$monthBase->format('m'),
            min($day, $lastDay)
        )
        ->format('Y-m-d');
}


/*
|--------------------------------------------------------------------------
| CADASTRAR / ATUALIZAR / EXCLUIR
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $action =
        $_POST['action']
        ?? 'save';

    $payableId =
        (int)(
            $_POST['payable_id']
            ?? 0
        );

    $returnMonth =
        max(
            1,
            min(
                12,
                (int)(
                    $_POST['return_month']
                    ?? date('m')
                )
            )
        );

    $returnYear =
        (int)(
            $_POST['return_year']
            ?? date('Y')
        );

    $returnUrl =
        base_url('payables.php')
        . '?month='
        . $returnMonth
        . '&year='
        . $returnYear;


    /*
    |--------------------------------------------------------------------------
    | EXCLUIR
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {

        if (!$payableId) {

            flash(
                'danger',
                'Conta a pagar inválida para exclusão.'
            );

            redirect($returnUrl);
        }

        $stmt = $pdo->prepare("
            DELETE FROM payables
            WHERE id = ?
        ");

        $stmt->execute([
            $payableId
        ]);

        flash(
            'success',
            'Conta a pagar excluída.'
        );

        redirect($returnUrl);
    }


    /*
    |--------------------------------------------------------------------------
    | DADOS DO FORMULÁRIO
    |--------------------------------------------------------------------------
    */

    $dueDate =
        trim(
            $_POST['due_date']
                ?? ''
        );

    $description =
        trim(
            $_POST['description']
                ?? ''
        );

    $category =
        trim(
            $_POST['category']
                ?? ''
        );

    $supplier =
        trim(
            $_POST['supplier']
                ?? ''
        );

    $totalAmount =
        (float)str_replace(
            ',',
            '.',
            $_POST['total_amount']
                ?? 0
        );

    $paymentMethod =
        $_POST['payment_method']
        ?? 'pix';

    $installments =
        max(
            1,
            (int)(
                $_POST['installments']
                ?? 1
            )
        );

    $currentInstallment =
        max(
            1,
            (int)(
                $_POST['current_installment']
                ?? 1
            )
        );

    $installmentValue =
        (float)str_replace(
            ',',
            '.',
            $_POST['installment_value']
                ?? 0
        );

    $notes =
        trim(
            $_POST['notes']
                ?? ''
        );


    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES
    |--------------------------------------------------------------------------
    */

    if ($description === '') {

        flash(
            'danger',
            'Informe a descrição.'
        );

        redirect($returnUrl);
    }

    if ($totalAmount <= 0) {

        flash(
            'danger',
            'Informe um valor total maior que zero.'
        );

        redirect($returnUrl);
    }

    if ($currentInstallment > $installments) {

        flash(
            'danger',
            'A parcela atual não pode ser maior que a quantidade total de parcelas.'
        );

        redirect($returnUrl);
    }


    /*
    |--------------------------------------------------------------------------
    | VALOR DA PARCELA
    |--------------------------------------------------------------------------
    */

    if ($installments === 1) {

        $installmentValue =
            $totalAmount;
    } elseif ($installmentValue <= 0) {

        $installmentValue =
            round(
                $totalAmount
                    /
                    $installments,
                2
            );
    }


    /*
    |--------------------------------------------------------------------------
    | ATUALIZAR
    |--------------------------------------------------------------------------
    */

    if ($action === 'update') {

        if (!$payableId) {

            flash(
                'danger',
                'Conta a pagar inválida para atualização.'
            );

            redirect($returnUrl);
        }

        $stmt = $pdo->prepare("
            UPDATE payables
            SET
                due_date = ?,
                description = ?,
                category = ?,
                supplier = ?,
                total_amount = ?,
                payment_method = ?,
                installments = ?,
                current_installment = ?,
                installment_value = ?,
                notes = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $dueDate,
            $description,
            $category,
            $supplier,
            $totalAmount,
            $paymentMethod,
            $installments,
            $currentInstallment,
            $installmentValue,
            $notes,
            $payableId
        ]);

        flash(
            'success',
            'Conta a pagar atualizada.'
        );

        redirect($returnUrl);
    }


    /*
    |--------------------------------------------------------------------------
    | CADASTRAR
    |--------------------------------------------------------------------------
    |
    | O status permanece gravado como "pendente" somente para compatibilidade
    | com a coluna existente no banco.
    |
    */

    $stmt = $pdo->prepare("
        INSERT INTO payables (
            due_date,
            description,
            category,
            supplier,
            total_amount,
            payment_method,
            installments,
            current_installment,
            installment_value,
            status,
            notes
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente', ?
        )
    ");

    $stmt->execute([
        $dueDate,
        $description,
        $category,
        $supplier,
        $totalAmount,
        $paymentMethod,
        $installments,
        $currentInstallment,
        $installmentValue,
        $notes
    ]);

    $due =
        new DateTimeImmutable(
            $dueDate
        );

    flash(
        'success',
        'Conta a pagar cadastrada.'
    );

    redirect(
        base_url('payables.php')
            . '?month='
            . $due->format('m')
            . '&year='
            . $due->format('Y')
    );
}


/*
|--------------------------------------------------------------------------
| MÊS / ANO DE REFERÊNCIA
|--------------------------------------------------------------------------
*/

$today = new DateTimeImmutable('today');

$selectedMonth = (int)(
    $_GET['month']
    ?? $today->format('m')
);

$selectedYear = (int)(
    $_GET['year']
    ?? $today->format('Y')
);

if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int)$today->format('m');
}

if ($selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = (int)$today->format('Y');
}

$referenceDate = new DateTimeImmutable(
    sprintf(
        '%04d-%02d-01',
        $selectedYear,
        $selectedMonth
    )
);


/*
|--------------------------------------------------------------------------
| ANOS DO FILTRO
|--------------------------------------------------------------------------
*/

$yearRows = $pdo->query("
    SELECT DISTINCT YEAR(due_date) AS year_value
    FROM payables
    ORDER BY year_value
")->fetchAll();

$availableYears = [];

foreach ($yearRows as $yearRow) {
    $availableYears[] = (int)$yearRow['year_value'];
}

$currentYear = (int)$today->format('Y');

for ($year = $currentYear - 3; $year <= $currentYear + 5; $year++) {
    $availableYears[] = $year;
}

$availableYears[] = $selectedYear;

$availableYears = array_values(
    array_unique($availableYears)
);

sort($availableYears);


/*
|--------------------------------------------------------------------------
| CONTAS DO MÊS
|--------------------------------------------------------------------------
|
| Para contas parceladas, o registro é projetado nos meses seguintes.
|
| Exemplo:
| vencimento 20/09/2026, parcela atual 1, total 3 parcelas:
| 09/2026 = 1x3
| 10/2026 = 2x3
| 11/2026 = 3x3
|
*/

$allRows = $pdo->query("
    SELECT *
    FROM payables
    ORDER BY due_date, id
")->fetchAll();

$rows = [];
$totalToPay = 0.0;

foreach ($allRows as $row) {

    $due = new DateTimeImmutable($row['due_date']);

    $dueMonth = $due->modify(
        'first day of this month'
    );

    $diff = payable_month_diff(
        $dueMonth,
        $referenceDate
    );

    $installments = max(
        1,
        (int)$row['installments']
    );

    $baseInstallment = max(
        1,
        (int)$row['current_installment']
    );


    if ($installments === 1) {

        if ($diff !== 0) {
            continue;
        }

        $displayInstallment = 1;
        $displayDueDate = $row['due_date'];
        $amountForMonth = (float)$row['total_amount'];
    } else {

        $displayInstallment =
            $baseInstallment + $diff;

        if (
            $displayInstallment < 1
            ||
            $displayInstallment > $installments
        ) {
            continue;
        }

        $displayDueDate =
            payable_add_months_clamped(
                $row['due_date'],
                $diff
            );

        $amountForMonth =
            (float)$row['installment_value'];

        if ($amountForMonth <= 0) {

            $amountForMonth = round(
                (float)$row['total_amount']
                    /
                    $installments,
                2
            );
        }
    }


    $row['display_installment'] =
        $displayInstallment;

    $row['display_due_date'] =
        $displayDueDate;

    $row['amount_for_month'] =
        $amountForMonth;

    $rows[] = $row;

    $totalToPay += $amountForMonth;
}


usort(
    $rows,
    static function (array $a, array $b): int {

        $dateCompare = strcmp(
            $a['display_due_date'],
            $b['display_due_date']
        );

        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        return (int)$a['id'] <=> (int)$b['id'];
    }
);



/*
|--------------------------------------------------------------------------
| MODO DE EDIÇÃO
|--------------------------------------------------------------------------
*/

$editId =
    (int)(
        $_GET['edit_id']
        ?? 0
    );

$editing = null;

if ($editId > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM payables
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $editId
    ]);

    $editing =
        $stmt->fetch();

    if (!$editing) {

        flash(
            'danger',
            'Conta a pagar não encontrada para edição.'
        );

        redirect(
            base_url('payables.php')
                . '?month='
                . $selectedMonth
                . '&year='
                . $selectedYear
        );
    }
}


render_header(
    'Contas a Pagar'
);

?>


<div class="row g-3">


    <div class="col-lg-4">

        <div class="card">

            <div class="card-body">

                <h5>
                    <?= $editing ? 'Editar compromisso' : 'Novo compromisso' ?>
                </h5>


                <form
                    method="post"
                    id="payableForm">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e(csrf_token()) ?>">

                    <input
                        type="hidden"
                        name="payable_id"
                        value="<?= $editing ? (int)$editing['id'] : 0 ?>">

                    <input
                        type="hidden"
                        name="return_month"
                        value="<?= (int)$selectedMonth ?>">

                    <input
                        type="hidden"
                        name="return_year"
                        value="<?= (int)$selectedYear ?>">


                    <div class="mb-2">

                        <label class="form-label">
                            Vencimento
                        </label>

                        <input
                            class="form-control"
                            type="date"
                            name="due_date"
                            value="<?= e(
                                        $editing
                                            ? $editing['due_date']
                                            : date('Y-m-d')
                                    ) ?>"
                            required>

                    </div>


                    <div class="mb-2">

                        <label class="form-label">
                            Descrição
                        </label>

                        <input
                            class="form-control"
                            name="description"
                            value="<?= e($editing['description'] ?? '') ?>"
                            required>

                    </div>


                    <div class="mb-2">

                        <label class="form-label">
                            Categoria
                        </label>

                        <input
                            class="form-control"
                            name="category"
                            value="<?= e($editing['category'] ?? '') ?>">

                    </div>


                    <div class="mb-2">

                        <label class="form-label">
                            Fornecedor
                        </label>

                        <input
                            class="form-control"
                            name="supplier"
                            value="<?= e($editing['supplier'] ?? '') ?>">

                    </div>


                    <div class="mb-2">

                        <label class="form-label">
                            Valor total
                        </label>

                        <input
                            class="form-control"
                            type="number"
                            name="total_amount"
                            id="total_amount"
                            min="0.01"
                            step="0.01"
                            value="<?= e(
                                        $editing
                                            ? number_format(
                                                (float)$editing['total_amount'],
                                                2,
                                                '.',
                                                ''
                                            )
                                            : ''
                                    ) ?>"
                            required>

                    </div>


                    <div class="mb-2">

                        <label class="form-label">
                            Forma de pagamento
                        </label>

                        <select
                            class="form-select"
                            name="payment_method">

                            <option
                                value="pix"
                                <?= (
                                    ($editing['payment_method'] ?? 'pix')
                                    ===
                                    'pix'
                                )
                                    ? 'selected'
                                    : ''
                                ?>>
                                PIX
                            </option>

                            <option
                                value="debito"
                                <?= (
                                    ($editing['payment_method'] ?? 'pix')
                                    ===
                                    'debito'
                                )
                                    ? 'selected'
                                    : ''
                                ?>>
                                Débito
                            </option>

                            <option
                                value="credito"
                                <?= (
                                    ($editing['payment_method'] ?? 'pix')
                                    ===
                                    'credito'
                                )
                                    ? 'selected'
                                    : ''
                                ?>>
                                Crédito
                            </option>

                            <option
                                value="dinheiro"
                                <?= (
                                    ($editing['payment_method'] ?? 'pix')
                                    ===
                                    'dinheiro'
                                )
                                    ? 'selected'
                                    : ''
                                ?>>
                                Dinheiro
                            </option>

                            <option
                                value="boleto"
                                <?= (
                                    ($editing['payment_method'] ?? 'pix')
                                    ===
                                    'boleto'
                                )
                                    ? 'selected'
                                    : ''
                                ?>>
                                Boleto
                            </option>

                            <option
                                value="transferencia"
                                <?= (
                                    ($editing['payment_method'] ?? 'pix')
                                    ===
                                    'transferencia'
                                )
                                    ? 'selected'
                                    : ''
                                ?>>
                                Transferência
                            </option>

                        </select>

                    </div>


                    <div class="row">

                        <div class="col">

                            <label class="form-label">
                                Parcelas
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                name="installments"
                                id="installments"
                                value="<?= $editing ? (int)$editing['installments'] : 1 ?>"
                                min="1"
                                required>

                        </div>


                        <div class="col">

                            <label class="form-label">
                                Parcela atual
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                name="current_installment"
                                id="current_installment"
                                value="<?= $editing ? (int)$editing['current_installment'] : 1 ?>"
                                min="1"
                                required>

                        </div>

                    </div>


                    <div class="mb-2 mt-2">

                        <label class="form-label">
                            Valor parcela
                        </label>

                        <input
                            class="form-control"
                            type="number"
                            name="installment_value"
                            id="installment_value"
                            min="0.01"
                            step="0.01"
                            value="<?= e(
                                        $editing
                                            ? number_format(
                                                (float)$editing['installment_value'],
                                                2,
                                                '.',
                                                ''
                                            )
                                            : ''
                                    ) ?>">

                        <div class="form-text">
                            Calculado automaticamente e pode ser ajustado manualmente.
                        </div>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Observações
                        </label>

                        <textarea
                            class="form-control"
                            name="notes"
                            rows="3"><?= e($editing['notes'] ?? '') ?></textarea>

                    </div>


                    <?php if ($editing): ?>

                        <div class="d-flex justify-content-between align-items-center gap-2">

                            <div class="d-flex gap-2">

                                <button
                                    class="btn btn-primary"
                                    type="submit"
                                    name="action"
                                    value="update">
                                    Atualizar
                                </button>

                                <a
                                    class="btn btn-outline-secondary"
                                    href="<?= e(
                                                base_url('payables.php')
                                                    . '?month='
                                                    . $selectedMonth
                                                    . '&year='
                                                    . $selectedYear
                                            ) ?>">
                                    Cancelar atualização
                                </a>

                            </div>


                            <button
                                class="btn btn-danger"
                                type="submit"
                                name="action"
                                value="delete"
                                formnovalidate
                                data-confirm="Confirma a exclusão desta conta a pagar?">
                                Excluir
                            </button>

                        </div>

                    <?php else: ?>

                        <button
                            class="btn btn-primary"
                            type="submit"
                            name="action"
                            value="save">
                            Salvar
                        </button>

                    <?php endif; ?>

                </form>

            </div>

        </div>

    </div>


    <div class="col-lg-8">


        <div class="card mb-3">

            <div class="card-body">

                <form
                    method="get"
                    class="row g-2 align-items-end">

                    <div class="col-md-4">

                        <label class="form-label">
                            Mês
                        </label>

                        <select
                            class="form-select"
                            name="month">

                            <?php for ($month = 1; $month <= 12; $month++): ?>

                                <option
                                    value="<?= $month ?>"
                                    <?= (
                                        $selectedMonth === $month
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>>
                                    <?= e(
                                        payable_month_name(
                                            $month
                                        )
                                    ) ?>
                                </option>

                            <?php endfor; ?>

                        </select>

                    </div>


                    <div class="col-md-3">

                        <label class="form-label">
                            Ano
                        </label>

                        <select
                            class="form-select"
                            name="year">

                            <?php foreach ($availableYears as $year): ?>

                                <option
                                    value="<?= (int)$year ?>"
                                    <?= (
                                        $selectedYear
                                        ===
                                        (int)$year
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>>
                                    <?= (int)$year ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-md-auto">

                        <button
                            class="btn btn-primary"
                            type="submit">
                            Pesquisar
                        </button>

                    </div>


                    <div class="col-md-auto">

                        <a
                            class="btn btn-outline-secondary"
                            href="<?= e(
                                        base_url(
                                            'payables.php'
                                        )
                                    ) ?>">
                            Mês atual
                        </a>

                    </div>

                </form>

            </div>

        </div>


        <div class="mb-2">

            <strong>
                Referência:
            </strong>

            <?= e(
                payable_month_name(
                    $selectedMonth
                )
            ) ?>

            /

            <?= (int)$selectedYear ?>

        </div>


        <input
            class="form-control mb-2"
            placeholder="Pesquisa instantânea"
            data-search-table="#payables">


        <div class="table-wrap">

            <div class="table-responsive">

                <table
                    id="payables"
                    class="table align-middle">

                    <thead>

                        <tr>

                            <th>Vencimento</th>
                            <th>Descrição</th>
                            <th>Categoria</th>
                            <th>Fornecedor</th>
                            <th>Valor total</th>
                            <th>Valor parcela</th>
                            <th>Parcela</th>
                            <th>Ações</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($rows as $r): ?>

                            <tr>

                                <td>
                                    <?= date(
                                        'd/m/Y',
                                        strtotime(
                                            $r['display_due_date']
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $r['description']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $r['category']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $r['supplier']
                                    ) ?>
                                </td>

                                <td>
                                    <?= money(
                                        $r['total_amount']
                                    ) ?>
                                </td>

                                <td>
                                    <?= money(
                                        $r['amount_for_month']
                                    ) ?>
                                </td>

                                <td class="text-nowrap">

                                    <?= (int)$r['display_installment'] ?>

                                    x

                                    <?= (int)$r['installments'] ?>

                                </td>


                                <td class="text-nowrap">

                                    <a
                                        class="btn btn-sm btn-outline-primary"
                                        href="<?= e(
                                                    base_url('payables.php')
                                                        . '?month='
                                                        . $selectedMonth
                                                        . '&year='
                                                        . $selectedYear
                                                        . '&edit_id='
                                                        . (int)$r['id']
                                                ) ?>">
                                        Editar
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>


                        <?php if (empty($rows)): ?>

                            <tr>

                                <td
                                    colspan="8"
                                    class="text-center text-muted py-4">
                                    Nenhuma conta a pagar encontrada para
                                    <?= e(
                                        payable_month_name(
                                            $selectedMonth
                                        )
                                    ) ?>
                                    /
                                    <?= (int)$selectedYear ?>.
                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>


                    <tfoot>

                        <tr class="table-light fw-bold">

                            <td
                                colspan="6"
                                class="text-end">
                                Total a pagar em
                                <?= e(
                                    payable_month_name(
                                        $selectedMonth
                                    )
                                ) ?>
                                /
                                <?= (int)$selectedYear ?>:
                            </td>

                            <td colspan="2">

                                <?= money(
                                    $totalToPay
                                ) ?>

                            </td>

                        </tr>

                    </tfoot>

                </table>

            </div>

        </div>

    </div>

</div>


<script>
    document.addEventListener(
        'DOMContentLoaded',
        function() {

            const total =
                document.getElementById(
                    'total_amount'
                );

            const installments =
                document.getElementById(
                    'installments'
                );

            const currentInstallment =
                document.getElementById(
                    'current_installment'
                );

            const installmentValue =
                document.getElementById(
                    'installment_value'
                );


            function recalculateInstallment() {

                const totalValue =
                    parseFloat(
                        total.value
                    );

                let installmentCount =
                    parseInt(
                        installments.value,
                        10
                    );

                if (
                    !Number.isFinite(
                        installmentCount
                    ) ||
                    installmentCount < 1
                ) {

                    installmentCount = 1;
                    installments.value = 1;
                }


                currentInstallment.max =
                    installmentCount;


                if (
                    parseInt(
                        currentInstallment.value,
                        10
                    ) >
                    installmentCount
                ) {

                    currentInstallment.value =
                        installmentCount;
                }


                if (
                    Number.isFinite(
                        totalValue
                    ) &&
                    totalValue > 0
                ) {

                    installmentValue.value =
                        (
                            totalValue /
                            installmentCount
                        )
                        .toFixed(2);
                }

            }


            total.addEventListener(
                'input',
                recalculateInstallment
            );

            installments.addEventListener(
                'input',
                recalculateInstallment
            );


            currentInstallment.addEventListener(
                'input',
                function() {

                    const count =
                        Math.max(
                            1,
                            parseInt(
                                installments.value,
                                10
                            ) ||
                            1
                        );

                    let current =
                        parseInt(
                            currentInstallment.value,
                            10
                        ) ||
                        1;

                    current =
                        Math.max(
                            1,
                            Math.min(
                                current,
                                count
                            )
                        );

                    currentInstallment.value =
                        current;
                }
            );


            <?php if (!$editing): ?>

                recalculateInstallment();

            <?php endif; ?>

        }
    );
</script>


<?php render_footer(); ?>