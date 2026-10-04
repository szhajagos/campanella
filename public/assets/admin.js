// Multi-valued fields in the admin forms: add, remove and reorder values.
// Plain JavaScript, no dependencies. Without JavaScript the existing values
// can still be edited, and a value is removed by clearing it.
//
// Also: once the edit form is changed, the publication panel says that its
// buttons do not save those changes, and those forms ask before submitting.
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
        markUnsaved(button);
    });

    let unsaved = false;
    const markUnsaved = (element) => {
        if (element.closest('form.admin-form')) {
            unsaved = true;
            document.querySelectorAll('[data-unsaved-hint]').forEach((hint) => hint.classList.remove('d-none'));
        }
    };
    // The other forms of the page (publish, unpublish, convert) do not save the edit
    // form's changes: ask before submitting one of them while there are unsaved changes.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (unsaved && form.dataset.confirmUnsaved && !window.confirm(form.dataset.confirmUnsaved)) {
            event.preventDefault();
        }
    });
    // The HTML editor's textarea is updated by the editor itself (also when it starts),
    // so its events do not count; admin-editor.js sends 'admin:edited' on a real edit.
    const fromUser = (event) => !event.target.matches('textarea[data-editor]');
    document.addEventListener('input', (event) => fromUser(event) && markUnsaved(event.target));
    document.addEventListener('change', (event) => fromUser(event) && markUnsaved(event.target));
    document.addEventListener('admin:edited', (event) => markUnsaved(event.target));

    document.querySelectorAll('[data-multi]').forEach(refresh);
})();
