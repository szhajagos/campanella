<?php

declare(strict_types=1);

namespace Campanella\Module;

/**
 * Versions and version constraints, as a module's module.json writes them (since 0.4.6).
 *
 * A version is `MAJOR.MINOR.PATCH`, optionally with a pre-release after a hyphen
 * (`1.2.0-beta.1`, which comes before `1.2.0`); build metadata (`+…`) is not accepted.
 *
 * A constraint is one or more alternatives separated by `||`, each one or more conditions
 * separated by spaces (all must hold):
 *
 *   1.2.3   =1.2.3   exactly that version
 *   >=1.2   >1.2   <=1.2   <2.0   compared (a missing part is 0)
 *   ^1.2.3   the same major version, at least 1.2.3: >=1.2.3 <2.0.0; below 1.0 the minor
 *            version counts as major: ^0.4.6 is >=0.4.6 <0.5.0, ^0.0.3 is >=0.0.3 <0.0.4
 *   ~1.2.3   the same minor version: >=1.2.3 <1.3.0 (~1.2 is the same as ~1.2.0)
 *   *        any version
 *
 * A pre-release satisfies a constraint only if a condition of it names one of the same
 * MAJOR.MINOR.PATCH (as in npm), so `^0.4.6` does not let in `0.5.0-alpha`.
 */
final class Semver
{
    private const string VERSION = '/^(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})(?:-([0-9A-Za-z-]{1,20}(?:\.[0-9A-Za-z-]{1,20}){0,4}))?$/D';
    private const string PARTIAL = '/^(0|[1-9]\d{0,5})(?:\.(0|[1-9]\d{0,5}))?(?:\.(0|[1-9]\d{0,5}))?(?:-([0-9A-Za-z-]{1,20}(?:\.[0-9A-Za-z-]{1,20}){0,4}))?$/D';
    private const int MAX_LENGTH = 200;

    /** Whether the text is a version. */
    public static function isVersion(string $version): bool
    {
        return preg_match(self::VERSION, $version) === 1;
    }

    /** Whether the text is a constraint this class can read. */
    public static function isConstraint(string $constraint): bool
    {
        return self::conditions($constraint) !== null;
    }

    /**
     * -1, 0 or 1 as `$a` is before, the same as or after `$b`.
     *
     * @throws \InvalidArgumentException if either is not a version
     */
    public static function compare(string $a, string $b): int
    {
        return self::compareParts(self::parse($a), self::parse($b));
    }

    /**
     * Whether the version satisfies the constraint; false if either cannot be read.
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        $alternatives = self::conditions($constraint);
        if ($alternatives === null || !self::isVersion($version)) {
            return false;
        }
        $parts = self::parse($version);
        foreach ($alternatives as $conditions) {
            $holds = true;
            $preAllowed = $parts[3] === [];
            foreach ($conditions as [$operator, $bound]) {
                $order = self::compareParts($parts, $bound);
                $holds = $holds && match ($operator) {
                    '=' => $order === 0,
                    '>' => $order > 0,
                    '>=' => $order >= 0,
                    '<' => $order < 0,
                    default => $order <= 0,
                };
                if ($bound[3] !== [] && array_slice($bound, 0, 3) === array_slice($parts, 0, 3)) {
                    $preAllowed = true;
                }
            }
            if ($holds && $preAllowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * The constraint as alternatives of conditions, each [operator, version's parts]; null if
     * it cannot be read.
     *
     * @return list<list<array{0: string, 1: array{0: int, 1: int, 2: int, 3: list<string>}}>>|null
     */
    private static function conditions(string $constraint): ?array
    {
        $constraint = trim($constraint);
        if ($constraint === '' || strlen($constraint) > self::MAX_LENGTH) {
            return null;
        }
        $alternatives = [];
        foreach (explode('||', $constraint) as $alternative) {
            $words = preg_split('/\s+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($words === []) {
                return null;
            }
            $conditions = [];
            foreach ($words as $word) {
                $read = self::condition($word);
                if ($read === null) {
                    return null;
                }
                array_push($conditions, ...$read);
            }
            $alternatives[] = $conditions;
        }

        return $alternatives;
    }

    /** @return list<array{0: string, 1: array{0: int, 1: int, 2: int, 3: list<string>}}>|null */
    private static function condition(string $word): ?array
    {
        if ($word === '*') {
            return [['>=', [0, 0, 0, []]]];
        }
        if (preg_match('/^(\^|~|>=|<=|>|<|=)?(.+)$/D', $word, $m) !== 1 || preg_match(self::PARTIAL, $m[2], $v) !== 1) {
            return null;
        }
        $given = isset($v[3]) && $v[3] !== '' ? 3 : (isset($v[2]) && $v[2] !== '' ? 2 : 1);
        $low = [(int) $v[1], (int) ($v[2] ?? 0), (int) ($v[3] ?? 0), isset($v[4]) ? explode('.', $v[4]) : []];
        [$major, $minor, $patch] = $low;

        return match ($m[1]) {
            '^' => [['>=', $low], ['<', match (true) {
                $major > 0 || $given === 1 => [$major + 1, 0, 0, []],
                $minor > 0 || $given === 2 => [0, $minor + 1, 0, []],
                default => [0, 0, $patch + 1, []],
            }]],
            '~' => [['>=', $low], ['<', $given === 1 ? [$major + 1, 0, 0, []] : [$major, $minor + 1, 0, []]]],
            '' , '=' => $given === 3 ? [['=', $low]] : [['>=', $low], ['<', $given === 1 ? [$major + 1, 0, 0, []] : [$major, $minor + 1, 0, []]]],
            default => [[$m[1], $low]],
        };
    }

    /** @return array{0: int, 1: int, 2: int, 3: list<string>} */
    private static function parse(string $version): array
    {
        if (preg_match(self::VERSION, $version, $m) !== 1) {
            throw new \InvalidArgumentException('Not a version: ' . mb_substr($version, 0, 40));
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3], isset($m[4]) ? explode('.', $m[4]) : []];
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: list<string>} $a
     * @param array{0: int, 1: int, 2: int, 3: list<string>} $b
     */
    private static function compareParts(array $a, array $b): int
    {
        for ($i = 0; $i < 3; ++$i) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }
        // A pre-release comes before the release; between two, part by part.
        if ($a[3] === [] || $b[3] === []) {
            return ($a[3] === []) <=> ($b[3] === []);
        }
        foreach ($a[3] as $i => $part) {
            if (!isset($b[3][$i])) {
                return 1;
            }
            $order = ctype_digit($part) && ctype_digit($b[3][$i]) ? (int) $part <=> (int) $b[3][$i]
                : (ctype_digit($part) ? -1 : (ctype_digit($b[3][$i]) ? 1 : strcmp($part, $b[3][$i]) <=> 0));
            if ($order !== 0) {
                return $order;
            }
        }

        return count($a[3]) < count($b[3]) ? -1 : 0;
    }
}
