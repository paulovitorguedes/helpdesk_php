(() => {
  'use strict';
  if (typeof window.fetch !== 'function') return;
  const pending = new WeakSet();
  document.querySelectorAll('[data-invoice-upload]').forEach(form => {
    const feedback = form.querySelector('[data-invoice-feedback]');
    const button = form.querySelector('button[type="submit"]');
    function show(message) { feedback.textContent = message; feedback.hidden = false; }
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (pending.has(form)) return;
      const file = form.querySelector('input[type="file"]').files[0];
      if (!file) { show('Selecione um PDF.'); return; }
      if (file.size > 10485760) { show('O PDF deve ter no máximo 10 MB.'); return; }
      pending.add(form); button.disabled = true;
      try {
        const response = await fetch(form.getAttribute('action'), {method:'POST', body:new FormData(form), credentials:'same-origin', headers:{Accept:'application/json'}});
        if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
          show('Não foi possível confirmar o envio. Confira a sessão e o histórico antes de repetir.'); return;
        }
        const result = await response.json();
        if (response.ok && result.ok === true) { window.location.assign(result.redirect); return; }
        show(result.message || 'Não foi possível anexar o PDF.');
      } catch (error) {
        show('Não foi possível confirmar o envio. Confira a conexão e o histórico antes de repetir.');
      } finally { pending.delete(form); button.disabled = false; }
    });
  });
})();
