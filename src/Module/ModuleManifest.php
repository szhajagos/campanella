<?php

declare(strict_types=1);

namespace Campanella\Module;

use Campanella\I18n\Message;

/**
 * A module's module.json, read and checked (since 0.4.6):
 *
 *     {
 *       "name": "newsletter",
 *       "label": "Newsletter",
 *       "description": "Subscribing, and the letters sent.",
 *       "version": "1.0.0",
 *       "campanella": "^0.4.6",
 *       "requires": {"other_module": "^1.0"},
 *       "autoload": {"Newsletter\\": "src/"}
 *     }
 *
 * `name` is the module's folder's name (small letters, digits, `_`, starting with a letter),
 * `version` a version and `campanella` a constraint of Semver; `requires` the other modules it
 * needs (optional); `autoload` exactly one namespace of its own (not `Campanella\`) and its
 * folder inside the module, which has the entry point `Module.php` (the namespace's `Module`
 * class). Other keys are left for later versions.
 */
final readonly class ModuleManifest
{
    public const string FILE = 'module.json';
    public const int MAX_BYTES = 65536;
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]{1,40}$/D';

    /** @param array<string, string> $requires */
    private function __construct(
        public string $name,
        public string $label,
        public string $description,
        public string $version,
        public string $campanella,
        public array $requires,
        public string $namespace,
        public string $sourceDir,
        public string $path,
    ) {
    }

    /** The entry point's class: the namespace's `Module`. */
    public function entryClass(): string
    {
        return $this->namespace . 'Module';
    }

    /**
     * The manifest of the module in this folder (an absolute, real path), or why it cannot be read.
     *
     * @return self|Message
     */
    public static function read(string $path): self|Message
    {
        $file = $path . '/' . self::FILE;
        if (!is_file($file) || is_link($file)) {
            return new Message('modules.problem.no_manifest');
        }
        $size = filesize($file);
        if ($size === false || $size > self::MAX_BYTES) {
            return new Message('modules.problem.manifest_size', ['max' => self::MAX_BYTES / 1024]);
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new Message('modules.problem.manifest_json');
        }
        if (!is_array($data) || array_is_list($data)) {
            return new Message('modules.problem.manifest_json');
        }
        $folder = basename($path);
        $text = static fn (string $key, int $max, bool $required = true): ?string => is_string($data[$key] ?? null) && mb_strlen(trim($data[$key])) <= $max && (!$required || trim($data[$key]) !== '')
            ? trim($data[$key])
            : ($required || isset($data[$key]) ? null : '');

        $name = $data['name'] ?? null;
        if (!is_string($name) || $name !== $folder) {
            return new Message('modules.problem.name', ['folder' => $folder]);
        }
        $label = $text('label', 100);
        $description = $text('description', 500, false);
        if ($label === null || $description === null) {
            return new Message('modules.problem.label');
        }
        $version = $data['version'] ?? null;
        if (!is_string($version) || !Semver::isVersion($version)) {
            return new Message('modules.problem.version');
        }
        $core = $data['campanella'] ?? null;
        if (!is_string($core) || !Semver::isConstraint($core)) {
            return new Message('modules.problem.core_constraint');
        }
        $requires = $data['requires'] ?? [];
        if (!is_array($requires) || ($requires !== [] && array_is_list($requires))) {
            return new Message('modules.problem.requires');
        }
        foreach ($requires as $other => $constraint) {
            if (!is_string($other) || preg_match(self::NAME_PATTERN, $other) !== 1 || $other === $name || !is_string($constraint) || !Semver::isConstraint($constraint)) {
                return new Message('modules.problem.requires');
            }
        }
        /** @var array<string, string> $requires */
        $autoload = $data['autoload'] ?? null;
        if (!is_array($autoload) || count($autoload) !== 1 || array_is_list($autoload)) {
            return new Message('modules.problem.autoload');
        }
        $namespace = (string) array_key_first($autoload);
        $dir = $autoload[$namespace];
        if (preg_match('/^[A-Z][A-Za-z0-9_]{0,40}(\\\\[A-Z][A-Za-z0-9_]{0,40}){0,4}\\\\$/D', $namespace) !== 1 || str_starts_with($namespace, 'Campanella\\')) {
            return new Message('modules.problem.namespace');
        }
        if (!is_string($dir) || preg_match('#^([A-Za-z0-9_-]+/)*[A-Za-z0-9_-]*/?$#D', $dir) !== 1) {
            return new Message('modules.problem.autoload');
        }
        $source = realpath($path . '/' . $dir);
        if ($source === false || !is_dir($source) || ($source !== $path && !str_starts_with($source, $path . '/'))) {
            return new Message('modules.problem.autoload');
        }
        if (!is_file($source . '/Module.php')) {
            return new Message('modules.problem.entry', ['file' => ($dir === '' ? '' : rtrim($dir, '/') . '/') . 'Module.php']);
        }

        return new self($name, $label, $description, $version, $core, $requires, $namespace, $source, $path);
    }
}
