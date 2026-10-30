// Light or dark Bootstrap theme, following the system setting. Loaded in <head>
// (not deferred), so the page never flashes in the wrong theme. A separate file
// instead of an inline script, so a Content-Security-Policy can forbid inline scripts.
// Since 0.3.6 only if the page has no mode yet: a fixed one (the site's colour mode, or the
// visitor's own choice) is set by the server, in <html data-bs-theme="…">.
if (!document.documentElement.dataset.bsTheme) {
    document.documentElement.dataset.bsTheme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}
