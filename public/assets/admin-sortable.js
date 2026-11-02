// Dragging in the admin (since 0.4.4), with SortableJS (public/assets/vendor/sortablejs, MIT).
// Each list is an `[data-sortable-region]` inside a `[data-sortable-group]`, and a drop sends
// the group's arrangement with the page's CSRF token to the group's `data-sortable-url`,
// asking for JSON. On an error the page is reloaded: the server has changed nothing and says
// why. The drag handles show only when this runs.
//
// - The components of the Regions page and of a page's own regions, also from one region to
//   another: `order[<region>]` = the IDs, comma-separated. The up and down buttons stay (the
//   keyboard, screen readers, and without JavaScript).
// - A tree (`data-sortable-mode="tree"`, e.g. a menu's items): nested lists, an item dragged
//   under another or out of it, at most `data-sortable-max-depth` levels: `tree` = `id:parent`
//   pairs in tree order (0: a root), with `scope` (e.g. the menu).
(() => {
    'use strict';

    if (typeof window.Sortable !== 'function') {
        return;
    }
    document.querySelectorAll('[data-sortable-group]').forEach((group) => {
        const lists = Array.from(group.querySelectorAll('[data-sortable-region]'));
        const tree = group.dataset.sortableMode === 'tree';
        const maxDepth = Number(group.dataset.sortableMaxDepth || 10);
        // A tree's item: its level (0: a root), and how many levels it brings with it.
        const levelOf = (list) => {
            let level = 0;
            for (let at = list.parentElement?.closest('[data-id]'); at && group.contains(at); at = at.parentElement?.closest('[data-id]')) {
                level += 1;
            }
            return level;
        };
        const heightOf = (item) => {
            let height = 1;
            item.querySelectorAll(':scope > [data-sortable-region] > [data-id]').forEach((child) => {
                height = Math.max(height, 1 + heightOf(child));
            });
            return height;
        };
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
            if (tree) {
                // In the document's order a parent comes before its children.
                const pairs = Array.from(group.querySelectorAll('[data-id]')).map((item) => {
                    const parent = item.parentElement?.closest('[data-id]');
                    return item.dataset.id + ':' + (parent && group.contains(parent) ? parent.dataset.id : '0');
                });
                body.append('tree', pairs.join(','));
                if (group.dataset.sortableScope) {
                    body.append('scope', group.dataset.sortableScope);
                }
            } else {
                lists.forEach((list) => {
                    const ids = Array.from(list.querySelectorAll(':scope > [data-id]')).map((item) => item.dataset.id);
                    body.append('order[' + list.dataset.sortableRegion + ']', ids.join(','));
                });
            }
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
                emptyInsertThreshold: tree ? 6 : 24,
                swapThreshold: tree ? 0.65 : 1,
                // A tree: not deeper than its levels allow.
                onMove: tree ? (event) => levelOf(event.to) + heightOf(event.dragged) <= maxDepth : undefined,
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
