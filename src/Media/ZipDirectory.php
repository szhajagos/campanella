<?php

declare(strict_types=1);

namespace Campanella\Media;

/**
 * Reads the table of contents of a ZIP archive (an Office or OpenDocument file is
 * one), and a small entry's content, without the zip extension and without
 * unpacking anything to the disk (since 0.3.2). Only what FileInspector needs: the
 * entries' names, sizes, methods and flags. ZIP64 archives and archives with more
 * than MAX_ENTRIES entries are refused: no document of the accepted size needs them.
 *
 * @internal
 */
final class ZipDirectory
{
    public const int MAX_ENTRIES = 20_000;

    /** An entry is read only up to this many bytes (unpacked). */
    public const int MAX_READ = 2 * 1024 * 1024;

    /** @var list<array{name: string, method: int, flags: int, compressed: int, size: int, offset: int}> */
    private array $entries = [];

    /**
     * @throws \InvalidArgumentException if the bytes are not a ZIP archive this reader accepts
     */
    public function __construct(private readonly string $bytes)
    {
        $length = strlen($bytes);
        if ($length < 22 || !str_starts_with($bytes, "PK\x03\x04")) {
            throw new \InvalidArgumentException('Not a ZIP archive.');
        }
        // The end of central directory record: 22 bytes, then a comment of at most 65 535.
        $end = strrpos(substr($bytes, max(0, $length - 65_557)), "PK\x05\x06");
        if ($end === false) {
            throw new \InvalidArgumentException('No central directory.');
        }
        $end += max(0, $length - 65_557);
        $record = unpack('vdisk/vcdDisk/vdiskEntries/ventries/Vsize/Voffset/vcomment', substr($bytes, $end + 4, 18));
        if ($record === false || $record['disk'] !== 0 || $record['cdDisk'] !== 0 || $record['diskEntries'] !== $record['entries']) {
            throw new \InvalidArgumentException('A multi-part archive.');
        }
        if ($record['entries'] === 0xFFFF || $record['size'] === 0xFFFFFFFF || $record['offset'] === 0xFFFFFFFF) {
            throw new \InvalidArgumentException('A ZIP64 archive.');
        }
        if ($record['entries'] > self::MAX_ENTRIES) {
            throw new \InvalidArgumentException('Too many entries.');
        }
        if ($record['offset'] + $record['size'] > $end) {
            throw new \InvalidArgumentException('The central directory is outside the archive.');
        }

        $position = $record['offset'];
        for ($i = 0; $i < $record['entries']; $i++) {
            if (substr($bytes, $position, 4) !== "PK\x01\x02" || $position + 46 > $end) {
                throw new \InvalidArgumentException('A broken central directory.');
            }
            $entry = unpack('vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr($bytes, $position + 4, 42));
            if ($entry === false) {
                throw new \InvalidArgumentException('A broken central directory.');
            }
            $name = substr($bytes, $position + 46, $entry['name']);
            if (strlen($name) !== $entry['name'] || $name === '' || str_contains($name, "\0")) {
                throw new \InvalidArgumentException('An entry without a usable name.');
            }
            if ($entry['compressed'] === 0xFFFFFFFF || $entry['size'] === 0xFFFFFFFF || $entry['offset'] === 0xFFFFFFFF) {
                throw new \InvalidArgumentException('A ZIP64 entry.');
            }
            if ($entry['offset'] >= $record['offset']) {
                throw new \InvalidArgumentException('An entry outside the archive.');
            }
            $this->entries[] = [
                'name' => $name,
                'method' => $entry['method'],
                'flags' => $entry['flags'],
                'compressed' => $entry['compressed'],
                'size' => $entry['size'],
                'offset' => $entry['offset'],
            ];
            $position += 46 + $entry['name'] + $entry['extra'] + $entry['comment'];
        }
    }

    /** @return list<string> The entries' names, in the archive's order. */
    public function names(): array
    {
        return array_column($this->entries, 'name');
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->names(), true);
    }

    /** Whether an entry is encrypted (password protected). */
    public function isEncrypted(string $name): bool
    {
        return (($this->entry($name)['flags'] ?? 0) & 1) === 1;
    }

    /** The compression method of an entry: 0 stored, 8 deflated (others are not read). */
    public function method(string $name): ?int
    {
        return $this->entry($name)['method'] ?? null;
    }

    /**
     * An entry's content, unpacked (stored or deflated), or null if it is missing,
     * encrypted, larger than MAX_READ, packed another way, or broken.
     */
    public function read(string $name): ?string
    {
        $entry = $this->entry($name);
        if ($entry === null || ($entry['flags'] & 1) === 1 || $entry['size'] > self::MAX_READ || $entry['compressed'] > self::MAX_READ) {
            return null;
        }
        $local = substr($this->bytes, $entry['offset'], 30);
        if (strlen($local) !== 30 || !str_starts_with($local, "PK\x03\x04")) {
            return null;
        }
        $header = unpack('vname/vextra', substr($local, 26, 4));
        if ($header === false) {
            return null;
        }
        $data = substr($this->bytes, $entry['offset'] + 30 + $header['name'] + $header['extra'], $entry['compressed']);
        if (strlen($data) !== $entry['compressed']) {
            return null;
        }
        if ($entry['method'] === 0) {
            return $data;
        }
        if ($entry['method'] !== 8) {
            return null;
        }
        $content = @gzinflate($data, self::MAX_READ);

        return is_string($content) && strlen($content) === $entry['size'] ? $content : null;
    }

    /** @return array{name: string, method: int, flags: int, compressed: int, size: int, offset: int}|null */
    private function entry(string $name): ?array
    {
        foreach ($this->entries as $entry) {
            if ($entry['name'] === $name) {
                return $entry;
            }
        }

        return null;
    }
}
