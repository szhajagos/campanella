<?php

declare(strict_types=1);

namespace Campanella\I18n;

/**
 * Translation of user-facing texts.
 *
 * Code refers to texts by key ('auth.invalid_credentials'); the texts live in
 * one PHP file per language (lang/en.php, lang/hu.php) as a flat key => text
 * array. English is the base language: a key missing in the current language
 * falls back to English, and a key missing everywhere is returned as it is.
 * This last rule also means that a plain text (not a key) passes through
 * unchanged, so e.g. a custom LoginGuard may still return a ready-made message.
 *
 * Parameters are written as {name} in the text:
 *
 *     $translator->translate('auth.too_many_attempts', ['minutes' => 5]);
 */
final class Translator
{
    public const string BASE_LOCALE = 'en';

    /**
     * @param array<string, array<string, string>> $catalogs locale => key => text
     */
    public function __construct(
        private readonly array $catalogs,
        private readonly string $locale = self::BASE_LOCALE,
    ) {
        if (preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale) !== 1) {
            throw new \InvalidArgumentException("Invalid locale: {$locale}");
        }
    }

    /**
     * Loads every <locale>.php file of the directory (each returns a key => text array).
     */
    public static function fromDirectory(string $directory, string $locale): self
    {
        return new self(self::loadCatalogs($directory), $locale);
    }

    /**
     * @return array<string, array<string, string>> locale => key => text
     */
    public static function loadCatalogs(string $directory): array
    {
        $catalogs = [];
        foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $file) {
            $messages = require $file;
            if (!is_array($messages)) {
                throw new \UnexpectedValueException("The language file {$file} must return an array.");
            }
            $catalogs[basename($file, '.php')] = array_map(strval(...), $messages);
        }

        return $catalogs;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @return list<string> The locales that have a language file. */
    public function locales(): array
    {
        return array_keys($this->catalogs);
    }

    /**
     * The text of the key in the current language (or in English, or the key itself),
     * with the {name} parameters substituted.
     *
     * @param array<string, string|int|float> $params
     */
    public function translate(string $key, array $params = []): string
    {
        $text = $this->catalogs[$this->locale][$key] ?? $this->catalogs[self::BASE_LOCALE][$key] ?? $key;
        if ($params === []) {
            return $text;
        }
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replace);
    }

    /** Whether the key exists in the current language or in English. */
    public function has(string $key): bool
    {
        return isset($this->catalogs[$this->locale][$key]) || isset($this->catalogs[self::BASE_LOCALE][$key]);
    }

    /**
     * Keys that the base (English) catalog has but the given language lacks, and vice versa.
     * Used by `composer lang:check` and the tests.
     *
     * @param array<string, array<string, string>> $catalogs
     * @return array<string, array{missing: list<string>, extra: list<string>}> locale => differences
     */
    public static function compare(array $catalogs): array
    {
        $base = array_keys($catalogs[self::BASE_LOCALE] ?? []);
        $result = [];
        foreach ($catalogs as $locale => $messages) {
            if ($locale === self::BASE_LOCALE) {
                continue;
            }
            $keys = array_keys($messages);
            $result[$locale] = [
                'missing' => array_values(array_diff($base, $keys)),
                'extra' => array_values(array_diff($keys, $base)),
            ];
        }

        return $result;
    }
}
