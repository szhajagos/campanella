<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * A file sent with a form (multipart/form-data). Request::fromGlobals() only
 * creates one for a file PHP received as an upload (is_uploaded_file()), so a
 * path given by the client can never pass for an upload.
 */
final readonly class UploadedFile
{
    /**
     * @param string $name The name the client gave it (only for display; never used as a path)
     * @param string $path The temporary file PHP stored it in
     * @param int $error One of the UPLOAD_ERR_* constants
     */
    public function __construct(
        public string $name,
        public string $path,
        public int $size,
        public int $error = UPLOAD_ERR_OK,
    ) {
    }

    public function isOk(): bool
    {
        return $this->error === UPLOAD_ERR_OK;
    }

    /** Whether PHP refused it for its size (upload_max_filesize, or the form's MAX_FILE_SIZE). */
    public function isTooLarge(): bool
    {
        return in_array($this->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    }
}
