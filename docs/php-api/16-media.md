# 16. Images (media)

Uploaded images are objects (since 0.0.5), following the decision that
anything referred to is an object: an `image` Blueprint with a title, an
alternative text, an author (the uploader) and the file's data. The file
itself is stored in `public/media/` and served directly by the web server.

Uploading from the admin (and the editor) comes in the next step; this chapter
describes the server side every upload goes through.

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
| `media.max_bytes` | 10 MB | The largest file accepted. PHP's `upload_max_filesize` and `post_max_size` must allow it too |
| `media.max_pixels` | 25 000 000 | The largest image (width × height) |
| `media.max_dimension` | 2560 | Larger images are scaled down to this width or height |
| `media.quality` | 85 | JPEG and WebP quality when re-encoding (1–100) |
| `media.store_unprocessed` | `false` | `true`: a type the server cannot re-encode is stored as uploaded (metadata included) instead of being refused |
| `media.memory_limit` | `'256M'` | PHP's `memory_limit` is raised to this while processing an image (for that request only), if the server allows it. Decoding a 12-megapixel photo needs about 100 MB; with the common 128 MB limit only a few megapixels would fit |

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
| `static titleFrom(string $originalName): string` | The original name without folders, extension and control characters (`C:\Képek\Nyaralás.JPG` → `Nyaralás`); `image` if nothing remains |

```php
$image = $container->get(MediaService::class)->uploadImage($actor, $_FILES['file']['tmp_name'], $_FILES['file']['name']);
```

## ImageProcessor and ProcessedImage

`Campanella\Media\ImageProcessor` · **Public** · `final class` · container: `ImageProcessor::class`

| Member | Description |
|---|---|
| `__construct(int $maxBytes = 10 MB, int $maxPixels = 25 000 000, int $maxDimension = 2560, int $quality = 85, ?bool $useGd = null, string $memoryLimit = '256M', bool $storeUnprocessed = false)` | From the `media` settings. `$useGd`: null means "if the gd extension is loaded" |
| `process(string $file): ProcessedImage` | Steps 2–5 above; `ValidationException` on the `file` field |
| `maxPixels(): int` | The largest image accepted now (also limited by `memory_limit`, after raising it to `memoryLimit` if allowed) |
| `maxBytes(): int` | `max_bytes` |
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
the system check's *Images* lines ([chapter 14](14-system-check.md)).

`Campanella\Media\DeleteMediaFile` · **Internal** · `ObjectListener`:
`afterDelete(CampanellaObject $object): void` deletes the file of a deleted
`MediaFile` object (and logs if it cannot).

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
with the formats explicitly:

```dockerfile
RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd exif \
    && rm -rf /var/lib/apt/lists/*
```

## Upgrading

The `cap_media_file` table is new (schema version 5): run
`php bin/campanella install` after upgrading. Until then the site shows the
"needs upgrade" page.
