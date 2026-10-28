# 27. Files

*Since 0.3.2.* Besides images ([chapter 16](16-media.md)), a site can offer
**files to download**: documents, spreadsheets, presentations, text files.
They are objects, as images are: a `file` Blueprint with a title, an author
(the uploader), the file's data (`Downloadable`) and who may download it
(`Restricted`).

Unlike an image, a file is never shown in a page, only downloaded, and it is
**never served by the web server itself**. Every file is stored outside the
web root (`var/files/`), and every download goes through PHP
(`FileController`), which checks who may see it, and sends it with headers
that make it a download on every server (Apache, nginx, the built-in one)
without any configuration. A file can be for **everyone**, for **every
logged-in user**, or for the users with **some roles**; making a file private
moves nothing.

Files are uploaded in the admin ([below](#in-the-admin-filepages)): on the
*Files* list, or in the **media library** with the images; the editor links
to one with its file picker.

## What happens to an uploaded file

1. **Who:** the `AccessPolicy` must allow creating a `file` (checked before
   anything else; the `DefaultPolicy` allows it for `administrator` and `editor`).
2. **Size:** at most `files.max_bytes` (20 MB); an empty file is refused.
3. **Type, from the content** (`FileInspector`): the file's name and the type
   the browser claims are never trusted.
   - **PDF:** it starts as a PDF does (`%PDF-`).
   - **Office** (`docx`, `xlsx`, `pptx`): a ZIP archive whose
     `[Content_Types].xml` names the main part of a document, a spreadsheet or
     a presentation.
   - **OpenDocument** (`odt`, `ods`, `odp`): a ZIP archive whose first entry,
     `mimetype`, says which.
   - **Text** (`txt`, `csv`): no zero bytes; the name's extension tells which
     of the two (both are only text to download).
   - **zip:** only if `files.types` lists it: what is inside cannot be checked.
   - **Never:** macro-enabled Office files (a VBA project, ActiveX, a
     `macroEnabled` content type, whatever the file's name says: a `.docm`
     renamed to `.docx` is refused too), OpenDocument files with Basic or other
     scripts, HTML, SVG, images (those are images), programs, or anything else.
     The ZIP archives are read without the zip extension and without unpacking
     anything to the disk (`ZipDirectory`): only their table of contents and
     the small XML entries that say what they are.
4. **The type and the extension come from the content:** a document named
   `.pdf` that is a `.docx` is stored and downloaded as a `.docx`.
5. **Storing** (`FileStorage`): under a new, random name with that extension:
   `var/files/YYYY/MM/<24 hex characters>.<extension>`. A `.htaccess` in the
   folder denies every request, in case the folder is reachable from the web
   after all (the `var/` folder has one too).
6. **The object** is created through the `ObjectService`, with the
   **download name** made from the uploaded name, in plain letters, digits and
   hyphens, with the real extension: `Áprilisi hírlevél.PDF` →
   `aprilisi-hirlevel.pdf`. If that fails, the file is deleted: there is no file
   without its object.

Deleting the object deletes its file (`DeleteStoredFile`, an `ObjectListener`
of the `ObjectService`).

Problems are reported as a `ValidationException` on the `file` field, with the
keys `file.empty`, `file.too_large`, `file.type_not_allowed`,
`file.not_recognized`, `file.macros`, `file.damaged`.

**What is not checked:** what a document says or does when opened. A PDF can
carry scripts, an Office document links; they are served as downloads, so
opening one is up to the reader's own program (and its protections), as with
any file downloaded from the web.

## Settings (`config/app.php`, `files`)

| Key | Default | |
|---|---|---|
| `files.directory` | `'var/files'` | The folder, relative to the project root (or absolute). Outside the web root; back it up with the database |
| `files.max_bytes` | 20 MB | The largest file accepted. PHP's `upload_max_filesize` and `post_max_size` must allow it too (the system page checks it) |
| `files.types` | `['pdf', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv']` | The accepted types, from `FileInspector::KNOWN` (the others are ignored: no setting can let HTML, SVG or programs in). `zip` can be added, with its risk: what is inside cannot be checked |
| `files.blueprint` | `'file'` | The Blueprint of the files. Only if the site made a Blueprint called `file` in the admin before 0.3.2 ([below](#upgrading)) |
| `paths.files` | `'/files'` | The downloads' address: `/files/<id>/<name>` (a prefix: no page can have a path under it) |
| `users.roles` | `[]` | The site's own roles besides `administrator` and `editor`, e.g. `['member']`: they can be given to users in the admin and a file can be limited to them; they do not let anyone into the admin |

## The `file` Blueprint

```php
'file' => [
    'label' => 'blueprint.file',
    'capabilities' => [Titled::class, Downloadable::class, Restricted::class, Authorable::class],
    'form_order' => ['title', 'visible_to', 'visible_roles', 'author'],
],
```

A file is created by uploading it, never from an empty form (the admin's
`/admin/file/new` does not exist), and is never indexed by the search
(`SearchIndex::NEVER`) or imported (`ImporterDefinition`). `downloadable` and
`restricted` are system capabilities: a Blueprint made in the admin cannot
have them (`BlueprintDefinition::SYSTEM_CAPABILITIES`).

## Downloadable

`Campanella\Capability\Downloadable` · **Public** · capability `downloadable`

| Field | Type | |
|---|---|---|
| `download_path` | String(64), unique | Relative to the files folder: `2026/10/3f…a1.pdf` |
| `download_name` | String(128) | The name it is downloaded with: `aprilisi-hirlevel.pdf` |
| `download_type` | String(100), indexed | The MIME type the content showed |
| `download_size` | Integer | In bytes |
| `download_hash` | String(64), indexed | SHA-256 of the file: finds the same file uploaded twice; the download's ETag |

The fields are set by the upload, never by a form (`ObjectForm::MANAGED_FIELDS`).

| Method | |
|---|---|
| `path(): string`, `name(): string`, `extension(): string` | |
| `mimeType(): string`, `size(): int`, `hash(): string` | |

## Restricted

`Campanella\Capability\Restricted` · **Public** · capability `restricted`

Who may see the object, besides the administrators and editors (who see it
always). The `DefaultPolicy` applies it to every query and every check
([chapter 5](05-access.md#defaultpolicy)): a restricted object is left out of
lists, relations and the search too.

| Field | Type | |
|---|---|---|
| `visible_to` | Choice (16), required, indexed, default `public` | `public` (everyone), `users` (every logged-in user) or `roles` |
| `visible_roles` | String(32), at most 20 values | With `roles`: whose users. At least one is needed; each like a role's name (`member`) |

| Member | |
|---|---|
| `PUBLIC`, `USERS`, `ROLES`, `SCOPES` | The values of `visible_to` |
| `scope(): string` | `public`, `users` or `roles` (anything else counts as `roles` without roles: nobody but the staff) |
| `isPublic(): bool` | |
| `roles(): list<string>` | |
| `restrict(string $scope, array $roles = []): void` | Sets both; the roles count only with `roles` (trimmed, repeated and empty ones left out) |
| `isVisibleTo(Actor $actor): bool` | Whether the actor is among those it is meant for (the staff are decided by the policy). A role without a login counts for nothing |

It is on the `file` Blueprint only in 0.3.2; restricted pages (e.g. for
members) would need the same in the site's lists, menus and the sitemap
first.

## FileService

`Campanella\Media\FileService` · **Public** · container: `FileService::class`

| Method | |
|---|---|
| `upload(Actor $actor, string $file, string $originalName, string $visibleTo = Restricted::PUBLIC, array $roles = [], string $title = ''): CampanellaObject` | The steps [above](#what-happens-to-an-uploaded-file). The title is the uploaded name without its extension, unless one is given. `AccessDeniedException`, `ValidationException` (the file, or the restriction), `RuntimeException` if files are not available or the file cannot be stored |
| `url(CampanellaObject $file): string` | The download address, relative to the site root: `/files/12/aprilisi-hirlevel.pdf` |
| `path(CampanellaObject $file): string` | The stored file's absolute path |
| `isAvailable(): bool` | Whether the Blueprint exists and has `Downloadable` ([below](#upgrading)) |
| `unavailableReason(): ?string` | Why not: `yielded` (a Blueprint of its name was made in the admin), `missing` (e.g. an older `config/blueprints.php` was kept), `not_files`; null if available. The system page tells it |
| `blueprint(): string` | `file` |
| `maxUploadBytes(): int` | The largest file that can be uploaded: `files.max_bytes`, or less if PHP's limits are lower |
| `maxBytesSetting(): int` | `files.max_bytes` |
| `types(): list<string>` | The accepted extensions |
| `static downloadName(string $originalName, string $extension): string` | `Áprilisi hírlevél.PDF`, `pdf` → `aprilisi-hirlevel.pdf` (at most 80 characters before the extension; `file.pdf` if nothing is left) |
| `static titleFrom(string $originalName): string` | The name without its extension, without control and direction-changing characters (as `MediaService::titleFrom()`); `file` if nothing is left |

```php
$files = $kernel->container()->get(\Campanella\Media\FileService::class);
$file = $files->upload($actor, '/tmp/tagok.csv', 'Tagok.csv', Restricted::ROLES, ['member']);
echo $files->url($file); // /files/57/tagok.csv
```

## FileInspector

`Campanella\Media\FileInspector` · **Public** · container: `FileInspector::class`

| Member | |
|---|---|
| `__construct(array $types = DEFAULT_TYPES, int $maxBytes = DEFAULT_MAX_BYTES)` | The accepted extensions (only those of `KNOWN`) and the largest file |
| `inspect(string $file, string $originalName): array{extension, mime, bytes}` | What the file is, or a `ValidationException` on `file` |
| `types(): list<string>`, `maxBytes(): int` | |
| `KNOWN` | Every type that can be accepted: extension => MIME type |
| `DEFAULT_TYPES`, `DEFAULT_MAX_BYTES` | |

`Campanella\Media\ZipDirectory` (**Internal**) reads a ZIP archive's table of
contents (`names()`, `has()`, `method()`, `isEncrypted()`) and a small entry's
content (`read()`: stored or deflated, at most 2 MB unpacked), without the zip
extension. ZIP64 and multi-part archives, and those
with more than 20 000 entries, are refused.

## FileStorage

`Campanella\Media\FileStorage` · **Public** · container: `FileStorage::class`

| Method | |
|---|---|
| `store(string $bytes, string $extension, ?DateTimeImmutable $now = null): string` | Under a new name; returns the relative path. Written under a temporary name and renamed; mode `0640` |
| `delete(string $relativePath): bool` | |
| `path(string $relativePath): string` | The absolute path. `InvalidArgumentException` for anything not of the form `YYYY/MM/<24 hex>.<known extension>` (`PATH_PATTERN`), so nothing else can be read or deleted through it |
| `exists(string $relativePath): bool` | |
| `directory(): string`, `isWritable(): bool` | |

## The downloads: FileController

`Campanella\Controller\FileController` · **Internal** · at `paths.files`

`GET /files/<id>/<name>` (and `HEAD`) sends the file of the object `<id>`:

- if the visitor may not see it (or it is not a file, or its file is missing):
  **404**, as if it did not exist, so not even a private file's existence is
  told;
- another name: **301** to its own (`/files/<id>` too), so a link keeps working;
- otherwise the file, sent from the disk in pieces, with:
  - `Content-Type`: the type the upload found (`text/…; charset=utf-8` for text),
  - `Content-Disposition: attachment; filename="<name>"`: always a download,
    never a page,
  - `Content-Security-Policy: default-src 'none'; sandbox`, `nosniff`,
    `Cross-Origin-Resource-Policy: same-origin`,
  - `ETag` (the checksum) and `Last-Modified`; `If-None-Match` gets `304`;
  - a public file: `Cache-Control: public, max-age=3600`; a restricted one:
    `private, no-cache` and `X-Robots-Tag: noindex`. With a session (a
    logged-in visitor) every response is `private, no-store` anyway.

Other methods get 405. A template links to a file with `file_url()`
([chapter 7](07-http-and-view.md#twig-extension)):

```twig
<a href="{{ file_url(file) }}" download>{{ file.title }}</a> ({{ file.download_size|file_size }})
```

## In the admin: FilePages

`Campanella\Admin\FilePages` · **Internal**

The *Media* menu item opens the **media library**, with *Images* and *Files*
under it (the two Blueprints' own lists).

| Address | |
|---|---|
| `GET /admin/media` | The media library: the images and the files together, newest first; `?kind=image` or `file`, `?q=` a title, `?page=`. One upload form for both: an image (as `getimagesize()` sees it) becomes an image, anything else a file |
| `POST /admin/media/file-upload` | A file (field `file`, with `visible_to` and `visible_roles[]`, and the CSRF token): the *Files* list's form |
| `POST /admin/media/add` | An image or a file: the media library's form |
| `GET /admin/media/files?q=&page=` | The files for the editor's file picker, newest first, 20 a page, as JSON: `{success, items: [{id, title, url, name, type, size, restricted}], page, pages, total}`. Only those the actor may see |

The uploads answer as the images' upload does ([chapter 16](16-media.md#uploading-from-the-admin)):
with `Accept: application/json` (`admin-upload.js`, which sends several files
one by one, chosen or dropped) `{success, id, kind, url, title, edit}` with
201, or `{success: false, message}` with 400 (form), 403, 405, 413 (too large),
422 (not accepted) or 500; without it, a redirect back with a one-time message.
After the session expired, the scripts get 401 and JSON, not the login page.

**The edit form** of a file shows its type, size, download name and address
(the side panel, `_download.html.twig`), and who may see it: `visible_to` as a
choice, `visible_roles` as checkboxes of the site's own roles (`users.roles`,
and the ones the file has already: `ObjectForm`'s last constructor argument,
`$roles`). The *Files* list shows the type, the size and who may see each
(`_visibility.html.twig`). A file is never made from an empty form, and its
list has no CSV export.

**The editor's file picker:** a form with an HTML field gets
`data-files-url`; both toolbars (`full` and `basic`) get a button after the
link button, that lists the files (search, pages), and a click links the
selected text to the file (or inserts its title as a link). Whether a visitor
may download it is decided when the link is followed: a link to a file for
members leads others to a 404, so put it on a page they see.

**Where a file is used:** the texts that link to it are recorded on saving
(`MediaUsage::record()` with the `Downloadable` table, [chapter 16](16-media.md#where-an-image-is-used)),
and its delete page lists them, besides the objects related to it.

**On the site,** a relation to a file (e.g. a Blueprint made in the admin
with a *Files* relation) is shown as the file's download link with its type
and size (`object/_relations.html.twig`).

| Member | |
|---|---|
| `uploadSettings(Actor $actor, bool $library = false): ?array` | The upload form's settings, if the actor may upload: `url`, `max`, `max_mb`, `accept`, `types`, `files`, `images`, `roles` |
| `upload(Request $request, Actor $actor, bool $library): Response` | |
| `browse(Request $request, Actor $actor): Response` | |
| `library(Request $request, Actor $actor, Closure $render): Response` | |
| `panel(CampanellaObject $file): array` | The side panel's data |
| `canUpload(Actor $actor): bool`, `canBrowse(): bool` | |
| `typeName(string $extension): string` | `PDF`, `Word document` … (`file.type.<extension>`) |
| `static roleOptions(array $roles, Translator $translator): array` | The roles by their translated names (`role.<name>`, or the name) |

## The system page

`Campanella\Media\FileCheck` (`static checks(FileService $files, FileStorage $storage): Closure`)
adds the files' lines to the *Images and files* group (formerly *Images*): the folder (writable?),
the accepted types (a warning with `zip`), the largest file (a warning if PHP
allows less than `files.max_bytes`), or, if files are not available, why.

## Upgrading

0.3.2 (schema version 18) adds the `cap_downloadable` and `cap_restricted`
tables: the upgrade creates them. Nothing existing changes.

**A Blueprint called `file` made in the admin** before 0.3.2 keeps its name and
its objects: the `file` Blueprint of `config/blueprints.php` is left out
(`BlueprintRegistry::yielded()`), and files are off (the system page says so).
To get files, name another Blueprint in `config/local.php`
(`'files' => ['blueprint' => 'document']`) and add it to
`config/blueprints.php` as the `file` one is.

**A page whose path starts with `/files/`** is shadowed by the downloads: set
`paths.files` to another address (`'paths' => ['files' => '/letoltes']`).
