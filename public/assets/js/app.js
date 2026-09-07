(() => {
  const root=document.documentElement, btn=document.getElementById('themeToggle');
  const saved=localStorage.getItem('gmb-theme'); if(saved) root.setAttribute('data-bs-theme',saved);
  btn?.addEventListener('click',()=>{const n=root.getAttribute('data-bs-theme')==='dark'?'light':'dark';root.setAttribute('data-bs-theme',n);localStorage.setItem('gmb-theme',n)});
  document.querySelectorAll('[data-confirm]').forEach(el=>el.addEventListener('click',e=>{if(!confirm(el.dataset.confirm||'Confirmar?'))e.preventDefault()}));
  document.querySelectorAll('[data-search-table]').forEach(inp=>inp.addEventListener('input',()=>{const q=inp.value.toLowerCase();document.querySelectorAll(inp.dataset.searchTable+' tbody tr').forEach(tr=>tr.hidden=!tr.innerText.toLowerCase().includes(q))}));
})();
