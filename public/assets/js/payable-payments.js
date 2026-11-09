(() => {
  'use strict';
  if (typeof window.fetch !== 'function') return;
  document.querySelectorAll('form[data-payable-operation]').forEach(form => {
    const feedback = form.querySelector('[data-payment-feedback]');
    const button = form.querySelector('button[type="submit"]');
    if (!feedback || !button) return;
    let pending = false;
    function show(text) { feedback.textContent = text; feedback.hidden = false; feedback.focus(); }
    form.addEventListener('submit', async event => {
      if (event.defaultPrevented) return;
      event.preventDefault();
      if (pending) return;
      pending = true;
      button.disabled = true;
      try {
        const response = await fetch(form.getAttribute('action') || window.location.href, {
          method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' },
        });
        if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
          show('Não foi possível confirmar a operação. Confira sua sessão e o histórico antes de tentar novamente. Os campos foram mantidos.'); return;
        }
        const result = await response.json();
        if (response.ok && result.ok === true) { window.location.assign(result.redirect); return; }
        show(result.message || 'Não foi possível concluir. Os campos foram mantidos.');
      } catch (error) {
        show('Não foi possível confirmar a operação. Confira a conexão e o histórico antes de reenviar. Os campos foram mantidos.');
      } finally { pending = false; button.disabled = false; }
    });
  });
})();
