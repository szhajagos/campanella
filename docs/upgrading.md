# Upgrading to 0.3.x

This guide takes a site from **any 0.3.x, 0.2.x or 0.1.x version** to the newest
0.3.x (now **0.3.3**) in one go. You do not need to install
the versions in between: one upgrade runs every change since your version. The
CHANGELOG has the same steps version by version; this page puts them
together, and adds what to check and how to go back.

| Your version | What to do |
|---|---|
| 0.3.0 – 0.3.2 | Upload the new files and run the upgrade (steps 1–3): 0.3.1 adds the importers' tables (schema 17), 0.3.2 the files' (schema 18), 0.3.3 the components' (schema 19). Back up `var/files/` from 0.3.2 on (step 1). CI upgrades 0.3.0, 0.3.1 and 0.3.2 to the current code on every push. |
| 0.2.0 – 0.2.9 | This guide. Supported and tested: CI upgrades 0.2.0 and 0.2.9 to the current code on every push, the latter with a content model made in the admin (`tests/upgrade/run.sh`). |
| 0.1.0 – 0.1.5 | This guide, and also [From 0.1.x](#from-01x) below. Tested too: CI upgrades 0.1.0 and 0.1.5. |
| 0.0.6, 0.0.7 | The upgrade runs (their schema version, 6, is accepted), but it is not tested. Also read the [0.1.0 section of the CHANGELOG](../CHANGELOG.md#010--2026-10-08). |
| Older than 0.0.6 | Upgrade to 0.0.7 first, then follow this guide. 0.3.x refuses to upgrade the database and changes nothing. |

Your version is shown on the System page (`/admin/system`), and by
`php bin/campanella status`.

The requirements did not change: PHP 8.3 or newer, MariaDB 10.6+ or MySQL 8.0+.

**Nothing is incompatible.** 0.2.x marked nothing Deprecated, so 0.3.x removes
and renames nothing: a site, a theme or code written for any 0.2.x keeps
working. What 0.3.x promises is in [Compatibility](compatibility.md).

## Contents

- [The steps](#the-steps)
- [What the upgrade changes in the database](#what-the-upgrade-changes-in-the-database)
- [What is new since 0.2.0](#what-is-new-since-020)
- [What behaves differently](#what-behaves-differently)
- [Themes and custom code](#themes-and-custom-code)
- [From 0.1.x](#from-01x)
- [Checklist after the upgrade](#checklist-after-the-upgrade)
- [Going back](#going-back)

## The steps

1. **Make a backup.** You need all three parts to go back:
   - the database: `php bin/campanella db:backup`, which writes into
     `var/backups/`. The upgrade makes one too, but have your own;
   - the uploaded files: the `public/media/` folder (images), and from 0.3.2
     on the `var/files/` folder (files to download);
   - your own files: `config/local.php`, your theme (`themes/`), and any
     file of the program you changed (e.g. `config/blueprints.php`).

   Copy them off the server.

2. **Upload the new version.** Use the release package (`campanella-0.3.3.zip`
   from the GitHub release); it includes the `vendor/` folder. With git:
   `git pull`, then `composer install --no-dev`.

   Keep these: `config/local.php`, `public/media/`, `var/` and `themes/`.
   Replace the program's folders as a whole (`bin/`, `config/` except
   `local.php`, `lang/`, `public/assets/`, `public/index.php`, `src/`,
   `templates/`, `vendor/`). Do not copy them over the old ones: files removed
   since your version would stay behind. If you changed `config/blueprints.php`,
   compare your copy with the new one (0.2.8 added `'admin_columns'` to the
   articles, 0.3.2 the `file` Blueprint, 0.3.3 the `snippet` and `component`
   Blueprints) and keep yours, with what is new added: without the `file`
   Blueprint there are no files to download (the System page says so), without
   `component` no components.

3. **Run the upgrade.** Until then the site answers 503 to visitors, but
   logging in works. Either:
   - on the command line: `php bin/campanella install` (or `migrate`). It asks
     first, makes a backup into `var/backups/` before it changes anything,
     then adds the new tables. Add `--yes` to skip the question;
   - or without a command line: log in as an administrator and open
     `/admin/upgrade`. It also makes the backup first, then shows each step. If
     logging in does not work, set an `upgrade.key` of at least 20 characters
     in `config/local.php`, open the page with it, then **remove the key**.

   From 0.2.7 on, the only changes are new tables (the importers' in 0.3.1,
   the files' in 0.3.2): the upgrade is quick, and the site works at once.

   Then run `php bin/campanella schema:check`, or look at the System page: the
   tables must match the definitions.

4. **Put the existing pages into the search** (new in 0.2.7), from 0.2.6 or
   older. The System page says how many are missing, with a button that adds
   them (press it again while some are left); or run
   `php bin/campanella search:index`. Until then the search does not find the
   pages saved before the upgrade.

5. **From 0.1.x:** also the steps of [From 0.1.x](#from-01x) (the smaller
   copies of the images, the new settings).

6. **Go through the [checklist](#checklist-after-the-upgrade).**

## What the upgrade changes in the database

Nothing is removed or rewritten; only tables and one column are added. 0.2.0
has schema version 12, 0.3.0 has 16, 0.3.1 has 17, 0.3.2 has 18, 0.3.3 has 19 (from 0.1.x: see [below](#from-01x) for
6 to 12).

| Schema | Version | Change |
|---|---|---|
| 13 | 0.2.2 | The `value_decimal` column of `field_values` (multi-valued decimal fields). A Blueprint's own queryable fields get a table of their own, `bp_<name>`, when they are defined |
| 14 | 0.2.3 | `blueprints` table: the Blueprints made in the admin |
| 15 | 0.2.5 | `lists` table: the lists made in the admin |
| 16 | 0.2.7 | `search_index` table (with FULLTEXT indexes), `redirects` table |
| 17 | 0.3.1 | `importers`, `import_runs`, `import_items` tables: the importers, their runs' log, which item became which object |
| 18 | 0.3.2 | `cap_downloadable` and `cap_restricted` tables: the files to download, and who may see an object |
| 19 | 0.3.3 | `cap_component` table: the components |
| 20 | 0.3.4 | The `component_region` column of `cap_component`: a component's region |

With a table prefix, every name starts with it (`cc_lists`, …).

## What is new since 0.2.0

Nothing needs setting up: everything below works with the defaults, and is
used only when an administrator starts using it.

| Version | What | Where |
|---|---|---|
| 0.2.1 | A guide for site owners | [docs/guide](guide/README.md) |
| 0.2.2 | Field types (decimal, date, e-mail, web address, choice, formatted text), queryable own fields | `config/blueprints.php` |
| 0.2.3, 0.2.4 | Blueprints made, changed and deleted in the admin | System → Blueprints |
| 0.2.5 | Lists made in the admin, at a path of their own and in templates (`list()`) | System → Lists |
| 0.2.6 | The content model's export and import | System → Content model; `model:export`, `model:import` |
| 0.2.7 | Search (`/search`, a field in the header), redirects, the editor's image picker | System → Redirects; `search:index` |
| 0.2.8 | The admin's lists with own columns, sorting and filters | the Blueprint editor; `admin_columns` |
| 0.2.9 | Visitors' filters on the lists | the list editor |
| 0.3.0 | The milestone: the guide's chapter on own content types, sample events, the compatibility promise for 0.3.x | [docs/guide](guide/README.md) |
| 0.3.1 | Importers from CSV and JSON files, the admin's lists as CSV | System → Importers; `import:run`, `import:undo`; the lists' *CSV export* |
| 0.3.2 | Files to download (for everyone, for logged-in users, or for some roles), the media library, the editor's file picker, the site's own roles | Media → Files; `files`, `users.roles` |
| 0.3.3 | Components (a source shown with a display), snippets, the displays in templates, the admin's menu in groups | Appearance → Components; Content → Snippet; `displays`, `display_sources` |

New settings: `paths.search` (0.2.7, default `/search`), the search page's
address, like the other `paths`; `paths.files` (0.3.2, default `/files`), the
downloads' address; `files` (0.3.2: the folder, the largest file, the accepted
types, the Blueprint); `users.roles` (0.3.2: the site's own roles, e.g.
`['member']`). See [chapter 27 of the PHP API](php-api/27-files.md#settings-configappphp-files).
`displays` and `display_sources` (0.3.3: the displays and the system sources; without
them, the core's own; [chapter 28](php-api/28-displays.md#the-registry)).

## What behaves differently

- **The search's page is at `/search`** (0.2.7). If an object of your site
  had that path, the search answers there now: give the search another
  address (`'paths' => ['search' => '/kereses']`) or the object another path.
  `robots.txt` lists the search's page as not to crawl.
- **A changed path leads on** (0.2.7): when an object's path changes, its old
  path redirects (301) to the new one. System → Redirects lists them, and can
  delete them.
- **The articles' list in the admin shows their categories**, with a filter
  (0.2.8, `config/blueprints.php`).
- **The search is limited to 30 searches a minute per network** (0.3.0), then
  answers 429 for the rest of the minute.
- **The content model's import is stricter** (0.3.0): a choice's key must
  follow the editor's pattern, and a relation cannot point to users or the
  forms' messages. What the editor made always did.
- **The downloads are at `/files/`** (0.3.2, a prefix: `/files/<id>/<name>`).
  If a page of your site had a path under `/files/`, the downloads answer
  there now: give them another address (`'paths' => ['files' => '/letoltes']`)
  or the page another path.
- **A Blueprint called `file` made in the admin keeps its name** (0.3.2): the
  new `file` Blueprint of `config/blueprints.php` is left out, and files are
  off until `files.blueprint` names another Blueprint (the System page says
  so, *Images and files*). See [chapter 27](php-api/27-files.md#upgrading).
- **The admin's menu has *Media*** (0.3.2), with *Images* and *Files* under it;
  the images are no longer under *Content*. The System page's *Images* group is
  *Images and files*.
- **The admin's menu is in groups** (0.3.3): *Content*, *Media library*,
  *Audience* (submissions, users), and for administrators *Appearance*
  (components), *Structure* (Blueprints, capabilities, lists, the content
  model) and *System* (its first page is now *Status*). The profile is the
  user's name in the top bar. The addresses stay, so bookmarks keep working.
- **Components can only be changed by administrators** (0.3.3): editors see
  them, but cannot make, change or delete them. A Blueprint called `snippet` or
  `component` made in the admin keeps its name, and the core's is left out.

## Themes and custom code

Only if your site has a theme of its own (`themes/`), changed core files, or
PHP code that uses Campanella's classes.

- **The base layout's header has a search field** (0.2.7). A theme's own
  `base.html.twig` can add one: a form to `{{ path('search') }}` with a `q`
  field.
- **New templates** a theme can override: `page/search.html.twig` (0.2.7),
  `list/teaser.html.twig`, `list/table.html.twig`, `list/_pager.html.twig`
  (0.2.5), `list/_filters.html.twig` (0.2.9). A theme's own `list/*` template
  should include `list/_filters.html.twig` to offer a list's filters, and its
  pager should keep `filter_query`.
- **`Admin\ListColumns`** (0.2.8, Internal) takes a `FieldFilters` since 0.2.9.
- **New templates** (0.3.2): `admin/_upload.html.twig`, `admin/_download.html.twig`,
  `admin/_visibility.html.twig`, `admin/media.html.twig` (the admin's, Internal).
  `object/_relations.html.twig` shows a relation to a file as its download
  link: a theme's own copy can do the same with `file_url()`.
- **`Response`** has a fourth constructor parameter, `$file` (0.3.2), and
  `Response::file()`: code that builds a Response with three arguments keeps
  working.
- For anything else, the **Changed** sections of 0.2.1–0.2.9 in the
  [CHANGELOG](../CHANGELOG.md) list every change of the
  [PHP API](php-api/README.md).

## From 0.1.x

From 0.1.0 to 0.1.5, the upgrade to 0.3.x also runs everything 0.2.0 brought.
The tables of schema 7 to 12:

| Schema | Version | Change |
|---|---|---|
| 7 | 0.1.1 | `settings` table: the site's settings edited in the admin |
| 8 | 0.1.2 | `media_usage` table, and the images' `variants` column. The migration `core:0008_media_usage` records which texts show which image. |
| 9 | 0.1.3 | `mail_log` table |
| 10 | 0.1.4 | `sessions` table: the logins listed on the profile |
| 11 | 0.1.4 | `password_resets` table |
| 12 | 0.1.5 | `cap_submitted` table: the contact form's messages |

After the upgrade, **make the smaller copies of the images** (new in 0.1.2),
from 0.1.0 or 0.1.1: the System page (*Images → Smaller copies*, and press the
button again while some are left), or `php bin/campanella media:variants`.
Until then the old images are shown in full size, as before. Then set up what
is new below; at the least the site's address under *Site settings*, e-mail in
`config/local.php`, and the privacy notice for the contact form.

### New settings since 0.1.x

All of them are optional. Without them the site works as before, and the
System page warns about what is missing. They go into `config/local.php`
unless noted.

| Setting | Since | Default | What it is for |
|---|---|---|---|
| *Site settings* (admin: System → Site settings) | 0.1.1 | the values in `config/app.php` | The site's name, slogan, description, **address** and share image. Without the address there are no canonical URLs, no share links, no `sitemap.xml`, and no forgotten password. `CAMPANELLA_SITE_URL` also sets it. |
| `mail.dsn`, `mail.from` | 0.1.3 | empty: nothing is sent | E-mail. Needed for the forgotten password and the contact form's notifications. Test it from the System page. See [Deployment: E-mail](deployment.md#e-mail). |
| `mail.from_name`, `mail.log_days`, `mail.timeout` | 0.1.3 | the site's name, 90, 10 | The sender's name, how long the mail log keeps entries, how long to wait for the server |
| `events` | 0.1.3 | `[]` | Actions bound to events, e.g. an e-mail to the administrators when something is published |
| `paths` | 0.1.4 | `/login`, `/logout`, `/password-reset`, `/contact` | The addresses of the system's pages; see [below](#what-behaves-differently-since-01x) |
| `session.absolute_timeout` | 0.1.4 | 43200 (12 hours) | A login ends this long after it started, however active |
| `auth.password_reset_minutes` | 0.1.4 | 60 | How long the forgotten password's link is valid |
| `contact.enabled` | 0.1.5 | `true` | The contact form at `/contact` (`false`: 404, and `contact_form()` shows nothing) |
| `contact.notify`, `contact.notify_to` | 0.1.5 | `true`, `[]`: the administrators | Who gets an e-mail about each message |
| `contact.notify_per_hour` | 0.1.5 | 50 | At most this many such e-mails in an hour. Every message is kept in the admin anyway. |
| `contact.keep_days` | 0.1.5 | 180 | Messages are deleted after this many days (`0`: kept for ever) |
| `contact.min_seconds`, `contact.guards` | 0.1.5 | 3, the honeypot | The form's protections against bots |
| *Site settings → Privacy* | 0.1.5 | none | The privacy notice's address, linked under the contact form |

On a quiet site, run the deletion of old messages from cron, e.g. daily:
`php bin/campanella submissions:prune`. Otherwise old messages are deleted
when a new one arrives or an administrator opens the dashboard.

### What behaves differently since 0.1.x

- **Logging in moved to `/login`, logging out to `/logout`** (0.1.4); they
  were `/belepes` and `/kilepes`. The login page's return parameter is
  `return` (was `vissza`). To keep the old addresses, e.g. for bookmarks:

  ```php
  'paths' => ['login' => '/belepes', 'logout' => '/kilepes'],
  ```

  If you changed `config/routes.php`, remove the two old routes from it.
- **The contact form is on** at `/contact` (0.1.5). Turn it off with
  `'contact' => ['enabled' => false]`, or give it a privacy notice and link it
  from a menu.
- **Messages are for administrators only** (0.1.5). Editors do not see
  *Submissions*, and templates never get the messages.
- **A login lasts at most 12 hours** (0.1.4), however active. Logins from
  before the upgrade stay logged in; their 12 hours start at their next
  request. Every login is listed on the profile, where it can be ended.
- **The forgotten password** (0.1.4) is offered on the login page only when
  e-mail is set up and the site's address is set.
- **The throttling counters start again** (0.1.5): their keys are now hashed
  with a secret of the installation. An account locked by too many wrong
  passwords at the moment of the upgrade is free again.
- **Absolute URLs come only from the site's address**, never from the
  request's `Host` header (0.1.1). Canonical URLs, share links and the
  sitemap appear once the address is set.
- **The images** (0.1.2) have smaller copies, and texts show them with
  `srcset`. An image's delete page lists the texts that show it.
- **A blocked account gets the same answer as a wrong password** (0.2.0),
  not "The account is blocked". Tell a blocked user yourself why they cannot
  log in.
- **Blocking ends the account's logins at once** (0.2.0), also those that
  made no request while it was blocked.
- **The per-address login and forgotten-password limits count an IPv6
  address by its /48** (0.2.0; before, by its /64). Many people behind one
  /48, e.g. an office, share its 20 login attempts in 15 minutes; raise
  `auth.max_attempts_per_ip` if they need more.

### Themes and custom code since 0.1.x

Only if your site has a theme of its own (`themes/`), changed core files, or
PHP code that uses Campanella's classes.

- **`public/index.php` must call `$kernel->terminate()`** after `send()`
  (0.1.4). The release's file does. If you keep your own copy, for example
  because you moved `public/` into a host's web root, add the line:

  ```php
  $kernel->handle(Request::fromGlobals())->send();
  $kernel->terminate();
  ```

  Without it, the work done after the response never runs. That work
  includes the forgotten password's e-mail and the contact form's
  notifications.
- **Links to logging in and out:** use `{{ path('login') }}` and
  `{{ path('logout') }}`, not `url('/belepes')` (0.1.4).
- **`site` in templates** is a read-only object (0.1.1). `{{ site.name }}`,
  `site.x is defined` and loops work as before. Filters that need an array
  (`site|merge(…)`) need `site.toArray()`.
- **The base layout has a `meta` block** (0.1.1) for the description,
  canonical URL and Open Graph tags. A theme's own `base.html.twig` should
  print it in `<head>`; copy it from the core template.
- **The contact form on any page:** `{{ contact_form() }}` (0.1.5). Its
  templates are `page/contact.html.twig` and `page/_contact_form.html.twig`.
  A theme's own copy must keep the hidden fields, `csrf_field()` and the
  guards' fields; see [chapter 22](php-api/22-forms.md#the-contact-form).
- **E-mail templates** (0.1.3–0.1.5) are `mail/<name>.txt.twig`. A theme
  overrides one by having its own; see
  [chapter 21](php-api/21-events-and-mail.md#sending-e-mail).
- **A custom access policy**, one that replaced `DefaultPolicy`, must hide the
  `submitted` objects (the messages) from everyone but administrators itself
  (0.1.5). Only `DefaultPolicy` does it. See
  [chapter 5](php-api/05-access.md#defaultpolicy).
- **`events`:** do not bind `MailSubmission` to `FormSubmitted` there. The
  contact form already does (`contact.notify`), and the messages would be
  mailed twice.
- **Constructors:** `ObjectService` and `UserService` take an optional
  `EventDispatcher` as their last argument (0.1.3). `ObjectController` and
  `QueryController` take an optional `MetaBuilder` (0.1.1). Code that gets
  them from the container is not affected. Code that constructs them itself
  should check its arguments.
- **The template cache** is kept per version (`var/cache/twig/<version>`). The
  old folders can be deleted with the System page's *Clear the template cache*
  button.

For anything else, the **Changed** and **Removed** sections of 0.1.1–0.1.5
in the [CHANGELOG](../CHANGELOG.md) list every change of the
[PHP API](php-api/README.md).

## Checklist after the upgrade

- [ ] The System page (*System → Status*) shows 0.3.3, no error, the tables match the
      definitions, and no page is missing from the search; under *Images and
      files* the files' folder is writable.
- [ ] Under *Appearance → Components* a new component (e.g. *The visitor* as
      *Log in/out button*) is saved, and listed without a *Not working* mark.
- [ ] A file uploaded under *Media → Files* downloads from its address; one
      for logged-in users gives "Page not found" in a private window.
- [ ] The front page, an article with an image, a list, the search
      (`/search?q=…`) and a 404 page look right.
- [ ] Logging in and out work.
- [ ] The Blueprints and lists made in the admin (if any) are under System,
      and their pages work.
- [ ] A changed path leads on: change a test page's path, open the old one.
- [ ] *Site settings*: the address (with `https://`) is set; e-mail: the
      System page's test e-mail arrives.
- [ ] From 0.1.x: the images have their smaller copies; the contact form
      works or is turned off.
- [ ] Cron: `db:backup`, and `submissions:prune` on a quiet site.
- [ ] `upgrade.key` is not set any more.
- [ ] The [Deployment checklist](deployment.md#checklist-before-going-live)
      still holds.

## Going back

If something is wrong after the upgrade and cannot be fixed at once, go back
to your old version:

1. **Put back the old files:** the old version's program folders, `vendor/`
   included. Keep `config/local.php`: settings an old version does not know
   are ignored.
2. **Restore the database** from the backup made before the upgrade: your
   own, or the one the upgrade made (`var/backups/campanella-*.sql.gz`, with
   the upgrade's date and time in its name, UTC; its first lines name the
   version and schema it holds). The file drops and re-creates the tables it
   holds:

   ```bash
   gunzip -c var/backups/campanella-20261011-101500-a1b2c3.sql.gz | mysql -u campanella -p campanella
   ```

   Or use phpMyAdmin → Import. The tables added by the upgrade (the lists
   [above](#what-the-upgrade-changes-in-the-database) and [from 0.1.x](#from-01x)) are not in the backup
   and stay. The old version does not use them, and its `schema:check` lists
   them as tables without a definition. Dropping them is cleaner (the
   `sessions` table, for one, would hold logins from after the upgrade); a
   later upgrade creates them again, and works over them too.
3. **Put back `public/media/`** only if images were uploaded or deleted after
   the upgrade. The upgrade itself only adds the smaller copies, which the old
   version ignores. The files uploaded after the upgrade (`var/files/`, 0.3.2)
   mean nothing to an older version: keep them aside, or delete them.

What was done on the site between the upgrade and going back is lost with the
restore: new content, users and messages.

Then report what went wrong ([GitHub issues](https://github.com/szhajagos/campanella/issues),
or privately for a security problem: [Security](security.md#reporting-a-vulnerability)).
