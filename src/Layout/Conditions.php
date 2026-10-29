<?php

declare(strict_types=1);

namespace Campanella\Layout;

use Campanella\Http\Request;
use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;

/**
 * Where something shows (since 0.3.4): a component, and later a layout chosen by rules.
 * Written apart from components so both can use it.
 *
 *  - `paths`: only on these paths; `*` stands for anything (`/hirek/*`: every page under
 *    `/hirek/`, not `/hirek` itself);
 *  - `blueprints`: only on the pages of these Blueprints' objects;
 *  - `front`: only on the front page;
 *  - `except`: never on these paths (the same patterns).
 *
 * The first three add up: a page that matches any of them is in. With none of them, every
 * page is. `except` is checked last. Paths are compared without their case.
 *
 *     $where = Conditions::fromArray(['paths' => ['/hirek/*'], 'except' => ['/hirek/archivum']]);
 *     $where->matches('/hirek/egy-cikk', $article);   // true
 */
final readonly class Conditions
{
    /** The most patterns or Blueprints of one kind. */
    public const int MAX = 20;

    /** The longest path pattern. */
    public const int MAX_PATH = 255;

    /**
     * @param list<string> $paths
     * @param list<string> $blueprints
     * @param list<string> $except
     */
    public function __construct(
        public array $paths = [],
        public array $blueprints = [],
        public bool $front = false,
        public array $except = [],
    ) {
    }

    /** Everywhere. */
    public static function everywhere(): self
    {
        return new self();
    }

    /**
     * From the stored form; patterns are trimmed, empty and repeated ones left out (problems()
     * tells what is not valid).
     *
     * @param array<string, mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            self::list($stored['paths'] ?? []),
            self::list($stored['blueprints'] ?? []),
            ($stored['front'] ?? false) === true,
            self::list($stored['except'] ?? []),
        );
    }

    /**
     * From the lines of a textarea (one pattern a line), as the admin's form gives them.
     *
     * @param list<string> $blueprints
     */
    public static function fromText(string $paths, array $blueprints, bool $front, string $except): self
    {
        $lines = static fn (string $text): array => preg_split('/\R/', $text) ?: [];

        return new self(self::list($lines($paths)), self::list($blueprints), $front, self::list($lines($except)));
    }

    /** @return array{paths?: list<string>, blueprints?: list<string>, front?: true, except?: list<string>} */
    public function toArray(): array
    {
        return array_filter([
            'paths' => $this->paths,
            'blueprints' => $this->blueprints,
            'front' => $this->front ?: null,
            'except' => $this->except,
        ], static fn ($value): bool => $value !== [] && $value !== null);
    }

    public function isEverywhere(): bool
    {
        return $this->paths === [] && $this->blueprints === [] && !$this->front && $this->except === [];
    }

    /**
     * What is not valid: a path that does not start with `/`, is too long or has characters a
     * path cannot have; a Blueprint name not written as one; too many of a kind.
     *
     * @param list<string> $knownBlueprints If given, the Blueprints that exist
     * @return array<string, Message> `paths`, `except`, `blueprints` => problem
     */
    public function problems(?array $knownBlueprints = null): array
    {
        $problems = [];
        foreach (['paths' => $this->paths, 'except' => $this->except] as $kind => $patterns) {
            if (count($patterns) > self::MAX) {
                $problems[$kind] = new Message('conditions.too_many', ['max' => self::MAX]);
                continue;
            }
            foreach ($patterns as $pattern) {
                if (!str_starts_with($pattern, '/') || mb_strlen($pattern, 'UTF-8') > self::MAX_PATH
                    || preg_match('/[\x00-\x20\x7f\\\\?#]/', $pattern) === 1) {
                    $problems[$kind] = new Message('conditions.invalid_path', ['path' => mb_substr($pattern, 0, 60, 'UTF-8')]);
                    break;
                }
            }
        }
        if (count($this->blueprints) > self::MAX) {
            $problems['blueprints'] = new Message('conditions.too_many', ['max' => self::MAX]);
        } else {
            foreach ($this->blueprints as $name) {
                if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) !== 1 || ($knownBlueprints !== null && !in_array($name, $knownBlueprints, true))) {
                    $problems['blueprints'] = new Message('conditions.unknown_blueprint', ['blueprint' => mb_substr($name, 0, 60, 'UTF-8')]);
                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * Whether a page matches.
     *
     * @param string $path The page's path (without the installation's folder)
     * @param CampanellaObject|null $object The page's object, if it shows one
     */
    public function matches(string $path, ?CampanellaObject $object = null): bool
    {
        $path = mb_strtolower(Request::normalizePath($path), 'UTF-8');
        foreach ($this->except as $pattern) {
            if (self::pathMatches($pattern, $path)) {
                return false;
            }
        }
        if ($this->paths === [] && $this->blueprints === [] && !$this->front) {
            return true;
        }
        if ($this->front && $path === '/') {
            return true;
        }
        if ($object !== null && in_array($object->blueprint(), $this->blueprints, true)) {
            return true;
        }
        foreach ($this->paths as $pattern) {
            if (self::pathMatches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /** A pattern (`*` for anything) against a normalized, lowercase path. */
    public static function pathMatches(string $pattern, string $path): bool
    {
        $pattern = mb_strtolower($pattern === '/' ? '/' : rtrim($pattern, '/'), 'UTF-8');
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#uD';

        return preg_match($regex, $path) === 1;
    }

    /** @return list<string> */
    private static function list(mixed $items): array
    {
        $list = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $item = is_string($item) ? trim($item) : '';
            if ($item !== '' && !in_array($item, $list, true)) {
                $list[] = $item;
            }
        }

        return $list;
    }
}
