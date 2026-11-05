(() => {
  'use strict';
  if (typeof window.fetch !== 'function') return;
  document.querySelectorAll('form[data-material-purchase]').forEach(form => {
    const feedback = form.querySelector('[data-purchase-feedback]');
    const button = form.querySelector('button[type="submit"]');
    if (!feedback || !button) return;
    let pending = false;
    function showMessage(text) {
      feedback.textContent = text;
      feedback.hidden = false;
      feedback.focus();
    }
    form.addEventListener('submit', async event => {
      if (event.defaultPrevented) return;
      event.preventDefault();
      if (pending) return;
      pending = true;
      const wasDisabled = button.disabled;
      button.disabled = true;
      try {
        const response = await fetch(form.getAttribute('action') || window.location.href, {
          method: 'POST', body: new FormData(form),
          headers: { Accept: 'application/json' }, credentials: 'same-origin',
        });
        if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
          showMessage(response.status === 419
            ? 'A sessão do formulário expirou. Os dados foram mantidos; renove a sessão em outra aba antes de tentar novamente.'
            : 'Não foi possível confirmar a compra. Confira a sessão e o histórico antes de tentar novamente. Os dados foram mantidos.');
          return;
        }
        const result = await response.json();
        if (response.ok && result.ok === true) {
          window.location.assign(result.redirect);
          return;
        }
        showMessage(result.message || 'Não foi possível registrar a compra. Ajuste os dados e tente novamente.');
      } catch (error) {
        showMessage('Não foi possível confirmar a compra. Confira a conexão e o histórico antes de tentar novamente. Os dados foram mantidos.');
      } finally {
        pending = false;
        button.disabled = wasDisabled;
      }
    });
  });
})();
