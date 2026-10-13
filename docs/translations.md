# Translations: the design

Multilingual content comes in 0.6.0. How translations are stored was decided
before that, on 2026-10-13, because it shapes every table that holds values:
the tables made from 0.2.2 on (a Blueprint's own `bp_<name>` table) already
fit it, so they will not have to be rebuilt. This page is the design; nothing
of it is built yet, except the `translatable` flag of a field.

## The model: a base and a translation layer

**The existing rows stay the content in its original language.** The
translations of the translatable fields go into tables of their own, next to
the tables they translate:

| Values | Original | Translations |
|---|---|---|
| A capability's single-valued queryable fields | `cap_<name>` (`object_id`) | `cap_<name>_t` (`object_id`, `language`) |
| A Blueprint's own single-valued queryable fields | `bp_<name>` (`object_id`) | `bp_<name>_t` (`object_id`, `language`) |
| The fields in the JSON data | `objects.data` | `object_translations` (`object_id`, `language`, `data`) |
| Multi-valued queryable fields | `field_values` | decided when it is built: a `language` column with the original's value as default, or `field_values_t` |

A translation table holds only the **translatable** columns of its original
(a `_t` table is made only if there is one), and a row only for the languages
an object has been translated into. An object records its original language
(a new `objects.language` column, defaulting to the site's language).

**Reading** an object in a language joins its translation rows and takes each
translatable value from there, **falling back to the original** where there is
no translation. **Querying** in a language does the same with one more join per
table (`COALESCE(translation, original)`).

**What is not translated lives in one place.** A price, a date, an image, a
category or the author belongs to the object, not to a language: it is stored
once, in the original row, and every language shows it. Relations (e.g. the
categories) are shared by every language.

## Why this model

Two other models were considered:

- **An object per language** (WordPress with Polylang): every translation is a
  separate object, linked in a group. It needs no schema change at all, but
  the values that are not translated (a date, an image, the categories) exist
  once per language and have to be kept the same by hand or by code: a lasting
  source of mistakes.
- **A full row per language** (Drupal 8 and later): every table gets
  `(object_id, language)` as its key. Clean and uniform, but every existing
  table's primary key changes, and the values that are not translated are
  repeated in every language's row.

The base and translation layer:

- **changes no existing table**: it only adds tables and columns, so the whole
  way to 0.6.0 stays within the
  [compatibility promise](compatibility.md) (upgrades only add);
- costs nothing for a site in one language (no translation rows, no joins);
- keeps a value that is not translated in one place;
- works the same for the capabilities' tables and the Blueprints' own tables.

Its costs: a query in a language other than the original needs a join per
table, and sorting on a translated field cannot use a simple index. For the
sites Campanella is made for, this is acceptable.

## What marks a field translatable

`Field`'s `translatable` flag (since 0.2.2; [PHP API](php-api/02-objects.md#field)).
It is part of the definition now, so the content model built in the admin
(0.3.0) records it from the start, but it has no effect until 0.6.0. Typically
translatable: titles, texts, a lead, a path, an image's alternative text.
Typically not: dates, numbers, choices (their labels are translated by the
interface instead), e-mail addresses, and anything that identifies an object.

## Open questions, decided in 0.6.0

- How a path works per language: a language prefix (`/en/…`) and a path per
  translation, unique within its language.
- Whether publishing is per translation (a translation can be a draft while
  the original is published).
- How multi-valued translatable values are stored (see the table above).
- Menus and the site settings per language.
