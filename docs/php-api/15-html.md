# 15. HTML texts

A `Textual` body in `html` format ([chapter 3](03-capabilities.md#textual))
is filtered with an allowlist **on every save** (since 0.0.5): everything that
is not explicitly allowed is removed. The filter runs in
`ObjectRepository::save()`, the lowest layer, so the admin, the command line,
the seed and any later API all go through it; there is no way to store
unfiltered HTML. Rendering (`{{ object|body }}`) therefore shows the stored
text as it is.

Built on [symfony/html-sanitizer](https://symfony.com/doc/current/html_sanitizer.html)
(MIT). It parses the text like a browser (HTML5), so the result is what a
browser would see, also for broken or deliberately tricky markup.

## What remains

The default allowlist (`config/html.php`, or `HtmlSanitizer::defaults()`):

| Kind | Elements (attributes) |
|---|---|
| Blocks | `p`, `div`, `h2`, `h3`, `h4`, `blockquote`, `pre`, `hr`, `br` |
| Inline | `strong`, `b`, `em`, `i`, `s`, `sub`, `sup`, `code` |
| Lists | `ul`, `ol`, `li` |
| Links | `a` (`href`, `title`); every link gets `rel="noopener noreferrer"` |
| Images | `img` (`src`, `alt`, `title`, `width`, `height`), `figure`, `figcaption` |
| Tables | `table`, `caption`, `thead`, `tbody`, `tfoot`, `tr`, `th` (`colspan`, `rowspan`, `scope`), `td` (`colspan`, `rowspan`) |

- **Links:** `http`, `https`, `mailto`, and relative addresses (`/hirek`,
  `#section`). Anything else (`javascript:`, `data:`, `vbscript:`…) loses its
  `href`.
- **Images:** only from this site, i.e. relative addresses such as
  `/media/2026/10/photo.jpg`. External images (including the protocol-relative
  `//host/…`) lose their `src`: they would send the visitors' data to another
  server, and they can change or vanish (`external_images` setting).
- **Attributes:** only the listed ones. `style`, `class`, `id` and every
  `on…` event handler are removed.
- **Other elements:** removed together with their content (`script`,
  `style`, `iframe`, `object`, `form`, `svg`…), except harmless wrappers that
  pasted content often uses (`span`, `font`, `section`, `h1`, `u`…:
  `HtmlSanitizer::UNWRAPPED`): those are removed but their text is kept.
- **Limits:** at most `max_length` bytes (1 MB) and `max_tags` tags (20 000,
  counted as `<` characters; deeply nested markup makes parsing very slow).
  Text that is not valid UTF-8 is not filtered either. In these cases the text
  is not changed or truncated but rejected with a validation error
  (`validation.html_too_long`, `validation.html_too_many_tags`,
  `validation.invalid_encoding`).

Filtering is idempotent: filtering a filtered text again gives the same text.

## Configuration: `config/html.php`

```php
return [
    'elements' => ['p' => [], 'a' => ['href', 'title'], /* … */],
    'link_schemes' => ['http', 'https', 'mailto'],
    'external_images' => false,
    'max_length' => 1_000_000,
    'max_tags' => 20_000,
];
```

Keys left out keep their default. `elements` replaces the whole list, so to
allow one more element, copy the list and add it. Widening the allowlist is a
security decision: an element or attribute added here reaches every visitor's
browser. After narrowing it, run `html:sanitize` to filter the texts stored
before.

## HtmlSanitizer

`Campanella\Html\HtmlSanitizer` · **Public** · `final class` · container: `HtmlSanitizer::class`

| Member | Description |
|---|---|
| `__construct(array $config = [])` | The allowlist (see above); keys left out keep their default. `InvalidArgumentException` for a `max_length` or `max_tags` below 1 |
| `static defaults(): array` | The built-in allowlist |
| `sanitize(string $html): string` | The allowed part of the text; `InvalidArgumentException` (with the message key as its message) if `problem()` reports one |
| `problem(string $html): ?Message` | Why the text cannot be filtered: too long, too many tags, or not valid UTF-8; null if it can |
| `isTooLong(string $html): bool` | Whether the text is longer than `max_length` (in bytes) |
| `maxLength(): int` | `max_length` |
| `UNWRAPPED` | The elements removed with their text kept |

`Campanella\Html\LocalMediaSanitizer` · **Internal** · implements Symfony's
`AttributeSanitizerInterface`: keeps an image `src` only if it is a relative
address on this site (used when `external_images` is off).

| Method | Description |
|---|---|
| `getSupportedElements(): array`, `getSupportedAttributes(): array` | `['img']`, `['src']` |
| `sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string` | The value, or null (removed) for `//host/…`, `/\host/…` or any `scheme:` address |

## Texts stored earlier: `html:sanitize`

```
php bin/campanella html:sanitize --dry-run   # lists the texts that would change
php bin/campanella html:sanitize             # filters and saves them
```

Filters every stored `html` text with the current allowlist and saves the ones
that change (also those that only change in form, e.g. `<br>` → `<br />`).
A text that cannot be filtered (see the limits above), or an object that
cannot be saved for another reason, is reported and left as it is; the others
are still processed, and the command exits with `1`. Run it after upgrading to 0.0.5 (texts stored before were not
filtered), and after narrowing the allowlist.
