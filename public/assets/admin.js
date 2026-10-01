// Multi-valued fields in the admin forms: add, remove and reorder values.
// Plain JavaScript, no dependencies. Without JavaScript the existing values
// can still be edited, and a value is removed by clearing it.
(() => {
    'use strict';

    const refresh = (multi) => {
        const max = Number(multi.dataset.multiMax || 0);
        const rows = multi.querySelectorAll('[data-multi-rows] > [data-multi-row]').length;
        const add = multi.querySelector('[data-multi-add]');
        if (add) {
            add.disabled = max > 0 && rows >= max;
        }
    };

    let counter = 0;

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-multi-add], [data-multi-remove], [data-multi-up], [data-multi-down]');
        if (!button) {
            return;
        }
        const multi = button.closest('[data-multi]');
        const rows = multi.querySelector('[data-multi-rows]');
        const row = button.closest('[data-multi-row]');

        if (button.matches('[data-multi-add]')) {
            const fragment = multi.querySelector('template[data-multi-template]').content.cloneNode(true);
            counter += 1;
            fragment.querySelectorAll('[id]').forEach((el) => { el.id = `${el.id}-${counter}`; });
            rows.appendChild(fragment);
            const inputs = rows.querySelectorAll('[data-multi-row]:last-child input, [data-multi-row]:last-child textarea');
            if (inputs.length) {
                inputs[inputs.length - 1].focus();
            }
        } else if (button.matches('[data-multi-remove]')) {
            row.remove();
        } else if (button.matches('[data-multi-up]') && row.previousElementSibling) {
            rows.insertBefore(row, row.previousElementSibling);
        } else if (button.matches('[data-multi-down]') && row.nextElementSibling) {
            rows.insertBefore(row.nextElementSibling, row);
        }
        refresh(multi);
    });

    document.querySelectorAll('[data-multi]').forEach(refresh);
})();
