# Campanella documentation

This folder holds the Campanella developer documentation. It lives and is
versioned together with the code: every new feature updates the affected
chapter in the same commit.

| Part | Contents | Status |
|---|---|---|
| [PHP API](php-api/README.md) | Classes, contracts and extension points, with examples | Matches 0.0.5 |
| [HTTP API](http-api/README.md) | The web endpoints: the current HTML routes and the planned JSON API | HTML: done · JSON: draft |
| [Changelog](../CHANGELOG.md) | What was added, changed or removed in each version | Ongoing |

Installation and startup are described in the [README](../README.md) in the
project root.

## Stability markers

Every element of the PHP API is marked with how much you can rely on it:

| Marker | Meaning |
|---|---|
| **Public** | Modules and your own code can safely use it. Changes are announced in the CHANGELOG. |
| **Internal** | Part of the core; do not build on it directly: it may change without notice. |

Campanella follows [semantic versioning](https://semver.org/). In the 0.x
versions any release may still bring backward-incompatible changes; these are
always listed in the **Changed** and **Removed** sections of the CHANGELOG.
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
