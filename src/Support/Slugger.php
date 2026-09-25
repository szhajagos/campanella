<?php

declare(strict_types=1);

namespace Campanella\Support;

/**
 * URL-barát szöveg készítése. Saját átírótáblát használ, mert az iconv
 * „TRANSLIT” viselkedése tárhelyenként eltér.
 */
final class Slugger
{
    private const array MAP = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o',
        'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
        'ä' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'č' => 'c', 'ć' => 'c', 'ď' => 'd', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e',
        'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ľ' => 'l', 'ł' => 'l', 'ñ' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe', 'ř' => 'r', 'š' => 's', 'ś' => 's',
        'ß' => 'ss', 'ť' => 't', 'ù' => 'u', 'û' => 'u', 'ů' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
    ];

    public static function slugify(string $text): string
    {
        $text = strtr(mb_strtolower($text, 'UTF-8'), self::MAP);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);

        return trim($text, '-') ?: 'n-a';
    }
}
