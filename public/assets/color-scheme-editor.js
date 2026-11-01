// The colour scheme's form (since 0.4.2): the colour picker beside each colour's text field,
// and the preview, follow what is typed or picked. The text field is what is sent, so the
// form works without this script too; the server checks every colour and the contrast.
(function () {
    var form = document.querySelector('[data-scheme-editor]');
    if (!form) {
        return;
    }
    var valid = function (value) {
        return /^#[0-9a-fA-F]{6}$/.test(value);
    };
    var show = function (field) {
        var preview = form.querySelector('[data-scheme-preview="' + field.dataset.schemeVariant + '"]');
        if (preview && valid(field.value)) {
            preview.style.setProperty('--sp-' + field.dataset.schemeColor, field.value);
        }
    };
    form.querySelectorAll('[data-scheme-color]').forEach(function (field) {
        var picker = form.querySelector('[data-scheme-picker="' + field.id + '"]');
        field.addEventListener('input', function () {
            if (picker && valid(field.value)) {
                picker.value = field.value.toLowerCase();
            }
            show(field);
        });
        if (picker) {
            picker.addEventListener('input', function () {
                field.value = picker.value;
                show(field);
            });
        }
    });
})();
