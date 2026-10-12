# Compatibility promise

From 0.2.0 on, a site, a theme, a module or your own code written for 0.2.0
keeps working with every later **0.2.x** version. Any 0.2.x version can be
installed over any earlier one, or over any 0.1.x (see
[Upgrading](upgrading.md)), and changes nothing that the list below covers,
except by adding to it.

Campanella is still before 1.0: the promise is for one minor series at a time.
In **0.3.0** and later, backward-incompatible changes are possible again, but
only after a warning in 0.2.x (below), and always listed in the CHANGELOG with
what to do.

## What only grows within 0.2.x

| Area | What stays | What may be added |
|---|---|---|
| **PHP API** | Every class, interface, method, constant and property marked **Public** in the [PHP API](php-api/README.md) chapters: its name, its parameters (in order), what it returns, and its documented behaviour | New Public classes and methods; new optional parameters at the end; new constants |
| **Configuration** | Every key of `config/app.php` (and its `CAMPANELLA_*` environment variable), its meaning and its default; `config/local.php` written for 0.2.0 needs no change | New keys, with defaults that keep the old behaviour, unless the change is a security fix |
| **Templates** | Every template's name (`page/login.html.twig`, `mail/submission.txt.twig` …) and the variables it gets; the blocks of `base.html.twig` | New templates; new variables; new blocks |
| **Twig** | Every function and filter (`path()`, `url()`, `t()`, `contact_form()`, `csrf_field()`, `image_srcset()`, `body` …) with its arguments | New functions, filters and optional arguments |
| **Command line** | Every command of `bin/campanella` and its options, and the exit codes | New commands and options |
| **The site's addresses** | The paths of the `paths` setting and their defaults (`/login`, `/logout`, `/password-reset`, `/contact`), `/install`, `/sitemap.xml`, `/robots.txt`, the admin's pages under its path | New pages |
| **Data** | Every table and column (upgrades only add); no upgrade within 0.2.x deletes or rewrites data | New tables and columns |

A theme that overrides a template keeps working, because the template's name
and variables stay. Its **markup** (HTML elements, CSS classes, texts) may
change in a 0.2.x, so a theme that copied a core template should compare its
copy with the new one when upgrading. The CHANGELOG lists such changes under
**Changed**.

## What is not covered

- Anything marked **Internal** in the PHP API, and classes and methods that
  are not documented: they may change in any version.
- The HTML, CSS classes and JavaScript of the core templates and of the admin
  (see above), and the look of the admin.
- The wording of texts and messages (the translations), and the text of the
  command line's output. The message **keys** a theme uses in `t()` stay.
- The database's own structure as seen by SQL: use the PHP API, not the
  tables.
- Behaviour that is a security problem: a security fix may change it in a
  0.2.x, and says so in the CHANGELOG.

## Removing something: Deprecated first

What is covered above is never removed or renamed within 0.2.x. To go in
0.3.0 or later, it is first marked **Deprecated** in a 0.2.x version:

1. the CHANGELOG lists it under **Deprecated**, with what to use instead;
2. the PHP API chapter marks it **Deprecated** (and the PHPDoc has a
   `@deprecated` tag naming the replacement);
3. it keeps working throughout 0.2.x.

Only then can 0.3.0 (or a later version) remove it, listed under **Removed**
with the upgrade steps.

## Security fixes

Security fixes come as patch versions: **0.2.1, 0.2.2 …**, which keep the
promise above. Each is a drop-in upgrade: upload the new files and run the
upgrade as usual. Fixes go to the newest minor series (now 0.2.x). How long
0.2.x keeps getting them once 0.3.0 is out is announced with 0.3.0.

To report a vulnerability, see [Security](security.md#reporting-a-vulnerability).

## How it is checked

- The [upgrade test](php-api/17-migrations.md#the-upgrade-test) installs
  0.1.0 with content and upgrades it to the current code on every push:
  every row, column and file must survive.
- The [browser tests](testing.md#the-browser-tests) and the
  [package check](testing.md#the-release-package) run every page a site owner
  and a visitor use, on the package as it is published.
- `composer docs:check` fails if a public class or method is not documented,
  so nothing becomes part of the API unnoticed.
