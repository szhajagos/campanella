# 16. Images (media)

Uploaded images are objects (since 0.0.5), following the decision that
anything referred to is an object: an `image` Blueprint with a title, an
alternative text, an author (the uploader) and the file's data. The file
itself is stored in `public/media/` and served directly by the web server.

Images are uploaded from the admin's editor (the image button, pasting,
dropping) or on the Images list ([below](#uploading-from-the-admin)); every
upload goes through the server side described here.

## What happens to an uploaded file

1. **Who:** the `AccessPolicy` must allow creating an `image` (checked before
   anything else; the `DefaultPolicy` allows it for `administrator` and `editor`).
2. **Size:** at most `media.max_bytes` (10 MB); an empty file is refused.
3. **Type, from the content:** `getimagesize()` (and `fileinfo`, if available)
   must recognise JPEG, PNG, WebP or GIF. The file's name and the type the
   browser claims are never trusted. SVG is not accepted: it can carry scripts.
4. **Dimensions and structure, before decoding:** at most `media.max_pixels`
   (25 megapixels), or less if `memory_limit` would not allow decoding a larger
   image (about 9 bytes per pixel). A tiny file claiming huge dimensions (a
   "decompression bomb") is refused here, as is a JPEG with more than 100 scans
   (`ImageProcessor::MAX_JPEG_SCANS`; a progressive photo has about ten, a
   crafted file repeating its scans would keep the decoder busy for minutes).
5. **Re-encoding with GD:** the image is decoded and encoded again, so only
   the pixels remain. Metadata (e.g. the GPS position of a phone photo) and
   anything hidden in the file (e.g. code appended to an image) are gone. A
   JPEG is turned upright by its EXIF orientation first (with the `exif`
   extension), and an image larger than `media.max_dimension` (2560 px) is
   scaled down. Transparency is kept; an animated GIF keeps only its first frame.
   **Strict by default:** a type this server cannot re-encode (no GD, or a GD
   built without support for it, e.g. WebP) is refused
   (`media.type_not_processable`), so no image is ever stored with its
   metadata. With `media.store_unprocessed` the checked original is stored
   instead, metadata included. The system page shows the formats gd was built
   with, and which types can be uploaded (the *Images* group).
6. **Storing:** under a new, random name with the extension of the recognised
   type: `public/media/YYYY/MM/<24 hex characters>.<jpg|png|webp|gif>`. A
   `.htaccess` in `public/media/` forbids running anything there as code.
7. **The object** is created through the `ObjectService`. If that fails, the
   file is deleted: there is no file without its object.

Deleting the object deletes its file (`DeleteMediaFile`, an `ObjectListener`
of the `ObjectService`).

Problems are reported as a `ValidationException` on the `file` field, with the
keys `media.empty`, `media.too_large`, `media.not_image`,
`media.type_not_allowed`, `media.type_not_processable`, `media.too_many_pixels`,
`media.too_complex`, `media.processing_failed`.

## Settings (`config/app.php`, `media`)

| Key | Default | |
|---|---|---|
| `media.directory` | `'public/media'` | The folder, relative to the project root (or absolute) |
| `media.url` | `'/media'` | Its address on the site |
| `media.max_bytes` | 10 MB | The largest file accepted. PHP's `upload_max_filesize` and `post_max_size` must allow it too (the system page checks it; [below](#web-server)) |
| `media.max_pixels` | 25 000 000 | The largest image (width × height) |
| `media.max_dimension` | 2560 | Larger images are scaled down to this width or height |
| `media.quality` | 85 | JPEG and WebP quality when re-encoding (1–100) |
| `media.store_unprocessed` | `false` | `true`: a type the server cannot re-encode is stored as uploaded (metadata included) instead of being refused |
| `media.memory_limit` | `'320M'` | PHP's `memory_limit` is raised to this while processing an image (for that request only), if the server allows it. Decoding a 12-megapixel photo needs about 100 MB, a 25-megapixel one (`max_pixels`) about 300 MB with the scaled copy; with the common 128 MB limit only a few megapixels would fit |

## The `image` Blueprint and the MediaFile capability

```php
'image' => [
    'label' => 'blueprint.image',
    'capabilities' => [Titled::class, MediaFile::class, Authorable::class],
    'fields' => [new Field('alt', FieldType::String, label: 'field.alt')],
],
```

`Campanella\Capability\MediaFile` · **Public** · name: `media_file` · table: `cap_media_file`

| Field | Type | |
|---|---|---|
| `file_path` | String(64) | required, unique; relative to the media folder: `2026/10/3f…a1.jpg` |
| `mime_type` | String(32) | required, indexed |
| `file_size` | Integer | required; bytes |
| `width`, `height` | Integer | pixels |
| `file_hash` | String(64) | required, indexed; SHA-256 of the stored file (finds the same file uploaded twice) |

Methods: `path(): string`, `mimeType(): string`, `size(): int`,
`width(): ?int`, `height(): ?int`, `hash(): string`.

The fields are set by the upload, never by a form: they are in
`ObjectForm::MANAGED_FIELDS`, and the admin offers no empty "new" form for a
Blueprint with this capability (its objects are created by uploading).

## MediaService

`Campanella\Media\MediaService` · **Public** · `final class` · container: `MediaService::class`

| Member | Description |
|---|---|
| `__construct(ObjectService $objects, ObjectRepository $repository, AccessPolicy $policy, ImageProcessor $processor, MediaStorage $storage, string $blueprint = 'image')` | |
| `uploadImage(Actor $actor, string $file, string $originalName, string $alt = ''): CampanellaObject` | Checks, stores and creates the image object (see above). `AccessDeniedException` if the actor may not create images; `ValidationException` (on `file`) for an unaccepted file |
| `url(CampanellaObject $object): string` | The file's address, e.g. `/media/2026/10/….jpg` |
| `maxUploadBytes(): int` | The largest file that can be uploaded: `media.max_bytes`, or less if PHP's `upload_max_filesize` or `post_max_size` (minus `FORM_MARGIN`) is lower |
| `FORM_MARGIN` | 64 KB: what `post_max_size` must allow beyond the file (the other fields, the multipart framing) |
| `static titleFrom(string $originalName): string` | The original name without folders, extension and control characters (`C:\Képek\Nyaralás.JPG` → `Nyaralás`); `image` if nothing remains |

```php
$image = $container->get(MediaService::class)->uploadImage($actor, $_FILES['file']['tmp_name'], $_FILES['file']['name']);
```

## ImageProcessor and ProcessedImage

`Campanella\Media\ImageProcessor` · **Public** · `final class` · container: `ImageProcessor::class`

| Member | Description |
|---|---|
| `__construct(int $maxBytes = 10 MB, int $maxPixels = 25 000 000, int $maxDimension = 2560, int $quality = 85, ?bool $useGd = null, string $memoryLimit = '320M', bool $storeUnprocessed = false)` | From the `media` settings. `$useGd`: null means "if the gd extension is loaded" |
| `process(string $file): ProcessedImage` | Steps 2–5 above; `ValidationException` on the `file` field |
| `maxPixels(): int` | The largest image accepted now (also limited by `memory_limit`, after raising it to `memoryLimit` if allowed) |
| `maxBytes(): int` | `max_bytes` |
| `maxPixelsSetting(): int` | `max_pixels` (`maxPixels()` may be lower) |
| `reencodes(): bool` | Whether GD is used |
| `storesUnprocessed(): bool` | The `store_unprocessed` setting |
| `NAMES` | MIME type → name for messages (`image/webp` → `WebP`) |
| `reencodedTypes(): list<string>` | The MIME types this server re-encodes (the others are stored as uploaded) |
| `MAX_JPEG_SCANS` | 100 |
| `static iniBytes(string $size): int` | A php.ini size (`128M`) in bytes; `-1` for no limit |
| `TYPES` | `IMAGETYPE_*` → [MIME type, extension] of the accepted types |

`Campanella\Media\ProcessedImage` · **Public** · `final readonly class`:
`$bytes`, `$mimeType`, `$extension`, `$width`, `$height`, `$reencoded`.

## MediaStorage

`Campanella\Media\MediaStorage` · **Public** · `final class` · container: `MediaStorage::class`

| Member | Description |
|---|---|
| `__construct(string $directory, string $urlPrefix = '/media')` | |
| `store(string $bytes, string $extension, ?DateTimeImmutable $now = null): string` | Stores under a new random name; returns the relative path. Writes a temporary file and renames it, so a half-written file is never served. `RuntimeException` if it cannot write |
| `delete(string $relativePath): bool` | |
| `path(string $relativePath): string` | The absolute path; `InvalidArgumentException` for anything that is not a stored file's path (`PATH_PATTERN`), so e.g. `../config/local.php` cannot be reached |
| `url(string $relativePath): string` | The address on the site |
| `directory(): string`, `isWritable(): bool` | |
| `HTACCESS` | The `.htaccess` written into the folder if missing (a copy ships in `public/media/`) |

`Campanella\Media\MediaCheck` · **Internal**: `static checks(ImageProcessor $processor, MediaStorage $storage): Closure`,
the system check's *Images* lines ([chapter 14](14-system-check.md)): the
folder, the re-encoded types, the largest file and the largest image that
PHP's settings allow.

`Campanella\Media\DeleteMediaFile` · **Internal** · `ObjectListener`:
`afterDelete(CampanellaObject $object): void` deletes the file of a deleted
`MediaFile` object (and logs if it cannot).

## Uploading from the admin

`POST /admin/media/upload` (multipart, field `file`, with the CSRF token),
for users who may create images (the `DefaultPolicy`: `administrator`,
`editor`). With `Accept: application/json` (as the editor and the Images
list send it) the answer is JSON:

| Status | Body |
|---|---|
| 201 | `{"success": true, "id": 12, "url": "/media/2026/10/….jpg", "title": "Nyaralás", "width": 1920, "height": 1080}` |
| 400 | No file, or the CSRF token is missing or expired |
| 401 | The session expired (also JSON, not the login page, so the editor can say so) |
| 403 | The user may not create images |
| 405 | Not POST |
| 413 | Too large (for `media.max_bytes`, or refused by PHP: `upload_max_filesize`, or a body over `post_max_size`, which PHP discards entirely) |
| 422 | Not an accepted image (`media.*` messages, see above) |
| 500 | PHP could not receive the file (temporary folder, disk) |

Errors are `{"success": false, "message": "…"}`, in the user's language.

Without `Accept: application/json` (the Images list's form without
JavaScript) the answer is a redirect (303) to the Images list, with the result
as a one-time message (`media.uploaded`, or the error). An expired session
then leads to the login page, and back to the list.

### In the Images list

`/admin/image` starts with an upload form (for users who may upload): choose
files, or drop them on the form. With JavaScript (`public/assets/admin-upload.js`)
several files are uploaded one after the other, then the list is reloaded; if
any is refused, the reasons are listed by file name instead (with a button to
show those that did go up). Without JavaScript the form uploads one file.

The list shows a thumbnail, the type, size and dimensions (sortable by size),
and "no alternative text" where it is missing. The edit page shows the image,
its data and its address (`/media/…`) beside the form. Deleting warns that
texts showing the image will have a missing image in its place: where images
are used is not tracked yet (planned; the texts keep the address).

**In the editor** ([chapter 13](13-admin.md#formatted-text-the-html-editor)):
the edit form carries the address and the size limit (`data-upload-url`,
`data-upload-max`) if the user may upload. Then the `full` toolbar has an
image button (upload tab, or an address), and pasted or dropped images are
uploaded too. A file over the limit is refused in the browser before sending.
The image is inserted at its own size; the site's CSS (`.body img`) keeps it
within the column. If several files are uploaded at once, those that succeed
are inserted and the others are reported.

An image is never kept in the text as data (base64): one pasted as HTML (e.g.
from another editor) is uploaded like a file. An image from another site is
removed from the editor at once with a message (the filter would remove it
on save anyway; meanwhile the admin's Content-Security-Policy does not even
let the browser load it). Without upload permission, dropping a file on the
editor does nothing.

## Web server

The protection of `public/media/` is the random names with image extensions,
plus the `.htaccess` (Apache with `AllowOverride All`, as in Campanella's
Docker image): no script runs there, and files are served with `nosniff` and
a sandboxing Content-Security-Policy. **On nginx** `.htaccess` is ignored;
the equivalent:

```nginx
location /media/ {
    location ~ \.php$ { deny all; }
    add_header X-Content-Type-Options nosniff;
    add_header Content-Security-Policy "default-src 'none'; sandbox";
}
```

**gd with all formats.** In the official `php` Docker images gd must be built
with the formats explicitly (Campanella's `Dockerfile` does so since 0.0.5):

```dockerfile
RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd exif \
    && rm -rf /var/lib/apt/lists/*
```

The `.htaccess` sets its headers with `mod_headers`, which the official image
does not enable: `a2enmod headers` (also in Campanella's `Dockerfile`).

**PHP's limits.** PHP's defaults (`upload_max_filesize = 2M`,
`post_max_size = 8M`, often `memory_limit = 128M`) are below what images need.
Campanella's Docker image sets them in `docker/php.ini`:

```ini
upload_max_filesize = 16M
post_max_size = 20M
memory_limit = 256M
```

`memory_limit` is enough for ordinary requests; while processing an image,
Campanella raises it to `media.memory_limit` (320 MB) for that request. If the
server forbids raising it from PHP, set `memory_limit = 320M` in `php.ini`
instead, or the largest image accepted is smaller (the system page shows it).

On another server put these into `php.ini` (or `.user.ini`, or the hosting
panel); `upload_max_filesize` and `post_max_size` cannot be changed from PHP
code. The system page's *Images* group shows whether they are enough.

## Upgrading

The `cap_media_file` table is new (schema version 5): run
`php bin/campanella install` after upgrading. Until then the site shows the
"needs upgrade" page.
