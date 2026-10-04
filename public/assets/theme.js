// Light or dark Bootstrap theme, following the system setting. Loaded in <head>
// (not deferred), so the page never flashes in the wrong theme. A separate file
// instead of an inline script, so a Content-Security-Policy can forbid inline scripts.
document.documentElement.dataset.bsTheme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
