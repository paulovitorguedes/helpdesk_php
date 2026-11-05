(() => {
  'use strict';
  const feedback = document.getElementById('material-delete-feedback');
  if (!feedback || typeof window.fetch !== 'function') return;
  const message = feedback.querySelector('[data-delete-message]');
  const pending = new WeakSet();
  feedback.querySelector('[data-delete-dismiss]').addEventListener('click', () => {
    feedback.hidden = true;
  });
  function showMessage(text) {
    message.textContent = text;
    feedback.hidden = false;
  }
  document.querySelectorAll('form[data-material-delete]').forEach(form => {
    form.addEventListener('submit', async event => {
      // A confirmacao existente pode ter cancelado o envio.
      if (event.defaultPrevented) return;
      event.preventDefault();
      if (pending.has(form)) return;
      pending.add(form);
      const button = form.querySelector('button[type="submit"]');
      const wasDisabled = button.disabled;
      button.disabled = true;
      try {
        const response = await fetch(form.getAttribute('action') || window.location.href, {
          method: 'POST',
          body: new FormData(form),
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });
        if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
          showMessage('Não foi possível confirmar a exclusão. Sua sessão pode ter expirado. O formulário foi mantido.');
          return;
        }
        const result = await response.json();
        if (response.ok && result.ok === true) {
          window.location.assign(result.redirect);
          return;
        }
        showMessage(result.message || 'Não foi possível excluir o material. O formulário foi mantido.');
      } catch (error) {
        showMessage('Não foi possível confirmar a exclusão. Confira a conexão e a listagem antes de tentar novamente. O formulário foi mantido.');
      } finally {
        pending.delete(form);
        button.disabled = wasDisabled;
      }
    });
  });
})();
