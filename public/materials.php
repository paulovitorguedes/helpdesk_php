<?php

require_once __DIR__ . '/../src/layout.php';

require_auth();

$pdo = db();


/*
|--------------------------------------------------------------------------
| FUNÇÃO AUXILIAR - VALORES DECIMAIS
|--------------------------------------------------------------------------
*/

function decimalValue($value): float
{
    $value = trim((string)$value);
    $value = str_replace(',', '.', $value);

    return (float)$value;
}


/*
|--------------------------------------------------------------------------
| MODO EDITAR MATERIAL
|--------------------------------------------------------------------------
*/

$editMaterial = null;

if (isset($_GET['edit'])) {

    $editId = (int)$_GET['edit'];

    $stmt = $pdo->prepare("
        SELECT
            id,
            description,
            minimum_stock,
            notes
        FROM materials
        WHERE id = ?
          AND deleted_at IS NULL
    ");

    $stmt->execute([$editId]);

    $editMaterial = $stmt->fetch();

    if (!$editMaterial) {

        flash(
            'danger',
            'Material não encontrado.'
        );

        redirect(base_url('materials.php'));
    }
}


/*
|--------------------------------------------------------------------------
| MODO NOVA COMPRA
|--------------------------------------------------------------------------
*/

$purchaseMaterial = null;

if (isset($_GET['purchase'])) {

    $purchaseId = (int)$_GET['purchase'];

    $stmt = $pdo->prepare("
        SELECT
            id,
            description,
            minimum_stock,
            notes
        FROM materials
        WHERE id = ?
          AND deleted_at IS NULL
    ");

    $stmt->execute([$purchaseId]);

    $purchaseMaterial = $stmt->fetch();

    if (!$purchaseMaterial) {

        flash(
            'danger',
            'Material não encontrado.'
        );

        redirect(base_url('materials.php'));
    }
}


/*
|--------------------------------------------------------------------------
| MODO HISTÓRICO
|--------------------------------------------------------------------------
*/

$historyMaterial = null;
$purchaseHistory = [];
$movementHistory = [];

if (isset($_GET['history'])) {

    $historyId = (int)$_GET['history'];

    $stmt = $pdo->prepare("
        SELECT
            id,
            description,
            minimum_stock,
            notes
        FROM materials
        WHERE id = ?
          AND deleted_at IS NULL
    ");

    $stmt->execute([$historyId]);

    $historyMaterial = $stmt->fetch();

    if (!$historyMaterial) {

        flash(
            'danger',
            'Material não encontrado.'
        );

        redirect(base_url('materials.php'));
    }


    /*
    |--------------------------------------------------------------------------
    | HISTÓRICO DE COMPRAS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            purchase_date,
            supplier,
            quantity,
            total_cost,
            unit_cost,
            notes,
            created_at
        FROM material_purchases
        WHERE material_id = ?
        ORDER BY purchase_date DESC, id DESC
    ");

    $stmt->execute([$historyId]);

    $purchaseHistory = $stmt->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | HISTÓRICO DE MOVIMENTAÇÕES
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            sm.id,
            sm.movement_type,
            sm.quantity,
            sm.reference,
            sm.notes,
            sm.created_at,
            t.ticket_number,
            u.name AS user_name
        FROM stock_movements sm

        LEFT JOIN tickets t
            ON t.id = sm.ticket_id

        LEFT JOIN users u
            ON u.id = sm.created_by

        WHERE sm.material_id = ?

        ORDER BY sm.created_at DESC, sm.id DESC
    ");

    $stmt->execute([$historyId]);

    $movementHistory = $stmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $action = $_POST['action'] ?? 'save';


    /*
    |--------------------------------------------------------------------------
    | EXCLUIR MATERIAL
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {

        $id = (int)($_POST['id'] ?? 0);

        if (!$id) {

            flash(
                'danger',
                'Material inválido.'
            );

            redirect(base_url('materials.php'));
        }


        /*
        |--------------------------------------------------------------------------
        | VERIFICAR USO EM CHAMADOS
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
        | EXCLUSÃO
        |--------------------------------------------------------------------------
        */

        try {

            $pdo->beginTransaction();


            $stmt = $pdo->prepare("
                DELETE FROM stock_movements
                WHERE material_id = ?
            ");

            $stmt->execute([$id]);


            $stmt = $pdo->prepare("
                DELETE FROM material_purchases
                WHERE material_id = ?
            ");

            $stmt->execute([$id]);


            $stmt = $pdo->prepare("
                DELETE FROM materials
                WHERE id = ?
            ");

            $stmt->execute([$id]);


            $pdo->commit();


            flash(
                'success',
                'Material excluído com sucesso.'
            );
        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash(
                'danger',
                'Não foi possível excluir o material: '
                    . $e->getMessage()
            );
        }


        redirect(base_url('materials.php'));
    }


    /*
    |--------------------------------------------------------------------------
    | NOVA COMPRA DE MATERIAL EXISTENTE
    |--------------------------------------------------------------------------
    */

    if ($action === 'purchase') {

        $materialId = (int)($_POST['material_id'] ?? 0);

        $purchaseDate =
            $_POST['purchase_date'] ?? '';

        $supplier =
            trim($_POST['supplier'] ?? '');

        $quantity =
            decimalValue($_POST['quantity'] ?? 0);

        $totalCost =
            decimalValue($_POST['total_cost'] ?? 0);

        $notes =
            trim($_POST['purchase_notes'] ?? '');


        /*
        |--------------------------------------------------------------------------
        | VALIDAR MATERIAL
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT id
            FROM materials
            WHERE id = ?
              AND deleted_at IS NULL
        ");

        $stmt->execute([$materialId]);

        if (!$stmt->fetchColumn()) {

            flash(
                'danger',
                'Material não encontrado.'
            );

            redirect(base_url('materials.php'));
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDAÇÕES DA COMPRA
        |--------------------------------------------------------------------------
        */

        if (!$purchaseDate) {

            flash(
                'danger',
                'Informe a data da compra.'
            );

            redirect(
                base_url(
                    'materials.php?purchase='
                        . $materialId
                )
            );
        }


        if ($quantity <= 0) {

            flash(
                'danger',
                'A quantidade comprada deve ser maior que zero.'
            );

            redirect(
                base_url(
                    'materials.php?purchase='
                        . $materialId
                )
            );
        }


        if ($totalCost < 0) {

            flash(
                'danger',
                'O valor total pago não pode ser negativo.'
            );

            redirect(
                base_url(
                    'materials.php?purchase='
                        . $materialId
                )
            );
        }


        /*
        |--------------------------------------------------------------------------
        | VALOR UNITÁRIO
        |--------------------------------------------------------------------------
        */

        $unitCost = $totalCost / $quantity;


        /*
        |--------------------------------------------------------------------------
        | TRANSAÇÃO
        |--------------------------------------------------------------------------
        */

        try {

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | REGISTRAR COMPRA
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO material_purchases (
                    material_id,
                    purchase_date,
                    supplier,
                    quantity,
                    total_cost,
                    unit_cost,
                    notes
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $materialId,
                $purchaseDate,
                $supplier,
                $quantity,
                $totalCost,
                $unitCost,
                $notes
            ]);

            $purchaseId =
                (int)$pdo->lastInsertId();


            /*
            |--------------------------------------------------------------------------
            | REGISTRAR ENTRADA NO ESTOQUE
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO stock_movements (
                    material_id,
                    movement_type,
                    quantity,
                    purchase_id,
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
                $quantity,
                $purchaseId,
                'Nova compra de material',
                $notes,
                auth_user()['id']
            ]);


            $pdo->commit();


            flash(
                'success',
                'Nova compra registrada e estoque atualizado com sucesso.'
            );
        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash(
                'danger',
                'Erro ao registrar nova compra: '
                    . $e->getMessage()
            );
        }


        redirect(base_url('materials.php'));
    }


    /*
    |--------------------------------------------------------------------------
    | SALVAR / EDITAR MATERIAL
    |--------------------------------------------------------------------------
    */

    $id =
        (int)($_POST['id'] ?? 0);

    $description =
        trim($_POST['description'] ?? '');

    $minimumStock =
        decimalValue(
            $_POST['minimum_stock'] ?? 0
        );

    $notes =
        trim($_POST['notes'] ?? '');


    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES
    |--------------------------------------------------------------------------
    */

    if ($description === '') {

        flash(
            'danger',
            'Informe a descrição do material.'
        );

        redirect(base_url('materials.php'));
    }


    if ($minimumStock < 0) {

        flash(
            'danger',
            'O estoque mínimo não pode ser negativo.'
        );

        redirect(base_url('materials.php'));
    }


    /*
    |--------------------------------------------------------------------------
    | EDITAR MATERIAL
    |--------------------------------------------------------------------------
    */

    if ($id) {

        $stmt = $pdo->prepare("
            UPDATE materials
            SET
                description = ?,
                minimum_stock = ?,
                notes = ?
            WHERE id = ?
              AND deleted_at IS NULL
        ");

        $stmt->execute([
            $description,
            $minimumStock,
            $notes,
            $id
        ]);


        flash(
            'success',
            'Material atualizado com sucesso.'
        );


        redirect(base_url('materials.php'));
    }


    /*
    |--------------------------------------------------------------------------
    | NOVO MATERIAL - DADOS DA COMPRA INICIAL
    |--------------------------------------------------------------------------
    */

    $purchaseDate =
        $_POST['purchase_date'] ?? '';

    $supplier =
        trim($_POST['supplier'] ?? '');

    $quantity =
        decimalValue($_POST['quantity'] ?? 0);

    $totalCost =
        decimalValue($_POST['total_cost'] ?? 0);


    if (!$purchaseDate) {

        flash(
            'danger',
            'Informe a data da compra.'
        );

        redirect(base_url('materials.php'));
    }


    if ($quantity <= 0) {

        flash(
            'danger',
            'A quantidade comprada deve ser maior que zero.'
        );

        redirect(base_url('materials.php'));
    }


    if ($totalCost < 0) {

        flash(
            'danger',
            'O valor total pago não pode ser negativo.'
        );

        redirect(base_url('materials.php'));
    }


    /*
    |--------------------------------------------------------------------------
    | CUSTO UNITÁRIO
    |--------------------------------------------------------------------------
    */

    $unitCost = $totalCost / $quantity;


    /*
    |--------------------------------------------------------------------------
    | CADASTRO COMPLETO
    |--------------------------------------------------------------------------
    */

    try {

        $pdo->beginTransaction();


        /*
        |--------------------------------------------------------------------------
        | MATERIAL
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            INSERT INTO materials (
                description,
                minimum_stock,
                notes
            )
            VALUES (?, ?, ?)
        ");

        $stmt->execute([
            $description,
            $minimumStock,
            $notes
        ]);

        $materialId =
            (int)$pdo->lastInsertId();


        /*
        |--------------------------------------------------------------------------
        | COMPRA
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            INSERT INTO material_purchases (
                material_id,
                purchase_date,
                supplier,
                quantity,
                total_cost,
                unit_cost,
                notes
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $materialId,
            $purchaseDate,
            $supplier,
            $quantity,
            $totalCost,
            $unitCost,
            $notes
        ]);

        $purchaseId =
            (int)$pdo->lastInsertId();


        /*
        |--------------------------------------------------------------------------
        | ESTOQUE
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            INSERT INTO stock_movements (
                material_id,
                movement_type,
                quantity,
                purchase_id,
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
            $quantity,
            $purchaseId,
            'Compra inicial',
            $notes,
            auth_user()['id']
        ]);


        $pdo->commit();


        flash(
            'success',
            'Material cadastrado e estoque atualizado com sucesso.'
        );
    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }


        flash(
            'danger',
            'Erro ao cadastrar material: '
                . $e->getMessage()
        );
    }


    redirect(base_url('materials.php'));
}


/*
|--------------------------------------------------------------------------
| LISTAGEM
|--------------------------------------------------------------------------
*/

$rows = $pdo->query("
    SELECT

        m.id,
        m.description,
        m.minimum_stock,
        m.notes,

        mp.purchase_date,
        mp.total_cost,
        mp.unit_cost,

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


    LEFT JOIN material_purchases mp

        ON mp.id = (

            SELECT mp2.id

            FROM material_purchases mp2

            WHERE mp2.material_id = m.id

            ORDER BY
                mp2.purchase_date DESC,
                mp2.id DESC

            LIMIT 1
        )


    LEFT JOIN stock_movements sm
        ON sm.material_id = m.id


    WHERE m.deleted_at IS NULL


    GROUP BY

        m.id,
        m.description,
        m.minimum_stock,
        m.notes,

        mp.purchase_date,
        mp.total_cost,
        mp.unit_cost


    ORDER BY
        mp.purchase_date DESC,
        m.description ASC
")->fetchAll();


render_header('Materiais');

?>


<div class="row g-3">


    <!-- ================================================================
         FORMULÁRIO
    ================================================================= -->

    <div class="col-lg-4">

        <div class="card">

            <div class="card-body">

                <?php if ($historyMaterial): ?>

                    <h5 class="mb-3">
                        Histórico do material
                    </h5>

                    <div class="alert alert-info">
                        Material:
                        <strong>
                            <?= e($historyMaterial['description']) ?>
                        </strong>
                    </div>


                    <!-- ================================================================
         COMPRAS
    ================================================================= -->

                    <h6>
                        Compras
                    </h6>

                    <div class="table-responsive mb-4">

                        <table class="table table-sm">

                            <thead>

                                <tr>
                                    <th>Data</th>
                                    <th>Qtd</th>
                                    <th>Total</th>
                                    <th>Unitário</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php if (empty($purchaseHistory)): ?>

                                    <tr>

                                        <td colspan="4">
                                            Nenhuma compra registrada.
                                        </td>

                                    </tr>

                                <?php else: ?>

                                    <?php foreach ($purchaseHistory as $purchase): ?>

                                        <tr>

                                            <td>
                                                <?= date(
                                                    'd/m/Y',
                                                    strtotime($purchase['purchase_date'])
                                                ) ?>
                                            </td>

                                            <td>
                                                <?= number_format(
                                                    (float)$purchase['quantity'],
                                                    2,
                                                    ',',
                                                    '.'
                                                ) ?>
                                            </td>

                                            <td>
                                                <?= money($purchase['total_cost']) ?>
                                            </td>

                                            <td>
                                                <?= money($purchase['unit_cost']) ?>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>


                    <!-- ================================================================
         MOVIMENTAÇÕES
    ================================================================= -->

                    <h6>
                        Movimentações
                    </h6>

                    <div class="table-responsive">

                        <table class="table table-sm">

                            <thead>

                                <tr>
                                    <th>Data</th>
                                    <th>Tipo</th>
                                    <th>Qtd</th>
                                    <th>Referência</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php if (empty($movementHistory)): ?>

                                    <tr>

                                        <td colspan="4">
                                            Nenhuma movimentação registrada.
                                        </td>

                                    </tr>

                                <?php else: ?>

                                    <?php foreach ($movementHistory as $movement): ?>

                                        <?php

                                        $quantity = (float)$movement['quantity'];

                                        if ($movement['movement_type'] === 'entrada') {

                                            $quantityText =
                                                '+'
                                                . number_format(
                                                    $quantity,
                                                    2,
                                                    ',',
                                                    '.'
                                                );
                                        } elseif ($movement['movement_type'] === 'saida') {

                                            $quantityText =
                                                '-'
                                                . number_format(
                                                    $quantity,
                                                    2,
                                                    ',',
                                                    '.'
                                                );
                                        } else {

                                            $quantityText =
                                                number_format(
                                                    $quantity,
                                                    2,
                                                    ',',
                                                    '.'
                                                );
                                        }

                                        ?>

                                        <tr>

                                            <td>
                                                <?= date(
                                                    'd/m/Y H:i',
                                                    strtotime($movement['created_at'])
                                                ) ?>
                                            </td>

                                            <td>
                                                <?= e($movement['movement_type']) ?>
                                            </td>

                                            <td>
                                                <?= e($quantityText) ?>
                                            </td>

                                            <td>

                                                <?= e(
                                                    $movement['reference']
                                                        ?? ''
                                                ) ?>

                                                <?php if ($movement['ticket_number']): ?>

                                                    <div>
                                                        Chamado:
                                                        <strong>
                                                            <?= e(
                                                                $movement['ticket_number']
                                                            ) ?>
                                                        </strong>
                                                    </div>

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>


                    <a
                        href="<?= e(base_url('materials.php')) ?>"
                        class="btn btn-secondary mt-3">
                        Voltar
                    </a>


                <?php elseif ($purchaseMaterial): ?>



                    <!-- ====================================================
                         NOVA COMPRA
                    ===================================================== -->

                    <h5 class="mb-3">
                        Nova compra
                    </h5>


                    <div class="alert alert-info">

                        Material:

                        <strong>
                            <?= e(
                                $purchaseMaterial['description']
                            ) ?>
                        </strong>

                    </div>


                    <form method="post">


                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= e(
                                        csrf_token()
                                    ) ?>">


                        <input
                            type="hidden"
                            name="action"
                            value="purchase">


                        <input
                            type="hidden"
                            name="material_id"
                            value="<?= (int)$purchaseMaterial['id'] ?>">


                        <!-- DATA -->

                        <div class="mb-3">

                            <label class="form-label">
                                Data da compra
                            </label>

                            <input
                                class="form-control"
                                type="date"
                                name="purchase_date"
                                value="<?= date('Y-m-d') ?>"
                                required>

                        </div>


                        <!-- FORNECEDOR -->

                        <div class="mb-3">

                            <label class="form-label">
                                Fornecedor / local
                            </label>

                            <input
                                class="form-control"
                                name="supplier">

                        </div>


                        <!-- QUANTIDADE -->

                        <div class="mb-3">

                            <label class="form-label">
                                Quantidade comprada
                            </label>

                            <input
                                class="form-control"
                                id="quantity"
                                name="quantity"
                                type="number"
                                step="0.01"
                                min="0.01"
                                required>

                        </div>


                        <!-- VALOR TOTAL -->

                        <div class="mb-3">

                            <label class="form-label">
                                Valor total pago
                            </label>

                            <input
                                class="form-control"
                                id="total_cost"
                                name="total_cost"
                                type="number"
                                step="0.01"
                                min="0"
                                required>

                        </div>


                        <!-- CUSTO UNITÁRIO -->

                        <div class="mb-3">

                            <label class="form-label">
                                Valor unitário calculado
                            </label>

                            <input
                                class="form-control"
                                id="unit_cost_preview"
                                value="R$ 0,00"
                                readonly>

                        </div>


                        <!-- OBSERVAÇÃO DA COMPRA -->

                        <div class="mb-3">

                            <label class="form-label">
                                Observações da compra
                            </label>

                            <textarea
                                class="form-control"
                                name="purchase_notes"
                                rows="3"></textarea>

                        </div>


                        <button
                            type="submit"
                            class="btn btn-success">
                            Registrar compra
                        </button>


                        <a
                            href="<?= e(
                                        base_url('materials.php')
                                    ) ?>"
                            class="btn btn-secondary">
                            Cancelar
                        </a>


                    </form>


                <?php else: ?>


                    <!-- ====================================================
                         NOVO / EDITAR MATERIAL
                    ===================================================== -->

                    <h5 class="mb-3">

                        <?= $editMaterial
                            ? 'Editar material'
                            : 'Novo material'
                        ?>

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
                            name="action"
                            value="save">


                        <input
                            type="hidden"
                            name="id"
                            value="<?= e(
                                        $editMaterial['id']
                                            ?? ''
                                    ) ?>">


                        <!-- DESCRIÇÃO -->

                        <div class="mb-3">

                            <label class="form-label">
                                Descrição
                            </label>

                            <input
                                class="form-control"
                                name="description"
                                value="<?= e(
                                            $editMaterial['description']
                                                ?? ''
                                        ) ?>"
                                required>

                        </div>


                        <!-- ESTOQUE MÍNIMO -->

                        <div class="mb-3">

                            <label class="form-label">
                                Estoque mínimo
                            </label>

                            <input
                                class="form-control"
                                name="minimum_stock"
                                type="number"
                                step="0.01"
                                min="0"
                                value="<?= e(
                                            $editMaterial['minimum_stock']
                                                ?? '0'
                                        ) ?>">

                        </div>


                        <!-- COMPRA INICIAL -->

                        <?php if (!$editMaterial): ?>


                            <hr>


                            <h6 class="mb-3">
                                Compra inicial
                            </h6>


                            <div class="mb-3">

                                <label class="form-label">
                                    Data da compra
                                </label>

                                <input
                                    class="form-control"
                                    type="date"
                                    name="purchase_date"
                                    value="<?= date('Y-m-d') ?>"
                                    required>

                            </div>


                            <div class="mb-3">

                                <label class="form-label">
                                    Fornecedor / local
                                </label>

                                <input
                                    class="form-control"
                                    name="supplier">

                            </div>


                            <div class="mb-3">

                                <label class="form-label">
                                    Quantidade comprada
                                </label>

                                <input
                                    class="form-control"
                                    id="quantity"
                                    name="quantity"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    required>

                            </div>


                            <div class="mb-3">

                                <label class="form-label">
                                    Valor total pago
                                </label>

                                <input
                                    class="form-control"
                                    id="total_cost"
                                    name="total_cost"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    required>

                            </div>


                            <div class="mb-3">

                                <label class="form-label">
                                    Valor unitário calculado
                                </label>

                                <input
                                    class="form-control"
                                    id="unit_cost_preview"
                                    value="R$ 0,00"
                                    readonly>

                            </div>


                        <?php endif; ?>


                        <!-- OBSERVAÇÕES -->

                        <div class="mb-3">

                            <label class="form-label">
                                Observações
                            </label>

                            <textarea
                                class="form-control"
                                name="notes"
                                rows="3"><?= e(
                                                $editMaterial['notes']
                                                    ?? ''
                                            ) ?></textarea>

                        </div>


                        <button
                            class="btn btn-primary"
                            type="submit">

                            <?= $editMaterial
                                ? 'Atualizar material'
                                : 'Salvar material'
                            ?>

                        </button>


                        <?php if ($editMaterial): ?>

                            <a
                                href="<?= e(
                                            base_url('materials.php')
                                        ) ?>"
                                class="btn btn-secondary">
                                Cancelar
                            </a>

                        <?php endif; ?>


                    </form>


                <?php endif; ?>


            </div>

        </div>

    </div>


    <!-- ================================================================
         LISTAGEM
    ================================================================= -->

    <div class="col-lg-8">


        <input
            class="form-control mb-2"
            placeholder="Pesquisa instantânea"
            data-search-table="#materialsTable">


        <div class="table-wrap">

            <div class="table-responsive">

                <table
                    id="materialsTable"
                    class="table table-hover align-middle">


                    <thead>

                        <tr>

                            <th>Data</th>

                            <th>Material</th>

                            <th>Valor total</th>

                            <th>Valor unitário</th>

                            <th>Estoque</th>

                            <th>Ações</th>

                        </tr>

                    </thead>


                    <tbody>


                        <?php foreach ($rows as $r): ?>


                            <?php

                            $stock =
                                (float)$r['stock_quantity'];

                            $minimum =
                                (float)$r['minimum_stock'];

                            ?>


                            <tr>


                                <td>

                                    <?php if ($r['purchase_date']): ?>

                                        <?= date(
                                            'd/m/Y',
                                            strtotime(
                                                $r['purchase_date']
                                            )
                                        ) ?>

                                    <?php else: ?>

                                        -

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= e(
                                        $r['description']
                                    ) ?>


                                    <?php if ($stock <= 0): ?>

                                        <div>

                                            <span
                                                class="badge bg-danger">
                                                Sem estoque
                                            </span>

                                        </div>


                                    <?php elseif (
                                        $minimum > 0
                                        && $stock <= $minimum
                                    ): ?>


                                        <div>

                                            <span
                                                class="badge bg-warning text-dark">
                                                Estoque baixo
                                            </span>

                                        </div>


                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= $r['total_cost'] !== null
                                        ? money(
                                            $r['total_cost']
                                        )
                                        : '-'
                                    ?>

                                </td>


                                <td>

                                    <?= $r['unit_cost'] !== null
                                        ? money(
                                            $r['unit_cost']
                                        )
                                        : '-'
                                    ?>

                                </td>


                                <td>

                                    <?= number_format(
                                        $stock,
                                        2,
                                        ',',
                                        '.'
                                    ) ?>

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


                                    <!-- NOVA COMPRA -->

                                    <a
                                        href="<?= e(
                                                    base_url(
                                                        'materials.php?purchase='
                                                            . $r['id']
                                                    )
                                                ) ?>"
                                        class="btn btn-sm btn-outline-success">
                                        Nova compra
                                    </a>

                                    <a
                                        href="<?= e(
                                                    base_url(
                                                        'materials.php?history='
                                                            . $r['id']
                                                    )
                                                ) ?>"
                                        class="btn btn-sm btn-outline-info">
                                        Histórico
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
                                            value="<?= e(
                                                        csrf_token()
                                                    ) ?>">


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


<!-- =====================================================================
     CÁLCULO VISUAL DO VALOR UNITÁRIO
====================================================================== -->

<script>
    document.addEventListener(
        'DOMContentLoaded',
        function() {

            const quantity =
                document.getElementById('quantity');

            const totalCost =
                document.getElementById('total_cost');

            const unitCostPreview =
                document.getElementById(
                    'unit_cost_preview'
                );


            if (
                !quantity ||
                !totalCost ||
                !unitCostPreview
            ) {
                return;
            }


            function calculateUnitCost() {

                const qty =
                    parseFloat(
                        quantity.value
                    ) || 0;

                const total =
                    parseFloat(
                        totalCost.value
                    ) || 0;


                if (qty <= 0) {

                    unitCostPreview.value =
                        'R$ 0,00';

                    return;
                }


                const unit =
                    total / qty;


                unitCostPreview.value =
                    unit.toLocaleString(
                        'pt-BR', {
                            style: 'currency',
                            currency: 'BRL'
                        }
                    );
            }


            quantity.addEventListener(
                'input',
                calculateUnitCost
            );


            totalCost.addEventListener(
                'input',
                calculateUnitCost
            );

        }
    );
</script>


<?php render_footer(); ?>