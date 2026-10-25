/*
 * A running import's page (since 0.3.1): sends its "Continue" form by itself, so a
 * run goes on step by step while the page is open. Without JavaScript the button
 * does the same by hand.
 */
(function () {
    'use strict';
    var form = document.querySelector('form[data-import-continue]');
    if (!form) {
        return;
    }
    window.setTimeout(function () {
        var button = form.querySelector('button[type=submit]');
        if (button) {
            button.disabled = true;
        }
        form.submit();
    }, 400);
}());
