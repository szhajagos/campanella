# Accessibility

Campanella's own pages aim at **WCAG 2.2 level AA**. A full review, with
assistive technologies, is planned for 1.0 ([ROADMAP](../ROADMAP.md)); until
then each milestone checks what it added, by hand. No automatic checker is used:
the common ones are not under an MIT-compatible licence.

## Review of the site's new parts, 0.4.0 (2026-10-31)

What 0.3.2–0.3.7 added to the site's pages (the layouts and their regions, the
components and displays, the pages assembled from components, the colour
schemes, the colour mode switch) was checked by hand, in both colour modes and
with the keyboard only. Tests keep the fixes (`Security review (0.4.0)`).

**Fixed:**

- **A skip link:** the first thing on every page is *Skip to the content*,
  seen when it has the keyboard's focus, leading to `<main id="main">`.
- **No navigation landmark around the menus:** the layouts' header was a
  `<nav>` holding the site's name, the search and the buttons, and the main
  menu's own `<nav>` inside it; now the header is a `<header>`, and each menu
  is the only navigation landmark of its own (with its name as its label).
- **Headings in order:** the cards' titles were `h2` under the list's own `h2`
  heading; under a heading they are `h3` now. The teaser display has a
  `heading` setting (2–4) for the same in a template or a component.
- **The focus ring** is in the scheme's accent and stronger, instead of
  Bootstrap's pale blue that hardly showed on the warm backgrounds.

**Checked and found right:**

| What | How |
|---|---|
| The page's landmarks | `header`, `main`, `aside` (the sidebar, labelled), `footer`; the search is `role="search"`, with a label for screen readers |
| A region collapsed on small screens | Its button says which region it opens (`aria-label`), `aria-controls`, `aria-expanded` |
| The colour mode switch | A group with a label; each button says its mode (a hidden label, or a visible one) and whether it is the current one (`aria-pressed`); a form, so it works without JavaScript; the icons have 3:1 against the background |
| The colour schemes | The text, the muted text, the links and the buttons' labels have 4.5:1 in both variants, checked when a scheme is read; the core's schemes also have 4.5:1 for the muted text and the links on the surface (boxes) |
| The menus | The current page is `aria-current="page"`; a dropdown is a button with `aria-expanded` (Bootstrap's) |
| The breadcrumbs | A labelled `nav`, the current page `aria-current="page"` |
| The login form display | Each field has its label and `autocomplete` |
| Language | `<html lang>` is the interface's language |

**Accepted for now:**

- An info box is an `<aside>`; in the sidebar it is one inside another. It is
  allowed, only a little more for a screen reader to announce.
- A theme's own colour scheme is checked for the pairs above, not for the muted
  text on the surface: a theme maker should keep 4.5:1 there too (the rule may
  become stricter in a later milestone, with a warning first).
- The admin is checked with the site's 1.0 review; its forms have labels and
  error messages tied to their fields since 0.1.0.
