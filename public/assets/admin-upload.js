// The upload forms of the Images list, the Files list and the media library: several
// files at once, chosen or dropped on the form, each sent to the form's address (POST
// /admin/media/upload, /file-upload or /add) asking for JSON, with the form's other
// fields (a file's visibility; since 0.3.2). When all succeed the list is reloaded;
// otherwise the failures are listed. Without JavaScript the form still works: one file,
// and the server redirects back.
(() => {
    'use strict';

    const form = document.querySelector('form[data-media-upload]');
    if (!form) {
        return;
    }
    const input = form.querySelector('input[type="file"]');
    const submit = form.querySelector('[data-upload-submit]');
    const status = form.querySelector('[data-upload-status]');
    const max = Number(form.dataset.uploadMax || 0);

    // A file's roles count only with "some roles": shown only then.
    const roles = form.querySelector('[data-visibility-roles]');
    const showRoles = () => {
        if (roles) {
            roles.classList.toggle('d-none', form.querySelector('input[name="visible_to"]:checked')?.value !== 'roles');
        }
    };
    form.querySelectorAll('input[name="visible_to"]').forEach((radio) => radio.addEventListener('change', showRoles));
    showRoles();

    input.multiple = true;
    input.required = false;
    submit.hidden = true; // choosing (or dropping) files starts the upload

    // One file; resolves to null (uploaded) or the error message.
    const upload = async (file) => {
        if (max > 0 && file.size > max) {
            return form.dataset.uploadTooLarge || 'Too large';
        }
        const body = new FormData(form);
        body.delete('file');
        body.append('file', file, file.name || 'file');
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!(response.headers.get('Content-Type') || '').includes('application/json')) {
                return form.dataset.uploadSession || 'Upload failed';
            }
            const result = await response.json();

            return result && result.success ? null : (result && result.message) || 'Upload failed';
        } catch (e) {
            return String(e && e.message ? e.message : e);
        }
    };

    let busy = false;
    const run = async (fileList) => {
        const files = Array.from(fileList || []).filter((f) => f instanceof File);
        if (busy || files.length === 0) {
            return;
        }
        busy = true;
        input.disabled = true;
        const failed = [];
        for (const [index, file] of files.entries()) {
            status.textContent = (form.dataset.uploading || '{done}/{total}')
                .replace('{done}', String(index + 1))
                .replace('{total}', String(files.length));
            const error = await upload(file);
            if (error) {
                failed.push([file.name, error]);
            }
        }
        if (failed.length === 0) {
            window.location.reload();
            return;
        }

        // Text only (textContent): file names and messages are never treated as HTML.
        const alert = document.createElement('div');
        alert.className = 'alert alert-danger mb-0';
        const title = document.createElement('p');
        title.className = 'mb-1';
        title.textContent = form.dataset.failed || '';
        const list = document.createElement('ul');
        list.className = 'mb-0';
        failed.forEach(([name, error]) => {
            const item = document.createElement('li');
            const strong = document.createElement('strong');
            strong.textContent = name;
            item.append(strong, ': ' + error);
            list.append(item);
        });
        alert.append(title, list);
        if (failed.length < files.length) {
            const reload = document.createElement('button');
            reload.type = 'button';
            reload.className = 'btn btn-sm btn-outline-secondary mt-2';
            reload.textContent = form.dataset.showUploaded || 'Reload';
            reload.addEventListener('click', () => window.location.reload());
            alert.append(reload);
        }
        status.replaceChildren(alert);
        input.value = '';
        input.disabled = false;
        busy = false;
    };

    input.addEventListener('change', () => run(input.files));
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        run(input.files);
    });

    // Dropping files on the form.
    ['dragenter', 'dragover'].forEach((type) => form.addEventListener(type, (event) => {
        if (event.dataTransfer && Array.from(event.dataTransfer.types).includes('Files')) {
            event.preventDefault();
            form.classList.add('is-dragover');
        }
    }));
    form.addEventListener('dragleave', (event) => {
        if (!form.contains(event.relatedTarget)) {
            form.classList.remove('is-dragover');
        }
    });
    form.addEventListener('drop', (event) => {
        event.preventDefault();
        form.classList.remove('is-dragover');
        run(event.dataTransfer ? event.dataTransfer.files : []);
    });
})();
