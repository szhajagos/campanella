// Dragging in the admin (since 0.4.4), with SortableJS (public/assets/vendor/sortablejs, MIT):
// the components of the Regions page and of a page's own regions, also from one region to
// another. Each list is an `[data-sortable-region]` inside a `[data-sortable-group]`; a drop
// sends the order of every list of the group (`order[<region>]` = IDs, comma-separated) with
// the page's CSRF token to the group's `data-sortable-url`, asking for JSON. On an error the
// page is reloaded: the server has changed nothing and says why. The up and down buttons stay
// (the keyboard, screen readers, and without JavaScript); the drag handles show only here.
(() => {
    'use strict';

    if (typeof window.Sortable !== 'function') {
        return;
    }
    document.querySelectorAll('[data-sortable-group]').forEach((group) => {
        const lists = Array.from(group.querySelectorAll('[data-sortable-region]'));
        const status = group.querySelector('[data-sortable-status]');
        const token = document.querySelector('input[name="_csrf"]')?.value || '';
        let busy = false;

        const say = (text, failed = false) => {
            if (status) {
                status.textContent = text;
                status.classList.toggle('text-danger', failed);
                status.classList.toggle('text-success', !failed);
            }
        };

        // After a drop: the empty notes, the counts, and the first and last buttons.
        const refresh = () => {
            lists.forEach((list) => {
                const items = Array.from(list.querySelectorAll(':scope > [data-id]'));
                const section = list.closest('[data-sortable-section]');
                const empty = section?.querySelector('[data-sortable-empty]');
                if (empty) {
                    empty.hidden = items.length > 0;
                }
                const count = section?.querySelector('[data-sortable-count]');
                if (count && count.dataset.sortableCount) {
                    count.textContent = count.dataset.sortableCount.replace('#', String(items.length));
                }
                items.forEach((item, at) => {
                    item.querySelector('[data-move="up"]')?.toggleAttribute('disabled', at === 0);
                    item.querySelector('[data-move="down"]')?.toggleAttribute('disabled', at === items.length - 1);
                });
            });
        };

        const save = async () => {
            if (busy) {
                return;
            }
            busy = true;
            const body = new FormData();
            body.append('_csrf', token);
            if (group.dataset.sortableLayout) {
                body.append('layout', group.dataset.sortableLayout);
            }
            lists.forEach((list) => {
                const ids = Array.from(list.querySelectorAll(':scope > [data-id]')).map((item) => item.dataset.id);
                body.append('order[' + list.dataset.sortableRegion + ']', ids.join(','));
            });
            say(group.dataset.sortableSaving || '…');
            try {
                const response = await fetch(group.dataset.sortableUrl, {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || data.ok !== true) {
                    throw new Error('refused');
                }
                say(group.dataset.sortableSaved || 'Saved.');
            } catch (e) {
                say(group.dataset.sortableFailed || 'Not saved.', true);
                window.location.reload();
            } finally {
                busy = false;
            }
        };

        lists.forEach((list) => {
            list.querySelectorAll('[data-drag-handle]').forEach((handle) => {
                handle.hidden = false;
            });
            window.Sortable.create(list, {
                group: group.dataset.sortableGroup,
                handle: '[data-drag-handle]',
                draggable: '[data-id]',
                animation: 150,
                ghostClass: 'admin-sortable-ghost',
                chosenClass: 'admin-sortable-chosen',
                emptyInsertThreshold: 24,
                // Pointer events instead of the browser's own drag and drop: the same with a
                // mouse, a pen or a finger, and scrolling the page while dragging.
                forceFallback: true,
                fallbackOnBody: true,
                scroll: true,
                onEnd: (event) => {
                    refresh();
                    if (event.from !== event.to || event.oldIndex !== event.newIndex) {
                        save();
                    }
                },
            });
        });
    });
})();
