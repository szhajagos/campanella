// The HTML editor of the admin forms: turns every <textarea data-editor> into a
// Jodit editor (MIT edition, shipped in assets/vendor/jodit). Plain JavaScript.
//
// The editor is only a convenience: the server filters every saved HTML text
// with its allowlist anyway. The allowlist is passed here too (data-allow-tags),
// so the editor keeps and offers what the filter keeps, and pasted content is
// cleaned the same way before saving.
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

    document.querySelectorAll('textarea[data-editor]').forEach((area) => {
        const buttons = profiles[area.dataset.editor] || profiles.full;
        let allowTags = false;
        try {
            allowTags = area.dataset.allowTags ? JSON.parse(area.dataset.allowTags) : false;
        } catch (e) {
            allowTags = false;
        }

        const editor = window.Jodit.make(area, {
            language: (root.lang || 'en').slice(0, 2),
            theme: root.dataset.bsTheme === 'dark' ? 'dark' : 'default',
            height: 480,
            minHeight: 240,
            toolbarAdaptive: false,
            toolbarSticky: false,
            buttons,
            // atom: replaces Jodit's default list (which has h1) instead of merging with it.
            controls: { paragraph: { list: window.Jodit.atom(paragraphs) } },

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
        });

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
