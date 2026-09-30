# 12. Translation

User-facing texts (the site's menus, the login form, error pages) are not
written into the code or the templates; they are referenced by **key**
(`auth.login`), and the texts live in one file per language. English is the
base language, Hungarian is a full translation. Since 0.0.4.

What is covered so far: templates, login, error pages. Validation messages and
the command-line output still contain Hungarian text and move to keys in a
later step (see the [ROADMAP](../../ROADMAP.md)).

## Language files

`lang/en.php`, `lang/hu.php`: each returns a flat `key => text` array.

```php
return [
    'auth.login' => 'Log in',
    'auth.too_many_attempts' => 'Too many failed attempts. Try again in {minutes} minutes.',
];
```

- **Keys:** lowercase, dot-separated by area (`site.`, `content.`, `auth.`,
  `error.`).
- **Parameters:** `{name}` in the text, filled in from the parameter array.
- **Every language has the same keys.** `composer lang:check` (and a test)
  reports keys missing from, or extra in, any language compared to `en.php`.
- **A new language** is a new file (e.g. `lang/de.php`) with all the keys of `en.php`.

## Choosing the language

The `locale` configuration key (`config/app.php`, or the `CAMPANELLA_LOCALE`
environment variable); default: `'hu'`. The language code must match a file
name in `lang/`. The `<html lang>` attribute follows it.

## Translator

`Campanella\I18n\Translator` · **Public** · `final class` · container: `Translator::class`

| Member | Description |
|---|---|
| `BASE_LOCALE` | `'en'`, the fallback language |
| `__construct(array $catalogs, string $locale = 'en')` | `locale => key => text`; `InvalidArgumentException` for an invalid language code (`hu`, `en_GB` form) |
| `static fromDirectory(string $directory, string $locale): self` | Loads every `<locale>.php` of the directory |
| `static loadCatalogs(string $directory): array` | The catalogs of a directory; `UnexpectedValueException` if a file does not return an array |
| `translate(string $key, array $params = []): string` | The text in the current language, else in English, else the key itself |
| `has(string $key): bool` | Whether the key exists in the current language or in English |
| `locale(): string` | The current language code |
| `locales(): list<string>` | The languages that have a file |
| `static compare(array $catalogs): array` | Per language, the keys `missing` from it and `extra` in it compared to English |

Because an unknown key is returned as it is, a **plain text passes through
unchanged**. So code that is given either a key or a ready-made text (e.g. a
custom `LoginGuard` returning its own message, or an exception message shown
on an error page in debug mode) works without special handling.

## In templates

```twig
<h1>{{ t('auth.login') }}</h1>
<p>{{ t('auth.too_many_attempts', {minutes: 5}) }}</p>
<html lang="{{ locale() }}">
```

`t()` and `locale()` are functions of the Twig extension
([chapter 7](07-http-and-view.md#twig-extension)); the output is escaped like
any other value.

## In PHP code

Code that produces a user-facing message returns its **key** (and parameters);
the text is looked up where it is displayed:

| Where | Key / behavior |
|---|---|
| `AuthService::attempt()` | `LoginResult::failure('auth.too_many_attempts', ['minutes' => 3])`; `AuthService::GENERIC_ERROR` is the key `'auth.invalid_credentials'` |
| `LoginGuard::check()` | Returns a key (e.g. `AuthService::GENERIC_ERROR`) or a ready-made text |
| `AuthController` | Translates the `LoginResult` error and the page title |
| `HttpException::notFound()` | Default message: the key `'error.not_found'` |
| `Kernel` error pages | Translates the message (`error.not_installed`, `error.needs_upgrade`, `error.internal`, or the `HttpException` message) |
