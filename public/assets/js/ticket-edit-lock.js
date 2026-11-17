(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-ticket-edit-locked]').forEach(form => {
      const editable = new Set(['complainant', 'request_text', 'solution_text']);
      form.querySelectorAll('input, select, textarea, button').forEach(control => {
        if (control.type === 'hidden' || control.type === 'submit' || editable.has(control.name)) return;
        control.disabled = true;
      });
    });
  });
})();
