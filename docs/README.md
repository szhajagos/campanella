# Campanella documentation

This folder holds the Campanella developer documentation. It lives and is
versioned together with the code: every new feature updates the affected
chapter in the same commit.

| Part | Contents | Status |
|---|---|---|
| [Guide for site owners](guide/README.md) | For people who run a site but do not develop it, in Hungarian and English: installing, the admin, content, users, the contact form, e-mail, backups and upgrades | Since 0.2.1 |
| [PHP API](php-api/README.md) | Classes, contracts and extension points, with examples | Matches 0.2.8 |
| [HTTP API](http-api/README.md) | The web endpoints: the current HTML routes and the planned JSON API | HTML: done · JSON: draft |
| [Security](security.md) | What protects a site, the security review of 0.1.0, the known and accepted risks, reporting a vulnerability | Since 0.1.0 |
| [Deployment](deployment.md) | Running Campanella on a public server: web root, HTTPS and proxies, settings, security headers, permissions, backups, upgrades, a checklist | Since 0.1.0 |
| [Compatibility](compatibility.md) | What stays within 0.2.x (the Public API, the settings, templates and their variables, Twig functions, commands, addresses, data), Deprecated before removed, security fixes | Since 0.2.0 |
| [Upgrading](upgrading.md) | From any 0.1.x to 0.2.0: the steps, the new settings, what behaves differently, themes and custom code, a checklist, going back | Since 0.2.0 |
| [Translations](translations.md) | How multilingual content will be stored: the original and a translation layer, and why | Design (since 0.2.2; built in 0.6.0) |
| [Testing](testing.md) | The automated checks: the tests, the upgrade test, the browser tests; running them locally | Since 0.2.0 |
| [Changelog](../CHANGELOG.md) | What was added, changed or removed in each version | Ongoing |

Installation and startup are described in the [README](../README.md) in the
project root.

## Stability markers

Every element of the PHP API is marked with how much you can rely on it:

| Marker | Meaning |
|---|---|
| **Public** | Modules and your own code can safely use it. Changes are announced in the CHANGELOG. |
| **Internal** | Part of the core; do not build on it directly: it may change without notice. |
| **Deprecated** | Still works throughout its minor series, but goes in a later one: the CHANGELOG and the chapter say what to use instead (since 0.2.0; none yet). |

Campanella follows [semantic versioning](https://semver.org/). Within a 0.x
minor series (from 0.2.0 on: 0.2.0, 0.2.1 …) the Public API, the settings, the
templates' names and variables and the Twig functions only grow: see the
[compatibility promise](compatibility.md). A new minor version (0.3.0) may
bring backward-incompatible changes, after a **Deprecated** notice in the
series before; they are always listed in the **Changed** and **Removed**
sections of the CHANGELOG.
From 1.0 on, the public API can only change in a backward-incompatible way
with a major version bump.

The HTTP API has its own version in the URL (`/api/v1/…`). A
backward-incompatible change can only appear in a new version (`/api/v2/…`).

## Checklist for every new feature

A feature is done only when the documentation keeps up with it:

1. **Code and PHPDoc.** Public classes and methods get a documentation
   comment.
2. **PHP API chapter.** The new class, method or extension point goes into the
   affected chapter, with at least one example. A new area gets a new chapter,
   which is added to the [table of contents](php-api/README.md).
3. **HTTP API.** If the feature adds or changes a web endpoint, the
   [HTTP API](http-api/README.md) description is updated too, and the
   endpoint's status changes from "planned" to "done".
4. **CHANGELOG.** The change goes into the "Unreleased" section of the
   [CHANGELOG](../CHANGELOG.md).
5. **Checks.**

   ```bash
   composer test          # tests
   composer analyse       # PHPStan
   composer docs:check    # any undocumented public class or method?
   ```

`docs:check` collects the public classes and methods from the code and reports
any that do not appear in the `docs/php-api` chapters. This way the
documentation cannot fall behind the code unnoticed.
