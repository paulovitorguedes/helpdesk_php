<?php

require_once __DIR__ . '/../src/layout.php';

require_auth();

$pdo = db();

/*
|--------------------------------------------------------------------------
| EDITAR - CARREGAR MATERIAL
|--------------------------------------------------------------------------
*/

$editMaterial = null;

if (isset($_GET['edit'])) {

    $editId = (int) $_GET['edit'];

    $stmt = $pdo->prepare("
        SELECT *
        FROM materials
        WHERE id = ?
          AND deleted_at IS NULL
    ");

    $stmt->execute([$editId]);

    $editMaterial = $stmt->fetch();

    if (!$editMaterial) {
        flash('danger', 'Material não encontrado.');
        redirect(base_url('materials.php'));
    }
}


/*
|--------------------------------------------------------------------------
| POST - SALVAR / EDITAR / EXCLUIR
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $action = $_POST['action'] ?? 'save';


    /*
    |--------------------------------------------------------------------------
    | EXCLUSÃO
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {

        $id = (int) ($_POST['id'] ?? 0);

        if (!$id) {
            flash('danger', 'Material inválido.');
            redirect(base_url('materials.php'));
        }


        /*
        |--------------------------------------------------------------------------
        | VERIFICAR SE O MATERIAL JÁ FOI UTILIZADO EM CHAMADO
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT DISTINCT
                t.ticket_number
            FROM ticket_materials tm
            INNER JOIN tickets t
                ON t.id = tm.ticket_id
            WHERE tm.material_id = ?
            ORDER BY t.ticket_number
        ");

        $stmt->execute([$id]);

        $ticketNumbers = $stmt->fetchAll(PDO::FETCH_COLUMN);


        /*
        |--------------------------------------------------------------------------
        | MATERIAL POSSUI VÍNCULO
        |--------------------------------------------------------------------------
        */

        if (!empty($ticketNumbers)) {

            flash(
                'warning',
                'Não é possível excluir este material, pois ele já está cadastrado '
                    . (
                        count($ticketNumbers) > 1
                        ? 'nos chamados '
                        : 'no chamado '
                    )
                    . implode(', ', $ticketNumbers)
                    . '. A edição do material continua disponível.'
            );

            redirect(base_url('materials.php'));
        }


        /*
        |--------------------------------------------------------------------------
        | MATERIAL NÃO POSSUI VÍNCULO - PODE EXCLUIR
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            DELETE FROM materials
            WHERE id = ?
        ");

        $stmt->execute([$id]);

        flash('success', 'Material excluído com sucesso.');

        redirect(base_url('materials.php'));
    }


    /*
    |--------------------------------------------------------------------------
    | SALVAR / EDITAR
    |--------------------------------------------------------------------------
    */

    $id = (int) ($_POST['id'] ?? 0);

    $purchaseDate = $_POST['purchase_date'] ?? '';

    $description = trim($_POST['description'] ?? '');

    $costPrice = (float) str_replace(
        ',',
        '.',
        $_POST['cost_price'] ?? 0
    );

    $supplier = trim($_POST['supplier'] ?? '');

    $paymentMethod = $_POST['payment_method'] ?? 'pix';

    $installments = (int) ($_POST['installments'] ?? 0);

    $installments = $installments ?: null;

    $installmentValue = (float) str_replace(
        ',',
        '.',
        $_POST['installment_value'] ?? 0
    );

    $installmentValue = $installmentValue ?: null;

    $notes = trim($_POST['notes'] ?? '');


    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES
    |--------------------------------------------------------------------------
    */

    if (!$purchaseDate || !$description) {

        flash(
            'danger',
            'Preencha a data da compra e a descrição do material.'
        );

        redirect(base_url('materials.php'));
    }


    /*
    |--------------------------------------------------------------------------
    | ATUALIZAÇÃO
    |--------------------------------------------------------------------------
    */

    if ($id) {

        $stmt = $pdo->prepare("
            UPDATE materials
            SET
                purchase_date = ?,
                description = ?,
                cost_price = ?,
                supplier = ?,
                payment_method = ?,
                installments = ?,
                installment_value = ?,
                notes = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $purchaseDate,
            $description,
            $costPrice,
            $supplier,
            $paymentMethod,
            $installments,
            $installmentValue,
            $notes,
            $id
        ]);

        flash('success', 'Material atualizado com sucesso.');
    } else {

        /*
        |--------------------------------------------------------------------------
        | NOVO MATERIAL
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            INSERT INTO materials (
                purchase_date,
                description,
                cost_price,
                supplier,
                payment_method,
                installments,
                installment_value,
                notes
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $purchaseDate,
            $description,
            $costPrice,
            $supplier,
            $paymentMethod,
            $installments,
            $installmentValue,
            $notes
        ]);

        flash('success', 'Material cadastrado com sucesso.');
    }

    redirect(base_url('materials.php'));
}


/*
|--------------------------------------------------------------------------
| LISTAGEM
|--------------------------------------------------------------------------
*/

$rows = $pdo->query("
    SELECT *
    FROM materials
    WHERE deleted_at IS NULL
    ORDER BY purchase_date DESC, id DESC
")->fetchAll();


render_header('Materiais');

?>

<div class="row g-3">

    <!-- FORMULÁRIO -->

    <div class="col-lg-4">

        <div class="card">

            <div class="card-body">

                <h5>
                    <?= $editMaterial
                        ? 'Editar material'
                        : 'Novo material'
                    ?>
                </h5>

                <form method="post">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e(csrf_token()) ?>">

                    <input
                        type="hidden"
                        name="action"
                        value="save">

                    <input
                        type="hidden"
                        name="id"
                        value="<?= e($editMaterial['id'] ?? '') ?>">


                    <div class="mb-2">

                        <label>Data compra</label>

                        <input
                            class="form-control"
                            type="date"
                            name="purchase_date"
                            value="<?= e(
                                        $editMaterial['purchase_date']
                                            ?? date('Y-m-d')
                                    ) ?>"
                            required>

                    </div>


                    <div class="mb-2">

                        <label>Descrição</label>

                        <input
                            class="form-control"
                            name="description"
                            value="<?= e(
                                        $editMaterial['description']
                                            ?? ''
                                    ) ?>"
                            required>

                    </div>


                    <div class="mb-2">

                        <label>Valor pago</label>

                        <input
                            class="form-control"
                            name="cost_price"
                            value="<?= e(
                                        $editMaterial['cost_price']
                                            ?? '0'
                                    ) ?>">

                    </div>


                    <div class="mb-2">

                        <label>Fornecedor/local</label>

                        <input
                            class="form-control"
                            name="supplier"
                            value="<?= e(
                                        $editMaterial['supplier']
                                            ?? ''
                                    ) ?>">

                    </div>


                    <div class="mb-2">

                        <label>Forma pagamento</label>

                        <?php
                        $paymentMethod =
                            $editMaterial['payment_method']
                            ?? 'pix';
                        ?>

                        <select
                            class="form-select"
                            name="payment_method">

                            <option
                                value="pix"
                                <?= $paymentMethod === 'pix'
                                    ? 'selected'
                                    : ''
                                ?>>
                                PIX
                            </option>

                            <option
                                value="debito"
                                <?= $paymentMethod === 'debito'
                                    ? 'selected'
                                    : ''
                                ?>>
                                Débito
                            </option>

                            <option
                                value="credito"
                                <?= $paymentMethod === 'credito'
                                    ? 'selected'
                                    : ''
                                ?>>
                                Crédito
                            </option>

                            <option
                                value="dinheiro"
                                <?= $paymentMethod === 'dinheiro'
                                    ? 'selected'
                                    : ''
                                ?>>
                                Dinheiro
                            </option>

                        </select>

                    </div>


                    <div class="row">

                        <div class="col">

                            <label>Parcelas</label>

                            <input
                                class="form-control"
                                name="installments"
                                type="number"
                                min="1"
                                value="<?= e(
                                            $editMaterial['installments']
                                                ?? ''
                                        ) ?>">

                        </div>


                        <div class="col">

                            <label>Valor parcela</label>

                            <input
                                class="form-control"
                                name="installment_value"
                                value="<?= e(
                                            $editMaterial['installment_value']
                                                ?? ''
                                        ) ?>">

                        </div>

                    </div>


                    <div class="mb-3">

                        <label>Observações</label>

                        <textarea
                            class="form-control"
                            name="notes"><?= e(
                                                $editMaterial['notes']
                                                    ?? ''
                                            ) ?></textarea>

                    </div>


                    <button class="btn btn-primary">

                        <?= $editMaterial
                            ? 'Atualizar'
                            : 'Salvar'
                        ?>

                    </button>


                    <?php if ($editMaterial): ?>

                        <a
                            href="<?= e(base_url('materials.php')) ?>"
                            class="btn btn-secondary">
                            Cancelar
                        </a>

                    <?php endif; ?>

                </form>

            </div>

        </div>

    </div>


    <!-- LISTAGEM -->

    <div class="col-lg-8">

        <input
            class="form-control mb-2"
            placeholder="Pesquisa instantânea"
            data-search-table="#materialsTable">


        <div class="table-wrap">

            <div class="table-responsive">

                <table
                    id="materialsTable"
                    class="table">

                    <thead>

                        <tr>

                            <th>Data</th>

                            <th>Material</th>

                            <th>Fornecedor</th>

                            <th>Valor pago</th>

                            <th>Pagamento</th>

                            <th>Ações</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($rows as $r): ?>

                            <tr>

                                <td>
                                    <?= date(
                                        'd/m/Y',
                                        strtotime($r['purchase_date'])
                                    ) ?>
                                </td>

                                <td>
                                    <?= e($r['description']) ?>
                                </td>

                                <td>
                                    <?= e($r['supplier']) ?>
                                </td>

                                <td>
                                    <?= money($r['cost_price']) ?>
                                </td>

                                <td>
                                    <?= e($r['payment_method']) ?>
                                </td>


                                <td class="text-nowrap">

                                    <!-- EDITAR -->

                                    <a
                                        href="<?= e(
                                                    base_url(
                                                        'materials.php?edit='
                                                            . $r['id']
                                                    )
                                                ) ?>"
                                        class="btn btn-sm btn-outline-primary">
                                        Editar
                                    </a>


                                    <!-- EXCLUIR -->

                                    <form
                                        method="post"
                                        style="display:inline"
                                        onsubmit="
                                        return confirm(
                                            'Deseja realmente excluir este material?'
                                        );
                                    ">

                                        <input
                                            type="hidden"
                                            name="csrf"
                                            value="<?= e(csrf_token()) ?>">

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete">

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int)$r['id'] ?>">

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger">
                                            Excluir
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<?php render_footer(); ?>