<?php

declare(strict_types=1);

namespace Campanella\Search;

use Campanella\Capability\Routable;
use Campanella\Capability\TextFormat;
use Campanella\Capability\Textual;
use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Model\Blueprint;
use Campanella\Model\CampanellaObject;
use Campanella\Model\FieldType;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The search's index (since 0.2.7): for each object with a page of its own
 * (Routable), its title and its plain text in the `search_index` table, which
 * has FULLTEXT indexes. The ObjectRepository writes it on every save, in the
 * same transaction; deleting an object deletes its row (ON DELETE CASCADE).
 *
 * The text: the body (HTML without its tags), then the object's own text fields
 * (string, text and formatted text) of its Blueprint, stored lowercase and without
 * accents (fold()). Users, the forms' messages and uploaded files are never indexed.
 */
final class SearchIndex
{
    /** The capabilities whose objects are never indexed. */
    public const array NEVER = ['authenticatable', 'submitted', 'media_file', 'downloadable'];

    /** The longest text kept for an object, in bytes (the rest is not searched). */
    public const int MAX_CONTENT = 60_000;

    /** Whether the object is indexed: it has a page of its own and is not a user or a message. */
    public static function indexes(CampanellaObject $object): bool
    {
        if (!$object->has(Routable::class)) {
            return false;
        }
        foreach (self::NEVER as $capability) {
            if ($object->has($capability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Writes (or deletes) the object's row.
     */
    public static function record(Connection $db, int $id, CampanellaObject $object, ?Blueprint $blueprint): void
    {
        $db->delete(CoreSchema::SEARCH_INDEX, ['object_id' => $id]);
        if (!self::indexes($object)) {
            return;
        }
        [$title, $content] = self::text($object, $blueprint);
        $db->insert(CoreSchema::SEARCH_INDEX, [
            'object_id' => $id,
            'blueprint' => $object->blueprint(),
            'title' => mb_substr(self::fold($title), 0, 255, 'UTF-8'),
            'content' => mb_strcut(self::fold($content), 0, self::MAX_CONTENT, 'UTF-8'),
            'indexed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(FieldType::STORAGE_DATE_FORMAT),
        ]);
    }

    /**
     * The object's title and plain text, as indexed.
     *
     * @return array{0: string, 1: string}
     */
    public static function text(CampanellaObject $object, ?Blueprint $blueprint): array
    {
        $title = $object->hasField('title') ? self::plain((string) $object->get('title')) : '';
        $parts = [];
        if ($object->has(Textual::class)) {
            $textual = $object->as(Textual::class);
            $parts[] = $textual->format() === TextFormat::Html ? self::fromHtml($textual->body()) : self::plain($textual->body());
        }
        foreach ($blueprint->fields ?? [] as $name => $field) {
            if ($field->hidden || !in_array($field->type, [FieldType::String, FieldType::Text, FieldType::Html], true) || !$object->hasField($name)) {
                continue;
            }
            foreach ((array) $object->get($name) as $value) {
                if (is_scalar($value) && (string) $value !== '') {
                    $parts[] = $field->type === FieldType::Html ? self::fromHtml((string) $value) : self::plain((string) $value);
                }
            }
        }
        $content = trim(implode("\n", array_filter($parts, static fn (string $p): bool => $p !== '')));

        return [mb_substr($title, 0, 255, 'UTF-8'), mb_strcut($content, 0, self::MAX_CONTENT, 'UTF-8')];
    }

    /**
     * A text as it is searched: lowercase, without accents (`Kertészet` → `kerteszet`),
     * so a search finds a word with or without its accents, on MariaDB and MySQL alike
     * (MySQL's FULLTEXT index compares accents in a word's start).
     */
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), self::FOLD);
    }

    /** The accented letters and what they become. */
    private const array FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
        'ĵ' => 'j', 'ķ' => 'k', 'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o', 'œ' => 'oe',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r', 'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's', 'ß' => 'ss',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ț' => 't', 'þ' => 'th',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w', 'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    /** HTML's text: a block's end is a space, the tags go, the entities are decoded. */
    public static function fromHtml(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/td|/th|/blockquote|/pre)\b[^>]*>#i', "$0\n", $html) ?? '';

        return self::plain(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** One space for each run of white space, without control characters. */
    private static function plain(string $text): string
    {
        return trim(preg_replace('/[\s\x00-\x1f\x7f]+/u', ' ', $text) ?? '');
    }
}
