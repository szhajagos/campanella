# 31. Appearance: colour schemes and the colour mode

*Since 0.3.6.* The site's **colour scheme** is its colours in a light and a dark
variant, chosen among the schemes of the core and the theme. The **colour mode**
says which variant a visitor sees: the visitor's system setting (`auto`, the
default), or always `light` or `dark`; a visitor's own choice (a cookie) comes
first. The admin follows the colour mode too, but never the scheme: it stays
neutral.

| Class | | |
|---|---|---|
| `ColorScheme` | **Public** | A scheme: its name, label, light and dark colours; its CSS |
| `ColorSchemeRegistry` | **Public** | The schemes there are, from the core's and the theme's `scheme/` folders |
| `Appearance` | **Public** | The site's scheme and colour mode, from the settings; the mode of a request |
| `AppearanceTwigExtension` | Internal | `color_mode()` and `color_scheme_url()` in templates |
| `AppearanceController` | Internal | The scheme as CSS, at `/color-scheme.css`; the visitor's mode, `POST /color-mode` |
| `ColorSchemePages` | Internal | The admin's *Appearance → Colour schemes* page (`Campanella\Admin`) |

All are in `Campanella\Appearance`, except `ColorSchemePages`.

## A scheme: a JSON file

A scheme is a JSON file in `templates/scheme/` (the core's: `default`, `forest`,
`sea`) or in a theme's `templates/scheme/`. A theme's scheme of a core one's
name takes its place.

```json
{
    "version": 1,
    "label": "scheme.forest",
    "light": {
        "accent": "#2f6b3a", "accent_hover": "#24532d", "on_accent": "#ffffff",
        "background": "#f8faf6", "text": "#1b1f1b", "muted": "#5b665c",
        "border": "#dfe5dc", "surface": "#eef3eb"
    },
    "dark": { "…": "the same eight colours" }
}
```

| Colour | What it is for | CSS variable |
|---|---|---|
| `accent` | Links, buttons, the current menu item | `--cc-accent`, `--cc-accent-rgb` |
| `accent_hover` | The accent under the pointer | `--cc-accent-hover` |
| `on_accent` | Text on the accent (a button's label) | `--cc-on-accent` |
| `background` | The page | `--bs-body-bg` |
| `text` | The text | `--bs-body-color` |
| `muted` | Secondary text (dates, help) | `--bs-secondary-color` |
| `border` | Lines and borders | `--bs-border-color` |
| `surface` | Boxes on the page (an info box, a card) | `--bs-tertiary-bg` |

Also set from them: `--bs-body-bg-rgb`, `--bs-body-color-rgb` and
`--bs-tertiary-bg-rgb` (Bootstrap's `bg-body`, `bg-body-tertiary`), and the
primary's subtle shades: `--bs-primary-bg-subtle` (12 % of the accent on the
background, `ColorScheme::SUBTLE`), `--bs-primary-border-subtle` (35 %,
`ColorScheme::SUBTLE_BORDER`) and `--bs-primary-text-emphasis` (`accent_hover`),
which the info box's *primary* style uses. `ColorScheme::mix()` mixes two colours.

`label` is a message key or a text. Every colour is `#rrggbb`; nothing else is
taken, so a scheme cannot put any other CSS into the page.

**A scheme must be readable.** In both variants these pairs need a contrast of
at least 4.5:1 (WCAG 2.2 AA, `ColorScheme::MIN_CONTRAST`): the text, the muted
text and the accent on the background, the text on the surface and on the
accent's subtle shade, and `on_accent`
on the accent and on `accent_hover` (`ColorScheme::READABLE`). A scheme that is
not valid or not readable is left out; `ColorSchemeRegistry::errors()` says why
(e.g. *forest: light.muted on light.background is hard to read (contrast
3.10:1, at least 4.5:1 is needed)*). A theme's broken scheme does not hide the
core's of its name.

`ColorScheme::fromJson(string $name, string $json, string $origin = 'core')`
reads one (`\InvalidArgumentException` if it is not valid);
`ColorScheme::contrast(string $a, string $b)` is the contrast ratio of two
colours (1 to 21), `ColorScheme::luminance()` a colour's relative luminance,
`ColorScheme::rgb()` its channels. `ColorScheme::css()` is the scheme as CSS:
the variables above for `:root, [data-bs-theme="light"]` and for
`[data-bs-theme="dark"]`.

## ColorSchemeRegistry

| Method | |
|---|---|
| `all(): array` | name => `ColorScheme`, the core's first |
| `has(string $name): bool` | |
| `get(string $name): ColorScheme` | The scheme, or `default` for an unknown name |
| `errors(): array` | The schemes left out: name => why |

`ColorSchemeRegistry::DEFAULT` is `default`: the colours `campanella.css` has
had since 0.0.4, so a site looks the same after the upgrade to 0.3.6.

## Appearance

The settings (saved on *Appearance → Colour schemes*):

| Setting | |
|---|---|
| `appearance.scheme` | The scheme's name (not stored: `default`) |
| `appearance.color_mode` | `light` or `dark` (not stored: `auto`, the visitor's system setting) |

| Method | |
|---|---|
| `scheme(): ColorScheme` | The site's scheme (the default one if the saved one is no longer there) |
| `missingScheme(): ?string` | The saved scheme's name, if it is no longer there (e.g. after changing the theme) |
| `siteMode(): string` | The site's mode: `auto`, `light` or `dark` |
| `mode(Request $request): string` | The request's mode: the visitor's own (the `campanella_color_mode` cookie, `Appearance::COOKIE`), or the site's |
| `theme(Request $request): string` | `light` or `dark` for `<html data-bs-theme>`, or `''` for `auto` |
| `css(): string` | The site's scheme as CSS |
| `version(): string` | The CSS's version (a hash of it), for its address |
| `save(?string $scheme, ?string $mode): void` | Saves them (null keeps one); `ValidationException` with `scheme` or `color_mode` for an unknown one, and nothing is saved |
| `registry(): ColorSchemeRegistry` | |

### In the admin: ColorSchemePages

*Appearance → Colour schemes* (`/admin/system/color-schemes`, for the system
roles, like the layouts): the schemes, each with a preview of its light and dark
variant in its own colours, the site's chosen; the colour mode; the schemes left
out, with the reason; a warning if the saved scheme is gone (e.g. after changing
the theme: the site uses the default one meanwhile). Saving is
`Appearance::save()`'s: all or nothing, with the problems at their fields.

## In templates

| Function | |
|---|---|
| `color_mode()` | `light` or `dark`, or `''` to follow the system |
| `color_scheme_url()` | The scheme's CSS: `/color-scheme.css?v=<version>` |

The core's `base.html.twig`:

```twig
{% set mode = color_mode() %}
<html lang="{{ locale() }}"{% if mode %} data-bs-theme="{{ mode }}"{% endif %}>
…
    <script src="{{ asset('theme.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('campanella.css') }}">
    <link rel="stylesheet" href="{{ color_scheme_url() }}">
```

The mode is set by the server, so a page never shows in the wrong mode first;
`theme.js` only sets it when the page has none (`auto`), from
`prefers-color-scheme`. The CSS is a file, not an inline style, so the
Content-Security-Policy stays `style-src 'self'`. With its version it is cached
for a year (`immutable`), without it (or with an old one) for five minutes.

`admin/base.html.twig` uses `color_mode()` only.

## The colour mode switch

`color_mode_switch` (`Campanella\Display\Builtin\ColorModeSwitchDisplay`) is a
display of the visitor (`system:visitor`), so a component shows it where an
administrator places it (the sample content: at the end of the navigation). It
is a form of three buttons, *as the system* (a half-filled circle), *light* (a
sun), *dark* (a moon), inline SVG icons in the text's colour, the
current one pressed (`aria-pressed`); each has its label for screen readers, or
beside the symbol (`show_labels`). `visitor_color_mode()` is the request's mode
(`auto` included).

The form is sent to `POST /color-mode` (`mode`, and `return`: the page's path).
`AppearanceController` sets the `campanella_color_mode` cookie for a year
(`Path` the site's, `SameSite=Lax`, `HttpOnly`, `Secure` on HTTPS) and leads back
to the page (`303`; a path of this site only, else the front page). Neither a
session nor a CSRF token is used, so a page with the switch is the same for
every visitor and starts no session. The cookie can only change how the pages
look; still, a request from another site is refused (`403`, by `Sec-Fetch-Site`,
or `Origin` in older browsers), so another site cannot change a visitor's mode.
An unknown mode is `400`, `GET` is `405`.

`public/assets/color-mode.js` (loaded by the display, deferred) changes the mode
at once, without loading the page again, and sends the form in the background;
without JavaScript the form works alone.

Since the pages depend on the cookie, the Kernel sends `Vary: Cookie` with every
HTML page, so an intermediate cache never gives one visitor's mode to another.

## Existing themes

A theme that **extends** the core's `base.html.twig` gets the scheme. A theme
that **overrides** it keeps its own colours until it adds the two lines above;
its own CSS can use the variables, e.g. `color: var(--cc-accent)`, and
`--cc-on-accent` for a text on the accent (since 0.3.6; `campanella.css` sets
the default scheme's, so they are there without the scheme's CSS too).
