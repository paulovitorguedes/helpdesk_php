<?php
declare(strict_types=1);

function client_form_text(array $input, string $key, string $default = ''): string
{
    $value = $input[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : '';
}

function client_form_decimal(string $value, int $integerDigits, bool $currency = false): string
{
    $value = trim($value);
    if ($currency) {
        $value = preg_replace('/^R\$[\s\x{00a0}]*/u', '', $value);
    }
    if (str_contains($value, ',')) {
        $pattern = $currency
            ? '/^(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2}$/'
            : '/^\d+,\d{1,2}$/';
        if (!preg_match($pattern, $value)) throw new InvalidArgumentException('Valor numérico inválido.');
        $value = str_replace(',', '.', str_replace('.', '', $value));
    } elseif ($currency && preg_match('/^\d{1,3}(?:\.\d{3})+$/', $value)) {
        $value = str_replace('.', '', $value);
    } elseif (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
        throw new InvalidArgumentException('Valor numérico inválido.');
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $whole = ltrim($whole, '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > $integerDigits) throw new InvalidArgumentException('Valor acima do limite do campo.');
    return $whole . '.' . str_pad($fraction, 2, '0');
}

function client_form_document(string $value): string
{
    $digits = preg_replace('/\D/', '', $value);
    $mask = strlen($digits) <= 11 ? '000.000.000-00' : '00.000.000/0000-00';
    $result = '';
    $position = 0;
    for ($index = 0; $index < strlen($mask) && $position < strlen($digits); $index++) {
        $result .= $mask[$index] === '0' ? $digits[$position++] : $mask[$index];
    }
    return $result;
}

function client_form_display(string $value, bool $currency = false): string
{
    if ($value === '') return '';
    try {
        $canonical = client_form_decimal($value, $currency ? 10 : 8, $currency);
        $formatted = number_format((float)$canonical, 2, ',', $currency ? '.' : '');
        return $currency ? $formatted : rtrim(rtrim($formatted, '0'), ',');
    } catch (InvalidArgumentException $error) {
        return $value;
    }
}

function client_form_normalize(array $input, ?array $existing = null): array
{
    $hasContract = isset($input['has_contract']);
    $data = [];
    foreach (['name', 'document', 'phone', 'email', 'address', 'status', 'contract_notes', 'notes'] as $field) {
        $data[$field] = client_form_text($input, $field);
    }
    $errors = [];
    if ($data['name'] === '') $errors['name'] = 'Informe o Nome/Razão Social.';
    if ($data['address'] === '') $errors['address'] = 'Informe o Endereço Completo.';
    if (!in_array($data['status'], ['ativo', 'inativo'], true)) $errors['status'] = 'Selecione um Status válido.';

    $documentDigits = preg_replace('/\D/', '', $data['document']);
    if (strlen($documentDigits) > 14) {
        $errors['document'] = 'Informe no máximo 14 números no CPF/CNPJ.';
    } else {
        $data['document'] = client_form_document($data['document']);
    }
    $data['phone'] = preg_replace('/\D/', '', $data['phone']);
    if (strlen($data['phone']) > 40) $errors['phone'] = 'Informe no máximo 40 números no Telefone.';
    $data['has_contract'] = $hasContract ? 1 : 0;

    foreach (['standard_km' => 'KM padrão', 'monthly_fee' => 'Mensalidade', 'extra_visit_fee' => 'Visita adicional'] as $field => $label) {
        $currency = $field !== 'standard_km';
        $default = $currency && !$hasContract ? (string)($existing[$field] ?? '0') : '';
        $raw = client_form_text($input, $field, $default);
        $requiresMoney = $currency && ($hasContract || !empty($existing['has_contract']));
        if ($raw === '' && !$requiresMoney) $raw = '0';
        try {
            $data[$field] = client_form_decimal($raw, $currency ? 10 : 8, $currency);
        } catch (InvalidArgumentException $error) {
            $errors[$field] = $raw === ''
                ? 'Informe ' . $label . '.'
                : $label . ': informe um valor não negativo com até duas casas decimais, dentro do limite do campo.';
            $data[$field] = '0.00';
        }
    }

    if (!$hasContract && ($data['monthly_fee'] !== '0.00' || $data['extra_visit_fee'] !== '0.00'
        || isset($errors['monthly_fee']) || isset($errors['extra_visit_fee']))) {
        $errors['contract_toggle'] = 'Para desmarcar Possui contrato, zere Mensalidade e Visita adicional.';
    }

    $dueDay = client_form_text($input, 'due_day');
    if ($hasContract && !in_array($dueDay, ['10', '20'], true)) {
        $errors['due_day'] = 'Selecione Dia 10 ou Dia 20 para o vencimento do contrato.';
    }
    // Sem contrato nao se exige vencimento. A coluna legada NOT NULL e preservada;
    // ela nao e reinterpretada como novo vencimento financeiro nesta entrega.
    $data['due_day'] = in_array($dueDay, ['10', '20'], true)
        ? (int)$dueDay : (int)($existing['due_day'] ?? 20);
    if (!array_key_exists('contract_notes', $input) && $existing !== null) {
        $data['contract_notes'] = (string)($existing['contract_notes'] ?? '');
    }
    return ['data' => $data, 'errors' => $errors];
}
