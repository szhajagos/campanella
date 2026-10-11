# Upgrading to 0.2.0

This guide takes a site from **any 0.1.x version** (0.1.0 to 0.1.5) to 0.2.0 in
one go. You do not need to install the versions in between: one upgrade runs
every change since your version. The CHANGELOG has the same steps version by
version. This page puts them together, and adds what to check and how to go
back.

| Your version | What to do |
|---|---|
| 0.1.0 – 0.1.5 | This guide. Supported and tested: CI upgrades 0.1.0 to the current code on every push, with content, users and images (`tests/upgrade/run.sh`). |
| 0.0.6, 0.0.7 | The upgrade runs (their schema version, 6, is accepted), but it is not tested. Also read the [0.1.0 section of the CHANGELOG](../CHANGELOG.md#010--2026-10-08). |
| Older than 0.0.6 | Upgrade to 0.0.7 first, then follow this guide. 0.2.0 refuses to upgrade the database and changes nothing. |

Your version is shown on the System page (`/admin/system`), and by
`php bin/campanella status`.

The requirements did not change: PHP 8.3 or newer, MariaDB 10.6+ or MySQL 8.0+.

## Contents

- [The steps](#the-steps)
- [What the upgrade changes in the database](#what-the-upgrade-changes-in-the-database)
- [New settings](#new-settings)
- [What behaves differently](#what-behaves-differently)
- [Themes and custom code](#themes-and-custom-code)
- [Checklist after the upgrade](#checklist-after-the-upgrade)
- [Going back](#going-back)

## The steps

1. **Make a backup.** You need all three parts to go back:
   - the database: `php bin/campanella db:backup`, which writes into
     `var/backups/`. The upgrade makes one too, but have your own;
   - the uploaded files: the `public/media/` folder;
   - your own files: `config/local.php`, your theme (`themes/`), and any
     file of the program you changed.

   Copy them off the server.

2. **Upload the new version.** Use the release package (`campanella-0.2.0.zip`
   from the GitHub release). It includes the `vendor/` folder, which **changed
   since 0.1.2**: 0.1.3 added symfony/mailer. With git: `git pull`, then
   `composer install --no-dev`.

   Keep these: `config/local.php`, `public/media/`, `var/` and `themes/`.
   Replace the program's folders as a whole (`bin/`, `config/` except
   `local.php`, `lang/`, `public/assets/`, `public/index.php`, `src/`,
   `templates/`, `vendor/`). Do not copy them over the old ones: files removed
   since your version would stay behind.

   With Docker: `docker compose exec web composer install`, then
   `docker compose up -d`, which also starts the Mailpit container added in
   0.1.3.

3. **Run the upgrade.** Until then the site answers 503 to visitors, but
   logging in works. Either:
   - on the command line: `php bin/campanella install`. It asks first, makes a
     backup into `var/backups/` before it changes anything, then adds the new
     tables and runs the migrations. Add `--yes` to skip the question;
   - or without a command line: log in as an administrator and open
     `/admin/upgrade`. It also makes the backup first, then shows each step. If
     logging in does not work, set an `upgrade.key` of at least 20 characters
     in `config/local.php`, open the page with it, then **remove the key**.

   Then run `php bin/campanella schema:check`, or look at the System page: the
   tables must match the definitions.

4. **Make the smaller copies of the images** (new in 0.1.2), from 0.1.0 or
   0.1.1. Use the System page (*Images → Smaller copies*, and press the button
   again while some are left), or `php bin/campanella media:variants`. Until
   then the old images are shown in full size, as before.

5. **Set up what is new.** Read [New settings](#new-settings). At the least:
   - the site's address under *Site settings*;
   - e-mail in `config/local.php`;
   - the privacy notice for the contact form.

6. **Go through the [checklist](#checklist-after-the-upgrade).**

## What the upgrade changes in the database

From 0.1.0 (schema version 6) to 0.2.0 (schema version 12). Nothing is
removed or rewritten; only tables and one column are added, plus one
migration that fills a new table.

| Schema | Version | Change |
|---|---|---|
| 7 | 0.1.1 | `settings` table: the site's settings edited in the admin |
| 8 | 0.1.2 | `media_usage` table, and the images' `variants` column. The migration `core:0008_media_usage` records which texts show which image. |
| 9 | 0.1.3 | `mail_log` table |
| 10 | 0.1.4 | `sessions` table: the logins listed on the profile |
| 11 | 0.1.4 | `password_resets` table |
| 12 | 0.1.5 | `cap_submitted` table: the contact form's messages |

With a table prefix, every name starts with it (`cc_settings`, …).

## New settings

All of them are optional. Without them the site works as before, and the
System page warns about what is missing. They go into `config/local.php`
unless noted.

| Setting | Since | Default | What it is for |
|---|---|---|---|
| *Site settings* (admin: System → Site settings) | 0.1.1 | the values in `config/app.php` | The site's name, slogan, description, **address** and share image. Without the address there are no canonical URLs, no share links, no `sitemap.xml`, and no forgotten password. `CAMPANELLA_SITE_URL` also sets it. |
| `mail.dsn`, `mail.from` | 0.1.3 | empty: nothing is sent | E-mail. Needed for the forgotten password and the contact form's notifications. Test it from the System page. See [Deployment: E-mail](deployment.md#e-mail). |
| `mail.from_name`, `mail.log_days`, `mail.timeout` | 0.1.3 | the site's name, 90, 10 | The sender's name, how long the mail log keeps entries, how long to wait for the server |
| `events` | 0.1.3 | `[]` | Actions bound to events, e.g. an e-mail to the administrators when something is published |
| `paths` | 0.1.4 | `/login`, `/logout`, `/password-reset`, `/contact` | The addresses of the system's pages; see [below](#what-behaves-differently) |
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

## What behaves differently

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

## Themes and custom code

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

- [ ] The System page shows 0.2.0, no error, and the tables match the
      definitions.
- [ ] The front page, an article with an image, and a 404 page look right.
- [ ] Logging in and out work, at the new address or at the one you kept.
- [ ] *Site settings*: the address (with `https://`), the name and the
      privacy notice are set.
- [ ] E-mail: the System page's test e-mail arrives. Log out, use
      *Forgot your password?*, and the link arrives.
- [ ] The contact form: send a test message. It shows under *Submissions*,
      and the e-mail about it arrives. Or the form is turned off.
- [ ] The images have their smaller copies (the System page says none are
      missing).
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

   Or use phpMyAdmin → Import. The tables added by the upgrade (the list
   [above](#what-the-upgrade-changes-in-the-database)) are not in the backup
   and stay. The old version does not use them, and its `schema:check` lists
   them as tables without a definition. Dropping them is cleaner (the
   `sessions` table, for one, would hold logins from after the upgrade); a
   later upgrade creates them again, and works over them too.
3. **Put back `public/media/`** only if images were uploaded or deleted after
   the upgrade. The upgrade itself only adds the smaller copies, which the old
   version ignores.

What was done on the site between the upgrade and going back is lost with the
restore: new content, users and messages.

Then report what went wrong ([GitHub issues](https://github.com/szhajagos/campanella/issues),
or privately for a security problem: [Security](security.md#reporting-a-vulnerability)).
