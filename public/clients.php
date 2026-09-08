<?php

require_once __DIR__ . '/../src/layout.php';

require_auth();

$pdo = db();



/*
|--------------------------------------------------------------------------
| SALVAR / ADICIONAR CLIENTE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();


    /*
    |--------------------------------------------------------------------------
    | ID
    |--------------------------------------------------------------------------
    */

    $id =
        (int)($_POST['id'] ?? 0);


    /*
    |--------------------------------------------------------------------------
    | DADOS PRINCIPAIS
    |--------------------------------------------------------------------------
    */

    $name =
        trim(
            $_POST['name']
                ?? ''
        );


    $document =
        trim(
            $_POST['document']
                ?? ''
        );


    $phone =
        trim(
            $_POST['phone']
                ?? ''
        );


    $email =
        trim(
            $_POST['email']
                ?? ''
        );


    $address =
        trim(
            $_POST['address']
                ?? ''
        );


    $status =
        $_POST['status']
        ?? 'ativo';


    $standardKm =
        (float)str_replace(
            ',',
            '.',
            $_POST['standard_km']
                ?? 0
        );


    $hasContract =
        isset(
            $_POST['has_contract']
        )
        ? 1
        : 0;


    $monthlyFee =
        (float)str_replace(
            ',',
            '.',
            $_POST['monthly_fee']
                ?? 0
        );


    $extraVisitFee =
        (float)str_replace(
            ',',
            '.',
            $_POST['extra_visit_fee']
                ?? 0
        );


    $dueDay =
        (int)(
            $_POST['due_day']
            ?? 20
        );


    $contractNotes =
        trim(
            $_POST['contract_notes']
                ?? ''
        );


    $notes =
        trim(
            $_POST['notes']
                ?? ''
        );


    /*
    |--------------------------------------------------------------------------
    | VALIDAR NOME
    |--------------------------------------------------------------------------
    */

    if ($name === '') {

        flash(
            'danger',
            'Informe o Nome/Razão Social.'
        );


        redirect(
            $id
                ? base_url(
                    'clients.php?edit='
                        . $id
                )
                : base_url(
                    'clients.php'
                )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | NORMALIZAR CPF/CNPJ
    |--------------------------------------------------------------------------
    |
    | Exemplo:
    |
    | 11.111.111/0001-11
    |
    | vira:
    |
    | 11111111000111
    |
    */

    $documentNormalized =
        preg_replace(
            '/\D/',
            '',
            $document
        );


    /*
    |--------------------------------------------------------------------------
    | VERIFICAR NOME DUPLICADO
    |--------------------------------------------------------------------------
    */

    if ($id) {

        /*
        |--------------------------------------------------------------------------
        | EDIÇÃO
        |--------------------------------------------------------------------------
        |
        | Ignora o próprio cliente.
        |
        */

        $stmt = $pdo->prepare("
            SELECT
                id,
                name
            FROM clients
            WHERE deleted_at IS NULL
              AND LOWER(TRIM(name)) = LOWER(TRIM(?))
              AND id <> ?
            LIMIT 1
        ");


        $stmt->execute([
            $name,
            $id
        ]);
    } else {

        /*
        |--------------------------------------------------------------------------
        | NOVO CLIENTE
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                id,
                name
            FROM clients
            WHERE deleted_at IS NULL
              AND LOWER(TRIM(name)) = LOWER(TRIM(?))
            LIMIT 1
        ");


        $stmt->execute([
            $name
        ]);
    }


    $duplicateName =
        $stmt->fetch();


    if ($duplicateName) {

        flash(
            'danger',
            'Já existe um cliente cadastrado com o Nome/Razão Social "'
                . $duplicateName['name']
                . '".'
        );


        redirect(
            $id
                ? base_url(
                    'clients.php?edit='
                        . $id
                )
                : base_url(
                    'clients.php'
                )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFICAR CPF/CNPJ DUPLICADO
    |--------------------------------------------------------------------------
    |
    | Só verificamos se foi informado algum documento.
    |
    */

    if ($documentNormalized !== '') {


        /*
        |--------------------------------------------------------------------------
        | A consulta abaixo remove:
        |
        | .
        | /
        | -
        | espaços
        |
        | do CPF/CNPJ já armazenado no banco.
        |--------------------------------------------------------------------------
        */

        if ($id) {

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    name,
                    document
                FROM clients
                WHERE deleted_at IS NULL

                  AND REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    document,
                                    '.',
                                    ''
                                ),
                                '/',
                                ''
                            ),
                            '-',
                            ''
                        ),
                        ' ',
                        ''
                    ) = ?

                  AND id <> ?

                LIMIT 1
            ");


            $stmt->execute([
                $documentNormalized,
                $id
            ]);
        } else {

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    name,
                    document
                FROM clients
                WHERE deleted_at IS NULL

                  AND REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    document,
                                    '.',
                                    ''
                                ),
                                '/',
                                ''
                            ),
                            '-',
                            ''
                        ),
                        ' ',
                        ''
                    ) = ?

                LIMIT 1
            ");


            $stmt->execute([
                $documentNormalized
            ]);
        }


        $duplicateDocument =
            $stmt->fetch();


        if ($duplicateDocument) {

            flash(
                'danger',
                'Já existe um cliente cadastrado com este CPF/CNPJ. Cliente: '
                    . $duplicateDocument['name']
                    . '.'
            );


            redirect(
                $id
                    ? base_url(
                        'clients.php?edit='
                            . $id
                    )
                    : base_url(
                        'clients.php'
                    )
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | VALORES PARA INSERT / UPDATE
    |--------------------------------------------------------------------------
    */

    $vals = [

        $name,
        $document,
        $phone,
        $email,
        $address,
        $status,
        $standardKm,
        $hasContract,
        $monthlyFee,
        $extraVisitFee,
        $dueDay,
        $contractNotes,
        $notes

    ];


    /*
    |--------------------------------------------------------------------------
    | EDITAR CLIENTE
    |--------------------------------------------------------------------------
    */

    if ($id) {

        $stmt = $pdo->prepare("
            UPDATE clients
            SET
                name = ?,
                document = ?,
                phone = ?,
                email = ?,
                address = ?,
                status = ?,
                standard_km = ?,
                has_contract = ?,
                monthly_fee = ?,
                extra_visit_fee = ?,
                due_day = ?,
                contract_notes = ?,
                notes = ?
            WHERE id = ?
        ");


        $stmt->execute([
            ...$vals,
            $id
        ]);


        flash(
            'success',
            'Cliente atualizado com sucesso.'
        );


        /*
    |--------------------------------------------------------------------------
    | ADICIONAR CLIENTE
    |--------------------------------------------------------------------------
    */
    } else {

        $stmt = $pdo->prepare("
            INSERT INTO clients (
                name,
                document,
                phone,
                email,
                address,
                status,
                standard_km,
                has_contract,
                monthly_fee,
                extra_visit_fee,
                due_day,
                contract_notes,
                notes
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
        ");


        $stmt->execute(
            $vals
        );


        flash(
            'success',
            'Cliente adicionado com sucesso.'
        );
    }


    redirect(
        base_url(
            'clients.php'
        )
    );
}

/*
|--------------------------------------------------------------------------
| EXCLUSÃO LÓGICA
|--------------------------------------------------------------------------
*/

if (isset($_GET['delete'])) {

    if (
        !role_allowed([
            'admin'
        ])
    ) {

        exit('Acesso negado');
    }


    $stmt = $pdo->prepare("
        UPDATE clients
        SET deleted_at = NOW()
        WHERE id = ?
    ");


    $stmt->execute([
        (int)$_GET['delete']
    ]);


    flash(
        'warning',
        'Cliente inativado logicamente.'
    );


    redirect(
        base_url('clients.php')
    );
}


/*
|--------------------------------------------------------------------------
| MODO EDITAR
|--------------------------------------------------------------------------
*/

$edit = null;


if (isset($_GET['edit'])) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM clients
        WHERE id = ?
          AND deleted_at IS NULL
    ");


    $stmt->execute([
        (int)$_GET['edit']
    ]);


    $edit =
        $stmt->fetch();


    if (!$edit) {

        flash(
            'danger',
            'Cliente não encontrado.'
        );


        redirect(
            base_url('clients.php')
        );
    }
}


/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$q =
    trim(
        $_GET['q']
            ?? ''
    );


$status =
    $_GET['status']
    ?? '';


$sql = "
    SELECT *
    FROM clients
    WHERE deleted_at IS NULL
";


$args = [];


/*
|--------------------------------------------------------------------------
| PESQUISA
|--------------------------------------------------------------------------
*/

if ($q !== '') {

    $sql .= "
        AND (
            name LIKE ?
            OR document LIKE ?
        )
    ";


    $args[] =
        "%{$q}%";


    $args[] =
        "%{$q}%";
}


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

if (
    in_array(
        $status,
        [
            'ativo',
            'inativo'
        ],
        true
    )
) {

    $sql .= "
        AND status = ?
    ";


    $args[] =
        $status;
}


$sql .= "
    ORDER BY name
";


$stmt =
    $pdo->prepare(
        $sql
    );


$stmt->execute(
    $args
);


$rows =
    $stmt->fetchAll();


render_header(
    'Clientes'
);

?>


<div class="row g-3">


    <!-- ================================================================
         FORMULÁRIO
    ================================================================= -->

    <div class="col-lg-4">


        <div class="card">


            <div class="card-body">


                <h5>


                    <?= $edit

                        ? 'Editar cliente'

                        : 'Novo cliente'

                    ?>


                </h5>


                <form method="post">


                    <!-- CSRF -->

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e(
                                    csrf_token()
                                ) ?>">


                    <!-- ID -->

                    <input
                        type="hidden"
                        name="id"
                        value="<?= e(
                                    (string)(
                                        $edit['id']
                                        ?? ''
                                    )
                                ) ?>">


                    <!-- ====================================================
                         NOME
                    ===================================================== -->

                    <div class="mb-2">


                        <label class="form-label">

                            Nome/Razão Social

                        </label>


                        <input
                            class="form-control"
                            name="name"
                            value="<?= e(
                                        $edit['name']
                                            ?? ''
                                    ) ?>"
                            required>


                    </div>


                    <!-- ====================================================
                         CPF / CNPJ
                    ===================================================== -->

                    <div class="mb-2">


                        <label class="form-label">

                            CPF/CNPJ

                        </label>


                        <input
                            class="form-control"
                            name="document"
                            value="<?= e(
                                        $edit['document']
                                            ?? ''
                                    ) ?>">


                    </div>


                    <!-- ====================================================
                         TELEFONE
                    ===================================================== -->

                    <div class="mb-2">


                        <label class="form-label">

                            Telefone

                        </label>


                        <input
                            class="form-control"
                            name="phone"
                            value="<?= e(
                                        $edit['phone']
                                            ?? ''
                                    ) ?>">


                    </div>


                    <!-- ====================================================
                         EMAIL
                    ===================================================== -->

                    <div class="mb-2">


                        <label class="form-label">

                            E-mail

                        </label>


                        <input
                            class="form-control"
                            name="email"
                            value="<?= e(
                                        $edit['email']
                                            ?? ''
                                    ) ?>">


                    </div>


                    <!-- ====================================================
                         ENDEREÇO
                    ===================================================== -->

                    <div class="mb-2">


                        <label class="form-label">

                            Endereço Completo

                        </label>


                        <input
                            class="form-control"
                            name="address"
                            value="<?= e(
                                        $edit['address']
                                            ?? ''
                                    ) ?>">


                    </div>


                    <!-- ====================================================
                         STATUS + KM
                    ===================================================== -->

                    <div class="row">


                        <div class="col">


                            <label class="form-label">

                                Status

                            </label>


                            <select
                                class="form-select"
                                name="status">


                                <option
                                    value="ativo"
                                    <?= (
                                        $edit['status']
                                        ?? 'ativo'
                                    ) === 'ativo'
                                        ? 'selected'
                                        : ''
                                    ?>>

                                    Ativo

                                </option>


                                <option
                                    value="inativo"
                                    <?= (
                                        $edit['status']
                                        ?? ''
                                    ) === 'inativo'
                                        ? 'selected'
                                        : ''
                                    ?>>

                                    Inativo

                                </option>


                            </select>


                        </div>


                        <div class="col">


                            <label class="form-label">

                                KM padrão

                            </label>


                            <input
                                class="form-control"
                                name="standard_km"
                                value="<?= e(
                                            (string)(
                                                $edit['standard_km']
                                                ?? 0
                                            )
                                        ) ?>">


                        </div>


                    </div>


                    <!-- ====================================================
                         CONTRATO
                    ===================================================== -->

                    <div class="form-check my-3">


                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="has_contract"
                            id="has_contract"
                            <?= !empty($edit['has_contract'])
                                ? 'checked'
                                : ''
                            ?>>


                        <label
                            class="form-check-label"
                            for="has_contract">

                            Possui contrato

                        </label>


                    </div>


                    <!-- ====================================================
                         MENSALIDADE + VISITA
                    ===================================================== -->

                    <div class="row">


                        <div class="col">


                            <label class="form-label">

                                Mensalidade

                            </label>


                            <input
                                class="form-control"
                                name="monthly_fee"
                                value="<?= e(
                                            (string)(
                                                $edit['monthly_fee']
                                                ?? 0
                                            )
                                        ) ?>">


                        </div>


                        <div class="col">


                            <label class="form-label">

                                Visita adicional

                            </label>


                            <input
                                class="form-control"
                                name="extra_visit_fee"
                                value="<?= e(
                                            (string)(
                                                $edit['extra_visit_fee']
                                                ?? 0
                                            )
                                        ) ?>">


                        </div>


                    </div>


                    <!-- ====================================================
                         DIA VENCIMENTO
                    ===================================================== -->

                    <div class="mb-2">


                        <label class="form-label">

                            Dia vencimento

                        </label>


                        <input
                            class="form-control"
                            type="number"
                            min="1"
                            max="28"
                            name="due_day"
                            value="<?= e(
                                        (string)(
                                            $edit['due_day']
                                            ?? 20
                                        )
                                    ) ?>">


                    </div>


                    <!-- ====================================================
                         OBSERVAÇÕES CONTRATO
                    ===================================================== -->

                    <div class="mb-2">


                        <label class="form-label">

                            Observações contrato

                        </label>


                        <textarea
                            class="form-control"
                            name="contract_notes"><?= e(
                                                        $edit['contract_notes']
                                                            ?? ''
                                                    ) ?></textarea>


                    </div>


                    <!-- ====================================================
                         OBSERVAÇÕES GERAIS
                    ===================================================== -->

                    <div class="mb-3">


                        <label class="form-label">

                            Observações gerais

                        </label>


                        <textarea
                            class="form-control"
                            name="notes"><?= e(
                                                $edit['notes']
                                                    ?? ''
                                            ) ?></textarea>


                    </div>


                    <!-- ====================================================
                         BOTÕES
                    ===================================================== -->

                    <?php if ($edit): ?>


                        <button
                            type="submit"
                            class="btn btn-primary">

                            Salvar

                        </button>


                        <a
                            href="<?= e(
                                        base_url(
                                            'clients.php'
                                        )
                                    ) ?>"
                            class="btn btn-secondary">

                            Cancelar edição

                        </a>


                    <?php else: ?>


                        <button
                            type="submit"
                            class="btn btn-success">

                            Adicionar

                        </button>


                    <?php endif; ?>


                </form>


            </div>


        </div>


    </div>


    <!-- ================================================================
         LISTAGEM
    ================================================================= -->

    <div class="col-lg-8">


        <!-- FILTROS -->

        <form class="row g-2 mb-2">


            <div class="col">


                <input
                    class="form-control"
                    name="q"
                    placeholder="Pesquisar nome ou CPF/CNPJ"
                    value="<?= e($q) ?>">


            </div>


            <div class="col-auto">


                <select
                    class="form-select"
                    name="status">


                    <option value="">

                        Todos status

                    </option>


                    <option
                        value="ativo"
                        <?= $status === 'ativo'
                            ? 'selected'
                            : ''
                        ?>>

                        Ativo

                    </option>


                    <option
                        value="inativo"
                        <?= $status === 'inativo'
                            ? 'selected'
                            : ''
                        ?>>

                        Inativo

                    </option>


                </select>


            </div>


            <div class="col-auto">


                <button
                    class="btn btn-outline-primary"
                    type="submit">

                    Filtrar

                </button>


            </div>


        </form>


        <!-- TABELA -->

        <div class="table-wrap">


            <div class="table-responsive">


                <table class="table">


                    <thead>


                        <tr>


                            <th>
                                Cliente
                            </th>


                            <th>
                                Documento
                            </th>


                            <th>
                                Status
                            </th>


                            <th>
                                Contrato
                            </th>


                            <th>
                                Mensalidade
                            </th>


                            <th>
                                Ações
                            </th>


                        </tr>


                    </thead>


                    <tbody>


                        <?php
                        foreach (
                            $rows
                            as $row
                        ):
                        ?>


                            <tr>


                                <td>

                                    <?= e(
                                        $row['name']
                                    ) ?>

                                </td>


                                <td>

                                    <?= e(
                                        $row['document']
                                    ) ?>

                                </td>


                                <td>

                                    <?= e(
                                        $row['status']
                                    ) ?>

                                </td>


                                <td>

                                    <?= $row['has_contract']
                                        ? 'Sim'
                                        : 'Não'
                                    ?>

                                </td>


                                <td>

                                    <?= money(
                                        $row['monthly_fee']
                                    ) ?>

                                </td>


                                <td class="text-nowrap">


                                    <a
                                        class="
                                            btn
                                            btn-sm
                                            btn-outline-primary
                                        "
                                        href="<?= e(
                                                    base_url(
                                                        'clients.php?edit='
                                                            . $row['id']
                                                    )
                                                ) ?>">

                                        Editar

                                    </a>


                                    <a
                                        class="
                                            btn
                                            btn-sm
                                            btn-outline-danger
                                        "
                                        data-confirm="
                                            Excluir logicamente este cliente?
                                        "
                                        href="<?= e(
                                                    base_url(
                                                        'clients.php?delete='
                                                            . $row['id']
                                                    )
                                                ) ?>">

                                        Excluir

                                    </a>


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