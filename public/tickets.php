<?php
require_once __DIR__ . '/../src/layout.php';
require_auth();
$pdo = db();
function generateTicketNumber(PDO $pdo, string $occurredAt): string
{
    // Extrai somente a data do chamado.
    $date = date('Y-m-d', strtotime($occurredAt));

    // Procura o contador correspondente a essa data.
    $stmt = $pdo->prepare("
        SELECT last_number
        FROM ticket_daily_sequences
        WHERE sequence_date = ?
    ");

    $stmt->execute([$date]);

    $lastNumber = $stmt->fetchColumn();

    // Se ainda não existe contador para essa data,
    // este será o primeiro chamado do dia.
    if ($lastNumber === false) {

        $nextNumber = 1;

        $stmt = $pdo->prepare("
            INSERT INTO ticket_daily_sequences
                (sequence_date, last_number)
            VALUES
                (?, ?)
        ");

        $stmt->execute([
            $date,
            $nextNumber
        ]);
    } else {

        // Já existem chamados nessa data.
        // Soma 1 ao último número utilizado.
        $nextNumber = ((int)$lastNumber) + 1;

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

    // Converte 2026-09-06 para 260906.
    $datePart = date(
        'ymd',
        strtotime($occurredAt)
    );

    // Converte 1 para 01, 2 para 02 etc.
    $sequencePart = str_pad(
        $nextNumber,
        2,
        '0',
        STR_PAD_LEFT
    );

    // Resultado: 260906-01
    return $datePart . '-' . $sequencePart;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $client = (int)$_POST['client_id'];
    $status = $_POST['status'] ?? 'aberto';
    $type = $_POST['service_type'] ?? 'remoto';
    $charged = isset($_POST['service_charged']) ? 1 : 0;
    $amount = (float)str_replace(',', '.', $_POST['service_amount'] ?? 0);
    // regra: contrato + remoto não cobra; vistoria/orçamento não cobram
    $st = $pdo->prepare('SELECT has_contract FROM clients WHERE id=?');
    $st->execute([$client]);
    $hasContract = (int)$st->fetchColumn();
    if (($hasContract && $type === 'remoto') || in_array($type, ['vistoria', 'orcamento'], true)) {
        $charged = 0;
        $amount = 0;
    }
    if ($id) {
        $st = $pdo->prepare('UPDATE tickets SET occurred_at=?,client_id=?,complainant=?,request_text=?,service_type=?,notes=?,status=?,solution_text=?,service_charged=?,service_amount=? WHERE id=?');
        $st->execute([$_POST['occurred_at'], $client, trim($_POST['complainant'] ?? ''), trim($_POST['request_text']), $type, trim($_POST['notes'] ?? ''), $status, trim($_POST['solution_text'] ?? ''), $charged, $amount, $id]);
        $ticketId = $id;
    } else {
        /*
     * Gera nosso novo número:
     *
     * Exemplo:
     * 260906-01
     */
        $ticketNumber = generateTicketNumber(
            $pdo,
            $_POST['occurred_at']
        );


        /*
     * Prepara o cadastro do chamado.
     *
     * Observe que agora adicionamos ticket_number.
     */
        $st = $pdo->prepare("
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
            service_charged,
            service_amount,
            created_by
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");


        /*
     * Os valores abaixo correspondem aos ?
     * exatamente na mesma ordem.
     */
        $st->execute([
            $ticketNumber,
            $_POST['occurred_at'],
            $client,
            trim($_POST['complainant'] ?? ''),
            trim($_POST['request_text']),
            $type,
            trim($_POST['notes'] ?? ''),
            $status,
            trim($_POST['solution_text'] ?? ''),
            $charged,
            $amount,
            auth_user()['id']
        ]);


        /*
     * Recupera o ID interno criado pelo MySQL.
     *
     * Exemplo:
     *
     * id            = 18
     * ticket_number = 260906-01
     */
        $ticketId = (int)$pdo->lastInsertId();
    }
    if (!empty($_POST['material_id']) && (float)($_POST['material_qty'] ?? 0) > 0) {
        $mid = (int)$_POST['material_id'];
        $st = $pdo->prepare('SELECT description FROM materials WHERE id=?');
        $st->execute([$mid]);
        $desc = (string)$st->fetchColumn();
        $qty = (float)str_replace(',', '.', $_POST['material_qty']);
        $unit = (float)str_replace(',', '.', $_POST['material_unit_price'] ?? 0);
        $st = $pdo->prepare('INSERT INTO ticket_materials(ticket_id,material_id,description,quantity,unit_price) VALUES (?,?,?,?,?)');
        $st->execute([$ticketId, $mid, $desc, $qty, $unit]);
        $pdo->prepare('UPDATE tickets SET materials_sold=1 WHERE id=?')->execute([$ticketId]);
    }
    flash('success', 'Chamado salvo.');
    redirect(base_url('tickets.php'));
}
$clients = $pdo->query("SELECT id,name FROM clients WHERE deleted_at IS NULL AND status='ativo' ORDER BY name")->fetchAll();
$materials = $pdo->query("SELECT id,description FROM materials WHERE deleted_at IS NULL ORDER BY description")->fetchAll();
$rows = $pdo->query("SELECT t.*,c.name client,(SELECT COALESCE(SUM(total_price),0) FROM ticket_materials tm WHERE tm.ticket_id=t.id) materials_total FROM tickets t JOIN clients c ON c.id=t.client_id ORDER BY occurred_at DESC LIMIT 500")->fetchAll();
render_header('Chamados');
?>
<div class="row g-3">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-body">
                <h5>Abrir chamado</h5>
                <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <div class="mb-2"><label>Data e horário</label><input class="form-control" type="datetime-local" name="occurred_at" value="<?= date('Y-m-d\TH:i') ?>" required></div>
                    <div class="mb-2"><label>Cliente</label><select class="form-select" name="client_id" required><?php foreach ($clients as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="mb-2"><label>Reclamante</label><input class="form-control" name="complainant"></div>
                    <div class="mb-2"><label>Solicitação</label><textarea class="form-control" name="request_text" required></textarea></div>
                    <div class="row">
                        <div class="col"><label>Tipo</label><select class="form-select" name="service_type">
                                <option value="remoto">Remoto</option>
                                <option value="presencial">Presencial</option>
                                <option value="preventiva">Preventiva</option>
                                <option value="vistoria">Vistoria</option>
                                <option value="orcamento">Orçamento</option>
                            </select></div>
                        <div class="col"><label>Status</label><select class="form-select" name="status">
                                <option>aberto</option>
                                <option>pendente</option>
                                <option>solucionado</option>
                                <option>fechado</option>
                            </select></div>
                    </div>
                    <div class="mb-2"><label>Solução aplicada</label><textarea class="form-control" name="solution_text"></textarea></div>
                    <div class="form-check my-2"><input class="form-check-input" type="checkbox" name="service_charged" id="sc"><label class="form-check-label" for="sc">Houve cobrança de serviço?</label></div>
                    <div class="mb-2"><label>Valor serviço</label><input class="form-control" name="service_amount" value="0"></div>
                    <hr>
                    <h6>Material vendido (opcional)</h6><select class="form-select mb-2" name="material_id">
                        <option value="">Nenhum</option><?php foreach ($materials as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['description']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="row">
                        <div class="col"><input class="form-control" name="material_qty" placeholder="Qtd"></div>
                        <div class="col"><input class="form-control" name="material_unit_price" placeholder="Valor unitário"></div>
                    </div>
                    <div class="mb-3"><label>Observações</label><textarea class="form-control" name="notes"></textarea></div><button class="btn btn-primary">Salvar chamado</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-8"><input class="form-control mb-2" placeholder="Pesquisa instantânea" data-search-table="#ticketTable">
        <div class="table-wrap">
            <div class="table-responsive">
                <table id="ticketTable" class="table table-sm">
                    <thead>
                        <tr>
                            <th>Nº</th>
                            <th>Data</th>
                            <th>Cliente</th>
                            <th>Tipo</th>
                            <th>Status</th>
                            <th>Serviço</th>
                            <th>Materiais</th>
                            <th>Faturado</th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($rows as $r): ?><tr>
                                <td><?= e($r['ticket_number']) ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($r['occurred_at'])) ?></td>
                                <td><?= e($r['client']) ?></td>
                                <td><?= e($r['service_type']) ?></td>
                                <td><?= e($r['status']) ?></td>
                                <td><?= money($r['service_amount']) ?></td>
                                <td><?= money($r['materials_total']) ?></td>
                                <td><?= $r['billed_at'] ? 'Sim' : 'Não' ?></td>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>