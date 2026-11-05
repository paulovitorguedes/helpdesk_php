(() => {
  'use strict';
  let active = null;
  function close(restoreFocus = false) {
    if (!active) return;
    const {button, menu} = active;
    menu.hidden = true;
    button.setAttribute('aria-expanded', 'false');
    active = null;
    if (restoreFocus) button.focus();
  }
  function items(menu) {
    return [...menu.querySelectorAll('[role="menuitem"]')].filter(item => !item.disabled);
  }
  function open(button, menu, focusFirst = false) {
    close();
    // Move outside the responsive table so its overflow cannot clip the menu.
    document.body.append(menu);
    menu.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    const rect = button.getBoundingClientRect();
    const width = menu.offsetWidth, height = menu.offsetHeight;
    menu.style.left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8)) + 'px';
    menu.style.top = Math.max(8, rect.bottom + height + 8 <= window.innerHeight ? rect.bottom + 4 : rect.top - height - 4) + 'px';
    active = {button, menu};
    if (focusFirst) items(menu)[0]?.focus();
  }
  document.querySelectorAll('[data-material-actions]').forEach(button => {
    const menu = document.getElementById(button.getAttribute('aria-controls'));
    if (!menu) return;
    button.addEventListener('click', () => {
      if (active?.button === button) close(); else open(button, menu);
    });
    button.addEventListener('keydown', event => {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault(); open(button, menu, true);
        if (event.key === 'ArrowUp') items(menu).at(-1)?.focus();
      }
    });
    menu.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); close(true); return; }
      if (event.key === 'Tab') { close(); return; }
      if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const links = items(menu), index = links.indexOf(document.activeElement);
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? links.length - 1
        : (index + (event.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
      links[next]?.focus();
    });
  });
  document.addEventListener('click', event => {
    if (active && !active.button.contains(event.target) && !active.menu.contains(event.target)) close();
  });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') close(true); });
  document.addEventListener('focusin', event => {
    if (active && !active.button.contains(event.target) && !active.menu.contains(event.target)) close();
  });
  window.addEventListener('resize', () => close());
  document.addEventListener('scroll', () => close(), true);
})();
