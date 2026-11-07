// The layout editor (since 0.4.10), Appearance → Layouts: rows and columns dragged with
// SortableJS (public/assets/vendor/sortablejs, MIT), each one's place written into its
// `position` field (the server puts them in that order); a row's proportion sends the form to
// show it with as many columns; the sketch at a large and a small width is drawn again as the
// form changes. Everything works without it: the form's buttons do the same, sent.
(() => {
    'use strict';

    const form = document.querySelector('[data-layout-editor]');
    if (!form) {
        return;
    }
    const rowsList = form.querySelector('[data-layout-rows]');
    const sketches = Array.from(document.querySelectorAll('#layout-sketch [data-layout-sketch]'));
    const contentLabel = sketches[0]?.querySelector('.layout-sketch-main')?.textContent || 'content';

    const field = (scope, name) => scope.querySelector('[data-layout-field="' + name + '"]');

    // The form's rows and columns, in the page's order.
    const read = () => Array.from(rowsList.querySelectorAll(':scope > [data-layout-row]')).map((row) => ({
        full: field(row, 'full')?.checked === true,
        columns: Array.from(row.querySelectorAll('[data-layout-columns] > [data-layout-column]')).map((column) => {
            const region = (field(column, 'region')?.value || '').trim();
            const label = (field(column, 'label')?.value || '').trim();
            return {
                main: region === 'content',
                label: label !== '' ? label : (region === 'content' ? contentLabel : region || '?'),
                width: Number(field(column, 'width')?.value || 12),
                order: Number(field(column, 'order')?.value || 0),
                hidden: (field(column, 'look')?.value || '').startsWith('hide:'),
            };
        }),
    }));

    const box = (column, small, hiddenText) => {
        const element = document.createElement('div');
        element.className = 'layout-sketch-box' + (column.main ? ' layout-sketch-main' : '') + (small && column.hidden ? ' layout-sketch-hidden' : '');
        element.textContent = column.label + (small && column.hidden ? ' · ' + hiddenText : '');
        return element;
    };

    // The sketches drawn again: their header and footer bars kept, what is between made anew.
    const draw = () => {
        const rows = read();
        sketches.forEach((sketch) => {
            const bars = sketch.querySelectorAll(':scope > .layout-sketch-bar');
            if (bars.length !== 2) {
                return;
            }
            const hiddenText = sketch.dataset.hiddenText || '';
            while (bars[0].nextSibling && bars[0].nextSibling !== bars[1]) {
                bars[0].nextSibling.remove();
            }
            const parts = document.createDocumentFragment();
            if (sketch.dataset.layoutSketch === 'large') {
                rows.forEach((row) => {
                    const element = document.createElement('div');
                    element.className = 'row g-1 mx-0 layout-sketch-row' + (row.full ? ' layout-sketch-full' : '');
                    row.columns.forEach((column) => {
                        const cell = document.createElement('div');
                        cell.className = 'col-' + Math.max(1, Math.min(12, column.width));
                        cell.appendChild(box(column, false, hiddenText));
                        element.appendChild(cell);
                    });
                    parts.appendChild(element);
                });
            } else {
                rows.forEach((row) => {
                    row.columns.map((column, index) => ({ column, index }))
                        .sort((a, b) => (a.column.order - b.column.order) || (a.index - b.index))
                        .forEach(({ column }) => parts.appendChild(box(column, true, hiddenText)));
                });
            }
            bars[1].before(parts);
        });
    };

    // Each row's and column's place, as dragged.
    const renumber = () => {
        Array.from(rowsList.querySelectorAll(':scope > [data-layout-row]')).forEach((row, i) => {
            row.querySelector(':scope [data-layout-position]').value = String(i);
            Array.from(row.querySelectorAll('[data-layout-columns] > [data-layout-column]')).forEach((column, j) => {
                column.querySelector('[data-layout-position]').value = String(j);
            });
        });
        draw();
    };

    form.addEventListener('input', draw);
    form.addEventListener('change', (event) => {
        if (event.target.matches('[data-layout-preset]') && event.target.value !== '') {
            form.querySelector('[data-layout-refresh]')?.click();
            return;
        }
        draw();
    });

    if (typeof window.Sortable === 'function') {
        form.querySelectorAll('.layout-drag').forEach((handle) => handle.classList.remove('d-none'));
        window.Sortable.create(rowsList, { handle: '.layout-drag-row', draggable: '[data-layout-row]', animation: 150, forceFallback: true, onEnd: renumber });
        rowsList.querySelectorAll('[data-layout-columns]').forEach((columns) => {
            window.Sortable.create(columns, { handle: '.layout-drag-column', draggable: '[data-layout-column]', animation: 150, forceFallback: true, onEnd: renumber });
        });
    }
})();
