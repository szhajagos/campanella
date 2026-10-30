// The colour mode switch (since 0.3.6): on top of its form, the mode changes at once,
// without loading the page again. The form still saves it (the cookie, POST /color-mode),
// so the next page comes in that mode from the server. Without JavaScript the form works
// alone. A file, not an inline script, so the Content-Security-Policy forbids inline ones.
(function () {
    if (window.campanellaColorMode) {
        return;
    }
    window.campanellaColorMode = true;
    var system = function () {
        return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    };
    var show = function (mode) {
        document.documentElement.dataset.bsTheme = mode === 'auto' ? system() : mode;
        document.querySelectorAll('[data-color-mode-switch] button[name=mode]').forEach(function (button) {
            var on = button.value === mode;
            button.classList.toggle('active', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    };
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-color-mode-switch')) {
            return;
        }
        var button = event.submitter;
        if (!button || button.name !== 'mode') {
            return;
        }
        event.preventDefault();
        var data = new FormData(form);
        data.set('mode', button.value);
        show(button.value);
        fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin', redirect: 'manual'}).catch(function () {});
    });
})();
