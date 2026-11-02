(() => {
  'use strict';
  const form = document.getElementById('client-form');
  if (!form) return;
  const contract = form.elements.namedItem('has_contract');
  const fields = document.getElementById('contract-fields');
  const warning = document.getElementById('contract-toggle-error');
  const due = form.elements.namedItem('due_day');
  const cutoff = document.getElementById('competence-cutoff');
  const monthly = form.elements.namedItem('monthly_fee');
  const visit = form.elements.namedItem('extra_visit_fee');

  function parseDecimal(value, currency) {
    let text = value.trim().replace(/^R\$[\s\u00a0]*/, '');
    if (text.includes(',')) {
      const pattern = currency ? /^(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2}$/ : /^\d+,\d{1,2}$/;
      if (!pattern.test(text)) return null;
      text = text.replace(/\./g, '').replace(',', '.');
    } else if (currency && /^\d{1,3}(?:\.\d{3})+$/.test(text)) {
      text = text.replace(/\./g, '');
    } else if (!/^\d+(?:\.\d{1,2})?$/.test(text)) {
      return null;
    }
    const valueNumber = Number(text);
    const maximum = currency ? 9999999999.99 : 99999999.99;
    return Number.isFinite(valueNumber) && valueNumber >= 0 && valueNumber <= maximum ? valueNumber : null;
  }

  function validateNumber(input, currency) {
    if (currency && !contract.checked) {
      input.setCustomValidity('');
      return;
    }
    const isEmpty = input.value.trim() === '';
    input.setCustomValidity(isEmpty || parseDecimal(input.value, currency) !== null
      ? '' : 'Informe um valor não negativo com até duas casas decimais, dentro do limite do campo.');
  }

  function refreshContract() {
    fields.hidden = !contract.checked;
    contract.setAttribute('aria-expanded', String(contract.checked));
    fields.querySelectorAll('[data-contract-required]').forEach(input => {
      input.required = contract.checked;
    });
    due.setCustomValidity(contract.checked && !['10', '20'].includes(due.value)
      ? 'Selecione Dia 10 ou Dia 20 para o vencimento do contrato.' : '');
    cutoff.textContent = due.value === '10' ? 'Virada da competência dia 05'
      : due.value === '20' ? 'Virada da competência dia 15' : '';
    validateNumber(monthly, true);
    validateNumber(visit, true);
  }

  contract.addEventListener('change', () => {
    if (!contract.checked && (parseDecimal(monthly.value, true) !== 0 || parseDecimal(visit.value, true) !== 0)) {
      contract.checked = true;
      warning.textContent = 'Para desmarcar Possui contrato, zere Mensalidade e Visita adicional.';
      warning.hidden = false;
    } else {
      warning.hidden = true;
    }
    refreshContract();
  });
  due.addEventListener('change', refreshContract);

  form.querySelectorAll('[data-client-money], [data-client-km]').forEach(input => {
    const currency = input.hasAttribute('data-client-money');
    input.addEventListener('input', () => {
      input.value = input.value.replace(/^R\$[\s\u00a0]*/, '').replace(/[^\d.,-]/g, '');
      validateNumber(input, currency);
    });
    input.addEventListener('blur', () => {
      const number = parseDecimal(input.value, currency);
      if (number !== null) {
        input.value = number.toLocaleString('pt-BR', {
          minimumFractionDigits: currency ? 2 : 0,
          maximumFractionDigits: 2,
          useGrouping: currency,
        });
      }
      validateNumber(input, currency);
    });
  });

  const documentInput = form.querySelector('[data-client-document]');
  function maskDocument() {
    const original = documentInput.value;
    const before = original.slice(0, documentInput.selectionStart ?? original.length).replace(/\D/g, '').length;
    const digits = original.replace(/\D/g, '');
    const mask = digits.length <= 11 ? '000.000.000-00' : '00.000.000/0000-00';
    let result = '';
    let position = 0;
    for (const character of mask) {
      if (position >= digits.length) break;
      result += character === '0' ? digits[position++] : character;
    }
    result += digits.slice(position);
    documentInput.value = result;
    let caret = 0;
    let count = 0;
    while (caret < result.length && count < before) {
      if (/\d/.test(result[caret])) count++;
      caret++;
    }
    if (document.activeElement === documentInput) documentInput.setSelectionRange(caret, caret);
  }
  documentInput.addEventListener('input', maskDocument);
  maskDocument();

  const phone = form.querySelector('[data-client-phone]');

function maskPhone() {
  const digits = phone.value.replace(/\D/g, '');

  // Preserva números maiores, como os que incluem código do país.
  if (digits.length > 11) {
    phone.value = digits;
    return;
  }

  const mask = digits.length <= 10
    ? '(00) 0000-0000'
    : '(00) 00000-0000';

  let formatted = '';
  let position = 0;

  for (const character of mask) {
    if (position >= digits.length) break;

    formatted += character === '0'
      ? digits[position++]
      : character;
  }

  phone.value = formatted;
}

phone.addEventListener('input', maskPhone);
maskPhone();

  form.addEventListener('submit', () => {
    refreshContract();
    form.querySelectorAll('[data-client-money], [data-client-km]').forEach(input => {
      validateNumber(input, input.hasAttribute('data-client-money'));
    });
  });
  refreshContract();
})();
