// The HTML editor of the admin forms: turns every <textarea data-editor> into a
// Jodit editor (MIT edition, shipped in assets/vendor/jodit). Plain JavaScript.
//
// The editor is only a convenience: the server filters every saved HTML text
// with its allowlist anyway. The allowlist is passed here too (data-allow-tags),
// so the editor keeps and offers what the filter keeps, and pasted content is
// cleaned the same way before saving.
//
// Images: if the form carries an upload address (data-upload-url: the user may
// upload images), the "full" toolbar gets an image button, and pasted or dropped
// images are uploaded too, all to that address (POST /admin/media/upload). An
// image is never embedded in the text as data (base64).
//
// The image picker (since 0.2.7): if the form carries a browse address (data-browse-url),
// the "full" toolbar gets a button that lists the uploaded images (GET
// /admin/media/browse, JSON), with a search by title and pages; a click inserts the
// image with its alternative text. The server adds the smaller copies (srcset) when
// the text is shown.
//
// Switching to another editor (e.g. SunEditor) means replacing this file and
// the html widget template; nothing else depends on Jodit.
(() => {
    'use strict';

    if (typeof window.Jodit === 'undefined') {
        return; // the textarea stays usable without the editor
    }

    // Toolbar profiles, chosen per field in the Blueprint: 'editor' => ['body' => 'full'].
    const profiles = {
        full: [
            'paragraph', '|', 'bold', 'italic', 'strikethrough', 'subscript', 'superscript', '|',
            'ul', 'ol', '|', 'link', 'table', 'hr', '|', 'eraser', '|', 'undo', 'redo', '|', 'source', 'fullsize',
        ],
        basic: ['bold', 'italic', '|', 'ul', 'ol', '|', 'link', '|', 'eraser', '|', 'undo', 'redo'],
    };

    // Block formats offered (the filter keeps h2–h4; h1 is the page title).
    const paragraphs = {
        p: 'Normal',
        h2: 'Heading 2',
        h3: 'Heading 3',
        h4: 'Heading 4',
        blockquote: 'Quote',
        pre: 'Code',
    };

    const root = document.documentElement;

    // Sends one file to the server. Resolves to {url} or {error: message}.
    const uploadFile = async (form, file) => {
        const max = Number(form.dataset.uploadMax || 0);
        if (max > 0 && file.size > max) {
            return { error: form.dataset.uploadTooLarge || 'Too large' };
        }
        const body = new FormData();
        body.append('_csrf', form.querySelector('input[name="_csrf"]')?.value || '');
        body.append('file', file, file.name || 'image');
        try {
            const response = await fetch(form.dataset.uploadUrl, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            // Not JSON: e.g. the login page after the session expired.
            if (!(response.headers.get('Content-Type') || '').includes('application/json')) {
                return { error: form.dataset.uploadSession || 'Upload failed' };
            }
            const result = await response.json();

            return result && result.success ? { url: result.url } : { error: (result && result.message) || 'Upload failed' };
        } catch (e) {
            return { error: String(e && e.message ? e.message : e) };
        }
    };

    // The editor's upload function (Jodit's customUploadFunction): uploads every file,
    // inserts those that succeeded, and reports the others.
    const uploadTo = (form, getEditor) => async (formData) => {
        const files = formData.getAll('file').filter((f) => f instanceof File);
        const urls = [];
        const errors = [];
        for (const file of files) {
            const result = await uploadFile(form, file);
            if (result.url) {
                urls.push(result.url);
            } else {
                errors.push(result.error);
            }
        }
        const time = new Date().toISOString();
        if (urls.length === 0) {
            return { success: false, time, data: { messages: errors, files: [], baseurl: '' } };
        }
        if (errors.length > 0) {
            getEditor().message.error(errors.join(' '), 8000);
        }

        return { success: true, time, data: { files: urls, isImages: urls.map(() => true), baseurl: '', messages: [] } };
    };

    // An image embedded as data (pasted HTML, e.g. from another editor) or from another
    // site: the data is uploaded like a file; an external image is removed at once (the
    // server would remove it on save anyway), so what the editor shows is what is saved.
    const checkImages = (editor, area, form, canUpload) => {
        const externalAllowed = area.dataset.externalImages === '1';
        editor.editor.querySelectorAll('img').forEach((img) => {
            const src = img.getAttribute('src') || '';
            if (img.dataset.campanellaUploading) {
                return;
            }
            if (/^data:image\//i.test(src) && canUpload) {
                img.dataset.campanellaUploading = '1';
                const [meta, data] = src.split(',', 2);
                let file;
                try {
                    const bytes = Uint8Array.from(atob(data || ''), (c) => c.charCodeAt(0));
                    file = new File([bytes], 'image.' + (meta.match(/image\/(\w+)/i) || [0, 'png'])[1], { type: meta.slice(5).split(';')[0] });
                } catch (e) {
                    img.remove();
                    return;
                }
                uploadFile(form, file).then((result) => {
                    if (result.url) {
                        img.setAttribute('src', result.url);
                        delete img.dataset.campanellaUploading;
                    } else {
                        img.remove();
                        editor.message.error(result.error, 8000);
                    }
                    editor.synchronizeValues();
                });
                return;
            }
            const external = /^(data:|blob:)/i.test(src)
                || (!externalAllowed && /^([a-z][a-z0-9+.-]*:|\/\/|\/\\)/i.test(src.trim())
                    && new URL(src, window.location.href).origin !== window.location.origin);
            if (external) {
                img.remove();
                editor.message.error(area.dataset.externalRemoved || 'Image removed', 8000);
                editor.synchronizeValues();
            }
        });
    };

    // Builds an element with a class and a text (never HTML from the server).
    const el = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    };

    // The image picker: a dialog of the uploaded images; a click inserts one.
    const openPicker = (editor, form) => {
        let labels = {};
        try {
            labels = JSON.parse(form.dataset.browseLabels || '{}');
        } catch (e) {
            labels = {};
        }
        editor.s.save();
        const dialog = editor.dlg({ closeOnClickOverlay: true, closeOnEsc: true });
        const box = el('div', 'campanella-picker');
        const search = el('input', 'form-control form-control-sm mb-3');
        search.type = 'search';
        search.placeholder = labels.search || 'Search';
        search.setAttribute('aria-label', labels.search || 'Search');
        const grid = el('div', 'campanella-picker__grid');
        grid.setAttribute('role', 'list');
        const status = el('p', 'campanella-picker__status text-body-secondary small mb-0');
        status.setAttribute('role', 'status');
        const pager = el('div', 'campanella-picker__pager d-flex align-items-center gap-2 mt-3');
        const previous = el('button', 'btn btn-sm btn-outline-secondary', labels.previous || '‹');
        const next = el('button', 'btn btn-sm btn-outline-secondary', labels.next || '›');
        const where = el('span', 'small text-body-secondary');
        previous.type = 'button';
        next.type = 'button';
        pager.append(previous, where, next);
        box.append(search, grid, status, pager);

        let page = 1;
        let pages = 1;
        let loading = 0;
        const insert = (item) => {
            dialog.close();
            editor.s.restore();
            const img = editor.createInside.element('img');
            img.setAttribute('src', item.url);
            img.setAttribute('alt', item.alt || '');
            editor.s.insertImage(img, null, null);
            editor.synchronizeValues();
        };
        const load = async () => {
            const ticket = ++loading;
            const url = new URL(form.dataset.browseUrl, window.location.href);
            url.searchParams.set('page', String(page));
            if (search.value.trim() !== '') {
                url.searchParams.set('q', search.value.trim());
            }
            let result = null;
            try {
                const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (!(response.headers.get('Content-Type') || '').includes('application/json')) {
                    throw new Error(labels.session || 'Error');
                }
                result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(response.status === 401 ? (labels.session || 'Error') : (labels.error || 'Error'));
                }
            } catch (e) {
                if (ticket === loading) {
                    grid.replaceChildren();
                    status.textContent = String(e && e.message ? e.message : e);
                }
                return;
            }
            if (ticket !== loading) {
                return; // an older answer, after a newer request
            }
            pages = result.pages;
            grid.replaceChildren(...result.items.map((item) => {
                const button = el('button', 'campanella-picker__item');
                button.type = 'button';
                button.setAttribute('role', 'listitem');
                button.title = item.title;
                const thumb = el('img');
                thumb.src = item.thumb;
                thumb.alt = '';
                thumb.loading = 'lazy';
                button.append(thumb, el('span', 'campanella-picker__title', item.title));
                button.addEventListener('click', () => insert(item));
                return button;
            }));
            status.textContent = result.items.length === 0 ? (labels.none || '') : '';
            where.textContent = (labels.page || '{page} / {pages}').replace('{page}', String(page)).replace('{pages}', String(pages));
            previous.disabled = page <= 1;
            next.disabled = page >= pages;
            pager.classList.toggle('d-none', pages <= 1);
        };
        let typing = 0;
        search.addEventListener('input', () => {
            window.clearTimeout(typing);
            typing = window.setTimeout(() => {
                page = 1;
                load();
            }, 300);
        });
        previous.addEventListener('click', () => {
            if (page > 1) {
                page--;
                load();
            }
        });
        next.addEventListener('click', () => {
            if (page < pages) {
                page++;
                load();
            }
        });

        dialog.setHeader(labels.title || 'Images');
        dialog.setContent(box);
        dialog.setSize(Math.min(760, window.innerWidth - 32), Math.min(620, window.innerHeight - 64));
        dialog.open();
        load();
        window.setTimeout(() => search.focus(), 50);
    };

    document.querySelectorAll('textarea[data-editor]').forEach((area) => {
        const form = area.form;
        const canUpload = Boolean(form && form.dataset.uploadUrl);
        const canBrowse = Boolean(form && form.dataset.browseUrl);
        let buttons = profiles[area.dataset.editor] || profiles.full;
        if ((canUpload || canBrowse) && buttons === profiles.full) {
            // The image buttons come after the link button: uploading, and the picker.
            const images = [...(canUpload ? ['image'] : []), ...(canBrowse ? ['campanellaImages'] : [])];
            buttons = buttons.flatMap((b) => (b === 'link' ? ['link', ...images] : [b]));
        }
        let pickerLabel = 'Images';
        try {
            pickerLabel = JSON.parse(form && form.dataset.browseLabels ? form.dataset.browseLabels : '{}').button || pickerLabel;
        } catch (e) {
            pickerLabel = 'Images';
        }
        let allowTags = false;
        try {
            allowTags = area.dataset.allowTags ? JSON.parse(area.dataset.allowTags) : false;
        } catch (e) {
            allowTags = false;
        }

        let editor = null;
        editor = window.Jodit.make(area, {
            language: (root.lang || 'en').slice(0, 2),
            theme: root.dataset.bsTheme === 'dark' ? 'dark' : 'default',
            height: 480,
            minHeight: 240,
            toolbarAdaptive: false,
            toolbarSticky: false,
            buttons,
            // atom: replaces Jodit's default list (which has h1) instead of merging with it.
            controls: {
                paragraph: { list: window.Jodit.atom(paragraphs) },
                // The image picker (since 0.2.7).
                campanellaImages: { icon: 'folder', tooltip: pickerLabel, exec: (jodit) => openPicker(jodit, form) },
            },

            // Nothing is loaded from other servers: the HTML view is a plain textarea
            // (not Ace from a CDN), and the HTML is not beautified (js-beautify from a CDN).
            sourceEditor: 'area',
            beautifyHTML: false,
            disablePlugins: [
                'ai-assistant', 'speech-recognize', 'powered-by-jodit', 'about',
                'iframe', 'video', 'media', 'file', 'print', 'preview',
            ],

            // Pasted content is cleaned to the allowlist without asking.
            askBeforePasteHTML: false,
            askBeforePasteFromWord: false,
            defaultActionOnPaste: 'insert_clear_html',
            cleanHTML: { allowTags, removeEmptyElements: true },

            // Links get only an address and a text: the filter removes class, target and
            // nofollow anyway (rel="noopener noreferrer" is added on save).
            link: { noFollowCheckbox: false, openInNewTabCheckbox: false, modeClassName: false },

            showCharsCounter: false,
            showWordsCounter: false,
            showXPathInStatusbar: false,

            // Images: uploaded to our own address (no file browser, no base64 data in the
            // text), inserted at their own size (the site's CSS keeps them within the column).
            imageDefaultWidth: null,
            filebrowser: { ajax: { url: '' } },
            // Without upload permission a dropped file is refused here (the browser would
            // otherwise open it in place of the page, losing the form).
            enableDragAndDropFileToEditor: canUpload,
            uploader: canUpload ? {
                url: form.dataset.uploadUrl,
                insertImageAsBase64URI: false,
                imagesExtensions: ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                filesVariableName: () => 'file',
                customUploadFunction: uploadTo(form, () => editor),
                isSuccess: (resp) => resp.success,
                getMessage: (resp) => (resp.data && resp.data.messages ? resp.data.messages.join(' ') : ''),
                process: (resp) => resp.data,
            } : { insertImageAsBase64URI: false, url: '' },
        });

        // Images pasted as HTML (data or external), and any that are already in the text.
        let checking = 0;
        editor.events.on('afterPaste change', () => {
            window.clearTimeout(checking);
            checking = window.setTimeout(() => checkImages(editor, area, form, canUpload), 50);
        });
        checkImages(editor, area, form, canUpload);

        // Changes in the editor count as changes of the form (admin.js shows the
        // "unsaved changes" hint next to the publication buttons). The editor also
        // reports a change when it starts (normalising the HTML), so only a value
        // different from the one it started with counts. A custom event, because
        // admin.js ignores the textarea's own input/change events (the editor fires them).
        let initial = null;
        window.requestAnimationFrame(() => {
            initial = editor.value;
        });
        editor.events.on('change', () => {
            if (initial !== null && editor.value !== initial) {
                area.dispatchEvent(new CustomEvent('admin:edited', { bubbles: true }));
            }
        });
    });
})();
