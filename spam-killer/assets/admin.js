(() => {
  'use strict';
  const list = document.getElementById('sk-conditions');
  const template = document.getElementById('sk-condition-template');
  const add = document.getElementById('sk-add-condition');
  if (!list || !template || !add) return;

  let next = list.children.length;
  add.addEventListener('click', () => {
    const html = template.innerHTML.replace(/__i__/g, String(next++));
    list.insertAdjacentHTML('beforeend', html);
    list.lastElementChild.querySelector('input').focus();
  });

  list.addEventListener('click', (event) => {
    if (!event.target.classList.contains('sk-remove')) return;
    if (list.children.length > 1) event.target.closest('.sk-condition').remove();
  });

  // "is empty" needs no value.
  const sync = (row) => {
    const operator = row.querySelector('select[name$="[operator]"]');
    const value = row.querySelector('input[type="text"]');
    value.hidden = operator.value === 'is_empty';
  };
  list.addEventListener('change', (event) => {
    const row = event.target.closest('.sk-condition');
    if (row) sync(row);
  });
  list.querySelectorAll('.sk-condition').forEach(sync);
})();
