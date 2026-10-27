<?php

declare(strict_types=1);

const PAGINATION_SIZES   = [20, 50, 100];
const PAGINATION_DEFAULT = 20;

/**
 * Valida os parametros da URL e devolve os recortes da consulta.
 */
function pagination_state(int $total, int $padrao = PAGINATION_DEFAULT): array
{
    $per = (int)($_GET['per'] ?? $padrao);
    if (!in_array($per, PAGINATION_SIZES, true)) {
        $per = $padrao;
    }

    $paginas = max(1, (int)ceil($total / $per));

    $pagina = (int)($_GET['page'] ?? 1);
    if ($pagina < 1) {
        $pagina = 1;
    }
    if ($pagina > $paginas) {
        $pagina = $paginas;
    }

    $offset = ($pagina - 1) * $per;

    return [
        'total'   => $total,
        'per'     => $per,
        'pagina'  => $pagina,
        'paginas' => $paginas,
        'offset'  => $offset,
        'limite'  => $per,
        'inicio'  => $total > 0 ? $offset + 1 : 0,
        'fim'     => min($offset + $per, $total),
    ];
}

/**
 * Monta a URL preservando os filtros que ja estavam na tela.
 */
function pagination_url(int $pagina, int $per): string
{
    $q = $_GET;
    $q['page'] = $pagina;
    $q['per']  = $per;
    return '?' . http_build_query($q);
}

/**
 * Desenha o rodape: resumo, seletor de quantidade e setas.
 */
function pagination_render(array $p): string
{
    $esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    $html  = '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2">';

    $html .= $p['total'] === 0
        ? '<span class="text-muted small">Nenhum registro encontrado.</span>'
        : '<span class="text-muted small">Mostrando ' . $p['inicio']
        . ' a ' . $p['fim'] . ' de ' . $p['total'] . ' registro(s)</span>';

    $html .= '<div class="d-flex align-items-center gap-2">';

    $html .= '<form method="get" class="d-flex align-items-center gap-1">';
    foreach ($_GET as $chave => $valor) {
        if (in_array($chave, ['page', 'per'], true) || is_array($valor)) {
            continue;
        }
        $html .= '<input type="hidden" name="' . $esc($chave) . '" value="' . $esc($valor) . '">';
    }
    $html .= '<label class="text-muted small mb-0">Por pagina</label>';
    $html .= '<select name="per" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">';
    foreach (PAGINATION_SIZES as $tamanho) {
        $sel   = $p['per'] === $tamanho ? ' selected' : '';
        $html .= '<option value="' . $tamanho . '"' . $sel . '>' . $tamanho . '</option>';
    }
    $html .= '</select></form>';

    if ($p['paginas'] > 1) {
        $anterior = $p['pagina'] > 1 ? pagination_url($p['pagina'] - 1, $p['per']) : null;
        $proxima  = $p['pagina'] < $p['paginas'] ? pagination_url($p['pagina'] + 1, $p['per']) : null;

        $html .= '<div class="btn-group btn-group-sm" role="group">';

        $html .= $anterior
            ? '<a class="btn btn-outline-secondary" href="' . $esc($anterior) . '" title="Pagina anterior">&laquo;</a>'
            : '<button class="btn btn-outline-secondary" disabled>&laquo;</button>';

        $html .= '<span class="btn btn-outline-secondary disabled">'
            . $p['pagina'] . ' / ' . $p['paginas'] . '</span>';

        $html .= $proxima
            ? '<a class="btn btn-outline-secondary" href="' . $esc($proxima) . '" title="Proxima pagina">&raquo;</a>'
            : '<button class="btn btn-outline-secondary" disabled>&raquo;</button>';

        $html .= '</div>';
    }

    $html .= '</div></div>';

    return $html;
}
